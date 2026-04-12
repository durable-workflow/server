<?php

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use App\Support\LongPoller;
use App\Support\LongPollSignalStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\Fixtures\AwaitApprovalWorkflow;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\WorkflowExecutor;

class LongPollSignalIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
        ]);
    }

    public function test_starting_a_workflow_signals_the_namespaced_workflow_task_poll_channels(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        /** @var LongPollSignalStore $signals */
        $signals = app(LongPollSignalStore::class);
        $snapshot = $signals->snapshot(
            $signals->workflowTaskPollChannels('default', null, 'external-workflows'),
        );

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-long-poll-signal-start',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ])
            ->assertCreated();

        $this->assertTrue($signals->changed($snapshot));
    }

    public function test_running_a_workflow_task_signals_activity_poll_channels_and_history_waiters(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-long-poll-signal-task',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Grace'],
            ]);

        $start->assertCreated();

        $runId = (string) $start->json('run_id');

        /** @var LongPollSignalStore $signals */
        $signals = app(LongPollSignalStore::class);

        $activitySnapshot = $signals->snapshot(
            $signals->activityTaskPollChannels('default', null, 'external-activities'),
        );
        $historySnapshot = $signals->snapshot([
            $signals->historyRunChannel($runId),
        ]);

        $this->runReadyWorkflowTask($runId);

        $this->assertTrue($signals->changed($activitySnapshot));
        $this->assertTrue($signals->changed($historySnapshot));
    }

    public function test_history_wait_new_event_uses_the_run_wake_channel_and_returns_new_events(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-long-poll-history-follow',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Taylor'],
            ]);

        $start->assertCreated();

        $runId = (string) $start->json('run_id');
        $lastSequence = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->max('sequence');

        $this->assertIsInt($lastSequence);
        $this->assertGreaterThan(0, $lastSequence);

        /** @var LongPollSignalStore $signals */
        $signals = app(LongPollSignalStore::class);
        $expectedChannel = $signals->historyRunChannel($runId);
        $captured = new \ArrayObject();

        $this->mock(LongPoller::class, function (MockInterface $mock) use (
            $expectedChannel,
            $runId,
            $captured,
        ): void {
            $mock->shouldReceive('until')
                ->once()
                ->andReturnUsing(function (
                    callable $probe,
                    callable $ready,
                    ?int $timeoutSeconds = null,
                    ?int $intervalMilliseconds = null,
                    array $wakeChannels = [],
                    ?callable $nextProbeAt = null,
                ) use ($expectedChannel, $runId, $captured) {
                    $this->assertSame([$expectedChannel], $wakeChannels);
                    $this->assertIsCallable($nextProbeAt);

                    $initial = $probe();
                    $this->assertCount(0, $initial);
                    $this->assertFalse($ready($initial));

                    $this->runReadyWorkflowTask($runId);

                    $events = $probe();
                    $this->assertTrue($ready($events));
                    $captured['returned_sequences'] = $events->pluck('sequence')->all();

                    return $events;
                });
        });

        $history = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-long-poll-history-follow/runs/'.$runId.'/history?'.http_build_query([
                'wait_new_event' => 1,
                'next_page_token' => base64_encode((string) $lastSequence),
            ]));

        $history->assertOk()
            ->assertHeader('X-Durable-Workflow-Control-Plane-Version', '2');

        $returnedSequences = $captured['returned_sequences'] ?? [];
        $this->assertIsArray($returnedSequences);
        $this->assertNotEmpty($returnedSequences);
        $this->assertTrue(collect($returnedSequences)->every(
            static fn (int $sequence): bool => $sequence > $lastSequence,
        ));
    }

    public function test_history_wait_new_event_uses_run_summary_timing_hints_for_earlier_reprobes(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-long-poll-history-hint',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Taylor'],
            ]);

        $start->assertCreated();

        $runId = (string) $start->json('run_id');
        $lastSequence = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->max('sequence');

        $this->assertIsInt($lastSequence);

        $nextTaskAt = now()->addMinutes(2)->startOfSecond();
        $leaseExpiryAt = now()->addMinutes(3)->startOfSecond();
        $waitDeadlineAt = now()->addMinutes(5)->startOfSecond();

        WorkflowRunSummary::query()
            ->whereKey($runId)
            ->update([
                'next_task_at' => $nextTaskAt,
                'next_task_lease_expires_at' => $leaseExpiryAt,
                'wait_deadline_at' => $waitDeadlineAt,
            ]);

        /** @var LongPollSignalStore $signals */
        $signals = app(LongPollSignalStore::class);
        $expectedChannel = $signals->historyRunChannel($runId);

        $this->mock(LongPoller::class, function (MockInterface $mock) use (
            $expectedChannel,
            $nextTaskAt,
        ): void {
            $mock->shouldReceive('until')
                ->once()
                ->andReturnUsing(function (
                    callable $probe,
                    callable $ready,
                    ?int $timeoutSeconds = null,
                    ?int $intervalMilliseconds = null,
                    array $wakeChannels = [],
                    ?callable $nextProbeAt = null,
                ) use ($expectedChannel, $nextTaskAt) {
                    $this->assertSame([$expectedChannel], $wakeChannels);
                    $this->assertIsCallable($nextProbeAt);

                    $initial = $probe();
                    $this->assertCount(0, $initial);
                    $this->assertFalse($ready($initial));

                    $hint = $nextProbeAt();

                    $this->assertInstanceOf(\DateTimeInterface::class, $hint);
                    $this->assertSame(
                        $nextTaskAt->format('U.u'),
                        $hint->format('U.u'),
                    );

                    return $initial;
                });
        });

        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-long-poll-history-hint/runs/'.$runId.'/history?'.http_build_query([
                'wait_new_event' => 1,
                'next_page_token' => base64_encode((string) $lastSequence),
            ]))
            ->assertOk()
            ->assertHeader('X-Durable-Workflow-Control-Plane-Version', '2')
            ->assertJsonCount(0, 'events')
            ->assertJsonPath('next_page_token', null);
    }

    private function configureWorkflowTypes(): void
    {
        config()->set('workflows.v2.types.workflows', [
            'tests.await-approval-workflow' => AwaitApprovalWorkflow::class,
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);
    }

    private function createNamespace(string $name, string $description): void
    {
        WorkflowNamespace::query()->updateOrCreate(
            ['name' => $name],
            [
                'description' => $description,
                'retention_days' => 30,
                'status' => 'active',
            ],
        );
    }

    private function apiHeaders(string $namespace = 'default'): array
    {
        return [
            'X-Namespace' => $namespace,
            'X-Durable-Workflow-Control-Plane-Version' => '2',
        ];
    }

    private function runReadyWorkflowTask(string $runId): void
    {
        $taskId = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', 'workflow')
            ->where('status', 'ready')
            ->orderBy('available_at')
            ->value('id');

        $this->assertIsString($taskId);

        $job = new RunWorkflowTask($taskId);
        $job->handle(app(WorkflowExecutor::class));
    }
}
