<?php

declare(strict_types=1);

namespace Magix\Cache\Strategy;

use Magix\Cache\Cached;
use RuntimeException;

/**
 * One composable unit of cache behavior with the contract it publishes.
 *
 * A strategy participates in the fixed stage order of the runtime — lookup,
 * origin execution, store — by wrapping the same three operations of the
 * strategies behind it. Composing strategies yields another CacheStrategy,
 * so a composition can be composed again; the order is meaningful, because
 * it decides pre- and post-processing, the capture range of failures, and
 * which delegations are short-circuited.
 *
 * A strategy publishes the effects it guarantees as contract attributes on
 * its operations, such as Contract\Ttl on fetch(). The analyzer derives the
 * composed behavior from those contracts and the construction code alone,
 * so the implementation is free as long as it honors what it declared.
 */
interface CacheStrategy
{
    /**
     * Returns the cached value for this operation, or null for a miss.
     *
     * Delegating reaches the storage lookup at the end of the chain, which
     * retains an expired-but-retained entry on the operation as the stale
     * candidate for the fetch stage.
     *
     * @return Cached<mixed>|null
     * @throws RuntimeException when a delegated read fails with declared behavior
     */
    public function get(CacheOperation $operation, NextCacheStrategy $next): ?Cached;

    /**
     * Produces the value of the boundary with this strategy's constraints.
     *
     * Delegating reaches the origin computation at the end of the chain.
     * Constraints on the produced metadata may only be added through the
     * metadata meet on the normal origin path, after the delegate returned
     * with the origin base time stamped on the operation.
     *
     * @return Cached<mixed>
     * @throws RuntimeException when the origin or a delegate fails with declared behavior
     */
    public function fetch(CacheOperation $operation, NextCacheStrategy $next): Cached;

    /**
     * Stores the produced value, or refuses to by not delegating.
     *
     * Delegating reaches the re-judged storage write at the end of the
     * chain. A strategy may extend the physical retention through the
     * operation before delegating; extending retention never changes the
     * expiration itself.
     *
     * @param Cached<mixed> $result
     * @throws RuntimeException when a delegated write fails with declared behavior
     */
    public function set(CacheOperation $operation, Cached $result, NextCacheStrategy $next): void;
}
