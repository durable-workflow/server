<?php

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use App\Models\WorkflowNamespace;
use App\Support\ServerPollingCache;
use App\Support\WorkerPollDiagnostics;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class WorkerPollDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
    }

    public function test_recent_cutoffs_are_isolated_and_overwrite_only_their_queue_observation(): void
    {
        $diagnostics = app(WorkerPollDiagnostics::class);
        $diagnostics->recordDisconnect('tenant-a', 'queue', 'workflow-tasks', 1.2345, 30);
        $diagnostics->recordDisconnect('tenant-b', 'queue', 'query-tasks', 3, 45);
        $diagnostics->recordDisconnect('tenant-a', 'queue', 'activity-tasks', 2.3456, 60);

        $current = $diagnostics->snapshot('tenant-a', 'queue');
        $this->assertSame('warning', $current['status']);
        $this->assertSame('activity-tasks', $current['last_disconnect']['poll_kind']);
        $this->assertSame(2.346, $current['last_disconnect']['observed_seconds']);
        $this->assertSame(60, $current['last_disconnect']['configured_wait_seconds']);
        $this->assertSame('query-tasks', $diagnostics->snapshot('tenant-b', 'queue')['last_disconnect']['poll_kind']);
        $this->assertSame('no_recent_observation', $diagnostics->snapshot('tenant-a', 'other')['status']);
    }

    public function test_observations_expire_and_full_waits_are_not_mislabeled_as_early_cutoffs(): void
    {
        $diagnostics = app(WorkerPollDiagnostics::class);
        $diagnostics->recordDisconnect('default', 'queue', 'workflow-tasks', 30, 30);
        $this->assertSame('no_recent_observation', $diagnostics->snapshot('default', 'queue')['status']);
        $diagnostics->recordDisconnect('default', 'queue', 'workflow-tasks', 2, 30);
        $this->travel(WorkerPollDiagnostics::RETENTION_SECONDS + 1)->seconds();
        $this->assertSame('no_recent_observation', $diagnostics->snapshot('default', 'queue')['status']);
        $this->travelBack();
    }

    public function test_store_failure_reports_unavailable_and_does_not_escape_disconnect_cleanup(): void
    {
        $store = Mockery::mock(Repository::class);
        $store->shouldReceive('get')->with('server:polling-cache:availability-probe')->andReturn(null);
        $store->shouldReceive('get')->with(Mockery::pattern('/^server:worker-poll-diagnostic:/'))->andThrow(new RuntimeException('fixture outage'));
        $store->shouldReceive('put')->andThrow(new RuntimeException('fixture outage'));
        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('store')->andReturn($store);
        $diagnostics = new WorkerPollDiagnostics(new ServerPollingCache($factory, new Filesystem));

        $diagnostics->recordDisconnect('default', 'queue', 'workflow-tasks', 2, 30);
        $result = $diagnostics->snapshot('default', 'queue');
        $this->assertSame('unavailable', $result['status']);
        $this->assertNull($result['last_disconnect']);
    }

    public function test_queue_detail_and_summary_expose_the_actual_cutoff_warning(): void
    {
        WorkflowNamespace::query()->updateOrCreate(['name' => 'default'], [
            'description' => 'Default namespace', 'retention_days' => 30, 'status' => 'active',
        ]);
        WorkerRegistration::query()->create([
            'namespace' => 'default', 'worker_id' => 'fixture-cutoff-worker', 'task_queue' => 'fixture-cutoff-queue',
            'runtime' => 'php', 'last_heartbeat_at' => now(), 'status' => 'active',
        ]);
        app(WorkerPollDiagnostics::class)->recordDisconnect('default', 'fixture-cutoff-queue', 'workflow-tasks', 2.125, 30);
        $headers = ['X-Namespace' => 'default', 'X-Durable-Workflow-Control-Plane-Version' => '2'];
        $this->withHeaders($headers)->getJson('/api/task-queues/fixture-cutoff-queue')
            ->assertOk()->assertJsonPath('repair.needs_attention', true)
            ->assertJsonPath('poll_transport.status', 'warning')
            ->assertJsonPath('repair.transport_warnings.0.reason', 'worker_poll_client_disconnected')
            ->assertJsonPath('repair.transport_warnings.0.observed_seconds', 2.125)
            ->assertJsonPath('repair.transport_warnings.0.configured_wait_seconds', 30);
        $this->withHeaders($headers)->getJson('/api/task-queues')
            ->assertOk()->assertJsonPath('task_queues.0.repair.needs_attention', true)
            ->assertJsonPath('task_queues.0.poll_transport.last_disconnect.configured_wait_seconds', 30);
    }
}
