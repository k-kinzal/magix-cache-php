<?php

declare(strict_types=1);

namespace Tests\Fixture;

use Magix\Cache\Attribute\BypassCacheErrors;
use Magix\Cache\Attribute\Cache;
use Magix\Cache\Attribute\DynamicTtl;
use Magix\Cache\Attribute\StaleIfError;
use Magix\Cache\Cached;
use RuntimeException;

/**
 * Declares class-level defaults with selective method overrides.
 */
#[Cache(ttl: 60, tags: ['declared'])]
#[StaleIfError(maxAge: 60, exceptions: [RuntimeException::class])]
#[DynamicTtl(resolver: FixedTtlResolver::class)]
#[BypassCacheErrors]
final readonly class DeclaredQuery
{
    /**
     * Uses only the class-level declarations.
     *
     * @return Cached<lowercase-string&non-falsy-string>
     */
    public function viaClass(int $id): Cached
    {
        return Cached::of('class:'.$id);
    }

    /**
     * Replaces the class policy and disables the class behaviors.
     *
     * @return Cached<lowercase-string&non-falsy-string>
     */
    #[Cache(ttl: 30)]
    #[StaleIfError(enabled: false)]
    #[DynamicTtl(enabled: false)]
    #[BypassCacheErrors(enabled: false)]
    public function viaMethod(int $id): Cached
    {
        return Cached::of('method:'.$id);
    }

    /**
     * Declares the same policy as viaMethod for fingerprint comparison.
     *
     * @return Cached<lowercase-string&non-falsy-string>
     */
    #[Cache(ttl: 30)]
    #[StaleIfError(enabled: false)]
    #[DynamicTtl(enabled: false)]
    #[BypassCacheErrors(enabled: false)]
    public function sameAsViaMethod(int $id): Cached
    {
        return Cached::of('same:'.$id);
    }
}
