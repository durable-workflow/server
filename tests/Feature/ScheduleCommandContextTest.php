<?php

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use App\Support\ControlPlaneProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowSchedule;
use Workflow\V2\Models\WorkflowScheduleHistoryEvent;

/**
 * Pins TD-S035: every mutating /api/schedules endpoint must build a
 * CommandContext from the request and thread it into the workflow
 * package so schedule history events carry request/auth attribution.
 *
 * Without this, schedule auditing is blind to who triggered what
 * through the HTTP control plane.
 */
class ScheduleCommandContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        WorkflowNamespace::query()->updateOrCreate(
            ['name' => 'default'],
            ['description' => 'default', 'retention_days' => 30, 'status' => 'active'],
        );
    }

    public function test_schedule_create_records_command_context(): void
    {
        $this->withHeaders($this->headers())->postJson('/api/schedules', [
            'schedule_id' => 'ctx-create',
            'spec' => ['cron_expressions' => ['0 0 * * *']],
            'action' => ['workflow_type' => 'TestWorkflow'],
        ])->assertCreated();

        $this->assertCommandContextRecorded('ctx-create', HistoryEventType::ScheduleCreated, 'schedule.create');
    }

    public function test_schedule_update_records_command_context(): void
    {
        $this->createSchedule('ctx-update');

        $this->withHeaders($this->headers())->putJson('/api/schedules/ctx-update', [
            'note' => 'adjusted window',
        ])->assertOk();

        $this->assertCommandContextRecorded('ctx-update', HistoryEventType::ScheduleUpdated, 'schedule.update');
    }

    public function test_schedule_pause_records_command_context(): void
    {
        $this->createSchedule('ctx-pause');

        $this->withHeaders($this->headers())->postJson('/api/schedules/ctx-pause/pause', [])
            ->assertOk();

        $this->assertCommandContextRecorded('ctx-pause', HistoryEventType::SchedulePaused, 'schedule.pause');
    }

    public function test_schedule_resume_records_command_context(): void
    {
        $schedule = $this->createSchedule('ctx-resume');
        \Workflow\V2\Support\ScheduleManager::pause($schedule);

        $this->withHeaders($this->headers())->postJson('/api/schedules/ctx-resume/resume', [])
            ->assertOk();

        $this->assertCommandContextRecorded('ctx-resume', HistoryEventType::ScheduleResumed, 'schedule.resume');
    }

    public function test_schedule_delete_records_command_context(): void
    {
        $this->createSchedule('ctx-delete');

        $this->withHeaders($this->headers())->deleteJson('/api/schedules/ctx-delete')
            ->assertOk();

        $this->assertCommandContextRecorded('ctx-delete', HistoryEventType::ScheduleDeleted, 'schedule.delete');
    }

    public function test_schedule_create_with_paused_records_context_on_both_events(): void
    {
        $this->withHeaders($this->headers())->postJson('/api/schedules', [
            'schedule_id' => 'ctx-paused-at-create',
            'spec' => ['cron_expressions' => ['0 0 * * *']],
            'action' => ['workflow_type' => 'TestWorkflow'],
            'paused' => true,
        ])->assertCreated();

        $this->assertCommandContextRecorded('ctx-paused-at-create', HistoryEventType::ScheduleCreated, 'schedule.create');
        $this->assertCommandContextRecorded('ctx-paused-at-create', HistoryEventType::SchedulePaused, 'schedule.pause');
    }

    private function createSchedule(string $scheduleId): WorkflowSchedule
    {
        return WorkflowSchedule::create([
            'schedule_id' => $scheduleId,
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['0 0 * * *']],
            'action' => ['workflow_type' => 'TestWorkflow'],
            'status' => 'active',
            'overlap_policy' => 'skip',
            'fires_count' => 0,
            'failures_count' => 0,
        ]);
    }

    /**
     * Assert that a history event of the given type recorded a command_context
     * payload with the expected server.command attribution.
     */
    private function assertCommandContextRecorded(
        string $scheduleId,
        HistoryEventType $eventType,
        string $expectedCommand,
    ): void {
        $schedule = WorkflowSchedule::query()
            ->where('schedule_id', $scheduleId)
            ->firstOrFail();

        $event = WorkflowScheduleHistoryEvent::query()
            ->where('workflow_schedule_id', $schedule->id)
            ->where('event_type', $eventType->value)
            ->latest('id')
            ->first();

        $this->assertNotNull(
            $event,
            "No {$eventType->value} event found for schedule [{$scheduleId}].",
        );

        $payload = is_array($event->payload) ? $event->payload : [];
        $attributes = $payload['command_context'] ?? null;

        $this->assertIsArray(
            $attributes,
            "Event {$eventType->value} for [{$scheduleId}] is missing a command_context payload.",
        );

        $this->assertSame('control_plane', $attributes['source'] ?? null);

        $contextPayload = $attributes['context'] ?? [];
        $this->assertIsArray($contextPayload);

        $server = $contextPayload['server'] ?? null;
        $this->assertIsArray($server);
        $this->assertSame($expectedCommand, $server['command'] ?? null);
        $this->assertSame($scheduleId, $server['workflow_id'] ?? null);
        $this->assertSame('default', $server['namespace'] ?? null);
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'X-Namespace' => 'default',
            'X-Durable-Workflow-Control-Plane-Version' => ControlPlaneProtocol::VERSION,
        ];
    }
}
