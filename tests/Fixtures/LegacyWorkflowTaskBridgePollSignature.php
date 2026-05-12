<?php

namespace Tests\Fixtures;

/**
 * Stand-in for the pre-a1d442d workflow-task bridge contract where poll()
 * could not narrow by workflow type. The server API floor must reject this
 * shape so workflow-task routing stays package-owned instead of using
 * local fallback SQL in the server.
 */
final class LegacyWorkflowTaskBridgePollSignature
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
