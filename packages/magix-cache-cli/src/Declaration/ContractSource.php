<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Declaration;

/**
 * Names where a contract value reference takes its value from.
 */
enum ContractSource
{
    /**
     * A constructor argument of the strategy declaring the contract.
     */
    case Constructor;

    /**
     * An argument of the create() method the declaration sits on.
     */
    case Create;
}
