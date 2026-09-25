<?php

declare(strict_types=1);

namespace Tests\Unit\Composition;

use Magix\Cache\AsyncCached;
use Magix\Cache\Composition\AsyncCapability2;
use Magix\Cache\Metadata\CacheMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\UsesNamespace('Magix\Cache')]
#[CoversClass(AsyncCapability2::class)]
#[UsesClass(AsyncCached::class)]
#[UsesClass(CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheTokenSet::class)]
#[UsesClass(\Magix\Cache\Metadata\Visibility::class)]
final class AsyncCapability2Test extends TestCase
{
    public function testMapTransformsTypedValuesAndMergesMetadata(): void
    {
        $result = AsyncCached::of(2, CacheMetadata::forTtl(20, 100.0, ['number']))
            ->combine2(AsyncCached::of('items', CacheMetadata::forTtl(40, 100.0, ['label'])))
            ->map(static fn (int $count, string $label): string => $count.' '.$label);

        self::assertSame('2 items', $result->value());
        self::assertSame(120.0, $result->toCached()->metadata->expiresAt);
        self::assertSame(['label', 'number'], $result->toCached()->metadata->tags);
    }
}
