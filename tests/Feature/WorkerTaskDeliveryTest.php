<?php

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use App\Support\WorkerTaskDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\Support\OpenApiSchema;
use Tests\TestCase;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowTask;

class WorkerTaskDeliveryTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->createNamespace('default');
        $this->configureWorkflowTypes(['tests.external-greeting-workflow' => ExternalGreetingWorkflow::class]);
        config(['cache.default' => 'array', 'server.polling.task_offer_accept_seconds' => 2]);
    }

    #[DataProvider('kinds')]
    public function test_an_offer_never_claims_or_reserves_the_task(string $kind): void
    {
        $task = $this->queuedTask($kind);
        $this->actor($task->queue);
        $before = $task->getRawOriginal();
        $counts = [ActivityAttempt::query()->count(), WorkflowHistoryEvent::query()->count()];
        $offer = $this->offer($kind, $task->queue);
        $this->assertSame($task->id, $offer['task_id']);
        $this->assertSame($kind, $offer['task_kind']);
        $this->assertSame($before, $task->fresh()->getRawOriginal());
        $this->assertSame($counts, [ActivityAttempt::query()->count(), WorkflowHistoryEvent::query()->count()]);
    }

    #[DataProvider('kinds')]
    public function test_a_lost_acceptance_reply_replays_exactly_one_live_claim_after_offer_expiry(string $kind): void
    {
        $task = $this->queuedTask($kind);
        $this->actor($task->queue);
        $offer = $this->offer($kind, $task->queue);
        $body = $this->body($task->queue) + ['task_offer_token' => $offer['token']];
        $first = $this->withHeaders($this->headers())->postJson($this->path($kind), $body)->assertOk();
        $this->assertSame($task->id, $first->json('task.task_id'));
        $this->assertSame(TaskStatus::Leased, $task->fresh()->status);
        $this->assertSame(1, (int) $task->fresh()->attempt_count);
        $counts = [ActivityAttempt::query()->count(), WorkflowHistoryEvent::query()->count()];
        $this->travel(3)->seconds();
        WorkerRegistration::query()->where('worker_id', 'offer-worker')->firstOrFail()
            ->forceFill(['last_heartbeat_at' => now()])->save();
        $replay = $this->withHeaders($this->headers())->postJson($this->path($kind), $body)->assertOk();
        foreach (['task_id', 'lease_owner', 'workflow_task_attempt', 'activity_attempt_id', 'attempt_number'] as $key) {
            $this->assertSame($first->json('task.'.$key), $replay->json('task.'.$key));
        }
        $this->assertSame(1, (int) $task->fresh()->attempt_count);
        $this->assertSame($counts, [ActivityAttempt::query()->count(), WorkflowHistoryEvent::query()->count()]);
    }

    #[DataProvider('kinds')]
    public function test_a_peer_can_claim_immediately_and_a_failed_acceptance_cannot_revoke_it(string $kind): void
    {
        $task = $this->queuedTask($kind);
        $this->actor($task->queue);
        $offer = $this->offer($kind, $task->queue);
        $this->registerWorker('peer', $task->queue);
        $this->flushHeaders();
        $peer = $this->withHeaders($this->workerHeaders())->postJson('/api/worker/'.$kind.'-tasks/poll', [
            'worker_id' => 'peer', 'task_queue' => $task->queue, 'poll_request_id' => 'peer-poll', 'timeout_seconds' => 0,
        ])->assertOk();
        $this->assertSame($task->id, $peer->json('task.task_id'));
        $before = $task->fresh()->getRawOriginal();
        $this->withHeaders($this->headers())->postJson($this->path($kind),
            $this->body($task->queue) + ['task_offer_token' => $offer['token']])->assertConflict();
        $this->assertSame($before, $task->fresh()->getRawOriginal());
    }

    #[DataProvider('kinds')]
    public function test_an_unaccepted_expired_offer_starts_no_business_attempt(string $kind): void
    {
        $task = $this->queuedTask($kind);
        $this->actor($task->queue);
        $offer = $this->offer($kind, $task->queue);
        $counts = [ActivityAttempt::query()->count(), WorkflowHistoryEvent::query()->count()];
        $this->travel(3)->seconds();
        $this->withHeaders($this->headers())->postJson($this->path($kind),
            $this->body($task->queue) + ['task_offer_token' => $offer['token']])
            ->assertConflict()->assertJsonPath('reason', 'task_offer_expired');
        $this->assertSame(TaskStatus::Ready, $task->fresh()->status);
        $this->assertSame(0, (int) $task->fresh()->attempt_count);
        $this->assertSame($counts, [ActivityAttempt::query()->count(), WorkflowHistoryEvent::query()->count()]);
    }

    #[DataProvider('kinds')]
    public function test_a_replacement_registration_cannot_accept_the_old_offer(string $kind): void
    {
        $task = $this->queuedTask($kind);
        $worker = $this->actor($task->queue);
        $offer = $this->offer($kind, $task->queue);
        $worker->forceFill(['registration_token' => str_repeat('b', 32)])->save();
        foreach ([str_repeat('a', 32), str_repeat('b', 32)] as $token) {
            $body = $this->body($task->queue);
            $body['registration_token'] = $token;
            $this->withHeaders($this->headers())->postJson($this->path($kind), $body + ['task_offer_token' => $offer['token']])
                ->assertConflict()->assertJsonPath('reason', 'worker_registration_lost_authority');
        }
        $this->assertSame(TaskStatus::Ready, $task->fresh()->status);
        $this->assertSame(0, (int) $task->fresh()->attempt_count);
    }

    public function test_modified_offer_ciphertext_cannot_authorize_a_claim(): void
    {
        $task = $this->queuedTask('workflow');
        $this->actor($task->queue);
        $offer = $this->offer('workflow', $task->queue);
        $token = $offer['token'];
        $token[20] = $token[20] === 'A' ? 'B' : 'A';
        $this->withHeaders($this->headers())->postJson($this->path('workflow'),
            $this->body($task->queue) + ['task_offer_token' => $token])
            ->assertConflict()->assertJsonPath('reason', 'task_offer_invalid');
        $this->assertSame(TaskStatus::Ready, $task->fresh()->status);
    }

    #[DataProvider('kinds')]
    public function test_two_offer_packets_for_one_poll_replay_the_same_confirmed_claim(string $kind): void
    {
        $task = $this->queuedTask($kind);
        $this->actor($task->queue);
        $first = $this->offer($kind, $task->queue);
        $second = $this->offer($kind, $task->queue);
        $this->assertNotSame($first['token'], $second['token']);
        $responses = [];
        foreach ([$first, $second] as $offer) {
            $responses[] = $this->withHeaders($this->headers())->postJson($this->path($kind),
                $this->body($task->queue) + ['task_offer_token' => $offer['token']])->assertOk()->json('task');
        }
        $this->assertSame($responses[0]['task_id'], $responses[1]['task_id']);
        $this->assertSame($responses[0]['lease_owner'], $responses[1]['lease_owner']);
        $this->assertSame(1, (int) $task->fresh()->attempt_count);
    }

    #[DataProvider('kinds')]
    public function test_a_claim_that_exceeds_the_acceptance_deadline_rolls_back_its_attempt_and_history(string $kind): void
    {
        $task = $this->queuedTask($kind);
        $this->actor($task->queue);
        $offer = $this->offer($kind, $task->queue);
        $before = $task->getRawOriginal();
        $counts = [ActivityAttempt::query()->count(), WorkflowHistoryEvent::query()->count()];
        $advanced = false;
        DB::listen(function ($query) use (&$advanced): void {
            if (! $advanced && str_starts_with(str_replace(['"', '`'], '', $query->sql), 'update workflow_tasks') && in_array('leased', $query->bindings, true)) {
                $advanced = true;
                $this->travel(3)->seconds();
            }
        });
        $this->withHeaders($this->headers())->postJson($this->path($kind),
            $this->body($task->queue) + ['task_offer_token' => $offer['token']])
            ->assertConflict()->assertJsonPath('reason', 'task_offer_expired');
        $this->assertTrue($advanced, 'The real bridge claim must run before the injected delay.');
        $this->assertSame($before, $task->fresh()->getRawOriginal());
        $this->assertSame($counts, [ActivityAttempt::query()->count(), WorkflowHistoryEvent::query()->count()]);
        if ($kind === 'activity') {
            $this->assertSame(0, (int) ActivityExecution::query()->firstOrFail()->attempt_count);
            $this->assertNull(ActivityExecution::query()->firstOrFail()->current_attempt_id);
        }
    }

    public static function kinds(): array
    {
        return ['workflow' => ['workflow'], 'activity' => ['activity']];
    }

    #[DataProvider('kinds')]
    public function test_negotiation_cannot_silently_fall_back_to_an_immediate_claim(string $kind): void
    {
        $task = $this->queuedTask($kind);
        $this->actor($task->queue);
        $before = $task->getRawOriginal();
        $this->withHeaders($this->headers())->postJson('/api/worker/'.$kind.'-tasks/poll', $this->body($task->queue))
            ->assertConflict()->assertJsonPath('reason', 'task_delivery_offer_endpoint_required');
        $this->flushHeaders();
        $this->withHeaders($this->workerHeaders())->postJson($this->path($kind), $this->body($task->queue))
            ->assertConflict()->assertJsonPath('reason', 'task_delivery_protocol_required');
        $this->assertSame($before, $task->fresh()->getRawOriginal());
    }

    public function test_an_offer_is_bound_to_the_original_poll_and_requested_kinds(): void
    {
        $task = $this->queuedTask('workflow');
        $worker = $this->actor($task->queue);
        $worker->forceFill(['capabilities' => [...$worker->capabilities, 'update_validation_tasks']])->save();
        $offer = $this->offer('workflow', $task->queue);
        foreach ([['poll_request_id' => 'different-poll'], ['task_kinds' => ['workflow', 'update_validation']]] as $change) {
            $this->withHeaders($this->headers())->postJson($this->path('workflow'),
                array_replace($this->body($task->queue), $change, ['task_offer_token' => $offer['token']]))
                ->assertConflict()->assertJsonPath('reason', 'task_offer_mismatch');
        }
        $this->assertSame(TaskStatus::Ready, $task->fresh()->status);
        $this->assertSame(0, (int) $task->fresh()->attempt_count);
    }

    #[DataProvider('routingChanges')]
    public function test_routing_changes_still_fence_an_offer_with_the_same_registration_token(string $kind, array $change): void
    {
        $task = $this->queuedTask($kind);
        $worker = $this->actor($task->queue);
        $offer = $this->offer($kind, $task->queue);
        $worker->forceFill($change)->save();
        $this->withHeaders($this->headers())->postJson($this->path($kind),
            $this->body($task->queue) + ['task_offer_token' => $offer['token']])
            ->assertConflict()->assertJsonPath('reason', 'worker_registration_lost_authority');
        $this->assertSame(TaskStatus::Ready, $task->fresh()->status);
        $this->assertSame(0, (int) $task->fresh()->attempt_count);
    }

    public static function routingChanges(): array
    {
        return [
            'workflow-build' => ['workflow', ['build_id' => 'changed-build']],
            'activity-build' => ['activity', ['build_id' => 'changed-build']],
            'workflow-process' => ['workflow', ['process_metrics' => ['process_id' => 9876]]],
            'activity-process' => ['activity', ['process_metrics' => ['process_id' => 9876]]],
        ];
    }

    #[DataProvider('kinds')]
    public function test_a_routine_heartbeat_during_acceptance_does_not_replace_the_incarnation(string $kind): void
    {
        $task = $this->queuedTask($kind);
        $worker = $this->actor($task->queue);
        $offer = $this->offer($kind, $task->queue);
        $advanced = false;
        DB::listen(function ($query) use (&$advanced, $worker): void {
            if (! $advanced && str_contains($query->sql, 'select')
                && str_contains(str_replace(['"', '`'], '', $query->sql), 'workflow_tasks')) {
                $advanced = true;
                $this->travel(1)->seconds();
                $worker->forceFill(['last_heartbeat_at' => now()])->save();
            }
        });
        $this->withHeaders($this->headers())->postJson($this->path($kind),
            $this->body($task->queue) + ['task_offer_token' => $offer['token']])
            ->assertOk()->assertJsonPath('task.task_id', $task->id);
        $this->assertTrue($advanced);
        $this->assertSame(1, (int) $task->fresh()->attempt_count);
    }

    private function queuedTask(string $kind): WorkflowTask
    {
        $result = $this->withHeaders($this->apiHeaders())->postJson('/api/workflows', [
            'workflow_type' => 'tests.external-greeting-workflow', 'task_queue' => 'delivery-queue', 'input' => ['Ada'],
        ])->assertCreated();
        $task = WorkflowTask::query()->where('workflow_run_id', $result->json('run_id'))->firstOrFail();
        if ($kind === 'activity') {
            $this->runReadyWorkflowTask($task->workflow_run_id);
            $task = WorkflowTask::query()->where('workflow_run_id', $task->workflow_run_id)->where('task_type', 'activity')->firstOrFail();
            $this->assertSame(0, (int) ActivityExecution::query()->firstOrFail()->attempt_count);
        }

        return $task;
    }

    private function actor(string $queue): WorkerRegistration
    {
        $this->registerWorker('offer-worker', $queue);
        $worker = WorkerRegistration::query()->where('worker_id', 'offer-worker')->firstOrFail();
        $worker->forceFill([
            'registration_token' => str_repeat('a', 32),
            'capabilities' => [...$worker->capabilities, WorkerTaskDelivery::CAPABILITY],
        ])->save();

        return $worker;
    }

    private function path(string $kind): string
    {
        return '/api/worker/'.$kind.'-tasks/offers';
    }

    private function headers(): array
    {
        return $this->workerHeaders() + [WorkerTaskDelivery::HEADER => WorkerTaskDelivery::VERSION];
    }

    private function body(string $queue): array
    {
        return ['worker_id' => 'offer-worker', 'task_queue' => $queue, 'poll_request_id' => 'original-poll',
            'registration_token' => str_repeat('a', 32), 'timeout_seconds' => 0];
    }

    private function offer(string $kind, string $queue): array
    {
        $response = $this->withHeaders($this->headers())->postJson($this->path($kind), $this->body($queue))
            ->assertOk()->assertJsonPath('task', null)->assertJsonPath('poll_status', 'offered')
            ->assertJsonPath('task_offer.schema', WorkerTaskDelivery::SCHEMA);
        $this->assertIsArray($response->json('task_offer'));
        OpenApiSchema::fromFile(base_path('resources/platform-protocol-specs/worker-protocol-api.openapi.yaml'))
            ->assertReferenceMatches('#/components/schemas/TaskDeliveryOfferedResponse', json_decode($response->getContent()));

        return $response->json('task_offer');
    }
}
