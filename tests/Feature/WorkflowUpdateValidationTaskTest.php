<?php

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use App\Models\WorkflowUpdateValidationTask;
use App\Support\LongPoller;
use App\Support\LongPollSignalStore;
use App\Support\LongPollWaitSlotStore;
use App\Support\ServerWorkflowControlPlane;
use App\Support\WorkflowQueryTaskBroker;
use App\Support\WorkflowUpdateValidationTaskBroker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowUpdate;

class WorkflowUpdateValidationTaskTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    private const WORKFLOW_TYPE = 'python.validated-update';

    private const TASK_QUEUE = 'validated-updates';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'server.polling.timeout' => 0,
            'server.update_validation.timeout' => 0,
            'server.update_validation.lease_timeout' => 5,
        ]);
        $this->createNamespace('default');
    }

    public function test_validator_approval_precedes_accepted_state_and_is_idempotent(): void
    {
        $this->registerValidatorWorker('validator-worker');
        $run = $this->startRemoteWorkflow('wf-validator-approved');
        $workflowTaskCount = WorkflowTask::query()->where('workflow_run_id', $run->id)->count();
        $leasedTask = null;

        $broker = $this->installValidationWorkerStep(function (WorkflowUpdateValidationTaskBroker $broker) use (
            $run,
            $workflowTaskCount,
            &$leasedTask,
        ): void {
            $worker = $this->worker('validator-worker');
            $leasedTask = $broker->poll('default', $worker, 0);

            $this->assertIsArray($leasedTask);
            $this->assertSame('update_validation', $leasedTask['task_kind']);
            $this->assertSame($run->id, $leasedTask['run_id']);
            $this->assertSame('approve', $leasedTask['update_name']);
            $this->assertArrayHasKey('history_export', $leasedTask);
            $this->assertSame(0, WorkflowUpdate::query()->where('workflow_run_id', $run->id)->count());
            $this->assertSame(0, $this->historyCount($run, HistoryEventType::UpdateAccepted));
            $this->assertSame(
                $workflowTaskCount,
                WorkflowTask::query()->where('workflow_run_id', $run->id)->count(),
            );

            $approved = $broker->approve(
                'default',
                (string) $leasedTask['update_validation_task_id'],
                (string) $leasedTask['lease_owner'],
                (int) $leasedTask['update_validation_attempt'],
            );
            $this->assertSame('approved', $approved['outcome']);
        });

        $response = $this->postJson('/api/workflows/wf-validator-approved/update/approve', [
            'input' => [true],
            'request_id' => 'approved-request',
            'wait_for' => 'accepted',
        ], $this->apiHeaders());

        $response->assertAccepted()
            ->assertJsonPath('update_status', 'accepted')
            ->assertJsonPath('update_id', $leasedTask['update_validation_task_id']);
        $this->assertSame(1, $this->historyCount($run, HistoryEventType::UpdateAccepted));

        $duplicate = $this->postJson('/api/workflows/wf-validator-approved/update/approve', [
            'input' => [true],
            'request_id' => 'approved-request',
            'wait_for' => 'accepted',
        ], $this->apiHeaders());
        $duplicate->assertAccepted()
            ->assertJsonPath('update_id', $response->json('update_id'));
        $this->assertSame(1, $this->historyCount($run, HistoryEventType::UpdateAccepted));
        $this->assertSame(1, WorkflowUpdateValidationTask::query()->firstOrFail()->attempt_count);

        $completion = $broker->approve(
            'default',
            (string) $leasedTask['update_validation_task_id'],
            (string) $leasedTask['lease_owner'],
            (int) $leasedTask['update_validation_attempt'],
        );
        $this->assertSame('duplicate_update_validation_completion', $completion['reason']);
    }

    public function test_validator_rejection_is_typed_and_never_accepts_or_dispatches_handler(): void
    {
        $this->registerValidatorWorker('rejecting-validator-worker');
        $run = $this->startRemoteWorkflow('wf-validator-rejected');
        $workflowTaskCount = WorkflowTask::query()->where('workflow_run_id', $run->id)->count();

        $this->installValidationWorkerStep(function (WorkflowUpdateValidationTaskBroker $broker): void {
            $task = $broker->poll('default', $this->worker('rejecting-validator-worker'), 0);
            $this->assertIsArray($task);
            $broker->reject(
                'default',
                (string) $task['update_validation_task_id'],
                (string) $task['lease_owner'],
                (int) $task['update_validation_attempt'],
                [
                    'reason' => 'update_validator_rejected',
                    'message' => 'approval is required',
                    'type' => 'ValueError',
                    'validation_errors' => ['approved' => ['must be true']],
                ],
            );
        });

        $response = $this->postJson('/api/workflows/wf-validator-rejected/update/approve', [
            'input' => [false],
            'request_id' => 'rejected-request',
        ], $this->apiHeaders());

        $response->assertStatus(422)
            ->assertJsonPath('reason', 'update_validator_rejected')
            ->assertJsonPath('update_status', 'rejected')
            ->assertJsonPath('validation_errors.approved.0', 'must be true');
        $this->assertSame(0, $this->historyCount($run, HistoryEventType::UpdateAccepted));
        $this->assertSame(1, $this->historyCount($run, HistoryEventType::UpdateRejected));
        $this->assertSame(
            $workflowTaskCount,
            WorkflowTask::query()->where('workflow_run_id', $run->id)->count(),
        );
        $this->assertSame('rejected', WorkflowUpdate::query()->firstOrFail()->status->value);
    }

    public function test_lost_worker_is_replaced_and_stale_completion_is_fenced(): void
    {
        $this->registerValidatorWorker('validator-worker-old');
        $this->registerValidatorWorker('validator-worker-new');
        $run = $this->startRemoteWorkflow('wf-validator-replaced');
        $deliveries = [];

        $broker = $this->installValidationWorkerStep(function (WorkflowUpdateValidationTaskBroker $broker) use (&$deliveries): void {
            $old = $this->worker('validator-worker-old');
            $deliveries['old'] = $broker->poll('default', $old, 0);
            $this->assertIsArray($deliveries['old']);

            $old->forceFill(['last_heartbeat_at' => now()->subMinute()])->save();
            WorkflowUpdateValidationTask::query()
                ->findOrFail((string) $deliveries['old']['update_validation_task_id'])
                ->forceFill(['lease_expires_at' => now()->subSecond()])
                ->save();

            $deliveries['new'] = $broker->poll('default', $this->worker('validator-worker-new'), 0);
            $this->assertIsArray($deliveries['new']);
            $this->assertSame(2, $deliveries['new']['update_validation_attempt']);
            $broker->approve(
                'default',
                (string) $deliveries['new']['update_validation_task_id'],
                (string) $deliveries['new']['lease_owner'],
                (int) $deliveries['new']['update_validation_attempt'],
            );
        });

        $response = $this->postJson('/api/workflows/wf-validator-replaced/update/approve', [
            'input' => [true],
            'request_id' => 'replacement-request',
        ], $this->apiHeaders());
        $response->assertAccepted();
        $this->assertSame(1, $this->historyCount($run, HistoryEventType::UpdateAccepted));

        $stale = $broker->approve(
            'default',
            (string) $deliveries['old']['update_validation_task_id'],
            (string) $deliveries['old']['lease_owner'],
            (int) $deliveries['old']['update_validation_attempt'],
        );
        $this->assertSame('stale_update_validation_completion', $stale['reason']);
    }

    public function test_unsupported_capability_and_missing_contract_fail_closed(): void
    {
        $this->registerValidatorWorker('unsupported-validator-worker', capabilities: ['workflow_tasks']);
        $unsupportedRun = $this->startRemoteWorkflow('wf-validator-unsupported');

        $unsupported = $this->postJson('/api/workflows/wf-validator-unsupported/update/approve', [
            'input' => [true],
            'request_id' => 'unsupported-request',
        ], $this->apiHeaders());
        $unsupported->assertStatus(409)
            ->assertJsonPath('reason', 'update_validation_capability_unsupported')
            ->assertJsonPath('retryable', false);
        $this->assertSame(0, $this->historyCount($unsupportedRun, HistoryEventType::UpdateAccepted));

        $this->registerValidatorWorker('missing-contract-worker', includeValidatorField: false);
        $missingRun = $this->startRemoteWorkflow('wf-validator-contract-missing');
        $missing = $this->postJson('/api/workflows/wf-validator-contract-missing/update/approve', [
            'input' => [true],
            'request_id' => 'missing-contract-request',
        ], $this->apiHeaders());
        $missing->assertStatus(409)
            ->assertJsonPath('reason', 'update_validator_contract_missing');
        $this->assertSame(0, $this->historyCount($missingRun, HistoryEventType::UpdateAccepted));
    }

    public function test_worker_loss_and_unclaimed_timeout_are_typed_without_acceptance(): void
    {
        $this->registerValidatorWorker('lost-validator-worker');
        $lostRun = $this->startRemoteWorkflow('wf-validator-worker-lost');
        $this->installValidationWorkerStep(function (WorkflowUpdateValidationTaskBroker $broker): void {
            $worker = $this->worker('lost-validator-worker');
            $task = $broker->poll('default', $worker, 0);
            $this->assertIsArray($task);
            $worker->forceFill(['last_heartbeat_at' => now()->subMinute()])->save();
        });

        $lost = $this->postJson('/api/workflows/wf-validator-worker-lost/update/approve', [
            'input' => [true],
            'request_id' => 'lost-worker-request',
        ], $this->apiHeaders());
        $lost->assertStatus(504)
            ->assertJsonPath('reason', 'update_validator_worker_lost');
        $this->assertSame(0, $this->historyCount($lostRun, HistoryEventType::UpdateAccepted));

        $this->registerValidatorWorker('unclaimed-validator-worker');
        $unclaimedRun = $this->startRemoteWorkflow('wf-validator-unclaimed');
        $this->app->forgetInstance(WorkflowUpdateValidationTaskBroker::class);
        $unclaimed = $this->postJson('/api/workflows/wf-validator-unclaimed/update/approve', [
            'input' => [true],
            'request_id' => 'unclaimed-request',
        ], $this->apiHeaders());
        $unclaimed->assertStatus(504)
            ->assertJsonPath('reason', 'update_validation_task_not_claimed');
        $this->assertSame(0, $this->historyCount($unclaimedRun, HistoryEventType::UpdateAccepted));
    }

    public function test_unavailable_worker_and_missing_request_id_are_typed(): void
    {
        $this->registerValidatorWorker('unavailable-validator-worker');
        $unavailableRun = $this->startRemoteWorkflow('wf-validator-unavailable');
        $this->worker('unavailable-validator-worker')
            ->forceFill(['last_heartbeat_at' => now()->subMinute()])
            ->save();

        $unavailable = $this->postJson('/api/workflows/wf-validator-unavailable/update/approve', [
            'input' => [true],
            'request_id' => 'unavailable-request',
        ], $this->apiHeaders());
        $unavailable->assertStatus(409)
            ->assertJsonPath('reason', 'update_validator_worker_unavailable')
            ->assertJsonPath('retryable', true);
        $this->assertSame(0, $this->historyCount($unavailableRun, HistoryEventType::UpdateAccepted));

        $this->registerValidatorWorker('request-id-validator-worker');
        $requestIdRun = $this->startRemoteWorkflow('wf-validator-request-id');
        $missingRequestId = $this->postJson('/api/workflows/wf-validator-request-id/update/approve', [
            'input' => [true],
        ], $this->apiHeaders());
        $missingRequestId->assertStatus(422)
            ->assertJsonPath('reason', 'update_validation_request_id_required');
        $this->assertSame(0, $this->historyCount($requestIdRun, HistoryEventType::UpdateAccepted));
    }

    public function test_multiplexed_poll_selects_a_workflow_task_with_a_discriminator(): void
    {
        $this->registerValidatorWorker('multiplex-workflow-worker');
        $this->startRemoteWorkflow('wf-multiplex-workflow');

        $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'multiplex-workflow-worker',
            'task_queue' => self::TASK_QUEUE,
            'task_kinds' => ['workflow', 'update_validation'],
            'timeout_seconds' => 0,
        ], $this->workerHeaders())
            ->assertOk()
            ->assertJsonPath('poll_status', 'leased')
            ->assertJsonPath('task.task_kind', 'workflow')
            ->assertJsonPath('task.workflow_id', 'wf-multiplex-workflow');
    }

    public function test_multiplexed_poll_initially_selects_validation_and_leases_only_one_task(): void
    {
        $this->registerValidatorWorker('multiplex-validation-worker');
        $run = $this->startRemoteWorkflow('wf-multiplex-validation');

        $this->installValidationWorkerStep(function (WorkflowUpdateValidationTaskBroker $broker) use ($run): void {
            $poll = $this->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'multiplex-validation-worker',
                'task_queue' => self::TASK_QUEUE,
                'task_kinds' => ['workflow', 'update_validation'],
                'poll_request_id' => 'multiplex-validation-poll',
                'timeout_seconds' => 0,
            ], $this->workerHeaders());

            $poll->assertOk()
                ->assertJsonPath('poll_status', 'leased')
                ->assertJsonPath('task.task_kind', 'update_validation')
                ->assertJsonPath('task.run_id', $run->id);
            $this->assertFalse(
                WorkflowTask::query()
                    ->where('workflow_run_id', $run->id)
                    ->where('status', 'leased')
                    ->exists(),
            );

            $broker->approve(
                'default',
                (string) $poll->json('task.update_validation_task_id'),
                (string) $poll->json('task.lease_owner'),
                (int) $poll->json('task.update_validation_attempt'),
            );
        });

        $this->postJson('/api/workflows/wf-multiplex-validation/update/approve', [
            'input' => [true],
            'request_id' => 'multiplex-validation-request',
        ], $this->apiHeaders())
            ->assertAccepted()
            ->assertJsonPath('update_status', 'accepted');
        $this->assertSame(1, $this->historyCount($run, HistoryEventType::UpdateAccepted));
    }

    public function test_multiplexed_poll_durably_alternates_kinds_while_both_remain_ready(): void
    {
        $this->registerValidatorWorker('multiplex-fair-worker');

        for ($index = 0; $index < 5; $index++) {
            $run = $this->startRemoteWorkflow('wf-multiplex-fair-'.$index);
            $this->createPendingValidationTask($run, 'multiplex-fair-request-'.$index);
        }

        $observed = [];
        $workerId = 'multiplex-fair-worker';

        for ($index = 0; $index < 8; $index++) {
            $this->assertTrue(WorkflowUpdateValidationTask::query()
                ->where('status', WorkflowUpdateValidationTask::STATUS_PENDING)
                ->exists());
            $this->assertTrue(WorkflowTask::query()
                ->where('task_type', TaskType::Workflow->value)
                ->where('status', TaskStatus::Ready->value)
                ->exists());

            if ($index === 4) {
                $workerId = 'multiplex-fair-replacement-worker';
                $this->registerValidatorWorker($workerId);
            }

            $poll = $this->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => $workerId,
                'task_queue' => self::TASK_QUEUE,
                'task_kinds' => ['workflow', 'update_validation'],
                'poll_request_id' => 'multiplex-fair-poll-'.$index,
                'timeout_seconds' => 0,
            ], $this->workerHeaders());

            $poll->assertOk()->assertJsonPath('poll_status', 'leased');
            $taskKind = $poll->json('task.task_kind');
            $this->assertIsString($taskKind);
            $observed[] = $taskKind;

            if ($taskKind === 'update_validation') {
                app(WorkflowUpdateValidationTaskBroker::class)->approve(
                    'default',
                    (string) $poll->json('task.update_validation_task_id'),
                    (string) $poll->json('task.lease_owner'),
                    (int) $poll->json('task.update_validation_attempt'),
                );

                continue;
            }

            WorkflowTask::query()
                ->findOrFail((string) $poll->json('task.task_id'))
                ->forceFill(['status' => TaskStatus::Completed->value])
                ->save();
        }

        $this->assertSame([
            'update_validation',
            'workflow',
            'update_validation',
            'workflow',
            'update_validation',
            'workflow',
            'update_validation',
            'workflow',
        ], $observed);
    }

    public function test_poll_request_id_is_fenced_to_the_normalized_task_kind_set(): void
    {
        $this->registerValidatorWorker('multiplex-idempotency-worker');
        $run = $this->startRemoteWorkflow('wf-multiplex-idempotency');
        $this->createPendingValidationTask($run, 'multiplex-idempotency-request');

        $this->assertTrue(WorkflowUpdateValidationTask::query()
            ->where('status', WorkflowUpdateValidationTask::STATUS_PENDING)
            ->exists());
        $this->assertTrue(WorkflowTask::query()
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->exists());

        $request = [
            'worker_id' => 'multiplex-idempotency-worker',
            'task_queue' => self::TASK_QUEUE,
            'task_kinds' => ['workflow', 'update_validation'],
            'poll_request_id' => 'multiplex-idempotency-poll',
            'timeout_seconds' => 0,
        ];
        $first = $this->postJson(
            '/api/worker/workflow-tasks/poll',
            $request,
            $this->workerHeaders(),
        );

        $first->assertOk()->assertJsonPath('poll_status', 'leased');
        $taskKind = (string) $first->json('task.task_kind');
        $identityField = $taskKind === 'update_validation'
            ? 'update_validation_task_id'
            : 'task_id';
        $taskIdentity = (string) $first->json('task.'.$identityField);

        $this->postJson(
            '/api/worker/workflow-tasks/poll',
            $request,
            $this->workerHeaders(),
        )
            ->assertOk()
            ->assertJsonPath('task.task_kind', $taskKind)
            ->assertJsonPath('task.'.$identityField, $taskIdentity);

        $this->postJson(
            '/api/worker/workflow-tasks/poll',
            [...$request, 'task_kinds' => ['update_validation', 'workflow']],
            $this->workerHeaders(),
        )
            ->assertOk()
            ->assertJsonPath('task.task_kind', $taskKind)
            ->assertJsonPath('task.'.$identityField, $taskIdentity);

        $differentTaskKinds = $taskKind === 'update_validation'
            ? ['workflow']
            : ['update_validation'];

        $this->postJson(
            '/api/worker/workflow-tasks/poll',
            [...$request, 'task_kinds' => $differentTaskKinds],
            $this->workerHeaders(),
        )
            ->assertStatus(409)
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'conflict')
            ->assertJsonPath('reason', 'poll_request_task_kinds_conflict')
            ->assertJsonPath('poll_request_id', 'multiplex-idempotency-poll')
            ->assertJsonPath('requested_task_kinds', $differentTaskKinds)
            ->assertJsonPath('bound_task_kinds', ['update_validation', 'workflow']);

        if ($taskKind === 'update_validation') {
            $this->assertTrue(WorkflowUpdateValidationTask::query()
                ->whereKey($taskIdentity)
                ->where('status', WorkflowUpdateValidationTask::STATUS_LEASED)
                ->where('lease_expires_at', '>', now())
                ->exists());
        } else {
            $this->assertTrue(WorkflowTask::query()
                ->whereKey($taskIdentity)
                ->where('status', TaskStatus::Leased->value)
                ->where('lease_expires_at', '>', now())
                ->exists());
        }
    }

    public function test_multiplexed_poll_returns_empty_without_leasing_work(): void
    {
        $this->registerValidatorWorker('multiplex-empty-worker');

        $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'multiplex-empty-worker',
            'task_queue' => self::TASK_QUEUE,
            'task_kinds' => ['workflow', 'update_validation'],
            'timeout_seconds' => 0,
        ], $this->workerHeaders())
            ->assertOk()
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'empty');
    }

    public function test_multiplexed_poll_rejects_invalid_kinds_and_unsupported_workers(): void
    {
        $this->registerValidatorWorker('multiplex-invalid-worker');

        $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'multiplex-invalid-worker',
            'task_queue' => self::TASK_QUEUE,
            'task_kinds' => ['workflow', 'activity'],
        ], $this->workerHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['task_kinds.1']);

        $this->registerValidatorWorker(
            'multiplex-unsupported-worker',
            capabilities: ['workflow_tasks'],
        );
        $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'multiplex-unsupported-worker',
            'task_queue' => self::TASK_QUEUE,
            'task_kinds' => ['workflow', 'update_validation'],
        ], $this->workerHeaders())
            ->assertStatus(409)
            ->assertJsonPath('poll_status', 'unsupported')
            ->assertJsonPath('reason', 'update_validation_capability_not_advertised');
    }

    public function test_capability_discovery_advertises_multiplexed_validation_polling(): void
    {
        $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath(
                'worker_protocol.server_capabilities.synchronous_update_validation.task_poll',
                [
                    'strategy' => 'multiplexed',
                    'endpoint' => '/worker/workflow-tasks/poll',
                    'request_field' => 'task_kinds',
                    'task_kinds' => ['workflow', 'update_validation'],
                    'default_task_kinds' => ['workflow'],
                    'response_discriminator' => 'task.task_kind',
                    'poll_request_id_binding' => 'normalized_task_kind_set',
                    'poll_request_id_conflict_reason' => 'poll_request_task_kinds_conflict',
                ],
            );
        $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath(
                'worker_protocol.server_capabilities.synchronous_update_validation.completion',
                [
                    'approve_endpoint' => '/worker/update-validation-tasks/{taskId}/approve',
                    'reject_endpoint' => '/worker/update-validation-tasks/{taskId}/reject',
                    'fence_fields' => ['lease_owner', 'update_validation_attempt'],
                    'typed_failure_reasons' => [
                        'update_validation_task_not_found',
                        'duplicate_update_validation_completion',
                        'update_validation_task_not_leased',
                        'update_validation_lease_owner_mismatch',
                        'stale_update_validation_completion',
                        'update_validation_lease_expired',
                        'update_validator_worker_lost',
                    ],
                ],
            );
    }

    /**
     * @param  callable(WorkflowUpdateValidationTaskBroker): void  $workerStep
     */
    private function installValidationWorkerStep(callable $workerStep): WorkflowUpdateValidationTaskBroker
    {
        $signals = app(LongPollSignalStore::class);
        $poller = new class($signals, app(LongPollWaitSlotStore::class)) extends LongPoller
        {
            private bool $ranWorkerStep = false;

            /** @var callable(): void|null */
            public $workerStep = null;

            public function __construct(
                LongPollSignalStore $signals,
                LongPollWaitSlotStore $waitSlots,
            ) {
                parent::__construct($signals, $waitSlots);
            }

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

                if ($ready($value) || $this->ranWorkerStep) {
                    return $value;
                }

                $this->ranWorkerStep = true;
                ($this->workerStep)();

                return $probe();
            }
        };
        $broker = null;
        $step = function () use (&$broker, $workerStep): void {
            $workerStep($broker);
        };
        $poller->workerStep = $step;
        $broker = new WorkflowUpdateValidationTaskBroker(
            $poller,
            $signals,
            app(WorkflowQueryTaskBroker::class),
            app(ServerWorkflowControlPlane::class),
        );
        $this->app->instance(WorkflowUpdateValidationTaskBroker::class, $broker);

        return $broker;
    }

    private function startRemoteWorkflow(string $workflowId): WorkflowRun
    {
        $response = $this->postJson('/api/workflows', [
            'workflow_id' => $workflowId,
            'workflow_type' => self::WORKFLOW_TYPE,
            'task_queue' => self::TASK_QUEUE,
            'input' => ['Ada'],
        ], $this->apiHeaders());
        $response->assertCreated();

        return WorkflowRun::query()->findOrFail((string) $response->json('run_id'));
    }

    private function createPendingValidationTask(WorkflowRun $run, string $requestId): void
    {
        $codec = CodecRegistry::defaultCodec();
        $arguments = Serializer::serializeWithCodec($codec, [true]);

        WorkflowUpdateValidationTask::query()->create([
            'idempotency_key' => hash('sha256', $run->id."\0".$requestId),
            'namespace' => 'default',
            'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_run_id' => $run->id,
            'workflow_type' => self::WORKFLOW_TYPE,
            'task_queue' => self::TASK_QUEUE,
            'compatibility' => $run->compatibility,
            'workflow_definition_fingerprint' => null,
            'update_name' => 'approve',
            'request_id' => $requestId,
            'input_hash' => hash('sha256', $codec."\0".$arguments),
            'payload_codec' => $codec,
            'arguments' => $arguments,
            'command_context' => [],
            'status' => WorkflowUpdateValidationTask::STATUS_PENDING,
        ]);
    }

    /**
     * @param  list<string>  $capabilities
     */
    private function registerValidatorWorker(
        string $workerId,
        array $capabilities = ['workflow_tasks', 'update_validation_tasks'],
        bool $includeValidatorField = true,
    ): void {
        $contract = [
            'queries' => [],
            'query_contracts' => [],
            'signals' => [],
            'signal_contracts' => [],
            'updates' => ['approve'],
            'update_contracts' => [[
                'name' => 'approve',
                'parameters' => [[
                    'name' => 'approved',
                    'position' => 0,
                    'required' => true,
                    'variadic' => false,
                    'default_available' => false,
                    'default' => null,
                    'type' => 'bool',
                    'allows_null' => false,
                ]],
            ]],
        ];

        if ($includeValidatorField) {
            $contract['update_validators'] = ['approve'];
        }

        WorkerRegistration::query()->updateOrCreate(
            ['worker_id' => $workerId, 'namespace' => 'default'],
            [
                'task_queue' => self::TASK_QUEUE,
                'runtime' => 'python',
                'sdk_version' => 'durable-workflow-python/0.5.0',
                'build_id' => null,
                'supported_workflow_types' => [self::WORKFLOW_TYPE],
                'workflow_definition_fingerprints' => [],
                'workflow_command_contracts' => [self::WORKFLOW_TYPE => $contract],
                'supported_activity_types' => [],
                'capabilities' => $capabilities,
                'last_heartbeat_at' => now(),
                'status' => 'active',
            ],
        );
    }

    private function worker(string $workerId): WorkerRegistration
    {
        return WorkerRegistration::query()
            ->where('namespace', 'default')
            ->where('worker_id', $workerId)
            ->firstOrFail();
    }

    private function historyCount(WorkflowRun $run, HistoryEventType $eventType): int
    {
        return WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', $eventType->value)
            ->count();
    }
}
