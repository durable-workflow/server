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
        $this->assertSame(3, WorkflowUpdateRuntimeContract::VERSION);
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

        foreach (['server', 'cli', 'workflow-php', 'sdk-php', 'sdk-python', 'waterline'] as $artifact) {
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
            'update_validator_approval_boundary',
            'update_validator_rejection_boundary',
            'update_validator_worker_replacement',
            'duplicate_validation_completion',
            'unsupported_validation_capability',
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
            'DW_WORKFLOW_UPDATES_PHP_EVIDENCE_PATH',
            $manifest['host_runner_contract']['evidence_inputs'],
        );
        $this->assertContains(
            'DW_WORKFLOW_UPDATES_PYTHON_EVIDENCE_PATH',
            $manifest['host_runner_contract']['evidence_inputs'],
        );
        $this->assertContains(
            'DW_WORKFLOW_UPDATES_OPERATOR_DIAGNOSTICS_EVIDENCE_PATH',
            $manifest['host_runner_contract']['evidence_inputs'],
        );
        $this->assertContains(
            'DW_WORKFLOW_UPDATES_SKIP_PYTHON_SDK_SHARD',
            $manifest['host_runner_contract']['evidence_inputs'],
        );
        $this->assertContains(
            'DW_WORKFLOW_UPDATES_SKIP_OPERATOR_DIAGNOSTICS_SHARD',
            $manifest['host_runner_contract']['evidence_inputs'],
        );
        $this->assertContains(
            'workflow-updates-focused-evidence.json',
            $manifest['host_runner_contract']['result_files'],
        );
        $this->assertContains(
            'sdk-php-workflow-updates-evidence.json',
            $manifest['host_runner_contract']['result_files'],
        );
        $this->assertContains(
            'python-sdk-workflow-updates-evidence.json',
            $manifest['host_runner_contract']['result_files'],
        );
        $this->assertContains(
            'workflow-updates-operator-diagnostics-evidence.json',
            $manifest['host_runner_contract']['result_files'],
        );
        $this->assertContains(
            'accepted_update_control_plane_and_history',
            $manifest['host_runner_contract']['focused_probe']['covers_required_scenarios'],
        );
        $this->assertContains(
            'principal_attribution_with_auth',
            $manifest['host_runner_contract']['focused_probe']['covers_required_scenarios'],
        );
        $typedCoverageGaps = (array) $manifest['host_runner_contract']['typed_coverage_gaps'];
        $this->assertArrayNotHasKey(
            'principal_attribution_with_auth',
            $typedCoverageGaps,
        );
        $this->assertSame(
            'implemented',
            $manifest['host_runner_contract']['php_sidecar']['status'],
        );
        $this->assertContains(
            'php_client_worker_update_surface',
            $manifest['host_runner_contract']['php_sidecar']['covers_required_scenarios'],
        );
        $this->assertContains(
            'python_client_worker_update_surface',
            $manifest['host_runner_contract']['python_sidecar']['covers_required_scenarios'],
        );
        $this->assertArrayNotHasKey(
            'php_client_worker_update_surface',
            $typedCoverageGaps,
        );
        $this->assertArrayNotHasKey(
            'python_client_worker_update_surface',
            $typedCoverageGaps,
        );
        $this->assertSame(
            'implemented',
            $manifest['host_runner_contract']['operator_diagnostics_sidecar']['status'],
        );
        $this->assertContains(
            'operator_diagnostics_surfaces',
            $manifest['host_runner_contract']['operator_diagnostics_sidecar']['covers_required_scenarios'],
        );
        $this->assertArrayNotHasKey(
            'operator_diagnostics_surfaces',
            $typedCoverageGaps,
        );
        $this->assertSame(WorkflowUpdateRuntimeResultGate::SCHEMA, $manifest['result_gate']['schema']);
        $this->assertContains(
            'unknown_update_invalid_input_and_terminal_workflow_refusals_are_typed',
            $manifest['result_gate']['pass_requires'],
        );
    }

    public function test_manifest_preserves_typed_coverage_gaps_as_empty_json_object(): void
    {
        $manifest = WorkflowUpdateRuntimeContract::manifest();

        $this->assertInstanceOf(\stdClass::class, $manifest['host_runner_contract']['typed_coverage_gaps']);
        $this->assertSame([], get_object_vars($manifest['host_runner_contract']['typed_coverage_gaps']));

        $encodedManifest = json_decode(
            json_encode($manifest, JSON_THROW_ON_ERROR),
            false,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertInstanceOf(
            \stdClass::class,
            $encodedManifest->host_runner_contract->typed_coverage_gaps,
        );
        $this->assertSame([], get_object_vars($encodedManifest->host_runner_contract->typed_coverage_gaps));

        $staticManifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2).'/static/platform-conformance/workflow-update-runtime-scenarios.json'),
            false,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertInstanceOf(
            \stdClass::class,
            $staticManifest->host_runner_contract->typed_coverage_gaps,
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
                '%s %s %s %s %s %s %s %s --result-dir %s 2>&1',
                'DW_SERVER_IMAGE=' . escapeshellarg('durableworkflow/server:0.2.536'),
                'DW_SERVER_VERSION=' . escapeshellarg('0.2.536'),
                'DW_CLI_VERSION=' . escapeshellarg('0.1.82'),
                'DW_PYTHON_SDK_VERSION=' . escapeshellarg('0.4.92'),
                'DW_PHP_SDK_VERSION=' . escapeshellarg('0.1.1'),
                'DW_WORKFLOW_PHP_VERSION=' . escapeshellarg('2.0.0-alpha.242'),
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
            $this->assertSame('0.1.1', $result['artifact_versions']['sdk-php']);
            $this->assertSame('0.4.92', $result['artifact_versions']['sdk-python']);
            $this->assertSame('2.0.0-alpha.242', $result['artifact_versions']['workflow']);
            $this->assertSame('2.0.0-alpha.111', $result['artifact_versions']['waterline']);
            $this->assertSame('durableworkflow/server:0.2.536', $result['artifact_sources']['server']);
            $this->assertSame(
                'https://github.com/durable-workflow/cli/releases/download/0.1.82/install.sh',
                $result['artifact_sources']['cli'],
            );
            $this->assertSame(
                'packagist://durable-workflow/sdk@0.1.1',
                $result['artifact_sources']['sdk-php'],
            );
            $this->assertSame(
                'pypi://durable-workflow==0.4.92',
                $result['artifact_sources']['sdk-python'],
            );
            $this->assertSame(
                'packagist://durable-workflow/workflow@2.0.0-alpha.242',
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
                $this->assertSame('not_covered', $scenario['status']);
                $this->assertFalse($scenario['published_artifact_cell_executed']);
                $this->assertNotEmpty($scenario['linked_findings']);
            }

            $this->assertSame('workflow-updates', $record['experiment']);
            $this->assertSame('fail', $record['outcome']);
            $this->assertFalse($record['runnerBlocked']);
            $this->assertSame('0.2.536', $record['artifactVersions']['server']);
            $this->assertSame(
                'https://github.com/durable-workflow/cli/releases/download/0.1.82/install.sh',
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

    public function test_handoff_uses_workflow_version_alias_for_php_package_tuple(): void
    {
        if (trim((string) shell_exec('command -v node')) === '') {
            $this->markTestSkipped('node is required to execute the workflow updates handoff');
        }

        $root = dirname(__DIR__, 2);
        $resultDir = sys_get_temp_dir() . '/dw-workflow-updates-workflow-version-alias-test-' . bin2hex(random_bytes(6));
        mkdir($resultDir, 0777, true);

        try {
            $command = sprintf(
                '%s %s %s %s %s %s %s --result-dir %s 2>&1',
                'DW_SERVER_IMAGE=' . escapeshellarg('durableworkflow/server:0.2.536'),
                'DW_SERVER_VERSION=' . escapeshellarg('0.2.536'),
                'DW_CLI_VERSION=' . escapeshellarg('0.1.82'),
                'DW_PYTHON_SDK_VERSION=' . escapeshellarg('0.4.92'),
                'DW_WORKFLOW_VERSION=' . escapeshellarg('2.0.0-alpha.242'),
                'DW_WATERLINE_VERSION=' . escapeshellarg('2.0.0-alpha.111'),
                escapeshellarg($root . '/scripts/conformance/workflow-updates-published-artifacts.sh'),
                escapeshellarg($resultDir),
            );

            exec($command, $output, $status);

            $this->assertSame(0, $status, implode("\n", $output));

            $result = json_decode((string) file_get_contents($resultDir . '/workflow-updates-result.json'), true);

            $this->assertSame('2.0.0-alpha.242', $result['artifact_versions']['workflow']);
            $this->assertSame(
                'packagist://durable-workflow/workflow@2.0.0-alpha.242',
                $result['artifact_sources']['workflow-php'],
            );
            $this->assertSame(
                'packagist://durable-workflow/workflow@2.0.0-alpha.242',
                $result['scenario_results']['php_client_worker_update_surface']['observed_outputs']['artifact_sources']['workflow-php'],
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
                '%s %s %s %s %s %s %s %s %s --result-dir %s 2>&1',
                'DW_SERVER_IMAGE=' . escapeshellarg('durableworkflow/server:0.2.536'),
                'DW_SERVER_VERSION=' . escapeshellarg('0.2.536'),
                'DW_CLI_VERSION=' . escapeshellarg('0.1.82'),
                'DW_PYTHON_SDK_VERSION=' . escapeshellarg('0.4.92'),
                'DW_PHP_SDK_VERSION=' . escapeshellarg('0.1.1'),
                'DW_WORKFLOW_PHP_VERSION=' . escapeshellarg('2.0.0-alpha.242'),
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

            foreach (self::focusedWorkflowUpdateRuntimeScenarioIds() as $scenarioId) {
                $scenario = $result['scenario_results'][$scenarioId];
                $this->assertSame('not_covered', $scenario['status'], $scenarioId);
                $this->assertFalse($scenario['published_artifact_cell_executed'], $scenarioId);
                $this->assertTrue($scenario['local_product_source_checkouts_used'], $scenarioId);
                $this->assertNotEmpty($scenario['linked_findings'], $scenarioId);
            }

            foreach (['php_client_worker_update_surface', 'python_client_worker_update_surface', 'operator_diagnostics_surfaces'] as $scenarioId) {
                $scenario = $result['scenario_results'][$scenarioId];
                $this->assertSame('not_covered', $scenario['status'], $scenarioId);
                $this->assertFalse($scenario['local_product_source_checkouts_used'], $scenarioId);
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
                '%s %s %s %s %s %s %s %s %s --result-dir %s 2>&1',
                'DW_SERVER_IMAGE=' . escapeshellarg('durableworkflow/server:0.2.536'),
                'DW_SERVER_VERSION=' . escapeshellarg('0.2.536'),
                'DW_CLI_VERSION=' . escapeshellarg('0.1.82'),
                'DW_PYTHON_SDK_VERSION=' . escapeshellarg('0.4.92'),
                'DW_PHP_SDK_VERSION=' . escapeshellarg('0.1.1'),
                'DW_WORKFLOW_PHP_VERSION=' . escapeshellarg('2.0.0-alpha.242'),
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
            $this->assertSame('not_covered', $result['scenario_results']['php_client_worker_update_surface']['status']);
            $this->assertNotContains(
                'workflow-updates-focused-probe-coverage-gap',
                array_column($result['findings'], 'finding_id'),
            );
        } finally {
            exec('rm -rf ' . escapeshellarg($resultDir));
        }
    }

    public function test_handoff_imports_python_sidecar_for_python_surface_only(): void
    {
        if (trim((string) shell_exec('command -v node')) === '') {
            $this->markTestSkipped('node is required to execute the workflow updates handoff');
        }

        $root = dirname(__DIR__, 2);
        $resultDir = sys_get_temp_dir() . '/dw-workflow-updates-python-sidecar-test-' . bin2hex(random_bytes(6));
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
            $scenarioResults['python_client_worker_update_surface']['observed_outputs']['package_import_path'] = '/tmp/durable-workflow-python-sdk-venv/lib/python3.11/site-packages/durable_workflow/workflow_updates_conformance.py';
            $scenarioResults['python_client_worker_update_surface']['observed_outputs']['artifact_versions'] = self::workflowUpdateEvidenceValue('published_artifact_versions', 'python_client_worker_update_surface');
            $scenarioResults['python_client_worker_update_surface']['observed_outputs']['published_artifact_versions'] = self::workflowUpdateEvidenceValue('published_artifact_versions', 'python_client_worker_update_surface');
            $scenarioResults['python_client_worker_update_surface']['observed_outputs']['artifact_sources'] = self::workflowUpdateEvidenceValue('artifact_sources', 'python_client_worker_update_surface');

            file_put_contents($resultDir . '/python-sdk-workflow-updates-evidence.json', json_encode([
                'schema' => 'durable-workflow.v2.workflow-updates.python-sdk-sidecar',
                'runner_blocked' => false,
                'artifact_versions' => self::workflowUpdateEvidenceValue('published_artifact_versions', 'python_client_worker_update_surface'),
                'published_artifact_versions' => self::workflowUpdateEvidenceValue('published_artifact_versions', 'python_client_worker_update_surface'),
                'artifact_sources' => self::workflowUpdateEvidenceValue('artifact_sources', 'python_client_worker_update_surface'),
                'source_policy' => [
                    'pass_requires_published_artifacts_only' => true,
                    'local_product_source_checkouts_used' => false,
                    'local_checkout_execution_counts_as_pass' => false,
                ],
                'scenario_results' => $scenarioResults,
                'findings' => [],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $command = sprintf(
                '%s %s %s %s %s %s %s %s %s --result-dir %s 2>&1',
                'DW_SERVER_IMAGE=' . escapeshellarg('durableworkflow/server:0.2.536'),
                'DW_SERVER_VERSION=' . escapeshellarg('0.2.536'),
                'DW_CLI_VERSION=' . escapeshellarg('0.1.82'),
                'DW_PYTHON_SDK_VERSION=' . escapeshellarg('0.4.92'),
                'DW_PHP_SDK_VERSION=' . escapeshellarg('0.1.1'),
                'DW_WORKFLOW_PHP_VERSION=' . escapeshellarg('2.0.0-alpha.242'),
                'DW_WATERLINE_VERSION=' . escapeshellarg('2.0.0-alpha.111'),
                'DW_WORKFLOW_UPDATES_SKIP_PHP_PACKAGE_SHARD=' . escapeshellarg('1'),
                escapeshellarg($root . '/scripts/conformance/workflow-updates-published-artifacts.sh'),
                escapeshellarg($resultDir),
            );

            exec($command, $output, $status);

            $this->assertSame(0, $status, implode("\n", $output));

            $result = json_decode((string) file_get_contents($resultDir . '/workflow-updates-result.json'), true);
            $metadata = json_decode((string) file_get_contents($resultDir . '/run-metadata.json'), true);
            $record = json_decode((string) file_get_contents($resultDir . '/workflow-updates-record.json'), true);
            $sidecar = json_decode((string) file_get_contents($resultDir . '/python-sdk-workflow-updates-evidence.json'), true);

            $this->assertSame('fail', $result['outcome']);
            $this->assertFalse($result['focused_probe']['evidence_loaded']);
            $this->assertTrue($result['python_sidecar']['evidence_loaded']);
            $this->assertSame('python-sdk-workflow-updates-evidence.json', $metadata['python_sidecar_evidence_file']);
            $this->assertSame('python-sdk-workflow-updates-evidence.json', $record['python_sidecar_evidence_file']);
            $this->assertSame('pass', $result['scenario_results']['python_client_worker_update_surface']['status']);
            $this->assertSame([], $result['artifact_policy_failures']);
            $this->assertFalse($result['source_policy']['local_product_source_checkouts_used']);
            $this->assertFalse($result['python_sidecar']['local_product_source_checkouts_used']);
            $this->assertFalse($result['scenario_results']['python_client_worker_update_surface']['local_product_source_checkouts_used']);
            foreach (['artifact_versions', 'published_artifact_versions'] as $field) {
                $this->assertSame('0.1.1', $sidecar[$field]['sdk-php']);
                $this->assertSame(
                    '0.1.1',
                    $sidecar['scenario_results']['python_client_worker_update_surface']['observed_outputs'][$field]['sdk-php'],
                );
            }
            $this->assertSame('packagist://durable-workflow/sdk@0.1.1', $sidecar['artifact_sources']['sdk-php']);
            $this->assertSame(
                'packagist://durable-workflow/sdk@0.1.1',
                $sidecar['scenario_results']['python_client_worker_update_surface']['observed_outputs']['artifact_sources']['sdk-php'],
            );
            $this->assertSame('not_covered', $result['scenario_results']['principal_attribution_with_auth']['status']);
            $this->assertSame('not_covered', $result['scenario_results']['php_client_worker_update_surface']['status']);
            $this->assertSame('not_covered', $result['scenario_results']['operator_diagnostics_surfaces']['status']);
            $this->assertContains('principal_attribution_with_auth', $result['non_passing_scenarios']);
            $this->assertContains('php_client_worker_update_surface', $result['non_passing_scenarios']);
            $this->assertContains('operator_diagnostics_surfaces', $result['non_passing_scenarios']);
        } finally {
            exec('rm -rf ' . escapeshellarg($resultDir));
        }
    }

    public function test_handoff_imports_php_package_sidecar_for_php_surface_only(): void
    {
        if (trim((string) shell_exec('command -v node')) === '') {
            $this->markTestSkipped('node is required to execute the workflow updates handoff');
        }

        $root = dirname(__DIR__, 2);
        $resultDir = sys_get_temp_dir() . '/dw-workflow-updates-php-sidecar-test-' . bin2hex(random_bytes(6));
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
            $scenarioResults['php_client_worker_update_surface']['observed_outputs']['sdk_php_artifact_source'] = 'packagist://durable-workflow/sdk@0.1.1';
            $scenarioResults['php_client_worker_update_surface']['observed_outputs']['composer_package'] = 'durable-workflow/sdk';
            $scenarioResults['php_client_worker_update_surface']['observed_outputs']['cell_outcomes'] = [
                'accepted' => ['status' => 'pass'],
                'completed' => ['status' => 'pass'],
                'failed' => ['status' => 'pass'],
                'refused_unknown_update' => ['status' => 'pass'],
                'duplicate_idempotent' => ['status' => 'pass'],
                'terminal_refusal' => ['status' => 'pass'],
                'payload_round_trip' => ['status' => 'pass'],
            ];

            file_put_contents($resultDir . '/sdk-php-workflow-updates-evidence.json', json_encode([
                'schema' => 'durable-workflow.v2.workflow-updates.php-package-sidecar',
                'runner_blocked' => false,
                'artifact_sources' => self::workflowUpdateEvidenceValue('artifact_sources', 'php_client_worker_update_surface'),
                'source_policy' => [
                    'pass_requires_published_artifacts_only' => true,
                    'local_product_source_checkouts_used' => false,
                    'local_checkout_execution_counts_as_pass' => false,
                ],
                'scenario_results' => array_values($scenarioResults),
                'findings' => [],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $command = sprintf(
                '%s %s %s %s %s %s %s %s --result-dir %s 2>&1',
                'DW_SERVER_IMAGE=' . escapeshellarg('durableworkflow/server:0.2.536'),
                'DW_SERVER_VERSION=' . escapeshellarg('0.2.536'),
                'DW_CLI_VERSION=' . escapeshellarg('0.1.82'),
                'DW_PYTHON_SDK_VERSION=' . escapeshellarg('0.4.92'),
                'DW_PHP_SDK_VERSION=' . escapeshellarg('0.1.1'),
                'DW_WORKFLOW_PHP_VERSION=' . escapeshellarg('2.0.0-alpha.242'),
                'DW_WATERLINE_VERSION=' . escapeshellarg('2.0.0-alpha.111'),
                escapeshellarg($root . '/scripts/conformance/workflow-updates-published-artifacts.sh'),
                escapeshellarg($resultDir),
            );

            exec($command, $output, $status);

            $this->assertSame(0, $status, implode("\n", $output));

            $result = json_decode((string) file_get_contents($resultDir . '/workflow-updates-result.json'), true);
            $metadata = json_decode((string) file_get_contents($resultDir . '/run-metadata.json'), true);
            $record = json_decode((string) file_get_contents($resultDir . '/workflow-updates-record.json'), true);

            $this->assertSame('fail', $result['outcome']);
            $this->assertTrue($result['php_sidecar']['evidence_loaded']);
            $this->assertSame('0.1.1', $result['php_sidecar']['package_version']);
            $this->assertSame(
                'packagist://durable-workflow/sdk@0.1.1',
                $result['php_sidecar']['artifact_source'],
            );
            $this->assertSame('sdk-php-workflow-updates-evidence.json', $metadata['php_sidecar_evidence_file']);
            $this->assertSame('sdk-php-workflow-updates-evidence.json', $record['php_sidecar_evidence_file']);
            $this->assertSame('pass', $result['scenario_results']['php_client_worker_update_surface']['status']);
            $this->assertSame('not_covered', $result['scenario_results']['principal_attribution_with_auth']['status']);
            $this->assertSame('not_covered', $result['scenario_results']['python_client_worker_update_surface']['status']);
            $this->assertSame('not_covered', $result['scenario_results']['operator_diagnostics_surfaces']['status']);
            $this->assertContains('principal_attribution_with_auth', $result['non_passing_scenarios']);
            $this->assertContains('python_client_worker_update_surface', $result['non_passing_scenarios']);
            $this->assertContains('operator_diagnostics_surfaces', $result['non_passing_scenarios']);
        } finally {
            exec('rm -rf ' . escapeshellarg($resultDir));
        }
    }

    public function test_handoff_imports_operator_diagnostics_sidecar_for_operator_surface_only(): void
    {
        if (trim((string) shell_exec('command -v node')) === '') {
            $this->markTestSkipped('node is required to execute the workflow updates handoff');
        }

        $root = dirname(__DIR__, 2);
        $resultDir = sys_get_temp_dir() . '/dw-workflow-updates-operator-sidecar-test-' . bin2hex(random_bytes(6));
        mkdir($resultDir, 0777, true);

        try {
            $operatorObserved = self::completeOperatorDiagnosticsObservedOutputs();
            file_put_contents($resultDir . '/workflow-updates-operator-diagnostics-evidence.json', json_encode([
                'schema' => 'durable-workflow.v2.workflow-updates.operator-diagnostics-sidecar',
                'runner_blocked' => false,
                'artifact_versions' => self::workflowUpdateEvidenceValue('published_artifact_versions', 'operator_diagnostics_surfaces'),
                'published_artifact_versions' => self::workflowUpdateEvidenceValue('published_artifact_versions', 'operator_diagnostics_surfaces'),
                'artifact_sources' => self::workflowUpdateEvidenceValue('artifact_sources', 'operator_diagnostics_surfaces'),
                'source_policy' => [
                    'pass_requires_published_artifacts_only' => true,
                    'local_product_source_checkouts_used' => false,
                    'local_checkout_execution_counts_as_pass' => false,
                ],
                'scenario_results' => [
                    'operator_diagnostics_surfaces' => [
                        'scenario_id' => 'operator_diagnostics_surfaces',
                        'status' => 'pass',
                        'classification' => 'product-evidence',
                        'published_artifact_cell_executed' => true,
                        'local_product_source_checkouts_used' => false,
                        'observed_outputs' => $operatorObserved,
                        'linked_findings' => [],
                    ],
                    'php_client_worker_update_surface' => [
                        'scenario_id' => 'php_client_worker_update_surface',
                        'status' => 'pass',
                        'classification' => 'product-evidence',
                        'published_artifact_cell_executed' => true,
                        'local_product_source_checkouts_used' => false,
                        'observed_outputs' => self::completeWorkflowUpdateObservedOutputs('php_client_worker_update_surface'),
                        'linked_findings' => [],
                    ],
                ],
                'findings' => [],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $command = sprintf(
                '%s %s %s %s %s %s %s %s %s --result-dir %s 2>&1',
                'DW_SERVER_IMAGE=' . escapeshellarg('durableworkflow/server:0.2.536'),
                'DW_SERVER_VERSION=' . escapeshellarg('0.2.536'),
                'DW_CLI_VERSION=' . escapeshellarg('0.1.82'),
                'DW_PYTHON_SDK_VERSION=' . escapeshellarg('0.4.92'),
                'DW_PHP_SDK_VERSION=' . escapeshellarg('0.1.1'),
                'DW_WORKFLOW_PHP_VERSION=' . escapeshellarg('2.0.0-alpha.242'),
                'DW_WATERLINE_VERSION=' . escapeshellarg('2.0.0-alpha.111'),
                'DW_WORKFLOW_UPDATES_SKIP_PHP_PACKAGE_SHARD=' . escapeshellarg('1'),
                escapeshellarg($root . '/scripts/conformance/workflow-updates-published-artifacts.sh'),
                escapeshellarg($resultDir),
            );

            exec($command, $output, $status);

            $this->assertSame(0, $status, implode("\n", $output));

            $result = json_decode((string) file_get_contents($resultDir . '/workflow-updates-result.json'), true);
            $metadata = json_decode((string) file_get_contents($resultDir . '/run-metadata.json'), true);
            $record = json_decode((string) file_get_contents($resultDir . '/workflow-updates-record.json'), true);
            $sidecar = json_decode((string) file_get_contents($resultDir . '/workflow-updates-operator-diagnostics-evidence.json'), true);

            $this->assertSame('fail', $result['outcome']);
            $this->assertTrue($result['operator_diagnostics_sidecar']['evidence_loaded']);
            $this->assertSame('workflow-updates-operator-diagnostics-evidence.json', $metadata['operator_diagnostics_evidence_file']);
            $this->assertSame('workflow-updates-operator-diagnostics-evidence.json', $record['operator_diagnostics_evidence_file']);
            $this->assertSame('pass', $result['scenario_results']['operator_diagnostics_surfaces']['status']);
            $this->assertSame([], $result['artifact_policy_failures']);
            $this->assertSame('not_covered', $result['scenario_results']['php_client_worker_update_surface']['status']);
            $this->assertContains('php_client_worker_update_surface', $result['non_passing_scenarios']);
            $this->assertFalse($result['operator_diagnostics_sidecar']['local_product_source_checkouts_used']);
            foreach (['artifact_versions', 'published_artifact_versions'] as $field) {
                $this->assertSame('0.1.1', $sidecar[$field]['sdk-php']);
                $this->assertSame(
                    '0.1.1',
                    $sidecar['scenario_results']['operator_diagnostics_surfaces']['observed_outputs'][$field]['sdk-php'],
                );
            }
            $this->assertSame('packagist://durable-workflow/sdk@0.1.1', $sidecar['artifact_sources']['sdk-php']);
            $this->assertSame(
                'packagist://durable-workflow/sdk@0.1.1',
                $sidecar['scenario_results']['operator_diagnostics_surfaces']['observed_outputs']['artifact_sources']['sdk-php'],
            );
            $this->assertStringNotContainsString(
                'workflow-updates-cli-waterline-diagnostics-coverage-gap',
                json_encode($result['findings'], JSON_THROW_ON_ERROR),
            );
        } finally {
            exec('rm -rf ' . escapeshellarg($resultDir));
        }
    }

    public function test_handoff_rejects_passing_python_and_operator_sidecars_without_php_sdk_provenance(): void
    {
        if (trim((string) shell_exec('command -v node')) === '') {
            $this->markTestSkipped('node is required to execute the workflow updates handoff');
        }

        $root = dirname(__DIR__, 2);
        $cases = [
            'python' => [
                'file' => 'python-sdk-workflow-updates-evidence.json',
                'schema' => 'durable-workflow.v2.workflow-updates.python-sdk-sidecar',
                'scenario' => 'python_client_worker_update_surface',
            ],
            'operator' => [
                'file' => 'workflow-updates-operator-diagnostics-evidence.json',
                'schema' => 'durable-workflow.v2.workflow-updates.operator-diagnostics-sidecar',
                'scenario' => 'operator_diagnostics_surfaces',
            ],
        ];

        foreach ($cases as $caseName => $case) {
            $resultDir = sys_get_temp_dir() . '/dw-workflow-updates-' . $caseName . '-missing-php-provenance-test-' . bin2hex(random_bytes(6));
            mkdir($resultDir, 0777, true);

            try {
                $observedOutputs = $caseName === 'operator'
                    ? self::completeOperatorDiagnosticsObservedOutputs()
                    : self::completeWorkflowUpdateObservedOutputs($case['scenario']);
                if ($caseName === 'python') {
                    $observedOutputs['package_import_path'] = '/tmp/durable-workflow-python-sdk-venv/lib/python3.11/site-packages/durable_workflow/workflow_updates_conformance.py';
                }

                $artifactVersions = self::workflowUpdateEvidenceValue('published_artifact_versions', $case['scenario']);
                $artifactSources = self::workflowUpdateEvidenceValue('artifact_sources', $case['scenario']);
                $observedOutputs['artifact_versions'] = $artifactVersions;
                $observedOutputs['published_artifact_versions'] = $artifactVersions;
                $observedOutputs['artifact_sources'] = $artifactSources;
                unset($artifactVersions['sdk-php'], $artifactSources['sdk-php']);
                foreach (['artifact_versions', 'published_artifact_versions'] as $field) {
                    unset($observedOutputs[$field]['sdk-php']);
                }
                unset($observedOutputs['artifact_sources']['sdk-php']);

                file_put_contents($resultDir . '/' . $case['file'], json_encode([
                    'schema' => $case['schema'],
                    'runner_blocked' => false,
                    'artifact_versions' => $artifactVersions,
                    'published_artifact_versions' => $artifactVersions,
                    'artifact_sources' => $artifactSources,
                    'source_policy' => [
                        'pass_requires_published_artifacts_only' => true,
                        'local_product_source_checkouts_used' => false,
                        'local_checkout_execution_counts_as_pass' => false,
                    ],
                    'scenario_results' => [
                        $case['scenario'] => [
                            'scenario_id' => $case['scenario'],
                            'status' => 'pass',
                            'classification' => 'product-evidence',
                            'published_artifact_cell_executed' => true,
                            'local_product_source_checkouts_used' => false,
                            'observed_outputs' => $observedOutputs,
                            'linked_findings' => [],
                        ],
                    ],
                    'findings' => [],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                $command = sprintf(
                    '%s %s %s %s %s %s %s %s %s --result-dir %s 2>&1',
                    'DW_SERVER_IMAGE=' . escapeshellarg('durableworkflow/server:0.2.536'),
                    'DW_SERVER_VERSION=' . escapeshellarg('0.2.536'),
                    'DW_CLI_VERSION=' . escapeshellarg('0.1.82'),
                    'DW_PYTHON_SDK_VERSION=' . escapeshellarg('0.4.92'),
                    'DW_PHP_SDK_VERSION=' . escapeshellarg('0.1.1'),
                    'DW_WORKFLOW_PHP_VERSION=' . escapeshellarg('2.0.0-alpha.242'),
                    'DW_WATERLINE_VERSION=' . escapeshellarg('2.0.0-alpha.111'),
                    'DW_WORKFLOW_UPDATES_SKIP_PHP_PACKAGE_SHARD=' . escapeshellarg('1'),
                    escapeshellarg($root . '/scripts/conformance/workflow-updates-published-artifacts.sh'),
                    escapeshellarg($resultDir),
                );

                $output = [];
                exec($command, $output, $status);

                $this->assertSame(0, $status, implode("\n", $output));

                $result = json_decode((string) file_get_contents($resultDir . '/workflow-updates-result.json'), true);
                $scenario = $result['scenario_results'][$case['scenario']];
                $failures = $scenario['observed_outputs']['artifact_prerequisite_failures'];
                $phpFailures = array_values(array_filter(
                    $failures,
                    static fn (array $failure): bool => $failure['artifact'] === 'sdk-php',
                ));

                $this->assertSame('not_covered', $scenario['status'], $caseName);
                $this->assertNotEmpty($phpFailures, $caseName);
                $this->assertContains('evidence_placeholder_artifact_version', array_column($phpFailures, 'code'), $caseName);
                $this->assertContains('evidence_placeholder_artifact_source', array_column($phpFailures, 'code'), $caseName);
            } finally {
                exec('rm -rf ' . escapeshellarg($resultDir));
            }
        }
    }

    public function test_handoff_rejects_operator_pass_from_focused_evidence(): void
    {
        if (trim((string) shell_exec('command -v node')) === '') {
            $this->markTestSkipped('node is required to execute the workflow updates handoff');
        }

        $root = dirname(__DIR__, 2);
        $resultDir = sys_get_temp_dir() . '/dw-workflow-updates-operator-focused-scope-test-' . bin2hex(random_bytes(6));
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
                    'observed_outputs' => $scenarioId === 'operator_diagnostics_surfaces'
                        ? self::completeOperatorDiagnosticsObservedOutputs()
                        : self::completeWorkflowUpdateObservedOutputs($scenarioId),
                    'linked_findings' => [],
                ];
            }

            $evidencePath = $resultDir . '/focused-with-operator-workflow-updates-evidence.json';
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
                '%s %s %s %s %s %s %s %s %s --result-dir %s 2>&1',
                'DW_SERVER_IMAGE=' . escapeshellarg('durableworkflow/server:0.2.536'),
                'DW_SERVER_VERSION=' . escapeshellarg('0.2.536'),
                'DW_CLI_VERSION=' . escapeshellarg('0.1.82'),
                'DW_PYTHON_SDK_VERSION=' . escapeshellarg('0.4.92'),
                'DW_PHP_SDK_VERSION=' . escapeshellarg('0.1.1'),
                'DW_WORKFLOW_PHP_VERSION=' . escapeshellarg('2.0.0-alpha.242'),
                'DW_WATERLINE_VERSION=' . escapeshellarg('2.0.0-alpha.111'),
                'DW_WORKFLOW_UPDATES_EVIDENCE_PATH=' . escapeshellarg($evidencePath),
                escapeshellarg($root . '/scripts/conformance/workflow-updates-published-artifacts.sh'),
                escapeshellarg($resultDir),
            );

            exec($command, $output, $status);

            $this->assertSame(0, $status, implode("\n", $output));

            $result = json_decode((string) file_get_contents($resultDir . '/workflow-updates-result.json'), true);

            $this->assertSame('not_covered', $result['scenario_results']['operator_diagnostics_surfaces']['status']);
            $this->assertContains('operator_diagnostics_surfaces', $result['non_passing_scenarios']);
            $this->assertSame(
                'workflow-updates-operator-diagnostics-shard-coverage-gap',
                $result['scenario_results']['operator_diagnostics_surfaces']['linked_findings'][0]['finding_id'],
            );
        } finally {
            exec('rm -rf ' . escapeshellarg($resultDir));
        }
    }

    public function test_handoff_downgrades_operator_sidecar_pass_when_cli_or_waterline_capture_is_missing(): void
    {
        if (trim((string) shell_exec('command -v node')) === '') {
            $this->markTestSkipped('node is required to execute the workflow updates handoff');
        }

        $root = dirname(__DIR__, 2);

        foreach (['cli_fields', 'waterline_fields'] as $missingField) {
            $resultDir = sys_get_temp_dir() . '/dw-workflow-updates-operator-missing-capture-test-' . bin2hex(random_bytes(6));
            mkdir($resultDir, 0777, true);

            try {
                $observedOutputs = self::completeOperatorDiagnosticsObservedOutputs();
                unset($observedOutputs[$missingField]);

                file_put_contents($resultDir . '/workflow-updates-operator-diagnostics-evidence.json', json_encode([
                    'schema' => 'durable-workflow.v2.workflow-updates.operator-diagnostics-sidecar',
                    'runner_blocked' => false,
                    'source_policy' => [
                        'pass_requires_published_artifacts_only' => true,
                        'local_product_source_checkouts_used' => false,
                        'local_checkout_execution_counts_as_pass' => false,
                    ],
                    'scenario_results' => [
                        'operator_diagnostics_surfaces' => [
                            'scenario_id' => 'operator_diagnostics_surfaces',
                            'status' => 'pass',
                            'classification' => 'product-evidence',
                            'published_artifact_cell_executed' => true,
                            'local_product_source_checkouts_used' => false,
                            'observed_outputs' => $observedOutputs,
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
                    'DW_PHP_SDK_VERSION=' . escapeshellarg('0.1.1'),
                    'DW_WORKFLOW_PHP_VERSION=' . escapeshellarg('2.0.0-alpha.242'),
                    'DW_WATERLINE_VERSION=' . escapeshellarg('2.0.0-alpha.111'),
                    escapeshellarg($root . '/scripts/conformance/workflow-updates-published-artifacts.sh'),
                    escapeshellarg($resultDir),
                );

                exec($command, $output, $status);

                $this->assertSame(0, $status, implode("\n", $output));

                $result = json_decode((string) file_get_contents($resultDir . '/workflow-updates-result.json'), true);
                $scenario = $result['scenario_results']['operator_diagnostics_surfaces'];

                $this->assertSame('not_covered', $scenario['status'], $missingField);
                $this->assertContains('operator_diagnostics_surfaces', $result['non_passing_scenarios'], $missingField);
                $this->assertSame(
                    'workflow-updates-operator-diagnostics-surfaces-required-evidence-gap',
                    $scenario['linked_findings'][0]['finding_id'],
                    $missingField,
                );
                $this->assertContains($missingField, $scenario['observed_outputs']['missing_required_fields'], $missingField);
            } finally {
                exec('rm -rf ' . escapeshellarg($resultDir));
            }
        }
    }

    public function test_handoff_downgrades_operator_sidecar_pass_when_operator_states_do_not_match(): void
    {
        if (trim((string) shell_exec('command -v node')) === '') {
            $this->markTestSkipped('node is required to execute the workflow updates handoff');
        }

        $root = dirname(__DIR__, 2);
        $resultDir = sys_get_temp_dir() . '/dw-workflow-updates-operator-state-mismatch-test-' . bin2hex(random_bytes(6));
        mkdir($resultDir, 0777, true);

        try {
            $observedOutputs = self::completeOperatorDiagnosticsObservedOutputs();
            foreach (['cli_fields', 'waterline_fields'] as $surfaceField) {
                foreach (['accepted', 'completed', 'failed', 'refused'] as $state) {
                    $observedOutputs[$surfaceField]['operator_surface_matrix']['states'][$state]['state'] = 'wrong-state';
                    $observedOutputs[$surfaceField]['operator_surface_matrix']['states'][$state]['state_visible'] = true;
                }
            }
            $observedOutputs['diagnostic_transition_matrix']['surfaces']['cli'] = $observedOutputs['cli_fields']['operator_surface_matrix'];
            $observedOutputs['diagnostic_transition_matrix']['surfaces']['waterline'] = $observedOutputs['waterline_fields']['operator_surface_matrix'];
            foreach (['accepted', 'completed', 'failed', 'refused'] as $state) {
                $observedOutputs['diagnostic_transition_matrix']['states'][$state]['cli'] = $observedOutputs['cli_fields']['operator_surface_matrix']['states'][$state];
                $observedOutputs['diagnostic_transition_matrix']['states'][$state]['waterline'] = $observedOutputs['waterline_fields']['operator_surface_matrix']['states'][$state];
            }

            file_put_contents($resultDir . '/workflow-updates-operator-diagnostics-evidence.json', json_encode([
                'schema' => 'durable-workflow.v2.workflow-updates.operator-diagnostics-sidecar',
                'runner_blocked' => false,
                'source_policy' => [
                    'pass_requires_published_artifacts_only' => true,
                    'local_product_source_checkouts_used' => false,
                    'local_checkout_execution_counts_as_pass' => false,
                ],
                'scenario_results' => [
                    'operator_diagnostics_surfaces' => [
                        'scenario_id' => 'operator_diagnostics_surfaces',
                        'status' => 'pass',
                        'classification' => 'product-evidence',
                        'published_artifact_cell_executed' => true,
                        'local_product_source_checkouts_used' => false,
                        'observed_outputs' => $observedOutputs,
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
                'DW_PHP_SDK_VERSION=' . escapeshellarg('0.1.1'),
                'DW_WORKFLOW_PHP_VERSION=' . escapeshellarg('2.0.0-alpha.242'),
                'DW_WATERLINE_VERSION=' . escapeshellarg('2.0.0-alpha.111'),
                escapeshellarg($root . '/scripts/conformance/workflow-updates-published-artifacts.sh'),
                escapeshellarg($resultDir),
            );

            exec($command, $output, $status);

            $this->assertSame(0, $status, implode("\n", $output));

            $result = json_decode((string) file_get_contents($resultDir . '/workflow-updates-result.json'), true);
            $scenario = $result['scenario_results']['operator_diagnostics_surfaces'];

            $this->assertSame('not_covered', $scenario['status']);
            $this->assertContains('operator_diagnostics_surfaces', $result['non_passing_scenarios']);
            $this->assertSame(
                'workflow-updates-operator-diagnostics-surfaces-required-evidence-gap',
                $scenario['linked_findings'][0]['finding_id'],
            );

            $failureKeys = array_map(
                static fn (array $failure): string => $failure['surface'] . '.' . $failure['state'] . ':' . implode(',', $failure['missing_fields']),
                $scenario['observed_outputs']['operator_diagnostics_failures'],
            );
            foreach (['cli', 'waterline'] as $surface) {
                foreach (['accepted', 'completed', 'failed', 'refused'] as $state) {
                    $this->assertContains($surface . '.' . $state . ':expected_state', $failureKeys);
                }
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($resultDir));
        }
    }

    public function test_handoff_rejects_python_sidecar_pass_with_local_source_import_path(): void
    {
        if (trim((string) shell_exec('command -v node')) === '') {
            $this->markTestSkipped('node is required to execute the workflow updates handoff');
        }

        $root = dirname(__DIR__, 2);
        $resultDir = sys_get_temp_dir() . '/dw-workflow-updates-python-sidecar-policy-test-' . bin2hex(random_bytes(6));
        mkdir($resultDir, 0777, true);

        try {
            file_put_contents($resultDir . '/python-sdk-workflow-updates-evidence.json', json_encode([
                'schema' => 'durable-workflow.v2.workflow-updates.python-sdk-sidecar',
                'runner_blocked' => false,
                'source_policy' => [
                    'pass_requires_published_artifacts_only' => true,
                    'local_product_source_checkouts_used' => false,
                    'local_checkout_execution_counts_as_pass' => false,
                ],
                'scenario_results' => [
                    'python_client_worker_update_surface' => [
                        'scenario_id' => 'python_client_worker_update_surface',
                        'status' => 'pass',
                        'classification' => 'product-evidence',
                        'published_artifact_cell_executed' => true,
                        'local_product_source_checkouts_used' => false,
                        'observed_outputs' => self::completeWorkflowUpdateObservedOutputs('python_client_worker_update_surface') + [
                            'package_import_path' => '/workspace/repos/sdk-python/src/durable_workflow/workflow_updates_conformance.py',
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
                'DW_PHP_SDK_VERSION=' . escapeshellarg('0.1.1'),
                'DW_WORKFLOW_PHP_VERSION=' . escapeshellarg('2.0.0-alpha.242'),
                'DW_WATERLINE_VERSION=' . escapeshellarg('2.0.0-alpha.111'),
                escapeshellarg($root . '/scripts/conformance/workflow-updates-published-artifacts.sh'),
                escapeshellarg($resultDir),
            );

            exec($command, $output, $status);

            $this->assertSame(0, $status, implode("\n", $output));

            $result = json_decode((string) file_get_contents($resultDir . '/workflow-updates-result.json'), true);
            $scenario = $result['scenario_results']['python_client_worker_update_surface'];

            $this->assertSame('fail', $result['outcome']);
            $this->assertTrue($result['source_policy']['local_product_source_checkouts_used']);
            $this->assertTrue($result['local_product_source_checkouts_used']);
            $this->assertTrue($result['python_sidecar']['local_product_source_checkouts_used']);
            $this->assertSame('not_covered', $scenario['status']);
            $this->assertTrue($scenario['local_product_source_checkouts_used']);
            $this->assertSame(
                'workflow-updates-python-client-worker-update-surface-source-policy-gap',
                $scenario['linked_findings'][0]['finding_id'],
            );
        } finally {
            exec('rm -rf ' . escapeshellarg($resultDir));
        }
    }

    public function test_handoff_rejects_php_package_sidecar_local_artifact_source(): void
    {
        if (trim((string) shell_exec('command -v node')) === '') {
            $this->markTestSkipped('node is required to execute the workflow updates handoff');
        }

        $root = dirname(__DIR__, 2);
        $resultDir = sys_get_temp_dir() . '/dw-workflow-updates-php-sidecar-policy-test-' . bin2hex(random_bytes(6));
        mkdir($resultDir, 0777, true);

        try {
            $observedOutputs = self::completeWorkflowUpdateObservedOutputs('php_client_worker_update_surface');
            $observedOutputs['sdk_php_artifact_source'] = 'file:///tmp/local-sdk-checkout';
            $observedOutputs['package_artifact_source'] = 'local_product_source_checkout';

            file_put_contents($resultDir . '/sdk-php-workflow-updates-evidence.json', json_encode([
                'schema' => 'durable-workflow.v2.workflow-updates.php-package-sidecar',
                'runner_blocked' => false,
                'source_policy' => [
                    'pass_requires_published_artifacts_only' => true,
                    'local_product_source_checkouts_used' => false,
                    'local_checkout_execution_counts_as_pass' => false,
                ],
                'scenario_results' => [
                    'php_client_worker_update_surface' => [
                        'scenario_id' => 'php_client_worker_update_surface',
                        'status' => 'pass',
                        'classification' => 'product-evidence',
                        'published_artifact_cell_executed' => true,
                        'local_product_source_checkouts_used' => false,
                        'observed_outputs' => $observedOutputs,
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
                'DW_PHP_SDK_VERSION=' . escapeshellarg('0.1.1'),
                'DW_WORKFLOW_PHP_VERSION=' . escapeshellarg('2.0.0-alpha.242'),
                'DW_WATERLINE_VERSION=' . escapeshellarg('2.0.0-alpha.111'),
                escapeshellarg($root . '/scripts/conformance/workflow-updates-published-artifacts.sh'),
                escapeshellarg($resultDir),
            );

            exec($command, $output, $status);

            $this->assertSame(0, $status, implode("\n", $output));

            $result = json_decode((string) file_get_contents($resultDir . '/workflow-updates-result.json'), true);
            $scenario = $result['scenario_results']['php_client_worker_update_surface'];

            $this->assertSame('fail', $result['outcome']);
            $this->assertTrue($result['source_policy']['local_product_source_checkouts_used']);
            $this->assertTrue($result['local_product_source_checkouts_used']);
            $this->assertTrue($result['php_sidecar']['local_product_source_checkouts_used']);
            $this->assertSame('not_covered', $scenario['status']);
            $this->assertTrue($scenario['local_product_source_checkouts_used']);
            $this->assertSame(
                'workflow-updates-php-client-worker-update-surface-source-policy-gap',
                $scenario['linked_findings'][0]['finding_id'],
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
                    '%s %s %s %s %s %s %s %s %s --result-dir %s 2>&1',
                    'DW_SERVER_IMAGE=' . escapeshellarg('durableworkflow/server:0.2.536'),
                    'DW_SERVER_VERSION=' . escapeshellarg('0.2.536'),
                    'DW_CLI_VERSION=' . escapeshellarg('0.1.82'),
                    'DW_PYTHON_SDK_VERSION=' . escapeshellarg('0.4.92'),
                    'DW_PHP_SDK_VERSION=' . escapeshellarg('0.1.1'),
                    'DW_WORKFLOW_PHP_VERSION=' . escapeshellarg('2.0.0-alpha.242'),
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
                '%s %s %s %s %s %s %s %s %s --result-dir %s 2>&1',
                'DW_SERVER_IMAGE=' . escapeshellarg('durableworkflow/server:0.2.536'),
                'DW_SERVER_VERSION=' . escapeshellarg('0.2.536'),
                'DW_CLI_VERSION=' . escapeshellarg('0.1.82'),
                'DW_PYTHON_SDK_VERSION=' . escapeshellarg('0.4.92'),
                'DW_PHP_SDK_VERSION=' . escapeshellarg('0.1.1'),
                'DW_WORKFLOW_PHP_VERSION=' . escapeshellarg('2.0.0-alpha.242'),
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
                '%s %s %s %s %s %s %s %s %s --result-dir %s 2>&1',
                'DW_SERVER_IMAGE=' . escapeshellarg('durableworkflow/server:latest'),
                'DW_SERVER_VERSION=' . escapeshellarg('latest'),
                'DW_CLI_VERSION=' . escapeshellarg('0.1.82'),
                'DW_PYTHON_SDK_VERSION=' . escapeshellarg('0.4.92'),
                'DW_PHP_SDK_VERSION=' . escapeshellarg('0.1.1'),
                'DW_WORKFLOW_PHP_VERSION=' . escapeshellarg('2.0.0-alpha.242'),
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
                '%s %s %s %s %s %s %s %s %s --result-dir %s 2>&1',
                'DW_SERVER_IMAGE=' . escapeshellarg('durableworkflow/server:0.2.536'),
                'DW_SERVER_VERSION=' . escapeshellarg('0.2.536'),
                'DW_CLI_VERSION=' . escapeshellarg('0.1.82'),
                'DW_PYTHON_SDK_VERSION=' . escapeshellarg('0.4.92'),
                'DW_PHP_SDK_VERSION=' . escapeshellarg('0.1.1'),
                'DW_WORKFLOW_PHP_VERSION=' . escapeshellarg('2.0.0-alpha.242'),
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
            $badSources['workflow-php'] = 'file:///tmp/local-workflow-engine-checkout';
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
                '%s %s %s %s %s %s %s %s %s --result-dir %s 2>&1',
                'DW_SERVER_IMAGE=' . escapeshellarg('durableworkflow/server:0.2.536'),
                'DW_SERVER_VERSION=' . escapeshellarg('0.2.536'),
                'DW_CLI_VERSION=' . escapeshellarg('0.1.82'),
                'DW_PYTHON_SDK_VERSION=' . escapeshellarg('0.4.92'),
                'DW_PHP_SDK_VERSION=' . escapeshellarg('0.1.1'),
                'DW_WORKFLOW_PHP_VERSION=' . escapeshellarg('2.0.0-alpha.242'),
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

    public function test_focused_probe_loads_composer_autoload_before_laravel_bootstrap(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/scripts/conformance/workflow-updates-published-artifacts.sh',
        );

        $autoload = "\$repoRoot = (string) getenv('RUNNER_REPO_ROOT');\n    require_once \$repoRoot.'/vendor/autoload.php';";
        $bootstrap = "\$app = require \$repoRoot.'/bootstrap/app.php';";

        $this->assertStringContainsString($autoload, $source);
        $this->assertStringContainsString($bootstrap, $source);
        $this->assertLessThan(
            strpos($source, $bootstrap),
            strpos($source, $autoload),
            'The published-image focused probe must load Composer autoload before bootstrap/app.php.',
        );
    }

    public function test_python_and_operator_sidecar_materializers_include_php_sdk_provenance(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/scripts/conformance/workflow-updates-published-artifacts.sh',
        );
        $boundaries = [
            'python status evidence' => ['write_python_sdk_shard_status() {', 'materialize_python_sdk_shard_report() {'],
            'python passing evidence' => ['materialize_python_sdk_shard_report() {', 'run_python_sdk_shard() {'],
            'operator status evidence' => ['write_operator_diagnostics_shard_status() {', 'run_operator_diagnostics_worker_step() {'],
            'operator passing evidence' => ['materialize_operator_diagnostics_report() {', 'run_operator_diagnostics_shard() {'],
        ];

        foreach ($boundaries as $message => [$startMarker, $endMarker]) {
            $start = strpos($source, $startMarker);
            $end = strpos($source, $endMarker, $start === false ? 0 : $start);

            $this->assertNotFalse($start, $message);
            $this->assertNotFalse($end, $message);

            $materializer = substr($source, (int) $start, (int) $end - (int) $start);
            foreach ([
                'DW_PHP_SDK_VERSION="${DW_PHP_SDK_VERSION:-}"',
                "const phpSdkVersion = (process.env.DW_PHP_SDK_VERSION || '').trim()",
                "'sdk-php': phpSdkVersion",
                "'sdk-php': `packagist://durable-workflow/sdk@\${phpSdkVersion}`",
            ] as $expected) {
                $this->assertStringContainsString($expected, $materializer, $message);
            }
        }
    }

    public function test_php_sdk_shard_installs_pinned_packagist_artifact_and_runs_separate_processes(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/scripts/conformance/workflow-updates-published-artifacts.sh',
        );

        $this->assertStringContainsString(
            'sdk-php-workflow-updates-evidence.json',
            $source,
        );
        $this->assertStringContainsString(
            'DW_PHP_SDK_VERSION="$sdk_php_version"',
            $source,
        );
        $this->assertStringContainsString(
            '"${DW_SERVER_IMAGE}" scripts/conformance/php-sdk-published-artifacts.sh --result-dir /result',
            $source,
        );
        $this->assertStringContainsString('docker compose -p "$compose_project"', $source);
        $this->assertStringContainsString('docker run --rm --network host', $source);
        $this->assertStringContainsString(
            'php-sdk-conformance-result.json',
            $source,
        );
        $this->assertStringContainsString(
            'php-sdk-lifecycle-evidence.json',
            $source,
        );
        $this->assertStringContainsString(
            'sdk-php-workflow-updates-conformance.log',
            $source,
        );
        $this->assertStringContainsString(
            'install_provenance',
            $source,
        );
        $this->assertStringContainsString(
            'sdk_php_artifact_source',
            $source,
        );
        $this->assertStringContainsString(
            'localSourceFieldValues(value).some((source) => sourceUsesForbiddenToken(source))',
            $source,
        );
        $this->assertStringNotContainsString(
            'durable-workflow/workflow:${sdk_php_version}',
            $source,
            'the PHP SDK shard must not depend on the removed Workflow package command',
        );
    }

    public function test_python_sdk_shard_installs_pinned_pypi_artifact_and_runs_package_command(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/scripts/conformance/workflow-updates-published-artifacts.sh',
        );

        $this->assertStringContainsString(
            'DW_WORKFLOW_UPDATES_SKIP_PYTHON_SDK_SHARD',
            $source,
        );
        $this->assertStringContainsString(
            'python-sdk-workflow-updates-evidence.json',
            $source,
        );
        $this->assertStringContainsString(
            'PIP_CONFIG_FILE=/dev/null "$venv_python" -m pip --isolated install',
            $source,
        );
        $this->assertStringContainsString(
            '--index-url https://pypi.org/simple',
            $source,
        );
        $this->assertStringContainsString(
            '"durable-workflow==${python_version}"',
            $source,
        );
        $this->assertStringContainsString(
            'python-sdk-package-source-policy.log',
            $source,
        );
        $this->assertStringContainsString(
            'durable-workflow-workflow-updates-conformance',
            $source,
        );
        $this->assertStringContainsString(
            '--expected-version "$python_version"',
            $source,
        );
        $this->assertStringContainsString(
            'sdk_python_artifact_source',
            $source,
        );
        $this->assertStringContainsString(
            'python_worker_update_handler',
            $source,
        );
        $this->assertStringContainsString(
            'python_client_update_request',
            $source,
        );
        $this->assertStringContainsString(
            'localSourceFieldValues(value).some((source) => sourceUsesForbiddenToken(source))',
            $source,
        );
        $this->assertStringContainsString(
            'packageImportPathValues(value).some((source) => packageImportPathUsesForbiddenToken(source))',
            $source,
        );
    }

    /**
     * @return list<string>
     */
    private static function focusedWorkflowUpdateRuntimeScenarioIds(): array
    {
        return array_values(array_diff(
            self::workflowUpdateRuntimeScenarioIds(),
            ['php_client_worker_update_surface', 'python_client_worker_update_surface', 'operator_diagnostics_surfaces'],
        ));
    }

    /**
     * @return list<string>
     */
    private static function workflowUpdateRuntimeScenarioIds(): array
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2).'/static/platform-conformance/workflow-update-runtime-scenarios.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return array_keys($manifest['scenario_requirements']);
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

    /**
     * @return array<string, mixed>
     */
    private static function completeOperatorDiagnosticsObservedOutputs(): array
    {
        $cliMatrix = self::operatorDiagnosticsMatrix(includeHistoryExport: false);
        $waterlineMatrix = self::operatorDiagnosticsMatrix(includeHistoryExport: true);

        return [
            'workflow_id' => 'wf-operator-diagnostics',
            'run_id' => 'run-operator-diagnostics',
            'cli_fields' => [
                'surface' => 'workflow:update --json',
                'cli_artifact_version' => '0.1.82',
                'cli_artifact_source' => 'https://github.com/durable-workflow/cli/releases/download/0.1.82/install.sh',
                'operator_surface_matrix' => $cliMatrix,
            ],
            'api_fields' => [
                'run_detail_capture' => [
                    'status' => 200,
                    'json' => [
                        'workflow_id' => 'wf-operator-diagnostics',
                        'run_id' => 'run-operator-diagnostics',
                    ],
                ],
            ],
            'history_fields' => [
                'history_capture' => [
                    'status' => 200,
                    'json' => [
                        'events' => [
                            ['event_type' => 'UpdateAccepted'],
                            ['event_type' => 'UpdateCompleted'],
                            ['event_type' => 'UpdateRejected'],
                        ],
                    ],
                ],
            ],
            'waterline_fields' => [
                'waterline_artifact_version' => '2.0.0-alpha.111',
                'waterline_artifact_source' => 'packagist://durable-workflow/waterline@2.0.0-alpha.111',
                'operator_surface_matrix' => $waterlineMatrix,
                'api_captures' => [
                    'selected_run_detail' => ['status' => 200],
                    'selected_run_history_export' => ['status' => 200],
                ],
            ],
            'diagnostic_transition_matrix' => [
                'required_states' => ['accepted', 'completed', 'failed', 'refused'],
                'surfaces' => [
                    'cli' => $cliMatrix,
                    'waterline' => $waterlineMatrix,
                ],
                'states' => [
                    'accepted' => [
                        'cli' => $cliMatrix['states']['accepted'],
                        'waterline' => $waterlineMatrix['states']['accepted'],
                    ],
                    'completed' => [
                        'cli' => $cliMatrix['states']['completed'],
                        'waterline' => $waterlineMatrix['states']['completed'],
                    ],
                    'failed' => [
                        'cli' => $cliMatrix['states']['failed'],
                        'waterline' => $waterlineMatrix['states']['failed'],
                    ],
                    'refused' => [
                        'cli' => $cliMatrix['states']['refused'],
                        'waterline' => $waterlineMatrix['states']['refused'],
                    ],
                ],
            ],
            'artifact_install_evidence' => [
                'cli' => [
                    'installed_from' => 'https://github.com/durable-workflow/cli/releases/download/0.1.82/install.sh',
                    'version' => '0.1.82',
                ],
                'waterline' => [
                    'installed_from' => 'packagist://durable-workflow/waterline@2.0.0-alpha.111',
                    'version' => '2.0.0-alpha.111',
                ],
            ],
            'artifact_versions' => self::workflowUpdateEvidenceValue('published_artifact_versions', 'operator_diagnostics_surfaces'),
            'artifact_sources' => self::workflowUpdateEvidenceValue('artifact_sources', 'operator_diagnostics_surfaces'),
            'published_artifact_versions' => self::workflowUpdateEvidenceValue('published_artifact_versions', 'operator_diagnostics_surfaces'),
            'source_policy' => self::workflowUpdateEvidenceValue('source_policy', 'operator_diagnostics_surfaces'),
            'published_artifact_cell_executed' => true,
            'local_product_source_checkouts_used' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function operatorDiagnosticsMatrix(bool $includeHistoryExport): array
    {
        $states = [];

        foreach ([
            'accepted' => ['result' => null, 'error' => null],
            'completed' => ['result' => true, 'error' => null],
            'failed' => ['result' => null, 'error' => true],
            'refused' => ['result' => null, 'error' => true],
        ] as $state => $expectations) {
            $states[$state] = [
                'present' => true,
                'request_id' => $state . '-request',
                'update_id' => $state === 'refused' ? null : $state . '-update',
                'state' => $state,
                'outcome' => 'update_' . $state,
                'reason' => $state === 'accepted' || $state === 'completed' ? null : $state . '_reason',
                'request_identifiers_visible' => true,
                'state_visible' => true,
                'outcome_or_reason_visible' => true,
                'payload_visible' => true,
                'result_visible' => $expectations['result'],
                'error_visible' => $expectations['error'],
                'history_references_visible' => true,
                'history_references' => [
                    'workflow_sequence' => 10,
                    'command_sequence' => 4,
                ],
            ];

            if ($includeHistoryExport) {
                $states[$state]['history_export_references_visible'] = true;
                $states[$state]['history_export_event_types'] = $state === 'refused'
                    ? ['UpdateRejected']
                    : ['UpdateAccepted', 'UpdateCompleted'];
            }
        }

        return [
            'surface' => $includeHistoryExport ? 'selected_run_update_history' : 'workflow:update --json',
            'required_states' => ['accepted', 'completed', 'failed', 'refused'],
            'state_counts' => [
                'accepted' => 1,
                'completed' => 1,
                'failed' => 1,
                'refused' => 1,
            ],
            'states' => $states,
        ];
    }

    private static function workflowUpdateEvidenceValue(string $field, string $scenarioId): mixed
    {
        return match ($field) {
            'published_artifact_versions' => [
                'server' => '0.2.536',
                'cli' => '0.1.82',
                'sdk-python' => '0.4.92',
                'sdk-php' => '0.1.1',
                'workflow' => '2.0.0-alpha.242',
                'workflow-php' => '2.0.0-alpha.242',
                'waterline' => '2.0.0-alpha.111',
            ],
            'artifact_sources' => [
                'server' => 'durableworkflow/server:0.2.536',
                'cli' => 'https://github.com/durable-workflow/cli/releases/download/0.1.82/install.sh',
                'sdk-python' => 'pypi://durable-workflow==0.4.92',
                'sdk-php' => 'packagist://durable-workflow/sdk@0.1.1',
                'workflow' => 'packagist://durable-workflow/workflow@2.0.0-alpha.242',
                'workflow-php' => 'packagist://durable-workflow/workflow@2.0.0-alpha.242',
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
            'covered_cells' => [
                'accepted',
                'completed',
                'failed',
                'refused_unknown_update',
                'duplicate_idempotent',
                'terminal_refusal',
                'payload_round_trip',
            ],
            'unsupported_cells' => [
                [
                    'cell' => 'invalid_input_refusal',
                    'classification' => 'typed_unsupported',
                ],
            ],
            'typed_errors' => [],
            'handler_not_invoked' => true,
            default => [
                'scenario_id' => $scenarioId,
                'field' => $field,
                'observed' => true,
            ],
        };
    }
}
