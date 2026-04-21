<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\InteractiveCommandWorkflow;
use Tests\TestCase;

class StartBoundaryOrderingContractTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createNamespace('default');
        $this->configureWorkflowTypes([
            'tests.interactive-command-workflow' => InteractiveCommandWorkflow::class,
        ]);
    }

    public function test_signal_recorded_before_first_poll_replays_after_workflow_started(): void
    {
        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-start-boundary-signal',
                'workflow_type' => 'tests.interactive-command-workflow',
                'task_queue' => 'start-boundary',
            ]);

        $start->assertCreated()
            ->assertJsonPath('outcome', 'started_new');

        $runId = (string) $start->json('run_id');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-start-boundary-signal/signal/advance', [
                'input' => ['Ada'],
                'request_id' => 'start-boundary-signal',
            ])
            ->assertAccepted()
            ->assertJsonPath('outcome', 'signal_received');

        $eventTypes = $this->pollStartBoundaryHistory($runId);

        $this->assertEventBefore($eventTypes, 'WorkflowStarted', 'SignalReceived');
    }

    public function test_update_recorded_before_first_poll_replays_after_workflow_started(): void
    {
        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-start-boundary-update',
                'workflow_type' => 'tests.interactive-command-workflow',
                'task_queue' => 'start-boundary',
            ]);

        $start->assertCreated()
            ->assertJsonPath('outcome', 'started_new');

        $runId = (string) $start->json('run_id');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-start-boundary-update/update/approve', [
                'input' => [true, 'start-boundary'],
                'request_id' => 'start-boundary-update',
                'wait_for' => 'accepted',
            ])
            ->assertAccepted()
            ->assertJsonPath('update_status', 'accepted');

        $eventTypes = $this->pollStartBoundaryHistory($runId);

        $this->assertEventBefore($eventTypes, 'WorkflowStarted', 'UpdateAccepted');
    }

    /**
     * @return list<string>
     */
    private function pollStartBoundaryHistory(string $runId): array
    {
        $this->registerWorker(
            workerId: 'worker-start-boundary-'.$runId,
            taskQueue: 'start-boundary',
            supportedWorkflowTypes: ['tests.interactive-command-workflow'],
        );

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'worker-start-boundary-'.$runId,
                'task_queue' => 'start-boundary',
            ]);

        $poll->assertOk()
            ->assertJsonPath('task.run_id', $runId)
            ->assertJsonPath('task.workflow_type', 'tests.interactive-command-workflow');

        return array_column((array) $poll->json('task.history_events'), 'event_type');
    }

    /**
     * @param  list<string>  $eventTypes
     */
    private function assertEventBefore(array $eventTypes, string $first, string $second): void
    {
        $firstIndex = array_search($first, $eventTypes, true);
        $secondIndex = array_search($second, $eventTypes, true);

        $this->assertIsInt($firstIndex, "{$first} must be present in the worker history.");
        $this->assertIsInt($secondIndex, "{$second} must be present in the worker history.");
        $this->assertLessThan(
            $secondIndex,
            $firstIndex,
            "{$first} must be recorded before {$second} at the start boundary.",
        );
    }
}
