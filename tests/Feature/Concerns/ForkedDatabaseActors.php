<?php

namespace Tests\Feature\Concerns;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Throwable;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;

/**
 * Controlled interleavings on real database connections (Server #774), on
 * MySQL, MariaDB, PostgreSQL and file-backed SQLite. Each actor is a child
 * process with its own connection. An actor can pause inside its transaction
 * right after a chosen statement runs, so a test decides which side holds a
 * lock and shows the other side waiting on it.
 *
 * MySQL, MariaDB and PostgreSQL show a waiting actor in their lock or
 * process views. SQLite has no row locks: a writer holds the database write
 * lock, and a waiting actor shows as not finishing while the first is paused.
 */
trait ForkedDatabaseActors
{
    use ServerTestHelpers;

    /**
     * One migrated database per driver for the whole test process, emptied
     * back to its migrated rows before each test.
     *
     * @var array<string, array{config: array<string, mixed>, tables: list<string>, seeds: array<string, list<array<string, mixed>>>}>
     */
    private static array $actorDatabases = [];

    /** @var array<int, array{pid: int, socket: resource}> */
    private array $actors = [];

    /** @return array<string, array{0: string}> */
    public static function drivers(): array
    {
        $drivers = ['mysql', 'mariadb', 'pgsql', 'sqlite'];

        return array_combine($drivers, array_map(static fn (string $driver): array => [$driver], $drivers));
    }

    private function initializeActorDatabase(string $driver): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill') || ! function_exists('stream_socket_pair')) {
            $this->markTestSkipped('Process control and local sockets are required.');
        }

        if (! isset(self::$actorDatabases[$driver])) {
            self::$actorDatabases[$driver] = $this->migrateActorDatabase($driver);
        } else {
            config(self::$actorDatabases[$driver]['config']);
            DB::purge($driver);
            $this->resetActorDatabase($driver, self::$actorDatabases[$driver]);
        }
        $this->createNamespace('default');
    }

    /**
     * Creates and migrates the process's database for $driver, records its
     * migrated rows, and drops it when the test process exits.
     *
     * @return array{config: array<string, mixed>, tables: list<string>, seeds: array<string, list<array<string, mixed>>>}
     */
    private function migrateActorDatabase(string $driver): array
    {
        $owner = getmypid();

        if ($driver === 'sqlite') {
            $directory = is_dir('/dev/shm') && is_writable('/dev/shm') ? '/dev/shm' : sys_get_temp_dir();
            $path = tempnam($directory, 'dw-stream-race-');
            $this->assertIsString($path);
            // The server's own SQLite settings: WAL and a five-second busy
            // timeout. PHP 8.3 begins transactions DEFERRED whatever the
            // configured mode, so the stream lock must hold without it.
            $config = ['database.default' => 'sqlite', 'database.connections.sqlite.database' => $path,
                'database.connections.sqlite.busy_timeout' => 5000, 'database.connections.sqlite.journal_mode' => 'WAL'];
            register_shutdown_function(static function () use ($owner, $path): void {
                if (getmypid() === $owner) {
                    foreach ([$path, $path.'-wal', $path.'-shm'] as $file) {
                        if (is_file($file)) {
                            @unlink($file);
                        }
                    }
                }
            });
        } else {
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
            $admin = [($driver === 'pgsql' ? 'pgsql' : 'mysql').':host='.$host.';port='.$port.($driver === 'pgsql' ? ';dbname=postgres' : ''), $user, $password];
            $name = 'dw_stream_race_'.bin2hex(random_bytes(6));
            (new PDO(...$admin))->exec('CREATE DATABASE '.$name);
            $config = ['database.default' => $driver, 'database.connections.'.$driver.'.host' => $host,
                'database.connections.'.$driver.'.port' => $port, 'database.connections.'.$driver.'.database' => $name,
                'database.connections.'.$driver.'.username' => $user, 'database.connections.'.$driver.'.password' => $password,
                'database.connections.'.$driver.'.url' => null];
            register_shutdown_function(static function () use ($owner, $admin, $name, $driver): void {
                if (getmypid() === $owner) {
                    try {
                        (new PDO(...$admin))->exec('DROP DATABASE '.$name.($driver === 'pgsql' ? ' WITH (FORCE)' : ''));
                    } catch (Throwable) {
                        // A disposable test server; nothing else to clean.
                    }
                }
            });
        }

        config($config);
        DB::purge($driver);
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);

        $schema = match ($driver) {
            'sqlite' => 'main',
            'pgsql' => 'public',
            default => DB::connection()->getDatabaseName(),
        };
        $tables = array_values(array_diff(Schema::getTableListing($schema, schemaQualified: false), ['migrations']));
        $seeds = [];
        foreach ($tables as $table) {
            $rows = DB::table($table)->get()->map(static fn (object $row): array => (array) $row)->all();
            if ($rows !== []) {
                $seeds[$table] = $rows;
            }
        }

        return ['config' => $config, 'tables' => $tables, 'seeds' => $seeds];
    }

    /**
     * Deletes every row a test wrote and restores the rows the migrations
     * wrote, with foreign keys unchecked while the tables are inconsistent.
     *
     * @param  array{tables: list<string>, seeds: array<string, list<array<string, mixed>>>}  $database
     */
    private function resetActorDatabase(string $driver, array $database): void
    {
        $connection = DB::connection();
        $grammar = $connection->getQueryGrammar();
        $wrapped = array_map(static fn (string $table): string => $grammar->wrapTable($table), $database['tables']);
        if ($driver === 'pgsql') {
            $connection->unprepared('TRUNCATE '.implode(', ', $wrapped).' CASCADE');
        } else {
            $connection->unprepared($driver === 'sqlite' ? 'PRAGMA foreign_keys = OFF' : 'SET FOREIGN_KEY_CHECKS = 0');
            foreach ($wrapped as $table) {
                $connection->unprepared('DELETE FROM '.$table);
            }
        }
        foreach ($database['seeds'] as $table => $rows) {
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table($table)->insert($chunk);
            }
        }
        if ($driver !== 'pgsql') {
            $connection->unprepared($driver === 'sqlite' ? 'PRAGMA foreign_keys = ON' : 'SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    protected function tearDown(): void
    {
        $this->tearDownActors();
        parent::tearDown();
    }

    /** Stops any actor still running. */
    private function tearDownActors(): void
    {
        foreach ($this->actors as $actor) {
            posix_kill($actor['pid'], SIGKILL);
            pcntl_waitpid($actor['pid'], $status);
            if (is_resource($actor['socket'])) {
                fclose($actor['socket']);
            }
        }
        $this->actors = [];
        DB::disconnect();
    }

    private function createRun(string $workflowId, RunStatus $status = RunStatus::Running): string
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
            'status' => $status->value,
            'started_at' => now(),
            'closed_at' => $status->isTerminal() ? now() : null,
            'last_progress_at' => now(),
        ]);
        $instance->forceFill(['current_run_id' => $run->id])->save();

        return $run->id;
    }

    /**
     * Starts $work in a child process on its own database connection and
     * returns once the child is connected. The child stops at each pause in
     * turn, until resumed: right after the first statement, or top-level
     * commit, for which the pause returns true. A pause is asked the
     * statement's lowercase SQL, or 'commit', with the driver and the
     * transaction level the statement ran at (0 after a top-level commit).
     *
     * @param  Closure(string $sql, string $driver, int $level): bool|list<Closure(string $sql, string $driver, int $level): bool>  $pauses
     * @return array{pid: int, socket: resource, connection_id: int}
     */
    private function startActor(callable $work, Closure|array $pauses = []): array
    {
        $pauses = $pauses instanceof Closure ? [$pauses] : $pauses;
        // The child must not share this process's database connection.
        DB::purge();
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
                $driver = DB::connection()->getDriverName();
                $connectionId = match ($driver) {
                    'mysql', 'mariadb' => (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id,
                    'pgsql' => (int) DB::selectOne('SELECT pg_backend_pid() AS id')->id,
                    default => getmypid(),
                };
                fwrite($sockets[1], json_encode(['connection_id' => $connectionId]).PHP_EOL);
                if (fgets($sockets[1]) !== "go\n") {
                    throw new RuntimeException('Actor was not released.');
                }
                if ($pauses !== []) {
                    $check = static function (string $sql, int $level) use (&$pauses, $sockets, $driver): void {
                        if ($pauses !== [] && $pauses[0]($sql, $driver, $level)) {
                            array_shift($pauses);
                            fwrite($sockets[1], json_encode(['paused' => true]).PHP_EOL);
                            if (fgets($sockets[1]) !== "continue\n") {
                                throw new RuntimeException('Actor was not resumed.');
                            }
                        }
                    };
                    DB::listen(static fn (QueryExecuted $query) => $check(strtolower(ltrim($query->sql)), $query->connection->transactionLevel()));
                    Event::listen(TransactionCommitted::class, static fn (TransactionCommitted $event) => $check('commit', $event->connection->transactionLevel()));
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
        $actor = ['pid' => $pid, 'socket' => $sockets[0]];
        $this->actors[$pid] = $actor;
        $actor['connection_id'] = (int) $this->readMessage($actor)['connection_id'];

        return $actor;
    }

    /** Releases an actor and waits until it reaches its first pause. */
    private function runUntilPaused(array $actor): void
    {
        fwrite($actor['socket'], "go\n");
        $this->assertTrue($this->readMessage($actor)['paused'] ?? false, 'Actor did not reach its pause.');
    }

    /** Resumes a paused actor and waits until it reaches its next pause. */
    private function resumeUntilPaused(array $actor): void
    {
        fwrite($actor['socket'], "continue\n");
        $this->assertTrue($this->readMessage($actor)['paused'] ?? false, 'Actor did not reach its next pause.');
    }

    private function release(array $actor): void
    {
        fwrite($actor['socket'], "go\n");
    }

    private function resume(array $actor): void
    {
        fwrite($actor['socket'], "continue\n");
    }

    /**
     * The actor is waiting on another actor's row lock in $table. MySQL
     * reports the lock wait and its table; PostgreSQL reports the actor
     * waiting on a lock in its locking read of $table. MariaDB has no
     * lock-wait table that shows a wait taken while the optimizer reads a
     * constant row, so there the actor must be executing its locking read of
     * $table and still be there a moment later. SQLite has no row locks: the
     * actor must not finish while the other holds the database write lock.
     */
    private function awaitWait(array $actor, string $table): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $this->assertStillRunning($actor);

            return;
        }

        [$query, $bindings] = match ($driver) {
            'mysql' => ['SELECT COUNT(*) AS waiting FROM performance_schema.data_lock_waits w '
                .'JOIN performance_schema.threads t ON t.THREAD_ID = w.REQUESTING_THREAD_ID '
                .'JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID '
                .'WHERE t.PROCESSLIST_ID = ? AND l.OBJECT_NAME = ?', [$actor['connection_id'], $table]],
            'mariadb' => ["SELECT COUNT(*) AS waiting FROM information_schema.PROCESSLIST WHERE ID = ? AND COMMAND <> 'Sleep' AND LOWER(INFO) LIKE ?",
                [$actor['connection_id'], '%'.$table.'%for update%']],
            default => ["SELECT COUNT(*) AS waiting FROM pg_stat_activity WHERE pid = ? AND wait_event_type = 'Lock' AND LOWER(query) LIKE ?",
                [$actor['connection_id'], '%'.$table.'%for update%']],
        };
        $until = microtime(true) + 5;
        do {
            if ((int) DB::selectOne($query, $bindings)->waiting > 0) {
                if ($driver === 'mariadb') {
                    $this->assertStillRunning($actor);
                    $this->assertSame(1, (int) DB::selectOne($query, $bindings)->waiting,
                        'The actor left its locking read of '.$table.' while the other actor held the lock.');
                }
                $this->addToAssertionCount(1);

                return;
            }
            usleep(10_000);
        } while (microtime(true) < $until);
        $this->fail('The actor did not wait on the other actor\'s lock on '.$table.', connection '.$actor['connection_id']
            .': '.json_encode($this->waitDiagnostics($driver, $actor['connection_id'])));
    }

    /** What the actor's connection is doing, for a failure message. */
    private function waitDiagnostics(string $driver, int $connectionId): array
    {
        return match ($driver) {
            'mysql' => DB::select('SELECT l.OBJECT_NAME, l.INDEX_NAME, l.LOCK_TYPE, l.LOCK_MODE, l.LOCK_DATA, t.PROCESSLIST_INFO '
                .'FROM performance_schema.data_lock_waits w '
                .'JOIN performance_schema.threads t ON t.THREAD_ID = w.REQUESTING_THREAD_ID '
                .'JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID '
                .'WHERE t.PROCESSLIST_ID = ?', [$connectionId]),
            'mariadb' => DB::select('SELECT COMMAND, STATE, INFO FROM information_schema.PROCESSLIST WHERE ID = ?', [$connectionId]),
            default => DB::select('SELECT state, wait_event_type, wait_event, query FROM pg_stat_activity WHERE pid = ?', [$connectionId]),
        };
    }

    private function assertStillRunning(array $actor): void
    {
        $read = [$actor['socket']];
        $write = $except = null;
        $this->assertSame(0, stream_select($read, $write, $except, 0, 300_000),
            'The actor finished while the other actor held its locks.');
    }

    private function readMessage(array $actor): array
    {
        $line = fgets($actor['socket']);
        $this->assertIsString($line, 'Actor did not respond.');
        $message = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('error', $message, $message['error'] ?? '');

        return $message;
    }

    /** @return mixed The actor's result. */
    private function finishActor(array $actor): mixed
    {
        $message = $this->readMessage($actor);
        pcntl_waitpid($actor['pid'], $status);
        unset($this->actors[$actor['pid']]);
        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));
        fclose($actor['socket']);

        return $message['result'];
    }
}
