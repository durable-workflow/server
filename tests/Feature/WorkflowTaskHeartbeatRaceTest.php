<?php

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use App\Models\WorkflowNamespace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowTask;

class WorkflowTaskHeartbeatRaceTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();
        $path = tempnam(sys_get_temp_dir(), 'dw-heartbeat-race-');
        $this->assertIsString($path);
        $this->databasePath = $path;
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $path,
            'database.connections.sqlite.busy_timeout' => 100,
            'database.connections.sqlite.journal_mode' => 'WAL',
            'database.connections.sqlite.transaction_mode' => 'IMMEDIATE',
            'cache.default' => 'array',
            'workflows.v2.workflow_task_lease_seconds' => 1,
            'workflows.v2.types.workflows' => ['tests.external-greeting-workflow' => ExternalGreetingWorkflow::class],
        ]);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        Queue::fake();
        WorkflowNamespace::query()->create([
            'name' => 'default', 'description' => 'Test', 'retention_days' => 30, 'status' => 'active',
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

    public function test_heartbeat_cannot_acknowledge_an_old_claim_after_concurrent_reclaim(): void
    {
        $clock = now()->startOfSecond();
        $this->travelTo($clock);
        $this->withHeaders([
            'X-Namespace' => 'default', 'X-Durable-Workflow-Control-Plane-Version' => '2',
        ])->postJson('/api/workflows', [
            'workflow_type' => 'tests.external-greeting-workflow', 'task_queue' => 'heartbeat-race', 'input' => ['Ada'],
        ])->assertCreated();
        foreach (['original', 'replacement'] as $workerId) {
            WorkerRegistration::query()->create([
                'namespace' => 'default', 'worker_id' => $workerId, 'task_queue' => 'heartbeat-race',
                'runtime' => 'php', 'supported_workflow_types' => ['tests.external-greeting-workflow'],
                'capabilities' => [], 'max_concurrent_workflow_tasks' => 1,
                'last_heartbeat_at' => now(), 'status' => 'active',
            ]);
        }
        $claim = $this->withHeaders([
            'X-Namespace' => 'default', 'X-Durable-Workflow-Protocol-Version' => '1.19',
        ])->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'original', 'task_queue' => 'heartbeat-race', 'poll_request_id' => 'original-poll',
        ])->assertOk()->json('task');
        $this->assertIsArray($claim);

        $children = [];
        try {
            foreach (['heartbeat' => 'original', 'poll' => 'replacement'] as $operation => $workerId) {
                $process = proc_open([
                    PHP_BINARY, dirname(__DIR__).'/Fixtures/WorkflowTaskHeartbeatRaceRequest.php',
                    $this->databasePath, $operation, $claim['task_id'], $workerId,
                    $clock->toIso8601String(), (string) $claim['workflow_task_attempt'],
                ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                $this->assertIsResource($process);
                stream_set_timeout($pipes[1], 10);
                $children[$operation] = ['process' => $process, 'pipes' => $pipes];
                $this->assertSame("ready\n", fgets($pipes[1]));
            }
            fwrite($children['heartbeat']['pipes'][0], "go\n");
            $this->assertSame("checked\n", fgets($children['heartbeat']['pipes'][1]));
            // The guard passed before expiry. Both HTTP kernels now cross
            // expiry, letting Native claim while heartbeat renewal is paused.
            // No lease, owner or attempt is changed by the test harness.
            fwrite($children['poll']['pipes'][0], "go\n");
            $read = [$children['poll']['pipes'][1]];
            $write = $except = null;
            stream_select($read, $write, $except, 1);
            fwrite($children['heartbeat']['pipes'][0], "go\n");

            $results = [];
            foreach ($children as $operation => $child) {
                fclose($child['pipes'][0]);
                $output = stream_get_contents($child['pipes'][1]);
                $this->assertFalse(stream_get_meta_data($child['pipes'][1])['timed_out'], 'Heartbeat race timed out.');
                $results[$operation] = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
            }
            $heartbeat = $results['heartbeat'];
            $task = WorkflowTask::query()->findOrFail($claim['task_id']);
            $trace = json_encode([
                'heartbeat_status' => $heartbeat['status'],
                'heartbeat' => array_intersect_key($heartbeat['body'], array_flip([
                    'task_id', 'lease_owner', 'workflow_task_attempt', 'renewed', 'reason',
                ])),
                'replacement_claim' => is_array($results['poll']['body']['task'] ?? null)
                    ? array_intersect_key($results['poll']['body']['task'], array_flip([
                        'task_id', 'lease_owner', 'workflow_task_attempt',
                    ])) : null,
            ]);
            $this->assertContains($heartbeat['status'], [200, 409], $trace);
            $this->assertSame(200, $results['poll']['status'], json_encode($results['poll']));
            if ($heartbeat['status'] === 200) {
                $this->assertTrue($heartbeat['body']['renewed']);
                $this->assertSame($task->lease_owner, $heartbeat['body']['lease_owner'], $trace);
                $this->assertSame($task->attempt_count, $heartbeat['body']['workflow_task_attempt']);
                $this->assertSame('original', $task->lease_owner);
                $this->assertSame($claim['workflow_task_attempt'], $task->attempt_count);
                $this->assertNull($results['poll']['body']['task']);
            } else {
                // SQLite without a writer lock may let reclaim win and retry
                // the heartbeat transaction. Revalidation must then fence it.
                $this->assertSame('lease_owner_mismatch', $heartbeat['body']['reason'], $trace);
                $this->assertFalse($heartbeat['body']['renewed'] ?? false);
                $this->assertSame('replacement', $task->lease_owner);
                $this->assertSame($claim['workflow_task_attempt'] + 1, $task->attempt_count);
                $this->assertSame($claim['task_id'], $results['poll']['body']['task']['task_id']);
                $this->assertSame($task->lease_owner, $results['poll']['body']['task']['lease_owner']);
                $this->assertSame($task->attempt_count, $results['poll']['body']['task']['workflow_task_attempt']);
            }
        } finally {
            foreach ($children as $child) {
                if (proc_get_status($child['process'])['running']) {
                    proc_terminate($child['process']);
                }
                foreach ($child['pipes'] as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($child['process']);
            }
        }
    }
}
