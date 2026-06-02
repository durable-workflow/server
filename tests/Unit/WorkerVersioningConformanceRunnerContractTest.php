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

        foreach ([
            'v1_worker_task_count',
            'v2_worker_task_count_for_v1_run',
            'cache_eviction_observed',
            'replay_worker_build_id',
            'incompatible_delivery_count',
            'incompatible_worker_task_count',
            'pending_or_typed_error',
            'operator_visible_signal_explicit',
        ] as $field) {
            $this->assertStringContainsString($field, $node);
        }

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
            'const cacheEvictionPasses = publishedWorkerScenarioPasses',
            $node,
            'the cache-eviction cell must require published worker execution and zero incompatible delivery',
        );
        $this->assertStringContainsString(
            "/api/workers/\${encodeURIComponent(v1WorkerId)}",
            $node,
            'the no-compatible cell must remove the compatible worker before polling with v2',
        );
        $this->assertStringContainsString(
            'const publishedNoCompatiblePasses = publishedNoCompatibleWorkerExecuted',
            $node,
            'the no-compatible cell may pass only when published worker evidence exercised the stopped-compatible-cohort topology',
        );
        $this->assertStringContainsString(
            'publishedNoCompatibleIncompatibleCount === 0',
            $node,
            'the published no-compatible cell may pass only when the incompatible worker receives zero tasks',
        );
        $this->assertStringContainsString(
            'isExplicitNoCompatibleSignal(publishedNoCompatibleSignal)',
            $node,
            'the no-compatible cell may pass only when zero incompatible delivery is paired with an explicit diagnostic',
        );
        $this->assertStringContainsString(
            "addNotCovered('no_compatible_worker_behavior'",
            $node,
            'server protocol evidence alone must stay non-passing until a published worker topology exercises the cell',
        );
        $this->assertStringContainsString(
            'no_compatible_worker_diagnostics',
            $this->read('static/platform-conformance/worker-versioning-runtime-scenarios.json'),
        );
        $this->assertStringNotContainsString('pending_or_health_surface', $node);
        $this->assertStringContainsString("addFail('replay_across_cache_eviction'", $node);
    }

    public function test_no_compatible_execution_only_shard_does_not_inherit_probe_outputs(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the worker-versioning runner shard gate.');
        }

        $repoRoot = dirname(__DIR__, 2);
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
        $result = json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($result['worker_executed']);
        $this->assertFalse($result['passes']);
        $this->assertNull($result['incompatible_worker_task_count']);
        $this->assertSame('', $result['operator_visible_signal']);
        $this->assertArrayNotHasKey('incompatible_worker_task_count', $result['outputs']);
        $this->assertArrayNotHasKey('operator_visible_signal', $result['outputs']);
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
}
