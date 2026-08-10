<?php

declare(strict_types=1);

namespace Magix\Cache\Cache;

use InvalidArgumentException;

use function is_finite;

use Magix\Cache\Runtime\Metadata\CacheTokenSet;
use Magix\Cache\Runtime\Metadata\Visibility;

/**
 * Carries one internal storage value with concrete cache metadata.
 *
 * @template-covariant T
 * @internal
 */
final readonly class CacheEntry
{
    /** @var T */
    private mixed $value;

    /**
     * Finite absolute Unix expiration time.
     */
    public float $expiresAt;

    /**
     * Finite absolute time until which storage may retain this entry.
     */
    public float $retainedUntil;

    /**
     * Tags persisted with the internal entry.
     *
     * @var list<non-empty-string>
     */
    public array $tags;

    /**
     * Storage visibility persisted with the internal entry.
     */
    public Visibility $visibility;

    /**
     * Diagnostic reasons persisted with the internal entry.
     *
     * @var list<non-empty-string>
     */
    public array $reasons;

    /**
     * Creates an internal entry from concrete storage metadata.
     *
     * @param T $value
     * @param list<string> $tags
     * @param list<string> $reasons
     */
    public function __construct(
        mixed $value,
        float $expiresAt,
        array $tags = [],
        Visibility $visibility = Visibility::Shared,
        array $reasons = [],
        ?float $retainedUntil = null,
    ) {
        if (!is_finite($expiresAt)) {
            throw new InvalidArgumentException('Cache entry expiration must be a finite Unix timestamp.');
        }

        $retainedUntil ??= $expiresAt;

        if (!is_finite($retainedUntil) || $retainedUntil < $expiresAt) {
            throw new InvalidArgumentException('Cache entry retention must be finite and no earlier than expiration.');
        }

        if ($visibility === Visibility::NoStore) {
            throw new InvalidArgumentException('Cache entry visibility must permit storage.');
        }

        $tokens = new CacheTokenSet();
        $this->value = $value;
        $this->expiresAt = $expiresAt;
        $this->retainedUntil = $retainedUntil;
        $this->tags = $tokens->tags($tags);
        $this->visibility = $visibility;
        $this->reasons = $tokens->reasons($reasons);
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
            expiresAt: $this->expiresAt,
            tags: $this->tags,
            visibility: $this->visibility,
            reasons: $this->reasons,
            retainedUntil: $retainedUntil,
        );
    }
}
