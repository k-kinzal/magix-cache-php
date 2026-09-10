<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Graph\Analysis;

use Magix\Cache\Cli\Graph\Analysis\CallAnalysis;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Tests\Package\Cli\Fixture\ReportSource;

#[CoversClass(CallAnalysis::class)]
#[UsesNamespace('Magix\Cache')]
final class CallAnalysisTest extends TestCase
{
    public function testFromCallsKeepsCallStructureWhenMetadataCannotBeFollowed(): void
    {
        $node = ReportSource::node('Page::automatic');
        self::assertSame(['operation', 'resolved'], array_map(static fn (CallAnalysis $call): string => $call->resolution, $node->calls));
        self::assertSame(['Bridge::get'], $node->calls[1]->candidates);
        self::assertNotEmpty($node->effect->analysis->causes());
    }

    public function testJsonSerializeDistinguishesAnUnresolvedCallFromNoCall(): void
    {
        $call = new CallAnalysis(null, 9, [], 'unresolved', 'fetch');
        self::assertSame(['target' => null, 'line' => 9, 'candidates' => [], 'resolution' => 'unresolved', 'method' => 'fetch'], $call->jsonSerialize());
    }
}
