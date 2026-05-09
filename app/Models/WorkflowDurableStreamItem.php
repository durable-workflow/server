<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One durable item inside a workflow stream.
 *
 * `offset` is the per-stream sequence number; `(stream_id, offset)` is
 * unique. Subscribers reconnect by passing `from=<last_seen_offset+1>`.
 */
class WorkflowDurableStreamItem extends Model
{
    protected $table = 'workflow_durable_stream_items';

    protected $guarded = [];

    protected $casts = [
        'stream_id' => 'integer',
        'offset' => 'integer',
        'payload' => 'array',
        'emitted_at' => 'datetime',
    ];

    public function stream(): BelongsTo
    {
        return $this->belongsTo(WorkflowDurableStream::class, 'stream_id');
    }
}
