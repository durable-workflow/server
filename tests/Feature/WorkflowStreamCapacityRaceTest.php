<?php

namespace Tests\Feature;

use App\Models\WorkflowDurableStream;
use App\Models\WorkflowDurableStreamItem;
use App\Support\StreamFullException;
use App\Support\WorkflowStreamService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Throwable;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;

/**
 * Two near-cap appends to one stream on two real database connections
 * (Server #774). The first is paused inside its transaction, after it opens
 * or locks the stream row; the second must wait for it, then decide its whole
 * batch against the committed stream. Accepted items never exceed the cap,
 * and a refused batch writes nothing.
 */
class WorkflowStreamCapacityRaceTest extends TestCase
{
    use ServerTestHelpers;

    private const CAP = 5;

    private ?array $databaseAdmin = null;

    private string $databaseName;

    public static function drivers(): array
    {
        return ['mysql' => ['mysql'], 'pgsql' => ['pgsql']];
    }

    #[DataProvider('drivers')]
    public function test_concurrent_first_appends_open_one_stream_and_never_exceed_the_cap(string $driver): void
    {
        $this->initializeDatabase($driver);
        $runId = $this->createRun('wf-stream-race-open');

        // The first append pauses after inserting the stream row, before it
        // commits; the second append's insert waits on that row.
        [$first, $second] = $this->race($runId, pause: 'insert', firstItems: 3, secondItems: 3);

        $this->assertSame(['outcome' => 'accepted', 'accepted' => 3], $first);
        $this->assertSame(['outcome' => 'full'], $second);
        $this->assertSame(1, WorkflowDurableStream::query()->where('workflow_run_id', $runId)->count());
        $this->assertSame(3, WorkflowDurableStreamItem::query()->where('workflow_run_id', $runId)->count());
        $this->assertSame(3, (int) WorkflowDurableStream::query()->where('workflow_run_id', $runId)->value('pending_items'));
    }

    public static function contended(): array
    {
        $cases = [];
        foreach (['mysql', 'pgsql'] as $driver) {
            // 1 stored + 3 + 3 would pass the cap: the second batch is refused whole.
            $cases[$driver.' over'] = [$driver, 3, ['outcome' => 'full'], 4];
            // 1 stored + 3 + 1 meets the cap exactly: the second batch is admitted.
            $cases[$driver.' at'] = [$driver, 1, ['outcome' => 'accepted', 'accepted' => 1], 5];
        }

        return $cases;
    }

    #[DataProvider('contended')]
    public function test_contended_appends_decide_capacity_against_the_committed_stream(string $driver, int $secondItems, array $expected, int $total): void
    {
        $this->initializeDatabase($driver);
        $runId = $this->createRun('wf-stream-race-locked');
        $run = WorkflowRun::query()->findOrFail($runId);
        app(WorkflowStreamService::class)->append($run, 'default', 'tokens', [['payload' => 0]], self::CAP);

        // The first append pauses holding the stream row's lock; the second
        // waits for it.
        [$first, $second] = $this->race($runId, pause: 'lock', firstItems: 3, secondItems: $secondItems);

        $this->assertSame(['outcome' => 'accepted', 'accepted' => 3], $first);
        $this->assertSame($expected, $second);
        $this->assertSame($total, WorkflowDurableStreamItem::query()->where('workflow_run_id', $runId)->count());
        $this->assertSame($total, (int) WorkflowDurableStream::query()->where('workflow_run_id', $runId)->value('pending_items'));
        $this->assertSame(range(0, $total - 1), WorkflowDurableStreamItem::query()->where('workflow_run_id', $runId)
            ->orderBy('offset')->pluck('offset')->map(static fn ($offset): int => (int) $offset)->all());
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function race(string $runId, string $pause, int $firstItems, int $secondItems): array
    {
        $actors = [];
        DB::purge();
        try {
            $actors['first'] = $this->forkActor(fn (): array => $this->append($runId, $firstItems), $pause);
            $this->readMessage($actors['first']);
            fwrite($actors['first']['socket'], "go\n");
            $this->assertSame($pause, $this->readMessage($actors['first'])['paused']);

            $actors['second'] = $this->forkActor(fn (): array => $this->append($runId, $secondItems));
            $waiting = $this->readMessage($actors['second']);
            fwrite($actors['second']['socket'], "go\n");
            $this->awaitLockWait($waiting['connection_id']);

            fwrite($actors['first']['socket'], "continue\n");
            $first = $this->finishActor($actors['first']);
            unset($actors['first']);
            $second = $this->finishActor($actors['second']);
            unset($actors['second']);
        } finally {
            foreach ($actors as $actor) {
                posix_kill($actor['pid'], SIGKILL);
                pcntl_waitpid($actor['pid'], $status);
                fclose($actor['socket']);
            }
            DB::purge();
            DB::reconnect();
        }

        return [$first, $second];
    }

    /** @return array<string, mixed> */
    private function append(string $runId, int $count): array
    {
        $run = WorkflowRun::query()->findOrFail($runId);
        $items = array_map(static fn (int $n): array => ['payload' => $n], range(1, $count));

        try {
            $result = app(WorkflowStreamService::class)->append($run, 'default', 'tokens', $items, self::CAP);

            return ['outcome' => 'accepted', 'accepted' => $result['accepted']];
        } catch (StreamFullException) {
            return ['outcome' => 'full'];
        }
    }

    private function createRun(string $workflowId): string
    {
        $instance = WorkflowInstance::query()->create([
            'id' => $workflowId,
            'workflow_class' => 'Tests\\Fixtures\\StreamingWorkflow',
            'workflow_type' => 'tests.streaming-workflow',
            'namespace' => 'default',
            'run_count' => 1,
            'started_at' => now(),
        ]);
        $run = WorkflowRun::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'Tests\\Fixtures\\StreamingWorkflow',
            'workflow_type' => 'tests.streaming-workflow',
            'namespace' => 'default',
            'status' => RunStatus::Running->value,
            'started_at' => now(),
            'last_progress_at' => now(),
        ]);
        $instance->forceFill(['current_run_id' => $run->id])->save();

        return $run->id;
    }

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
        $this->databaseName = 'dw_stream_race_'.bin2hex(random_bytes(6));
        $admin = new PDO(...$this->databaseAdmin);
        $admin->exec('CREATE DATABASE '.$this->databaseName);
        unset($admin);
        config(['database.default' => $driver, 'database.connections.'.$driver.'.host' => $host,
            'database.connections.'.$driver.'.port' => $port, 'database.connections.'.$driver.'.database' => $this->databaseName,
            'database.connections.'.$driver.'.username' => $user, 'database.connections.'.$driver.'.password' => $password,
            'database.connections.'.$driver.'.url' => null]);
        DB::purge($driver);
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->createNamespace('default');
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

    /**
     * Runs $work in a child process on its own database connection. The child
     * reports its connection id and waits for "go". With $pause it stops until
     * "continue" right after it inserts the stream row ('insert') or locks it
     * ('lock'), inside the append's transaction.
     *
     * @return array{pid: int, socket: resource}
     */
    private function forkActor(callable $work, ?string $pause = null): array
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
                if ($pause !== null) {
                    $paused = false;
                    DB::listen(static function (QueryExecuted $query) use (&$paused, $sockets, $pause): void {
                        $sql = strtolower($query->sql);
                        $stream = str_contains($sql, 'workflow_durable_streams') && ! str_contains($sql, 'workflow_durable_stream_items');
                        $matches = $pause === 'insert'
                            ? str_starts_with(ltrim($sql), 'insert')
                            : str_contains($sql, 'for update') || str_contains($sql, 'for no key update');
                        if (! $paused && $stream && $matches) {
                            $paused = true;
                            fwrite($sockets[1], json_encode(['paused' => $pause]).PHP_EOL);
                            if (fgets($sockets[1]) !== "continue\n") {
                                throw new RuntimeException('Append was not released.');
                            }
                        }
                    });
                }
                fwrite($sockets[1], json_encode(['result' => $work()], JSON_THROW_ON_ERROR).PHP_EOL);
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

    private function awaitLockWait(int $connectionId): void
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
        $this->fail('The second append did not wait on the first append\'s stream row, connection '.$connectionId.'.');
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
