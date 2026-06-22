<?php

namespace Tests\Unit;

use App\Support\ActivityRuntimeContract;
use App\Support\ActivityRuntimeResultGate;
use PHPUnit\Framework\TestCase;

class ActivityConformanceRunnerContractTest extends TestCase
{
    public function test_runner_script_routes_every_required_activity_scenario(): void
    {
        $source = $this->read('scripts/conformance/activities-published-artifacts.sh');

        $this->assertStringContainsString(
            'Usage: activities-published-artifacts.sh [--result-dir DIR|--result-dir=DIR] [--keep-run-root[=1|true]]',
            $source,
        );
        $this->assertStringContainsString('activities-result.json', $source);
        $this->assertStringContainsString('activities-record.json', $source);
        $this->assertStringContainsString('activities-findings.json', $source);
        $this->assertStringContainsString('DW_ACTIVITIES_SCENARIO_MANIFEST', $source);
        $this->assertStringContainsString('DW_ACTIVITIES_ARTIFACT_INSTALL_EVIDENCE', $source);
        $this->assertStringContainsString('DW_ACTIVITIES_EVIDENCE', $source);
        $this->assertStringContainsString('DW_ACTIVITIES_EVIDENCE_PATH', $source);
        $this->assertStringContainsString('DW_ACTIVITIES_RUNNER_SOURCE', $source);
        $this->assertStringContainsString('DW_ACTIVITIES_PYTHON_BIN', $source);
        $this->assertStringContainsString('DW_ACTIVITIES_SKIP_FOCUSED_HOST_PROBE', $source);
        $this->assertStringContainsString('RUNNER_REPO_ROOT', $source);

        foreach ([
            'DW_SERVER_VERSION',
            'DW_CLI_VERSION',
            'DW_PYTHON_SDK_VERSION',
            'DW_WORKFLOW_PHP_VERSION',
            'DW_WATERLINE_VERSION',
        ] as $envName) {
            $this->assertStringContainsString($envName, $source);
        }

        foreach (ActivityRuntimeContract::manifest()['required_scenarios'] as $scenarioId) {
            $this->assertStringContainsString(
                $scenarioId,
                $source,
                "the published-artifact runner must know how to route scenario {$scenarioId}",
            );
        }

        foreach ([
            'workflow-embedded',
            'standalone',
            'not_covered',
            'runner_blocked',
            'product-gap',
            'coverage-gap',
            'runner-gap',
            'stale-artifact',
            'pipeline-churn',
            'conformance_runner_coverage_gap',
            'artifact_install_evidence missing',
            'activity host evidence missing',
            'local_product_source_checkouts_used',
            'FORBIDDEN_INSTALL_SOURCE_TOKENS',
            'published_artifact_worker_execution',
            'published_artifact_worker_execution_derived',
            'published_server_image_activity_runtime_probe',
            'published_artifact_worker_execution must prove execution inside the pinned server container',
            'SOURCE_FREE_RUNNER_STATEMENT',
            'published_server_image_conformance_handoff',
            'local vendor trees were not used as pass evidence',
            'focused_published_server_activity_host_probe',
            'PublishedActivitiesEmbeddedWorkflow',
            'run_retry_backoff_cell',
            'run_timeout_behavior_cell',
            'scenario_from_timeout_behavior_cell',
            'retry_task_not_ready_before_backoff_elapsed',
            'start_to_close_timeout_seconds',
            'ActivityTimedOut',
            'enforcement_observed_at',
            'caller_visible_outcome',
            'activity_host_evidence',
            'published_server_container',
            'focusedActivityHostEvidenceFailures',
            'sdkPythonCellArtifactFailures',
            'worker_artifact',
            'durable_workflow.serializer.envelope',
            'sdk_python_activity_worker_artifact_missing',
            'outcome === \'pass\' ? 0 : 1',
        ] as $token) {
            $this->assertStringContainsString($token, $source);
        }
    }

    public function test_runner_does_not_pass_without_activity_product_evidence(): void
    {
        if (trim((string) shell_exec('command -v bash 2>/dev/null')) === ''
            || trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
            $this->markTestSkipped('bash and node are required to exercise the activities runner.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $resultDir = $repoRoot.'/storage/framework/activities-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($resultDir, 0777, true));

        try {
            $env = [
                'DW_SERVER_VERSION' => '9.9.9',
                'DW_CLI_VERSION' => '9.9.9',
                'DW_PYTHON_SDK_VERSION' => '9.9.9',
                'DW_WORKFLOW_PHP_VERSION' => '9.9.9',
                'DW_WATERLINE_VERSION' => '9.9.9',
            ];
            $envPrefix = implode(' ', array_map(
                static fn (string $name, string $value): string => $name.'='.escapeshellarg($value),
                array_keys($env),
                array_values($env),
            ));
            $command = sprintf(
                '%s bash %s --result-dir %s >/dev/null 2>&1',
                $envPrefix,
                escapeshellarg($repoRoot.'/scripts/conformance/activities-published-artifacts.sh'),
                escapeshellarg($resultDir),
            );

            exec($command, $output, $exitCode);
            $this->assertSame(1, $exitCode);

            $result = json_decode(
                file_get_contents($resultDir.'/activities-result.json') ?: '',
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $this->assertSame('non_passing', $result['outcome']);
            $this->assertFalse($result['runner_blocked']);
            $this->assertSame('9.9.9', $result['published_artifact_versions']['server']);
            $this->assertSame('9.9.9', $result['published_artifact_versions']['cli']);
            $this->assertSame('9.9.9', $result['published_artifact_versions']['sdk-python']);
            $this->assertSame('9.9.9', $result['published_artifact_versions']['workflow']);
            $this->assertSame('9.9.9', $result['published_artifact_versions']['waterline']);

            $byScenario = [];
            foreach ($result['scenario_results'] ?? [] as $scenario) {
                $byScenario[$scenario['scenario_id']] = $scenario;
            }

            foreach (ActivityRuntimeContract::manifest()['required_scenarios'] as $scenarioId) {
                $this->assertArrayHasKey($scenarioId, $byScenario);
                $this->assertSame('not_covered', $byScenario[$scenarioId]['status']);
                $this->assertSame('coverage-gap', $byScenario[$scenarioId]['classification']);
                $this->assertNotEmpty($byScenario[$scenarioId]['linked_findings'] ?? []);
            }
        } finally {
            foreach (glob($resultDir.'/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($resultDir)) {
                rmdir($resultDir);
            }
        }
    }

    public function test_runner_accepts_digest_pinned_server_install_source(): void
    {
        if (trim((string) shell_exec('command -v bash 2>/dev/null')) === ''
            || trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
            $this->markTestSkipped('bash and node are required to exercise the activities runner.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $resultDir = $repoRoot.'/storage/framework/activities-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($resultDir, 0777, true));

        try {
            $version = '9.9.9';
            $digest = 'sha256:'.str_repeat('a', 64);
            $serverImage = 'durableworkflow/server@'.$digest;

            $installEvidence = [
                'schema' => 'durable-workflow.v2.activity-runtime.artifact-install-evidence',
                'local_product_source_checkouts_used' => false,
                'artifacts' => [
                    [
                        'artifact' => 'server',
                        'version' => $version,
                        'source' => $serverImage,
                        'status' => 'pass',
                        'local_product_source_checkouts_used' => false,
                    ],
                    [
                        'artifact' => 'cli',
                        'version' => $version,
                        'source' => 'https://github.com/durable-workflow/cli/releases/download/v'.$version.'/install.sh',
                        'status' => 'pass',
                        'local_product_source_checkouts_used' => false,
                    ],
                    [
                        'artifact' => 'sdk-python',
                        'version' => $version,
                        'source' => 'https://pypi.org/project/durable-workflow/'.$version.'/',
                        'status' => 'pass',
                        'local_product_source_checkouts_used' => false,
                    ],
                    [
                        'artifact' => 'workflow-php',
                        'version' => $version,
                        'source' => 'https://packagist.org/packages/durable-workflow/workflow#'.$version,
                        'status' => 'pass',
                        'local_product_source_checkouts_used' => false,
                    ],
                    [
                        'artifact' => 'waterline',
                        'version' => $version,
                        'source' => 'https://packagist.org/packages/durable-workflow/waterline#'.$version,
                        'status' => 'pass',
                        'local_product_source_checkouts_used' => false,
                    ],
                ],
            ];

            $scenarioResults = [];
            foreach (ActivityRuntimeContract::manifest()['required_scenarios'] as $scenarioId) {
                $activityHostEvidence = $this->activityHostEvidenceForScenario($scenarioId);
                $scenarioResults[] = [
                    'scenario_id' => $scenarioId,
                    'status' => 'pass',
                    'observed_outputs' => array_filter([
                        'evidence' => $scenarioId,
                        'activity_host_evidence' => $activityHostEvidence,
                    ]),
                    'scenario_evidence' => array_filter([
                        'evidence' => $scenarioId,
                        'activity_host_evidence' => $activityHostEvidence,
                    ]),
                ];
            }

            $activityEvidence = [
                'schema' => 'durable-workflow.v2.activity-runtime.host-evidence',
                'execution_source' => 'published_server_container',
                'scenario_results' => $scenarioResults,
                'published_artifact_worker_execution' => $this->publishedServerExecutionEvidence($version, $serverImage),
                'published_artifact_install' => [
                    'status' => 'pass',
                    'server_image' => $serverImage,
                ],
                'runtime_matrix' => [
                    'execution_modes' => ['workflow-embedded', 'standalone'],
                    'runtimes' => ['workflow-php', 'sdk-python'],
                ],
                'durable_result_recording' => ['status' => 'pass'],
                'retry_backoff' => ['status' => 'pass'],
                'timeout_behavior' => ['status' => 'pass'],
                'typed_failure_propagation' => ['status' => 'pass'],
                'heartbeat_cancellation' => ['status' => 'pass'],
                'idempotent_completion' => ['status' => 'pass'],
                'operator_visibility' => ['status' => 'pass'],
            ];

            file_put_contents(
                $resultDir.'/artifact-install-evidence.json',
                json_encode($installEvidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            );
            file_put_contents(
                $resultDir.'/activity-evidence.json',
                json_encode($activityEvidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            );

            $env = [
                'DW_SERVER_IMAGE' => $serverImage,
                'DW_SERVER_VERSION' => $version,
                'DW_CLI_VERSION' => $version,
                'DW_PYTHON_SDK_VERSION' => $version,
                'DW_WORKFLOW_PHP_VERSION' => $version,
                'DW_WATERLINE_VERSION' => $version,
            ];
            $envPrefix = implode(' ', array_map(
                static fn (string $name, string $value): string => $name.'='.escapeshellarg($value),
                array_keys($env),
                array_values($env),
            ));
            $command = sprintf(
                '%s bash %s --result-dir %s >/dev/null 2>&1',
                $envPrefix,
                escapeshellarg($repoRoot.'/scripts/conformance/activities-published-artifacts.sh'),
                escapeshellarg($resultDir),
            );

            exec($command, $output, $exitCode);
            $this->assertSame(0, $exitCode);

            $result = json_decode(
                file_get_contents($resultDir.'/activities-result.json') ?: '',
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $this->assertSame('pass', $result['outcome']);
            $this->assertSame($version, $result['published_artifact_versions']['server']);
            $this->assertSame($serverImage, $result['artifact_sources']['server']);
            $this->assertSame([], $result['published_artifact_install']['pin_failures'] ?? []);
            $this->assertSame([], $result['published_artifact_install']['install_failures'] ?? []);
            $this->assertSame(
                'pass',
                ActivityRuntimeResultGate::evaluate($result, ActivityRuntimeContract::manifest())['status'],
            );
        } finally {
            foreach (glob($resultDir.'/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($resultDir)) {
                rmdir($resultDir);
            }
        }
    }

    public function test_runner_records_focused_published_activity_host_evidence_without_passing_full_matrix(): void
    {
        if (trim((string) shell_exec('command -v bash 2>/dev/null')) === ''
            || trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
            $this->markTestSkipped('bash and node are required to exercise the activities runner.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $resultDir = $repoRoot.'/storage/framework/activities-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($resultDir, 0777, true));

        try {
            $version = '9.9.9';
            $serverImage = 'durableworkflow/server:'.$version;
            $installEvidence = $this->completeRunnerInstallEvidence($version);
            $scenarioResults = [];

            foreach (['workflow_embedded_activity_result', 'standalone_activity_result'] as $scenarioId) {
                $activityHostEvidence = $this->activityHostEvidenceForScenario($scenarioId);
                $scenarioResults[] = [
                    'scenario_id' => $scenarioId,
                    'status' => 'pass',
                    'observed_outputs' => [
                        'activity_result' => $scenarioId.' ok',
                        'activity_host_evidence' => $activityHostEvidence,
                    ],
                    'scenario_evidence' => [
                        'activity_host_evidence' => $activityHostEvidence,
                    ],
                ];
            }

            $activityEvidence = [
                'schema' => 'durable-workflow.v2.activity-runtime.host-evidence',
                'execution_source' => 'published_server_container',
                'scenario_results' => $scenarioResults,
                'published_artifact_worker_execution' => $this->publishedServerExecutionEvidence($version, $serverImage),
                'runtime_matrix' => [
                    'execution_modes' => ['workflow-embedded', 'standalone'],
                    'runtimes' => ['workflow-php', 'sdk-python'],
                    'activity_cells' => array_merge(
                        $this->activityHostEvidenceForScenario('workflow_embedded_activity_result')['activity_cells'],
                        $this->activityHostEvidenceForScenario('standalone_activity_result')['activity_cells'],
                    ),
                    'behavior_cells' => [
                        ['scenario' => 'workflow_embedded_activity_result', 'status' => 'pass'],
                        ['scenario' => 'standalone_activity_result', 'status' => 'pass'],
                    ],
                ],
            ];

            file_put_contents(
                $resultDir.'/artifact-install-evidence.json',
                json_encode($installEvidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            );
            file_put_contents(
                $resultDir.'/activity-evidence.json',
                json_encode($activityEvidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            );

            $env = [
                'DW_ACTIVITIES_SKIP_FOCUSED_HOST_PROBE' => '1',
                'DW_SERVER_IMAGE' => $serverImage,
                'DW_SERVER_VERSION' => $version,
                'DW_CLI_VERSION' => $version,
                'DW_PYTHON_SDK_VERSION' => $version,
                'DW_WORKFLOW_PHP_VERSION' => $version,
                'DW_WATERLINE_VERSION' => $version,
            ];
            $envPrefix = implode(' ', array_map(
                static fn (string $name, string $value): string => $name.'='.escapeshellarg($value),
                array_keys($env),
                array_values($env),
            ));
            $command = sprintf(
                '%s bash %s --result-dir %s >/dev/null 2>&1',
                $envPrefix,
                escapeshellarg($repoRoot.'/scripts/conformance/activities-published-artifacts.sh'),
                escapeshellarg($resultDir),
            );

            exec($command, $output, $exitCode);
            $this->assertSame(1, $exitCode);

            $result = json_decode(
                file_get_contents($resultDir.'/activities-result.json') ?: '',
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $this->assertSame('non_passing', $result['outcome']);
            $this->assertFalse($result['runner_blocked']);
            $this->assertSame('published_server_container', $result['execution_source']);

            $byScenario = [];
            foreach ($result['scenario_results'] ?? [] as $scenario) {
                $byScenario[$scenario['scenario_id']] = $scenario;
            }

            foreach (['workflow_embedded_activity_result', 'standalone_activity_result'] as $scenarioId) {
                $this->assertSame('pass', $byScenario[$scenarioId]['status'] ?? null);
                $this->assertArrayNotHasKey('linked_findings', $byScenario[$scenarioId]);
                $this->assertSame(
                    'published_server_container',
                    $byScenario[$scenarioId]['observed_outputs']['activity_host_evidence']['execution_source'] ?? null,
                );
                $this->assertNotEmpty(
                    $byScenario[$scenarioId]['observed_outputs']['activity_host_evidence']['activity_cells'] ?? [],
                );
            }

            $this->assertSame('not_covered', $byScenario['retry_attempt_backoff_behavior']['status'] ?? null);
            $this->assertSame('coverage-gap', $byScenario['retry_attempt_backoff_behavior']['classification'] ?? null);

            $evaluation = ActivityRuntimeResultGate::evaluate($result, ActivityRuntimeContract::manifest());
            $this->assertSame('non_passing', $evaluation['status']);
            $focusedMissingFailures = array_filter(
                $evaluation['gate_failures'],
                static fn (array $failure): bool => ($failure['code'] ?? null) === 'activity_host_evidence_missing'
                    && in_array($failure['scenario_id'] ?? null, [
                        'workflow_embedded_activity_result',
                        'standalone_activity_result',
                    ], true),
            );
            $this->assertSame([], array_values($focusedMissingFailures));
        } finally {
            foreach (glob($resultDir.'/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($resultDir)) {
                rmdir($resultDir);
            }
        }
    }

    public function test_runner_records_restart_safe_result_recording_without_passing_full_matrix(): void
    {
        if (trim((string) shell_exec('command -v bash 2>/dev/null')) === ''
            || trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
            $this->markTestSkipped('bash and node are required to exercise the activities runner.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $resultDir = $repoRoot.'/storage/framework/activities-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($resultDir, 0777, true));

        try {
            $version = '9.9.9';
            $serverImage = 'durableworkflow/server:'.$version;
            $scenarioResults = [];

            foreach (['workflow_embedded_activity_result', 'standalone_activity_result'] as $scenarioId) {
                $activityHostEvidence = $this->activityHostEvidenceForScenario($scenarioId);
                $scenarioResults[] = [
                    'scenario_id' => $scenarioId,
                    'status' => 'pass',
                    'observed_outputs' => array_filter([
                        'activity_host_evidence' => $activityHostEvidence,
                    ]),
                    'scenario_evidence' => array_filter([
                        'activity_host_evidence' => $activityHostEvidence,
                    ]),
                ];
            }

            $scenarioResults[] = [
                'scenario_id' => 'durable_result_recording_after_worker_restart',
                'status' => 'pass',
                'observed_outputs' => [
                    'execution_source' => 'published_server_container',
                    'first_worker_identity' => 'activities-restart-first-abc123',
                    'restart_worker_identity' => 'activities-restart-replay-abc123',
                    'activity_execution_id' => 'act_exec_abc123',
                    'result_recorded_before_restart' => true,
                    'result_observed_after_restart' => true,
                    'activity_completed_count_before_restart' => 1,
                    'activity_completed_count_after_replay' => 1,
                    'duplicate_activity_count' => 0,
                ],
                'scenario_evidence' => [
                    'restart_durable_result_recording' => [
                        'execution_source' => 'published_server_container',
                        'first_worker_identity' => 'activities-restart-first-abc123',
                        'restart_worker_identity' => 'activities-restart-replay-abc123',
                        'activity_execution_id' => 'act_exec_abc123',
                        'result_recorded_before_restart' => true,
                        'result_observed_after_restart' => true,
                        'duplicate_activity_count' => 0,
                    ],
                ],
            ];

            $activityEvidence = [
                'schema' => 'durable-workflow.v2.activity-runtime.host-evidence',
                'execution_source' => 'published_server_container',
                'scenario_results' => $scenarioResults,
                'published_artifact_worker_execution' => $this->publishedServerExecutionEvidence($version, $serverImage),
                'runtime_matrix' => [
                    'execution_modes' => ['workflow-embedded', 'standalone'],
                    'runtimes' => ['workflow-php', 'sdk-python'],
                    'activity_cells' => array_merge(
                        $this->activityHostEvidenceForScenario('workflow_embedded_activity_result')['activity_cells'],
                        $this->activityHostEvidenceForScenario('standalone_activity_result')['activity_cells'],
                    ),
                    'behavior_cells' => [
                        ['scenario' => 'durable_result_recording_after_worker_restart', 'status' => 'pass'],
                        ['scenario' => 'retry_attempt_backoff_behavior', 'status' => 'not_covered'],
                    ],
                ],
                'durable_result_recording' => [
                    'status' => 'pass',
                    'scenario' => 'durable_result_recording_after_worker_restart',
                    'activity_execution_id' => 'act_exec_abc123',
                    'duplicate_activity_count' => 0,
                ],
            ];

            file_put_contents(
                $resultDir.'/artifact-install-evidence.json',
                json_encode($this->completeRunnerInstallEvidence($version), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            );
            file_put_contents(
                $resultDir.'/activity-evidence.json',
                json_encode($activityEvidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            );

            $env = [
                'DW_ACTIVITIES_SKIP_FOCUSED_HOST_PROBE' => '1',
                'DW_SERVER_IMAGE' => $serverImage,
                'DW_SERVER_VERSION' => $version,
                'DW_CLI_VERSION' => $version,
                'DW_PYTHON_SDK_VERSION' => $version,
                'DW_WORKFLOW_PHP_VERSION' => $version,
                'DW_WATERLINE_VERSION' => $version,
            ];
            $envPrefix = implode(' ', array_map(
                static fn (string $name, string $value): string => $name.'='.escapeshellarg($value),
                array_keys($env),
                array_values($env),
            ));
            $command = sprintf(
                '%s bash %s --result-dir %s >/dev/null 2>&1',
                $envPrefix,
                escapeshellarg($repoRoot.'/scripts/conformance/activities-published-artifacts.sh'),
                escapeshellarg($resultDir),
            );

            exec($command, $output, $exitCode);
            $this->assertSame(1, $exitCode);

            $result = json_decode(
                file_get_contents($resultDir.'/activities-result.json') ?: '',
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $this->assertSame('non_passing', $result['outcome']);
            $this->assertFalse($result['runner_blocked']);
            $this->assertSame('pass', $result['durable_result_recording']['status'] ?? null);
            $this->assertSame(0, $result['durable_result_recording']['duplicate_activity_count'] ?? null);

            $byScenario = [];
            foreach ($result['scenario_results'] ?? [] as $scenario) {
                $byScenario[$scenario['scenario_id']] = $scenario;
            }

            $this->assertSame('pass', $byScenario['durable_result_recording_after_worker_restart']['status'] ?? null);
            $this->assertArrayNotHasKey(
                'linked_findings',
                $byScenario['durable_result_recording_after_worker_restart'],
            );
            $this->assertSame(
                true,
                $byScenario['durable_result_recording_after_worker_restart']['observed_outputs']['result_recorded_before_restart'] ?? null,
            );
            $this->assertSame(
                true,
                $byScenario['durable_result_recording_after_worker_restart']['observed_outputs']['result_observed_after_restart'] ?? null,
            );
            $this->assertSame(
                0,
                $byScenario['durable_result_recording_after_worker_restart']['observed_outputs']['duplicate_activity_count'] ?? null,
            );
            $this->assertSame('not_covered', $byScenario['retry_attempt_backoff_behavior']['status'] ?? null);
            $this->assertSame('coverage-gap', $byScenario['retry_attempt_backoff_behavior']['classification'] ?? null);

            $evaluation = ActivityRuntimeResultGate::evaluate($result, ActivityRuntimeContract::manifest());
            $this->assertSame('non_passing', $evaluation['status']);
            $this->assertNotContains(
                'durable_result_recording_after_worker_restart',
                $evaluation['non_pass_scenarios'],
            );
        } finally {
            foreach (glob($resultDir.'/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($resultDir)) {
                rmdir($resultDir);
            }
        }
    }

    public function test_runner_records_retry_backoff_attempt_behavior_without_passing_full_matrix(): void
    {
        if (trim((string) shell_exec('command -v bash 2>/dev/null')) === ''
            || trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
            $this->markTestSkipped('bash and node are required to exercise the activities runner.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $resultDir = $repoRoot.'/storage/framework/activities-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($resultDir, 0777, true));

        try {
            $version = '9.9.9';
            $serverImage = 'durableworkflow/server:'.$version;
            $scenarioResults = [];

            foreach (['workflow_embedded_activity_result', 'standalone_activity_result'] as $scenarioId) {
                $activityHostEvidence = $this->activityHostEvidenceForScenario($scenarioId);
                $scenarioResults[] = [
                    'scenario_id' => $scenarioId,
                    'status' => 'pass',
                    'observed_outputs' => array_filter([
                        'activity_host_evidence' => $activityHostEvidence,
                    ]),
                    'scenario_evidence' => array_filter([
                        'activity_host_evidence' => $activityHostEvidence,
                    ]),
                ];
            }

            $retryObserved = [
                'execution_source' => 'published_server_container',
                'activity_id' => 'activities-retry-backoff-abc123',
                'workflow_run_id' => 'run_retry_backoff_abc123',
                'activity_execution_id' => 'act_exec_retry_abc123',
                'activity_type' => 'activities.conformance.echo',
                'attempts' => [
                    [
                        'attempt_number' => 1,
                        'task_id' => 'task_retry_first',
                        'activity_attempt_id' => 'attempt_retry_first',
                        'activity_execution_id' => 'act_exec_retry_abc123',
                        'status_after_report' => 'failed_retry_scheduled',
                    ],
                    [
                        'attempt_number' => 2,
                        'task_id' => 'task_retry_second',
                        'activity_attempt_id' => 'attempt_retry_second',
                        'activity_execution_id' => 'act_exec_retry_abc123',
                        'status_after_report' => 'completed',
                    ],
                ],
                'attempt_state' => [
                    [
                        'activity_attempt_id' => 'attempt_retry_first',
                        'attempt_number' => 1,
                        'status' => 'failed',
                    ],
                    [
                        'activity_attempt_id' => 'attempt_retry_second',
                        'attempt_number' => 2,
                        'status' => 'completed',
                    ],
                ],
                'failure_payloads' => [
                    [
                        'attempt_number' => 1,
                        'failure' => [
                            'message' => 'activities conformance retryable failure',
                            'type' => 'ActivitiesConformanceRetryableFailure',
                            'retryable' => true,
                            'non_retryable' => false,
                        ],
                    ],
                ],
                'configured_retry_policy' => [
                    'max_attempts' => 3,
                    'backoff_seconds' => [1],
                    'non_retryable_error_types' => ['ActivitiesConformanceNonRetryable'],
                ],
                'retry_policy' => [
                    'snapshot_version' => 1,
                    'max_attempts' => 3,
                    'backoff_seconds' => [1],
                    'start_to_close_timeout' => null,
                    'schedule_to_start_timeout' => null,
                    'schedule_to_close_timeout' => null,
                    'heartbeat_timeout' => null,
                    'non_retryable_error_types' => ['ActivitiesConformanceNonRetryable'],
                ],
                'leased_retry_policies' => [
                    'first_attempt' => [
                        'max_attempts' => 3,
                        'backoff_seconds' => [1],
                    ],
                    'second_attempt' => [
                        'max_attempts' => 3,
                        'backoff_seconds' => [1],
                    ],
                ],
                'scheduled_backoff_seconds' => 1.0,
                'configured_backoff_seconds' => 1,
                'observed_redelivery_timestamps' => [
                    'first_attempt_failed_at' => '2026-06-22T00:00:00.000000Z',
                    'retry_task_available_at' => '2026-06-22T00:00:01.000000Z',
                    'second_attempt_leased_at' => '2026-06-22T00:00:01.050000Z',
                    'retry_task_not_ready_before_backoff_elapsed' => true,
                    'second_attempt_leased_after_available_at' => true,
                    'observed_redelivery_delay_seconds' => 1.05,
                ],
                'terminal_result' => [
                    'activity_status' => 'completed',
                    'run_status' => 'completed',
                    'closed_reason' => 'completed',
                ],
            ];

            $scenarioResults[] = [
                'scenario_id' => 'retry_attempt_backoff_behavior',
                'status' => 'pass',
                'observed_outputs' => $retryObserved,
                'scenario_evidence' => [
                    'retry_backoff_attempt_behavior' => $retryObserved,
                ],
            ];

            $activityEvidence = [
                'schema' => 'durable-workflow.v2.activity-runtime.host-evidence',
                'execution_source' => 'published_server_container',
                'scenario_results' => $scenarioResults,
                'published_artifact_worker_execution' => $this->publishedServerExecutionEvidence($version, $serverImage),
                'runtime_matrix' => [
                    'execution_modes' => ['workflow-embedded', 'standalone'],
                    'runtimes' => ['workflow-php', 'sdk-python'],
                    'activity_cells' => array_merge(
                        $this->activityHostEvidenceForScenario('workflow_embedded_activity_result')['activity_cells'],
                        $this->activityHostEvidenceForScenario('standalone_activity_result')['activity_cells'],
                    ),
                    'behavior_cells' => [
                        ['scenario' => 'durable_result_recording_after_worker_restart', 'status' => 'not_covered'],
                        ['scenario' => 'retry_attempt_backoff_behavior', 'status' => 'pass'],
                    ],
                ],
                'retry_backoff' => [
                    'status' => 'pass',
                    'scenario' => 'retry_attempt_backoff_behavior',
                    'attempts' => $retryObserved['attempts'],
                    'failure_payloads' => $retryObserved['failure_payloads'],
                    'configured_retry_policy' => $retryObserved['configured_retry_policy'],
                    'retry_policy' => $retryObserved['retry_policy'],
                    'leased_retry_policies' => $retryObserved['leased_retry_policies'],
                    'configured_backoff_seconds' => $retryObserved['configured_backoff_seconds'],
                    'scheduled_backoff_seconds' => $retryObserved['scheduled_backoff_seconds'],
                    'observed_redelivery_timestamps' => $retryObserved['observed_redelivery_timestamps'],
                    'terminal_result' => $retryObserved['terminal_result'],
                ],
            ];

            file_put_contents(
                $resultDir.'/artifact-install-evidence.json',
                json_encode($this->completeRunnerInstallEvidence($version), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            );
            file_put_contents(
                $resultDir.'/activity-evidence.json',
                json_encode($activityEvidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            );

            $env = [
                'DW_ACTIVITIES_SKIP_FOCUSED_HOST_PROBE' => '1',
                'DW_SERVER_IMAGE' => $serverImage,
                'DW_SERVER_VERSION' => $version,
                'DW_CLI_VERSION' => $version,
                'DW_PYTHON_SDK_VERSION' => $version,
                'DW_WORKFLOW_PHP_VERSION' => $version,
                'DW_WATERLINE_VERSION' => $version,
            ];
            $envPrefix = implode(' ', array_map(
                static fn (string $name, string $value): string => $name.'='.escapeshellarg($value),
                array_keys($env),
                array_values($env),
            ));
            $command = sprintf(
                '%s bash %s --result-dir %s >/dev/null 2>&1',
                $envPrefix,
                escapeshellarg($repoRoot.'/scripts/conformance/activities-published-artifacts.sh'),
                escapeshellarg($resultDir),
            );

            exec($command, $output, $exitCode);
            $this->assertSame(1, $exitCode);

            $result = json_decode(
                file_get_contents($resultDir.'/activities-result.json') ?: '',
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $this->assertSame('non_passing', $result['outcome']);
            $this->assertFalse($result['runner_blocked']);
            $this->assertSame('pass', $result['retry_backoff']['status'] ?? null);
            $this->assertSame([1], $result['retry_backoff']['configured_retry_policy']['backoff_seconds'] ?? null);
            $this->assertSame(1, $result['retry_backoff']['configured_backoff_seconds'] ?? null);
            $this->assertEquals(1.0, $result['retry_backoff']['scheduled_backoff_seconds'] ?? null);
            $this->assertTrue(
                $result['retry_backoff']['observed_redelivery_timestamps']['retry_task_not_ready_before_backoff_elapsed'] ?? false,
            );
            $this->assertTrue(
                $result['retry_backoff']['observed_redelivery_timestamps']['second_attempt_leased_after_available_at'] ?? false,
            );
            $this->assertSame('completed', $result['retry_backoff']['terminal_result']['run_status'] ?? null);

            $byScenario = [];
            foreach ($result['scenario_results'] ?? [] as $scenario) {
                $byScenario[$scenario['scenario_id']] = $scenario;
            }

            $this->assertSame('pass', $byScenario['retry_attempt_backoff_behavior']['status'] ?? null);
            $this->assertArrayNotHasKey('linked_findings', $byScenario['retry_attempt_backoff_behavior']);
            $this->assertSame(
                2,
                $byScenario['retry_attempt_backoff_behavior']['observed_outputs']['attempts'][1]['attempt_number'] ?? null,
            );
            $this->assertSame('not_covered', $byScenario['timeout_behavior']['status'] ?? null);
            $this->assertSame('coverage-gap', $byScenario['timeout_behavior']['classification'] ?? null);

            $evaluation = ActivityRuntimeResultGate::evaluate($result, ActivityRuntimeContract::manifest());
            $this->assertSame('non_passing', $evaluation['status']);
            $this->assertNotContains('retry_attempt_backoff_behavior', $evaluation['non_pass_scenarios']);
        } finally {
            foreach (glob($resultDir.'/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($resultDir)) {
                rmdir($resultDir);
            }
        }
    }

    public function test_runner_records_timeout_behavior_host_evidence_without_passing_full_matrix(): void
    {
        if (trim((string) shell_exec('command -v bash 2>/dev/null')) === ''
            || trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
            $this->markTestSkipped('bash and node are required to exercise the activities runner.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $resultDir = $repoRoot.'/storage/framework/activities-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($resultDir, 0777, true));

        try {
            $version = '9.9.9';
            $serverImage = 'durableworkflow/server:'.$version;
            $scenarioResults = [];
            foreach ([
                'workflow_embedded_activity_result',
                'standalone_activity_result',
            ] as $scenarioId) {
                $activityHostEvidence = $this->activityHostEvidenceForScenario($scenarioId);
                $scenarioResults[] = [
                    'scenario_id' => $scenarioId,
                    'status' => 'pass',
                    'observed_outputs' => [
                        'evidence' => $scenarioId,
                        'activity_host_evidence' => $activityHostEvidence,
                    ],
                    'scenario_evidence' => [
                        'evidence' => $scenarioId,
                        'activity_host_evidence' => $activityHostEvidence,
                    ],
                ];
            }

            $timeoutObserved = [
                'activity_host_evidence' => [
                    'schema' => 'durable-workflow.v2.activity-runtime.published-artifact-host-evidence',
                    'scenario_id' => 'timeout_behavior',
                    'status' => 'pass',
                    'execution_source' => 'published_server_container',
                    'local_product_source_checkouts_used' => false,
                    'activity_cells' => [[
                        'mode' => 'standalone',
                        'runtime' => 'workflow-php',
                        'status' => 'pass',
                        'execution_source' => 'published_server_container',
                        'worker_visible_deadlines' => [
                            'start_to_close' => '2026-06-22T00:00:01Z',
                            'schedule_to_close' => '2026-06-22T00:00:30Z',
                        ],
                        'local_product_source_checkouts_used' => false,
                    ]],
                ],
                'configured_timeout_inputs' => [
                    'start_to_close_timeout_seconds' => 1,
                    'schedule_to_close_timeout_seconds' => 30,
                    'retry_policy' => [
                        'max_attempts' => 1,
                        'backoff_seconds' => [0],
                    ],
                ],
                'timeout_type' => 'start_to_close',
                'deadline_at' => '2026-06-22T00:00:01Z',
                'worker_visible_deadlines' => [
                    'start_to_close' => '2026-06-22T00:00:01Z',
                    'schedule_to_close' => '2026-06-22T00:00:30Z',
                ],
                'enforcement_endpoint' => 'POST /api/system/activity-timeouts/pass',
                'enforcement_observed_at' => '2026-06-22T00:00:02Z',
                'timeout_status_before_enforce' => [
                    'expired_count' => 1,
                    'expired_execution_ids' => ['activity-timeout-execution'],
                    'scan_limit' => 100,
                    'scan_pressure' => false,
                ],
                'enforce_response' => [
                    'processed' => 1,
                    'enforced' => 1,
                    'skipped' => 0,
                    'failed' => 0,
                    'results' => [[
                        'execution_id' => 'activity-timeout-execution',
                        'outcome' => 'enforced',
                        'has_retry' => false,
                    ]],
                ],
                'typed_timeout_payload' => [
                    'timeout_type' => 'start_to_close',
                    'timeout_kind' => 'start_to_close',
                    'failure_category' => 'timeout',
                    'exception_class' => 'Workflow\\V2\\Exceptions\\ActivityTimeoutException',
                    'message' => 'Activity activities.conformance.echo start-to-close deadline expired at 2026-06-22T00:00:01Z.',
                    'activity_execution_id' => 'activity-timeout-execution',
                    'activity_attempt_id' => 'activity-timeout-attempt',
                ],
                'activity_status' => 'failed',
                'caller_visible_outcome' => [
                    'activity_status' => 'failed',
                    'run_status' => 'failed',
                    'closed_reason' => 'timed_out',
                ],
                'history_events' => [
                    'ActivityTimedOut',
                    'WorkflowFailed',
                ],
            ];

            $scenarioResults[] = [
                'scenario_id' => 'timeout_behavior',
                'status' => 'pass',
                'observed_outputs' => $timeoutObserved,
                'scenario_evidence' => [
                    'timeout_behavior' => $timeoutObserved,
                    'activity_host_evidence' => $timeoutObserved['activity_host_evidence'],
                ],
            ];

            $activityEvidence = [
                'schema' => 'durable-workflow.v2.activity-runtime.host-evidence',
                'execution_source' => 'published_server_container',
                'scenario_results' => $scenarioResults,
                'published_artifact_worker_execution' => $this->publishedServerExecutionEvidence($version, $serverImage),
                'runtime_matrix' => [
                    'execution_modes' => ['workflow-embedded', 'standalone'],
                    'runtimes' => ['workflow-php', 'sdk-python'],
                    'activity_cells' => array_merge(
                        $this->activityHostEvidenceForScenario('workflow_embedded_activity_result')['activity_cells'],
                        $this->activityHostEvidenceForScenario('standalone_activity_result')['activity_cells'],
                    ),
                    'behavior_cells' => [
                        ['scenario' => 'timeout_behavior', 'status' => 'pass'],
                    ],
                ],
                'timeout_behavior' => [
                    'status' => 'pass',
                    'scenario' => 'timeout_behavior',
                    'configured_timeout_inputs' => $timeoutObserved['configured_timeout_inputs'],
                    'timeout_type' => $timeoutObserved['timeout_type'],
                    'deadline_at' => $timeoutObserved['deadline_at'],
                    'worker_visible_deadlines' => $timeoutObserved['worker_visible_deadlines'],
                    'enforcement_endpoint' => $timeoutObserved['enforcement_endpoint'],
                    'enforcement_observed_at' => $timeoutObserved['enforcement_observed_at'],
                    'timeout_status_before_enforce' => $timeoutObserved['timeout_status_before_enforce'],
                    'enforce_response' => $timeoutObserved['enforce_response'],
                    'typed_timeout_payload' => $timeoutObserved['typed_timeout_payload'],
                    'activity_status' => $timeoutObserved['activity_status'],
                    'caller_visible_outcome' => $timeoutObserved['caller_visible_outcome'],
                    'history_events' => $timeoutObserved['history_events'],
                ],
            ];

            file_put_contents(
                $resultDir.'/artifact-install-evidence.json',
                json_encode($this->completeRunnerInstallEvidence($version), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            );
            file_put_contents(
                $resultDir.'/activity-evidence.json',
                json_encode($activityEvidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            );

            $env = [
                'DW_ACTIVITIES_SKIP_FOCUSED_HOST_PROBE' => '1',
                'DW_SERVER_IMAGE' => $serverImage,
                'DW_SERVER_VERSION' => $version,
                'DW_CLI_VERSION' => $version,
                'DW_PYTHON_SDK_VERSION' => $version,
                'DW_WORKFLOW_PHP_VERSION' => $version,
                'DW_WATERLINE_VERSION' => $version,
            ];
            $envPrefix = implode(' ', array_map(
                static fn (string $name, string $value): string => $name.'='.escapeshellarg($value),
                array_keys($env),
                array_values($env),
            ));
            $command = sprintf(
                '%s bash %s --result-dir %s >/dev/null 2>&1',
                $envPrefix,
                escapeshellarg($repoRoot.'/scripts/conformance/activities-published-artifacts.sh'),
                escapeshellarg($resultDir),
            );

            exec($command, $output, $exitCode);
            $this->assertSame(1, $exitCode);

            $result = json_decode(
                file_get_contents($resultDir.'/activities-result.json') ?: '',
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $this->assertSame('non_passing', $result['outcome']);
            $this->assertFalse($result['runner_blocked']);
            $this->assertSame('pass', $result['timeout_behavior']['status'] ?? null);
            $this->assertSame('start_to_close', $result['timeout_behavior']['timeout_type'] ?? null);
            $this->assertSame(1, $result['timeout_behavior']['configured_timeout_inputs']['start_to_close_timeout_seconds'] ?? null);
            $this->assertSame('POST /api/system/activity-timeouts/pass', $result['timeout_behavior']['enforcement_endpoint'] ?? null);
            $this->assertSame('timeout', $result['timeout_behavior']['typed_timeout_payload']['failure_category'] ?? null);
            $this->assertSame('timed_out', $result['timeout_behavior']['caller_visible_outcome']['closed_reason'] ?? null);

            $byScenario = [];
            foreach ($result['scenario_results'] ?? [] as $scenario) {
                $byScenario[$scenario['scenario_id']] = $scenario;
            }

            $this->assertSame('pass', $byScenario['timeout_behavior']['status'] ?? null);
            $this->assertArrayNotHasKey('linked_findings', $byScenario['timeout_behavior']);
            $this->assertSame(
                'start_to_close',
                $byScenario['timeout_behavior']['observed_outputs']['typed_timeout_payload']['timeout_type'] ?? null,
            );

            $evaluation = ActivityRuntimeResultGate::evaluate($result, ActivityRuntimeContract::manifest());
            $this->assertSame('non_passing', $evaluation['status']);
            $this->assertNotContains('timeout_behavior', $evaluation['non_pass_scenarios']);
            $this->assertContains('typed_failure_propagation', $evaluation['non_pass_scenarios']);
        } finally {
            foreach (glob($resultDir.'/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($resultDir)) {
                rmdir($resultDir);
            }
        }
    }

    public function test_runner_rejects_local_source_activity_host_cells_for_focused_evidence(): void
    {
        if (trim((string) shell_exec('command -v bash 2>/dev/null')) === ''
            || trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
            $this->markTestSkipped('bash and node are required to exercise the activities runner.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $resultDir = $repoRoot.'/storage/framework/activities-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($resultDir, 0777, true));

        try {
            $version = '9.9.9';
            $serverImage = 'durableworkflow/server:'.$version;
            $activityEvidence = $this->completeRunnerActivityEvidence($version, $serverImage);

            foreach ($activityEvidence['scenario_results'] as &$scenario) {
                if (($scenario['scenario_id'] ?? '') === 'workflow_embedded_activity_result') {
                    $scenario['observed_outputs']['activity_host_evidence']['activity_cells'][0]['local_product_source_checkouts_used'] = true;
                    $scenario['observed_outputs']['activity_host_evidence']['activity_cells'][0]['probe_source'] = 'local_source_checkout';
                    $scenario['scenario_evidence']['activity_host_evidence'] = $scenario['observed_outputs']['activity_host_evidence'];
                }

                if (($scenario['scenario_id'] ?? '') === 'standalone_activity_result') {
                    $scenario['observed_outputs']['activity_host_evidence']['activity_cells'][1]['localProductSourceCheckoutsUsed'] = true;
                    $scenario['scenario_evidence']['activity_host_evidence'] = $scenario['observed_outputs']['activity_host_evidence'];
                }
            }
            unset($scenario);

            file_put_contents(
                $resultDir.'/artifact-install-evidence.json',
                json_encode($this->completeRunnerInstallEvidence($version), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            );
            file_put_contents(
                $resultDir.'/activity-evidence.json',
                json_encode($activityEvidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            );

            $env = [
                'DW_ACTIVITIES_SKIP_FOCUSED_HOST_PROBE' => '1',
                'DW_SERVER_IMAGE' => $serverImage,
                'DW_SERVER_VERSION' => $version,
                'DW_CLI_VERSION' => $version,
                'DW_PYTHON_SDK_VERSION' => $version,
                'DW_WORKFLOW_PHP_VERSION' => $version,
                'DW_WATERLINE_VERSION' => $version,
            ];
            $envPrefix = implode(' ', array_map(
                static fn (string $name, string $value): string => $name.'='.escapeshellarg($value),
                array_keys($env),
                array_values($env),
            ));
            $command = sprintf(
                '%s bash %s --result-dir %s >/dev/null 2>&1',
                $envPrefix,
                escapeshellarg($repoRoot.'/scripts/conformance/activities-published-artifacts.sh'),
                escapeshellarg($resultDir),
            );

            exec($command, $output, $exitCode);
            $this->assertSame(1, $exitCode);

            $result = json_decode(
                file_get_contents($resultDir.'/activities-result.json') ?: '',
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $record = json_decode(
                file_get_contents($resultDir.'/activities-record.json') ?: '',
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $this->assertSame('non_passing', $result['outcome']);
            $this->assertFalse($result['runner_blocked']);
            $this->assertSame('fail', $record['outcome']);

            $byScenario = [];
            foreach ($result['scenario_results'] ?? [] as $scenario) {
                $byScenario[$scenario['scenario_id']] = $scenario;
            }

            foreach (['workflow_embedded_activity_result', 'standalone_activity_result'] as $scenarioId) {
                $this->assertSame('fail', $byScenario[$scenarioId]['status'] ?? null);
                $this->assertSame('product-gap', $byScenario[$scenarioId]['classification'] ?? null);
                $failureText = implode(
                    '; ',
                    $byScenario[$scenarioId]['observed_outputs']['activity_host_evidence_failures'] ?? [],
                );
                $this->assertStringContainsString('local_product_source_checkouts_used=true', $failureText);
                $this->assertNotEmpty($byScenario[$scenarioId]['linked_findings'] ?? []);
            }

            $workflowFailureText = implode(
                '; ',
                $byScenario['workflow_embedded_activity_result']['observed_outputs']['activity_host_evidence_failures'] ?? [],
            );
            $this->assertStringContainsString('local product source probe signals', $workflowFailureText);
        } finally {
            foreach (glob($resultDir.'/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($resultDir)) {
                rmdir($resultDir);
            }
        }
    }

    public function test_runner_rejects_focused_activity_host_evidence_without_explicit_source_free_marker(): void
    {
        if (trim((string) shell_exec('command -v bash 2>/dev/null')) === ''
            || trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
            $this->markTestSkipped('bash and node are required to exercise the activities runner.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $resultDir = $repoRoot.'/storage/framework/activities-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($resultDir, 0777, true));

        try {
            $version = '9.9.9';
            $serverImage = 'durableworkflow/server:'.$version;
            $activityEvidence = $this->completeRunnerActivityEvidence($version, $serverImage);

            foreach ($activityEvidence['scenario_results'] as &$scenario) {
                if (! in_array($scenario['scenario_id'] ?? '', [
                    'workflow_embedded_activity_result',
                    'standalone_activity_result',
                ], true)) {
                    continue;
                }

                unset($scenario['observed_outputs']['activity_host_evidence']['local_product_source_checkouts_used']);
                unset($scenario['scenario_evidence']['activity_host_evidence']['local_product_source_checkouts_used']);
            }
            unset($scenario);

            file_put_contents(
                $resultDir.'/artifact-install-evidence.json',
                json_encode($this->completeRunnerInstallEvidence($version), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            );
            file_put_contents(
                $resultDir.'/activity-evidence.json',
                json_encode($activityEvidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            );

            $env = [
                'DW_ACTIVITIES_SKIP_FOCUSED_HOST_PROBE' => '1',
                'DW_SERVER_IMAGE' => $serverImage,
                'DW_SERVER_VERSION' => $version,
                'DW_CLI_VERSION' => $version,
                'DW_PYTHON_SDK_VERSION' => $version,
                'DW_WORKFLOW_PHP_VERSION' => $version,
                'DW_WATERLINE_VERSION' => $version,
            ];
            $envPrefix = implode(' ', array_map(
                static fn (string $name, string $value): string => $name.'='.escapeshellarg($value),
                array_keys($env),
                array_values($env),
            ));
            $command = sprintf(
                '%s bash %s --result-dir %s >/dev/null 2>&1',
                $envPrefix,
                escapeshellarg($repoRoot.'/scripts/conformance/activities-published-artifacts.sh'),
                escapeshellarg($resultDir),
            );

            exec($command, $output, $exitCode);
            $this->assertSame(1, $exitCode);

            $result = json_decode(
                file_get_contents($resultDir.'/activities-result.json') ?: '',
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $record = json_decode(
                file_get_contents($resultDir.'/activities-record.json') ?: '',
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $this->assertSame('non_passing', $result['outcome']);
            $this->assertFalse($result['runner_blocked']);
            $this->assertSame('fail', $record['outcome']);

            $byScenario = [];
            foreach ($result['scenario_results'] ?? [] as $scenario) {
                $byScenario[$scenario['scenario_id']] = $scenario;
            }

            foreach (['workflow_embedded_activity_result', 'standalone_activity_result'] as $scenarioId) {
                $this->assertSame('fail', $byScenario[$scenarioId]['status'] ?? null);
                $this->assertSame('product-gap', $byScenario[$scenarioId]['classification'] ?? null);
                $failureText = implode(
                    '; ',
                    $byScenario[$scenarioId]['observed_outputs']['activity_host_evidence_failures'] ?? [],
                );
                $this->assertStringContainsString(
                    'activity_host_evidence.local_product_source_checkouts_used=false missing',
                    $failureText,
                );
                $this->assertNotEmpty($byScenario[$scenarioId]['linked_findings'] ?? []);
            }

            $this->assertSame(
                'non_passing',
                ActivityRuntimeResultGate::evaluate($result, ActivityRuntimeContract::manifest())['status'],
            );
        } finally {
            foreach (glob($resultDir.'/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($resultDir)) {
                rmdir($resultDir);
            }
        }
    }

    public function test_runner_rejects_host_level_local_source_signal_in_focused_activity_evidence(): void
    {
        if (trim((string) shell_exec('command -v bash 2>/dev/null')) === ''
            || trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
            $this->markTestSkipped('bash and node are required to exercise the activities runner.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $resultDir = $repoRoot.'/storage/framework/activities-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($resultDir, 0777, true));

        try {
            $version = '9.9.9';
            $serverImage = 'durableworkflow/server:'.$version;
            $activityEvidence = $this->completeRunnerActivityEvidence($version, $serverImage);

            foreach ($activityEvidence['scenario_results'] as &$scenario) {
                if (! in_array($scenario['scenario_id'] ?? '', [
                    'workflow_embedded_activity_result',
                    'standalone_activity_result',
                ], true)) {
                    continue;
                }

                $scenario['observed_outputs']['activity_host_evidence']['probe_source'] = 'local_source_checkout';
                $scenario['scenario_evidence']['activity_host_evidence'] = $scenario['observed_outputs']['activity_host_evidence'];
            }
            unset($scenario);

            file_put_contents(
                $resultDir.'/artifact-install-evidence.json',
                json_encode($this->completeRunnerInstallEvidence($version), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            );
            file_put_contents(
                $resultDir.'/activity-evidence.json',
                json_encode($activityEvidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            );

            $env = [
                'DW_ACTIVITIES_SKIP_FOCUSED_HOST_PROBE' => '1',
                'DW_SERVER_IMAGE' => $serverImage,
                'DW_SERVER_VERSION' => $version,
                'DW_CLI_VERSION' => $version,
                'DW_PYTHON_SDK_VERSION' => $version,
                'DW_WORKFLOW_PHP_VERSION' => $version,
                'DW_WATERLINE_VERSION' => $version,
            ];
            $envPrefix = implode(' ', array_map(
                static fn (string $name, string $value): string => $name.'='.escapeshellarg($value),
                array_keys($env),
                array_values($env),
            ));
            $command = sprintf(
                '%s bash %s --result-dir %s >/dev/null 2>&1',
                $envPrefix,
                escapeshellarg($repoRoot.'/scripts/conformance/activities-published-artifacts.sh'),
                escapeshellarg($resultDir),
            );

            exec($command, $output, $exitCode);
            $this->assertSame(1, $exitCode);

            $result = json_decode(
                file_get_contents($resultDir.'/activities-result.json') ?: '',
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $record = json_decode(
                file_get_contents($resultDir.'/activities-record.json') ?: '',
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $this->assertSame('non_passing', $result['outcome']);
            $this->assertFalse($result['runner_blocked']);
            $this->assertSame('fail', $record['outcome']);

            $byScenario = [];
            foreach ($result['scenario_results'] ?? [] as $scenario) {
                $byScenario[$scenario['scenario_id']] = $scenario;
            }

            foreach (['workflow_embedded_activity_result', 'standalone_activity_result'] as $scenarioId) {
                $this->assertSame('fail', $byScenario[$scenarioId]['status'] ?? null);
                $this->assertSame('product-gap', $byScenario[$scenarioId]['classification'] ?? null);
                $failureText = implode(
                    '; ',
                    $byScenario[$scenarioId]['observed_outputs']['activity_host_evidence_failures'] ?? [],
                );
                $this->assertStringContainsString(
                    'activity_host_evidence contains local product source probe signals',
                    $failureText,
                );
                $this->assertStringContainsString('local_source_checkout', $failureText);
                $this->assertNotEmpty($byScenario[$scenarioId]['linked_findings'] ?? []);
            }

            $evaluation = ActivityRuntimeResultGate::evaluate($result, ActivityRuntimeContract::manifest());
            $this->assertSame('non_passing', $evaluation['status']);
        } finally {
            foreach (glob($resultDir.'/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($resultDir)) {
                rmdir($resultDir);
            }
        }
    }

    public function test_runner_rejects_php_only_sdk_python_focused_activity_cells(): void
    {
        if (trim((string) shell_exec('command -v bash 2>/dev/null')) === ''
            || trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
            $this->markTestSkipped('bash and node are required to exercise the activities runner.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $resultDir = $repoRoot.'/storage/framework/activities-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($resultDir, 0777, true));

        try {
            $version = '9.9.9';
            $serverImage = 'durableworkflow/server:'.$version;
            $activityEvidence = $this->completeRunnerActivityEvidence($version, $serverImage);

            foreach ($activityEvidence['scenario_results'] as &$scenario) {
                if (! in_array($scenario['scenario_id'] ?? '', [
                    'workflow_embedded_activity_result',
                    'standalone_activity_result',
                ], true)) {
                    continue;
                }

                foreach ($scenario['observed_outputs']['activity_host_evidence']['activity_cells'] as &$cell) {
                    if (($cell['runtime'] ?? null) !== 'sdk-python') {
                        continue;
                    }

                    unset($cell['worker_artifact']);
                    $cell['worker_protocol']['registered_runtime'] = 'php';
                }
                unset($cell);
                $scenario['scenario_evidence']['activity_host_evidence'] = $scenario['observed_outputs']['activity_host_evidence'];
            }
            unset($scenario);

            file_put_contents(
                $resultDir.'/artifact-install-evidence.json',
                json_encode($this->completeRunnerInstallEvidence($version), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            );
            file_put_contents(
                $resultDir.'/activity-evidence.json',
                json_encode($activityEvidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            );

            $env = [
                'DW_ACTIVITIES_SKIP_FOCUSED_HOST_PROBE' => '1',
                'DW_SERVER_IMAGE' => $serverImage,
                'DW_SERVER_VERSION' => $version,
                'DW_CLI_VERSION' => $version,
                'DW_PYTHON_SDK_VERSION' => $version,
                'DW_WORKFLOW_PHP_VERSION' => $version,
                'DW_WATERLINE_VERSION' => $version,
            ];
            $envPrefix = implode(' ', array_map(
                static fn (string $name, string $value): string => $name.'='.escapeshellarg($value),
                array_keys($env),
                array_values($env),
            ));
            $command = sprintf(
                '%s bash %s --result-dir %s >/dev/null 2>&1',
                $envPrefix,
                escapeshellarg($repoRoot.'/scripts/conformance/activities-published-artifacts.sh'),
                escapeshellarg($resultDir),
            );

            exec($command, $output, $exitCode);
            $this->assertSame(1, $exitCode);

            $result = json_decode(
                file_get_contents($resultDir.'/activities-result.json') ?: '',
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $this->assertSame('non_passing', $result['outcome']);

            $byScenario = [];
            foreach ($result['scenario_results'] ?? [] as $scenario) {
                $byScenario[$scenario['scenario_id']] = $scenario;
            }

            foreach (['workflow_embedded_activity_result', 'standalone_activity_result'] as $scenarioId) {
                $this->assertSame('fail', $byScenario[$scenarioId]['status'] ?? null);
                $failureText = implode(
                    '; ',
                    $byScenario[$scenarioId]['observed_outputs']['activity_host_evidence_failures'] ?? [],
                );
                $this->assertStringContainsString('sdk-python worker_artifact evidence missing', $failureText);
                $this->assertStringContainsString('missing passing', $failureText);
            }
        } finally {
            foreach (glob($resultDir.'/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($resultDir)) {
                rmdir($resultDir);
            }
        }
    }

    public function test_runner_rejects_local_repo_root_vendor_runtime_probe(): void
    {
        if (trim((string) shell_exec('command -v bash 2>/dev/null')) === ''
            || trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
            $this->markTestSkipped('bash and node are required to exercise the activities runner.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $resultDir = $repoRoot.'/storage/framework/activities-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($resultDir, 0777, true));

        try {
            $version = '9.9.9';
            $installEvidence = $this->completeRunnerInstallEvidence($version);
            $activityEvidence = $this->completeRunnerActivityEvidence($version);
            unset($activityEvidence['published_artifact_worker_execution']);
            $activityEvidence['published_server_image_activity_runtime_probe'] = [
                'label' => 'published_server_image_activity_runtime_probe',
                'status' => 'pass',
                'execution_environment' => 'local_php',
                'working_directory' => $repoRoot,
                'command' => 'php '.$repoRoot.'/vendor/bin/phpunit',
                'autoload_path' => $repoRoot.'/vendor/autoload.php',
                'local_product_source_checkouts_used' => true,
                'artifacts' => [
                    [
                        'artifact' => 'server',
                        'version' => $version,
                        'source' => $repoRoot,
                        'status' => 'pass',
                        'local_product_source_checkouts_used' => true,
                    ],
                ],
            ];

            file_put_contents(
                $resultDir.'/artifact-install-evidence.json',
                json_encode($installEvidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            );
            file_put_contents(
                $resultDir.'/activity-evidence.json',
                json_encode($activityEvidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            );

            $env = [
                'DW_SERVER_VERSION' => $version,
                'DW_CLI_VERSION' => $version,
                'DW_PYTHON_SDK_VERSION' => $version,
                'DW_WORKFLOW_PHP_VERSION' => $version,
                'DW_WATERLINE_VERSION' => $version,
            ];
            $envPrefix = implode(' ', array_map(
                static fn (string $name, string $value): string => $name.'='.escapeshellarg($value),
                array_keys($env),
                array_values($env),
            ));
            $command = sprintf(
                '%s bash %s --result-dir %s >/dev/null 2>&1',
                $envPrefix,
                escapeshellarg($repoRoot.'/scripts/conformance/activities-published-artifacts.sh'),
                escapeshellarg($resultDir),
            );

            exec($command, $output, $exitCode);
            $this->assertSame(1, $exitCode);

            $result = json_decode(
                file_get_contents($resultDir.'/activities-result.json') ?: '',
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $record = json_decode(
                file_get_contents($resultDir.'/activities-record.json') ?: '',
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $this->assertSame('non_passing', $result['outcome']);
            $this->assertFalse($result['runner_blocked']);
            $this->assertSame('fail', $record['outcome']);
            $this->assertFalse($record['runnerBlocked']);
            $this->assertNotEmpty($result['published_artifact_worker_execution_failures'] ?? []);
            $this->assertStringContainsString(
                'local product source probe',
                implode('; ', $result['published_artifact_worker_execution_failures'] ?? []),
            );

            $byScenario = [];
            foreach ($result['scenario_results'] ?? [] as $scenario) {
                $byScenario[$scenario['scenario_id']] = $scenario;
            }

            $scenario = $byScenario['workflow_embedded_activity_result'] ?? [];
            $this->assertSame('not_covered', $scenario['status'] ?? null);
            $this->assertSame('coverage-gap', $scenario['classification'] ?? null);
            $this->assertNotEmpty($scenario['linked_findings'] ?? []);
            $this->assertStringContainsString(
                'pinned published server artifact',
                $scenario['linked_findings'][0]['observed_behavior'] ?? '',
            );
        } finally {
            foreach (glob($resultDir.'/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($resultDir)) {
                rmdir($resultDir);
            }
        }
    }

    public function test_runner_does_not_derive_published_execution_from_workspace_checkout_even_with_runner_source(): void
    {
        if (trim((string) shell_exec('command -v bash 2>/dev/null')) === ''
            || trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
            $this->markTestSkipped('bash and node are required to exercise the activities runner.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $resultDir = $repoRoot.'/storage/framework/activities-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($resultDir, 0777, true));

        try {
            $version = '9.9.9';
            $serverImage = 'durableworkflow/server:'.$version;
            $activityEvidence = $this->completeRunnerActivityEvidence($version, $serverImage);
            unset($activityEvidence['published_artifact_worker_execution']);

            file_put_contents(
                $resultDir.'/artifact-install-evidence.json',
                json_encode($this->completeRunnerInstallEvidence($version), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            );
            file_put_contents(
                $resultDir.'/activity-evidence.json',
                json_encode($activityEvidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            );

            $env = [
                'DW_SERVER_IMAGE' => $serverImage,
                'DW_SERVER_VERSION' => $version,
                'DW_CLI_VERSION' => $version,
                'DW_PYTHON_SDK_VERSION' => $version,
                'DW_WORKFLOW_PHP_VERSION' => $version,
                'DW_WATERLINE_VERSION' => $version,
                'DW_ACTIVITIES_RUNNER_SOURCE' => $serverImage,
            ];
            $envPrefix = implode(' ', array_map(
                static fn (string $name, string $value): string => $name.'='.escapeshellarg($value),
                array_keys($env),
                array_values($env),
            ));
            $command = sprintf(
                '%s bash %s --result-dir %s >/dev/null 2>&1',
                $envPrefix,
                escapeshellarg($repoRoot.'/scripts/conformance/activities-published-artifacts.sh'),
                escapeshellarg($resultDir),
            );

            exec($command, $output, $exitCode);
            $this->assertSame(1, $exitCode);

            $result = json_decode(
                file_get_contents($resultDir.'/activities-result.json') ?: '',
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $this->assertSame('non_passing', $result['outcome']);
            $this->assertNull($result['published_artifact_worker_execution']);
            $this->assertFalse($result['published_artifact_worker_execution_derived']);
            $this->assertSame('missing', $result['published_artifact_worker_execution_source']);
            $this->assertStringContainsString(
                'published server image root',
                $result['published_artifact_worker_execution_derivation_reason'] ?? '',
            );
            $this->assertContains(
                'published_artifact_worker_execution missing',
                $result['published_artifact_worker_execution_failures'] ?? [],
            );

            $byScenario = [];
            foreach ($result['scenario_results'] ?? [] as $scenario) {
                $byScenario[$scenario['scenario_id']] = $scenario;
            }

            $this->assertSame('pass', $byScenario['published_artifact_install_only']['status'] ?? null);
            foreach (ActivityRuntimeContract::manifest()['required_scenarios'] as $scenarioId) {
                if ($scenarioId === 'published_artifact_install_only') {
                    continue;
                }

                $this->assertSame('not_covered', $byScenario[$scenarioId]['status'] ?? null);
                $this->assertSame('coverage-gap', $byScenario[$scenarioId]['classification'] ?? null);
                $this->assertNotEmpty($byScenario[$scenarioId]['linked_findings'] ?? []);
            }
        } finally {
            foreach (glob($resultDir.'/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($resultDir)) {
                rmdir($resultDir);
            }
        }
    }

    public function test_runner_rejects_unofficial_cli_install_source_when_behavior_evidence_passes(): void
    {
        if (trim((string) shell_exec('command -v bash 2>/dev/null')) === ''
            || trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
            $this->markTestSkipped('bash and node are required to exercise the activities runner.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $resultDir = $repoRoot.'/storage/framework/activities-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($resultDir, 0777, true));

        try {
            $version = '9.9.9';
            $unofficialCliSource = 'https://github.com/not-durable-workflow/cli/releases/download/v'.$version.'/install.sh';
            $installEvidence = $this->completeRunnerInstallEvidence($version);
            $installEvidence['artifacts'][1]['source'] = $unofficialCliSource;

            file_put_contents(
                $resultDir.'/artifact-install-evidence.json',
                json_encode($installEvidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            );
            file_put_contents(
                $resultDir.'/activity-evidence.json',
                json_encode($this->completeRunnerActivityEvidence($version), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            );

            $env = [
                'DW_SERVER_VERSION' => $version,
                'DW_CLI_VERSION' => $version,
                'DW_PYTHON_SDK_VERSION' => $version,
                'DW_WORKFLOW_PHP_VERSION' => $version,
                'DW_WATERLINE_VERSION' => $version,
            ];
            $envPrefix = implode(' ', array_map(
                static fn (string $name, string $value): string => $name.'='.escapeshellarg($value),
                array_keys($env),
                array_values($env),
            ));
            $command = sprintf(
                '%s bash %s --result-dir %s >/dev/null 2>&1',
                $envPrefix,
                escapeshellarg($repoRoot.'/scripts/conformance/activities-published-artifacts.sh'),
                escapeshellarg($resultDir),
            );

            exec($command, $output, $exitCode);
            $this->assertSame(1, $exitCode);

            $result = json_decode(
                file_get_contents($resultDir.'/activities-result.json') ?: '',
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $record = json_decode(
                file_get_contents($resultDir.'/activities-record.json') ?: '',
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $this->assertSame('non_passing', $result['outcome']);
            $this->assertFalse($result['runner_blocked']);
            $this->assertSame('fail', $record['outcome']);
            $this->assertFalse($record['runnerBlocked']);
            $this->assertSame($unofficialCliSource, $result['artifact_sources']['cli']);
            $this->assertContains(
                'cli.source='.$unofficialCliSource,
                $result['published_artifact_install']['install_failures'] ?? [],
            );

            $byScenario = [];
            foreach ($result['scenario_results'] ?? [] as $scenario) {
                $byScenario[$scenario['scenario_id']] = $scenario;
            }

            $this->assertSame('not_covered', $byScenario['published_artifact_install_only']['status'] ?? null);
            $this->assertContains(
                'cli.source='.$unofficialCliSource,
                $byScenario['published_artifact_install_only']['observed_outputs']['artifact_install_failures'] ?? [],
            );
            $this->assertNotEmpty($byScenario['published_artifact_install_only']['linked_findings'] ?? []);

            $evaluation = ActivityRuntimeResultGate::evaluate($result, ActivityRuntimeContract::manifest());
            $this->assertSame('non_passing', $evaluation['status']);
            $this->assertContains(
                'unrecognized_published_artifact_install_source',
                array_column($evaluation['gate_failures'], 'code'),
            );
            $this->assertContains('cli', array_column($evaluation['gate_failures'], 'artifact'));
        } finally {
            foreach (glob($resultDir.'/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($resultDir)) {
                rmdir($resultDir);
            }
        }
    }

    public function test_result_gate_accepts_full_activity_product_evidence(): void
    {
        $evaluation = ActivityRuntimeResultGate::evaluate(
            $this->completeActivityResult(),
            ActivityRuntimeContract::manifest(),
        );

        $this->assertSame('pass', $evaluation['status']);
        $this->assertSame([], $evaluation['gate_failures']);
    }

    public function test_result_gate_requires_focused_activity_host_evidence_for_activity_result_cells(): void
    {
        $result = $this->completeActivityResult();
        foreach ($result['scenario_results'] as &$scenario) {
            if (($scenario['scenario_id'] ?? '') !== 'workflow_embedded_activity_result') {
                continue;
            }
            unset($scenario['observed_outputs']['activity_host_evidence']);
            unset($scenario['scenario_evidence']['activity_host_evidence']);
        }
        unset($scenario);

        $evaluation = ActivityRuntimeResultGate::evaluate($result, ActivityRuntimeContract::manifest());

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'activity_host_evidence_missing',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_rejects_local_activity_host_evidence_for_focused_cells(): void
    {
        $result = $this->completeActivityResult();
        foreach ($result['scenario_results'] as &$scenario) {
            if (($scenario['scenario_id'] ?? '') !== 'standalone_activity_result') {
                continue;
            }
            $scenario['observed_outputs']['activity_host_evidence']['execution_source'] = 'local_checkout';
            $scenario['observed_outputs']['activity_host_evidence']['local_product_source_checkouts_used'] = true;
            $scenario['observed_outputs']['activity_host_evidence']['activity_cells'][0]['execution_source'] = 'local_checkout';
            $scenario['scenario_evidence']['activity_host_evidence'] = $scenario['observed_outputs']['activity_host_evidence'];
        }
        unset($scenario);

        $evaluation = ActivityRuntimeResultGate::evaluate($result, ActivityRuntimeContract::manifest());

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'activity_host_evidence_not_from_published_server_container',
            array_column($evaluation['gate_failures'], 'code'),
        );
        $this->assertContains(
            'local_product_source_checkouts_used_must_be_false',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_requires_explicit_source_free_activity_host_marker_for_focused_cells(): void
    {
        $result = $this->completeActivityResult();
        foreach ($result['scenario_results'] as &$scenario) {
            if (($scenario['scenario_id'] ?? '') !== 'workflow_embedded_activity_result') {
                continue;
            }

            unset($scenario['observed_outputs']['activity_host_evidence']['local_product_source_checkouts_used']);
            unset($scenario['scenario_evidence']['activity_host_evidence']['local_product_source_checkouts_used']);
        }
        unset($scenario);

        $evaluation = ActivityRuntimeResultGate::evaluate($result, ActivityRuntimeContract::manifest());

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'activity_host_evidence.local_product_source_checkouts_used',
            array_column($evaluation['gate_failures'], 'field'),
        );
        $this->assertContains(
            'local_product_source_checkouts_used_must_be_false',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_rejects_sdk_python_activity_cell_without_published_sdk_artifact(): void
    {
        $result = $this->completeActivityResult();
        foreach ($result['scenario_results'] as &$scenario) {
            if (($scenario['scenario_id'] ?? '') !== 'workflow_embedded_activity_result') {
                continue;
            }
            foreach ($scenario['observed_outputs']['activity_host_evidence']['activity_cells'] as &$cell) {
                if (($cell['runtime'] ?? null) !== 'sdk-python') {
                    continue;
                }

                unset($cell['worker_artifact']);
                $cell['worker_protocol']['registered_runtime'] = 'php';
            }
            unset($cell);
            $scenario['scenario_evidence']['activity_host_evidence'] = $scenario['observed_outputs']['activity_host_evidence'];
        }
        unset($scenario);

        $evaluation = ActivityRuntimeResultGate::evaluate($result, ActivityRuntimeContract::manifest());

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'sdk_python_activity_worker_artifact_missing',
            array_column($evaluation['gate_failures'], 'code'),
        );
        $this->assertContains(
            'activity_host_evidence_missing_activity_cell',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_requires_source_free_statement_for_published_execution_evidence(): void
    {
        $result = $this->completeActivityResult();
        unset($result['published_artifact_worker_execution']['source_integrity_statement']);
        foreach ($result['published_artifact_worker_execution']['artifacts'] as &$artifact) {
            unset($artifact['source_integrity_statement']);
        }
        unset($artifact);
        foreach ($result['scenario_results'] as &$scenario) {
            unset($scenario['observed_outputs']['published_artifact_worker_execution']['source_integrity_statement']);
            unset($scenario['scenario_evidence']['published_artifact_worker_execution']['source_integrity_statement']);
        }
        unset($scenario);

        $evaluation = ActivityRuntimeResultGate::evaluate($result, ActivityRuntimeContract::manifest());

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'missing_published_artifact_worker_execution_source_integrity_statement',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_rejects_local_runtime_probe_as_pass_evidence(): void
    {
        $result = $this->completeActivityResult();
        $localProbe = [
            'label' => 'published_server_image_activity_runtime_probe',
            'status' => 'pass',
            'execution_environment' => 'local_php',
            'working_directory' => dirname(__DIR__, 2),
            'command' => 'php REPO_ROOT/vendor/bin/phpunit',
            'autoload_path' => 'REPO_ROOT/vendor/autoload.php',
            'local_product_source_checkouts_used' => true,
            'artifacts' => [
                [
                    'artifact' => 'server',
                    'version' => '9.9.9',
                    'source' => dirname(__DIR__, 2),
                    'status' => 'pass',
                    'local_product_source_checkouts_used' => true,
                ],
            ],
        ];

        foreach ($result['scenario_results'] as &$scenario) {
            if (($scenario['scenario_id'] ?? '') === 'published_artifact_install_only') {
                continue;
            }
            $scenario['observed_outputs']['published_artifact_worker_execution'] = $localProbe;
            $scenario['scenario_evidence']['published_artifact_worker_execution'] = $localProbe;
        }
        unset($scenario);
        $result['published_artifact_worker_execution'] = $localProbe;

        $evaluation = ActivityRuntimeResultGate::evaluate($result, ActivityRuntimeContract::manifest());

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'local_product_source_checkouts_used_must_be_false',
            array_column($evaluation['gate_failures'], 'code'),
        );
        $this->assertContains(
            'forbidden_published_artifact_worker_execution_source',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_requires_explicit_runner_blocked_false_for_product_evidence(): void
    {
        $result = $this->completeActivityResult();
        unset($result['runner_blocked']);

        $evaluation = ActivityRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('runner_blocked', $this->missingRunRecordFields($evaluation));
        $this->assertContains(
            'runner_blocked_result_is_not_product_evidence',
            array_column($evaluation['gate_failures'], 'code'),
        );

        $result = $this->completeActivityResult();
        $result['runner_blocked'] = 'false';

        $evaluation = ActivityRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('runner_blocked', $this->missingRunRecordFields($evaluation));
        $this->assertContains(
            'runner_blocked_result_is_not_product_evidence',
            array_column($evaluation['gate_failures'], 'code'),
        );

        $result = $this->completeActivityResult();
        $result['runner_blocked'] = true;

        $evaluation = ActivityRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'runner_blocked_result_is_not_product_evidence',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_requires_every_activity_install_channel_source(): void
    {
        $result = $this->completeActivityResult();
        unset($result['artifact_sources']['workflow']);

        $evaluation = ActivityRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'missing_published_artifact_install_source',
            array_column($evaluation['gate_failures'], 'code'),
        );
        $this->assertContains('workflow-php', array_column($evaluation['gate_failures'], 'artifact'));
    }

    public function test_result_gate_rejects_unrecognized_activity_install_sources(): void
    {
        $result = $this->completeActivityResult();
        $result['artifact_sources']['cli'] = 'https://github.com/not-durable-workflow/cli/releases/download/v9.9.9/install.sh';

        $evaluation = ActivityRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'unrecognized_published_artifact_install_source',
            array_column($evaluation['gate_failures'], 'code'),
        );
        $this->assertContains('cli', array_column($evaluation['gate_failures'], 'artifact'));
    }

    public function test_result_gate_rejects_local_activity_install_sources(): void
    {
        $result = $this->completeActivityResult();
        $result['artifact_sources']['server'] = '/workspace/repos/server';

        $evaluation = ActivityRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'forbidden_artifact_source',
            array_column($evaluation['gate_failures'], 'code'),
        );
        $this->assertContains('server', array_column($evaluation['gate_failures'], 'artifact'));
    }

    public function test_result_gate_rejects_generic_activity_source_labels(): void
    {
        $result = $this->completeActivityResult();
        $result['artifact_sources']['cli'] = 'github_release';
        $result['artifact_sources']['sdk-python'] = 'pypi';
        $result['artifact_sources']['workflow'] = 'packagist';

        $evaluation = ActivityRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertSame(
            ['cli', 'sdk-python', 'workflow-php'],
            array_values(array_intersect(
                ['cli', 'sdk-python', 'workflow-php'],
                array_column($evaluation['gate_failures'], 'artifact'),
            )),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function completeRunnerInstallEvidence(string $version): array
    {
        return [
            'schema' => 'durable-workflow.v2.activity-runtime.artifact-install-evidence',
            'local_product_source_checkouts_used' => false,
            'artifacts' => [
                [
                    'artifact' => 'server',
                    'version' => $version,
                    'source' => 'durableworkflow/server:'.$version,
                    'status' => 'pass',
                    'local_product_source_checkouts_used' => false,
                ],
                [
                    'artifact' => 'cli',
                    'version' => $version,
                    'source' => 'https://github.com/durable-workflow/cli/releases/download/v'.$version.'/install.sh',
                    'status' => 'pass',
                    'local_product_source_checkouts_used' => false,
                ],
                [
                    'artifact' => 'sdk-python',
                    'version' => $version,
                    'source' => 'https://pypi.org/project/durable-workflow/'.$version.'/',
                    'status' => 'pass',
                    'local_product_source_checkouts_used' => false,
                ],
                [
                    'artifact' => 'workflow-php',
                    'version' => $version,
                    'source' => 'https://packagist.org/packages/durable-workflow/workflow#'.$version,
                    'status' => 'pass',
                    'local_product_source_checkouts_used' => false,
                ],
                [
                    'artifact' => 'waterline',
                    'version' => $version,
                    'source' => 'https://packagist.org/packages/durable-workflow/waterline#'.$version,
                    'status' => 'pass',
                    'local_product_source_checkouts_used' => false,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function completeRunnerActivityEvidence(string $version = '9.9.9', ?string $serverImage = null): array
    {
        $scenarioResults = [];
        foreach (ActivityRuntimeContract::manifest()['required_scenarios'] as $scenarioId) {
            $activityHostEvidence = $this->activityHostEvidenceForScenario($scenarioId);
            $scenarioResults[] = [
                'scenario_id' => $scenarioId,
                'status' => 'pass',
                'observed_outputs' => array_filter([
                    'evidence' => $scenarioId,
                    'activity_host_evidence' => $activityHostEvidence,
                ]),
                'scenario_evidence' => array_filter([
                    'evidence' => $scenarioId,
                    'activity_host_evidence' => $activityHostEvidence,
                ]),
            ];
        }

        return [
            'schema' => 'durable-workflow.v2.activity-runtime.host-evidence',
            'execution_source' => 'published_server_container',
            'scenario_results' => $scenarioResults,
            'published_artifact_worker_execution' => $this->publishedServerExecutionEvidence(
                $version,
                $serverImage ?? 'durableworkflow/server:'.$version,
            ),
            'published_artifact_install' => [
                'status' => 'pass',
            ],
            'runtime_matrix' => [
                'execution_modes' => ['workflow-embedded', 'standalone'],
                'runtimes' => ['workflow-php', 'sdk-python'],
            ],
            'durable_result_recording' => ['status' => 'pass'],
            'retry_backoff' => ['status' => 'pass'],
            'timeout_behavior' => ['status' => 'pass'],
            'typed_failure_propagation' => ['status' => 'pass'],
            'heartbeat_cancellation' => ['status' => 'pass'],
            'idempotent_completion' => ['status' => 'pass'],
            'operator_visibility' => ['status' => 'pass'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function completeActivityResult(): array
    {
        $contract = ActivityRuntimeContract::manifest();
        $artifactVersions = [
            'server' => '9.9.9',
            'cli' => '9.9.9',
            'sdk-python' => '9.9.9',
            'workflow' => '9.9.9',
            'waterline' => '9.9.9',
        ];
        $publishedServerExecution = $this->publishedServerExecutionEvidence(
            $artifactVersions['server'],
            'docker.io/durableworkflow/server:'.$artifactVersions['server'],
        );
        $scenarioResults = [];
        foreach ($contract['required_scenarios'] as $scenarioId) {
            $activityHostEvidence = $this->activityHostEvidenceForScenario($scenarioId);
            $scenarioResults[] = [
                'scenario_id' => $scenarioId,
                'status' => 'pass',
                'observed_outputs' => array_filter([
                    'sample' => $scenarioId,
                    'published_artifact_worker_execution' => $publishedServerExecution,
                    'activity_host_evidence' => $activityHostEvidence,
                ]),
                'scenario_evidence' => array_filter([
                    'sample' => $scenarioId,
                    'published_artifact_worker_execution' => $publishedServerExecution,
                    'activity_host_evidence' => $activityHostEvidence,
                ]),
            ];
        }

        return [
            'outcome' => 'pass',
            'runner_blocked' => false,
            'started_at' => '2026-06-21T00:00:00Z',
            'finished_at' => '2026-06-21T00:00:10Z',
            'generated_at' => '2026-06-21T00:00:10Z',
            'artifact_versions' => $artifactVersions,
            'published_artifact_versions' => $artifactVersions,
            'execution_source' => 'published_server_container',
            'artifact_sources' => [
                'server' => 'docker.io/durableworkflow/server:9.9.9',
                'cli' => 'https://github.com/durable-workflow/cli/releases/download/v9.9.9/install.sh',
                'sdk-python' => 'https://pypi.org/project/durable-workflow/9.9.9/',
                'workflow' => 'https://packagist.org/packages/durable-workflow/workflow#9.9.9',
                'waterline' => 'https://packagist.org/packages/durable-workflow/waterline#9.9.9',
            ],
            'scenario_results' => $scenarioResults,
            'published_artifact_worker_execution' => $publishedServerExecution,
            'findings' => [],
            'finding_links' => [],
            'topology' => [
                'task_queue' => 'activities-shared',
            ],
            'runtime_matrix' => [
                'execution_modes' => ['workflow-embedded', 'standalone'],
                'runtimes' => ['workflow-php', 'sdk-python'],
            ],
            'published_artifact_install' => ['status' => 'pass'],
            'durable_result_recording' => ['status' => 'pass'],
            'retry_backoff' => ['status' => 'pass'],
            'timeout_behavior' => ['status' => 'pass'],
            'typed_failure_propagation' => ['status' => 'pass'],
            'heartbeat_cancellation' => ['status' => 'pass'],
            'idempotent_completion' => ['status' => 'pass'],
            'operator_visibility' => ['status' => 'pass'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function activityHostEvidenceForScenario(string $scenarioId): ?array
    {
        $mode = match ($scenarioId) {
            'workflow_embedded_activity_result' => 'workflow-embedded',
            'standalone_activity_result' => 'standalone',
            default => null,
        };

        if ($mode === null) {
            return null;
        }

        return [
            'schema' => 'durable-workflow.v2.activity-runtime.published-artifact-host-evidence',
            'status' => 'pass',
            'scenario_id' => $scenarioId,
            'execution_source' => 'published_server_container',
            'local_product_source_checkouts_used' => false,
            'activity_cells' => [
                [
                    'mode' => $mode,
                    'runtime' => 'workflow-php',
                    'status' => 'pass',
                    'execution_source' => 'published_server_container',
                    'local_product_source_checkouts_used' => false,
                ],
                [
                    'mode' => $mode,
                    'runtime' => 'sdk-python',
                    'status' => 'pass',
                    'execution_source' => 'published_server_container',
                    'local_product_source_checkouts_used' => false,
                    'worker_protocol' => [
                        'registered_runtime' => 'python',
                    ],
                    'worker_artifact' => [
                        'artifact' => 'sdk-python',
                        'package' => 'durable-workflow',
                        'version' => '9.9.9',
                        'source' => 'pypi://durable-workflow==9.9.9',
                        'status' => 'pass',
                        'runtime' => 'sdk-python',
                        'language' => 'python',
                        'execution_source' => 'published_server_container',
                        'execution_method' => 'durable_workflow.serializer.envelope',
                        'local_product_source_checkouts_used' => false,
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function publishedServerExecutionEvidence(string $version, string $serverImage): array
    {
        return [
            'schema' => 'durable-workflow.v2.activity-runtime.published-server-execution',
            'status' => 'pass',
            'execution_source' => 'published_server_container',
            'execution_environment' => 'docker_container',
            'worker_execution_mode' => 'published_server_image_conformance_handoff',
            'executed_in_pinned_server_artifact' => true,
            'local_product_source_checkouts_used' => false,
            'source_integrity_statement' => 'Activities conformance ran from the pinned published server container; local product checkouts, branch source, and local vendor trees were not used as pass evidence.',
            'image_identity' => [
                'pinned_server_image' => $serverImage,
                'runner_source' => $serverImage,
                'matches_pinned_server_image' => true,
            ],
            'artifacts' => [
                [
                    'artifact' => 'server',
                    'version' => $version,
                    'source' => $serverImage,
                    'status' => 'pass',
                    'execution_source' => 'published_server_container',
                    'execution_context' => 'published_server_image_conformance_handoff',
                    'local_product_source_checkouts_used' => false,
                    'source_integrity_statement' => 'Activities conformance ran from the pinned published server container; local product checkouts, branch source, and local vendor trees were not used as pass evidence.',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $evaluation
     *
     * @return list<string>
     */
    private function missingRunRecordFields(array $evaluation): array
    {
        return array_values(array_map(
            static fn (array $failure): string => (string) ($failure['field'] ?? ''),
            array_filter(
                $evaluation['gate_failures'],
                static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_run_record_field',
            ),
        ));
    }

    private function read(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2).'/'.$path);
        $this->assertIsString($contents, "Unable to read {$path}");

        return $contents;
    }
}
