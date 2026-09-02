<?php

namespace Tests\Feature;

use App\Models\RuntimeExternalPayload;
use App\Models\WorkerBuildIdRollout;
use App\Models\WorkerRegistration;
use App\Models\WorkflowNamespace;
use App\Support\ControlPlaneProtocol;
use App\Support\ExternalPayloadEnvelopeService;
use App\Support\LongPollCapacityExhaustedException;
use App\Support\LongPoller;
use App\Support\LongPollSignalStore;
use App\Support\LongPollWaitSlotStore;
use App\Support\QueryTaskPollRequestStore;
use App\Support\QueryTaskQueueFullException;
use App\Support\RuntimeExternalPayloadRegistry;
use App\Support\ServerPollingCache;
use App\Support\WorkerProtocol;
use App\Support\WorkflowQueryTaskBroker;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Lock as CacheLock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Store as CacheStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\CommandContext;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ExternalPayloads;

class WorkflowQueryTaskBrokerTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'server.polling.timeout' => 0,
            'server.query_tasks.timeout' => 0,
        ]);

        $this->createNamespace('default');
    }

    public function test_worker_can_poll_and_complete_worker_routed_query_task(): void
    {
        Queue::fake();

        $run = $this->startRemoteWorkflow('wf-query-task-complete');
        $this->registerPythonWorker('python-query-worker', 'python-queries', ['python.queryable']);

        /** @var WorkflowQueryTaskBroker $broker */
        $broker = app(WorkflowQueryTaskBroker::class);
        $queryArguments = [
            'codec' => 'avro',
            'blob' => Serializer::serializeWithCodec('avro', ['summary']),
        ];
        $task = $broker->enqueue('default', $run, 'status', [
            'codec' => $queryArguments['codec'],
            'blob' => $queryArguments['blob'],
        ]);

        $poll = $this->postJson('/api/worker/query-tasks/poll', [
            'worker_id' => 'python-query-worker',
            'task_queue' => 'python-queries',
        ], $this->workerHeaders());

        $poll->assertOk()
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertHeaderMissing(ControlPlaneProtocol::HEADER)
            ->assertJsonPath('protocol_version', WorkerProtocol::VERSION)
            ->assertJsonPath('server_capabilities.query_tasks', true)
            ->assertJsonPath('server_capabilities.query_task_poll_request_idempotency', true)
            ->assertJsonPath('task.query_task_id', $task['query_task_id'])
            ->assertJsonPath('task.query_task_attempt', 1)
            ->assertJsonPath('task.workflow_id', 'wf-query-task-complete')
            ->assertJsonPath('task.run_id', $run->id)
            ->assertJsonPath('task.workflow_type', 'python.queryable')
            ->assertJsonPath('task.workflow_class', 'python.queryable')
            ->assertJsonPath('task.query_name', 'status')
            ->assertJsonPath('task.task_queue', 'python-queries')
            ->assertJsonPath('task.lease_owner', 'python-query-worker')
            ->assertJsonPath('task.query_arguments.codec', 'avro')
            ->assertJsonPath('task.history_export.schema', 'durable-workflow.v2.history-export')
            ->assertJsonPath('task.history_export.workflow.workflow_type', 'python.queryable')
            ->assertJsonPath('task.history_export.workflow.workflow_class', 'python.queryable')
            ->assertJsonPath('task.history_export.payloads.codec', 'avro');

        $pollTask = $poll->json('task');

        $this->assertSame(
            ['Ada'],
            Serializer::unserializeWithCodec(
                (string) $pollTask['workflow_arguments']['codec'],
                (string) $pollTask['workflow_arguments']['blob'],
            ),
        );
        $this->assertSame(
            ['summary'],
            Serializer::unserializeWithCodec(
                (string) $pollTask['query_arguments']['codec'],
                (string) $pollTask['query_arguments']['blob'],
            ),
        );
        $this->assertContains(
            'WorkflowStarted',
            array_column($pollTask['history_events'], 'event_type'),
        );

        $complete = $this->postJson("/api/worker/query-tasks/{$task['query_task_id']}/complete", [
            'lease_owner' => 'python-query-worker',
            'query_task_attempt' => 1,
            'result' => ['status' => 'ready'],
            'result_envelope' => [
                'codec' => 'avro',
                'blob' => Serializer::serializeWithCodec('avro', ['status' => 'ready']),
            ],
        ], $this->workerHeaders());

        $complete->assertOk()
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertJsonPath('query_task_id', $task['query_task_id'])
            ->assertJsonPath('query_task_attempt', 1)
            ->assertJsonPath('outcome', 'completed');

        $stored = $broker->task((string) $task['query_task_id']);

        $this->assertSame('completed', $stored['status'] ?? null);
        $this->assertSame(['status' => 'ready'], $stored['result'] ?? null);
        $this->assertSame('avro', $stored['result_envelope']['codec'] ?? null);
        $this->assertSame(
            ['status' => 'ready'],
            Serializer::unserializeWithCodec(
                'avro',
                (string) ($stored['result_envelope']['blob'] ?? ''),
            ),
        );
    }

    public function test_worker_routed_query_task_history_enriches_signal_received_arguments(): void
    {
        Queue::fake();

        $this->registerPythonWorker(
            'python-query-signal-history-worker',
            'python-queries',
            ['python.queryable'],
            workflowCommandContracts: [
                'python.queryable' => [
                    'queries' => ['currentTotal'],
                    'query_contracts' => [
                        [
                            'name' => 'currentTotal',
                            'parameters' => [],
                        ],
                    ],
                    'signals' => ['increment'],
                    'signal_contracts' => [
                        [
                            'name' => 'increment',
                            'parameters' => [
                                $this->typedCommandParameter('amount', 0, 'int'),
                            ],
                        ],
                    ],
                    'updates' => [],
                    'update_contracts' => [],
                ],
            ],
        );
        $run = $this->startRemoteWorkflow('wf-query-task-signal-history');

        foreach ([1, 2] as $sequence => $amount) {
            $signal = $this->postJson("/api/workflows/{$run->workflow_instance_id}/signal/increment", [
                'input' => ['amount' => $amount],
                'request_id' => "query-task-signal-history-{$amount}",
            ], $this->apiHeaders());

            $signal->assertAccepted()
                ->assertJsonPath('signal_name', 'increment')
                ->assertJsonPath('command_status', 'accepted')
                ->assertJsonPath('outcome', 'signal_received');

            /** @var WorkflowSignal $recordedSignal */
            $recordedSignal = WorkflowSignal::query()
                ->where('workflow_command_id', (string) $signal->json('command_id'))
                ->sole();
            $recordedSignal->forceFill([
                'workflow_sequence' => $sequence + 1,
            ])->save();
        }

        /** @var WorkflowQueryTaskBroker $broker */
        $broker = app(WorkflowQueryTaskBroker::class);
        $task = $broker->enqueue('default', $run->refresh(), 'currentTotal', $this->queryArguments());

        $poll = $this->postJson('/api/worker/query-tasks/poll', [
            'worker_id' => 'python-query-signal-history-worker',
            'task_queue' => 'python-queries',
        ], $this->workerHeaders());

        $poll->assertOk()
            ->assertJsonPath('poll_status', 'leased')
            ->assertJsonPath('task.query_task_id', $task['query_task_id'])
            ->assertJsonPath('task.workflow_id', 'wf-query-task-signal-history')
            ->assertJsonPath('task.query_name', 'currentTotal');

        $signalReceived = collect($poll->json('task.history_events'))
            ->filter(static fn (array $event): bool => ($event['event_type'] ?? null) === 'SignalReceived')
            ->values();

        $this->assertCount(2, $signalReceived);
        $this->assertSame(
            [1, 2],
            $signalReceived
                ->map(static fn (array $event): mixed => $event['payload']['workflow_sequence'] ?? null)
                ->all(),
        );
        $this->assertSame(
            [1, 2],
            $signalReceived
                ->map(static function (array $event): mixed {
                    $arguments = $event['payload']['arguments'] ?? null;

                    return is_array($arguments)
                        ? Serializer::unserializeWithCodec(
                            (string) ($arguments['codec'] ?? ''),
                            (string) ($arguments['blob'] ?? ''),
                        )
                        : null;
                })
                ->map(static function (mixed $arguments): mixed {
                    if (! is_array($arguments)) {
                        return null;
                    }

                    if (array_key_exists('amount', $arguments)) {
                        return $arguments['amount'];
                    }

                    $first = $arguments[0] ?? null;

                    if (is_int($first)) {
                        return $first;
                    }

                    return is_array($first) ? ($first['amount'] ?? null) : null;
                })
                ->all(),
        );
    }

    public function test_query_task_poll_waits_for_same_worker_active_workflow_task_lease_before_claiming(): void
    {
        Queue::fake();

        $run = $this->startRemoteWorkflow('wf-query-task-same-worker-replay');
        $this->registerPythonWorker('python-query-same-worker-replay', 'python-queries', ['python.queryable']);

        $workflowPoll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'python-query-same-worker-replay',
            'task_queue' => 'python-queries',
        ], $this->workerHeaders());

        $workflowPoll->assertOk()
            ->assertJsonPath('poll_status', 'leased')
            ->assertJsonPath('task.run_id', $run->id)
            ->assertJsonPath('task.lease_owner', 'python-query-same-worker-replay');

        /** @var WorkflowQueryTaskBroker $broker */
        $broker = app(WorkflowQueryTaskBroker::class);
        $task = $broker->enqueue('default', $run, 'status', $this->queryArguments());

        $queryPoll = $this->postJson('/api/worker/query-tasks/poll', [
            'worker_id' => 'python-query-same-worker-replay',
            'task_queue' => 'python-queries',
            'timeout_seconds' => 0,
        ], $this->workerHeaders());

        $queryPoll->assertOk()
            ->assertJsonPath('poll_status', 'workflow_task_leased')
            ->assertJsonPath('task', null);

        $this->assertSame('pending', $broker->task((string) $task['query_task_id'])['status'] ?? null);

        $workflowTaskId = (string) $workflowPoll->json('task.task_id');
        $workflowTaskAttempt = (int) $workflowPoll->json('task.workflow_task_attempt');
        $workflowLeaseOwner = (string) $workflowPoll->json('task.lease_owner');

        $this->postJson("/api/worker/workflow-tasks/{$workflowTaskId}/complete", [
            'lease_owner' => $workflowLeaseOwner,
            'workflow_task_attempt' => $workflowTaskAttempt,
            'commands' => [
                [
                    'type' => 'open_condition_wait',
                    'condition_key' => 'query-same-worker-replay-barrier',
                    'timeout_seconds' => 60,
                ],
            ],
        ], $this->workerHeaders())
            ->assertOk()
            ->assertJsonPath('run_status', 'waiting');

        $afterReplay = $this->postJson('/api/worker/query-tasks/poll', [
            'worker_id' => 'python-query-same-worker-replay',
            'task_queue' => 'python-queries',
        ], $this->workerHeaders());

        $afterReplay->assertOk()
            ->assertJsonPath('poll_status', 'leased')
            ->assertJsonPath('task.query_task_id', $task['query_task_id'])
            ->assertJsonPath('task.run_id', $run->id)
            ->assertJsonPath('task.lease_owner', 'python-query-same-worker-replay');
    }

    public function test_query_task_poll_waits_for_other_worker_active_workflow_task_lease_before_claiming(): void
    {
        Queue::fake();

        $run = $this->startRemoteWorkflow('wf-query-task-replay-barrier');
        $this->registerPythonWorker('python-query-replay-barrier-worker', 'python-queries', ['python.queryable']);
        $this->registerPythonWorker('python-query-replay-barrier-query-worker', 'python-queries', ['python.queryable']);

        $workflowPoll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'python-query-replay-barrier-worker',
            'task_queue' => 'python-queries',
        ], $this->workerHeaders());

        $workflowPoll->assertOk()
            ->assertJsonPath('poll_status', 'leased')
            ->assertJsonPath('task.run_id', $run->id);

        /** @var WorkflowQueryTaskBroker $broker */
        $broker = app(WorkflowQueryTaskBroker::class);
        $task = $broker->enqueue('default', $run, 'status', $this->queryArguments());

        $duringReplay = $this->postJson('/api/worker/query-tasks/poll', [
            'worker_id' => 'python-query-replay-barrier-query-worker',
            'task_queue' => 'python-queries',
            'timeout_seconds' => 0,
        ], $this->workerHeaders());

        $duringReplay->assertOk()
            ->assertJsonPath('poll_status', 'workflow_task_leased')
            ->assertJsonPath('task', null);

        $this->assertSame('pending', $broker->task((string) $task['query_task_id'])['status'] ?? null);

        $workflowTaskId = (string) $workflowPoll->json('task.task_id');
        $workflowTaskAttempt = (int) $workflowPoll->json('task.workflow_task_attempt');
        $workflowLeaseOwner = (string) $workflowPoll->json('task.lease_owner');

        $this->postJson("/api/worker/workflow-tasks/{$workflowTaskId}/complete", [
            'lease_owner' => $workflowLeaseOwner,
            'workflow_task_attempt' => $workflowTaskAttempt,
            'commands' => [
                [
                    'type' => 'open_condition_wait',
                    'condition_key' => 'query-replay-barrier',
                    'timeout_seconds' => 60,
                ],
            ],
        ], $this->workerHeaders())
            ->assertOk()
            ->assertJsonPath('run_status', 'waiting');

        $afterReplay = $this->postJson('/api/worker/query-tasks/poll', [
            'worker_id' => 'python-query-replay-barrier-worker',
            'task_queue' => 'python-queries',
        ], $this->workerHeaders());

        $afterReplay->assertOk()
            ->assertJsonPath('poll_status', 'leased')
            ->assertJsonPath('task.query_task_id', $task['query_task_id'])
            ->assertJsonPath('task.run_id', $run->id);
    }

    public function test_distinct_worker_id_recovers_stale_workflow_lease_before_query_deadline(): void
    {
        Queue::fake();
        config([
            'server.workers.stale_after_seconds' => 3,
            'server.query_tasks.timeout' => 20,
        ]);
        Carbon::setTestNow(Carbon::parse('2026-07-11 08:00:00'));

        try {
            $run = $this->startRemoteWorkflow('wf-query-distinct-worker-replacement');
            $this->registerPythonWorker('python-query-worker-old', 'python-queries', ['python.queryable']);

            $oldPoll = $this->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'python-query-worker-old',
                'task_queue' => 'python-queries',
            ], $this->workerHeaders());

            $oldPoll->assertOk()
                ->assertJsonPath('poll_status', 'leased')
                ->assertJsonPath('task.run_id', $run->id)
                ->assertJsonPath('task.workflow_task_attempt', 1);

            /** @var WorkflowQueryTaskBroker $broker */
            $broker = app(WorkflowQueryTaskBroker::class);
            $queryTask = $broker->enqueue('default', $run->refresh(), 'current', $this->queryArguments());
            $this->assertArrayHasKey('history_cutoff_sequence', $queryTask);

            Carbon::setTestNow(now()->addSeconds(5));
            $this->registerPythonWorker('python-query-worker-new', 'python-queries', ['python.queryable']);

            $leasedBeforeRecovery = WorkflowTask::query()->findOrFail((string) $oldPoll->json('task.task_id'));
            $this->assertSame(TaskStatus::Leased, $leasedBeforeRecovery->status);
            $this->assertSame('python-query-worker-old', $leasedBeforeRecovery->lease_owner);
            $this->assertTrue($leasedBeforeRecovery->lease_expires_at->isFuture());

            $this->postJson("/api/worker/workflow-tasks/{$oldPoll->json('task.task_id')}/complete", [
                'lease_owner' => 'python-query-worker-old',
                'workflow_task_attempt' => 1,
                'commands' => [
                    [
                        'type' => 'complete_workflow',
                        'result' => Serializer::serializeWithCodec('avro', ['current' => 999]),
                    ],
                ],
            ], $this->workerHeaders())
                ->assertStatus(409)
                ->assertJsonPath('reason', 'stale_worker_registration')
                ->assertJsonPath('lease_owner', 'python-query-worker-old');

            $replacementPoll = $this->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'python-query-worker-new',
                'task_queue' => 'python-queries',
            ], $this->workerHeaders());

            $replacementPoll->assertOk()
                ->assertJsonPath('poll_status', 'leased')
                ->assertJsonPath('task.task_id', $oldPoll->json('task.task_id'))
                ->assertJsonPath('task.run_id', $run->id)
                ->assertJsonPath('task.lease_owner', 'python-query-worker-new')
                ->assertJsonPath('task.workflow_task_attempt', 2);

            $this->postJson('/api/worker/heartbeat', [
                'worker_id' => 'python-query-worker-old',
            ], $this->workerHeaders())
                ->assertStatus(409)
                ->assertJsonPath('reason', 'worker_registration_superseded');

            $this->postJson("/api/worker/workflow-tasks/{$oldPoll->json('task.task_id')}/complete", [
                'lease_owner' => 'python-query-worker-old',
                'workflow_task_attempt' => 1,
                'commands' => [
                    [
                        'type' => 'complete_workflow',
                        'result' => Serializer::serializeWithCodec('avro', ['current' => 999]),
                    ],
                ],
            ], $this->workerHeaders())
                ->assertStatus(409)
                ->assertJsonPath('reason', 'lease_owner_mismatch')
                ->assertJsonPath('lease_owner', 'python-query-worker-new');

            $this->postJson("/api/worker/workflow-tasks/{$replacementPoll->json('task.task_id')}/complete", [
                'lease_owner' => 'python-query-worker-new',
                'workflow_task_attempt' => 2,
                'commands' => [
                    [
                        'type' => 'open_condition_wait',
                        'condition_key' => 'replacement-query-ready',
                        'timeout_seconds' => 300,
                    ],
                ],
            ], $this->workerHeaders())
                ->assertOk()
                ->assertJsonPath('run_status', 'waiting');

            $queryPoll = $this->postJson('/api/worker/query-tasks/poll', [
                'worker_id' => 'python-query-worker-new',
                'task_queue' => 'python-queries',
                'timeout_seconds' => 0,
            ], $this->workerHeaders());

            $queryPoll->assertOk()
                ->assertJsonPath('poll_status', 'leased')
                ->assertJsonPath('task.query_task_id', $queryTask['query_task_id'])
                ->assertJsonPath('task.lease_owner', 'python-query-worker-new')
                ->assertJsonPath('task.query_task_attempt', 1);

            $historyTypes = array_column($queryPoll->json('task.history_events'), 'event_type');
            $this->assertSame(1, count(array_filter($historyTypes, static fn (string $type): bool => $type === 'WorkflowStarted')));
            $this->assertSame(
                1,
                WorkflowHistoryEvent::query()
                    ->where('workflow_run_id', $run->id)
                    ->where('event_type', HistoryEventType::RepairRequested->value)
                    ->count(),
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_distinct_worker_id_reclaims_stale_query_lease_and_fences_old_attempt(): void
    {
        Queue::fake();
        config(['server.workers.stale_after_seconds' => 3]);
        Carbon::setTestNow(Carbon::parse('2026-07-11 09:00:00'));

        try {
            $run = $this->startRemoteWorkflow('wf-query-lease-worker-replacement');
            WorkflowTask::query()->where('workflow_run_id', $run->id)->delete();
            $this->registerPythonWorker('python-query-lease-old', 'python-queries', ['python.queryable']);

            /** @var WorkflowQueryTaskBroker $broker */
            $broker = app(WorkflowQueryTaskBroker::class);
            $task = $broker->enqueue('default', $run, 'current', $this->queryArguments());

            $oldPoll = $this->postJson('/api/worker/query-tasks/poll', [
                'worker_id' => 'python-query-lease-old',
                'task_queue' => 'python-queries',
                'poll_request_id' => 'query-worker-old-poll-1',
                'timeout_seconds' => 0,
            ], $this->workerHeaders());

            $oldPoll->assertOk()
                ->assertJsonPath('poll_status', 'leased')
                ->assertJsonPath('task.query_task_id', $task['query_task_id'])
                ->assertJsonPath('task.query_task_attempt', 1);

            Carbon::setTestNow(now()->addSeconds(5));
            $this->registerPythonWorker('python-query-lease-new', 'python-queries', ['python.queryable']);

            $this->postJson("/api/worker/query-tasks/{$task['query_task_id']}/complete", [
                'lease_owner' => 'python-query-lease-old',
                'query_task_attempt' => 1,
                'result' => ['current' => 999],
            ], $this->workerHeaders())
                ->assertStatus(409)
                ->assertJsonPath('reason', 'stale_worker_registration')
                ->assertJsonPath('lease_owner', 'python-query-lease-old');

            // The control-plane result probe owns abandoned query-lease
            // recovery. A zero timeout still performs the initial probe.
            $broker->waitForResult((string) $task['query_task_id']);

            $replacementPoll = $this->postJson('/api/worker/query-tasks/poll', [
                'worker_id' => 'python-query-lease-new',
                'task_queue' => 'python-queries',
                'poll_request_id' => 'query-worker-new-poll-1',
                'timeout_seconds' => 0,
            ], $this->workerHeaders());

            $replacementPoll->assertOk()
                ->assertJsonPath('poll_status', 'leased')
                ->assertJsonPath('task.query_task_id', $task['query_task_id'])
                ->assertJsonPath('task.lease_owner', 'python-query-lease-new')
                ->assertJsonPath('task.query_task_attempt', 2);

            $this->postJson("/api/worker/query-tasks/{$task['query_task_id']}/complete", [
                'lease_owner' => 'python-query-lease-old',
                'query_task_attempt' => 1,
                'result' => ['current' => 999],
            ], $this->workerHeaders())
                ->assertStatus(409)
                ->assertJsonPath('reason', 'lease_owner_mismatch')
                ->assertJsonPath('lease_owner', 'python-query-lease-new');

            $this->postJson("/api/worker/query-tasks/{$task['query_task_id']}/complete", [
                'lease_owner' => 'python-query-lease-new',
                'query_task_attempt' => 2,
                'result' => ['current' => 5],
            ], $this->workerHeaders())
                ->assertOk()
                ->assertJsonPath('outcome', 'completed');

            $stored = $broker->task((string) $task['query_task_id']);
            $this->assertSame('completed', $stored['status'] ?? null);
            $this->assertSame(['current' => 5], $stored['result'] ?? null);
            $this->assertSame(2, $stored['attempt_count'] ?? null);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_query_task_long_poll_waits_while_ready_workflow_resume_task_blocks_claim(): void
    {
        Queue::fake();
        config([
            'server.polling.timeout' => 1,
            'server.query_tasks.timeout' => 1,
        ]);

        $workerId = 'python-query-ready-resume-wait-worker';
        $this->registerPythonWorker(
            $workerId,
            'python-queries',
            ['python.queryable'],
            workflowCommandContracts: [
                'python.queryable' => $this->querySignalUpdateCommandContract(),
            ],
        );
        $run = $this->startRemoteWorkflow('wf-query-task-ready-resume-long-poll');

        $initialPoll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => $workerId,
            'task_queue' => 'python-queries',
        ], $this->workerHeaders());

        $initialPoll->assertOk()
            ->assertJsonPath('poll_status', 'leased')
            ->assertJsonPath('task.run_id', $run->id);

        $this->postJson("/api/worker/workflow-tasks/{$initialPoll->json('task.task_id')}/complete", [
            'lease_owner' => $initialPoll->json('task.lease_owner'),
            'workflow_task_attempt' => $initialPoll->json('task.workflow_task_attempt'),
            'commands' => [
                [
                    'type' => 'open_signal_wait',
                    'signal_name' => 'increment',
                    'timeout_seconds' => 60,
                ],
            ],
        ], $this->workerHeaders())
            ->assertOk()
            ->assertJsonPath('run_status', 'waiting');

        /** @var WorkflowQueryTaskBroker $broker */
        $broker = app(WorkflowQueryTaskBroker::class);
        $queryTask = $broker->enqueue('default', $run->refresh(), 'current', $this->queryArguments());
        $this->assertArrayNotHasKey('history_cutoff_sequence', $queryTask);

        $this->postJson('/api/workflows/wf-query-task-ready-resume-long-poll/signal/increment', [
            'input' => ['amount' => 5],
            'request_id' => 'ready-resume-long-poll-signal-1',
        ], $this->apiHeaders())
            ->assertAccepted()
            ->assertJsonPath('command_status', 'accepted');

        /** @var WorkflowTask $readyResumeTask */
        $readyResumeTask = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', 'workflow')
            ->where('status', 'ready')
            ->where('payload->resume_source_kind', 'workflow_signal')
            ->firstOrFail();

        /** @var LongPollSignalStore $signals */
        $signals = app(LongPollSignalStore::class);
        /** @var LongPollWaitSlotStore $waitSlots */
        $waitSlots = app(LongPollWaitSlotStore::class);
        $poller = new class($signals, $waitSlots) extends LongPoller
        {
            public int $pauseCalls = 0;

            /** @var callable(int): void|null */
            public $afterPause = null;

            protected function pause(int $milliseconds): void
            {
                $this->pauseCalls++;

                if (is_callable($this->afterPause)) {
                    ($this->afterPause)($this->pauseCalls);
                }
            }
        };

        $waitingBroker = new WorkflowQueryTaskBroker(
            app(ServerPollingCache::class),
            $poller,
            $signals,
            app(ExternalPayloadEnvelopeService::class),
            app(QueryTaskPollRequestStore::class),
        );

        /** @var WorkerRegistration $worker */
        $worker = WorkerRegistration::query()
            ->where('namespace', 'default')
            ->where('worker_id', $workerId)
            ->firstOrFail();

        $blockedPoll = $waitingBroker->pollResult('default', $worker, 'query-poll-ready-resume-wait-1', 1);

        $this->assertNull($blockedPoll['task']);
        $this->assertSame('workflow_task_pending', $blockedPoll['poll_status']);
        $this->assertSame(0, $poller->pauseCalls);

        $resumePoll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => $workerId,
            'task_queue' => 'python-queries',
        ], $this->workerHeaders());

        $resumePoll->assertOk()
            ->assertJsonPath('poll_status', 'leased')
            ->assertJsonPath('task.task_id', $readyResumeTask->id)
            ->assertJsonPath('task.resume_source_kind', 'workflow_signal');

        $this->postJson("/api/worker/workflow-tasks/{$readyResumeTask->id}/complete", [
            'lease_owner' => $resumePoll->json('task.lease_owner'),
            'workflow_task_attempt' => $resumePoll->json('task.workflow_task_attempt'),
            'commands' => [
                [
                    'type' => 'open_condition_wait',
                    'condition_key' => 'ready-resume-long-poll-drained',
                    'timeout_seconds' => 60,
                ],
            ],
        ], $this->workerHeaders())
            ->assertOk()
            ->assertJsonPath('run_status', 'waiting');

        $poll = $waitingBroker->poll('default', $worker, 'query-poll-ready-resume-wait-2', 1);

        $this->assertSame($queryTask['query_task_id'], $poll['query_task_id'] ?? null);
        $this->assertSame($workerId, $poll['lease_owner'] ?? null);
    }

    public function test_worker_routed_query_task_exposes_server_derived_principal(): void
    {
        Queue::fake();

        $run = $this->startRemoteWorkflow('wf-query-task-principal');
        $this->registerPythonWorker('python-query-principal-worker', 'python-queries', ['python.queryable']);

        /** @var WorkflowQueryTaskBroker $broker */
        $broker = app(WorkflowQueryTaskBroker::class);
        $task = $broker->enqueue(
            'default',
            $run,
            'current',
            $this->queryArguments(),
            CommandContext::controlPlane()->withPrincipal('auth:test-header', 'bob', 'Bob'),
        );

        $poll = $this->postJson('/api/worker/query-tasks/poll', [
            'worker_id' => 'python-query-principal-worker',
            'task_queue' => 'python-queries',
        ], $this->workerHeaders());

        $poll->assertOk()
            ->assertJsonPath('task.query_task_id', $task['query_task_id'])
            ->assertJsonPath('task.principal.type', 'auth:test-header')
            ->assertJsonPath('task.principal.id', 'bob')
            ->assertJsonPath('task.principal.label', 'Bob')
            ->assertJsonPath('task.command_context.context.principal.type', 'auth:test-header')
            ->assertJsonPath('task.command_context.context.principal.id', 'bob');

        $stored = $broker->task((string) $task['query_task_id']);

        $this->assertSame('auth:test-header', $stored['principal']['type'] ?? null);
        $this->assertSame('bob', $stored['principal']['id'] ?? null);
    }

    public function test_query_rejects_non_completed_terminal_run_before_enqueuing_worker_task(): void
    {
        Queue::fake();

        $run = $this->startRemoteWorkflow('wf-query-task-failed-terminal');
        $run->forceFill([
            'status' => RunStatus::Failed,
            'closed_at' => now(),
        ])->save();
        $this->registerPythonWorker('python-query-failed-terminal-worker', 'python-queries', ['python.queryable']);

        /** @var WorkflowQueryTaskBroker $broker */
        $broker = app(WorkflowQueryTaskBroker::class);
        $result = $broker->query('default', $run->refresh(), 'status', $this->queryArguments());

        $this->assertFalse($result['success']);
        $this->assertSame('wf-query-task-failed-terminal', $result['workflow_id']);
        $this->assertSame($run->id, $result['run_id']);
        $this->assertSame('status', $result['query_name']);
        $this->assertSame('run_not_active', $result['reason']);
        $this->assertSame('failed', $result['run_status']);
        $this->assertTrue($result['is_terminal']);
        $this->assertSame(409, $result['status']);
    }

    public function test_completed_control_plane_query_routes_to_worker_with_replay_history(): void
    {
        Queue::fake();

        $run = $this->startRemoteWorkflow('wf-query-task-completed-replay');
        $run->forceFill([
            'status' => RunStatus::Completed,
            'closed_at' => now(),
        ])->save();
        $this->registerPythonWorker('python-query-completed-worker', 'python-queries', ['python.queryable']);

        /** @var LongPollSignalStore $signals */
        $signals = app(LongPollSignalStore::class);
        /** @var LongPollWaitSlotStore $waitSlots */
        $waitSlots = app(LongPollWaitSlotStore::class);
        $poller = new class($signals, $waitSlots) extends LongPoller
        {
            /** @var callable(): void|null */
            public $afterFirstUnreadyProbe = null;

            private bool $runningAfterProbe = false;

            public function until(
                callable $probe,
                callable $ready,
                ?int $timeoutSeconds = null,
                ?int $intervalMilliseconds = null,
                array $wakeChannels = [],
                ?callable $nextProbeAt = null,
                bool $reserveWorkerWaitSlot = false,
                string $waitSlotPool = 'worker',
                ?string $waitSlotNamespace = null,
            ): mixed {
                $value = $probe();

                if ($ready($value)) {
                    return $value;
                }

                if (! is_callable($this->afterFirstUnreadyProbe) || $this->runningAfterProbe) {
                    return $value;
                }

                $this->runningAfterProbe = true;

                try {
                    ($this->afterFirstUnreadyProbe)();
                } finally {
                    $this->runningAfterProbe = false;
                    $this->afterFirstUnreadyProbe = null;
                }

                return $probe();
            }
        };
        $broker = new WorkflowQueryTaskBroker(
            app(ServerPollingCache::class),
            $poller,
            $signals,
            app(ExternalPayloadEnvelopeService::class),
            app(QueryTaskPollRequestStore::class),
        );
        $this->app->instance(WorkflowQueryTaskBroker::class, $broker);

        $this->primeQueryTaskPoller('python-query-completed-worker');

        /** @var WorkerRegistration $worker */
        $worker = WorkerRegistration::query()
            ->where('namespace', 'default')
            ->where('worker_id', 'python-query-completed-worker')
            ->firstOrFail();

        $polledTask = null;
        $poller->afterFirstUnreadyProbe = function () use ($broker, $worker, &$polledTask): void {
            $task = $broker->poll('default', $worker);

            $this->assertIsArray($task);
            $this->assertSame('completed', $task['run_status'] ?? null);
            $this->assertTrue($task['history_export']['history_complete'] ?? false);

            $polledTask = $task;

            $broker->complete(
                'default',
                (string) $task['query_task_id'],
                'python-query-completed-worker',
                (int) $task['query_task_attempt'],
                ['stage' => 'completed', 'source' => 'replay'],
                [
                    'codec' => 'avro',
                    'blob' => Serializer::serializeWithCodec('avro', ['stage' => 'completed', 'source' => 'replay']),
                ],
            );
        };

        $query = $this->postJson('/api/workflows/wf-query-task-completed-replay/query/status', [
            'input' => ['summary'],
        ], $this->apiHeaders());

        $query->assertOk()
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertJsonPath('workflow_id', 'wf-query-task-completed-replay')
            ->assertJsonPath('run_id', $run->id)
            ->assertJsonPath('query_name', 'status')
            ->assertJsonPath('result.stage', 'completed')
            ->assertJsonPath('result.source', 'replay')
            ->assertJsonPath('reason', null)
            ->assertJsonPath('control_plane.operation', 'query')
            ->assertJsonPath('control_plane.operation_name', 'status')
            ->assertJsonPath('control_plane.reason', null);

        $this->assertIsArray($polledTask);
        $this->assertSame($run->id, $polledTask['run_id'] ?? null);
    }

    public function test_worker_can_complete_worker_routed_query_task_with_scalar_zero_result(): void
    {
        Queue::fake();

        $run = $this->startRemoteWorkflow('wf-query-task-scalar-zero');
        $this->registerPythonWorker('python-query-scalar-worker', 'python-queries', ['python.queryable']);

        /** @var WorkflowQueryTaskBroker $broker */
        $broker = app(WorkflowQueryTaskBroker::class);
        $task = $broker->enqueue('default', $run, 'current', $this->queryArguments());

        $this->postJson('/api/worker/query-tasks/poll', [
            'worker_id' => 'python-query-scalar-worker',
            'task_queue' => 'python-queries',
        ], $this->workerHeaders())
            ->assertOk()
            ->assertJsonPath('task.query_task_id', $task['query_task_id']);

        $this->postJson("/api/worker/query-tasks/{$task['query_task_id']}/complete", [
            'lease_owner' => 'python-query-scalar-worker',
            'query_task_attempt' => 1,
            'result' => 0,
            'result_envelope' => [
                'codec' => 'avro',
                'blob' => Serializer::serializeWithCodec('avro', 0),
            ],
        ], $this->workerHeaders())
            ->assertOk()
            ->assertJsonPath('query_task_id', $task['query_task_id'])
            ->assertJsonPath('query_task_attempt', 1)
            ->assertJsonPath('outcome', 'completed');

        $stored = $broker->task((string) $task['query_task_id']);

        $this->assertSame('completed', $stored['status'] ?? null);
        $this->assertSame(0, $stored['result'] ?? null);
        $this->assertSame('avro', $stored['result_envelope']['codec'] ?? null);
        $this->assertSame(
            0,
            Serializer::unserializeWithCodec(
                'avro',
                (string) ($stored['result_envelope']['blob'] ?? ''),
            ),
        );
    }

    public function test_worker_query_task_poll_encodes_missing_query_input_as_empty_arguments(): void
    {
        Queue::fake();

        $run = $this->startRemoteWorkflow('wf-query-task-empty-query-input');
        $this->registerPythonWorker('python-query-empty-input-worker', 'python-queries', ['python.queryable']);

        /** @var WorkflowQueryTaskBroker $broker */
        $broker = app(WorkflowQueryTaskBroker::class);
        $task = $broker->enqueue('default', $run, 'current', [
            'codec' => null,
            'blob' => null,
        ]);

        $poll = $this->postJson('/api/worker/query-tasks/poll', [
            'worker_id' => 'python-query-empty-input-worker',
            'task_queue' => 'python-queries',
        ], $this->workerHeaders());

        $poll->assertOk()
            ->assertJsonPath('task.query_task_id', $task['query_task_id'])
            ->assertJsonPath('task.query_name', 'current')
            ->assertJsonPath('task.query_arguments.codec', 'avro');

        $pollTask = $poll->json('task');
        $this->assertSame(
            [],
            Serializer::unserializeWithCodec(
                (string) $pollTask['query_arguments']['codec'],
                (string) $pollTask['query_arguments']['blob'],
            ),
        );
    }

    public function test_external_zero_argument_query_with_durable_command_contract_reaches_worker(): void
    {
        Queue::fake();

        $this->registerPythonWorker(
            'python-query-zero-argument-worker',
            'python-queries',
            ['python.queryable'],
            workflowCommandContracts: [
                'python.queryable' => [
                    'queries' => ['status'],
                    'query_contracts' => [
                        [
                            'name' => 'status',
                            'parameters' => [],
                        ],
                    ],
                    'signals' => [],
                    'signal_contracts' => [],
                    'updates' => [],
                    'update_contracts' => [],
                ],
            ],
        );
        $run = $this->startRemoteWorkflow('wf-query-task-contracted-zero-argument');

        $started = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowStarted->value)
            ->firstOrFail();
        $this->assertSame(['status'], $started->payload['declared_queries'] ?? null);
        $this->assertSame(
            [
                [
                    'name' => 'status',
                    'parameters' => [],
                ],
            ],
            $started->payload['declared_query_contracts'] ?? null,
        );

        /** @var LongPollSignalStore $signals */
        $signals = app(LongPollSignalStore::class);
        /** @var LongPollWaitSlotStore $waitSlots */
        $waitSlots = app(LongPollWaitSlotStore::class);
        $poller = new class($signals, $waitSlots) extends LongPoller
        {
            /** @var callable(): void|null */
            public $afterFirstUnreadyProbe = null;

            private bool $runningAfterProbe = false;

            public function until(
                callable $probe,
                callable $ready,
                ?int $timeoutSeconds = null,
                ?int $intervalMilliseconds = null,
                array $wakeChannels = [],
                ?callable $nextProbeAt = null,
                bool $reserveWorkerWaitSlot = false,
                string $waitSlotPool = 'worker',
                ?string $waitSlotNamespace = null,
            ): mixed {
                $value = $probe();

                if ($ready($value)) {
                    return $value;
                }

                if (! is_callable($this->afterFirstUnreadyProbe) || $this->runningAfterProbe) {
                    return $value;
                }

                $this->runningAfterProbe = true;

                try {
                    ($this->afterFirstUnreadyProbe)();
                } finally {
                    $this->runningAfterProbe = false;
                    $this->afterFirstUnreadyProbe = null;
                }

                return $probe();
            }
        };
        $broker = new WorkflowQueryTaskBroker(
            app(ServerPollingCache::class),
            $poller,
            $signals,
            app(ExternalPayloadEnvelopeService::class),
            app(QueryTaskPollRequestStore::class),
        );
        $this->app->instance(WorkflowQueryTaskBroker::class, $broker);

        /** @var WorkerRegistration $worker */
        $worker = WorkerRegistration::query()
            ->where('namespace', 'default')
            ->where('worker_id', 'python-query-zero-argument-worker')
            ->firstOrFail();

        $this->primeQueryTaskPoller('python-query-zero-argument-worker');

        $polledTask = null;
        $poller->afterFirstUnreadyProbe = function () use ($broker, $worker, &$polledTask): void {
            $task = $broker->poll('default', $worker);

            $this->assertIsArray($task);
            $this->assertSame('status', $task['query_name'] ?? null);
            $this->assertSame(
                [],
                Serializer::unserializeWithCodec(
                    (string) ($task['query_arguments']['codec'] ?? ''),
                    (string) ($task['query_arguments']['blob'] ?? ''),
                ),
            );

            $polledTask = $task;

            $broker->complete(
                'default',
                (string) $task['query_task_id'],
                'python-query-zero-argument-worker',
                (int) $task['query_task_attempt'],
                ['state' => 'ready'],
                [
                    'codec' => 'avro',
                    'blob' => Serializer::serializeWithCodec('avro', ['state' => 'ready']),
                ],
            );
        };

        $query = $this->postJson(
            '/api/workflows/wf-query-task-contracted-zero-argument/query/status',
            [],
            $this->apiHeaders(),
        );

        $query->assertOk()
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertJsonPath('workflow_id', 'wf-query-task-contracted-zero-argument')
            ->assertJsonPath('run_id', $run->id)
            ->assertJsonPath('query_name', 'status')
            ->assertJsonPath('result.state', 'ready')
            ->assertJsonPath('reason', null)
            ->assertJsonPath('control_plane.operation', 'query')
            ->assertJsonPath('control_plane.operation_name', 'status')
            ->assertJsonPath('control_plane.reason', null);

        $this->assertIsArray($polledTask);
        $this->assertSame($run->id, $polledTask['run_id'] ?? null);
    }

    public function test_query_poll_replays_same_task_after_fresh_slot_and_result_cache_loss(): void
    {
        Queue::fake();

        $run = $this->startRemoteWorkflow('wf-query-task-duplicate-poll');
        $this->registerPythonWorker('python-query-duplicate-worker', 'python-queries', ['python.queryable']);
        $this->registerPythonWorker('python-query-other-worker', 'python-queries', ['python.queryable']);

        /** @var WorkflowQueryTaskBroker $broker */
        $broker = app(WorkflowQueryTaskBroker::class);
        $task = $broker->enqueue('default', $run, 'status', $this->queryArguments());

        $firstPoll = $this->postJson('/api/worker/query-tasks/poll', [
            'worker_id' => 'python-query-duplicate-worker',
            'task_queue' => 'python-queries',
            'poll_request_id' => 'query-poll-request-1',
        ], $this->workerHeaders());

        $firstPoll->assertOk()
            ->assertJsonPath('task.query_task_id', $task['query_task_id'])
            ->assertJsonPath('task.query_task_attempt', 1)
            ->assertJsonPath('task.lease_owner', 'python-query-duplicate-worker');

        app(QueryTaskPollRequestStore::class)->forgetResult(
            'default',
            'python-queries',
            null,
            'python-query-duplicate-worker',
            'query-poll-request-1',
        );

        // A fresh concurrent slot is independent from the logical poll that
        // received the lease and must not execute the same query attempt.
        $freshPoll = $this->postJson('/api/worker/query-tasks/poll', [
            'worker_id' => 'python-query-duplicate-worker',
            'task_queue' => 'python-queries',
            'poll_request_id' => 'query-poll-request-2',
            'timeout_seconds' => 0,
        ], $this->workerHeaders());

        $freshPoll->assertOk()
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'empty');

        $duplicatePoll = $this->postJson('/api/worker/query-tasks/poll', [
            'worker_id' => 'python-query-duplicate-worker',
            'task_queue' => 'python-queries',
            'poll_request_id' => 'query-poll-request-1',
        ], $this->workerHeaders());

        $duplicatePoll->assertOk()
            ->assertJsonPath('task.query_task_id', $task['query_task_id'])
            ->assertJsonPath('task.query_task_attempt', 1)
            ->assertJsonPath('task.lease_owner', 'python-query-duplicate-worker');

        $this->postJson('/api/worker/query-tasks/poll', [
            'worker_id' => 'python-query-other-worker',
            'task_queue' => 'python-queries',
            'poll_request_id' => 'query-poll-request-1',
        ], $this->workerHeaders())
            ->assertOk()
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'empty');
    }

    public function test_superseded_query_poll_request_cannot_lease_query_task(): void
    {
        Queue::fake();

        $run = $this->startRemoteWorkflow('wf-query-task-superseded-poll');
        $this->registerPythonWorker('python-query-superseded-worker', 'python-queries', ['python.queryable']);

        /** @var QueryTaskPollRequestStore $pollRequests */
        $pollRequests = app(QueryTaskPollRequestStore::class);
        $poller = new class(app(LongPollSignalStore::class), app(LongPollWaitSlotStore::class)) extends LongPoller
        {
            /** @var callable(): void|null */
            public $beforeProbe = null;

            public function until(
                callable $probe,
                callable $ready,
                ?int $timeoutSeconds = null,
                ?int $intervalMilliseconds = null,
                array $wakeChannels = [],
                ?callable $nextProbeAt = null,
                bool $reserveWorkerWaitSlot = false,
                string $waitSlotPool = 'worker',
                ?string $waitSlotNamespace = null,
            ): mixed {
                if (is_callable($this->beforeProbe)) {
                    ($this->beforeProbe)();
                }

                return $probe();
            }
        };
        $broker = new WorkflowQueryTaskBroker(
            app(ServerPollingCache::class),
            $poller,
            app(LongPollSignalStore::class),
            app(ExternalPayloadEnvelopeService::class),
            $pollRequests,
        );

        $task = $broker->enqueue('default', $run, 'status', $this->queryArguments());
        /** @var WorkerRegistration $worker */
        $worker = WorkerRegistration::query()
            ->where('namespace', 'default')
            ->where('worker_id', 'python-query-superseded-worker')
            ->firstOrFail();

        $poller->beforeProbe = function () use ($pollRequests): void {
            $pollRequests->markCurrent(
                'default',
                'python-queries',
                null,
                'python-query-superseded-worker',
                'query-poll-new',
            );
        };

        $this->assertNull($broker->poll('default', $worker, 'query-poll-old'));

        $stored = $broker->task((string) $task['query_task_id']);
        $this->assertSame('pending', $stored['status'] ?? null);

        $newPoll = $broker->poll('default', $worker, 'query-poll-new');

        $this->assertSame($task['query_task_id'], $newPoll['query_task_id'] ?? null);
        $this->assertSame('python-query-superseded-worker', $newPoll['lease_owner'] ?? null);
    }

    public function test_superseded_query_poll_request_cannot_lease_after_current_check_race(): void
    {
        Queue::fake();

        $run = $this->startRemoteWorkflow('wf-query-task-superseded-claim-race');
        $this->registerPythonWorker('python-query-claim-race-worker', 'python-queries', ['python.queryable']);

        $pollRequests = new WorkflowQueryTaskBrokerSupersessionRacePollRequestStore(app(ServerPollingCache::class));
        $broker = new WorkflowQueryTaskBroker(
            app(ServerPollingCache::class),
            app(LongPoller::class),
            app(LongPollSignalStore::class),
            app(ExternalPayloadEnvelopeService::class),
            $pollRequests,
        );

        $task = $broker->enqueue('default', $run, 'status', $this->queryArguments());
        /** @var WorkerRegistration $worker */
        $worker = WorkerRegistration::query()
            ->where('namespace', 'default')
            ->where('worker_id', 'python-query-claim-race-worker')
            ->firstOrFail();

        $pollRequests->afterFirstCurrentCheck = function () use ($pollRequests): void {
            $pollRequests->markCurrent(
                'default',
                'python-queries',
                null,
                'python-query-claim-race-worker',
                'query-poll-new',
            );
        };

        $this->assertNull($broker->poll('default', $worker, 'query-poll-old'));
        $this->assertGreaterThanOrEqual(2, $pollRequests->currentChecks);

        $stored = $broker->task((string) $task['query_task_id']);
        $this->assertIsArray($stored);
        $this->assertSame('pending', $stored['status'] ?? null);
        $this->assertArrayNotHasKey('lease_owner', $stored);

        $newPoll = $broker->poll('default', $worker, 'query-poll-new');

        $this->assertSame($task['query_task_id'], $newPoll['query_task_id'] ?? null);
        $this->assertSame('python-query-claim-race-worker', $newPoll['lease_owner'] ?? null);
    }

    public function test_duplicate_old_query_poll_request_does_not_become_current_after_newer_poll_starts(): void
    {
        Queue::fake();

        $run = $this->startRemoteWorkflow('wf-query-task-old-duplicate-current');
        $this->registerPythonWorker('python-query-old-duplicate-worker', 'python-queries', ['python.queryable']);

        $pollRequests = new WorkflowQueryTaskBrokerImmediatePollRequestStore(app(ServerPollingCache::class));
        $broker = new WorkflowQueryTaskBroker(
            app(ServerPollingCache::class),
            app(LongPoller::class),
            app(LongPollSignalStore::class),
            app(ExternalPayloadEnvelopeService::class),
            $pollRequests,
        );
        /** @var WorkerRegistration $worker */
        $worker = WorkerRegistration::query()
            ->where('namespace', 'default')
            ->where('worker_id', 'python-query-old-duplicate-worker')
            ->firstOrFail();

        $pollRequests->markCurrent(
            'default',
            'python-queries',
            null,
            'python-query-old-duplicate-worker',
            'query-poll-old',
        );
        $this->assertTrue($pollRequests->tryStart(
            'default',
            'python-queries',
            null,
            'python-query-old-duplicate-worker',
            'query-poll-old',
        ));
        $pollRequests->markCurrent(
            'default',
            'python-queries',
            null,
            'python-query-old-duplicate-worker',
            'query-poll-new',
        );

        $this->assertNull($broker->poll('default', $worker, 'query-poll-old'));
        $this->assertFalse($pollRequests->isCurrent(
            'default',
            'python-queries',
            null,
            'python-query-old-duplicate-worker',
            'query-poll-old',
        ));
        $this->assertTrue($pollRequests->isCurrent(
            'default',
            'python-queries',
            null,
            'python-query-old-duplicate-worker',
            'query-poll-new',
        ));

        $task = $broker->enqueue('default', $run, 'status', $this->queryArguments());
        $newPoll = $broker->poll('default', $worker, 'query-poll-new');

        $this->assertSame($task['query_task_id'], $newPoll['query_task_id'] ?? null);
        $this->assertSame('python-query-old-duplicate-worker', $newPoll['lease_owner'] ?? null);
    }

    public function test_query_workers_must_advertise_the_workflow_type_and_query_capability(): void
    {
        Queue::fake();

        $run = $this->startRemoteWorkflow('wf-query-task-explicit-type-required');
        $this->registerQueryWorker('generic-query-worker', 'python-queries', [], 'php');

        /** @var WorkflowQueryTaskBroker $broker */
        $broker = app(WorkflowQueryTaskBroker::class);

        $this->assertFalse($broker->hasWorkerFor('default', $run));

        $this->registerPythonWorker('python-query-explicit-worker', 'python-queries', ['python.queryable']);

        $this->assertTrue($broker->hasWorkerFor('default', $run));
    }

    public function test_external_control_plane_query_reports_no_worker_without_php_fallback(): void
    {
        Queue::fake();

        $this->startRemoteWorkflow('wf-query-task-no-worker');

        $query = $this->postJson('/api/workflows/wf-query-task-no-worker/query/status', [
            'input' => ['summary'],
        ], $this->apiHeaders());

        $query->assertStatus(409)
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertJsonPath('workflow_id', 'wf-query-task-no-worker')
            ->assertJsonPath('query_name', 'status')
            ->assertJsonPath('reason', 'query_worker_unavailable')
            ->assertJsonPath('message', 'No active worker is registered on task queue [python-queries].')
            ->assertJsonPath('control_plane.operation', 'query')
            ->assertJsonPath('control_plane.operation_name', 'status');
    }

    public function test_worker_routed_query_enqueues_for_registered_worker_before_active_query_task_poll(): void
    {
        Queue::fake();

        $this->startRemoteWorkflow(
            'wf-query-task-capable-but-not-polling',
            workflowType: 'polyglot.php.signal.wait',
            taskQueue: 'polyglot-php',
        );
        $this->registerQueryWorker(
            'php-query-capable-but-not-polling-worker',
            'polyglot-php',
            ['polyglot.php.signal.wait'],
            'php',
        );

        $query = $this->postJson('/api/workflows/wf-query-task-capable-but-not-polling/query/status', [
            'input' => ['summary'],
        ], $this->apiHeaders());

        $query->assertStatus(504)
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertJsonPath('workflow_id', 'wf-query-task-capable-but-not-polling')
            ->assertJsonPath('query_name', 'status')
            ->assertJsonPath('reason', 'query_task_not_claimed')
            ->assertJsonPath(
                'message',
                'Timed out waiting for a compatible worker to claim workflow query [status] on task queue [polyglot-php].',
            )
            ->assertJsonPath('control_plane.operation', 'query')
            ->assertJsonPath('control_plane.operation_name', 'status');
    }

    public function test_worker_routed_query_rejects_stale_active_query_task_poller(): void
    {
        Queue::fake();
        config([
            'server.query_tasks.timeout' => 0,
            'server.workers.stale_after_seconds' => 3,
        ]);

        $registeredAt = now()->startOfSecond();
        $this->travelTo($registeredAt);

        $run = $this->startRemoteWorkflow('wf-query-task-stale-worker-active-poll');
        $this->registerPythonWorker('python-query-stale-active-poller', 'python-queries', ['python.queryable']);

        $this->travelTo($registeredAt->copy()->addSeconds(5));

        /** @var WorkflowQueryTaskBroker $broker */
        $broker = app(WorkflowQueryTaskBroker::class);
        /** @var WorkerRegistration $worker */
        $worker = WorkerRegistration::query()
            ->where('namespace', 'default')
            ->where('worker_id', 'python-query-stale-active-poller')
            ->firstOrFail();

        $this->assertFalse($broker->hasWorkerFor('default', $run));
        $this->assertFalse($broker->queryRoute('default', $run)['servable']);

        $this->postJson('/api/worker/query-tasks/poll', [
            'worker_id' => 'python-query-stale-active-poller',
            'task_queue' => 'python-queries',
        ], $this->workerHeaders())
            ->assertOk()
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'stale_worker_registration');

        $task = $broker->enqueue('default', $run, 'status', $this->queryArguments());

        $this->assertNull($broker->poll('default', $worker, 'query-poll-stale-worker'));
        $this->assertFalse($broker->hasWorkerFor('default', $run));

        $stored = $broker->task((string) $task['query_task_id']);
        $this->assertIsArray($stored);
        $this->assertSame('pending', $stored['status'] ?? null);
        $this->assertArrayNotHasKey('lease_owner', $stored);

        $result = $broker->query('default', $run, 'status', $this->queryArguments());

        $this->assertFalse($result['success']);
        $this->assertSame('wf-query-task-stale-worker-active-poll', $result['workflow_id']);
        $this->assertSame($run->id, $result['run_id']);
        $this->assertSame('status', $result['query_name']);
        $this->assertSame('query_worker_unavailable', $result['reason']);
        $this->assertSame(409, $result['status']);
    }

    public function test_worker_routed_query_waits_for_worker_registration_before_reporting_unavailable(): void
    {
        Queue::fake();
        config(['server.query_tasks.timeout' => 5]);

        $run = $this->startRemoteWorkflow('wf-query-task-registration-race');

        /** @var LongPollSignalStore $signals */
        $signals = app(LongPollSignalStore::class);
        /** @var LongPollWaitSlotStore $waitSlots */
        $waitSlots = app(LongPollWaitSlotStore::class);
        $poller = new class($signals, $waitSlots) extends LongPoller
        {
            /** @var list<callable(): void> */
            public array $afterUnreadyProbes = [];

            private bool $runningAfterProbe = false;

            public function until(
                callable $probe,
                callable $ready,
                ?int $timeoutSeconds = null,
                ?int $intervalMilliseconds = null,
                array $wakeChannels = [],
                ?callable $nextProbeAt = null,
                bool $reserveWorkerWaitSlot = false,
                string $waitSlotPool = 'worker',
                ?string $waitSlotNamespace = null,
            ): mixed {
                while (true) {
                    $value = $probe();

                    if ($ready($value)) {
                        return $value;
                    }

                    if ($this->runningAfterProbe) {
                        return $value;
                    }

                    $afterProbe = array_shift($this->afterUnreadyProbes);

                    if (! is_callable($afterProbe)) {
                        return $value;
                    }

                    $this->runningAfterProbe = true;

                    try {
                        $afterProbe();
                    } finally {
                        $this->runningAfterProbe = false;
                    }
                }
            }
        };
        $broker = new WorkflowQueryTaskBroker(
            app(ServerPollingCache::class),
            $poller,
            $signals,
            app(ExternalPayloadEnvelopeService::class),
            app(QueryTaskPollRequestStore::class),
        );

        $poller->afterUnreadyProbes[] = function (): void {
            $this->registerPythonWorker('python-query-registration-race-worker', 'python-queries', ['python.queryable']);
        };
        $poller->afterUnreadyProbes[] = function () use ($broker): void {
            /** @var WorkerRegistration $worker */
            $worker = WorkerRegistration::query()
                ->where('namespace', 'default')
                ->where('worker_id', 'python-query-registration-race-worker')
                ->firstOrFail();

            $task = $broker->poll('default', $worker);

            $this->assertIsArray($task);
            $this->assertSame('wf-query-task-registration-race', $task['workflow_id'] ?? null);

            $broker->complete(
                'default',
                (string) $task['query_task_id'],
                'python-query-registration-race-worker',
                (int) $task['query_task_attempt'],
                ['status' => 'ready'],
                null,
            );
        };

        $result = $broker->query('default', $run, 'status', $this->queryArguments());

        $this->assertTrue($result['success']);
        $this->assertSame('wf-query-task-registration-race', $result['workflow_id']);
        $this->assertSame($run->id, $result['run_id']);
        $this->assertSame('status', $result['query_name']);
        $this->assertSame(['status' => 'ready'], $result['result']);
        $this->assertNull($result['reason']);
        $this->assertSame(200, $result['status']);
    }

    public function test_external_control_plane_query_reports_incompatible_worker_type(): void
    {
        Queue::fake();

        $this->startRemoteWorkflow('wf-query-task-incompatible-type');
        $this->registerPythonWorker('python-query-wrong-type-worker', 'python-queries', ['python.other']);

        $query = $this->postJson('/api/workflows/wf-query-task-incompatible-type/query/status', [
            'input' => ['summary'],
        ], $this->apiHeaders());

        $query->assertStatus(409)
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertJsonPath('workflow_id', 'wf-query-task-incompatible-type')
            ->assertJsonPath('query_name', 'status')
            ->assertJsonPath('reason', 'query_worker_incompatible')
            ->assertJsonPath('message', 'Query-capable workers on task queue [python-queries] do not advertise workflow type [python.queryable].')
            ->assertJsonPath('control_plane.operation', 'query')
            ->assertJsonPath('control_plane.operation_name', 'status');
    }

    public function test_external_control_plane_query_reports_incompatible_worker_fingerprint(): void
    {
        Queue::fake();

        $this->startRemoteWorkflow(
            'wf-query-task-incompatible-fingerprint',
            workflowDefinitionFingerprint: 'sha256:expected',
        );
        $this->registerPythonWorker(
            'python-query-wrong-fingerprint-worker',
            'python-queries',
            ['python.queryable'],
            workflowDefinitionFingerprints: ['python.queryable' => 'sha256:actual'],
        );

        $query = $this->postJson('/api/workflows/wf-query-task-incompatible-fingerprint/query/status', [
            'input' => ['summary'],
        ], $this->apiHeaders());

        $query->assertStatus(409)
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertJsonPath('workflow_id', 'wf-query-task-incompatible-fingerprint')
            ->assertJsonPath('query_name', 'status')
            ->assertJsonPath('reason', 'query_worker_incompatible')
            ->assertJsonPath(
                'message',
                'Query-capable workers on task queue [python-queries] support workflow type [python.queryable], but none advertise the recorded workflow definition fingerprint.',
            )
            ->assertJsonPath('control_plane.operation', 'query')
            ->assertJsonPath('control_plane.operation_name', 'status');
    }

    public function test_query_task_poll_skips_workers_without_explicit_workflow_type(): void
    {
        Queue::fake();

        $run = $this->startRemoteWorkflow('wf-query-task-shared-queue-explicit-type');
        $this->registerQueryWorker('generic-shared-query-worker', 'python-queries', [], 'php');
        $this->registerPythonWorker('python-shared-query-worker', 'python-queries', ['python.queryable']);

        /** @var WorkflowQueryTaskBroker $broker */
        $broker = app(WorkflowQueryTaskBroker::class);
        $task = $broker->enqueue('default', $run, 'status', $this->queryArguments());

        /** @var WorkerRegistration $genericWorker */
        $genericWorker = WorkerRegistration::query()
            ->where('namespace', 'default')
            ->where('worker_id', 'generic-shared-query-worker')
            ->firstOrFail();

        $this->assertNull($broker->poll('default', $genericWorker, 'query-poll-generic'));

        $stored = $broker->task((string) $task['query_task_id']);
        $this->assertIsArray($stored);
        $this->assertSame('pending', $stored['status'] ?? null);
        $this->assertArrayNotHasKey('lease_owner', $stored);

        /** @var WorkerRegistration $pythonWorker */
        $pythonWorker = WorkerRegistration::query()
            ->where('namespace', 'default')
            ->where('worker_id', 'python-shared-query-worker')
            ->firstOrFail();

        $poll = $broker->poll('default', $pythonWorker, 'query-poll-python');

        $this->assertSame($task['query_task_id'], $poll['query_task_id'] ?? null);
        $this->assertSame('python-shared-query-worker', $poll['lease_owner'] ?? null);
    }

    public function test_legacy_query_workers_become_eligible_after_polling_query_tasks(): void
    {
        Queue::fake();

        $run = $this->startRemoteWorkflow('wf-query-task-legacy-query-poller');
        $this->registerPythonWorker('python-legacy-query-poller', 'python-queries', ['python.queryable'], []);

        /** @var WorkflowQueryTaskBroker $broker */
        $broker = app(WorkflowQueryTaskBroker::class);

        $this->assertFalse($broker->hasWorkerFor('default', $run));

        /** @var WorkerRegistration $worker */
        $worker = WorkerRegistration::query()
            ->where('namespace', 'default')
            ->where('worker_id', 'python-legacy-query-poller')
            ->firstOrFail();

        $this->assertNull($broker->poll('default', $worker, 'query-poll-legacy-primer'));
        $this->assertTrue($broker->hasWorkerFor('default', $run));
    }

    public function test_query_task_poll_skips_workers_with_mismatched_workflow_definition_fingerprint(): void
    {
        Queue::fake();

        $run = $this->startRemoteWorkflow(
            'wf-query-task-definition-fingerprint',
            workflowDefinitionFingerprint: 'sha256:python-counter',
        );
        $this->registerPythonWorker(
            'python-mismatched-definition-worker',
            'python-queries',
            ['python.queryable'],
            workflowDefinitionFingerprints: ['python.queryable' => 'sha256:php-counter'],
        );
        $this->registerPythonWorker(
            'python-matching-definition-worker',
            'python-queries',
            ['python.queryable'],
            workflowDefinitionFingerprints: ['python.queryable' => 'sha256:python-counter'],
        );

        /** @var WorkflowQueryTaskBroker $broker */
        $broker = app(WorkflowQueryTaskBroker::class);
        $task = $broker->enqueue('default', $run, 'status', $this->queryArguments());

        /** @var WorkerRegistration $mismatchedWorker */
        $mismatchedWorker = WorkerRegistration::query()
            ->where('namespace', 'default')
            ->where('worker_id', 'python-mismatched-definition-worker')
            ->firstOrFail();

        $this->assertNull($broker->poll('default', $mismatchedWorker, 'query-poll-definition-mismatch'));

        $stored = $broker->task((string) $task['query_task_id']);
        $this->assertIsArray($stored);
        $this->assertSame('pending', $stored['status'] ?? null);
        $this->assertArrayNotHasKey('lease_owner', $stored);

        /** @var WorkerRegistration $matchingWorker */
        $matchingWorker = WorkerRegistration::query()
            ->where('namespace', 'default')
            ->where('worker_id', 'python-matching-definition-worker')
            ->firstOrFail();

        $poll = $broker->poll('default', $matchingWorker, 'query-poll-definition-match');

        $this->assertSame($task['query_task_id'], $poll['query_task_id'] ?? null);
        $this->assertSame('python-matching-definition-worker', $poll['lease_owner'] ?? null);
    }

    public function test_query_task_poll_requires_worker_build_to_match_run_compatibility_for_contracted_queries(): void
    {
        Queue::fake();

        $this->registerPythonWorker(
            'python-query-contract-v1-worker',
            'contract-query-builds',
            ['external.counter'],
            workflowCommandContracts: [
                'external.counter' => $this->externalCounterCommandContract(queryName: 'legacy-count'),
            ],
            buildId: 'v1.0.0',
        );
        $this->registerPythonWorker(
            'python-query-contract-v2-worker',
            'contract-query-builds',
            ['external.counter'],
            workflowCommandContracts: [
                'external.counter' => $this->externalCounterCommandContract(queryName: 'count-at-least'),
            ],
            buildId: 'v2.0.0',
        );

        WorkerBuildIdRollout::query()->create([
            'namespace' => 'default',
            'task_queue' => 'contract-query-builds',
            'build_id' => 'v2.0.0',
            'drain_intent' => WorkerBuildIdRollout::DRAIN_INTENT_ACTIVE,
            'promoted_at' => now()->subMinute(),
        ]);

        $run = $this->startRemoteWorkflow(
            'wf-query-task-build-contract',
            workflowType: 'external.counter',
            taskQueue: 'contract-query-builds',
        );

        $this->assertSame('v2.0.0', $run->compatibility);

        $started = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowStarted->value)
            ->firstOrFail();

        $this->assertSame(['count-at-least', 'state'], $started->payload['declared_queries'] ?? null);
        $this->assertNotContains('legacy-count', $started->payload['declared_queries'] ?? []);

        /** @var WorkflowQueryTaskBroker $broker */
        $broker = app(WorkflowQueryTaskBroker::class);
        $task = $broker->enqueue('default', $run, 'count-at-least', [
            'codec' => 'avro',
            'blob' => Serializer::serializeWithCodec('avro', [1]),
        ]);

        $this->assertSame('v2.0.0', $task['compatibility'] ?? null);

        /** @var WorkerRegistration $v1Worker */
        $v1Worker = WorkerRegistration::query()
            ->where('namespace', 'default')
            ->where('worker_id', 'python-query-contract-v1-worker')
            ->firstOrFail();

        $this->assertNull($broker->poll('default', $v1Worker));

        $stored = $broker->task((string) $task['query_task_id']);
        $this->assertIsArray($stored);
        $this->assertSame('pending', $stored['status'] ?? null);
        $this->assertArrayNotHasKey('lease_owner', $stored);

        /** @var WorkerRegistration $v2Worker */
        $v2Worker = WorkerRegistration::query()
            ->where('namespace', 'default')
            ->where('worker_id', 'python-query-contract-v2-worker')
            ->firstOrFail();

        $poll = $broker->poll('default', $v2Worker);

        $this->assertSame($task['query_task_id'], $poll['query_task_id'] ?? null);
        $this->assertSame('v2.0.0', $poll['compatibility'] ?? null);
        $this->assertSame('python-query-contract-v2-worker', $poll['lease_owner'] ?? null);
    }

    public function test_query_task_poll_requires_unversioned_worker_for_unversioned_contracted_queries_in_mixed_queue(): void
    {
        Queue::fake();

        $this->registerPythonWorker(
            'python-query-contract-unversioned-worker',
            'contract-query-unversioned',
            ['external.counter'],
            workflowCommandContracts: [
                'external.counter' => $this->externalCounterCommandContract(queryName: 'legacy-count'),
            ],
        );
        $this->registerPythonWorker(
            'python-query-contract-versioned-worker',
            'contract-query-unversioned',
            ['external.counter'],
            workflowCommandContracts: [
                'external.counter' => $this->externalCounterCommandContract(queryName: 'count-at-least'),
            ],
            buildId: 'v2.0.0',
        );

        WorkerBuildIdRollout::query()->create([
            'namespace' => 'default',
            'task_queue' => 'contract-query-unversioned',
            'build_id' => WorkerBuildIdRollout::UNVERSIONED_KEY,
            'drain_intent' => WorkerBuildIdRollout::DRAIN_INTENT_ACTIVE,
            'promoted_at' => now()->subMinute(),
        ]);

        $run = $this->startRemoteWorkflow(
            'wf-query-task-unversioned-contract',
            workflowType: 'external.counter',
            taskQueue: 'contract-query-unversioned',
        );

        $this->assertNull($run->compatibility);

        $started = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowStarted->value)
            ->firstOrFail();

        $this->assertSame(['legacy-count', 'state'], $started->payload['declared_queries'] ?? null);
        $this->assertNotContains('count-at-least', $started->payload['declared_queries'] ?? []);

        $this->primeQueryTaskPoller('python-query-contract-unversioned-worker', 'contract-query-unversioned');

        /** @var WorkflowQueryTaskBroker $broker */
        $broker = app(WorkflowQueryTaskBroker::class);
        $route = $broker->queryRoute('default', $run);

        $this->assertTrue($route['servable']);
        $this->assertSame(2, $route['query_capable_worker_count']);
        $this->assertSame(2, $route['workflow_type_worker_count']);
        $this->assertSame(1, $route['compatible_worker_count']);

        $task = $broker->enqueue('default', $run, 'legacy-count', [
            'codec' => 'avro',
            'blob' => Serializer::serializeWithCodec('avro', [1]),
        ]);

        $this->assertNull($task['compatibility'] ?? null);
        $this->assertSame('unversioned', $task['compatibility_scope'] ?? null);
        $this->assertFalse($broker->hasPendingTaskForPoller(
            'default',
            'contract-query-unversioned',
            ['external.counter'],
            'v2.0.0',
        ));
        $this->assertTrue($broker->hasPendingTaskForPoller(
            'default',
            'contract-query-unversioned',
            ['external.counter'],
        ));

        /** @var WorkerRegistration $versionedWorker */
        $versionedWorker = WorkerRegistration::query()
            ->where('namespace', 'default')
            ->where('worker_id', 'python-query-contract-versioned-worker')
            ->firstOrFail();

        $this->assertNull($broker->poll('default', $versionedWorker));

        $stored = $broker->task((string) $task['query_task_id']);
        $this->assertIsArray($stored);
        $this->assertSame('pending', $stored['status'] ?? null);
        $this->assertArrayNotHasKey('lease_owner', $stored);

        /** @var WorkerRegistration $unversionedWorker */
        $unversionedWorker = WorkerRegistration::query()
            ->where('namespace', 'default')
            ->where('worker_id', 'python-query-contract-unversioned-worker')
            ->firstOrFail();

        $poll = $broker->poll('default', $unversionedWorker);

        $this->assertSame($task['query_task_id'], $poll['query_task_id'] ?? null);
        $this->assertNull($poll['compatibility'] ?? null);
        $this->assertSame('unversioned', $poll['compatibility_scope'] ?? null);
        $this->assertSame('python-query-contract-unversioned-worker', $poll['lease_owner'] ?? null);
    }

    public function test_contracted_query_validation_resolves_external_storage_arguments_before_worker_routing(): void
    {
        Queue::fake();

        $directory = storage_path('framework/testing/query-task-contracted-external-input');
        File::deleteDirectory($directory);
        WorkflowNamespace::query()->where('name', 'default')->update([
            'external_payload_storage' => [
                'driver' => 'local',
                'enabled' => true,
                'threshold_bytes' => 1,
                'config' => [
                    'uri' => 'file://'.$directory,
                ],
            ],
        ]);

        try {
            $this->registerPythonWorker(
                'python-query-contract-external-worker',
                'contract-query-external-input',
                ['external.counter'],
                workflowCommandContracts: [
                    'external.counter' => $this->externalCounterCommandContract(queryName: 'count-at-least'),
                ],
            );
            $run = $this->startRemoteWorkflow(
                'wf-query-task-contracted-external-input',
                workflowType: 'external.counter',
                taskQueue: 'contract-query-external-input',
            );

            /** @var LongPollSignalStore $signals */
            $signals = app(LongPollSignalStore::class);
            /** @var LongPollWaitSlotStore $waitSlots */
            $waitSlots = app(LongPollWaitSlotStore::class);
            $poller = new class($signals, $waitSlots) extends LongPoller
            {
                /** @var callable(): void|null */
                public $afterFirstUnreadyProbe = null;

                private bool $runningAfterProbe = false;

                public function until(
                    callable $probe,
                    callable $ready,
                    ?int $timeoutSeconds = null,
                    ?int $intervalMilliseconds = null,
                    array $wakeChannels = [],
                    ?callable $nextProbeAt = null,
                    bool $reserveWorkerWaitSlot = false,
                    string $waitSlotPool = 'worker',
                    ?string $waitSlotNamespace = null,
                ): mixed {
                    $value = $probe();

                    if ($ready($value)) {
                        return $value;
                    }

                    if (! is_callable($this->afterFirstUnreadyProbe) || $this->runningAfterProbe) {
                        return $value;
                    }

                    $this->runningAfterProbe = true;

                    try {
                        ($this->afterFirstUnreadyProbe)();
                    } finally {
                        $this->runningAfterProbe = false;
                        $this->afterFirstUnreadyProbe = null;
                    }

                    return $probe();
                }
            };
            $broker = new WorkflowQueryTaskBroker(
                app(ServerPollingCache::class),
                $poller,
                $signals,
                app(ExternalPayloadEnvelopeService::class),
                app(QueryTaskPollRequestStore::class),
            );
            $this->app->instance(WorkflowQueryTaskBroker::class, $broker);

            /** @var WorkerRegistration $worker */
            $worker = WorkerRegistration::query()
                ->where('namespace', 'default')
                ->where('worker_id', 'python-query-contract-external-worker')
                ->firstOrFail();

            $payload = Serializer::serializeWithCodec('avro', [3]);
            $storedReference = ExternalPayloads::externalizeForNamespace($payload, 'avro', 'default');
            $this->assertIsString($storedReference);
            $this->assertStringStartsWith(ExternalPayloads::STORED_REFERENCE_PREFIX, $storedReference);
            $externalEnvelope = ExternalPayloads::storedEnvelope($storedReference);
            $this->assertIsArray($externalEnvelope);

            foreach ([
                'explicit_external_storage' => $externalEnvelope,
                'stored_reference_blob' => [
                    'codec' => 'avro',
                    'blob' => $storedReference,
                ],
            ] as $mode => $queryArguments) {
                $this->primeQueryTaskPoller('python-query-contract-external-worker', 'contract-query-external-input');

                $poller->afterFirstUnreadyProbe = function () use ($broker, $worker, $mode): void {
                    $task = $broker->poll('default', $worker);

                    $this->assertIsArray($task);
                    $this->assertSame('count-at-least', $task['query_name'] ?? null);
                    $this->assertSame(
                        [3],
                        Serializer::unserializeWithCodec(
                            (string) ($task['query_arguments']['codec'] ?? ''),
                            (string) ($task['query_arguments']['blob'] ?? ''),
                        ),
                        'Contracted query arguments were not resolved for '.$mode,
                    );

                    $broker->complete(
                        'default',
                        (string) $task['query_task_id'],
                        'python-query-contract-external-worker',
                        (int) $task['query_task_attempt'],
                        ['mode' => $mode],
                        [
                            'codec' => 'avro',
                            'blob' => Serializer::serializeWithCodec('avro', ['mode' => $mode]),
                        ],
                    );
                };

                $result = $broker->query('default', $run, 'count-at-least', $queryArguments);

                $this->assertTrue($result['success']);
                $this->assertSame(200, $result['status']);
                $this->assertSame(['mode' => $mode], $result['result']);
            }
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_duplicate_query_poll_request_id_does_not_replay_after_query_task_completion(): void
    {
        Queue::fake();

        $run = $this->startRemoteWorkflow('wf-query-task-duplicate-complete');
        $this->registerPythonWorker('python-query-duplicate-complete-worker', 'python-queries', ['python.queryable']);

        /** @var WorkflowQueryTaskBroker $broker */
        $broker = app(WorkflowQueryTaskBroker::class);
        $task = $broker->enqueue('default', $run, 'status', $this->queryArguments());

        $firstPoll = $this->postJson('/api/worker/query-tasks/poll', [
            'worker_id' => 'python-query-duplicate-complete-worker',
            'task_queue' => 'python-queries',
            'poll_request_id' => 'query-poll-complete-1',
        ], $this->workerHeaders());

        $firstPoll->assertOk()
            ->assertJsonPath('task.query_task_id', $task['query_task_id']);

        $this->postJson("/api/worker/query-tasks/{$task['query_task_id']}/complete", [
            'lease_owner' => 'python-query-duplicate-complete-worker',
            'query_task_attempt' => 1,
            'result' => ['status' => 'ready'],
        ], $this->workerHeaders())
            ->assertOk()
            ->assertJsonPath('outcome', 'completed');

        $duplicatePoll = $this->postJson('/api/worker/query-tasks/poll', [
            'worker_id' => 'python-query-duplicate-complete-worker',
            'task_queue' => 'python-queries',
            'poll_request_id' => 'query-poll-complete-1',
        ], $this->workerHeaders());

        $duplicatePoll->assertOk()
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'empty');
    }

    public function test_worker_query_task_failure_preserves_validation_errors(): void
    {
        Queue::fake();

        $run = $this->startRemoteWorkflow('wf-query-task-invalid-arguments');
        $this->registerPythonWorker('python-query-worker', 'python-queries', ['python.queryable']);

        /** @var WorkflowQueryTaskBroker $broker */
        $broker = app(WorkflowQueryTaskBroker::class);
        $task = $broker->enqueue('default', $run, 'status', [
            'codec' => 'avro',
            'blob' => Serializer::serializeWithCodec('avro', [
                'extra' => 'summary',
            ]),
        ]);

        $this->postJson('/api/worker/query-tasks/poll', [
            'worker_id' => 'python-query-worker',
            'task_queue' => 'python-queries',
        ], $this->workerHeaders())->assertOk();

        $failure = $this->postJson("/api/worker/query-tasks/{$task['query_task_id']}/fail", [
            'lease_owner' => 'python-query-worker',
            'query_task_attempt' => 1,
            'failure' => [
                'message' => 'Workflow query [status] received invalid arguments.',
                'reason' => 'invalid_query_arguments',
                'type' => 'Workflow\\V2\\Exceptions\\InvalidQueryArgumentsException',
                'validation_errors' => [
                    'prefix' => ['The prefix argument is required.'],
                    'extra' => ['Unknown argument [extra].'],
                ],
            ],
        ], $this->workerHeaders());

        $failure->assertOk()
            ->assertJsonPath('query_task_id', $task['query_task_id'])
            ->assertJsonPath('query_task_attempt', 1)
            ->assertJsonPath('outcome', 'failed')
            ->assertJsonPath('reason', 'invalid_query_arguments')
            ->assertJsonPath('validation_errors.prefix.0', 'The prefix argument is required.')
            ->assertJsonPath('validation_errors.extra.0', 'Unknown argument [extra].');

        $stored = $broker->task((string) $task['query_task_id']);

        $this->assertSame('failed', $stored['status'] ?? null);
        $this->assertSame('invalid_query_arguments', $stored['reason'] ?? null);
        $this->assertSame(422, $stored['http_status'] ?? null);
        $this->assertSame(
            ['The prefix argument is required.'],
            $stored['validation_errors']['prefix'] ?? null,
        );
        $this->assertSame(
            ['Unknown argument [extra].'],
            $stored['validation_errors']['extra'] ?? null,
        );
    }

    public function test_query_task_completion_rejects_wrong_lease_before_reading_external_payload_reference(): void
    {
        Queue::fake();

        $directory = storage_path('framework/testing/query-task-external-storage');
        File::deleteDirectory($directory);
        WorkflowNamespace::query()->where('name', 'default')->update([
            'external_payload_storage' => [
                'driver' => 'local',
                'enabled' => true,
                'threshold_bytes' => 32,
                'config' => [
                    'uri' => 'file://'.$directory,
                ],
            ],
        ]);

        try {
            $run = $this->startRemoteWorkflow('wf-query-external-wrong-lease');
            $this->registerPythonWorker('python-query-external-lease', 'python-queries', ['python.queryable']);

            /** @var WorkflowQueryTaskBroker $broker */
            $broker = app(WorkflowQueryTaskBroker::class);
            $task = $broker->enqueue('default', $run, 'status', $this->queryArguments());

            $poll = $this->postJson('/api/worker/query-tasks/poll', [
                'worker_id' => 'python-query-external-lease',
                'task_queue' => 'python-queries',
            ], $this->workerHeaders());

            $poll->assertOk()
                ->assertJsonPath('task.query_task_id', $task['query_task_id'])
                ->assertJsonPath('task.lease_owner', 'python-query-external-lease');

            $missingPayload = Serializer::serializeWithCodec('avro', ['not-read']);
            $reference = app(RuntimeExternalPayloadRegistry::class)->upload(
                'default',
                $missingPayload,
                'avro',
                hash('sha256', $missingPayload),
            );
            $row = RuntimeExternalPayload::query()->whereKey($reference['reference_id'])->firstOrFail();
            $path = parse_url($row->storage_uri, PHP_URL_PATH);
            $this->assertIsString($path);
            File::delete($path);

            $this->postJson("/api/worker/query-tasks/{$task['query_task_id']}/complete", [
                'lease_owner' => 'wrong-query-worker',
                'query_task_attempt' => 1,
                'result_envelope' => [
                    'codec' => 'avro',
                    'external_payload' => $reference,
                ],
            ], $this->workerHeaders())
                ->assertStatus(409)
                ->assertJsonPath('reason', 'lease_owner_mismatch')
                ->assertJsonPath('lease_owner', 'python-query-external-lease');
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_control_plane_query_reports_unclaimed_task_timeout_without_result(): void
    {
        Queue::fake();

        $this->startRemoteWorkflow('wf-query-task-timeout');
        $this->registerPythonWorker('python-query-timeout-worker', 'python-queries', ['python.queryable']);
        $this->primeQueryTaskPoller('python-query-timeout-worker');

        $query = $this->postJson('/api/workflows/wf-query-task-timeout/query/status', [
            'input' => ['summary'],
        ], $this->apiHeaders());

        $query->assertStatus(504)
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertJsonPath('workflow_id', 'wf-query-task-timeout')
            ->assertJsonPath('query_name', 'status')
            ->assertJsonPath('reason', 'query_task_not_claimed')
            ->assertJsonPath('message', 'Timed out waiting for a compatible worker to claim workflow query [status] on task queue [python-queries].')
            ->assertJsonPath('control_plane.operation', 'query')
            ->assertJsonPath('control_plane.operation_name', 'status');

        $pollAfterTimeout = $this->postJson('/api/worker/query-tasks/poll', [
            'worker_id' => 'python-query-timeout-worker',
            'task_queue' => 'python-queries',
        ], $this->workerHeaders());

        $pollAfterTimeout->assertOk()
            ->assertJsonPath('task', null);
    }

    public function test_control_plane_query_reports_php_unclaimed_task_timeout_without_result(): void
    {
        Queue::fake();

        $this->startRemoteWorkflow(
            'wf-query-task-php-worker-timeout',
            workflowType: 'polyglot.php.signal.wait',
            taskQueue: 'polyglot-php',
        );
        $this->registerQueryWorker('php-query-timeout-worker', 'polyglot-php', ['polyglot.php.signal.wait'], 'php');
        $this->primeQueryTaskPoller('php-query-timeout-worker', 'polyglot-php');

        $query = $this->postJson('/api/workflows/wf-query-task-php-worker-timeout/query/status', [
            'input' => ['summary'],
        ], $this->apiHeaders());

        $query->assertStatus(504)
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertJsonPath('workflow_id', 'wf-query-task-php-worker-timeout')
            ->assertJsonPath('query_name', 'status')
            ->assertJsonPath('reason', 'query_task_not_claimed')
            ->assertJsonPath('message', 'Timed out waiting for a compatible worker to claim workflow query [status] on task queue [polyglot-php].')
            ->assertJsonPath('control_plane.operation', 'query')
            ->assertJsonPath('control_plane.operation_name', 'status');
    }

    public function test_control_plane_query_reports_leased_task_execution_timeout_without_result(): void
    {
        Queue::fake();

        $this->startRemoteWorkflow('wf-query-task-leased-timeout');
        $this->registerPythonWorker('python-query-leased-timeout-worker', 'python-queries', ['python.queryable']);

        /** @var LongPollSignalStore $signals */
        $signals = app(LongPollSignalStore::class);
        /** @var LongPollWaitSlotStore $waitSlots */
        $waitSlots = app(LongPollWaitSlotStore::class);
        $poller = new class($signals, $waitSlots) extends LongPoller
        {
            /** @var callable(): void|null */
            public $afterFirstUnreadyProbe = null;

            private bool $runningAfterProbe = false;

            public function until(
                callable $probe,
                callable $ready,
                ?int $timeoutSeconds = null,
                ?int $intervalMilliseconds = null,
                array $wakeChannels = [],
                ?callable $nextProbeAt = null,
                bool $reserveWorkerWaitSlot = false,
                string $waitSlotPool = 'worker',
                ?string $waitSlotNamespace = null,
            ): mixed {
                $value = $probe();

                if ($ready($value)) {
                    return $value;
                }

                if (! is_callable($this->afterFirstUnreadyProbe) || $this->runningAfterProbe) {
                    return $value;
                }

                $this->runningAfterProbe = true;

                try {
                    ($this->afterFirstUnreadyProbe)();
                } finally {
                    $this->runningAfterProbe = false;
                    $this->afterFirstUnreadyProbe = null;
                }

                return $probe();
            }
        };
        $broker = new WorkflowQueryTaskBroker(
            app(ServerPollingCache::class),
            $poller,
            $signals,
            app(ExternalPayloadEnvelopeService::class),
            app(QueryTaskPollRequestStore::class),
        );
        $this->app->instance(WorkflowQueryTaskBroker::class, $broker);

        $this->primeQueryTaskPoller('python-query-leased-timeout-worker');

        /** @var WorkerRegistration $worker */
        $worker = WorkerRegistration::query()
            ->where('namespace', 'default')
            ->where('worker_id', 'python-query-leased-timeout-worker')
            ->firstOrFail();
        $leasedTaskId = null;

        $poller->afterFirstUnreadyProbe = function () use ($broker, $worker, &$leasedTaskId): void {
            $task = $broker->poll('default', $worker);

            $this->assertIsArray($task);
            $this->assertSame('python-query-leased-timeout-worker', $task['lease_owner'] ?? null);

            $leasedTaskId = $task['query_task_id'] ?? null;
        };

        $query = $this->postJson('/api/workflows/wf-query-task-leased-timeout/query/status', [
            'input' => ['summary'],
        ], $this->apiHeaders());

        $query->assertStatus(504)
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertJsonPath('workflow_id', 'wf-query-task-leased-timeout')
            ->assertJsonPath('query_name', 'status')
            ->assertJsonPath('reason', 'query_worker_execution_timeout')
            ->assertJsonPath('message', 'Timed out waiting for worker [python-query-leased-timeout-worker] to complete workflow query [status].')
            ->assertJsonPath('control_plane.operation', 'query')
            ->assertJsonPath('control_plane.operation_name', 'status');

        $this->assertIsString($leasedTaskId);

        $stored = $broker->task($leasedTaskId);

        $this->assertSame('timed_out', $stored['status'] ?? null);
        $this->assertSame('python-query-leased-timeout-worker', $stored['lease_owner'] ?? null);
    }

    public function test_query_result_wait_ignores_exhausted_worker_long_poll_slots(): void
    {
        Queue::fake();
        config([
            'server.polling.max_concurrent_waits' => 1,
            'server.query_tasks.timeout' => 1,
        ]);

        $run = $this->startRemoteWorkflow('wf-query-task-slot-exhaustion');
        /** @var LongPollSignalStore $signals */
        $signals = app(LongPollSignalStore::class);
        /** @var LongPollWaitSlotStore $waitSlots */
        $waitSlots = app(LongPollWaitSlotStore::class);
        $heldSlot = $waitSlots->tryAcquire(1);
        $this->assertNotNull($heldSlot);

        $poller = new class($signals, $waitSlots) extends LongPoller
        {
            public int $pauseCalls = 0;

            /** @var callable(int): void|null */
            public $afterPause = null;

            protected function pause(int $milliseconds): void
            {
                $this->pauseCalls++;

                if (is_callable($this->afterPause)) {
                    ($this->afterPause)($this->pauseCalls);
                }
            }
        };

        $broker = new WorkflowQueryTaskBroker(
            app(ServerPollingCache::class),
            $poller,
            $signals,
            app(ExternalPayloadEnvelopeService::class),
            app(QueryTaskPollRequestStore::class),
        );
        $task = $broker->enqueue('default', $run, 'status', $this->queryArguments());
        $queryTaskId = (string) $task['query_task_id'];
        $putTask = new \ReflectionMethod(WorkflowQueryTaskBroker::class, 'putTask');
        $putTask->setAccessible(true);

        $poller->afterPause = function (int $pauseCalls) use ($broker, $signals, $putTask, $queryTaskId): void {
            if ($pauseCalls !== 1) {
                return;
            }

            $stored = $broker->task($queryTaskId);
            $this->assertIsArray($stored);

            $stored['status'] = 'completed';
            $stored['result'] = ['status' => 'ready'];
            $stored['result_envelope'] = null;
            $stored['completed_at'] = now()->toJSON();

            $putTask->invoke($broker, $stored);
            $signals->signalQueryTaskResult($queryTaskId);
        };

        try {
            $result = $broker->waitForResult($queryTaskId);
        } finally {
            $heldSlot->release();
        }

        $this->assertSame('completed', $result['status'] ?? null);
        $this->assertSame(['status' => 'ready'], $result['result'] ?? null);
        $this->assertSame(1, $poller->pauseCalls);
    }

    public function test_query_task_poll_waits_when_worker_long_poll_slots_are_exhausted(): void
    {
        Queue::fake();
        config([
            'server.polling.max_concurrent_waits' => 1,
            'server.polling.timeout' => 1,
            'server.query_tasks.max_concurrent_poll_waits' => 1,
        ]);

        $run = $this->startRemoteWorkflow('wf-query-task-wait-slot-starvation');
        $this->registerPythonWorker('python-query-wait-slot-worker', 'python-queries', ['python.queryable']);

        /** @var LongPollSignalStore $signals */
        $signals = app(LongPollSignalStore::class);
        /** @var LongPollWaitSlotStore $waitSlots */
        $waitSlots = app(LongPollWaitSlotStore::class);
        $heldSlot = $waitSlots->tryAcquire(1);
        $this->assertNotNull($heldSlot);

        $poller = new class($signals, $waitSlots) extends LongPoller
        {
            public int $pauseCalls = 0;

            /** @var callable(int): void|null */
            public $afterPause = null;

            protected function pause(int $milliseconds): void
            {
                $this->pauseCalls++;

                if (is_callable($this->afterPause)) {
                    ($this->afterPause)($this->pauseCalls);
                }
            }
        };

        $broker = new WorkflowQueryTaskBroker(
            app(ServerPollingCache::class),
            $poller,
            $signals,
            app(ExternalPayloadEnvelopeService::class),
            app(QueryTaskPollRequestStore::class),
        );
        $queryTaskId = null;
        /** @var WorkerRegistration $worker */
        $worker = WorkerRegistration::query()
            ->where('namespace', 'default')
            ->where('worker_id', 'python-query-wait-slot-worker')
            ->firstOrFail();

        $poller->afterPause = function (int $pauseCalls) use ($broker, $run, &$queryTaskId): void {
            if ($pauseCalls !== 1) {
                return;
            }

            $task = $broker->enqueue('default', $run, 'status', $this->queryArguments());
            $queryTaskId = $task['query_task_id'];
        };

        try {
            $poll = $broker->poll('default', $worker, 'query-poll-wait-slot-1');
        } finally {
            $heldSlot->release();
        }

        $this->assertSame($queryTaskId, $poll['query_task_id'] ?? null);
        $this->assertSame('python-query-wait-slot-worker', $poll['lease_owner'] ?? null);
        $this->assertSame(1, $poller->pauseCalls);
    }

    public function test_query_task_poll_backpressures_when_query_poll_wait_slots_are_exhausted(): void
    {
        Queue::fake();
        config([
            'server.polling.timeout' => 1,
            'server.query_tasks.max_concurrent_poll_waits' => 1,
        ]);

        $this->registerPythonWorker('python-query-slot-worker', 'python-queries', ['python.queryable']);

        /** @var LongPollSignalStore $signals */
        $signals = app(LongPollSignalStore::class);
        /** @var LongPollWaitSlotStore $waitSlots */
        $waitSlots = app(LongPollWaitSlotStore::class);
        $heldSlot = $waitSlots->tryAcquireQueryTaskPoll(1);
        $this->assertNotNull($heldSlot);

        $poller = new class($signals, $waitSlots) extends LongPoller
        {
            public int $pauseCalls = 0;

            protected function pause(int $milliseconds): void
            {
                $this->pauseCalls++;
            }
        };

        $broker = new WorkflowQueryTaskBroker(
            app(ServerPollingCache::class),
            $poller,
            $signals,
            app(ExternalPayloadEnvelopeService::class),
            app(QueryTaskPollRequestStore::class),
        );
        /** @var WorkerRegistration $worker */
        $worker = WorkerRegistration::query()
            ->where('namespace', 'default')
            ->where('worker_id', 'python-query-slot-worker')
            ->firstOrFail();

        try {
            try {
                $broker->poll('default', $worker, 'query-poll-slot-exhausted-1');

                $this->fail('An exhausted query-task poll wait pool must apply backpressure.');
            } catch (LongPollCapacityExhaustedException $exception) {
                $this->assertSame('query-task', $exception->pool);
            }
        } finally {
            $heldSlot->release();
        }

        $this->assertSame(0, $poller->pauseCalls);
    }

    public function test_pending_query_task_claim_does_not_require_idle_query_poll_wait_slot(): void
    {
        Queue::fake();
        config([
            'server.polling.timeout' => 1,
            'server.query_tasks.max_concurrent_poll_waits' => 0,
        ]);

        $run = $this->startRemoteWorkflow('wf-query-task-pending-without-slot');
        $this->registerPythonWorker('python-query-no-slot-worker', 'python-queries', ['python.queryable']);

        /** @var LongPollSignalStore $signals */
        $signals = app(LongPollSignalStore::class);
        /** @var LongPollWaitSlotStore $waitSlots */
        $waitSlots = app(LongPollWaitSlotStore::class);
        $poller = new class($signals, $waitSlots) extends LongPoller
        {
            public int $pauseCalls = 0;

            protected function pause(int $milliseconds): void
            {
                $this->pauseCalls++;
            }
        };

        $broker = new WorkflowQueryTaskBroker(
            app(ServerPollingCache::class),
            $poller,
            $signals,
            app(ExternalPayloadEnvelopeService::class),
            app(QueryTaskPollRequestStore::class),
        );
        $task = $broker->enqueue('default', $run, 'status', $this->queryArguments());

        /** @var WorkerRegistration $worker */
        $worker = WorkerRegistration::query()
            ->where('namespace', 'default')
            ->where('worker_id', 'python-query-no-slot-worker')
            ->firstOrFail();

        $poll = $broker->poll('default', $worker, 'query-poll-no-slot-1');

        $this->assertSame($task['query_task_id'], $poll['query_task_id'] ?? null);
        $this->assertSame('python-query-no-slot-worker', $poll['lease_owner'] ?? null);
        $this->assertSame(0, $poller->pauseCalls);
    }

    public function test_pending_query_task_interrupts_idle_workflow_task_poll(): void
    {
        Queue::fake();
        config(['server.polling.timeout' => 10]);

        $run = $this->startRemoteWorkflow('wf-query-task-interrupt-workflow-poll');
        WorkflowTask::query()->where('workflow_run_id', $run->id)->delete();
        $this->registerPythonWorker('python-query-workflow-interrupt-worker', 'python-queries', ['python.queryable']);
        $this->primeQueryTaskPoller('python-query-workflow-interrupt-worker');

        /** @var WorkflowQueryTaskBroker $broker */
        $broker = app(WorkflowQueryTaskBroker::class);
        $broker->enqueue('default', $run, 'status', $this->queryArguments());

        $poll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'python-query-workflow-interrupt-worker',
            'task_queue' => 'python-queries',
        ], $this->workerHeaders());

        $poll->assertOk()
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'query_task_pending');
    }

    public function test_advertised_query_capability_interrupts_workflow_poll_before_first_query_poll(): void
    {
        Queue::fake();
        config(['server.polling.timeout' => 10]);

        $run = $this->startRemoteWorkflow('wf-query-task-interrupt-before-query-poll');
        WorkflowTask::query()->where('workflow_run_id', $run->id)->delete();
        $this->registerPythonWorker('python-query-advertised-worker', 'python-queries', ['python.queryable']);

        /** @var WorkflowQueryTaskBroker $broker */
        $broker = app(WorkflowQueryTaskBroker::class);
        $broker->enqueue('default', $run, 'status', $this->queryArguments());

        $poll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'python-query-advertised-worker',
            'task_queue' => 'python-queries',
        ], $this->workerHeaders());

        $poll->assertOk()
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'query_task_pending');
    }

    public function test_ready_workflow_task_leases_before_claimable_query_task_poll(): void
    {
        Queue::fake();
        config(['server.polling.timeout' => 10]);

        $run = $this->startRemoteWorkflow('wf-query-task-ready-before-query');
        $this->registerPythonWorker('python-query-ready-before-query-worker', 'python-queries', ['python.queryable']);
        $this->primeQueryTaskPoller('python-query-ready-before-query-worker');

        /** @var WorkflowQueryTaskBroker $broker */
        $broker = app(WorkflowQueryTaskBroker::class);
        $queryTask = $broker->enqueue('default', $run, 'status', $this->queryArguments());

        $this->assertTrue(
            WorkflowTask::query()
                ->where('workflow_run_id', $run->id)
                ->where('task_type', 'workflow')
                ->where('status', 'ready')
                ->exists(),
        );

        $poll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'python-query-ready-before-query-worker',
            'task_queue' => 'python-queries',
        ], $this->workerHeaders());

        $poll->assertOk()
            ->assertJsonPath('poll_status', 'leased')
            ->assertJsonPath('task.run_id', $run->id)
            ->assertJsonPath('task.lease_owner', 'python-query-ready-before-query-worker');

        $this->assertSame(
            'pending',
            $broker->task((string) $queryTask['query_task_id'])['status'] ?? null,
        );
        $this->assertSame(
            TaskStatus::Leased,
            WorkflowTask::query()
                ->where('workflow_run_id', $run->id)
                ->where('task_type', 'workflow')
                ->value('status'),
        );
    }

    public function test_pending_query_task_does_not_block_initial_workflow_task_for_other_run(): void
    {
        Queue::fake();
        config(['server.polling.timeout' => 10]);

        $workerId = 'python-query-cross-run-ready-worker';
        $this->registerPythonWorker($workerId, 'python-queries', ['python.queryable']);
        $this->primeQueryTaskPoller($workerId);

        $queriedRun = $this->startRemoteWorkflow('wf-query-task-cross-run-query');

        $initialPoll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => $workerId,
            'task_queue' => 'python-queries',
        ], $this->workerHeaders());

        $initialPoll->assertOk()
            ->assertJsonPath('poll_status', 'leased')
            ->assertJsonPath('task.run_id', $queriedRun->id);

        $this->postJson("/api/worker/workflow-tasks/{$initialPoll->json('task.task_id')}/complete", [
            'lease_owner' => $initialPoll->json('task.lease_owner'),
            'workflow_task_attempt' => $initialPoll->json('task.workflow_task_attempt'),
            'commands' => [
                [
                    'type' => 'open_condition_wait',
                    'condition_key' => 'cross-run-query-ready',
                    'timeout_seconds' => 300,
                ],
            ],
        ], $this->workerHeaders())
            ->assertOk()
            ->assertJsonPath('run_status', 'waiting');

        /** @var WorkflowQueryTaskBroker $broker */
        $broker = app(WorkflowQueryTaskBroker::class);
        $queryTask = $broker->enqueue('default', $queriedRun->refresh(), 'current', $this->queryArguments());

        $readyRun = $this->startRemoteWorkflow('wf-query-task-cross-run-ready');

        $poll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => $workerId,
            'task_queue' => 'python-queries',
        ], $this->workerHeaders());

        $poll->assertOk()
            ->assertJsonPath('poll_status', 'leased')
            ->assertJsonPath('task.run_id', $readyRun->id)
            ->assertJsonPath('task.lease_owner', $workerId);

        $this->assertSame(
            'pending',
            $broker->task((string) $queryTask['query_task_id'])['status'] ?? null,
        );
    }

    public function test_ready_resume_workflow_task_blocks_query_preemption(): void
    {
        Queue::fake();
        config(['server.polling.timeout' => 0]);

        $run = $this->startRemoteWorkflow('wf-query-task-signal-resume-barrier');
        $this->registerPythonWorker('python-query-signal-resume-barrier-worker', 'python-queries', ['python.queryable']);
        $this->primeQueryTaskPoller('python-query-signal-resume-barrier-worker');

        /** @var WorkflowTask $readyTask */
        $readyTask = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', 'workflow')
            ->where('status', 'ready')
            ->firstOrFail();
        $readyTask->forceFill([
            'available_at' => now(),
            'payload' => [
                'workflow_wait_kind' => 'signal',
                'resume_source_kind' => 'workflow_signal',
                'workflow_signal_id' => 'sig-query-barrier',
                'signal_name' => 'increment',
            ],
        ])->save();

        /** @var WorkflowQueryTaskBroker $broker */
        $broker = app(WorkflowQueryTaskBroker::class);
        $queryTask = $broker->enqueue('default', $run, 'current', $this->queryArguments());

        $queryPoll = $this->postJson('/api/worker/query-tasks/poll', [
            'worker_id' => 'python-query-signal-resume-barrier-worker',
            'task_queue' => 'python-queries',
            'poll_request_id' => 'query-poll-signal-resume-barrier-1',
            'timeout_seconds' => 0,
        ], $this->workerHeaders());

        $queryPoll->assertOk()
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'workflow_task_pending');

        $duplicateQueryPoll = $this->postJson('/api/worker/query-tasks/poll', [
            'worker_id' => 'python-query-signal-resume-barrier-worker',
            'task_queue' => 'python-queries',
            'poll_request_id' => 'query-poll-signal-resume-barrier-1',
            'timeout_seconds' => 0,
        ], $this->workerHeaders());

        $duplicateQueryPoll->assertOk()
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'workflow_task_pending');

        $this->assertSame(
            'pending',
            $broker->task((string) $queryTask['query_task_id'])['status'] ?? null,
        );

        $workflowPoll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'python-query-signal-resume-barrier-worker',
            'task_queue' => 'python-queries',
        ], $this->workerHeaders());

        $workflowPoll->assertOk()
            ->assertJsonPath('poll_status', 'leased')
            ->assertJsonPath('task.task_id', $readyTask->id)
            ->assertJsonPath('task.workflow_wait_kind', 'signal')
            ->assertJsonPath('task.resume_source_kind', 'workflow_signal');
    }

    public function test_query_task_enqueued_during_replay_can_claim_before_later_signal_resume(): void
    {
        Queue::fake();
        config(['server.polling.timeout' => 0]);
        Carbon::setTestNow(Carbon::parse('2026-05-20 00:00:00.000000'));

        try {
            $workflowId = 'wf-query-task-replay-before-later-signal';
            $workerId = 'python-query-replay-before-later-signal-worker';
            $this->registerPythonWorker(
                $workerId,
                'python-queries',
                ['python.queryable'],
                workflowCommandContracts: [
                    'python.queryable' => [
                        'queries' => ['current'],
                        'query_contracts' => [
                            [
                                'name' => 'current',
                                'parameters' => [],
                            ],
                        ],
                        'signals' => ['increment'],
                        'signal_contracts' => [
                            [
                                'name' => 'increment',
                                'parameters' => [
                                    $this->typedCommandParameter('amount', 0, 'int'),
                                ],
                            ],
                        ],
                        'updates' => [],
                        'update_contracts' => [],
                    ],
                ],
            );
            $run = $this->startRemoteWorkflow($workflowId);

            $replayPoll = $this->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => $workerId,
                'task_queue' => 'python-queries',
            ], $this->workerHeaders());

            $replayPoll->assertOk()
                ->assertJsonPath('poll_status', 'leased')
                ->assertJsonPath('task.run_id', $run->id);

            /** @var WorkflowQueryTaskBroker $broker */
            $broker = app(WorkflowQueryTaskBroker::class);
            $queryTask = $broker->enqueue('default', $run->refresh(), 'current', $this->queryArguments());
            $historyCutoff = (int) $queryTask['history_cutoff_sequence'];

            Carbon::setTestNow(Carbon::parse('2026-05-20 00:00:01.000000'));
            $this->postJson("/api/workflows/{$workflowId}/signal/increment", [
                'input' => ['amount' => 5],
                'request_id' => 'replay-before-later-signal-1',
            ], $this->apiHeaders())
                ->assertAccepted()
                ->assertJsonPath('command_status', 'accepted');

            $blockedPollStartedAt = microtime(true);
            $duringReplay = $this->postJson('/api/worker/query-tasks/poll', [
                'worker_id' => $workerId,
                'task_queue' => 'python-queries',
                'timeout_seconds' => 1,
            ], $this->workerHeaders());
            $blockedPollDuration = microtime(true) - $blockedPollStartedAt;

            $duringReplay->assertOk()
                ->assertJsonPath('task', null)
                ->assertJsonPath('poll_status', 'workflow_task_leased');
            $this->assertGreaterThanOrEqual(
                0.75,
                $blockedPollDuration,
                'A replay-blocked long poll must not return an immediate hot-loop status.',
            );

            Carbon::setTestNow(Carbon::parse('2026-05-20 00:00:02.000000'));
            $this->postJson("/api/worker/workflow-tasks/{$replayPoll->json('task.task_id')}/complete", [
                'lease_owner' => $replayPoll->json('task.lease_owner'),
                'workflow_task_attempt' => $replayPoll->json('task.workflow_task_attempt'),
                'commands' => [
                    [
                        'type' => 'open_condition_wait',
                        'condition_key' => 'replay-before-later-signal',
                        'timeout_seconds' => 60,
                    ],
                ],
            ], $this->workerHeaders())
                ->assertOk();

            $readyResumeTask = WorkflowTask::query()
                ->where('workflow_run_id', $run->id)
                ->where('task_type', 'workflow')
                ->where('status', 'ready')
                ->where('payload->resume_source_kind', 'workflow_signal')
                ->firstOrFail();

            Carbon::setTestNow(Carbon::parse('2026-05-20 00:00:03.000000'));
            $queryPoll = $this->postJson('/api/worker/query-tasks/poll', [
                'worker_id' => $workerId,
                'task_queue' => 'python-queries',
                'timeout_seconds' => 0,
            ], $this->workerHeaders());

            $queryPoll->assertOk()
                ->assertJsonPath('poll_status', 'leased')
                ->assertJsonPath('task.query_task_id', $queryTask['query_task_id'])
                ->assertJsonPath('task.history_cutoff_sequence', $historyCutoff)
                ->assertJsonPath('task.last_history_sequence', $historyCutoff)
                ->assertJsonPath('task.history_export', null);

            $historyEventTypes = array_column((array) $queryPoll->json('task.history_events'), 'event_type');
            $this->assertNotContains('SignalReceived', $historyEventTypes);

            $workflowPoll = $this->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => $workerId,
                'task_queue' => 'python-queries',
            ], $this->workerHeaders());

            $workflowPoll->assertOk()
                ->assertJsonPath('poll_status', 'leased')
                ->assertJsonPath('task.task_id', $readyResumeTask->id)
                ->assertJsonPath('task.resume_source_kind', 'workflow_signal');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_query_task_without_cutoff_waits_for_later_signal_resume(): void
    {
        $this->assertQueryTaskWithoutCutoffWaitsForLaterResume('workflow_signal');
    }

    public function test_query_task_without_cutoff_waits_for_later_update_resume(): void
    {
        $this->assertQueryTaskWithoutCutoffWaitsForLaterResume('workflow_update');
    }

    public function test_query_task_poll_yields_without_long_poll_when_ready_resume_blocks_pending_query(): void
    {
        Queue::fake();
        config(['server.polling.timeout' => 10]);

        $run = $this->startRemoteWorkflow('wf-query-task-signal-resume-yield');
        $this->registerPythonWorker('python-query-signal-resume-yield-worker', 'python-queries', ['python.queryable']);

        /** @var WorkflowTask $readyTask */
        $readyTask = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', 'workflow')
            ->where('status', 'ready')
            ->firstOrFail();
        $readyTask->forceFill([
            'available_at' => now(),
            'payload' => [
                'workflow_wait_kind' => 'signal',
                'resume_source_kind' => 'workflow_signal',
                'workflow_signal_id' => 'sig-query-yield',
                'signal_name' => 'increment',
            ],
        ])->save();

        /** @var LongPollSignalStore $signals */
        $signals = app(LongPollSignalStore::class);
        /** @var LongPollWaitSlotStore $waitSlots */
        $waitSlots = app(LongPollWaitSlotStore::class);
        $poller = new class($signals, $waitSlots) extends LongPoller
        {
            public int $pauseCalls = 0;

            protected function pause(int $milliseconds): void
            {
                $this->pauseCalls++;
            }
        };

        $broker = new WorkflowQueryTaskBroker(
            app(ServerPollingCache::class),
            $poller,
            $signals,
            app(ExternalPayloadEnvelopeService::class),
            app(QueryTaskPollRequestStore::class),
        );
        $queryTask = $broker->enqueue('default', $run, 'current', $this->queryArguments());

        /** @var WorkerRegistration $worker */
        $worker = WorkerRegistration::query()
            ->where('namespace', 'default')
            ->where('worker_id', 'python-query-signal-resume-yield-worker')
            ->firstOrFail();

        $poll = $broker->pollResult('default', $worker, null, 10);

        $this->assertNull($poll['task']);
        $this->assertSame('workflow_task_pending', $poll['poll_status']);
        $this->assertSame(0, $poller->pauseCalls);
        $this->assertSame(
            'pending',
            $broker->task((string) $queryTask['query_task_id'])['status'] ?? null,
        );
    }

    public function test_open_wait_payload_without_resume_source_does_not_block_query_poll(): void
    {
        Queue::fake();
        config(['server.polling.timeout' => 0]);

        $run = $this->startRemoteWorkflow('wf-query-task-open-wait-does-not-block');
        $this->registerPythonWorker('python-query-open-wait-worker', 'python-queries', ['python.queryable']);

        /** @var WorkflowTask $readyTask */
        $readyTask = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', 'workflow')
            ->where('status', 'ready')
            ->firstOrFail();
        $readyTask->forceFill([
            'available_at' => now(),
            'payload' => [
                'workflow_wait_kind' => 'condition',
                'open_wait_id' => 'condition-query-open-wait',
                'condition_wait_id' => 'condition-query-open-wait',
                'condition_key' => 'ordered-total-ready',
            ],
        ])->save();

        /** @var WorkflowQueryTaskBroker $broker */
        $broker = app(WorkflowQueryTaskBroker::class);
        $queryTask = $broker->enqueue('default', $run, 'current', $this->queryArguments());

        $queryPoll = $this->postJson('/api/worker/query-tasks/poll', [
            'worker_id' => 'python-query-open-wait-worker',
            'task_queue' => 'python-queries',
        ], $this->workerHeaders());

        $queryPoll->assertOk()
            ->assertJsonPath('poll_status', 'leased')
            ->assertJsonPath('task.query_task_id', $queryTask['query_task_id'])
            ->assertJsonPath('task.lease_owner', 'python-query-open-wait-worker');

        $this->assertSame(
            TaskStatus::Ready,
            WorkflowTask::query()
                ->whereKey($readyTask->id)
                ->value('status'),
        );
    }

    public function test_query_task_poll_caps_each_wait_window(): void
    {
        Queue::fake();
        config([
            'server.polling.timeout' => 30,
            'server.query_tasks.poll_timeout' => 4,
        ]);

        $this->registerPythonWorker('python-query-poll-window-worker', 'python-queries', ['python.queryable']);

        /** @var LongPollSignalStore $signals */
        $signals = app(LongPollSignalStore::class);
        /** @var LongPollWaitSlotStore $waitSlots */
        $waitSlots = app(LongPollWaitSlotStore::class);
        $poller = new class($signals, $waitSlots) extends LongPoller
        {
            public ?int $timeoutSeconds = null;

            public function until(
                callable $probe,
                callable $ready,
                ?int $timeoutSeconds = null,
                ?int $intervalMilliseconds = null,
                array $wakeChannels = [],
                ?callable $nextProbeAt = null,
                bool $reserveWorkerWaitSlot = false,
                string $waitSlotPool = 'worker',
                ?string $waitSlotNamespace = null,
            ): mixed {
                $this->timeoutSeconds = $timeoutSeconds;

                return $probe();
            }
        };

        $broker = new WorkflowQueryTaskBroker(
            app(ServerPollingCache::class),
            $poller,
            $signals,
            app(ExternalPayloadEnvelopeService::class),
            app(QueryTaskPollRequestStore::class),
        );

        /** @var WorkerRegistration $worker */
        $worker = WorkerRegistration::query()
            ->where('namespace', 'default')
            ->where('worker_id', 'python-query-poll-window-worker')
            ->firstOrFail();

        $poll = $broker->pollResult('default', $worker, 'query-poll-window', 45);

        $this->assertNull($poll['task']);
        $this->assertSame('empty', $poll['poll_status']);
        $this->assertSame(4, $poller->timeoutSeconds);
    }

    public function test_python_same_key_signal_query_lifecycle_preserves_terminal_object_result(): void
    {
        Queue::fake();
        config(['server.polling.timeout' => 0]);

        $workflowId = 'wf-python-same-key-signal-query-result';
        $workerId = 'python-same-key-signal-query-worker';
        $conditionKey = 'polyglot.signal.polyglot-signal';
        $this->registerPythonWorker(
            $workerId,
            'python-queries',
            ['python.queryable'],
            workflowCommandContracts: [
                'python.queryable' => [
                    'queries' => ['state'],
                    'query_contracts' => [
                        [
                            'name' => 'state',
                            'parameters' => [],
                        ],
                    ],
                    'signals' => ['polyglot-signal'],
                    'signal_contracts' => [
                        [
                            'name' => 'polyglot-signal',
                            'parameters' => [
                                $this->typedCommandParameter('sequence', 0, 'int'),
                            ],
                        ],
                    ],
                    'updates' => [],
                    'update_contracts' => [],
                ],
            ],
        );
        $run = $this->startRemoteWorkflow($workflowId);

        $initialPoll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => $workerId,
            'task_queue' => 'python-queries',
        ], $this->workerHeaders());

        $initialPoll->assertOk()
            ->assertJsonPath('poll_status', 'leased')
            ->assertJsonPath('task.payload_codec', 'avro');

        $this->postJson("/api/worker/workflow-tasks/{$initialPoll->json('task.task_id')}/complete", [
            'lease_owner' => $initialPoll->json('task.lease_owner'),
            'workflow_task_attempt' => $initialPoll->json('task.workflow_task_attempt'),
            'commands' => [
                [
                    'type' => 'open_condition_wait',
                    'condition_key' => $conditionKey,
                    'timeout_seconds' => 300,
                ],
            ],
        ], $this->workerHeaders())
            ->assertOk()
            ->assertJsonPath('run_status', 'waiting');

        /** @var WorkflowQueryTaskBroker $broker */
        $broker = app(WorkflowQueryTaskBroker::class);
        $queryObservations = [];
        $observe = function (array $state) use (
            $broker,
            $run,
            $workerId,
            &$queryObservations,
        ): void {
            $historyCount = WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $run->id)
                ->count();
            $lastHistorySequence = (int) $run->refresh()->last_history_sequence;
            $runStatus = $run->status;
            $runOutput = $run->output;
            $queryTask = $broker->enqueue('default', $run, 'state', $this->queryArguments());

            $queryPoll = $this->postJson('/api/worker/query-tasks/poll', [
                'worker_id' => $workerId,
                'task_queue' => 'python-queries',
            ], $this->workerHeaders());

            $queryPoll->assertOk()
                ->assertJsonPath('poll_status', 'leased')
                ->assertJsonPath('task.query_task_id', $queryTask['query_task_id'])
                ->assertJsonPath('task.query_name', 'state');

            $resultEnvelope = [
                'codec' => 'avro',
                'blob' => Serializer::serializeWithCodec('avro', $state),
            ];
            $this->postJson("/api/worker/query-tasks/{$queryTask['query_task_id']}/complete", [
                'lease_owner' => $workerId,
                'query_task_attempt' => $queryPoll->json('task.query_task_attempt'),
                'result' => $state,
                'result_envelope' => $resultEnvelope,
            ], $this->workerHeaders())
                ->assertOk()
                ->assertJsonPath('outcome', 'completed');

            $stored = $broker->task((string) $queryTask['query_task_id']);
            $this->assertSame($state, $stored['result'] ?? null);
            $this->assertSame($resultEnvelope, $stored['result_envelope'] ?? null);
            $this->assertSame($historyCount, WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $run->id)
                ->count());
            $this->assertSame($lastHistorySequence, (int) $run->refresh()->last_history_sequence);
            $this->assertSame($runStatus, $run->status);
            $this->assertSame($runOutput, $run->output);

            $queryObservations[] = [
                'state' => $state,
                'history_events' => $queryPoll->json('task.history_events'),
                'result_envelope' => $stored['result_envelope'] ?? null,
            ];
        };

        $observe([
            'stage' => 'waiting',
            'signal_count' => 0,
            'signals' => [],
        ]);

        $this->postJson("/api/workflows/{$workflowId}/signal/polyglot-signal", [
            'input' => [1],
            'request_id' => 'python-same-key-signal-1',
        ], $this->apiHeaders())
            ->assertAccepted()
            ->assertJsonPath('command_status', 'accepted');

        $firstSignalPoll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => $workerId,
            'task_queue' => 'python-queries',
        ], $this->workerHeaders());

        $firstSignalPoll->assertOk()
            ->assertJsonPath('poll_status', 'leased')
            ->assertJsonPath('task.resume_source_kind', 'workflow_signal');

        $this->postJson("/api/worker/workflow-tasks/{$firstSignalPoll->json('task.task_id')}/complete", [
            'lease_owner' => $firstSignalPoll->json('task.lease_owner'),
            'workflow_task_attempt' => $firstSignalPoll->json('task.workflow_task_attempt'),
            'commands' => [
                [
                    'type' => 'open_condition_wait',
                    'condition_key' => $conditionKey,
                    'timeout_seconds' => 300,
                ],
            ],
        ], $this->workerHeaders())
            ->assertOk()
            ->assertJsonPath('run_status', 'waiting');

        $afterFirstSignal = [
            'stage' => 'signaled',
            'signal_count' => 1,
            'signals' => [1],
        ];
        $observe($afterFirstSignal);
        $observe($afterFirstSignal);

        $this->postJson("/api/workflows/{$workflowId}/signal/polyglot-signal", [
            'input' => [2],
            'request_id' => 'python-same-key-signal-2',
        ], $this->apiHeaders())
            ->assertAccepted()
            ->assertJsonPath('command_status', 'accepted');

        $terminalPoll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => $workerId,
            'task_queue' => 'python-queries',
        ], $this->workerHeaders());

        $terminalPoll->assertOk()
            ->assertJsonPath('poll_status', 'leased')
            ->assertJsonPath('task.resume_source_kind', 'workflow_signal');

        $expectedResult = [
            'workflow_runtime' => 'python',
            'request' => ['Ada'],
            'signal' => 1,
        ];
        $terminalEnvelope = [
            'codec' => 'avro',
            'blob' => Serializer::serializeWithCodec('avro', $expectedResult),
        ];
        $terminalCommands = [
            [
                'type' => 'complete_workflow',
                'result' => $terminalEnvelope,
            ],
        ];

        $this->assertCount(1, $terminalCommands);
        $this->assertSame('complete_workflow', $terminalCommands[0]['type']);
        $this->assertSame(
            $expectedResult,
            Serializer::unserializeWithCodec(
                $terminalCommands[0]['result']['codec'],
                $terminalCommands[0]['result']['blob'],
            ),
        );

        $this->postJson("/api/worker/workflow-tasks/{$terminalPoll->json('task.task_id')}/complete", [
            'lease_owner' => $terminalPoll->json('task.lease_owner'),
            'workflow_task_attempt' => $terminalPoll->json('task.workflow_task_attempt'),
            'commands' => $terminalCommands,
        ], $this->workerHeaders())
            ->assertOk()
            ->assertJsonPath('run_status', 'completed');

        $this->assertSame('avro', $run->refresh()->output_payload_codec);

        $completionEvents = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowCompleted->value)
            ->get();
        $this->assertCount(1, $completionEvents);
        $completionEvent = $completionEvents->first();
        $this->assertInstanceOf(WorkflowHistoryEvent::class, $completionEvent);
        $completionPayload = $completionEvent->payload;
        $this->assertIsArray($completionPayload);
        $this->assertSame('avro', $completionPayload['payload_codec'] ?? null);
        $this->assertSame($terminalEnvelope['blob'], $completionPayload['output'] ?? null);
        $this->assertSame(
            $expectedResult,
            Serializer::unserializeWithCodec(
                (string) $completionPayload['payload_codec'],
                (string) $completionPayload['output'],
            ),
        );

        foreach ([
            "/api/workflows/{$workflowId}",
            "/api/workflows/{$workflowId}/runs/{$run->id}",
        ] as $resultPath) {
            $this->getJson($resultPath, $this->apiHeaders())
                ->assertOk()
                ->assertJsonPath('output', $expectedResult)
                ->assertJsonPath('output_envelope', $terminalEnvelope);
        }

        $this->postJson("/api/worker/workflow-tasks/{$terminalPoll->json('task.task_id')}/complete", [
            'lease_owner' => $terminalPoll->json('task.lease_owner'),
            'workflow_task_attempt' => $terminalPoll->json('task.workflow_task_attempt'),
            'commands' => [
                [
                    'type' => 'complete_workflow',
                    'result' => [
                        'codec' => 'avro',
                        'blob' => Serializer::serializeWithCodec('avro', null),
                    ],
                ],
            ],
        ], $this->workerHeaders())
            ->assertStatus(409)
            ->assertJsonPath('reason', 'run_closed');

        $this->assertCount(3, $queryObservations);
        $this->assertSame([0, 1, 1], array_column(array_column($queryObservations, 'state'), 'signal_count'));
        $this->assertSame(1, WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowCompleted->value)
            ->count());

        foreach ([
            "/api/workflows/{$workflowId}",
            "/api/workflows/{$workflowId}/runs/{$run->id}",
        ] as $resultPath) {
            $this->getJson($resultPath, $this->apiHeaders())
                ->assertOk()
                ->assertJsonPath('output', $expectedResult);
        }
    }

    public function test_query_task_poll_leases_after_ordered_signal_replays_open_new_waits(): void
    {
        Queue::fake();
        config(['server.polling.timeout' => 0]);

        $workflowId = 'wf-query-task-after-ordered-signals';
        $workerId = 'python-query-after-ordered-signals-worker';
        $this->registerPythonWorker(
            $workerId,
            'python-queries',
            ['python.queryable'],
            workflowCommandContracts: [
                'python.queryable' => [
                    'queries' => ['current'],
                    'query_contracts' => [
                        [
                            'name' => 'current',
                            'parameters' => [],
                        ],
                    ],
                    'signals' => ['increment'],
                    'signal_contracts' => [
                        [
                            'name' => 'increment',
                            'parameters' => [
                                $this->typedCommandParameter('amount', 0, 'int'),
                            ],
                        ],
                    ],
                    'updates' => [],
                    'update_contracts' => [],
                ],
            ],
        );
        $run = $this->startRemoteWorkflow($workflowId);

        $initialPoll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => $workerId,
            'task_queue' => 'python-queries',
        ], $this->workerHeaders());

        $initialPoll->assertOk()
            ->assertJsonPath('poll_status', 'leased');

        $this->postJson("/api/worker/workflow-tasks/{$initialPoll->json('task.task_id')}/complete", [
            'lease_owner' => $initialPoll->json('task.lease_owner'),
            'workflow_task_attempt' => $initialPoll->json('task.workflow_task_attempt'),
            'commands' => [
                [
                    'type' => 'open_condition_wait',
                    'condition_key' => 'ordered-initial',
                    'timeout_seconds' => 300,
                ],
            ],
        ], $this->workerHeaders())
            ->assertOk()
            ->assertJsonPath('run_status', 'waiting');

        foreach (range(1, 10) as $amount) {
            $this->postJson("/api/workflows/{$workflowId}/signal/increment", [
                'input' => ['amount' => $amount],
                'request_id' => "ordered-signal-{$amount}",
            ], $this->apiHeaders())
                ->assertAccepted()
                ->assertJsonPath('command_status', 'accepted');
        }

        foreach (range(1, 10) as $index) {
            $signalPoll = $this->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => $workerId,
                'task_queue' => 'python-queries',
            ], $this->workerHeaders());

            $signalPoll->assertOk()
                ->assertJsonPath('poll_status', 'leased')
                ->assertJsonPath('task.resume_source_kind', 'workflow_signal')
                ->assertJsonPath('task.signal_name', 'increment');

            $this->postJson("/api/worker/workflow-tasks/{$signalPoll->json('task.task_id')}/complete", [
                'lease_owner' => $signalPoll->json('task.lease_owner'),
                'workflow_task_attempt' => $signalPoll->json('task.workflow_task_attempt'),
                'commands' => [
                    [
                        'type' => 'open_condition_wait',
                        'condition_key' => "ordered-after-{$index}",
                        'timeout_seconds' => 300,
                    ],
                ],
            ], $this->workerHeaders())
                ->assertOk()
                ->assertJsonPath('run_status', 'waiting');
        }

        /** @var WorkflowQueryTaskBroker $broker */
        $broker = app(WorkflowQueryTaskBroker::class);
        $queryTask = $broker->enqueue('default', $run->refresh(), 'current', $this->queryArguments());

        $queryPoll = $this->postJson('/api/worker/query-tasks/poll', [
            'worker_id' => $workerId,
            'task_queue' => 'python-queries',
        ], $this->workerHeaders());

        $queryPoll->assertOk()
            ->assertJsonPath('poll_status', 'leased')
            ->assertJsonPath('task.query_task_id', $queryTask['query_task_id'])
            ->assertJsonPath('task.lease_owner', $workerId);
    }

    public function test_query_task_poll_leases_when_ordered_signal_history_is_visible_before_all_signal_resume_tasks_apply(): void
    {
        Queue::fake();
        config(['server.polling.timeout' => 0]);

        $workflowId = 'wf-query-task-after-batched-ordered-signal-history';
        $workerId = 'python-query-after-batched-ordered-signal-history-worker';
        $this->registerPythonWorker(
            $workerId,
            'python-queries',
            ['python.queryable'],
            workflowCommandContracts: [
                'python.queryable' => [
                    'queries' => ['state'],
                    'query_contracts' => [
                        [
                            'name' => 'state',
                            'parameters' => [],
                        ],
                    ],
                    'signals' => ['increment'],
                    'signal_contracts' => [
                        [
                            'name' => 'increment',
                            'parameters' => [
                                $this->typedCommandParameter('amount', 0, 'int'),
                            ],
                        ],
                    ],
                    'updates' => [],
                    'update_contracts' => [],
                ],
            ],
        );
        $run = $this->startRemoteWorkflow($workflowId);

        $initialPoll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => $workerId,
            'task_queue' => 'python-queries',
        ], $this->workerHeaders());

        $initialPoll->assertOk()
            ->assertJsonPath('poll_status', 'leased');

        $this->postJson("/api/worker/workflow-tasks/{$initialPoll->json('task.task_id')}/complete", [
            'lease_owner' => $initialPoll->json('task.lease_owner'),
            'workflow_task_attempt' => $initialPoll->json('task.workflow_task_attempt'),
            'commands' => [
                [
                    'type' => 'open_condition_wait',
                    'condition_key' => 'ordered-initial',
                    'timeout_seconds' => 300,
                ],
            ],
        ], $this->workerHeaders())
            ->assertOk()
            ->assertJsonPath('run_status', 'waiting');

        foreach (range(1, 10) as $amount) {
            $this->postJson("/api/workflows/{$workflowId}/signal/increment", [
                'input' => ['amount' => $amount],
                'request_id' => "ordered-signal-{$amount}",
            ], $this->apiHeaders())
                ->assertAccepted()
                ->assertJsonPath('command_status', 'accepted');
        }

        $batchedSignalPoll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => $workerId,
            'task_queue' => 'python-queries',
        ], $this->workerHeaders());

        $batchedSignalPoll->assertOk()
            ->assertJsonPath('poll_status', 'leased')
            ->assertJsonPath('task.resume_source_kind', 'workflow_signal')
            ->assertJsonPath('task.signal_name', 'increment');

        $historySignalCount = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::SignalReceived->value)
            ->count();

        $this->assertSame(10, $historySignalCount);

        $batchedWorkflowSignalOrder = collect($batchedSignalPoll->json('task.history_events'))
            ->filter(fn (mixed $event): bool => is_array($event) && ($event['event_type'] ?? null) === 'SignalReceived')
            ->map(fn (array $event): ?int => $this->signalAmountFromHistoryPayload($event['payload'] ?? null))
            ->filter(static fn (?int $amount): bool => $amount !== null)
            ->values()
            ->all();

        $this->assertSame(range(1, 10), $batchedWorkflowSignalOrder);

        $this->postJson("/api/worker/workflow-tasks/{$batchedSignalPoll->json('task.task_id')}/complete", [
            'lease_owner' => $batchedSignalPoll->json('task.lease_owner'),
            'workflow_task_attempt' => $batchedSignalPoll->json('task.workflow_task_attempt'),
            'commands' => [
                [
                    'type' => 'open_condition_wait',
                    'condition_key' => 'ordered-after-batch',
                    'timeout_seconds' => 300,
                ],
            ],
        ], $this->workerHeaders())
            ->assertOk()
            ->assertJsonPath('run_status', 'waiting');

        $this->assertTrue(
            WorkflowTask::query()
                ->where('workflow_run_id', $run->id)
                ->where('task_type', 'workflow')
                ->where('status', 'ready')
                ->where('payload->resume_source_kind', 'workflow_signal')
                ->exists(),
        );

        /** @var WorkflowQueryTaskBroker $broker */
        $broker = app(WorkflowQueryTaskBroker::class);
        $queryTask = $broker->enqueue('default', $run->refresh(), 'state', $this->queryArguments());

        $queryPoll = $this->postJson('/api/worker/query-tasks/poll', [
            'worker_id' => $workerId,
            'task_queue' => 'python-queries',
        ], $this->workerHeaders());

        $queryPoll->assertOk()
            ->assertJsonPath('poll_status', 'leased')
            ->assertJsonPath('task.query_task_id', $queryTask['query_task_id'])
            ->assertJsonPath('task.lease_owner', $workerId);

        $queryHistorySignalOrder = collect($queryPoll->json('task.history_events'))
            ->filter(fn (mixed $event): bool => is_array($event) && ($event['event_type'] ?? null) === 'SignalReceived')
            ->map(fn (array $event): ?int => $this->signalAmountFromHistoryPayload($event['payload'] ?? null))
            ->filter(static fn (?int $amount): bool => $amount !== null)
            ->values()
            ->all();

        $this->assertSame(range(1, 10), $queryHistorySignalOrder);
        $this->assertSame(55, array_sum($queryHistorySignalOrder));
    }

    public function test_advertised_query_capability_leases_ready_workflow_task_without_query_poll_marker(): void
    {
        Queue::fake();
        config(['server.polling.timeout' => 10]);

        $run = $this->startRemoteWorkflow('wf-query-task-advertised-ready-first');
        $this->registerPythonWorker('python-query-advertised-ready-worker', 'python-queries', ['python.queryable']);

        /** @var WorkflowQueryTaskBroker $broker */
        $broker = app(WorkflowQueryTaskBroker::class);
        $queryTask = $broker->enqueue('default', $run, 'status', $this->queryArguments());

        $poll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'python-query-advertised-ready-worker',
            'task_queue' => 'python-queries',
        ], $this->workerHeaders());

        $poll->assertOk()
            ->assertJsonPath('poll_status', 'leased')
            ->assertJsonPath('task.run_id', $run->id)
            ->assertJsonPath('task.lease_owner', 'python-query-advertised-ready-worker');

        $this->assertSame(
            'pending',
            $broker->task((string) $queryTask['query_task_id'])['status'] ?? null,
        );
        $this->assertSame(
            TaskStatus::Leased,
            WorkflowTask::query()
                ->where('workflow_run_id', $run->id)
                ->where('task_type', 'workflow')
                ->value('status'),
        );

        $this->postJson("/api/worker/workflow-tasks/{$poll->json('task.task_id')}/complete", [
            'lease_owner' => $poll->json('task.lease_owner'),
            'workflow_task_attempt' => $poll->json('task.workflow_task_attempt'),
            'commands' => [
                [
                    'type' => 'open_condition_wait',
                    'condition_key' => 'advertised-ready-first',
                    'timeout_seconds' => 300,
                ],
            ],
        ], $this->workerHeaders())
            ->assertOk()
            ->assertJsonPath('run_status', 'waiting');

        $queryPoll = $this->postJson('/api/worker/query-tasks/poll', [
            'worker_id' => 'python-query-advertised-ready-worker',
            'task_queue' => 'python-queries',
        ], $this->workerHeaders());

        $queryPoll->assertOk()
            ->assertJsonPath('task.query_task_id', $queryTask['query_task_id'])
            ->assertJsonPath('task.lease_owner', 'python-query-advertised-ready-worker');
    }

    public function test_ready_workflow_task_leases_before_same_worker_query_task_while_other_run_lease_is_active(): void
    {
        Queue::fake();
        config(['server.polling.timeout' => 10]);

        $this->registerPythonWorker('python-query-same-lease-preempt-worker', 'python-queries', ['python.queryable']);
        $leasedRun = $this->startRemoteWorkflow('wf-query-task-preempts-same-worker-lease');

        $leased = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'python-query-same-lease-preempt-worker',
            'task_queue' => 'python-queries',
        ], $this->workerHeaders());

        $leased->assertOk()
            ->assertJsonPath('poll_status', 'leased')
            ->assertJsonPath('task.run_id', $leasedRun->id)
            ->assertJsonPath('task.lease_owner', 'python-query-same-lease-preempt-worker');

        $readyRun = $this->startRemoteWorkflow('wf-query-task-preempts-competing-ready');

        /** @var WorkflowQueryTaskBroker $broker */
        $broker = app(WorkflowQueryTaskBroker::class);
        $queryTask = $broker->enqueue('default', $leasedRun, 'status', $this->queryArguments());

        $poll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'python-query-same-lease-preempt-worker',
            'task_queue' => 'python-queries',
        ], $this->workerHeaders());

        $poll->assertOk()
            ->assertJsonPath('poll_status', 'leased')
            ->assertJsonPath('task.run_id', $readyRun->id)
            ->assertJsonPath('task.lease_owner', 'python-query-same-lease-preempt-worker');

        $this->assertSame(
            'pending',
            $broker->task((string) $queryTask['query_task_id'])['status'] ?? null,
        );
        $this->assertSame(
            TaskStatus::Leased,
            WorkflowTask::query()
                ->where('workflow_run_id', $readyRun->id)
                ->where('task_type', 'workflow')
                ->value('status'),
        );

        $queryPoll = $this->postJson('/api/worker/query-tasks/poll', [
            'worker_id' => 'python-query-same-lease-preempt-worker',
            'task_queue' => 'python-queries',
            'timeout_seconds' => 0,
        ], $this->workerHeaders());

        $queryPoll->assertOk()
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'workflow_task_leased');

        $this->assertSame(
            'pending',
            $broker->task((string) $queryTask['query_task_id'])['status'] ?? null,
        );
    }

    public function test_duplicate_workflow_poll_replays_cached_lease_before_same_worker_query_preemption(): void
    {
        Queue::fake();
        config(['server.polling.timeout' => 10]);

        $workerId = 'python-query-redeliver-before-preempt-worker';
        $this->registerPythonWorker($workerId, 'python-queries', ['python.queryable']);
        $run = $this->startRemoteWorkflow('wf-query-task-does-not-strand-lost-workflow-lease');

        $firstPoll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => $workerId,
            'task_queue' => 'python-queries',
            'poll_request_id' => 'workflow-query-redelivery-1',
        ], $this->workerHeaders());

        $firstPoll->assertOk()
            ->assertJsonPath('poll_status', 'leased')
            ->assertJsonPath('task.run_id', $run->id)
            ->assertJsonPath('task.lease_owner', $workerId)
            ->assertJsonPath('task.workflow_task_attempt', 1);

        $taskId = (string) $firstPoll->json('task.task_id');

        /** @var WorkflowQueryTaskBroker $broker */
        $broker = app(WorkflowQueryTaskBroker::class);
        $queryTask = $broker->enqueue('default', $run, 'status', $this->queryArguments());

        $queryPoll = $this->postJson('/api/worker/query-tasks/poll', [
            'worker_id' => $workerId,
            'task_queue' => 'python-queries',
            'timeout_seconds' => 0,
        ], $this->workerHeaders());

        $queryPoll->assertOk()
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'workflow_task_leased');

        $secondPoll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => $workerId,
            'task_queue' => 'python-queries',
            'poll_request_id' => 'workflow-query-redelivery-1',
        ], $this->workerHeaders());

        $secondPoll->assertOk()
            ->assertJsonPath('poll_status', 'leased')
            ->assertJsonPath('task.task_id', $taskId)
            ->assertJsonPath('task.run_id', $run->id)
            ->assertJsonPath('task.lease_owner', $workerId)
            ->assertJsonPath('task.workflow_task_attempt', 1);

        $this->assertSame(
            'pending',
            $broker->task((string) $queryTask['query_task_id'])['status'] ?? null,
        );
        $this->assertSame(
            1,
            WorkflowTask::query()
                ->whereKey($taskId)
                ->value('attempt_count'),
        );
    }

    public function test_query_task_preemption_requires_polling_worker_query_capability(): void
    {
        Queue::fake();
        config(['server.polling.timeout' => 10]);

        $run = $this->startRemoteWorkflow('wf-query-task-preempt-capability');
        $this->registerPythonWorker(
            'python-query-capable-worker',
            'python-queries',
            ['python.queryable'],
        );
        $this->registerPythonWorker(
            'python-workflow-only-worker',
            'python-queries',
            ['python.queryable'],
            capabilities: [],
        );

        /** @var WorkflowQueryTaskBroker $broker */
        $broker = app(WorkflowQueryTaskBroker::class);
        $queryTask = $broker->enqueue('default', $run, 'status', $this->queryArguments());

        $poll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'python-workflow-only-worker',
            'task_queue' => 'python-queries',
        ], $this->workerHeaders());

        $poll->assertOk()
            ->assertJsonPath('poll_status', 'leased')
            ->assertJsonPath('task.run_id', $run->id)
            ->assertJsonPath('task.lease_owner', 'python-workflow-only-worker');

        $this->assertSame(
            'pending',
            $broker->task((string) $queryTask['query_task_id'])['status'] ?? null,
        );
    }

    public function test_query_task_preemption_requires_matching_workflow_definition_fingerprint(): void
    {
        Queue::fake();
        config(['server.polling.timeout' => 10]);

        $run = $this->startRemoteWorkflow(
            'wf-query-task-preempt-fingerprint',
            workflowDefinitionFingerprint: 'sha256:python-counter',
        );
        $this->registerPythonWorker(
            'python-query-matching-fingerprint-worker',
            'python-queries',
            ['python.queryable'],
            workflowDefinitionFingerprints: ['python.queryable' => 'sha256:python-counter'],
        );
        $this->registerPythonWorker(
            'python-query-mismatched-fingerprint-worker',
            'python-queries',
            ['python.queryable'],
            workflowDefinitionFingerprints: ['python.queryable' => 'sha256:other-counter'],
        );

        /** @var WorkflowQueryTaskBroker $broker */
        $broker = app(WorkflowQueryTaskBroker::class);
        $queryTask = $broker->enqueue('default', $run, 'status', $this->queryArguments());

        $poll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'python-query-mismatched-fingerprint-worker',
            'task_queue' => 'python-queries',
        ], $this->workerHeaders());

        $poll->assertOk()
            ->assertJsonPath('poll_status', 'leased')
            ->assertJsonPath('task.run_id', $run->id)
            ->assertJsonPath('task.lease_owner', 'python-query-mismatched-fingerprint-worker');

        $this->assertSame(
            'pending',
            $broker->task((string) $queryTask['query_task_id'])['status'] ?? null,
        );
    }

    public function test_pending_query_task_interrupts_idle_activity_task_poll(): void
    {
        Queue::fake();
        config(['server.polling.timeout' => 10]);

        $run = $this->startRemoteWorkflow('wf-query-task-interrupt-activity-poll');
        WorkflowTask::query()->where('workflow_run_id', $run->id)->delete();
        $this->registerWorkerWithActivities(
            'python-query-activity-interrupt-worker',
            'python-queries',
            ['python.queryable'],
            ['python.activity'],
        );
        $this->primeQueryTaskPoller('python-query-activity-interrupt-worker');

        /** @var WorkflowQueryTaskBroker $broker */
        $broker = app(WorkflowQueryTaskBroker::class);
        $broker->enqueue('default', $run, 'status', $this->queryArguments());

        $poll = $this->postJson('/api/worker/activity-tasks/poll', [
            'worker_id' => 'python-query-activity-interrupt-worker',
            'task_queue' => 'python-queries',
        ], $this->workerHeaders());

        $poll->assertOk()
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'query_task_pending');
    }

    public function test_worker_task_polls_honor_request_timeout_seconds(): void
    {
        Queue::fake();
        config(['server.polling.timeout' => 2]);

        $this->registerWorkerWithActivities(
            'python-short-poll-worker',
            'python-short-polls',
            ['python.queryable'],
            ['python.activity'],
        );

        $workflowPoll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'python-short-poll-worker',
            'task_queue' => 'python-short-polls',
            'timeout_seconds' => 0,
        ], $this->workerHeaders());

        $workflowPoll->assertOk()
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'empty');

        $activityPoll = $this->postJson('/api/worker/activity-tasks/poll', [
            'worker_id' => 'python-short-poll-worker',
            'task_queue' => 'python-short-polls',
            'timeout_seconds' => 0,
        ], $this->workerHeaders());

        $activityPoll->assertOk()
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'empty');
    }

    public function test_query_task_lease_timeout_is_clamped_beyond_control_plane_wait(): void
    {
        Queue::fake();
        config([
            'server.query_tasks.timeout' => 20,
            'server.query_tasks.lease_timeout' => 2,
        ]);

        $run = $this->startRemoteWorkflow('wf-query-task-lease-clamp');
        $this->registerPythonWorker('python-query-lease-clamp-worker', 'python-queries', ['python.queryable']);

        /** @var WorkflowQueryTaskBroker $broker */
        $broker = app(WorkflowQueryTaskBroker::class);
        $task = $broker->enqueue('default', $run, 'status', $this->queryArguments());
        $claimedAfter = now();

        $poll = $this->postJson('/api/worker/query-tasks/poll', [
            'worker_id' => 'python-query-lease-clamp-worker',
            'task_queue' => 'python-queries',
        ], $this->workerHeaders());

        $poll->assertOk()
            ->assertJsonPath('task.query_task_id', $task['query_task_id']);

        $leaseExpiresAt = Carbon::parse((string) $poll->json('task.lease_expires_at'));
        $this->assertGreaterThanOrEqual(
            $claimedAfter->copy()->addSeconds(24)->getTimestamp(),
            $leaseExpiresAt->getTimestamp(),
        );

        $cluster = $this->getJson('/api/cluster/info', $this->apiHeaders());
        $cluster->assertOk()
            ->assertJsonPath('worker_protocol.server_capabilities.query_task_timeouts.control_plane_timeout_seconds', 20)
            ->assertJsonPath('worker_protocol.server_capabilities.query_task_timeouts.lease_timeout_seconds', 25)
            ->assertJsonPath('worker_protocol.server_capabilities.query_task_timeouts.lease_grace_seconds', 5);
    }

    public function test_query_task_completion_after_control_plane_timeout_returns_structured_rejection(): void
    {
        Queue::fake();

        $run = $this->startRemoteWorkflow('wf-query-task-late-complete');
        $this->registerPythonWorker('python-query-late-complete-worker', 'python-queries', ['python.queryable']);

        /** @var WorkflowQueryTaskBroker $broker */
        $broker = app(WorkflowQueryTaskBroker::class);
        $task = $broker->enqueue('default', $run, 'status', $this->queryArguments());

        $poll = $this->postJson('/api/worker/query-tasks/poll', [
            'worker_id' => 'python-query-late-complete-worker',
            'task_queue' => 'python-queries',
        ], $this->workerHeaders());

        $poll->assertOk()
            ->assertJsonPath('task.query_task_id', $task['query_task_id'])
            ->assertJsonPath('task.lease_owner', 'python-query-late-complete-worker');

        $stored = $broker->task((string) $task['query_task_id']);
        $this->assertIsArray($stored);

        $stored['status'] = 'timed_out';
        $stored['timed_out_at'] = now()->toJSON();

        $putTask = new \ReflectionMethod(WorkflowQueryTaskBroker::class, 'putTask');
        $putTask->setAccessible(true);
        $putTask->invoke($broker, $stored);

        $this->postJson("/api/worker/query-tasks/{$task['query_task_id']}/complete", [
            'lease_owner' => 'python-query-late-complete-worker',
            'query_task_attempt' => 1,
            'result' => ['status' => 'ready'],
        ], $this->workerHeaders())
            ->assertStatus(409)
            ->assertJsonPath('query_task_id', $task['query_task_id'])
            ->assertJsonPath('outcome', 'rejected')
            ->assertJsonPath('reason', 'query_task_timed_out')
            ->assertJsonPath('error', 'Query task timed out before completion.');
    }

    public function test_query_task_enqueue_rejects_when_per_queue_pending_limit_is_reached(): void
    {
        Queue::fake();
        config(['server.query_tasks.max_pending_per_queue' => 1]);

        $run = $this->startRemoteWorkflow('wf-query-task-enqueue-limit');

        /** @var WorkflowQueryTaskBroker $broker */
        $broker = app(WorkflowQueryTaskBroker::class);

        $broker->enqueue('default', $run, 'status', $this->queryArguments());

        $this->expectException(QueryTaskQueueFullException::class);

        $broker->enqueue('default', $run, 'status', $this->queryArguments());
    }

    public function test_control_plane_query_reports_queue_full_when_pending_limit_is_reached(): void
    {
        Queue::fake();
        config(['server.query_tasks.max_pending_per_queue' => 1]);

        $run = $this->startRemoteWorkflow('wf-query-task-full-response');
        $this->registerPythonWorker('python-query-full-worker', 'python-queries', ['python.queryable']);
        $this->primeQueryTaskPoller('python-query-full-worker');

        /** @var WorkflowQueryTaskBroker $broker */
        $broker = app(WorkflowQueryTaskBroker::class);
        $broker->enqueue('default', $run, 'status', $this->queryArguments());

        $query = $this->postJson('/api/workflows/wf-query-task-full-response/query/status', [
            'input' => ['summary'],
        ], $this->apiHeaders());

        $query->assertStatus(429)
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertJsonPath('workflow_id', 'wf-query-task-full-response')
            ->assertJsonPath('query_name', 'status')
            ->assertJsonPath('reason', 'query_task_queue_full')
            ->assertJsonPath('control_plane.operation', 'query')
            ->assertJsonPath('control_plane.operation_name', 'status');
    }

    public function test_worker_query_task_poll_reports_typed_503_when_cache_store_does_not_support_locks(): void
    {
        $this->bindPollingCacheStore(new WorkflowQueryTaskBrokerTestCacheStore);
        $this->registerPythonWorker('python-query-unlocked-worker', 'python-queries', ['python.queryable']);

        $poll = $this->postJson('/api/worker/query-tasks/poll', [
            'worker_id' => 'python-query-unlocked-worker',
            'task_queue' => 'python-queries',
        ], $this->workerHeaders());

        $poll->assertStatus(503)
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertHeaderMissing(ControlPlaneProtocol::HEADER)
            ->assertJsonPath('protocol_version', WorkerProtocol::VERSION)
            ->assertJsonPath('task', null)
            ->assertJsonPath('reason', 'query_task_queue_unavailable')
            ->assertJsonPath('error', 'Query task queue is temporarily unavailable.')
            ->assertJsonPath('namespace', 'default')
            ->assertJsonPath('task_queue', 'python-queries')
            ->assertJsonPath('server_capabilities.query_tasks', true)
            ->assertJsonMissingPath('control_plane');
    }

    public function test_control_plane_query_reports_typed_503_without_orphaning_task_when_cache_store_does_not_support_locks(): void
    {
        Queue::fake();

        $store = new WorkflowQueryTaskBrokerTestCacheStore;
        $this->bindPollingCacheStore($store);
        $this->startRemoteWorkflow('wf-query-task-unlocked-response');
        $this->registerPythonWorker('python-query-unlocked-worker', 'python-queries', ['python.queryable']);

        $this->postJson('/api/worker/query-tasks/poll', [
            'worker_id' => 'python-query-unlocked-worker',
            'task_queue' => 'python-queries',
        ], $this->workerHeaders())->assertStatus(503);

        $query = $this->postJson('/api/workflows/wf-query-task-unlocked-response/query/status', [
            'input' => ['summary'],
        ], $this->apiHeaders());

        $query->assertStatus(503)
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertJsonPath('workflow_id', 'wf-query-task-unlocked-response')
            ->assertJsonPath('query_name', 'status')
            ->assertJsonPath('reason', 'query_task_queue_unavailable')
            ->assertJsonPath('control_plane.operation', 'query')
            ->assertJsonPath('control_plane.operation_name', 'status');

        $this->assertSame([], $store->keysStartingWith('server:workflow-query-task:task:'));
        $this->assertSame([], $store->keysStartingWith('server:workflow-query-task:queue:'));
    }

    public function test_worker_query_task_poll_reports_typed_503_when_queue_lock_times_out(): void
    {
        $this->bindPollingCacheStore(new WorkflowQueryTaskBrokerTestLockTimeoutStore);
        $this->registerPythonWorker('python-query-lock-timeout-worker', 'python-queries', ['python.queryable']);

        $poll = $this->postJson('/api/worker/query-tasks/poll', [
            'worker_id' => 'python-query-lock-timeout-worker',
            'task_queue' => 'python-queries',
        ], $this->workerHeaders());

        $poll->assertStatus(503)
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertHeaderMissing(ControlPlaneProtocol::HEADER)
            ->assertJsonPath('protocol_version', WorkerProtocol::VERSION)
            ->assertJsonPath('task', null)
            ->assertJsonPath('reason', 'query_task_queue_unavailable')
            ->assertJsonPath('error', 'Query task queue is temporarily unavailable.')
            ->assertJsonPath('message', static fn (mixed $message): bool => is_string($message)
                && str_contains($message, 'Timed out waiting for the query task queue lock.'))
            ->assertJsonPath('namespace', 'default')
            ->assertJsonPath('task_queue', 'python-queries')
            ->assertJsonPath('server_capabilities.query_tasks', true)
            ->assertJsonMissingPath('control_plane');
    }

    public function test_control_plane_query_reports_typed_503_without_orphaning_task_when_queue_lock_times_out(): void
    {
        Queue::fake();

        $store = new WorkflowQueryTaskBrokerTestLockTimeoutStore;
        $this->bindPollingCacheStore($store);
        $this->startRemoteWorkflow('wf-query-task-lock-timeout-response');
        $this->registerPythonWorker('python-query-lock-timeout-worker', 'python-queries', ['python.queryable']);

        $this->postJson('/api/worker/query-tasks/poll', [
            'worker_id' => 'python-query-lock-timeout-worker',
            'task_queue' => 'python-queries',
        ], $this->workerHeaders())->assertStatus(503);

        $query = $this->postJson('/api/workflows/wf-query-task-lock-timeout-response/query/status', [
            'input' => ['summary'],
        ], $this->apiHeaders());

        $query->assertStatus(503)
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertJsonPath('workflow_id', 'wf-query-task-lock-timeout-response')
            ->assertJsonPath('query_name', 'status')
            ->assertJsonPath('reason', 'query_task_queue_unavailable')
            ->assertJsonPath('message', static fn (mixed $message): bool => is_string($message)
                && str_contains($message, 'Timed out waiting for the query task queue lock.'))
            ->assertJsonPath('control_plane.operation', 'query')
            ->assertJsonPath('control_plane.operation_name', 'status');

        $this->assertSame([], $store->keysStartingWith('server:workflow-query-task:task:'));
        $this->assertSame([], $store->keysStartingWith('server:workflow-query-task:queue:'));
    }

    public function test_concurrent_query_task_enqueues_are_atomic_for_file_cache_backend(): void
    {
        $cachePath = sys_get_temp_dir().'/dw-server-query-task-race-'.bin2hex(random_bytes(5));
        $readyDir = $cachePath.'-ready';
        $barrierPath = $cachePath.'.release';
        $processCount = 8;
        $limit = 3;
        $processes = [];

        File::ensureDirectoryExists($cachePath);
        File::ensureDirectoryExists($readyDir);

        config([
            'cache.default' => 'file',
            'server.polling.cache_path' => $cachePath,
            'server.query_tasks.max_pending_per_queue' => $limit,
        ]);

        try {
            for ($i = 0; $i < $processCount; $i++) {
                $process = new Process([
                    PHP_BINARY,
                    base_path('tests/Fixtures/query_task_enqueue_worker.php'),
                    $cachePath,
                    $barrierPath,
                    $readyDir,
                    (string) $limit,
                    'default',
                    'python-queries',
                    'worker-'.$i,
                ], base_path());
                $process->setTimeout(30);
                $process->start();

                $processes[] = $process;
            }

            $this->waitForReadyQueryTaskEnqueueWorkers($readyDir, $processCount, $processes);

            touch($barrierPath);

            $results = array_map(
                fn (Process $process): array => $this->queryTaskEnqueueWorkerResult($process),
                $processes,
            );

            $errors = array_values(array_filter(
                $results,
                static fn (array $result): bool => ($result['status'] ?? null) === 'error',
            ));

            $this->assertSame([], $errors);

            $enqueuedIds = array_values(array_map(
                static fn (array $result): string => (string) $result['query_task_id'],
                array_filter($results, static fn (array $result): bool => ($result['status'] ?? null) === 'enqueued'),
            ));
            $fullResults = array_values(array_filter(
                $results,
                static fn (array $result): bool => ($result['status'] ?? null) === 'full',
            ));

            $this->assertCount($limit, $enqueuedIds);
            $this->assertCount($processCount - $limit, $fullResults);

            /** @var ServerPollingCache $cache */
            $cache = app(ServerPollingCache::class);
            $store = $cache->store();
            $queueIds = $store->get('server:workflow-query-task:queue:'.sha1('default|python-queries'));

            $this->assertIsArray($queueIds);
            sort($queueIds);
            sort($enqueuedIds);

            $this->assertSame($enqueuedIds, $queueIds);

            foreach ($queueIds as $queryTaskId) {
                $task = $store->get('server:workflow-query-task:task:'.$queryTaskId);

                $this->assertIsArray($task);
                $this->assertSame('pending', $task['status'] ?? null);
            }
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(0);
                }
            }

            File::deleteDirectory($cachePath);
            File::deleteDirectory($readyDir);
            @unlink($barrierPath);
        }
    }

    private function startRemoteWorkflow(
        string $workflowId,
        string $workflowType = 'python.queryable',
        string $taskQueue = 'python-queries',
        ?string $workflowDefinitionFingerprint = null,
    ): WorkflowRun {
        $start = $this->postJson('/api/workflows', [
            'workflow_id' => $workflowId,
            'workflow_type' => $workflowType,
            'task_queue' => $taskQueue,
            'input' => ['Ada'],
        ], $this->apiHeaders());

        $start->assertCreated();

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail((string) $start->json('run_id'));

        if ($workflowDefinitionFingerprint !== null) {
            /** @var WorkflowHistoryEvent $started */
            $started = WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $run->id)
                ->where('event_type', HistoryEventType::WorkflowStarted->value)
                ->firstOrFail();
            $payload = is_array($started->payload) ? $started->payload : [];
            $payload['workflow_definition_fingerprint'] = $workflowDefinitionFingerprint;
            $started->forceFill(['payload' => $payload])->save();
        }

        return $run->refresh();
    }

    /**
     * @return array{codec: string, blob: string}
     */
    private function queryArguments(): array
    {
        return [
            'codec' => 'avro',
            'blob' => Serializer::serializeWithCodec('avro', ['summary']),
        ];
    }

    private function signalAmountFromHistoryPayload(mixed $payload): ?int
    {
        if (! is_array($payload)) {
            return null;
        }

        $arguments = $payload['arguments'] ?? null;

        if (is_array($arguments)) {
            try {
                $arguments = Serializer::unserializeWithCodec(
                    (string) ($arguments['codec'] ?? ''),
                    (string) ($arguments['blob'] ?? ''),
                );
            } catch (\Throwable) {
                return null;
            }
        }

        return $this->signalAmountFromDecodedArguments($arguments);
    }

    private function signalAmountFromDecodedArguments(mixed $arguments): ?int
    {
        if (is_int($arguments)) {
            return $arguments;
        }

        if (! is_array($arguments)) {
            return null;
        }

        if (isset($arguments['amount']) && is_int($arguments['amount'])) {
            return $arguments['amount'];
        }

        $first = $arguments[0] ?? null;

        if (is_int($first)) {
            return $first;
        }

        return is_array($first) && isset($first['amount']) && is_int($first['amount'])
            ? $first['amount']
            : null;
    }

    private function assertQueryTaskWithoutCutoffWaitsForLaterResume(string $resumeKind): void
    {
        Queue::fake();
        config(['server.polling.timeout' => 0]);
        Carbon::setTestNow(Carbon::parse('2026-05-20 00:10:00.000000'));

        try {
            $suffix = $resumeKind === 'workflow_update' ? 'update' : 'signal';
            $workflowId = "wf-query-task-no-cutoff-later-{$suffix}";
            $workerId = "python-query-no-cutoff-later-{$suffix}-worker";

            $this->registerPythonWorker(
                $workerId,
                'python-queries',
                ['python.queryable'],
                workflowCommandContracts: [
                    'python.queryable' => $this->querySignalUpdateCommandContract(),
                ],
            );
            $run = $this->startRemoteWorkflow($workflowId);

            $initialPoll = $this->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => $workerId,
                'task_queue' => 'python-queries',
            ], $this->workerHeaders());

            $initialPoll->assertOk()
                ->assertJsonPath('poll_status', 'leased')
                ->assertJsonPath('task.run_id', $run->id);

            $this->postJson("/api/worker/workflow-tasks/{$initialPoll->json('task.task_id')}/complete", [
                'lease_owner' => $initialPoll->json('task.lease_owner'),
                'workflow_task_attempt' => $initialPoll->json('task.workflow_task_attempt'),
                'commands' => [
                    [
                        'type' => 'open_signal_wait',
                        'signal_name' => 'increment',
                        'timeout_seconds' => 300,
                    ],
                ],
            ], $this->workerHeaders())
                ->assertOk()
                ->assertJsonPath('run_status', 'waiting');

            Carbon::setTestNow(Carbon::parse('2026-05-20 00:10:01.000000'));
            /** @var WorkflowQueryTaskBroker $broker */
            $broker = app(WorkflowQueryTaskBroker::class);
            $queryTask = $broker->enqueue('default', $run->refresh(), 'current', $this->queryArguments());

            $this->assertArrayNotHasKey('history_cutoff_sequence', $queryTask);

            Carbon::setTestNow(Carbon::parse('2026-05-20 00:10:02.000000'));

            if ($resumeKind === 'workflow_update') {
                $this->postJson("/api/workflows/{$workflowId}/update/approve", [
                    'input' => [true],
                    'request_id' => 'no-cutoff-later-update-1',
                    'wait_for' => 'accepted',
                ], $this->apiHeaders())
                    ->assertAccepted()
                    ->assertJsonPath('command_status', 'accepted');
            } else {
                $this->postJson("/api/workflows/{$workflowId}/signal/increment", [
                    'input' => ['amount' => 5],
                    'request_id' => 'no-cutoff-later-signal-1',
                ], $this->apiHeaders())
                    ->assertAccepted()
                    ->assertJsonPath('command_status', 'accepted');
            }

            $readyResumeTask = WorkflowTask::query()
                ->where('workflow_run_id', $run->id)
                ->where('task_type', 'workflow')
                ->where('status', 'ready')
                ->where('payload->resume_source_kind', $resumeKind)
                ->firstOrFail();

            $this->assertTrue(
                $readyResumeTask->created_at->gt(Carbon::parse((string) $queryTask['created_at'])),
            );

            $queryPoll = $this->postJson('/api/worker/query-tasks/poll', [
                'worker_id' => $workerId,
                'task_queue' => 'python-queries',
                'timeout_seconds' => 0,
            ], $this->workerHeaders());

            $queryPoll->assertOk()
                ->assertJsonPath('task', null)
                ->assertJsonPath('poll_status', 'workflow_task_pending');

            $stored = $broker->task((string) $queryTask['query_task_id']);
            $this->assertIsArray($stored);
            $this->assertSame('pending', $stored['status'] ?? null);
            $this->assertArrayNotHasKey('history_cutoff_sequence', $stored);

            $workflowPoll = $this->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => $workerId,
                'task_queue' => 'python-queries',
            ], $this->workerHeaders());

            $workflowPoll->assertOk()
                ->assertJsonPath('poll_status', 'leased')
                ->assertJsonPath('task.task_id', $readyResumeTask->id)
                ->assertJsonPath('task.resume_source_kind', $resumeKind);
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function querySignalUpdateCommandContract(): array
    {
        return [
            'queries' => ['current'],
            'query_contracts' => [
                [
                    'name' => 'current',
                    'parameters' => [],
                ],
            ],
            'signals' => ['increment'],
            'signal_contracts' => [
                [
                    'name' => 'increment',
                    'parameters' => [
                        $this->typedCommandParameter('amount', 0, 'int'),
                    ],
                ],
            ],
            'updates' => ['approve'],
            'update_validators' => [],
            'update_contracts' => [
                [
                    'name' => 'approve',
                    'parameters' => [
                        $this->typedCommandParameter('approved', 0, 'bool'),
                    ],
                ],
            ],
        ];
    }

    private function primeQueryTaskPoller(string $workerId, string $taskQueue = 'python-queries'): void
    {
        $pollingTimeout = config('server.polling.timeout');

        config(['server.polling.timeout' => 0]);

        try {
            $this->postJson('/api/worker/query-tasks/poll', [
                'worker_id' => $workerId,
                'task_queue' => $taskQueue,
            ], $this->workerHeaders())
                ->assertOk()
                ->assertJsonPath('task', null)
                ->assertJsonPath('poll_status', 'empty');
        } finally {
            config(['server.polling.timeout' => $pollingTimeout]);
        }
    }

    /**
     * @param  list<string>  $supportedWorkflowTypes
     */
    private function registerPythonWorker(
        string $workerId,
        string $taskQueue,
        array $supportedWorkflowTypes,
        array $capabilities = ['query_tasks'],
        array $workflowDefinitionFingerprints = [],
        array $workflowCommandContracts = [],
        ?string $buildId = null,
    ): void {
        $this->registerQueryWorker(
            $workerId,
            $taskQueue,
            $supportedWorkflowTypes,
            'python',
            $capabilities,
            $workflowDefinitionFingerprints,
            $workflowCommandContracts,
            $buildId,
        );
    }

    /**
     * @param  list<string>  $supportedWorkflowTypes
     * @param  list<string>  $capabilities
     * @param  array<string, string>  $workflowDefinitionFingerprints
     * @param  array<string, array<string, mixed>>  $workflowCommandContracts
     */
    private function registerQueryWorker(
        string $workerId,
        string $taskQueue,
        array $supportedWorkflowTypes,
        string $runtime,
        array $capabilities = ['query_tasks'],
        array $workflowDefinitionFingerprints = [],
        array $workflowCommandContracts = [],
        ?string $buildId = null,
    ): void {
        WorkerRegistration::query()->updateOrCreate(
            ['worker_id' => $workerId, 'namespace' => 'default'],
            [
                'task_queue' => $taskQueue,
                'runtime' => $runtime,
                'sdk_version' => 'durable-workflow-'.$runtime.'/0.2.0',
                'build_id' => $buildId,
                'supported_workflow_types' => $supportedWorkflowTypes,
                'workflow_definition_fingerprints' => $workflowDefinitionFingerprints,
                'workflow_command_contracts' => $workflowCommandContracts,
                'supported_activity_types' => [],
                'capabilities' => $capabilities,
                'last_heartbeat_at' => now(),
                'status' => 'active',
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function externalCounterCommandContract(string $queryName): array
    {
        return [
            'queries' => ['state', $queryName],
            'query_contracts' => [
                [
                    'name' => 'state',
                    'parameters' => [],
                ],
                [
                    'name' => $queryName,
                    'parameters' => [
                        $this->typedCommandParameter('minimum', 0, 'int'),
                    ],
                ],
            ],
            'signals' => [],
            'signal_contracts' => [],
            'updates' => [],
            'update_contracts' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function typedCommandParameter(string $name, int $position, string $type): array
    {
        return [
            'name' => $name,
            'position' => $position,
            'required' => true,
            'variadic' => false,
            'default_available' => false,
            'default' => null,
            'type' => $type,
            'allows_null' => false,
        ];
    }

    /**
     * @param  list<string>  $supportedWorkflowTypes
     * @param  list<string>  $supportedActivityTypes
     */
    private function registerWorkerWithActivities(
        string $workerId,
        string $taskQueue,
        array $supportedWorkflowTypes,
        array $supportedActivityTypes,
    ): void {
        WorkerRegistration::query()->updateOrCreate(
            ['worker_id' => $workerId, 'namespace' => 'default'],
            [
                'task_queue' => $taskQueue,
                'runtime' => 'python',
                'sdk_version' => 'durable-workflow-python/0.2.0',
                'supported_workflow_types' => $supportedWorkflowTypes,
                'workflow_definition_fingerprints' => [],
                'supported_activity_types' => $supportedActivityTypes,
                'capabilities' => ['query_tasks'],
                'last_heartbeat_at' => now(),
                'status' => 'active',
            ],
        );
    }

    private function bindPollingCacheStore(CacheStore $store): void
    {
        $cache = app(ServerPollingCache::class);
        $repository = new CacheRepository($store);
        $property = new \ReflectionProperty(ServerPollingCache::class, 'store');
        $property->setAccessible(true);
        $property->setValue($cache, $repository);

        $this->app->instance(ServerPollingCache::class, $cache);
    }

    /**
     * @param  list<Process>  $processes
     */
    private function waitForReadyQueryTaskEnqueueWorkers(string $readyDir, int $expected, array $processes): void
    {
        $deadline = microtime(true) + 15;

        while ($this->readyQueryTaskEnqueueWorkerCount($readyDir) < $expected && microtime(true) < $deadline) {
            foreach ($processes as $process) {
                if (! $process->isRunning()) {
                    $this->fail("Query-task enqueue worker exited before the barrier.\n".$process->getOutput().$process->getErrorOutput());
                }
            }

            usleep(10000);
        }

        $this->assertSame($expected, $this->readyQueryTaskEnqueueWorkerCount($readyDir));
    }

    private function readyQueryTaskEnqueueWorkerCount(string $readyDir): int
    {
        return count(glob($readyDir.'/*.ready') ?: []);
    }

    /**
     * @return array<string, mixed>
     */
    private function queryTaskEnqueueWorkerResult(Process $process): array
    {
        $process->wait();

        $output = trim($process->getOutput());
        $decoded = json_decode($output, true);

        if (! $process->isSuccessful() || ! is_array($decoded)) {
            return [
                'status' => 'error',
                'exit_code' => $process->getExitCode(),
                'stdout' => $output,
                'stderr' => trim($process->getErrorOutput()),
            ];
        }

        return $decoded;
    }
}

final class WorkflowQueryTaskBrokerImmediatePollRequestStore extends QueryTaskPollRequestStore
{
    public function waitForResult(
        string $namespace,
        string $taskQueue,
        ?string $buildId,
        string $leaseOwner,
        string $pollRequestId,
        ?int $timeoutMilliseconds = null,
    ): array {
        return [
            'resolved' => false,
            'task' => null,
            'poll_status' => null,
        ];
    }
}

final class WorkflowQueryTaskBrokerSupersessionRacePollRequestStore extends QueryTaskPollRequestStore
{
    public int $currentChecks = 0;

    /** @var callable(): void|null */
    public $afterFirstCurrentCheck = null;

    public function isCurrent(
        string $namespace,
        string $taskQueue,
        ?string $buildId,
        string $leaseOwner,
        string $pollRequestId,
    ): bool {
        $isCurrent = parent::isCurrent($namespace, $taskQueue, $buildId, $leaseOwner, $pollRequestId);
        $this->currentChecks++;

        if ($this->currentChecks === 1 && $isCurrent && is_callable($this->afterFirstCurrentCheck)) {
            ($this->afterFirstCurrentCheck)();
        }

        return $isCurrent;
    }
}

class WorkflowQueryTaskBrokerTestCacheStore implements CacheStore
{
    private ArrayStore $store;

    /** @var array<string, true> */
    private array $keys = [];

    public function __construct()
    {
        $this->store = new ArrayStore;
    }

    public function get($key)
    {
        return $this->store->get($key);
    }

    public function many(array $keys)
    {
        return $this->store->many($keys);
    }

    public function put($key, $value, $seconds)
    {
        $this->keys[(string) $key] = true;

        return $this->store->put($key, $value, $seconds);
    }

    public function putMany(array $values, $seconds)
    {
        foreach (array_keys($values) as $key) {
            $this->keys[(string) $key] = true;
        }

        return $this->store->putMany($values, $seconds);
    }

    public function increment($key, $value = 1)
    {
        return $this->store->increment($key, $value);
    }

    public function decrement($key, $value = 1)
    {
        return $this->store->decrement($key, $value);
    }

    public function forever($key, $value)
    {
        $this->keys[(string) $key] = true;

        return $this->store->forever($key, $value);
    }

    public function touch($key, $seconds)
    {
        return $this->store->touch($key, $seconds);
    }

    public function forget($key)
    {
        unset($this->keys[(string) $key]);

        return $this->store->forget($key);
    }

    public function flush()
    {
        $this->keys = [];

        return $this->store->flush();
    }

    public function getPrefix()
    {
        return $this->store->getPrefix();
    }

    /**
     * @return list<string>
     */
    public function keysStartingWith(string $prefix): array
    {
        $keys = array_values(array_filter(
            array_keys($this->keys),
            static fn (string $key): bool => str_starts_with($key, $prefix),
        ));

        sort($keys);

        return $keys;
    }
}

final class WorkflowQueryTaskBrokerTestLockTimeoutStore extends WorkflowQueryTaskBrokerTestCacheStore implements LockProvider
{
    public function lock($name, $seconds = 0, $owner = null)
    {
        return new WorkflowQueryTaskBrokerTestTimeoutLock((string) $owner);
    }

    public function restoreLock($name, $owner)
    {
        return new WorkflowQueryTaskBrokerTestTimeoutLock((string) $owner);
    }
}

final class WorkflowQueryTaskBrokerTestTimeoutLock implements CacheLock
{
    public function __construct(private readonly string $owner = '') {}

    public function get($callback = null)
    {
        return false;
    }

    public function block($seconds, $callback = null)
    {
        throw new LockTimeoutException;
    }

    public function release()
    {
        return false;
    }

    public function owner()
    {
        return $this->owner;
    }

    public function forceRelease() {}
}
