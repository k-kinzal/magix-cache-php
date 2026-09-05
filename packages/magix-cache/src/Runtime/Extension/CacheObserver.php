<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime\Extension;

/**
 * Receives diagnostic events from the runtime.
 *
 * Observation is one-way: an observer sees what happened and for which key,
 * and cannot change values, metadata, or control flow.
 */
interface CacheObserver
{
    /**
     * Records one runtime event for the supplied cache key.
     */
    public function observe(CacheEvent $event, string $key): void;
}
