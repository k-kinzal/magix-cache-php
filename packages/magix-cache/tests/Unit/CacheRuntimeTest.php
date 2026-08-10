<?php

declare(strict_types=1);

namespace Tests\Unit;

use Closure;
use Magix\Cache\Cache\CacheEntry;
use Magix\Cache\Cached;
use Magix\Cache\CachePolicy;
use Magix\Cache\CacheRuntime;
use Magix\Cache\Runtime\CacheEntryConverter;
use Magix\Cache\Runtime\CacheKeyStrategy;
use Magix\Cache\Runtime\KeyStrategy\HashCacheKeyStrategy;
use Magix\Cache\Runtime\Metadata\Visibility;
use Magix\Cache\Runtime\Operation\CacheGet;
use Magix\Cache\Runtime\Operation\CacheOperationTerminal;
use Magix\Cache\Runtime\Operation\CacheSet;
use Magix\Cache\Runtime\Operation\OriginFetch;
use Magix\Cache\Runtime\Operation\OriginFetchOutcome;
use Magix\Cache\Runtime\Operation\OriginFetchResult;
use Magix\Cache\Runtime\Policy\Ttl;
use Magix\Cache\Runtime\Strategy\CacheStrategyMiddleware;
use Magix\Cache\Runtime\Strategy\DynamicTtlCacheStrategy;
use Magix\Cache\Runtime\Strategy\DynamicTtlContext;
use Magix\Cache\Runtime\Strategy\StaleIfErrorCacheStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Fixture\MemoryCache;
use Tests\Fixture\MutableClock;

#[CoversClass(CacheRuntime::class)]
#[UsesClass(CacheEntry::class)]
#[UsesClass(CacheEntryConverter::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\CacheMetadata::class)]
#[UsesClass(CachePolicy::class)]
#[UsesClass(Cached::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\CacheTokenSet::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\ConstraintMeet::class)]
#[UsesClass(Visibility::class)]
#[UsesClass(CacheGet::class)]
#[UsesClass(CacheOperationTerminal::class)]
#[UsesClass(CacheSet::class)]
#[UsesClass(OriginFetch::class)]
#[UsesClass(OriginFetchOutcome::class)]
#[UsesClass(OriginFetchResult::class)]
#[UsesClass(CacheStrategyMiddleware::class)]
#[UsesClass(DynamicTtlCacheStrategy::class)]
#[UsesClass(DynamicTtlContext::class)]
#[UsesClass(StaleIfErrorCacheStrategy::class)]
final class CacheRuntimeTest extends TestCase
{
    public function testKeyStrategyReturnsConfiguredStrategy(): void
    {
        $strategy = self::createStub(CacheKeyStrategy::class);
        $runtime = new CacheRuntime(new MemoryCache(), keyStrategy: $strategy);

        self::assertSame($strategy, $runtime->keyStrategy());
        self::assertInstanceOf(HashCacheKeyStrategy::class, (new CacheRuntime(new MemoryCache()))->keyStrategy());
    }

    public function testExecuteUsesMagixCache(): void
    {
        $calls = 0;
        $runtime = new CacheRuntime(new MemoryCache());
        /** @var Closure(): Cached<string> $compute */
        $compute = static function () use (&$calls): Cached {
            ++$calls;

            return Cached::of('value');
        };
        $policy = new CachePolicy(ttl: 20);

        $first = $runtime->execute('key', $policy, $compute);
        $second = $runtime->execute('key', $policy, $compute);

        self::assertEquals($first, $second);
        self::assertSame(1, $calls);
    }

    public function testSetCurrentInstallsRuntime(): void
    {
        $runtime = new CacheRuntime(new MemoryCache(), new MutableClock(100.0));
        CacheRuntime::setCurrent($runtime);

        self::assertSame($runtime, CacheRuntime::current());
        CacheRuntime::setCurrent(null);
    }

    public function testCurrentReturnsInstalledRuntime(): void
    {
        $runtime = new CacheRuntime(new MemoryCache(), new MutableClock(100.0));
        CacheRuntime::setCurrent($runtime);

        self::assertSame($runtime, CacheRuntime::current());
        CacheRuntime::setCurrent(null);
    }

    public function testExecuteAppliesPolicyAndPreservesAbsoluteExpiration(): void
    {
        $now = 4_000_000_000.0;
        $runtime = new CacheRuntime(new MemoryCache(), new MutableClock($now));
        $policy = new CachePolicy(ttl: 15, tags: ['explicit']);

        $result = $runtime->execute('key', $policy, static fn (): Cached => Cached::of('value'));

        self::assertSame('value', $result->value());
        self::assertSame($now + 15.0, $result->metadata->expiresAt);
        self::assertSame(['explicit'], $result->metadata->tags);
    }

    public function testExecuteDoesNotStoreNoStorePolicy(): void
    {
        $calls = 0;
        $runtime = new CacheRuntime(new MemoryCache(), new MutableClock(100.0));
        $policy = new CachePolicy(ttl: 10, visibility: Visibility::NoStore);
        /** @var Closure(): Cached<string> $compute */
        $compute = static function () use (&$calls): Cached {
            ++$calls;

            return Cached::of('value');
        };

        $first = $runtime->execute('key', $policy, $compute);
        $second = $runtime->execute('key', $policy, $compute);

        self::assertSame(2, $calls);
        self::assertSame(Visibility::NoStore, $first->metadata->visibility);
        self::assertSame(Visibility::NoStore, $second->metadata->visibility);
    }

    public function testExecuteServesRetainedStaleEntryWhenOriginFails(): void
    {
        $clock = new MutableClock(100.0);
        $runtime = new CacheRuntime(new MemoryCache(), $clock);
        $policy = new CachePolicy(ttl: 10);
        $strategy = new StaleIfErrorCacheStrategy(30);

        $fresh = $runtime->execute(
            'key',
            $policy,
            static fn (): Cached => Cached::of('fresh'),
            $strategy,
        );
        $clock->advance(11.0);
        $failure = static function () use ($clock): Cached {
            if ($clock->time < 0.0) {
                return Cached::of('type-witness');
            }

            throw new RuntimeException('origin failed');
        };
        $stale = $runtime->execute(
            'key',
            $policy,
            $failure,
            $strategy,
        );

        self::assertSame('fresh', $fresh->value());
        self::assertSame('fresh', $stale->value());
        self::assertSame(110.0, $stale->metadata->expiresAt);
    }

    public function testExecuteRethrowsOriginFailureAfterStaleRetentionEnds(): void
    {
        $clock = new MutableClock(100.0);
        $runtime = new CacheRuntime(new MemoryCache(), $clock);
        $policy = new CachePolicy(ttl: 10);
        $strategy = new StaleIfErrorCacheStrategy(30);
        $runtime->execute('key', $policy, static fn (): Cached => Cached::of('fresh'), $strategy);
        $clock->advance(41.0);
        $failure = static function () use ($clock): Cached {
            if ($clock->time < 0.0) {
                return Cached::of('type-witness');
            }

            throw new RuntimeException('origin failed');
        };

        $this->expectException(RuntimeException::class);

        $runtime->execute(
            'key',
            $policy,
            $failure,
            $strategy,
        );
    }

    public function testExecuteAppliesDynamicTtlBeforeAutomaticPolicy(): void
    {
        $clock = new MutableClock(100.0);
        $runtime = new CacheRuntime(new MemoryCache(), $clock);
        $strategy = new DynamicTtlCacheStrategy(static fn (DynamicTtlContext $context): int => match (
            $context->result->value()
        ) {
            'short' => 5,
            default => 30,
        });
        $calls = 0;
        $compute = static function () use (&$calls): Cached {
            ++$calls;

            return Cached::of('short');
        };

        $first = $runtime->execute('key', new CachePolicy(ttl: Ttl::Auto), $compute, $strategy);
        $clock->advance(4.0);
        $second = $runtime->execute('key', new CachePolicy(ttl: Ttl::Auto), $compute, $strategy);

        self::assertSame(105.0, $first->metadata->expiresAt);
        self::assertSame('short', $second->value());
        self::assertSame(1, $calls);
    }

}
