<?php

namespace Tests\Unit;

use App\Support\WorkflowLifecycleContract;
use App\Support\WorkflowLifecycleResultGate;
use PHPUnit\Framework\TestCase;

class WorkflowLifecycleContractTest extends TestCase
{
    public function test_manifest_publishes_an_enforceable_result_gate(): void
    {
        $manifest = WorkflowLifecycleContract::manifest();
        $resultGate = $manifest['result_gate'];
        $hostRunner = $manifest['host_runner_contract'];

        $this->assertSame(WorkflowLifecycleContract::SCHEMA, $manifest['schema']);
        $this->assertSame(WorkflowLifecycleContract::RESULT_SCHEMA, $manifest['result_schema']);
        $this->assertSame(WorkflowLifecycleResultGate::SCHEMA, $resultGate['schema']);
        $this->assertSame(WorkflowLifecycleResultGate::VERSION, $resultGate['version']);
        $this->assertSame(
            WorkflowLifecycleContract::RESULT_SCHEMA,
            $resultGate['evaluates_result_schema'],
        );
        $this->assertContains('artifact_sources', $manifest['artifact_policy']['required_run_record_fields']);
        $this->assertContains('lifecycle_cell_outcomes', $manifest['artifact_policy']['required_run_record_fields']);
        $this->assertContains('findings', $manifest['artifact_policy']['required_run_record_fields']);
        $this->assertContains('local_product_source_checkouts_used', $manifest['artifact_policy']['required_run_record_fields']);
        $this->assertContains('source_policy', $manifest['artifact_policy']['required_run_record_fields']);
        $this->assertContains('local_product_source_truthy_values_are_refused_consistently', $resultGate['pass_requires']);
        $this->assertContains('published_server_php_and_composer_separate_processes', $hostRunner['php_sdk_probe_executors']);
        $this->assertContains('local_php_and_composer', $hostRunner['php_sdk_probe_executors']);
        $this->assertNotContains('docker_composer_2', $hostRunner['php_sdk_probe_executors']);
        $this->assertContains('DW_WORKFLOW_LIFECYCLE_PHP_BIN', $hostRunner['php_sdk_probe_binary_overrides']);
        $this->assertContains('DW_WORKFLOW_LIFECYCLE_COMPOSER_BIN', $hostRunner['php_sdk_probe_binary_overrides']);
        $this->assertTrue($hostRunner['php_sdk_probe_does_not_require_docker_inside_server_container']);
        $this->assertContains('python_venv_pypi_install', $hostRunner['python_sdk_probe_executors']);
        $this->assertContains('configured_python_binary', $hostRunner['python_sdk_probe_executors']);
        $this->assertContains('DW_WORKFLOW_LIFECYCLE_PYTHON_BIN', $hostRunner['python_sdk_probe_binary_overrides']);
        $this->assertContains('<result-dir>/python-sdk-lifecycle-evidence.json', $hostRunner['evidence_inputs']);
        $this->assertContains('<result-dir>/rust-sdk-lifecycle-evidence.json', $hostRunner['evidence_inputs']);
        $this->assertContains('python-sdk-lifecycle-evidence.json', $hostRunner['result_files']);
        $this->assertContains('rust-sdk-lifecycle-evidence.json', $hostRunner['result_files']);
        $this->assertTrue($hostRunner['python_sdk_probe_does_not_require_docker_inside_server_container']);
        $this->assertSame(
            'scripts/conformance/workflow_lifecycle_python_discovery_fixture.py',
            $hostRunner['python_sdk_runtime_discovery_fixture_path'],
        );
        $this->assertSame('GET /api/cluster/info', $hostRunner['python_sdk_runtime_discovery_request']);
        $this->assertSame(
            'worker_protocol.server_capabilities.query_tasks',
            $hostRunner['python_sdk_runtime_discovery_required_capability'],
        );
        $this->assertSame('runner-gap', $hostRunner['python_sdk_pre_behavior_failure_classification']);
        $this->assertSame(
            ['operation', 'classification', 'owning_surface', 'exception_type', 'message'],
            $hostRunner['python_sdk_unexpected_exception_required_fields'],
        );
        $this->assertTrue($hostRunner['rust_sdk_probe_required']);
        $this->assertSame('0.1.15', $hostRunner['rust_sdk_probe_minimum_version']);
        $this->assertContains('docker_rust_1_86_exact_crates_io_install', $hostRunner['rust_sdk_probe_executors']);
        $this->assertSame('scripts/conformance/workflow-lifecycle-host-published-artifacts.sh', $hostRunner['runner_path']);
        $this->assertSame('docker_capable_host', $hostRunner['runner_execution_context']);
        $this->assertSame('extract_from_exact_published_server_image', $hostRunner['runner_distribution']);
        $this->assertSame('/app/scripts/conformance/workflow-lifecycle-host-published-artifacts.sh', $hostRunner['runner_image_path']);
        $this->assertSame('docker_capable_host', $hostRunner['published_topology']['executor']);
        $this->assertSame('scripts_extracted_from_exact_server_image', $hostRunner['published_topology']['runner_source']);
        $this->assertTrue($hostRunner['rust_sdk_probe_requires_http_and_scheduler_topology']);
        $this->assertTrue($hostRunner['rust_sdk_probe_runs_outside_server_container']);
        $this->assertSame(
            [
                'php_sdk_lifecycle_surface',
                'python_sdk_lifecycle_surface',
                'rust_sdk_lifecycle_surface',
            ],
            $hostRunner['lifecycle_shard_diagnostics']['non_pass_shards'],
        );
        $this->assertSame(8192, $hostRunner['lifecycle_shard_diagnostics']['max_bytes_per_shard']);
        $this->assertTrue(
            $hostRunner['lifecycle_shard_diagnostics']['readiness_mismatch_and_last_server_observation_retained_when_observed'],
        );
        $this->assertTrue(
            $hostRunner['lifecycle_shard_diagnostics']['assertion_expected_and_observed_per_failed_operation_retained'],
        );
        $this->assertTrue(
            $hostRunner['lifecycle_shard_diagnostics']['assertion_worker_and_sdk_response_layers_retained_when_observed'],
        );
        $this->assertTrue(
            $hostRunner['lifecycle_shard_diagnostics']['client_timeout_and_unavailable_worker_companion_retained'],
        );
        $this->assertSame(
            'durable-workflow.v2.php-sdk-companion-failure',
            $hostRunner['lifecycle_shard_diagnostics']['companion_diagnostic_schema'],
        );
        $this->assertSame(6144, $hostRunner['lifecycle_shard_diagnostics']['companion_diagnostic_max_bytes']);
        $this->assertTrue(
            $hostRunner['lifecycle_shard_diagnostics']['structured_worker_protocol_failure_required'],
        );
        $this->assertTrue(
            $hostRunner['lifecycle_shard_diagnostics']['worker_runtime_exception_separate_from_protocol_failure'],
        );
        $this->assertSame(
            ['operation', 'classification', 'owning_surface', 'exception_type', 'message'],
            $hostRunner['lifecycle_shard_diagnostics']['worker_runtime_exception_required_fields'],
        );
        $this->assertSame(
            [
                'operation',
                'http_method',
                'endpoint_class',
                'status_code',
                'reason',
                'retryable',
                'task_id',
                'workflow_id',
                'run_id',
                'public_error_envelope',
            ],
            $hostRunner['lifecycle_shard_diagnostics']['worker_protocol_failure_required_fields'],
        );
        $this->assertTrue(
            $hostRunner['lifecycle_shard_diagnostics']['generic_protocol_failure_server_error_record_required'],
        );
        $this->assertTrue(
            $hostRunner['lifecycle_shard_diagnostics']['generic_protocol_failure_correlated_error_severity_required'],
        );
    }

    public function test_result_gate_accepts_complete_published_artifact_lifecycle_pass(): void
    {
        $evaluation = WorkflowLifecycleResultGate::evaluate($this->completeLifecycleResult());

        $this->assertSame('pass', $evaluation['status']);
        $this->assertSame([], $evaluation['gate_failures']);
    }

    public function test_result_gate_rejects_assertion_failure_diagnostics_without_operation_evidence(): void
    {
        $result = $this->completeLifecycleResult();
        $result['outcome'] = 'non_passing';
        $result['scenario_results']['php_sdk_lifecycle_surface']['status'] = 'fail';
        $result['scenario_results']['php_sdk_lifecycle_surface']['observed_outputs']['shard_diagnostic'] = [
            'schema' => 'durable-workflow.v2.workflow-lifecycle.shard-diagnostic',
            'version' => 1,
            'retention' => 'inline_result_and_record',
            'operation' => 'multiple_lifecycle_operations',
            'failure_stage' => 'runtime_assertions',
            'process_state' => ['state' => 'exited'],
            'classification' => 'product-gap',
            'owning_surface' => 'multiple_product_surfaces',
            'excerpt' => 'Failed assertions: signal_query, update.',
        ];
        $result['lifecycle_cell_outcomes']['php_sdk_lifecycle_surface']['status'] = 'fail';

        $evaluation = WorkflowLifecycleResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'missing_lifecycle_assertion_failure_evidence',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_rejects_client_timeout_without_companion_evidence(): void
    {
        $result = $this->completeLifecycleResult();
        $result['outcome'] = 'non_passing';
        $php = &$result['scenario_results']['php_sdk_lifecycle_surface'];
        $php['status'] = 'fail';
        $php['observed_outputs']['failure_kind'] = 'client_timeout';
        $php['observed_outputs']['runtime_failure_evidence'] = [
            'exception_type' => 'DurableWorkflow\\Exception\\WorkflowTimedOut',
            'message' => 'Timed out waiting for a terminal result.',
        ];
        $php['observed_outputs']['shard_diagnostic'] = [
            'schema' => 'durable-workflow.v2.workflow-lifecycle.shard-diagnostic',
            'version' => 1,
            'retention' => 'inline_result_and_record',
            'operation' => 'workflow.result:failed',
            'failure_stage' => 'baseline_client',
            'process_state' => ['state' => 'failed'],
            'classification' => 'product-gap',
            'owning_surface' => 'sdk-php',
            'excerpt' => 'The client timed out.',
        ];
        $result['lifecycle_cell_outcomes']['php_sdk_lifecycle_surface']['status'] = 'fail';

        $evaluation = WorkflowLifecycleResultGate::evaluate($result);

        $this->assertContains(
            'missing_lifecycle_timeout_companion_evidence',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_rejects_server_ownership_when_retained_run_is_terminal(): void
    {
        $result = $this->completeLifecycleResult();
        $result['outcome'] = 'non_passing';
        $php = &$result['scenario_results']['php_sdk_lifecycle_surface'];
        $php['status'] = 'fail';
        $php['lifecycle_cell_outcome'] = 'fail';
        $php['observed_outputs']['failure_kind'] = 'client_timeout';
        $php['observed_outputs']['runtime_failure_evidence'] = [
            'exception_type' => 'DurableWorkflow\\Exception\\WorkflowTimedOut',
            'message' => 'Timed out waiting for the terminal result.',
        ];
        $companion = [
            'schema' => 'durable-workflow.v2.php-sdk-companion-failure',
            'failure_kind' => 'client_timeout',
            'operation' => 'workflow.result:failed',
            'classification' => 'server',
            'owning_surface' => 'server',
            'classification_basis' => 'worker_alive_run_not_terminal',
            'worker' => [
                'process_state' => ['state' => 'alive', 'alive' => true, 'exit_code' => null],
                'last_protocol_failure' => null,
                'last_runtime_exception' => null,
                'structured_stderr' => ['excerpt' => 'Worker remained alive.'],
            ],
            'server' => [
                'health' => ['http_status' => 200, 'payload' => ['status' => 'serving']],
                'run_state' => ['http_status' => 200, 'payload' => [
                    'status' => 'failed',
                    'is_terminal' => true,
                ]],
                'history' => ['http_status' => 200, 'payload' => [
                    'last_event_types' => ['WorkflowStarted', 'WorkflowFailed'],
                ]],
                'task_queue' => ['http_status' => 200, 'payload' => [
                    'name' => 'php-sdk-conformance',
                    'stats' => ['workflow_tasks' => ['ready_count' => 0]],
                    'pollers' => [['worker_id' => 'php-sdk-worker-1', 'status' => 'active']],
                    'current_leases' => [],
                    'admission' => ['workflow_tasks' => ['status' => 'accepting']],
                ]],
            ],
            'retained_after_cleanup' => true,
            'max_bytes' => 6144,
        ];
        $php['observed_outputs']['shard_diagnostic'] = [
            'schema' => 'durable-workflow.v2.workflow-lifecycle.shard-diagnostic',
            'version' => 1,
            'retention' => 'inline_result_and_record',
            'operation' => 'workflow.result:failed',
            'failure_stage' => 'baseline_client',
            'process_state' => ['state' => 'alive'],
            'classification' => 'server',
            'owning_surface' => 'server',
            'companion_failure' => $companion,
            'excerpt' => 'Client timed out after the run failed.',
        ];
        $result['lifecycle_cell_outcomes']['php_sdk_lifecycle_surface']['status'] = 'fail';

        $evaluation = WorkflowLifecycleResultGate::evaluate($result);

        $this->assertContains(
            'lifecycle_timeout_ownership_ignores_terminal_run_state',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_published_artifact_runner_handoff_emits_non_passing_matrix_without_evidence(): void
    {
        $resultDir = sys_get_temp_dir().'/dw-workflow-lifecycle-'.bin2hex(random_bytes(6));
        mkdir($resultDir, 0777, true);

        try {
            exec($this->runnerCommand($resultDir), $output, $exitCode);

            $this->assertSame(0, $exitCode, implode("\n", $output));
            $result = $this->readJson($resultDir.'/workflow-lifecycle-result.json');

            $this->assertSame('non_passing', $result['outcome']);
            $this->assertFalse($result['runner_blocked']);
            $this->assertSame(WorkflowLifecycleContract::requiredScenarios(), array_keys($result['scenario_results']));
            $this->assertSame(WorkflowLifecycleContract::requiredScenarios(), $result['unproven_lifecycle_cells']);
            $this->assertSame(
                [
                    'php_sdk_lifecycle_surface',
                    'python_sdk_lifecycle_surface',
                    'rust_sdk_lifecycle_surface',
                ],
                array_keys($result['shard_diagnostics']),
            );
            foreach ($result['shard_diagnostics'] as $diagnostic) {
                $this->assertSame('inline_result_and_record', $diagnostic['retention']);
                $this->assertNotSame('', $diagnostic['operation']);
                $this->assertNotSame('', $diagnostic['process_state']['state']);
                $this->assertLessThanOrEqual(
                    8192,
                    strlen(json_encode($diagnostic, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
                );
            }
            $this->assertSame([], WorkflowLifecycleResultGate::evaluate($result)['gate_failures']);
        } finally {
            $this->removeDirectory($resultDir);
        }
    }

    public function test_published_artifact_runner_accepts_host_evidence_with_execution_markers(): void
    {
        $resultDir = sys_get_temp_dir().'/dw-workflow-lifecycle-'.bin2hex(random_bytes(6));
        mkdir($resultDir, 0777, true);
        $evidencePath = $resultDir.'/workflow-lifecycle-evidence.json';
        file_put_contents($evidencePath, json_encode($this->hostEvidence(), JSON_THROW_ON_ERROR));
        $this->writeRustSidecar($resultDir);

        try {
            exec($this->runnerCommand($resultDir, [
                'DW_WORKFLOW_LIFECYCLE_EVIDENCE_PATH' => $evidencePath,
            ]), $output, $exitCode);

            $this->assertSame(0, $exitCode, implode("\n", $output));
            $result = $this->readJson($resultDir.'/workflow-lifecycle-result.json');

            $this->assertSame('pass', $result['outcome']);
            $this->assertFalse($result['local_product_source_checkouts_used']);
            $this->assertSame(WorkflowLifecycleContract::requiredScenarios(), $result['proven_lifecycle_cells']);
            $this->assertSame([], $result['findings']);
            $this->assertSame('pass', WorkflowLifecycleResultGate::evaluate($result)['status']);
            $this->assertSame([], $result['shard_diagnostics']);
            $this->assertArrayNotHasKey(
                'shard_diagnostic',
                $result['scenario_results']['php_sdk_lifecycle_surface']['observed_outputs'],
            );
            $this->assertArrayNotHasKey(
                'assertion_failures',
                $result['scenario_results']['php_sdk_lifecycle_surface']['observed_outputs'],
            );
        } finally {
            $this->removeDirectory($resultDir);
        }
    }

    public function test_published_artifact_runner_merges_php_sdk_lifecycle_sidecar(): void
    {
        $resultDir = sys_get_temp_dir().'/dw-workflow-lifecycle-'.bin2hex(random_bytes(6));
        mkdir($resultDir, 0777, true);
        $evidencePath = $resultDir.'/workflow-lifecycle-evidence.json';
        $sidecarPath = $resultDir.'/php-sdk-lifecycle-evidence.json';

        $hostEvidence = $this->hostEvidence();
        unset($hostEvidence['scenario_results']['php_sdk_lifecycle_surface']);
        file_put_contents($evidencePath, json_encode($hostEvidence, JSON_THROW_ON_ERROR));
        $this->writeRustSidecar($resultDir);
        file_put_contents($sidecarPath, json_encode([
            'schema' => 'durable-workflow.v2.workflow-lifecycle.php-sdk-sidecar',
            'runner_blocked' => false,
            'scenario_results' => [
                'php_sdk_lifecycle_surface' => [
                    'status' => 'pass',
                    'published_artifact_cell_executed' => true,
                    'observed_outputs' => $this->outputsForScenario('php_sdk_lifecycle_surface') + [
                        'artifact_source' => 'packagist://durable-workflow/sdk@0.1.1',
                        'packagist_artifact_verified' => true,
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            exec($this->runnerCommand($resultDir, [
                'DW_WORKFLOW_LIFECYCLE_EVIDENCE_PATH' => $evidencePath,
                'DW_WORKFLOW_LIFECYCLE_SKIP_PHP_SDK_PROBE' => '1',
            ]), $output, $exitCode);

            $this->assertSame(0, $exitCode, implode("\n", $output));
            $result = $this->readJson($resultDir.'/workflow-lifecycle-result.json');

            $this->assertSame('pass', $result['outcome']);
            $this->assertSame(
                'pass',
                $result['scenario_results']['php_sdk_lifecycle_surface']['status'],
            );
            $this->assertStringContainsString('php-sdk-lifecycle-evidence.json', $result['evidence_source']);
            $this->assertSame('pass', WorkflowLifecycleResultGate::evaluate($result)['status']);
        } finally {
            $this->removeDirectory($resultDir);
        }
    }

    public function test_published_artifact_runner_preserves_php_http_failure_observed_evidence(): void
    {
        $resultDir = sys_get_temp_dir().'/dw-workflow-lifecycle-'.bin2hex(random_bytes(6));
        mkdir($resultDir, 0777, true);
        $evidencePath = $resultDir.'/workflow-lifecycle-evidence.json';
        $sidecarPath = $resultDir.'/php-sdk-lifecycle-evidence.json';

        $hostEvidence = $this->hostEvidence();
        unset($hostEvidence['scenario_results']['php_sdk_lifecycle_surface']);
        file_put_contents($evidencePath, json_encode($hostEvidence, JSON_THROW_ON_ERROR));
        $this->writeRustSidecar($resultDir);
        $httpFailure = [
            'schema' => 'durable-workflow.v2.php-sdk-runtime-failure',
            'classification' => 'server',
            'owning_surface' => 'server',
            'process' => 'client',
            'failure_stage' => 'baseline_client',
            'operation' => 'workflow.update:set',
            'http_method' => 'POST',
            'endpoint' => '/api/workflows/{workflow_id}/update/set',
            'status_code' => 404,
            'public_error_envelope' => [
                'error' => 'workflow_update_not_found',
                'reason' => 'unknown_update',
                'message' => 'Update set is not declared for this workflow.',
            ],
            'workflow_id' => 'php-sdk-addressable-123',
            'run_id' => 'run-456',
            'exception_type' => 'DurableWorkflow\\Exception\\UpdateFailed',
            'message' => 'Update set is not declared for this workflow.',
        ];
        file_put_contents($sidecarPath, json_encode([
            'schema' => 'durable-workflow.v2.workflow-lifecycle.php-sdk-sidecar',
            'runner_blocked' => false,
            'scenario_results' => [
                'php_sdk_lifecycle_surface' => [
                    'status' => 'fail',
                    'classification' => 'server',
                    'published_artifact_cell_executed' => true,
                    'observed_outputs' => $this->outputsForScenario('php_sdk_lifecycle_surface') + [
                        'artifact_source' => 'packagist://durable-workflow/sdk@0.1.1',
                        'runtime_failure_evidence' => $httpFailure,
                    ],
                    'linked_findings' => [[
                        'finding_id' => 'php-sdk-baseline-client-failure',
                        'finding_type' => 'product_behavior_gap',
                        'classification' => 'server',
                        'owning_surface' => 'server',
                        'summary' => 'The released PHP SDK operation workflow.update:set received HTTP 404 during baseline_client.',
                        'observed_evidence' => $httpFailure,
                        'next_acceptance_criterion' => 'Correct the named failure surface and rerun the exact published artifacts.',
                    ]],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            exec($this->runnerCommand($resultDir, [
                'DW_WORKFLOW_LIFECYCLE_EVIDENCE_PATH' => $evidencePath,
                'DW_WORKFLOW_LIFECYCLE_SKIP_PHP_SDK_PROBE' => '1',
            ]), $output, $exitCode);

            $this->assertSame(0, $exitCode, implode("\n", $output));
            $result = $this->readJson($resultDir.'/workflow-lifecycle-result.json');
            $record = $this->readJson($resultDir.'/workflow-lifecycle-record.json');
            $php = $result['scenario_results']['php_sdk_lifecycle_surface'];
            $finding = array_values(array_filter(
                $result['findings'],
                static fn (array $candidate): bool => ($candidate['finding_id'] ?? null) === 'php-sdk-baseline-client-failure',
            ))[0] ?? null;

            $this->assertSame('non_passing', $result['outcome']);
            $this->assertFalse($result['runner_blocked']);
            $this->assertSame('fail', $php['status']);
            $this->assertSame($httpFailure, $php['observed_outputs']['runtime_failure_evidence']);
            $this->assertIsArray($finding);
            $this->assertSame(404, $finding['observed_evidence']['status_code']);
            $this->assertSame('workflow.update:set', $finding['observed_evidence']['operation']);
            $this->assertSame('php-sdk-addressable-123', $finding['observed_evidence']['workflow_id']);
            $this->assertSame('server', $finding['owning_surface']);
            $this->assertSame($php, $record['scenarioResults']['php_sdk_lifecycle_surface']);
        } finally {
            $this->removeDirectory($resultDir);
        }
    }

    public function test_failing_lifecycle_shard_retains_diagnostic_after_disposable_files_are_removed(): void
    {
        $resultDir = sys_get_temp_dir().'/dw-workflow-lifecycle-'.bin2hex(random_bytes(6));
        $retainedRecord = sys_get_temp_dir().'/dw-workflow-lifecycle-retained-'.bin2hex(random_bytes(6)).'.json';
        mkdir($resultDir, 0777, true);
        $evidencePath = $resultDir.'/workflow-lifecycle-evidence.json';
        $sidecarPath = $resultDir.'/php-sdk-lifecycle-evidence.json';
        $privateToken = 'private-lifecycle-token';

        $hostEvidence = $this->hostEvidence();
        unset($hostEvidence['scenario_results']['php_sdk_lifecycle_surface']);
        file_put_contents($evidencePath, json_encode($hostEvidence, JSON_THROW_ON_ERROR));
        $this->writeRustSidecar($resultDir);
        $expectedContract = [
            'workflow_type' => 'php.sdk.waiting',
            'queries' => ['current'],
            'updates' => ['set'],
        ];
        $observedContract = [
            'queries' => ['current'],
            'updates' => ['set'],
            'update_contracts' => [],
        ];
        $lastRegistration = [
            'worker_id' => 'php-sdk-worker-1',
            'status' => 'active',
            'last_heartbeat_at' => '2026-07-16T00:00:10Z',
            'workflow_command_contracts' => [
                'php.sdk.waiting' => $observedContract,
            ],
        ];
        file_put_contents($sidecarPath, json_encode([
            'schema' => 'durable-workflow.v2.workflow-lifecycle.php-sdk-sidecar',
            'runner_blocked' => false,
            'scenario_results' => [
                'php_sdk_lifecycle_surface' => [
                    'status' => 'fail',
                    'classification' => 'product-gap',
                    'published_artifact_cell_executed' => true,
                    'observed_outputs' => $this->outputsForScenario('php_sdk_lifecycle_surface') + [
                        'failure_stage' => 'worker_readiness_timeout',
                        'failure_owner' => 'sdk-php',
                        'failure_summary' => 'The released PHP SDK worker remained alive while readiness timed out.',
                        'failure_diagnostic' => [
                            'path' => '/ephemeral/runtime/php-sdk-worker-1.diagnostic.log',
                            'excerpt' => 'Authorization: Bearer '.$privateToken.'; token='.$privateToken.' '.str_repeat('diagnostic ', 1200),
                        ],
                        'worker_startup' => [
                            'outcome' => 'readiness_timeout',
                            'worker_id' => 'php-sdk-worker-1',
                            'attempts' => 100,
                            'process_id' => 4321,
                            'process_alive_at_failure' => true,
                            'process_exit_code' => null,
                            'last_server_observation' => [
                                'observed_at' => '2026-07-16T00:00:10Z',
                                'http_status' => 200,
                                'payload' => $lastRegistration,
                            ],
                            'readiness_observation' => [
                                'required_workflow_command_contract' => $expectedContract,
                                'last_observed_workflow_command_contracts' => [
                                    'php.sdk.waiting' => $observedContract,
                                ],
                                'readiness_mismatch' => [
                                    'reason' => 'authoritative_workflow_command_contract_mismatch',
                                    'workflow_type' => 'php.sdk.waiting',
                                    'expected_contract' => $expectedContract,
                                    'observed_contract' => $observedContract,
                                ],
                                'last_server_observation' => [
                                    'observed_at' => '2026-07-16T00:00:10Z',
                                    'http_status' => 200,
                                    'payload' => $lastRegistration,
                                ],
                            ],
                        ],
                    ],
                    'linked_findings' => [[
                        'finding_id' => 'php-sdk-worker-readiness-timeout',
                        'finding_type' => 'product_behavior_gap',
                        'classification' => 'product-gap',
                        'owning_surface' => 'sdk-php',
                        'summary' => 'The released PHP SDK worker remained alive while readiness timed out.',
                        'next_acceptance_criterion' => 'Make the worker registration satisfy authoritative readiness.',
                    ]],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            exec($this->runnerCommand($resultDir, [
                'DW_WORKFLOW_LIFECYCLE_EVIDENCE_PATH' => $evidencePath,
                'DW_WORKFLOW_LIFECYCLE_AUTH_TOKEN' => $privateToken,
                'DW_WORKFLOW_LIFECYCLE_SKIP_PHP_SDK_PROBE' => '1',
            ]), $output, $exitCode);

            $this->assertSame(0, $exitCode, implode("\n", $output));
            file_put_contents(
                $retainedRecord,
                (string) file_get_contents($resultDir.'/workflow-lifecycle-record.json'),
            );
            $this->removeDirectory($resultDir);

            $record = $this->readJson($retainedRecord);
            $diagnostic = $record['shardDiagnostics']['php_sdk_lifecycle_surface'];
            $php = $record['scenarioResults']['php_sdk_lifecycle_surface'];
            $finding = $php['linked_findings'][0];
            $encodedDiagnostic = json_encode($diagnostic, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            $this->assertSame('non_passing', $record['outcome']);
            $this->assertFalse($record['runnerBlocked']);
            $this->assertSame('worker_registration_readiness', $diagnostic['operation']);
            $this->assertSame('alive', $diagnostic['process_state']['state']);
            $this->assertSame('readiness_timeout', $diagnostic['process_state']['outcome']);
            $this->assertSame(200, $diagnostic['http']['status']);
            $this->assertSame(100, $diagnostic['readiness']['attempts']);
            $this->assertSame(
                'authoritative_workflow_command_contract_mismatch',
                $diagnostic['readiness']['mismatch']['reason'],
            );
            $this->assertSame(
                $observedContract,
                $diagnostic['readiness']['last_server_observation']['payload']['workflow_command_contracts']['php.sdk.waiting'],
            );
            $this->assertSame('sdk-php', $diagnostic['owning_surface']);
            $this->assertSame('sdk-php', $finding['owning_surface']);
            $this->assertSame($diagnostic, $finding['observed_evidence']['shard_diagnostic']);
            $this->assertLessThanOrEqual(8192, strlen($encodedDiagnostic));
            $this->assertStringNotContainsString($privateToken, (string) file_get_contents($retainedRecord));
            $this->assertStringNotContainsString('/ephemeral/runtime', (string) file_get_contents($retainedRecord));
            $this->assertFileDoesNotExist($sidecarPath);
        } finally {
            $this->removeDirectory($resultDir);
            @unlink($retainedRecord);
        }
    }

    public function test_php_assertion_failures_capture_worker_server_and_sdk_observations(): void
    {
        require_once dirname(__DIR__, 2).'/scripts/conformance/php-sdk-assertion-failure-evidence.php';

        $failures = php_sdk_assertion_failure_evidence(
            ['signal_query', 'signal_negative_contracts', 'update'],
            [
                'signal_query' => 'server',
                'signal_negative_contracts' => 'server',
                'update' => 'sdk',
            ],
            [
                'signal_query' => [
                    'signals_sent' => 2,
                    'accepted_inputs' => [3, 5],
                    'query_result' => ['inputs' => [3, 5], 'total' => 7],
                    'history_inputs' => [3, 5],
                    'unknown_signal' => ['status_code' => 500, 'reason' => 'internal_error'],
                    'invalid_signal_arguments' => ['status_code' => 400, 'reason' => 'invalid_request'],
                ],
                'update' => ['result' => ['accepted' => false, 'value' => null]],
                'worker_operation_responses' => [
                    'workflow.query:current' => ['inputs' => [3, 5], 'total' => 8],
                    'workflow.update:set' => ['accepted' => true, 'value' => 13],
                ],
            ],
        );
        $byOperation = array_column($failures, null, 'operation');

        $this->assertCount(5, $failures);
        $this->assertSame(
            8,
            $byOperation['workflow.query:current']['observed']['worker_callback_response']['total'],
        );
        $this->assertSame(
            7,
            $byOperation['workflow.query:current']['observed']['sdk_decoded_response']['total'],
        );
        $this->assertSame(
            ['http_status' => 404, 'reason' => 'unknown_signal'],
            $byOperation['workflow.signal:undeclared']['expected'],
        );
        $this->assertSame(
            ['status_code' => 400, 'reason' => 'invalid_request'],
            $byOperation['workflow.signal:increment_invalid_arguments']['observed'],
        );
        $this->assertSame(
            ['accepted_signal_inputs' => [3, 5]],
            $byOperation['workflow.history:addressable_signals']['observed'],
        );
        $this->assertSame('server', $byOperation['workflow.query:current']['owning_surface']);
        $this->assertSame('sdk-php', $byOperation['workflow.update:set']['owning_surface']);
        $this->assertTrue(
            $byOperation['workflow.update:set']['observed']['worker_callback_response']['accepted'],
        );
        $this->assertFalse(
            $byOperation['workflow.update:set']['observed']['sdk_decoded_response']['accepted'],
        );
    }

    public function test_accepted_update_without_worker_dispatch_is_owned_by_the_server_protocol(): void
    {
        require_once dirname(__DIR__, 2).'/scripts/conformance/php-sdk-assertion-failure-evidence.php';

        $baseline = [
            'update' => [
                'result' => [
                    'update_status' => 'accepted',
                    'wait_timed_out' => true,
                    'applied_at' => null,
                    'value' => null,
                ],
            ],
            'worker_operation_responses' => [],
        ];

        $this->assertSame('server', php_sdk_update_assertion_domain($baseline));

        $failures = php_sdk_assertion_failure_evidence(
            ['update'],
            ['update' => php_sdk_update_assertion_domain($baseline)],
            $baseline,
        );

        $this->assertSame('server', $failures[0]['classification']);
        $this->assertSame('server', $failures[0]['owning_surface']);
        $this->assertFalse($failures[0]['observed']['worker_callback_dispatched']);
        $this->assertSame('accepted', $failures[0]['observed']['sdk_decoded_response']['update_status']);

        $baseline['worker_operation_responses']['workflow.update:set'] = ['accepted' => true, 'value' => 13];
        $this->assertSame('sdk', php_sdk_update_assertion_domain($baseline));
    }

    public function test_assertion_failure_evidence_remains_actionable_after_runtime_cleanup(): void
    {
        require_once dirname(__DIR__, 2).'/scripts/conformance/php-sdk-assertion-failure-evidence.php';

        $resultDir = sys_get_temp_dir().'/dw-workflow-lifecycle-'.bin2hex(random_bytes(6));
        $retainedRecord = sys_get_temp_dir().'/dw-workflow-lifecycle-retained-'.bin2hex(random_bytes(6)).'.json';
        $privateToken = 'private-assertion-evidence-token';
        mkdir($resultDir, 0777, true);
        $evidencePath = $resultDir.'/workflow-lifecycle-evidence.json';
        $sidecarPath = $resultDir.'/php-sdk-lifecycle-evidence.json';

        $hostEvidence = $this->hostEvidence();
        unset($hostEvidence['scenario_results']['php_sdk_lifecycle_surface']);
        file_put_contents($evidencePath, json_encode($hostEvidence, JSON_THROW_ON_ERROR));
        $this->writeRustSidecar($resultDir);

        $assertionFailures = php_sdk_assertion_failure_evidence(
            ['signal_query', 'signal_negative_contracts', 'update'],
            [
                'signal_query' => 'server',
                'signal_negative_contracts' => 'server',
                'update' => 'sdk',
            ],
            [
                'signal_query' => [
                    'signals_sent' => 2,
                    'accepted_inputs' => [3, 5],
                    'query_result' => [
                        'inputs' => [3, 5],
                        'total' => 7,
                        'decoder_message' => 'token='.$privateToken,
                    ],
                    'history_inputs' => [3, 5],
                    'unknown_signal' => [
                        'exception_type' => 'DurableWorkflow\\Exception\\ServerException',
                        'status_code' => 500,
                        'reason' => 'internal_error',
                    ],
                    'invalid_signal_arguments' => [
                        'exception_type' => 'DurableWorkflow\\Exception\\ServerException',
                        'status_code' => 400,
                        'reason' => 'invalid_request',
                    ],
                ],
                'update' => [
                    'result' => [
                        'accepted' => false,
                        'value' => null,
                        'decoder_message' => 'Authorization: Bearer '.$privateToken,
                    ],
                ],
                'worker_operation_responses' => [
                    'workflow.query:current' => ['inputs' => [3, 5], 'total' => 8],
                    'workflow.update:set' => ['accepted' => true, 'value' => 13],
                ],
            ],
        );
        file_put_contents($sidecarPath, json_encode([
            'schema' => 'durable-workflow.v2.workflow-lifecycle.php-sdk-sidecar',
            'runner_blocked' => false,
            'scenario_results' => [
                'php_sdk_lifecycle_surface' => [
                    'status' => 'fail',
                    'classification' => 'product-gap',
                    'published_artifact_cell_executed' => true,
                    'observed_outputs' => $this->outputsForScenario('php_sdk_lifecycle_surface') + [
                        'failure_stage' => 'runtime_assertions',
                        'failure_owner' => 'multiple_product_surfaces',
                        'failure_summary' => 'Failed lifecycle assertions: signal_query, signal_negative_contracts, update',
                        'operation' => 'multiple_lifecycle_operations',
                        'process_state' => [
                            'process' => 'php-sdk-aggregate',
                            'state' => 'exited',
                            'outcome' => 'assertion_failure',
                            'alive' => false,
                            'exit_code' => 1,
                        ],
                        'failures' => ['signal_query', 'signal_negative_contracts', 'update'],
                        'assertion_failures' => $assertionFailures,
                    ],
                    'linked_findings' => [
                        [
                            'finding_id' => 'php-sdk-published-artifact-server-failure',
                            'finding_type' => 'product_behavior_gap',
                            'classification' => 'server',
                            'owning_surface' => 'server',
                            'summary' => 'Failed server assertions: signal_query, signal_negative_contracts',
                            'observed_evidence' => [
                                'assertion_failures' => array_slice($assertionFailures, 0, 4),
                            ],
                        ],
                        [
                            'finding_id' => 'php-sdk-published-artifact-sdk-failure',
                            'finding_type' => 'product_behavior_gap',
                            'classification' => 'sdk',
                            'owning_surface' => 'sdk-php',
                            'summary' => 'Failed SDK assertions: update',
                            'observed_evidence' => [
                                'assertion_failures' => array_slice($assertionFailures, 4),
                            ],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            exec($this->runnerCommand($resultDir, [
                'DW_WORKFLOW_LIFECYCLE_EVIDENCE_PATH' => $evidencePath,
                'DW_WORKFLOW_LIFECYCLE_AUTH_TOKEN' => $privateToken,
                'DW_WORKFLOW_LIFECYCLE_SKIP_PHP_SDK_PROBE' => '1',
            ]), $output, $exitCode);

            $this->assertSame(0, $exitCode, implode("\n", $output));
            file_put_contents(
                $retainedRecord,
                (string) file_get_contents($resultDir.'/workflow-lifecycle-record.json'),
            );
            $this->removeDirectory($resultDir);

            $record = $this->readJson($retainedRecord);
            $diagnostic = $record['shardDiagnostics']['php_sdk_lifecycle_surface'];
            $php = $record['scenarioResults']['php_sdk_lifecycle_surface'];
            $byOperation = array_column($diagnostic['assertion_failures']['operations'], null, 'operation');
            $encodedDiagnostic = json_encode($diagnostic, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            $this->assertSame('non_passing', $record['outcome']);
            $this->assertFalse($record['runnerBlocked']);
            $this->assertSame('multiple_lifecycle_operations', $diagnostic['operation']);
            $this->assertSame('exited', $diagnostic['process_state']['state']);
            $this->assertSame('assertion_failure', $diagnostic['process_state']['outcome']);
            $this->assertSame(1, $diagnostic['process_state']['exit_code']);
            $this->assertSame(5, $diagnostic['assertion_failures']['count']);
            $this->assertSame(
                8,
                $byOperation['workflow.query:current']['observed']['worker_callback_response']['total'],
            );
            $this->assertSame(
                7,
                $byOperation['workflow.query:current']['observed']['sdk_decoded_response']['total'],
            );
            $this->assertSame(
                500,
                $byOperation['workflow.signal:undeclared']['observed']['status_code'],
            );
            $this->assertSame(
                'invalid_request',
                $byOperation['workflow.signal:increment_invalid_arguments']['observed']['reason'],
            );
            $this->assertTrue(
                $byOperation['workflow.update:set']['observed']['worker_callback_response']['accepted'],
            );
            $this->assertFalse(
                $byOperation['workflow.update:set']['observed']['sdk_decoded_response']['accepted'],
            );
            $this->assertSame(['server', 'sdk-php'], array_column($php['linked_findings'], 'owning_surface'));
            $this->assertLessThanOrEqual(8192, strlen($encodedDiagnostic));
            $this->assertStringNotContainsString($privateToken, (string) file_get_contents($retainedRecord));
            $this->assertFileDoesNotExist($sidecarPath);
            $this->assertSame([], WorkflowLifecycleResultGate::evaluate($record['result'])['gate_failures']);
        } finally {
            $this->removeDirectory($resultDir);
            @unlink($retainedRecord);
        }
    }

    public function test_retained_shard_diagnostic_redacts_structured_scalars_and_caps_final_serialization(): void
    {
        $resultDir = sys_get_temp_dir().'/dw-workflow-lifecycle-'.bin2hex(random_bytes(6));
        $retainedRecord = sys_get_temp_dir().'/dw-workflow-lifecycle-retained-'.bin2hex(random_bytes(6)).'.json';
        mkdir($resultDir, 0777, true);
        $evidencePath = $resultDir.'/workflow-lifecycle-evidence.json';
        $sidecarPath = $resultDir.'/php-sdk-lifecycle-evidence.json';
        $privateCredential = 'private-structured-diagnostic-credential';
        $largeProcessScalar = 'php-worker token='.$privateCredential.' '.str_repeat('p', 12288);
        $largeOwnerScalar = 'sdk-php credential='.$privateCredential.' '.str_repeat('o', 12288);
        $rawAdversarialInput = $largeProcessScalar.$largeOwnerScalar;
        $oversizedObservation = str_repeat('x', 4096);

        $hostEvidence = $this->hostEvidence();
        unset($hostEvidence['scenario_results']['php_sdk_lifecycle_surface']);
        file_put_contents($evidencePath, json_encode($hostEvidence, JSON_THROW_ON_ERROR));
        $this->writeRustSidecar($resultDir);
        file_put_contents($sidecarPath, json_encode([
            'schema' => 'durable-workflow.v2.workflow-lifecycle.php-sdk-sidecar',
            'runner_blocked' => false,
            'scenario_results' => [
                'php_sdk_lifecycle_surface' => [
                    'status' => 'fail',
                    'classification' => 'product-gap',
                    'published_artifact_cell_executed' => true,
                    'observed_outputs' => $this->outputsForScenario('php_sdk_lifecycle_surface') + [
                        'failure_stage' => 'worker_readiness_timeout',
                        'failure_owner' => $largeOwnerScalar,
                        'failure_summary' => 'The released PHP SDK worker did not satisfy readiness.',
                        'failure_diagnostic' => [
                            'excerpt' => 'token='.$privateCredential.' '.str_repeat('diagnostic ', 512),
                        ],
                        'process_state' => [
                            'process' => $largeProcessScalar,
                            'state' => 'alive',
                            'owner' => $largeOwnerScalar,
                        ],
                        'runtime_failure_evidence' => [
                            'classification' => 'sdk',
                            'owning_surface' => $largeOwnerScalar,
                            'process' => $largeProcessScalar,
                            'operation' => 'worker.registration.readiness',
                            'status_code' => 409,
                            'public_error_envelope' => [
                                'reason' => 'worker_contract_mismatch',
                                'credential' => $privateCredential,
                            ],
                        ],
                        'worker_startup' => [
                            'outcome' => 'readiness_timeout',
                            'attempts' => 100,
                            'process_alive_at_failure' => true,
                            'readiness_observation' => [
                                'readiness_mismatch' => [
                                    'reason' => 'authoritative_workflow_command_contract_mismatch',
                                    'workflow_type' => 'php.sdk.waiting',
                                    'expected_contract' => ['updates' => ['set']],
                                    'observed_contract' => ['updates' => []],
                                    'oversized_observation' => $oversizedObservation,
                                    'owner' => 'sdk-php api_key='.$privateCredential,
                                ],
                                'last_server_observation' => [
                                    'http_status' => 409,
                                    'payload' => [
                                        'reason' => 'worker_contract_mismatch',
                                        'token' => $privateCredential,
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'linked_findings' => [[
                        'finding_id' => 'php-sdk-worker-contract-mismatch',
                        'finding_type' => 'product_behavior_gap',
                        'classification' => 'product-gap',
                        'owning_surface' => 'sdk-php credential='.$privateCredential,
                        'summary' => 'The released PHP SDK worker did not satisfy readiness.',
                        'next_acceptance_criterion' => 'Correct the SDK worker command contract.',
                    ]],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            $this->assertGreaterThanOrEqual(24566, strlen($rawAdversarialInput));
            exec($this->runnerCommand($resultDir, [
                'DW_WORKFLOW_LIFECYCLE_EVIDENCE_PATH' => $evidencePath,
                'DW_WORKFLOW_LIFECYCLE_SKIP_PHP_SDK_PROBE' => '1',
            ]), $output, $exitCode);

            $this->assertSame(0, $exitCode, implode("\n", $output));
            file_put_contents(
                $retainedRecord,
                (string) file_get_contents($resultDir.'/workflow-lifecycle-record.json'),
            );
            $this->removeDirectory($resultDir);

            $retainedJson = (string) file_get_contents($retainedRecord);
            $record = $this->readJson($retainedRecord);
            $diagnostic = $record['shardDiagnostics']['php_sdk_lifecycle_surface'];
            $php = $record['scenarioResults']['php_sdk_lifecycle_surface'];
            $finding = $php['linked_findings'][0];
            $encodedDiagnostic = json_encode($diagnostic, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            $this->assertSame('non_passing', $record['outcome']);
            $this->assertFalse($record['runnerBlocked']);
            $this->assertSame('fail', $php['status']);
            $this->assertSame('product-gap', $php['classification']);
            $this->assertStringContainsString('sdk-php', $diagnostic['owning_surface']);
            $this->assertLessThanOrEqual(128, strlen($diagnostic['owning_surface']));
            $this->assertStringContainsString('php-worker', $diagnostic['process_state']['process']);
            $this->assertLessThanOrEqual(128, strlen($diagnostic['process_state']['process']));
            $this->assertSame('worker.registration.readiness', $diagnostic['operation']);
            $this->assertSame(409, $diagnostic['http']['status']);
            $this->assertSame('worker_contract_mismatch', $diagnostic['http']['reason']);
            $this->assertSame(
                'authoritative_workflow_command_contract_mismatch',
                $diagnostic['readiness']['mismatch']['reason'],
            );
            $this->assertSame(
                ['updates' => ['set']],
                $diagnostic['readiness']['mismatch']['expected_contract'],
            );
            $this->assertSame(
                ['updates' => []],
                $diagnostic['readiness']['mismatch']['observed_contract'],
            );
            $this->assertSame(
                'worker_contract_mismatch',
                $diagnostic['readiness']['last_server_observation']['payload']['reason'],
            );
            $this->assertSame(
                '[REDACTED]',
                $diagnostic['readiness']['last_server_observation']['payload']['token'],
            );
            $this->assertTrue($diagnostic['truncated']);
            $this->assertTrue($diagnostic['readiness']['mismatch']['_truncated']);
            $this->assertStringContainsString('sdk-php', $finding['owning_surface']);
            $this->assertSame($diagnostic, $finding['observed_evidence']['shard_diagnostic']);
            $this->assertLessThanOrEqual(8192, strlen($encodedDiagnostic));
            $this->assertStringContainsString('[REDACTED]', $retainedJson);
            $this->assertStringNotContainsString($privateCredential, $retainedJson);
            $this->assertStringNotContainsString(str_repeat('x', 8192), $retainedJson);
            $this->assertSame([], WorkflowLifecycleResultGate::evaluate($record['result'])['gate_failures']);
            $this->assertFileDoesNotExist($sidecarPath);
        } finally {
            $this->removeDirectory($resultDir);
            @unlink($retainedRecord);
        }
    }

    public function test_live_php_readiness_http_failure_is_queryable_after_runtime_cleanup(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the PHP lifecycle failure writer.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $runner = (string) file_get_contents($repoRoot.'/scripts/conformance/php-sdk-published-artifacts.sh');
        $matched = preg_match(
            "~write_failure\\(\\) \\{.*?node <<'NODE'\n(.*?)\nNODE\n\\}~s",
            $runner,
            $matches,
        );
        $this->assertSame(1, $matched);

        $resultDir = sys_get_temp_dir().'/dw-workflow-lifecycle-'.bin2hex(random_bytes(6));
        $retainedRecord = sys_get_temp_dir().'/dw-workflow-lifecycle-retained-'.bin2hex(random_bytes(6)).'.json';
        mkdir($resultDir, 0777, true);
        $evidencePath = $resultDir.'/workflow-lifecycle-evidence.json';
        $observationPath = $resultDir.'/php-sdk-worker-php-sdk-worker-1.readiness-observation.json';
        $diagnosticPath = $resultDir.'/php-sdk-worker-1.diagnostic.log';
        $privateToken = 'private-readiness-token';

        $hostEvidence = $this->hostEvidence();
        unset($hostEvidence['scenario_results']['php_sdk_lifecycle_surface']);
        file_put_contents($evidencePath, json_encode($hostEvidence, JSON_THROW_ON_ERROR));
        $this->writeRustSidecar($resultDir);
        file_put_contents($diagnosticPath, "Worker readiness lookup failed with HTTP 503. token={$privateToken}\n");
        file_put_contents($observationPath, json_encode([
            'required_workflow_command_contract' => [
                'workflow_type' => 'php.sdk.waiting',
                'queries' => ['current'],
                'updates' => ['set'],
            ],
            'readiness_mismatch' => [
                'reason' => 'worker_readiness_http_response',
                'expected_http_status' => '2xx',
                'observed_http_status' => 503,
                'public_reason' => 'registration rejected by the server',
            ],
            'last_server_observation' => [
                'observed_at' => '2026-07-16T00:00:10Z',
                'http_status' => 503,
                'payload' => [
                    'error' => 'registration_rejected',
                    'reason' => 'unsupported_worker_protocol',
                    'token' => $privateToken,
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $environment = array_merge($_ENV, [
            'RESULT_DIR' => $resultDir,
            'SDK_VERSION' => '0.1.1',
            'SERVER_VERSION' => '0.2.649',
            'SERVER_IMAGE' => 'durableworkflow/server:0.2.649',
            'SERVER_URL' => 'http://server.test',
            'NAMESPACE' => 'workflow-lifecycle-conformance',
            'STARTED_AT' => '2026-07-16T00:00:00Z',
            'FAILURE_CLASSIFICATION' => 'server',
            'FAILURE_OWNER' => 'server',
            'FAILURE_STAGE' => 'worker_readiness_probe',
            'FAILURE_SUMMARY' => 'generic readiness failure',
            'FAILURE_DIAGNOSTIC_FILE' => $diagnosticPath,
            'FAILURE_EVIDENCE_HELPER' => $repoRoot.'/scripts/conformance/php-sdk-runtime-failure-evidence.cjs',
            'WORKER_START_OUTCOME' => 'readiness_probe_failure',
            'WORKER_START_WORKER_ID' => 'php-sdk-worker-1',
            'WORKER_START_ATTEMPTS' => '7',
            'WORKER_START_PROCESS_ID' => '4321',
            'WORKER_START_PROCESS_ALIVE' => 'true',
            'WORKER_START_PROCESS_EXIT_CODE' => '',
            'WORKER_START_OBSERVATION_FILE' => $observationPath,
            'CONTROL_TOKEN' => $privateToken,
            'WORKER_TOKEN' => 'private-worker-token',
        ]);

        try {
            $process = proc_open(
                [$nodeBinary, '-e', $matches[1]],
                [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                $repoRoot,
                $environment,
            );
            $this->assertIsResource($process);
            stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), (string) $stderr);
            $this->assertFileExists($resultDir.'/php-sdk-lifecycle-evidence.json');

            unlink($observationPath);
            unlink($diagnosticPath);
            exec($this->runnerCommand($resultDir, [
                'DW_WORKFLOW_LIFECYCLE_EVIDENCE_PATH' => $evidencePath,
                'DW_WORKFLOW_LIFECYCLE_AUTH_TOKEN' => $privateToken,
                'DW_WORKFLOW_LIFECYCLE_SKIP_PHP_SDK_PROBE' => '1',
            ]), $output, $exitCode);
            $this->assertSame(0, $exitCode, implode("\n", $output));

            file_put_contents(
                $retainedRecord,
                (string) file_get_contents($resultDir.'/workflow-lifecycle-record.json'),
            );
            $this->removeDirectory($resultDir);

            $record = $this->readJson($retainedRecord);
            $diagnostic = $record['shardDiagnostics']['php_sdk_lifecycle_surface'];
            $php = $record['scenarioResults']['php_sdk_lifecycle_surface'];
            $finding = $php['linked_findings'][0];

            $this->assertSame('non_passing', $record['outcome']);
            $this->assertFalse($record['runnerBlocked']);
            $this->assertSame('worker.registration.readiness', $diagnostic['operation']);
            $this->assertSame('alive', $diagnostic['process_state']['state']);
            $this->assertSame('readiness_probe_failure', $diagnostic['process_state']['outcome']);
            $this->assertSame(503, $diagnostic['http']['status']);
            $this->assertSame('unsupported_worker_protocol', $diagnostic['http']['reason']);
            $this->assertSame(
                'worker_readiness_http_response',
                $diagnostic['readiness']['mismatch']['reason'],
            );
            $this->assertSame(
                'registration_rejected',
                $diagnostic['readiness']['last_server_observation']['payload']['error'],
            );
            $this->assertSame('server', $diagnostic['owning_surface']);
            $this->assertSame('server', $finding['owning_surface']);
            $this->assertSame(503, $finding['observed_evidence']['status_code']);
            $this->assertSame($diagnostic, $finding['observed_evidence']['shard_diagnostic']);
            $this->assertStringNotContainsString($privateToken, (string) file_get_contents($retainedRecord));
            $this->assertStringNotContainsString($observationPath, (string) file_get_contents($retainedRecord));
            $this->assertFileDoesNotExist($observationPath);
            $this->assertFileDoesNotExist($diagnosticPath);
        } finally {
            $this->removeDirectory($resultDir);
            @unlink($retainedRecord);
        }
    }

    public function test_client_timeout_retains_no_poller_queue_and_current_lease_evidence_after_cleanup(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise PHP companion diagnostics.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $runner = (string) file_get_contents($repoRoot.'/scripts/conformance/php-sdk-published-artifacts.sh');
        $matched = preg_match(
            "~write_failure\\(\\) \\{.*?node <<'NODE'\n(.*?)\nNODE\n\\}~s",
            $runner,
            $matches,
        );
        $this->assertSame(1, $matched);

        $resultDir = sys_get_temp_dir().'/dw-workflow-lifecycle-'.bin2hex(random_bytes(6));
        $retainedRecord = sys_get_temp_dir().'/dw-workflow-lifecycle-retained-'.bin2hex(random_bytes(6)).'.json';
        mkdir($resultDir, 0777, true);
        $evidencePath = $resultDir.'/workflow-lifecycle-evidence.json';
        $clientDiagnosticPath = $resultDir.'/php-sdk-client-baseline.diagnostic.log';
        $workerLogPath = $resultDir.'/php-sdk-worker-1.log';
        $serverLogPath = $resultDir.'/workflow-lifecycle-server-process.log';
        $probeFixturePath = $resultDir.'/companion-probes.json';
        $companionPath = $resultDir.'/php-sdk-worker-1-companion-failure.json';
        $privateToken = 'private-timeout-companion-token';
        $workflowId = 'php-sdk-failed-retained';
        $runId = '01retainedtimeoutdiagnostic';

        $hostEvidence = $this->hostEvidence();
        unset($hostEvidence['scenario_results']['php_sdk_lifecycle_surface']);
        file_put_contents($evidencePath, json_encode($hostEvidence, JSON_THROW_ON_ERROR));
        $this->writeRustSidecar($resultDir);
        $clientFailure = [
            'classification' => 'sdk',
            'owning_surface' => 'sdk-php',
            'process' => 'client',
            'operation' => 'workflow.result:failed',
            'http_method' => 'GET',
            'endpoint' => '/api/workflows/{workflow_id}/runs/{run_id}/result',
            'status_code' => null,
            'public_error_envelope' => null,
            'workflow_id' => $workflowId,
            'run_id' => $runId,
            'exception_type' => 'DurableWorkflow\\Exception\\WorkflowTimedOut',
            'message' => 'Timed out waiting for the intentional failure workflow; token='.$privateToken,
        ];
        file_put_contents(
            $clientDiagnosticPath,
            'DW_PHP_SDK_RUNTIME_FAILURE='.json_encode($clientFailure, JSON_THROW_ON_ERROR)."\n",
        );
        file_put_contents($workerLogPath, 'Worker remains active; Authorization: Bearer '.$privateToken."\n");
        file_put_contents($serverLogPath, 'Dispatch scan left the run pending; token='.$privateToken."\n");
        file_put_contents($probeFixturePath, json_encode([
            'health' => ['http_status' => 200, 'payload' => ['status' => 'serving', 'checks' => ['database' => 'ok']]],
            'worker' => ['http_status' => 200, 'payload' => [
                'worker_id' => 'php-sdk-worker-1',
                'task_queue' => 'php-sdk-conformance-retained',
                'status' => 'active',
                'last_heartbeat_at' => '2026-07-17T23:50:00Z',
            ]],
            'run' => ['http_status' => 200, 'payload' => [
                'workflow_id' => $workflowId,
                'run_id' => $runId,
                'status' => 'running',
                'is_terminal' => false,
                'task_queue' => 'php-sdk-conformance-retained',
            ]],
            'history' => ['http_status' => 200, 'payload' => [
                'workflow_id' => $workflowId,
                'run_id' => $runId,
                'events' => [
                    ['sequence' => 1, 'event_type' => 'WorkflowStarted'],
                    ['sequence' => 2, 'event_type' => 'WorkflowTaskScheduled'],
                ],
            ]],
            'task_queue' => ['http_status' => 200, 'payload' => [
                'name' => 'php-sdk-conformance-retained',
                'stats' => [
                    'approximate_backlog_count' => 1,
                    'tasks_added_last_minute' => 12,
                    'tasks_dispatched_last_minute' => 11,
                    'workflow_tasks' => [
                        'ready_count' => 0,
                        'leased_count' => 1,
                        'expired_lease_count' => 0,
                    ],
                    'activity_tasks' => [
                        'ready_count' => 0,
                        'leased_count' => 0,
                        'expired_lease_count' => 0,
                    ],
                    'pollers' => ['active_count' => 0, 'stale_count' => 0],
                ],
                'pollers' => [],
                'current_leases' => [[
                    'task_id' => 'workflow-task-retained',
                    'task_type' => 'workflow',
                    'workflow_id' => $workflowId,
                    'run_id' => $runId,
                    'lease_owner' => 'php-sdk-worker-1',
                    'lease_expires_at' => '2026-07-17T23:50:30Z',
                    'is_expired' => false,
                    'workflow_task_attempt' => 1,
                    'activity_attempt_id' => null,
                    'attempt_number' => 1,
                ]],
                'admission' => [
                    'workflow_tasks' => [
                        'status' => 'worker_unavailable',
                        'budget_source' => 'worker_registration.max_concurrent_workflow_tasks',
                        'active_worker_count' => 0,
                        'configured_slot_count' => 10,
                        'leased_count' => 1,
                        'ready_count' => 0,
                        'available_slot_count' => 9,
                        'server_active_lease_count' => 1,
                        'server_remaining_active_lease_capacity' => 9,
                        'approximate_pending_count' => 0,
                        'remaining_pending_capacity' => 1024,
                    ],
                    'activity_tasks' => [
                        'status' => 'worker_unavailable',
                        'budget_source' => 'worker_registration.max_concurrent_activity_tasks',
                        'ready_count' => 0,
                    ],
                    'query_tasks' => ['status' => 'worker_unavailable'],
                ],
                'reason' => 'no_active_workers',
                'message' => 'No active poller is currently available while a workflow task lease remains observable.',
            ]],
        ], JSON_THROW_ON_ERROR));

        $runNode = static function (array $command, array $environment) use ($repoRoot): array {
            $process = proc_open(
                $command,
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $repoRoot,
                array_merge($_ENV, $environment),
            );
            if (! is_resource($process)) {
                return [1, '', 'Unable to start node.'];
            }
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            return [proc_close($process), (string) $stdout, (string) $stderr];
        };

        try {
            [$companionExit, , $companionStderr] = $runNode(
                [$nodeBinary, $repoRoot.'/scripts/conformance/php-sdk-companion-failure-evidence.cjs'],
                [
                    'CLIENT_DIAGNOSTIC_FILE' => $clientDiagnosticPath,
                    'COMPANION_OUTPUT_FILE' => $companionPath,
                    'COMPANION_WORKER_ID' => 'php-sdk-worker-1',
                    'COMPANION_WORKER_LOG' => $workerLogPath,
                    'COMPANION_WORKER_ALIVE' => 'true',
                    'COMPANION_WORKER_EXIT_CODE' => '',
                    'COMPANION_TASK_QUEUE' => 'php-sdk-conformance-retained',
                    'COMPANION_SERVER_LOG' => $serverLogPath,
                    'COMPANION_PROBE_FIXTURE' => $probeFixturePath,
                    'SERVER_URL' => 'http://server.test',
                    'NAMESPACE' => 'workflow-lifecycle-conformance',
                    'CONTROL_TOKEN' => $privateToken,
                    'WORKER_TOKEN' => 'private-worker-token',
                ],
            );
            $this->assertSame(0, $companionExit, $companionStderr);
            $generatedCompanion = $this->readJson($companionPath);
            $generatedTaskQueueBytes = strlen(json_encode(
                $generatedCompanion['server']['task_queue'],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
            $this->assertGreaterThan(1280, $generatedTaskQueueBytes);
            $this->assertLessThanOrEqual(1536, $generatedTaskQueueBytes);

            [$writerExit, , $writerStderr] = $runNode(
                [$nodeBinary, '-e', $matches[1]],
                [
                    'RESULT_DIR' => $resultDir,
                    'SDK_VERSION' => '0.1.10',
                    'SERVER_VERSION' => '0.2.678',
                    'SERVER_IMAGE' => 'durableworkflow/server:0.2.678',
                    'SERVER_URL' => 'http://server.test',
                    'NAMESPACE' => 'workflow-lifecycle-conformance',
                    'STARTED_AT' => '2026-07-17T23:49:00Z',
                    'FAILURE_CLASSIFICATION' => 'sdk',
                    'FAILURE_OWNER' => 'sdk-php',
                    'FAILURE_STAGE' => 'baseline_client',
                    'FAILURE_SUMMARY' => 'The released PHP SDK client timed out.',
                    'FAILURE_DIAGNOSTIC_FILE' => $clientDiagnosticPath,
                    'FAILURE_COMPANION_FILE' => $companionPath,
                    'FAILURE_EVIDENCE_HELPER' => $repoRoot.'/scripts/conformance/php-sdk-runtime-failure-evidence.cjs',
                    'DISTRIBUTION_IDENTITY_FILE' => '',
                    'WORKER_START_OUTCOME' => '',
                    'WORKER_START_WORKER_ID' => '',
                    'WORKER_START_ATTEMPTS' => '',
                    'WORKER_START_PROCESS_ID' => '',
                    'WORKER_START_PROCESS_ALIVE' => '',
                    'WORKER_START_PROCESS_EXIT_CODE' => '',
                    'WORKER_START_OBSERVATION_FILE' => '',
                    'CONTROL_TOKEN' => $privateToken,
                    'WORKER_TOKEN' => 'private-worker-token',
                ],
            );
            $this->assertSame(0, $writerExit, $writerStderr);

            unlink($clientDiagnosticPath);
            unlink($workerLogPath);
            unlink($serverLogPath);
            unlink($probeFixturePath);
            unlink($companionPath);
            exec($this->runnerCommand($resultDir, [
                'DW_WORKFLOW_LIFECYCLE_EVIDENCE_PATH' => $evidencePath,
                'DW_WORKFLOW_LIFECYCLE_AUTH_TOKEN' => $privateToken,
                'DW_WORKFLOW_LIFECYCLE_SKIP_PHP_SDK_PROBE' => '1',
            ]), $output, $exitCode);
            $this->assertSame(0, $exitCode, implode("\n", $output));

            file_put_contents(
                $retainedRecord,
                (string) file_get_contents($resultDir.'/workflow-lifecycle-record.json'),
            );
            $this->removeDirectory($resultDir);

            $retainedJson = (string) file_get_contents($retainedRecord);
            $record = $this->readJson($retainedRecord);
            $php = $record['scenarioResults']['php_sdk_lifecycle_surface'];
            $rust = $record['scenarioResults']['rust_sdk_lifecycle_surface'];
            $diagnostic = $record['shardDiagnostics']['php_sdk_lifecycle_surface'];
            $companion = $diagnostic['companion_failure'];

            $this->assertSame('non_passing', $record['outcome']);
            $this->assertFalse($record['runnerBlocked']);
            $this->assertSame('fail', $php['status']);
            $this->assertSame('pass', $rust['status']);
            $this->assertArrayNotHasKey('rust_sdk_lifecycle_surface', $record['shardDiagnostics']);
            $this->assertSame('server', $diagnostic['owning_surface']);
            $this->assertSame('alive', $diagnostic['process_state']['state']);
            $this->assertTrue($diagnostic['process_state']['alive']);
            $this->assertSame('client_timeout', $companion['failure_kind']);
            $this->assertSame('worker_alive_run_not_terminal', $companion['classification_basis']);
            $this->assertSame(200, $companion['server']['health']['http_status']);
            $this->assertSame('running', $companion['server']['run_state']['payload']['status']);
            $this->assertContains(
                'WorkflowTaskScheduled',
                $companion['server']['history']['payload']['last_event_types'],
            );
            $this->assertSame('active', $companion['worker']['server_registration']['payload']['status']);
            $this->assertSame(
                'php-sdk-conformance-retained',
                $companion['server']['task_queue']['payload']['name'],
            );
            $this->assertSame(
                0,
                $companion['server']['task_queue']['payload']['stats']['workflow_tasks']['ready_count'],
            );
            $this->assertSame([], $companion['server']['task_queue']['payload']['pollers']);
            $this->assertSame(
                'workflow-task-retained',
                $companion['server']['task_queue']['payload']['current_leases'][0]['task_id'],
            );
            $this->assertSame(
                'worker_unavailable',
                $companion['server']['task_queue']['payload']['admission']['workflow_tasks']['status'],
            );
            $this->assertArrayNotHasKey(
                'bounded_json_excerpt',
                $companion['server']['task_queue'],
            );
            $this->assertLessThanOrEqual(
                1280,
                strlen(json_encode(
                    $companion['server']['task_queue'],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                )),
            );
            $this->assertStringContainsString('[REDACTED]', $companion['worker']['structured_stderr']['excerpt']);
            $this->assertStringContainsString('[REDACTED]', $companion['server']['process_log']['excerpt']);
            $this->assertLessThanOrEqual(
                8192,
                strlen(json_encode($diagnostic, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            );
            $this->assertStringNotContainsString($privateToken, $retainedJson);
            $this->assertSame([], WorkflowLifecycleResultGate::evaluate($record['result'])['gate_failures']);
            $this->assertFileDoesNotExist($resultDir);
        } finally {
            $this->removeDirectory($resultDir);
            @unlink($retainedRecord);
        }
    }

    public function test_alive_worker_terminal_failed_run_is_not_classified_as_a_server_stall(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise PHP companion diagnostics.');
        }

        $helper = dirname(__DIR__, 2).'/scripts/conformance/php-sdk-companion-failure-evidence.cjs';
        $input = [
            'clientDiagnostic' => 'DW_PHP_SDK_RUNTIME_FAILURE='.json_encode([
                'classification' => 'sdk',
                'owning_surface' => 'sdk-php',
                'process' => 'client',
                'operation' => 'workflow.result:failed',
                'workflow_id' => 'php-sdk-intentional-failure',
                'run_id' => '01terminalfailure',
                'exception_type' => 'DurableWorkflow\\Exception\\WorkflowTimedOut',
                'message' => 'Timed out after the server had closed the run.',
            ], JSON_THROW_ON_ERROR)."\n",
            'workerDiagnostic' => 'The companion worker remained alive without a protocol exception.',
            'serverLog' => 'The intentional failure committed a terminal event.',
            'workerId' => 'php-sdk-worker-1',
            'processAlive' => true,
            'processExitCode' => null,
            'probes' => [
                'health' => ['http_status' => 200, 'payload' => ['status' => 'serving']],
                'worker' => ['http_status' => 200, 'payload' => [
                    'worker_id' => 'php-sdk-worker-1',
                    'status' => 'active',
                ]],
                'run' => ['http_status' => 200, 'payload' => [
                    'workflow_id' => 'php-sdk-intentional-failure',
                    'run_id' => '01terminalfailure',
                    'status' => 'failed',
                    'status_bucket' => 'failed',
                    'is_terminal' => true,
                    'closed_reason' => 'workflow_failed',
                ]],
                'history' => ['http_status' => 200, 'payload' => [
                    'workflow_id' => 'php-sdk-intentional-failure',
                    'run_id' => '01terminalfailure',
                    'events' => [
                        ['sequence' => 1, 'event_type' => 'WorkflowStarted'],
                        ['sequence' => 2, 'event_type' => 'WorkflowTaskScheduled'],
                        ['sequence' => 3, 'event_type' => 'WorkflowFailed', 'payload' => [
                            'exception_type' => 'RuntimeException',
                            'message' => 'intentional failure',
                        ]],
                    ],
                ]],
                'task_queue' => ['http_status' => 200, 'payload' => [
                    'name' => 'php-sdk-conformance-retained',
                    'stats' => [
                        'approximate_backlog_count' => 0,
                        'workflow_tasks' => ['ready_count' => 0, 'leased_count' => 0],
                        'pollers' => ['active_count' => 1, 'stale_count' => 0],
                    ],
                    'pollers' => [[
                        'worker_id' => 'php-sdk-worker-1',
                        'status' => 'active',
                        'is_stale' => false,
                    ]],
                    'current_leases' => [],
                    'admission' => [
                        'workflow_tasks' => ['status' => 'accepting'],
                        'activity_tasks' => ['status' => 'no_slots'],
                        'query_tasks' => ['status' => 'accepting'],
                    ],
                ]],
            ],
        ];
        $program = <<<'NODE'
const fs = require('node:fs');
const {createCompanionFailureEvidence} = require(process.argv[1]);
const input = JSON.parse(fs.readFileSync(0, 'utf8'));
process.stdout.write(JSON.stringify(createCompanionFailureEvidence(input)));
NODE;
        $process = proc_open(
            [$nodeBinary, '-e', $program, $helper],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);
        fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), (string) $stderr);

        $evidence = json_decode((string) $stdout, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('sdk', $evidence['classification']);
        $this->assertSame('sdk-php', $evidence['owning_surface']);
        $this->assertSame('worker_alive_run_terminal_client_failure', $evidence['classification_basis']);
        $this->assertSame('failed', $evidence['server']['run_state']['payload']['status']);
        $this->assertTrue($evidence['server']['run_state']['payload']['is_terminal']);
        $this->assertContains(
            'WorkflowFailed',
            $evidence['server']['history']['payload']['last_event_types'],
        );
        $this->assertSame(
            'php-sdk-conformance-retained',
            $evidence['server']['task_queue']['payload']['name'],
        );
        $this->assertLessThanOrEqual(
            6144,
            strlen(json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        );
    }

    public function test_php_worker_exit_after_transient_readiness_response_retains_sdk_crash_evidence(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the PHP lifecycle failure writer.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $runner = (string) file_get_contents($repoRoot.'/scripts/conformance/php-sdk-published-artifacts.sh');
        $matched = preg_match(
            "~write_failure\\(\\) \\{.*?node <<'NODE'\n(.*?)\nNODE\n\\}~s",
            $runner,
            $matches,
        );
        $this->assertSame(1, $matched);

        $resultDir = sys_get_temp_dir().'/dw-workflow-lifecycle-'.bin2hex(random_bytes(6));
        $retainedRecord = sys_get_temp_dir().'/dw-workflow-lifecycle-retained-'.bin2hex(random_bytes(6)).'.json';
        mkdir($resultDir, 0777, true);
        $evidencePath = $resultDir.'/workflow-lifecycle-evidence.json';
        $observationPath = $resultDir.'/php-sdk-worker-php-sdk-worker-1.readiness-observation.json';
        $diagnosticPath = $resultDir.'/php-sdk-worker-1.diagnostic.log';
        $privateToken = 'private-worker-crash-token';

        $hostEvidence = $this->hostEvidence();
        unset($hostEvidence['scenario_results']['php_sdk_lifecycle_surface']);
        file_put_contents($evidencePath, json_encode($hostEvidence, JSON_THROW_ON_ERROR));
        $this->writeRustSidecar($resultDir);
        file_put_contents(
            $diagnosticPath,
            "[stdout: php-sdk-worker-1.log]\nFatal SDK worker bootstrap crash; token={$privateToken}\n"
                ."[stderr: php-sdk-worker-php-sdk-worker-1.readiness.log]\n"
                .'Worker readiness lookup failed with HTTP 404.'."\n",
        );
        file_put_contents($observationPath, json_encode([
            'required_workflow_command_contract' => [
                'workflow_type' => 'php.sdk.waiting',
                'queries' => ['current'],
                'updates' => ['set'],
            ],
            'readiness_mismatch' => [
                'reason' => 'worker_readiness_http_response',
                'expected_http_status' => '2xx',
                'observed_http_status' => 404,
                'public_reason' => 'worker registration is not visible yet',
            ],
            'last_server_observation' => [
                'observed_at' => '2026-07-16T00:00:01Z',
                'http_status' => 404,
                'payload' => [
                    'error' => 'worker_not_found',
                    'reason' => 'worker_registration_not_visible',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $environment = array_merge($_ENV, [
            'RESULT_DIR' => $resultDir,
            'SDK_VERSION' => '0.1.1',
            'SERVER_VERSION' => '0.2.649',
            'SERVER_IMAGE' => 'durableworkflow/server:0.2.649',
            'SERVER_URL' => 'http://server.test',
            'NAMESPACE' => 'workflow-lifecycle-conformance',
            'STARTED_AT' => '2026-07-16T00:00:00Z',
            'FAILURE_CLASSIFICATION' => 'server',
            'FAILURE_OWNER' => 'server',
            'FAILURE_STAGE' => 'worker_process_exit',
            'FAILURE_SUMMARY' => 'The readiness probe received a server HTTP failure.',
            'FAILURE_DIAGNOSTIC_FILE' => $diagnosticPath,
            'FAILURE_EVIDENCE_HELPER' => $repoRoot.'/scripts/conformance/php-sdk-runtime-failure-evidence.cjs',
            'WORKER_START_OUTCOME' => 'process_exit',
            'WORKER_START_WORKER_ID' => 'php-sdk-worker-1',
            'WORKER_START_ATTEMPTS' => '2',
            'WORKER_START_PROCESS_ID' => '4321',
            'WORKER_START_PROCESS_ALIVE' => 'false',
            'WORKER_START_PROCESS_EXIT_CODE' => '17',
            'WORKER_START_OBSERVATION_FILE' => $observationPath,
            'CONTROL_TOKEN' => $privateToken,
            'WORKER_TOKEN' => 'private-worker-token',
        ]);

        try {
            $process = proc_open(
                [$nodeBinary, '-e', $matches[1]],
                [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                $repoRoot,
                $environment,
            );
            $this->assertIsResource($process);
            stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), (string) $stderr);

            $sidecar = $this->readJson($resultDir.'/php-sdk-lifecycle-evidence.json');
            $sidecarScenario = $sidecar['scenario_results']['php_sdk_lifecycle_surface'];
            $sidecarOutputs = $sidecarScenario['observed_outputs'];
            $this->assertSame('sdk', $sidecarScenario['classification']);
            $this->assertSame('sdk-php', $sidecarOutputs['failure_owner']);
            $this->assertArrayNotHasKey('runtime_failure_evidence', $sidecarOutputs);
            $this->assertSame(17, $sidecarOutputs['worker_startup']['process_exit_code']);
            $this->assertStringContainsString('exited with code 17', $sidecarOutputs['failure_summary']);
            $this->assertStringNotContainsString('worker.registration.readiness', $sidecarOutputs['failure_summary']);
            $this->assertStringContainsString('Fatal SDK worker bootstrap crash', $sidecarOutputs['failure_diagnostic']['excerpt']);

            unlink($observationPath);
            unlink($diagnosticPath);
            exec($this->runnerCommand($resultDir, [
                'DW_WORKFLOW_LIFECYCLE_EVIDENCE_PATH' => $evidencePath,
                'DW_WORKFLOW_LIFECYCLE_AUTH_TOKEN' => $privateToken,
                'DW_WORKFLOW_LIFECYCLE_SKIP_PHP_SDK_PROBE' => '1',
            ]), $output, $exitCode);
            $this->assertSame(0, $exitCode, implode("\n", $output));

            file_put_contents(
                $retainedRecord,
                (string) file_get_contents($resultDir.'/workflow-lifecycle-record.json'),
            );
            $this->removeDirectory($resultDir);

            $record = $this->readJson($retainedRecord);
            $diagnostic = $record['shardDiagnostics']['php_sdk_lifecycle_surface'];
            $php = $record['scenarioResults']['php_sdk_lifecycle_surface'];
            $finding = $php['linked_findings'][0];

            $this->assertSame('non_passing', $record['outcome']);
            $this->assertFalse($record['runnerBlocked']);
            $this->assertSame('worker_process_exit', $diagnostic['operation']);
            $this->assertSame('exited', $diagnostic['process_state']['state']);
            $this->assertSame('process_exit', $diagnostic['process_state']['outcome']);
            $this->assertFalse($diagnostic['process_state']['alive']);
            $this->assertSame(17, $diagnostic['process_state']['exit_code']);
            $this->assertSame(404, $diagnostic['http']['status']);
            $this->assertSame(
                'worker_registration_not_visible',
                $diagnostic['http']['reason'],
            );
            $this->assertSame(
                'worker_readiness_http_response',
                $diagnostic['readiness']['mismatch']['reason'],
            );
            $this->assertSame('sdk-php', $diagnostic['owning_surface']);
            $this->assertSame('sdk-php', $finding['owning_surface']);
            $this->assertSame($diagnostic, $finding['observed_evidence']['shard_diagnostic']);
            $this->assertStringContainsString('Fatal SDK worker bootstrap crash', $diagnostic['excerpt']);
            $this->assertLessThanOrEqual(
                8192,
                strlen(json_encode($diagnostic, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            );
            $this->assertStringNotContainsString($privateToken, (string) file_get_contents($retainedRecord));
            $this->assertStringNotContainsString($observationPath, (string) file_get_contents($retainedRecord));
            $this->assertFileDoesNotExist($observationPath);
            $this->assertFileDoesNotExist($diagnosticPath);
        } finally {
            $this->removeDirectory($resultDir);
            @unlink($retainedRecord);
        }
    }

    public function test_timeout_companion_ownership_follows_worker_protocol_http_failure(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise PHP companion diagnostics.');
        }

        $helper = dirname(__DIR__, 2).'/scripts/conformance/php-sdk-companion-failure-evidence.cjs';
        $privateToken = 'private-worker-protocol-token';
        $runtimeFailure = static fn (array $payload): string => 'DW_PHP_SDK_RUNTIME_FAILURE='.
            json_encode($payload, JSON_THROW_ON_ERROR)."\n";
        $clientDiagnostic = $runtimeFailure([
            'classification' => 'sdk',
            'owning_surface' => 'sdk-php',
            'process' => 'client',
            'operation' => 'workflow.result:failed',
            'workflow_id' => 'php-sdk-failed-worker-exit',
            'run_id' => '01workerexit',
            'exception_type' => 'DurableWorkflow\\Exception\\WorkflowTimedOut',
            'message' => 'Timed out waiting for terminal state.',
        ]);
        $workerDiagnostic = $runtimeFailure([
            'classification' => 'server',
            'owning_surface' => 'server',
            'process' => 'worker',
            'operation' => 'worker.heartbeat',
            'http_method' => 'POST',
            'endpoint' => '/api/worker-protocol/workflow-tasks/{task_id}/heartbeat',
            'status_code' => 500,
            'public_error_envelope' => [
                'reason' => 'control_plane_write_failed',
                'message' => 'Heartbeat persistence failed.',
            ],
            'exception_type' => 'DurableWorkflow\\Exception\\ServerException',
            'message' => 'Server returned token='.$privateToken,
        ]);
        $input = [
            'clientDiagnostic' => $clientDiagnostic,
            'workerDiagnostic' => $workerDiagnostic,
            'serverLog' => 'Worker heartbeat returned HTTP 500 reason=control_plane_write_failed token='.$privateToken,
            'workerId' => 'php-sdk-worker-1',
            'processAlive' => false,
            'processExitCode' => 1,
            'secrets' => [$privateToken],
            'probes' => [
                'health' => ['http_status' => 200, 'payload' => ['status' => 'serving']],
                'worker' => ['http_status' => 200, 'payload' => ['worker_id' => 'php-sdk-worker-1', 'status' => 'stale']],
            ],
        ];
        $program = <<<'NODE'
const fs = require('node:fs');
const {createCompanionFailureEvidence} = require(process.argv[1]);
const input = JSON.parse(fs.readFileSync(0, 'utf8'));
process.stdout.write(JSON.stringify(createCompanionFailureEvidence(input)));
NODE;
        $process = proc_open(
            [$nodeBinary, '-e', $program, $helper],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);
        fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), (string) $stderr);

        $evidence = json_decode((string) $stdout, true, flags: JSON_THROW_ON_ERROR);
        $encoded = json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->assertSame('server', $evidence['classification']);
        $this->assertSame('server', $evidence['owning_surface']);
        $this->assertSame('worker_protocol_server_failure', $evidence['classification_basis']);
        $this->assertSame('exited', $evidence['worker']['process_state']['state']);
        $this->assertSame(1, $evidence['worker']['process_state']['exit_code']);
        $this->assertSame('worker.heartbeat', $evidence['worker']['last_protocol_failure']['operation']);
        $this->assertSame(500, $evidence['worker']['last_protocol_failure']['status_code']);
        $this->assertSame(
            'control_plane_write_failed',
            $evidence['worker']['last_protocol_failure']['public_error_envelope']['reason'],
        );
        $this->assertStringContainsString('[REDACTED]', $evidence['server']['process_log']['excerpt']);
        $this->assertStringNotContainsString($privateToken, $encoded);
        $this->assertLessThanOrEqual(6144, strlen($encoded));
    }

    public function test_non_http_worker_exception_remains_sdk_owned_without_becoming_a_protocol_failure(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise PHP companion diagnostics.');
        }

        $helper = dirname(__DIR__, 2).'/scripts/conformance/php-sdk-companion-failure-evidence.cjs';
        $runtimeFailure = static fn (array $payload): string => 'DW_PHP_SDK_RUNTIME_FAILURE='.
            json_encode($payload, JSON_THROW_ON_ERROR)."\n";
        $clientFailure = [
            'classification' => 'sdk',
            'owning_surface' => 'sdk-php',
            'process' => 'client',
            'operation' => 'workflow.result:failed',
            'workflow_id' => 'php-sdk-worker-runtime-exception',
            'run_id' => '01workerruntimeexception',
            'exception_type' => 'DurableWorkflow\\Exception\\WorkflowTimedOut',
            'message' => 'Timed out waiting for the worker-owned failure to reach a terminal state.',
        ];
        $workerFailure = [
            'classification' => 'sdk',
            'owning_surface' => 'sdk-php',
            'process' => 'worker',
            'operation' => 'worker.run',
            'workflow_id' => 'php-sdk-worker-runtime-exception',
            'run_id' => '01workerruntimeexception',
            'exception_type' => 'RuntimeException',
            'message' => 'The released SDK worker failed while decoding a workflow task.',
        ];
        $input = [
            'clientDiagnostic' => $runtimeFailure($clientFailure),
            'workerDiagnostic' => $runtimeFailure($workerFailure),
            'serverLog' => 'INFO request completed HTTP 200 workflow_id=php-sdk-worker-runtime-exception',
            'workerId' => 'php-sdk-worker-1',
            'processAlive' => false,
            'processExitCode' => 1,
            'probes' => [
                'health' => ['http_status' => 200, 'payload' => ['status' => 'serving']],
                'worker' => ['http_status' => 200, 'payload' => [
                    'worker_id' => 'php-sdk-worker-1',
                    'status' => 'stale',
                ]],
            ],
        ];
        $program = <<<'NODE'
const fs = require('node:fs');
const {createCompanionFailureEvidence} = require(process.argv[1]);
const input = JSON.parse(fs.readFileSync(0, 'utf8'));
process.stdout.write(JSON.stringify(createCompanionFailureEvidence(input)));
NODE;
        $process = proc_open(
            [$nodeBinary, '-e', $program, $helper],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);
        fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), (string) $stderr);

        $companion = json_decode((string) $stdout, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('sdk', $companion['classification']);
        $this->assertSame('sdk-php', $companion['owning_surface']);
        $this->assertSame('worker_process_exception', $companion['classification_basis']);
        $this->assertNull($companion['worker']['last_protocol_failure']);
        $this->assertSame('worker.run', $companion['worker']['last_runtime_exception']['operation']);
        $this->assertSame('RuntimeException', $companion['worker']['last_runtime_exception']['exception_type']);
        $this->assertNull($companion['worker']['last_runtime_exception']['status_code']);
        $this->assertFalse($companion['server']['error_record_required']);
        $this->assertNull($companion['server']['error_record']);

        $resultDir = sys_get_temp_dir().'/dw-workflow-lifecycle-'.bin2hex(random_bytes(6));
        $retainedRecord = sys_get_temp_dir().'/dw-workflow-lifecycle-retained-'.bin2hex(random_bytes(6)).'.json';
        mkdir($resultDir, 0777, true);
        $hostEvidence = $this->hostEvidence();
        unset($hostEvidence['scenario_results']['php_sdk_lifecycle_surface']);
        file_put_contents(
            $resultDir.'/workflow-lifecycle-evidence.json',
            json_encode($hostEvidence, JSON_THROW_ON_ERROR),
        );
        $this->writeRustSidecar($resultDir);
        file_put_contents($resultDir.'/php-sdk-lifecycle-evidence.json', json_encode([
            'schema' => 'durable-workflow.v2.workflow-lifecycle.php-sdk-sidecar',
            'runner_blocked' => false,
            'scenario_results' => [
                'php_sdk_lifecycle_surface' => [
                    'status' => 'fail',
                    'classification' => 'product-gap',
                    'published_artifact_cell_executed' => true,
                    'observed_outputs' => array_merge(
                        $this->outputsForScenario('php_sdk_lifecycle_surface'),
                        [
                            'failure_kind' => 'client_timeout',
                            'failure_stage' => 'baseline_client',
                            'failure_classification' => 'sdk',
                            'failure_owner' => 'sdk-php',
                            'failure_summary' => 'The released SDK worker exited with a runtime exception.',
                            'runtime_failure_evidence' => $companion['client_failure'],
                            'companion_failure_evidence' => $companion,
                        ],
                    ),
                    'linked_findings' => [[
                        'finding_id' => 'php-sdk-worker-runtime-exception',
                        'finding_type' => 'product_behavior_gap',
                        'classification' => 'sdk',
                        'owning_surface' => 'sdk-php',
                        'failure_stage' => 'baseline_client',
                        'summary' => 'The released SDK worker raised a runtime exception.',
                        'observed_behavior' => 'The worker exited before the workflow reached a terminal state.',
                        'next_acceptance_criterion' => 'Correct the released SDK worker exception and rerun the published artifacts.',
                    ]],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            exec($this->runnerCommand($resultDir, [
                'DW_WORKFLOW_LIFECYCLE_EVIDENCE_PATH' => $resultDir.'/workflow-lifecycle-evidence.json',
                'DW_WORKFLOW_LIFECYCLE_SKIP_PHP_SDK_PROBE' => '1',
            ]), $output, $exitCode);
            $this->assertSame(0, $exitCode, implode("\n", $output));

            file_put_contents(
                $retainedRecord,
                (string) file_get_contents($resultDir.'/workflow-lifecycle-record.json'),
            );
            $this->removeDirectory($resultDir);

            $record = $this->readJson($retainedRecord);
            $diagnostic = $record['shardDiagnostics']['php_sdk_lifecycle_surface'];
            $retainedCompanion = $diagnostic['companion_failure'];
            $this->assertSame('non_passing', $record['outcome']);
            $this->assertFalse($record['runnerBlocked']);
            $this->assertSame('sdk-php', $diagnostic['owning_surface']);
            $this->assertSame('worker.run', $diagnostic['operation']);
            $this->assertNull($diagnostic['http']);
            $this->assertNull($retainedCompanion['worker']['last_protocol_failure']);
            $this->assertSame(
                'RuntimeException',
                $retainedCompanion['worker']['last_runtime_exception']['exception_type'],
            );
            $this->assertSame([], WorkflowLifecycleResultGate::evaluate($record['result'])['gate_failures']);
            $this->assertFileDoesNotExist($resultDir);
        } finally {
            $this->removeDirectory($resultDir);
            @unlink($retainedRecord);
        }
    }

    public function test_oversized_worker_protocol_failure_remains_actionable_after_cleanup(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise retained worker-protocol diagnostics.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $helper = $repoRoot.'/scripts/conformance/php-sdk-companion-failure-evidence.cjs';
        $resultDir = sys_get_temp_dir().'/dw-workflow-lifecycle-'.bin2hex(random_bytes(6));
        $retainedRecord = sys_get_temp_dir().'/dw-workflow-lifecycle-retained-'.bin2hex(random_bytes(6)).'.json';
        $privateToken = 'private-oversized-protocol-token';
        $workflowId = 'php-sdk-oversized-failure';
        $runId = '01oversizedprotocolfailure';
        $taskId = 'workflow-task-oversized-failure';
        mkdir($resultDir, 0777, true);
        $evidencePath = $resultDir.'/workflow-lifecycle-evidence.json';
        $sidecarPath = $resultDir.'/php-sdk-lifecycle-evidence.json';

        $marker = static fn (array $failure): string => str_repeat('secondary worker prose ', 800)."\n"
            .'DW_PHP_SDK_RUNTIME_FAILURE='.json_encode($failure, JSON_THROW_ON_ERROR)."\n";
        $clientDiagnostic = $marker([
            'classification' => 'sdk',
            'owning_surface' => 'sdk-php',
            'process' => 'client',
            'operation' => 'workflow.result:failed',
            'http_method' => 'GET',
            'endpoint' => '/api/workflows/{workflow_id}/runs/{run_id}/result',
            'workflow_id' => $workflowId,
            'run_id' => $runId,
            'exception_type' => 'DurableWorkflow\\Exception\\WorkflowTimedOut',
            'message' => 'Timed out waiting for the intentional failure workflow.',
        ]);
        $workerDiagnostic = $marker([
            'classification' => 'server',
            'owning_surface' => 'server',
            'process' => 'worker',
            'operation' => 'worker.run',
            'http_method' => 'MULTIPLE',
            'endpoint_class' => 'worker_protocol',
            'endpoint' => '/api/worker-protocol/*',
            'status_code' => 500,
            'reason' => 'internal_server_error',
            'retryable' => false,
            'task_id' => $taskId,
            'workflow_id' => $workflowId,
            'run_id' => $runId,
            'public_error_envelope' => [
                'error' => 'internal_server_error',
                'reason' => 'internal_server_error',
                'status_code' => 500,
                'retryable' => false,
                'task_id' => $taskId,
                'workflow_id' => $workflowId,
                'run_id' => $runId,
                'message' => 'The public response was intentionally generic. '.str_repeat('response ', 1200),
                'credential' => $privateToken,
            ],
            'exception_type' => 'DurableWorkflow\\Exception\\ServerException',
            'message' => 'Server exception token='.$privateToken.' '.str_repeat('secondary ', 1200),
        ]);
        $correlatedErrorLog = json_encode([
            'timestamp' => '2026-07-19T05:00:00Z',
            'level' => 'ERROR',
            'status_code' => 500,
            'reason' => 'workflow_task_commit_failed',
            'exception_type' => 'Illuminate\\Database\\QueryException',
            'task_id' => $taskId,
            'message' => 'The workflow task commit failed token='.$privateToken.' '.str_repeat('server detail ', 800),
        ], JSON_THROW_ON_ERROR);
        $identifierRichAccessLog = json_encode([
            'timestamp' => '2026-07-19T05:00:01Z',
            'level' => 'INFO',
            'http_status' => 500,
            'task_id' => $taskId,
            'workflow_id' => $workflowId,
            'run_id' => $runId,
            'message' => 'HTTP 500 request completed for the worker protocol endpoint.',
        ], JSON_THROW_ON_ERROR);
        $serverLog = $correlatedErrorLog."\n".$identifierRichAccessLog;
        $input = [
            'clientDiagnostic' => $clientDiagnostic,
            'workerDiagnostic' => $workerDiagnostic,
            'serverLog' => $serverLog,
            'workerId' => 'php-sdk-worker-1',
            'processAlive' => false,
            'processExitCode' => 1,
            'secrets' => [$privateToken],
            'probes' => [
                'health' => ['http_status' => 200, 'payload' => ['status' => 'serving']],
                'worker' => ['http_status' => 200, 'payload' => [
                    'worker_id' => 'php-sdk-worker-1',
                    'status' => 'stale',
                ]],
                'run' => ['http_status' => 200, 'payload' => [
                    'workflow_id' => $workflowId,
                    'run_id' => $runId,
                    'status' => 'pending',
                    'is_terminal' => false,
                    'task_queue' => 'php-sdk-oversized-protocol',
                ]],
                'history' => ['http_status' => 200, 'payload' => [
                    'workflow_id' => $workflowId,
                    'run_id' => $runId,
                    'events' => [
                        ['sequence' => 1, 'event_type' => 'WorkflowStarted'],
                        ['sequence' => 2, 'event_type' => 'WorkflowTaskScheduled'],
                    ],
                ]],
                'task_queue' => ['http_status' => 200, 'payload' => [
                    'name' => 'php-sdk-oversized-protocol',
                    'stats' => [
                        'approximate_backlog_count' => 1,
                        'workflow_tasks' => ['ready_count' => 1, 'leased_count' => 0],
                        'activity_tasks' => ['ready_count' => 0, 'leased_count' => 0],
                        'pollers' => ['active_count' => 1, 'stale_count' => 0],
                    ],
                    'pollers' => [[
                        'worker_id' => 'php-sdk-worker-1',
                        'status' => 'stale',
                        'is_stale' => true,
                    ]],
                    'current_leases' => [],
                    'admission' => [
                        'workflow_tasks' => ['status' => 'accepting'],
                        'activity_tasks' => ['status' => 'accepting'],
                        'query_tasks' => ['status' => 'accepting'],
                    ],
                ]],
            ],
        ];
        $program = <<<'NODE'
const fs = require('node:fs');
const {createCompanionFailureEvidence} = require(process.argv[1]);
const input = JSON.parse(fs.readFileSync(0, 'utf8'));
process.stdout.write(JSON.stringify(createCompanionFailureEvidence(input)));
NODE;
        $process = proc_open(
            [$nodeBinary, '-e', $program, $helper],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);
        fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        $companionJson = stream_get_contents($pipes[1]);
        $companionStderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), (string) $companionStderr);
        $companion = json_decode((string) $companionJson, true, flags: JSON_THROW_ON_ERROR);
        $this->assertLessThanOrEqual(6144, strlen((string) $companionJson));
        $this->assertNull($companion['client_failure']['status_code']);

        $hostEvidence = $this->hostEvidence();
        unset($hostEvidence['scenario_results']['php_sdk_lifecycle_surface']);
        file_put_contents($evidencePath, json_encode($hostEvidence, JSON_THROW_ON_ERROR));
        $this->writeRustSidecar($resultDir);
        file_put_contents($sidecarPath, json_encode([
            'schema' => 'durable-workflow.v2.workflow-lifecycle.php-sdk-sidecar',
            'runner_blocked' => false,
            'scenario_results' => [
                'php_sdk_lifecycle_surface' => [
                    'status' => 'fail',
                    'classification' => 'product-gap',
                    'published_artifact_cell_executed' => true,
                    'observed_outputs' => array_merge(
                        $this->outputsForScenario('php_sdk_lifecycle_surface'),
                        [
                            'failure_kind' => 'client_timeout',
                            'failure_stage' => 'baseline_client',
                            'failure_classification' => 'server',
                            'failure_owner' => 'server',
                            'failure_summary' => 'The client timed out after its companion worker exited.',
                            'runtime_failure_evidence' => $companion['client_failure'],
                            'companion_failure_evidence' => $companion,
                        ],
                    ),
                    'linked_findings' => [[
                        'finding_id' => 'php-sdk-worker-protocol-failure',
                        'finding_type' => 'product_behavior_gap',
                        'classification' => 'server',
                        'owning_surface' => 'server',
                        'failure_stage' => 'baseline_client',
                        'summary' => 'The companion worker exited after a server protocol failure.',
                        'observed_behavior' => 'The next workflow remained pending after worker exit.',
                        'next_acceptance_criterion' => 'Correct the server protocol failure and rerun the exact published artifacts.',
                    ]],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            exec($this->runnerCommand($resultDir, [
                'DW_WORKFLOW_LIFECYCLE_EVIDENCE_PATH' => $evidencePath,
                'DW_WORKFLOW_LIFECYCLE_AUTH_TOKEN' => $privateToken,
                'DW_WORKFLOW_LIFECYCLE_SKIP_PHP_SDK_PROBE' => '1',
            ]), $output, $exitCode);
            $this->assertSame(0, $exitCode, implode("\n", $output));

            file_put_contents(
                $retainedRecord,
                (string) file_get_contents($resultDir.'/workflow-lifecycle-record.json'),
            );
            $this->removeDirectory($resultDir);

            $retainedJson = (string) file_get_contents($retainedRecord);
            $record = $this->readJson($retainedRecord);
            $diagnostic = $record['shardDiagnostics']['php_sdk_lifecycle_surface'];
            $protocol = $diagnostic['companion_failure']['worker']['last_protocol_failure'];
            $server = $diagnostic['companion_failure']['server'];

            $this->assertSame('non_passing', $record['outcome']);
            $this->assertFalse($record['runnerBlocked']);
            $this->assertSame('fail', $record['scenarioResults']['php_sdk_lifecycle_surface']['status']);
            $this->assertSame('pass', $record['scenarioResults']['rust_sdk_lifecycle_surface']['status']);
            $this->assertArrayNotHasKey('rust_sdk_lifecycle_surface', $record['shardDiagnostics']);
            $this->assertSame(500, $diagnostic['http']['status']);
            $this->assertSame('internal_server_error', $diagnostic['http']['reason']);
            $this->assertSame('worker.run', $protocol['operation']);
            $this->assertSame('MULTIPLE', $protocol['http_method']);
            $this->assertSame('worker_protocol', $protocol['endpoint_class']);
            $this->assertSame(500, $protocol['status_code']);
            $this->assertSame('internal_server_error', $protocol['reason']);
            $this->assertFalse($protocol['retryable']);
            $this->assertSame($taskId, $protocol['task_id']);
            $this->assertSame($workflowId, $protocol['workflow_id']);
            $this->assertSame($runId, $protocol['run_id']);
            $this->assertSame('internal_server_error', $protocol['public_error_envelope']['reason']);
            $this->assertArrayNotHasKey('bounded_json_excerpt', $protocol);
            $this->assertNull($diagnostic['companion_failure']['worker']['last_runtime_exception']);
            $this->assertTrue($server['error_record_required']);
            $this->assertSame('failure_identifier_and_error_severity', $server['error_record']['matched_by']);
            $this->assertSame('ERROR', $server['error_record']['level']);
            $this->assertSame('workflow_task_commit_failed', $server['error_record']['reason']);
            $this->assertSame($taskId, $server['error_record']['task_id']);
            $this->assertNull($server['error_record']['workflow_id']);
            $this->assertSame(
                'Illuminate\\Database\\QueryException',
                $server['error_record']['exception_type'],
            );
            $this->assertStringNotContainsString('request completed', strtolower($server['error_record']['excerpt']));
            $this->assertSame('php-sdk-oversized-protocol', $server['task_queue']['payload']['name']);
            $this->assertSame(1, $server['task_queue']['payload']['stats']['workflow_tasks']['ready_count']);
            $this->assertLessThanOrEqual(
                8192,
                strlen(json_encode($diagnostic, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            );
            $this->assertStringNotContainsString($privateToken, $retainedJson);
            $this->assertSame([], WorkflowLifecycleResultGate::evaluate($record['result'])['gate_failures']);

            $tamperedResult = $record['result'];
            $tamperedErrorRecord = &$tamperedResult['scenario_results']['php_sdk_lifecycle_surface']['observed_outputs']['shard_diagnostic']['companion_failure']['server']['error_record'];
            $tamperedErrorRecord = [
                'source' => 'server_process_stderr',
                'matched_by' => 'failure_identifier',
                'level' => 'INFO',
                'status_code' => 500,
                'task_id' => $taskId,
                'workflow_id' => $workflowId,
                'run_id' => $runId,
                'excerpt' => 'HTTP 500 request completed for the worker protocol endpoint.',
            ];
            $tamperedFailures = array_column(
                WorkflowLifecycleResultGate::evaluate($tamperedResult)['gate_failures'],
                'code',
            );
            $this->assertContains('invalid_lifecycle_server_error_record_severity', $tamperedFailures);
            $this->assertContains('invalid_lifecycle_server_error_record_match', $tamperedFailures);
            $this->assertFileDoesNotExist($resultDir);
        } finally {
            $this->removeDirectory($resultDir);
            @unlink($retainedRecord);
        }
    }

    public function test_published_artifact_runner_merges_python_sdk_lifecycle_sidecar(): void
    {
        $resultDir = sys_get_temp_dir().'/dw-workflow-lifecycle-'.bin2hex(random_bytes(6));
        mkdir($resultDir, 0777, true);
        $evidencePath = $resultDir.'/workflow-lifecycle-evidence.json';
        $sidecarPath = $resultDir.'/python-sdk-lifecycle-evidence.json';

        $hostEvidence = $this->hostEvidence();
        unset($hostEvidence['scenario_results']['python_sdk_lifecycle_surface']);
        file_put_contents($evidencePath, json_encode($hostEvidence, JSON_THROW_ON_ERROR));
        $this->writeRustSidecar($resultDir);
        file_put_contents($sidecarPath, json_encode([
            'schema' => 'durable-workflow.v2.workflow-lifecycle.python-sdk-sidecar',
            'runner_blocked' => false,
            'scenario_results' => [
                'python_sdk_lifecycle_surface' => [
                    'status' => 'pass',
                    'published_artifact_cell_executed' => true,
                    'observed_outputs' => $this->outputsForScenario('python_sdk_lifecycle_surface') + [
                        'artifact_source' => 'pypi://durable-workflow==0.4.91',
                        'pypi_artifact_verified' => true,
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            exec($this->runnerCommand($resultDir, [
                'DW_WORKFLOW_LIFECYCLE_EVIDENCE_PATH' => $evidencePath,
                'DW_WORKFLOW_LIFECYCLE_SKIP_PYTHON_SDK_PROBE' => '1',
            ]), $output, $exitCode);

            $this->assertSame(0, $exitCode, implode("\n", $output));
            $result = $this->readJson($resultDir.'/workflow-lifecycle-result.json');

            $this->assertSame('pass', $result['outcome']);
            $this->assertSame(
                'pass',
                $result['scenario_results']['python_sdk_lifecycle_surface']['status'],
            );
            $this->assertStringContainsString('python-sdk-lifecycle-evidence.json', $result['evidence_source']);
            $this->assertSame('pass', WorkflowLifecycleResultGate::evaluate($result)['status']);
        } finally {
            $this->removeDirectory($resultDir);
        }
    }

    public function test_published_artifact_runner_fails_closed_when_rust_sidecar_is_missing(): void
    {
        $resultDir = sys_get_temp_dir().'/dw-workflow-lifecycle-'.bin2hex(random_bytes(6));
        mkdir($resultDir, 0777, true);
        $evidencePath = $resultDir.'/workflow-lifecycle-evidence.json';
        file_put_contents($evidencePath, json_encode($this->hostEvidence(), JSON_THROW_ON_ERROR));

        try {
            exec($this->runnerCommand($resultDir, [
                'DW_WORKFLOW_LIFECYCLE_EVIDENCE_PATH' => $evidencePath,
            ]), $output, $exitCode);

            $this->assertSame(0, $exitCode, implode("\n", $output));
            $result = $this->readJson($resultDir.'/workflow-lifecycle-result.json');
            $rust = $result['scenario_results']['rust_sdk_lifecycle_surface'];

            $this->assertSame('non_passing', $result['outcome']);
            $this->assertSame('not_covered', $rust['status']);
            $this->assertSame('rust_sdk_shard_missing', $rust['observed_outputs']['stable_reason']);
        } finally {
            $this->removeDirectory($resultDir);
        }
    }

    public function test_published_artifact_runner_preserves_executed_rust_product_failure(): void
    {
        $resultDir = sys_get_temp_dir().'/dw-workflow-lifecycle-'.bin2hex(random_bytes(6));
        mkdir($resultDir, 0777, true);
        $evidencePath = $resultDir.'/workflow-lifecycle-evidence.json';
        file_put_contents($evidencePath, json_encode($this->hostEvidence(), JSON_THROW_ON_ERROR));
        $this->writeRustSidecar($resultDir);
        $sidecar = $this->readJson($resultDir.'/rust-sdk-lifecycle-evidence.json');
        $sidecar['shard_exit_status'] = 17;
        $rustScenario = &$sidecar['scenario_results']['rust_sdk_lifecycle_surface'];
        $rustScenario['status'] = 'fail';
        $rustScenario['classification'] = 'product-gap';
        $rustScenario['observed_outputs']['probe_outcome'] = 'fail';
        $rustScenario['observed_outputs']['shard_exit_status'] = 17;
        $rustScenario['observed_outputs']['stable_reason'] = 'server_terminal_typed_timeout_reason_unstable';
        $rustScenario['observed_outputs']['failure_message'] = 'typed_timed_out observed client_timeout; token=private-test-token '.str_repeat('detail ', 100);
        $rustScenario['observed_outputs']['failing_lifecycle_cell'] = 'typed_timed_out';
        $rustScenario['observed_outputs']['command_output'] = 'unrelated process output';
        $rustScenario['observed_outputs']['auth_token'] = 'private-test-token';
        $rustScenario['observed_outputs']['scenario_outcomes']['typed_timed_out'] = [
            'status' => 'fail',
            'stable_reason' => 'server_terminal_typed_timeout_reason_unstable',
            'observed_behavior' => 'WorkflowTimedOut returned client_timeout instead of a server terminal timeout.',
            'typed_outcome' => 'WorkflowTimedOut',
            'failure_category' => 'client_timeout',
            'server_terminal' => false,
        ];
        file_put_contents(
            $resultDir.'/rust-sdk-lifecycle-evidence.json',
            json_encode($sidecar, JSON_THROW_ON_ERROR),
        );

        try {
            exec($this->runnerCommand($resultDir, [
                'DW_WORKFLOW_LIFECYCLE_EVIDENCE_PATH' => $evidencePath,
                'DW_WORKFLOW_LIFECYCLE_AUTH_TOKEN' => 'private-test-token',
            ]), $output, $exitCode);

            $this->assertSame(0, $exitCode, implode("\n", $output));
            $result = $this->readJson($resultDir.'/workflow-lifecycle-result.json');
            $record = $this->readJson($resultDir.'/workflow-lifecycle-record.json');
            $rust = $result['scenario_results']['rust_sdk_lifecycle_surface'];

            $this->assertSame('non_passing', $result['outcome']);
            $this->assertFalse($result['runner_blocked']);
            $this->assertSame('fail', $rust['status']);
            $this->assertSame('product-gap', $rust['classification']);
            $this->assertTrue($rust['observed_outputs']['published_artifact_cell_executed']);
            $this->assertSame('server_terminal_typed_timeout_reason_unstable', $rust['observed_outputs']['stable_reason']);
            $this->assertSame('typed_timed_out', $rust['observed_outputs']['failing_lifecycle_cell']);
            $this->assertSame('client_timeout', $rust['observed_outputs']['scenario_outcomes']['typed_timed_out']['failure_category']);
            $this->assertSame('0.1.15', $rust['observed_outputs']['install_provenance']['installed_version']);
            $this->assertSame('0.2.649', $rust['observed_outputs']['server_version']);
            $this->assertStringNotContainsString('private-test-token', $rust['observed_outputs']['failure_message']);
            $this->assertStringContainsString('[REDACTED]', $rust['observed_outputs']['failure_message']);
            $this->assertSame(512, strlen($rust['observed_outputs']['failure_message']));
            $this->assertArrayNotHasKey('command_output', $rust['observed_outputs']);
            $this->assertArrayNotHasKey('auth_token', $rust['observed_outputs']);
            $this->assertSame(
                'workflow-lifecycle-rust-sdk-lifecycle-surface-product-gap',
                $rust['linked_findings'][0]['finding_id'],
            );
            $this->assertStringContainsString('client_timeout', $rust['linked_findings'][0]['summary']);
            $this->assertStringContainsString('typed_timed_out', $rust['linked_findings'][0]['next_acceptance_criterion']);
            $this->assertSame(
                'client_timeout',
                $rust['linked_findings'][0]['observed_evidence']['failure_category'],
            );
            $this->assertSame(
                $rust['observed_outputs']['shard_diagnostic'],
                $rust['linked_findings'][0]['observed_evidence']['shard_diagnostic'],
            );
            $this->assertSame($rust, $record['scenarioResults']['rust_sdk_lifecycle_surface']);
        } finally {
            $this->removeDirectory($resultDir);
        }
    }

    /**
     * @dataProvider invalidNormalizedRustFailureEvidence
     *
     * @param  array<string, mixed>  $scenarioOutcomes
     */
    public function test_published_artifact_runner_fails_closed_for_invalid_rust_product_failure_evidence(
        string $failingCell,
        array $scenarioOutcomes,
        string $observedExecutionMarker = 'true',
    ): void {
        $resultDir = sys_get_temp_dir().'/dw-workflow-lifecycle-'.bin2hex(random_bytes(6));
        mkdir($resultDir, 0777, true);
        $evidencePath = $resultDir.'/workflow-lifecycle-evidence.json';
        file_put_contents($evidencePath, json_encode($this->hostEvidence(), JSON_THROW_ON_ERROR));
        $this->writeRustSidecar($resultDir);
        $sidecar = $this->readJson($resultDir.'/rust-sdk-lifecycle-evidence.json');
        $sidecar['shard_exit_status'] = 17;
        $rust = &$sidecar['scenario_results']['rust_sdk_lifecycle_surface'];
        $rust['status'] = 'fail';
        $rust['classification'] = 'product-gap';
        $rust['observed_outputs']['probe_outcome'] = 'fail';
        $rust['observed_outputs']['shard_exit_status'] = 17;
        $rust['observed_outputs']['stable_reason'] = 'server_terminal_typed_timeout_reason_unstable';
        $rust['observed_outputs']['failure_message'] = 'Rust timeout behavior did not satisfy the lifecycle contract.';
        $rust['observed_outputs']['failing_lifecycle_cell'] = $failingCell;
        $rust['observed_outputs']['scenario_outcomes'] = $scenarioOutcomes;
        if ($observedExecutionMarker === 'false') {
            $rust['observed_outputs']['published_artifact_cell_executed'] = false;
        } elseif ($observedExecutionMarker === 'missing') {
            unset($rust['observed_outputs']['published_artifact_cell_executed']);
        }
        file_put_contents(
            $resultDir.'/rust-sdk-lifecycle-evidence.json',
            json_encode($sidecar, JSON_THROW_ON_ERROR),
        );

        try {
            exec($this->runnerCommand($resultDir, [
                'DW_WORKFLOW_LIFECYCLE_EVIDENCE_PATH' => $evidencePath,
            ]), $output, $exitCode);

            $this->assertSame(0, $exitCode, implode("\n", $output));
            $result = $this->readJson($resultDir.'/workflow-lifecycle-result.json');
            $rust = $result['scenario_results']['rust_sdk_lifecycle_surface'];

            $this->assertTrue($result['runner_blocked']);
            $this->assertSame('runner_blocked', $rust['status']);
            $this->assertSame('runner-gap', $rust['classification']);
            $this->assertFalse($rust['published_artifact_cell_executed']);
            $this->assertFalse($rust['observed_outputs']['published_artifact_cell_executed']);
            $this->assertSame('rust_sdk_sidecar_contract_invalid', $rust['observed_outputs']['stable_reason']);
            $this->assertNotContains(
                'workflow-lifecycle-rust-sdk-lifecycle-surface-product-gap',
                array_column($result['findings'], 'finding_id'),
            );
        } finally {
            $this->removeDirectory($resultDir);
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: array<string, mixed>, 2?: string}>
     */
    public static function invalidNormalizedRustFailureEvidence(): iterable
    {
        $validOutcome = [
            'status' => 'fail',
            'stable_reason' => 'server_terminal_typed_timeout_reason_unstable',
            'observed_behavior' => 'WorkflowTimedOut returned client_timeout.',
        ];

        yield 'missing scenario outcome' => ['typed_timed_out', []];
        yield 'missing failing lifecycle cell' => ['', ['typed_timed_out' => $validOutcome]];
        yield 'contradictory scenario status' => ['typed_timed_out', [
            'typed_timed_out' => [...$validOutcome, 'status' => 'pass'],
        ]];
        yield 'contradictory stable reason' => ['typed_timed_out', [
            'typed_timed_out' => [...$validOutcome, 'stable_reason' => 'different_failure'],
        ]];
        yield 'missing observed behavior' => ['typed_timed_out', [
            'typed_timed_out' => [...$validOutcome, 'observed_behavior' => ''],
        ]];
        yield 'false observed-output execution marker' => ['typed_timed_out', [
            'typed_timed_out' => $validOutcome,
        ], 'false'];
        yield 'missing observed-output execution marker' => ['typed_timed_out', [
            'typed_timed_out' => $validOutcome,
        ], 'missing'];
    }

    public function test_published_artifact_runner_fails_closed_for_artifact_mismatched_rust_sidecar(): void
    {
        $resultDir = sys_get_temp_dir().'/dw-workflow-lifecycle-'.bin2hex(random_bytes(6));
        mkdir($resultDir, 0777, true);
        $evidencePath = $resultDir.'/workflow-lifecycle-evidence.json';
        file_put_contents($evidencePath, json_encode($this->hostEvidence(), JSON_THROW_ON_ERROR));
        $this->writeRustSidecar($resultDir);
        $sidecar = $this->readJson($resultDir.'/rust-sdk-lifecycle-evidence.json');
        $sidecar['scenario_results']['rust_sdk_lifecycle_surface']['observed_outputs']['artifact_version'] = '0.1.7';
        file_put_contents(
            $resultDir.'/rust-sdk-lifecycle-evidence.json',
            json_encode($sidecar, JSON_THROW_ON_ERROR),
        );

        try {
            exec($this->runnerCommand($resultDir, [
                'DW_WORKFLOW_LIFECYCLE_EVIDENCE_PATH' => $evidencePath,
            ]), $output, $exitCode);

            $this->assertSame(0, $exitCode, implode("\n", $output));
            $result = $this->readJson($resultDir.'/workflow-lifecycle-result.json');
            $rust = $result['scenario_results']['rust_sdk_lifecycle_surface'];

            $this->assertSame('non_passing', $result['outcome']);
            $this->assertTrue($result['runner_blocked']);
            $this->assertSame('runner_blocked', $rust['status']);
            $this->assertSame('runner-gap', $rust['classification']);
            $this->assertFalse($rust['observed_outputs']['published_artifact_cell_executed']);
            $this->assertSame('rust_sdk_sidecar_artifact_mismatch', $rust['observed_outputs']['stable_reason']);
            $this->assertNotContains(
                'workflow-lifecycle-rust-sdk-lifecycle-surface-product-gap',
                array_column($result['findings'], 'finding_id'),
            );
        } finally {
            $this->removeDirectory($resultDir);
        }
    }

    public function test_published_artifact_runner_fails_closed_for_malformed_rust_sidecar(): void
    {
        $resultDir = sys_get_temp_dir().'/dw-workflow-lifecycle-'.bin2hex(random_bytes(6));
        mkdir($resultDir, 0777, true);
        $evidencePath = $resultDir.'/workflow-lifecycle-evidence.json';
        file_put_contents($evidencePath, json_encode($this->hostEvidence(), JSON_THROW_ON_ERROR));
        file_put_contents($resultDir.'/rust-sdk-lifecycle-evidence.json', '{invalid-json');

        try {
            exec($this->runnerCommand($resultDir, [
                'DW_WORKFLOW_LIFECYCLE_EVIDENCE_PATH' => $evidencePath,
            ]), $output, $exitCode);

            $this->assertSame(0, $exitCode, implode("\n", $output));
            $result = $this->readJson($resultDir.'/workflow-lifecycle-result.json');
            $rust = $result['scenario_results']['rust_sdk_lifecycle_surface'];

            $this->assertTrue($result['runner_blocked']);
            $this->assertSame('runner_blocked', $rust['status']);
            $this->assertFalse($rust['observed_outputs']['published_artifact_cell_executed']);
            $this->assertSame('rust_sdk_sidecar_contract_invalid', $rust['observed_outputs']['stable_reason']);
        } finally {
            $this->removeDirectory($resultDir);
        }
    }

    public function test_published_artifact_runner_preserves_validated_rust_runner_failure_reason(): void
    {
        $resultDir = sys_get_temp_dir().'/dw-workflow-lifecycle-'.bin2hex(random_bytes(6));
        mkdir($resultDir, 0777, true);
        $evidencePath = $resultDir.'/workflow-lifecycle-evidence.json';
        file_put_contents($evidencePath, json_encode($this->hostEvidence(), JSON_THROW_ON_ERROR));
        $this->writeRustSidecar($resultDir);
        $sidecar = $this->readJson($resultDir.'/rust-sdk-lifecycle-evidence.json');
        $sidecar['runner_blocked'] = true;
        $sidecar['shard_exit_status'] = 125;
        $rust = &$sidecar['scenario_results']['rust_sdk_lifecycle_surface'];
        $rust['status'] = 'runner_blocked';
        $rust['classification'] = 'runner-gap';
        $rust['published_artifact_cell_executed'] = false;
        $rust['observed_outputs']['published_artifact_cell_executed'] = false;
        $rust['observed_outputs']['shard_exit_status'] = 125;
        $rust['observed_outputs']['stable_reason'] = 'rust_sdk_probe_output_contract_invalid';
        $rust['observed_outputs']['failure_message'] = 'Probe process exited without a valid result envelope.';
        file_put_contents(
            $resultDir.'/rust-sdk-lifecycle-evidence.json',
            json_encode($sidecar, JSON_THROW_ON_ERROR),
        );

        try {
            exec($this->runnerCommand($resultDir, [
                'DW_WORKFLOW_LIFECYCLE_EVIDENCE_PATH' => $evidencePath,
            ]), $output, $exitCode);

            $this->assertSame(0, $exitCode, implode("\n", $output));
            $result = $this->readJson($resultDir.'/workflow-lifecycle-result.json');
            $rust = $result['scenario_results']['rust_sdk_lifecycle_surface'];

            $this->assertTrue($result['runner_blocked']);
            $this->assertSame('runner_blocked', $rust['status']);
            $this->assertFalse($rust['published_artifact_cell_executed']);
            $this->assertSame(
                'rust_sdk_probe_output_contract_invalid',
                $rust['observed_outputs']['stable_reason'],
            );
            $this->assertSame(125, $rust['observed_outputs']['shard_exit_status']);
        } finally {
            $this->removeDirectory($resultDir);
        }
    }

    public function test_rust_producer_preserves_only_validated_executed_product_failure(): void
    {
        $resultDir = sys_get_temp_dir().'/dw-workflow-lifecycle-rust-'.bin2hex(random_bytes(6));
        $fakeBin = sys_get_temp_dir().'/dw-workflow-lifecycle-rust-bin-'.bin2hex(random_bytes(6));
        mkdir($resultDir, 0777, true);
        mkdir($fakeBin, 0777, true);
        $this->writeFakeRustDocker($fakeBin);
        $probeOutput = json_encode([
            'sdk' => 'sdk-rust',
            'artifact_version' => '0.1.15',
            'server_version' => '0.2.649',
            'covered_cells' => [],
            'unsupported_cells' => [],
            'typed_errors' => [],
            'probe_outcome' => 'fail',
            'stable_reason' => 'server_terminal_typed_timeout_reason_unstable',
            'stable_reasons' => ['server_terminal_typed_timeout_reason_unstable'],
            'failure_message' => 'typed_timed_out observed client_timeout; token=private-test-token',
            'failing_lifecycle_cell' => 'typed_timed_out',
            'scenario_outcomes' => [
                'typed_timed_out' => [
                    'status' => 'fail',
                    'stable_reason' => 'server_terminal_typed_timeout_reason_unstable',
                    'observed_behavior' => 'WorkflowTimedOut returned client_timeout',
                ],
            ],
            'rust_shard_contract_version' => 3,
            'published_artifact_cell_executed' => true,
            'local_product_source_checkouts_used' => false,
        ], JSON_THROW_ON_ERROR);

        try {
            exec($this->rustProducerCommand($resultDir, $fakeBin, $probeOutput, 17), $output, $exitCode);

            $this->assertSame(17, $exitCode, implode("\n", $output));
            $sidecar = $this->readJson($resultDir.'/rust-sdk-lifecycle-evidence.json');
            $rust = $sidecar['scenario_results']['rust_sdk_lifecycle_surface'];

            $this->assertFalse($sidecar['runner_blocked']);
            $this->assertSame(17, $sidecar['shard_exit_status']);
            $this->assertSame('fail', $rust['status']);
            $this->assertSame('product-gap', $rust['classification']);
            $this->assertTrue($rust['published_artifact_cell_executed']);
            $this->assertSame(
                'server_terminal_typed_timeout_reason_unstable',
                $rust['observed_outputs']['stable_reason'],
            );
            $this->assertSame('typed_timed_out', $rust['observed_outputs']['failing_lifecycle_cell']);
            $this->assertSame('0.1.15', $rust['observed_outputs']['install_provenance']['installed_version']);
            $this->assertSame('0.2.649', $rust['observed_outputs']['server_version']);
            $this->assertStringNotContainsString(
                'private-test-token',
                $rust['observed_outputs']['failure_message'],
            );
            $this->assertStringContainsString('[REDACTED]', $rust['observed_outputs']['failure_message']);
        } finally {
            $this->removeDirectory($resultDir);
            $this->removeDirectory($fakeBin);
        }
    }

    public function test_rust_producer_preserves_typed_stale_rejection_evidence_in_outcome_and_finding(): void
    {
        $resultDir = sys_get_temp_dir().'/dw-workflow-lifecycle-rust-'.bin2hex(random_bytes(6));
        $fakeBin = sys_get_temp_dir().'/dw-workflow-lifecycle-rust-bin-'.bin2hex(random_bytes(6));
        mkdir($resultDir, 0777, true);
        mkdir($fakeBin, 0777, true);
        $this->writeFakeRustDocker($fakeBin);
        $staleOutcome = [
            'status' => 'fail',
            'stable_reason' => 'stale_run_rejection_reason_unstable',
            'observed_behavior' => 'Typed rejection retained with an unexpected reason.',
            'typed_error' => 'WorkflowCommandRejected',
            'http_status' => 409,
            'reason' => 'run_not_active',
            'target_scope' => 'run',
            'workflow_id' => 'rust-selected',
            'run_id' => 'rust-run-selected',
            'prior_run_id' => 'rust-run-selected',
            'successor_run_id' => 'rust-run-selected-successor',
            'successor_workflow_id' => 'rust-selected',
        ];
        $probeOutput = json_encode([
            'sdk' => 'sdk-rust',
            'artifact_version' => '0.1.15',
            'server_version' => '0.2.649',
            'covered_cells' => [],
            'unsupported_cells' => [],
            'typed_errors' => [],
            'probe_outcome' => 'fail',
            'stable_reason' => 'stale_run_rejection_reason_unstable',
            'stable_reasons' => ['stale_run_rejection_reason_unstable'],
            'failure_message' => 'stale rejection returned run_not_active',
            'failing_lifecycle_cell' => 'stale_run_rejection',
            'scenario_outcomes' => ['stale_run_rejection' => $staleOutcome],
            'rust_shard_contract_version' => 3,
            'published_artifact_cell_executed' => true,
            'local_product_source_checkouts_used' => false,
        ], JSON_THROW_ON_ERROR);

        try {
            exec($this->rustProducerCommand($resultDir, $fakeBin, $probeOutput, 19), $output, $exitCode);

            $this->assertSame(19, $exitCode, implode("\n", $output));
            $sidecar = $this->readJson($resultDir.'/rust-sdk-lifecycle-evidence.json');
            $rust = $sidecar['scenario_results']['rust_sdk_lifecycle_surface'];
            $observed = $rust['observed_outputs']['scenario_outcomes']['stale_run_rejection'];
            $findingEvidence = $rust['linked_findings'][0]['observed_evidence'];

            foreach (['http_status', 'reason', 'target_scope', 'workflow_id', 'run_id'] as $field) {
                $this->assertSame($staleOutcome[$field], $observed[$field]);
                $this->assertSame($staleOutcome[$field], $findingEvidence[$field]);
            }
        } finally {
            $this->removeDirectory($resultDir);
            $this->removeDirectory($fakeBin);
        }
    }

    /**
     * @dataProvider invalidRustProbeOutputs
     */
    public function test_rust_producer_keeps_process_and_output_contract_failures_runner_blocked(
        string $probeOutput,
        int $probeExit,
    ): void {
        $resultDir = sys_get_temp_dir().'/dw-workflow-lifecycle-rust-'.bin2hex(random_bytes(6));
        $fakeBin = sys_get_temp_dir().'/dw-workflow-lifecycle-rust-bin-'.bin2hex(random_bytes(6));
        mkdir($resultDir, 0777, true);
        mkdir($fakeBin, 0777, true);
        $this->writeFakeRustDocker($fakeBin);

        try {
            exec($this->rustProducerCommand($resultDir, $fakeBin, $probeOutput, $probeExit), $output, $exitCode);

            $this->assertSame(1, $exitCode, implode("\n", $output));
            $sidecar = $this->readJson($resultDir.'/rust-sdk-lifecycle-evidence.json');
            $rust = $sidecar['scenario_results']['rust_sdk_lifecycle_surface'];

            $this->assertTrue($sidecar['runner_blocked']);
            $this->assertSame('runner_blocked', $rust['status']);
            $this->assertSame('runner-gap', $rust['classification']);
            $this->assertFalse($rust['published_artifact_cell_executed']);
            $this->assertFalse($rust['observed_outputs']['published_artifact_cell_executed']);
            $this->assertSame(
                'rust_sdk_probe_output_contract_invalid',
                $rust['observed_outputs']['stable_reason'],
            );
            $this->assertSame([], $rust['observed_outputs']['scenario_outcomes']);
        } finally {
            $this->removeDirectory($resultDir);
            $this->removeDirectory($fakeBin);
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: int}>
     */
    public static function invalidRustProbeOutputs(): iterable
    {
        yield 'docker process exits without probe output' => ['', 125];
        yield 'probe emits malformed json' => ['not-json', 1];
        yield 'probe emits wrong contract' => ['{"rust_shard_contract_version":1}', 1];
    }

    public function test_result_gate_rejects_mismatched_or_incomplete_rust_shard(): void
    {
        $result = $this->completeLifecycleResult();
        $outputs = &$result['scenario_results']['rust_sdk_lifecycle_surface']['observed_outputs'];
        $outputs['artifact_version'] = '0.1.7';
        $outputs['install_provenance']['requested_version'] = '0.1.7';
        $outputs['install_provenance']['installed_version'] = '0.1.7';
        $outputs['covered_cells'] = array_values(array_diff(
            $outputs['covered_cells'],
            ['late_activity_completion_refused'],
        ));

        $evaluation = WorkflowLifecycleResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('rust_sdk_artifact_version_mismatch', $failureCodes);
        $this->assertContains('rust_sdk_required_cell_missing', $failureCodes);
    }

    public function test_result_gate_rejects_rust_pass_labels_without_required_lifecycle_semantics(): void
    {
        $result = $this->completeLifecycleResult();
        $outputs = &$result['scenario_results']['rust_sdk_lifecycle_surface']['observed_outputs'];
        $outputs['scenario_outcomes']['typed_timed_out'] = [
            'status' => 'pass',
            'typed_outcome' => 'WorkflowTimedOut',
            'reason' => 'result_wait_timeout',
            'failure_category' => 'client_timeout',
        ];
        $outputs['scenario_outcomes']['worker_restart_during_cancellation'] = ['status' => 'pass'];
        $outputs['executor_topology']['scheduler_process'] = false;
        $outputs['shard_exit_status'] = 17;

        $evaluation = WorkflowLifecycleResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('rust_sdk_scenario_semantics_invalid', $failureCodes);
        $this->assertContains('rust_sdk_server_terminal_timeout_not_proven', $failureCodes);
        $this->assertContains('rust_sdk_restart_boundary_not_proven', $failureCodes);
        $this->assertContains('rust_sdk_shard_execution_invalid', $failureCodes);
        $this->assertContains('rust_sdk_executor_topology_invalid', $failureCodes);
    }

    public function test_result_gate_does_not_accept_a_terminal_current_run_as_stale_run_evidence(): void
    {
        $result = $this->completeLifecycleResult();
        $stale = &$result['scenario_results']['rust_sdk_lifecycle_surface']['observed_outputs']['scenario_outcomes']['stale_run_rejection'];
        $stale['successor_run_id'] = $stale['prior_run_id'];

        $evaluation = WorkflowLifecycleResultGate::evaluate($result);

        $this->assertContains(
            'rust_sdk_historical_run_boundary_not_proven',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_accepts_stale_run_evidence_with_a_distinct_same_workflow_successor(): void
    {
        $result = $this->completeLifecycleResult();

        $evaluation = WorkflowLifecycleResultGate::evaluate($result);

        $this->assertNotContains(
            'rust_sdk_historical_run_boundary_not_proven',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_rejects_restart_evidence_without_observed_replacement_poll_ordering(): void
    {
        $result = $this->completeLifecycleResult();
        $restart = &$result['scenario_results']['rust_sdk_lifecycle_surface']['observed_outputs']['scenario_outcomes']['worker_restart_during_cancellation'];
        $restart['replacement_poll_start_observed'] = false;
        $restart['replacement_poll_started_elapsed_ns'] = 30;
        $restart['settlement_released_elapsed_ns'] = 20;

        $evaluation = WorkflowLifecycleResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'rust_sdk_restart_boundary_not_proven',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_rejects_incomplete_rust_continue_as_new_redelivery_evidence(): void
    {
        $result = $this->completeLifecycleResult();
        $continue = &$result['scenario_results']['rust_sdk_lifecycle_surface']['observed_outputs']['scenario_outcomes']['continue_as_new_replay_boundary'];
        $continue['run_chain']['run_count'] = 3;
        $continue['predecessor_worker_process']['process_id'] = $continue['successor_worker_process']['process_id'];
        $continue['predecessor_worker_process']['completion']['completion_delivery_count'] = 1;
        $continue['predecessor_history']['events'][] = [
            'event_type' => 'SideEffectRecorded',
            'payload' => ['sequence' => 4],
        ];
        $continue['predecessor_transition_link']['continued_to_run_id'] = 'wrong-successor';
        $continue['predecessor_worker_process']['callback_calls'] = 2;
        $continue['final_result']['run_id'] = 'wrong-successor';

        $evaluation = WorkflowLifecycleResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('rust_sdk_continue_as_new_run_identity_invalid', $failureCodes);
        $this->assertContains('rust_sdk_continue_as_new_process_replacement_invalid', $failureCodes);
        $this->assertContains('rust_sdk_continue_as_new_completion_redelivery_invalid', $failureCodes);
        $this->assertContains('rust_sdk_continue_as_new_history_decisions_invalid', $failureCodes);
        $this->assertContains('rust_sdk_continue_as_new_history_links_invalid', $failureCodes);
        $this->assertContains('rust_sdk_continue_as_new_callback_or_routing_invalid', $failureCodes);
    }

    public function test_rust_lifecycle_probe_uses_exact_registry_artifacts_and_public_envelope(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $runner = file_get_contents($repoRoot.'/scripts/conformance/workflow-lifecycle-rust-published-artifacts.mjs') ?: '';
        $gate = file_get_contents($repoRoot.'/scripts/conformance/workflow-lifecycle-published-artifacts.mjs') ?: '';
        $probe = file_get_contents($repoRoot.'/scripts/conformance/workflow-lifecycle-rust-probe.rs') ?: '';

        $this->assertStringContainsString('durable-workflow = "=${SDK_VERSION}"', $runner);
        $this->assertStringContainsString('apache-avro = { version = "0.21"', $runner);
        $this->assertStringContainsString('axum = "0.8"', $runner);
        $this->assertStringContainsString('reqwest = { version = "0.12"', $runner);
        $this->assertStringContainsString("provenance(lock, 'durable-workflow', SDK_VERSION)", $runner);
        $this->assertStringContainsString("provenance(lock, 'apache-avro')", $runner);
        $this->assertStringContainsString("'rust_sdk_probe_output_contract_invalid'", $runner);
        $this->assertStringContainsString("outputs.probe_outcome === 'fail'", $runner);
        $this->assertStringContainsString('validated_product_failure', $probe);
        $this->assertStringContainsString('PayloadEnvelope::avro', $probe);
        $this->assertStringContainsString('historical_run_command_rejected', $probe);
        $this->assertStringContainsString('"type":"continue_as_new"', $probe);
        $this->assertStringContainsString('complete_workflow_task(', $probe);
        $this->assertStringContainsString('describe_workflow(&stale_workflow_id)', $probe);
        $this->assertStringContainsString('stale_run_rejection_successor', $probe);
        $this->assertStringContainsString('successor_run_id != stale_handle.run_id', $probe);
        $this->assertStringContainsString('observed_evidence: outputs.scenario_outcomes?.[failingCell]', $runner);
        $this->assertStringContainsString('observed_evidence: boundedOutputs.scenario_outcomes?.[failingCell]', $gate);
        $this->assertStringContainsString(
            "const REQUIRED_ARTIFACTS = ['server', 'cli', 'workflow', 'sdk-php', 'sdk-python', 'sdk-rust', 'waterline'];",
            $gate,
        );
        $this->assertStringContainsString('ActivityTaskRejected', $probe);
        $this->assertStringContainsString('start_workflow_with_options', $probe);
        $this->assertStringContainsString('WorkflowStartOptions::new()', $probe);
        $this->assertStringContainsString('"observation_source":"WorkflowHandle::result"', $probe);
        $this->assertStringContainsString('"restart_phase":"cancellation_pending"', $probe);
        $this->assertStringContainsString('wait_observed_at(&replacement_poll_started_at).await?', $probe);
        $this->assertStringContainsString('"replacement_poll_start_observed":replacement_poll_start_observed', $probe);
        $this->assertStringContainsString('replacement_poll_started_elapsed_ns', $probe);
        $this->assertStringContainsString('restartOutcome.replacement_poll_start_observed', $gate);
        $this->assertStringContainsString('replacementPollStartedAt < settlementReleasedAt', $gate);
        $this->assertStringContainsString('CompletionRetryProxy', $probe);
        $this->assertStringContainsString('run_transition_phase_process("predecessor"', $probe);
        $this->assertStringContainsString('run_transition_phase_process("successor"', $probe);
        $this->assertStringContainsString('continue_as_new_replay_boundary', $gate);
        $this->assertStringContainsString('workflow_task_completion_redelivery_rejected', $probe);
        $this->assertStringNotContainsString('result_wait_timeout', $probe);
    }

    public function test_php_sdk_probe_uses_the_released_package_across_process_boundaries(): void
    {
        $runner = (string) file_get_contents(dirname(__DIR__, 2).'/scripts/conformance/php-sdk-published-artifacts.sh');

        $this->assertStringContainsString('durable-workflow/sdk', $runner);
        $this->assertStringContainsString('start_worker php-sdk-worker-1', $runner);
        $this->assertStringContainsString('start_worker php-sdk-worker-2', $runner);
        $this->assertStringContainsString('initial_client_phase=baseline', $runner);
        $this->assertStringContainsString('run_client_phase "$initial_client_phase" "$initial_client_output"', $runner);
        $this->assertStringContainsString('wait-replay-checkpoint', $runner);
        $this->assertStringContainsString('apache_avro_provenance', $runner);
        $this->assertStringContainsString('php-sdk-assertion-failure-evidence.php', $runner);
        $this->assertStringContainsString('record_operation_response(', $runner);
        $this->assertStringContainsString('php_sdk_assertion_failure_evidence(', $runner);
        $this->assertStringContainsString("'assertion_failures' => \$assertionFailures", $runner);
        $this->assertStringContainsString('local_product_source_checkouts_used', $runner);
        $this->assertStringNotContainsString('durable-workflow/workflow:', $runner);
    }

    public function test_python_sdk_probe_uses_explicit_python_binary_without_docker(): void
    {
        $resultDir = sys_get_temp_dir().'/dw-workflow-lifecycle-'.bin2hex(random_bytes(6));
        $fakeBin = sys_get_temp_dir().'/dw-workflow-lifecycle-bin-'.bin2hex(random_bytes(6));
        mkdir($resultDir, 0777, true);
        mkdir($fakeBin, 0777, true);

        file_put_contents($fakeBin.'/python-explicit', <<<'SH'
#!/usr/bin/env bash
cat > "$RESULT_DIR/python-sdk-lifecycle-evidence.json" <<JSON
{
  "schema": "durable-workflow.v2.workflow-lifecycle.python-sdk-sidecar",
  "runner_blocked": false,
  "scenario_results": {
    "python_sdk_lifecycle_surface": {
      "status": "pass",
      "published_artifact_cell_executed": true,
      "observed_outputs": {
        "sdk": "sdk-python",
        "covered_cells": ["pypi_artifact_imported", "workflow_client_start_with_duplicate_policy_and_timeout_budgets"],
        "unsupported_cells": ["workflow_level_retry_policy"],
        "typed_errors": [
          {
            "cell": "workflow_level_retry_policy",
            "typed_error": "InvalidArgument",
            "refusal_reason": "The retry_policy field is not supported by the v2 workflow start API.",
            "documented": true
          }
        ],
        "artifact_version": "$DW_PYTHON_SDK_VERSION",
        "artifact_source": "pypi://durable-workflow==$DW_PYTHON_SDK_VERSION",
        "pypi_artifact_verified": true,
        "published_artifact_cell_executed": true,
        "local_product_source_checkouts_used": false,
        "probe_executor": "$PYTHON_SDK_PROBE_EXECUTOR"
      }
    }
  }
}
JSON
exit 0
SH);
        chmod($fakeBin.'/python-explicit', 0755);

        try {
            exec($this->runnerCommand($resultDir, [
                'PATH' => '/usr/local/bin:/usr/bin:/bin',
                'DW_WORKFLOW_LIFECYCLE_SKIP_PYTHON_SDK_PROBE' => '0',
                'DW_WORKFLOW_LIFECYCLE_PYTHON_BIN' => $fakeBin.'/python-explicit',
            ]), $output, $exitCode);

            $this->assertSame(0, $exitCode, implode("\n", $output));

            $result = $this->readJson($resultDir.'/workflow-lifecycle-result.json');
            $pythonScenario = $result['scenario_results']['python_sdk_lifecycle_surface'];

            $this->assertFalse($result['runner_blocked']);
            $this->assertSame('pass', $pythonScenario['status']);
            $this->assertSame('configured_python_binary', $pythonScenario['observed_outputs']['probe_executor']);
            $this->assertSame('0.4.91', $pythonScenario['observed_outputs']['artifact_version']);
            $this->assertStringContainsString('python-sdk-lifecycle-evidence.json', $result['evidence_source']);
            $this->assertNotContains(
                'workflow-lifecycle-python-sdk-lifecycle-surface-runner-gap',
                array_column($result['findings'], 'finding_id'),
            );
            $this->assertSame([], WorkflowLifecycleResultGate::evaluate($result)['gate_failures']);
        } finally {
            $this->removeDirectory($resultDir);
            $this->removeDirectory($fakeBin);
        }
    }

    public function test_python_sdk_probe_fixture_reproduces_runtime_discovery_request(): void
    {
        $python = trim((string) shell_exec('command -v python3 2>/dev/null'));
        if ($python === '') {
            $this->markTestSkipped('python3 is required to exercise the Python lifecycle discovery fixture.');
        }

        $fixture = dirname(__DIR__, 2).'/scripts/conformance/workflow_lifecycle_python_discovery_fixture.py';
        exec(
            escapeshellarg($python).' '.escapeshellarg($fixture).' --self-test 2>&1',
            $output,
            $exitCode,
        );

        $this->assertSame(0, $exitCode, implode("\n", $output));
        $evidence = json_decode(implode("\n", $output), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('GET', $evidence['method']);
        $this->assertSame('/api/cluster/info', $evidence['path']);
        $this->assertSame(200, $evidence['status']);
        $this->assertSame('worker_protocol.server_capabilities.query_tasks', $evidence['capability_path']);
        $this->assertTrue($evidence['capability_value']);
    }

    public function test_python_sdk_probe_classifies_an_unserved_discovery_request_as_runner_gap(): void
    {
        [$sidecar, $result] = $this->runPythonDiscoveryRejectionProbe('unserved');
        $scenario = $sidecar['scenario_results']['python_sdk_lifecycle_surface'];
        $outputs = $scenario['observed_outputs'];
        $failure = $outputs['runtime_failure_evidence'];
        $discovery = $outputs['runtime_discovery'];
        $retained = $result['scenario_results']['python_sdk_lifecycle_surface'];

        $this->assertTrue($sidecar['runner_blocked']);
        $this->assertSame('runner_blocked', $scenario['status']);
        $this->assertSame('runner-gap', $scenario['classification']);
        $this->assertTrue($scenario['published_artifact_cell_executed']);
        $this->assertSame(['Client.start_workflow', 'WorkflowHandle.signal'], $outputs['completed_operations']);
        $this->assertFalse($discovery['request_observed']);
        $this->assertFalse($discovery['fixture_response_served']);
        $this->assertNull($discovery['response_status']);
        $this->assertNull($discovery['capability_value']);
        $this->assertFalse($discovery['valid_public_response']);
        $this->assertSame('RuntimeDiscoveryUnavailable', $failure['exception_type']);
        $this->assertSame('runner-gap', $failure['classification']);
        $this->assertSame('conformance_harness', $failure['owning_surface']);
        $this->assertSame($discovery, $failure['runtime_discovery']);
        $this->assertTrue($result['runner_blocked']);
        $this->assertTrue($retained['published_artifact_cell_executed']);
        $this->assertSame($outputs['completed_operations'], $retained['observed_outputs']['completed_operations']);
    }

    public function test_python_sdk_probe_classifies_sdk_rejection_after_valid_discovery_as_product_gap(): void
    {
        [$sidecar, $result] = $this->runPythonDiscoveryRejectionProbe('valid_response');
        $scenario = $sidecar['scenario_results']['python_sdk_lifecycle_surface'];
        $outputs = $scenario['observed_outputs'];
        $failure = $outputs['runtime_failure_evidence'];
        $discovery = $outputs['runtime_discovery'];
        $finding = $scenario['linked_findings'][0];
        $retained = $result['scenario_results']['python_sdk_lifecycle_surface'];

        $this->assertFalse($sidecar['runner_blocked']);
        $this->assertSame('fail', $scenario['status']);
        $this->assertSame('product-gap', $scenario['classification']);
        $this->assertTrue($scenario['published_artifact_cell_executed']);
        $this->assertSame(['Client.start_workflow', 'WorkflowHandle.signal'], $outputs['completed_operations']);
        $this->assertTrue($discovery['request_observed']);
        $this->assertTrue($discovery['fixture_response_served']);
        $this->assertSame(200, $discovery['response_status']);
        $this->assertTrue($discovery['capability_value']);
        $this->assertTrue($discovery['valid_public_response']);
        $this->assertSame('RuntimeDiscoveryUnavailable', $failure['exception_type']);
        $this->assertSame('product-gap', $failure['classification']);
        $this->assertSame('sdk-python', $failure['owning_surface']);
        $this->assertSame($discovery, $failure['runtime_discovery']);
        $this->assertSame('product_behavior_gap', $finding['finding_type']);
        $this->assertSame('sdk-python', $finding['owning_surface']);
        $this->assertFalse($result['runner_blocked']);
        $this->assertSame('product-gap', $retained['classification']);
        $this->assertTrue($retained['published_artifact_cell_executed']);
        $this->assertSame($discovery, $retained['observed_outputs']['runtime_discovery']);
    }

    public function test_python_sdk_probe_retains_structured_pre_behavior_exception_as_runner_gap(): void
    {
        $resultDir = sys_get_temp_dir().'/dw-workflow-lifecycle-'.bin2hex(random_bytes(6));
        $fakeBin = sys_get_temp_dir().'/dw-workflow-lifecycle-bin-'.bin2hex(random_bytes(6));
        mkdir($resultDir, 0777, true);
        mkdir($fakeBin, 0777, true);

        file_put_contents($fakeBin.'/python-explicit', <<<'SH'
#!/usr/bin/env bash
echo 'Traceback (most recent call last):' >&2
printf 'durable_workflow.errors.RuntimeDiscoveryUnavailable: cluster discovery fixture was unavailable ' >&2
index=0
while [[ "$index" -lt 12000 ]]; do
  printf 'x' >&2
  index=$((index + 1))
done
printf '\n' >&2
exit 17
SH);
        chmod($fakeBin.'/python-explicit', 0755);

        try {
            exec($this->runnerCommand($resultDir, [
                'PATH' => '/usr/local/bin:/usr/bin:/bin',
                'DW_WORKFLOW_LIFECYCLE_SKIP_PYTHON_SDK_PROBE' => '0',
                'DW_WORKFLOW_LIFECYCLE_PYTHON_BIN' => $fakeBin.'/python-explicit',
            ]), $output, $exitCode);

            $this->assertSame(0, $exitCode, implode("\n", $output));
            $sidecar = $this->readJson($resultDir.'/python-sdk-lifecycle-evidence.json');
            $scenario = $sidecar['scenario_results']['python_sdk_lifecycle_surface'];
            $diagnostic = $scenario['observed_outputs']['runtime_failure_evidence'];
            $result = $this->readJson($resultDir.'/workflow-lifecycle-result.json');
            $retained = $result['scenario_results']['python_sdk_lifecycle_surface']['observed_outputs'];

            $this->assertTrue($sidecar['runner_blocked']);
            $this->assertSame('runner_blocked', $scenario['status']);
            $this->assertSame('runner-gap', $scenario['classification']);
            $this->assertFalse($scenario['published_artifact_cell_executed']);
            $this->assertSame('python_sdk_probe.execution', $diagnostic['operation']);
            $this->assertSame('runner-gap', $diagnostic['classification']);
            $this->assertSame('conformance_harness', $diagnostic['owning_surface']);
            $this->assertSame('durable_workflow.errors.RuntimeDiscoveryUnavailable', $diagnostic['exception_type']);
            $this->assertStringContainsString('cluster discovery fixture was unavailable', $diagnostic['message']);
            $this->assertSame($diagnostic['operation'], $retained['runtime_failure_evidence']['operation']);
            $this->assertSame($diagnostic['classification'], $retained['runtime_failure_evidence']['classification']);
            $this->assertSame($diagnostic['owning_surface'], $retained['runtime_failure_evidence']['owning_surface']);
            $this->assertSame($diagnostic['exception_type'], $retained['runtime_failure_evidence']['exception_type']);
            $this->assertStringContainsString(
                'cluster discovery fixture was unavailable',
                $retained['runtime_failure_evidence']['message'],
            );
            $this->assertSame(
                'python_sdk_probe.execution',
                $retained['shard_diagnostic']['operation'],
            );
            $this->assertStringNotContainsString(
                'see python-sdk-lifecycle-probe.log',
                $scenario['observed_outputs']['failure_summary'],
            );
            $this->assertLessThanOrEqual(8192, strlen(json_encode($scenario, JSON_THROW_ON_ERROR)));
        } finally {
            $this->removeDirectory($resultDir);
            $this->removeDirectory($fakeBin);
        }
    }

    public function test_published_artifact_runner_records_retry_refusal_as_pass_evidence(): void
    {
        $resultDir = sys_get_temp_dir().'/dw-workflow-lifecycle-'.bin2hex(random_bytes(6));
        mkdir($resultDir, 0777, true);
        $evidencePath = $resultDir.'/workflow-lifecycle-evidence.json';
        file_put_contents($evidencePath, json_encode($this->hostEvidenceWithRetryRefusal(), JSON_THROW_ON_ERROR));
        $this->writeRustSidecar($resultDir);

        try {
            exec($this->runnerCommand($resultDir, [
                'DW_WORKFLOW_LIFECYCLE_EVIDENCE_PATH' => $evidencePath,
            ]), $output, $exitCode);

            $this->assertSame(0, $exitCode, implode("\n", $output));
            $result = $this->readJson($resultDir.'/workflow-lifecycle-result.json');
            $retry = $result['scenario_results']['workflow_retry_backoff_or_refusal'];

            $this->assertSame('pass', $result['outcome']);
            $this->assertSame('pass', $retry['status']);
            $this->assertSame('passed', $retry['classification']);
            $this->assertSame('validation_error', $retry['observed_outputs']['typed_refusal']['typed_error']);
            $this->assertContains('workflow_retry_backoff_or_refusal', $result['proven_lifecycle_cells']);
            $this->assertNotContains('workflow_retry_backoff_or_refusal', $result['unproven_lifecycle_cells']);
            $this->assertNotContains(
                'workflow-lifecycle-workflow-retry-backoff-or-refusal-unsupported',
                array_column($result['findings'], 'finding_id'),
            );
            $this->assertNotContains(
                'workflow-lifecycle-workflow-retry-backoff-or-refusal-coverage-gap',
                array_column($result['findings'], 'finding_id'),
            );
            $this->assertSame('pass', WorkflowLifecycleResultGate::evaluate($result)['status']);
        } finally {
            $this->removeDirectory($resultDir);
        }
    }

    public function test_published_artifact_runner_has_guarded_focused_host_probes(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/scripts/conformance/workflow-lifecycle-published-artifacts.sh') ?: '';
        $phpSource = file_get_contents(dirname(__DIR__, 2).'/scripts/conformance/php-sdk-published-artifacts.sh') ?: '';

        foreach ([
            'DW_WORKFLOW_LIFECYCLE_SKIP_FOCUSED_HOST_PROBE',
            'DW_WORKFLOW_LIFECYCLE_SKIP_PHP_SDK_PROBE',
            'DW_WORKFLOW_LIFECYCLE_SKIP_PYTHON_SDK_PROBE',
            'should_run_focused_host_probes',
            'run_php_sdk_lifecycle_probe',
            'run_python_sdk_lifecycle_probe',
            'run_rust_sdk_lifecycle_probe',
            'write_python_sdk_runner_blocked',
            'workflow_lifecycle_python_discovery_fixture.py',
            'runtime_discovery_query_tasks_capability',
            'runtime_failure_evidence',
            'php-sdk-lifecycle-evidence.json',
            'python-sdk-lifecycle-evidence.json',
            'rust-sdk-lifecycle-evidence.json',
            'published-python-sdk-lifecycle-surface-probe',
            'pypi_artifact_verified',
            'DW_WORKFLOW_LIFECYCLE_PHP_BIN',
            'DW_WORKFLOW_LIFECYCLE_COMPOSER_BIN',
            'DW_WORKFLOW_LIFECYCLE_PYTHON_BIN',
            'DW_WORKFLOW_LIFECYCLE_CARGO_BIN',
            'DW_RUST_SDK_VERSION',
            'python_sdk_resolve_command',
            'python-sdk-lifecycle-venv',
            'pip install --disable-pip-version-check --no-input "durable-workflow==${sdk_version}"',
            'RESULT_DIR="$result_dir"',
            'PYTHON_SDK_PROBE_EXECUTOR',
            'workflow_client_start_with_duplicate_policy_and_timeout_budgets',
            'workflow_handle_signal_query_cancel_terminate_methods',
            'workflow_retry_policy_typed_refusal',
            'run_focused_host_probes',
            'focused_published_server_workflow_lifecycle_host_probes',
            'published-server-workflow-lifecycle-focused-host-probes',
            'workflow-lifecycle-evidence.json',
            'duplicate_worker_completion_after_continue_as_new',
            'successor_run_ids_after_duplicate',
            'cancellation_public_surface_terminal_state',
            'termination_public_surface_terminal_state',
            'workflow_id_reuse_duplicate_start_policy',
            'workflow_timeout_terminal_state',
            'workflow_retry_backoff_or_refusal',
            'operator_diagnostics_surfaces',
            'run_workflow_timeout_terminal_state_probe',
            'run_workflow_retry_backoff_or_refusal_probe',
            'run_operator_diagnostics_surfaces_probe',
            'diagnostic_transition_matrix',
            'cli_fields',
            'api_fields',
            'history_fields',
            'waterline_fields',
            'Waterline flow detail observer state',
            'OperatorObservabilityRepository::runDetail',
            'unsupported_timeout_shape_refusals',
            'workflow_run_timeout',
            'workflow_task_timeout',
            'retry_policy',
            'unsupported_retry_policy_refusal',
            'retry_policy_typed_refusal',
            'counted_as_pass_evidence',
            'WorkflowTimedOut',
            'run_timeout_seconds',
            'run_duplicate_start_policy_probe',
            "'duplicate_policy' => 'fail'",
            'duplicate_start_http_status',
            'duplicate_start_rejection_reason',
            'run_count_after_duplicate',
            'run_ids_after_duplicate',
            'refused_without_creating_or_replacing_run',
            'server_api_run_targeted',
            'run_not_active_',
            'run_cancelled',
            'run_terminated',
            'WorkflowCancelled',
            'WorkflowTerminated',
            'if [[ "$repo_root" != "/app" || -d "$repo_root/.git" ]]; then',
            'local_product_source_checkout_used_as_pass_evidence',
            'published_artifact_cell_executed',
        ] as $token) {
            $this->assertStringContainsString($token, $source);
        }

        foreach ([
            'durable-workflow/sdk',
            'packagist_artifact_verified',
            'COMPOSER_ALLOW_SUPERUSER=1',
            'start_worker php-sdk-worker-1',
            'start_worker php-sdk-worker-2',
            'client_worker_distinct_processes',
            'apache_avro_provenance',
            'local_product_source_checkouts_used',
            'php-sdk-companion-failure-evidence.cjs',
            'capture_client_companion',
        ] as $token) {
            $this->assertStringContainsString($token, $phpSource);
        }

        $hostSource = file_get_contents(dirname(__DIR__, 2).'/scripts/conformance/workflow-lifecycle-host-published-artifacts.sh') ?: '';
        foreach ([
            'required host command not found',
            'server-bootstrap',
            'DW_SERVER_PROCESS_CLASS=server_http_node',
            'DW_SERVER_PROCESS_CLASS=scheduler_node',
            'schedule:evaluate --limit=100 --json',
            'workflow-lifecycle-rust-published-artifacts.mjs',
            'DW_WORKFLOW_LIFECYCLE_SERVER_HTTP_PROCESS=exact_published_image',
            'DW_WORKFLOW_LIFECYCLE_SCHEDULER_PROCESS=exact_published_image',
            'DW_WORKFLOW_LIFECYCLE_RUST_EXECUTOR=host_rust_container',
            'DW_WORKFLOW_LIFECYCLE_SKIP_RUST_SDK_PROBE=1',
            'docker logs --follow "$server_name"',
            'DW_PHP_SDK_CONFORMANCE_SERVER_LOG=/result/workflow-lifecycle-server-process.log',
            'DW_PHP_SDK_CONFORMANCE_SCHEDULER_LOG=/result/workflow-lifecycle-scheduler-process.log',
            'workflow-lifecycle-result.json',
        ] as $token) {
            $this->assertStringContainsString($token, $hostSource);
        }
    }

    public function test_result_gate_rejects_pass_when_required_provenance_is_missing(): void
    {
        $result = $this->completeLifecycleResult();
        unset(
            $result['artifact_sources'],
            $result['lifecycle_cell_outcomes'],
            $result['findings'],
            $result['local_product_source_checkouts_used'],
            $result['source_policy'],
        );

        $evaluation = WorkflowLifecycleResultGate::evaluate($result);
        $missingFields = $this->missingRunRecordFields($evaluation);

        $this->assertSame('non_passing', $evaluation['status']);
        foreach ([
            'artifact_sources',
            'lifecycle_cell_outcomes',
            'findings',
            'local_product_source_checkouts_used',
            'source_policy',
        ] as $field) {
            $this->assertContains($field, $missingFields);
        }
        $this->assertContains('missing_source_policy', array_column($evaluation['gate_failures'], 'code'));
        $this->assertContains('declared_outcome_mismatch', array_column($evaluation['gate_failures'], 'code'));
    }

    /**
     * @dataProvider truthyLocalSourceMarkers
     */
    public function test_result_gate_rejects_alternate_truthy_local_source_markers(mixed $marker): void
    {
        $result = $this->completeLifecycleResult();
        $result['local_product_source_checkouts_used'] = $marker;

        $evaluation = WorkflowLifecycleResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'local_product_source_checkout_used'
                && ($failure['field'] ?? null) === 'local_product_source_checkouts_used',
        ));
    }

    public function test_result_gate_rejects_nested_truthy_local_source_markers_consistently(): void
    {
        $result = $this->completeLifecycleResult();
        $scenarioId = 'continue_as_new_run_chain_visibility';
        $result['source_policy']['local_product_source_checkout_used_as_pass_evidence'] = 'yes';
        $result['lifecycle_cell_outcomes'][$scenarioId]['localProductSourceCheckoutsUsed'] = '1';
        $result['scenario_results'][$scenarioId]['observed_outputs']['local_product_source_checkouts_used'] = 'on';

        $evaluation = WorkflowLifecycleResultGate::evaluate($result);
        $sourceFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'local_product_source_checkout_used',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        foreach ([
            'source_policy',
            'lifecycle_cell_outcomes.localProductSourceCheckoutsUsed',
            'observed_outputs.local_product_source_checkouts_used',
        ] as $field) {
            $this->assertNotEmpty(array_filter(
                $sourceFailures,
                static fn (array $failure): bool => ($failure['field'] ?? null) === $field,
            ), $field);
        }
    }

    public function test_result_gate_rejects_contradictory_source_policy(): void
    {
        $result = $this->completeLifecycleResult();
        $result['source_policy']['published_artifacts_only'] = 'off';
        $result['source_policy']['allows_local_product_source_checkout_pass_evidence'] = 'on';

        $evaluation = WorkflowLifecycleResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('source_policy_must_require_published_artifacts', $failureCodes);
        $this->assertContains('source_policy_allows_local_product_source_pass_evidence', $failureCodes);
    }

    public function test_result_gate_requires_focused_findings_for_non_pass_lifecycle_cells(): void
    {
        $result = $this->completeLifecycleResult();
        $scenarioId = 'workflow_retry_backoff_or_refusal';
        $result['outcome'] = 'fail';
        $result['scenario_results'][$scenarioId]['status'] = 'not_covered';
        $result['scenario_results'][$scenarioId]['lifecycle_cell_outcome'] = 'not_covered';
        $result['lifecycle_cell_outcomes'][$scenarioId]['status'] = 'not_covered';

        $evaluation = WorkflowLifecycleResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains($scenarioId, $evaluation['non_pass_scenarios']);
        $this->assertContains(
            [
                'code' => 'missing_focused_finding_for_non_pass_cell',
                'scenario_id' => $scenarioId,
                'status' => 'not_covered',
            ],
            $evaluation['gate_failures'],
        );
    }

    public function test_result_gate_rejects_pass_claim_without_published_artifact_execution_marker(): void
    {
        $result = $this->completeLifecycleResult();

        foreach (array_keys($result['scenario_results']) as $scenarioId) {
            unset($result['scenario_results'][$scenarioId]['observed_outputs']['published_artifact_cell_executed']);
        }

        $evaluation = WorkflowLifecycleResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('missing_published_artifact_cell_execution', $failureCodes);
        $this->assertContains('declared_outcome_mismatch', $failureCodes);
    }

    public function test_result_gate_rejects_contradictory_pass_lifecycle_evidence(): void
    {
        $result = $this->completeLifecycleResult();
        $result['scenario_results']['continue_as_new_run_chain_visibility']['observed_outputs']['continued_run_id'] = 'run-initial';
        $result['scenario_results']['continue_as_new_duplicate_side_effect_prevention']['observed_outputs']['observed_count'] = 2;
        $result['scenario_results']['cancellation_public_surface_terminal_state']['observed_outputs']['terminal_status'] = 'completed';
        $result['scenario_results']['termination_public_surface_terminal_state']['observed_outputs']['terminal_status'] = 'completed';
        $result['scenario_results']['workflow_id_reuse_duplicate_start_policy']['observed_outputs']['duplicate_start_outcome'] = 'accepted';
        $result['scenario_results']['workflow_timeout_terminal_state']['observed_outputs']['terminal_status'] = 'completed';
        $result['scenario_results']['workflow_timeout_terminal_state']['observed_outputs']['observed_terminal_at'] = '2026-06-28T00:00:10Z';
        $result['scenario_results']['workflow_retry_backoff_or_refusal']['observed_outputs']['docs_match'] = false;

        $evaluation = WorkflowLifecycleResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('continue_as_new_run_ids_not_distinct', $failureCodes);
        $this->assertContains('duplicate_side_effect_count_mismatch', $failureCodes);
        $this->assertContains('cancellation_terminal_status_invalid', $failureCodes);
        $this->assertContains('termination_terminal_status_invalid', $failureCodes);
        $this->assertContains('duplicate_start_accepted', $failureCodes);
        $this->assertContains('workflow_timeout_terminal_status_invalid', $failureCodes);
        $this->assertContains('workflow_timeout_terminal_before_deadline', $failureCodes);
        $this->assertContains('workflow_retry_docs_mismatch', $failureCodes);
        $this->assertContains('declared_outcome_mismatch', $failureCodes);
    }

    public function test_result_gate_rejects_timeout_pass_without_typed_unsupported_shape_refusals(): void
    {
        $result = $this->completeLifecycleResult();
        unset($result['scenario_results']['workflow_timeout_terminal_state']['observed_outputs']['unsupported_timeout_shape_refusals']);

        $evaluation = WorkflowLifecycleResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains([
            'code' => 'missing_scenario_required_field',
            'scenario_id' => 'workflow_timeout_terminal_state',
            'field' => 'unsupported_timeout_shape_refusals',
        ], $evaluation['gate_failures']);
        $this->assertContains('workflow_timeout_refusals_missing', $failureCodes);
        $this->assertContains('declared_outcome_mismatch', $failureCodes);
    }

    public function test_result_gate_rejects_duplicate_start_refusal_that_creates_or_replaces_run(): void
    {
        $extraRunResult = $this->completeLifecycleResult();
        $extraRunResult['scenario_results']['workflow_id_reuse_duplicate_start_policy']['observed_outputs']['run_count_after_duplicate'] = 2;
        $extraRunResult['scenario_results']['workflow_id_reuse_duplicate_start_policy']['observed_outputs']['run_ids_after_duplicate'] = [
            'run-first',
            'run-extra',
        ];

        $extraRunEvaluation = WorkflowLifecycleResultGate::evaluate($extraRunResult);

        $this->assertSame('non_passing', $extraRunEvaluation['status']);
        $this->assertContains(
            'duplicate_start_run_count_changed',
            array_column($extraRunEvaluation['gate_failures'], 'code'),
        );

        $replacedRunResult = $this->completeLifecycleResult();
        $replacedRunResult['scenario_results']['workflow_id_reuse_duplicate_start_policy']['observed_outputs']['run_ids_after_duplicate'] = [
            'run-replacement',
        ];

        $replacedRunEvaluation = WorkflowLifecycleResultGate::evaluate($replacedRunResult);

        $this->assertSame('non_passing', $replacedRunEvaluation['status']);
        $this->assertContains(
            'duplicate_start_first_run_not_preserved',
            array_column($replacedRunEvaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_accepts_retry_pass_with_documented_typed_refusal(): void
    {
        $result = $this->completeLifecycleResult();
        $scenarioId = 'workflow_retry_backoff_or_refusal';
        $result['scenario_results'][$scenarioId]['status'] = 'pass';
        $result['scenario_results'][$scenarioId]['lifecycle_cell_outcome'] = 'pass';
        $result['scenario_results'][$scenarioId]['observed_outputs'] = [
            'published_artifact_cell_executed' => true,
            'workflow_id' => 'wf-lifecycle-retry-refusal',
            'retry_policy_shape' => ['maximum_attempts' => 3],
            'attempt_count_or_refusal_reason' => 'workflow_retry_policy_not_supported',
            'backoff_observation_or_error_type' => 'WorkflowRetryPolicyUnsupported',
            'docs_match' => true,
            'typed_refusal' => [
                'typed_error' => 'WorkflowRetryPolicyUnsupported',
                'refusal_reason' => 'workflow retry policy is not part of the published lifecycle surface',
                'documented' => true,
            ],
        ];
        $result['lifecycle_cell_outcomes'][$scenarioId]['status'] = 'pass';

        $evaluation = WorkflowLifecycleResultGate::evaluate($result);

        $this->assertSame('pass', $evaluation['status']);
        $this->assertSame([], $evaluation['gate_failures']);

        unset($result['scenario_results'][$scenarioId]['observed_outputs']['typed_refusal']);
        $evaluation = WorkflowLifecycleResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'workflow_retry_backoff_not_proven',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function truthyLocalSourceMarkers(): iterable
    {
        yield 'boolean true' => [true];
        yield 'integer one' => [1];
        yield 'string one' => ['1'];
        yield 'string true' => ['true'];
        yield 'string yes' => ['yes'];
        yield 'string on' => ['on'];
    }

    /**
     * @return array<string, mixed>
     */
    private function completeLifecycleResult(): array
    {
        $artifactVersions = [
            'server' => '0.2.649',
            'cli' => '0.1.82',
            'workflow-php' => '2.0.0-alpha.224',
            'workflow' => '2.0.0-alpha.224',
            'sdk-php' => '0.1.1',
            'sdk-python' => '0.4.91',
            'sdk-rust' => '0.1.15',
            'waterline' => '2.0.0-alpha.111',
        ];
        $artifactSources = [
            'server' => 'docker://durableworkflow/server:0.2.649',
            'cli' => 'github-release://durable-workflow/cli/v0.1.82/install.sh',
            'workflow-php' => 'packagist://durable-workflow/workflow:2.0.0-alpha.224',
            'workflow' => 'packagist://durable-workflow/workflow:2.0.0-alpha.224',
            'sdk-php' => 'packagist://durable-workflow/sdk@0.1.1',
            'sdk-python' => 'pypi://durable-workflow/0.4.91',
            'sdk-rust' => 'crates.io://durable-workflow@0.1.15',
            'waterline' => 'npm://durable-workflow-waterline/2.0.0-alpha.111',
        ];
        $sourcePolicy = [
            'published_artifacts_only' => true,
            'published_artifact_evidence_only' => true,
            'local_product_source_checkouts_used' => false,
            'local_product_source_checkout_used_as_pass_evidence' => false,
            'statement' => 'Workflow lifecycle conformance ran against pinned published artifacts.',
        ];

        $scenarioResults = [];
        $cellOutcomes = [];
        foreach (WorkflowLifecycleContract::manifest()['required_scenarios'] as $scenarioId) {
            $scenarioResults[$scenarioId] = [
                'scenario_id' => $scenarioId,
                'status' => 'pass',
                'lifecycle_cell_outcome' => 'pass',
                'artifact_sources' => $artifactSources,
                'local_product_source_checkouts_used' => false,
                'observed_outputs' => $this->outputsForScenario($scenarioId),
            ];
            $cellOutcomes[$scenarioId] = [
                'status' => 'pass',
                'observed_at' => '2026-06-28T00:01:00Z',
                'local_product_source_checkouts_used' => false,
            ];
        }

        return [
            'schema' => WorkflowLifecycleContract::RESULT_SCHEMA,
            'version' => WorkflowLifecycleContract::RESULT_VERSION,
            'artifact_versions' => $artifactVersions,
            'published_artifact_versions' => $artifactVersions,
            'artifact_sources' => $artifactSources,
            'started_at' => '2026-06-28T00:00:00Z',
            'finished_at' => '2026-06-28T00:05:00Z',
            'generated_at' => '2026-06-28T00:05:01Z',
            'outcome' => 'pass',
            'runner_blocked' => false,
            'scenario_results' => $scenarioResults,
            'lifecycle_cell_outcomes' => $cellOutcomes,
            'findings' => [],
            'local_product_source_checkouts_used' => false,
            'source_policy' => $sourcePolicy,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function hostEvidence(): array
    {
        $result = $this->completeLifecycleResult();

        return [
            'schema' => 'durable-workflow.v2.workflow-lifecycle.host-evidence',
            'artifact_versions' => $result['artifact_versions'],
            'artifact_sources' => $result['artifact_sources'],
            'source_policy' => $result['source_policy'],
            'local_product_source_checkouts_used' => false,
            'scenario_results' => array_map(
                static fn (array $scenario): array => [
                    'status' => 'pass',
                    'published_artifact_cell_executed' => true,
                    'observed_outputs' => $scenario['observed_outputs'],
                ],
                $result['scenario_results'],
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function hostEvidenceWithRetryRefusal(): array
    {
        $evidence = $this->hostEvidence();
        $scenarioId = 'workflow_retry_backoff_or_refusal';

        $evidence['scenario_results'][$scenarioId] = [
            'status' => 'pass',
            'classification' => 'passed',
            'published_artifact_cell_executed' => true,
            'observed_outputs' => [
                'published_artifact_cell_executed' => true,
                'local_product_source_checkouts_used' => false,
                'workflow_id' => 'wf-retry-refusal',
                'retry_policy_shape' => [
                    'maximum_attempts' => 3,
                    'initial_interval_seconds' => 1,
                    'backoff_coefficient' => 2.0,
                ],
                'attempt_count_or_refusal_reason' => 'The retry_policy field is not supported by the v2 workflow start API.',
                'backoff_observation_or_error_type' => 'validation_error',
                'docs_match' => true,
                'typed_refusal' => [
                    'typed_error' => 'validation_error',
                    'refusal_reason' => 'The retry_policy field is not supported by the v2 workflow start API.',
                    'documented' => true,
                    'http_status' => 422,
                    'field' => 'retry_policy',
                ],
                'unsupported_retry_policy_refusal' => [
                    'shape' => 'workflow_retry_policy',
                    'field' => 'retry_policy',
                    'http_status' => 422,
                    'typed_error' => 'validation_error',
                    'refusal_reason' => 'The retry_policy field is not supported by the v2 workflow start API.',
                    'documented' => true,
                    'counted_as_pass_evidence' => true,
                ],
            ],
        ];

        return $evidence;
    }

    /**
     * @return array<string, mixed>
     */
    private function outputsForScenario(string $scenarioId): array
    {
        $common = [
            'published_artifact_cell_executed' => true,
            'local_product_source_checkouts_used' => false,
        ];

        return $common + match ($scenarioId) {
            'continue_as_new_run_chain_visibility' => [
                'workflow_id' => 'wf-continue-as-new',
                'initial_run_id' => 'run-initial',
                'continued_run_id' => 'run-continued',
                'run_count' => 2,
                'current_run_id' => 'run-continued',
                'run_numbers' => [1, 2],
            ],
            'continue_as_new_identity_and_history_continuity' => [
                'workflow_id' => 'wf-continue-as-new',
                'history_events' => ['WorkflowStarted', 'WorkflowContinuedAsNew', 'WorkflowStarted'],
                'predecessor_closed_event' => 'WorkflowContinuedAsNew',
                'successor_started_event' => 'WorkflowStarted',
                'history_api_links' => ['/api/workflows/wf-continue-as-new/runs/run-initial/history'],
            ],
            'continue_as_new_duplicate_side_effect_prevention' => [
                'workflow_id' => 'wf-continue-as-new',
                'side_effect_key' => 'workflow-lifecycle-side-effect',
                'expected_count' => 1,
                'observed_count' => 1,
                'replay_or_restart_window' => 'continue_as_new_replay',
            ],
            'cancellation_public_surface_terminal_state' => [
                'workflow_id' => 'wf-cancel',
                'request_surface' => 'api',
                'cancel_requested_at' => '2026-06-28T00:00:10Z',
                'terminal_status' => 'cancelled',
                'worker_error_type' => 'WorkflowCancelledError',
                'caller_error_type' => 'WorkflowCancelledError',
            ],
            'termination_public_surface_terminal_state' => [
                'workflow_id' => 'wf-terminate',
                'request_surface' => 'api',
                'terminate_requested_at' => '2026-06-28T00:00:10Z',
                'terminal_status' => 'terminated',
                'worker_error_type' => 'WorkflowTerminatedError',
                'caller_error_type' => 'WorkflowTerminatedError',
            ],
            'workflow_id_reuse_duplicate_start_policy' => [
                'workflow_id' => 'wf-duplicate-start',
                'duplicate_policy' => 'fail',
                'first_start_outcome' => 'started',
                'first_run_id' => 'run-first',
                'duplicate_start_outcome' => 'refused',
                'http_status_or_error_type' => '409 duplicate_workflow_id',
                'run_count_after_duplicate' => 1,
                'run_ids_after_duplicate' => ['run-first'],
            ],
            'workflow_timeout_terminal_state' => [
                'workflow_id' => 'wf-timeout',
                'timeout_field' => 'run_timeout_seconds',
                'deadline_at' => '2026-06-28T00:00:30Z',
                'observed_terminal_at' => '2026-06-28T00:00:31Z',
                'terminal_status' => 'timed_out',
                'operator_visible_timing' => ['api' => true, 'history' => true],
                'unsupported_timeout_shape_refusals' => [
                    [
                        'shape' => 'workflow_run_timeout',
                        'field' => 'workflow_run_timeout',
                        'http_status' => 422,
                        'typed_error' => 'validation_error',
                        'refusal_reason' => 'Use run_timeout_seconds instead of workflow_run_timeout.',
                        'documented' => true,
                        'counted_as_pass_evidence' => false,
                    ],
                    [
                        'shape' => 'workflow_task_timeout',
                        'field' => 'workflow_task_timeout',
                        'http_status' => 422,
                        'typed_error' => 'validation_error',
                        'refusal_reason' => 'The workflow_task_timeout field is not supported by the v2 workflow start API.',
                        'documented' => true,
                        'counted_as_pass_evidence' => false,
                    ],
                ],
            ],
            'workflow_retry_backoff_or_refusal' => [
                'workflow_id' => 'wf-retry',
                'retry_policy_shape' => ['maximum_attempts' => 2, 'initial_interval_seconds' => 1],
                'attempt_count_or_refusal_reason' => 2,
                'backoff_observation_or_error_type' => 'backoff_elapsed',
                'docs_match' => true,
            ],
            'php_sdk_lifecycle_surface' => [
                'sdk' => 'sdk-php',
                'covered_cells' => ['start', 'cancel', 'result'],
                'unsupported_cells' => [],
                'typed_errors' => [],
                'artifact_version' => '0.1.1',
                'server_version' => '0.2.649',
                'install_provenance' => ['package' => 'durable-workflow/sdk', 'source' => 'packagist'],
                'apache_avro_provenance' => ['package' => 'apache/avro', 'source' => 'packagist'],
                'client_processes' => [['process_id' => 1001]],
                'worker_processes' => [['process_id' => 2001], ['process_id' => 2002]],
                'callback_counts' => ['activity' => 2],
                'history_assertions' => ['activity_completed_before_restart' => true],
                'local_product_source_checkouts_used' => false,
            ],
            'python_sdk_lifecycle_surface' => [
                'sdk' => 'sdk-python',
                'covered_cells' => ['start', 'cancel', 'result'],
                'unsupported_cells' => [],
                'typed_errors' => [],
                'artifact_version' => '0.4.91',
            ],
            'rust_sdk_lifecycle_surface' => [
                'sdk' => 'sdk-rust',
                'covered_cells' => [
                    'instance_cancel',
                    'instance_terminate',
                    'selected_run_guard',
                    'stale_run_rejection',
                    'typed_failed',
                    'typed_cancelled',
                    'typed_terminated',
                    'typed_timed_out',
                    'cancellation_heartbeat',
                    'late_activity_completion_refused',
                    'worker_restart_during_cancellation',
                    'continue_as_new_replay_boundary',
                ],
                'unsupported_cells' => [],
                'typed_errors' => [],
                'artifact_version' => '0.1.15',
                'server_version' => '0.2.649',
                'server_cluster_info' => ['version' => '0.2.649'],
                'install_provenance' => [
                    'package' => 'durable-workflow',
                    'requested_version' => '0.1.15',
                    'installed_version' => '0.1.15',
                    'registry_source' => 'registry+https://index.crates.io',
                    'registry_checksum_sha256' => str_repeat('a', 64),
                ],
                'workflow_identities' => [
                    ['scenario' => 'instance_cancel', 'workflow_id' => 'rust-cancel', 'run_id' => 'rust-run-cancel'],
                    ['scenario' => 'instance_terminate', 'workflow_id' => 'rust-terminate', 'run_id' => 'rust-run-terminate'],
                    ['scenario' => 'selected_run_guard', 'workflow_id' => 'rust-selected', 'run_id' => 'rust-run-selected'],
                    ['scenario' => 'typed_failed', 'workflow_id' => 'rust-failed', 'run_id' => 'rust-run-failed'],
                    ['scenario' => 'typed_timed_out', 'workflow_id' => 'rust-timeout', 'run_id' => 'rust-run-timeout'],
                    ['scenario' => 'continue_as_new_replay_boundary_predecessor', 'workflow_id' => 'rust-continue', 'run_id' => 'rust-run-predecessor'],
                    ['scenario' => 'continue_as_new_replay_boundary_successor', 'workflow_id' => 'rust-continue', 'run_id' => 'rust-run-successor'],
                ],
                'scenario_outcomes' => [
                    'instance_cancel' => ['status' => 'pass', 'command_status' => 'accepted', 'target_scope' => 'instance', 'typed_outcome' => 'WorkflowCancelled', 'reason' => 'run_cancelled'],
                    'instance_terminate' => ['status' => 'pass', 'command_status' => 'accepted', 'target_scope' => 'instance', 'typed_outcome' => 'WorkflowTerminated', 'reason' => 'run_terminated'],
                    'selected_run_guard' => ['status' => 'pass', 'command_status' => 'accepted', 'target_scope' => 'run', 'workflow_id' => 'rust-selected', 'run_id' => 'rust-run-selected'],
                    'stale_run_rejection' => [
                        'status' => 'pass',
                        'typed_error' => 'WorkflowCommandRejected',
                        'http_status' => 409,
                        'reason' => 'historical_run_command_rejected',
                        'target_scope' => 'run',
                        'workflow_id' => 'rust-selected',
                        'run_id' => 'rust-run-selected',
                        'prior_run_id' => 'rust-run-selected',
                        'successor_run_id' => 'rust-run-selected-successor',
                        'successor_workflow_id' => 'rust-selected',
                    ],
                    'typed_failed' => ['status' => 'pass', 'typed_outcome' => 'WorkflowFailed'],
                    'typed_cancelled' => ['status' => 'pass', 'typed_outcome' => 'WorkflowCancelled'],
                    'typed_terminated' => ['status' => 'pass', 'typed_outcome' => 'WorkflowTerminated'],
                    'typed_timed_out' => ['status' => 'pass', 'typed_outcome' => 'WorkflowTimedOut', 'reason' => 'run_timeout', 'failure_category' => 'timeout', 'observation_source' => 'WorkflowHandle::result', 'server_terminal' => true, 'server_closed_reason' => 'timed_out'],
                    'cancellation_heartbeat' => ['status' => 'pass', 'cancel_requested' => true, 'should_stop' => true, 'reason' => 'run_cancelled', 'run_closed_reason' => 'cancelled'],
                    'late_activity_completion_refused' => ['status' => 'pass', 'typed_error' => 'ActivityTaskRejected', 'http_status' => 409, 'reason' => 'run_cancelled'],
                    'worker_restart_during_cancellation' => ['status' => 'pass', 'restart_phase' => 'cancellation_pending', 'replacement_registered' => true, 'replacement_poll_start_observed' => true, 'original_activity_unsettled_when_replacement_poll_started' => true, 'replacement_started_before_original_settled' => true, 'settlement_released_after_replacement_started' => true, 'original_settled_after_restart' => true, 'replacement_poll_started_elapsed_ns' => 10, 'settlement_released_elapsed_ns' => 20, 'original_settlement_observed_elapsed_ns' => 30],
                    'continue_as_new_replay_boundary' => [
                        'status' => 'pass',
                        'workflow_id' => 'rust-continue',
                        'predecessor_run_id' => 'rust-run-predecessor',
                        'successor_run_id' => 'rust-run-successor',
                        'current_run_id' => 'rust-run-successor',
                        'selected_historical_run_id' => 'rust-run-predecessor',
                        'selected_historical_closed_reason' => 'continued',
                        'run_chain' => [
                            'workflow_id' => 'rust-continue',
                            'run_count' => 2,
                            'runs' => [
                                ['run_id' => 'rust-run-predecessor', 'run_number' => 1, 'status' => 'completed'],
                                ['run_id' => 'rust-run-successor', 'run_number' => 2, 'status' => 'completed'],
                            ],
                        ],
                        'predecessor_history' => [
                            'workflow_id' => 'rust-continue',
                            'run_id' => 'rust-run-predecessor',
                            'events' => [
                                ['event_type' => 'WorkflowStarted', 'payload' => []],
                                ['event_type' => 'SideEffectRecorded', 'payload' => ['sequence' => 1]],
                                ['event_type' => 'VersionMarkerRecorded', 'payload' => ['sequence' => 2]],
                                ['event_type' => 'WorkflowContinuedAsNew', 'payload' => ['continued_to_run_id' => 'rust-run-successor']],
                            ],
                        ],
                        'successor_history' => [
                            'workflow_id' => 'rust-continue',
                            'run_id' => 'rust-run-successor',
                            'events' => [
                                ['event_type' => 'WorkflowStarted', 'payload' => ['continued_from_run_id' => 'rust-run-predecessor']],
                                ['event_type' => 'SideEffectRecorded', 'payload' => ['sequence' => 1]],
                                ['event_type' => 'VersionMarkerRecorded', 'payload' => ['sequence' => 2]],
                                ['event_type' => 'WorkflowCompleted', 'payload' => []],
                            ],
                        ],
                        'predecessor_history_event_counts' => [
                            'SideEffectRecorded' => 1,
                            'VersionMarkerRecorded' => 1,
                            'WorkflowContinuedAsNew' => 1,
                        ],
                        'successor_history_event_counts' => [
                            'SideEffectRecorded' => 1,
                            'VersionMarkerRecorded' => 1,
                            'WorkflowContinuedAsNew' => 0,
                        ],
                        'predecessor_transition_link' => ['continued_to_run_id' => 'rust-run-successor'],
                        'successor_transition_link' => ['continued_from_run_id' => 'rust-run-predecessor'],
                        'predecessor_worker_process' => [
                            'process_id' => 101,
                            'worker_id' => 'rust-continue-predecessor-101',
                            'handled_tasks' => 1,
                            'callback_calls' => 1,
                            'completion' => [
                                'completion_delivery_count' => 2,
                                'first_response_status' => 200,
                                'first_response' => ['recorded' => true],
                                'retry_response_status' => 409,
                                'retry_response' => ['reason' => 'task_not_leased'],
                                'command_types' => ['record_side_effect', 'record_version_marker', 'continue_as_new'],
                                'commands' => [
                                    ['type' => 'record_side_effect'],
                                    ['type' => 'record_version_marker'],
                                    ['type' => 'continue_as_new'],
                                ],
                            ],
                        ],
                        'successor_worker_process' => [
                            'process_id' => 202,
                            'worker_id' => 'rust-continue-successor-202',
                            'handled_tasks' => 1,
                            'callback_calls' => 1,
                            'completion' => [
                                'completion_delivery_count' => 1,
                                'first_response_status' => 200,
                                'first_response' => ['recorded' => true],
                                'command_types' => ['record_side_effect', 'record_version_marker', 'complete_workflow'],
                                'commands' => [
                                    ['type' => 'record_side_effect'],
                                    ['type' => 'record_version_marker'],
                                    ['type' => 'complete_workflow'],
                                ],
                            ],
                        ],
                        'final_result' => [
                            'status' => 'completed',
                            'workflow_id' => 'rust-continue',
                            'run_id' => 'rust-run-successor',
                            'successor_version' => 3,
                        ],
                        'final_result_observation_source' => 'WorkflowHandle::result',
                        'current_run_observation_source' => 'WorkflowHandle::describe',
                        'selected_run_observation_source' => 'WorkflowHandle::describe_selected_run',
                        'predecessor_decisions_immutable' => true,
                        'successor_decisions_are_new_run_decisions' => true,
                        'successor_count' => 1,
                    ],
                ],
                'stable_reasons' => ['run_cancelled', 'run_terminated', 'historical_run_command_rejected', 'run_timeout', 'workflow_task_completion_redelivery_rejected'],
                'payload_contract' => [
                    'codec' => 'avro',
                    'envelope_contract' => 'durable-workflow-published-envelope',
                    'apache_avro_package' => 'apache-avro',
                    'official_crates_io_provenance' => true,
                    'apache_avro_registry_source' => 'registry+https://index.crates.io',
                    'apache_avro_registry_checksum_sha256' => str_repeat('b', 64),
                ],
                'executor_topology' => [
                    'server_http_process' => 'exact_published_image',
                    'scheduler_process' => 'exact_published_image',
                    'rust_executor' => 'host_rust_container',
                    'rust_executor_outside_server_image' => true,
                ],
                'rust_shard_contract_version' => 3,
                'probe_outcome' => 'pass',
                'shard_runner' => 'published-rust-sdk-lifecycle-surface-probe',
                'shard_exit_status' => 0,
            ],
            'operator_diagnostics_surfaces' => [
                'workflow_id' => 'wf-diagnostics',
                'cli_fields' => ['workflow_id', 'run_id', 'status'],
                'api_fields' => ['workflow_id', 'run_id', 'status'],
                'history_fields' => ['event_type', 'event_id'],
                'waterline_fields' => ['status', 'history'],
                'diagnostic_transition_matrix' => ['started' => 'completed'],
            ],
            default => [
                'workflow_id' => 'wf-'.$scenarioId,
            ],
        };
    }

    /**
     * @param  array<string, string>  $extraEnv
     */
    private function runnerCommand(string $resultDir, array $extraEnv = []): string
    {
        $repoRoot = dirname(__DIR__, 2);
        $env = array_merge([
            'DW_SERVER_IMAGE' => 'durableworkflow/server:0.2.649',
            'DW_SERVER_VERSION' => '0.2.649',
            'DW_CLI_VERSION' => '0.1.82',
            'DW_PYTHON_SDK_VERSION' => '0.4.91',
            'DW_RUST_SDK_VERSION' => '0.1.15',
            'DW_PHP_SDK_VERSION' => '0.1.1',
            'DW_WORKFLOW_PHP_VERSION' => '2.0.0-alpha.224',
            'DW_WATERLINE_VERSION' => '2.0.0-alpha.111',
            'DW_WORKFLOW_LIFECYCLE_SKIP_PHP_SDK_PROBE' => '1',
            'DW_WORKFLOW_LIFECYCLE_SKIP_PYTHON_SDK_PROBE' => '1',
            'DW_WORKFLOW_LIFECYCLE_SKIP_RUST_SDK_PROBE' => '1',
        ], $extraEnv);

        $envPrefix = implode(' ', array_map(
            static fn (string $name, string $value): string => $name.'='.escapeshellarg($value),
            array_keys($env),
            array_values($env),
        ));

        return sprintf(
            '%s bash %s --result-dir %s 2>&1',
            $envPrefix,
            escapeshellarg($repoRoot.'/scripts/conformance/workflow-lifecycle-published-artifacts.sh'),
            escapeshellarg($resultDir),
        );
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function runPythonDiscoveryRejectionProbe(string $mode): array
    {
        $python = trim((string) shell_exec('command -v python3 2>/dev/null'));
        if ($python === '') {
            $this->markTestSkipped('python3 is required to exercise Python lifecycle discovery classification.');
        }

        $resultDir = sys_get_temp_dir().'/dw-workflow-lifecycle-'.bin2hex(random_bytes(6));
        mkdir($resultDir, 0777, true);

        try {
            exec($this->runnerCommand($resultDir, [
                'DW_WORKFLOW_LIFECYCLE_SKIP_PYTHON_SDK_PROBE' => '0',
                'DW_WORKFLOW_LIFECYCLE_PYTHON_BIN' => $python,
                'PYTHONPATH' => dirname(__DIR__).'/Fixtures/PythonDiscoverySdk',
                'PYTHON_DISCOVERY_REJECTION_MODE' => $mode,
            ]), $output, $exitCode);

            $this->assertSame(0, $exitCode, implode("\n", $output));

            return [
                $this->readJson($resultDir.'/python-sdk-lifecycle-evidence.json'),
                $this->readJson($resultDir.'/workflow-lifecycle-result.json'),
            ];
        } finally {
            $this->removeDirectory($resultDir);
        }
    }

    private function rustProducerCommand(
        string $resultDir,
        string $fakeBin,
        string $probeOutput,
        int $probeExit,
    ): string {
        $repoRoot = dirname(__DIR__, 2);
        $env = [
            'PATH' => $fakeBin.':'.(getenv('PATH') ?: '/usr/bin:/bin'),
            'RESULT_DIR' => $resultDir,
            'REPO_ROOT' => $repoRoot,
            'DW_SERVER_IMAGE' => 'durableworkflow/server:0.2.649',
            'DW_SERVER_VERSION' => '0.2.649',
            'DW_RUST_SDK_VERSION' => '0.1.15',
            'DW_WORKFLOW_LIFECYCLE_AUTH_TOKEN' => 'private-test-token',
            'DW_WORKFLOW_LIFECYCLE_SERVER_HTTP_PROCESS' => 'exact_published_image',
            'DW_WORKFLOW_LIFECYCLE_SCHEDULER_PROCESS' => 'exact_published_image',
            'DW_WORKFLOW_LIFECYCLE_RUST_EXECUTOR' => 'host_rust_container',
            'FAKE_RUST_PROBE_OUTPUT' => $probeOutput,
            'FAKE_RUST_PROBE_EXIT' => (string) $probeExit,
        ];
        $envPrefix = implode(' ', array_map(
            static fn (string $name, string $value): string => $name.'='.escapeshellarg($value),
            array_keys($env),
            array_values($env),
        ));

        return sprintf(
            '%s node %s 2>&1',
            $envPrefix,
            escapeshellarg($repoRoot.'/scripts/conformance/workflow-lifecycle-rust-published-artifacts.mjs'),
        );
    }

    private function writeFakeRustDocker(string $fakeBin): void
    {
        $script = <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
if [[ "${1:-}" == "pull" ]]; then
    exit 0
fi
if [[ " $* " == *" cargo generate-lockfile "* ]]; then
    mkdir -p "$RESULT_DIR/rust-sdk-lifecycle-probe"
    cat > "$RESULT_DIR/rust-sdk-lifecycle-probe/Cargo.lock" <<'LOCK'
[[package]]
name = "durable-workflow"
version = "0.1.15"
source = "registry+https://github.com/rust-lang/crates.io-index"
checksum = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"

[[package]]
name = "apache-avro"
version = "0.21.0"
source = "registry+https://github.com/rust-lang/crates.io-index"
checksum = "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"
LOCK
    exit 0
fi
if [[ " $* " == *" cargo build --locked --release "* ]]; then
    exit 0
fi
if [[ -n "${FAKE_RUST_PROBE_OUTPUT:-}" ]]; then
    printf '%s\n' "$FAKE_RUST_PROBE_OUTPUT"
fi
exit "${FAKE_RUST_PROBE_EXIT:-1}"
SH;
        file_put_contents($fakeBin.'/docker', $script);
        chmod($fakeBin.'/docker', 0755);
    }

    private function writeRustSidecar(string $resultDir): void
    {
        file_put_contents($resultDir.'/rust-sdk-lifecycle-evidence.json', json_encode([
            'schema' => 'durable-workflow.v2.workflow-lifecycle.rust-sdk-sidecar',
            'version' => 1,
            'runner' => 'published-rust-sdk-lifecycle-surface-probe',
            'runner_blocked' => false,
            'shard_exit_status' => 0,
            'scenario_results' => [
                'rust_sdk_lifecycle_surface' => [
                    'scenario_id' => 'rust_sdk_lifecycle_surface',
                    'status' => 'pass',
                    'classification' => 'product-gap',
                    'published_artifact_cell_executed' => true,
                    'observed_outputs' => $this->outputsForScenario('rust_sdk_lifecycle_surface'),
                ],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $decoded = json_decode(file_get_contents($path) ?: '', true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path.'/'.$entry;
            if (is_dir($child)) {
                $this->removeDirectory($child);
            } else {
                @unlink($child);
            }
        }

        @rmdir($path);
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
}
