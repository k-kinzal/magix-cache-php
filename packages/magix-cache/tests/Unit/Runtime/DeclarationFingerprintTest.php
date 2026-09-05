<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use Magix\Cache\Runtime\CacheDefinitionResolver;
use Magix\Cache\Runtime\DeclarationFingerprint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\DeclaredQuery;

#[CoversClass(DeclarationFingerprint::class)]
#[UsesClass(CacheDefinitionResolver::class)]
#[UsesClass(\Magix\Cache\Runtime\CacheAttributeReader::class)]
#[UsesClass(\Magix\Cache\Runtime\CacheDefinition::class)]
#[UsesClass(\Magix\Cache\Runtime\CacheKeyArgumentBinder::class)]
#[UsesClass(\Magix\Cache\Runtime\CacheKeyContext::class)]
#[UsesClass(\Magix\Cache\Runtime\CacheKeyReducer::class)]
#[UsesClass(\Magix\Cache\Attribute\BypassCacheErrors::class)]
#[UsesClass(\Magix\Cache\Attribute\Cache::class)]
#[UsesClass(\Magix\Cache\Attribute\DynamicTtl::class)]
#[UsesClass(\Magix\Cache\Attribute\StaleIfError::class)]
#[UsesClass(\Magix\Cache\CachePolicy::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheTokenSet::class)]
#[UsesClass(\Magix\Cache\Metadata\Visibility::class)]
final class DeclarationFingerprintTest extends TestCase
{
    public function testCalculateIsStableForEqualEffectiveDeclarations(): void
    {
        $resolver = new CacheDefinitionResolver();
        $first = $resolver->resolve(new DeclaredQuery(), 'viaMethod')->keyContext([1]);
        $second = $resolver->resolve(new DeclaredQuery(), 'sameAsViaMethod')->keyContext([1]);

        self::assertSame($first->fingerprint, $second->fingerprint);
    }

    public function testCalculateSeparatesChangedDeclarations(): void
    {
        $resolver = new CacheDefinitionResolver();
        $method = $resolver->resolve(new DeclaredQuery(), 'viaMethod')->keyContext([1]);
        $class = $resolver->resolve(new DeclaredQuery(), 'viaClass')->keyContext([1]);

        self::assertNotSame($method->fingerprint, $class->fingerprint);
    }
}
