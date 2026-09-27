<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Invariance;

use Magix\Cache\Cli\Reader\StrategyReader;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\Int_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the existing recipe alias scope and expansion limit before replacement.
 */
#[CoversClass(StrategyReader::class)]
final class BindingBaselineTest extends TestCase
{
    /**
     * Aliases are resolved in the supplied binding map, not PHP execution order.
     */
    public function testBoundUsesTheSuppliedAliasesWithoutExecutingTheLeaf(): void
    {
        $leaf = new Int_(42);
        $bindings = ['first' => new Variable('second'), 'second' => $leaf];

        self::assertSame($leaf, (new StrategyReader())->bound(new Variable('first'), $bindings));
        self::assertSame($leaf, (new StrategyReader())->bound(new Variable('first'), array_reverse($bindings)));
    }

    /**
     * The ninth reference remains an expression rather than an invented value.
     */
    public function testBoundPreservesTheExpansionBudget(): void
    {
        $leaf = new Int_(42);
        $bindings = ['v9' => $leaf];

        for ($index = 8; $index >= 0; --$index) {
            $bindings['v'.$index] = new Variable('v'.($index + 1));
        }

        $reader = new StrategyReader();
        self::assertSame($bindings['v7'], $reader->bound(new Variable('v0'), $bindings));
        self::assertSame($leaf, $reader->bound(new Variable('v2'), $bindings));
    }

    /**
     * Cycles and absent names preserve unresolved syntax at the same limit.
     */
    public function testBoundKeepsCyclicAndMissingReferencesUnresolved(): void
    {
        $first = new Variable('a');
        $second = new Variable('b');
        $reader = new StrategyReader();

        self::assertSame($first, $reader->bound($first, []));
        self::assertSame($first, $reader->bound($first, ['a' => $second, 'b' => $first]));
        self::assertSame($first, $reader->bound($first, ['a' => new Int_(1)], 0));
    }
}
