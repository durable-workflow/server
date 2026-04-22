<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use App\Support\NamespaceWorkflowScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowMemo;
use Workflow\V2\Models\WorkflowMessage;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowRunTimerEntry;
use Workflow\V2\Models\WorkflowSearchAttribute;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\WorkflowStub;

class HistoryRetentionTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    // ── Retention Status Endpoint ──────────────────────────────────

    public function test_retention_status_returns_empty_when_no_expired_runs(): void
    {
        $this->createNamespace('default');

        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/system/retention')
            ->assertOk()
            ->assertJsonPath('namespace', 'default')
            ->assertJsonPath('retention_days', 30)
            ->assertJsonPath('expired_run_count', 0)
            ->assertJsonPath('expired_run_ids', [])
            ->assertJsonPath('scan_pressure', false);
    }

    public function test_retention_status_detects_expired_runs(): void
    {
        Queue::fake();

        $this->createNamespace('default');
        $runId = $this->createExpiredClosedRun('default', 'wf-retention-detect');

        $response = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/system/retention');

        $response->assertOk()
            ->assertJsonPath('expired_run_count', 1)
            ->assertJsonPath('scan_pressure', false);

        $this->assertContains($runId, $response->json('expired_run_ids'));
    }

    public function test_retention_status_respects_limit_query(): void
    {
        Queue::fake();

        $this->createNamespace('default');
        $this->createExpiredClosedRun('default', 'wf-retention-limit-a');
        $this->createExpiredClosedRun('default', 'wf-retention-limit-b');

        $response = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/system/retention?limit=1');

        $response->assertOk()
            ->assertJsonPath('expired_run_count', 1)
            ->assertJsonPath('scan_limit', 1)
            ->assertJsonPath('scan_pressure', true);

        $this->assertCount(1, $response->json('expired_run_ids'));
    }

    public function test_retention_status_respects_namespace_retention_days(): void
    {
        Queue::fake();

        $this->createNamespaceWithRetention('short-retention', 7);
        $runId = $this->createExpiredClosedRun('short-retention', 'wf-short-ret', daysAgo: 10);

        $response = $this->withHeaders($this->apiHeaders('short-retention'))
            ->getJson('/api/system/retention');

        $response->assertOk()
            ->assertJsonPath('namespace', 'short-retention')
            ->assertJsonPath('retention_days', 7)
            ->assertJsonPath('expired_run_count', 1);
    }

    public function test_retention_status_does_not_include_running_workflows(): void
    {
        Queue::fake();

        $this->createNamespace('default');

        $workflow = WorkflowStub::make(ExternalGreetingWorkflow::class, 'wf-still-running');
        $workflow->start('Ada');
        NamespaceWorkflowScope::bind('default', $workflow->id(), ExternalGreetingWorkflow::class);

        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/system/retention')
            ->assertOk()
            ->assertJsonPath('expired_run_count', 0);
    }

    public function test_retention_status_namespace_scoping(): void
    {
        Queue::fake();

        $this->createNamespace('ns-a');
        $this->createNamespace('ns-b');
        $this->createExpiredClosedRun('ns-a', 'wf-a-retention');

        $this->withHeaders($this->apiHeaders('ns-b'))
            ->getJson('/api/system/retention')
            ->assertOk()
            ->assertJsonPath('expired_run_count', 0);

        $this->withHeaders($this->apiHeaders('ns-a'))
            ->getJson('/api/system/retention')
            ->assertOk()
            ->assertJsonPath('expired_run_count', 1);
    }

    // ── Retention Enforce Pass Endpoint ─────────────────────────────

    public function test_retention_pass_with_no_expired_runs(): void
    {
        $this->createNamespace('default');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/system/retention/pass')
            ->assertOk()
            ->assertJsonPath('processed', 0)
            ->assertJsonPath('pruned', 0)
            ->assertJsonPath('skipped', 0)
            ->assertJsonPath('failed', 0)
            ->assertJsonPath('results', []);
    }

    public function test_retention_pass_prunes_expired_run(): void
    {
        Queue::fake();

        $this->createNamespace('default');
        $runId = $this->createExpiredClosedRun('default', 'wf-prune-test');

        $this->assertGreaterThan(0, WorkflowHistoryEvent::where('workflow_run_id', $runId)->count());

        $response = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/system/retention/pass');

        $response->assertOk()
            ->assertJsonPath('processed', 1)
            ->assertJsonPath('pruned', 1)
            ->assertJsonPath('skipped', 0)
            ->assertJsonPath('failed', 0);

        $results = $response->json('results');
        $this->assertCount(1, $results);
        $this->assertSame($runId, $results[0]['run_id']);
        $this->assertSame('pruned', $results[0]['outcome']);
        $this->assertGreaterThanOrEqual(0, $results[0]['history_events_deleted']);

        $this->assertSame(0, WorkflowHistoryEvent::where('workflow_run_id', $runId)->count());
        $this->assertNull(WorkflowRunSummary::find($runId));
    }

    public function test_retention_pass_prunes_extended_run_detail_rows_transactionally(): void
    {
        Queue::fake();

        $this->createNamespace('default');
        $runId = $this->createExpiredClosedRun('default', 'wf-prune-detail-rows');
        $run = WorkflowRun::findOrFail($runId);

        WorkflowMemo::query()->create([
            'workflow_run_id' => $runId,
            'workflow_instance_id' => $run->workflow_instance_id,
            'key' => 'customer',
            'value' => ['id' => 'cust-123'],
            'upserted_at_sequence' => 1,
        ]);

        WorkflowSearchAttribute::query()->create([
            'workflow_run_id' => $runId,
            'workflow_instance_id' => $run->workflow_instance_id,
            'key' => 'plan',
            'type' => WorkflowSearchAttribute::TYPE_KEYWORD,
            'value_keyword' => 'enterprise',
            'upserted_at_sequence' => 1,
        ]);

        WorkflowMessage::query()->create([
            'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_run_id' => $runId,
            'direction' => 'inbound',
            'channel' => 'signal',
            'stream_key' => 'signal:approve',
            'sequence' => 999,
            'consume_state' => 'pending',
        ]);

        WorkflowRunTimerEntry::query()->create([
            'id' => 'retention-timer-'.$runId,
            'workflow_run_id' => $runId,
            'workflow_instance_id' => $run->workflow_instance_id,
            'timer_id' => 'retention-timer',
            'position' => 1,
            'status' => 'scheduled',
        ]);

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/system/retention/pass')
            ->assertOk()
            ->assertJsonPath('processed', 1)
            ->assertJsonPath('pruned', 1)
            ->assertJsonPath('results.0.deleted.memos_deleted', 1)
            ->assertJsonPath('results.0.deleted.search_attributes_deleted', 1)
            ->assertJsonPath('results.0.deleted.messages_deleted', 1)
            ->assertJsonPath('results.0.deleted.run_timer_entries_deleted', 1);

        $this->assertSame(0, WorkflowMemo::where('workflow_run_id', $runId)->count());
        $this->assertSame(0, WorkflowSearchAttribute::where('workflow_run_id', $runId)->count());
        $this->assertSame(0, WorkflowMessage::where('workflow_run_id', $runId)->count());
        $this->assertSame(0, WorkflowRunTimerEntry::where('workflow_run_id', $runId)->count());
        $this->assertNull(WorkflowRunSummary::find($runId));
    }

    public function test_retention_pass_with_specific_run_ids(): void
    {
        $this->createNamespace('default');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/system/retention/pass', [
                'run_ids' => ['non-existent-id'],
            ])
            ->assertOk()
            ->assertJsonPath('processed', 1)
            ->assertJsonPath('pruned', 0)
            ->assertJsonPath('skipped', 1)
            ->assertJsonPath('results.0.outcome', 'skipped')
            ->assertJsonPath('results.0.reason', 'run_not_found');
    }

    public function test_retention_pass_skips_non_terminal_runs(): void
    {
        Queue::fake();

        $this->createNamespace('default');

        $workflow = WorkflowStub::make(ExternalGreetingWorkflow::class, 'wf-running-prune');
        $start = $workflow->start('Ada');
        NamespaceWorkflowScope::bind('default', $workflow->id(), ExternalGreetingWorkflow::class);

        $summary = WorkflowRunSummary::find($start->runId());

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/system/retention/pass', [
                'run_ids' => [$start->runId()],
            ])
            ->assertOk()
            ->assertJsonPath('processed', 1)
            ->assertJsonPath('pruned', 0)
            ->assertJsonPath('skipped', 1)
            ->assertJsonPath('results.0.reason', 'run_not_terminal');
    }

    public function test_retention_pass_respects_namespace_scoping(): void
    {
        Queue::fake();

        $this->createNamespace('ns-a');
        $this->createNamespace('ns-b');
        $runId = $this->createExpiredClosedRun('ns-a', 'wf-scoped-prune');

        $this->withHeaders($this->apiHeaders('ns-b'))
            ->postJson('/api/system/retention/pass', [
                'run_ids' => [$runId],
            ])
            ->assertOk()
            ->assertJsonPath('pruned', 0)
            ->assertJsonPath('skipped', 1)
            ->assertJsonPath('results.0.reason', 'run_not_found');
    }

    // ── Artisan Command ────────────────────────────────────────────

    public function test_artisan_prune_reports_no_expired_runs(): void
    {
        $this->createNamespace('default');

        $this->artisan('history:prune')
            ->assertExitCode(0)
            ->expectsOutputToContain('No expired runs to prune.');
    }

    public function test_artisan_prune_prunes_expired_runs(): void
    {
        Queue::fake();

        $this->createNamespace('default');
        $runId = $this->createExpiredClosedRun('default', 'wf-artisan-prune');

        $this->artisan('history:prune')
            ->assertExitCode(0)
            ->expectsOutputToContain('Done: 1 pruned, 0 skipped, 0 failed.');

        $this->assertNull(WorkflowRunSummary::find($runId));
    }

    public function test_artisan_prune_respects_namespace_filter(): void
    {
        Queue::fake();

        $this->createNamespace('ns-a');
        $this->createNamespace('ns-b');
        $this->createExpiredClosedRun('ns-a', 'wf-ns-a-prune');

        $this->artisan('history:prune', ['--namespace' => 'ns-b'])
            ->assertExitCode(0)
            ->expectsOutputToContain('No expired runs to prune.');
    }

    public function test_worker_heartbeat_runs_bounded_inline_retention_pass(): void
    {
        Queue::fake();
        Cache::flush();

        $this->createNamespace('default');
        $this->registerWorker('retention-worker', 'default');
        $runId = $this->createExpiredClosedRun('default', 'wf-heartbeat-retention');

        $this->assertNotNull(WorkflowRunSummary::find($runId));

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/heartbeat', [
                'worker_id' => 'retention-worker',
            ])
            ->assertOk()
            ->assertJsonPath('acknowledged', true)
            ->assertJsonPath('retention.throttled', false)
            ->assertJsonPath('retention.processed', 1)
            ->assertJsonPath('retention.pruned', 1);

        $this->assertNull(WorkflowRunSummary::find($runId));
        $this->assertSame(0, WorkflowHistoryEvent::where('workflow_run_id', $runId)->count());
    }

    public function test_worker_heartbeat_retention_pass_is_throttled_per_namespace(): void
    {
        Queue::fake();
        Cache::flush();

        $this->createNamespace('default');
        $this->registerWorker('retention-worker', 'default');
        $this->createExpiredClosedRun('default', 'wf-heartbeat-retention-a');
        $secondRunId = $this->createExpiredClosedRun('default', 'wf-heartbeat-retention-b');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/heartbeat', [
                'worker_id' => 'retention-worker',
            ])
            ->assertOk()
            ->assertJsonPath('retention.throttled', false)
            ->assertJsonPath('retention.pruned', 1);

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/heartbeat', [
                'worker_id' => 'retention-worker',
            ])
            ->assertOk()
            ->assertJsonPath('retention.throttled', true)
            ->assertJsonPath('retention.processed', 0);

        $this->assertNotNull(WorkflowRunSummary::find($secondRunId));
    }

    // ── Cluster Info ───────────────────────────────────────────────

    public function test_cluster_info_advertises_history_retention_capability(): void
    {
        $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('capabilities.history_retention', true);
    }

    // ── Auth ───────────────────────────────────────────────────────

    public function test_retention_endpoints_require_auth(): void
    {
        config(['server.auth.driver' => 'token', 'server.auth.token' => 'test-token']);

        $this->getJson('/api/system/retention')
            ->assertUnauthorized();

        $this->postJson('/api/system/retention/pass')
            ->assertUnauthorized();
    }

    // ── Helpers ────────────────────────────────────────────────────

    private function createNamespaceWithRetention(string $name, int $retentionDays): void
    {
        WorkflowNamespace::query()->updateOrCreate(
            ['name' => $name],
            [
                'description' => 'Test namespace',
                'retention_days' => $retentionDays,
                'status' => 'active',
            ],
        );
    }

    private function createExpiredClosedRun(string $namespace, string $workflowId, int $daysAgo = 60): string
    {
        $workflow = WorkflowStub::make(ExternalGreetingWorkflow::class, $workflowId);
        $start = $workflow->start('Ada');
        NamespaceWorkflowScope::bind($namespace, $workflow->id(), ExternalGreetingWorkflow::class);

        // Force namespace on all related models (WorkflowStub defaults to 'default')
        WorkflowRun::where('id', $start->runId())->update(['namespace' => $namespace]);
        WorkflowRunSummary::where('id', $start->runId())->update(['namespace' => $namespace]);
        WorkflowTask::where('workflow_run_id', $start->runId())->update(['namespace' => $namespace]);

        $this->runReadyWorkflowTask($start->runId());

        $run = WorkflowRun::find($start->runId());
        $run->forceFill([
            'status' => RunStatus::Completed->value,
            'closed_at' => now()->subDays($daysAgo),
            'namespace' => $namespace,
        ])->save();

        $summary = WorkflowRunSummary::find($start->runId());
        if ($summary) {
            $summary->forceFill([
                'status' => RunStatus::Completed->value,
                'status_bucket' => 'completed',
                'closed_at' => now()->subDays($daysAgo),
                'namespace' => $namespace,
            ])->save();
        }

        return $start->runId();
    }
}
