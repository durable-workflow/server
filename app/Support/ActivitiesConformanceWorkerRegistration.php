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
        return DirectConformanceWorkerProtocol::registration(
            $workerId,
            $taskQueue,
            $runtime,
            $sdkVersion,
            $workflowTypes,
            $activityTypes,
            attributes: [
                'max_concurrent_workflow_tasks' => 1,
                'max_concurrent_activity_tasks' => 1,
                'process_metrics' => $processMetrics,
            ],
            capabilityManifest: self::portableAffinityRefusalManifest(),
        );
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
