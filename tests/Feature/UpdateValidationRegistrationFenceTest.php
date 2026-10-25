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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;
use Workflow\V2\Models\WorkflowRun;

class UpdateValidationRegistrationFenceTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    private const WORKFLOW_TYPE = 'tests.fenced-validation';

    private const TASK_QUEUE = 'fenced-validation';

    #[DataProvider('registrationChanges')]
    public function test_waiting_poll_fences_a_replacement_but_keeps_current_registration(string $change): void
    {
        $this->freezeTime();
        $this->createNamespace('default');
        $this->registerValidator();
        $originalToken = $this->worker()->registration_token;
        $response = $this->postJson('/api/workflows', [
            'workflow_id' => 'fenced-validation', 'workflow_type' => self::WORKFLOW_TYPE,
            'task_queue' => self::TASK_QUEUE, 'input' => ['Ada'],
        ], $this->apiHeaders())->assertCreated();
        $run = WorkflowRun::query()->findOrFail($response->json('run_id'));
        $task = null;
        $queue = $change === 'queue' ? 'replacement-validation' : self::TASK_QUEUE;
        $step = function () use ($change, $run, $queue, &$task): void {
            if ($change !== 'unchanged') {
                $this->registerValidator($queue, $change === 'capabilities');
            }
            $codec = CodecRegistry::defaultCodec();
            $arguments = Serializer::serializeWithCodec($codec, [true]);
            $task = WorkflowUpdateValidationTask::query()->create([
                'idempotency_key' => hash('sha256', $run->id.'replacement-validation'),
                'namespace' => 'default', 'workflow_instance_id' => $run->workflow_instance_id,
                'workflow_run_id' => $run->id, 'workflow_type' => self::WORKFLOW_TYPE,
                'task_queue' => $queue, 'update_name' => 'approve', 'request_id' => 'replacement-validation',
                'input_hash' => hash('sha256', $codec."\0".$arguments),
                'payload_codec' => $codec, 'arguments' => $arguments, 'command_context' => [],
                'status' => WorkflowUpdateValidationTask::STATUS_PENDING,
            ]);
            app(LongPollSignalStore::class)->signalUpdateValidationTaskQueue('default', self::TASK_QUEUE);
        };
        $signals = app(LongPollSignalStore::class);
        $poller = new class($signals, app(LongPollWaitSlotStore::class), $step) extends LongPoller
        {
            public function __construct(LongPollSignalStore $signals, LongPollWaitSlotStore $slots, private ?\Closure $step)
            {
                parent::__construct($signals, $slots);
            }

            protected function pause(int $milliseconds): void
            {
                if ($this->step !== null) {
                    $step = $this->step;
                    $this->step = null;
                    $step();
                }
                parent::pause($milliseconds);
            }
        };
        $this->app->instance(WorkflowUpdateValidationTaskBroker::class, new WorkflowUpdateValidationTaskBroker(
            $poller, $signals, app(WorkflowQueryTaskBroker::class), app(ServerWorkflowControlPlane::class),
        ));
        $oldPoll = $this->postJson('/api/worker/update-validation-tasks/poll', [
            'worker_id' => 'validator', 'task_queue' => self::TASK_QUEUE, 'timeout_seconds' => 1,
        ], $this->workerHeaders())->assertOk();
        $this->assertInstanceOf(WorkflowUpdateValidationTask::class, $task);

        if ($change === 'unchanged') {
            $this->assertSame($originalToken, $this->worker()->registration_token);
            $oldPoll->assertJsonPath('poll_status', 'leased')->assertJsonPath('task.update_validation_task_id', $task->id);
            $this->assertSame(1, $task->fresh()->attempt_count);

            return;
        }

        $this->assertNotSame($originalToken, $this->worker()->registration_token);
        $oldPoll->assertJsonPath('task', null)->assertJsonPath('poll_status', 'stale_worker_registration');
        $this->assertSame(WorkflowUpdateValidationTask::STATUS_PENDING, $task->refresh()->status);
        $this->assertSame(0, $task->attempt_count);
        $this->assertNull($task->lease_owner);
        $this->assertNull($task->lease_expires_at);
        $this->postJson('/api/worker/update-validation-tasks/poll', [
            'worker_id' => 'validator', 'task_queue' => $queue, 'timeout_seconds' => 0,
        ], $this->workerHeaders())->assertOk()
            ->assertJsonPath('poll_status', 'leased')->assertJsonPath('task.update_validation_task_id', $task->id);
        $this->assertSame(1, $task->fresh()->attempt_count);
    }

    public static function registrationChanges(): array
    {
        return [
            'same routing and process identity' => ['same'],
            'changed queue' => ['queue'],
            'changed capabilities' => ['capabilities'],
            'unchanged registration' => ['unchanged'],
        ];
    }

    private function registerValidator(string $queue = self::TASK_QUEUE, bool $queryCapability = false): void
    {
        $capabilities = ['workflow_tasks', 'update_validation_tasks'];
        if ($queryCapability) {
            $capabilities[] = 'query_tasks';
        }
        $this->postJson('/api/worker/register', [
            'worker_id' => 'validator', 'task_queue' => $queue, 'runtime' => 'php',
            'supported_workflow_types' => [self::WORKFLOW_TYPE], 'capabilities' => $capabilities,
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
            'process_metrics' => ['host' => 'fixture-host', 'process_id' => 42, 'process_started_at' => '2026-10-24T00:00:00Z'],
            'workflow_command_contracts' => [self::WORKFLOW_TYPE => [
                'queries' => [], 'query_contracts' => [], 'signals' => [], 'signal_contracts' => [],
                'updates' => ['approve'], 'update_validators' => ['approve'],
                'update_contracts' => [[
                    'name' => 'approve',
                    'parameters' => [[
                        'name' => 'approved', 'position' => 0, 'required' => true, 'variadic' => false,
                        'default_available' => false, 'default' => null, 'type' => 'bool', 'allows_null' => false,
                    ]],
                ]],
            ]],
        ], $this->workerHeaders())->assertCreated();
    }

    private function worker(): WorkerRegistration
    {
        return WorkerRegistration::query()->where('namespace', 'default')->where('worker_id', 'validator')->sole();
    }
}
