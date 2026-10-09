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
        $schema = dirname(__DIR__, 2).'/rust/migrations/sqlite/0003_full_schema.sql';
        self::assertFileExists($schema);
        $this->database->exec(file_get_contents($schema));
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
        $new = $this->directory.'/new ? # %.sqlite';
        $uri = 'file:'.str_replace('%2F', '/', rawurlencode($new)).'?mode=rwc&cache=private';
        $this->artisan(['server:bootstrap', '--force'], ['DB_DATABASE' => $uri])->mustRun();
        self::assertFileExists($new);
        $database = new PDO('sqlite:'.$new);
        self::assertSame('default', $database->query('SELECT name FROM workflow_namespaces')->fetchColumn());
        $readonly = $this->directory.'/readonly.sqlite';
        self::assertNotSame(0, $this->artisan(['server:assert-php-database'], ['DB_DATABASE' => 'file:'.$readonly.'?mode=ro'])->getExitCode());
        self::assertFileDoesNotExist($readonly);
    }

    public function test_cache_only_recovery_commands_work_while_database_is_unavailable(): void
    {
        $this->initialize('sqlite');
        $this->database = null;
        $path = $this->environment['DB_DATABASE'];
        unlink($path);
        foreach ([['queue:restart'], ['schedule:clear-cache'], ['schedule:list']] as $command) {
            $this->artisan($command)->mustRun();
            self::assertFileDoesNotExist($path);
        }
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

    public function test_sqlite_hot_journal_recovers_unmarked_php_database_and_discards_probe(): void
    {
        $this->initialize('sqlite');
        $this->database->exec('CREATE TABLE sentinel (payload BLOB)');
        $this->database->exec('INSERT INTO sentinel VALUES (zeroblob(1048576))');
        $path = $this->environment['DB_DATABASE'];
        $this->crashSqliteWriter();
        $this->artisan(['server:assert-php-database', '--allow-unavailable'])->mustRun();
        $this->database = new PDO('sqlite:'.$path);
        self::assertSame(str_repeat('0', 32), $this->database->query('SELECT hex(substr(payload, 1, 16)) FROM sentinel')->fetchColumn());
        self::assertFileDoesNotExist($path.'-journal');
        $this->assertNoRecoveryCopy($path);
    }

    public function test_sqlite_hot_journal_restores_marker_in_private_copy_and_refuses_untouched_original(): void
    {
        $this->initialize('sqlite');
        $this->database->exec('CREATE TABLE dw_server_schema (engine TEXT, version INTEGER)');
        $this->database->exec("INSERT INTO dw_server_schema VALUES ('rust-development', 1)");
        $this->database->exec('CREATE TABLE sentinel (payload BLOB)');
        $this->database->exec('INSERT INTO sentinel VALUES (zeroblob(1048576))');
        $path = $this->environment['DB_DATABASE'];
        $this->crashSqliteWriter('leave-journal-drop-marker');
        $before = [hash_file('sha256', $path), hash_file('sha256', $path.'-journal')];
        $uri = 'file:'.str_replace('%2F', '/', rawurlencode($path)).'?mode=rw&immutable=1';
        foreach ([$path, $uri] as $location) {
            $process = $this->artisan(['server:assert-php-database', '--allow-unavailable'], ['DB_DATABASE' => $location]);
            self::assertSame(1, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            self::assertStringContainsString('php_database_refused', $process->getOutput());
            self::assertSame($before, [hash_file('sha256', $path), hash_file('sha256', $path.'-journal')]);
            $this->assertNoRecoveryCopy($path);
        }
    }

    public function test_sqlite_concurrent_php_startup_serializes_hot_journal_recovery(): void
    {
        $this->initialize('sqlite');
        $this->database->exec('CREATE TABLE sentinel (payload BLOB)');
        $this->database->exec('INSERT INTO sentinel VALUES (zeroblob(1048576))');
        $path = $this->environment['DB_DATABASE'];
        $this->crashSqliteWriter();
        $processes = [];
        try {
            for ($index = 0; $index < 3; $index++) {
                $process = new Process([PHP_BINARY, 'artisan', 'server:assert-php-database'], dirname(__DIR__, 2), $this->environment, timeout: 30);
                $process->start();
                $processes[] = $process;
            }
            foreach ($processes as $process) {
                $process->wait();
                self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            }
            $this->database = new PDO('sqlite:'.$path);
            self::assertSame(str_repeat('0', 32), $this->database->query('SELECT hex(substr(payload, 1, 16)) FROM sentinel')->fetchColumn());
            $this->assertNoRecoveryCopy($path);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(0);
                }
            }
        }
    }

    public function test_sqlite_killed_recovery_probe_is_discarded_on_restart(): void
    {
        $this->initialize('sqlite');
        $this->database->exec('CREATE TABLE sentinel (payload BLOB)');
        $this->database->exec('INSERT INTO sentinel VALUES (zeroblob(1048576))');
        $this->database->exec('CREATE TABLE acknowledged (payload BLOB)');
        $this->database->exec('INSERT INTO acknowledged VALUES (zeroblob(134217728))');
        $path = $this->environment['DB_DATABASE'];
        $this->crashSqliteWriter();
        $before = [hash_file('sha256', $path), hash_file('sha256', $path.'-journal')];
        $directory = $this->recoveryDirectory($path);
        $process = new Process([PHP_BINARY, 'artisan', 'server:assert-php-database'], dirname(__DIR__, 2), $this->environment, timeout: 30);
        $process->start();
        try {
            $deadline = microtime(true) + 10;
            do {
                clearstatcache(true, $directory.'/probe.sqlite');
                $copying = is_file($directory.'/probe.sqlite') && filesize($directory.'/probe.sqlite') > 0;
                if ($copying) {
                    break;
                }
                usleep(500);
            } while ($process->isRunning() && microtime(true) < $deadline);
            self::assertTrue($copying, $process->getOutput().$process->getErrorOutput());
            $process->signal(9);
            $process->wait();
            self::assertTrue($process->hasBeenSignaled());
            self::assertSame(9, $process->getTermSignal());
            self::assertSame($before, [hash_file('sha256', $path), hash_file('sha256', $path.'-journal')]);
            self::assertFileExists($directory.'/probe.sqlite');
            $this->artisan(['server:assert-php-database'])->mustRun();
            $this->assertNoRecoveryCopy($path);
            $this->database = new PDO('sqlite:'.$path);
            self::assertSame(134217728, (int) $this->database->query('SELECT length(payload) FROM acknowledged')->fetchColumn());
            self::assertSame(str_repeat('0', 32), $this->database->query('SELECT hex(substr(payload, 1, 16)) FROM sentinel')->fetchColumn());
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
    }

    public function test_sqlite_recovery_low_space_refuses_without_original_mutation(): void
    {
        if (! getenv('DW_TEST_OWNERSHIP_LOW_SPACE')) {
            self::markTestSkipped('Run in a bounded low-space TMPDIR to exercise real capacity refusal.');
        }
        $this->initialize('sqlite');
        $this->database->exec('CREATE TABLE sentinel (payload BLOB)');
        $this->database->exec('INSERT INTO sentinel VALUES (zeroblob(1048576))');
        $path = $this->environment['DB_DATABASE'];
        $this->crashSqliteWriter();
        self::assertLessThan(64 * 1024 * 1024, disk_free_space($this->directory));
        $before = [hash_file('sha256', $path), hash_file('sha256', $path.'-journal')];
        $process = $this->artisan(['server:assert-php-database', '--allow-unavailable']);
        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('php_database_ownership_unknown', $process->getOutput());
        self::assertStringContainsString('Insufficient space', $process->getOutput());
        self::assertSame($before, [hash_file('sha256', $path), hash_file('sha256', $path.'-journal')]);
        $this->assertNoRecoveryCopy($path);
    }

    public function test_sqlite_recovery_copy_cannot_authorize_a_replaced_database(): void
    {
        $this->initialize('sqlite');
        $this->database->exec('CREATE TABLE sentinel (payload BLOB)');
        $this->database->exec('INSERT INTO sentinel VALUES (zeroblob(1048576))');
        $this->database->exec('CREATE TABLE acknowledged (payload BLOB)');
        $this->database->exec('INSERT INTO acknowledged VALUES (zeroblob(134217728))');
        $path = $this->environment['DB_DATABASE'];
        $this->crashSqliteWriter();
        $directory = $this->recoveryDirectory($path);
        $before = [hash_file('sha256', $path), hash_file('sha256', $path.'-journal')];
        $process = new Process([PHP_BINARY, 'artisan', 'server:assert-php-database'], dirname(__DIR__, 2), $this->environment, timeout: 30);
        $process->start();
        try {
            $deadline = microtime(true) + 10;
            do {
                clearstatcache(true, $directory.'/probe.sqlite');
                $copying = is_file($directory.'/probe.sqlite') && filesize($directory.'/probe.sqlite') > 0;
                if ($copying) {
                    break;
                }
                usleep(500);
            } while ($process->isRunning() && microtime(true) < $deadline);
            self::assertTrue($copying, $process->getOutput().$process->getErrorOutput());
            rename($path, $path.'.prior');
            rename($path.'-journal', $path.'.prior-journal');
            $replacement = new PDO('sqlite:'.$path);
            $replacement->exec('CREATE TABLE dw_server_schema (engine TEXT, version INTEGER)');
            unset($replacement);
            $marked = hash_file('sha256', $path);
            $process->wait();
            self::assertSame(1, $process->getExitCode());
            self::assertStringContainsString('php_database_ownership_unknown', $process->getOutput());
            self::assertSame($marked, hash_file('sha256', $path));
            self::assertSame($before, [hash_file('sha256', $path.'.prior'), hash_file('sha256', $path.'.prior-journal')]);
            $this->assertNoRecoveryCopy($path);
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
    }

    public function test_sqlite_recovery_rejects_superjournal_footer_without_touching_external_file(): void
    {
        $this->initialize('sqlite');
        $this->database->exec('CREATE TABLE sentinel (payload BLOB)');
        $this->database->exec('INSERT INTO sentinel VALUES (zeroblob(1048576))');
        $path = $this->environment['DB_DATABASE'];
        $this->crashSqliteWriter();
        $directory = $this->recoveryDirectory($path);
        $external = $path.'-mj0000009aa';
        // Model a journal referencing an external super-journal. A writable
        // copy must never follow or reclaim this original cohort's filename.
        $contents = $directory.'/probe.sqlite-journal'."\0";
        file_put_contents($external, $contents);
        $checksum = array_sum(unpack('C*', $external));
        file_put_contents($path.'-journal', $external.pack('NN', strlen($external), $checksum)."\xd9\xd5\x05\xf9\x20\xa1\x63\xd7", FILE_APPEND);
        $before = [hash_file('sha256', $path), hash_file('sha256', $path.'-journal')];
        $process = $this->artisan(['server:assert-php-database', '--allow-unavailable']);
        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('Unsupported SQLite recovery journal', $process->getOutput());
        self::assertSame($contents, file_get_contents($external));
        self::assertSame($before, [hash_file('sha256', $path), hash_file('sha256', $path.'-journal')]);
        $this->assertNoRecoveryCopy($path);
    }

    private function assertNoRecoveryCopy(string $path): void
    {
        $directory = $this->recoveryDirectory($path);
        self::assertSame(['.', '..', 'lock'], scandir($directory));
        self::assertSame(0700, fileperms($directory) & 0777);
    }

    private function recoveryDirectory(string $path): string
    {
        return dirname($path).'/.dw-php-ownership-'.hash('sha256', basename($path)).'-'.posix_geteuid();
    }

    public function test_sqlite_root_startup_does_not_block_the_apache_runtime_user(): void
    {
        if (! function_exists('posix_geteuid') || posix_geteuid() !== 0 || ! posix_getpwnam('www-data')) {
            self::markTestSkipped('Run in the official image as root to verify its www-data handoff.');
        }
        $this->initialize('sqlite');
        $this->database->exec('CREATE TABLE sentinel (payload BLOB)');
        $this->database->exec('INSERT INTO sentinel VALUES (zeroblob(1048576))');
        $path = $this->environment['DB_DATABASE'];
        $uid = posix_getpwnam('www-data')['uid'];
        chown($this->directory, $uid);
        chown($path, $uid);
        $this->crashSqliteWriter();
        $this->artisan(['server:assert-php-database'])->mustRun();
        $this->assertNoRecoveryCopy($path);
        $process = new Process(['su', '--preserve-environment', '-s', '/bin/sh', 'www-data', '-c', 'php artisan server:assert-php-database'], dirname(__DIR__, 2), $this->environment, timeout: 30);
        $process->mustRun();
        clearstatcache(true, $path);
        self::assertSame($uid, fileowner($path));
        $this->database = new PDO('sqlite:'.$path);
        self::assertSame(str_repeat('0', 32), $this->database->query('SELECT hex(substr(payload, 1, 16)) FROM sentinel')->fetchColumn());
    }

    private function crashSqliteWriter(string $action = 'leave-journal'): void
    {
        $path = $this->environment['DB_DATABASE'];
        $this->database = null;
        $writer = new Process([PHP_BINARY, 'tests/Support/PhpDatabaseOwnershipProcess.php', $action], dirname(__DIR__, 2), $this->environment, timeout: 10);
        $writer->start();
        try {
            $deadline = microtime(true) + 5;
            while ($writer->isRunning() && ! str_contains($writer->getOutput(), 'journal-ready') && microtime(true) < $deadline) {
                usleep(10000);
            }
            self::assertStringContainsString('journal-ready', $writer->getOutput(), $writer->getErrorOutput());
            $writer->signal(9);
            $writer->wait();
            self::assertTrue($writer->hasBeenSignaled());
            self::assertSame(9, $writer->getTermSignal());
            self::assertFileExists($path.'-journal');
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

    public function test_mysql_table_only_role_can_probe_a_future_reserved_marker_with_its_explicit_grant(): void
    {
        $this->initialize('mysql');
        $this->database->exec('CREATE TABLE sentinel (value INTEGER NOT NULL)');
        $this->database->exec('INSERT INTO sentinel VALUES (42)');
        $user = 'dw_guard_'.bin2hex(random_bytes(6));
        $this->admin->exec("CREATE USER '{$user}'@'%' IDENTIFIED BY 'synthetic-test-password'");
        try {
            $this->admin->exec("GRANT SELECT, UPDATE ON {$this->databaseName}.sentinel TO '{$user}'@'%'");
            $environment = ['DB_USERNAME' => $user, 'DB_PASSWORD' => 'synthetic-test-password'];
            // A table-only role cannot prove absence without visibility of the
            // reserved name. SELECT and the table-scoped CREATE privilege let
            // the administrator grant access before that table even exists.
            self::assertSame(1, $this->artisan(['server:assert-php-database'], $environment)->getExitCode());
            $this->admin->exec("GRANT SELECT, CREATE ON {$this->databaseName}.dw_server_schema TO '{$user}'@'%'");
            $this->artisan(['server:assert-php-database'], $environment)->mustRun();
            $this->probe('mysql-init', $environment)->mustRun();
            self::assertSame(99, (int) $this->database->query('SELECT value FROM sentinel')->fetchColumn());
            $this->database->exec('UPDATE sentinel SET value = 42');
            $this->database->exec('CREATE TABLE dw_server_schema (engine VARCHAR(64), version INTEGER)');
            $process = $this->probe('mysql-init', $environment);
            self::assertSame(1, $process->getExitCode());
            self::assertStringContainsString('php_database_refused', $process->getErrorOutput());
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
            foreach (glob($this->directory.'/.dw-php-ownership-*') ?: [] as $directory) {
                foreach (glob($directory.'/*') ?: [] as $file) {
                    unlink($file);
                }
                rmdir($directory);
            }
            foreach (glob($this->directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->directory);
        }
        parent::tearDown();
    }
}
