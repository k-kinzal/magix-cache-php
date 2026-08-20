<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Key;

use Magix\Cache\Cli\Key\CacheKeyResolver;
use Magix\Cache\Cli\Key\CacheKeyUnresolvable;
use Magix\Cache\Runtime\CacheDefinitionResolver;
use Magix\Cache\Runtime\KeyStrategy\HashCacheKeyStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Tests\Package\Cli\Fixture\Project\ProductQuery;

#[CoversClass(CacheKeyResolver::class)]
#[UsesClass(CacheKeyUnresolvable::class)]
#[UsesNamespace('Magix\Cache\Runtime')]
#[UsesNamespace('Magix\Cache\Attribute')]
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
        $expected = (new HashCacheKeyStrategy())->generate(
            (new CacheDefinitionResolver())->resolve(new ProductQuery(), 'execute')->keyContext([42], '1'),
        );

        $key = (new CacheKeyResolver())->resolve(ProductQuery::class, 'execute', '1', [42]);

        self::assertSame($expected, $key);
    }
}
