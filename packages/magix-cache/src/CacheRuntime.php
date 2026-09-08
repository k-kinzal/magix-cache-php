<?php

declare(strict_types=1);

namespace Magix\Cache;

use LogicException;
use Magix\Cache\Cache\Cache;
use Magix\Cache\Clock\SystemClock;
use Magix\Cache\Metadata\Visibility;
use Magix\Cache\Runtime\CacheInvocation;
use Magix\Cache\Runtime\CacheKeyContext;
use Magix\Cache\Runtime\CacheKeyStrategy;
use Magix\Cache\Runtime\Extension\CacheEvent;
use Magix\Cache\Runtime\Extension\CacheObserver;
use Magix\Cache\Runtime\Extension\RegisteredExtensions;
use Magix\Cache\Runtime\GuardedCache;
use Magix\Cache\Runtime\KeyStrategy\HashCacheKeyStrategy;
use Magix\Cache\Runtime\OriginConstraints;
use Magix\Cache\Runtime\StrategyChain;
use Magix\Cache\Runtime\UnixClock;
use Magix\Cache\Strategy\CacheAnswer;
use Magix\Cache\Strategy\CacheOperation;
use Magix\Cache\Strategy\CacheRead;
use Magix\Cache\Strategy\CacheWrite;
use Magix\Cache\Strategy\OriginFailure;
use Magix\Cache\Strategy\OriginResult;
use Psr\Clock\ClockInterface;
use RuntimeException;

/**
 * Executes cache boundaries through a fixed sequence of stages.
 *
 * The order never depends on how attributes are written: lookup, fresh-hit
 * judgement, origin execution, constraint application at one base time, and a
 * re-judged store. Each stage runs through the strategy chain of the boundary
 * — the declared composition, when one exists, in front of the terminal that
 * owns the stage bodies — so a strategy wraps the stages without being able
 * to reorder them. Strategy constraints are met before the policy constraint,
 * both evaluated at the single base time taken right after the origin
 * succeeded, so a declared policy can derive its lifetime from what the
 * strategies guarantee. Only the origin call is captured as OriginFailure;
 * strategies decide whether to answer it, and unhandled failures propagate
 * with their original identity. Every invocation constructs fresh strategy
 * instances. Only storage reads and writes sit inside the backend bypass
 * range.
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
     * @throws LogicException when a referenced extension is not registered or a derived lifetime cannot be derived
     */
    public function execute(CacheInvocation $invocation): Cached
    {
        $clock = new UnixClock($this->clock);
        $key = $this->keyStrategy->generate($invocation->context->withNamespace($this->namespace));
        $operation = new CacheOperation($key, $clock->now(...));
        $chain = (new StrategyChain(
            new GuardedCache($this->cache, $this->observer),
            $this->extensions->classifier($invocation->bypassCacheErrors),
            $this->observer,
        ))->bind($invocation);
        $resolver = $this->extensions->ttlResolver($invocation->dynamicTtl);

        if ($invocation->policy->visibility !== Visibility::NoStore) {
            /** @var CacheRead<T>|null $read */
            $read = $chain->get($operation);

            if ($read !== null && $read->isFresh($operation->now())) {
                $this->observer?->observe(CacheEvent::FreshHit, $key);

                return $read->cached;
            }

            $this->observer?->observe(CacheEvent::Miss, $key);
        }

        /** @var OriginResult<T>|OriginFailure|CacheAnswer<T> $fetched */
        $fetched = $chain->fetch($operation);

        if ($fetched instanceof OriginFailure) {
            throw $fetched->error;
        }

        if ($fetched instanceof CacheAnswer) {
            $event = CacheEvent::named($fetched->event);

            if ($event !== null) {
                $this->observer?->observe($event, $key);
            }

            return $fetched->cached;
        }

        $metadata = (new OriginConstraints())->apply($invocation->policy, $resolver, $fetched->cached, $key, $fetched->baseTime, $invocation->parameterTtl);
        $result = Cached::of($fetched->cached->value(), $metadata);
        $chain->set($operation, new CacheWrite($result));

        return $result;
    }
}
