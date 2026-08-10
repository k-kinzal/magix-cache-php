<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime\Strategy;

use Magix\Cache\Cache\CacheEntry;
use Magix\Cache\Runtime\Operation\CacheSet;
use Magix\Cache\Runtime\Strategy\CacheSetStrategyHandler;
use Magix\Cache\Runtime\Strategy\CacheStrategyMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheSetStrategyHandler::class)]
#[UsesClass(CacheStrategyMiddleware::class)]
#[UsesClass(CacheEntry::class)]
#[UsesClass(CacheSet::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\CacheTokenSet::class)]
final class CacheSetStrategyHandlerTest extends TestCase
{
    public function testInvokeRunsBoundSetStrategy(): void
    {
        $handled = null;
        $strategy = new readonly class () extends CacheStrategyMiddleware {};
        $handler = new CacheSetStrategyHandler(
            $strategy,
            static function (CacheSet $operation) use (&$handled): void {
                $handled = $operation;
            },
        );
        $set = new CacheSet('key', new CacheEntry('value', 120.0));

        $handler($set);

        self::assertSame($set, $handled);
    }
}
