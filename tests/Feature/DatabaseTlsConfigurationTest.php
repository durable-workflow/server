<?php

namespace Tests\Feature;

use Illuminate\Support\Env;
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
}
