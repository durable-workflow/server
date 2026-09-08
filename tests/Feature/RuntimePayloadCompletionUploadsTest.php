<?php

namespace Tests\Feature;

use App\Models\RuntimeExternalPayload;
use App\Models\RuntimePayloadCompletionBudget;
use App\Models\WorkerRegistration;
use App\Models\WorkflowNamespace;
use App\Support\RuntimeExternalPayloadCleanup;
use App\Support\RuntimeExternalPayloadRegistry;
use App\Support\RuntimePayloadCompletionContext;
use App\Support\WorkflowQueryTaskBroker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Feature\Concerns\StoragePressureFixture;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

class RuntimePayloadCompletionUploadsTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;
    use StoragePressureFixture;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->directory = storage_path('framework/testing/completion-uploads');
        File::deleteDirectory($this->directory);
        foreach (['default', 'other'] as $namespace) {
            $this->createNamespace($namespace);
            WorkflowNamespace::query()->where('name', $namespace)->update(['external_payload_storage' => [
                'driver' => 'local', 'enabled' => true, 'threshold_bytes' => 32,
                'config' => ['uri' => 'file://'.$this->directory.'/'.$namespace],
            ]]);
        }
        $this->registerWorker('worker', 'queue');
        config(['server.external_payload_transport.max_payload_bytes' => 4096,
            'server.external_payload_transport.completion_max_bytes' => null]);
        $this->configureStoragePressure();
    }

    protected function tearDown(): void
    {
        RuntimePayloadCompletionBudget::flushEventListeners();
        $this->removeStoragePressure();
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_owned_activity_result_can_upload_complete_and_reconcile_after_lease_closes(): void
    {
        $context = $this->activity();
        $payload = Serializer::serializeWithCodec('avro', str_repeat('result', 200));
        $this->observeStoragePressure('draining');
        $reference = $this->upload($payload, $context)->assertCreated()->json('reference');
        $this->upload($payload, $context)->assertCreated()->assertJsonPath('reference', $reference);
        $this->postJson('/api/worker/activity-tasks/'.$context['task_id'].'/complete', [
            'activity_attempt_id' => $context['attempt'], 'lease_owner' => 'worker',
            'result' => ['codec' => 'avro', 'external_payload' => $reference],
        ], $this->workerHeaders())->assertOk();
        $before = RuntimeExternalPayload::query()->sole()->getRawOriginal();
        $budgetBefore = RuntimePayloadCompletionBudget::query()->sole()->getRawOriginal();
        $this->upload($payload, $context)->assertCreated()->assertJsonPath('reference', $reference);
        $this->assertSame($before, RuntimeExternalPayload::query()->sole()->getRawOriginal());
        $this->assertSame($budgetBefore, RuntimePayloadCompletionBudget::query()->sole()->getRawOriginal());
        $this->getJson('/api/activities/activity', $this->apiHeaders())->assertOk()
            ->assertJsonPath('activity_status', 'completed')
            ->assertJsonPath('result.external_payload.reference_id', $reference['reference_id']);
        $this->call('GET', '/api/external-payloads/v1/'.$reference['reference_id'], [], [], [], [
            'HTTP_X_NAMESPACE' => 'default', 'HTTP_X_DURABLE_WORKFLOW_PAYLOAD_CODEC' => 'avro',
            'HTTP_X_DURABLE_WORKFLOW_PAYLOAD_SIZE' => (string) strlen($payload),
            'HTTP_X_DURABLE_WORKFLOW_PAYLOAD_SHA256' => hash('sha256', $payload),
        ])->assertOk()->assertStreamedContent($payload);
    }

    public function test_workflow_completion_upload_can_be_committed_during_draining(): void
    {
        $context = $this->workflow();
        $payload = Serializer::serializeWithCodec('avro', ['result' => str_repeat('x', 100)]);
        $this->observeStoragePressure('draining');
        $reference = $this->upload($payload, $context)->assertCreated()->json('reference');
        $this->postJson('/api/worker/workflow-tasks/'.$context['task_id'].'/complete', [
            'lease_owner' => 'worker', 'workflow_task_attempt' => $context['attempt'],
            'commands' => [['type' => 'complete_workflow', 'sequence' => 1,
                'result' => ['codec' => 'avro', 'external_payload' => $reference]]],
        ], $this->workerHeaders())->assertOk();
        $this->assertNotNull(RuntimeExternalPayload::query()->sole()->retained_at);
        $this->assertSame('completed', WorkflowRun::query()->sole()->status->value);
        $this->upload($payload, $context)->assertCreated()->assertJsonPath('reference', $reference);
    }

    public function test_query_completion_upload_uses_its_own_current_lease(): void
    {
        $this->postJson('/api/workflows', ['workflow_id' => 'query-workflow',
            'workflow_type' => 'tests.external-greeting-workflow', 'task_queue' => 'queue', 'input' => []],
            $this->apiHeaders())->assertCreated();
        WorkerRegistration::query()->where('worker_id', 'worker')->update(['capabilities' => ['query_tasks']]);
        $broker = app(WorkflowQueryTaskBroker::class);
        $broker->enqueue('default', WorkflowRun::query()->sole(), 'status', [
            'codec' => 'avro', 'blob' => Serializer::serializeWithCodec('avro', []),
        ]);
        $task = $this->postJson('/api/worker/query-tasks/poll', ['worker_id' => 'worker', 'task_queue' => 'queue'],
            $this->workerHeaders())->assertOk()->json('task');
        $this->assertIsArray($task);
        $context = ['schema' => RuntimePayloadCompletionContext::SCHEMA, 'kind' => 'query',
            'task_id' => $task['query_task_id'], 'attempt' => $task['query_task_attempt'],
            'lease_owner' => 'worker', 'operation' => 'complete', 'slot' => ['result_envelope']];
        $this->observeStoragePressure('draining');
        $wrong = $context;
        $wrong['attempt']++;
        $this->upload('bytes', $wrong)->assertStatus(409);
        $payload = Serializer::serializeWithCodec('avro', ['status' => str_repeat('ready', 50)]);
        $reference = $this->upload($payload, $context)->assertCreated()->json('reference');
        $this->postJson('/api/worker/query-tasks/'.$context['task_id'].'/complete', [
            'lease_owner' => 'worker', 'query_task_attempt' => $context['attempt'],
            'result_envelope' => ['codec' => 'avro', 'external_payload' => $reference],
        ], $this->workerHeaders())->assertOk()->assertJsonPath('outcome', 'completed');
        $this->upload($payload, $context)->assertCreated()->assertJsonPath('reference', $reference);
    }

    public function test_activity_cannot_claim_another_budget_as_a_workflow_task(): void
    {
        $context = $this->activity();
        $context['kind'] = 'workflow';
        $context['attempt'] = 1;
        $context['slot'] = ['commands', 0, 'result'];
        $this->observeStoragePressure('draining');
        $this->upload('bytes', $context)->assertStatus(409);
        $this->assertDatabaseCount('runtime_payload_completion_budgets', 0);
    }

    public function test_workflow_slots_are_bounded_even_when_the_bytes_are_deduplicated(): void
    {
        $context = $this->workflow();
        $this->observeStoragePressure('draining');
        for ($index = 0; $index < 128; $index++) {
            $context['slot'] = ['commands', $index, 'arguments'];
            $this->upload('same', $context)->assertCreated();
        }
        $context['slot'] = ['commands', 128, 'arguments'];
        $this->upload('same', $context)->assertStatus(503)
            ->assertJsonPath('reason', 'storage_pressure')->assertJsonPath('request_admitted', false);
        $this->assertDatabaseCount('runtime_external_payloads', 1);
        $this->assertCount(128, RuntimePayloadCompletionBudget::query()->sole()->slots);
    }

    public function test_renewed_worker_registration_does_not_authorize_a_wrong_workflow_attempt(): void
    {
        $context = $this->workflow();
        $context['attempt']++;
        $this->observeStoragePressure('draining');
        $this->upload('bytes', $context)->assertStatus(409);
        $this->assertDatabaseCount('runtime_payload_completion_budgets', 0);
    }

    #[DataProvider('wrongContexts')]
    public function test_foreign_or_unrelated_context_rejects_without_reserving_storage(array $changes, string $namespace): void
    {
        $context = array_replace($this->activity(), $changes);
        $this->observeStoragePressure('draining');
        $this->upload('bytes', $context, $namespace)->assertStatus(409)
            ->assertJsonPath('reason', 'external_payload_completion_lease_rejected');
        $this->assertDatabaseCount('runtime_payload_completion_budgets', 0);
        $this->assertDatabaseCount('runtime_external_payloads', 0);
    }

    public static function wrongContexts(): array
    {
        return [[['task_id' => 'missing'], 'default'], [['attempt' => 'missing'], 'default'],
            [['lease_owner' => 'another-worker'], 'default'], [[], 'other']];
    }

    public function test_operator_credentials_cannot_claim_the_worker_completion_exception(): void
    {
        config(['server.auth.driver' => 'token', 'server.auth.token' => null,
            'server.auth.role_tokens' => ['worker' => 'worker-fixture', 'operator' => 'operator-fixture']]);
        $context = ['schema' => RuntimePayloadCompletionContext::SCHEMA, 'kind' => 'activity',
            'task_id' => 'missing', 'attempt' => 'missing', 'lease_owner' => 'worker',
            'operation' => 'complete', 'slot' => ['result']];
        $this->observeStoragePressure('draining');
        $this->upload('bytes', $context, token: 'operator-fixture')->assertForbidden()
            ->assertJsonPath('reason', 'external_payload_unauthorized');
        $this->upload('bytes', $context, token: 'worker-fixture')->assertStatus(409)
            ->assertJsonPath('reason', 'external_payload_completion_lease_rejected');
        $this->assertDatabaseCount('runtime_payload_completion_budgets', 0);
    }

    public function test_registry_ownership_guards_precede_object_locks_in_each_write_transaction(): void
    {
        $order = [];
        DB::listen(function ($query) use (&$order): void {
            if (str_starts_with(strtolower($query->sql), 'select')
                && str_contains($query->sql, 'runtime_external_payload_object_locks')) {
                $order[] = 'object-lock';
            }
        });
        app(RuntimeExternalPayloadRegistry::class)->upload('default', 'bytes', 'avro', hash('sha256', 'bytes'),
            function () use (&$order): void {
                $this->assertGreaterThan(0, DB::transactionLevel());
                $order[] = 'ownership';
            });
        $this->assertSame(['ownership', 'object-lock', 'ownership',
            'ownership', 'object-lock', 'ownership'], $order);
    }

    public function test_expired_lease_cannot_create_an_upload(): void
    {
        $context = $this->activity();
        WorkflowTask::query()->whereKey($context['task_id'])->update(['lease_expires_at' => now()->subSecond()]);
        $this->observeStoragePressure('draining');
        $this->upload('bytes', $context)->assertStatus(409);
        $this->assertDatabaseCount('runtime_payload_completion_budgets', 0);
        $this->assertDatabaseCount('runtime_external_payloads', 0);
    }

    public function test_one_slot_cannot_rotate_content_and_complete_fail_share_the_budget(): void
    {
        $context = $this->activity();
        config(['server.external_payload_transport.completion_max_bytes' => 6]);
        $this->observeStoragePressure('draining');
        $this->upload('first', $context)->assertCreated();
        $this->upload('other', $context)->assertStatus(409)->assertJsonPath('reason', 'external_payload_completion_conflict');
        $context['operation'] = 'fail';
        $context['slot'] = ['failure', 'details'];
        $this->upload('other', $context)->assertStatus(503)->assertJsonPath('reason', 'storage_pressure')
            ->assertJsonPath('request_admitted', false)->assertJsonPath('retryable', true);
        $this->upload('first', $context)->assertCreated();
        $this->assertDatabaseCount('runtime_external_payloads', 1);
        $this->assertDatabaseCount('runtime_payload_completion_budgets', 1);
        $this->observeStoragePressure('normal');
        $this->upload('other', null)->assertCreated();
    }

    #[DataProvider('closedAdmission')]
    public function test_fenced_or_stale_state_does_not_admit_even_an_owned_upload(string $state): void
    {
        $context = $this->activity();
        $this->observeStoragePressure($state === 'stale' ? 'normal' : $state);
        if ($state === 'stale') {
            $this->travel(120)->seconds();
        }
        $this->upload('bytes', $context)->assertStatus(503);
        $this->assertDatabaseCount('runtime_external_payloads', 0);
        $this->assertDatabaseCount('runtime_payload_completion_budgets', 0);
    }

    public static function closedAdmission(): array
    {
        return [['fenced'], ['stale']];
    }

    public function test_lease_is_rechecked_after_reservation_and_before_object_write(): void
    {
        $context = $this->activity();
        $this->observeStoragePressure('draining');
        RuntimePayloadCompletionBudget::created(function () use ($context): void {
            WorkflowTask::query()->whereKey($context['task_id'])->update(['lease_expires_at' => now()->subSecond()]);
        });
        $this->upload('bytes', $context)->assertStatus(409);
        $this->assertDatabaseCount('runtime_external_payloads', 0);
    }

    public function test_hard_fence_is_rechecked_before_object_write(): void
    {
        $context = $this->activity();
        $this->observeStoragePressure('draining');
        RuntimePayloadCompletionBudget::created(fn () => $this->observeStoragePressure('fenced'));
        $this->upload('bytes', $context)->assertStatus(503);
        $this->assertDatabaseCount('runtime_external_payloads', 0);
    }

    public function test_namespace_quota_still_applies_to_owned_uploads(): void
    {
        $context = $this->activity();
        config(['server.external_payload_transport.hard_max_bytes_per_namespace' => 2]);
        $this->observeStoragePressure('draining');
        $this->upload('bytes', $context)->assertStatus(429)->assertJsonPath('reason', 'external_payload_namespace_bytes_exhausted');
        $this->assertDatabaseCount('runtime_external_payloads', 0);
    }

    public function test_cleanup_does_not_reset_an_expired_but_renewable_activity_budget(): void
    {
        $context = $this->activity();
        $this->observeStoragePressure('draining');
        $this->upload('bytes', $context)->assertCreated();
        $this->observeStoragePressure('normal');
        RuntimePayloadCompletionBudget::query()->update(['expires_at' => now()->subHour()]);
        WorkflowTask::query()->whereKey($context['task_id'])->update(['lease_expires_at' => now()->subMinute()]);
        app(RuntimeExternalPayloadCleanup::class)->runPass();
        $this->assertDatabaseCount('runtime_payload_completion_budgets', 1);
        WorkflowTask::query()->whereKey($context['task_id'])->update(['status' => 'completed']);
        RuntimePayloadCompletionBudget::query()->update(['expires_at' => now()->subHour()]);
        app(RuntimeExternalPayloadCleanup::class)->runPass();
        $this->assertDatabaseCount('runtime_payload_completion_budgets', 0);
    }

    private function activity(): array
    {
        $this->postJson('/api/activities', ['activity_id' => 'activity',
            'activity_type' => 'tests.external-greeting-activity', 'task_queue' => 'queue', 'input' => []], $this->apiHeaders())->assertCreated();
        $task = $this->postJson('/api/worker/activity-tasks/poll', ['worker_id' => 'worker', 'task_queue' => 'queue'],
            $this->workerHeaders())->assertOk()->json('task');
        $this->assertIsArray($task);

        return ['schema' => RuntimePayloadCompletionContext::SCHEMA, 'kind' => 'activity',
            'task_id' => $task['task_id'], 'attempt' => $task['activity_attempt_id'],
            'lease_owner' => 'worker', 'operation' => 'complete', 'slot' => ['result']];
    }

    private function workflow(): array
    {
        $this->postJson('/api/workflows', ['workflow_id' => 'workflow',
            'workflow_type' => 'tests.external-greeting-workflow', 'task_queue' => 'queue', 'input' => []],
            $this->apiHeaders())->assertCreated();
        $task = $this->postJson('/api/worker/workflow-tasks/poll', ['worker_id' => 'worker', 'task_queue' => 'queue'],
            $this->workerHeaders())->assertOk()->json('task');
        $this->assertIsArray($task);

        return ['schema' => RuntimePayloadCompletionContext::SCHEMA, 'kind' => 'workflow',
            'task_id' => $task['task_id'], 'attempt' => $task['workflow_task_attempt'],
            'lease_owner' => 'worker', 'operation' => 'complete', 'slot' => ['commands', 0, 'result']];
    }

    private function upload(string $bytes, ?array $context, string $namespace = 'default', ?string $token = null): TestResponse
    {
        return $this->call('POST', '/api/external-payloads/v1', [], [], [], [
            'CONTENT_TYPE' => 'application/octet-stream', 'HTTP_X_NAMESPACE' => $namespace,
            'HTTP_X_DURABLE_WORKFLOW_PAYLOAD_CODEC' => 'avro', 'HTTP_X_DURABLE_WORKFLOW_PAYLOAD_SIZE' => (string) strlen($bytes),
            'HTTP_X_DURABLE_WORKFLOW_PAYLOAD_SHA256' => hash('sha256', $bytes),
            ...($context === null ? [] : ['HTTP_X_DURABLE_WORKFLOW_PAYLOAD_COMPLETION' => json_encode($context, JSON_THROW_ON_ERROR)]),
            ...($token === null ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$token]),
        ], $bytes);
    }
}
