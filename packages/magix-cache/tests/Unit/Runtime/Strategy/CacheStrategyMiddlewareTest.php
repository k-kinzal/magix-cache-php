<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime\Strategy;

use Magix\Cache\Cache\CacheEntry;
use Magix\Cache\Cached;
use Magix\Cache\Runtime\Operation\CacheGet;
use Magix\Cache\Runtime\Operation\CacheSet;
use Magix\Cache\Runtime\Operation\OriginFetch;
use Magix\Cache\Runtime\Operation\OriginFetchResult;
use Magix\Cache\Runtime\Strategy\CacheStrategyMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\MutableClock;

#[CoversClass(CacheStrategyMiddleware::class)]
#[UsesClass(CacheEntry::class)]
#[UsesClass(CacheGet::class)]
#[UsesClass(CacheSet::class)]
#[UsesClass(OriginFetch::class)]
#[UsesClass(OriginFetchResult::class)]
#[UsesClass(\Magix\Cache\Runtime\Operation\OriginFetchOutcome::class)]
#[UsesClass(Cached::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\CacheTokenSet::class)]
final class CacheStrategyMiddlewareTest extends TestCase
{
    public function testGetDelegatesUnchangedOperation(): void
    {
        $strategy = new readonly class () extends CacheStrategyMiddleware {};
        $get = new CacheGet('key', new MutableClock(100.0));
        $entry = new CacheEntry('value', 120.0);

        self::assertSame($entry, $strategy->get($get, static fn (): CacheEntry => $entry));
    }

    public function testFetchDelegatesUnchangedOperation(): void
    {
        $strategy = new readonly class () extends CacheStrategyMiddleware {};
        $fetch = new OriginFetch(
            'key',
            static fn (): Cached => Cached::of('value'),
            null,
            new MutableClock(100.0),
        );
        $result = new OriginFetchResult(Cached::of('value'));

        self::assertSame($result, $strategy->fetch($fetch, static fn (): OriginFetchResult => $result));
    }

    public function testSetDelegatesUnchangedOperation(): void
    {
        $strategy = new readonly class () extends CacheStrategyMiddleware {};
        $set = new CacheSet('key', new CacheEntry('value', 120.0));
        $handled = null;

        $strategy->set($set, static function (CacheSet $operation) use (&$handled): void {
            $handled = $operation;
        });

        self::assertSame($set, $handled);
    }
}
