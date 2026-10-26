<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RuntimeExternalPayloadObjectLock
{
    public const BUCKETS = 256;

    public static function bucketFor(string $uri): int
    {
        return (int) (hexdec(substr(hash('sha256', $uri), 0, 4)) % self::BUCKETS);
    }

    /**
     * URIs in the order their lock rows must be taken when one transaction
     * holds several: by bucket, then by URI. Every caller that locks more
     * than one object uses it, so two transactions never wait on each other
     * in opposite orders (Server #774).
     *
     * @param  list<string>  $uris
     * @return list<string>
     */
    public static function inLockOrder(array $uris): array
    {
        $uris = array_values(array_unique($uris));
        usort($uris, static fn (string $left, string $right): int => [self::bucketFor($left), $left] <=> [self::bucketFor($right), $right]);

        return $uris;
    }

    /**
     * Serialize registry and backing-object mutations for one provider URI.
     *
     * The fixed-size lock table avoids an unbounded lock-row registry. The
     * no-op update also obtains SQLite's writer lock, where lockForUpdate() is
     * otherwise ignored. There it comes first: a SQLite transaction that has
     * read cannot wait for another writer, it fails at once, so reading the
     * lock row first made a concurrent payload operation fail instead of
     * waiting its turn (Server #774).
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function transaction(string $uri, Closure $callback, ?Closure $beforeLock = null): mixed
    {
        $bucket = self::bucketFor($uri);

        return DB::transaction(function () use ($bucket, $callback, $beforeLock): mixed {
            // Completion ownership locks must precede payload locks, as they do
            // when the engine commits an activity result.
            $beforeLock?->__invoke();
            $sqlite = DB::connection()->getDriverName() === 'sqlite';
            if ($sqlite) {
                $this->touch($bucket);
            }

            $lock = DB::table('runtime_external_payload_object_locks')
                ->where('bucket', $bucket)
                ->lockForUpdate()
                ->first();

            if ($lock === null) {
                throw new RuntimeException('Runtime external payload object lock table is not initialized.');
            }

            if (! $sqlite) {
                $this->touch($bucket);
            }

            return $callback();
        }, 3);
    }

    private function touch(int $bucket): void
    {
        DB::table('runtime_external_payload_object_locks')
            ->where('bucket', $bucket)
            ->update(['bucket' => $bucket]);
    }
}
