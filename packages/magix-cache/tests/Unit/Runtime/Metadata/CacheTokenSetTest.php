<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime\Metadata;

use InvalidArgumentException;
use Magix\Cache\Runtime\Metadata\CacheTokenSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheTokenSet::class)]
final class CacheTokenSetTest extends TestCase
{
    public function testTagsAreUniqueAndSorted(): void
    {
        self::assertSame(['a', 'b'], (new CacheTokenSet())->tags(['b', 'a', 'b']));
    }

    public function testTagsRejectUnsafeHeaderCharacters(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CacheTokenSet())->tags(["bad\ntag"]);
    }

    public function testReasonsAreUniqueAndSorted(): void
    {
        self::assertSame(['first', 'second'], (new CacheTokenSet())->reasons(['second', 'first', 'second']));
    }
}
