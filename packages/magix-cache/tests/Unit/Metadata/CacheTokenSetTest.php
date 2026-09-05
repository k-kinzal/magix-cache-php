<?php

declare(strict_types=1);

namespace Tests\Unit\Metadata;

use Magix\Cache\Metadata\CacheTokenSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheTokenSet::class)]
final class CacheTokenSetTest extends TestCase
{
    public function testTagsAreUniqueAndSorted(): void
    {
        self::assertSame(['a', 'b'], (new CacheTokenSet())->tags(['b', 'a', 'b']));
    }

    public function testReasonsAreUniqueAndSorted(): void
    {
        self::assertSame(['first', 'second'], (new CacheTokenSet())->reasons(['second', 'first', 'second']));
    }
}
