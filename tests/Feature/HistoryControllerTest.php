<?php

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowHistoryEvent;

class HistoryControllerTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'workflows.v2.types.workflows' => [
                'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
            ],
        ]);
    }

    public function test_it_returns_history_events_for_a_workflow_run(): void
    {
        Queue::fake();

        $this->createNamespace('default');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-history-show',
                'workflow_type' => 'tests.external-greeting-workflow',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $runId = (string) $start->json('run_id');

        $response = $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/wf-history-show/runs/{$runId}/history");

        $response->assertOk()
            ->assertHeader('X-Durable-Workflow-Control-Plane-Version', '2')
            ->assertJsonPath('workflow_id', 'wf-history-show')
            ->assertJsonPath('run_id', $runId)
            ->assertJsonStructure([
                'events' => [
                    ['sequence', 'event_type', 'timestamp'],
                ],
            ]);

        $events = $response->json('events');
        $this->assertNotEmpty($events);

        // Events should be in sequence order
        $sequences = array_column($events, 'sequence');
        $this->assertSame($sequences, array_values(array_unique($sequences)));
        $this->assertSame(
            $sequences,
            collect($sequences)->sort()->values()->all(),
        );
    }

    public function test_it_paginates_history_with_page_size(): void
    {
        Queue::fake();

        $this->createNamespace('default');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-history-paginate',
                'workflow_type' => 'tests.external-greeting-workflow',
                'input' => ['Grace'],
            ]);

        $start->assertCreated();

        $runId = (string) $start->json('run_id');

        // Run the workflow task to generate more history events
        $this->runReadyWorkflowTask($runId);

        $totalEvents = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->count();

        $this->assertGreaterThan(1, $totalEvents, 'Need multiple events to test pagination');

        // Request just 1 event at a time
        $response = $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/wf-history-paginate/runs/{$runId}/history?page_size=1");

        $response->assertOk()
            ->assertJsonCount(1, 'events');

        $nextPageToken = $response->json('next_page_token');
        $this->assertNotNull($nextPageToken, 'Should have a next page token when more events exist');

        // Fetch next page
        $response2 = $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/wf-history-paginate/runs/{$runId}/history?page_size=1&next_page_token={$nextPageToken}");

        $response2->assertOk()
            ->assertJsonCount(1, 'events');

        // Second page should have later sequence numbers
        $firstPageSequence = $response->json('events.0.sequence');
        $secondPageSequence = $response2->json('events.0.sequence');
        $this->assertGreaterThan($firstPageSequence, $secondPageSequence);
    }

    public function test_it_returns_null_next_page_token_when_no_more_events(): void
    {
        Queue::fake();

        $this->createNamespace('default');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-history-no-more',
                'workflow_type' => 'tests.external-greeting-workflow',
                'input' => ['Taylor'],
            ]);

        $start->assertCreated();

        $runId = (string) $start->json('run_id');

        // Request with a large page size to get all events
        $response = $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/wf-history-no-more/runs/{$runId}/history?page_size=1000");

        $response->assertOk()
            ->assertJsonPath('next_page_token', null);
    }

    public function test_it_returns_404_for_unknown_workflow_run(): void
    {
        $this->createNamespace('default');

        $response = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-unknown/runs/run-unknown/history');

        $response->assertNotFound();
    }

    public function test_it_returns_404_for_workflow_in_wrong_namespace(): void
    {
        Queue::fake();

        $this->createNamespace('default');
        $this->createNamespace('other');

        $start = $this->withHeaders($this->apiHeaders('default'))
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-history-scoped',
                'workflow_type' => 'tests.external-greeting-workflow',
                'input' => ['Scoped'],
            ]);

        $start->assertCreated();

        $runId = (string) $start->json('run_id');

        // Should be visible in the default namespace
        $this->withHeaders($this->apiHeaders('default'))
            ->getJson("/api/workflows/wf-history-scoped/runs/{$runId}/history")
            ->assertOk();

        // Should not be visible in the other namespace
        $this->withHeaders($this->apiHeaders('other'))
            ->getJson("/api/workflows/wf-history-scoped/runs/{$runId}/history")
            ->assertNotFound();
    }

    public function test_it_rejects_requests_without_control_plane_version_header(): void
    {
        $this->createNamespace('default');

        $response = $this->withHeaders(['X-Namespace' => 'default'])
            ->getJson('/api/workflows/wf-any/runs/run-any/history');

        $response->assertBadRequest()
            ->assertJsonPath('reason', 'missing_control_plane_version');
    }

    public function test_it_validates_page_size_bounds(): void
    {
        Queue::fake();

        $this->createNamespace('default');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-history-validate',
                'workflow_type' => 'tests.external-greeting-workflow',
                'input' => ['Validate'],
            ]);

        $start->assertCreated();

        $runId = (string) $start->json('run_id');

        // page_size = 0 should fail validation
        $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/wf-history-validate/runs/{$runId}/history?page_size=0")
            ->assertUnprocessable();

        // page_size = 1001 should fail validation
        $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/wf-history-validate/runs/{$runId}/history?page_size=1001")
            ->assertUnprocessable();
    }

    public function test_it_exports_history_as_a_replay_bundle(): void
    {
        Queue::fake();

        $this->createNamespace('default');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-history-export',
                'workflow_type' => 'tests.external-greeting-workflow',
                'input' => ['Export'],
            ]);

        $start->assertCreated();

        $runId = (string) $start->json('run_id');

        $this->runReadyWorkflowTask($runId);

        $response = $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/wf-history-export/runs/{$runId}/history/export");

        $response->assertOk()
            ->assertHeader('X-Durable-Workflow-Control-Plane-Version', '2')
            ->assertJsonPath('schema', 'durable-workflow.v2.history-export')
            ->assertJsonPath('workflow.instance_id', 'wf-history-export')
            ->assertJsonPath('workflow.run_id', $runId);
    }

    public function test_export_returns_404_for_unknown_run(): void
    {
        $this->createNamespace('default');

        $response = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-unknown/runs/run-unknown/history/export');

        $response->assertNotFound();
    }

    public function test_export_respects_namespace_scoping(): void
    {
        Queue::fake();

        $this->createNamespace('default');
        $this->createNamespace('other');

        $start = $this->withHeaders($this->apiHeaders('default'))
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-export-scoped',
                'workflow_type' => 'tests.external-greeting-workflow',
                'input' => ['Scoped'],
            ]);

        $start->assertCreated();

        $runId = (string) $start->json('run_id');

        $this->runReadyWorkflowTask($runId);

        // Export from the correct namespace should work
        $this->withHeaders($this->apiHeaders('default'))
            ->getJson("/api/workflows/wf-export-scoped/runs/{$runId}/history/export")
            ->assertOk();

        // Export from the wrong namespace should 404
        $this->withHeaders($this->apiHeaders('other'))
            ->getJson("/api/workflows/wf-export-scoped/runs/{$runId}/history/export")
            ->assertNotFound();
    }
}
