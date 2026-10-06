<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\AwaitApprovalWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
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
use Workflow\V2\Support\CancellationCascadeView;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\Support\WorkerCompatibilityFleet;

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

    #[DataProvider('recoveredTerminalOutcomes')]
    public function test_recovered_tasks_are_informational_history_after_a_run_closes(string $outcome): void
    {
        $workflowId = 'debug-recovered-'.$outcome;
        [$runId, $task, $workerId, $attempt] = $this->diagnosticTask($workflowId);
        $task->forceFill(['repair_count' => 1])->save();

        if ($outcome === 'cancelled') {
            $this->postJson("/api/workflows/{$workflowId}/cancel", [], $this->controlPlaneHeadersWithWorkerProtocol())
                ->assertOk();
        } else {
            $this->postJson("/api/worker/workflow-tasks/{$task->id}/complete", [
                'lease_owner' => $workerId,
                'workflow_task_attempt' => $attempt,
                'commands' => [['type' => 'complete_workflow']],
            ], $this->workerHeaders())->assertOk()->assertJsonPath('run_status', 'completed');
        }

        $this->getJson("/api/workflows/{$workflowId}/debug", $this->controlPlaneHeadersWithWorkerProtocol())
            ->assertOk()
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('diagnostic_status', 'terminal')
            ->assertJsonPath('execution.status', $outcome)
            ->assertJsonPath('execution.task_problem', true)
            ->assertJsonPath('execution.task_problem_badge.code', 'history')
            ->assertJsonPath('findings.0.code', 'task_recovery_history')
            ->assertJsonPath('findings.0.severity', 'info')
            ->assertJsonPath('findings.0.message', 'The run previously needed workflow-task repair or replay recovery.')
            ->assertJsonMissing(['code' => 'task_problem']);
    }

    public static function recoveredTerminalOutcomes(): array
    {
        return ['completed' => ['completed'], 'cancelled' => ['cancelled']];
    }

    public function test_recent_failures_link_to_their_retained_history_without_loading_the_history(): void
    {
        [$runId] = $this->diagnosticTask('debug-failure-reference');
        $failures = [];

        for ($index = 0; $index < 12; $index++) {
            $failure = WorkflowFailure::query()->create([
                'workflow_run_id' => $runId,
                'source_kind' => 'activity',
                'source_id' => 'activity-'.$index,
                'propagation_kind' => 'workflow',
                'failure_category' => FailureCategory::TaskFailure->value,
                'non_retryable' => false,
                'handled' => true,
                'exception_class' => 'RuntimeException',
                'message' => 'Activity failed.',
                'file' => __FILE__,
                'line' => __LINE__,
                'created_at' => now()->addSeconds($index),
            ]);
            $failures[] = $failure;
            WorkflowHistoryEvent::query()->create([
                'workflow_run_id' => $runId,
                'sequence' => 1000 + $index,
                'event_type' => HistoryEventType::ActivityFailed,
                'payload' => ['failure_id' => $failure->id, 'private_detail' => 'do not include in diagnostics'],
                'recorded_at' => now(),
            ]);
            WorkflowHistoryEvent::query()->create([
                'workflow_run_id' => $runId,
                'sequence' => 2000 + $index,
                'event_type' => HistoryEventType::FailureHandled,
                'payload' => ['failure_id' => $failure->id],
                'recorded_at' => now(),
            ]);
        }

        for ($index = 0; $index < 500; $index++) {
            WorkflowHistoryEvent::query()->create([
                'workflow_run_id' => $runId,
                'sequence' => 3000 + $index,
                'event_type' => HistoryEventType::SignalReceived,
                'payload' => [],
                'recorded_at' => now(),
            ]);
        }

        $hydratedEvents = 0;
        WorkflowHistoryEvent::retrieved(static function () use (&$hydratedEvents): void {
            $hydratedEvents++;
        });
        $response = $this->getJson('/api/workflows/debug-failure-reference/debug', $this->controlPlaneHeadersWithWorkerProtocol())
            ->assertOk()
            ->assertJsonCount(10, 'recent_failures')
            ->assertJsonPath('recent_failures_truncated', true)
            ->assertJsonPath('recent_failures.0.failure_id', $failures[11]->id)
            ->assertJsonPath('recent_failures.0.supporting_event.state', 'retained')
            ->assertJsonPath('recent_failures.0.supporting_event.sequence', 1011)
            ->assertJsonPath('recent_failures.0.supporting_event.event_type', 'ActivityFailed')
            ->assertJsonMissing(['private_detail' => 'do not include in diagnostics']);
        $this->assertLessThanOrEqual(12, $hydratedEvents);

        $token = rawurlencode($response->json('recent_failures.0.supporting_event.next_page_token'));
        $this->getJson("/api/workflows/debug-failure-reference/runs/{$runId}/history?page_size=1&next_page_token={$token}", $this->controlPlaneHeadersWithWorkerProtocol())
            ->assertOk()
            ->assertJsonPath('events.0.sequence', 1011)
            ->assertJsonPath('events.0.event_type', 'ActivityFailed')
            ->assertJsonPath('events.0.payload.failure_id', $failures[11]->id);
    }

    public function test_missing_failure_history_is_explicitly_unavailable_or_pruned(): void
    {
        [$runId] = $this->diagnosticTask('debug-missing-failure-reference');
        WorkflowFailure::query()->create([
            'workflow_run_id' => $runId,
            'source_kind' => 'activity',
            'source_id' => 'activity-missing',
            'propagation_kind' => 'workflow',
            'failure_category' => FailureCategory::TaskFailure->value,
            'non_retryable' => false,
            'handled' => false,
            'exception_class' => 'RuntimeException',
            'message' => 'No supporting event retained.',
            'file' => __FILE__,
            'line' => __LINE__,
        ]);

        $this->getJson('/api/workflows/debug-missing-failure-reference/debug', $this->controlPlaneHeadersWithWorkerProtocol())
            ->assertOk()
            ->assertJsonPath('recent_failures_truncated', false)
            ->assertJsonPath('recent_failures.0.supporting_event', ['state' => 'unavailable']);

        WorkflowRun::query()->findOrFail($runId)->forceFill(['details_pruned_at' => now()])->save();
        $this->getJson('/api/workflows/debug-missing-failure-reference/debug', $this->controlPlaneHeadersWithWorkerProtocol())
            ->assertOk()
            ->assertJsonPath('recent_failures.0.supporting_event', ['state' => 'pruned']);
    }

    public function test_active_replay_failure_keeps_its_warning_and_error(): void
    {
        $workflowId = 'debug-active-replay';
        [$runId, $task] = $this->diagnosticTask($workflowId);
        $task->forceFill([
            'status' => TaskStatus::Failed,
            'payload' => [...($task->payload ?? []), 'replay_blocked' => true],
            'last_error' => 'Recorded workflow commands changed.',
        ])->save();
        RunSummaryProjector::project(WorkflowRun::query()->findOrFail($runId));

        $debug = $this->getJson("/api/workflows/{$workflowId}/debug", $this->controlPlaneHeadersWithWorkerProtocol())
            ->assertOk()
            ->assertJsonPath('diagnostic_status', 'needs_attention')
            ->assertJsonPath('execution.task_problem_badge.code', 'replay_blocked');
        $findings = collect($debug->json('findings'))->keyBy('code');
        $this->assertSame('warning', $findings['task_problem']['severity']);
        $this->assertSame('error', $findings['workflow_replay_blocked']['severity']);
        $this->assertFalse($findings->has('task_recovery_history'));
    }

    private function diagnosticTask(string $workflowId): array
    {
        $workerId = $workflowId.'-worker';
        $queue = $workflowId.'-queue';
        $this->registerWorker($workerId, $queue, supportedWorkflowTypes: ['tests.await-approval-workflow']);
        $started = $this->postJson('/api/workflows', [
            'workflow_id' => $workflowId,
            'workflow_type' => 'tests.await-approval-workflow',
            'task_queue' => $queue,
        ], $this->controlPlaneHeadersWithWorkerProtocol())->assertCreated();
        $polled = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => $workerId, 'task_queue' => $queue,
        ], $this->workerHeaders())->assertOk();

        return [
            $started->json('run_id'),
            WorkflowTask::query()->findOrFail($polled->json('task.task_id')),
            $workerId,
            (int) $polled->json('task.workflow_task_attempt'),
        ];
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
            ->assertJsonPath('cancellation_cascade_supported', class_exists(CancellationCascadeView::class))
            ->assertJsonPath('cancellation_cascade', null)
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
                'activity_task_queues',
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

    public function test_debug_diagnostics_identify_no_compatible_worker_for_pinned_workflow_task(): void
    {
        $this->postJson('/api/worker/register', [
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
            'worker_id' => 'debug-worker-v1',
            'task_queue' => 'debug-queue',
            'runtime' => 'php',
            'sdk_version' => '1.0.0',
            'build_id' => 'build-v1',
            'supported_workflow_types' => ['tests.await-approval-workflow'],
        ], $this->workerHeaders())->assertCreated();

        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-debug-no-compatible-worker',
            'workflow_type' => 'tests.await-approval-workflow',
            'task_queue' => 'debug-queue',
        ], $this->controlPlaneHeadersWithWorkerProtocol());

        $start->assertCreated();

        WorkerCompatibilityFleet::clear();

        $this->postJson('/api/worker/register', [
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
            'worker_id' => 'debug-worker-v2',
            'task_queue' => 'debug-queue',
            'runtime' => 'php',
            'sdk_version' => '1.0.0',
            'build_id' => 'build-v2',
            'supported_workflow_types' => ['tests.await-approval-workflow'],
        ], $this->workerHeaders())->assertCreated();

        $debug = $this->getJson(
            '/api/workflows/wf-debug-no-compatible-worker/debug',
            $this->controlPlaneHeadersWithWorkerProtocol(),
        );

        $debug->assertOk()
            ->assertJsonPath('execution.compatibility', 'build-v1')
            ->assertJsonPath('pending_workflow_tasks.0.compatibility', 'build-v1')
            ->assertJsonPath('pending_workflow_tasks.0.compatibility_supported_in_fleet', false)
            ->assertJsonPath('findings.0.code', 'no_compatible_worker')
            ->assertJsonPath('findings.0.compatibility', 'build-v1')
            ->assertJsonMissing(['code' => 'pending_workflow_type_unsupported']);
    }

    public function test_debug_diagnostics_identify_pending_workflow_types_without_capable_workers(): void
    {
        $this->createNamespace('unrelated');
        $this->registerWorker(
            'debug-wrong-workflow-worker',
            'debug-workflow-queue',
            supportedWorkflowTypes: ['tests.interactive-command-workflow'],
        );
        $this->registerWorker(
            'unrelated-matching-workflow-worker',
            'debug-workflow-queue',
            namespace: 'unrelated',
            supportedWorkflowTypes: ['tests.await-approval-workflow'],
        );

        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-debug-workflow-unsupported',
            'workflow_type' => 'tests.await-approval-workflow',
            'task_queue' => 'debug-workflow-queue',
        ], $this->controlPlaneHeadersWithWorkerProtocol());

        $start->assertCreated()
            ->assertJsonPath('status', 'pending');
        $runId = (string) $start->json('run_id');

        $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'debug-wrong-workflow-worker',
            'task_queue' => 'debug-workflow-queue',
        ], $this->workerHeaders())
            ->assertOk()
            ->assertJsonPath('task', null);

        $debug = $this->getJson(
            '/api/workflows/wf-debug-workflow-unsupported/debug',
            $this->controlPlaneHeadersWithWorkerProtocol(),
        );

        $debug->assertOk()
            ->assertJsonPath('execution.status', 'pending')
            ->assertJsonPath('execution.workflow_type', 'tests.await-approval-workflow')
            ->assertJsonPath('pending_workflow_tasks.0.status', 'ready')
            ->assertJsonPath('findings.0.code', 'pending_workflow_type_unsupported')
            ->assertJsonPath('findings.0.task_queue', 'debug-workflow-queue')
            ->assertJsonPath('findings.0.workflow_type', 'tests.await-approval-workflow')
            ->assertJsonPath('findings.0.workflow_type_match', 'exact_case_sensitive')
            ->assertJsonPath('findings.0.active_worker_count', 1)
            ->assertJsonPath('findings.0.active_workers.0.worker_id', 'debug-wrong-workflow-worker')
            ->assertJsonPath(
                'findings.0.active_workers.0.supported_workflow_types',
                ['tests.interactive-command-workflow'],
            )
            ->assertJsonMissing(['code' => 'no_active_pollers'])
            ->assertJsonMissing(['worker_id' => 'unrelated-matching-workflow-worker']);

        $this->assertStringContainsString('exact and case-sensitive', (string) $debug->json('findings.0.message'));
        $this->assertStringContainsString('tests.await-approval-workflow', (string) $debug->json('findings.0.message'));
        $this->assertStringContainsString('debug-workflow-queue', (string) $debug->json('findings.0.message'));

        $this->registerWorker(
            'debug-matching-workflow-worker',
            'debug-workflow-queue',
            supportedWorkflowTypes: ['tests.await-approval-workflow'],
        );

        $this->getJson(
            '/api/workflows/wf-debug-workflow-unsupported/debug',
            $this->controlPlaneHeadersWithWorkerProtocol(),
        )
            ->assertOk()
            ->assertJsonMissing(['code' => 'pending_workflow_type_unsupported']);

        $poll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'debug-matching-workflow-worker',
            'task_queue' => 'debug-workflow-queue',
        ], $this->workerHeaders());

        $poll->assertOk()
            ->assertJsonPath('task.run_id', $runId)
            ->assertJsonPath('task.workflow_type', 'tests.await-approval-workflow');

        $taskId = (string) $poll->json('task.task_id');
        $complete = $this->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
            'lease_owner' => 'debug-matching-workflow-worker',
            'workflow_task_attempt' => (int) $poll->json('task.workflow_task_attempt'),
            'commands' => [[
                'type' => 'complete_workflow',
                'result' => Serializer::serializeWithCodec(
                    (string) config('workflows.serializer'),
                    ['matched_worker_completed' => true],
                ),
            ]],
        ], $this->workerHeaders());

        $complete->assertOk()
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('run_status', 'completed');

        $this->getJson(
            "/api/workflows/wf-debug-workflow-unsupported/runs/{$runId}",
            $this->controlPlaneHeadersWithWorkerProtocol(),
        )
            ->assertOk()
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('status', 'completed');
    }

    public function test_debug_diagnostics_bound_workflow_type_worker_evidence_to_the_run_namespace(): void
    {
        $this->createNamespace('unrelated');
        $advertisedTypes = array_map(
            static fn (int $index): string => sprintf('tests.other-workflow-%02d', $index),
            range(1, 25),
        );

        foreach (range(1, 12) as $index) {
            $this->registerWorker(
                sprintf('debug-wrong-workflow-worker-%02d', $index),
                'debug-bounded-workflow-queue',
                supportedWorkflowTypes: $advertisedTypes,
            );
        }

        $this->registerWorker(
            'unrelated-secret-workflow-worker',
            'debug-bounded-workflow-queue',
            namespace: 'unrelated',
            supportedWorkflowTypes: ['tests.await-approval-workflow', 'secret.unrelated-workflow'],
        );

        $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-debug-workflow-bounded',
            'workflow_type' => 'tests.await-approval-workflow',
            'task_queue' => 'debug-bounded-workflow-queue',
        ], $this->controlPlaneHeadersWithWorkerProtocol())
            ->assertCreated();

        $debug = $this->getJson(
            '/api/workflows/wf-debug-workflow-bounded/debug',
            $this->controlPlaneHeadersWithWorkerProtocol(),
        );

        $debug->assertOk()
            ->assertJsonPath('findings.0.code', 'pending_workflow_type_unsupported')
            ->assertJsonPath('findings.0.active_worker_count', 12)
            ->assertJsonPath('findings.0.active_worker_limit', 10)
            ->assertJsonPath('findings.0.active_workers_truncated', true)
            ->assertJsonPath('findings.0.active_workers.0.supported_workflow_type_count', 25)
            ->assertJsonPath('findings.0.active_workers.0.supported_workflow_type_limit', 20)
            ->assertJsonPath('findings.0.active_workers.0.supported_workflow_types_truncated', true);

        $this->assertCount(10, $debug->json('findings.0.active_workers'));
        $this->assertCount(20, $debug->json('findings.0.active_workers.0.supported_workflow_types'));
        $this->assertStringNotContainsString('unrelated-secret-workflow-worker', $debug->getContent());
        $this->assertStringNotContainsString('secret.unrelated-workflow', $debug->getContent());
    }

    public function test_debug_diagnostics_identify_pending_activity_queues_without_active_pollers(): void
    {
        $this->registerWorker(
            'debug-workflow-worker',
            'debug-workflow-queue',
            supportedWorkflowTypes: ['tests.await-approval-workflow'],
        );

        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-debug-activity-no-poller',
            'workflow_type' => 'tests.await-approval-workflow',
            'task_queue' => 'debug-workflow-queue',
        ], $this->controlPlaneHeadersWithWorkerProtocol());

        $start->assertCreated();
        $run = WorkflowRun::query()->findOrFail((string) $start->json('run_id'));
        $activity = $this->createDiagnosticActivity(
            $run,
            1,
            ActivityStatus::Running,
            ActivityAttemptStatus::Running,
            1,
            [
                'activity_type' => 'polyglot.python-to-php.echo',
                'queue' => 'polyglot-python-to-php',
            ],
        );

        $debug = $this->getJson(
            '/api/workflows/wf-debug-activity-no-poller/debug',
            $this->controlPlaneHeadersWithWorkerProtocol(),
        );

        $debug->assertOk()
            ->assertJsonPath('pending_activities.0.activity_execution_id', $activity->id)
            ->assertJsonPath('pending_activities.0.activity_type', 'polyglot.python-to-php.echo')
            ->assertJsonPath('pending_activities.0.queue', 'polyglot-python-to-php')
            ->assertJsonPath('activity_task_queues.polyglot-python-to-php.name', 'polyglot-python-to-php')
            ->assertJsonPath('activity_task_queues.polyglot-python-to-php.stats.pollers.active_count', 0)
            ->assertJsonPath('findings.0.code', 'pending_activity_queue_without_active_pollers')
            ->assertJsonPath('findings.0.task_queue', 'polyglot-python-to-php')
            ->assertJsonPath('findings.0.activity_type', 'polyglot.python-to-php.echo')
            ->assertJsonPath('findings.0.activity_execution_id', $activity->id);
    }

    public function test_debug_diagnostics_treat_draining_workflow_pollers_as_inactive(): void
    {
        $this->registerWorker(
            'debug-draining-workflow-worker',
            'debug-workflow-queue',
            supportedWorkflowTypes: ['tests.await-approval-workflow'],
        );

        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-debug-workflow-draining-poller',
            'workflow_type' => 'tests.await-approval-workflow',
            'task_queue' => 'debug-workflow-queue',
        ], $this->controlPlaneHeadersWithWorkerProtocol());

        $start->assertCreated();

        DB::table('workflow_worker_registrations')
            ->where('worker_id', 'debug-draining-workflow-worker')
            ->update(['status' => 'draining']);

        $debug = $this->getJson(
            '/api/workflows/wf-debug-workflow-draining-poller/debug',
            $this->controlPlaneHeadersWithWorkerProtocol(),
        );

        $debug->assertOk()
            ->assertJsonPath('task_queue.pollers.0.worker_id', 'debug-draining-workflow-worker')
            ->assertJsonPath('task_queue.pollers.0.status', 'draining')
            ->assertJsonPath('task_queue.stats.pollers.active_count', 0)
            ->assertJsonPath('findings.0.code', 'no_active_pollers');
    }

    public function test_debug_diagnostics_treat_draining_activity_pollers_as_inactive(): void
    {
        $this->registerWorker(
            'debug-workflow-worker',
            'debug-workflow-queue',
            supportedWorkflowTypes: ['tests.await-approval-workflow'],
        );
        $this->registerWorker(
            'debug-draining-activity-worker',
            'polyglot-python-to-php',
            supportedWorkflowTypes: [],
            supportedActivityTypes: ['polyglot.python-to-php.echo'],
        );

        DB::table('workflow_worker_registrations')
            ->where('worker_id', 'debug-draining-activity-worker')
            ->update(['status' => 'draining']);

        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-debug-activity-draining-poller',
            'workflow_type' => 'tests.await-approval-workflow',
            'task_queue' => 'debug-workflow-queue',
        ], $this->controlPlaneHeadersWithWorkerProtocol());

        $start->assertCreated();
        $run = WorkflowRun::query()->findOrFail((string) $start->json('run_id'));
        $activity = $this->createDiagnosticActivity(
            $run,
            1,
            ActivityStatus::Running,
            ActivityAttemptStatus::Running,
            1,
            [
                'activity_type' => 'polyglot.python-to-php.echo',
                'queue' => 'polyglot-python-to-php',
            ],
        );

        $debug = $this->getJson(
            '/api/workflows/wf-debug-activity-draining-poller/debug',
            $this->controlPlaneHeadersWithWorkerProtocol(),
        );

        $debug->assertOk()
            ->assertJsonPath('activity_task_queues.polyglot-python-to-php.pollers.0.worker_id', 'debug-draining-activity-worker')
            ->assertJsonPath('activity_task_queues.polyglot-python-to-php.pollers.0.status', 'draining')
            ->assertJsonPath('activity_task_queues.polyglot-python-to-php.stats.pollers.active_count', 0)
            ->assertJsonPath('findings.0.code', 'pending_activity_queue_without_active_pollers')
            ->assertJsonPath('findings.0.task_queue', 'polyglot-python-to-php')
            ->assertJsonPath('findings.0.activity_type', 'polyglot.python-to-php.echo')
            ->assertJsonPath('findings.0.activity_execution_id', $activity->id)
            ->assertJsonMissing(['code' => 'pending_activity_type_unsupported']);
    }

    public function test_debug_diagnostics_identify_pending_activity_types_without_capable_workers(): void
    {
        $this->registerWorker(
            'debug-workflow-worker',
            'debug-workflow-queue',
            supportedWorkflowTypes: ['tests.await-approval-workflow'],
        );
        $this->registerWorker(
            'debug-wrong-activity-worker',
            'polyglot-python-to-php',
            supportedWorkflowTypes: [],
            supportedActivityTypes: ['polyglot.other.echo'],
        );

        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-debug-activity-unsupported',
            'workflow_type' => 'tests.await-approval-workflow',
            'task_queue' => 'debug-workflow-queue',
        ], $this->controlPlaneHeadersWithWorkerProtocol());

        $start->assertCreated();
        $run = WorkflowRun::query()->findOrFail((string) $start->json('run_id'));
        $activity = $this->createDiagnosticActivity(
            $run,
            1,
            ActivityStatus::Running,
            ActivityAttemptStatus::Running,
            1,
            [
                'activity_type' => 'polyglot.python-to-php.echo',
                'queue' => 'polyglot-python-to-php',
            ],
        );

        $debug = $this->getJson(
            '/api/workflows/wf-debug-activity-unsupported/debug',
            $this->controlPlaneHeadersWithWorkerProtocol(),
        );

        $debug->assertOk()
            ->assertJsonPath('activity_task_queues.polyglot-python-to-php.stats.pollers.active_count', 1)
            ->assertJsonPath('activity_task_queues.polyglot-python-to-php.pollers.0.worker_id', 'debug-wrong-activity-worker')
            ->assertJsonPath('findings.0.code', 'pending_activity_type_unsupported')
            ->assertJsonPath('findings.0.task_queue', 'polyglot-python-to-php')
            ->assertJsonPath('findings.0.activity_type', 'polyglot.python-to-php.echo')
            ->assertJsonPath('findings.0.activity_execution_id', $activity->id)
            ->assertJsonPath('findings.0.active_workers.0.worker_id', 'debug-wrong-activity-worker')
            ->assertJsonPath('findings.0.active_workers.0.supported_activity_types', ['polyglot.other.echo']);
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
        ]);

        $debug = $this->getJson(
            '/api/workflows/wf-debug-large-last-event/debug',
            $this->controlPlaneHeadersWithWorkerProtocol(),
        );

        $debug->assertOk()
            ->assertJsonPath('execution.last_event.event_type', HistoryEventType::SideEffectRecorded->value)
            ->assertJsonPath('execution.last_event.payload_summary.included', false)
            ->assertJsonPath('execution.last_event.payload_summary.top_level_keys', ['result'])
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

    public function test_debug_diagnostics_remain_available_when_bootstrap_migrations_are_pending(): void
    {
        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-debug-bootstrap-gate',
            'workflow_type' => 'tests.await-approval-workflow',
            'task_queue' => 'debug-queue',
        ], $this->controlPlaneHeadersWithWorkerProtocol());

        $start->assertCreated();

        DB::table('migrations')
            ->where('migration', '2026_04_21_000300_add_workflow_definition_fingerprints_to_worker_registrations')
            ->delete();

        $this->getJson(
            '/api/workflows/wf-debug-bootstrap-gate/debug',
            $this->controlPlaneHeadersWithWorkerProtocol(),
        )->assertOk()
            ->assertJsonPath('workflow_id', 'wf-debug-bootstrap-gate')
            ->assertJsonMissing(['reason' => 'workflow_v2_blocked']);
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
        array $attributes = [],
        array $attemptAttributes = [],
    ): ActivityExecution {
        $execution = ActivityExecution::query()->create(array_merge([
            'workflow_run_id' => $run->id,
            'sequence' => $sequence,
            'activity_class' => 'Tests\\Fixtures\\DebugActivity',
            'activity_type' => sprintf('debug.activity.%d', $sequence),
            'status' => $status->value,
            'connection' => $run->connection,
            'queue' => $run->queue,
            'attempt_count' => $attemptCount,
            'started_at' => now()->addSeconds($sequence),
        ], $attributes));

        $currentAttempt = null;

        for ($attemptNumber = 1; $attemptNumber <= $attemptCount; $attemptNumber++) {
            $currentAttempt = ActivityAttempt::query()->create(array_merge([
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
            ], $attemptAttributes));
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
