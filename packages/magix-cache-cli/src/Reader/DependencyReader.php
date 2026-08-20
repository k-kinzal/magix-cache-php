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
        $variableTypes = $this->variableTypes($statements, $propertyTypes);
        $calls = [];

        foreach ($statements === [] ? [] : $this->finder->find($statements, static fn (Node $node): bool => $node instanceof MethodCall || $node instanceof StaticCall) as $node) {
            if (!$node instanceof MethodCall && !$node instanceof StaticCall) {
                continue;
            }

            $target = $this->target($node, $class, $propertyTypes, $variableTypes);

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
     * Returns the class name of local variables that hold known objects.
     *
     * @param array<Node\Stmt> $statements
     * @param array<string, string> $propertyTypes
     * @return array<string, string>
     */
    public function variableTypes(array $statements, array $propertyTypes): array
    {
        $types = [];

        foreach ($statements === [] ? [] : $this->finder->findInstanceOf($statements, Assign::class) as $assign) {
            if (!$assign->var instanceof Variable || !is_string($assign->var->name)) {
                continue;
            }

            $expression = $assign->expr;

            if ($expression instanceof New_ && $expression->class instanceof Name) {
                $types[$assign->var->name] = $expression->class->toString();

                continue;
            }

            if (
                $expression instanceof PropertyFetch
                && $expression->var instanceof Variable
                && $expression->var->name === 'this'
                && $expression->name instanceof Identifier
            ) {
                $type = $propertyTypes[$expression->name->toString()] ?? null;

                if ($type !== null) {
                    $types[$assign->var->name] = $type;
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
