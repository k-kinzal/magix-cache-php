<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Reader;

use Magix\Cache\Attribute\CacheTtl;
use Magix\Cache\Attribute\StrategyArgument;
use Magix\Cache\Cli\Reader\ParameterConfigurationReader;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;

#[CoversClass(ParameterConfigurationReader::class)]
#[UsesNamespace('Magix\Cache')]
final class ParameterConfigurationReaderTest extends TestCase
{
    public function testReadKeepsAllDestinationsWithoutExecutingTheAttributes(): void
    {
        $groups = [new AttributeGroup([
            new Attribute(new Name(CacheTtl::class)),
            new Attribute(new Name(StrategyArgument::class), [new Arg(new String_('minimum'))]),
        ])];
        $configuration = (new ParameterConfigurationReader())->read($groups);

        self::assertNotNull($configuration);
        self::assertTrue($configuration->ttl);
        self::assertSame('minimum', $configuration->strategyArgument);
        self::assertSame([], $configuration->problems);
    }

    public function testReadReportsRepeatedAttributesAsDeclarations(): void
    {
        $configuration = (new ParameterConfigurationReader())->read([new AttributeGroup([
            new Attribute(new Name(CacheTtl::class)),
            new Attribute(new Name(CacheTtl::class)),
        ])]);

        self::assertNotNull($configuration);
        self::assertCount(1, $configuration->problems);
    }

    public function testTargetAcceptsTheNamedDestinationArgument(): void
    {
        $attribute = new Attribute(new Name(StrategyArgument::class), [
            new Arg(new String_('min'), name: new Identifier('name')),
        ]);

        self::assertSame('min', (new ParameterConfigurationReader())->target($attribute));
    }
}
