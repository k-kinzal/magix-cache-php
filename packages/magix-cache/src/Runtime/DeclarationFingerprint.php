<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime;

use function hash;
use function is_int;
use function json_encode;

use LogicException;
use Magix\Cache\Attribute\BypassCacheErrors;
use Magix\Cache\Attribute\CacheIgnore;
use Magix\Cache\Attribute\CacheKey;
use Magix\Cache\Attribute\DynamicTtl;
use Magix\Cache\Attribute\StaleIfError;
use Magix\Cache\CachePolicy;
use Magix\Cache\Metadata\CacheTokenSet;
use ReflectionMethod;

use function sort;

/**
 * Digests one effective declaration into a canonical cache-key component.
 *
 * Any change to the resolved policy, behaviors, scope, or key settings changes
 * the fingerprint, so entries stored under an older declaration can no longer
 * answer a lookup.
 *
 * @internal
 */
final readonly class DeclarationFingerprint
{
    /**
     * Returns a canonical digest of the effective declaration.
     *
     * The policy already carries the visibility scoped parameters impose.
     *
     * @throws LogicException when the declaration cannot be encoded
     */
    public function calculate(
        ReflectionMethod $method,
        CachePolicy $policy,
        ?StaleIfError $staleIfError,
        ?DynamicTtl $dynamicTtl,
        ?BypassCacheErrors $bypassCacheErrors,
    ): string {
        $exceptions = $staleIfError?->exceptions;

        if ($exceptions !== null) {
            sort($exceptions);
        }

        $parameters = [];

        foreach ($method->getParameters() as $parameter) {
            $reducers = $parameter->getAttributes(CacheKey::class);

            $parameters[] = [
                'name' => $parameter->getName(),
                'ignored' => $parameter->getAttributes(CacheIgnore::class) !== [],
                'reducer' => $reducers === [] ? null : $reducers[0]->newInstance()->reduce,
            ];
        }

        $encoded = json_encode([
            'ttl' => is_int($policy->ttl) ? $policy->ttl : 'Ttl::'.$policy->ttl->name,
            'maxTtl' => $policy->maxTtl,
            'tags' => (new CacheTokenSet())->tags($policy->tags),
            'visibility' => $policy->visibility->name,
            'version' => $policy->version,
            'staleIfError' => $staleIfError === null ? null : [$staleIfError->maxAge, $exceptions],
            'dynamicTtl' => $dynamicTtl?->resolver,
            'bypassCacheErrors' => $bypassCacheErrors === null ? null : [$bypassCacheErrors->classifier],
            'parameters' => $parameters,
        ]);

        if ($encoded === false) {
            throw new LogicException('The effective cache declaration cannot be encoded for fingerprinting.');
        }

        return hash('sha256', $encoded);
    }
}
