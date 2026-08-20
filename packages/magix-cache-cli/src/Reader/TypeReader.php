<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Reader;

use function array_map;
use function implode;
use function in_array;

use PhpParser\Node\ComplexType;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\UnionType;

use function strtolower;

/**
 * Converts parsed type declarations into class names and readable labels.
 */
final readonly class TypeReader
{
    /**
     * Type names that never identify a resolvable class declaration.
     */
    private const array RELATIVE = ['self', 'static', 'parent'];

    /**
     * Returns the single class name a type refers to, when it has one.
     */
    public function className(Identifier|Name|ComplexType|null $type): ?string
    {
        if ($type instanceof NullableType) {
            return $this->className($type->type);
        }

        if (!$type instanceof Name) {
            return null;
        }

        $name = $type->toString();

        return in_array(strtolower($name), self::RELATIVE, true) ? null : $name;
    }

    /**
     * Returns the type rendered the way it is written in the source.
     */
    public function label(Identifier|Name|ComplexType|null $type): ?string
    {
        if ($type instanceof Identifier || $type instanceof Name) {
            return $type->toString();
        }

        if ($type instanceof NullableType) {
            $inner = $this->label($type->type);

            return $inner === null ? null : '?'.$inner;
        }

        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            $separator = $type instanceof UnionType ? '|' : '&';

            return implode($separator, array_map(
                fn (Identifier|Name|IntersectionType $inner): string => $this->label($inner) ?? 'mixed',
                $type->types,
            ));
        }

        return null;
    }
}
