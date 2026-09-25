<?php

declare(strict_types=1);

namespace Magix\Cache\Strategy;

use Closure;
use Magix\Cache\Async\Promise;
use Magix\Cache\Cached;
use RuntimeException;

/**
 * Middleware over cache reads, argument-free origin inquiries and cache writes.
 *
 * Each next closure performs just the corresponding operation. Middleware may
 * change its arguments or response, catch its declared failures, or not call it.
 * A composition implements this same interface. It neither owns the actual
 * operations nor requires them to implement CacheStrategy.
 *
 * One invocation uses fresh instances, shared across get/fetch/set. Dependencies
 * such as clocks belong to constructors; operation arguments contain only data.
 */
interface CacheStrategy
{
    /**
     * Wraps a read by key; the runtime judges the returned candidate's freshness.
     *
     * @param Closure(string): (CacheRead<mixed>|null) $next Reads the supplied key.
     * @return CacheRead<mixed>|null
     * @throws RuntimeException when the middleware or delegated read fails
     */
    public function get(string $key, Closure $next): ?CacheRead;

    /**
     * Wraps an origin inquiry and may transform its eventual Cached response.
     *
     * The key identifies this cache boundary for key-dependent metadata rules.
     * It is not an origin input: next takes no arguments. Use then for post-processing and rejection callbacks for delayed failures.
     * Every resolved response must
     * preserve the boundary's value type and honor declared metadata effects.
     *
     * @param Closure(): Promise<Cached<mixed>> $next Performs the argument-free inquiry.
     * @return Promise<Cached<mixed>>
     * @throws RuntimeException when the middleware or delegated inquiry fails
     */
    public function fetch(string $key, Closure $next): Promise;

    /**
     * Wraps a write by key and may replace or decline its request.
     *
     * Physical retention is independent of the Cached value's expiration.
     * The actual write rechecks storage eligibility after middleware returns
     * its arguments to next.
     *
     * @param CacheWrite<mixed> $request
     * @param Closure(string, CacheWrite<mixed>): void $next Writes the supplied request.
     * @throws RuntimeException when the middleware or delegated write fails
     */
    public function set(string $key, CacheWrite $request, Closure $next): void;
}
