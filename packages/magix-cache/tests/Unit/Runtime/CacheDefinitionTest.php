<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use Magix\Cache\Cached;
use Magix\Cache\Metadata\Visibility;
use Magix\Cache\Runtime\CacheDefinition;
use Magix\Cache\Runtime\CacheDefinitionResolver;
use Magix\Cache\Runtime\CacheKeyArgumentBinder;
use Magix\Cache\Runtime\CacheKeyContext;
use Magix\Cache\Runtime\CacheKeyReducer;
use Magix\Cache\Runtime\Parameter\ParameterConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\KeyQuery;
use Tests\Fixture\ParameterQuery;

#[CoversClass(CacheDefinition::class)]
#[UsesNamespace('Magix\Cache')]
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

    public function testInvocationBindsConfigurationBeforeRuntimeExecution(): void
    {
        $definition = (new CacheDefinitionResolver())->resolve(new ParameterQuery(), 'fetch');
        $invocation = $definition->invocation(['ttl' => 7, 'visibility' => Visibility::NoStore], static fn (): Cached => Cached::of('origin'));

        self::assertSame(7, $invocation->parameterTtl);
        self::assertSame(Visibility::NoStore, $invocation->policy->visibility);
        self::assertSame('origin', ($invocation->origin)()->value());
    }

    public function testContextIncludesConfigurationWhenAKeyReducerDropsTheValue(): void
    {
        $definition = (new CacheDefinitionResolver())->resolve(new ParameterQuery(), 'fetch');
        $context = $definition->context([30], new ParameterConfiguration(ttl: 30), null);

        self::assertArrayHasKey('@configuration', $context->arguments);
        self::assertSame(30, $context->arguments['ttl']);
    }

    public function testStrategyForLeavesBoundariesWithoutStrategiesUnconfigured(): void
    {
        $definition = (new CacheDefinitionResolver())->resolve(new ParameterQuery(), 'fetch');

        self::assertNull($definition->strategyFor(new ParameterConfiguration(ttl: 30)));
    }
}
