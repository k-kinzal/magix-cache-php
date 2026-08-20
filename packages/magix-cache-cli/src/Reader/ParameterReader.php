<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Reader;

use function count;
use function implode;
use function is_array;
use function is_string;

use Magix\Cache\Attribute\CacheIgnore;
use Magix\Cache\Attribute\CacheKey;
use Magix\Cache\Attribute\CacheScope;
use Magix\Cache\Cli\Declaration\KeyParameter;
use Magix\Cache\Runtime\Metadata\Visibility;
use PhpParser\Node\Arg;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\ClassMethod;

/**
 * Reads the parameters of a boundary and the attributes that shape its key.
 */
final readonly class ParameterReader
{
    /**
     * Creates a parameter reader.
     */
    public function __construct(
        private AttributeReader $attributes = new AttributeReader(),
        private LiteralReader $literals = new LiteralReader(),
        private TypeReader $types = new TypeReader(),
    ) {
    }

    /**
     * Returns every declared parameter of a boundary method.
     *
     * @return list<KeyParameter>
     */
    public function read(ClassMethod $method): array
    {
        $parameters = [];

        foreach ($method->params as $parameter) {
            if (!$parameter->var instanceof Variable || !is_string($parameter->var->name)) {
                continue;
            }

            $parameters[] = new KeyParameter(
                name: $parameter->var->name,
                type: $this->types->label($parameter->type),
                ignored: $this->attributes->find($parameter->attrGroups, CacheIgnore::class) !== null,
                scope: $this->scope($parameter->attrGroups),
                reducer: $this->reducer($parameter->attrGroups),
                variadic: $parameter->variadic,
                optional: $parameter->default !== null || $parameter->variadic,
            );
        }

        return $parameters;
    }

    /**
     * Returns the visibility declared by #[CacheScope] on a parameter.
     *
     * @param array<AttributeGroup> $groups
     */
    public function scope(array $groups): ?Visibility
    {
        $attribute = $this->attributes->find($groups, CacheScope::class);

        if ($attribute === null) {
            return null;
        }

        $argument = $attribute->args[0] ?? null;

        if (!$argument instanceof Arg) {
            return Visibility::Private;
        }

        $value = $this->literals->value($argument->value);

        return $value instanceof Visibility ? $value : Visibility::Private;
    }

    /**
     * Returns the reducer declared by #[CacheKey] on a parameter.
     *
     * @param array<AttributeGroup> $groups
     */
    public function reducer(array $groups): ?string
    {
        $attribute = $this->attributes->find($groups, CacheKey::class);

        if ($attribute === null) {
            return null;
        }

        $argument = $attribute->args[0] ?? null;

        if (!$argument instanceof Arg) {
            return 'callable';
        }

        $value = $this->literals->value($argument->value);

        if (!is_array($value) || count($value) !== 2) {
            return 'callable';
        }

        $callable = array_values(array_filter($value, is_string(...)));

        return count($callable) === 2 ? implode('::', $callable) : 'callable';
    }
}
