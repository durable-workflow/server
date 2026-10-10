<?php

namespace Tests\Feature;

use App\Contracts\AuthProvider;
use App\Models\WorkerRegistration;
use App\Models\WorkerRegistrationIncarnation;
use App\Support\BackendUnavailable;
use App\Support\FencedWorkerDeregistration;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Feature\Concerns\StoragePressureFixture;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\Fixtures\ResourceAwareAuthProvider;
use Tests\Support\OpenApiSchema;
use Tests\TestCase;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowTask;

class FencedWorkerDeregistrationTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;
    use StoragePressureFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'server.auth.driver' => 'token',
            'server.auth.token' => null,
            'server.auth.role_tokens' => ['worker' => 'worker-token', 'admin' => 'admin-token'],
            'server.auth.backward_compatible' => true,
        ]);
        $this->createNamespace('default', 'Default namespace');
    }

    public function test_original_receipt_replays_without_deleting_replacement(): void
    {
        $this->registerFencedWorker('worker-a');
        $original = $this->token('worker-a');
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $original);

        $first = $this->withHeaders($this->headers())
            ->postJson('/api/worker/registrations/worker-a/deregister', ['registration_token' => $original]);
        $first->assertOk()->assertJsonPath('outcome', 'deregistered')
            ->assertJsonPath('registration_token', $original);
        OpenApiSchema::fromFile(base_path('resources/platform-protocol-specs/worker-protocol-api.openapi.yaml'))
            ->assertReferenceMatches('#/components/schemas/FencedWorkerDeregistrationResult', json_decode($first->getContent()));
        $this->assertDatabaseMissing('workflow_worker_registrations', ['worker_id' => 'worker-a']);

        $this->registerFencedWorker('worker-a');
        $replacement = $this->token('worker-a');
        self::assertNotSame($original, $replacement);
        $replay = $this->withHeaders($this->headers())
            ->postJson('/api/worker/registrations/worker-a/deregister', ['registration_token' => $original]);
        $replay->assertOk();
        foreach (['worker_id', 'registration_token', 'outcome', 'recovered_workflow_task_count'] as $field) {
            self::assertSame($first->json($field), $replay->json($field));
        }
        $this->assertDatabaseHas('workflow_worker_registrations', [
            'worker_id' => 'worker-a', 'registration_token' => $replacement, 'status' => 'active',
        ]);
    }

    public function test_superseded_incarnation_cannot_deregister_replacement(): void
    {
        $this->registerFencedWorker('worker-a');
        $original = $this->token('worker-a');
        $this->registerFencedWorker('worker-a');
        $replacement = $this->token('worker-a');

        $this->withHeaders($this->headers())
            ->postJson('/api/worker/registrations/worker-a/deregister', ['registration_token' => $original])
            ->assertConflict()->assertJsonPath('reason', 'worker_registration_lost_authority');
        $this->assertDatabaseHas('workflow_worker_registrations', [
            'worker_id' => 'worker-a', 'registration_token' => $replacement, 'status' => 'active',
        ]);
    }

    public function test_token_is_bound_to_worker_and_namespace(): void
    {
        $this->createNamespace('other', 'Other namespace');
        $this->registerFencedWorker('worker-a');
        $this->registerFencedWorker('worker-b');
        $token = $this->token('worker-a');

        foreach ([['worker-b', 'default'], ['worker-a', 'other']] as [$workerId, $namespace]) {
            $this->withHeaders($this->headers($namespace))
                ->postJson('/api/worker/registrations/'.$workerId.'/deregister', ['registration_token' => $token])
                ->assertNotFound()->assertJsonPath('reason', 'worker_registration_token_not_found');
        }
        $this->assertDatabaseCount('workflow_worker_registrations', 2);
    }

    public function test_expired_receipt_cannot_acknowledge_or_delete_replacement(): void
    {
        $this->registerFencedWorker('worker-a');
        $token = $this->token('worker-a');
        $this->withHeaders($this->headers())
            ->postJson('/api/worker/registrations/worker-a/deregister', ['registration_token' => $token])
            ->assertOk();
        $this->travel(WorkerRegistrationIncarnation::RECEIPT_RETENTION_SECONDS + 1)->seconds();
        $this->registerFencedWorker('worker-a');
        $replacement = $this->token('worker-a');

        $this->withHeaders($this->headers())
            ->postJson('/api/worker/registrations/worker-a/deregister', ['registration_token' => $token])
            ->assertStatus(410)->assertJsonPath('reason', 'worker_registration_receipt_expired');
        $this->assertDatabaseHas('workflow_worker_registrations', [
            'worker_id' => 'worker-a', 'registration_token' => $replacement, 'status' => 'active',
        ]);
    }

    public function test_heartbeat_keeps_the_original_incarnation(): void
    {
        $this->registerFencedWorker('worker-a');
        $token = $this->token('worker-a');
        $this->withHeaders($this->headers())->postJson('/api/worker/heartbeat', ['worker_id' => 'worker-a'])
            ->assertOk();
        self::assertSame($token, $this->token('worker-a'));
        $this->assertDatabaseCount('workflow_worker_registration_incarnations', 1);
    }

    public function test_control_role_cannot_use_the_fenced_worker_route(): void
    {
        $this->registerFencedWorker('worker-a');
        $token = $this->token('worker-a');
        $headers = $this->headers();
        $headers['Authorization'] = 'Bearer admin-token';
        $this->withHeaders($headers)->postJson('/api/worker/registrations/worker-a/deregister', ['registration_token' => $token])
            ->assertForbidden();
        $this->assertDatabaseHas('workflow_worker_registrations', ['worker_id' => 'worker-a', 'registration_token' => $token]);
    }

    public function test_namespace_scoped_provider_must_authorize_the_target(): void
    {
        $this->registerFencedWorker('worker-a');
        $token = $this->token('worker-a');
        ResourceAwareAuthProvider::reset();
        config(['server.auth.provider' => ResourceAwareAuthProvider::class]);
        $this->app->forgetInstance(AuthProvider::class);
        $headers = $this->headers();
        $headers['X-Test-Subject'] = 'namespace-worker';
        $headers['X-Test-Roles'] = 'worker';
        $headers['X-Test-Allow-Namespace'] = 'other';
        $this->withHeaders($headers)->postJson('/api/worker/registrations/worker-a/deregister', ['registration_token' => $token])
            ->assertForbidden();
        $this->assertDatabaseHas('workflow_worker_registrations', ['worker_id' => 'worker-a', 'registration_token' => $token]);
    }

    public function test_new_migration_is_required_before_fenced_requests(): void
    {
        $this->registerFencedWorker('worker-a');
        $token = $this->token('worker-a');
        DB::table('migrations')->where('migration', '2026_10_10_000100_add_fenced_worker_deregistration')->delete();
        $this->withHeaders($this->headers())->postJson('/api/worker/registrations/worker-a/deregister', ['registration_token' => $token])
            ->assertStatus(503)->assertJsonPath('reason', 'workflow_v2_blocked');
        $this->assertDatabaseHas('workflow_worker_registrations', ['worker_id' => 'worker-a', 'registration_token' => $token]);
        $this->artisan('server:bootstrap', ['--force' => true])->assertSuccessful();
        $this->withHeaders($this->headers())->postJson('/api/worker/registrations/worker-a/deregister', ['registration_token' => $token])
            ->assertOk();
    }

    public function test_draining_allows_fenced_handoff_but_fenced_storage_does_not(): void
    {
        $this->registerFencedWorker('worker-a');
        $token = $this->token('worker-a');
        $this->configureStoragePressure('fenced');
        try {
            $this->withHeaders($this->headers())->postJson('/api/worker/registrations/worker-a/deregister', ['registration_token' => $token])
                ->assertStatus(503)->assertJsonPath('request_admitted', false);
            $this->assertDatabaseHas('workflow_worker_registrations', ['worker_id' => 'worker-a', 'registration_token' => $token]);
            $this->observeStoragePressure('draining');
            $this->withHeaders($this->headers())->postJson('/api/worker/registrations/worker-a/deregister', ['registration_token' => $token])
                ->assertOk();
        } finally {
            $this->removeStoragePressure();
        }
    }

    public function test_early_backend_response_retains_registration_identity(): void
    {
        $request = Request::create('/api/worker/registrations/worker-a/deregister', 'POST', ['registration_token' => str_repeat('a', 32)]);
        $request->headers->set(WorkerProtocol::HEADER, WorkerProtocol::VERSION);
        $route = new Route('POST', 'api/worker/registrations/{workerId}/deregister', fn () => null);
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);
        $response = BackendUnavailable::workerResponse($request);
        self::assertNotNull($response);
        $body = $response->getData(true);
        self::assertSame('deregister_worker', $body['operation']);
        self::assertSame('worker-a', $body['worker_id']);
        self::assertSame(str_repeat('a', 32), $body['registration_token']);
        self::assertTrue($body['retryable']);
        OpenApiSchema::fromFile(base_path('resources/platform-protocol-specs/worker-protocol-api.openapi.yaml'))
            ->assertReferenceMatches('#/components/schemas/FencedWorkerDeregistrationUnavailable', $response->getData());
        $request->merge(['registration_token' => 'invalid']);
        self::assertFalse(BackendUnavailable::workerResponse($request)->getData(true)['retryable']);
    }

    public function test_independent_database_handles_cannot_claim_atomic_fencing(): void
    {
        $this->registerFencedWorker('worker-a');
        $token = $this->token('worker-a');
        config(['workflows.storage.connection' => 'separate-workflow-database']);
        self::assertFalse(FencedWorkerDeregistration::available());
        $result = app(FencedWorkerDeregistration::class)->complete(new Request, 'default', 'worker-a', $token);
        self::assertSame(412, $result['status']);
        self::assertSame('worker_registration_transaction_boundary_unavailable', $result['body']['reason']);
        $this->assertDatabaseHas('workflow_worker_registrations', ['worker_id' => 'worker-a', 'registration_token' => $token]);
    }

    public function test_recovery_is_atomic_and_stale_completion_cannot_publish(): void
    {
        $task = $this->leaseWorkflow();
        $token = $this->token('worker-a');
        $this->withHeaders($this->headers())->postJson('/api/worker/registrations/worker-a/deregister', ['registration_token' => $token])
            ->assertOk()->assertJsonPath('recovered_workflow_task_count', 1);
        self::assertFalse(WorkflowTask::query()->whereKey($task['task_id'])
            ->where('status', TaskStatus::Leased->value)->where('lease_owner', 'worker-a')->exists());
        $historyCount = DB::table('workflow_history_events')->count();
        $this->withHeaders($this->headers())->postJson('/api/worker/workflow-tasks/'.$task['task_id'].'/complete', [
            'lease_owner' => 'worker-a', 'workflow_task_attempt' => $task['workflow_task_attempt'],
            'commands' => [['type' => 'complete_workflow', 'result' => ['stale' => true]]],
        ])->assertConflict();
        self::assertSame($historyCount, DB::table('workflow_history_events')->count());
        $this->withHeaders($this->headers())->postJson('/api/worker/registrations/worker-a/deregister', ['registration_token' => $token])
            ->assertOk()->assertJsonPath('recovered_workflow_task_count', 1);
        self::assertSame($historyCount, DB::table('workflow_history_events')->count());
    }

    public function test_failure_inside_repair_rolls_back_registration_task_and_receipt(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            self::markTestSkipped('SQLite trigger fault injection; cross-database rollback is covered by shared HTTP qualification.');
        }
        $task = $this->leaseWorkflow();
        $token = $this->token('worker-a');
        $before = WorkflowTask::query()->findOrFail($task['task_id'])->getRawOriginal();
        $historyCount = DB::table('workflow_history_events')->count();
        DB::unprepared("CREATE TRIGGER reject_shutdown_repair BEFORE INSERT ON workflow_commands BEGIN SELECT RAISE(ABORT, 'shutdown repair rejected'); END");
        try {
            $this->withHeaders($this->headers())->postJson('/api/worker/registrations/worker-a/deregister', ['registration_token' => $token])
                ->assertStatus(500);
        } finally {
            DB::unprepared('DROP TRIGGER reject_shutdown_repair');
        }
        self::assertSame($before, WorkflowTask::query()->findOrFail($task['task_id'])->getRawOriginal());
        self::assertSame($historyCount, DB::table('workflow_history_events')->count());
        $this->assertDatabaseHas('workflow_worker_registrations', ['worker_id' => 'worker-a', 'status' => 'active', 'registration_token' => $token]);
        $this->assertDatabaseHas('workflow_worker_registration_incarnations', ['token' => $token, 'status' => 'active', 'finished_at' => null]);
        $this->withHeaders($this->headers())->postJson('/api/worker/registrations/worker-a/deregister', ['registration_token' => $token])
            ->assertOk()->assertJsonPath('recovered_workflow_task_count', 1);
    }

    public function test_normal_maintenance_prunes_expired_receipts_and_preserves_active_incarnations(): void
    {
        $this->registerFencedWorker('worker-a');
        $original = $this->token('worker-a');
        $this->withHeaders($this->headers())->postJson('/api/worker/registrations/worker-a/deregister', ['registration_token' => $original])
            ->assertOk();
        $this->registerFencedWorker('worker-b');
        $active = $this->token('worker-b');
        $this->travel(601)->seconds();
        $this->artisan('history:prune', ['--limit' => 100])->assertSuccessful();
        $this->assertDatabaseMissing('workflow_worker_registration_incarnations', ['token' => $original]);
        $this->assertDatabaseHas('workflow_worker_registration_incarnations', ['token' => $active, 'status' => 'active']);
        $this->withHeaders($this->headers())->postJson('/api/worker/registrations/worker-a/deregister', ['registration_token' => $original])
            ->assertNotFound()->assertJsonPath('reason', 'worker_registration_token_not_found');
    }

    /** @return array<string, mixed> */
    private function leaseWorkflow(): array
    {
        Queue::fake();
        $this->configureWorkflowTypes(['tests.external-greeting-workflow' => ExternalGreetingWorkflow::class]);
        $this->withHeaders(['Authorization' => 'Bearer admin-token', 'X-Namespace' => 'default', 'X-Durable-Workflow-Control-Plane-Version' => '2'])
            ->postJson('/api/workflows', ['workflow_id' => 'fenced-shutdown-workflow',
                'workflow_type' => 'tests.external-greeting-workflow', 'task_queue' => 'queue', 'input' => ['Ada']])
            ->assertCreated();
        $this->registerFencedWorker('worker-a');
        $poll = $this->withHeaders($this->headers())->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'worker-a', 'task_queue' => 'queue', 'timeout_seconds' => 0,
        ])->assertOk()->assertJsonPath('task.workflow_id', 'fenced-shutdown-workflow');

        return $poll->json('task');
    }

    private function token(string $workerId): string
    {
        return (string) WorkerRegistration::query()->where('namespace', 'default')
            ->where('worker_id', $workerId)->value('registration_token');
    }

    private function registerFencedWorker(string $workerId): void
    {
        $this->withHeaders($this->headers())->postJson('/api/worker/register', [
            'worker_id' => $workerId,
            'task_queue' => 'queue',
            'runtime' => 'php',
            'sdk_version' => '2.2.6',
            'supported_workflow_types' => ['tests.external-greeting-workflow'],
            'supported_activity_types' => [],
            'capabilities' => [],
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
        ])->assertCreated()->assertJsonPath('registered', true);
    }

    /** @return array<string, string> */
    private function headers(string $namespace = 'default'): array
    {
        return ['Authorization' => 'Bearer worker-token', 'X-Namespace' => $namespace,
            WorkerProtocol::HEADER => WorkerProtocol::VERSION];
    }
}
