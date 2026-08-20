<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Reader;

use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;

/**
 * Finds one resolved attribute among the groups declared on a node.
 */
final readonly class AttributeReader
{
    /**
     * Returns the first attribute with the given fully qualified name.
     *
     * @param array<AttributeGroup> $groups
     */
    public function find(array $groups, string $name): ?Attribute
    {
        foreach ($groups as $group) {
            foreach ($group->attrs as $attribute) {
                if ($attribute->name->toString() === $name) {
                    return $attribute;
                }
            }
        }

        return null;
    }
}
