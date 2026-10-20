<?php

namespace App\Support;

use App\Models\WorkerRegistration;
use Workflow\V2\Models\WorkflowRun;

final class WorkflowReplayEligibility
{
    public const PROTOCOL_METRIC = '_worker_protocol_version';

    public static function allows(WorkerRegistration $worker, WorkflowRun $run, ?string $protocolVersion): bool
    {
        if ($worker->namespace !== $run->namespace || ! WorkerPollFence::isFresh($worker)) {
            return false;
        }

        $capabilities = is_array($worker->capabilities) ? $worker->capabilities : [];

        if (is_string($run->cancellation_request_command_id)
            && ! CooperativeCancellationPolicy::supports($capabilities, $protocolVersion)) {
            return false;
        }
        if (is_string($run->cancellation_request_command_id)
            && NexusCooperativeCancellationPolicy::requiresCapabilityForReplay($run)
            && ! NexusCooperativeCancellationPolicy::supports($capabilities, $protocolVersion)) {
            return false;
        }

        return PreparedLocalActivityPolicy::canReplayRun($run->id, $capabilities, $protocolVersion)
            && WorkflowMetadataCapabilityPolicy::canReplayRun($run->id, $capabilities, $protocolVersion);
    }

    public static function recordedProtocol(WorkerRegistration $worker): ?string
    {
        $version = $worker->process_metrics[self::PROTOCOL_METRIC] ?? null;

        return is_string($version) && $version !== '' ? $version : null;
    }
}
