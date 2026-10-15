<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WorkerBuildIdRollout;
use App\Models\WorkerRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;

class WorkerCommandContractAdmissionTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    private const TYPE = 'external.command-snapshot';

    private const QUEUE = 'command-snapshot';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->createNamespace('default');
        $this->createNamespace('other');
    }

    #[DataProvider('emptyDeclarations')]
    public function test_explicit_empty_contract_is_retained_at_original_admission(array|\stdClass $contract): void
    {
        $this->registerDeclaration(['workflow_command_contracts' => [self::TYPE => $contract]]);
        $observation = $this->startAndObserve('original-empty');
        $this->assertSame('durable_history', $observation['command_contract']['source']);
        $this->assertFalse($observation['command_contract']['backfill_needed']);
        foreach (['queries', 'signals', 'updates', 'query_contracts', 'signal_contracts', 'update_contracts'] as $field) {
            $this->assertSame([], $observation['command_contract'][$field]);
        }
        $payload = $this->startedPayload($observation['run_id']);
        $this->assertSame([], $payload['declared_queries']);
        $this->assertSame([], $payload['declared_signals']);
        $this->assertSame([], $payload['declared_updates']);
    }

    public static function emptyDeclarations(): array
    {
        return [
            'empty object' => [(object) []],
            'empty array' => [[]],
            'all explicit lists' => [array_fill_keys([
                'queries', 'query_contracts', 'signals', 'signal_contracts',
                'updates', 'update_contracts', 'update_validators',
            ], [])],
        ];
    }

    #[DataProvider('numericStringDeclarations')]
    public function test_numeric_string_workflow_types_retain_their_declarations(string $type, array $contract): void
    {
        $this->registerDeclaration([
            'supported_workflow_types' => [$type],
            'workflow_definition_fingerprints' => [$type => 'numeric-name-fingerprint'],
            'workflow_command_contracts' => [$type => $contract],
        ]);

        $observation = $this->startAndObserve('numeric-name-original', $type);
        $this->assertSame('durable_history', $observation['command_contract']['source']);
        $this->assertFalse($observation['command_contract']['backfill_needed']);
        $this->assertSame($contract['queries'] ?? [], $observation['command_contract']['queries']);
        $this->assertSame('numeric-name-fingerprint', WorkerRegistration::query()->sole()->workflow_definition_fingerprints[$type]);
    }

    public static function numericStringDeclarations(): array
    {
        return [
            'zero empty' => ['0', []],
            'zero named' => ['0', ['queries' => ['current'], 'query_contracts' => [['name' => 'current', 'parameters' => []]]]],
            'integer empty' => ['17', []],
            'integer named' => ['17', ['queries' => ['current'], 'query_contracts' => [['name' => 'current', 'parameters' => []]]]],
            'leading zero empty' => ['017', []],
            'leading zero named' => ['017', ['queries' => ['current'], 'query_contracts' => [['name' => 'current', 'parameters' => []]]]],
        ];
    }

    #[DataProvider('numericStringWorkflowTypes')]
    public function test_numeric_string_keys_do_not_bypass_contract_validation(string $type): void
    {
        $this->postJson('/api/worker/register', $this->registrationPayload([
            'supported_workflow_types' => [$type],
            'workflow_command_contracts' => [$type => null],
        ]), $this->workerHeaders())->assertUnprocessable();
        $this->assertSame(0, WorkerRegistration::query()->count());
    }

    public static function numericStringWorkflowTypes(): array
    {
        return ['zero' => ['0'], 'integer' => ['17']];
    }

    #[DataProvider('missingDeclarations')]
    public function test_missing_legacy_declaration_stays_unavailable(array $extra): void
    {
        $this->registerDeclaration($extra);
        $observation = $this->startAndObserve('original-legacy');
        $this->assertSame('unavailable', $observation['command_contract']['source']);
        $this->assertTrue($observation['command_contract']['backfill_needed']);
        $this->assertArrayNotHasKey('declared_signals', $this->startedPayload($observation['run_id']));
    }

    public static function missingDeclarations(): array
    {
        return [
            'omitted map' => [[]],
            'null map' => [['workflow_command_contracts' => null]],
            'empty map' => [['workflow_command_contracts' => []]],
            'different workflow entry' => [['workflow_command_contracts' => ['other.workflow' => []]]],
        ];
    }

    public function test_reregistration_changes_only_new_admission_snapshots(): void
    {
        $this->registerDeclaration(['workflow_command_contracts' => [self::TYPE => []]]);
        $original = $this->startAndObserve('original-empty');
        $before = $this->startedPayload($original['run_id']);
        $this->registerDeclaration(['workflow_command_contracts' => [self::TYPE => [
            'queries' => ['current'],
            'query_contracts' => [['name' => 'current', 'parameters' => []]],
        ]]]);
        $replacement = $this->startAndObserve('later-named');
        $this->assertSame('durable_history', $replacement['command_contract']['source']);
        $this->assertSame(['current'], $replacement['command_contract']['queries']);
        $this->assertSame($before, $this->startedPayload($original['run_id']));
        $this->getJson('/api/workflows/original-empty/observation', $this->apiHeaders())
            ->assertOk()->assertJsonPath('command_contract.source', 'durable_history')
            ->assertJsonPath('command_contract.queries', []);
    }

    #[DataProvider('mismatchedRegistrations')]
    public function test_empty_declaration_requires_the_selected_worker_scope(array $extra, string $namespace): void
    {
        WorkerBuildIdRollout::query()->create([
            'namespace' => 'default', 'task_queue' => self::QUEUE, 'build_id' => 'selected',
            'drain_intent' => WorkerBuildIdRollout::DRAIN_INTENT_ACTIVE, 'promoted_at' => now(),
        ]);
        $this->registerDeclaration(array_replace([
            'build_id' => 'selected', 'workflow_command_contracts' => [self::TYPE => []],
        ], $extra), $namespace);
        $observation = $this->startAndObserve('scope-control');
        $this->assertSame('unavailable', $observation['command_contract']['source']);
        $this->assertArrayNotHasKey('declared_signals', $this->startedPayload($observation['run_id']));
    }

    public static function mismatchedRegistrations(): array
    {
        return [
            'namespace' => [[], 'other'],
            'queue' => [['task_queue' => 'other'], 'default'],
            'type' => [['supported_workflow_types' => ['other.workflow']], 'default'],
            'build' => [['build_id' => 'other'], 'default'],
        ];
    }

    #[DataProvider('malformedDeclarations')]
    public function test_malformed_contract_cannot_register_as_authoritative_empty(mixed $contract): void
    {
        $this->postJson('/api/worker/register', $this->registrationPayload([
            'workflow_command_contracts' => [self::TYPE => $contract],
        ]), $this->workerHeaders())->assertUnprocessable();
        $this->assertSame(0, WorkerRegistration::query()->count());
    }

    public static function malformedDeclarations(): array
    {
        return [
            'null entry' => [null], 'string entry' => ['invalid'],
            'null query list' => [['queries' => null]],
            'string query list' => [['queries' => 'current']],
            'numeric query name' => [['queries' => [17]]],
            'blank signal name' => [['signals' => [' ']]],
            'associative updates' => [['updates' => ['unexpected' => 'set']]],
            'null validators' => [['update_validators' => null]],
            'string handler contracts' => [['query_contracts' => 'invalid']],
            'null handler' => [['signal_contracts' => [null]]],
            'missing handler name' => [['query_contracts' => [[]]]],
            'invalid handler name' => [['update_contracts' => [['name' => 17]]]],
        ];
    }

    private function registrationPayload(array $extra): array
    {
        return array_replace([
            'worker_id' => 'contract-worker', 'runtime' => 'python', 'task_queue' => self::QUEUE,
            'supported_workflow_types' => [self::TYPE], 'capabilities' => ['workflow_tasks'],
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
        ], $extra);
    }

    private function registerDeclaration(array $extra, string $namespace = 'default'): void
    {
        $this->postJson('/api/worker/register', $this->registrationPayload($extra),
            $this->workerHeaders($namespace))->assertCreated();
    }

    private function startAndObserve(string $workflowId, string $type = self::TYPE): array
    {
        $start = $this->postJson('/api/workflows', [
            'workflow_id' => $workflowId, 'workflow_type' => $type, 'task_queue' => self::QUEUE,
        ], $this->apiHeaders())->assertCreated();

        return $this->getJson('/api/workflows/'.$workflowId.'/runs/'.$start->json('run_id').'/observation',
            $this->apiHeaders())->assertOk()->json();
    }

    private function startedPayload(string $runId): array
    {
        return WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::WorkflowStarted->value)->sole()->payload;
    }
}
