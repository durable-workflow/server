<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use App\Support\ControlPlaneProtocol;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\StandaloneActivity\StandaloneActivityHostType;

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

    protected function setUp(): void
    {
        parent::setUp();

        WorkflowNamespace::query()->updateOrCreate(
            ['name' => 'default'],
            [
                'description' => 'Default namespace',
                'retention_days' => 30,
                'status' => 'active',
            ],
        );
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
}
