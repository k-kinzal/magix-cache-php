<?php

declare(strict_types=1);

namespace Tests\Fixture;

use Magix\Cache\Attribute\Cache;
use Magix\Cache\Attribute\CacheComment;
use Magix\Cache\Cacheable;
use Magix\Cache\Cached;

/**
 * Declares identical cache policies with different analysis-only comments.
 */
#[Cache(ttl: 30)]
#[CacheComment('Migration in progress')]
final class CommentedQuery
{
    use Cacheable;

    /**
     * @return Cached<int>
     */
    public function viaClass(): Cached
    {
        return $this->cached(static fn (): Cached => Cached::of(1));
    }

    /**
     * @return Cached<int>
     */
    #[CacheComment(comment: 'Bubbling enabled for comparison')]
    public function viaMethod(): Cached
    {
        return $this->cached(static fn (): Cached => Cached::of(1));
    }

    /**
     * @return Cached<int>
     */
    #[CacheComment('')]
    public function hidden(): Cached
    {
        return $this->cached(static fn (): Cached => Cached::of(1));
    }
}
