<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Graph;

/**
 * Names what a static analysis can honestly say about a lifetime.
 */
enum TtlEstimateState: string
{
    /**
     * A finite lifetime in seconds is statically determined.
     */
    case Known = 'known';

    /**
     * It is statically certain that no expiration constraint is provided.
     */
    case Unconstrained = 'unconstrained';

    /**
     * The lifetime depends on runtime values, branches, or resolvers.
     */
    case Unknown = 'unknown';

    /**
     * The declaration or a confirmed combination cannot work as written.
     */
    case Invalid = 'invalid';
}
