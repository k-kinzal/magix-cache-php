<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Source;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\Catalog;
use Magix\Cache\Cli\Declaration\ClassDeclaration;
use Magix\Cache\Cli\Declaration\DependencyCall;
use Magix\Cache\Cli\Declaration\KeyParameter;
use Magix\Cache\Cli\Declaration\PolicyDeclaration;
use Magix\Cache\Cli\Graph\CacheTree;
use Magix\Cache\Cli\Graph\TtlEstimateState;
use Magix\Cache\Cli\Lint\CacheLinter;
use Magix\Cache\Cli\Reader\ArgumentReader;
use Magix\Cache\Cli\Reader\AttributeReader;
use Magix\Cache\Cli\Reader\BoundaryReader;
use Magix\Cache\Cli\Reader\DependencyReader;
use Magix\Cache\Cli\Reader\LiteralReader;
use Magix\Cache\Cli\Reader\ParameterReader;
use Magix\Cache\Cli\Reader\PolicyReader;
use Magix\Cache\Cli\Reader\TypeReader;
use Magix\Cache\Cli\Render\JsonRenderer;
use Magix\Cache\Cli\Render\TreeRenderer;
use Magix\Cache\Cli\Source\ClassVisitor;
use Magix\Cache\Cli\Source\SourceParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Tests\Package\Cli\Fixture\ParameterizedStrategy;
use Tests\Package\Cli\Fixture\ParameterQuery;
use Tests\Package\Cli\Fixture\Project\ProductQuery;

#[CoversClass(SourceParser::class)]
#[UsesNamespace('Magix\Cache')]
#[UsesClass(ArgumentReader::class)]
#[UsesClass(AttributeReader::class)]
#[UsesClass(BoundaryDeclaration::class)]
#[UsesClass(BoundaryReader::class)]
#[UsesClass(ClassDeclaration::class)]
#[UsesClass(ClassVisitor::class)]
#[UsesClass(DependencyCall::class)]
#[UsesClass(DependencyReader::class)]
#[UsesClass(KeyParameter::class)]
#[UsesClass(LiteralReader::class)]
#[UsesClass(ParameterReader::class)]
#[UsesClass(PolicyDeclaration::class)]
#[UsesClass(PolicyReader::class)]
#[UsesClass(TypeReader::class)]
#[UsesClass(\Magix\Cache\Cli\Reader\ContractReader::class)]
#[UsesClass(\Magix\Cache\Cli\Reader\StrategyReader::class)]
#[UsesClass(\Magix\Cache\Cli\Reader\UseStrategyReader::class)]
final class SourceParserTest extends TestCase
{
    public function testParseReadsABoundaryFromARealFile(): void
    {
        $declarations = (new SourceParser())->parse(dirname(__DIR__, 2).'/Fixture/Project/ProductQuery.php');

        self::assertCount(1, $declarations);
        self::assertSame(ProductQuery::class, $declarations[0]->name);
        self::assertSame(20, $declarations[0]->boundaries[0]->policy?->ttl);
    }

    public function testParseRecordsTheDisplayPathOfEveryBoundary(): void
    {
        $declarations = (new SourceParser())->parse(
            dirname(__DIR__, 2).'/Fixture/Project/ProductQuery.php',
            'src/ProductQuery.php',
        );

        self::assertSame('src/ProductQuery.php', $declarations[0]->boundaries[0]->file);
    }

    public function testParseCarriesParameterConfigurationThroughAnalysisRenderingAndLint(): void
    {
        $parser = new SourceParser();
        $directory = dirname(__DIR__, 2).'/Fixture/';
        $before = ParameterizedStrategy::$calls;
        $catalog = new Catalog([
            ...$parser->parse($directory.'ParameterQuery.php'),
            ...$parser->parse($directory.'ParameterizedStrategy.php'),
        ]);
        $boundary = $catalog->candidates(ParameterQuery::class, 'fetch')[0];
        $node = (new CacheTree($catalog))->build($boundary);

        self::assertSame(TtlEstimateState::Unknown, $node->effect->ttl->state);
        self::assertSame(60, $node->effect->ttl->upperBound);
        self::assertTrue($node->effect->visibilityUnknown);
        self::assertTrue($node->effect->tagsUnknown);
        self::assertSame('ParameterizedStrategy::create(minimum: $min)', $node->effect->strategy?->label);
        $rendered = (new TreeRenderer())->render($node);

        self::assertStringContainsString('private or stricter', $rendered);
        self::assertStringContainsString('fixed + runtime tags', $rendered);
        self::assertStringContainsString('cache ttl', $rendered);
        $json = (new JsonRenderer())->tree($node);
        self::assertIsArray($json['effective']);
        self::assertTrue($json['effective']['tagsUnknown']);
        self::assertSame([], (new CacheLinter())->inspect($catalog));
        self::assertSame($before, ParameterizedStrategy::$calls, 'static analysis never executes create()');
    }
}
