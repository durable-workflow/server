<?php

namespace Tests\Unit;

use App\Support\SingleRegionFailoverContract;
use Tests\TestCase;

class SingleRegionFailoverRehearsalTest extends TestCase
{
    public function test_manifest_and_runner_publish_the_same_required_scenarios(): void
    {
        $manifest = SingleRegionFailoverContract::manifest();
        $scenarioDocument = json_decode(
            (string) file_get_contents(base_path('static/platform-conformance/single-region-failover-scenarios.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame(
            $manifest['required_scenarios'],
            array_column($scenarioDocument['scenarios'], 'id'),
        );
        $this->assertSame(SingleRegionFailoverContract::RESULT_SCHEMA, $scenarioDocument['result_contract']['schema']);
        $this->assertSame([
            'pending' => ['status_bucket' => 'running', 'is_terminal' => false],
            'running' => ['status_bucket' => 'running', 'is_terminal' => false],
            'waiting' => ['status_bucket' => 'running', 'is_terminal' => false],
            'cancelled' => ['status_bucket' => 'failed', 'is_terminal' => true],
            'terminated' => ['status_bucket' => 'failed', 'is_terminal' => true],
            'completed' => ['status_bucket' => 'completed', 'is_terminal' => true],
            'failed' => ['status_bucket' => 'failed', 'is_terminal' => true],
        ], $manifest['run_status_contract']);
    }

    public function test_compose_rehearsal_has_no_product_build_or_source_mount(): void
    {
        $compose = (string) file_get_contents(base_path('docker-compose.failover-rehearsal.yml'));

        $this->assertStringNotContainsString('build:', $compose);
        $this->assertStringNotContainsString('context:', $compose);
        $this->assertStringNotContainsString('../workflow', $compose);
        $this->assertSame(1, substr_count($compose, "\n  scheduler:\n"));
        $this->assertSame(1, substr_count($compose, "\n  mysql:\n"));
        $this->assertSame(1, substr_count($compose, "\n  redis:\n"));
        $this->assertStringContainsString('DW_FAILOVER_SERVER_IMAGE:?', $compose);
    }

    public function test_shell_handoff_requires_and_resolves_a_public_server_image(): void
    {
        $runner = (string) file_get_contents(base_path('scripts/conformance/single-region-failover-published-artifacts.sh'));

        $this->assertStringContainsString('DW_SERVER_IMAGE is required', $runner);
        $this->assertStringContainsString('durableworkflow/server', $runner);
        $this->assertStringContainsString('RepoDigests', $runner);
        $this->assertStringContainsString('DW_FAILOVER_SERVER_IMAGE=', $runner);
        $this->assertStringContainsString('DW_FAILOVER_CONNECT_HOST', $runner);
        $this->assertStringNotContainsString('docker build', $runner);
        $this->assertStringNotContainsString('docker compose build', $runner);
    }

    public function test_workflow_dispatch_image_override_is_optional_and_has_no_frozen_default(): void
    {
        $workflow = (string) file_get_contents(base_path('.github/workflows/single-region-failover.yml'));

        $this->assertMatchesRegularExpression(
            '/server_image:\s+description: Exact public durableworkflow\/server tag or digest\s+required: false/',
            $workflow,
        );
        $this->assertStringNotContainsString('default: durableworkflow/server:', $workflow);
        $this->assertStringContainsString('scripts/ci/select-single-region-failover-server-image.sh', $workflow);
    }

    public function test_workflow_selects_latest_exact_release_when_dispatch_override_is_omitted(): void
    {
        $tags = [];
        $status = 0;
        exec(
            'git -C '.escapeshellarg(base_path()).' tag --list --sort=-version:refname',
            $tags,
            $status,
        );

        $this->assertSame(0, $status);
        $latestRelease = current(array_filter(
            $tags,
            static fn (string $tag): bool => preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $tag) === 1,
        ));
        $this->assertIsString($latestRelease);

        $result = $this->runWorkflowImageSelector('');

        $this->assertSame(0, $result['status'], implode("\n", $result['output']));
        $this->assertSame(['durableworkflow/server:'.$latestRelease], $result['output']);
    }

    public function test_workflow_preserves_explicit_dispatch_image_selection(): void
    {
        $requested = 'durableworkflow/server@sha256:'.str_repeat('a', 64);

        $result = $this->runWorkflowImageSelector($requested);

        $this->assertSame(0, $result['status'], implode("\n", $result['output']));
        $this->assertSame([$requested], $result['output']);
    }

    public function test_rehearsal_result_fails_closed_for_false_or_unset_recovery_bounds(): void
    {
        if (trim((string) shell_exec('command -v python3 2>/dev/null')) === '') {
            $this->markTestSkipped('python3 is required to exercise the failover result gate.');
        }

        $output = [];
        $status = 0;
        exec(
            'python3 '.escapeshellarg(base_path('tests/Unit/Support/single_region_failover_result_gate_test.py')).' 2>&1',
            $output,
            $status,
        );

        $this->assertSame(0, $status, implode("\n", $output));
    }

    public function test_redis_cell_uses_request_id_polling_and_rejects_duplicate_leases(): void
    {
        $runner = (string) file_get_contents(base_path('scripts/conformance/single-region-failover-published-artifacts.py'));
        $manifest = (string) file_get_contents(base_path('static/platform-conformance/single-region-failover-scenarios.json'));

        $this->assertStringContainsString('degraded_poll_request_id', $runner);
        $this->assertStringContainsString('duplicate_lease_observed', $runner);
        $this->assertStringContainsString('"poll_request_id"', $manifest);
        $this->assertStringContainsString('"duplicate_lease_observed"', $manifest);
    }

    public function test_runner_documents_the_docker_socket_connect_host_override(): void
    {
        $runner = (string) file_get_contents(base_path('scripts/conformance/single-region-failover-published-artifacts.sh'));
        $documentation = (string) file_get_contents(base_path('docs/ha-failover-validation.md'));

        $this->assertStringContainsString('DW_FAILOVER_CONNECT_HOST', $runner);
        $this->assertStringContainsString('Defaults to 127.0.0.1', $runner);
        $this->assertStringContainsString('DW_FAILOVER_CONNECT_HOST=host.docker.internal', $documentation);
        $this->assertStringContainsString('without a URL', $documentation);
    }

    public function test_redis_recovery_requires_healthy_cache_readiness_before_discovery(): void
    {
        $runner = (string) file_get_contents(base_path('scripts/conformance/single-region-failover-published-artifacts.py'));

        $this->assertStringContainsString('def cache_ready(', $runner);
        $this->assertStringContainsString('get("cache", {}).get("status") != "ok"', $runner);
        $this->assertStringContainsString('lambda base=base: cache_ready(base)', $runner);
        $this->assertStringContainsString('"readiness_recovered": recovered_readiness', $runner);
        $cacheReadinessPosition = strpos($runner, 'lambda base=base: cache_ready(base)');
        $recoveredDiscoveryPosition = strpos($runner, 'timed_discovery(recovered_worker, "redis-recovered")');
        $this->assertNotFalse($cacheReadinessPosition);
        $this->assertNotFalse($recoveredDiscoveryPosition);
        $this->assertLessThan(
            $recoveredDiscoveryPosition,
            $cacheReadinessPosition,
        );
    }

    /** @return array{output: list<string>, status: int} */
    private function runWorkflowImageSelector(string $requestedImage): array
    {
        $output = [];
        $status = 0;
        $command = sprintf(
            'cd %s && REQUESTED_IMAGE=%s %s 2>&1',
            escapeshellarg(base_path()),
            escapeshellarg($requestedImage),
            escapeshellarg(base_path('scripts/ci/select-single-region-failover-server-image.sh')),
        );
        exec($command, $output, $status);

        return ['output' => $output, 'status' => $status];
    }
}
