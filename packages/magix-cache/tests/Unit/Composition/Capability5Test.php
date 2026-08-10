<?php

declare(strict_types=1);

namespace Tests\Unit\Composition;

use Magix\Cache\Cached;
use Magix\Cache\Composition\Capability5;
use Magix\Cache\Runtime\Metadata\CacheMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Capability5::class)]
#[UsesClass(Cached::class)]
#[UsesClass(CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\CacheTokenSet::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\ConstraintMeet::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\Visibility::class)]
final class Capability5Test extends TestCase
{
    public function testMapTransformsTypedValuesAndMergesEveryMetadata(): void
    {
        $result = Cached::of(1, CacheMetadata::forTtl(50, 100.0, ['one']))
            ->combine5(
                Cached::of('two', CacheMetadata::forTtl(40, 100.0, ['two'])),
                Cached::of(3.0, CacheMetadata::forTtl(30, 100.0, ['three'])),
                Cached::of(true, CacheMetadata::forTtl(20, 100.0, ['four'])),
                Cached::of(null, CacheMetadata::forTtl(10, 100.0, ['five'])),
            )
            ->map(static fn (int $first, string $second, float $third, bool $fourth, null $fifth): array => [
                $first,
                $second,
                $third,
                $fourth,
                $fifth,
            ]);

        self::assertSame([1, 'two', 3.0, true, null], $result->value());
        self::assertSame(110.0, $result->metadata->expiresAt);
        self::assertSame(['five', 'four', 'one', 'three', 'two'], $result->metadata->tags);
    }
}
