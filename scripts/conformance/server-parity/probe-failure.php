<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use DurableWorkflow\Exception\ServerException;
use DurableWorkflow\Transport\Psr18Transport;
use GuzzleHttp\Client as GuzzleClient;

function parityWorkerDiagnostic(string $event, array $details): array
{
    // Keep logical SDK diagnostics, never transport headers, credentials or
    // exception traces. Unknown context fields do not enter retained evidence.
    $safe = array_intersect_key($details, array_flip([
        'worker_id', 'task_queue', 'task_id', 'workflow_id', 'run_id',
        'workflow_type', 'activity_type', 'operation', 'attempt',
        'delay_seconds', 'heartbeat_interval_seconds', 'status', 'reason',
    ]));
    $safe = array_filter($safe, static fn ($value): bool => is_scalar($value) || $value === null);
    if (($details['exception'] ?? null) instanceof Throwable) {
        $error = $details['exception'];
        $safe['exception'] = ['type' => $error::class];
        if ($error instanceof ServerException) {
            $safe['exception'] += ['status' => $error->status, 'reason' => $error->reason];
        }
    }

    return ['event' => $event, 'details' => $safe];
}

function parityHttpFailureEvidence(Throwable $error, string $phase, string $workflowId, ?string $runId,
    string $url, string $namespace, array $diagnostics, array $traffic = []): void
{
    $directory = getenv('DW_PARITY_FAILURE_EVIDENCE_DIR');
    if (! is_string($directory) || ! is_dir($directory)) {
        return;
    }
    // The execution budget stays at 30 seconds. Failure inspection uses a
    // separate client with one-second requests and at most six reads; it does
    // not change the worker's transport or resume work after the deadline.
    $client = new Client($url, namespace: $namespace, token: getenv('DW_PARITY_TOKEN') ?: null,
        transport: new Psr18Transport(new GuzzleClient(['http_errors' => false, 'connect_timeout' => 1, 'timeout' => 1])));
    $read = static function (callable $inspect): array {
        try {
            return ['outcome' => 'observed', 'value' => $inspect()];
        } catch (Throwable $inspectionError) {
            $refusal = ['outcome' => 'inspection-failed', 'type' => $inspectionError::class];
            if ($inspectionError instanceof ServerException) {
                $refusal += ['status' => $inspectionError->status, 'reason' => $inspectionError->reason];
            }

            return $refusal;
        }
    };
    $snapshot = static function (string $id, string $selectedRun) use ($client, $read): array {
        return [
            'workflow_id' => $id, 'run_id' => $selectedRun,
            'execution' => $read(static fn (): array => $client->describeWorkflow($id, $selectedRun)->raw),
            // One bounded page, including its continuation token, is retained
            // honestly as a page rather than claimed to be complete history.
            'history_page' => $read(static fn (): array => $client->workflowHistory($id, $selectedRun, 100)),
            'diagnostics' => $read(static fn (): array => $client->workflowDiagnostics($id, $selectedRun)),
        ];
    };
    $evidence = ['captured_at' => gmdate('c'), 'phase' => $phase,
        'error' => ['type' => $error::class, 'message' => $error->getMessage()],
        'workflow_id' => $workflowId, 'run_id' => $runId,
        'worker_diagnostics' => array_slice($diagnostics, -200), 'traffic' => $traffic];
    if ($runId !== null) {
        $evidence['original_run'] = $snapshot($workflowId, $runId);
        $child = null;
        foreach ($evidence['original_run']['history_page']['value']['events'] ?? [] as $event) {
            if ($event['event_type'] === 'ChildRunStarted') {
                $child = $event;
                break;
            }
        }
        if ($child !== null) {
            $evidence['original_child'] = $snapshot($child['payload']['child_workflow_instance_id'], $child['payload']['child_workflow_run_id']);
        }
    }
    $name = preg_replace('/[^A-Za-z0-9_.-]/', '_', $workflowId);
    // Evidence must never replace the original probe failure with a write error.
    @file_put_contents($directory.'/failure-'.$name.'.json', json_encode($evidence,
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n");
}
