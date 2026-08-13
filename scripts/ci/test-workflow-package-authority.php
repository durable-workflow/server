#!/usr/bin/env php
<?php

declare(strict_types=1);

use DurableWorkflow\Server\Ci\WorkflowPackageAuthority;

require_once __DIR__.'/WorkflowPackageAuthority.php';

$root = dirname(__DIR__, 2);
$staleVersion = '2.0.0-rc.13';
$staleCommit = 'fc28432a82a2433959c6690505c52eabea4aca8c';
$fixtureVersion = '9.8.7-test.1';
$fixtureCommit = str_repeat('f', 40);
$tmp = sys_get_temp_dir().'/server-workflow-authority-'.bin2hex(random_bytes(6));

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @return array<string, mixed>
 */
function readJson(string $path): array
{
    $contents = file_get_contents($path);
    check(is_string($contents), "Cannot read {$path}.");
    $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    check(is_array($decoded), "{$path} must contain a JSON object.");

    return $decoded;
}

function writeJson(string $path, array $value): void
{
    file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
}

function removeTree(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $child = $path.'/'.$entry;
        if (is_dir($child)) {
            removeTree($child);
        } else {
            unlink($child);
        }
    }

    rmdir($path);
}

try {
    check(mkdir($tmp), "Cannot create {$tmp}.");
    $composerPath = $tmp.'/composer.json';
    $lockPath = $tmp.'/composer.lock';
    $workflowPath = $tmp.'/workflow';
    check(mkdir($workflowPath), "Cannot create {$workflowPath}.");

    $composer = readJson($root.'/composer.json');
    $lock = readJson($root.'/composer.lock');
    $composer['require'][WorkflowPackageAuthority::PACKAGE] = $fixtureVersion;

    $fixturePackageCount = 0;
    foreach ($lock['packages'] as &$package) {
        if (($package['name'] ?? null) !== WorkflowPackageAuthority::PACKAGE) {
            continue;
        }

        $fixturePackageCount++;
        $package['version'] = $fixtureVersion;
        $package['source']['reference'] = $fixtureCommit;
        $package['dist']['reference'] = $fixtureCommit;
        $package['dist']['url'] = preg_replace(
            '/[0-9a-f]{40}$/D',
            $fixtureCommit,
            (string) $package['dist']['url'],
        );
    }
    unset($package);
    check($fixturePackageCount === 1, 'The fixture must mutate one locked Workflow package.');

    writeJson($composerPath, $composer);
    writeJson($lockPath, $lock);

    $authority = WorkflowPackageAuthority::resolve($composerPath, $lockPath);
    check($authority['ref'] === $fixtureVersion, 'The resolver did not follow the changed Composer requirement.');
    check($authority['commit'] === $fixtureCommit, 'The resolver did not follow the changed locked source commit.');

    foreach ([
        'WORKFLOW_PACKAGE_SOURCE' => 'https://example.invalid/workflow.git',
        'WORKFLOW_PACKAGE_REF' => $staleVersion,
        'WORKFLOW_PACKAGE_COMMIT' => $staleCommit,
        'WORKFLOW_PACKAGE_QUALIFICATION_REF' => $staleCommit,
    ] as $name => $value) {
        try {
            WorkflowPackageAuthority::resolve($composerPath, $lockPath, [$name => $value]);
            throw new RuntimeException("{$name} accepted a stale override.");
        } catch (RuntimeException $exception) {
            check(
                str_contains($exception->getMessage(), 'disagrees with the Composer Workflow package authority'),
                "{$name} did not fail with the authority mismatch.",
            );
        }
    }

    $mismatchedComposer = $composer;
    $mismatchedComposer['require'][WorkflowPackageAuthority::PACKAGE] = '9.8.7-test.2';
    writeJson($composerPath, $mismatchedComposer);
    try {
        WorkflowPackageAuthority::resolve($composerPath, $lockPath);
        throw new RuntimeException('A Composer manifest/lock version mismatch was accepted.');
    } catch (RuntimeException $exception) {
        check(
            str_contains($exception->getMessage(), 'composer.lock contains'),
            'The Composer manifest/lock mismatch did not fail closed.',
        );
    }
    writeJson($composerPath, $composer);

    file_put_contents(
        $workflowPath.'/.package-provenance',
        implode("\n", [$authority['source'], $fixtureVersion, $fixtureCommit])."\n",
    );
    $metadataCommand = [PHP_BINARY, $root.'/scripts/ci/prepare-release-workflow-composer-metadata.php'];
    $pipes = [];
    $process = proc_open($metadataCommand, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, [
        ...getenv(),
        'COMPOSER_JSON_PATH' => $composerPath,
        'COMPOSER_LOCK_PATH' => $lockPath,
        'WORKFLOW_PACKAGE_PATH' => $workflowPath,
    ]);
    check(is_resource($process), 'Cannot run the Composer metadata consumer.');
    $metadataStdout = stream_get_contents($pipes[1]);
    $metadataStderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $metadataExit = proc_close($process);
    check($metadataExit === 0, "Composer metadata consumer failed: {$metadataStdout}{$metadataStderr}");
    $preparedComposer = readJson($composerPath);
    check(
        ($preparedComposer['repositories'][0]['options']['versions'][WorkflowPackageAuthority::PACKAGE] ?? null) === $fixtureVersion,
        'Composer metadata generation did not follow the changed locked identity.',
    );

    $consumers = [
        'Dockerfile',
        'docker-compose.yml',
        'docker-compose.small-cluster.yml',
        '.github/workflows/release.yml',
        '.github/workflows/phpunit-feature.yml',
        '.github/workflows/server-perf.yml',
        '.github/workflows/replay-query-concurrent-http.yml',
        '.github/workflows/helm-chart-checks.yml',
        'scripts/ci/prepare-release-workflow-composer-metadata.php',
        'scripts/ci/verify-release-exact-images.sh',
        'scripts/ci/verify-release-protocol-catalog.sh',
    ];

    foreach ($consumers as $path) {
        $contents = file_get_contents($root.'/'.$path);
        check(is_string($contents), "Cannot read authority consumer {$path}.");
        check(! str_contains($contents, $staleVersion), "{$path} retains the stale Workflow package version.");
        check(! str_contains($contents, $staleCommit), "{$path} retains the stale Workflow package commit.");
    }

    foreach (['docker-compose.yml', 'docker-compose.small-cluster.yml'] as $path) {
        $contents = (string) file_get_contents($root.'/'.$path);
        foreach (['SOURCE', 'REF', 'COMMIT'] as $field) {
            check(
                str_contains($contents, "WORKFLOW_PACKAGE_{$field}: \${WORKFLOW_PACKAGE_{$field}:-}"),
                "{$path} must pass only an optional Workflow package {$field} override to the Dockerfile.",
            );
        }
    }

    foreach ([
        'Dockerfile',
        '.github/workflows/release.yml',
        '.github/workflows/phpunit-feature.yml',
        '.github/workflows/server-perf.yml',
        '.github/workflows/replay-query-concurrent-http.yml',
        '.github/workflows/helm-chart-checks.yml',
    ] as $path) {
        $contents = (string) file_get_contents($root.'/'.$path);
        check(
            str_contains($contents, 'resolve-workflow-package-authority.php'),
            "{$path} does not resolve the Composer Workflow authority.",
        );
    }

    $releaseWorkflow = (string) file_get_contents($root.'/.github/workflows/release.yml');
    foreach ([
        'dev.durable-workflow.workflow.source=${{ steps.workflow.outputs.source }}',
        'dev.durable-workflow.workflow.version=${{ steps.workflow.outputs.ref }}',
        'dev.durable-workflow.workflow.commit=${{ steps.workflow.outputs.commit }}',
        'WORKFLOW_PACKAGE_SOURCE: ${{ steps.workflow.outputs.source }}',
        'WORKFLOW_PACKAGE_REF: ${{ steps.workflow.outputs.ref }}',
        'WORKFLOW_PACKAGE_COMMIT: ${{ steps.workflow.outputs.commit }}',
        'Verify published protocol catalog convergence',
    ] as $contract) {
        check(str_contains($releaseWorkflow, $contract), "Release authority consumer is missing {$contract}.");
    }

    check(
        ! str_contains($releaseWorkflow, 'Select compatible workflow package version'),
        'Release must not select an older protocol-compatible Workflow package.',
    );

    $protocolCatalog = (string) file_get_contents($root.'/scripts/ci/verify-release-protocol-catalog.sh');
    check(
        str_contains($protocolCatalog, 'WORKFLOW_PACKAGE_SOURCE="$workflow_package_source"'),
        'Protocol-catalog evidence does not follow the locked Workflow package source.',
    );

    fwrite(STDOUT, "Workflow package authority structural regression passed.\n");
} catch (Throwable $throwable) {
    fwrite(STDERR, "ERROR: {$throwable->getMessage()}\n");
    exit(1);
} finally {
    removeTree($tmp);
}
