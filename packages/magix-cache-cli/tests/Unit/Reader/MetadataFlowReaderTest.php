<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Reader;

use Magix\Cache\Cli\Graph\TtlEstimateState;
use Magix\Cache\Cli\Reader\MetadataFlowReader;
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
}
