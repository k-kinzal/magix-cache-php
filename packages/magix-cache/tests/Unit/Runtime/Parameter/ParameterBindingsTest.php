<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime\Parameter;

use Magix\Cache\Cached;
use Magix\Cache\Metadata\Visibility;
use Magix\Cache\Runtime\CacheDefinitionResolver;
use Magix\Cache\Runtime\Parameter\ParameterBindings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\ParameterQuery;

#[CoversClass(ParameterBindings::class)]
#[UsesNamespace('Magix\Cache')]
final class ParameterBindingsTest extends TestCase
{
    public function testBindReadsNamedArgumentsAndDefaultsWithoutRetainingValues(): void
    {
        $bindings = (new CacheDefinitionResolver())->resolve(new ParameterQuery(), 'fetch');
        $first = $bindings->invocation(['visibility' => Visibility::Private, 'ttl' => 12], static fn (): Cached => Cached::of('origin'));
        $second = $bindings->invocation([], static fn (): Cached => Cached::of('origin'));

        self::assertSame(12, $first->parameterTtl);
        self::assertSame(Visibility::Private, $first->policy->visibility);
        self::assertSame(30, $second->parameterTtl);
        self::assertSame(Visibility::Shared, $second->policy->visibility);
    }

    public function testStrategyArgumentsNamesTheFactoryDestination(): void
    {
        $bindings = (new CacheDefinitionResolver())->resolve(new ParameterQuery(), 'both');

        $invocation = $bindings->invocation([20], static fn (): Cached => Cached::of('origin'));

        self::assertSame(20, $invocation->parameterTtl);
        self::assertNotNull($invocation->strategy);
    }

    public function testTtlPreservesZeroAsAnExplicitConstraint(): void
    {
        $bindings = (new CacheDefinitionResolver())->resolve(new ParameterQuery(), 'auto');

        $invocation = $bindings->invocation([0], static fn (): Cached => Cached::of('origin'));

        self::assertSame(0, $invocation->parameterTtl);
    }

    public function testTagsCanonicalizesTheSuppliedTokens(): void
    {
        $bindings = (new CacheDefinitionResolver())->resolve(new ParameterQuery(), 'fetch');

        $invocation = $bindings->invocation([30, ['b', 'a', 'b']], static fn (): Cached => Cached::of('origin'));

        self::assertSame(['a', 'b'], $invocation->policy->tags);
    }

    public function testVisibilityPreservesTheNoStoreRestriction(): void
    {
        $bindings = (new CacheDefinitionResolver())->resolve(new ParameterQuery(), 'fetch');

        $invocation = $bindings->invocation(['visibility' => Visibility::NoStore], static fn (): Cached => Cached::of('origin'));

        self::assertSame(Visibility::NoStore, $invocation->policy->visibility);
    }
}
