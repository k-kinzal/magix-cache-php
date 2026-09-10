<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Graph\Analysis;

use JsonSerializable;
use Magix\Cache\Metadata\Visibility;
use Override;

/**
 * Carries only limitations affecting returned fields, never unrelated call subtrees.
 */
final readonly class MetadataAnalysis implements JsonSerializable
{
    /**
     * @param array<string, AnalysisCause> $ttl
     * @param array<string, AnalysisCause> $visibility
     * @param array<string, AnalysisCause> $tags
     * @param MetadataReference<Visibility>|null $visibilityReference
     * @param MetadataReference<list<string>>|null $tagsReference
     */
    public function __construct(public array $ttl = [], public array $visibility = [], public array $tags = [], public ?TtlReference $ttlReference = null, public bool $referenceAmbiguous = false, public ?MetadataReference $visibilityReference = null, public ?MetadataReference $tagsReference = null)
    {
    }

    /**
     * A limitation starts on exactly the fields the operation can affect.
     *
     * @param list<'ttl'|'visibility'|'tags'> $fields
     */
    public function withCause(AnalysisCause $cause, array $fields = ['ttl', 'visibility', 'tags']): self
    {
        return new self(
            in_array('ttl', $fields, true) ? [...$this->ttl, $cause->id => $cause] : $this->ttl,
            in_array('visibility', $fields, true) ? [...$this->visibility, $cause->id => $cause] : $this->visibility,
            in_array('tags', $fields, true) ? [...$this->tags, $cause->id => $cause] : $this->tags,
            $this->ttlReference,
            $this->referenceAmbiguous,
            $this->visibilityReference,
            $this->tagsReference,
        );
    }

    /**
     * Combines provenance without turning numeric references into constraints.
     */
    public function merge(self $other): self
    {
        $reference = $this->ttlReference ?? $other->ttlReference;

        $ambiguous = $this->referenceAmbiguous || $other->referenceAmbiguous;

        if ($this->ttlReference !== null && $other->ttlReference !== null && $this->ttlReference->seconds !== $other->ttlReference->seconds) {
            $ambiguous = true;
        }

        return new self(
            [...$this->ttl, ...$other->ttl],
            [...$this->visibility, ...$other->visibility],
            [...$this->tags, ...$other->tags],
            $ambiguous ? null : $reference,
            $ambiguous,
            $this->visibilityReference === null ? $other->visibilityReference : ($other->visibilityReference === null ? $this->visibilityReference : $this->visibilityReference->merge($other->visibilityReference)),
            $this->tagsReference === null ? $other->tagsReference : ($other->tagsReference === null ? $this->tagsReference : $this->tagsReference->merge($other->tagsReference)),
        );
    }

    /**
     * Explicit replacements remove the previous field's uncertainty and provenance.
     *
     * @param 'ttl'|'visibility'|'tags' ...$fields
     */
    public function without(string ...$fields): self
    {
        return new self(
            in_array('ttl', $fields, true) ? [] : $this->ttl,
            in_array('visibility', $fields, true) ? [] : $this->visibility,
            in_array('tags', $fields, true) ? [] : $this->tags,
            in_array('ttl', $fields, true) ? null : $this->ttlReference,
            !in_array('ttl', $fields, true) && $this->referenceAmbiguous,
            in_array('visibility', $fields, true) ? null : $this->visibilityReference,
            in_array('tags', $fields, true) ? null : $this->tagsReference,
        );
    }

    /**
     * Attaches a separately justified reference for compact human reports.
     */
    public function withReference(?TtlReference $reference): self
    {
        return new self($this->ttl, $this->visibility, $this->tags, $reference, visibilityReference: $this->visibilityReference, tagsReference: $this->tagsReference);
    }

    /**
     * Attaches metadata references without changing effective fields or their causes.
     *
     * @param MetadataReference<Visibility>|null $visibility
     * @param MetadataReference<list<string>>|null $tags
     */
    public function withMetadataReferences(?MetadataReference $visibility, ?MetadataReference $tags): self
    {
        return new self($this->ttl, $this->visibility, $this->tags, $this->ttlReference, $this->referenceAmbiguous, $visibility, $tags);
    }

    /**
     * Compares stable identities and reference values rather than object instances.
     */
    public function equals(self $other): bool
    {
        return array_keys($this->ttl) === array_keys($other->ttl)
            && array_keys($this->visibility) === array_keys($other->visibility)
            && array_keys($this->tags) === array_keys($other->tags)
            && $this->ttlReference?->jsonSerialize() === $other->ttlReference?->jsonSerialize()
            && $this->referenceAmbiguous === $other->referenceAmbiguous
            && $this->visibilityReference?->jsonSerialize() === $other->visibilityReference?->jsonSerialize()
            && $this->tagsReference?->jsonSerialize() === $other->tagsReference?->jsonSerialize();
    }

    /**
     * @return array<string, AnalysisCause> Unique causes affecting any returned field.
     */
    public function causes(): array
    {
        return [...$this->ttl, ...$this->visibility, ...$this->tags];
    }

    /**
     * @return array{ttl: list<string>, visibility: list<string>, tags: list<string>, ttlReference: TtlReference|null, visibilityReference: MetadataReference<Visibility>|null, tagsReference: MetadataReference<list<string>>|null}
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return ['ttl' => array_keys($this->ttl), 'visibility' => array_keys($this->visibility), 'tags' => array_keys($this->tags), 'ttlReference' => $this->ttlReference, 'visibilityReference' => $this->visibilityReference, 'tagsReference' => $this->tagsReference];
    }
}
