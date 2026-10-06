<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\V2\Contracts\OperatorObservabilityRepository;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSearchAttribute;

class WorkflowObservationTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    public function test_initial_observation_bounds_related_runs_and_failure_evidence_without_loading_child_histories(): void
    {
        $this->createNamespace('default');
        $run = $this->makeRun('coordinator', 'completed');
        for ($index = 1; $index <= 101; $index++) {
            $child = $this->makeRun(sprintf('child-%03d', $index), $index === 1 ? 'failed' : 'running');
            WorkflowLink::query()->create([
                'id' => sprintf('link-%03d', $index), 'link_type' => 'child_workflow', 'sequence' => $index,
                'parent_workflow_instance_id' => $run->workflow_instance_id, 'parent_workflow_run_id' => $run->id,
                'child_workflow_instance_id' => $child->workflow_instance_id, 'child_workflow_run_id' => $child->id,
                'is_primary_parent' => true,
            ]);
        }
        $rows = [];
        for ($sequence = 1; $sequence <= 1001; $sequence++) {
            foreach ([$run->id, 'child-001'] as $id) {
                $rows[] = [
                    'id' => $id.'-event-'.$sequence, 'workflow_run_id' => $id, 'sequence' => $sequence,
                    'event_type' => $sequence === 1 ? 'WorkflowStarted' : 'SignalReceived',
                    'payload' => '{}', 'recorded_at' => now(),
                ];
            }
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('workflow_history_events')->insert($chunk);
        }
        WorkflowFailure::query()->create([
            'id' => 'failure', 'workflow_run_id' => $run->id, 'source_kind' => 'activity', 'source_id' => 'activity',
            'propagation_kind' => 'activity', 'failure_category' => 'application',
            'exception_class' => 'RuntimeException', 'message' => 'Activity failed',
            'file' => 'fixture.php', 'line' => 12, 'trace_preview' => '',
        ]);
        WorkflowHistoryEvent::query()->create([
            'id' => 'failure-event', 'workflow_run_id' => $run->id, 'sequence' => 1002,
            'event_type' => 'ActivityFailed', 'payload' => ['failure_id' => 'failure'], 'recorded_at' => now(),
        ]);
        $retrieved = ['runs' => 0, 'history' => 0];
        WorkflowRun::retrieved(static function (WorkflowRun $model) use (&$retrieved): void {
            $retrieved['runs']++;
            self::assertArrayNotHasKey('arguments', $model->getAttributes());
            self::assertArrayNotHasKey('output', $model->getAttributes());
        });
        WorkflowHistoryEvent::retrieved(static function () use (&$retrieved): void {
            $retrieved['history']++;
        });
        $response = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/coordinator/runs/coordinator/observation')
            ->assertOk()->assertJsonPath('control_plane.operation', 'observe_workflow')
            ->assertJsonPath('read_mode', 'bounded')->assertJsonPath('status', 'completed')
            ->assertJsonPath('history_audit', 'not_evaluated')
            ->assertJsonPath('children.returned_count', 50)->assertJsonPath('children.has_more', true)
            ->assertJsonPath('children.relationships.0.status', 'failed')
            ->assertJsonPath('children.relationships.1.status', 'running')
            ->assertJsonPath('recent_failures.failures.0.supporting_event.sequence', 1002)
            ->assertJsonPath('history.returned_count', 200)->assertJsonPath('history.has_more', true)
            ->assertJsonPath('history.through_sequence', 1002);
        $this->assertSame(['runs' => 52, 'history' => 203], $retrieved);
        $this->assertArrayNotHasKey('arguments', $response->json());
        $this->assertArrayNotHasKey('output', $response->json());
        $this->assertArrayNotHasKey('cancellation_cascade', $response->json());
        $token = $response->json('recent_failures.failures.0.supporting_event.next_page_token');
        $this->withHeaders($this->apiHeaders())->getJson('/api/workflows/coordinator/runs/coordinator/observation?'.http_build_query([
            'history_page_token' => $token,
        ]))->assertOk()->assertJsonPath('history.returned_count', 1)
            ->assertJsonPath('history.events.0.event_type', 'ActivityFailed');
    }

    public function test_current_selection_is_scoped_and_never_repairs_a_wrong_pointer(): void
    {
        $this->createNamespace('default');
        $this->createNamespace('other');
        $run = $this->makeRun('selected');
        $other = $this->makeRun('other', namespace: 'other');
        $this->withHeaders($this->apiHeaders())->getJson('/api/workflows/selected/observation')
            ->assertOk()->assertJsonPath('run_id', $run->id);
        $this->withHeaders($this->apiHeaders('other'))->getJson('/api/workflows/selected/observation')->assertNotFound();
        WorkflowInstance::query()->whereKey('selected')->update(['current_run_id' => $other->id]);
        $this->withHeaders($this->apiHeaders())->getJson('/api/workflows/selected/observation')
            ->assertNotFound()->assertJsonPath('reason', 'current_run_observation_unavailable');
        $this->withHeaders($this->apiHeaders())->getJson('/api/workflows/selected/runs/selected/observation')
            ->assertOk()->assertJsonPath('current_run_id', null)->assertJsonPath('is_current_run', null);
        $this->withHeaders($this->apiHeaders())->getJson('/api/workflows/selected/runs/other/observation')->assertNotFound();
        $this->assertSame($other->id, DB::table('workflow_instances')->where('id', 'selected')->value('current_run_id'));
    }

    public function test_context_attributes_are_explicitly_selected_and_validated_before_reads(): void
    {
        $this->createNamespace('default');
        $run = $this->makeRun('context');
        foreach (['order' => '42', 'private' => 'private-value'] as $key => $value) {
            WorkflowSearchAttribute::query()->create([
                'workflow_run_id' => $run->id, 'workflow_instance_id' => $run->workflow_instance_id,
                'key' => $key, 'type' => 'keyword', 'value_keyword' => $value, 'upserted_at_sequence' => 1,
            ]);
        }
        $attributes = 0;
        WorkflowSearchAttribute::retrieved(static function () use (&$attributes): void {
            $attributes++;
        });
        $path = '/api/workflows/context/observation';
        $this->withHeaders($this->apiHeaders())->getJson($path)->assertOk()->assertJsonPath('search_attributes', []);
        $this->assertSame(0, $attributes);
        $response = $this->withHeaders($this->apiHeaders())->getJson($path.'?search_attribute_keys[]=order')
            ->assertOk()->assertJsonPath('search_attributes.order', '42');
        $this->assertSame(1, $attributes);
        $this->assertStringNotContainsString('private-value', $response->getContent());
        $this->withHeaders($this->apiHeaders())->getJson($path.'?'.http_build_query([
            'search_attribute_keys' => array_map(static fn ($id) => 'key-'.$id, range(1, 21)),
        ]))->assertStatus(422);
        $this->assertSame(1, $attributes);
    }

    public function test_an_observer_without_the_optional_capability_is_explicitly_unsupported(): void
    {
        $this->createNamespace('default');
        $this->makeRun('unsupported');
        $this->app->instance(OperatorObservabilityRepository::class, $this->createMock(OperatorObservabilityRepository::class));
        $this->withHeaders($this->apiHeaders())->getJson('/api/workflows/unsupported/observation')
            ->assertStatus(501)->assertJsonPath('reason', 'bounded_run_observation_unsupported');
    }

    public function test_history_cursor_keeps_its_original_run_and_ceiling_and_pruning_stays_explicit(): void
    {
        $this->createNamespace('default');
        $run = $this->makeRun('growing');
        $this->makeRun('other');
        for ($sequence = 1; $sequence <= 3; $sequence++) {
            WorkflowHistoryEvent::query()->create([
                'workflow_run_id' => $run->id, 'sequence' => $sequence,
                'event_type' => 'SignalReceived', 'payload' => ['arguments' => ['external_payload' => 'opaque']],
                'recorded_at' => now(),
            ]);
        }
        $path = '/api/workflows/growing/runs/growing/observation';
        $first = $this->withHeaders($this->apiHeaders())->getJson($path.'?history_page_size=2')
            ->assertOk()->assertJsonPath('history.events.0.payload.arguments.external_payload', 'opaque');
        $token = $first->json('history.next_page_token');
        WorkflowHistoryEvent::query()->create([
            'workflow_run_id' => $run->id, 'sequence' => 4, 'event_type' => 'SignalReceived',
            'payload' => [], 'recorded_at' => now(),
        ]);
        $query = '?'.http_build_query(['history_page_token' => $token]);
        $this->withHeaders($this->apiHeaders())->getJson($path.$query)->assertOk()
            ->assertJsonPath('history.through_sequence', 3)->assertJsonCount(1, 'history.events')
            ->assertJsonPath('history.events.0.sequence', 3)->assertJsonPath('history.next_page_token', null);
        $this->withHeaders($this->apiHeaders())->getJson('/api/workflows/other/runs/other/observation'.$query)->assertStatus(422);
        $this->withHeaders($this->apiHeaders())->getJson($path.'?history_page_token=invalid')->assertStatus(422);
        $run->update(['details_pruned_at' => now()]);
        WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->delete();
        $this->withHeaders($this->apiHeaders())->getJson($path)->assertOk()
            ->assertJsonPath('history.details_state', 'pruned')->assertJsonPath('history.returned_count', 0);
    }

    private function makeRun(string $id, string $status = 'running', string $namespace = 'default'): WorkflowRun
    {
        WorkflowInstance::query()->create([
            'id' => $id, 'namespace' => $namespace,
            'workflow_class' => ExternalGreetingWorkflow::class,
            'workflow_type' => 'tests.external-greeting-workflow', 'run_count' => 1,
        ]);
        $run = WorkflowRun::query()->create([
            'id' => $id, 'workflow_instance_id' => $id, 'namespace' => $namespace,
            'workflow_class' => ExternalGreetingWorkflow::class, 'workflow_type' => 'tests.external-greeting-workflow',
            'status' => $status, 'run_number' => 1, 'connection' => 'database', 'queue' => 'default',
            'payload_codec' => 'avro', 'started_at' => now(),
        ]);
        WorkflowInstance::query()->whereKey($id)->update(['current_run_id' => $id]);

        return $run;
    }
}
