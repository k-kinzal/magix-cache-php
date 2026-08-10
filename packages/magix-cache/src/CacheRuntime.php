<?php

declare(strict_types=1);

namespace Magix\Cache;

use Closure;
use LogicException;
use Magix\Cache\Cache\Cache;
use Magix\Cache\Clock\SystemClock;
use Magix\Cache\Runtime\CacheEntryConverter;
use Magix\Cache\Runtime\CacheKeyStrategy;
use Magix\Cache\Runtime\CacheStrategy;
use Magix\Cache\Runtime\KeyStrategy\HashCacheKeyStrategy;
use Magix\Cache\Runtime\Metadata\Visibility;
use Magix\Cache\Runtime\Operation\CacheGet;
use Magix\Cache\Runtime\Operation\CacheOperationTerminal;
use Magix\Cache\Runtime\Operation\CacheSet;
use Magix\Cache\Runtime\Operation\OriginFetch;
use Magix\Cache\Runtime\Operation\OriginFetchOutcome;
use Magix\Cache\Runtime\Strategy\PassThroughCacheStrategy;
use Psr\Clock\ClockInterface;

/**
 * Applies cache policies, performs lookups, and persists complete result entries.
 */
final class CacheRuntime
{
    private static ?self $current = null;

    /**
     * Creates a cache runtime using a Magix cache implementation.
     */
    public function __construct(
        private readonly Cache $cache,
        private readonly ClockInterface $clock = new SystemClock(),
        private readonly CacheKeyStrategy $keyStrategy = new HashCacheKeyStrategy(),
    ) {
    }

    /**
     * Returns the strategy used by Cacheable to derive cache keys.
     */
    public function keyStrategy(): CacheKeyStrategy
    {
        return $this->keyStrategy;
    }

    /**
     * Installs or removes the process-local runtime used by Cacheable.
     */
    public static function setCurrent(?self $runtime): void
    {
        self::$current = $runtime;
    }

    /**
     * Returns the installed process-local runtime.
     */
    public static function current(): self
    {
        return self::$current ?? throw new LogicException('No CacheRuntime has been installed.');
    }

    /**
     * Resolves one cache entry using an already determined key and policy.
     *
     * @template T
     * @param Closure(): Cached<T> $compute
     * @param CacheStrategy $strategy Per-boundary cache-operation strategy.
     * @return Cached<T>
     */
    public function execute(
        string $key,
        CachePolicy $policy,
        Closure $compute,
        CacheStrategy $strategy = new PassThroughCacheStrategy(),
    ): Cached {
        $stale = null;
        $entries = new CacheEntryConverter();
        $terminal = new CacheOperationTerminal(
            $this->cache,
            static fn () => $compute()->value(),
        );

        if ($policy->visibility !== Visibility::NoStore) {
            $get = new CacheGet(key: $key, clock: $this->clock);
            $entry = $strategy->get($get, $terminal->get(...));
            $now = (float) $this->clock->now()->format('U.u');

            if ($entry !== null && $entry->retainedUntil > $now) {
                if ($entry->expiresAt > $now) {
                    return $entries->toCached($entry);
                }

                $stale = $entry;
            }
        }

        $fetch = new OriginFetch(
            key: $key,
            origin: $compute,
            stale: $stale,
            clock: $this->clock,
        );
        $fetched = $strategy->fetch($fetch, $terminal->fetch(...));

        if ($fetched->outcome === OriginFetchOutcome::Stale) {
            return $entries->toCached($fetched->staleEntry());
        }

        $now = (float) $this->clock->now()->format('U.u');
        $origin = $fetched->originValue();
        $result = Cached::of($origin->value(), $origin->metadata->applyPolicy($policy, $now));
        $entry = $entries->toEntry($result, $now);

        if ($entry !== null) {
            $strategy->set(new CacheSet($key, $entry), $terminal->set(...));
        }

        return $result;
    }
}
