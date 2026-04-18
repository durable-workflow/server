<?php

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkerProtocolContractTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_worker_validation_errors_use_worker_protocol_contract_even_with_control_plane_header(): void
    {
        $this->withHeaders($this->workerHeaders() + [
            'X-Durable-Workflow-Control-Plane-Version' => '2',
        ])->postJson('/api/worker/register', [
            'worker_id' => 'py-worker-invalid',
        ])->assertStatus(422)
            ->assertHeader('X-Durable-Workflow-Protocol-Version', '1.0')
            ->assertHeaderMissing('X-Durable-Workflow-Control-Plane-Version')
            ->assertJsonPath('protocol_version', '1.0')
            ->assertJsonPath('reason', 'validation_failed')
            ->assertJsonPath('server_capabilities.workflow_task_poll_request_idempotency', true)
            ->assertJsonPath('validation_errors.task_queue.0', 'The task queue field is required.')
            ->assertJsonPath('validation_errors.runtime.0', 'The runtime field is required.')
            ->assertJsonMissingPath('control_plane');
    }

    public function test_worker_authentication_errors_use_worker_protocol_contract(): void
    {
        config([
            'server.auth.driver' => 'token',
            'server.auth.token' => 'worker-token',
        ]);

        $this->withHeaders($this->workerHeaders(withAuthorization: false))
            ->postJson('/api/worker/register', [
                'worker_id' => 'py-worker-auth',
                'task_queue' => 'default',
                'runtime' => 'python',
            ])->assertUnauthorized()
            ->assertHeader('X-Durable-Workflow-Protocol-Version', '1.0')
            ->assertHeaderMissing('X-Durable-Workflow-Control-Plane-Version')
            ->assertJsonPath('protocol_version', '1.0')
            ->assertJsonPath('reason', 'unauthorized')
            ->assertJsonPath('message', 'Invalid or missing authentication token.')
            ->assertJsonPath('server_capabilities.long_poll_timeout', 0)
            ->assertJsonMissingPath('control_plane');
    }

    public function test_worker_authorization_errors_use_worker_protocol_contract(): void
    {
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

        $this->withHeaders($this->workerHeaders(token: 'operator-token'))
            ->postJson('/api/worker/register', [
                'worker_id' => 'py-worker-wrong-role',
                'task_queue' => 'default',
                'runtime' => 'python',
            ])->assertForbidden()
            ->assertHeader('X-Durable-Workflow-Protocol-Version', '1.0')
            ->assertHeaderMissing('X-Durable-Workflow-Control-Plane-Version')
            ->assertJsonPath('protocol_version', '1.0')
            ->assertJsonPath('reason', 'forbidden')
            ->assertJsonPath('role', 'operator')
            ->assertJsonPath('allowed_roles.0', 'worker')
            ->assertJsonPath('server_capabilities.supported_workflow_task_commands.0', 'complete_workflow')
            ->assertJsonMissingPath('control_plane');
    }

    /**
     * @return array<string, string>
     */
    private function workerHeaders(string $token = 'worker-token', bool $withAuthorization = true): array
    {
        $headers = [
            'X-Namespace' => 'default',
            'X-Durable-Workflow-Protocol-Version' => '1.0',
        ];

        if ($withAuthorization) {
            $headers['Authorization'] = "Bearer {$token}";
        }

        return $headers;
    }
}
