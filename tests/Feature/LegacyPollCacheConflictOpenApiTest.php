<?php

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use App\Support\WorkflowTaskPollRequestStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\Support\OpenApiSchema;
use Tests\TestCase;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowTask;

class LegacyPollCacheConflictOpenApiTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    public function test_same_id_retry_rejects_a_deliverable_legacy_cached_task_with_a_schema_valid_conflict(): void
    {
        Queue::fake();
        $this->createNamespace('default');
        $this->configureWorkflowTypes([
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);

        $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-legacy-poll-cache-conflict',
            'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'external-workflows',
            'input' => ['Ada'],
        ], $this->apiHeaders())->assertCreated();
        $this->registerWorker('legacy-poll-cache-worker', 'external-workflows');

        $request = [
            'worker_id' => 'legacy-poll-cache-worker',
            'task_queue' => 'external-workflows',
            'poll_request_id' => 'legacy-poll-cache-request',
            'task_kinds' => ['workflow'],
            'timeout_seconds' => 0,
        ];
        $first = $this->postJson(
            '/api/worker/workflow-tasks/poll',
            $request,
            $this->workerHeaders(),
        );
        $first->assertOk()->assertJsonPath('task.task_kind', 'workflow');

        $legacyTask = $first->json('task');
        $this->assertIsArray($legacyTask);
        unset($legacyTask['task_kind']);

        $task = WorkflowTask::query()->findOrFail((string) $legacyTask['task_id']);
        $payload = is_array($task->payload) ? $task->payload : [];
        unset($payload['_server_poll_request_id']);
        $task->forceFill(['payload' => $payload === [] ? null : $payload])->save();

        app(WorkflowTaskPollRequestStore::class)->rememberResult(
            'default',
            'external-workflows',
            null,
            'legacy-poll-cache-worker',
            'legacy-poll-cache-request',
            $legacyTask,
            'leased',
            ['workflow'],
        );

        $retry = $this->postJson(
            '/api/worker/workflow-tasks/poll',
            $request,
            $this->workerHeaders(),
        );
        $retry->assertStatus(409)
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'conflict')
            ->assertJsonPath('reason', 'poll_cached_task_kind_conflict')
            ->assertJsonPath('poll_request_id', 'legacy-poll-cache-request')
            ->assertJsonPath('requested_task_kinds', ['workflow'])
            ->assertJsonPath('cached_task_kind', null)
            ->assertJsonPath('cached_task_kind_state', 'legacy_missing_discriminator');

        $this->assertTrue(WorkflowTask::query()
            ->whereKey($task->id)
            ->where('status', TaskStatus::Leased->value)
            ->where('lease_owner', 'legacy-poll-cache-worker')
            ->where('lease_expires_at', '>', now())
            ->exists());

        $response = json_decode((string) $retry->getContent(), flags: JSON_THROW_ON_ERROR);
        OpenApiSchema::fromFile(
            dirname(__DIR__, 2).'/resources/platform-protocol-specs/worker-protocol-api.openapi.yaml',
        )->assertReferenceMatches('#/components/schemas/CachedPollTaskKindConflict', $response);
    }

    public function test_same_id_retry_never_returns_a_cached_task_outside_the_requested_kind_set(): void
    {
        Queue::fake();
        $this->createNamespace('default');
        $this->configureWorkflowTypes([
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);

        $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-unrequested-poll-cache-conflict',
            'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'external-workflows',
            'input' => ['Ada'],
        ], $this->apiHeaders())->assertCreated();
        $this->registerWorker('unrequested-poll-cache-worker', 'external-workflows');
        WorkerRegistration::query()
            ->where('worker_id', 'unrequested-poll-cache-worker')
            ->firstOrFail()
            ->forceFill(['capabilities' => ['workflow_tasks', 'update_validation_tasks']])
            ->save();

        $first = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'unrequested-poll-cache-worker',
            'task_queue' => 'external-workflows',
            'task_kinds' => ['workflow'],
            'timeout_seconds' => 0,
        ], $this->workerHeaders());
        $first->assertOk()->assertJsonPath('task.task_kind', 'workflow');

        $cachedTask = $first->json('task');
        $this->assertIsArray($cachedTask);
        app(WorkflowTaskPollRequestStore::class)->rememberResult(
            'default',
            'external-workflows',
            null,
            'unrequested-poll-cache-worker',
            'unrequested-poll-cache-request',
            $cachedTask,
            'leased',
            ['update_validation'],
        );

        $retry = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'unrequested-poll-cache-worker',
            'task_queue' => 'external-workflows',
            'poll_request_id' => 'unrequested-poll-cache-request',
            'task_kinds' => ['update_validation'],
            'timeout_seconds' => 0,
        ], $this->workerHeaders());
        $retry->assertStatus(409)
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'conflict')
            ->assertJsonPath('reason', 'poll_cached_task_kind_conflict')
            ->assertJsonPath('requested_task_kinds', ['update_validation'])
            ->assertJsonPath('cached_task_kind', 'workflow')
            ->assertJsonPath('cached_task_kind_state', 'unrequested_discriminator');

        $this->assertTrue(WorkflowTask::query()
            ->whereKey((string) $cachedTask['task_id'])
            ->where('status', TaskStatus::Leased->value)
            ->where('lease_owner', 'unrequested-poll-cache-worker')
            ->where('lease_expires_at', '>', now())
            ->exists());

        $response = json_decode((string) $retry->getContent(), flags: JSON_THROW_ON_ERROR);
        OpenApiSchema::fromFile(
            dirname(__DIR__, 2).'/resources/platform-protocol-specs/worker-protocol-api.openapi.yaml',
        )->assertReferenceMatches('#/components/schemas/CachedPollTaskKindConflict', $response);
    }
}
