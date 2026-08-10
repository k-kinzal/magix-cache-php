<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use Magix\Cache\Runtime\CacheDefinition;
use Magix\Cache\Runtime\CacheDefinitionResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\KeyQuery;
use Tests\Fixture\PlainQuery;

#[CoversClass(CacheDefinitionResolver::class)]
#[UsesClass(CacheDefinition::class)]
#[UsesClass(\Magix\Cache\Attribute\Cache::class)]
#[UsesClass(\Magix\Cache\Attribute\CacheIgnore::class)]
#[UsesClass(\Magix\Cache\Attribute\CacheScope::class)]
#[UsesClass(\Magix\Cache\CachePolicy::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\Visibility::class)]
final class CacheDefinitionResolverTest extends TestCase
{
    public function testResolveReturnsAndMemoizesMethodPolicy(): void
    {
        $resolver = new CacheDefinitionResolver();
        $first = $resolver->resolve(new KeyQuery(), 'execute');
        $second = $resolver->resolve(new KeyQuery(), 'execute');

        self::assertSame($first, $second);
        self::assertNotNull($first->policy());
        self::assertSame(30, $first->policy()->ttl);
    }

    public function testResolveReturnsNullPolicyWithoutAttribute(): void
    {
        $definition = (new CacheDefinitionResolver())->resolve(new PlainQuery(), 'execute');

        self::assertNull($definition->policy());
    }
}
