<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use App\Support\WorkflowQueryTaskBroker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\Serializers\Avro;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

final class WorkerPayloadValidationTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->createNamespace('default');
        $this->registerWorker('payload-worker', 'payload-validation',
            supportedWorkflowTypes: ['tests.payload-validation'],
            supportedActivityTypes: ['tests.payload-activity'],
            capabilities: ['query_tasks', 'local_activities']);
        WorkerRegistration::query()->where('worker_id', 'payload-worker')->firstOrFail()->update([
            'capability_manifest' => ['local_activities' => ['supported' => true]],
        ]);
    }

    #[DataProvider('invalidPayloads')]
    public function test_malformed_workflow_result_leaves_history_and_lease_unchanged(string $blob, bool $envelope): void
    {
        $task = $this->leaseWorkflow();
        $history = WorkflowHistoryEvent::query()->count();
        $result = $envelope ? ['codec' => 'avro', 'blob' => $blob] : $blob;

        $this->postJson('/api/worker/workflow-tasks/'.$task['task_id'].'/complete', [
            'lease_owner' => $task['lease_owner'],
            'workflow_task_attempt' => $task['workflow_task_attempt'],
            'commands' => [['type' => 'complete_workflow', 'result' => $result]],
        ], $this->workerHeaders())->assertUnprocessable()
            ->assertJsonValidationErrors($envelope ? 'commands.0.result.blob' : 'commands.0.result');

        $this->assertSame($history, WorkflowHistoryEvent::query()->count());
        $this->assertDatabaseHas('workflow_tasks', ['id' => $task['task_id'], 'status' => 'leased',
            'lease_owner' => $task['lease_owner'], 'attempt_count' => $task['workflow_task_attempt']]);
        $this->assertFalse(WorkflowRun::query()->findOrFail($task['run_id'])->status->isTerminal());

        $value = ['codec' => 'json', 'blob' => 'ordinary application fields', 'nested' => ['text' => $blob]];
        $this->postJson('/api/worker/workflow-tasks/'.$task['task_id'].'/complete', [
            'lease_owner' => $task['lease_owner'],
            'workflow_task_attempt' => $task['workflow_task_attempt'],
            'commands' => [['type' => 'complete_workflow', 'result' => Avro::envelope($value)]],
        ], $this->workerHeaders())->assertOk()->assertJsonPath('run_status', 'completed');

        $this->getJson('/api/workflows/payload-validation/runs/'.$task['run_id'], $this->apiHeaders())
            ->assertOk()->assertJsonPath('output', $value);
    }

    public static function invalidPayloads(): array
    {
        $frame = base64_decode(Avro::serialize(['value' => 'complete']), true);
        $invalid = [
            'unframed text' => 'not-an-avro-frame',
            'JSON string' => '"hello"',
            'JSON object' => '{"value":"not Avro"}',
            'JSON long' => '7',
            'JSON double' => '7.0',
            'JSON boolean' => 'true',
            'JSON null' => 'null',
            'base64 JSON' => base64_encode('{"value":"not Avro"}'),
            'truncated datum' => base64_encode(substr($frame, 0, -1)),
            'truncated header' => base64_encode(substr($frame, 0, 9)),
            'wrong fingerprint' => base64_encode(substr_replace($frame, str_repeat("\0", 8), 2, 8)),
            'trailing datum' => base64_encode($frame."\0"),
        ];
        $cases = [];
        foreach ($invalid as $name => $blob) {
            $cases[$name.' serialized'] = [$blob, false];
            $cases[$name.' envelope'] = [$blob, true];
        }

        return $cases;
    }

    #[DataProvider('commandPayloads')]
    public function test_other_serialized_command_fields_are_validated_before_any_command_commits(array $command, string $field): void
    {
        $task = $this->leaseWorkflow();
        $history = WorkflowHistoryEvent::query()->count();
        $this->postJson('/api/worker/workflow-tasks/'.$task['task_id'].'/complete', [
            'lease_owner' => $task['lease_owner'],
            'workflow_task_attempt' => $task['workflow_task_attempt'],
            'commands' => [
                ['type' => 'record_side_effect', 'result' => Avro::serialize('must not commit')],
                $command,
            ],
        ], $this->workerHeaders())->assertUnprocessable()->assertJsonValidationErrors('commands.1.'.$field);
        $this->assertSame($history, WorkflowHistoryEvent::query()->count());
        $this->assertSame('leased', WorkflowTask::query()->findOrFail($task['task_id'])->status->value);
    }

    public static function commandPayloads(): array
    {
        return [
            'activity input' => [['type' => 'schedule_activity', 'activity_type' => 'tests.payload-activity', 'arguments' => 'plain'], 'arguments'],
            'child input' => [['type' => 'start_child_workflow', 'workflow_type' => 'tests.payload-validation', 'arguments' => 'plain'], 'arguments'],
            'continue input' => [['type' => 'continue_as_new', 'arguments' => 'plain'], 'arguments'],
            'update result' => [['type' => 'complete_update', 'update_id' => 'update-1', 'result' => 'plain'], 'result'],
            'side effect' => [['type' => 'record_side_effect', 'result' => 'plain'], 'result'],
            'local activity' => [['type' => 'record_local_activity', 'activity_type' => 'tests.payload-activity',
                'outcome' => 'completed', 'attempts' => [['attempt_number' => 1, 'outcome' => 'completed']],
                'result' => 'plain'], 'result'],
        ];
    }

    #[DataProvider('activityAcknowledgements')]
    public function test_malformed_activity_envelopes_are_rejected_before_acknowledgement(string $operation, string $field): void
    {
        $workflow = $this->leaseWorkflow();
        $this->postJson('/api/worker/workflow-tasks/'.$workflow['task_id'].'/complete', [
            'lease_owner' => $workflow['lease_owner'],
            'workflow_task_attempt' => $workflow['workflow_task_attempt'],
            'commands' => [['type' => 'schedule_activity', 'activity_type' => 'tests.payload-activity',
                'task_queue' => 'payload-validation', 'arguments' => Avro::envelope([])]],
        ], $this->workerHeaders())->assertOk();
        $task = $this->postJson('/api/worker/activity-tasks/poll', [
            'worker_id' => 'payload-worker', 'task_queue' => 'payload-validation',
        ], $this->workerHeaders())->assertOk()->assertJsonPath('poll_status', 'leased')->json('task');
        $history = WorkflowHistoryEvent::query()->count();
        $body = ['activity_attempt_id' => $task['activity_attempt_id'], 'lease_owner' => $task['lease_owner']];
        data_set($body, $field, ['codec' => 'avro', 'blob' => 'malformed']);
        if ($operation === 'fail') {
            $body['failure']['message'] = 'activity failed';
        }
        $this->postJson('/api/worker/activity-tasks/'.$task['task_id'].'/'.$operation, $body, $this->workerHeaders())
            ->assertUnprocessable()->assertJsonValidationErrors($field.'.blob');
        $this->assertSame($history, WorkflowHistoryEvent::query()->count());
        $this->assertSame('running', ActivityAttempt::query()->findOrFail($task['activity_attempt_id'])->status->value);

        // Activity raw values are encoded by the runtime, unlike serialized workflow command strings.
        $this->postJson('/api/worker/activity-tasks/'.$task['task_id'].'/complete', [
            'activity_attempt_id' => $task['activity_attempt_id'], 'lease_owner' => $task['lease_owner'],
            'result' => 'ordinary activity text',
        ], $this->workerHeaders())->assertOk()->assertJsonPath('recorded', true);
    }

    public static function activityAcknowledgements(): array
    {
        return [['complete', 'result'], ['fail', 'failure.details']];
    }

    public function test_malformed_query_envelope_does_not_complete_the_query_lease(): void
    {
        $workflow = $this->leaseWorkflow();
        $this->postJson('/api/worker/workflow-tasks/'.$workflow['task_id'].'/complete', [
            'lease_owner' => $workflow['lease_owner'],
            'workflow_task_attempt' => $workflow['workflow_task_attempt'],
            'commands' => [['type' => 'start_timer', 'delay_seconds' => 60]],
        ], $this->workerHeaders())->assertOk();
        $run = WorkflowRun::query()->findOrFail($workflow['run_id']);
        $broker = app(WorkflowQueryTaskBroker::class);
        $query = $broker->enqueue('default', $run, 'status', Avro::envelope([]));
        $task = $this->postJson('/api/worker/query-tasks/poll', [
            'worker_id' => 'payload-worker', 'task_queue' => 'payload-validation',
        ], $this->workerHeaders())->assertOk()->assertJsonPath('poll_status', 'leased')->json('task');
        $body = ['lease_owner' => $task['lease_owner'], 'query_task_attempt' => $task['query_task_attempt'],
            'result_envelope' => ['codec' => 'avro', 'blob' => 'malformed']];
        $this->postJson('/api/worker/query-tasks/'.$task['query_task_id'].'/complete', $body, $this->workerHeaders())
            ->assertUnprocessable()->assertJsonValidationErrors('result_envelope.blob');
        $this->assertSame('leased', $broker->task($query['query_task_id'])['status']);

        $body['result_envelope'] = Avro::envelope(['codec' => 'json', 'blob' => 'ordinary query fields']);
        $this->postJson('/api/worker/query-tasks/'.$task['query_task_id'].'/complete', $body, $this->workerHeaders())
            ->assertOk()->assertJsonPath('outcome', 'completed');
        $this->assertSame($body['result_envelope'], $broker->task($query['query_task_id'])['result_envelope']);
    }

    private function leaseWorkflow(): array
    {
        $this->postJson('/api/workflows', [
            'workflow_id' => 'payload-validation', 'workflow_type' => 'tests.payload-validation',
            'task_queue' => 'payload-validation',
        ], $this->apiHeaders())->assertCreated();

        return $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'payload-worker', 'task_queue' => 'payload-validation',
        ], $this->workerHeaders())->assertOk()->assertJsonPath('poll_status', 'leased')->json('task');
    }
}
