<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime;

use Closure;
use Magix\Cache\Cached;
use Magix\Cache\CachePolicy;
use Magix\Cache\Clock\UnixClock;
use Magix\Cache\Observation\CacheEvent;
use Magix\Cache\Observation\CacheObserver;
use Magix\Cache\Runtime\Extension\BackendErrorClassifier;
use Magix\Cache\Runtime\Extension\CacheTtlResolver;
use Magix\Cache\Strategy\CacheRead;
use Magix\Cache\Strategy\CacheWrite;
use RuntimeException;

/**
 * Performs the actual cache reads, origin inquiry and cache writes.
 *
 * Lookups expose retained data and writes recheck storage eligibility.
 * Fetch executes the argument-free origin closure,
 * then applies local boundary settings before returning the Cached result.
 * Exceptions propagate through the ordinary middleware call stack.
 *
 * @internal
 */
final readonly class CacheExecution
{
    private CacheEntryConverter $converter;

    /**
     * Supplies storage, the origin and boundary settings for one execution.
     *
     * @param Closure(): Cached<mixed> $origin
     * @param int<0, max>|null $parameterTtl Validated invocation lifetime.
     */
    public function __construct(
        private GuardedCache $cache,
        private ?BackendErrorClassifier $classifier,
        private Closure $origin,
        private UnixClock $clock,
        private ?CacheObserver $observer = null,
        private CachePolicy $policy = new CachePolicy(),
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
    public function get(string $key): ?CacheRead
    {
        $witness = fn (): mixed => ($this->origin)()->value();
        $entry = $this->cache->lookup($key, $this->classifier, $witness, $this->clock->now());

        return $entry === null ? null : new CacheRead($this->converter->toCached($entry), $entry->retainedUntil);
    }

    /**
     * Invokes the origin without arguments and applies local settings.
     *
     * Exceptions from the origin and local resolver propagate unchanged.
     *
     * @return Cached<mixed>
     */
    public function fetch(string $key): Cached
    {
        $result = ($this->origin)();
        $baseTime = $this->clock->now();
        $metadata = (new BoundaryMetadata())->apply(
            $this->policy,
            $this->ttlResolver,
            $result,
            $key,
            $baseTime,
            $this->parameterTtl,
        );

        return Cached::of($result->value(), $metadata);
    }

    /**
     * Rechecks storage eligibility and writes the explicit retention request.
     *
     * @param CacheWrite<mixed> $request
     * @throws RuntimeException when the write fails and is not bypassed
     */
    public function set(string $key, CacheWrite $request): void
    {
        $entry = $this->converter->toEntry($request->cached, $this->clock->now(), $request->retainedUntil);

        if ($entry === null) {
            $this->observer?->observe(CacheEvent::StoreSkipped, $key);

            return;
        }

        $this->cache->write($key, $entry, $this->classifier);
    }
}
