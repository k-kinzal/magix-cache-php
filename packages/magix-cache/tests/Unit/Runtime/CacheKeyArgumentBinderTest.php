<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use Magix\Cache\Runtime\CacheDefinition;
use Magix\Cache\Runtime\CacheDefinitionResolver;
use Magix\Cache\Runtime\CacheKeyArgumentBinder;
use Magix\Cache\Runtime\CacheKeyContext;
use Magix\Cache\Runtime\CacheKeyReducer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\KeyQuery;

#[CoversClass(CacheKeyArgumentBinder::class)]
#[UsesClass(CacheDefinition::class)]
#[UsesClass(CacheDefinitionResolver::class)]
#[UsesClass(CacheKeyContext::class)]
#[UsesClass(CacheKeyReducer::class)]
#[UsesClass(\Magix\Cache\Attribute\Cache::class)]
#[UsesClass(\Magix\Cache\Attribute\CacheIgnore::class)]
#[UsesClass(\Magix\Cache\Attribute\CacheKey::class)]
#[UsesClass(\Magix\Cache\Attribute\CacheScope::class)]
#[UsesClass(\Magix\Cache\CachePolicy::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\Visibility::class)]
final class CacheKeyArgumentBinderTest extends TestCase
{
    public function testBindNormalizesIgnoredReducedAndVariadicArguments(): void
    {
        $definition = (new CacheDefinitionResolver())->resolve(new KeyQuery(), 'execute');

        self::assertSame([
            'viewer' => 'even',
            'rest[2]' => 'extra',
        ], $definition->keyContext([2, 'trace', 'extra'], 'version')->arguments);
    }
}
