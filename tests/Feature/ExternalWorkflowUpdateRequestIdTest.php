<?php

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;

class ExternalWorkflowUpdateRequestIdTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    private const WORKFLOW_TYPE = 'tests.retained-update';

    public static function namespaces(): iterable
    {
        yield 'default' => ['default'];
        yield 'named' => ['retained-namespace'];
    }

    #[DataProvider('namespaces')]
    public function test_request_id_reused_for_another_declared_name_returns_original_without_mutation(string $namespace): void
    {
        $this->startRemote('retained-original', $namespace);
        $original = $this->postJson('/api/workflows/retained-original/update/add', [
            'input' => [17], 'request_id' => 'retained-key', 'wait_for' => 'accepted',
        ], $this->apiHeaders($namespace))->assertAccepted();
        $before = $this->durableRows();

        $this->postJson('/api/workflows/retained-original/update/other', [
            'input' => [99], 'request_id' => 'retained-key', 'wait_for' => 'accepted',
        ], $this->apiHeaders($namespace))
            ->assertAccepted()
            ->assertJsonPath('update_id', $original->json('update_id'))
            ->assertJsonPath('command_id', $original->json('command_id'))
            ->assertJsonPath('run_id', $original->json('run_id'))
            ->assertJsonPath('update_name', 'add')
            ->assertJsonPath('update_status', 'accepted');

        $this->assertSame($before, $this->durableRows());
    }

    public function test_same_name_changed_arguments_keeps_existing_receipt(): void
    {
        $this->startRemote('same-name');
        $original = $this->postJson('/api/workflows/same-name/update/add', [
            'input' => [17], 'request_id' => 'retained-key',
        ], $this->apiHeaders())->assertAccepted();
        $before = $this->durableRows();

        $this->postJson('/api/workflows/same-name/update/add', [
            'input' => [99], 'request_id' => 'retained-key',
        ], $this->apiHeaders())->assertAccepted()
            ->assertJsonPath('update_id', $original->json('update_id'));

        $this->assertSame($before, $this->durableRows());
    }

    public function test_a_new_key_admits_the_other_update(): void
    {
        $this->startRemote('new-key');
        $first = $this->postJson('/api/workflows/new-key/update/add', [
            'input' => [17], 'request_id' => 'first-key',
        ], $this->apiHeaders())->assertAccepted();
        $second = $this->postJson('/api/workflows/new-key/update/other', [
            'input' => [99], 'request_id' => 'second-key',
        ], $this->apiHeaders())->assertAccepted()->assertJsonPath('update_name', 'other');

        $this->assertNotSame($first->json('update_id'), $second->json('update_id'));
        $this->assertDatabaseCount('workflow_updates', 2);
    }

    public function test_request_keys_are_independent_between_workflow_instances(): void
    {
        $this->startRemote('first-instance');
        $this->startRemote('second-instance');
        $first = $this->postJson('/api/workflows/first-instance/update/add', [
            'input' => [17], 'request_id' => 'shared-key',
        ], $this->apiHeaders())->assertAccepted();
        $second = $this->postJson('/api/workflows/second-instance/update/other', [
            'input' => [99], 'request_id' => 'shared-key',
        ], $this->apiHeaders())->assertAccepted()->assertJsonPath('update_name', 'other');

        $this->assertNotSame($first->json('update_id'), $second->json('update_id'));
    }

    public function test_foreign_namespace_cannot_obtain_a_retained_receipt(): void
    {
        $this->startRemote('namespace-original');
        $this->createNamespace('foreign');
        $this->postJson('/api/workflows/namespace-original/update/add', [
            'input' => [17], 'request_id' => 'retained-key',
        ], $this->apiHeaders())->assertAccepted();
        $before = $this->durableRows();

        $this->postJson('/api/workflows/namespace-original/update/other', [
            'input' => [99], 'request_id' => 'retained-key',
        ], $this->apiHeaders('foreign'))->assertNotFound()->assertJsonPath('reason', 'instance_not_found');

        $this->assertSame($before, $this->durableRows());
    }

    public function test_omitted_request_ids_do_not_create_an_idempotency_key(): void
    {
        $this->startRemote('no-key');
        $first = $this->postJson('/api/workflows/no-key/update/add', ['input' => [17]], $this->apiHeaders())->assertAccepted();
        $second = $this->postJson('/api/workflows/no-key/update/other', ['input' => [99]], $this->apiHeaders())->assertAccepted();

        $this->assertNotSame($first->json('update_id'), $second->json('update_id'));
        $this->assertDatabaseCount('workflow_updates', 2);
    }

    private function startRemote(string $workflowId, string $namespace = 'default'): void
    {
        Queue::fake();
        $this->createNamespace($namespace);
        $contract = [
            'queries' => [], 'query_contracts' => [], 'signals' => [], 'signal_contracts' => [],
            'updates' => ['add', 'other'], 'update_validators' => [],
            'update_contracts' => array_map(static fn (string $name): array => [
                'name' => $name,
                'parameters' => [[
                    'name' => 'amount', 'position' => 0, 'required' => true, 'variadic' => false,
                    'default_available' => false, 'default' => null, 'type' => 'int', 'allows_null' => false,
                ]],
            ], ['add', 'other']),
        ];
        WorkerRegistration::query()->updateOrCreate(['worker_id' => 'retained-'.$namespace, 'namespace' => $namespace], [
            'task_queue' => 'retained-'.$namespace, 'runtime' => 'php', 'supported_workflow_types' => [self::WORKFLOW_TYPE],
            'workflow_command_contracts' => [self::WORKFLOW_TYPE => $contract], 'supported_activity_types' => [],
            'last_heartbeat_at' => now(), 'status' => 'active',
        ]);
        $this->postJson('/api/workflows', [
            'workflow_id' => $workflowId, 'workflow_type' => self::WORKFLOW_TYPE, 'task_queue' => 'retained-'.$namespace,
        ], $this->apiHeaders($namespace))->assertCreated();
    }

    private function durableRows(): array
    {
        $snapshot = [];
        foreach (['workflow_commands', 'workflow_updates', 'workflow_runs', 'workflow_tasks', 'workflow_history_events'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
        }

        return $snapshot;
    }
}
