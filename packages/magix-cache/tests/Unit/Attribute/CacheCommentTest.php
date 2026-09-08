<?php

declare(strict_types=1);

namespace Tests\Unit\Attribute;

use Magix\Cache\Attribute\CacheComment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheComment::class)]
final class CacheCommentTest extends TestCase
{
    public function testCommentPreservesMigrationNotesAndExplicitEmptyOverrides(): void
    {
        self::assertSame("移行中\n比較を続ける", (new CacheComment("移行中\n比較を続ける"))->comment);
        self::assertSame('Bubbling enabled', (new CacheComment(comment: 'Bubbling enabled'))->comment);
        self::assertSame('', (new CacheComment(''))->comment);
    }
}
