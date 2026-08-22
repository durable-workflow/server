<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\WorkerRegistration;
use App\Support\WorkerProtocol;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Assert;
use Tests\TestCase;
use Workflow\Serializers\Serializer;

/** Workflow Stream extension for the immutable codec boundary helpers. */
final class ServerCodecRegressionBoundaryV3
{
    public static function exerciseWorkflowStreamItem(TestCase $test): void
    {
        Queue::fake();
        $start = $test->withHeaders(self::apiHeaders())->postJson('/api/workflows', [
            'workflow_id' => 'codec-workflow-stream-item',
            'workflow_type' => 'tests.external-greeting-workflow',
        ]);
        $start->assertCreated();

        WorkerRegistration::query()->updateOrCreate(
            ['worker_id' => 'codec-workflow-stream-worker', 'namespace' => 'default'],
            [
                'task_queue' => 'default',
                'runtime' => 'php',
                'supported_workflow_types' => ['tests.external-greeting-workflow'],
                'supported_activity_types' => [],
                'last_heartbeat_at' => now(),
                'status' => 'active',
            ],
        );

        $headers = self::workerHeaders();
        $poll = $test->withHeaders($headers)->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'codec-workflow-stream-worker',
            'task_queue' => 'default',
        ]);
        $poll->assertOk();

        $codec = self::proofInputCodec();
        $taskId = (string) $poll->json('task.task_id');
        $commandIdentity = (string) ($poll->json('task.workflow_command_id') ?: $taskId);
        $response = $test->withHeaders($headers)->postJson(
            "/api/worker/workflow-tasks/{$taskId}/complete",
            [
                'lease_owner' => $poll->json('task.lease_owner'),
                'workflow_task_attempt' => $poll->json('task.workflow_task_attempt'),
                'commands' => [[
                    'type' => 'record_side_effect',
                    'result' => Serializer::serializeWithCodec('avro', null),
                    'workflow_stream' => [
                        'operation' => 'append',
                        'stream_name' => 'tokens',
                        'command_identity' => $commandIdentity,
                        'command_ordinal' => 0,
                        'items' => [[
                            'payload' => self::proofEnvelope($codec),
                            'payload_codec' => $codec,
                            'idempotency_key' => "dw-stream:{$commandIdentity}:0:0",
                        ]],
                    ],
                ]],
            ],
        );

        if ($codec === 'json') {
            $response->assertStatus(422)->assertJsonPath(
                'reason',
                'invalid_workflow_stream_command',
            );
            Assert::assertStringContainsString(
                'unsupported_payload_codec',
                $response->getContent(),
            );

            return;
        }

        Assert::assertTrue($response->isSuccessful(), $response->getContent());
    }

    /** @return array{codec: string, blob: string} */
    private static function proofEnvelope(string $codec): array
    {
        return [
            'codec' => $codec,
            'blob' => $codec === 'avro'
                ? Serializer::serializeWithCodec('avro', ['stream-item'])
                : '{"stale":true}',
        ];
    }

    private static function proofInputCodec(): string
    {
        $configuredCodec = getenv('SERVER_CODEC_PROOF_INPUT_CODEC');
        $codec = is_string($configuredCodec) ? $configuredCodec : 'json';
        Assert::assertContains($codec, ['avro', 'json']);

        return $codec;
    }

    /** @return array<string, string> */
    private static function apiHeaders(): array
    {
        return [
            'X-Namespace' => 'default',
            'X-Durable-Workflow-Control-Plane-Version' => '2',
        ];
    }

    /** @return array<string, string> */
    private static function workerHeaders(): array
    {
        return [
            'X-Namespace' => 'default',
            WorkerProtocol::HEADER => WorkerProtocol::VERSION,
        ];
    }
}
