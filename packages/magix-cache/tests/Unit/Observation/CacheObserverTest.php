<?php

declare(strict_types=1);

namespace Tests\Unit\Observation;

use Magix\Cache\Observation\CacheEvent;
use Magix\Cache\Observation\CacheObserver;
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
