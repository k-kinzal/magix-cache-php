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
    public function execute(int $id, int $depth, int $width = 2): Cached
    {
        return $this->cached(function () use ($id, $depth, $width): Cached {
            if ($depth === 0) {
                return Cached::of($this->catalog->total($id));
            }

            $total = $this->execute($id * $width, $depth - 1, $width);
            for ($child = 1; $child < $width; ++$child) {
                $total = $total
                    ->combine2($this->execute($id * $width + $child, $depth - 1, $width))
                    ->map(static fn (int $left, int $right): int => $left + $right);
            }

            return $total;
        });
    }
}
