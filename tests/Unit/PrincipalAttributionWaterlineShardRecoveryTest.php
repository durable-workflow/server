<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PrincipalAttributionWaterlineShardRecoveryTest extends TestCase
{
    public function test_successful_waterline_shard_preserves_normative_result_with_global_err_trap(): void
    {
        $evidence = $this->runWaterlineShard('success');
        $execution = $evidence['execution'];
        $aggregate = $evidence['aggregate_result'];
        $record = $evidence['aggregate_record'];
        $aggregateScenarios = array_column($aggregate['scenario_results'], null, 'scenario_id');
        $waterline = $aggregateScenarios['waterline_operator_visibility'];

        $this->assertTrue($execution['normative_result_written']);
        $this->assertNull($execution['failed_phase']);
        $this->assertSame(0, $execution['exit_status']);
        $this->assertFalse($execution['runner_blocked']);
        $this->assertTrue($execution['reached_published_product_behavior']);
        $this->assertSame(0, $execution['phase_statuses']['artisan_command']);
        $this->assertSame('2.0.0-alpha.132', $execution['artifact_versions']['waterline']);
        $this->assertStringContainsString(
            'waterline success diagnostic',
            $execution['diagnostic']['output_tail'],
        );
        $this->assertArrayNotHasKey('stale_fixture', $evidence['shard_result']);
        $this->assertTrue($evidence['shard_result']['normative_fixture']);

        $this->assertCount(11, $aggregateScenarios);
        $this->assertSame('pass', $aggregate['outcome']);
        $this->assertFalse($aggregate['runner_blocked']);
        $this->assertSame('pass', $waterline['status']);
        $this->assertSame($execution, $waterline['observed_outputs']['shard_execution']);
        $this->assertTrue($waterline['observed_outputs']['shard_report']['normative_fixture']);
        $this->assertSame('pass', $record['outcome']);
        $this->assertFalse($record['runnerBlocked']);
        $this->assertSame($waterline, $record['waterlineOperatorVisibility']);
        $this->assertSame($execution, $record['waterlineShardExecution']);
    }

    public function test_failing_waterline_shard_writes_classifiable_bounded_fallback_with_global_err_trap(): void
    {
        $evidence = $this->runWaterlineShard('failure');
        $execution = $evidence['execution'];
        $shardResult = $evidence['shard_result'];
        $aggregate = $evidence['aggregate_result'];
        $record = $evidence['aggregate_record'];
        $aggregateScenarios = array_column($aggregate['scenario_results'], null, 'scenario_id');
        $waterline = $aggregateScenarios['waterline_operator_visibility'];

        $this->assertFalse($execution['normative_result_written']);
        $this->assertSame('artisan_command', $execution['failed_phase']);
        $this->assertSame(42, $execution['exit_status']);
        $this->assertSame(42, $execution['phase_statuses']['artisan_command']);
        $this->assertFalse($execution['runner_blocked']);
        $this->assertTrue($execution['reached_published_product_behavior']);
        $this->assertSame('0.1.90', $execution['artifact_versions']['cli']);
        $this->assertSame('0.1.3', $execution['artifact_versions']['sdk-php']);
        $this->assertSame('0.4.99', $execution['artifact_versions']['sdk-python']);
        $this->assertSame('0.2.655', $execution['artifact_versions']['server']);
        $this->assertSame('2.0.0-alpha.280', $execution['artifact_versions']['workflow']);
        $this->assertSame('2.0.0-alpha.132', $execution['artifact_versions']['waterline']);
        $this->assertSame('published_package', $execution['artifact_sources']['waterline']);
        $this->assertTrue($execution['diagnostic']['truncated']);
        $this->assertLessThanOrEqual(
            $execution['diagnostic']['limit_bytes'],
            strlen($execution['diagnostic']['output_tail']),
        );
        $this->assertStringContainsString(
            'concrete waterline failure: fixture write refused',
            $execution['diagnostic']['output_tail'],
        );

        $this->assertArrayNotHasKey('stale_fixture', $shardResult);
        $this->assertSame('fail', $shardResult['outcome']);
        $this->assertFalse($shardResult['runner_blocked']);

        $this->assertCount(11, $aggregateScenarios);
        $this->assertSame('fail', $aggregate['outcome']);
        $this->assertFalse($aggregate['runner_blocked']);
        $this->assertSame('fail', $waterline['status']);
        $this->assertSame($execution, $waterline['observed_outputs']['shard_execution']);
        $this->assertStringContainsString(
            'concrete waterline failure: fixture write refused',
            $waterline['output_sample'],
        );
        $this->assertStringContainsString(
            'concrete waterline failure: fixture write refused',
            $aggregate['operator_visibility']['waterline']['execution']['diagnostic']['output_tail'],
        );
        $this->assertSame('fail', $record['outcome']);
        $this->assertFalse($record['runnerBlocked']);
        $this->assertSame($waterline, $record['waterlineOperatorVisibility']);
        $this->assertSame($execution, $record['waterlineShardExecution']);
    }

    public function test_container_runtime_prerequisite_remains_runner_blocked(): void
    {
        $evidence = $this->runWaterlineShard('runner-blocked');
        $execution = $evidence['execution'];
        $shardScenarios = array_column($evidence['shard_result']['scenario_results'], null, 'scenario_id');
        $aggregateScenarios = array_column($evidence['aggregate_result']['scenario_results'], null, 'scenario_id');

        $this->assertFalse($execution['normative_result_written']);
        $this->assertSame('create_project', $execution['failed_phase']);
        $this->assertSame(125, $execution['exit_status']);
        $this->assertTrue($execution['runner_blocked']);
        $this->assertFalse($execution['reached_published_product_behavior']);
        $this->assertSame('error', $evidence['shard_result']['outcome']);
        $this->assertTrue($evidence['shard_result']['runner_blocked']);
        $this->assertSame('runner_blocked', $shardScenarios['waterline_operator_visibility']['status']);
        $this->assertSame('error', $evidence['aggregate_result']['outcome']);
        $this->assertTrue($evidence['aggregate_result']['runner_blocked']);
        $this->assertSame('runner_blocked', $aggregateScenarios['waterline_operator_visibility']['status']);
        $this->assertTrue($evidence['aggregate_record']['runnerBlocked']);
        $this->assertStringContainsString(
            'container runtime unavailable',
            $execution['diagnostic']['output_tail'],
        );
    }

    /**
     * @return array{
     *     execution: array<string, mixed>,
     *     shard_result: array<string, mixed>,
     *     aggregate_result: array<string, mixed>,
     *     aggregate_record: array<string, mixed>
     * }
     */
    private function runWaterlineShard(string $mode): array
    {
        if (! is_file('/bin/bash') || ! $this->commandExists('python3')) {
            $this->markTestSkipped('bash and python3 are required to exercise Waterline shard recovery.');
        }

        $tempRoot = sys_get_temp_dir().'/dw-waterline-shard-recovery-'.bin2hex(random_bytes(6));
        $binDir = $tempRoot.'/bin';
        $runRoot = $tempRoot.'/run';
        $resultDir = $tempRoot.'/result';
        mkdir($binDir, 0777, true);
        mkdir($runRoot, 0777, true);
        mkdir($resultDir, 0777, true);

        try {
            $pins = [
                'server' => '0.2.655',
                'cli' => '0.1.90',
                'workflow' => '2.0.0-alpha.280',
                'sdk-php' => '0.1.3',
                'sdk-python' => '0.4.99',
                'waterline' => '2.0.0-alpha.132',
                'artifact_sources' => [
                    'server' => 'docker_image',
                    'cli' => 'published_install_script',
                    'workflow' => 'published_composer_package',
                    'sdk-php' => 'published_packagist_package',
                    'sdk-python' => 'published_pypi_package',
                    'waterline' => 'published_package',
                ],
            ];
            $encodedPins = json_encode($pins, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
            file_put_contents($runRoot.'/pins.json', $encodedPins);
            file_put_contents($resultDir.'/pins.json', $encodedPins);
            file_put_contents(
                $resultDir.'/waterline-principal-attribution-result.json',
                json_encode([
                    'stale_fixture' => true,
                    'scenario_results' => [[
                        'scenario_id' => 'waterline_operator_visibility',
                        'status' => 'pass',
                        'surface' => 'stale selected-run output',
                        'output_sample' => 'stale principal visible',
                        'principal_visible' => true,
                    ]],
                ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n",
            );
            file_put_contents(
                $resultDir.'/waterline-principal-attribution-execution.json',
                json_encode(['stale_fixture' => true], JSON_THROW_ON_ERROR)."\n",
            );
            $this->writeFakeDocker($binDir.'/docker');

            $runner = (string) file_get_contents($this->runnerPath());
            $begin = '# BEGIN principal-attribution-waterline-shard';
            $end = '# END principal-attribution-waterline-shard';
            $beginOffset = strpos($runner, $begin);
            $endOffset = $beginOffset === false ? false : strpos($runner, $end, $beginOffset);
            $this->assertNotFalse($beginOffset);
            $this->assertNotFalse($endOffset);
            $block = substr($runner, $beginOffset, $endOffset - $beginOffset + strlen($end));

            $harness = <<<'BASH'
#!/usr/bin/env bash
set -Eeuo pipefail
trap 'printf "unexpected global ERR trap\n" >&2; exit 97' ERR

run_root="$TEST_RUN_ROOT"
result_dir="$TEST_RESULT_DIR"
started_at="2026-07-14T15:20:34Z"
principal_suite_version=15
cli_version=0.1.90
workflow_php_version=2.0.0-alpha.280
waterline_version=2.0.0-alpha.132
BASH;
            $harness .= "\n".$block."\n";
            $harnessPath = $tempRoot.'/waterline-shard-harness.sh';
            file_put_contents($harnessPath, $harness);
            chmod($harnessPath, 0755);

            $process = proc_open(
                ['/bin/bash', $harnessPath],
                [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                dirname(__DIR__, 2),
                [
                    'PATH' => $binDir.':'.(getenv('PATH') ?: '/usr/bin:/bin'),
                    'HOME' => getenv('HOME') ?: $tempRoot,
                    'TEST_RUN_ROOT' => $runRoot,
                    'TEST_RESULT_DIR' => $resultDir,
                    'DW_WATERLINE_FAKE_MODE' => $mode,
                    'RUN_ID' => 'waterline-recovery-test',
                ],
            );

            $this->assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
            $this->assertSame(0, $exitCode, $stdout.$stderr);

            $orchestratorPath = $runRoot.'/orchestrate.py';
            file_put_contents($orchestratorPath, $this->extractOrchestrator($runner));
            $launcherPath = $runRoot.'/aggregate-fixture.py';
            $this->writeAggregateLauncher($launcherPath);
            $aggregateProcess = proc_open(
                ['python3', $launcherPath, $orchestratorPath],
                [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $aggregatePipes,
                dirname(__DIR__, 2),
                [
                    'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
                    'SERVER_URL' => 'http://127.0.0.1:19090',
                    'ANONYMOUS_SERVER_URL' => 'http://127.0.0.1:19091',
                    'RESULT_DIR' => $resultDir,
                    'DW_BIN' => '/bin/true',
                    'PYTHON_BIN' => 'python3',
                    'PHP_SDK_AUTOLOAD' => $runRoot.'/autoload.php',
                    'WATERLINE_PRINCIPAL_RESULT' => $resultDir.'/waterline-principal-attribution-result.json',
                    'WATERLINE_PRINCIPAL_EXECUTION' => $resultDir.'/waterline-principal-attribution-execution.json',
                    'STARTED_AT' => '2026-07-14T15:20:34Z',
                    'PRINCIPAL_ATTRIBUTION_SUITE_VERSION' => '15',
                ],
            );
            $this->assertIsResource($aggregateProcess);
            $aggregateStdout = stream_get_contents($aggregatePipes[1]);
            $aggregateStderr = stream_get_contents($aggregatePipes[2]);
            fclose($aggregatePipes[1]);
            fclose($aggregatePipes[2]);
            $aggregateExitCode = proc_close($aggregateProcess);
            $this->assertSame(0, $aggregateExitCode, $aggregateStdout.$aggregateStderr);

            return [
                'execution' => json_decode(
                    (string) file_get_contents($resultDir.'/waterline-principal-attribution-execution.json'),
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                ),
                'shard_result' => json_decode(
                    (string) file_get_contents($resultDir.'/waterline-principal-attribution-result.json'),
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                ),
                'aggregate_result' => json_decode(
                    (string) file_get_contents($resultDir.'/principal-attribution-result.json'),
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                ),
                'aggregate_record' => json_decode(
                    (string) file_get_contents($resultDir.'/principal-attribution-record.json'),
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                ),
            ];
        } finally {
            $this->removeTree($tempRoot);
        }
    }

    private function runnerPath(): string
    {
        return dirname(__DIR__, 2).'/scripts/conformance/principal-attribution-published-artifacts.sh';
    }

    private function extractOrchestrator(string $runner): string
    {
        $startMarker = <<<'MARKER'
cat > "$run_root/orchestrate.py" <<'PY'
MARKER;
        $endMarker = "\nPY\n\nSERVER_URL=";
        $start = strpos($runner, $startMarker);
        $this->assertNotFalse($start);
        $start += strlen($startMarker);
        if (substr($runner, $start, 1) === "\n") {
            $start++;
        }

        $end = strpos($runner, $endMarker, $start);
        $this->assertNotFalse($end);

        return substr($runner, $start, $end - $start);
    }

    private function writeAggregateLauncher(string $path): void
    {
        file_put_contents($path, <<<'PYTHON'
from __future__ import annotations

import importlib.util
import json
import os
import sys
from pathlib import Path


spec = importlib.util.spec_from_file_location("principal_attribution_orchestrate", sys.argv[1])
if spec is None or spec.loader is None:
    raise RuntimeError("could not load principal-attribution orchestrator")
orchestrator = importlib.util.module_from_spec(spec)
spec.loader.exec_module(orchestrator)

pins = json.loads((Path(os.environ["RESULT_DIR"]) / "pins.json").read_text(encoding="utf-8"))
versions = {
    key: pins[key]
    for key in ("server", "cli", "workflow", "sdk-php", "sdk-python", "waterline")
}
baseline_scenarios = [
    {"scenario_id": scenario_id, "status": "pass"}
    for scenario_id in (
        "published_artifact_install_only",
        "named_token_actor_matrix",
        "start_signal_cancel_spoofing",
        "query_attribution",
        "completion_failure_attribution",
        "server_originated_events",
        "anonymous_attribution",
        "python_sdk_visibility",
        "php_client_visibility",
        "cli_operator_visibility",
    )
]
sdk_parity = {
    "python_sdk_visibility": {"status": "pass"},
    "php_client_visibility": {"status": "pass"},
}
aggregate_status = orchestrator.write_principal_attribution_aggregate(
    versions=versions,
    artifact_sources=pins["artifact_sources"],
    findings=[],
    history_dumps={"fixture": {"status": "complete"}},
    scenario_results=baseline_scenarios,
    action_credentials={},
    main_spoofing_matrix=[],
    query_spoofing_matrix=[],
    anonymous_spoofing_matrix=[],
    cli_json_ok=True,
    sdk_principal_attribution_parity=sdk_parity,
    anonymous_failures=[],
    anonymous_principals={"WorkflowStarted": {"type": "server", "id": "anonymous"}},
    expected_anonymous_principal={"type": "server", "id": "anonymous"},
)
print(json.dumps({"aggregate_status": aggregate_status}))
PYTHON);
    }

    private function writeFakeDocker(string $path): void
    {
        file_put_contents($path, <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail

joined=" $* "
if [[ "${DW_WATERLINE_FAKE_MODE:-failure}" == "runner-blocked" && "$joined" == *" composer create-project "* ]]; then
  printf '%s\n' 'container runtime unavailable'
  exit 125
fi

if [[ "$joined" != *" waterline:principal-attribution-conformance "* ]]; then
  exit 0
fi

result_dir=""
args=("$@")
for ((index = 0; index < ${#args[@]}; index++)); do
  if [[ "${args[$index]}" == "-v" && "${args[$((index + 1))]}" == *":/result" ]]; then
    result_dir="${args[$((index + 1))]%:/result}"
    break
  fi
done

if [[ "${DW_WATERLINE_FAKE_MODE:-failure}" == "success" ]]; then
  printf '%s\n' 'waterline success diagnostic'
  cat > "$result_dir/waterline-principal-attribution-result.json" <<'JSON'
{
  "normative_fixture": true,
  "scenario_results": [
    {
      "scenario_id": "waterline_operator_visibility",
      "status": "pass",
      "surface": "selected-run detail API commands and timeline",
      "output_sample": "principal visible",
      "principal_visible": true
    }
  ]
}
JSON
  exit 0
fi

for ((index = 0; index < 4500; index++)); do
  printf 'x'
done
printf '\nconcrete waterline failure: fixture write refused\n'
exit 42
BASH);
        chmod($path, 0755);
    }

    private function commandExists(string $command): bool
    {
        foreach (explode(':', getenv('PATH') ?: '/usr/bin:/bin') as $directory) {
            if (is_file($directory.'/'.$command) && is_executable($directory.'/'.$command)) {
                return true;
            }
        }

        return false;
    }

    private function removeTree(string $path): void
    {
        if (! file_exists($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir() && ! $item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }
}
