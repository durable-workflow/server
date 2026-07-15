<?php

namespace App\Support;

use RuntimeException;
use Workflow\V2\Support\WorkflowTaskLease;

/**
 * Maps the standalone server lease setting into Workflow's lease authority.
 */
final class WorkflowTaskLeaseConfiguration
{
    public static function apply(): int
    {
        $configured = config(
            'server.lease.workflow_task_timeout',
            WorkflowTaskLease::DEFAULT_SECONDS,
        );

        config([WorkflowTaskLease::CONFIG_KEY => $configured]);

        $effective = WorkflowTaskLease::seconds();

        if (! is_numeric($configured) || (int) $configured !== $effective) {
            throw new RuntimeException(sprintf(
                'Configured standalone workflow-task lease [%s] does not resolve to the Workflow package lease [%d].',
                is_scalar($configured) ? (string) $configured : get_debug_type($configured),
                $effective,
            ));
        }

        return $effective;
    }
}
