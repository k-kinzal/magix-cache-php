<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Render;

use function array_map;
use function count;
use function implode;

use Magix\Cache\Cli\Graph\CacheNode;

use function max;
use function rtrim;
use function str_pad;
use function strlen;
use function strtolower;

/**
 * Renders every known boundary as one aligned inventory table.
 */
final readonly class BoundaryTableRenderer
{
    /**
     * Column titles of the inventory table.
     */
    private const array HEADERS = ['BOUNDARY', 'TTL', 'VISIBILITY', 'STORABLE', 'TAGS', 'DEPS', 'LOCATION'];

    /**
     * Returns the inventory table for the given trees.
     *
     * @param list<CacheNode> $nodes
     */
    public function render(array $nodes): string
    {
        $rows = array_map($this->row(...), $nodes);
        $widths = array_map(strlen(...), self::HEADERS);

        foreach ($rows as $row) {
            foreach ($row as $index => $cell) {
                $widths[$index] = max($widths[$index], strlen($cell));
            }
        }

        $lines = [];

        foreach ([self::HEADERS, ...$rows] as $row) {
            $cells = [];

            foreach ($row as $index => $cell) {
                $cells[] = str_pad($cell, $widths[$index]);
            }

            $lines[] = rtrim(implode('  ', $cells));
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Returns one table row for one boundary.
     *
     * @return list<string>
     */
    public function row(CacheNode $node): array
    {
        $effect = $node->effect;

        return [
            $node->boundary->id(),
            $effect->ttl === null ? '-' : $effect->ttl.'s',
            strtolower($effect->visibility->name),
            $effect->storable ? 'yes' : 'no',
            $effect->tags === [] ? '-' : implode(',', $effect->tags),
            (string) count($node->children),
            $node->boundary->file.':'.$node->boundary->line,
        ];
    }
}
