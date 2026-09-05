<?php

declare(strict_types=1);

namespace Magix\Cache;

use LogicException;
use Magix\Cache\Cache\Cache;
use Magix\Cache\Clock\SystemClock;
use Magix\Cache\Metadata\Visibility;
use Magix\Cache\Runtime\CacheEntryConverter;
use Magix\Cache\Runtime\CacheInvocation;
use Magix\Cache\Runtime\CacheKeyStrategy;
use Magix\Cache\Runtime\Extension\CacheEvent;
use Magix\Cache\Runtime\Extension\CacheObserver;
use Magix\Cache\Runtime\Extension\RegisteredExtensions;
use Magix\Cache\Runtime\GuardedCache;
use Magix\Cache\Runtime\KeyStrategy\HashCacheKeyStrategy;
use Magix\Cache\Runtime\OriginConstraints;
use Magix\Cache\Runtime\StaleReuse;
use Magix\Cache\Runtime\UnixClock;
use Psr\Clock\ClockInterface;
use RuntimeException;

/**
 * Executes cache boundaries through a fixed sequence of stages.
 *
 * The order never depends on how attributes are written: lookup, fresh-hit
 * judgement, origin execution, constraint application at one base time, and a
 * re-judged store. Only the origin call sits inside the stale-if-error capture
 * range, and the capture takes only the RuntimeException family — failures the
 * origin declares as behavior. Bugs from the origin propagate untouched, and
 * only cache reads and writes sit inside the backend bypass range.
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
        private string $namespace = 'magix',
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
     * @throws RuntimeException when the origin fails without an eligible stale fallback
     * @throws LogicException when a referenced extension is not registered or a derived lifetime cannot be derived
     */
    public function execute(CacheInvocation $invocation): Cached
    {
        $converter = new CacheEntryConverter();
        $reuse = new StaleReuse();
        $clock = new UnixClock($this->clock);
        $key = $this->keyStrategy->generate($invocation->context->withNamespace($this->namespace));
        $classifier = $this->extensions->classifier($invocation->bypassCacheErrors);
        $resolver = $this->extensions->ttlResolver($invocation->dynamicTtl);
        $cache = new GuardedCache($this->cache, $this->observer);
        $fresh = null;
        $stale = null;

        if ($invocation->policy->visibility !== Visibility::NoStore) {
            $witness = static fn (): mixed => ($invocation->origin)()->value();
            [$fresh, $stale] = $cache->lookup($key, $classifier, $witness, $clock->now());
        }

        if ($fresh !== null) {
            return $converter->toCached($fresh);
        }

        try {
            $result = ($invocation->origin)();
        } catch (RuntimeException $error) {
            $served = $reuse->candidate($invocation->staleIfError, $stale, $error, $clock->now());

            if ($served === null) {
                throw $error;
            }

            $this->observer?->observe(CacheEvent::StaleServed, $key);

            return $converter->toCached($served);
        }

        $metadata = (new OriginConstraints())->apply($invocation->policy, $resolver, $result, $key, $clock->now());
        $result = Cached::of($result->value(), $metadata);
        $entry = $converter->toEntry($result, $clock->now(), $reuse->retention($invocation->staleIfError, $metadata));

        if ($entry === null) {
            $this->observer?->observe(CacheEvent::StoreSkipped, $key);

            return $result;
        }

        $cache->write($key, $entry, $classifier);

        return $result;
    }
}
