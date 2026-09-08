<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Declaration;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\Catalog;
use Magix\Cache\Cli\Declaration\ClassDeclaration;
use Magix\Cache\Cli\Declaration\StrategyDeclaration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Catalog::class)]
#[UsesClass(BoundaryDeclaration::class)]
#[UsesClass(ClassDeclaration::class)]
#[UsesClass(StrategyDeclaration::class)]
final class CatalogTest extends TestCase
{
    public function testEntryPointsAreOptInAndNeverListedAsCacheBoundaries(): void
    {
        $entryPoint = new BoundaryDeclaration('App\ProductController', 'show', 'a.php', 1, isCacheBoundary: false);
        $cached = new BoundaryDeclaration('App\ProductController', 'query', 'a.php', 10);
        $catalog = new Catalog([
            new ClassDeclaration('App\ProductController', boundaries: [$cached], entryPoints: [$entryPoint]),
        ]);

        self::assertSame([$cached], $catalog->boundaries());
        self::assertSame([], $catalog->search('ProductController::show'));
        self::assertSame([], $catalog->candidates('App\ProductController', 'show'));
        self::assertSame([$entryPoint], $catalog->search('App\ProductController::show', includeEntryPoints: true));
        self::assertSame([$entryPoint], $catalog->candidates('App\ProductController', 'show', includeEntryPoints: true));
        self::assertSame([$cached, $entryPoint], $catalog->search('ProductController', includeEntryPoints: true));
    }

    public function testBoundariesAreSortedByIdentifier(): void
    {
        $catalog = new Catalog([
            new ClassDeclaration('App\ProductQuery', [], [new BoundaryDeclaration('App\ProductQuery', 'execute', 'a.php', 1)]),
            new ClassDeclaration('App\FeedQuery', [], [new BoundaryDeclaration('App\FeedQuery', 'execute', 'b.php', 1)]),
        ]);

        self::assertSame(
            ['App\FeedQuery::execute', 'App\ProductQuery::execute'],
            array_map(static fn (BoundaryDeclaration $boundary): string => $boundary->id(), $catalog->boundaries()),
        );
    }

    public function testCandidatesResolveImplementationsOfAnInterface(): void
    {
        $catalog = new Catalog([
            new ClassDeclaration('App\FeedQuery'),
            new ClassDeclaration('App\RecentFeedQuery', ['App\FeedQuery'], [
                new BoundaryDeclaration('App\RecentFeedQuery', 'execute', 'b.php', 1),
            ]),
        ]);

        $candidates = $catalog->candidates('App\FeedQuery', 'execute');

        self::assertCount(1, $candidates);
        self::assertSame('App\RecentFeedQuery::execute', $candidates[0]->id());
        self::assertSame([], $catalog->candidates('App\FeedQuery', 'missing'));
    }

    public function testSearchMatchesShortAndQualifiedReferences(): void
    {
        $catalog = new Catalog([
            new ClassDeclaration('App\Query\ProductQuery', [], [
                new BoundaryDeclaration('App\Query\ProductQuery', 'execute', 'a.php', 1),
                new BoundaryDeclaration('App\Query\ProductQuery', 'preview', 'a.php', 20),
            ]),
        ]);

        self::assertCount(1, $catalog->search('ProductQuery::execute'));
        self::assertCount(2, $catalog->search('App\Query\ProductQuery'));
        self::assertSame([], $catalog->search('Missing::execute'));
    }

    public function testStrategyIsIndexedByItsClassName(): void
    {
        $declared = new StrategyDeclaration('App\Cache\SpreadStrategy');
        $catalog = new Catalog([
            new ClassDeclaration('App\Cache\SpreadStrategy', [], [], $declared),
            new ClassDeclaration('App\Query\ProductQuery'),
        ]);

        self::assertSame($declared, $catalog->strategy('App\Cache\SpreadStrategy'));
        self::assertNull($catalog->strategy('App\Query\ProductQuery'));
        self::assertNull($catalog->strategy('App\Missing'));
    }
}
