<?php

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use App\Support\ControlPlaneProtocol;
use DateTimeInterface;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use LogicException;
use PDO;
use Tests\TestCase;
use Workflow\V2\Contracts\ScheduleWorkflowStarter;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowSchedule;
use Workflow\V2\Models\WorkflowScheduleHistoryEvent;
use Workflow\V2\Support\ScheduleStartResult;

class ScheduleTriggerSqliteContentionTest extends TestCase
{
    private string $databasePath;

    private ?PDO $writer = null;

    protected function setUp(): void
    {
        parent::setUp();
        $path = tempnam(sys_get_temp_dir(), 'dw-schedule-trigger-');
        $this->assertNotFalse($path);
        $this->databasePath = $path;
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $path,
            'database.connections.sqlite.busy_timeout' => 1,
            'database.connections.sqlite.journal_mode' => 'WAL',
            'database.connections.sqlite.transaction_mode' => 'DEFERRED',
        ]);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        Queue::fake();
        WorkflowNamespace::query()->create([
            'name' => 'default', 'description' => 'Default', 'retention_days' => 30, 'status' => 'active',
        ]);
        DB::statement('CREATE TABLE contention_marker (writes INTEGER NOT NULL)');
        DB::statement('INSERT INTO contention_marker(writes) VALUES (0)');
        $this->writer = new PDO('sqlite:'.$path);
        $this->writer->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->writer->exec('PRAGMA busy_timeout=1');
    }

    protected function tearDown(): void
    {
        if ($this->writer?->inTransaction()) {
            $this->writer->rollBack();
        }
        $this->writer = null;
        DB::disconnect('sqlite');
        foreach (['', '-wal', '-shm'] as $suffix) {
            if (is_file($this->databasePath.$suffix)) {
                unlink($this->databasePath.$suffix);
            }
        }
        parent::tearDown();
    }

    public function test_manual_trigger_retries_an_invalidated_snapshot_and_admits_once(): void
    {
        $schedule = $this->schedule();
        $injected = $this->injectWriter(false);
        $this->trigger()->assertOk()->assertJsonPath('outcome', 'triggered');
        $this->assertTrue($injected());
        $this->assertSingleAdmission($schedule);
    }

    public function test_exhausted_writer_pressure_returns_503_and_preserves_the_action_for_retry(): void
    {
        $schedule = $this->schedule();
        $before = $schedule->fresh()->getRawOriginal();
        $injected = $this->injectWriter(true);
        $this->trigger()->assertStatus(503)
            ->assertJsonPath('reason', 'backend_lock_pressure')
            ->assertJsonPath('retryable', true)
            ->assertHeader('Retry-After', '1');
        $this->assertTrue($injected());
        $this->assertSame($before, $schedule->fresh()->getRawOriginal());
        $this->assertSame(0, WorkflowInstance::query()->count());
        $this->assertSame(0, WorkflowScheduleHistoryEvent::query()->count());
        $this->writer->rollBack();
        $this->trigger()->assertOk()->assertJsonPath('outcome', 'triggered');
        $this->assertSingleAdmission($schedule);
    }

    public function test_permanent_start_failure_is_not_retried_or_reported_as_backend_pressure(): void
    {
        $this->schedule();
        $starter = new class implements ScheduleWorkflowStarter
        {
            public int $calls = 0;

            public function start(
                WorkflowSchedule $schedule,
                ?DateTimeInterface $occurrenceTime,
                string $outcome,
                ?string $effectiveOverlapPolicy = null,
            ): ScheduleStartResult {
                $this->calls++;
                throw new LogicException('Permanent schedule start failure.');
            }
        };
        $this->app->instance(ScheduleWorkflowStarter::class, $starter);
        $this->trigger()->assertStatus(500)
            ->assertJsonPath('outcome', 'trigger_failed')
            ->assertJsonPath('reason', 'Permanent schedule start failure.');
        $this->assertSame(1, $starter->calls);
        $this->assertSame(0, WorkflowInstance::query()->count());
    }

    private function schedule(): WorkflowSchedule
    {
        return WorkflowSchedule::query()->create([
            'schedule_id' => 'manual-contention', 'namespace' => 'default',
            'status' => 'active', 'spec' => ['intervals' => [['every' => 'PT1H']], 'timezone' => 'UTC'],
            'action' => ['workflow_type' => 'ExampleWorkflow', 'task_queue' => 'default'],
            'overlap_policy' => 'allow_all', 'remaining_actions' => 1,
            'next_fire_at' => now()->addHour(),
        ]);
    }

    private function injectWriter(bool $hold): callable
    {
        $injected = false;
        DB::listen(function (QueryExecuted $query) use ($hold, &$injected): void {
            if ($injected || $query->connection->transactionLevel() === 0
                || ! str_starts_with(strtolower($query->sql), 'select')
                || ! str_contains($query->sql, 'workflow_schedules')) {
                return;
            }
            $injected = true;
            $this->writer->beginTransaction();
            $this->writer->exec('UPDATE contention_marker SET writes=writes+1');
            if (! $hold) {
                $this->writer->commit();
            }
        });

        return static function () use (&$injected): bool {
            return $injected;
        };
    }

    private function trigger(): TestResponse
    {
        return $this->withHeaders([ControlPlaneProtocol::HEADER => ControlPlaneProtocol::VERSION])
            ->postJson('/api/schedules/manual-contention/trigger');
    }

    private function assertSingleAdmission(WorkflowSchedule $schedule): void
    {
        $fresh = $schedule->fresh();
        $this->assertSame(1, (int) $fresh->fires_count);
        $this->assertSame(0, (int) $fresh->failures_count);
        $this->assertSame(0, (int) $fresh->remaining_actions);
        $this->assertSame(1, WorkflowInstance::query()->count());
        $this->assertSame(1, WorkflowScheduleHistoryEvent::query()->where('event_type', 'ScheduleTriggered')->count());
    }
}
