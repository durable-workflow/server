<?php

namespace Tests\Unit;

use App\Support\SingleRegionFailoverContract;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Workflow\V2\Support\PlatformConformanceSuite;

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
        $this->assertSame(PlatformConformanceSuite::SCHEMA, $manifest['scenario_manifest']['suite_schema']);
        $this->assertSame($manifest['scenario_manifest']['suite_schema'], $scenarioDocument['suite_schema']);
        $this->assertSame(SingleRegionFailoverContract::RESULT_SCHEMA, $scenarioDocument['result_contract']['schema']);
        $this->assertSame(
            $manifest['required_topology']['queue_workers'],
            $scenarioDocument['result_contract']['required_topology']['queue_workers'],
        );
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

    #[DataProvider('nonterminalRunStatusProvider')]
    public function test_manifest_publishes_each_allowed_nonterminal_run_status(string $rawStatus): void
    {
        $status = SingleRegionFailoverContract::manifest()['run_status_contract'][$rawStatus] ?? null;

        $this->assertSame([
            'status_bucket' => 'running',
            'is_terminal' => false,
        ], $status);
    }

    /** @return iterable<string, array{string}> */
    public static function nonterminalRunStatusProvider(): iterable
    {
        yield 'pending' => ['pending'];
        yield 'running' => ['running'];
        yield 'waiting' => ['waiting'];
    }

    public function test_database_interruption_contract_requires_bounded_run_state_evidence(): void
    {
        $manifest = SingleRegionFailoverContract::manifest();
        $scenarioDocument = json_decode(
            (string) file_get_contents(base_path('static/platform-conformance/single-region-failover-scenarios.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $scenario = current(array_filter(
            $scenarioDocument['scenarios'],
            static fn (array $candidate): bool => $candidate['id'] === 'database_interruption',
        ));

        $this->assertSame([
            'acknowledged_task',
            'lease_timing',
            'readiness_down',
            'database_down_write',
            'readiness_recovered',
            'post_recovery_description',
            'stale_owner_fence',
            'replacement_reclaim',
            'completion',
            'duplicate_completion',
            'final_description',
        ], $manifest['host_runner_contract']['database_interruption_evidence']);
        $this->assertIsArray($scenario);
        $this->assertContains('post_recovery_description', $scenario['required_evidence']);
        $this->assertContains('lease_timing', $scenario['required_evidence']);
        $this->assertContains('completion_before_lease_expiry', $scenario['required_evidence']);
        $this->assertContains('stale_owner_fence', $scenario['required_evidence']);
        $this->assertContains('replacement_reclaim', $scenario['required_evidence']);
        $this->assertContains('final_description', $scenario['required_evidence']);
        $this->assertContains('duplicate_completion_refused', $scenario['required_evidence']);
    }

    public function test_api_node_loss_contract_requires_live_lease_timing_evidence(): void
    {
        $manifest = SingleRegionFailoverContract::manifest();
        $scenarioDocument = json_decode(
            (string) file_get_contents(base_path('static/platform-conformance/single-region-failover-scenarios.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $scenario = current(array_filter(
            $scenarioDocument['scenarios'],
            static fn (array $candidate): bool => $candidate['id'] === 'api_node_loss',
        ));

        $this->assertSame([
            'acknowledged_task',
            'lease_timing',
            'topology_after_loss',
            'surviving_node_readiness',
            'survivor_traffic',
            'survivor_completion',
            'final_description',
            'compose_ps',
            'load_balancer_logs',
            'surviving_node_logs',
        ], $manifest['host_runner_contract']['api_node_loss_evidence']);
        $this->assertIsArray($scenario);
        $this->assertContains('node_loss_mode', $scenario['required_evidence']);
        $this->assertContains('lease_expires_at', $scenario['required_evidence']);
        $this->assertContains('lease_timing', $scenario['required_evidence']);
        $this->assertContains('completion_before_lease_expiry', $scenario['required_evidence']);
    }

    public function test_compose_rehearsal_has_no_product_build_or_source_mount(): void
    {
        $compose = (string) file_get_contents(base_path('docker-compose.failover-rehearsal.yml'));

        $this->assertStringNotContainsString('build:', $compose);
        $this->assertStringNotContainsString('context:', $compose);
        $this->assertStringNotContainsString('../workflow', $compose);
        $this->assertSame(1, substr_count($compose, "\n  scheduler:\n"));
        $this->assertSame(1, substr_count($compose, "\n  queue-worker:\n"));
        $this->assertSame(1, substr_count($compose, "\n  mysql:\n"));
        $this->assertSame(1, substr_count($compose, "\n  redis:\n"));
        $this->assertStringContainsString('DW_FAILOVER_SERVER_IMAGE:?', $compose);
        $this->assertStringContainsString('command: ["php", "artisan", "queue:work", "redis"', $compose);
        $this->assertStringContainsString('DW_SERVER_ID: failover-queue-worker', $compose);
        $this->assertStringContainsString('DW_SERVER_PROCESS_CLASS: worker_node', $compose);
        $this->assertStringContainsString('condition: service_completed_successfully', $compose);
        $this->assertStringContainsString('condition: service_healthy', $compose);
    }

    public function test_rehearsal_runner_requires_the_queue_worker_to_remain_in_the_published_topology(): void
    {
        $runner = (string) file_get_contents(base_path('scripts/conformance/single-region-failover-published-artifacts.py'));

        $this->assertStringContainsString('"queue_workers": ["queue-worker"]', $runner);
        $this->assertStringContainsString('running.count("queue-worker") == 1', $runner);
        $this->assertStringContainsString('"server-a", "server-b", "queue-worker", "scheduler"', $runner);
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

    public function test_shell_runner_version_matches_the_published_contract_version(): void
    {
        $runner = (string) file_get_contents(base_path('scripts/conformance/single-region-failover-published-artifacts.sh'));

        $this->assertMatchesRegularExpression(
            '/^export DW_FAILOVER_RUNNER_VERSION="'.SingleRegionFailoverContract::VERSION.'"$/m',
            $runner,
        );
    }

    public function test_released_runner_requires_and_preserves_the_canonical_suite_schema(): void
    {
        $runner = (string) file_get_contents(base_path('scripts/conformance/single-region-failover-published-artifacts.py'));

        $this->assertStringContainsString(
            'PLATFORM_CONFORMANCE_SUITE_SCHEMA = "'.PlatformConformanceSuite::SCHEMA.'"',
            $runner,
        );
        $this->assertStringContainsString('RESULT["artifacts"]["suite_schema"] = suite_schema', $runner);
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

    public function test_python_rehearsal_contract_suite_covers_result_and_nonterminal_run_state_gates(): void
    {
        if (trim((string) shell_exec('command -v python3 2>/dev/null')) === '') {
            $this->markTestSkipped('python3 is required to exercise the failover result gate.');
        }

        $output = [];
        $status = 0;
        exec(
            'python3 '.escapeshellarg(base_path('tests/Unit/Support/single_region_failover_result_gate_test.py'))
                .' -v 2>&1',
            $output,
            $status,
        );

        $this->assertSame(0, $status, implode("\n", $output));
        $transcript = implode("\n", $output);
        $this->assertStringContainsString(
            'test_database_interruption_completes_within_live_lease_for_every_public_running_status',
            $transcript,
        );
        $this->assertStringContainsString(
            'test_database_interruption_fails_closed_for_invalid_post_recovery_descriptions',
            $transcript,
        );
        $this->assertStringContainsString(
            'test_database_interruption_reclaims_after_outage_crosses_lease_expiry',
            $transcript,
        );
        $this->assertStringContainsString(
            'test_worker_lease_loss_accepts_every_public_running_raw_status',
            $transcript,
        );
        $this->assertStringContainsString(
            'test_worker_lease_loss_fails_closed_for_invalid_pre_recovery_descriptions',
            $transcript,
        );
    }

    public function test_worker_lease_loss_contract_requires_canonical_run_state_and_exactly_once_evidence(): void
    {
        $manifest = SingleRegionFailoverContract::manifest();
        $scenarioDocument = json_decode(
            (string) file_get_contents(base_path('static/platform-conformance/single-region-failover-scenarios.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $scenario = current(array_filter(
            $scenarioDocument['scenarios'],
            static fn (array $candidate): bool => $candidate['id'] === 'worker_lease_loss',
        ));

        $this->assertSame([
            'acknowledged_task',
            'pre_recovery_description',
            'recovered_lease',
            'completion',
            'duplicate_completion',
            'final_description',
        ], $manifest['host_runner_contract']['worker_lease_loss_evidence']);
        $this->assertIsArray($scenario);
        $this->assertContains('pre_recovery_description', $scenario['required_evidence']);
        $this->assertContains('duplicate_completion_refused', $scenario['required_evidence']);
        $this->assertContains('final_description', $scenario['required_evidence']);
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

    public function test_runner_exposes_the_docker_socket_connect_host_override(): void
    {
        $runner = (string) file_get_contents(base_path('scripts/conformance/single-region-failover-published-artifacts.sh'));

        $this->assertStringContainsString('DW_FAILOVER_CONNECT_HOST', $runner);
        $this->assertStringContainsString('Defaults to 127.0.0.1', $runner);
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
