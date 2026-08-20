<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Reader;

use Magix\Cache\Attribute\Cache;
use Magix\Cache\CachePolicy;
use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\PolicyDeclaration;
use Magix\Cache\Cli\Declaration\PolicySource;
use Magix\Cache\Runtime\Metadata\CacheMetadata;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;

use function strtolower;

/**
 * Reads one cached() call site as a complete boundary declaration.
 */
final readonly class BoundaryReader
{
    /**
     * Parameter order of the cached() method provided by Cacheable.
     */
    private const array PARAMETERS = ['compute', 'policy', 'strategy'];

    /**
     * Creates a boundary reader.
     */
    public function __construct(
        private AttributeReader $attributes = new AttributeReader(),
        private PolicyReader $policies = new PolicyReader(),
        private ParameterReader $parameters = new ParameterReader(),
        private DependencyReader $dependencies = new DependencyReader(),
        private NodeFinder $finder = new NodeFinder(),
    ) {
    }

    /**
     * Returns the boundary a method declares, or null when it caches nothing.
     *
     * @param array<string, string> $propertyTypes
     */
    public function read(
        ClassMethod $method,
        string $class,
        string $file,
        array $propertyTypes,
        ?PolicyDeclaration $classPolicy,
    ): ?BoundaryDeclaration {
        $statements = $method->stmts ?? [];

        if ($statements === []) {
            return null;
        }

        $call = $this->finder->findFirst($statements, static fn (Node $node): bool => $node instanceof MethodCall
            && $node->var instanceof Variable
            && $node->var->name === 'this'
            && $node->name instanceof Identifier
            && $node->name->toString() === 'cached');

        if (!$call instanceof MethodCall) {
            return null;
        }

        $expressions = $this->arguments($call);
        $parameters = $this->parameters->read($method);

        return new BoundaryDeclaration(
            class: $class,
            method: $method->name->toString(),
            file: $file,
            line: $method->getStartLine(),
            policy: $this->policy($expressions, $method, $classPolicy),
            parameters: $parameters,
            dependencies: $this->dependencies->read($method, $class, $propertyTypes, $parameters),
            hasStrategy: isset($expressions['strategy']),
            suppliesMetadata: $this->finder->findFirst(
                $statements,
                static fn (Node $node): bool => $node instanceof Name && $node->toString() === CacheMetadata::class,
            ) !== null,
        );
    }

    /**
     * Returns the policy a class declares for all of its boundaries.
     */
    public function classPolicy(Class_ $class): ?PolicyDeclaration
    {
        $attribute = $this->attributes->find($class->attrGroups, Cache::class);

        return $attribute === null ? null : $this->policies->read($attribute->args, PolicySource::ClassAttribute);
    }

    /**
     * Returns the expression written for each cached() parameter.
     *
     * @return array<string, Expr>
     */
    public function arguments(MethodCall $call): array
    {
        $expressions = [];
        $position = 0;

        foreach ($call->args as $argument) {
            if (!$argument instanceof Arg || $argument->unpack) {
                continue;
            }

            $name = $argument->name?->toString() ?? (self::PARAMETERS[$position] ?? null);

            if ($argument->name === null) {
                ++$position;
            }

            if ($name !== null) {
                $expressions[$name] = $argument->value;
            }
        }

        return $expressions;
    }

    /**
     * Returns the policy that applies to a cached() call.
     *
     * @param array<string, Expr> $expressions
     */
    public function policy(array $expressions, ClassMethod $method, ?PolicyDeclaration $classPolicy): ?PolicyDeclaration
    {
        $declared = $expressions['policy'] ?? null;

        if ($declared instanceof New_ && $declared->class instanceof Name && $declared->class->toString() === CachePolicy::class) {
            return $this->policies->read($declared->args, PolicySource::ExplicitPolicy);
        }

        if ($declared !== null && !($declared instanceof ConstFetch && strtolower($declared->name->toString()) === 'null')) {
            return new PolicyDeclaration(source: PolicySource::Unresolved, ttl: null);
        }

        $attribute = $this->attributes->find($method->attrGroups, Cache::class);

        if ($attribute !== null) {
            return $this->policies->read($attribute->args, PolicySource::MethodAttribute);
        }

        return $classPolicy;
    }
}
