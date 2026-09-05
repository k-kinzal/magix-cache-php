<?php

declare(strict_types=1);

namespace Tests\Unit\Attribute;

use LogicException;
use Magix\Cache\Attribute\StaleIfError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TypeError;

#[CoversClass(StaleIfError::class)]
final class StaleIfErrorTest extends TestCase
{
    public function testCapturesOnlyDeclaredExceptionTypes(): void
    {
        $behavior = new StaleIfError(maxAge: 300, exceptions: [RuntimeException::class]);

        self::assertTrue($behavior->captures(new RuntimeException('upstream unavailable')));
        self::assertFalse($behavior->captures(new LogicException('a bug, not an outage')));
        self::assertFalse($behavior->captures(new TypeError('a PHP Error never falls back implicitly')));
    }

    public function testDisablingDeclarationNeedsNoCaptureSet(): void
    {
        $disabled = new StaleIfError(enabled: false);

        self::assertFalse($disabled->enabled);
        self::assertSame([], $disabled->exceptions);
    }
}
