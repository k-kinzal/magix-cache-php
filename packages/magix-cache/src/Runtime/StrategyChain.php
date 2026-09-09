<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime;

use Magix\Cache\Runtime\Extension\BackendErrorClassifier;
use Magix\Cache\Runtime\Extension\CacheObserver;
use Magix\Cache\Runtime\Extension\CacheTtlResolver;
use Magix\Cache\Strategy\NextCacheStrategy;

/**
 * Binds newly constructed strategies to this execution's terminal stages.
 *
 * @internal
 */
final readonly class StrategyChain
{
    /**
     * Supplies the guarded storage capabilities of one runtime execution.
     */
    public function __construct(private GuardedCache $cache, private ?BackendErrorClassifier $classifier, private ?CacheObserver $observer)
    {
    }

    /**
     * Creates an independent composition for the invocation.
     *
     * @template T
     * @param CacheInvocation<T> $invocation
     */
    public function bind(CacheInvocation $invocation, ?CacheTtlResolver $ttlResolver = null): NextCacheStrategy
    {
        $terminal = new TerminalCacheStrategy($this->cache, $this->classifier, $invocation->origin, $invocation->policy, $this->observer, $ttlResolver, $invocation->parameterTtl);
        $strategy = $invocation->strategy?->instantiate();

        return $strategy === null ? NextCacheStrategy::of($terminal) : NextCacheStrategy::of($strategy, $terminal);
    }
}
