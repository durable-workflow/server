<?php

declare(strict_types=1);

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class BoundedGrowthPolicyTest extends TestCase
{
    private static string $repoRoot;

    /** @var array<string, mixed> */
    private static array $policy;

    public static function setUpBeforeClass(): void
    {
        self::$repoRoot = dirname(__DIR__, 2);
        self::$policy = require self::$repoRoot.'/config/dw-bounded-growth.php';
    }

    public function test_every_server_cache_prefix_literal_in_app_source_is_declared(): void
    {
        $declared = $this->declaredCachePrefixes();
        $seen = $this->serverCachePrefixesInAppSource();

        $this->assertNotEmpty($seen, 'No server cache prefixes were found in app source.');

        foreach ($seen as $prefix) {
            $this->assertTrue(
                $this->sourcePrefixHasPolicy($prefix, $declared),
                "{$prefix} appears in app source but is missing from config/dw-bounded-growth.php cache_keys.",
            );
        }
    }

    public function test_every_declared_cache_prefix_is_still_used_by_app_source(): void
    {
        $seen = $this->serverCachePrefixesInAppSource();

        foreach ($this->declaredCachePrefixes() as $prefix) {
            $this->assertTrue(
                $this->policyPrefixAppearsInSource($prefix, $seen),
                "{$prefix} is declared in config/dw-bounded-growth.php but was not found in app source.",
            );
        }
    }

    public function test_every_dw_metric_literal_in_bounded_growth_source_is_declared(): void
    {
        $declared = array_keys($this->policyMetrics());
        $seen = $this->metricNamesInBoundedGrowthSource();

        $this->assertNotEmpty($seen, 'No dw_* metric names were found in bounded-growth source.');

        foreach ($seen as $metric) {
            $this->assertContains(
                $metric,
                $declared,
                "{$metric} appears in bounded-growth source but is missing from config/dw-bounded-growth.php metrics.",
            );
        }
    }

    public function test_every_declared_metric_is_still_used_by_bounded_growth_source(): void
    {
        $seen = $this->metricNamesInBoundedGrowthSource();

        foreach (array_keys($this->policyMetrics()) as $metric) {
            $this->assertContains(
                $metric,
                $seen,
                "{$metric} is declared in config/dw-bounded-growth.php but was not found in bounded-growth source.",
            );
        }
    }

    public function test_cache_policy_entries_have_review_fields(): void
    {
        $required = [
            'owner',
            'prefix',
            'dimensions',
            'ttl',
            'bound',
            'admission',
            'eviction',
        ];

        foreach ($this->policyCacheKeys() as $id => $entry) {
            $this->assertIsString($id);
            $this->assertNotSame('', $id);

            foreach ($required as $field) {
                $this->assertArrayHasKey($field, $entry, "{$id} is missing {$field}");
            }

            $this->assertIsString($entry['owner'], "{$id}.owner must be a class string");
            $this->assertNotSame('', $entry['owner'], "{$id}.owner must not be empty");
            $this->assertIsString($entry['prefix'], "{$id}.prefix must be a string");
            $this->assertStringStartsWith('server:', $entry['prefix'], "{$id}.prefix must use the server: namespace");
            $this->assertIsArray($entry['dimensions'], "{$id}.dimensions must be an array");
            $this->assertNotEmpty($entry['dimensions'], "{$id}.dimensions must list key dimensions");

            foreach (['ttl', 'bound', 'admission', 'eviction'] as $field) {
                $this->assertIsString($entry[$field], "{$id}.{$field} must be a string");
                $this->assertNotSame('', trim($entry[$field]), "{$id}.{$field} must not be empty");
            }
        }
    }

    public function test_metric_policy_entries_have_cardinality_fields(): void
    {
        $required = [
            'owner',
            'surface',
            'dimensions',
            'cardinality',
            'selection',
            'suppression',
        ];

        foreach ($this->policyMetrics() as $metric => $entry) {
            $this->assertMatchesRegularExpression('/^dw_[a-z0-9_]+$/', $metric);

            foreach ($required as $field) {
                $this->assertArrayHasKey($field, $entry, "{$metric} is missing {$field}");
            }

            $this->assertIsString($entry['owner'], "{$metric}.owner must be a class string");
            $this->assertIsString($entry['surface'], "{$metric}.surface must be a string");
            $this->assertIsArray($entry['dimensions'], "{$metric}.dimensions must be an array");

            foreach (['cardinality', 'selection', 'suppression'] as $field) {
                $this->assertIsString($entry[$field], "{$metric}.{$field} must be a string");
                $this->assertNotSame('', trim($entry[$field]), "{$metric}.{$field} must not be empty");
            }
        }
    }

    public function test_bounded_growth_document_references_each_declared_surface(): void
    {
        $document = file_get_contents(self::$repoRoot.'/docs/bounded-growth.md');
        $this->assertNotFalse($document, 'docs/bounded-growth.md must be readable');

        foreach ($this->policyCacheKeys() as $id => $entry) {
            $this->assertStringContainsString($id, $document, "docs/bounded-growth.md must mention {$id}");
            $this->assertStringContainsString($entry['prefix'], $document, "docs/bounded-growth.md must mention {$entry['prefix']}");
        }

        foreach (array_keys($this->policyMetrics()) as $metric) {
            $this->assertStringContainsString($metric, $document, "docs/bounded-growth.md must mention {$metric}");
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function policyCacheKeys(): array
    {
        $cacheKeys = self::$policy['cache_keys'] ?? null;

        $this->assertIsArray($cacheKeys, 'config/dw-bounded-growth.php must define cache_keys.');

        return $cacheKeys;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function policyMetrics(): array
    {
        $metrics = self::$policy['metrics'] ?? null;

        $this->assertIsArray($metrics, 'config/dw-bounded-growth.php must define metrics.');

        return $metrics;
    }

    /**
     * @return list<string>
     */
    private function declaredCachePrefixes(): array
    {
        $prefixes = array_map(
            static fn (array $entry): string => (string) ($entry['prefix'] ?? ''),
            array_values($this->policyCacheKeys()),
        );

        $prefixes = array_values(array_filter($prefixes, static fn (string $prefix): bool => $prefix !== ''));
        sort($prefixes);

        return $prefixes;
    }

    /**
     * @return list<string>
     */
    private function serverCachePrefixesInAppSource(): array
    {
        $prefixes = [];

        foreach ($this->phpFiles(self::$repoRoot.'/app') as $file) {
            $source = file_get_contents($file);
            $this->assertNotFalse($source, "{$file} must be readable");

            preg_match_all('/[\'"]((?:server:)[^\'"]+)/', $source, $matches);

            foreach ($matches[1] ?? [] as $literal) {
                $prefix = $this->normalizeServerCacheLiteral($literal);

                if ($prefix !== null) {
                    $prefixes[$prefix] = $prefix;
                }
            }
        }

        $prefixes = array_values($prefixes);
        sort($prefixes);

        return $prefixes;
    }

    private function normalizeServerCacheLiteral(string $literal): ?string
    {
        $literal = preg_replace('/%[a-zA-Z].*$/', '', $literal) ?? $literal;
        $lastColon = strrpos($literal, ':');

        if ($lastColon === false) {
            return null;
        }

        $prefix = substr($literal, 0, $lastColon + 1);

        return str_starts_with($prefix, 'server:') ? $prefix : null;
    }

    /**
     * @param  list<string>  $candidates
     */
    private function sourcePrefixHasPolicy(string $sourcePrefix, array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if (str_starts_with($sourcePrefix, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $sourcePrefixes
     */
    private function policyPrefixAppearsInSource(string $policyPrefix, array $sourcePrefixes): bool
    {
        foreach ($sourcePrefixes as $sourcePrefix) {
            if (str_starts_with($sourcePrefix, $policyPrefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function metricNamesInBoundedGrowthSource(): array
    {
        $metrics = [];

        foreach ($this->metricSourceFiles() as $file) {
            $source = file_get_contents($file);
            $this->assertNotFalse($source, "{$file} must be readable");

            preg_match_all('/\bdw_[a-z0-9_]+\b/', $source, $matches);

            foreach ($matches[0] ?? [] as $metric) {
                $metrics[$metric] = $metric;
            }
        }

        $metrics = array_values($metrics);
        sort($metrics);

        return $metrics;
    }

    /**
     * @return list<string>
     */
    private function metricSourceFiles(): array
    {
        return [
            ...$this->filesWithExtensions(self::$repoRoot.'/app', ['php']),
            ...$this->filesWithExtensions(self::$repoRoot.'/scripts/perf', ['py', 'sh']),
        ];
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $directory): array
    {
        return $this->filesWithExtensions($directory, ['php']);
    }

    /**
     * @param  list<string>  $extensions
     * @return list<string>
     */
    private function filesWithExtensions(string $directory, array $extensions): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), $extensions, true)) {
                continue;
            }

            $files[] = $file->getPathname();
        }

        sort($files);

        return $files;
    }
}
