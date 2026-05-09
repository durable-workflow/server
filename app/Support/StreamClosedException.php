<?php

namespace App\Support;

use App\Models\WorkflowDurableStream;
use RuntimeException;

class StreamClosedException extends RuntimeException
{
    public function __construct(public readonly WorkflowDurableStream $stream)
    {
        parent::__construct(sprintf(
            'Stream "%s" on run %s is closed and cannot accept further appends.',
            $stream->stream_name,
            $stream->workflow_run_id,
        ));
    }
}
