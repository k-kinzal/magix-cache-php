<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime;

use Closure;
use InvalidArgumentException;
use LogicException;
use Magix\Cache\Attribute\BypassCacheErrors;
use Magix\Cache\Attribute\Cache;
use Magix\Cache\Attribute\CacheIgnore;
use Magix\Cache\Attribute\CacheScope;
use Magix\Cache\Attribute\DynamicTtl;
use Magix\Cache\Attribute\StaleIfError;
use Magix\Cache\Attribute\UseStrategy;
use Magix\Cache\Cached;
use Magix\Cache\CachePolicy;
use Magix\Cache\Metadata\Visibility;
use Magix\Cache\Runtime\Parameter\ParameterBindings;
use Magix\Cache\Runtime\Parameter\ParameterConfiguration;
use Magix\Cache\Runtime\Parameter\StrategyBindings;
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
     * Memoized static strategy recipe; null when absent or invocation-bound.
     */
    public ?StrategyDefinition $strategy;

    private string $fingerprint;

    private ParameterBindings $parameters;

    /**
     * Creates a definition from one reflected method and its declarations.
     *
     * Static strategy arguments are resolved once here. Parameter bindings
     * are validated here and evaluated by invocation(), which retains no
     * caller values on this memoized definition.
     *
     * @throws InvalidArgumentException when parameter scopes or configuration bindings conflict
     * @throws LogicException when the declared strategy cannot be resolved
     */
    public function __construct(
        private ReflectionMethod $method,
        private string $concreteClass,
        Cache $declaration,
        public ?StaleIfError $staleIfError = null,
        public ?DynamicTtl $dynamicTtl = null,
        public ?BypassCacheErrors $bypassCacheErrors = null,
        private ?UseStrategy $useStrategy = null,
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
        $this->parameters = new ParameterBindings($method);
        $bindings = $this->parameters->strategyArguments();
        (new StrategyBindings())->validate($useStrategy, $bindings);
        $this->strategy = $bindings === [] ? $useStrategy?->resolve() : null;
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
        $values = $this->parameters->bind($arguments);

        return $this->context($arguments, $values, $this->strategyFor($values));
    }

    /**
     * Binds configuration before lookup without retaining invocation values.
     *
     * @template T
     * @param array<array-key, mixed> $arguments
     * @param Closure(): Cached<T> $origin
     * @return CacheInvocation<T>
     * @throws InvalidArgumentException when parameter values do not satisfy their cache constraints
     * @throws LogicException when the strategy factory does not produce a construction definition
     */
    public function invocation(array $arguments, Closure $origin): CacheInvocation
    {
        $values = $this->parameters->bind($arguments);
        $strategy = $this->strategyFor($values);

        return new CacheInvocation(
            context: $this->context($arguments, $values, $strategy),
            policy: $values->policy($this->policy),
            origin: $origin,
            staleIfError: $this->staleIfError,
            dynamicTtl: $this->dynamicTtl,
            bypassCacheErrors: $this->bypassCacheErrors,
            strategy: $strategy,
            parameterTtl: $values->ttl,
        );
    }

    /**
     * Builds a fresh recipe only when create() has invocation arguments.
     *
     * @throws LogicException when the strategy factory does not produce a construction definition
     */
    public function strategyFor(ParameterConfiguration $values): ?StrategyDefinition
    {
        return $values->strategyArguments === []
            ? $this->strategy
            : $this->useStrategy?->resolve($values->strategyArguments);
    }

    /**
     * Includes evaluated settings even when a key reducer omits them.
     *
     * @param array<array-key, mixed> $arguments
     */
    public function context(array $arguments, ParameterConfiguration $values, ?StrategyDefinition $strategy): CacheKeyContext
    {
        $keyArguments = (new CacheKeyArgumentBinder())->bind($this->method, $arguments);

        if ($this->parameters->bindings !== []) {
            $keyArguments['@configuration'] = [
                'ttl' => $values->ttl,
                'tags' => $values->tags,
                'visibility' => $values->visibility,
                'strategy' => $strategy,
            ];
        }

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
