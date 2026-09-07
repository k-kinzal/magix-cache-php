<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy;

use Magix\Cache\Cached;
use Magix\Cache\Metadata\CacheMetadata;
use Magix\Cache\Strategy\CacheRead;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheRead::class)]
#[UsesClass(Cached::class)]
#[UsesClass(CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheTokenSet::class)]
#[UsesClass(\Magix\Cache\Metadata\Visibility::class)]
final class CacheReadTest extends TestCase
{
    public function testIsFreshSeparatesExpirationFromRetention(): void
    {
        $read = new CacheRead(Cached::of('value', new CacheMetadata(expiresAt: 120.0)), 150.0);

        self::assertTrue($read->isFresh(119.0));
        self::assertFalse($read->isFresh(120.0));
        self::assertFalse($read->isFresh(150.0));
        self::assertSame(150.0, $read->retainedUntil);
    }
}
