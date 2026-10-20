<?php

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\InteractiveCommandWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Models\WorkflowCommand;

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

    public static function legacyRequestNames(): iterable
    {
        yield 'same name, missing column' => ['add', null];
        yield 'different name, missing column' => ['other', null];
        yield 'same name, proxy column' => ['add', 'legacy-proxy'];
        yield 'different name, proxy column' => ['other', 'legacy-proxy'];
    }

    #[DataProvider('legacyRequestNames')]
    public function test_legacy_context_key_is_retained_without_repairing_stored_column(string $name, ?string $storedKey): void
    {
        $this->startRemote('legacy-key');
        $original = $this->postJson('/api/workflows/legacy-key/update/add', [
            'input' => [17], 'request_id' => 'retained-key',
        ], $this->apiHeaders())->assertAccepted();
        DB::table('workflow_commands')->where('id', $original->json('command_id'))->update(['request_id' => $storedKey]);
        $before = $this->durableRows();

        $this->postJson('/api/workflows/legacy-key/update/'.$name, [
            'input' => [99], 'request_id' => 'retained-key',
        ], $this->apiHeaders())->assertAccepted()
            ->assertJsonPath('update_id', $original->json('update_id'))
            ->assertJsonPath('update_name', 'add');

        $this->assertSame($before, $this->durableRows());
        $this->assertSame($storedKey, DB::table('workflow_commands')->where('id', $original->json('command_id'))->value('request_id'));
    }

    public static function proxyRequestKeys(): iterable
    {
        foreach (['default', 'retained-namespace'] as $namespace) {
            foreach (['remote', 'php'] as $definition) {
                yield $definition.' '.$namespace.' duplicate caller key' => [$definition, $namespace, 'caller-key', 'caller-key', 'proxy-a', 'proxy-b', true];
                yield $definition.' '.$namespace.' independent caller keys' => [$definition, $namespace, 'caller-a', 'caller-b', 'shared-proxy', 'shared-proxy', false];
                yield $definition.' '.$namespace.' omitted keys' => [$definition, $namespace, null, null, 'shared-proxy', 'shared-proxy', false];
                yield $definition.' '.$namespace.' literal zero key' => [$definition, $namespace, '0', '0', 'proxy-a', 'proxy-b', true];
                yield $definition.' '.$namespace.' maximum length key' => [$definition, $namespace, str_repeat('k', 255), str_repeat('k', 255), 'proxy-a', 'proxy-b', true];
                yield $definition.' '.$namespace.' maximum Unicode key' => [$definition, $namespace, str_repeat('é', 255), str_repeat('é', 255), 'proxy-a', 'proxy-b', true];
                yield $definition.' '.$namespace.' case sensitive key' => [$definition, $namespace, 'Caller', 'caller', 'proxy', 'proxy', false];
            }
        }
    }

    #[DataProvider('proxyRequestKeys')]
    public function test_only_the_caller_key_selects_an_update_receipt(
        string $definition, string $namespace, ?string $firstKey, ?string $secondKey,
        string $firstHeader, string $secondHeader, bool $sameReceipt,
    ): void {
        if ($definition === 'php') {
            $this->startAvailablePhp('proxy-request-key', $namespace);
        } else {
            $this->startRemote('proxy-request-key', $namespace);
        }
        $firstName = $definition === 'php' ? 'approve' : 'add';
        $secondName = $definition === 'php' ? 'approve' : 'other';
        $first = $this->postJson('/api/workflows/proxy-request-key/update/'.$firstName, [
            'input' => $definition === 'php' ? [true, 'original'] : [17], 'request_id' => $firstKey,
        ], [...$this->apiHeaders($namespace), 'X-Request-Id' => $firstHeader])->assertAccepted();
        $before = $this->durableRows();
        $second = $this->postJson('/api/workflows/proxy-request-key/update/'.$secondName, [
            'input' => $definition === 'php' ? [false, 'changed'] : [99], 'request_id' => $secondKey,
        ], [...$this->apiHeaders($namespace), 'X-Request-Id' => $secondHeader])->assertAccepted();

        if ($sameReceipt) {
            $second->assertJsonPath('update_id', $first->json('update_id'));
            $this->assertSame($before, $this->durableRows());
        } else {
            $this->assertNotSame($first->json('update_id'), $second->json('update_id'));
            $this->assertDatabaseCount('workflow_updates', 2);
        }
        $command = DB::table('workflow_commands')->where('id', $first->json('command_id'))->first();
        $this->assertNotNull($command);
        $this->assertSame($firstKey, WorkflowCommand::query()->findOrFail($command->id)->requestId());
        $this->assertLessThanOrEqual(191, strlen($command->request_id));
        $context = json_decode($command->context, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($firstHeader, $context['request']['headers']['x_request_id']);
    }

    public function test_available_php_definition_retains_a_legacy_body_key_receipt(): void
    {
        $this->startAvailablePhp('available-legacy-key');
        $original = $this->postJson('/api/workflows/available-legacy-key/update/approve', [
            'input' => [true, 'original'], 'request_id' => 'caller-key',
        ], [...$this->apiHeaders(), 'X-Request-Id' => 'proxy-a'])->assertAccepted();
        DB::table('workflow_commands')->where('id', $original->json('command_id'))
            ->update(['request_id' => 'legacy-proxy']);
        $before = $this->durableRows();

        $this->postJson('/api/workflows/available-legacy-key/update/approve', [
            'input' => [false, 'changed'], 'request_id' => 'caller-key',
        ], [...$this->apiHeaders(), 'X-Request-Id' => 'proxy-b'])->assertAccepted()
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

    public function test_a_legacy_proxy_column_does_not_reserve_another_callers_key(): void
    {
        $this->startRemote('legacy-proxy-occupancy');
        $original = $this->postJson('/api/workflows/legacy-proxy-occupancy/update/add', [
            'input' => [17], 'request_id' => 'original-caller',
        ], $this->apiHeaders())->assertAccepted();
        DB::table('workflow_commands')->where('id', $original->json('command_id'))
            ->update(['request_id' => 'next-caller']);

        $next = $this->postJson('/api/workflows/legacy-proxy-occupancy/update/other', [
            'input' => [99], 'request_id' => 'next-caller',
        ], $this->apiHeaders())->assertAccepted();
        $this->assertNotSame($original->json('update_id'), $next->json('update_id'));
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

    public static function serviceLimits(): iterable
    {
        foreach (['default', 'retained-namespace'] as $namespace) {
            yield $namespace.' pending limit' => [$namespace, 'pending_update_count'];
            yield $namespace.' payload limit' => [$namespace, 'payload_size'];
        }
    }

    #[DataProvider('serviceLimits')]
    public function test_service_limits_retain_inspectable_rejection_without_closing_the_run(string $namespace, string $kind): void
    {
        $this->startRemote('limited-update', $namespace);
        if ($kind === 'pending_update_count') {
            $this->postJson('/api/workflows/limited-update/update/add', [
                'input' => [17], 'request_id' => 'first',
            ], $this->apiHeaders($namespace))->assertAccepted();
            config()->set('workflows.v2.structural_limits.pending_update_count', 1);
        } else {
            config()->set('workflows.v2.structural_limits.payload_size_bytes', 1);
        }
        $run = DB::table('workflow_runs')->sole();
        $response = $this->postJson('/api/workflows/limited-update/update/add', [
            'input' => [17], 'request_id' => 'over-limit',
        ], $this->apiHeaders($namespace))
            ->assertStatus(409)
            ->assertJsonPath('reason', 'structural_limit_exceeded')
            ->assertJsonPath('structural_limit_kind', $kind)
            ->assertJsonPath('structural_limit_configured', 1)
            ->assertJsonPath('structural_limit_value', $kind === 'pending_update_count'
                ? 1 : strlen(Serializer::serializeWithCodec('avro', [17])));
        $command = WorkflowCommand::query()->findOrFail($response->json('command_id'));
        $this->assertSame('structural_limit_exceeded', $command->rejection_reason);
        $this->assertSame('rejected', DB::table('workflow_updates')->where('id', $response->json('update_id'))->value('status'));
        $afterRun = DB::table('workflow_runs')->sole();
        foreach (['status', 'started_at', 'execution_deadline_at', 'run_deadline_at', 'run_timeout_seconds'] as $field) {
            $this->assertSame($run->{$field}, $afterRun->{$field});
        }
        $before = $this->durableRows();
        config()->set('workflows.v2.structural_limits.pending_update_count', 0);
        config()->set('workflows.v2.structural_limits.payload_size_bytes', 0);
        $this->postJson('/api/workflows/limited-update/update/other', [
            'input' => [99], 'request_id' => 'over-limit',
        ], $this->apiHeaders($namespace))->assertStatus(409)
            ->assertJsonPath('command_id', $response->json('command_id'))
            ->assertJsonPath('structural_limit_kind', $kind)
            ->assertJsonPath('structural_limit_configured', 1);
        $this->assertSame($before, $this->durableRows());
    }

    public static function terminalTransitions(): iterable
    {
        yield 'closed original' => ['complete_workflow'];
        yield 'continue-as-new successor' => ['continue_as_new'];
    }

    #[DataProvider('terminalTransitions')]
    public function test_completed_receipt_survives_real_task_closure_or_continuation(string $transition): void
    {
        $this->startRemote('retained-terminal');
        $original = $this->postJson('/api/workflows/retained-terminal/update/add', [
            'input' => [17], 'request_id' => 'retained-key',
        ], $this->apiHeaders())->assertAccepted();
        $runId = $original->json('run_id');
        $poll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'retained-default', 'task_queue' => 'retained-default',
        ], $this->workerHeaders())->assertOk()->assertJsonPath('task.run_id', $runId);
        $value = ['count' => 17, 'nil' => null, 'label' => 'café'];
        $resultEnvelope = ['codec' => 'avro', 'blob' => Serializer::serializeWithCodec('avro', $value)];
        $closing = $transition === 'continue_as_new'
            ? ['type' => $transition, 'arguments' => ['codec' => 'avro', 'blob' => Serializer::serializeWithCodec('avro', [])]]
            : ['type' => $transition, 'result' => $resultEnvelope];
        $this->postJson('/api/worker/workflow-tasks/'.$poll->json('task.task_id').'/complete', [
            'lease_owner' => $poll->json('task.lease_owner'),
            'workflow_task_attempt' => $poll->json('task.workflow_task_attempt'),
            'commands' => [
                ['type' => 'complete_update', 'update_id' => $original->json('update_id'),
                    'result' => $resultEnvelope],
                $closing,
            ],
        ], $this->workerHeaders())->assertOk()->assertJsonPath('recorded', true)->assertJsonPath('run_status', 'completed');
        $this->assertDatabaseCount('workflow_runs', $transition === 'continue_as_new' ? 2 : 1);
        $before = $this->durableRows();

        $this->postJson('/api/workflows/retained-terminal/update/other', [
            'input' => [99], 'request_id' => 'retained-key', 'wait_for' => 'completed',
        ], $this->apiHeaders())->assertOk()
            ->assertJsonPath('update_id', $original->json('update_id'))
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('update_name', 'add')
            ->assertJsonPath('update_status', 'completed')
            ->assertJsonPath('result', $value);

        $this->assertSame($before, $this->durableRows());
        if ($transition === 'continue_as_new') {
            $this->postJson('/api/workflows/retained-terminal/runs/'.$runId.'/update/other', [
                'input' => [99], 'request_id' => 'retained-key',
            ], $this->apiHeaders())->assertStatus(409)->assertJsonPath('reason', 'historical_run_command_rejected');
            $this->assertSame($before, $this->durableRows());
        }
    }

    private function startAvailablePhp(string $workflowId, string $namespace = 'default'): void
    {
        Queue::fake();
        $this->createNamespace($namespace);
        config()->set('workflows.v2.types.workflows', [
            'tests.interactive-command-workflow' => InteractiveCommandWorkflow::class,
        ]);
        $start = $this->postJson('/api/workflows', [
            'workflow_id' => $workflowId, 'workflow_type' => 'tests.interactive-command-workflow',
        ], $this->apiHeaders($namespace))->assertCreated();
        $this->runReadyWorkflowTask($start->json('run_id'));
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
