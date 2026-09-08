<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture\Display;

use Magix\Cache\Attribute\Cache;
use Magix\Cache\Cacheable;
use Magix\Cache\Cached;
use Magix\Cache\Metadata\Visibility;
use Tests\Package\Cli\Fixture\Project\InventoryQuery;

/**
 * Keeps a stored child below parents whose own results cannot be stored.
 */
final class NoStorePageQuery
{
    use Cacheable;

    /**
     * Receives the stored child used beneath non-storable boundaries.
     */
    public function __construct(private readonly InventoryQuery $inventory)
    {
    }

    /**
     * @return Cached<int>
     */
    #[Cache]
    public function execute(int $id): Cached
    {
        return $this->cached(fn (): Cached => $this->disabled($id));
    }

    /**
     * @return Cached<int>
     */
    #[Cache(ttl: 10, visibility: Visibility::NoStore)]
    public function disabled(int $id): Cached
    {
        return $this->cached(fn (): Cached => $this->inventory->execute($id));
    }

    /**
     * @return Cached<int>
     */
    #[Cache(ttl: 0)]
    public function expired(int $id): Cached
    {
        return $this->cached(fn (): Cached => $this->inventory->execute($id));
    }
}
