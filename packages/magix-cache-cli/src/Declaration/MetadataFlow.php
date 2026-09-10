<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Declaration;

/**
 * Describes returned metadata separately from the calls made while computing a value.
 */
final readonly class MetadataFlow
{
    /**
     * @param 'none'|'unknown'|'call'|'meet'|'choice'|'value'|'wrap'|'preserve'|'collection' $kind
     * @param list<self> $inputs
     * @param string|null $target A fully qualified method identifier for a call.
     * @param self|null $payload The value inside this Cached, when it is known.
     */
    public function __construct(
        public string $kind,
        public array $inputs = [],
        public ?string $target = null,
        public ?self $payload = null,
        public ?string $reason = null,
        public int $line = 0,
    ) {
    }

    /**
     * Retains the operation and understood inputs behind a local limitation.
     *
     * @param list<self> $inputs
     */
    public static function unknown(string $reason, int $line = 0, array $inputs = []): self
    {
        return new self('unknown', $inputs, reason: $reason, line: $line);
    }

    /**
     * Reports opaque syntax even when it occurs in only one alternative.
     */
    public function hasUnknown(): bool
    {
        if ($this->kind === 'unknown') {
            return true;
        }

        foreach ([...$this->inputs, ...($this->payload === null ? [] : [$this->payload])] as $input) {
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

        foreach ([...$this->inputs, ...($this->payload === null ? [] : [$this->payload])] as $input) {
            if ($input->references($target)) {
                return true;
            }
        }

        return false;
    }
}
