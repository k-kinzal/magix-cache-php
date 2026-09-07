<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use Magix\Cache\Runtime\CacheDefinition;
use Magix\Cache\Runtime\CacheDefinitionResolver;
use Magix\Cache\Strategy\StrategyDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\ChildDeclaredQuery;
use Tests\Fixture\DeclaredQuery;
use Tests\Fixture\StrategyQuery;

#[CoversClass(CacheDefinitionResolver::class)]
#[UsesClass(CacheDefinition::class)]
#[UsesClass(\Magix\Cache\Runtime\CacheAttributeReader::class)]
#[UsesClass(\Magix\Cache\Runtime\DeclarationFingerprint::class)]
#[UsesClass(\Magix\Cache\Attribute\BypassCacheErrors::class)]
#[UsesClass(\Magix\Cache\Attribute\Cache::class)]
#[UsesClass(\Magix\Cache\Attribute\DynamicTtl::class)]
#[UsesClass(\Magix\Cache\Attribute\StaleIfError::class)]
#[UsesClass(\Magix\Cache\Attribute\UseStrategy::class)]
#[UsesClass(\Magix\Cache\Strategy\ComposedCacheStrategy::class)]
#[UsesClass(\Magix\Cache\Strategy\CompositeCacheStrategy::class)]
#[UsesClass(\Magix\Cache\Strategy\KeySpreadExpirationStrategy::class)]
#[UsesClass(\Magix\Cache\Strategy\StaleIfErrorCacheStrategy::class)]
#[UsesClass(\Magix\Cache\CachePolicy::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheTokenSet::class)]
#[UsesClass(\Magix\Cache\Metadata\Visibility::class)]
#[UsesClass(\Magix\Cache\Strategy\StrategyArguments::class)]
#[UsesClass(StrategyDefinition::class)]
final class CacheDefinitionResolverTest extends TestCase
{
    public function testResolveMemoizesTheStaticDeclaration(): void
    {
        $resolver = new CacheDefinitionResolver();

        self::assertSame(
            $resolver->resolve(new DeclaredQuery(), 'viaMethod'),
            $resolver->resolve(new DeclaredQuery(), 'viaMethod'),
        );
    }

    public function testResolvePrefersTheMethodPolicyAndDisablesClassBehaviors(): void
    {
        $definition = (new CacheDefinitionResolver())->resolve(new DeclaredQuery(), 'viaMethod');

        self::assertSame(30, $definition->policy->ttl);
        self::assertNull($definition->staleIfError);
        self::assertNull($definition->dynamicTtl);
        self::assertNull($definition->bypassCacheErrors);
    }

    public function testResolveFallsBackToTheClassPolicyAndBehaviors(): void
    {
        $definition = (new CacheDefinitionResolver())->resolve(new DeclaredQuery(), 'viaClass');

        self::assertSame(60, $definition->policy->ttl);
        self::assertNotNull($definition->staleIfError);
        self::assertSame(60, $definition->staleIfError->maxAge);
        self::assertNotNull($definition->dynamicTtl);
        self::assertNotNull($definition->bypassCacheErrors);
    }

    public function testResolveKeepsTheDeclarationOfAnInheritedMethod(): void
    {
        $resolver = new CacheDefinitionResolver();

        self::assertSame(25, $resolver->resolve(new ChildDeclaredQuery(), 'inherited')->policy->ttl);
        self::assertSame('inherited', $resolver->resolve(new ChildDeclaredQuery(), 'inherited')->policy->version);
    }

    public function testResolveBuildsTheDeclaredStrategyThroughCreate(): void
    {
        $resolver = new CacheDefinitionResolver();

        self::assertInstanceOf(StrategyDefinition::class, $resolver->resolve(new StrategyQuery(), 'viaClass')->strategy);
        self::assertInstanceOf(StrategyDefinition::class, $resolver->resolve(new StrategyQuery(), 'viaMethod')->strategy);
        self::assertNull($resolver->resolve(new StrategyQuery(), 'withoutStrategy')->strategy);
    }
}
