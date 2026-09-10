<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Graph;

/**
 * Keeps one possible metadata result together with the dependencies selecting it.
 */
final readonly class CacheVariant
{
    /**
     * @param array<string, int> $selections Choices already made by this value, shared by its aliases.
     * @param bool $cached Whether the returned object is known to carry Cached metadata.
     * @param list<string> $sources Dependencies composed on this return path.
     * @param bool $analyzed False when syntax or traversal prevents following the returned metadata.
     */
    public function __construct(public CacheEffect $effect, public array $sources = [], public bool $analyzed = true, public ?string $ttlSource = null, public ?string $visibilitySource = null, public array $selections = [], public bool $cached = false)
    {
    }

    /**
     * Compares observable metadata and path selections when deduplicating outcomes.
     */
    public function equals(self $other): bool
    {
        $a = $this->effect;
        $b = $other->effect;

        return $this->sources === $other->sources && $this->analyzed === $other->analyzed
            && $this->ttlSource === $other->ttlSource && $this->visibilitySource === $other->visibilitySource
            && $this->selections === $other->selections && $this->cached === $other->cached
            && $a->ttl->equals($b->ttl) && $a->visibility === $b->visibility && $a->tags === $b->tags
            && $a->storable === $b->storable && $a->visibilityReason === $b->visibilityReason
            && $a->problems === $b->problems && $a->strategy === $b->strategy
            && $a->visibilityUnknown === $b->visibilityUnknown && $a->tagsUnknown === $b->tagsUnknown
            && $a->localOverrides === $b->localOverrides && $a->expirationConstraints === $b->expirationConstraints
            && $a->analysis->equals($b->analysis);
    }

    /**
     * Adds a selection without changing the correlated metadata fields.
     */
    public function select(string $choice, int $index, bool $replace = false): self
    {
        return new self(
            $this->effect,
            $this->sources,
            $this->analyzed,
            $this->ttlSource,
            $this->visibilitySource,
            [$choice => $index, ...($replace ? [] : $this->selections)],
            $this->cached
        );
    }

    /**
     * Reports whether two uses of the same returned value can coexist.
     */
    public function compatible(self $other): bool
    {
        foreach ($this->selections as $choice => $index) {
            if (isset($other->selections[$choice]) && $other->selections[$choice] !== $index) {
                return false;
            }
        }

        return true;
    }

    /**
     * Exposes this candidate as a parent constraint, retaining correlated metadata fields.
     */
    public function constraint(): DependencyConstraint
    {
        $source = $this->sources === [] ? null : implode(' + ', $this->sources);

        return new DependencyConstraint(
            $this->effect->ttl,
            $this->ttlSource ?? $source,
            $this->effect->visibility,
            $this->visibilitySource ?? $source,
            $this->effect->tags,
            $this->effect->visibilityUnknown,
            $this->effect->tagsUnknown,
            $this->sources !== [],
            $this->effect->expirationConstraints,
            $this->effect->analysis,
        );
    }
}
