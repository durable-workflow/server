<?php

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use App\Models\WorkflowNamespace;
use App\Support\CooperativeCancellationPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

class CooperativeCancellationRaceTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();
        $path = tempnam(sys_get_temp_dir(), 'dw-cooperative-race-');
        $this->assertIsString($path);
        $this->databasePath = $path;
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $path,
            'database.connections.sqlite.busy_timeout' => 100,
            'database.connections.sqlite.journal_mode' => 'WAL',
            'database.connections.sqlite.transaction_mode' => 'IMMEDIATE',
            'cache.default' => 'array',
            'server.worker_protocol.version' => '1.20',
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

    public static function workerCapabilities(): array
    {
        return ['old worker' => [false], 'cooperative worker' => [true]];
    }

    #[DataProvider('workerCapabilities')]
    public function test_concurrent_request_and_claim_cannot_accept_an_incompatible_lease(bool $capable): void
    {
        // Repeat real process races. Deterministic before/after cases live in
        // CooperativeCancellationProtocolTest; this covers overlapping writes.
        for ($iteration = 1; $iteration <= 3; $iteration++) {
            $workerId = 'race-worker-'.$iteration;
            $queue = 'race-'.$iteration;
            $start = $this->withHeaders($this->controlHeaders())->postJson('/api/workflows', [
                'workflow_type' => 'tests.external-greeting-workflow', 'task_queue' => $queue, 'input' => ['Ada'],
            ])->assertCreated();
            $workflowId = $start->json('workflow_id');
            $runId = $start->json('run_id');
            WorkerRegistration::query()->create([
                'namespace' => 'default', 'worker_id' => $workerId, 'task_queue' => $queue,
                'runtime' => 'php', 'supported_workflow_types' => ['tests.external-greeting-workflow'],
                'capabilities' => $capable ? [CooperativeCancellationPolicy::CAPABILITY] : [],
                'max_concurrent_workflow_tasks' => 1, 'last_heartbeat_at' => now(), 'status' => 'active',
            ]);

            [$request, $poll] = $this->race($workflowId, $workerId, $queue, $capable ? '1.20' : '1.19');
            $this->assertSame(200, $poll['status'], json_encode($poll));
            $this->assertContains($request['status'], [202, 409], json_encode($request));
            $run = WorkflowRun::query()->findOrFail($runId);
            $task = $poll['body']['task'];

            if ($request['status'] === 202) {
                $this->assertNotNull($run->cancellation_request_command_id);
                if ($capable) {
                    $this->assertIsArray($task);
                    $this->assertTrue(CooperativeCancellationPolicy::claimSupportsCancellation(
                        WorkflowTask::query()->findOrFail($task['task_id']),
                    ));
                } else {
                    $this->assertNull($task);
                }
            } else {
                $this->assertFalse($capable);
                $this->assertSame('active_claim_cancellation_not_supported', $request['body']['reason']);
                $this->assertNull($run->cancellation_request_command_id);
                $this->assertIsArray($task);
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function race(string $workflowId, string $workerId, string $queue, string $protocol): array
    {
        $children = [];
        try {
            foreach (['request', 'poll'] as $operation) {
                $process = proc_open([
                    PHP_BINARY, dirname(__DIR__).'/Fixtures/CooperativeCancellationRaceRequest.php',
                    $this->databasePath, $operation, $workflowId, $workerId, $queue, $protocol,
                ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                $this->assertIsResource($process);
                stream_set_timeout($pipes[1], 10);
                $children[] = ['process' => $process, 'pipes' => $pipes];
            }
            foreach ($children as $child) {
                $this->assertSame("ready\n", fgets($child['pipes'][1]));
            }
            foreach ($children as $child) {
                fwrite($child['pipes'][0], "go\n");
                fclose($child['pipes'][0]);
            }

            $results = [];
            foreach ($children as $child) {
                $output = stream_get_contents($child['pipes'][1]);
                $this->assertFalse(stream_get_meta_data($child['pipes'][1])['timed_out'], 'Concurrent request timed out.');
                $results[] = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
            }

            return $results;
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

    private function controlHeaders(): array
    {
        return ['X-Namespace' => 'default', 'X-Durable-Workflow-Control-Plane-Version' => '2'];
    }
}
