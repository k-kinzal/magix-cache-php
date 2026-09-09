<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Declaration;

use function strrpos;
use function substr;

/**
 * Holds one strategy class with its contracts and its construction shape.
 *
 * A leaf strategy carries the lifetime contract of its fetch operation; a
 * composition additionally carries the create() parameters, the children it
 * composes in order, and the explicit assumptions declared for children the
 * analysis cannot reach. When the composition cannot be read statically the
 * children stay null with a note, never an empty guess.
 */
final readonly class StrategyDeclaration
{
    /**
     * Creates a statically read strategy class.
     *
     * @param list<StrategyParameter> $parameters Constructor parameters in order.
     * @param list<StrategyParameter> $createParameters Parameters of static create(), when declared.
     * @param bool $hasCreate Whether the class declares a static create().
     * @param list<StrategyInstantiation>|null $composed Children in composition order, or null when unreadable.
     * @param TtlContract|null $ttl Lifetime contract declared on the fetch operation.
     * @param list<TtlAssumption> $assumptions Explicit assumptions declared on create().
     * @param list<string> $notes Why parts of the declaration could not be read.
     * @param bool $constructible Whether this class can be a constructed CacheStrategy leaf.
     * @param list<ExpirationContract> $expirations Daily wall-clock contracts on fetch(), in declaration order, independent of TTL.
     * @param string|null $definitionProblem A factory signature incompatible with StrategyDefinition.
     */
    public function __construct(
        public string $name,
        public string $file = '',
        public int $line = 0,
        public array $parameters = [],
        public array $createParameters = [],
        public bool $hasCreate = false,
        public ?array $composed = null,
        public ?TtlContract $ttl = null,
        public array $assumptions = [],
        public array $notes = [],
        public bool $constructible = true,
        public ?string $definitionProblem = null,
        public array $expirations = [],
    ) {
    }

    /**
     * Returns the class name without its namespace.
     */
    public function shortName(): string
    {
        $separator = strrpos($this->name, '\\');

        return $separator === false ? $this->name : substr($this->name, $separator + 1);
    }

    /**
     * Returns the assumption declared for one composed strategy, when any.
     */
    public function assumptionFor(string $class): ?TtlAssumption
    {
        foreach ($this->assumptions as $assumption) {
            if ($assumption->strategy === $class) {
                return $assumption;
            }
        }

        return null;
    }
}
