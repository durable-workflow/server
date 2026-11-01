<?php

namespace App\Support;

use App\Models\RuntimeExternalPayload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use Workflow\V2\Contracts\ExternalPayloadStorageDriver;

final class RuntimeExternalPayloadDeletions
{
    private const TABLE = 'runtime_external_payload_deletions';

    /** @var array<string, ExternalPayloadStorageDriver|null> */
    private array $pending = [];

    /**
     * The caller holds the object lock and removes its last registration in
     * this same transaction. A rollback must never reach the provider.
     */
    public function stage(string $namespace, string $uri, ExternalPayloadStorageDriver $driver): void
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('Payload deletion must be staged inside its reference transaction.');
        }

        app(ExternalPayloadBackupHold::class)->assertReclamationAllowed();
        $hash = hash('sha256', $uri);
        DB::table(self::TABLE)->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'namespace' => $namespace,
            'storage_uri' => $uri,
            'storage_uri_sha256' => $hash,
            'storage_policy' => json_encode(app(NamespaceExternalPayloadStorage::class)->policyFor($namespace), JSON_THROW_ON_ERROR),
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $row = DB::table(self::TABLE)->where('namespace', $namespace)->where('storage_uri_sha256', $hash)->first();
        if ($row === null || $row->storage_uri !== $uri) {
            throw new RuntimeException('Unable to record an unambiguous payload deletion.');
        }

        // The callback accelerates ordinary cleanup; the committed row owns
        // recovery if the process exits before or during this callback.
        $this->pending[$row->id] = $driver;
        DB::afterCommit(fn () => $this->flushPending());
    }

    public function retryPending(?string $namespace, int $limit): void
    {
        DB::transaction(function () use ($namespace, $limit): void {
            $rows = DB::table(self::TABLE)
                ->when($namespace !== null, static fn ($query) => $query->where('namespace', $namespace))
                ->orderBy('updated_at')->orderBy('id')->limit(max(1, min(1000, $limit)))->get(['id']);
            foreach ($rows as $row) {
                $this->pending[$row->id] = null;
                DB::afterCommit(fn () => $this->flushPending());
            }
        });
    }

    private function flushPending(): void
    {
        $pending = $this->pending;
        $this->pending = [];
        if ($pending === []) {
            return;
        }

        try {
            ReadCommittedTransaction::run(function () use ($pending): void {
                $rows = DB::table(self::TABLE)->whereIn('id', array_keys($pending))->get(['id', 'storage_uri']);
                if ($rows->isEmpty()) {
                    return;
                }
                $uris = RuntimeExternalPayloadObjectLock::inLockOrder($rows->pluck('storage_uri')->all());
                // Lock every candidate before scanning once. Writers cannot
                // invalidate that scan, and no later object lock follows the
                // backup hold acquired by a provider deletion.
                $buckets = [];
                foreach ($uris as $uri) {
                    $bucket = RuntimeExternalPayloadObjectLock::bucketFor($uri);
                    if (! isset($buckets[$bucket])) {
                        app(RuntimeExternalPayloadObjectLock::class)->transaction($uri, static fn () => null);
                        $buckets[$bucket] = true;
                    }
                }
                $retained = app(ExternalPayloadRetentionCleanup::class)->retainedReferences($uris);
                foreach ($rows as $row) {
                    $this->attempt($row->id, $pending[$row->id], $retained);
                }
            });
        } catch (Throwable $exception) {
            // The original pruning transaction is already committed. Even a
            // batch acknowledgement/connection failure leaves durable intents.
            $this->logPending('external_payload_deletion_batch_pending', ['exception_class' => $exception::class]);
        }
    }

    /** @return array{pending: int, failed: int, blocked: int} */
    public function status(?string $namespace): array
    {
        $query = DB::table(self::TABLE)
            ->when($namespace !== null, static fn ($query) => $query->where('namespace', $namespace));

        return [
            'pending' => (clone $query)->count(),
            'failed' => (clone $query)->whereNotNull('failure_code')->where('failure_code', '!=', 'backup_hold')->count(),
            'blocked' => (clone $query)->where('failure_code', 'backup_hold')->count(),
        ];
    }

    private function attempt(string $id, ?ExternalPayloadStorageDriver $driver, array $retained): void
    {
        try {
            // flushPending already holds every candidate's object lock. A
            // savepoint isolates one failed provider/acknowledgement from the
            // remaining intentions without reacquiring those locks.
            DB::transaction(function () use ($id, $driver, $retained): void {
                $row = DB::table(self::TABLE)->where('id', $id)->lockForUpdate()->first();
                if ($row === null) {
                    return;
                }

                // A new upload/claim can win after pruning commits. Its
                // registration fences this older deletion, in any namespace.
                $owned = RuntimeExternalPayload::query()
                    ->where('storage_uri_sha256', $row->storage_uri_sha256)
                    ->where('storage_uri', $row->storage_uri)->exists();
                if ($owned || isset($retained[$row->storage_uri])) {
                    DB::table(self::TABLE)->where('id', $id)->delete();

                    return;
                }

                $driver ??= app(NamespaceExternalPayloadStorage::class)->untrackedDriverForPolicy(
                    json_decode($row->storage_policy, true, flags: JSON_THROW_ON_ERROR),
                    $row->namespace,
                );
                if ($driver === null) {
                    throw new ExternalPayloadStorageUnavailable('The saved external payload storage policy is unavailable.');
                }

                // Also fence custom drivers. The ordinary guarded driver
                // re-enters the same hold in this transaction.
                app(ExternalPayloadBackupHold::class)->deleting(function () use ($driver, $row): void {
                    try {
                        $driver->delete($row->storage_uri);
                    } catch (ExternalPayloadObjectMissing) {
                        // A previous attempt may have removed the bytes before
                        // losing its acknowledgement. Confirmed absence settles
                        // that intention without restoring pruned references.
                    }
                });
                DB::table(self::TABLE)->where('id', $id)->delete();
            });
        } catch (Throwable $exception) {
            // The reference transaction has committed. Provider/acknowledgement
            // failure must retain retry work, never report a fictitious rollback.
            try {
                DB::table(self::TABLE)->where('id', $id)->update([
                    'attempts' => DB::raw('attempts + 1'),
                    'failure_code' => $this->causedByHold($exception) ? 'backup_hold' : 'storage_unavailable',
                    'updated_at' => now(),
                ]);
            } catch (Throwable) {
                // The committed intent still exists if even failure recording
                // is unavailable. A later cleanup pass can retry it.
            }
            $this->logPending('external_payload_deletion_pending', ['deletion_id' => $id, 'exception_class' => $exception::class]);
        }
    }

    private function causedByHold(Throwable $exception): bool
    {
        do {
            if ($exception instanceof ExternalPayloadBackupInProgress) {
                return true;
            }
            $exception = $exception->getPrevious();
        } while ($exception !== null);

        return false;
    }

    private function logPending(string $message, array $context): void
    {
        try {
            Log::warning($message, $context);
        } catch (Throwable) {
            // The committed intention remains authoritative even when the
            // diagnostic sink is unavailable.
        }
    }
}
