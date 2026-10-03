<?php

namespace Tests\Source;

use App\Models\WorkflowNamespace;
use App\Support\CooperativeCancellationPolicy;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\Support\OpenApiSchema;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ActivityCancellationCompletion;
use Workflow\V2\Support\ActivityCancellationContext;
use Workflow\V2\Support\CancellationScopeRequests;
use Workflow\V2\Support\ScopedActivityCancellation;
use Workflow\V2\WorkflowStub;

/** Exact Native Source qualification. The HTTP receipt does not prove physical callback exit. */
final class ScopedActivityCancellationReceiptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config([
            'server.worker_protocol.version' => '1.20',
            'server.auth.driver' => 'token',
            'server.auth.role_tokens' => ['operator' => 'fixture-operator', 'worker' => 'fixture-worker'],
            'workflows.v2.types.workflows' => ['tests.external-greeting-workflow' => ExternalGreetingWorkflow::class],
        ]);
        WorkflowNamespace::query()->create(['name' => 'default', 'retention_days' => 30, 'status' => 'active']);
        $this->assertTrue(class_exists(ScopedActivityCancellation::class));
        $this->assertTrue(class_exists(ActivityCancellationContext::class));
    }

    public function test_scoped_receipt_and_original_owner_acknowledgement_leave_sibling_and_workflow_running(): void
    {
        [$workflowTask, $target, $sibling, $request, $root, $fence] = $this->scopedPair();
        $before = $this->snapshot($workflowTask, $target, $sibling);
        $observed = $this->observe($target)->assertOk()->assertJsonPath('can_continue', false)
            ->assertJsonPath('cancel_requested', true)->assertJsonPath('heartbeat_recorded', false)
            ->assertJsonPath('cancellation_acknowledgement.callback_state', 'unknown')
            ->assertJsonPath('cancellation_acknowledgement.request_id', $request->payload['request_id'])
            ->assertJsonPath('cancellation_acknowledgement.root_request_id', $root->payload['request_id'])
            ->assertJsonPath('cancellation_acknowledgement.cleanup_deadline_at', $fence['cleanup_deadline_at'])
            ->assertJsonPath('cancellation_acknowledgement.cancellation_history_event_id', $fence['history_event_id']);
        $this->assertSame($before, $this->snapshot($workflowTask, $target, $sibling));
        $this->assertNull(WorkflowRun::query()->findOrFail($target['run_id'])->cancellation_request_command_id);
        $this->assertNotSame($root->payload['request_id'], $request->payload['request_id']);
        OpenApiSchema::fromFile(resource_path('platform-protocol-specs/worker-protocol-api.openapi.yaml'))
            ->assertReferenceMatches('#/components/schemas/ActivityCancellationReceipt', json_decode($observed->getContent(), flags: JSON_THROW_ON_ERROR)->cancellation_acknowledgement);
        $this->observe($sibling)->assertOk()->assertJsonPath('can_continue', true)
            ->assertJsonPath('cancel_requested', false)->assertJsonMissingPath('cancellation_acknowledgement');
        $this->withHeaders($this->headers())->postJson($this->path($target, 'complete'), $this->attempt($target) + ['result' => null])
            ->assertConflict()->assertJsonPath('recorded', false);
        $this->withHeaders($this->headers())->postJson($this->path($target, 'fail'), $this->attempt($target) + ['failure' => ['message' => 'stale', 'non_retryable' => true]])
            ->assertConflict()->assertJsonPath('recorded', false);
        $this->assertSame($before, $this->snapshot($workflowTask, $target, $sibling));

        $ack = $this->acknowledge($target, $request->payload['request_id'])->assertOk()
            ->assertJsonPath('acknowledged', true)->assertJsonPath('duplicate', false)
            ->assertJsonPath('heartbeat_recorded', false)->json('history_event_id');
        $this->observe($target)->assertOk()->assertJsonPath('cancellation_acknowledgement.callback_state', 'stopped')
            ->assertJsonPath('cancellation_acknowledgement.history_event_id', $ack)
            ->assertJsonPath('cancellation_acknowledgement.received_after_deadline', false);
        $this->acknowledge($target, $request->payload['request_id'])->assertOk()
            ->assertJsonPath('duplicate', true)->assertJsonPath('history_event_id', $ack);
        $after = $this->snapshot($workflowTask, $target, $sibling);
        $this->assertSame($before[3] + 1, $after[3]);
        unset($before[3], $after[3]);
        $this->assertSame($before[2]['last_history_sequence'] + 1, $after[2]['last_history_sequence']);
        foreach (['last_history_sequence', 'last_progress_at', 'updated_at'] as $field) {
            unset($before[2][$field], $after[2][$field]);
        }
        $this->assertSame($before, $after);
        $run = WorkflowRun::query()->findOrFail($target['run_id']);
        $this->assertTrue(ActivityCancellationCompletion::resolved($run, $target['activity_execution_id'], CancellationScopeRequests::context($run, $fence['scope_id'])));
    }

    public function test_later_whole_run_request_and_late_receipt_preserve_original_scoped_identity_and_deadline(): void
    {
        [, $target, , $request, $root, $fence] = $this->scopedPair();
        $this->travel(7)->seconds();
        $result = WorkflowStub::load($target['workflow_id'])->requestCancellation('later whole-run cleanup', 90);
        $this->assertTrue($result->accepted());
        $run = WorkflowRun::query()->findOrFail($target['run_id']);
        $this->assertNotSame($request->payload['request_id'], $run->cancellation_request_command_id);
        $this->assertNotSame($root->payload['request_id'], $run->cancellation_request_command_id);
        $this->observe($target)->assertOk()->assertJsonPath('cancellation_acknowledgement.request_id', $request->payload['request_id'])
            ->assertJsonPath('cancellation_acknowledgement.root_request_id', $root->payload['request_id'])
            ->assertJsonPath('cancellation_acknowledgement.cleanup_deadline_at', $fence['cleanup_deadline_at']);
        $this->acknowledge($target, $run->cancellation_request_command_id)->assertConflict()->assertJsonPath('acknowledged', false);
        $this->travel(24)->seconds();
        $ack = $this->acknowledge($target, $request->payload['request_id'])->assertOk()->json('history_event_id');
        $this->observe($target)->assertOk()->assertJsonPath('cancellation_acknowledgement.callback_state', 'stopped')
            ->assertJsonPath('cancellation_acknowledgement.request_id', $request->payload['request_id'])
            ->assertJsonPath('cancellation_acknowledgement.root_request_id', $root->payload['request_id'])
            ->assertJsonPath('cancellation_acknowledgement.cleanup_deadline_at', $fence['cleanup_deadline_at'])
            ->assertJsonPath('cancellation_acknowledgement.received_after_deadline', true);
        $this->acknowledge($target, $request->payload['request_id'])->assertOk()
            ->assertJsonPath('duplicate', true)->assertJsonPath('history_event_id', $ack);
        $this->assertSame($request->id, CancellationScopeRequests::request($run->fresh(), $fence['scope_id'], '1.20', 300)->id);
        $this->assertSame($run->cancellation_deadline_at->toISOString(), $run->fresh()->cancellation_deadline_at->toISOString());
    }

    public static function invalidAcknowledgements(): array
    {
        return [
            'wrong original owner' => ['lease_owner', 'other-owner'],
            'wrong root' => ['root_request_id', 'other-root'],
            'wrong original request' => ['request_id', 'other-request'],
            'changed budget' => ['cleanup_deadline_at', '2099-01-01T00:00:00.000000Z'],
            'still running' => ['callback_state', 'running'],
            'wrong evidence source' => ['evidence_source', 'workflow_worker'],
            'wrong authored operation' => ['sequence', 99],
        ];
    }

    #[DataProvider('invalidAcknowledgements')]
    public function test_mismatched_stop_report_cannot_make_scoped_callback_look_stopped(string $field, mixed $value): void
    {
        [$workflowTask, $target, $sibling, $request] = $this->scopedPair();
        $ack = $this->acknowledge($target, $request->payload['request_id'])->assertOk()->json('history_event_id');
        $event = WorkflowHistoryEvent::query()->findOrFail($ack);
        $payload = $event->payload;
        $payload[$field] = $value;
        $event->forceFill(['payload' => $payload])->save();
        $before = $this->snapshot($workflowTask, $target, $sibling);
        $this->observe($target)->assertOk()->assertJsonPath('cancellation_acknowledgement.callback_state', 'unknown')
            ->assertJsonPath('cancellation_acknowledgement.history_event_id', null);
        $this->assertSame($before, $this->snapshot($workflowTask, $target, $sibling));
    }

    public static function invalidScopeFacts(): array
    {
        return [
            'missing context' => ['cancellation_scope', null],
            'wrong run' => ['workflow_run_id', 'other-run'],
            'wrong accepted request' => ['request_id', 'other-request'],
            'wrong scope membership' => ['scope_id', 'other-scope'],
            'malformed request event ID' => ['request_history_event_id', []],
            'inflated authority budget' => ['authority_deadline_at', '2099-01-01T00:00:00.000000Z'],
        ];
    }

    #[DataProvider('invalidScopeFacts')]
    public function test_malformed_scoped_fact_never_falls_back_to_later_whole_run_request(string $field, mixed $value): void
    {
        [$workflowTask, $target, $sibling, $request, , $fence] = $this->scopedPair();
        $this->assertTrue(WorkflowStub::load($target['workflow_id'])->requestCancellation('later', 90)->accepted());
        $event = WorkflowHistoryEvent::query()->findOrFail($fence['history_event_id']);
        $payload = $event->payload;
        if ($field === 'cancellation_scope') {
            $payload[$field] = $value;
        } else {
            $payload['cancellation_scope'][$field] = $value;
        }
        $event->forceFill(['payload' => $payload])->save();
        $before = $this->snapshot($workflowTask, $target, $sibling);
        $this->observe($target)->assertOk()->assertJsonPath('can_continue', false)
            ->assertJsonPath('cancel_requested', true)->assertJsonMissingPath('cancellation_acknowledgement');
        $this->acknowledge($target, $request->payload['request_id'])->assertConflict()->assertJsonPath('acknowledged', false);
        $this->assertSame($before, $this->snapshot($workflowTask, $target, $sibling));
    }

    public function test_scoped_receipt_remains_bound_to_original_worker_role_owner_attempt_and_namespace(): void
    {
        [$workflowTask, $target, $sibling, $request] = $this->scopedPair();
        WorkflowNamespace::query()->create(['name' => 'other', 'retention_days' => 30, 'status' => 'active']);
        $before = $this->snapshot($workflowTask, $target, $sibling);
        $this->withHeaders($this->headers(role: 'operator'))->postJson($this->path($target), $this->attempt($target))->assertForbidden();
        $this->withHeaders($this->headers(role: 'operator'))->postJson($this->path($target, 'acknowledge-cancellation'), $this->attempt($target) + ['request_id' => $request->payload['request_id']])->assertForbidden();
        $this->withHeaders($this->headers(namespace: 'other'))->postJson($this->path($target), $this->attempt($target))->assertNotFound();
        $this->withHeaders($this->headers(namespace: 'other'))->postJson($this->path($target, 'acknowledge-cancellation'), $this->attempt($target) + ['request_id' => $request->payload['request_id']])->assertNotFound();
        $this->observe($target, ['lease_owner' => 'other-owner'])->assertConflict()->assertJsonPath('reason', 'lease_owner_mismatch');
        $this->acknowledge($target, $request->payload['request_id'], ['lease_owner' => 'other-owner'])->assertConflict();
        $this->observe($target, ['activity_attempt_id' => $sibling['activity_attempt_id']])->assertConflict()->assertJsonPath('reason', 'task_mismatch');
        $this->acknowledge($target, $request->payload['request_id'], ['activity_attempt_id' => $sibling['activity_attempt_id']])->assertConflict();
        $this->assertSame($before, $this->snapshot($workflowTask, $target, $sibling));
    }

    /** Canonical open/checkpoint/claims use HTTP. Scoped request/fence are still internal Native primitives. */
    private function scopedPair(): array
    {
        $this->withHeaders($this->headers(role: 'operator'))
            ->postJson('/api/workflows', ['workflow_type' => 'tests.external-greeting-workflow', 'task_queue' => 'scoped-receipt', 'input' => ['Ada']])->assertCreated();
        $this->withHeaders($this->headers())->postJson('/api/worker/register', [
            'worker_id' => 'scope-owner', 'task_queue' => 'scoped-receipt', 'runtime' => 'php',
            'supported_workflow_types' => ['tests.external-greeting-workflow'],
            'supported_activity_types' => ['opaque-scoped-activity'],
            'capabilities' => [CooperativeCancellationPolicy::CAPABILITY],
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
            'max_concurrent_workflow_tasks' => 1,
            'process_metrics' => ['host' => 'fixture', 'process_started_at' => now()->toISOString(), 'process_id' => 10],
        ])->assertCreated();
        $task = $this->withHeaders($this->headers())->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'scope-owner', 'task_queue' => 'scoped-receipt',
        ])->assertOk()->json('task');
        $scopes = [];
        foreach (range(1, 3) as $sequence) {
            $scopes[] = $this->withHeaders($this->headers())->postJson("/api/worker/workflow-tasks/{$task['task_id']}/cancellation-scopes/open", [
                'lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'],
                'sequence' => $sequence, 'parent_scope_id' => $sequence === 2 ? $scopes[0] : 'root',
            ])->assertOk()->assertJsonPath('opened', true)->json('scope_id');
        }
        $commands = [];
        foreach ([$scopes[1], $scopes[2]] as $scope) {
            $commands[] = ['type' => 'schedule_activity', 'activity_type' => 'opaque-scoped-activity',
                'arguments' => Serializer::serializeWithCodec('avro', ['Ada']), 'payload_codec' => 'avro',
                'queue' => 'scoped-receipt', 'cancellation_scope_id' => $scope,
                'cancellation_policy' => 'wait_cancellation_completed', 'schedule_to_close_timeout' => 120];
        }
        $this->withHeaders($this->headers())->postJson("/api/worker/workflow-tasks/{$task['task_id']}/cancellation-scopes/checkpoint", [
            'lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'],
            'checkpoint_id' => 'scoped-activities', 'start_sequence' => 4, 'commands' => $commands,
        ])->assertOk()->assertJsonPath('checkpointed', true);
        $activities = [];
        foreach (range(1, 2) as $_) {
            $activities[] = $this->withHeaders($this->headers())->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'scope-owner', 'task_queue' => 'scoped-receipt',
            ])->assertOk()->json('task');
        }
        usort($activities, fn (array $a, array $b): int => ActivityExecution::query()->findOrFail($a['activity_execution_id'])->sequence
            <=> ActivityExecution::query()->findOrFail($b['activity_execution_id'])->sequence);
        $run = WorkflowRun::query()->findOrFail($task['run_id']);
        $root = CancellationScopeRequests::request($run, $scopes[0], '1.20', 30, 'original scoped cleanup');
        $request = CancellationScopeRequests::request($run, $scopes[1], '1.20', 300, parentScopeId: $scopes[0]);
        $fence = ScopedActivityCancellation::fence($run, WorkflowTask::query()->findOrFail($task['task_id']),
            $activities[0]['activity_execution_id'], $scopes[1], $request->payload['request_id'], '1.20');
        $this->assertTrue($fence['fenced']);
        $this->assertTrue($fence['waiting_for_stop']);

        return [$task, $activities[0], $activities[1], $request, $root, $fence];
    }

    private function headers(string $role = 'worker', string $namespace = 'default'): array
    {
        return ['Authorization' => 'Bearer fixture-'.$role, 'X-Namespace' => $namespace,
            'X-Durable-Workflow-Control-Plane-Version' => '2', WorkerProtocol::HEADER => '1.20'];
    }

    private function path(array $task, string $action = 'status'): string
    {
        return "/api/worker/activity-tasks/{$task['task_id']}/{$action}";
    }

    private function attempt(array $task): array
    {
        return ['activity_attempt_id' => $task['activity_attempt_id'], 'lease_owner' => $task['lease_owner']];
    }

    private function observe(array $task, array $overrides = []): TestResponse
    {
        return $this->withHeaders($this->headers())->postJson($this->path($task), array_replace($this->attempt($task), $overrides));
    }

    private function acknowledge(array $task, string $requestId, array $overrides = []): TestResponse
    {
        return $this->withHeaders($this->headers())->postJson($this->path($task, 'acknowledge-cancellation'),
            array_replace($this->attempt($task) + ['request_id' => $requestId], $overrides));
    }

    private function snapshot(array $workflowTask, array $target, array $sibling): array
    {
        return [WorkflowTask::query()->findOrFail($workflowTask['task_id'])->getRawOriginal(),
            array_map(fn (array $task): array => [
                ActivityExecution::query()->findOrFail($task['activity_execution_id'])->getRawOriginal(),
                ActivityAttempt::query()->findOrFail($task['activity_attempt_id'])->getRawOriginal(),
                WorkflowTask::query()->findOrFail($task['task_id'])->getRawOriginal(),
            ], [$target, $sibling]),
            WorkflowRun::query()->findOrFail($target['run_id'])->getRawOriginal(),
            WorkflowHistoryEvent::query()->where('workflow_run_id', $target['run_id'])->count()];
    }
}
