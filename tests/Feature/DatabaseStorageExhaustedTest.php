<?php

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PDOException;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\InteractiveCommandWorkflow;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;

class DatabaseStorageExhaustedTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    private ?string $fullTable = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createNamespace('default');
        $this->configureWorkflowTypes([
            'tests.interactive-command-workflow' => InteractiveCommandWorkflow::class,
        ]);
        Queue::fake();

        DB::connection()->beforeExecuting(function (string $query): void {
            if ($this->fullTable !== null && preg_match('/^(insert into|update) /i', $query) === 1 && str_contains($query, $this->fullTable)) {
                $exception = new PDOException('private SQL, credentials and payload');
                $exception->errorInfo = ['HY000', 1114, 'private table full'];
                throw $exception;
            }
        });
    }

    public function test_failed_start_is_safe_to_diagnose_and_does_not_replace_acknowledged_work(): void
    {
        $accepted = $this->start('accepted-before-capacity');
        $accepted->assertCreated();
        $runId = $accepted->json('run_id');
        $historyCount = WorkflowHistoryEvent::query()->where('run_id', $runId)->count();

        $this->fullTable = 'workflow_runs';
        $response = $this->start('failed-at-capacity');
        $response->assertStatus(503)
            ->assertHeader('X-Durable-Workflow-Control-Plane-Version', '2')
            ->assertJsonPath('reason', 'database_storage_exhausted')
            ->assertJsonPath('resource', 'database')
            ->assertJsonPath('retryable', false)
            ->assertJsonPath('control_plane.operation', 'start');
        $this->assertStringContainsString('Restore', $response->json('remediation'));
        $this->assertStringNotContainsString('private', $response->getContent());
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('secret-payload', $response->getContent());
        $this->assertDatabaseMissing('workflow_instances', ['id' => 'failed-at-capacity']);
        $this->assertSame($historyCount, WorkflowHistoryEvent::query()->where('run_id', $runId)->count());
        $this->assertTrue(WorkflowRun::query()->whereKey($runId)->exists());

        $this->fullTable = null;
        $this->start('failed-at-capacity')->assertCreated();
        $this->assertSame(1, (int) WorkflowInstance::query()->findOrFail('failed-at-capacity')->run_count);
        $this->assertSame($runId, WorkflowInstance::query()->findOrFail('accepted-before-capacity')->current_run_id);
    }

    public function test_worker_storage_failure_keeps_worker_protocol_metadata(): void
    {
        $this->registerWorker(workerId: 'capacity-worker', taskQueue: 'capacity-queue');
        WorkerRegistration::query()->where('worker_id', 'capacity-worker')
            ->update(['last_heartbeat_at' => now()->subMinute()]);
        $this->fullTable = 'workflow_worker_registrations';
        $this->postJson('/api/worker/heartbeat', [
            'worker_id' => 'capacity-worker',
            'task_queue' => 'capacity-queue',
        ], $this->workerHeaders())
            ->assertStatus(503)
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertJsonPath('reason', 'database_storage_exhausted')
            ->assertJsonPath('protocol_version', WorkerProtocol::VERSION)
            ->assertJsonPath('retryable', false);
    }

    public function test_database_readiness_explains_that_write_capacity_is_not_verified(): void
    {
        $this->fullTable = 'workflow_runs';
        $this->getJson('/api/ready')
            ->assertOk()
            ->assertJsonPath('checks.database.status', 'ok')
            ->assertJsonPath('checks.database.check_scope', 'connection_only')
            ->assertJsonPath('checks.database.write_capacity_verified', false);
    }

    private function start(string $id): TestResponse
    {
        return $this->postJson('/api/workflows', [
            'workflow_id' => $id,
            'workflow_type' => 'tests.interactive-command-workflow',
            'task_queue' => 'capacity-queue',
            'input' => ['secret-payload'],
        ], $this->apiHeaders());
    }
}
