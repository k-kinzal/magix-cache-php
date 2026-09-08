<?php

declare(strict_types=1);

namespace Bench\AllMiss;

use Magix\Cache\Attribute\Cache;
use Magix\Cache\Cacheable;
use Magix\Cache\Cached;

/**
 * Wraps every node in the read-model tree in the public declarative cache API.
 */
final class MagixQuery
{
    use Cacheable;

    /**
     * Supplies the same catalog used by the plain query.
     */
    public function __construct(private readonly Catalog $catalog)
    {
    }

    /**
     * Evaluates the tree while meeting child metadata at every parent.
     *
     * @return Cached<int>
     */
    #[Cache(ttl: 60)]
    public function execute(int $id, int $depth): Cached
    {
        return $this->cached(function () use ($id, $depth): Cached {
            if ($depth === 0) {
                return Cached::of($this->catalog->total($id));
            }

            return $this->execute($id * 2, $depth - 1)
                ->combine2($this->execute($id * 2 + 1, $depth - 1))
                ->map(static fn (int $left, int $right): int => $left + $right);
        });
    }
}
