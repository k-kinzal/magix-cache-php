<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime\Extension;

use Magix\Cache\Cache\CacheBackendFailure;
use Magix\Cache\Runtime\Extension\CacheAccess;
use Magix\Cache\Runtime\Extension\DefaultBackendErrorClassifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheException as Psr16CacheException;
use RuntimeException;

#[CoversClass(DefaultBackendErrorClassifier::class)]
final class DefaultBackendErrorClassifierTest extends TestCase
{
    public function testIsBackendFailureAcceptsDeclaredBackendFailures(): void
    {
        $classifier = new DefaultBackendErrorClassifier();
        $psr16 = new class () extends RuntimeException implements Psr16CacheException {
        };

        self::assertTrue($classifier->isBackendFailure(new CacheBackendFailure('down'), CacheAccess::Read));
        self::assertTrue($classifier->isBackendFailure($psr16, CacheAccess::Write));
        self::assertFalse($classifier->isBackendFailure(new RuntimeException('origin failed'), CacheAccess::Read));
    }
}
