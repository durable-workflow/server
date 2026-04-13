<?php

namespace Tests\Unit;

use App\Models\WorkflowSchedule;
use App\Support\ScheduleOverlapEnforcer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;
use Workflow\V2\Contracts\WorkflowControlPlane;

class ScheduleOverlapEnforcerTest extends TestCase
{
    use RefreshDatabase;

    // ── Buffer policy detection ────────────────────────────────────

    public function test_buffer_one_is_buffer_policy(): void
    {
        $enforcer = $this->makeEnforcer();

        $this->assertTrue($enforcer->isBufferPolicy('buffer_one'));
    }

    public function test_buffer_all_is_buffer_policy(): void
    {
        $enforcer = $this->makeEnforcer();

        $this->assertTrue($enforcer->isBufferPolicy('buffer_all'));
    }

    /**
     * @dataProvider nonBufferPoliciesProvider
     */
    public function test_non_buffer_policies_are_not_flagged_as_buffer(string $policy): void
    {
        $enforcer = $this->makeEnforcer();

        $this->assertFalse($enforcer->isBufferPolicy($policy));
    }

    public static function nonBufferPoliciesProvider(): array
    {
        return [
            'skip' => ['skip'],
            'cancel_other' => ['cancel_other'],
            'terminate_other' => ['terminate_other'],
            'allow_all' => ['allow_all'],
        ];
    }

    // ── Duplicate start policy mapping ─────────────────────────────

    public function test_skip_maps_to_use_existing(): void
    {
        $enforcer = $this->makeEnforcer();

        $this->assertSame('use-existing', $enforcer->duplicateStartPolicy('skip'));
    }

    /**
     * @dataProvider nonSkipPoliciesProvider
     */
    public function test_non_skip_policies_map_to_null(string $policy): void
    {
        $enforcer = $this->makeEnforcer();

        $this->assertNull($enforcer->duplicateStartPolicy($policy));
    }

    public static function nonSkipPoliciesProvider(): array
    {
        return [
            'cancel_other' => ['cancel_other'],
            'terminate_other' => ['terminate_other'],
            'allow_all' => ['allow_all'],
            'buffer_one' => ['buffer_one'],
            'buffer_all' => ['buffer_all'],
        ];
    }

    // ── Enforce cancel_other ───────────────────────────────────────

    public function test_cancel_other_calls_cancel_on_last_workflow(): void
    {
        $controlPlane = $this->mock(WorkflowControlPlane::class, function (MockInterface $mock): void {
            $mock->shouldReceive('cancel')
                ->once()
                ->with('wf-prev-1', \Mockery::on(static fn (array $opts): bool => str_contains($opts['reason'] ?? '', 'cancel_other')));
        });

        $enforcer = new ScheduleOverlapEnforcer($controlPlane);

        $schedule = new WorkflowSchedule([
            'recent_actions' => [
                ['workflow_id' => 'wf-prev-1', 'run_id' => 'run-1', 'outcome' => 'started'],
            ],
        ]);

        $enforcer->enforce($schedule, 'cancel_other');
    }

    // ── Enforce terminate_other ────────────────────────────────────

    public function test_terminate_other_calls_terminate_on_last_workflow(): void
    {
        $controlPlane = $this->mock(WorkflowControlPlane::class, function (MockInterface $mock): void {
            $mock->shouldReceive('terminate')
                ->once()
                ->with('wf-prev-2', \Mockery::on(static fn (array $opts): bool => str_contains($opts['reason'] ?? '', 'terminate_other')));
        });

        $enforcer = new ScheduleOverlapEnforcer($controlPlane);

        $schedule = new WorkflowSchedule([
            'recent_actions' => [
                ['workflow_id' => 'wf-prev-2', 'run_id' => 'run-2', 'outcome' => 'started'],
            ],
        ]);

        $enforcer->enforce($schedule, 'terminate_other');
    }

    // ── No-op for non-enforcement policies ─────────────────────────

    /**
     * @dataProvider nonEnforcementPoliciesProvider
     */
    public function test_non_enforcement_policies_do_not_call_control_plane(string $policy): void
    {
        $controlPlane = $this->mock(WorkflowControlPlane::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('cancel');
            $mock->shouldNotReceive('terminate');
        });

        $enforcer = new ScheduleOverlapEnforcer($controlPlane);

        $schedule = new WorkflowSchedule([
            'recent_actions' => [
                ['workflow_id' => 'wf-1', 'run_id' => 'run-1', 'outcome' => 'started'],
            ],
        ]);

        $enforcer->enforce($schedule, $policy);
    }

    public static function nonEnforcementPoliciesProvider(): array
    {
        return [
            'skip' => ['skip'],
            'allow_all' => ['allow_all'],
            'buffer_one' => ['buffer_one'],
            'buffer_all' => ['buffer_all'],
        ];
    }

    // ── No-op when no recent actions ───────────────────────────────

    public function test_cancel_other_is_noop_when_no_recent_actions(): void
    {
        $controlPlane = $this->mock(WorkflowControlPlane::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('cancel');
        });

        $enforcer = new ScheduleOverlapEnforcer($controlPlane);

        $schedule = new WorkflowSchedule(['recent_actions' => []]);

        $enforcer->enforce($schedule, 'cancel_other');
    }

    public function test_cancel_other_is_noop_when_last_action_has_no_workflow_id(): void
    {
        $controlPlane = $this->mock(WorkflowControlPlane::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('cancel');
        });

        $enforcer = new ScheduleOverlapEnforcer($controlPlane);

        $schedule = new WorkflowSchedule([
            'recent_actions' => [
                ['outcome' => 'failed', 'reason' => 'boom'],
            ],
        ]);

        $enforcer->enforce($schedule, 'cancel_other');
    }

    // ── lastFiredWorkflowIsRunning ─────────────────────────────────

    public function test_last_fired_workflow_is_running_returns_true_when_describe_reports_running(): void
    {
        $controlPlane = $this->mock(WorkflowControlPlane::class, function (MockInterface $mock): void {
            $mock->shouldReceive('describe')
                ->once()
                ->with('wf-running-1', ['run_id' => 'run-1'])
                ->andReturn([
                    'found' => true,
                    'run' => ['status_bucket' => 'running'],
                ]);
        });

        $enforcer = new ScheduleOverlapEnforcer($controlPlane);

        $schedule = new WorkflowSchedule([
            'recent_actions' => [
                ['workflow_id' => 'wf-running-1', 'run_id' => 'run-1', 'outcome' => 'started'],
            ],
        ]);

        $this->assertTrue($enforcer->lastFiredWorkflowIsRunning($schedule));
    }

    public function test_last_fired_workflow_is_running_returns_false_when_describe_reports_completed(): void
    {
        $controlPlane = $this->mock(WorkflowControlPlane::class, function (MockInterface $mock): void {
            $mock->shouldReceive('describe')
                ->once()
                ->with('wf-done-1', ['run_id' => 'run-1'])
                ->andReturn([
                    'found' => true,
                    'run' => ['status_bucket' => 'completed'],
                ]);
        });

        $enforcer = new ScheduleOverlapEnforcer($controlPlane);

        $schedule = new WorkflowSchedule([
            'recent_actions' => [
                ['workflow_id' => 'wf-done-1', 'run_id' => 'run-1', 'outcome' => 'started'],
            ],
        ]);

        $this->assertFalse($enforcer->lastFiredWorkflowIsRunning($schedule));
    }

    public function test_last_fired_workflow_is_running_returns_false_when_no_recent_actions(): void
    {
        $controlPlane = $this->mock(WorkflowControlPlane::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('describe');
        });

        $enforcer = new ScheduleOverlapEnforcer($controlPlane);

        $schedule = new WorkflowSchedule(['recent_actions' => []]);

        $this->assertFalse($enforcer->lastFiredWorkflowIsRunning($schedule));
    }

    public function test_last_fired_workflow_is_running_returns_false_when_workflow_not_found(): void
    {
        $controlPlane = $this->mock(WorkflowControlPlane::class, function (MockInterface $mock): void {
            $mock->shouldReceive('describe')
                ->once()
                ->with('wf-gone', ['run_id' => 'nonexistent-run'])
                ->andReturn([
                    'found' => false,
                ]);
        });

        $enforcer = new ScheduleOverlapEnforcer($controlPlane);

        $schedule = new WorkflowSchedule([
            'recent_actions' => [
                ['workflow_id' => 'wf-gone', 'run_id' => 'nonexistent-run', 'outcome' => 'started'],
            ],
        ]);

        $this->assertFalse($enforcer->lastFiredWorkflowIsRunning($schedule));
    }

    private function makeEnforcer(): ScheduleOverlapEnforcer
    {
        return new ScheduleOverlapEnforcer(
            $this->mock(WorkflowControlPlane::class),
        );
    }
}
