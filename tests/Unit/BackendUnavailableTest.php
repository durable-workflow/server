<?php

namespace Tests\Unit;

use App\Support\BackendUnavailable;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class BackendUnavailableTest extends TestCase
{
    #[DataProvider('driverErrors')]
    public function test_only_recognized_connection_failures_are_retryable(string $state, int $code, bool $retryable): void
    {
        $exception = new PDOException('arbitrary database error text');
        $exception->errorInfo = [$state, $code, 'diagnostic'];

        $this->assertSame($retryable, BackendUnavailable::is($exception));
        $this->assertSame($retryable, BackendUnavailable::is(new QueryException('mysql', 'select ?', ['value'], $exception)));
    }

    public static function driverErrors(): array
    {
        return [
            'mysql refused' => ['HY000', 2002, true],
            'mysql host unavailable' => ['HY000', 2003, true],
            'mysql gone away' => ['HY000', 2006, true],
            'mysql connection lost' => ['HY000', 2013, true],
            'mysql extended connection lost' => ['HY000', 2055, true],
            'postgres shutdown' => ['57P01', 7, true],
            'postgres recovery' => ['57P03', 7, true],
            'connection failure' => ['08006', 7, true],
            'unknown transaction result' => ['08007', 7, true],
            'access denied' => ['HY000', 1045, false],
            'missing database' => ['HY000', 1049, false],
            'disk full' => ['HY000', 1114, false],
            'deadlock' => ['40001', 1213, false],
            'schema error' => ['42S02', 1146, false],
            'unique violation' => ['23000', 1062, false],
            'wrong sqlstate' => ['42000', 2002, false],
        ];
    }

    public function test_error_text_cannot_classify_a_failure(): void
    {
        $this->assertFalse(BackendUnavailable::is(new RuntimeException('SQLSTATE[HY000] [2002] Connection refused')));
        $this->assertFalse(BackendUnavailable::is(new PDOException('SQLSTATE[HY000] [2002] Connection refused')));
    }
}
