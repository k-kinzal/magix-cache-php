<?php

declare(strict_types=1);

namespace Magix\Cache\Strategy;

use Magix\Cache\Cached;

/**
 * Answers a fetch without applying origin constraints or writing it again.
 *
 * @template-covariant T
 */
final readonly class CacheAnswer
{
    /**
     * Returns an already evaluated answer with its original constraints.
     *
     * @param Cached<T> $cached
     * @param string|null $event Optional diagnostic name; runtimes report recognized events.
     */
    public function __construct(public Cached $cached, public ?string $event = null)
    {
    }
}
