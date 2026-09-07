<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Declaration;

/**
 * Holds one explicit value reference written in a contract declaration.
 *
 * A reference names its source and the parameter it binds to; the analyzer
 * resolves it against the construction code instead of guessing from the
 * name. A reference to a parameter that does not exist is a declaration
 * error, never a silent unknown.
 */
final readonly class ContractReference
{
    /**
     * Creates one contract value reference.
     */
    public function __construct(
        public ContractSource $source,
        public string $name,
    ) {
    }

    /**
     * Returns the reference rendered the way it was declared.
     */
    public function label(): string
    {
        return ($this->source === ContractSource::Constructor ? 'ConstructorArg' : 'Arg')."('".$this->name."')";
    }
}
