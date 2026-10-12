<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\PostCommitChildWorkflow;
use Tests\Fixtures\PostCommitParentWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;

class CommittedCommandRecoveryTest extends TestCase
{
    use ServerTestHelpers;

    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        // RefreshDatabase would hold an outer transaction and hide the real
        // post-commit boundary. This fixture needs two independent connections.
        $this->databasePath = tempnam(sys_get_temp_dir(), 'dw-committed-command-');
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->databasePath,
            'workflows.storage.connection' => 'sqlite',
            'workflows.v2.task_dispatch_mode' => 'poll',
        ]);
        DB::purge('sqlite');
        Artisan::call('migrate:fresh', ['--force' => true]);
        Queue::fake();
        $this->createNamespace('default');
        $this->configureWorkflowTypes([
            'tests.post-commit-parent' => PostCommitParentWorkflow::class,
            'tests.post-commit-child' => PostCommitChildWorkflow::class,
        ]);
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        foreach ([$this->databasePath, $this->databasePath.'-wal', $this->databasePath.'-shm'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    #[DataProvider('terminalOperations')]
    public function test_real_post_commit_lock_preserves_original_command_and_parent_recovery(string $operation, bool $failReceiptLookup): void
    {
        $parent = $this->postJson('/api/workflows', [
            'workflow_id' => 'post-commit-parent',
            'workflow_type' => 'tests.post-commit-parent',
        ], $this->apiHeaders())->assertCreated();
        $parentRunId = $parent->json('run_id');
        $this->runReadyWorkflowTask($parentRunId);
        $child = WorkflowRun::query()->where('workflow_type', 'tests.post-commit-child')->sole();
        $this->runReadyWorkflowTask($child->id);
        $this->assertSame(TimerStatus::Pending, WorkflowTimer::query()->where('workflow_run_id', $child->id)->sole()->status);

        $connection = DB::connection();
        $writer = new PDO('sqlite:'.$this->databasePath);
        $writer->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $injected = $locked = false;
        $receiptFailures = 0;
        $connection->beforeExecuting(function (string $sql, array $bindings, $db) use ($writer, &$injected, &$locked, &$receiptFailures, $failReceiptLookup): void {
            if ($locked) {
                $writer->exec('ROLLBACK');
                $locked = false;
            }

            if (! $injected && $db->transactionLevel() === 0
                && str_starts_with(strtolower($sql), 'update ')
                && str_contains($sql, 'workflow_tasks') && str_contains($sql, 'last_dispatched_at')) {
                $injected = $locked = true;
                $writer->exec('BEGIN IMMEDIATE');
                $db->getPdo()->exec('PRAGMA busy_timeout = 0');
            } elseif ($injected && $failReceiptLookup && $receiptFailures === 0
                && str_contains($sql, 'select * from "workflow_commands"')) {
                $receiptFailures++;
                throw new RuntimeException('database is locked while recovering the committed receipt');
            }
        });
        Log::spy();
        $path = "/api/workflows/{$child->workflow_instance_id}/runs/{$child->id}/{$operation}";
        $request = ['reason' => 'original reason', 'request_id' => 'same-client-request-id'];

        try {
            $response = $this->postJson($path, $request, $this->apiHeaders())->assertOk()
                ->assertJsonPath('command_status', 'accepted')
                ->assertJsonPath('outcome', $operation === 'cancel' ? 'cancelled' : 'terminated')
                ->assertJsonPath('run_id', $child->id);
            $this->assertTrue($injected);
            $this->assertSame($failReceiptLookup ? 1 : 0, $receiptFailures);
            $commands = WorkflowCommand::query()->where('workflow_run_id', $child->id)->where('command_type', $operation)->get();
            $this->assertCount(1, $commands);
            $this->assertSame($commands[0]->id, $response->json('command_id'));
            $this->assertSame('original reason', $commands[0]->commandReason());
            $this->assertSame(TimerStatus::Cancelled, WorkflowTimer::query()->where('workflow_run_id', $child->id)->sole()->status);
            $this->assertSame(0, WorkflowHistoryEvent::query()->where('workflow_run_id', $child->id)->where('event_type', 'TimerFired')->count());
            $this->assertSame(1, WorkflowTask::query()->where('workflow_run_id', $parentRunId)->where('status', 'ready')->count());
            $this->runReadyWorkflowTask($parentRunId);
            $this->assertSame(RunStatus::Completed, WorkflowRun::query()->findOrFail($parentRunId)->status);

            // Even an identical reason and reused client request ID are a new
            // HTTP operation, and must not recover the previous receipt.
            $this->postJson($path, $request, $this->apiHeaders())->assertStatus(409)
                ->assertJsonPath('command_status', 'rejected')
                ->assertJsonPath('reason', 'run_not_active');
            $commands = WorkflowCommand::query()->where('workflow_run_id', $child->id)->where('command_type', $operation)->orderBy('command_sequence')->get();
            $this->assertCount(2, $commands);
            $this->assertNotSame($commands[0]->commandContext()['server']['operation_id'], $commands[1]->commandContext()['server']['operation_id']);
            Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'Recovered a committed command after a post-commit exception.'
                && $context['command']['id'] === $commands[0]->id
                && $context['workflow']['run_id'] === $child->id
                && $context['recovery'] === 'committed_command')->once();
        } finally {
            if ($locked) {
                $writer->exec('ROLLBACK');
            }
        }
    }

    public static function terminalOperations(): iterable
    {
        yield 'cancel' => ['cancel', false];
        yield 'terminate' => ['terminate', false];
        yield 'receipt lookup fails before retry' => ['cancel', true];
    }

    #[DataProvider('refreshOperations')]
    public function test_committed_repair_and_archive_survive_a_refresh_exception(string $operation): void
    {
        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'post-commit-refresh',
            'workflow_type' => 'tests.post-commit-child',
        ], $this->apiHeaders())->assertCreated();
        $runId = $start->json('run_id');
        $this->runReadyWorkflowTask($runId);
        if ($operation === 'archive') {
            $this->postJson('/api/workflows/post-commit-refresh/cancel', [], $this->apiHeaders())->assertOk();
        }
        $commandId = null;
        WorkflowCommand::created(function (WorkflowCommand $command) use ($operation, &$commandId): void {
            if ($command->command_type->value === $operation) {
                $commandId = $command->id;
            }
        });
        $failed = false;
        DB::connection()->beforeExecuting(function (string $sql, array $bindings, $db) use (&$failed, &$commandId): void {
            if (! $failed && $commandId !== null && $db->transactionLevel() === 0
                && str_contains($sql, 'select * from "workflow_instances"')) {
                $failed = true;
                throw new RuntimeException('database is locked during post-commit refresh');
            }
        });
        $response = $this->postJson("/api/workflows/post-commit-refresh/runs/{$runId}/{$operation}", [], $this->apiHeaders())->assertOk();
        $this->assertTrue($failed);
        $this->assertSame($commandId, $response->json('command_id'));
        $this->assertSame(1, WorkflowCommand::query()->where('workflow_run_id', $runId)->where('command_type', $operation)->count());
    }

    public static function refreshOperations(): iterable
    {
        yield 'repair' => ['repair'];
        yield 'archive' => ['archive'];
    }
}
