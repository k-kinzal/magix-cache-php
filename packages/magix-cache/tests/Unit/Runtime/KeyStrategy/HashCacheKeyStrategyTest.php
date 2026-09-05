<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime\KeyStrategy;

use Magix\Cache\Runtime\CacheKeyContext;
use Magix\Cache\Runtime\KeyStrategy\HashCacheKeyStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function strlen;

use Tests\Fixture\KeyDto;

#[CoversClass(HashCacheKeyStrategy::class)]
#[UsesClass(CacheKeyContext::class)]
final class HashCacheKeyStrategyTest extends TestCase
{
    /**
     * @return array<string, array{CacheKeyContext}>
     */
    public static function providerDistinctContexts(): array
    {
        return [
            'different namespace' => [new CacheKeyContext('other', 'App\\Q', 'App\\Q', 'execute', ['id' => 1], '1', 'f1')],
            'different concrete class' => [new CacheKeyContext('magix', 'App\\OtherQ', 'App\\Q', 'execute', ['id' => 1], '1', 'f1')],
            'different arguments' => [new CacheKeyContext('magix', 'App\\Q', 'App\\Q', 'execute', ['id' => 2], '1', 'f1')],
            'different version' => [new CacheKeyContext('magix', 'App\\Q', 'App\\Q', 'execute', ['id' => 1], '2', 'f1')],
            'different fingerprint' => [new CacheKeyContext('magix', 'App\\Q', 'App\\Q', 'execute', ['id' => 1], '1', 'f2')],
        ];
    }

    public function testGenerateIsDeterministic(): void
    {
        $strategy = new HashCacheKeyStrategy();
        $context = new CacheKeyContext('magix', 'App\\Q', 'App\\Q', 'execute', ['id' => 1], '1', 'f1');

        self::assertSame($strategy->generate($context), $strategy->generate($context));
        self::assertSame(64, strlen($strategy->generate($context)));
    }

    #[DataProvider('providerDistinctContexts')]
    public function testGenerateSeparatesEveryIdentityField(CacheKeyContext $other): void
    {
        $strategy = new HashCacheKeyStrategy();
        $baseline = new CacheKeyContext('magix', 'App\\Q', 'App\\Q', 'execute', ['id' => 1], '1', 'f1');

        self::assertNotSame($strategy->generate($baseline), $strategy->generate($other));
    }

    public function testGeneratePreservesPhpSerializableTypeIdentity(): void
    {
        $strategy = new HashCacheKeyStrategy();
        $integer = $strategy->generate(new CacheKeyContext('magix', 'App\\Q', 'App\\Q', 'execute', ['value' => 1], '1', 'f'));
        $string = $strategy->generate(new CacheKeyContext('magix', 'App\\Q', 'App\\Q', 'execute', ['value' => '1'], '1', 'f'));
        $firstObject = $strategy->generate(new CacheKeyContext('magix', 'App\\Q', 'App\\Q', 'execute', ['value' => new KeyDto(1)], '1', 'f'));
        $secondObject = $strategy->generate(new CacheKeyContext('magix', 'App\\Q', 'App\\Q', 'execute', ['value' => new KeyDto(1)], '1', 'f'));

        self::assertNotSame($integer, $string);
        self::assertSame($firstObject, $secondObject);
    }
}
