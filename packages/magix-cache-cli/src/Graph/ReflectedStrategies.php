<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Graph;

use function class_exists;
use function is_int;

use Magix\Cache\Cli\Declaration\ContractReference;
use Magix\Cache\Cli\Declaration\ContractSource;
use Magix\Cache\Cli\Declaration\StrategyDeclaration;
use Magix\Cache\Cli\Declaration\StrategyParameter;
use Magix\Cache\Cli\Declaration\TtlAssumption;
use Magix\Cache\Cli\Declaration\TtlContract;
use Magix\Cache\Cli\Declaration\Unresolved;
use Magix\Cache\Strategy\CacheStrategy;
use Magix\Cache\Strategy\CompositeCacheStrategy;
use Magix\Cache\Strategy\Contract\Arg;
use Magix\Cache\Strategy\Contract\AssumeTtl;
use Magix\Cache\Strategy\Contract\ConstructorArg;
use Magix\Cache\Strategy\Contract\Ttl;
use Magix\Cache\Strategy\Contract\TtlRange;
use Magix\Cache\Strategy\StrategyDefinition;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Reads the contracts of strategies that live outside the scanned sources.
 *
 * The bundled strategies of the library are not part of a scanned project,
 * but their contract attributes are readable through reflection without
 * executing any user code. Reflection sees signatures and attributes only,
 * so the body of a create() stays statically unknown and its composition is
 * reported as such, never guessed.
 */
final readonly class ReflectedStrategies
{
    /**
     * Returns the declaration of a loadable strategy class, when it is one.
     */
    public function read(string $class): ?StrategyDeclaration
    {
        if (!class_exists($class)) {
            return null;
        }

        $reflection = new ReflectionClass($class);
        $leaf = $reflection->implementsInterface(CacheStrategy::class);

        if (!$leaf && !$reflection->isSubclassOf(CompositeCacheStrategy::class)) {
            return null;
        }

        $create = $this->method($reflection, 'create');
        $hasCreate = $create !== null && $create->isStatic() && $create->isPublic();

        return new StrategyDeclaration(
            name: $class,
            file: $reflection->getFileName() === false ? '' : $reflection->getFileName(),
            line: $reflection->getStartLine() === false ? 0 : $reflection->getStartLine(),
            parameters: $this->parameters($reflection->getConstructor()),
            createParameters: $hasCreate ? $this->parameters($create) : [],
            hasCreate: $hasCreate,
            composed: null,
            ttl: $leaf ? $this->contract($reflection) : null,
            assumptions: $hasCreate ? $this->assumptions($create) : [],
            constructible: $leaf && $reflection->isInstantiable(),
            definitionProblem: $hasCreate ? $this->definitionProblem($create) : null,
            notes: $hasCreate ? ['the body of '.$class.'::create() is outside the scanned sources'] : [],
        );
    }

    /**
     * Rejects factories declaring an incompatible result without calling them.
     */
    public function definitionProblem(ReflectionMethod $create): ?string
    {
        $type = $create->getReturnType();

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin() && $type->getName() !== StrategyDefinition::class) {
            return 'create() must return StrategyDefinition, not an executable strategy instance';
        }

        return null;
    }

    /**
     * Returns one reflected method, or null when the class lacks it.
     *
     * @param ReflectionClass<object> $reflection
     */
    public function method(ReflectionClass $reflection, string $name): ?ReflectionMethod
    {
        try {
            return $reflection->getMethod($name);
        } catch (ReflectionException) {
            return null;
        }
    }

    /**
     * Returns the reflected parameters of a method in order.
     *
     * @param ReflectionMethod|null $method
     * @return list<StrategyParameter>
     */
    public function parameters(?ReflectionMethod $method): array
    {
        $parameters = [];

        foreach ($method?->getParameters() ?? [] as $position => $parameter) {
            $hasDefault = $parameter->isDefaultValueAvailable();

            try {
                $default = $hasDefault ? $parameter->getDefaultValue() : null;
            } catch (ReflectionException) {
                $default = Unresolved::Value;
            }

            $parameters[] = new StrategyParameter(
                name: $parameter->getName(),
                position: $position,
                hasDefault: $hasDefault,
                default: $default,
                variadic: $parameter->isVariadic(),
                byReference: $parameter->isPassedByReference(),
            );
        }

        return $parameters;
    }

    /**
     * Returns the lifetime contract declared on the fetch operation.
     *
     * @param ReflectionClass<object> $reflection
     */
    public function contract(ReflectionClass $reflection): ?TtlContract
    {
        $attributes = $this->method($reflection, 'fetch')?->getAttributes(Ttl::class) ?? [];

        if ($attributes === []) {
            return null;
        }

        $declared = $attributes[0]->newInstance();

        return new TtlContract(
            min: $this->bound($declared->min),
            max: $this->bound($declared->max),
            unconstrained: $declared->unconstrained,
            oneOf: $this->alternatives($declared->oneOf),
        );
    }

    /**
     * Returns the assumptions declared on a create() method.
     *
     * @return list<TtlAssumption>
     */
    public function assumptions(?ReflectionMethod $create): array
    {
        $assumptions = [];

        foreach ($create?->getAttributes(AssumeTtl::class) ?? [] as $attribute) {
            $declared = $attribute->newInstance();
            $assumptions[] = new TtlAssumption(
                strategy: $declared->strategy,
                min: $this->bound($declared->min),
                max: $this->bound($declared->max),
                unconstrained: $declared->unconstrained,
                oneOf: $this->alternatives($declared->oneOf),
            );
        }

        return $assumptions;
    }

    /**
     * Returns one attribute bound converted into the declaration model.
     */
    public function bound(int|ConstructorArg|Arg|null $bound): int|ContractReference|null
    {
        if ($bound === null || is_int($bound)) {
            return $bound;
        }

        if ($bound instanceof ConstructorArg) {
            return new ContractReference(ContractSource::Constructor, $bound->name);
        }

        return new ContractReference(ContractSource::Create, $bound->name);
    }

    /**
     * Preserves each reflected point or interval as a separate declaration.
     *
     * @param list<int|ConstructorArg|Arg|TtlRange>|null $alternatives
     * @return list<TtlContract>|null
     */
    public function alternatives(?array $alternatives): ?array
    {
        if ($alternatives === null) {
            return null;
        }

        return array_map(fn (int|ConstructorArg|Arg|TtlRange $alternative): TtlContract =>
            $alternative instanceof TtlRange
                ? new TtlContract(min: $this->bound($alternative->min), max: $this->bound($alternative->max))
                : new TtlContract(min: $this->bound($alternative), max: $this->bound($alternative)), $alternatives);
    }
}
