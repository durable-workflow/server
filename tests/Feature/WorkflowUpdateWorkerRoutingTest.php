<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowUpdate;

class WorkflowUpdateWorkerRoutingTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    private const TYPE = 'tests.update-routing';

    private const QUEUE = 'update-routing';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public static function incapableWorkers(): iterable
    {
        foreach (['default', 'named-updates'] as $namespace) {
            yield $namespace.' missing capability' => [$namespace, [], ['approve'], self::TYPE];
            yield $namespace.' empty declaration' => [$namespace, ['workflow_updates'], [], self::TYPE];
            yield $namespace.' other update' => [$namespace, ['workflow_updates'], ['other'], self::TYPE];
            yield $namespace.' other workflow' => [$namespace, ['workflow_updates'], ['approve'], 'tests.other-type'];
        }
    }

    #[DataProvider('incapableWorkers')]
    public function test_incapable_worker_cannot_consume_an_accepted_update(
        string $namespace,
        array $capabilities,
        array $updates,
        string $contractType,
    ): void {
        $this->createNamespace($namespace);
        $this->register('old-worker', $namespace, $capabilities, $updates, $contractType);
        $this->register('new-worker', $namespace, ['workflow_updates'], ['approve']);
        $runId = $this->startWaiting($namespace);
        $accepted = $this->postJson('/api/workflows/rolling-update/update/approve', [
            'input' => [true], 'request_id' => 'original-update', 'wait_for' => 'accepted',
        ], $this->apiHeaders($namespace))->assertAccepted();
        $updateId = $accepted->json('update_id');
        $task = WorkflowTask::query()->where('workflow_run_id', $runId)
            ->where('task_type', 'workflow')->where('status', 'ready')->sole();
        $before = $task->getRawOriginal();
        $historyCount = WorkflowHistoryEvent::query()->count();

        $this->poll('old-worker', $namespace)->assertOk()->assertJsonPath('task', null);
        $this->assertSame($before, $task->fresh()->getRawOriginal());
        $this->assertSame('accepted', WorkflowUpdate::query()->findOrFail($updateId)->status->value);
        $this->assertSame($historyCount, WorkflowHistoryEvent::query()->count());

        $delivery = $this->poll('new-worker', $namespace)->assertOk()
            ->assertJsonPath('task.task_id', $task->id)
            ->assertJsonPath('task.run_id', $runId)
            ->assertJsonPath('task.workflow_update_id', $updateId)
            ->assertJsonPath('task.resume_source_kind', 'workflow_update');
        $result = Serializer::serializeWithCodec('avro', ['approved' => true]);
        $this->postJson('/api/worker/workflow-tasks/'.$task->id.'/complete', [
            'lease_owner' => 'new-worker',
            'workflow_task_attempt' => $delivery->json('task.workflow_task_attempt'),
            'commands' => [[
                'type' => 'complete_update', 'update_id' => $updateId,
                'result' => ['codec' => 'avro', 'blob' => $result],
            ]],
        ], $this->workerHeaders($namespace))->assertOk();

        $update = WorkflowUpdate::query()->findOrFail($updateId);
        $this->assertSame('completed', $update->status->value);
        $this->assertSame($result, $update->result);
        $this->assertSame(1, $task->fresh()->attempt_count);
        $this->assertSame(0, $task->fresh()->repair_count);
    }

    public static function namespaces(): iterable
    {
        yield 'default' => ['default'];
        yield 'named' => ['named-updates'];
    }

    #[DataProvider('namespaces')]
    public function test_ordinary_workflow_task_does_not_require_update_support(string $namespace): void
    {
        $this->createNamespace($namespace);
        $this->register('old-worker', $namespace, [], []);
        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'ordinary', 'workflow_type' => self::TYPE, 'task_queue' => self::QUEUE,
        ], $this->apiHeaders($namespace))->assertCreated();

        $this->poll('old-worker', $namespace)->assertOk()
            ->assertJsonPath('task.run_id', $start->json('run_id'));
    }

    #[DataProvider('namespaces')]
    public function test_literal_zero_update_name_is_routable(string $namespace): void
    {
        $this->createNamespace($namespace);
        $this->register('new-worker', $namespace, ['workflow_updates'], ['0']);
        $runId = $this->startWaiting($namespace);
        $accepted = $this->postJson('/api/workflows/rolling-update/update/0', [
            'input' => [true], 'request_id' => 'zero-update', 'wait_for' => 'accepted',
        ], $this->apiHeaders($namespace))->assertAccepted();

        $this->poll('new-worker', $namespace)->assertOk()
            ->assertJsonPath('task.run_id', $runId)
            ->assertJsonPath('task.workflow_update_id', $accepted->json('update_id'));
    }

    public function test_update_link_cannot_borrow_authority_from_another_run(): void
    {
        $this->createNamespace('default');
        $this->register('new-worker', 'default', ['workflow_updates'], ['approve']);
        $firstRun = $this->startWaiting('default');
        $secondRun = $this->startWaiting('default', 'another-update');
        $first = $this->postJson('/api/workflows/rolling-update/update/approve', [
            'input' => [true], 'request_id' => 'first-update', 'wait_for' => 'accepted',
        ], $this->apiHeaders())->assertAccepted();
        $second = $this->postJson('/api/workflows/another-update/update/approve', [
            'input' => [true], 'request_id' => 'second-update', 'wait_for' => 'accepted',
        ], $this->apiHeaders())->assertAccepted();
        $invalid = WorkflowTask::query()->where('workflow_run_id', $firstRun)
            ->where('task_type', 'workflow')->where('status', 'ready')->sole();
        $invalid->update(['payload' => [
            ...$invalid->payload, 'workflow_update_id' => $second->json('update_id'),
        ]]);
        $before = $invalid->fresh()->getRawOriginal();

        $this->poll('new-worker', 'default')->assertOk()
            ->assertJsonPath('task.run_id', $secondRun)
            ->assertJsonPath('task.workflow_update_id', $second->json('update_id'));
        $this->assertSame($before, $invalid->fresh()->getRawOriginal());
        $this->assertSame('accepted', WorkflowUpdate::query()->findOrFail($first->json('update_id'))->status->value);
    }

    private function register(
        string $workerId,
        string $namespace,
        array $capabilities,
        array $updates,
        string $contractType = self::TYPE,
    ): void {
        $this->postJson('/api/worker/register', [
            'worker_id' => $workerId, 'task_queue' => self::QUEUE, 'runtime' => 'python',
            'supported_workflow_types' => array_values(array_unique([self::TYPE, $contractType])),
            'supported_activity_types' => [], 'capabilities' => $capabilities,
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
            'workflow_command_contracts' => [$contractType => [
                'queries' => [], 'query_contracts' => [], 'signals' => [], 'signal_contracts' => [],
                'updates' => $updates, 'update_validators' => [],
                'update_contracts' => array_map(static fn (string $name): array => [
                    'name' => $name,
                    'parameters' => [[
                        'name' => 'approved', 'position' => 0, 'required' => true, 'variadic' => false,
                        'default_available' => false, 'default' => null, 'type' => 'bool', 'allows_null' => false,
                    ]],
                ], $updates),
            ]],
        ], $this->workerHeaders($namespace))->assertCreated();
    }

    private function poll(string $workerId, string $namespace): TestResponse
    {
        return $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => $workerId, 'task_queue' => self::QUEUE, 'timeout_seconds' => 0,
        ], $this->workerHeaders($namespace));
    }

    private function startWaiting(string $namespace, string $workflowId = 'rolling-update'): string
    {
        $start = $this->postJson('/api/workflows', [
            'workflow_id' => $workflowId, 'workflow_type' => self::TYPE, 'task_queue' => self::QUEUE,
        ], $this->apiHeaders($namespace))->assertCreated();
        $task = $this->poll('new-worker', $namespace)->assertOk()->json('task');
        $this->assertIsArray($task);
        $this->postJson('/api/worker/workflow-tasks/'.$task['task_id'].'/complete', [
            'lease_owner' => 'new-worker', 'workflow_task_attempt' => $task['workflow_task_attempt'],
            'commands' => [['type' => 'start_timer', 'delay_seconds' => 3600]],
        ], $this->workerHeaders($namespace))->assertOk()->assertJsonPath('run_status', 'waiting');

        return $start->json('run_id');
    }
}
