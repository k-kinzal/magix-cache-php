<?php

declare(strict_types=1);

namespace Tests\Fixture;

/**
 * Represents a reflective object-normalization fixture.
 */
final class KeyDto
{
    /**
     * Creates a recursive-capable key fixture.
     */
    public function __construct(
        public int $id,
        public ?self $child = null,
    ) {
    }

    /**
     * Returns a method-forwarding fixture label.
     */
    public function label(): string
    {
        return 'key:'.$this->id;
    }
}
