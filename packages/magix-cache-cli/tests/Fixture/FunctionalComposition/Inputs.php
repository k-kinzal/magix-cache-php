<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture\FunctionalComposition;

use Magix\Cache\Attribute\Cache;
use Magix\Cache\Cacheable;
use Magix\Cache\Cached;
use Magix\Cache\Metadata\Visibility;

/**
 * Declares independent constraints for functional composition analysis.
 */
final class Inputs
{
    use Cacheable;

    /**
     * Returns a product identifier.
     *
     * @return Cached<int>
     */
    #[Cache(ttl: 20, tags: ['product'])]
    public function product(int $id): Cached
    {
        return $this->cached(static fn (): Cached => Cached::of($id));
    }

    /**
     * Returns viewer-specific display text.
     *
     * @return Cached<string>
     */
    #[Cache(ttl: 60, tags: ['viewer'], visibility: Visibility::Private)]
    public function viewer(int $id): Cached
    {
        return $this->cached(static fn (): Cached => Cached::of('viewer:'.$id));
    }
}
