<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Console;

use function getcwd;

use Symfony\Component\Console\Application as ConsoleApplication;

/**
 * Builds the magix console application for one working directory.
 */
final readonly class Application
{
    /**
     * Released version of the command line tools.
     */
    public const string VERSION = '0.1.0';

    /**
     * Creates the application factory.
     *
     * @param string|null $workingDirectory Directory scanned by default, defaulting to the current one.
     */
    public function __construct(private ?string $workingDirectory = null)
    {
    }

    /**
     * Returns the console application with every magix command registered.
     */
    public function console(): ConsoleApplication
    {
        $directory = $this->workingDirectory ?? getcwd();
        $loader = new CatalogLoader($directory === false ? '.' : $directory);
        $console = new ConsoleApplication('magix', self::VERSION);

        $console->addCommand(new AnalyzeCommand($loader));
        $console->addCommand(new BoundariesCommand($loader));
        $console->addCommand(new LintCommand($loader));
        $console->addCommand(new KeyCommand($loader));

        return $console;
    }
}
