<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime\Parameter;

use InvalidArgumentException;
use Magix\Cache\Attribute\CacheIgnore;
use Magix\Cache\Attribute\CacheTags;
use Magix\Cache\Attribute\CacheTtl;
use Magix\Cache\Attribute\CacheVisibility;
use Magix\Cache\Attribute\StrategyArgument;
use ReflectionParameter;

/**
 * Holds the destinations of one boundary parameter, never its value.
 *
 * @internal
 */
final readonly class ParameterBinding
{
    /**
     * @param string|null $strategyArgument Destination on the strategy factory.
     */
    public function __construct(
        public string $name,
        public bool $ttl = false,
        public bool $tags = false,
        public bool $visibility = false,
        public ?string $strategyArgument = null,
    ) {
    }

    /**
     * @throws InvalidArgumentException when a binding is repeated, ignored or variadic
     */
    public static function read(ReflectionParameter $parameter): ?self
    {
        $attributes = [];

        foreach ([CacheTtl::class, CacheTags::class, CacheVisibility::class, StrategyArgument::class] as $class) {
            $declared = $parameter->getAttributes($class);

            if (count($declared) > 1) {
                throw new InvalidArgumentException('$'.$parameter->getName().' repeats '.$class.'.');
            }

            if ($declared !== []) {
                $attributes[$class] = $declared[0]->newInstance();
            }
        }

        if ($attributes === []) {
            return null;
        }

        if ($parameter->isVariadic() || $parameter->getAttributes(CacheIgnore::class) !== []) {
            throw new InvalidArgumentException('A cache configuration parameter cannot be variadic or ignored: $'.$parameter->getName().'.');
        }

        $strategy = $attributes[StrategyArgument::class] ?? null;

        return new self(
            $parameter->getName(),
            isset($attributes[CacheTtl::class]),
            isset($attributes[CacheTags::class]),
            isset($attributes[CacheVisibility::class]),
            $strategy instanceof StrategyArgument ? $strategy->name : null,
        );
    }
}
