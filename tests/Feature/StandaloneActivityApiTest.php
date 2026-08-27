<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use App\Support\ControlPlaneProtocol;
use App\Support\RuntimeExternalPayloadReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\StandaloneActivity\StandaloneActivityHostType;
use Workflow\V2\Support\ExternalPayloads;

/**
 * End-to-end coverage for standalone activities on the control plane.
 *
 * Exercises the SDK-shaped contract:
 *  - POST /api/activities returns a top-level activity handle (not a
 *    workflow that happens to schedule one activity).
 *  - GET /api/activities/{id} surfaces the host run + activity execution.
 *  - The activity is dispatched on the existing worker activity-task
 *    poll/complete/fail surface — same Activity definition, no rewrite.
 *  - Failure + retry semantics: a transient failure schedules a retry
 *    while the host run stays Running; the success on the retry attempt
 *    closes the host run with the activity result.
 */
class StandaloneActivityApiTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    private string $externalStorageDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->externalStorageDirectory = storage_path('framework/testing/standalone-activity-external-storage');
        File::deleteDirectory($this->externalStorageDirectory);

        WorkflowNamespace::query()->updateOrCreate(
            ['name' => 'default'],
            [
                'description' => 'Default namespace',
                'retention_days' => 30,
                'status' => 'active',
            ],
        );
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->externalStorageDirectory);

        parent::tearDown();
    }

    public function test_start_returns_handle_and_persists_host_run_and_activity_execution(): void
    {
        $response = $this->withHeaders($this->apiHeaders())->postJson('/api/activities', [
            'activity_id' => 'standalone-greet-1',
            'activity_type' => 'tests.external-greeting-activity',
            'task_queue' => 'external-activities',
            'input' => ['Taylor'],
            'retry_policy' => [
                'max_attempts' => 3,
                'backoff_seconds' => [0],
            ],
            'start_to_close_timeout_seconds' => 60,
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'activity_id' => 'standalone-greet-1',
            'workflow_id' => 'standalone-greet-1',
            'workflow_type' => StandaloneActivityHostType::WORKFLOW_TYPE,
            'activity_type' => 'tests.external-greeting-activity',
            'task_queue' => 'external-activities',
            'namespace' => 'default',
            'command_status' => 'accepted',
        ]);

        $body = $response->json();
        $this->assertIsString($body['workflow_run_id']);
        $this->assertIsString($body['activity_execution_id']);

        $run = WorkflowRun::query()->find($body['workflow_run_id']);
        $this->assertNotNull($run);
        $this->assertSame(StandaloneActivityHostType::WORKFLOW_TYPE, $run->workflow_type);
        $this->assertSame(RunStatus::Running, $run->status);
        $this->assertSame('default', $run->namespace);

        $execution = ActivityExecution::query()->find($body['activity_execution_id']);
        $this->assertNotNull($execution);
        $this->assertSame('tests.external-greeting-activity', $execution->activity_type);
        $this->assertSame(ActivityStatus::Pending, $execution->status);
    }

    public function test_poll_deadline_precision_matches_timeout_scanner_deadline(): void
    {
        $startedAt = Carbon::parse('2026-06-22 12:00:00.100000', 'UTC');
        Carbon::setTestNow($startedAt);

        try {
            $this->registerWorker(
                'standalone-timeout-worker',
                'external-activities',
                supportedWorkflowTypes: [],
                supportedActivityTypes: ['tests.external-greeting-activity'],
            );

            $start = $this->withHeaders($this->apiHeaders())->postJson('/api/activities', [
                'activity_id' => 'standalone-timeout-precision-1',
                'activity_type' => 'tests.external-greeting-activity',
                'task_queue' => 'external-activities',
                'input' => ['Taylor'],
                'retry_policy' => [
                    'max_attempts' => 1,
                    'backoff_seconds' => [0],
                ],
                'start_to_close_timeout_seconds' => 1,
                'schedule_to_close_timeout_seconds' => 30,
            ])->assertStatus(201);

            $poll = $this->withHeaders($this->workerHeaders())->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'standalone-timeout-worker',
                'task_queue' => 'external-activities',
            ])->assertOk();

            $startToClose = $poll->json('task.deadlines.start_to_close');
            $scheduleToClose = $poll->json('task.deadlines.schedule_to_close');

            $this->assertSame('2026-06-22T12:00:01.100000Z', $startToClose);
            $this->assertSame('2026-06-22T12:00:30.100000Z', $scheduleToClose);

            Carbon::setTestNow(Carbon::parse($startToClose)->addMilliseconds(600));

            $status = $this->withHeaders($this->apiHeaders())
                ->getJson('/api/system/activity-timeouts')
                ->assertOk();

            $this->assertContains(
                $start->json('activity_execution_id'),
                $status->json('expired_execution_ids'),
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_external_storage_round_trips_oversize_standalone_activity_payloads(): void
    {
        WorkflowNamespace::query()->where('name', 'default')->update([
            'external_payload_storage' => [
                'driver' => 'local',
                'enabled' => true,
                'threshold_bytes' => 32,
                'config' => [
                    'uri' => 'file://'.$this->externalStorageDirectory,
                ],
            ],
        ]);

        $this->registerWorker(
            'php-worker-standalone-external',
            'external-activities',
            supportedWorkflowTypes: [],
            supportedActivityTypes: ['tests.external-greeting-activity'],
        );

        $input = str_repeat('A', 128);

        $start = $this->withHeaders($this->apiHeaders())->postJson('/api/activities', [
            'activity_id' => 'standalone-external-greet',
            'activity_type' => 'tests.external-greeting-activity',
            'task_queue' => 'external-activities',
            'input' => [$input],
        ]);

        $start->assertStatus(201);

        $run = WorkflowRun::query()->findOrFail((string) $start->json('workflow_run_id'));
        $execution = ActivityExecution::query()->findOrFail((string) $start->json('activity_execution_id'));

        $this->assertStoredExternalPayload($run->arguments);
        $this->assertSame([$input], $run->workflowArguments());
        $this->assertStoredExternalPayload($execution->arguments);
        $this->assertSame([$input], $execution->activityArguments());

        $workerHeaders = $this->workerHeaders() + [
            ControlPlaneProtocol::HEADER => ControlPlaneProtocol::VERSION,
        ];

        $poll = $this->withHeaders($workerHeaders)->postJson('/api/worker/activity-tasks/poll', [
            'worker_id' => 'php-worker-standalone-external',
            'task_queue' => 'external-activities',
        ]);

        $poll->assertOk()
            ->assertJsonPath('task.payload_codec', 'avro')
            ->assertJsonStructure(['task' => ['arguments' => ['codec', 'external_payload']]]);
        $this->assertExternalEnvelopeDecodes($poll->json('task.arguments'), [$input]);

        $result = ['message' => str_repeat('B', 128)];
        $codec = $poll->json('task.payload_codec') ?? CodecRegistry::defaultCodec();
        $resultBlob = Serializer::serializeWithCodec($codec, $result);

        $this->withHeaders($workerHeaders)->postJson(
            '/api/worker/activity-tasks/'.$poll->json('task.task_id').'/complete',
            [
                'activity_attempt_id' => $poll->json('task.activity_attempt_id'),
                'lease_owner' => $poll->json('task.lease_owner'),
                'result' => [
                    'codec' => $codec,
                    'blob' => $resultBlob,
                ],
            ],
        )->assertOk();

        $execution->refresh();
        $this->assertStoredExternalPayload($execution->result);
        $this->assertSame($result, $execution->activityResult());

        $show = $this->withHeaders($this->apiHeaders())->getJson('/api/activities/standalone-external-greet');
        $show->assertOk()
            ->assertJsonPath('activity_status', ActivityStatus::Completed->value)
            ->assertJsonPath('result.external_payload.schema', RuntimeExternalPayloadReference::SCHEMA)
            ->assertJsonStructure(['result' => ['codec', 'external_payload']])
            ->assertJsonMissingPath('result.external_storage');
        $this->assertStringNotContainsString(
            'file://',
            json_encode($show->json('result'), JSON_THROW_ON_ERROR),
        );
        $this->assertExternalEnvelopeDecodes($show->json('result'), $result);
    }

    public function test_show_returns_404_for_unknown_activity(): void
    {
        $response = $this->withHeaders($this->apiHeaders())->getJson('/api/activities/missing-activity');

        $response->assertStatus(404);
        $response->assertJsonFragment(['reason' => 'activity_not_found']);
    }

    public function test_index_only_lists_standalone_activities(): void
    {
        $this->withHeaders($this->apiHeaders())->postJson('/api/activities', [
            'activity_id' => 'standalone-list-1',
            'activity_type' => 'tests.external-greeting-activity',
            'task_queue' => 'external-activities',
            'input' => ['World'],
        ])->assertStatus(201);

        $response = $this->withHeaders($this->apiHeaders())->getJson('/api/activities');

        $response->assertOk();
        $body = $response->json();
        $this->assertSame(1, $body['activity_count']);
        $this->assertSame('standalone-list-1', $body['activities'][0]['activity_id']);
        $this->assertSame('tests.external-greeting-activity', $body['activities'][0]['activity_type']);
    }

    public function test_show_and_index_include_operator_visible_attempt_state(): void
    {
        $this->registerWorker(
            'php-worker-standalone-visible-attempt',
            'external-activities',
            supportedWorkflowTypes: [],
            supportedActivityTypes: ['tests.external-greeting-activity'],
        );

        $start = $this->withHeaders($this->apiHeaders())->postJson('/api/activities', [
            'activity_id' => 'standalone-visible-attempt',
            'activity_type' => 'tests.external-greeting-activity',
            'task_queue' => 'external-activities',
            'input' => ['Visible'],
            'heartbeat_timeout_seconds' => 30,
        ]);

        $start->assertStatus(201);

        $workerHeaders = $this->workerHeaders() + [
            ControlPlaneProtocol::HEADER => ControlPlaneProtocol::VERSION,
        ];

        $poll = $this->withHeaders($workerHeaders)->postJson('/api/worker/activity-tasks/poll', [
            'worker_id' => 'php-worker-standalone-visible-attempt',
            'task_queue' => 'external-activities',
        ]);

        $poll->assertOk();
        $task = $poll->json('task');
        $this->assertIsArray($task);

        $show = $this->withHeaders($this->apiHeaders())->getJson('/api/activities/standalone-visible-attempt');

        $show->assertOk()
            ->assertJsonPath('activity_execution_id', $start->json('activity_execution_id'))
            ->assertJsonPath('activity_status', ActivityStatus::Running->value)
            ->assertJsonPath('current_attempt_id', $task['activity_attempt_id'])
            ->assertJsonPath('current_attempt_status', ActivityAttemptStatus::Running->value)
            ->assertJsonPath('current_attempt.activity_attempt_id', $task['activity_attempt_id'])
            ->assertJsonPath('current_attempt.workflow_task_id', $task['task_id'])
            ->assertJsonPath('current_attempt.status', ActivityAttemptStatus::Running->value)
            ->assertJsonPath('current_attempt.can_continue', true)
            ->assertJsonPath('attempts.0.activity_attempt_id', $task['activity_attempt_id'])
            ->assertJsonPath('attempts.0.status', ActivityAttemptStatus::Running->value)
            ->assertJsonPath('attempt_state.current_attempt_id', $task['activity_attempt_id'])
            ->assertJsonPath('attempt_state.attempts.0.activity_attempt_id', $task['activity_attempt_id']);

        $list = $this->withHeaders($this->apiHeaders())->getJson('/api/activities');

        $list->assertOk()
            ->assertJsonPath('activities.0.activity_id', 'standalone-visible-attempt')
            ->assertJsonPath('activities.0.activity_execution_id', $start->json('activity_execution_id'))
            ->assertJsonPath('activities.0.current_attempt_id', $task['activity_attempt_id'])
            ->assertJsonPath('activities.0.current_attempt_status', ActivityAttemptStatus::Running->value)
            ->assertJsonPath('activities.0.attempts.0.activity_attempt_id', $task['activity_attempt_id'])
            ->assertJsonPath('activities.0.attempts.0.status', ActivityAttemptStatus::Running->value);
    }

    public function test_failure_then_retry_then_success_closes_host_run_with_result(): void
    {
        $this->registerWorker(
            'php-worker-standalone',
            'external-activities',
            supportedWorkflowTypes: [],
            supportedActivityTypes: ['tests.external-greeting-activity'],
        );

        $this->withHeaders($this->apiHeaders())->postJson('/api/activities', [
            'activity_id' => 'standalone-retry-greet',
            'activity_type' => 'tests.external-greeting-activity',
            'task_queue' => 'external-activities',
            'input' => ['Retry'],
            'retry_policy' => [
                'max_attempts' => 3,
                'backoff_seconds' => [0],
            ],
        ])->assertStatus(201);

        $workerHeaders = $this->workerHeaders() + [
            ControlPlaneProtocol::HEADER => ControlPlaneProtocol::VERSION,
        ];

        // First attempt: poll, fail, expect retry scheduled and run still Running.
        $firstPoll = $this->withHeaders($workerHeaders)->postJson('/api/worker/activity-tasks/poll', [
            'worker_id' => 'php-worker-standalone',
            'task_queue' => 'external-activities',
        ]);
        $firstPoll->assertOk();
        $firstTask = $firstPoll->json('task');
        $this->assertNotNull($firstTask, 'Standalone activity task should be claimable.');
        $this->assertSame('tests.external-greeting-activity', $firstTask['activity_type']);

        $this->withHeaders($workerHeaders)->postJson(
            '/api/worker/activity-tasks/'.$firstTask['task_id'].'/fail',
            [
                'activity_attempt_id' => $firstTask['activity_attempt_id'],
                'lease_owner' => $firstTask['lease_owner'],
                'failure' => [
                    'message' => 'transient',
                    'type' => 'RuntimeException',
                ],
            ],
        )->assertOk();

        $run = WorkflowRun::query()
            ->where('workflow_instance_id', 'standalone-retry-greet')
            ->orderByDesc('run_number')
            ->first();
        $this->assertNotNull($run);
        $this->assertSame(RunStatus::Running, $run->status, 'Host run must stay Running across retry.');

        // Second attempt: poll, complete with the activity's expected result.
        $secondPoll = $this->withHeaders($workerHeaders)->postJson('/api/worker/activity-tasks/poll', [
            'worker_id' => 'php-worker-standalone',
            'task_queue' => 'external-activities',
        ]);
        $secondPoll->assertOk();
        $secondTask = $secondPoll->json('task');
        $this->assertNotNull($secondTask, 'Retry should re-deliver the same activity to the worker.');
        $this->assertNotSame($firstTask['task_id'], $secondTask['task_id']);

        $codec = $secondTask['payload_codec'] ?? CodecRegistry::defaultCodec();
        $resultBlob = Serializer::serializeWithCodec($codec, 'Hello, Retry!');
        $this->withHeaders($workerHeaders)->postJson(
            '/api/worker/activity-tasks/'.$secondTask['task_id'].'/complete',
            [
                'activity_attempt_id' => $secondTask['activity_attempt_id'],
                'lease_owner' => $secondTask['lease_owner'],
                'result' => [
                    'codec' => $codec,
                    'blob' => $resultBlob,
                ],
            ],
        )->assertOk();

        $run->refresh();
        $this->assertSame(RunStatus::Completed, $run->status);
        $this->assertSame('completed', $run->closed_reason);

        // The handle endpoint must surface the result envelope to the caller
        // so a job-style consumer can read a result without spelunking
        // workflow history.
        $show = $this->withHeaders($this->apiHeaders())->getJson('/api/activities/standalone-retry-greet');
        $show->assertOk();
        $show->assertJsonPath('status', RunStatus::Completed->value);
        $show->assertJsonPath('activity_status', ActivityStatus::Completed->value);
        $this->assertSame($resultBlob, $show->json('result.blob'));
    }

    public function test_default_activity_failure_history_payload_omits_runtime_exception_internals(): void
    {
        $this->registerWorker(
            'php-worker-neutral-failure',
            'external-activities',
            supportedWorkflowTypes: [],
            supportedActivityTypes: ['tests.external-greeting-activity'],
        );

        $this->withHeaders($this->apiHeaders())->postJson('/api/activities', [
            'activity_id' => 'standalone-neutral-failure',
            'activity_type' => 'tests.external-greeting-activity',
            'task_queue' => 'external-activities',
            'input' => ['Neutral'],
            'retry_policy' => [
                'max_attempts' => 1,
                'backoff_seconds' => [0],
            ],
        ])->assertStatus(201);

        $workerHeaders = $this->workerHeaders() + [
            ControlPlaneProtocol::HEADER => ControlPlaneProtocol::VERSION,
        ];

        $poll = $this->withHeaders($workerHeaders)->postJson('/api/worker/activity-tasks/poll', [
            'worker_id' => 'php-worker-neutral-failure',
            'task_queue' => 'external-activities',
        ]);

        $poll->assertOk();
        $task = $poll->json('task');
        $this->assertIsArray($task);

        $detailsBlob = Serializer::serializeWithCodec('avro', [
            'stage' => 'inventory',
            'retry_after' => 30,
        ]);

        $this->withHeaders($workerHeaders)->postJson(
            '/api/worker/activity-tasks/'.$task['task_id'].'/fail',
            [
                'activity_attempt_id' => $task['activity_attempt_id'],
                'lease_owner' => $task['lease_owner'],
                'failure' => [
                    'message' => 'php activity planned failure',
                    'type' => 'PolyglotPhpPlannedFailure',
                    'stack_trace' => 'at /app/src/PlannedFailureActivity.php:42',
                    'non_retryable' => true,
                    'details' => [
                        'codec' => 'avro',
                        'blob' => $detailsBlob,
                    ],
                ],
            ],
        )->assertOk();

        $run = WorkflowRun::query()
            ->where('workflow_instance_id', 'standalone-neutral-failure')
            ->orderByDesc('run_number')
            ->firstOrFail();

        $history = $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/standalone-neutral-failure/runs/{$run->id}/history");

        $history->assertOk();

        $activityFailed = collect($history->json('events'))
            ->firstWhere('event_type', 'ActivityFailed');

        $this->assertIsArray($activityFailed);
        $exception = $activityFailed['payload']['exception'] ?? null;
        $this->assertIsArray($exception);
        $this->assertSame('PolyglotPhpPlannedFailure', $exception['type'] ?? null);
        $this->assertSame('php activity planned failure', $exception['message'] ?? null);
        $this->assertTrue($exception['non_retryable'] ?? false);
        $this->assertSame('avro', $exception['details_payload_codec'] ?? null);
        $this->assertSame('avro', $exception['details']['codec'] ?? null);
        $this->assertSame($detailsBlob, $exception['details']['blob'] ?? null);

        foreach (['class', 'file', 'line', 'trace', 'properties', 'stack_trace'] as $internalField) {
            $this->assertArrayNotHasKey($internalField, $exception);
        }
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function assertExternalEnvelopeDecodes(array $envelope, mixed $expected): void
    {
        $this->assertArrayHasKey('external_payload', $envelope);
        $this->assertArrayNotHasKey('external_storage', $envelope);
        $this->assertArrayNotHasKey('blob', $envelope);

        $reference = $envelope['external_payload'];
        $this->assertIsArray($reference);
        $this->assertSame(RuntimeExternalPayloadReference::SCHEMA, $reference['schema'] ?? null);
        $this->assertArrayNotHasKey('uri', $reference);

        $response = $this->withHeaders([
            'X-Namespace' => 'default',
            'X-Durable-Workflow-Payload-Codec' => $reference['codec'],
            'X-Durable-Workflow-Payload-Size' => (string) $reference['size_bytes'],
            'X-Durable-Workflow-Payload-SHA256' => $reference['sha256'],
        ])->get('/api/external-payloads/v1/'.$reference['reference_id']);

        $response->assertOk();
        $payload = $response->getContent();
        $this->assertSame((int) $reference['size_bytes'], strlen($payload));
        $this->assertSame((string) $reference['sha256'], hash('sha256', $payload));

        $this->assertSame(
            $expected,
            Serializer::unserializeWithCodec((string) $envelope['codec'], $payload),
        );
    }

    private function assertStoredExternalPayload(?string $payload): void
    {
        if (! is_string($payload)) {
            $this->fail('Expected payload column to contain a stored external reference.');
        }

        $this->assertStringStartsWith(ExternalPayloads::STORED_REFERENCE_PREFIX, $payload);
    }
}
