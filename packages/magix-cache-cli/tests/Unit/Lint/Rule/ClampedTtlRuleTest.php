<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Lint\Rule;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\Catalog;
use Magix\Cache\Cli\Declaration\PolicyDeclaration;
use Magix\Cache\Cli\Declaration\PolicySource;
use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Lint\Diagnostic;
use Magix\Cache\Cli\Lint\Rule\ClampedTtlRule;
use Magix\Cache\Cli\Lint\Severity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClampedTtlRule::class)]
#[UsesClass(BoundaryDeclaration::class)]
#[UsesClass(CacheEffect::class)]
#[UsesClass(CacheNode::class)]
#[UsesClass(Catalog::class)]
#[UsesClass(Diagnostic::class)]
#[UsesClass(PolicyDeclaration::class)]
final class ClampedTtlRuleTest extends TestCase
{
    public function testCheckReportsATtlThatIsAlwaysShortened(): void
    {
        $node = new CacheNode(
            new BoundaryDeclaration(
                class: 'App\PageQuery',
                method: 'execute',
                file: 'src/PageQuery.php',
                line: 31,
                policy: new PolicyDeclaration(PolicySource::MethodAttribute, 120),
            ),
            new CacheEffect(ttl: 20),
        );

        $diagnostics = (new ClampedTtlRule())->check($node, new Catalog([]));

        self::assertCount(1, $diagnostics);
        self::assertSame(Severity::Notice, $diagnostics[0]->severity);
        self::assertStringContainsString('120s', $diagnostics[0]->message);
        self::assertStringContainsString('20s', $diagnostics[0]->message);
    }

    public function testCheckAcceptsATtlThatCanBeReached(): void
    {
        $node = new CacheNode(
            new BoundaryDeclaration(
                class: 'App\PageQuery',
                method: 'execute',
                file: 'src/PageQuery.php',
                line: 31,
                policy: new PolicyDeclaration(PolicySource::MethodAttribute, 20),
            ),
            new CacheEffect(ttl: 20),
        );

        self::assertSame([], (new ClampedTtlRule())->check($node, new Catalog([])));
    }
}
