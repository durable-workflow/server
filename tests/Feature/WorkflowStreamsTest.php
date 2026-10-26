<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WorkflowDurableStream;
use App\Models\WorkflowDurableStreamItem;
use App\Models\WorkflowNamespace;
use App\Support\HistoryRetentionEnforcer;
use App\Support\NamespaceDurableStateException;
use App\Support\RuntimeExternalPayloadRegistry;
use App\Support\WorkerProtocol;
use App\Support\WorkflowStreamCommandProcessor;
use App\Support\WorkflowStreamRetention;
use App\Support\WorkflowStreamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
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

        // Server #774: the whole batch is refused before any item is
        // written, and the stream this append would have opened is not
        // created, so a plain retry duplicates nothing.
        $this->assertSame(0, WorkflowDurableStreamItem::query()->where('workflow_run_id', $run->id)->count());
        $this->assertSame(0, WorkflowDurableStream::query()->where('workflow_run_id', $run->id)->count());
    }

    public function test_capacity_is_decided_for_the_whole_batch_and_duplicates_take_none(): void
    {
        $run = $this->createRun('default', 'wf-streams-batch-cap');
        $route = $this->itemsRoute('wf-streams-batch-cap', $run->id, 'tokens');
        $append = fn (array $items) => $this->withHeaders($this->apiHeaders())
            ->postJson($route, ['max_pending_items' => 3, 'items' => $items]);

        $append([['payload' => ['n' => 0], 'idempotency_key' => 'k0']])->assertOk()->assertJsonPath('accepted', 1);
        $append([['payload' => ['n' => 1]], ['payload' => ['n' => 2]]])->assertOk()->assertJsonPath('accepted', 2);

        // At the cap: one more fresh item is refused with nothing written.
        $append([['payload' => ['n' => 3]]])->assertStatus(429)->assertJsonPath('pending_items', 3);
        // A batch that repeats a stored key and adds a fresh one is refused
        // whole: the duplicate is not answered and the fresh item not written.
        $append([['payload' => ['n' => 0], 'idempotency_key' => 'k0'], ['payload' => ['n' => 4]]])->assertStatus(429);
        // A batch of duplicates only takes no capacity and answers its offsets.
        $append([['payload' => ['n' => 0], 'idempotency_key' => 'k0'], ['payload' => ['n' => 0], 'idempotency_key' => 'k0']])
            ->assertOk()
            ->assertJsonPath('accepted', 0)
            ->assertJsonPath('deduped', 2)
            ->assertJsonPath('accepted_offsets', [0, 0]);

        $this->assertSame([0, 1, 2], WorkflowDurableStreamItem::query()->where('workflow_run_id', $run->id)
            ->orderBy('offset')->pluck('offset')->map(static fn ($offset): int => (int) $offset)->all());
        $this->assertSame(3, (int) WorkflowDurableStream::query()->where('workflow_run_id', $run->id)->value('pending_items'));
    }

    public function test_producer_controls_are_bounded_at_every_ingress(): void
    {
        $run = $this->createRun('default', 'wf-streams-bounds');
        $items = $this->itemsRoute('wf-streams-bounds', $run->id, 'tokens');

        $this->withHeaders($this->apiHeaders())
            ->postJson($items, ['max_pending_items' => WorkflowStreamService::MAX_PENDING_ITEMS_MAX + 1, 'items' => [['payload' => 1]]])
            ->assertStatus(422)
            ->assertJsonPath('errors.max_pending_items.0', 'The max pending items field must not be greater than 10000.');
        $this->assertSame(0, WorkflowDurableStream::query()->where('workflow_run_id', $run->id)->count());

        $this->withHeaders($this->apiHeaders())
            ->postJson($items, ['max_pending_items' => WorkflowStreamService::MAX_PENDING_ITEMS_MAX, 'items' => [['payload' => 1]]])
            ->assertOk();

        $close = $this->closeRoute('wf-streams-bounds', $run->id, 'tokens');
        $this->withHeaders($this->apiHeaders())
            ->postJson($close, ['retention_seconds' => WorkflowStreamService::RETENTION_SECONDS_MAX + 1])
            ->assertStatus(422)
            ->assertJsonPath('errors.retention_seconds.0', 'The retention seconds field must not be greater than 2592000.');
        $this->assertSame('open', WorkflowDurableStream::query()->where('workflow_run_id', $run->id)->value('status'));

        $this->withHeaders($this->apiHeaders())
            ->postJson($close, ['retention_seconds' => WorkflowStreamService::RETENTION_SECONDS_MAX])
            ->assertOk()
            ->assertJsonPath('stream.retention_seconds', WorkflowStreamService::RETENTION_SECONDS_MAX);

        // The service refuses the same values for callers that skip request
        // validation.
        $streams = app(WorkflowStreamService::class);
        foreach ([0, WorkflowStreamService::MAX_PENDING_ITEMS_MAX + 1] as $cap) {
            try {
                $streams->append($run, 'default', 'other', [['payload' => 1]], $cap);
                $this->fail("cap {$cap} accepted");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $streams->close($run, 'default', 'tokens', null, WorkflowStreamService::RETENTION_SECONDS_MAX + 1);
    }

    public static function oversizedStreamCommands(): array
    {
        return [
            'max_pending_items' => ['max_pending_items', WorkflowStreamService::MAX_PENDING_ITEMS_MAX + 1, 'append'],
            'retention_seconds' => ['retention_seconds', WorkflowStreamService::RETENTION_SECONDS_MAX + 1, 'close'],
        ];
    }

    #[DataProvider('oversizedStreamCommands')]
    public function test_workflow_stream_commands_bound_producer_controls_before_admission(string $field, int $value, string $operation): void
    {
        $task = $this->claimStreamCompletionTask();
        $command = $this->streamCompletionCommand($task);
        $command['workflow_stream'][$field] = $value;
        if ($operation === 'close') {
            $command['workflow_stream']['operation'] = 'close';
            unset($command['workflow_stream']['items']);
        }

        $this->withHeaders($this->workerHeaders())->postJson("/api/worker/workflow-tasks/{$task['task_id']}/complete", [
            'lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'],
            'commands' => [$command],
        ])->assertStatus(422);
        $this->assertDatabaseCount('workflow_durable_streams', 0);
        $this->assertDatabaseMissing('workflow_history_events', ['workflow_run_id' => $task['run_id'], 'event_type' => 'SideEffectRecorded']);
    }

    public function test_an_expired_stream_reads_as_not_found(): void
    {
        $run = $this->createRun('default', 'wf-streams-expiry');
        $this->postItems($run->id, 'tokens', [['payload' => 1]], 'wf-streams-expiry');
        $this->postItems($run->id, 'kept', [['payload' => 1]], 'wf-streams-expiry');
        $this->withHeaders($this->apiHeaders())
            ->postJson($this->closeRoute('wf-streams-expiry', $run->id, 'tokens'), ['retention_seconds' => 60])
            ->assertOk();

        $describe = sprintf('/api/workflows/%s/runs/%s/streams/%s', 'wf-streams-expiry', $run->id, 'tokens');
        $this->withHeaders($this->apiHeaders())->getJson($describe)->assertOk();

        $this->travel(61)->seconds();

        $this->withHeaders($this->apiHeaders())->getJson($describe)->assertNotFound()->assertJsonPath('reason', 'stream_not_found');
        $this->withHeaders($this->apiHeaders())->getJson($this->itemsRoute('wf-streams-expiry', $run->id, 'tokens'))
            ->assertNotFound()->assertJsonPath('reason', 'stream_not_found');
        $this->withHeaders($this->apiHeaders())->postJson($this->closeRoute('wf-streams-expiry', $run->id, 'tokens'), [])
            ->assertNotFound()->assertJsonPath('reason', 'stream_not_found');
        $this->withHeaders($this->apiHeaders())
            ->getJson(sprintf('/api/workflows/%s/runs/%s/streams', 'wf-streams-expiry', $run->id))
            ->assertOk()
            ->assertJsonCount(1, 'streams')
            ->assertJsonPath('streams.0.stream_name', 'kept');
        // Append is not found too, and writes nothing: the terminal row stays
        // until the run is pruned, so the name is never opened again.
        $this->withHeaders($this->apiHeaders())
            ->postJson($this->itemsRoute('wf-streams-expiry', $run->id, 'tokens'), ['items' => [['payload' => 2]]])
            ->assertNotFound()->assertJsonPath('reason', 'stream_not_found');
        $stream = WorkflowDurableStream::query()->where('workflow_run_id', $run->id)->where('stream_name', 'tokens')->sole();
        $this->assertSame(WorkflowDurableStream::STATUS_CLOSED, $stream->status);
        $this->assertSame(1, (int) $stream->total_items);
        $this->assertSame(1, WorkflowDurableStreamItem::query()->where('stream_id', $stream->id)->count());
    }

    public function test_a_stream_expires_at_the_instant_its_retention_ends(): void
    {
        $run = $this->createRun('default', 'wf-streams-expiry-instant');
        $this->postItems($run->id, 'tokens', [['payload' => 1]], 'wf-streams-expiry-instant');
        $this->withHeaders($this->apiHeaders())
            ->postJson($this->closeRoute('wf-streams-expiry-instant', $run->id, 'tokens'), ['retention_seconds' => 60])
            ->assertOk();
        $append = fn () => $this->withHeaders($this->apiHeaders())
            ->postJson($this->itemsRoute('wf-streams-expiry-instant', $run->id, 'tokens'), ['items' => [['payload' => 2]]]);

        $this->travel(59)->seconds();
        $append()->assertStatus(409)->assertJsonPath('reason', 'stream_closed');

        $this->travel(1)->seconds();
        $append()->assertNotFound()->assertJsonPath('reason', 'stream_not_found');
        $this->assertSame(1, WorkflowDurableStreamItem::query()->where('workflow_run_id', $run->id)->count());
    }

    public function test_an_append_that_opens_a_stream_counts_against_the_namespace_stream_quota(): void
    {
        $run = $this->createRun('default', 'wf-streams-quota');
        $streams = app(WorkflowStreamService::class);
        config(['server.namespace_durable_state.overrides' => [
            'default' => ['max_workflow_streams' => 1],
        ]]);

        $streams->append($run, 'default', 'first', [['payload' => 1]]);
        // An existing stream takes no more of the quota.
        $streams->append($run, 'default', 'first', [['payload' => 2]]);

        try {
            $streams->append($run, 'default', 'second', [['payload' => 1]]);
            $this->fail('Expected the namespace stream quota to refuse a second stream.');
        } catch (NamespaceDurableStateException $exception) {
            $this->assertSame('namespace_workflow_streams_exhausted', $exception->reason);
        }

        $this->assertSame(['first'], WorkflowDurableStream::query()->pluck('stream_name')->all());
        $this->assertSame(2, WorkflowDurableStreamItem::query()->count());
    }

    public function test_the_retention_pass_reclaims_expired_streams_in_bounded_resumable_steps(): void
    {
        $run = $this->createRun('default', 'wf-streams-sweep');
        $streams = app(WorkflowStreamService::class);
        $streams->append($run, 'default', 'first', array_map(static fn (int $n): array => ['payload' => $n], range(1, 5)));
        $streams->close($run, 'default', 'first', null, 60);
        $this->travel(1)->seconds();
        $streams->append($run, 'default', 'second', [['payload' => 1], ['payload' => 2]]);
        $streams->close($run, 'default', 'second', 'failed', 60);
        $streams->append($run, 'default', 'long', [['payload' => 1]]);
        $streams->close($run, 'default', 'long', null, 3600);
        $streams->append($run, 'default', 'open', [['payload' => 1]]);
        $this->createNamespace('elsewhere');
        $other = $this->createRun('elsewhere', 'wf-streams-sweep-other');
        $streams->append($other, 'elsewhere', 'foreign', [['payload' => 1]]);
        $streams->close($other, 'elsewhere', 'foreign', null, 60);

        // Nothing is due yet.
        $this->assertSame(['streams_reclaimed' => 0, 'items_removed' => 0, 'budget_exhausted' => false, 'blocked_reason' => null],
            WorkflowStreamRetention::pruneExpired('default', 10, 1000));

        $this->travel(120)->seconds();

        // A pass deletes at most its item budget, earliest deadline first.
        $this->assertSame(['streams_reclaimed' => 0, 'items_removed' => 3, 'budget_exhausted' => true, 'blocked_reason' => null],
            WorkflowStreamRetention::pruneExpired('default', 10, 3));
        $this->assertSame(2, WorkflowDurableStreamItem::query()->where('stream_name', 'first')->count());
        $this->assertSame(2, WorkflowDurableStreamItem::query()->where('stream_name', 'second')->count());

        // The next pass resumes: the rest of `first`, then `second`.
        $this->assertSame(['streams_reclaimed' => 2, 'items_removed' => 4, 'budget_exhausted' => false, 'blocked_reason' => null],
            WorkflowStreamRetention::pruneExpired('default', 10, 1000));
        $this->assertSame(['long' => 1, 'open' => 1], WorkflowDurableStreamItem::query()->where('workflow_run_id', $run->id)
            ->orderBy('stream_name')->pluck('stream_name')->countBy()->all());
        // Reclaimed streams keep their rows, with no deadline left.
        $this->assertSame(['first', 'long', 'open', 'second'], WorkflowDurableStream::query()
            ->where('workflow_run_id', $run->id)->orderBy('stream_name')->pluck('stream_name')->all());
        $this->assertSame(['first', 'open', 'second'], WorkflowDurableStream::query()
            ->where('workflow_run_id', $run->id)->whereNull('reclaim_at')->orderBy('stream_name')->pluck('stream_name')->all());
        // Nothing is left to read for `default`; another namespace's stream waits for its own pass.
        $this->assertSame([], WorkflowStreamRetention::dueStreams('default', 10, now()));
        $this->assertSame(1, WorkflowDurableStreamItem::query()->where('stream_name', 'foreign')->count());

        // The stream-count bound: one stream per pass.
        $this->assertSame(['streams_reclaimed' => 1, 'items_removed' => 1, 'budget_exhausted' => false, 'blocked_reason' => null],
            WorkflowStreamRetention::pruneExpired('elsewhere', 1, 1000));
    }

    public function test_a_reclaimed_stream_keeps_its_identity_until_its_run_is_pruned(): void
    {
        $run = $this->createRun('default', 'wf-streams-tombstone');
        $this->postItems($run->id, 'tokens', [['payload' => 1], ['payload' => 2]], 'wf-streams-tombstone');
        $this->withHeaders($this->apiHeaders())
            ->postJson($this->closeRoute('wf-streams-tombstone', $run->id, 'tokens'), ['retention_seconds' => 60])
            ->assertOk();
        $this->travel(60)->seconds();

        WorkflowStreamRetention::pruneExpired('default', 10, 1000);

        $this->assertSame(0, WorkflowDurableStreamItem::query()->where('workflow_run_id', $run->id)->count());
        $stream = WorkflowDurableStream::query()->where('workflow_run_id', $run->id)->sole();
        $this->assertNull($stream->reclaim_at);
        // The name is not opened again: append, describe and close stay not found.
        $this->withHeaders($this->apiHeaders())
            ->postJson($this->itemsRoute('wf-streams-tombstone', $run->id, 'tokens'), ['items' => [['payload' => 3]]])
            ->assertNotFound()->assertJsonPath('reason', 'stream_not_found');
        $this->withHeaders($this->apiHeaders())
            ->getJson(sprintf('/api/workflows/%s/runs/%s/streams/%s', 'wf-streams-tombstone', $run->id, 'tokens'))
            ->assertNotFound();
        $this->withHeaders($this->apiHeaders())
            ->postJson($this->closeRoute('wf-streams-tombstone', $run->id, 'tokens'), [])
            ->assertNotFound();
        $this->assertSame(1, WorkflowDurableStream::query()->where('workflow_run_id', $run->id)->count());
        $this->assertSame(0, WorkflowDurableStreamItem::query()->where('workflow_run_id', $run->id)->count());
    }

    public function test_worker_heartbeat_and_history_prune_run_the_stream_retention_pass(): void
    {
        $run = $this->createRun('default', 'wf-streams-inline');
        $streams = app(WorkflowStreamService::class);
        $streams->append($run, 'default', 'inline', [['payload' => 1]]);
        $streams->close($run, 'default', 'inline', null, 60);
        $streams->append($run, 'default', 'command', [['payload' => 1]]);
        $streams->close($run, 'default', 'command', null, 120);
        WorkflowNamespace::query()->where('name', 'default')->update(['retention_mode' => WorkflowNamespace::RETENTION_MODE_FOREVER]);

        $this->travel(61)->seconds();
        HistoryRetentionEnforcer::runInlinePass('default');
        $this->assertSame(['command'], WorkflowDurableStreamItem::query()->pluck('stream_name')->all());

        $this->travel(60)->seconds();
        $this->artisan('history:prune', ['--namespace' => 'default'])->assertExitCode(0);
        $this->assertSame(0, WorkflowDurableStreamItem::query()->count());
        $this->assertSame(2, WorkflowDurableStream::query()->whereNull('reclaim_at')->count());
    }

    public function test_the_operator_retention_pass_reclaims_expired_streams_within_its_limit(): void
    {
        $run = $this->createRun('default', 'wf-streams-operator-pass');
        $streams = app(WorkflowStreamService::class);
        $streams->append($run, 'default', 'large', array_map(static fn (int $n): array => ['payload' => $n], range(1, 150)));
        $streams->close($run, 'default', 'large', null, 60);
        $streams->append($run, 'default', 'small', [['payload' => 1]]);
        $streams->close($run, 'default', 'small', null, 60);
        $this->travel(61)->seconds();

        // limit 1: one stream and at most 100 items per pass, resumed by the next.
        $pass = fn () => $this->withHeaders($this->apiHeaders())->postJson('/api/system/retention/pass', ['limit' => 1])->assertOk();
        $pass();
        $this->assertSame(50, WorkflowDurableStreamItem::query()->where('stream_name', 'large')->count());
        $pass();
        $this->assertSame(['small'], WorkflowDurableStreamItem::query()->pluck('stream_name')->all());
        $pass();
        $this->assertSame(0, WorkflowDurableStreamItem::query()->count());
        $this->assertSame(2, WorkflowDurableStream::query()->whereNull('reclaim_at')->count());
    }

    public function test_due_stream_discovery_reads_the_deadline_index(): void
    {
        $plan = collect(DB::select('EXPLAIN QUERY PLAN '.WorkflowStreamRetention::dueStreamsQuery('default', 10, now())->toRawSql()))
            ->pluck('detail')->implode("\n");

        // A search on both index columns, namespace equal and deadline
        // bounded, read in index order.
        $this->assertStringContainsString('SEARCH workflow_durable_streams USING INDEX wf_durable_streams_ns_reclaim_idx (namespace=? AND reclaim_at<?)', $plan);
        $this->assertStringNotContainsString('TEMP B-TREE', $plan);
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

    public function test_timed_out_completion_records_timeout_without_committing_stream_output(): void
    {
        $task = $this->claimStreamCompletionTask(1);
        $this->travel(2)->seconds();
        $this->withHeaders($this->workerHeaders())->postJson("/api/worker/workflow-tasks/{$task['task_id']}/complete", [
            'lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'],
            'commands' => [$this->streamCompletionCommand($task)],
        ])->assertConflict()->assertJsonPath('recorded', false)->assertJsonPath('reason', 'run_timed_out');
        $this->assertDatabaseCount('workflow_durable_stream_items', 0);
        $this->assertDatabaseCount('workflow_durable_streams', 0);
        $this->assertDatabaseHas('workflow_history_events', ['workflow_run_id' => $task['run_id'], 'event_type' => 'WorkflowTimedOut']);
        $this->assertDatabaseMissing('workflow_history_events', ['workflow_run_id' => $task['run_id'], 'event_type' => 'SideEffectRecorded']);
        $this->assertSame('failed', WorkflowRun::query()->findOrFail($task['run_id'])->status->value);
    }

    public function test_terminal_completion_commits_its_last_stream_item_and_close(): void
    {
        $task = $this->claimStreamCompletionTask();
        $close = $this->streamCompletionCommand($task);
        $close['workflow_stream'] = [...$close['workflow_stream'], 'operation' => 'close', 'command_ordinal' => 1];
        unset($close['workflow_stream']['items']);
        $this->withHeaders($this->workerHeaders())->postJson("/api/worker/workflow-tasks/{$task['task_id']}/complete", [
            'lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'],
            'commands' => [$this->streamCompletionCommand($task), $close, ['type' => 'complete_workflow', 'result' => Serializer::serializeWithCodec('avro', 'done')]],
        ])->assertOk()->assertJsonPath('recorded', true);
        $this->assertDatabaseCount('workflow_durable_stream_items', 1);
        $this->assertSame('closed', WorkflowDurableStream::query()->sole()->status);
        $this->assertSame('completed', WorkflowRun::query()->findOrFail($task['run_id'])->status->value);
    }

    public function test_legacy_request_refuses_explicit_scope_before_stream_output(): void
    {
        $task = $this->claimStreamCompletionTask();
        $this->withHeaders(array_replace($this->workerHeaders(), [WorkerProtocol::HEADER => '1.19']))->postJson("/api/worker/workflow-tasks/{$task['task_id']}/complete", [
            'lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'],
            'commands' => [$this->streamCompletionCommand($task), ['type' => 'start_timer', 'delay_seconds' => 10, 'cancellation_scope_id' => 'root']],
        ])->assertConflict()->assertJsonPath('recorded', false)->assertJsonPath('reason', 'cancellation_scope_membership_unavailable');
        $this->assertDatabaseCount('workflow_durable_stream_items', 0);
        $this->assertDatabaseCount('workflow_run_timers', 0);
    }

    public function test_stream_failure_rolls_back_successful_native_admission(): void
    {
        $task = $this->claimStreamCompletionTask();
        $before = WorkflowRun::query()->findOrFail($task['run_id'])->status;
        $command = $this->streamCompletionCommand($task);
        $command['workflow_stream']['max_pending_items'] = 1;
        $second = $command['workflow_stream']['items'][0];
        $second['idempotency_key'] = substr($second['idempotency_key'], 0, -1).'1';
        $command['workflow_stream']['items'][] = $second;
        $this->withHeaders($this->workerHeaders())->postJson("/api/worker/workflow-tasks/{$task['task_id']}/complete", [
            'lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'],
            'commands' => [$command, ['type' => 'complete_workflow', 'result' => Serializer::serializeWithCodec('avro', 'done')]],
        ])->assertStatus(429);
        $this->assertDatabaseCount('workflow_durable_stream_items', 0);
        $this->assertDatabaseMissing('workflow_history_events', ['workflow_run_id' => $task['run_id'], 'event_type' => 'SideEffectRecorded']);
        $this->assertSame($before, WorkflowRun::query()->findOrFail($task['run_id'])->status);
        $this->assertSame(TaskStatus::Leased, WorkflowTask::query()->findOrFail($task['task_id'])->status);
    }

    private function claimStreamCompletionTask(?int $timeout = null): array
    {
        config()->set('workflows.v2.types.workflows', ['tests.external-greeting-workflow' => ExternalGreetingWorkflow::class]);
        $this->withHeaders($this->apiHeaders())->postJson('/api/workflows', [
            'workflow_type' => 'tests.external-greeting-workflow', 'task_queue' => 'stream-command-queue',
            'input' => ['codec' => 'avro', 'blob' => Serializer::serializeWithCodec('avro', ['Ada'])],
            ...($timeout === null ? [] : ['run_timeout_seconds' => $timeout]),
        ])->assertCreated();
        $this->registerWorker('stream-command-worker', 'stream-command-queue', supportedWorkflowTypes: ['tests.external-greeting-workflow']);

        return $this->withHeaders($this->workerHeaders())->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'stream-command-worker', 'task_queue' => 'stream-command-queue',
        ])->assertOk()->json('task');
    }

    private function streamCompletionCommand(array $task): array
    {
        $identity = $task['workflow_command_id'] ?: $task['task_id'];

        return ['type' => 'record_side_effect', 'result' => Serializer::serializeWithCodec('avro', null),
            'workflow_stream' => ['operation' => 'append', 'stream_name' => 'tokens', 'command_identity' => $identity,
                'command_ordinal' => 0, 'items' => [['payload' => Serializer::serializeWithCodec('avro', ['token']),
                    'payload_codec' => 'avro', 'idempotency_key' => "dw-stream:{$identity}:0:0"]]]];
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
