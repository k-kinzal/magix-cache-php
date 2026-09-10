<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture\Display;

use Magix\Cache\Attribute\Cache;
use Magix\Cache\Cacheable;
use Magix\Cache\Cached;

/**
 * Composes a lookup whose returned metadata the analyzer cannot follow.
 */
final class UnverifiedPageQuery
{
    use Cacheable;

    /**
     * Creates a page over an unresolvable lookup.
     */
    public function __construct(private UnresolvedLookup $lookup)
    {
    }

    /**
     * @return Cached<array<string, int>>
     */
    #[Cache(ttl: 120)]
    public function execute(int $id): Cached
    {
        return $this->cached(fn (): Cached => $this->lookup->get($id));
    }
}
