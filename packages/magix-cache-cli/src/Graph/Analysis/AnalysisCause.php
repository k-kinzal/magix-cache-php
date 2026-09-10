<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Graph\Analysis;

use JsonSerializable;
use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Override;

/**
 * Identifies one analysis limitation at its source, independently of its consumers.
 */
final readonly class AnalysisCause implements JsonSerializable
{
    /**
     * Stable identity for deduplicating causes across fields and callers.
     */
    public string $id;

    /**
     * Creates a stable source identity shared by all affected fields and callers.
     */
    public function __construct(public string $kind, public string $method, public string $file, public int $line, public string $message)
    {
        $this->id = hash('sha256', implode(':', [$kind, $method, $file, $line, $message]));
    }

    /**
     * Locates a limitation in the method being analyzed.
     */
    public static function at(?BoundaryDeclaration $owner, string $kind, string $message, int $line = 0): self
    {
        return new self($kind, $owner?->id() ?? '', $owner->file ?? '', $line > 0 ? $line : ($owner->line ?? 0), $message);
    }

    /**
     * @return array{id: string, kind: string, method: string, file: string, line: int, message: string}
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return ['id' => $this->id, 'kind' => $this->kind, 'method' => $this->method, 'file' => $this->file, 'line' => $this->line, 'message' => $this->message];
    }
}
