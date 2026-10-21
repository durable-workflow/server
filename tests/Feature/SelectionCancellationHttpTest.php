<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

final class SelectionCancellationHttpTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->createNamespace('default');
        $this->createNamespace('other');
        $this->registerWorker('select-worker', 'select-http', supportedWorkflowTypes: ['select-http']);
        $this->registerWorker('activity-worker', 'later', supportedActivityTypes: ['lose']);
    }

    public function test_losing_queued_activity_is_cancelled_through_http_and_cannot_be_claimed(): void
    {
        [$runId, $task, $cancel] = $this->stageTimerWinner();
        $this->complete($task, [$cancel])->assertOk()->assertJsonPath('recorded', true);

        $this->assertSame('cancelled', ActivityExecution::query()->sole()->status->value);
        $this->assertSame(1, WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::SelectionOperationCancelled->value)->count());
        $this->postJson('/api/worker/activity-tasks/poll', [
            'worker_id' => 'activity-worker', 'task_queue' => 'later',
        ], $this->workerHeaders())->assertOk()->assertJsonPath('task', null);

        $resume = $this->pollWorkflow();
        $this->complete($resume, [[
            'type' => 'complete_workflow', 'result' => Serializer::serialize(['timer-won', 9007199254740993, null, 'λ']),
        ]])->assertOk()->assertJsonPath('recorded', true);
        $this->assertSame(RunStatus::Completed, WorkflowRun::query()->findOrFail($runId)->status);
        $this->assertSame(0, WorkflowTask::query()->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Activity->value)->where('status', TaskStatus::Leased->value)->count());
    }

    #[DataProvider('invalidMembers')]
    public function test_invalid_or_foreign_member_cannot_cancel_the_original_activity(array $changes, string $namespace, int $status): void
    {
        [$runId, $task, $cancel] = $this->stageTimerWinner();
        $before = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->count();
        $this->complete($task, [array_replace($cancel, $changes)], $namespace)->assertStatus($status);
        $this->assertSame('scheduled', ActivityExecution::query()->sole()->status->value);
        $this->assertSame($before, WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->count());
        $this->assertSame(TaskStatus::Leased, WorkflowTask::query()->findOrFail($task['task_id'])->status);
    }

    public static function invalidMembers(): array
    {
        return [
            'wrong operation' => [['operation_identity' => 'foreign-activity'], 'default', 409],
            'wrong member' => [['member_key' => 'deadline'], 'default', 409],
            'outside group' => [['member_base_sequence' => 3], 'default', 422],
            'wrong namespace' => [[], 'other', 404],
        ];
    }

    private function stageTimerWinner(): array
    {
        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'select-http', 'workflow_type' => 'select-http', 'task_queue' => 'select-http',
        ], $this->apiHeaders())->assertCreated();
        $task = $this->pollWorkflow();
        $commands = [];
        foreach ([['schedule_activity', 'act', 'activity'], ['start_timer', 'deadline', 'timer']] as $index => [$type, $key, $kind]) {
            $entry = [
                'parallel_group_id' => 'select-calls:1:2', 'parallel_group_kind' => 'mixed',
                'parallel_group_mode' => 'select', 'parallel_group_base_sequence' => 1,
                'parallel_group_size' => 2, 'parallel_group_index' => $index,
                'selection_member_key' => $key, 'selection_member_index' => $index,
                'selection_member_base_sequence' => 1 + $index, 'selection_member_size' => 1,
                'selection_member_kind' => $kind,
            ];
            $commands[] = array_merge([
                'type' => $type, ...$entry, 'parallel_group_path' => [$entry],
            ], $index === 0 ? ['activity_type' => 'lose', 'queue' => 'later'] : ['delay_seconds' => 0]);
        }
        $this->complete($task, $commands)->assertOk()->assertJsonPath('recorded', true);
        $timerTask = WorkflowTask::query()->where('task_type', TaskType::Timer->value)->sole();
        $this->app->call([new RunTimerTask($timerTask->id), 'handle']);
        $cancel = [
            'type' => 'cancel_selection_operation', 'selection_group_id' => 'select-calls:1:2',
            'member_key' => 'act', 'member_index' => 0, 'member_base_sequence' => 1, 'member_size' => 1,
            'operation_kind' => 'activity', 'operation_identity' => ActivityExecution::query()->sole()->id,
        ];

        return [$start->json('run_id'), $this->pollWorkflow(), $cancel];
    }

    private function pollWorkflow(): array
    {
        return $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'select-worker', 'task_queue' => 'select-http',
        ], $this->workerHeaders())->assertOk()->json('task');
    }

    private function complete(array $task, array $commands, string $namespace = 'default'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/worker/workflow-tasks/'.$task['task_id'].'/complete', [
            'lease_owner' => 'select-worker', 'workflow_task_attempt' => $task['workflow_task_attempt'],
            'commands' => $commands,
        ], $this->workerHeaders($namespace));
    }
}
