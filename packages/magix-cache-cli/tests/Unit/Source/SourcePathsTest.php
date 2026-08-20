<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Source;

use Magix\Cache\Cli\Source\SourcePaths;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SourcePaths::class)]
final class SourcePathsTest extends TestCase
{
    public function testResolveTurnsRequestedPathsIntoAbsoluteDirectories(): void
    {
        $root = dirname(__DIR__, 5);
        $paths = (new SourcePaths($root))->resolve(['packages/magix-cache-cli/tests/Fixture/Project']);

        self::assertSame([$root.'/packages/magix-cache-cli/tests/Fixture/Project'], $paths);
    }

    public function testResolveFallsBackToTheWorkingDirectory(): void
    {
        $root = dirname(__DIR__, 5);

        self::assertSame([$root], (new SourcePaths($root))->resolve(['does/not/exist']));
    }

    public function testAutoloadPathsComeFromTheComposerManifest(): void
    {
        $paths = (new SourcePaths(dirname(__DIR__, 5)))->autoloadPaths();

        self::assertContains('packages/magix-cache/src/', $paths);
    }

    public function testAutoloadPathsDefaultToSourceWithoutAManifest(): void
    {
        self::assertSame(['src'], (new SourcePaths(__DIR__))->autoloadPaths());
    }

    public function testFilesListEveryPhpFileBelowAPath(): void
    {
        $directory = dirname(__DIR__, 2).'/Fixture/Project';

        $files = (new SourcePaths(dirname(__DIR__, 5)))->files([$directory]);

        self::assertContains($directory.'/ProductQuery.php', $files);
        self::assertContains($directory.'/ViewerQuery.php', $files);
        self::assertSame($files, array_values(array_unique($files)));
    }

    public function testSkippedExcludesDependencyDirectories(): void
    {
        $paths = new SourcePaths(__DIR__);

        self::assertTrue($paths->skipped('/app/vendor/acme/src/Query.php'));
        self::assertFalse($paths->skipped('/app/src/Query.php'));
    }
}
