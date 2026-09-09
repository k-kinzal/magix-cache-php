<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Declaration;

/**
 * Describes returned metadata separately from the calls made while computing a value.
 */
final readonly class MetadataFlow
{
    /**
     * @param 'none'|'unknown'|'call'|'meet'|'choice'|'value'|'wrap'|'preserve' $kind
     * @param list<self> $inputs
     * @param string|null $target A fully qualified method identifier for a call.
     */
    public function __construct(public string $kind, public array $inputs = [], public ?string $target = null)
    {
    }

    /**
     * Reports opaque syntax even when it occurs in only one alternative.
     */
    public function hasUnknown(): bool
    {
        if ($this->kind === 'unknown') {
            return true;
        }

        foreach ($this->inputs as $input) {
            if ($input->hasUnknown()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reports whether a returned value can depend on this call's metadata.
     */
    public function references(string $target): bool
    {
        if ($this->target === $target) {
            return true;
        }

        foreach ($this->inputs as $input) {
            if ($input->references($target)) {
                return true;
            }
        }

        return false;
    }
}
