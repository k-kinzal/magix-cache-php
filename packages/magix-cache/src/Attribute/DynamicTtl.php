<?php

declare(strict_types=1);

namespace Magix\Cache\Attribute;

use Attribute;
use InvalidArgumentException;

use function is_a;

use Magix\Cache\Runtime\Extension\CacheTtlResolver;

/**
 * References a registered resolver that overrides the per-result lifetime.
 *
 * The attribute carries only the reference: the resolver instance is
 * registered with the runtime at bootstrap. Its lifetime replaces policy,
 * parameter and inherited expiration; a Strategy may override it afterward. One
 * boundary declares at most one resolver.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class DynamicTtl
{
    /**
     * Declares the dynamic-TTL behavior for a boundary.
     *
     * @param string|null $resolver Class name of a registered CacheTtlResolver.
     * @param bool $enabled Explicit false disables a class-level declaration.
     * @throws InvalidArgumentException when an enabled declaration names no resolver, or the reference is not a CacheTtlResolver
     */
    public function __construct(
        public ?string $resolver = null,
        public bool $enabled = true,
    ) {
        if ($enabled && $resolver === null) {
            throw new InvalidArgumentException('DynamicTtl requires a resolver reference.');
        }

        if ($resolver !== null && !is_a($resolver, CacheTtlResolver::class, true)) {
            throw new InvalidArgumentException('DynamicTtl resolver "'.$resolver.'" must implement CacheTtlResolver.');
        }
    }
}
