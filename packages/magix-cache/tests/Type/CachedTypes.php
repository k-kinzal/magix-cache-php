<?php

declare(strict_types=1);

namespace Tests\Type;

use Magix\Cache\Cached;

use function PHPStan\Testing\assertType;

/**
 * Verifies the public composition signatures with PHPStan during composer lint.
 *
 * @param Cached<Cached<int>> $nested
 * @param Cached<string> $label
 * @param Cached<array{int, string|null}> $pair
 * @param array<string, Cached<int>> $items
 * @param iterable<int, string> $ids
 */
function cachedTypes(Cached $nested, Cached $label, Cached $pair, array $items, iterable $ids): void
{
    assertType('Magix\Cache\Cached<int>', $nested->flatten());
    assertType('Magix\Cache\Cached<array{int, string}>', $nested->flatten()->zip($label));
    assertType('array{Magix\Cache\Cached<int>, Magix\Cache\Cached<string|null>}', $pair->unzip());
    assertType('Magix\Cache\Cached<array<string, int>>', Cached::sequence($items));
    assertType('Magix\Cache\Cached<array{}>', Cached::sequence([]));
    assertType('Magix\Cache\Cached<array<int, string>>', Cached::traverse($ids, static fn (string $id): Cached => Cached::of($id)));
}
