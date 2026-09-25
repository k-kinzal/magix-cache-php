<?php

declare(strict_types=1);

namespace Tests\Unit\Composition;

use Magix\Cache\AsyncCached;
use Magix\Cache\Composition\AsyncCapability10;
use Magix\Cache\Metadata\CacheMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\UsesNamespace('Magix\Cache')]
#[CoversClass(AsyncCapability10::class)]
#[UsesClass(AsyncCached::class)]
#[UsesClass(CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheTokenSet::class)]
#[UsesClass(\Magix\Cache\Metadata\Visibility::class)]
final class AsyncCapability10Test extends TestCase
{
    public function testMapTransformsTenTypedValuesAndMergesEveryMetadata(): void
    {
        $result = AsyncCached::of(1, CacheMetadata::forTtl(100, 100.0, ['one']))
            ->combine10(
                AsyncCached::of('two', CacheMetadata::forTtl(90, 100.0, ['two'])),
                AsyncCached::of(3.0, CacheMetadata::forTtl(80, 100.0, ['three'])),
                AsyncCached::of(true, CacheMetadata::forTtl(70, 100.0, ['four'])),
                AsyncCached::of(null, CacheMetadata::forTtl(60, 100.0, ['five'])),
                AsyncCached::of(6, CacheMetadata::forTtl(50, 100.0, ['six'])),
                AsyncCached::of('seven', CacheMetadata::forTtl(40, 100.0, ['seven'])),
                AsyncCached::of(8.0, CacheMetadata::forTtl(30, 100.0, ['eight'])),
                AsyncCached::of(false, CacheMetadata::forTtl(20, 100.0, ['nine'])),
                AsyncCached::of(null, CacheMetadata::forTtl(10, 100.0, ['ten'])),
            )
            ->map(static fn (int $first, string $second, float $third, bool $fourth, null $fifth, int $sixth, string $seventh, float $eighth, bool $ninth, null $tenth): array => [
                $first,
                $second,
                $third,
                $fourth,
                $fifth,
                $sixth,
                $seventh,
                $eighth,
                $ninth,
                $tenth,
            ]);

        self::assertSame([1, 'two', 3.0, true, null, 6, 'seven', 8.0, false, null], $result->value());
        self::assertSame(110.0, $result->toCached()->metadata->expiresAt);
        self::assertSame(['eight', 'five', 'four', 'nine', 'one', 'seven', 'six', 'ten', 'three', 'two'], $result->toCached()->metadata->tags);
    }
}
