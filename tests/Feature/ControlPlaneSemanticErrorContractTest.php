<?php

namespace Tests\Feature;

use App\Models\SearchAttributeDefinition;
use App\Support\ControlPlaneProtocol;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowSchedule;

class ControlPlaneSemanticErrorContractTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createNamespace('default');
    }

    public function test_namespace_duplicate_errors_use_control_plane_semantic_error_contract(): void
    {
        $response = $this->postJson('/api/namespaces', [
            'name' => 'Default',
        ], $this->controlPlaneHeadersWithWorkerProtocol());

        $this->assertControlPlaneSemanticError($response, 409, 'namespace_already_exists');
        $response->assertJsonPath('namespace', 'default');
    }

    public function test_schedule_duplicate_errors_use_control_plane_semantic_error_contract(): void
    {
        $this->createSchedule('existing-schedule');

        $response = $this->postJson('/api/schedules', [
            'schedule_id' => 'existing-schedule',
            'spec' => ['cron_expressions' => ['0 * * * *']],
            'action' => ['workflow_type' => 'AnotherWorkflow'],
        ], $this->controlPlaneHeadersWithWorkerProtocol());

        $this->assertControlPlaneSemanticError($response, 409, 'schedule_already_exists');
        $response->assertJsonPath('schedule_id', 'existing-schedule');
    }

    public function test_schedule_invalid_time_range_errors_use_control_plane_semantic_error_contract(): void
    {
        $this->createSchedule('backfill-contract');

        $response = $this->postJson('/api/schedules/backfill-contract/backfill', [
            'start_time' => '2026-04-18T10:00:00Z',
            'end_time' => '2026-04-18T09:00:00Z',
        ], $this->controlPlaneHeadersWithWorkerProtocol());

        $this->assertControlPlaneSemanticError($response, 422, 'invalid_time_range');
    }

    public function test_schedule_memo_limit_errors_use_control_plane_semantic_error_contract(): void
    {
        config(['server.limits.max_memo_bytes' => 50]);

        $response = $this->postJson('/api/schedules', [
            'schedule_id' => 'oversized-memo',
            'spec' => ['cron_expressions' => ['0 * * * *']],
            'action' => ['workflow_type' => 'MemoWorkflow'],
            'memo' => ['data' => str_repeat('x', 100)],
        ], $this->controlPlaneHeadersWithWorkerProtocol());

        $this->assertControlPlaneSemanticError($response, 422, 'memo_too_large');
        $response->assertJsonPath('limit', 50);
    }

    public function test_search_attribute_errors_use_control_plane_semantic_error_contract(): void
    {
        SearchAttributeDefinition::create([
            'namespace' => 'default',
            'name' => 'Priority',
            'type' => 'int',
        ]);

        $reserved = $this->postJson('/api/search-attributes', [
            'name' => 'WorkflowId',
            'type' => 'keyword',
        ], $this->controlPlaneHeadersWithWorkerProtocol());

        $this->assertControlPlaneSemanticError($reserved, 409, 'name_reserved');

        $duplicate = $this->postJson('/api/search-attributes', [
            'name' => 'Priority',
            'type' => 'keyword',
        ], $this->controlPlaneHeadersWithWorkerProtocol());

        $this->assertControlPlaneSemanticError($duplicate, 409, 'attribute_already_exists');
        $duplicate->assertJsonPath('name', 'Priority')
            ->assertJsonPath('type', 'int');

        $systemDelete = $this->deleteJson(
            '/api/search-attributes/WorkflowId',
            [],
            $this->controlPlaneHeadersWithWorkerProtocol(),
        );

        $this->assertControlPlaneSemanticError($systemDelete, 409, 'system_attribute');

        config(['server.limits.max_search_attributes' => 1]);

        $limit = $this->postJson('/api/search-attributes', [
            'name' => 'Region',
            'type' => 'keyword',
        ], $this->controlPlaneHeadersWithWorkerProtocol());

        $this->assertControlPlaneSemanticError($limit, 422, 'search_attribute_limit_reached');
        $limit->assertJsonPath('limit', 1);
    }

    private function createSchedule(string $scheduleId): WorkflowSchedule
    {
        return WorkflowSchedule::create([
            'schedule_id' => $scheduleId,
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['0 * * * *'], 'timezone' => 'UTC'],
            'action' => ['workflow_type' => 'ContractWorkflow'],
            'overlap_policy' => 'skip',
            'status' => 'active',
        ]);
    }

    private function assertControlPlaneSemanticError(TestResponse $response, int $status, string $reason): void
    {
        $response->assertStatus($status)
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertHeaderMissing(WorkerProtocol::HEADER)
            ->assertJsonPath('reason', $reason)
            ->assertJsonPath('message', static fn (mixed $message): bool => is_string($message) && $message !== '')
            ->assertJsonMissingPath('error')
            ->assertJsonMissingPath('protocol_version')
            ->assertJsonMissingPath('server_capabilities')
            ->assertJsonMissingPath('control_plane');
    }
}
