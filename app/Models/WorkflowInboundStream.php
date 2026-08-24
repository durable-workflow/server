<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowInboundStream extends Model
{
    protected $table = 'workflow_inbound_streams';

    protected $guarded = [];

    protected $casts = [
        'last_position' => 'integer',
        'cursor_position' => 'integer',
        'waiting_after_position' => 'integer',
        'duplicate_count' => 'integer',
        'malformed_count' => 'integer',
        'waiting_since' => 'datetime',
        'last_input_at' => 'datetime',
        'cleanup_blocked_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(WorkflowInboundStreamItem::class, 'stream_id')
            ->orderBy('position');
    }
}
