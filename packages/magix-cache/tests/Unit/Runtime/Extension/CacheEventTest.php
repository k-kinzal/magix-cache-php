<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime\Extension;

use function count;

use Magix\Cache\Runtime\Extension\CacheEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheEvent::class)]
final class CacheEventTest extends TestCase
{
    public function testEveryStageOutcomeIsNamed(): void
    {
        self::assertSame(7, count(CacheEvent::cases()));
        self::assertNotSame(CacheEvent::FreshHit, CacheEvent::StaleServed);
    }
}
