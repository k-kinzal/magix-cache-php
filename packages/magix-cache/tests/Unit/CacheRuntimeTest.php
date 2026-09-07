<?php

declare(strict_types=1);

namespace Tests\Unit;

use function array_slice;

use ArrayObject;
use Magix\Cache\Attribute\BypassCacheErrors;
use Magix\Cache\Attribute\DynamicTtl;
use Magix\Cache\Attribute\StaleIfError;
use Magix\Cache\Cache\CacheBackendFailure;
use Magix\Cache\Cached;
use Magix\Cache\CachePolicy;
use Magix\Cache\CacheRuntime;
use Magix\Cache\Metadata\CacheMetadata;
use Magix\Cache\Metadata\Visibility;
use Magix\Cache\Runtime\CacheInvocation;
use Magix\Cache\Runtime\CacheKeyContext;
use Magix\Cache\Runtime\Extension\CacheEvent;
use Magix\Cache\Runtime\Extension\CacheTtlResolver;
use Magix\Cache\Runtime\Extension\DynamicTtlContext;
use Magix\Cache\Runtime\Policy\Ttl;
use Magix\Cache\Strategy\ComposedCacheStrategy;
use Magix\Cache\Strategy\KeySpreadExpirationStrategy;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Fixture\FailingCache;
use Tests\Fixture\FixedTtlResolver;
use Tests\Fixture\MemoryCache;
use Tests\Fixture\MutableClock;
use Tests\Fixture\ProductCacheStrategy;
use Tests\Fixture\RecordingObserver;
use Tests\Fixture\RecordingStrategy;
use Tests\Fixture\UpstreamUnavailable;

#[CoversClass(CacheRuntime::class)]
#[UsesNamespace('Magix\Cache')]
final class CacheRuntimeTest extends TestCase
{
    public function testExecuteServesAFreshHitWithoutReExecutingTheOrigin(): void
    {
        $calls = 0;
        $clock = new MutableClock(100.0);
        $runtime = new CacheRuntime(new MemoryCache(), $clock);
        $invocation = new CacheInvocation(
            context: new CacheKeyContext('', 'App\\Q', 'App\\Q', 'execute', ['id' => 1], '1', 'f'),
            policy: new CachePolicy(ttl: 20),
            origin: static function () use (&$calls): Cached {
                ++$calls;

                return Cached::of('value');
            },
        );

        $first = $runtime->execute($invocation);
        $clock->advance(5.0);
        $second = $runtime->execute($invocation);

        self::assertSame('value', $second->value());
        self::assertSame(120.0, $first->metadata->expiresAt);
        self::assertSame(120.0, $second->metadata->expiresAt);
        self::assertSame(1, $calls);
    }

    public function testExecuteNeverExtendsAnUpstreamExpiration(): void
    {
        $runtime = new CacheRuntime(new MemoryCache(), new MutableClock(100.0));
        $invocation = new CacheInvocation(
            context: new CacheKeyContext('', 'App\\Q', 'App\\Q', 'execute', [], '1', 'f'),
            policy: new CachePolicy(ttl: 20),
            origin: static fn (): Cached => Cached::of(
                'value',
                new CacheMetadata(expiresAt: 105.0),
            ),
        );

        self::assertSame(105.0, $runtime->execute($invocation)->metadata->expiresAt);
    }

    public function testExecuteReturnsATtlZeroResultWithoutStoringIt(): void
    {
        $calls = 0;
        $runtime = new CacheRuntime(new MemoryCache(), new MutableClock(100.0));
        $invocation = new CacheInvocation(
            context: new CacheKeyContext('', 'App\\Q', 'App\\Q', 'execute', [], '1', 'f'),
            policy: new CachePolicy(ttl: 0),
            origin: static function () use (&$calls): Cached {
                ++$calls;

                return Cached::of('value');
            },
        );

        $runtime->execute($invocation);
        $result = $runtime->execute($invocation);

        self::assertSame('value', $result->value());
        self::assertSame(2, $calls);
    }

    public function testExecuteSkipsLookupAndStorageForNoStore(): void
    {
        $calls = 0;
        $runtime = new CacheRuntime(new FailingCache(), new MutableClock(100.0));
        $invocation = new CacheInvocation(
            context: new CacheKeyContext('', 'App\\Q', 'App\\Q', 'execute', [], '1', 'f'),
            policy: new CachePolicy(ttl: 10, visibility: Visibility::NoStore),
            origin: static function () use (&$calls): Cached {
                ++$calls;

                return Cached::of('value');
            },
        );

        $runtime->execute($invocation);
        $result = $runtime->execute($invocation);

        self::assertSame(Visibility::NoStore, $result->metadata->visibility);
        self::assertSame(2, $calls);
    }

    public function testExecuteServesAnEligibleStaleCandidateAndKeepsItExpired(): void
    {
        $clock = new MutableClock(100.0);
        $runtime = new CacheRuntime(new MemoryCache(), $clock);
        $behavior = new StaleIfError(maxAge: 30, exceptions: [RuntimeException::class]);
        $context = new CacheKeyContext('', 'App\\Q', 'App\\Q', 'execute', [], '1', 'f');
        $policy = new CachePolicy(ttl: 10);

        $runtime->execute(new CacheInvocation(
            context: $context,
            policy: $policy,
            origin: static fn (): Cached => Cached::of('fresh'),
            staleIfError: $behavior,
        ));
        $clock->advance(11.0);
        $stale = $runtime->execute(new CacheInvocation(
            context: $context,
            policy: $policy,
            origin: static fn (): Cached => throw new RuntimeException('origin failed'),
            staleIfError: $behavior,
        ));

        self::assertSame('fresh', $stale->value());
        self::assertSame(110.0, $stale->metadata->expiresAt);
        self::assertFalse($stale->metadata->isStorable($clock->time));
    }

    public function testExecuteRethrowsUndeclaredOriginFailures(): void
    {
        $clock = new MutableClock(100.0);
        $runtime = new CacheRuntime(new MemoryCache(), $clock);
        $behavior = new StaleIfError(maxAge: 30, exceptions: [UpstreamUnavailable::class]);
        $context = new CacheKeyContext('', 'App\\Q', 'App\\Q', 'execute', [], '1', 'f');
        $policy = new CachePolicy(ttl: 10);

        $runtime->execute(new CacheInvocation(
            context: $context,
            policy: $policy,
            origin: static fn (): Cached => Cached::of('fresh'),
            staleIfError: $behavior,
        ));
        $clock->advance(11.0);

        $this->expectException(RuntimeException::class);

        $runtime->execute(new CacheInvocation(
            context: $context,
            policy: $policy,
            origin: static fn (): Cached => throw new RuntimeException('an undeclared failure'),
            staleIfError: $behavior,
        ));
    }

    public function testExecuteRethrowsOriginFailuresAfterTheRetentionEnds(): void
    {
        $clock = new MutableClock(100.0);
        $runtime = new CacheRuntime(new MemoryCache(), $clock);
        $behavior = new StaleIfError(maxAge: 30, exceptions: [RuntimeException::class]);
        $context = new CacheKeyContext('', 'App\\Q', 'App\\Q', 'execute', [], '1', 'f');
        $policy = new CachePolicy(ttl: 10);

        $runtime->execute(new CacheInvocation(
            context: $context,
            policy: $policy,
            origin: static fn (): Cached => Cached::of('fresh'),
            staleIfError: $behavior,
        ));
        $clock->advance(41.0);

        $this->expectException(RuntimeException::class);

        $runtime->execute(new CacheInvocation(
            context: $context,
            policy: $policy,
            origin: static fn (): Cached => throw new RuntimeException('origin failed'),
            staleIfError: $behavior,
        ));
    }

    public function testExecuteDoesNotHideAResolverFailureBehindStale(): void
    {
        $clock = new MutableClock(100.0);
        $resolver = new class () implements CacheTtlResolver {
            /**
             * @throws RuntimeException always, to prove stale cannot hide it
             */
            #[Override]
            public function resolve(DynamicTtlContext $context): int
            {
                unset($context);

                throw new RuntimeException('resolver failed');
            }
        };
        $runtime = new CacheRuntime(new MemoryCache(), $clock, ttlResolvers: [$resolver]);
        $behavior = new StaleIfError(maxAge: 30, exceptions: [RuntimeException::class]);
        $context = new CacheKeyContext('', 'App\\Q', 'App\\Q', 'execute', [], '1', 'f');

        $runtime->execute(new CacheInvocation(
            context: $context,
            policy: new CachePolicy(ttl: 10),
            origin: static fn (): Cached => Cached::of('fresh'),
            staleIfError: $behavior,
        ));
        $clock->advance(11.0);

        $this->expectException(RuntimeException::class);

        $runtime->execute(new CacheInvocation(
            context: $context,
            policy: new CachePolicy(ttl: 10),
            origin: static fn (): Cached => Cached::of('recomputed'),
            staleIfError: $behavior,
            dynamicTtl: new DynamicTtl(resolver: $resolver::class),
        ));
    }

    public function testExecuteBypassesOnlyClassifiedBackendFailures(): void
    {
        $observer = new RecordingObserver();
        $runtime = new CacheRuntime(new FailingCache(), new MutableClock(100.0), observer: $observer);
        $invocation = new CacheInvocation(
            context: new CacheKeyContext('', 'App\\Q', 'App\\Q', 'execute', [], '1', 'f'),
            policy: new CachePolicy(ttl: 10),
            origin: static fn (): Cached => Cached::of('value'),
            bypassCacheErrors: new BypassCacheErrors(),
        );

        $result = $runtime->execute($invocation);

        self::assertSame('value', $result->value());
        self::assertSame(
            [CacheEvent::BackendBypassed, CacheEvent::Miss, CacheEvent::BackendBypassed],
            $observer->events,
        );
    }

    public function testExecutePropagatesBackendFailuresWithoutTheBypassBehavior(): void
    {
        $runtime = new CacheRuntime(new FailingCache(), new MutableClock(100.0));

        $this->expectException(CacheBackendFailure::class);

        $runtime->execute(new CacheInvocation(
            context: new CacheKeyContext('', 'App\\Q', 'App\\Q', 'execute', [], '1', 'f'),
            policy: new CachePolicy(ttl: 10),
            origin: static fn (): Cached => Cached::of('value'),
        ));
    }

    public function testExecuteNeverClassifiesAnOriginFailureAsABackendFailure(): void
    {
        $runtime = new CacheRuntime(new MemoryCache(), new MutableClock(100.0));

        $this->expectException(CacheBackendFailure::class);

        $runtime->execute(new CacheInvocation(
            context: new CacheKeyContext('', 'App\\Q', 'App\\Q', 'execute', [], '1', 'f'),
            policy: new CachePolicy(ttl: 10),
            origin: static fn (): Cached => throw new CacheBackendFailure('raised by the origin'),
            bypassCacheErrors: new BypassCacheErrors(),
        ));
    }

    public function testExecuteAppliesDynamicTtlBeforeTheAutomaticPolicy(): void
    {
        $calls = 0;
        $clock = new MutableClock(100.0);
        $runtime = new CacheRuntime(new MemoryCache(), $clock, ttlResolvers: [new FixedTtlResolver(5)]);
        $invocation = new CacheInvocation(
            context: new CacheKeyContext('', 'App\\Q', 'App\\Q', 'execute', [], '1', 'f'),
            policy: new CachePolicy(ttl: Ttl::Auto),
            origin: static function () use (&$calls): Cached {
                ++$calls;

                return Cached::of('short');
            },
            dynamicTtl: new DynamicTtl(resolver: FixedTtlResolver::class),
        );

        $first = $runtime->execute($invocation);
        $clock->advance(4.0);
        $second = $runtime->execute($invocation);

        self::assertSame(105.0, $first->metadata->expiresAt);
        self::assertSame('short', $second->value());
        self::assertSame(1, $calls);
    }

    public function testExecuteReportsTheFixedStageEventsInOrder(): void
    {
        $observer = new RecordingObserver();
        $runtime = new CacheRuntime(new MemoryCache(), new MutableClock(100.0), observer: $observer);
        $invocation = new CacheInvocation(
            context: new CacheKeyContext('', 'App\\Q', 'App\\Q', 'execute', [], '1', 'f'),
            policy: new CachePolicy(ttl: 20),
            origin: static fn (): Cached => Cached::of('value'),
        );

        $runtime->execute($invocation);
        $runtime->execute($invocation);

        self::assertSame([CacheEvent::Miss, CacheEvent::Stored, CacheEvent::FreshHit], $observer->events);
    }

    public function testExecuteLetsAStrategyConstraintSatisfyAnAutomaticPolicy(): void
    {
        $runtime = new CacheRuntime(new MemoryCache(), new MutableClock(100.0));
        $invocation = new CacheInvocation(
            context: new CacheKeyContext('', 'App\\Q', 'App\\Q', 'execute', [], '1', 'f'),
            policy: new CachePolicy(ttl: Ttl::Auto),
            origin: static fn (): Cached => Cached::of('value'),
            strategy: new KeySpreadExpirationStrategy(minimum: 30, maximum: 60),
        );

        $expiresAt = $runtime->execute($invocation)->metadata->expiresAt;

        self::assertNotNull($expiresAt);
        self::assertGreaterThanOrEqual(130.0, $expiresAt);
        self::assertLessThanOrEqual(160.0, $expiresAt);
    }

    public function testExecuteNeverLetsAStrategyExtendAnUpstreamExpiration(): void
    {
        $runtime = new CacheRuntime(new MemoryCache(), new MutableClock(100.0));
        $invocation = new CacheInvocation(
            context: new CacheKeyContext('', 'App\\Q', 'App\\Q', 'execute', [], '1', 'f'),
            policy: new CachePolicy(ttl: Ttl::Auto),
            origin: static fn (): Cached => Cached::of('value', new CacheMetadata(expiresAt: 105.0)),
            strategy: new KeySpreadExpirationStrategy(minimum: 30, maximum: 60),
        );

        self::assertSame(105.0, $runtime->execute($invocation)->metadata->expiresAt);
    }

    public function testExecuteServesStaleThroughAComposedStrategyWithoutTheBehaviorAttribute(): void
    {
        $calls = 0;
        $clock = new MutableClock(100.0);
        $observer = new RecordingObserver();
        $runtime = new CacheRuntime(new MemoryCache(), $clock, observer: $observer);
        $invocation = new CacheInvocation(
            context: new CacheKeyContext('', 'App\\Q', 'App\\Q', 'execute', [], '1', 'f'),
            policy: new CachePolicy(ttl: Ttl::Auto),
            origin: static function () use (&$calls): Cached {
                if (++$calls > 1) {
                    throw new UpstreamUnavailable('down');
                }

                return Cached::of('value');
            },
            strategy: ProductCacheStrategy::create(min: 30),
        );

        $first = $runtime->execute($invocation);
        $clock->advance(70.0);
        $served = $runtime->execute($invocation);

        self::assertSame('value', $served->value());
        self::assertSame($first->metadata->expiresAt, $served->metadata->expiresAt, 'a served candidate keeps its expired expiration');
        self::assertNotContains(CacheEvent::Stored, array_slice($observer->events, 2), 'a served candidate is never stored again');
    }

    public function testExecuteRunsStrategyStagesAroundTheTerminal(): void
    {
        /** @var ArrayObject<int, string> $log */
        $log = new ArrayObject();
        $runtime = new CacheRuntime(new MemoryCache(), new MutableClock(100.0));
        $invocation = new CacheInvocation(
            context: new CacheKeyContext('', 'App\\Q', 'App\\Q', 'execute', [], '1', 'f'),
            policy: new CachePolicy(ttl: 20),
            origin: static fn (): Cached => Cached::of('value'),
            strategy: new ComposedCacheStrategy(new RecordingStrategy('outer', $log), new RecordingStrategy('inner', $log)),
        );

        $runtime->execute($invocation);

        self::assertSame(
            [
                'outer.get.before', 'inner.get.before', 'inner.get.after', 'outer.get.after',
                'outer.fetch.before', 'inner.fetch.before', 'inner.fetch.after', 'outer.fetch.after',
                'outer.set.before', 'inner.set.before', 'inner.set.after', 'outer.set.after',
            ],
            $log->getArrayCopy(),
        );
    }
}
