<?php

namespace App\Support;

use RuntimeException;
use Throwable;

/** The audit COMMIT cannot safely be reported as a failed admission. */
final class ServiceCallAdmissionOutcomeUnknown extends RuntimeException
{
    public function __construct(
        public readonly string $serviceCallId,
        public readonly string $namespace,
        public readonly bool $keyed,
        Throwable $previous,
    ) {
        parent::__construct('The service-call admission may have committed.', 0, $previous);
    }
}
