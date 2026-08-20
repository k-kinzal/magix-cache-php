<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Key;

use Magix\Cache\Cli\Key\CacheKeyUnresolvable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(CacheKeyUnresolvable::class)]
final class CacheKeyUnresolvableTest extends TestCase
{
    public function testFailureKeepsTheCauseItReports(): void
    {
        $cause = new RuntimeException('Class "App\\Missing" does not exist.');

        $failure = new CacheKeyUnresolvable('The boundary App\\Missing::execute cannot be loaded.', previous: $cause);

        self::assertSame('The boundary App\\Missing::execute cannot be loaded.', $failure->getMessage());
        self::assertSame($cause, $failure->getPrevious());
    }
}
