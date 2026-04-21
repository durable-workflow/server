<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\AwaitApprovalWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\FailureCategory;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

class WorkflowDebugTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->createNamespace('default');
        $this->configureWorkflowTypes([
            'tests.await-approval-workflow' => AwaitApprovalWorkflow::class,
        ]);
    }

    public function test_it_aggregates_a_one_shot_workflow_debug_diagnostic(): void
    {
        $this->registerWorker(
            'debug-worker',
            'debug-queue',
            supportedWorkflowTypes: ['tests.await-approval-workflow'],
        );

        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-debug',
            'workflow_type' => 'tests.await-approval-workflow',
            'task_queue' => 'debug-queue',
            'business_key' => 'debug-case',
        ], $this->controlPlaneHeadersWithWorkerProtocol());

        $start->assertCreated();
        $runId = (string) $start->json('run_id');

        $poll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'debug-worker',
            'task_queue' => 'debug-queue',
        ], $this->workerHeaders());

        $poll->assertOk();
        $taskId = (string) $poll->json('task.task_id');

        WorkflowFailure::query()->create([
            'workflow_run_id' => $runId,
            'source_kind' => 'workflow_task',
            'source_id' => $taskId,
            'propagation_kind' => 'workflow',
            'failure_category' => FailureCategory::TaskFailure->value,
            'non_retryable' => false,
            'handled' => false,
            'exception_class' => 'RuntimeException',
            'message' => 'Replay failed in debug test.',
            'file' => __FILE__,
            'line' => 55,
        ]);

        $debug = $this->getJson('/api/workflows/wf-debug/debug', $this->controlPlaneHeadersWithWorkerProtocol());

        $debug->assertOk()
            ->assertJsonPath('workflow_id', 'wf-debug')
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('namespace', 'default')
            ->assertJsonPath('diagnostic_status', 'pending_work')
            ->assertJsonPath('execution.status', 'pending')
            ->assertJsonPath('execution.task_queue', 'debug-queue')
            ->assertJsonPath('pending_workflow_tasks.0.task_id', $taskId)
            ->assertJsonPath('pending_workflow_tasks.0.status', 'leased')
            ->assertJsonPath('pending_workflow_tasks.0.lease_owner', 'debug-worker')
            ->assertJsonPath('task_queue.name', 'debug-queue')
            ->assertJsonPath('task_queue.stats.workflow_tasks.leased_count', 1)
            ->assertJsonPath('task_queue.pollers.0.worker_id', 'debug-worker')
            ->assertJsonPath('recent_failures.0.exception_class', 'RuntimeException')
            ->assertJsonPath('recent_failures.0.message', 'Replay failed in debug test.')
            ->assertJsonPath('control_plane.operation', 'debug_workflow')
            ->assertJsonPath('control_plane.workflow_id', 'wf-debug')
            ->assertJsonPath('execution.last_event.payload_summary.included', false)
            ->assertJsonStructure([
                'generated_at',
                'execution' => [
                    'last_event' => [
                        'sequence',
                        'event_type',
                        'timestamp',
                        'payload_summary',
                    ],
                ],
                'pending_workflow_tasks',
                'pending_activities',
                'task_queue' => [
                    'stats',
                    'current_leases',
                ],
                'compatibility' => [
                    'run',
                    'task_queue_pollers',
                    'namespace_worker_fleet',
                ],
                'findings',
            ]);
    }

    public function test_it_can_debug_a_specific_run(): void
    {
        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-debug-run',
            'workflow_type' => 'tests.await-approval-workflow',
            'task_queue' => 'debug-queue',
        ], $this->controlPlaneHeadersWithWorkerProtocol());

        $start->assertCreated();
        $runId = (string) $start->json('run_id');

        $debug = $this->getJson(
            "/api/workflows/wf-debug-run/runs/{$runId}/debug",
            $this->controlPlaneHeadersWithWorkerProtocol(),
        );

        $debug->assertOk()
            ->assertJsonPath('workflow_id', 'wf-debug-run')
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('control_plane.operation', 'debug_workflow')
            ->assertJsonPath('control_plane.run_id', $runId);
    }

    public function test_debug_diagnostics_bound_last_event_payload_detail(): void
    {
        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-debug-large-last-event',
            'workflow_type' => 'tests.await-approval-workflow',
            'task_queue' => 'debug-queue',
        ], $this->controlPlaneHeadersWithWorkerProtocol());

        $start->assertCreated();
        $run = WorkflowRun::query()->findOrFail((string) $start->json('run_id'));
        $largeValue = str_repeat('x', 64 * 1024);

        WorkflowHistoryEvent::record($run, HistoryEventType::SideEffectRecorded, [
            'result' => $largeValue,
            'metadata' => [
                'source' => 'debug-test',
            ],
        ]);

        $debug = $this->getJson(
            '/api/workflows/wf-debug-large-last-event/debug',
            $this->controlPlaneHeadersWithWorkerProtocol(),
        );

        $debug->assertOk()
            ->assertJsonPath('execution.last_event.event_type', HistoryEventType::SideEffectRecorded->value)
            ->assertJsonPath('execution.last_event.payload_summary.included', false)
            ->assertJsonPath('execution.last_event.payload_summary.top_level_keys', ['result', 'metadata'])
            ->assertJsonMissingPath('execution.last_event.payload')
            ->assertJsonMissingPath('execution.last_event.payload_preview');

        $this->assertGreaterThan(64 * 1024, $debug->json('execution.last_event.payload_summary.size_bytes'));
        $this->assertStringNotContainsString(str_repeat('x', 4096), $debug->getContent());

        $withPreview = $this->getJson(
            '/api/workflows/wf-debug-large-last-event/debug?include_last_event_payload=true',
            $this->controlPlaneHeadersWithWorkerProtocol(),
        );

        $withPreview->assertOk()
            ->assertJsonPath('execution.last_event.payload_summary.included', true)
            ->assertJsonPath('execution.last_event.payload_preview.encoding', 'json')
            ->assertJsonPath('execution.last_event.payload_preview.max_bytes', 4096)
            ->assertJsonPath('execution.last_event.payload_preview.truncated', true);

        $preview = (string) $withPreview->json('execution.last_event.payload_preview.data');
        $this->assertSame(4096, strlen($preview));
        $this->assertStringStartsWith('{"result":"', $preview);
        $this->assertStringNotContainsString(str_repeat('x', 8192), $withPreview->getContent());
    }

    public function test_debug_diagnostics_do_not_load_unbounded_historical_task_graphs(): void
    {
        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-debug-large-history',
            'workflow_type' => 'tests.await-approval-workflow',
            'task_queue' => 'debug-queue',
        ], $this->controlPlaneHeadersWithWorkerProtocol());

        $start->assertCreated();
        $run = WorkflowRun::query()->findOrFail((string) $start->json('run_id'));

        $historicalWorkflowTaskIds = [];
        $historicalActivityIds = [];

        for ($i = 0; $i < 40; $i++) {
            $historicalWorkflowTaskIds[] = $this->createDiagnosticWorkflowTask(
                $run,
                TaskStatus::Completed,
                ['available_at' => now()->subMinutes(120 - $i)],
            )->id;

            $historicalActivityIds[] = $this->createDiagnosticActivity(
                $run,
                1000 + $i,
                ActivityStatus::Completed,
                ActivityAttemptStatus::Completed,
                4,
            )->id;
        }

        for ($i = 0; $i < 30; $i++) {
            $this->createDiagnosticWorkflowTask(
                $run,
                TaskStatus::Ready,
                ['available_at' => now()->addSeconds($i)],
            );
            $this->createDiagnosticActivity(
                $run,
                2000 + $i,
                ActivityStatus::Running,
                ActivityAttemptStatus::Running,
                1,
            );
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $debug = $this->getJson(
            '/api/workflows/wf-debug-large-history/debug',
            $this->controlPlaneHeadersWithWorkerProtocol(),
        );

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $debug->assertOk()
            ->assertJsonCount(25, 'pending_workflow_tasks')
            ->assertJsonCount(25, 'pending_activities');

        $debugWorkflowTaskIds = collect($debug->json('pending_workflow_tasks'))
            ->pluck('task_id')
            ->all();
        $debugActivityIds = collect($debug->json('pending_activities'))
            ->pluck('activity_execution_id')
            ->all();

        $this->assertEmpty(array_intersect($historicalWorkflowTaskIds, $debugWorkflowTaskIds));
        $this->assertEmpty(array_intersect($historicalActivityIds, $debugActivityIds));
        $this->assertNoUnboundedDebugGraphQueries($queries);
    }

    private function createDiagnosticWorkflowTask(
        WorkflowRun $run,
        TaskStatus $status,
        array $attributes = [],
    ): WorkflowTask {
        return WorkflowTask::query()->create(array_merge([
            'workflow_run_id' => $run->id,
            'namespace' => $run->namespace,
            'task_type' => TaskType::Workflow->value,
            'status' => $status->value,
            'compatibility' => $run->compatibility,
            'payload' => [],
            'connection' => $run->connection,
            'queue' => $run->queue,
            'available_at' => now(),
        ], $attributes));
    }

    private function createDiagnosticActivity(
        WorkflowRun $run,
        int $sequence,
        ActivityStatus $status,
        ActivityAttemptStatus $attemptStatus,
        int $attemptCount,
    ): ActivityExecution {
        $execution = ActivityExecution::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => $sequence,
            'activity_class' => 'Tests\\Fixtures\\DebugActivity',
            'activity_type' => sprintf('debug.activity.%d', $sequence),
            'status' => $status->value,
            'connection' => $run->connection,
            'queue' => $run->queue,
            'attempt_count' => $attemptCount,
            'started_at' => now()->addSeconds($sequence),
        ]);

        $currentAttempt = null;

        for ($attemptNumber = 1; $attemptNumber <= $attemptCount; $attemptNumber++) {
            $currentAttempt = ActivityAttempt::query()->create([
                'workflow_run_id' => $run->id,
                'activity_execution_id' => $execution->id,
                'workflow_task_id' => null,
                'attempt_number' => $attemptNumber,
                'status' => $attemptStatus->value,
                'lease_owner' => $attemptStatus === ActivityAttemptStatus::Running ? 'debug-worker' : null,
                'started_at' => now()->addSeconds($sequence + $attemptNumber),
                'closed_at' => $attemptStatus === ActivityAttemptStatus::Running
                    ? null
                    : now()->addSeconds($sequence + $attemptNumber + 1),
            ]);
        }

        if ($currentAttempt instanceof ActivityAttempt) {
            $execution->forceFill([
                'current_attempt_id' => $currentAttempt->id,
            ])->save();
        }

        return $execution->refresh();
    }

    /**
     * @param  list<array<string, mixed>>  $queries
     */
    private function assertNoUnboundedDebugGraphQueries(array $queries): void
    {
        $offenders = collect($queries)
            ->pluck('query')
            ->filter(static function (string $query): bool {
                $normalized = strtolower($query);

                foreach (['workflow_tasks', 'activity_executions', 'activity_attempts'] as $table) {
                    $selectsWholeRows = preg_match(
                        '/^\s*select\s+\*\s+from\s+["`]?'.preg_quote($table, '/').'["`]?/',
                        $normalized,
                    ) === 1;

                    if ($selectsWholeRows && ! str_contains($normalized, 'limit')) {
                        return true;
                    }
                }

                return false;
            })
            ->values()
            ->all();

        $this->assertSame([], $offenders, 'Debug diagnostics must not select full task graphs without a limit.');
    }
}
