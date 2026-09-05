<?php

declare(strict_types=1);

namespace Tests\Fixture;

use Exception;
use LogicException;
use Magix\Cache\Cache\CacheEntry;
use Magix\Cache\Metadata\CacheMetadata;

use function serialize;
use function str_replace;
use function unserialize;

/**
 * Builds a stored entry whose format version no longer matches the code.
 */
final readonly class ForeignFormatEntry
{
    /**
     * Returns an entry as an older library version would have persisted it.
     *
     * serialize() reports a value it refuses with a bare \Exception; this
     * fixture value contains only scalars, so that refusal is a fixture bug.
     *
     * @return CacheEntry<string>
     * @throws LogicException when the fixture entry cannot be re-encoded
     */
    public static function create(): CacheEntry
    {
        $entry = new CacheEntry('value', new CacheMetadata(expiresAt: 120.0));

        try {
            $payload = serialize($entry);
        } catch (Exception $refused) {
            throw new LogicException('The fixture entry cannot be serialized.', previous: $refused);
        }

        $foreign = unserialize(str_replace('s:13:"formatVersion";i:1;', 's:13:"formatVersion";i:0;', $payload));

        if (!$foreign instanceof CacheEntry) {
            throw new LogicException('The tampered payload no longer decodes to a CacheEntry.');
        }

        /** @var CacheEntry<string> $foreign */
        return $foreign;
    }
}
