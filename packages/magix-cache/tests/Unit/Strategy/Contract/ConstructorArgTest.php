<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy\Contract;

use Magix\Cache\Strategy\Contract\ConstructorArg;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConstructorArg::class)]
final class ConstructorArgTest extends TestCase
{
    public function testReferencesAConstructorParameterByName(): void
    {
        self::assertSame('minimum', (new ConstructorArg('minimum'))->name);
    }
}
