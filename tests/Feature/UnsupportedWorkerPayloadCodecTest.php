<?php

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use App\Support\WorkflowQueryTaskBroker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\HistoryBudget;

class UnsupportedWorkerPayloadCodecTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    private string $externalStorageDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->externalStorageDirectory = storage_path('framework/testing/unsupported-codec-external-payloads');
        File::deleteDirectory($this->externalStorageDirectory);

        config([
            'server.polling.timeout' => 0,
            'server.query_tasks.timeout' => 0,
        ]);

        $this->createNamespace('default');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->externalStorageDirectory);

        parent::tearDown();
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

    public function test_worker_workflow_task_oversize_inline_payload_preserves_opaque_codec(): void
    {
        Queue::fake();

        WorkflowNamespace::where('name', 'default')->firstOrFail()->forceFill([
            'external_payload_storage' => [
                'driver' => 'local',
                'enabled' => true,
                'threshold_bytes' => 8,
                'config' => [
                    'uri' => 'file://'.$this->externalStorageDirectory,
                ],
            ],
        ])->save();

        $arguments = str_repeat('opaque-zstd-payload-', 8);

        $run = $this->startRemoteWorkflow('wf-worker-unsupported-codec-external');
        $run->forceFill([
            'payload_codec' => 'zstd',
            'arguments' => $arguments,
        ])->save();

        $this->registerWorker(
            'python-codec-workflow-external',
            'python-workflows',
            supportedWorkflowTypes: ['python.codec-workflow'],
        );

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'python-codec-workflow-external',
                'task_queue' => 'python-workflows',
            ]);

        $poll->assertOk()
            ->assertJsonPath('task.payload_codec', 'zstd')
            ->assertJsonPath('task.arguments.codec', 'zstd')
            ->assertJsonPath('task.arguments.blob', $arguments);

        $this->assertArrayNotHasKey('external_storage', $poll->json('task.arguments'));
    }

    public function test_opaque_codec_empty_history_preserves_falsey_budget_across_response_paths(): void
    {
        Queue::fake();

        $run = $this->startRemoteWorkflow('wf-worker-opaque-codec-empty-history');

        WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->delete();
        WorkflowRunSummary::query()->whereKey($run->id)->delete();
        $run->forceFill([
            'last_history_sequence' => 0,
            'payload_codec' => 'zstd',
        ])->save();

        $this->registerWorker(
            'python-codec-empty-history',
            'python-workflows',
            supportedWorkflowTypes: ['python.codec-workflow'],
        );

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'python-codec-empty-history',
                'task_queue' => 'python-workflows',
            ]);

        $poll->assertOk()
            ->assertJsonPath('task.payload_codec', 'zstd')
            ->assertJsonPath('task.history_events', [])
            ->assertJsonPath('task.next_history_page_token', null);

        $pollTask = $poll->json('task');

        $this->assertIsArray($pollTask);
        $this->assertEmptyHistoryBudget($pollTask);

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $historyRequest = [
            'lease_owner' => 'python-codec-empty-history',
            'workflow_task_attempt' => $attempt,
            'next_history_page_token' => base64_encode('0'),
            'history_page_size' => 1,
        ];

        $historyPage = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/history", $historyRequest);

        $historyPage->assertOk()
            ->assertJsonPath('history_events', [])
            ->assertJsonPath('next_history_page_token', null);

        $historyPagePayload = $historyPage->json();

        $this->assertIsArray($historyPagePayload);
        $this->assertEmptyHistoryBudget($historyPagePayload);

        // Compression negotiation uses the same bounded page path. An empty
        // event list remains inline because it is below the public threshold.
        $compressedPath = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/history", [
                ...$historyRequest,
                'accept_history_encoding' => 'gzip',
            ]);

        $compressedPath->assertOk()
            ->assertJsonPath('history_events', [])
            ->assertJsonPath('next_history_page_token', null)
            ->assertJsonMissingPath('history_events_compressed')
            ->assertJsonMissingPath('history_events_encoding');

        $compressedPathPayload = $compressedPath->json();

        $this->assertIsArray($compressedPathPayload);
        $this->assertEmptyHistoryBudget($compressedPathPayload);
    }

    public function test_opaque_codec_history_pages_reuse_one_bounded_budget_without_hydrating_the_run_history(): void
    {
        Queue::fake();

        $run = $this->startRemoteWorkflow('wf-worker-opaque-codec-bounded-history');
        $lastSequence = (int) WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->max('sequence');
        $recordedAt = now();
        $rows = [];

        for ($offset = 1; $offset <= 80; $offset++) {
            $rows[] = [
                'id' => (string) Str::ulid(),
                'workflow_run_id' => $run->id,
                'sequence' => $lastSequence + $offset,
                'event_type' => HistoryEventType::SideEffectRecorded->value,
                'payload' => json_encode(['result' => "opaque-history-{$offset}"], JSON_THROW_ON_ERROR),
                'workflow_task_id' => null,
                'workflow_command_id' => null,
                'recorded_at' => $recordedAt,
                'created_at' => $recordedAt,
                'updated_at' => $recordedAt,
            ];
        }

        DB::table('workflow_history_events')->insert($rows);
        $run->forceFill([
            'last_history_sequence' => $lastSequence + count($rows),
            'payload_codec' => 'zstd',
        ])->save();
        WorkflowRunSummary::query()->whereKey($run->id)->delete();

        $expectedBudget = HistoryBudget::forRun($run->refresh());
        $run->unsetRelation('historyEvents');

        $this->registerWorker(
            'python-codec-bounded-history',
            'python-workflows',
            supportedWorkflowTypes: ['python.codec-workflow'],
        );

        $retrievedHistoryEvents = 0;
        Event::listen(
            'eloquent.retrieved: '.WorkflowHistoryEvent::class,
            static function () use (&$retrievedHistoryEvents): void {
                $retrievedHistoryEvents++;
            },
        );

        DB::flushQueryLog();
        DB::enableQueryLog();
        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'python-codec-bounded-history',
                'task_queue' => 'python-workflows',
                'history_page_size' => 1,
            ]);
        $pollQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $poll->assertOk()
            ->assertJsonPath('task.payload_codec', 'zstd')
            ->assertJsonPath('task.total_history_events', $expectedBudget['history_event_count'])
            ->assertJsonPath('task.history_size_bytes', $expectedBudget['history_size_bytes'])
            ->assertJsonPath('task.history_fan_out', $expectedBudget['history_fan_out'])
            ->assertJsonPath('task.continue_as_new_recommended', $expectedBudget['continue_as_new_recommended'])
            ->assertJsonPath('task.history_budget_pressure', $expectedBudget['pressure'])
            ->assertJsonPath('task.history_budget_pressure_dimensions', $expectedBudget['pressure_dimensions']);

        $this->assertCount(1, $poll->json('task.history_events'));
        $this->assertIsString($poll->json('task.next_history_page_token'));
        $this->assertSame(2, $retrievedHistoryEvents);
        $this->assertCount(1, $this->historyAggregateQueries($pollQueries));
        $this->assertFalse($run->relationLoaded('historyEvents'));

        $retrievedHistoryEvents = 0;
        DB::flushQueryLog();
        DB::enableQueryLog();
        $historyPage = $this->withHeaders($this->workerHeaders())
            ->postJson(sprintf('/api/worker/workflow-tasks/%s/history', $poll->json('task.task_id')), [
                'lease_owner' => 'python-codec-bounded-history',
                'workflow_task_attempt' => $poll->json('task.workflow_task_attempt'),
                'next_history_page_token' => $poll->json('task.next_history_page_token'),
                'history_page_size' => 60,
                'accept_history_encoding' => 'gzip',
            ]);
        $pageQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $historyPage->assertOk()
            ->assertJsonPath('history_events', [])
            ->assertJsonPath('history_events_encoding', 'gzip')
            ->assertJsonPath('total_history_events', $expectedBudget['history_event_count'])
            ->assertJsonPath('history_size_bytes', $expectedBudget['history_size_bytes'])
            ->assertJsonPath('history_fan_out', $expectedBudget['history_fan_out'])
            ->assertJsonPath('continue_as_new_recommended', $expectedBudget['continue_as_new_recommended'])
            ->assertJsonPath('history_budget_pressure', $expectedBudget['pressure'])
            ->assertJsonPath('history_budget_pressure_dimensions', $expectedBudget['pressure_dimensions']);

        $this->assertIsString($historyPage->json('history_events_compressed'));
        $this->assertIsString($historyPage->json('next_history_page_token'));
        $this->assertSame(61, $retrievedHistoryEvents);
        $this->assertCount(0, $this->historyAggregateQueries($pageQueries));
        $this->assertFalse($run->relationLoaded('historyEvents'));
    }

    public function test_history_response_preserves_opaque_payload_codec_in_envelopes(): void
    {
        Queue::fake();

        $run = $this->startRemoteWorkflow('wf-history-unsupported-codec');
        $run->forceFill(['payload_codec' => 'zstd'])->save();

        $nextSequence = ((int) WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->max('sequence')) + 1;

        WorkflowHistoryEvent::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => $nextSequence,
            'event_type' => HistoryEventType::WorkflowCompleted->value,
            'payload' => [
                'payload_codec' => 'zstd',
                'output' => 'opaque-completed-payload',
            ],
            'recorded_at' => now(),
        ]);

        $history = $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/wf-history-unsupported-codec/runs/{$run->id}/history");

        $history->assertOk();

        $completed = collect($history->json('events'))
            ->firstWhere('event_type', HistoryEventType::WorkflowCompleted->value);

        $this->assertIsArray($completed);
        $this->assertSame('zstd', $completed['payload']['output']['codec'] ?? null);
        $this->assertSame('opaque-completed-payload', $completed['payload']['output']['blob'] ?? null);
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

        $scheduledExecution = ActivityExecution::query()
            ->where('workflow_run_id', $run->id)
            ->where('activity_type', 'python.codec-activity')
            ->firstOrFail();
        $scheduledExecution->forceFill([
            'payload_codec' => 'zstd',
            'arguments' => 'opaque-activity-arguments',
        ])->save();

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

    public function test_worker_query_result_envelope_uses_inline_result_when_codec_is_unsupported(): void
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

        $complete->assertOk()
            ->assertJsonPath('query_task_id', $task['query_task_id'])
            ->assertJsonPath('query_task_attempt', 1)
            ->assertJsonPath('outcome', 'completed');

        $stored = $broker->task((string) $task['query_task_id']);

        $this->assertIsArray($stored);
        $this->assertSame('completed', $stored['status'] ?? null);
        $this->assertSame(['status' => 'ready'], $stored['result'] ?? null);
        $this->assertNull($stored['result_envelope'] ?? null);
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

    /**
     * @param  list<array{query: string, bindings: array<mixed>, time: float|null}>  $queries
     * @return list<array{query: string, bindings: array<mixed>, time: float|null}>
     */
    private function historyAggregateQueries(array $queries): array
    {
        return array_values(array_filter(
            $queries,
            static fn (array $query): bool => str_contains($query['query'], 'workflow_history_events')
                && str_contains(strtolower($query['query']), 'count(*)'),
        ));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertEmptyHistoryBudget(array $payload): void
    {
        $expected = [
            'total_history_events' => 0,
            'history_size_bytes' => 0,
            'history_fan_out' => 0,
            'continue_as_new_recommended' => false,
            'history_budget_pressure' => 'ok',
            'history_budget_pressure_dimensions' => [],
        ];

        foreach ($expected as $key => $value) {
            $this->assertArrayHasKey($key, $payload);
            $this->assertSame($value, $payload[$key]);
        }
    }
}
