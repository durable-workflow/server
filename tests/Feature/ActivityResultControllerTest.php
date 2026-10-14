<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\Fixtures\ResultInspectionRetryWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

final class ActivityResultControllerTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->createNamespace('default');
        $this->createNamespace('other');
        $this->configureWorkflowTypes(['tests.external-greeting-workflow' => ExternalGreetingWorkflow::class]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('framework/testing/activity-result-inspection'));
        parent::tearDown();
    }

    public static function results(): array
    {
        return [
            'typed' => [['number' => 9007199254740993, 'null' => null, 'text' => '日本語']],
            'null' => [null],
            'false' => [false],
        ];
    }

    #[DataProvider('results')]
    public function test_it_returns_only_the_canonical_activity_result(mixed $value): void
    {
        [$workflowId, $runId, $activityId] = $this->activity();
        $this->complete($value);
        $event = $this->completion($runId);
        $before = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->get()->toArray();

        // A mutable row must not overwrite the retained completion's result.
        ActivityExecution::query()->whereKey($activityId)->update(['result' => Serializer::serializeWithCodec('avro', 'wrong')]);
        $response = $this->withHeaders($this->apiHeaders())->getJson($this->path($workflowId, $runId, $activityId));
        $response->assertOk()->assertHeader('X-Durable-Workflow-Control-Plane-Version', '2')
            ->assertJsonPath('workflow_id', $workflowId)->assertJsonPath('run_id', $runId)
            ->assertJsonPath('activity_id', $activityId)->assertJsonPath('history_sequence', $event->sequence)
            ->assertJsonPath('state', 'available')->assertJsonPath('available', true);
        $this->assertSame($value, Serializer::unserializeWithCodec('avro', $response->json('result_envelope.blob')));
        $this->assertSame($before, WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->get()->toArray());
        $this->withHeaders($this->apiHeaders())->getJson("/api/workflows/{$workflowId}/runs/{$runId}/activities")
            ->assertOk()->assertJsonMissingPath('activities.0.result');
    }

    public function test_it_binds_the_activity_to_the_selected_namespace_workflow_and_run(): void
    {
        [$workflowId, $runId, $activityId] = $this->activity();
        [$otherId, $otherRun] = $this->activity('other-workflow');
        $this->withHeaders($this->apiHeaders('other'))->getJson($this->path($workflowId, $runId, $activityId))->assertNotFound();
        $this->withHeaders($this->apiHeaders())->getJson($this->path($otherId, $otherRun, $activityId))->assertNotFound();
        $this->withHeaders($this->apiHeaders())->getJson($this->path($otherId, $runId, $activityId))->assertNotFound();
        $this->withHeaders($this->apiHeaders())->getJson($this->path($workflowId, $runId, 'missing'))->assertNotFound();
    }

    public function test_pending_is_not_an_available_null_result(): void
    {
        [$workflowId, $runId, $activityId] = $this->activity();
        $this->withHeaders($this->apiHeaders())->getJson($this->path($workflowId, $runId, $activityId))
            ->assertOk()->assertJsonPath('state', 'pending')->assertJsonPath('available', false)
            ->assertJsonMissingPath('result_envelope');
    }

    public function test_missing_and_pruned_history_never_use_mutable_result_fallback(): void
    {
        [$workflowId, $runId, $activityId] = $this->activity();
        $this->complete('retained');
        WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
            ->where('event_type', 'like', 'Activity%')->delete();
        $this->withHeaders($this->apiHeaders())->getJson($this->path($workflowId, $runId, $activityId))
            ->assertOk()->assertJsonPath('state', 'unsupported')->assertJsonPath('available', false)
            ->assertJsonMissingPath('result_envelope');
        WorkflowRun::query()->whereKey($runId)->update(['details_pruned_at' => now()]);
        $this->withHeaders($this->apiHeaders())->getJson($this->path($workflowId, $runId, $activityId))
            ->assertOk()->assertJsonPath('state', 'pruned')->assertJsonPath('available', false);
    }

    public function test_local_results_are_explicitly_unsupported(): void
    {
        [$workflowId, $runId, $activityId] = $this->activity();
        $this->complete('local');
        $event = $this->completion($runId);
        $payload = $event->payload;
        $payload['activity']['local_activity'] = true;
        $event->forceFill(['payload' => $payload])->save();
        $this->withHeaders($this->apiHeaders())->getJson($this->path($workflowId, $runId, $activityId))
            ->assertOk()->assertJsonPath('state', 'unsupported')
            ->assertJsonPath('reason', 'local_activity_result_inspection_unsupported')->assertJsonMissingPath('result_envelope');
    }

    public function test_it_preserves_external_result_bytes_without_returning_stored_paths(): void
    {
        config(['cache.default' => 'file']);
        WorkflowNamespace::query()->where('name', 'default')->update(['external_payload_storage' => [
            'driver' => 'local', 'enabled' => true, 'threshold_bytes' => 32,
            'config' => ['uri' => 'file://'.storage_path('framework/testing/activity-result-inspection')],
        ]]);
        [$workflowId, $runId, $activityId] = $this->activity();
        $value = ['text' => str_repeat('日本語', 256), 'number' => 9007199254740993, 'null' => null];
        $this->complete($value);
        $response = $this->withHeaders($this->apiHeaders())->getJson($this->path($workflowId, $runId, $activityId))
            ->assertOk()->assertJsonPath('state', 'available')->assertJsonMissingPath('result_envelope.external_storage')
            ->assertJsonStructure(['result_envelope' => ['external_payload' => ['reference_id', 'size_bytes', 'sha256']]]);
        $reference = $response->json('result_envelope.external_payload');
        $download = $this->withHeaders($this->apiHeaders() + [
            'Accept' => 'application/octet-stream', 'X-Durable-Workflow-Payload-Codec' => 'avro',
            'X-Durable-Workflow-Payload-Size' => (string) $reference['size_bytes'],
            'X-Durable-Workflow-Payload-SHA256' => $reference['sha256'],
        ])->get('/api/external-payloads/v1/'.$reference['reference_id'])->assertOk();
        $blob = $download->streamedContent();
        $this->assertSame($reference['size_bytes'], strlen($blob));
        $this->assertSame($reference['sha256'], hash('sha256', $blob));
        $this->assertSame($value, Serializer::unserializeWithCodec('avro', $blob));
    }

    public static function unavailableOutcomes(): array
    {
        return [
            'failed' => ['failed', HistoryEventType::ActivityFailed, 'failed'],
            'cancelled' => ['cancelled', HistoryEventType::ActivityCancelled, 'cancelled'],
            'retry pending' => ['pending', HistoryEventType::ActivityFailed, 'pending'],
        ];
    }

    #[DataProvider('unavailableOutcomes')]
    public function test_it_uses_the_canonical_activity_status_not_the_attempt_outcome(
        string $status, HistoryEventType $eventType, string $state,
    ): void {
        [$workflowId, $runId, $activityId] = $this->activity();
        $this->complete('original');
        $event = $this->completion($runId);
        $payload = $event->payload;
        $payload['activity']['status'] = $status;
        unset($payload['result'], $payload['activity']['result']);
        $event->forceFill(['event_type' => $eventType, 'payload' => $payload])->save();
        $this->withHeaders($this->apiHeaders())->getJson($this->path($workflowId, $runId, $activityId))
            ->assertOk()->assertJsonPath('state', $state)->assertJsonPath('available', false)
            ->assertJsonMissingPath('result_envelope');
    }

    public function test_retained_completion_remains_inspectable_without_a_mutable_activity_row(): void
    {
        [$workflowId, $runId, $activityId] = $this->activity();
        $this->complete('history-owned');
        ActivityExecution::query()->whereKey($activityId)->delete();
        $response = $this->withHeaders($this->apiHeaders())->getJson($this->path($workflowId, $runId, $activityId))
            ->assertOk()->assertJsonPath('state', 'available');
        $this->assertSame('history-owned', Serializer::unserializeWithCodec('avro', $response->json('result_envelope.blob')));
    }

    public function test_worker_credentials_cannot_read_operator_results(): void
    {
        [$workflowId, $runId, $activityId] = $this->activity();
        config(['server.auth.driver' => 'token', 'server.auth.token' => null,
            'server.auth.role_tokens' => ['worker' => 'synthetic-worker-token', 'operator' => 'synthetic-operator-token', 'admin' => null],
        ]);
        $this->withHeaders($this->apiHeaders() + ['Authorization' => 'Bearer synthetic-worker-token'])
            ->getJson($this->path($workflowId, $runId, $activityId))->assertForbidden();
        $this->withHeaders($this->apiHeaders() + ['Authorization' => 'Bearer synthetic-operator-token'])
            ->getJson($this->path($workflowId, $runId, $activityId))->assertOk()->assertJsonPath('state', 'pending');
    }

    public function test_closed_workflow_keeps_its_distinct_activity_result_inspectable(): void
    {
        [$workflowId, $runId, $activityId] = $this->activity();
        $this->complete('activity result');
        $this->runReadyWorkflowTask($runId);
        $this->assertSame('completed', WorkflowRun::query()->findOrFail($runId)->status->value);
        $response = $this->withHeaders($this->apiHeaders())->getJson($this->path($workflowId, $runId, $activityId))
            ->assertOk()->assertJsonPath('state', 'available');
        $this->assertSame('activity result', Serializer::unserializeWithCodec('avro', $response->json('result_envelope.blob')));
        $this->withHeaders($this->apiHeaders())->getJson("/api/workflows/{$workflowId}/runs/{$runId}")
            ->assertOk()->assertJsonPath('output.greeting', 'activity result');
    }

    public function test_discovery_advertises_the_scoped_result_read(): void
    {
        $this->withHeaders($this->apiHeaders())->getJson('/api/cluster/info')
            ->assertOk()->assertJsonPath('capabilities.workflow_activity_result_inspection', true);
    }

    public function test_retry_completion_selects_the_same_activity_and_final_attempt(): void
    {
        $this->configureWorkflowTypes(['tests.external-greeting-workflow' => ResultInspectionRetryWorkflow::class]);
        [$workflowId, $runId, $activityId] = $this->activity();
        $this->registerWorker('result-inspection-worker', 'external-activities',
            supportedActivityTypes: ['tests.result-inspection-retry-activity']);
        $first = $this->withHeaders($this->workerHeaders())->postJson('/api/worker/activity-tasks/poll', [
            'worker_id' => 'result-inspection-worker', 'task_queue' => 'external-activities',
        ])->assertOk()->json('task');
        $this->withHeaders($this->workerHeaders())->postJson('/api/worker/activity-tasks/'.$first['task_id'].'/fail', [
            'activity_attempt_id' => $first['activity_attempt_id'], 'lease_owner' => 'result-inspection-worker',
            'failure' => ['message' => 'First attempt failed.', 'retryable' => true],
        ])->assertOk();
        $this->withHeaders($this->apiHeaders())->getJson($this->path($workflowId, $runId, $activityId))
            ->assertOk()->assertJsonPath('state', 'pending')->assertJsonMissingPath('result_envelope');
        $second = $this->withHeaders($this->workerHeaders())->postJson('/api/worker/activity-tasks/poll', [
            'worker_id' => 'result-inspection-worker', 'task_queue' => 'external-activities',
        ])->assertOk()->json('task');
        $this->assertNotSame($first['activity_attempt_id'], $second['activity_attempt_id']);
        $this->withHeaders($this->workerHeaders())->postJson('/api/worker/activity-tasks/'.$second['task_id'].'/complete', [
            'activity_attempt_id' => $second['activity_attempt_id'], 'lease_owner' => 'result-inspection-worker',
            'result' => ['codec' => 'avro', 'blob' => Serializer::serializeWithCodec('avro', 'second attempt')],
        ])->assertOk();
        $response = $this->withHeaders($this->apiHeaders())->getJson($this->path($workflowId, $runId, $activityId))
            ->assertOk()->assertJsonPath('activity_id', $activityId)->assertJsonPath('state', 'available');
        $this->assertSame('second attempt', Serializer::unserializeWithCodec('avro', $response->json('result_envelope.blob')));
        $this->assertSame(2, ActivityExecution::query()->findOrFail($activityId)->attempts()->count());
        $this->assertSame(1, WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::ActivityCompleted->value)->count());
    }

    public static function malformedHistories(): array
    {
        return [
            'missing result' => ['missing', 'unavailable'],
            'identity conflict' => ['identity', 'unsupported'],
            'value conflict' => ['value', 'unsupported'],
            'unsupported codec' => ['codec', 'unsupported'],
        ];
    }

    #[DataProvider('malformedHistories')]
    public function test_malformed_retained_history_does_not_present_an_available_result(string $kind, string $state): void
    {
        [$workflowId, $runId, $activityId] = $this->activity();
        $this->complete('original');
        $event = $this->completion($runId);
        $payload = $event->payload;
        if ($kind === 'missing') {
            unset($payload['result'], $payload['activity']['result']);
        } elseif ($kind === 'identity') {
            $payload['activity']['id'] = 'different-activity';
        } elseif ($kind === 'value') {
            $payload['activity']['result'] = ['codec' => 'avro', 'blob' => Serializer::serializeWithCodec('avro', 'different')];
        } else {
            $payload['activity']['payload_codec'] = 'json';
        }
        $event->forceFill(['payload' => $payload])->save();
        $this->withHeaders($this->apiHeaders())->getJson($this->path($workflowId, $runId, $activityId))
            ->assertOk()->assertJsonPath('state', $state)->assertJsonPath('available', false)
            ->assertJsonMissingPath('result_envelope');
    }

    private function activity(string $workflowId = 'result-inspection'): array
    {
        $start = $this->withHeaders($this->apiHeaders())->postJson('/api/workflows', [
            'workflow_id' => $workflowId, 'workflow_type' => 'tests.external-greeting-workflow', 'input' => ['Ada'],
        ])->assertCreated();
        $runId = $start->json('run_id');
        $this->runReadyWorkflowTask($runId);
        $activity = ActivityExecution::query()->where('workflow_run_id', $runId)->firstOrFail();

        return [$workflowId, $runId, $activity->id];
    }

    private function complete(mixed $value): void
    {
        $this->registerWorker('result-inspection-worker', 'external-activities');
        $poll = $this->withHeaders($this->workerHeaders())->postJson('/api/worker/activity-tasks/poll', [
            'worker_id' => 'result-inspection-worker', 'task_queue' => 'external-activities',
        ])->assertOk();
        $task = $poll->json('task');
        $this->withHeaders($this->workerHeaders())->postJson('/api/worker/activity-tasks/'.$task['task_id'].'/complete', [
            'activity_attempt_id' => $task['activity_attempt_id'], 'lease_owner' => 'result-inspection-worker',
            'result' => ['codec' => 'avro', 'blob' => Serializer::serializeWithCodec('avro', $value)],
        ])->assertOk()->assertJsonPath('outcome', 'completed');
    }

    private function completion(string $runId): WorkflowHistoryEvent
    {
        return WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::ActivityCompleted->value)->firstOrFail();
    }

    private function path(string $workflowId, string $runId, string $activityId): string
    {
        return "/api/workflows/{$workflowId}/runs/{$runId}/activities/{$activityId}/result";
    }
}
