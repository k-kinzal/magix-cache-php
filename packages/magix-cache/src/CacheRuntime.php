<?php

declare(strict_types=1);

namespace Magix\Cache;

use LogicException;
use Magix\Cache\Async\Promise;
use Magix\Cache\Cache\Cache;
use Magix\Cache\Clock\SystemClock;
use Magix\Cache\Clock\UnixClock;
use Magix\Cache\Metadata\Visibility;
use Magix\Cache\Observation\CacheEvent;
use Magix\Cache\Observation\CacheObserver;
use Magix\Cache\Runtime\CacheExecution;
use Magix\Cache\Runtime\CacheInvocation;
use Magix\Cache\Runtime\CacheKeyContext;
use Magix\Cache\Runtime\CacheKeyStrategy;
use Magix\Cache\Runtime\Extension\RegisteredExtensions;
use Magix\Cache\Runtime\GuardedCache;
use Magix\Cache\Runtime\KeyStrategy\HashCacheKeyStrategy;
use Magix\Cache\Runtime\StrategyFactory;
use Magix\Cache\Strategy\CacheRead;
use Magix\Cache\Strategy\CacheWrite;
use Psr\Clock\ClockInterface;
use RuntimeException;

/**
 * Executes cache boundaries through a fixed sequence of stages.
 *
 * After lookup and fresh-hit judgement the runtime invokes the composed
 * fetch handler. Middleware owns delegation, result transformations and
 * exception handling. Every returned Cached
 * enters the same set chain. Each invocation constructs fresh strategy
 * instances, shared across that invocation's operations.
 */
final readonly class CacheRuntime
{
    private RegisteredExtensions $extensions;

    /**
     * Creates a cache runtime using a Magix cache implementation.
     *
     * @param string $namespace Key namespace that separates this runtime's entries.
     * @param list<Runtime\Extension\CacheTtlResolver> $ttlResolvers Resolvers referenced by #[DynamicTtl].
     * @param list<Runtime\Extension\BackendErrorClassifier> $errorClassifiers Classifiers referenced by #[BypassCacheErrors].
     */
    public function __construct(
        private Cache $cache,
        private ClockInterface $clock = new SystemClock(),
        private CacheKeyStrategy $keyStrategy = new HashCacheKeyStrategy(),
        private string $namespace = CacheKeyContext::DEFAULT_NAMESPACE,
        array $ttlResolvers = [],
        array $errorClassifiers = [],
        private ?CacheObserver $observer = null,
    ) {
        $this->extensions = new RegisteredExtensions($ttlResolvers, $errorClassifiers);
    }

    /**
     * Resolves one cache boundary through the fixed execution stages.
     *
     * @template T
     * @param CacheInvocation<T> $invocation
     * @return Cached<T>
     * @throws RuntimeException when an origin failure remains unanswered or a delegated stage fails
     * @throws LogicException when a referenced extension is not registered
     */
    public function execute(CacheInvocation $invocation): Cached
    {
        return $this->executeAsync($invocation)->toCached();
    }

    /**
     * Starts a boundary and registers its write after successful completion.
     *
     * @template T
     * @param CacheInvocation<T> $invocation
     * @return AsyncCached<T>
     * @throws RuntimeException when lookup fails
     * @throws LogicException when a referenced extension is not registered
     */
    public function executeAsync(CacheInvocation $invocation): AsyncCached
    {
        $clock = new UnixClock($this->clock);
        $key = $this->keyStrategy->generate($invocation->context->withNamespace($this->namespace));
        $execution = new CacheExecution(
            new GuardedCache($this->cache, $this->observer),
            $this->extensions->classifier($invocation->bypassCacheErrors),
            $invocation->origin,
            $clock,
            $this->observer,
            $invocation->policy,
            $this->extensions->ttlResolver($invocation->dynamicTtl),
            $invocation->parameterTtl,
        );
        $strategy = $invocation->strategy?->instantiate((new StrategyFactory($this->clock, $this->observer))->create(...));

        if ($invocation->policy->visibility !== Visibility::NoStore) {
            /** @var CacheRead<T>|null $read */
            $read = $strategy === null ? $execution->get($key) : $strategy->get($key, $execution->get(...));

            if ($read !== null && $read->isFresh($clock->now())) {
                $this->observer?->observe(CacheEvent::FreshHit, $key);

                return AsyncCached::fromCached($read->cached);
            }

            $this->observer?->observe(CacheEvent::Miss, $key);
        }

        $promise = Promise::call(static fn (): Promise => $strategy === null
            ? $execution->fetch($key)
            : $strategy->fetch($key, static fn (): Promise => $execution->fetch($key)));

        $store = static function (Cached $result) use ($strategy, $execution, $key): Cached {
            if ($strategy === null) {
                $execution->set($key, new CacheWrite($result));
            } else {
                $strategy->set($key, new CacheWrite($result), $execution->set(...));
            }

            return $result;
        };

        /** @var Promise<Cached<T>> $stored Middleware and storage preserve the boundary's payload type. */
        $stored = $promise->then($store);

        return AsyncCached::fromCachedPromise($stored);
    }

}
