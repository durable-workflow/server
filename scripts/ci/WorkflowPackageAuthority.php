<?php

declare(strict_types=1);

namespace DurableWorkflow\Server\Ci;

use RuntimeException;

final class WorkflowPackageAuthority
{
    public const PACKAGE = 'durable-workflow/workflow';

    /**
     * @param  array<string, string|null>  $overrides
     * @return array{source:string, ref:string, commit:string}
     */
    public static function resolve(string $composerPath, string $lockPath, array $overrides = []): array
    {
        $composer = self::readJsonObject($composerPath);
        $lock = self::readJsonObject($lockPath);
        $require = $composer['require'][self::PACKAGE] ?? null;

        if (! is_string($require) || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?$/D', $require) !== 1) {
            throw new RuntimeException(
                'composer.json must require '.self::PACKAGE.' at one exact package version.',
            );
        }

        $packages = [];
        foreach (['packages', 'packages-dev'] as $section) {
            foreach ($lock[$section] ?? [] as $package) {
                if (is_array($package) && ($package['name'] ?? null) === self::PACKAGE) {
                    $packages[] = $package;
                }
            }
        }

        if (count($packages) !== 1) {
            throw new RuntimeException('composer.lock must contain exactly one '.self::PACKAGE.' package entry.');
        }

        $package = $packages[0];
        $lockedVersion = $package['version'] ?? null;
        $sourceType = $package['source']['type'] ?? null;
        $source = $package['source']['url'] ?? null;
        $commit = $package['source']['reference'] ?? null;

        if ($lockedVersion !== $require) {
            throw new RuntimeException(
                sprintf('Composer requires %s, but composer.lock contains %s.', $require, self::printable($lockedVersion)),
            );
        }

        if (
            $sourceType !== 'git'
            || ! is_string($source)
            || $source === ''
            || preg_match('/[\x00\r\n]/', $source) === 1
        ) {
            throw new RuntimeException('The locked Workflow package must declare a non-empty Git source URL.');
        }

        if (! is_string($commit) || preg_match('/^[0-9a-f]{40}$/D', $commit) !== 1) {
            throw new RuntimeException('The locked Workflow package source reference must be a full lowercase Git SHA.');
        }

        $dist = $package['dist'] ?? null;
        if (is_array($dist)) {
            $distReference = $dist['reference'] ?? null;
            $distUrl = $dist['url'] ?? null;

            if ($distReference !== $commit) {
                throw new RuntimeException('The locked Workflow package dist reference must match its source commit.');
            }

            if (! is_string($distUrl) || ! str_contains($distUrl, $commit)) {
                throw new RuntimeException('The locked Workflow package dist URL must identify its source commit.');
            }
        }

        $authority = [
            'source' => $source,
            'ref' => $require,
            'commit' => $commit,
        ];

        foreach ([
            'WORKFLOW_PACKAGE_SOURCE' => 'source',
            'WORKFLOW_PACKAGE_REF' => 'ref',
            'WORKFLOW_PACKAGE_COMMIT' => 'commit',
            'WORKFLOW_PACKAGE_QUALIFICATION_REF' => 'commit',
        ] as $environmentName => $field) {
            $override = $overrides[$environmentName] ?? null;

            if ($override !== null && $override !== '' && $override !== $authority[$field]) {
                throw new RuntimeException(
                    sprintf(
                        '%s=%s disagrees with the Composer Workflow package authority %s.',
                        $environmentName,
                        $override,
                        $authority[$field],
                    ),
                );
            }
        }

        return $authority;
    }

    /**
     * @return array<string, mixed>
     */
    private static function readJsonObject(string $path): array
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Cannot read {$path}.");
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            throw new RuntimeException("Cannot parse {$path} as a JSON object.");
        }

        return $decoded;
    }

    private static function printable(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : get_debug_type($value);
    }
}
