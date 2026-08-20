<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Source;

use function is_string;

use Magix\Cache\Cli\Declaration\ClassDeclaration;
use Magix\Cache\Cli\Reader\BoundaryReader;
use Magix\Cache\Cli\Reader\TypeReader;
use Override;
use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects class declarations and their cache boundaries from one file.
 */
final class ClassVisitor extends NodeVisitorAbstract
{
    /**
     * Declarations collected so far.
     *
     * @var list<ClassDeclaration>
     */
    private array $collected = [];

    /**
     * Creates a class visitor for one already name-resolved file.
     */
    public function __construct(
        private readonly string $file,
        private readonly BoundaryReader $boundaries = new BoundaryReader(),
        private readonly TypeReader $types = new TypeReader(),
    ) {
    }

    /**
     * Collects one class declaration when the traverser reaches it.
     */
    #[Override]
    public function enterNode(Node $node): ?Node
    {
        if (!$node instanceof Class_ || $node->name === null) {
            return null;
        }

        $name = $node->namespacedName?->toString() ?? $node->name->toString();
        $classPolicy = $this->boundaries->classPolicy($node);
        $propertyTypes = $this->propertyTypes($node);
        $boundaries = [];

        foreach ($node->getMethods() as $method) {
            $boundary = $this->boundaries->read($method, $name, $this->file, $propertyTypes, $classPolicy);

            if ($boundary !== null) {
                $boundaries[] = $boundary;
            }
        }

        $parents = [];

        if ($node->extends !== null) {
            $parents[] = $node->extends->toString();
        }

        foreach ($node->implements as $interface) {
            $parents[] = $interface->toString();
        }

        $this->collected[] = new ClassDeclaration($name, $parents, $boundaries);

        return null;
    }

    /**
     * Returns the declared class type of every property, including promoted ones.
     *
     * @return array<string, string>
     */
    public function propertyTypes(Class_ $node): array
    {
        $types = [];

        foreach ($node->getProperties() as $property) {
            $type = $this->types->className($property->type);

            if ($type === null) {
                continue;
            }

            foreach ($property->props as $declared) {
                $types[$declared->name->toString()] = $type;
            }
        }

        $constructor = $node->getMethod('__construct');

        if ($constructor === null) {
            return $types;
        }

        foreach ($constructor->params as $parameter) {
            $type = $this->types->className($parameter->type);

            if ($parameter->flags === 0 || $type === null) {
                continue;
            }

            if ($parameter->var instanceof Variable && is_string($parameter->var->name)) {
                $types[$parameter->var->name] = $type;
            }
        }

        return $types;
    }

    /**
     * Returns every class declaration collected from the file.
     *
     * @return list<ClassDeclaration>
     */
    public function declarations(): array
    {
        return $this->collected;
    }
}
