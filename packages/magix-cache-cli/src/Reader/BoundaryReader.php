<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Reader;

use Magix\Cache\Attribute\Cache;
use Magix\Cache\Attribute\DynamicTtl;
use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\PolicyDeclaration;
use Magix\Cache\Cli\Declaration\PolicySource;
use Magix\Cache\Metadata\CacheMetadata;
use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;

/**
 * Reads one cached() call site as a complete boundary declaration.
 *
 * cached() takes only the computation: the policy, the runtime reference,
 * and every behavior come from attributes on the method or its class.
 */
final readonly class BoundaryReader
{
    /**
     * Parameter order of the #[DynamicTtl] attribute.
     */
    private const array DYNAMIC_TTL_OPTIONS = ['resolver', 'enabled'];

    /**
     * Creates a boundary reader.
     */
    public function __construct(
        private AttributeReader $attributes = new AttributeReader(),
        private PolicyReader $policies = new PolicyReader(),
        private ParameterReader $parameters = new ParameterReader(),
        private DependencyReader $dependencies = new DependencyReader(),
        private ArgumentReader $arguments = new ArgumentReader(),
        private NodeFinder $finder = new NodeFinder(),
    ) {
    }

    /**
     * Returns the boundary a method declares, or null when it caches nothing.
     *
     * @param array<string, string> $propertyTypes
     */
    public function read(
        ClassMethod $method,
        string $class,
        string $file,
        array $propertyTypes,
        ?PolicyDeclaration $classPolicy,
        bool $classDynamicTtl = false,
    ): ?BoundaryDeclaration {
        $statements = $method->stmts ?? [];

        if ($statements === []) {
            return null;
        }

        $call = $this->finder->findFirst($statements, static fn (Node $node): bool => $node instanceof MethodCall
            && $node->var instanceof Variable
            && $node->var->name === 'this'
            && $node->name instanceof Identifier
            && $node->name->toString() === 'cached');

        if (!$call instanceof MethodCall) {
            return null;
        }

        $parameters = $this->parameters->read($method);

        return new BoundaryDeclaration(
            class: $class,
            method: $method->name->toString(),
            file: $file,
            line: $method->getStartLine(),
            policy: $this->policy($method, $classPolicy),
            parameters: $parameters,
            dependencies: $this->dependencies->read($method, $class, $propertyTypes, $parameters),
            hasDynamicTtl: $this->dynamicTtl($method, $classDynamicTtl),
            suppliesMetadata: $this->finder->findFirst(
                $statements,
                static fn (Node $node): bool => $node instanceof Name && $node->toString() === CacheMetadata::class,
            ) !== null,
        );
    }

    /**
     * Returns the policy a class declares for all of its boundaries.
     */
    public function classPolicy(Class_ $class): ?PolicyDeclaration
    {
        $attribute = $this->attributes->find($class->attrGroups, Cache::class);

        return $attribute === null ? null : $this->policies->read($attribute->args, PolicySource::ClassAttribute);
    }

    /**
     * Reports whether the class declares an enabled #[DynamicTtl] default.
     */
    public function classDynamicTtl(Class_ $class): bool
    {
        $attribute = $this->attributes->find($class->attrGroups, DynamicTtl::class);

        return $attribute !== null && $this->enabled($attribute);
    }

    /**
     * Returns the policy that applies to a cache boundary method.
     */
    public function policy(ClassMethod $method, ?PolicyDeclaration $classPolicy): ?PolicyDeclaration
    {
        $attribute = $this->attributes->find($method->attrGroups, Cache::class);

        if ($attribute !== null) {
            return $this->policies->read($attribute->args, PolicySource::MethodAttribute);
        }

        return $classPolicy;
    }

    /**
     * Reports whether an enabled #[DynamicTtl] applies to a boundary method.
     *
     * A method-level declaration replaces the class-level default as a whole.
     */
    public function dynamicTtl(ClassMethod $method, bool $classDynamicTtl): bool
    {
        $attribute = $this->attributes->find($method->attrGroups, DynamicTtl::class);

        if ($attribute === null) {
            return $classDynamicTtl;
        }

        return $this->enabled($attribute);
    }

    /**
     * Reports whether a #[DynamicTtl] declaration is not explicitly disabled.
     */
    public function enabled(Attribute $attribute): bool
    {
        $values = $this->arguments->values($attribute->args, self::DYNAMIC_TTL_OPTIONS);

        return ($values['enabled'] ?? true) !== false;
    }
}
