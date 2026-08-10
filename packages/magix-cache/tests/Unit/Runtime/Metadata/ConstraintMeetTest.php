<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime\Metadata;

use Magix\Cache\Runtime\Metadata\ConstraintMeet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConstraintMeet::class)]
final class ConstraintMeetTest extends TestCase
{
    public function testExpirationTreatsNullAsInfinity(): void
    {
        $meet = new ConstraintMeet();

        self::assertSame(10.0, $meet->expiration(null, 10.0));
        self::assertSame(5.0, $meet->expiration(5.0, 10.0));
    }
}
