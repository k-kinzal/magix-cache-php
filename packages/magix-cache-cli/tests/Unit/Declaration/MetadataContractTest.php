<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Declaration;

use Magix\Cache\Cli\Declaration\MetadataContract;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MetadataContract::class)]
final class MetadataContractTest extends TestCase
{
    public function testPreservesEverythingHoldsForAnOperationThatDeclaresNoWrites(): void
    {
        $contract = new MetadataContract();

        self::assertTrue($contract->preservesEverything());
        self::assertFalse($contract->visibility);
    }

    public function testUndescribedWritesEveryField(): void
    {
        $contract = MetadataContract::undescribed();

        self::assertFalse($contract->preservesEverything());
        self::assertTrue($contract->visibility);
        self::assertTrue($contract->tags);
        self::assertTrue($contract->cacheable);
    }

    public function testMergeKeepsTheFieldsEitherOperationWrites(): void
    {
        $merged = (new MetadataContract(visibility: true))->merge(new MetadataContract(tags: true));

        self::assertTrue($merged->visibility);
        self::assertTrue($merged->tags);
        self::assertFalse($merged->cacheable);
    }
}
