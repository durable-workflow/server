<?php

namespace Tests\Feature;

use App\Models\SearchAttributeDefinition;
use App\Models\WorkflowNamespace;
use App\Support\RemoteScheduleStarter;
use App\Support\WorkerProtocol;
use App\Support\WorkflowStartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Exceptions\WorkflowExecutionUnavailableException;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSchedule;
use Workflow\V2\Models\WorkflowScheduleHistoryEvent;
use Workflow\V2\Models\WorkflowTask;

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

    public function test_it_outputs_machine_readable_evaluation_report(): void
    {
        $this->fakeStartService(result: [
            'workflow_id' => 'wf-json-eval',
            'run_id' => 'run-json-eval',
            'workflow_type' => 'TestWorkflow',
            'outcome' => 'started_new',
            'reason' => null,
        ]);

        WorkflowSchedule::create([
            'schedule_id' => 'json-eval-sched',
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['* * * * *'], 'timezone' => 'UTC'],
            'action' => ['workflow_type' => 'TestWorkflow'],
            'next_fire_at' => now()->subMinute(),
        ]);

        $exitCode = Artisan::call('schedule:evaluate', ['--json' => true]);
        $report = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame('durable-workflow.server.schedule-evaluation.report', $report['schema']);
        $this->assertSame(1, $report['schema_version']);
        $this->assertSame(100, $report['limit']);
        $this->assertSame(1, $report['processed']);
        $this->assertSame(1, $report['processed_count']);
        $this->assertSame(1, $report['processed_schedule_count']);
        $this->assertSame(1, $report['eligible_count']);
        $this->assertSame(1, $report['eligible_schedule_count']);
        $this->assertSame(['json-eval-sched'], $report['processed_schedule_ids']);
        $this->assertSame(['json-eval-sched'], $report['eligible_schedule_ids']);
        $this->assertSame(1, $report['fired']);
        $this->assertSame(1, $report['fired_count']);
        $this->assertSame(1, $report['summary']['processed_count']);
        $this->assertSame(1, $report['summary']['processed_schedule_count']);
        $this->assertSame(1, $report['summary']['eligible_schedule_count']);
        $this->assertCount(1, $report['results']);
        $this->assertSame('json-eval-sched', $report['results'][0]['schedule_id']);
        $this->assertSame('wf-json-eval', $report['results'][0]['instance_id']);
        $this->assertArrayHasKey('evaluated_at', $report);
        $this->assertArrayHasKey('occurrence_time', $report['results'][0]);
        $this->assertArrayHasKey('last_fired_at', $report['results'][0]);
        $this->assertArrayHasKey('next_fire_at', $report['results'][0]);
    }

    public function test_it_skips_paused_schedules(): void
    {
        WorkflowSchedule::create([
            'schedule_id' => 'paused-sched',
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['* * * * *']],
            'action' => ['workflow_type' => 'TestWorkflow'],
            'status' => 'paused',
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
            ->expectsOutputToContain('1 processed')
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

    public function test_it_records_rollout_safety_start_rejections_as_skips(): void
    {
        $this->fakeStartService(result: [
            'started' => false,
            'workflow_id' => 'wf-skip-compatibility-blocked',
            'run_id' => null,
            'workflow_type' => 'TestWorkflow',
            'outcome' => 'rejected_compatibility_blocked',
            'reason' => 'compatibility_blocked',
            'rejection_reason' => 'compatibility_blocked',
            'message' => 'Workflow instance [wf-skip-compatibility-blocked] cannot start.',
        ]);

        WorkflowSchedule::create([
            'schedule_id' => 'skip-compatibility-blocked',
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['* * * * *']],
            'action' => ['workflow_type' => 'TestWorkflow'],
            'next_fire_at' => now()->subMinute(),
        ]);

        $this->artisan('schedule:evaluate')
            ->assertExitCode(0)
            ->expectsOutputToContain('skipped');

        $schedule = WorkflowSchedule::where('schedule_id', 'skip-compatibility-blocked')->firstOrFail();
        $this->assertSame('compatibility_blocked', $schedule->last_skip_reason);
        $this->assertSame(1, (int) $schedule->skipped_trigger_count);
        $this->assertSame(0, (int) $schedule->fires_count);
        $this->assertSame(0, (int) $schedule->failures_count);
        $this->assertNull($schedule->last_fired_at);
        $this->assertNull($schedule->latest_workflow_instance_id);
        $this->assertSame([], $schedule->recent_actions ?? []);
    }

    public function test_it_skips_due_schedules_when_workflow_bootstrap_is_blocked(): void
    {
        DB::table('migrations')
            ->where('migration', '2026_04_21_000300_add_workflow_definition_fingerprints_to_worker_registrations')
            ->delete();

        WorkflowSchedule::create([
            'schedule_id' => 'skip-bootstrap-blocked',
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['* * * * *']],
            'action' => ['workflow_type' => 'TestWorkflow'],
            'next_fire_at' => now()->subMinute(),
        ]);

        $this->artisan('schedule:evaluate')
            ->assertExitCode(0)
            ->expectsOutputToContain('skipped');

        $schedule = WorkflowSchedule::where('schedule_id', 'skip-bootstrap-blocked')->firstOrFail();
        $this->assertSame('workflow_v2_blocked', $schedule->last_skip_reason);
        $this->assertSame(1, (int) $schedule->skipped_trigger_count);
        $this->assertSame(0, (int) $schedule->fires_count);
        $this->assertSame(0, (int) $schedule->failures_count);
        $this->assertNull($schedule->last_fired_at);
        $this->assertNull($schedule->latest_workflow_instance_id);
        $this->assertSame([], $schedule->recent_actions ?? []);
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
        $this->createWorkflowWithRun('wf-running', 'run-1', 'running');

        WorkflowSchedule::create([
            'schedule_id' => 'buffer-eval',
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['* * * * *']],
            'action' => ['workflow_type' => 'TestWorkflow'],
            'overlap_policy' => 'buffer_one',
            'next_fire_at' => now()->subMinute(),
            'latest_workflow_instance_id' => 'wf-running',
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
        $this->createWorkflowWithRun('wf-running-cap', 'run-cap', 'running');

        WorkflowSchedule::create([
            'schedule_id' => 'buffer-cap-eval',
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['* * * * *']],
            'action' => ['workflow_type' => 'TestWorkflow'],
            'overlap_policy' => 'buffer_one',
            'next_fire_at' => now()->subMinute(),
            'latest_workflow_instance_id' => 'wf-running-cap',
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
        $this->createWorkflowWithRun('wf-done', 'run-done', 'completed');

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
            'latest_workflow_instance_id' => 'wf-done',
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
        $this->createWorkflowWithRun('wf-drain-done', 'run-drain-done', 'completed');

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
            'status' => 'active',
            'latest_workflow_instance_id' => 'wf-drain-done',
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
        $this->createWorkflowWithRun('wf-still-running', 'run-still', 'running');

        WorkflowSchedule::create([
            'schedule_id' => 'no-drain-eval',
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['0 0 1 1 *']],
            'action' => ['workflow_type' => 'TestWorkflow'],
            'overlap_policy' => 'buffer_one',
            'status' => 'active',
            'latest_workflow_instance_id' => 'wf-still-running',
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
            'status' => 'paused',
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

    public function test_deleted_schedule_selected_before_dispatch_is_not_started_or_attributed(): void
    {
        $startServiceCalled = false;
        $this->fakeStartService(callback: function () use (&$startServiceCalled): array {
            $startServiceCalled = true;

            return [
                'workflow_id' => 'wf-stale-delete',
                'run_id' => 'run-stale-delete',
                'workflow_type' => 'TestWorkflow',
                'outcome' => 'started_new',
                'reason' => null,
            ];
        });

        $schedule = WorkflowSchedule::create([
            'schedule_id' => 'stale-delete-before-dispatch',
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['* * * * *']],
            'action' => ['workflow_type' => 'TestWorkflow'],
            'status' => 'active',
            'next_fire_at' => now()->subMinute(),
        ]);
        $selectedBeforeDelete = WorkflowSchedule::whereKey($schedule->id)->firstOrFail();

        $schedule->forceFill([
            'status' => 'deleted',
            'deleted_at' => now(),
            'next_fire_at' => null,
        ])->save();

        try {
            $this->app->make(RemoteScheduleStarter::class)->start(
                $selectedBeforeDelete,
                now()->subMinute(),
                'scheduled',
            );
            $this->fail('Deleted schedules must not dispatch selected stale work.');
        } catch (WorkflowExecutionUnavailableException $exception) {
            $this->assertSame('schedule_deleted', $exception->blockedReason());
        }

        $this->assertFalse($startServiceCalled);
        $this->assertFalse(
            WorkflowScheduleHistoryEvent::query()
                ->where('workflow_schedule_id', $schedule->id)
                ->where('event_type', HistoryEventType::ScheduleTriggered->value)
                ->exists(),
        );
    }

    public function test_drain_failure_is_recorded(): void
    {
        $this->createWorkflowWithRun('wf-drain-fail', 'run-df', 'completed');

        $this->fakeStartService(exception: new \RuntimeException('Start failed during drain'));

        WorkflowSchedule::create([
            'schedule_id' => 'drain-fail-eval',
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['0 0 1 1 *']],
            'action' => ['workflow_type' => 'TestWorkflow'],
            'overlap_policy' => 'buffer_one',
            'status' => 'active',
            'latest_workflow_instance_id' => 'wf-drain-fail',
            'recent_actions' => [
                ['workflow_id' => 'wf-drain-fail', 'run_id' => 'run-df', 'outcome' => 'started'],
            ],
            'buffered_actions' => [
                ['buffered_at' => now()->subMinutes(5)->toIso8601String()],
            ],
        ]);

        $this->artisan('schedule:evaluate')
            ->assertExitCode(1)
            ->expectsOutputToContain('failed');

        $schedule = WorkflowSchedule::where('schedule_id', 'drain-fail-eval')->first();
        $this->assertEquals(1, $schedule->failures_count);
    }

    // ── Cancel/terminate overlap policies ───────────────────────────

    public function test_cancel_other_policy_cancels_previous_and_fires(): void
    {
        $this->createWorkflowWithRun('wf-to-cancel', 'run-cancel', 'running');

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
            'latest_workflow_instance_id' => 'wf-to-cancel',
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

    public function test_it_passes_registered_schedule_search_attribute_types_to_start_service(): void
    {
        SearchAttributeDefinition::create([
            'namespace' => 'default',
            'name' => 'Tags',
            'type' => 'keyword_list',
        ]);

        $capturedParams = null;
        $this->fakeStartService(callback: function (array $params) use (&$capturedParams): array {
            $capturedParams = $params;

            return [
                'workflow_id' => 'wf-search-attrs',
                'run_id' => 'run-search-attrs',
                'workflow_type' => 'TestWorkflow',
                'outcome' => 'started_new',
                'reason' => null,
            ];
        });

        WorkflowSchedule::create([
            'schedule_id' => 'search-attrs-sched',
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['* * * * *']],
            'action' => ['workflow_type' => 'TestWorkflow'],
            'search_attributes' => ['Tags' => ['alpha', 'beta']],
            'next_fire_at' => now()->subMinute(),
        ]);

        $this->artisan('schedule:evaluate')
            ->assertExitCode(0);

        $this->assertNotNull($capturedParams);
        $this->assertSame(['Tags' => ['alpha', 'beta']], $capturedParams['search_attributes']);
        $this->assertSame(['Tags' => 'keyword_list'], $capturedParams['search_attribute_types']);
    }

    public function test_it_rejects_existing_schedule_search_attribute_type_mismatch_before_start(): void
    {
        SearchAttributeDefinition::create([
            'namespace' => 'default',
            'name' => 'CustomerAge',
            'type' => 'int',
        ]);

        $startServiceCalled = false;
        $this->fakeStartService(callback: function (array $params) use (&$startServiceCalled): array {
            $startServiceCalled = true;

            return [
                'workflow_id' => 'wf-invalid-search-attrs',
                'run_id' => 'run-invalid-search-attrs',
                'workflow_type' => 'TestWorkflow',
                'outcome' => 'started_new',
                'reason' => null,
            ];
        });

        WorkflowSchedule::create([
            'schedule_id' => 'invalid-search-attrs-sched',
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['* * * * *']],
            'action' => ['workflow_type' => 'TestWorkflow'],
            'search_attributes' => ['CustomerAge' => 'not-an-int'],
            'next_fire_at' => now()->subMinute(),
        ]);

        $this->artisan('schedule:evaluate')
            ->assertExitCode(1)
            ->expectsOutputToContain('failed');

        $this->assertFalse($startServiceCalled);
    }

    // ── Legacy timeout normalization ───────────────────────────────

    public function test_it_normalizes_legacy_timeout_fields_when_firing(): void
    {
        $capturedParams = null;
        $this->fakeStartService(callback: function (array $params) use (&$capturedParams): array {
            $capturedParams = $params;

            return [
                'workflow_id' => 'wf-legacy-timeout',
                'run_id' => 'run-legacy-timeout',
                'workflow_type' => 'TestWorkflow',
                'outcome' => 'started_new',
                'reason' => null,
            ];
        });

        // Simulate a pre-existing schedule row with legacy field names
        $schedule = WorkflowSchedule::create([
            'schedule_id' => 'legacy-timeout-sched',
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['* * * * *']],
            'action' => ['workflow_type' => 'TestWorkflow', 'workflow_execution_timeout' => 300, 'workflow_run_timeout' => 120],
            'next_fire_at' => now()->subMinute(),
        ]);

        // Bypass the model accessor to write raw legacy JSON
        DB::table('workflow_schedules')
            ->where('id', $schedule->id)
            ->update(['action' => json_encode([
                'workflow_type' => 'TestWorkflow',
                'workflow_execution_timeout' => 300,
                'workflow_run_timeout' => 120,
            ])]);

        $this->artisan('schedule:evaluate')
            ->assertExitCode(0)
            ->expectsOutputToContain('fired');

        $this->assertNotNull($capturedParams);
        $this->assertEquals(300, $capturedParams['execution_timeout_seconds']);
        $this->assertEquals(120, $capturedParams['run_timeout_seconds']);
        $this->assertArrayNotHasKey('workflow_execution_timeout', $capturedParams);
        $this->assertArrayNotHasKey('workflow_run_timeout', $capturedParams);
    }

    public function test_it_normalizes_legacy_timeout_fields_when_draining_buffer(): void
    {
        $this->createWorkflowWithRun('wf-drain-legacy', 'run-dl', 'completed');

        $capturedParams = null;
        $this->fakeStartService(callback: function (array $params) use (&$capturedParams): array {
            $capturedParams = $params;

            return [
                'workflow_id' => 'wf-drained-legacy',
                'run_id' => 'run-drained-legacy',
                'workflow_type' => 'TestWorkflow',
                'outcome' => 'drained',
                'reason' => null,
            ];
        });

        $schedule = WorkflowSchedule::create([
            'schedule_id' => 'drain-legacy-timeout',
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['0 0 1 1 *']],
            'action' => ['workflow_type' => 'TestWorkflow'],
            'overlap_policy' => 'buffer_one',
            'status' => 'active',
            'latest_workflow_instance_id' => 'wf-drain-legacy',
            'recent_actions' => [
                ['workflow_id' => 'wf-drain-legacy', 'run_id' => 'run-dl', 'outcome' => 'started'],
            ],
            'buffered_actions' => [
                ['buffered_at' => now()->subMinutes(10)->toIso8601String()],
            ],
        ]);

        // Write raw legacy JSON directly
        DB::table('workflow_schedules')
            ->where('id', $schedule->id)
            ->update(['action' => json_encode([
                'workflow_type' => 'TestWorkflow',
                'workflow_execution_timeout' => 600,
                'workflow_run_timeout' => 180,
            ])]);

        $this->artisan('schedule:evaluate')
            ->assertExitCode(0)
            ->expectsOutputToContain('drained');

        $this->assertNotNull($capturedParams);
        $this->assertEquals(600, $capturedParams['execution_timeout_seconds']);
        $this->assertEquals(180, $capturedParams['run_timeout_seconds']);
    }

    public function test_scheduled_polyglot_start_stays_unpinned_when_no_worker_build_id_is_selected(): void
    {
        config()->set('workflows.v2.compatibility.current', 'server-image-build');
        config()->set('workflows.v2.compatibility.supported', ['server-image-build']);

        $workflowType = 'SchedulesConformancePhpWorkflow';
        $taskQueue = 'schedules-shared';

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', [
                'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
                'worker_id' => 'schedules-php-worker',
                'task_queue' => $taskQueue,
                'runtime' => 'php',
                'sdk_version' => '2.0.0-alpha.test',
                'supported_workflow_types' => [$workflowType],
                'workflow_definition_fingerprints' => [
                    $workflowType => 'schedules-conformance:SchedulesConformancePhpWorkflow:php',
                ],
                'supported_activity_types' => [],
                'max_concurrent_workflow_tasks' => 10,
                'max_concurrent_activity_tasks' => 10,
                'task_slots' => [
                    'workflow_available' => 10,
                    'activity_available' => 10,
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('build_id', null);

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/schedules', [
                'schedule_id' => 'python-created-php-worker',
                'spec' => [
                    'intervals' => [['every' => 'PT30S']],
                    'timezone' => 'UTC',
                ],
                'action' => [
                    'workflow_type' => $workflowType,
                    'task_queue' => $taskQueue,
                    'input' => [[
                        'scenario' => 'python_created_php_workflow',
                        'schedule_creator' => 'sdk-python',
                        'workflow_runtime' => 'workflow-php',
                    ]],
                ],
                'overlap_policy' => 'allow_all',
                'jitter_seconds' => 0,
                'max_runs' => 1,
            ])
            ->assertCreated()
            ->assertJsonPath('schedule_id', 'python-created-php-worker');

        WorkflowSchedule::query()
            ->where('schedule_id', 'python-created-php-worker')
            ->update(['next_fire_at' => now()->subSecond()]);

        $exitCode = Artisan::call('schedule:evaluate', ['--json' => true]);
        $report = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, $report['fired_count']);

        $run = WorkflowRun::query()
            ->where('workflow_type', $workflowType)
            ->firstOrFail();
        $this->assertNull($run->compatibility);

        $task = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->firstOrFail();
        $this->assertNull($task->compatibility);

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'schedules-php-worker',
                'task_queue' => $taskQueue,
            ])
            ->assertOk()
            ->assertJsonPath('poll_status', 'leased')
            ->assertJsonPath('task.workflow_id', $run->workflow_instance_id)
            ->assertJsonPath('task.workflow_type', $workflowType)
            ->assertJsonPath('task.compatibility', null);
    }

    public function test_scheduled_polyglot_start_pins_to_target_worker_cohort_on_shared_queue(): void
    {
        config()->set('workflows.v2.compatibility.current', 'server-image-build');
        config()->set('workflows.v2.compatibility.supported', ['server-image-build']);

        $phpWorkflowType = 'SchedulesConformancePhpWorkflow';
        $pythonWorkflowType = 'SchedulesConformancePythonWorkflow';
        $taskQueue = 'schedules-shared-builds';

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', [
                'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
                'worker_id' => 'schedules-php-worker-build',
                'task_queue' => $taskQueue,
                'runtime' => 'php',
                'sdk_version' => '2.0.0-alpha.test',
                'build_id' => 'workflow-php-build',
                'supported_workflow_types' => [$phpWorkflowType],
                'workflow_definition_fingerprints' => [
                    $phpWorkflowType => 'schedules-conformance:SchedulesConformancePhpWorkflow:php',
                ],
                'supported_activity_types' => [],
                'max_concurrent_workflow_tasks' => 10,
                'max_concurrent_activity_tasks' => 10,
                'task_slots' => [
                    'workflow_available' => 10,
                    'activity_available' => 10,
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('build_id', 'workflow-php-build');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', [
                'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
                'worker_id' => 'schedules-python-worker-build',
                'task_queue' => $taskQueue,
                'runtime' => 'python',
                'sdk_version' => '0.4.test',
                'build_id' => 'sdk-python-build',
                'supported_workflow_types' => [$pythonWorkflowType],
                'workflow_definition_fingerprints' => [
                    $pythonWorkflowType => 'schedules-conformance:SchedulesConformancePythonWorkflow:python',
                ],
                'supported_activity_types' => [],
                'max_concurrent_workflow_tasks' => 10,
                'max_concurrent_activity_tasks' => 10,
                'task_slots' => [
                    'workflow_available' => 10,
                    'activity_available' => 10,
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('build_id', 'sdk-python-build');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/schedules', [
                'schedule_id' => 'python-created-php-worker-build',
                'spec' => [
                    'intervals' => [['every' => 'PT30S']],
                    'timezone' => 'UTC',
                ],
                'action' => [
                    'workflow_type' => $phpWorkflowType,
                    'task_queue' => $taskQueue,
                    'input' => [[
                        'scenario' => 'python_created_php_workflow',
                        'schedule_creator' => 'sdk-python',
                        'workflow_runtime' => 'workflow-php',
                    ]],
                ],
                'overlap_policy' => 'allow_all',
                'jitter_seconds' => 0,
                'max_runs' => 1,
            ])
            ->assertCreated()
            ->assertJsonPath('schedule_id', 'python-created-php-worker-build');

        WorkflowSchedule::query()
            ->where('schedule_id', 'python-created-php-worker-build')
            ->update(['next_fire_at' => now()->subSecond()]);

        $exitCode = Artisan::call('schedule:evaluate', ['--json' => true]);
        $report = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, $report['fired_count']);

        $run = WorkflowRun::query()
            ->where('workflow_type', $phpWorkflowType)
            ->firstOrFail();
        $this->assertSame('workflow-php-build', $run->compatibility);

        $task = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->firstOrFail();
        $this->assertSame('workflow-php-build', $task->compatibility);

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'schedules-php-worker-build',
                'task_queue' => $taskQueue,
            ])
            ->assertOk()
            ->assertJsonPath('poll_status', 'leased')
            ->assertJsonPath('task.workflow_id', $run->workflow_instance_id)
            ->assertJsonPath('task.workflow_type', $phpWorkflowType)
            ->assertJsonPath('task.compatibility', 'workflow-php-build');
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
     * @return array<string, string>
     */
    private function apiHeaders(): array
    {
        return [
            'X-Namespace' => 'default',
            'X-Durable-Workflow-Control-Plane-Version' => '2',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function workerHeaders(): array
    {
        return [
            'X-Namespace' => 'default',
            WorkerProtocol::HEADER => WorkerProtocol::VERSION,
        ];
    }

    /**
     * Bind a fake WorkflowStartService into the container.
     */
    private function fakeStartService(
        ?array $result = null,
        ?\Throwable $exception = null,
        ?\Closure $callback = null,
    ): void {
        $this->mock(WorkflowStartService::class, function (MockInterface $mock) use ($result, $exception, $callback): void {
            $mock->shouldReceive('start')
                ->zeroOrMoreTimes()
                ->andReturnUsing(function (
                    array $validated,
                    ?string $namespace = null,
                    mixed $commandContext = null,
                    bool $allowAmbientCompatibilityFallback = true,
                ) use ($result, $exception, $callback): array {
                    if ($exception) {
                        throw $exception;
                    }

                    if ($callback) {
                        return ($callback)($validated, $namespace);
                    }

                    return $result;
                });
        });
    }

    private function createWorkflowWithRun(string $workflowId, string $runId, string $status): void
    {
        WorkflowInstance::forceCreate([
            'id' => $workflowId,
            'workflow_class' => 'TestWorkflow',
            'workflow_type' => 'TestWorkflow',
            'namespace' => 'default',
        ]);

        WorkflowRun::forceCreate([
            'id' => $runId,
            'workflow_instance_id' => $workflowId,
            'workflow_class' => 'TestWorkflow',
            'workflow_type' => 'TestWorkflow',
            'run_number' => 1,
            'status' => $status,
            'namespace' => 'default',
        ]);
    }
}
