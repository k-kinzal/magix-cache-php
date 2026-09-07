<?php

declare(strict_types=1);

namespace Tests\Fixture;

/**
 * Declares a create() that does not build a CacheStrategy.
 */
final class BadFactoryStrategy
{
    /**
     * Returns something that is not a strategy.
     */
    public static function create(): string
    {
        return 'not a strategy';
    }
}
