<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowInboundStreamItem extends Model
{
    protected $table = 'workflow_inbound_stream_items';

    protected $guarded = [];

    protected $hidden = [
        'payload_blob',
        'payload_hash',
    ];

    protected $casts = [
        'stream_id' => 'integer',
        'position' => 'integer',
        'delivered_at' => 'datetime',
        'consumed_at' => 'datetime',
        'payload_released_at' => 'datetime',
    ];

    public function stream(): BelongsTo
    {
        return $this->belongsTo(WorkflowInboundStream::class, 'stream_id');
    }
}
