<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Declaration;

/**
 * Holds one call from a cache boundary to another potential boundary.
 */
final readonly class DependencyCall
{
    /**
     * Creates a dependency call.
     *
     * @param array<int|string, string> $forwarded Caller parameter names indexed by callee position and by callee parameter name.
     */
    public function __construct(
        public string $class,
        public string $method,
        public int $line,
        public array $forwarded = [],
    ) {
    }
}
