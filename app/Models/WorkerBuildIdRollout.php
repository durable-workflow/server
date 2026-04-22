<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkerBuildIdRollout extends Model
{
    public const DRAIN_INTENT_ACTIVE = 'active';

    public const DRAIN_INTENT_DRAINING = 'draining';

    public const UNVERSIONED_KEY = '';

    protected $table = 'workflow_worker_build_id_rollouts';

    protected $fillable = [
        'namespace',
        'task_queue',
        'build_id',
        'drain_intent',
        'drained_at',
    ];

    protected function casts(): array
    {
        return [
            'drained_at' => 'datetime',
        ];
    }

    public static function buildIdKey(?string $buildId): string
    {
        if (! is_string($buildId)) {
            return self::UNVERSIONED_KEY;
        }

        $trimmed = trim($buildId);

        return $trimmed === '' ? self::UNVERSIONED_KEY : $trimmed;
    }

    public static function buildIdFromKey(string $key): ?string
    {
        return $key === self::UNVERSIONED_KEY ? null : $key;
    }

    public function publicBuildId(): ?string
    {
        return self::buildIdFromKey((string) $this->build_id);
    }

    public function isDraining(): bool
    {
        return $this->drain_intent === self::DRAIN_INTENT_DRAINING;
    }
}
