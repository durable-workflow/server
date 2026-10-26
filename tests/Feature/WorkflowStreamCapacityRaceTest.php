<?php

namespace Tests\Feature;

use App\Models\WorkflowDurableStream;
use App\Models\WorkflowDurableStreamItem;
use App\Support\StreamFullException;
use App\Support\WorkflowStreamRetention;
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
 * (Server #774), on MySQL, MariaDB, PostgreSQL and file-backed SQLite. The
 * first is paused inside its transaction, holding the run's stream lock,
 * after it opens or locks the stream row; the second must wait for it, then
 * decide its whole batch against the committed stream. Accepted items never
 * exceed the cap, and a refused batch writes nothing.
 *
 * MySQL, MariaDB and PostgreSQL show the second append waiting on a row
 * lock. SQLite has no row locks: its second append waits for the database
 * write lock, which shows as it not finishing while the first is paused.
 */
class WorkflowStreamCapacityRaceTest extends TestCase
{
    use ServerTestHelpers;

    private const CAP = 5;

    private const DRIVERS = ['mysql', 'mariadb', 'pgsql', 'sqlite'];

    private ?array $databaseAdmin = null;

    private string $databaseName;

    private ?string $sqlitePath = null;

    public static function drivers(): array
    {
        return array_combine(self::DRIVERS, array_map(static fn (string $driver): array => [$driver], self::DRIVERS));
    }

    public static function serverDrivers(): array
    {
        return ['mysql' => ['mysql'], 'mariadb' => ['mariadb'], 'pgsql' => ['pgsql']];
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
        foreach (self::DRIVERS as $driver) {
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
     * The retention pass reads a namespace's due streams from the
     * (namespace, reclaim_at, id) index: a range scan in deadline order with
     * no sort, which stops at the limit. Each namespace here mostly holds
     * rows the pass must not read: open streams and reclaimed terminal rows
     * (no deadline) and streams not yet due. MySQL and MariaDB show the rows
     * read in their handler counters. PostgreSQL's planner prefers a
     * sequential scan of a small table, so its plan is read with sequential
     * scans disabled, which shows the index serves predicate and order.
     */
    #[DataProvider('serverDrivers')]
    public function test_due_stream_discovery_reads_the_deadline_index(string $driver): void
    {
        $this->initializeDatabase($driver);
        $now = now()->startOfSecond();
        foreach (['default', 'elsewhere'] as $namespace) {
            $rows = [];
            for ($n = 0; $n < 2400; $n++) {
                // 1800 without a deadline, 570 not yet due, 30 due.
                $reclaimAt = match (true) {
                    $n < 1800 => null,
                    $n < 2370 => $now->copy()->addSeconds($n),
                    default => $now->copy()->subSeconds($n),
                };
                $rows[] = [
                    'namespace' => $namespace,
                    'workflow_instance_id' => 'plan-'.$namespace,
                    'workflow_run_id' => sprintf('%s%08d', $namespace === 'default' ? 'D' : 'E', $n),
                    'stream_name' => 'stream',
                    'status' => $reclaimAt === null && $n % 2 === 0 ? 'open' : 'closed',
                    'closed_at' => $reclaimAt === null && $n % 2 === 0 ? null : $now,
                    'reclaim_at' => $reclaimAt,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            foreach (array_chunk($rows, 400) as $chunk) {
                DB::table('workflow_durable_streams')->insert($chunk);
            }
        }

        $query = WorkflowStreamRetention::dueStreamsQuery('default', 10, $now)->toBase();
        $sql = $query->toSql();

        if ($driver === 'pgsql') {
            DB::statement('ANALYZE workflow_durable_streams');
            $plan = DB::transaction(static function () use ($sql, $query): string {
                DB::statement('SET LOCAL enable_seqscan = off');

                return collect(DB::select('EXPLAIN (ANALYZE, COSTS OFF, TIMING OFF, SUMMARY OFF) '.$sql, $query->getBindings()))
                    ->map(static fn (object $row): string => (string) $row->{'QUERY PLAN'})->implode("\n");
            });
            $this->assertMatchesRegularExpression('/Index (Only )?Scan using wf_durable_streams_ns_reclaim_idx .*\(actual rows=10 loops=1\)/', $plan, $plan);
            $this->assertStringNotContainsString('Sort', $plan, $plan);
            $this->assertStringNotContainsString('Rows Removed', $plan, $plan);
        } else {
            DB::statement('ANALYZE TABLE workflow_durable_streams');
            $plan = DB::select('EXPLAIN '.$sql, $query->getBindings());
            $this->assertCount(1, $plan, json_encode($plan));
            $this->assertSame('wf_durable_streams_ns_reclaim_idx', $plan[0]->key, json_encode($plan));
            $this->assertSame('range', $plan[0]->type, json_encode($plan));
            $this->assertStringNotContainsString('filesort', (string) ($plan[0]->Extra ?? ''), json_encode($plan));
            // One PDO handle, so the counters are this session's own. MySQL
            // also counts the data-dictionary reads of a table's or a status
            // view's first use, so both run once before the counted read.
            $pdo = DB::connection()->getPdo();
            $statement = $pdo->prepare($sql);
            $statement->execute(DB::connection()->prepareBindings($query->getBindings()));
            $statement->fetchAll();
            $pdo->query("SHOW SESSION STATUS LIKE 'Handler_read%'")->fetchAll();
            $pdo->exec('FLUSH STATUS');
            $statement->execute(DB::connection()->prepareBindings($query->getBindings()));
            $this->assertCount(10, $statement->fetchAll());
            $reads = collect($pdo->query("SHOW SESSION STATUS LIKE 'Handler_read%'")->fetchAll(PDO::FETCH_KEY_PAIR))
                ->map(static fn (mixed $value): int => (int) $value);
            // One seek and nine index steps: the limit, not the namespace.
            $this->assertLessThanOrEqual(11, $reads['Handler_read_key'] + $reads['Handler_read_next'], json_encode($reads));
            $this->assertSame(0, $reads['Handler_read_rnd_next'], json_encode($reads));
            $plan = json_encode(['explain' => $plan, 'handler_reads' => $reads], JSON_PRETTY_PRINT);
        }
        fwrite(STDERR, sprintf("[%s due-stream plan]\n%s\n", $driver, $plan));

        $this->assertCount(10, $query->get());
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
            $this->awaitWait($actors['second'], $waiting['connection_id']);

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
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill') || ! function_exists('stream_socket_pair')) {
            $this->markTestSkipped('Process control and local sockets are required.');
        }

        if ($driver === 'sqlite') {
            $directory = is_dir('/dev/shm') && is_writable('/dev/shm') ? '/dev/shm' : sys_get_temp_dir();
            $path = tempnam($directory, 'dw-stream-race-');
            $this->assertIsString($path);
            $this->sqlitePath = $path;
            // The server's own SQLite settings: WAL and a five-second busy
            // timeout. PHP 8.3 begins transactions DEFERRED whatever the
            // configured mode, so the stream lock must hold without it.
            config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $path,
                'database.connections.sqlite.busy_timeout' => 5000, 'database.connections.sqlite.journal_mode' => 'WAL']);
            DB::purge('sqlite');
            $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
            $this->createNamespace('default');

            return;
        }

        $prefix = [
            'mysql' => 'DW_TEST_COMPLETION_MYSQL_',
            'mariadb' => 'DW_TEST_COMPLETION_MARIADB_',
            'pgsql' => 'DW_TEST_COMPLETION_PGSQL_',
        ][$driver];
        $host = getenv($prefix.'HOST');
        if (! $host) {
            $this->markTestSkipped('Set '.$prefix.'HOST to a disposable database test server.');
        }
        $port = getenv($prefix.'PORT') ?: ($driver === 'pgsql' ? '5432' : '3306');
        $user = getenv($prefix.'USER') ?: ($driver === 'pgsql' ? 'postgres' : 'root');
        $password = getenv($prefix.'PASSWORD') ?: '';
        $dsn = ($driver === 'pgsql' ? 'pgsql' : 'mysql').':host='.$host.';port='.$port.($driver === 'pgsql' ? ';dbname=postgres' : '');
        $this->databaseAdmin = [$dsn, $user, $password];
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
        if ($this->sqlitePath !== null) {
            DB::disconnect('sqlite');
            foreach ([$this->sqlitePath, $this->sqlitePath.'-wal', $this->sqlitePath.'-shm'] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
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
                $connectionId = match (DB::connection()->getDriverName()) {
                    'mysql', 'mariadb' => (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id,
                    'pgsql' => (int) DB::selectOne('SELECT pg_backend_pid() AS id')->id,
                    default => getmypid(),
                };
                fwrite($sockets[1], json_encode(['connection_id' => $connectionId]).PHP_EOL);
                if (fgets($sockets[1]) !== "go\n") {
                    throw new RuntimeException('Actor was not released.');
                }
                if ($pause !== null) {
                    $paused = false;
                    DB::listen(static function (QueryExecuted $query) use (&$paused, $sockets, $pause): void {
                        $sql = strtolower($query->sql);
                        $stream = str_contains($sql, 'workflow_durable_streams') && ! str_contains($sql, 'workflow_durable_stream_items');
                        // SQLite drops FOR UPDATE; its append holds the write
                        // lock from the run lock's no-op write on.
                        $matches = $pause === 'insert'
                            ? str_starts_with(ltrim($sql), 'insert')
                            : (DB::connection()->getDriverName() === 'sqlite'
                                ? str_starts_with(ltrim($sql), 'select')
                                : str_contains($sql, 'for update') || str_contains($sql, 'for no key update'));
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

    /**
     * The second append is waiting on the first. MySQL and PostgreSQL report
     * the lock wait itself. MariaDB has no lock-wait table that shows a wait
     * taken while the optimizer reads a constant row, so there the second
     * connection must be executing its run-lock statement and still be there
     * a moment later. SQLite has no row locks: the second append must not
     * have finished while the first holds the database write lock.
     */
    private function awaitWait(array $actor, int $connectionId): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $this->assertStillRunning($actor);

            return;
        }

        $query = match ($driver) {
            'mysql' => 'SELECT COUNT(*) AS waiting FROM performance_schema.data_lock_waits w JOIN performance_schema.threads t ON t.THREAD_ID = w.REQUESTING_THREAD_ID WHERE t.PROCESSLIST_ID = ?',
            'mariadb' => "SELECT COUNT(*) AS waiting FROM information_schema.PROCESSLIST WHERE ID = ? AND COMMAND <> 'Sleep' AND LOWER(INFO) LIKE '%workflow_runs%for update%'",
            default => 'SELECT COUNT(*) AS waiting FROM pg_locks WHERE pid = ? AND NOT granted',
        };
        $until = microtime(true) + 5;
        do {
            if ((int) DB::selectOne($query, [$connectionId])->waiting > 0) {
                if ($driver === 'mariadb') {
                    $this->assertStillRunning($actor);
                    $this->assertSame(1, (int) DB::selectOne($query, [$connectionId])->waiting,
                        'The second append left its run-lock statement while the first held the lock.');
                }
                $this->addToAssertionCount(1);

                return;
            }
            usleep(10_000);
        } while (microtime(true) < $until);
        $this->fail('The second append did not wait on the first append\'s locks, connection '.$connectionId.'.');
    }

    private function assertStillRunning(array $actor): void
    {
        $read = [$actor['socket']];
        $write = $except = null;
        $this->assertSame(0, stream_select($read, $write, $except, 0, 300_000),
            'The second append finished while the first held its locks.');
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
