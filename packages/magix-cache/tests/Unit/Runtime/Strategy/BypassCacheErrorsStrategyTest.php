<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime\Strategy;

use Magix\Cache\Cache\CacheEntry;
use Magix\Cache\Runtime\Operation\CacheGet;
use Magix\Cache\Runtime\Operation\CacheSet;
use Magix\Cache\Runtime\Strategy\BypassCacheErrorsStrategy;
use Magix\Cache\Runtime\Strategy\CacheStrategyMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Fixture\MutableClock;
use Throwable;

#[CoversClass(BypassCacheErrorsStrategy::class)]
#[UsesClass(CacheStrategyMiddleware::class)]
#[UsesClass(CacheEntry::class)]
#[UsesClass(CacheGet::class)]
#[UsesClass(CacheSet::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\CacheTokenSet::class)]
final class BypassCacheErrorsStrategyTest extends TestCase
{
    public function testGetTurnsPsrCacheExceptionIntoMiss(): void
    {
        $error = new class ('cache failed') extends RuntimeException implements \Psr\Cache\CacheException {};
        $strategy = new BypassCacheErrorsStrategy();
        $next = static function (CacheGet $operation) use ($error): CacheEntry {
            if ($operation->key === '') {
                return new CacheEntry('type-witness', 120.0);
            }

            throw $error;
        };

        self::assertNull($strategy->get(
            new CacheGet('key', new MutableClock(100.0)),
            $next,
        ));
    }

    public function testGetRethrowsUnclassifiedException(): void
    {
        $error = new RuntimeException('unexpected');
        $next = static function (CacheGet $operation) use ($error): CacheEntry {
            if ($operation->key === '') {
                return new CacheEntry('type-witness', 120.0);
            }

            throw $error;
        };

        $this->expectExceptionObject($error);

        (new BypassCacheErrorsStrategy())->get(
            new CacheGet('key', new MutableClock(100.0)),
            $next,
        );
    }

    public function testGetUsesCustomClassifier(): void
    {
        $strategy = new BypassCacheErrorsStrategy(
            static fn (Throwable $error): bool => $error instanceof RuntimeException,
        );
        $next = static function (CacheGet $operation): CacheEntry {
            if ($operation->key === '') {
                return new CacheEntry('type-witness', 120.0);
            }

            throw new RuntimeException('classified');
        };

        self::assertNull($strategy->get(
            new CacheGet('key', new MutableClock(100.0)),
            $next,
        ));
    }

    public function testSetSkipsWriteFailureForPsrCacheException(): void
    {
        $error = new class ('cache failed') extends RuntimeException implements \Psr\SimpleCache\CacheException {};
        $strategy = new BypassCacheErrorsStrategy();

        $strategy->set(
            new CacheSet('key', new CacheEntry('value', 120.0)),
            static function () use ($error): never {
                throw $error;
            },
        );

        self::addToAssertionCount(1);
    }

    public function testSetRethrowsUnclassifiedException(): void
    {
        $error = new RuntimeException('unexpected');

        $this->expectExceptionObject($error);

        (new BypassCacheErrorsStrategy())->set(
            new CacheSet('key', new CacheEntry('value', 120.0)),
            static function () use ($error): never {
                throw $error;
            },
        );
    }
}
