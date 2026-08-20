<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime\KeyStrategy;

use Magix\Cache\Runtime\CacheKeyContext;
use Magix\Cache\Runtime\KeyStrategy\HashCacheKeyStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\KeyDto;

#[CoversClass(HashCacheKeyStrategy::class)]
#[UsesClass(CacheKeyContext::class)]
final class HashCacheKeyStrategyTest extends TestCase
{
    public function testGenerateHashesClassMethodArgumentsAndVersion(): void
    {
        $strategy = new HashCacheKeyStrategy();
        $context = new CacheKeyContext(
            class: 'App\\ProductQuery',
            method: 'execute',
            arguments: ['productId' => 1],
            version: '1',
        );

        $key = $strategy->generate($context);

        self::assertSame($key, $strategy->generate($context));
        self::assertSame(64, strlen($key));
        self::assertNotSame($key, $strategy->generate(new CacheKeyContext(
            class: 'App\\ProductQuery',
            method: 'execute',
            arguments: ['productId' => 2],
            version: '1',
        )));
        self::assertNotSame($key, $strategy->generate(new CacheKeyContext(
            class: 'App\\ProductQuery',
            method: 'execute',
            arguments: ['productId' => 1],
            version: '2',
        )));
    }

    public function testGeneratePreservesPhpSerializableTypeIdentity(): void
    {
        $strategy = new HashCacheKeyStrategy();
        $integer = $strategy->generate(new CacheKeyContext('App\\Query', 'execute', ['value' => 1], '1'));
        $string = $strategy->generate(new CacheKeyContext('App\\Query', 'execute', ['value' => '1'], '1'));
        $firstObject = $strategy->generate(new CacheKeyContext('App\\Query', 'execute', ['value' => new KeyDto(1)], '1'));
        $secondObject = $strategy->generate(new CacheKeyContext('App\\Query', 'execute', ['value' => new KeyDto(1)], '1'));

        self::assertNotSame($integer, $string);
        self::assertSame($firstObject, $secondObject);
    }
}
