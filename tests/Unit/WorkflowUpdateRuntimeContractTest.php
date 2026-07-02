<?php

namespace Tests\Unit;

use App\Support\WorkflowUpdateRuntimeContract;
use App\Support\WorkflowUpdateRuntimeResultGate;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\PlatformConformanceSuite;

class WorkflowUpdateRuntimeContractTest extends TestCase
{
    public function test_manifest_names_update_lifecycle_scenarios_and_focused_probe(): void
    {
        $manifest = WorkflowUpdateRuntimeContract::manifest();

        $this->assertSame('durable-workflow.v2.workflow-update-runtime.contract', $manifest['schema']);
        $this->assertSame(1, WorkflowUpdateRuntimeContract::VERSION);
        $this->assertSame('durable-workflow.v2.workflow-update-runtime.result', $manifest['result_schema']);
        $this->assertSame('workflow_update_runtime_contract', $manifest['fixture_category']);
        $this->assertSame(
            PlatformConformanceSuite::SCHEMA,
            $manifest['platform_conformance_suite_authority'],
        );
        $this->assertSame(
            'static/platform-conformance/workflow-update-runtime-scenarios.json',
            $manifest['scenario_manifest']['source_path'],
        );

        foreach (['server', 'cli', 'workflow-php', 'sdk-python', 'waterline'] as $artifact) {
            $this->assertArrayHasKey($artifact, $manifest['artifact_policy']['install_channels']);
        }

        foreach ([
            'artifact_versions',
            'published_artifact_versions',
            'artifact_sources',
            'update_cell_outcomes',
            'findings',
            'local_product_source_checkouts_used',
            'source_policy',
        ] as $field) {
            $this->assertContains($field, $manifest['artifact_policy']['required_run_record_fields']);
        }

        foreach ([
            'published_artifact_install_only',
            'declared_update_contract_visibility',
            'accepted_update_control_plane_and_history',
            'running_or_waiting_update_operator_visibility',
            'completed_update_result_round_trip',
            'failed_update_outcome',
            'duplicate_request_idempotency',
            'unknown_update_refusal',
            'invalid_input_refusal',
            'payload_envelope_round_trip',
            'terminal_workflow_update_behavior',
            'principal_attribution_with_auth',
            'php_client_worker_update_surface',
            'python_client_worker_update_surface',
            'operator_diagnostics_surfaces',
        ] as $scenario) {
            $this->assertContains($scenario, $manifest['required_scenarios']);
        }

        $this->assertTrue($manifest['host_runner_contract']['host_runner_implemented']);
        $this->assertSame('not_covered', $manifest['host_runner_contract']['unexecuted_required_scenario_status']);
        $this->assertContains(
            'DW_WORKFLOW_UPDATES_EVIDENCE_PATH',
            $manifest['host_runner_contract']['evidence_inputs'],
        );
        $this->assertContains(
            'workflow-updates-focused-evidence.json',
            $manifest['host_runner_contract']['result_files'],
        );
        $this->assertContains(
            'accepted_update_control_plane_and_history',
            $manifest['host_runner_contract']['focused_probe']['covers_required_scenarios'],
        );
        $this->assertSame(
            'coverage-gap',
            $manifest['host_runner_contract']['typed_coverage_gaps']['python_client_worker_update_surface']['classification'],
        );
        $this->assertSame(WorkflowUpdateRuntimeResultGate::SCHEMA, $manifest['result_gate']['schema']);
        $this->assertContains(
            'unknown_update_invalid_input_and_terminal_workflow_refusals_are_typed',
            $manifest['result_gate']['pass_requires'],
        );
    }

    public function test_handoff_writes_non_runner_blocked_coverage_record_with_current_artifact_tuple(): void
    {
        if (trim((string) shell_exec('command -v node')) === '') {
            $this->markTestSkipped('node is required to execute the workflow updates handoff');
        }

        $root = dirname(__DIR__, 2);
        $resultDir = sys_get_temp_dir() . '/dw-workflow-updates-test-' . bin2hex(random_bytes(6));
        mkdir($resultDir, 0777, true);

        try {
            $command = sprintf(
                '%s %s %s %s %s %s %s --result-dir %s 2>&1',
                'DW_SERVER_IMAGE=' . escapeshellarg('durableworkflow/server:0.2.536'),
                'DW_SERVER_VERSION=' . escapeshellarg('0.2.536'),
                'DW_CLI_VERSION=' . escapeshellarg('0.1.82'),
                'DW_PYTHON_SDK_VERSION=' . escapeshellarg('0.4.92'),
                'DW_WORKFLOW_PHP_VERSION=' . escapeshellarg('2.0.0-alpha.241'),
                'DW_WATERLINE_VERSION=' . escapeshellarg('2.0.0-alpha.111'),
                escapeshellarg($root . '/scripts/conformance/workflow-updates-published-artifacts.sh'),
                escapeshellarg($resultDir),
            );

            exec($command, $output, $status);

            $this->assertSame(0, $status, implode("\n", $output));

            $result = json_decode((string) file_get_contents($resultDir . '/workflow-updates-result.json'), true);
            $record = json_decode((string) file_get_contents($resultDir . '/workflow-updates-record.json'), true);

            $this->assertSame('workflow-updates', $result['experiment']);
            $this->assertSame('fail', $result['outcome']);
            $this->assertFalse($result['runner_blocked']);
            $this->assertFalse($result['local_product_source_checkouts_used']);
            $this->assertTrue($result['focused_probe']['implemented']);
            $this->assertFalse($result['focused_probe']['evidence_loaded']);
            $this->assertSame('0.2.536', $result['artifact_versions']['server']);
            $this->assertSame('0.1.82', $result['artifact_versions']['cli']);
            $this->assertSame('0.4.92', $result['artifact_versions']['sdk-python']);
            $this->assertSame('2.0.0-alpha.241', $result['artifact_versions']['workflow']);
            $this->assertSame('2.0.0-alpha.111', $result['artifact_versions']['waterline']);
            $this->assertSame('durableworkflow/server:0.2.536', $result['artifact_sources']['server']);
            $this->assertSame(
                'github-release://durable-workflow/cli/v0.1.82/install.sh',
                $result['artifact_sources']['cli'],
            );
            $this->assertSame(
                'pypi://durable-workflow==0.4.92',
                $result['artifact_sources']['sdk-python'],
            );
            $this->assertSame(
                'packagist://durable-workflow/workflow@2.0.0-alpha.241',
                $result['artifact_sources']['workflow'],
            );
            $this->assertSame(
                'packagist://durable-workflow/waterline@2.0.0-alpha.111',
                $result['artifact_sources']['waterline'],
            );
            $this->assertTrue($result['source_policy']['pass_requires_published_artifacts_only']);
            $this->assertFalse($result['source_policy']['local_product_source_checkouts_used']);

            foreach ($result['scenario_results'] as $scenarioId => $scenario) {
                $this->assertSame($scenarioId, $scenario['scenario_id']);
                if ($scenarioId === 'principal_attribution_with_auth') {
                    $this->assertSame('unsupported', $scenario['status']);
                } else {
                    $this->assertSame('not_covered', $scenario['status']);
                }
                $this->assertFalse($scenario['published_artifact_cell_executed']);
                $this->assertNotEmpty($scenario['linked_findings']);
            }

            $this->assertSame('workflow-updates', $record['experiment']);
            $this->assertSame('fail', $record['outcome']);
            $this->assertFalse($record['runnerBlocked']);
            $this->assertSame('0.2.536', $record['artifactVersions']['server']);
            $this->assertSame(
                'github-release://durable-workflow/cli/v0.1.82/install.sh',
                $record['artifactSources']['cli'],
            );
            $this->assertTrue($record['sourcePolicy']['pass_requires_published_artifacts_only']);
            $this->assertStringContainsString(
                'Focused published-server workflow update runtime cells execute',
                $record['notes'][0],
            );
        } finally {
            exec('rm -rf ' . escapeshellarg($resultDir));
        }
    }

    public function test_handoff_refuses_external_pass_evidence_from_local_source_checkout(): void
    {
        if (trim((string) shell_exec('command -v node')) === '') {
            $this->markTestSkipped('node is required to execute the workflow updates handoff');
        }

        $root = dirname(__DIR__, 2);
        $resultDir = sys_get_temp_dir() . '/dw-workflow-updates-local-source-test-' . bin2hex(random_bytes(6));
        mkdir($resultDir, 0777, true);

        try {
            $scenarioResults = [];
            foreach (self::workflowUpdateRuntimeScenarioIds() as $scenarioId) {
                $scenarioResults[$scenarioId] = [
                    'scenario_id' => $scenarioId,
                    'status' => 'pass',
                    'classification' => 'product-evidence',
                    'published_artifact_cell_executed' => true,
                    'local_product_source_checkouts_used' => true,
                    'observed_outputs' => [
                        'published_artifact_cell_executed' => true,
                        'local_product_source_checkouts_used' => true,
                    ],
                    'linked_findings' => [],
                ];
            }

            $evidencePath = $resultDir . '/external-workflow-updates-evidence.json';
            file_put_contents($evidencePath, json_encode([
                'schema' => 'durable-workflow.v2.workflow-update-runtime.external-evidence',
                'runner_blocked' => false,
                'source_policy' => [
                    'pass_requires_published_artifacts_only' => true,
                    'local_product_source_checkouts_used' => true,
                    'local_checkout_execution_counts_as_pass' => false,
                ],
                'scenario_results' => $scenarioResults,
                'findings' => [],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $command = sprintf(
                '%s %s %s %s %s %s %s %s --result-dir %s 2>&1',
                'DW_SERVER_IMAGE=' . escapeshellarg('durableworkflow/server:0.2.536'),
                'DW_SERVER_VERSION=' . escapeshellarg('0.2.536'),
                'DW_CLI_VERSION=' . escapeshellarg('0.1.82'),
                'DW_PYTHON_SDK_VERSION=' . escapeshellarg('0.4.92'),
                'DW_WORKFLOW_PHP_VERSION=' . escapeshellarg('2.0.0-alpha.241'),
                'DW_WATERLINE_VERSION=' . escapeshellarg('2.0.0-alpha.111'),
                'DW_WORKFLOW_UPDATES_EVIDENCE_PATH=' . escapeshellarg($evidencePath),
                escapeshellarg($root . '/scripts/conformance/workflow-updates-published-artifacts.sh'),
                escapeshellarg($resultDir),
            );

            exec($command, $output, $status);

            $this->assertSame(0, $status, implode("\n", $output));

            $result = json_decode((string) file_get_contents($resultDir . '/workflow-updates-result.json'), true);
            $record = json_decode((string) file_get_contents($resultDir . '/workflow-updates-record.json'), true);

            $this->assertSame('fail', $result['outcome']);
            $this->assertTrue($result['local_product_source_checkouts_used']);
            $this->assertTrue($result['source_policy']['local_product_source_checkouts_used']);
            $this->assertSame('fail', $record['outcome']);
            $this->assertTrue($record['local_product_source_checkouts_used']);
            $this->assertContains('published_artifact_install_only', $result['non_passing_scenarios']);

            foreach (self::workflowUpdateRuntimeScenarioIds() as $scenarioId) {
                $scenario = $result['scenario_results'][$scenarioId];
                $this->assertSame('not_covered', $scenario['status'], $scenarioId);
                $this->assertFalse($scenario['published_artifact_cell_executed'], $scenarioId);
                $this->assertTrue($scenario['local_product_source_checkouts_used'], $scenarioId);
                $this->assertNotEmpty($scenario['linked_findings'], $scenarioId);
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($resultDir));
        }
    }

    public function test_handoff_replaces_focused_probe_placeholders_with_clean_published_evidence(): void
    {
        if (trim((string) shell_exec('command -v node')) === '') {
            $this->markTestSkipped('node is required to execute the workflow updates handoff');
        }

        $root = dirname(__DIR__, 2);
        $resultDir = sys_get_temp_dir() . '/dw-workflow-updates-focused-evidence-test-' . bin2hex(random_bytes(6));
        mkdir($resultDir, 0777, true);

        try {
            $scenarioResults = [];
            foreach (self::focusedWorkflowUpdateRuntimeScenarioIds() as $scenarioId) {
                $scenarioResults[$scenarioId] = [
                    'scenario_id' => $scenarioId,
                    'status' => 'pass',
                    'classification' => 'product-evidence',
                    'published_artifact_cell_executed' => true,
                    'local_product_source_checkouts_used' => false,
                    'observed_outputs' => self::completeWorkflowUpdateObservedOutputs($scenarioId),
                    'linked_findings' => [],
                ];
            }

            $evidencePath = $resultDir . '/focused-workflow-updates-evidence.json';
            file_put_contents($evidencePath, json_encode([
                'schema' => 'durable-workflow.v2.workflow-update-runtime.focused-evidence',
                'runner_blocked' => false,
                'source_policy' => [
                    'pass_requires_published_artifacts_only' => true,
                    'local_product_source_checkouts_used' => false,
                    'local_checkout_execution_counts_as_pass' => false,
                ],
                'scenario_results' => $scenarioResults,
                'findings' => [],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $command = sprintf(
                '%s %s %s %s %s %s %s %s --result-dir %s 2>&1',
                'DW_SERVER_IMAGE=' . escapeshellarg('durableworkflow/server:0.2.536'),
                'DW_SERVER_VERSION=' . escapeshellarg('0.2.536'),
                'DW_CLI_VERSION=' . escapeshellarg('0.1.82'),
                'DW_PYTHON_SDK_VERSION=' . escapeshellarg('0.4.92'),
                'DW_WORKFLOW_PHP_VERSION=' . escapeshellarg('2.0.0-alpha.241'),
                'DW_WATERLINE_VERSION=' . escapeshellarg('2.0.0-alpha.111'),
                'DW_WORKFLOW_UPDATES_EVIDENCE_PATH=' . escapeshellarg($evidencePath),
                escapeshellarg($root . '/scripts/conformance/workflow-updates-published-artifacts.sh'),
                escapeshellarg($resultDir),
            );

            exec($command, $output, $status);

            $this->assertSame(0, $status, implode("\n", $output));

            $result = json_decode((string) file_get_contents($resultDir . '/workflow-updates-result.json'), true);
            $metadata = json_decode((string) file_get_contents($resultDir . '/run-metadata.json'), true);
            $record = json_decode((string) file_get_contents($resultDir . '/workflow-updates-record.json'), true);
            $materializedEvidencePath = $resultDir . '/workflow-updates-focused-evidence.json';

            $this->assertSame('fail', $result['outcome']);
            $this->assertTrue($result['focused_probe']['evidence_loaded']);
            $this->assertFileExists($materializedEvidencePath);
            $materializedEvidence = json_decode((string) file_get_contents($materializedEvidencePath), true);
            $this->assertSame('workflow-updates-focused-evidence.json', $metadata['focused_evidence_file']);
            $this->assertSame('workflow-updates-focused-evidence.json', $record['focused_evidence_file']);
            $this->assertSame(
                'durable-workflow.v2.workflow-update-runtime.focused-evidence',
                $materializedEvidence['schema'],
            );
            $this->assertSame(
                'pass',
                $materializedEvidence['scenario_results']['published_artifact_install_only']['status'],
            );
            $this->assertFalse($result['local_product_source_checkouts_used']);
            foreach (self::focusedWorkflowUpdateRuntimeScenarioIds() as $scenarioId) {
                $this->assertSame('pass', $result['scenario_results'][$scenarioId]['status'], $scenarioId);
                $this->assertTrue($result['scenario_results'][$scenarioId]['published_artifact_cell_executed'], $scenarioId);
                $this->assertFalse($result['scenario_results'][$scenarioId]['local_product_source_checkouts_used'], $scenarioId);
            }
            $this->assertSame('unsupported', $result['scenario_results']['principal_attribution_with_auth']['status']);
            $this->assertSame('not_covered', $result['scenario_results']['php_client_worker_update_surface']['status']);
            $this->assertStringNotContainsString(
                'focused published-server workflow update runtime probe did not run',
                json_encode($result['findings'], JSON_THROW_ON_ERROR),
            );
        } finally {
            exec('rm -rf ' . escapeshellarg($resultDir));
        }
    }

    public function test_handoff_preserves_runner_blocked_external_pass_evidence_as_non_passing(): void
    {
        if (trim((string) shell_exec('command -v node')) === '') {
            $this->markTestSkipped('node is required to execute the workflow updates handoff');
        }

        $root = dirname(__DIR__, 2);

        foreach (['runner_blocked', 'runnerBlocked'] as $runnerBlockedField) {
            $resultDir = sys_get_temp_dir() . '/dw-workflow-updates-runner-blocked-test-' . bin2hex(random_bytes(6));
            mkdir($resultDir, 0777, true);

            try {
                $scenarioResults = [];
                foreach (self::workflowUpdateRuntimeScenarioIds() as $scenarioId) {
                    $scenarioResults[$scenarioId] = [
                        'scenario_id' => $scenarioId,
                        'status' => 'pass',
                        'classification' => 'product-evidence',
                        'published_artifact_cell_executed' => true,
                        'local_product_source_checkouts_used' => false,
                        'observed_outputs' => self::completeWorkflowUpdateObservedOutputs($scenarioId),
                        'linked_findings' => [],
                    ];
                }

                $evidencePath = $resultDir . '/runner-blocked-workflow-updates-evidence.json';
                file_put_contents($evidencePath, json_encode([
                    'schema' => 'durable-workflow.v2.workflow-update-runtime.focused-evidence',
                    $runnerBlockedField => true,
                    'source_policy' => [
                        'pass_requires_published_artifacts_only' => true,
                        'local_product_source_checkouts_used' => false,
                        'local_checkout_execution_counts_as_pass' => false,
                    ],
                    'scenario_results' => $scenarioResults,
                    'findings' => [],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                $command = sprintf(
                    '%s %s %s %s %s %s %s %s --result-dir %s 2>&1',
                    'DW_SERVER_IMAGE=' . escapeshellarg('durableworkflow/server:0.2.536'),
                    'DW_SERVER_VERSION=' . escapeshellarg('0.2.536'),
                    'DW_CLI_VERSION=' . escapeshellarg('0.1.82'),
                    'DW_PYTHON_SDK_VERSION=' . escapeshellarg('0.4.92'),
                    'DW_WORKFLOW_PHP_VERSION=' . escapeshellarg('2.0.0-alpha.241'),
                    'DW_WATERLINE_VERSION=' . escapeshellarg('2.0.0-alpha.111'),
                    'DW_WORKFLOW_UPDATES_EVIDENCE_PATH=' . escapeshellarg($evidencePath),
                    escapeshellarg($root . '/scripts/conformance/workflow-updates-published-artifacts.sh'),
                    escapeshellarg($resultDir),
                );

                exec($command, $output, $status);

                $this->assertSame(0, $status, implode("\n", $output));

                $result = json_decode((string) file_get_contents($resultDir . '/workflow-updates-result.json'), true);
                $record = json_decode((string) file_get_contents($resultDir . '/workflow-updates-record.json'), true);

                $this->assertSame('fail', $result['outcome'], $runnerBlockedField);
                $this->assertTrue($result['runner_blocked'], $runnerBlockedField);
                $this->assertTrue($record['runnerBlocked'], $runnerBlockedField);
                $this->assertContains('runner_blocked', $result['update_cell_outcomes'], $runnerBlockedField);
                $this->assertSame(
                    'runner_blocked',
                    $result['scenario_results']['published_artifact_install_only']['status'],
                    $runnerBlockedField,
                );
                $this->assertContains('conformance_runner_blocked', array_column($result['findings'], 'finding_type'));
                $this->assertStringContainsString(
                    'runner_blocked=true',
                    json_encode($result['findings'], JSON_THROW_ON_ERROR),
                );
            } finally {
                exec('rm -rf ' . escapeshellarg($resultDir));
            }
        }
    }

    public function test_handoff_downgrades_claimed_pass_when_required_observed_outputs_are_missing(): void
    {
        if (trim((string) shell_exec('command -v node')) === '') {
            $this->markTestSkipped('node is required to execute the workflow updates handoff');
        }

        $root = dirname(__DIR__, 2);
        $resultDir = sys_get_temp_dir() . '/dw-workflow-updates-missing-required-test-' . bin2hex(random_bytes(6));
        mkdir($resultDir, 0777, true);

        try {
            $scenarioResults = [];
            foreach (self::focusedWorkflowUpdateRuntimeScenarioIds() as $scenarioId) {
                $observedOutputs = self::completeWorkflowUpdateObservedOutputs($scenarioId);
                if ($scenarioId === 'accepted_update_control_plane_and_history') {
                    unset($observedOutputs['update_response']);
                }

                $scenarioResults[$scenarioId] = [
                    'scenario_id' => $scenarioId,
                    'status' => 'pass',
                    'classification' => 'product-evidence',
                    'published_artifact_cell_executed' => true,
                    'local_product_source_checkouts_used' => false,
                    'observed_outputs' => $observedOutputs,
                    'linked_findings' => [],
                ];
            }

            $evidencePath = $resultDir . '/missing-required-workflow-updates-evidence.json';
            file_put_contents($evidencePath, json_encode([
                'schema' => 'durable-workflow.v2.workflow-update-runtime.focused-evidence',
                'runner_blocked' => false,
                'source_policy' => [
                    'pass_requires_published_artifacts_only' => true,
                    'local_product_source_checkouts_used' => false,
                    'local_checkout_execution_counts_as_pass' => false,
                ],
                'scenario_results' => $scenarioResults,
                'findings' => [],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $command = sprintf(
                '%s %s %s %s %s %s %s %s --result-dir %s 2>&1',
                'DW_SERVER_IMAGE=' . escapeshellarg('durableworkflow/server:0.2.536'),
                'DW_SERVER_VERSION=' . escapeshellarg('0.2.536'),
                'DW_CLI_VERSION=' . escapeshellarg('0.1.82'),
                'DW_PYTHON_SDK_VERSION=' . escapeshellarg('0.4.92'),
                'DW_WORKFLOW_PHP_VERSION=' . escapeshellarg('2.0.0-alpha.241'),
                'DW_WATERLINE_VERSION=' . escapeshellarg('2.0.0-alpha.111'),
                'DW_WORKFLOW_UPDATES_EVIDENCE_PATH=' . escapeshellarg($evidencePath),
                escapeshellarg($root . '/scripts/conformance/workflow-updates-published-artifacts.sh'),
                escapeshellarg($resultDir),
            );

            exec($command, $output, $status);

            $this->assertSame(0, $status, implode("\n", $output));

            $result = json_decode((string) file_get_contents($resultDir . '/workflow-updates-result.json'), true);
            $scenario = $result['scenario_results']['accepted_update_control_plane_and_history'];

            $this->assertSame('not_covered', $scenario['status']);
            $this->assertContains('update_response', $scenario['observed_outputs']['missing_required_fields']);
            $this->assertSame(
                'workflow-updates-accepted-update-control-plane-and-history-required-evidence-gap',
                $scenario['linked_findings'][0]['finding_id'],
            );
        } finally {
            exec('rm -rf ' . escapeshellarg($resultDir));
        }
    }

    public function test_handoff_downgrades_claimed_pass_with_placeholder_artifact_tuple(): void
    {
        if (trim((string) shell_exec('command -v node')) === '') {
            $this->markTestSkipped('node is required to execute the workflow updates handoff');
        }

        $root = dirname(__DIR__, 2);
        $resultDir = sys_get_temp_dir() . '/dw-workflow-updates-placeholder-artifacts-test-' . bin2hex(random_bytes(6));
        mkdir($resultDir, 0777, true);

        try {
            $scenarioResults = [];
            foreach (self::focusedWorkflowUpdateRuntimeScenarioIds() as $scenarioId) {
                $scenarioResults[$scenarioId] = [
                    'scenario_id' => $scenarioId,
                    'status' => 'pass',
                    'classification' => 'product-evidence',
                    'published_artifact_cell_executed' => true,
                    'local_product_source_checkouts_used' => false,
                    'observed_outputs' => self::completeWorkflowUpdateObservedOutputs($scenarioId),
                    'linked_findings' => [],
                ];
            }

            $evidencePath = $resultDir . '/placeholder-artifacts-workflow-updates-evidence.json';
            file_put_contents($evidencePath, json_encode([
                'schema' => 'durable-workflow.v2.workflow-update-runtime.focused-evidence',
                'runner_blocked' => false,
                'source_policy' => [
                    'pass_requires_published_artifacts_only' => true,
                    'local_product_source_checkouts_used' => false,
                    'local_checkout_execution_counts_as_pass' => false,
                ],
                'scenario_results' => $scenarioResults,
                'findings' => [],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $command = sprintf(
                '%s %s %s %s %s %s %s %s --result-dir %s 2>&1',
                'DW_SERVER_IMAGE=' . escapeshellarg('durableworkflow/server:latest'),
                'DW_SERVER_VERSION=' . escapeshellarg('latest'),
                'DW_CLI_VERSION=' . escapeshellarg('0.1.82'),
                'DW_PYTHON_SDK_VERSION=' . escapeshellarg('0.4.92'),
                'DW_WORKFLOW_PHP_VERSION=' . escapeshellarg('2.0.0-alpha.241'),
                'DW_WATERLINE_VERSION=' . escapeshellarg('2.0.0-alpha.111'),
                'DW_WORKFLOW_UPDATES_EVIDENCE_PATH=' . escapeshellarg($evidencePath),
                escapeshellarg($root . '/scripts/conformance/workflow-updates-published-artifacts.sh'),
                escapeshellarg($resultDir),
            );

            exec($command, $output, $status);

            $this->assertSame(0, $status, implode("\n", $output));

            $result = json_decode((string) file_get_contents($resultDir . '/workflow-updates-result.json'), true);
            $scenario = $result['scenario_results']['published_artifact_install_only'];

            $this->assertSame('not_covered', $scenario['status']);
            $this->assertSame(
                'workflow-updates-published-artifact-install-only-artifact-prerequisite-gap',
                $scenario['linked_findings'][0]['finding_id'],
            );
            $this->assertContains(
                'server',
                array_column($scenario['observed_outputs']['artifact_prerequisite_failures'], 'artifact'),
            );
        } finally {
            exec('rm -rf ' . escapeshellarg($resultDir));
        }
    }

    public function test_handoff_downgrades_install_only_pass_missing_required_artifact_fields(): void
    {
        if (trim((string) shell_exec('command -v node')) === '') {
            $this->markTestSkipped('node is required to execute the workflow updates handoff');
        }

        $root = dirname(__DIR__, 2);
        $resultDir = sys_get_temp_dir() . '/dw-workflow-updates-install-only-missing-test-' . bin2hex(random_bytes(6));
        mkdir($resultDir, 0777, true);

        try {
            $evidencePath = $resultDir . '/install-only-missing-workflow-updates-evidence.json';
            file_put_contents($evidencePath, json_encode([
                'schema' => 'durable-workflow.v2.workflow-update-runtime.focused-evidence',
                'runner_blocked' => false,
                'source_policy' => [
                    'pass_requires_published_artifacts_only' => true,
                    'local_product_source_checkouts_used' => false,
                    'local_checkout_execution_counts_as_pass' => false,
                ],
                'scenario_results' => [
                    'published_artifact_install_only' => [
                        'scenario_id' => 'published_artifact_install_only',
                        'status' => 'pass',
                        'classification' => 'product-evidence',
                        'published_artifact_cell_executed' => true,
                        'local_product_source_checkouts_used' => false,
                        'observed_outputs' => [
                            'published_artifact_cell_executed' => true,
                            'local_product_source_checkouts_used' => false,
                        ],
                        'linked_findings' => [],
                    ],
                ],
                'findings' => [],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $command = sprintf(
                '%s %s %s %s %s %s %s %s --result-dir %s 2>&1',
                'DW_SERVER_IMAGE=' . escapeshellarg('durableworkflow/server:0.2.536'),
                'DW_SERVER_VERSION=' . escapeshellarg('0.2.536'),
                'DW_CLI_VERSION=' . escapeshellarg('0.1.82'),
                'DW_PYTHON_SDK_VERSION=' . escapeshellarg('0.4.92'),
                'DW_WORKFLOW_PHP_VERSION=' . escapeshellarg('2.0.0-alpha.241'),
                'DW_WATERLINE_VERSION=' . escapeshellarg('2.0.0-alpha.111'),
                'DW_WORKFLOW_UPDATES_EVIDENCE_PATH=' . escapeshellarg($evidencePath),
                escapeshellarg($root . '/scripts/conformance/workflow-updates-published-artifacts.sh'),
                escapeshellarg($resultDir),
            );

            exec($command, $output, $status);

            $this->assertSame(0, $status, implode("\n", $output));

            $result = json_decode((string) file_get_contents($resultDir . '/workflow-updates-result.json'), true);
            $scenario = $result['scenario_results']['published_artifact_install_only'];

            $this->assertSame('fail', $result['outcome']);
            $this->assertSame('not_covered', $scenario['status']);
            foreach ([
                'published_artifact_versions',
                'artifact_sources',
                'artifact_install_evidence',
                'source_policy',
            ] as $field) {
                $this->assertContains($field, $scenario['observed_outputs']['missing_required_fields']);
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($resultDir));
        }
    }

    public function test_handoff_rejects_forbidden_artifact_source_values(): void
    {
        if (trim((string) shell_exec('command -v node')) === '') {
            $this->markTestSkipped('node is required to execute the workflow updates handoff');
        }

        $root = dirname(__DIR__, 2);
        $resultDir = sys_get_temp_dir() . '/dw-workflow-updates-forbidden-sources-test-' . bin2hex(random_bytes(6));
        mkdir($resultDir, 0777, true);

        try {
            $badSources = self::workflowUpdateEvidenceValue('artifact_sources', 'published_artifact_install_only');
            $badSources['server'] = 'local_source_checkout';
            $badSources['cli'] = 'branch_source';
            $badSources['sdk-python'] = 'workspace_repo';
            $badSources['workflow'] = 'file:///workspace/repos/workflow';
            $badSources['workflow-php'] = 'file:///workspace/repos/workflow';
            $badSources['waterline'] = '/tmp/local-worktree/waterline';

            $scenarioResults = [];
            foreach (self::workflowUpdateRuntimeScenarioIds() as $scenarioId) {
                $observedOutputs = self::completeWorkflowUpdateObservedOutputs($scenarioId);
                $observedOutputs['artifact_sources'] = $badSources;
                $scenarioResults[$scenarioId] = [
                    'scenario_id' => $scenarioId,
                    'status' => 'pass',
                    'classification' => 'product-evidence',
                    'published_artifact_cell_executed' => true,
                    'local_product_source_checkouts_used' => false,
                    'observed_outputs' => $observedOutputs,
                    'linked_findings' => [],
                ];
            }

            $evidencePath = $resultDir . '/forbidden-sources-workflow-updates-evidence.json';
            file_put_contents($evidencePath, json_encode([
                'schema' => 'durable-workflow.v2.workflow-update-runtime.focused-evidence',
                'runner_blocked' => false,
                'artifact_sources' => $badSources,
                'source_policy' => [
                    'pass_requires_published_artifacts_only' => true,
                    'local_product_source_checkouts_used' => false,
                    'local_checkout_execution_counts_as_pass' => false,
                ],
                'scenario_results' => $scenarioResults,
                'findings' => [],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $command = sprintf(
                '%s %s %s %s %s %s %s %s --result-dir %s 2>&1',
                'DW_SERVER_IMAGE=' . escapeshellarg('durableworkflow/server:0.2.536'),
                'DW_SERVER_VERSION=' . escapeshellarg('0.2.536'),
                'DW_CLI_VERSION=' . escapeshellarg('0.1.82'),
                'DW_PYTHON_SDK_VERSION=' . escapeshellarg('0.4.92'),
                'DW_WORKFLOW_PHP_VERSION=' . escapeshellarg('2.0.0-alpha.241'),
                'DW_WATERLINE_VERSION=' . escapeshellarg('2.0.0-alpha.111'),
                'DW_WORKFLOW_UPDATES_EVIDENCE_PATH=' . escapeshellarg($evidencePath),
                escapeshellarg($root . '/scripts/conformance/workflow-updates-published-artifacts.sh'),
                escapeshellarg($resultDir),
            );

            exec($command, $output, $status);

            $this->assertSame(0, $status, implode("\n", $output));

            $result = json_decode((string) file_get_contents($resultDir . '/workflow-updates-result.json'), true);

            $this->assertSame('fail', $result['outcome']);
            $this->assertTrue($result['local_product_source_checkouts_used']);
            $this->assertTrue($result['source_policy']['local_product_source_checkouts_used']);
            $this->assertSame('not_covered', $result['scenario_results']['published_artifact_install_only']['status']);
            $this->assertContains(
                'forbidden_published_artifact_source',
                array_column($result['artifact_policy_failures'], 'code'),
            );

            $failureValues = array_column($result['artifact_policy_failures'], 'value');
            foreach ([
                'local_source_checkout',
                'branch_source',
                'workspace_repo',
                'file:///workspace/repos/workflow',
                '/tmp/local-worktree/waterline',
            ] as $value) {
                $this->assertContains($value, $failureValues);
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($resultDir));
        }
    }

    public function test_handoff_materializes_inline_focused_evidence_file(): void
    {
        if (trim((string) shell_exec('command -v node')) === '') {
            $this->markTestSkipped('node is required to execute the workflow updates handoff');
        }

        $root = dirname(__DIR__, 2);
        $resultDir = sys_get_temp_dir() . '/dw-workflow-updates-inline-evidence-test-' . bin2hex(random_bytes(6));
        mkdir($resultDir, 0777, true);

        try {
            $inlineEvidence = json_encode([
                'schema' => 'durable-workflow.v2.workflow-update-runtime.focused-evidence',
                'runner_blocked' => false,
                'source_policy' => [
                    'pass_requires_published_artifacts_only' => true,
                    'local_product_source_checkouts_used' => false,
                    'local_checkout_execution_counts_as_pass' => false,
                ],
                'scenario_results' => [
                    'published_artifact_install_only' => [
                        'scenario_id' => 'published_artifact_install_only',
                        'status' => 'pass',
                        'classification' => 'product-evidence',
                        'published_artifact_cell_executed' => true,
                        'local_product_source_checkouts_used' => false,
                        'observed_outputs' => [
                            'published_artifact_cell_executed' => true,
                            'local_product_source_checkouts_used' => false,
                        ],
                        'linked_findings' => [],
                    ],
                ],
                'findings' => [],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            $command = sprintf(
                '%s %s --result-dir %s 2>&1',
                'DW_WORKFLOW_UPDATES_EVIDENCE=' . escapeshellarg($inlineEvidence),
                escapeshellarg($root . '/scripts/conformance/workflow-updates-published-artifacts.sh'),
                escapeshellarg($resultDir),
            );

            exec($command, $output, $status);

            $this->assertSame(0, $status, implode("\n", $output));

            $metadata = json_decode((string) file_get_contents($resultDir . '/run-metadata.json'), true);
            $evidencePath = $resultDir . '/workflow-updates-focused-evidence.json';

            $this->assertFileExists($evidencePath);
            $materializedEvidence = json_decode((string) file_get_contents($evidencePath), true);
            $this->assertSame('workflow-updates-focused-evidence.json', $metadata['focused_evidence_file']);
            $this->assertSame(
                'pass',
                $materializedEvidence['scenario_results']['published_artifact_install_only']['status'],
            );
        } finally {
            exec('rm -rf ' . escapeshellarg($resultDir));
        }
    }

    /**
     * @return list<string>
     */
    private static function focusedWorkflowUpdateRuntimeScenarioIds(): array
    {
        return [
            'published_artifact_install_only',
            'declared_update_contract_visibility',
            'accepted_update_control_plane_and_history',
            'running_or_waiting_update_operator_visibility',
            'completed_update_result_round_trip',
            'failed_update_outcome',
            'duplicate_request_idempotency',
            'unknown_update_refusal',
            'invalid_input_refusal',
            'payload_envelope_round_trip',
            'terminal_workflow_update_behavior',
        ];
    }

    /**
     * @return list<string>
     */
    private static function workflowUpdateRuntimeScenarioIds(): array
    {
        return [
            'published_artifact_install_only',
            'declared_update_contract_visibility',
            'accepted_update_control_plane_and_history',
            'running_or_waiting_update_operator_visibility',
            'completed_update_result_round_trip',
            'failed_update_outcome',
            'duplicate_request_idempotency',
            'unknown_update_refusal',
            'invalid_input_refusal',
            'payload_envelope_round_trip',
            'terminal_workflow_update_behavior',
            'principal_attribution_with_auth',
            'php_client_worker_update_surface',
            'python_client_worker_update_surface',
            'operator_diagnostics_surfaces',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function completeWorkflowUpdateObservedOutputs(string $scenarioId): array
    {
        static $requirements = null;

        if ($requirements === null) {
            $manifest = json_decode(
                (string) file_get_contents(dirname(__DIR__, 2) . '/static/platform-conformance/workflow-update-runtime-scenarios.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $requirements = $manifest['scenario_requirements'];
        }

        $observedOutputs = [
            'published_artifact_cell_executed' => true,
            'local_product_source_checkouts_used' => false,
        ];

        foreach ($requirements[$scenarioId]['required_fields'] ?? [] as $field) {
            if (! array_key_exists($field, $observedOutputs)) {
                $observedOutputs[$field] = self::workflowUpdateEvidenceValue($field, $scenarioId);
            }
        }

        return $observedOutputs;
    }

    private static function workflowUpdateEvidenceValue(string $field, string $scenarioId): mixed
    {
        return match ($field) {
            'published_artifact_versions' => [
                'server' => '0.2.536',
                'cli' => '0.1.82',
                'sdk-python' => '0.4.92',
                'workflow' => '2.0.0-alpha.241',
                'workflow-php' => '2.0.0-alpha.241',
                'waterline' => '2.0.0-alpha.111',
            ],
            'artifact_sources' => [
                'server' => 'durableworkflow/server:0.2.536',
                'cli' => 'github-release://durable-workflow/cli/v0.1.82/install.sh',
                'sdk-python' => 'pypi://durable-workflow==0.4.92',
                'workflow' => 'packagist://durable-workflow/workflow@2.0.0-alpha.241',
                'workflow-php' => 'packagist://durable-workflow/workflow@2.0.0-alpha.241',
                'waterline' => 'packagist://durable-workflow/waterline@2.0.0-alpha.111',
            ],
            'artifact_install_evidence' => [
                'installed_from' => 'published_artifact',
                'scenario_id' => $scenarioId,
            ],
            'source_policy' => [
                'pass_requires_published_artifacts_only' => true,
                'local_product_source_checkouts_used' => false,
                'local_checkout_execution_counts_as_pass' => false,
            ],
            'local_product_source_checkouts_used' => false,
            'handler_not_invoked' => true,
            default => [
                'scenario_id' => $scenarioId,
                'field' => $field,
                'observed' => true,
            ],
        };
    }
}
