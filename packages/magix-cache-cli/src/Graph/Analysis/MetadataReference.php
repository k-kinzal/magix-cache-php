<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Graph\Analysis;

use JsonSerializable;
use Magix\Cache\Cli\Graph\CacheVariant;
use Magix\Cache\Metadata\Visibility;
use Override;

/**
 * Retains metadata observed before an opaque operation, separately from constraints.
 *
 * @template-covariant T of Visibility|list<string>
 */
final readonly class MetadataReference implements JsonSerializable
{
    /**
     * @param non-empty-list<T> $candidates Conflicting observations remain available but cannot choose a display value.
     * @param list<string> $sources
     */
    public function __construct(public array $candidates, public array $sources, public string $basis = 'input metadata before an unverified operation')
    {
    }

    /**
     * @param list<CacheVariant> $variants
     * @return self<Visibility>|null
     */
    public static function fromVisibility(array $variants): ?self
    {
        $reference = null;

        foreach ($variants as $variant) {
            $effect = $variant->effect;
            $candidate = $variant->cached && !$effect->visibilityUnknown
                ? new self([$effect->visibility], $variant->sources)
                : $effect->analysis->visibilityReference;

            if ($candidate !== null) {
                $reference = $reference === null ? $candidate : $reference->merge($candidate);
            }
        }

        return $reference;
    }

    /**
     * Keeps observed tag names even when an input also has an unknown remainder.
     *
     * @param list<CacheVariant> $variants
     * @return self<list<string>>|null
     */
    public static function fromTags(array $variants): ?self
    {
        $reference = null;

        foreach ($variants as $variant) {
            $effect = $variant->effect;
            $previous = $effect->analysis->tagsReference;

            if (!$variant->cached && $previous === null) {
                continue;
            }

            $candidates = $effect->tagsUnknown ? ($previous->candidates ?? ($effect->tags === [] ? [] : [[]])) : [$effect->tags];

            foreach ($candidates as $tags) {
                $tags = array_values(array_unique([...$effect->tags, ...$tags]));
                sort($tags);
                $candidate = new self([$tags], array_values(array_unique([...$variant->sources, ...($previous->sources ?? [])])));
                $reference = $reference === null ? $candidate : $reference->merge($candidate);
            }
        }

        return $reference;
    }

    /**
     * Exposes a single reference only when every observation agrees.
     *
     * @return T|null
     */
    public function value(): Visibility|array|null
    {
        return count($this->candidates) === 1 ? $this->candidates[0] : null;
    }

    /**
     * Conflicts survive subsequent merges instead of reviving an arbitrary candidate.
     *
     * @template U of Visibility|list<string>
     * @param self<U> $other
     * @return self<T|U>
     */
    public function merge(self $other): self
    {
        $candidates = $this->candidates;

        foreach ($other->candidates as $candidate) {
            if (!in_array($candidate, $candidates, true)) {
                $candidates[] = $candidate;
            }
        }

        return new self(
            $candidates,
            array_values(array_unique([...$this->sources, ...$other->sources])),
            $this->basis === $other->basis ? $this->basis : 'metadata from multiple unverified operations',
        );
    }

    /**
     * @return array{value: string|list<string>|null, candidates: non-empty-list<string|list<string>>, sources: list<string>, basis: string}
     */
    #[Override]
    public function jsonSerialize(): array
    {
        $value = $this->value();

        return [
            'value' => $value instanceof Visibility ? strtolower($value->name) : $value,
            'candidates' => array_map(static fn (Visibility|array $candidate): string|array => $candidate instanceof Visibility ? strtolower($candidate->name) : $candidate, $this->candidates),
            'sources' => $this->sources,
            'basis' => $this->basis,
        ];
    }
}
