<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Graph;

use Magix\Cache\Cli\Declaration\MetadataContract;
use Magix\Cache\Cli\Graph\StrategyStep;
use Magix\Cache\Cli\Graph\TtlEstimate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StrategyStep::class)]
#[UsesClass(MetadataContract::class)]
#[UsesClass(TtlEstimate::class)]
#[UsesClass(\Magix\Cache\Cli\Graph\TtlInterval::class)]
#[UsesClass(\Magix\Cache\Cli\Graph\TtlRangeSet::class)]
final class StrategyStepTest extends TestCase
{
    public function testShortNameStripsTheNamespace(): void
    {
        $ttl = TtlEstimate::known(60);

        $step = new StrategyStep('App\Spread', $ttl);

        self::assertSame('Spread', $step->shortName());
        self::assertSame('App\Spread', $step->strategy);
        self::assertSame($ttl, $step->ttl);
        self::assertFalse($step->assumed);
    }

    public function testShortNameKeepsAnUnqualifiedName(): void
    {
        $step = new StrategyStep('Spread', TtlEstimate::unconstrained(), assumed: true);

        self::assertSame('Spread', $step->shortName());
        self::assertTrue($step->assumed);
    }
}
