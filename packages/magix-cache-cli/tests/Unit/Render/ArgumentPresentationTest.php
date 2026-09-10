<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Render;

use JsonException;
use Magix\Cache\Cli\Declaration\ParameterReference;
use Magix\Cache\Cli\Declaration\Unresolved;
use Magix\Cache\Cli\Render\ArgumentPresentation;
use Magix\Cache\Runtime\Policy\Ttl;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArgumentPresentation::class)]
#[UsesNamespace('Magix\Cache')]
final class ArgumentPresentationTest extends TestCase
{
    /**
     * @throws JsonException
     */
    public function testDataKeepsUnknownNullEnumsAndRuntimeArgumentsDistinct(): void
    {
        $data = (new ArgumentPresentation())->data([
            'unknown' => Unresolved::Value,
            'null' => null,
            'enum' => Ttl::Auto,
            'parameter' => new ParameterReference('ttl'),
            'literal' => ['state' => 'unknown'],
        ]);
        self::assertSame(['state' => 'known', 'items' => [
            'unknown' => ['state' => 'unknown'],
            'null' => ['state' => 'known', 'value' => null],
            'enum' => ['state' => 'known', 'enum' => Ttl::class, 'case' => 'Auto'],
            'parameter' => ['state' => 'runtime', 'parameter' => 'ttl'],
            'literal' => ['state' => 'known', 'items' => ['state' => ['state' => 'known', 'value' => 'unknown']]],
        ]], $data);
        self::assertJson(json_encode($data, JSON_THROW_ON_ERROR));
    }
}
