<?php

declare(strict_types=1);

namespace Tests\Unit\Composition;

use Magix\Cache\Cached;
use Magix\Cache\Composition\Capability2;
use Magix\Cache\Runtime\Metadata\CacheMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Capability2::class)]
#[UsesClass(Cached::class)]
#[UsesClass(CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\CacheTokenSet::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\ConstraintMeet::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\Visibility::class)]
final class Capability2Test extends TestCase
{
    public function testMapTransformsTypedValuesAndMergesMetadata(): void
    {
        $result = Cached::of(2, CacheMetadata::forTtl(20, 100.0, ['number']))
            ->combine2(Cached::of('items', CacheMetadata::forTtl(40, 100.0, ['label'])))
            ->map(static fn (int $count, string $label): string => $count.' '.$label);

        self::assertSame('2 items', $result->value());
        self::assertSame(120.0, $result->metadata->expiresAt);
        self::assertSame(['label', 'number'], $result->metadata->tags);
    }
}
