# Lint Rules

This guide documents the rules `magix lint` applies to every cache boundary.

| Rule | Severity | Reports |
|---|---|---|
| [`missing-policy`](#missing-policy) | error | `cached()` without any `#[Cache]` declaration |
| [`auto-ttl-without-upstream`](#auto-ttl-without-upstream) | error / notice | A derived TTL with nothing to derive from |
| [`scoped-ignore-conflict`](#scoped-ignore-conflict) | error | A parameter that is ignored and scoped |
| [`unscoped-private-key`](#unscoped-private-key) | warning | A private dependency the caller key cannot separate |
| [`unstable-key-argument`](#unstable-key-argument) | warning | A key parameter that cannot be hashed reliably |

Errors and warnings fail the command; see [Commands](commands.md#magix-lint).

## missing-policy

`cached()` resolves its policy from the `#[Cache]` attribute on the method, and then on the concrete class. When neither exists, the call throws a `LogicException` the first time it runs.

```php
public function execute(int $id): Cached
{
    return $this->cached(static fn (): Cached => Cached::of($id));
}
```

Add `#[Cache(...)]` to the method or its concrete class.

## auto-ttl-without-upstream

`Ttl::Auto` and `Ttl::FromUpstream` derive their expiration from the result. When the analyzer can prove that no dependency ever constrains it, and the boundary neither builds `CacheMetadata` itself nor declares `#[DynamicTtl]`, applying the policy throws a `LogicException`, and the rule reports an error.

```php
#[Cache(ttl: Ttl::Auto)]
public function execute(int $id): Cached
{
    return $this->cached(static fn (): Cached => Cached::of($id));
}
```

Declare a fixed TTL, depend on a cached query, or supply `CacheMetadata` with an expiration.

When the upstream expiration cannot be decided statically, the requirement is only conditional: the rule reports a notice instead of an error, because whether a finite expiration exists is known only at runtime. Notices never fail the run.

## scoped-ignore-conflict

A parameter that is excluded from the key cannot narrow visibility, because the narrowed entry would have no key to distinguish it. `CacheDefinition` throws an `InvalidArgumentException` for this combination unless the scope is `Visibility::NoStore`.

```php
#[Cache(ttl: 10)]
public function execute(
    #[CacheIgnore]
    #[CacheScope(Visibility::Private)]
    int $viewerId,
): Cached {
    // ...
}
```

Remove `#[CacheIgnore]`, or scope the parameter with `Visibility::NoStore` when the value must disable storage entirely.

## unscoped-private-key

This rule reports the case where composition keeps a result correct but the key does not.

A dependency that declares `#[CacheScope(Visibility::Private)]` separates its own entries by the scoped value. A caller inherits the private visibility, but its own key is built from its own parameters. When the caller never receives that value as a parameter, every viewer shares one key, and the first viewer's result is served to the next one.

```php
#[Cache(ttl: 45)]
public function execute(string $section): Cached
{
    return $this->cached(function () use ($section): Cached {
        $viewer = $this->viewer->execute($this->session->viewerId());
        // The result depends on the viewer, but $section is the whole key.
    });
}
```

Accept the value as a parameter and pass it on, so that it becomes part of the key:

```php
#[Cache(ttl: 45)]
public function execute(string $section, int $viewerId): Cached
{
    return $this->cached(function () use ($section, $viewerId): Cached {
        $viewer = $this->viewer->execute($viewerId);
        // ...
    });
}
```

Marking the parameter with `#[CacheScope]` on the caller as well documents the restriction and silences the rule for boundaries that are already scoped themselves.

## unstable-key-argument

The default key strategy serializes every argument. Values typed as `mixed`, `object`, `callable`, `iterable`, `Closure`, or left untyped either cannot be serialized or carry state that changes the key without changing the result.

```php
#[Cache(ttl: 10)]
public function execute(object $filter): Cached
{
    // ...
}
```

Reduce the value with `#[CacheKey]` to the part that determines the output, or exclude it with `#[CacheIgnore]` when it cannot change the result.
