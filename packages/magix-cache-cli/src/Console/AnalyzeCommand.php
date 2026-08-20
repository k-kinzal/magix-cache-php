<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Console;

use function array_map;
use function array_slice;
use function count;
use function implode;

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
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shows how one boundary composes the caches it depends on.
 */
#[AsCommand(
    name: 'analyze',
    description: 'Shows the cache tree of one boundary with its key, TTL, scope and tags',
    help: 'Reads the source of a project without running it and expands one cached() call site into the tree of boundaries it depends on.',
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
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Boundary to analyze, for example FooBarQuery::execute')]
        string $boundary,
        #[Option(description: 'Directory or file to scan, repeatable', name: 'path')]
        array $path = [],
        #[Option(description: 'Output format: tree, json or mermaid')]
        string $format = 'tree',
        #[Option(description: 'Maximum dependency depth to expand')]
        int $depth = 8,
    ): int {
        $catalog = $this->catalog->load($path);
        $matches = $catalog->search($boundary);

        if ($matches === []) {
            $io->error('No cache boundary matches "'.$boundary.'".');
            $io->writeln($this->suggestions($catalog->boundaries()));

            return Command::FAILURE;
        }

        $tree = new CacheTree($catalog);
        $nodes = array_map(static fn (BoundaryDeclaration $found): CacheNode => $tree->build($found, $depth), $matches);

        if ($format === 'json') {
            $io->writeln((new JsonRenderer())->render($nodes));

            return Command::SUCCESS;
        }

        $renderer = $format === 'mermaid' ? new MermaidRenderer() : new TreeRenderer();

        foreach ($nodes as $node) {
            $io->writeln($renderer->render($node));
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
