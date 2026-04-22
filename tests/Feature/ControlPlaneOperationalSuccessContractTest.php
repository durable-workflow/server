<?php

namespace Tests\Feature;

use App\Support\ControlPlaneProtocol;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;

class ControlPlaneOperationalSuccessContractTest extends TestCase
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
     *     method: string,
     *     path: string,
     *     body: array<string, mixed>,
     *     structure: array<int|string, mixed>
     * }>
     */
    public static function operationalSuccessProvider(): array
    {
        return [
            'task-queues.index' => [
                'method' => 'get',
                'path' => '/api/task-queues',
                'body' => [],
                'structure' => [
                    'namespace',
                    'task_queues',
                ],
            ],
            'task-queues.show_empty_queue' => [
                'method' => 'get',
                'path' => '/api/task-queues/empty-queue',
                'body' => [],
                'structure' => [
                    'name',
                    'pollers',
                    'stats' => [
                        'approximate_backlog_count',
                        'workflow_tasks' => [
                            'ready_count',
                            'leased_count',
                            'expired_lease_count',
                        ],
                        'activity_tasks' => [
                            'ready_count',
                            'leased_count',
                            'expired_lease_count',
                        ],
                        'pollers' => [
                            'active_count',
                            'stale_count',
                        ],
                    ],
                    'current_leases',
                    'repair' => [
                        'candidates',
                        'dispatch_failed',
                        'expired_leases',
                        'dispatch_overdue',
                        'needs_attention',
                        'policy' => [
                            'redispatch_after_seconds',
                        ],
                    ],
                ],
            ],
            'task-queues.build_ids_empty_queue' => [
                'method' => 'get',
                'path' => '/api/task-queues/empty-queue/build-ids',
                'body' => [],
                'structure' => [
                    'namespace',
                    'task_queue',
                    'stale_after_seconds',
                    'build_ids',
                ],
            ],
            'system.metrics_empty' => [
                'method' => 'get',
                'path' => '/api/system/metrics',
                'body' => [],
                'structure' => [
                    'generated_at',
                    'namespace',
                    'metrics' => [
                        'dw_workflow_task_consecutive_failures' => [
                            'max_consecutive_failures',
                            'failed_task_count',
                            'workflow_type_count',
                            'workflow_type_limit',
                            'workflow_types_truncated',
                            'suppressed_workflow_type_count',
                            'suppressed_failed_task_count',
                            'label_cardinality_policy',
                            'by_workflow_type',
                        ],
                        'dw_projection_drift_total' => [
                            'total',
                            'table_count',
                            'tables_with_drift',
                            'scope',
                            'label_cardinality_policy',
                            'by_table',
                        ],
                    ],
                    'cardinality' => [
                        'metric_label_sets',
                    ],
                ],
            ],
            'system.repair_status' => [
                'method' => 'get',
                'path' => '/api/system/repair',
                'body' => [],
                'structure' => [
                    'policy' => [
                        'redispatch_after_seconds',
                        'loop_throttle_seconds',
                        'scan_limit',
                        'scan_strategy',
                        'failure_backoff_max_seconds',
                        'failure_backoff_strategy',
                    ],
                    'candidates' => [
                        'existing_task_candidates',
                        'missing_task_candidates',
                        'total_candidates',
                        'scan_limit',
                        'scan_strategy',
                    ],
                ],
            ],
            'system.repair_pass_empty' => [
                'method' => 'post',
                'path' => '/api/system/repair/pass',
                'body' => [],
                'structure' => [
                    'throttled',
                    'selected_existing_task_candidates',
                    'selected_missing_task_candidates',
                    'selected_total_candidates',
                    'repaired_existing_tasks',
                    'repaired_missing_tasks',
                    'dispatched_tasks',
                    'selected_command_contract_candidates',
                    'backfilled_command_contracts',
                    'command_contract_backfill_unavailable',
                    'existing_task_failures',
                    'missing_run_failures',
                    'command_contract_failures',
                ],
            ],
            'system.activity_timeout_status' => [
                'method' => 'get',
                'path' => '/api/system/activity-timeouts',
                'body' => [],
                'structure' => [
                    'expired_count',
                    'expired_execution_ids',
                    'scan_limit',
                    'scan_pressure',
                ],
            ],
            'system.activity_timeout_pass_empty' => [
                'method' => 'post',
                'path' => '/api/system/activity-timeouts/pass',
                'body' => [],
                'structure' => [
                    'processed',
                    'enforced',
                    'skipped',
                    'failed',
                    'results',
                ],
            ],
            'system.retention_status' => [
                'method' => 'get',
                'path' => '/api/system/retention',
                'body' => [],
                'structure' => [
                    'namespace',
                    'retention_days',
                    'cutoff',
                    'expired_run_count',
                    'expired_run_ids',
                    'scan_limit',
                    'scan_pressure',
                ],
            ],
            'system.retention_pass_empty' => [
                'method' => 'post',
                'path' => '/api/system/retention/pass',
                'body' => [],
                'structure' => [
                    'processed',
                    'pruned',
                    'skipped',
                    'failed',
                    'results',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<int|string, mixed>  $structure
     */
    #[DataProvider('operationalSuccessProvider')]
    public function test_operational_success_responses_use_control_plane_contract(
        string $method,
        string $path,
        array $body,
        array $structure,
    ): void {
        $response = $this->sendJson($method, $path, $body, $this->controlPlaneHeadersWithWorkerProtocol());

        $response->assertOk()
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertHeaderMissing(WorkerProtocol::HEADER)
            ->assertJsonMissingPath('protocol_version')
            ->assertJsonMissingPath('server_capabilities')
            ->assertJsonMissingPath('control_plane')
            ->assertJsonStructure($structure);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    private function sendJson(string $method, string $path, array $body, array $headers): TestResponse
    {
        return match ($method) {
            'get' => $this->getJson($path, $headers),
            'post' => $this->postJson($path, $body, $headers),
            default => throw new \InvalidArgumentException("Unsupported method: {$method}"),
        };
    }
}
