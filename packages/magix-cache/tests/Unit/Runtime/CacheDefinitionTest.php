<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use Magix\Cache\Metadata\Visibility;
use Magix\Cache\Runtime\CacheDefinition;
use Magix\Cache\Runtime\CacheDefinitionResolver;
use Magix\Cache\Runtime\CacheKeyArgumentBinder;
use Magix\Cache\Runtime\CacheKeyContext;
use Magix\Cache\Runtime\CacheKeyReducer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\KeyQuery;

#[CoversClass(CacheDefinition::class)]
#[UsesClass(CacheDefinitionResolver::class)]
#[UsesClass(CacheKeyArgumentBinder::class)]
#[UsesClass(CacheKeyContext::class)]
#[UsesClass(CacheKeyReducer::class)]
#[UsesClass(\Magix\Cache\Runtime\CacheAttributeReader::class)]
#[UsesClass(\Magix\Cache\Runtime\DeclarationFingerprint::class)]
#[UsesClass(\Magix\Cache\Attribute\Cache::class)]
#[UsesClass(\Magix\Cache\Attribute\CacheIgnore::class)]
#[UsesClass(\Magix\Cache\Attribute\CacheKey::class)]
#[UsesClass(\Magix\Cache\Attribute\CacheScope::class)]
#[UsesClass(\Magix\Cache\CachePolicy::class)]
#[UsesClass(Visibility::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheTokenSet::class)]
final class CacheDefinitionTest extends TestCase
{
    public function testKeyContextNormalizesIgnoredReducedAndVariadicArguments(): void
    {
        $definition = (new CacheDefinitionResolver())->resolve(new KeyQuery(), 'execute');

        $context = $definition->keyContext([2, 'trace', 'extra']);

        self::assertSame(KeyQuery::class, $context->class);
        self::assertSame(KeyQuery::class, $context->declaringClass);
        self::assertSame('execute', $context->method);
        self::assertSame([
            'viewer' => 'even',
            'rest[2]' => 'extra',
        ], $context->arguments);
        self::assertSame('key-query', $context->version);
        self::assertSame('', $context->namespace);
        self::assertNotSame('', $context->fingerprint);
    }

    public function testPolicyFoldsScopedParameterConstraintsWithTheMeet(): void
    {
        $definition = (new CacheDefinitionResolver())->resolve(new KeyQuery(), 'execute');

        self::assertSame(30, $definition->policy->ttl);
        self::assertSame(Visibility::Private, $definition->policy->visibility);
    }
}
