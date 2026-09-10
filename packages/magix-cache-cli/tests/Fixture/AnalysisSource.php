<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture;

use Magix\Cache\Cli\Declaration\Catalog;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Graph\CacheTree;
use Magix\Cache\Cli\Source\SourceParser;

/**
 * Parses example return paths without executing their application code.
 */
final readonly class AnalysisSource
{
    /**
     * Builds a cached parent over an ordinary method or a direct origin body.
     */
    public static function node(string $body, string $policy = '#[Cache]', bool $direct = false, ?string $origin = null): CacheNode
    {
        $origin ??= $direct ? $body : 'return $this->bridge->pick($flag);';
        $source = <<<'PHP'
            <?php
            use Magix\Cache\Attribute\Cache;
            use Magix\Cache\Cacheable;
            use Magix\Cache\Cached;
            use Magix\Cache\Metadata\Visibility;
            use Magix\Cache\Runtime\Policy\Ttl;
            class Inputs {
                use Cacheable;
                #[Cache(ttl: 20, tags: ['a'])]
                public function a(): Cached { return $this->cached(fn () => Cached::of(1)); }
                #[Cache(ttl: 60, visibility: Visibility::Private, tags: ['b'])]
                public function b(): Cached { return $this->cached(fn () => Cached::of(2)); }
                #[Cache(ttl: 90, visibility: Visibility::NoStore, tags: ['c'])]
                public function c(): Cached { return $this->cached(fn () => Cached::of(3)); }
            }
            class Bridge {
                public function __construct(private Inputs $inputs) {}
                public function pick(int $flag) { BODY }
            }
            class Root {
                use Cacheable;
                public function __construct(private Bridge $bridge, private Inputs $inputs) {}
                POLICY
                public function run(int $flag): Cached { return $this->cached(function () use ($flag) { ORIGIN }); }
            }
            PHP;
        $source = str_replace(['BODY', 'POLICY', 'ORIGIN'], [$body, $policy, $origin], $source);
        $catalog = new Catalog((new SourceParser())->parse('data:text/plain;base64,'.base64_encode($source), 'branches.php'));

        return (new CacheTree($catalog))->build($catalog->search('Root::run')[0]);
    }

    /**
     * Returns one parsed statement, with the source positions it was written at.
     */
    public static function statement(string $code): \PhpParser\Node\Stmt
    {
        $statements = (new \PhpParser\ParserFactory())->createForNewestSupportedVersion()->parse('<?php '.$code) ?? [];

        return $statements[0];
    }
}
