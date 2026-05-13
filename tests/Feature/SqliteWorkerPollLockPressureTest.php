<?php

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use App\Models\WorkflowNamespace;
use App\Support\ControlPlaneProtocol;
use App\Support\NamespaceWorkflowScope;
use App\Support\WorkerProtocol;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PDO;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\WorkflowExecutor;
use Workflow\V2\WorkflowStub;

class SqliteWorkerPollLockPressureTest extends TestCase
{
    private ?string $databasePath = null;

    private ?PDO $lockConnection = null;

    protected function setUp(): void
    {
        parent::setUp();

        $path = tempnam(sys_get_temp_dir(), 'dw-server-sqlite-poll-');

        if ($path === false) {
            $this->fail('Could not allocate a SQLite database file for the lock-pressure test.');
        }

        $this->databasePath = $path;

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $path,
            'database.connections.sqlite.busy_timeout' => 1,
            'database.connections.sqlite.journal_mode' => 'DELETE',
            'database.connections.sqlite.transaction_mode' => 'IMMEDIATE',
        ]);

        DB::purge('sqlite');

        $this->artisan('migrate:fresh', ['--force' => true])
            ->assertExitCode(0);
    }

    protected function tearDown(): void
    {
        if ($this->lockConnection instanceof PDO) {
            if ($this->lockConnection->inTransaction()) {
                $this->lockConnection->rollBack();
            }

            $this->lockConnection = null;
        }

        DB::disconnect('sqlite');

        if (is_string($this->databasePath)) {
            foreach ([$this->databasePath, $this->databasePath.'-wal', $this->databasePath.'-shm'] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }

        parent::tearDown();
    }

    public function test_activity_polls_return_structured_lock_pressure_for_two_external_workers_on_sqlite(): void
    {
        Queue::fake();

        WorkflowNamespace::query()->updateOrCreate(
            ['name' => 'default'],
            ['description' => 'Default namespace', 'retention_days' => 30, 'status' => 'active'],
        );

        $workflow = WorkflowStub::make(ExternalGreetingWorkflow::class, 'wf-sqlite-poll-lock-pressure');
        $start = $workflow->start('Ada');

        NamespaceWorkflowScope::bind('default', $workflow->id(), ExternalGreetingWorkflow::class);

        $this->runReadyWorkflowTask($start->runId());

        /** @var WorkflowTask|null $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $start->runId())
            ->where('task_type', TaskType::Activity->value)
            ->first();

        $this->assertInstanceOf(WorkflowTask::class, $task);

        $task->forceFill(['queue' => 'polyglot-shared'])->save();
        ActivityExecution::query()
            ->where('workflow_run_id', $start->runId())
            ->update(['queue' => 'polyglot-shared']);

        $this->registerWorker('php-sqlite-poller', 'php');
        $this->registerWorker('python-sqlite-poller', 'python');

        $this->holdSqliteWriteLock($task->id);

        foreach (['php-sqlite-poller', 'python-sqlite-poller'] as $workerId) {
            $poll = $this->withHeaders($this->workerHeaders())
                ->postJson('/api/worker/activity-tasks/poll', [
                    'worker_id' => $workerId,
                    'task_queue' => 'polyglot-shared',
                ]);

            $poll->assertStatus(503)
                ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
                ->assertHeaderMissing(ControlPlaneProtocol::HEADER)
                ->assertHeader('Retry-After', '1')
                ->assertJsonPath('protocol_version', WorkerProtocol::VERSION)
                ->assertJsonPath('task', null)
                ->assertJsonPath('poll_status', 'backend_lock_pressure')
                ->assertJsonPath('reason', 'backend_lock_pressure')
                ->assertJsonPath('task_kind', 'activity_task')
                ->assertJsonPath('namespace', 'default')
                ->assertJsonPath('task_queue', 'polyglot-shared')
                ->assertJsonPath('retry_after_seconds', 1)
                ->assertJsonPath('backend.driver', 'sqlite')
                ->assertJsonPath('backend.lock_pressure', true)
                ->assertJsonPath('message', static fn (mixed $message): bool => is_string($message)
                    && str_contains($message, 'Retry the poll with backoff'))
                ->assertJsonPath('server_capabilities.poll_status', true)
                ->assertJsonMissingPath('control_plane');

            DB::disconnect('sqlite');
        }
    }

    private function registerWorker(string $workerId, string $runtime): void
    {
        WorkerRegistration::query()->create([
            'worker_id' => $workerId,
            'namespace' => 'default',
            'task_queue' => 'polyglot-shared',
            'runtime' => $runtime,
            'supported_workflow_types' => ['tests.external-greeting-workflow'],
            'supported_activity_types' => ['tests.external-greeting-activity'],
            'last_heartbeat_at' => now(),
            'status' => 'active',
        ]);
    }

    private function holdSqliteWriteLock(string $taskId): void
    {
        $this->assertIsString($this->databasePath);

        $pdo = new PDO('sqlite:'.$this->databasePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA busy_timeout = 1');
        $pdo->exec('BEGIN IMMEDIATE');
        $pdo->exec("UPDATE workflow_tasks SET updated_at = updated_at WHERE id = ".$pdo->quote($taskId));

        $this->lockConnection = $pdo;
    }

    private function workerHeaders(): array
    {
        return [
            'X-Namespace' => 'default',
            WorkerProtocol::HEADER => WorkerProtocol::VERSION,
        ];
    }

    private function runReadyWorkflowTask(string $runId): void
    {
        $taskId = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', 'ready')
            ->orderBy('available_at')
            ->value('id');

        $this->assertIsString($taskId);

        $job = new RunWorkflowTask($taskId);
        $job->handle(app(WorkflowExecutor::class));
    }
}
