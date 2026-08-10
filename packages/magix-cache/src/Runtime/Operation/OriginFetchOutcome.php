<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime\Operation;

/**
 * Identifies whether a fetch resolved from the origin or retained stale data.
 */
enum OriginFetchOutcome
{
    case Origin;
    case Stale;
}
