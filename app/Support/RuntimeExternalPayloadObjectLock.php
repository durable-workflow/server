<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RuntimeExternalPayloadObjectLock
{
    public const BUCKETS = 256;

    /**
     * Serialize registry and backing-object mutations for one provider URI.
     *
     * The fixed-size lock table avoids an unbounded lock-row registry. The
     * no-op update also obtains SQLite's writer lock, where lockForUpdate() is
     * otherwise ignored.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function transaction(string $uri, Closure $callback): mixed
    {
        $bucket = hexdec(substr(hash('sha256', $uri), 0, 4)) % self::BUCKETS;

        return DB::transaction(function () use ($bucket, $callback): mixed {
            $lock = DB::table('runtime_external_payload_object_locks')
                ->where('bucket', $bucket)
                ->lockForUpdate()
                ->first();

            if ($lock === null) {
                throw new RuntimeException('Runtime external payload object lock table is not initialized.');
            }

            DB::table('runtime_external_payload_object_locks')
                ->where('bucket', $bucket)
                ->update(['bucket' => $bucket]);

            return $callback();
        }, 3);
    }
}
