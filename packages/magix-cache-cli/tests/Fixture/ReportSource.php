<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture;

use Magix\Cache\Cli\Declaration\Catalog;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Graph\CacheTree;
use Magix\Cache\Cli\Source\SourceParser;

/**
 * Exercises migration and partial results without executing the inspected application.
 */
final readonly class ReportSource
{
    /**
     * Includes ordinary call branches independently of presentation options.
     */
    public static function node(string $reference): CacheNode
    {
        $source = Invariance::source(<<<'PHP'
            class Leaf {
                use Cacheable;
                #[Cache(ttl: 10, tags: ['leaf'])]
                public function get(): Cached { return $this->cached(fn () => 1); }
            }
            class Utility {
                public function get() { return opaqueUtility(); }
            }
            class Bridge {
                public function __construct(private Leaf $leaf) {}
                public function get() { return opaqueTransform($this->leaf->get()); }
            }
            class Page {
                use Cacheable;
                public function __construct(private Leaf $leaf, private Bridge $bridge, private Utility $utility) {}
                #[Cache(ttl: 60)]
                public function clean(): Cached { return $this->cached(fn () => $this->leaf->get()); }
                #[Cache(ttl: 60)]
                public function unrelated(): Cached {
                    $this->utility->get();
                    return $this->cached(fn () => $this->leaf->get());
                }
                #[Cache]
                public function automatic(): Cached { return $this->cached(fn () => $this->bridge->get()); }
                #[Cache(ttl: 60)]
                public function fixed(): Cached { return $this->cached(fn () => $this->bridge->get()); }
                #[Cache(ttl: 60, tags: [], visibility: Visibility::Shared)]
                public function replaced(): Cached { return $this->cached(fn () => $this->bridge->get()); }
                #[Cache(ttl: 60)]
                public function multiple(): Cached {
                    return $this->cached(fn () => $this->automatic()->zip($this->fixed()));
                }
                #[Cache(ttl: -1)]
                public function invalid(): Cached { return $this->cached(fn () => $this->leaf->get()); }
            }
            class Migration {
                public function __construct(private Leaf $leaf) {}
                #[Cache(ttl: 60)]
                #[\Magix\Cache\Attribute\UseStrategy('ExternalStrategy', limit: UNKNOWN_LIMIT)]
                public function get(#[\Magix\Cache\Attribute\CacheTtl] int $ttl = 30): Cached { return $this->leaf->get(); }
            }
            class TypedOnly {
                public function __construct(private Leaf $leaf) {}
                public function get(): Cached { return $this->leaf->get(); }
            }
            class ExecutionOnly {
                use Cacheable;
                public function __construct(private Leaf $leaf) {}
                public function get(): Cached { return $this->cached(fn () => $this->leaf->get()); }
            }
            class Sandwich {
                use Cacheable;
                public function __construct(private TypedOnly $typed, private ExecutionOnly $execution, private Utility $utility) {}
                #[Cache(ttl: 60)]
                public function get(): Cached {
                    $this->utility->get();
                    return $this->cached(fn () => $this->typed->get()->zip($this->execution->get()));
                }
            }
            class Controller {
                public function __construct(private Page $page, private Migration $migration, private Utility $utility) {}
                public function run(): array { return [$this->page->unrelated(), $this->migration->get(), $this->utility->get()]; }
            }
            PHP);
        $parser = new SourceParser();
        $uri = 'data:text/plain;base64,'.base64_encode($source);
        $catalog = new Catalog($parser->parse($uri, 'report.php', $parser->constants($uri)));

        return (new CacheTree($catalog))->build($catalog->search($reference, includeEntryPoints: true)[0], includeUncached: true);
    }
}
