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
            'sampling_health',
            'resource sampling failed',
            'unhealthy_field_counts',
            'field failures:',
            'docker_stats_ok',
            'redis_sample_ok',
            'mysql_sample_ok',
            'workflow_worker_registrations',
            'dw_perf_redis_server_keys_by_policy',
            'DW_PERF_MAX_SERVER_CACHE_KEYS_BY_POLICY',
            'DW_PERF_MAX_FINAL_SERVER_CACHE_KEYS_BY_POLICY',
            'parse_policy_limit_map',
            'unknown cache policy',
            'missing cache policy thresholds',
            'must be a non-negative integer',
            'isinstance(limit, bool)',
            'SERVER_CACHE_KEY_PATTERNS',
            'bounded_growth_policy_sha256',
            'tracked_working_tree_changes',
            'tracked_working_tree_clean',
            'tracked_working_tree_change_count',
            'GITHUB_RUN_ID',
            'GITHUB_EVENT_NAME',
            'event_name',
            'RUNNER_NAME',
            'RUNNER_ENVIRONMENT',
            'evidence_trust_profile',
            'github_actions_provenance_present',
            'trusted_long_soak_v1',
            'minimum_duration_seconds',
            'requires_self_hosted_runner',
            'requires_github_actions_provenance',
            'requires_server_main_ref',
            'requires_server_perf_workflow',
            'requires_trusted_event',
            'requires_compose_resource_sampling',
            'requires_clean_tracked_working_tree',
            'runner environment is unknown',
            'GitHub Actions provenance is incomplete',
            'GitHub Actions repository is not durable-workflow/server',
            'GitHub Actions ref is not refs/heads/main',
            'GitHub Actions workflow is not Server Perf',
            'GitHub Actions event is not schedule or workflow_dispatch',
            'checked_out_sha',
            'github_sha_matches_checked_out',
            'requires_github_sha_match',
            'GitHub Actions SHA does not match checked-out source',
            'tracked working tree has uncommitted changes',
            'requires_per_policy_cache_thresholds',
            'per-policy max cache thresholds missing for:',
            'per-policy final cache thresholds missing for:',
            'per_policy_threshold_reasons',
            'max_server_cache_keys_by_policy=args.max_server_cache_keys_by_policy',
            'max_final_server_cache_keys_by_policy=args.max_final_server_cache_keys_by_policy',
            'DW_PERF_REQUIRE_TRUSTED_EVIDENCE',
            '--require-trusted-evidence',
            'require_trusted_evidence',
            'trusted evidence profile is ineligible',
            'duration below trusted long-soak minimum',
            'bounded-growth assertions failed',
        ] as $needle) {
            $this->assertStringContainsString($needle, $source, "Perf soak summary must retain {$needle}");
        }
    }

    public function test_perf_workers_register_supported_workflow_types_so_polls_reach_polling_cache(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/scripts/perf/server_soak.py');
        $this->assertNotFalse($source, 'scripts/perf/server_soak.py must be readable');

        $this->assertMatchesRegularExpression(
            '/def register_workers\b[\s\S]*?"supported_workflow_types":\s*\[PERF_WORKFLOW_TYPE\]/',
            $source,
            'Perf harness must register workers with at least one supported workflow type. '
            .'Without it the server poll endpoint short-circuits at no_workflow_capability '
            .'and the polling cache surface is never exercised, leaving the bounded-growth '
            .'smoke without any observation of the path it asserts on.'
        );

        $this->assertStringContainsString('PERF_WORKFLOW_TYPE = ', $source);
    }

    public function test_polling_assertions_are_decoupled_from_redis_dbsize(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/scripts/perf/server_soak.py');
        $this->assertNotFalse($source, 'scripts/perf/server_soak.py must be readable');

        $this->assertStringContainsString(
            'max_polling_keys = max_pattern_polling_keys',
            $source,
            'Polling-cache bounded-growth threshold must be measured against the polling '
            .'pattern observation alone. Conflating it with Redis DBSIZE drags in unrelated '
            .'queue/session/lock keys and trips the gate on PRs that do not touch the polling '
            .'cache.'
        );
        $this->assertStringContainsString(
            'final_polling_keys = final_pattern_polling_keys',
            $source,
            'Final-drain polling threshold must also use the polling pattern observation '
            .'alone, for the same reason.'
        );
        $this->assertStringNotContainsString(
            'max_polling_keys = max(max_pattern_polling_keys, max_redis_db_keys)',
            $source,
        );
        $this->assertStringNotContainsString(
            'final_polling_keys = max(final_pattern_polling_keys, final_redis_db_keys)',
            $source,
        );
    }

    public function test_polling_assertions_skip_when_no_polling_activity_was_observed(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/scripts/perf/server_soak.py');
        $this->assertNotFalse($source, 'scripts/perf/server_soak.py must be readable');

        $this->assertStringContainsString('polling_activity_observed = max_pattern_polling_keys > 0', $source);
        $this->assertStringContainsString('"skipped_no_activity"', $source);
        $this->assertStringContainsString('"polling_observation_status"', $source);
        $this->assertMatchesRegularExpression(
            '/if polling_activity_observed:\s+if max_polling_keys > args\.max_polling_keys:/s',
            $source,
            'Polling-cache bounded-growth assertions must be guarded by '
            .'polling_activity_observed so the smoke does not assert against zero '
            .'observed activity (which would block unrelated PRs without exercising '
            .'what the gate is meant to protect).'
        );
    }

    public function test_trusted_long_soak_evidence_requires_polling_cache_activity_observed(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/scripts/perf/server_soak.py');
        $this->assertNotFalse($source, 'scripts/perf/server_soak.py must be readable');

        $this->assertStringContainsString('"requires_polling_cache_activity_observed": True', $source);
        $this->assertStringContainsString(
            'polling cache activity was not observed during the run',
            $source,
            'Trusted long-soak evidence must be ineligible if the run never exercised the '
            .'polling cache, otherwise the soak certifies a surface it never touched.'
        );
        $this->assertStringContainsString(
            'polling_activity_observed=polling_activity_observed',
            $source,
            'Trust-profile builder must receive the polling_activity_observed signal from main().'
        );
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

    public function test_per_policy_cache_threshold_parser_rejects_partial_maps(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/scripts/perf/server_soak.py');
        $this->assertNotFalse($source, 'scripts/perf/server_soak.py must be readable');

        $this->assertStringContainsString('missing_policy_ids = sorted(policy_ids - set(limits))', $source);
        $this->assertStringContainsString('is missing cache policy thresholds for:', $source);
    }

    public function test_trusted_perf_evidence_requires_per_policy_cache_thresholds(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/scripts/perf/server_soak.py');
        $this->assertNotFalse($source, 'scripts/perf/server_soak.py must be readable');

        $this->assertStringContainsString('def per_policy_threshold_reasons(', $source);
        $this->assertStringContainsString('missing_max_policy_ids = sorted(policy_ids - set(max_server_cache_keys_by_policy))', $source);
        $this->assertStringContainsString('missing_final_policy_ids = sorted(', $source);
        $this->assertStringContainsString('policy_ids - set(max_final_server_cache_keys_by_policy)', $source);
        $this->assertStringContainsString('"requires_per_policy_cache_thresholds": True', $source);
    }

    public function test_ci_perf_jobs_set_runner_environment_provenance(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/server-perf.yml');
        $this->assertNotFalse($workflow, '.github/workflows/server-perf.yml must be readable');
        $soakWorkflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/server-perf-soak.yml');
        $this->assertNotFalse($soakWorkflow, '.github/workflows/server-perf-soak.yml must be readable');

        $this->assertMatchesRegularExpression(
            '/name:\s+Polling cache bounded-growth smoke.*?RUNNER_ENVIRONMENT:\s+"github-hosted"/s',
            $workflow,
            'Short perf smokes must record github-hosted runner provenance.',
        );

        $this->assertMatchesRegularExpression(
            '/name:\s+Self-hosted polling cache soak.*?RUNNER_ENVIRONMENT:\s+"self-hosted"/s',
            $soakWorkflow,
            'Trusted long soaks must explicitly record self-hosted runner provenance.',
        );
    }

    public function test_server_perf_jobs_keep_event_split_guards(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/server-perf.yml');
        $this->assertNotFalse($workflow, '.github/workflows/server-perf.yml must be readable');
        $soakWorkflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/server-perf-soak.yml');
        $this->assertNotFalse($soakWorkflow, '.github/workflows/server-perf-soak.yml must be readable');

        $this->assertMatchesRegularExpression(
            "/contract:\\s+name:\\s+Bounded-growth contract\\s+runs-on:\\s+ubuntu-latest\\s+if:\\s+github\\.event_name == 'pull_request' \\|\\| github\\.event_name == 'push'/s",
            $workflow,
            'Contract checks should only run for pull_request/push events, with runs-on before if for runner compatibility.',
        );

        $this->assertMatchesRegularExpression(
            "/smoke:\\s+name:\\s+Polling cache bounded-growth smoke\\s+runs-on:\\s+ubuntu-latest\\s+if:\\s+github\\.event_name == 'pull_request' \\|\\| github\\.event_name == 'push'/s",
            $workflow,
            'Short perf smokes should only run for pull_request/push events.',
        );

        $this->assertStringNotContainsString(
            'needs: contract',
            $workflow,
            'Compatible Actions servers can leave dependent smoke jobs pending after contract success, so the smoke must be scheduled directly.',
        );
        $this->assertStringContainsString(
            'group: server-perf-${{ github.event_name }}-${{ github.ref }}-${{ github.sha }}',
            $workflow,
            'Perf workflow concurrency must be scoped to the commit so stale checks from an older PR head cannot block the current head.',
        );

        $this->assertStringNotContainsString(
            'Self-hosted polling cache soak',
            $workflow,
            'Pull-request perf workflow must not create the self-hosted soak status.',
        );

        // The soak job is gated behind a repository variable so it does not
        // queue forever waiting on a self-hosted runner fleet that may not be
        // registered. The schedule/workflow_dispatch event guard is preserved
        // inside the same `if:` so the gate is still a strict superset of the
        // historical event filter — when DW_PERF_SOAK_ENABLED is set, only
        // schedule and workflow_dispatch can spawn the job.
        $this->assertMatchesRegularExpression(
            "/soak:\\s+name:\\s+Self-hosted polling cache soak\\s+runs-on:\\s+\\[self-hosted, linux, x64, perf-soak, server-perf\\][^\\n]*\\n.*?if:\\s*\\|\\s*\\n\\s*vars\\.DW_PERF_SOAK_ENABLED == 'true'\\s*\\n?\\s*&&\\s*\\(github\\.event_name == 'schedule' \\|\\| github\\.event_name == 'workflow_dispatch'\\)/s",
            $soakWorkflow,
            'Trusted long soaks should only run for schedule/workflow_dispatch events AND only when the runner-fleet variable DW_PERF_SOAK_ENABLED is set.',
        );
    }

    public function test_self_hosted_perf_soak_requires_trusted_evidence_eligibility(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/server-perf.yml');
        $this->assertNotFalse($workflow, '.github/workflows/server-perf.yml must be readable');
        $soakWorkflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/server-perf-soak.yml');
        $this->assertNotFalse($soakWorkflow, '.github/workflows/server-perf-soak.yml must be readable');

        $this->assertMatchesRegularExpression(
            '/name:\s+Self-hosted polling cache soak.*?DW_PERF_REQUIRE_TRUSTED_EVIDENCE:\s+"true"/s',
            $soakWorkflow,
            'Self-hosted long soaks must fail instead of producing green ineligible trusted evidence.',
        );

        $this->assertMatchesRegularExpression(
            '/name:\s+Polling cache bounded-growth smoke(?P<block>.*)$/s',
            $workflow,
            'Server Perf workflow must keep a distinct short smoke job.',
        );
        preg_match('/name:\s+Polling cache bounded-growth smoke(?P<block>.*)$/s', $workflow, $smokeMatch);
        $this->assertStringNotContainsString(
            'DW_PERF_REQUIRE_TRUSTED_EVIDENCE: "true"',
            (string) ($smokeMatch['block'] ?? ''),
            'Short perf smokes should remain useful but ineligible artifacts.',
        );
    }

    public function test_server_perf_artifact_uploads_avoid_deprecated_actions(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/server-perf.yml');
        $this->assertNotFalse($workflow, '.github/workflows/server-perf.yml must be readable');
        $soakWorkflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/server-perf-soak.yml');
        $this->assertNotFalse($soakWorkflow, '.github/workflows/server-perf-soak.yml must be readable');
        $workflows = $workflow."\n".$soakWorkflow;

        $this->assertSame(2, substr_count($workflows, 'uses: actions/upload-artifact@v4'));
        $this->assertSame(0, substr_count($workflows, 'uses: actions/upload-artifact@v3'));
        $this->assertSame(2, substr_count($workflows, "github.server_url == 'https://github.com'"));
        $this->assertSame(0, substr_count($workflows, "github.server_url != 'https://github.com'"));
    }

    public function test_server_perf_soak_uses_current_worker_protocol_default(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/scripts/perf/server_soak.py');
        $this->assertNotFalse($source, 'scripts/perf/server_soak.py must be readable');

        $this->assertStringContainsString(
            'WORKER_PROTOCOL_VERSION = os.environ.get("DW_PERF_WORKER_PROTOCOL_VERSION", "1.2")',
            $source,
        );
        $this->assertStringContainsString(
            'headers["X-Durable-Workflow-Protocol-Version"] = WORKER_PROTOCOL_VERSION',
            $source,
        );
        $this->assertStringNotContainsString('WORKER_PROTOCOL_VERSION = "1.0"', $source);
    }

    public function test_short_perf_smoke_keeps_flake_resistant_sample_coverage_floor(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/server-perf.yml');
        $this->assertNotFalse($workflow, '.github/workflows/server-perf.yml must be readable');

        $this->assertMatchesRegularExpression(
            '/name:\s+Polling cache bounded-growth smoke(?P<block>.*)$/s',
            $workflow,
            'Server Perf workflow must keep a distinct short smoke job.',
        );
        preg_match('/name:\s+Polling cache bounded-growth smoke(?P<block>.*)$/s', $workflow, $smokeMatch);

        $this->assertStringContainsString(
            'DW_PERF_MIN_SAMPLE_COVERAGE: "0.75"',
            (string) ($smokeMatch['block'] ?? ''),
            'Short perf smokes should tolerate one slow compose-backed sample without losing coverage signal.',
        );
    }

    public function test_server_perf_base_url_probe_supports_containerized_actions_runners(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/scripts/perf/run-server-soak.sh');
        $this->assertNotFalse($source, 'scripts/perf/run-server-soak.sh must be readable');

        $this->assertStringContainsString('http://host.docker.internal:${SERVER_PORT}', $source);
        $this->assertMatchesRegularExpression(
            '/host\.docker\.internal.*?docker_host_ip.*?docker inspect/s',
            $source,
            'Perf smoke should prefer host-published ports before falling back to direct container addresses.',
        );
        $this->assertMatchesRegularExpression(
            '/server_container_url="http:\/\/\$\{server_ip\}:8080".*?curl -fsS --max-time 2 "\$server_container_url\/api\/health"/s',
            $source,
            'Perf smoke should only select a direct container address after confirming the runner can reach it.',
        );
    }

    public function test_server_perf_smoke_uses_dynamic_host_ports_by_default(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/scripts/perf/run-server-soak.sh');
        $this->assertNotFalse($source, 'scripts/perf/run-server-soak.sh must be readable');

        $this->assertStringContainsString('SERVER_PORT="${DW_PERF_SERVER_PORT:-}"', $source);
        $this->assertStringContainsString('SERVER_PORT_MAPPING="8080"', $source);
        $this->assertStringContainsString('SERVER_PORT_MAPPING="${SERVER_PORT}:8080"', $source);
        $this->assertStringContainsString('- "${SERVER_PORT_MAPPING}"', $source);
        $this->assertStringContainsString('port server 8080', $source);
        $this->assertStringContainsString('SERVER_PORT="$PUBLISHED_SERVER_PORT"', $source);
        $this->assertStringContainsString('METRICS_PORT="${DW_PERF_METRICS_PORT:-$(choose_free_port)}"', $source);
    }

    public function test_server_perf_workflow_can_produce_trusted_long_soak_evidence(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/server-perf-soak.yml');
        $this->assertNotFalse($workflow, '.github/workflows/server-perf-soak.yml must be readable');

        foreach ([
            'schedule:',
            'cron: "17 7 * * *"',
            'workflow_dispatch:',
            'duration_seconds:',
            'default: "7200"',
            'concurrency:',
            'default: "24"',
            'remote_write:',
            'type: boolean',
            "github.event_name == 'schedule' || github.event_name == 'workflow_dispatch'",
            'runs-on: [self-hosted, linux, x64, perf-soak, server-perf]',
            'DW_PERF_REQUIRE_TRUSTED_EVIDENCE: "true"',
            'RUNNER_ENVIRONMENT: "self-hosted"',
        ] as $needle) {
            $this->assertStringContainsString(
                $needle,
                $workflow,
                "Server Perf soak workflow must retain trusted long-soak trigger support for {$needle}.",
            );
        }
    }

    public function test_ci_perf_trigger_paths_cover_bounded_growth_runtime_surfaces(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $workflow = file_get_contents($repoRoot.'/.github/workflows/server-perf.yml');
        $this->assertNotFalse($workflow, '.github/workflows/server-perf.yml must be readable');

        $policy = require $repoRoot.'/config/dw-bounded-growth.php';
        $paths = [
            'app/Support/BoundedMetricPolicy.php',
            'app/Http/Controllers/Api/SystemController.php',
            'config/dw-bounded-growth.php',
            'routes/api.php',
            'scripts/perf/**',
            'tests/Feature/SystemMetricsTest.php',
            'tests/Unit/BoundedGrowthPolicyTest.php',
            'tests/Unit/BoundedMetricPolicyTest.php',
            'tests/Unit/ServerPerfHarnessContractTest.php',
        ];

        foreach ($policy['cache_keys'] ?? [] as $entry) {
            $paths[] = $this->policyOwnerPath((string) ($entry['owner'] ?? ''));
        }

        foreach ($policy['metrics'] ?? [] as $entry) {
            $paths[] = $this->policyOwnerPath((string) ($entry['owner'] ?? ''));
        }

        $paths = array_values(array_unique(array_filter($paths)));
        sort($paths);

        foreach ($paths as $path) {
            $this->assertGreaterThanOrEqual(
                2,
                substr_count($workflow, '- "'.$path.'"'),
                "Server Perf workflow must run on pull_request and push when {$path} changes.",
            );
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

    private function policyOwnerPath(string $owner): ?string
    {
        if ($owner === '') {
            return null;
        }

        if (str_starts_with($owner, 'App\\')) {
            return str_replace('\\', '/', preg_replace('/^App\\\\/', 'app/', $owner)).'.php';
        }

        if (str_starts_with($owner, 'scripts/perf/')) {
            return 'scripts/perf/**';
        }

        return $owner;
    }
}
