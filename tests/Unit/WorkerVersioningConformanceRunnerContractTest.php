<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class WorkerVersioningConformanceRunnerContractTest extends TestCase
{
    public function test_published_artifact_runner_records_cross_language_delivery_counts(): void
    {
        $shell = $this->read('scripts/conformance/worker-versioning-published-artifacts.sh');
        $node = $this->read('scripts/conformance/worker-versioning-published-artifacts.mjs');
        $publishedWorkers = $this->read('scripts/conformance/worker-versioning-published-workers.mjs');

        $this->assertStringContainsString(
            'Usage: worker-versioning-published-artifacts.sh [--result-dir DIR|--result-dir=DIR] [--keep-run-root[=1|true]]',
            $shell,
        );
        $this->assertStringContainsString(
            'node "$script_dir/worker-versioning-published-artifacts.mjs"',
            $shell,
            'the shell handoff must execute the checked-in Node probe',
        );

        foreach ([
            'php_v1_to_python_v2_incompatible_delivery_count',
            'python_v1_to_php_v2_incompatible_delivery_count',
            'php_v1_compatible_delivery_count',
            'python_v1_compatible_delivery_count',
            'php_worker_build_ids',
            'python_worker_build_ids',
            'worker_runtime_identities',
            'workflow_runs',
            'rollout_state',
            'public_outcome',
            'worker_registration_build_ids',
            'worker_registration_responses',
            'worker_list_surface',
            'task_queue_build_id_surface',
            'published_worker_registration_entries',
        ] as $field) {
            $this->assertStringContainsString($field, $node);
            $this->assertStringContainsString($field, $publishedWorkers);
        }

        $this->assertStringContainsString(
            'const crossLanguagePasses = publishedCrossLanguageEvidence.passes;',
            $node,
            'the PHP/Python cell must pass only through parsed published cross-language evidence',
        );
        $this->assertStringContainsString(
            'const workerExecuted = publishedWorkerScenarioPasses(
    outputs,
    [\'sdk-python\', \'workflow-php\'],
    true,
  );',
            $node,
            'the parsed PHP/Python evidence must require both published SDK Python and workflow PHP worker artifacts',
        );
        $this->assertStringContainsString(
            'server_protocol_probe_only',
            $node,
            'synthetic HTTP worker records must be identified in the result',
        );
        $this->assertStringContainsString(
            "addNotCovered('cross_language_php_python_pinning'",
            $node,
            'synthetic PHP/Python records must leave the cross-language cell non-passing',
        );
        $this->assertStringContainsString(
            'published_artifact_worker_execution: false',
            $node,
            'the runner must not pass cross-language pinning without published PHP and Python worker execution',
        );
        $this->assertStringContainsString(
            'local_product_source_checkouts_used: false',
            $node,
            'the cross-language fallback must explicitly report that synthetic server probes did not use a local product checkout',
        );
        $this->assertStringContainsString(
            'Optional JSON report from a host topology that executed',
            $shell,
            'the shell handoff must document the published worker evidence input',
        );
        $this->assertStringContainsString(
            'registration/replay/cache/no-compatible/cross-language/',
            $shell,
            'the shell handoff must document every published-worker evidence cell required by the gate',
        );
        $this->assertStringContainsString(
            'worker-versioning-published-workers.mjs',
            $shell,
            'the shell handoff must attempt the published PHP/Python worker shard before aggregating results',
        );
        $this->assertStringContainsString(
            'maybeGeneratePublishedWorkerEvidence(serverUrl, artifactVersions, artifactSources);',
            $node,
            'direct Node host invocations must generate the published worker shard with artifact source evidence before aggregating results',
        );
        $this->assertStringContainsString(
            'publishedWorkerScenarioFindings(',
            $node,
            'the aggregate result must preserve focused findings from the published PHP/Python worker shard',
        );
        $this->assertStringContainsString(
            'workerRegistrationPublishedWorkerEvidenceResult(',
            $node,
            'the worker registration cell must pass only through parsed published PHP/Python registration evidence',
        );
        $this->assertStringContainsString(
            'const registrationWorkerList = await workerList();',
            $publishedWorkers,
            'the published worker shard must capture the public worker-list surface on the same task queue as registration',
        );
        $this->assertStringContainsString(
            'const registrationBuildIds = await taskQueueBuildIds();',
            $publishedWorkers,
            'the published worker shard must capture the public task-queue build-id surface on the same task queue as registration',
        );
        $this->assertStringContainsString(
            'focusedCrossLanguageNotCoveredFinding(',
            $node,
            'cross-language not-covered results must route the shard-specific finding instead of a generic synthetic-probe gap',
        );
        $this->assertStringContainsString(
            "spawnSync(process.execPath, [workerShardPath]",
            $node,
            'direct Node host invocations must execute the checked-in published worker shard',
        );
        $this->assertStringContainsString(
            'DW_WV_PUBLISHED_WORKER_EVIDENCE: publishedWorkerEvidencePath',
            $node,
            'the direct Node handoff must write the shard to the evidence path consumed by the result gate',
        );
        $this->assertStringContainsString(
            'DW_WV_SKIP_PUBLISHED_WORKER_SHARD',
            $shell,
            'the host runner must be able to skip automatic shard generation when it supplies a richer topology',
        );
        $this->assertStringContainsString(
            'DW_WV_SKIP_PUBLISHED_WORKER_SHARD',
            $node,
            'direct Node host invocations must respect the same skip override as the shell handoff',
        );
        foreach ([
            'DW_WV_SERVER_BIND_HOST',
            'DW_WV_SERVER_CONNECT_HOST',
            'DW_WV_SERVER_READINESS_TIMEOUT_SECONDS',
            'default_route_gateway',
            'docker_bridge_gateway',
            'server_url_candidates=()',
            'build_server_url_candidates',
            'promote_server_url_candidate',
            'wait_for_server_namespace_setup',
            'verify_server_namespace_setup',
            'server_state_summary',
            'block_missing_resolved_server_url',
            'server-namespace-setup.log',
            'server-url-candidates.txt',
            'server-url-resolved.txt',
            'docker-compose-ps.log',
            'server-namespace-url.txt',
            'published server namespace setup prerequisite failed before worker-versioning matrix',
            'published server namespace setup returned success without writing a non-empty server-url-resolved.txt before worker-versioning matrix',
            'if [[ ! -s "$resolved_url_file" ]]',
            'expected one of',
            'host.docker.internal',
            'SERVER_PORT="$compose_server_port"',
        ] as $token) {
            $this->assertStringContainsString($token, $shell);
        }
        foreach ([
            'ensureNamespacePrerequisite',
            'DW_WV_SERVER_READINESS_TIMEOUT_MS',
            'published server namespace setup prerequisite failed before worker-versioning matrix',
        ] as $token) {
            $this->assertStringContainsString($token, $node);
        }
        $this->assertLessThan(
            strpos($shell, 'run_published_worker_shard'),
            strpos($shell, 'verify_server_namespace_setup'),
            'the runner must verify/create the namespace before starting the broad published-worker matrix',
        );
        $this->assertLessThan(
            strpos($node, 'maybeGeneratePublishedWorkerEvidence(serverUrl, artifactVersions, artifactSources);'),
            strpos($node, 'await ensureNamespace(serverUrl, namespace, bootstrapControlHeaders, controlHeaders);'),
            'direct Node invocations must bootstrap namespace reachability before generating the published-worker shard',
        );
        $this->assertStringContainsString(
            'DW_WV_PUBLISHED_WORKER_SHARD_TIMEOUT_SECONDS',
            $shell,
            'the shell handoff must document the bounded published-worker shard budget',
        );
        $this->assertStringContainsString(
            'publishedWorkerShardFallbackEvidence(',
            $node,
            'the aggregate runner must emit structured not-covered evidence when the published-worker shard times out',
        );
        $this->assertStringContainsString(
            'write_published_worker_fallback_evidence',
            $shell,
            'the shell timeout handoff must write structured fallback evidence before skipping aggregate shard generation',
        );
        $this->assertStringContainsString(
            'DW_WV_PUBLISHED_WORKER_SHARD_EXIT_STATUS',
            $shell,
            'the shell fallback must preserve the direct shard exit status',
        );
        $this->assertStringContainsString(
            'artifactVersionsFromEnv',
            $shell,
            'the shell fallback must use the same artifact version normalization as the aggregate runner',
        );
        $this->assertStringContainsString(
            'if [[ ! -s "${DW_WV_PUBLISHED_WORKER_EVIDENCE:-}" ]]',
            $shell,
            'the shell fallback must only synthesize evidence when the shard did not write its own file',
        );
        foreach ([
            'durable-workflow==${pythonVersion}',
            'durable-workflow/workflow:${workflowPhpVersion}',
            'pypi_release',
            'packagist_release',
            'published_php_python_worker_protocol_clients',
            'php_v1_not_delivered_to_python_v2',
            'python_v1_not_delivered_to_php_v2',
            'NO_COMPATIBLE_SCENARIO',
            'runPythonNoCompatibleShardSafely',
            'raw_poll',
            'published_artifact_worker_execution',
            'local_product_source_checkouts_used: false',
            'mergeExistingShard',
            'for (const [scenarioId, existingScenario] of Object.entries(existingScenarios))',
        ] as $token) {
            $this->assertStringContainsString($token, $publishedWorkers);
        }
    }

    public function test_published_worker_shard_records_cross_language_before_supplemental_python_cells(): void
    {
        $publishedWorkers = $this->read('scripts/conformance/worker-versioning-published-workers.mjs');

        foreach ([
            'const python = await installPythonWorker(shardRoot);',
            'let pythonReplay = emptySupplementalShard();',
            'let pythonNoCompatible = emptySupplementalShard();',
            'let pythonAdversarial = emptySupplementalShard();',
            'pythonReplay = await runPythonReplayShardSafely(python);',
            'pythonNoCompatible = await runPythonNoCompatibleShardSafely(python);',
            'pythonAdversarial = await runPythonAdversarialShardSafely(python);',
            'const publishedPhpPythonShard = {',
            'const baseShard = writeShard(publishedPhpPythonShard);',
            'const supplementalShard = pythonScenarioShard(',
            'writeJson(outputPath, mergeShardValues(baseShard, supplementalShard));',
            "if (!workflowPhpVersion) crossLanguageMissing.push('DW_WORKFLOW_PHP_VERSION');",
            "if (!commandExists('docker')) crossLanguageMissing.push('docker');",
            'published PHP/Python cross-language worker shard prerequisites are missing',
            'function emptySupplementalShard()',
            'function pythonScenarioShard(python, shardRoot, pythonReplay, pythonNoCompatible, pythonAdversarial)',
            'export function mergeShardValues(existing, value)',
        ] as $token) {
            $this->assertStringContainsString($token, $publishedWorkers);
        }

        $registrationEvidence = strpos($publishedWorkers, '[REGISTRATION_SCENARIO]: {');
        $crossLanguageEvidence = strpos($publishedWorkers, '[CROSS_LANGUAGE_SCENARIO]: {');
        $baseEvidenceWrite = strpos($publishedWorkers, 'const baseShard = writeShard(publishedPhpPythonShard);');
        $pythonSupplementalRun = strpos($publishedWorkers, 'pythonReplay = await runPythonReplayShardSafely(python);');
        $finalEvidenceWrite = strpos($publishedWorkers, 'writeJson(outputPath, mergeShardValues(baseShard, supplementalShard));');
        $crossLanguagePrerequisiteGate = strpos($publishedWorkers, 'const crossLanguageMissing = [];');
        $phpInstall = strpos($publishedWorkers, 'const php = installPhpWorker(shardRoot);');

        $this->assertIsInt($registrationEvidence);
        $this->assertIsInt($crossLanguageEvidence);
        $this->assertIsInt($baseEvidenceWrite);
        $this->assertIsInt($pythonSupplementalRun);
        $this->assertIsInt($finalEvidenceWrite);
        $this->assertIsInt($crossLanguagePrerequisiteGate);
        $this->assertIsInt($phpInstall);
        $this->assertLessThan(
            $phpInstall,
            $crossLanguagePrerequisiteGate,
            'PHP installation must remain scoped to the cross-language cell after prerequisite checks.',
        );
        $this->assertLessThan(
            $registrationEvidence,
            $phpInstall,
            'Published PHP/Python registration evidence must be assembled after published PHP installation is available.',
        );
        $this->assertLessThan(
            $crossLanguageEvidence,
            $registrationEvidence,
            'Published registration and cross-language scenarios must be part of the same base shard.',
        );
        $this->assertLessThan(
            $pythonSupplementalRun,
            $baseEvidenceWrite,
            'Published PHP/Python evidence must be written before supplemental Python-only cells start.',
        );
        $this->assertLessThan(
            $finalEvidenceWrite,
            $pythonSupplementalRun,
            'Supplemental Python replay/no-compatible/adversarial evidence must be merged into the final shard after it runs.',
        );
    }

    public function test_published_worker_final_shard_keeps_registration_scenario_after_supplemental_merge(): void
    {
        $finalShard = $this->mergePublishedWorkerShardValues(
            [
                'schema' => 'durable-workflow.v2.worker-versioning-runtime.published-worker-execution-evidence',
                'local_product_source_checkouts_used' => false,
                'artifact_versions' => [
                    'server' => '0.2.416',
                    'sdk-python' => '0.4.88',
                    'workflow-php' => '2.0.0-alpha.204',
                ],
                'artifact_sources' => [
                    'server' => 'published_server_url',
                    'sdk-python' => 'pypi_release',
                    'workflow-php' => 'packagist_release',
                ],
                'topology' => [
                    'namespace' => 'worker-versioning-conformance',
                    'task_queue' => 'worker-versioning-shared',
                    'workers' => [
                        ['worker_id' => 'php-v1', 'runtime' => 'php', 'build_id' => 'php-build-v1'],
                        ['worker_id' => 'python-v2', 'runtime' => 'python', 'build_id' => 'python-build-v2'],
                    ],
                ],
                'scenario_results' => [
                    'worker_registration_build_ids' => [
                        'scenario_id' => 'worker_registration_build_ids',
                        'status' => 'pass',
                        'observed_outputs' => [
                            'task_queue' => 'worker-versioning-shared',
                            'worker_registration_responses' => [
                                'workflow_php' => [
                                    'artifact' => 'workflow-php',
                                    'worker_id' => 'php-v1',
                                    'task_queue' => 'worker-versioning-shared',
                                    'build_id' => 'php-build-v1',
                                    'response' => ['build_id' => 'php-build-v1'],
                                ],
                                'sdk_python' => [
                                    'artifact' => 'sdk-python',
                                    'worker_id' => 'python-v2',
                                    'task_queue' => 'worker-versioning-shared',
                                    'build_id' => 'python-build-v2',
                                    'response' => ['build_id' => 'python-build-v2'],
                                ],
                            ],
                            'worker_list_build_ids' => ['php-build-v1', 'python-build-v2'],
                            'task_queue_build_ids' => ['php-build-v1', 'python-build-v2'],
                            'active_worker_counts_per_cohort' => [
                                'php-build-v1' => 1,
                                'python-build-v2' => 1,
                            ],
                            'worker_list_surface' => [
                                'workers' => [
                                    ['worker_id' => 'php-v1', 'build_id' => 'php-build-v1'],
                                    ['worker_id' => 'python-v2', 'build_id' => 'python-build-v2'],
                                ],
                            ],
                            'task_queue_build_id_surface' => [
                                'build_ids' => [
                                    ['build_id' => 'php-build-v1', 'active_worker_count' => 1],
                                    ['build_id' => 'python-build-v2', 'active_worker_count' => 1],
                                ],
                            ],
                            'published_artifact_worker_execution' => [
                                'local_product_source_checkouts_used' => false,
                                'artifacts' => [
                                    [
                                        'artifact' => 'workflow-php',
                                        'version' => '2.0.0-alpha.204',
                                        'source' => 'packagist_release',
                                        'status' => 'pass',
                                    ],
                                    [
                                        'artifact' => 'sdk-python',
                                        'version' => '0.4.88',
                                        'source' => 'pypi_release',
                                        'status' => 'pass',
                                    ],
                                ],
                            ],
                            'local_product_source_checkouts_used' => false,
                        ],
                        'linked_findings' => [],
                    ],
                    'cross_language_php_python_pinning' => [
                        'scenario_id' => 'cross_language_php_python_pinning',
                        'status' => 'pass',
                        'observed_outputs' => [
                            'local_product_source_checkouts_used' => false,
                            'published_artifact_worker_execution' => [
                                'local_product_source_checkouts_used' => false,
                                'artifacts' => [
                                    [
                                        'artifact' => 'workflow-php',
                                        'version' => '2.0.0-alpha.204',
                                        'source' => 'packagist_release',
                                        'status' => 'pass',
                                    ],
                                    [
                                        'artifact' => 'sdk-python',
                                        'version' => '0.4.88',
                                        'source' => 'pypi_release',
                                        'status' => 'pass',
                                    ],
                                ],
                            ],
                        ],
                        'linked_findings' => [],
                    ],
                ],
                'findings' => [],
                'logs' => ['php_install' => 'php-install.log'],
            ],
            [
                'schema' => 'durable-workflow.v2.worker-versioning-runtime.published-worker-execution-evidence',
                'local_product_source_checkouts_used' => false,
                'topology' => [
                    'namespace' => 'worker-versioning-conformance',
                    'task_queue' => 'worker-versioning-shared',
                    'workers' => [
                        ['worker_id' => 'python-replay-v1', 'runtime' => 'python', 'build_id' => 'python-replay-build-v1'],
                    ],
                ],
                'scenario_results' => [
                    'replay_only_by_compatible_workers' => [
                        'scenario_id' => 'replay_only_by_compatible_workers',
                        'status' => 'not_covered',
                        'observed_outputs' => [
                            'worker_execution_mode' => 'published_python_worker_protocol_client',
                            'local_product_source_checkouts_used' => false,
                        ],
                        'linked_findings' => [],
                    ],
                ],
                'findings' => [],
                'logs' => ['python_install' => 'python-install.log'],
            ],
        );

        $this->assertArrayHasKey('worker_registration_build_ids', $finalShard['scenario_results']);
        $this->assertArrayHasKey('cross_language_php_python_pinning', $finalShard['scenario_results']);
        $this->assertArrayHasKey('replay_only_by_compatible_workers', $finalShard['scenario_results']);
        $this->assertSame(
            'pass',
            $finalShard['scenario_results']['worker_registration_build_ids']['status'],
        );
        $registrationOutputs = $finalShard['scenario_results']['worker_registration_build_ids']['observed_outputs'];
        $this->assertSame('worker-versioning-shared', $registrationOutputs['task_queue']);
        $this->assertSame(['php-build-v1', 'python-build-v2'], $registrationOutputs['worker_list_build_ids']);
        $this->assertSame(['php-build-v1', 'python-build-v2'], $registrationOutputs['task_queue_build_ids']);
        $this->assertSame(1, $registrationOutputs['active_worker_counts_per_cohort']['php-build-v1']);
        $this->assertSame(1, $registrationOutputs['active_worker_counts_per_cohort']['python-build-v2']);
        $this->assertSame(
            'php-build-v1',
            $registrationOutputs['worker_registration_responses']['workflow_php']['response']['build_id'],
        );
        $this->assertSame(
            'python-build-v2',
            $registrationOutputs['worker_registration_responses']['sdk_python']['response']['build_id'],
        );
        $this->assertCount(3, $finalShard['topology']['workers']);
        $this->assertSame('php-install.log', $finalShard['logs']['php_install']);
        $this->assertSame('python-install.log', $finalShard['logs']['python_install']);
    }

    public function test_published_artifact_install_cell_requires_install_evidence(): void
    {
        $node = $this->read('scripts/conformance/worker-versioning-published-artifacts.mjs');

        foreach ([
            'artifact_install_evidence',
            'artifactInstallEvidencePasses(installEvidence)',
            "addNotCovered('published_artifact_install_only'",
            'not_exercised',
            'REQUIRED_INSTALL_ARTIFACTS',
            'FORBIDDEN_INSTALL_SOURCE_TOKENS',
            'truthyEvidenceFlag',
            'artifactSourceIsForbidden(source)',
            'publishedWorkerScenarioPasses',
            'publishedWorkerExecutionEntries',
            'workflowVisibilitySignalValuesFromOutputs',
            'DW_WV_PUBLISHED_WORKER_EVIDENCE',
            'local_product_source_checkouts_used: truthyEvidenceFlag(evidence.local_product_source_checkouts_used)',
            'supplied_shard_local_product_source_checkouts_used',
            'outputs?.supplied_shard_local_product_source_checkouts_used !== false',
            'explicitFalse(outputs?.local_product_source_checkouts_used)',
        ] as $token) {
            $this->assertStringContainsString($token, $node);
        }

        $this->assertStringNotContainsString("cli: 'published_install_script'", $node);
        $this->assertStringNotContainsString("'sdk-python': 'published_pypi'", $node);
        $this->assertStringNotContainsString("'workflow-php': 'published_composer'", $node);
        $this->assertStringNotContainsString("waterline: 'published_artifact'", $node);
    }

    public function test_worker_versioning_runner_records_published_cli_rollout_and_drain_evidence(): void
    {
        $node = $this->read('scripts/conformance/worker-versioning-published-artifacts.mjs');

        foreach ([
            'resolvePublishedCliArtifact',
            'downloadCliInstaller',
            'worker-versioning-cli-install.json',
            "runCliJson(\n    cli.executable,\n    ['task-queue:promote'",
            "runCliJson(\n    cli.executable,\n    ['worker:list'",
            "runCliJson(\n    cli.executable,\n    ['task-queue:build-ids'",
            "runCliJson(\n    cli.executable,\n    ['workflow:show-run'",
            "runCliJson(\n    cli.executable,\n    ['task-queue:drain'",
            "runCliJson(\n    cli.executable,\n    ['task-queue:resume'",
            'pollWorkflowTaskWithStatuses',
            'draining_worker_poll: drainingWorkerPoll',
            'draining_worker_claim_blocked: drainingWorkerClaimBlocked',
            'draining_worker_claim_count: drainingWorkerClaimCount',
            "stringValue(drainingWorkerPoll?.reason) === 'worker_draining'",
            'cli_operator_command_execution: commandExecutionPasses',
            'rollout_visibility_passes: rolloutVisibilityPasses',
            'drain_resume_controls_passes: drainResumeControlsPasses',
            "addNotCovered('operator_rollout_visibility'",
            "addPass('drain_resume_operator_controls'",
            'Published CLI rollout controls were exercised and recorded',
            'cli_rollout_visibility_passes: cliOperatorEvidence.rollout_visibility_passes',
            'runtimeMatrix.client_paths = unique([...runtimeMatrix.client_paths, \'cli\']);',
            'runtimeMatrix.uncovered_required_client_paths = runtimeMatrix.uncovered_required_client_paths',
            "'dw workers list'",
            "'dw task-queue build-ids'",
            "'workflow show compatibility'",
            'mergeCliInstallEvidence(installEvidence, cliOperatorEvidence.cli_install_evidence)',
            'published_cli_execution: publishedCliExecution',
        ] as $token) {
            $this->assertStringContainsString($token, $node);
        }

        $this->assertStringNotContainsString(
            "if (cliOperatorEvidence.rollout_visibility_passes) {\n    addPass('operator_rollout_visibility'",
            $node,
            'CLI-only rollout evidence must not pass the combined CLI and Waterline rollout scenario',
        );
        $this->assertStringNotContainsString('draining_worker_claim_count: 0,', $node);
    }

    public function test_worker_versioning_runner_records_published_waterline_visibility(): void
    {
        $shell = $this->read('scripts/conformance/worker-versioning-published-artifacts.sh');
        $node = $this->read('scripts/conformance/worker-versioning-published-artifacts.mjs');
        $manifest = $this->read('static/platform-conformance/worker-versioning-runtime-scenarios.json');

        foreach ([
            'DW_WV_WATERLINE_URL',
            'DW_WV_WATERLINE_CONNECT_HOST',
            'DW_WV_WATERLINE_RUNTIME_IMAGE',
            'DW_WV_WATERLINE_PHP_BASE_IMAGE',
            'DW_WV_WATERLINE_BUILT_RUNTIME_IMAGE',
            'DW_WV_WATERLINE_DB_HOST',
            'DW_WV_WATERLINE_DOCKER_NETWORK',
            'DW_WV_SKIP_WATERLINE_SHARD',
            'durable-workflow/waterline:${DW_WATERLINE_VERSION}',
            'waterline-compose.yml',
            'php_version_at_least',
            'PHP >= 8.4.1',
            'waterline-runtime-build.log',
            'waterline-runtime-php-version.txt',
            'waterline-runtime-php-modules.txt',
            'docker-php-ext-install pdo_mysql',
            'grep -qi \'^pdo_mysql$\'',
            'waterline_runtime_image="${DW_WV_WATERLINE_RUNTIME_IMAGE:-}"',
            'waterline_php_base_image="${DW_WV_WATERLINE_PHP_BASE_IMAGE:-php:8.4-cli}"',
            'published Waterline default PHP runtime could not be built',
            'durable-workflow/waterline ${DW_WATERLINE_VERSION} requires PHP >= 8.4.1',
            'image: "${waterline_runtime_image}"',
            'entrypoint: []',
            'wait_for_waterline',
            'WATERLINE_NAMESPACE: ${DW_WV_NAMESPACE:-worker-versioning-conformance}',
            'packagist://durable-workflow/waterline@${DW_WATERLINE_VERSION}',
            'DW_WV_SERVER_URL was provided without DW_WV_WATERLINE_URL or DW_WV_WATERLINE_DB_HOST',
            'DW_WV_SKIP_WATERLINE_SHARD=1 was set without DW_WV_WATERLINE_URL',
            'waterline_container="dw-worker-versioning-waterline-${run_label}"',
            '--add-host=host.docker.internal:host-gateway',
            'waterline-docker-run.log',
        ] as $token) {
            $this->assertStringContainsString($token, $shell);
        }

        foreach ([
            'publishedWaterlineOperatorVisibility',
            'capturePublishedWaterlineOperatorVisibility',
            '/waterline/api/v2/health',
            '/waterline/api/flows/running',
            'waterlineRunDetailPath',
            'waterlineWorkerRows',
            'waterline_operator_visibility: waterlineOperatorVisibility',
            "if (cliOperatorEvidence.rollout_visibility_passes && waterlineOperatorVisibility.status === 'pass')",
            "addPass('operator_visibility_surfaces'",
            'mergeWaterlineInstallEvidence',
            'Published Waterline worker/workflow views',
            "'Waterline worker and workflow views'",
            'waterline_artifact_version',
            'reachability_status',
            'rollout_state_observed',
            'request_failures: waterlineRequestFailures',
            'Published Waterline request failures',
            'worker_view_capture',
            'workflow_view_capture',
            'directNodeWaterlineAttachBlocker',
            'direct Node invocation cannot boot Waterline',
        ] as $token) {
            $this->assertStringContainsString($token, $node);
        }

        foreach ([
            'DW_WV_SERVER_BIND_HOST',
            'DW_WV_SERVER_CONNECT_HOST',
            'DW_WV_SERVER_READINESS_TIMEOUT_SECONDS',
            'DW_WV_WATERLINE_URL',
            'DW_WV_WATERLINE_CONNECT_HOST',
            'DW_WV_WATERLINE_DB_HOST',
            'DW_WV_WATERLINE_DOCKER_NETWORK',
            'DW_WV_SKIP_WATERLINE_SHARD',
            'published_waterline_worker_workflow_view_capture',
            'server-url-candidates.txt',
            'server-namespace-url.txt',
            'waterline-url.txt',
        ] as $token) {
            $this->assertStringContainsString($token, $manifest);
        }

        $this->assertStringNotContainsString(
            "const waterlineOperatorVisibility = { status: 'not_exercised_by_server_handoff' };",
            $node,
            'Waterline visibility must come from the published Waterline shard, not a placeholder.',
        );
        $this->assertStringNotContainsString(
            "image: composer:2\n    working_dir: /app",
            $shell,
            'the disposable Waterline service must not run under composer:2 because it lacks pdo_mysql',
        );
        $this->assertStringNotContainsString(
            'waterline_runtime_image="${DW_WV_WATERLINE_RUNTIME_IMAGE:-$server_image}"',
            $shell,
            'the disposable Waterline service must not default to the PHP 8.3 server image',
        );
    }

    public function test_shell_reports_external_waterline_attach_prerequisite(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the worker-versioning shell handoff.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $suffix = bin2hex(random_bytes(4));
        $resultDir = $repoRoot.'/storage/framework/worker-versioning-waterline-result-'.$suffix;
        $runRoot = $repoRoot.'/storage/framework/worker-versioning-waterline-run-'.$suffix;
        mkdir($resultDir, 0777, true);
        mkdir($runRoot, 0777, true);

        try {
            $process = proc_open(
                [
                    'bash',
                    $repoRoot.'/scripts/conformance/worker-versioning-published-artifacts.sh',
                    '--result-dir',
                    $resultDir,
                    '--keep-run-root',
                ],
                [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                $repoRoot,
                [
                    'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
                    'DW_WV_RESULT_DIR' => $resultDir,
                    'DW_WV_RUN_ROOT' => $runRoot,
                    'DW_WV_SERVER_URL' => 'http://127.0.0.1:9',
                    'DW_SERVER_VERSION' => '0.2.419',
                    'DW_CLI_VERSION' => '0.1.80',
                    'DW_PYTHON_SDK_VERSION' => '0.4.88',
                    'DW_WORKFLOW_PHP_VERSION' => '2.0.0-alpha.204',
                    'DW_WATERLINE_VERSION' => '2.0.0-alpha.96',
                    'DW_WV_SERVER_ARTIFACT_SOURCE' => 'published_server_url',
                ],
            );

            $this->assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            $this->assertSame(0, $exitCode, $stdout.$stderr);

            $resultPath = $resultDir.'/worker-versioning-result.json';
            $recordPath = $resultDir.'/worker-versioning-record.json';
            $this->assertFileExists($resultPath);
            $this->assertFileExists($recordPath);

            $result = json_decode((string) file_get_contents($resultPath), true, 512, JSON_THROW_ON_ERROR);
            $record = json_decode((string) file_get_contents($recordPath), true, 512, JSON_THROW_ON_ERROR);
            $reason = 'DW_WV_SERVER_URL was provided without DW_WV_WATERLINE_URL or DW_WV_WATERLINE_DB_HOST; the runner cannot attach published Waterline to the same worker-versioning run database';

            $this->assertSame('non_passing_runner_blocked', $result['outcome']);
            $this->assertTrue($result['runner_blocked']);
            $this->assertSame($reason, $result['scenario_results']['operator_visibility_surfaces']['observed_outputs']['blocked_reason']);
            $this->assertSame('non_passing_runner_blocked', $record['outcome']);
            $this->assertTrue($record['runner_blocked']);
        } finally {
            $this->removeDirectory($resultDir);
            $this->removeDirectory($runRoot);
        }
    }

    public function test_shell_records_blocked_result_when_namespace_setup_succeeds_without_resolved_url(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the worker-versioning shell handoff.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $suffix = bin2hex(random_bytes(4));
        $resultDir = $repoRoot.'/storage/framework/worker-versioning-missing-url-result-'.$suffix;
        $runRoot = $repoRoot.'/storage/framework/worker-versioning-missing-url-run-'.$suffix;
        $fakeBin = $repoRoot.'/storage/framework/worker-versioning-missing-url-bin-'.$suffix;
        mkdir($resultDir, 0777, true);
        mkdir($runRoot, 0777, true);
        mkdir($fakeBin, 0777, true);

        try {
            $fakeNode = $fakeBin.'/node';
            $fakeNodeScript = implode("\n", [
                '#!/bin/sh',
                'if [ "${1:-}" = "-" ]; then',
                "  printf '%s\\n' 'simulated namespace setup success without resolved URL'",
                '  exit 0',
                'fi',
                'exec '.escapeshellarg($nodeBinary).' "$@"',
                '',
            ]);
            file_put_contents($fakeNode, $fakeNodeScript);
            chmod($fakeNode, 0755);

            $process = proc_open(
                [
                    'bash',
                    $repoRoot.'/scripts/conformance/worker-versioning-published-artifacts.sh',
                    '--result-dir',
                    $resultDir,
                    '--keep-run-root',
                ],
                [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                $repoRoot,
                [
                    'PATH' => $fakeBin.PATH_SEPARATOR.(getenv('PATH') ?: '/usr/bin:/bin'),
                    'DW_WV_RESULT_DIR' => $resultDir,
                    'DW_WV_RUN_ROOT' => $runRoot,
                    'DW_WV_SERVER_URL' => 'http://127.0.0.1:65534',
                    'DW_WV_WATERLINE_URL' => 'http://127.0.0.1:65534',
                    'DW_WV_SKIP_PUBLISHED_WORKER_SHARD' => '1',
                    'DW_SERVER_VERSION' => '0.2.425',
                    'DW_CLI_VERSION' => '0.1.81',
                    'DW_PYTHON_SDK_VERSION' => '0.4.89',
                    'DW_WORKFLOW_PHP_VERSION' => '2.0.0-alpha.206',
                    'DW_WATERLINE_VERSION' => '2.0.0-alpha.97',
                    'DW_WV_SERVER_ARTIFACT_SOURCE' => 'published_server_url',
                ],
            );

            $this->assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            $this->assertSame(0, $exitCode, $stdout.$stderr);

            $resultPath = $resultDir.'/worker-versioning-result.json';
            $recordPath = $resultDir.'/worker-versioning-record.json';
            $this->assertFileExists($resultPath);
            $this->assertFileExists($recordPath);
            $this->assertFileExists($resultDir.'/server-namespace-setup.log');
            $this->assertFileExists($resultDir.'/server-url-candidates.txt');
            $this->assertFileDoesNotExist($resultDir.'/server-url-resolved.txt');
            $this->assertFileDoesNotExist($resultDir.'/server-namespace-url.txt');
            $this->assertFileDoesNotExist($resultDir.'/published-worker-shard-direct.log');

            $result = json_decode((string) file_get_contents($resultPath), true, 512, JSON_THROW_ON_ERROR);
            $record = json_decode((string) file_get_contents($recordPath), true, 512, JSON_THROW_ON_ERROR);
            $reason = $result['scenario_results']['worker_registration_build_ids']['observed_outputs']['blocked_reason'];

            $this->assertSame('non_passing_runner_blocked', $result['outcome']);
            $this->assertTrue($result['runner_blocked']);
            $this->assertSame('non_passing_runner_blocked', $record['outcome']);
            $this->assertTrue($record['runner_blocked']);
            $this->assertStringContainsString(
                'published server namespace setup returned success without writing a non-empty server-url-resolved.txt before worker-versioning matrix',
                $reason,
            );
            $this->assertStringContainsString(
                'expected one of http://127.0.0.1:65534/api/namespaces/worker-versioning-conformance',
                $reason,
            );
            $this->assertStringContainsString(
                'server process/container state is not managed by this runner',
                $reason,
            );
            $this->assertStringContainsString(
                'server-namespace-setup.log, server-url-candidates.txt, docker-compose-ps.log, and server.log',
                $reason,
            );
            $this->assertStringContainsString(
                'simulated namespace setup success without resolved URL',
                (string) file_get_contents($resultDir.'/server-namespace-setup.log'),
            );
        } finally {
            $this->removeDirectory($resultDir);
            $this->removeDirectory($runRoot);
            $this->removeDirectory($fakeBin);
        }
    }

    public function test_shell_fails_fast_when_namespace_setup_url_is_unreachable(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the worker-versioning shell handoff.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $suffix = bin2hex(random_bytes(4));
        $resultDir = $repoRoot.'/storage/framework/worker-versioning-server-reachability-result-'.$suffix;
        $runRoot = $repoRoot.'/storage/framework/worker-versioning-server-reachability-run-'.$suffix;
        mkdir($resultDir, 0777, true);
        mkdir($runRoot, 0777, true);

        try {
            $process = proc_open(
                [
                    'bash',
                    $repoRoot.'/scripts/conformance/worker-versioning-published-artifacts.sh',
                    '--result-dir',
                    $resultDir,
                    '--keep-run-root',
                ],
                [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                $repoRoot,
                [
                    'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
                    'DW_WV_RESULT_DIR' => $resultDir,
                    'DW_WV_RUN_ROOT' => $runRoot,
                    'DW_WV_SERVER_URL' => 'http://127.0.0.1:65534',
                    'DW_WV_WATERLINE_URL' => 'http://127.0.0.1:65534',
                    'DW_WV_SERVER_READINESS_TIMEOUT_SECONDS' => '1',
                    'DW_WV_SKIP_PUBLISHED_WORKER_SHARD' => '1',
                    'DW_SERVER_VERSION' => '0.2.422',
                    'DW_CLI_VERSION' => '0.1.80',
                    'DW_PYTHON_SDK_VERSION' => '0.4.88',
                    'DW_WORKFLOW_PHP_VERSION' => '2.0.0-alpha.205',
                    'DW_WATERLINE_VERSION' => '2.0.0-alpha.96',
                    'DW_WV_SERVER_ARTIFACT_SOURCE' => 'published_server_url',
                ],
            );

            $this->assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            $this->assertSame(0, $exitCode, $stdout.$stderr);

            $resultPath = $resultDir.'/worker-versioning-result.json';
            $this->assertFileExists($resultPath);
            $this->assertFileExists($resultDir.'/server-namespace-setup.log');
            $this->assertFileDoesNotExist($resultDir.'/published-worker-shard-direct.log');

            $result = json_decode((string) file_get_contents($resultPath), true, 512, JSON_THROW_ON_ERROR);
            $reason = $result['scenario_results']['worker_registration_build_ids']['observed_outputs']['blocked_reason'];

            $this->assertSame('non_passing_runner_blocked', $result['outcome']);
            $this->assertTrue($result['runner_blocked']);
            $this->assertStringContainsString(
                'published server namespace setup prerequisite failed before worker-versioning matrix',
                $reason,
            );
            $this->assertStringContainsString(
                'expected one of http://127.0.0.1:65534/api/namespaces/worker-versioning-conformance',
                $reason,
            );
            $this->assertStringContainsString(
                'server process/container state is not managed by this runner',
                $reason,
            );
            $this->assertStringContainsString(
                'published server namespace setup did not become reachable before worker-versioning matrix',
                (string) file_get_contents($resultDir.'/server-namespace-setup.log'),
            );
            $this->assertFileExists($resultDir.'/server-url-candidates.txt');
            $this->assertStringContainsString(
                'http://127.0.0.1:65534',
                (string) file_get_contents($resultDir.'/server-url-candidates.txt'),
            );
        } finally {
            $this->removeDirectory($resultDir);
            $this->removeDirectory($runRoot);
        }
    }

    public function test_runner_reports_fetch_failures_with_request_context(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the worker-versioning runner.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $suffix = bin2hex(random_bytes(4));
        $resultDir = $repoRoot.'/storage/framework/worker-versioning-fetch-result-'.$suffix;
        $runRoot = $repoRoot.'/storage/framework/worker-versioning-fetch-run-'.$suffix;
        mkdir($resultDir, 0777, true);
        mkdir($runRoot, 0777, true);

        try {
            $process = proc_open(
                [
                    $nodeBinary,
                    $repoRoot.'/scripts/conformance/worker-versioning-published-artifacts.mjs',
                ],
                [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                $repoRoot,
                [
                    'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
                    'DW_WV_RESULT_DIR' => $resultDir,
                    'DW_WV_RUN_ROOT' => $runRoot,
                    'DW_WV_SERVER_URL' => 'http://127.0.0.1:65534',
                    'DW_WV_WATERLINE_URL' => 'http://127.0.0.1:65534',
                    'DW_WV_SERVER_READINESS_TIMEOUT_SECONDS' => '1',
                    'DW_WV_SKIP_PUBLISHED_WORKER_SHARD' => '1',
                    'DW_SERVER_VERSION' => '0.2.421',
                    'DW_CLI_VERSION' => '0.1.80',
                    'DW_PYTHON_SDK_VERSION' => '0.4.88',
                    'DW_WORKFLOW_PHP_VERSION' => '2.0.0-alpha.205',
                    'DW_WATERLINE_VERSION' => '2.0.0-alpha.96',
                    'DW_WV_SERVER_ARTIFACT_SOURCE' => 'published_server_url',
                ],
            );

            $this->assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            $this->assertSame(0, $exitCode, $stdout.$stderr);

            $resultPath = $resultDir.'/worker-versioning-result.json';
            $this->assertFileExists($resultPath);

            $result = json_decode((string) file_get_contents($resultPath), true, 512, JSON_THROW_ON_ERROR);
            $reason = $result['scenario_results']['worker_registration_build_ids']['observed_outputs']['blocked_reason'];

            $this->assertSame('non_passing_runner_blocked', $result['outcome']);
            $this->assertTrue($result['runner_blocked']);
            $this->assertStringContainsString(
                'published server namespace setup prerequisite failed before worker-versioning matrix',
                $reason,
            );
            $this->assertStringContainsString(
                'expected http://127.0.0.1:65534/api/namespaces/worker-versioning-conformance',
                $reason,
            );
            $this->assertStringContainsString(
                'GET http://127.0.0.1:65534/api/ready failed:',
                $reason,
            );
            $this->assertStringContainsString('ECONNREFUSED', $reason);
            $this->assertNotSame('fetch failed', $reason);
        } finally {
            $this->removeDirectory($resultDir);
            $this->removeDirectory($runRoot);
        }
    }

    public function test_published_artifact_runner_gates_replay_cells_on_zero_incompatible_delivery(): void
    {
        $node = $this->read('scripts/conformance/worker-versioning-published-artifacts.mjs');
        $publishedWorkers = $this->read('scripts/conformance/worker-versioning-published-workers.mjs');

        foreach ([
            'v1_worker_task_count',
            'v2_worker_task_count_for_v1_run',
            'replay_decision',
            'v1_first_task_id',
            'replay_task_id',
            'workflow_task_retry_of',
            'cache_eviction_observed',
            'replay_worker_build_id',
            'expected_replay_worker_build_id',
            'v1_pinned_run_id',
            'pinned_run_build_id',
            'incompatible_delivery_count',
            'incompatible_worker_task_count',
            'started_workflow_visibility',
            'task_queue_build_id_samples',
            'no_compatible_visibility_deadline_seconds',
            'no_compatible_visibility_attempts',
            'pending_or_typed_error',
            'operator_visible_signal_explicit',
        ] as $field) {
            $this->assertStringContainsString($field, $node);
        }

        $this->assertStringContainsString(
            'pythonReplay = await runPythonReplayShardSafely(python);',
            $publishedWorkers,
            'a replay/cache shard exception must be recorded after the cross-language cell has had a chance to run',
        );
        $this->assertStringContainsString(
            'pythonNoCompatible = await runPythonNoCompatibleShardSafely(python);',
            $publishedWorkers,
            'the no-compatible cell must be measured by an installed published worker shard',
        );
        $this->assertStringContainsString(
            'notCoveredPythonReplayShard',
            $publishedWorkers,
            'replay/cache shard exceptions must become focused non-passing evidence instead of aborting the PHP/Python shard',
        );

        $this->assertStringContainsString(
            'v1TaskCount > 0',
            $node,
            'the compatible replay cell may pass only when v1 receives work and v2 receives zero v1-pinned tasks',
        );
        $this->assertStringContainsString(
            'divergent_workflow_execution_observed',
            $node,
            'server-protocol counts alone must not pass the divergent replay cells',
        );
        $this->assertStringContainsString(
            "publishedReplayRunId !== ''",
            $node,
            'published replay counts must name the v1-pinned run they measured',
        );
        $this->assertStringContainsString(
            'const cacheEvictionPasses = publishedWorkerScenarioPasses',
            $node,
            'the cache-eviction cell must require published worker execution and zero incompatible delivery',
        );
        $this->assertStringContainsString(
            "publishedCacheRunId !== ''",
            $node,
            'cache-eviction evidence must name the v1-pinned run it replayed',
        );
        $this->assertStringContainsString(
            'publishedReplayWorkerBuildId === publishedExpectedReplayBuildId',
            $node,
            'published cache evidence must compare replay against the shard pinned-build field, not a separate HTTP probe build id',
        );
        $this->assertStringContainsString(
            "normalizedArtifactStatus(outputs.published_worker_evidence_status) !== 'pass'",
            $node,
            'a non-passing published-worker shard row cannot be promoted to passing evidence by compatible-looking counts',
        );
        $this->assertStringContainsString(
            "/api/workers/\${encodeURIComponent(v1WorkerId)}",
            $node,
            'the no-compatible cell must remove the compatible worker before polling with v2',
        );
        $this->assertStringContainsString(
            'build_id: buildId',
            $node,
            'direct HTTP worker polls must name the registered build id so incompatible-cohort probes are explicit',
        );
        $this->assertStringContainsString(
            'build_id: buildId,',
            $node,
            'direct HTTP worker poll outputs must preserve the requested build id for conformance evidence',
        );
        $this->assertStringContainsString(
            'noCompatibleServerProtocolProbePasses',
            $node,
            'the no-compatible cell may pass when the published-server protocol probe exercises the stopped-compatible-cohort topology',
        );
        $this->assertStringContainsString(
            'incompatibleWorkerTaskCount === 0',
            $node,
            'the no-compatible cell may pass only when the incompatible worker receives zero tasks',
        );
        $this->assertStringContainsString(
            'incompatibleWorkerPollAttempts > 0',
            $node,
            'the no-compatible cell may pass only after the incompatible cohort actually polls',
        );
        $this->assertStringContainsString(
            'compatibleWorkerDeregistered',
            $node,
            'the no-compatible cell must prove the compatible cohort was stopped',
        );
        $this->assertStringContainsString(
            'for (let attempt = 1; attempt <= 3; attempt += 1)',
            $node,
            'the server protocol probe should not prove no-compatible behavior from one lucky empty poll',
        );
        $this->assertStringContainsString(
            'isExplicitNoCompatibleSignal(publishedNoCompatibleSignal)',
            $node,
            'the no-compatible cell may pass only when zero incompatible delivery is paired with an explicit diagnostic',
        );
        $this->assertStringContainsString(
            'publishedNoCompatibleWorkerExecuted && publishedNoCompatibleIncompatibleCount > 0',
            $node,
            'published-worker evidence may only override a passing protocol probe when it actually observed incompatible delivery',
        );
        $protocolProbePassBranch = strpos($node, '} else if (noCompatibleProtocolProbePasses) {');
        $genericPublishedWorkerFailBranch = strpos($node, '} else if (publishedNoCompatibleWorkerExecuted) {');
        $this->assertIsInt($protocolProbePassBranch);
        $this->assertIsInt($genericPublishedWorkerFailBranch);
        $this->assertLessThan(
            $genericPublishedWorkerFailBranch,
            $protocolProbePassBranch,
            'a passing published-server protocol probe must not be masked by a published-worker shard that merely missed the diagnostic',
        );
        $this->assertStringContainsString(
            "addPass('no_compatible_worker_behavior', noCompatibleOutputs)",
            $node,
            'server protocol evidence against a published server artifact can prove the focused no-compatible cell',
        );
        $this->assertStringContainsString(
            'pendingWorkflowTaskDiagnosticSignals(noCompatibleBuildIdEntry)',
            $node,
            'the server protocol probe must accept the task-queue build-id pending-work diagnostic as an explicit no-compatible signal',
        );
        $this->assertStringContainsString(
            'waitForNoCompatibleVisibility('."\n      serverUrl,\n      noCompatibleWorkflowId,\n      noCompatibleRunId,\n      taskQueue,",
            $node,
            'the no-compatible visibility helper must receive the scoped task queue used for build-id diagnostics',
        );
        $this->assertStringContainsString(
            'task_queue_build_id_entry: noCompatibleBuildIdEntry',
            $node,
            'the server protocol probe must capture the build-id cohort row used to prove the no-compatible diagnostic',
        );
        $this->assertStringContainsString(
            'Date.now() + noCompatibleVisibilitySeconds * 1000',
            $node,
            'the server protocol probe must wait up to the public no-compatible visibility deadline',
        );
        $this->assertStringContainsString(
            'DW_WV_NO_COMPATIBLE_VISIBILITY_SECONDS',
            $node,
            'the no-compatible visibility deadline should be configurable for host conformance',
        );
        $this->assertStringContainsString(
            'task_queue_build_id_samples: noCompatibleVisibility.task_queue_build_id_samples',
            $node,
            'the server protocol probe must retain the sampled task-queue diagnostics that prove the explicit signal',
        );
        $this->assertStringContainsString(
            'taskQueueBuildIdSignalValuesFromOutputs(outputs)',
            $node,
            'published worker no-compatible evidence normalization must accept sampled task-queue diagnostics',
        );
        $this->assertStringContainsString(
            "publishedWorkerScenarioOutputs(publishedWorkerEvidence, 'adversarial_no_version_bump')",
            $node,
            'adversarial no-version-bump can pass only from a published worker evidence shard',
        );
        $this->assertStringContainsString(
            "addNotCovered('adversarial_no_version_bump'",
            $node,
            'the server protocol probe must leave adversarial no-version-bump non-passing without published worker execution',
        );
        $this->assertStringContainsString(
            'adversarialNoVersionBump',
            $node,
            'top-level host shard aliases must include adversarial no-version-bump evidence',
        );
        $this->assertStringContainsString(
            'no_compatible_worker_diagnostics',
            $this->read('static/platform-conformance/worker-versioning-runtime-scenarios.json'),
        );
        $this->assertStringNotContainsString('pending_or_health_surface', $node);
        $this->assertStringContainsString("addFail('replay_across_cache_eviction'", $node);

        foreach ([
            'runPythonReplayShard',
            'sequence-python-replay-v2-divergent',
            'published_python_worker_protocol_client',
            'fail_workflow_task',
            'v1_pinned_run_id: runId',
            'pinned_run_build_id: pinnedRunBuildId',
            'worker_task_counts_by_run',
            'replay_decision',
            'poll_outputs',
            'v1_first_task_id',
            'replay_task_id',
            'workflow_task_retry_of',
            'workflowTaskRetryOf',
            'expected_replay_worker_build_id: pinnedRunBuildId',
            "scenario_id: REPLAY_SCENARIO",
            "scenario_id: CACHE_EVICTION_SCENARIO",
            'runPythonAdversarialShard',
            "scenario_id: ADVERSARIAL_SCENARIO",
            'allow_register_error: true',
            'workflow_definition_changed',
            'workflowDefinitionFingerprintConflictVisible',
            'published_artifact_worker_execution: workerExecution',
            'runPythonNoCompatibleShard',
            "scenario_id: NO_COMPATIBLE_SCENARIO",
            "/api/workers/\${encodeURIComponent(noCompatibleV1WorkerId)}",
            'compatible_worker_deregistered: compatibleWorkerDeregistered',
            'incompatible_worker_task_count: incompatibleWorkerTaskCount',
            'incompatible_worker_poll_attempts: incompatiblePolls.length',
            'incompatible_worker_poll_statuses: incompatiblePollStatuses',
            'incompatible_worker_polls: incompatiblePolls',
            'started_workflow_visibility',
            'workflow_visibility_samples',
            'task_queue_build_id_entry',
            'task_queue_build_id_samples',
            'pending_workflow_tasks',
            'no_compatible_visibility_deadline_seconds',
            'no_compatible_visibility_attempts',
            'operator_visible_signal: operatorVisibleSignal',
            'isExplicitNoCompatibleSignal(operatorVisibleSignal)',
            'Date.now() + noCompatibleVisibilitySeconds * 1000',
            'DW_WV_NO_COMPATIBLE_VISIBILITY_SECONDS',
            'poll_timeout_seconds',
            'DW_WV_WORKER_POLL_CLIENT_TIMEOUT_SECONDS',
            'urllib.request.Request',
            'poll_workflow_task_response',
            'sdk_poll_envelope_used',
            'build_id=payload["build_id"]',
            '"http_status": http_status',
            '"poll_timeout" if exc.__class__.__name__ in ("TimeoutException", "TimeoutError", "timeout") else "poll_error"',
            '"error_type": error_type',
            ".replace(/[^a-z0-9]+/g, '_')",
            '.some((token) => normalized.includes(token))',
            'Published Python no-compatible-worker shard',
            '"poll_status": poll_status',
            "stringValue(existingScenario?.status) === 'pass'",
        ] as $token) {
            $this->assertStringContainsString($token, $publishedWorkers);
        }
    }

    public function test_no_compatible_execution_only_shard_does_not_inherit_probe_outputs(): void
    {
        $evidence = [
            'local_product_source_checkouts_used' => false,
            'supplied_shard_local_product_source_checkouts_used' => false,
            'source_path' => 'published-worker-execution-evidence.json',
            'scenario_results' => [
                'no_compatible_worker_behavior' => [
                    'status' => 'pass',
                    'observed_outputs' => [
                        'local_product_source_checkouts_used' => false,
                        'published_artifact_worker_execution' => [
                            'local_product_source_checkouts_used' => false,
                            'artifacts' => [
                                [
                                    'artifact' => 'sdk-python',
                                    'version' => '0.4.84',
                                    'source' => 'pypi_release',
                                    'status' => 'pass',
                                ],
                                [
                                    'artifact' => 'workflow-php',
                                    'version' => '2.0.0-alpha.189',
                                    'source' => 'composer_release',
                                    'status' => 'pass',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $result = $this->evaluateNoCompatiblePublishedWorkerEvidence($evidence);

        $this->assertTrue($result['worker_executed']);
        $this->assertFalse($result['passes']);
        $this->assertNull($result['incompatible_worker_task_count']);
        $this->assertSame('', $result['operator_visible_signal']);
        $this->assertArrayNotHasKey('incompatible_worker_task_count', $result['outputs']);
        $this->assertArrayNotHasKey('operator_visible_signal', $result['outputs']);
    }

    public function test_cross_language_published_shard_accepts_nested_delivery_cells(): void
    {
        $result = $this->evaluateCrossLanguagePublishedWorkerEvidence([
            'localProductSourceCheckoutsUsed' => false,
            'suppliedShardLocalProductSourceCheckoutsUsed' => false,
            'publishedArtifactWorkerExecution' => [
                'localProductSourceCheckoutsUsed' => false,
                'artifacts' => [
                    [
                        'id' => 'sdk-python',
                        'artifactVersion' => '0.4.88',
                        'artifactSource' => 'pypi_release',
                        'result' => 'pass',
                        'localProductSourceCheckoutsUsed' => false,
                    ],
                    [
                        'id' => 'workflow-php',
                        'artifactVersion' => '2.0.0-alpha.203',
                        'artifactSource' => 'packagist_release',
                        'result' => 'pass',
                        'localProductSourceCheckoutsUsed' => false,
                    ],
                ],
            ],
            'crossLanguageMatrix' => [
                'workerRuntimeIdentities' => [
                    ['workerId' => 'php-v1', 'runtime' => 'php', 'buildId' => 'php-build-v1'],
                    ['workerId' => 'python-v2', 'runtime' => 'python', 'buildId' => 'python-build-v2'],
                    ['workerId' => 'python-v1', 'runtime' => 'python', 'buildId' => 'python-build-v1'],
                    ['workerId' => 'php-v2', 'runtime' => 'php', 'buildId' => 'php-build-v2'],
                ],
                'workflowRuns' => [
                    'phpV1Started' => [
                        'workflowId' => 'php-run',
                        'runId' => 'run-php',
                        'pinnedBuildId' => 'php-build-v1',
                    ],
                    'pythonV1Started' => [
                        'workflowId' => 'python-run',
                        'runId' => 'run-python',
                        'pinnedBuildId' => 'python-build-v1',
                    ],
                ],
                'rolloutState' => [
                    'promotedBuildIds' => [
                        'phpStartedRun' => 'php-build-v1',
                        'pythonStartedRun' => 'python-build-v1',
                    ],
                ],
                'publicOutcome' => [
                    'verificationSurface' => 'published worker poll outputs and task-queue build-id rollout API',
                ],
                'cells' => [
                    [
                        'scenario' => 'php_v1_not_delivered_to_python_v2',
                        'startedBy' => 'workflow-php-v1',
                        'incompatibleWorker' => 'sdk-python-v2',
                        'compatibleDeliveryCount' => 2,
                        'incompatibleDeliveryCount' => 0,
                    ],
                    [
                        'scenario' => 'python_v1_not_delivered_to_php_v2',
                        'startedBy' => 'sdk-python-v1',
                        'incompatibleWorker' => 'workflow-php-v2',
                        'compatibleDeliveryCount' => 1,
                        'incompatibleDeliveryCount' => 0,
                    ],
                ],
            ],
        ]);

        $this->assertTrue($result['worker_executed']);
        $this->assertTrue($result['passes']);
        $this->assertSame(0, $result['php_v1_to_python_v2_incompatible_delivery_count']);
        $this->assertSame(0, $result['python_v1_to_php_v2_incompatible_delivery_count']);
        $this->assertSame(2, $result['php_v1_compatible_delivery_count']);
        $this->assertSame(1, $result['python_v1_compatible_delivery_count']);
        $this->assertSame(0, $result['outputs']['php_v1_to_python_v2_incompatible_delivery_count']);
        $this->assertTrue($result['outputs']['public_outcome']['passed']);
        $this->assertSame(
            'published_php_python_worker_protocol_clients',
            $result['outputs']['worker_execution_mode'],
        );
        $this->assertFalse($result['outputs']['server_protocol_probe_only']);
        $this->assertSame('php-build-v1', $result['outputs']['php_worker_build_id']);
        $this->assertSame('python-build-v1', $result['outputs']['python_worker_build_id']);
        $this->assertSame('php-v1', $result['outputs']['worker_runtime_identities'][0]['worker_id']);
        $this->assertSame('php-build-v1', $result['outputs']['workflow_runs']['php_v1_started']['pinned_build_id']);
        $this->assertSame('php-build-v1', $result['outputs']['rollout_state']['promoted_build_ids']['php_started_run']);
        $this->assertSame(
            'php_v1_not_delivered_to_python_v2',
            $result['outputs']['cross_language_delivery']['cells'][0]['scenario'],
        );
        $this->assertSame(
            0,
            $result['outputs']['cross_language_delivery']['cells'][0]['incompatible_delivery_count'],
        );
    }

    public function test_worker_registration_published_shard_accepts_public_surface_evidence(): void
    {
        $result = $this->evaluateWorkerRegistrationPublishedWorkerEvidence([
            'localProductSourceCheckoutsUsed' => false,
            'suppliedShardLocalProductSourceCheckoutsUsed' => false,
            'source_path' => 'published-worker-execution-evidence.json',
            'scenarioResults' => [
                [
                    'id' => 'worker_registration_build_ids',
                    'status' => 'pass',
                    'observedOutputs' => [
                        'taskQueue' => 'worker-versioning-shared',
                        'localProductSourceCheckoutsUsed' => false,
                        'workerRegistrationResponses' => [
                            'workflowPhp' => [
                                'artifact' => 'workflow-php',
                                'workerId' => 'php-v1',
                                'taskQueue' => 'worker-versioning-shared',
                                'buildId' => 'php-build-v1',
                                'response' => [
                                    'workerId' => 'php-v1',
                                    'taskQueue' => 'worker-versioning-shared',
                                    'buildId' => 'php-build-v1',
                                ],
                            ],
                            'sdkPython' => [
                                'artifact' => 'sdk-python',
                                'workerId' => 'python-v2',
                                'taskQueue' => 'worker-versioning-shared',
                                'buildId' => 'python-build-v2',
                                'response' => [
                                    'workerId' => 'python-v2',
                                    'taskQueue' => 'worker-versioning-shared',
                                    'buildId' => 'python-build-v2',
                                ],
                            ],
                        ],
                        'workerListSurface' => [
                            'workers' => [
                                ['workerId' => 'php-v1', 'buildId' => 'php-build-v1'],
                                ['workerId' => 'python-v2', 'buildId' => 'python-build-v2'],
                            ],
                        ],
                        'taskQueueBuildIdSurface' => [
                            'buildIds' => [
                                ['buildId' => 'php-build-v1', 'activeWorkerCount' => 1],
                                ['buildId' => 'python-build-v2', 'activeWorkerCount' => 1],
                            ],
                        ],
                        'publishedArtifactWorkerExecution' => [
                            'localProductSourceCheckoutsUsed' => false,
                            'artifacts' => [
                                [
                                    'artifact' => 'sdk-python',
                                    'version' => '0.4.88',
                                    'source' => 'pypi_release',
                                    'status' => 'pass',
                                    'localProductSourceCheckoutsUsed' => false,
                                ],
                                [
                                    'artifact' => 'workflow-php',
                                    'version' => '2.0.0-alpha.203',
                                    'source' => 'packagist_release',
                                    'status' => 'pass',
                                    'localProductSourceCheckoutsUsed' => false,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($result['worker_executed']);
        $this->assertTrue($result['passes']);
        $this->assertSame('worker-versioning-shared', $result['outputs']['task_queue']);
        $this->assertSame(
            ['php-build-v1', 'python-build-v2'],
            $result['worker_list_build_ids'],
        );
        $this->assertSame(
            ['php-build-v1', 'python-build-v2'],
            $result['task_queue_build_ids'],
        );
        $this->assertSame(1, $result['active_worker_counts_per_cohort']['php-build-v1']);
        $this->assertSame(1, $result['active_worker_counts_per_cohort']['python-build-v2']);
        $this->assertSame(
            'workflow-php',
            $result['outputs']['published_worker_registration_entries'][0]['artifact'],
        );
        $this->assertSame(
            'php-build-v1',
            $result['outputs']['published_worker_registration_entries'][0]['response_build_id'],
        );
        $this->assertTrue($result['outputs']['public_outcome']['passed']);
    }

    public function test_worker_registration_published_shard_requires_task_queue_build_id_surface(): void
    {
        $result = $this->evaluateWorkerRegistrationPublishedWorkerEvidence([
            'local_product_source_checkouts_used' => false,
            'supplied_shard_local_product_source_checkouts_used' => false,
            'source_path' => 'published-worker-execution-evidence.json',
            'scenario_results' => [
                'worker_registration_build_ids' => [
                    'status' => 'pass',
                    'observed_outputs' => [
                        'task_queue' => 'worker-versioning-shared',
                        'local_product_source_checkouts_used' => false,
                        'worker_registration_responses' => [
                            'workflow_php' => [
                                'artifact' => 'workflow-php',
                                'worker_id' => 'php-v1',
                                'task_queue' => 'worker-versioning-shared',
                                'build_id' => 'php-build-v1',
                                'response' => ['build_id' => 'php-build-v1'],
                            ],
                            'sdk_python' => [
                                'artifact' => 'sdk-python',
                                'worker_id' => 'python-v2',
                                'task_queue' => 'worker-versioning-shared',
                                'build_id' => 'python-build-v2',
                                'response' => ['build_id' => 'python-build-v2'],
                            ],
                        ],
                        'worker_list_build_ids' => ['php-build-v1', 'python-build-v2'],
                        'published_artifact_worker_execution' => [
                            'local_product_source_checkouts_used' => false,
                            'artifacts' => [
                                [
                                    'artifact' => 'sdk-python',
                                    'version' => '0.4.88',
                                    'source' => 'pypi_release',
                                    'status' => 'pass',
                                ],
                                [
                                    'artifact' => 'workflow-php',
                                    'version' => '2.0.0-alpha.203',
                                    'source' => 'packagist_release',
                                    'status' => 'pass',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($result['worker_executed']);
        $this->assertFalse($result['passes']);
        $this->assertContains('task_queue_build_ids', $result['missing']);
        $this->assertContains('active_worker_counts_per_cohort', $result['missing']);
        $this->assertFalse($result['public_surfaces_cover_build_ids']);
        $this->assertSame([], $result['task_queue_build_ids']);
    }

    public function test_no_compatible_published_shard_accepts_camel_case_outputs(): void
    {
        $result = $this->evaluateNoCompatiblePublishedWorkerEvidence([
            'localProductSourceCheckoutsUsed' => false,
            'suppliedShardLocalProductSourceCheckoutsUsed' => false,
            'source_path' => 'published-worker-execution-evidence.json',
            'scenarioResults' => [
                [
                    'id' => 'no_compatible_worker_behavior',
                    'status' => 'pass',
                    'observedOutputs' => [
                        'localProductSourceCheckoutsUsed' => false,
                        'incompatibleWorkerTaskCount' => 0,
                        'incompatibleWorkerPollAttempts' => 3,
                        'compatibleWorkerDeregistered' => true,
                        'operatorVisibleSignal' => 'no_compatible_worker',
                        'pendingOrTypedError' => 'pending',
                        'publishedArtifactWorkerExecution' => [
                            'localProductSourceCheckoutsUsed' => false,
                            'artifacts' => [
                                [
                                    'artifact' => 'sdk-python',
                                    'version' => '0.4.84',
                                    'source' => 'pypi_release',
                                    'status' => 'pass',
                                    'localProductSourceCheckoutsUsed' => false,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($result['worker_executed']);
        $this->assertTrue($result['passes']);
        $this->assertSame(0, $result['incompatible_worker_task_count']);
        $this->assertSame('no_compatible_worker', $result['operator_visible_signal']);
        $this->assertSame('pending', $result['pending_or_typed_error']);
        $this->assertSame(0, $result['outputs']['incompatible_worker_task_count']);
        $this->assertSame(3, $result['outputs']['incompatible_worker_poll_attempts']);
        $this->assertTrue($result['outputs']['compatible_worker_deregistered']);
        $this->assertSame('no_compatible_worker', $result['outputs']['operator_visible_signal']);
        $this->assertSame('pending', $result['outputs']['pending_or_typed_error']);
    }

    public function test_no_compatible_published_shard_accepts_top_level_no_compatible_section(): void
    {
        $result = $this->evaluateNoCompatiblePublishedWorkerEvidence([
            'suppliedShardLocalProductSourceCheckoutsUsed' => false,
            'source_path' => 'published-worker-execution-evidence.json',
            'publishedArtifactWorkerExecution' => [
                'localProductSourceCheckoutsUsed' => false,
                'artifacts' => [
                    [
                        'id' => 'sdk-python',
                        'artifactVersion' => '0.4.84',
                        'artifactSource' => 'pypi_release',
                        'result' => 'pass',
                        'localProductSourceCheckoutsUsed' => false,
                    ],
                ],
            ],
            'noCompatibleWorker' => [
                'incompatibleWorkerTaskCount' => 0,
                'incompatibleWorkerPollAttempts' => 2,
                'compatibleWorkerDeregistered' => true,
                'operatorVisibleSignal' => 'no_compatible_worker',
                'pendingOrTypedError' => 'pending',
            ],
        ]);

        $this->assertTrue($result['worker_executed']);
        $this->assertTrue($result['passes']);
        $this->assertSame(0, $result['incompatible_worker_task_count']);
        $this->assertSame('no_compatible_worker', $result['operator_visible_signal']);
        $this->assertSame('pending', $result['pending_or_typed_error']);
        $this->assertSame(0, $result['outputs']['incompatible_worker_task_count']);
        $this->assertSame(2, $result['outputs']['incompatible_worker_poll_attempts']);
        $this->assertTrue($result['outputs']['compatible_worker_deregistered']);
        $this->assertSame('no_compatible_worker', $result['outputs']['operator_visible_signal']);
    }

    public function test_no_compatible_published_shard_accepts_diagnostic_aliases(): void
    {
        $result = $this->evaluateNoCompatiblePublishedWorkerEvidence([
            'localProductSourceCheckoutsUsed' => false,
            'suppliedShardLocalProductSourceCheckoutsUsed' => false,
            'source_path' => 'published-worker-execution-evidence.json',
            'publishedWorkerExecution' => [
                'localProductSourceCheckoutsUsed' => false,
                'artifacts' => [
                    [
                        'id' => 'sdk-python',
                        'artifactVersion' => '0.4.84',
                        'artifactSource' => 'pypi_release',
                        'result' => 'pass',
                        'localProductSourceCheckoutsUsed' => false,
                    ],
                ],
            ],
            'noCompatibleWorkerDiagnostics' => [
                'incompatibleTaskCount' => 0,
                'pollAttempts' => 2,
                'compatibleCohortStopped' => true,
                'publicDiagnostic' => 'No compatible worker is currently available',
                'pendingState' => 'pending',
            ],
        ]);

        $this->assertTrue($result['worker_executed']);
        $this->assertTrue($result['passes']);
        $this->assertSame(0, $result['incompatible_worker_task_count']);
        $this->assertSame(
            'No compatible worker is currently available',
            $result['operator_visible_signal'],
        );
        $this->assertSame('pending', $result['pending_or_typed_error']);
        $this->assertSame(0, $result['outputs']['incompatible_worker_task_count']);
        $this->assertSame(2, $result['outputs']['incompatible_worker_poll_attempts']);
        $this->assertTrue($result['outputs']['compatible_worker_deregistered']);
    }

    public function test_no_compatible_published_shard_prefers_explicit_compatibility_signal_over_empty_poll(): void
    {
        $result = $this->evaluateNoCompatiblePublishedWorkerEvidence([
            'local_product_source_checkouts_used' => false,
            'supplied_shard_local_product_source_checkouts_used' => false,
            'source_path' => 'published-worker-execution-evidence.json',
            'scenario_results' => [
                'no_compatible_worker_behavior' => [
                    'status' => 'pass',
                    'observed_outputs' => [
                        'local_product_source_checkouts_used' => false,
                        'incompatible_worker_task_count' => 0,
                        'incompatible_worker_poll_attempts' => 3,
                        'compatible_worker_deregistered' => true,
                        'poll_status' => 'empty',
                        'compatibility_status' => 'no_compatible_worker',
                        'published_artifact_worker_execution' => [
                            'local_product_source_checkouts_used' => false,
                            'artifacts' => [
                                [
                                    'artifact' => 'sdk-python',
                                    'version' => '0.4.84',
                                    'source' => 'pypi_release',
                                    'status' => 'pass',
                                    'local_product_source_checkouts_used' => false,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($result['worker_executed']);
        $this->assertTrue($result['passes']);
        $this->assertSame(0, $result['incompatible_worker_task_count']);
        $this->assertSame('no_compatible_worker', $result['operator_visible_signal']);
        $this->assertSame('no_compatible_worker', $result['pending_or_typed_error']);
        $this->assertSame(3, $result['outputs']['incompatible_worker_poll_attempts']);
        $this->assertTrue($result['outputs']['compatible_worker_deregistered']);
        $this->assertSame('no_compatible_worker', $result['outputs']['operator_visible_signal']);
        $this->assertSame('no_compatible_worker', $result['outputs']['pending_or_typed_error']);
    }

    public function test_no_compatible_published_shard_accepts_workflow_visibility_samples(): void
    {
        $result = $this->evaluateNoCompatiblePublishedWorkerEvidence([
            'local_product_source_checkouts_used' => false,
            'supplied_shard_local_product_source_checkouts_used' => false,
            'source_path' => 'published-worker-execution-evidence.json',
            'scenario_results' => [
                'no_compatible_worker_behavior' => [
                    'status' => 'pass',
                    'observed_outputs' => [
                        'local_product_source_checkouts_used' => false,
                        'incompatible_worker_task_count' => 0,
                        'incompatible_worker_poll_attempts' => 3,
                        'compatible_worker_deregistered' => true,
                        'poll_status' => 'empty',
                        'workflow_visibility_samples' => [
                            ['compatibility_status' => 'compatible'],
                            ['compatibility_status' => 'no_compatible_worker'],
                        ],
                        'published_artifact_worker_execution' => [
                            'local_product_source_checkouts_used' => false,
                            'artifacts' => [
                                [
                                    'artifact' => 'sdk-python',
                                    'version' => '0.4.84',
                                    'source' => 'pypi_release',
                                    'status' => 'pass',
                                    'local_product_source_checkouts_used' => false,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($result['worker_executed']);
        $this->assertTrue($result['passes']);
        $this->assertSame(0, $result['incompatible_worker_task_count']);
        $this->assertSame('no_compatible_worker', $result['operator_visible_signal']);
        $this->assertSame('no_compatible_worker', $result['pending_or_typed_error']);
    }

    public function test_no_compatible_published_shard_rejects_poll_error_even_with_compatibility_status(): void
    {
        $result = $this->evaluateNoCompatiblePublishedWorkerEvidence([
            'local_product_source_checkouts_used' => false,
            'supplied_shard_local_product_source_checkouts_used' => false,
            'source_path' => 'published-worker-execution-evidence.json',
            'scenario_results' => [
                'no_compatible_worker_behavior' => [
                    'status' => 'pass',
                    'observed_outputs' => [
                        'local_product_source_checkouts_used' => false,
                        'incompatible_worker_task_count' => 0,
                        'incompatible_worker_poll_attempts' => 3,
                        'compatible_worker_deregistered' => true,
                        'poll_status' => 'poll_error',
                        'compatibility_status' => 'no_compatible_worker',
                        'published_artifact_worker_execution' => [
                            'local_product_source_checkouts_used' => false,
                            'artifacts' => [
                                [
                                    'artifact' => 'sdk-python',
                                    'version' => '0.4.84',
                                    'source' => 'pypi_release',
                                    'status' => 'pass',
                                    'local_product_source_checkouts_used' => false,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($result['worker_executed']);
        $this->assertFalse($result['passes']);
        $this->assertSame(0, $result['incompatible_worker_task_count']);
        $this->assertSame(3, $result['incompatible_worker_poll_attempts']);
        $this->assertSame(1, $result['incompatible_worker_poll_error_count']);
        $this->assertTrue($result['compatible_worker_deregistered']);
        $this->assertSame('no_compatible_worker', $result['operator_visible_signal']);
        $this->assertSame('no_compatible_worker', $result['pending_or_typed_error']);
        $this->assertSame(1, $result['outputs']['incompatible_worker_poll_error_count']);
    }

    public function test_runner_normalization_preserves_top_level_no_compatible_shard(): void
    {
        $evaluation = $this->evaluateNoCompatiblePublishedWorkerEvidenceThroughRunnerNormalization([
            'publishedArtifactWorkerExecution' => [
                'localProductSourceCheckoutsUsed' => false,
                'artifacts' => [
                    [
                        'id' => 'sdk-python',
                        'artifactVersion' => '0.4.84',
                        'artifactSource' => 'pypi_release',
                        'result' => 'pass',
                        'localProductSourceCheckoutsUsed' => false,
                    ],
                ],
            ],
            'noCompatibleWorker' => [
                'incompatibleWorkerTaskCount' => 0,
                'incompatibleWorkerPollAttempts' => 2,
                'compatibleWorkerDeregistered' => true,
                'operatorVisibleSignal' => 'no_compatible_worker',
                'pendingOrTypedError' => 'pending',
            ],
        ]);
        $normalized = $evaluation['normalized'];
        $result = $evaluation['result'];

        $this->assertArrayHasKey('no_compatible_worker_behavior', $normalized['scenario_results']);
        $this->assertArrayHasKey('published_artifact_worker_execution', $normalized);
        $this->assertFalse($normalized['supplied_shard_local_product_source_checkouts_used']);
        $this->assertTrue($result['worker_executed']);
        $this->assertTrue($result['passes']);
        $this->assertSame(0, $result['incompatible_worker_task_count']);
        $this->assertSame(2, $result['incompatible_worker_poll_attempts']);
        $this->assertTrue($result['compatible_worker_deregistered']);
        $this->assertSame('no_compatible_worker', $result['operator_visible_signal']);
        $this->assertSame('pending', $result['pending_or_typed_error']);
    }

    public function test_install_evidence_local_checkout_flags_are_preserved_and_non_passing(): void
    {
        $evaluation = $this->evaluateArtifactInstallEvidenceThroughRunnerNormalization([
            'local_product_source_checkouts_used' => false,
            'artifacts' => [
                [
                    'artifact' => 'server',
                    'version' => '0.2.250',
                    'source' => 'docker',
                    'status' => 'pass',
                    'local_product_source_checkouts_used' => false,
                ],
                [
                    'artifact' => 'cli',
                    'version' => '0.1.75',
                    'source' => 'official_install_script',
                    'status' => 'pass',
                    'local_product_source_checkouts_used' => false,
                ],
                [
                    'artifact' => 'sdk-python',
                    'version' => '0.4.84',
                    'source' => 'pypi_release',
                    'status' => 'pass',
                    'local_product_source_checkouts_used' => false,
                ],
                [
                    'artifact' => 'workflow-php',
                    'version' => '2.0.0-alpha.191',
                    'source' => 'composer_release',
                    'status' => 'pass',
                    'local_product_source_checkouts_used' => false,
                ],
                [
                    'artifact' => 'waterline',
                    'version' => '2.0.0-alpha.77',
                    'source' => 'local_product_source_checkout',
                    'status' => 'pass',
                    'local_product_source_checkouts_used' => 'true',
                ],
            ],
        ]);

        $this->assertFalse($evaluation['passes']);
        $this->assertTrue($evaluation['evidence']['local_product_source_checkouts_used']);
        $this->assertSame(
            'local_product_source_checkout',
            $evaluation['merged_sources']['waterline'],
            'forbidden install sources must remain visible in the row instead of being replaced by a fallback source',
        );
        $this->assertContains('waterline.source=local_product_source_checkout', $evaluation['gaps']);
        $this->assertContains('waterline.local_product_source_checkouts_used=true', $evaluation['gaps']);
    }

    public function test_published_worker_normalization_preserves_nested_local_checkout_flags(): void
    {
        $evaluation = $this->evaluateNoCompatiblePublishedWorkerEvidenceThroughRunnerNormalization([
            'local_product_source_checkouts_used' => false,
            'published_artifact_worker_execution' => [
                'local_product_source_checkouts_used' => false,
                'artifacts' => [
                    [
                        'artifact' => 'sdk-python',
                        'version' => '0.4.84',
                        'source' => 'pypi_release',
                        'status' => 'pass',
                        'local_product_source_checkouts_used' => 'true',
                    ],
                ],
            ],
            'no_compatible_worker' => [
                'incompatible_worker_task_count' => 0,
                'operator_visible_signal' => 'no_compatible_worker',
                'pending_or_typed_error' => 'pending',
            ],
        ]);

        $normalized = $evaluation['normalized'];
        $result = $evaluation['result'];

        $this->assertTrue($normalized['local_product_source_checkouts_used']);
        $this->assertTrue($normalized['supplied_shard_local_product_source_checkouts_used']);
        $this->assertFalse($result['worker_executed']);
        $this->assertFalse($result['passes']);
    }

    public function test_no_compatible_null_task_count_is_not_zero_evidence(): void
    {
        $result = $this->evaluateNoCompatiblePublishedWorkerEvidence([
            'local_product_source_checkouts_used' => false,
            'supplied_shard_local_product_source_checkouts_used' => false,
            'source_path' => 'published-worker-execution-evidence.json',
            'scenario_results' => [
                'no_compatible_worker_behavior' => [
                    'status' => 'pass',
                    'observed_outputs' => [
                        'local_product_source_checkouts_used' => false,
                        'incompatible_worker_task_count' => null,
                        'operator_visible_signal' => 'no_compatible_worker',
                        'pending_or_typed_error' => 'pending',
                        'published_artifact_worker_execution' => [
                            'local_product_source_checkouts_used' => false,
                            'artifacts' => [
                                [
                                    'artifact' => 'sdk-python',
                                    'version' => '0.4.84',
                                    'source' => 'pypi_release',
                                    'status' => 'pass',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($result['worker_executed']);
        $this->assertFalse($result['passes']);
        $this->assertNull($result['incompatible_worker_task_count']);
        $this->assertNull($result['outputs']['incompatible_worker_task_count']);
    }

    public function test_runner_writes_gate_consumable_result_and_record_files(): void
    {
        $node = $this->read('scripts/conformance/worker-versioning-published-artifacts.mjs');

        foreach ([
            'worker-versioning-result.json',
            'worker-versioning-record.json',
            'worker-versioning-http-captures.json',
            'durable-workflow.v2.worker-versioning-runtime.result',
            'runner_blocked: false',
            'artifact_versions: artifactVersions',
            'artifact_sources: result.artifact_sources',
            'required_scenarios: requiredScenarios',
            'reported_scenarios: scenarioEntries.map',
            'scenario_results: scenarioResults',
            'scenario_statuses: scenarioStatuses',
            'non_pass_scenarios: nonPassScenarios',
            'finding_links: result.finding_links',
            'no_compatible_worker: result.no_compatible_worker',
            "status: scenarioResults.no_compatible_worker_behavior.status",
            'operator_visible_signal_explicit: scenarioResults',
            'incompatible_worker_poll_attempts: scenarioResults',
            'compatible_worker_deregistered: scenarioResults',
            'published_server_protocol_probe: scenarioResults',
            'published_server_artifact: scenarioResults',
            'worker_execution_mode: scenarioResults.no_compatible_worker_behavior.observed_outputs.worker_execution_mode',
            'structured_findings: result.findings',
            'publishedWorkerShardProvesNoLocalSource',
            'topLevelPublishedWorkerScenarios',
        ] as $token) {
            $this->assertStringContainsString($token, $node);
        }
    }

    public function test_published_worker_shard_fallback_preserves_artifact_sources(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the worker-versioning runner fallback evidence.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $script = <<<'JS'
import { pathToFileURL } from 'node:url';

const moduleUrl = pathToFileURL(process.argv[2]).href;
const { publishedWorkerShardFallbackEvidence } = await import(moduleUrl);
const evidence = publishedWorkerShardFallbackEvidence(
  { status: 9, signal: null, error: null },
  {
    server: '0.2.391',
    cli: '0.1.80',
    'sdk-python': '0.4.88',
    workflow: '2.0.0-alpha.203',
    'workflow-php': '2.0.0-alpha.203',
    waterline: '2.0.0-alpha.86',
  },
  {
    server: 'published_docker_image',
    cli: 'official_install_script',
    'sdk-python': 'pypi_release',
    workflow: 'packagist_release',
    'workflow-php': 'packagist_release',
    waterline: 'published_waterline_release',
  },
);

console.log(JSON.stringify(evidence));
JS;

        $process = proc_open(
            [
                $nodeBinary,
                '--input-type=module',
                '-e',
                $script,
                'import-runner-helper',
                $repoRoot.'/scripts/conformance/worker-versioning-published-artifacts.mjs',
            ],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $repoRoot,
            ['PATH' => getenv('PATH') ?: '/usr/bin:/bin'],
        );

        $this->assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame(0, $exitCode, $stderr);

        $evidence = json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('published_docker_image', $evidence['artifact_sources']['server']);
        $this->assertSame('pypi_release', $evidence['artifact_sources']['sdk-python']);
        $this->assertSame('packagist_release', $evidence['artifact_sources']['workflow-php']);
        $this->assertSame(
            'not_covered',
            $evidence['scenario_results']['cross_language_php_python_pinning']['status'],
        );
        $this->assertFalse(
            $evidence['scenario_results']['cross_language_php_python_pinning']['observed_outputs']['published_artifact_worker_execution'],
        );
    }

    public function test_shell_timeout_handoff_writes_published_worker_fallback_evidence(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the worker-versioning shell fallback evidence.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $suffix = bin2hex(random_bytes(4));
        $resultDir = $repoRoot.'/storage/framework/worker-versioning-timeout-result-'.$suffix;
        $runRoot = $repoRoot.'/storage/framework/worker-versioning-timeout-run-'.$suffix;
        $fakeBin = $repoRoot.'/storage/framework/worker-versioning-timeout-bin-'.$suffix;
        mkdir($resultDir, 0777, true);
        mkdir($runRoot, 0777, true);
        mkdir($fakeBin, 0777, true);

        try {
            $fakeTimeout = $fakeBin.'/timeout';
            file_put_contents($fakeTimeout, "#!/bin/sh\nexit 124\n");
            chmod($fakeTimeout, 0755);
            $installEvidencePath = $resultDir.'/artifact-install-evidence.json';
            file_put_contents($installEvidencePath, json_encode([
                'local_product_source_checkouts_used' => false,
                'artifacts' => [
                    [
                        'artifact' => 'server',
                        'version' => '0.2.391',
                        'source' => 'docker',
                        'status' => 'pass',
                    ],
                    [
                        'artifact' => 'cli',
                        'version' => '0.1.80',
                        'source' => 'official_install_script',
                        'status' => 'pass',
                    ],
                    [
                        'artifact' => 'sdk-python',
                        'version' => '0.4.88',
                        'source' => 'pypi_release',
                        'status' => 'pass',
                    ],
                    [
                        'artifact' => 'workflow-php',
                        'version' => '2.0.0-alpha.203',
                        'source' => 'packagist_release',
                        'status' => 'pass',
                    ],
                    [
                        'artifact' => 'waterline',
                        'version' => '2.0.0-alpha.86',
                        'source' => 'published_waterline_release',
                        'status' => 'pass',
                    ],
                ],
            ], JSON_THROW_ON_ERROR));

            $path = $fakeBin.PATH_SEPARATOR.(getenv('PATH') ?: '/usr/bin:/bin');
            $process = proc_open(
                [
                    'bash',
                    $repoRoot.'/scripts/conformance/worker-versioning-published-artifacts.sh',
                    '--result-dir',
                    $resultDir,
                    '--keep-run-root',
                ],
                [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                $repoRoot,
                [
                    'PATH' => $path,
                    'DW_WV_RESULT_DIR' => $resultDir,
                    'DW_WV_RUN_ROOT' => $runRoot,
                    'DW_WV_SERVER_URL' => 'http://127.0.0.1:9',
                    'DW_WV_BLOCKED_REASON' => 'unit test stops after shell fallback evidence',
                    'DW_WV_ARTIFACT_INSTALL_EVIDENCE' => $installEvidencePath,
                    'DW_WV_PUBLISHED_WORKER_SHARD_TIMEOUT_SECONDS' => '1',
                    'DW_SERVER_VERSION' => '0.2.391',
                    'DW_CLI_VERSION' => '0.1.80',
                    'DW_PYTHON_SDK_VERSION' => '0.4.88',
                    'DW_WORKFLOW_PHP_VERSION' => '2.0.0-alpha.203',
                    'DW_WATERLINE_VERSION' => '2.0.0-alpha.86',
                    'DW_WV_SERVER_ARTIFACT_SOURCE' => 'docker',
                    'DW_CLI_ARTIFACT_SOURCE' => 'official_install_script',
                    'DW_PYTHON_SDK_ARTIFACT_SOURCE' => 'pypi_release',
                    'DW_WORKFLOW_PHP_ARTIFACT_SOURCE' => 'packagist_release',
                    'DW_WATERLINE_ARTIFACT_SOURCE' => 'published_waterline_release',
                ],
            );

            $this->assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            $this->assertSame(0, $exitCode, $stdout.$stderr);

            $evidencePath = $resultDir.'/published-worker-execution-evidence.json';
            $this->assertFileExists($evidencePath);

            $evidence = json_decode((string) file_get_contents($evidencePath), true, 512, JSON_THROW_ON_ERROR);
            $scenario = $evidence['scenario_results']['cross_language_php_python_pinning'];
            $outputs = $scenario['observed_outputs'];

            $this->assertSame('not_covered', $scenario['status']);
            $this->assertSame(1000, $outputs['shard_timeout_ms']);
            $this->assertSame(124, $outputs['shard_status']);
            $this->assertSame('SIGTERM', $outputs['shard_signal']);
            $this->assertFalse($outputs['published_artifact_worker_execution']);
            $this->assertSame('docker', $evidence['artifact_sources']['server']);
            $this->assertSame('pypi_release', $evidence['artifact_sources']['sdk-python']);
            $this->assertSame('packagist_release', $evidence['artifact_sources']['workflow-php']);
            $this->assertStringContainsString(
                'published worker shard did not complete during direct shell handoff',
                (string) file_get_contents($resultDir.'/published-worker-shard-direct.log'),
            );
        } finally {
            $this->removeDirectory($resultDir);
            $this->removeDirectory($runRoot);
            $this->removeDirectory($fakeBin);
        }
    }

    private function read(string $path): string
    {
        $fullPath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.$path;

        $this->assertFileExists($fullPath);

        return (string) file_get_contents($fullPath);
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
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }

    /**
     * @param array<string, mixed> $evidence
     * @return array<string, mixed>
     */
    private function evaluateArtifactInstallEvidenceThroughRunnerNormalization(array $evidence): array
    {
        $repoRoot = dirname(__DIR__, 2);
        $evidencePath = tempnam($repoRoot.'/storage/framework', 'artifact-install-evidence-');
        $this->assertIsString($evidencePath);
        $this->assertNotFalse(file_put_contents($evidencePath, json_encode($evidence, JSON_THROW_ON_ERROR)));

        try {
            $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
            if ($nodeBinary === '') {
                $this->markTestSkipped('node is required to exercise the worker-versioning install evidence gate.');
            }

            $script = <<<'JS'
import { pathToFileURL } from 'node:url';

const moduleUrl = pathToFileURL(process.argv[2]).href;
const {
  artifactInstallEvidence,
  artifactInstallEvidenceGaps,
  artifactInstallEvidencePasses,
  mergeArtifactSources,
} = await import(moduleUrl);
const artifactVersions = {
  server: '0.2.250',
  cli: '0.1.75',
  'sdk-python': '0.4.84',
  workflow: '2.0.0-alpha.191',
  'workflow-php': '2.0.0-alpha.191',
  waterline: '2.0.0-alpha.77',
};
const artifactSources = {
  server: 'docker',
  cli: 'official_install_script',
  'sdk-python': 'pypi_release',
  workflow: 'composer_release',
  'workflow-php': 'composer_release',
  waterline: 'published_waterline_release',
};
const evidence = artifactInstallEvidence(artifactVersions, artifactSources);

console.log(JSON.stringify({
  evidence,
  passes: artifactInstallEvidencePasses(evidence),
  gaps: artifactInstallEvidenceGaps(evidence),
  merged_sources: mergeArtifactSources(artifactSources, evidence),
}));
JS;

            $process = proc_open(
                [
                    $nodeBinary,
                    '--input-type=module',
                    '-e',
                    $script,
                    'import-runner-helper',
                    $repoRoot.'/scripts/conformance/worker-versioning-published-artifacts.mjs',
                ],
                [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                $repoRoot,
                [
                    'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
                    'DW_WV_ARTIFACT_INSTALL_EVIDENCE' => $evidencePath,
                ],
            );

            $this->assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            $this->assertSame(0, $exitCode, $stderr);

            return json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);
        } finally {
            @unlink($evidencePath);
        }
    }

    /**
     * @param array<string, mixed> $shard
     * @return array{normalized: array<string, mixed>, result: array<string, mixed>}
     */
    private function evaluateNoCompatiblePublishedWorkerEvidenceThroughRunnerNormalization(array $shard): array
    {
        $repoRoot = dirname(__DIR__, 2);
        $shardPath = tempnam($repoRoot.'/storage/framework', 'published-worker-evidence-');
        $this->assertIsString($shardPath);
        $this->assertNotFalse(file_put_contents($shardPath, json_encode($shard, JSON_THROW_ON_ERROR)));

        try {
            $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
            if ($nodeBinary === '') {
                $this->markTestSkipped('node is required to exercise the worker-versioning runner shard gate.');
            }

            $script = <<<'JS'
import { pathToFileURL } from 'node:url';

const moduleUrl = pathToFileURL(process.argv[2]).href;
const {
  noCompatiblePublishedWorkerEvidenceResult,
  publishedWorkerExecutionEvidence,
} = await import(moduleUrl);
const normalized = publishedWorkerExecutionEvidence(
  { 'sdk-python': '0.4.84', 'workflow-php': '2.0.0-alpha.189' },
  { 'sdk-python': 'pypi_release', 'workflow-php': 'composer_release' },
);

console.log(JSON.stringify({
  normalized,
  result: noCompatiblePublishedWorkerEvidenceResult(normalized),
}));
JS;

            $process = proc_open(
                [
                    $nodeBinary,
                    '--input-type=module',
                    '-e',
                    $script,
                    'import-runner-helper',
                    $repoRoot.'/scripts/conformance/worker-versioning-published-artifacts.mjs',
                ],
                [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                $repoRoot,
                [
                    'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
                    'DW_WV_PUBLISHED_WORKER_EVIDENCE' => $shardPath,
                ],
            );

            $this->assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            $this->assertSame(0, $exitCode, $stderr);

            return json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);
        } finally {
            @unlink($shardPath);
        }
    }

    /**
     * @param array<string, mixed> $evidence
     * @return array<string, mixed>
     */
    private function evaluateNoCompatiblePublishedWorkerEvidence(array $evidence): array
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the worker-versioning runner shard gate.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $script = <<<'JS'
import { pathToFileURL } from 'node:url';

const moduleUrl = pathToFileURL(process.argv[2]).href;
const evidence = JSON.parse(process.argv[3]);
const { noCompatiblePublishedWorkerEvidenceResult } = await import(moduleUrl);

console.log(JSON.stringify(noCompatiblePublishedWorkerEvidenceResult(evidence)));
JS;

        $process = proc_open(
            [
                $nodeBinary,
                '--input-type=module',
                '-e',
                $script,
                'import-runner-helper',
                $repoRoot.'/scripts/conformance/worker-versioning-published-artifacts.mjs',
                json_encode($evidence, JSON_THROW_ON_ERROR),
            ],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $repoRoot,
            ['PATH' => getenv('PATH') ?: '/usr/bin:/bin'],
        );

        $this->assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame(0, $exitCode, $stderr);

        return json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $evidence
     * @return array<string, mixed>
     */
    private function evaluateCrossLanguagePublishedWorkerEvidence(array $evidence): array
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the worker-versioning runner shard gate.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $script = <<<'JS'
import { pathToFileURL } from 'node:url';

const moduleUrl = pathToFileURL(process.argv[2]).href;
const evidence = JSON.parse(process.argv[3]);
const { crossLanguagePublishedWorkerEvidenceResult } = await import(moduleUrl);

console.log(JSON.stringify(crossLanguagePublishedWorkerEvidenceResult(evidence)));
JS;

        $process = proc_open(
            [
                $nodeBinary,
                '--input-type=module',
                '-e',
                $script,
                'import-runner-helper',
                $repoRoot.'/scripts/conformance/worker-versioning-published-artifacts.mjs',
                json_encode($evidence, JSON_THROW_ON_ERROR),
            ],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $repoRoot,
            ['PATH' => getenv('PATH') ?: '/usr/bin:/bin'],
        );

        $this->assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame(0, $exitCode, $stderr);

        return json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $evidence
     * @return array<string, mixed>
     */
    private function evaluateWorkerRegistrationPublishedWorkerEvidence(array $evidence): array
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the worker-versioning runner shard gate.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $script = <<<'JS'
import { pathToFileURL } from 'node:url';

const moduleUrl = pathToFileURL(process.argv[2]).href;
const evidence = JSON.parse(process.argv[3]);
const { workerRegistrationPublishedWorkerEvidenceResult } = await import(moduleUrl);

console.log(JSON.stringify(workerRegistrationPublishedWorkerEvidenceResult(evidence)));
JS;

        $process = proc_open(
            [
                $nodeBinary,
                '--input-type=module',
                '-e',
                $script,
                'import-runner-helper',
                $repoRoot.'/scripts/conformance/worker-versioning-published-artifacts.mjs',
                json_encode($evidence, JSON_THROW_ON_ERROR),
            ],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $repoRoot,
            ['PATH' => getenv('PATH') ?: '/usr/bin:/bin'],
        );

        $this->assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame(0, $exitCode, $stderr);

        return json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $baseShard
     * @param array<string, mixed> $supplementalShard
     * @return array<string, mixed>
     */
    private function mergePublishedWorkerShardValues(array $baseShard, array $supplementalShard): array
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the published worker shard merge contract.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $script = <<<'JS'
import { pathToFileURL } from 'node:url';

const moduleUrl = pathToFileURL(process.argv[2]).href;
const existing = JSON.parse(process.argv[3]);
const incoming = JSON.parse(process.argv[4]);
const { mergeShardValues } = await import(moduleUrl);

console.log(JSON.stringify(mergeShardValues(existing, incoming)));
JS;

        $process = proc_open(
            [
                $nodeBinary,
                '--input-type=module',
                '-e',
                $script,
                'import-published-worker-helper',
                $repoRoot.'/scripts/conformance/worker-versioning-published-workers.mjs',
                json_encode($baseShard, JSON_THROW_ON_ERROR),
                json_encode($supplementalShard, JSON_THROW_ON_ERROR),
            ],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $repoRoot,
            ['PATH' => getenv('PATH') ?: '/usr/bin:/bin'],
        );

        $this->assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame(0, $exitCode, $stderr);

        return json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);
    }
}
