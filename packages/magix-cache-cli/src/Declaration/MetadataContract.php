<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Declaration;

/**
 * Holds which non-expiration metadata fields a strategy operation replaces.
 *
 * A field nobody writes keeps whatever composition produced, so an unwritten
 * field stays as precise as its dependencies made it.
 */
final readonly class MetadataContract
{
    /**
     * Creates a metadata contract; every field defaults to preserved.
     */
    public function __construct(
        public bool $visibility = false,
        public bool $tags = false,
        public bool $cacheable = false,
    ) {
    }

    /**
     * Returns the contract of an operation whose writes cannot be described.
     */
    public static function undescribed(): self
    {
        return new self(true, true, true);
    }

    /**
     * Returns the fields either operation writes.
     */
    public function merge(self $other): self
    {
        return new self(
            $this->visibility || $other->visibility,
            $this->tags || $other->tags,
            $this->cacheable || $other->cacheable,
        );
    }

    /**
     * Reports whether the composition leaves every field to its dependencies.
     */
    public function preservesEverything(): bool
    {
        return !$this->visibility && !$this->tags && !$this->cacheable;
    }
}
