<?php

declare(strict_types=1);

namespace Tests\Fixture;

use LogicException;
use Magix\Cache\Strategy\CacheOperation;
use Magix\Cache\Strategy\CacheRead;
use Magix\Cache\Strategy\CacheStrategy;
use Magix\Cache\Strategy\CacheWrite;
use Magix\Cache\Strategy\NextCacheStrategy;
use Magix\Cache\Strategy\OriginFailure;
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
     * @return CacheRead<mixed>|null
     */
    #[Override]
    public function get(CacheOperation $operation, NextCacheStrategy $next): ?CacheRead
    {
        return null;
    }

    /**
     * @return OriginFailure
     * @throws LogicException
     */
    #[Override]
    public function fetch(CacheOperation $operation, NextCacheStrategy $next): OriginFailure
    {
        if ($this->error instanceof RuntimeException) {
            return new OriginFailure($this->error);
        }

        throw $this->error;
    }

    /**
     * @param CacheWrite<mixed> $result
     */
    #[Override]
    public function set(CacheOperation $operation, CacheWrite $result, NextCacheStrategy $next): void
    {
    }
}
