<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Per-run named durable stream that an external subscriber can read from.
 *
 * One row per (workflow_run_id, stream_name). Items hang off the
 * `items()` relation in append order.
 */
class WorkflowDurableStream extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_ERRORED = 'errored';

    protected $table = 'workflow_durable_streams';

    protected $guarded = [];

    protected $casts = [
        'last_offset' => 'integer',
        'total_items' => 'integer',
        'pending_items' => 'integer',
        'retention_seconds' => 'integer',
        'opened_at' => 'datetime',
        'last_appended_at' => 'datetime',
        'closed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(WorkflowDurableStreamItem::class, 'stream_id')
            ->orderBy('offset');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isTerminal(): bool
    {
        return $this->status !== self::STATUS_OPEN;
    }
}
