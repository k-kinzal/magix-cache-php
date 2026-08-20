<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Key;

use Magix\Cache\Cli\Key\CacheKeyResolver;
use Magix\Cache\Runtime\CacheDefinitionResolver;
use Magix\Cache\Runtime\KeyStrategy\HashCacheKeyStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Tests\Package\Cli\Fixture\Project\ProductQuery;

#[CoversClass(CacheKeyResolver::class)]
#[UsesNamespace('Magix\Cache\Runtime')]
#[UsesNamespace('Magix\Cache\Attribute')]
final class CacheKeyResolverTest extends TestCase
{
    public function testArgumentsApplyTheAttributesOfTheBoundary(): void
    {
        $arguments = (new CacheKeyResolver())->arguments(ProductQuery::class, 'execute', [42]);

        self::assertSame(['productId' => 42], $arguments);
    }

    public function testResolveMatchesTheKeyTheRuntimeDerives(): void
    {
        $expected = (new HashCacheKeyStrategy())->generate(
            (new CacheDefinitionResolver())->resolve(new ProductQuery(), 'execute')->keyContext([42], '1'),
        );

        $key = (new CacheKeyResolver())->resolve(ProductQuery::class, 'execute', '1', [42]);

        self::assertSame($expected, $key);
    }
}
