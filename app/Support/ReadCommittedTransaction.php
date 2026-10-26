<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * A transaction whose plain reads each see every commit made before them,
 * not a snapshot taken at the transaction's first read (Server #774).
 *
 * External payload cleanup asks again, under an object's lock, whether a
 * retained row still references the object before it deletes it. A writer
 * holds that lock until its reference commits. Under REPEATABLE READ, the
 * MySQL and MariaDB default, the question would be answered from a snapshot
 * taken before the cleanup waited for the lock, so the writer's committed
 * reference would be missed and its object deleted. PostgreSQL defaults to
 * READ COMMITTED; it is set here too so a server default cannot change it.
 * SQLite runs one writer at a time, and a writer reads every commit.
 *
 * The isolation level can only be chosen for a new transaction. Inside a
 * caller's transaction the callback runs in that transaction as it is.
 */
final class ReadCommittedTransaction
{
    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function run(Closure $callback): mixed
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if ($connection->transactionLevel() > 0 || ! in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
            return $connection->transaction($callback);
        }

        if ($driver !== 'pgsql') {
            // Applies to the next transaction the session starts.
            $connection->unprepared('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');

            return $connection->transaction($callback);
        }

        return $connection->transaction(static function () use ($connection, $callback): mixed {
            // Must be the transaction's first statement.
            $connection->unprepared('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');

            return $callback();
        });
    }
}
