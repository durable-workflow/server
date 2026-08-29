<?php

namespace App\Support;

final class ActivitiesConformanceWorkerRegistration
{
    public const UNSUPPORTED_PORTABLE_AFFINITY_REASON = 'synthetic_activity_worker_does_not_implement_portable_affinity';

    /**
     * @param  list<string>  $workflowTypes
     * @param  list<string>  $activityTypes
     * @param  array<string, mixed>  $processMetrics
     * @return array<string, mixed>
     */
    public static function payload(
        string $workerId,
        string $taskQueue,
        string $runtime,
        string $sdkVersion,
        array $workflowTypes,
        array $activityTypes,
        array $processMetrics = [],
    ): array {
        return [
            'worker_id' => $workerId,
            'task_queue' => $taskQueue,
            'runtime' => $runtime,
            'sdk_version' => $sdkVersion,
            'supported_workflow_types' => $workflowTypes,
            'supported_activity_types' => $activityTypes,
            'capabilities' => [],
            'capability_manifest' => self::portableAffinityRefusalManifest(),
            'max_concurrent_workflow_tasks' => 1,
            'max_concurrent_activity_tasks' => 1,
            'task_slots' => [
                'workflow_available' => $workflowTypes === [] ? 0 : 1,
                'activity_available' => $activityTypes === [] ? 0 : 1,
                'session_available' => 0,
            ],
            'process_metrics' => $processMetrics,
        ];
    }

    /**
     * @return array<string, array{supported: false, minimum_protocol_version: string, reason: string}>
     */
    public static function portableAffinityRefusalManifest(): array
    {
        return array_fill_keys(WorkerProtocol::PORTABLE_WORKER_AFFINITY_CAPABILITIES, [
            'supported' => false,
            'minimum_protocol_version' => WorkerProtocol::PORTABLE_WORKER_AFFINITY_MINIMUM_PROTOCOL_VERSION,
            'reason' => self::UNSUPPORTED_PORTABLE_AFFINITY_REASON,
        ]);
    }
}
