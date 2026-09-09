<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Reader;

use function array_map;
use function count;
use function in_array;
use function is_string;

use Magix\Cache\Cli\Declaration\ExpirationContract;
use Magix\Cache\Cli\Declaration\StrategyArgument;
use Magix\Cache\Cli\Declaration\StrategyDeclaration;
use Magix\Cache\Cli\Declaration\StrategyInstantiation;
use Magix\Cache\Cli\Declaration\StrategyParameter;
use Magix\Cache\Cli\Declaration\TtlAssumption;
use Magix\Cache\Cli\Declaration\TtlContract;
use Magix\Cache\Cli\Declaration\Unresolved;
use Magix\Cache\Strategy\CacheStrategy;
use Magix\Cache\Strategy\CompositeCacheStrategy;
use Magix\Cache\Strategy\Contract\AssumeTtl;
use Magix\Cache\Strategy\Contract\ExpiresAt;
use Magix\Cache\Strategy\Contract\Ttl as TtlAttribute;
use Magix\Cache\Strategy\StrategyDefinition;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\VariadicPlaceholder;
use PhpParser\NodeFinder;

/**
 * Reads one strategy class into its contracts and construction shape.
 *
 * The reader takes the same construction code the runtime executes: the
 * constructor and create() parameters, the strategies create() composes in
 * order, the lifetime contract on fetch(), and the assumptions declared on
 * create(). What it cannot read stays null with a note; nothing is filled
 * with a guess, and no user code is executed.
 */
final readonly class StrategyReader
{
    /**
     * Creates a strategy reader.
     */
    public function __construct(
        private ContractReader $contracts = new ContractReader(),
        private AttributeReader $attributes = new AttributeReader(),
        private LiteralReader $literals = new LiteralReader(),
    ) {
    }

    /**
     * Returns the strategy a class declares, or null for other classes.
     */
    public function read(Class_ $node, string $file = ''): ?StrategyDeclaration
    {
        $composite = $node->extends?->toString() === CompositeCacheStrategy::class;
        $leaf = in_array(CacheStrategy::class, array_map(static fn (Name $name): string => $name->toString(), $node->implements), true);

        if (!$composite && !$leaf) {
            return null;
        }

        $create = $node->getMethod('create');
        $hasCreate = $create !== null && $create->isStatic() && $create->isPublic();
        [$composed, $notes] = $hasCreate ? $this->composition($create) : [null, []];
        $name = $node->namespacedName?->toString() ?? ($node->name?->toString() ?? '');

        return new StrategyDeclaration(
            name: $name,
            file: $file,
            line: $node->getStartLine(),
            parameters: $this->parameters($node->getMethod('__construct')),
            createParameters: $hasCreate ? $this->parameters($create) : [],
            hasCreate: $hasCreate,
            composed: $composed,
            ttl: $this->contract($node->getMethod('fetch')),
            expiration: $this->expiration($node->getMethod('fetch')),
            assumptions: $hasCreate ? $this->assumptions($create) : [],
            notes: $notes,
            constructible: $leaf && !$node->isAbstract(),
            definitionProblem: $hasCreate ? $this->definitionProblem($create) : null,
        );
    }

    /**
     * Returns the declared parameters of a method in order.
     *
     * @return list<StrategyParameter>
     */
    public function parameters(?ClassMethod $method): array
    {
        $parameters = [];

        foreach ($method->params ?? [] as $position => $parameter) {
            if (!$parameter->var instanceof Variable || !is_string($parameter->var->name)) {
                continue;
            }

            $default = $parameter->default === null ? null : $this->literals->value($parameter->default);

            $parameters[] = new StrategyParameter(
                name: $parameter->var->name,
                position: $position,
                hasDefault: $parameter->default !== null,
                default: $default === LiteralReader::UNRESOLVED ? Unresolved::Value : $default,
                variadic: $parameter->variadic,
                byReference: $parameter->byRef,
            );
        }

        return $parameters;
    }

    /**
     * Returns the lifetime contract declared on one operation, when any.
     */
    public function contract(?ClassMethod $method): ?TtlContract
    {
        if ($method === null) {
            return null;
        }

        $attribute = $this->attributes->find($method->attrGroups, TtlAttribute::class);

        return $attribute === null ? null : $this->contracts->ttl($attribute);
    }

    /**
     * Returns the daily expiration contract declared on the origin operation.
     */
    public function expiration(?ClassMethod $method): ?ExpirationContract
    {
        $attributes = [];

        foreach ($method->attrGroups ?? [] as $group) {
            foreach ($group->attrs as $attribute) {
                if ($attribute->name->toString() === ExpiresAt::class) {
                    $attributes[] = $attribute;
                }
            }
        }

        if ($attributes === []) {
            return null;
        }

        $contract = (new ExpirationReader())->read($attributes[0]);

        return count($attributes) === 1 ? $contract : new ExpirationContract(
            $contract->at,
            $contract->until,
            $contract->timezone,
            [...$contract->problems, '#[ExpiresAt] cannot be repeated'],
        );
    }

    /**
     * Returns every assumption declared on create(), in order.
     *
     * @return list<TtlAssumption>
     */
    public function assumptions(ClassMethod $create): array
    {
        $assumptions = [];

        foreach ($create->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if ($attribute->name->toString() !== AssumeTtl::class) {
                    continue;
                }

                $assumption = $this->contracts->assumption($attribute);

                if ($assumption !== null) {
                    $assumptions[] = $assumption;
                }
            }
        }

        return $assumptions;
    }

    /**
     * Returns the strategies create() composes, or null with a note.
     *
     * Only a create() whose top level returns one compose() call or one
     * construction is followed; branches and helpers keep the composition
     * statically unknown instead of guessing one path.
     *
     * @return array{list<StrategyInstantiation>|null, list<string>}
     */
    public function composition(ClassMethod $create): array
    {
        $returns = [];

        foreach ($create->stmts ?? [] as $statement) {
            if ($statement instanceof Return_) {
                $returns[] = $statement;
            }
        }

        if (count($returns) !== 1 || $returns[0]->expr === null) {
            return [null, ['create() does not resolve to a single composition statically']];
        }

        $expression = $returns[0]->expr;
        $children = $this->compose($expression) ?? [$expression];
        $instantiations = [];

        foreach ($children as $child) {
            $instantiation = $this->instantiation($child);

            if ($instantiation === null) {
                return [null, ['an argument of the composition cannot be read statically']];
            }

            $instantiations[] = $instantiation;
        }

        return [$instantiations, []];
    }

    /**
     * Returns the argument expressions of a compose() call, when it is one.
     *
     * @return list<Expr>|null
     */
    public function compose(Expr $expression): ?array
    {
        if (!$expression instanceof StaticCall
            || !$expression->name instanceof Identifier
            || $expression->name->toString() !== 'compose'
            || !$expression->class instanceof Name
            || !in_array($expression->class->toString(), ['parent', 'self', 'static', CompositeCacheStrategy::class, StrategyDefinition::class], true)) {
            return null;
        }

        $children = [];

        foreach ($expression->args as $argument) {
            if (!$argument instanceof Arg || $argument->unpack) {
                return [$expression];
            }

            $children = [...$children, ...($this->compose($argument->value) ?? [$argument->value])];
        }

        return $children;
    }

    /**
     * Returns one composed construction, or null when it cannot be read.
     */
    public function instantiation(Expr $expression): ?StrategyInstantiation
    {
        if ($expression instanceof New_ && $expression->class instanceof Name) {
            if ($expression->class->toString() === StrategyDefinition::class) {
                return $this->definition($expression);
            }

            return new StrategyInstantiation(
                class: $expression->class->toString(),
                line: $expression->getStartLine(),
                problem: 'create() must return construction definitions; replace new with StrategyDefinition::of()',
            );
        }

        if ($expression instanceof StaticCall && $expression->class instanceof Name
            && $expression->class->toString() === StrategyDefinition::class
            && $expression->name instanceof Identifier && $expression->name->toString() === 'of') {
            return $this->definition($expression);
        }

        if ($expression instanceof StaticCall
            && $expression->name instanceof Identifier
            && $expression->name->toString() === 'create'
            && $expression->class instanceof Name
            && !in_array($expression->class->toString(), ['parent', 'self', 'static'], true)) {
            $arguments = $this->arguments($expression->args);

            return $arguments === null ? null : new StrategyInstantiation(
                class: $expression->class->toString(),
                arguments: $arguments,
                viaCreate: true,
                line: $expression->getStartLine(),
            );
        }

        return null;
    }

    /**
     * Reads the class and constructor arguments of a construction definition.
     */
    public function definition(StaticCall|New_ $call): ?StrategyInstantiation
    {
        $classArgument = null;
        $arguments = [];

        foreach ($call->args as $argument) {
            if (!$argument instanceof Arg || $argument->unpack) {
                return null;
            }

            if ($argument->name?->toString() === 'class' || ($classArgument === null && $argument->name === null)) {
                $classArgument = $argument;
            } else {
                $arguments[] = $argument;
            }
        }

        $class = $classArgument === null ? null : $this->literals->value($classArgument->value);
        $bound = $this->arguments($arguments);

        if (!is_string($class) || $class === LiteralReader::UNRESOLVED || $bound === null) {
            return null;
        }

        $shared = (new NodeFinder())->findFirst(
            $arguments,
            static fn (\PhpParser\Node $node): bool =>
            $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction
            || ($node instanceof New_ && (!$node->class instanceof Name || $node->class->toString() !== StrategyDefinition::class))
        );
        $problem = $shared === null ? null : 'StrategyDefinition arguments cannot contain objects or closures; keep execution state in the strategy';

        return new StrategyInstantiation($class, $bound, line: $call->getStartLine(), problem: $problem);
    }

    /**
     * Rejects a factory whose declared result cannot be a construction definition.
     */
    public function definitionProblem(ClassMethod $create): ?string
    {
        $type = $create->returnType;

        if (($type instanceof Name || $type instanceof Identifier)
            && !in_array($type->toString(), [StrategyDefinition::class, 'object', 'mixed'], true)) {
            return 'create() must return StrategyDefinition, not an executable strategy instance';
        }

        return null;
    }

    /**
     * Returns written call arguments, or null when their shape is unreadable.
     *
     * @param array<Arg|VariadicPlaceholder> $arguments
     * @return list<StrategyArgument>|null
     */
    public function arguments(array $arguments): ?array
    {
        $read = [];

        foreach ($arguments as $argument) {
            if (!$argument instanceof Arg || $argument->unpack) {
                return null;
            }

            if ($argument->value instanceof Variable && is_string($argument->value->name)) {
                $read[] = new StrategyArgument($argument->name?->toString(), Unresolved::Value, $argument->value->name);

                continue;
            }

            $value = $this->literals->value($argument->value);
            $read[] = new StrategyArgument(
                $argument->name?->toString(),
                $value === LiteralReader::UNRESOLVED ? Unresolved::Value : $value,
            );
        }

        return $read;
    }
}
