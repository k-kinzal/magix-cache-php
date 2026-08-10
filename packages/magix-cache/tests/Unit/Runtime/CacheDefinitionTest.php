<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use Magix\Cache\Runtime\CacheDefinition;
use Magix\Cache\Runtime\CacheDefinitionResolver;
use Magix\Cache\Runtime\CacheKeyArgumentBinder;
use Magix\Cache\Runtime\CacheKeyContext;
use Magix\Cache\Runtime\CacheKeyReducer;
use Magix\Cache\Runtime\Metadata\Visibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\KeyQuery;

#[CoversClass(CacheDefinition::class)]
#[UsesClass(CacheDefinitionResolver::class)]
#[UsesClass(CacheKeyArgumentBinder::class)]
#[UsesClass(CacheKeyContext::class)]
#[UsesClass(CacheKeyReducer::class)]
#[UsesClass(\Magix\Cache\Attribute\Cache::class)]
#[UsesClass(\Magix\Cache\Attribute\CacheIgnore::class)]
#[UsesClass(\Magix\Cache\Attribute\CacheKey::class)]
#[UsesClass(\Magix\Cache\Attribute\CacheScope::class)]
#[UsesClass(\Magix\Cache\CachePolicy::class)]
#[UsesClass(Visibility::class)]
final class CacheDefinitionTest extends TestCase
{
    public function testKeyContextNormalizesIgnoredReducedAndVariadicArguments(): void
    {
        $definition = (new CacheDefinitionResolver())->resolve(new KeyQuery(), 'execute');

        $context = $definition->keyContext([2, 'trace', 'extra'], 'version');

        self::assertSame(KeyQuery::class, $context->class);
        self::assertSame('execute', $context->method);
        self::assertSame([
            'viewer' => 'even',
            'rest[2]' => 'extra',
        ], $context->arguments);
        self::assertSame('version', $context->version);
    }

    public function testPolicyReturnsDeclaredPolicy(): void
    {
        $definition = (new CacheDefinitionResolver())->resolve(new KeyQuery(), 'execute');

        self::assertNotNull($definition->policy());
        self::assertSame('key-query', $definition->policy()->version);
    }

    public function testVisibilityReturnsParameterVisibility(): void
    {
        $definition = (new CacheDefinitionResolver())->resolve(new KeyQuery(), 'execute');

        self::assertSame(Visibility::Private, $definition->visibility());
    }
}
