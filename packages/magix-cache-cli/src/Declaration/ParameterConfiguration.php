<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Declaration;

/**
 * Describes the configuration destinations attached to a boundary parameter.
 */
final readonly class ParameterConfiguration
{
    /**
     * @param list<string> $problems Malformed attribute declarations.
     */
    public function __construct(
        public bool $ttl = false,
        public bool $tags = false,
        public bool $visibility = false,
        public ?string $strategyArgument = null,
        public array $problems = [],
    ) {
    }

    /**
     * Returns the destinations rendered alongside a parameter's key role.
     */
    public function label(): string
    {
        $labels = [];

        if ($this->ttl) {
            $labels[] = 'cache ttl';
        }

        if ($this->tags) {
            $labels[] = 'cache tags';
        }

        if ($this->visibility) {
            $labels[] = 'cache visibility';
        }

        if ($this->strategyArgument !== null) {
            $labels[] = 'strategy '.$this->strategyArgument;
        }

        return implode(', ', $labels);
    }
}
