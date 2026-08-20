<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Lint\Rule;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\Catalog;
use Magix\Cache\Cli\Declaration\ClassDeclaration;
use Magix\Cache\Cli\Declaration\DependencyCall;
use Magix\Cache\Cli\Declaration\KeyParameter;
use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Lint\Diagnostic;
use Magix\Cache\Cli\Lint\Rule\UnscopedPrivateKeyRule;
use Magix\Cache\Cli\Lint\Severity;
use Magix\Cache\Runtime\Metadata\Visibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnscopedPrivateKeyRule::class)]
#[UsesClass(BoundaryDeclaration::class)]
#[UsesClass(CacheEffect::class)]
#[UsesClass(CacheNode::class)]
#[UsesClass(Catalog::class)]
#[UsesClass(ClassDeclaration::class)]
#[UsesClass(DependencyCall::class)]
#[UsesClass(Diagnostic::class)]
#[UsesClass(KeyParameter::class)]
final class UnscopedPrivateKeyRuleTest extends TestCase
{
    public function testCheckReportsAPrivateDependencyThatTheKeyCannotSeparate(): void
    {
        $viewer = new BoundaryDeclaration(
            class: 'App\ViewerQuery',
            method: 'execute',
            file: 'src/ViewerQuery.php',
            line: 12,
            parameters: [new KeyParameter('viewerId', scope: Visibility::Private)],
        );
        $dashboard = new BoundaryDeclaration(
            class: 'App\DashboardQuery',
            method: 'execute',
            file: 'src/DashboardQuery.php',
            line: 31,
            parameters: [new KeyParameter('section')],
            dependencies: [new DependencyCall('App\ViewerQuery', 'execute', 36)],
        );
        $catalog = new Catalog([
            new ClassDeclaration('App\ViewerQuery', [], [$viewer]),
            new ClassDeclaration('App\DashboardQuery', [], [$dashboard]),
        ]);

        $diagnostics = (new UnscopedPrivateKeyRule())->check(new CacheNode($dashboard, new CacheEffect()), $catalog);

        self::assertCount(1, $diagnostics);
        self::assertSame(Severity::Warning, $diagnostics[0]->severity);
        self::assertSame(36, $diagnostics[0]->line);
        self::assertStringContainsString('ViewerQuery::execute', $diagnostics[0]->message);
    }

    public function testCheckAcceptsAScopedParameterCallersCannotChange(): void
    {
        $viewer = new BoundaryDeclaration(
            class: 'App\ViewerQuery',
            method: 'execute',
            file: 'src/ViewerQuery.php',
            line: 12,
            parameters: [new KeyParameter('viewerId', scope: Visibility::Private, optional: true)],
        );
        $dashboard = new BoundaryDeclaration(
            class: 'App\DashboardQuery',
            method: 'execute',
            file: 'src/DashboardQuery.php',
            line: 31,
            parameters: [new KeyParameter('section')],
            dependencies: [new DependencyCall('App\ViewerQuery', 'execute', 36)],
        );
        $catalog = new Catalog([
            new ClassDeclaration('App\ViewerQuery', [], [$viewer]),
            new ClassDeclaration('App\DashboardQuery', [], [$dashboard]),
        ]);

        self::assertSame([], (new UnscopedPrivateKeyRule())->check(new CacheNode($dashboard, new CacheEffect()), $catalog));
    }

    public function testCheckAcceptsAForwardedScopedParameter(): void
    {
        $viewer = new BoundaryDeclaration(
            class: 'App\ViewerQuery',
            method: 'execute',
            file: 'src/ViewerQuery.php',
            line: 12,
            parameters: [new KeyParameter('viewerId', scope: Visibility::Private)],
        );
        $page = new BoundaryDeclaration(
            class: 'App\PageQuery',
            method: 'execute',
            file: 'src/PageQuery.php',
            line: 31,
            parameters: [new KeyParameter('viewerId')],
            dependencies: [new DependencyCall('App\ViewerQuery', 'execute', 36, [0 => 'viewerId'])],
        );
        $catalog = new Catalog([
            new ClassDeclaration('App\ViewerQuery', [], [$viewer]),
            new ClassDeclaration('App\PageQuery', [], [$page]),
        ]);

        self::assertSame([], (new UnscopedPrivateKeyRule())->check(new CacheNode($page, new CacheEffect()), $catalog));
    }
}
