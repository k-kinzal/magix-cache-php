<?php

declare(strict_types=1);

namespace Tests\Fixture;

use Magix\Cache\Cached;

/**
 * Represents a query without a cache declaration.
 */
final readonly class PlainQuery
{
    /**
     * Returns an uncached fixture result.
     *
     * @return Cached<string>
     */
    public function execute(): Cached
    {
        return Cached::of('plain');
    }
}
