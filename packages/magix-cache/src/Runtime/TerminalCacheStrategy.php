<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime;

use Closure;
use Magix\Cache\Cached;
use Magix\Cache\CachePolicy;
use Magix\Cache\Runtime\Extension\BackendErrorClassifier;
use Magix\Cache\Runtime\Extension\CacheEvent;
use Magix\Cache\Runtime\Extension\CacheObserver;
use Magix\Cache\Runtime\Extension\CacheTtlResolver;
use Magix\Cache\Strategy\CacheOperation;
use Magix\Cache\Strategy\CacheRead;
use Magix\Cache\Strategy\CacheStrategy;
use Magix\Cache\Strategy\CacheWrite;
use Magix\Cache\Strategy\NextCacheStrategy;
use Magix\Cache\Strategy\OriginFailure;
use Magix\Cache\Strategy\OriginResult;
use Override;
use RuntimeException;

/**
 * Executes the fixed storage and origin stage bodies and the boundary overrides before returning to strategies.
 *
 * Lookups expose retained data, origin calls report success or declared
 * failure, and writes recheck storage eligibility. Strategies own all
 * fallback decisions. Only the origin call becomes an OriginFailure.
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
     * @param int<0, max>|null $parameterTtl Validated invocation lifetime.
     */
    public function __construct(
        private GuardedCache $cache,
        private ?BackendErrorClassifier $classifier,
        private Closure $origin,
        private CachePolicy $policy,
        private ?CacheObserver $observer = null,
        private ?CacheTtlResolver $ttlResolver = null,
        private ?int $parameterTtl = null,
    ) {
        $this->converter = new CacheEntryConverter();
    }

    /**
     * Exposes physically retained data to the lookup chain.
     *
     * @return CacheRead<mixed>|null
     * @throws RuntimeException when the read fails and is not bypassed
     */
    #[Override]
    public function get(CacheOperation $operation, NextCacheStrategy $next): ?CacheRead
    {
        $witness = fn (): mixed => ($this->origin)()->value();
        $entry = $this->cache->lookup($operation->key(), $this->classifier, $witness, $operation->now());

        return $entry === null ? null : new CacheRead($this->converter->toCached($entry), $entry->retainedUntil);
    }

    /**
     * Captures declared origin failure or stamps the successful result's time.
     *
     * @return OriginResult<mixed>|OriginFailure
     */
    #[Override]
    public function fetch(CacheOperation $operation, NextCacheStrategy $next): OriginResult|OriginFailure
    {
        try {
            $result = ($this->origin)();
        } catch (RuntimeException $error) {
            return new OriginFailure($error);
        }

        $baseTime = $operation->now();

        $metadata = (new OriginOverrides())->apply($this->policy, $this->ttlResolver, $result, $operation->key(), $baseTime, $this->parameterTtl);
        $result = Cached::of($result->value(), $metadata);

        return new OriginResult($result, $baseTime);
    }

    /**
     * Rechecks storage eligibility and writes the explicit retention request.
     *
     * @param CacheWrite<mixed> $request
     * @throws RuntimeException when the write fails and is not bypassed
     */
    #[Override]
    public function set(CacheOperation $operation, CacheWrite $request, NextCacheStrategy $next): void
    {
        $entry = $this->converter->toEntry($request->cached, $operation->now(), $request->retainedUntil);

        if ($entry === null) {
            $this->observer?->observe(CacheEvent::StoreSkipped, $operation->key());

            return;
        }

        $this->cache->write($operation->key(), $entry, $this->classifier);
    }
}
