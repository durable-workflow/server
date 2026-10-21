<?php

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use App\Support\WorkflowObservability;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\InteractiveCommandWorkflow;
use Tests\TestCase;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\WorkflowExecutor;

class WorkflowObservabilityAdmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'server.observability_context.enabled' => true,
            'server.observability_context.baggage_allowlist' => [],
            'workflows.v2.types.workflows' => ['tests.interactive-command-workflow' => InteractiveCommandWorkflow::class],
        ]);
        if (! WorkflowObservability::enabled()) {
            if (getenv('DW_TEST_OBSERVABILITY_CONTEXT_REQUIRED') === '1') {
                $this->fail('This qualification requires the tracing Core source and official optional API.');
            }
            $this->markTestSkipped('Requires the optional tracing Core/API dependency profile.');
        }
        Queue::fake();
        WorkflowNamespace::query()->create(['name' => 'default', 'description' => 'Default', 'status' => 'active', 'retention_days' => 30]);
    }

    public function test_new_admission_persists_bounded_origin_outside_business_history(): void
    {
        $start = $this->start('origin', $this->carrier());
        $run = WorkflowRun::query()->findOrFail($start->json('run_id'));
        $expected = $this->carrier();
        unset($expected['carrier']['baggage']);
        $this->assertSame($expected, $run->observability_context);
        $this->assertSame($expected, WorkflowCommand::query()->sole()->observability_context);
        $history = json_encode(WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->get()->toArray(), JSON_THROW_ON_ERROR);
        foreach (['traceparent', 'private-value', 'observability_context'] as $excluded) {
            $this->assertStringNotContainsString($excluded, $history);
        }
    }

    public function test_discovery_exposes_the_exact_optional_capability_without_a_protocol_floor(): void
    {
        config(['server.worker_protocol.version' => '1.1']);
        $this->getJson('/api/cluster/info')->assertOk()
            ->assertJsonPath('worker_protocol.version', '1.1')
            ->assertJsonPath('worker_protocol.server_capabilities.observability_context', [
                'supported' => true, 'schema' => 'durable-workflow.trace-context', 'version' => 1,
                'dispatch_schema' => 'durable-workflow.queue-trace', 'links_schema' => 'durable-workflow.trace-links',
                'max_context_bytes' => 4096, 'max_links' => 32, 'max_links_bytes' => 4096,
            ]);
        config(['server.observability_context.enabled' => false]);
        $this->getJson('/api/cluster/info')->assertOk()
            ->assertJsonMissingPath('worker_protocol.server_capabilities.observability_context');
    }

    public static function carrierModels(): array
    {
        return ['runs' => [WorkflowRun::class], 'commands' => [WorkflowCommand::class], 'tasks' => [WorkflowTask::class]];
    }

    #[DataProvider('carrierModels')]
    public function test_missing_actual_column_is_not_advertised_and_business_admission_survives(string $class): void
    {
        $model = new $class;
        $model->getConnection()->getSchemaBuilder()->table($model->getTable(), static function (Blueprint $table): void {
            $table->dropColumn('observability_context');
        });
        $this->getJson('/api/cluster/info')->assertOk()
            ->assertJsonMissingPath('worker_protocol.server_capabilities.observability_context');
        $this->start('legacy-schema', $this->carrier())->assertCreated();
        $this->assertSame(1, WorkflowRun::query()->count());
    }

    public function test_default_empty_and_explicit_allowlist_control_baggage(): void
    {
        config(['server.observability_context.baggage_allowlist' => ['approved']]);
        $start = $this->start('allowlist', $this->carrier());
        $carrier = WorkflowRun::query()->findOrFail($start->json('run_id'))->observability_context;
        $this->assertSame('approved=tenant-a', $carrier['carrier']['baggage']);
        $this->assertStringNotContainsString('private-value', json_encode($carrier, JSON_THROW_ON_ERROR));
    }

    #[DataProvider('environmentBaggagePolicies')]
    public function test_environment_baggage_policy_requires_a_json_list(string $json, ?string $expectedBaggage): void
    {
        $key = 'DW_OBSERVABILITY_BAGGAGE_ALLOWLIST';
        $original = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
        try {
            putenv($key.'='.$json);
            $_ENV[$key] = $json;
            $_SERVER[$key] = $json;
            $settings = require config_path('server.php');
            config(['server.observability_context.baggage_allowlist' => $settings['observability_context']['baggage_allowlist']]);
            $start = $this->start('environment-baggage', $this->carrier());
            $carrier = WorkflowRun::query()->findOrFail($start->json('run_id'))->observability_context;
            $this->assertSame($expectedBaggage, $carrier['carrier']['baggage'] ?? null);
        } finally {
            $original[0] === false ? putenv($key) : putenv($key.'='.$original[0]);
            if ($original[1] === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $original[1];
            }
            if ($original[2] === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $original[2];
            }
        }
    }

    public static function environmentBaggagePolicies(): array
    {
        return [
            'explicit list' => ['["approved"]', 'approved=tenant-a'],
            'mixed list' => ['["approved", {"key":"private"}, 42]', 'approved=tenant-a'],
            'empty list' => ['[]', null],
            'numeric-key object' => ['{"0":"approved"}', null],
            'named-key object' => ['{"approved":true}', null],
            'malformed JSON' => ['[', null],
            'scalar JSON' => ['"approved"', null],
        ];
    }

    public function test_retained_or_conflicting_start_never_replaces_an_origin(): void
    {
        $start = $this->start('retained', $this->carrier());
        $run = WorkflowRun::query()->findOrFail($start->json('run_id'));
        $original = $run->getRawOriginal();
        $different = $this->carrier('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $this->withHeaders($this->headers())->postJson('/api/workflows', [
            'workflow_id' => 'retained', 'workflow_type' => 'tests.interactive-command-workflow',
            'duplicate_policy' => 'use-existing', 'observability_context' => $different,
        ])->assertOk()->assertJsonPath('run_id', $run->id);
        $this->withHeaders($this->headers())->postJson('/api/workflows', [
            'workflow_id' => 'retained', 'workflow_type' => 'tests.interactive-command-workflow',
            'observability_context' => $different,
        ])->assertConflict();
        $this->assertSame($original['observability_context'], $run->fresh()->getRawOriginal('observability_context'));
        $this->assertSame(1, WorkflowRun::query()->count());
        $this->assertSame(1, WorkflowTask::query()->count());
        $this->assertSame(1, WorkflowCommand::query()->whereNotNull('observability_context')->count());
    }

    public function test_bad_or_absent_metadata_does_not_borrow_the_active_http_parent(): void
    {
        $scope = TraceContextPropagator::getInstance()->extract([
            'traceparent' => '00-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-bbbbbbbbbbbbbbbb-01',
        ])->activate();
        try {
            foreach ([null, 'malformed', ['schema' => 'unknown'], $this->carrier(str_repeat('0', 32))] as $index => $value) {
                $start = $this->start('bad-'.$index, $value);
                $this->assertNull(WorkflowRun::query()->findOrFail($start->json('run_id'))->observability_context);
            }
        } finally {
            $scope->detach();
        }
    }

    public function test_admitted_signal_links_its_caller_without_replacing_the_run_parent(): void
    {
        $start = $this->start('signal', $this->carrier());
        $run = WorkflowRun::query()->findOrFail($start->json('run_id'));
        $original = $run->observability_context;
        $task = WorkflowTask::query()->where('workflow_run_id', $run->id)->sole();
        (new RunWorkflowTask($task->id))->handle(app(WorkflowExecutor::class));
        $caller = $this->carrier('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $this->withHeaders($this->headers())->postJson('/api/workflows/signal/signal/advance', [
            'input' => ['Ada'], 'request_id' => 'real-signal', 'observability_context' => $caller,
        ])->assertAccepted();
        unset($caller['carrier']['baggage']);
        $command = WorkflowCommand::query()->where('command_type', 'signal')->sole();
        $this->assertSame($caller, $command->observability_context);
        $this->assertSame($original, $run->fresh()->observability_context);
    }

    public function test_failed_authority_and_rejected_business_input_do_not_persist_carriers(): void
    {
        config(['server.auth.driver' => 'token', 'server.auth.token' => 'valid-token']);
        $this->withHeaders($this->headers() + ['Authorization' => 'Bearer wrong-token'])
            ->postJson('/api/workflows', ['workflow_id' => 'unauthorized',
                'workflow_type' => 'tests.interactive-command-workflow', 'observability_context' => $this->carrier()])
            ->assertUnauthorized();
        $this->assertSame(0, WorkflowRun::query()->count());
        $this->assertSame(0, WorkflowCommand::query()->count());
        config(['server.auth.driver' => 'none']);
        $this->withHeaders($this->headers())->postJson('/api/workflows', [
            'workflow_type' => 'tests.interactive-command-workflow', 'execution_timeout_seconds' => -1,
            'observability_context' => $this->carrier(),
        ])->assertUnprocessable();
        $this->assertSame(0, WorkflowRun::query()->count());
        $this->assertSame(0, WorkflowCommand::query()->count());
    }

    private function start(string $id, mixed $carrier): TestResponse
    {
        return $this->withHeaders($this->headers())->postJson('/api/workflows', [
            'workflow_id' => $id, 'workflow_type' => 'tests.interactive-command-workflow',
            'observability_context' => $carrier,
        ])->assertCreated();
    }

    private function carrier(string $traceId = '4bf92f3577b34da6a3ce929d0e0e4736'): array
    {
        return ['schema' => 'durable-workflow.trace-context', 'version' => 1, 'carrier' => [
            'traceparent' => '00-'.$traceId.'-00f067aa0ba902b7-01', 'tracestate' => 'dwtest=one',
            'baggage' => 'approved=tenant-a,private=private-value',
        ]];
    }

    private function headers(): array
    {
        return ['X-Namespace' => 'default', 'X-Durable-Workflow-Control-Plane-Version' => '2'];
    }
}
