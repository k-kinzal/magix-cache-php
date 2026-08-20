<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Lint;

use Magix\Cache\Cli\Lint\Diagnostic;
use Magix\Cache\Cli\Lint\Severity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Diagnostic::class)]
final class DiagnosticTest extends TestCase
{
    public function testDiagnosticPointsAtTheBoundaryItDescribes(): void
    {
        $diagnostic = new Diagnostic(
            rule: 'missing-policy',
            severity: Severity::Error,
            boundary: 'App\PageQuery::execute',
            file: 'src/PageQuery.php',
            line: 31,
            message: 'cached() is called without a policy.',
            hint: 'Add #[Cache].',
        );

        self::assertSame('missing-policy', $diagnostic->rule);
        self::assertSame(Severity::Error, $diagnostic->severity);
        self::assertSame('App\PageQuery::execute', $diagnostic->boundary);
        self::assertSame('src/PageQuery.php', $diagnostic->file);
        self::assertSame(31, $diagnostic->line);
        self::assertSame('cached() is called without a policy.', $diagnostic->message);
        self::assertSame('Add #[Cache].', $diagnostic->hint);
    }
}
