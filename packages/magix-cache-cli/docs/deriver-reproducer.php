<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Deriver\Analyzer;
use Deriver\Project\ProjectInput;
use Deriver\Project\SourceFile;
use Deriver\Query\ReturnQuery;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$cases = [
    'float-concatenation' => ["'v' . 0.25", 'Magix resolves v0.25; Deriver reports FLOAT_STRING_CONFIGURATION.'],
    'null-array-key' => ["[null => 'a']", 'Magix appends at key 0; PHP and Deriver use the empty string key.'],
    'numeric-string' => ["'60' + 5", 'Magix leaves coercion unresolved; PHP and Deriver resolve 65.'],
    'undefined-variable' => ['$missing', 'Magix leaves this unresolved; Deriver has a concrete null with a PHP_WARNING frontier.'],
    'host-deprecation' => ['0 ** -1', 'Both yield INF, but PHP 8.5 emits a host deprecation while Deriver targets PHP 8.3.'],
];

try {
    $report = [
        'deriver' => InstalledVersions::getReference('k-kinzal/deriver'),
        'phpParser' => InstalledVersions::getPrettyVersion('nikic/php-parser'),
        'hostPhp' => PHP_VERSION,
        'hostPrecision' => ini_get('precision'),
        'target' => 'php-8.3-64bit',
        'cases' => [],
    ];
    $analyzer = new Analyzer();

    foreach ($cases as $name => [$expression, $expectation]) {
        $source = '<?php function resolve() { return '.$expression.'; }';
        $result = $analyzer->open(new ProjectInput([new SourceFile('reproducer.php', $source)]))
            ->derive(new ReturnQuery('resolve'));
        $report['cases'][$name] = [
            'source' => $source,
            'expectation' => $expectation,
            'result' => json_decode($result->toJson(), true, flags: JSON_THROW_ON_ERROR),
        ];
    }

    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (JsonException $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);

    exit(1);
}
