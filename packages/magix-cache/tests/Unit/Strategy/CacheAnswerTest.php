<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy;

use Magix\Cache\Cached;
use Magix\Cache\Metadata\CacheMetadata;
use Magix\Cache\Strategy\CacheAnswer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheAnswer::class)]
#[UsesClass(Cached::class)]
#[UsesClass(CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheTokenSet::class)]
#[UsesClass(\Magix\Cache\Metadata\Visibility::class)]
final class CacheAnswerTest extends TestCase
{
    public function testAnswerKeepsItsOriginalConstraints(): void
    {
        $cached = Cached::of('old', new CacheMetadata(expiresAt: 90.0));
        $answer = new CacheAnswer($cached, 'StaleServed');

        self::assertSame($cached, $answer->cached);
        self::assertSame('StaleServed', $answer->event);
    }
}
