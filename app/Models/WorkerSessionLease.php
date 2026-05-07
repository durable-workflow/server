<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkerSessionLease extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ORPHANED = 'orphaned';

    protected $table = 'workflow_worker_sessions';

    protected $fillable = [
        'namespace',
        'session_id',
        'connection',
        'queue',
        'requirements',
        'status',
        'lease_owner',
        'lease_expires_at',
        'ttl_expires_at',
        'closed_at',
        'failure_reason',
        'lease_seconds',
        'ttl_seconds',
        'max_concurrent_activities',
        'create_if_missing',
        'allow_reacquire_after_failure',
        'last_heartbeat_at',
    ];

    protected function casts(): array
    {
        return [
            'requirements' => 'array',
            'lease_expires_at' => 'datetime',
            'ttl_expires_at' => 'datetime',
            'closed_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'create_if_missing' => 'boolean',
            'allow_reacquire_after_failure' => 'boolean',
        ];
    }
}
