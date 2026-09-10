<?php

declare(strict_types=1);

namespace Tests\Unit\Composition;

use Magix\Cache\Cached;
use Magix\Cache\Composition\Capability9;
use Magix\Cache\Metadata\CacheMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Capability9::class)]
#[UsesClass(Cached::class)]
#[UsesClass(CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheTokenSet::class)]
#[UsesClass(\Magix\Cache\Metadata\Visibility::class)]
final class Capability9Test extends TestCase
{
    public function testMapTransformsNineTypedValuesAndMergesEveryMetadata(): void
    {
        $result = Cached::of(1, CacheMetadata::forTtl(90, 100.0, ['one']))
            ->combine9(
                Cached::of('two', CacheMetadata::forTtl(80, 100.0, ['two'])),
                Cached::of(3.0, CacheMetadata::forTtl(70, 100.0, ['three'])),
                Cached::of(true, CacheMetadata::forTtl(60, 100.0, ['four'])),
                Cached::of(null, CacheMetadata::forTtl(50, 100.0, ['five'])),
                Cached::of(6, CacheMetadata::forTtl(40, 100.0, ['six'])),
                Cached::of('seven', CacheMetadata::forTtl(30, 100.0, ['seven'])),
                Cached::of(8.0, CacheMetadata::forTtl(20, 100.0, ['eight'])),
                Cached::of(false, CacheMetadata::forTtl(10, 100.0, ['nine'])),
            )
            ->map(static fn (int $first, string $second, float $third, bool $fourth, null $fifth, int $sixth, string $seventh, float $eighth, bool $ninth): array => [
                $first,
                $second,
                $third,
                $fourth,
                $fifth,
                $sixth,
                $seventh,
                $eighth,
                $ninth,
            ]);

        self::assertSame([1, 'two', 3.0, true, null, 6, 'seven', 8.0, false], $result->value());
        self::assertSame(110.0, $result->metadata->expiresAt);
        self::assertSame(['eight', 'five', 'four', 'nine', 'one', 'seven', 'six', 'three', 'two'], $result->metadata->tags);
    }
}
