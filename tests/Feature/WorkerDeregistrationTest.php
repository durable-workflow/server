<?php

namespace Tests\Feature;

use App\Support\ControlPlaneProtocol;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\Fixtures\ResourceAwareAuthProvider;
use Tests\TestCase;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowTask;

class WorkerDeregistrationTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'server.auth.driver' => 'token',
            'server.auth.token' => null,
            'server.auth.role_tokens' => [
                'worker' => 'worker-token',
                'operator' => 'operator-token',
                'admin' => 'admin-token',
            ],
            'server.auth.backward_compatible' => true,
        ]);

        $this->createNamespace('default', 'Default namespace');
    }

    public function test_namespace_scoped_worker_credential_can_deregister_its_registration(): void
    {
        $this->createNamespace('other', 'Other namespace');
        $this->registerWorker('worker-a', 'queue', 'default');

        ResourceAwareAuthProvider::reset();
        config(['server.auth.provider' => ResourceAwareAuthProvider::class]);

        $this->withHeaders($this->resourceWorkerHeaders('other', 'default'))
            ->deleteJson('/api/worker/registrations/worker-a')
            ->assertForbidden()
            ->assertJsonPath('reason', 'forbidden');

        $this->withHeaders($this->resourceWorkerHeaders('other', 'other'))
            ->deleteJson('/api/worker/registrations/worker-a')
            ->assertNotFound()
            ->assertJsonPath('reason', 'worker_not_found');

        $this->assertDatabaseHas('workflow_worker_registrations', [
            'worker_id' => 'worker-a',
            'namespace' => 'default',
        ]);

        $this->withHeaders($this->resourceWorkerHeaders('default', 'default'))
            ->deleteJson('/api/worker/registrations/worker-a')
            ->assertOk()
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertHeaderMissing(ControlPlaneProtocol::HEADER)
            ->assertJsonPath('worker_id', 'worker-a')
            ->assertJsonPath('outcome', 'deregistered')
            ->assertJsonPath('recovered_workflow_task_count', 0);

        $this->assertDatabaseMissing('workflow_worker_registrations', [
            'worker_id' => 'worker-a',
            'namespace' => 'default',
        ]);
    }

    public function test_control_credentials_cannot_use_worker_route_and_management_authorization_is_unchanged(): void
    {
        $this->registerWorker('worker-role-boundary', 'queue');

        foreach (['operator-token', 'admin-token'] as $token) {
            $this->withHeaders($this->workerHeadersFor($token))
                ->deleteJson('/api/worker/registrations/worker-role-boundary')
                ->assertForbidden()
                ->assertJsonPath('reason', 'forbidden');
        }

        $this->withHeaders($this->controlHeaders('worker-token'))
            ->deleteJson('/api/workers/worker-role-boundary')
            ->assertForbidden()
            ->assertJsonPath('reason', 'forbidden');

        $this->withHeaders($this->controlHeaders('admin-token'))
            ->deleteJson('/api/workers/worker-role-boundary')
            ->assertOk()
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertHeaderMissing(WorkerProtocol::HEADER)
            ->assertJsonPath('outcome', 'deregistered');
    }

    public function test_worker_deregistration_recovers_leased_workflow_task(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes([
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);

        $this->withHeaders($this->controlHeaders('admin-token'))
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-worker-plane-handoff',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'handoff-queue',
                'input' => ['Ada'],
            ])
            ->assertCreated();

        $this->registerWorker(
            workerId: 'handoff-worker',
            taskQueue: 'handoff-queue',
            supportedWorkflowTypes: ['tests.external-greeting-workflow'],
        );

        $poll = $this->withHeaders($this->workerHeadersFor('worker-token'))
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'handoff-worker',
                'task_queue' => 'handoff-queue',
                'timeout_seconds' => 0,
            ]);

        $poll->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-worker-plane-handoff')
            ->assertJsonPath('task.lease_owner', 'handoff-worker');

        $taskId = (string) $poll->json('task.task_id');

        $this->withHeaders($this->workerHeadersFor('worker-token'))
            ->deleteJson('/api/worker/registrations/handoff-worker')
            ->assertOk()
            ->assertJsonPath('outcome', 'deregistered')
            ->assertJsonPath('recovered_workflow_task_count', 1);

        self::assertFalse(WorkflowTask::query()
            ->whereKey($taskId)
            ->where('status', TaskStatus::Leased->value)
            ->where('lease_owner', 'handoff-worker')
            ->exists());
    }

    public function test_worker_deregistration_removes_only_its_compatibility_heartbeats(): void
    {
        $this->registerWorker('worker-a', 'queue');
        $this->registerWorker('worker-b', 'queue');
        $now = now();

        foreach ([
            ['worker_id' => 'worker-a', 'scope_key' => 'default:queue:a', 'supported' => ['build-v1']],
            ['worker_id' => 'worker-b', 'scope_key' => 'default:queue:b', 'supported' => ['build-v2']],
        ] as $heartbeat) {
            DB::table('workflow_worker_compatibility_heartbeats')->insert(array_replace($heartbeat, [
                'namespace' => 'default',
                'queue' => 'queue',
                'supported' => json_encode($heartbeat['supported'], JSON_THROW_ON_ERROR),
                'recorded_at' => $now,
                'expires_at' => $now->copy()->addMinute(),
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }

        $this->withHeaders($this->workerHeadersFor('worker-token'))
            ->deleteJson('/api/worker/registrations/worker-a')
            ->assertOk()
            ->assertJsonPath('outcome', 'deregistered');

        self::assertFalse(DB::table('workflow_worker_compatibility_heartbeats')
            ->where('worker_id', 'worker-a')
            ->where('namespace', 'default')
            ->exists());
        self::assertTrue(DB::table('workflow_worker_compatibility_heartbeats')
            ->where('worker_id', 'worker-b')
            ->where('namespace', 'default')
            ->exists());
    }

    public function test_worker_deregistration_returns_established_not_found_response(): void
    {
        $this->withHeaders($this->workerHeadersFor('worker-token'))
            ->deleteJson('/api/worker/registrations/unknown-worker')
            ->assertNotFound()
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertJsonPath('reason', 'worker_not_found')
            ->assertJsonPath('message', 'Worker [unknown-worker] not found in namespace [default].');
    }

    public function test_worker_deregistration_is_blocked_until_workflow_bootstrap_is_ready(): void
    {
        $this->registerWorker('bootstrap-worker', 'queue');

        DB::table('migrations')
            ->where('migration', '2026_04_21_000300_add_workflow_definition_fingerprints_to_worker_registrations')
            ->delete();

        $this->withHeaders($this->workerHeadersFor('worker-token'))
            ->deleteJson('/api/worker/registrations/bootstrap-worker')
            ->assertStatus(503)
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertJsonPath('reason', 'workflow_v2_blocked');

        $this->assertDatabaseHas('workflow_worker_registrations', [
            'worker_id' => 'bootstrap-worker',
            'namespace' => 'default',
        ]);
    }

    /** @return array<string, string> */
    private function workerHeadersFor(string $token, string $namespace = 'default'): array
    {
        return [
            'Authorization' => "Bearer {$token}",
            'X-Namespace' => $namespace,
            WorkerProtocol::HEADER => WorkerProtocol::VERSION,
        ];
    }

    /** @return array<string, string> */
    private function controlHeaders(string $token, string $namespace = 'default'): array
    {
        return [
            'Authorization' => "Bearer {$token}",
            'X-Namespace' => $namespace,
            ControlPlaneProtocol::HEADER => ControlPlaneProtocol::VERSION,
        ];
    }

    /** @return array<string, string> */
    private function resourceWorkerHeaders(string $allowedNamespace, string $requestedNamespace): array
    {
        return [
            'X-Test-Subject' => "worker:{$allowedNamespace}",
            'X-Test-Roles' => 'worker',
            'X-Test-Allow-Namespace' => $allowedNamespace,
            'X-Namespace' => $requestedNamespace,
            WorkerProtocol::HEADER => WorkerProtocol::VERSION,
        ];
    }
}
