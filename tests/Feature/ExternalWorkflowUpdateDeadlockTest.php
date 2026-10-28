<?php

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use App\Models\WorkflowUpdateValidationTask;
use App\Support\WorkflowUpdateValidationTaskBroker;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Concerns\ForkedDatabaseActors;
use Tests\TestCase;
use Throwable;
use Workflow\Serializers\Serializer;
use Workflow\V2\Models\WorkflowRun;

class ExternalWorkflowUpdateDeadlockTest extends TestCase
{
    use ForkedDatabaseActors;

    private const TYPE = 'tests.external-update-deadlock';

    public static function admissions(): iterable
    {
        foreach (['mysql', 'mariadb'] as $driver) {
            yield $driver.' direct' => [$driver, false];
            yield $driver.' approved' => [$driver, true];
        }
    }

    #[DataProvider('admissions')]
    public function test_owned_admission_retries_a_real_deadlock_without_duplicate_effects(string $driver, bool $validated): void
    {
        [$run, $approval] = $this->fixture($driver, $validated);
        $approvalBefore = $approval?->getRawOriginal();
        $race = $this->deadlock($run);

        $this->assertNull($race['error'], $race['error']?->getMessage() ?? '');
        $this->assertSame(2, $race['attempts']);
        $race['response']->assertAccepted()->assertJsonPath('update_status', 'accepted');
        $this->assertCommittedOnce($run);
        if ($approval !== null) {
            $race['response']->assertJsonPath('update_id', $approval->id);
            $this->assertSame($approvalBefore, $approval->fresh()->getRawOriginal());
            $this->assertSame(1, WorkflowUpdateValidationTask::query()->count());
        }

        $before = $this->durableRows();
        $this->postJson($this->path($run), ['input' => [99], 'request_id' => 'update-key'], $this->apiHeaders())
            ->assertAccepted()->assertJsonPath('update_id', $race['response']->json('update_id'));
        $this->assertSame($before, $this->durableRows());
    }

    #[DataProvider('admissions')]
    public function test_configured_single_attempt_preserves_the_deadlock_and_full_rollback(string $driver, bool $validated): void
    {
        [$run] = $this->fixture($driver, $validated);
        config(['workflows.storage.transaction_attempts' => 1]);
        $race = $this->deadlock($run);

        $this->assertSame(1213, $this->errorCode($race['error']));
        $this->assertSame(1, $race['attempts']);
        $this->assertNotAdmitted($run);
    }

    #[DataProvider('admissions')]
    public function test_caller_owned_transaction_is_not_replayed(string $driver, bool $validated): void
    {
        [$run] = $this->fixture($driver, $validated);
        $race = $this->deadlock($run, callerOwned: true);

        $this->assertSame(1213, $this->errorCode($race['error']));
        $this->assertSame(1, $race['attempts']);
        $this->assertFalse(DB::table('update_deadlock_weights')->where('id', 2000)->exists());
        $this->assertNotAdmitted($run);
    }

    #[DataProvider('admissions')]
    public function test_selected_run_is_not_retargeted_when_a_deadlock_releases_its_instance(string $driver, bool $validated): void
    {
        [$run] = $this->fixture($driver, $validated, continuation: true);
        $continue = $this->continuation($run);
        $race = $this->deadlock($run, afterInstanceLock: $continue);

        $this->assertNull($race['error'], $race['error']?->getMessage() ?? '');
        $this->assertSame(1, $race['attempts']);
        $this->assertNotSame($run->id, DB::table('workflow_instances')->where('id', $run->workflow_instance_id)->value('current_run_id'));
        $this->assertSame(409, $race['response']->status(), json_encode([
            'attempts' => $race['attempts'],
            'reason' => $race['response']->json('reason'),
            'run_id' => $race['response']->json('run_id'),
            'update_status' => $race['response']->json('update_status'),
            'update_rows' => DB::table('workflow_updates')->count(),
        ], JSON_THROW_ON_ERROR));
        $race['response']->assertJsonPath('run_id', $run->id)->assertJsonPath(
            'reason', $validated ? 'stale_update_validation_completion' : 'historical_run_command_rejected',
        );
        $this->assertNotAdmitted($run);
    }

    #[DataProvider('admissions')]
    public function test_unrelated_constraint_failure_is_not_retried(string $driver, bool $validated): void
    {
        [$run] = $this->fixture($driver, $validated);
        DB::table('update_deadlock_weights')->insert(['id' => 1]);
        $attempts = 0;
        DB::listen(function (QueryExecuted $query) use (&$attempts): void {
            if ($this->insertsUpdate($query)) {
                $attempts++;
                DB::table('update_deadlock_weights')->insert(['id' => 1]);
            }
        });
        $error = null;
        try {
            $this->submit($run);
        } catch (Throwable $exception) {
            $error = $exception;
        }

        $this->assertSame(1062, $this->errorCode($error));
        $this->assertSame(1, $attempts);
        $this->assertNotAdmitted($run);
    }

    #[DataProvider('admissions')]
    public function test_failure_after_commit_does_not_repeat_a_committed_admission(string $driver, bool $validated): void
    {
        [$run] = $this->fixture($driver, $validated);
        $attempts = 0;
        $reported = new RuntimeException('Deadlock found when trying to get lock; try restarting transaction');
        DB::listen(function (QueryExecuted $query) use (&$attempts, $reported): void {
            if ($this->insertsUpdate($query)) {
                $attempts++;
            }
            if (str_starts_with(strtolower($query->sql), 'insert into `workflow_tasks`')) {
                DB::afterCommit(static function () use ($reported): void {
                    throw $reported;
                });
            }
        });
        $error = null;
        try {
            $this->submit($run);
        } catch (Throwable $exception) {
            $error = $exception;
        }

        $this->assertSame($reported, $error);
        $this->assertSame(1, $attempts);
        $this->assertCommittedOnce($run);
        $before = $this->durableRows();
        $this->submit($run)->assertAccepted();
        $this->assertSame($before, $this->durableRows());
    }

    /** @return array{WorkflowRun, ?WorkflowUpdateValidationTask} */
    private function fixture(string $driver, bool $validated, bool $continuation = false): array
    {
        $this->initializeActorDatabase($driver);
        Schema::dropIfExists('update_deadlock_weights');
        Schema::create('update_deadlock_weights', static fn (Blueprint $table) => $table->unsignedInteger('id')->primary());
        DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
        Queue::fake();
        config(['workflows.storage.transaction_attempts' => 3]);
        $this->withoutExceptionHandling();
        $this->registerWorker('update-owner', 'updates', supportedWorkflowTypes: [self::TYPE], capabilities: [
            'workflow_updates', 'workflow_tasks', 'update_validation_tasks',
        ]);
        WorkerRegistration::query()->where('worker_id', 'update-owner')->update([
            'workflow_command_contracts' => json_encode([self::TYPE => [
                'queries' => [], 'query_contracts' => [],
                'signals' => $continuation ? ['finish'] : [],
                'signal_contracts' => $continuation ? [['name' => 'finish', 'parameters' => []]] : [],
                'updates' => ['approve'], 'update_validators' => $validated ? ['approve'] : [],
                'update_contracts' => [['name' => 'approve', 'parameters' => [[
                    'name' => 'amount', 'position' => 0, 'required' => true, 'type' => 'int',
                    'variadic' => false, 'default_available' => false, 'default' => null, 'allows_null' => false,
                ]]]],
            ]], JSON_THROW_ON_ERROR),
        ]);
        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'deadlock-update', 'workflow_type' => self::TYPE, 'task_queue' => 'updates',
        ], $this->apiHeaders())->assertCreated();
        $run = WorkflowRun::query()->findOrFail($start->json('run_id'));
        $this->assertSame($validated ? 'required' : 'not_required', app(WorkflowUpdateValidationTaskBroker::class)->declaration($run, 'approve')['state']);
        $claim = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'update-owner', 'task_queue' => 'updates', 'timeout_seconds' => 0,
        ], $this->workerHeaders())->assertOk()->json('task');
        $this->assertIsArray($claim);
        $this->postJson('/api/worker/workflow-tasks/'.$claim['task_id'].'/complete', [
            'lease_owner' => $claim['lease_owner'], 'workflow_task_attempt' => $claim['workflow_task_attempt'],
            'commands' => [['type' => 'open_signal_wait', 'signal_name' => 'finish']],
        ], $this->workerHeaders())->assertOk();

        $approval = null;
        if ($validated) {
            $arguments = Serializer::serializeWithCodec('avro', [17]);
            $approval = WorkflowUpdateValidationTask::query()->create([
                'idempotency_key' => hash('sha256', implode("\0", ['default', $run->id, 'approve', 'update-key'])),
                'namespace' => 'default', 'workflow_instance_id' => $run->workflow_instance_id,
                'workflow_run_id' => $run->id, 'workflow_type' => self::TYPE, 'task_queue' => 'updates',
                'update_name' => 'approve', 'request_id' => 'update-key', 'payload_codec' => 'avro',
                'input_hash' => hash('sha256', 'avro'."\0".$arguments), 'arguments' => $arguments,
                'command_context' => [], 'status' => 'leased', 'attempt_count' => 1,
                'lease_owner' => 'update-owner', 'lease_expires_at' => now()->addMinute(),
            ]);
            $approved = app(WorkflowUpdateValidationTaskBroker::class)->approve('default', $approval->id, 'update-owner', 1);
            $this->assertSame('approved', $approved['outcome']);
            $approval->refresh();
        }

        return [$run, $approval];
    }

    private function deadlock(WorkflowRun $run, bool $callerOwned = false, ?callable $afterInstanceLock = null): array
    {
        // This actor holds the task range while admission holds the instance.
        // Its larger write set makes admission the real InnoDB deadlock victim.
        $actor = $this->startActor(static function () use ($run, $afterInstanceLock): string {
            return DB::transaction(static function () use ($run, $afterInstanceLock): string {
                DB::table('update_deadlock_weights')->insert(array_map(static fn (int $id): array => ['id' => $id], range(1, 1000)));
                if ($afterInstanceLock !== null) {
                    // A continuation needs a leased workflow task, so admission
                    // cannot deadlock on creating a ready task in this case.
                    DB::table('workflow_history_events')->where('workflow_run_id', $run->id)->lockForUpdate()->get();
                }
                DB::table('workflow_tasks')->where('workflow_run_id', $run->id)->lockForUpdate()->get();
                DB::table('workflow_instances')->where('id', $run->workflow_instance_id)->lockForUpdate()->first();
                if ($afterInstanceLock !== null) {
                    $afterInstanceLock();
                }

                return 'committed';
            });
        }, static fn (string $sql): bool => str_contains($sql, 'from `workflow_tasks`') && str_contains($sql, 'for update'));
        $this->runUntilPaused($actor);
        $attempts = 0;
        DB::listen(function (QueryExecuted $query) use (&$attempts, $actor): void {
            if ($this->insertsUpdate($query) && ++$attempts === 1) {
                $this->resume($actor);
                $this->awaitWait($actor, 'workflow_instances');
            }
        });
        $response = $error = null;
        try {
            $response = $callerOwned ? DB::transaction(function () use ($run) {
                DB::table('update_deadlock_weights')->insert(['id' => 2000]);

                return $this->submit($run);
            }) : $this->submit($run);
        } catch (Throwable $exception) {
            $error = $exception;
        }
        $this->assertSame('committed', $this->finishActor($actor));

        return compact('response', 'error', 'attempts');
    }

    private function continuation(WorkflowRun $run): \Closure
    {
        $this->postJson('/api/workflows/'.$run->workflow_instance_id.'/signal/finish', [
            'input' => [],
        ], $this->apiHeaders())->assertSuccessful();
        $claim = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'update-owner', 'task_queue' => 'updates', 'timeout_seconds' => 0,
        ], $this->workerHeaders())->assertOk()->json('task');
        $this->assertIsArray($claim);

        return function () use ($claim): void {
            $this->postJson('/api/worker/workflow-tasks/'.$claim['task_id'].'/complete', [
                'lease_owner' => $claim['lease_owner'], 'workflow_task_attempt' => $claim['workflow_task_attempt'],
                'commands' => [['type' => 'continue_as_new', 'workflow_type' => self::TYPE,
                    'arguments' => Serializer::serializeWithCodec('avro', [])]],
            ], $this->workerHeaders())->assertOk();
            $next = $this->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'update-owner', 'task_queue' => 'updates', 'timeout_seconds' => 0,
            ], $this->workerHeaders())->assertOk()->json('task');
            $this->assertIsArray($next);
            $this->postJson('/api/worker/workflow-tasks/'.$next['task_id'].'/complete', [
                'lease_owner' => $next['lease_owner'], 'workflow_task_attempt' => $next['workflow_task_attempt'],
                'commands' => [['type' => 'open_signal_wait', 'signal_name' => 'finish']],
            ], $this->workerHeaders())->assertOk();
        };
    }

    private function insertsUpdate(QueryExecuted $query): bool
    {
        return str_starts_with(strtolower($query->sql), 'insert into `workflow_commands`')
            && in_array('update', $query->bindings, true);
    }

    private function path(WorkflowRun $run): string
    {
        return '/api/workflows/'.$run->workflow_instance_id.'/runs/'.$run->id.'/update/approve';
    }

    private function submit(WorkflowRun $run): TestResponse
    {
        return $this->postJson($this->path($run), ['input' => [17], 'request_id' => 'update-key'], $this->apiHeaders());
    }

    private function errorCode(?Throwable $error): ?int
    {
        for ($current = $error; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof PDOException && isset($current->errorInfo[1])) {
                return (int) $current->errorInfo[1];
            }
        }

        return null;
    }

    private function assertCommittedOnce(WorkflowRun $run): void
    {
        $this->assertSame(1, DB::table('workflow_commands')->where('command_type', 'update')->count());
        $this->assertSame(1, DB::table('workflow_updates')->count());
        $this->assertSame(1, DB::table('workflow_history_events')->where('event_type', 'UpdateAccepted')->count());
        $this->assertSame(1, DB::table('workflow_tasks')->where('workflow_run_id', $run->id)->where('status', 'ready')->count());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    private function assertNotAdmitted(WorkflowRun $run): void
    {
        $this->assertSame(0, DB::table('workflow_commands')->where('command_type', 'update')->count());
        $this->assertSame(0, DB::table('workflow_updates')->count());
        $this->assertSame(0, DB::table('workflow_history_events')->where('event_type', 'UpdateAccepted')->count());
        $this->assertSame(0, DB::table('workflow_tasks')->where('workflow_run_id', $run->id)->where('status', 'ready')->count());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    private function durableRows(): array
    {
        $rows = [];
        foreach (['workflow_commands', 'workflow_updates', 'workflow_tasks', 'workflow_history_events', 'workflow_update_validation_tasks'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
        }

        return $rows;
    }
}
