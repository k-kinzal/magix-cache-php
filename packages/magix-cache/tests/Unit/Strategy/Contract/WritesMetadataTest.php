<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy\Contract;

use Magix\Cache\Strategy\Contract\WritesMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WritesMetadata::class)]
final class WritesMetadataTest extends TestCase
{
    public function testNoArgumentsDeclaresEveryFieldReplacedWithAnUndescribedValue(): void
    {
        $contract = new WritesMetadata();

        self::assertTrue($contract->visibility);
        self::assertTrue($contract->tags);
        self::assertTrue($contract->cacheable);
    }

    public function testNamingFieldsDeclaresExactlyThoseFields(): void
    {
        $contract = new WritesMetadata(visibility: true);

        self::assertTrue($contract->visibility);
        self::assertFalse($contract->tags);
        self::assertFalse($contract->cacheable);
    }

    public function testAFieldNamedFalseIsDeclaredPreserved(): void
    {
        $contract = new WritesMetadata(visibility: false, tags: true);

        self::assertFalse($contract->visibility);
        self::assertTrue($contract->tags);
        self::assertFalse($contract->cacheable);
    }
}
