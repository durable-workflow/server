<?php

namespace Tests\Feature;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class PhpDatabaseOwnershipProcessTest extends TestCase
{
    private string $directory;

    private array $environment;

    private ?PDO $admin = null;

    private ?PDO $database = null;

    private ?string $databaseName = null;

    public static function databases(): array
    {
        return [['sqlite'], ['mysql'], ['mariadb'], ['pgsql']];
    }

    #[DataProvider('databases')]
    public function test_markers_refuse_startup_and_write_capable_commands_without_mutation(string $driver): void
    {
        $this->initialize($driver);
        $this->database->exec('CREATE TABLE sentinel (value INTEGER NOT NULL)');
        $this->database->exec('INSERT INTO sentinel VALUES (42)');
        // Refuse even an empty or malformed marker, before reading its rows.
        $this->database->exec('CREATE TABLE dw_server_schema (unexpected INTEGER)');
        $before = $driver === 'sqlite' ? hash_file('sha256', $this->environment['DB_DATABASE']) : null;
        foreach ([
            ['server:assert-php-database', '--allow-unavailable'],
            ['server:bootstrap', '--force'],
            ['migrate', '--force'],
            ['migrate:fresh', '--force'],
            ['db:wipe', '--force'],
            ['queue:work', '--once', '--sleep=0', '--timeout=2'],
            ['schedule:evaluate', '--limit=1'],
            ['schedule:run'],
        ] as $command) {
            $process = $this->artisan($command);
            self::assertNotSame(0, $process->getExitCode(), implode(' ', $command).': '.$process->getOutput().$process->getErrorOutput());
            self::assertStringContainsString('php_database_refused', $process->getOutput().$process->getErrorOutput());
            self::assertSame(42, (int) $this->database->query('SELECT value FROM sentinel')->fetchColumn());
            if ($driver === 'sqlite') {
                self::assertSame($before, hash_file('sha256', $this->environment['DB_DATABASE']));
                self::assertFileDoesNotExist($this->environment['DB_DATABASE'].'-wal');
            }
        }
        $this->database->exec('DROP TABLE dw_server_schema');
        $this->database->exec('CREATE TABLE dw_server_schema (engine VARCHAR(64), version INTEGER)');
        $this->database->exec("INSERT INTO dw_server_schema VALUES ('future-unknown-engine', 999)");
        $process = $this->artisan(['server:assert-php-database']);
        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('php_database_refused', $process->getOutput());
    }

    #[DataProvider('databases')]
    public function test_unmarked_php_database_can_bootstrap_and_reconnect(string $driver): void
    {
        $this->initialize($driver);
        $this->artisan(['server:assert-php-database'])->mustRun();
        $this->artisan(['server:bootstrap', '--force'])->mustRun();
        self::assertSame('default', $this->database->query("SELECT name FROM workflow_namespaces WHERE name = 'default'")->fetchColumn());
        // Each command is a fresh process/connection. New ownership must be
        // checked again; an earlier accepted connection is not a cached permit.
        $this->database->exec('CREATE TABLE dw_server_schema (engine VARCHAR(64), version INTEGER)');
        $this->database->exec("INSERT INTO dw_server_schema VALUES ('rust-development', 1)");
        self::assertSame(1, $this->artisan(['server:assert-php-database'])->getExitCode());
    }

    public function test_sqlite_probe_observes_uncheckpointed_wal_and_ignores_immutable_uri_option(): void
    {
        $this->initialize('sqlite');
        $this->database->exec('PRAGMA journal_mode=WAL');
        $this->database->exec('PRAGMA wal_autocheckpoint=0');
        $this->database->exec(file_get_contents(dirname(__DIR__, 2).'/rust/migrations/sqlite/0001_development.sql'));
        $path = $this->environment['DB_DATABASE'];
        self::assertFileExists($path.'-wal');
        $before = [hash_file('sha256', $path), hash_file('sha256', $path.'-wal')];
        $uri = 'file:'.str_replace('%2F', '/', rawurlencode($path)).'?mode=rw&immutable=1';
        foreach ([$path, $uri] as $location) {
            $process = $this->artisan(['server:assert-php-database'], ['DB_DATABASE' => $location]);
            self::assertSame(1, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            self::assertStringContainsString('php_database_refused', $process->getOutput());
            self::assertSame($before, [hash_file('sha256', $path), hash_file('sha256', $path.'-wal')]);
        }
    }

    public function test_sqlite_uri_and_ordinary_filename_characters_preserve_unmarked_access(): void
    {
        $this->initialize('sqlite');
        $this->database = null;
        $old = $this->environment['DB_DATABASE'];
        $path = $this->directory.'/spaces ? # %.sqlite';
        rename($old, $path);
        self::assertSame(0, $this->artisan(['server:assert-php-database'], ['DB_DATABASE' => $path])->getExitCode());
        $uri = 'file:'.str_replace('%2F', '/', rawurlencode($path)).'?mode=rw&cache=private';
        self::assertSame(0, $this->artisan(['server:assert-php-database'], ['DB_DATABASE' => $uri])->getExitCode());
    }

    public function test_sqlite_direct_nested_wipe_and_standard_entrypoint_refuse_marked_database(): void
    {
        $this->initialize('sqlite');
        $this->database->exec('CREATE TABLE dw_server_schema (engine TEXT, version INTEGER)');
        $path = $this->environment['DB_DATABASE'];
        $before = hash_file('sha256', $path);
        // The default connection is innocent; the explicitly selected target
        // is marked. A nested Artisan call can bypass CommandStarting events.
        $process = $this->probe('wipe-other');
        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('php_database_refused', $process->getErrorOutput());
        $entrypoint = new Process(['sh', 'docker/entrypoint.sh', 'true'], dirname(__DIR__, 2), $this->environment, timeout: 30);
        $entrypoint->run();
        self::assertSame(1, $entrypoint->getExitCode());
        self::assertStringContainsString('php_database_refused', $entrypoint->getOutput().$entrypoint->getErrorOutput());
        self::assertSame($before, hash_file('sha256', $path));
    }

    public function test_sqlite_hot_journal_probe_fails_without_recovering_or_changing_unknown_database(): void
    {
        $this->initialize('sqlite');
        $this->database->exec('CREATE TABLE sentinel (payload BLOB)');
        $this->database->exec('INSERT INTO sentinel VALUES (zeroblob(1048576))');
        $path = $this->environment['DB_DATABASE'];
        $this->database = null;
        $writer = new Process([PHP_BINARY, 'tests/Support/PhpDatabaseOwnershipProcess.php', 'leave-journal'], dirname(__DIR__, 2), $this->environment, timeout: 10);
        $writer->start();
        try {
            $deadline = microtime(true) + 5;
            while ($writer->isRunning() && ! str_contains($writer->getOutput(), 'journal-ready') && microtime(true) < $deadline) {
                usleep(10000);
            }
            self::assertStringContainsString('journal-ready', $writer->getOutput());
            $writer->signal(9);
            $writer->wait();
            self::assertTrue($writer->hasBeenSignaled());
            self::assertSame(9, $writer->getTermSignal());
            self::assertFileExists($path.'-journal');
            $before = [hash_file('sha256', $path), hash_file('sha256', $path.'-journal')];
            $process = $this->artisan(['server:assert-php-database', '--allow-unavailable']);
            self::assertSame(1, $process->getExitCode());
            self::assertStringContainsString('php_database_ownership_unknown', $process->getOutput());
            self::assertSame($before, [hash_file('sha256', $path), hash_file('sha256', $path.'-journal')]);
        } finally {
            if ($writer->isRunning()) {
                $writer->stop(0);
            }
        }
    }

    public function test_mysql_init_command_cannot_write_before_ownership_check(): void
    {
        $this->initialize('mysql');
        $this->database->exec('CREATE TABLE sentinel (value INTEGER NOT NULL)');
        $this->database->exec('INSERT INTO sentinel VALUES (42)');
        $this->database->exec('CREATE TABLE dw_server_schema (engine VARCHAR(64), version INTEGER)');
        $process = $this->probe('mysql-init');
        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('php_database_refused', $process->getErrorOutput());
        self::assertSame(42, (int) $this->database->query('SELECT value FROM sentinel')->fetchColumn());
        $this->database->exec('DROP TABLE dw_server_schema');
        $this->probe('mysql-init')->mustRun();
        self::assertSame(99, (int) $this->database->query('SELECT value FROM sentinel')->fetchColumn());
        self::assertSame(0, $this->probe('mysql-silent')->getExitCode());
        $this->database->exec('CREATE TABLE dw_server_schema (engine VARCHAR(64), version INTEGER)');
        self::assertSame(1, $this->probe('mysql-silent')->getExitCode());
    }

    public function test_postgres_configured_schema_is_checked_before_search_path_setup(): void
    {
        $this->initialize('pgsql');
        $this->database->exec('CREATE SCHEMA other_runtime');
        $this->database->exec('CREATE TABLE other_runtime.dw_server_schema (engine VARCHAR(64), version INTEGER)');
        $process = $this->probe('pgsql-schema');
        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('php_database_refused', $process->getErrorOutput());
    }

    public function test_mysql_hidden_marker_and_probe_permission_failure_do_not_allow_init_sql(): void
    {
        $this->initialize('mysql');
        $this->database->exec('CREATE TABLE sentinel (value INTEGER NOT NULL)');
        $this->database->exec('INSERT INTO sentinel VALUES (42)');
        $this->database->exec('CREATE TABLE dw_server_schema (engine VARCHAR(64), version INTEGER)');
        $user = 'dw_guard_'.bin2hex(random_bytes(6));
        $this->admin->exec("CREATE USER '{$user}'@'%' IDENTIFIED BY 'synthetic-test-password'");
        try {
            $this->admin->exec("GRANT SELECT, UPDATE ON {$this->databaseName}.sentinel TO '{$user}'@'%'");
            $environment = ['DB_USERNAME' => $user, 'DB_PASSWORD' => 'synthetic-test-password'];
            $process = $this->artisan(['server:assert-php-database', '--allow-unavailable'], $environment);
            self::assertSame(1, $process->getExitCode());
            self::assertStringContainsString('php_database_ownership_unknown', $process->getOutput());
            self::assertSame(1, $this->probe('mysql-init', $environment)->getExitCode());
            self::assertSame(42, (int) $this->database->query('SELECT value FROM sentinel')->fetchColumn());
        } finally {
            $this->admin->exec("DROP USER '{$user}'@'%'");
        }
    }

    private function initialize(string $driver): void
    {
        $prefix = $driver === 'pgsql' ? 'DW_TEST_BACKUP_PGSQL_' : 'DW_TEST_BACKUP_MYSQL_';
        $host = getenv($prefix.'HOST');
        if ($driver !== 'sqlite' && ! $host) {
            self::markTestSkipped('Set '.$prefix.'HOST to an isolated test database.');
        }
        $this->directory = sys_get_temp_dir().'/dw-php-ownership-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        $this->environment = [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false',
            'APP_CONFIG_CACHE' => $this->directory.'/config.php',
            'APP_KEY' => 'base64:UTyp33UhGolgzCK5CJmT+hNHcA+dJyp3+oINtX+VoPI=',
            'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'database',
            'DB_CONNECTION' => $driver, 'DB_URL' => false,
            'DB_DATABASE' => $this->directory.'/database.sqlite',
            // A marked file must be refused before this journal-mode change.
            'DB_SQLITE_JOURNAL_MODE' => 'DELETE',
            'DW_AUTH_DRIVER' => 'none', 'DW_SERVER_MODE' => 'service',
        ];
        if ($driver === 'sqlite') {
            $this->database = new PDO('sqlite:'.$this->environment['DB_DATABASE']);
        } else {
            $pdoDriver = $driver === 'mariadb' ? 'mysql' : $driver;
            $port = getenv($prefix.'PORT') ?: ($driver === 'pgsql' ? '5432' : '3306');
            $user = getenv($prefix.'USER') ?: ($driver === 'pgsql' ? 'postgres' : 'root');
            $password = getenv($prefix.'PASSWORD') ?: '';
            $this->admin = new PDO($pdoDriver.':host='.$host.';port='.$port.($driver === 'pgsql' ? ';dbname=postgres' : ''), $user, $password);
            $this->databaseName = 'dw_ownership_'.bin2hex(random_bytes(6));
            $this->admin->exec('CREATE DATABASE '.$this->databaseName);
            $this->database = new PDO($pdoDriver.':host='.$host.';port='.$port.';dbname='.$this->databaseName, $user, $password);
            $this->environment = array_replace($this->environment, [
                'DB_HOST' => $host, 'DB_PORT' => $port, 'DB_DATABASE' => $this->databaseName,
                'DB_USERNAME' => $user, 'DB_PASSWORD' => $password,
            ]);
        }
        $this->database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    private function artisan(array $arguments, array $environment = []): Process
    {
        $process = new Process([PHP_BINARY, 'artisan', ...$arguments], dirname(__DIR__, 2), array_replace($this->environment, $environment), timeout: 30);
        $process->run();

        return $process;
    }

    private function probe(string $action, array $environment = []): Process
    {
        $process = new Process([PHP_BINARY, 'tests/Support/PhpDatabaseOwnershipProcess.php', $action], dirname(__DIR__, 2), array_replace($this->environment, $environment), timeout: 30);
        $process->run();

        return $process;
    }

    protected function tearDown(): void
    {
        $this->database = null;
        if ($this->admin !== null && $this->databaseName !== null) {
            $this->admin->exec('DROP DATABASE '.$this->databaseName);
        }
        $this->admin = null;
        if (isset($this->directory)) {
            foreach (glob($this->directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->directory);
        }
        parent::tearDown();
    }
}
