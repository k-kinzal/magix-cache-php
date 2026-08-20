<?php

declare(strict_types=1);

namespace Tests\Unit\Cache;

use Magix\Cache\Cache\CacheBackendFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(CacheBackendFailure::class)]
final class CacheBackendFailureTest extends TestCase
{
    public function testFailureKeepsTheBackendCauseItReports(): void
    {
        $cause = new RuntimeException('Connection refused.');

        $failure = new CacheBackendFailure('The PSR-6 pool failed to read "generated-key".', previous: $cause);

        self::assertSame('The PSR-6 pool failed to read "generated-key".', $failure->getMessage());
        self::assertSame($cause, $failure->getPrevious());
    }
}
