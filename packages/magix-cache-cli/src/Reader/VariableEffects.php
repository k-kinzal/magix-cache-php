<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Reader;

use function array_unique;
use function array_values;
use function is_string;

use Magix\Cache\Cli\Declaration\MetadataFlow;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

use function spl_object_id;

/**
 * Reports what a piece of syntax does to the local variables around it.
 *
 * Following a return path needs two separate facts about every statement:
 * which variables it may write, and which it may read. Keeping them here lets
 * the walk decide whether an unmodelled statement matters at all.
 */
final readonly class VariableEffects
{
    /**
     * Returns the local variable names a node may write.
     *
     * @return list<string>
     */
    public function written(Node $node): array
    {
        $names = [];

        foreach ((new NodeFinder())->find($node, $this->write(...)) as $write) {
            $name = $this->target($write);

            if ($name !== null) {
                $names[] = $name;
            }
        }

        foreach ($node instanceof Stmt\Foreach_ ? [$node->keyVar, $node->valueVar] : [] as $bound) {
            if ($bound instanceof Expr\Variable && is_string($bound->name)) {
                $names[] = $bound->name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Returns the variable one write ultimately changes, when it is a local.
     */
    public function target(Node $write): ?string
    {
        return match (true) {
            $write instanceof Expr\Assign, $write instanceof Expr\AssignRef, $write instanceof Expr\AssignOp,
            $write instanceof Expr\PreInc, $write instanceof Expr\PostInc,
            $write instanceof Expr\PreDec, $write instanceof Expr\PostDec => $this->root($write->var),
            default => null,
        };
    }

    /**
     * Returns the variable an assignment target ultimately writes.
     *
     * Appending to $items or setting $items['a'] changes what $items holds,
     * so the write belongs to the variable at the root of the target.
     */
    public function root(Node $target): ?string
    {
        if ($target instanceof Expr\Variable) {
            return is_string($target->name) ? $target->name : null;
        }

        if ($target instanceof Expr\ArrayDimFetch || $target instanceof Expr\PropertyFetch
            || $target instanceof Expr\NullsafePropertyFetch) {
            return $this->root($target->var);
        }

        return null;
    }

    /**
     * Returns the local variable names a node may read.
     *
     * Only the destination of a plain assignment is excluded; every other
     * appearance counts, so a read is never missed.
     *
     * @return list<string>
     */
    public function reads(Node $node): array
    {
        $finder = new NodeFinder();
        $targets = [];

        foreach ($finder->findInstanceOf($node, Expr\Assign::class) as $assign) {
            $targets[spl_object_id($assign->var)] = true;
        }

        $names = [];

        foreach ($finder->findInstanceOf($node, Expr\Variable::class) as $variable) {
            if (is_string($variable->name) && !isset($targets[spl_object_id($variable)])) {
                $names[] = $variable->name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Reports whether a node binds a reference this reader cannot follow.
     */
    public function aliases(Node $node): bool
    {
        return (new NodeFinder())->findFirstInstanceOf($node, Expr\AssignRef::class) !== null;
    }

    /**
     * Reports whether a node is a write to a variable.
     */
    public function write(Node $node): bool
    {
        return $node instanceof Expr\Assign || $node instanceof Expr\AssignRef || $node instanceof Expr\AssignOp
            || $node instanceof Expr\PreInc || $node instanceof Expr\PostInc
            || $node instanceof Expr\PreDec || $node instanceof Expr\PostDec;
    }

    /**
     * Reports whether an expression writes somewhere this reader does not follow.
     */
    public function writes(Expr $expression): bool
    {
        return (new NodeFinder())->findFirst($expression, $this->write(...)) !== null;
    }

    /**
     * Drops every binding a node may have written.
     *
     * @param array<string, MetadataFlow> $variables
     * @return array<string, MetadataFlow>
     */
    public function unbind(Node $node, array $variables): array
    {
        foreach ($this->written($node) as $name) {
            unset($variables[$name]);
        }

        return $variables;
    }

    /**
     * Adds one written value to what an array variable is known to hold.
     *
     * The number of writes a loop performs is not decided here, so the
     * collection keeps every value it may contain rather than a count.
     */
    public function extend(?MetadataFlow $collection, MetadataFlow $value): MetadataFlow
    {
        $held = $collection !== null && $collection->kind === 'collection' ? $collection->inputs : [];

        foreach ($held as $existing) {
            if ($existing === $value) {
                return $collection ?? $value;
            }
        }

        return new MetadataFlow('collection', [...$held, $value]);
    }
}
