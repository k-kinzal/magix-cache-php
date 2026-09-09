<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Reader;

use Magix\Cache\Cached;
use Magix\Cache\Cli\Declaration\MetadataFlow;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;

/**
 * Interprets the metadata operations of Cached, leaving opaque transformations unknown.
 */
final readonly class ExpressionFlowReader
{
    /**
     * @param array<string, string> $propertyTypes
     * @param array<string, string> $variableTypes
     */
    public function __construct(private string $class, private array $propertyTypes, private array $variableTypes)
    {
    }

    /**
     * @param array<string, MetadataFlow> $variables
     */
    public function read(Expr $expression, array $variables): MetadataFlow
    {
        return match (true) {
            $expression instanceof Expr\Variable && is_string($expression->name) => $variables[$expression->name] ?? new MetadataFlow('unknown'),
            $expression instanceof Expr\Assign => $this->read($expression->expr, $variables),
            $expression instanceof Expr\Ternary => new MetadataFlow('choice', [
                $this->read($expression->if ?? $expression->cond, $variables), $this->read($expression->else, $variables),
            ]),
            $expression instanceof Expr\Match_ => new MetadataFlow('choice', array_map(
                fn (\PhpParser\Node\MatchArm $arm): MetadataFlow => $this->read($arm->body, $variables),
                array_values($expression->arms),
            )),
            $expression instanceof Expr\BinaryOp\Coalesce => new MetadataFlow('choice', [
                $this->read($expression->left, $variables), $this->read($expression->right, $variables),
            ]),
            $expression instanceof Expr\MethodCall => $this->method($expression, $variables),
            $expression instanceof Expr\StaticCall => $this->staticCall($expression, $variables),
            $expression instanceof Expr\ArrayDimFetch => $this->projection($expression, $variables),
            $expression instanceof Scalar, $expression instanceof Expr\ConstFetch,
            $expression instanceof Expr\Array_, $expression instanceof Expr\BinaryOp,
            $expression instanceof Expr\Cast, $expression instanceof Expr\New_ => new MetadataFlow('none'),
            $expression instanceof Expr\Throw_ => new MetadataFlow('choice'),
            default => new MetadataFlow('unknown'),
        };
    }

    /**
     * @param array<string, MetadataFlow> $variables
     */
    public function method(Expr\MethodCall $call, array $variables): MetadataFlow
    {
        if (!$call->name instanceof Identifier || $call->isFirstClassCallable()) {
            return new MetadataFlow('unknown');
        }

        $name = $call->name->toString();

        if ($name === 'cached' && $call->var instanceof Expr\Variable && $call->var->name === 'this') {
            return $this->callback($this->argument($call, 0), $variables);
        }

        $target = (new DependencyReader())->target($call, $this->class, $this->propertyTypes, $this->variableTypes);

        if ($target !== null && $target[0] !== Cached::class) {
            return new MetadataFlow('call', target: implode('::', $target));
        }

        $receiver = $this->read($call->var, $variables);

        if ($name === 'value') {
            return $target !== null ? new MetadataFlow('none') : $this->extraction($call->var, $variables);
        }

        return match (true) {
            $name === 'map', $name === 'unzip' => new MetadataFlow('preserve', [$receiver]),
            $name === 'flatMap' => new MetadataFlow('meet', [new MetadataFlow('preserve', [$receiver]), $this->callback($this->argument($call, 0), $variables)]),
            $name === 'flatten' => new MetadataFlow('meet', [new MetadataFlow('preserve', [$receiver]), $this->nested($call->var, $variables)]),
            $name === 'zip', in_array($name, ['combine2', 'combine3', 'combine4', 'combine5'], true) => new MetadataFlow('meet', [new MetadataFlow('preserve', [$receiver]), ...$this->arguments($call, $variables)]),
            default => new MetadataFlow('unknown'),
        };
    }

    /**
     * A known nested of() exposes its value; other Cached receivers detach metadata.
     *
     * @param array<string, MetadataFlow> $variables
     */
    public function extraction(Expr $receiver, array $variables): MetadataFlow
    {
        if ($receiver instanceof Expr\StaticCall && $receiver->class instanceof Name && $receiver->class->toString() === Cached::class
            && $receiver->name instanceof Identifier && $receiver->name->toString() === 'of') {
            $value = $this->argument($receiver, 0);

            return $value === null ? new MetadataFlow('unknown') : $this->read($value, $variables);
        }

        return new MetadataFlow('value', [$this->read($receiver, $variables)]);
    }

    /**
     * @param array<string, MetadataFlow> $variables
     */
    public function staticCall(Expr\StaticCall $call, array $variables): MetadataFlow
    {
        if (!$call->name instanceof Identifier || !$call->class instanceof Name || $call->isFirstClassCallable()) {
            return new MetadataFlow('unknown');
        }

        if ($call->class->toString() !== Cached::class) {
            $target = (new DependencyReader())->target($call, $this->class, $this->propertyTypes, $this->variableTypes);

            return $target === null ? new MetadataFlow('unknown') : new MetadataFlow('call', target: implode('::', $target));
        }

        return match ($call->name->toString()) {
            'of' => new MetadataFlow('wrap', [$this->metadata($this->argument($call, 1), $variables)]),
            'sequence' => new MetadataFlow('wrap', [$this->collection($this->argument($call, 0), $variables)]),
            'traverse' => new MetadataFlow('wrap', [$this->traverse($call, $variables)]),
            default => new MetadataFlow('unknown'),
        };
    }

    /**
     * Reads callbacks only where the API invokes them, never as independent return paths.
     *
     * @param array<string, MetadataFlow> $variables
     */
    public function callback(?Expr $expression, array $variables): MetadataFlow
    {
        if (!$expression instanceof Expr\ArrowFunction && !$expression instanceof Expr\Closure) {
            return new MetadataFlow('unknown');
        }

        if ($expression instanceof Expr\Closure) {
            $captured = [];

            foreach ($expression->uses as $use) {
                if (is_string($use->var->name) && isset($variables[$use->var->name])) {
                    $captured[$use->var->name] = $variables[$use->var->name];
                }
            }

            $variables = $captured;
        }

        foreach ($expression->params as $parameter) {
            if ($parameter->var instanceof Expr\Variable && is_string($parameter->var->name)) {
                unset($variables[$parameter->var->name]);
            }
        }

        return $expression instanceof Expr\ArrowFunction
            ? $this->read($expression->expr, $variables)
            : (new StatementFlowReader($this))->read(array_values($expression->stmts), $variables);
    }

    /**
     * Explicitly reusing a child's metadata preserves that child's alternatives.
     *
     * @param array<string, MetadataFlow> $variables
     */
    public function metadata(?Expr $expression, array $variables): MetadataFlow
    {
        if ($expression === null || ($expression instanceof Expr\ConstFetch && $expression->name->toLowerString() === 'null')) {
            return new MetadataFlow('none');
        }

        if ($expression instanceof Expr\PropertyFetch && $expression->name instanceof Identifier && $expression->name->toString() === 'metadata') {
            return $this->read($expression->var, $variables);
        }

        return new MetadataFlow('unknown');
    }

    /**
     * Empty traversals contribute no metadata; runtime collections remain unknown.
     *
     * @param array<string, MetadataFlow> $variables
     */
    public function traverse(Expr\StaticCall $call, array $variables): MetadataFlow
    {
        $items = $this->argument($call, 0);

        if (!$items instanceof Expr\Array_) {
            return new MetadataFlow('unknown');
        }

        $inputs = [];

        foreach ($items->items as $item) {
            if ($item->unpack) {
                return new MetadataFlow('unknown');
            }

            $inputs[] = $this->callback($this->argument($call, 1), $variables);
        }

        return new MetadataFlow('meet', $inputs);
    }

    /**
     * One flatten exposes the Cached value created by of() or a map callback.
     *
     * @param array<string, MetadataFlow> $variables
     */
    public function nested(Expr $expression, array $variables): MetadataFlow
    {
        if ($expression instanceof Expr\MethodCall && $expression->name instanceof Identifier && $expression->name->toString() === 'map') {
            return $this->callback($this->argument($expression, 0), $variables);
        }

        if ($expression instanceof Expr\StaticCall && $expression->class instanceof Name && $expression->class->toString() === Cached::class
            && $expression->name instanceof Identifier && $expression->name->toString() === 'of') {
            $value = $this->argument($expression, 0);

            return $value === null ? new MetadataFlow('unknown') : $this->read($value, $variables);
        }

        return new MetadataFlow('unknown');
    }

    /**
     * @param array<string, MetadataFlow> $variables
     */
    public function projection(Expr\ArrayDimFetch $expression, array $variables): MetadataFlow
    {
        $value = $expression->var;

        if ($value instanceof Expr\MethodCall && $value->name instanceof Identifier && $value->name->toString() === 'unzip') {
            return new MetadataFlow('preserve', [$this->read($value->var, $variables)]);
        }

        $flow = $this->read($value, $variables);

        return in_array($flow->kind, ['none', 'value'], true) ? $flow : new MetadataFlow('unknown');
    }

    /**
     * @param array<string, MetadataFlow> $variables
     */
    public function collection(?Expr $expression, array $variables): MetadataFlow
    {
        if (!$expression instanceof Expr\Array_) {
            return new MetadataFlow('unknown');
        }

        $inputs = [];

        foreach ($expression->items as $item) {
            $inputs[] = $item->unpack ? $this->collection($item->value, $variables) : $this->read($item->value, $variables);
        }

        return new MetadataFlow('meet', $inputs);
    }

    /**
     * @param array<string, MetadataFlow> $variables
     * @return list<MetadataFlow>
     */
    public function arguments(Expr\MethodCall $call, array $variables): array
    {
        $inputs = [];

        foreach ($call->args as $argument) {
            if ($argument instanceof Arg) {
                $inputs[] = $argument->unpack ? new MetadataFlow('unknown') : $this->read($argument->value, $variables);
            }
        }

        return $inputs;
    }

    /**
     * Resolves positional or named arguments without evaluating application code.
     */
    public function argument(Expr\MethodCall|Expr\StaticCall $call, int $position): ?Expr
    {
        $names = match ($call->name instanceof Identifier ? $call->name->toString() : '') {
            'cached' => ['compute'],
            'of' => ['value', 'metadata'],
            'sequence' => ['items'],
            'traverse' => ['items', 'transform'],
            'map', 'flatMap' => ['transform'],
            default => [],
        };

        foreach ($call->args as $index => $argument) {
            if ($argument instanceof Arg && !$argument->unpack
                && ($argument->name === null ? $index === $position : $argument->name->toString() === ($names[$position] ?? null))) {
                return $argument->value;
            }
        }

        return null;
    }
}
