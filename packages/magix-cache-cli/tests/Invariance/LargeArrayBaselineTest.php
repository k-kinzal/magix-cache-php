<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Invariance;

use Magix\Cache\Cli\Declaration\ConstantCatalog;
use Magix\Cache\Cli\Reader\ExpressionDeriver;
use Magix\Cache\Cli\Reader\LiteralReader;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Retains large-array behavior verified against the original Magix reader.
 */
#[CoversClass(LiteralReader::class)]
#[UsesClass(ConstantCatalog::class)]
#[UsesClass(ExpressionDeriver::class)]
#[Medium]
final class LargeArrayBaselineTest extends TestCase
{
    /**
     * Keeps every tag known at the size that previously exhausted Deriver memory.
     */
    public function testValueRetainsEveryTagInALargeList(): void
    {
        $tags = array_map(static fn (int $index): string => 'tag'.$index, range(0, 8191));
        $items = array_map(static fn (string $tag): ArrayItem => new ArrayItem(new String_($tag)), $tags);

        self::assertSame($tags, (new LiteralReader())->value(new Array_($items)));
    }

    /**
     * Keeps the last value at each key without changing its original position.
     */
    public function testValuePreservesOrderingAndReplacementInALargeKeyedArray(): void
    {
        $values = range(0, 8191);
        $keys = array_map(static fn (int $index): string => 'key'.($index % 4096), $values);
        $items = array_map(static fn (string $key, int $value): ArrayItem => new ArrayItem(new Int_($value), new String_($key)), $keys, $values);

        self::assertSame(array_combine($keys, $values), (new LiteralReader())->value(new Array_($items)));
    }
}
