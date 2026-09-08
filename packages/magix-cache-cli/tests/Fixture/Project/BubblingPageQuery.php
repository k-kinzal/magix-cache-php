<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture\Project;

use Magix\Cache\Attribute\Cache;
use Magix\Cache\Cacheable;
use Magix\Cache\Cached;
use Magix\Cache\Runtime\Policy\Ttl;

/**
 * Carries a child page's constraints through implicit and explicit automatic policies.
 */
#[Cache]
final class BubblingPageQuery
{
    use Cacheable;

    /**
     * Creates the bubbling page query.
     */
    public function __construct(private readonly ProductPageQuery $page)
    {
    }

    /**
     * Returns the child page under the argument-free class policy.
     *
     * @return Cached<int>
     */
    public function execute(int $productId, int $viewerId): Cached
    {
        return $this->cached(fn (): Cached => $this->page->execute($productId, $viewerId));
    }

    /**
     * Returns the same page under an explicit automatic method policy.
     *
     * @return Cached<int>
     */
    #[Cache(ttl: Ttl::Auto)]
    public function explicit(int $productId, int $viewerId): Cached
    {
        return $this->cached(fn (): Cached => $this->execute($productId, $viewerId));
    }
}
