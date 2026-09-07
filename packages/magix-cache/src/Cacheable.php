<?php

declare(strict_types=1);

namespace Magix\Cache;

use Closure;

use function debug_backtrace;

use LogicException;
use Magix\Cache\Runtime\CacheDefinitionResolver;
use Magix\Cache\Runtime\CacheRuntimeRegistry;
use RuntimeException;

use function str_contains;

/**
 * Adds a declarative cache boundary around one method.
 *
 * cached() is the anti-corruption layer between PHP and the internal model:
 * it captures the call site, resolves the static declaration, and delegates
 * to the runtime the declaration references. Policy, behaviors, and runtime
 * come from attributes, including declared parameter bindings; there is no
 * per-call override path. Binding values are resolved before lookup.
 */
trait Cacheable
{
    private static ?CacheDefinitionResolver $magixCacheDefinitions = null;

    /**
     * Executes the computation once per cache key and propagates its metadata.
     *
     * @template T
     * @param Closure(): Cached<T> $compute
     * @return Cached<T>
     * @throws LogicException when the calling boundary cannot be identified or declares no #[Cache]
     * @throws RuntimeException when the origin computation fails without an eligible stale fallback
     */
    final protected function cached(Closure $compute): Cached
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 2);
        $caller = $trace[1] ?? throw new LogicException('Unable to identify the method that called cached().');

        if (($caller['object'] ?? null) !== $this || str_contains($caller['function'], '{closure')) {
            throw new LogicException('cached() must be called directly from the boundary method, not through a helper or closure.');
        }

        $definitions = self::$magixCacheDefinitions ??= new CacheDefinitionResolver();
        $definition = $definitions->resolve($this, $caller['function']);

        return CacheRuntimeRegistry::resolve($definition->runtime)->execute(
            $definition->invocation($caller['args'] ?? [], $compute),
        );
    }
}
