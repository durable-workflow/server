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

    public function test_runner_composes_python_and_php_runtime_shards(): void
    {
        $source = $this->read('scripts/conformance/replay-published-artifacts.sh');

        $this->assertStringContainsString('durable-workflow-replay-conformance --json', $source);
        $this->assertStringContainsString('composer require "durable-workflow/workflow:${workflow_php_version}"', $source);
        $this->assertStringContainsString('php artisan workflow:v2:replay-conformance --json', $source);
        $this->assertStringContainsString('python-replay-shard.json', $source);
        $this->assertStringContainsString('php-replay-shard.json', $source);
        $this->assertStringContainsString('workflow-php-runtime-shard', $source);
        $this->assertStringContainsString('sdk-python-runtime-shard', $source);
        $this->assertStringContainsString('command -v durable-workflow-replay-conformance', $source);
        $this->assertStringContainsString('php_artisan_command_available', $source);
        $this->assertStringContainsString('NF > 0 && $1 == command', $source);
        $this->assertStringContainsString(
            "php_artisan_command_available 'workflow:v2:replay-conformance'",
            $source,
        );
        $this->assertStringContainsString('artisan_command_available(raw_list: str, command: str)', $source);
        $this->assertStringNotContainsString("grep -Fxq 'workflow:v2:replay-conformance'", $source);
        $this->assertStringContainsString('unsupported_public_surface', $source);
        $this->assertStringContainsString('Published durable-workflow/workflow:${workflow_php_version} does not expose workflow:v2:replay-conformance.', $source);
    }

    public function test_runner_installs_and_probes_waterline_before_install_only_can_pass(): void
    {
        $source = $this->read('scripts/conformance/replay-published-artifacts.sh');

        $this->assertStringContainsString('"durable-workflow/waterline:${waterline_version}"', $source);
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
        ] as $scenario) {
            $this->assertStringContainsString('"' . $scenario . '"', $source);
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
        $stagedScript = $workspace . '/.tmp/scripts/conformance/replay-published-artifacts.sh';
        $serverCheckout = $workspace . '/repos/server';
        $binDir = $workspace . '/bin';
        $runRoot = $workspace . '/.tmp/run-root';
        $resultDir = $workspace . '/.tmp/results';
        $cwd = $workspace . '/.tmp/cwd';
        $dockerLog = $workspace . '/docker-argv.log';

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

            copy($repoRoot . '/scripts/conformance/replay-published-artifacts.sh', $stagedScript);
            chmod($stagedScript, 0755);
            copy($repoRoot . '/docker-compose.published.yml', $serverCheckout . '/docker-compose.published.yml');

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
    "sdk-python": "pypi_package",
    "server": "published_docker_image",
    "waterline": "packagist_package",
    "workflow-php": "packagist_package"
  },
  "artifact_versions": {
    "cli": "0.1.80",
    "sdk-python": "0.4.88",
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
                $binDir . '/python3',
                str_replace('__REAL_PYTHON__', escapeshellarg($realPython), $pythonShim),
            );

            $this->writeExecutable($binDir . '/curl', <<<'SH'
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

            $this->writeExecutable($binDir . '/docker', <<<'SH'
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
                'PATH' => $binDir . PATH_SEPARATOR . (string) getenv('PATH'),
                'DW_CONFORMANCE_TMPDIR' => $workspace . '/.tmp',
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
            $expectedComposeFile = $serverCheckout . '/docker-compose.published.yml';
            $this->assertStringContainsString('compose -p ', $log);
            $this->assertStringContainsString('-f ' . $expectedComposeFile, $log);
            $this->assertStringNotContainsString($workspace . '/.tmp/docker-compose.published.yml', $log);
            $this->assertStringNotContainsString($cwd . '/docker-compose.published.yml', $log);
            $this->assertStringNotContainsString($resultDir . '/docker-compose.published.yml', $log);
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

            $this->assertFileExists($resultDir . '/compose-startup-diagnostics.json');
            $diagnostics = (string) file_get_contents($resultDir . '/compose-startup-diagnostics.json');
            $this->assertStringNotContainsString('compose_config', $diagnostics);
            $this->assertStringNotContainsString('config.yml', $diagnostics);
        } finally {
            $this->removeTree($workspace);
        }
    }

    private function read(string $path): string
    {
        $fullPath = dirname(__DIR__, 2) . '/' . $path;
        $this->assertFileExists($fullPath);

        return (string) file_get_contents($fullPath);
    }

    private function makeTempDir(string $prefix): string
    {
        $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . $prefix
            . '-'
            . bin2hex(random_bytes(6));

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
