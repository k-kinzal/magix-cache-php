<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime;

use Closure;
use Magix\Cache\Attribute\BypassCacheErrors;
use Magix\Cache\Attribute\DynamicTtl;
use Magix\Cache\Attribute\StaleIfError;
use Magix\Cache\Cached;
use Magix\Cache\CachePolicy;
use Magix\Cache\Strategy\CacheStrategy;

/**
 * Bundles every input one runtime execution needs.
 *
 * An invocation is an internal argument pack, not a public return type: the
 * runtime consumes it immediately, and it is never stored or re-executed.
 *
 * @template T
 * @internal
 */
final readonly class CacheInvocation
{
    /**
     * Creates the input of one runtime execution.
     *
     * @param Closure(): Cached<T> $origin
     */
    public function __construct(
        public CacheKeyContext $context,
        public CachePolicy $policy,
        public Closure $origin,
        public ?StaleIfError $staleIfError = null,
        public ?DynamicTtl $dynamicTtl = null,
        public ?BypassCacheErrors $bypassCacheErrors = null,
        public ?CacheStrategy $strategy = null,
    ) {
    }
}
