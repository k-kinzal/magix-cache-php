# Cache Keys

This guide explains how MagixCache identifies method calls and how to make keys stable, private, or intentionally independent of selected arguments.

## Default Key

`Cacheable` captures the method invocation before it reaches the runtime. The default `HashCacheKeyStrategy` hashes the following data with SHA-256:

- Runtime key namespace (`namespace` on `CacheRuntime`, `'magix'` by default)
- The concrete class the boundary was invoked on
- The declaring class and method name
- Every method argument, associated with its parameter name
- Cache policy version
- A fingerprint of the effective declaration

The result is an opaque 64-character key. Calls with the same normalized invocation produce the same key, while a different argument, version, or declaration produces a different key. Because the concrete class is part of the key, a subclass never shares entries with its parent.

The declaration fingerprint covers the resolved policy, behaviors, scopes, and key attributes: changing any of them invalidates old entries, which are then no longer read but remain in the backend until their physical expiration.

Default values are included even when the caller omits them. Variadic arguments are included individually and preserve their captured positions.

## Argument Requirements

The default strategy uses PHP serialization before hashing, so scalar values, arrays, and serializable value objects can be used directly:

```php
#[Cache(ttl: 30)]
public function execute(int $productId, string $locale = 'en'): Cached
{
    return $this->cached(/* ... */);
}
```

A direct resource argument is rejected. Values that PHP cannot serialize, such as closures, are also rejected. Reduce them to stable data with `#[CacheKey]`, or use `#[CacheIgnore]` only when they cannot affect the result.

## Reduce an Argument

Use `#[CacheKey]` when only part of an object determines the output or when its default serialized representation is unstable. The reducer must be a public static callable:

```php
<?php

use Magix\Cache\Attribute\Cache;
use Magix\Cache\Attribute\CacheKey;

final readonly class ProductRequestKey
{
    /** @return array{id: int, locale: string} */
    public static function reduce(ProductRequest $request): array
    {
        return [
            'id' => $request->productId,
            'locale' => $request->locale,
        ];
    }
}

final class ProductQuery
{
    use Cacheable;

    #[Cache(ttl: 30)]
    public function execute(
        #[CacheKey([ProductRequestKey::class, 'reduce'])]
        ProductRequest $request,
    ): Cached {
        return $this->cached(/* ... */);
    }
}
```

The reducer output becomes the argument's key representation. It must itself be serializable and must include every input property that can change the query result.

## Ignore an Argument

Use `#[CacheIgnore]` for operational data that cannot affect the returned value, such as a trace identifier:

```php
use Magix\Cache\Attribute\CacheIgnore;

#[Cache(ttl: 30)]
public function execute(
    int $productId,
    #[CacheIgnore] string $traceId = '',
): Cached {
    return $this->cached(/* ... */);
}
```

Calls with the same `$productId` share an entry even when `$traceId` differs.

> [!WARNING]
> Ignoring a value that changes the output can return data for the wrong request. Prefer `#[CacheKey]` when a smaller stable representation exists.

## Private Variants

`#[CacheScope]` restricts visibility but does not remove the parameter from the key. This makes personalized variants private while keeping them separated per principal:

```php
use Magix\Cache\Attribute\CacheScope;
use Magix\Cache\Metadata\Visibility;

#[Cache(ttl: 30)]
public function execute(
    #[CacheScope(Visibility::Private)] int $viewerId,
    int $productId,
): Cached {
    return $this->cached(/* ... */);
}
```

`$viewerId` remains in the generated key, and the result visibility becomes `Private`.

A scoped parameter may only be ignored when its scope is `Visibility::NoStore`. This is useful for unkeyable inputs that must disable storage entirely:

```php
use Closure;
use Magix\Cache\Attribute\CacheIgnore;
use Magix\Cache\Attribute\CacheScope;
use Magix\Cache\Metadata\Visibility;

public function execute(
    #[CacheIgnore]
    #[CacheScope(Visibility::NoStore)]
    Closure $load,
): Cached {
    return $this->cached(fn (): Cached => Cached::of($load()));
}
```

## Versioned Keys

Policy versions provide explicit key invalidation when the meaning or shape of a cached result changes without a change to the declaration itself:

```php
#[Cache(ttl: 300, version: 'product-v2')]
```

The version is part of the hash input. After changing it, old entries are no longer read but remain in the backend until their physical expiration.

## Custom Key Strategy

Implement `CacheKeyStrategy` when the application needs namespaced, observable, or backend-specific keys:

```php
<?php

use Magix\Cache\Runtime\CacheKeyContext;
use Magix\Cache\Runtime\CacheKeyStrategy;
use Magix\Cache\Runtime\KeyStrategy\HashCacheKeyStrategy;
use Override;

final readonly class PrefixedCacheKeyStrategy implements CacheKeyStrategy
{
    public function __construct(
        private string $prefix,
        private HashCacheKeyStrategy $hash = new HashCacheKeyStrategy(),
    ) {
    }

    #[Override]
    public function generate(CacheKeyContext $context): string
    {
        return $this->prefix.':'.$this->hash->generate($context);
    }
}
```

Install it on the runtime at registration time:

```php
use Magix\Cache\CacheRuntime;
use Magix\Cache\Runtime\CacheRuntimeRegistry;

CacheRuntimeRegistry::register('default', new CacheRuntime(
    cache: $magixCache,
    keyStrategy: new PrefixedCacheKeyStrategy('storefront'),
));
```

The strategy receives already bound, ignored, and reduced arguments in `CacheKeyContext::$arguments`, together with the runtime namespace, classes, method, version, and declaration fingerprint. Its output must satisfy the key rules of the configured cache backend.

Use `CacheKeyStrategy` for key format changes. Use a `Cache` decorator when keys need to route to different storage tiers.
