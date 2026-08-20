<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Lint;

use Magix\Cache\Cli\Declaration\Catalog;
use Magix\Cache\Cli\Graph\CacheNode;

/**
 * Inspects one analyzed boundary and reports what is wrong with it.
 */
interface LintRule
{
    /**
     * Returns every finding for one boundary and its resolved dependencies.
     *
     * @return list<Diagnostic>
     */
    public function check(CacheNode $node, Catalog $catalog): array;
}
