<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Graph;

use Magix\Cache\Cli\Graph\StrategyEffect;
use Magix\Cache\Cli\Graph\StrategyStep;
use Magix\Cache\Cli\Graph\TtlEstimate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StrategyEffect::class)]
#[UsesClass(StrategyStep::class)]
#[UsesClass(TtlEstimate::class)]
#[UsesClass(\Magix\Cache\Cli\Graph\TtlInterval::class)]
#[UsesClass(\Magix\Cache\Cli\Graph\TtlRangeSet::class)]
final class StrategyEffectTest extends TestCase
{
    public function testCarriesTheAnalyzedCompositionOfADeclaredStrategy(): void
    {
        $ttl = TtlEstimate::known(45);
        $step = new StrategyStep('App\Spread', $ttl);

        $effect = new StrategyEffect('Composite::create(min: 45)', $ttl, [$step], true, ['a problem']);

        self::assertSame('Composite::create(min: 45)', $effect->label);
        self::assertSame($ttl, $effect->ttl);
        self::assertSame([$step], $effect->steps);
        self::assertTrue($effect->overridesExpiration);
        self::assertSame(['a problem'], $effect->problems);
    }

    public function testLeavesEverythingUndeclaredOpenByDefault(): void
    {
        $ttl = TtlEstimate::unconstrained();

        $effect = new StrategyEffect('', $ttl);

        self::assertSame('', $effect->label);
        self::assertSame($ttl, $effect->ttl);
        self::assertSame([], $effect->steps);
        self::assertNull($effect->overridesExpiration);
        self::assertSame([], $effect->problems);
    }
}
