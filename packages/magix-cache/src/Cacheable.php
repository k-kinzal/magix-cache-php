<?php

declare(strict_types=1);

namespace Magix\Cache;

use Closure;

use function debug_backtrace;

use LogicException;
use Magix\Cache\Runtime\CacheDefinitionResolver;
use Magix\Cache\Runtime\CacheStrategy;
use Magix\Cache\Runtime\Strategy\PassThroughCacheStrategy;

/**
 * Adds a structurally safe cache boundary with explicit or attributed policy.
 */
trait Cacheable
{
    private static ?CacheDefinitionResolver $magixCacheDefinitions = null;

    /**
     * Executes the computation once per cache key and propagates its metadata.
     *
     * @template T
     * @param Closure(): Cached<T> $compute
     * @param CacheStrategy $strategy Per-boundary cache-operation strategy.
     * @return Cached<T>
     */
    final protected function cached(
        Closure $compute,
        ?CachePolicy $policy = null,
        CacheStrategy $strategy = new PassThroughCacheStrategy(),
    ): Cached {
        $trace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 2);
        $caller = $trace[1] ?? throw new LogicException('Unable to identify the method that called cached().');
        $arguments = $caller['args'] ?? [];
        $definitions = self::$magixCacheDefinitions ??= new CacheDefinitionResolver();
        $runtime = CacheRuntime::current();

        $definition = $definitions->resolve($this, $caller['function']);
        $policy ??= $definition->policy()
            ?? throw new LogicException($this::class.'::'.$caller['function'].' requires an explicit CachePolicy or #[Cache].');
        $policy = $policy->restrictVisibility($definition->visibility());
        $context = $definition->keyContext($arguments, $policy->version);

        return $runtime->execute(
            $runtime->keyStrategy()->generate($context),
            $policy,
            $compute,
            $strategy,
        );
    }
}
