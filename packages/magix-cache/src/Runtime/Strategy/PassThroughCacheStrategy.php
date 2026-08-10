<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime\Strategy;

/**
 * Delegates every cache-runtime phase without changing its operation or result.
 */
final readonly class PassThroughCacheStrategy extends CacheStrategyMiddleware
{
}
