<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Graph\Analysis;

use Magix\Cache\Cli\Graph\Analysis\AnalysisCause;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Tests\Package\Cli\Fixture\ReportSource;

#[CoversClass(AnalysisCause::class)]
#[UsesNamespace('Magix\Cache')]
final class AnalysisCauseTest extends TestCase
{
    public function testAtKeepsOneIdentityAcrossConsumersAndLocatesTheExpression(): void
    {
        $owner = ReportSource::node('Bridge::get')->boundary;
        $cause = AnalysisCause::at($owner, 'opaque-call', 'Unknown transformation', 9);
        self::assertSame(9, $cause->line);
        self::assertSame('report.php', $cause->file);
        self::assertSame($cause->id, AnalysisCause::at($owner, 'opaque-call', 'Unknown transformation', 9)->id);
        self::assertNotSame($cause->id, AnalysisCause::at($owner, 'opaque-call', 'Unknown transformation', 10)->id);
    }

    public function testJsonSerializeIncludesTheOriginWithoutConsumerSpecificCopies(): void
    {
        $cause = AnalysisCause::at(ReportSource::node('Bridge::get')->boundary, 'opaque-call', 'Unknown transformation');
        $data = $cause->jsonSerialize();
        self::assertSame('Bridge::get', $data['method']);
        self::assertSame($cause->id, $data['id']);
        self::assertSame('opaque-call', $data['kind']);
    }
}
