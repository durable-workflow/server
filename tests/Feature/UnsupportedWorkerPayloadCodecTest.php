<?php

namespace Tests\Feature;

use App\Support\WorkflowQueryTaskBroker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

class UnsupportedWorkerPayloadCodecTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'server.polling.timeout' => 0,
            'server.query_tasks.timeout' => 0,
        ]);

        $this->createNamespace('default');
    }

    public function test_worker_workflow_task_payload_codec_failure_records_task_failure(): void
    {
        Queue::fake();

        $run = $this->startRemoteWorkflow('wf-worker-unsupported-codec');
        $run->forceFill(['payload_codec' => 'zstd'])->save();

        $this->registerWorker(
            'python-codec-workflow',
            'python-workflows',
            supportedWorkflowTypes: ['python.codec-workflow'],
        );

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'python-codec-workflow',
                'task_queue' => 'python-workflows',
            ]);

        $poll->assertOk()
            ->assertJsonPath('task.payload_codec', 'zstd')
            ->assertJsonPath('task.arguments.codec', 'zstd');

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');

        $fail = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/fail", [
                'lease_owner' => 'python-codec-workflow',
                'workflow_task_attempt' => $attempt,
                'failure' => [
                    'message' => "Unsupported payload codec 'zstd'.",
                    'type' => 'ValueError',
                    'stack_trace' => 'at worker.decode_payload',
                ],
            ]);

        $fail->assertOk()
            ->assertJsonPath('outcome', 'failed')
            ->assertJsonPath('recorded', true);

        $task = WorkflowTask::query()->findOrFail($taskId);

        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertSame("Unsupported payload codec 'zstd'.", $task->last_error);
        $this->assertFalse(
            WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $run->id)
                ->where('event_type', HistoryEventType::WorkflowCompleted->value)
                ->exists(),
        );
    }

    public function test_worker_activity_payload_codec_failure_is_non_retryable_even_without_details(): void
    {
        Queue::fake();

        $run = $this->startRemoteWorkflow('wf-activity-unsupported-codec');
        $this->registerWorker(
            'python-codec-scheduler',
            'python-workflows',
            supportedWorkflowTypes: ['python.codec-workflow'],
        );

        $workflowPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'python-codec-scheduler',
                'task_queue' => 'python-workflows',
            ]);

        $workflowPoll->assertOk();

        $schedule = $this->withHeaders($this->workerHeaders())
            ->postJson(sprintf('/api/worker/workflow-tasks/%s/complete', $workflowPoll->json('task.task_id')), [
                'lease_owner' => $workflowPoll->json('task.lease_owner'),
                'workflow_task_attempt' => $workflowPoll->json('task.workflow_task_attempt'),
                'commands' => [
                    [
                        'type' => 'schedule_activity',
                        'activity_type' => 'python.codec-activity',
                        'arguments' => Serializer::serializeWithCodec('avro', ['Ada']),
                        'queue' => 'python-activities',
                        'retry_policy' => [
                            'max_attempts' => 3,
                            'backoff_seconds' => [1],
                        ],
                    ],
                ],
            ]);

        $schedule->assertOk()
            ->assertJsonPath('outcome', 'completed');

        $run->refresh()->forceFill(['payload_codec' => 'zstd'])->save();

        $this->registerWorker(
            'python-codec-activity',
            'python-activities',
            supportedActivityTypes: ['python.codec-activity'],
        );

        $activityPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'python-codec-activity',
                'task_queue' => 'python-activities',
            ]);

        $activityPoll->assertOk()
            ->assertJsonPath('task.payload_codec', 'zstd')
            ->assertJsonPath('task.arguments.codec', 'zstd');

        $fail = $this->withHeaders($this->workerHeaders())
            ->postJson(sprintf('/api/worker/activity-tasks/%s/fail', $activityPoll->json('task.task_id')), [
                'activity_attempt_id' => $activityPoll->json('task.activity_attempt_id'),
                'lease_owner' => $activityPoll->json('task.lease_owner'),
                'failure' => [
                    'message' => "Unsupported payload codec 'zstd'.",
                    'type' => 'ValueError',
                    'stack_trace' => 'at worker.decode_payload',
                    'non_retryable' => true,
                ],
            ]);

        $fail->assertOk()
            ->assertJsonPath('outcome', 'failed')
            ->assertJsonPath('recorded', true);

        $this->assertIsString($fail->json('next_task_id'));

        $execution = ActivityExecution::query()
            ->findOrFail((string) $activityPoll->json('task.activity_execution_id'));

        $this->assertSame(ActivityStatus::Failed, $execution->status);
        $this->assertTrue(
            WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $run->id)
                ->where('event_type', HistoryEventType::ActivityFailed->value)
                ->exists(),
        );
        $this->assertFalse(
            WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $run->id)
                ->where('event_type', HistoryEventType::ActivityRetryScheduled->value)
                ->exists(),
        );

        /** @var WorkflowRun $refreshedRun */
        $refreshedRun = WorkflowRun::query()->findOrFail($run->id);
        $this->assertSame('zstd', $refreshedRun->payload_codec);

        $resumePoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'python-codec-scheduler',
                'task_queue' => 'python-workflows',
            ]);

        $resumePoll->assertOk()
            ->assertJsonPath('task.task_id', $fail->json('next_task_id'))
            ->assertJsonPath('task.payload_codec', 'zstd');

        $resumeEvents = collect((array) $resumePoll->json('task.history_events'));
        $this->assertTrue($resumeEvents->contains(
            static fn (array $event): bool => ($event['event_type'] ?? null) === HistoryEventType::ActivityFailed->value
        ));
    }

    public function test_worker_query_payload_codec_failure_records_failed_query_task(): void
    {
        Queue::fake();

        $run = $this->startRemoteWorkflow('wf-query-unsupported-codec');
        $run->forceFill(['payload_codec' => 'zstd'])->save();

        $this->registerWorker(
            'python-codec-query',
            'python-workflows',
            supportedWorkflowTypes: ['python.codec-workflow'],
        );

        /** @var WorkflowQueryTaskBroker $broker */
        $broker = app(WorkflowQueryTaskBroker::class);
        $task = $broker->enqueue('default', $run, 'status', [
            'codec' => 'zstd',
            'blob' => 'opaque-query-arguments',
        ]);

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/query-tasks/poll', [
                'worker_id' => 'python-codec-query',
                'task_queue' => 'python-workflows',
            ]);

        $poll->assertOk()
            ->assertJsonPath('task.query_task_id', $task['query_task_id'])
            ->assertJsonPath('task.payload_codec', 'zstd')
            ->assertJsonPath('task.workflow_arguments.codec', 'zstd')
            ->assertJsonPath('task.query_arguments.codec', 'zstd');

        $fail = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/query-tasks/{$task['query_task_id']}/fail", [
                'lease_owner' => 'python-codec-query',
                'query_task_attempt' => 1,
                'failure' => [
                    'message' => "Unsupported payload codec 'zstd'.",
                    'reason' => 'query_payload_decode_failed',
                    'type' => 'ValueError',
                    'stack_trace' => 'at worker.decode_payload',
                ],
            ]);

        $fail->assertOk()
            ->assertJsonPath('outcome', 'failed')
            ->assertJsonPath('reason', 'query_payload_decode_failed');

        $stored = $broker->task((string) $task['query_task_id']);

        $this->assertIsArray($stored);
        $this->assertSame('failed', $stored['status'] ?? null);
        $this->assertSame('query_payload_decode_failed', $stored['reason'] ?? null);
        $this->assertSame("Unsupported payload codec 'zstd'.", $stored['message'] ?? null);
    }

    public function test_worker_query_result_envelope_rejects_unsupported_codec_without_completing(): void
    {
        Queue::fake();

        $run = $this->startRemoteWorkflow('wf-query-result-unsupported-codec');
        $this->registerWorker(
            'python-codec-query-result',
            'python-workflows',
            supportedWorkflowTypes: ['python.codec-workflow'],
        );

        /** @var WorkflowQueryTaskBroker $broker */
        $broker = app(WorkflowQueryTaskBroker::class);
        $task = $broker->enqueue('default', $run, 'status', [
            'codec' => 'avro',
            'blob' => Serializer::serializeWithCodec('avro', ['summary']),
        ]);

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/query-tasks/poll', [
                'worker_id' => 'python-codec-query-result',
                'task_queue' => 'python-workflows',
            ]);

        $poll->assertOk()
            ->assertJsonPath('task.query_task_id', $task['query_task_id']);

        $complete = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/query-tasks/{$task['query_task_id']}/complete", [
                'lease_owner' => 'python-codec-query-result',
                'query_task_attempt' => 1,
                'result' => ['status' => 'ready'],
                'result_envelope' => [
                    'codec' => 'zstd',
                    'blob' => 'opaque-result',
                ],
            ]);

        $complete->assertStatus(422)
            ->assertJsonValidationErrors(['result_envelope.codec']);

        $stored = $broker->task((string) $task['query_task_id']);

        $this->assertIsArray($stored);
        $this->assertSame('leased', $stored['status'] ?? null);
        $this->assertArrayNotHasKey('result_envelope', $stored);
    }

    private function startRemoteWorkflow(string $workflowId): WorkflowRun
    {
        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => $workflowId,
                'workflow_type' => 'python.codec-workflow',
                'task_queue' => 'python-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        return WorkflowRun::query()->findOrFail((string) $start->json('run_id'));
    }
}
