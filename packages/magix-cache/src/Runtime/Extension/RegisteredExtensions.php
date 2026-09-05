<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime\Extension;

use LogicException;
use Magix\Cache\Attribute\BypassCacheErrors;
use Magix\Cache\Attribute\DynamicTtl;

/**
 * Resolves behavior references against the extensions fixed at bootstrap.
 *
 * @internal
 */
final readonly class RegisteredExtensions
{
    /** @var array<class-string, CacheTtlResolver> */
    private array $ttlResolvers;

    /** @var array<class-string, BackendErrorClassifier> */
    private array $errorClassifiers;

    /**
     * Indexes the registered extension instances by their concrete class.
     *
     * @param list<CacheTtlResolver> $ttlResolvers
     * @param list<BackendErrorClassifier> $errorClassifiers
     */
    public function __construct(array $ttlResolvers = [], array $errorClassifiers = [])
    {
        $resolvers = [];

        foreach ($ttlResolvers as $resolver) {
            $resolvers[$resolver::class] = $resolver;
        }

        $classifiers = [];

        foreach ($errorClassifiers as $classifier) {
            $classifiers[$classifier::class] = $classifier;
        }

        $this->ttlResolvers = $resolvers;
        $this->errorClassifiers = $classifiers;
    }

    /**
     * Resolves the referenced TTL resolver, or null when the behavior is absent.
     *
     * @throws LogicException when the referenced resolver is not registered
     */
    public function ttlResolver(?DynamicTtl $behavior): ?CacheTtlResolver
    {
        $reference = $behavior?->resolver;

        if ($reference === null) {
            return null;
        }

        return $this->ttlResolvers[$reference]
            ?? throw new LogicException('TTL resolver "'.$reference.'" is not registered with this runtime.');
    }

    /**
     * Resolves the referenced classifier, defaulting when the behavior names none.
     *
     * @throws LogicException when the referenced classifier is not registered
     */
    public function classifier(?BypassCacheErrors $behavior): ?BackendErrorClassifier
    {
        if ($behavior === null) {
            return null;
        }

        if ($behavior->classifier === null) {
            return new DefaultBackendErrorClassifier();
        }

        return $this->errorClassifiers[$behavior->classifier]
            ?? throw new LogicException('Backend error classifier "'.$behavior->classifier.'" is not registered with this runtime.');
    }
}
