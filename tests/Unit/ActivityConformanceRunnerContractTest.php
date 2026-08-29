<?php

namespace Tests\Unit;

use App\Support\ActivityRuntimeContract;
use App\Support\ActivityRuntimeResultGate;
use PHPUnit\Framework\TestCase;

class ActivityConformanceRunnerContractTest extends TestCase
{
    public function test_extracted_runner_hands_execution_back_to_the_exact_published_server_image(): void
    {
        if (trim((string) shell_exec('command -v bash 2>/dev/null')) === '') {
            $this->markTestSkipped('bash is required to exercise the activities container handoff.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $workspace = sys_get_temp_dir().'/dw-activities-handoff-'.bin2hex(random_bytes(6));
        $runnerDir = $workspace.'/published-server/scripts/conformance';
        $resultDir = $workspace.'/result';
        $binDir = $workspace.'/bin';
        $dockerLog = $workspace.'/docker.log';
        mkdir($runnerDir, 0777, true);
        mkdir($resultDir, 0777, true);
        mkdir($binDir, 0777, true);
        copy($repoRoot.'/scripts/conformance/activities-published-artifacts.sh', $runnerDir.'/activities-published-artifacts.sh');
        file_put_contents(
            $binDir.'/docker',
            "#!/usr/bin/env bash\nprintf '%s\\n' \"\$@\" > \"\$FAKE_DOCKER_LOG\"\n",
        );
        chmod($binDir.'/docker', 0755);

        try {
            $digest = str_repeat('a', 64);
            $serverImage = 'docker.io/durableworkflow/server@sha256:'.$digest;
            $command = implode(' ', [
                'PATH='.escapeshellarg($binDir.':'.getenv('PATH')),
                'FAKE_DOCKER_LOG='.escapeshellarg($dockerLog),
                'DW_SERVER_IMAGE='.escapeshellarg($serverImage),
                'DW_SERVER_VERSION=9.9.9',
                'DW_CLI_VERSION=9.9.9',
                'DW_PHP_SDK_VERSION=9.9.9',
                'DW_PYTHON_SDK_VERSION=9.9.9',
                'DW_WORKFLOW_PHP_VERSION=9.9.9',
                'DW_WATERLINE_VERSION=9.9.9',
                'bash',
                escapeshellarg($runnerDir.'/activities-published-artifacts.sh'),
                '--result-dir',
                escapeshellarg($resultDir),
            ]);
            exec($command.' 2>&1', $output, $exitCode);

            $this->assertSame(0, $exitCode, implode("\n", $output));
            $arguments = file($dockerLog, FILE_IGNORE_NEW_LINES);
            $this->assertIsArray($arguments);
            $this->assertContains('--entrypoint', $arguments);
            $this->assertContains('bash', $arguments);
            $this->assertContains('--user', $arguments);
            $this->assertContains(
                trim((string) shell_exec('id -u')).':'.trim((string) shell_exec('id -g')),
                $arguments,
            );
            $this->assertContains($resultDir.':/result', $arguments);
            $this->assertContains('DW_ACTIVITIES_CONTAINER_HANDOFF=1', $arguments);
            $this->assertContains('DW_ACTIVITIES_CONTAINER_REMOVED_ON_EXIT=1', $arguments);
            $this->assertContains('DW_ACTIVITIES_RUNNER_SOURCE='.$serverImage, $arguments);
            $this->assertContains('DW_PHP_SDK_VERSION=9.9.9', $arguments);
            $this->assertContains($serverImage, $arguments);
            $this->assertContains('/app/scripts/conformance/activities-published-artifacts.sh', $arguments);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

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
        $this->assertStringContainsString('LARAVEL_STORAGE_PATH', $source);
        $this->assertStringContainsString('VIEW_COMPILED_PATH', $source);
        $this->assertStringContainsString('ActivitiesConformanceWorkerRegistration::payload(', $source);

        foreach ([
            'DW_SERVER_VERSION',
            'DW_CLI_VERSION',
            'DW_PHP_SDK_VERSION',
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
            'run_typed_failure_propagation_cell',
            'scenario_from_typed_failure_cell',
            'run_heartbeat_cancellation_cell',
            'scenario_from_heartbeat_cancellation_cell',
            'run_heartbeat_timeout_renewal_cell',
            'scenario_from_heartbeat_timeout_renewal_cell',
            'PublishedPhpSdkWorker',
            'PublishedServerKernelSdkTransport',
            'durable-workflow/sdk',
            'heartbeat_acknowledgements',
            'authoritative_deadline_at',
            'deadline_advanced',
            'enforcement_passes',
            'no_deadline_expired',
            'negative_control',
            'late_heartbeat_response',
            'late_completion_conflict',
            'late_failure_conflict',
            'isolated_cleanup',
            'run_idempotent_completion_cell',
            'scenario_from_idempotent_completion_cell',
            'run_php_python_parity_cell',
            'scenario_from_php_python_parity_cell',
            'run_operator_visibility_cell',
            'scenario_from_operator_visibility_cell',
            'retry_task_not_ready_before_backoff_elapsed',
            'start_to_close_timeout_seconds',
            'ActivityTimedOut',
            'ActivityFailed',
            'ActivityHeartbeatRecorded',
            'enforcement_observed_at',
            'caller_visible_outcome',
            'history_exception',
            'caller_observed_failure',
            'heartbeat_details',
            'cancel_requested_response',
            'worker_observed_cancellation',
            'late_completion_after_cancel_response',
            'terminal_cancellation_state',
            'first_completion_response',
            'duplicate_completion_response',
            'stale_attempt_or_idempotent_verdict',
            'php_activity_result',
            'python_activity_result',
            'cross_language_payload_shape',
            'cross_language_failure_shape',
            'cross_language_retry_shape',
            'cross_language_timeout_shape',
            'cross_language_heartbeat_shape',
            'cross_language_cancellation_shape',
            'parity_observations',
            'failure_observations',
            'retry_observations',
            'timeout_observations',
            'cancellation_observations',
            'operator_state_matrix',
            'missing_operator_surface_reasons',
            'cli_json_list_evidence',
            'run_dw_json_command',
            'official published dw CLI JSON command output',
            'dw activity:list --output=json --limit=200',
            'cli_activity_attempt_state_visibility',
            'in_flight',
            'retrying',
            'timed_out',
            'cancelled',
            'Waterline\\Support\\CompensationVisibility',
            'CompensationVisibility::activitiesForRun',
            'distribution-execution-observation',
            'record_executed_php_activity_distributions',
            'waterline_activity_attempt_view',
            'operator_visible_activity_attempt_state',
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

        $this->assertStringNotContainsString(
            "'json_contract_source' => 'GET /activities and GET /activities/{activity_id}'",
            $source,
        );
        $this->assertStringNotContainsString(
            "'projection_source' => 'Workflow\\\\V2\\\\Support\\\\RunActivityView::activitiesForRun'",
            $source,
        );
    }

    public function test_focused_probe_isolates_scenario_queues_and_rejects_mismatched_poll_identity(): void
    {
        $source = $this->read('scripts/conformance/activities-published-artifacts.sh');

        $this->assertStringNotContainsString('activities-shared', $source);
        $this->assertStringContainsString('function scenario_task_queue(string $identity): string', $source);
        $this->assertStringContainsString('new ActivityOptions(queue: $taskQueue)', $source);
        $this->assertStringContainsString('assert_task_identity($task, $expectedIdentity', $source);

        foreach ([
            'run_embedded_cell',
            'run_standalone_cell',
            'start_parity_activity',
            'start_operator_visibility_activity',
            'run_heartbeat_cancellation_cell',
            'run_idempotent_completion_cell',
            'run_restart_durable_result_cell',
            'run_retry_backoff_cell',
            'run_timeout_behavior_cell',
            'run_heartbeat_timeout_renewal_cell',
            'run_typed_failure_propagation_cell',
        ] as $function) {
            $start = strpos($source, "function {$function}(");
            $this->assertNotFalse($start, "missing {$function}");
            $nextFunction = strpos($source, "\nfunction ", $start + 10);
            $body = substr($source, $start, $nextFunction === false ? null : $nextFunction - $start);
            $this->assertStringContainsString('scenario_task_queue(', $body, "{$function} must select an isolated task queue");
        }
    }

    public function test_operator_stale_queue_fixture_crosses_the_sixty_second_retry_before_timeout_poll(): void
    {
        $source = $this->read('scripts/conformance/activities-published-artifacts.sh');
        $start = strpos($source, 'function run_operator_visibility_cell(): array');
        $end = strpos($source, "\nfunction run_restart_durable_result_cell", $start);
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $body = substr($source, $start, $end - $start);

        $retry = strpos($body, "operator_visibility_state_observation('retrying'");
        $availableAt = strpos($body, 'workflow_task_available_at($retryTaskId)');
        $crossBackoff = strpos($body, 'wait_until_timestamp($retryAvailableTimestamp + 0.10)');
        $timedOut = strpos($body, "operator_visibility_state_observation('timed_out'");

        $this->assertNotFalse($retry);
        $this->assertNotFalse($availableAt);
        $this->assertNotFalse($crossBackoff);
        $this->assertNotFalse($timedOut);
        $this->assertTrue($retry < $availableAt && $availableAt < $crossBackoff && $crossBackoff < $timedOut);
        $this->assertStringContainsString("'configured_backoff_seconds' => 60", $body);
        $this->assertStringContainsString("'isolated_task_queues' =>", $body);
        $this->assertStringContainsString("'timed_out_worker_visible_start_to_close_deadline' =>", $body);
    }

    public function test_heartbeat_negative_control_uses_a_fresh_registered_worker_after_managed_deregistration(): void
    {
        $source = $this->read('scripts/conformance/activities-published-artifacts.sh');
        $start = strpos($source, 'function run_heartbeat_timeout_renewal_cell(): array');
        $end = strpos($source, "\nfunction scenario_from_heartbeat_timeout_renewal_cell", $start);
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $body = substr($source, $start, $end - $start);

        $managedRun = strpos($body, '$worker->run(0)');
        $managedDeregistration = strpos($body, '$managedWorkerDeregistration = $transport->latest(');
        $negativeRegistration = strpos($body, '$negativeWorkerRegistration = $client->registerWorker(');
        $negativePoll = strpos($body, '$negativeTask = $client->pollActivityTask($negativeWorkerId, $negativeTaskQueue, 0)');

        $this->assertNotFalse($managedRun);
        $this->assertNotFalse($managedDeregistration);
        $this->assertNotFalse($negativeRegistration);
        $this->assertNotFalse($negativePoll);
        $this->assertTrue(
            $managedRun < $managedDeregistration
            && $managedDeregistration < $negativeRegistration
            && $negativeRegistration < $negativePoll,
        );
        $this->assertStringContainsString("'activity_execution_id' => \$negativeExecutionId", $body);
        $this->assertStringContainsString('$client->deregisterWorkerRegistration($negativeWorkerId)', $body);
    }

    public function test_runner_claims_waterline_identity_only_after_the_installed_package_executes(): void
    {
        $source = $this->read('scripts/conformance/activities-published-artifacts.sh');
        $prepareStart = strpos($source, 'prepare_published_php_activity_artifacts() {');
        $recordStart = strpos($source, 'record_executed_php_activity_distributions() {');
        $installEvidenceStart = strpos($source, 'write_published_activity_install_evidence() {');

        $this->assertNotFalse($prepareStart);
        $this->assertNotFalse($recordStart);
        $this->assertNotFalse($installEvidenceStart);

        $prepare = substr($source, $prepareStart, $recordStart - $prepareStart);
        $record = substr($source, $recordStart, $installEvidenceStart - $recordStart);
        $this->assertStringNotContainsString('mv "$bundled_workflow"', $prepare);
        $this->assertStringNotContainsString('cp -a "$published_workflow"', $prepare);
        $this->assertStringNotContainsString('"$distribution_identity_file" workflow', $prepare);
        $this->assertStringNotContainsString('"$distribution_identity_file" sdk-php', $prepare);
        $this->assertStringNotContainsString('"$distribution_identity_file" waterline', $prepare);
        $this->assertStringContainsString('DW_ACTIVITIES_WORKFLOW_EXECUTION_OBSERVATION', $record);
        $this->assertStringContainsString('DW_ACTIVITIES_PHP_SDK_EXECUTION_OBSERVATION', $record);
        $this->assertStringContainsString('DW_ACTIVITIES_WATERLINE_EXECUTION_OBSERVATION', $record);
        $this->assertStringContainsString('"$distribution_identity_file" workflow', $record);
        $this->assertStringContainsString('"$distribution_identity_file" sdk-php', $record);
        $this->assertStringContainsString('"$distribution_identity_file" waterline', $record);
        $this->assertStringContainsString('WorkflowFiberRunner::class', $source);
        $this->assertStringContainsString('$activities = CompensationVisibility::activitiesForRun($run);', $source);
        $this->assertStringContainsString('ReflectionClass(CompensationVisibility::class)', $source);
    }

    public function test_manifest_publishes_the_bounded_portable_activity_result_contract(): void
    {
        $portableResult = ActivityRuntimeContract::manifest()['host_runner_contract']['portable_result_contract'];

        $this->assertSame(4 * 1024 * 1024, $portableResult['runner_max_bytes']);
        $this->assertSame(3 * 1024 * 1024, $portableResult['projection_target_bytes']);
        $this->assertSame(4 * 1024 * 1024, $portableResult['host_consumer_max_bytes']);
        $this->assertSame('required_scenarios', $portableResult['required_scenario_status_source']);
        $this->assertSame(
            'executed_distribution_identities',
            $portableResult['exact_distribution_identity_field'],
        );
        $this->assertSame(
            [
                'oversized' => 'runner_infrastructure_failure',
                'malformed' => 'runner_infrastructure_failure',
                'incomplete' => 'runner_infrastructure_failure',
            ],
            $portableResult['native_evidence_failure_classification'],
        );
        $this->assertSame('fail_closed_before_projection', $portableResult['product_assertions']);
        $this->assertSame('omitted', $portableResult['sensitive_values']);

        $publicManifest = json_decode(
            (string) file_get_contents(
                dirname(__DIR__, 2).'/static/platform-conformance/activity-runtime-scenarios.json',
            ),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $publicPortableResult = $publicManifest['host_runner_contract']['portable_result_contract'];
        $this->assertSame($portableResult['required_top_level_fields'], $publicPortableResult['required_top_level_fields']);
        $this->assertSame('scenarios', $publicPortableResult['required_scenario_status_source']);
        $this->assertSame(4 * 1024 * 1024, $publicPortableResult['runner_max_bytes']);
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
                'DW_PHP_SDK_VERSION' => '9.9.9',
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
                        'artifact' => 'sdk-php',
                        'version' => $version,
                        'source' => 'https://packagist.org/packages/durable-workflow/sdk#'.$version,
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
                $observedOutputs = $scenarioId === 'heartbeat_timeout_renewal_across_enforcement_passes'
                    ? $this->heartbeatTimeoutRenewalEvidence($version)
                    : array_filter([
                        'evidence' => $scenarioId,
                        'activity_host_evidence' => $activityHostEvidence,
                    ]) + $this->activityIsolationEvidence($scenarioId);
                $scenarioResults[] = [
                    'scenario_id' => $scenarioId,
                    'status' => 'pass',
                    'observed_outputs' => $observedOutputs,
                    'scenario_evidence' => $observedOutputs,
                ];
            }

            $activityEvidence = [
                'schema' => 'durable-workflow.v2.activity-runtime.host-evidence',
                'execution_source' => 'published_server_container',
                'executed_distribution_identities' => $this->executedDistributionIdentities($version),
                'scenario_results' => $scenarioResults,
                'published_artifact_worker_execution' => $this->publishedServerExecutionEvidence($version, $serverImage),
                'published_artifact_install' => [
                    'status' => 'pass',
                    'server_image' => $serverImage,
                ],
                'runtime_matrix' => [
                    'execution_modes' => ['workflow-embedded', 'standalone'],
                    'runtimes' => ['workflow-php', 'sdk-php', 'sdk-python'],
                ],
                'durable_result_recording' => ['status' => 'pass'],
                'retry_backoff' => ['status' => 'pass'],
                'timeout_behavior' => ['status' => 'pass'],
                'heartbeat_timeout_renewal' => ['status' => 'pass'],
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
                'DW_PHP_SDK_VERSION' => $version,
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

            $this->assertSame('pass', $result['outcome'], json_encode($result, JSON_PRETTY_PRINT));
            $this->assertSame($version, $result['published_artifact_versions']['server']);
            $this->assertSame($serverImage, $result['artifact_sources']['server']);
            $this->assertSame([], $result['published_artifact_install']['pin_failures'] ?? []);
            $this->assertSame([], $result['published_artifact_install']['install_failures'] ?? []);
            $artifactVersionComponents = array_keys($result['artifact_versions']);
            sort($artifactVersionComponents);
            $this->assertSame(['cli', 'sdk-php', 'sdk-python', 'server', 'waterline', 'workflow'], $artifactVersionComponents);
            $this->assertArrayNotHasKey('workflow-php', $result['artifact_versions']);
            $artifactSourceComponents = array_keys($result['artifact_sources']);
            sort($artifactSourceComponents);
            $this->assertSame(['cli', 'sdk-php', 'sdk-python', 'server', 'waterline', 'workflow'], $artifactSourceComponents);
            $this->assertArrayNotHasKey('workflow-php', $result['artifact_sources']);
            $identityComponents = array_keys($result['executed_distribution_identities']);
            sort($identityComponents);
            $this->assertSame(['cli', 'sdk-php', 'sdk-python', 'server', 'waterline', 'workflow'], $identityComponents);
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

    public function test_runner_compacts_repeated_native_activity_evidence_without_omitting_required_cells(): void
    {
        $evidence = $this->completeRunnerActivityEvidence();
        $repeatedPayload = str_repeat('portable-activity-evidence-', 700);
        $repeatedCatalog = array_fill(0, 24, $repeatedPayload);

        foreach ($evidence['scenario_results'] as &$scenario) {
            $scenario['observed_outputs']['repeated_catalog'] = $repeatedCatalog;
            $scenario['observed_outputs']['runner_logs'] = $repeatedCatalog;
            $scenario['scenario_evidence']['repeated_catalog'] = $repeatedCatalog;
            $scenario['scenario_evidence']['runner_logs'] = $repeatedCatalog;
        }
        unset($scenario);
        $evidence['operator_visibility']['repeated_catalog'] = $repeatedCatalog;
        $evidence['runtime_matrix']['runtimes'][] = 'sdk-php';

        $nativeBytes = strlen((string) json_encode($evidence, JSON_THROW_ON_ERROR));
        $this->assertGreaterThan(4 * 1024 * 1024, $nativeBytes);

        $run = $this->runActivityRunnerWithEvidence($evidence);

        $this->assertSame(0, $run['exit'], $run['output']);
        $this->assertSame('pass', $run['result']['outcome']);
        $this->assertFalse($run['result']['runner_blocked']);
        $this->assertLessThanOrEqual(
            4 * 1024 * 1024,
            strlen((string) json_encode(
                $run['result'],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            )."\n"),
        );
        $this->assertSame(
            ActivityRuntimeContract::manifest()['required_scenarios'],
            array_column($run['result']['scenario_results'], 'scenario_id'),
        );
        $this->assertSame(
            $this->executedDistributionIdentities('9.9.9'),
            $run['result']['executed_distribution_identities'],
        );
        $this->assertStringContainsString(
            'sha256',
            (string) json_encode($run['result']['scenario_results'], JSON_THROW_ON_ERROR),
        );
        $evaluation = ActivityRuntimeResultGate::evaluate($run['result'], ActivityRuntimeContract::manifest());
        $this->assertSame('pass', $evaluation['status'], json_encode($evaluation, JSON_PRETTY_PRINT));
    }

    public function test_runner_does_not_turn_a_large_activity_behavior_failure_into_a_pass(): void
    {
        $evidence = $this->completeRunnerActivityEvidence();
        foreach ($evidence['scenario_results'] as &$scenario) {
            if (($scenario['scenario_id'] ?? null) !== 'timeout_behavior') {
                continue;
            }
            $scenario['status'] = 'fail';
            $scenario['classification'] = 'product-gap';
            $scenario['observed_behavior'] = 'the timeout behavior cell failed';
            $scenario['observed_outputs']['runner_logs'] = array_fill(0, 300, str_repeat('failure-log-', 2000));
        }
        unset($scenario);

        $run = $this->runActivityRunnerWithEvidence($evidence);
        $byScenario = array_column($run['result']['scenario_results'], null, 'scenario_id');

        $this->assertSame(1, $run['exit']);
        $this->assertSame('non_passing', $run['result']['outcome']);
        $this->assertSame('fail', $byScenario['timeout_behavior']['status']);
        $this->assertNotEmpty($byScenario['timeout_behavior']['linked_findings'] ?? []);
        $this->assertLessThanOrEqual(
            4 * 1024 * 1024,
            strlen((string) json_encode(
                $run['result'],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            )."\n"),
        );
    }

    public function test_runner_rejects_complete_staged_evidence_without_required_distribution_identity(): void
    {
        $evidence = $this->completeRunnerActivityEvidence();
        unset($evidence['executed_distribution_identities']['waterline']);

        $run = $this->runActivityRunnerWithEvidence($evidence);

        $this->assertSame(1, $run['exit'], $run['output']);
        $this->assertSame('non_passing', $run['result']['outcome']);
        $this->assertStringContainsString(
            'missing executed distribution evidence for: waterline',
            implode("\n", $run['result']['executed_distribution_identity_failures']),
        );
        $this->assertArrayNotHasKey('waterline', $run['result']['executed_distribution_identities']);
    }

    public function test_runner_emits_distribution_artifacts_in_canonical_code_point_order(): void
    {
        $evidence = $this->completeRunnerActivityEvidence();
        $evidence['executed_distribution_identities']['cli']['artifacts'] = [
            ['name' => 'install.sh', 'sha256' => str_repeat('1', 64)],
            ['name' => 'dw_cli_linux_x86_64.tar.gz', 'sha256' => str_repeat('2', 64)],
        ];
        $recorded = [
            'cli' => [
                'kind' => 'github-release',
                'locator' => 'github-release:durable-workflow/cli@9.9.9',
                'artifacts' => [
                    ['name' => 'checksums.txt', 'sha256' => str_repeat('3', 64)],
                    ['name' => 'DW_CLI_Linux_x86_64.tar.gz', 'sha256' => str_repeat('4', 64)],
                ],
            ],
        ];

        $run = $this->runActivityRunnerWithEvidence($evidence, $recorded);

        $this->assertSame(0, $run['exit'], $run['output']);
        $this->assertSame('pass', $run['result']['outcome']);
        $this->assertSame(
            [
                ['name' => 'DW_CLI_Linux_x86_64.tar.gz', 'sha256' => str_repeat('4', 64)],
                ['name' => 'checksums.txt', 'sha256' => str_repeat('3', 64)],
                ['name' => 'dw_cli_linux_x86_64.tar.gz', 'sha256' => str_repeat('2', 64)],
                ['name' => 'install.sh', 'sha256' => str_repeat('1', 64)],
            ],
            $run['result']['executed_distribution_identities']['cli']['artifacts'],
        );
    }

    public function test_runner_rejects_malformed_distribution_artifact_fields_without_normalizing_them(): void
    {
        $cases = [
            'whitespace-padded name' => [
                'field' => 'name',
                'value' => ' install.sh ',
                'failure' => 'executed distribution artifact name for cli is invalid',
            ],
            'non-string name' => [
                'field' => 'name',
                'value' => 123,
                'failure' => 'executed distribution artifact name for cli is invalid',
            ],
            'whitespace-padded digest' => [
                'field' => 'sha256',
                'value' => str_repeat('b', 64).' ',
                'failure' => 'executed distribution SHA-256 for cli:install.sh is invalid',
            ],
        ];

        foreach ($cases as $case => $malformed) {
            $recorded = $this->executedDistributionIdentities('9.9.9');
            $recorded['cli']['artifacts'][0][$malformed['field']] = $malformed['value'];

            $run = $this->runActivityRunnerWithEvidence($this->completeRunnerActivityEvidence(), $recorded);

            $this->assertSame(1, $run['exit'], $case."\n".$run['output']);
            $this->assertSame('non_passing', $run['result']['outcome'], $case);
            $this->assertStringContainsString(
                $malformed['failure'],
                implode("\n", $run['result']['executed_distribution_identity_failures']),
                $case,
            );
        }
    }

    public function test_runner_rejects_conflicting_same_version_distribution_bytes(): void
    {
        $evidence = $this->completeRunnerActivityEvidence();
        $recorded = $this->executedDistributionIdentities('9.9.9');
        $recorded['cli']['artifacts'][0]['sha256'] = str_repeat('f', 64);

        $run = $this->runActivityRunnerWithEvidence($evidence, $recorded);

        $this->assertSame(1, $run['exit'], $run['output']);
        $this->assertSame('non_passing', $run['result']['outcome']);
        $this->assertStringContainsString(
            'conflicting consumed bytes for cli:install.sh',
            implode("\n", $run['result']['executed_distribution_identity_failures']),
        );
        $this->assertSame(
            str_repeat('f', 64),
            $run['result']['executed_distribution_identities']['cli']['artifacts'][0]['sha256'],
        );
    }

    public function test_runner_reports_missing_published_prerequisites_as_an_explicit_runner_failure(): void
    {
        $run = $this->runActivityRunnerWithEvidence(
            $this->completeRunnerActivityEvidence(),
            null,
            ['DW_ACTIVITIES_PREREQUISITE_FAILURE' => 'Composer could not install the exact candidate packages'],
        );

        $this->assertSame(1, $run['exit'], $run['output']);
        $this->assertSame('non_passing_runner_blocked', $run['result']['outcome']);
        $this->assertTrue($run['result']['runner_blocked']);
        $this->assertSame(
            ['runner_blocked'],
            array_values(array_unique(array_column($run['result']['scenario_results'], 'status'))),
        );
        $this->assertStringContainsString(
            'Composer could not install the exact candidate packages',
            $run['result']['scenario_results'][0]['linked_findings'][0]['observed_behavior'] ?? '',
        );
    }

    public function test_runner_blocked_evidence_retains_the_first_host_probe_exception(): void
    {
        $evidence = $this->completeRunnerActivityEvidence();
        $firstException = 'RuntimeException: POST /worker/register failed with HTTP 422: capability_manifest is required';
        $evidence['scenario_results'][0]['observed_outputs']['activity_cells'] = [
            [
                'runtime' => 'workflow-php',
                'status' => 'fail',
                'failure' => $firstException,
            ],
        ];

        $run = $this->runActivityRunnerWithEvidence(
            $evidence,
            null,
            ['DW_ACTIVITIES_PREREQUISITE_FAILURE' => 'Workflow execution observation is missing'],
        );

        $this->assertSame(1, $run['exit'], $run['output']);
        $this->assertSame('non_passing_runner_blocked', $run['result']['outcome']);
        $this->assertSame(
            $firstException,
            $run['result']['runner_blocked_evidence']['first_actionable_host_probe_exception'] ?? null,
        );
        $observedBehavior = $run['result']['scenario_results'][0]['linked_findings'][0]['observed_behavior'] ?? '';
        $this->assertStringContainsString($firstException, $observedBehavior);
        $this->assertStringContainsString('Workflow execution observation is missing', $observedBehavior);
        $this->assertTrue(
            strpos($observedBehavior, $firstException) < strpos($observedBehavior, 'Workflow execution observation is missing'),
        );
    }

    public function test_runner_does_not_remove_externally_supplied_run_root_after_pass(): void
    {
        if (trim((string) shell_exec('command -v bash 2>/dev/null')) === ''
            || trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
            $this->markTestSkipped('bash and node are required to exercise the activities runner.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $workspace = $repoRoot.'/storage/framework/activities-cleanup-'.bin2hex(random_bytes(4));
        $resultDir = $workspace.'/results';
        $runRoot = $workspace.'/run-root';
        $binDir = $workspace.'/bin';
        $fakeRmLog = $workspace.'/rm.log';
        $this->assertTrue(mkdir($resultDir, 0777, true));
        $this->assertTrue(mkdir($runRoot, 0777, true));
        $this->assertTrue(mkdir($binDir, 0777, true));

        try {
            $version = '9.9.9';
            $serverImage = 'durableworkflow/server:'.$version;

            file_put_contents(
                $resultDir.'/artifact-install-evidence.json',
                json_encode($this->completeRunnerInstallEvidence($version), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            );
            file_put_contents(
                $resultDir.'/activity-evidence.json',
                json_encode(
                    $this->completeRunnerActivityEvidence($version, $serverImage),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                )."\n",
            );
            file_put_contents($binDir.'/rm', <<<'SH'
#!/usr/bin/env bash
set -eu
printf '%s\n' "$*" >> "${DW_ACTIVITIES_FAKE_RM_LOG:?}"
for arg in "$@"; do
  if [[ "$arg" == "${DW_ACTIVITIES_PROTECTED_RUN_ROOT:?}" ]]; then
    printf 'fake rm cleanup failure: %s\n' "$*" >&2
    exit 1
  fi
done
exec /bin/rm "$@"
SH);
            chmod($binDir.'/rm', 0755);

            $processEnv = getenv();
            if (! is_array($processEnv)) {
                $processEnv = [];
            }

            $process = proc_open(
                [
                    '/bin/bash',
                    $repoRoot.'/scripts/conformance/activities-published-artifacts.sh',
                    '--result-dir',
                    $resultDir,
                ],
                [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                $repoRoot,
                array_merge($processEnv, [
                    'PATH' => $binDir.PATH_SEPARATOR.(string) getenv('PATH'),
                    'DW_ACTIVITIES_FAKE_RM_LOG' => $fakeRmLog,
                    'DW_ACTIVITIES_PROTECTED_RUN_ROOT' => $runRoot,
                    'DW_ACTIVITIES_RUN_ROOT' => $runRoot,
                    'DW_ACTIVITIES_SKIP_FOCUSED_HOST_PROBE' => '1',
                    'DW_SERVER_IMAGE' => $serverImage,
                    'DW_SERVER_VERSION' => $version,
                    'DW_CLI_VERSION' => $version,
                    'DW_PHP_SDK_VERSION' => $version,
                    'DW_PYTHON_SDK_VERSION' => $version,
                    'DW_WORKFLOW_PHP_VERSION' => $version,
                    'DW_WATERLINE_VERSION' => $version,
                ]),
            );
            $this->assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            $this->assertSame(
                0,
                $exitCode,
                ($stdout === false ? '' : $stdout).($stderr === false ? '' : $stderr),
            );
            $this->assertDirectoryExists($runRoot);
            $this->assertFileDoesNotExist($fakeRmLog);

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

            $this->assertSame('pass', $result['outcome']);
            $this->assertSame('pass', $record['outcome']);
            $this->assertSame('published_server_container', $result['execution_source']);
            $this->assertTrue($result['activity_evidence_supplied']);
            $this->assertFalse($result['local_product_source_checkouts_used']);
        } finally {
            $this->removeDirectory($workspace);
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
                'DW_PHP_SDK_VERSION' => $version,
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
                'DW_PHP_SDK_VERSION' => $version,
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
                'DW_PHP_SDK_VERSION' => $version,
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

    public function test_runner_records_timeout_and_typed_failure_host_evidence_without_passing_full_matrix(): void
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

            $typedFailureObserved = [
                'activity_host_evidence' => [
                    'schema' => 'durable-workflow.v2.activity-runtime.published-artifact-host-evidence',
                    'scenario_id' => 'typed_failure_propagation',
                    'status' => 'pass',
                    'execution_source' => 'published_server_container',
                    'local_product_source_checkouts_used' => false,
                    'activity_cells' => [[
                        'mode' => 'workflow-embedded',
                        'runtime' => 'workflow-php',
                        'status' => 'pass',
                        'execution_source' => 'published_server_container',
                        'activity_execution_id' => 'activity-typed-failure-execution',
                        'activity_attempt_id' => 'activity-typed-failure-attempt',
                        'local_product_source_checkouts_used' => false,
                    ]],
                ],
                'failure_type' => 'ActivitiesConformanceTypedFailure',
                'failure_message' => 'typed activity failure propagated from published artifact worker',
                'failure_details' => [
                    'failure_code' => 'ACTIVITY_TYPED_FAILURE',
                    'stage' => 'typed_failure_propagation',
                    'retry_after_seconds' => 45,
                    'runtime' => 'workflow-php',
                ],
                'history_exception' => [
                    'type' => 'ActivitiesConformanceTypedFailure',
                    'class' => 'DurableWorkflow\\Conformance\\Activities\\TypedActivityFailure',
                    'message' => 'typed activity failure propagated from published artifact worker',
                    'details_payload_codec' => 'avro',
                    'details' => 'encoded-details',
                ],
                'caller_observed_failure' => [
                    'status' => 'caught',
                    'class' => 'Workflow\\V2\\Exceptions\\RestoredWorkflowException',
                    'original_exception_class' => 'DurableWorkflow\\Conformance\\Activities\\TypedActivityFailure',
                    'failure_type' => 'ActivitiesConformanceTypedFailure',
                    'failure_message' => 'typed activity failure propagated from published artifact worker',
                    'failure_details' => [
                        'failure_code' => 'ACTIVITY_TYPED_FAILURE',
                        'stage' => 'typed_failure_propagation',
                        'retry_after_seconds' => 45,
                        'runtime' => 'workflow-php',
                    ],
                ],
                'failure_row' => [
                    'failure_category' => 'activity',
                    'propagation_kind' => 'activity',
                    'exception_class' => 'DurableWorkflow\\Conformance\\Activities\\TypedActivityFailure',
                    'message' => 'typed activity failure propagated from published artifact worker',
                    'non_retryable' => true,
                ],
                'history_events' => [
                    'ActivityFailed',
                    'WorkflowCompleted',
                ],
            ];

            $scenarioResults[] = [
                'scenario_id' => 'typed_failure_propagation',
                'status' => 'pass',
                'observed_outputs' => $typedFailureObserved,
                'scenario_evidence' => [
                    'typed_failure_propagation' => $typedFailureObserved,
                    'activity_host_evidence' => $typedFailureObserved['activity_host_evidence'],
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
                        ['scenario' => 'typed_failure_propagation', 'status' => 'pass'],
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
                'typed_failure_propagation' => [
                    'status' => 'pass',
                    'scenario' => 'typed_failure_propagation',
                    'failure_type' => $typedFailureObserved['failure_type'],
                    'failure_message' => $typedFailureObserved['failure_message'],
                    'failure_details' => $typedFailureObserved['failure_details'],
                    'history_exception' => $typedFailureObserved['history_exception'],
                    'caller_observed_failure' => $typedFailureObserved['caller_observed_failure'],
                    'failure_row' => $typedFailureObserved['failure_row'],
                    'history_events' => $typedFailureObserved['history_events'],
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
                'DW_PHP_SDK_VERSION' => $version,
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
            $this->assertSame('pass', $result['typed_failure_propagation']['status'] ?? null);
            $this->assertSame('ActivitiesConformanceTypedFailure', $result['typed_failure_propagation']['failure_type'] ?? null);
            $this->assertSame(
                'typed activity failure propagated from published artifact worker',
                $result['typed_failure_propagation']['failure_message'] ?? null,
            );
            $this->assertSame(
                'ACTIVITY_TYPED_FAILURE',
                $result['typed_failure_propagation']['failure_details']['failure_code'] ?? null,
            );
            $this->assertSame(
                'ActivitiesConformanceTypedFailure',
                $result['typed_failure_propagation']['caller_observed_failure']['failure_type'] ?? null,
            );

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
            $this->assertSame('pass', $byScenario['typed_failure_propagation']['status'] ?? null);
            $this->assertArrayNotHasKey('linked_findings', $byScenario['typed_failure_propagation']);
            $this->assertSame(
                'ActivitiesConformanceTypedFailure',
                $byScenario['typed_failure_propagation']['observed_outputs']['history_exception']['type'] ?? null,
            );

            $evaluation = ActivityRuntimeResultGate::evaluate($result, ActivityRuntimeContract::manifest());
            $this->assertSame('non_passing', $evaluation['status']);
            $this->assertNotContains('timeout_behavior', $evaluation['non_pass_scenarios']);
            $this->assertNotContains('typed_failure_propagation', $evaluation['non_pass_scenarios']);
            $this->assertContains('heartbeat_and_cancellation_observation', $evaluation['non_pass_scenarios']);
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
                'DW_PHP_SDK_VERSION' => $version,
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
                'DW_PHP_SDK_VERSION' => $version,
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
                'DW_PHP_SDK_VERSION' => $version,
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
                'DW_PHP_SDK_VERSION' => $version,
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
                'DW_PHP_SDK_VERSION' => $version,
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
                'DW_PHP_SDK_VERSION' => $version,
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
                'DW_PHP_SDK_VERSION' => $version,
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

    public function test_runner_rejects_contradictory_heartbeat_timeout_renewal_evidence(): void
    {
        $evidence = $this->completeRunnerActivityEvidence();
        foreach ($evidence['scenario_results'] as &$scenario) {
            if (($scenario['scenario_id'] ?? null) !== 'heartbeat_timeout_renewal_across_enforcement_passes') {
                continue;
            }
            $scenario['observed_outputs']['heartbeat_acknowledgements'][1]['deadline_advanced'] = false;
            $scenario['scenario_evidence'] = $scenario['observed_outputs'];
        }
        unset($scenario);

        $run = $this->runActivityRunnerWithEvidence($evidence);

        $this->assertSame(1, $run['exit'], $run['output']);
        $this->assertSame('non_passing', $run['result']['outcome']);
        $byScenario = [];
        foreach ($run['result']['scenario_results'] as $scenario) {
            $byScenario[$scenario['scenario_id']] = $scenario;
        }
        $this->assertSame(
            'fail',
            $byScenario['heartbeat_timeout_renewal_across_enforcement_passes']['status'] ?? null,
        );
        $this->assertStringContainsString(
            'did_not_advance_deadline',
            implode(
                ' ',
                $byScenario['heartbeat_timeout_renewal_across_enforcement_passes']['observed_outputs']['scenario_contract_failures'] ?? [],
            ),
        );
    }

    public function test_runner_rejects_activity_evidence_without_scenario_isolation_identity(): void
    {
        $cases = [
            [
                'scenario' => 'heartbeat_timeout_renewal_across_enforcement_passes',
                'failure' => 'heartbeat_timeout_renewal_fresh_negative_worker_missing',
                'mutate' => static function (array &$outputs): void {
                    $outputs['negative_control']['worker_id'] = $outputs['worker_id'];
                },
            ],
            [
                'scenario' => 'idempotent_completion_handling',
                'failure' => 'idempotent_completion_task_attempt_identity_invalid',
                'mutate' => static function (array &$outputs): void {
                    $outputs['duplicate_completion_response']['activity_attempt_id'] = 'different-attempt';
                },
            ],
            [
                'scenario' => 'php_python_activity_parity',
                'failure' => 'php_python_parity_python_completion_identity_invalid',
                'mutate' => static function (array &$outputs): void {
                    $outputs['handle_responses']['sdk-python']['activity_execution_id'] = 'different-execution';
                },
            ],
            [
                'scenario' => 'operator_visible_activity_attempt_state',
                'failure' => 'operator_visibility_stale_queue_regression_fixture_invalid',
                'mutate' => static function (array &$outputs): void {
                    $outputs['stale_shared_queue_regression_fixture']['timed_out_task_queue'] =
                        $outputs['stale_shared_queue_regression_fixture']['retry_task_queue'];
                },
            ],
        ];

        foreach ($cases as $case) {
            $evidence = $this->completeRunnerActivityEvidence();
            foreach ($evidence['scenario_results'] as &$scenario) {
                if (($scenario['scenario_id'] ?? null) !== $case['scenario']) {
                    continue;
                }
                $case['mutate']($scenario['observed_outputs']);
                $scenario['scenario_evidence'] = $scenario['observed_outputs'];
            }
            unset($scenario);

            $run = $this->runActivityRunnerWithEvidence($evidence);
            $this->assertSame(1, $run['exit'], $run['output']);
            $byScenario = [];
            foreach ($run['result']['scenario_results'] as $scenario) {
                $byScenario[$scenario['scenario_id']] = $scenario;
            }
            $this->assertContains(
                $case['failure'],
                $byScenario[$case['scenario']]['observed_outputs']['scenario_contract_failures'] ?? [],
            );

            $result = $this->completeActivityResult();
            foreach ($result['scenario_results'] as &$scenario) {
                if (($scenario['scenario_id'] ?? null) !== $case['scenario']) {
                    continue;
                }
                $case['mutate']($scenario['observed_outputs']);
                $scenario['scenario_evidence'] = $scenario['observed_outputs'];
            }
            unset($scenario);

            $evaluation = ActivityRuntimeResultGate::evaluate($result, ActivityRuntimeContract::manifest());
            $this->assertSame('non_passing', $evaluation['status']);
            $this->assertContains($case['failure'], array_column($evaluation['gate_failures'], 'code'));
        }
    }

    public function test_result_gate_rejects_heartbeat_timeout_renewal_without_typed_stale_attempt_control(): void
    {
        $result = $this->completeActivityResult();
        foreach ($result['scenario_results'] as &$scenario) {
            if (($scenario['scenario_id'] ?? null) !== 'heartbeat_timeout_renewal_across_enforcement_passes') {
                continue;
            }
            $scenario['observed_outputs']['negative_control']['late_failure_conflict']['reason'] = 'unknown';
            $scenario['scenario_evidence'] = $scenario['observed_outputs'];
        }
        unset($scenario);

        $evaluation = ActivityRuntimeResultGate::evaluate($result, ActivityRuntimeContract::manifest());

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'heartbeat_timeout_renewal_negative_control_invalid',
            array_column($evaluation['gate_failures'], 'code'),
        );
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
     * @param  array<string, mixed>  $activityEvidence
     * @param  array<string, mixed>|null  $recordedIdentities
     * @param  array<string, string>  $extraEnvironment
     * @return array{exit: int, output: string, result: array<string, mixed>}
     */
    private function runActivityRunnerWithEvidence(
        array $activityEvidence,
        ?array $recordedIdentities = null,
        array $extraEnvironment = [],
    ): array {
        if (trim((string) shell_exec('command -v bash 2>/dev/null')) === ''
            || trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
            $this->markTestSkipped('bash and node are required to exercise the activities runner.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $resultDir = sys_get_temp_dir().'/dw-activities-identities-'.bin2hex(random_bytes(6));
        mkdir($resultDir);
        try {
            file_put_contents(
                $resultDir.'/artifact-install-evidence.json',
                json_encode($this->completeRunnerInstallEvidence('9.9.9'), JSON_THROW_ON_ERROR),
            );
            file_put_contents(
                $resultDir.'/activity-evidence.json',
                json_encode($activityEvidence, JSON_THROW_ON_ERROR),
            );
            if ($recordedIdentities !== null) {
                file_put_contents(
                    $resultDir.'/executed-distribution-identities.json',
                    json_encode($recordedIdentities, JSON_THROW_ON_ERROR),
                );
            }

            $environment = [
                'DW_ACTIVITIES_SKIP_FOCUSED_HOST_PROBE' => '1',
                'DW_SERVER_IMAGE' => 'durableworkflow/server:9.9.9',
                'DW_SERVER_VERSION' => '9.9.9',
                'DW_CLI_VERSION' => '9.9.9',
                'DW_PHP_SDK_VERSION' => '9.9.9',
                'DW_PYTHON_SDK_VERSION' => '9.9.9',
                'DW_WORKFLOW_PHP_VERSION' => '9.9.9',
                'DW_WATERLINE_VERSION' => '9.9.9',
                ...$extraEnvironment,
            ];
            $environmentPrefix = array_map(
                static fn (string $name, string $value): string => $name.'='.escapeshellarg($value),
                array_keys($environment),
                array_values($environment),
            );
            $command = implode(' ', [
                ...$environmentPrefix,
                escapeshellarg($repoRoot.'/scripts/conformance/activities-published-artifacts.sh'),
                '--result-dir',
                escapeshellarg($resultDir),
            ]);
            $output = [];
            exec($command.' 2>&1', $output, $exitCode);

            return [
                'exit' => $exitCode,
                'output' => implode("\n", $output),
                'result' => json_decode(
                    (string) file_get_contents($resultDir.'/activities-result.json'),
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                ),
            ];
        } finally {
            $this->removeDirectory($resultDir);
        }
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
                    'artifact' => 'sdk-php',
                    'version' => $version,
                    'source' => 'https://packagist.org/packages/durable-workflow/sdk#'.$version,
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
     * @return array<string, array{kind: string, locator: string, artifacts: list<array{name: string, sha256: string}>}>
     */
    private function executedDistributionIdentities(string $version): array
    {
        return [
            'workflow' => [
                'kind' => 'composer',
                'locator' => 'composer:durable-workflow/workflow@'.$version,
                'artifacts' => [['name' => 'durable-workflow/workflow', 'sha256' => str_repeat('d', 64)]],
            ],
            'waterline' => [
                'kind' => 'composer',
                'locator' => 'composer:durable-workflow/waterline@'.$version,
                'artifacts' => [['name' => 'durable-workflow/waterline', 'sha256' => str_repeat('e', 64)]],
            ],
            'server' => [
                'kind' => 'oci',
                'locator' => 'oci:docker.io/durableworkflow/server@'.$version,
                'artifacts' => [['name' => 'manifest', 'sha256' => str_repeat('a', 64)]],
            ],
            'cli' => [
                'kind' => 'github-release',
                'locator' => 'github-release:durable-workflow/cli@'.$version,
                'artifacts' => [['name' => 'install.sh', 'sha256' => str_repeat('b', 64)]],
            ],
            'sdk-python' => [
                'kind' => 'pypi',
                'locator' => 'pypi:durable-workflow@'.$version,
                'artifacts' => [[
                    'name' => 'durable_workflow-'.$version.'-py3-none-any.whl',
                    'sha256' => str_repeat('c', 64),
                ]],
            ],
            'sdk-php' => [
                'kind' => 'composer',
                'locator' => 'composer:durable-workflow/sdk@'.$version,
                'artifacts' => [['name' => 'durable-workflow/sdk', 'sha256' => str_repeat('f', 64)]],
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
            $observedOutputs = $scenarioId === 'heartbeat_timeout_renewal_across_enforcement_passes'
                ? $this->heartbeatTimeoutRenewalEvidence($version)
                : array_filter([
                    'evidence' => $scenarioId,
                    'activity_host_evidence' => $activityHostEvidence,
                ]) + $this->activityIsolationEvidence($scenarioId);
            $scenarioResults[] = [
                'scenario_id' => $scenarioId,
                'status' => 'pass',
                'observed_outputs' => $observedOutputs,
                'scenario_evidence' => $observedOutputs,
            ];
        }

        return [
            'schema' => 'durable-workflow.v2.activity-runtime.host-evidence',
            'execution_source' => 'published_server_container',
            'executed_distribution_identities' => $this->executedDistributionIdentities($version),
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
                'runtimes' => ['workflow-php', 'sdk-php', 'sdk-python'],
            ],
            'durable_result_recording' => ['status' => 'pass'],
            'retry_backoff' => ['status' => 'pass'],
            'timeout_behavior' => ['status' => 'pass'],
            'heartbeat_timeout_renewal' => ['status' => 'pass'],
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
            'sdk-php' => '9.9.9',
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
            $observedOutputs = $scenarioId === 'heartbeat_timeout_renewal_across_enforcement_passes'
                ? $this->heartbeatTimeoutRenewalEvidence($artifactVersions['sdk-php'])
                : array_filter([
                    'sample' => $scenarioId,
                    'published_artifact_worker_execution' => $publishedServerExecution,
                    'activity_host_evidence' => $activityHostEvidence,
                ]) + $this->activityIsolationEvidence($scenarioId);
            $scenarioResults[] = [
                'scenario_id' => $scenarioId,
                'status' => 'pass',
                'observed_outputs' => $observedOutputs,
                'scenario_evidence' => $observedOutputs,
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
                'sdk-php' => 'https://packagist.org/packages/durable-workflow/sdk#9.9.9',
                'sdk-python' => 'https://pypi.org/project/durable-workflow/9.9.9/',
                'workflow' => 'https://packagist.org/packages/durable-workflow/workflow#9.9.9',
                'waterline' => 'https://packagist.org/packages/durable-workflow/waterline#9.9.9',
            ],
            'scenario_results' => $scenarioResults,
            'published_artifact_worker_execution' => $publishedServerExecution,
            'findings' => [],
            'finding_links' => [],
            'topology' => [
                'task_queue_strategy' => 'per_scenario_isolated',
            ],
            'runtime_matrix' => [
                'execution_modes' => ['workflow-embedded', 'standalone'],
                'runtimes' => ['workflow-php', 'sdk-php', 'sdk-python'],
            ],
            'published_artifact_install' => ['status' => 'pass'],
            'durable_result_recording' => ['status' => 'pass'],
            'retry_backoff' => ['status' => 'pass'],
            'timeout_behavior' => ['status' => 'pass'],
            'heartbeat_timeout_renewal' => ['status' => 'pass'],
            'typed_failure_propagation' => ['status' => 'pass'],
            'heartbeat_cancellation' => ['status' => 'pass'],
            'idempotent_completion' => ['status' => 'pass'],
            'operator_visibility' => ['status' => 'pass'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function activityIsolationEvidence(string $scenarioId): array
    {
        if ($scenarioId === 'idempotent_completion_handling') {
            return [
                'activity_execution_id' => 'idempotent-execution',
                'first_completion_response' => [
                    'task_id' => 'idempotent-task',
                    'activity_attempt_id' => 'idempotent-attempt',
                    'recorded' => true,
                ],
                'duplicate_completion_response' => [
                    'task_id' => 'idempotent-task',
                    'activity_attempt_id' => 'idempotent-attempt',
                    'recorded' => false,
                    'reason' => 'stale_attempt',
                ],
                'same_task_and_attempt_ids' => true,
                'recorded_once' => true,
                'activity_completed_history_count' => 1,
                'activity_completed_history_events' => [[
                    'activity_execution_id' => 'idempotent-execution',
                    'activity_attempt_id' => 'idempotent-attempt',
                ]],
            ];
        }

        if ($scenarioId === 'php_python_activity_parity') {
            return [
                'php_activity_id' => 'parity-result-workflow-php',
                'python_activity_id' => 'parity-result-sdk-python',
                'php_workflow_run_id' => 'parity-run-workflow-php',
                'python_workflow_run_id' => 'parity-run-sdk-python',
                'php_activity_execution_id' => 'parity-execution-workflow-php',
                'python_activity_execution_id' => 'parity-execution-sdk-python',
                'php_activity_result' => [
                    'runtime' => 'workflow-php',
                    'input_marker' => 'parity-result-fixture',
                ],
                'python_activity_result' => [
                    'runtime' => 'sdk-python',
                    'input_marker' => 'parity-result-fixture',
                ],
                'handle_responses' => [
                    'workflow-php' => [
                        'activity_id' => 'parity-result-workflow-php',
                        'workflow_run_id' => 'parity-run-workflow-php',
                        'activity_execution_id' => 'parity-execution-workflow-php',
                    ],
                    'sdk-python' => [
                        'activity_id' => 'parity-result-sdk-python',
                        'workflow_run_id' => 'parity-run-sdk-python',
                        'activity_execution_id' => 'parity-execution-sdk-python',
                    ],
                ],
            ];
        }

        if ($scenarioId === 'operator_visible_activity_attempt_state') {
            return [
                'stale_shared_queue_regression_fixture' => [
                    'retry_task_id' => 'operator-retry-task',
                    'retry_activity_execution_id' => 'operator-retry-execution',
                    'retry_task_queue' => 'activities-isolated-operator-retrying',
                    'configured_backoff_seconds' => 60,
                    'retry_available_at' => '2026-06-21T00:01:00.000000Z',
                    'backoff_crossed_at' => '2026-06-21T00:01:00.100000Z',
                    'backoff_crossed_before_timed_out_poll' => true,
                    'timed_out_activity_execution_id' => 'operator-timeout-execution',
                    'timed_out_task_queue' => 'activities-isolated-operator-timed-out',
                    'isolated_task_queues' => true,
                    'timed_out_worker_visible_start_to_close_deadline' => '2026-06-21T00:01:01.100000Z',
                ],
            ];
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function heartbeatTimeoutRenewalEvidence(string $version): array
    {
        $acknowledgements = [];
        $enforcementPasses = [];
        $timestamp = static function (float $seconds): string {
            $wholeSeconds = (int) floor($seconds);
            $microseconds = (int) round(($seconds - $wholeSeconds) * 1_000_000);

            return sprintf('2026-06-21T00:00:%02d.%06dZ', $wholeSeconds, $microseconds);
        };
        foreach (range(1, 4) as $sequence) {
            $lastHeartbeatOffset = $sequence * 0.35;
            $previousDeadline = $timestamp(2 + (($sequence - 1) * 0.35));
            $currentDeadline = $timestamp(2 + $lastHeartbeatOffset);
            $acknowledgements[] = [
                'sequence' => $sequence,
                'response' => [
                    'heartbeat_recorded' => true,
                    'can_continue' => true,
                    'cancel_requested' => false,
                    'activity_attempt_id' => 'sdk-php-heartbeat-attempt',
                ],
                'request_started_at' => $timestamp($lastHeartbeatOffset - 0.005),
                'response_received_at' => $timestamp($lastHeartbeatOffset + 0.005),
                'previous_deadline_at' => $previousDeadline,
                'authoritative_deadline_at' => $currentDeadline,
                'last_heartbeat_at' => $timestamp($lastHeartbeatOffset),
                'deadline_advanced' => true,
            ];
            $enforcementPasses[] = [
                'pass' => $sequence,
                'observed_at' => $timestamp($lastHeartbeatOffset + 0.01),
                'finished_at' => $timestamp($lastHeartbeatOffset + 0.02),
                'authoritative_deadline_at' => $currentDeadline,
                'activity_timed_out_history_count' => 0,
                'response' => [
                    'processed' => 1,
                    'enforced' => 0,
                    'skipped' => 1,
                    'failed' => 0,
                    'results' => [[
                        'execution_id' => 'sdk-php-heartbeat-execution',
                        'outcome' => 'skipped',
                        'reason' => 'no_deadline_expired',
                    ]],
                ],
            ];
        }

        return [
            'worker_id' => 'sdk-php-heartbeat-worker',
            'task_queue' => 'activities-isolated-sdk-php-heartbeat',
            'managed_worker_deregistration' => [
                'response' => ['outcome' => 'deregistered'],
            ],
            'php_sdk_worker_artifact' => [
                'artifact' => 'sdk-php',
                'package' => 'durable-workflow/sdk',
                'version' => $version,
                'source' => 'packagist://durable-workflow/sdk@'.$version,
                'status' => 'pass',
                'runtime' => 'sdk-php',
                'language' => 'php',
                'execution_source' => 'published_server_container',
                'execution_method' => 'DurableWorkflow\\Worker::run',
                'local_product_source_checkouts_used' => false,
            ],
            'heartbeat_timeout_seconds' => 2,
            'heartbeat_cadence_seconds' => 0.35,
            'initial_heartbeat_deadline_at' => '2026-06-21T00:00:02.000000Z',
            'heartbeat_acknowledgements' => $acknowledgements,
            'enforcement_passes' => $enforcementPasses,
            'in_flight_duration_seconds' => 2.4,
            'completion_response' => [
                'recorded' => true,
                'reason' => null,
            ],
            'terminal_history' => [
                'event_types' => [
                    'ActivityHeartbeatRecorded',
                    'ActivityCompleted',
                    'WorkflowCompleted',
                ],
                'activity_heartbeat_recorded_count' => count($acknowledgements),
                'activity_completed_count' => 1,
                'activity_timed_out_count' => 0,
                'completed_exactly_once' => true,
                'history_without_contradiction' => true,
            ],
            'negative_control' => [
                'worker_id' => 'sdk-php-heartbeat-negative-worker',
                'task_queue' => 'activities-isolated-sdk-php-heartbeat-negative',
                'worker_deregistration' => ['outcome' => 'deregistered'],
                'initial_heartbeat_deadline_at' => $timestamp(5),
                'enforcement_observed_at' => $timestamp(5.25),
                'enforcement_pass' => [
                    'processed' => 1,
                    'enforced' => 1,
                    'skipped' => 0,
                    'failed' => 0,
                    'results' => [[
                        'execution_id' => 'sdk-php-heartbeat-negative-execution',
                        'outcome' => 'enforced',
                        'has_retry' => false,
                    ]],
                ],
                'typed_timeout_payload' => [
                    'timeout_kind' => 'heartbeat',
                    'failure_category' => 'timeout',
                    'activity_execution_id' => 'sdk-php-heartbeat-negative-execution',
                    'activity_attempt_id' => 'sdk-php-heartbeat-negative-attempt',
                ],
                'late_heartbeat_response' => [
                    'heartbeat_recorded' => false,
                    'can_continue' => false,
                    'reason' => 'attempt_closed',
                ],
                'late_completion_conflict' => [
                    'http_status' => 409,
                    'reason' => 'stale_attempt',
                    'recorded' => false,
                ],
                'late_failure_conflict' => [
                    'http_status' => 409,
                    'reason' => 'stale_attempt',
                    'recorded' => false,
                ],
                'terminal_history' => [
                    'event_types' => ['ActivityTimedOut', 'WorkflowFailed'],
                    'activity_timed_out_count' => 1,
                    'activity_completed_count' => 0,
                    'activity_failed_count' => 0,
                ],
            ],
            'isolated_cleanup' => [
                'isolated_database' => true,
                'isolated_storage' => true,
                'scratch_removed_on_exit' => true,
                'published_server_container_removed' => true,
                'result_evidence_retained_outside_scratch' => true,
            ],
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

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}
