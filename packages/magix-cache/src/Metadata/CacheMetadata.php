<?php

declare(strict_types=1);

namespace Magix\Cache\Metadata;

use function array_merge;

use InvalidArgumentException;

use function is_finite;
use function min;

/**
 * Immutable cache metadata: meet dependencies, explicitly replace local fields.
 *
 * Dependency bubbling uses meet: it selects
 * the earliest finite expiration, combines cacheability with AND, chooses the
 * stricter visibility, and unions tags and diagnostic reasons. top() is its
 * identity, and every composition is as strict as or stricter than each input.
 * Explicit boundary overrides use the with* methods and may relax a field.
 */
final readonly class CacheMetadata
{
    /**
     * Absolute Unix expiration time, or null when unconstrained.
     */
    public ?float $expiresAt;

    /**
     * Whether the value is eligible for caching.
     */
    public bool $cacheable;

    /**
     * Tags inherited by server and CDN cache entries.
     *
     * @var list<non-empty-string>
     */
    public array $tags;

    /**
     * Storage visibility after dependency bubbling and explicit overrides.
     */
    public Visibility $visibility;

    /**
     * Human-readable reasons for disabling cache storage.
     *
     * @var list<non-empty-string>
     */
    public array $reasons;

    /**
     * Creates an immutable cache constraint set.
     *
     * @param list<string> $tags
     * @param list<string> $reasons
     * @throws InvalidArgumentException when the expiration is not a finite Unix timestamp
     */
    public function __construct(
        ?float $expiresAt = null,
        bool $cacheable = true,
        array $tags = [],
        Visibility $visibility = Visibility::Shared,
        array $reasons = [],
    ) {
        if ($expiresAt !== null && !is_finite($expiresAt)) {
            throw new InvalidArgumentException('Expiration must be a finite Unix timestamp or null.');
        }

        $tokens = new CacheTokenSet();
        $this->expiresAt = $expiresAt;
        $this->cacheable = $cacheable;
        $this->tags = $tokens->tags($tags);
        $this->visibility = $visibility;
        $this->reasons = $tokens->reasons($reasons);
    }

    /**
     * Returns the identity element of the meet: no declared constraints.
     */
    public static function top(): self
    {
        return new self();
    }

    /**
     * Creates metadata with a TTL relative to the supplied time.
     *
     * @param list<string> $tags
     * @throws InvalidArgumentException when the lifetime is negative
     */
    public static function forTtl(int $ttl, float $now, array $tags = []): self
    {
        if ($ttl < 0) {
            throw new InvalidArgumentException('TTL must be zero or greater.');
        }

        return new self(expiresAt: $now + $ttl, tags: $tags);
    }

    /**
     * Creates metadata that forbids storage and records the reason.
     *
     * @param non-empty-string $reason
     */
    public static function uncacheable(string $reason): self
    {
        return new self(cacheable: false, visibility: Visibility::NoStore, reasons: [$reason]);
    }

    /**
     * Combines this value with every supplied constraint set.
     */
    public function meet(self ...$others): self
    {
        $result = $this;

        foreach ($others as $other) {
            $expiresAt = $result->expiresAt === null || $other->expiresAt === null
                ? $result->expiresAt ?? $other->expiresAt
                : min($result->expiresAt, $other->expiresAt);

            $result = new self(
                expiresAt: $expiresAt,
                cacheable: $result->cacheable && $other->cacheable,
                tags: array_merge($result->tags, $other->tags),
                visibility: $result->visibility->meet($other->visibility),
                reasons: array_merge($result->reasons, $other->reasons),
            );
        }

        return $result;
    }

    /**
     * Replaces only expiration; null removes the expiration.
     *
     * @throws InvalidArgumentException when the expiration is not finite
     */
    public function withExpiration(?float $expiresAt): self
    {
        return new self(expiresAt: $expiresAt, cacheable: $this->cacheable, tags: $this->tags, visibility: $this->visibility, reasons: $this->reasons);
    }

    /**
     * Replaces only cacheability, preserving visibility and other fields.
     */
    public function withCacheability(bool $cacheable): self
    {
        return new self(expiresAt: $this->expiresAt, cacheable: $cacheable, tags: $this->tags, visibility: $this->visibility, reasons: $this->reasons);
    }

    /**
     * Replaces only tags; an empty list clears them.
     *
     * @param list<string> $tags
     */
    public function withTags(array $tags): self
    {
        return new self(expiresAt: $this->expiresAt, cacheable: $this->cacheable, tags: $tags, visibility: $this->visibility, reasons: $this->reasons);
    }

    /**
     * Replaces only storage visibility.
     */
    public function withVisibility(Visibility $visibility): self
    {
        return new self(expiresAt: $this->expiresAt, cacheable: $this->cacheable, tags: $this->tags, visibility: $visibility, reasons: $this->reasons);
    }

    /**
     * Replaces only diagnostics; an empty list clears them.
     *
     * @param list<string> $reasons
     */
    public function withReasons(array $reasons): self
    {
        return new self(expiresAt: $this->expiresAt, cacheable: $this->cacheable, tags: $this->tags, visibility: $this->visibility, reasons: $reasons);
    }

    /**
     * Reports value equality after canonicalization, not object identity.
     */
    public function equals(self $other): bool
    {
        return $this->expiresAt === $other->expiresAt
            && $this->cacheable === $other->cacheable
            && $this->tags === $other->tags
            && $this->visibility === $other->visibility
            && $this->reasons === $other->reasons;
    }

    /**
     * Reports whether the value is eligible for server-side storage right now.
     */
    public function isStorable(float $now): bool
    {
        return $this->cacheable
            && $this->visibility !== Visibility::NoStore
            && $this->expiresAt !== null
            && $this->expiresAt > $now;
    }
}
