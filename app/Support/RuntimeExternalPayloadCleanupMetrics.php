<?php

namespace App\Support;

use App\Models\RuntimeExternalPayload;
use App\Models\RuntimeExternalPayloadCleanupStat;

final class RuntimeExternalPayloadCleanupMetrics
{
    /** @return array<string, mixed> */
    public static function snapshot(string $namespace): array
    {
        $namespace = strtolower($namespace);
        $expired = RuntimeExternalPayload::query()
            ->where('namespace', $namespace)
            ->whereNull('retained_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
        $stats = RuntimeExternalPayloadCleanupStat::query()->find($namespace);

        return [
            'schema' => 'durable-workflow.v2.runtime-external-payload-cleanup-metrics.v1',
            'backlog' => [
                'expired_unclaimed' => (clone $expired)->count(),
                'failed_registration_objects' => (clone $expired)
                    ->where('upload_status', RuntimeExternalPayload::UPLOAD_WRITING)
                    ->count(),
            ],
            'bounds' => [
                'abandoned_upload_expiry_seconds' => max(
                    1,
                    (int) config('server.external_payload_transport.abandoned_upload_expiry_seconds'),
                ),
                'default_batch_size' => RuntimeExternalPayloadCleanup::DEFAULT_BATCH_SIZE,
                'maximum_batch_size' => RuntimeExternalPayloadCleanup::MAX_BATCH_SIZE,
            ],
            'totals' => [
                'passes' => (int) ($stats?->passes_total ?? 0),
                'deleted_references' => (int) ($stats?->deleted_references_total ?? 0),
                'deleted_backing_objects' => (int) ($stats?->deleted_backing_objects_total ?? 0),
                'shared_objects_preserved' => (int) ($stats?->shared_objects_preserved_total ?? 0),
                'blocked_outcomes' => (int) ($stats?->blocked_outcomes_total ?? 0),
                'storage_driver_failures' => (int) ($stats?->storage_driver_failures_total ?? 0),
            ],
            'last_pass' => [
                'status' => $stats?->last_pass_status,
                'processed' => (int) ($stats?->last_processed ?? 0),
                'deleted_references' => (int) ($stats?->last_deleted_references ?? 0),
                'deleted_backing_objects' => (int) ($stats?->last_deleted_backing_objects ?? 0),
                'shared_objects_preserved' => (int) ($stats?->last_shared_objects_preserved ?? 0),
                'blocked_outcomes' => (int) ($stats?->last_blocked_outcomes ?? 0),
                'storage_driver_failures' => (int) ($stats?->last_storage_driver_failures ?? 0),
                'completed_at' => $stats?->last_completed_at?->toJSON(),
                'last_storage_failure_at' => $stats?->last_storage_failure_at?->toJSON(),
            ],
            'identity_policy' => [
                'provider_credentials_exposed' => false,
                'provider_locations_exposed' => false,
                'reusable_reference_identities_exposed' => false,
            ],
        ];
    }
}
