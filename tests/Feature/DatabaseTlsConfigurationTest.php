<?php

namespace Tests\Feature;

use App\Database\DatabaseTlsConfiguration;
use App\Database\DatabaseTlsRefused;
use Illuminate\Support\Env;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DatabaseTlsConfigurationTest extends TestCase
{
    #[DataProvider('databaseTlsInputs')]
    public function test_database_tls_inputs_reach_the_selected_connection(array $environment, string $driver, string $mode, ?string $certificate): void
    {
        $repository = Env::getRepository();
        $original = [];
        foreach (['DB_SSLMODE', 'DB_SSL_MODE', 'DB_SSLROOTCERT', 'DB_SSL_ROOT_CERT'] as $key) {
            $original[$key] = $repository->get($key);
            $repository->clear($key);
            if (isset($environment[$key])) {
                $repository->set($key, $environment[$key]);
            }
        }

        try {
            $database = require base_path('config/database.php');
            self::assertSame($mode, $database['connections'][$driver]['sslmode'] ?? null);
            self::assertSame($certificate, $database['connections'][$driver]['sslrootcert'] ?? null);
        } finally {
            foreach ($original as $key => $value) {
                $repository->clear($key);
                if ($value !== null) {
                    $repository->set($key, $value);
                }
            }
        }
    }

    public static function databaseTlsInputs(): array
    {
        return [
            'postgres canonical names' => [['DB_SSLMODE' => 'verify-full', 'DB_SSLROOTCERT' => '/certs/ca.pem'], 'pgsql', 'verify-full', '/certs/ca.pem'],
            'postgres existing chart input' => [['DB_SSL_MODE' => 'require'], 'pgsql', 'require', null],
            'mysql required encryption' => [['DB_SSLMODE' => 'require'], 'mysql', 'require', null],
            'mariadb verified encryption' => [['DB_SSLMODE' => 'verify-full', 'DB_SSLROOTCERT' => '/certs/ca.pem'], 'mariadb', 'verify-full', '/certs/ca.pem'],
        ];
    }

    #[DataProvider('invalidTlsConfigurations')]
    public function test_invalid_tls_configuration_refuses_before_a_connection(array $configuration): void
    {
        $this->expectException(DatabaseTlsRefused::class);
        $this->expectExceptionCode(0);
        DatabaseTlsConfiguration::configure($configuration + ['driver' => 'pgsql', 'host' => 'database.test']);
    }

    public static function invalidTlsConfigurations(): array
    {
        return [
            'unknown mode' => [['sslmode' => 'silently-insecure']],
            'conflicting chart alias' => [['sslmode' => 'verify-full', 'sslmode_alias' => 'disable']],
            'URL cannot downgrade explicit mode' => [['sslmode' => 'disable', 'sslmode_requested' => 'verify-full']],
            'URL cannot replace explicit CA' => [['sslmode' => 'verify-full', 'sslrootcert' => '/wrong.pem', 'sslrootcert_requested' => '/original.pem']],
            'missing verifying CA' => [['sslmode' => 'verify-full']],
            'unreadable CA' => [['sslmode' => 'verify-full', 'sslrootcert' => '/not-a-real-database-ca.pem']],
            'postgres Unix socket' => [['sslmode' => 'require', 'host' => '/var/run/postgresql']],
            'postgres abstract socket' => [['sslmode' => 'require', 'host' => '@postgres']],
            'mysql implicit Unix socket' => [['driver' => 'mysql', 'sslmode' => 'require', 'host' => 'localhost']],
            'mysql explicit Unix socket' => [['driver' => 'mysql', 'sslmode' => 'require', 'unix_socket' => '/tmp/mysql.sock']],
            'unsupported MySQL chain-only verification' => [['driver' => 'mysql', 'sslmode' => 'verify-ca']],
        ];
    }

    public function test_required_mysql_tls_keeps_the_false_verification_option_and_requests_encryption(): void
    {
        $configuration = DatabaseTlsConfiguration::configure(['driver' => 'mysql', 'host' => 'database.test', 'sslmode' => 'required']);
        self::assertSame('require', $configuration['sslmode']);
        self::assertSame('DEFAULT', $configuration['options'][PDO::MYSQL_ATTR_SSL_CIPHER]);
        self::assertFalse($configuration['options'][PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]);
    }

    public function test_legacy_mysql_ca_can_supply_an_explicit_verified_connection(): void
    {
        $certificate = tempnam(sys_get_temp_dir(), 'database-ca-');
        try {
            $configuration = DatabaseTlsConfiguration::configure([
                'driver' => 'mysql', 'host' => 'database.test', 'sslmode' => 'verify-full',
                'options' => [PDO::MYSQL_ATTR_SSL_CA => $certificate],
            ]);
            self::assertSame($certificate, $configuration['sslrootcert']);
            self::assertTrue($configuration['options'][PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]);
        } finally {
            unlink($certificate);
        }
    }

    public function test_unconfigured_encryption_is_unknown_rather_than_reported_safe(): void
    {
        $snapshot = DatabaseTlsConfiguration::snapshot(['driver' => 'pgsql', 'host' => 'database.test', 'sslmode' => 'prefer']);
        self::assertNull($snapshot['encrypted']);
        self::assertNull($snapshot['ca_verified']);
        self::assertNull($snapshot['hostname_verified']);
        self::assertSame('not_verified', $snapshot['evidence']);
    }
}
