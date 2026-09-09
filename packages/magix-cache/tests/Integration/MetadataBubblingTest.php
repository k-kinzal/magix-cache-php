<?php

declare(strict_types=1);

namespace Tests\Integration;

use function array_intersect_key;

use Magix\Cache\CacheRuntime;
use Magix\Cache\Metadata\CacheMetadata;
use Magix\Cache\Metadata\Visibility;
use Magix\Cache\Runtime\CacheRuntimeRegistry;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\MetadataScenario;

#[CoversClass(CacheRuntime::class)]
#[UsesNamespace('Magix\Cache')]
final class MetadataBubblingTest extends TestCase
{
    #[Override]
    protected function tearDown(): void
    {
        CacheRuntimeRegistry::reset();
    }

    /**
     * @return iterable<string, array{'memory'|'psr6'|'psr16', CacheMetadata, int, Visibility, int, int, CacheMetadata}>
     */
    public static function providerCacheLayouts(): iterable
    {
        $sources = [
            'shared' => [CacheMetadata::top(), 20, Visibility::Shared, 7],
            'parameter private' => [CacheMetadata::top(), 20, Visibility::Private, 7],
            'private fractional expiry' => [new CacheMetadata(expiresAt: MetadataScenario::BASE_TIME + 15.125, tags: ['upstream', 'common'], visibility: Visibility::Private, reasons: ['upstream']), 20, Visibility::Shared, 7],
            'uncacheable' => [new CacheMetadata(cacheable: false, reasons: ['do not cache']), 20, Visibility::Shared, 4],
            'parameter no-store' => [CacheMetadata::top(), 20, Visibility::NoStore, 4],
            'parameter zero TTL overridden by strategy' => [CacheMetadata::top(), 0, Visibility::Shared, 7],
            'already expired upstream' => [new CacheMetadata(expiresAt: MetadataScenario::BASE_TIME - 0.125), 20, Visibility::Shared, 7],
        ];

        foreach (['memory', 'psr6', 'psr16'] as $backend) {
            foreach ($sources as $name => [$source, $ttl, $visibility, $entryCount]) {
                $expected = new CacheMetadata(
                    expiresAt: MetadataScenario::BASE_TIME + 90,
                    cacheable: $source->cacheable,
                    tags: ['common', 'root'],
                    visibility: $visibility,
                    reasons: ['source:0', 'source:1', 'source:2', 'source:3', ...$source->reasons],
                );
                for ($mask = 0; $mask < (1 << $entryCount); ++$mask) {
                    yield $backend.' / '.$name.' / retained '.$mask => [$backend, $source, $ttl, $visibility, $entryCount, $mask, $expected];
                }
            }
        }
    }

    /**
     * @param 'memory'|'psr6'|'psr16' $backend
     */
    #[DataProvider('providerCacheLayouts')]
    public function testEveryStoredSubtreeSubstitutionPreservesAllMetadata(
        string $backend,
        CacheMetadata $source,
        int $ttl,
        Visibility $visibility,
        int $entryCount,
        int $mask,
        CacheMetadata $expected,
    ): void {
        $scenario = new MetadataScenario($backend, $source, $ttl, $visibility);
        $cold = $scenario->graph->root();
        $coldEntries = $scenario->cache->entries;

        self::assertCount($entryCount, $scenario->cache->entries);
        self::assertSame(['root' => 1, 'left' => 1, 'leaf:0' => 1, 'leaf:1' => 1, 'right' => 1, 'leaf:2' => 1, 'leaf:3' => 1], $scenario->graph->calls);
        self::assertSame('leaf:0,leaf:1|leaf:1,leaf:2,leaf:3', $cold->value());
        self::assertEquals($expected, $cold->metadata);

        $retainedNodes = $scenario->retain($mask);
        $result = $scenario->graph->root();

        self::assertSame($cold->value(), $result->value());
        self::assertTrue($cold->metadata->equals($result->metadata));
        self::assertEquals($cold, $result);
        self::assertEquals(array_intersect_key($coldEntries, $scenario->cache->entries), $scenario->cache->entries, 'recomputed subtrees store the same metadata and retention');
        self::assertSame([], array_intersect_key($scenario->graph->calls, $retainedNodes), 'retained boundaries must actually hit');
    }

    /**
     * @return iterable<string, array{'memory'|'psr6'|'psr16'}>
     */
    public static function providerBackends(): iterable
    {
        foreach (['memory', 'psr6', 'psr16'] as $backend) {
            yield $backend => [$backend];
        }
    }

    /**
     * @param 'memory'|'psr6'|'psr16' $backend
     */
    #[DataProvider('providerBackends')]
    public function testHitsPreserveMetadataAndRecomputedParentsReapplyTheirOverrides(string $backend): void
    {
        $source = new CacheMetadata(expiresAt: MetadataScenario::BASE_TIME + 15.125, visibility: Visibility::Private, reasons: ['upstream']);
        $scenario = new MetadataScenario($backend, $source);
        $cold = $scenario->graph->root();
        $scenario->graph->calls = [];
        self::assertSame(4, $scenario->resolver->calls);
        $scenario->clock->advance(5.5);
        $hit = $scenario->graph->root();

        self::assertEquals($cold, $hit);
        self::assertSame([], $scenario->graph->calls);
        self::assertSame(4, $scenario->resolver->calls, 'a root hit does not reevaluate dynamic TTLs');
        self::assertNotNull($hit->metadata->expiresAt);
        self::assertSame(84.5, $hit->metadata->expiresAt - $scenario->clock->time);

        $scenario->retainNodes(['leaf:0', 'leaf:1', 'leaf:2', 'leaf:3']);
        $rebuilt = $scenario->graph->root();

        self::assertSame($cold->value(), $rebuilt->value());
        self::assertEquals($cold->metadata->withExpiration(MetadataScenario::BASE_TIME + 95.5), $rebuilt->metadata);
        self::assertSame(['root' => 1, 'left' => 1, 'right' => 1], $scenario->graph->calls);
        self::assertSame(4, $scenario->resolver->calls, 'leaf hits also preserve evaluated dynamic TTLs');
    }

    /**
     * @return iterable<string, array{'psr6'|'psr16'}>
     */
    public static function providerPsrBackends(): iterable
    {
        yield 'psr6' => ['psr6'];
        yield 'psr16' => ['psr16'];
    }

    /**
     * @param 'psr6'|'psr16' $backend
     */
    #[DataProvider('providerPsrBackends')]
    public function testPsrRootHitRestoresMetadataWithoutExecutingOrStoring(string $backend): void
    {
        $scenario = new MetadataScenario($backend, CacheMetadata::top());
        $cold = $scenario->graph->root();
        $scenario->cache->entries = [];
        $scenario->graph->calls = [];
        $hit = $scenario->graph->root();

        self::assertEquals($cold, $hit);
        self::assertNotSame($cold->metadata, $hit->metadata);
        self::assertSame([], $scenario->graph->calls);
        self::assertSame([], $scenario->cache->entries);
    }

    /**
     * @param 'memory'|'psr6'|'psr16' $backend
     */
    #[DataProvider('providerBackends')]
    public function testRecomputingAParentAtANewBaseTimeCanChangeItsOwnExpiration(string $backend): void
    {
        $scenario = new MetadataScenario($backend, CacheMetadata::top());
        $cold = $scenario->graph->root(ttl: 10);
        $scenario->clock->advance(5.0);
        $hit = $scenario->graph->root(ttl: 10);
        self::assertEquals($cold, $hit);

        $scenario->retainNodes(['left', 'right']);
        $rebuilt = $scenario->graph->root(ttl: 10);

        self::assertSame(MetadataScenario::BASE_TIME + 10.0, $hit->metadata->expiresAt);
        self::assertSame(MetadataScenario::BASE_TIME + 15.0, $rebuilt->metadata->expiresAt);
        self::assertSame($cold->value(), $rebuilt->value());
        self::assertSame($cold->metadata->tags, $rebuilt->metadata->tags);
        self::assertSame($cold->metadata->reasons, $rebuilt->metadata->reasons);
        self::assertSame($cold->metadata->visibility, $rebuilt->metadata->visibility);
        self::assertSame($cold->metadata->cacheable, $rebuilt->metadata->cacheable);
        self::assertSame(['root' => 1], $scenario->graph->calls);
    }

    /**
     * @param 'memory'|'psr6'|'psr16' $backend
     */
    #[DataProvider('providerBackends')]
    public function testExplicitParentTtlCanCacheAResultComposedFromStaleLeaves(string $backend): void
    {
        $scenario = new MetadataScenario($backend, new CacheMetadata(visibility: Visibility::Private, tags: ['upstream'], reasons: ['upstream']));
        $cold = $scenario->graph->root();
        $scenario->clock->advance(90.0);
        $scenario->cache->entries = [];
        $scenario->graph->calls = [];
        $scenario->graph->unavailable = true;
        $result = $scenario->graph->root();

        self::assertSame($cold->value(), $result->value());
        self::assertEquals($cold->metadata->withExpiration(MetadataScenario::BASE_TIME + 180.0), $result->metadata);
        self::assertTrue($result->metadata->isStorable($scenario->clock->time));
        self::assertCount(1, $scenario->cache->entries, 'only the explicitly overridden parent gets a new expiration');
        self::assertSame(['root' => 1, 'left' => 1, 'leaf:0' => 1, 'leaf:1' => 2, 'right' => 1, 'leaf:2' => 1, 'leaf:3' => 1], $scenario->graph->calls);
    }
}
