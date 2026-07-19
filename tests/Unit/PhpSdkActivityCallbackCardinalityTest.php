<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PhpSdkActivityCallbackCardinalityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require_once dirname(__DIR__, 2).'/scripts/conformance/php-sdk-activity-callback-cardinality.php';
        require_once dirname(__DIR__, 2).'/scripts/conformance/php-sdk-assertion-failure-evidence.php';
    }

    public function test_replay_matrix_callbacks_are_bounded_by_phase_and_durable_history(): void
    {
        $cardinality = php_sdk_activity_callback_cardinality(
            ['callbacks' => [
                $this->activityCallback('initial_execution', 'task-simple', 'attempt-simple', 'php-sdk-worker-1'),
                $this->activityCallback('replay_matrix', 'task-matrix-1', 'attempt-matrix-1', 'php-sdk-worker-1'),
                $this->activityCallback('replay_matrix', 'task-matrix-2', 'attempt-matrix-2', 'php-sdk-worker-1'),
                $this->activityCallback('replay_matrix', 'task-matrix-3', 'attempt-matrix-3', 'php-sdk-worker-1'),
                $this->activityCallback('durable_replay', 'task-replay', 'attempt-replay', 'php-sdk-worker-1'),
                $this->activityCallback('in_flight_replay', 'task-in-flight', 'attempt-in-flight', 'php-sdk-worker-2'),
            ]],
            $this->historyEventCounts(),
            true,
        );

        $this->assertTrue($cardinality['passed']);
        $this->assertSame(
            [
                'initial_execution' => 1,
                'durable_replay' => 1,
                'replay_matrix' => 3,
                'in_flight_replay' => 1,
            ],
            $cardinality['expected_callback_counts_by_phase'],
        );
        $this->assertSame(6, array_sum($cardinality['expected_callback_counts_by_phase']));
        $this->assertSame(1, $cardinality['phase_results']['durable_replay']['observed_callback_count']);
        $this->assertSame(
            ['ActivityScheduled' => 1, 'ActivityCompleted' => 1],
            $cardinality['phase_results']['durable_replay']['history_checkpoints']['after_worker_restart']['observed_event_counts'],
        );
    }

    public function test_duplicate_replay_callback_reports_the_failing_phase_and_unchanged_history(): void
    {
        $callbacks = [
            $this->activityCallback('initial_execution', 'task-simple', 'attempt-simple', 'php-sdk-worker-1'),
            $this->activityCallback('durable_replay', 'task-replay', 'attempt-replay', 'php-sdk-worker-1'),
            $this->activityCallback('durable_replay', 'task-replay', 'attempt-replay', 'php-sdk-worker-2'),
        ];
        $cardinality = php_sdk_activity_callback_cardinality(
            ['callbacks' => $callbacks],
            array_intersect_key(
                $this->historyEventCounts(),
                array_flip(['initial_execution', 'durable_replay']),
            ),
            false,
        );

        $this->assertFalse($cardinality['passed']);
        $this->assertFalse($cardinality['phase_results']['durable_replay']['passed']);
        $this->assertSame(2, $cardinality['phase_results']['durable_replay']['observed_callback_count']);
        $this->assertSame(['task-replay'], $cardinality['phase_results']['durable_replay']['distinct_task_ids']);

        $failures = php_sdk_assertion_failure_evidence(
            ['activity_callback_once_for_replay', 'activity_callback_cardinality_by_phase'],
            [
                'activity_callback_once_for_replay' => 'server',
                'activity_callback_cardinality_by_phase' => 'server',
            ],
            [],
            $cardinality,
        );
        $byOperation = array_column($failures, null, 'operation');

        $this->assertSame(1, $byOperation['activity.callback:replay']['expected']['callback_count']);
        $this->assertSame(2, $byOperation['activity.callback:replay']['observed']['callback_count']);
        $this->assertSame(
            ['ActivityScheduled' => 1, 'ActivityCompleted' => 1],
            $byOperation['activity.callback:replay']['observed']['history_event_counts']['after_worker_restart'],
        );
        $this->assertFalse(
            $byOperation['activity.callback:phase-cardinality']['observed']['phase_results']['durable_replay']['passed'],
        );
    }

    public function test_activity_inputs_are_assigned_to_their_execution_phase(): void
    {
        $this->assertSame('initial_execution', php_sdk_activity_callback_phase(['message' => 'hello']));
        $this->assertSame('durable_replay', php_sdk_activity_callback_phase(['replay' => true]));
        $this->assertSame('replay_matrix', php_sdk_activity_callback_phase(['matrix' => 'compensation']));
        $this->assertSame('in_flight_replay', php_sdk_activity_callback_phase(['matrix' => 'in-flight-after-signal']));
    }

    /** @return array<string, mixed> */
    private function activityCallback(string $phase, string $taskId, string $attemptId, string $workerId): array
    {
        return [
            'phase' => $phase,
            'task_id' => $taskId,
            'activity_attempt_id' => $attemptId,
            'worker_id' => $workerId,
            'activity_type' => 'php.sdk.echo',
            'attempt_number' => 1,
            'heartbeat_recorded' => true,
        ];
    }

    /** @return array<string, array<string, array<string, int>>> */
    private function historyEventCounts(): array
    {
        return [
            'initial_execution' => [
                'completed' => ['ActivityScheduled' => 1, 'ActivityCompleted' => 1],
            ],
            'durable_replay' => [
                'before_worker_restart' => ['ActivityScheduled' => 1, 'ActivityCompleted' => 1],
                'after_worker_restart' => ['ActivityScheduled' => 1, 'ActivityCompleted' => 1],
            ],
            'replay_matrix' => [
                'completed' => ['ActivityScheduled' => 4, 'ActivityCompleted' => 3, 'ActivityFailed' => 1],
                'after_worker_restart' => ['ActivityScheduled' => 4, 'ActivityCompleted' => 3, 'ActivityFailed' => 1],
            ],
            'in_flight_replay' => [
                'after_worker_restart' => ['ActivityScheduled' => 1, 'ActivityCompleted' => 1],
            ],
        ];
    }
}
