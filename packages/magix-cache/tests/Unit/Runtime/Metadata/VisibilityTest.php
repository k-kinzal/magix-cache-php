<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime\Metadata;

use Magix\Cache\Runtime\Metadata\Visibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Visibility::class)]
final class VisibilityTest extends TestCase
{
    public function testMeetReturnsMoreRestrictiveVisibility(): void
    {
        self::assertSame(Visibility::Private, Visibility::Shared->meet(Visibility::Private));
        self::assertSame(Visibility::NoStore, Visibility::NoStore->meet(Visibility::Private));
    }
}
