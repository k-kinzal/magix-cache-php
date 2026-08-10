<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime\Strategy;

use Error;
use InvalidArgumentException;
use Magix\Cache\Cache\CacheEntry;
use Magix\Cache\Cached;
use Magix\Cache\Runtime\Operation\CacheSet;
use Magix\Cache\Runtime\Operation\OriginFetch;
use Magix\Cache\Runtime\Operation\OriginFetchOutcome;
use Magix\Cache\Runtime\Operation\OriginFetchResult;
use Magix\Cache\Runtime\Strategy\CacheStrategyMiddleware;
use Magix\Cache\Runtime\Strategy\StaleIfErrorCacheStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Fixture\MutableClock;
use Throwable;

#[CoversClass(StaleIfErrorCacheStrategy::class)]
#[UsesClass(CacheStrategyMiddleware::class)]
#[UsesClass(CacheEntry::class)]
#[UsesClass(CacheSet::class)]
#[UsesClass(OriginFetch::class)]
#[UsesClass(OriginFetchOutcome::class)]
#[UsesClass(OriginFetchResult::class)]
#[UsesClass(Cached::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\CacheTokenSet::class)]
final class StaleIfErrorCacheStrategyTest extends TestCase
{
    public function testFetchReturnsEligibleStaleEntryAfterOriginException(): void
    {
        $stale = new CacheEntry('stale', 90.0, retainedUntil: 120.0);
        $operation = new OriginFetch(
            'key',
            static fn (): Cached => Cached::of('unused'),
            $stale,
            new MutableClock(100.0),
        );
        $strategy = new StaleIfErrorCacheStrategy(30);

        $result = $strategy->fetch(
            $operation,
            static function (): never {
                throw new RuntimeException('origin failed');
            },
        );

        self::assertSame(OriginFetchOutcome::Stale, $result->outcome);
        self::assertSame($stale, $result->staleEntry());
    }

    public function testFetchDoesNotCatchErrorsByDefault(): void
    {
        $error = new Error('programming error');
        $operation = new OriginFetch(
            'key',
            static fn (): Cached => Cached::of('unused'),
            new CacheEntry('stale', 90.0, retainedUntil: 120.0),
            new MutableClock(100.0),
        );

        $this->expectExceptionObject($error);

        (new StaleIfErrorCacheStrategy(30))->fetch(
            $operation,
            static function () use ($error): never {
                throw $error;
            },
        );
    }

    public function testFetchUsesConfiguredThrowableClassifier(): void
    {
        $operation = new OriginFetch(
            'key',
            static fn (): Cached => Cached::of('unused'),
            new CacheEntry('stale', 90.0, retainedUntil: 120.0),
            new MutableClock(100.0),
        );
        $strategy = new StaleIfErrorCacheStrategy(30, static fn (Throwable $error): bool => $error instanceof Error);

        $result = $strategy->fetch($operation, static function (): never {
            throw new Error('eligible');
        });

        self::assertSame(OriginFetchOutcome::Stale, $result->outcome);
    }

    public function testFetchRethrowsWhenStaleWindowHasElapsed(): void
    {
        $operation = new OriginFetch(
            'key',
            static fn (): Cached => Cached::of('unused'),
            new CacheEntry('stale', 90.0, retainedUntil: 130.0),
            new MutableClock(121.0),
        );

        $this->expectException(RuntimeException::class);

        (new StaleIfErrorCacheStrategy(30))->fetch($operation, static function (): never {
            throw new RuntimeException('too old');
        });
    }

    public function testSetExtendsPhysicalRetentionWithoutChangingLogicalExpiration(): void
    {
        $strategy = new StaleIfErrorCacheStrategy(30);
        $set = new CacheSet('key', new CacheEntry('value', 120.0));
        $written = null;

        $strategy->set($set, static function (CacheSet $operation) use (&$written): void {
            $written = $operation;
        });

        self::assertInstanceOf(CacheSet::class, $written);
        self::assertSame(120.0, $written->entry()->expiresAt);
        self::assertSame(150.0, $written->entry()->retainedUntil);
    }

    public function testNegativeMaximumAgeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StaleIfErrorCacheStrategy(-1);
    }
}
