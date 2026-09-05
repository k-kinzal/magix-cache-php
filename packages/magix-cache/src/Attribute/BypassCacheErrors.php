<?php

declare(strict_types=1);

namespace Magix\Cache\Attribute;

use Attribute;
use InvalidArgumentException;

use function is_a;

use Magix\Cache\Runtime\Extension\BackendErrorClassifier;

/**
 * Treats classified cache backend failures as misses or skipped writes.
 *
 * The bypass applies only around cache reads and writes: origin failures and
 * definition errors always propagate. Without an explicit classifier the
 * runtime uses its default, which accepts the failures the bundled adapters
 * and the PSR cache interfaces declare.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class BypassCacheErrors
{
    /**
     * Declares the backend-bypass behavior for a boundary.
     *
     * @param string|null $classifier Class name of a registered BackendErrorClassifier; null uses the runtime default.
     * @param bool $enabled Explicit false disables a class-level declaration.
     * @throws InvalidArgumentException when the reference is not a BackendErrorClassifier
     */
    public function __construct(
        public ?string $classifier = null,
        public bool $enabled = true,
    ) {
        if ($classifier !== null && !is_a($classifier, BackendErrorClassifier::class, true)) {
            throw new InvalidArgumentException('BypassCacheErrors classifier "'.$classifier.'" must implement BackendErrorClassifier.');
        }
    }
}
