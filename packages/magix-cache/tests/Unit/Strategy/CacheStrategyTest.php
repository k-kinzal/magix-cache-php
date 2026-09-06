<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy;

use Magix\Cache\Cached;
use Magix\Cache\Strategy\CacheOperation;
use Magix\Cache\Strategy\CacheStrategy;
use Magix\Cache\Strategy\NextCacheStrategy;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class CacheStrategyTest extends TestCase
{
    public function testGetReceivesTheOperationAndItsContinuation(): void
    {
        $operation = new CacheOperation('key', static fn (): float => 100.0);
        $next = NextCacheStrategy::end();
        $strategy = $this->createMock(CacheStrategy::class);
        $strategy
            ->expects(self::once())
            ->method('get')
            ->with($operation, $next)
            ->willReturn(null);

        self::assertNull($strategy->get($operation, $next));
    }

    public function testFetchReceivesTheOperationAndItsContinuation(): void
    {
        $operation = new CacheOperation('key', static fn (): float => 100.0);
        $next = NextCacheStrategy::end();
        $produced = Cached::of('value');
        $strategy = $this->createMock(CacheStrategy::class);
        $strategy
            ->expects(self::once())
            ->method('fetch')
            ->with($operation, $next)
            ->willReturn($produced);

        self::assertSame($produced, $strategy->fetch($operation, $next));
    }

    public function testSetReceivesTheProducedResult(): void
    {
        $operation = new CacheOperation('key', static fn (): float => 100.0);
        $next = NextCacheStrategy::end();
        $produced = Cached::of('value');
        $strategy = $this->createMock(CacheStrategy::class);
        $strategy
            ->expects(self::once())
            ->method('set')
            ->with($operation, $produced, $next);

        $strategy->set($operation, $produced, $next);
    }
}
