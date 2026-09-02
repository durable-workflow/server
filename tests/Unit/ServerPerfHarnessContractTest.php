<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class ServerPerfHarnessContractTest extends TestCase
{
    public function test_contract_qualification_installs_the_verified_workflow_source(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/server-perf.yml');
        $this->assertNotFalse($workflow, '.github/workflows/server-perf.yml must be readable');

        $parsed = Yaml::parse($workflow);
        $this->assertIsArray($parsed);
        $steps = [];
        foreach ($parsed['jobs']['contract']['steps'] ?? [] as $step) {
            if (isset($step['name'])) {
                $steps[$step['name']] = $step;
            }
        }

        $checkout = $steps['Checkout workflow package'] ?? null;
        $this->assertIsArray($checkout);
        $this->assertSame('bash', $checkout['shell'] ?? null);
        $this->assertSame(
            [
                'WORKFLOW_PACKAGE_SOURCE' => '${{ steps.workflow.outputs.source }}',
                'WORKFLOW_PACKAGE_COMMIT' => '${{ steps.workflow.outputs.commit }}',
            ],
            $checkout['env'] ?? null,
        );
        $this->assertSame('scripts/ci/checkout-workflow-package-source.sh', $checkout['run'] ?? null);

        $sourceCheckout = file_get_contents(dirname(__DIR__, 2).'/scripts/ci/checkout-workflow-package-source.sh');
        $this->assertNotFalse($sourceCheckout, 'Workflow source checkout helper must be readable');
        foreach ([
            "canonical_source='https://github.com/durable-workflow/workflow.git'",
            '[[ "$WORKFLOW_PACKAGE_SOURCE" != "$canonical_source" ]]',
            '[[ ! "$WORKFLOW_PACKAGE_COMMIT" =~ ^[0-9a-f]{40}$ ]]',
            '[[ -e workflow-package ]]',
            'GIT_CONFIG_GLOBAL=/dev/null',
            'GIT_CONFIG_NOSYSTEM=1',
            'GIT_TERMINAL_PROMPT=0',
            'GIT_ASKPASS=/bin/false',
            'SSH_ASKPASS=/bin/false',
            'git -C workflow-package fetch --no-tags --depth=1 origin "$WORKFLOW_PACKAGE_COMMIT"',
            'git -C workflow-package checkout --quiet --detach FETCH_HEAD',
        ] as $needle) {
            $this->assertStringContainsString($needle, $sourceCheckout);
        }
        $this->assertStringNotContainsString('github.repository_owner', $workflow);

        $verification = $steps['Verify workflow package source provenance'] ?? null;
        $this->assertIsArray($verification);
        $this->assertSame('bash', $verification['shell'] ?? null);
        $this->assertSame(
            [
                'WORKFLOW_PACKAGE_SOURCE' => '${{ steps.workflow.outputs.source }}',
                'WORKFLOW_PACKAGE_REF' => '${{ steps.workflow.outputs.ref }}',
                'WORKFLOW_PACKAGE_COMMIT' => '${{ steps.workflow.outputs.commit }}',
            ],
            $verification['env'] ?? null,
        );
        $verificationRun = $verification['run'] ?? null;
        $this->assertIsString($verificationRun);
        $this->assertSame(1, substr_count($verificationRun, 'git '));
        foreach ([
            'git -C workflow-package rev-parse HEAD',
            'if [[ "$resolved_commit" != "$WORKFLOW_PACKAGE_COMMIT" ]]',
            '> workflow-package/.package-provenance',
            'rm -rf workflow-package/.git',
        ] as $needle) {
            $this->assertStringContainsString($needle, $verificationRun);
        }

        $checkoutOffset = strpos($workflow, 'scripts/ci/checkout-workflow-package-source.sh');
        $provenanceOffset = strpos($workflow, '> workflow-package/.package-provenance');
        $removeGitOffset = strpos($workflow, 'rm -rf workflow-package/.git');
        $prepareOffset = strpos($workflow, 'php scripts/ci/prepare-release-workflow-composer-metadata.php');
        $updateOffset = strpos($workflow, 'composer update durable-workflow/workflow');
        $installOffset = strpos($workflow, 'composer install --no-interaction');

        $this->assertIsInt($checkoutOffset);
        $this->assertIsInt($provenanceOffset);
        $this->assertIsInt($removeGitOffset);
        $this->assertIsInt($prepareOffset);
        $this->assertIsInt($updateOffset);
        $this->assertIsInt($installOffset);
        $this->assertLessThan($provenanceOffset, $checkoutOffset);
        $this->assertLessThan($removeGitOffset, $provenanceOffset);
        $this->assertLessThan($prepareOffset, $removeGitOffset);
        $this->assertLessThan($updateOffset, $prepareOffset);
        $this->assertLessThan($installOffset, $updateOffset);
    }

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
            'workflow_runs_target',
            'final_workflow_runs',
            'final_ready_tasks',
            'request_availability',
            'worker_poll',
            'backpressured',
            'long_poll_capacity_exhausted',
            'cluster_info',
            'max_health_latency_seconds',
            'max_control_plane_latency_seconds',
            'artifact_versions',
            'workflow_start_loop',
            'workflow_list_loop',
            'health_probe_loop',
            'cluster_info_probe_loop',
            'workflow growth target incomplete',
            'availability fell below 1.0',
            'latency exceeded',
            'resource sampling failed',
            'unhealthy_field_counts',
            'field failures:',
            'docker_stats_ok',
            'server_container_healthy',
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
            'DW_PERF_RUNNER_ENVIRONMENT',
            'DW_PERF_REDIS_CACHE_DB',
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
            'GitHub Actions workflow is not Server Perf Soak',
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
            'emit_progress',
            'flush=True',
            'waiting for health at {base_url}',
            'sample {periodic_sample_count}/{expected_periodic_samples}',
            'load window complete; waiting for worker loops to finish',
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
        $this->assertStringContainsString('"runtime": "python"', $source);
    }

    public function test_short_perf_smoke_exercises_health_during_thousand_run_growth(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/server-perf.yml');
        $this->assertNotFalse($workflow, '.github/workflows/server-perf.yml must be readable');

        foreach ([
            'DW_PERF_WORKFLOW_RUNS: "1000"',
            'DW_PERF_START_CONCURRENCY: "8"',
            'DW_PERF_MIN_WORKFLOW_COMPLETION_RATIO: "0.98"',
            'DW_PERF_HEALTH_INTERVAL_SECONDS: "0.5"',
            'DW_PERF_MAX_HEALTH_LATENCY_SECONDS: "3"',
            'DW_PERF_CONTROL_PLANE_INTERVAL_SECONDS: "5"',
            'DW_PERF_MAX_CONTROL_PLANE_LATENCY_SECONDS: "5"',
            'DW_PERF_WORKFLOW_VERSION: ${{ steps.workflow.outputs.ref }}',
        ] as $needle) {
            $this->assertStringContainsString(
                $needle,
                $workflow,
                "Short perf smoke must retain the health-growth setting {$needle}.",
            );
        }

        $this->assertStringContainsString(
            'python3 -m unittest tests/Unit/Support/server_soak_test.py',
            $workflow,
            'The required perf contract check must execute the focused workflow-growth result gate tests.',
        );
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

    public function test_redis_sampling_batches_server_cache_inventory_in_one_container_exec(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/scripts/perf/server_soak.py');
        $this->assertNotFalse($source, 'scripts/perf/server_soak.py must be readable');

        $this->assertStringContainsString('def redis_sampling_script(cache_database: int)', $source);
        $this->assertStringContainsString('redis-cli -n \"$cache_database\" --scan --pattern \'*server:*\'', $source);
        $this->assertStringContainsString('fnmatch.fnmatchcase(key, pattern)', $source);
        $this->assertStringContainsString(
            'compose_command(project, "exec", "-T", "redis", "sh", "-lc", redis_sampling_script(cache_database))',
            $source,
            'Redis sampling should use one container exec and one server-key scan, then classify per-policy counts locally.',
        );
        $this->assertStringNotContainsString('for policy_id, pattern in SERVER_CACHE_KEY_PATTERNS.items():'.PHP_EOL.'        count, ok = redis_scan_count', $source);
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

    public function test_short_perf_load_has_bounded_timeout_diagnostics(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/scripts/perf/run-server-soak.sh');
        $this->assertNotFalse($source, 'scripts/perf/run-server-soak.sh must be readable');

        foreach ([
            'DW_PERF_LOAD_TIMEOUT_SECONDS',
            'LOAD_TIMEOUT_SECONDS=$((DURATION_SECONDS + DRAIN_SECONDS + 300))',
            'timeout --kill-after=30s "${LOAD_TIMEOUT_SECONDS}s"',
            'Perf load timed out after ${LOAD_TIMEOUT_SECONDS}s; writing timeout diagnostics.',
            'load-timeout.json',
            'docker compose -p "$PROJECT"',
            'docker logs --tail=120 "${PROJECT}-server-1"',
            'docker logs --tail=120 "${PROJECT}-worker-1"',
        ] as $needle) {
            $this->assertStringContainsString(
                $needle,
                $source,
                "Perf smoke wrapper must retain bounded-timeout diagnostic {$needle}.",
            );
        }
    }

    public function test_perf_smoke_records_environment_setup_failures_before_load_starts(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/scripts/perf/run-server-soak.sh');
        $this->assertNotFalse($source, 'scripts/perf/run-server-soak.sh must be readable');

        foreach ([
            'write_environment_setup_failure()',
            'environment-setup-failure.json',
            '"phase": "environment_setup"',
            'Wrote perf environment setup failure artifact',
            'Perf environment setup failed before product smoke execution',
            'docker compose failed before server_soak.py started',
            'server port discovery failed before server_soak.py started',
        ] as $needle) {
            $this->assertStringContainsString(
                $needle,
                $source,
                "Perf smoke wrapper must classify pre-load setup failures with {$needle}.",
            );
        }

        $composeOffset = strpos($source, 'docker compose -p "$PROJECT" -f "$ROOT_DIR/docker-compose.yml" -f "$OVERRIDE_FILE" up -d --build --wait');
        $loadOffset = strpos($source, 'Running perf load against ${BASE_URL}');
        $this->assertIsInt($composeOffset);
        $this->assertIsInt($loadOffset);
        $this->assertLessThan($loadOffset, $composeOffset);
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
            '/name:\s+Ephemeral Vultr polling cache soak.*?DW_PERF_RUNNER_ENVIRONMENT:\s+"self-hosted"/s',
            $soakWorkflow,
            'Disposable-host long soaks must record self-hosted execution provenance.',
        );
    }

    public function test_server_perf_jobs_keep_authoritative_execution_split(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/server-perf.yml');
        $this->assertNotFalse($workflow, '.github/workflows/server-perf.yml must be readable');
        $soakWorkflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/server-perf-soak.yml');
        $this->assertNotFalse($soakWorkflow, '.github/workflows/server-perf-soak.yml must be readable');

        $this->assertMatchesRegularExpression(
            "/contract:\\s+name:\\s+Bounded-growth contract\\s+runs-on:\\s+ubuntu-latest\\s+if:\\s+github\\.event_name == 'pull_request' \\|\\| github\\.event_name == 'push' \\|\\| github\\.event_name == 'workflow_dispatch'/s",
            $workflow,
            'Focused contract checks should run for every supported event, with runs-on before if for runner compatibility.',
        );

        $this->assertMatchesRegularExpression(
            "/smoke:\\s+name:\\s+Polling cache bounded-growth smoke\\s+runs-on:\\s+ubuntu-latest\\s+if:\\s+\\$\\{\\{ github\\.server_url == 'https:\\/\\/github\\.com' \\}\\}/s",
            $workflow,
            'The topology-backed smoke must only run on the authoritative GitHub service.',
        );

        $this->assertStringNotContainsString(
            'needs: contract',
            $workflow,
            'Compatible Actions servers can leave dependent smoke jobs pending after contract success, so the smoke must be scheduled directly.',
        );
        $this->assertStringContainsString('workflow_dispatch:', $workflow);
        foreach ([
            'qualification:',
            'name: Performance source qualification',
            'needs: [contract, smoke]',
            'if: ${{ always() }}',
            'test "$CONTRACT_RESULT" = success',
            '[[ "$ACTIONS_SERVER_URL" == "https://github.com" ]]',
            'test "$SMOKE_RESULT" = success',
            'test "$SMOKE_RESULT" = skipped',
        ] as $needle) {
            $this->assertStringContainsString($needle, $workflow);
        }
        $this->assertStringContainsString(
            'group: server-perf-${{ github.event_name }}-${{ github.ref }}-${{ github.sha }}',
            $workflow,
            'Perf workflow concurrency must be scoped to the commit so stale checks from an older PR head cannot block the current head.',
        );

        $this->assertStringNotContainsString(
            'GitHub-hosted polling cache soak',
            $workflow,
            'Pull-request perf workflow must not create the long-soak status.',
        );
        $this->assertStringContainsString('runs-on: ubuntu-latest', $soakWorkflow);
        $this->assertStringContainsString('run: scripts/perf/run-vultr-soak.sh', $soakWorkflow);

        $parsedSoakWorkflow = Yaml::parse($soakWorkflow);
        $this->assertIsArray($parsedSoakWorkflow);
        $soakCondition = $parsedSoakWorkflow['jobs']['soak']['if'] ?? null;
        $this->assertIsString($soakCondition);

        // Scheduled runs remain explicitly enabled by a repository variable,
        // while manual runs must originate from the protected default branch.
        // Normalize YAML whitespace so this contract checks the predicate
        // rather than coupling the test to its multiline source rendering.
        $this->assertSame(
            "(github.event_name == 'workflow_dispatch' && github.ref == 'refs/heads/main') "
            ."|| (github.event_name == 'schedule' && vars.DW_PERF_SOAK_ENABLED == 'true')",
            preg_replace('/\s+/', ' ', trim($soakCondition)),
            'Long soaks should only run for protected-main workflow_dispatch, or for explicitly enabled schedules.',
        );
    }

    public function test_ephemeral_perf_soak_requires_trusted_evidence(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/server-perf.yml');
        $this->assertNotFalse($workflow, '.github/workflows/server-perf.yml must be readable');
        $soakWorkflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/server-perf-soak.yml');
        $this->assertNotFalse($soakWorkflow, '.github/workflows/server-perf-soak.yml must be readable');

        $this->assertStringContainsString(
            'DW_PERF_REQUIRE_TRUSTED_EVIDENCE: "true"',
            $soakWorkflow,
            'Disposable-host long soaks must fail rather than publish ineligible evidence.',
        );
        $this->assertStringContainsString(
            'DW_PERF_RUNNER_ENVIRONMENT: "self-hosted"',
            $soakWorkflow,
            'Disposable-host long soaks must identify the actual execution environment.',
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

        $this->assertSame(2, substr_count($workflows, 'uses: actions/upload-artifact@043fb46d1a93c77aae656e7c1c64a875d1fc6a0a'));
        $this->assertSame(0, substr_count($workflows, 'uses: actions/upload-artifact@v4'));
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
        $this->assertStringContainsString('SERVER_BIND="${DW_PERF_SERVER_BIND:-}"', $source);
        $this->assertStringContainsString('SERVER_PORT_MAPPING="8080"', $source);
        $this->assertStringContainsString('SERVER_PORT_MAPPING="${SERVER_BIND}:${SERVER_PORT}:8080"', $source);
        $this->assertStringContainsString('SERVER_PORT_MAPPING="${SERVER_PORT}:8080"', $source);
        $this->assertStringContainsString('- "${SERVER_PORT_MAPPING}"', $source);
        $this->assertStringContainsString('port server 8080', $source);
        $this->assertStringContainsString('SERVER_PORT="$PUBLISHED_SERVER_PORT"', $source);
        $this->assertStringContainsString('METRICS_PORT="${DW_PERF_METRICS_PORT:-$(choose_free_port)}"', $source);
    }

    public function test_server_perf_workflow_runs_long_soak_on_disposable_vultr_host(): void
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
            "github.event_name == 'workflow_dispatch'",
            "github.event_name == 'schedule' && vars.DW_PERF_SOAK_ENABLED == 'true'",
            'runs-on: ubuntu-latest',
            'environment: perf-soak',
            'VULTR_API_KEY: ${{ secrets.VULTR_PERF_API_KEY }}',
            'VULTR_PERF_PLAN: "vhp-2c-4gb-amd"',
            'DW_PERF_REQUIRE_TRUSTED_EVIDENCE: "true"',
            'DW_PERF_REDIS_CACHE_DB: "1"',
            'DW_PERF_RUNNER_ENVIRONMENT: "self-hosted"',
            'run: scripts/perf/run-vultr-soak.sh',
        ] as $needle) {
            $this->assertStringContainsString(
                $needle,
                $workflow,
                "Server Perf soak workflow must retain public long-soak support for {$needle}.",
            );
        }

    }

    public function test_vultr_soak_controller_keeps_credentials_off_the_host_and_always_deletes_it(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/scripts/perf/run-vultr-soak.sh');
        $this->assertNotFalse($source, 'scripts/perf/run-vultr-soak.sh must be readable');

        $this->assertStringContainsString('trap cleanup EXIT INT TERM', $source);
        $this->assertStringContainsString('api DELETE "/instances/$INSTANCE_ID"', $source);
        $this->assertStringContainsString('VULTR_API_KEY is required', $source);
        $this->assertStringContainsString('ufw allow from $controller_ip to any port 22 proto tcp', $source);
        $this->assertStringContainsString("printf 'export DW_PERF_SERVER_BIND=%q", $source);
        $this->assertStringContainsString("printf 'export DW_PERF_SERVER_PORT=%q", $source);
        $this->assertStringContainsString('DW_PERF_REDIS_CACHE_DB \\', $source);
        $this->assertStringContainsString('DW_PERF_RUNNER_ENVIRONMENT', $source);
        $this->assertStringContainsString('DURATION_SECONDS < 3600 || DURATION_SECONDS > 14400', $source);
        $this->assertStringNotContainsString('VULTR_API_KEY \\', $source);
    }

    public function test_ci_perf_trigger_paths_cover_bounded_growth_runtime_surfaces(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $workflow = file_get_contents($repoRoot.'/.github/workflows/server-perf.yml');
        $this->assertNotFalse($workflow, '.github/workflows/server-perf.yml must be readable');
        $parsed = Yaml::parse($workflow);
        $this->assertIsArray($parsed);
        $steps = [];
        foreach ($parsed['jobs']['contract']['steps'] ?? [] as $step) {
            if (isset($step['name'])) {
                $steps[$step['name']] = $step;
            }
        }
        $qualificationStep = $steps[
            'Qualify capacity benchmark contract and bounded reference cell'
        ]['run'] ?? null;
        $this->assertIsString($qualificationStep);
        $this->assertStringContainsString(
            'python3 scripts/benchmark/capacity_suite.py validate',
            $qualificationStep,
        );
        $this->assertStringNotContainsString('verify-publication', $qualificationStep);
        $this->assertStringNotContainsString('PUBLICATION_BASE_REF', $workflow);

        $publicationWorkflowSource = file_get_contents(
            $repoRoot.'/.github/workflows/capacity-schema-publication.yml',
        );
        $this->assertNotFalse($publicationWorkflowSource);
        $publicationWorkflow = Yaml::parse($publicationWorkflowSource);
        $this->assertIsArray($publicationWorkflow);
        $this->assertNotEmpty($publicationWorkflow['on']['schedule'] ?? null);
        $this->assertArrayHasKey('workflow_dispatch', $publicationWorkflow['on'] ?? []);
        $this->assertArrayNotHasKey('pull_request', $publicationWorkflow['on'] ?? []);
        $this->assertArrayNotHasKey('push', $publicationWorkflow['on'] ?? []);
        $publicationJob = $publicationWorkflow['jobs']['audit'] ?? null;
        $this->assertIsArray($publicationJob);
        $this->assertSame(
            'Public capacity schema publication/runtime audit',
            $publicationJob['name'] ?? null,
        );
        $this->assertArrayNotHasKey(
            'if',
            $publicationJob,
            'The provider-neutral HTTPS audit must fail closed on scheduled GitHub and Forgejo runs.',
        );
        $publicationSteps = [];
        foreach ($publicationJob['steps'] ?? [] as $step) {
            if (isset($step['name'])) {
                $publicationSteps[$step['name']] = $step;
            }
        }
        $publicationAudit = $publicationSteps['Audit immutable public capacity schemas'] ?? null;
        $this->assertIsArray($publicationAudit);
        $this->assertSame(
            'python3 scripts/benchmark/capacity_schema_publication.py',
            $publicationAudit['run'] ?? null,
        );
        $this->assertArrayNotHasKey('continue-on-error', $publicationAudit);

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

    public function test_namespace_isolation_experiment_retains_its_adversarial_contract(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $driver = file_get_contents($repoRoot.'/scripts/perf/namespace_isolation.py');
        $runner = file_get_contents($repoRoot.'/scripts/perf/run-namespace-isolation.sh');

        $this->assertNotFalse($driver);
        $this->assertNotFalse($runner);

        foreach ([
            'workflow_start_history',
            'workflow_start_timer',
            'workflow_start_child',
            'workflow_start_external',
            'standalone_activity',
            'create_schedule',
            'execute_nexus_operation',
            'describe_task_queue',
            '"queue_samples": queue_samples',
            '"operator_metrics": metric_snapshots',
            '"/system/metrics"',
            'await disruption(args.compose_project, "redis"',
            'await disruption(args.compose_project, "server"',
            'no deterministic noisy-namespace throttling was observed',
            'control namespace completed fewer than five workflows',
            'a namespace failed the post-pressure recovery probe',
            'queue-depth diagnostics were not captured for both namespaces',
            'no noisy-namespace budget rejection was visible in operator metrics',
        ] as $needle) {
            $this->assertStringContainsString($needle, $driver);
        }

        foreach ([
            'DW_ISOLATION_SERVER_VERSION',
            'durableworkflow/server:$SERVER_VERSION',
            'durable-workflow==$SDK_VERSION',
            'DW_NAMESPACE_ADMISSION_OVERRIDES',
            'DW_NAMESPACE_DURABLE_OVERRIDES',
            'DW_WORKER_LONG_POLL_MAX_CONCURRENT',
            'DW_EXTERNAL_PAYLOAD_MAX_BYTES_PER_NAMESPACE',
            'DW_SERVICE_BOUNDARY_NAMESPACE_RATE_LIMIT_PER_MINUTE',
            'docker compose -p "$PROJECT" -f "$COMPOSE_FILE" down -v --remove-orphans',
        ] as $needle) {
            $this->assertStringContainsString($needle, $runner);
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
