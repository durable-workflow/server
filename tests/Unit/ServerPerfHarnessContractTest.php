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
            'expected_periodic_samples',
            'minimum_trusted_samples',
            'sample coverage below trusted minimum',
            'next_sample += sample_interval',
            'max_server_cache_keys',
            'final_server_cache_keys',
            'max_server_cache_keys_by_policy',
            'final_server_cache_keys_by_policy',
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

        foreach ($cacheKeys as $policyId => $entry) {
            $prefix = (string) ($entry['prefix'] ?? '');

            $this->assertStringContainsString(
                sprintf('"%s":', $policyId),
                $source,
                "Perf soak cache inventory must include {$policyId}.",
            );
            $this->assertStringContainsString(
                sprintf('"*%s*"', $prefix),
                $source,
                "Perf soak cache inventory must scan {$prefix}.",
            );
        }
    }
}
