<?php

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\InternalChildWorkflow;
use Tests\Fixtures\ParallelChildQueryWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\WorkflowExecutor;

class ParallelChildQueryTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('terminalChildren')]
    public function test_http_queries_wait_for_committed_parent_outcomes(string $status): void
    {
        Queue::fake();
        config()->set('workflows.v2.types.workflows', [
            'tests.parallel-child-query-workflow' => ParallelChildQueryWorkflow::class,
            'tests.internal-child-workflow' => InternalChildWorkflow::class,
        ]);
        WorkflowNamespace::query()->updateOrCreate(['name' => 'default'], [
            'description' => 'Parallel child query regression',
            'retention_days' => 30,
            'status' => 'active',
        ]);
        $headers = ['X-Namespace' => 'default', 'X-Durable-Workflow-Control-Plane-Version' => '2'];
        $start = $this->withHeaders($headers)->postJson('/api/workflows', [
            'workflow_id' => 'parallel-child-query',
            'workflow_type' => 'tests.parallel-child-query-workflow',
        ])->assertCreated();
        $runId = (string) $start->json('run_id');
        $taskId = WorkflowTask::query()->where('workflow_run_id', $runId)
            ->where('task_type', 'workflow')->where('status', 'ready')->value('id');
        $this->assertIsString($taskId);
        (new RunWorkflowTask($taskId))->handle(app(WorkflowExecutor::class));
        $run = WorkflowRun::query()->findOrFail($runId);
        $this->assertSame(RunStatus::Waiting, $run->status);
        $scheduled = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::ChildWorkflowScheduled->value)
            ->orderBy('sequence')->get();
        $this->assertCount(2, $scheduled);
        foreach ($scheduled as $event) {
            WorkflowRun::query()->findOrFail($event->payload['child_workflow_run_id'])->forceFill([
                'status' => $status,
                'closed_at' => now(),
                'closed_reason' => $status,
                'output' => Serializer::serializeWithCodec($run->payload_codec, 'uncommitted-child'),
                'output_payload_codec' => $run->payload_codec,
            ])->save();
        }
        $before = $this->snapshot();
        foreach ([1, 2] as $repetition) {
            $this->withHeaders($headers)->postJson('/api/workflows/parallel-child-query/query/currentState')
                ->assertOk()->assertJsonPath('run_id', $runId)
                ->assertJsonPath('result.stage', 'waiting-for-children')
                ->assertJsonPath('result.value', null)->assertJsonPath('result.failure', null);
            $this->assertSame($before, $this->snapshot());
        }
        $resolution = match (RunStatus::from($status)) {
            RunStatus::Completed => HistoryEventType::ChildRunCompleted,
            RunStatus::Failed => HistoryEventType::ChildRunFailed,
            RunStatus::Cancelled => HistoryEventType::ChildRunCancelled,
            default => HistoryEventType::ChildRunTerminated,
        };
        $resolutionBase = now()->subMinute();
        foreach ($scheduled->reverse() as $event) {
            $sequence = $event->payload['sequence'];
            $run->increment('last_history_sequence');
            WorkflowHistoryEvent::query()->create([
                'workflow_run_id' => $runId,
                'sequence' => $run->last_history_sequence,
                'event_type' => $resolution->value,
                'payload' => $event->payload + [
                    'child_status' => $status,
                    'output' => Serializer::serializeWithCodec($run->payload_codec, 'committed-child-'.$sequence),
                    'payload_codec' => $run->payload_codec,
                    'exception_class' => \RuntimeException::class,
                    'message' => 'committed-child-'.$sequence.'-failure',
                ],
                'recorded_at' => $resolutionBase->copy()->addSeconds($run->last_history_sequence),
            ]);
        }
        $expectedValue = $status === RunStatus::Completed->value ? ['committed-child-1', 'committed-child-2'] : null;
        $expectedFailure = match (RunStatus::from($status)) {
            RunStatus::Completed => null,
            RunStatus::Failed => 'committed-child-2-failure',
            default => 'Child workflow '.$scheduled->last()->payload['child_workflow_run_id'].' closed as '.$status.'.',
        };
        $before = $this->snapshot();
        foreach ([1, 2] as $repetition) {
            $this->withHeaders($headers)->postJson('/api/workflows/parallel-child-query/query/currentState')
                ->assertOk()->assertJsonPath('run_id', $runId)
                ->assertJsonPath('result.stage', 'waiting-for-finish')
                ->assertJsonPath('result.value', $expectedValue)->assertJsonPath('result.failure', $expectedFailure);
            $this->assertSame($before, $this->snapshot());
        }
    }

    public static function terminalChildren(): array
    {
        return array_combine(
            array_map(static fn (RunStatus $status): string => $status->value, [
                RunStatus::Completed, RunStatus::Failed, RunStatus::Cancelled, RunStatus::Terminated,
            ]),
            array_map(static fn (RunStatus $status): array => [$status->value], [
                RunStatus::Completed, RunStatus::Failed, RunStatus::Cancelled, RunStatus::Terminated,
            ])
        );
    }

    private function snapshot(): array
    {
        return array_map(static fn (string $model): array => $model::query()->orderBy('id')->get()->toArray(), [
            WorkflowRun::class, WorkflowHistoryEvent::class, WorkflowTask::class, WorkflowLink::class,
        ]);
    }
}
