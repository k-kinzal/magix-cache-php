<?php

declare(strict_types=1);

namespace Tests\Unit\Composition;

use Magix\Cache\AsyncCached;
use Magix\Cache\Composition\AsyncCapability6;
use Magix\Cache\Metadata\CacheMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\UsesNamespace('Magix\Cache')]
#[CoversClass(AsyncCapability6::class)]
#[UsesClass(AsyncCached::class)]
#[UsesClass(CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheTokenSet::class)]
#[UsesClass(\Magix\Cache\Metadata\Visibility::class)]
final class AsyncCapability6Test extends TestCase
{
    public function testMapTransformsSixTypedValuesAndMergesEveryMetadata(): void
    {
        $result = AsyncCached::of(1, CacheMetadata::forTtl(60, 100.0, ['one']))
            ->combine6(
                AsyncCached::of('two', CacheMetadata::forTtl(50, 100.0, ['two'])),
                AsyncCached::of(3.0, CacheMetadata::forTtl(40, 100.0, ['three'])),
                AsyncCached::of(true, CacheMetadata::forTtl(30, 100.0, ['four'])),
                AsyncCached::of(null, CacheMetadata::forTtl(20, 100.0, ['five'])),
                AsyncCached::of(6, CacheMetadata::forTtl(10, 100.0, ['six'])),
            )
            ->map(static fn (int $first, string $second, float $third, bool $fourth, null $fifth, int $sixth): array => [
                $first,
                $second,
                $third,
                $fourth,
                $fifth,
                $sixth,
            ]);

        self::assertSame([1, 'two', 3.0, true, null, 6], $result->value());
        self::assertSame(110.0, $result->toCached()->metadata->expiresAt);
        self::assertSame(['five', 'four', 'one', 'six', 'three', 'two'], $result->toCached()->metadata->tags);
    }
}
