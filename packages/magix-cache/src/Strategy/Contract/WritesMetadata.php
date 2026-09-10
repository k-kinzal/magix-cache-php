<?php

declare(strict_types=1);

namespace Magix\Cache\Strategy\Contract;

use Attribute;

/**
 * Declares which non-expiration metadata fields fetch() replaces.
 *
 * OriginResult::withMetadata() may replace visibility, tags and cacheability,
 * which no lifetime contract describes. This attribute states which of them an
 * operation writes, so a strategy that only adjusts expiration keeps the
 * composed metadata of its dependencies precise instead of erasing it.
 *
 * Omitting the attribute declares that the operation preserves every field,
 * the same promise an omitted Ttl makes about expiration. Supplying it with no
 * arguments declares that the operation replaces all of them with values the
 * declaration cannot describe. Naming fields declares exactly those.
 *
 * The declaration covers the normal origin path only. As with every contract
 * here, the implementation is free as long as it honors what it declared: an
 * operation that writes a field it did not name breaks its own contract.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class WritesMetadata
{
    /**
     * Whether the operation replaces the visibility of the result.
     */
    public bool $visibility;

    /**
     * Whether the operation replaces the tags of the result.
     */
    public bool $tags;

    /**
     * Whether the operation replaces the cacheability of the result.
     */
    public bool $cacheable;

    /**
     * Declares the metadata fields one operation replaces.
     *
     * @param bool|null $visibility Null names no field; every field is null only when no argument was supplied.
     * @param bool|null $tags Null names no field.
     * @param bool|null $cacheable Null names no field.
     */
    public function __construct(?bool $visibility = null, ?bool $tags = null, ?bool $cacheable = null)
    {
        $undescribed = $visibility === null && $tags === null && $cacheable === null;

        $this->visibility = $visibility ?? $undescribed;
        $this->tags = $tags ?? $undescribed;
        $this->cacheable = $cacheable ?? $undescribed;
    }
}
