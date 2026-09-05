<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class ExternalPayloadBackupHold
{
    public const MAX_TTL_SECONDS = 3600;

    public const MAX_DURATION_SECONDS = 86400;

    private const TABLE = 'runtime_external_payload_backup_hold';

    public function acquire(string $owner, int $ttl): array
    {
        $this->validate($owner, $ttl);

        return $this->locked(function (object $row, CarbonImmutable $now) use ($owner, $ttl): array {
            if ($row->owner === $owner) {
                $this->assertOwner($row, $now, $owner);

                return $this->report($row, $now);
            }
            if ($this->active($row, $now)) {
                throw new RuntimeException('Another backup owns the external payload reclamation hold.');
            }

            $row->owner = $owner;
            $row->acquired_at = $now->toDateTimeString();
            $row->expires_at = $now->addSeconds($ttl)->toDateTimeString();
            $this->save($row);

            return $this->report($row, $now);
        });
    }

    public function renew(string $owner, int $ttl): array
    {
        $this->validate($owner, $ttl);

        return $this->locked(function (object $row, CarbonImmutable $now) use ($owner, $ttl): array {
            $this->assertOwner($row, $now, $owner);
            $deadline = CarbonImmutable::parse($row->acquired_at, 'UTC')->addSeconds(self::MAX_DURATION_SECONDS);
            $row->expires_at = $now->addSeconds($ttl)->min($deadline)->toDateTimeString();
            $this->save($row);

            return $this->report($row, $now);
        });
    }

    public function release(string $owner): array
    {
        $this->validate($owner);

        return $this->locked(function (object $row, CarbonImmutable $now) use ($owner): array {
            if ($row->owner !== $owner) {
                throw new RuntimeException('The backup does not own the external payload reclamation hold.');
            }
            $row->expires_at = null;
            $this->save($row);

            return $this->report($row, $now);
        });
    }

    public function status(?string $owner = null): array
    {
        if ($owner !== null) {
            $this->validate($owner);
        }

        return $this->locked(function (object $row, CarbonImmutable $now) use ($owner): array {
            if ($owner !== null) {
                $this->assertOwner($row, $now, $owner);
            }

            return $this->report($row, $now);
        });
    }

    public function deleting(Closure $delete): void
    {
        $this->locked(function (object $row, CarbonImmutable $now) use ($delete): void {
            if ($this->active($row, $now)) {
                throw new ExternalPayloadBackupInProgress('External payload reclamation is paused for an online backup.');
            }

            // Hold the row lock through the provider effect so acquire() waits
            // for an already-running deletion before allowing a database dump.
            $delete();
        });
    }

    private function locked(Closure $callback): mixed
    {
        return DB::transaction(function () use ($callback): mixed {
            $row = DB::table(self::TABLE)->where('id', 1)->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('External payload backup coordination is not initialized.');
            }

            // SQLite ignores FOR UPDATE; take its writer lock before reading
            // the lease and applying an external effect.
            DB::table(self::TABLE)->where('id', 1)->update(['id' => 1]);
            $row = DB::table(self::TABLE)->where('id', 1)->lockForUpdate()->first();
            // PostgreSQL CURRENT_TIMESTAMP is frozen at transaction start.
            $clock = DB::getDriverName() === 'pgsql' ? 'clock_timestamp()' : 'CURRENT_TIMESTAMP';
            $now = CarbonImmutable::parse(DB::scalar('SELECT '.$clock), 'UTC')->utc();

            return $callback($row, $now);
        });
    }

    private function active(object $row, CarbonImmutable $now): bool
    {
        return $row->owner !== null && $row->expires_at !== null
            && CarbonImmutable::parse($row->expires_at, 'UTC')->greaterThan($now);
    }

    private function assertOwner(object $row, CarbonImmutable $now, string $owner): void
    {
        if ($row->owner !== $owner || ! $this->active($row, $now)) {
            throw new RuntimeException('The backup hold expired, was released, or belongs to another backup; discard this candidate.');
        }
    }

    private function validate(string $owner, ?int $ttl = null): void
    {
        if (! Str::isUuid($owner)) {
            throw new InvalidArgumentException('A unique backup UUID is required as owner.');
        }
        if ($ttl !== null && ($ttl < 1 || $ttl > self::MAX_TTL_SECONDS)) {
            throw new InvalidArgumentException('Backup hold TTL must be between 1 and 3600 seconds.');
        }
    }

    private function save(object $row): void
    {
        DB::table(self::TABLE)->where('id', 1)->update([
            'owner' => $row->owner,
            'acquired_at' => $row->acquired_at,
            'expires_at' => $row->expires_at,
        ]);
    }

    private function report(object $row, CarbonImmutable $now): array
    {
        return [
            'schema' => 'durable-workflow.external-payload-backup-hold.v1',
            'active' => $this->active($row, $now),
            'owner' => $row->owner,
            'acquired_at' => $row->acquired_at,
            'expires_at' => $row->expires_at,
            'database_time' => $now->toDateTimeString(),
        ];
    }
}
