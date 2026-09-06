<?php

declare(strict_types=1);

namespace Magix\Cache;

use LogicException;
use Magix\Cache\Cache\Cache;
use Magix\Cache\Clock\SystemClock;
use Magix\Cache\Metadata\Visibility;
use Magix\Cache\Runtime\CacheInvocation;
use Magix\Cache\Runtime\CacheKeyStrategy;
use Magix\Cache\Runtime\Extension\CacheObserver;
use Magix\Cache\Runtime\Extension\RegisteredExtensions;
use Magix\Cache\Runtime\GuardedCache;
use Magix\Cache\Runtime\KeyStrategy\HashCacheKeyStrategy;
use Magix\Cache\Runtime\OriginConstraints;
use Magix\Cache\Runtime\TerminalCacheStrategy;
use Magix\Cache\Runtime\UnixClock;
use Magix\Cache\Strategy\CacheOperation;
use Magix\Cache\Strategy\NextCacheStrategy;
use Psr\Clock\ClockInterface;
use RuntimeException;

/**
 * Executes cache boundaries through a fixed sequence of stages.
 *
 * The order never depends on how attributes are written: lookup, fresh-hit
 * judgement, origin execution, constraint application at one base time, and a
 * re-judged store. Each stage runs through the strategy chain of the boundary
 * — the declared composition, when one exists, in front of the terminal that
 * owns the stage bodies — so a strategy wraps the stages without being able
 * to reorder them. Strategy constraints are met before the policy constraint,
 * both evaluated at the single base time taken right after the origin
 * succeeded, so a declared policy can derive its lifetime from what the
 * strategies guarantee. Only the origin call sits inside the stale-if-error
 * capture range, and the capture takes only the RuntimeException family —
 * failures the origin declares as behavior. Bugs from the origin propagate
 * untouched, and only cache reads and writes sit inside the backend bypass
 * range.
 */
final readonly class CacheRuntime
{
    private RegisteredExtensions $extensions;

    /**
     * Creates a cache runtime using a Magix cache implementation.
     *
     * @param string $namespace Key namespace that separates this runtime's entries.
     * @param list<Runtime\Extension\CacheTtlResolver> $ttlResolvers Resolvers referenced by #[DynamicTtl].
     * @param list<Runtime\Extension\BackendErrorClassifier> $errorClassifiers Classifiers referenced by #[BypassCacheErrors].
     */
    public function __construct(
        private Cache $cache,
        private ClockInterface $clock = new SystemClock(),
        private CacheKeyStrategy $keyStrategy = new HashCacheKeyStrategy(),
        private string $namespace = 'magix',
        array $ttlResolvers = [],
        array $errorClassifiers = [],
        private ?CacheObserver $observer = null,
    ) {
        $this->extensions = new RegisteredExtensions($ttlResolvers, $errorClassifiers);
    }

    /**
     * Resolves one cache boundary through the fixed execution stages.
     *
     * @template T
     * @param CacheInvocation<T> $invocation
     * @return Cached<T>
     * @throws RuntimeException when the origin fails without an eligible stale fallback
     * @throws LogicException when a referenced extension is not registered or a derived lifetime cannot be derived
     */
    public function execute(CacheInvocation $invocation): Cached
    {
        $clock = new UnixClock($this->clock);
        $key = $this->keyStrategy->generate($invocation->context->withNamespace($this->namespace));
        $classifier = $this->extensions->classifier($invocation->bypassCacheErrors);
        $resolver = $this->extensions->ttlResolver($invocation->dynamicTtl);
        $terminal = new TerminalCacheStrategy(
            cache: new GuardedCache($this->cache, $this->observer),
            classifier: $classifier,
            origin: $invocation->origin,
            staleIfError: $invocation->staleIfError,
            observer: $this->observer,
        );
        $chain = $invocation->strategy === null
            ? NextCacheStrategy::of($terminal)
            : NextCacheStrategy::of($invocation->strategy, $terminal);
        $operation = new CacheOperation($key, $clock->now(...));

        if ($invocation->policy->visibility !== Visibility::NoStore) {
            /** @var Cached<T>|null $fresh */
            $fresh = $chain->get($operation);

            if ($fresh !== null) {
                return $fresh;
            }
        }

        /** @var Cached<T> $result */
        $result = $chain->fetch($operation);

        if ($operation->storeSuppressed()) {
            return $result;
        }

        $metadata = (new OriginConstraints())->apply($invocation->policy, $resolver, $result, $key, $operation->baseTime());
        $result = Cached::of($result->value(), $metadata);
        $chain->set($operation, $result);

        return $result;
    }
}
