<?php

namespace App\Support;

use App\Models\WorkflowDurableStream;
use RuntimeException;

class StreamFullException extends RuntimeException
{
    public function __construct(
        public readonly WorkflowDurableStream $stream,
        public readonly int $maxPendingItems,
    ) {
        parent::__construct(sprintf(
            'Stream "%s" on run %s has %d pending items (cap %d); slow consumer triggered backpressure.',
            $stream->stream_name,
            $stream->workflow_run_id,
            $stream->pending_items,
            $maxPendingItems,
        ));
    }
}
