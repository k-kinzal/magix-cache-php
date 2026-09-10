<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Render;

use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\TtlEstimate;
use Magix\Cache\Cli\Render\NodePresentation;
use Magix\Cache\Metadata\Visibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Tests\Package\Cli\Fixture\ReportSource;

#[CoversClass(NodePresentation::class)]
#[UsesNamespace('Magix\Cache')]
#[Medium]
final class NodePresentationTest extends TestCase
{
    public function testColorUsesParticipationInsteadOfDescendantDiagnosticCounts(): void
    {
        $presentation = new NodePresentation();
        self::assertSame('white', $presentation->color(ReportSource::node('Page::unrelated')));
        self::assertSame('white', $presentation->color(ReportSource::node('Page::automatic')));
        self::assertSame('white', $presentation->color(ReportSource::node('Migration::get')));
        self::assertSame('gray', $presentation->color(ReportSource::node('TypedOnly::get')));
    }

    public function testStorageUsesTheResultFieldsEvenWhenHiddenMethodsHaveLimits(): void
    {
        $presentation = new NodePresentation();
        self::assertSame('yes', $presentation->storage(ReportSource::node('Page::unrelated')));
        self::assertSame('unknown', $presentation->storage(ReportSource::node('Page::fixed')));
        self::assertSame('no', $presentation->storage(ReportSource::node('Migration::get')));
    }

    public function testInvalidDistinguishesMissingProofFromDefinitionProblems(): void
    {
        $presentation = new NodePresentation();
        self::assertFalse($presentation->invalid(new CacheEffect(TtlEstimate::unknown())));
        self::assertTrue($presentation->invalid(ReportSource::node('Page::invalid')->effect));
    }

    public function testDisabledRequiresProvenNonStorage(): void
    {
        $presentation = new NodePresentation();
        self::assertFalse($presentation->disabled(new CacheEffect(TtlEstimate::unknown(lowerBound: 0, finite: true))));
        self::assertTrue($presentation->disabled(new CacheEffect(TtlEstimate::known(0))));
        self::assertTrue($presentation->disabled(new CacheEffect(TtlEstimate::known(30), Visibility::NoStore)));
    }

    public function testMermaidKeepsTheSameColorForPartialAndCompleteCacheNodes(): void
    {
        $presentation = new NodePresentation();
        self::assertSame($presentation->mermaid(ReportSource::node('Page::clean')), $presentation->mermaid(ReportSource::node('Page::automatic')));
    }
}
