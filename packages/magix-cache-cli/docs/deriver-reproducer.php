<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Deriver\Analyzer;
use Deriver\Project\Configuration;
use Deriver\Project\ProjectInput;
use Deriver\Project\SourceFile;
use Deriver\Project\TargetProfile;
use Deriver\Query\ReturnQuery;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$cases = [
    'float-without-precision' => ["'v' . 0.25", 'Unresolved with FLOAT_STRING_CONFIGURATION when precision is not captured.', null],
    'float-with-precision' => ["'v' . 0.25", 'Definite v0.25 with captured precision.', 14],
    'precision-three' => ["'v' . (1 / 3)", 'Definite v0.333, independently of host precision.', 3],
    'precision-seventeen' => ["'v' . (1 / 3)", 'Definite v0.33333333333333331, independently of host precision.', 17],
    'null-array-key' => ["[null => 'a']", 'Magix appends at key 0; PHP and Deriver use the empty string key.', 14],
    'numeric-string' => ["'60' + 5", 'Magix leaves coercion unresolved; PHP and Deriver resolve 65.', 14],
    'undefined-variable' => ['$missing', 'An unresolved dependency; definite() must reject it.', 14],
    'concrete-null' => ['null', 'A definite null must remain distinguishable from rejection.', 14],
    'host-deprecation' => ['0 ** -1', 'Definite INF with no PHP 8.5 host deprecation.', 14],
];

$size = isset($argv[1]) ? max(1, (int) $argv[1]) : 0;
$variant = $argv[2] ?? 'plain';

if ($size > 0) {
    $items = array_map(static fn (int $index): string => (string) $index, range(0, $size - 1));
    $items[0] = match ($variant) {
        'negative-key' => '-1 => 0',
        'negative-string-key' => "'-1' => 0",
        'negative-expression-key' => '-(1 + 0) => 0',
        default => '0',
    };
    $cases['large-array'] = ['['.implode(',', $items).']', 'The original Magix reader resolves every element; inspect latency and budget frontiers.', 14];
}

try {
    $report = [
        'deriver' => InstalledVersions::getReference('k-kinzal/deriver'),
        'phpParser' => InstalledVersions::getPrettyVersion('nikic/php-parser'),
        'hostPhp' => PHP_VERSION,
        'hostPrecision' => ini_get('precision'),
        'target' => 'php-8.3-64bit',
        'arrayVariant' => $variant,
        'cases' => [],
    ];
    $analyzer = new Analyzer();

    foreach ($cases as $name => [$expression, $expectation, $precision]) {
        $source = '<?php function resolve() { return '.$expression.'; }';
        $start = hrtime(true);
        $result = $analyzer->open(
            new ProjectInput([new SourceFile('reproducer.php', $source)]),
            new Configuration(new TargetProfile(floatPrecision: $precision)),
        )
            ->derive(new ReturnQuery('resolve'));
        $report['cases'][$name] = [
            'source' => $source,
            'expectation' => $expectation,
            'targetPrecision' => $precision,
            'milliseconds' => (hrtime(true) - $start) / 1_000_000,
            'definite' => $result->definite() !== null,
            'result' => json_decode($result->toJson(), true, flags: JSON_THROW_ON_ERROR),
        ];
    }

    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (JsonException $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);

    exit(1);
}
