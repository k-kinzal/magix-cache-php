<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Declaration;

use Magix\Cache\Cli\Declaration\Unresolved;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Unresolved::class)]
final class UnresolvedTest extends TestCase
{
    public function testValueIsTheOnlyCase(): void
    {
        self::assertSame([Unresolved::Value], Unresolved::cases());
    }
}
