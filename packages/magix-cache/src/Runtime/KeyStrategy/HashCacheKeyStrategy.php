<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime\KeyStrategy;

use Exception;

use function hash;

use InvalidArgumentException;

use function is_resource;

use Magix\Cache\Runtime\CacheKeyContext;
use Magix\Cache\Runtime\CacheKeyStrategy;

use function serialize;

/**
 * Hashes every identity field of the key context with SHA-256.
 */
final readonly class HashCacheKeyStrategy implements CacheKeyStrategy
{
    /**
     * Returns an opaque SHA-256 cache key.
     *
     * PHP refuses to serialize a Closure or an internal object such as PDO, and
     * reports that refusal as a bare \Exception. That refusal is what this
     * catch is for. An Error raised by the caller's own __serialize() is a bug
     * in that method, and relabelling it as an unusable key would hide it.
     *
     * @throws InvalidArgumentException when an argument cannot be represented in a key
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
                'namespace' => $context->namespace,
                'class' => $context->class,
                'declaringClass' => $context->declaringClass,
                'method' => $context->method,
                'arguments' => $context->arguments,
                'version' => $context->version,
                'fingerprint' => $context->fingerprint,
            ]);
        } catch (Exception $exception) {
            throw new InvalidArgumentException(
                'The argument cannot be represented in a cache key. Reduce it with #[CacheKey] or exclude it with #[CacheIgnore].',
                previous: $exception,
            );
        }

        return hash('sha256', $serialized);
    }
}
