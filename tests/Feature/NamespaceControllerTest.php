<?php

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NamespaceControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_namespaces(): void
    {
        WorkflowNamespace::create([
            'name' => 'default',
            'description' => 'Default namespace',
            'retention_days' => 30,
            'status' => 'active',
        ]);

        WorkflowNamespace::create([
            'name' => 'staging',
            'description' => 'Staging namespace',
            'retention_days' => 7,
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/namespaces');

        $response->assertOk()
            ->assertJsonCount(2, 'namespaces')
            ->assertJsonPath('namespaces.0.name', 'default')
            ->assertJsonPath('namespaces.0.retention_days', 30)
            ->assertJsonPath('namespaces.1.name', 'staging')
            ->assertJsonPath('namespaces.1.retention_days', 7);
    }

    public function test_it_returns_empty_list_when_no_namespaces_exist(): void
    {
        $response = $this->getJson('/api/namespaces');

        $response->assertOk()
            ->assertJsonCount(0, 'namespaces');
    }

    public function test_it_creates_a_namespace(): void
    {
        $response = $this->postJson('/api/namespaces', [
            'name' => 'production',
            'description' => 'Production environment',
            'retention_days' => 90,
        ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'production')
            ->assertJsonPath('description', 'Production environment')
            ->assertJsonPath('retention_days', 90)
            ->assertJsonPath('status', 'active');

        $this->assertDatabaseHas('workflow_namespaces', [
            'name' => 'production',
            'description' => 'Production environment',
            'retention_days' => 90,
            'status' => 'active',
        ]);
    }

    public function test_it_creates_a_namespace_with_default_retention(): void
    {
        $response = $this->postJson('/api/namespaces', [
            'name' => 'minimal',
        ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'minimal')
            ->assertJsonPath('retention_days', config('server.history.retention_days'));
    }

    public function test_it_rejects_namespace_creation_without_a_name(): void
    {
        $response = $this->postJson('/api/namespaces', [
            'description' => 'Missing name',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_it_rejects_namespace_names_with_invalid_characters(): void
    {
        $response = $this->postJson('/api/namespaces', [
            'name' => 'invalid namespace!',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_it_rejects_namespace_names_exceeding_max_length(): void
    {
        $response = $this->postJson('/api/namespaces', [
            'name' => str_repeat('a', 129),
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_it_rejects_duplicate_namespace_names(): void
    {
        WorkflowNamespace::create([
            'name' => 'existing',
            'retention_days' => 30,
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/namespaces', [
            'name' => 'existing',
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('error', 'Namespace already exists.')
            ->assertJsonPath('namespace', 'existing');
    }

    public function test_it_rejects_retention_days_out_of_range(): void
    {
        $response = $this->postJson('/api/namespaces', [
            'name' => 'bad-retention',
            'retention_days' => 0,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('retention_days');

        $response = $this->postJson('/api/namespaces', [
            'name' => 'bad-retention',
            'retention_days' => 366,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('retention_days');
    }

    public function test_it_shows_a_namespace(): void
    {
        WorkflowNamespace::create([
            'name' => 'default',
            'description' => 'Default namespace',
            'retention_days' => 30,
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/namespaces/default');

        $response->assertOk()
            ->assertJsonPath('name', 'default')
            ->assertJsonPath('description', 'Default namespace')
            ->assertJsonPath('retention_days', 30)
            ->assertJsonPath('status', 'active')
            ->assertJsonStructure(['created_at', 'updated_at']);
    }

    public function test_it_returns_404_for_unknown_namespace(): void
    {
        $response = $this->getJson('/api/namespaces/nonexistent');

        $response->assertNotFound();
    }

    public function test_it_updates_a_namespace_description(): void
    {
        WorkflowNamespace::create([
            'name' => 'default',
            'description' => 'Original',
            'retention_days' => 30,
            'status' => 'active',
        ]);

        $response = $this->putJson('/api/namespaces/default', [
            'description' => 'Updated description',
        ]);

        $response->assertOk()
            ->assertJsonPath('name', 'default')
            ->assertJsonPath('description', 'Updated description')
            ->assertJsonPath('retention_days', 30);

        $this->assertDatabaseHas('workflow_namespaces', [
            'name' => 'default',
            'description' => 'Updated description',
        ]);
    }

    public function test_it_updates_a_namespace_retention_days(): void
    {
        WorkflowNamespace::create([
            'name' => 'default',
            'description' => 'Default',
            'retention_days' => 30,
            'status' => 'active',
        ]);

        $response = $this->putJson('/api/namespaces/default', [
            'retention_days' => 90,
        ]);

        $response->assertOk()
            ->assertJsonPath('retention_days', 90);

        $this->assertDatabaseHas('workflow_namespaces', [
            'name' => 'default',
            'retention_days' => 90,
        ]);
    }

    public function test_it_returns_404_when_updating_unknown_namespace(): void
    {
        $response = $this->putJson('/api/namespaces/nonexistent', [
            'description' => 'Update',
        ]);

        $response->assertNotFound();
    }

    public function test_it_rejects_update_with_invalid_retention(): void
    {
        WorkflowNamespace::create([
            'name' => 'default',
            'retention_days' => 30,
            'status' => 'active',
        ]);

        $response = $this->putJson('/api/namespaces/default', [
            'retention_days' => 0,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('retention_days');
    }

    public function test_list_response_includes_timestamps(): void
    {
        WorkflowNamespace::create([
            'name' => 'default',
            'description' => 'Default',
            'retention_days' => 30,
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/namespaces');

        $response->assertOk()
            ->assertJsonStructure([
                'namespaces' => [
                    ['name', 'description', 'retention_days', 'status', 'created_at', 'updated_at'],
                ],
            ]);
    }

    public function test_it_allows_namespace_names_with_dots_underscores_and_hyphens(): void
    {
        $names = ['my.namespace', 'my_namespace', 'my-namespace', 'ns.v2_test-1'];

        foreach ($names as $name) {
            $response = $this->postJson('/api/namespaces', [
                'name' => $name,
            ]);

            $response->assertCreated();
        }

        $response = $this->getJson('/api/namespaces');
        $response->assertJsonCount(count($names), 'namespaces');
    }
}
