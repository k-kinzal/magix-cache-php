<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use Magix\Cache\Runtime\CacheKeyContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheKeyContext::class)]
final class CacheKeyContextTest extends TestCase
{
    public function testPropertiesExposeNormalizedInvocation(): void
    {
        $context = new CacheKeyContext(
            namespace: 'magix',
            class: 'App\\ConcreteProductQuery',
            declaringClass: 'App\\ProductQuery',
            method: 'execute',
            arguments: ['productId' => 1],
            version: '2',
            fingerprint: 'digest',
        );

        self::assertSame('magix', $context->namespace);
        self::assertSame('App\\ConcreteProductQuery', $context->class);
        self::assertSame('App\\ProductQuery', $context->declaringClass);
        self::assertSame('execute', $context->method);
        self::assertSame(['productId' => 1], $context->arguments);
        self::assertSame('2', $context->version);
        self::assertSame('digest', $context->fingerprint);
    }

    public function testWithNamespacePlacesTheContextInARuntimeNamespace(): void
    {
        $context = new CacheKeyContext('', 'App\\Query', 'App\\Query', 'execute', ['id' => 1], '1', 'digest');

        $placed = $context->withNamespace('runtime-a');

        self::assertSame('runtime-a', $placed->namespace);
        self::assertSame($context->class, $placed->class);
        self::assertSame($context->arguments, $placed->arguments);
        self::assertSame($context->fingerprint, $placed->fingerprint);
    }
}
