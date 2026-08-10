<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime\Strategy;

use Magix\Cache\Cached;
use Magix\Cache\Runtime\Operation\OriginFetch;
use Magix\Cache\Runtime\Operation\OriginFetchResult;
use Magix\Cache\Runtime\Strategy\CacheStrategyMiddleware;
use Magix\Cache\Runtime\Strategy\OriginFetchStrategyHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\MutableClock;

#[CoversClass(OriginFetchStrategyHandler::class)]
#[UsesClass(CacheStrategyMiddleware::class)]
#[UsesClass(OriginFetch::class)]
#[UsesClass(OriginFetchResult::class)]
#[UsesClass(\Magix\Cache\Runtime\Operation\OriginFetchOutcome::class)]
#[UsesClass(Cached::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\CacheTokenSet::class)]
final class OriginFetchStrategyHandlerTest extends TestCase
{
    public function testInvokeRunsBoundFetchStrategy(): void
    {
        $result = new OriginFetchResult(Cached::of('value'));
        $strategy = new readonly class () extends CacheStrategyMiddleware {};
        $handler = new OriginFetchStrategyHandler($strategy, static fn (): OriginFetchResult => $result);
        $fetch = new OriginFetch(
            'key',
            static fn (): Cached => Cached::of('value'),
            null,
            new MutableClock(100.0),
        );

        self::assertSame($result, $handler($fetch));
    }
}
