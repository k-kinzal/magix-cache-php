<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Lint\Rule;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\Catalog;
use Magix\Cache\Cli\Declaration\KeyParameter;
use Magix\Cache\Cli\Declaration\ParameterConfiguration;
use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Lint\Rule\ParameterBindingRule;
use Magix\Cache\Cli\Lint\Severity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;

#[CoversClass(ParameterBindingRule::class)]
#[UsesNamespace('Magix\Cache')]
final class ParameterBindingRuleTest extends TestCase
{
    public function testCheckReportsInvalidBindingsAtTheirBoundaryLocation(): void
    {
        $boundary = new BoundaryDeclaration('Query', 'fetch', 'query.php', 12, parameters: [
            new KeyParameter('ttl', 'int', ignored: true, configuration: new ParameterConfiguration(ttl: true)),
        ]);
        $diagnostics = (new ParameterBindingRule())->check(new CacheNode($boundary, new CacheEffect()), new Catalog([]));

        self::assertCount(1, $diagnostics);
        self::assertSame('invalid-parameter-binding', $diagnostics[0]->rule);
        self::assertSame(Severity::Error, $diagnostics[0]->severity);
        self::assertSame('query.php', $diagnostics[0]->file);
        self::assertSame(12, $diagnostics[0]->line);
    }
}
