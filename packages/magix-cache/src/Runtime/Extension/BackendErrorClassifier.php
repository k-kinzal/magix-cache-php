<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime\Extension;

use RuntimeException;

/**
 * Classifies storage failures for the #[BypassCacheErrors] behavior.
 *
 * The runtime consults a classifier only around cache reads and writes, and
 * only for the RuntimeException family the Cache port declares its failures
 * in; origin failures, definition errors, and bugs never reach it. A
 * classified read failure becomes a miss and a classified write failure
 * becomes a skipped write, while everything else propagates unchanged.
 */
interface BackendErrorClassifier
{
    /**
     * Reports whether the failure is a backend fault the runtime may bypass.
     */
    public function isBackendFailure(RuntimeException $error, CacheAccess $access): bool;
}
