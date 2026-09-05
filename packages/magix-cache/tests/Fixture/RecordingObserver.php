<?php

declare(strict_types=1);

namespace Tests\Fixture;

use Magix\Cache\Runtime\Extension\CacheEvent;
use Magix\Cache\Runtime\Extension\CacheObserver;
use Override;

/**
 * Records runtime diagnostic events for assertions.
 */
final class RecordingObserver implements CacheObserver
{
    /**
     * @var list<CacheEvent>
     */
    public array $events = [];

    /**
     * Appends the observed event to the recorded sequence.
     */
    #[Override]
    public function observe(CacheEvent $event, string $key): void
    {
        unset($key);
        $this->events[] = $event;
    }
}
