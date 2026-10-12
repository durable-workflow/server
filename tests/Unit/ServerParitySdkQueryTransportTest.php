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
