<?php

declare(strict_types=1);

namespace Magix\Cache\Strategy;

/**
 * Base class for strategy compositions built by a typed static create() definition.
 *
 * A subclass declares one public static create() that takes typed arguments,
 * describes its child strategies, and connects their definitions with compose().
 * The returned definition composes again. The runtime constructs a fresh
 * composition for each execution; no executable instance is memoized.
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
    final protected static function compose(StrategyDefinition $first, StrategyDefinition ...$rest): StrategyDefinition
    {
        return StrategyDefinition::compose($first, ...$rest);
    }
}
