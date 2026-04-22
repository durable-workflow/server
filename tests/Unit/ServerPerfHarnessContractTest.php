<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ServerPerfHarnessContractTest extends TestCase
{
    public function test_soak_summary_records_trusted_evidence_fields(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/scripts/perf/server_soak.py');
        $this->assertNotFalse($source, 'scripts/perf/server_soak.py must be readable');

        foreach ([
            'sample_count',
            'periodic_sample_count',
            'expected_periodic_samples',
            'observed_sample_coverage',
            'minimum_trusted_samples',
            'observed {periodic_sample_count} periodic samples',
            'next_sample += sample_interval',
            'max_server_cache_keys',
            'final_server_cache_keys',
            'max_server_cache_keys_by_policy',
            'final_server_cache_keys_by_policy',
            'dw_perf_redis_server_keys_by_policy',
            'DW_PERF_MAX_SERVER_CACHE_KEYS_BY_POLICY',
            'DW_PERF_MAX_FINAL_SERVER_CACHE_KEYS_BY_POLICY',
            'parse_policy_limit_map',
            'unknown cache policy',
            'must be a non-negative integer',
            'isinstance(limit, bool)',
            'SERVER_CACHE_KEY_PATTERNS',
            'bounded_growth_policy_sha256',
            'GITHUB_RUN_ID',
            'RUNNER_NAME',
        ] as $needle) {
            $this->assertStringContainsString($needle, $source, "Perf soak summary must retain {$needle}");
        }
    }

    public function test_remote_write_target_labels_exclude_per_run_dimensions(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/scripts/perf/run-server-soak.sh');
        $this->assertNotFalse($source, 'scripts/perf/run-server-soak.sh must be readable');

        $this->assertStringContainsString('repository: "${GITHUB_REPOSITORY:-local}"', $source);
        $this->assertStringContainsString('workflow: "${GITHUB_WORKFLOW:-local}"', $source);
        $this->assertStringNotContainsString('run_id: "${GITHUB_RUN_ID:-local}"', $source);
        $this->assertStringNotContainsString('runner: "${RUNNER_NAME:-local}"', $source);
    }

    public function test_soak_cache_key_patterns_match_bounded_growth_policy(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $source = file_get_contents($repoRoot.'/scripts/perf/server_soak.py');
        $this->assertNotFalse($source, 'scripts/perf/server_soak.py must be readable');

        $policy = require $repoRoot.'/config/dw-bounded-growth.php';
        $cacheKeys = $policy['cache_keys'] ?? [];
        $this->assertNotEmpty($cacheKeys, 'config/dw-bounded-growth.php must declare cache_keys.');

        $expected = [];

        foreach ($cacheKeys as $policyId => $entry) {
            $expected[$policyId] = '*'.((string) ($entry['prefix'] ?? '')).'*';
        }

        $this->assertSame(
            $expected,
            $this->serverCacheKeyPatterns($source),
            'Perf soak cache inventory must exactly mirror config/dw-bounded-growth.php cache_keys.',
        );
    }

    public function test_ci_perf_jobs_enforce_per_policy_cache_thresholds(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/server-perf.yml');
        $this->assertNotFalse($workflow, '.github/workflows/server-perf.yml must be readable');

        $policy = require dirname(__DIR__, 2).'/config/dw-bounded-growth.php';
        $policyIds = array_keys($policy['cache_keys'] ?? []);

        foreach ([
            'DW_PERF_MAX_SERVER_CACHE_KEYS_BY_POLICY',
            'DW_PERF_MAX_FINAL_SERVER_CACHE_KEYS_BY_POLICY',
        ] as $envName) {
            $this->assertStringContainsString($envName, $workflow, "Server Perf workflow must set {$envName}.");

            foreach ($policyIds as $policyId) {
                $this->assertStringContainsString(
                    '"'.$policyId.'":',
                    $workflow,
                    "{$envName} must include a threshold for {$policyId}.",
                );
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function serverCacheKeyPatterns(string $source): array
    {
        $this->assertMatchesRegularExpression(
            '/SERVER_CACHE_KEY_PATTERNS\s*=\s*\{(?P<body>.*?)\n\}/s',
            $source,
            'scripts/perf/server_soak.py must declare SERVER_CACHE_KEY_PATTERNS as a literal map.',
        );

        preg_match('/SERVER_CACHE_KEY_PATTERNS\s*=\s*\{(?P<body>.*?)\n\}/s', $source, $mapMatch);
        $body = (string) ($mapMatch['body'] ?? '');
        preg_match_all('/^\s+"(?P<id>[a-z0-9_]+)":\s+"(?P<pattern>\*server:[^"]+\*)",\s*$/m', $body, $matches, PREG_SET_ORDER);

        $patterns = [];

        foreach ($matches as $match) {
            $patterns[$match['id']] = $match['pattern'];
        }

        return $patterns;
    }
}
