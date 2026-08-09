<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class WorkflowUpdateValidationTask extends Model
{
    use HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_LEASED = 'leased';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_FAILED = 'failed';

    public const STATUS_TIMED_OUT = 'timed_out';

    public $incrementing = false;

    protected $table = 'workflow_update_validation_tasks';

    protected $guarded = [];

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'attempt_count' => 'integer',
            'command_context' => 'array',
            'validation_errors' => 'array',
            'lease_expires_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'failed_at' => 'datetime',
            'timed_out_at' => 'datetime',
        ];
    }
}
