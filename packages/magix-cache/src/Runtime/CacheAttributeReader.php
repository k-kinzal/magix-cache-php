<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime;

use function count;

use LogicException;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

/**
 * Reads one attribute kind with method-over-class precedence.
 *
 * A method-level attribute replaces the class-level attribute of the same
 * kind as a whole. Class defaults are read from the concrete class only:
 * parent-class attributes are never inherited implicitly, while an inherited
 * method keeps the attributes of the method it reflects to.
 *
 * @internal
 */
final readonly class CacheAttributeReader
{
    /**
     * Returns the effective declaration of one attribute kind, when present.
     *
     * @template A of object
     * @param class-string<A> $attribute
     * @return A|null
     * @throws LogicException when the boundary cannot be reflected or repeats a declaration
     */
    public function read(object $service, string $methodName, string $attribute): ?object
    {
        try {
            $method = new ReflectionMethod($service, $methodName);
        } catch (ReflectionException $missing) {
            throw new LogicException($service::class.'::'.$methodName.' is not a method that can be reflected.', previous: $missing);
        }

        $attributes = $method->getAttributes($attribute);

        if ($attributes === []) {
            $attributes = (new ReflectionClass($service))->getAttributes($attribute);
        }

        if (count($attributes) > 1) {
            throw new LogicException($service::class.'::'.$methodName.' repeats #['.$attribute.'] on one declaration target.');
        }

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }
}
