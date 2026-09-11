<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Reader;

use Magix\Cache\Cli\Declaration\ConstantCatalog;
use Magix\Cache\Cli\Declaration\PolicyDeclaration;
use Magix\Cache\Cli\Declaration\PolicySource;
use Magix\Cache\Cli\Reader\ArgumentReader;
use Magix\Cache\Cli\Reader\LiteralReader;
use Magix\Cache\Cli\Reader\PolicyReader;
use Magix\Cache\Metadata\Visibility;
use Magix\Cache\Runtime\Policy\Ttl;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PolicyReader::class)]
#[UsesClass(ArgumentReader::class)]
#[UsesClass(ConstantCatalog::class)]
#[UsesClass(LiteralReader::class)]
#[UsesClass(PolicyDeclaration::class)]
final class PolicyReaderTest extends TestCase
{
    public function testReadUnderstandsEveryPolicyOption(): void
    {
        $policy = (new PolicyReader())->read([
            new Arg(new Int_(30)),
            new Arg(new Array_([new ArrayItem(new String_('page'))])),
            new Arg(new ClassConstFetch(new Name(Visibility::class), 'Private')),
            new Arg(new String_('v2')),
            new Arg(new String_('edge')),
        ], PolicySource::MethodAttribute);

        self::assertSame(30, $policy->ttl);
        self::assertSame(['page'], $policy->tags);
        self::assertSame(Visibility::Private, $policy->visibility);
        self::assertSame('v2', $policy->version);
        self::assertSame('edge', $policy->runtime);
        self::assertSame(PolicySource::MethodAttribute, $policy->source);
    }

    public function testReadFallsBackToTheRuntimeDefaults(): void
    {
        $policy = (new PolicyReader())->read([], PolicySource::ClassAttribute);

        self::assertSame(Ttl::Auto, $policy->ttl);
        self::assertNull($policy->tags);
        self::assertNull($policy->visibility);
        self::assertSame('1', $policy->version);
        self::assertSame('default', $policy->runtime);
    }

    public function testReadKeepsAnUnreadableTtlUnresolved(): void
    {
        $policy = (new PolicyReader())->read(
            [
                new Arg(new Variable('ttl'), name: new Identifier('ttl')),
                new Arg(new Variable('runtime'), name: new Identifier('runtime')),
            ],
            PolicySource::MethodAttribute,
        );

        self::assertNull($policy->ttl);
        self::assertSame('default', $policy->runtime);
    }
    public function testReadDistinguishesExplicitEmptyAndSharedFieldsFromInheritance(): void
    {
        $policy = (new PolicyReader())->read([
            new Arg(new Array_([]), name: new Identifier('tags')),
            new Arg(new ClassConstFetch(new Name(Visibility::class), 'Shared'), name: new Identifier('visibility')),
        ], PolicySource::MethodAttribute);

        self::assertSame([], $policy->tags);
        self::assertSame(Visibility::Shared, $policy->visibility);
        self::assertSame('#[Cache(tags: [], visibility: Shared)]', $policy->label());
    }

    public function testReadKeepsOpaqueMetadataOverridesUnknown(): void
    {
        $policy = (new PolicyReader())->read([
            new Arg(new Variable('tags'), name: new Identifier('tags')),
            new Arg(new Variable('visibility'), name: new Identifier('visibility')),
        ], PolicySource::MethodAttribute);

        self::assertTrue($policy->tagsUnknown);
        self::assertTrue($policy->visibilityUnknown);
    }
}
