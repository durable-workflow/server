<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class WorkerRegistrationIncarnation extends Model
{
    public const ACTIVE = 'active';

    public const DEREGISTERED = 'deregistered';

    public const SUPERSEDED = 'superseded';

    public const RECEIPT_RETENTION_SECONDS = 600;

    protected $table = 'workflow_worker_registration_incarnations';

    protected $primaryKey = 'token';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'token',
        'namespace',
        'worker_id',
        'status',
        'recovered_workflow_task_count',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'recovered_workflow_task_count' => 'integer',
            'finished_at' => 'datetime',
        ];
    }

    public static function pruneExpired(int $limit = 100, ?string $namespace = null): int
    {
        $cutoff = now()->subSeconds(self::RECEIPT_RETENTION_SECONDS);
        $tokens = self::query()->when($namespace !== null, fn ($query) => $query->where('namespace', $namespace))
            ->where(function ($query) use ($cutoff): void {
                $query->where('finished_at', '<=', $cutoff)
                    ->orWhere(function ($query) use ($cutoff): void {
                        // Legacy/admin deletion can leave an active incarnation.
                        // Never prune one still bound to its physical registration.
                        $query->where('status', self::ACTIVE)->where('updated_at', '<=', $cutoff)
                            ->whereNotExists(function ($query): void {
                                $query->select(DB::raw('1'))->from('workflow_worker_registrations as registrations')
                                    ->whereColumn('registrations.registration_token', 'workflow_worker_registration_incarnations.token')
                                    ->whereColumn('registrations.namespace', 'workflow_worker_registration_incarnations.namespace')
                                    ->whereColumn('registrations.worker_id', 'workflow_worker_registration_incarnations.worker_id');
                            });
                    });
            })->orderBy('updated_at')->limit(max(1, $limit))->pluck('token');

        return $tokens->isEmpty() ? 0 : self::query()->whereKey($tokens->all())->delete();
    }
}
