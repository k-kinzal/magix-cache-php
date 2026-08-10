<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime\Operation;

use Magix\Cache\Runtime\Operation\OriginFetchOutcome;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OriginFetchOutcome::class)]
final class OriginFetchOutcomeTest extends TestCase
{
    public function testOutcomesAreDistinct(): void
    {
        self::assertNotSame(OriginFetchOutcome::Origin, OriginFetchOutcome::Stale);
    }
}
