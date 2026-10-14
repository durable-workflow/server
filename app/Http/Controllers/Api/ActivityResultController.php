<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Support\ControlPlaneProtocol;
use App\Support\ExternalPayloadEnvelopeService;
use App\Support\NamespaceWorkflowScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Support\ActivitySnapshot;
use Workflow\V2\Support\ConfiguredV2Models;

final class ActivityResultController
{
    public function __construct(private readonly ExternalPayloadEnvelopeService $envelopes) {}

    public function show(Request $request, string $workflowId, string $runId, string $activityId): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = (string) $request->attributes->get('namespace');
        $run = NamespaceWorkflowScope::run($namespace, $workflowId, $runId);
        $identity = ['workflow_id' => $workflowId, 'run_id' => $runId, 'activity_id' => $activityId];
        if ($run === null || $run->id !== $runId || $run->workflow_instance_id !== $workflowId
            || $run->namespace !== $namespace) {
            return ControlPlaneProtocol::jsonForRequest($request, $identity + [
                'reason' => 'run_not_found', 'message' => 'Workflow run not found.',
            ], 404);
        }

        // Select one activity's latest durable snapshot. Do not build the full
        // activity projection or read/fetch other activities' application values.
        $event = ConfiguredV2Models::query('history_event_model', WorkflowHistoryEvent::class)
            ->setEagerLoads([])->where('workflow_run_id', $run->id)
            ->whereIn('event_type', [
                HistoryEventType::ActivityScheduled->value, HistoryEventType::ActivityStarted->value,
                HistoryEventType::ActivityHeartbeatRecorded->value, HistoryEventType::ActivityRetryScheduled->value,
                HistoryEventType::ActivityCompleted->value, HistoryEventType::ActivityFailed->value,
                HistoryEventType::ActivityTimedOut->value, HistoryEventType::ActivityCancelled->value,
            ])->where(function ($query) use ($activityId): void {
                $query->where('payload->activity_execution_id', $activityId)
                    ->orWhere('payload->activity->id', $activityId);
            })->orderByDesc('sequence')->first();

        if ($event === null) {
            // A retained row establishes identity only. Its mutable status and
            // result are never a substitute for missing durable outcome history.
            $row = ConfiguredV2Models::query('activity_execution_model', ActivityExecution::class)
                ->setEagerLoads([])->whereKey($activityId)->where('workflow_run_id', $run->id)->first(['id']);
            if ($row === null || $row->id !== $activityId) {
                return ControlPlaneProtocol::jsonForRequest($request, $identity + [
                    'reason' => 'activity_not_found', 'message' => 'Activity not found in this run.',
                ], 404);
            }

            return $this->state($request, $identity, $run->details_pruned_at === null ? 'unsupported' : 'pruned',
                'activity_result_history_unavailable');
        }

        $identity['history_sequence'] = (int) $event->sequence;
        $payload = is_array($event->payload) ? $event->payload : [];
        $snapshot = ActivitySnapshot::fromEvent($event);
        if ($snapshot === null || ($snapshot['id'] ?? null) !== $activityId
            || (isset($payload['activity_execution_id']) && $payload['activity_execution_id'] !== $activityId)) {
            return $this->state($request, $identity, 'unsupported', 'activity_result_history_identity_mismatch');
        }
        if (($snapshot['local_activity'] ?? false) === true) {
            return $this->state($request, $identity, 'unsupported', 'local_activity_result_inspection_unsupported');
        }

        $status = $snapshot['status'] ?? null;
        if (in_array($status, ['pending', 'running'], true)) {
            return $this->state($request, $identity, 'pending', 'activity_result_pending');
        }
        if (in_array($status, ['failed', 'cancelled'], true)) {
            return $this->state($request, $identity, $status, 'activity_result_'.$status);
        }
        if ($status !== 'completed' || $event->event_type !== HistoryEventType::ActivityCompleted) {
            return $this->state($request, $identity, 'unsupported', 'activity_result_terminal_event_unavailable');
        }

        $codec = is_string($snapshot['payload_codec'] ?? null) ? $snapshot['payload_codec'] : $run->payload_codec;
        if ($codec !== null && $codec !== 'avro') {
            return $this->state($request, $identity, 'unsupported', 'activity_result_codec_unsupported');
        }
        $envelope = $this->envelopes->historyValue($namespace, $codec, $snapshot['result'] ?? null);
        if (is_array($envelope) && ($envelope['codec'] ?? null) !== 'avro') {
            return $this->state($request, $identity, 'unsupported', 'activity_result_codec_unsupported');
        }
        if (! is_array($envelope) || count($envelope) !== 2
            || (! is_string($envelope['blob'] ?? null) && ! is_array($envelope['external_storage'] ?? null))) {
            return $this->state($request, $identity, 'unavailable', 'activity_result_envelope_unavailable');
        }
        if (isset($payload['result'])) {
            $original = $this->envelopes->historyValue($namespace, $codec, $payload['result']);
            if ($original != $envelope) {
                return $this->state($request, $identity, 'unsupported', 'activity_result_history_value_mismatch');
            }
        }

        return ControlPlaneProtocol::jsonForRequest($request, $identity + [
            'state' => 'available', 'available' => true, 'reason' => null, 'result_envelope' => $envelope,
        ]);
    }

    /** @param array<string, mixed> $identity */
    private function state(Request $request, array $identity, string $state, string $reason): JsonResponse
    {
        return ControlPlaneProtocol::jsonForRequest($request, $identity + [
            'state' => $state, 'available' => false, 'reason' => $reason,
        ]);
    }
}
