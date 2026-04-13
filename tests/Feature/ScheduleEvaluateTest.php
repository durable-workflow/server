<?php

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use App\Models\WorkflowSchedule;
use App\Support\ScheduleOverlapEnforcer;
use App\Support\WorkflowStartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;
use Workflow\V2\Contracts\WorkflowControlPlane;

class ScheduleEvaluateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createNamespace('default');
    }

    // ── No work ─────────────────────────────────────────────────────

    public function test_it_reports_no_schedules_due(): void
    {
        $this->artisan('schedule:evaluate')
            ->assertExitCode(0)
            ->expectsOutputToContain('No schedules due');
    }

    public function test_it_skips_paused_schedules(): void
    {
        WorkflowSchedule::create([
            'schedule_id' => 'paused-sched',
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['* * * * *']],
            'action' => ['workflow_type' => 'TestWorkflow'],
            'paused' => true,
            'next_fire_at' => now()->subMinute(),
        ]);

        $this->artisan('schedule:evaluate')
            ->assertExitCode(0)
            ->expectsOutputToContain('No schedules due');
    }

    public function test_it_skips_schedules_not_yet_due(): void
    {
        WorkflowSchedule::create([
            'schedule_id' => 'future-sched',
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['0 0 1 1 *']],
            'action' => ['workflow_type' => 'TestWorkflow'],
            'next_fire_at' => now()->addDay(),
        ]);

        $this->artisan('schedule:evaluate')
            ->assertExitCode(0)
            ->expectsOutputToContain('No schedules due');
    }

    // ── Successful fire ─────────────────────────────────────────────

    public function test_it_fires_a_due_schedule(): void
    {
        $this->fakeStartService(result: [
            'workflow_id' => 'wf-eval-1',
            'run_id' => 'run-eval-1',
            'workflow_type' => 'TestWorkflow',
            'outcome' => 'started_new',
            'reason' => null,
        ]);

        WorkflowSchedule::create([
            'schedule_id' => 'due-sched',
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['* * * * *'], 'timezone' => 'UTC'],
            'action' => ['workflow_type' => 'TestWorkflow'],
            'next_fire_at' => now()->subMinute(),
        ]);

        $this->artisan('schedule:evaluate')
            ->assertExitCode(0)
            ->expectsOutputToContain('fired');

        $schedule = WorkflowSchedule::where('schedule_id', 'due-sched')->first();
        $this->assertEquals(1, $schedule->fires_count);
        $this->assertNotNull($schedule->last_fired_at);
        $this->assertNotEmpty($schedule->recent_actions);

        $actions = $schedule->recent_actions;
        $lastAction = end($actions);
        $this->assertEquals('wf-eval-1', $lastAction['workflow_id']);
        $this->assertEquals('run-eval-1', $lastAction['run_id']);
    }

    public function test_it_fires_multiple_due_schedules(): void
    {
        $callCount = 0;
        $this->fakeStartService(callback: function () use (&$callCount): array {
            $callCount++;

            return [
                'workflow_id' => "wf-multi-{$callCount}",
                'run_id' => "run-multi-{$callCount}",
                'workflow_type' => 'TestWorkflow',
                'outcome' => 'started_new',
                'reason' => null,
            ];
        });

        WorkflowSchedule::create([
            'schedule_id' => 'due-1',
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['* * * * *']],
            'action' => ['workflow_type' => 'TestWorkflow'],
            'next_fire_at' => now()->subMinutes(2),
        ]);

        WorkflowSchedule::create([
            'schedule_id' => 'due-2',
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['* * * * *']],
            'action' => ['workflow_type' => 'TestWorkflow'],
            'next_fire_at' => now()->subMinute(),
        ]);

        $this->artisan('schedule:evaluate')
            ->assertExitCode(0)
            ->expectsOutputToContain('2 fired');
    }

    // ── Limit option ────────────────────────────────────────────────

    public function test_it_respects_the_limit_option(): void
    {
        $this->fakeStartService(result: [
            'workflow_id' => 'wf-limited',
            'run_id' => 'run-limited',
            'workflow_type' => 'TestWorkflow',
            'outcome' => 'started_new',
            'reason' => null,
        ]);

        for ($i = 1; $i <= 3; $i++) {
            WorkflowSchedule::create([
                'schedule_id' => "limit-sched-{$i}",
                'namespace' => 'default',
                'spec' => ['cron_expressions' => ['* * * * *']],
                'action' => ['workflow_type' => 'TestWorkflow'],
                'next_fire_at' => now()->subMinutes($i),
            ]);
        }

        $this->artisan('schedule:evaluate', ['--limit' => 1])
            ->assertExitCode(0)
            ->expectsOutputToContain('1 fired');
    }

    // ── Failure handling ────────────────────────────────────────────

    public function test_it_records_failures_and_returns_exit_code_1(): void
    {
        $this->fakeStartService(exception: new \RuntimeException('Workflow type not found'));

        WorkflowSchedule::create([
            'schedule_id' => 'fail-sched',
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['* * * * *']],
            'action' => ['workflow_type' => 'BrokenWorkflow'],
            'next_fire_at' => now()->subMinute(),
        ]);

        $this->artisan('schedule:evaluate')
            ->assertExitCode(1)
            ->expectsOutputToContain('failed');

        $schedule = WorkflowSchedule::where('schedule_id', 'fail-sched')->first();
        $this->assertEquals(1, $schedule->failures_count);

        $actions = $schedule->recent_actions;
        $lastAction = end($actions);
        $this->assertEquals('failed', $lastAction['outcome']);
        $this->assertStringContainsString('Workflow type not found', $lastAction['reason']);
    }

    // ── next_fire_at advancement ────────────────────────────────────

    public function test_it_advances_next_fire_at_after_successful_fire(): void
    {
        $this->fakeStartService(result: [
            'workflow_id' => 'wf-advance',
            'run_id' => 'run-advance',
            'workflow_type' => 'TestWorkflow',
            'outcome' => 'started_new',
            'reason' => null,
        ]);

        $originalNext = now()->subMinute();

        WorkflowSchedule::create([
            'schedule_id' => 'advance-sched',
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['0 * * * *'], 'timezone' => 'UTC'],
            'action' => ['workflow_type' => 'TestWorkflow'],
            'next_fire_at' => $originalNext,
        ]);

        $this->artisan('schedule:evaluate')
            ->assertExitCode(0);

        $schedule = WorkflowSchedule::where('schedule_id', 'advance-sched')->first();
        $this->assertNotNull($schedule->next_fire_at);
        $this->assertTrue($schedule->next_fire_at->gt($originalNext));
    }

    // ── Skip overlap policy ─────────────────────────────────────────

    public function test_skip_policy_passes_use_existing_duplicate_policy(): void
    {
        $capturedParams = null;
        $this->fakeStartService(callback: function (array $params) use (&$capturedParams): array {
            $capturedParams = $params;

            return [
                'workflow_id' => 'wf-skip',
                'run_id' => 'run-skip',
                'workflow_type' => 'TestWorkflow',
                'outcome' => 'started_new',
                'reason' => null,
            ];
        });

        WorkflowSchedule::create([
            'schedule_id' => 'skip-sched',
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['* * * * *']],
            'action' => ['workflow_type' => 'TestWorkflow'],
            'overlap_policy' => 'skip',
            'next_fire_at' => now()->subMinute(),
        ]);

        $this->artisan('schedule:evaluate')
            ->assertExitCode(0);

        $this->assertNotNull($capturedParams);
        $this->assertEquals('use-existing', $capturedParams['duplicate_policy']);
    }

    // ── Buffer policies ─────────────────────────────────────────────

    public function test_buffer_one_buffers_when_previous_workflow_is_running(): void
    {
        $this->mockControlPlaneDescribe('wf-running', 'running');

        WorkflowSchedule::create([
            'schedule_id' => 'buffer-eval',
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['* * * * *']],
            'action' => ['workflow_type' => 'TestWorkflow'],
            'overlap_policy' => 'buffer_one',
            'next_fire_at' => now()->subMinute(),
            'recent_actions' => [
                ['workflow_id' => 'wf-running', 'run_id' => 'run-1', 'outcome' => 'started'],
            ],
        ]);

        $this->artisan('schedule:evaluate')
            ->assertExitCode(0)
            ->expectsOutputToContain('buffered');

        $schedule = WorkflowSchedule::where('schedule_id', 'buffer-eval')->first();
        $this->assertCount(1, $schedule->buffered_actions);
        $this->assertNotNull($schedule->next_fire_at);
    }

    public function test_buffer_one_skips_at_capacity(): void
    {
        $this->mockControlPlaneDescribe('wf-running-cap', 'running');

        WorkflowSchedule::create([
            'schedule_id' => 'buffer-cap-eval',
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['* * * * *']],
            'action' => ['workflow_type' => 'TestWorkflow'],
            'overlap_policy' => 'buffer_one',
            'next_fire_at' => now()->subMinute(),
            'recent_actions' => [
                ['workflow_id' => 'wf-running-cap', 'run_id' => 'run-cap', 'outcome' => 'started'],
            ],
            'buffered_actions' => [
                ['buffered_at' => now()->subMinutes(5)->toIso8601String()],
            ],
        ]);

        $this->artisan('schedule:evaluate')
            ->assertExitCode(0)
            ->expectsOutputToContain('skipped');

        $schedule = WorkflowSchedule::where('schedule_id', 'buffer-cap-eval')->first();
        $this->assertCount(1, $schedule->buffered_actions);
    }

    public function test_buffer_policy_fires_normally_when_previous_workflow_completed(): void
    {
        $this->mockControlPlaneDescribe('wf-done', 'completed');

        $this->fakeStartService(result: [
            'workflow_id' => 'wf-new',
            'run_id' => 'run-new',
            'workflow_type' => 'TestWorkflow',
            'outcome' => 'started_new',
            'reason' => null,
        ]);

        WorkflowSchedule::create([
            'schedule_id' => 'buffer-fire-eval',
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['* * * * *']],
            'action' => ['workflow_type' => 'TestWorkflow'],
            'overlap_policy' => 'buffer_one',
            'next_fire_at' => now()->subMinute(),
            'recent_actions' => [
                ['workflow_id' => 'wf-done', 'run_id' => 'run-done', 'outcome' => 'started'],
            ],
        ]);

        $this->artisan('schedule:evaluate')
            ->assertExitCode(0)
            ->expectsOutputToContain('fired');

        $schedule = WorkflowSchedule::where('schedule_id', 'buffer-fire-eval')->first();
        $this->assertEquals(1, $schedule->fires_count);
    }

    // ── Phase 1: Buffer draining ────────────────────────────────────

    public function test_it_drains_buffer_when_previous_workflow_completed(): void
    {
        $this->mockControlPlaneDescribe('wf-drain-done', 'completed');

        $this->fakeStartService(result: [
            'workflow_id' => 'wf-drained',
            'run_id' => 'run-drained',
            'workflow_type' => 'TestWorkflow',
            'outcome' => 'drained',
            'reason' => null,
        ]);

        WorkflowSchedule::create([
            'schedule_id' => 'drain-eval',
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['0 0 1 1 *']],
            'action' => ['workflow_type' => 'TestWorkflow'],
            'overlap_policy' => 'buffer_one',
            'paused' => false,
            'recent_actions' => [
                ['workflow_id' => 'wf-drain-done', 'run_id' => 'run-drain-done', 'outcome' => 'started'],
            ],
            'buffered_actions' => [
                ['buffered_at' => now()->subMinutes(10)->toIso8601String()],
            ],
        ]);

        $this->artisan('schedule:evaluate')
            ->assertExitCode(0)
            ->expectsOutputToContain('drained');

        $schedule = WorkflowSchedule::where('schedule_id', 'drain-eval')->first();
        $this->assertFalse($schedule->hasBufferedActions());
        $this->assertEquals(1, $schedule->fires_count);
    }

    public function test_it_does_not_drain_buffer_when_previous_workflow_still_running(): void
    {
        $this->mockControlPlaneDescribe('wf-still-running', 'running');

        WorkflowSchedule::create([
            'schedule_id' => 'no-drain-eval',
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['0 0 1 1 *']],
            'action' => ['workflow_type' => 'TestWorkflow'],
            'overlap_policy' => 'buffer_one',
            'paused' => false,
            'recent_actions' => [
                ['workflow_id' => 'wf-still-running', 'run_id' => 'run-still', 'outcome' => 'started'],
            ],
            'buffered_actions' => [
                ['buffered_at' => now()->subMinutes(5)->toIso8601String()],
            ],
        ]);

        $this->artisan('schedule:evaluate')
            ->assertExitCode(0);

        $schedule = WorkflowSchedule::where('schedule_id', 'no-drain-eval')->first();
        $this->assertCount(1, $schedule->buffered_actions);
    }

    public function test_it_does_not_drain_paused_schedules(): void
    {
        WorkflowSchedule::create([
            'schedule_id' => 'paused-drain',
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['0 0 1 1 *']],
            'action' => ['workflow_type' => 'TestWorkflow'],
            'overlap_policy' => 'buffer_one',
            'paused' => true,
            'recent_actions' => [
                ['workflow_id' => 'wf-paused-drain', 'run_id' => 'run-pd', 'outcome' => 'started'],
            ],
            'buffered_actions' => [
                ['buffered_at' => now()->subMinutes(5)->toIso8601String()],
            ],
        ]);

        $this->artisan('schedule:evaluate')
            ->assertExitCode(0);

        $schedule = WorkflowSchedule::where('schedule_id', 'paused-drain')->first();
        $this->assertCount(1, $schedule->buffered_actions);
    }

    public function test_drain_failure_is_recorded(): void
    {
        $this->mockControlPlaneDescribe('wf-drain-fail', 'completed');

        $this->fakeStartService(exception: new \RuntimeException('Start failed during drain'));

        WorkflowSchedule::create([
            'schedule_id' => 'drain-fail-eval',
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['0 0 1 1 *']],
            'action' => ['workflow_type' => 'TestWorkflow'],
            'overlap_policy' => 'buffer_one',
            'paused' => false,
            'recent_actions' => [
                ['workflow_id' => 'wf-drain-fail', 'run_id' => 'run-df', 'outcome' => 'started'],
            ],
            'buffered_actions' => [
                ['buffered_at' => now()->subMinutes(5)->toIso8601String()],
            ],
        ]);

        $this->artisan('schedule:evaluate')
            ->assertExitCode(1)
            ->expectsOutputToContain('drain failed');

        $schedule = WorkflowSchedule::where('schedule_id', 'drain-fail-eval')->first();
        $this->assertEquals(1, $schedule->failures_count);
    }

    // ── Cancel/terminate overlap policies ───────────────────────────

    public function test_cancel_other_policy_cancels_previous_and_fires(): void
    {
        $this->mock(WorkflowControlPlane::class, function (MockInterface $mock): void {
            $mock->shouldReceive('cancel')
                ->once()
                ->with('wf-to-cancel', \Mockery::type('array'));
        });

        $this->fakeStartService(result: [
            'workflow_id' => 'wf-after-cancel',
            'run_id' => 'run-after-cancel',
            'workflow_type' => 'TestWorkflow',
            'outcome' => 'started_new',
            'reason' => null,
        ]);

        WorkflowSchedule::create([
            'schedule_id' => 'cancel-other-eval',
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['* * * * *']],
            'action' => ['workflow_type' => 'TestWorkflow'],
            'overlap_policy' => 'cancel_other',
            'next_fire_at' => now()->subMinute(),
            'recent_actions' => [
                ['workflow_id' => 'wf-to-cancel', 'run_id' => 'run-cancel', 'outcome' => 'started'],
            ],
        ]);

        $this->artisan('schedule:evaluate')
            ->assertExitCode(0)
            ->expectsOutputToContain('fired');
    }

    // ── Namespace threading ─────────────────────────────────────────

    public function test_it_passes_schedule_namespace_to_start_service(): void
    {
        $this->createNamespace('production');

        $capturedNamespace = null;
        $this->fakeStartService(callback: function (array $params, ?string $namespace = null) use (&$capturedNamespace): array {
            $capturedNamespace = $namespace;

            return [
                'workflow_id' => 'wf-ns',
                'run_id' => 'run-ns',
                'workflow_type' => 'TestWorkflow',
                'outcome' => 'started_new',
                'reason' => null,
            ];
        });

        WorkflowSchedule::create([
            'schedule_id' => 'ns-sched',
            'namespace' => 'production',
            'spec' => ['cron_expressions' => ['* * * * *']],
            'action' => ['workflow_type' => 'TestWorkflow'],
            'next_fire_at' => now()->subMinute(),
        ]);

        $this->artisan('schedule:evaluate')
            ->assertExitCode(0);

        $this->assertEquals('production', $capturedNamespace);
    }

    // ── Helpers ──────────────────────────────────────────────────────

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

    /**
     * Bind a fake WorkflowStartService into the container.
     *
     * WorkflowStartService is final, so Mockery cannot mock it directly.
     * Instead we bind an anonymous object that delegates to a callback.
     */
    private function fakeStartService(
        ?array $result = null,
        ?\Throwable $exception = null,
        ?\Closure $callback = null,
    ): void {
        $fake = new class($result, $exception, $callback)
        {
            public function __construct(
                private readonly ?array $result,
                private readonly ?\Throwable $exception,
                private readonly ?\Closure $callback,
            ) {}

            public function start(array $validated, ?string $namespace = null): array
            {
                if ($this->exception) {
                    throw $this->exception;
                }

                if ($this->callback) {
                    return ($this->callback)($validated, $namespace);
                }

                return $this->result;
            }
        };

        $this->instance(WorkflowStartService::class, $fake);
    }

    private function mockControlPlaneDescribe(string $workflowId, string $statusBucket): void
    {
        $this->mock(WorkflowControlPlane::class, function (MockInterface $mock) use ($workflowId, $statusBucket): void {
            $mock->shouldReceive('describe')
                ->with($workflowId, \Mockery::type('array'))
                ->andReturn([
                    'found' => true,
                    'run' => ['status_bucket' => $statusBucket],
                ]);
        });
    }
}
