<?php

namespace App\Support;

use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Support\ServiceBoundaryDecision;
use Workflow\V2\Support\ServiceBoundaryRequest;

/**
 * Outcome of {@see ServiceCallBoundary::admit()}.
 *
 * Carries the typed decision, the durable audit row, and the
 * originating request so dispatch surfaces can release concurrency or
 * cross-reference the audit row when reporting handler completion.
 */
final class ServiceCallAdmission
{
    public function __construct(
        public readonly ServiceBoundaryDecision $decision,
        public readonly WorkflowServiceCall $call,
        public readonly ServiceBoundaryRequest $request,
    ) {
    }

    public function accepted(): bool
    {
        return $this->decision->isAllowed();
    }

    public function rejected(): bool
    {
        return $this->decision->isDenied();
    }

    /**
     * HTTP failure surface for policy denials. The status code keeps
     * authorization denials distinct from rate/concurrency/circuit
     * denials so SDKs can decide whether to retry without re-checking
     * credentials.
     */
    public function httpStatus(): int
    {
        if ($this->accepted()) {
            return 202;
        }

        return match ($this->decision->outcome->value) {
            'denied_authorization' => 403,
            'denied_namespace_policy' => 403,
            'denied_unknown_target' => 404,
            'denied_rate_limit', 'denied_concurrency', 'denied_circuit_open' => 429,
            default => 409,
        };
    }
}
