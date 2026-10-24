<?php

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use App\Models\WorkflowUpdateValidationTask;
use App\Support\WorkflowUpdateValidationTaskBroker;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;
use Workflow\V2\CommandContext;
use Workflow\V2\Models\WorkflowRun;

class WorkflowUpdateValidationLockPressureTest extends TestCase
{
    use DatabaseMigrations;
    use ServerTestHelpers;

    private const WORKFLOW_TYPE = 'tests.validation-pressure';

    private const TASK_QUEUE = 'validation-pressure';

    private ?string $databasePath = null;

    private ?PDO $writer = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->databasePath = tempnam(sys_get_temp_dir(), 'dw-validation-pressure-');
            $this->assertIsString($this->databasePath);
            config([
                'database.connections.sqlite.database' => $this->databasePath,
                'database.connections.sqlite.busy_timeout' => 1,
                'database.connections.sqlite.journal_mode' => 'WAL',
                'database.connections.sqlite.transaction_mode' => 'IMMEDIATE',
            ]);
            DB::purge('sqlite');
            $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        }

        config([
            'server.update_validation.timeout' => 0,
            'server.update_validation.lease_timeout' => 5,
            'workflows.storage.transaction_attempts' => 3,
        ]);
        DB::connection()->beforeStartingTransaction(static function ($connection): void {
            match ($connection->getDriverName()) {
                'mysql', 'mariadb' => $connection->statement('SET SESSION innodb_lock_wait_timeout = 1'),
                'pgsql' => $connection->statement("SET lock_timeout = '10ms'"),
                default => null,
            };
        });

        $this->createNamespace('default');
        $this->registerWorker('validator', self::TASK_QUEUE, supportedWorkflowTypes: [self::WORKFLOW_TYPE], capabilities: [
            'workflow_tasks', 'update_validation_tasks',
        ]);
        WorkerRegistration::query()->where('worker_id', 'validator')->update([
            'workflow_command_contracts' => json_encode([
                self::WORKFLOW_TYPE => [
                    'queries' => [], 'query_contracts' => [], 'signals' => [], 'signal_contracts' => [],
                    'updates' => ['approve'], 'update_validators' => ['approve'],
                    'update_contracts' => [[
                        'name' => 'approve',
                        'parameters' => [[
                            'name' => 'approved', 'position' => 0, 'required' => true, 'variadic' => false,
                            'default_available' => false, 'default' => null, 'type' => 'bool', 'allows_null' => false,
                        ]],
                    ]],
                ],
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    protected function tearDown(): void
    {
        $this->releaseWriter();
        DB::purge('validation-pressure-writer');

        try {
            parent::tearDown();
        } finally {
            if (is_string($this->databasePath)) {
                foreach (['', '-wal', '-shm'] as $suffix) {
                    @unlink($this->databasePath.$suffix);
                }
            }
        }
    }

    #[DataProvider('deliveryStates')]
    public function test_exhausted_poll_pressure_is_typed_and_does_not_commit_a_lease(bool $reclaim): void
    {
        [, $task] = $this->validationTask($reclaim);
        $before = $task->fresh()->getRawOriginal();
        $this->holdWriter($task);

        try {
            $this->postJson('/api/worker/update-validation-tasks/poll', [
                'worker_id' => 'validator', 'task_queue' => self::TASK_QUEUE, 'timeout_seconds' => 0,
            ], $this->workerHeaders())
                ->assertStatus(503)
                ->assertHeader('Retry-After', '1')
                ->assertJsonPath('task', null)
                ->assertJsonPath('poll_status', 'backend_lock_pressure')
                ->assertJsonPath('reason', 'backend_lock_pressure')
                ->assertJsonPath('task_kind', 'update_validation_task');
        } finally {
            $this->releaseWriter();
        }

        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame($before, $task->fresh()->getRawOriginal());
    }

    #[DataProvider('deliveryStates')]
    public function test_claim_and_reclaim_retry_real_lock_pressure(bool $reclaim): void
    {
        [, $task] = $this->validationTask($reclaim);
        $this->holdWriter($task);
        $transactions = $this->releaseBeforeRetry();

        $payload = app(WorkflowUpdateValidationTaskBroker::class)->poll(
            'default', WorkerRegistration::query()->where('worker_id', 'validator')->firstOrFail(), 0,
        );

        $this->assertIsArray($payload);
        $this->assertSame($task->id, $payload['update_validation_task_id']);
        $this->assertSame($reclaim ? 2 : 1, $payload['update_validation_attempt']);
        $this->assertSame('validator', $task->fresh()->lease_owner);
        $this->assertSame(WorkflowUpdateValidationTask::STATUS_LEASED, $task->fresh()->status);
        $this->assertGreaterThanOrEqual(2, $transactions());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_validation_timeout_retries_real_lock_pressure_and_is_terminal(): void
    {
        [$run, $task] = $this->validationTask(true);
        $this->holdWriter($task);
        $transactions = $this->releaseBeforeRetry();

        $result = app(WorkflowUpdateValidationTaskBroker::class)->validate(
            'default', $run, 'approve', [true], 'pressure-request', CommandContext::controlPlane(),
        );
        $this->assertSame(504, $result['status']);
        $this->assertSame('update_validation_execution_timeout', $result['reason']);

        $task = $task->fresh();
        $this->assertSame(WorkflowUpdateValidationTask::STATUS_TIMED_OUT, $task->status);
        $this->assertNotNull($task->timed_out_at);
        $this->assertNull($task->lease_expires_at);
        $this->assertGreaterThanOrEqual(2, $transactions());
        $duplicate = app(WorkflowUpdateValidationTaskBroker::class)->approve('default', $task->id, 'validator', 1);
        $this->assertSame('duplicate_update_validation_completion', $duplicate['reason']);
    }

    public function test_timeout_retry_preserves_an_approval_committed_by_the_validator(): void
    {
        [$run, $task] = $this->validationTask(true);
        $this->holdWriter($task);
        $statement = $this->writer->prepare("UPDATE workflow_update_validation_tasks SET status = 'approved', approved_at = CURRENT_TIMESTAMP, lease_expires_at = NULL WHERE id = ?");
        $statement->execute([$task->id]);
        $transactions = $this->releaseBeforeRetry(commit: true);

        $result = app(WorkflowUpdateValidationTaskBroker::class)->validate(
            'default', $run, 'approve', [true], 'pressure-request', CommandContext::controlPlane(),
        );
        $this->assertTrue($result['approved']);
        $this->assertSame(200, $result['status']);

        $this->assertSame(WorkflowUpdateValidationTask::STATUS_APPROVED, $task->fresh()->status);
        $this->assertNull($task->fresh()->timed_out_at);
        $this->assertGreaterThanOrEqual(2, $transactions());
    }

    public function test_unrelated_database_errors_are_not_retried_or_reported_as_pressure(): void
    {
        $this->validationTask(false);
        Schema::drop('workflow_update_validation_tasks');
        $transactions = 0;
        DB::connection()->beforeStartingTransaction(function () use (&$transactions): void {
            $transactions++;
        });

        $this->postJson('/api/worker/update-validation-tasks/poll', [
            'worker_id' => 'validator', 'task_queue' => self::TASK_QUEUE, 'timeout_seconds' => 0,
        ], $this->workerHeaders())
            ->assertStatus(500)
            ->assertJsonMissingPath('poll_status');

        $this->assertSame(1, $transactions);
        $this->assertSame(0, DB::transactionLevel());
    }

    public static function deliveryStates(): array
    {
        return ['first claim' => [false], 'expired lease reclaim' => [true]];
    }

    /** @return array{WorkflowRun, WorkflowUpdateValidationTask} */
    private function validationTask(bool $leased): array
    {
        $response = $this->postJson('/api/workflows', [
            'workflow_id' => 'validation-pressure', 'workflow_type' => self::WORKFLOW_TYPE,
            'task_queue' => self::TASK_QUEUE, 'input' => ['Ada'],
        ], $this->apiHeaders())->assertCreated();
        $run = WorkflowRun::query()->findOrFail($response->json('run_id'));
        $codec = CodecRegistry::defaultCodec();
        $arguments = Serializer::serializeWithCodec($codec, [true]);
        $task = WorkflowUpdateValidationTask::query()->create([
            'idempotency_key' => hash('sha256', implode("\0", ['default', $run->id, 'approve', 'pressure-request'])),
            'namespace' => 'default', 'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_run_id' => $run->id, 'workflow_type' => self::WORKFLOW_TYPE, 'task_queue' => self::TASK_QUEUE,
            'update_name' => 'approve', 'request_id' => 'pressure-request',
            'input_hash' => hash('sha256', $codec."\0".$arguments), 'payload_codec' => $codec, 'arguments' => $arguments,
            'command_context' => [], 'status' => $leased ? 'leased' : 'pending',
            'attempt_count' => $leased ? 1 : 0, 'lease_owner' => $leased ? 'validator' : null,
            'lease_expires_at' => $leased ? now()->subSecond() : null,
        ]);

        return [$run, $task];
    }

    private function holdWriter(WorkflowUpdateValidationTask $task): void
    {
        config(['database.connections.validation-pressure-writer' => DB::connection()->getConfig()]);
        $this->writer = DB::connection('validation-pressure-writer')->getPdo();
        $this->writer->beginTransaction();
        $statement = $this->writer->prepare('UPDATE workflow_update_validation_tasks SET updated_at = updated_at WHERE id = ?');
        $statement->execute([$task->id]);
    }

    /** @return \Closure(): int */
    private function releaseBeforeRetry(bool $commit = false): \Closure
    {
        $transactions = 0;
        DB::connection()->beforeStartingTransaction(function () use (&$transactions, $commit): void {
            if (++$transactions === 2) {
                $this->releaseWriter($commit);
            }
        });

        return static function () use (&$transactions): int {
            return $transactions;
        };
    }

    private function releaseWriter(bool $commit = false): void
    {
        if ($this->writer?->inTransaction()) {
            $commit ? $this->writer->commit() : $this->writer->rollBack();
        }
        $this->writer = null;
    }
}
