<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Console;

use function array_map;
use function array_slice;
use function count;
use function implode;

use JsonException;
use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Graph\CacheTree;
use Magix\Cache\Cli\Render\JsonRenderer;
use Magix\Cache\Cli\Render\MermaidRenderer;
use Magix\Cache\Cli\Render\TreeRenderer;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shows the composed caches of a boundary or an uncached entry point.
 */
#[AsCommand(
    name: 'analyze',
    description: 'Shows the cache tree of a boundary or an uncached entry point',
    help: 'Reads source without running it and composes the cache boundaries called by a cached method or an uncached entry point, such as a controller action.',
)]
final readonly class AnalyzeCommand
{
    /**
     * Creates the analyze command.
     */
    public function __construct(private CatalogLoader $catalog)
    {
    }

    /**
     * Renders the cache tree of the referenced boundary.
     *
     * @param array<array-key, mixed> $path
     * @throws JsonException when the tree cannot be encoded as JSON
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Boundary or entry point to analyze, for example FooBarQuery::execute or HomeController::index')]
        string $boundary,
        #[Option(description: 'Directory or file to scan, repeatable', name: 'path')]
        array $path = [],
        #[Option(description: 'Output format: tree, json or mermaid')]
        string $format = 'tree',
        #[Option(description: 'Maximum dependency depth to expand')]
        int $depth = 8,
    ): int {
        $catalog = $this->catalog->load($path);
        $matches = $catalog->search($boundary, includeEntryPoints: true);

        if ($matches === []) {
            $io->error('No cache boundary or entry point matches "'.$boundary.'".');
            $io->writeln($this->suggestions($catalog->boundaries()));

            return Command::FAILURE;
        }

        $tree = new CacheTree($catalog);
        $nodes = array_map(static fn (BoundaryDeclaration $found): CacheNode => $tree->build($found, $depth), $matches);

        if ($format === 'json') {
            $io->writeln((new JsonRenderer())->render($nodes), OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        }

        $renderer = $format === 'mermaid' ? new MermaidRenderer() : new TreeRenderer();

        foreach ($nodes as $node) {
            $io->writeln($renderer->render($node), $format === 'mermaid' ? OutputInterface::OUTPUT_RAW : OutputInterface::OUTPUT_NORMAL);
        }

        return Command::SUCCESS;
    }

    /**
     * Returns the boundaries that can be analyzed instead.
     *
     * @param list<BoundaryDeclaration> $boundaries
     */
    public function suggestions(array $boundaries): string
    {
        if ($boundaries === []) {
            return 'No cache boundaries were found. Use --path to point at the directory that contains them.';
        }

        $known = array_map(static fn (BoundaryDeclaration $found): string => '  '.$found->id(), array_slice($boundaries, 0, 10));
        $more = count($boundaries) > 10 ? ['  ... and '.(count($boundaries) - 10).' more'] : [];

        return implode("\n", ['Known boundaries:', ...$known, ...$more]);
    }
}
