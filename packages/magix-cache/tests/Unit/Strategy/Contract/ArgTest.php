<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy\Contract;

use Magix\Cache\Strategy\Contract\Arg;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Arg::class)]
final class ArgTest extends TestCase
{
    public function testReferencesAMethodParameterByName(): void
    {
        self::assertSame('min', new Arg('min')->name);
    }
}
