<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RuntimeExternalPayloadCleanupStat extends Model
{
    public $incrementing = false;

    protected $table = 'runtime_external_payload_cleanup_stats';

    protected $primaryKey = 'namespace';

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'passes_total' => 'integer',
        'deleted_references_total' => 'integer',
        'deleted_backing_objects_total' => 'integer',
        'shared_objects_preserved_total' => 'integer',
        'blocked_outcomes_total' => 'integer',
        'storage_driver_failures_total' => 'integer',
        'last_processed' => 'integer',
        'last_deleted_references' => 'integer',
        'last_deleted_backing_objects' => 'integer',
        'last_shared_objects_preserved' => 'integer',
        'last_blocked_outcomes' => 'integer',
        'last_storage_driver_failures' => 'integer',
        'last_completed_at' => 'immutable_datetime',
        'last_storage_failure_at' => 'immutable_datetime',
    ];
}
