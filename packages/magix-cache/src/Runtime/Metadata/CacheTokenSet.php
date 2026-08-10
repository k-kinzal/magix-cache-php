<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime\Metadata;

use function array_unique;
use function array_values;

use InvalidArgumentException;

use function preg_match;
use function sort;

/**
 * Validates and canonicalizes cache tags and diagnostic reasons.
 *
 * @internal
 */
final readonly class CacheTokenSet
{
    /**
     * Returns unique, sorted cache tags that are safe for HTTP headers.
     *
     * @param list<string> $tokens
     * @return list<non-empty-string>
     */
    public function tags(array $tokens): array
    {
        foreach ($tokens as $token) {
            if ($token === '') {
                throw new InvalidArgumentException('Cache tag must not be empty.');
            }

            if (preg_match('/\A[A-Za-z0-9_.:-]+\z/D', $token) !== 1) {
                throw new InvalidArgumentException('Cache tag contains characters that are unsafe in HTTP headers.');
            }
        }

        $normalized = array_values(array_unique($tokens));
        sort($normalized);

        return $normalized;
    }

    /**
     * Returns unique, sorted non-empty diagnostic reasons.
     *
     * @param list<string> $tokens
     * @return list<non-empty-string>
     */
    public function reasons(array $tokens): array
    {
        foreach ($tokens as $token) {
            if ($token === '') {
                throw new InvalidArgumentException('Cacheability reason must not be empty.');
            }
        }

        $normalized = array_values(array_unique($tokens));
        sort($normalized);

        return $normalized;
    }
}
