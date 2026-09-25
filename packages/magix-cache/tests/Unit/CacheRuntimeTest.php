<?php

declare(strict_types=1);

namespace Tests\Unit;

use function array_slice;

use Magix\Cache\Attribute\BypassCacheErrors;
use Magix\Cache\Attribute\DynamicTtl;
use Magix\Cache\Attribute\StaleIfError;
use Magix\Cache\Cache\CacheBackendFailure;
use Magix\Cache\Cached;
use Magix\Cache\CachePolicy;
use Magix\Cache\CacheRuntime;
use Magix\Cache\Metadata\CacheMetadata;
use Magix\Cache\Metadata\Visibility;
use Magix\Cache\Observation\CacheEvent;
use Magix\Cache\Runtime\CacheInvocation;
use Magix\Cache\Runtime\CacheKeyContext;
use Magix\Cache\Runtime\Extension\CacheTtlResolver;
use Magix\Cache\Runtime\Extension\DynamicTtlContext;
use Magix\Cache\Runtime\Policy\Ttl;
use Magix\Cache\Strategy\KeySpreadExpirationStrategy;
use Magix\Cache\Strategy\StrategyDefinition;
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
use Tests\Fixture\UpstreamUnavailable;

#[CoversClass(CacheRuntime::class)]
#[UsesNamespace('Magix\Cache')]
final class CacheRuntimeTest extends TestCase
{
    public function testExecuteWithoutStrategiesFetchesStoresAndHitsWithoutReExecutingTheOrigin(): void
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

    public function testExecuteOverridesAnUpstreamExpiration(): void
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

        self::assertSame(120.0, $runtime->execute($invocation)->metadata->expiresAt);
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

    public function testExecuteStaleCanHandleADeclaredFailureWithinItsDelegatedInquiry(): void
    {
        $clock = new MutableClock(100.0);
        $resolver = new class () implements CacheTtlResolver {
            /**
             * @throws RuntimeException when resolving the origin result fails
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

        $result = $runtime->execute(new CacheInvocation(
            context: $context,
            policy: new CachePolicy(ttl: 10),
            origin: static fn (): Cached => Cached::of('recomputed'),
            staleIfError: $behavior,
            dynamicTtl: new DynamicTtl(resolver: $resolver::class),
        ));

        self::assertSame('fresh', $result->value());
        self::assertSame(110.0, $result->metadata->expiresAt);
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
            strategy: StrategyDefinition::of(KeySpreadExpirationStrategy::class, minimum: 30, maximum: 60),
        );

        $expiresAt = $runtime->execute($invocation)->metadata->expiresAt;

        self::assertNotNull($expiresAt);
        self::assertGreaterThanOrEqual(130.0, $expiresAt);
        self::assertLessThanOrEqual(160.0, $expiresAt);
    }

    public function testExecuteLetsAStrategyOverrideAnUpstreamExpiration(): void
    {
        $runtime = new CacheRuntime(new MemoryCache(), new MutableClock(100.0));
        $invocation = new CacheInvocation(
            context: new CacheKeyContext('', 'App\\Q', 'App\\Q', 'execute', [], '1', 'f'),
            policy: new CachePolicy(ttl: Ttl::Auto),
            origin: static fn (): Cached => Cached::of('value', new CacheMetadata(expiresAt: 105.0)),
            strategy: StrategyDefinition::of(KeySpreadExpirationStrategy::class, minimum: 30, maximum: 60),
        );

        self::assertGreaterThanOrEqual(130.0, $runtime->execute($invocation)->metadata->expiresAt);
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

    public function testExecuteCreatesFreshStateForRepeatedInvocationsOfTheSameDefinition(): void
    {
        $runtime = new CacheRuntime(new MemoryCache(), new MutableClock(100.0));
        $definition = StrategyDefinition::compose(
            StrategyDefinition::of(\Tests\Fixture\StatefulStrategy::class, label: 'outer'),
            StrategyDefinition::compose(StrategyDefinition::of(\Tests\Fixture\StatefulStrategy::class, label: 'inner')),
        );
        $invocation = new CacheInvocation(
            new CacheKeyContext('', 'Q', 'Q', 'execute', [], '1', 'f'),
            new CachePolicy(ttl: 0),
            static fn (): Cached => Cached::of('value'),
            strategy: $definition,
        );
        $first = $runtime->execute($invocation);
        $second = $runtime->execute($invocation);

        self::assertContains('outer:1:1', $first->metadata->tags);
        self::assertContains('inner:1:1', $first->metadata->tags);
        self::assertSame($first->metadata->tags, $second->metadata->tags);
    }

    public function testExecuteDoesNotShareStateWithANestedInvocation(): void
    {
        $runtime = new CacheRuntime(new MemoryCache(), new MutableClock(100.0));
        $definition = StrategyDefinition::of(\Tests\Fixture\StatefulStrategy::class);
        $inner = new CacheInvocation(
            new CacheKeyContext('', 'Q', 'Q', 'execute', ['id' => 2], '1', 'f'),
            new CachePolicy(ttl: 0),
            static fn (): Cached => Cached::of('inner'),
            strategy: $definition,
        );
        $outer = new CacheInvocation(
            new CacheKeyContext('', 'Q', 'Q', 'execute', ['id' => 1], '1', 'f'),
            new CachePolicy(ttl: 0),
            static function () use ($runtime, $inner): Cached {
                $runtime->execute($inner);

                return Cached::of('outer');
            },
            strategy: $definition,
        );
        $result = $runtime->execute($outer);
        $key = (new \Magix\Cache\Runtime\KeyStrategy\HashCacheKeyStrategy())->generate($outer->context->withNamespace('magix'));

        self::assertContains('state:1:1', $result->metadata->tags);
        self::assertContains('lookup:'.$key, $result->metadata->tags);
    }

    public function testExecuteKeepsNestedStaleCandidatesInTheirOwnStrategies(): void
    {
        $clock = new MutableClock(100.0);
        $runtime = new CacheRuntime(new MemoryCache(), $clock);
        $definition = StrategyDefinition::of(\Magix\Cache\Strategy\StaleIfErrorCacheStrategy::class, maxAge: 30, exceptions: [UpstreamUnavailable::class]);
        $policy = new CachePolicy(ttl: 10);
        $outerKey = new CacheKeyContext('', 'Q', 'Q', 'execute', ['id' => 1], '1', 'f');
        $innerKey = new CacheKeyContext('', 'Q', 'Q', 'execute', ['id' => 2], '1', 'f');
        $runtime->execute(new CacheInvocation($outerKey, $policy, static fn (): Cached => Cached::of('outer-old'), strategy: $definition));
        $runtime->execute(new CacheInvocation($innerKey, $policy, static fn (): Cached => Cached::of('inner-old'), strategy: $definition));
        $clock->advance(11.0);
        $inner = new CacheInvocation($innerKey, $policy, static fn (): Cached => throw new UpstreamUnavailable('inner-down'), strategy: $definition);
        $outer = new CacheInvocation(
            $outerKey,
            $policy,
            /** @throws UpstreamUnavailable */
            static function () use ($runtime, $inner): Cached {
                $runtime->execute($inner);

                throw new UpstreamUnavailable('outer-down');
            },
            strategy: $definition,
        );

        $result = $runtime->execute($outer);

        self::assertSame('outer-old', $result->value());
        self::assertSame(110.0, $result->metadata->expiresAt);
    }

    public function testExecuteDoesNotReuseACandidateFromAnotherKey(): void
    {
        $clock = new MutableClock(100.0);
        $runtime = new CacheRuntime(new MemoryCache(), $clock);
        $definition = StrategyDefinition::of(\Magix\Cache\Strategy\StaleIfErrorCacheStrategy::class, maxAge: 30, exceptions: [UpstreamUnavailable::class]);
        $key = new CacheKeyContext('', 'Q', 'Q', 'execute', ['id' => 1], '1', 'f');
        $policy = new CachePolicy(ttl: 10);
        $runtime->execute(new CacheInvocation($key, $policy, static fn (): Cached => Cached::of('old'), strategy: $definition));
        $clock->advance(11.0);
        $served = $runtime->execute(new CacheInvocation($key, $policy, static fn (): Cached => throw new UpstreamUnavailable('down'), strategy: $definition));
        self::assertSame('old', $served->value());
        $error = new UpstreamUnavailable('missing');

        $this->expectExceptionObject($error);
        $runtime->execute(new CacheInvocation(
            new CacheKeyContext('', 'Q', 'Q', 'execute', ['id' => 2], '1', 'f'),
            $policy,
            static fn (): Cached => throw $error,
            strategy: $definition,
        ));
    }
    public function testExecuteDoesNotAnswerAnExpiredEntryWithoutAStaleStrategy(): void
    {
        $store = new MemoryCache();
        $context = new CacheKeyContext('', 'Q', 'Q', 'execute', [], '1', 'f');
        $key = (new \Magix\Cache\Runtime\KeyStrategy\HashCacheKeyStrategy())->generate($context->withNamespace('magix'));
        $store->set($key, new \Magix\Cache\Cache\CacheEntry('old', new CacheMetadata(expiresAt: 90.0), 130.0));
        $error = new UpstreamUnavailable('down');

        $this->expectExceptionObject($error);
        (new CacheRuntime($store, new MutableClock(100.0)))->execute(new CacheInvocation(
            $context,
            new CachePolicy(ttl: 10),
            static fn (): Cached => throw $error,
        ));
    }
    public function testExecuteOuterStrategyOverridesDynamicParameterPolicyAndDependencyTtls(): void
    {
        $clock = new MutableClock(100.25);
        $resolver = new FixedTtlResolver(15);
        $runtime = new CacheRuntime(new MemoryCache(), $clock, ttlResolvers: [$resolver]);
        $source = new CacheMetadata(expiresAt: 120.0, tags: ['source'], visibility: Visibility::Private);
        $outer = StrategyDefinition::of(KeySpreadExpirationStrategy::class, minimum: 60, maximum: 60);
        $inner = StrategyDefinition::of(KeySpreadExpirationStrategy::class, minimum: 30, maximum: 30);
        $invocation = new CacheInvocation(
            context: new CacheKeyContext('', 'App\\Q', 'App\\Q', 'execute', [], '1', 'priority'),
            policy: new CachePolicy(ttl: 5),
            origin: static fn (): Cached => Cached::of('value', $source),
            dynamicTtl: new DynamicTtl(resolver: FixedTtlResolver::class),
            parameterTtl: 10,
            strategy: StrategyDefinition::compose($outer, $inner),
        );
        $first = $runtime->execute($invocation);
        self::assertEquals($source->withExpiration(160.25), $first->metadata);
        $clock->advance(5.0);
        self::assertEquals($first, $runtime->execute($invocation));
        self::assertSame(1, $resolver->calls);

        $reversed = new CacheInvocation(
            context: new CacheKeyContext('', 'App\\Q', 'App\\Q', 'execute', [], '1', 'reversed'),
            policy: new CachePolicy(ttl: 90),
            origin: static fn (): Cached => Cached::of('value', $source),
            strategy: StrategyDefinition::compose($inner, $outer),
        );
        self::assertEquals($source->withExpiration(135.25), $runtime->execute($reversed)->metadata);
    }

    public function testExecuteDoesNotHideAFetchStrategyFailureBehindStale(): void
    {
        $clock = new MutableClock(100.0);
        $runtime = new CacheRuntime(new MemoryCache(), $clock);
        $behavior = new StaleIfError(maxAge: 30, exceptions: [RuntimeException::class]);
        $context = new CacheKeyContext('', 'Q', 'Q', 'execute', [], '1', 'f');
        $runtime->execute(new CacheInvocation($context, new CachePolicy(ttl: 10), static fn (): Cached => Cached::of('old'), staleIfError: $behavior));
        $clock->advance(11.0);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('strategy failed');
        $runtime->execute(new CacheInvocation(
            $context,
            new CachePolicy(ttl: 10),
            static fn (): Cached => Cached::of('new'),
            staleIfError: $behavior,
            strategy: StrategyDefinition::of(\Tests\Fixture\FailingStrategy::class, message: 'strategy failed'),
        ));
    }

    public function testExecuteStoresAFreshRecoveryAndServesItOnTheNextLookup(): void
    {
        $calls = 0;
        $clock = new MutableClock(100.0);
        $observer = new RecordingObserver();
        $runtime = new CacheRuntime(new MemoryCache(), $clock, observer: $observer);
        $invocation = new CacheInvocation(
            new CacheKeyContext('', 'Q', 'Q', 'execute', [], '1', 'f'),
            new CachePolicy(ttl: 20),
            /** @throws UpstreamUnavailable */
            static function () use (&$calls): Cached {
                ++$calls;

                throw new UpstreamUnavailable('primary unavailable');
            },
            strategy: StrategyDefinition::compose(
                StrategyDefinition::of(KeySpreadExpirationStrategy::class, minimum: 60, maximum: 60),
                StrategyDefinition::of(\Tests\Fixture\FreshRecoveryStrategy::class),
            ),
        );

        $recovered = $runtime->execute($invocation);
        $clock->advance(5.0);
        $hit = $runtime->execute($invocation);

        self::assertSame('fresh replacement', $recovered->value());
        self::assertSame(160.0, $recovered->metadata->expiresAt);
        self::assertSame(['recovered'], $recovered->metadata->tags);
        self::assertEquals($recovered, $hit);
        self::assertSame(1, $calls);
        self::assertSame([CacheEvent::Miss, CacheEvent::Stored, CacheEvent::FreshHit], $observer->events);
    }
    public function testExecuteStoresAMiddlewareAnswerWithoutInvokingTheOrigin(): void
    {
        $calls = 0;
        $runtime = new CacheRuntime(new MemoryCache(), new MutableClock(100.0));
        $invocation = new CacheInvocation(
            new CacheKeyContext('', 'Q', 'Q', 'execute', [], '1', 'f'),
            new CachePolicy(ttl: 20),
            static function () use (&$calls): Cached {
                ++$calls;

                return Cached::of('origin');
            },
            strategy: StrategyDefinition::of(\Tests\Fixture\ImmediateStrategy::class),
        );

        $first = $runtime->execute($invocation);
        $hit = $runtime->execute($invocation);

        self::assertSame(0, $calls);
        self::assertSame('immediate', $first->value());
        self::assertSame(130.0, $first->metadata->expiresAt);
        self::assertEquals($first, $hit);
    }

    public function testExecuteAnOuterTtlMiddlewareCanOverrideAndStoreAnInnerStaleAnswer(): void
    {
        $clock = new MutableClock(100.0);
        $observer = new RecordingObserver();
        $runtime = new CacheRuntime(new MemoryCache(), $clock, observer: $observer);
        $context = new CacheKeyContext('', 'Q', 'Q', 'execute', [], '1', 'f');
        $definition = StrategyDefinition::compose(
            StrategyDefinition::of(KeySpreadExpirationStrategy::class, minimum: 10, maximum: 10),
            StrategyDefinition::of(\Magix\Cache\Strategy\StaleIfErrorCacheStrategy::class, maxAge: 30, exceptions: [UpstreamUnavailable::class]),
        );
        $runtime->execute(new CacheInvocation($context, new CachePolicy(), static fn (): Cached => Cached::of('old'), strategy: $definition));
        $clock->advance(11.0);
        $invocation = new CacheInvocation($context, new CachePolicy(), static fn (): Cached => throw new UpstreamUnavailable('down'), strategy: $definition);

        $result = $runtime->execute($invocation);
        $clock->advance(1.0);
        $hit = $runtime->execute($invocation);

        self::assertSame('old', $result->value());
        self::assertSame(121.0, $result->metadata->expiresAt);
        self::assertEquals($result, $hit);
        self::assertSame([CacheEvent::Miss, CacheEvent::Stored, CacheEvent::Miss, CacheEvent::Stored, CacheEvent::FreshHit], $observer->events);
    }

    public function testExecuteLocalSettingsKeepTheirOriginTimeAndMiddlewareChoosesItsOwnTime(): void
    {
        $clock = new MutableClock(100.0);
        $resolver = new class ($clock) implements CacheTtlResolver {
            public function __construct(private readonly MutableClock $clock)
            {
            }

            #[Override]
            public function resolve(DynamicTtlContext $context): int
            {
                $this->clock->advance(17.0);

                return 7;
            }
        };
        $runtime = new CacheRuntime(new MemoryCache(), $clock, ttlResolvers: [$resolver]);
        $invocation = new CacheInvocation(
            new CacheKeyContext('', 'Q', 'Q', 'execute', [], '1', 'f'),
            new CachePolicy(ttl: 100),
            static function () use ($clock): Cached {
                $clock->advance(2.25);

                return Cached::of('value', new CacheMetadata(tags: ['dependency']));
            },
            dynamicTtl: new DynamicTtl(resolver: $resolver::class),
            parameterTtl: 90,
            strategy: StrategyDefinition::of(KeySpreadExpirationStrategy::class, minimum: 60, maximum: 60),
        );

        $result = $runtime->execute($invocation);

        self::assertSame(119.25, $clock->time);
        self::assertSame(179.25, $result->metadata->expiresAt);
        self::assertSame(['dependency'], $result->metadata->tags);
    }


    public function testExecuteWithAnEmptyCompositionMatchesExecutionWithoutStrategies(): void
    {
        $clock = new MutableClock(100.25);
        $plainObserver = new RecordingObserver();
        $emptyObserver = new RecordingObserver();
        $plain = new CacheRuntime(new MemoryCache(), $clock, observer: $plainObserver);
        $empty = new CacheRuntime(new MemoryCache(), $clock, observer: $emptyObserver);
        $calls = 0;
        $origin = static function () use (&$calls): Cached {
            ++$calls;

            return Cached::of(func_num_args(), new CacheMetadata(tags: ['origin']));
        };
        $context = new CacheKeyContext('', 'Q', 'Q', 'execute', [], '1', 'f');
        $policy = new CachePolicy(ttl: 10);
        $plainInvocation = new CacheInvocation($context, $policy, $origin);
        $emptyInvocation = new CacheInvocation($context, $policy, $origin, strategy: StrategyDefinition::compose());
        $first = $plain->execute($plainInvocation);
        self::assertEquals($first, $empty->execute($emptyInvocation));
        $clock->advance(2.0);
        self::assertEquals($plain->execute($plainInvocation), $empty->execute($emptyInvocation));
        self::assertSame(2, $calls);
        self::assertSame(0, $first->value());
        self::assertSame(110.25, $first->metadata->expiresAt);
        self::assertSame($plainObserver->events, $emptyObserver->events);
        self::assertSame([CacheEvent::Miss, CacheEvent::Stored, CacheEvent::FreshHit], $emptyObserver->events);
    }
    public function testExecuteAsyncKeepsOriginPendingUntilThePromiseQueueRuns(): void
    {
        $source = new \Tests\Fixture\PendingResult('value');
        $runtime = new CacheRuntime(new MemoryCache(), new MutableClock(100.0));
        $invocation = new CacheInvocation(
            new CacheKeyContext('', 'Query', 'Query', 'fetch', [], '0', ''),
            new CachePolicy(ttl: 20),
            static fn (): \Magix\Cache\AsyncCached => \Magix\Cache\AsyncCached::fromPromise($source->promise()),
        );
        $result = $runtime->executeAsync($invocation);
        self::assertSame(0, $source->waits);
        self::assertSame('value', $result->value());
        self::assertSame(120.0, $result->toCached()->metadata->expiresAt);
        self::assertSame(1, $source->waits);
    }

}
