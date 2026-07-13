<?php

namespace App\Support;

use Workflow\V2\Models\WorkerCompatibilityHeartbeat;

final class DatabaseWorkerCompatibilityReadiness
{
    /**
     * Check the durable heartbeat substrate without consulting the legacy
     * cache fallback. This path is used only after readiness has already
     * classified Redis as a degraded acceleration dependency.
     */
    public function supportsRequired(?string $namespace, string $required): bool
    {
        return WorkerCompatibilityHeartbeat::query()
            ->where('expires_at', '>=', now())
            ->when(
                $namespace !== null,
                static fn ($query) => $query->where('namespace', $namespace),
            )
            ->where(static function ($query) use ($required): void {
                $query->whereJsonContains('supported', $required)
                    ->orWhereJsonContains('supported', '*');
            })
            ->exists();
    }
}
