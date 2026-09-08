<?php

namespace Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class RuntimePayloadCompletionProcessTest extends TestCase
{
    private array $processes = [];

    private array $environment;

    private ?PDO $databaseAdmin = null;

    private string $database;

    private string $directory;

    public static function cases(): array
    {
        return [['sqlite', 'activity'], ['sqlite', 'workflow'], ['mysql', 'activity'], ['mysql', 'workflow']];
    }

    #[DataProvider('cases')]
    public function test_concurrent_uploads_and_cold_retries_preserve_one_bounded_lease(string $driver, string $kind): void
    {
        $this->initialize($driver);
        $this->runProbe('init', $kind);
        $first = $this->probe('upload', $kind, 'alpha', '0', 'first');
        $second = $this->probe('upload', $kind, 'bravo', '0', 'second');
        $first->start();
        $second->start();
        foreach (['first' => $first, 'second' => $second] as $name => $process) {
            $deadline = microtime(true) + 10;
            while (! is_file($this->directory.'/'.$name.'.ready') && $process->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            self::assertFileExists($this->directory.'/'.$name.'.ready', $process->getErrorOutput());
        }
        touch($this->directory.'/go');
        $a = $this->completedResult($first);
        $b = $this->completedResult($second);
        foreach ([$a, $b] as $response) {
            if ($response['status'] === 503) {
                self::assertSame('sqlite', $driver);
                self::assertSame('backend_lock_pressure', $response['body']['reason']);
                self::assertTrue($response['body']['retryable']);
            }
        }
        $statuses = [$a['status'], $b['status']];
        sort($statuses);
        self::assertContains($statuses, [[201, 409], [201, 503]], json_encode([$a, $b]));
        $winner = $a['status'] === 201 ? 'alpha' : 'bravo';
        $loser = $winner === 'alpha' ? 'bravo' : 'alpha';
        $accepted = $a['status'] === 201 ? $a : $b;
        self::assertSame($accepted, $this->runProbe('upload', $kind, $winner));
        self::assertSame(409, $this->runProbe('upload', $kind, $loser)['status']);
        $exhausted = $this->runProbe('upload', $kind, $loser, '1');
        self::assertSame(503, $exhausted['status']);
        self::assertSame('storage_pressure', $exhausted['body']['reason']);
        self::assertFalse($exhausted['body']['request_admitted']);
        $status = $this->runProbe('status', $kind);
        self::assertSame(['budgets' => 1, 'slots' => 1, 'objects' => 1,
            'bytes' => $accepted['body']['reference']['size_bytes'], 'rows' => 1], $status);
        self::assertSame(200, $this->runProbe('complete', $kind)['status']);
        // Every operation above and below boots a new application/database connection.
        self::assertSame($accepted, $this->runProbe('upload', $kind, $winner));
        self::assertSame($status, $this->runProbe('status', $kind));
        self::assertSame(409, $this->runProbe('upload', $kind, $loser, '1')['status']);
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
        if ($this->databaseAdmin !== null) {
            $this->databaseAdmin->exec('DROP DATABASE '.$this->database);
        }
        if (isset($this->directory)) {
            (new Filesystem)->deleteDirectory($this->directory);
        }
        parent::tearDown();
    }

    private function initialize(string $driver): void
    {
        $host = getenv('DW_TEST_COMPLETION_MYSQL_HOST');
        if ($driver === 'mysql' && ! $host) {
            $this->markTestSkipped('Set DW_TEST_COMPLETION_MYSQL_HOST to a disposable MySQL test server.');
        }
        $this->directory = sys_get_temp_dir().'/dw-completion-process-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        $this->environment = ['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => $this->directory.'/config.php',
            'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'database', 'DW_AUTH_DRIVER' => 'none',
            'DW_WORKER_POLL_TIMEOUT' => '0', 'DB_URL' => false, 'DB_CONNECTION' => $driver,
            'DB_DATABASE' => $this->directory.'/database.sqlite'];
        if ($driver === 'sqlite') {
            touch($this->environment['DB_DATABASE']);
        } else {
            $this->database = 'dw_completion_'.bin2hex(random_bytes(6));
            $user = getenv('DW_TEST_COMPLETION_MYSQL_USER') ?: 'root';
            $password = getenv('DW_TEST_COMPLETION_MYSQL_PASSWORD') ?: '';
            $this->databaseAdmin = new PDO('mysql:host='.$host.';port=3306', $user, $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $this->databaseAdmin->exec('CREATE DATABASE '.$this->database);
            $this->environment = array_replace($this->environment, ['DB_HOST' => $host, 'DB_PORT' => '3306',
                'DB_DATABASE' => $this->database, 'DB_USERNAME' => $user, 'DB_PASSWORD' => $password]);
        }
    }

    private function probe(string $action, string $kind, string $variant = 'alpha', string $slot = '0', string $barrier = ''): Process
    {
        $process = new Process([PHP_BINARY, 'tests/Support/RuntimePayloadCompletionProcess.php', $action,
            $this->directory, $kind, $variant, $slot, $barrier], dirname(__DIR__, 2), $this->environment,
            timeout: $action === 'init' ? 120 : 30);
        $this->processes[] = $process;

        return $process;
    }

    private function runProbe(string $action, string $kind, string $variant = 'alpha', string $slot = '0'): array
    {
        $process = $this->probe($action, $kind, $variant, $slot);
        $process->start();

        return $this->completedResult($process);
    }

    private function completedResult(Process $process): array
    {
        $process->wait();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        if (isset($result['body']['reference'])) {
            // MySQL JSON may reorder object keys; preserve strict scalar identity.
            ksort($result['body']['reference']);
        }

        return $result;
    }
}
