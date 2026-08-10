<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime\Strategy;

use Magix\Cache\Cache\CacheEntry;
use Magix\Cache\Runtime\Operation\CacheGet;
use Magix\Cache\Runtime\Strategy\CacheGetStrategyHandler;
use Magix\Cache\Runtime\Strategy\CacheStrategyMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\MutableClock;

#[CoversClass(CacheGetStrategyHandler::class)]
#[UsesClass(CacheStrategyMiddleware::class)]
#[UsesClass(CacheEntry::class)]
#[UsesClass(CacheGet::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\CacheTokenSet::class)]
final class CacheGetStrategyHandlerTest extends TestCase
{
    public function testInvokeRunsBoundGetStrategy(): void
    {
        $entry = new CacheEntry('value', 120.0);
        $strategy = new readonly class () extends CacheStrategyMiddleware {};
        $handler = new CacheGetStrategyHandler($strategy, static fn (): CacheEntry => $entry);

        self::assertSame($entry, $handler(new CacheGet('key', new MutableClock(100.0))));
    }
}
