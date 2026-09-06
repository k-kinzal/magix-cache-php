<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime;

use Closure;
use Magix\Cache\Attribute\StaleIfError;
use Magix\Cache\Cached;
use Magix\Cache\Runtime\Extension\BackendErrorClassifier;
use Magix\Cache\Runtime\Extension\CacheEvent;
use Magix\Cache\Runtime\Extension\CacheObserver;
use Magix\Cache\Strategy\CacheOperation;
use Magix\Cache\Strategy\CacheStrategy;
use Magix\Cache\Strategy\NextCacheStrategy;

use function max;

use Override;
use RuntimeException;

/**
 * Ends every strategy chain with the fixed stage bodies of the runtime.
 *
 * The terminal answers each operation itself and never delegates: get is the
 * guarded lookup with the fresh-hit judgement, fetch is the origin call
 * inside the stale-if-error capture range, and set is the re-judged store.
 * Only the origin call sits inside the capture range, and the capture takes
 * only the RuntimeException family; only cache reads and writes sit inside
 * the backend bypass range.
 *
 * @internal
 */
final readonly class TerminalCacheStrategy implements CacheStrategy
{
    private CacheEntryConverter $converter;

    /**
     * Creates the terminal of one runtime execution.
     *
     * @param Closure(): Cached<mixed> $origin
     */
    public function __construct(
        private GuardedCache $cache,
        private ?BackendErrorClassifier $classifier,
        private Closure $origin,
        private ?StaleIfError $staleIfError = null,
        private ?CacheObserver $observer = null,
    ) {
        $this->converter = new CacheEntryConverter();
    }

    /**
     * Performs the lookup stage and retains the stale candidate.
     *
     * @return Cached<mixed>|null
     * @throws RuntimeException when the read fails and no classifier accepts the failure
     */
    #[Override]
    public function get(CacheOperation $operation, NextCacheStrategy $next): ?Cached
    {
        $witness = fn (): mixed => ($this->origin)()->value();
        [$fresh, $stale] = $this->cache->lookup($operation->key(), $this->classifier, $witness, $operation->now());

        if ($stale !== null) {
            $operation->retainStale($this->converter->toCached($stale), $stale->retainedUntil);
        }

        return $fresh === null ? null : $this->converter->toCached($fresh);
    }

    /**
     * Performs the origin stage inside the stale-if-error capture range.
     *
     * On success the single base time is stamped on the operation; every
     * constraint of this execution is evaluated at that instant. A served
     * stale candidate keeps its expired expiration and suppresses the store.
     *
     * @return Cached<mixed>
     * @throws RuntimeException when the origin fails without an eligible stale fallback
     */
    #[Override]
    public function fetch(CacheOperation $operation, NextCacheStrategy $next): Cached
    {
        try {
            $result = ($this->origin)();
        } catch (RuntimeException $error) {
            $served = $this->staleIfError?->captures($error) === true
                ? $operation->staleWithin($this->staleIfError->maxAge)
                : null;

            if ($served === null) {
                throw $error;
            }

            $this->observer?->observe(CacheEvent::StaleServed, $operation->key());
            $operation->suppressStore();

            return $served;
        }

        $operation->stampBaseTime($operation->now());

        return $result;
    }

    /**
     * Performs the store stage, re-judged immediately before the write.
     *
     * @param Cached<mixed> $result
     * @throws RuntimeException when the write fails and no classifier accepts the failure
     */
    #[Override]
    public function set(CacheOperation $operation, Cached $result, NextCacheStrategy $next): void
    {
        $entry = $this->converter->toEntry($result, $operation->now(), $this->retention($operation, $result));

        if ($entry === null) {
            $this->observer?->observe(CacheEvent::StoreSkipped, $operation->key());

            return;
        }

        $this->cache->write($operation->key(), $entry, $this->classifier);
    }

    /**
     * Returns the physical retention deadline stale handling asks for.
     *
     * The declared behavior and the strategies of the chain only ever grow
     * the retention; extending it never changes the expiration itself.
     *
     * @param Cached<mixed> $result
     */
    public function retention(CacheOperation $operation, Cached $result): ?float
    {
        $requested = $operation->retention();
        $expiresAt = $result->metadata->expiresAt;

        if ($this->staleIfError === null || $expiresAt === null) {
            return $requested;
        }

        $declared = $expiresAt + $this->staleIfError->maxAge;

        return $requested === null ? $declared : max($declared, $requested);
    }
}
