<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use LogicException;
use Magix\Cache\Attribute\StaleIfError;
use Magix\Cache\Cache\CacheEntry;
use Magix\Cache\Metadata\CacheMetadata;
use Magix\Cache\Runtime\StaleReuse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(StaleReuse::class)]
#[UsesClass(StaleIfError::class)]
#[UsesClass(CacheEntry::class)]
#[UsesClass(CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheTokenSet::class)]
#[UsesClass(\Magix\Cache\Metadata\Visibility::class)]
final class StaleReuseTest extends TestCase
{
    /**
     * @return array<string, array{float, bool}>
     */
    public static function providerJudgementTimes(): array
    {
        return [
            'still fresh' => [104.0, false],
            'exactly expired' => [105.0, true],
            'just inside retention' => [134.0, true],
            'exactly at retention' => [135.0, false],
        ];
    }

    #[DataProvider('providerJudgementTimes')]
    public function testCandidateIsServedOnlyInsideEveryBoundary(float $now, bool $served): void
    {
        $entry = new CacheEntry('value', new CacheMetadata(expiresAt: 105.0), retainedUntil: 135.0);
        $behavior = new StaleIfError(maxAge: 30, exceptions: [RuntimeException::class]);

        $candidate = (new StaleReuse())->candidate($behavior, $entry, new RuntimeException('failed'), $now);

        self::assertSame($served ? $entry : null, $candidate);
    }

    public function testCandidateExactlyAtTheAgeLimitIsRejected(): void
    {
        $entry = new CacheEntry('value', new CacheMetadata(expiresAt: 105.0), retainedUntil: 200.0);
        $behavior = new StaleIfError(maxAge: 30, exceptions: [RuntimeException::class]);

        self::assertNull((new StaleReuse())->candidate($behavior, $entry, new RuntimeException('failed'), 135.0));
    }

    public function testCandidateRequiresADeclaredFailureAndARetainedEntry(): void
    {
        $reuse = new StaleReuse();
        $entry = new CacheEntry('value', new CacheMetadata(expiresAt: 105.0), retainedUntil: 135.0);
        $behavior = new StaleIfError(maxAge: 30, exceptions: [RuntimeException::class]);

        self::assertNull($reuse->candidate(null, $entry, new RuntimeException('failed'), 110.0));
        self::assertNull($reuse->candidate($behavior, $entry, new LogicException('a bug'), 110.0));
    }

    public function testRetentionExtendsPhysicalRetentionWithoutTouchingExpiration(): void
    {
        $reuse = new StaleReuse();
        $behavior = new StaleIfError(maxAge: 30, exceptions: [RuntimeException::class]);
        $metadata = new CacheMetadata(expiresAt: 105.0);

        self::assertSame(135.0, $reuse->retention($behavior, $metadata));
        self::assertNull($reuse->retention(null, $metadata));
        self::assertNull($reuse->retention($behavior, CacheMetadata::top()));
    }
}
