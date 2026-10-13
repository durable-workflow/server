<?php

declare(strict_types=1);

// Each callback invokes the installed HTTP Client or public embedded bridge.
// Responses retain their actual format; this recipe does not fabricate leases.
function activityHeartbeatRenewalObservation(array $fixture, array $initial, float $admittedAt,
    callable $snapshot, callable $budget, array $calls, callable $requests): array
{
    $spec = $fixture['activity_deadline_receipts'];
    usleep(1000000);
    $budget();
    $one = $calls['heartbeat'](['message' => 'first renewal λ']);
    $statusOne = $calls['status']();
    $afterOne = $snapshot();
    // Cross the original two-second heartbeat boundary, inside the renewed one.
    usleep(1250000);
    $budget();
    $statusAfterOriginal = $calls['status']();
    $afterStatus = $snapshot();
    $two = $calls['heartbeat'](['message' => 'second renewal λ']);
    $afterTwo = $snapshot();
    $completion = $calls['complete']();
    [$parent, $workflowCompletion] = $calls['finish']();
    $committed = $snapshot();
    $remaining = $admittedAt + $spec['schedule_to_close_timeout'] + 0.25 - microtime(true);
    if ($remaining > 0) usleep((int) ceil($remaining * 1000000));
    $budget();
    $late = $calls['heartbeat'](['message' => 'post-terminal progress']);
    $after = $snapshot();

    return [...$after, 'activity_deadline_receipts' => [...$initial,
        'overall_budget_seconds' => $spec['overall_budget_seconds'],
        'global_elapsed_seconds' => microtime(true) - $admittedAt,
        'heartbeat_one' => $one, 'status_one' => $statusOne, 'after_first_heartbeat' => $afterOne,
        'status_after_initial_expiry' => $statusAfterOriginal, 'after_status_read' => $afterStatus,
        'heartbeat_two' => $two, 'after_second_heartbeat' => $afterTwo,
        'activity_completion' => $completion, 'parent_delivery' => $parent,
        'workflow_completion' => $workflowCompletion, 'committed' => $committed,
        'late_heartbeat' => $late, 'post_completion_activity_poll' => $calls['extra_activity_poll'](),
        'requests' => $requests()]];
}
