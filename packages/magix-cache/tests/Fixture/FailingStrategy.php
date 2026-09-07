<?php

declare(strict_types=1);

namespace Tests\Fixture;

use LogicException;
use Magix\Cache\Cached;
use Magix\Cache\Strategy\CacheOperation;
use Magix\Cache\Strategy\CacheStrategy;
use Magix\Cache\Strategy\NextCacheStrategy;
use Override;
use RuntimeException;

/**
 * A delegate whose origin operation always fails with the given error.
 */
final readonly class FailingStrategy implements CacheStrategy
{
    /**
     * Creates a failing delegate.
     */
    public function __construct(private RuntimeException|LogicException $error)
    {
    }

    /**
     * @return Cached<mixed>|null
     */
    #[Override]
    public function get(CacheOperation $operation, NextCacheStrategy $next): ?Cached
    {
        return null;
    }

    /**
     * @return Cached<mixed>
     * @throws RuntimeException
     * @throws LogicException
     */
    #[Override]
    public function fetch(CacheOperation $operation, NextCacheStrategy $next): Cached
    {
        throw $this->error;
    }

    /**
     * @param Cached<mixed> $result
     */
    #[Override]
    public function set(CacheOperation $operation, Cached $result, NextCacheStrategy $next): void
    {
    }
}
