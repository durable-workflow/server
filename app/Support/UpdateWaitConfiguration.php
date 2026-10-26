<?php

namespace App\Support;

use Workflow\V2\Support\UpdateWaitPolicy;

/**
 * Bounds the configured update completion wait (Server #775).
 *
 * A completion wait holds the request open. A caller's own
 * `wait_timeout_seconds` above the ceiling is refused at the route; the
 * operator's default (DW_V2_UPDATE_WAIT_COMPLETION_TIMEOUT_SECONDS), used
 * when the request omits the field, is clamped to the same ceiling.
 */
final class UpdateWaitConfiguration
{
    public const CONFIG_KEY = 'workflows.v2.update_wait.completion_timeout_seconds';

    public static function apply(): int
    {
        $effective = min(
            UpdateWaitPolicy::completionTimeoutSeconds(),
            ControlPlaneRequestContract::UPDATE_WAIT_TIMEOUT_SECONDS_MAX,
        );

        config([self::CONFIG_KEY => $effective]);

        return $effective;
    }
}
