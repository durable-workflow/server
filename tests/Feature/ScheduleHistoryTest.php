<?php

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowSchedule;
use Workflow\V2\Models\WorkflowScheduleHistoryEvent;
use Workflow\V2\Support\ScheduleManager;

/**
 * Covers GET /api/schedules/{scheduleId}/history: the standalone server's
 * non-Waterline surface for the per-schedule audit stream recorded on
 * workflow_schedule_history_events. The Waterline HTTP endpoint at
 * /waterline/api/v2/schedules/{scheduleId}/history exposes the same
 * stream inside Waterline deployments; this endpoint makes the stream
 * available to CLI and SDK clients going through the standalone control
 * plane.
 */
class ScheduleHistoryTest extends TestCase
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

    public function test_history_returns_404_for_missing_schedule(): void
    {
        $response = $this->withHeaders($this->headers())
            ->getJson('/api/schedules/does-not-exist/history');

        $response->assertStatus(404)
            ->assertJsonPath('reason', 'schedule_not_found');
    }

    public function test_history_returns_events_in_sequence_order(): void
    {
        $schedule = $this->createSchedule('history-order');

        ScheduleManager::pause($schedule);
        ScheduleManager::resume($schedule);
        ScheduleManager::pause($schedule);

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/schedules/history-order/history');

        $response->assertOk()
            ->assertJsonPath('schedule_id', 'history-order')
            ->assertJsonPath('namespace', 'default')
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('next_cursor', null);

        $events = $response->json('events');
        $this->assertIsArray($events);
        $this->assertCount(3, $events);

        $sequences = array_column($events, 'sequence');
        $this->assertSame($sequences, [1, 2, 3]);

        $types = array_column($events, 'event_type');
        $this->assertSame([
            HistoryEventType::SchedulePaused->value,
            HistoryEventType::ScheduleResumed->value,
            HistoryEventType::SchedulePaused->value,
        ], $types);

        foreach ($events as $event) {
            $this->assertArrayHasKey('id', $event);
            $this->assertArrayHasKey('payload', $event);
            $this->assertArrayHasKey('recorded_at', $event);
            $this->assertIsInt($event['sequence']);
        }
    }

    public function test_history_scopes_events_to_the_requesting_namespace(): void
    {
        WorkflowNamespace::query()->updateOrCreate(
            ['name' => 'secondary'],
            ['description' => 'secondary', 'retention_days' => 30, 'status' => 'active'],
        );

        $primary = $this->createSchedule('ns-scoped', namespace: 'default');
        $secondary = $this->createSchedule('ns-scoped', namespace: 'secondary');

        ScheduleManager::pause($primary);
        ScheduleManager::pause($secondary);
        ScheduleManager::resume($secondary);

        $defaultResponse = $this->withHeaders($this->headers('default'))
            ->getJson('/api/schedules/ns-scoped/history');

        $defaultResponse->assertOk();
        $this->assertCount(1, $defaultResponse->json('events'));
        $this->assertSame(
            HistoryEventType::SchedulePaused->value,
            $defaultResponse->json('events.0.event_type'),
        );

        $secondaryResponse = $this->withHeaders($this->headers('secondary'))
            ->getJson('/api/schedules/ns-scoped/history');

        $secondaryResponse->assertOk();
        $this->assertCount(2, $secondaryResponse->json('events'));
    }

    public function test_history_paginates_with_after_sequence(): void
    {
        $schedule = $this->createSchedule('history-paginate');

        for ($i = 0; $i < 5; $i++) {
            ScheduleManager::pause($schedule);
            ScheduleManager::resume($schedule);
        }

        $firstPage = $this->withHeaders($this->headers())
            ->getJson('/api/schedules/history-paginate/history?limit=4');

        $firstPage->assertOk()
            ->assertJsonPath('has_more', true)
            ->assertJsonPath('next_cursor', 4);

        $this->assertCount(4, $firstPage->json('events'));
        $this->assertSame([1, 2, 3, 4], array_column($firstPage->json('events'), 'sequence'));

        $secondPage = $this->withHeaders($this->headers())
            ->getJson('/api/schedules/history-paginate/history?limit=4&after_sequence=4');

        $secondPage->assertOk()
            ->assertJsonPath('has_more', true)
            ->assertJsonPath('next_cursor', 8);

        $this->assertSame([5, 6, 7, 8], array_column($secondPage->json('events'), 'sequence'));

        $thirdPage = $this->withHeaders($this->headers())
            ->getJson('/api/schedules/history-paginate/history?limit=4&after_sequence=8');

        $thirdPage->assertOk()
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('next_cursor', null);

        $this->assertSame([9, 10], array_column($thirdPage->json('events'), 'sequence'));
    }

    public function test_history_defaults_limit_and_clamps_to_maximum(): void
    {
        $schedule = $this->createSchedule('history-limit');

        $limitResponse = $this->withHeaders($this->headers())
            ->getJson('/api/schedules/history-limit/history?limit=5000');

        $limitResponse->assertOk();
        $this->assertSame([], $limitResponse->json('events'));
        $this->assertSame(false, $limitResponse->json('has_more'));

        ScheduleManager::pause($schedule);

        $defaultResponse = $this->withHeaders($this->headers())
            ->getJson('/api/schedules/history-limit/history');

        $defaultResponse->assertOk();
        $this->assertCount(1, $defaultResponse->json('events'));
    }

    public function test_history_rejects_invalid_after_sequence(): void
    {
        $this->createSchedule('history-invalid-cursor');

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/schedules/history-invalid-cursor/history?after_sequence=not-a-number');

        $response->assertStatus(422)
            ->assertJsonPath('reason', 'invalid_after_sequence');

        $negativeResponse = $this->withHeaders($this->headers())
            ->getJson('/api/schedules/history-invalid-cursor/history?after_sequence=-3');

        $negativeResponse->assertStatus(422)
            ->assertJsonPath('reason', 'invalid_after_sequence');
    }

    public function test_history_is_available_for_deleted_schedules(): void
    {
        $schedule = $this->createSchedule('history-deleted');

        ScheduleManager::pause($schedule);

        $this->withHeaders($this->headers())
            ->deleteJson('/api/schedules/history-deleted')
            ->assertOk();

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/schedules/history-deleted/history');

        $response->assertOk();

        $types = array_column($response->json('events'), 'event_type');
        $this->assertContains(HistoryEventType::SchedulePaused->value, $types);
        $this->assertContains(HistoryEventType::ScheduleDeleted->value, $types);
    }

    public function test_history_surfaces_workflow_instance_and_run_ids_when_recorded(): void
    {
        $schedule = $this->createSchedule('history-instance');

        WorkflowScheduleHistoryEvent::record(
            $schedule,
            HistoryEventType::ScheduleTriggered,
            [
                'workflow_instance_id' => 'wf-instance-xyz',
                'workflow_run_id' => 'run-xyz',
                'outcome' => 'triggered',
                'trigger_number' => 1,
            ],
        );

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/schedules/history-instance/history');

        $response->assertOk();

        $event = $response->json('events.0');
        $this->assertSame(HistoryEventType::ScheduleTriggered->value, $event['event_type']);
        $this->assertSame('wf-instance-xyz', $event['workflow_instance_id']);
        $this->assertSame('run-xyz', $event['workflow_run_id']);
        $this->assertSame('triggered', $event['payload']['outcome'] ?? null);
        $this->assertSame(1, $event['payload']['trigger_number'] ?? null);
    }

    private function createSchedule(string $scheduleId, string $namespace = 'default'): WorkflowSchedule
    {
        return WorkflowSchedule::create([
            'schedule_id' => $scheduleId,
            'namespace' => $namespace,
            'spec' => ['cron_expressions' => ['0 0 * * *']],
            'action' => ['workflow_type' => 'TestWorkflow'],
            'status' => 'active',
            'overlap_policy' => 'skip',
            'fires_count' => 0,
            'failures_count' => 0,
        ]);
    }

    private function headers(string $namespace = 'default'): array
    {
        return [
            'X-Namespace' => $namespace,
            'X-Durable-Workflow-Control-Plane-Version' => \App\Support\ControlPlaneProtocol::VERSION,
        ];
    }
}
