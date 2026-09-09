<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime\Extension;

/**
 * Chooses a lifetime override from one successful origin result.
 *
 * The runtime sets expiration to the origin base time plus this lifetime,
 * replacing inherited, policy and parameter expiration. Other fields remain
 * unchanged; a Strategy may override expiration afterward. Implementations are registered with
 * a runtime at bootstrap and referenced from #[DynamicTtl] by class name.
 */
interface CacheTtlResolver
{
    /**
     * Returns the lifetime in seconds this result may keep.
     *
     * The runtime rejects a negative return value as a configuration error.
     */
    public function resolve(DynamicTtlContext $context): int;
}
