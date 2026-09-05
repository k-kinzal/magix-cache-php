<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime\Extension;

use Magix\Cache\Runtime\Extension\CacheAccess;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheAccess::class)]
final class CacheAccessTest extends TestCase
{
    public function testSidesAreDistinct(): void
    {
        self::assertNotSame(CacheAccess::Read, CacheAccess::Write);
    }
}
