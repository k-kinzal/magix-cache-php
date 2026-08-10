<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime\Operation;

use Magix\Cache\Cache\CacheEntry;

/**
 * Describes one cache write as it passes through a strategy chain.
 *
 * @template-covariant T
 */
final readonly class CacheSet
{
    /**
     * @param CacheEntry<T> $entry
     */
    public function __construct(
        public string $key,
        private CacheEntry $entry,
    ) {
    }

    /**
     * Returns this write with a transformed storage key.
     *
     * @return self<T>
     */
    public function withKey(string $key): self
    {
        return new self($key, $this->entry);
    }

    /**
     * Returns the complete entry being written.
     *
     * @return CacheEntry<T>
     */
    public function entry(): CacheEntry
    {
        return $this->entry;
    }

    /**
     * Returns this write with a transformed complete entry.
     *
     * @template V
     * @param CacheEntry<V> $entry
     * @return self<V>
     */
    public function withEntry(CacheEntry $entry): self
    {
        return new self($this->key, $entry);
    }
}
