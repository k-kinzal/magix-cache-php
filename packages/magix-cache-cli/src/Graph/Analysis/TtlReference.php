<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Graph\Analysis;

use JsonSerializable;
use Magix\Cache\Cli\Graph\CacheVariant;
use Override;

/**
 * Records a numeric reference without claiming an effective value or a bound.
 */
final readonly class TtlReference implements JsonSerializable
{
    /**
     * @param list<string> $sources Declarations or returned values supporting this reference.
     */
    public function __construct(public int $seconds, public array $sources, public string $basis)
    {
    }

    /**
     * Uses a reference only when all numerically understood inputs agree.
     *
     * @param list<CacheVariant> $variants
     */
    public static function fromVariants(array $variants): ?self
    {
        $values = [];
        $sources = [];

        foreach ($variants as $variant) {
            $seconds = $variant->effect->ttl->seconds ?? $variant->effect->analysis->ttlReference?->seconds;

            if ($seconds !== null) {
                $values[$seconds] = true;
                $sources = [...$sources, ...$variant->sources, ...($variant->effect->analysis->ttlReference->sources ?? [])];
            }
        }

        return count($values) === 1 ? new self(array_key_first($values), array_values(array_unique($sources)), 'known input before an unverified operation') : null;
    }

    /**
     * @return array{seconds: int, sources: list<string>, basis: string}
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return ['seconds' => $this->seconds, 'sources' => $this->sources, 'basis' => $this->basis];
    }
}
