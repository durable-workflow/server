<?php

namespace Tests\Feature;

use App\Support\WorkflowObservability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\InteractiveCommandWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;

class WorkflowObservabilityTransitionsTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        config(['server.observability_context.enabled' => true, 'server.observability_context.baggage_allowlist' => []]);
        if (! WorkflowObservability::enabled()) {
            if (getenv('DW_TEST_OBSERVABILITY_CONTEXT_REQUIRED') === '1') {
                $this->fail('This qualification requires the tracing Core source and optional API.');
            }
            $this->markTestSkipped('Requires the optional tracing Core/API dependency profile.');
        }
        Queue::fake();
        $this->createNamespace('default');
        $this->configureWorkflowTypes(['tests.interactive-command-workflow' => InteractiveCommandWorkflow::class]);
        $this->registerWorker('transition-worker', 'default', supportedWorkflowTypes: ['tests.interactive-command-workflow']);
    }

    public static function transitions(): array
    {
        return [
            'new child producer' => ['start_child_workflow', true, false],
            'new continued producer' => ['continue_as_new', true, false],
            'disabled child inherits' => ['start_child_workflow', false, false],
            'disabled continuation inherits' => ['continue_as_new', false, false],
            'invalid child metadata inherits' => ['start_child_workflow', true, true],
            'invalid continued metadata inherits' => ['continue_as_new', true, true],
        ];
    }

    #[DataProvider('transitions')]
    public function test_only_new_runs_receive_their_producer_while_business_payloads_and_retained_origins_stay_fixed(
        string $type, bool $enabled, bool $malformed,
    ): void {
        $origin = $this->carrier('4bf92f3577b34da6a3ce929d0e0e4736');
        $start = $this->withHeaders($this->apiHeaders())->postJson('/api/workflows', [
            'workflow_id' => 'transition-origin', 'workflow_type' => 'tests.interactive-command-workflow',
            'task_queue' => 'default', 'observability_context' => $origin,
        ])->assertCreated();
        $parentId = $start->json('run_id');
        $task = $this->withHeaders($this->workerHeaders())->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'transition-worker', 'task_queue' => 'default', 'timeout_seconds' => 0,
        ])->assertOk()->json('task');
        $this->assertIsArray($task);
        config(['server.observability_context.enabled' => $enabled]);
        $producer = $this->carrier('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $arguments = [['observability_context' => 'ordinary business value', 'integer' => 9007199254740993, 'unicode' => '雪']];
        $command = ['type' => $type, 'arguments' => ['codec' => 'avro', 'blob' => Serializer::serializeWithCodec('avro', $arguments)],
            'observability_context' => $malformed ? ['schema' => 'unknown'] : $producer];
        if ($type === 'start_child_workflow') {
            $command['workflow_type'] = 'remote.observed-child';
        }
        $body = ['lease_owner' => 'transition-worker', 'workflow_task_attempt' => $task['workflow_task_attempt'], 'commands' => [$command]];
        $path = '/api/worker/workflow-tasks/'.$task['task_id'].'/complete';
        $this->withHeaders($this->workerHeaders())->postJson($path, $body)->assertOk()->assertJsonPath('recorded', true);
        $link = WorkflowLink::query()->where('parent_workflow_run_id', $parentId)
            ->where('link_type', $type === 'start_child_workflow' ? 'child_workflow' : 'continue_as_new')->sole();
        $child = WorkflowRun::query()->findOrFail($link->child_workflow_run_id);
        $this->assertSame($enabled && ! $malformed ? $producer : $origin, $child->observability_context);
        $this->assertSame($origin, WorkflowRun::query()->findOrFail($parentId)->observability_context);
        $this->assertSame($arguments, Serializer::unserializeWithCodec($child->payload_codec, $child->arguments));
        $history = json_encode(WorkflowHistoryEvent::query()->whereIn('workflow_run_id', [$parentId, $child->id])->get()->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('durable-workflow.trace-context', $history);
        $this->assertStringNotContainsString('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $history);
        $before = $child->getRawOriginal();
        $runCount = WorkflowRun::query()->count();
        $body['commands'][0]['observability_context'] = $this->carrier('cccccccccccccccccccccccccccccccc');
        $retry = $this->withHeaders($this->workerHeaders())->postJson($path, $body);
        $this->assertContains($retry->getStatusCode(), [200, 409]);
        $this->assertSame($before, $child->fresh()->getRawOriginal());
        $this->assertSame($runCount, WorkflowRun::query()->count());
    }

    private function carrier(string $traceId): array
    {
        return ['schema' => 'durable-workflow.trace-context', 'version' => 1,
            'carrier' => ['traceparent' => '00-'.$traceId.'-00f067aa0ba902b7-01']];
    }
}
