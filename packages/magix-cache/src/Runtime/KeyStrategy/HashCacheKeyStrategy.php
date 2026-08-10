<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime\KeyStrategy;

use function hash;

use InvalidArgumentException;

use function is_resource;

use Magix\Cache\Runtime\CacheKeyContext;
use Magix\Cache\Runtime\CacheKeyStrategy;

use function serialize;

use Throwable;

/**
 * Hashes the class, method, normalized arguments, and cache version with SHA-256.
 */
final readonly class HashCacheKeyStrategy implements CacheKeyStrategy
{
    /**
     * Returns an opaque SHA-256 cache key.
     */
    public function generate(CacheKeyContext $context): string
    {
        foreach ($context->arguments as $value) {
            if (is_resource($value)) {
                throw new InvalidArgumentException('Resources cannot be used in cache keys. Reduce the argument with #[CacheKey] or exclude it with #[CacheIgnore].');
            }
        }

        try {
            $serialized = serialize([
                'class' => $context->class,
                'method' => $context->method,
                'arguments' => $context->arguments,
                'version' => $context->version,
            ]);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException(
                'The argument cannot be represented in a cache key. Reduce it with #[CacheKey] or exclude it with #[CacheIgnore].',
                previous: $exception,
            );
        }

        return hash('sha256', $serialized);
    }
}
