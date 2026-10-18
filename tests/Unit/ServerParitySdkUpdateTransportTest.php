<?php

declare(strict_types=1);

namespace Tests\Unit;

use JsonException;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2).'/scripts/conformance/server-parity/sdk-direction-probe.php';
require_once dirname(__DIR__, 2).'/scripts/conformance/server-parity/sdk-query-direction-probe.php';
require_once dirname(__DIR__, 2).'/scripts/conformance/server-parity/sdk-update-direction-probe.php';

final class ServerParitySdkUpdateTransportTest extends TestCase
{
    private function requests(): array
    {
        return [
            ['method' => 'POST', 'path' => '/api/worker/workflow-tasks/poll', 'status' => 200,
                'response' => ['task' => ['task_id' => 'original-task', 'workflow_id' => 'original', 'run_id' => 'run',
                    'workflow_update_id' => 'update', 'lease_owner' => 'author', 'workflow_task_attempt' => 1]]],
            ['method' => 'POST', 'path' => '/api/worker/workflow-tasks/original-task/complete', 'status' => 200,
                'client_cancelled' => false, 'request_body_complete' => true, 'upstream_request_started' => true,
                'transport_error' => null,
                'request' => ['lease_owner' => 'author', 'workflow_task_attempt' => 1,
                    'commands' => [['type' => 'complete_update', 'update_id' => 'update']]],
                'response' => ['task_id' => 'original-task', 'run_id' => 'run', 'workflow_task_attempt' => 1,
                    'recorded' => true, 'reason' => null]],
        ];
    }

    public function test_original_update_checkpoint_requires_its_matching_fenced_completion(): void
    {
        self::assertSame(['path' => '/api/worker/workflow-tasks/original-task/complete', 'task_id' => 'original-task',
            'lease_owner' => 'author', 'workflow_task_attempt' => 1, 'request_index' => 1, 'status' => 200],
            \sdkUpdateWorkflowAcknowledgement($this->requests(), 'original', 'run', 'resumed-1', 'update'));
        self::assertNull(\sdkUpdateWorkflowAcknowledgement(array_reverse($this->requests()), 'original', 'run', 'resumed-1', 'update'));
        self::assertNull(\sdkUpdateWorkflowAcknowledgement([$this->requests()[0]], 'original', 'run', 'resumed-1', 'update'));
    }

    public function test_partial_failed_and_foreign_receipts_never_release_a_cold_author(): void
    {
        foreach ([
            ['status', 409], ['method', 'GET'], ['path', '/api/worker/workflow-tasks/foreign/complete'],
            ['client_cancelled', true], ['request_body_complete', false], ['upstream_request_started', false],
            ['transport_error', 'reset'],
        ] as [$field, $value]) {
            $requests = $this->requests();
            $requests[1][$field] = $value;
            self::assertNull(\sdkUpdateWorkflowAcknowledgement($requests, 'original', 'run', 'resumed-1', 'update'), $field);
        }
        foreach ([
            ['request', 'lease_owner', 'foreign'], ['request', 'workflow_task_attempt', 2],
            ['response', 'task_id', 'foreign'], ['response', 'run_id', 'foreign'],
            ['response', 'workflow_task_attempt', 2], ['response', 'recorded', false], ['response', 'reason', 'ignored'],
        ] as [$side, $field, $value]) {
            $requests = $this->requests();
            $requests[1][$side][$field] = $value;
            self::assertNull(\sdkUpdateWorkflowAcknowledgement($requests, 'original', 'run', 'resumed-1', 'update'), $side.'.'.$field);
        }
        foreach (['update_id', 'type'] as $field) {
            $requests = $this->requests();
            $requests[1]['request']['commands'][0][$field] = 'foreign';
            self::assertNull(\sdkUpdateWorkflowAcknowledgement($requests, 'original', 'run', 'resumed-1', 'update'));
        }
        self::assertNull(\sdkUpdateWorkflowAcknowledgement($this->requests(), 'foreign', 'run', 'resumed-1', 'update'));
        self::assertNull(\sdkUpdateWorkflowAcknowledgement($this->requests(), 'original', 'foreign', 'resumed-1', 'update'));
        self::assertNull(\sdkUpdateWorkflowAcknowledgement($this->requests(), 'original', 'run', 'resumed-1', 'foreign'));
    }

    public function test_failed_handler_checkpoint_cannot_be_replaced_by_a_successful_update_ack(): void
    {
        $requests = $this->requests();
        self::assertNull(\sdkUpdateWorkflowAcknowledgement($requests, 'original', 'run', 'resumed-1', 'update', 'fail_update'));
        $requests[1]['request']['commands'][0]['type'] = 'fail_update';
        self::assertNotNull(\sdkUpdateWorkflowAcknowledgement($requests, 'original', 'run', 'resumed-1', 'update', 'fail_update'));
        self::assertNull(\sdkUpdateWorkflowAcknowledgement($requests, 'original', 'run', 'resumed-1', 'update'));
        $requests[1]['request']['commands'][0]['update_id'] = 'foreign';
        self::assertNull(\sdkUpdateWorkflowAcknowledgement($requests, 'original', 'run', 'resumed-1', 'update', 'fail_update'));
    }

    private function arrival(): array
    {
        return ['schema' => 'durable-workflow.server-parity-http-request-arrival/v1', 'request_observation_id' => 1,
            'method' => 'POST', 'path' => '/api/workflows/original/update/set_payload',
            'request_body' => '{"request_id":"stable","input":{"codec":"avro","blob":"original"}}',
            'request_body_complete' => true, 'upstream_request_started' => true, 'response_observed' => false];
    }

    public function test_unterminated_live_arrival_does_not_release_the_author_or_alter_evidence(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'update-arrival-test-');
        $line = json_encode($this->arrival(), JSON_THROW_ON_ERROR)."\n";
        try {
            file_put_contents($path, substr($line, 0, -1));
            self::assertSame([], \sdkUpdateLiveArrivals(['arrivals' => $path]));
            self::assertSame(substr($line, 0, -1), file_get_contents($path));
            file_put_contents($path, "\n", FILE_APPEND);
            self::assertSame([$this->arrival()], \sdkUpdateLiveArrivals(['arrivals' => $path]));
            self::assertSame($line, file_get_contents($path));
        } finally {
            unlink($path);
        }
    }

    public function test_completed_arrival_journal_retains_bodies_and_order(): void
    {
        $second = $this->arrival();
        $second['request_observation_id'] = 2;
        $second['request_body'] = '{"request_id":"stable","input":{"codec":"avro","blob":"duplicate"}}';
        $journal = json_encode($this->arrival(), JSON_THROW_ON_ERROR)."\n".json_encode($second, JSON_THROW_ON_ERROR)."\n";
        self::assertSame([$this->arrival(), $second], \sdkUpdateArrivalRecords($journal));
        self::assertSame([], \sdkUpdateArrivalRecords(''));
    }

    public function test_a_final_arrival_journal_must_not_hide_an_incomplete_tail(): void
    {
        $this->expectException(\RuntimeException::class);
        \sdkUpdateArrivalRecords(json_encode($this->arrival(), JSON_THROW_ON_ERROR));
    }

    public function test_malformed_committed_arrival_cannot_be_treated_as_a_submission(): void
    {
        $this->expectException(JsonException::class);
        \sdkUpdateArrivalRecords("{\"schema\":\n");
    }

    public function test_real_bodyless_discovery_prefix_is_not_decoded_as_update_json(): void
    {
        $discovery = $this->arrival();
        $discovery['method'] = 'GET';
        $discovery['path'] = '/api/cluster/info';
        $discovery['request_body'] = '';
        self::assertNull(\sdkUpdateSubmissionArrival([$discovery], 'original', 'stable'));
        self::assertSame($this->arrival(), \sdkUpdateSubmissionArrival([$discovery, $this->arrival()], 'original', 'stable'));
    }

    public function test_foreign_paths_or_incomplete_forwarding_cannot_supply_an_update_submission(): void
    {
        foreach ([
            ['method', 'GET'], ['path', '/api/workflows/foreign/update/set_payload'],
            ['path', '/api/worker/workflow-tasks/poll'], ['schema', 'foreign-schema'],
            ['request_body_complete', false], ['upstream_request_started', false], ['response_observed', true],
        ] as [$field, $value]) {
            $arrival = $this->arrival();
            $arrival[$field] = $value;
            $arrival['request_body'] = '{'; // Unrelated bytes must remain uninterpreted.
            self::assertNull(\sdkUpdateSubmissionArrival([$arrival], 'original', 'stable'), $field);
        }
        self::assertNull(\sdkUpdateSubmissionArrival([$this->arrival()], 'original', 'foreign-id'));
    }

    public function test_matching_forwarded_empty_update_body_still_fails(): void
    {
        $arrival = $this->arrival();
        $arrival['request_body'] = '';
        $this->expectException(JsonException::class);
        \sdkUpdateSubmissionArrival([$arrival], 'original', 'stable');
    }

    public function test_matching_forwarded_malformed_update_body_still_fails(): void
    {
        $arrival = $this->arrival();
        $arrival['request_body'] = '{';
        $this->expectException(JsonException::class);
        \sdkUpdateSubmissionArrival([$arrival], 'original', 'stable');
    }
}
