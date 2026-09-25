<?php

declare(strict_types=1);

namespace Tests\Type;

use Magix\Cache\Async\Promise;
use Magix\Cache\AsyncCached;
use Magix\Cache\Cached;

use function PHPStan\Testing\assertType;

/**
 * @param AsyncCached<AsyncCached<int>> $nested
 * @param AsyncCached<Cached<int>> $syncNested
 * @param AsyncCached<string> $label
 * @param AsyncCached<array{int, string|null}> $pair
 * @param array<string, AsyncCached<int>> $items
 * @param Promise<int> $promise
 */
function asyncCachedTypes(AsyncCached $nested, AsyncCached $syncNested, AsyncCached $label, AsyncCached $pair, array $items, Promise $promise): void
{
    assertType('Magix\Cache\AsyncCached<int>', $nested->flatten());
    assertType('Magix\Cache\Cached<Magix\Cache\Cached<int>>', $syncNested->toCached());
    assertType('Magix\Cache\Cached<int>', $syncNested->toCached()->flatten());
    assertType('Magix\Cache\AsyncCached<array{int, string}>', $nested->flatten()->zip($label));
    assertType('array{Magix\Cache\AsyncCached<int>, Magix\Cache\AsyncCached<string|null>}', $pair->unzip());
    assertType('Magix\Cache\AsyncCached<array<string, int>>', AsyncCached::sequence($items));
    assertType('Magix\Cache\AsyncCached<array{}>', AsyncCached::sequence([]));
    assertType('Magix\Cache\AsyncCached<int>', AsyncCached::fromPromise($promise));
    assertType('Magix\Cache\Async\Promise<decimal-int-string>', $promise->then(static fn (int $n): string => (string) $n));
    assertType('Magix\Cache\Async\Promise<decimal-int-string>', $promise->then(static fn (int $n): Promise => Promise::resolved((string) $n)));
    assertType('Magix\Cache\AsyncCached<non-empty-string>', $label->combine2($nested->flatten())->map(static fn (string $s, int $n): string => $s.$n));
}
