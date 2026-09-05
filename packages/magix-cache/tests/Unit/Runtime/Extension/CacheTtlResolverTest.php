<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime\Extension;

use Magix\Cache\Cached;
use Magix\Cache\Runtime\Extension\CacheTtlResolver;
use Magix\Cache\Runtime\Extension\DynamicTtlContext;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class CacheTtlResolverTest extends TestCase
{
    public function testResolveReturnsLifetimeFromImplementation(): void
    {
        $context = new DynamicTtlContext('key', Cached::of('value'), 100.0);
        $resolver = $this->createMock(CacheTtlResolver::class);
        $resolver
            ->expects(self::once())
            ->method('resolve')
            ->with($context)
            ->willReturn(30);

        self::assertSame(30, $resolver->resolve($context));
    }
}
