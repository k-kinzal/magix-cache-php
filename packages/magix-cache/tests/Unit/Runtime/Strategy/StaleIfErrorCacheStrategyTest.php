<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime\Strategy;

use Exception;
use JsonException;
use Magix\Cache\Cache\CacheEntry;
use Magix\Cache\Cached;
use Magix\Cache\Runtime\Metadata\CacheMetadata;
use Magix\Cache\Runtime\Metadata\CacheTokenSet;
use Magix\Cache\Runtime\Operation\CacheSet;
use Magix\Cache\Runtime\Operation\OriginFetch;
use Magix\Cache\Runtime\Operation\OriginFetchProvenance;
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
#[UsesClass(OriginFetchProvenance::class)]
#[UsesClass(OriginFetchResult::class)]
#[UsesClass(Cached::class)]
#[UsesClass(CacheMetadata::class)]
#[UsesClass(CacheTokenSet::class)]
final class StaleIfErrorCacheStrategyTest extends TestCase
{
    public function testFetchReturnsEligibleStaleEntryAfterOriginFailure(): void
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
                throw new RuntimeException('The origin failed.');
            },
        );

        self::assertSame(OriginFetchProvenance::Stale, $result->provenance);
        self::assertSame($stale, $result->staleEntry());
    }

    public function testFetchServesStaleForOriginFailuresOutsideTheRuntimeExceptionFamily(): void
    {
        $stale = new CacheEntry('stale', 90.0, retainedUntil: 120.0);
        $operation = new OriginFetch(
            'key',
            static fn (): Cached => Cached::of('unused'),
            $stale,
            new MutableClock(100.0),
        );

        $result = (new StaleIfErrorCacheStrategy(30))->fetch(
            $operation,
            static function (): never {
                throw new JsonException('Syntax error.');
            },
        );

        self::assertSame(OriginFetchProvenance::Stale, $result->provenance);
        self::assertSame($stale, $result->staleEntry());
    }

    public function testFetchServesStaleForAnOriginFailureThatExtendsNothingFamiliar(): void
    {
        $failure = new class ('The upstream is unavailable.') extends Exception {};
        $stale = new CacheEntry('stale', 90.0, retainedUntil: 120.0);
        $operation = new OriginFetch(
            'key',
            static fn (): Cached => Cached::of('unused'),
            $stale,
            new MutableClock(100.0),
        );

        $result = (new StaleIfErrorCacheStrategy(30))->fetch(
            $operation,
            static function () use ($failure): never {
                throw $failure;
            },
        );

        self::assertSame(OriginFetchProvenance::Stale, $result->provenance);
    }

    public function testFetchServesStaleOnlyForFailuresTheClassifierAccepts(): void
    {
        $operation = new OriginFetch(
            'key',
            static fn (): Cached => Cached::of('unused'),
            new CacheEntry('stale', 90.0, retainedUntil: 120.0),
            new MutableClock(100.0),
        );
        $strategy = new StaleIfErrorCacheStrategy(
            30,
            static fn (Throwable $error): bool => $error->getMessage() === 'The upstream is unavailable.',
        );

        $result = $strategy->fetch($operation, static function (): never {
            throw new RuntimeException('The upstream is unavailable.');
        });

        self::assertSame(OriginFetchProvenance::Stale, $result->provenance);
    }

    public function testFetchRethrowsTheOriginalFailureTheClassifierRejects(): void
    {
        $failure = new RuntimeException('The response was malformed.');
        $operation = new OriginFetch(
            'key',
            static fn (): Cached => Cached::of('unused'),
            new CacheEntry('stale', 90.0, retainedUntil: 120.0),
            new MutableClock(100.0),
        );
        $strategy = new StaleIfErrorCacheStrategy(
            30,
            static fn (Throwable $error): bool => $error->getMessage() === 'The upstream is unavailable.',
        );

        $this->expectExceptionObject($failure);

        $strategy->fetch($operation, static function () use ($failure): never {
            throw $failure;
        });
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
            throw new RuntimeException('The retained entry is too old.');
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
}
