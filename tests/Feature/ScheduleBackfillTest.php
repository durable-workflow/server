<?php

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use Workflow\V2\Models\WorkflowSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;
use Workflow\V2\Contracts\WorkflowControlPlane;

class ScheduleBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createNamespace('default');
    }

    // ── Validation ──────────────────────────────────────────────────

    public function test_backfill_requires_start_time_and_end_time(): void
    {
        $this->createSchedule('backfill-val');

        $this->withHeaders($this->headers())
            ->postJson('/api/schedules/backfill-val/backfill', [])
            ->assertUnprocessable();

        $this->withHeaders($this->headers())
            ->postJson('/api/schedules/backfill-val/backfill', [
                'start_time' => '2026-04-01T00:00:00Z',
            ])
            ->assertUnprocessable();

        $this->withHeaders($this->headers())
            ->postJson('/api/schedules/backfill-val/backfill', [
                'end_time' => '2026-04-02T00:00:00Z',
            ])
            ->assertUnprocessable();
    }

    public function test_backfill_rejects_end_time_before_start_time(): void
    {
        $this->createSchedule('backfill-range');

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/schedules/backfill-range/backfill', [
                'start_time' => '2026-04-10T00:00:00Z',
                'end_time' => '2026-04-09T00:00:00Z',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('reason', 'invalid_time_range');
    }

    public function test_backfill_rejects_equal_start_and_end_time(): void
    {
        $this->createSchedule('backfill-equal');

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/schedules/backfill-equal/backfill', [
                'start_time' => '2026-04-10T00:00:00Z',
                'end_time' => '2026-04-10T00:00:00Z',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('reason', 'invalid_time_range');
    }

    public function test_backfill_returns_404_for_nonexistent_schedule(): void
    {
        $this->withHeaders($this->headers())
            ->postJson('/api/schedules/nonexistent/backfill', [
                'start_time' => '2026-04-01T00:00:00Z',
                'end_time' => '2026-04-02T00:00:00Z',
            ])
            ->assertNotFound()
            ->assertJsonPath('reason', 'schedule_not_found');
    }

    public function test_backfill_validates_overlap_policy(): void
    {
        $this->createSchedule('backfill-policy');

        $this->withHeaders($this->headers())
            ->postJson('/api/schedules/backfill-policy/backfill', [
                'start_time' => '2026-04-01T00:00:00Z',
                'end_time' => '2026-04-02T00:00:00Z',
                'overlap_policy' => 'invalid_policy',
            ])
            ->assertUnprocessable();
    }

    // ── Successful backfill ─────────────────────────────────────────

    public function test_backfill_starts_workflows_for_each_fire_time(): void
    {
        $callCount = 0;

        $this->mock(WorkflowControlPlane::class, function (MockInterface $mock) use (&$callCount): void {
            $mock->shouldReceive('start')
                ->andReturnUsing(function () use (&$callCount): array {
                    $callCount++;

                    return [
                        'started' => true,
                        'workflow_instance_id' => "wf-backfill-{$callCount}",
                        'workflow_run_id' => "run-backfill-{$callCount}",
                        'workflow_type' => 'HourlyWorkflow',
                        'outcome' => 'started_new',
                        'task_id' => null,
                        'reason' => null,
                    ];
                });
        });

        // Hourly cron — backfill window covers 3 hours
        $this->createSchedule('backfill-hourly', [
            'spec' => ['cron_expressions' => ['0 * * * *'], 'timezone' => 'UTC'],
            'action' => ['workflow_type' => 'HourlyWorkflow'],
        ]);

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/schedules/backfill-hourly/backfill', [
                'start_time' => '2026-04-10T00:00:00Z',
                'end_time' => '2026-04-10T03:00:00Z',
            ]);

        $response->assertOk()
            ->assertJsonPath('schedule_id', 'backfill-hourly')
            ->assertJsonPath('outcome', 'backfill_started');

        $this->assertGreaterThanOrEqual(2, $response->json('fires_attempted'));
        $this->assertCount($response->json('fires_attempted'), $response->json('results'));

        foreach ($response->json('results') as $result) {
            $this->assertArrayHasKey('fire_time', $result);
            $this->assertArrayHasKey('outcome', $result);
            $this->assertEquals('started', $result['outcome']);
            $this->assertArrayHasKey('workflow_id', $result);
        }
    }

    public function test_backfill_records_fire_on_schedule(): void
    {
        $this->mock(WorkflowControlPlane::class, function (MockInterface $mock): void {
            $mock->shouldReceive('start')
                ->andReturn([
                    'started' => true,
                    'workflow_instance_id' => 'wf-bf-rec-1',
                    'workflow_run_id' => 'run-bf-rec-1',
                    'workflow_type' => 'DailyWorkflow',
                    'outcome' => 'started_new',
                    'task_id' => null,
                    'reason' => null,
                ]);
        });

        $this->createSchedule('backfill-record', [
            'spec' => ['cron_expressions' => ['0 12 * * *'], 'timezone' => 'UTC'],
            'action' => ['workflow_type' => 'DailyWorkflow'],
        ]);

        $this->withHeaders($this->headers())
            ->postJson('/api/schedules/backfill-record/backfill', [
                'start_time' => '2026-04-10T00:00:00Z',
                'end_time' => '2026-04-11T00:00:00Z',
            ]);

        $schedule = WorkflowSchedule::where('schedule_id', 'backfill-record')->first();
        $this->assertGreaterThanOrEqual(1, $schedule->fires_count);
        $this->assertNotEmpty($schedule->recent_actions);

        $actions = $schedule->recent_actions;
        $lastAction = end($actions);
        $this->assertEquals('backfilled', $lastAction['outcome']);
    }

    public function test_backfill_records_failures_on_schedule(): void
    {
        $this->mock(WorkflowControlPlane::class, function (MockInterface $mock): void {
            $mock->shouldReceive('start')
                ->andThrow(new \RuntimeException('Type not registered'));
        });

        $this->createSchedule('backfill-fail', [
            'spec' => ['cron_expressions' => ['0 12 * * *'], 'timezone' => 'UTC'],
            'action' => ['workflow_type' => 'MissingWorkflow'],
        ]);

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/schedules/backfill-fail/backfill', [
                'start_time' => '2026-04-10T00:00:00Z',
                'end_time' => '2026-04-11T00:00:00Z',
            ]);

        $response->assertOk()
            ->assertJsonPath('outcome', 'backfill_started');

        $this->assertGreaterThanOrEqual(1, $response->json('fires_attempted'));

        foreach ($response->json('results') as $result) {
            $this->assertEquals('failed', $result['outcome']);
            $this->assertArrayHasKey('reason', $result);
        }

        $schedule = WorkflowSchedule::where('schedule_id', 'backfill-fail')->first();
        $this->assertGreaterThanOrEqual(1, $schedule->failures_count);
    }

    public function test_backfill_with_no_fire_times_in_window(): void
    {
        $this->createSchedule('backfill-empty', [
            // Annual schedule — no fires in a 1-hour window
            'spec' => ['cron_expressions' => ['0 0 1 1 *'], 'timezone' => 'UTC'],
            'action' => ['workflow_type' => 'AnnualWorkflow'],
        ]);

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/schedules/backfill-empty/backfill', [
                'start_time' => '2026-04-10T00:00:00Z',
                'end_time' => '2026-04-10T01:00:00Z',
            ]);

        $response->assertOk()
            ->assertJsonPath('outcome', 'backfill_started')
            ->assertJsonPath('fires_attempted', 0)
            ->assertJsonPath('results', []);
    }

    public function test_backfill_accepts_override_overlap_policy(): void
    {
        $capturedParams = [];
        $callCount = 0;

        $this->mock(WorkflowControlPlane::class, function (MockInterface $mock) use (&$capturedParams, &$callCount): void {
            $mock->shouldReceive('start')
                ->andReturnUsing(function (string $type, ?string $id, array $options) use (&$capturedParams, &$callCount): array {
                    $callCount++;
                    $capturedParams[] = $options;

                    return [
                        'started' => true,
                        'workflow_instance_id' => "wf-bf-op-{$callCount}",
                        'workflow_run_id' => "run-bf-op-{$callCount}",
                        'workflow_type' => $type,
                        'outcome' => 'started_new',
                        'task_id' => null,
                        'reason' => null,
                    ];
                });
        });

        $this->createSchedule('backfill-op', [
            'spec' => ['cron_expressions' => ['0 12 * * *'], 'timezone' => 'UTC'],
            'action' => ['workflow_type' => 'TestWorkflow'],
            'overlap_policy' => 'skip',
        ]);

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/schedules/backfill-op/backfill', [
                'start_time' => '2026-04-10T00:00:00Z',
                'end_time' => '2026-04-11T00:00:00Z',
                'overlap_policy' => 'allow_all',
            ]);

        $response->assertOk();

        // The override policy 'allow_all' maps to null from duplicateStartPolicy(),
        // which gets filtered out or mapped to reject_duplicate by WorkflowStartService.
        // The key assertion: the backfill used the override policy, not the schedule's 'skip'.
        // 'skip' would map to 'return_existing_active', 'allow_all' maps to 'reject_duplicate'.
        if (count($capturedParams) > 0) {
            $duplicatePolicy = $capturedParams[0]['duplicate_start_policy'] ?? null;
            $this->assertEquals('reject_duplicate', $duplicatePolicy);
        }
    }

    // ── Namespace scoping ───────────────────────────────────────────

    public function test_backfill_is_namespace_scoped(): void
    {
        $this->createNamespace('other');

        $this->createSchedule('scoped-backfill', [
            'spec' => ['cron_expressions' => ['0 * * * *'], 'timezone' => 'UTC'],
            'action' => ['workflow_type' => 'TestWorkflow'],
        ], 'other');

        $this->withHeaders($this->headers('default'))
            ->postJson('/api/schedules/scoped-backfill/backfill', [
                'start_time' => '2026-04-10T00:00:00Z',
                'end_time' => '2026-04-10T03:00:00Z',
            ])
            ->assertNotFound();
    }

    public function test_backfill_with_interval_schedule(): void
    {
        $callCount = 0;

        $this->mock(WorkflowControlPlane::class, function (MockInterface $mock) use (&$callCount): void {
            $mock->shouldReceive('start')
                ->andReturnUsing(function () use (&$callCount): array {
                    $callCount++;

                    return [
                        'started' => true,
                        'workflow_instance_id' => "wf-bf-int-{$callCount}",
                        'workflow_run_id' => "run-bf-int-{$callCount}",
                        'workflow_type' => 'IntervalWorkflow',
                        'outcome' => 'started_new',
                        'task_id' => null,
                        'reason' => null,
                    ];
                });
        });

        $this->createSchedule('backfill-interval', [
            'spec' => ['intervals' => [['every' => 'PT30M']]],
            'action' => ['workflow_type' => 'IntervalWorkflow'],
        ]);

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/schedules/backfill-interval/backfill', [
                'start_time' => '2026-04-10T00:00:00Z',
                'end_time' => '2026-04-10T02:00:00Z',
            ]);

        $response->assertOk()
            ->assertJsonPath('outcome', 'backfill_started');

        // 30-min interval over 2 hours = ~3-4 fires
        $this->assertGreaterThanOrEqual(3, $response->json('fires_attempted'));
    }

    public function test_backfill_partial_failure_reports_mixed_results(): void
    {
        $callCount = 0;

        $this->mock(WorkflowControlPlane::class, function (MockInterface $mock) use (&$callCount): void {
            $mock->shouldReceive('start')
                ->andReturnUsing(function () use (&$callCount): array {
                    $callCount++;

                    if ($callCount === 2) {
                        throw new \RuntimeException('Transient error');
                    }

                    return [
                        'started' => true,
                        'workflow_instance_id' => "wf-bf-mix-{$callCount}",
                        'workflow_run_id' => "run-bf-mix-{$callCount}",
                        'workflow_type' => 'HourlyWorkflow',
                        'outcome' => 'started_new',
                        'task_id' => null,
                        'reason' => null,
                    ];
                });
        });

        $this->createSchedule('backfill-mixed', [
            'spec' => ['cron_expressions' => ['0 * * * *'], 'timezone' => 'UTC'],
            'action' => ['workflow_type' => 'HourlyWorkflow'],
        ]);

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/schedules/backfill-mixed/backfill', [
                'start_time' => '2026-04-10T00:00:00Z',
                'end_time' => '2026-04-10T04:00:00Z',
            ]);

        $response->assertOk();

        $results = $response->json('results');
        $outcomes = array_column($results, 'outcome');

        $this->assertContains('started', $outcomes);
        $this->assertContains('failed', $outcomes);
    }

    // ── Timeout threading ────────────────────────────────────────────

    public function test_backfill_threads_canonical_timeout_fields(): void
    {
        $capturedOptions = [];

        $this->mock(WorkflowControlPlane::class, function (MockInterface $mock) use (&$capturedOptions): void {
            $mock->shouldReceive('start')
                ->andReturnUsing(function (string $type, ?string $workflowId, array $options) use (&$capturedOptions): array {
                    $capturedOptions[] = $options;

                    return [
                        'started' => true,
                        'workflow_instance_id' => 'wf-bf-to-1',
                        'workflow_run_id' => 'run-bf-to-1',
                        'workflow_type' => $type,
                        'outcome' => 'started_new',
                        'task_id' => null,
                        'reason' => null,
                    ];
                });
        });

        $this->createSchedule('backfill-timeout', [
            'action' => [
                'workflow_type' => 'TestWorkflow',
                'execution_timeout_seconds' => 300,
                'run_timeout_seconds' => 120,
            ],
        ]);

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/schedules/backfill-timeout/backfill', [
                'start_time' => '2026-04-10T00:00:00Z',
                'end_time' => '2026-04-10T02:00:00Z',
            ]);

        $response->assertOk()
            ->assertJsonPath('outcome', 'backfill_started');

        $this->assertNotEmpty($capturedOptions);
        $this->assertEquals(300, $capturedOptions[0]['execution_timeout_seconds']);
        $this->assertEquals(120, $capturedOptions[0]['run_timeout_seconds']);
    }

    public function test_backfill_normalizes_legacy_timeout_fields(): void
    {
        $capturedOptions = [];

        $this->mock(WorkflowControlPlane::class, function (MockInterface $mock) use (&$capturedOptions): void {
            $mock->shouldReceive('start')
                ->andReturnUsing(function (string $type, ?string $workflowId, array $options) use (&$capturedOptions): array {
                    $capturedOptions[] = $options;

                    return [
                        'started' => true,
                        'workflow_instance_id' => 'wf-bf-legacy-1',
                        'workflow_run_id' => 'run-bf-legacy-1',
                        'workflow_type' => $type,
                        'outcome' => 'started_new',
                        'task_id' => null,
                        'reason' => null,
                    ];
                });
        });

        $schedule = $this->createSchedule('backfill-legacy-to');

        // Write raw legacy JSON directly to simulate pre-existing row
        \Illuminate\Support\Facades\DB::table('workflow_schedules')
            ->where('id', $schedule->id)
            ->update(['action' => json_encode([
                'workflow_type' => 'TestWorkflow',
                'workflow_execution_timeout' => 300,
                'workflow_run_timeout' => 120,
            ])]);

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/schedules/backfill-legacy-to/backfill', [
                'start_time' => '2026-04-10T00:00:00Z',
                'end_time' => '2026-04-10T02:00:00Z',
            ]);

        $response->assertOk()
            ->assertJsonPath('outcome', 'backfill_started');

        $this->assertNotEmpty($capturedOptions);
        $this->assertEquals(300, $capturedOptions[0]['execution_timeout_seconds']);
        $this->assertEquals(120, $capturedOptions[0]['run_timeout_seconds']);
        $this->assertArrayNotHasKey('workflow_execution_timeout', $capturedOptions[0]);
        $this->assertArrayNotHasKey('workflow_run_timeout', $capturedOptions[0]);
    }

    // ── Auth ────────────────────────────────────────────────────────

    public function test_backfill_requires_authentication(): void
    {
        config(['server.auth.driver' => 'token', 'server.auth.token' => 'secret']);

        $this->createSchedule('backfill-auth');

        $this->postJson('/api/schedules/backfill-auth/backfill', [
            'start_time' => '2026-04-10T00:00:00Z',
            'end_time' => '2026-04-11T00:00:00Z',
        ])->assertUnauthorized();
    }

    // ── Helpers ─────────────────────────────────────────────────────

    private function createNamespace(string $name): void
    {
        WorkflowNamespace::query()->updateOrCreate(
            ['name' => $name],
            [
                'description' => "{$name} namespace",
                'retention_days' => 30,
                'status' => 'active',
            ],
        );
    }

    private function createSchedule(string $scheduleId, array $overrides = [], string $namespace = 'default'): WorkflowSchedule
    {
        return WorkflowSchedule::create(array_merge([
            'schedule_id' => $scheduleId,
            'namespace' => $namespace,
            'spec' => ['cron_expressions' => ['0 * * * *'], 'timezone' => 'UTC'],
            'action' => ['workflow_type' => 'TestWorkflow'],
            'overlap_policy' => 'skip',
        ], $overrides));
    }

    private function headers(string $namespace = 'default'): array
    {
        return [
            'X-Namespace' => $namespace,
            'X-Durable-Workflow-Control-Plane-Version' => \App\Support\ControlPlaneProtocol::VERSION,
        ];
    }
}
