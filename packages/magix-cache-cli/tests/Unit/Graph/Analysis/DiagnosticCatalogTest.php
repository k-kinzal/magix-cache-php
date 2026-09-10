<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Graph\Analysis;

use Magix\Cache\Cli\Graph\Analysis\DiagnosticCatalog;
use Magix\Cache\Cli\Render\TreeFilter;
use Magix\Cache\Cli\Render\UncachedMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Tests\Package\Cli\Fixture\ReportSource;

#[CoversClass(DiagnosticCatalog::class)]
#[UsesNamespace('Magix\Cache')]
final class DiagnosticCatalogTest extends TestCase
{
    public function testCollectDeduplicatesSharedCausesAndKeepsHiddenReferencedOrigins(): void
    {
        $node = ReportSource::node('Page::multiple');
        $selected = (new TreeFilter(uncached: UncachedMode::None))->apply($node);
        $causes = (new DiagnosticCatalog())->collect($selected);
        self::assertCount(1, $causes);
        self::assertSame('Bridge::get', array_values($causes)[0]->method);
        self::assertSame(array_keys($node->effect->analysis->tags), array_keys($causes));
        self::assertSame([], (new DiagnosticCatalog())->collect((new TreeFilter(uncached: UncachedMode::None))->apply(ReportSource::node('Page::unrelated'))));
    }
}
