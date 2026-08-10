<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use Magix\Cache\Runtime\CacheKeyContext;
use Magix\Cache\Runtime\CacheKeyStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheKeyStrategy::class)]
#[UsesClass(CacheKeyContext::class)]
final class CacheKeyStrategyTest extends TestCase
{
    public function testGenerateReturnsKeyFromImplementation(): void
    {
        $context = new CacheKeyContext('App\\Query', 'execute', ['id' => 1], '1');
        $strategy = $this->createMock(CacheKeyStrategy::class);
        $strategy
            ->expects(self::once())
            ->method('generate')
            ->with($context)
            ->willReturn('custom-key');

        self::assertSame('custom-key', $strategy->generate($context));
    }
}
