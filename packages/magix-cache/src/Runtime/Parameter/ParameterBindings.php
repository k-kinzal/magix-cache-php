<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime\Parameter;

use InvalidArgumentException;
use Magix\Cache\Metadata\CacheTokenSet;
use Magix\Cache\Metadata\Visibility;
use ReflectionMethod;

/**
 * Resolves a memoized binding plan against each invocation's raw arguments.
 *
 * @internal
 */
final readonly class ParameterBindings
{
    /**
     * @var list<ParameterBinding>
     */
    public array $bindings;

    /**
     * Reads only declarations; no invocation value is retained.
     */
    public function __construct(private ReflectionMethod $method)
    {
        $bindings = [];

        foreach ($method->getParameters() as $parameter) {
            $binding = ParameterBinding::read($parameter);

            if ($binding !== null) {
                $bindings[] = $binding;
            }
        }

        $this->bindings = $bindings;
    }

    /**
     * @return array<string, string> Factory destination to boundary parameter.
     * @throws InvalidArgumentException when two parameters supply one destination
     */
    public function strategyArguments(): array
    {
        $arguments = [];

        foreach ($this->bindings as $binding) {
            $target = $binding->strategyArgument;

            if ($target === null) {
                continue;
            }

            if (isset($arguments[$target])) {
                throw new InvalidArgumentException('Multiple parameters supply strategy argument $'.$target.'.');
            }

            $arguments[$target] = $binding->name;
        }

        return $arguments;
    }

    /**
     * @param array<array-key, mixed> $arguments
     */
    public function bind(array $arguments): ParameterConfiguration
    {
        if ($this->bindings === []) {
            return new ParameterConfiguration();
        }

        $values = (new CallArguments())->bind($this->method, $arguments);
        $ttl = null;
        $tags = [];
        $visibility = Visibility::Shared;
        $strategy = [];

        foreach ($this->bindings as $binding) {
            $value = $values[$binding->name];

            if ($binding->ttl) {
                $seconds = $this->ttl($value);
                $ttl = $ttl === null ? $seconds : min($ttl, $seconds);
            }

            if ($binding->tags) {
                $tags = [...$tags, ...$this->tags($value)];
            }

            if ($binding->visibility) {
                $visibility = $visibility->meet($this->visibility($value));
            }

            if ($binding->strategyArgument !== null) {
                $strategy[$binding->strategyArgument] = $value;
            }
        }

        return new ParameterConfiguration($ttl, $tags, $visibility, $strategy);
    }

    /**
     * @return int<0, max>
     * @throws InvalidArgumentException when a TTL argument is not a non-negative integer
     */
    public function ttl(mixed $value): int
    {
        if (!is_int($value) || $value < 0) {
            throw new InvalidArgumentException('CacheTtl requires non-negative integer seconds.');
        }

        return $value;
    }

    /**
     * @return list<non-empty-string>
     * @throws InvalidArgumentException when a tags argument is not a list of strings
     */
    public function tags(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException('CacheTags requires a list of strings.');
        }

        $tags = [];

        foreach ($value as $tag) {
            if (!is_string($tag)) {
                throw new InvalidArgumentException('CacheTags requires string tokens.');
            }

            $tags[] = $tag;
        }

        return (new CacheTokenSet())->tags($tags);
    }

    /**
     * @throws InvalidArgumentException when a visibility argument is not the enum
     */
    public function visibility(mixed $value): Visibility
    {
        if (!$value instanceof Visibility) {
            throw new InvalidArgumentException('CacheVisibility requires a Metadata\\Visibility value.');
        }

        return $value;
    }
}
