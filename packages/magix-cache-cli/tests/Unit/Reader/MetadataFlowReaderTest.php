<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Reader;

use Magix\Cache\Cli\Graph\TtlEstimateState;
use Magix\Cache\Cli\Reader\MetadataFlowReader;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Tests\Package\Cli\Fixture\AnalysisSource;

#[CoversClass(MetadataFlowReader::class)]
#[UsesNamespace('Magix\Cache')]
final class MetadataFlowReaderTest extends TestCase
{
    public function testReadDistinguishesReturningCachedFromReturningItsValue(): void
    {
        $pass = AnalysisSource::node('return $this->inputs->a();');
        $detached = AnalysisSource::node('return Cached::of($this->inputs->a()->value());', '#[Cache(ttl: 120)]');
        self::assertSame(20, $pass->children[0]->effect->ttl->seconds);
        self::assertSame(TtlEstimateState::Unconstrained, $detached->children[0]->effect->ttl->state);
        self::assertSame([], $pass->gaps);
        self::assertSame([], $detached->gaps);
    }

    public function testReturnsNoMetadataRulesOutTypesThatCannotCarryIt(): void
    {
        $code = <<<'SOURCE'
            <?php
            class Shapes {
                public function scalar(): int { return 1; }
                public function other(): \Tests\Other { return new \Tests\Other(); }
                public function cached(): \Magix\Cache\Cached { return \Magix\Cache\Cached::of(1); }
                public function nullable(): ?\Magix\Cache\Cached { return null; }
                public function untyped() { return 1; }
                public function object(): object { return \Magix\Cache\Cached::of(1); }
            }
            SOURCE;
        $statements = (new NodeTraverser(new NameResolver()))->traverse(
            (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [],
        );
        $class = (new NodeFinder())->findFirstInstanceOf($statements, Class_::class);
        self::assertInstanceOf(Class_::class, $class);
        $reader = new MetadataFlowReader();
        $scalar = $class->getMethod('scalar');
        $other = $class->getMethod('other');
        $cached = $class->getMethod('cached');
        $nullable = $class->getMethod('nullable');
        $untyped = $class->getMethod('untyped');
        $object = $class->getMethod('object');
        self::assertInstanceOf(ClassMethod::class, $scalar);
        self::assertInstanceOf(ClassMethod::class, $other);
        self::assertInstanceOf(ClassMethod::class, $cached);
        self::assertInstanceOf(ClassMethod::class, $nullable);
        self::assertInstanceOf(ClassMethod::class, $untyped);
        self::assertInstanceOf(ClassMethod::class, $object);

        self::assertTrue($reader->returnsNoMetadata($scalar));
        self::assertTrue($reader->returnsNoMetadata($other));
        self::assertFalse($reader->returnsNoMetadata($cached));
        self::assertFalse($reader->returnsNoMetadata($nullable));
        self::assertFalse($reader->returnsNoMetadata($untyped));
        self::assertFalse($reader->returnsNoMetadata($object));
    }
}
