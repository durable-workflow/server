<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\RuntimeSignalControlPlane;
use App\Models\RuntimeExternalPayload;
use App\Models\WorkflowInboundStream;
use App\Models\WorkflowInboundStreamItem;
use App\Models\WorkflowNamespace;
use App\Support\ExternalPayloadRetentionCleanup;
use App\Support\MessageStreamsContract;
use App\Support\MessageStreamService;
use App\Support\RuntimeExternalPayloadRegistry;
use App\Support\ServerWorkflowControlPlane;
use App\Support\WorkerProtocol;
use App\Support\WorkflowTaskPoller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Symfony\Component\Yaml\Yaml;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\Serializers\AvroBinaryValue;
use Workflow\Serializers\AvroMapValue;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ExternalPayloadReference;
use Workflow\V2\Support\ExternalPayloads;

class MessageStreamsTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        WorkflowNamespace::query()->create([
            'name' => 'default',
            'description' => 'Default namespace',
            'retention_days' => 30,
            'status' => 'active',
        ]);
    }

    public function test_cluster_info_publishes_the_language_neutral_message_stream_contract(): void
    {
        $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('capabilities.message_streams', true)
            ->assertJsonPath('message_streams_contract.schema', 'durable-workflow.v2.message-streams.contract')
            ->assertJsonPath('message_streams_contract.version', 1)
            ->assertJsonPath('message_streams_contract.identity.order_key', 'position')
            ->assertJsonPath('message_streams_contract.cursor.history_event', 'MessageCursorAdvanced')
            ->assertJsonPath('message_streams_contract.batch.maximum', 100)
            ->assertJsonPath('message_streams_contract.sdk_handoffs.php', 'WorkflowContext::messageStream(string)->receive(int)')
            ->assertJsonPath('message_streams_contract.conformance.runtimes.2', 'rust');
    }

    public function test_control_and_worker_protocol_documents_publish_message_stream_surfaces(): void
    {
        $root = dirname(__DIR__, 2).'/resources/platform-protocol-specs';
        $control = Yaml::parseFile($root.'/control-plane-api.openapi.yaml');
        $worker = Yaml::parseFile($root.'/worker-protocol-api.openapi.yaml');

        $this->assertSame(
            'appendWorkflowMessageStreamMessage',
            $control['paths']['/workflows/{workflowId}/message-streams/{streamName}/messages']['post']['operationId'],
        );
        $this->assertSame(
            '1.15',
            $worker['x-durable-workflow-message-streams-contract']['minimum_protocol_version'],
        );
        $this->assertSame(
            '#/components/schemas/MessageStreamCursorAdvance',
            $worker['components']['schemas']['WorkflowTaskCompleteRequest']['properties']['message_stream_cursors']['items']['$ref'],
        );
    }

    public function test_append_assigns_ordered_positions_and_deduplicates_by_message_identity(): void
    {
        $run = $this->createRun('wf-message-order');
        $this->acceptInternalSignals($run);

        $first = $this->postMessage('wf-message-order', 'orders', 'message-1', [['order' => 1]])
            ->assertStatus(202)
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('duplicate', false)
            ->assertJsonPath('position', 1);

        $this->postMessage('wf-message-order', 'orders', 'message-2', [['order' => 2]])
            ->assertStatus(202)
            ->assertJsonPath('position', 2);

        $this->postMessage('wf-message-order', 'orders', 'message-1', [['order' => 1]])
            ->assertOk()
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('duplicate', true)
            ->assertJsonPath('position', 1);

        $this->postMessage('wf-message-order', 'orders', 'message-1', [['order' => 999]])
            ->assertConflict()
            ->assertJsonPath('accepted', false)
            ->assertJsonPath('reason', 'message_identity_conflict');

        $this->assertDatabaseCount('workflow_inbound_stream_items', 2);
        $this->assertSame(
            [1, 2],
            WorkflowInboundStreamItem::query()->orderBy('position')->pluck('position')->all(),
        );

        $diagnostics = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-message-order/message-streams/orders')
            ->assertOk()
            ->assertJsonPath('stream.stream_name', 'orders')
            ->assertJsonPath('stream.last_position', 2)
            ->assertJsonPath('stream.cursor_position', 0)
            ->assertJsonPath('stream.pending_count', 2)
            ->assertJsonPath('stream.duplicate_count', 2)
            ->assertJsonPath('stream.last_input_outcome', 'message_identity_conflict');

        $diagnostics->assertJsonMissingPath('stream.arguments');
        $diagnostics->assertJsonMissingPath('stream.payload');
        $first->assertJsonMissingPath('input');
    }

    public function test_append_persists_and_redelivers_the_original_lossless_avro_envelope(): void
    {
        $run = $this->createRun('wf-message-lossless');
        $delivered = null;
        $this->mock(RuntimeSignalControlPlane::class, function (MockInterface $mock) use ($run, &$delivered): void {
            $mock->shouldReceive('runtimeSignal')->andReturnUsing(
                static function (string $workflowId, string $signalName, array $options) use ($run, &$delivered): array {
                    $delivered = compact('workflowId', 'signalName', 'options');

                    return [
                        'accepted' => true,
                        'workflow_command_id' => (string) Str::ulid(),
                        'run_id' => $run->id,
                        'status' => 202,
                    ];
                },
            );
        });
        $this->app->forgetInstance(MessageStreamService::class);

        $arguments = [[
            'bytes' => AvroBinaryValue::fromBytes("\x00\xFF"),
            'long' => 1,
            'double' => 1.0,
            'empty_list' => [],
            'empty_map' => AvroMapValue::fromPairs([]),
            'nested' => [['empty_map' => AvroMapValue::fromPairs([])]],
        ]];
        $blob = Serializer::serializeWithCodec('avro', $arguments);

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-message-lossless/message-streams/orders/messages', [
                'message_id' => 'lossless-1',
                'input' => ['codec' => 'avro', 'blob' => $blob],
            ])
            ->assertStatus(202);

        $item = WorkflowInboundStreamItem::query()->firstOrFail();
        $this->assertSame('avro', $item->payload_codec);
        $this->assertSame($blob, $item->payload_blob);
        $this->assertSame('__durable_workflow_message_stream', $delivered['signalName'] ?? null);
        $this->assertArrayNotHasKey('runtime_reserved_signal', $delivered['options'] ?? []);
        $outer = Serializer::unserializeWithCodec(
            (string) $delivered['options']['payload_codec'],
            (string) $delivered['options']['payload_blob'],
        );
        $this->assertSame('avro', $outer[0]['payload_envelope']['codec'] ?? null);
        $this->assertSame($blob, $outer[0]['payload_envelope']['blob'] ?? null);
    }

    public function test_external_payload_reference_is_not_copied_into_stream_storage_and_survives_redelivery(): void
    {
        $run = $this->createRun('wf-message-external');
        $deliveries = [];
        $this->mock(RuntimeSignalControlPlane::class, function (MockInterface $mock) use ($run, &$deliveries): void {
            $mock->shouldReceive('runtimeSignal')->andReturnUsing(
                static function (string $workflowId, string $signalName, array $options) use ($run, &$deliveries): array {
                    $deliveries[] = [
                        'codec' => (string) $options['payload_codec'],
                        'blob' => (string) $options['payload_blob'],
                        'arguments' => Serializer::unserializeWithCodec(
                            (string) $options['payload_codec'],
                            (string) $options['payload_blob'],
                        ),
                    ];

                    return [
                        'accepted' => true,
                        'workflow_command_id' => (string) Str::ulid(),
                        'run_id' => $run->id,
                        'status' => 202,
                    ];
                },
            );
        });
        $this->app->forgetInstance(MessageStreamService::class);

        $storageDirectory = storage_path('framework/testing/message-stream-external');
        File::deleteDirectory($storageDirectory);
        File::ensureDirectoryExists($storageDirectory);
        WorkflowNamespace::query()->where('name', 'default')->firstOrFail()->forceFill([
            'external_payload_storage' => [
                'driver' => 'local',
                'enabled' => true,
                'config' => ['uri' => 'file://'.$storageDirectory],
            ],
        ])->save();

        $blob = Serializer::serializeWithCodec('avro', [['secret' => str_repeat('private-', 256)]]);
        $reference = app(RuntimeExternalPayloadRegistry::class)->upload(
            'default',
            $blob,
            'avro',
            hash('sha256', $blob),
        );
        $runtimePayload = RuntimeExternalPayload::query()->findOrFail($reference['reference_id']);
        $path = rawurldecode(substr($runtimePayload->storage_uri, strlen('file://')));

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-message-external/message-streams/orders/messages', [
                'message_id' => 'external-1',
                'input' => ['codec' => 'avro', 'external_payload' => $reference],
            ])
            ->assertStatus(202);

        $item = WorkflowInboundStreamItem::query()->firstOrFail();
        $this->assertStringStartsWith(ExternalPayloads::STORED_REFERENCE_PREFIX, $item->payload_blob);
        $this->assertStringNotContainsString(base64_encode($blob), $item->payload_blob);
        $storedReference = ExternalPayloads::storedEnvelope($item->payload_blob)['external_storage'];
        $this->assertSame(ExternalPayloadReference::SCHEMA, $storedReference['schema']);
        $this->assertSame($runtimePayload->storage_uri, $storedReference['uri']);
        $this->assertArrayNotHasKey('reference_id', $storedReference);
        $this->assertSame('avro', $deliveries[0]['arguments'][0]['payload_envelope']['codec']);
        $this->assertEquals(
            $storedReference,
            $deliveries[0]['arguments'][0]['payload_envelope']['external_storage'],
        );
        $this->assertArrayNotHasKey('blob', $deliveries[0]['arguments'][0]['payload_envelope']);

        $workerEvents = app(WorkflowTaskPoller::class)->historyEventsWithSignalArguments([[
            'event_type' => HistoryEventType::SignalReceived->value,
            'payload' => [
                'signal_name' => MessageStreamsContract::INTERNAL_SIGNAL,
                'payload_codec' => $deliveries[0]['codec'],
                'arguments' => [
                    'codec' => $deliveries[0]['codec'],
                    'blob' => $deliveries[0]['blob'],
                ],
            ],
        ]], 'default', 'avro');
        $workerArguments = Serializer::unserializeWithCodec(
            (string) $workerEvents[0]['payload']['arguments']['codec'],
            (string) $workerEvents[0]['payload']['arguments']['blob'],
        );
        $this->assertSame('avro', $workerArguments[0]['payload_envelope']['codec']);
        $this->assertSame($blob, $workerArguments[0]['payload_envelope']['blob']);
        $this->assertArrayNotHasKey('external_storage', $workerArguments[0]['payload_envelope']);

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-message-external/message-streams/orders/messages', [
                'message_id' => 'external-1',
                'input' => ['codec' => 'avro', 'external_payload' => $reference],
            ])
            ->assertOk()
            ->assertJsonPath('duplicate', true);
        $this->assertCount(1, $deliveries);

        $task = $this->createLeasedTask($run);
        $nextRun = $this->createContinuedRun($run);
        app(MessageStreamService::class)->recordCompletion(
            'default',
            $task->id,
            [],
            [],
            ['completed' => true],
        );
        $this->assertCount(2, $deliveries);
        $this->assertEquals(
            $storedReference,
            $deliveries[1]['arguments'][0]['payload_envelope']['external_storage'],
        );
        $this->assertSame($nextRun->id, $item->fresh()->delivered_run_id);

        $cleanup = app(ExternalPayloadRetentionCleanup::class)->deleteForNamespaceCleanup(
            'default',
            [$run->id, $nextRun->id],
            ['wf-message-external'],
        );
        $this->assertSame(1, $cleanup['deleted']);
        $this->assertFileDoesNotExist($path);
        File::deleteDirectory($storageDirectory);
    }

    public function test_generic_signal_ingress_cannot_forge_the_runtime_transport(): void
    {
        $run = $this->createRun('wf-message-reserved');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-message-reserved/signal/__durable_workflow_message_stream', [
                'input' => [['schema' => 'forged']],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('accepted', false)
            ->assertJsonPath('reason', 'runtime_reserved_signal');

        $this->withHeaders($this->apiHeaders())
            ->postJson("/api/workflows/wf-message-reserved/runs/{$run->id}/signal/__durable_workflow_message_stream", [
                'input' => [['schema' => 'forged']],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('accepted', false)
            ->assertJsonPath('reason', 'runtime_reserved_signal');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/bridge-adapters/webhook/github', [
                'action' => 'signal_workflow',
                'idempotency_key' => 'reserved-stream-signal',
                'target' => [
                    'workflow_id' => 'wf-message-reserved',
                    'signal_name' => '__durable_workflow_message_stream',
                ],
                'input' => [['schema' => 'forged']],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('accepted', false)
            ->assertJsonPath('reason', 'runtime_reserved_signal');
    }

    public function test_server_control_plane_can_deliver_the_reserved_runtime_transport_internally(): void
    {
        $run = $this->createRun('wf-message-runtime-control-plane');
        $arguments = [[
            'schema' => MessageStreamsContract::CURSOR_SCHEMA,
            'stream_name' => 'orders',
            'through_position' => 0,
        ]];

        $result = app(ServerWorkflowControlPlane::class)->runtimeSignal(
            'wf-message-runtime-control-plane',
            MessageStreamsContract::INTERNAL_SIGNAL,
            [
                'namespace' => 'default',
                'arguments' => $arguments,
                'payload_codec' => 'avro',
                'payload_blob' => Serializer::serializeWithCodec('avro', $arguments),
                'strict_configured_type_validation' => true,
            ],
        );

        $this->assertTrue($result['accepted'] ?? false);
        $this->assertDatabaseHas('workflow_signal_records', [
            'workflow_run_id' => $run->id,
            'signal_name' => MessageStreamsContract::INTERNAL_SIGNAL,
        ]);
        $this->assertDatabaseHas('workflow_history_events', [
            'workflow_run_id' => $run->id,
            'event_type' => HistoryEventType::SignalReceived->value,
        ]);
    }

    public function test_cursor_acknowledgement_is_monotonic_and_history_visible(): void
    {
        $run = $this->createRun('wf-message-cursor');
        $this->acceptInternalSignals($run);
        $this->appendDirect('wf-message-cursor', 'events', 'event-1', [['n' => 1]]);
        $this->appendDirect('wf-message-cursor', 'events', 'event-2', [['n' => 2]]);

        $task = $this->createLeasedTask($run);
        $service = app(MessageStreamService::class);
        $cursors = [['stream_name' => 'events', 'through_position' => 2]];

        $service->validateCompletion('default', $task->id, $cursors, []);
        $service->recordCompletion('default', $task->id, $cursors, [], ['completed' => true]);
        $service->recordCompletion('default', $task->id, $cursors, [], ['completed' => true]);

        $stream = WorkflowInboundStream::query()->where('stream_name', 'events')->firstOrFail();
        $this->assertSame(2, $stream->cursor_position);
        $this->assertSame(2, WorkflowInboundStreamItem::query()->whereNotNull('consumed_at')->count());
        $this->assertSame(1, WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::MessageCursorAdvanced->value)
            ->count());

        $event = WorkflowHistoryEvent::query()
            ->where('event_type', HistoryEventType::MessageCursorAdvanced->value)
            ->firstOrFail();
        $this->assertSame('events', $event->payload['stream_key']);
        $this->assertSame(0, $event->payload['previous_position']);
        $this->assertSame(2, $event->payload['new_position']);
    }

    public function test_pending_wait_is_inspectable_and_append_clears_it(): void
    {
        $run = $this->createRun('wf-message-wait');
        $this->acceptInternalSignals($run);
        $task = $this->createLeasedTask($run);
        $service = app(MessageStreamService::class);
        $waits = [['stream_name' => 'approval', 'after_position' => 0]];

        $service->validateCompletion('default', $task->id, [], $waits);
        $service->recordCompletion('default', $task->id, [], $waits, ['completed' => true]);

        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-message-wait/message-streams/approval')
            ->assertOk()
            ->assertJsonPath('stream.pending_wait.run_id', $run->id)
            ->assertJsonPath('stream.pending_wait.after_position', 0);

        $this->postMessage('wf-message-wait', 'approval', 'approved-1', [true])
            ->assertStatus(202);

        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-message-wait/message-streams/approval')
            ->assertOk()
            ->assertJsonPath('stream.pending_wait', null);
    }

    public function test_pending_wait_cannot_move_behind_the_durable_cursor(): void
    {
        $run = $this->createRun('wf-message-stale-wait');
        $this->acceptInternalSignals($run);
        $this->appendDirect('wf-message-stale-wait', 'approval', 'approved-1', [true]);
        $task = $this->createLeasedTask($run);
        $service = app(MessageStreamService::class);
        $cursors = [['stream_name' => 'approval', 'through_position' => 1]];

        $service->validateCompletion('default', $task->id, $cursors, []);
        $service->recordCompletion('default', $task->id, $cursors, [], ['completed' => true]);

        $this->expectException(ValidationException::class);
        $service->validateCompletion(
            'default',
            $task->id,
            [],
            [['stream_name' => 'approval', 'after_position' => 0]],
        );
    }

    public function test_stream_cursor_metadata_requires_worker_protocol_1_15(): void
    {
        $run = $this->createRun('wf-message-protocol-floor');
        $task = $this->createLeasedTask($run);
        $this->registerWorker('worker-message-stream', 'default');
        $headers = $this->workerHeaders();
        $headers[WorkerProtocol::HEADER] = '1.14';

        $this->postJson("/api/worker/workflow-tasks/{$task->id}/complete", [
            'lease_owner' => 'worker-message-stream',
            'workflow_task_attempt' => 1,
            'commands' => [[
                'type' => 'complete_workflow',
                'result' => Serializer::serializeWithCodec('avro', ['done']),
            ]],
            'message_stream_waits' => [[
                'stream_name' => 'orders',
                'after_position' => 0,
            ]],
        ], $headers)
            ->assertConflict()
            ->assertJsonPath('reason', 'message_streams_unavailable')
            ->assertJsonPath('minimum_protocol_version', '1.15');
    }

    public function test_worker_cannot_advertise_message_streams_with_a_prefeature_protocol(): void
    {
        $headers = $this->workerHeaders();
        $headers[WorkerProtocol::HEADER] = '1.14';

        $this->postJson('/api/worker/register', [
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
            'worker_id' => 'prefeature-stream-worker',
            'task_queue' => 'default',
            'runtime' => 'php',
            'supported_workflow_types' => ['tests.external-greeting-workflow'],
            'capabilities' => [MessageStreamsContract::CAPABILITY],
        ], $headers)
            ->assertConflict()
            ->assertJsonPath('registered', false)
            ->assertJsonPath('reason', 'message_streams_unavailable')
            ->assertJsonPath('minimum_protocol_version', '1.15');

        $this->postJson('/api/worker/register', [
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
            'worker_id' => 'prefeature-one-shot-worker',
            'task_queue' => 'default',
            'runtime' => 'php',
            'supported_workflow_types' => ['tests.external-greeting-workflow'],
            'capabilities' => ['query_tasks'],
        ], $headers)
            ->assertCreated()
            ->assertJsonPath('registered', true);
    }

    public function test_malformed_input_is_counted_without_retaining_payload_contents(): void
    {
        $this->createRun('wf-message-malformed');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-message-malformed/message-streams/orders/messages', [
                'message_id' => 'spaces are invalid',
                'input' => ['secret'],
            ])
            ->assertUnprocessable();

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-message-malformed/message-streams/orders/messages', [
                'message_id' => 'malformed-codec',
                'input' => [
                    'codec' => 'json',
                    'blob_base64' => base64_encode('{}'),
                ],
            ])
            ->assertUnprocessable();

        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-message-malformed/message-streams/orders')
            ->assertOk()
            ->assertJsonPath('stream.malformed_count', 2)
            ->assertJsonPath('stream.last_input_outcome', 'malformed')
            ->assertJsonMissingPath('stream.input')
            ->assertJsonMissingPath('stream.arguments');
    }

    public function test_malformed_message_ids_are_bounded_before_diagnostic_persistence(): void
    {
        $this->createRun('wf-message-id-boundary');
        $overlongId = str_repeat('a', 192);

        foreach ([
            ['message_id' => $overlongId],
            ['message_id' => 'invalid characters'],
            [],
            ['message_id' => ['not-a-string']],
        ] as $payload) {
            $this->withHeaders($this->apiHeaders())
                ->postJson('/api/workflows/wf-message-id-boundary/message-streams/orders/messages', $payload + [
                    'input' => [],
                ])
                ->assertUnprocessable()
                ->assertJsonPath('reason', 'validation_failed');
        }

        $diagnostics = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-message-id-boundary/message-streams/orders')
            ->assertOk()
            ->assertJsonPath('stream.malformed_count', 4)
            ->assertJsonPath('stream.last_input_message_id', '[omitted]');

        $json = json_encode($diagnostics->json(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($overlongId, $json);
    }

    public function test_valid_message_id_at_database_boundary_appends_and_deduplicates(): void
    {
        $run = $this->createRun('wf-message-id-max');
        $this->acceptInternalSignals($run);
        $messageId = str_repeat('a', 191);

        $this->postMessage('wf-message-id-max', 'orders', $messageId, [['order' => 1]])
            ->assertStatus(202)
            ->assertJsonPath('duplicate', false);

        $this->postMessage('wf-message-id-max', 'orders', $messageId, [['order' => 1]])
            ->assertOk()
            ->assertJsonPath('duplicate', true);

        $this->assertDatabaseCount('workflow_inbound_stream_items', 1);
    }

    public function test_continue_as_new_redelivers_only_unconsumed_positions_to_the_current_run(): void
    {
        $firstRun = $this->createRun('wf-message-continue');
        $this->acceptInternalSignals($firstRun);
        $this->appendDirect('wf-message-continue', 'events', 'event-1', [['n' => 1]]);
        $this->appendDirect('wf-message-continue', 'events', 'event-2', [['n' => 2]]);
        $task = $this->createLeasedTask($firstRun);

        $nextRun = $this->createContinuedRun($firstRun);
        $this->acceptInternalSignals($nextRun);

        app(MessageStreamService::class)->recordCompletion(
            'default',
            $task->id,
            [['stream_name' => 'events', 'through_position' => 1]],
            [],
            ['completed' => true],
        );

        $first = WorkflowInboundStreamItem::query()->where('position', 1)->firstOrFail();
        $second = WorkflowInboundStreamItem::query()->where('position', 2)->firstOrFail();
        $this->assertSame($firstRun->id, $first->consumed_run_id);
        $this->assertSame($firstRun->id, $first->delivered_run_id);
        $this->assertSame($nextRun->id, $second->delivered_run_id);
        $this->assertNull($second->consumed_at);
        $this->assertSame(
            $nextRun->id,
            WorkflowInboundStream::query()->where('stream_name', 'events')->value('cursor_checkpoint_run_id'),
        );
    }

    public function test_continue_as_new_redelivers_every_position_across_multiple_chunks_and_streams(): void
    {
        $firstRun = $this->createRun('wf-message-many-continue');
        $task = $this->createLeasedTask($firstRun);
        $blob = Serializer::serializeWithCodec('avro', []);

        foreach (['z-inserted-first', 'a-inserted-second'] as $streamName) {
            $stream = WorkflowInboundStream::query()->create([
                'namespace' => 'default',
                'workflow_instance_id' => 'wf-message-many-continue',
                'stream_name' => $streamName,
                'last_position' => 120,
                'cursor_position' => 0,
            ]);
            foreach (range(1, 120) as $position) {
                WorkflowInboundStreamItem::query()->create([
                    'stream_id' => $stream->id,
                    'namespace' => 'default',
                    'workflow_instance_id' => 'wf-message-many-continue',
                    'stream_name' => $streamName,
                    'message_id' => $streamName.'-'.$position,
                    'position' => $position,
                    'payload_codec' => 'avro',
                    'payload_blob' => $blob,
                    'payload_hash' => hash('sha256', "avro\0".$blob),
                    'delivered_run_id' => $firstRun->id,
                    'delivered_at' => now(),
                ]);
            }
        }

        $nextRun = $this->createContinuedRun($firstRun);
        $delivered = [];
        $this->mock(RuntimeSignalControlPlane::class, function (MockInterface $mock) use ($nextRun, &$delivered): void {
            $mock->shouldReceive('runtimeSignal')->andReturnUsing(
                static function (string $workflowId, string $signalName, array $options) use ($nextRun, &$delivered): array {
                    $arguments = Serializer::unserializeWithCodec(
                        (string) $options['payload_codec'],
                        (string) $options['payload_blob'],
                    );
                    $delivered[] = ($arguments[0]['stream_name'] ?? '').':'.($arguments[0]['position'] ?? 0);

                    return [
                        'accepted' => true,
                        'workflow_command_id' => (string) Str::ulid(),
                        'run_id' => $nextRun->id,
                        'status' => 202,
                    ];
                },
            );
        });
        $this->app->forgetInstance(MessageStreamService::class);

        app(MessageStreamService::class)->recordCompletion(
            'default',
            $task->id,
            [],
            [],
            ['completed' => true],
        );

        $this->assertCount(240, $delivered);
        $this->assertCount(240, array_unique($delivered));
        foreach (['z-inserted-first', 'a-inserted-second'] as $streamName) {
            foreach (range(1, 120) as $position) {
                $this->assertContains($streamName.':'.$position, $delivered);
            }
        }
        $this->assertSame(
            240,
            WorkflowInboundStreamItem::query()->where('delivered_run_id', $nextRun->id)->count(),
        );
    }

    private function acceptInternalSignals(WorkflowRun $run): void
    {
        $this->mock(RuntimeSignalControlPlane::class, function (MockInterface $mock) use ($run): void {
            $mock->shouldReceive('runtimeSignal')->andReturnUsing(static fn (): array => [
                'accepted' => true,
                'workflow_command_id' => (string) Str::ulid(),
                'run_id' => $run->id,
                'status' => 202,
            ]);
        });
        $this->app->forgetInstance(MessageStreamService::class);
    }

    private function appendDirect(string $workflowId, string $streamName, string $messageId, array $arguments): void
    {
        $result = app(MessageStreamService::class)->append(
            'default',
            $workflowId,
            $streamName,
            $messageId,
            'avro',
            $blob = Serializer::serializeWithCodec('avro', $arguments),
            hash('sha256', "avro\0".$blob),
        );
        $this->assertTrue($result['accepted']);
    }

    private function postMessage(string $workflowId, string $streamName, string $messageId, array $input)
    {
        return $this->withHeaders($this->apiHeaders())
            ->postJson("/api/workflows/{$workflowId}/message-streams/{$streamName}/messages", [
                'message_id' => $messageId,
                'input' => $input,
            ]);
    }

    private function createRun(string $workflowId): WorkflowRun
    {
        $instance = WorkflowInstance::query()->create([
            'id' => $workflowId,
            'workflow_class' => 'Tests\\Fixtures\\ExternalGreetingWorkflow',
            'workflow_type' => 'tests.external-greeting-workflow',
            'namespace' => 'default',
            'run_count' => 1,
            'started_at' => now(),
        ]);

        $run = WorkflowRun::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $workflowId,
            'run_number' => 1,
            'workflow_class' => $instance->workflow_class,
            'workflow_type' => $instance->workflow_type,
            'namespace' => 'default',
            'status' => RunStatus::Running->value,
            'started_at' => now(),
            'last_progress_at' => now(),
        ]);

        $instance->forceFill(['current_run_id' => $run->id])->save();

        return $run;
    }

    private function createContinuedRun(WorkflowRun $previous): WorkflowRun
    {
        $run = WorkflowRun::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $previous->workflow_instance_id,
            'run_number' => 2,
            'workflow_class' => $previous->workflow_class,
            'workflow_type' => $previous->workflow_type,
            'namespace' => 'default',
            'status' => RunStatus::Running->value,
            'started_at' => now(),
            'last_progress_at' => now(),
        ]);

        WorkflowInstance::query()->whereKey($previous->workflow_instance_id)->update([
            'current_run_id' => $run->id,
            'run_count' => 2,
        ]);

        return $run;
    }

    private function createLeasedTask(WorkflowRun $run): WorkflowTask
    {
        return WorkflowTask::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'namespace' => 'default',
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'queue' => 'default',
            'lease_owner' => 'worker-message-stream',
            'leased_at' => now(),
            'lease_expires_at' => now()->addMinute(),
            'attempt_count' => 1,
        ]);
    }
}
