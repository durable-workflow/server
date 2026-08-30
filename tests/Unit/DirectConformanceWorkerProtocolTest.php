<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\DirectConformanceWorkerProtocol;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\Serializers\Avro;
use Workflow\Serializers\AvroBinaryValue;
use Workflow\Serializers\AvroMapValue;
use Workflow\Serializers\Serializer;

final class DirectConformanceWorkerProtocolTest extends TestCase
{
    public function test_release_critical_direct_probe_inventory_is_explicit(): void
    {
        $this->assertSame([
            'activities',
            'worker-versioning',
            'timers',
            'workflow-lifecycle',
            'workflow-updates-operator-diagnostics',
        ], DirectConformanceWorkerProtocol::RELEASE_CRITICAL_PROBES);
    }

    public function test_registration_declares_exact_types_and_truthful_current_capability_manifest(): void
    {
        $payload = DirectConformanceWorkerProtocol::registration(
            'probe-worker',
            'probe-queue',
            'php',
            'durable-workflow/server:published-artifact',
            ['probe.workflow'],
            ['probe.activity'],
            ['workflow_tasks'],
        );

        $this->assertSame(['probe.workflow'], $payload['supported_workflow_types']);
        $this->assertSame(['probe.activity'], $payload['supported_activity_types']);
        $this->assertSame(['workflow_tasks'], $payload['capabilities']);
        $this->assertSame(
            ['local_activities', 'worker_sessions', 'sticky_execution'],
            array_keys($payload['capability_manifest']),
        );
        foreach ($payload['capability_manifest'] as $entry) {
            $this->assertFalse($entry['supported']);
            $this->assertSame('1.18', $entry['minimum_protocol_version']);
            $this->assertNotSame('', $entry['reason']);
        }
    }

    public function test_registration_mutation_without_capability_manifest_fails_qualification(): void
    {
        $payload = DirectConformanceWorkerProtocol::registration(
            'probe-worker',
            'probe-queue',
            'php',
            'durable-workflow/server:published-artifact',
            ['probe.workflow'],
            [],
        );
        unset($payload['capability_manifest']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires capability_manifest');
        DirectConformanceWorkerProtocol::assertRegistrationPayload($payload);
    }

    #[DataProvider('jsonDocumentStringProvider')]
    public function test_completion_mutation_with_complete_json_document_string_fails_qualification(string $payload): void
    {
        $task = [
            'task_id' => 'task-1',
            'lease_owner' => 'probe-worker',
            'workflow_task_attempt' => 1,
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('json_bytes_labeled_avro');
        DirectConformanceWorkerProtocol::workflowTaskCompletion($task, [[
            'type' => 'complete_workflow',
            'result' => $payload,
        ]]);
    }

    /** @return iterable<string, array{string}> */
    public static function jsonDocumentStringProvider(): iterable
    {
        yield 'null' => [" \tnull\r\n"];
        yield 'true' => [" true\t"];
        yield 'false' => ["\nfalse "];
        yield 'integer' => [" 7\r\n"];
        yield 'exponent number' => ["\t-7.25e+3 "];
        yield 'string' => [" \"raw-json\"\n"];
        yield 'array' => ["\r[\"raw-json\"] "];
        yield 'object' => [" {\"status\":\"completed\"}\t"];
    }

    #[DataProvider('rawJsonValueProvider')]
    public function test_completion_mutation_with_non_string_json_value_fails_qualification(mixed $payload): void
    {
        $task = [
            'task_id' => 'task-1',
            'lease_owner' => 'probe-worker',
            'workflow_task_attempt' => 1,
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must contain an Avro Value payload');
        DirectConformanceWorkerProtocol::workflowTaskCompletion($task, [[
            'type' => 'complete_workflow',
            'result' => $payload,
        ]]);
    }

    /** @return iterable<string, array{mixed}> */
    public static function rawJsonValueProvider(): iterable
    {
        yield 'null' => [null];
        yield 'true' => [true];
        yield 'false' => [false];
        yield 'integer' => [7];
        yield 'float' => [7.25];
        yield 'array' => [['raw-json']];
        yield 'object-shaped array' => [['status' => 'completed']];
    }

    public function test_completion_preserves_fixed_avro_value_types_and_map_order(): void
    {
        $value = AvroMapValue::fromPairs([
            ['double', 7.0],
            ['long', 7],
            ['binary', AvroBinaryValue::fromBytes("\x00\xFF")],
            ['text', 'AP8='],
            ['nested', AvroMapValue::fromPairs([
                ['z', 1],
                ['a', 2],
            ])],
        ]);
        $task = [
            'task_id' => 'task-1',
            'lease_owner' => 'probe-worker',
            'workflow_task_attempt' => 1,
        ];
        $encoded = Avro::serialize($value);

        $completion = DirectConformanceWorkerProtocol::workflowTaskCompletion($task, [[
            'type' => 'complete_workflow',
            'result' => $encoded,
        ]]);

        $this->assertSame($encoded, $completion['commands'][0]['result']);
        $decoded = Serializer::unserializeWithCodec('avro', $encoded);
        $this->assertIsArray($decoded);
        $this->assertSame(['double', 'long', 'binary', 'text', 'nested'], array_keys($decoded));
        $this->assertSame(7.0, $decoded['double']);
        $this->assertSame(7, $decoded['long']);
        $this->assertInstanceOf(AvroBinaryValue::class, $decoded['binary']);
        $this->assertSame("\x00\xFF", $decoded['binary']->bytes);
        $this->assertSame('AP8=', $decoded['text']);
        $this->assertSame(['z', 'a'], array_keys($decoded['nested']));
    }

    public function test_completion_accepts_fixed_avro_value_envelope(): void
    {
        $task = [
            'task_id' => 'task-1',
            'lease_owner' => 'probe-worker',
            'workflow_task_attempt' => 1,
        ];
        $envelope = Avro::envelope(AvroMapValue::fromPairs([
            ['status', 'completed'],
        ]));

        $completion = DirectConformanceWorkerProtocol::workflowTaskCompletion($task, [[
            'type' => 'complete_workflow',
            'result' => $envelope,
        ]]);

        $this->assertSame($envelope, $completion['commands'][0]['result']);
    }

    public function test_successful_setup_without_runtime_artifact_fails_validation(): void
    {
        $missing = sys_get_temp_dir().'/dw-missing-operator-runtime-'.bin2hex(random_bytes(6)).'.json';
        $validator = dirname(__DIR__, 2).'/scripts/conformance/validate-workflow-updates-operator-runtime.php';
        $command = 'true && '.escapeshellarg(PHP_BINARY).' '.escapeshellarg($validator).' '.escapeshellarg($missing);

        exec($command.' 2>&1', $output, $exitCode);

        $this->assertSame(1, $exitCode, implode("\n", $output));
        $this->assertStringContainsString('operator_runtime_artifact_missing', implode("\n", $output));
    }

    public function test_node_direct_protocol_mutation_regressions(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('Node is required for the direct worker-versioning protocol regressions.');
        }

        $test = __DIR__.'/CurrentWorkerProtocolHelperTest.mjs';
        exec(escapeshellarg($node).' --test '.escapeshellarg($test).' 2>&1', $output, $exitCode);

        $this->assertSame(0, $exitCode, implode("\n", $output));
    }
}
