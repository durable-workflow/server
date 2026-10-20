<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\WorkerCompatibilityFleet;

class WorkflowDefinitionEvolutionTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    private const TYPE = 'remote.definition-evolution';

    private const QUEUE = 'definition-evolution';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        WorkerCompatibilityFleet::clear();
        $this->createNamespace('default');
        config(['server.polling.timeout' => 0, 'workflows.v2.compatibility.pin_to_recorded_fingerprint' => true]);
    }

    public function test_default_replacement_can_claim_when_the_recorded_definition_is_gone(): void
    {
        [$run, $task, $started] = $this->original();
        $original = [$started->getRawOriginal('payload'), $run->run_deadline_at, $run->execution_deadline_at];
        $this->deleteJson('/api/workers/original-worker', [], $this->apiHeaders())->assertOk();
        $this->worker('replacement-worker', 'definition-v2');

        $this->poll('replacement-worker')->assertOk()
            ->assertJsonPath('task.run_id', $run->id)
            ->assertJsonPath('task.task_id', $task->id);

        self::assertSame($original, [
            $started->fresh()->getRawOriginal('payload'), $run->fresh()->run_deadline_at, $run->fresh()->execution_deadline_at,
        ]);
        self::assertSame(1, $task->fresh()->attempt_count);
        self::assertSame(0, $task->fresh()->repair_count);
    }

    public function test_explicit_opt_out_does_not_wait_for_a_matching_peer(): void
    {
        [$run, $task] = $this->original();
        $this->worker('replacement-worker', 'definition-v2');
        config(['workflows.v2.compatibility.pin_to_recorded_fingerprint' => false]);

        $this->poll('replacement-worker')->assertOk()
            ->assertJsonPath('task.run_id', $run->id)
            ->assertJsonPath('task.task_id', $task->id);
    }

    public function test_default_keeps_the_matching_registered_peer_preference(): void
    {
        [, $task] = $this->original();
        $this->worker('replacement-worker', 'definition-v2');

        $this->poll('replacement-worker')->assertOk()->assertJsonPath('task', null);
        self::assertSame(TaskStatus::Ready, $task->fresh()->status);
        self::assertNull($task->fresh()->lease_owner);
        self::assertSame(0, $task->fresh()->attempt_count);

        $this->poll('original-worker')->assertOk()->assertJsonPath('task.task_id', $task->id);
    }

    private function original(): array
    {
        $this->worker('original-worker', 'definition-v1');
        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'definition-original', 'workflow_type' => self::TYPE, 'task_queue' => self::QUEUE,
            'run_timeout_seconds' => 60, 'execution_timeout_seconds' => 120,
        ], $this->apiHeaders())->assertCreated();
        $run = WorkflowRun::query()->findOrFail($start->json('run_id'));
        $task = WorkflowTask::query()->where('workflow_run_id', $run->id)->firstOrFail();
        $started = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)
            ->where('event_type', 'WorkflowStarted')->firstOrFail();
        self::assertSame('worker', $started->payload['workflow_definition_fingerprint_source']);
        self::assertSame('definition-v1', $started->payload['workflow_definition_fingerprint']);

        return [$run, $task, $started];
    }

    private function worker(string $id, string $fingerprint): void
    {
        $this->postJson('/api/worker/register', [
            'worker_id' => $id, 'task_queue' => self::QUEUE, 'runtime' => 'python', 'sdk_version' => '2.6.1',
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
            'supported_workflow_types' => [self::TYPE],
            'workflow_definition_fingerprints' => [self::TYPE => $fingerprint],
            'workflow_command_contracts' => [self::TYPE => ['signals' => [], 'queries' => [], 'updates' => [], 'update_validators' => []]],
        ], $this->workerHeaders())->assertCreated();
    }

    private function poll(string $id): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => $id, 'task_queue' => self::QUEUE, 'timeout_seconds' => 0,
        ], $this->workerHeaders());
    }
}
