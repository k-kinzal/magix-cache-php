# Asynchronous Cached Values

`AsyncCached<T>` owns a private `Promise<Cached<T>>`. Its public payload is `T`;
there is no promise getter and no implicit `AsyncCached<Promise<T>>` layer.
The `Magix\Cache\Async\Promise` adapter uses Guzzle Promises for scheduling,
adoption and waiting. `Promise::fromGuzzle($request)` imports a Guzzle promise
without waiting. Guzzle Promises 2 and 3 are supported.

## Declaring a Boundary

Both boundary methods take one argument-free closure. Policies, key arguments,
parameter bindings, behavior precedence and strategy construction are identical.

| Boundary method | Origin result | Boundary result |
| --- | --- | --- |
| `cached(fn)` | `Cached<T>` or `AsyncCached<T>` | `Cached<T>`, after waiting |
| `asyncCached(fn)` | `Cached<T>` or `AsyncCached<T>` | `AsyncCached<T>`, without waiting |

```php
use Magix\Cache\AsyncCached;
use Magix\Cache\Attribute\Cache;
use Magix\Cache\Cacheable;

final class ProductQuery
{
    use Cacheable;

    public function __construct(private ProductClient $client) {}

    /** @return AsyncCached<Product> */
    #[Cache(ttl: 20, tags: ['products'])]
    public function execute(int $id): AsyncCached
    {
        return $this->asyncCached(
            fn (): AsyncCached => AsyncCached::fromGuzzle($this->client->findAsync($id)),
        );
    }
}
```

Here `ProductClient::findAsync()` returns a Guzzle promise of `Product`.
`AsyncCached::fromPromise()` accepts the library's own typed `Promise<T>`.
`AsyncCached::of($value)` lifts a plain value; `fromCached($cached)` lifts an
already evaluated result with its complete metadata. Passing a promise as a
plain payload is a definition error: use one of the promise factories instead.

Lookup happens when the boundary is called. A fresh hit produces an already
available async result and never invokes the origin. On a miss, the inquiry is
scheduled through Guzzle's task queue; constructing or combining results does
not block. Starting actual I/O and providing a wait driver remain the source
promise's responsibility. For an event loop, run Guzzle's task queue as described
in the [Guzzle interoperability guide](https://github.com/guzzle/promises/blob/3.0/docs/promise-interoperability.md).

`toCached()` waits for the complete result, including its cache write.
`value()` also waits, then detaches the payload from its metadata. Repeated
extraction reuses the same settled result; it never repeats the origin, map
callbacks, or write. Reads and writes through the cache port remain synchronous.

## Composition

```php
$product = $products->execute($id);
$inventory = $inventoryQuery->execute($id);

// Neither operation waits for the requests.
$page = $product->combine2($inventory)->map(
    static fn (Product $product, Inventory $stock): ProductPage => new ProductPage($product, $stock),
);

$cached = $page->toCached(); // Explicit synchronization.
```

The asynchronous operations use the same metadata meet law as `Cached`:
earliest finite expiration, AND cacheability, stricter visibility, and union
of tags and reasons. Completion order does not change this law.

| Operation | Behavior |
| --- | --- |
| `map(fn)` | Transform once after completion; preserve receiver metadata |
| `flatMap(fn)` | Return another `AsyncCached`; adopt it and meet both metadata values |
| `flatten()` | Remove exactly one nested `AsyncCached` layer and meet its metadata |
| `zip(other)` | Pair independently running results and meet both metadata values |
| `unzip()` | Return two asynchronous projections, each retaining all pair metadata |
| `sequence(items)` | Consume the iterable now; resolve all items without waiting here |
| `traverse(items, fn)` | Call `fn` once per input now; collect its asynchronous results |
| `combine2()` … `combine10()` | Preserve heterogeneous parameter types for `map(fn)` |

`sequence` and `traverse` preserve keys and insertion order. Repeated keys keep
the last payload, but every input still contributes its metadata. Empty input
has `CacheMetadata::top()`. Independent requests should be created before
composition; `flatMap` is the operation for a request that depends on a previous
result. Functor identity/composition and monad left identity/right identity/
associativity hold for pure callbacks, including the resulting metadata.

A nested `Cached` is a legitimate plain payload:

```php
$async = AsyncCached::of(Cached::of('value', $innerMetadata), $outerMetadata);
$nested = $async->toCached(); // Cached<Cached<string>>
$flat = $nested->flatten();  // Cached<string>, meeting both metadata values
```

`map()` also preserves a returned `Cached` or `AsyncCached` as a nested payload.
`AsyncCached::fromPromise($promiseOfCached)` gives `AsyncCached<Cached<T>>`.
Runtime construction uses `new AsyncCached($promiseOfCached)` or the internal
`fromCachedPromise()` factory to supply the complete result without adding a
payload layer. `subscribe()` is an internal callback bridge; it never returns
the owned promise.

## Middleware and Storage

`CacheStrategy::fetch()` and its argument-free `$next()` return
`Promise<Cached<mixed>>`. Successful continuations receive an evaluated `Cached`:

```php
use Magix\Cache\Async\Promise;
use Magix\Cache\Cached;

public function fetch(string $key, Closure $next): Promise
{
    return $next()->then(fn (Cached $result): Cached => Cached::of(
        $result->value(),
        $result->metadata->withExpiration((float) $this->clock->now()->format('U.u') + 30),
    ));
}
```

A pass-through returns `$next()`. A short circuit returns an already fulfilled
promise such as `Promise::resolved(Cached::of($replacement))`, without calling
`$next`. A null-valued boundary can use `Promise::resolved(Cached::of(null))`.
A bare `Promise::resolved()` is a unit promise for chaining; it is not a valid
fetch result because fetch must produce the boundary's `Cached<T>`.

Use `Promise::call($next)->recover(fn (RuntimeException $error) => ...)` to handle
both a synchronous delegate failure and a later rejection. `recover` selects
only `RuntimeException`; other rejections propagate unchanged. `then` also
accepts an optional rejection callback for application-specific promise logic.
Returning another library promise adopts its eventual result without blocking.

The runtime registers storage with `then` after the composed fetch. A failed
fetch never writes. An unrecovered write failure rejects the result and is
observed on synchronization; it is outside stale-if-error's recovery range.
Stale-if-error checks candidate age and retention when the rejection arrives,
returns the same stored `Cached`, and suppresses its write as before. Each
invocation owns fresh strategy objects, including overlapping invocations.

Policy, parameter and dynamic TTL use one origin-completion timestamp.
Middleware reads its clock inside its continuation. Expiration is rechecked
at the eventual write, so time spent in asynchronous work cannot bypass the
storage rules. Hits retain their original fractional absolute expiration.

## Static Analysis and Migration

The CLI recognizes `asyncCached`, `AsyncCached` composition, `fromCached`,
`toCached`, and value extraction without running the promise or user code.
Synchronization does not alter metadata; extracting a value detaches it.
The existing effect calculator applies the same boundary overrides to both
execution modes. An opaque promise transformation remains unknown where its
returned metadata cannot be followed.

Existing synchronous boundaries keep using `cached()` and `Cached<T>`.
Custom fetch middleware must change its return type to `Promise` and put result
transformations in `then`. Replace a synchronous `try/catch` around `$next()`
with rejection handling: a catch alone cannot see failures that happen later.
Contract attributes (`Ttl`, `ExpiresAt`, `WritesMetadata`) still describe the
final resolved `Cached`, in the same inner-to-outer override order.
