<?php

namespace Tests\Fixtures;

/**
 * Stand-in for the `\Workflow\V2\Support\BackendCapabilities` snapshot
 * that shipped BEFORE workflow@f666b25. The real pre-f666b25 `queue()`
 * body does not reference `task_dispatch_mode` and flags
 * `queue_sync_unsupported` / `queue_connection_missing` as severity
 * `error` unconditionally. This fixture reproduces that shape so
 * {@see \App\Support\WorkflowPackageApiFloor::assert()} regression
 * coverage can prove a stale install is rejected.
 */
final class StaleBackendCapabilities
{
    public static function queue(?string $configuredConnection = null): array
    {
        $issues = [
            [
                'component' => 'queue',
                'severity' => 'error',
                'code' => 'queue_connection_missing',
                'message' => 'Workflow v2 requires a configured asynchronous queue connection.',
            ],
            [
                'component' => 'queue',
                'severity' => 'error',
                'code' => 'queue_sync_unsupported',
                'message' => 'Workflow v2 requires an asynchronous queue worker.',
            ],
        ];

        return [
            'connection' => $configuredConnection,
            'driver' => null,
            'supported' => false,
            'capabilities' => [
                'async_delivery' => false,
                'delayed_delivery' => false,
                'requires_worker' => true,
            ],
            'issues' => $issues,
        ];
    }
}
