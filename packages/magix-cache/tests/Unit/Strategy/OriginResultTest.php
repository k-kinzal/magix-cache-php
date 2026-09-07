<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy;

use Magix\Cache\Cached;
use Magix\Cache\Metadata\CacheMetadata;
use Magix\Cache\Strategy\OriginResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OriginResult::class)]
#[UsesClass(Cached::class)]
#[UsesClass(CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheTokenSet::class)]
#[UsesClass(\Magix\Cache\Metadata\Visibility::class)]
final class OriginResultTest extends TestCase
{
    public function testConstrainMeetsMetadataAtTheOriginalBaseTime(): void
    {
        $cached = Cached::of('value', new CacheMetadata(expiresAt: 120.0, tags: ['dependency']));
        $origin = new OriginResult($cached, 100.0);
        $result = $origin->constrain(new CacheMetadata(expiresAt: 160.0, tags: ['strategy']));

        self::assertSame(100.0, $result->baseTime);
        self::assertSame(120.0, $result->cached->metadata->expiresAt);
        self::assertSame(['dependency', 'strategy'], $result->cached->metadata->tags);
        self::assertSame($cached, $origin->cached);
    }
}
