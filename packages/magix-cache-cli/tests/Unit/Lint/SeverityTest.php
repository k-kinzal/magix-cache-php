<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Lint;

use Magix\Cache\Cli\Lint\Severity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Severity::class)]
final class SeverityTest extends TestCase
{
    public function testSeveritiesAreNamedForOutput(): void
    {
        self::assertSame('error', Severity::Error->value);
        self::assertSame('warning', Severity::Warning->value);
        self::assertSame('notice', Severity::Notice->value);
    }
}
