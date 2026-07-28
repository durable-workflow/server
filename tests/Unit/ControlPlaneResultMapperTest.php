<?php

namespace Tests\Unit;

use App\Support\ControlPlaneResultMapper;
use Tests\TestCase;
use Workflow\Serializers\AvroBinaryValue;
use Workflow\Serializers\AvroMapValue;

class ControlPlaneResultMapperTest extends TestCase
{
    public function test_query_and_update_results_use_the_json_safe_projection(): void
    {
        $mapper = new ControlPlaneResultMapper;
        $result = AvroMapValue::fromPairs([
            ['0', AvroBinaryValue::fromBytes("\x00\xFF")],
            ['1', ['list']],
        ]);
        $envelope = ['codec' => 'avro', 'blob' => 'lossless-wire'];

        foreach ([
            $mapper->query('typed-1', 'inspect', [
                'result' => $result,
                'result_envelope' => $envelope,
            ]),
            $mapper->update('typed-1', 'replace', 'completed', [
                'result' => $result,
                'result_envelope' => $envelope,
            ]),
        ] as $response) {
            $payload = $response->getData(true);

            self::assertSame('map', $payload['result']['$type']);
            self::assertSame('0', $payload['result']['entries'][0]['key']);
            self::assertSame('bytes', $payload['result']['entries'][0]['value']['$type']);
            self::assertSame('AP8=', $payload['result']['entries'][0]['value']['base64']);
            self::assertSame(['list'], $payload['result']['entries'][1]['value']);
            self::assertSame($envelope, $payload['result_envelope']);
            json_encode($payload, JSON_THROW_ON_ERROR);
        }
    }
}
