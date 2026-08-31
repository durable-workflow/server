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
        $this->assertStringContainsString('DW_MIGRATION_FOUNDATION_PLAN_FILE', $shell);
        $this->assertStringContainsString('DW_MIGRATION_FOUNDATION_PLAN_JSON', $shell);
        $this->assertStringContainsString('DW_MIGRATION_RUN_FOUNDATION_PLAN', $shell);
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
            'source_capabilities',
            'sourceCapabilityInventory',
            'not_applicable',
            'v1_embedded_runtime_no_durable_schedule_surface',
            'storage_connection_smoke',
            'public_artifact_resolution',
            'public_operator_signal',
            'cli_skew_observations',
            'worker_skew_observations',
            'request_response_evidence',
            'readMigrationEvidence',
            'evidenceShardPaths',
            'mergeScenarioResults',
            'normalizeMigrationEvidenceShape',
            'runbookEvidenceFrom',
            'pinnedVersions',
            'resolvePublicArtifactDefaults',
            'latestPackagistVersion',
            'resolveV1WorkflowPackage',
            'latest_supported_v1_with_current_namespace_preference',
            'latestDockerHubTag',
            'latestGithubReleaseVersion',
            'latestGithubBranchCommit',
            'pinV1ServerBaselineFromWorkflowRuntime',
            'embedded-v1-server-runtime',
            'maybeExecuteFoundationPlan',
            'executeFoundationPlan',
            'preferFocusedFoundationScheduleEvidence',
            'preferFocusedFoundationWorkerEvidence',
            'buildFoundationQueueStateEvidence',
            'queueStateProductFailures',
            'queueResultHasDuplicationObservation',
            'buildFoundationV2ScheduleEvidence',
            'scheduleAssertionFailures',
            'foundationScheduleFinding',
            'buildFoundationV2WorkerRegistrationEvidence',
            'executeWorkerRegistrationOperation',
            'workerRegistrationProductFailures',
            'workerProjectionFreshness',
            'workerProjectionMismatches',
            'workerRegistrationCommandIsRunnerFailure',
            'foundationWorkerRegistrationFinding',
            'host_executed_migration_foundation_plan',
            'maybeRunPublicGuideAudit',
            'public_migration_guide_audit',
            'migrationGuideCommandExecutability',
            'unresolved_placeholder',
            'interactive_password_prompt',
            'SCENARIO_FINDING_POLICIES',
            'findingForNonPassScenario',
            'scenario_statuses',
            'missingRollbackClassificationFields',
            'cli-v1-to-server-v2',
            'worker-v2-to-server-v1',
            'PLACEHOLDER_EVIDENCE_TOKENS',
            'isPlaceholderEvidenceString',
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
            'queue_state_preserved',
            'schedule_cross_upgrade_cadence_preserved',
            'worker_registration_projection_preserved',
            'waterline_operator_visibility_preserved',
            'cli_access_to_preupgrade_state',
            'new_v2_workflow_start_after_upgrade',
            'new_v2_schedule_after_upgrade',
            'new_v2_worker_registration_after_upgrade',
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
                    'packagist_versions' => [
                        'durable-workflow/workflow' => ['1.0.75', '1.0.77', '2.0.0-alpha.204'],
                        'laravel-workflow/laravel-workflow' => ['1.0.76'],
                    ],
                    'artifact_versions' => [
                        'cli-v1' => '0.1.44',
                        'waterline-v1' => '1.0.16',
                        'sample-app-v1' => 'e769ac5f4147498c652445f517ae724d73afa4de',
                    ],
                    'artifact_sources' => [
                        'server-v1' => 'docker_hub:durableworkflow/server:no_v1_release_tag_found',
                        'cli-v1' => 'github_release:durable-workflow/cli:0.1.44:install.sh',
                        'waterline-v1' => 'packagist:laravel-workflow/waterline:1.0.16',
                        'sample-app-v1' => 'github_branch:durable-workflow/sample-app:Laravel-12@e769ac5f4147498c652445f517ae724d73afa4de',
                    ],
                    'observations' => [
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

            $this->assertSame('1.0.77', $result['published_artifact_versions']['workflow-php-v1']);
            $this->assertSame(
                'packagist:durable-workflow/workflow:1.0.77',
                $result['artifact_sources']['workflow-php-v1'],
            );
            $this->assertSame(
                'durable-workflow/workflow',
                $result['public_artifact_resolution']['observations']['workflow-php-v1']['package'],
            );
            $this->assertSame(
                '1.0.77',
                $result['public_artifact_resolution']['observations']['workflow-php-v1']['latest_supported_version'],
            );
            $this->assertTrue(
                $result['public_artifact_resolution']['observations']['workflow-php-v1']['current_namespace_preferred'],
            );
            $this->assertFalse(
                $result['public_artifact_resolution']['observations']['workflow-php-v1']['legacy_alias_fallback']['eligible'],
                'an older legacy alias must not replace the current package namespace',
            );
            $this->assertSame(
                '1.0.76',
                $result['public_artifact_resolution']['observations']['workflow-php-v1']['candidates']['legacy_alias']['version'],
            );
            $this->assertSame(
                '1.0.77',
                $result['published_artifact_versions']['server-v1'],
            );
            $this->assertSame(
                'packagist:durable-workflow/workflow:1.0.77:embedded-v1-server-runtime',
                $result['artifact_sources']['server-v1'],
            );
            $this->assertSame(
                'durable-workflow/workflow',
                $result['public_artifact_resolution']['observations']['server-v1']['package'],
            );
            $this->assertSame(
                'resolved',
                $result['public_artifact_resolution']['observations']['server-v1']['status'],
            );
            $this->assertSame(
                'embedded-v1-server-runtime',
                $result['public_artifact_resolution']['observations']['server-v1']['runtime'],
            );
            $this->assertSame('complete', $result['source_capabilities']['status']);
            $this->assertSame(
                'unsupported',
                $result['source_capabilities']['capabilities']['standalone_server_api']['status'],
            );
            $this->assertSame(
                'v1_embedded_runtime_no_durable_schedule_surface',
                $result['source_capabilities']['capabilities']['schedule']['reason_code'],
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

    public function test_runner_selects_legacy_v1_alias_only_when_it_is_newer(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the migration runner public artifact resolver.');
        }

        $result = $this->runPublicV1ResolverFixture(
            $nodeBinary,
            [
                'durable-workflow/workflow' => ['1.0.77'],
                'laravel-workflow/laravel-workflow' => ['1.0.78'],
            ],
        );
        $observation = $result['public_artifact_resolution']['observations']['workflow-php-v1'];

        $this->assertSame('1.0.78', $result['published_artifact_versions']['workflow-php-v1']);
        $this->assertSame(
            'packagist:laravel-workflow/laravel-workflow:1.0.78',
            $result['artifact_sources']['workflow-php-v1'],
        );
        $this->assertSame(
            'packagist:laravel-workflow/laravel-workflow:1.0.78:embedded-v1-server-runtime',
            $result['artifact_sources']['server-v1'],
        );
        $this->assertFalse($observation['current_namespace_preferred']);
        $this->assertTrue($observation['legacy_alias_fallback']['eligible']);
        $this->assertTrue($observation['legacy_alias_fallback']['selected']);
        $this->assertSame('1.0.77', $observation['legacy_alias_fallback']['comparison_version']);
    }

    public function test_runner_rejects_legacy_v1_alias_without_a_current_namespace_comparison(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the migration runner public artifact resolver.');
        }

        $result = $this->runPublicV1ResolverFixture(
            $nodeBinary,
            [
                'durable-workflow/workflow' => [],
                'laravel-workflow/laravel-workflow' => ['1.0.78'],
            ],
        );
        $observation = $result['public_artifact_resolution']['observations']['workflow-php-v1'];

        $this->assertSame('', $result['published_artifact_versions']['workflow-php-v1']);
        $this->assertSame('', $result['published_artifact_versions']['server-v1']);
        $this->assertSame('resolution_error', $observation['status']);
        $this->assertFalse($observation['legacy_alias_fallback']['eligible']);
        $this->assertNull($observation['legacy_alias_fallback']['comparison_version']);
        $this->assertContains(
            'workflow-php-v1',
            array_column($result['artifact_prerequisite_failures'], 'artifact'),
        );
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
        $this->assertSame('not_applicable', $result['scenario_results']['version_skew_refusal']['status']);
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

    public function test_runner_records_foundation_evidence_from_detailed_storage_smoke(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the migration runner foundation evidence gate.');
        }

        $artifactVersions = $this->artifactVersions();
        $artifactSources = $this->artifactSources();
        $commands = [
            'composer require durable-workflow/workflow:'.$artifactVersions['workflow-php-v2'],
            'php artisan migrate --force',
            'php artisan queue:restart',
        ];
        $preupgradeSnapshot = $this->stateSnapshotEvidence('preupgrade') + [
            'workflow_status_counts' => ['completed' => 1, 'running' => 2],
            'api_operations' => [
                'GET /api/workflows/migration-completed',
                'GET /api/workflows/migration-awaiting-signal/runs/latest/history',
            ],
        ];
        $postupgradeSnapshot = $this->stateSnapshotEvidence('postupgrade') + [
            'workflow_status_counts' => ['completed' => 3, 'running' => 0],
            'api_operations' => [
                'GET /api/workflows/migration-completed',
                'GET /api/workflows/migration-awaiting-signal/runs/latest/history',
            ],
        ];

        $result = $this->runRunnerEvidence(
            $nodeBinary,
            [],
            'dw-migration-foundation-storage-smoke-',
            $this->publicGuideAuditArtifactEnvironment($artifactVersions, $artifactSources),
            [
                'status' => 'pass',
                'source' => 'published-server-migration-contract',
                'storage_connection' => 'workflow_storage',
                'migration_foundation_evidence' => [
                    'source' => 'published-server-migration-contract',
                    'migration_plan' => [
                        'source' => 'published-server-migration-contract',
                        'migration_guide_revision' => [
                            'url' => 'https://durable-workflow.github.io/docs/2.0/migration/',
                            'sha256' => 'foundation-guide-sha',
                        ],
                        'guide_command_executability' => [
                            'status' => 'pass',
                            'checked_commands' => $commands,
                            'unexecutable_commands' => [],
                        ],
                        'commands_executed' => $commands,
                        'exit_codes' => [0, 0, 0],
                        'command_timings' => [
                            $commands[0] => 1410,
                            $commands[1] => 380,
                            $commands[2] => 90,
                        ],
                        'schema_or_storage_migration_output' => [
                            'workflow_storage_tables_preserved' => true,
                            'preupgrade_rows_visible_after_migrate' => true,
                        ],
                        'api_operations' => [
                            'GET /api/workflows/migration-completed',
                            'GET /api/workflows/migration-awaiting-signal/runs/latest/history',
                        ],
                    ],
                    'latest_supported_v1_state_setup' => [
                        'source_release_versions' => $artifactVersions,
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
                                'workflow_ids' => ['migration-completed', 'migration-awaiting-signal'],
                                'history_exported' => true,
                            ],
                        ],
                        'commands' => [
                            'composer require durable-workflow/workflow:'.$artifactVersions['workflow-php-v1'],
                            'php artisan workflow:start MigrationCompletedWorkflow',
                            'php artisan workflow:signal migration-awaiting-signal approve',
                        ],
                        'command_outputs' => [
                            $this->commandOutput('composer require durable-workflow/workflow:'.$artifactVersions['workflow-php-v1']),
                            $this->commandOutput('php artisan workflow:start MigrationCompletedWorkflow'),
                            $this->commandOutput('php artisan workflow:signal migration-awaiting-signal approve'),
                        ],
                    ],
                    'preupgrade_state_snapshot' => $preupgradeSnapshot,
                    'postupgrade_state_snapshot' => $postupgradeSnapshot,
                ],
            ],
        );

        $this->assertSame('non_passing', $result['outcome']);
        $this->assertSame(
            'pass',
            $result['scenario_results']['latest_supported_v1_state_setup']['status'],
        );
        $this->assertSame(
            'pass',
            $result['scenario_results']['documented_migration_steps_execute']['status'],
        );
        $this->assertSame(
            'not_covered',
            $result['scenario_results']['completed_history_preservation_and_replay']['status'],
        );
        $this->assertSame(
            'not_applicable',
            $result['scenario_results']['schedule_cross_upgrade_cadence_preserved']['status'],
        );
        $this->assertSame(
            'not_covered',
            $result['scenario_results']['waterline_operator_visibility_preserved']['status'],
        );
        $this->assertSame(
            'published-server-migration-contract',
            $result['migration_plan']['source'],
        );
        $this->assertSame($commands, $result['migration_plan']['commands_executed']);
        $this->assertSame(
            $preupgradeSnapshot['observed_states'],
            $result['preupgrade_state_snapshot']['observed_states'],
        );
        $this->assertSame(
            $postupgradeSnapshot['observed_states'],
            $result['postupgrade_state_snapshot']['observed_states'],
        );

        $missingRunRecordFields = array_column($result['finding_links']['run_record'] ?? [], 'missing_run_record_field');
        $this->assertNotContains('migration_plan', $missingRunRecordFields);
        $this->assertNotContains('preupgrade_state_snapshot', $missingRunRecordFields);
        $this->assertNotContains('postupgrade_state_snapshot', $missingRunRecordFields);
        $this->assertContains('history_dumps', $missingRunRecordFields);
    }

    public function test_runner_executes_foundation_plan_for_first_migration_cells(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the migration runner foundation plan.');
        }

        $artifactVersions = $this->artifactVersions();
        $artifactSources = $this->artifactSources();
        $command = static fn (string $label): array => [
            'command' => 'printf "%s\n" '.escapeshellarg($label),
            'public_api_surface' => $label,
        ];
        $snapshotWithCommands = function (string $phase) use ($command): array {
            $snapshot = $this->stateSnapshotEvidence($phase);
            foreach ($snapshot['observed_states'] as $index => $state) {
                $snapshot['observed_states'][$index] = $state + $command(
                    'GET /api/migration-conformance/'.$phase.'/'.$state['state_kind'],
                );
            }

            return $snapshot;
        };
        $guideCommands = [
            'composer update durable-workflow/workflow',
            'php artisan migrate',
            'php artisan queue:restart',
            'php artisan workflow:v1:list',
        ];
        $queuedTaskIdentity = [
            'task_id' => 'migration-queued-activity',
            'workflow_id' => 'migration-queue-holder',
            'activity_id' => 'migration-queued-activity-call',
            'activity_type' => 'migration_sample_activity',
        ];
        $plan = [
            'source' => 'published_artifact_foundation_plan',
            'source_release_versions' => $artifactVersions,
            'migration_guide_revision' => [
                'url' => 'https://durable-workflow.github.io/docs/2.0/migration/',
                'sha256' => 'foundation-plan-guide-sha',
            ],
            'v1_state_setup' => [
                'seeded_workflows' => [
                    'completed_workflow' => $command('php artisan workflow:start MigrationCompletedWorkflow'),
                    'running_workflow_waiting_on_signal' => $command('php artisan workflow:signal migration-awaiting-signal approve'),
                    'workflow_with_activity' => $command('php artisan workflow:start MigrationActivityWorkflow'),
                    'workflow_mid_activity_retry' => $command('php artisan workflow:start MigrationRetryWorkflow'),
                ],
                'seeded_schedules' => [
                    'active_schedule' => $command('php artisan schedule:list --name=migration-cross-upgrade-schedule'),
                ],
                'seeded_worker_registrations' => [
                    'registered_workers' => $command('GET /api/workers?task_queue=migration-v1'),
                ],
                'seeded_queue_state' => [
                    'queued_task' => $queuedTaskIdentity + [
                        'task_queue' => 'migration-v1',
                        'availability_state' => 'pending',
                    ] + $command('php artisan workflow:start MigrationQueuedActivityWorkflow'),
                ],
                'queryable_history' => [
                    'queryable_history' => $command('GET /api/workflows/migration-completed/runs/latest/history'),
                ],
            ],
            'migration_plan' => [
                'commands' => array_map(
                    static fn (string $guideCommand): array => [
                        'command' => 'printf "%s\n" '.escapeshellarg('executed '.$guideCommand),
                        'public_guide_command' => $guideCommand,
                    ],
                    $guideCommands,
                ),
                'schema_or_storage_migration_output' => $command('php artisan migrate output: Nothing to migrate'),
            ],
            'preupgrade_state_snapshot' => $snapshotWithCommands('preupgrade'),
            'postupgrade_state_snapshot' => $snapshotWithCommands('postupgrade'),
            'queue_state_preserved' => [
                'preupgrade_queue_state' => $queuedTaskIdentity + [
                    'task_queue' => 'migration-v1',
                    'availability_state' => 'pending',
                ] + $command('GET /api/tasks/migration-queued-activity'),
                'pending_task_identity' => $queuedTaskIdentity
                    + $command('GET /api/tasks/migration-queued-activity/identity'),
                'postupgrade_queue_state' => $queuedTaskIdentity + [
                    'disposition' => 'completed',
                ] + $command('GET /api/tasks/migration-queued-activity?runtime=v2'),
                'dequeue_or_completion_result' => $queuedTaskIdentity + [
                    'disposition' => 'completed',
                    'duplicate_execution_count' => 0,
                ] + $command('GET /api/tasks/migration-queued-activity/result'),
            ],
        ];
        $result = $this->runRunnerEvidence(
            $nodeBinary,
            [],
            'dw-migration-foundation-plan-',
            $this->publicGuideAuditArtifactEnvironment($artifactVersions, $artifactSources) + [
                'DW_MIGRATION_FOUNDATION_PLAN_JSON' => json_encode($plan, JSON_THROW_ON_ERROR),
                'DW_MIGRATION_RUN_FOUNDATION_PLAN' => '1',
            ],
        );

        $this->assertSame('non_passing', $result['outcome']);
        $this->assertSame('pass', $result['scenario_results']['latest_supported_v1_state_setup']['status']);
        $this->assertSame('pass', $result['scenario_results']['documented_migration_steps_execute']['status']);
        $this->assertSame('pass', $result['scenario_results']['queue_state_preserved']['status']);
        $this->assertSame(
            'migration-queued-activity',
            $result['scenario_results']['latest_supported_v1_state_setup']['observed_outputs']['seeded_queue_state']['queued_task']['task_id'],
        );
        $this->assertSame(
            'published_artifact_foundation_plan',
            $result['scenario_results']['latest_supported_v1_state_setup']['observed_outputs']['source'],
        );
        $this->assertSame(
            'pass',
            $result['migration_plan']['guide_command_executability']['status'],
        );
        $this->assertSame(
            $guideCommands,
            $result['migration_plan']['guide_command_executability']['checked_commands'],
        );
        $this->assertCount(4, $result['migration_plan']['commands_executed']);
        $this->assertSame([0, 0, 0, 0], $result['migration_plan']['exit_codes']);
        $this->assertSame(
            'pass',
            $result['migration_plan']['command_outputs'][0]['status'],
        );
        $this->assertSame(
            $guideCommands[0],
            $result['migration_plan']['command_outputs'][0]['public_guide_command'],
        );
        $this->assertStringContainsString(
            'executed '.$guideCommands[0],
            $result['migration_plan']['command_outputs'][0]['stdout'],
        );
        $this->assertSame(
            'pass',
            $result['preupgrade_state_snapshot']['status'],
        );
        $this->assertSame(
            'pass',
            $result['postupgrade_state_snapshot']['status'],
        );

        $missingRunRecordFields = array_column($result['finding_links']['run_record'] ?? [], 'missing_run_record_field');
        $this->assertNotContains('migration_plan', $missingRunRecordFields);
        $this->assertNotContains('preupgrade_state_snapshot', $missingRunRecordFields);
        $this->assertNotContains('postupgrade_state_snapshot', $missingRunRecordFields);
        $this->assertContains('history_dumps', $missingRunRecordFields);
    }

    public function test_runner_keeps_foundation_plan_command_failures_sticky(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the migration runner foundation plan.');
        }

        $artifactVersions = $this->artifactVersions();
        $artifactSources = $this->artifactSources();
        $plan = [
            'source' => 'published_artifact_foundation_plan',
            'source_release_versions' => $artifactVersions,
            'migration_guide_revision' => [
                'url' => 'https://durable-workflow.github.io/docs/2.0/migration/',
                'sha256' => 'foundation-plan-guide-sha',
            ],
            'migration_plan' => [
                'commands' => [
                    [
                        'command' => 'printf "failing migration step\n"; exit 7',
                        'public_guide_command' => 'php artisan migrate',
                    ],
                ],
                'schema_or_storage_migration_output' => [
                    'command' => 'printf "schema migration output\n"',
                ],
            ],
        ];

        $result = $this->runRunnerEvidence(
            $nodeBinary,
            $this->completeRunnerEvidence(),
            'dw-migration-foundation-plan-failure-',
            $this->publicGuideAuditArtifactEnvironment($artifactVersions, $artifactSources) + [
                'DW_MIGRATION_FOUNDATION_PLAN_JSON' => json_encode($plan, JSON_THROW_ON_ERROR),
                'DW_MIGRATION_RUN_FOUNDATION_PLAN' => '1',
            ],
        );

        $this->assertSame('non_passing', $result['outcome']);
        $this->assertSame('pass', $result['scenario_results']['latest_supported_v1_state_setup']['status']);
        $this->assertSame('fail', $result['scenario_results']['documented_migration_steps_execute']['status']);
        $this->assertSame('fail', $result['migration_plan']['command_outputs'][0]['status']);
        $this->assertSame(7, $result['migration_plan']['command_outputs'][0]['exit_code']);
        $this->assertContains(
            'documented_migration_steps_execute',
            array_keys($result['finding_links']),
        );
    }

    public function test_runner_executes_focused_postupgrade_schedule_cell_independently_of_v1_schedule_absence(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the focused schedule plan.');
        }

        $artifactVersions = $this->artifactVersions();
        $artifactSources = $this->artifactSources();
        $evidence = $this->completeRunnerEvidence();
        $evidence['scenario_results']['schedule_cross_upgrade_cadence_preserved'] = [
            'status' => 'not_applicable',
            'observed_outputs' => [
                'applicability' => [
                    'status' => 'not_applicable',
                    'source_capability' => 'schedule',
                    'reason_code' => 'v1_embedded_runtime_no_durable_schedule_surface',
                    'durable_state_mutation_attempted' => false,
                ],
            ],
        ];
        $evidence['scenario_results']['new_v2_schedule_after_upgrade'] = [
            'status' => 'fail',
            'observed_outputs' => [
                'observed_behavior' => 'The broad migration pass derived the target schedule verdict from source capability.',
            ],
        ];
        $evidence['finding_links']['new_v2_schedule_after_upgrade'] = [[
            'scenario_id' => 'new_v2_schedule_after_upgrade',
            'owning_surface' => 'conformance_harness',
            'finding_type' => 'stale_source_capability_interpretation',
        ]];

        $result = $this->runRunnerEvidence(
            $nodeBinary,
            $evidence,
            'dw-migration-focused-schedule-',
            $this->publicGuideAuditArtifactEnvironment($artifactVersions, $artifactSources) + [
                'DW_MIGRATION_FOUNDATION_PLAN_JSON' => json_encode(
                    $this->focusedSchedulePlan(),
                    JSON_THROW_ON_ERROR,
                ),
                'DW_MIGRATION_RUN_FOUNDATION_PLAN' => '1',
            ],
        );

        $scenario = $result['scenario_results']['new_v2_schedule_after_upgrade'];
        $this->assertSame('pass', $result['outcome']);
        $this->assertFalse($result['runner_blocked']);
        $this->assertSame('not_applicable', $result['scenario_results']['schedule_cross_upgrade_cadence_preserved']['status']);
        $this->assertSame(
            'v1_embedded_runtime_no_durable_schedule_surface',
            $result['scenario_results']['schedule_cross_upgrade_cadence_preserved']['observed_outputs']['applicability']['reason_code'],
        );
        $this->assertSame('pass', $scenario['status']);
        $this->assertSame('migration-v2-schedule-focused', $scenario['observed_outputs']['schedule_id']);
        $this->assertSame('migration-v2-scheduled-workflow', $scenario['observed_outputs']['workflow_id']);
        $this->assertSame('migration-v2-scheduled-run', $scenario['observed_outputs']['run_id']);
        $this->assertSame(
            'migration-v2-scheduled-run',
            $scenario['observed_outputs']['observed_ticks']['schedule_history']['events'][0]['workflow_run_id'],
        );
        $this->assertSame(
            'command_stdout_json',
            $scenario['observed_outputs']['request_response_evidence']['cli_describe']['response_source'],
        );
        $this->assertSame(
            ['setup' => [], 'transport' => [], 'product' => [], 'assertion' => []],
            $scenario['observed_outputs']['failure_classification'],
        );
        $this->assertArrayHasKey('cli', $scenario['observed_outputs']['typed_response_contracts']);
        $this->assertArrayHasKey('operator_api', $scenario['observed_outputs']['typed_response_contracts']);
        $this->assertArrayHasKey('schedule', $scenario['observed_outputs']['typed_response_contracts']);
        $this->assertArrayNotHasKey('new_v2_schedule_after_upgrade', $result['finding_links']);
    }

    public function test_runner_classifies_focused_schedule_failures_without_losing_command_evidence(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise focused schedule failure routing.');
        }

        $artifactVersions = $this->artifactVersions();
        $artifactSources = $this->artifactSources();
        $cases = [
            'setup' => static function (array $plan): array {
                $plan['new_v2_schedule_after_upgrade']['create_request'] = [];

                return $plan;
            },
            'transport' => static function (array $plan): array {
                $plan['new_v2_schedule_after_upgrade']['trigger_response'] = [
                    'command' => 'printf "%s\\n" '.escapeshellarg('connection refused').' >&2; exit 7',
                    'endpoint' => 'POST /api/schedules/migration-v2-schedule-focused/trigger',
                ];

                return $plan;
            },
            'product' => static function (array $plan): array {
                $response = ['http_status' => 500, 'body' => ['reason' => 'schedule_trigger_failed']];
                $plan['new_v2_schedule_after_upgrade']['trigger_response'] = [
                    'command' => 'printf "%s\\n" '.escapeshellarg(json_encode($response, JSON_THROW_ON_ERROR)),
                    'endpoint' => 'POST /api/schedules/migration-v2-schedule-focused/trigger',
                ];

                return $plan;
            },
            'assertion' => static function (array $plan): array {
                $response = ['http_status' => 200, 'body' => [
                    'schedule_id' => 'migration-v2-schedule-focused',
                    'outcome' => 'triggered',
                    'workflow_id' => 'migration-v2-scheduled-workflow',
                    'run_id' => 'different-run',
                ]];
                $plan['new_v2_schedule_after_upgrade']['trigger_response'] = [
                    'command' => 'printf "%s\\n" '.escapeshellarg(json_encode($response, JSON_THROW_ON_ERROR)),
                    'endpoint' => 'POST /api/schedules/migration-v2-schedule-focused/trigger',
                ];

                return $plan;
            },
        ];

        foreach ($cases as $classification => $mutate) {
            $result = $this->runRunnerEvidence(
                $nodeBinary,
                $this->completeRunnerEvidence(),
                'dw-migration-focused-schedule-'.$classification.'-',
                $this->publicGuideAuditArtifactEnvironment($artifactVersions, $artifactSources) + [
                    'DW_MIGRATION_FOUNDATION_PLAN_JSON' => json_encode(
                        $mutate($this->focusedSchedulePlan()),
                        JSON_THROW_ON_ERROR,
                    ),
                    'DW_MIGRATION_RUN_FOUNDATION_PLAN' => '1',
                ],
            );

            $scenario = $result['scenario_results']['new_v2_schedule_after_upgrade'];
            $this->assertSame('non_passing', $result['outcome'], $classification);
            $this->assertFalse($result['runner_blocked'], $classification);
            $this->assertSame('fail', $scenario['status'], $classification);
            $this->assertNotEmpty($scenario['observed_outputs']['failure_classification'][$classification], $classification);
            $this->assertNotEmpty(
                $scenario['observed_outputs']['request_response_evidence']['trigger']['command']
                    ?? $scenario['observed_outputs']['request_response_evidence']['create']['endpoint'],
                $classification,
            );
            $this->assertSame(
                $classification,
                $result['finding_links']['new_v2_schedule_after_upgrade'][0]['failure_classification'],
                $classification,
            );
        }
    }

    public function test_runner_executes_focused_postupgrade_worker_registration_and_poll_plan(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the focused worker-registration plan.');
        }

        $artifactVersions = $this->artifactVersions();
        $artifactSources = $this->artifactSources();
        $workerId = 'migration-v2-worker-20260710';
        $namespace = 'migration-conformance';
        $taskQueue = 'migration-v2-registration-20260710';
        $request = [
            'worker_id' => $workerId,
            'namespace' => $namespace,
            'task_queue' => $taskQueue,
            'runtime' => 'php',
            'sdk_version' => $artifactVersions['workflow-php-v2'],
            'build_id' => 'migration-v2-build',
            'supported_workflow_types' => ['migration.worker.registration.probe'],
            'capabilities' => ['workflow_tasks'],
            'max_concurrent_workflow_tasks' => 2,
            'max_concurrent_activity_tasks' => 1,
        ];
        $workerProjection = [
            'worker_id' => $workerId,
            'namespace' => $namespace,
            'task_queue' => $taskQueue,
            'runtime' => 'php',
            'sdk_version' => $artifactVersions['workflow-php-v2'],
            'build_id' => 'migration-v2-build',
            'capabilities' => ['workflow_tasks'],
            'status' => 'active',
            'last_heartbeat_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'task_slots' => [
                'workflow_available' => 2,
                'activity_available' => 1,
                'session_available' => 1,
                'workflow_capacity' => 2,
                'activity_capacity' => 1,
                'session_capacity' => 1,
            ],
        ];
        $protocolMetadata = [
            'protocol_version' => '1.13',
            'server_capabilities' => [
                'poll_status' => true,
                'worker_status' => ['supported' => true],
            ],
        ];
        $operation = static function (
            string $endpoint,
            array $requestBody,
            array $responseBody,
            int $httpStatus = 200,
        ): array {
            $response = ['http_status' => $httpStatus, 'body' => $responseBody];

            return [
                'command' => 'printf "%s\\n" '.escapeshellarg(json_encode($response, JSON_THROW_ON_ERROR)),
                'endpoint' => $endpoint,
                'request' => $requestBody,
                'http_status' => $httpStatus,
            ];
        };
        $pollRequest = [
            'worker_id' => $workerId,
            'task_queue' => $taskQueue,
            'poll_request_id' => 'migration-v2-registration-poll-20260710',
            'timeout_seconds' => 0,
        ];
        $plan = [
            'source' => 'published_artifact_foundation_plan',
            'new_v2_worker_registration_after_upgrade' => [
                'worker_id' => $workerId,
                'namespace' => $namespace,
                'task_queue' => $taskQueue,
                'unique_task_queue' => true,
                'registration_request' => $operation(
                    'POST /api/worker/register',
                    $request,
                    [
                        'registered' => true,
                        'worker_id' => $workerId,
                        'namespace' => $namespace,
                        'task_queue' => $taskQueue,
                    ] + $protocolMetadata,
                    201,
                ),
                'operator_api_response' => $operation(
                    'GET /api/workers/'.$workerId,
                    ['worker_id' => $workerId, 'namespace' => $namespace],
                    $workerProjection,
                ),
                'cli_worker_projection' => $operation(
                    'dw worker:list --task-queue='.$taskQueue.' --output=json',
                    ['namespace' => $namespace, 'task_queue' => $taskQueue],
                    ['workers' => [$workerProjection]],
                ),
                'polling_result' => $operation(
                    'POST /api/worker/workflow-tasks/poll',
                    $pollRequest,
                    ['task' => null, 'poll_status' => 'empty'] + $protocolMetadata,
                ),
            ],
        ];
        $staleBroadEvidence = $this->completeRunnerEvidence();
        $staleBroadEvidence['scenario_results']['new_v2_worker_registration_after_upgrade'] = [
            'status' => 'fail',
            'observed_outputs' => [
                'observed_behavior' => 'The broad migration pass did not capture focused worker registration behavior.',
            ],
        ];
        $staleBroadEvidence['finding_links']['new_v2_worker_registration_after_upgrade'] = [[
            'scenario_id' => 'new_v2_worker_registration_after_upgrade',
            'owning_surface' => 'conformance_harness',
            'finding_type' => 'stale_broad_migration_observation',
        ]];

        $result = $this->runRunnerEvidence(
            $nodeBinary,
            $staleBroadEvidence,
            'dw-migration-focused-worker-registration-',
            $this->publicGuideAuditArtifactEnvironment($artifactVersions, $artifactSources) + [
                'DW_MIGRATION_FOUNDATION_PLAN_JSON' => json_encode($plan, JSON_THROW_ON_ERROR),
                'DW_MIGRATION_RUN_FOUNDATION_PLAN' => '1',
            ],
        );

        $scenario = $result['scenario_results']['new_v2_worker_registration_after_upgrade'];
        $this->assertSame('pass', $result['outcome']);
        $this->assertFalse($result['runner_blocked']);
        $this->assertSame('pass', $scenario['status']);
        $this->assertSame($workerId, $scenario['observed_outputs']['worker_id']);
        $this->assertSame($namespace, $scenario['observed_outputs']['namespace']);
        $this->assertSame($taskQueue, $scenario['observed_outputs']['task_queue']);
        $this->assertTrue($scenario['observed_outputs']['unique_task_queue']);
        $this->assertSame('active', $scenario['observed_outputs']['task_queue_projection']['status']);
        $this->assertSame(2, $scenario['observed_outputs']['cli_worker_projection']['task_slots']['workflow_available']);
        $this->assertSame('1.13', $scenario['observed_outputs']['protocol_metadata']['poll']['protocol_version']);
        $this->assertSame('empty', $scenario['observed_outputs']['polling_result']['response']['body']['poll_status']);
        $this->assertSame(0, $scenario['observed_outputs']['exit_codes']['poll']);
        $this->assertNotEmpty($scenario['observed_outputs']['timestamps']['poll']['started_at']);
        $this->assertSame(
            'POST /api/worker/workflow-tasks/poll',
            $scenario['observed_outputs']['request_response_evidence']['poll']['endpoint'],
        );
        $this->assertSame(
            'command_stdout_json',
            $scenario['observed_outputs']['request_response_evidence']['poll']['response_source'],
        );
        $this->assertTrue($scenario['observed_outputs']['freshness']['operator_api']['valid']);
        $this->assertTrue($scenario['observed_outputs']['freshness']['cli']['valid']);
        $this->assertArrayHasKey('cli', $scenario['observed_outputs']['typed_response_contracts']);
        $this->assertArrayHasKey('worker_poll', $scenario['observed_outputs']['typed_response_contracts']);
        $this->assertArrayNotHasKey(
            'new_v2_worker_registration_after_upgrade',
            $result['finding_links'],
            'the freshly executed focused cell must replace stale broad-run failure evidence',
        );
    }

    public function test_runner_parses_large_worker_responses_before_bounding_command_diagnostics(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise large focused worker responses.');
        }

        $artifactVersions = $this->artifactVersions();
        $artifactSources = $this->artifactSources();
        $plan = $this->focusedWorkerRegistrationPlan($artifactVersions);
        $worker = $plan['new_v2_worker_registration_after_upgrade'];
        $largeCapabilityCatalog = str_repeat('current-worker-protocol-capability;', 800);
        $registrationResponse = [
            'http_status' => 201,
            'body' => [
                'registered' => true,
                'worker_id' => $worker['worker_id'],
                'namespace' => $worker['namespace'],
                'task_queue' => $worker['task_queue'],
                'protocol_version' => '1.13',
                'server_capabilities' => [
                    'poll_status' => true,
                    'capability_catalog' => $largeCapabilityCatalog,
                ],
            ],
        ];
        $plan['new_v2_worker_registration_after_upgrade']['registration_request']['command'] =
            'printf "%s\\n" ' . escapeshellarg(json_encode($registrationResponse, JSON_THROW_ON_ERROR));

        $result = $this->runRunnerEvidence(
            $nodeBinary,
            [],
            'dw-migration-focused-worker-large-response-',
            $this->publicGuideAuditArtifactEnvironment($artifactVersions, $artifactSources) + [
                'DW_MIGRATION_FOUNDATION_PLAN_JSON' => json_encode($plan, JSON_THROW_ON_ERROR),
                'DW_MIGRATION_RUN_FOUNDATION_PLAN' => '1',
            ],
        );

        $scenario = $result['scenario_results']['new_v2_worker_registration_after_upgrade'];
        $registration = $scenario['observed_outputs']['request_response_evidence']['registration'];
        $this->assertSame('non_passing', $result['outcome']);
        $this->assertFalse($result['runner_blocked']);
        $this->assertSame('pass', $scenario['status']);
        $this->assertSame(
            $largeCapabilityCatalog,
            $registration['response']['body']['server_capabilities']['capability_catalog'],
        );
        $this->assertGreaterThan(20000, $registration['stdout_character_count']);
        $this->assertTrue($registration['stdout_truncated']);
        $this->assertLessThanOrEqual(4096, strlen($registration['stdout']));
        $this->assertSame('', $registration['stderr']);
        $this->assertSame(0, $registration['stderr_character_count']);
        $this->assertFalse($registration['stderr_truncated']);
    }

    public function test_runner_routes_focused_worker_poll_product_failure_with_exact_evidence(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise focused worker product-failure routing.');
        }

        $artifactVersions = $this->artifactVersions();
        $artifactSources = $this->artifactSources();
        $plan = $this->focusedWorkerRegistrationPlan($artifactVersions);
        $failedPoll = [
            'http_status' => 200,
            'body' => [
                'task' => null,
                'poll_status' => 'no_workflow_capability',
                'protocol_version' => '1.13',
                'server_capabilities' => ['poll_status' => true],
            ],
        ];
        $plan['new_v2_worker_registration_after_upgrade']['polling_result']['command'] =
            'printf "%s\\n" '.escapeshellarg(json_encode($failedPoll, JSON_THROW_ON_ERROR));

        $result = $this->runRunnerEvidence(
            $nodeBinary,
            [],
            'dw-migration-focused-worker-product-failure-',
            $this->publicGuideAuditArtifactEnvironment($artifactVersions, $artifactSources) + [
                'DW_MIGRATION_FOUNDATION_PLAN_JSON' => json_encode($plan, JSON_THROW_ON_ERROR),
                'DW_MIGRATION_RUN_FOUNDATION_PLAN' => '1',
            ],
        );

        $scenario = $result['scenario_results']['new_v2_worker_registration_after_upgrade'];
        $this->assertFalse($result['runner_blocked']);
        $this->assertSame('fail', $scenario['status']);
        $this->assertSame('worker_poll_unsuccessful', $scenario['observed_outputs']['product_failures'][0]['code']);
        $this->assertSame('server', $scenario['observed_outputs']['product_failures'][0]['owning_surface']);
        $this->assertSame(
            'POST /api/worker/workflow-tasks/poll',
            $scenario['observed_outputs']['product_failures'][0]['endpoint'],
        );
        $this->assertSame(
            'no_workflow_capability',
            $scenario['observed_outputs']['product_failures'][0]['response']['body']['poll_status'],
        );
        $this->assertSame($artifactVersions, $result['resolved_artifact_versions']);
        $detailedFindings = array_values(array_filter(
            $result['finding_links']['new_v2_worker_registration_after_upgrade'],
            static fn (array $finding): bool => isset($finding['product_failures']),
        ));
        $this->assertCount(1, $detailedFindings);
        $this->assertSame($artifactVersions, $detailedFindings[0]['artifact_versions']);
        $this->assertSame('server', $detailedFindings[0]['owning_surface']);
    }

    public function test_runner_classifies_focused_worker_transport_failure_as_runner_blocked(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise focused worker runner-blocked routing.');
        }

        $artifactVersions = $this->artifactVersions();
        $artifactSources = $this->artifactSources();
        $plan = $this->focusedWorkerRegistrationPlan($artifactVersions);
        $plan['new_v2_worker_registration_after_upgrade']['polling_result']['command'] =
            'durable-workflow-missing-poll-command';

        $result = $this->runRunnerEvidence(
            $nodeBinary,
            [],
            'dw-migration-focused-worker-runner-blocked-',
            $this->publicGuideAuditArtifactEnvironment($artifactVersions, $artifactSources) + [
                'DW_MIGRATION_FOUNDATION_PLAN_JSON' => json_encode($plan, JSON_THROW_ON_ERROR),
                'DW_MIGRATION_RUN_FOUNDATION_PLAN' => '1',
            ],
        );

        $this->assertTrue($result['runner_blocked']);
        $this->assertSame('non_passing_runner_blocked', $result['outcome']);
        $this->assertSame(
            'runner_blocked',
            $result['scenario_results']['new_v2_worker_registration_after_upgrade']['status'],
        );
        $this->assertStringContainsString(
            'runner_infrastructure failure',
            $result['scenario_results']['new_v2_worker_registration_after_upgrade']['observed_outputs']['blocked_reason'],
        );
        $scenario = $result['scenario_results']['new_v2_worker_registration_after_upgrade'];
        $poll = $scenario['observed_outputs']['request_response_evidence']['poll'];
        $this->assertSame('', $poll['stdout']);
        $this->assertSame(0, $poll['stdout_character_count']);
        $this->assertFalse($poll['stdout_truncated']);
        $this->assertStringContainsString('not found', $poll['stderr']);
        $this->assertGreaterThan(0, $poll['stderr_character_count']);
        $this->assertFalse($poll['stderr_truncated']);
    }

    public function test_runner_rejects_plan_supplied_worker_responses_without_command_output(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise focused worker response provenance.');
        }

        $artifactVersions = $this->artifactVersions();
        $artifactSources = $this->artifactSources();
        $plan = $this->focusedWorkerRegistrationPlan($artifactVersions);
        foreach (['registration_request', 'operator_api_response', 'cli_worker_projection', 'polling_result'] as $field) {
            $plan['new_v2_worker_registration_after_upgrade'][$field]['command'] = true;
            $plan['new_v2_worker_registration_after_upgrade'][$field]['response'] = [
                'http_status' => 200,
                'body' => ['spoofed' => true],
            ];
        }

        $result = $this->runRunnerEvidence(
            $nodeBinary,
            [],
            'dw-migration-focused-worker-spoofed-response-',
            $this->publicGuideAuditArtifactEnvironment($artifactVersions, $artifactSources) + [
                'DW_MIGRATION_FOUNDATION_PLAN_JSON' => json_encode($plan, JSON_THROW_ON_ERROR),
                'DW_MIGRATION_RUN_FOUNDATION_PLAN' => '1',
            ],
        );

        $this->assertTrue($result['runner_blocked']);
        $this->assertSame('non_passing_runner_blocked', $result['outcome']);
        $this->assertStringContainsString(
            'did not emit a JSON response on stdout',
            $result['scenario_results']['new_v2_worker_registration_after_upgrade']['observed_outputs']['blocked_reason'],
        );
        $scenario = $result['scenario_results']['new_v2_worker_registration_after_upgrade'];
        $registration = $scenario['observed_outputs']['request_response_evidence']['registration'];
        $this->assertNull($registration['response']);
        $this->assertSame('missing_command_stdout_json', $registration['response_source']);
        $this->assertFalse($registration['response_observed_from_command_stdout']);
        $this->assertSame('', $registration['stdout']);
        $this->assertSame(0, $registration['stdout_character_count']);
        $this->assertFalse($registration['stdout_truncated']);
        $this->assertSame('', $registration['stderr']);
        $this->assertSame(0, $registration['stderr_character_count']);
        $this->assertFalse($registration['stderr_truncated']);
    }

    public function test_runner_rejects_unsuccessful_worker_http_statuses_as_product_failures(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise focused worker HTTP status validation.');
        }

        $artifactVersions = $this->artifactVersions();
        $artifactSources = $this->artifactSources();
        $plan = $this->focusedWorkerRegistrationPlan($artifactVersions);
        foreach (['registration_request', 'operator_api_response', 'polling_result'] as $field) {
            $response = ['http_status' => 500, 'body' => ['message' => 'forced product failure']];
            $plan['new_v2_worker_registration_after_upgrade'][$field]['command'] =
                'printf "%s\\n" '.escapeshellarg(json_encode($response, JSON_THROW_ON_ERROR));
        }

        $result = $this->runRunnerEvidence(
            $nodeBinary,
            [],
            'dw-migration-focused-worker-http-status-',
            $this->publicGuideAuditArtifactEnvironment($artifactVersions, $artifactSources) + [
                'DW_MIGRATION_FOUNDATION_PLAN_JSON' => json_encode($plan, JSON_THROW_ON_ERROR),
                'DW_MIGRATION_RUN_FOUNDATION_PLAN' => '1',
            ],
        );

        $scenario = $result['scenario_results']['new_v2_worker_registration_after_upgrade'];
        $httpFailures = array_values(array_filter(
            $scenario['observed_outputs']['product_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'unsuccessful_http_status',
        ));
        $this->assertFalse($result['runner_blocked']);
        $this->assertSame('fail', $scenario['status']);
        $this->assertSame(['registration', 'operator_api', 'poll'], array_column($httpFailures, 'operation'));
        $this->assertSame([500, 500, 500], array_column($httpFailures, 'http_status'));
    }

    public function test_runner_rejects_mismatched_api_and_cli_worker_projections(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise focused worker projection parity.');
        }

        $artifactVersions = $this->artifactVersions();
        $artifactSources = $this->artifactSources();
        $plan = $this->focusedWorkerRegistrationPlan($artifactVersions);
        $cliProjection = $this->focusedWorkerProjection($artifactVersions);
        $cliProjection['sdk_version'] = '2.0.0-adversarial';
        $response = ['http_status' => 200, 'body' => ['workers' => [$cliProjection]]];
        $plan['new_v2_worker_registration_after_upgrade']['cli_worker_projection']['command'] =
            'printf "%s\\n" '.escapeshellarg(json_encode($response, JSON_THROW_ON_ERROR));

        $result = $this->runRunnerEvidence(
            $nodeBinary,
            [],
            'dw-migration-focused-worker-projection-mismatch-',
            $this->publicGuideAuditArtifactEnvironment($artifactVersions, $artifactSources) + [
                'DW_MIGRATION_FOUNDATION_PLAN_JSON' => json_encode($plan, JSON_THROW_ON_ERROR),
                'DW_MIGRATION_RUN_FOUNDATION_PLAN' => '1',
            ],
        );

        $scenario = $result['scenario_results']['new_v2_worker_registration_after_upgrade'];
        $mismatches = array_values(array_filter(
            $scenario['observed_outputs']['product_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'worker_projection_mismatch',
        ));
        $this->assertSame('fail', $scenario['status']);
        $this->assertCount(1, $mismatches);
        $this->assertStringContainsString('sdk_version', $mismatches[0]['detail']);
    }

    public function test_runner_rejects_invalid_worker_heartbeat_timestamps(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise focused worker freshness validation.');
        }

        $artifactVersions = $this->artifactVersions();
        $artifactSources = $this->artifactSources();
        $plan = $this->focusedWorkerRegistrationPlan($artifactVersions);
        $projection = $this->focusedWorkerProjection($artifactVersions);
        $projection['last_heartbeat_at'] = 'not-a-timestamp';
        $apiResponse = ['http_status' => 200, 'body' => $projection];
        $cliResponse = ['http_status' => 200, 'body' => ['workers' => [$projection]]];
        $plan['new_v2_worker_registration_after_upgrade']['operator_api_response']['command'] =
            'printf "%s\\n" '.escapeshellarg(json_encode($apiResponse, JSON_THROW_ON_ERROR));
        $plan['new_v2_worker_registration_after_upgrade']['cli_worker_projection']['command'] =
            'printf "%s\\n" '.escapeshellarg(json_encode($cliResponse, JSON_THROW_ON_ERROR));

        $result = $this->runRunnerEvidence(
            $nodeBinary,
            [],
            'dw-migration-focused-worker-invalid-heartbeat-',
            $this->publicGuideAuditArtifactEnvironment($artifactVersions, $artifactSources) + [
                'DW_MIGRATION_FOUNDATION_PLAN_JSON' => json_encode($plan, JSON_THROW_ON_ERROR),
                'DW_MIGRATION_RUN_FOUNDATION_PLAN' => '1',
            ],
        );

        $scenario = $result['scenario_results']['new_v2_worker_registration_after_upgrade'];
        $this->assertSame('fail', $scenario['status']);
        $this->assertFalse($scenario['observed_outputs']['freshness']['operator_api']['valid']);
        $this->assertContains(
            'last_heartbeat_at_invalid',
            $scenario['observed_outputs']['freshness']['operator_api']['failures'],
        );
    }

    public function test_runner_enforces_the_fixed_worker_freshness_window_for_stale_and_future_heartbeats(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise focused worker freshness validation.');
        }

        $artifactVersions = $this->artifactVersions();
        $artifactSources = $this->artifactSources();
        $heartbeatCases = [
            'stale' => [
                'timestamp' => gmdate('Y-m-d\TH:i:s\Z', time() - 31536000),
                'failure' => 'last_heartbeat_at_stale',
            ],
            'future' => [
                'timestamp' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
                'failure' => 'last_heartbeat_at_in_future',
            ],
        ];

        foreach ($heartbeatCases as $case => $heartbeatCase) {
            $plan = $this->focusedWorkerRegistrationPlan($artifactVersions);
            $plan['new_v2_worker_registration_after_upgrade']['stale_after_seconds'] = 31536000;
            $projection = $this->focusedWorkerProjection($artifactVersions);
            $projection['last_heartbeat_at'] = $heartbeatCase['timestamp'];
            $apiResponse = ['http_status' => 200, 'body' => $projection];
            $cliResponse = ['http_status' => 200, 'body' => ['workers' => [$projection]]];
            $plan['new_v2_worker_registration_after_upgrade']['operator_api_response']['command'] =
                'printf "%s\\n" '.escapeshellarg(json_encode($apiResponse, JSON_THROW_ON_ERROR));
            $plan['new_v2_worker_registration_after_upgrade']['cli_worker_projection']['command'] =
                'printf "%s\\n" '.escapeshellarg(json_encode($cliResponse, JSON_THROW_ON_ERROR));

            $result = $this->runRunnerEvidence(
                $nodeBinary,
                [],
                'dw-migration-focused-worker-'.$case.'-heartbeat-',
                $this->publicGuideAuditArtifactEnvironment($artifactVersions, $artifactSources) + [
                    'DW_MIGRATION_FOUNDATION_PLAN_JSON' => json_encode($plan, JSON_THROW_ON_ERROR),
                    'DW_MIGRATION_RUN_FOUNDATION_PLAN' => '1',
                ],
            );

            $scenario = $result['scenario_results']['new_v2_worker_registration_after_upgrade'];
            $this->assertFalse($result['runner_blocked']);
            $this->assertSame('fail', $scenario['status']);
            $this->assertSame(300, $scenario['observed_outputs']['freshness']['stale_after_seconds']);
            $this->assertFalse($scenario['observed_outputs']['freshness']['operator_api']['valid']);
            $this->assertContains(
                $heartbeatCase['failure'],
                $scenario['observed_outputs']['freshness']['operator_api']['failures'],
            );
        }
    }

    public function test_runner_routes_observed_non_2xx_empty_poll_as_product_failure(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise focused worker HTTP failure routing.');
        }

        $artifactVersions = $this->artifactVersions();
        $artifactSources = $this->artifactSources();
        $plan = $this->focusedWorkerRegistrationPlan($artifactVersions);
        $plan['new_v2_worker_registration_after_upgrade']['polling_result']['command'] =
            'printf "500\\n"; exit 22';
        $plan['new_v2_worker_registration_after_upgrade']['polling_result']['failure_classification'] =
            'runner_infrastructure';

        $result = $this->runRunnerEvidence(
            $nodeBinary,
            [],
            'dw-migration-focused-worker-empty-http-failure-',
            $this->publicGuideAuditArtifactEnvironment($artifactVersions, $artifactSources) + [
                'DW_MIGRATION_FOUNDATION_PLAN_JSON' => json_encode($plan, JSON_THROW_ON_ERROR),
                'DW_MIGRATION_RUN_FOUNDATION_PLAN' => '1',
            ],
        );

        $scenario = $result['scenario_results']['new_v2_worker_registration_after_upgrade'];
        $httpFailures = array_values(array_filter(
            $scenario['observed_outputs']['product_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'unsuccessful_http_status'
                && ($failure['operation'] ?? null) === 'poll',
        ));
        $this->assertFalse($result['runner_blocked']);
        $this->assertSame('non_passing', $result['outcome']);
        $this->assertSame('fail', $scenario['status']);
        $this->assertCount(1, $httpFailures);
        $this->assertSame(500, $httpFailures[0]['http_status']);
        $this->assertSame(22, $httpFailures[0]['exit_code']);
        $this->assertNull($httpFailures[0]['response']);
        $this->assertSame([], $scenario['observed_outputs']['runner_failures']);
    }

    public function test_runner_keeps_failed_queue_seed_command_sticky_in_queue_continuity(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise queued-task foundation commands.');
        }

        $artifactVersions = $this->artifactVersions();
        $artifactSources = $this->artifactSources();
        $queuedTaskIdentity = [
            'task_id' => 'migration-queued-activity',
            'workflow_id' => 'migration-queue-holder',
            'activity_id' => 'migration-queued-activity-call',
            'activity_type' => 'migration_sample_activity',
        ];
        $observationCommand = static fn (string $label): array => [
            'command' => 'printf "%s\n" '.escapeshellarg($label),
        ];
        $plan = [
            'source' => 'published_artifact_foundation_plan',
            'source_release_versions' => $artifactVersions,
            'v1_state_setup' => [
                'seeded_queue_state' => [
                    'queued_task' => $queuedTaskIdentity + [
                        'task_queue' => 'migration-v1',
                        'availability_state' => 'pending',
                        'command' => 'printf "queue seed failed\n"; exit 9',
                    ],
                ],
            ],
            'queue_state_preserved' => [
                'preupgrade_queue_state' => $queuedTaskIdentity + [
                    'task_queue' => 'migration-v1',
                    'availability_state' => 'pending',
                ] + $observationCommand('preupgrade queue state'),
                'pending_task_identity' => $queuedTaskIdentity
                    + $observationCommand('pending task identity'),
                'postupgrade_queue_state' => $queuedTaskIdentity + [
                    'disposition' => 'completed',
                ] + $observationCommand('postupgrade queue state'),
                'dequeue_or_completion_result' => $queuedTaskIdentity + [
                    'disposition' => 'completed',
                    'duplicate_execution_count' => 0,
                ] + $observationCommand('queue completion result'),
            ],
        ];

        $result = $this->runRunnerEvidence(
            $nodeBinary,
            $this->completeRunnerEvidence(),
            'dw-migration-queue-seed-command-failure-',
            $this->publicGuideAuditArtifactEnvironment($artifactVersions, $artifactSources) + [
                'DW_MIGRATION_FOUNDATION_PLAN_JSON' => json_encode($plan, JSON_THROW_ON_ERROR),
                'DW_MIGRATION_RUN_FOUNDATION_PLAN' => '1',
            ],
        );

        $this->assertSame('non_passing', $result['outcome']);
        $this->assertSame('fail', $result['scenario_results']['latest_supported_v1_state_setup']['status']);
        $this->assertSame('fail', $result['scenario_results']['queue_state_preserved']['status']);
        $this->assertSame(
            9,
            $result['scenario_results']['latest_supported_v1_state_setup']['observed_outputs']['seeded_queue_state']['queued_task']['exit_code'],
        );
        $this->assertTrue(
            $result['scenario_results']['queue_state_preserved']['observed_outputs']['commands_failed'],
        );
    }

    public function test_runner_keeps_signaled_foundation_plan_commands_failed(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the migration runner foundation plan.');
        }

        $artifactVersions = $this->artifactVersions();
        $artifactSources = $this->artifactSources();
        $plan = [
            'source' => 'published_artifact_foundation_plan',
            'source_release_versions' => $artifactVersions,
            'migration_guide_revision' => [
                'url' => 'https://durable-workflow.github.io/docs/2.0/migration/',
                'sha256' => 'foundation-plan-guide-sha',
            ],
            'migration_plan' => [
                'commands' => [
                    [
                        'command' => 'kill -TERM $$',
                        'public_guide_command' => 'php artisan migrate',
                    ],
                ],
                'schema_or_storage_migration_output' => [
                    'command' => 'printf "schema migration output\n"',
                ],
            ],
        ];

        $result = $this->runRunnerEvidence(
            $nodeBinary,
            $this->completeRunnerEvidence(),
            'dw-migration-foundation-plan-signal-',
            $this->publicGuideAuditArtifactEnvironment($artifactVersions, $artifactSources) + [
                'DW_MIGRATION_FOUNDATION_PLAN_JSON' => json_encode($plan, JSON_THROW_ON_ERROR),
                'DW_MIGRATION_RUN_FOUNDATION_PLAN' => '1',
            ],
        );

        $this->assertSame('non_passing', $result['outcome']);
        $this->assertSame('fail', $result['scenario_results']['documented_migration_steps_execute']['status']);
        $this->assertSame('fail', $result['migration_plan']['command_outputs'][0]['status']);
        $this->assertSame(143, $result['migration_plan']['command_outputs'][0]['exit_code']);
        $this->assertSame('SIGTERM', $result['migration_plan']['command_outputs'][0]['signal']);
        $this->assertTrue($result['migration_plan']['commands_failed']);
    }

    public function test_runner_downgrades_shallow_rollback_and_classifies_source_absent_skew(): void
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

        $skew = $result['scenario_results']['version_skew_refusal'];
        $this->assertSame('not_applicable', $skew['status']);
        $this->assertSame(
            'v1_embedded_runtime_no_standalone_cli_server_surface',
            $skew['observed_outputs']['applicability_evidence']['cli-v1-to-server-v2']['reason_codes'][0],
        );
        $this->assertFalse(
            $skew['observed_outputs']['applicability_evidence']['worker-v2-to-server-v1']['durable_state_mutation_attempted'],
        );
    }

    public function test_runner_keeps_v1_durable_state_strict_while_classifying_absent_control_plane_cells(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise source-capability migration classification.');
        }

        $evidence = $this->completeRunnerEvidence();
        unset(
            $evidence['scenario_results']['latest_supported_v1_state_setup']['observed_outputs']['seeded_schedules'],
            $evidence['scenario_results']['latest_supported_v1_state_setup']['observed_outputs']['seeded_worker_registrations'],
        );
        $evidence['scenario_results']['schedule_cross_upgrade_cadence_preserved'] = [
            'status' => 'fail',
            'observed_outputs' => [
                'failure_reason' => 'the selected v1 embedded runtime has no durable schedule surface',
            ],
        ];
        $evidence['scenario_results']['worker_registration_projection_preserved'] = [
            'status' => 'fail',
            'observed_outputs' => [
                'failure_reason' => 'the selected v1 embedded runtime has no worker-registration projection',
            ],
        ];
        $evidence['scenario_results']['version_skew_refusal'] = [
            'status' => 'fail',
            'observed_outputs' => [
                'failure_reason' => 'there is no standalone v1 endpoint for a skew request',
            ],
        ];
        $evidence['preupgrade_state_snapshot']['observed_states'] = array_values(array_filter(
            $evidence['preupgrade_state_snapshot']['observed_states'],
            static fn (array $state): bool => ! in_array(
                $state['state_kind'],
                ['schedule', 'worker_registration'],
                true,
            ),
        ));

        $result = $this->runRunnerEvidence(
            $nodeBinary,
            $evidence,
            'dw-migration-source-capabilities-',
        );

        $this->assertSame('pass', $result['outcome']);
        $this->assertSame('complete', $result['source_capabilities']['status']);
        $this->assertSame(
            'supported',
            $result['source_capabilities']['capabilities']['queue_state']['status'],
        );
        $this->assertSame('pass', $result['scenario_results']['queue_state_preserved']['status']);
        $this->assertSame(
            'not_applicable',
            $result['scenario_results']['schedule_cross_upgrade_cadence_preserved']['status'],
        );
        $this->assertSame(
            'v1_embedded_runtime_no_durable_schedule_surface',
            $result['scenario_results']['schedule_cross_upgrade_cadence_preserved']['observed_outputs']['applicability']['reason_code'],
        );
        $this->assertSame(
            'not_applicable',
            $result['scenario_results']['worker_registration_projection_preserved']['status'],
        );
        $this->assertSame(
            'not_applicable',
            $result['scenario_results']['version_skew_refusal']['status'],
        );
        $this->assertSame('pass', $result['scenario_results']['cli_access_to_preupgrade_state']['status']);
        $this->assertSame('pass', $result['scenario_results']['new_v2_schedule_after_upgrade']['status']);
        $this->assertSame('pass', $result['scenario_results']['new_v2_worker_registration_after_upgrade']['status']);
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
        $this->assertSame('fail', $result['migration_plan']['guide_command_executability']['status']);
        $this->assertContains('php artisan migrate', $result['migration_plan']['commands_extracted']);
        $this->assertSame(
            'fail',
            $result['scenario_results']['documented_migration_steps_execute']['status'],
        );
        $this->assertSame(
            'missing_or_wrong_migration_guide_step',
            $result['scenario_results']['documented_migration_steps_execute']['linked_findings'][0]['finding_type'],
        );
        $this->assertSame(
            'docs',
            $result['scenario_results']['documented_migration_steps_execute']['linked_findings'][0]['owning_surface'],
        );
        $this->assertSame(
            'blocked_before_execution_by_unexecutable_public_guide_commands',
            $result['scenario_results']['documented_migration_steps_execute']['observed_outputs']['schema_or_storage_migration_output'],
        );
        $this->assertSame(
            'public_migration_guide_audit',
            $result['scenario_results']['documented_migration_steps_execute']['observed_outputs']['source'],
        );
        $this->assertStringContainsString(
            'cannot be executed verbatim',
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
        $runRecordFindings = $result['finding_links']['run_record'] ?? [];
        $this->assertContains(
            'migration_plan',
            array_column($runRecordFindings, 'missing_run_record_command_outputs'),
            'guide-audit observations are not a substitute for concrete command-output evidence from the migration runbook',
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

    public function test_runner_rejects_placeholder_required_evidence_before_passing(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the migration runner placeholder evidence gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        $evidence['scenario_results']['completed_history_preservation_and_replay']['observed_outputs']['replay_result'] =
            'not_executed_by_public_guide_audit';
        $evidence['scenario_results']['documented_migration_steps_execute']['observed_outputs']['commands_executed'] = [
            'not_executed_by_public_guide_audit',
        ];
        $evidence['history_dumps'] = [
            'status' => 'pass',
            'completed_history' => 'not_executed_by_public_guide_audit',
        ];

        $result = $this->runRunnerEvidence($nodeBinary, $evidence, 'dw-migration-placeholder-evidence-');

        $this->assertSame('non_passing', $result['outcome']);
        $this->assertSame(
            'not_covered',
            $result['scenario_results']['completed_history_preservation_and_replay']['status'],
        );
        $this->assertContains(
            'replay_result',
            $result['scenario_results']['completed_history_preservation_and_replay']['observed_outputs']['missing_required_fields'],
        );
        $this->assertSame(
            'not_covered',
            $result['scenario_results']['documented_migration_steps_execute']['status'],
        );
        $this->assertContains(
            'commands_executed',
            $result['scenario_results']['documented_migration_steps_execute']['observed_outputs']['missing_required_fields'],
        );

        $runRecordFindings = $result['finding_links']['run_record'] ?? [];
        $this->assertNotEmpty(array_filter(
            $runRecordFindings,
            static fn (array $finding): bool => ($finding['missing_run_record_field'] ?? null) === 'history_dumps',
        ));
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
            [
                'completed_workflow',
                'running_workflow_waiting_on_signal',
                'workflow_with_activity',
                'workflow_mid_activity_retry',
            ];
        $evidence['scenario_results']['latest_supported_v1_state_setup']['observed_outputs']['seeded_schedules'] =
            ['active_schedule'];
        $evidence['scenario_results']['latest_supported_v1_state_setup']['observed_outputs']['seeded_worker_registrations'] =
            ['registered_workers'];
        $evidence['scenario_results']['latest_supported_v1_state_setup']['observed_outputs']['queryable_history'] =
            ['queryable_history'];

        $result = $this->runRunnerEvidence($nodeBinary, $evidence, 'dw-migration-shallow-state-');
        $scenario = $result['scenario_results']['latest_supported_v1_state_setup'];

        $this->assertSame('non_passing', $result['outcome']);
        $this->assertSame('not_covered', $scenario['status']);
        foreach ([
            'seeded_workflows.completed_workflow',
            'seeded_workflows.running_workflow_waiting_on_signal',
            'seeded_workflows.workflow_with_activity',
            'seeded_workflows.workflow_mid_activity_retry',
            'seeded_schedules.active_schedule',
            'seeded_worker_registrations.registered_workers',
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

    public function test_runner_keeps_non_pass_state_snapshots_from_passing(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the migration runner state snapshot status gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        $evidence['preupgrade_state_snapshot'] = [
            'status' => 'fail',
            'observed_behavior' => 'v1 state APIs returned an error before observed state cells were captured',
        ];
        $evidence['postupgrade_state_snapshot'] = [
            'status' => 'fail',
            'observed_behavior' => 'v2 state APIs returned an error before observed state cells were captured',
        ];

        $result = $this->runRunnerEvidence($nodeBinary, $evidence, 'dw-migration-failed-state-snapshots-');
        $runRecordFindings = $result['finding_links']['run_record'] ?? [];

        $this->assertSame(
            'non_passing',
            $result['outcome'],
            'failed state snapshots must not allow the migration runner to emit pass',
        );
        $this->assertSame('fail', $result['preupgrade_state_snapshot']['status']);
        $this->assertSame('fail', $result['postupgrade_state_snapshot']['status']);
        foreach (['preupgrade_state_snapshot', 'postupgrade_state_snapshot'] as $field) {
            $this->assertNotEmpty(array_filter(
                $runRecordFindings,
                static fn (array $finding): bool => ($finding['missing_run_record_field'] ?? null) === $field
                    && ($finding['state_snapshot_failure'] ?? null) === 'non_pass_state_snapshot'
                    && ($finding['state_snapshot_status'] ?? null) === 'fail',
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
            $this->assertSame('complete', $record['source_capabilities']['status']);
            $this->assertSame(
                'not_applicable',
                $record['scenario_statuses']['version_skew_refusal'],
            );
            $this->assertContains('version_skew_refusal', $record['not_applicable_scenarios']);
            $this->assertSame(
                $result['version_skew_observations'],
                $record['version_skew_observations'],
            );
            $this->assertSame(
                'pass',
                $record['scenario_statuses']['latest_supported_v1_state_setup'],
            );
        } finally {
            $this->removeTree($tempRoot);
        }
    }

    public function test_runner_unwraps_host_result_payload_before_passing(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the migration runner host payload normalization.');
        }

        $evidence = [
            'migrationConformanceResult' => $this->completeRunnerEvidence(),
        ];

        $result = $this->runRunnerEvidence($nodeBinary, $evidence, 'dw-migration-wrapped-result-');

        $this->assertSame('pass', $result['outcome']);
        $this->assertSame(
            'pass',
            $result['scenario_results']['latest_supported_v1_state_setup']['status'],
        );
        $this->assertSame($this->artifactVersions(), $result['published_artifact_versions']);
        $this->assertSame($this->artifactSources(), $result['artifact_sources']);
    }

    public function test_runner_rejects_queue_continuity_that_switches_from_the_seeded_task(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise queued-task identity continuity.');
        }

        $evidence = $this->completeRunnerEvidence();
        $evidence['scenario_results']['latest_supported_v1_state_setup']['observed_outputs']['seeded_queue_state']['queued_task']['task_id'] = 'migration-seeded-task-a';

        $result = $this->runRunnerEvidence($nodeBinary, $evidence, 'dw-migration-queue-identity-switch-');
        $queueResult = $result['scenario_results']['queue_state_preserved'];

        $this->assertSame('non_passing', $result['outcome']);
        $this->assertSame('fail', $queueResult['status']);
        $this->assertSame(
            ['queued_task_identity_changed'],
            array_column($queueResult['observed_outputs']['queue_state_product_failures'], 'code'),
        );
        $this->assertCount(1, $queueResult['linked_findings']);
        $this->assertSame('workflow', $queueResult['linked_findings'][0]['owning_surface']);
        $this->assertSame('queue_state_loss', $queueResult['linked_findings'][0]['finding_type']);
        $this->assertSame($this->artifactVersions(), $queueResult['linked_findings'][0]['artifact_versions']);
    }

    public function test_runner_requires_a_non_null_finite_queue_duplication_observation(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise queued-task duplication evidence.');
        }

        foreach ([null, '', false, []] as $missingDuplicationCount) {
            $evidence = $this->completeRunnerEvidence();
            $evidence['scenario_results']['queue_state_preserved']['observed_outputs']['dequeue_or_completion_result']['duplicate_execution_count'] = $missingDuplicationCount;

            $result = $this->runRunnerEvidence($nodeBinary, $evidence, 'dw-migration-queue-duplication-gap-');
            $queueResult = $result['scenario_results']['queue_state_preserved'];

            $this->assertSame('non_passing', $result['outcome']);
            $this->assertSame('not_covered', $queueResult['status']);
            $this->assertContains(
                'dequeue_or_completion_result.duplicate_execution_count',
                $queueResult['observed_outputs']['missing_required_fields'],
            );
        }
    }

    public function test_runner_accepts_claimable_queue_disposition_with_preserved_placement(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise claimable queued-task disposition evidence.');
        }

        $evidence = $this->completeRunnerEvidence();
        $queueEvidence = &$evidence['scenario_results']['queue_state_preserved']['observed_outputs'];
        $queueEvidence['postupgrade_queue_state']['disposition'] = 'claimable';
        $queueEvidence['postupgrade_queue_state']['task_queue'] = 'migration-v1';
        $queueEvidence['dequeue_or_completion_result']['disposition'] = 'claimable';
        $queueEvidence['dequeue_or_completion_result']['task_queue'] = 'migration-v1';

        $result = $this->runRunnerEvidence($nodeBinary, $evidence, 'dw-migration-queue-claimable-');

        $this->assertSame('pass', $result['outcome']);
        $this->assertSame('pass', $result['scenario_results']['queue_state_preserved']['status']);
    }

    public function test_runner_accepts_terminal_queue_result_without_postupgrade_queue_state(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise terminal queued-task disposition evidence.');
        }

        $terminalResults = [
            'completed' => [
                'disposition' => 'completed',
            ],
            'recovered' => [
                'disposition' => 'deliberately_recovered',
                'deliberate_recovery' => true,
                'recovery_action' => 'Requeued the task once under the v2 worker runtime.',
            ],
            'refused' => [
                'disposition' => 'explicitly_refused',
                'explicit_refusal' => true,
                'refusal_reason' => 'The migrated activity type is not registered by this v2 worker.',
            ],
        ];

        foreach ($terminalResults as $terminalDisposition => $terminalResult) {
            $evidence = $this->completeRunnerEvidence();
            $queueEvidence = &$evidence['scenario_results']['queue_state_preserved']['observed_outputs'];
            $queueEvidence['dequeue_or_completion_result'] = array_replace(
                $queueEvidence['dequeue_or_completion_result'],
                $terminalResult,
            );
            unset($queueEvidence['postupgrade_queue_state']);

            $result = $this->runRunnerEvidence($nodeBinary, $evidence, 'dw-migration-queue-terminal-no-post-state-');

            $this->assertSame('pass', $result['outcome'], $terminalDisposition);
            $this->assertSame(
                'pass',
                $result['scenario_results']['queue_state_preserved']['status'],
                $terminalDisposition,
            );
        }
    }

    public function test_runner_requires_postupgrade_queue_state_for_claimable_disposition(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise claimable queued-task disposition evidence.');
        }

        $evidence = $this->completeRunnerEvidence();
        $queueEvidence = &$evidence['scenario_results']['queue_state_preserved']['observed_outputs'];
        $queueEvidence['dequeue_or_completion_result']['disposition'] = 'claimable';
        unset($queueEvidence['postupgrade_queue_state']);

        $result = $this->runRunnerEvidence($nodeBinary, $evidence, 'dw-migration-queue-claimable-no-post-state-');
        $queueResult = $result['scenario_results']['queue_state_preserved'];

        $this->assertSame('non_passing', $result['outcome']);
        $this->assertSame('not_covered', $queueResult['status']);
        $this->assertContains('postupgrade_queue_state', $queueResult['observed_outputs']['missing_required_fields']);
        $this->assertContains('postupgrade_queue_state.task_id', $queueResult['observed_outputs']['missing_required_fields']);
        $this->assertContains('postupgrade_queue_state.task_queue', $queueResult['observed_outputs']['missing_required_fields']);
    }

    public function test_runner_rejects_terminal_availability_as_preupgrade_queue_evidence(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise pre-upgrade queued-task availability evidence.');
        }

        foreach (['completed', 'recovered', 'refused'] as $terminalAvailability) {
            $evidence = $this->completeRunnerEvidence();
            $evidence['scenario_results']['latest_supported_v1_state_setup']['observed_outputs']['seeded_queue_state']['queued_task']['availability_state'] = $terminalAvailability;
            $evidence['scenario_results']['queue_state_preserved']['observed_outputs']['preupgrade_queue_state']['availability_state'] = $terminalAvailability;

            $result = $this->runRunnerEvidence($nodeBinary, $evidence, 'dw-migration-queue-terminal-preupgrade-');
            $setupResult = $result['scenario_results']['latest_supported_v1_state_setup'];
            $queueResult = $result['scenario_results']['queue_state_preserved'];

            $this->assertSame('non_passing', $result['outcome']);
            $this->assertSame('not_covered', $setupResult['status']);
            $this->assertContains(
                'seeded_queue_state.queued_task.availability_state.queued',
                $setupResult['observed_outputs']['missing_required_fields'],
            );
            $this->assertSame('not_covered', $queueResult['status']);
            $this->assertContains(
                'preupgrade_queue_state.availability_state.queued',
                $queueResult['observed_outputs']['missing_required_fields'],
            );
        }
    }

    public function test_runner_accepts_transport_specific_preupgrade_queue_availability(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise transport-specific queued-task availability evidence.');
        }

        foreach (['delayed', 'reserved'] as $queuedAvailability) {
            $evidence = $this->completeRunnerEvidence();
            $evidence['scenario_results']['latest_supported_v1_state_setup']['observed_outputs']['seeded_queue_state']['queued_task']['availability_state'] = $queuedAvailability;
            $evidence['scenario_results']['queue_state_preserved']['observed_outputs']['preupgrade_queue_state']['availability_state'] = $queuedAvailability;

            $result = $this->runRunnerEvidence($nodeBinary, $evidence, 'dw-migration-queue-transport-preupgrade-');

            $this->assertSame('pass', $result['outcome'], $queuedAvailability);
            $this->assertSame(
                'pass',
                $result['scenario_results']['latest_supported_v1_state_setup']['status'],
                $queuedAvailability,
            );
            $this->assertSame(
                'pass',
                $result['scenario_results']['queue_state_preserved']['status'],
                $queuedAvailability,
            );
        }
    }

    public function test_runner_requires_refusal_to_be_explicit_and_operator_visible(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise refused queued-task disposition evidence.');
        }

        $evidence = $this->completeRunnerEvidence();
        $queueEvidence = &$evidence['scenario_results']['queue_state_preserved']['observed_outputs'];
        $queueEvidence['postupgrade_queue_state']['disposition'] = 'refused';
        $queueEvidence['dequeue_or_completion_result']['disposition'] = 'refused';

        $result = $this->runRunnerEvidence($nodeBinary, $evidence, 'dw-migration-queue-implicit-refusal-');
        $this->assertSame('fail', $result['scenario_results']['queue_state_preserved']['status']);
        $this->assertContains(
            'queued_task_refusal_not_explicit',
            array_column(
                $result['scenario_results']['queue_state_preserved']['observed_outputs']['queue_state_product_failures'],
                'code',
            ),
        );

        $queueEvidence['dequeue_or_completion_result']['disposition'] = 'explicitly_refused';
        $queueEvidence['dequeue_or_completion_result']['explicit_refusal'] = true;
        $queueEvidence['dequeue_or_completion_result']['refusal_reason'] = 'The migrated activity type is not registered by this v2 worker.';

        $result = $this->runRunnerEvidence($nodeBinary, $evidence, 'dw-migration-queue-explicit-refusal-');
        $this->assertSame('pass', $result['outcome']);
        $this->assertSame('pass', $result['scenario_results']['queue_state_preserved']['status']);
    }

    public function test_runner_normalizes_runbook_shaped_host_evidence_before_passing(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the migration runner runbook normalization.');
        }

        $complete = $this->completeRunnerEvidence();
        $scenarioResults = $complete['scenario_results'];
        $runbook = [
            'outcome' => 'pass',
            'startedAt' => $complete['started_at'],
            'finishedAt' => $complete['finished_at'],
            'localProductSourceCheckoutsUsed' => false,
            'pinnedVersions' => [
                'v1' => [
                    'server' => $complete['published_artifact_versions']['server-v1'],
                    'cli' => $complete['published_artifact_versions']['cli-v1'],
                    'workflow' => $complete['published_artifact_versions']['workflow-php-v1'],
                    'waterline' => $complete['published_artifact_versions']['waterline-v1'],
                    'sampleApp' => $complete['published_artifact_versions']['sample-app-v1'],
                ],
                'v2' => [
                    'server' => $complete['published_artifact_versions']['server-v2'],
                    'cli' => $complete['published_artifact_versions']['cli-v2'],
                    'workflow' => $complete['published_artifact_versions']['workflow-php-v2'],
                    'pythonSdk' => $complete['published_artifact_versions']['sdk-python'],
                    'waterline' => $complete['published_artifact_versions']['waterline-v2'],
                ],
            ],
            'artifactSources' => $complete['artifact_sources'],
            'realisticV1StateSnapshot' => $scenarioResults['latest_supported_v1_state_setup']['observed_outputs'],
            'migrationGuideExecution' => $scenarioResults['documented_migration_steps_execute']['observed_outputs'],
            'completedHistoryReplay' => $scenarioResults['completed_history_preservation_and_replay']['observed_outputs'],
            'inFlightWorkflowProgress' => $scenarioResults['in_flight_workflow_progress_preserved']['observed_outputs'],
            'midActivityRetryPreserved' => $scenarioResults['mid_activity_retry_preserved']['observed_outputs'],
            'queueStatePreserved' => $scenarioResults['queue_state_preserved']['observed_outputs'],
            'scheduleCrossUpgradeCadencePreserved' => $scenarioResults['schedule_cross_upgrade_cadence_preserved']['observed_outputs'],
            'workerRegistrationProjectionPreserved' => $scenarioResults['worker_registration_projection_preserved']['observed_outputs'],
            'waterlineOperatorVisibilityPreserved' => $scenarioResults['waterline_operator_visibility_preserved']['observed_outputs'],
            'cliAccessToPreupgradeState' => $scenarioResults['cli_access_to_preupgrade_state']['observed_outputs'],
            'newV2WorkflowStartAfterUpgrade' => $scenarioResults['new_v2_workflow_start_after_upgrade']['observed_outputs'],
            'newV2ScheduleAfterUpgrade' => $scenarioResults['new_v2_schedule_after_upgrade']['observed_outputs'],
            'newV2WorkerRegistrationAfterUpgrade' => $scenarioResults['new_v2_worker_registration_after_upgrade']['observed_outputs'],
            'rollbackResult' => $scenarioResults['rollback_contract_verified']['observed_outputs'],
            'versionSkewObservations' => $scenarioResults['version_skew_refusal']['observed_outputs'],
            'preupgradeStateSnapshot' => $complete['preupgrade_state_snapshot'],
            'postupgradeStateSnapshot' => $complete['postupgrade_state_snapshot'],
            'historyDumps' => $complete['history_dumps'],
            'activityAttempts' => $complete['activity_attempts'],
            'scheduleTicks' => $complete['schedule_ticks'],
            'workerRegistrationObservations' => $complete['worker_registration_observations'],
            'cliObservations' => $complete['cli_observations'],
            'waterlineObservations' => $complete['waterline_observations'],
            'rollbackObservations' => $complete['rollback_observations'],
            'storageConnectionSmoke' => $complete['storage_connection_smoke'],
        ];

        $result = $this->runRunnerEvidence($nodeBinary, $runbook, 'dw-migration-runbook-shaped-');

        $this->assertSame('pass', $result['outcome']);
        $this->assertSame($this->artifactVersions(), $result['published_artifact_versions']);
        $this->assertSame(
            'pass',
            $result['scenario_results']['documented_migration_steps_execute']['status'],
        );
        $this->assertSame(
            $scenarioResults['documented_migration_steps_execute']['observed_outputs']['commands_executed'],
            $result['scenario_results']['documented_migration_steps_execute']['observed_outputs']['commands_executed'],
        );
        $this->assertSame('pass', $result['scenario_results']['queue_state_preserved']['status']);
        $this->assertSame('pass', $result['scenario_results']['new_v2_schedule_after_upgrade']['status']);
        $this->assertSame('pass', $result['scenario_results']['new_v2_worker_registration_after_upgrade']['status']);
        $this->assertSame(
            $scenarioResults['queue_state_preserved']['observed_outputs']['postupgrade_queue_state'],
            $result['scenario_results']['queue_state_preserved']['observed_outputs']['postupgrade_queue_state'],
        );
        $this->assertSame(
            $scenarioResults['new_v2_schedule_after_upgrade']['observed_outputs']['typed_response_contracts'],
            $result['scenario_results']['new_v2_schedule_after_upgrade']['observed_outputs']['typed_response_contracts'],
        );
        $this->assertSame(
            $scenarioResults['new_v2_worker_registration_after_upgrade']['observed_outputs']['typed_response_contracts'],
            $result['scenario_results']['new_v2_worker_registration_after_upgrade']['observed_outputs']['typed_response_contracts'],
        );
    }

    public function test_runner_keeps_source_absence_and_target_control_plane_runbook_evidence(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the migration runner runbook normalization.');
        }

        $complete = $this->completeRunnerEvidence();
        $scenarioResults = $complete['scenario_results'];
        $runbook = $this->completeRunbookEvidence($complete, $complete['migration_plan']);
        unset($runbook['scheduleTicks'], $runbook['workerRegistrationObservations']);

        $runbook['scheduleCrossUpgradeCadencePreserved'] = [
            'status' => 'not_applicable',
            'applicability' => [
                'status' => 'not_applicable',
                'source_capability' => 'schedule',
                'reason_code' => 'v1_embedded_runtime_no_durable_schedule_surface',
                'durable_state_mutation_attempted' => false,
            ],
        ];
        $runbook['workerRegistrationProjectionPreserved'] = [
            'status' => 'not_applicable',
            'applicability' => [
                'status' => 'not_applicable',
                'source_capability' => 'worker_registration',
                'reason_code' => 'v1_embedded_runtime_no_worker_registration_projection',
                'durable_state_mutation_attempted' => false,
            ],
        ];

        $result = $this->runRunnerEvidence($nodeBinary, $runbook, 'dw-migration-runbook-target-control-plane-');

        $this->assertSame('pass', $result['outcome']);
        $this->assertSame(
            'not_applicable',
            $result['scenario_results']['schedule_cross_upgrade_cadence_preserved']['status'],
        );
        $this->assertSame(
            'not_applicable',
            $result['scenario_results']['worker_registration_projection_preserved']['status'],
        );
        $this->assertSame('pass', $result['scenario_results']['new_v2_schedule_after_upgrade']['status']);
        $this->assertSame('pass', $result['scenario_results']['new_v2_worker_registration_after_upgrade']['status']);
        $this->assertSame('pass', $result['schedule_ticks']['status']);
        $this->assertSame('pass', $result['worker_registration_observations']['status']);
        $this->assertSame(
            $scenarioResults['new_v2_schedule_after_upgrade']['observed_outputs']['command_outputs'],
            $result['schedule_ticks']['command_outputs'],
        );
        $this->assertSame(
            $scenarioResults['new_v2_worker_registration_after_upgrade']['observed_outputs']['command_outputs'],
            $result['worker_registration_observations']['command_outputs'],
        );
        $this->assertSame(
            'v1_embedded_runtime_no_durable_schedule_surface',
            $result['schedule_ticks']['applicability']['reason_code'],
        );
        $this->assertSame(
            'v1_embedded_runtime_no_worker_registration_projection',
            $result['worker_registration_observations']['applicability']['reason_code'],
        );
    }

    public function test_runner_promotes_migration_plan_command_outputs_to_documented_steps(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the migration runner runbook normalization.');
        }

        $complete = $this->completeRunnerEvidence();
        $scenarioResults = $complete['scenario_results'];
        $documentedOutputs = $scenarioResults['documented_migration_steps_execute']['observed_outputs'];
        $commands = $documentedOutputs['commands_executed'];
        $commandOutputs = [
            [
                'public_guide_command' => $commands[0],
                'command' => $commands[0],
                'status' => 'pass',
                'exit_code' => 0,
                'duration_ms' => 1280,
                'stdout' => 'Updated durable-workflow/workflow to 2.0.0-alpha.185.',
                'stderr' => '',
            ],
            [
                'public_guide_command' => $commands[1],
                'command' => $commands[1],
                'status' => 'pass',
                'exit_code' => 0,
                'duration_ms' => 430,
                'stdout' => 'Migrated workflow storage tables.',
                'stderr' => '',
            ],
            [
                'public_guide_command' => $commands[2],
                'command' => $commands[2],
                'status' => 'pass',
                'exit_code' => 0,
                'duration_ms' => 95,
                'stdout' => 'Queue restart signal sent.',
                'stderr' => '',
            ],
        ];
        $runbook = [
            'outcome' => 'pass',
            'startedAt' => $complete['started_at'],
            'finishedAt' => $complete['finished_at'],
            'localProductSourceCheckoutsUsed' => false,
            'pinnedVersions' => [
                'v1' => [
                    'server' => $complete['published_artifact_versions']['server-v1'],
                    'cli' => $complete['published_artifact_versions']['cli-v1'],
                    'workflow' => $complete['published_artifact_versions']['workflow-php-v1'],
                    'waterline' => $complete['published_artifact_versions']['waterline-v1'],
                    'sampleApp' => $complete['published_artifact_versions']['sample-app-v1'],
                ],
                'v2' => [
                    'server' => $complete['published_artifact_versions']['server-v2'],
                    'cli' => $complete['published_artifact_versions']['cli-v2'],
                    'workflow' => $complete['published_artifact_versions']['workflow-php-v2'],
                    'pythonSdk' => $complete['published_artifact_versions']['sdk-python'],
                    'waterline' => $complete['published_artifact_versions']['waterline-v2'],
                ],
            ],
            'artifactSources' => $complete['artifact_sources'],
            'realisticV1StateSnapshot' => $scenarioResults['latest_supported_v1_state_setup']['observed_outputs'],
            'migrationPlan' => [
                'source' => 'published_migration_guide_execution',
                'migration_guide_revision' => $documentedOutputs['migration_guide_revision'],
                'guide_command_executability' => $documentedOutputs['guide_command_executability'],
                'command_outputs' => $commandOutputs,
            ],
            'completedHistoryReplay' => $scenarioResults['completed_history_preservation_and_replay']['observed_outputs'],
            'inFlightWorkflowProgress' => $scenarioResults['in_flight_workflow_progress_preserved']['observed_outputs'],
            'midActivityRetryPreserved' => $scenarioResults['mid_activity_retry_preserved']['observed_outputs'],
            'queueStatePreserved' => $scenarioResults['queue_state_preserved']['observed_outputs'],
            'scheduleCrossUpgradeCadencePreserved' => $scenarioResults['schedule_cross_upgrade_cadence_preserved']['observed_outputs'],
            'workerRegistrationProjectionPreserved' => $scenarioResults['worker_registration_projection_preserved']['observed_outputs'],
            'waterlineOperatorVisibilityPreserved' => $scenarioResults['waterline_operator_visibility_preserved']['observed_outputs'],
            'cliAccessToPreupgradeState' => $scenarioResults['cli_access_to_preupgrade_state']['observed_outputs'],
            'newV2WorkflowStartAfterUpgrade' => $scenarioResults['new_v2_workflow_start_after_upgrade']['observed_outputs'],
            'newV2ScheduleAfterUpgrade' => $scenarioResults['new_v2_schedule_after_upgrade']['observed_outputs'],
            'newV2WorkerRegistrationAfterUpgrade' => $scenarioResults['new_v2_worker_registration_after_upgrade']['observed_outputs'],
            'rollbackResult' => $scenarioResults['rollback_contract_verified']['observed_outputs'],
            'versionSkewObservations' => $scenarioResults['version_skew_refusal']['observed_outputs'],
            'preupgradeStateSnapshot' => $complete['preupgrade_state_snapshot'],
            'postupgradeStateSnapshot' => $complete['postupgrade_state_snapshot'],
            'historyDumps' => $complete['history_dumps'],
            'activityAttempts' => $complete['activity_attempts'],
            'scheduleTicks' => $complete['schedule_ticks'],
            'workerRegistrationObservations' => $complete['worker_registration_observations'],
            'cliObservations' => $complete['cli_observations'],
            'waterlineObservations' => $complete['waterline_observations'],
            'rollbackObservations' => $complete['rollback_observations'],
            'storageConnectionSmoke' => $complete['storage_connection_smoke'],
        ];

        $result = $this->runRunnerEvidence($nodeBinary, $runbook, 'dw-migration-plan-command-outputs-');
        $observedOutputs = $result['scenario_results']['documented_migration_steps_execute']['observed_outputs'];

        $this->assertSame('pass', $result['outcome']);
        $this->assertSame('pass', $result['scenario_results']['documented_migration_steps_execute']['status']);
        $this->assertArrayNotHasKey('missing_required_fields', $observedOutputs);
        $this->assertSame($commands, $observedOutputs['commands_executed']);
        $this->assertSame([0, 0, 0], $observedOutputs['exit_codes']);
        $this->assertSame($documentedOutputs['command_timings'], $observedOutputs['command_timings']);
        $this->assertSame($commandOutputs, $observedOutputs['command_outputs']);
        $this->assertSame(
            $commandOutputs,
            $observedOutputs['schema_or_storage_migration_output']['command_outputs'],
        );
    }

    public function test_runner_rejects_malformed_direct_migration_plan_command_outputs(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the migration runner command-output gate.');
        }

        $complete = $this->completeRunnerEvidence();
        $documentedOutputs = $complete['scenario_results']['documented_migration_steps_execute']['observed_outputs'];
        $runbook = $this->completeRunbookEvidence($complete, [
            'source' => 'published_migration_guide_execution',
            'migration_guide_revision' => $documentedOutputs['migration_guide_revision'],
            'guide_command_executability' => $documentedOutputs['guide_command_executability'],
            'command_outputs' => ['captured'],
        ]);

        $result = $this->runRunnerEvidence($nodeBinary, $runbook, 'dw-migration-malformed-command-outputs-');
        $scenario = $result['scenario_results']['documented_migration_steps_execute'];
        $observedOutputs = $scenario['observed_outputs'];

        $this->assertSame('non_passing', $result['outcome']);
        $this->assertSame('not_covered', $scenario['status']);
        $this->assertArrayNotHasKey('command_outputs', $observedOutputs);
        $this->assertArrayNotHasKey('schema_or_storage_migration_output', $observedOutputs);
        $this->assertContains('commands_executed', $observedOutputs['missing_required_fields']);
        $this->assertContains('exit_codes', $observedOutputs['missing_required_fields']);
        $this->assertContains('command_timings', $observedOutputs['missing_required_fields']);
        $this->assertContains('schema_or_storage_migration_output', $observedOutputs['missing_required_fields']);
    }

    public function test_runner_rejects_malformed_direct_runbook_section_command_outputs(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the migration runner command-output gate.');
        }

        $complete = $this->completeRunnerEvidence();
        $runbook = $this->completeRunbookEvidence($complete, $complete['migration_plan']);
        $runbook['historyDumps'] = [
            'completed' => true,
            'running' => true,
            'command_outputs' => ['captured'],
        ];

        $result = $this->runRunnerEvidence($nodeBinary, $runbook, 'dw-migration-malformed-section-command-outputs-');
        $runRecordFindings = $result['finding_links']['run_record'] ?? [];

        $this->assertSame('non_passing', $result['outcome']);
        $this->assertContains(
            'history_dumps',
            array_column($runRecordFindings, 'missing_run_record_command_outputs'),
        );
        $this->assertSame(
            'pass',
            $result['scenario_results']['completed_history_preservation_and_replay']['status'],
        );
    }

    public function test_runner_promotes_sectioned_runbook_command_outputs_to_required_record_sections(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the migration runner command-output sidecar gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        foreach ([
            'migration_plan',
            'preupgrade_state_snapshot',
            'postupgrade_state_snapshot',
            'history_dumps',
            'activity_attempts',
            'schedule_ticks',
            'worker_registration_observations',
            'cli_observations',
            'waterline_observations',
            'rollback_observations',
            'version_skew_observations',
            'storage_connection_smoke',
        ] as $field) {
            unset($evidence[$field]);
        }

        $stateOutputs = function (string $phase): array {
            return array_map(
                fn (string $stateKind): array => $this->commandOutput('dw migration:state '.$phase.' '.$stateKind) + [
                    'state_kind' => $stateKind,
                    'phase' => $phase,
                ],
                MigrationRuntimeContract::manifest()['required_matrix']['state_kinds'],
            );
        };

        $evidence['runbookCommandOutputs'] = [
            'migrationPlan' => $evidence['scenario_results']['documented_migration_steps_execute']['observed_outputs']['command_outputs'],
            'preupgradeStateSnapshot' => $stateOutputs('preupgrade'),
            'postupgradeStateSnapshot' => $stateOutputs('postupgrade'),
            'historyDumps' => [$this->commandOutput('dw workflow:history-export migration-completed')],
            'activityAttempts' => [$this->commandOutput('dw workflow:describe migration-retrying-activity')],
            'scheduleTicks' => [$this->commandOutput('dw schedule:list --json')],
            'workerRegistrationObservations' => [$this->commandOutput('GET /api/workers?task_queue=migration-v1')],
            'cliObservations' => [$this->commandOutput('dw workflow:describe migration-completed --json')],
            'waterlineObservations' => [$this->commandOutput('GET /waterline/api/instances/migration-completed')],
            'rollbackResult' => [$this->commandOutput('php artisan queue:restart')],
            'versionSkewRefusal' => [$this->commandOutput('dw workflow:list --server http://server-v1')],
            'storageConnectionSmoke' => [$this->commandOutput('php artisan migrate:status')],
        ];

        $result = $this->runRunnerEvidence($nodeBinary, $evidence, 'dw-migration-sectioned-command-outputs-');

        $this->assertSame('pass', $result['outcome']);
        foreach ([
            'migration_plan',
            'preupgrade_state_snapshot',
            'postupgrade_state_snapshot',
            'history_dumps',
            'activity_attempts',
            'schedule_ticks',
            'worker_registration_observations',
            'cli_observations',
            'waterline_observations',
            'rollback_observations',
            'version_skew_observations',
            'storage_connection_smoke',
        ] as $field) {
            $this->assertArrayHasKey('command_outputs', $result[$field]);
            $this->assertNotEmpty($result[$field]['command_outputs']);
            $this->assertArrayHasKey($field, $result['runbook_command_outputs']);
        }
        $this->assertArrayHasKey('observed_states', $result['preupgrade_state_snapshot']);
        $this->assertArrayHasKey('observed_states', $result['postupgrade_state_snapshot']);
        $this->assertArrayNotHasKey('run_record', $result['finding_links']);
    }

    public function test_runner_promotes_capability_aware_scenarios_to_required_record_sections(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the migration runner scenario command-output gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        foreach ([
            'migration_plan',
            'preupgrade_state_snapshot',
            'postupgrade_state_snapshot',
            'history_dumps',
            'activity_attempts',
            'schedule_ticks',
            'worker_registration_observations',
            'cli_observations',
            'waterline_observations',
            'rollback_observations',
            'version_skew_observations',
            'storage_connection_smoke',
        ] as $field) {
            unset($evidence[$field]);
        }

        unset(
            $evidence['scenario_results']['latest_supported_v1_state_setup']['observed_outputs']['seeded_schedules'],
            $evidence['scenario_results']['latest_supported_v1_state_setup']['observed_outputs']['seeded_worker_registrations'],
            $evidence['scenario_results']['schedule_cross_upgrade_cadence_preserved'],
            $evidence['scenario_results']['worker_registration_projection_preserved'],
            $evidence['scenario_results']['version_skew_refusal'],
        );

        $evidence['scenario_results']['storage_connection_smoke'] = [
            'status' => 'pass',
            'observed_outputs' => [
                'storage_connection' => 'workflow_storage',
                'command_outputs' => [
                    $this->commandOutput('php artisan migrate:status --database=workflow_storage'),
                ],
            ],
        ];

        $result = $this->runRunnerEvidence($nodeBinary, $evidence, 'dw-migration-scenario-command-outputs-');
        $requiredStateKinds = MigrationRuntimeContract::manifest()['required_matrix']['state_kinds'];

        $this->assertSame('pass', $result['outcome']);
        $this->assertArrayNotHasKey(
            'run_record',
            $result['finding_links'],
            'scenario-attached command outputs must satisfy the queryable migration run record sections',
        );

        foreach ([
            'migration_plan',
            'preupgrade_state_snapshot',
            'postupgrade_state_snapshot',
            'history_dumps',
            'activity_attempts',
            'schedule_ticks',
            'worker_registration_observations',
            'cli_observations',
            'waterline_observations',
            'rollback_observations',
            'storage_connection_smoke',
        ] as $field) {
            $this->assertSame('pass', $result[$field]['status']);
            $this->assertArrayHasKey('command_outputs', $result[$field]);
            $this->assertNotEmpty($result[$field]['command_outputs']);
            $this->assertArrayHasKey($field, $result['runbook_command_outputs']);
        }

        $skew = $result['scenario_results']['version_skew_refusal'];
        $this->assertSame('not_applicable', $skew['status']);
        $this->assertSame(
            'source_skew_endpoints_not_exposed',
            $skew['observed_outputs']['operator_visible_reason']['code'],
        );
        foreach ($skew['observed_outputs']['applicability_evidence'] as $cell) {
            $this->assertSame('not_applicable', $cell['status']);
            $this->assertTrue($cell['preflight_refusal']);
            $this->assertFalse($cell['durable_state_mutation_attempted']);
        }
        $this->assertArrayNotHasKey('command_outputs', $result['version_skew_observations']);
        $this->assertArrayNotHasKey('version_skew_observations', $result['runbook_command_outputs']);

        $preupgradeKinds = array_values(array_unique(array_column(
            $result['preupgrade_state_snapshot']['observed_states'],
            'state_kind',
        )));
        $postupgradeKinds = array_values(array_unique(array_column(
            $result['postupgrade_state_snapshot']['observed_states'],
            'state_kind',
        )));

        foreach (['completed_history', 'in_flight_workflow', 'retrying_activity', 'queue_state'] as $stateKind) {
            $this->assertContains($stateKind, $preupgradeKinds);
        }
        $this->assertNotContains('schedule', $preupgradeKinds);
        $this->assertNotContains('worker_registration', $preupgradeKinds);

        foreach ($requiredStateKinds as $stateKind) {
            $this->assertContains($stateKind, $postupgradeKinds);
        }

        $this->assertSame(
            'not_applicable',
            $result['scenario_results']['schedule_cross_upgrade_cadence_preserved']['status'],
        );
        $this->assertSame(
            'not_applicable',
            $result['scenario_results']['worker_registration_projection_preserved']['status'],
        );
        $this->assertSame(
            'not_applicable',
            $result['scenario_results']['version_skew_refusal']['status'],
        );

        $preupgradeSources = array_column($result['preupgrade_state_snapshot']['observed_states'], 'source_field');
        $postupgradeSources = array_column($result['postupgrade_state_snapshot']['observed_states'], 'source_field');
        $this->assertContains('seeded_queue_state.queued_task', $preupgradeSources);
        $this->assertContains('queue_state_preserved.preupgrade_queue_state', $preupgradeSources);
        $this->assertContains('queue_state_preserved.postupgrade_queue_state', $postupgradeSources);
        $this->assertContains('new_v2_schedule_after_upgrade', $postupgradeSources);
        $this->assertContains('new_v2_worker_registration_after_upgrade', $postupgradeSources);

        $this->assertSame(
            'scenario_result.documented_migration_steps_execute',
            $result['migration_plan']['source'],
        );
        $this->assertSame(
            'scenario_result.storage_connection_smoke',
            $result['storage_connection_smoke']['source'],
        );
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
     * @param array<string, string> $artifactVersions
     *
     * @return array<string, mixed>
     */
    private function focusedWorkerRegistrationPlan(array $artifactVersions): array
    {
        $workerId = 'migration-v2-worker-20260710';
        $namespace = 'migration-conformance';
        $taskQueue = 'migration-v2-registration-20260710';
        $registrationRequest = [
            'worker_id' => $workerId,
            'namespace' => $namespace,
            'task_queue' => $taskQueue,
            'runtime' => 'php',
            'sdk_version' => $artifactVersions['workflow-php-v2'],
            'build_id' => 'migration-v2-build',
            'supported_workflow_types' => ['migration.worker.registration.probe'],
            'capabilities' => ['workflow_tasks'],
        ];
        $projection = $this->focusedWorkerProjection($artifactVersions);
        $protocol = [
            'protocol_version' => '1.13',
            'server_capabilities' => ['poll_status' => true],
        ];
        $operation = static fn (
            string $endpoint,
            array $request,
            array $body,
            int $httpStatus = 200,
        ): array => [
            'command' => 'printf "%s\\n" '.escapeshellarg(json_encode([
                'http_status' => $httpStatus,
                'body' => $body,
            ], JSON_THROW_ON_ERROR)),
            'endpoint' => $endpoint,
            'request' => $request,
            'http_status' => $httpStatus,
        ];

        return [
            'source' => 'published_artifact_foundation_plan',
            'new_v2_worker_registration_after_upgrade' => [
                'worker_id' => $workerId,
                'namespace' => $namespace,
                'task_queue' => $taskQueue,
                'unique_task_queue' => true,
                'registration_request' => $operation(
                    'POST /api/worker/register',
                    $registrationRequest,
                    [
                        'registered' => true,
                        'worker_id' => $workerId,
                        'namespace' => $namespace,
                        'task_queue' => $taskQueue,
                    ] + $protocol,
                    201,
                ),
                'operator_api_response' => $operation(
                    'GET /api/workers/'.$workerId,
                    ['worker_id' => $workerId, 'namespace' => $namespace],
                    $projection,
                ),
                'cli_worker_projection' => $operation(
                    'dw worker:list --task-queue='.$taskQueue.' --output=json',
                    ['namespace' => $namespace, 'task_queue' => $taskQueue],
                    ['workers' => [$projection]],
                ),
                'polling_result' => $operation(
                    'POST /api/worker/workflow-tasks/poll',
                    [
                        'worker_id' => $workerId,
                        'task_queue' => $taskQueue,
                        'poll_request_id' => 'migration-v2-registration-poll-20260710',
                        'timeout_seconds' => 0,
                    ],
                    ['task' => null, 'poll_status' => 'empty'] + $protocol,
                ),
            ],
        ];
    }

    /**
     * @param array<string, string> $artifactVersions
     *
     * @return array<string, mixed>
     */
    private function focusedWorkerProjection(array $artifactVersions): array
    {
        return [
            'worker_id' => 'migration-v2-worker-20260710',
            'namespace' => 'migration-conformance',
            'task_queue' => 'migration-v2-registration-20260710',
            'runtime' => 'php',
            'sdk_version' => $artifactVersions['workflow-php-v2'],
            'build_id' => 'migration-v2-build',
            'capabilities' => ['workflow_tasks'],
            'status' => 'active',
            'last_heartbeat_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'task_slots' => [
                'workflow_available' => 2,
                'activity_available' => 1,
                'session_available' => 1,
                'workflow_capacity' => 2,
                'activity_capacity' => 1,
                'session_capacity' => 1,
            ],
        ];
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

        $queuedTaskIdentity = [
            'task_id' => 'migration-queued-activity',
            'workflow_id' => 'migration-queue-holder',
            'activity_id' => 'migration-queued-activity-call',
            'activity_type' => 'migration_sample_activity',
        ];

        $scenarioResults['cli_access_to_preupgrade_state']['observed_outputs']['typed_response_contracts'] = [
            'cli' => ['schema' => 'durable-workflow.cli.workflow-response.v2'],
            'operator_api' => ['schema' => 'durable-workflow.operator.workflow-response.v2'],
        ];
        $scenarioResults['new_v2_schedule_after_upgrade']['observed_outputs']['typed_response_contracts'] = [
            'cli' => ['schema' => 'durable-workflow.cli.schedule-response.v2'],
            'operator_api' => ['schema' => 'durable-workflow.operator.schedule-response.v2'],
            'schedule' => ['type' => 'schedule', 'schema' => 'durable-workflow.schedule.v2'],
        ];
        $scenarioResults['new_v2_worker_registration_after_upgrade']['observed_outputs']['typed_response_contracts'] = [
            'cli' => ['schema' => 'durable-workflow.cli.worker-projection.v2'],
            'operator_api' => ['schema' => 'durable-workflow.operator.worker-response.v2'],
            'worker_registration' => ['type' => 'worker_registration', 'schema' => 'durable-workflow.worker-registration.v2'],
            'worker_poll' => ['type' => 'worker_task_poll', 'schema' => 'durable-workflow.worker-task-poll.v2'],
        ];
        $workerProjection = [
            'worker_id' => 'migration-v2-worker',
            'namespace' => 'migration-conformance',
            'task_queue' => 'migration-v2-registration',
            'status' => 'active',
            'last_heartbeat_at' => '2026-05-31T22:40:19Z',
            'task_slots' => [
                'workflow_available' => 2,
                'activity_available' => 1,
                'session_available' => 1,
                'workflow_capacity' => 2,
                'activity_capacity' => 1,
                'session_capacity' => 1,
            ],
            'runtime' => 'php',
            'sdk_version' => $this->artifactVersions()['workflow-php-v2'],
            'build_id' => 'migration-v2-build',
            'capabilities' => ['workflow_tasks'],
        ];
        $operationTimestamp = [
            'started_at' => '2026-05-31T22:40:18Z',
            'finished_at' => '2026-05-31T22:40:19Z',
        ];
        $scenarioResults['new_v2_worker_registration_after_upgrade']['observed_outputs'] = [
            ...$scenarioResults['new_v2_worker_registration_after_upgrade']['observed_outputs'],
            'worker_id' => $workerProjection['worker_id'],
            'namespace' => $workerProjection['namespace'],
            'task_queue' => $workerProjection['task_queue'],
            'unique_task_queue' => true,
            'task_queue_projection' => $workerProjection,
            'cli_worker_projection' => $workerProjection,
            'protocol_metadata' => [
                'registration' => ['protocol_version' => '1.13'],
                'poll' => ['protocol_version' => '1.13'],
                'operator_api' => ['runtime' => 'php'],
                'cli' => ['runtime' => 'php'],
            ],
            'freshness' => [
                'stale_after_seconds' => 300,
                'operator_api' => ['valid' => true, 'last_heartbeat_at' => $workerProjection['last_heartbeat_at']],
                'cli' => ['valid' => true, 'last_heartbeat_at' => $workerProjection['last_heartbeat_at']],
            ],
            'polling_result' => [
                'request' => ['worker_id' => $workerProjection['worker_id'], 'task_queue' => $workerProjection['task_queue']],
                'response' => ['poll_status' => 'empty', 'task' => null],
                'exit_code' => 0,
                ...$operationTimestamp,
            ],
            'request_response_evidence' => [
                'registration' => ['request' => 'POST /api/worker/register', 'response' => ['registered' => true], 'http_status' => 201, 'response_source' => 'command_stdout_json', 'response_observed_from_command_stdout' => true],
                'operator_api' => ['request' => 'GET /api/workers/migration-v2-worker', 'response' => $workerProjection, 'http_status' => 200, 'response_source' => 'command_stdout_json', 'response_observed_from_command_stdout' => true],
                'cli' => ['request' => 'dw worker:list --output=json', 'response' => ['workers' => [$workerProjection]], 'response_source' => 'command_stdout_json', 'response_observed_from_command_stdout' => true],
                'poll' => ['request' => 'POST /api/worker/workflow-tasks/poll', 'response' => ['poll_status' => 'empty'], 'http_status' => 200, 'response_source' => 'command_stdout_json', 'response_observed_from_command_stdout' => true],
            ],
            'exit_codes' => ['registration' => 0, 'operator_api' => 0, 'cli' => 0, 'poll' => 0],
            'timestamps' => [
                'registration' => $operationTimestamp,
                'operator_api' => $operationTimestamp,
                'cli' => $operationTimestamp,
                'poll' => $operationTimestamp,
            ],
        ];

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
            'seeded_queue_state' => [
                'queued_task' => $queuedTaskIdentity + [
                    'task_queue' => 'migration-v1',
                    'availability_state' => 'pending',
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
        $scenarioResults['queue_state_preserved']['observed_outputs'] = [
            'preupgrade_queue_state' => $queuedTaskIdentity + [
                'task_queue' => 'migration-v1',
                'availability_state' => 'pending',
            ],
            'pending_task_identity' => $queuedTaskIdentity,
            'postupgrade_queue_state' => $queuedTaskIdentity + [
                'disposition' => 'completed',
            ],
            'dequeue_or_completion_result' => $queuedTaskIdentity + [
                'disposition' => 'completed',
                'duplicate_execution_count' => 0,
                'worker_runtime' => 'workflow-php-v2',
            ],
        ];
        $scenarioResults['documented_migration_steps_execute']['observed_outputs'] = [
            'migration_guide_revision' => [
                'url' => 'https://durable-workflow.github.io/docs/2.0/migration/',
                'sha256' => 'migration-guide-sha',
            ],
            'guide_command_executability' => [
                'status' => 'pass',
                'checked_commands' => [
                    'composer require durable-workflow/workflow:2.0.0-alpha.185',
                    'php artisan migrate',
                    'php artisan queue:restart',
                ],
                'unexecutable_commands' => [],
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

        foreach ($scenarioResults as $scenarioId => $scenarioResult) {
            if ($scenarioId === 'published_artifact_install_only') {
                continue;
            }

            $scenarioResults[$scenarioId]['observed_outputs']['command_outputs'] ??= [
                $this->commandOutput('dw migration-conformance '.$scenarioId),
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
            'migration_plan' => [
                'guide_revision' => 'docs/2.0/migration',
                'commands_executed' => $scenarioResults['documented_migration_steps_execute']['observed_outputs']['commands_executed'],
                'command_timings' => $scenarioResults['documented_migration_steps_execute']['observed_outputs']['command_timings'],
                'command_outputs' => $scenarioResults['documented_migration_steps_execute']['observed_outputs']['command_outputs'],
            ],
            'preupgrade_state_snapshot' => $this->stateSnapshotEvidence('preupgrade'),
            'postupgrade_state_snapshot' => $this->stateSnapshotEvidence('postupgrade'),
            'history_dumps' => [
                'completed' => true,
                'running' => true,
                'command_outputs' => [$this->commandOutput('dw workflow:history-export migration-completed')],
            ],
            'activity_attempts' => [
                'retry_preserved' => true,
                'command_outputs' => [$this->commandOutput('dw workflow:describe migration-retrying-activity')],
            ],
            'schedule_ticks' => [
                'cadence_preserved' => true,
                'command_outputs' => [$this->commandOutput('dw schedule:list --json')],
            ],
            'worker_registration_observations' => [
                'projection_preserved' => true,
                'command_outputs' => [$this->commandOutput('GET /api/workers?task_queue=migration-v1')],
            ],
            'cli_observations' => [
                'preupgrade_state_readable' => true,
                'command_outputs' => [$this->commandOutput('dw workflow:describe migration-completed --json')],
            ],
            'waterline_observations' => [
                'preupgrade_state_visible' => true,
                'command_outputs' => [$this->commandOutput('GET /waterline/api/instances/migration-completed')],
            ],
            'rollback_observations' => $scenarioResults['rollback_contract_verified']['observed_outputs'],
            'version_skew_observations' => $scenarioResults['version_skew_refusal']['observed_outputs'],
            'storage_connection_smoke' => [
                'passed' => true,
                'command_outputs' => [$this->commandOutput('php artisan migrate:status')],
            ],
            'scenario_results' => $scenarioResults,
        ];
    }

    /**
     * @param array<string, mixed> $complete
     * @param array<string, mixed> $migrationPlan
     *
     * @return array<string, mixed>
     */
    private function completeRunbookEvidence(array $complete, array $migrationPlan): array
    {
        $scenarioResults = $complete['scenario_results'];

        return [
            'outcome' => 'pass',
            'startedAt' => $complete['started_at'],
            'finishedAt' => $complete['finished_at'],
            'localProductSourceCheckoutsUsed' => false,
            'pinnedVersions' => [
                'v1' => [
                    'server' => $complete['published_artifact_versions']['server-v1'],
                    'cli' => $complete['published_artifact_versions']['cli-v1'],
                    'workflow' => $complete['published_artifact_versions']['workflow-php-v1'],
                    'waterline' => $complete['published_artifact_versions']['waterline-v1'],
                    'sampleApp' => $complete['published_artifact_versions']['sample-app-v1'],
                ],
                'v2' => [
                    'server' => $complete['published_artifact_versions']['server-v2'],
                    'cli' => $complete['published_artifact_versions']['cli-v2'],
                    'workflow' => $complete['published_artifact_versions']['workflow-php-v2'],
                    'pythonSdk' => $complete['published_artifact_versions']['sdk-python'],
                    'waterline' => $complete['published_artifact_versions']['waterline-v2'],
                ],
            ],
            'artifactSources' => $complete['artifact_sources'],
            'realisticV1StateSnapshot' => $scenarioResults['latest_supported_v1_state_setup']['observed_outputs'],
            'migrationPlan' => $migrationPlan,
            'completedHistoryReplay' => $scenarioResults['completed_history_preservation_and_replay']['observed_outputs'],
            'inFlightWorkflowProgress' => $scenarioResults['in_flight_workflow_progress_preserved']['observed_outputs'],
            'midActivityRetryPreserved' => $scenarioResults['mid_activity_retry_preserved']['observed_outputs'],
            'queueStatePreserved' => $scenarioResults['queue_state_preserved']['observed_outputs'],
            'scheduleCrossUpgradeCadencePreserved' => $scenarioResults['schedule_cross_upgrade_cadence_preserved']['observed_outputs'],
            'workerRegistrationProjectionPreserved' => $scenarioResults['worker_registration_projection_preserved']['observed_outputs'],
            'waterlineOperatorVisibilityPreserved' => $scenarioResults['waterline_operator_visibility_preserved']['observed_outputs'],
            'cliAccessToPreupgradeState' => $scenarioResults['cli_access_to_preupgrade_state']['observed_outputs'],
            'newV2WorkflowStartAfterUpgrade' => $scenarioResults['new_v2_workflow_start_after_upgrade']['observed_outputs'],
            'newV2ScheduleAfterUpgrade' => $scenarioResults['new_v2_schedule_after_upgrade']['observed_outputs'],
            'newV2WorkerRegistrationAfterUpgrade' => $scenarioResults['new_v2_worker_registration_after_upgrade']['observed_outputs'],
            'rollbackResult' => $scenarioResults['rollback_contract_verified']['observed_outputs'],
            'versionSkewObservations' => $scenarioResults['version_skew_refusal']['observed_outputs'],
            'preupgradeStateSnapshot' => $complete['preupgrade_state_snapshot'],
            'postupgradeStateSnapshot' => $complete['postupgrade_state_snapshot'],
            'historyDumps' => $complete['history_dumps'],
            'activityAttempts' => $complete['activity_attempts'],
            'scheduleTicks' => $complete['schedule_ticks'],
            'workerRegistrationObservations' => $complete['worker_registration_observations'],
            'cliObservations' => $complete['cli_observations'],
            'waterlineObservations' => $complete['waterline_observations'],
            'rollbackObservations' => $complete['rollback_observations'],
            'storageConnectionSmoke' => $complete['storage_connection_smoke'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function focusedSchedulePlan(): array
    {
        $scheduleId = 'migration-v2-schedule-focused';
        $workflowId = 'migration-v2-scheduled-workflow';
        $runId = 'migration-v2-scheduled-run';
        $namespace = 'migration-conformance';
        $operation = static function (
            string $endpoint,
            array $requestBody,
            array $responseBody,
            int $httpStatus = 200,
        ): array {
            $response = ['http_status' => $httpStatus, 'body' => $responseBody];

            return [
                'command' => 'printf "%s\\n" '.escapeshellarg(json_encode($response, JSON_THROW_ON_ERROR)),
                'endpoint' => $endpoint,
                'request' => $requestBody,
            ];
        };
        $projection = [
            'schedule_id' => $scheduleId,
            'namespace' => $namespace,
            'state' => ['paused' => false],
            'spec' => ['intervals' => [['every' => 'PT1H']]],
            'action' => [
                'workflow_type' => 'migration.v2.scheduled',
                'workflow_id' => $workflowId,
                'task_queue' => 'migration-v2-schedule-queue',
            ],
        ];

        return [
            'source' => 'published_artifact_foundation_plan',
            'postupgrade_state_snapshot' => $this->stateSnapshotEvidence('postupgrade'),
            'new_v2_schedule_after_upgrade' => [
                'isolated_public_server_artifact' => true,
                'schedule_id' => $scheduleId,
                'create_request' => $operation(
                    'dw schedules create --schedule-id='.$scheduleId.' --output=json',
                    [
                        'schedule_id' => $scheduleId,
                        'namespace' => $namespace,
                        'spec' => $projection['spec'],
                        'action' => $projection['action'],
                    ],
                    ['schedule_id' => $scheduleId, 'outcome' => 'created'],
                    201,
                ),
                'schedule_list_json' => $operation(
                    'dw schedules describe '.$scheduleId.' --output=json',
                    ['schedule_id' => $scheduleId, 'namespace' => $namespace],
                    $projection,
                ),
                'operator_api_response' => $operation(
                    'GET /api/schedules/'.$scheduleId,
                    ['schedule_id' => $scheduleId, 'namespace' => $namespace],
                    $projection,
                ),
                'trigger_response' => $operation(
                    'POST /api/schedules/'.$scheduleId.'/trigger',
                    ['schedule_id' => $scheduleId, 'namespace' => $namespace],
                    [
                        'schedule_id' => $scheduleId,
                        'outcome' => 'triggered',
                        'workflow_id' => $workflowId,
                        'run_id' => $runId,
                    ],
                ),
                'schedule_history' => $operation(
                    'GET /api/schedules/'.$scheduleId.'/history',
                    ['schedule_id' => $scheduleId, 'namespace' => $namespace],
                    [
                        'schedule_id' => $scheduleId,
                        'namespace' => $namespace,
                        'events' => [[
                            'sequence' => 2,
                            'event_type' => 'ScheduleTriggered',
                            'workflow_instance_id' => $workflowId,
                            'workflow_run_id' => $runId,
                            'recorded_at' => '2026-07-11T00:00:00Z',
                        ]],
                    ],
                ),
                'workflow_run' => $operation(
                    'GET /api/workflows/'.$workflowId.'/runs/'.$runId,
                    ['workflow_id' => $workflowId, 'run_id' => $runId, 'namespace' => $namespace],
                    [
                        'workflow_id' => $workflowId,
                        'run_id' => $runId,
                        'status' => 'running',
                        'workflow_type' => 'migration.v2.scheduled',
                    ],
                ),
            ],
        ];
    }

    private function stateSnapshotEvidence(string $phase): array
    {
        return [
            'state_kinds' => MigrationRuntimeContract::manifest()['required_matrix']['state_kinds'],
            'command_outputs' => [
                $this->commandOutput('dw workflow:list --phase='.$phase),
                $this->commandOutput('dw schedule:list --phase='.$phase),
                $this->commandOutput('GET /api/workers?phase='.$phase),
            ],
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
                    'state_kind' => 'queue_state',
                    'phase' => $phase,
                    'task_id' => 'migration-queued-activity',
                    'workflow_id' => 'migration-queue-holder',
                    'activity_id' => 'migration-queued-activity-call',
                    'activity_type' => 'migration_sample_activity',
                    'task_queue' => 'migration-v1',
                    'availability_state' => $phase === 'preupgrade' ? 'pending' : 'completed',
                ],
                ...($phase === 'postupgrade' ? [
                    [
                        'state_kind' => 'schedule',
                        'phase' => $phase,
                        'schedule_id' => 'migration-v2-schedule',
                        'next_fire_at' => '2026-05-31T22:45:00Z',
                    ],
                    [
                        'state_kind' => 'worker_registration',
                        'phase' => $phase,
                        'worker_id' => 'migration-v2-worker',
                        'task_queue' => 'migration-v2',
                    ],
                ] : []),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function commandOutput(string $command, string $stdout = 'captured migration conformance output'): array
    {
        return [
            'command' => $command,
            'status' => 'pass',
            'exit_code' => 0,
            'duration_ms' => 42,
            'stdout' => $stdout,
            'stderr' => '',
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

    /**
     * @param array<string, list<string>> $packagistVersions
     *
     * @return array<string, mixed>
     */
    private function runPublicV1ResolverFixture(string $nodeBinary, array $packagistVersions): array
    {
        $repoRoot = dirname(__DIR__, 2);
        $tempRoot = sys_get_temp_dir().'/dw-migration-public-v1-alias-'.bin2hex(random_bytes(6));
        $resultDir = $tempRoot.'/result';
        $metadataPath = $tempRoot.'/public-artifacts.json';

        try {
            mkdir($resultDir, 0777, true);
            file_put_contents(
                $metadataPath,
                json_encode([
                    'packagist_versions' => $packagistVersions,
                    'artifact_versions' => [
                        'cli-v1' => '0.1.44',
                        'waterline-v1' => '1.0.16',
                        'sample-app-v1' => 'e769ac5f4147498c652445f517ae724d73afa4de',
                    ],
                    'artifact_sources' => [
                        'server-v1' => 'docker_hub:durableworkflow/server:no_v1_release_tag_found',
                        'cli-v1' => 'github_release:durable-workflow/cli:0.1.44:install.sh',
                        'waterline-v1' => 'packagist:laravel-workflow/waterline:1.0.16',
                        'sample-app-v1' => 'github_branch:durable-workflow/sample-app:Laravel-12@e769ac5f4147498c652445f517ae724d73afa4de',
                    ],
                    'observations' => [
                        'server-v1' => ['status' => 'missing', 'channel' => 'docker_hub'],
                        'cli-v1' => ['status' => 'resolved', 'channel' => 'github_release'],
                        'waterline-v1' => ['status' => 'resolved', 'channel' => 'packagist'],
                        'sample-app-v1' => ['status' => 'resolved', 'channel' => 'github_branch'],
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

            return json_decode(
                (string) file_get_contents($resultDir.'/migration-conformance-result.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } finally {
            $this->removeTree($tempRoot);
        }
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
