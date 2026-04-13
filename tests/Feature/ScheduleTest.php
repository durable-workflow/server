<?php

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use App\Models\WorkflowSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowRunSummary;

class ScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createNamespace('default');
    }

    // ── List ─────────────────────────────────────────────────────────

    public function test_it_lists_empty_schedules(): void
    {
        $response = $this->withHeaders($this->headers())
            ->getJson('/api/schedules');

        $response->assertOk()
            ->assertJsonPath('schedules', [])
            ->assertJsonPath('next_page_token', null);
    }

    public function test_it_lists_schedules_in_namespace(): void
    {
        WorkflowSchedule::create([
            'schedule_id' => 'daily-report',
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['0 9 * * *'], 'timezone' => 'UTC'],
            'action' => ['workflow_type' => 'DailyReportWorkflow'],
        ]);

        WorkflowSchedule::create([
            'schedule_id' => 'hourly-sync',
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['0 * * * *'], 'timezone' => 'UTC'],
            'action' => ['workflow_type' => 'HourlySyncWorkflow'],
        ]);

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/schedules');

        $response->assertOk();
        $this->assertCount(2, $response->json('schedules'));
    }

    public function test_it_scopes_schedules_to_namespace(): void
    {
        $this->createNamespace('other');

        WorkflowSchedule::create([
            'schedule_id' => 'default-sched',
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['0 9 * * *']],
            'action' => ['workflow_type' => 'DefaultWorkflow'],
        ]);

        WorkflowSchedule::create([
            'schedule_id' => 'other-sched',
            'namespace' => 'other',
            'spec' => ['cron_expressions' => ['0 9 * * *']],
            'action' => ['workflow_type' => 'OtherWorkflow'],
        ]);

        $defaultResponse = $this->withHeaders($this->headers('default'))
            ->getJson('/api/schedules');

        $defaultResponse->assertOk();
        $this->assertCount(1, $defaultResponse->json('schedules'));
        $this->assertEquals('default-sched', $defaultResponse->json('schedules.0.schedule_id'));

        $otherResponse = $this->withHeaders($this->headers('other'))
            ->getJson('/api/schedules');

        $otherResponse->assertOk();
        $this->assertCount(1, $otherResponse->json('schedules'));
        $this->assertEquals('other-sched', $otherResponse->json('schedules.0.schedule_id'));
    }

    // ── Create ───────────────────────────────────────────────────────

    public function test_it_creates_a_schedule(): void
    {
        $response = $this->withHeaders($this->headers())
            ->postJson('/api/schedules', [
                'schedule_id' => 'nightly-cleanup',
                'spec' => [
                    'cron_expressions' => ['0 2 * * *'],
                    'timezone' => 'UTC',
                ],
                'action' => [
                    'workflow_type' => 'CleanupWorkflow',
                    'task_queue' => 'maintenance',
                ],
                'overlap_policy' => 'skip',
                'note' => 'Nightly data cleanup',
            ]);

        $response->assertCreated()
            ->assertJsonPath('schedule_id', 'nightly-cleanup')
            ->assertJsonPath('outcome', 'created');

        $this->assertDatabaseHas('workflow_schedules', [
            'schedule_id' => 'nightly-cleanup',
            'namespace' => 'default',
            'overlap_policy' => 'skip',
            'paused' => false,
            'note' => 'Nightly data cleanup',
        ]);
    }

    public function test_it_generates_schedule_id_when_not_provided(): void
    {
        $response = $this->withHeaders($this->headers())
            ->postJson('/api/schedules', [
                'spec' => ['cron_expressions' => ['0 * * * *']],
                'action' => ['workflow_type' => 'TestWorkflow'],
            ]);

        $response->assertCreated();
        $this->assertNotEmpty($response->json('schedule_id'));
    }

    public function test_it_creates_a_paused_schedule(): void
    {
        $response = $this->withHeaders($this->headers())
            ->postJson('/api/schedules', [
                'schedule_id' => 'paused-sched',
                'spec' => ['cron_expressions' => ['0 * * * *']],
                'action' => ['workflow_type' => 'TestWorkflow'],
                'paused' => true,
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('workflow_schedules', [
            'schedule_id' => 'paused-sched',
            'paused' => true,
        ]);
    }

    public function test_it_rejects_duplicate_schedule_ids(): void
    {
        WorkflowSchedule::create([
            'schedule_id' => 'existing',
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['0 * * * *']],
            'action' => ['workflow_type' => 'TestWorkflow'],
        ]);

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/schedules', [
                'schedule_id' => 'existing',
                'spec' => ['cron_expressions' => ['0 * * * *']],
                'action' => ['workflow_type' => 'OtherWorkflow'],
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('reason', 'schedule_already_exists');
    }

    public function test_it_validates_required_fields(): void
    {
        $this->withHeaders($this->headers())
            ->postJson('/api/schedules', [])
            ->assertStatus(422);

        $this->withHeaders($this->headers())
            ->postJson('/api/schedules', [
                'spec' => ['cron_expressions' => ['0 * * * *']],
            ])
            ->assertStatus(422);
    }

    public function test_it_validates_overlap_policy(): void
    {
        $this->withHeaders($this->headers())
            ->postJson('/api/schedules', [
                'spec' => ['cron_expressions' => ['0 * * * *']],
                'action' => ['workflow_type' => 'TestWorkflow'],
                'overlap_policy' => 'invalid_policy',
            ])
            ->assertStatus(422);
    }

    public function test_it_computes_next_fire_at_on_create(): void
    {
        $response = $this->withHeaders($this->headers())
            ->postJson('/api/schedules', [
                'schedule_id' => 'with-next-fire',
                'spec' => ['cron_expressions' => ['0 * * * *'], 'timezone' => 'UTC'],
                'action' => ['workflow_type' => 'TestWorkflow'],
            ]);

        $response->assertCreated();

        $schedule = WorkflowSchedule::where('schedule_id', 'with-next-fire')->first();
        $this->assertNotNull($schedule->next_fire_at);
    }

    // ── Show ─────────────────────────────────────────────────────────

    public function test_it_shows_schedule_detail(): void
    {
        WorkflowSchedule::create([
            'schedule_id' => 'detail-test',
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['0 9 * * *'], 'timezone' => 'America/New_York'],
            'action' => ['workflow_type' => 'ReportWorkflow', 'task_queue' => 'reports'],
            'overlap_policy' => 'skip',
            'note' => 'Daily report',
        ]);

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/schedules/detail-test');

        $response->assertOk()
            ->assertJsonPath('schedule_id', 'detail-test')
            ->assertJsonPath('spec.cron_expressions.0', '0 9 * * *')
            ->assertJsonPath('spec.timezone', 'America/New_York')
            ->assertJsonPath('action.workflow_type', 'ReportWorkflow')
            ->assertJsonPath('action.task_queue', 'reports')
            ->assertJsonPath('overlap_policy', 'skip')
            ->assertJsonPath('state.paused', false)
            ->assertJsonPath('state.note', 'Daily report')
            ->assertJsonPath('info.fires_count', 0)
            ->assertJsonPath('info.failures_count', 0);
    }

    public function test_it_returns_404_for_nonexistent_schedule(): void
    {
        $response = $this->withHeaders($this->headers())
            ->getJson('/api/schedules/nonexistent');

        $response->assertNotFound()
            ->assertJsonPath('reason', 'schedule_not_found');
    }

    // ── Update ───────────────────────────────────────────────────────

    public function test_it_updates_a_schedule(): void
    {
        WorkflowSchedule::create([
            'schedule_id' => 'updatable',
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['0 * * * *']],
            'action' => ['workflow_type' => 'OriginalWorkflow'],
            'overlap_policy' => 'skip',
        ]);

        $response = $this->withHeaders($this->headers())
            ->putJson('/api/schedules/updatable', [
                'spec' => ['cron_expressions' => ['0 */2 * * *'], 'timezone' => 'UTC'],
                'action' => ['workflow_type' => 'UpdatedWorkflow'],
                'overlap_policy' => 'allow_all',
                'note' => 'Updated schedule',
            ]);

        $response->assertOk()
            ->assertJsonPath('schedule_id', 'updatable')
            ->assertJsonPath('outcome', 'updated');

        $schedule = WorkflowSchedule::where('schedule_id', 'updatable')->first();
        $this->assertEquals(['cron_expressions' => ['0 */2 * * *'], 'timezone' => 'UTC'], $schedule->spec);
        $this->assertEquals('UpdatedWorkflow', $schedule->action['workflow_type']);
        $this->assertEquals('allow_all', $schedule->overlap_policy);
        $this->assertEquals('Updated schedule', $schedule->note);
    }

    public function test_it_partially_updates_a_schedule(): void
    {
        WorkflowSchedule::create([
            'schedule_id' => 'partial',
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['0 * * * *']],
            'action' => ['workflow_type' => 'OriginalWorkflow'],
            'note' => 'Original note',
        ]);

        $response = $this->withHeaders($this->headers())
            ->putJson('/api/schedules/partial', [
                'note' => 'Updated note only',
            ]);

        $response->assertOk();

        $schedule = WorkflowSchedule::where('schedule_id', 'partial')->first();
        $this->assertEquals(['cron_expressions' => ['0 * * * *']], $schedule->spec);
        $this->assertEquals('OriginalWorkflow', $schedule->action['workflow_type']);
        $this->assertEquals('Updated note only', $schedule->note);
    }

    public function test_update_returns_404_for_nonexistent_schedule(): void
    {
        $this->withHeaders($this->headers())
            ->putJson('/api/schedules/nonexistent', ['note' => 'test'])
            ->assertNotFound();
    }

    // ── Delete ───────────────────────────────────────────────────────

    public function test_it_deletes_a_schedule(): void
    {
        WorkflowSchedule::create([
            'schedule_id' => 'deletable',
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['0 * * * *']],
            'action' => ['workflow_type' => 'TestWorkflow'],
        ]);

        $response = $this->withHeaders($this->headers())
            ->deleteJson('/api/schedules/deletable');

        $response->assertOk()
            ->assertJsonPath('schedule_id', 'deletable')
            ->assertJsonPath('outcome', 'deleted');

        $this->assertDatabaseMissing('workflow_schedules', [
            'schedule_id' => 'deletable',
        ]);
    }

    public function test_delete_returns_404_for_nonexistent_schedule(): void
    {
        $this->withHeaders($this->headers())
            ->deleteJson('/api/schedules/nonexistent')
            ->assertNotFound();
    }

    public function test_delete_is_namespace_scoped(): void
    {
        $this->createNamespace('other');

        WorkflowSchedule::create([
            'schedule_id' => 'scoped',
            'namespace' => 'other',
            'spec' => ['cron_expressions' => ['0 * * * *']],
            'action' => ['workflow_type' => 'TestWorkflow'],
        ]);

        $response = $this->withHeaders($this->headers('default'))
            ->deleteJson('/api/schedules/scoped');

        $response->assertNotFound();

        $this->assertDatabaseHas('workflow_schedules', [
            'schedule_id' => 'scoped',
            'namespace' => 'other',
        ]);
    }

    // ── Pause / Resume ──────────────────────────────────────────────

    public function test_it_pauses_a_schedule(): void
    {
        WorkflowSchedule::create([
            'schedule_id' => 'pausable',
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['0 * * * *']],
            'action' => ['workflow_type' => 'TestWorkflow'],
            'paused' => false,
        ]);

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/schedules/pausable/pause', [
                'note' => 'Paused for maintenance',
            ]);

        $response->assertOk()
            ->assertJsonPath('schedule_id', 'pausable')
            ->assertJsonPath('outcome', 'paused');

        $schedule = WorkflowSchedule::where('schedule_id', 'pausable')->first();
        $this->assertTrue($schedule->paused);
        $this->assertEquals('Paused for maintenance', $schedule->note);
    }

    public function test_it_resumes_a_paused_schedule(): void
    {
        WorkflowSchedule::create([
            'schedule_id' => 'resumable',
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['0 * * * *']],
            'action' => ['workflow_type' => 'TestWorkflow'],
            'paused' => true,
            'note' => 'Was paused',
        ]);

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/schedules/resumable/resume', [
                'note' => 'Back to normal',
            ]);

        $response->assertOk()
            ->assertJsonPath('schedule_id', 'resumable')
            ->assertJsonPath('outcome', 'resumed');

        $schedule = WorkflowSchedule::where('schedule_id', 'resumable')->first();
        $this->assertFalse($schedule->paused);
        $this->assertEquals('Back to normal', $schedule->note);
    }

    public function test_pause_returns_404_for_nonexistent_schedule(): void
    {
        $this->withHeaders($this->headers())
            ->postJson('/api/schedules/nonexistent/pause')
            ->assertNotFound();
    }

    public function test_resume_returns_404_for_nonexistent_schedule(): void
    {
        $this->withHeaders($this->headers())
            ->postJson('/api/schedules/nonexistent/resume')
            ->assertNotFound();
    }

    // ── List item shape ─────────────────────────────────────────────

    public function test_list_item_contains_expected_fields(): void
    {
        WorkflowSchedule::create([
            'schedule_id' => 'shape-test',
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['0 9 * * *'], 'timezone' => 'UTC'],
            'action' => ['workflow_type' => 'ShapeWorkflow'],
            'overlap_policy' => 'skip',
            'paused' => false,
        ]);

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/schedules');

        $response->assertOk();

        $item = $response->json('schedules.0');
        $this->assertArrayHasKey('schedule_id', $item);
        $this->assertArrayHasKey('workflow_type', $item);
        $this->assertArrayHasKey('paused', $item);
        $this->assertArrayHasKey('next_fire', $item);
        $this->assertArrayHasKey('last_fire', $item);
        $this->assertEquals('ShapeWorkflow', $item['workflow_type']);
        $this->assertFalse($item['paused']);
    }

    // ── Cron computation ────────────────────────────────────────────

    public function test_cron_computation_for_standard_expressions(): void
    {
        $schedule = new WorkflowSchedule([
            'spec' => ['cron_expressions' => ['0 12 * * *'], 'timezone' => 'UTC'],
        ]);

        $after = new \DateTimeImmutable('2026-04-13T10:00:00Z');
        $next = $schedule->computeNextFireAt($after);

        $this->assertNotNull($next);
        $this->assertEquals('12', $next->format('H'));
        $this->assertEquals('00', $next->format('i'));
    }

    public function test_cron_computation_picks_earliest_from_multiple_expressions(): void
    {
        $schedule = new WorkflowSchedule([
            'spec' => [
                'cron_expressions' => ['0 18 * * *', '0 6 * * *'],
                'timezone' => 'UTC',
            ],
        ]);

        $after = new \DateTimeImmutable('2026-04-13T03:00:00Z');
        $next = $schedule->computeNextFireAt($after);

        $this->assertNotNull($next);
        $this->assertEquals('06', $next->format('H'));
    }

    public function test_cron_computation_returns_null_for_empty_expressions(): void
    {
        $schedule = new WorkflowSchedule([
            'spec' => ['cron_expressions' => []],
        ]);

        $this->assertNull($schedule->computeNextFireAt());
    }

    // ── Interval computation ────────────────────────────────────────

    public function test_interval_computation_for_iso8601_duration(): void
    {
        $schedule = new WorkflowSchedule([
            'spec' => ['intervals' => [['every' => 'PT30M']]],
        ]);

        $after = new \DateTimeImmutable('2026-04-13T10:10:00Z');
        $next = $schedule->computeNextFireAt($after);

        $this->assertNotNull($next);
        // PT30M grid anchored at epoch: next 30-min boundary after 10:10
        $this->assertEquals('30', $next->format('i'));
        $this->assertEquals('10', $next->format('H'));
    }

    public function test_interval_computation_with_offset(): void
    {
        $schedule = new WorkflowSchedule([
            'spec' => ['intervals' => [['every' => 'PT1H', 'offset' => 'PT15M']]],
        ]);

        $after = new \DateTimeImmutable('2026-04-13T10:20:00Z');
        $next = $schedule->computeNextFireAt($after);

        $this->assertNotNull($next);
        // 1h grid offset by 15m: ..., 10:15, 11:15, ...
        $this->assertEquals('15', $next->format('i'));
        $this->assertEquals('11', $next->format('H'));
    }

    public function test_interval_and_cron_pick_earliest(): void
    {
        $schedule = new WorkflowSchedule([
            'spec' => [
                'cron_expressions' => ['0 23 * * *'],
                'intervals' => [['every' => 'PT30M']],
                'timezone' => 'UTC',
            ],
        ]);

        $after = new \DateTimeImmutable('2026-04-13T10:10:00Z');
        $next = $schedule->computeNextFireAt($after);

        $this->assertNotNull($next);
        // The 30-min interval fires at 10:30, much earlier than the cron at 23:00
        $this->assertEquals('10', $next->format('H'));
        $this->assertEquals('30', $next->format('i'));
    }

    public function test_interval_returns_null_for_missing_every(): void
    {
        $schedule = new WorkflowSchedule([
            'spec' => ['intervals' => [['offset' => 'PT5M']]],
        ]);

        $this->assertNull($schedule->computeNextFireAt());
    }

    public function test_interval_returns_null_for_invalid_duration(): void
    {
        $schedule = new WorkflowSchedule([
            'spec' => ['intervals' => [['every' => 'not-a-duration']]],
        ]);

        $this->assertNull($schedule->computeNextFireAt());
    }

    public function test_interval_only_schedules_compute_next_fire_at_on_create(): void
    {
        $response = $this->withHeaders($this->headers())
            ->postJson('/api/schedules', [
                'schedule_id' => 'interval-sched',
                'spec' => ['intervals' => [['every' => 'PT1H']]],
                'action' => ['workflow_type' => 'TestWorkflow'],
            ]);

        $response->assertCreated();

        $schedule = WorkflowSchedule::where('schedule_id', 'interval-sched')->first();
        $this->assertNotNull($schedule->next_fire_at);
    }

    // ── Overlap policy enforcement ──────────────────────────────────

    public function test_trigger_buffers_when_previous_workflow_is_running(): void
    {
        $summary = WorkflowRunSummary::forceCreate([
            'id' => 'run_buf_running_00001',
            'workflow_instance_id' => 'wf-buffer-running',
            'run_number' => 1,
            'class' => 'App\\Workflows\\TestWorkflow',
            'workflow_type' => 'TestWorkflow',
            'status' => 'pending',
            'status_bucket' => 'running',
        ]);

        WorkflowSchedule::create([
            'schedule_id' => 'buffer-test',
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['0 * * * *']],
            'action' => ['workflow_type' => 'TestWorkflow'],
            'overlap_policy' => 'buffer_one',
            'recent_actions' => [
                ['workflow_id' => 'wf-buffer-running', 'run_id' => $summary->id, 'outcome' => 'started'],
            ],
        ]);

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/schedules/buffer-test/trigger');

        $response->assertOk()
            ->assertJsonPath('outcome', 'buffered')
            ->assertJsonPath('schedule_id', 'buffer-test')
            ->assertJsonPath('buffer_depth', 1);

        $schedule = WorkflowSchedule::where('schedule_id', 'buffer-test')->first();
        $this->assertCount(1, $schedule->buffered_actions);
    }

    public function test_trigger_returns_buffer_full_when_buffer_one_is_at_capacity(): void
    {
        $summary = WorkflowRunSummary::forceCreate([
            'id' => 'run_buf_full_000000001',
            'workflow_instance_id' => 'wf-buffer-full',
            'run_number' => 1,
            'class' => 'App\\Workflows\\TestWorkflow',
            'workflow_type' => 'TestWorkflow',
            'status' => 'pending',
            'status_bucket' => 'running',
        ]);

        WorkflowSchedule::create([
            'schedule_id' => 'buffer-full-test',
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['0 * * * *']],
            'action' => ['workflow_type' => 'TestWorkflow'],
            'overlap_policy' => 'buffer_one',
            'recent_actions' => [
                ['workflow_id' => 'wf-buffer-full', 'run_id' => $summary->id, 'outcome' => 'started'],
            ],
            'buffered_actions' => [
                ['buffered_at' => now()->toIso8601String()],
            ],
        ]);

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/schedules/buffer-full-test/trigger');

        $response->assertOk()
            ->assertJsonPath('outcome', 'buffer_full')
            ->assertJsonPath('schedule_id', 'buffer-full-test');
    }

    public function test_trigger_fires_buffer_policy_when_previous_workflow_is_completed(): void
    {
        $summary = WorkflowRunSummary::forceCreate([
            'id' => 'run_buf_done_000000001',
            'workflow_instance_id' => 'wf-buffer-done',
            'run_number' => 1,
            'class' => 'App\\Workflows\\TestWorkflow',
            'workflow_type' => 'TestWorkflow',
            'status' => 'completed',
            'status_bucket' => 'completed',
        ]);

        WorkflowSchedule::create([
            'schedule_id' => 'buffer-fire-test',
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['0 * * * *']],
            'action' => ['workflow_type' => 'TestWorkflow'],
            'overlap_policy' => 'buffer_one',
            'recent_actions' => [
                ['workflow_id' => 'wf-buffer-done', 'run_id' => $summary->id, 'outcome' => 'started'],
            ],
        ]);

        // Previous workflow is completed, so trigger should attempt to fire.
        // It will fail because TestWorkflow isn't a real class, but we should
        // get past the buffer check (not a 422 or buffered response).
        $response = $this->withHeaders($this->headers())
            ->postJson('/api/schedules/buffer-fire-test/trigger');

        $this->assertNotEquals('buffered', $response->json('outcome'));
        $this->assertNotEquals('buffer_full', $response->json('outcome'));
    }

    public function test_trigger_buffer_all_allows_multiple_buffered_actions(): void
    {
        $summary = WorkflowRunSummary::forceCreate([
            'id' => 'run_buf_all_0000000001',
            'workflow_instance_id' => 'wf-buffer-all',
            'run_number' => 1,
            'class' => 'App\\Workflows\\TestWorkflow',
            'workflow_type' => 'TestWorkflow',
            'status' => 'pending',
            'status_bucket' => 'running',
        ]);

        WorkflowSchedule::create([
            'schedule_id' => 'buffer-all-test',
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['0 * * * *']],
            'action' => ['workflow_type' => 'TestWorkflow'],
            'overlap_policy' => 'buffer_all',
            'recent_actions' => [
                ['workflow_id' => 'wf-buffer-all', 'run_id' => $summary->id, 'outcome' => 'started'],
            ],
            'buffered_actions' => [
                ['buffered_at' => now()->subMinutes(5)->toIso8601String()],
            ],
        ]);

        // buffer_all should accept another buffer even when one is already present
        $response = $this->withHeaders($this->headers())
            ->postJson('/api/schedules/buffer-all-test/trigger');

        $response->assertOk()
            ->assertJsonPath('outcome', 'buffered')
            ->assertJsonPath('buffer_depth', 2);
    }

    public function test_trigger_accepts_allow_all_policy(): void
    {
        WorkflowSchedule::create([
            'schedule_id' => 'allow-all-test',
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['0 * * * *']],
            'action' => ['workflow_type' => 'TestWorkflow'],
            'overlap_policy' => 'allow_all',
        ]);

        // allow_all should not pass any duplicate_policy, so it starts freely.
        // This will fail because TestWorkflow isn't a real class, but the
        // overlap policy enforcement itself should not block it.
        $response = $this->withHeaders($this->headers())
            ->postJson('/api/schedules/allow-all-test/trigger');

        // The 500 means we got past overlap policy and into the start attempt
        $this->assertNotEquals(422, $response->status());
    }

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

    private function headers(string $namespace = 'default'): array
    {
        return [
            'X-Namespace' => $namespace,
        ];
    }
}
