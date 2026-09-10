<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Render;

use Magix\Cache\Cli\Render\AlternativePresentation;
use Magix\Cache\Cli\Render\IgnorePattern;
use Magix\Cache\Cli\Render\JsonRenderer;
use Magix\Cache\Cli\Render\MermaidRenderer;
use Magix\Cache\Cli\Render\TreeFilter;
use Magix\Cache\Cli\Render\TreeRenderer;
use Magix\Cache\Cli\Render\UncachedMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Tests\Package\Cli\Fixture\AnalysisSource;

#[CoversClass(AlternativePresentation::class)]
#[UsesNamespace('Magix\Cache')]
final class AlternativePresentationTest extends TestCase
{
    public function testLabelRendersDisjunctionsInTerminalAndDiagram(): void
    {
        $node = AnalysisSource::node('return $flag ? $this->inputs->a() : $this->inputs->b();');
        $label = (new AlternativePresentation())->label($node);
        self::assertSame('Inputs::a [ttl 20s, shared, tags a] or Inputs::b [ttl 60s, private, tags b]', $label);
        self::assertStringContainsString('ttl 20/60s', (new TreeRenderer())->render($node));
        self::assertStringNotContainsString('alternatives:', (new TreeRenderer())->render($node));
        self::assertStringContainsString('20/60s', (new MermaidRenderer())->render($node));
    }

    public function testDataPreservesCorrelatedMetadataWhenDisplayHidesChildren(): void
    {
        $node = AnalysisSource::node('return $flag ? $this->inputs->a() : $this->inputs->b();');
        $before = (new JsonRenderer())->tree($node);
        $filtered = (new TreeFilter([new IgnorePattern('Inputs::*')], UncachedMode::None))->apply($node);
        self::assertCount(1, $filtered);
        self::assertSame($before['metadataAlternatives'], (new JsonRenderer())->tree($filtered[0])['metadataAlternatives']);
        self::assertSame($node->effect, $filtered[0]->effect);
    }
    public function testVariantNamesAnUnconstrainedReturnWithoutInventingADependency(): void
    {
        $node = AnalysisSource::node('return Cached::of(1);', '#[Cache(ttl: 120)]');
        self::assertNotNull($node->metadataVariants);
        self::assertSame('no cache dependency [ttl 120s, shared, tags -]', (new AlternativePresentation())->variant($node->metadataVariants[0]));
    }

}
