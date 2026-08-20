<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime\Strategy;

use Exception;
use Magix\Cache\Cache\CacheBackendFailure;
use Magix\Cache\Cache\CacheEntry;
use Magix\Cache\Runtime\Metadata\CacheTokenSet;
use Magix\Cache\Runtime\Operation\CacheGet;
use Magix\Cache\Runtime\Operation\CacheSet;
use Magix\Cache\Runtime\Strategy\BypassCacheErrorsStrategy;
use Magix\Cache\Runtime\Strategy\CacheStrategyMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheException as Psr6CacheException;
use Psr\SimpleCache\CacheException as Psr16CacheException;
use RuntimeException;
use Tests\Fixture\MutableClock;
use Throwable;

#[CoversClass(BypassCacheErrorsStrategy::class)]
#[UsesClass(CacheStrategyMiddleware::class)]
#[UsesClass(CacheBackendFailure::class)]
#[UsesClass(CacheEntry::class)]
#[UsesClass(CacheGet::class)]
#[UsesClass(CacheSet::class)]
#[UsesClass(CacheTokenSet::class)]
final class BypassCacheErrorsStrategyTest extends TestCase
{
    public function testAcceptsTheFailureTheBundledAdaptersReport(): void
    {
        $strategy = new BypassCacheErrorsStrategy();

        self::assertTrue($strategy->accepts(new CacheBackendFailure('The pool failed to read "key".')));
    }

    public function testAcceptsABackendUsedDirectlyThroughThePsrInterfaces(): void
    {
        $strategy = new BypassCacheErrorsStrategy();
        $psr6 = new class ('cache failed') extends RuntimeException implements Psr6CacheException {};
        $psr16 = new class ('cache failed') extends RuntimeException implements Psr16CacheException {};

        self::assertTrue($strategy->accepts($psr6));
        self::assertTrue($strategy->accepts($psr16));
    }

    public function testAcceptsRejectsAFailureThatIsNotAboutStorage(): void
    {
        $strategy = new BypassCacheErrorsStrategy();

        self::assertFalse($strategy->accepts(new RuntimeException('The origin timed out.')));
    }

    public function testAcceptsDefersToTheConfiguredClassifierForANonPsrBackend(): void
    {
        $unavailable = new class ('redis is down') extends Exception {};
        $strategy = new BypassCacheErrorsStrategy(
            static fn (Throwable $error): bool => $error->getMessage() === 'redis is down',
        );

        self::assertTrue($strategy->accepts($unavailable));
        self::assertFalse($strategy->accepts(new CacheBackendFailure('The pool failed to read "key".')));
    }

    public function testGetTurnsAnAcceptedBackendFailureIntoMiss(): void
    {
        $failure = new CacheBackendFailure('The pool failed to read "key".');
        $strategy = new BypassCacheErrorsStrategy();
        $next = static function (CacheGet $operation) use ($failure): CacheEntry {
            if ($operation->key === '') {
                return new CacheEntry('type-witness', 120.0);
            }

            throw $failure;
        };

        self::assertNull($strategy->get(
            new CacheGet('key', new MutableClock(100.0)),
            $next,
        ));
    }

    public function testGetRethrowsTheOriginalFailureItDoesNotAccept(): void
    {
        $error = new RuntimeException('The origin timed out.');
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

    public function testSetSkipsTheWriteOnAnAcceptedBackendFailure(): void
    {
        $failure = new CacheBackendFailure('The pool failed to write "key".');
        $strategy = new BypassCacheErrorsStrategy();

        $strategy->set(
            new CacheSet('key', new CacheEntry('value', 120.0)),
            static function () use ($failure): never {
                throw $failure;
            },
        );

        self::addToAssertionCount(1);
    }

    public function testSetRethrowsTheOriginalFailureItDoesNotAccept(): void
    {
        $error = new RuntimeException('The serializer refused the value.');

        $this->expectExceptionObject($error);

        (new BypassCacheErrorsStrategy())->set(
            new CacheSet('key', new CacheEntry('value', 120.0)),
            static function () use ($error): never {
                throw $error;
            },
        );
    }
}
