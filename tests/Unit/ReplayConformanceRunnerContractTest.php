<?php

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class ReplayConformanceRunnerContractTest extends TestCase
{
    public function test_runner_script_resolves_and_records_published_artifacts(): void
    {
        $source = $this->read('scripts/conformance/replay-published-artifacts.sh');

        $this->assertStringContainsString(
            'Usage: replay-published-artifacts.sh [--result-dir DIR|--result-dir=DIR] [--keep-run-root[=1|true]]',
            $source,
        );
        $this->assertStringContainsString('https://registry.hub.docker.com/v2/repositories/durableworkflow/server/tags', $source);
        $this->assertStringContainsString('github_release(', $source);
        $this->assertStringContainsString('"durable-workflow/cli"', $source);
        $this->assertStringContainsString('latest_pypi_version(', $source);
        $this->assertStringContainsString('latest_packagist_version(', $source);
        $this->assertStringContainsString('latest_crates_io_version(', $source);
        $this->assertStringContainsString('"artifact_sources"', $source);
        $this->assertStringContainsString('"local_product_source_checkouts_used": False', $source);
        $this->assertStringContainsString('docker pull "$server_image"', $source);
        $this->assertStringContainsString('docker compose -p "$compose_project" -f "$published_compose_file" up -d mysql redis', $source);
        $this->assertStringContainsString('docker compose -p "$compose_project" -f "$published_compose_file" run --rm bootstrap', $source);
        $this->assertStringContainsString('docker compose -p "$compose_project" -f "$published_compose_file" up -d --no-deps server', $source);
        $this->assertStringContainsString('GET /api/cluster/info did not expose replay_verification_contract', $source);
        $this->assertStringContainsString('VERSION="$cli_version"', $source);
        $this->assertStringContainsString('DURABLE_WORKFLOW_INSTALL_DIR="$run_root/cli/bin"', $source);
        $this->assertStringContainsString('"$dw_bin" --version', $source);
        $this->assertStringContainsString('"$dw_bin" server:health --server "$server_base_url" --token "$auth_token" --output=json', $source);
        $this->assertStringContainsString('published-artifact-install.json', $source);
    }

    public function test_cli_release_resolution_accepts_bare_and_v_prefixed_tags(): void
    {
        $source = $this->read('scripts/conformance/replay-published-artifacts.sh');

        $this->assertStringContainsString('import urllib.error', $source);
        $this->assertStringContainsString('def github_release_tag_candidates(override: str) -> list[str]:', $source);
        $this->assertStringContainsString('return list(dict.fromkeys([requested, normalized, f"v{normalized}"]))', $source);
        $this->assertStringContainsString('for candidate in github_release_tag_candidates(override):', $source);
        $this->assertStringContainsString('release = github_release_by_tag(repo, candidate)', $source);
        $this->assertStringContainsString('if exc.code == 404:', $source);
        $this->assertStringContainsString('resolved_tag = str(release.get("tag_name", tag))', $source);
        $this->assertStringNotContainsString(
            'tag = override if override.startswith("v") else f"v{override}"',
            $source,
            'explicit CLI versions must try the requested release tag before falling back to alternate semver spellings',
        );
    }

    public function test_server_resolution_rejects_non_release_and_mismatched_image_pins(): void
    {
        $source = $this->read('scripts/conformance/replay-published-artifacts.sh');

        $this->assertStringContainsString('if not exact_server_tag(override):', $source);
        $this->assertStringContainsString('DW_SERVER_VERSION must be an exact SemVer release', $source);
        $this->assertStringContainsString('DW_SERVER_VERSION does not match DW_SERVER_IMAGE tag', $source);
        $this->assertStringContainsString(
            'DW_SERVER_VERSION must name the exact release for a digest-pinned server image',
            $source,
        );
    }

    public function test_runner_composes_python_and_php_runtime_shards(): void
    {
        $source = $this->read('scripts/conformance/replay-published-artifacts.sh');

        $this->assertStringContainsString('durable-workflow-replay-conformance --json', $source);
        $this->assertStringContainsString('DW_PHP_SDK_VERSION="$php_sdk_version"', $source);
        $this->assertStringContainsString('scripts/conformance/php-sdk-published-artifacts.sh --result-dir /result', $source);
        $this->assertStringContainsString('"schema": "durable-workflow.v2.replay-conformance.sdk-php-shard"', $source);
        $this->assertStringContainsString('python-replay-shard.json', $source);
        $this->assertStringContainsString('php-replay-shard.json', $source);
        $this->assertStringContainsString('rust-replay-shard.json', $source);
        $this->assertStringContainsString('sdk-php-runtime-shard', $source);
        $this->assertStringContainsString('sdk-python-runtime-shard', $source);
        $this->assertStringContainsString('sdk-rust-runtime-shard', $source);
        $this->assertStringContainsString('cargo install durable-workflow --version "$rust_sdk_version"', $source);
        $this->assertStringContainsString('command -v durable-workflow-replay-conformance', $source);
        $this->assertStringContainsString('"artifact": "sdk-php"', $source);
        $this->assertStringContainsString('"package": "durable-workflow/sdk"', $source);
        $this->assertStringNotContainsString('workflow:v2:replay-conformance', $source);
    }

    public function test_php_shard_executes_every_required_replay_scenario_in_real_runtime_cells(): void
    {
        $runner = $this->read('scripts/conformance/replay-published-artifacts.sh');
        $phpCell = $this->read('scripts/conformance/php-sdk-published-artifacts.sh');
        $scenarioExpander = $this->read('scripts/conformance/php_sdk_replay_scenarios.py');

        $this->assertStringContainsString('DW_PHP_SDK_CONFORMANCE_REPLAY_MATRIX=1', $runner);
        $this->assertStringContainsString('evidence.get("executed_runtime_cell") is True', $scenarioExpander);
        $this->assertStringContainsString('runtime_cell.get("executed") is True', $scenarioExpander);
        $this->assertStringContainsString('observed.get("runtime_cell_executed") is True', $scenarioExpander);

        foreach ([
            'php_completed_history_activity_replay',
            'php_completed_history_signal_update_replay',
            'php_completed_history_wait_condition_replay',
            'php_completed_history_version_marker_replay',
            'php_completed_history_saga_compensation_replay',
            'php_worker_restart_completed_query',
            'php_worker_restart_activity_state',
            'php_worker_restart_signal_update_state',
            'php_worker_restart_wait_condition_state',
            'php_worker_restart_version_marker_state',
            'php_worker_restart_saga_compensation_state',
            'php_code_divergence_refusal',
            'php_in_flight_signal_restart_timing',
        ] as $scenario) {
            $this->assertStringContainsString("\$replayScenarioResults['{$scenario}']", $phpCell);
        }

        $this->assertStringContainsString("if (\$phase === 'run-replay-matrix')", $phpCell);
        $this->assertStringContainsString("if (\$phase === 'start-in-flight')", $phpCell);
        $this->assertStringContainsString("if (\$phase === 'finish-replay-matrix')", $phpCell);
        $this->assertStringContainsString("'executed_runtime_cell' => true", $phpCell);
        $this->assertStringContainsString("'observed_outcome' => 'non_determinism_error'", $phpCell);
        $this->assertStringContainsString("'observed_outcome' => 'same_next_decision_after_replay'", $phpCell);
        $this->assertStringContainsString("'evidence_files' => [", $phpCell);
    }

    public function test_php_shard_expands_early_worker_exit_into_explicit_failed_cells(): void
    {
        $scenarios = [
            'php_completed_history_activity_replay',
            'php_completed_history_signal_update_replay',
            'php_completed_history_wait_condition_replay',
            'php_completed_history_version_marker_replay',
            'php_completed_history_saga_compensation_replay',
            'php_worker_restart_completed_query',
            'php_worker_restart_activity_state',
            'php_worker_restart_signal_update_state',
            'php_worker_restart_wait_condition_state',
            'php_worker_restart_version_marker_state',
            'php_worker_restart_saga_compensation_state',
            'php_code_divergence_refusal',
            'php_in_flight_signal_restart_timing',
        ];
        $workspace = sys_get_temp_dir().'/dw-php-replay-expansion-'.bin2hex(random_bytes(6));
        mkdir($workspace, 0777, true);
        $sourcePath = $workspace.'/source.json';
        $expandedPath = $workspace.'/expanded.json';
        $failureSummary = 'The released PHP SDK raised DurableWorkflow\\Exception\\InvalidWorkerDefinition during worker_process_exit: '
            .'Invalid worker contract workflow php.sdk.failure. Make the first handler parameter DurableWorkflow\\Worker\\WorkflowContext.';
        file_put_contents($sourcePath, json_encode([
            'outcome' => 'fail',
            'runner_blocked' => false,
            'worker_startup' => ['outcome' => 'process_exit', 'process_exit_code' => 1],
            'findings' => [[
                'scenario_id' => 'php_sdk_lifecycle_surface',
                'summary' => $failureSummary,
                'owning_surface' => 'sdk-php',
            ]],
        ], JSON_THROW_ON_ERROR));

        try {
            $process = proc_open(
                [
                    'python3',
                    dirname(__DIR__, 2).'/scripts/conformance/php_sdk_replay_scenarios.py',
                    $sourcePath,
                    $expandedPath,
                ],
                [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                dirname(__DIR__, 2),
            );
            $this->assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(
                0,
                proc_close($process),
                "PHP replay expansion failed.\nstdout:\n{$stdout}\nstderr:\n{$stderr}",
            );

            $expanded = json_decode(
                (string) file_get_contents($expandedPath),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            $this->assertSame($scenarios, array_keys($expanded['scenario_results']));
            $this->assertTrue($expanded['scenario_contract']['all_declared_scenarios_emitted']);
            $this->assertFalse($expanded['scenario_contract']['all_declared_scenarios_executed']);
            $this->assertSame($scenarios, $expanded['scenario_contract']['missing_executed_scenarios']);
            foreach ($expanded['scenario_results'] as $scenarioId => $scenario) {
                $this->assertSame('fail', $scenario['status'], $scenarioId);
                $this->assertFalse($scenario['executed_runtime_cell'], $scenarioId);
                $this->assertFalse($scenario['observed_outputs']['runtime_cell_executed'], $scenarioId);
                $this->assertSame(
                    [$failureSummary],
                    $scenario['observed_outputs']['failure_summaries'],
                    $scenarioId,
                );
            }
        } finally {
            foreach (glob($workspace.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($workspace);
        }
    }

    public function test_runner_establishes_runtime_namespaces_before_starting_any_replay_shard(): void
    {
        $source = $this->read('scripts/conformance/replay-published-artifacts.sh');

        $namespaceSetup = strpos($source, '"schema": "durable-workflow.v2.replay-conformance.namespace-setup"');
        $pythonShard = strpos($source, 'durable-workflow-replay-conformance --json');
        $rustShard = strpos($source, '/cargo/bin/durable-workflow-replay-conformance');
        $phpShard = strpos($source, 'scripts/conformance/php-sdk-published-artifacts.sh --result-dir /result');

        $this->assertIsInt($namespaceSetup);
        $this->assertIsInt($pythonShard);
        $this->assertIsInt($rustShard);
        $this->assertIsInt($phpShard);
        $this->assertLessThan($pythonShard, $namespaceSetup);
        $this->assertLessThan($rustShard, $namespaceSetup);
        $this->assertLessThan($phpShard, $namespaceSetup);
        $this->assertStringContainsString('selected_namespaces = list(dict.fromkeys(["default", replay_namespace]))', $source);
        $this->assertStringContainsString('DW_PHP_SDK_CONFORMANCE_NAMESPACE="$replay_namespace"', $source);
        $this->assertStringContainsString('"X-Durable-Workflow-Control-Plane-Version": "2"', $source);
        $this->assertStringContainsString('"fresh_published_server_topology": True', $source);
        $this->assertStringContainsString('"server_response": {', $source);
        $this->assertStringContainsString('"runner_blocked": True', $source);
    }

    public function test_runner_installs_and_probes_waterline_before_install_only_can_pass(): void
    {
        $source = $this->read('scripts/conformance/replay-published-artifacts.sh');

        $this->assertStringContainsString('container_user="$(id -u):$(id -g)"', $source);
        $this->assertStringContainsString('COMPOSER_HOME=/tmp/composer', $source);
        $this->assertStringContainsString('COMPOSER_CACHE_DIR=/tmp/composer-cache', $source);
        $this->assertStringContainsString('docker run --rm "${composer_env_args[@]}" -v "$waterline_app:/app" -w /app composer:2', $source);
        $this->assertStringNotContainsString('docker run --rm -v "$waterline_app:/app" -w /app composer:2', $source);
        $this->assertStringContainsString('"durable-workflow/waterline:${waterline_version}@beta"', $source);
        $this->assertStringContainsString('"durable-workflow/workflow:${workflow_php_version}@beta"', $source);
        $this->assertStringContainsString('"durable-workflow/sdk:${php_sdk_version}@beta"', $source);
        $this->assertStringContainsString('waterline-probe.php', $source);
        $this->assertStringContainsString('\\Waterline\\Waterline::class', $source);
        $this->assertStringContainsString('\\Waterline\\WaterlineServiceProvider::class', $source);
        $this->assertStringContainsString('\\Waterline\\Support\\WorkflowPackageApiFloor::class', $source);
        $this->assertStringContainsString('artifact_install_evidence', $source);
        $this->assertStringContainsString('artifact_install_pass', $source);
        $this->assertStringNotContainsString(
            'and python_results.get("published_artifact_install_only", {}).get("status") == "pass"',
            $source,
        );
        $this->assertStringNotContainsString(
            'and php_results.get("published_artifact_install_only", {}).get("status") == "pass"',
            $source,
        );
    }

    public function test_runner_merges_the_full_replay_matrix_and_keeps_missing_cells_non_passing(): void
    {
        $source = $this->read('scripts/conformance/replay-published-artifacts.sh');

        foreach ([
            'php_completed_history_activity_replay',
            'php_worker_restart_saga_compensation_state',
            'python_code_divergence_refusal',
            'server_history_mutation_refusal',
            'malformed_history_refusal',
            'php_in_flight_signal_restart_timing',
            'rust_side_effect_replay_after_worker_restart',
            'rust_version_marker_replay_after_code_upgrade',
        ] as $scenario) {
            $this->assertStringContainsString('"'.$scenario.'"', $source);
        }

        $this->assertStringContainsString('"not_covered"', $source);
        $this->assertStringContainsString('"runner_blocked"', $source);
        $this->assertStringContainsString('"conformance_runner_coverage_gap"', $source);
        $this->assertStringContainsString('"replay-conformance-result.json"', $source);
        $this->assertStringContainsString('"replay-conformance-record.json"', $source);
        $this->assertStringContainsString('raise SystemExit(0 if outcome == "pass" and not runner_blocked else 1)', $source);
    }

    public function test_runner_shell_fallback_reports_every_required_scenario_when_python_is_missing(): void
    {
        $source = $this->read('scripts/conformance/replay-published-artifacts.sh');

        $this->assertStringContainsString('REPLAY_REQUIRED_SCENARIOS=(', $source);
        $this->assertStringContainsString('emit_shell_blocked_scenario_results "$escaped_reason"', $source);
        $this->assertStringContainsString('host_environment_failure', $source);
        $this->assertStringNotContainsString('"scenario_results": {},', $source);
        $this->assertStringContainsString('"published_artifact_install_only"', $source);
        $this->assertStringContainsString('"python_in_flight_signal_restart_timing"', $source);
        $this->assertStringContainsString('"php_in_flight_signal_restart_timing"', $source);
    }

    public function test_runner_records_published_topology_startup_diagnostics_as_product_evidence(): void
    {
        $source = $this->read('scripts/conformance/replay-published-artifacts.sh');

        foreach ([
            'capture_compose_diagnostics docker-compose',
            'docker-compose-dependencies-up.log',
            'server-bootstrap.log',
            'docker-compose-ps.log',
            'docker-compose-ps.json',
            'docker-compose-logs.log',
            'compose-startup-diagnostics.json',
            'bootstrap.log',
            'server.log',
            'mysql.log',
            'redis.log',
            'published_server_topology_failure_result',
            'published_server_topology_startup_failure',
            '"owning_surface": "server"',
            '"runner_blocked": False',
            '"runnerBlocked": False',
            '"published_server_topology_started": False',
            '"blocked_before_replay_execution": True',
            '"compose_service_status_file": "docker-compose-ps.json"',
            'docker_compose_up_dependencies',
            'server_bootstrap',
            'docker_compose_up_server',
            'server_ready_probe',
            'local compose_file="$published_compose_file"',
        ] as $needle) {
            $this->assertStringContainsString($needle, $source);
        }

        $this->assertStringNotContainsString(
            'docker compose -p "$compose_project" -f "$compose_file" config',
            $source,
            'published compose diagnostics must not dump expanded compose config because it contains interpolated secrets',
        );
        $this->assertStringNotContainsString(
            'compose_config',
            $source,
            'published compose diagnostics must not reference an expanded compose config artifact',
        );
        $this->assertStringNotContainsString(
            'config.yml',
            $source,
            'published compose diagnostics must not write expanded compose config artifacts',
        );

        $this->assertStringNotContainsString(
            'blocked_result "Replay conformance runner could not start the published server topology',
            $source,
            'published server startup failures must preserve tuple and service diagnostics as non-runner-blocked replay findings',
        );
    }

    public function test_runner_uses_server_checkout_compose_path_before_any_compose_call(): void
    {
        $source = $this->read('scripts/conformance/replay-published-artifacts.sh');

        $composeDefinition = strpos($source, 'published_compose_file="$(resolve_published_compose_file "$repo_root")"');
        $firstComposeCall = strpos($source, 'docker compose');

        $this->assertIsInt($composeDefinition);
        $this->assertIsInt($firstComposeCall);
        $this->assertLessThan(
            $firstComposeCall,
            $composeDefinition,
            'the published compose path must be resolved before cleanup, diagnostics, or startup can invoke docker compose',
        );
        $this->assertStringContainsString('resolve_server_repo_root()', $source);
        $this->assertStringContainsString('canonical_server_repo_root "$candidate/repos/server"', $source);
        $this->assertStringContainsString('repo_root="$(resolve_server_repo_root)"', $source);
        $this->assertStringContainsString('local compose_file="$published_compose_file"', $source);

        preg_match_all('/docker compose[^\n]+ -f "([^"]+)"/', $source, $matches);
        $this->assertNotEmpty($matches[1], 'the contract test must inspect replay docker compose invocations');
        foreach ($matches[1] as $composeFileArgument) {
            $this->assertContains(
                $composeFileArgument,
                ['$published_compose_file', '$compose_file'],
                'docker compose invocations must use the server checkout compose file, including diagnostics',
            );
        }

        $this->assertStringNotContainsString('-f docker-compose.published.yml', $source);
        $this->assertStringNotContainsString('published_compose_file="$repo_root/docker-compose.published.yml"', $source);
        $this->assertStringNotContainsString('-f "$repo_root/docker-compose.published.yml"', $source);
        $this->assertStringNotContainsString('-f "$run_root/docker-compose.published.yml"', $source);
        $this->assertStringNotContainsString('-f "$result_dir/docker-compose.published.yml"', $source);
    }

    public function test_staged_runner_resolves_compose_file_from_server_checkout_not_caller_directory(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $workspace = $this->makeTempDir('dw-replay-compose-contract');
        $stagedScript = $workspace.'/.tmp/scripts/conformance/replay-published-artifacts.sh';
        $serverCheckout = $workspace.'/repos/server';
        $binDir = $workspace.'/bin';
        $runRoot = $workspace.'/.tmp/run-root';
        $resultDir = $workspace.'/.tmp/results';
        $cwd = $workspace.'/.tmp/cwd';
        $dockerLog = $workspace.'/docker-argv.log';

        try {
            foreach ([
                dirname($stagedScript),
                $serverCheckout,
                $binDir,
                $runRoot,
                $resultDir,
                $cwd,
            ] as $directory) {
                $this->mkdirp($directory);
            }

            copy($repoRoot.'/scripts/conformance/replay-published-artifacts.sh', $stagedScript);
            chmod($stagedScript, 0755);
            copy($repoRoot.'/docker-compose.published.yml', $serverCheckout.'/docker-compose.published.yml');

            $realPython = trim((string) shell_exec('command -v python3 2>/dev/null'));
            if ($realPython === '') {
                $this->markTestSkipped('python3 is required for the replay runner contract.');
            }

            $pythonShim = <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
real_python=__REAL_PYTHON__
if [[ "${1:-}" == */resolve-pins.py ]]; then
  cat <<'JSON'
{
  "artifact_sources": {
    "cli": "github_release_asset",
    "sdk-php": "packagist_package",
    "sdk-python": "pypi_package",
    "sdk-rust": "crates_io_package",
    "server": "published_docker_image",
    "waterline": "packagist_package",
    "workflow-php": "packagist_package"
  },
  "artifact_versions": {
    "cli": "0.1.80",
    "sdk-php": "0.1.1",
    "sdk-python": "0.4.88",
    "sdk-rust": "0.1.13",
    "server": "0.2.407",
    "waterline": "2.0.0-alpha.92",
    "workflow-php": "2.0.0-alpha.204"
  },
  "cli_install_url": "https://example.invalid/install.sh",
  "schema": "durable-workflow.v2.replay-conformance.pins",
  "server_image": "durableworkflow/server:0.2.407"
}
JSON
  exit 0
fi
exec "$real_python" "$@"
SH;
            $this->writeExecutable(
                $binDir.'/python3',
                str_replace('__REAL_PYTHON__', escapeshellarg($realPython), $pythonShim),
            );

            $this->writeExecutable($binDir.'/curl', <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
output=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    -o)
      output="$2"
      shift 2
      ;;
    *)
      shift
      ;;
  esac
done
if [[ -z "$output" ]]; then
  exit 2
fi
cat > "$output" <<'INSTALL'
#!/usr/bin/env sh
set -eu
bin_name="${DURABLE_WORKFLOW_BIN_NAME:-dw}"
mkdir -p "$DURABLE_WORKFLOW_INSTALL_DIR"
cat > "$DURABLE_WORKFLOW_INSTALL_DIR/$bin_name" <<'DW'
#!/usr/bin/env sh
if [ "${1:-}" = "--version" ]; then
  echo "dw version 0.1.80"
  exit 0
fi
if [ "${1:-}" = "server:health" ]; then
  echo '{"status":"ok"}'
  exit 0
fi
echo "fake dw"
DW
chmod +x "$DURABLE_WORKFLOW_INSTALL_DIR/$bin_name"
INSTALL
SH);

            $this->writeExecutable($binDir.'/docker', <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
printf '%q ' "$@" >> "$DW_FAKE_DOCKER_LOG"
printf '\n' >> "$DW_FAKE_DOCKER_LOG"

if [[ "${1:-}" == "compose" ]]; then
  for arg in "$@"; do
    if [[ "$arg" == "config" ]]; then
      echo "expanded compose config must not be requested" >&2
      exit 64
    fi
  done
  if [[ "${2:-}" == "version" ]]; then
    exit 0
  fi
  for arg in "$@"; do
    if [[ "$arg" == "up" ]]; then
      echo "fake compose startup failure" >&2
      exit 1
    fi
  done
  exit 0
fi

if [[ "${1:-}" == "image" && "${2:-}" == "inspect" ]]; then
  if [[ "${3:-}" == "--format" ]]; then
    echo "durableworkflow/server@sha256:fake"
  fi
  exit 0
fi

exit 0
SH);

            $env = array_merge($_ENV, [
                'PATH' => $binDir.PATH_SEPARATOR.(string) getenv('PATH'),
                'DW_CONFORMANCE_TMPDIR' => $workspace.'/.tmp',
                'DW_FAKE_DOCKER_LOG' => $dockerLog,
                'DW_REPLAY_RESULT_DIR' => $resultDir,
                'DW_REPLAY_RUN_ROOT' => $runRoot,
                'DW_REPLAY_SERVER_PORT' => '39876',
                'DW_REPLAY_SKIP_DOCKER_PULL' => '1',
                'DW_SERVER_REPO_ROOT' => '',
                'SERVER_REPO_PATH' => '',
            ]);

            $process = proc_open(
                [$stagedScript, '--result-dir', $resultDir],
                [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                $cwd,
                $env,
            );
            $this->assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($process);

            $this->assertSame(
                1,
                $status,
                "the fake docker compose startup should stop the runner after diagnostics\nstdout:\n$stdout\nstderr:\n$stderr",
            );
            $this->assertFileExists($dockerLog);

            $log = (string) file_get_contents($dockerLog);
            $expectedComposeFile = $serverCheckout.'/docker-compose.published.yml';
            $this->assertStringContainsString('compose -p ', $log);
            $this->assertStringContainsString('-f '.$expectedComposeFile, $log);
            $this->assertStringNotContainsString($workspace.'/.tmp/docker-compose.published.yml', $log);
            $this->assertStringNotContainsString($cwd.'/docker-compose.published.yml', $log);
            $this->assertStringNotContainsString($resultDir.'/docker-compose.published.yml', $log);
            $this->assertStringNotContainsString(' config ', $log);

            preg_match_all('/compose [^\n]* -f ([^ ]+)/', $log, $matches);
            $this->assertNotEmpty($matches[1], 'the fake docker log must include compose file arguments');
            foreach ($matches[1] as $composeFile) {
                $this->assertSame(
                    $expectedComposeFile,
                    stripcslashes($composeFile),
                    'every compose invocation, including diagnostics and cleanup, must use the server checkout compose file',
                );
            }

            $this->assertFileExists($resultDir.'/compose-startup-diagnostics.json');
            $diagnostics = (string) file_get_contents($resultDir.'/compose-startup-diagnostics.json');
            $this->assertStringNotContainsString('compose_config', $diagnostics);
            $this->assertStringNotContainsString('config.yml', $diagnostics);
        } finally {
            $this->removeTree($workspace);
        }
    }

    public function test_staged_runner_rewrites_pass_record_when_cleanup_fails_after_success(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $workspace = $this->makeTempDir('dw-replay-cleanup-contract');
        $stagedScript = $workspace.'/scripts/conformance/replay-published-artifacts.sh';
        $serverCheckout = $workspace.'/repos/server';
        $binDir = $workspace.'/bin';
        $runRoot = $workspace.'/tmp/run-root';
        $resultDir = $workspace.'/tmp/results';
        $cwd = $workspace.'/tmp/cwd';
        $dockerLog = $workspace.'/docker-argv.log';
        $namespaceRequestLog = $workspace.'/namespace-requests.jsonl';
        $httpServerProcess = null;

        try {
            foreach ([
                dirname($stagedScript),
                $serverCheckout,
                $binDir,
                $runRoot,
                $resultDir,
                $cwd,
            ] as $directory) {
                $this->mkdirp($directory);
            }

            copy($repoRoot.'/scripts/conformance/replay-published-artifacts.sh', $stagedScript);
            chmod($stagedScript, 0755);
            copy(
                $repoRoot.'/scripts/conformance/distribution_identities.py',
                dirname($stagedScript).'/distribution_identities.py',
            );
            copy(
                $repoRoot.'/scripts/conformance/php_sdk_replay_scenarios.py',
                dirname($stagedScript).'/php_sdk_replay_scenarios.py',
            );
            copy($repoRoot.'/docker-compose.published.yml', $serverCheckout.'/docker-compose.published.yml');

            $realPython = trim((string) shell_exec('command -v python3 2>/dev/null'));
            if ($realPython === '') {
                $this->markTestSkipped('python3 is required for the replay runner contract.');
            }

            $portReservation = stream_socket_server('tcp://127.0.0.1:0', $socketErrorCode, $socketError);
            $this->assertIsResource(
                $portReservation,
                "could not reserve fake replay server port: [$socketErrorCode] $socketError",
            );
            $reservedAddress = (string) stream_socket_get_name($portReservation, false);
            fclose($portReservation);
            $httpServerPort = (int) substr((string) strrchr($reservedAddress, ':'), 1);
            $this->assertGreaterThan(0, $httpServerPort);

            $httpServerCode = <<<'PY'
from __future__ import annotations

import json
import sys
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

port = int(sys.argv[1])
request_log = Path(sys.argv[2])


class Handler(BaseHTTPRequestHandler):
    def do_POST(self) -> None:
        length = int(self.headers.get("Content-Length", "0"))
        body = self.rfile.read(length)
        payload = json.loads(body.decode("utf-8"))
        namespace = payload.get("name")
        control_plane_version = self.headers.get("X-Durable-Workflow-Control-Plane-Version")
        entry = {
            "path": self.path,
            "namespace": namespace,
            "control_plane_version": control_plane_version,
            "request_namespace": self.headers.get("X-Namespace"),
            "authorization_configured": bool(self.headers.get("Authorization")),
        }
        with request_log.open("a", encoding="utf-8") as stream:
            stream.write(json.dumps(entry, sort_keys=True) + "\n")

        if self.path != "/api/namespaces":
            self.respond(404, {"reason": "not_found"})
        elif control_plane_version != "2":
            self.respond(400, {"reason": "missing_control_plane_version"})
        elif namespace == "default":
            self.respond(409, {"namespace": "default", "reason": "namespace_already_exists"})
        else:
            self.respond(201, {"name": namespace, "status": "active"})

    def respond(self, status: int, payload: dict[str, object]) -> None:
        encoded = json.dumps(payload, sort_keys=True).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(encoded)))
        self.end_headers()
        self.wfile.write(encoded)

    def log_message(self, format: str, *args: object) -> None:
        pass


ThreadingHTTPServer(("127.0.0.1", port), Handler).serve_forever()
PY;
            $httpServerProcess = proc_open(
                [$realPython, '-c', $httpServerCode, (string) $httpServerPort, $namespaceRequestLog],
                [
                    0 => ['pipe', 'r'],
                    1 => ['file', $workspace.'/fake-http-server.log', 'a'],
                    2 => ['file', $workspace.'/fake-http-server.log', 'a'],
                ],
                $httpServerPipes,
                $cwd,
            );
            $this->assertIsResource($httpServerProcess);
            fclose($httpServerPipes[0]);
            $serverReady = false;
            for ($attempt = 0; $attempt < 100; $attempt++) {
                $socket = @fsockopen('127.0.0.1', $httpServerPort, $errorCode, $errorMessage, 0.05);
                if (is_resource($socket)) {
                    fclose($socket);
                    $serverReady = true;
                    break;
                }
                usleep(20_000);
            }
            $this->assertTrue($serverReady, 'fake replay server did not start');

            $pythonShim = <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
real_python=__REAL_PYTHON__

write_python_shard() {
  local output="$1"
  mkdir -p "$(dirname "$output")"
  cat > "$output" <<'JSON'
{
  "findings": [],
  "outcome": "pass",
  "scenario_results": {
    "malformed_history_refusal": {"scenario_id": "malformed_history_refusal", "status": "pass"},
    "python_code_divergence_refusal": {"scenario_id": "python_code_divergence_refusal", "status": "pass"},
    "python_completed_history_activity_replay": {"scenario_id": "python_completed_history_activity_replay", "status": "pass"},
    "python_completed_history_saga_compensation_replay": {"scenario_id": "python_completed_history_saga_compensation_replay", "status": "pass"},
    "python_completed_history_signal_update_replay": {"scenario_id": "python_completed_history_signal_update_replay", "status": "pass"},
    "python_completed_history_version_marker_replay": {"scenario_id": "python_completed_history_version_marker_replay", "status": "pass"},
    "python_completed_history_wait_condition_replay": {"scenario_id": "python_completed_history_wait_condition_replay", "status": "pass"},
    "python_in_flight_signal_restart_timing": {"scenario_id": "python_in_flight_signal_restart_timing", "status": "pass"},
    "python_worker_restart_activity_state": {"scenario_id": "python_worker_restart_activity_state", "status": "pass"},
    "python_worker_restart_completed_query": {"scenario_id": "python_worker_restart_completed_query", "status": "pass"},
    "python_worker_restart_saga_compensation_state": {"scenario_id": "python_worker_restart_saga_compensation_state", "status": "pass"},
    "python_worker_restart_signal_update_state": {"scenario_id": "python_worker_restart_signal_update_state", "status": "pass"},
    "python_worker_restart_version_marker_state": {"scenario_id": "python_worker_restart_version_marker_state", "status": "pass"},
    "python_worker_restart_wait_condition_state": {"scenario_id": "python_worker_restart_wait_condition_state", "status": "pass"},
    "server_history_mutation_refusal": {"scenario_id": "server_history_mutation_refusal", "status": "pass"}
  }
}
JSON
}

if [[ "${1:-}" == "-m" && "${2:-}" == "venv" ]]; then
  venv="${3:?venv path required}"
  mkdir -p "$venv/bin"
  cat > "$venv/bin/activate" <<ACT
deactivate() { :; }
PATH="$venv/bin:\${PATH}"
export PATH
ACT
  cat > "$venv/bin/python" <<PYTHON
#!/usr/bin/env bash
set -euo pipefail
real_python=$real_python
if [[ "\${1:-}" == "-m" && "\${2:-}" == "pip" ]]; then
  if [[ "\${3:-}" == "download" ]]; then
    destination=""
    previous=""
    for argument in "\$@"; do
      if [[ "\$previous" == "--dest" ]]; then
        destination="\$argument"
      fi
      previous="\$argument"
    done
    if [[ -n "\$destination" ]]; then
      mkdir -p "\$destination"
      printf '%s\n' 'fake published wheel bytes' \
        > "\$destination/durable_workflow-0.4.89-py3-none-any.whl"
    fi
  fi
  exit 0
fi
if [[ "\${1:-}" == "-" ]]; then
  cat >/dev/null
  printf '%s\n' '{"import_name":"durable_workflow","package":"durable-workflow","status":"pass","version":"0.4.89"}'
  exit 0
fi
exec "\$real_python" "\$@"
PYTHON
  chmod +x "$venv/bin/python"
  cat > "$venv/bin/durable-workflow-replay-conformance" <<'PYSH'
#!/usr/bin/env bash
set -euo pipefail
output=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --output)
      output="$2"
      shift 2
      ;;
    --output=*)
      output="${1#--output=}"
      shift
      ;;
    *)
      shift
      ;;
  esac
done
if [[ -z "$output" ]]; then
  exit 2
fi
write_python_shard() {
  local output="$1"
  mkdir -p "$(dirname "$output")"
  cat > "$output" <<'JSON'
{
  "findings": [],
  "outcome": "pass",
  "scenario_results": {
    "malformed_history_refusal": {"scenario_id": "malformed_history_refusal", "status": "pass"},
    "python_code_divergence_refusal": {"scenario_id": "python_code_divergence_refusal", "status": "pass"},
    "python_completed_history_activity_replay": {"scenario_id": "python_completed_history_activity_replay", "status": "pass"},
    "python_completed_history_saga_compensation_replay": {"scenario_id": "python_completed_history_saga_compensation_replay", "status": "pass"},
    "python_completed_history_signal_update_replay": {"scenario_id": "python_completed_history_signal_update_replay", "status": "pass"},
    "python_completed_history_version_marker_replay": {"scenario_id": "python_completed_history_version_marker_replay", "status": "pass"},
    "python_completed_history_wait_condition_replay": {"scenario_id": "python_completed_history_wait_condition_replay", "status": "pass"},
    "python_in_flight_signal_restart_timing": {"scenario_id": "python_in_flight_signal_restart_timing", "status": "pass"},
    "python_worker_restart_activity_state": {"scenario_id": "python_worker_restart_activity_state", "status": "pass"},
    "python_worker_restart_completed_query": {"scenario_id": "python_worker_restart_completed_query", "status": "pass"},
    "python_worker_restart_saga_compensation_state": {"scenario_id": "python_worker_restart_saga_compensation_state", "status": "pass"},
    "python_worker_restart_signal_update_state": {"scenario_id": "python_worker_restart_signal_update_state", "status": "pass"},
    "python_worker_restart_version_marker_state": {"scenario_id": "python_worker_restart_version_marker_state", "status": "pass"},
    "python_worker_restart_wait_condition_state": {"scenario_id": "python_worker_restart_wait_condition_state", "status": "pass"},
    "server_history_mutation_refusal": {"scenario_id": "server_history_mutation_refusal", "status": "pass"}
  }
}
JSON
}
write_python_shard "$output"
printf '%s\n' '{"runtime":"sdk-python","status":"pass"}'
PYSH
  chmod +x "$venv/bin/durable-workflow-replay-conformance"
  exit 0
fi

if [[ "${1:-}" == */resolve-pins.py ]]; then
  cat <<'JSON'
{
  "artifact_sources": {
    "cli": "github_release_asset",
    "sdk-php": "packagist_package",
    "sdk-python": "pypi_package",
    "sdk-rust": "crates_io_package",
    "server": "published_docker_image",
    "waterline": "packagist_package",
    "workflow-php": "packagist_package"
  },
  "artifact_versions": {
    "cli": "0.1.81",
    "sdk-php": "0.1.1",
    "sdk-python": "0.4.89",
    "sdk-rust": "0.1.13",
    "server": "0.2.449",
    "waterline": "2.0.0-alpha.111",
    "workflow-php": "2.0.0-alpha.210"
  },
  "cli_install_url": "https://example.invalid/install.sh",
  "schema": "durable-workflow.v2.replay-conformance.pins",
  "server_image": "durableworkflow/server:0.2.449"
}
JSON
  exit 0
fi

if [[ "${1:-}" == "-" ]]; then
  tmp="$(mktemp)"
  cat > "$tmp"
  if grep -q 'published server did not become ready' "$tmp"; then
    printf '%s\n' 'ready 200'
    /bin/rm -f "$tmp"
    exit 0
  fi
  if grep -q 'durable-workflow.v2.replay-conformance.namespace-setup' "$tmp"; then
    namespace="${4:?replay namespace required}"
    output="${5:?namespace setup output path required}"
    compose_project="${6:?compose project required}"
    if [[ "${DW_FAKE_NAMESPACE_SETUP_FAILURE:-0}" == "1" ]]; then
      cat > "$output" <<JSON
{
  "isolation": {"compose_project": "$compose_project", "fresh_published_server_topology": true},
  "outcome": "fail",
  "requests": [
    {"establishment": "already_exists", "namespace": "default", "server_response": {"reason": "namespace_already_exists", "status_code": 409}, "status": "pass"},
    {"establishment": "rejected", "namespace": "$namespace", "server_response": {"payload": {"namespace": "$namespace", "reason": "namespace_not_found"}, "reason": "namespace_not_found", "status_code": 404}, "status": "fail"}
  ],
  "runtime_namespaces": {"sdk-php": "$namespace", "sdk-python": null, "sdk-rust": "default"},
  "schema": "durable-workflow.v2.replay-conformance.namespace-setup",
  "selected_namespace": "$namespace",
  "selected_namespaces": ["default", "$namespace"]
}
JSON
      printf '%s\n' '{"outcome":"fail"}'
      /bin/rm -f "$tmp"
      exit 1
    fi
  fi
  if grep -q 'GET /api/cluster/info did not expose replay_verification_contract' "$tmp"; then
    output="${4:?cluster info output path required}"
    mkdir -p "$(dirname "$output")"
    printf '%s\n' '{"replay_verification_contract":{}}' > "$output"
    printf '%s\n' 'cluster info replay_verification_contract exposed'
    /bin/rm -f "$tmp"
    exit 0
  fi
  "$real_python" "$@" < "$tmp"
  status=$?
  /bin/rm -f "$tmp"
  exit "$status"
fi

exec "$real_python" "$@"
SH;
            $this->writeExecutable(
                $binDir.'/python3',
                str_replace('__REAL_PYTHON__', escapeshellarg($realPython), $pythonShim),
            );

            $this->writeExecutable($binDir.'/curl', <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
output=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    -o)
      output="$2"
      shift 2
      ;;
    *)
      shift
      ;;
  esac
done
if [[ -z "$output" ]]; then
  exit 2
fi
cat > "$output" <<'INSTALL'
#!/usr/bin/env sh
set -eu
bin_name="${DURABLE_WORKFLOW_BIN_NAME:-dw}"
mkdir -p "$DURABLE_WORKFLOW_INSTALL_DIR"
cat > "$DURABLE_WORKFLOW_INSTALL_DIR/$bin_name" <<'DW'
#!/usr/bin/env sh
if [ "${1:-}" = "--version" ]; then
  echo "dw version 0.1.81"
  exit 0
fi
if [ "${1:-}" = "server:health" ]; then
  echo '{"status":"ok"}'
  exit 0
fi
echo "fake dw"
DW
chmod +x "$DURABLE_WORKFLOW_INSTALL_DIR/$bin_name"
INSTALL
SH);

            $this->writeExecutable($binDir.'/docker', <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
printf '%q ' "$@" >> "$DW_FAKE_DOCKER_LOG"
printf '\n' >> "$DW_FAKE_DOCKER_LOG"

write_php_shard() {
  local output="$1"
  mkdir -p "$(dirname "$output")"
  cat > "$output" <<'JSON'
{
  "apache_avro_provenance": {"package": "apache/avro", "version": "1.12.0", "dist": {"url": "https://api.github.com/repos/apache/avro/zipball/example"}},
  "artifact_versions": {"sdk-php": "0.1.1", "server": "0.2.449"},
  "assertions": {
    "activity_callback_once_for_replay": true,
    "activity_callback_cardinality_by_phase": true,
    "apache_avro_dependency": true,
    "distinct_worker_restart_processes": true,
    "durable_replay_history": true,
    "durable_replay_result": true,
    "exact_sdk_version": true,
    "replay_checkpoint": true,
    "sdk_dist_provenance": true
  },
  "findings": [],
  "local_product_source_checkouts_used": false,
  "outcome": "pass",
  "package_provenance": {"name": "durable-workflow/sdk", "version": "0.1.1", "dist": {"url": "https://api.github.com/repos/durable-workflow/sdk-php/zipball/example"}},
  "replay_scenario_results": {
    "php_code_divergence_refusal": {"scenario_id": "php_code_divergence_refusal", "status": "pass", "executed_runtime_cell": true, "runtime_cell": {"cell_id": "php-code-divergence", "executed": true}, "observed_outputs": {"runtime_cell_executed": true}, "replay_diagnostics": {"observed_outcome": "non_determinism_error", "workflow_sequence": 1, "expected_shape": "php.sdk.echo", "recorded_event_types": ["ActivityScheduled"], "message": "Recorded activity detail changed."}},
    "php_completed_history_activity_replay": {"scenario_id": "php_completed_history_activity_replay", "status": "pass", "executed_runtime_cell": true, "runtime_cell": {"cell_id": "php-completed-history", "executed": true}, "observed_outputs": {"runtime_cell_executed": true, "activity_completed_events": 3}},
    "php_completed_history_saga_compensation_replay": {"scenario_id": "php_completed_history_saga_compensation_replay", "status": "pass", "executed_runtime_cell": true, "runtime_cell": {"cell_id": "php-completed-history", "executed": true}, "observed_outputs": {"runtime_cell_executed": true, "activity_failed_events": 1}},
    "php_completed_history_signal_update_replay": {"scenario_id": "php_completed_history_signal_update_replay", "status": "pass", "executed_runtime_cell": true, "runtime_cell": {"cell_id": "php-completed-history", "executed": true}, "observed_outputs": {"runtime_cell_executed": true, "signals": [7], "updates": [19]}},
    "php_completed_history_version_marker_replay": {"scenario_id": "php_completed_history_version_marker_replay", "status": "pass", "executed_runtime_cell": true, "runtime_cell": {"cell_id": "php-completed-history", "executed": true}, "observed_outputs": {"runtime_cell_executed": true, "side_effect_recorded_events": 1}},
    "php_completed_history_wait_condition_replay": {"scenario_id": "php_completed_history_wait_condition_replay", "status": "pass", "executed_runtime_cell": true, "runtime_cell": {"cell_id": "php-completed-history", "executed": true}, "observed_outputs": {"runtime_cell_executed": true, "timer_fired_events": 1}},
    "php_in_flight_signal_restart_timing": {"scenario_id": "php_in_flight_signal_restart_timing", "status": "pass", "executed_runtime_cell": true, "runtime_cell": {"cell_id": "php-in-flight-signal", "executed": true}, "observed_outputs": {"runtime_cell_executed": true, "observed_outcome": "same_next_decision_after_replay", "worker_restart_at": "2026-01-01T00:00:01.000000Z", "signal_sent_at": "2026-01-01T00:00:00.000000Z", "history_reloaded_at": "2026-01-01T00:00:02.000000Z", "replayed_next_decision": "schedule_activity:php.sdk.echo"}},
    "php_worker_restart_activity_state": {"scenario_id": "php_worker_restart_activity_state", "status": "pass", "executed_runtime_cell": true, "runtime_cell": {"cell_id": "php-completed-query", "executed": true}, "observed_outputs": {"runtime_cell_executed": true, "activity_completed_events": 3}},
    "php_worker_restart_completed_query": {"scenario_id": "php_worker_restart_completed_query", "status": "pass", "executed_runtime_cell": true, "runtime_cell": {"cell_id": "php-completed-query", "executed": true}, "observed_outputs": {"runtime_cell_executed": true, "query_process_matches_restarted_worker": true}},
    "php_worker_restart_saga_compensation_state": {"scenario_id": "php_worker_restart_saga_compensation_state", "status": "pass", "executed_runtime_cell": true, "runtime_cell": {"cell_id": "php-completed-query", "executed": true}, "observed_outputs": {"runtime_cell_executed": true, "activity_failed_events": 1}},
    "php_worker_restart_signal_update_state": {"scenario_id": "php_worker_restart_signal_update_state", "status": "pass", "executed_runtime_cell": true, "runtime_cell": {"cell_id": "php-completed-query", "executed": true}, "observed_outputs": {"runtime_cell_executed": true, "signals": [7], "updates": [19]}},
    "php_worker_restart_version_marker_state": {"scenario_id": "php_worker_restart_version_marker_state", "status": "pass", "executed_runtime_cell": true, "runtime_cell": {"cell_id": "php-completed-query", "executed": true}, "observed_outputs": {"runtime_cell_executed": true, "side_effect_recorded_events": 1}},
    "php_worker_restart_wait_condition_state": {"scenario_id": "php_worker_restart_wait_condition_state", "status": "pass", "executed_runtime_cell": true, "runtime_cell": {"cell_id": "php-completed-query", "executed": true}, "observed_outputs": {"runtime_cell_executed": true, "timer_fired_events": 1}}
  }
}
JSON

  local oversized="$output.oversized"
  {
    head -n -1 "$output"
    printf ',\n  "ephemeral_diagnostic_payload": "'
    head -c 4300000 /dev/zero | tr '\000' x
    printf '"\n}\n'
  } > "$oversized"
  mv "$oversized" "$output"
}

write_rust_shard() {
  local output="$1"
  mkdir -p "$(dirname "$output")"
  cat > "$output" <<'JSON'
{
  "findings": [],
  "outcome": "pass",
  "scenario_results": {
    "rust_side_effect_replay_after_worker_restart": {"scenario_id": "rust_side_effect_replay_after_worker_restart", "status": "pass", "observed_outputs": {"callback_calls": 1}},
    "rust_version_marker_replay_after_code_upgrade": {"scenario_id": "rust_version_marker_replay_after_code_upgrade", "status": "pass", "observed_outputs": {"version": 2}}
  }
}
JSON

  local oversized="$output.oversized"
  {
    head -n -1 "$output"
    printf ',\n  "diagnostics": {"worker_protocol_trace": "'
    head -c 262144 /dev/zero | tr '\000' r
    printf '"}\n}\n'
  } > "$oversized"
  mv "$oversized" "$output"
}

if [[ "${1:-}" == "compose" ]]; then
  if [[ "${2:-}" == "version" ]]; then
    exit 0
  fi
  exit 0
fi

if [[ "${1:-}" == "image" && "${2:-}" == "inspect" ]]; then
  if [[ "${3:-}" == "--format" ]]; then
    echo "durableworkflow/server@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
  else
    echo '[{"RepoDigests":["durableworkflow/server@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"]}]'
  fi
  exit 0
fi

if [[ "${1:-}" == "run" ]]; then
  joined=" $* "
  if [[ "$joined" == *" composer require "* ]]; then
    previous=""
    for arg in "$@"; do
      if [[ "$previous" == "-v" && "$arg" == *":/tmp/composer-cache" ]]; then
        composer_cache="${arg%:/tmp/composer-cache}"
        mkdir -p \
          "$composer_cache/files/durable-workflow/workflow" \
          "$composer_cache/files/durable-workflow/waterline"
        printf '%s\n' 'fake workflow archive bytes' \
          > "$composer_cache/files/durable-workflow/workflow/workflow.zip"
        printf '%s\n' 'fake waterline archive bytes' \
          > "$composer_cache/files/durable-workflow/waterline/waterline.zip"
      fi
      previous="$arg"
    done
    exit 0
  fi
  if [[ "$joined" == *" cargo install durable-workflow "* ]]; then
    previous=""
    for arg in "$@"; do
      if [[ "$previous" == "-v" && "$arg" == *":/cargo" ]]; then
        rust_root="${arg%:/cargo}"
        mkdir -p "$rust_root/bin" "$rust_root/registry/cache/fake-registry"
        printf '#!/usr/bin/env sh\nexit 0\n' > "$rust_root/bin/durable-workflow-replay-conformance"
        printf '%s\n' 'fake Rust crate bytes' \
          > "$rust_root/registry/cache/fake-registry/durable-workflow-0.1.13.crate"
        chmod +x "$rust_root/bin/durable-workflow-replay-conformance"
      fi
      previous="$arg"
    done
    exit 0
  fi
  if [[ "$joined" == *" /cargo/bin/durable-workflow-replay-conformance "* ]]; then
    previous=""
    for arg in "$@"; do
      if [[ "$previous" == "-v" && "$arg" == *":/result" ]]; then
        result_dir="${arg%:/result}"
      fi
      previous="$arg"
    done
    write_rust_shard "$result_dir/rust-replay-shard.json"
    exit 0
  fi
  if [[ "$joined" == *" php /probe.php "* ]]; then
    printf '%s\n' '{"classes_checked":[],"missing_classes":[],"package":"durable-workflow/waterline","status":"pass","workflow_package_api_floor_missing":[]}'
    exit 0
  fi
  if [[ "$joined" == *"php-sdk-published-artifacts.sh "* ]]; then
    if [[ ! -s "$DW_REPLAY_RESULT_DIR/replay-namespace-setup.json" ]]; then
      echo "PHP replay cell started before namespace setup evidence was retained" >&2
      exit 70
    fi
    if [[ "$joined" != *" DW_PHP_SDK_CONFORMANCE_NAMESPACE=replay-cleanup-contract "* ]]; then
      echo "PHP replay cell did not receive the established namespace" >&2
      exit 71
    fi
    if [[ "$joined" != *" DW_PHP_SDK_CONFORMANCE_REPLAY_MATRIX=1 "* ]]; then
      echo "PHP replay cell did not enable executed replay matrix cells" >&2
      exit 72
    fi
    result_dir=""
    previous=""
    for arg in "$@"; do
      if [[ "$previous" == "-v" && "$arg" == *":/result" ]]; then
        result_dir="${arg%:/result}"
      fi
      previous="$arg"
    done
    if [[ -z "$result_dir" ]]; then
      exit 2
    fi
    mkdir -p "$result_dir/composer-cache/files/durable-workflow/sdk"
    printf '%s\n' 'fake PHP SDK archive bytes' \
      > "$result_dir/composer-cache/files/durable-workflow/sdk/sdk.zip"
    write_php_shard "$result_dir/php-sdk-conformance-result.json"
    printf '%s\n' '{"runtime":"sdk-php","status":"pass"}'
    exit 0
  fi
  exit 0
fi

exit 0
SH);

            $this->writeExecutable($binDir.'/rm', <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
printf 'fake rm cleanup failure: %s\n' "$*" >&2
exit 1
SH);

            $env = array_merge($_ENV, [
                'PATH' => $binDir.PATH_SEPARATOR.(string) getenv('PATH'),
                'DW_CONFORMANCE_TMPDIR' => $workspace.'/tmp',
                'DW_FAKE_DOCKER_LOG' => $dockerLog,
                'DW_FAKE_NAMESPACE_SETUP_FAILURE' => '0',
                'DW_REPLAY_RESULT_DIR' => $resultDir,
                'DW_REPLAY_RUN_ROOT' => $runRoot,
                'DW_REPLAY_NAMESPACE' => 'replay-cleanup-contract',
                'DW_REPLAY_SERVER_PORT' => (string) $httpServerPort,
                'DW_REPLAY_SKIP_DOCKER_PULL' => '1',
                'DW_SERVER_REPO_ROOT' => '',
                'SERVER_REPO_PATH' => '',
            ]);

            $namespaceFailureRunRoot = $workspace.'/tmp/namespace-failure-run-root';
            $namespaceFailureResultDir = $workspace.'/tmp/namespace-failure-results';
            $this->mkdirp($namespaceFailureRunRoot);
            $this->mkdirp($namespaceFailureResultDir);
            $namespaceFailureEnv = array_merge($env, [
                'DW_FAKE_NAMESPACE_SETUP_FAILURE' => '1',
                'DW_REPLAY_RESULT_DIR' => $namespaceFailureResultDir,
                'DW_REPLAY_RUN_ROOT' => $namespaceFailureRunRoot,
            ]);
            $namespaceFailureProcess = proc_open(
                [$stagedScript, '--result-dir', $namespaceFailureResultDir],
                [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $namespaceFailurePipes,
                $cwd,
                $namespaceFailureEnv,
            );
            $this->assertIsResource($namespaceFailureProcess);
            $namespaceFailureStdout = stream_get_contents($namespaceFailurePipes[1]);
            $namespaceFailureStderr = stream_get_contents($namespaceFailurePipes[2]);
            fclose($namespaceFailurePipes[1]);
            fclose($namespaceFailurePipes[2]);
            $namespaceFailureStatus = proc_close($namespaceFailureProcess);

            $this->assertSame(
                1,
                $namespaceFailureStatus,
                "namespace setup failure must stop before runtime shards\nstdout:\n$namespaceFailureStdout\nstderr:\n$namespaceFailureStderr",
            );
            $namespaceFailureResult = json_decode(
                (string) file_get_contents($namespaceFailureResultDir.'/replay-conformance-result.json'),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            $this->assertTrue($namespaceFailureResult['runner_blocked']);
            $this->assertSame('0.2.449', $namespaceFailureResult['artifact_versions']['server']);
            $namespaceFailureIdentities = array_keys($namespaceFailureResult['executed_distribution_identities']);
            sort($namespaceFailureIdentities);
            $this->assertSame(['cli', 'server'], $namespaceFailureIdentities);
            $this->assertSame('replay-cleanup-contract', $namespaceFailureResult['namespace_setup']['selected_namespace']);
            $this->assertSame(404, $namespaceFailureResult['namespace_setup']['requests'][1]['server_response']['status_code']);
            $this->assertSame('namespace_not_found', $namespaceFailureResult['namespace_setup']['requests'][1]['server_response']['reason']);
            $this->assertSame('runner_blocked', $namespaceFailureResult['scenario_results']['php_completed_history_activity_replay']['status']);
            $this->assertSame('runner_blocked', $namespaceFailureResult['scenario_results']['python_completed_history_activity_replay']['status']);
            $this->assertSame('runner_blocked', $namespaceFailureResult['scenario_results']['rust_side_effect_replay_after_worker_restart']['status']);
            $this->assertSame(
                $namespaceFailureResult['namespace_setup'],
                $namespaceFailureResult['findings'][0]['observed_behavior']['namespace_setup'],
            );
            $this->assertStringNotContainsString(
                'php-sdk-published-artifacts.sh',
                (string) file_get_contents($dockerLog),
            );

            $process = proc_open(
                [$stagedScript, '--result-dir', $resultDir],
                [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                $cwd,
                $env,
            );
            $this->assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($process);

            $this->assertSame(
                1,
                $status,
                "the fake cleanup failure should make an otherwise passing replay run non-passing\nstdout:\n$stdout\nstderr:\n$stderr",
            );
            $this->assertFileExists(
                $resultDir.'/replay-conformance-result.json',
                "the replay runner must retain its portable result\nstdout:\n$stdout\nstderr:\n$stderr",
            );

            $result = json_decode((string) file_get_contents($resultDir.'/replay-conformance-result.json'), true, flags: JSON_THROW_ON_ERROR);
            $record = json_decode((string) file_get_contents($resultDir.'/replay-conformance-record.json'), true, flags: JSON_THROW_ON_ERROR);
            $namespaceSetup = json_decode((string) file_get_contents($resultDir.'/replay-namespace-setup.json'), true, flags: JSON_THROW_ON_ERROR);

            $expectedIdentities = ['cli', 'sdk-php', 'sdk-python', 'sdk-rust', 'server', 'waterline', 'workflow'];
            $executedIdentities = array_keys($result['executed_distribution_identities'] ?? []);
            sort($executedIdentities);
            $this->assertSame($expectedIdentities, $executedIdentities);
            $this->assertSame([
                'cli' => '0.1.81',
                'sdk-php' => '0.1.1',
                'sdk-python' => '0.4.89',
                'sdk-rust' => '0.1.13',
                'server' => '0.2.449',
                'waterline' => '2.0.0-alpha.111',
                'workflow' => '2.0.0-alpha.210',
            ], $result['artifact_versions']);
            foreach ($result['executed_distribution_identities'] as $identity) {
                $this->assertNotEmpty($identity['artifacts']);
                $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $identity['artifacts'][0]['sha256']);
            }
            $this->assertFalse($result['source_policy']['local_product_source_checkouts_used']);
            $this->assertLessThanOrEqual(
                4 * 1024 * 1024,
                filesize($resultDir.'/replay-conformance-result.json'),
                'hosted replay evidence must remain within the portable consumer limit',
            );
            $this->assertSame(4 * 1024 * 1024, $result['portable_evidence_contract']['max_result_bytes']);
            $this->assertSame(
                ['sdk-php', 'sdk-python', 'sdk-rust'],
                $result['runtime_matrix']['runtimes'],
            );
            $this->assertCount(31, $result['scenario_results']);
            $installOutputs = $result['scenario_results']['published_artifact_install_only']['observed_outputs'];
            $this->assertLessThanOrEqual(
                64 * 1024,
                strlen(json_encode($installOutputs, JSON_THROW_ON_ERROR)),
                'published install evidence must fit in its portable scenario cell',
            );
            $installArtifacts = $installOutputs['artifact_install_evidence']['artifacts'];
            $this->assertSame(
                [
                    'server' => 'pass',
                    'cli' => 'pass',
                    'sdk-python' => 'pass',
                    'workflow-php' => 'pass',
                    'sdk-php' => 'pass',
                    'sdk-rust' => 'pass',
                    'waterline' => 'pass',
                ],
                array_column($installArtifacts, 'status', 'artifact'),
            );
            $sdkPhpInstall = array_values(array_filter(
                $installArtifacts,
                static fn (array $artifact): bool => $artifact['artifact'] === 'sdk-php',
            ))[0];
            $this->assertSame(
                true,
                $sdkPhpInstall['probe']['result']['assertions']['durable_replay_history'],
            );
            $this->assertGreaterThan(
                4 * 1024 * 1024,
                $sdkPhpInstall['probe']['result']['source_document']['bytes'],
            );
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{64}$/',
                $sdkPhpInstall['probe']['result']['source_document']['sha256'],
            );
            $sdkRustInstall = array_values(array_filter(
                $installArtifacts,
                static fn (array $artifact): bool => $artifact['artifact'] === 'sdk-rust',
            ))[0];
            $this->assertGreaterThan(
                256 * 1024,
                $sdkRustInstall['probe']['replay_shard']['source_document']['bytes'],
            );
            $this->assertSame(
                'pass',
                $sdkRustInstall['probe']['replay_shard']['scenario_results']['rust_side_effect_replay_after_worker_restart']['status'],
            );
            $this->assertArrayNotHasKey('diagnostics', $sdkRustInstall['probe']['replay_shard']);
            $this->assertStringNotContainsString(
                str_repeat('x', 4096),
                (string) file_get_contents($resultDir.'/replay-conformance-result.json'),
            );
            $this->assertSame('fail', $result['outcome']);
            $this->assertSame('fail', $record['outcome']);
            $this->assertTrue($result['runner_blocked']);
            $this->assertTrue($record['runnerBlocked']);
            $this->assertSame('fail', $result['cleanup']['status']);
            $this->assertSame('pass', $result['findings'][0]['observed_behavior']['previous_outcome']);
            $this->assertSame('replay_runner_cleanup_failure', $result['findings'][0]['type']);
            $this->assertStringContainsString('replay run-root cleanup failed', $record['reason']);
            $namespaceRequests = array_map(
                static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                file($namespaceRequestLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
            );
            $this->assertCount(2, $namespaceRequests);
            $this->assertSame(['default', 'replay-cleanup-contract'], array_column($namespaceRequests, 'namespace'));
            foreach ($namespaceRequests as $request) {
                $this->assertSame('/api/namespaces', $request['path']);
                $this->assertSame('2', $request['control_plane_version']);
                $this->assertSame('default', $request['request_namespace']);
                $this->assertTrue($request['authorization_configured']);
            }
            $this->assertSame('pass', $namespaceSetup['outcome']);
            $this->assertSame('replay-cleanup-contract', $namespaceSetup['selected_namespace']);
            $this->assertSame(['default', 'replay-cleanup-contract'], $namespaceSetup['selected_namespaces']);
            $this->assertSame($namespaceSetup, $result['namespace_setup']);
            $this->assertStringContainsString(
                'DW_PHP_SDK_CONFORMANCE_NAMESPACE=replay-cleanup-contract',
                (string) file_get_contents($dockerLog),
            );
            $this->assertSame('pass', json_decode((string) file_get_contents($resultDir.'/replay-conformance-merge.log'), true, flags: JSON_THROW_ON_ERROR)['outcome']);
            $this->assertFileExists($resultDir.'/run-root-cleanup.log');
            $this->assertStringContainsString('fake rm cleanup failure', (string) file_get_contents($resultDir.'/run-root-cleanup.log'));
        } finally {
            if (is_resource($httpServerProcess)) {
                proc_terminate($httpServerProcess);
                proc_close($httpServerProcess);
            }
            $this->removeTree($workspace);
        }
    }

    private function read(string $path): string
    {
        $fullPath = dirname(__DIR__, 2).'/'.$path;
        $this->assertFileExists($fullPath);

        return (string) file_get_contents($fullPath);
    }

    private function makeTempDir(string $prefix): string
    {
        $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .$prefix
            .'-'
            .bin2hex(random_bytes(6));

        $this->mkdirp($path);

        return $path;
    }

    private function mkdirp(string $path): void
    {
        if (! is_dir($path)) {
            $this->assertTrue(mkdir($path, 0777, true));
        }
    }

    private function writeExecutable(string $path, string $contents): void
    {
        file_put_contents($path, $contents);
        chmod($path, 0755);
    }

    private function removeTree(string $path): void
    {
        if (! file_exists($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}
