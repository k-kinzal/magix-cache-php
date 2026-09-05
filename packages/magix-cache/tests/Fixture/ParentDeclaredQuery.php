<?php

declare(strict_types=1);

namespace Tests\Fixture;

use Magix\Cache\Attribute\Cache;
use Magix\Cache\Cached;

/**
 * Declares a parent-class default that must never be inherited implicitly.
 */
#[Cache(ttl: 40, version: 'parent')]
abstract class ParentDeclaredQuery
{
    /**
     * Carries its own method-level declaration into subclasses.
     *
     * @return Cached<lowercase-string&non-falsy-string>
     */
    #[Cache(ttl: 25, version: 'inherited')]
    public function inherited(int $id): Cached
    {
        return Cached::of('inherited:'.$id);
    }

    /**
     * Relies on a class-level declaration that subclasses do not inherit.
     *
     * @return Cached<lowercase-string&non-falsy-string>
     */
    public function classOnly(int $id): Cached
    {
        return Cached::of('class-only:'.$id);
    }
}
