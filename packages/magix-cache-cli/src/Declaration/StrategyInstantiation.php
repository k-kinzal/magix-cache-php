<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Declaration;

/**
 * Holds one strategy a composition constructs, in composition order.
 */
final readonly class StrategyInstantiation
{
    /**
     * Creates a statically read strategy construction.
     *
     * @param string $class Fully qualified class name being constructed.
     * @param list<StrategyArgument> $arguments Written arguments in order.
     * @param bool $viaCreate True when the child is built by its own create() instead of new.
     */
    public function __construct(
        public string $class,
        public array $arguments = [],
        public bool $viaCreate = false,
        public int $line = 0,
    ) {
    }
}
