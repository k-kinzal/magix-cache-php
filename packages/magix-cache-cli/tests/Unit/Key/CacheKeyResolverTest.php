<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Key;

use Magix\Cache\Cached;
use Magix\Cache\CacheRuntime;
use Magix\Cache\Cli\Key\CacheKeyResolver;
use Magix\Cache\Cli\Key\CacheKeyUnresolvable;
use Magix\Cache\Runtime\CacheDefinitionResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\MemoryCache;
use Tests\Package\Cli\Fixture\ParameterQuery;
use Tests\Package\Cli\Fixture\Project\ProductQuery;

#[CoversClass(CacheKeyResolver::class)]
#[UsesClass(CacheKeyUnresolvable::class)]
#[UsesNamespace('Magix\Cache')]
#[UsesNamespace('Magix\Cache\Attribute')]
#[UsesNamespace('Magix\Cache\Metadata')]
#[UsesClass(\Magix\Cache\CachePolicy::class)]
final class CacheKeyResolverTest extends TestCase
{
    public function testReflectReturnsTheDeclaredBoundaryMethod(): void
    {
        $reflection = (new CacheKeyResolver())->reflect(ProductQuery::class, 'execute');

        self::assertSame(ProductQuery::class, $reflection->getDeclaringClass()->getName());
        self::assertSame('execute', $reflection->getName());
    }

    public function testReflectReportsABoundaryThisProcessCannotLoad(): void
    {
        $this->expectException(CacheKeyUnresolvable::class);

        (new CacheKeyResolver())->reflect('App\\NoSuchQuery', 'execute');
    }

    public function testDefinitionResolvesTheDeclarationTheRuntimeWouldUse(): void
    {
        $definition = (new CacheKeyResolver())->definition(ProductQuery::class, 'execute');

        self::assertSame('1', $definition->policy->version);
        self::assertSame(['product'], $definition->policy->tags);
        self::assertSame('default', $definition->runtime);
    }

    public function testDefinitionReportsAClassThisProcessCannotLoad(): void
    {
        $this->expectException(CacheKeyUnresolvable::class);

        (new CacheKeyResolver())->definition('App\\NoSuchQuery', 'execute');
    }

    public function testArgumentsReportACallThatOmitsARequiredParameter(): void
    {
        $this->expectException(CacheKeyUnresolvable::class);

        (new CacheKeyResolver())->arguments(ProductQuery::class, 'execute', []);
    }

    public function testArgumentsReportACallWithMoreArgumentsThanParameters(): void
    {
        $this->expectException(CacheKeyUnresolvable::class);

        (new CacheKeyResolver())->arguments(ProductQuery::class, 'execute', [42, 43]);
    }

    public function testArgumentsApplyTheAttributesOfTheBoundary(): void
    {
        $arguments = (new CacheKeyResolver())->arguments(ProductQuery::class, 'execute', [42]);

        self::assertSame(['productId' => 42], $arguments);
    }

    public function testResolveMatchesTheKeyTheRuntimeDerives(): void
    {
        $cache = new MemoryCache();
        (new CacheRuntime($cache))->execute(
            (new CacheDefinitionResolver())->resolve(new ProductQuery(), 'execute')->invocation(
                [42],
                static fn (): Cached => Cached::of(['id' => 42]),
            ),
        );

        $key = (new CacheKeyResolver())->resolve(ProductQuery::class, 'execute', [42]);

        self::assertSame(['id' => 42], $cache->get($key, static fn (): array => ['id' => 0])?->value());
    }

    public function testResolveMatchesAnInvocationWithParameterConfigurationAndDefaults(): void
    {
        $definition = (new CacheDefinitionResolver())->resolve(new ParameterQuery(), 'fetch');
        $invocation = $definition->invocation([10], static fn (): Cached => Cached::of('origin'));
        $cache = new MemoryCache();
        (new CacheRuntime($cache, namespace: 'parameters'))->execute($invocation);

        $resolver = new CacheKeyResolver();
        $key = $resolver->resolve(ParameterQuery::class, 'fetch', [10], namespace: 'parameters');

        self::assertSame('origin', $cache->get($key, static fn (): string => '')?->value());
        self::assertNull($cache->get($resolver->resolve(ParameterQuery::class, 'fetch', [20], namespace: 'parameters'), static fn (): string => ''));
    }
}
