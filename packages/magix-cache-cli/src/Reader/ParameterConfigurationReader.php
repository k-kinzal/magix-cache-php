<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Reader;

use Magix\Cache\Attribute\CacheTags;
use Magix\Cache\Attribute\CacheTtl;
use Magix\Cache\Attribute\CacheVisibility;
use Magix\Cache\Attribute\StrategyArgument;
use Magix\Cache\Cli\Declaration\ParameterConfiguration;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;

/**
 * Reads configuration bindings without instantiating attributes or user code.
 */
final readonly class ParameterConfigurationReader
{
    /**
     * @param array<AttributeGroup> $groups
     */
    public function read(array $groups): ?ParameterConfiguration
    {
        $found = [];
        $problems = [];

        foreach ($groups as $group) {
            foreach ($group->attrs as $attribute) {
                $name = $attribute->name->toString();

                if (!in_array($name, [CacheTtl::class, CacheTags::class, CacheVisibility::class, StrategyArgument::class], true)) {
                    continue;
                }

                if (isset($found[$name])) {
                    $problems[] = 'repeats '.$name;
                }

                $found[$name] = $attribute;
            }
        }

        if ($found === []) {
            return null;
        }

        $strategy = $found[StrategyArgument::class] ?? null;
        $target = $strategy === null ? null : $this->target($strategy);

        if ($strategy !== null && $target === '') {
            $problems[] = 'StrategyArgument requires a non-empty destination name';
        }

        return new ParameterConfiguration(
            ttl: isset($found[CacheTtl::class]),
            tags: isset($found[CacheTags::class]),
            visibility: isset($found[CacheVisibility::class]),
            strategyArgument: $target,
            problems: $problems,
        );
    }

    /**
     * Returns the explicitly named factory parameter, or an invalid empty name.
     */
    public function target(Attribute $attribute): string
    {
        $values = (new ArgumentReader())->values($attribute->args, ['name']);
        $name = $values['name'] ?? null;

        return is_string($name) && $name !== LiteralReader::UNRESOLVED ? $name : '';
    }
}
