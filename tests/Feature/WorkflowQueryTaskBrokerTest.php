<?php

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use App\Models\WorkflowNamespace;
use App\Support\ControlPlaneProtocol;
use App\Support\ExternalPayloadEnvelopeService;
use App\Support\LongPoller;
use App\Support\LongPollSignalStore;
use App\Support\LongPollWaitSlotStore;
use App\Support\QueryTaskPollRequestStore;
use App\Support\QueryTaskQueueFullException;
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
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

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

    public function test_query_rejects_terminal_run_before_enqueuing_worker_task(): void
    {
        Queue::fake();

        $run = $this->startRemoteWorkflow('wf-query-task-terminal');
        $run->forceFill([
            'status' => RunStatus::Completed,
            'closed_at' => now(),
        ])->save();
        $this->registerPythonWorker('python-query-terminal-worker', 'python-queries', ['python.queryable']);

        /** @var WorkflowQueryTaskBroker $broker */
        $broker = app(WorkflowQueryTaskBroker::class);
        $result = $broker->query('default', $run->refresh(), 'status', $this->queryArguments());

        $this->assertFalse($result['success']);
        $this->assertSame('wf-query-task-terminal', $result['workflow_id']);
        $this->assertSame($run->id, $result['run_id']);
        $this->assertSame('status', $result['query_name']);
        $this->assertSame('run_not_active', $result['reason']);
        $this->assertSame('completed', $result['run_status']);
        $this->assertTrue($result['is_terminal']);
        $this->assertSame(409, $result['status']);
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

    public function test_duplicate_query_poll_request_ids_replay_the_same_query_task(): void
    {
        Queue::fake();

        $run = $this->startRemoteWorkflow('wf-query-task-duplicate-poll');
        $this->registerPythonWorker('python-query-duplicate-worker', 'python-queries', ['python.queryable']);

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

        $duplicatePoll = $this->postJson('/api/worker/query-tasks/poll', [
            'worker_id' => 'python-query-duplicate-worker',
            'task_queue' => 'python-queries',
            'poll_request_id' => 'query-poll-request-1',
        ], $this->workerHeaders());

        $duplicatePoll->assertOk()
            ->assertJsonPath('task.query_task_id', $task['query_task_id'])
            ->assertJsonPath('task.query_task_attempt', 1)
            ->assertJsonPath('task.lease_owner', 'python-query-duplicate-worker');
    }

    public function test_superseded_query_poll_request_cannot_lease_query_task(): void
    {
        Queue::fake();

        $run = $this->startRemoteWorkflow('wf-query-task-superseded-poll');
        $this->registerPythonWorker('python-query-superseded-worker', 'python-queries', ['python.queryable']);

        /** @var QueryTaskPollRequestStore $pollRequests */
        $pollRequests = app(QueryTaskPollRequestStore::class);
        $poller = new class(
            app(LongPollSignalStore::class),
            app(LongPollWaitSlotStore::class),
        ) extends LongPoller
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

    public function test_query_workers_must_advertise_the_workflow_type(): void
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

            $this->postJson("/api/worker/query-tasks/{$task['query_task_id']}/complete", [
                'lease_owner' => 'wrong-query-worker',
                'query_task_attempt' => 1,
                'result_envelope' => $this->missingExternalStorageEnvelope($directory, 'avro', $missingPayload),
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

    public function test_query_task_poll_returns_empty_when_query_poll_wait_slots_are_exhausted(): void
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
            $poll = $broker->poll('default', $worker, 'query-poll-slot-exhausted-1');
        } finally {
            $heldSlot->release();
        }

        $this->assertNull($poll);
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
    ): WorkflowRun
    {
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

    /**
     * @return array{codec: string, external_storage: array{schema: string, uri: string, sha256: string, size_bytes: int, codec: string}}
     */
    private function missingExternalStorageEnvelope(string $root, string $codec, string $payload): array
    {
        $sha256 = hash('sha256', $payload);
        $directory = $root.'/missing';
        File::ensureDirectoryExists($directory);

        return [
            'codec' => $codec,
            'external_storage' => [
                'schema' => 'durable-workflow.v2.external-payload-reference.v1',
                'uri' => 'file://'.$directory.'/'.$sha256.'.bin',
                'sha256' => $sha256,
                'size_bytes' => strlen($payload),
                'codec' => $codec,
            ],
        ];
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
    ): void {
        $this->registerQueryWorker(
            $workerId,
            $taskQueue,
            $supportedWorkflowTypes,
            'python',
            $capabilities,
            $workflowDefinitionFingerprints,
        );
    }

    /**
     * @param  list<string>  $supportedWorkflowTypes
     * @param  list<string>  $capabilities
     * @param  array<string, string>  $workflowDefinitionFingerprints
     */
    private function registerQueryWorker(
        string $workerId,
        string $taskQueue,
        array $supportedWorkflowTypes,
        string $runtime,
        array $capabilities = ['query_tasks'],
        array $workflowDefinitionFingerprints = [],
    ): void {
        WorkerRegistration::query()->updateOrCreate(
            ['worker_id' => $workerId, 'namespace' => 'default'],
            [
                'task_queue' => $taskQueue,
                'runtime' => $runtime,
                'sdk_version' => 'durable-workflow-'.$runtime.'/0.2.0',
                'supported_workflow_types' => $supportedWorkflowTypes,
                'workflow_definition_fingerprints' => $workflowDefinitionFingerprints,
                'supported_activity_types' => [],
                'capabilities' => $capabilities,
                'last_heartbeat_at' => now(),
                'status' => 'active',
            ],
        );
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
