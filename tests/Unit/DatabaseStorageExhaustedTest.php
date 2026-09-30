<?php

namespace Tests\Unit;

use App\Support\DatabaseStorageExhausted;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DatabaseStorageExhaustedTest extends TestCase
{
    #[DataProvider('driverErrors')]
    public function test_classification_uses_driver_codes_not_sql_text(string $state, int|string $code, bool $full): void
    {
        $exception = new PDOException('private SQL and customer payload');
        $exception->errorInfo = [$state, $code, 'private driver diagnostic'];

        $this->assertSame($full, DatabaseStorageExhausted::is($exception));
        $this->assertSame($full, DatabaseStorageExhausted::is(new QueryException('database', 'insert into private_table values (?)', ['secret-payload'], $exception)));
        $this->assertSame($full, DatabaseStorageExhausted::is(new RuntimeException('wrapped error', 0, $exception)));
    }

    public static function driverErrors(): array
    {
        return [
            'MySQL tablespace full' => ['HY000', 1114, true],
            'MySQL string driver code' => ['HY000', '1114', true],
            'SQLite full' => ['HY000', 13, true],
            'PostgreSQL disk full' => ['53100', 7, true],
            'PostgreSQL out of memory' => ['53200', 7, false],
            'MySQL sort memory' => ['HY001', 1038, false],
            'connection refused' => ['HY000', 2002, false],
            'deadlock' => ['40001', 1213, false],
            'unique violation' => ['23000', 1062, false],
            'wrong SQLSTATE' => ['42000', 1114, false],
            'non-numeric code' => ['HY000', '13 customer SQL', false],
        ];
    }

    public function test_customer_text_cannot_classify_a_failure(): void
    {
        $this->assertFalse(DatabaseStorageExhausted::is(new RuntimeException('SQLSTATE[HY000]: General error: 1114 table full')));
        $this->assertFalse(DatabaseStorageExhausted::is(new PDOException('SQLSTATE[HY000]: General error: 1114 table full')));
    }
}
