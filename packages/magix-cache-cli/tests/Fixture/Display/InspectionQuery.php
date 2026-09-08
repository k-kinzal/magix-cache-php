<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture\Display;

use Magix\Cache\Attribute\Cache;
use Magix\Cache\Cacheable;
use Magix\Cache\Cached;
use Tests\Package\Cli\Fixture\Project\ViewerQuery;

/**
 * Has ordinary callees whose metadata is not part of the returned composition.
 */
final class InspectionQuery
{
    use Cacheable;

    /**
     * Creates an inspection boundary with cached and ordinary dependencies.
     */
    public function __construct(private ViewerQuery $viewer, private InventoryLookup $inventory)
    {
    }

    /**
     * @return Cached<int>
     */
    #[Cache(ttl: 120)]
    public function execute(int $id): Cached
    {
        return $this->cached(fn (): Cached => $this->viewer->execute($id)->map(
            fn (int $viewer): int => $viewer + $this->inventory->get($id) + $this->offset(),
        ));
    }

    /**
     * Returns a constant without calling another method.
     */
    public function offset(): int
    {
        return 1;
    }
}
