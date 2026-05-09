<?php

namespace App\Support;

use RuntimeException;
use Workflow\V2\Models\WorkflowRun;

class StreamNotFoundException extends RuntimeException
{
    public function __construct(public readonly WorkflowRun $run, public readonly string $streamName)
    {
        parent::__construct(sprintf(
            'Stream "%s" not found on run %s.',
            $streamName,
            $run->id,
        ));
    }
}
