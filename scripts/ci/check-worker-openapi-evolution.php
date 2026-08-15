#!/usr/bin/env php
<?php

declare(strict_types=1);

use DurableWorkflow\Server\Ci\OpenApiDocumentEvolution;
use Symfony\Component\Yaml\Yaml;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/OpenApiDocumentEvolution.php';

$specPath = 'resources/platform-protocol-specs/worker-protocol-api.openapi.yaml';
$baseRef = $argv[1] ?? 'HEAD^';

if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]*(?:\^)?$/', $baseRef) !== 1) {
    throw new RuntimeException('The OpenAPI evolution base ref contains unsupported characters.');
}

$baseCommit = runGit(['rev-parse', '--verify', $baseRef.'^{commit}']);
$previousSource = runGit(['show', $baseCommit.':'.$specPath]);
$candidateSource = file_get_contents(dirname(__DIR__, 2).'/'.$specPath);

if ($candidateSource === false) {
    throw new RuntimeException("Could not read candidate worker OpenAPI at {$specPath}.");
}

$previous = Yaml::parse($previousSource);
$candidate = Yaml::parse($candidateSource);

if (! is_array($previous) || ! is_array($candidate)) {
    throw new RuntimeException('Worker OpenAPI documents must parse as mappings.');
}

$result = OpenApiDocumentEvolution::assertVersionedChange($previous, $candidate);
$change = $result['semantic_shape_changed'] ? 'semantic shape changed' : 'description-only or equivalent shape';

fwrite(STDOUT, sprintf(
    "Worker OpenAPI evolution passed: version %s -> %s; %s.\n",
    $result['previous_version'],
    $result['candidate_version'],
    $change,
));

/**
 * @param  list<string>  $arguments
 */
function runGit(array $arguments): string
{
    $command = ['git', ...$arguments];
    $process = proc_open($command, [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, dirname(__DIR__, 2));

    if (! is_resource($process)) {
        throw new RuntimeException('Could not start git for OpenAPI evolution validation.');
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        throw new RuntimeException(sprintf(
            'git %s failed: %s',
            implode(' ', $arguments),
            trim((string) $stderr),
        ));
    }

    return trim((string) $stdout);
}
