<?php

namespace Tests\Feature;

use App\Models\SearchAttributeDefinition;
use App\Models\WorkflowNamespace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchAttributeTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_system_attributes_and_empty_custom_attributes_for_a_new_namespace(): void
    {
        $this->createNamespace('default');

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/search-attributes');

        $response->assertOk()
            ->assertJsonPath('system_attributes.WorkflowType', 'keyword')
            ->assertJsonPath('system_attributes.WorkflowId', 'keyword')
            ->assertJsonPath('system_attributes.RunId', 'keyword')
            ->assertJsonPath('system_attributes.Status', 'keyword')
            ->assertJsonPath('system_attributes.StartTime', 'datetime')
            ->assertJsonPath('system_attributes.CloseTime', 'datetime')
            ->assertJsonPath('system_attributes.TaskQueue', 'keyword')
            ->assertJsonPath('system_attributes.BuildId', 'keyword')
            ->assertJsonPath('custom_attributes', []);
    }

    public function test_it_creates_a_custom_search_attribute(): void
    {
        $this->createNamespace('default');

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/search-attributes', [
                'name' => 'OrderStatus',
                'type' => 'keyword',
            ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'OrderStatus')
            ->assertJsonPath('type', 'keyword')
            ->assertJsonPath('outcome', 'created');

        $this->assertDatabaseHas('search_attribute_definitions', [
            'namespace' => 'default',
            'name' => 'OrderStatus',
            'type' => 'keyword',
        ]);
    }

    public function test_it_lists_custom_attributes_after_creation(): void
    {
        $this->createNamespace('default');

        SearchAttributeDefinition::create([
            'namespace' => 'default',
            'name' => 'Priority',
            'type' => 'int',
        ]);

        SearchAttributeDefinition::create([
            'namespace' => 'default',
            'name' => 'Region',
            'type' => 'keyword',
        ]);

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/search-attributes');

        $response->assertOk()
            ->assertJsonPath('custom_attributes.Priority', 'int')
            ->assertJsonPath('custom_attributes.Region', 'keyword');
    }

    public function test_it_scopes_custom_attributes_to_the_namespace(): void
    {
        $this->createNamespace('default');
        $this->createNamespace('other');

        SearchAttributeDefinition::create([
            'namespace' => 'default',
            'name' => 'Tenant',
            'type' => 'keyword',
        ]);

        SearchAttributeDefinition::create([
            'namespace' => 'other',
            'name' => 'Region',
            'type' => 'keyword',
        ]);

        $defaultResponse = $this->withHeaders($this->headers('default'))
            ->getJson('/api/search-attributes');

        $defaultResponse->assertOk()
            ->assertJsonPath('custom_attributes.Tenant', 'keyword');
        $this->assertArrayNotHasKey('Region', $defaultResponse->json('custom_attributes'));

        $otherResponse = $this->withHeaders($this->headers('other'))
            ->getJson('/api/search-attributes');

        $otherResponse->assertOk()
            ->assertJsonPath('custom_attributes.Region', 'keyword');
        $this->assertArrayNotHasKey('Tenant', $otherResponse->json('custom_attributes'));
    }

    public function test_it_rejects_duplicate_custom_attribute_names(): void
    {
        $this->createNamespace('default');

        SearchAttributeDefinition::create([
            'namespace' => 'default',
            'name' => 'Priority',
            'type' => 'int',
        ]);

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/search-attributes', [
                'name' => 'Priority',
                'type' => 'keyword',
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('reason', 'attribute_already_exists')
            ->assertJsonPath('name', 'Priority')
            ->assertJsonPath('type', 'int');
    }

    public function test_it_rejects_system_attribute_names(): void
    {
        $this->createNamespace('default');

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/search-attributes', [
                'name' => 'WorkflowId',
                'type' => 'keyword',
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('reason', 'name_reserved');
    }

    public function test_it_validates_attribute_name_format(): void
    {
        $this->createNamespace('default');

        $this->withHeaders($this->headers())
            ->postJson('/api/search-attributes', [
                'name' => '123invalid',
                'type' => 'keyword',
            ])
            ->assertStatus(422);

        $this->withHeaders($this->headers())
            ->postJson('/api/search-attributes', [
                'name' => 'has spaces',
                'type' => 'keyword',
            ])
            ->assertStatus(422);
    }

    public function test_it_validates_attribute_type(): void
    {
        $this->createNamespace('default');

        $this->withHeaders($this->headers())
            ->postJson('/api/search-attributes', [
                'name' => 'ValidName',
                'type' => 'invalid_type',
            ])
            ->assertStatus(422);
    }

    public function test_it_supports_all_allowed_types(): void
    {
        $this->createNamespace('default');

        foreach (SearchAttributeDefinition::ALLOWED_TYPES as $type) {
            $name = 'Attr'.ucfirst(str_replace('_', '', $type));

            $response = $this->withHeaders($this->headers())
                ->postJson('/api/search-attributes', [
                    'name' => $name,
                    'type' => $type,
                ]);

            $response->assertCreated()
                ->assertJsonPath('name', $name)
                ->assertJsonPath('type', $type);
        }

        $listResponse = $this->withHeaders($this->headers())
            ->getJson('/api/search-attributes');

        $this->assertCount(
            count(SearchAttributeDefinition::ALLOWED_TYPES),
            $listResponse->json('custom_attributes'),
        );
    }

    public function test_it_deletes_a_custom_search_attribute(): void
    {
        $this->createNamespace('default');

        SearchAttributeDefinition::create([
            'namespace' => 'default',
            'name' => 'Obsolete',
            'type' => 'keyword',
        ]);

        $response = $this->withHeaders($this->headers())
            ->deleteJson('/api/search-attributes/Obsolete');

        $response->assertOk()
            ->assertJsonPath('name', 'Obsolete')
            ->assertJsonPath('outcome', 'deleted');

        $this->assertDatabaseMissing('search_attribute_definitions', [
            'namespace' => 'default',
            'name' => 'Obsolete',
        ]);
    }

    public function test_it_returns_404_when_deleting_a_nonexistent_attribute(): void
    {
        $this->createNamespace('default');

        $response = $this->withHeaders($this->headers())
            ->deleteJson('/api/search-attributes/DoesNotExist');

        $response->assertNotFound()
            ->assertJsonPath('reason', 'attribute_not_found');
    }

    public function test_it_rejects_deleting_system_attributes(): void
    {
        $this->createNamespace('default');

        $response = $this->withHeaders($this->headers())
            ->deleteJson('/api/search-attributes/WorkflowType');

        $response->assertStatus(409)
            ->assertJsonPath('reason', 'system_attribute');
    }

    public function test_delete_is_namespace_scoped(): void
    {
        $this->createNamespace('default');
        $this->createNamespace('other');

        SearchAttributeDefinition::create([
            'namespace' => 'other',
            'name' => 'OtherAttr',
            'type' => 'keyword',
        ]);

        $response = $this->withHeaders($this->headers('default'))
            ->deleteJson('/api/search-attributes/OtherAttr');

        $response->assertNotFound()
            ->assertJsonPath('reason', 'attribute_not_found');

        $this->assertDatabaseHas('search_attribute_definitions', [
            'namespace' => 'other',
            'name' => 'OtherAttr',
        ]);
    }

    private function createNamespace(string $name): void
    {
        WorkflowNamespace::query()->updateOrCreate(
            ['name' => $name],
            [
                'description' => "{$name} namespace",
                'retention_days' => 30,
                'status' => 'active',
            ],
        );
    }

    private function headers(string $namespace = 'default'): array
    {
        return [
            'X-Namespace' => $namespace,
        ];
    }
}
