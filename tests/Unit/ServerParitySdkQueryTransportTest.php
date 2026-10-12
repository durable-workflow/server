<?php

declare(strict_types=1);

namespace Tests\Unit;

use JsonException;
use PHPUnit\Framework\TestCase;
use stdClass;

require_once dirname(__DIR__, 2).'/scripts/conformance/server-parity/sdk-direction-probe.php';
require_once dirname(__DIR__, 2).'/scripts/conformance/server-parity/sdk-query-direction-probe.php';

final class ServerParitySdkQueryTransportTest extends TestCase
{
    private function acknowledgementRequests(int $checkpoint = 0): array
    {
        return [
            ['method' => 'POST', 'path' => '/api/worker/workflow-tasks/poll', 'status' => 200,
                'response' => ['task' => ['task_id' => 'original-task', 'workflow_id' => 'original', 'run_id' => 'run',
                    'lease_owner' => 'author', 'workflow_task_attempt' => 1]]],
            ['method' => 'POST', 'path' => '/api/worker/workflow-tasks/original-task/complete', 'status' => 200,
                'client_cancelled' => false, 'request_body_complete' => true, 'upstream_request_started' => true, 'transport_error' => null,
                'request' => ['lease_owner' => 'author', 'workflow_task_attempt' => 1,
                    'commands' => [$checkpoint === 2 ? ['type' => 'complete_workflow']
                        : ['type' => 'open_condition_wait', 'condition_key' => 'payload:'.$checkpoint]]],
                'response' => ['task_id' => 'original-task', 'run_id' => 'run', 'workflow_task_attempt' => 1,
                    'recorded' => true, 'reason' => null]],
        ];
    }

    public function test_each_original_checkpoint_waits_for_its_matching_successful_receipt(): void
    {
        foreach ([0, 1, 2] as $checkpoint) {
            self::assertSame(['path' => '/api/worker/workflow-tasks/original-task/complete', 'task_id' => 'original-task',
                'lease_owner' => 'author', 'workflow_task_attempt' => 1, 'request_index' => 1, 'status' => 200],
                \sdkQueryWorkflowAcknowledgement($this->acknowledgementRequests($checkpoint), 'original', 'run', $checkpoint));
        }
    }

    public function test_matching_receipt_must_follow_the_original_claim(): void
    {
        self::assertNull(\sdkQueryWorkflowAcknowledgement(array_reverse($this->acknowledgementRequests()), 'original', 'run', 0));
        self::assertNull(\sdkQueryWorkflowAcknowledgement([$this->acknowledgementRequests()[0]], 'original', 'run', 0));
    }

    public function test_failed_partial_or_foreign_completions_do_not_release_the_checkpoint(): void
    {
        foreach ([
            ['status', 409], ['method', 'GET'], ['path', '/api/worker/workflow-tasks/foreign-task/complete'],
            ['client_cancelled', true], ['request_body_complete', false], ['upstream_request_started', false],
            ['transport_error', 'connection closed'], ['request', new stdClass()], ['response', new stdClass()],
        ] as [$field, $value]) {
            $requests = $this->acknowledgementRequests();
            $requests[1][$field] = $value;
            self::assertNull(\sdkQueryWorkflowAcknowledgement($requests, 'original', 'run', 0), $field);
        }
        foreach ([
            ['request', 'lease_owner', 'foreign'], ['request', 'workflow_task_attempt', 2],
            ['response', 'task_id', 'foreign'], ['response', 'run_id', 'foreign'],
            ['response', 'workflow_task_attempt', 2], ['response', 'recorded', false], ['response', 'reason', 'ignored'],
        ] as [$side, $field, $value]) {
            $requests = $this->acknowledgementRequests();
            $requests[1][$side][$field] = $value;
            self::assertNull(\sdkQueryWorkflowAcknowledgement($requests, 'original', 'run', 0), $side.'.'.$field);
        }
        self::assertNull(\sdkQueryWorkflowAcknowledgement($this->acknowledgementRequests(), 'foreign', 'run', 0));
        self::assertNull(\sdkQueryWorkflowAcknowledgement($this->acknowledgementRequests(), 'original', 'foreign', 0));
        self::assertNull(\sdkQueryWorkflowAcknowledgement($this->acknowledgementRequests(1), 'original', 'run', 0));
    }

    private function parse(string $request, string $response = '', array $fields = []): array
    {
        $journal = tempnam(sys_get_temp_dir(), 'query-wire-test-');
        $wire = $fields + ['method' => 'POST', 'path' => '/api/workflows/original/query/state',
            'status' => 200, 'request_body' => $request, 'response_body' => $response,
            'request_body_complete' => true, 'upstream_request_started' => true,
            'client_cancelled' => false, 'transport_error' => null, 'response_bytes_base64' => base64_encode($response)];
        $bytes = json_encode($wire, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
        file_put_contents($journal, $bytes);
        try {
            $requests = \sdkQueryWireRequests(['journal' => $journal]);
            self::assertSame($bytes, file_get_contents($journal), 'original exact wire journal stays intact');

            return $requests[0];
        } finally {
            unlink($journal);
        }
    }

    public function test_python_empty_object_stays_distinct_from_an_empty_argument_array(): void
    {
        $object = $this->parse('{}')['request'];
        self::assertInstanceOf(stdClass::class, $object);
        self::assertSame('{}', json_encode($object, JSON_THROW_ON_ERROR));
        $array = $this->parse('[]')['request'];
        self::assertSame([], $array);
        self::assertSame('[]', json_encode($array, JSON_THROW_ON_ERROR));
    }

    public function test_whitespace_does_not_change_empty_object_kind(): void
    {
        $request = $this->parse(" { \n } ")['request'];
        self::assertInstanceOf(stdClass::class, $request);
        self::assertSame('{}', json_encode($request, JSON_THROW_ON_ERROR));
    }

    public function test_empty_response_object_is_not_a_missing_receipt(): void
    {
        self::assertInstanceOf(stdClass::class, $this->parse('{}', '{}')['response']);
        self::assertNull($this->parse('{}')['response']);
    }

    public function test_unicode_and_int64_remain_exact_when_actual_wire_is_parsed(): void
    {
        $request = $this->parse('{"value":{"message":"query λ","count":9007199254740993,"ready":true,"optional":null}}')['request'];
        self::assertSame(9007199254740993, $request['value']['count']);
        self::assertSame('query λ', $request['value']['message']);
        self::assertTrue($request['value']['ready']);
        self::assertNull($request['value']['optional']);
        self::assertStringContainsString('9007199254740993', json_encode($request, JSON_THROW_ON_ERROR));
    }

    public function test_cancelled_incomplete_unsent_body_is_not_invented_as_a_request(): void
    {
        $request = $this->parse('{"input":', fields: ['status' => 0,
            'request_body_complete' => false, 'upstream_request_started' => false, 'client_cancelled' => true]);
        self::assertNull($request['request']);
        self::assertNull($request['response']);
        self::assertFalse($request['request_body_complete']);
        self::assertFalse($request['upstream_request_started']);
    }

    public function test_cancelled_fully_received_held_poll_keeps_its_actual_body(): void
    {
        $request = $this->parse('{"task_queue":"original-queue"}', fields: ['status' => 0,
            'request_body_complete' => true, 'upstream_request_started' => false, 'client_cancelled' => true]);
        self::assertSame(['task_queue' => 'original-queue'], $request['request']);
        self::assertNull($request['response']);
        self::assertFalse($request['upstream_request_started']);
    }

    public function test_malformed_complete_body_is_not_treated_as_an_empty_query(): void
    {
        $this->expectException(JsonException::class);
        $this->parse('{"input":');
    }

    public function test_repeated_discovery_metadata_is_summary_only_with_full_original_bytes_retained(): void
    {
        $response = json_encode(['version' => 'published', 'server_id' => 'original-server',
            'server_capabilities' => ['query_tasks' => true, 'details' => str_repeat('metadata', 10000)]], JSON_THROW_ON_ERROR);
        $request = $this->parse('{}', $response, ['method' => 'GET', 'path' => '/api/cluster/info']);
        self::assertSame(['version' => 'published', 'server_id' => 'original-server'], $request['response']);
        self::assertLessThan(512, strlen(json_encode($request, JSON_THROW_ON_ERROR)));
    }
}
