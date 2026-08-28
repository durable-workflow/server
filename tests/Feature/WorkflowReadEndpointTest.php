<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\AwaitApprovalWorkflow;
use Tests\Fixtures\InteractiveCommandWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

class WorkflowReadEndpointTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->configureWorkflowTypes([
            'tests.interactive-command-workflow' => InteractiveCommandWorkflow::class,
            'tests.await-approval-workflow' => AwaitApprovalWorkflow::class,
        ]);

        $this->createNamespace('default');
    }

    // ── Describe (show) ─────────────────────────────────────────────

    public function test_describe_returns_full_response_structure_for_a_running_workflow(): void
    {
        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-read-describe',
                'workflow_type' => 'tests.await-approval-workflow',
                'business_key' => 'order-42',
            ]);

        $start->assertCreated();
        $runId = (string) $start->json('run_id');

        $this->runReadyWorkflowTask($runId);

        $describe = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-read-describe');

        $describe->assertOk()
            ->assertJsonPath('workflow_id', 'wf-read-describe')
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('namespace', 'default')
            ->assertJsonPath('workflow_type', 'tests.await-approval-workflow')
            ->assertJsonPath('business_key', 'order-42')
            ->assertJsonPath('is_terminal', false)
            ->assertJsonPath('run_number', 1)
            ->assertJsonPath('run_count', 1)
            ->assertJsonPath('is_current_run', true)
            ->assertJsonPath('actions.can_signal', true)
            ->assertJsonPath('actions.can_query', true)
            ->assertJsonPath('actions.can_update', true)
            ->assertJsonPath('actions.can_cancel', true)
            ->assertJsonPath('actions.can_terminate', true)
            ->assertJsonStructure([
                'workflow_id',
                'run_id',
                'namespace',
                'workflow_type',
                'business_key',
                'status',
                'status_bucket',
                'is_terminal',
                'task_queue',
                'run_number',
                'run_count',
                'is_current_run',
                'compatibility',
                'payload_codec',
                'execution_timeout_seconds',
                'run_timeout_seconds',
                'execution_deadline_at',
                'run_deadline_at',
                'input',
                'output',
                'input_envelope',
                'output_envelope',
                'started_at',
                'closed_at',
                'last_progress_at',
                'wait_kind',
                'wait_reason',
                'memo',
                'search_attributes',
                'actions' => [
                    'can_signal',
                    'can_query',
                    'can_update',
                    'can_cancel',
                    'can_terminate',
                    'can_repair',
                    'can_archive',
                ],
                'control_plane',
            ]);
    }

    public function test_describe_returns_404_for_unknown_workflow(): void
    {
        $describe = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-nonexistent');

        $describe->assertNotFound()
            ->assertJsonPath('message', 'Workflow not found.')
            ->assertJsonPath('reason', 'instance_not_found');
    }

    public function test_describe_is_scoped_by_namespace(): void
    {
        $this->createNamespace('other');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-read-ns-scoped',
                'workflow_type' => 'tests.await-approval-workflow',
            ]);

        $start->assertCreated();

        // Visible in default namespace
        $this->withHeaders($this->apiHeaders('default'))
            ->getJson('/api/workflows/wf-read-ns-scoped')
            ->assertOk()
            ->assertJsonPath('workflow_id', 'wf-read-ns-scoped');

        // Not visible in other namespace
        $this->withHeaders($this->apiHeaders('other'))
            ->getJson('/api/workflows/wf-read-ns-scoped')
            ->assertNotFound()
            ->assertJsonPath('reason', 'instance_not_found');
    }

    public function test_describe_shows_terminal_state_after_workflow_completes(): void
    {
        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-read-terminal',
                'workflow_type' => 'tests.await-approval-workflow',
            ]);

        $start->assertCreated();
        $runId = (string) $start->json('run_id');

        $this->runReadyWorkflowTask($runId);

        // Terminate the workflow to reach a terminal state
        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-read-terminal/terminate', [
                'reason' => 'test termination',
            ])
            ->assertOk();

        $describe = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-read-terminal');

        $describe->assertOk()
            ->assertJsonPath('workflow_id', 'wf-read-terminal')
            ->assertJsonPath('is_terminal', true)
            ->assertJsonPath('status_bucket', 'failed')
            ->assertJsonPath('actions.can_signal', false)
            ->assertJsonPath('actions.can_query', false)
            ->assertJsonPath('actions.can_update', false)
            ->assertJsonPath('actions.can_cancel', false)
            ->assertJsonPath('actions.can_terminate', false)
            ->assertJsonPath('actions.can_repair', false)
            ->assertJsonPath('actions.can_archive', true);

        $this->assertNotNull($describe->json('closed_at'));
    }

    // ── Runs (list runs) ────────────────────────────────────────────

    public function test_runs_returns_run_list_with_expected_structure(): void
    {
        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-read-runs-list',
                'workflow_type' => 'tests.await-approval-workflow',
            ]);

        $start->assertCreated();
        $runId = (string) $start->json('run_id');

        $runs = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-read-runs-list/runs');

        $runs->assertOk()
            ->assertJsonPath('workflow_id', 'wf-read-runs-list')
            ->assertJsonPath('run_count', 1)
            ->assertJsonPath('runs.0.run_id', $runId)
            ->assertJsonPath('runs.0.run_number', 1)
            ->assertJsonPath('runs.0.workflow_type', 'tests.await-approval-workflow')
            ->assertJsonStructure([
                'workflow_id',
                'run_count',
                'runs' => [
                    '*' => [
                        'run_id',
                        'run_number',
                        'workflow_type',
                        'business_key',
                        'status',
                        'task_queue',
                        'memo',
                        'started_at',
                        'closed_at',
                    ],
                ],
                'control_plane',
            ]);
    }

    public function test_runs_returns_404_for_unknown_workflow(): void
    {
        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-nonexistent/runs')
            ->assertNotFound()
            ->assertJsonPath('reason', 'instance_not_found');
    }

    public function test_runs_is_scoped_by_namespace(): void
    {
        $this->createNamespace('other');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-read-runs-ns',
                'workflow_type' => 'tests.await-approval-workflow',
            ])
            ->assertCreated();

        // Visible in default
        $this->withHeaders($this->apiHeaders('default'))
            ->getJson('/api/workflows/wf-read-runs-ns/runs')
            ->assertOk()
            ->assertJsonPath('run_count', 1);

        // Not visible in other
        $this->withHeaders($this->apiHeaders('other'))
            ->getJson('/api/workflows/wf-read-runs-ns/runs')
            ->assertNotFound();
    }

    // ── Show Run ────────────────────────────────────────────────────

    public function test_show_run_returns_full_response_for_valid_run_id(): void
    {
        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-read-show-run',
                'workflow_type' => 'tests.await-approval-workflow',
                'business_key' => 'run-detail-test',
            ]);

        $start->assertCreated();
        $runId = (string) $start->json('run_id');

        $this->runReadyWorkflowTask($runId);

        $showRun = $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/wf-read-show-run/runs/{$runId}");

        $showRun->assertOk()
            ->assertJsonPath('workflow_id', 'wf-read-show-run')
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('namespace', 'default')
            ->assertJsonPath('business_key', 'run-detail-test')
            ->assertJsonPath('is_terminal', false)
            ->assertJsonPath('run_number', 1)
            ->assertJsonPath('is_current_run', true)
            ->assertJsonStructure([
                'workflow_id',
                'run_id',
                'namespace',
                'workflow_type',
                'business_key',
                'status',
                'status_bucket',
                'is_terminal',
                'task_queue',
                'run_number',
                'input',
                'output',
                'input_envelope',
                'output_envelope',
                'started_at',
                'closed_at',
                'actions',
                'control_plane',
            ]);
    }

    public function test_build_pinned_run_detail_survives_worker_task_failure_and_replay_completion(): void
    {
        config(['server.polling.timeout' => 0]);

        $taskQueue = 'versioned-replay-detail';
        $workflowType = 'external.versioned-replay';
        $workflowId = 'wf-versioned-replay-detail';
        $buildV1 = 'build-v1';
        $buildV2 = 'build-v2';

        $registration = [
            'worker_id' => 'versioned-worker-v1',
            'task_queue' => $taskQueue,
            'runtime' => 'php',
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
            'sdk_version' => '1.0.0',
            'build_id' => $buildV1,
            'supported_workflow_types' => [$workflowType],
            'workflow_definition_fingerprints' => [$workflowType => 'versioned-replay-v1'],
        ];

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', $registration)
            ->assertCreated();

        $this->withHeaders($this->apiHeaders())
            ->postJson("/api/task-queues/{$taskQueue}/build-ids/promote", [
                'build_id' => $buildV1,
            ])
            ->assertSuccessful();

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => $workflowId,
                'workflow_type' => $workflowType,
                'task_queue' => $taskQueue,
                'input' => ['v1'],
            ])
            ->assertCreated();
        $runId = (string) $start->json('run_id');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', array_replace($registration, [
                'worker_id' => 'versioned-worker-v2',
                'build_id' => $buildV2,
                'workflow_definition_fingerprints' => [$workflowType => 'versioned-replay-v2'],
            ]))
            ->assertCreated();

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'versioned-worker-v2',
                'task_queue' => $taskQueue,
                'build_id' => $buildV2,
                'timeout_seconds' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('task', null);

        $firstPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'versioned-worker-v1',
                'task_queue' => $taskQueue,
                'build_id' => $buildV1,
                'timeout_seconds' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('task.run_id', $runId)
            ->assertJsonPath('task.compatibility', $buildV1);

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$firstPoll->json('task.task_id')}/fail", [
                'lease_owner' => $firstPoll->json('task.lease_owner'),
                'workflow_task_attempt' => $firstPoll->json('task.workflow_task_attempt'),
                'failure' => [
                    'message' => 'worker process restarted before workflow task completion',
                    'type' => 'RuntimeError',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('recorded', true);

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', $registration + [
                'process_metrics' => ['process_id' => 1002],
            ])
            ->assertCreated();

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'versioned-worker-v2',
                'task_queue' => $taskQueue,
                'build_id' => $buildV2,
                'timeout_seconds' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('task', null);

        $replayPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'versioned-worker-v1',
                'task_queue' => $taskQueue,
                'build_id' => $buildV1,
                'timeout_seconds' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('task.run_id', $runId)
            ->assertJsonPath('task.compatibility', $buildV1)
            ->assertJsonPath('task.workflow_task_attempt', 2);

        $expectedResult = ['activity_a', 'activity_b'];
        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$replayPoll->json('task.task_id')}/complete", [
                'lease_owner' => $replayPoll->json('task.lease_owner'),
                'workflow_task_attempt' => $replayPoll->json('task.workflow_task_attempt'),
                'commands' => [[
                    'type' => 'complete_workflow',
                    'result' => [
                        'codec' => 'avro',
                        'blob' => Serializer::serializeWithCodec('avro', $expectedResult),
                    ],
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('run_status', 'completed');

        $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/{$workflowId}/runs/{$runId}")
            ->assertOk()
            ->assertJsonPath('workflow_id', $workflowId)
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('compatibility', $buildV1)
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('output', $expectedResult)
            ->assertJsonPath('output_envelope.codec', 'avro');

        $history = $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/{$workflowId}/runs/{$runId}/history")
            ->assertOk()
            ->assertJsonPath('compatibility', $buildV1);

        $eventTypes = array_column($history->json('events'), 'event_type');
        $this->assertContains('WorkflowStarted', $eventTypes);
        $this->assertContains('WorkflowCompleted', $eventTypes);
        $this->assertSame(1, count(array_keys($eventTypes, 'WorkflowCompleted', true)));
        $this->assertSame(1, WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('status', TaskStatus::Failed->value)
            ->count());
        $this->assertSame(1, WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('status', TaskStatus::Completed->value)
            ->count());
        $this->assertSame([$buildV1], WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->pluck('compatibility')
            ->unique()
            ->values()
            ->all());
    }

    public function test_unexpected_selected_run_read_failure_is_correlated_and_bounded(): void
    {
        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-read-correlated-failure',
                'workflow_type' => 'tests.await-approval-workflow',
            ])
            ->assertCreated();
        $runId = (string) $start->json('run_id');
        $serverMessage = str_repeat('unexpected read failure ', 200);

        $this->mock(WorkflowControlPlane::class, function (MockInterface $mock) use ($serverMessage): void {
            $mock->shouldReceive('describe')
                ->once()
                ->andThrow(new RuntimeException($serverMessage));
        });
        Log::spy();

        $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/wf-read-correlated-failure/runs/{$runId}")
            ->assertStatus(500)
            ->assertJsonPath('reason', 'control_plane_internal_error')
            ->assertJsonPath('retryable', false)
            ->assertJsonPath('workflow_id', 'wf-read-correlated-failure')
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('control_plane.operation', 'describe_run')
            ->assertJsonPath('control_plane.reason', 'control_plane_internal_error')
            ->assertJsonPath('error_id', static fn (mixed $value): bool => is_string($value) && $value !== '')
            ->assertJsonPath('exception.type', RuntimeException::class)
            ->assertJsonPath('exception.message', static fn (mixed $value): bool => is_string($value)
                && strlen($value) <= 512
                && str_starts_with($serverMessage, $value));

        Log::shouldHaveReceived('error')
            ->withArgs(function (string $message, array $context) use ($runId, $serverMessage): bool {
                $exceptionMessage = $context['exception_chain'][0]['message'] ?? null;

                return $message === 'Unhandled control-plane operation exception.'
                    && ($context['operation']['name'] ?? null) === 'describe_run'
                    && ($context['operation']['workflow_id'] ?? null) === 'wf-read-correlated-failure'
                    && ($context['operation']['requested_run_id'] ?? null) === $runId
                    && ($context['workflow']['run_id'] ?? null) === $runId
                    && ($context['workflow']['workflow_id'] ?? null) === 'wf-read-correlated-failure'
                    && is_string($exceptionMessage)
                    && strlen($exceptionMessage) <= 2048
                    && str_starts_with($serverMessage, $exceptionMessage);
            })
            ->once();
    }

    public function test_current_and_selected_run_surface_unknown_legacy_output_codec(): void
    {
        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-read-show-run-undecodable-payload',
                'workflow_type' => 'tests.await-approval-workflow',
                'input' => ['seed' => true],
            ]);

        $start->assertCreated();
        $runId = (string) $start->json('run_id');

        $run = WorkflowRun::query()->findOrFail($runId);
        $run->forceFill([
            'arguments' => 'not-a-decodable-input-payload',
            'output' => 'not-a-decodable-output-payload',
            'output_payload_codec' => null,
            'payload_codec' => 'workflow-serializer-y',
            'compatibility' => 'php-worker-v1',
        ])->save();

        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowCompleted, [
            'output' => 'not-a-decodable-output-payload',
        ]);

        foreach ([
            '/api/workflows/wf-read-show-run-undecodable-payload',
            "/api/workflows/wf-read-show-run-undecodable-payload/runs/{$runId}",
        ] as $path) {
            $this->withHeaders($this->apiHeaders())
                ->getJson($path)
                ->assertStatus(500)
                ->assertJsonPath('reason', 'workflow_output_codec_unavailable')
                ->assertJsonPath(
                    'message',
                    "Workflow output codec is unavailable for run [{$runId}]; the terminal output cannot be decoded safely.",
                );
        }
    }

    public function test_show_run_returns_404_for_unknown_run_id(): void
    {
        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-read-run-404',
                'workflow_type' => 'tests.await-approval-workflow',
            ])
            ->assertCreated();

        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-read-run-404/runs/99999')
            ->assertNotFound()
            ->assertJsonPath('message', 'Workflow run not found.')
            ->assertJsonPath('reason', 'run_not_found')
            ->assertJsonPath('workflow_id', 'wf-read-run-404')
            ->assertJsonPath('run_id', '99999')
            ->assertJsonPath('control_plane.operation', 'describe_run');
    }

    public function test_show_run_returns_404_for_unknown_workflow(): void
    {
        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-nonexistent/runs/1')
            ->assertNotFound()
            ->assertJsonPath('message', 'Workflow run not found.')
            ->assertJsonPath('reason', 'run_not_found')
            ->assertJsonPath('workflow_id', 'wf-nonexistent')
            ->assertJsonPath('run_id', '1')
            ->assertJsonPath('control_plane.operation', 'describe_run');
    }

    public function test_show_run_is_scoped_by_namespace(): void
    {
        $this->createNamespace('other');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-read-run-ns',
                'workflow_type' => 'tests.await-approval-workflow',
            ]);

        $start->assertCreated();
        $runId = (string) $start->json('run_id');

        // Visible in default
        $this->withHeaders($this->apiHeaders('default'))
            ->getJson("/api/workflows/wf-read-run-ns/runs/{$runId}")
            ->assertOk()
            ->assertJsonPath('run_id', $runId);

        // Not visible in other
        $this->withHeaders($this->apiHeaders('other'))
            ->getJson("/api/workflows/wf-read-run-ns/runs/{$runId}")
            ->assertNotFound();
    }

    // ── Workflow List ────────────────────────────────────────────────

    public function test_workflow_list_returns_paginated_results_with_expected_structure(): void
    {
        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-read-list-1',
                'workflow_type' => 'tests.await-approval-workflow',
                'business_key' => 'list-item-1',
            ])
            ->assertCreated();

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-read-list-2',
                'workflow_type' => 'tests.await-approval-workflow',
                'business_key' => 'list-item-2',
            ])
            ->assertCreated();

        $list = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows');

        $list->assertOk()
            ->assertJsonPath('workflow_count', 2)
            ->assertJsonStructure([
                'workflows' => [
                    '*' => [
                        'workflow_id',
                        'run_id',
                        'workflow_type',
                        'business_key',
                        'status',
                        'status_bucket',
                        'task_queue',
                        'is_terminal',
                        'started_at',
                        'closed_at',
                        'search_attributes',
                    ],
                ],
                'workflow_count',
                'next_page_token',
                'control_plane',
            ]);
    }

    public function test_workflow_list_paginates_with_page_size(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->withHeaders($this->apiHeaders())
                ->postJson('/api/workflows', [
                    'workflow_id' => "wf-read-page-{$i}",
                    'workflow_type' => 'tests.await-approval-workflow',
                ])
                ->assertCreated();
        }

        $page1 = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows?page_size=2');

        $page1->assertOk()
            ->assertJsonPath('workflow_count', 2);

        $nextPageToken = $page1->json('next_page_token');
        $this->assertNotNull($nextPageToken);

        $page2 = $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows?page_size=2&next_page_token={$nextPageToken}");

        $page2->assertOk()
            ->assertJsonPath('workflow_count', 1)
            ->assertJsonPath('next_page_token', null);
    }

    public function test_workflow_list_is_scoped_by_namespace(): void
    {
        $this->createNamespace('other');

        $this->withHeaders($this->apiHeaders('default'))
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-read-list-ns',
                'workflow_type' => 'tests.await-approval-workflow',
            ])
            ->assertCreated();

        $this->withHeaders($this->apiHeaders('default'))
            ->getJson('/api/workflows')
            ->assertOk()
            ->assertJsonPath('workflow_count', 1);

        $this->withHeaders($this->apiHeaders('other'))
            ->getJson('/api/workflows')
            ->assertOk()
            ->assertJsonPath('workflow_count', 0);
    }

    public function test_workflow_list_filters_by_workflow_type(): void
    {
        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-read-filter-type-1',
                'workflow_type' => 'tests.await-approval-workflow',
            ])
            ->assertCreated();

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-read-filter-type-2',
                'workflow_type' => 'tests.interactive-command-workflow',
            ])
            ->assertCreated();

        $filtered = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows?workflow_type='.urlencode('tests.await-approval-workflow'));

        $filtered->assertOk()
            ->assertJsonPath('workflow_count', 1)
            ->assertJsonPath('workflows.0.workflow_id', 'wf-read-filter-type-1');
    }

    public function test_workflow_list_filters_by_query_string(): void
    {
        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-search-target-abc',
                'workflow_type' => 'tests.await-approval-workflow',
            ])
            ->assertCreated();

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-search-other-xyz',
                'workflow_type' => 'tests.await-approval-workflow',
            ])
            ->assertCreated();

        $results = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows?query=target-abc');

        $results->assertOk()
            ->assertJsonPath('workflow_count', 1)
            ->assertJsonPath('workflows.0.workflow_id', 'wf-search-target-abc');
    }

    // ── Control Plane Version Enforcement ────────────────────────────

    public function test_describe_rejects_requests_without_control_plane_version(): void
    {
        $this->withHeaders(['X-Namespace' => 'default'])
            ->getJson('/api/workflows/wf-any')
            ->assertStatus(400);
    }

    public function test_runs_rejects_requests_without_control_plane_version(): void
    {
        $this->withHeaders(['X-Namespace' => 'default'])
            ->getJson('/api/workflows/wf-any/runs')
            ->assertStatus(400);
    }

    public function test_show_run_rejects_requests_without_control_plane_version(): void
    {
        $this->withHeaders(['X-Namespace' => 'default'])
            ->getJson('/api/workflows/wf-any/runs/1')
            ->assertStatus(400);
    }
}
