<?php

namespace Tests\Unit;

use App\Support\MigrationRuntimeContract;
use PHPUnit\Framework\TestCase;

class MigrationConformanceRunnerContractTest extends TestCase
{
    public function test_runner_handoff_composes_full_migration_result(): void
    {
        $shell = $this->read('scripts/conformance/migration-published-artifacts.sh');
        $node = $this->read('scripts/conformance/migration-published-artifacts.mjs');

        $this->assertStringContainsString(
            'Usage: migration-published-artifacts.sh [--result-dir DIR|--result-dir=DIR] [--keep-run-root[=1|true]]',
            $shell,
        );
        $this->assertStringContainsString(
            'node "$script_dir/migration-published-artifacts.mjs"',
            $shell,
            'the shell handoff must execute the checked-in Node composer',
        );
        $this->assertStringContainsString('DW_MIGRATION_EVIDENCE_JSON', $shell);
        $this->assertStringContainsString('DW_MIGRATION_STORAGE_SMOKE_JSON', $shell);

        foreach ([
            'migration-published-artifacts.json',
            'migration-conformance-result.json',
            'migration-conformance-record.json',
            'durable-workflow.v2.migration-runtime.result',
            'scenario_results',
            'published_artifact_versions',
            'resolved_artifact_versions',
            'artifact_sources',
            'storage_connection_smoke',
        ] as $token) {
            $this->assertStringContainsString($token, $node);
        }
    }

    public function test_runner_keeps_missing_required_cells_non_passing_with_findings(): void
    {
        $node = $this->read('scripts/conformance/migration-published-artifacts.mjs');

        foreach ([
            'latest_supported_v1_state_setup',
            'documented_migration_steps_execute',
            'completed_history_preservation_and_replay',
            'in_flight_workflow_progress_preserved',
            'mid_activity_retry_preserved',
            'schedule_cross_upgrade_cadence_preserved',
            'worker_registration_projection_preserved',
            'waterline_operator_visibility_preserved',
            'cli_access_to_preupgrade_state',
            'new_v2_workflow_start_after_upgrade',
            'rollback_contract_verified',
            'version_skew_refusal',
            'not_covered',
            'conformance_runner_coverage_gap',
            'resultPasses(result) ? \'pass\' : \'non_passing\'',
        ] as $token) {
            $this->assertStringContainsString($token, $node);
        }

        $this->assertStringContainsString(
            'No published-artifact migration evidence was supplied for ${scenarioId}.',
            $node,
            'missing required cells must become linked coverage findings rather than disappearing from the result',
        );
    }

    public function test_runner_rejects_contract_placeholder_artifact_versions_before_passing(): void
    {
        $node = $this->read('scripts/conformance/migration-published-artifacts.mjs');

        foreach ([
            'FALLBACK_PLACEHOLDER_VERSION_EXAMPLES',
            'placeholderVersionExamples',
            'isPlaceholderArtifactVersion',
            '1.x',
            '2.0.0-alpha.<latest>',
        ] as $token) {
            $this->assertStringContainsString($token, $node);
        }

        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the migration runner placeholder gate.');
        }

        foreach ([
            'workflow-php-v1' => '1.x',
            'workflow-php-v2' => '2.0.0-alpha.<latest>',
        ] as $artifact => $placeholderVersion) {
            $this->assertRunnerKeepsPlaceholderVersionNonPassing($nodeBinary, $artifact, $placeholderVersion);
        }
    }

    public function test_runner_rejects_whitespace_only_required_evidence_before_passing(): void
    {
        $node = $this->read('scripts/conformance/migration-published-artifacts.mjs');

        $this->assertStringContainsString('value.trim() === \'\'', $node);

        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the migration runner whitespace evidence gate.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $tempRoot = sys_get_temp_dir().'/dw-migration-whitespace-'.bin2hex(random_bytes(6));
        $resultDir = $tempRoot.'/result';
        $evidencePath = $tempRoot.'/migration-evidence.json';

        try {
            mkdir($resultDir, 0777, true);
            $evidence = $this->completeRunnerEvidence();
            $evidence['scenario_results']['latest_supported_v1_state_setup']['observed_outputs']['seeded_workflows'] = " \t\n ";

            file_put_contents(
                $evidencePath,
                json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n",
            );

            $process = proc_open(
                [$nodeBinary, $repoRoot.'/scripts/conformance/migration-published-artifacts.mjs'],
                [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                $repoRoot,
                [
                    'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
                    'DW_MIGRATION_REPO_ROOT' => $repoRoot,
                    'DW_MIGRATION_RESULT_DIR' => $resultDir,
                    'DW_MIGRATION_EVIDENCE_JSON' => $evidencePath,
                ],
            );

            $this->assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            $this->assertSame(0, $exitCode, ($stdout === false ? '' : $stdout).($stderr === false ? '' : $stderr));

            $resultPath = $resultDir.'/migration-conformance-result.json';
            $this->assertFileExists($resultPath);
            $result = json_decode((string) file_get_contents($resultPath), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(
                'non_passing',
                $result['outcome'],
                'whitespace-only required migration scenario evidence must not allow the runner to emit pass',
            );
        } finally {
            $this->removeTree($tempRoot);
        }
    }

    public function test_runner_normalizes_contract_release_artifact_aliases_before_passing(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the migration runner alias gate.');
        }

        $evidence = $this->completeRunnerEvidence();

        $workflowV1Version = $evidence['published_artifact_versions']['workflow-php-v1'];
        $workflowV2Version = $evidence['published_artifact_versions']['workflow-php-v2'];
        $workflowV1Source = $evidence['artifact_sources']['workflow-php-v1'];
        $workflowV2Source = $evidence['artifact_sources']['workflow-php-v2'];

        foreach (['published_artifact_versions', 'resolved_artifact_versions', 'artifact_sources'] as $field) {
            unset($evidence[$field]['workflow-php-v1'], $evidence[$field]['workflow-php-v2']);
        }

        $evidence['published_artifact_versions']['workflow-v1'] = $workflowV1Version;
        $evidence['published_artifact_versions']['workflow'] = $workflowV2Version;
        $evidence['resolved_artifact_versions']['workflow-v1'] = $workflowV1Version;
        $evidence['resolved_artifact_versions']['workflow-php'] = $workflowV2Version;
        $evidence['artifact_sources']['workflow-v1'] = $workflowV1Source;
        $evidence['artifact_sources']['workflow-php'] = $workflowV2Source;

        $evidence['scenario_results']['published_artifact_install_only']['observed_outputs']['resolved_artifact_versions'] =
            $evidence['resolved_artifact_versions'];
        $evidence['scenario_results']['published_artifact_install_only']['observed_outputs']['artifact_sources'] =
            $evidence['artifact_sources'];

        $result = $this->runRunnerEvidence($nodeBinary, $evidence, 'dw-migration-aliases-');

        $this->assertSame('pass', $result['outcome']);
        $this->assertSame($workflowV1Version, $result['resolved_artifact_versions']['workflow-php-v1']);
        $this->assertSame($workflowV2Version, $result['resolved_artifact_versions']['workflow-php-v2']);
        $this->assertSame($workflowV2Source, $result['artifact_sources']['workflow-php-v2']);
    }

    public function test_runner_keeps_runner_blocked_flag_non_passing_without_blocked_reason(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the migration runner blocked gate.');
        }

        foreach (['runner_blocked', 'runnerBlocked'] as $field) {
            $evidence = $this->completeRunnerEvidence();
            $evidence['outcome'] = 'non_passing_runner_blocked';
            $evidence[$field] = true;

            $result = $this->runRunnerEvidence($nodeBinary, $evidence, 'dw-migration-runner-blocked-');

            $this->assertSame('non_passing_runner_blocked', $result['outcome']);
            $this->assertTrue($result['runner_blocked']);
            $this->assertSame(
                'runner_blocked',
                $result['scenario_results']['published_artifact_install_only']['status'],
            );
        }
    }

    public function test_runner_rejects_nested_local_product_source_checkout_usage(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the migration runner local-source gate.');
        }

        foreach (['scenario', 'observed_outputs'] as $location) {
            $evidence = $this->completeRunnerEvidence();
            $evidence['local_product_source_checkouts_used'] = false;

            if ($location === 'scenario') {
                $evidence['scenario_results']['published_artifact_install_only']['local_product_source_checkouts_used'] = true;
            } else {
                $evidence['scenario_results']['published_artifact_install_only']['observed_outputs']['local_product_source_checkouts_used'] = true;
            }

            $result = $this->runRunnerEvidence($nodeBinary, $evidence, 'dw-migration-local-source-'.$location.'-');

            $this->assertSame('non_passing', $result['outcome']);
            $this->assertTrue($result['local_product_source_checkouts_used']);
        }
    }

    public function test_runner_rejects_non_source_artifact_placeholders(): void
    {
        $node = $this->read('scripts/conformance/migration-published-artifacts.mjs');

        foreach ([
            'FORBIDDEN_SOURCE_TOKENS',
            'not_exercised',
            'unverified_artifact_source',
            'local_product_source_checkouts_used',
            'local_product_source_artifacts: false',
            'artifactMapComplete(result.artifact_sources, true)',
        ] as $token) {
            $this->assertStringContainsString($token, $node);
        }
    }

    private function read(string $path): string
    {
        $fullPath = dirname(__DIR__, 2) . '/' . $path;

        $this->assertFileExists($fullPath);

        return (string) file_get_contents($fullPath);
    }

    private function assertRunnerKeepsPlaceholderVersionNonPassing(
        string $nodeBinary,
        string $artifact,
        string $placeholderVersion,
    ): void {
        $repoRoot = dirname(__DIR__, 2);
        $tempRoot = sys_get_temp_dir().'/dw-migration-placeholder-'.bin2hex(random_bytes(6));
        $resultDir = $tempRoot.'/result';
        $evidencePath = $tempRoot.'/migration-evidence.json';

        try {
            mkdir($resultDir, 0777, true);
            $evidence = $this->completeRunnerEvidence();
            $evidence['published_artifact_versions'][$artifact] = $placeholderVersion;
            $evidence['resolved_artifact_versions'][$artifact] = $placeholderVersion;
            $evidence['scenario_results']['published_artifact_install_only']['observed_outputs']['resolved_artifact_versions'][$artifact] = $placeholderVersion;

            file_put_contents(
                $evidencePath,
                json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n",
            );

            $process = proc_open(
                [$nodeBinary, $repoRoot.'/scripts/conformance/migration-published-artifacts.mjs'],
                [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                $repoRoot,
                [
                    'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
                    'DW_MIGRATION_REPO_ROOT' => $repoRoot,
                    'DW_MIGRATION_RESULT_DIR' => $resultDir,
                    'DW_MIGRATION_EVIDENCE_JSON' => $evidencePath,
                ],
            );

            $this->assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            $this->assertSame(0, $exitCode, ($stdout === false ? '' : $stdout).($stderr === false ? '' : $stderr));

            $resultPath = $resultDir.'/migration-conformance-result.json';
            $this->assertFileExists($resultPath);
            $result = json_decode((string) file_get_contents($resultPath), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(
                'non_passing',
                $result['outcome'],
                "{$artifact}={$placeholderVersion} must not allow the published-artifact migration runner to emit pass",
            );
        } finally {
            $this->removeTree($tempRoot);
        }
    }

    /**
     * @param array<string, mixed> $evidence
     *
     * @return array<string, mixed>
     */
    private function runRunnerEvidence(string $nodeBinary, array $evidence, string $tempPrefix): array
    {
        $repoRoot = dirname(__DIR__, 2);
        $tempRoot = sys_get_temp_dir().'/'.$tempPrefix.bin2hex(random_bytes(6));
        $resultDir = $tempRoot.'/result';
        $evidencePath = $tempRoot.'/migration-evidence.json';

        try {
            mkdir($resultDir, 0777, true);
            file_put_contents(
                $evidencePath,
                json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n",
            );

            $process = proc_open(
                [$nodeBinary, $repoRoot.'/scripts/conformance/migration-published-artifacts.mjs'],
                [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                $repoRoot,
                [
                    'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
                    'DW_MIGRATION_REPO_ROOT' => $repoRoot,
                    'DW_MIGRATION_RESULT_DIR' => $resultDir,
                    'DW_MIGRATION_EVIDENCE_JSON' => $evidencePath,
                ],
            );

            $this->assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            $this->assertSame(0, $exitCode, ($stdout === false ? '' : $stdout).($stderr === false ? '' : $stderr));

            $resultPath = $resultDir.'/migration-conformance-result.json';
            $this->assertFileExists($resultPath);

            return json_decode((string) file_get_contents($resultPath), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            $this->removeTree($tempRoot);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function completeRunnerEvidence(): array
    {
        $scenarioResults = [];
        foreach (MigrationRuntimeContract::manifest()['scenario_requirements'] as $scenarioId => $requirements) {
            $observedOutputs = [];
            foreach ($requirements['required_fields'] as $field) {
                $observedOutputs[$field] = match ($field) {
                    'local_product_source_checkouts_used' => false,
                    'artifact_sources' => $this->artifactSources(),
                    'resolved_artifact_versions' => $this->artifactVersions(),
                    default => $field.'-observed',
                };
            }

            $scenarioResults[$scenarioId] = [
                'status' => 'pass',
                'observed_outputs' => $observedOutputs,
            ];
        }

        return [
            'outcome' => 'pass',
            'started_at' => '2026-05-31T22:39:36Z',
            'finished_at' => '2026-05-31T22:40:20Z',
            'published_artifact_versions' => $this->artifactVersions(),
            'resolved_artifact_versions' => $this->artifactVersions(),
            'artifact_sources' => $this->artifactSources(),
            'local_product_source_checkouts_used' => false,
            'findings' => [],
            'finding_links' => [],
            'migration_plan' => ['guide_revision' => 'docs/2.0/migration'],
            'preupgrade_state_snapshot' => ['state_kinds' => MigrationRuntimeContract::manifest()['required_matrix']['state_kinds']],
            'postupgrade_state_snapshot' => ['state_kinds' => MigrationRuntimeContract::manifest()['required_matrix']['state_kinds']],
            'history_dumps' => ['completed' => true, 'running' => true],
            'activity_attempts' => ['retry_preserved' => true],
            'schedule_ticks' => ['cadence_preserved' => true],
            'worker_registration_observations' => ['projection_preserved' => true],
            'cli_observations' => ['preupgrade_state_readable' => true],
            'waterline_observations' => ['preupgrade_state_visible' => true],
            'rollback_observations' => ['documented_behavior_verified' => true],
            'version_skew_observations' => ['refused_loudly' => true],
            'storage_connection_smoke' => ['passed' => true],
            'scenario_results' => $scenarioResults,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function artifactVersions(): array
    {
        return [
            'server-v1' => '1.3.9',
            'server-v2' => '0.2.203',
            'cli' => '0.1.70',
            'workflow-php-v1' => '1.7.4',
            'workflow-php-v2' => '2.0.0-alpha.185',
            'sdk-python' => '0.4.83',
            'waterline' => '2.0.0-alpha.69',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function artifactSources(): array
    {
        return [
            'server-v1' => 'published_docker_image',
            'server-v2' => 'published_docker_image',
            'cli' => 'official_install_script',
            'workflow-php-v1' => 'composer_release',
            'workflow-php-v2' => 'composer_release',
            'sdk-python' => 'pypi_release',
            'waterline' => 'published_waterline_release',
        ];
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);
        $this->assertNotFalse($items);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path.'/'.$item;
            if (is_dir($itemPath)) {
                $this->removeTree($itemPath);
            } else {
                @unlink($itemPath);
            }
        }

        @rmdir($path);
    }
}
