<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime\Extension;

use Throwable;

/**
 * Classifies storage failures for the #[BypassCacheErrors] behavior.
 *
 * The runtime consults a classifier only around cache reads and writes; origin
 * failures and definition errors never reach it. A classified read failure
 * becomes a miss and a classified write failure becomes a skipped write, while
 * everything else propagates unchanged.
 */
interface BackendErrorClassifier
{
    /**
     * Reports whether the failure is a backend fault the runtime may bypass.
     */
    public function isBackendFailure(Throwable $error, CacheAccess $access): bool;
}
