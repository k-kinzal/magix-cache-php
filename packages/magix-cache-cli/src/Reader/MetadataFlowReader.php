<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Reader;

use Magix\Cache\Cached;
use Magix\Cache\Cli\Declaration\MetadataFlow;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\UnionType;

/**
 * Reads return paths without treating every call in a method as metadata composition.
 */
final readonly class MetadataFlowReader
{
    /**
     * Built-in return types that never carry cache metadata.
     */
    private const array WITHOUT_METADATA = ['int', 'float', 'string', 'bool', 'array', 'void', 'null', 'false', 'true', 'never', 'iterable', 'callable'];

    /**
     * Reports whether a declared return type rules out carrying cache metadata.
     *
     * Cached is final, so a return type that names anything else cannot be
     * one. Nothing is assumed from an absent or dynamic return type.
     */
    public function returnsNoMetadata(ClassMethod $method): bool
    {
        $type = $method->returnType;

        if ($type instanceof NullableType) {
            $type = $type->type;
        }

        $names = $type instanceof UnionType || $type instanceof IntersectionType ? $type->types : [$type];

        foreach ($names as $name) {
            if (!$name instanceof Identifier && !$name instanceof Name) {
                return false;
            }

            if ($name instanceof Name && $name->toString() === Cached::class) {
                return false;
            }

            if ($name instanceof Identifier && !in_array($name->toString(), self::WITHOUT_METADATA, true)) {
                return false;
            }
        }

        return $names !== [];
    }

    /**
     * @param array<string, string> $propertyTypes
     */
    public function read(ClassMethod $method, string $class, array $propertyTypes): MetadataFlow
    {
        if ($this->returnsNoMetadata($method)) {
            return new MetadataFlow('none');
        }

        $dependencies = new DependencyReader();
        $expressions = new ExpressionFlowReader(
            $class,
            $propertyTypes,
            $dependencies->parameterTypes($method),
            $dependencies->variableTypes($method->stmts ?? [], $propertyTypes),
        );

        return (new StatementFlowReader($expressions))->read(array_values($method->stmts ?? []));
    }
}
