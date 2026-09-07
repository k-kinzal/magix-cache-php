<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime;

use InvalidArgumentException;
use LogicException;
use Magix\Cache\Attribute\BypassCacheErrors;
use Magix\Cache\Attribute\Cache;
use Magix\Cache\Attribute\CacheIgnore;
use Magix\Cache\Attribute\CacheScope;
use Magix\Cache\Attribute\DynamicTtl;
use Magix\Cache\Attribute\StaleIfError;
use Magix\Cache\Attribute\UseStrategy;
use Magix\Cache\CachePolicy;
use Magix\Cache\Metadata\Visibility;
use Magix\Cache\Strategy\StrategyDefinition;
use ReflectionMethod;

/**
 * Holds one resolved static cache declaration.
 *
 * A definition carries no per-invocation state: arguments and the origin
 * closure live in CacheInvocation, and the runtime is referenced by name only,
 * so memoizing a definition never retains a runtime instance. Constraints
 * from scoped parameters are folded into the policy with the meet, so a
 * method declaration cannot relax them.
 *
 * @internal
 */
final readonly class CacheDefinition
{
    /**
     * Effective policy, including the visibility scoped parameters impose.
     */
    public CachePolicy $policy;

    /**
     * Name of the runtime registered for this boundary.
     */
    public string $runtime;

    /**
     * Strategy construction definition resolved from the #[UseStrategy] declaration.
     */
    public ?StrategyDefinition $strategy;

    private string $fingerprint;

    /**
     * Creates a definition from one reflected method and its declarations.
     *
     * The declared strategy is resolved here, once per memoized declaration,
     * by calling the static create() of the referenced class with the
     * declared arguments.
     *
     * @throws InvalidArgumentException when a parameter is both scoped and ignored
     * @throws LogicException when the declared strategy cannot be resolved
     */
    public function __construct(
        private ReflectionMethod $method,
        private string $concreteClass,
        Cache $declaration,
        public ?StaleIfError $staleIfError = null,
        public ?DynamicTtl $dynamicTtl = null,
        public ?BypassCacheErrors $bypassCacheErrors = null,
        ?UseStrategy $useStrategy = null,
    ) {
        $visibility = Visibility::Shared;

        foreach ($method->getParameters() as $parameter) {
            $ignored = $parameter->getAttributes(CacheIgnore::class) !== [];
            $scopes = $parameter->getAttributes(CacheScope::class);

            if ($scopes === []) {
                continue;
            }

            $scope = $scopes[0]->newInstance()->visibility;

            if ($ignored && $scope !== Visibility::NoStore) {
                throw new InvalidArgumentException('A scoped cache parameter cannot also be ignored unless its scope is NoStore.');
            }

            $visibility = $visibility->meet($scope);
        }

        $this->policy = $declaration->policy()->restrictVisibility($visibility);
        $this->runtime = $declaration->runtime;
        $this->strategy = $useStrategy?->resolve();
        $this->fingerprint = (new DeclarationFingerprint())->calculate(
            $method,
            $this->policy,
            $staleIfError,
            $dynamicTtl,
            $bypassCacheErrors,
            $useStrategy,
        );
    }

    /**
     * Builds normalized key-strategy input from arguments captured by Cacheable.
     *
     * @param array<array-key, mixed> $arguments
     */
    public function keyContext(array $arguments): CacheKeyContext
    {
        $keyArguments = (new CacheKeyArgumentBinder())->bind($this->method, $arguments);

        return new CacheKeyContext(
            namespace: '',
            class: $this->concreteClass,
            declaringClass: $this->method->getDeclaringClass()->getName(),
            method: $this->method->getName(),
            arguments: $keyArguments,
            version: $this->policy->version,
            fingerprint: $this->fingerprint,
        );
    }
}
