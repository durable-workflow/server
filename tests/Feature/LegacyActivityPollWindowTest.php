<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowTask;

final class LegacyActivityPollWindowTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    public function test_legacy_foreign_tasks_cannot_fill_the_activity_poll_window(): void
    {
        Queue::fake();
        $this->createNamespace('default');
        $this->configureWorkflowTypes(['tests.external-greeting-workflow' => ExternalGreetingWorkflow::class]);
        $tasks = [];
        foreach (['foreign', 'owned'] as $owner) {
            $runId = $this->postJson('/api/workflows', [
                'workflow_id' => $owner, 'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'workflow', 'input' => ['Ada'],
            ], $this->apiHeaders())->assertCreated()->json('run_id');
            $this->runReadyWorkflowTask($runId);
            $tasks[$owner] = WorkflowTask::query()->where('workflow_run_id', $runId)->where('task_type', 'activity')->sole();
        }
        DB::table('workflow_instances')->where('id', 'foreign')->update(['namespace' => 'other']);
        $tasks['foreign']->forceFill(['priority' => 0])->save();
        for ($i = 1; $i < 10; $i++) {
            $tasks['foreign']->replicate()->save();
        }
        $this->registerWorker('window-worker', 'external-activities');
        $this->postJson('/api/worker/activity-tasks/poll', [
            'worker_id' => 'window-worker', 'task_queue' => 'external-activities',
        ], $this->workerHeaders())->assertOk()->assertJsonPath('task.task_id', $tasks['owned']->id);
    }
}
