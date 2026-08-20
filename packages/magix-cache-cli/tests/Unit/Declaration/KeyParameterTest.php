<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Declaration;

use Magix\Cache\Cli\Declaration\KeyParameter;
use Magix\Cache\Runtime\Metadata\Visibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(KeyParameter::class)]
final class KeyParameterTest extends TestCase
{
    public function testLabelListsTheAttributesThatShapeTheKey(): void
    {
        $parameter = new KeyParameter(
            name: 'viewerId',
            type: 'int',
            ignored: false,
            scope: Visibility::Private,
            reducer: 'App\Reducer::parity',
        );

        self::assertSame('$viewerId (scope private, reduced by App\Reducer::parity)', $parameter->label());
    }

    public function testLabelRendersVariadicParametersWithoutAttributes(): void
    {
        $parameter = new KeyParameter(name: 'rest', variadic: true, optional: true);

        self::assertSame('...$rest', $parameter->label());
        self::assertTrue($parameter->optional);
    }
}
