<?php

namespace Tests\Fixtures;

/**
 * Stand-in for an activity-task bridge contract where poll() cannot
 * narrow by activity type. The server API floor rejects this shape so
 * activity-task routing stays package-owned instead of using local
 * fallback SQL in the server.
 */
final class LegacyActivityTaskBridgePollSignature
{
    public function poll(
        ?string $connection,
        ?string $queue,
        int $limit = 1,
        ?string $compatibility = null,
        ?string $namespace = null,
    ): array {
        return [];
    }
}
