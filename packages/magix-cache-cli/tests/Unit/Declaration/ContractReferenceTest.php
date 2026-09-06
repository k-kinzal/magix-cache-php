<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Declaration;

use Magix\Cache\Cli\Declaration\ContractReference;
use Magix\Cache\Cli\Declaration\ContractSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContractReference::class)]
final class ContractReferenceTest extends TestCase
{
    public function testLabelRendersAConstructorReferenceAsDeclared(): void
    {
        $reference = new ContractReference(ContractSource::Constructor, 'minimum');

        self::assertSame(ContractSource::Constructor, $reference->source);
        self::assertSame('minimum', $reference->name);
        self::assertSame("ConstructorArg('minimum')", $reference->label());
    }

    public function testLabelRendersACreateReferenceAsDeclared(): void
    {
        self::assertSame("Arg('min')", (new ContractReference(ContractSource::Create, 'min'))->label());
    }
}
