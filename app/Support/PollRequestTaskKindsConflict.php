<?php

namespace App\Support;

use RuntimeException;

final class PollRequestTaskKindsConflict extends RuntimeException
{
    /**
     * @param  list<string>  $requestedTaskKinds
     * @param  list<string>  $boundTaskKinds
     */
    public function __construct(
        public readonly string $pollRequestId,
        public readonly array $requestedTaskKinds,
        public readonly array $boundTaskKinds,
    ) {
        parent::__construct('Poll request ID is already bound to a different task-kind set.');
    }
}
