<?php

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\LegacyVersionQueryWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\WorkflowExecutor;

class LegacyVersionQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_http_queries_replay_a_legacy_patch_at_the_recorded_timer_position(): void
    {
        Queue::fake();
        config()->set('workflows.v2.types.workflows', [
            'tests.legacy-version-query-workflow' => LegacyVersionQueryWorkflow::class,
        ]);
        WorkflowNamespace::query()->updateOrCreate(['name' => 'default'], [
            'description' => 'Legacy query regression',
            'retention_days' => 30,
            'status' => 'active',
        ]);
        $headers = ['X-Namespace' => 'default', 'X-Durable-Workflow-Control-Plane-Version' => '2'];
        $start = $this->withHeaders($headers)->postJson('/api/workflows', [
            'workflow_id' => 'legacy-version-query',
            'workflow_type' => 'tests.legacy-version-query-workflow',
        ])->assertCreated();
        $runId = (string) $start->json('run_id');
        $started = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::WorkflowStarted->value)
            ->firstOrFail();

        // Model a run created before definition fingerprints were recorded.
        $payload = $started->payload;
        unset($payload['workflow_definition_fingerprint']);
        $started->forceFill(['payload' => $payload])->save();
        $taskId = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', 'workflow')
            ->where('status', 'ready')
            ->value('id');
        $this->assertIsString($taskId);
        (new RunWorkflowTask($taskId))->handle(app(WorkflowExecutor::class));

        $run = WorkflowRun::query()->findOrFail($runId);
        $this->assertSame(RunStatus::Waiting, $run->status);
        $this->assertSame(1, WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::TimerScheduled->value)
            ->firstOrFail()->payload['sequence']);
        $this->assertFalse(WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::VersionMarkerRecorded->value)
            ->exists());
        $beforeRun = $run->getAttributes();
        $beforeHistory = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->get()->toArray();

        foreach ([1, 2] as $repetition) {
            $this->withHeaders($headers)
                ->postJson('/api/workflows/legacy-version-query/query/currentState')
                ->assertOk()
                ->assertJsonPath('run_id', $runId)
                ->assertJsonPath('result.patched', false)
                ->assertJsonPath('result.stage', 'waiting-for-timer');
            $this->assertSame($beforeRun, $run->fresh()->getAttributes());
            $this->assertSame($beforeHistory, WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $runId)->get()->toArray());
        }
    }
}
