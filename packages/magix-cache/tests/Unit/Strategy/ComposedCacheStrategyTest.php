<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy;

use ArrayObject;
use Magix\Cache\Cached;
use Magix\Cache\Strategy\CacheWrite;
use Magix\Cache\Strategy\ComposedCacheStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\CacheHandlers;
use Tests\Fixture\RecordingStrategy;

#[CoversClass(ComposedCacheStrategy::class)]
#[UsesNamespace('Magix\Cache')]
#[UsesClass(Cached::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheTokenSet::class)]
#[UsesClass(CacheWrite::class)]
#[UsesClass(\Magix\Cache\Strategy\CacheRead::class)]
#[UsesClass(\Magix\Cache\Strategy\StaleIfErrorCacheStrategy::class)]
final class ComposedCacheStrategyTest extends TestCase
{
    public function testFetchActsAsOneStrategyInCompositionOrder(): void
    {
        /** @var ArrayObject<int, string> $log */
        $log = new ArrayObject();
        $composed = new ComposedCacheStrategy(
            new RecordingStrategy('outer', $log),
            new RecordingStrategy('inner', $log),
        );
        $terminal = new CacheHandlers(hit: null, fetched: Cached::of('origin'));
        $key = 'key';

        $result = $composed->fetch($key, $terminal->fetch(...));


        self::assertSame('origin', $result->value());
        self::assertSame(
            ['outer.fetch.before', 'inner.fetch.before', 'inner.fetch.after', 'outer.fetch.after'],
            $log->getArrayCopy(),
        );
    }

    public function testFetchComposesAgainWithoutLosingOrder(): void
    {
        /** @var ArrayObject<int, string> $log */
        $log = new ArrayObject();
        $composed = new ComposedCacheStrategy(
            new ComposedCacheStrategy(
                new RecordingStrategy('a', $log),
                new RecordingStrategy('b', $log),
            ),
            new RecordingStrategy('c', $log),
        );
        $terminal = new CacheHandlers(hit: null, fetched: Cached::of('origin'));
        $key = 'key';

        $fetched1 = $composed->fetch($key, $terminal->fetch(...));

        self::assertSame('origin', $fetched1->value());
        self::assertSame(
            ['a.fetch.before', 'b.fetch.before', 'c.fetch.before', 'c.fetch.after', 'b.fetch.after', 'a.fetch.after'],
            $log->getArrayCopy(),
        );
    }

    public function testSetDelegatesThroughTheSequence(): void
    {
        /** @var ArrayObject<int, string> $log */
        $log = new ArrayObject();
        $composed = new ComposedCacheStrategy(
            new RecordingStrategy('outer', $log),
            new RecordingStrategy('inner', $log),
        );
        $terminal = new CacheHandlers(hit: null, fetched: Cached::of('origin'));
        $key = 'key';
        $result = Cached::of('value');

        $composed->set($key, new CacheWrite($result), $terminal->set(...));

        self::assertSame($result, $terminal->stored?->cached);
        self::assertSame(
            ['outer.set.before', 'inner.set.before', 'inner.set.after', 'outer.set.after'],
            $log->getArrayCopy(),
        );
    }

    public function testGetDelegatesThroughTheComposition(): void
    {
        $composed = new ComposedCacheStrategy(new RecordingStrategy('only'));
        $terminal = new CacheHandlers(hit: Cached::of('hit', new \Magix\Cache\Metadata\CacheMetadata(expiresAt: 150.0)), fetched: Cached::of('origin'));
        $key = 'key';

        self::assertSame('hit', $composed->get($key, $terminal->get(...))?->cached->value());
    }


    public function testFetchNestsResultTransformationsAroundTheArgumentFreeOrigin(): void
    {
        /** @var ArrayObject<int, string> $events */
        $events = new ArrayObject();
        $origin = static function () use ($events): Cached {
            $events->append('origin');

            return Cached::of(['origin', func_num_args()]);
        };
        $a = new \Tests\Fixture\TransformingStrategy('a', $events);
        $b = new \Tests\Fixture\TransformingStrategy('b', $events);
        $c = new \Tests\Fixture\TransformingStrategy('c', $events);
        $nested = new ComposedCacheStrategy(new ComposedCacheStrategy($a, $b), $c);
        $result = $nested->fetch('key', $origin);

        self::assertSame(['a', ['b', ['c', ['origin', 0]]]], $result->value());
        self::assertSame(['a.before', 'b.before', 'c.before', 'origin', 'c.after', 'b.after', 'a.after'], $events->getArrayCopy());
        $rightAssociated = new ComposedCacheStrategy($a, new ComposedCacheStrategy($b, $c));
        self::assertEquals($result, $rightAssociated->fetch('key', $origin));
    }

    public function testEmptyCompositionCallsEachOperationUnchanged(): void
    {
        $composed = new ComposedCacheStrategy();
        $cached = Cached::of('value', new \Magix\Cache\Metadata\CacheMetadata(expiresAt: 150.0));
        $handlers = new CacheHandlers($cached, $cached);
        $request = new CacheWrite($cached);

        self::assertSame($cached, $composed->get('key', $handlers->get(...))?->cached);
        self::assertSame($cached, $composed->fetch('key', $handlers->fetch(...)));
        $composed->set('key', $request, $handlers->set(...));
        self::assertSame($request, $handlers->stored);
    }

    public function testGetNestsRequestAndResponseRewritesWithTheSameInterface(): void
    {
        $strategy = new ComposedCacheStrategy(
            new \Tests\Fixture\RewritingStrategy('a'),
            new ComposedCacheStrategy(new \Tests\Fixture\RewritingStrategy('b')),
        );
        $readKey = null;
        $read = $strategy->get('key', static function (string $key) use (&$readKey): \Magix\Cache\Strategy\CacheRead {
            $readKey = $key;

            return new \Magix\Cache\Strategy\CacheRead(Cached::of('stored', new \Magix\Cache\Metadata\CacheMetadata(expiresAt: 150.0)), 200.0);
        });

        self::assertSame('bakey', $readKey);
        self::assertSame(['a', ['b', 'stored']], $read?->cached->value());
        self::assertSame(150.0, $read->cached->metadata->expiresAt);
        self::assertSame(200.0, $read->retainedUntil);
    }

    public function testSetNestsKeyAndWriteRewrites(): void
    {
        $strategy = new ComposedCacheStrategy(
            new \Tests\Fixture\RewritingStrategy('a'),
            new ComposedCacheStrategy(new \Tests\Fixture\RewritingStrategy('b')),
        );
        $keyWritten = null;
        $written = null;
        $request = new CacheWrite(Cached::of('value', new \Magix\Cache\Metadata\CacheMetadata(expiresAt: 150.0)), 200.0);
        $strategy->set('key', $request, static function (string $key, CacheWrite $request) use (&$keyWritten, &$written): void {
            $keyWritten = $key;
            $written = $request;
        });

        self::assertSame('bakey', $keyWritten);
        self::assertSame(['b', ['a', 'value']], $written?->cached->value());
        self::assertSame($request->cached->metadata, $written->cached->metadata);
        self::assertSame(200.0, $written->retainedUntil);
        self::assertSame('value', $request->cached->value());
    }

    public function testFetchPropagatesTheSameExceptionThroughNestedMiddleware(): void
    {
        /** @var ArrayObject<int, string> $log */
        $log = new ArrayObject();
        $strategy = new ComposedCacheStrategy(new RecordingStrategy('outer', $log), new RecordingStrategy('inner', $log));
        $error = new \Tests\Fixture\UpstreamUnavailable('down');

        try {
            $strategy->fetch('key', static fn (): Cached => throw $error);
            self::fail('The delegated failure must propagate.');
        } catch (\Tests\Fixture\UpstreamUnavailable $caught) {
            self::assertSame($error, $caught);
            self::assertSame(['outer.fetch.before', 'inner.fetch.before'], $log->getArrayCopy());
        }
    }
}
