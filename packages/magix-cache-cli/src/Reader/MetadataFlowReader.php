<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Reader;

use Magix\Cache\Cli\Declaration\MetadataFlow;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\ClassMethod;

/**
 * Reads return paths without treating every call in a method as metadata composition.
 */
final readonly class MetadataFlowReader
{
    /**
     * @param array<string, string> $propertyTypes
     */
    public function read(ClassMethod $method, string $class, array $propertyTypes): MetadataFlow
    {
        if ($method->returnType instanceof Identifier && in_array($method->returnType->toString(), ['int', 'float', 'string', 'bool', 'array', 'void', 'null', 'false', 'true'], true)) {
            return new MetadataFlow('none');
        }

        $dependencies = new DependencyReader();
        $types = [...$dependencies->parameterTypes($method), ...$dependencies->variableTypes($method->stmts ?? [], $propertyTypes)];

        return (new StatementFlowReader(new ExpressionFlowReader($class, $propertyTypes, $types)))->read(array_values($method->stmts ?? []));
    }
}
