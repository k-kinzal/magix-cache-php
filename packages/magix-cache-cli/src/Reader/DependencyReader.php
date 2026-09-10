<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Reader;

use function in_array;
use function is_string;

use Magix\Cache\Cli\Declaration\DependencyCall;
use Magix\Cache\Cli\Declaration\KeyParameter;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\VariadicPlaceholder;
use PhpParser\NodeFinder;

use function strtolower;

/**
 * Reads the calls a boundary makes to other cache boundaries.
 */
final readonly class DependencyReader
{
    /**
     * Static call targets that refer back to the declaring class.
     */
    private const array SELF_REFERENCES = ['self', 'static'];

    /**
     * Creates a dependency reader.
     */
    public function __construct(private NodeFinder $finder = new NodeFinder())
    {
    }

    /**
     * Returns every resolvable call the boundary body performs.
     *
     * @param array<string, string> $propertyTypes
     * @param list<KeyParameter> $parameters
     * @return list<DependencyCall>
     */
    public function read(ClassMethod $method, string $class, array $propertyTypes, array $parameters): array
    {
        $statements = $method->stmts ?? [];
        $parameterTypes = $this->parameterTypes($method);
        $assignments = $this->variableTypes($statements, $propertyTypes);
        $calls = [];

        foreach ($statements === [] ? [] : $this->finder->find($statements, static fn (Node $node): bool => $node instanceof MethodCall || $node instanceof StaticCall) as $node) {
            if (!$node instanceof MethodCall && !$node instanceof StaticCall) {
                continue;
            }

            $target = $this->target($node, $class, $propertyTypes, $this->bindings($parameterTypes, $assignments, $node->getStartFilePos()));

            if ($target === null) {
                continue;
            }

            $calls[] = new DependencyCall(
                class: $target[0],
                method: $target[1],
                line: $node->getStartLine(),
                forwarded: $this->forwarded($node->args, $parameters),
            );
        }

        return $calls;
    }

    /**
     * Returns class types for method arguments, including injected query objects.
     *
     * @return array<string, string>
     */
    public function parameterTypes(ClassMethod $method): array
    {
        $types = [];
        $reader = new TypeReader();

        foreach ($method->params as $parameter) {
            $type = $reader->className($parameter->type);

            if ($type !== null && !$parameter->variadic && $parameter->var instanceof Variable && is_string($parameter->var->name)) {
                $types[$parameter->var->name] = $type;
            }
        }

        return $types;
    }

    /**
     * Retains unresolved call sites without inventing dependencies or metadata effects.
     *
     * @param array<string, string> $propertyTypes
     * @return list<array{method: string, line: int}>
     */
    public function unresolved(ClassMethod $method, string $class, array $propertyTypes): array
    {
        $statements = $method->stmts ?? [];
        $parameters = $this->parameterTypes($method);
        $assignments = $this->variableTypes($statements, $propertyTypes);
        $unresolved = [];

        foreach ($this->finder->find($statements, static fn (Node $node): bool => $node instanceof MethodCall || $node instanceof StaticCall) as $call) {
            if (($call instanceof MethodCall || $call instanceof StaticCall) && $this->target($call, $class, $propertyTypes, $this->bindings($parameters, $assignments, $call->getStartFilePos())) === null) {
                $unresolved[] = ['method' => $call->name instanceof Identifier ? $call->name->toString() : '(dynamic)', 'line' => $call->getStartLine()];
            }
        }

        return $unresolved;
    }

    /**
     * Returns where each local variable is bound to a known object type.
     *
     * Assignments keep their source position, because a variable reassigned
     * later must not decide which method an earlier call reached.
     *
     * @param array<Node\Stmt> $statements
     * @param array<string, string> $propertyTypes
     * @return array<string, list<array{int, string}>>
     */
    public function variableTypes(array $statements, array $propertyTypes): array
    {
        $assignments = [];

        foreach ($statements === [] ? [] : $this->finder->findInstanceOf($statements, Assign::class) as $assign) {
            if (!$assign->var instanceof Variable || !is_string($assign->var->name)) {
                continue;
            }

            $type = $this->assigned($assign->expr, $propertyTypes);

            if ($type !== null) {
                $assignments[$assign->var->name][] = [$assign->getStartFilePos(), $type];
            }
        }

        return $assignments;
    }

    /**
     * Returns the object type an assignment binds, when it is one this reader knows.
     *
     * @param array<string, string> $propertyTypes
     */
    public function assigned(Node\Expr $expression, array $propertyTypes): ?string
    {
        if ($expression instanceof New_ && $expression->class instanceof Name) {
            return $expression->class->toString();
        }

        if (
            $expression instanceof PropertyFetch
            && $expression->var instanceof Variable
            && $expression->var->name === 'this'
            && $expression->name instanceof Identifier
        ) {
            return $propertyTypes[$expression->name->toString()] ?? null;
        }

        return null;
    }

    /**
     * Returns the types in scope at one position in the method body.
     *
     * A parameter type holds until a local assignment replaces it, and only
     * assignments that already ran can do so.
     *
     * @param array<string, string> $parameterTypes
     * @param array<string, list<array{int, string}>> $assignments
     * @return array<string, string>
     */
    public function bindings(array $parameterTypes, array $assignments, int $position): array
    {
        $types = $parameterTypes;

        foreach ($assignments as $name => $bound) {
            foreach ($bound as [$at, $type]) {
                if ($at < $position) {
                    $types[$name] = $type;
                }
            }
        }

        return $types;
    }

    /**
     * Returns the class and method a call refers to, when both are known.
     *
     * @param array<string, string> $propertyTypes
     * @param array<string, string> $variableTypes
     * @return array{string, string}|null
     */
    public function target(MethodCall|StaticCall $node, string $class, array $propertyTypes, array $variableTypes): ?array
    {
        if (!$node->name instanceof Identifier) {
            return null;
        }

        $method = $node->name->toString();

        if ($node instanceof StaticCall) {
            if (!$node->class instanceof Name) {
                return null;
            }

            $target = $node->class->toString();

            return [in_array(strtolower($target), self::SELF_REFERENCES, true) ? $class : $target, $method];
        }

        $receiver = $node->var;

        if ($receiver instanceof Variable && is_string($receiver->name)) {
            if ($receiver->name === 'this') {
                return [$class, $method];
            }

            $type = $variableTypes[$receiver->name] ?? null;

            return $type === null ? null : [$type, $method];
        }

        if (
            !$receiver instanceof PropertyFetch
            || !$receiver->var instanceof Variable
            || $receiver->var->name !== 'this'
            || !$receiver->name instanceof Identifier
        ) {
            return null;
        }

        $type = $propertyTypes[$receiver->name->toString()] ?? null;

        return $type === null ? null : [$type, $method];
    }

    /**
     * Returns which caller parameters are passed on to the called method.
     *
     * @param array<Arg|VariadicPlaceholder> $arguments
     * @param list<KeyParameter> $parameters
     * @return array<int|string, string>
     */
    public function forwarded(array $arguments, array $parameters): array
    {
        $keyed = [];

        foreach ($parameters as $parameter) {
            if (!$parameter->ignored) {
                $keyed[$parameter->name] = true;
            }
        }

        $forwarded = [];
        $position = 0;

        foreach ($arguments as $argument) {
            if (!$argument instanceof Arg) {
                continue;
            }

            $value = $argument->value;
            $name = $argument->name?->toString() ?? $position;

            if ($argument->name === null) {
                ++$position;
            }

            if ($value instanceof Variable && is_string($value->name) && isset($keyed[$value->name])) {
                $forwarded[$name] = $value->name;
            }
        }

        return $forwarded;
    }
}
