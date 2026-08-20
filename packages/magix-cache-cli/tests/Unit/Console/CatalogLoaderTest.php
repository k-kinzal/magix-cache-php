<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Console;

use Magix\Cache\Cli\Console\CatalogLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;

#[CoversClass(CatalogLoader::class)]
#[UsesNamespace('Magix\Cache\Cli')]
final class CatalogLoaderTest extends TestCase
{
    public function testLoadCollectsEveryBoundaryBelowThePath(): void
    {
        $catalog = (new CatalogLoader(dirname(__DIR__, 5)))
            ->load(['packages/magix-cache-cli/tests/Fixture/Project']);

        $boundaries = $catalog->search('ProductQuery::execute');

        self::assertCount(1, $boundaries);
        self::assertSame(20, $boundaries[0]->policy?->ttl);
        self::assertSame('packages/magix-cache-cli/tests/Fixture/Project/ProductQuery.php', $boundaries[0]->file);
    }

    public function testLoadIgnoresPathsThatDoNotExist(): void
    {
        $catalog = (new CatalogLoader(dirname(__DIR__, 2).'/Fixture/Project'))->load(['missing', 123]);

        self::assertNotSame([], $catalog->boundaries());
    }
}
