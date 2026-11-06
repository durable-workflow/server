<?php

namespace Tests\Source;

use App\Models\WorkerRegistration;
use App\Models\WorkflowNamespace;
use App\Support\CooperativeCancellationPolicy;
use App\Support\PreparedLocalActivityPolicy;
use App\Support\PreparedLocalRetryAdmission;
use App\Support\WorkerPollFence;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\PreparedLocalActivityRetryTaskBridge;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\LocalActivityExecutor;
use Workflow\V2\Support\PortableLocalActivityControl;
use Workflow\V2\Support\PortableLocalActivityPreparation;

/** Exact candidate source only. The test role delegates to real Core kernels. */
final class PreparedLocalRetryAdmissionSourceTest extends TestCase
{
    use RefreshDatabase;

    private WorkflowTaskBridge $baseBridge;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(interface_exists(PreparedLocalActivityRetryTaskBridge::class), 'Select the exact maintained Core retry candidate.');
        Queue::fake();
        config(['server.worker_protocol.version' => '1.20', 'workflows.v2.workflow_task_lease_seconds' => 300,
            'workflows.v2.types.workflows' => ['tests.external-greeting-workflow' => ExternalGreetingWorkflow::class]]);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-06T00:00:00Z'));
        WorkflowNamespace::query()->create(['name' => 'default', 'description' => 'Test', 'retention_days' => 30, 'status' => 'active']);
        $this->baseBridge = app(WorkflowTaskBridge::class);
        $this->assertNotInstanceOf(\Mockery\MockInterface::class, $this->baseBridge);
        $this->bindRetryRole();
        $this->assertTrue(PreparedLocalRetryAdmission::backendSupported());
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function bindRetryRole(array $overrides = []): void
    {
        $base = $this->baseBridge;
        $interfaces = array_values(array_unique([...class_implements($base), PreparedLocalActivityRetryTaskBridge::class]));
        $role = \Mockery::mock(implode(', ', $interfaces));
        $versioned = [
            'prepareLocalActivityWithRetryPolicy' => [PortableLocalActivityPreparation::class, 'prepareWithRetryPolicy'],
            'recordLocalActivityRetryOutcome' => [app(LocalActivityExecutor::class), 'recordPortableRetryOutcome'],
            'controlLocalActivityWithRetryPolicy' => [PortableLocalActivityControl::class, 'pollWithRetryPolicy'],
            'heartbeatLocalActivityWithRetryPolicy' => [PortableLocalActivityControl::class, 'heartbeatWithRetryPolicy'],
            'recoverLocalActivityWithRetryPolicy' => [PortableLocalActivityPreparation::class, 'recoverWithRetryPolicy'],
        ];
        $versioned = array_replace($versioned, $overrides);
        $methods = [];
        foreach ($interfaces as $interface) {
            foreach ((new ReflectionClass($interface))->getMethods() as $method) {
                $methods[$method->name] = true;
            }
        }
        foreach (array_keys($methods) as $method) {
            $role->shouldReceive($method)->andReturnUsing($versioned[$method] ?? [$base, $method]);
        }
        $this->app->instance(WorkflowTaskBridge::class, $role);
    }

    public function test_real_preparation_retains_a_closed_original_v2_graph(): void
    {
        $task = $this->claim();
        $prepared = $this->local($task, 'prepare')->assertOk()->assertJsonPath('prepared', true)->json();
        $stored = WorkflowTask::query()->findOrFail($task['task_id']);
        $attempt = ActivityAttempt::query()->findOrFail($prepared['activity_attempt_id']);
        $receipt = $stored->payload['_server_prepared_local_activity_claims'][$attempt->id];
        $keys = array_keys($receipt);
        sort($keys);
        $expected = ['schema', 'claim', 'activity_execution_id', 'activity_attempt_id', 'worker_attempt_id',
            'local_activity_contract_version', 'retry_policy_snapshot', 'preparation_history_event_id', 'descriptor_fingerprint'];
        sort($expected);
        $this->assertSame($expected, $keys);
        $this->assertSame(2, $receipt['local_activity_contract_version']);
        $this->assertSame(2, $receipt['retry_policy_snapshot']['snapshot_version']);
        $this->assertSame($receipt['claim'], PreparedLocalRetryAdmission::originalClaim($stored, $attempt, $task['lease_owner'], $task['workflow_task_attempt']));
        $before = $this->state();
        $this->local($task, 'prepare')->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('activity_attempt_id', $attempt->id);
        $this->assertSame($before, $this->state());
    }

    public function test_supplied_override_and_other_report_errors_refuse_before_rows(): void
    {
        foreach ([null, true, '0', 0.0, -1, 9007199254740992] as $delay) {
            DB::enableQueryLog();
            $this->outcome(['task_id' => 'missing', 'lease_owner' => 'worker', 'workflow_task_attempt' => 1], 'absent',
                ['outcome' => 'failed', 'message' => 'failed', 'next_retry_delay_seconds' => $delay])
                ->assertStatus(422)->assertJsonPath('reason', 'invalid_activity_retry_delay');
            $this->assertNoAuthorityRows();
        }
        DB::enableQueryLog();
        $this->outcome(['task_id' => 'missing', 'lease_owner' => 'worker', 'workflow_task_attempt' => 1], 'absent',
            ['outcome' => 'failed', 'message' => [], 'next_retry_delay_seconds' => 0])
            ->assertStatus(409)->assertJsonPath('reason', 'invalid_local_activity_outcome');
        $this->assertNoAuthorityRows();
        $this->outcome(['task_id' => 'missing', 'lease_owner' => 'worker', 'workflow_task_attempt' => 1], 'absent',
            ['outcome' => 'failed', 'message' => []])->assertStatus(404)->assertJsonPath('reason', 'activity_attempt_not_found');
    }

    public function test_preparation_duplicate_cannot_recreate_missing_original_authority(): void
    {
        $task = $this->claim();
        $prepared = $this->local($task, 'prepare')->assertOk()->json();
        $stored = WorkflowTask::query()->findOrFail($task['task_id']);
        $payload = $stored->payload;
        unset($payload['_server_prepared_local_activity_claims'][$prepared['activity_attempt_id']]);
        $stored->forceFill(['payload' => $payload])->save();
        $before = $this->state();

        $this->local($task, 'prepare')->assertStatus(409)->assertJsonPath('reason', 'prepared_local_activity_not_supported');
        $this->assertSame($before, $this->state());
        $this->assertArrayNotHasKey($prepared['activity_attempt_id'], $stored->fresh()->payload['_server_prepared_local_activity_claims']);
    }

    public function test_outcome_duplicate_preserves_the_committed_decision_after_time_and_registration_change(): void
    {
        $task = $this->claim();
        $prepared = $this->local($task, 'prepare')->assertOk()->json();
        $this->travel(1)->seconds();
        $report = ['outcome' => 'failed', 'message' => 'again', 'next_retry_delay_seconds' => 2];
        $original = $this->outcome($task, $prepared['activity_attempt_id'], $report)->assertOk()
            ->assertJsonPath('recorded', true)->assertJsonPath('duplicate', false)->json();
        $this->assertSame('scheduled', $original['retry_disposition']['decision']);
        $before = $this->state();
        WorkerRegistration::query()->where('worker_id', 'retry-worker')->update(['status' => 'superseded']);
        $this->travel(20)->seconds();
        $duplicate = $this->outcome($task, $prepared['activity_attempt_id'], $report)->assertOk()->assertJsonPath('duplicate', true)->json();
        $this->assertSame($original['event_id'], $duplicate['event_id']);
        $this->assertSame($original['retry_disposition'], $duplicate['retry_disposition']);
        $this->assertSame($before, $this->state());
    }

    public function test_recovery_retains_the_recovering_claim_and_replays_after_a_following_attempt(): void
    {
        config(['workflows.v2.workflow_task_lease_seconds' => 10]);
        $descriptor = $this->descriptor(['start_to_close_timeout' => 100, 'schedule_to_close_timeout' => 300,
            'retry_policy' => ['contract_version' => 2, 'mode' => 'explicit', 'max_attempts' => 3,
                'backoff_seconds' => [0], 'maximum_interval_seconds' => 10]]);
        $task = $this->claim();
        $this->local($task, 'prepare', $descriptor)->assertOk();
        $this->travel(11)->seconds();
        $recovering = $this->poll()->assertOk()->json('task');
        $original = $this->local($recovering, 'recover', $descriptor)->assertOk()->assertJsonPath('recovered', true)
            ->assertJsonPath('duplicate', false)->assertJsonPath('callback_stop_state', 'unknown')->json();
        $this->assertSame('engine_interruption', $original['retry_disposition']['failure_source']);
        $stored = WorkflowTask::query()->findOrFail($recovering['task_id']);
        $receipt = $stored->payload['_server_prepared_local_activity_recoveries'][$original['event_id']];
        $this->assertSame(PreparedLocalRetryAdmission::RECOVERY_SCHEMA, $receipt['schema']);
        $this->assertSame($recovering['workflow_task_attempt'], $receipt['claim']['attempt']);
        $following = $this->poll()->assertOk()->json('task');
        $this->local($following, 'prepare', $descriptor, 'sdk-next')->assertOk()->assertJsonPath('attempt_number', 2);
        WorkerRegistration::query()->where('worker_id', 'retry-worker')->update(['status' => 'superseded']);
        $before = $this->state();
        $this->travel(20)->seconds();
        $duplicate = $this->local($recovering, 'recover', $descriptor)->assertOk()->assertJsonPath('duplicate', true)->json();
        $this->assertSame($original['event_id'], $duplicate['event_id']);
        $this->assertSame($original['retry_disposition'], $duplicate['retry_disposition']);
        $this->assertSame($before, $this->state());
        $event = WorkflowHistoryEvent::query()->findOrFail($original['event_id']);
        $eventPayload = $event->payload;
        $event->forceFill(['payload' => [...$eventPayload, 'sequence' => '1']])->save();
        $corrupt = $this->state();
        $this->local($recovering, 'recover', $descriptor)->assertStatus(409);
        $this->assertSame($corrupt, $this->state());
        $event->forceFill(['payload' => $eventPayload])->save();
        $stored = $stored->fresh();
        $payload = $stored->payload;
        unset($payload['_server_prepared_local_activity_recoveries'][$original['event_id']]);
        $stored->forceFill(['payload' => $payload])->save();
        $before = $this->state();
        $this->local($recovering, 'recover', $descriptor)->assertStatus(409);
        $this->assertSame($before, $this->state());
        $this->assertArrayNotHasKey($original['event_id'], $stored->fresh()->payload['_server_prepared_local_activity_recoveries']);
    }

    public function test_corrupt_original_contract_or_snapshot_cannot_acquire_outcome_authority(): void
    {
        $task = $this->claim();
        $prepared = $this->local($task, 'prepare')->assertOk()->json();
        $execution = ActivityExecution::query()->findOrFail($prepared['activity_execution_id']);
        $options = $execution->activity_options;
        $options['local_activity_contract_version'] = '2';
        $execution->forceFill(['activity_options' => $options])->save();
        $before = $this->state();
        $this->outcome($task, $prepared['activity_attempt_id'], ['outcome' => 'failed', 'message' => 'failed'])
            ->assertStatus(409)->assertJsonPath('reason', 'prepared_local_activity_not_supported');
        $this->assertSame($before, $this->state());
    }

    public function test_changed_original_receipts_refuse_without_repair(): void
    {
        $task = $this->claim();
        $prepared = $this->local($task, 'prepare')->assertOk()->json();
        $stored = WorkflowTask::query()->findOrFail($task['task_id']);
        $payload = $stored->payload;
        $attemptId = $prepared['activity_attempt_id'];
        $receipt = $payload['_server_prepared_local_activity_claims'][$attemptId];
        $mutations = [
            [...$receipt, 'local_activity_contract_version' => '2'],
            [...$receipt, 'activity_attempt_id' => 'foreign'],
            [...$receipt, 'preparation_history_event_id' => 'foreign'],
            [...$receipt, 'descriptor_fingerprint' => str_repeat('0', 64)],
            [...$receipt, 'unexpected' => true],
            [...$receipt, 'retry_policy_snapshot' => [...$receipt['retry_policy_snapshot'], 'max_attempts' => 4]],
            [...$receipt, 'claim' => [...$receipt['claim'], 'capabilities' => [PreparedLocalActivityPolicy::CAPABILITY]]],
            [...$receipt, 'claim' => [...$receipt['claim'], 'namespace' => 'foreign']],
        ];
        foreach ($mutations as $mutation) {
            $stored->forceFill(['payload' => [...$payload, '_server_prepared_local_activity_claims' => [$attemptId => $mutation]]])->save();
            $before = $this->state();
            $this->outcome($task, $attemptId, ['outcome' => 'failed', 'message' => 'failed'])
                ->assertStatus(409)->assertJsonPath('reason', 'prepared_local_activity_not_supported');
            $this->assertSame($before, $this->state());
        }
        $stored->forceFill(['payload' => $payload])->save();
        $this->outcome($task, $attemptId, ['outcome' => 'failed', 'message' => 'failed'])->assertOk()->assertJsonPath('recorded', true);
    }

    public function test_failed_post_write_preparation_binding_rolls_back_the_real_writer(): void
    {
        $task = $this->claim();
        $this->bindRetryRole(['prepareLocalActivityWithRetryPolicy' => static function (...$arguments): array {
            $reply = PortableLocalActivityPreparation::prepareWithRetryPolicy(...$arguments);
            $execution = ActivityExecution::query()->findOrFail($reply['activity_execution_id']);
            $execution->forceFill(['activity_options' => [...$execution->activity_options, 'local_activity_contract_version' => '2']])->save();

            return $reply;
        }]);
        $before = $this->state();
        $this->local($task, 'prepare')->assertStatus(409)->assertJsonPath('reason', 'prepared_local_activity_not_supported');
        $this->assertSame($before, $this->state());
    }

    public function test_failed_post_write_recovery_binding_rolls_back_decision_and_following_task(): void
    {
        config(['workflows.v2.workflow_task_lease_seconds' => 10]);
        $task = $this->claim();
        $this->local($task, 'prepare')->assertOk();
        $this->travel(11)->seconds();
        $recovering = $this->poll()->assertOk()->json('task');
        $this->bindRetryRole(['recoverLocalActivityWithRetryPolicy' => static function (...$arguments): array {
            $reply = PortableLocalActivityPreparation::recoverWithRetryPolicy(...$arguments);

            return [...$reply, 'event_id' => 'foreign'];
        }]);
        $before = $this->state();
        $this->local($recovering, 'recover')->assertStatus(409)->assertJsonPath('reason', 'prepared_local_activity_not_supported');
        $this->assertSame($before, $this->state());
    }

    public static function totalDeadlines(): array
    {
        return ['before original deadline' => [99], 'at original deadline' => [100]];
    }

    #[DataProvider('totalDeadlines')]
    public function test_total_deadline_preserves_selection_and_engine_timeout_precedence(int $seconds): void
    {
        config(['server.workers.stale_after_seconds' => 300]);
        $task = $this->claim();
        $preparation = $this->local($task, 'prepare', $this->descriptor([
            'start_to_close_timeout' => 100, 'schedule_to_close_timeout' => 100,
        ]));
        $this->assertSame(200, $preparation->status(), $preparation->getContent());
        $prepared = $preparation->json();
        $before = ActivityAttempt::query()->count();
        $this->travel($seconds)->seconds();
        $hosting = WorkflowTask::query()->findOrFail($task['task_id']);
        $attempt = ActivityAttempt::query()->findOrFail($prepared['activity_attempt_id']);
        $this->assertTrue(WorkerPollFence::isCurrent($hosting->payload['_server_workflow_claim']), 'The original registration must still be current.');
        $this->assertTrue($hosting->lease_expires_at->gt(now()), 'The hosting task lease must outlive the activity deadline sample.');
        $this->assertTrue($attempt->lease_expires_at->gt(now()), 'The callback lease must outlive the activity deadline sample.');
        $report = ['outcome' => 'failed', 'message' => 'caller failure', 'next_retry_delay_seconds' => 1];
        $response = $this->outcome($task, $prepared['activity_attempt_id'], $report);
        $this->assertSame(200, $response->status(), $response->getContent());
        $reply = $response->assertJsonPath('recorded', true)->assertJsonPath('created_task_ids', [])->json();
        $this->assertSame('terminal', $reply['retry_disposition']['decision']);
        $this->assertSame($seconds === 99 ? 'application' : 'engine_timeout', $reply['retry_disposition']['failure_source']);
        if ($seconds === 99) {
            $this->assertSame('retry_delay_exceeds_schedule_to_close', $reply['retry_disposition']['terminal_classification']);
        } else {
            $this->assertSame('schedule_to_close', $reply['retry_disposition']['timeout_kind']);
        }
        $this->assertSame($before, ActivityAttempt::query()->count());
        $this->assertSame(0, WorkflowHistoryEvent::query()->where('workflow_run_id', $task['run_id'])
            ->where('event_type', HistoryEventType::ActivityRetryScheduled)->count());
        if ($seconds === 99) {
            $this->travel(20)->seconds();
            WorkerRegistration::query()->where('worker_id', 'retry-worker')->update(['status' => 'superseded']);
            $before = $this->state();
            $duplicate = $this->outcome($task, $prepared['activity_attempt_id'], $report);
            $this->assertSame(200, $duplicate->status(), $duplicate->getContent());
            $duplicate->assertJsonPath('duplicate', true)->assertJsonPath('event_id', $reply['event_id']);
            $originalDecision = $reply['retry_disposition'];
            $duplicateDecision = $duplicate->json('retry_disposition');
            ksort($originalDecision);
            ksort($duplicateDecision);
            $this->assertSame($originalDecision, $duplicateDecision);
            $this->assertSame($before, $this->state());
        }
    }

    private function assertNoAuthorityRows(): void
    {
        $queries = array_filter(DB::getQueryLog(), static fn (array $row): bool =>
            preg_match('/\b(?:from|join|update|into)\s+(?:["`]?[A-Za-z0-9_]+["`]?\.)?["`]?(?:workflow_tasks|activity_attempts|activity_executions|workflow_history_events|workflow_worker_registrations)\b/i', $row['query']) === 1);
        DB::disableQueryLog();
        DB::flushQueryLog();
        $this->assertSame([], array_values($queries));
    }

    private function state(): array
    {
        $rows = [];
        foreach (['workflow_history_events', 'activity_attempts', 'activity_executions', 'workflow_tasks'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->map(static fn ($row): array => (array) $row)->all();
        }

        return $rows;
    }

    private function descriptor(array $overrides = []): array
    {
        return [...['type' => 'record_local_activity', 'activity_type' => 'opaque-local',
            'arguments' => Serializer::serializeWithCodec('avro', ['Ada']), 'payload_codec' => 'avro',
            'start_to_close_timeout' => 100, 'schedule_to_close_timeout' => 300,
            'retry_policy' => ['contract_version' => 2, 'mode' => 'explicit', 'max_attempts' => 3,
                'backoff_seconds' => [1], 'maximum_interval_seconds' => 10]], ...$overrides];
    }

    private function claim(): array
    {
        $this->withHeaders(['X-Namespace' => 'default', 'X-Durable-Workflow-Control-Plane-Version' => '2'])
            ->postJson('/api/workflows', ['workflow_type' => 'tests.external-greeting-workflow', 'task_queue' => 'retry-v2', 'input' => ['Ada']])->assertCreated();
        $this->withHeaders($this->headers())->postJson('/api/worker/register', ['worker_id' => 'retry-worker', 'task_queue' => 'retry-v2',
            'runtime' => 'php', 'supported_workflow_types' => ['tests.external-greeting-workflow'],
            'capabilities' => [CooperativeCancellationPolicy::CAPABILITY, PreparedLocalActivityPolicy::CAPABILITY, PreparedLocalRetryAdmission::CAPABILITY],
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(), 'max_concurrent_workflow_tasks' => 1,
            'process_metrics' => ['host' => 'test-worker', 'process_started_at' => now()->toISOString(), 'process_id' => 10]])->assertCreated();

        return $this->poll()->assertOk()->json('task');
    }

    private function poll(): TestResponse
    {
        return $this->withHeaders($this->headers())->postJson('/api/worker/workflow-tasks/poll',
            ['worker_id' => 'retry-worker', 'task_queue' => 'retry-v2', 'timeout_seconds' => 0]);
    }

    private function local(array $task, string $operation, ?array $descriptor = null, string $workerAttempt = 'sdk-original'): TestResponse
    {
        return $this->withHeaders($this->headers())->postJson("/api/worker/workflow-tasks/{$task['task_id']}/local-activities/{$operation}",
            ['lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'], 'sequence' => 1,
                'worker_attempt_id' => $workerAttempt, 'descriptor' => $descriptor ?? $this->descriptor()]);
    }

    private function outcome(array $task, string $attempt, array $report): TestResponse
    {
        return $this->postJson("/api/worker/workflow-tasks/{$task['task_id']}/local-activities/{$attempt}/outcome", [
            'lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'], 'report' => $report,
        ], $this->headers(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function headers(): array
    {
        return ['X-Namespace' => 'default', WorkerProtocol::HEADER => '1.20'];
    }
}
