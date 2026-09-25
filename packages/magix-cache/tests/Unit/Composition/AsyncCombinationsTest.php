<?php

declare(strict_types=1);

namespace Tests\Unit\Composition;

use Magix\Cache\AsyncCached;
use Magix\Cache\Composition\AsyncCombinations;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\PendingResult;

#[CoversTrait(AsyncCombinations::class)]
#[UsesNamespace('Magix\Cache')]
final class AsyncCombinationsTest extends TestCase
{
    public function testCombinationsKeepTheirSourcesPendingUntilExtraction(): void
    {
        $source = new PendingResult(2);
        $result = AsyncCached::fromPromise($source->promise())->combine2(AsyncCached::of(3))->map(static fn (int $a, int $b): int => $a + $b);
        self::assertSame(0, $source->waits);
        self::assertSame(5, $result->value());
        self::assertSame(1, $source->waits);
    }
}
