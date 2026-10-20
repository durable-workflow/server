<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Requires an isolated PostgreSQL or PgBouncer endpoint. Run this same test
 * through direct/session/transaction endpoints, with prepared tracking 0/200.
 * No application schema or production data is mutated by these read controls.
 */
class PostgresPoolerTest extends TestCase
{
    public static function endpoints(): array
    {
        $endpoints = ['configured' => [getenv('DW_POOLER_TEST_HOST') ?: null, (int) (getenv('DW_POOLER_TEST_PORT') ?: 6432), getenv('DW_POOLER_TEST_EXPECT_SWITCH') === '1']];
        $prefix = getenv('DW_POOLER_TEST_PREFIX');
        if (is_string($prefix) && $prefix !== '') {
            foreach (['session', 'transaction0', 'transaction200'] as $mode) {
                $endpoints[$mode] = [$prefix.'-'.$mode, 5432, $mode !== 'session'];
            }
        }

        return $endpoints;
    }

    private function configureEndpoint(?string $host, int $port): void
    {
        if ($host === null) {
            $this->markTestSkipped('Requires the isolated DW_POOLER_TEST_HOST endpoint.');
        }
        $base = config('database.connections.pgsql');
        foreach (['pooler_first', 'pooler_second'] as $name) {
            config(['database.connections.'.$name => [...$base,
                'host' => $host,
                'port' => $port,
                'database' => getenv('DW_POOLER_TEST_DATABASE') ?: 'dw_pooler_probe',
                'username' => getenv('DW_POOLER_TEST_USER') ?: 'pooler_probe',
                'password' => getenv('DW_POOLER_TEST_PASSWORD') ?: '',
            ]]);
        }
    }

    protected function tearDown(): void
    {
        foreach (['pooler_first', 'pooler_second'] as $name) {
            DB::purge($name);
        }
        parent::tearDown();
    }

    #[DataProvider('endpoints')]
    public function test_clients_keep_their_bound_values_and_types_when_backend_sessions_change(?string $host, int $port, bool $expectSwitch): void
    {
        $this->configureEndpoint($host, $port);
        $first = DB::connection('pooler_first');
        $second = DB::connection('pooler_second');

        // Occupy two server sessions using simple protocol commands first.
        // The probe itself then exercises Laravel's configured PDO strategy.
        $first->getPdo()->exec('BEGIN');
        $first->getPdo()->exec('SELECT 1');
        $second->getPdo()->exec('BEGIN');
        $second->getPdo()->exec('SELECT 1');
        $first->getPdo()->exec('ROLLBACK');
        $second->getPdo()->exec('ROLLBACK');

        $backends = [];
        for ($iteration = 0; $iteration < 20; $iteration++) {
            $marker = 'first-'.$iteration.'-'.bin2hex(random_bytes(8));
            $row = $first->selectOne('SELECT CAST(? AS TEXT) AS marker, CAST(? AS BIGINT) AS number, CAST(? AS TEXT) AS absent, pg_backend_pid() AS backend',
                [$marker, '9223372036854775807', null]);
            $backends[] = $row->backend;
            self::assertSame($marker, $row->marker);
            self::assertSame('9223372036854775807', (string) $row->number);
            self::assertNull($row->absent);

            $other = 'second-'.$iteration.'-Київ';
            $row = $second->selectOne('SELECT CAST(? AS TEXT) AS marker', [$other]);
            self::assertSame($other, $row->marker);
            // An odd number of commands per iteration makes the next first
            // client's query use another backend in the round-robin pool.
            self::assertSame(1, (int) $first->selectOne('SELECT 1 AS value')->value);
        }
        if ($expectSwitch) {
            self::assertGreaterThan(1, count(array_unique($backends)), 'The control must change this client\'s physical backend.');
        }
    }

    #[DataProvider('endpoints')]
    public function test_bound_statements_do_not_abort_a_multi_query_transaction(?string $host, int $port, bool $expectSwitch): void
    {
        $this->configureEndpoint($host, $port);
        $connection = DB::connection('pooler_first');
        $connection->beginTransaction();
        try {
            foreach (['first', 'second', 'third'] as $value) {
                self::assertSame($value, $connection->selectOne('SELECT CAST(? AS TEXT) AS marker', [$value])->marker);
                self::assertSame(2, (int) $connection->selectOne('SELECT 1 + 1 AS result')->result);
            }
        } finally {
            $connection->rollBack();
        }
        self::assertSame('after-rollback', $connection->selectOne('SELECT CAST(? AS TEXT) AS marker', ['after-rollback'])->marker);
    }
}
