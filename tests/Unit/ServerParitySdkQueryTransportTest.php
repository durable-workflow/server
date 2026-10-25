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

    private function journalRecord(array $request): string
    {
        return json_encode($request + ['request_body' => '{}', 'response_body' => '',
            'request_body_complete' => true, 'upstream_request_started' => true,
            'client_cancelled' => false, 'transport_error' => null], JSON_THROW_ON_ERROR)."\n";
    }

    public function test_live_partial_write_cannot_release_a_checkpoint_before_its_receipt_is_committed(): void
    {
        $journal = tempnam(sys_get_temp_dir(), 'query-live-test-');
        $actor = ['journal' => $journal];
        try {
            [$poll, $receipt] = $this->acknowledgementRequests();
            $poll['response_body'] = json_encode($poll['response'], JSON_THROW_ON_ERROR);
            unset($poll['response']);
            $receipt['request_body'] = json_encode($receipt['request'], JSON_THROW_ON_ERROR);
            $receipt['response_body'] = json_encode($receipt['response'], JSON_THROW_ON_ERROR);
            unset($receipt['request'], $receipt['response']);
            $first = $this->journalRecord($poll);
            $second = $this->journalRecord($receipt);
            $split = intdiv(strlen($second), 2);
            file_put_contents($journal, $first.substr($second, 0, $split));
            $before = file_get_contents($journal);
            $requests = \sdkQueryLiveWireRequests($actor);
            self::assertCount(1, $requests);
            self::assertNull(\sdkQueryWorkflowAcknowledgement($requests, 'original', 'run', 0));
            self::assertSame($before, file_get_contents($journal));
            self::assertSame($requests, \sdkQueryLiveWireRequests($actor), 'an unfinished tail is not a new record');
            file_put_contents($journal, substr($second, $split), FILE_APPEND);
            $requests = \sdkQueryLiveWireRequests($actor);
            self::assertCount(2, $requests);
            self::assertSame(1, \sdkQueryWorkflowAcknowledgement($requests, 'original', 'run', 0)['request_index']);
            self::assertEquals($requests, \sdkQueryWireRequests($actor));
            self::assertSame($first.$second, file_get_contents($journal));
        } finally {
            unlink($journal);
        }
    }

    public function test_a_live_tail_without_any_committed_record_stays_pending(): void
    {
        $journal = tempnam(sys_get_temp_dir(), 'query-live-test-');
        $actor = ['journal' => $journal];
        try {
            $line = $this->journalRecord(['method' => 'GET', 'path' => '/api/cluster/info', 'status' => 200,
                'response_body' => json_encode(['details' => str_repeat('metadata λ', 70000)], JSON_THROW_ON_ERROR)]);
            file_put_contents($journal, substr($line, 0, intdiv(strlen($line), 2)));
            self::assertSame([], \sdkQueryLiveWireRequests($actor));
            file_put_contents($journal, $line);
            self::assertCount(1, \sdkQueryLiveWireRequests($actor));
            self::assertSame($line, file_get_contents($journal));
        } finally {
            unlink($journal);
        }
    }

    public function test_malformed_committed_live_record_still_fails(): void
    {
        $journal = tempnam(sys_get_temp_dir(), 'query-live-test-');
        $actor = ['journal' => $journal];
        try {
            file_put_contents($journal, "{\"method\":\n");
            $this->expectException(JsonException::class);
            \sdkQueryLiveWireRequests($actor);
        } finally {
            unlink($journal);
        }
    }

    public function test_final_observation_refuses_even_valid_json_without_its_commit_newline(): void
    {
        $journal = tempnam(sys_get_temp_dir(), 'query-live-test-');
        try {
            file_put_contents($journal, rtrim($this->journalRecord(['method' => 'GET', 'path' => '/api', 'status' => 200]), "\n"));
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('unterminated record');
            \sdkQueryWireRequests(['journal' => $journal]);
        } finally {
            unlink($journal);
        }
    }

    public function test_a_live_journal_cannot_lose_already_committed_bytes(): void
    {
        $journal = tempnam(sys_get_temp_dir(), 'query-live-test-');
        $actor = ['journal' => $journal];
        try {
            file_put_contents($journal, $this->journalRecord(['method' => 'GET', 'path' => '/api', 'status' => 200]));
            self::assertCount(1, \sdkQueryLiveWireRequests($actor));
            file_put_contents($journal, '');
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('lost committed bytes');
            \sdkQueryLiveWireRequests($actor);
        } finally {
            unlink($journal);
        }
    }

    private function queryCompletionRequests(): array
    {
        return [
            ['method' => 'POST', 'path' => '/api/worker/query-tasks/poll', 'status' => 200,
                'client_cancelled' => false, 'transport_error' => null,
                'response' => ['task' => ['query_task_id' => 'query-task', 'workflow_id' => 'original', 'run_id' => 'run']]],
            ['method' => 'POST', 'path' => '/api/worker/query-tasks/query-task/complete', 'status' => 200,
                'client_cancelled' => false, 'transport_error' => null,
                'response' => ['query_task_id' => 'query-task', 'outcome' => 'completed']],
        ];
    }

    public function test_the_author_is_released_only_after_its_query_completion_response(): void
    {
        self::assertTrue(\sdkQueryCompletionAcknowledged($this->queryCompletionRequests(), 'original', 'run'));
        self::assertFalse(\sdkQueryCompletionAcknowledged([$this->queryCompletionRequests()[0]], 'original', 'run'),
            'a claimed query whose completion has no response yet keeps the author running');
    }

    public function test_cancelled_failed_foreign_or_misordered_query_completions_do_not_release_the_author(): void
    {
        // The observed flake: the caller returned, the author was stopped, and
        // its in-flight completion was recorded as client-cancelled status 0.
        foreach ([
            'status' => 0, 'client_cancelled' => true, 'transport_error' => 'ECONNRESET',
        ] as $field => $value) {
            $requests = $this->queryCompletionRequests();
            $requests[1][$field] = $value;
            self::assertFalse(\sdkQueryCompletionAcknowledged($requests, 'original', 'run'), $field);
        }
        $requests = $this->queryCompletionRequests();
        $requests[1]['response'] = new stdClass;
        self::assertFalse(\sdkQueryCompletionAcknowledged($requests, 'original', 'run'), 'an empty response object');
        $requests = $this->queryCompletionRequests();
        $requests[1]['response']['query_task_id'] = 'foreign-query';
        self::assertFalse(\sdkQueryCompletionAcknowledged($requests, 'original', 'run'), 'a foreign completion receipt');
        $requests = $this->queryCompletionRequests();
        $requests[0]['response'] = new stdClass;
        self::assertFalse(\sdkQueryCompletionAcknowledged($requests, 'original', 'run'), 'an empty poll');
        self::assertFalse(\sdkQueryCompletionAcknowledged(array_reverse($this->queryCompletionRequests()), 'original', 'run'));
        self::assertFalse(\sdkQueryCompletionAcknowledged($this->queryCompletionRequests(), 'foreign', 'run'));
        self::assertFalse(\sdkQueryCompletionAcknowledged($this->queryCompletionRequests(), 'original', 'foreign'));
    }
}
