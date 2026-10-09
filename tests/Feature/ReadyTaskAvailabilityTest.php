<?php

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\ExternalGreetingActivity;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowTask;

class ReadyTaskAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_null_availability_is_ready_while_future_work_waits(): void
    {
        Queue::fake();
        config([
            'workflows.v2.types.workflows' => ['tests.external-greeting-workflow' => ExternalGreetingWorkflow::class],
            'workflows.v2.types.activities' => ['tests.external-greeting-activity' => ExternalGreetingActivity::class],
        ]);
        WorkflowNamespace::query()->create(['name' => 'default', 'status' => 'active', 'retention_days' => 30]);
        $headers = ['X-Namespace' => 'default', 'X-Durable-Workflow-Control-Plane-Version' => '2', WorkerProtocol::HEADER => WorkerProtocol::VERSION];
        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'null-availability', 'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'availability', 'input' => ['Ada'],
        ], $headers)->assertCreated();
        $runId = $start->json('run_id');
        foreach (['future', 'ready'] as $worker) {
            $this->postJson('/api/worker/register', [
                'worker_id' => $worker, 'task_queue' => 'availability', 'runtime' => 'php',
                'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
                'supported_workflow_types' => ['tests.external-greeting-workflow'],
                'supported_activity_types' => ['tests.external-greeting-activity'],
            ], $headers)->assertCreated();
        }

        $task = WorkflowTask::query()->where('workflow_run_id', $runId)->where('task_type', TaskType::Workflow->value)->sole();
        $task->update(['available_at' => now()->addDay()]);
        $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'future', 'task_queue' => 'availability', 'timeout_seconds' => 0,
        ], $headers)->assertOk()->assertJsonPath('task', null);
        $task->update(['available_at' => null]);
        $claim = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'ready', 'task_queue' => 'availability', 'timeout_seconds' => 0,
        ], $headers)->assertOk()->assertJsonPath('task.task_id', $task->id)
            ->assertJsonPath('task.run_id', $runId)->assertJsonPath('task.workflow_task_attempt', 1)->json('task');
        $this->postJson('/api/worker/workflow-tasks/'.$task->id.'/complete', [
            'lease_owner' => $claim['lease_owner'], 'workflow_task_attempt' => $claim['workflow_task_attempt'],
            'commands' => [[
                'type' => 'schedule_activity', 'activity_type' => 'tests.external-greeting-activity',
                'arguments' => Serializer::serializeWithCodec('avro', ['Ada']), 'queue' => 'availability',
            ]],
        ], $headers)->assertOk()->assertJsonPath('recorded', true);

        $activity = WorkflowTask::query()->where('workflow_run_id', $runId)->where('task_type', TaskType::Activity->value)->sole();
        $activity->update(['available_at' => now()->addDay()]);
        $this->postJson('/api/worker/activity-tasks/poll', [
            'worker_id' => 'future', 'task_queue' => 'availability', 'timeout_seconds' => 0,
        ], $headers)->assertOk()->assertJsonPath('task', null);
        $activity->update(['available_at' => null]);
        $claim = $this->postJson('/api/worker/activity-tasks/poll', [
            'worker_id' => 'ready', 'task_queue' => 'availability', 'timeout_seconds' => 0,
        ], $headers)->assertOk()->assertJsonPath('task.task_id', $activity->id)
            ->assertJsonPath('task.run_id', $runId)->assertJsonPath('task.attempt_number', 1)->json('task');
        $body = [
            'lease_owner' => $claim['lease_owner'], 'activity_attempt_id' => $claim['activity_attempt_id'],
            'result' => 'Hello, Ada!',
        ];
        $this->postJson('/api/worker/activity-tasks/'.$activity->id.'/complete', $body, $headers)
            ->assertOk()->assertJsonPath('recorded', true);
        $this->postJson('/api/worker/activity-tasks/'.$activity->id.'/complete', $body, $headers)
            ->assertOk()->assertJsonPath('recorded', false);
        $resumed = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'ready', 'task_queue' => 'availability', 'timeout_seconds' => 0,
        ], $headers)->assertOk()->assertJsonPath('task.run_id', $runId)->json('task');
        $this->postJson('/api/worker/workflow-tasks/'.$resumed['task_id'].'/complete', [
            'lease_owner' => $resumed['lease_owner'], 'workflow_task_attempt' => $resumed['workflow_task_attempt'],
            'commands' => [['type' => 'complete_workflow', 'result' => Serializer::serializeWithCodec('avro', 'Hello, Ada!')]],
        ], $headers)->assertOk()->assertJsonPath('recorded', true);
        $this->getJson('/api/workflows/null-availability/runs/'.$runId, $headers)
            ->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('output', 'Hello, Ada!');
        $this->getJson('/api/workflows/null-availability/runs/'.$runId.'/history', $headers)
            ->assertOk()->assertJsonCount(6, 'events')->assertJsonPath('events.5.event_type', 'WorkflowCompleted');
    }
}
