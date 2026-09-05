<?php

namespace Tests\Feature;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class ExternalPayloadBackupHoldProcessTest extends TestCase
{
    /** @var list<Process> */
    private array $processes = [];

    private array $environment;

    private ?PDO $databaseAdmin = null;

    private string $database;

    private string $directory;

    public static function databases(): array
    {
        return [['sqlite'], ['mysql'], ['pgsql']];
    }

    #[DataProvider('databases')]
    public function test_deletion_barrier_persistence_expiry_and_stale_reads(string $driver): void
    {
        $this->initialize($driver);
        $owner = 'f36f49dc-1847-41a4-8f12-4a623495ff06';
        $delete = $this->probe('delete', 'first', $owner);
        $delete->start();
        $this->waitFor('first.entered', $delete);
        $acquire = $this->probe('acquire', 'first', $owner);
        $acquire->start();
        $this->waitFor('first.requested', $acquire);
        usleep(150000);
        self::assertTrue($acquire->isRunning(), $acquire->getErrorOutput());
        self::assertSame('', $acquire->getOutput(), 'Acquisition must wait for the in-flight deletion.');
        touch($this->directory.'/first.continue');
        $this->assertSuccess($delete);
        $this->assertSuccess($acquire);
        self::assertFileExists($this->directory.'/first.deleted');

        $status = $this->probe('status', 'status', $owner);
        $status->start();
        $this->assertSuccess($status);
        self::assertTrue(json_decode($status->getOutput(), true, flags: JSON_THROW_ON_ERROR)['active']);
        $blocked = $this->probe('delete', 'blocked', $owner);
        $blocked->run();
        self::assertSame(1, $blocked->getExitCode());
        self::assertStringContainsString('ExternalPayloadBackupInProgress', $blocked->getErrorOutput());
        self::assertFileDoesNotExist($this->directory.'/blocked.entered');

        $this->probe('expire', 'expire', $owner)->mustRun();
        $expired = $this->probe('status', 'expired', $owner);
        $expired->run();
        self::assertSame(1, $expired->getExitCode());
        touch($this->directory.'/expired.continue');
        $this->probe('delete', 'expired', $owner)->mustRun();
        self::assertFileExists($this->directory.'/expired.deleted');

        if ($driver === 'mysql') {
            $stale = $this->probe('stale-read-delete', 'stale', $owner);
            $stale->start();
            $this->waitFor('stale.snapshot', $stale);
            $next = '9592c686-4371-4896-bae8-bcc7548c41ce';
            $this->probe('acquire', 'next', $next)->mustRun();
            touch($this->directory.'/stale.continue');
            $stale->wait();
            self::assertSame(1, $stale->getExitCode());
            self::assertStringContainsString('ExternalPayloadBackupInProgress', $stale->getErrorOutput());
            self::assertFileDoesNotExist($this->directory.'/stale.deleted');
        }

        if ($driver === 'pgsql') {
            $expired = $this->probe('expired-transaction-status', 'clock', '9592c686-4371-4896-bae8-bcc7548c41ce');
            $expired->mustRun();
            self::assertFalse(json_decode($expired->getOutput(), true, flags: JSON_THROW_ON_ERROR)['active']);
        }
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
            foreach (glob($this->directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    private function initialize(string $driver): void
    {
        $prefix = $driver === 'pgsql' ? 'DW_TEST_BACKUP_PGSQL_' : 'DW_TEST_BACKUP_MYSQL_';
        $host = getenv($prefix.'HOST');
        if ($driver !== 'sqlite' && ! $host) {
            $this->markTestSkipped('Set '.$prefix.'HOST to a disposable database test server.');
        }
        $this->directory = sys_get_temp_dir().'/dw-backup-hold-process-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        $this->environment = [
            'APP_ENV' => 'testing',
            'APP_CONFIG_CACHE' => $this->directory.'/config.php',
            'CACHE_STORE' => 'array',
            'DB_CONNECTION' => $driver,
            'DB_URL' => false,
            'DB_DATABASE' => $this->directory.'/database.sqlite',
        ];
        if ($driver === 'sqlite') {
            touch($this->environment['DB_DATABASE']);
        } else {
            $this->database = 'dw_hold_'.bin2hex(random_bytes(6));
            $port = $driver === 'pgsql' ? '5432' : '3306';
            $user = getenv($prefix.'USER') ?: ($driver === 'pgsql' ? 'postgres' : 'root');
            $password = getenv($prefix.'PASSWORD') ?: '';
            $dsn = $driver.':host='.$host.';port='.$port.($driver === 'pgsql' ? ';dbname=postgres' : '');
            $this->databaseAdmin = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $this->databaseAdmin->exec('CREATE DATABASE '.$this->database);
            $this->environment = array_replace($this->environment, [
                'DB_HOST' => $host, 'DB_PORT' => $port, 'DB_DATABASE' => $this->database,
                'DB_USERNAME' => $user, 'DB_PASSWORD' => $password,
            ]);
        }
        $this->probe('init', 'init', '')->mustRun();
    }

    private function probe(string $action, string $barrier, string $owner): Process
    {
        $process = new Process(
            [PHP_BINARY, 'tests/Support/ExternalPayloadBackupHoldProcess.php', $action, $this->directory.'/'.$barrier, $owner],
            dirname(__DIR__, 2), $this->environment, timeout: 20,
        );
        $this->processes[] = $process;

        return $process;
    }

    private function waitFor(string $file, Process $process): void
    {
        $deadline = microtime(true) + 10;
        while (! is_file($this->directory.'/'.$file) && $process->isRunning() && microtime(true) < $deadline) {
            usleep(10000);
        }
        self::assertFileExists($this->directory.'/'.$file, $process->getErrorOutput());
    }

    private function assertSuccess(Process $process): void
    {
        $process->wait();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }
}
