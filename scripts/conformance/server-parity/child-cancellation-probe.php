<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\WorkflowStub;

final class ChildCancellationProbeState
{
    public static ?array $caught = null;
}

function driveHttpChildCancellation(Client $client, string $parentId, string $parentRunId, array $fixture, ?array &$state): void
{
    if ($state !== null) {
        return;
    }
    $parentHistory = httpHistory($client, $parentId, $parentRunId);
    foreach ($parentHistory as $event) {
        if ($event['event_type'] !== 'ChildRunStarted') {
            continue;
        }
        $id = $event['payload']['child_workflow_instance_id'];
        $runId = $event['payload']['child_workflow_run_id'];
        $history = httpHistory($client, $id, $runId);
        if ($fixture['child_cancellation']['phase'] === 'pending_timer'
            && array_filter($history, static fn (array $row): bool => $row['event_type'] === 'TimerScheduled') === []) {
            return;
        }
        $state = ['workflow_id' => $id, 'run_id' => $runId,
            'child_before' => $client->describeWorkflow($id, $runId)->raw,
            'child_history_before' => $history, 'parent_history_before' => $parentHistory];
        $state['accepted'] = cancellationReceipt(static fn (): array => $client->cancelWorkflow($id, $fixture['child_cancellation']['reason'], $runId));
        if ($state['accepted']['status'] !== 200) {
            throw new RuntimeException('Direct child cancellation was refused: '.json_encode($state['accepted'], JSON_THROW_ON_ERROR));
        }
        $state['child_history_before_duplicate'] = httpHistory($client, $id, $runId);
        $state['parent_history_before_duplicate'] = httpHistory($client, $parentId, $parentRunId);
        $state['duplicate'] = cancellationReceipt(static fn (): array => $client->cancelWorkflow($id, 'replacement child reason', $runId));
        $state['child_history_after_duplicate'] = httpHistory($client, $id, $runId);
        $state['parent_history_after_duplicate'] = httpHistory($client, $parentId, $parentRunId);

        return;
    }
}

function finishHttpChildCancellation(Client $client, string $parentId, string $parentRunId, array $state, string $url, string $namespace): array
{
    $id = $state['workflow_id'];
    $runId = $state['run_id'];
    $state['child_history_before_terminal_duplicate'] = httpHistory($client, $id, $runId);
    $state['parent_history_before_terminal_duplicate'] = httpHistory($client, $parentId, $parentRunId);
    $state['terminal_duplicate'] = cancellationReceipt(static fn (): array => $client->cancelWorkflow($id, 'post-parent-completion replacement', $runId));
    $state['child_history_after_terminal_duplicate'] = httpHistory($client, $id, $runId);
    $state['parent_history_after_terminal_duplicate'] = httpHistory($client, $parentId, $parentRunId);
    $fresh = new Client($url, namespace: $namespace, token: getenv('DW_PARITY_TOKEN') ?: null);
    $state['fresh_child'] = $fresh->describeWorkflow($id, $runId)->raw;
    $state['fresh_child_history'] = httpHistory($fresh, $id, $runId);
    $state['fresh_parent'] = $fresh->describeWorkflow($parentId, $parentRunId)->raw;
    $state['fresh_parent_history'] = httpHistory($fresh, $parentId, $parentRunId);

    return $state;
}

function driveEmbeddedChildCancellation(string $parentRunId, array $fixture, ?array &$state): void
{
    if ($state !== null) {
        return;
    }
    $parentHistory = embeddedCancellationHistory($parentRunId);
    foreach ($parentHistory as $event) {
        if ($event['event_type'] !== 'ChildRunStarted') {
            continue;
        }
        $id = $event['payload']['child_workflow_instance_id'];
        $runId = $event['payload']['child_workflow_run_id'];
        $history = embeddedCancellationHistory($runId);
        if ($fixture['child_cancellation']['phase'] === 'pending_timer'
            && array_filter($history, static fn (array $row): bool => $row['event_type'] === 'TimerScheduled') === []) {
            return;
        }
        $child = WorkflowRun::query()->findOrFail($runId);
        $state = ['workflow_id' => $id, 'run_id' => $runId,
            'child_before' => $child->toArray(), 'child_history_before' => $history,
            'parent_history_before' => $parentHistory];
        $state['accepted'] = embeddedCancellationReceipt(WorkflowStub::loadRun($runId, 'default')->attemptCancel($fixture['child_cancellation']['reason']));
        $state['child_history_before_duplicate'] = embeddedCancellationHistory($runId);
        $state['parent_history_before_duplicate'] = embeddedCancellationHistory($parentRunId);
        $state['failures_before_duplicate'] = $child->failures()->orderBy('id')->get()->map->toArray()->all();
        $state['duplicate'] = embeddedCancellationReceipt(WorkflowStub::loadRun($runId, 'default')->attemptCancel('replacement child reason'));
        $state['child_history_after_duplicate'] = embeddedCancellationHistory($runId);
        $state['parent_history_after_duplicate'] = embeddedCancellationHistory($parentRunId);
        $state['failures_after_duplicate'] = $child->failures()->orderBy('id')->get()->map->toArray()->all();

        return;
    }
}

function finishEmbeddedChildCancellation(string $parentRunId, array $state): array
{
    $runId = $state['run_id'];
    $state['child_history_before_terminal_duplicate'] = embeddedCancellationHistory($runId);
    $state['parent_history_before_terminal_duplicate'] = embeddedCancellationHistory($parentRunId);
    $state['terminal_duplicate'] = embeddedCancellationReceipt(WorkflowStub::loadRun($runId, 'default')->attemptCancel('post-parent-completion replacement'));
    $state['child_history_after_terminal_duplicate'] = embeddedCancellationHistory($runId);
    $state['parent_history_after_terminal_duplicate'] = embeddedCancellationHistory($parentRunId);
    $child = WorkflowRun::query()->findOrFail($runId);
    $state['fresh_child'] = $child->toArray();
    $state['fresh_child_history'] = embeddedCancellationHistory($runId);
    $state['fresh_parent'] = WorkflowRun::query()->findOrFail($parentRunId)->toArray();
    $state['fresh_parent_history'] = embeddedCancellationHistory($parentRunId);
    $state['fresh_failures'] = $child->failures()->orderBy('id')->get()->map->toArray()->all();

    return $state;
}
