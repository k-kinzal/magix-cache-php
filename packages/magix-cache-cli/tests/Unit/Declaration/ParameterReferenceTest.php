<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Declaration;

use Magix\Cache\Cli\Declaration\ParameterReference;
use Magix\Cache\Cli\Declaration\UseStrategyDeclaration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ParameterReference::class)]
#[UsesClass(UseStrategyDeclaration::class)]
final class ParameterReferenceTest extends TestCase
{
    public function testReferenceKeepsItsSourceVisibleInTheFactoryLabel(): void
    {
        $use = new UseStrategyDeclaration('App\Strategy', ['min' => new ParameterReference('ttl')]);

        self::assertSame('Strategy::create(min: $ttl)', $use->label());
    }
}
