<?php

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use App\Models\WorkerSessionLease;
use App\Support\WorkerProtocol;
use App\Support\WorkerSessionRegistry;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Throwable;

/**
 * An explicit worker session request and a same-ID re-registration on two
 * real database connections (Server #740 R3). The session request is paused
 * just after it reads, and locks, the worker's current registration; the
 * re-registration must then wait for it, so the request can never check one
 * incarnation and write for the next.
 */
class WorkerSessionRegistrationRaceTest extends TestCase
{
    use ServerTestHelpers;

    private ?array $databaseAdmin = null;

    private string $databaseName;

    public static function verbs(): array
    {
        $cases = [];
        foreach (['mysql', 'pgsql'] as $driver) {
            foreach (['create', 'heartbeat', 'close'] as $verb) {
                $cases[$driver.' '.$verb] = [$driver, $verb];
            }
        }

        return $cases;
    }

    #[DataProvider('verbs')]
    public function test_a_session_request_holds_the_registration_until_it_commits(string $driver, string $verb): void
    {
        $this->initializeDatabase($driver);
        $first = $this->register();
        $this->postJson('/api/worker/sessions', ['worker_id' => 'race-worker', 'session_id' => 'race-state',
            'requirements' => ['gpu'], 'registration_token' => $first], $this->workerHeaders())->assertCreated();
        $actors = [];
        DB::purge();
        try {
            $actors['session'] = $this->forkActor(fn (): array => $this->sessionRequest($verb, $first), pause: 'lock');
            $this->readMessage($actors['session']);
            fwrite($actors['session']['socket'], "go\n");
            $this->assertNotNull($this->readMessage($actors['session'])['paused']);

            $actors['register'] = $this->forkActor(fn (): array => ['token' => $this->register()]);
            $replacement = $this->readMessage($actors['register']);
            fwrite($actors['register']['socket'], "go\n");
            // The re-registration waits on the row the session request holds.
            $this->awaitLockWait($replacement['connection_id']);

            fwrite($actors['session']['socket'], "continue\n");
            $session = $this->finishActor($actors['session']);
            unset($actors['session']);
            $second = $this->finishActor($actors['register'])['token'];
            unset($actors['register']);
        } finally {
            foreach ($actors as $actor) {
                posix_kill($actor['pid'], SIGKILL);
                pcntl_waitpid($actor['pid'], $status);
                fclose($actor['socket']);
            }
            DB::purge();
            DB::reconnect();
        }

        // The request ran wholly before the rotation, for the incarnation
        // that was current then.
        $this->assertSame(200, $session['status'], json_encode($session));
        $this->assertSame(match ($verb) {
            'create' => 'reused', 'heartbeat' => 'heartbeat_recorded', 'close' => 'closed'
        },
            $session['outcome'], json_encode($session));
        $this->assertIsString($second);
        $this->assertNotSame($first, $second);
        $this->assertSame(hash('sha256', $first),
            WorkerSessionLease::query()->where('session_id', 'race-state')->value('lease_owner_incarnation'));

        // After the rotation the old process is refused and changes nothing.
        $before = WorkerSessionLease::query()->where('session_id', 'race-state')->firstOrFail()->getAttributes();
        $this->sessionResponse($verb, $first)->assertStatus(409)->assertJsonPath('reason', 'stale_worker_registration');
        $this->assertSame($before, WorkerSessionLease::query()->where('session_id', 'race-state')->firstOrFail()->getAttributes());
    }

    public static function deregistrations(): array
    {
        $cases = [];
        foreach (['mysql', 'pgsql'] as $driver) {
            foreach (['heartbeat', 'close'] as $verb) {
                $cases[$driver.' '.$verb] = [$driver, $verb];
            }
        }

        return $cases;
    }

    /**
     * A published tokenless heartbeat or close and the worker's deregistration
     * serialize on the registration row: the request either commits while the
     * registration exists, as here, or sees none and is refused, as it is
     * afterwards, without changing the session.
     */
    #[DataProvider('deregistrations')]
    public function test_a_session_request_and_a_deregistration_serialize(string $driver, string $verb): void
    {
        $this->initializeDatabase($driver);
        $this->register(sendsToken: false);
        $this->sessionResponse('create', null)->assertCreated();
        $actors = [];
        DB::purge();
        try {
            $actors['session'] = $this->forkActor(fn (): array => $this->sessionRequest($verb, null), pause: 'lock');
            $this->readMessage($actors['session']);
            fwrite($actors['session']['socket'], "go\n");
            $this->assertNotNull($this->readMessage($actors['session'])['paused']);

            $actors['deregister'] = $this->forkActor(fn (): array => ['status' => $this->deleteJson('/api/worker/registrations/race-worker',
                [], $this->workerHeaders())->status()]);
            $deregistration = $this->readMessage($actors['deregister']);
            fwrite($actors['deregister']['socket'], "go\n");
            // The deregistration waits on the row the session request holds.
            $this->awaitLockWait($deregistration['connection_id']);

            fwrite($actors['session']['socket'], "continue\n");
            $session = $this->finishActor($actors['session']);
            unset($actors['session']);
            $this->assertSame(200, $this->finishActor($actors['deregister'])['status']);
            unset($actors['deregister']);
        } finally {
            foreach ($actors as $actor) {
                posix_kill($actor['pid'], SIGKILL);
                pcntl_waitpid($actor['pid'], $status);
                fclose($actor['socket']);
            }
            DB::purge();
            DB::reconnect();
        }

        $this->assertSame([200, $verb === 'heartbeat' ? 'heartbeat_recorded' : 'closed'], [$session['status'], $session['outcome']],
            json_encode($session));
        $before = WorkerSessionLease::query()->where('session_id', 'race-state')->firstOrFail()->getAttributes();
        $this->sessionResponse($verb, null)->assertStatus(412)->assertJsonPath('reason', 'worker_not_registered');
        $this->assertSame($before, WorkerSessionLease::query()->where('session_id', 'race-state')->firstOrFail()->getAttributes());
    }

    public static function drivers(): array
    {
        return ['mysql' => ['mysql'], 'pgsql' => ['pgsql']];
    }

    /**
     * Plain deregistration commits the registration as superseded, recovers
     * its leases and only then deletes the row. Inside that window a
     * published tokenless create, heartbeat or close is refused and changes
     * nothing; once the row is gone it is refused as unregistered.
     */
    #[DataProvider('drivers')]
    public function test_session_requests_inside_a_deregistrations_superseded_window_are_refused(string $driver): void
    {
        $this->initializeDatabase($driver);
        $this->register(sendsToken: false);
        $this->sessionResponse('create', null)->assertCreated();
        $actors = [];
        DB::purge();
        try {
            $actors['deregister'] = $this->forkActor(fn (): array => ['status' => $this->deleteJson('/api/worker/registrations/race-worker',
                [], $this->workerHeaders())->status()], pause: 'superseded');
            $this->readMessage($actors['deregister']);
            fwrite($actors['deregister']['socket'], "go\n");
            $this->assertSame('superseded', $this->readMessage($actors['deregister'])['paused']);

            $before = WorkerSessionLease::query()->where('session_id', 'race-state')->firstOrFail()->getAttributes();
            foreach (['create', 'heartbeat', 'close'] as $verb) {
                $this->sessionResponse($verb, null)->assertStatus(409)->assertJsonPath('reason', 'worker_registration_superseded');
            }
            $this->assertSame($before, WorkerSessionLease::query()->where('session_id', 'race-state')->firstOrFail()->getAttributes());

            fwrite($actors['deregister']['socket'], "continue\n");
            $this->assertSame(200, $this->finishActor($actors['deregister'])['status']);
            unset($actors['deregister']);
        } finally {
            foreach ($actors as $actor) {
                posix_kill($actor['pid'], SIGKILL);
                pcntl_waitpid($actor['pid'], $status);
                fclose($actor['socket']);
            }
            DB::purge();
            DB::reconnect();
        }

        $this->sessionResponse('heartbeat', null)->assertStatus(412)->assertJsonPath('reason', 'worker_not_registered');
    }

    public static function creates(): array
    {
        $cases = [];
        foreach (['mysql', 'pgsql'] as $driver) {
            $cases[$driver.' token worker'] = [$driver, true];
            $cases[$driver.' published worker'] = [$driver, false];
        }

        return $cases;
    }

    /**
     * A create read the worker's registration before a same-ID replacement
     * registered and reacquired the session, and continues only afterwards.
     * It is admitted against the registration as it is when it commits: a
     * worker that sends its token is refused with its old token and changes
     * nothing, and a published tokenless create reuses the replacement's
     * session. Neither restores the old incarnation.
     */
    #[DataProvider('creates')]
    public function test_a_create_admits_against_the_registration_current_when_it_commits(string $driver, bool $sendsToken): void
    {
        $this->initializeDatabase($driver);
        $first = $this->register($sendsToken);
        $this->sessionResponse('create', $sendsToken ? $first : null)->assertCreated();
        $actors = [];
        DB::purge();
        try {
            $actors['session'] = $this->forkActor(fn (): array => $this->sessionRequest('create', $sendsToken ? $first : null), pause: 'read');
            $this->readMessage($actors['session']);
            fwrite($actors['session']['socket'], "go\n");
            $this->assertNotNull($this->readMessage($actors['session'])['paused']);

            // The replacement registers and reacquires while the create waits
            // between its first read and its transaction.
            $second = $this->register($sendsToken);
            $this->sessionResponse('heartbeat', $sendsToken ? $second : null)->assertStatus(409)
                ->assertJsonPath('session.failure_reason', 'worker_registration_replaced');
            $this->sessionResponse('create', $sendsToken ? $second : null)->assertOk()->assertJsonPath('outcome', 'reacquired');
            $before = WorkerSessionLease::query()->where('session_id', 'race-state')->firstOrFail()->getAttributes();

            fwrite($actors['session']['socket'], "continue\n");
            $session = $this->finishActor($actors['session']);
            unset($actors['session']);
        } finally {
            foreach ($actors as $actor) {
                posix_kill($actor['pid'], SIGKILL);
                pcntl_waitpid($actor['pid'], $status);
                fclose($actor['socket']);
            }
            DB::purge();
            DB::reconnect();
        }

        $this->assertNotSame($first, $second);
        $after = WorkerSessionLease::query()->where('session_id', 'race-state')->firstOrFail()->getAttributes();
        if ($sendsToken) {
            $this->assertSame([409, 'stale_worker_registration'], [$session['status'], $session['reason']], json_encode($session));
            $this->assertSame($before, $after, 'the stale create changes nothing');
        } else {
            $this->assertSame([200, 'reused'], [$session['status'], $session['outcome']], json_encode($session));
        }
        $this->assertSame(hash('sha256', $second), $after['lease_owner_incarnation'], 'the session keeps the replacement as its holder');
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
        $this->databaseName = 'dw_session_race_'.bin2hex(random_bytes(6));
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

    /** Registers race-worker, by default declaring that it sends its token; returns the token. */
    private function register(bool $sendsToken = true): string
    {
        return (string) $this->postJson('/api/worker/register', [
            'worker_id' => 'race-worker',
            'task_queue' => 'race-queue',
            'runtime' => 'php',
            'supported_activity_types' => ['tests.race-activity'],
            'capabilities' => ['gpu', 'worker_sessions', ...($sendsToken ? [WorkerSessionRegistry::REGISTRATION_TOKEN_CAPABILITY] : [])],
            'capability_manifest' => array_replace($this->portableWorkerAffinityRefusalManifest(), [
                'worker_sessions' => ['supported' => true,
                    'minimum_protocol_version' => WorkerProtocol::PORTABLE_WORKER_AFFINITY_MINIMUM_PROTOCOL_VERSION,
                    'implementation' => 'worker_session_registration_race_test'],
            ]),
        ], $this->workerHeaders())->assertCreated()->json('registration_token');
    }

    private function sessionResponse(string $verb, ?string $token)
    {
        return match ($verb) {
            'create' => $this->postJson('/api/worker/sessions', array_filter(['worker_id' => 'race-worker', 'session_id' => 'race-state',
                'requirements' => ['gpu'], 'registration_token' => $token]), $this->workerHeaders()),
            'heartbeat' => $this->postJson('/api/worker/sessions/race-state/heartbeat',
                ['worker_id' => 'race-worker', 'registration_token' => $token, 'lease_seconds' => 900], $this->workerHeaders()),
            'close' => $this->deleteJson('/api/worker/sessions/race-state',
                ['worker_id' => 'race-worker', 'registration_token' => $token], $this->workerHeaders()),
        };
    }

    /** @return array<string, mixed> */
    private function sessionRequest(string $verb, ?string $token): array
    {
        $response = $this->sessionResponse($verb, $token);

        return ['status' => $response->status(), 'outcome' => $response->json('outcome'), 'reason' => $response->json('reason')];
    }

    /**
     * Runs $work in a child process on its own database connection. The child
     * reports its connection id, waits for "go", and with $pause stops until
     * "continue" right after it first reads the worker's registration ('read')
     * or locks it ('lock').
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
                if ($pause === 'superseded') {
                    // Pause right after a transaction commits the registration
                    // as superseded, while the row still exists.
                    $paused = false;
                    Event::listen(TransactionCommitted::class, static function () use (&$paused, $sockets, $pause): void {
                        if (! $paused && DB::table('workflow_worker_registrations')->where('worker_id', 'race-worker')
                            ->value('status') === WorkerRegistration::STATUS_SUPERSEDED) {
                            $paused = true;
                            fwrite($sockets[1], json_encode(['paused' => $pause]).PHP_EOL);
                            if (fgets($sockets[1]) !== "continue\n") {
                                throw new RuntimeException('Deregistration was not released.');
                            }
                        }
                    });
                } elseif ($pause !== null) {
                    $paused = false;
                    DB::listen(static function (QueryExecuted $query) use (&$paused, $sockets, $pause): void {
                        if (! $paused && str_contains($query->sql, 'workflow_worker_registrations')
                            && ($pause === 'read' || str_contains(strtolower($query->sql), 'for update'))) {
                            $paused = true;
                            fwrite($sockets[1], json_encode(['paused' => $pause]).PHP_EOL);
                            if (fgets($sockets[1]) !== "continue\n") {
                                throw new RuntimeException('Session request was not released.');
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
        $this->fail('The re-registration did not wait on the session request\'s registration lock, connection '.$connectionId.'.');
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
