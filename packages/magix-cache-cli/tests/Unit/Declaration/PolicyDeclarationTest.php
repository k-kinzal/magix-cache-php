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

    public function testLabelOmitsAutomaticTtlAndRetainsAdditionalOptions(): void
    {
        $plain = new PolicyDeclaration(source: PolicySource::MethodAttribute);
        $tagged = new PolicyDeclaration(source: PolicySource::ClassAttribute, ttl: Ttl::Auto, tags: ['page']);
        $capped = new PolicyDeclaration(source: PolicySource::MethodAttribute, ttl: Ttl::FromUpstream, maxTtl: 30);
        $unresolved = new PolicyDeclaration(source: PolicySource::MethodAttribute, ttl: null);

        self::assertSame('#[Cache]', $plain->label());
        self::assertSame('#[Cache(tags: [page])]', $tagged->label());
        self::assertSame('#[Cache(ttl: Ttl::FromUpstream, maxTtl: 30)]', $capped->label());
        self::assertSame('#[Cache(ttl: unresolved)]', $unresolved->label());
    }

    public function testVersionLabelNeverSubstitutesTheDefaultForAnUnreadableVersion(): void
    {
        self::assertSame('v7', (new PolicyDeclaration(PolicySource::MethodAttribute, version: 'v7'))->versionLabel());
        self::assertSame(
            'unresolved',
            (new PolicyDeclaration(PolicySource::MethodAttribute, versionUnknown: true))->versionLabel(),
        );
    }
}
