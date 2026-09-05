<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime\Extension;

use Magix\Cache\Runtime\Extension\CacheEvent;
use Magix\Cache\Runtime\Extension\CacheObserver;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class CacheObserverTest extends TestCase
{
    public function testObserveReceivesTheEventAndKey(): void
    {
        $observer = $this->createMock(CacheObserver::class);
        $observer
            ->expects(self::once())
            ->method('observe')
            ->with(CacheEvent::FreshHit, 'key');

        $observer->observe(CacheEvent::FreshHit, 'key');
    }
}
