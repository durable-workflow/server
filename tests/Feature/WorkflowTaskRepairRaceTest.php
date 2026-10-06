<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Throwable;
use Workflow\Serializers\Serializer;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\WorkflowStub;

class WorkflowTaskRepairRaceTest extends TestCase
{
    use ServerTestHelpers;

    private ?array $databaseAdmin = null;

    private string $databaseName;

    private function initializeDatabase(string $driver): void
    {
        $prefix = $driver === 'pgsql' ? 'DW_TEST_COMPLETION_PGSQL_' : 'DW_TEST_COMPLETION_MYSQL_';
        $host = getenv($prefix.'HOST');
        if (! $host) {
            $this->markTestSkipped('Set '.$prefix.'HOST to a disposable database test server.');
        }
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill') || ! function_exists('stream_socket_pair')) {
            $this->markTestSkipped('Process control and local sockets are required.');
        }
        $port = getenv($prefix.'PORT') ?: ($driver === 'pgsql' ? '5432' : '3306');
        $user = getenv($prefix.'USER') ?: ($driver === 'pgsql' ? 'postgres' : 'root');
        $password = getenv($prefix.'PASSWORD') ?: '';
        $this->databaseAdmin = [$driver.':host='.$host.';port='.$port.($driver === 'pgsql' ? ';dbname=postgres' : ''), $user, $password];
        $this->databaseName = 'dw_task_repair_'.bin2hex(random_bytes(6));
        $admin = new PDO(...$this->databaseAdmin);
        $admin->exec('CREATE DATABASE '.$this->databaseName);
        unset($admin);
        config(['database.default' => $driver, 'database.connections.'.$driver.'.host' => $host,
            'database.connections.'.$driver.'.port' => $port, 'database.connections.'.$driver.'.database' => $this->databaseName,
            'database.connections.'.$driver.'.username' => $user, 'database.connections.'.$driver.'.password' => $password,
            'database.connections.'.$driver.'.url' => null]);
        DB::purge($driver);
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        Queue::fake();
        $this->configureWorkflowTypes(['tests.external-greeting-workflow' => ExternalGreetingWorkflow::class]);
        $this->createNamespace('default');
        $this->registerWorker('repair-race-worker', 'repair-race');
    }

    protected function tearDown(): void
    {
        if ($this->databaseAdmin !== null) {
            DB::disconnect();
            $admin = new PDO(...$this->databaseAdmin);
            $admin->exec('DROP DATABASE '.$this->databaseName);
        }
        parent::tearDown();
    }

    public static function mutations(): array
    {
        $cases = [];
        foreach (['mysql', 'pgsql'] as $driver) {
            foreach (['heartbeat', 'complete', 'fail', 'waiting_history'] as $operation) {
                $cases[$driver.' '.$operation] = [$driver, $operation];
            }
        }

        return $cases;
    }

    #[DataProvider('mutations')]
    public function test_repair_can_lock_tasks_while_a_worker_mutation_waits_for_the_run(string $driver, string $operation): void
    {
        $this->initializeDatabase($driver);
        $runId = $this->postJson('/api/workflows', [
            'workflow_type' => 'tests.external-greeting-workflow', 'task_queue' => 'repair-race', 'input' => ['Ada'],
        ], $this->apiHeaders())->assertCreated()->json('run_id');
        $claim = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'repair-race-worker', 'task_queue' => 'repair-race', 'poll_request_id' => 'claim',
        ], $this->workerHeaders())->assertOk()->json('task');
        $this->assertIsArray($claim);
        $actors = [];
        DB::purge();
        try {
            $actors['repair'] = $this->forkActor('repair', $runId, $claim);
            $this->readMessage($actors['repair']);
            fwrite($actors['repair']['socket'], "go\n");
            $this->assertTrue($this->readMessage($actors['repair'])['run_locked']);
            $actors['worker'] = $this->forkActor($operation, $runId, $claim);
            $worker = $this->readMessage($actors['worker']);
            fwrite($actors['worker']['socket'], "go\n");
            $this->awaitRunWait($worker['connection_id']);

            // Repair holds the run, and the real HTTP request is waiting for
            // it. Its task must remain available to repair, rather than form
            // the opposite half of the captured task/run deadlock.
            DB::transaction(function () use ($claim): void {
                $task = WorkflowTask::query()->whereKey($claim['task_id'])->lock('for update nowait')->firstOrFail();
                $this->assertSame('repair-race-worker', $task->lease_owner);
                $this->assertSame($claim['workflow_task_attempt'], $task->attempt_count);
            });

            fwrite($actors['repair']['socket'], "continue\n");
            $this->assertTrue($this->finishActor($actors['repair'])['accepted']);
            unset($actors['repair']);
            $result = $this->finishActor($actors['worker']);
            unset($actors['worker']);
            $this->assertSame(200, $result['status'], json_encode($result));
            $this->assertTrue($result['body'][$operation === 'heartbeat' ? 'renewed' : 'recorded']);
            $this->assertSame(match ($operation) {
                'complete' => 'completed', 'waiting_history' => 'waiting', default => 'pending',
            },
                WorkflowRun::query()->findOrFail($runId)->status->value);
        } finally {
            foreach ($actors as $actor) {
                posix_kill($actor['pid'], SIGKILL);
                pcntl_waitpid($actor['pid'], $status);
                fclose($actor['socket']);
            }
            DB::purge();
            DB::reconnect();
        }
    }

    /** @return array{pid: int, socket: resource} */
    private function forkActor(string $operation, string $runId, array $claim): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($sockets);
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            fclose($sockets[0]);
            stream_set_timeout($sockets[1], 15);
            $ok = false;
            try {
                DB::reconnect();
                $idQuery = DB::connection()->getDriverName() === 'mysql'
                    ? 'SELECT CONNECTION_ID() AS id' : 'SELECT pg_backend_pid() AS id';
                fwrite($sockets[1], json_encode(['connection_id' => (int) DB::selectOne($idQuery)->id]).PHP_EOL);
                if (fgets($sockets[1]) !== "go\n") {
                    throw new RuntimeException('Actor was not released.');
                }
                if ($operation === 'repair') {
                    $paused = false;
                    DB::listen(static function (QueryExecuted $query) use (&$paused, $sockets): void {
                        if (! $paused && str_contains($query->sql, 'workflow_runs')
                            && str_contains(strtolower($query->sql), 'for update')) {
                            $paused = true;
                            fwrite($sockets[1], json_encode(['run_locked' => true]).PHP_EOL);
                            if (fgets($sockets[1]) !== "continue\n") {
                                throw new RuntimeException('Repair was not released.');
                            }
                        }
                    });
                    $result = ['accepted' => WorkflowStub::loadRun($runId)->repair()->accepted()];
                } else {
                    $body = ['lease_owner' => 'repair-race-worker',
                        'workflow_task_attempt' => $claim['workflow_task_attempt']];
                    if ($operation === 'complete') {
                        $body['commands'] = [['type' => 'complete_workflow',
                            'result' => Serializer::serializeWithCodec('avro', 'done')]];
                    } elseif (in_array($operation, ['fail', 'waiting_history'], true)) {
                        $body['failure'] = ['message' => 'Synthetic worker failure',
                            'type' => $operation === 'waiting_history' ? 'WorkflowTaskWaitingForHistory' : 'TestFailure'];
                    }
                    $endpoint = $operation === 'waiting_history' ? 'fail' : $operation;
                    $response = $this->postJson('/api/worker/workflow-tasks/'.$claim['task_id'].'/'.$endpoint,
                        $body, $this->workerHeaders());
                    $result = ['status' => $response->status(), 'body' => array_intersect_key($response->json(),
                        array_flip(['renewed', 'recorded', 'reason', 'outcome', 'run_status', 'errors']))];
                }
                fwrite($sockets[1], json_encode(['result' => $result], JSON_THROW_ON_ERROR).PHP_EOL);
                $ok = true;
            } catch (Throwable $error) {
                fwrite($sockets[1], json_encode(['error' => $error::class.': '.$error->getMessage()]).PHP_EOL);
            } finally {
                DB::disconnect();
                fclose($sockets[1]);
            }
            exit($ok ? 0 : 1);
        }
        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 15);

        return ['pid' => $pid, 'socket' => $sockets[0]];
    }

    private function awaitRunWait(int $connectionId): void
    {
        $query = DB::connection()->getDriverName() === 'mysql'
            ? 'SELECT COUNT(*) AS waiting FROM performance_schema.data_lock_waits w JOIN performance_schema.threads t ON t.THREAD_ID = w.REQUESTING_THREAD_ID WHERE t.PROCESSLIST_ID = ?'
            : 'SELECT COUNT(*) AS waiting FROM pg_locks WHERE pid = ? AND NOT granted';
        $until = microtime(true) + 5;
        do {
            if ((int) DB::selectOne($query, [$connectionId])->waiting > 0) {
                $this->addToAssertionCount(1);

                return;
            }
            usleep(10_000);
        } while (microtime(true) < $until);
        $this->fail('Worker mutation did not wait on the run lock, connection '.$connectionId.'.');
    }

    private function readMessage(array $actor): array
    {
        $line = fgets($actor['socket']);
        $this->assertIsString($line, 'Actor did not respond.');
        $message = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('error', $message, $message['error'] ?? '');

        return $message;
    }

    private function finishActor(array $actor): array
    {
        $message = $this->readMessage($actor);
        pcntl_waitpid($actor['pid'], $status);
        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));
        fclose($actor['socket']);

        return $message['result'];
    }
}
