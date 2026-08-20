<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Declaration;

use Magix\Cache\Cli\Declaration\DependencyCall;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DependencyCall::class)]
final class DependencyCallTest extends TestCase
{
    public function testCallKeepsTheForwardedCallerParameters(): void
    {
        $call = new DependencyCall('App\ViewerQuery', 'execute', 42, [0 => 'viewerId']);

        self::assertSame('App\ViewerQuery', $call->class);
        self::assertSame('execute', $call->method);
        self::assertSame(42, $call->line);
        self::assertSame([0 => 'viewerId'], $call->forwarded);
    }
}
