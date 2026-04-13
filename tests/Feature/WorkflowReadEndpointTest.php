<?php

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\AwaitApprovalWorkflow;
use Tests\Fixtures\InteractiveCommandWorkflow;
use Tests\TestCase;

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
                'input',
                'output',
                'started_at',
                'closed_at',
                'memo',
                'search_attributes',
                'actions',
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
            ->assertJsonPath('actions.can_terminate', false);

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
                'started_at',
                'closed_at',
                'actions',
                'control_plane',
            ]);
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
            ->assertJsonPath('message', 'Workflow run not found.');
    }

    public function test_show_run_returns_404_for_unknown_workflow(): void
    {
        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-nonexistent/runs/1')
            ->assertNotFound()
            ->assertJsonPath('message', 'Workflow run not found.');
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
            ->getJson('/api/workflows?workflow_type=' . urlencode('tests.await-approval-workflow'));

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
