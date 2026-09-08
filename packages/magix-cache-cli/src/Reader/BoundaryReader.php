<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Reader;

use function is_string;

use Magix\Cache\Attribute\Cache;
use Magix\Cache\Attribute\CacheComment;
use Magix\Cache\Attribute\DynamicTtl;
use Magix\Cache\Attribute\UseStrategy;
use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\PolicyDeclaration;
use Magix\Cache\Cli\Declaration\PolicySource;
use Magix\Cache\Cli\Declaration\UseStrategyDeclaration;
use Magix\Cache\Metadata\CacheMetadata;
use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
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
        private UseStrategyReader $useStrategies = new UseStrategyReader(),
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
        ?UseStrategyDeclaration $classUseStrategy = null,
        ?string $classComment = null,
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
            useStrategy: $this->useStrategy($method, $classUseStrategy),
            comment: $this->comment($method->attrGroups, $classComment),
        );
    }

    /**
     * Reads an uncached entry point with its comment, without applying cache policies or behaviors.
     *
     * Call this only after read() has established that the method has no cached() call.
     *
     * @param array<string, string> $propertyTypes
     */
    public function entryPoint(ClassMethod $method, string $class, string $file, array $propertyTypes, ?string $classComment = null): ?BoundaryDeclaration
    {
        $parameters = $this->parameters->read($method);
        $dependencies = $this->dependencies->read($method, $class, $propertyTypes, $parameters);

        if ($dependencies === []) {
            return null;
        }

        return new BoundaryDeclaration(
            class: $class,
            method: $method->name->toString(),
            file: $file,
            line: $method->getStartLine(),
            dependencies: $dependencies,
            isCacheBoundary: false,
            comment: $this->comment($method->attrGroups, $classComment),
        );
    }

    /**
     * Reads a literal comment, replacing the class default when declared.
     *
     * Unreadable expressions stay visible as an unresolved comment; they are
     * never evaluated and never fall back to a misleading class comment.
     *
     * @param array<AttributeGroup> $groups
     */
    public function comment(array $groups, ?string $classComment = null): ?string
    {
        $attribute = $this->attributes->find($groups, CacheComment::class);

        if ($attribute === null) {
            return $classComment;
        }

        $values = $this->arguments->values($attribute->args, ['comment']);
        $comment = $values['comment'] ?? null;

        return is_string($comment) && $comment !== LiteralReader::UNRESOLVED ? $comment : '(unresolved #[CacheComment])';
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
     * Returns the strategy usage a class declares for all of its boundaries.
     */
    public function classUseStrategy(Class_ $class): ?UseStrategyDeclaration
    {
        $attribute = $this->attributes->find($class->attrGroups, UseStrategy::class);

        return $attribute === null ? null : $this->useStrategies->read($attribute);
    }

    /**
     * Returns the strategy usage that applies to a boundary method.
     *
     * A method-level declaration replaces the class-level default as a
     * whole, so a disabled method declaration leaves the boundary without a
     * strategy.
     */
    public function useStrategy(ClassMethod $method, ?UseStrategyDeclaration $classUseStrategy): ?UseStrategyDeclaration
    {
        $attribute = $this->attributes->find($method->attrGroups, UseStrategy::class);

        if ($attribute !== null) {
            return $this->useStrategies->read($attribute);
        }

        return $classUseStrategy;
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
