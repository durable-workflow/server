<?php

namespace Tests\Unit;

use App\Support\ExternalPayloadEnvelopeService;
use App\Support\LongPoller;
use App\Support\LongPollSignalStore;
use App\Support\PollRequestLeaseBinding;
use App\Support\ServerPollingCache;
use App\Support\TaskQueueAdmission;
use App\Support\WorkerPollClaimGate;
use App\Support\WorkflowQueryTaskBroker;
use App\Support\WorkflowTaskKindSelector;
use App\Support\WorkflowTaskLeaseRecovery;
use App\Support\WorkflowTaskPoller;
use App\Support\WorkflowTaskPollRequestStore;
use App\Support\WorkflowUpdateValidationTaskBroker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Support\TaskFairnessState;

class WorkflowTaskPollerTest extends TestCase
{
    use RefreshDatabase;

    public function test_claim_ready_task_forwards_supported_workflow_types_when_bridge_supports_filtering(): void
    {
        $bridge = \Mockery::mock(WorkflowTaskBridge::class);
        $bridge->shouldReceive('poll')
            ->once()
            ->with(null, 'default', 1, null, 'default', ['tests.matched-workflow'])
            ->andReturn([]);

        $poller = new WorkflowTaskPoller(
            app(LongPoller::class),
            $bridge,
            app(LongPollSignalStore::class),
            app(WorkflowTaskLeaseRecovery::class),
            app(WorkflowTaskPollRequestStore::class),
            app(ServerPollingCache::class),
            app(TaskQueueAdmission::class),
            app(TaskFairnessState::class),
            app(ExternalPayloadEnvelopeService::class),
            app(WorkerPollClaimGate::class),
            app(WorkflowQueryTaskBroker::class),
            app(PollRequestLeaseBinding::class),
            app(WorkflowUpdateValidationTaskBroker::class),
            app(WorkflowTaskKindSelector::class),
        );

        $result = $this->invokeClaimReadyTask(
            $poller,
            namespace: 'default',
            taskQueue: 'default',
            leaseOwner: 'worker-1',
            buildId: null,
            limit: 1,
            historyPageSize: null,
            acceptHistoryEncoding: null,
            supportedWorkflowTypes: ['tests.matched-workflow'],
        );

        $this->assertNull($result);
    }

    /**
     * @param  list<string>  $supportedWorkflowTypes
     * @return array<string, mixed>|null
     */
    private function invokeClaimReadyTask(
        WorkflowTaskPoller $poller,
        string $namespace,
        string $taskQueue,
        string $leaseOwner,
        ?string $buildId,
        int $limit,
        ?int $historyPageSize,
        ?string $acceptHistoryEncoding,
        array $supportedWorkflowTypes,
    ): ?array {
        $reflection = new ReflectionMethod(WorkflowTaskPoller::class, 'claimReadyTask');
        $reflection->setAccessible(true);

        /** @var array<string, mixed>|null $result */
        $result = $reflection->invoke(
            $poller,
            $namespace,
            $taskQueue,
            $leaseOwner,
            $buildId,
            $limit,
            null,
            $historyPageSize,
            $acceptHistoryEncoding,
            $supportedWorkflowTypes,
        );

        return $result;
    }
}
