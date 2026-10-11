<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2).'/scripts/conformance/server-parity/sdk-direction-probe.php';

final class ServerParitySdkDirectionResponseSummaryTest extends TestCase
{
    public function test_repeated_protocol_metadata_does_not_fill_the_recorder_pipe(): void
    {
        $wire = ['recorded' => true, 'run_id' => 'original-run',
            'task' => ['task_id' => 'original-task', 'workflow_run_id' => 'original-run',
                'history' => [str_repeat('history', 750000)]],
            'server_capabilities' => ['discovery' => str_repeat('metadata', 750000)]];
        $before = serialize($wire);
        $summary = \sdkDirectionResponseSummary($wire);

        self::assertLessThan(1024, strlen(json_encode($summary, JSON_THROW_ON_ERROR)));
        self::assertTrue($summary['recorded']);
        self::assertSame('original-run', $summary['run_id']);
        self::assertSame('original-task', $summary['task']['task_id']);
        self::assertSame('original-run', $summary['task']['workflow_run_id']);
        self::assertSame($before, serialize($wire), 'the full wire response is not mutated');
    }

    public function test_refused_and_missing_receipts_remain_distinguishable(): void
    {
        $refused = \sdkDirectionResponseSummary(['recorded' => false, 'registered' => false,
            'outcome' => 'refused', 'reason' => 'stale_attempt', 'error' => 'actual_server_error']);
        self::assertFalse($refused['recorded']);
        self::assertFalse($refused['registered']);
        self::assertSame('refused', $refused['outcome']);
        self::assertSame('stale_attempt', $refused['reason']);
        self::assertSame('actual_server_error', $refused['error']);
        $missing = \sdkDirectionResponseSummary(['server_capabilities' => []]);
        self::assertArrayNotHasKey('recorded', $missing);
        self::assertArrayNotHasKey('registered', $missing);
    }

    public function test_empty_polls_and_original_start_identity_are_preserved(): void
    {
        self::assertNull(\sdkDirectionResponseSummary(null));
        self::assertSame(['task' => null, 'poll_status' => 'empty'],
            \sdkDirectionResponseSummary(['task' => null, 'poll_status' => 'empty', 'server_capabilities' => []]));
        self::assertSame(['run_id' => 'original-run'],
            \sdkDirectionResponseSummary(['run_id' => 'original-run', 'control_plane' => ['details' => 'raw-only']]));
    }

    public function test_timer_claim_keeps_original_deadline_and_full_history_without_discovery_metadata(): void
    {
        $task = ['task_id' => 'original-timer-resume', 'workflow_id' => 'original-workflow',
            'run_id' => 'original-run', 'workflow_type' => 'parity.v1.sdk_timer',
            'lease_owner' => 'cold-timer-worker', 'workflow_task_attempt' => 1,
            'task_queue' => 'server-parity-sdk-timer',
            'history_events' => [['sequence' => 3, 'event_type' => 'TimerScheduled',
                'payload' => ['timer_id' => 'original-timer', 'sequence' => 1, 'delay_seconds' => 6,
                    'fire_at' => '2026-01-01T00:00:06.123456Z']]]];
        $wire = ['task' => $task, 'server_capabilities' => ['repeated' => str_repeat('metadata', 750000)]];
        $before = serialize($wire);
        $summary = \sdkDirectionResponseSummary($wire, true);

        self::assertSame($task, $summary['task']);
        self::assertArrayNotHasKey('server_capabilities', $summary);
        self::assertSame($before, serialize($wire));
        self::assertLessThan(2048, strlen(json_encode($summary, JSON_THROW_ON_ERROR)));
    }

    public function test_child_claim_keeps_durable_prefix_and_resume_identity_without_discovery_metadata(): void
    {
        $task = ['task_id' => 'original-parent-task', 'workflow_id' => 'original-parent',
            'run_id' => 'original-parent-run', 'workflow_type' => 'parity.v1.one_child',
            'lease_owner' => 'cold-parent-worker', 'workflow_task_attempt' => 1,
            'task_queue' => 'server-parity-sdk-parent',
            'history_events' => [['sequence' => 5, 'event_type' => 'ChildRunCompleted', 'payload' => ['child_call_id' => 'original-call']]],
            'child_call_id' => 'original-call', 'child_workflow_run_id' => 'original-child-run',
            'resume_source_kind' => 'child_workflow_run', 'resume_source_id' => 'original-child-run',
            'workflow_event_type' => 'ChildRunCompleted', 'workflow_sequence' => 1, 'open_wait_id' => 'child:original-call'];
        $wire = ['task' => $task, 'server_capabilities' => ['repeated' => str_repeat('metadata', 750000)]];
        $before = serialize($wire);
        $summary = \sdkDirectionResponseSummary($wire, true);

        self::assertSame($task, $summary['task']);
        self::assertArrayNotHasKey('server_capabilities', $summary);
        self::assertSame($before, serialize($wire));
        self::assertLessThan(2048, strlen(json_encode($summary, JSON_THROW_ON_ERROR)));
    }
}
