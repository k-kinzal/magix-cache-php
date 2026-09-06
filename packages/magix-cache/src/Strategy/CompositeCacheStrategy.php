<?php

declare(strict_types=1);

namespace Magix\Cache\Strategy;

/**
 * Base class for strategy compositions built by a typed static create().
 *
 * A subclass declares one public static create() that takes typed arguments,
 * constructs its child strategies, and connects them with compose(). The
 * returned value is an ordinary CacheStrategy: the runtime executes it, and
 * another composition can take it as a child.
 *
 * The composition never re-declares the contracts of its children. The
 * analyzer binds the create() arguments to the same construction code and
 * derives the composed contract from the child contracts, so create() only
 * has to build what actually runs. A child whose construction is outside
 * the analyzable sources can be covered with an explicit
 * Contract\AssumeTtl declaration on create().
 */
abstract class CompositeCacheStrategy
{
    /**
     * Connects strategies into one, in delegation order.
     *
     * The first strategy wraps everything after it: its pre-processing runs
     * first, its post-processing last, and its failure capture surrounds the
     * delegates. The result composes again.
     */
    final protected static function compose(CacheStrategy $first, CacheStrategy ...$rest): CacheStrategy
    {
        return new ComposedCacheStrategy($first, ...$rest);
    }
}
