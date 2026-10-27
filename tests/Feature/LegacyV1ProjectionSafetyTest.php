<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\RuntimeSignalControlPlane;
use App\Models\WorkflowInboundStream;
use App\Models\WorkflowInboundStreamItem;
use App\Support\LegacyV1Projection;
use App\Support\MessageStreamService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowRun;

class LegacyV1ProjectionSafetyTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    public static function projectionStates(): array
    {
        return array_map(static fn (string $state): array => [$state], [
            'pending', 'running', 'waiting', 'completed', 'failed', 'cancelled', 'terminated',
        ]);
    }

    #[DataProvider('projectionStates')]
    public function test_stream_append_refuses_valid_and_malformed_bodies_without_writes(string $state): void
    {
        $run = $this->projection($state);
        $before = $this->projectionState();
        $writes = [];
        DB::listen(static function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        foreach ([
            ['message_id' => 'message-1', 'input' => []],
            [],
            ['message_id' => ['invalid']],
            ['message_id' => 'message-1', 'input' => 'invalid'],
            ['message_id' => 'message-1', 'input' => ['codec' => 'unsupported']],
        ] as $body) {
            $this->withHeaders($this->apiHeaders())
                ->postJson('/api/workflows/'.rawurlencode($run->workflow_instance_id).'/message-streams/orders/messages', $body)
                ->assertConflict()
                ->assertJsonPath('reason', 'v1_projection_read_only')
                ->assertJsonPath('accepted', false)
                ->assertJsonPath('duplicate', false)
                ->assertJsonPath('outcome', 'rejected')
                ->assertJsonPath('operation', 'message_stream_append')
                ->assertJsonPath('stream_name', 'orders')
                ->assertJsonPath('run_id', $run->id)
                ->assertJsonPath('execution_owner', 'v1');
            $this->assertSame($before, $this->projectionState());
        }

        foreach (['null', '"not an object"'] as $body) {
            $this->call(
                'POST',
                '/api/workflows/'.rawurlencode($run->workflow_instance_id).'/message-streams/orders/messages',
                server: [
                    ...$this->transformHeadersToServerVars($this->apiHeaders()),
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_ACCEPT' => 'application/json',
                ],
                content: $body,
            )->assertConflict()->assertJsonPath('reason', 'v1_projection_read_only');
            $this->assertSame($before, $this->projectionState());
        }

        $this->call(
            'POST',
            '/api/workflows/'.rawurlencode($run->workflow_instance_id).'/message-streams/orders/messages',
            server: [
                ...$this->transformHeadersToServerVars($this->apiHeaders()),
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            content: '{',
        )->assertStatus(400)->assertJsonPath('reason', 'malformed_json');
        $this->assertSame($before, $this->projectionState());

        $this->assertSame([], $writes);
    }

    public function test_a_direct_append_cannot_create_a_stream_or_update_an_existing_duplicate(): void
    {
        $run = $this->projection('waiting');
        $this->mock(RuntimeSignalControlPlane::class, static function (MockInterface $mock): void {
            $mock->shouldNotReceive('runtimeSignal');
        });
        $service = app(MessageStreamService::class);
        $payload = 'unchanged-payload';
        $hash = hash('sha256', $payload);

        foreach ([false, true] as $existing) {
            if ($existing) {
                $stream = WorkflowInboundStream::query()->create([
                    'namespace' => 'default',
                    'workflow_instance_id' => $run->workflow_instance_id,
                    'stream_name' => 'orders',
                    'last_position' => 1,
                    'cursor_position' => 0,
                ]);
                WorkflowInboundStreamItem::query()->create([
                    'stream_id' => $stream->id,
                    'namespace' => 'default',
                    'workflow_instance_id' => $run->workflow_instance_id,
                    'stream_name' => 'orders',
                    'message_id' => 'message-1',
                    'position' => 1,
                    'payload_codec' => 'avro',
                    'payload_blob' => $payload,
                    'payload_hash' => $hash,
                ]);
            }
            $before = $this->projectionState();
            $result = $service->append('default', $run->workflow_instance_id, 'orders', 'message-1', 'avro', $payload, $hash);
            $this->assertSame(409, $result['status']);
            $this->assertSame('v1_projection_read_only', $result['reason']);
            $this->assertFalse($result['accepted']);
            $this->assertSame($before, $this->projectionState());

            $service->recordMalformed('default', $run->workflow_instance_id, 'orders', 'message-1');
            $this->assertSame($before, $this->projectionState());
        }
    }

    public function test_the_projection_fence_preserves_namespace_non_disclosure(): void
    {
        $run = $this->projection('waiting');
        $this->createNamespace('other');
        $before = $this->projectionState();

        $this->withHeaders($this->apiHeaders('other'))
            ->postJson('/api/workflows/'.rawurlencode($run->workflow_instance_id).'/message-streams/orders/messages', [])
            ->assertNotFound()
            ->assertJsonPath('reason', 'instance_not_found')
            ->assertJsonMissingPath('run_id');

        $this->assertSame($before, $this->projectionState());
    }

    private function projection(string $status): WorkflowRun
    {
        $this->createNamespace('default');
        $report = LegacyV1Projection::import('projection-safety', [
            'id' => 'v1:42',
            'engine_source' => 'v1',
            'class' => 'LegacyOrderWorkflow',
            'status' => $status,
            'created_at' => '2026-07-10T10:00:00Z',
            'updated_at' => '2026-07-10T10:05:00Z',
        ], 'default');
        $this->assertSame('projected', $report['status']);

        return WorkflowRun::query()->findOrFail($report['identity']['standalone']['run_id']);
    }

    private function projectionState(): array
    {
        $state = [];
        foreach ([
            'workflow_instances', 'workflow_runs', 'workflow_run_summaries', 'workflow_history_events',
            'workflow_inbound_streams', 'workflow_inbound_stream_items', 'workflow_signal_records',
            'workflow_commands', 'workflow_tasks',
        ] as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
        }

        return $state;
    }
}
