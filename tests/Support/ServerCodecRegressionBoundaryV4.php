<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\RuntimeExternalPayload;
use App\Models\WorkerRegistration;
use App\Models\WorkflowNamespace;
use App\Support\ExternalTaskResultContract;
use App\Support\MessageStreamsContract;
use App\Support\NamespaceLifecycleCleanup;
use App\Support\RuntimeExternalPayloadReference;
use App\Support\WorkerProtocol;
use App\Support\WorkflowQueryTaskBroker;
use App\Support\WorkflowTaskPoller;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use Tests\TestCase;
use Throwable;
use Workflow\Serializers\AvroBinaryValue;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowMemo;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\MemoPayload;

/** Codec regression extensions for runtime payloads and inbound message streams. */
final class ServerCodecRegressionBoundaryV4
{
    public static function exerciseClaimedBoundary(TestCase $test): void
    {
        $configuredBoundary = getenv('SERVER_CODEC_CLAIMED_BOUNDARY');
        $boundary = is_string($configuredBoundary)
            ? $configuredBoundary
            : 'app/Http/Controllers/Api/HealthController.php';

        if (self::proofInputCodec() === 'avro') {
            self::exerciseSentinelBoundary($test, $boundary);

            return;
        }

        match ($boundary) {
            'app/Http/Controllers/Api/HealthController.php' => self::exerciseClusterInfo($test),
            'app/Http/Controllers/Api/WorkerController.php' => self::exerciseWorkerCommand($test),
            'app/Http/Controllers/Api/WorkflowStreamController.php' => self::exerciseStreamAppend($test),
            'app/Support/ExternalTaskResultContract.php' => self::exerciseTaskResultContract(),
            'app/Support/NamespaceLifecycleCleanup.php' => self::exerciseNamespaceCleanup(),
            default => Assert::fail("Unsupported runtime external-payload proof boundary {$boundary}."),
        };
    }

    public static function exerciseWorkflowMemoResolution(TestCase $test): void
    {
        if (self::proofInputCodec() === 'avro') {
            self::exerciseWorkerPollSentinel($test);

            return;
        }

        Queue::fake();
        self::configureStorage();
        $start = self::startWorkflow(
            $test,
            'runtime-payload-proof-memo',
            'runtime-payload-proof',
        );
        self::registerWorker();

        $poll = $test->withHeaders(self::workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'runtime-payload-proof-worker',
                'task_queue' => 'runtime-payload-proof',
            ])
            ->assertOk();
        $entries = MemoPayload::mapEnvelope([
            'stage' => 'waiting',
            'tenant' => null,
        ]);
        $reference = self::seedPayload($entries['blob']);

        $test->withHeaders(self::workerHeaders())
            ->postJson('/api/worker/workflow-tasks/'.$poll->json('task.task_id').'/complete', [
                'lease_owner' => $poll->json('task.lease_owner'),
                'workflow_task_attempt' => $poll->json('task.workflow_task_attempt'),
                'commands' => [[
                    'type' => 'upsert_memo',
                    'entries' => [
                        'codec' => 'avro',
                        'external_payload' => $reference,
                    ],
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('recorded', true);

        $event = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $start->json('run_id'))
            ->where('event_type', 'MemoUpserted')
            ->sole();
        Assert::assertSame(
            ['stage' => 'waiting', 'tenant' => null],
            MemoPayload::decodeEntries($event->payload['entries'] ?? []),
        );
        Assert::assertStringNotContainsString(
            $reference['reference_id'],
            json_encode($event->payload, JSON_THROW_ON_ERROR),
        );
    }

    public static function exerciseWorkflowMemoJsonProjection(TestCase $test): void
    {
        $configuredBoundary = getenv('SERVER_CODEC_CLAIMED_BOUNDARY');
        $boundary = is_string($configuredBoundary)
            ? $configuredBoundary
            : 'app/Http/Controllers/Api/WorkflowController.php';
        Assert::assertSame('app/Http/Controllers/Api/WorkflowController.php', $boundary);

        self::exerciseWorkflowMemoProjection(
            $test,
            invalidBinary: self::proofInputCodec() === 'json',
        );
    }

    private static function exerciseWorkflowMemoProjection(TestCase $test, bool $invalidBinary): void
    {
        Queue::fake();
        $workflowId = $invalidBinary
            ? 'codec-memo-invalid-binary-projection'
            : 'codec-memo-projection-sentinel';
        $start = self::startWorkflow($test, $workflowId);
        $run = WorkflowRun::query()->findOrFail((string) $start->json('run_id'));
        $value = $invalidBinary
            ? AvroBinaryValue::fromBytes("\xFF\x00")
            : 'waiting';

        WorkflowMemo::query()->create([
            'workflow_run_id' => $run->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'key' => 'stage',
            'value' => MemoPayload::envelope($value),
            'upserted_at_sequence' => 1,
        ]);

        $response = $test->withHeaders(self::controlHeaders())
            ->getJson("/api/workflows/{$workflowId}/runs/{$run->id}")
            ->assertOk();

        if ($invalidBinary) {
            $response->assertJsonPath('memo.stage', [
                '$type' => 'bytes',
                'base64' => '/wA=',
            ]);

            return;
        }

        $response->assertJsonPath('memo.stage', 'waiting');
    }

    private static function exerciseSentinelBoundary(TestCase $test, string $boundary): void
    {
        match ($boundary) {
            'app/Http/Controllers/Api/HealthController.php' => $test->getJson('/api/cluster/info')
                ->assertOk()
                ->assertJsonPath('capabilities.payload_codecs', ['avro']),
            'app/Http/Controllers/Api/WorkerController.php' => self::exerciseWorkerPollSentinel($test),
            'app/Http/Controllers/Api/WorkflowStreamController.php' => self::exerciseStreamListSentinel($test),
            'app/Support/ExternalTaskResultContract.php' => Assert::assertSame(
                ExternalTaskResultContract::SCHEMA,
                ExternalTaskResultContract::manifest()['schema'] ?? null,
            ),
            'app/Support/NamespaceLifecycleCleanup.php' => Assert::assertIsArray(
                app(NamespaceLifecycleCleanup::class)->cleanup('default'),
            ),
            default => Assert::fail("Unsupported runtime external-payload sentinel boundary {$boundary}."),
        };
    }

    private static function exerciseClusterInfo(TestCase $test): void
    {
        $test->withHeaders(['X-Namespace' => 'default'])
            ->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath(
                'namespace.external_payload_storage.schema',
                RuntimeExternalPayloadReference::SCHEMA,
            )
            ->assertJsonMissingPath('namespace.external_payload_storage.driver');
    }

    private static function exerciseTaskResultContract(): void
    {
        $payloadSupport = ExternalTaskResultContract::manifest()['payload_support'] ?? null;
        Assert::assertIsArray($payloadSupport);
        Assert::assertArrayHasKey('unsupported_external_payload', $payloadSupport);
        Assert::assertArrayNotHasKey('unsupported_external_storage', $payloadSupport);
    }

    private static function exerciseNamespaceCleanup(): void
    {
        self::configureStorage();
        $reference = self::seed('namespace cleanup payload');

        $deleted = app(NamespaceLifecycleCleanup::class)->cleanup('default');

        Assert::assertSame(1, $deleted['runtime_external_payload_references'] ?? null);
        Assert::assertNull(RuntimeExternalPayload::query()->find($reference['reference_id']));
    }

    private static function exerciseStreamAppend(TestCase $test): void
    {
        Queue::fake();
        self::configureStorage();
        $start = self::startWorkflow($test, 'runtime-payload-proof-stream');
        $reference = self::seed('stream integrity payload', corrupt: true);

        $test->withHeaders(self::controlHeaders())->postJson(
            '/api/workflows/runtime-payload-proof-stream/runs/'
                .$start->json('run_id').'/streams/proof/items',
            ['items' => [[
                'payload_reference' => $reference,
                'payload_codec' => 'avro',
            ]]],
        )->assertStatus(422)
            ->assertJsonPath('reason', 'external_payload_integrity_mismatch');
    }

    private static function exerciseWorkerCommand(TestCase $test): void
    {
        Queue::fake();
        self::configureStorage();
        self::startWorkflow($test, 'runtime-payload-proof-worker', 'runtime-payload-proof');
        self::registerWorker();

        $poll = $test->withHeaders(self::workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'runtime-payload-proof-worker',
                'task_queue' => 'runtime-payload-proof',
            ])
            ->assertOk();
        $taskId = (string) $poll->json('task.task_id');
        $commandIdentity = (string) ($poll->json('task.workflow_command_id') ?: $taskId);
        $reference = self::seed('worker command integrity payload', corrupt: true);

        $test->withHeaders(self::workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => $poll->json('task.lease_owner'),
                'workflow_task_attempt' => $poll->json('task.workflow_task_attempt'),
                'commands' => [[
                    'type' => 'record_side_effect',
                    'result' => Serializer::serializeWithCodec('avro', null),
                    'workflow_stream' => [
                        'operation' => 'append',
                        'stream_name' => 'proof',
                        'command_identity' => $commandIdentity,
                        'command_ordinal' => 0,
                        'items' => [[
                            'payload_reference' => $reference,
                            'payload_codec' => 'avro',
                            'idempotency_key' => "dw-stream:{$commandIdentity}:0:0",
                        ]],
                    ],
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('recorded', false)
            ->assertJsonPath('reason', 'external_payload_integrity_mismatch');
    }

    private static function exerciseWorkerPollSentinel(TestCase $test): void
    {
        Queue::fake();
        self::startWorkflow($test, 'runtime-payload-sentinel-worker', 'runtime-payload-proof');
        self::registerWorker();

        $test->withHeaders(self::workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'runtime-payload-proof-worker',
                'task_queue' => 'runtime-payload-proof',
            ])
            ->assertOk();
    }

    private static function exerciseStreamListSentinel(TestCase $test): void
    {
        Queue::fake();
        $start = self::startWorkflow($test, 'runtime-payload-sentinel-stream');

        $test->withHeaders(self::controlHeaders())->getJson(
            '/api/workflows/runtime-payload-sentinel-stream/runs/'
                .$start->json('run_id').'/streams',
        )->assertOk();
    }

    private static function configureStorage(): void
    {
        $directory = self::storageDirectory();
        File::deleteDirectory($directory);
        File::ensureDirectoryExists($directory);
        WorkflowNamespace::query()->where('name', 'default')->update([
            'external_payload_storage' => [
                'driver' => 'local',
                'enabled' => true,
                'threshold_bytes' => 32,
                'config' => ['uri' => 'file://'.$directory],
            ],
        ]);
    }

    /**
     * @return array{schema: string, reference_id: string, codec: string, size_bytes: int, sha256: string}
     */
    private static function seed(string $value, bool $corrupt = false): array
    {
        $payload = Serializer::serializeWithCodec('avro', [$value]);

        return self::seedPayload($payload, $corrupt);
    }

    /**
     * @return array{schema: string, reference_id: string, codec: string, size_bytes: int, sha256: string}
     */
    private static function seedPayload(string $payload, bool $corrupt = false): array
    {
        $sha256 = hash('sha256', $payload);
        $path = self::storageDirectory().'/avro/'.substr($sha256, 0, 2).'/'.$sha256;
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $corrupt ? 'corrupt' : $payload);
        $referenceId = 'ep_01ARZ3NDEKTSV4RRFFQ69G5FAV';
        $uri = 'file://'.$path;

        RuntimeExternalPayload::query()->create([
            'id' => $referenceId,
            'namespace' => 'default',
            'storage_uri' => $uri,
            'storage_uri_sha256' => hash('sha256', $uri),
            'codec' => 'avro',
            'sha256' => $sha256,
            'size_bytes' => strlen($payload),
            'retained_at' => now(),
            'expires_at' => null,
        ]);

        return [
            'schema' => RuntimeExternalPayloadReference::SCHEMA,
            'reference_id' => $referenceId,
            'codec' => 'avro',
            'size_bytes' => strlen($payload),
            'sha256' => $sha256,
        ];
    }

    private static function storageDirectory(): string
    {
        return storage_path('framework/testing/runtime-external-payload-proof');
    }

    private static function startWorkflow(
        TestCase $test,
        string $workflowId,
        string $taskQueue = 'default',
    ): mixed {
        return $test->withHeaders(self::controlHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => $workflowId,
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => $taskQueue,
            ])
            ->assertCreated();
    }

    private static function registerWorker(): void
    {
        WorkerRegistration::query()->updateOrCreate(
            ['worker_id' => 'runtime-payload-proof-worker', 'namespace' => 'default'],
            [
                'task_queue' => 'runtime-payload-proof',
                'runtime' => 'php',
                'supported_workflow_types' => ['tests.external-greeting-workflow'],
                'supported_activity_types' => [],
                'capabilities' => ['memo_upserts'],
                'last_heartbeat_at' => now(),
                'status' => 'active',
            ],
        );
    }

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
    private static function controlHeaders(): array
    {
        return self::apiHeaders();
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
