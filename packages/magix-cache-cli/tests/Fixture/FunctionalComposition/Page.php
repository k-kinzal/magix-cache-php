<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture\FunctionalComposition;

use Magix\Cache\Attribute\Cache;
use Magix\Cache\Cacheable;
use Magix\Cache\Cached;
use Magix\Cache\Runtime\Policy\Ttl;

/**
 * Exercises dependency discovery through each functional composition form.
 */
#[Cache(ttl: Ttl::Auto, tags: ['page'])]
final class Page
{
    use Cacheable;

    /**
     * Creates a page with two constrained inputs.
     */
    public function __construct(private readonly Inputs $inputs)
    {
    }

    /**
     * @return Cached<string>
     */
    public function flatten(int $id): Cached
    {
        return $this->cached(fn (): Cached => $this->inputs->product($id)
            ->map(fn (int $product): Cached => $this->inputs->viewer($product))
            ->flatten());
    }

    /**
     * @return Cached<array{int, string}>
     */
    public function zip(int $id): Cached
    {
        return $this->cached(fn (): Cached => $this->inputs->product($id)->zip($this->inputs->viewer($id)));
    }

    /**
     * @return Cached<int>
     */
    public function unzip(int $id): Cached
    {
        return $this->cached(fn (): Cached => $this->inputs->product($id)
            ->zip($this->inputs->viewer($id))->unzip()[0]);
    }

    /**
     * @return Cached<array<int, int|string>>
     */
    public function sequence(int $id): Cached
    {
        return $this->cached(fn (): Cached => Cached::sequence([
            $this->inputs->product($id),
            $this->inputs->viewer($id),
        ]));
    }

    /**
     * @return Cached<array<int, array{int, string}>>
     */
    public function traverse(int $id): Cached
    {
        return $this->cached(fn (): Cached => Cached::traverse(
            [$id],
            fn (int $id): Cached => $this->inputs->product($id)->zip($this->inputs->viewer($id)),
        ));
    }

    /**
     * @return Cached<array{}>
     */
    public function emptySequence(): Cached
    {
        return $this->cached(static fn (): Cached => Cached::sequence([]));
    }
}
