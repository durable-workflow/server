<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WorkflowDurableStream;
use App\Models\WorkflowDurableStreamItem;
use App\Models\WorkflowNamespace;
use App\Support\RuntimeExternalPayloadRegistry;
use App\Support\WorkflowStreamCommandProcessor;
use App\Support\WorkflowStreamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

class WorkflowStreamsTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createNamespace('default');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('framework/testing/workflow-stream-runtime-payloads'));

        parent::tearDown();
    }

    public function test_cluster_info_publishes_workflow_streams_contract(): void
    {
        $response = $this->getJson('/api/cluster/info');

        $response->assertOk()
            ->assertJsonPath('capabilities.workflow_streams', true)
            ->assertJsonPath('workflow_streams_contract.schema', 'durable-workflow.v2.workflow-streams.contract')
            ->assertJsonPath('workflow_streams_contract.version', 1)
            ->assertJsonPath('workflow_streams_contract.cluster_info_key', 'workflow_streams_contract')
            ->assertJsonPath('workflow_streams_contract.capability_flag', 'workflow_streams')
            ->assertJsonPath(
                'workflow_streams_contract.parity_target.name',
                'Workflow Streams',
            )
            ->assertJsonPath(
                'workflow_streams_contract.ordering_guarantees.first_offset',
                0,
            )
            ->assertJsonPath(
                'workflow_streams_contract.ordering_guarantees.duplicate_handling',
                'idempotency_key_dedupes_within_stream',
            )
            ->assertJsonPath(
                'workflow_streams_contract.backpressure_semantics.producer_throttle_outcome',
                'append_returns_429_with_reason_stream_full_when_pending_items_threshold_exceeded',
            )
            ->assertJsonPath(
                'workflow_streams_contract.workflow_authoring.command_boundary',
                'record_side_effect.workflow_stream',
            )
            ->assertJsonPath(
                'workflow_streams_contract.message_stream_relationship.service_mode_inbound_workflow_messaging',
                false,
            )
            ->assertJsonPath(
                'workflow_streams_contract.first_party_sdk_support.python.external_payload_references',
                'opaque-reference',
            );
    }

    public function test_append_assigns_monotonically_increasing_offsets(): void
    {
        $run = $this->createRun('default', 'wf-streams-append');

        $response = $this->withHeaders($this->apiHeaders())
            ->postJson($this->itemsRoute('wf-streams-append', $run->id, 'tokens'), [
                'items' => [
                    ['payload' => ['t' => 'hello']],
                    ['payload' => ['t' => 'world']],
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('accepted', 2)
            ->assertJsonPath('deduped', 0)
            ->assertJsonPath('accepted_offsets.0', 0)
            ->assertJsonPath('accepted_offsets.1', 1)
            ->assertJsonPath('stream.last_offset', 1)
            ->assertJsonPath('stream.total_items', 2)
            ->assertJsonPath('stream.status', 'open');

        $second = $this->withHeaders($this->apiHeaders())
            ->postJson($this->itemsRoute('wf-streams-append', $run->id, 'tokens'), [
                'items' => [
                    ['payload' => ['t' => '!']],
                ],
            ]);

        $second->assertOk()
            ->assertJsonPath('accepted_offsets.0', 2)
            ->assertJsonPath('stream.last_offset', 2)
            ->assertJsonPath('stream.total_items', 3);
    }

    public function test_append_rejects_json_tagged_payloads_before_persistence(): void
    {
        $run = $this->createRun('default', 'wf-streams-json-codec');

        $response = $this->withHeaders($this->apiHeaders())
            ->postJson($this->itemsRoute('wf-streams-json-codec', $run->id, 'tokens'), [
                'items' => [[
                    'payload' => ['stale' => true],
                    'payload_codec' => 'json',
                ]],
            ])
            ->assertUnprocessable();

        $message = $response->json('errors')['items.0.payload_codec'][0] ?? '';
        $this->assertStringContainsString('unsupported_payload_codec', $message);
        $this->assertStringContainsString('HTTP document transport', $message);

        $this->assertDatabaseCount('workflow_durable_stream_items', 0);
    }

    public function test_subscriber_reconnects_with_from_offset_without_loss(): void
    {
        $run = $this->createRun('default', 'wf-streams-reconnect');

        $this->postItems($run->id, 'tokens', [
            ['payload' => ['n' => 1]],
            ['payload' => ['n' => 2]],
            ['payload' => ['n' => 3]],
            ['payload' => ['n' => 4]],
            ['payload' => ['n' => 5]],
        ], 'wf-streams-reconnect');

        // First read window picks up 0..2.
        $first = $this->withHeaders($this->apiHeaders())
            ->getJson($this->itemsRoute('wf-streams-reconnect', $run->id, 'tokens').'?from=0&max_items=3');

        $first->assertOk()
            ->assertJsonPath('items.0.offset', 0)
            ->assertJsonPath('items.2.offset', 2)
            ->assertJsonPath('next_offset', 3)
            ->assertJsonPath('terminal', false);

        // Subscriber crashes, then reconnects with from=3 and reads
        // the rest. No items are missed; no items are duplicated.
        $second = $this->withHeaders($this->apiHeaders())
            ->getJson($this->itemsRoute('wf-streams-reconnect', $run->id, 'tokens').'?from=3&max_items=10');

        $second->assertOk()
            ->assertJsonPath('items.0.offset', 3)
            ->assertJsonPath('items.1.offset', 4)
            ->assertJsonPath('next_offset', 5);

        $this->assertCount(2, $second->json('items'));
    }

    public function test_idempotency_key_collapses_retried_appends(): void
    {
        $run = $this->createRun('default', 'wf-streams-idem');

        $first = $this->postItems($run->id, 'tokens', [
            ['payload' => ['t' => 'a'], 'idempotency_key' => 'k-1'],
            ['payload' => ['t' => 'b'], 'idempotency_key' => 'k-2'],
        ], 'wf-streams-idem');

        $first->assertOk()->assertJsonPath('accepted', 2);

        // Retry with overlapping keys: the first two collapse, only
        // the third allocates a new offset.
        $retry = $this->postItems($run->id, 'tokens', [
            ['payload' => ['t' => 'a'], 'idempotency_key' => 'k-1'],
            ['payload' => ['t' => 'b'], 'idempotency_key' => 'k-2'],
            ['payload' => ['t' => 'c'], 'idempotency_key' => 'k-3'],
        ], 'wf-streams-idem');

        $retry->assertOk()
            ->assertJsonPath('accepted', 1)
            ->assertJsonPath('deduped', 2)
            ->assertJsonPath('stream.total_items', 3)
            ->assertJsonPath('stream.last_offset', 2);

        // Verify only three rows landed despite five append attempts.
        $this->assertSame(
            3,
            WorkflowDurableStreamItem::query()
                ->where('workflow_run_id', $run->id)
                ->count(),
        );
    }

    public function test_pending_items_cap_returns_429_stream_full(): void
    {
        $run = $this->createRun('default', 'wf-streams-cap');

        $response = $this->withHeaders($this->apiHeaders())
            ->postJson($this->itemsRoute('wf-streams-cap', $run->id, 'tokens'), [
                'max_pending_items' => 2,
                'items' => [
                    ['payload' => ['n' => 1]],
                    ['payload' => ['n' => 2]],
                    ['payload' => ['n' => 3]],
                ],
            ]);

        $response->assertStatus(429)
            ->assertJsonPath('reason', 'stream_full')
            ->assertJsonPath('max_pending_items', 2);

        // The first two items did land before the cap kicked in;
        // ordering is preserved.
        $this->assertSame(
            2,
            WorkflowDurableStreamItem::query()
                ->where('workflow_run_id', $run->id)
                ->count(),
        );
    }

    public function test_close_marks_stream_terminal_and_rejects_further_appends(): void
    {
        $run = $this->createRun('default', 'wf-streams-close');

        $this->postItems($run->id, 'tokens', [
            ['payload' => ['n' => 1]],
        ], 'wf-streams-close');

        $close = $this->withHeaders($this->apiHeaders())
            ->postJson($this->closeRoute('wf-streams-close', $run->id, 'tokens'), []);

        $close->assertOk()
            ->assertJsonPath('stream.status', 'closed')
            ->assertJsonPath('stream.last_offset', 0);

        $rejected = $this->withHeaders($this->apiHeaders())
            ->postJson($this->itemsRoute('wf-streams-close', $run->id, 'tokens'), [
                'items' => [['payload' => ['n' => 2]]],
            ]);

        $rejected->assertStatus(409)
            ->assertJsonPath('reason', 'stream_closed');
    }

    public function test_close_with_error_reason_marks_stream_errored(): void
    {
        $run = $this->createRun('default', 'wf-streams-error');

        $this->postItems($run->id, 'tokens', [
            ['payload' => ['n' => 1]],
        ], 'wf-streams-error');

        $close = $this->withHeaders($this->apiHeaders())
            ->postJson($this->closeRoute('wf-streams-error', $run->id, 'tokens'), [
                'error_reason' => 'producer_failed',
            ]);

        $close->assertOk()
            ->assertJsonPath('stream.status', 'errored')
            ->assertJsonPath('stream.error_reason', 'producer_failed');
    }

    public function test_subscribe_after_close_reports_terminal_when_caught_up(): void
    {
        $run = $this->createRun('default', 'wf-streams-terminal');

        $this->postItems($run->id, 'tokens', [
            ['payload' => ['n' => 1]],
            ['payload' => ['n' => 2]],
        ], 'wf-streams-terminal');

        $this->withHeaders($this->apiHeaders())
            ->postJson($this->closeRoute('wf-streams-terminal', $run->id, 'tokens'), [])
            ->assertOk();

        // Reading the tail returns terminal=true and zero items.
        $tail = $this->withHeaders($this->apiHeaders())
            ->getJson($this->itemsRoute('wf-streams-terminal', $run->id, 'tokens').'?from=2');

        $tail->assertOk()
            ->assertJsonPath('terminal', true)
            ->assertJsonCount(0, 'items')
            ->assertJsonPath('next_offset', 2);

        // But reading from earlier still surfaces the persisted history.
        $body = $this->withHeaders($this->apiHeaders())
            ->getJson($this->itemsRoute('wf-streams-terminal', $run->id, 'tokens').'?from=0');

        $body->assertOk()
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.offset', 0)
            ->assertJsonPath('items.1.offset', 1);
    }

    public function test_index_lists_open_streams_with_last_offset(): void
    {
        $run = $this->createRun('default', 'wf-streams-index');

        $this->postItems($run->id, 'tokens', [['payload' => ['t' => 'a']]], 'wf-streams-index');
        $this->postItems($run->id, 'progress', [
            ['payload' => ['p' => 0]],
            ['payload' => ['p' => 1]],
        ], 'wf-streams-index');

        $response = $this->withHeaders($this->apiHeaders())
            ->getJson(sprintf(
                '/api/workflows/%s/runs/%s/streams',
                'wf-streams-index',
                $run->id,
            ));

        $response->assertOk()
            ->assertJsonPath('count', 2)
            ->assertJsonPath('streams.0.stream_name', 'progress')
            ->assertJsonPath('streams.0.last_offset', 1)
            ->assertJsonPath('streams.1.stream_name', 'tokens')
            ->assertJsonPath('streams.1.last_offset', 0);
    }

    public function test_append_to_unknown_run_returns_instance_not_found(): void
    {
        $response = $this->withHeaders($this->apiHeaders())
            ->postJson($this->itemsRoute('wf-missing', '01HZZZZZZZZZZZZZZZZZZZZZZZ', 'tokens'), [
                'items' => [['payload' => ['n' => 1]]],
            ]);

        $response->assertNotFound()
            ->assertJsonPath('reason', 'instance_not_found');
    }

    public function test_invalid_stream_name_is_rejected_at_service_layer(): void
    {
        // Reach the service directly so we exercise the stream-name
        // pattern without relying on the routing layer's own URL
        // segment rules. The wire-level invalid name (slashes in the
        // path, etc.) is a routing-level 404 that callers see uniformly.
        $run = $this->createRun('default', 'wf-streams-name');
        $service = app(WorkflowStreamService::class);

        $this->expectException(\InvalidArgumentException::class);

        $service->append($run, 'default', 'has spaces and !', [
            ['payload' => ['n' => 1]],
        ]);
    }

    public function test_subscribe_max_items_caps_response_window(): void
    {
        $run = $this->createRun('default', 'wf-streams-window');

        $batch = [];
        for ($i = 0; $i < 5; $i++) {
            $batch[] = ['payload' => ['n' => $i]];
        }

        $this->postItems($run->id, 'tokens', $batch, 'wf-streams-window');

        $response = $this->withHeaders($this->apiHeaders())
            ->getJson($this->itemsRoute('wf-streams-window', $run->id, 'tokens').'?from=0&max_items=2');

        $response->assertOk()
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('next_offset', 2);
    }

    public function test_describe_returns_lifecycle_metadata(): void
    {
        $run = $this->createRun('default', 'wf-streams-describe');

        $this->postItems($run->id, 'tokens', [['payload' => ['n' => 1]]], 'wf-streams-describe');

        $response = $this->withHeaders($this->apiHeaders())
            ->getJson(sprintf(
                '/api/workflows/%s/runs/%s/streams/%s',
                'wf-streams-describe',
                $run->id,
                'tokens',
            ));

        $response->assertOk()
            ->assertJsonPath('stream.stream_name', 'tokens')
            ->assertJsonPath('stream.status', 'open')
            ->assertJsonPath('stream.last_offset', 0)
            ->assertJsonPath('stream.total_items', 1);
    }

    public function test_service_layer_at_least_once_durability(): void
    {
        // Direct-service contract test: the durability semantics the
        // wire surface promises — items are persisted before the
        // append call returns, and the offset is reused on retry —
        // are encoded in the service rather than the controller.
        $run = $this->createRun('default', 'wf-streams-svc');
        $service = app(WorkflowStreamService::class);

        $service->append($run, 'default', 'tokens', [
            ['payload' => ['t' => 'a'], 'idempotency_key' => 'k-1'],
        ]);

        $service->append($run, 'default', 'tokens', [
            ['payload' => ['t' => 'a'], 'idempotency_key' => 'k-1'],
            ['payload' => ['t' => 'b'], 'idempotency_key' => 'k-2'],
        ]);

        $stream = WorkflowDurableStream::query()
            ->where('workflow_run_id', $run->id)
            ->where('stream_name', 'tokens')
            ->firstOrFail();

        $this->assertSame(2, (int) $stream->total_items);
        $this->assertSame(1, (int) $stream->last_offset);

        // A retry-only append (no new items) is a no-op.
        $service->append($run, 'default', 'tokens', [
            ['payload' => ['t' => 'a'], 'idempotency_key' => 'k-1'],
        ]);

        $stream->refresh();
        $this->assertSame(2, (int) $stream->total_items);
        $this->assertSame(1, (int) $stream->last_offset);
    }

    public function test_workflow_command_directive_derives_replay_safe_durable_records(): void
    {
        $run = $this->createRun('default', 'wf-streams-command');
        $task = WorkflowTask::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'namespace' => 'default',
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'queue' => 'default',
            'payload' => ['workflow_command_id' => 'command-stream-1'],
            'available_at' => now(),
        ]);
        $processor = app(WorkflowStreamCommandProcessor::class);
        $commands = [[
            'type' => 'record_side_effect',
            'result' => ['codec' => 'avro', 'blob' => 'recorded-null'],
            'workflow_stream' => [
                'operation' => 'append',
                'stream_name' => 'tokens',
                'command_identity' => 'command-stream-1',
                'command_ordinal' => 0,
                'items' => [[
                    'payload' => ['codec' => 'avro', 'blob' => 'item-one'],
                    'payload_codec' => 'avro',
                    'idempotency_key' => 'dw-stream:command-stream-1:0:0',
                ]],
            ],
        ]];

        $forwarded = $processor->process($task->id, 'default', $commands);
        $processor->process($task->id, 'default', $commands);

        $this->assertArrayNotHasKey('workflow_stream', $forwarded[0]);
        $this->assertDatabaseCount('workflow_durable_stream_items', 1);
        $item = WorkflowDurableStreamItem::query()->firstOrFail();
        $this->assertSame(0, (int) $item->offset);
        $this->assertSame('dw-stream:command-stream-1:0:0', $item->idempotency_key);
        $this->assertSame('workflow_command', $item->origin);
        $this->assertSame('command-stream-1', $item->origin_reference);
    }

    public function test_worker_completion_commits_stream_append_with_side_effect_history(): void
    {
        config()->set('workflows.v2.types.workflows', [
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);
        $start = $this->withHeaders($this->apiHeaders())->postJson('/api/workflows', [
            'workflow_id' => 'wf-stream-command-completion',
            'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'stream-command-queue',
            'input' => [
                'codec' => 'avro',
                'blob' => Serializer::serializeWithCodec('avro', ['Ada']),
            ],
        ]);
        $start->assertCreated();
        $this->registerWorker(
            'stream-command-worker',
            'stream-command-queue',
            supportedWorkflowTypes: ['tests.external-greeting-workflow'],
        );
        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'stream-command-worker',
                'task_queue' => 'stream-command-queue',
            ]);
        $poll->assertOk();
        $taskId = (string) $poll->json('task.task_id');
        $commandIdentity = (string) ($poll->json('task.workflow_command_id') ?: $taskId);

        $payloadDirectory = storage_path('framework/testing/workflow-stream-runtime-payloads');
        WorkflowNamespace::query()->where('name', 'default')->update([
            'external_payload_storage' => [
                'driver' => 'local',
                'enabled' => true,
                'threshold_bytes' => 32,
                'config' => ['uri' => 'file://'.$payloadDirectory],
            ],
        ]);
        $streamPayload = Serializer::serializeWithCodec('avro', ['token-1']);
        $streamReference = app(RuntimeExternalPayloadRegistry::class)->upload(
            'default',
            $streamPayload,
            'avro',
            hash('sha256', $streamPayload),
        );

        $complete = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => 'stream-command-worker',
                'workflow_task_attempt' => (int) $poll->json('task.workflow_task_attempt'),
                'commands' => [[
                    'type' => 'record_side_effect',
                    'result' => Serializer::serializeWithCodec('avro', null),
                    'workflow_stream' => [
                        'operation' => 'append',
                        'stream_name' => 'tokens',
                        'command_identity' => $commandIdentity,
                        'command_ordinal' => 0,
                        'items' => [[
                            'payload_reference' => $streamReference,
                            'payload_codec' => 'avro',
                            'idempotency_key' => "dw-stream:{$commandIdentity}:0:0",
                        ]],
                    ],
                ]],
            ]);

        $complete->assertOk()->assertJsonPath('recorded', true);
        $this->assertDatabaseCount('workflow_durable_stream_items', 1);
        $this->assertSame(1, WorkflowHistoryEvent::query()
            ->where('workflow_run_id', (string) $start->json('run_id'))
            ->where('event_type', 'SideEffectRecorded')
            ->count());
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function postItems(string $runId, string $streamName, array $items, string $workflowId)
    {
        return $this->withHeaders($this->apiHeaders())
            ->postJson($this->itemsRoute($workflowId, $runId, $streamName), [
                'items' => $items,
            ])
            ->assertOk();
    }

    private function itemsRoute(string $workflowId, string $runId, string $streamName): string
    {
        return sprintf(
            '/api/workflows/%s/runs/%s/streams/%s/items',
            $workflowId,
            $runId,
            $streamName,
        );
    }

    private function closeRoute(string $workflowId, string $runId, string $streamName): string
    {
        return sprintf(
            '/api/workflows/%s/runs/%s/streams/%s/close',
            $workflowId,
            $runId,
            $streamName,
        );
    }

    private function createRun(string $namespace, string $workflowId): WorkflowRun
    {
        $instance = WorkflowInstance::query()->create([
            'id' => $workflowId,
            'workflow_class' => 'Tests\\Fixtures\\StreamingWorkflow',
            'workflow_type' => 'tests.streaming-workflow',
            'namespace' => $namespace,
            'run_count' => 1,
            'started_at' => now(),
        ]);

        $run = WorkflowRun::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'Tests\\Fixtures\\StreamingWorkflow',
            'workflow_type' => 'tests.streaming-workflow',
            'namespace' => $namespace,
            'status' => RunStatus::Running->value,
            'started_at' => now(),
            'last_progress_at' => now(),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        return $run;
    }
}
