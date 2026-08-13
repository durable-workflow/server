#!/usr/bin/env php
<?php

declare(strict_types=1);

use DurableWorkflow\Server\Ci\WorkflowPackageAuthority;

require_once __DIR__.'/WorkflowPackageAuthority.php';

$format = 'human';
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--format=')) {
        $format = substr($argument, strlen('--format='));
    }
}

if (! in_array($format, ['human', 'shell'], true)) {
    fwrite(STDERR, "ERROR: --format must be human or shell.\n");
    exit(1);
}

$composerPath = getenv('COMPOSER_JSON_PATH') ?: getcwd().'/composer.json';
$lockPath = getenv('COMPOSER_LOCK_PATH') ?: dirname($composerPath).'/composer.lock';
$overrides = [];

foreach ([
    'WORKFLOW_PACKAGE_SOURCE',
    'WORKFLOW_PACKAGE_REF',
    'WORKFLOW_PACKAGE_COMMIT',
    'WORKFLOW_PACKAGE_QUALIFICATION_REF',
] as $name) {
    $value = getenv($name);
    $overrides[$name] = is_string($value) ? $value : null;
}

try {
    $authority = WorkflowPackageAuthority::resolve($composerPath, $lockPath, $overrides);
} catch (Throwable $throwable) {
    fwrite(STDERR, "ERROR: {$throwable->getMessage()}\n");
    exit(1);
}

$outputPath = getenv('GITHUB_OUTPUT');
if (is_string($outputPath) && $outputPath !== '') {
    $output = '';
    foreach ($authority as $name => $value) {
        $output .= "{$name}={$value}\n";
    }
    $output .= "tag={$authority['ref']}\n";
    file_put_contents($outputPath, $output, FILE_APPEND);
}

if ($format === 'shell') {
    foreach ([
        'WORKFLOW_PACKAGE_SOURCE' => $authority['source'],
        'WORKFLOW_PACKAGE_REF' => $authority['ref'],
        'WORKFLOW_PACKAGE_COMMIT' => $authority['commit'],
    ] as $name => $value) {
        printf("export %s=%s\n", $name, escapeshellarg($value));
    }

    exit(0);
}

printf(
    "Workflow package authority: %s at %s from %s\n",
    $authority['ref'],
    $authority['commit'],
    $authority['source'],
);
