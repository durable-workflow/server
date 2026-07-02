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

    public function test_handoff_materializes_external_evidence_and_references_materialized_file(): void
    {
        $evidence = self::completeWorkflowUpdateEvidence();

        $run = $this->runWorkflowUpdatesHandoff($evidence, 'dw-workflow-updates-materialized-');

        $this->assertSame('pass', $run['result']['outcome']);
        $this->assertTrue($run['materialized_focused_evidence_file_exists']);
        $this->assertSame($evidence['schema'], $run['focused_evidence']['schema']);
        $this->assertSame(
            'workflow-updates-focused-evidence.json',
            $run['metadata']['focused_evidence_file'],
        );
        $this->assertSame(
            $run['metadata']['focused_evidence_file'],
            $run['record']['focused_evidence_file'],
        );
        $this->assertSame(
            $run['metadata']['focused_evidence_file'],
            $run['result']['focused_probe']['evidence_file'],
        );
    }

    public function test_handoff_keeps_runner_blocked_external_evidence_non_passing(): void
    {
        foreach (['runner_blocked', 'runnerBlocked'] as $field) {
            $evidence = self::completeWorkflowUpdateEvidence();
            unset($evidence['runner_blocked'], $evidence['runnerBlocked']);
            $evidence[$field] = 'true';
            $evidence['blocked_reason'] = 'host probe could not start the published image';

            $run = $this->runWorkflowUpdatesHandoff($evidence, 'dw-workflow-updates-runner-blocked-');
            $result = $run['result'];

            $this->assertSame('fail', $result['outcome'], $field);
            $this->assertTrue($result['runner_blocked'], $field);
            $this->assertTrue($run['record']['runnerBlocked'], $field);
            $this->assertSame(
                'runner_blocked',
                $result['scenario_results']['published_artifact_install_only']['status'],
                $field,
            );
            $this->assertSame(
                'runner_blocked',
                $result['update_cell_outcomes']['published_artifact_install_only'],
                $field,
            );
            $this->assertContains(
                'conformance_runner_blocked',
                array_column($result['findings'], 'finding_type'),
                $field,
            );
        }
    }

    public function test_handoff_downgrades_pass_evidence_missing_required_observed_outputs(): void
    {
        $evidence = self::completeWorkflowUpdateEvidence();
        unset($evidence['scenario_results']['accepted_update_control_plane_and_history']['observed_outputs']['update_response']);

        $run = $this->runWorkflowUpdatesHandoff($evidence, 'dw-workflow-updates-missing-required-');
        $scenario = $run['result']['scenario_results']['accepted_update_control_plane_and_history'];

        $this->assertSame('fail', $run['result']['outcome']);
        $this->assertSame('not_covered', $scenario['status']);
        $this->assertContains('accepted_update_control_plane_and_history', $run['result']['non_passing_scenarios']);
        $this->assertContains('update_response', $scenario['observed_outputs']['missing_required_fields']);
        $this->assertContains(
            'conformance_runner_coverage_gap',
            array_column($scenario['linked_findings'], 'finding_type'),
        );
    }

    public function test_handoff_downgrades_placeholder_artifact_tuple_before_pass(): void
    {
        $evidence = self::completeWorkflowUpdateEvidence();
        $evidence['artifact_versions']['server'] = 'current';
        $evidence['published_artifact_versions']['server'] = 'current';
        $evidence['artifact_sources']['server'] = 'docker://durableworkflow/server:latest';

        foreach (self::workflowUpdateRuntimeScenarioIds() as $scenarioId) {
            $evidence['scenario_results'][$scenarioId]['observed_outputs']['artifact_versions']['server'] = 'current';
            $evidence['scenario_results'][$scenarioId]['observed_outputs']['published_artifact_versions']['server'] = 'current';
            $evidence['scenario_results'][$scenarioId]['observed_outputs']['artifact_sources']['server'] = 'docker://durableworkflow/server:latest';
        }

        $run = $this->runWorkflowUpdatesHandoff($evidence, 'dw-workflow-updates-placeholder-artifacts-');

        $this->assertSame('fail', $run['result']['outcome']);
        $this->assertSame(
            'not_covered',
            $run['result']['scenario_results']['published_artifact_install_only']['status'],
        );
        $this->assertContains(
            'placeholder_published_artifact_version',
            array_column($run['result']['artifact_policy_failures'], 'code'),
        );
        $this->assertContains(
            'placeholder_published_artifact_source',
            array_column($run['result']['artifact_policy_failures'], 'code'),
        );
    }

    public function test_handoff_validates_workflow_php_artifact_entries_when_workflow_alias_is_clean(): void
    {
        $evidence = self::completeWorkflowUpdateEvidence();
        $evidence['artifact_versions']['workflow'] = '2.0.0-alpha.241';
        $evidence['artifact_versions']['workflow-php'] = 'current';
        $evidence['published_artifact_versions']['workflow'] = '2.0.0-alpha.241';
        $evidence['published_artifact_versions']['workflow-php'] = 'current';
        $evidence['artifact_sources']['workflow'] = 'packagist://durable-workflow/workflow@2.0.0-alpha.241';
        $evidence['artifact_sources']['workflow-php'] = 'current';

        foreach (self::workflowUpdateRuntimeScenarioIds() as $scenarioId) {
            $evidence['scenario_results'][$scenarioId]['observed_outputs']['artifact_versions'] = $evidence['artifact_versions'];
            $evidence['scenario_results'][$scenarioId]['observed_outputs']['published_artifact_versions'] = $evidence['published_artifact_versions'];
            $evidence['scenario_results'][$scenarioId]['observed_outputs']['artifact_sources'] = $evidence['artifact_sources'];
        }

        $run = $this->runWorkflowUpdatesHandoff($evidence, 'dw-workflow-updates-workflow-php-placeholder-');
        $result = $run['result'];

        $this->assertSame('fail', $result['outcome']);
        $this->assertSame(
            'not_covered',
            $result['scenario_results']['published_artifact_install_only']['status'],
        );
        $this->assertContains(
            'placeholder_published_artifact_version',
            array_column($result['artifact_policy_failures'], 'code'),
        );
        $this->assertContains(
            'placeholder_published_artifact_source',
            array_column($result['artifact_policy_failures'], 'code'),
        );
        $this->assertContains(
            '$.artifact_versions.workflow-php',
            array_column($result['artifact_policy_failures'], 'path'),
        );
        $this->assertContains(
            '$.published_artifact_versions.workflow-php',
            array_column($result['artifact_policy_failures'], 'path'),
        );
        $this->assertContains(
            '$.artifact_sources.workflow-php',
            array_column($result['artifact_policy_failures'], 'path'),
        );
    }

    public function test_handoff_rejects_forbidden_artifact_source_values(): void
    {
        $evidence = self::completeWorkflowUpdateEvidence();
        $badSources = self::artifactSources();
        $badSources['server'] = 'local_source_checkout';
        $badSources['cli'] = 'branch_source';
        $badSources['sdk-python'] = 'workspace_repo';
        $badSources['workflow'] = 'file:///workspace/repos/workflow';
        $badSources['workflow-php'] = 'file:///workspace/repos/workflow';
        $badSources['waterline'] = '/tmp/local-worktree/waterline';
        $evidence['artifact_sources'] = $badSources;

        foreach (self::workflowUpdateRuntimeScenarioIds() as $scenarioId) {
            $evidence['scenario_results'][$scenarioId]['observed_outputs']['artifact_sources'] = $badSources;
        }

        $run = $this->runWorkflowUpdatesHandoff($evidence, 'dw-workflow-updates-forbidden-sources-');
        $result = $run['result'];

        $this->assertSame('fail', $result['outcome']);
        $this->assertTrue($result['local_product_source_checkouts_used']);
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
     * @param array<string, string> $env
     * @return array{
     *     result: array<string, mixed>,
     *     record: array<string, mixed>,
     *     metadata: array<string, mixed>,
     *     focused_evidence: array<string, mixed>,
     *     materialized_focused_evidence_file_exists: bool
     * }
     */
    private function runWorkflowUpdatesHandoff(array $evidence, string $prefix, array $env = []): array
    {
        if (trim((string) shell_exec('command -v node')) === '') {
            $this->markTestSkipped('node is required to execute the workflow updates handoff');
        }

        $root = dirname(__DIR__, 2);
        $resultDir = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));
        mkdir($resultDir, 0777, true);

        try {
            $evidencePath = $resultDir . '/external-workflow-updates-evidence.json';
            file_put_contents(
                $evidencePath,
                json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            );

            $environment = array_merge([
                'DW_SERVER_IMAGE' => 'durableworkflow/server:0.2.536',
                'DW_SERVER_VERSION' => '0.2.536',
                'DW_CLI_VERSION' => '0.1.82',
                'DW_PYTHON_SDK_VERSION' => '0.4.92',
                'DW_WORKFLOW_PHP_VERSION' => '2.0.0-alpha.241',
                'DW_WATERLINE_VERSION' => '2.0.0-alpha.111',
                'DW_WORKFLOW_UPDATES_EVIDENCE_PATH' => $evidencePath,
            ], $env);

            $command = [];
            foreach ($environment as $key => $value) {
                $command[] = $key . '=' . escapeshellarg($value);
            }
            $command[] = escapeshellarg($root . '/scripts/conformance/workflow-updates-published-artifacts.sh');
            $command[] = '--result-dir';
            $command[] = escapeshellarg($resultDir);

            exec(implode(' ', $command) . ' 2>&1', $output, $status);

            $this->assertSame(0, $status, implode("\n", $output));

            $focusedEvidencePath = $resultDir . '/workflow-updates-focused-evidence.json';

            return [
                'result' => json_decode((string) file_get_contents($resultDir . '/workflow-updates-result.json'), true),
                'record' => json_decode((string) file_get_contents($resultDir . '/workflow-updates-record.json'), true),
                'metadata' => json_decode((string) file_get_contents($resultDir . '/run-metadata.json'), true),
                'focused_evidence' => json_decode((string) file_get_contents($focusedEvidencePath), true),
                'materialized_focused_evidence_file_exists' => file_exists($focusedEvidencePath),
            ];
        } finally {
            exec('rm -rf ' . escapeshellarg($resultDir));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function completeWorkflowUpdateEvidence(): array
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/static/platform-conformance/workflow-update-runtime-scenarios.json'),
            true,
        );
        $artifactVersions = self::artifactVersions();
        $publishedArtifactVersions = $artifactVersions;
        $publishedArtifactVersions['workflow-php'] = $artifactVersions['workflow'];
        $artifactSources = self::artifactSources();
        $sourcePolicy = self::sourcePolicy();

        $scenarioResults = [];
        foreach (self::workflowUpdateRuntimeScenarioIds() as $scenarioId) {
            $observedOutputs = [
                'published_artifact_cell_executed' => true,
                'local_product_source_checkouts_used' => false,
                'published_artifact_versions' => $publishedArtifactVersions,
                'artifact_versions' => $artifactVersions,
                'artifact_sources' => $artifactSources,
                'artifact_install_evidence' => [
                    'installed_from_published_channels' => true,
                ],
                'source_policy' => $sourcePolicy,
            ];

            foreach ($manifest['scenario_requirements'][$scenarioId]['required_fields'] as $field) {
                if (! array_key_exists($field, $observedOutputs)) {
                    $observedOutputs[$field] = self::observedValueFor($field);
                }
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

        return [
            'schema' => 'durable-workflow.v2.workflow-update-runtime.external-evidence',
            'runner_blocked' => false,
            'artifact_versions' => $artifactVersions,
            'published_artifact_versions' => $publishedArtifactVersions,
            'artifact_sources' => $artifactSources,
            'source_policy' => $sourcePolicy,
            'local_product_source_checkouts_used' => false,
            'scenario_results' => $scenarioResults,
            'findings' => [],
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function artifactVersions(): array
    {
        return [
            'server' => '0.2.536',
            'cli' => '0.1.82',
            'sdk-python' => '0.4.92',
            'workflow' => '2.0.0-alpha.241',
            'waterline' => '2.0.0-alpha.111',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function artifactSources(): array
    {
        return [
            'server' => 'durableworkflow/server:0.2.536',
            'cli' => 'github-release://durable-workflow/cli/v0.1.82/install.sh',
            'sdk-python' => 'pypi://durable-workflow==0.4.92',
            'workflow' => 'packagist://durable-workflow/workflow@2.0.0-alpha.241',
            'workflow-php' => 'packagist://durable-workflow/workflow@2.0.0-alpha.241',
            'waterline' => 'packagist://durable-workflow/waterline@2.0.0-alpha.111',
        ];
    }

    /**
     * @return array<string, bool>
     */
    private static function sourcePolicy(): array
    {
        return [
            'pass_requires_published_artifacts_only' => true,
            'local_product_source_checkouts_used' => false,
            'local_checkout_execution_counts_as_pass' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function observedValueFor(string $field): array
    {
        return [
            'field' => $field,
            'observed' => true,
        ];
    }
}
