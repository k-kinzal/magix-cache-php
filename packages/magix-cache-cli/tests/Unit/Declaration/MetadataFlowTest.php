<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Declaration;

use Magix\Cache\Cli\Declaration\MetadataFlow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;

#[CoversClass(MetadataFlow::class)]
#[UsesNamespace('Magix\Cache')]
final class MetadataFlowTest extends TestCase
{
    public function testReferencesFollowsAlternativesButNotDiscardedCalls(): void
    {
        $flow = new MetadataFlow('choice', [new MetadataFlow('call', target: 'A::get'), new MetadataFlow('none')]);
        self::assertTrue($flow->references('A::get'));
        self::assertFalse($flow->references('B::get'));
    }

    public function testHasUnknownFindsAnOpaqueBranchWithinComposition(): void
    {
        self::assertTrue((new MetadataFlow('meet', [new MetadataFlow('choice', [new MetadataFlow('unknown')])]))->hasUnknown());
        self::assertFalse((new MetadataFlow('value', [new MetadataFlow('call', target: 'A::get')]))->hasUnknown());
    }
    public function testUnknownRetainsItsInputReferencesAndLocation(): void
    {
        $flow = MetadataFlow::unknown('opaque-call', 7, [new MetadataFlow('call', target: 'A::get')]);
        self::assertTrue($flow->references('A::get'));
        self::assertSame(7, $flow->line);
        self::assertSame('opaque-call', $flow->reason);
    }
}
