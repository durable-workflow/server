<?php

namespace Tests\Feature;

use App\Models\SearchAttributeDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\AwaitApprovalWorkflow;
use Tests\TestCase;

class WorkflowSearchAttributeVisibilityQueryTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->configureWorkflowTypes([
            'tests.await-approval-workflow' => AwaitApprovalWorkflow::class,
        ]);

        $this->createNamespace('default');
        $this->createNamespace('other');

        foreach (['default', 'other'] as $namespace) {
            SearchAttributeDefinition::query()->create([
                'namespace' => $namespace,
                'name' => 'customer_id',
                'type' => 'string',
            ]);
            SearchAttributeDefinition::query()->create([
                'namespace' => $namespace,
                'name' => 'order_total_cents',
                'type' => 'int',
            ]);
            SearchAttributeDefinition::query()->create([
                'namespace' => $namespace,
                'name' => 'is_vip',
                'type' => 'bool',
            ]);
            SearchAttributeDefinition::query()->create([
                'namespace' => $namespace,
                'name' => 'priority_tier',
                'type' => 'keyword',
            ]);
            SearchAttributeDefinition::query()->create([
                'namespace' => $namespace,
                'name' => 'tags',
                'type' => 'keyword_list',
            ]);
        }

        $this->startWorkflow('default', 'wf-sa-match', [
            'customer_id' => 'cust-7',
            'order_total_cents' => 7500,
            'is_vip' => true,
            'priority_tier' => 'gold',
            'tags' => ['urgent', 'oversized'],
        ]);

        $this->startWorkflow('default', 'wf-sa-miss', [
            'customer_id' => 'cust-2',
            'order_total_cents' => 1200,
            'is_vip' => false,
            'priority_tier' => 'silver',
            'tags' => ['standard'],
        ]);

        $this->startWorkflow('default', 'wf-sa-non-vip', [
            'customer_id' => 'cust-8',
            'order_total_cents' => 9000,
            'is_vip' => false,
            'priority_tier' => 'platinum',
            'tags' => ['urgent'],
        ]);

        $this->startWorkflow('other', 'wf-sa-other-namespace', [
            'customer_id' => 'cust-7',
            'order_total_cents' => 7500,
            'is_vip' => true,
            'priority_tier' => 'gold',
            'tags' => ['urgent'],
        ]);
    }

    public function test_workflow_list_query_filters_by_keyword_search_attribute(): void
    {
        $this->assertSame(['wf-sa-match'], $this->workflowIdsForQuery('customer_id = "cust-7"'));
    }

    public function test_workflow_list_query_filters_by_numeric_search_attribute_range(): void
    {
        $this->assertSame(['wf-sa-non-vip', 'wf-sa-match'], $this->workflowIdsForQuery('order_total_cents > 5000 AND order_total_cents <= 10000'));
    }

    public function test_workflow_list_query_filters_by_boolean_search_attribute(): void
    {
        $this->assertSame(['wf-sa-match'], $this->workflowIdsForQuery('is_vip = true'));
    }

    public function test_workflow_list_search_attribute_query_is_namespace_scoped(): void
    {
        $this->assertSame(['wf-sa-other-namespace'], $this->workflowIdsForQuery('customer_id = "cust-7"', 'other'));
    }

    public function test_workflow_list_query_filters_by_keyword_list_membership(): void
    {
        $this->assertSame(['wf-sa-non-vip', 'wf-sa-match'], $this->workflowIdsForQuery('tags = "urgent"'));
    }

    public function test_workflow_list_eager_loads_search_attributes_for_page(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->getJson('/api/workflows?page_size=3', $this->apiHeaders());

        $response->assertOk()
            ->assertJsonPath('workflow_count', 3)
            ->assertJsonPath('workflows.0.search_attributes.tags.0', 'urgent');

        $searchAttributeQueries = collect(DB::getQueryLog())
            ->filter(static fn (array $query): bool => str_contains(
                strtolower((string) ($query['query'] ?? '')),
                'workflow_search_attributes'
            ))
            ->count();

        DB::disableQueryLog();

        $this->assertSame(
            1,
            $searchAttributeQueries,
            'Workflow list should hydrate search attributes for the page in one relation query.'
        );
    }

    public function test_workflow_list_query_supports_in_and_not_boolean_predicates(): void
    {
        $this->assertSame(['wf-sa-non-vip'], $this->workflowIdsForQuery('priority_tier IN ("gold", "platinum") AND NOT is_vip'));
    }

    public function test_workflow_list_query_supports_or_predicates(): void
    {
        $this->assertSame(['wf-sa-non-vip', 'wf-sa-miss'], $this->workflowIdsForQuery('customer_id = "cust-2" OR customer_id = "cust-8"'));
    }

    public function test_workflow_list_query_rejects_or_injection_predicates(): void
    {
        $response = $this->getJson(
            '/api/workflows?'.http_build_query(['query' => 'customer_id = "cust-7" OR 1=1']),
            $this->apiHeaders(),
        );

        $response->assertStatus(422)
            ->assertJsonPath('errors.query.0', 'Visibility query predicates must use: Field = literal.');
    }

    public function test_workflow_list_query_rejects_embedded_sql_comment_input(): void
    {
        $response = $this->getJson(
            '/api/workflows?'.http_build_query(['query' => 'customer_id = "cust-7" -- embedded SQL comment']),
            $this->apiHeaders(),
        );

        $response->assertStatus(422)
            ->assertJsonPath('errors.query.0', 'Visibility query literal ["cust-7" -- embedded SQL comment] is not valid.');
    }

    public function test_workflow_list_query_rejects_shell_metacharacter_input(): void
    {
        $response = $this->getJson(
            '/api/workflows?'.http_build_query(['query' => 'customer_id = "cust-7"; rm -rf /']),
            $this->apiHeaders(),
        );

        $response->assertStatus(422)
            ->assertJsonPath('errors.query.0', 'Visibility query literal ["cust-7"; rm -rf /] is not valid.');
    }

    public function test_workflow_list_query_rejects_search_attribute_literal_type_mismatch(): void
    {
        $response = $this->getJson(
            '/api/workflows?'.http_build_query(['query' => 'order_total_cents = "not-a-number"']),
            $this->apiHeaders(),
        );

        $response->assertStatus(422)
            ->assertJsonPath('errors.query.0', '[order_total_cents] must be compared with an integer literal.');
    }

    public function test_workflow_list_query_rejects_undefined_search_attribute(): void
    {
        $response = $this->getJson(
            '/api/workflows?'.http_build_query(['query' => 'unknown_attr = "missing"']),
            $this->apiHeaders(),
        );

        $response->assertStatus(422)
            ->assertJsonPath(
                'errors.query.0',
                'Search attribute [unknown_attr] is not defined in namespace [default].',
            );
    }

    /**
     * @param  array<string, scalar|list<string>|null>  $searchAttributes
     */
    private function startWorkflow(string $namespace, string $workflowId, array $searchAttributes): void
    {
        $this->postJson('/api/workflows', [
            'workflow_id' => $workflowId,
            'workflow_type' => 'tests.await-approval-workflow',
            'business_key' => $workflowId,
            'search_attributes' => $searchAttributes,
        ], $this->apiHeaders($namespace))->assertCreated();
    }

    /**
     * @return list<string>
     */
    private function workflowIdsForQuery(string $query, string $namespace = 'default'): array
    {
        $response = $this->getJson(
            '/api/workflows?'.http_build_query(['query' => $query]),
            $this->apiHeaders($namespace),
        );

        $response->assertOk();

        return array_column($response->json('workflows'), 'workflow_id');
    }
}
