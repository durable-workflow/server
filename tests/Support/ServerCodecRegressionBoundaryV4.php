<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\WorkerRegistration;
use App\Support\MessageStreamsContract;
use App\Support\WorkflowQueryTaskBroker;
use App\Support\WorkflowTaskPoller;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use Tests\TestCase;
use Throwable;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

/** Inbound message-stream extension for immutable codec boundary helpers. */
final class ServerCodecRegressionBoundaryV4
{
    public static function exerciseHistoryDelivery(TestCase $test): void
    {
        $configuredBoundary = getenv('SERVER_CODEC_CLAIMED_BOUNDARY');
        $boundary = is_string($configuredBoundary)
            ? $configuredBoundary
            : 'app/Support/WorkflowTaskPoller.php';

        match ($boundary) {
            'app/Support/WorkflowQueryTaskBroker.php' => self::exerciseQueryHistory($test),
            'app/Support/WorkflowTaskPoller.php' => self::exerciseWorkflowHistory(),
            default => throw new InvalidArgumentException("Unsupported message-stream codec boundary {$boundary}."),
        };
    }

    private static function exerciseQueryHistory(TestCase $test): void
    {
        Queue::fake();
        $start = $test->withHeaders(self::apiHeaders())->postJson('/api/workflows', [
            'workflow_id' => 'codec-message-stream-query-history',
            'workflow_type' => 'tests.external-greeting-workflow',
        ]);
        $start->assertCreated();
        $run = WorkflowRun::query()->findOrFail((string) $start->json('run_id'));
        self::recordHistoryEvent($run);

        WorkerRegistration::query()->updateOrCreate(
            ['worker_id' => 'codec-message-stream-query-worker', 'namespace' => 'default'],
            [
                'task_queue' => 'default',
                'runtime' => 'python',
                'sdk_version' => 'durable-workflow-python/2.0.0',
                'supported_workflow_types' => ['tests.external-greeting-workflow'],
                'supported_activity_types' => [],
                'capabilities' => ['query_tasks'],
                'last_heartbeat_at' => now(),
                'status' => 'active',
            ],
        );

        self::assertCodecBoundary(static function () use ($run): array {
            $broker = app(WorkflowQueryTaskBroker::class);
            $broker->enqueue(
                'default',
                $run->refresh(),
                'status',
                [
                    'codec' => 'avro',
                    'blob' => Serializer::serializeWithCodec('avro', []),
                ],
            );

            $worker = WorkerRegistration::query()
                ->where('namespace', 'default')
                ->where('worker_id', 'codec-message-stream-query-worker')
                ->firstOrFail();

            return $broker->poll('default', $worker) ?? [];
        });
    }

    private static function exerciseWorkflowHistory(): void
    {
        self::assertCodecBoundary(static fn (): array => app(WorkflowTaskPoller::class)
            ->historyEventsWithSignalArguments([self::historyEventPayload()], 'default', 'avro'));
    }

    /** @param  callable(): array<mixed>  $boundary */
    private static function assertCodecBoundary(callable $boundary): void
    {
        try {
            $result = $boundary();
            Assert::assertSame('avro', self::proofInputCodec());
            Assert::assertIsArray($result);
        } catch (Throwable $exception) {
            Assert::assertSame('json', self::proofInputCodec());
            Assert::assertStringContainsString('unsupported_payload_codec', $exception->getMessage());
        }
    }

    private static function recordHistoryEvent(WorkflowRun $run): void
    {
        $sequence = ((int) WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->max('sequence')) + 1;

        WorkflowHistoryEvent::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => $sequence,
            ...self::historyEventPayload(),
            'recorded_at' => now(),
        ]);
    }

    /** @return array{event_type: string, payload: array<string, mixed>} */
    private static function historyEventPayload(): array
    {
        return [
            'event_type' => HistoryEventType::SignalReceived->value,
            'payload' => [
                'signal_name' => MessageStreamsContract::INTERNAL_SIGNAL,
                'payload_codec' => 'avro',
                'arguments' => self::outerEnvelope(),
            ],
        ];
    }

    /** @return array{codec: string, blob: string} */
    private static function outerEnvelope(): array
    {
        $payloadCodec = self::proofInputCodec();
        $payloadBlob = $payloadCodec === 'avro'
            ? Serializer::serializeWithCodec('avro', ['message-stream-payload'])
            : '{"stale":true}';

        return [
            'codec' => 'avro',
            'blob' => Serializer::serializeWithCodec('avro', [[
                'schema' => MessageStreamsContract::MESSAGE_SCHEMA,
                'stream_name' => 'orders',
                'message_id' => 'codec-message-1',
                'position' => 1,
                'payload_envelope' => [
                    'codec' => $payloadCodec,
                    'blob' => $payloadBlob,
                ],
            ]]),
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
}
