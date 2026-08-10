<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime;

use InvalidArgumentException;
use Magix\Cache\Attribute\CacheIgnore;
use Magix\Cache\Attribute\CacheScope;
use Magix\Cache\CachePolicy;
use Magix\Cache\Runtime\Metadata\Visibility;
use ReflectionMethod;

/**
 * Holds one resolved cache boundary and normalizes its invocation arguments.
 *
 * @internal
 */
final readonly class CacheDefinition
{
    private Visibility $visibility;

    /**
     * Creates a definition from one reflected method and its optional declared policy.
     */
    public function __construct(
        private ReflectionMethod $method,
        private ?CachePolicy $policy,
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

        $this->visibility = $visibility;
    }

    /**
     * Returns the method-or-class policy declared by attributes, when present.
     */
    public function policy(): ?CachePolicy
    {
        return $this->policy;
    }

    /**
     * Returns the visibility implied by parameter scope attributes.
     */
    public function visibility(): Visibility
    {
        return $this->visibility;
    }

    /**
     * Builds normalized key-strategy input from arguments captured by Cacheable.
     *
     * @param array<array-key, mixed> $arguments
     */
    public function keyContext(array $arguments, string $version): CacheKeyContext
    {
        $keyArguments = (new CacheKeyArgumentBinder())->bind($this->method, $arguments);

        return new CacheKeyContext(
            class: $this->method->getDeclaringClass()->getName(),
            method: $this->method->getName(),
            arguments: $keyArguments,
            version: $version,
        );
    }
}
