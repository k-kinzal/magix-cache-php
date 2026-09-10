<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Graph\Analysis;

use JsonSerializable;
use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Graph\CacheNode;
use Override;

/**
 * Retains call resolution separately from the returned metadata's provenance.
 */
final readonly class CallAnalysis implements JsonSerializable
{
    /**
     * @param list<string> $candidates Resolved methods, never assumed to execute together.
     */
    public function __construct(public ?string $target, public int $line, public array $candidates, public string $resolution, public ?string $method = null)
    {
    }

    /**
     * @param array<string, list<CacheNode>> $calls
     * @return list<self>
     */
    public static function fromCalls(BoundaryDeclaration $boundary, array $calls): array
    {
        $result = [];

        foreach ($boundary->dependencies as $dependency) {
            $target = $dependency->class.'::'.$dependency->method;
            $candidates = array_values(array_unique(array_map(static fn (CacheNode $node): string => $node->boundary->id(), $calls[$target] ?? [])));
            $operation = $dependency->class === 'Magix\\Cache\\Cached' || ($dependency->class === $boundary->class && $dependency->method === 'cached');
            $resolution = $operation ? 'operation' : match (count($candidates)) {
                0 => 'unscanned', 1 => 'resolved', default => 'alternatives'
            };
            $result[] = new self($target, $dependency->line, $candidates, $resolution, $dependency->method);
        }

        foreach ($boundary->unresolvedCalls as $call) {
            $result[] = new self(null, $call['line'], [], 'unresolved', $call['method']);
        }

        return $result;
    }

    /**
     * @return array{target: string|null, line: int, candidates: list<string>, resolution: string, method: string|null}
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return ['target' => $this->target, 'line' => $this->line, 'candidates' => $this->candidates, 'resolution' => $this->resolution, 'method' => $this->method];
    }
}
