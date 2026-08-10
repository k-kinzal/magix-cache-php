<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use Closure;
use Magix\Cache\Cache\CacheEntry;
use Magix\Cache\Cached;
use Magix\Cache\Runtime\CacheStrategy;
use Magix\Cache\Runtime\Operation\CacheGet;
use Magix\Cache\Runtime\Operation\CacheSet;
use Magix\Cache\Runtime\Operation\OriginFetch;
use Magix\Cache\Runtime\Operation\OriginFetchResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\MutableClock;

#[CoversClass(CacheStrategy::class)]
#[UsesClass(CacheEntry::class)]
#[UsesClass(CacheGet::class)]
#[UsesClass(CacheSet::class)]
#[UsesClass(OriginFetch::class)]
#[UsesClass(OriginFetchResult::class)]
#[UsesClass(\Magix\Cache\Runtime\Operation\OriginFetchOutcome::class)]
#[UsesClass(Cached::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\CacheTokenSet::class)]
final class CacheStrategyTest extends TestCase
{
    public function testGetCanDelegateToTheNextHandler(): void
    {
        $get = new CacheGet('key', new MutableClock(100.0));
        $entry = new CacheEntry('value', 120.0);
        $strategy = $this->createMock(CacheStrategy::class);
        $strategy
            ->expects(self::once())
            ->method('get')
            ->willReturn($entry);

        self::assertSame($entry, $strategy->get($get, static fn (): CacheEntry => $entry));
    }

    public function testFetchCanDelegateToTheNextHandler(): void
    {
        $fetch = new OriginFetch(
            'key',
            static fn (): Cached => Cached::of('value'),
            null,
            new MutableClock(100.0),
        );
        $result = new OriginFetchResult(Cached::of('value'));
        $strategy = $this->createMock(CacheStrategy::class);
        $strategy
            ->expects(self::once())
            ->method('fetch')
            ->willReturn($result);

        self::assertSame($result, $strategy->fetch($fetch, static fn (): OriginFetchResult => $result));
    }

    public function testSetCanDelegateToTheNextHandler(): void
    {
        $set = new CacheSet('key', new CacheEntry('value', 120.0));
        $handled = null;
        $strategy = $this->createMock(CacheStrategy::class);
        $strategy
            ->expects(self::once())
            ->method('set')
            ->willReturnCallback(static function (CacheSet $operation, Closure $next): void {
                $next($operation);
            });

        $strategy->set($set, static function (CacheSet $operation) use (&$handled): void {
            $handled = $operation;
        });

        self::assertSame($set, $handled);
    }
}
