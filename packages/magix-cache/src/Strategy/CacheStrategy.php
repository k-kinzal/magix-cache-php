<?php

declare(strict_types=1);

namespace Magix\Cache\Strategy;

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
 * Each boundary execution constructs its own strategy objects, including
 * every child. They may hold execution state across get/fetch/set.
 *
 * A strategy publishes the effects it guarantees as contract attributes on
 * its operations, such as Contract\Ttl on fetch(). The analyzer derives the
 * composed behavior from those contracts and the construction code alone,
 * so the implementation is free as long as it honors what it declared.
 */
interface CacheStrategy
{
    /**
     * Returns a storage candidate for this operation, or null for a miss.
     *
     * Delegating exposes physically retained data, including expired entries.
     * The runtime judges freshness after the chain returns. A strategy owns
     * any candidate it needs across the stages of this one execution.
     *
     * @return CacheRead<mixed>|null
     * @throws RuntimeException when a delegated read fails with declared behavior
     */
    public function get(CacheOperation $operation, NextCacheStrategy $next): ?CacheRead;

    /**
     * Produces the value of the boundary with this strategy's constraints.
     *
     * Delegating reaches the origin computation at the end of the chain.
     * OriginResult carries the successful value and its single base time;
     * constraints are added through its metadata meet. OriginFailure carries
     * only declared origin behavior. CacheAnswer ends the execution without
     * applying origin constraints or storing the answer again.
     *
     * @return OriginResult<mixed>|OriginFailure|CacheAnswer<mixed>
     * @throws RuntimeException when the origin or a delegate fails with declared behavior
     */
    public function fetch(CacheOperation $operation, NextCacheStrategy $next): OriginResult|OriginFailure|CacheAnswer;

    /**
     * Stores the produced value, or refuses to by not delegating.
     *
     * Delegating reaches the re-judged storage write at the end of the
     * chain. A strategy may extend the request's physical retention before
     * delegating; extending retention never changes the expiration itself.
     *
     * @param CacheWrite<mixed> $result
     * @throws RuntimeException when a delegated write fails with declared behavior
     */
    public function set(CacheOperation $operation, CacheWrite $result, NextCacheStrategy $next): void;
}
