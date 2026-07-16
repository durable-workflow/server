<?php

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;

class RepeatedSignalDispatchRecoveryTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['workflows.v2.task_dispatch_mode' => 'poll']);

        $this->createNamespace('default');
        $this->registerPythonCounterWorker();
    }

    public function test_repeated_avro_signal_recovers_the_committed_command_when_dispatch_bookkeeping_fails(): void
    {
        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-python-rust-repeated-signal',
            'workflow_type' => 'conformance.counter',
            'task_queue' => 'python-counter-signals',
        ], $this->apiHeaders());

        $start->assertCreated();
        $runId = (string) $start->json('run_id');

        $this->completeOpenWorkflowTask($runId, 'python-counter-worker');

        $first = $this->postJson('/api/workflows/wf-python-rust-repeated-signal/signal/increment', [
            'input' => $this->avroEnvelope([4]),
        ], $this->apiHeaders());

        $first->assertAccepted()
            ->assertJsonPath('signal_name', 'increment')
            ->assertJsonPath('command_status', 'accepted')
            ->assertJsonPath('outcome', 'signal_received');

        $this->completeOpenWorkflowTask($runId, 'python-counter-worker');

        $failDispatchBookkeeping = true;
        WorkflowTask::updating(function (WorkflowTask $task) use (&$failDispatchBookkeeping): void {
            if (! $failDispatchBookkeeping || ! $task->isDirty('last_dispatch_attempt_at')) {
                return;
            }

            $failDispatchBookkeeping = false;

            throw new RuntimeException('simulated post-commit dispatch bookkeeping failure');
        });

        Log::spy();

        $second = $this->postJson('/api/workflows/wf-python-rust-repeated-signal/signal/increment', [
            'input' => $this->avroEnvelope([6]),
        ], $this->apiHeaders());

        $second->assertAccepted()
            ->assertJsonPath('workflow_id', 'wf-python-rust-repeated-signal')
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('signal_name', 'increment')
            ->assertJsonPath('command_status', 'accepted')
            ->assertJsonPath('outcome', 'signal_received')
            ->assertJsonPath('reason', null)
            ->assertJsonPath('control_plane.operation', 'signal');

        $signals = WorkflowSignal::query()
            ->where('workflow_run_id', $runId)
            ->orderBy('command_sequence')
            ->get();

        $this->assertCount(2, $signals);
        $this->assertSame(['increment', 'increment'], $signals->pluck('signal_name')->all());
        $this->assertSame(
            $signals[0]->command_sequence + 1,
            $signals[1]->command_sequence,
        );
        $this->assertSame(
            [[4], [6]],
            $signals->map(static fn (WorkflowSignal $signal): mixed => Serializer::unserializeWithCodec(
                (string) $signal->payload_codec,
                (string) $signal->arguments,
            ))->all(),
        );
        $this->assertSame(
            2,
            WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $runId)
                ->where('event_type', HistoryEventType::SignalReceived->value)
                ->count(),
        );
        $this->assertSame(
            2,
            WorkflowCommand::query()
                ->where('workflow_run_id', $runId)
                ->where('command_type', 'signal')
                ->count(),
        );
        $this->assertSame($signals[1]->workflow_command_id, $second->json('command_id'));

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context) use ($runId, $signals): bool {
                $routes = $context['worker_routes'] ?? [];
                $leases = $context['lease_state'] ?? [];

                return $message === 'Recovered a committed signal after a delivery-hint exception.'
                    && ($context['exception'] ?? null) instanceof RuntimeException
                    && ($context['exception_chain'][0]['message'] ?? null)
                        === 'simulated post-commit dispatch bookkeeping failure'
                    && ($context['command']['id'] ?? null) === $signals[1]->workflow_command_id
                    && is_string($context['command']['operation_id'] ?? null)
                    && ($context['command']['operation_id'] ?? '') !== ''
                    && ($context['workflow']['run_id'] ?? null) === $runId
                    && ($context['workflow']['task_queue'] ?? null) === 'python-counter-signals'
                    && ($routes[0]['worker_id'] ?? null) === 'python-counter-worker'
                    && ($routes[0]['runtime'] ?? null) === 'python'
                    && collect($leases)->contains(
                        static fn (array $lease): bool => ($lease['task_id'] ?? null) !== null
                            && ($lease['status'] ?? null) === TaskStatus::Ready->value,
                    )
                    && ($context['recovery'] ?? null) === 'committed_signal';
            })
            ->once();
    }

    public function test_unhandled_signal_exception_returns_a_typed_error_and_logs_route_and_lease_context(): void
    {
        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-signal-internal-error',
            'workflow_type' => 'conformance.counter',
            'task_queue' => 'python-counter-signals',
        ], $this->apiHeaders());

        $start->assertCreated();
        $runId = (string) $start->json('run_id');

        $this->mock(WorkflowControlPlane::class, function (MockInterface $mock): void {
            $mock->shouldReceive('signal')
                ->once()
                ->andThrow(new RuntimeException('exact server-side signal exception'));
        });

        Log::spy();

        $response = $this->postJson('/api/workflows/wf-signal-internal-error/signal/increment', [
            'input' => $this->avroEnvelope([2]),
            'request_id' => 'rust-signal-internal-error',
        ], $this->apiHeaders());

        $response->assertStatus(500)
            ->assertJsonPath('reason', 'control_plane_internal_error')
            ->assertJsonPath('command_status', 'indeterminate')
            ->assertJsonPath('outcome', 'operation_failed')
            ->assertJsonPath('rejection_category', 'internal')
            ->assertJsonPath('retryable', false)
            ->assertJsonPath('workflow_id', 'wf-signal-internal-error')
            ->assertJsonPath('signal_name', 'increment')
            ->assertJsonPath('control_plane.operation', 'signal')
            ->assertJsonPath('control_plane.reason', 'control_plane_internal_error')
            ->assertJsonPath('error_id', static fn (mixed $value): bool => is_string($value) && $value !== '')
            ->assertJsonMissingPath('exception');

        Log::shouldHaveReceived('error')
            ->withArgs(function (string $message, array $context) use ($runId): bool {
                $routes = $context['worker_routes'] ?? [];
                $leases = $context['lease_state'] ?? [];

                return $message === 'Unhandled control-plane operation exception.'
                    && ($context['exception'] ?? null) instanceof RuntimeException
                    && ($context['exception_chain'][0]['class'] ?? null) === RuntimeException::class
                    && ($context['exception_chain'][0]['message'] ?? null) === 'exact server-side signal exception'
                    && ($context['operation']['name'] ?? null) === 'signal'
                    && ($context['operation']['target_name'] ?? null) === 'increment'
                    && ($context['operation']['request_id'] ?? null) === 'rust-signal-internal-error'
                    && ($context['workflow']['run_id'] ?? null) === $runId
                    && ($routes[0]['worker_id'] ?? null) === 'python-counter-worker'
                    && collect($leases)->contains(
                        static fn (array $lease): bool => ($lease['task_id'] ?? null) !== null,
                    );
            })
            ->once();
    }

    private function registerPythonCounterWorker(): void
    {
        WorkerRegistration::query()->create([
            'worker_id' => 'python-counter-worker',
            'namespace' => 'default',
            'task_queue' => 'python-counter-signals',
            'runtime' => 'python',
            'sdk_version' => 'durable-workflow-python/test',
            'supported_workflow_types' => ['conformance.counter'],
            'workflow_definition_fingerprints' => [],
            'workflow_command_contracts' => [
                'conformance.counter' => $this->counterCommandContract(),
            ],
            'supported_activity_types' => [],
            'capabilities' => ['query_tasks'],
            'max_concurrent_workflow_tasks' => 2,
            'max_concurrent_activity_tasks' => 1,
            'last_heartbeat_at' => now(),
            'status' => 'active',
        ]);
    }

    private function completeOpenWorkflowTask(string $runId, string $workerId): void
    {
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->orderByDesc('created_at')
            ->firstOrFail();

        $task->forceFill([
            'status' => TaskStatus::Completed->value,
            'lease_owner' => $workerId,
            'leased_at' => now(),
            'lease_expires_at' => now()->addMinute(),
            'attempt_count' => max(1, (int) $task->attempt_count),
        ])->save();

        WorkflowRun::query()->whereKey($runId)->update([
            'status' => RunStatus::Waiting->value,
        ]);
    }

    /**
     * @return array{codec: string, blob: string}
     */
    private function avroEnvelope(mixed $value): array
    {
        return [
            'codec' => 'avro',
            'blob' => Serializer::serializeWithCodec('avro', $value),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function counterCommandContract(): array
    {
        return [
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
                        [
                            'name' => 'amount',
                            'position' => 0,
                            'required' => true,
                            'variadic' => false,
                            'default_available' => false,
                            'default' => null,
                            'type' => 'int',
                            'allows_null' => false,
                        ],
                    ],
                ],
            ],
            'updates' => [],
            'update_contracts' => [],
        ];
    }
}
