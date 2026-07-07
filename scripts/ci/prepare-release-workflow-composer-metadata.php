#!/usr/bin/env php
<?php

declare(strict_types=1);

$packageName = 'durable-workflow/workflow';
$workflowRef = getenv('WORKFLOW_PACKAGE_REF') ?: '2.0.0-alpha.250';
$workflowCommit = getenv('WORKFLOW_PACKAGE_COMMIT') ?: 'cdb59bc5e27401be6749c893b28636a24b1f6530';
$workflowPath = getenv('WORKFLOW_PACKAGE_PATH') ?: '/workflow';
$composerPath = getenv('COMPOSER_JSON_PATH') ?: getcwd().'/composer.json';
$provenancePath = $workflowPath.'/.package-provenance';

/**
 * @return never
 */
function fail(string $message): void
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}

/**
 * @return array<string, mixed>
 */
function readJsonObject(string $path): array
{
    $contents = file_get_contents($path);

    if ($contents === false) {
        fail("Cannot read {$path}.");
    }

    $decoded = json_decode($contents, true);

    if (! is_array($decoded)) {
        fail("Cannot parse {$path} as a JSON object.");
    }

    return $decoded;
}

/**
 * @param array<string, mixed> $contents
 */
function writeJsonObject(string $path, array $contents): void
{
    $encoded = json_encode($contents, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if (! is_string($encoded)) {
        fail("Cannot encode {$path}.");
    }

    if (file_put_contents($path, $encoded."\n") === false) {
        fail("Cannot write {$path}.");
    }
}

function composerVersionForRef(string $ref): string
{
    if (preg_match('/^v?[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?$/', $ref) === 1) {
        return $ref;
    }

    if (str_starts_with($ref, 'dev-')) {
        return $ref;
    }

    return 'dev-'.$ref;
}

if (! is_file($composerPath)) {
    fail("Composer manifest {$composerPath} does not exist.");
}

if (! is_dir($workflowPath)) {
    fail("Workflow package path {$workflowPath} does not exist.");
}

if (is_file($provenancePath)) {
    $provenance = file($provenancePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if (! is_array($provenance) || count($provenance) < 3) {
        fail("Workflow package provenance {$provenancePath} must contain source, ref, and commit lines.");
    }

    $provenanceRef = trim($provenance[1]);
    $provenanceCommit = trim($provenance[2]);

    if ($provenanceRef !== $workflowRef) {
        fail("Workflow package provenance ref {$provenanceRef} does not match WORKFLOW_PACKAGE_REF={$workflowRef}.");
    }

    if ($workflowCommit !== '' && $provenanceCommit !== $workflowCommit) {
        fail("Workflow package provenance commit {$provenanceCommit} does not match WORKFLOW_PACKAGE_COMMIT={$workflowCommit}.");
    }
}

$composerVersion = composerVersionForRef($workflowRef);
$composer = readJsonObject($composerPath);

if (! isset($composer['require']) || ! is_array($composer['require'])) {
    fail("Composer manifest {$composerPath} must contain a require object.");
}

$composer['require'][$packageName] = $composerVersion;

if (! isset($composer['repositories']) || ! is_array($composer['repositories'])) {
    fail("Composer manifest {$composerPath} must contain repository entries for {$packageName}.");
}

$updatedRepository = false;

foreach ($composer['repositories'] as &$repository) {
    if (! is_array($repository)) {
        continue;
    }

    $name = $repository['name'] ?? null;
    $type = $repository['type'] ?? null;
    $url = $repository['url'] ?? null;

    if ($type !== 'path') {
        continue;
    }

    if ($name !== 'workflow' && $url !== $workflowPath && $url !== '../workflow') {
        continue;
    }

    if (! isset($repository['options']) || ! is_array($repository['options'])) {
        $repository['options'] = [];
    }

    if (! isset($repository['options']['versions']) || ! is_array($repository['options']['versions'])) {
        $repository['options']['versions'] = [];
    }

    $repository['options']['versions'][$packageName] = $composerVersion;
    $repository['options']['reference'] = 'auto';
    $updatedRepository = true;
}

unset($repository);

if (! $updatedRepository) {
    fail("Composer manifest {$composerPath} does not contain a path repository for {$packageName}.");
}

writeJsonObject($composerPath, $composer);

$commitSuffix = $workflowCommit !== '' ? " at {$workflowCommit}" : '';
fwrite(
    STDOUT,
    "Prepared Composer metadata for {$packageName}: {$composerVersion} from {$workflowRef}{$commitSuffix}.\n",
);
