<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime\Extension;

use Magix\Cache\Runtime\Extension\BackendErrorClassifier;
use Magix\Cache\Runtime\Extension\CacheAccess;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversNothing]
final class BackendErrorClassifierTest extends TestCase
{
    public function testIsBackendFailureReceivesTheFailureAndAccessSide(): void
    {
        $error = new RuntimeException('backend down');
        $classifier = $this->createMock(BackendErrorClassifier::class);
        $classifier
            ->expects(self::once())
            ->method('isBackendFailure')
            ->with($error, CacheAccess::Read)
            ->willReturn(true);

        self::assertTrue($classifier->isBackendFailure($error, CacheAccess::Read));
    }
}
