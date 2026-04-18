<?php

namespace Tests\Feature;

use App\Models\SearchAttributeDefinition;
use App\Support\ControlPlaneProtocol;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowSchedule;

class ControlPlaneResourceSuccessContractTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createNamespace('default');
    }

    /**
     * @return array<string, array{
     *     case: string,
     *     method: string,
     *     path: string,
     *     body: array<string, mixed>,
     *     status: int,
     *     structure: array<int|string, mixed>,
     *     paths: array<string, mixed>
     * }>
     */
    public static function resourceSuccessProvider(): array
    {
        return [
            'namespaces.index' => [
                'case' => 'namespaces.index',
                'method' => 'get',
                'path' => '/api/namespaces',
                'body' => [],
                'status' => 200,
                'structure' => ['namespaces' => [['name', 'description', 'retention_days', 'status', 'created_at', 'updated_at']]],
                'paths' => ['namespaces.0.name' => 'default'],
            ],
            'namespaces.store' => [
                'case' => 'namespaces.store',
                'method' => 'post',
                'path' => '/api/namespaces',
                'body' => ['name' => 'contract-resource', 'description' => 'Contract resource', 'retention_days' => 14],
                'status' => 201,
                'structure' => ['name', 'description', 'retention_days', 'status', 'created_at'],
                'paths' => ['name' => 'contract-resource', 'status' => 'active'],
            ],
            'namespaces.show' => [
                'case' => 'namespaces.show',
                'method' => 'get',
                'path' => '/api/namespaces/default',
                'body' => [],
                'status' => 200,
                'structure' => ['name', 'description', 'retention_days', 'status', 'created_at', 'updated_at'],
                'paths' => ['name' => 'default'],
            ],
            'namespaces.update' => [
                'case' => 'namespaces.update',
                'method' => 'put',
                'path' => '/api/namespaces/default',
                'body' => ['description' => 'Updated namespace'],
                'status' => 200,
                'structure' => ['name', 'description', 'retention_days', 'status', 'updated_at'],
                'paths' => ['name' => 'default', 'description' => 'Updated namespace'],
            ],
            'search-attributes.index' => [
                'case' => 'search-attributes.index',
                'method' => 'get',
                'path' => '/api/search-attributes',
                'body' => [],
                'status' => 200,
                'structure' => ['system_attributes', 'custom_attributes'],
                'paths' => ['system_attributes.WorkflowId' => 'keyword'],
            ],
            'search-attributes.store' => [
                'case' => 'search-attributes.store',
                'method' => 'post',
                'path' => '/api/search-attributes',
                'body' => ['name' => 'ContractPriority', 'type' => 'int'],
                'status' => 201,
                'structure' => ['name', 'type', 'outcome'],
                'paths' => ['name' => 'ContractPriority', 'type' => 'int', 'outcome' => 'created'],
            ],
            'search-attributes.destroy' => [
                'case' => 'search-attributes.destroy',
                'method' => 'delete',
                'path' => '/api/search-attributes/Obsolete',
                'body' => [],
                'status' => 200,
                'structure' => ['name', 'outcome'],
                'paths' => ['name' => 'Obsolete', 'outcome' => 'deleted'],
            ],
            'workers.index' => [
                'case' => 'workers.index',
                'method' => 'get',
                'path' => '/api/workers',
                'body' => [],
                'status' => 200,
                'structure' => [
                    'workers' => [[
                        'worker_id',
                        'namespace',
                        'task_queue',
                        'runtime',
                        'sdk_version',
                        'build_id',
                        'supported_workflow_types',
                        'supported_activity_types',
                        'max_concurrent_workflow_tasks',
                        'max_concurrent_activity_tasks',
                        'status',
                        'last_heartbeat_at',
                        'registered_at',
                    ]],
                ],
                'paths' => ['workers.0.worker_id' => 'worker-contract'],
            ],
            'workers.show' => [
                'case' => 'workers.show',
                'method' => 'get',
                'path' => '/api/workers/worker-contract',
                'body' => [],
                'status' => 200,
                'structure' => [
                    'worker_id',
                    'namespace',
                    'task_queue',
                    'runtime',
                    'sdk_version',
                    'build_id',
                    'supported_workflow_types',
                    'supported_activity_types',
                    'max_concurrent_workflow_tasks',
                    'max_concurrent_activity_tasks',
                    'status',
                    'last_heartbeat_at',
                    'registered_at',
                    'updated_at',
                ],
                'paths' => ['worker_id' => 'worker-contract', 'runtime' => 'php'],
            ],
            'workers.destroy' => [
                'case' => 'workers.destroy',
                'method' => 'delete',
                'path' => '/api/workers/worker-contract',
                'body' => [],
                'status' => 200,
                'structure' => ['worker_id', 'outcome'],
                'paths' => ['worker_id' => 'worker-contract', 'outcome' => 'deregistered'],
            ],
            'schedules.index' => [
                'case' => 'schedules.index',
                'method' => 'get',
                'path' => '/api/schedules',
                'body' => [],
                'status' => 200,
                'structure' => ['schedules', 'next_page_token'],
                'paths' => ['schedules.0.schedule_id' => 'contract-schedule'],
            ],
            'schedules.store' => [
                'case' => 'schedules.store',
                'method' => 'post',
                'path' => '/api/schedules',
                'body' => [
                    'schedule_id' => 'contract-created',
                    'spec' => ['cron_expressions' => ['0 * * * *']],
                    'action' => ['workflow_type' => 'ContractWorkflow'],
                ],
                'status' => 201,
                'structure' => ['schedule_id', 'outcome'],
                'paths' => ['schedule_id' => 'contract-created', 'outcome' => 'created'],
            ],
            'schedules.show' => [
                'case' => 'schedules.show',
                'method' => 'get',
                'path' => '/api/schedules/contract-schedule',
                'body' => [],
                'status' => 200,
                'structure' => ['schedule_id', 'spec', 'action', 'overlap_policy', 'state', 'info'],
                'paths' => ['schedule_id' => 'contract-schedule', 'action.workflow_type' => 'ContractWorkflow'],
            ],
            'schedules.update' => [
                'case' => 'schedules.update',
                'method' => 'put',
                'path' => '/api/schedules/contract-schedule',
                'body' => ['note' => 'Updated contract schedule'],
                'status' => 200,
                'structure' => ['schedule_id', 'outcome'],
                'paths' => ['schedule_id' => 'contract-schedule', 'outcome' => 'updated'],
            ],
            'schedules.destroy' => [
                'case' => 'schedules.destroy',
                'method' => 'delete',
                'path' => '/api/schedules/contract-schedule',
                'body' => [],
                'status' => 200,
                'structure' => ['schedule_id', 'outcome'],
                'paths' => ['schedule_id' => 'contract-schedule', 'outcome' => 'deleted'],
            ],
            'schedules.pause' => [
                'case' => 'schedules.pause',
                'method' => 'post',
                'path' => '/api/schedules/contract-schedule/pause',
                'body' => ['note' => 'Pause via contract test'],
                'status' => 200,
                'structure' => ['schedule_id', 'outcome'],
                'paths' => ['schedule_id' => 'contract-schedule', 'outcome' => 'paused'],
            ],
            'schedules.resume' => [
                'case' => 'schedules.resume',
                'method' => 'post',
                'path' => '/api/schedules/contract-schedule/resume',
                'body' => ['note' => 'Resume via contract test'],
                'status' => 200,
                'structure' => ['schedule_id', 'outcome'],
                'paths' => ['schedule_id' => 'contract-schedule', 'outcome' => 'resumed'],
            ],
            'schedules.trigger_skipped' => [
                'case' => 'schedules.trigger_skipped',
                'method' => 'post',
                'path' => '/api/schedules/contract-trigger/trigger',
                'body' => [],
                'status' => 200,
                'structure' => ['schedule_id', 'outcome', 'reason'],
                'paths' => [
                    'schedule_id' => 'contract-trigger',
                    'outcome' => 'skipped',
                    'reason' => 'remaining_actions_exhausted',
                ],
            ],
            'schedules.backfill_empty' => [
                'case' => 'schedules.backfill_empty',
                'method' => 'post',
                'path' => '/api/schedules/contract-backfill/backfill',
                'body' => [
                    'start_time' => '2026-04-10T00:30:00Z',
                    'end_time' => '2026-04-10T00:45:00Z',
                ],
                'status' => 200,
                'structure' => ['schedule_id', 'outcome', 'fires_attempted', 'results'],
                'paths' => [
                    'schedule_id' => 'contract-backfill',
                    'outcome' => 'backfill_started',
                    'fires_attempted' => 0,
                    'results' => [],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<int|string, mixed>  $structure
     * @param  array<string, mixed>  $paths
     */
    #[DataProvider('resourceSuccessProvider')]
    public function test_resource_success_responses_use_control_plane_contract(
        string $case,
        string $method,
        string $path,
        array $body,
        int $status,
        array $structure,
        array $paths,
    ): void {
        $this->prepareResourceCase($case);

        $response = $this->sendJson($method, $path, $body, $this->apiHeaders());

        $response->assertStatus($status)
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertHeaderMissing(WorkerProtocol::HEADER)
            ->assertJsonMissingPath('protocol_version')
            ->assertJsonMissingPath('server_capabilities')
            ->assertJsonMissingPath('control_plane')
            ->assertJsonStructure($structure);

        foreach ($paths as $jsonPath => $expected) {
            $response->assertJsonPath($jsonPath, $expected);
        }
    }

    private function prepareResourceCase(string $case): void
    {
        if (str_starts_with($case, 'workers.')) {
            $this->registerWorker(
                workerId: 'worker-contract',
                taskQueue: 'contract-queue',
                supportedWorkflowTypes: ['ContractWorkflow'],
                supportedActivityTypes: ['ContractActivity'],
            );

            return;
        }

        if ($case === 'search-attributes.destroy') {
            SearchAttributeDefinition::create([
                'namespace' => 'default',
                'name' => 'Obsolete',
                'type' => 'keyword',
            ]);

            return;
        }

        if ($case === 'schedules.trigger_skipped') {
            WorkflowSchedule::create([
                'schedule_id' => 'contract-trigger',
                'namespace' => 'default',
                'spec' => ['cron_expressions' => ['0 * * * *'], 'timezone' => 'UTC'],
                'action' => ['workflow_type' => 'ContractWorkflow', 'task_queue' => 'contract-queue'],
                'overlap_policy' => 'skip',
                'status' => 'active',
                'remaining_actions' => 0,
            ]);

            return;
        }

        if ($case === 'schedules.backfill_empty') {
            WorkflowSchedule::create([
                'schedule_id' => 'contract-backfill',
                'namespace' => 'default',
                'spec' => ['cron_expressions' => ['0 0 * * *'], 'timezone' => 'UTC'],
                'action' => ['workflow_type' => 'ContractWorkflow', 'task_queue' => 'contract-queue'],
                'overlap_policy' => 'skip',
                'status' => 'active',
            ]);

            return;
        }

        if (str_starts_with($case, 'schedules.') && $case !== 'schedules.store') {
            $status = $case === 'schedules.resume' ? 'paused' : 'active';

            WorkflowSchedule::create([
                'schedule_id' => 'contract-schedule',
                'namespace' => 'default',
                'spec' => ['cron_expressions' => ['0 * * * *'], 'timezone' => 'UTC'],
                'action' => ['workflow_type' => 'ContractWorkflow', 'task_queue' => 'contract-queue'],
                'overlap_policy' => 'skip',
                'status' => $status,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    private function sendJson(string $method, string $path, array $body, array $headers): TestResponse
    {
        return match ($method) {
            'delete' => $this->deleteJson($path, $body, $headers),
            'get' => $this->getJson($path, $headers),
            'post' => $this->postJson($path, $body, $headers),
            'put' => $this->putJson($path, $body, $headers),
            default => throw new \InvalidArgumentException("Unsupported method: {$method}"),
        };
    }
}
