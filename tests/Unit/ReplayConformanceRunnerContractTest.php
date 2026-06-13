<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

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
        $this->assertStringContainsString('docker compose -p "$compose_project" -f "$repo_root/docker-compose.published.yml" up -d server', $source);
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

    private function read(string $path): string
    {
        $fullPath = dirname(__DIR__, 2) . '/' . $path;
        $this->assertFileExists($fullPath);

        return (string) file_get_contents($fullPath);
    }
}
