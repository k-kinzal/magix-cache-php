<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Declaration;

use Magix\Cache\Cli\Declaration\PolicyDeclaration;
use Magix\Cache\Cli\Declaration\PolicySource;
use Magix\Cache\Runtime\Metadata\Visibility;
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
            clamp: false,
            version: '2',
        );

        self::assertSame(
            '#[Cache(ttl: 30s, maxTtl: 60, tags: [product], visibility: Private, clamp: false, version: 2)]',
            $policy->label(),
        );
    }

    public function testLabelOmitsDefaultOptions(): void
    {
        $policy = new PolicyDeclaration(source: PolicySource::ClassAttribute, ttl: 10);

        self::assertSame('#[Cache(ttl: 10s)]', $policy->label());
    }

    public function testTtlLabelDescribesInheritedAndUnreadableModes(): void
    {
        $auto = new PolicyDeclaration(source: PolicySource::MethodAttribute, ttl: Ttl::Auto);
        $unresolved = new PolicyDeclaration(source: PolicySource::Unresolved, ttl: null);

        self::assertSame('Ttl::Auto', $auto->ttlLabel());
        self::assertSame('unresolved', $unresolved->ttlLabel());
    }
}
