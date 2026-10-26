<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\ConfiguredV2Models;

/**
 * The run-row lock that serializes a run's durable workflow stream writes
 * (Server #774). Stream appends and closes take it before they read any
 * stream row, and run retention takes it before it deletes the run's
 * streams, so each decides against committed stream state.
 *
 * SQLite has no row locks, and the server's PHP begins SQLite transactions
 * DEFERRED: a transaction that has read cannot wait for a concurrent writer,
 * it fails as soon as it tries to write. On SQLite a no-op write takes the
 * database write lock instead, waiting up to the busy timeout, before
 * anything is read.
 */
final class WorkflowRunStreamLock
{
    /**
     * Call inside the transaction, before its first read. Returns false when
     * the run row no longer exists.
     */
    public static function acquire(string $runId): bool
    {
        $connection = DB::connection();
        $table = ConfiguredV2Models::query('run_model', WorkflowRun::class)->getModel()->getTable();

        if ($connection->getDriverName() === 'sqlite') {
            return $connection->update(
                'UPDATE '.$connection->getQueryGrammar()->wrapTable($table).' SET id = id WHERE id = ?',
                [$runId],
            ) > 0;
        }

        return $connection->table($table)->where('id', $runId)->lockForUpdate()->first(['id']) !== null;
    }
}
