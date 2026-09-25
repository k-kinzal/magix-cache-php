<?php

declare(strict_types=1);

namespace Tests\Fixture;

use ArrayObject;
use Closure;
use Magix\Cache\Cached;
use Magix\Cache\Strategy\CacheRead;
use Magix\Cache\Strategy\CacheStrategy;
use Magix\Cache\Strategy\CacheWrite;
use Magix\Cache\Strategy\StrategyDefinition;
use Override;

/**
 * Makes response nesting observable without inspecting its source.
 */
final readonly class TransformingStrategy implements CacheStrategy
{
    /**
     * Labels this middleware's transformations.
     * @param ArrayObject<int, string>|null $log
     */
    public function __construct(private string $label, private ?ArrayObject $log = null)
    {
    }

    /**
     * Builds this middleware for an attributed boundary.
     */
    public static function create(string $label): StrategyDefinition
    {
        return StrategyDefinition::of(self::class, label: $label);
    }

    /**
     * @return CacheRead<mixed>|null
     * @param Closure(string): (CacheRead<mixed>|null) $next
     */
    #[Override]
    public function get(string $key, Closure $next): ?CacheRead
    {
        return $next($key);
    }

    /**
     * @return Cached<mixed>
     * @param Closure(): Cached<mixed> $next
     */
    #[Override]
    public function fetch(string $key, Closure $next): Cached
    {
        $this->log?->append($this->label.'.before');
        $result = $next();
        $this->log?->append($this->label.'.after');

        return $result->map(fn (mixed $value): array => [$this->label, $value]);
    }

    /**
     * @param CacheWrite<mixed> $request
     * @param Closure(string, CacheWrite<mixed>): void $next
     */
    #[Override]
    public function set(string $key, CacheWrite $request, Closure $next): void
    {
        $next($key, $request);
    }
}
