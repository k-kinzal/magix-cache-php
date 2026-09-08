<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture\Comments;

use Magix\Cache\Attribute\Cache;
use Magix\Cache\Attribute\CacheComment as Memo;
use Magix\Cache\Cacheable;
use Magix\Cache\Cached;
use Magix\Cache\Metadata\Visibility;

/**
 * Describes both directions of a cache migration with analysis-only notes.
 */
#[Cache(ttl: 60)]
#[Memo('移行中の既定メモ')]
class MigrationQuery
{
    use Cacheable;

    /**
     * An application constant that static analysis must not evaluate.
     */
    public const string NOTE = 'This constant is deliberately not evaluated by analyze.';

    /**
     * @return list<Cached<int>>
     */
    #[Memo('移行状況を確認する入口')]
    public function show(): array
    {
        return [$this->stopped(), $this->bubbling()];
    }

    /**
     * @return Cached<int>
     */
    #[Cache(visibility: Visibility::NoStore)]
    #[Memo('既存準拠で NoStore。本来は Bubbling を止める必要なし')]
    public function stopped(): Cached
    {
        return $this->cached(fn (): Cached => $this->origin());
    }

    /**
     * @return Cached<int>
     */
    #[Cache]
    #[Memo(comment: "既存は NoStore。Bubbling を有効にして検証中\n<info>比較</info> & \"確認\" #35;")]
    public function bubbling(): Cached
    {
        return $this->cached(fn (): Cached => $this->origin());
    }

    /**
     * @return Cached<int>
     */
    public function origin(): Cached
    {
        return $this->cached(static fn (): Cached => Cached::of(1));
    }

    /**
     * @return Cached<int>
     */
    #[Memo('')]
    public function hidden(): Cached
    {
        return $this->cached(static fn (): Cached => Cached::of(1));
    }

    /**
     * @return Cached<int>
     */
    #[Memo(self::NOTE)]
    public function unresolved(): Cached
    {
        return $this->cached(static fn (): Cached => Cached::of(1));
    }
}
