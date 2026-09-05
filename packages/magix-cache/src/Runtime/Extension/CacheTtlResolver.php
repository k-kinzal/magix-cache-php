<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime\Extension;

/**
 * Derives an additional lifetime constraint from one successful origin result.
 *
 * A resolver only proposes an expiration candidate: the runtime meets it with
 * the origin metadata, so a resolver can never replace metadata or extend an
 * expiration a dependency already imposed. Implementations are registered with
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
