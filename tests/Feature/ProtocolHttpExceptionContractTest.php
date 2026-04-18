<?php

namespace Tests\Feature;

use App\Support\ControlPlaneProtocol;
use App\Support\WorkerProtocol;
use Tests\TestCase;

class ProtocolHttpExceptionContractTest extends TestCase
{
    public function test_control_plane_method_not_allowed_errors_use_control_plane_contract(): void
    {
        $this->patchJson('/api/schedules', [], [
            'X-Namespace' => 'default',
            ControlPlaneProtocol::HEADER => ControlPlaneProtocol::VERSION,
        ])->assertStatus(405)
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertHeaderMissing(WorkerProtocol::HEADER)
            ->assertJsonPath('reason', 'method_not_allowed')
            ->assertJsonPath('message', static fn (mixed $message): bool => is_string($message) && $message !== '')
            ->assertJsonMissingPath('protocol_version');
    }

    public function test_control_plane_not_found_errors_use_control_plane_contract(): void
    {
        $this->getJson('/api/schedules/unknown/subroute', [
            'X-Namespace' => 'default',
            ControlPlaneProtocol::HEADER => ControlPlaneProtocol::VERSION,
        ])->assertNotFound()
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertHeaderMissing(WorkerProtocol::HEADER)
            ->assertJsonPath('reason', 'not_found')
            ->assertJsonPath('message', static fn (mixed $message): bool => is_string($message) && $message !== '')
            ->assertJsonMissingPath('protocol_version');
    }

    public function test_worker_method_not_allowed_errors_use_worker_protocol_contract(): void
    {
        $this->getJson('/api/worker/register', [
            'X-Namespace' => 'default',
            WorkerProtocol::HEADER => WorkerProtocol::VERSION,
        ])->assertStatus(405)
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertHeaderMissing(ControlPlaneProtocol::HEADER)
            ->assertJsonPath('protocol_version', WorkerProtocol::VERSION)
            ->assertJsonPath('reason', 'method_not_allowed')
            ->assertJsonPath('message', static fn (mixed $message): bool => is_string($message) && $message !== '')
            ->assertJsonPath('server_capabilities.workflow_task_poll_request_idempotency', true)
            ->assertJsonMissingPath('control_plane');
    }

    public function test_worker_not_found_errors_use_worker_protocol_contract(): void
    {
        $this->postJson('/api/worker/unknown-route', [], [
            'X-Namespace' => 'default',
            WorkerProtocol::HEADER => WorkerProtocol::VERSION,
        ])->assertNotFound()
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertHeaderMissing(ControlPlaneProtocol::HEADER)
            ->assertJsonPath('protocol_version', WorkerProtocol::VERSION)
            ->assertJsonPath('reason', 'not_found')
            ->assertJsonPath('message', static fn (mixed $message): bool => is_string($message) && $message !== '')
            ->assertJsonPath('server_capabilities.workflow_task_poll_request_idempotency', true)
            ->assertJsonMissingPath('control_plane');
    }
}
