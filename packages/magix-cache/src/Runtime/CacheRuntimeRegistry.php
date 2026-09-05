<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime;

use Closure;
use InvalidArgumentException;
use LogicException;
use Magix\Cache\CacheRuntime;

/**
 * Maps runtime reference names to runtimes fixed at bootstrap.
 *
 * A name is registered once and never rebound afterwards, so the registry is
 * a lookup window, not per-request mutable state. A provider closure supports
 * request-scoped integrations: it is invoked on every resolution and its
 * result is never memoized here.
 */
final class CacheRuntimeRegistry
{
    /**
     * Runtime reference resolved when a declaration names none.
     */
    public const string DEFAULT_NAME = 'default';

    /** @var array<non-empty-string, CacheRuntime|Closure> */
    private static array $runtimes = [];

    /**
     * Registers a runtime or provider under a reference name at bootstrap.
     *
     * @param Closure(): CacheRuntime|CacheRuntime $runtime
     * @throws InvalidArgumentException when the reference name is empty
     * @throws LogicException when the reference name is already fixed
     */
    public static function register(string $name, CacheRuntime|Closure $runtime): void
    {
        if ($name === '') {
            throw new InvalidArgumentException('Runtime reference name must not be empty.');
        }

        if (isset(self::$runtimes[$name])) {
            throw new LogicException('Runtime reference "'.$name.'" is already fixed; references are registered once at bootstrap.');
        }

        self::$runtimes[$name] = $runtime;
    }

    /**
     * Reports whether a reference name has been registered.
     *
     * Framework integrations use this to observe the bootstrap lifecycle
     * without resolve(), which treats a missing reference as a definition error.
     */
    public static function isRegistered(string $name): bool
    {
        return isset(self::$runtimes[$name]);
    }

    /**
     * Resolves a reference name to a runtime.
     *
     * @throws LogicException when the reference is unknown or its provider returns no runtime
     */
    public static function resolve(string $name): CacheRuntime
    {
        $registered = self::$runtimes[$name]
            ?? throw new LogicException('No CacheRuntime is registered under "'.$name.'".');

        if ($registered instanceof CacheRuntime) {
            return $registered;
        }

        $resolved = $registered();

        if (!$resolved instanceof CacheRuntime) {
            throw new LogicException('The provider registered under "'.$name.'" did not return a CacheRuntime.');
        }

        return $resolved;
    }

    /**
     * Clears every registration for process bootstrap or test isolation.
     */
    public static function reset(): void
    {
        self::$runtimes = [];
    }
}
