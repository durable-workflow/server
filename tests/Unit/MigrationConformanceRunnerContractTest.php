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
        $this->assertStringContainsString('DW_MIGRATION_EVIDENCE_DIR', $shell);
        $this->assertStringContainsString('DW_MIGRATION_STORAGE_SMOKE_JSON', $shell);
        $this->assertStringContainsString('DW_MIGRATION_RUN_PUBLIC_GUIDE_AUDIT', $shell);
        $this->assertStringContainsString('DW_MIGRATION_GUIDE_AUDIT_TEXT', $shell);
        $this->assertStringContainsString('DW_MIGRATION_RESOLVE_PUBLIC_ARTIFACTS', $shell);
        $this->assertStringContainsString('DW_MIGRATION_PUBLIC_ARTIFACTS_JSON', $shell);

        foreach ([
            'migration-published-artifacts.json',
            'migration-conformance-result.json',
            'migration-conformance-record.json',
            'durable-workflow.v2.migration-runtime.result',
            'experiment: \'migration\'',
            'runnerBlocked',
            'artifactVersions',
            'artifactSources',
            'resultPath',
            'artifactPath',
            'scenario_results',
            'published_artifact_versions',
            'resolved_artifact_versions',
            'artifact_sources',
            'storage_connection_smoke',
            'public_artifact_resolution',
            'public_operator_signal',
            'cli_skew_observations',
            'worker_skew_observations',
            'request_response_evidence',
            'readMigrationEvidence',
            'evidenceShardPaths',
            'mergeScenarioResults',
            'resolvePublicArtifactDefaults',
            'latestPackagistVersion',
            'latestDockerHubTag',
            'latestGithubReleaseVersion',
            'latestGithubBranchCommit',
            'pinV1ServerBaselineFromWorkflowRuntime',
            'embedded-v1-server-runtime',
            'maybeRunPublicGuideAudit',
            'public_migration_guide_audit',
            'SCENARIO_FINDING_POLICIES',
            'findingForNonPassScenario',
            'scenario_statuses',
            'missingRollbackClassificationFields',
            'cli-v1-to-server-v2',
            'worker-v2-to-server-v1',
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

    public function test_runner_routes_missing_published_artifact_prerequisites_as_failures(): void
    {
        $node = $this->read('scripts/conformance/migration-published-artifacts.mjs');

        foreach ([
            'artifactPrerequisiteFailuresFor',
            'missing_or_invalid_published_migration_artifact',
            'missing_published_artifact_version',
            'forbidden_published_artifact_source',
            'artifact_prerequisite_failed',
        ] as $token) {
            $this->assertStringContainsString($token, $node);
        }

        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the migration runner artifact prerequisite gate.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $tempRoot = sys_get_temp_dir().'/dw-migration-prerequisites-'.bin2hex(random_bytes(6));
        $resultDir = $tempRoot.'/result';

        try {
            mkdir($resultDir, 0777, true);

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
                    'DW_MIGRATION_RESOLVE_PUBLIC_ARTIFACTS' => '0',
                    'DW_SERVER_VERSION' => '0.2.239',
                    'DW_SERVER_ARTIFACT_SOURCE' => 'published_docker_image',
                    'DW_CLI_VERSION' => '0.1.75',
                    'DW_CLI_ARTIFACT_SOURCE' => 'official_install_script',
                    'DW_WORKFLOW_PHP_VERSION' => '2.0.0-alpha.189',
                    'DW_WORKFLOW_PHP_ARTIFACT_SOURCE' => 'composer_release',
                    'DW_PYTHON_SDK_VERSION' => '0.4.84',
                    'DW_PYTHON_SDK_ARTIFACT_SOURCE' => 'pypi_release',
                    'DW_WATERLINE_VERSION' => '2.0.0-alpha.77',
                    'DW_WATERLINE_ARTIFACT_SOURCE' => 'published_waterline_release',
                ],
            );

            $this->assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            $this->assertSame(0, $exitCode, ($stdout === false ? '' : $stdout).($stderr === false ? '' : $stderr));

            $result = json_decode(
                (string) file_get_contents($resultDir.'/migration-conformance-result.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $this->assertSame('non_passing', $result['outcome']);
            $this->assertSame(
                'fail',
                $result['scenario_results']['published_artifact_install_only']['status'],
            );
            $this->assertSame(
                true,
                $result['scenario_results']['published_artifact_install_only']['observed_outputs']['artifact_prerequisite_failed'],
            );
            $this->assertContains(
                'server-v1',
                array_column($result['artifact_prerequisite_failures'], 'artifact'),
            );
            $this->assertContains(
                'workflow-php-v1',
                array_column($result['artifact_prerequisite_failures'], 'artifact'),
            );

            $findingTypes = array_column(
                $result['scenario_results']['published_artifact_install_only']['linked_findings'],
                'finding_type',
            );
            $this->assertContains('missing_or_invalid_published_migration_artifact', $findingTypes);
        } finally {
            $this->removeTree($tempRoot);
        }
    }

    public function test_runner_routes_artifact_prerequisites_into_supplied_scenario_results(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the migration runner artifact prerequisite gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        unset(
            $evidence['published_artifact_versions']['server-v1'],
            $evidence['resolved_artifact_versions']['server-v1'],
        );
        $evidence['published_artifact_versions']['workflow-php-v2'] = '2.0.0-alpha.<latest>';
        $evidence['resolved_artifact_versions']['workflow-php-v1'] = '1.x';

        $result = $this->runRunnerEvidence($nodeBinary, $evidence, 'dw-migration-supplied-prerequisites-');

        $this->assertSame('non_passing', $result['outcome']);
        $this->assertContains(
            [
                'artifact' => 'server-v1',
                'field' => 'published_artifact_versions',
                'code' => 'missing_published_artifact_version',
            ],
            $result['artifact_prerequisite_failures'],
        );
        $this->assertContains(
            [
                'artifact' => 'server-v1',
                'field' => 'resolved_artifact_versions',
                'code' => 'missing_resolved_artifact_version',
            ],
            $result['artifact_prerequisite_failures'],
        );
        $this->assertContains(
            [
                'artifact' => 'workflow-php-v2',
                'field' => 'published_artifact_versions',
                'code' => 'placeholder_published_artifact_version',
                'value' => '2.0.0-alpha.<latest>',
            ],
            $result['artifact_prerequisite_failures'],
        );
        $this->assertContains(
            [
                'artifact' => 'workflow-php-v1',
                'field' => 'resolved_artifact_versions',
                'code' => 'placeholder_resolved_artifact_version',
                'value' => '1.x',
            ],
            $result['artifact_prerequisite_failures'],
        );
        $this->assertSame(
            'fail',
            $result['scenario_results']['published_artifact_install_only']['status'],
            'supplied passing scenarios must fail when required artifact versions are missing or placeholders',
        );
        $this->assertSame(
            true,
            $result['scenario_results']['published_artifact_install_only']['observed_outputs']['artifact_prerequisite_failed'],
        );
        $this->assertSame(
            'fail',
            $result['scenario_results']['documented_migration_steps_execute']['status'],
            'artifact prerequisites apply to every supplied required scenario, not only missing scenario cells',
        );

        $findingTypes = array_column(
            $result['scenario_results']['published_artifact_install_only']['linked_findings'],
            'finding_type',
        );
        $this->assertContains('missing_or_invalid_published_migration_artifact', $findingTypes);
        $this->assertNotContains('pass', array_column($result['scenario_results'], 'status'));
    }

    public function test_runner_rejects_explicit_forbidden_sources_masked_by_public_defaults(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the migration runner artifact source gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        $evidence['artifact_sources']['workflow-php-v1'] = 'not_exercised';
        $evidence['scenario_results']['published_artifact_install_only']['observed_outputs']['artifact_sources']['workflow-php-v1'] =
            'not_exercised';

        $publicArtifacts = [
            'artifact_versions' => $this->artifactVersions(),
            'artifact_sources' => $this->artifactSources(),
        ];

        $result = $this->runRunnerEvidence(
            $nodeBinary,
            $evidence,
            'dw-migration-forbidden-source-defaults-',
            [],
            null,
            $publicArtifacts,
        );

        $this->assertSame('non_passing', $result['outcome']);
        $this->assertSame(
            'not_exercised',
            $result['artifact_sources']['workflow-php-v1'],
            'explicit forbidden artifact source evidence must not be replaced by public resolver defaults',
        );
        $this->assertSame('fail', $result['scenario_results']['published_artifact_install_only']['status']);
        $this->assertNotEmpty(array_filter(
            $result['artifact_prerequisite_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'forbidden_published_artifact_source'
                && ($failure['artifact'] ?? null) === 'workflow-php-v1'
                && ($failure['field'] ?? null) === 'artifact_sources'
                && ($failure['value'] ?? null) === 'not_exercised',
        ));
        $this->assertNotEmpty(array_filter(
            $result['artifact_prerequisite_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'forbidden_published_artifact_source'
                && ($failure['artifact'] ?? null) === 'workflow-php-v1'
                && ($failure['path'] ?? null) === '$.scenario_results.published_artifact_install_only.observed_outputs.artifact_sources',
        ));
    }

    public function test_runner_resolves_latest_v1_server_baseline_from_supported_v1_runtime(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the migration runner public artifact resolver.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $tempRoot = sys_get_temp_dir().'/dw-migration-public-artifacts-'.bin2hex(random_bytes(6));
        $resultDir = $tempRoot.'/result';
        $metadataPath = $tempRoot.'/public-artifacts.json';

        try {
            mkdir($resultDir, 0777, true);
            file_put_contents(
                $metadataPath,
                json_encode([
                    'artifact_versions' => [
                        'workflow-php-v1' => '1.0.76',
                        'cli-v1' => '0.1.44',
                        'waterline-v1' => '1.0.16',
                        'sample-app-v1' => 'e769ac5f4147498c652445f517ae724d73afa4de',
                    ],
                    'artifact_sources' => [
                        'workflow-php-v1' => 'packagist:laravel-workflow/laravel-workflow:1.0.76',
                        'server-v1' => 'docker_hub:durableworkflow/server:no_v1_release_tag_found',
                        'cli-v1' => 'github_release:durable-workflow/cli:0.1.44:install.sh',
                        'waterline-v1' => 'packagist:laravel-workflow/waterline:1.0.16',
                        'sample-app-v1' => 'github_branch:durable-workflow/sample-app:Laravel-12@e769ac5f4147498c652445f517ae724d73afa4de',
                    ],
                    'observations' => [
                        'workflow-php-v1' => [
                            'status' => 'resolved',
                            'channel' => 'packagist',
                        ],
                        'server-v1' => [
                            'status' => 'missing',
                            'channel' => 'docker_hub',
                        ],
                        'cli-v1' => [
                            'status' => 'resolved',
                            'channel' => 'github_release',
                        ],
                        'waterline-v1' => [
                            'status' => 'resolved',
                            'channel' => 'packagist',
                        ],
                        'sample-app-v1' => [
                            'status' => 'resolved',
                            'channel' => 'github_branch',
                        ],
                    ],
                ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n",
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
                    'DW_MIGRATION_RESOLVE_PUBLIC_ARTIFACTS' => '1',
                    'DW_MIGRATION_PUBLIC_ARTIFACTS_JSON' => $metadataPath,
                    'DW_SERVER_VERSION' => '0.2.276',
                    'DW_SERVER_ARTIFACT_SOURCE' => 'published_docker_image',
                    'DW_CLI_VERSION' => '0.1.76',
                    'DW_CLI_ARTIFACT_SOURCE' => 'official_install_script',
                    'DW_WORKFLOW_PHP_VERSION' => '2.0.0-alpha.195',
                    'DW_WORKFLOW_PHP_ARTIFACT_SOURCE' => 'composer_release',
                    'DW_PYTHON_SDK_VERSION' => '0.4.85',
                    'DW_PYTHON_SDK_ARTIFACT_SOURCE' => 'pypi_release',
                    'DW_WATERLINE_VERSION' => '2.0.0-alpha.81',
                    'DW_WATERLINE_ARTIFACT_SOURCE' => 'published_waterline_release',
                ],
            );

            $this->assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            $this->assertSame(0, $exitCode, ($stdout === false ? '' : $stdout).($stderr === false ? '' : $stderr));

            $result = json_decode(
                (string) file_get_contents($resultDir.'/migration-conformance-result.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $record = json_decode(
                (string) file_get_contents($resultDir.'/migration-conformance-record.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $this->assertSame('1.0.76', $result['published_artifact_versions']['workflow-php-v1']);
            $this->assertSame(
                'packagist:laravel-workflow/laravel-workflow:1.0.76',
                $result['artifact_sources']['workflow-php-v1'],
            );
            $this->assertSame(
                '1.0.76',
                $result['published_artifact_versions']['server-v1'],
            );
            $this->assertSame(
                'packagist:laravel-workflow/laravel-workflow:1.0.76:embedded-v1-server-runtime',
                $result['artifact_sources']['server-v1'],
            );
            $this->assertSame(
                'resolved',
                $result['public_artifact_resolution']['observations']['server-v1']['status'],
            );
            $this->assertSame(
                'embedded-v1-server-runtime',
                $result['public_artifact_resolution']['observations']['server-v1']['runtime'],
            );
            $this->assertSame(
                'missing',
                $result['public_artifact_resolution']['observations']['server-v1']['standalone_server_image']['status'],
            );
            $this->assertSame(
                $result['public_artifact_resolution'],
                $record['public_artifact_resolution'],
            );
            $this->assertNotContains(
                'workflow-php-v1',
                array_column($result['artifact_prerequisite_failures'], 'artifact'),
                'public metadata should satisfy the latest supported v1 workflow install channel',
            );
            $this->assertSame('0.1.44', $result['published_artifact_versions']['cli-v1']);
            $this->assertSame('1.0.16', $result['published_artifact_versions']['waterline-v1']);
            $this->assertSame(
                'e769ac5f4147498c652445f517ae724d73afa4de',
                $result['published_artifact_versions']['sample-app-v1'],
            );
            foreach (['cli-v1', 'waterline-v1', 'sample-app-v1'] as $artifact) {
                $this->assertNotContains(
                    $artifact,
                    array_column($result['artifact_prerequisite_failures'], 'artifact'),
                    "public metadata should satisfy the {$artifact} install channel",
                );
            }
            $this->assertNotContains(
                'server-v1',
                array_column($result['artifact_prerequisite_failures'], 'artifact'),
                'the supported embedded v1 runtime should satisfy the v1 server baseline when no standalone image is published',
            );
        } finally {
            $this->removeTree($tempRoot);
        }
    }

    public function test_runner_synthesizes_published_install_cell_from_artifact_pins(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the migration runner artifact install synthesis.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $tempRoot = sys_get_temp_dir().'/dw-migration-install-cell-'.bin2hex(random_bytes(6));
        $resultDir = $tempRoot.'/result';
        $artifactVersions = $this->artifactVersions();
        $artifactSources = $this->artifactSources();

        try {
            mkdir($resultDir, 0777, true);

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
                    'DW_MIGRATION_RESOLVE_PUBLIC_ARTIFACTS' => '0',
                    'DW_SERVER_V1_VERSION' => $artifactVersions['server-v1'],
                    'DW_SERVER_V2_VERSION' => $artifactVersions['server-v2'],
                    'DW_SERVER_V1_ARTIFACT_SOURCE' => $artifactSources['server-v1'],
                    'DW_SERVER_V2_ARTIFACT_SOURCE' => $artifactSources['server-v2'],
                    'DW_CLI_V1_VERSION' => $artifactVersions['cli-v1'],
                    'DW_CLI_VERSION' => $artifactVersions['cli-v2'],
                    'DW_CLI_V1_ARTIFACT_SOURCE' => $artifactSources['cli-v1'],
                    'DW_CLI_ARTIFACT_SOURCE' => $artifactSources['cli-v2'],
                    'DW_WORKFLOW_PHP_V1_VERSION' => $artifactVersions['workflow-php-v1'],
                    'DW_WORKFLOW_PHP_V2_VERSION' => $artifactVersions['workflow-php-v2'],
                    'DW_WORKFLOW_PHP_V1_ARTIFACT_SOURCE' => $artifactSources['workflow-php-v1'],
                    'DW_WORKFLOW_PHP_V2_ARTIFACT_SOURCE' => $artifactSources['workflow-php-v2'],
                    'DW_PYTHON_SDK_VERSION' => $artifactVersions['sdk-python'],
                    'DW_PYTHON_SDK_ARTIFACT_SOURCE' => $artifactSources['sdk-python'],
                    'DW_WATERLINE_V1_VERSION' => $artifactVersions['waterline-v1'],
                    'DW_WATERLINE_VERSION' => $artifactVersions['waterline-v2'],
                    'DW_WATERLINE_V1_ARTIFACT_SOURCE' => $artifactSources['waterline-v1'],
                    'DW_WATERLINE_ARTIFACT_SOURCE' => $artifactSources['waterline-v2'],
                    'DW_SAMPLE_APP_V1_VERSION' => $artifactVersions['sample-app-v1'],
                    'DW_SAMPLE_APP_V1_ARTIFACT_SOURCE' => $artifactSources['sample-app-v1'],
                ],
            );

            $this->assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            $this->assertSame(0, $exitCode, ($stdout === false ? '' : $stdout).($stderr === false ? '' : $stderr));

            $result = json_decode(
                (string) file_get_contents($resultDir.'/migration-conformance-result.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $scenario = $result['scenario_results']['published_artifact_install_only'];

            $this->assertSame('non_passing', $result['outcome']);
            $this->assertSame('pass', $scenario['status']);
            $this->assertSame($artifactVersions, $scenario['observed_outputs']['resolved_artifact_versions']);
            $this->assertSame($artifactSources, $scenario['observed_outputs']['artifact_sources']);
            $this->assertFalse($scenario['observed_outputs']['local_product_source_checkouts_used']);
            $this->assertSame(
                'not_covered',
                $result['scenario_results']['latest_supported_v1_state_setup']['status'],
                'install evidence must not collapse the full migration-state contract into a passing result',
            );
        } finally {
            $this->removeTree($tempRoot);
        }
    }

    public function test_runner_routes_storage_smoke_only_runs_to_focused_contract_findings(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the migration runner storage-smoke-only gate.');
        }

        $artifactVersions = $this->artifactVersions();
        $artifactSources = $this->artifactSources();

        $result = $this->runRunnerEvidence(
            $nodeBinary,
            [],
            'dw-migration-storage-smoke-only-',
            [
                'DW_SERVER_V1_VERSION' => $artifactVersions['server-v1'],
                'DW_SERVER_V2_VERSION' => $artifactVersions['server-v2'],
                'DW_SERVER_V1_ARTIFACT_SOURCE' => $artifactSources['server-v1'],
                'DW_SERVER_V2_ARTIFACT_SOURCE' => $artifactSources['server-v2'],
                'DW_CLI_V1_VERSION' => $artifactVersions['cli-v1'],
                'DW_CLI_VERSION' => $artifactVersions['cli-v2'],
                'DW_CLI_V1_ARTIFACT_SOURCE' => $artifactSources['cli-v1'],
                'DW_CLI_ARTIFACT_SOURCE' => $artifactSources['cli-v2'],
                'DW_WORKFLOW_PHP_V1_VERSION' => $artifactVersions['workflow-php-v1'],
                'DW_WORKFLOW_PHP_V2_VERSION' => $artifactVersions['workflow-php-v2'],
                'DW_WORKFLOW_PHP_V1_ARTIFACT_SOURCE' => $artifactSources['workflow-php-v1'],
                'DW_WORKFLOW_PHP_V2_ARTIFACT_SOURCE' => $artifactSources['workflow-php-v2'],
                'DW_PYTHON_SDK_VERSION' => $artifactVersions['sdk-python'],
                'DW_PYTHON_SDK_ARTIFACT_SOURCE' => $artifactSources['sdk-python'],
                'DW_WATERLINE_V1_VERSION' => $artifactVersions['waterline-v1'],
                'DW_WATERLINE_VERSION' => $artifactVersions['waterline-v2'],
                'DW_WATERLINE_V1_ARTIFACT_SOURCE' => $artifactSources['waterline-v1'],
                'DW_WATERLINE_ARTIFACT_SOURCE' => $artifactSources['waterline-v2'],
                'DW_SAMPLE_APP_V1_VERSION' => $artifactVersions['sample-app-v1'],
                'DW_SAMPLE_APP_V1_ARTIFACT_SOURCE' => $artifactSources['sample-app-v1'],
            ],
            [
                'status' => 'pass',
                'storage_connection' => 'workflow_storage',
            ],
        );

        $this->assertSame('non_passing', $result['outcome']);
        $this->assertSame('pass', $result['scenario_results']['published_artifact_install_only']['status']);
        $this->assertSame('fail', $result['scenario_results']['latest_supported_v1_state_setup']['status']);
        $this->assertSame('fail', $result['scenario_results']['documented_migration_steps_execute']['status']);
        $this->assertSame('fail', $result['scenario_results']['waterline_operator_visibility_preserved']['status']);
        $this->assertSame('fail', $result['scenario_results']['cli_access_to_preupgrade_state']['status']);
        $this->assertSame('fail', $result['scenario_results']['version_skew_refusal']['status']);
        $this->assertSame('fail', $result['migration_plan']['status']);
        $this->assertSame(true, $result['migration_plan']['storage_connection_smoke_only']);
        $this->assertArrayNotHasKey(
            'run_record',
            $result['finding_links'],
            'storage-smoke-only runs should carry failed run-record observations instead of generic missing-record findings',
        );

        $this->assertSame(
            'migration_v1_state_setup_failure',
            $result['scenario_results']['latest_supported_v1_state_setup']['linked_findings'][0]['finding_type'],
        );
        $this->assertSame(
            'missing_or_wrong_migration_guide_step',
            $result['scenario_results']['documented_migration_steps_execute']['linked_findings'][0]['finding_type'],
        );
        $this->assertSame(
            'waterline_visibility_break',
            $result['scenario_results']['waterline_operator_visibility_preserved']['linked_findings'][0]['finding_type'],
        );
        $this->assertSame(
            'cli_regression',
            $result['scenario_results']['cli_access_to_preupgrade_state']['linked_findings'][0]['finding_type'],
        );
        $this->assertSame(
            'skew_silence',
            $result['scenario_results']['version_skew_refusal']['linked_findings'][0]['finding_type'],
        );
    }

    public function test_runner_downgrades_shallow_rollback_and_skew_pass_evidence(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the migration runner rollback and skew evidence gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        $evidence['scenario_results']['rollback_contract_verified']['observed_outputs'] = [
            'rollback_steps' => ['php artisan queue:restart'],
            'rollback_supported_state' => ['documented_behavior_verified' => true],
            'postrollback_visibility' => ['status' => 'checked'],
            'postrollback_execution_result' => ['status' => 'checked'],
        ];
        $evidence['scenario_results']['version_skew_refusal']['observed_outputs'] = [
            'skew_matrix' => [
                'cli-v1-to-server-v2' => ['server' => 'server-v2', 'client' => 'cli-v1'],
            ],
            'refusal_errors' => 'refused loudly',
            'operator_visible_reason' => 'version mismatch',
            'no_partial_mutation_evidence' => true,
        ];

        $result = $this->runRunnerEvidence($nodeBinary, $evidence, 'dw-migration-shallow-rollback-skew-');

        $this->assertSame('non_passing', $result['outcome']);
        $this->assertSame('not_covered', $result['scenario_results']['rollback_contract_verified']['status']);
        $this->assertContains(
            'public_operator_signal',
            $result['scenario_results']['rollback_contract_verified']['observed_outputs']['missing_required_fields'],
        );
        $this->assertContains(
            'rollback_supported_state.supported_refused_or_irreversible',
            $result['scenario_results']['rollback_contract_verified']['observed_outputs']['missing_required_fields'],
        );

        $this->assertSame('not_covered', $result['scenario_results']['version_skew_refusal']['status']);
        foreach ([
            'cli_skew_observations.cli-v1-to-server-v2',
            'cli_skew_observations.cli-v2-to-server-v1',
            'worker_skew_observations.worker-v1-to-server-v2',
            'worker_skew_observations.worker-v2-to-server-v1',
            'request_response_evidence.cli-v1-to-server-v2',
            'request_response_evidence.worker-v2-to-server-v1',
            'no_partial_mutation_evidence',
        ] as $field) {
            $this->assertContains(
                $field,
                $result['scenario_results']['version_skew_refusal']['observed_outputs']['missing_required_fields'],
            );
        }
        $this->assertSame(
            'conformance_runner_coverage_gap',
            $result['scenario_results']['version_skew_refusal']['linked_findings'][0]['finding_type'],
        );
    }

    public function test_runner_audits_public_guide_when_storage_smoke_is_the_only_product_evidence(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the migration runner guide-audit shard.');
        }

        $artifactVersions = $this->artifactVersions();
        $artifactSources = $this->artifactSources();
        $guideText = <<<'GUIDE'
Migrating to 2.0

composer require durable-workflow/workflow:2.0.0-alpha.197
php artisan migrate
php artisan queue:restart
php artisan workflow:v1:list

Open Waterline and verify both v1 and v2 workflows are visible.

Rollback procedure:
php artisan queue:restart
mysql -u root -p your_database < backup-v1.sql
composer require laravel-workflow/laravel-workflow:^1.0

The finish-on-v1 strategy avoids forcing a data migration at upgrade time.
v1 workflows continue executing on the v1 engine until they complete.
GUIDE;

        $result = $this->runRunnerEvidence(
            $nodeBinary,
            [],
            'dw-migration-guide-audit-',
            [
                'DW_MIGRATION_RUN_PUBLIC_GUIDE_AUDIT' => '1',
                'DW_MIGRATION_GUIDE_AUDIT_TEXT' => $guideText,
                'DW_SERVER_V1_VERSION' => $artifactVersions['server-v1'],
                'DW_SERVER_V2_VERSION' => $artifactVersions['server-v2'],
                'DW_SERVER_V1_ARTIFACT_SOURCE' => $artifactSources['server-v1'],
                'DW_SERVER_V2_ARTIFACT_SOURCE' => $artifactSources['server-v2'],
                'DW_CLI_V1_VERSION' => $artifactVersions['cli-v1'],
                'DW_CLI_VERSION' => $artifactVersions['cli-v2'],
                'DW_CLI_V1_ARTIFACT_SOURCE' => $artifactSources['cli-v1'],
                'DW_CLI_ARTIFACT_SOURCE' => $artifactSources['cli-v2'],
                'DW_WORKFLOW_PHP_V1_VERSION' => $artifactVersions['workflow-php-v1'],
                'DW_WORKFLOW_PHP_V2_VERSION' => $artifactVersions['workflow-php-v2'],
                'DW_WORKFLOW_PHP_V1_ARTIFACT_SOURCE' => $artifactSources['workflow-php-v1'],
                'DW_WORKFLOW_PHP_V2_ARTIFACT_SOURCE' => $artifactSources['workflow-php-v2'],
                'DW_PYTHON_SDK_VERSION' => $artifactVersions['sdk-python'],
                'DW_PYTHON_SDK_ARTIFACT_SOURCE' => $artifactSources['sdk-python'],
                'DW_WATERLINE_V1_VERSION' => $artifactVersions['waterline-v1'],
                'DW_WATERLINE_VERSION' => $artifactVersions['waterline-v2'],
                'DW_WATERLINE_V1_ARTIFACT_SOURCE' => $artifactSources['waterline-v1'],
                'DW_WATERLINE_ARTIFACT_SOURCE' => $artifactSources['waterline-v2'],
                'DW_SAMPLE_APP_V1_VERSION' => $artifactVersions['sample-app-v1'],
                'DW_SAMPLE_APP_V1_ARTIFACT_SOURCE' => $artifactSources['sample-app-v1'],
            ],
            [
                'status' => 'pass',
                'storage_connection' => 'workflow_storage',
            ],
        );

        $this->assertSame('non_passing', $result['outcome']);
        $this->assertSame('public_migration_guide_audit', $result['migration_plan']['source']);
        $this->assertTrue($result['migration_plan']['guide_audit_only']);
        $this->assertTrue($result['migration_plan']['guide_signals']['finish_on_v1_strategy']);
        $this->assertTrue($result['migration_plan']['guide_signals']['rollback_procedure']);
        $this->assertContains('php artisan migrate', $result['migration_plan']['commands_extracted']);
        $this->assertSame(
            'not_covered',
            $result['scenario_results']['documented_migration_steps_execute']['status'],
        );
        $this->assertSame(
            'public_migration_guide_audit',
            $result['scenario_results']['documented_migration_steps_execute']['observed_outputs']['source'],
        );
        $this->assertStringContainsString(
            'public migration guide was audited',
            $result['scenario_results']['documented_migration_steps_execute']['linked_findings'][0]['observed_behavior'],
        );
        $this->assertSame(
            'conformance_runner_coverage_gap',
            $result['scenario_results']['completed_history_preservation_and_replay']['linked_findings'][0]['finding_type'],
        );
        $rollbackOutputs = $result['scenario_results']['rollback_contract_verified']['observed_outputs'];
        $this->assertSame('documented_but_not_executed', $rollbackOutputs['rollback_supported_state']);
        $this->assertSame(
            'documented_but_not_executed',
            $rollbackOutputs['public_operator_signal']['status'],
        );
        $skewOutputs = $result['scenario_results']['version_skew_refusal']['observed_outputs'];
        $this->assertArrayHasKey('cli-v1-to-server-v2', $skewOutputs['cli_skew_observations']);
        $this->assertArrayHasKey('cli-v2-to-server-v1', $skewOutputs['cli_skew_observations']);
        $this->assertArrayHasKey('worker-v1-to-server-v2', $skewOutputs['worker_skew_observations']);
        $this->assertArrayHasKey('worker-v2-to-server-v1', $skewOutputs['worker_skew_observations']);
        $this->assertArrayHasKey('cli-v1-to-server-v2', $skewOutputs['request_response_evidence']);
        $this->assertArrayHasKey('worker-v2-to-server-v1', $skewOutputs['request_response_evidence']);
        $this->assertArrayNotHasKey(
            'run_record',
            $result['finding_links'],
            'guide-audit observations should fill the required run-record sections while the live migration cells remain non-passing',
        );
    }

    public function test_runner_extracts_commands_from_live_style_html_guide_blocks(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the migration runner guide-audit shard.');
        }

        $artifactVersions = $this->artifactVersions();
        $artifactSources = $this->artifactSources();
        $guideHtml = <<<'HTML'
<!doctype html>
<html>
<body>
<article>
<h2>Upgrade steps</h2>
<p>Composer prerelease stability suffix for the active pre-stable 2.0 package.</p>
<div class="language-bash codeBlockContainer_x">
<pre tabindex="0" class="prism-code language-bash codeBlock_x"><code>
<span class="token-line"><span class="token plain">composer require durable-workflow/workflow:2.0.0-alpha.197</span></span>
<span class="token-line"><span class="token plain">php artisan migrate</span></span>
<span class="token-line"><span class="token plain">php artisan queue:restart</span></span>
</code></pre>
</div>
<p>Open Waterline and verify both v1 and v2 workflows are visible.</p>
<div class="language-bash codeBlockContainer_y">
<pre tabindex="0" class="prism-code language-bash codeBlock_y"><code class="codeBlockLines_e6Vv">
<span class="token-line" style="color:#F8F8F2">
  <span class="token plain">php artisan vendor:publish </span><span class="token punctuation">\</span><span class="token plain"></span><br>
</span>
<span class="token-line" style="color:#F8F8F2">
  <span class="token plain">  --provider</span><span class="token operator">=</span><span class="token string">&quot;Workflow\Providers\WorkflowServiceProvider&quot;</span><span class="token plain"> </span><span class="token punctuation">\</span><span class="token plain"></span><br>
</span>
<span class="token-line" style="color:#F8F8F2">
  <span class="token plain">  --tag</span><span class="token operator">=</span><span class="token plain">migrations </span><span class="token punctuation">\</span><span class="token plain"></span><br>
</span>
<span class="token-line" style="color:#F8F8F2">
  <span class="token plain">  --force</span><br>
</span>
<span class="token-line" style="color:#F8F8F2">
  <span class="token plain" style="display:inline-block"></span><br>
</span>
<span class="token-line" style="color:#F8F8F2">
  <span class="token plain">php artisan migrate</span><br>
</span>
</code></pre>
</div>
<h2>Rollback procedure</h2>
<pre><code class="language-bash">
<span class="token-line">mysql -u root -p your_database &lt; backup-v1.sql</span>
<span class="token-line">composer require laravel-workflow/laravel-workflow:^1.0</span>
</code></pre>
<p>The finish-on-v1 strategy avoids forcing a data migration at upgrade time. v1 workflows continue executing on the v1 engine until they complete.</p>
</article>
</body>
</html>
HTML;

        $result = $this->runRunnerEvidence(
            $nodeBinary,
            [],
            'dw-migration-guide-html-audit-',
            array_merge(
                $this->publicGuideAuditArtifactEnvironment($artifactVersions, $artifactSources),
                [
                    'DW_MIGRATION_RUN_PUBLIC_GUIDE_AUDIT' => '1',
                    'DW_MIGRATION_GUIDE_AUDIT_TEXT' => $guideHtml,
                ],
            ),
            [
                'status' => 'pass',
                'storage_connection' => 'workflow_storage',
            ],
        );

        $commands = $result['migration_plan']['commands_extracted'];
        $this->assertContains('composer require durable-workflow/workflow:2.0.0-alpha.197', $commands);
        $this->assertContains('php artisan migrate', $commands);
        $this->assertContains('php artisan queue:restart', $commands);
        $this->assertContains('mysql -u root -p your_database < backup-v1.sql', $commands);
        $this->assertContains('composer require laravel-workflow/laravel-workflow:^1.0', $commands);
        $this->assertFalse(
            in_array('Composer prerelease stability suffix for the active pre-stable 2.0 package.', $commands, true),
            'guide prose must not be recorded as command evidence',
        );

        $vendorPublish = array_values(array_filter(
            $commands,
            static fn (string $command): bool => str_contains($command, 'php artisan vendor:publish'),
        ));
        $expectedVendorPublish = <<<'COMMAND'
php artisan vendor:publish \
  --provider="Workflow\Providers\WorkflowServiceProvider" \
  --tag=migrations \
  --force
COMMAND;

        $this->assertNotEmpty($vendorPublish);
        $this->assertContains($expectedVendorPublish, $commands);
        $this->assertStringNotContainsString("\n\n", $vendorPublish[0]);
        $this->assertStringContainsString('--tag=migrations', $vendorPublish[0]);
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
                    'DW_MIGRATION_RESOLVE_PUBLIC_ARTIFACTS' => '0',
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

    public function test_runner_downgrades_supplied_pass_scenario_with_missing_required_fields(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the migration runner scenario evidence gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        unset($evidence['scenario_results']['latest_supported_v1_state_setup']['observed_outputs']['seeded_workflows']);
        $evidence['scenario_results']['latest_supported_v1_state_setup']['observed_outputs']['seeded_schedules'] = [
            'status' => 'not_covered',
            'observed_behavior' => 'placeholder coverage row',
        ];

        $result = $this->runRunnerEvidence($nodeBinary, $evidence, 'dw-migration-missing-scenario-fields-');
        $scenario = $result['scenario_results']['latest_supported_v1_state_setup'];

        $this->assertSame('non_passing', $result['outcome']);
        $this->assertSame('not_covered', $scenario['status']);
        $this->assertContains('seeded_workflows', $scenario['observed_outputs']['missing_required_fields']);
        $this->assertContains('seeded_schedules', $scenario['observed_outputs']['missing_required_fields']);
        $this->assertContains(
            'conformance_runner_coverage_gap',
            array_column($scenario['linked_findings'], 'finding_type'),
        );
        $this->assertArrayHasKey('latest_supported_v1_state_setup', $result['finding_links']);
    }

    public function test_runner_downgrades_supplied_pass_scenario_without_realistic_v1_state_cells(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the migration runner realistic-state gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        $evidence['scenario_results']['latest_supported_v1_state_setup']['observed_outputs']['seeded_workflows'] =
            'seeded_workflows-observed';
        $evidence['scenario_results']['latest_supported_v1_state_setup']['observed_outputs']['queryable_history'] =
            'queryable_history-observed';

        $result = $this->runRunnerEvidence($nodeBinary, $evidence, 'dw-migration-shallow-state-');
        $scenario = $result['scenario_results']['latest_supported_v1_state_setup'];

        $this->assertSame('non_passing', $result['outcome']);
        $this->assertSame('not_covered', $scenario['status']);
        foreach ([
            'seeded_workflows.completed_workflow',
            'seeded_workflows.running_workflow_waiting_on_signal',
            'seeded_workflows.workflow_with_activity',
            'seeded_workflows.workflow_mid_activity_retry',
            'queryable_history.queryable_history',
        ] as $field) {
            $this->assertContains($field, $scenario['observed_outputs']['missing_required_fields']);
        }
    }

    public function test_runner_keeps_expected_state_kind_snapshots_non_passing(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the migration runner state snapshot gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        $expectedStateKinds = MigrationRuntimeContract::manifest()['required_matrix']['state_kinds'];
        $evidence['preupgrade_state_snapshot'] = [
            'status' => 'pass',
            'expected_state_kinds' => $expectedStateKinds,
            'observed_behavior' => 'runner listed the expected state matrix without observed v1 state evidence',
        ];
        $evidence['postupgrade_state_snapshot'] = [
            'status' => 'pass',
            'expected_state_kinds' => $expectedStateKinds,
            'observed_behavior' => 'runner listed the expected state matrix without observed v2 state evidence',
        ];

        $result = $this->runRunnerEvidence($nodeBinary, $evidence, 'dw-migration-expected-state-kinds-');

        $this->assertSame(
            'non_passing',
            $result['outcome'],
            'expected_state_kinds alone must not allow the migration runner to emit pass',
        );
        $this->assertSame($expectedStateKinds, $result['preupgrade_state_snapshot']['expected_state_kinds']);
        $this->assertSame($expectedStateKinds, $result['postupgrade_state_snapshot']['expected_state_kinds']);
    }

    public function test_runner_keeps_declared_state_kind_snapshots_non_passing(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the migration runner state snapshot gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        $stateKinds = MigrationRuntimeContract::manifest()['required_matrix']['state_kinds'];
        $evidence['preupgrade_state_snapshot'] = [
            'status' => 'pass',
            'state_kinds' => $stateKinds,
            'workflow_ids' => ['migration-completed', 'migration-awaiting-signal'],
        ];
        $evidence['postupgrade_state_snapshot'] = [
            'status' => 'pass',
            'state_kinds' => $stateKinds,
            'workflow_ids' => ['migration-completed', 'migration-awaiting-signal'],
        ];

        $result = $this->runRunnerEvidence($nodeBinary, $evidence, 'dw-migration-declared-state-kinds-');
        $runRecordFindings = $result['finding_links']['run_record'] ?? [];

        $this->assertSame(
            'non_passing',
            $result['outcome'],
            'state_kinds alone must not allow the migration runner to emit pass',
        );
        foreach (['preupgrade_state_snapshot', 'postupgrade_state_snapshot'] as $field) {
            $this->assertNotEmpty(array_filter(
                $runRecordFindings,
                static fn (array $finding): bool => ($finding['missing_run_record_field'] ?? null) === $field
                    && ($finding['missing_state_kind'] ?? null) === 'completed_history',
            ));
        }
    }

    public function test_runner_records_run_record_findings_for_missing_top_level_sections(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the migration runner run-record evidence gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        unset($evidence['migration_plan']);
        $evidence['rollback_observations'] = [
            'status' => 'not_covered',
            'observed_behavior' => 'rollback was not exercised',
        ];

        $result = $this->runRunnerEvidence($nodeBinary, $evidence, 'dw-migration-missing-run-record-');
        $runRecordFindings = $result['finding_links']['run_record'] ?? [];
        $missingFields = array_column($runRecordFindings, 'missing_run_record_field');

        $this->assertSame('non_passing', $result['outcome']);
        $this->assertSame('not_covered', $result['migration_plan']['status']);
        $this->assertContains('migration_plan', $missingFields);
        $this->assertContains('rollback_observations', $missingFields);
        $this->assertContains(
            'conformance_runner_coverage_gap',
            array_column($runRecordFindings, 'finding_type'),
        );
    }

    public function test_runner_routes_supplied_failed_cells_to_product_findings(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the migration runner failure finding routing.');
        }

        $evidence = $this->completeRunnerEvidence();
        $evidence['scenario_results']['waterline_operator_visibility_preserved'] = [
            'status' => 'fail',
            'observed_outputs' => [
                'failure_reason' => 'preupgrade run detail was not visible after migration',
                'preupgrade_waterline_snapshot' => 'captured',
                'postupgrade_waterline_snapshot' => 'missing run detail',
                'run_detail_visibility' => 'missing',
                'history_visibility' => 'present',
            ],
        ];

        $result = $this->runRunnerEvidence($nodeBinary, $evidence, 'dw-migration-product-finding-routing-');
        $scenario = $result['scenario_results']['waterline_operator_visibility_preserved'];
        $finding = $scenario['linked_findings'][0] ?? [];

        $this->assertSame('non_passing', $result['outcome']);
        $this->assertSame('fail', $scenario['status']);
        $this->assertSame('waterline', $finding['owning_surface']);
        $this->assertSame('waterline_visibility_break', $finding['finding_type']);
        $this->assertSame(
            'preupgrade run detail was not visible after migration',
            $finding['observed_behavior'],
        );
        $this->assertArrayHasKey('waterline_operator_visibility_preserved', $result['finding_links']);
    }

    public function test_runner_uses_normalized_env_and_file_backed_run_record_fields_before_passing(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the migration runner normalized run-record gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        unset(
            $evidence['published_artifact_versions'],
            $evidence['resolved_artifact_versions'],
            $evidence['artifact_sources'],
            $evidence['storage_connection_smoke'],
        );

        $artifactVersions = $this->artifactVersions();
        $artifactSources = $this->artifactSources();
        $result = $this->runRunnerEvidence(
            $nodeBinary,
            $evidence,
            'dw-migration-normalized-run-record-',
            [
                'DW_SERVER_V1_VERSION' => $artifactVersions['server-v1'],
                'DW_SERVER_V2_VERSION' => $artifactVersions['server-v2'],
                'DW_SERVER_V1_ARTIFACT_SOURCE' => $artifactSources['server-v1'],
                'DW_SERVER_V2_ARTIFACT_SOURCE' => $artifactSources['server-v2'],
                'DW_CLI_V1_VERSION' => $artifactVersions['cli-v1'],
                'DW_CLI_VERSION' => $artifactVersions['cli-v2'],
                'DW_CLI_V1_ARTIFACT_SOURCE' => $artifactSources['cli-v1'],
                'DW_CLI_ARTIFACT_SOURCE' => $artifactSources['cli-v2'],
                'DW_WORKFLOW_PHP_V1_VERSION' => $artifactVersions['workflow-php-v1'],
                'DW_WORKFLOW_PHP_V2_VERSION' => $artifactVersions['workflow-php-v2'],
                'DW_WORKFLOW_PHP_V1_ARTIFACT_SOURCE' => $artifactSources['workflow-php-v1'],
                'DW_WORKFLOW_PHP_V2_ARTIFACT_SOURCE' => $artifactSources['workflow-php-v2'],
                'DW_PYTHON_SDK_VERSION' => $artifactVersions['sdk-python'],
                'DW_PYTHON_SDK_ARTIFACT_SOURCE' => $artifactSources['sdk-python'],
                'DW_WATERLINE_V1_VERSION' => $artifactVersions['waterline-v1'],
                'DW_WATERLINE_VERSION' => $artifactVersions['waterline-v2'],
                'DW_WATERLINE_V1_ARTIFACT_SOURCE' => $artifactSources['waterline-v1'],
                'DW_WATERLINE_ARTIFACT_SOURCE' => $artifactSources['waterline-v2'],
                'DW_SAMPLE_APP_V1_VERSION' => $artifactVersions['sample-app-v1'],
                'DW_SAMPLE_APP_V1_ARTIFACT_SOURCE' => $artifactSources['sample-app-v1'],
            ],
            [
                'passed' => true,
                'source' => 'DW_MIGRATION_STORAGE_SMOKE_JSON',
            ],
        );

        $this->assertSame('pass', $result['outcome']);
        $this->assertSame($artifactVersions, $result['published_artifact_versions']);
        $this->assertSame($artifactVersions, $result['resolved_artifact_versions']);
        $this->assertSame($artifactSources, $result['artifact_sources']);
        $this->assertSame('DW_MIGRATION_STORAGE_SMOKE_JSON', $result['storage_connection_smoke']['source']);
        $this->assertArrayNotHasKey(
            'run_record',
            $result['finding_links'],
            'normalized env and file-backed inputs must satisfy run-record fields before pass evaluation',
        );
    }

    public function test_runner_merges_host_evidence_shards_before_passing(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the migration runner evidence shard merge.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $tempRoot = sys_get_temp_dir().'/dw-migration-shards-'.bin2hex(random_bytes(6));
        $resultDir = $tempRoot.'/result';
        $evidenceDir = $tempRoot.'/migration-evidence.d';
        $evidencePath = $tempRoot.'/migration-evidence.json';
        $evidence = $this->completeRunnerEvidence();
        $scenarioResults = $evidence['scenario_results'];
        $singleScenario = $scenarioResults['latest_supported_v1_state_setup'];
        $singleScenario['scenario_id'] = 'latest_supported_v1_state_setup';
        unset($scenarioResults['latest_supported_v1_state_setup']);

        $baseEvidence = $evidence;
        unset($baseEvidence['scenario_results'], $baseEvidence['history_dumps']);

        try {
            mkdir($resultDir, 0777, true);
            mkdir($evidenceDir, 0777, true);
            file_put_contents(
                $evidencePath,
                json_encode($baseEvidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n",
            );
            file_put_contents(
                $evidenceDir.'/010-scenarios.json',
                json_encode(['scenario_results' => $scenarioResults], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n",
            );
            file_put_contents(
                $evidenceDir.'/020-latest-supported-v1-state.json',
                json_encode($singleScenario, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n",
            );
            file_put_contents(
                $evidenceDir.'/030-run-record.json',
                json_encode(['history_dumps' => $evidence['history_dumps']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n",
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
                    'DW_MIGRATION_EVIDENCE_DIR' => $evidenceDir,
                    'DW_MIGRATION_RESOLVE_PUBLIC_ARTIFACTS' => '0',
                ],
            );

            $this->assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            $this->assertSame(0, $exitCode, ($stdout === false ? '' : $stdout).($stderr === false ? '' : $stderr));

            $result = json_decode(
                (string) file_get_contents($resultDir.'/migration-conformance-result.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $record = json_decode(
                (string) file_get_contents($resultDir.'/migration-conformance-record.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $this->assertSame('pass', $result['outcome']);
            $this->assertSame(
                'pass',
                $result['scenario_results']['latest_supported_v1_state_setup']['status'],
            );
            $this->assertSame($evidence['history_dumps'], $result['history_dumps']);
            $this->assertSame($evidence['artifact_sources'], $record['artifact_sources']);
            $this->assertSame('migration', $record['experiment']);
            $this->assertFalse($record['runnerBlocked']);
            $this->assertSame($evidence['resolved_artifact_versions'], $record['artifactVersions']);
            $this->assertSame($evidence['published_artifact_versions'], $record['publishedArtifactVersions']);
            $this->assertSame($evidence['resolved_artifact_versions'], $record['resolvedArtifactVersions']);
            $this->assertSame($evidence['artifact_sources'], $record['artifactSources']);
            $this->assertFalse($record['localProductSourceCheckoutsUsed']);
            $this->assertSame($record['scenario_statuses'], $record['scenarioStatuses']);
            $this->assertSame($record['non_pass_scenarios'], $record['nonPassScenarios']);
            $this->assertSame($record['finding_links'], $record['findingLinks']);
            $this->assertSame($resultDir.'/migration-conformance-result.json', $record['resultPath']);
            $this->assertSame($resultDir.'/migration-published-artifacts.json', $record['artifactPath']);
            $this->assertSame($evidence['migration_plan'], $record['migration_plan']);
            $this->assertSame($evidence['preupgrade_state_snapshot'], $record['preupgrade_state_snapshot']);
            $this->assertSame($evidence['rollback_observations'], $record['rollback_observations']);
            $this->assertSame($evidence['version_skew_observations'], $record['version_skew_observations']);
            $this->assertSame(
                'pass',
                $record['scenario_statuses']['latest_supported_v1_state_setup'],
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
        $cliV2Version = $evidence['published_artifact_versions']['cli-v2'];
        $waterlineV2Version = $evidence['published_artifact_versions']['waterline-v2'];
        $workflowV1Source = $evidence['artifact_sources']['workflow-php-v1'];
        $workflowV2Source = $evidence['artifact_sources']['workflow-php-v2'];
        $cliV2Source = $evidence['artifact_sources']['cli-v2'];
        $waterlineV2Source = $evidence['artifact_sources']['waterline-v2'];

        foreach (['published_artifact_versions', 'resolved_artifact_versions', 'artifact_sources'] as $field) {
            unset(
                $evidence[$field]['cli-v2'],
                $evidence[$field]['workflow-php-v1'],
                $evidence[$field]['workflow-php-v2'],
                $evidence[$field]['waterline-v2'],
            );
        }

        $evidence['published_artifact_versions']['cli'] = $cliV2Version;
        $evidence['published_artifact_versions']['workflow-v1'] = $workflowV1Version;
        $evidence['published_artifact_versions']['workflow'] = $workflowV2Version;
        $evidence['published_artifact_versions']['waterline'] = $waterlineV2Version;
        $evidence['resolved_artifact_versions']['cli'] = $cliV2Version;
        $evidence['resolved_artifact_versions']['workflow-v1'] = $workflowV1Version;
        $evidence['resolved_artifact_versions']['workflow-php'] = $workflowV2Version;
        $evidence['resolved_artifact_versions']['waterline'] = $waterlineV2Version;
        $evidence['artifact_sources']['cli'] = $cliV2Source;
        $evidence['artifact_sources']['workflow-v1'] = $workflowV1Source;
        $evidence['artifact_sources']['workflow-php'] = $workflowV2Source;
        $evidence['artifact_sources']['waterline'] = $waterlineV2Source;

        $evidence['scenario_results']['published_artifact_install_only']['observed_outputs']['resolved_artifact_versions'] =
            $evidence['resolved_artifact_versions'];
        $evidence['scenario_results']['published_artifact_install_only']['observed_outputs']['artifact_sources'] =
            $evidence['artifact_sources'];

        $result = $this->runRunnerEvidence($nodeBinary, $evidence, 'dw-migration-aliases-');

        $this->assertSame('pass', $result['outcome']);
        $this->assertSame($cliV2Version, $result['resolved_artifact_versions']['cli-v2']);
        $this->assertSame($workflowV1Version, $result['resolved_artifact_versions']['workflow-php-v1']);
        $this->assertSame($workflowV2Version, $result['resolved_artifact_versions']['workflow-php-v2']);
        $this->assertSame($waterlineV2Version, $result['resolved_artifact_versions']['waterline-v2']);
        $this->assertSame($cliV2Source, $result['artifact_sources']['cli-v2']);
        $this->assertSame($workflowV2Source, $result['artifact_sources']['workflow-php-v2']);
        $this->assertSame($waterlineV2Source, $result['artifact_sources']['waterline-v2']);
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
                    'DW_MIGRATION_RESOLVE_PUBLIC_ARTIFACTS' => '0',
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
    private function runRunnerEvidence(
        string $nodeBinary,
        array $evidence,
        string $tempPrefix,
        array $environment = [],
        ?array $storageSmoke = null,
        ?array $publicArtifacts = null,
    ): array {
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

            if ($storageSmoke !== null) {
                $storageSmokePath = $tempRoot.'/storage-smoke.json';
                file_put_contents(
                    $storageSmokePath,
                    json_encode($storageSmoke, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n",
                );
                $environment['DW_MIGRATION_STORAGE_SMOKE_JSON'] = $storageSmokePath;
            }

            if ($publicArtifacts !== null) {
                $publicArtifactsPath = $tempRoot.'/public-artifacts.json';
                file_put_contents(
                    $publicArtifactsPath,
                    json_encode($publicArtifacts, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n",
                );
                $environment['DW_MIGRATION_PUBLIC_ARTIFACTS_JSON'] = $publicArtifactsPath;
            }

            $baseEnvironment = [
                'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
                'DW_MIGRATION_REPO_ROOT' => $repoRoot,
                'DW_MIGRATION_RESULT_DIR' => $resultDir,
                'DW_MIGRATION_EVIDENCE_JSON' => $evidencePath,
                'DW_MIGRATION_RESOLVE_PUBLIC_ARTIFACTS' => '0',
                'DW_MIGRATION_RUN_PUBLIC_GUIDE_AUDIT' => '0',
            ];

            $process = proc_open(
                [$nodeBinary, $repoRoot.'/scripts/conformance/migration-published-artifacts.mjs'],
                [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                $repoRoot,
                array_merge($baseEnvironment, $environment),
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

        $scenarioResults['latest_supported_v1_state_setup']['observed_outputs'] = [
            'source_release_versions' => $this->artifactVersions(),
            'seeded_workflows' => [
                'completed_workflow' => [
                    'workflow_id' => 'migration-completed',
                    'status' => 'completed',
                    'history_event_count' => 8,
                ],
                'running_workflow_waiting_on_signal' => [
                    'workflow_id' => 'migration-awaiting-signal',
                    'status' => 'running',
                    'signal_name' => 'approve',
                ],
                'workflow_with_activity' => [
                    'workflow_id' => 'migration-activity',
                    'activity_type' => 'migration_sample_activity',
                    'activity_completed' => true,
                ],
                'workflow_mid_activity_retry' => [
                    'workflow_id' => 'migration-retrying-activity',
                    'attempt' => 2,
                    'next_retry_at' => '2026-05-31T22:42:00Z',
                ],
            ],
            'seeded_schedules' => [
                'active_schedule' => [
                    'schedule_id' => 'migration-cross-upgrade-schedule',
                    'next_fire_at' => '2026-05-31T22:45:00Z',
                ],
            ],
            'seeded_worker_registrations' => [
                'registered_workers' => [
                    [
                        'worker_id' => 'migration-v1-worker',
                        'task_queue' => 'migration-v1',
                    ],
                ],
            ],
            'queryable_history' => [
                'queryable_history' => [
                    'workflow_ids' => [
                        'migration-completed',
                        'migration-awaiting-signal',
                    ],
                    'history_exported' => true,
                ],
            ],
        ];
        $scenarioResults['documented_migration_steps_execute']['observed_outputs'] = [
            'migration_guide_revision' => [
                'url' => 'https://durable-workflow.github.io/docs/2.0/migration/',
                'sha256' => 'migration-guide-sha',
            ],
            'commands_executed' => [
                'composer require durable-workflow/workflow:2.0.0-alpha.185',
                'php artisan migrate',
                'php artisan queue:restart',
            ],
            'exit_codes' => [0, 0, 0],
            'command_timings' => [
                'composer require durable-workflow/workflow:2.0.0-alpha.185' => 1280,
                'php artisan migrate' => 430,
                'php artisan queue:restart' => 95,
            ],
            'schema_or_storage_migration_output' => [
                'migrations_ran' => true,
                'workflow_storage_tables_created' => true,
            ],
        ];
        $scenarioResults['rollback_contract_verified']['observed_outputs'] = [
            'rollback_steps' => [
                'php artisan down',
                'mysql app < backup-before-v2.sql',
                'composer require laravel-workflow/laravel-workflow:1.7.4 laravel-workflow/waterline:1.4.2',
                'php artisan queue:restart',
            ],
            'rollback_supported_state' => [
                'classification' => 'refused',
                'state_after_v2_writes' => 'irreversible without restoring the pre-upgrade database backup',
            ],
            'public_operator_signal' => [
                'source' => 'https://durable-workflow.github.io/docs/2.0/migration/',
                'message' => 'Rollback after v2 writes is refused unless the operator restores the pre-upgrade database backup first.',
            ],
            'postrollback_visibility' => [
                'workflow_describe_exit_code' => 2,
                'stderr' => 'Refusing rollback without a pre-upgrade database restore.',
            ],
            'postrollback_execution_result' => [
                'status' => 'refused',
                'exit_code' => 2,
                'operator_visible_reason' => 'pre-upgrade backup restore required before v1 workers are restarted',
            ],
        ];
        $scenarioResults['version_skew_refusal']['observed_outputs'] = [
            'skew_matrix' => [
                'cli-v1-to-server-v2' => ['server' => 'server-v2', 'client' => 'cli-v1'],
                'cli-v2-to-server-v1' => ['server' => 'server-v1', 'client' => 'cli-v2'],
                'worker-v1-to-server-v2' => ['server' => 'server-v2', 'worker' => 'workflow-php-v1'],
                'worker-v2-to-server-v1' => ['server' => 'server-v1', 'worker' => 'workflow-php-v2'],
            ],
            'cli_skew_observations' => [
                'cli-v1-to-server-v2' => [
                    'command' => 'dw workflow:list --server http://server-v2',
                    'exit_code' => 2,
                    'stderr' => 'Unsupported server generation for this CLI.',
                ],
                'cli-v2-to-server-v1' => [
                    'command' => 'dw workflow:list --server http://server-v1',
                    'exit_code' => 2,
                    'stderr' => 'Server API is older than the CLI compatibility window.',
                ],
            ],
            'worker_skew_observations' => [
                'worker-v1-to-server-v2' => [
                    'request' => 'POST /api/worker/register',
                    'status' => 409,
                    'body' => ['error' => 'worker_version_unsupported'],
                ],
                'worker-v2-to-server-v1' => [
                    'request' => 'POST /api/worker/register',
                    'status' => 409,
                    'body' => ['error' => 'server_version_unsupported'],
                ],
            ],
            'refusal_errors' => [
                'worker_version_unsupported',
                'server_version_unsupported',
                'cli_server_generation_mismatch',
            ],
            'operator_visible_reason' => [
                'message' => 'Upgrade the CLI or worker to the server generation before submitting workflow operations.',
            ],
            'request_response_evidence' => [
                'cli-v1-to-server-v2' => ['request' => 'dw workflow:list', 'response' => ['exit_code' => 2]],
                'cli-v2-to-server-v1' => ['request' => 'dw workflow:list', 'response' => ['exit_code' => 2]],
                'worker-v1-to-server-v2' => ['request' => 'POST /api/worker/register', 'response' => ['status' => 409]],
                'worker-v2-to-server-v1' => ['request' => 'POST /api/worker/register', 'response' => ['status' => 409]],
            ],
            'no_partial_mutation_evidence' => [
                'workflow_count_before' => 3,
                'workflow_count_after' => 3,
                'worker_registration_count_after' => 0,
            ],
        ];

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
            'migration_plan' => [
                'guide_revision' => 'docs/2.0/migration',
                'commands_executed' => $scenarioResults['documented_migration_steps_execute']['observed_outputs']['commands_executed'],
                'command_timings' => $scenarioResults['documented_migration_steps_execute']['observed_outputs']['command_timings'],
            ],
            'preupgrade_state_snapshot' => $this->stateSnapshotEvidence('preupgrade'),
            'postupgrade_state_snapshot' => $this->stateSnapshotEvidence('postupgrade'),
            'history_dumps' => ['completed' => true, 'running' => true],
            'activity_attempts' => ['retry_preserved' => true],
            'schedule_ticks' => ['cadence_preserved' => true],
            'worker_registration_observations' => ['projection_preserved' => true],
            'cli_observations' => ['preupgrade_state_readable' => true],
            'waterline_observations' => ['preupgrade_state_visible' => true],
            'rollback_observations' => $scenarioResults['rollback_contract_verified']['observed_outputs'],
            'version_skew_observations' => $scenarioResults['version_skew_refusal']['observed_outputs'],
            'storage_connection_smoke' => ['passed' => true],
            'scenario_results' => $scenarioResults,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stateSnapshotEvidence(string $phase): array
    {
        return [
            'state_kinds' => MigrationRuntimeContract::manifest()['required_matrix']['state_kinds'],
            'observed_states' => [
                [
                    'state_kind' => 'completed_history',
                    'phase' => $phase,
                    'workflow_id' => 'migration-completed',
                    'history_event_count' => 8,
                    'history_readable' => true,
                ],
                [
                    'state_kind' => 'in_flight_workflow',
                    'phase' => $phase,
                    'workflow_id' => 'migration-awaiting-signal',
                    'status' => $phase === 'preupgrade' ? 'running' : 'completed',
                    'signal_name' => 'approve',
                ],
                [
                    'state_kind' => 'retrying_activity',
                    'phase' => $phase,
                    'workflow_id' => 'migration-retrying-activity',
                    'activity_type' => 'migration_sample_activity',
                    'attempt' => $phase === 'preupgrade' ? 2 : 3,
                ],
                [
                    'state_kind' => 'schedule',
                    'phase' => $phase,
                    'schedule_id' => 'migration-cross-upgrade-schedule',
                    'next_fire_at' => '2026-05-31T22:45:00Z',
                ],
                [
                    'state_kind' => 'worker_registration',
                    'phase' => $phase,
                    'worker_id' => 'migration-v1-worker',
                    'task_queue' => 'migration-v1',
                ],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function artifactVersions(): array
    {
        return [
            'server-v1' => '1.0.76',
            'server-v2' => '0.2.203',
            'cli-v1' => '0.1.44',
            'cli-v2' => '0.1.70',
            'workflow-php-v1' => '1.0.76',
            'workflow-php-v2' => '2.0.0-alpha.185',
            'sdk-python' => '0.4.83',
            'waterline-v1' => '1.4.2',
            'waterline-v2' => '2.0.0-alpha.69',
            'sample-app-v1' => 'v1.12.0',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function artifactSources(): array
    {
        return [
            'server-v1' => 'packagist:laravel-workflow/laravel-workflow:1.0.76:embedded-v1-server-runtime',
            'server-v2' => 'published_docker_image',
            'cli-v1' => 'official_v1_install_script',
            'cli-v2' => 'official_install_script',
            'workflow-php-v1' => 'composer_release',
            'workflow-php-v2' => 'composer_release',
            'sdk-python' => 'pypi_release',
            'waterline-v1' => 'published_waterline_v1_release',
            'waterline-v2' => 'published_waterline_release',
            'sample-app-v1' => 'published_sample_app_v1_tag',
        ];
    }

    /**
     * @param array<string, string> $artifactVersions
     * @param array<string, string> $artifactSources
     *
     * @return array<string, string>
     */
    private function publicGuideAuditArtifactEnvironment(array $artifactVersions, array $artifactSources): array
    {
        return [
            'DW_SERVER_V1_VERSION' => $artifactVersions['server-v1'],
            'DW_SERVER_V2_VERSION' => $artifactVersions['server-v2'],
            'DW_SERVER_V1_ARTIFACT_SOURCE' => $artifactSources['server-v1'],
            'DW_SERVER_V2_ARTIFACT_SOURCE' => $artifactSources['server-v2'],
            'DW_CLI_V1_VERSION' => $artifactVersions['cli-v1'],
            'DW_CLI_VERSION' => $artifactVersions['cli-v2'],
            'DW_CLI_V1_ARTIFACT_SOURCE' => $artifactSources['cli-v1'],
            'DW_CLI_ARTIFACT_SOURCE' => $artifactSources['cli-v2'],
            'DW_WORKFLOW_PHP_V1_VERSION' => $artifactVersions['workflow-php-v1'],
            'DW_WORKFLOW_PHP_V2_VERSION' => $artifactVersions['workflow-php-v2'],
            'DW_WORKFLOW_PHP_V1_ARTIFACT_SOURCE' => $artifactSources['workflow-php-v1'],
            'DW_WORKFLOW_PHP_V2_ARTIFACT_SOURCE' => $artifactSources['workflow-php-v2'],
            'DW_PYTHON_SDK_VERSION' => $artifactVersions['sdk-python'],
            'DW_PYTHON_SDK_ARTIFACT_SOURCE' => $artifactSources['sdk-python'],
            'DW_WATERLINE_V1_VERSION' => $artifactVersions['waterline-v1'],
            'DW_WATERLINE_VERSION' => $artifactVersions['waterline-v2'],
            'DW_WATERLINE_V1_ARTIFACT_SOURCE' => $artifactSources['waterline-v1'],
            'DW_WATERLINE_ARTIFACT_SOURCE' => $artifactSources['waterline-v2'],
            'DW_SAMPLE_APP_V1_VERSION' => $artifactVersions['sample-app-v1'],
            'DW_SAMPLE_APP_V1_ARTIFACT_SOURCE' => $artifactSources['sample-app-v1'],
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
