<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime\Extension;

use Magix\Cache\Attribute\BypassCacheErrors;
use Magix\Cache\Attribute\DynamicTtl;
use Magix\Cache\Runtime\Extension\DefaultBackendErrorClassifier;
use Magix\Cache\Runtime\Extension\RegisteredExtensions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\FixedTtlResolver;

#[CoversClass(RegisteredExtensions::class)]
#[UsesClass(BypassCacheErrors::class)]
#[UsesClass(DynamicTtl::class)]
#[UsesClass(DefaultBackendErrorClassifier::class)]
final class RegisteredExtensionsTest extends TestCase
{
    public function testTtlResolverReturnsTheRegisteredInstance(): void
    {
        $resolver = new FixedTtlResolver(5);
        $extensions = new RegisteredExtensions(ttlResolvers: [$resolver]);

        self::assertSame($resolver, $extensions->ttlResolver(new DynamicTtl(resolver: FixedTtlResolver::class)));
        self::assertNull($extensions->ttlResolver(null));
    }

    public function testClassifierDefaultsWhenTheBehaviorNamesNone(): void
    {
        $extensions = new RegisteredExtensions();

        self::assertInstanceOf(DefaultBackendErrorClassifier::class, $extensions->classifier(new BypassCacheErrors()));
        self::assertNull($extensions->classifier(null));
    }

    public function testClassifierReturnsTheRegisteredInstance(): void
    {
        $classifier = new DefaultBackendErrorClassifier();
        $extensions = new RegisteredExtensions(errorClassifiers: [$classifier]);

        self::assertSame(
            $classifier,
            $extensions->classifier(new BypassCacheErrors(classifier: DefaultBackendErrorClassifier::class)),
        );
    }
}
