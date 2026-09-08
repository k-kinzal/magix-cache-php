<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Declaration;

/**
 * Holds a daily expiration declaration, including malformed source values.
 */
final readonly class ExpirationContract
{
    /**
     * Retains literal values or ContractReference/Unresolved for later binding.
     *
     * @param list<string> $problems Invalid argument shapes read without execution.
     */
    public function __construct(
        public mixed $at = Unresolved::Value,
        public mixed $until = null,
        public mixed $timezone = 'UTC',
        public array $problems = [],
    ) {
    }
}
