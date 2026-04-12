<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowTaskProtocolLease extends Model
{
    protected $table = 'workflow_task_protocol_leases';

    protected $primaryKey = 'task_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'task_id',
        'namespace',
        'workflow_instance_id',
        'workflow_run_id',
        'workflow_task_attempt',
        'lease_owner',
        'lease_expires_at',
        'last_claimed_at',
        'last_poll_request_id',
    ];

    protected function casts(): array
    {
        return [
            'workflow_task_attempt' => 'integer',
            'lease_expires_at' => 'datetime',
            'last_claimed_at' => 'datetime',
        ];
    }

    public function hasActiveLease(): bool
    {
        return is_string($this->lease_owner)
            && $this->lease_owner !== ''
            && $this->lease_expires_at !== null;
    }
}
