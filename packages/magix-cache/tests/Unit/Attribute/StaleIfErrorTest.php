<?php

declare(strict_types=1);

namespace Tests\Unit\Attribute;

use Magix\Cache\Attribute\StaleIfError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(StaleIfError::class)]
final class StaleIfErrorTest extends TestCase
{
    public function testDeclarationCarriesTheAcceptedOriginFailureTypes(): void
    {
        $behavior = new StaleIfError(maxAge: 300, exceptions: [RuntimeException::class]);

        self::assertSame(300, $behavior->maxAge);
        self::assertSame([RuntimeException::class], $behavior->exceptions);
        self::assertTrue($behavior->enabled);
    }

    public function testDisablingDeclarationNeedsNoCaptureSet(): void
    {
        $disabled = new StaleIfError(enabled: false);

        self::assertFalse($disabled->enabled);
        self::assertSame([], $disabled->exceptions);
    }
}
