<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy;

use Magix\Cache\Cached;
use Magix\Cache\Strategy\CacheStrategy;
use Magix\Cache\Strategy\CacheWrite;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class CacheStrategyTest extends TestCase
{
    public function testGetReceivesItsKeyAndOperationClosure(): void
    {
        $key = 'key';
        $next = static fn (string $key): null => null;
        $strategy = $this->createMock(CacheStrategy::class);
        $strategy
            ->expects(self::once())
            ->method('get')
            ->with($key, $next)
            ->willReturn(null);

        self::assertNull($strategy->get($key, $next));
    }

    public function testFetchReceivesItsKeyAndOperationClosure(): void
    {
        $key = 'key';
        $next = static fn (): Cached => Cached::of('value');
        $produced = Cached::of('value');
        $strategy = $this->createMock(CacheStrategy::class);
        $strategy
            ->expects(self::once())
            ->method('fetch')
            ->with($key, $next)
            ->willReturn($produced);

        self::assertSame($produced, $strategy->fetch($key, $next));
    }

    public function testSetReceivesTheProducedResult(): void
    {
        $key = 'key';
        $next = static function (string $key, CacheWrite $request): void {
        };
        $produced = new CacheWrite(Cached::of('value'));
        $strategy = $this->createMock(CacheStrategy::class);
        $strategy
            ->expects(self::once())
            ->method('set')
            ->with($key, $produced, $next);

        $strategy->set($key, $produced, $next);
    }


}
