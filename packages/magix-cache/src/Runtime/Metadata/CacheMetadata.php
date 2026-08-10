<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime\Metadata;

use function array_merge;

use InvalidArgumentException;

use function is_finite;
use function is_int;

use LogicException;
use Magix\Cache\CachePolicy;
use Magix\Cache\Runtime\Policy\Ttl;

use function min;

/**
 * Immutable cache constraints that compose by selecting the stricter value.
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
     * The most restrictive storage visibility observed so far.
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
     * Returns the unconstrained identity element for metadata composition.
     */
    public static function top(): self
    {
        return new self();
    }

    /**
     * Creates metadata with a TTL relative to the supplied time.
     *
     * @param list<string> $tags
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
     * Combines this value with every supplied dependency using meet semantics.
     */
    public function merge(self ...$others): self
    {
        $result = $this;
        $meet = new ConstraintMeet();

        foreach ($others as $other) {
            $result = new self(
                expiresAt: $meet->expiration($result->expiresAt, $other->expiresAt),
                cacheable: $result->cacheable && $other->cacheable,
                tags: array_merge($result->tags, $other->tags),
                visibility: $result->visibility->meet($other->visibility),
                reasons: array_merge($result->reasons, $other->reasons),
            );
        }

        return $result;
    }

    /**
     * Applies a cache boundary's declared policy to these constraints.
     */
    public function applyPolicy(CachePolicy $policy, float $now): self
    {
        $metadata = $this
            ->withTags($policy->tags)
            ->withVisibility($policy->visibility);

        if (is_int($policy->ttl)) {
            $ownExpiration = $now + $policy->ttl;
            $expiration = $policy->clamp && $metadata->expiresAt !== null
                ? min($metadata->expiresAt, $ownExpiration)
                : $ownExpiration;

            return $metadata->withExpiresAt($expiration);
        }

        if ($metadata->expiresAt === null) {
            throw new LogicException($policy->ttl->name.' requires a finite dependency or upstream expiration.');
        }

        if ($policy->ttl === Ttl::FromUpstream) {
            $maximum = $policy->maxTtl ?? throw new LogicException('Ttl::FromUpstream requires maxTtl.');

            return $metadata->withExpiresAt(min($metadata->expiresAt, $now + $maximum));
        }

        return $metadata;
    }

    /**
     * Returns a copy with an exact absolute expiration.
     */
    public function withExpiresAt(float $expiresAt): self
    {
        return new self(
            expiresAt: $expiresAt,
            cacheable: $this->cacheable,
            tags: $this->tags,
            visibility: $this->visibility,
            reasons: $this->reasons,
        );
    }

    /**
     * Returns a copy with additional tags.
     *
     * @param list<string> $tags
     */
    public function withTags(array $tags): self
    {
        return new self(
            expiresAt: $this->expiresAt,
            cacheable: $this->cacheable,
            tags: array_merge($this->tags, $tags),
            visibility: $this->visibility,
            reasons: $this->reasons,
        );
    }

    /**
     * Returns a copy restricted to the supplied visibility.
     */
    public function withVisibility(Visibility $visibility): self
    {
        return new self(
            expiresAt: $this->expiresAt,
            cacheable: $this->cacheable,
            tags: $this->tags,
            visibility: $this->visibility->meet($visibility),
            reasons: $this->reasons,
        );
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
