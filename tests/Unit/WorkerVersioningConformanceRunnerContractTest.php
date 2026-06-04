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
        ] as $field) {
            $this->assertStringContainsString($field, $node);
        }

        $this->assertStringContainsString(
            'const crossLanguagePasses = publishedWorkerScenarioPasses',
            $node,
            'the PHP/Python cell must require published worker execution evidence before passing',
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
            'replay/cache/no-compatible/cross-language/adversarial',
            $shell,
            'the shell handoff must document every published-worker evidence cell required by the gate',
        );
        $this->assertStringContainsString(
            'worker-versioning-published-workers.mjs',
            $shell,
            'the shell handoff must attempt the published PHP/Python worker shard before aggregating results',
        );
        $this->assertStringContainsString(
            'DW_WV_SKIP_PUBLISHED_WORKER_SHARD',
            $shell,
            'the host runner must be able to skip automatic shard generation when it supplies a richer topology',
        );
        foreach ([
            'durable-workflow==${pythonVersion}',
            'durable-workflow/workflow:${workflowPhpVersion}',
            'pypi_release',
            'packagist_release',
            'published_php_python_worker_protocol_clients',
            'php_v1_not_delivered_to_python_v2',
            'python_v1_not_delivered_to_php_v2',
            'published_artifact_worker_execution',
            'local_product_source_checkouts_used: false',
            'mergeExistingShard',
            "stringValue(existingCrossLanguage.status) === 'pass'",
        ] as $token) {
            $this->assertStringContainsString($token, $publishedWorkers);
        }
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

    public function test_published_artifact_runner_gates_replay_cells_on_zero_incompatible_delivery(): void
    {
        $node = $this->read('scripts/conformance/worker-versioning-published-artifacts.mjs');
        $publishedWorkers = $this->read('scripts/conformance/worker-versioning-published-workers.mjs');

        foreach ([
            'v1_worker_task_count',
            'v2_worker_task_count_for_v1_run',
            'cache_eviction_observed',
            'replay_worker_build_id',
            'expected_replay_worker_build_id',
            'v1_pinned_run_id',
            'pinned_run_build_id',
            'incompatible_delivery_count',
            'incompatible_worker_task_count',
            'pending_or_typed_error',
            'operator_visible_signal_explicit',
        ] as $field) {
            $this->assertStringContainsString($field, $node);
        }

        $this->assertStringContainsString(
            'const pythonReplay = await runPythonReplayShardSafely(python);',
            $publishedWorkers,
            'a replay/cache shard exception must be recorded without preventing the cross-language cell from running',
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
            'isExplicitNoCompatibleSignal(publishedNoCompatibleSignal)',
            $node,
            'the no-compatible cell may pass only when zero incompatible delivery is paired with an explicit diagnostic',
        );
        $this->assertStringContainsString(
            "addPass('no_compatible_worker_behavior', noCompatibleOutputs)",
            $node,
            'server protocol evidence against a published server artifact can prove the focused no-compatible cell',
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
            'expected_replay_worker_build_id: pinnedRunBuildId',
            "scenario_id: REPLAY_SCENARIO",
            "scenario_id: CACHE_EVICTION_SCENARIO",
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
        $this->assertSame('no_compatible_worker', $result['outputs']['operator_visible_signal']);
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
            'scenario_results: scenarioResults',
            'publishedWorkerShardProvesNoLocalSource',
            'topLevelPublishedWorkerScenarios',
        ] as $token) {
            $this->assertStringContainsString($token, $node);
        }
    }

    private function read(string $path): string
    {
        $fullPath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.$path;

        $this->assertFileExists($fullPath);

        return (string) file_get_contents($fullPath);
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
}
