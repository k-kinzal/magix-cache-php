<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime;

use Closure;
use Magix\Cache\Attribute\BypassCacheErrors;
use Magix\Cache\Attribute\DynamicTtl;
use Magix\Cache\Attribute\StaleIfError;
use Magix\Cache\Cached;
use Magix\Cache\CachePolicy;
use Magix\Cache\Strategy\StaleIfErrorCacheStrategy;
use Magix\Cache\Strategy\StrategyDefinition;

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
     * Construction recipe, never an executable strategy instance.
     */
    public ?StrategyDefinition $strategy;

    /**
     * Creates the input of one runtime execution.
     *
     * @param Closure(): Cached<T> $origin
     */
    public function __construct(
        public CacheKeyContext $context,
        public CachePolicy $policy,
        public Closure $origin,
        ?StaleIfError $staleIfError = null,
        public ?DynamicTtl $dynamicTtl = null,
        public ?BypassCacheErrors $bypassCacheErrors = null,
        ?StrategyDefinition $strategy = null,
    ) {
        $declared = $staleIfError?->enabled === true
            ? StrategyDefinition::of(StaleIfErrorCacheStrategy::class, maxAge: $staleIfError->maxAge, exceptions: $staleIfError->exceptions)
            : null;
        $this->strategy = $declared === null
            ? $strategy
            : ($strategy === null ? $declared : StrategyDefinition::compose($strategy, $declared));
    }
}
