<?php

declare(strict_types=1);

namespace Magix\Cache\Cache;

use InvalidArgumentException;

use function is_finite;

use Magix\Cache\Metadata\CacheMetadata;
use Magix\Cache\Metadata\Visibility;

/**
 * Carries one internal storage value with its metadata and physical retention.
 *
 * expiresAt bounds fresh reuse; retainedUntil bounds physical retention for
 * stale handling. Extending retention never changes the expiration. The
 * format version guards stored payloads across incompatible layout changes.
 *
 * @template-covariant T
 * @internal
 */
final readonly class CacheEntry
{
    /**
     * Storage format version persisted with every entry.
     */
    public const int FORMAT_VERSION = 1;

    /** @var T */
    private mixed $value;

    /**
     * Constraints the stored value carries back into composition.
     */
    public CacheMetadata $metadata;

    /**
     * Finite absolute Unix expiration time for fresh reuse.
     */
    public float $expiresAt;

    /**
     * Finite absolute time until which storage may retain this entry.
     */
    public float $retainedUntil;

    /**
     * Format version this entry was written with.
     */
    public int $formatVersion;

    /**
     * Creates an internal entry from storable metadata.
     *
     * @param T $value
     * @throws InvalidArgumentException when the metadata lacks a finite expiration or forbids storage, or the retention precedes the expiration
     */
    public function __construct(
        mixed $value,
        CacheMetadata $metadata,
        ?float $retainedUntil = null,
    ) {
        $expiresAt = $metadata->expiresAt
            ?? throw new InvalidArgumentException('Cache entry metadata must carry a finite expiration.');

        if (!$metadata->cacheable || $metadata->visibility === Visibility::NoStore) {
            throw new InvalidArgumentException('Cache entry metadata must permit storage.');
        }

        $retainedUntil ??= $expiresAt;

        if (!is_finite($retainedUntil) || $retainedUntil < $expiresAt) {
            throw new InvalidArgumentException('Cache entry retention must be finite and no earlier than expiration.');
        }

        $this->value = $value;
        $this->metadata = $metadata;
        $this->expiresAt = $expiresAt;
        $this->retainedUntil = $retainedUntil;
        $this->formatVersion = self::FORMAT_VERSION;
    }

    /**
     * Returns the internally stored value.
     *
     * @return T
     */
    public function value(): mixed
    {
        return $this->value;
    }

    /**
     * Returns this entry with a different physical retention deadline.
     *
     * @return self<T>
     */
    public function withRetainedUntil(float $retainedUntil): self
    {
        return new self(
            value: $this->value,
            metadata: $this->metadata,
            retainedUntil: $retainedUntil,
        );
    }
}
