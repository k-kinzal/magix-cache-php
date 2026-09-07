<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy;

use Magix\Cache\Cached;
use Magix\Cache\Metadata\CacheMetadata;
use Magix\Cache\Strategy\CacheWrite;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheWrite::class)]
#[UsesClass(Cached::class)]
#[UsesClass(CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheTokenSet::class)]
#[UsesClass(\Magix\Cache\Metadata\Visibility::class)]
final class CacheWriteTest extends TestCase
{
    public function testRetainUntilOnlyExtendsPhysicalRetention(): void
    {
        $cached = Cached::of('value', new CacheMetadata(expiresAt: 120.0));
        $write = new CacheWrite($cached);
        $extended = $write->retainUntil(180.0)->retainUntil(150.0);

        self::assertSame(180.0, $extended->retainedUntil);
        self::assertSame($cached, $extended->cached);
        self::assertNull($write->retainedUntil);
    }
}
