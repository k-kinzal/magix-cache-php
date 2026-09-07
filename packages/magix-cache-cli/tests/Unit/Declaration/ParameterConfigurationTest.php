<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Declaration;

use Magix\Cache\Cli\Declaration\ParameterConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ParameterConfiguration::class)]
final class ParameterConfigurationTest extends TestCase
{
    public function testLabelShowsEveryConfigurationDestination(): void
    {
        $configuration = new ParameterConfiguration(ttl: true, strategyArgument: 'minimum');

        self::assertSame('cache ttl, strategy minimum', $configuration->label());
        self::assertSame('cache tags', (new ParameterConfiguration(tags: true))->label());
        self::assertSame('cache visibility', (new ParameterConfiguration(visibility: true))->label());
    }
}
