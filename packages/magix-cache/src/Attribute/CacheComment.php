<?php

declare(strict_types=1);

namespace Magix\Cache\Attribute;

use Attribute;

/**
 * Adds a human-written note to analyze output without affecting caching or keys.
 *
 * A method comment replaces the class default, including an empty string that
 * hides it. Parent-class comments are not inherited. The CLI reads string
 * literals without executing application code.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class CacheComment
{
    /**
     * Creates an analysis-only comment for a boundary or uncached entry point.
     */
    public function __construct(public string $comment)
    {
    }
}
