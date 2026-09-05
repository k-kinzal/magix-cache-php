<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Declaration;

use Magix\Cache\Cli\Declaration\PolicyDeclaration;
use Magix\Cache\Cli\Declaration\PolicySource;
use Magix\Cache\Metadata\Visibility;
use Magix\Cache\Runtime\Policy\Ttl;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PolicyDeclaration::class)]
final class PolicyDeclarationTest extends TestCase
{
    public function testLabelRendersEveryDeclaredOption(): void
    {
        $policy = new PolicyDeclaration(
            source: PolicySource::MethodAttribute,
            ttl: 30,
            maxTtl: 60,
            tags: ['product'],
            visibility: Visibility::Private,
            version: '2',
            runtime: 'edge',
        );

        self::assertSame(
            '#[Cache(ttl: 30s, maxTtl: 60, tags: [product], visibility: Private, version: 2, runtime: edge)]',
            $policy->label(),
        );
    }

    public function testLabelOmitsDefaultOptions(): void
    {
        $policy = new PolicyDeclaration(source: PolicySource::ClassAttribute, ttl: 10);

        self::assertSame('default', $policy->runtime);
        self::assertSame('#[Cache(ttl: 10s)]', $policy->label());
    }

    public function testTtlLabelDescribesInheritedAndUnreadableModes(): void
    {
        $auto = new PolicyDeclaration(source: PolicySource::MethodAttribute, ttl: Ttl::Auto);
        $unresolved = new PolicyDeclaration(source: PolicySource::MethodAttribute, ttl: null);

        self::assertSame('Ttl::Auto', $auto->ttlLabel());
        self::assertSame('unresolved', $unresolved->ttlLabel());
    }
}
