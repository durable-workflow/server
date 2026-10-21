<?php

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use App\Support\DispatchObservability;
use App\Support\WorkflowObservability;
use App\Support\WorkflowObservabilityPoll;
use App\Support\WorkflowQueryTaskBroker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\ExternalGreetingActivity;
use Tests\Fixtures\InteractiveCommandWorkflow;
use Tests\Fixtures\InternalChildWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\WorkflowDefinition;

class WorkflowObservabilityPollTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    private QueueManager $actualQueue;

    public function createApplication()
    {
        if ($this->name() !== 'test_real_database_queue_producer_is_a_bound_link_without_replacing_origin') {
            return parent::createApplication();
        }
        $savedEnv = $_ENV;
        $savedServer = $_SERVER;
        $_ENV['DW_MODE'] = $_SERVER['DW_MODE'] = 'service';
        $_ENV['DW_TASK_DISPATCH_MODE'] = $_SERVER['DW_TASK_DISPATCH_MODE'] = 'queue';

        try {
            return parent::createApplication();
        } finally {
            $_ENV = $savedEnv;
            $_SERVER = $savedServer;
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['server.observability_context.enabled' => true, 'server.observability_context.baggage_allowlist' => []]);
        if (! WorkflowObservability::enabled()) {
            if (getenv('DW_TEST_OBSERVABILITY_CONTEXT_REQUIRED') === '1') {
                $this->fail('This qualification requires the tracing Core source and optional API.');
            }
            $this->markTestSkipped('Requires the optional tracing Core/API dependency profile.');
        }
        $this->actualQueue = app('queue');
        Queue::fake();
        $this->createNamespace('default');
        $this->configureWorkflowTypes(['tests.interactive-command-workflow' => InteractiveCommandWorkflow::class]);
        $this->registerWorker('trace-worker', 'default', supportedWorkflowTypes: ['tests.interactive-command-workflow']);
    }

    public function test_real_authorized_workflow_poll_delivers_the_committed_origin(): void
    {
        $runId = $this->start();
        $wire = $this->poll();
        $this->assertSame($runId, $wire['run_id']);
        $this->assertSame('default', $wire['namespace']);
        $this->assertSame($this->carrier(), $wire['observability_context']);
        $this->assertSame('trace-worker', $wire['lease_owner']);
        $this->assertSame(1, $wire['workflow_task_attempt']);
        $this->assertArrayNotHasKey('dispatch_observability_context', $wire);
        $this->assertArrayNotHasKey('observability_links', $wire);
    }

    public function test_current_signal_resume_links_the_real_admitted_command(): void
    {
        $runId = $this->start();
        $this->runReadyWorkflowTask($runId);
        $caller = $this->carrier('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $this->withHeaders($this->apiHeaders())->postJson('/api/workflows/trace-workflow/signal/advance', [
            'input' => ['Ada'], 'request_id' => 'signal-link', 'observability_context' => $caller,
        ])->assertAccepted();
        $command = WorkflowCommand::query()->where('command_type', 'signal')->sole();
        $wire = $this->poll();
        $this->assertSame($this->carrier(), $wire['observability_context']);
        $this->assertSame(['schema' => 'durable-workflow.trace-links', 'version' => 1,
            'task_id' => $wire['task_id'], 'run_id' => $runId, 'namespace' => 'default',
            'links' => [['kind' => 'command', 'command_id' => $command->id, 'context' => $caller]],
        ], $wire['observability_links']);
    }

    public function test_real_database_queue_producer_is_a_bound_link_without_replacing_origin(): void
    {
        Queue::swap($this->actualQueue);
        $this->assertSame('queue', config('workflows.v2.task_dispatch_mode'));
        config(['workflows.tracing.enabled' => true]);
        $exporter = new InMemoryExporter;
        $provider = new TracerProvider([new SimpleSpanProcessor($exporter)]);
        $configuration = Configurator::create()->withTracerProvider($provider)
            ->withPropagator(TraceContextPropagator::getInstance())->activate();
        try {
            $runId = $this->start();
            $task = WorkflowTask::query()->where('workflow_run_id', $runId)->sole();
            $this->assertIsString($task->getRawOriginal('queue_delivery_reference'), json_encode([
                'mode' => config('workflows.v2.task_dispatch_mode'), 'transaction_level' => DB::transactionLevel(),
                'queue_manager' => get_debug_type(app('queue')), 'jobs' => DB::table('jobs')->count(),
                'dispatched' => $task->getRawOriginal('last_dispatched_at'),
                'dispatch_failed' => $task->getRawOriginal('last_dispatch_error') !== null,
            ], JSON_THROW_ON_ERROR));
            $job = DB::table('jobs')->sole();
            $payload = json_decode($job->payload, true, flags: JSON_THROW_ON_ERROR);
            $this->assertIsString($payload['data']['command']);
            $producer = $payload['dispatch_observability_context'];
            $spans = array_values(array_filter($exporter->getSpans(), static fn ($span): bool => $span->getName() === 'durable.task.dispatch'));
            $this->assertCount(1, $spans);
            $span = $spans[0]->getContext();
            $this->assertSame('00-'.$span->getTraceId().'-'.$span->getSpanId().'-01', $producer['context']['carrier']['traceparent']);
            $this->assertArrayNotHasKey('baggage', $producer['context']['carrier']);
            $wire = $this->poll();
            $this->assertSame($this->carrier(), $wire['observability_context']);
            $this->assertSame($producer, $wire['dispatch_observability_context']);
            $this->assertSame($wire['task_id'], $producer['task_id']);
            $before = $task->fresh()->getRawOriginal();
            $reference = $task->getAttribute('queue_delivery_reference');
            $reference['payload_uuid'] = 'wrong-row-identity';
            $changed = $task->fresh();
            $changed->setRawAttributes(array_replace($changed->getRawOriginal(), [
                'queue_delivery_reference' => json_encode($reference, JSON_THROW_ON_ERROR),
            ]), true);
            $this->assertNull(DispatchObservability::forTask($changed));
            $this->assertSame($before, $task->fresh()->getRawOriginal());
        } finally {
            $configuration->detach();
            $provider->shutdown();
        }
    }

    public function test_real_activity_poll_delivers_its_run_origin_and_original_attempt(): void
    {
        $runId = $this->start();
        $workflow = $this->poll();
        $this->withHeaders($this->workerHeaders())->postJson('/api/worker/workflow-tasks/'.$workflow['task_id'].'/complete', [
            'lease_owner' => 'trace-worker', 'workflow_task_attempt' => $workflow['workflow_task_attempt'],
            'commands' => [['type' => 'schedule_activity', 'activity_type' => 'tests.external-greeting-activity']],
        ])->assertOk()->assertJsonPath('recorded', true);
        $wire = $this->withHeaders($this->workerHeaders())->postJson('/api/worker/activity-tasks/poll', [
            'worker_id' => 'trace-worker', 'task_queue' => 'default', 'timeout_seconds' => 0,
        ])->assertOk()->json('task');
        $this->assertIsArray($wire);
        $this->assertSame($runId, $wire['run_id']);
        $this->assertSame('default', $wire['namespace']);
        $this->assertSame($this->carrier(), $wire['observability_context']);
        $this->assertSame(1, $wire['attempt_number']);
        $this->assertNotEmpty($wire['activity_attempt_id']);
    }

    public function test_committed_activity_outcome_links_its_real_producer_and_event(): void
    {
        config(['workflows.tracing.enabled' => true,
            'workflows.v2.types.activities' => ['tests.external-greeting-activity' => ExternalGreetingActivity::class]]);
        $exporter = new InMemoryExporter;
        $provider = new TracerProvider([new SimpleSpanProcessor($exporter)]);
        $configuration = Configurator::create()->withTracerProvider($provider)
            ->withPropagator(TraceContextPropagator::getInstance())->activate();
        try {
            $runId = $this->start();
            $first = $this->poll();
            $this->withHeaders($this->workerHeaders())->postJson('/api/worker/workflow-tasks/'.$first['task_id'].'/complete', [
                'lease_owner' => 'trace-worker', 'workflow_task_attempt' => $first['workflow_task_attempt'],
                'commands' => [['type' => 'schedule_activity', 'activity_type' => 'tests.external-greeting-activity',
                    'arguments' => ['codec' => 'avro', 'blob' => Serializer::serializeWithCodec('avro', ['Ada'])]]],
            ])->assertOk()->assertJsonPath('recorded', true);
            $producer = WorkflowTask::query()->where('workflow_run_id', $runId)->where('task_type', 'activity')->sole();
            (new RunActivityTask($producer->id))->handle();
            $event = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->where('event_type', 'ActivityCompleted')->sole();
            $wire = $this->poll();
            $entry = $wire['observability_links']['links'][0];
            $this->assertSame('task_outcome', $entry['kind']);
            $this->assertSame($producer->id, $entry['task_id']);
            $this->assertSame($event->id, $entry['event_id']);
            $this->assertSame((int) $event->sequence, $entry['sequence']);
            $this->assertSame('ActivityCompleted', $entry['event_type']);
            $this->assertSame($event->payload['activity_execution_id'], $entry['activity_execution_id']);
            $this->assertSame($event->payload['activity_attempt_id'], $entry['activity_attempt_id']);
            $this->assertArrayNotHasKey('activity_id', $entry);
            $this->assertArrayNotHasKey('baggage', $entry['context']['carrier']);
            $spans = array_values(array_filter($exporter->getSpans(), static fn ($span): bool => $span->getName() === 'durable.activity.attempt'));
            $this->assertCount(1, $spans);
            $span = $spans[0]->getContext();
            $this->assertSame('00-'.$span->getTraceId().'-'.$span->getSpanId().'-01', $entry['context']['carrier']['traceparent']);
            $this->assertSame($this->carrier(), $wire['observability_context']);
            $this->assertStringNotContainsString('traceparent', json_encode($event->payload, JSON_THROW_ON_ERROR));
        } finally {
            $configuration->detach();
            $provider->shutdown();
        }
    }

    public function test_real_query_poll_delivers_origin_without_changing_the_original_deadline(): void
    {
        $runId = $this->start();
        $this->runReadyWorkflowTask($runId);
        $this->registerWorker('trace-query-worker', 'default', supportedWorkflowTypes: ['tests.interactive-command-workflow'], capabilities: ['query_tasks']);
        $run = WorkflowRun::query()->findOrFail($runId);
        $broker = app(WorkflowQueryTaskBroker::class);
        $query = $broker->enqueue('default', $run, 'events-starting-with', [
            'codec' => 'avro', 'blob' => Serializer::serializeWithCodec('avro', ['approved']),
        ]);
        $response = $this->withHeaders($this->workerHeaders())->postJson('/api/worker/query-tasks/poll', [
            'worker_id' => 'trace-query-worker', 'task_queue' => 'default', 'timeout_seconds' => 0,
        ])->assertOk();
        $wire = $response->json('task');
        $this->assertNull($wire, 'A worker without the recorded definition fingerprint must remain refused.');
        $definition = WorkflowDefinition::fingerprint(InteractiveCommandWorkflow::class);
        $this->assertIsString($definition);
        $this->assertSame($definition, $query['workflow_definition_fingerprint']);
        WorkerRegistration::query()->where('worker_id', 'trace-query-worker')->sole()->forceFill([
            'workflow_definition_fingerprints' => ['tests.interactive-command-workflow' => $definition],
        ])->save();
        $response = $this->withHeaders($this->workerHeaders())->postJson('/api/worker/query-tasks/poll', [
            'worker_id' => 'trace-query-worker', 'task_queue' => 'default', 'timeout_seconds' => 0,
        ])->assertOk();
        $wire = $response->json('task');
        $this->assertIsArray($wire, json_encode(['poll_status' => $response->json('poll_status'), 'reason' => $response->json('reason'),
            'query_status' => $broker->task($query['query_task_id'])['status'] ?? null,
            'query_build' => $query['compatibility'] ?? null,
            'query_definition' => $query['workflow_definition_fingerprint'] ?? null,
            'query_queue' => $query['task_queue'] ?? null], JSON_THROW_ON_ERROR));
        $this->assertSame($query['query_task_id'], $wire['query_task_id']);
        $this->assertSame($this->carrier(), $wire['observability_context']);
        $this->assertSame('default', $wire['namespace']);
        $this->assertSame(1, $wire['query_task_attempt']);
        $this->assertSame($query['query_admitted_at'], $wire['query_admitted_at']);
        $this->assertSame($query['query_deadline_at'], $wire['query_deadline_at']);
        $stored = $broker->task($query['query_task_id']);
        $this->assertSame($query['query_admitted_at'], $stored['query_admitted_at']);
        $this->assertSame($query['query_deadline_at'], $stored['query_deadline_at']);
    }

    public function test_child_outcome_uses_the_linked_childs_real_terminal_event_and_task(): void
    {
        config(['workflows.tracing.enabled' => true]);
        $this->configureWorkflowTypes(['tests.interactive-command-workflow' => InteractiveCommandWorkflow::class,
            'tests.internal-child-workflow' => InternalChildWorkflow::class]);
        $exporter = new InMemoryExporter;
        $provider = new TracerProvider([new SimpleSpanProcessor($exporter)]);
        $configuration = Configurator::create()->withTracerProvider($provider)
            ->withPropagator(TraceContextPropagator::getInstance())->activate();
        try {
            $runId = $this->start();
            $first = $this->poll();
            $this->withHeaders($this->workerHeaders())->postJson('/api/worker/workflow-tasks/'.$first['task_id'].'/complete', [
                'lease_owner' => 'trace-worker', 'workflow_task_attempt' => $first['workflow_task_attempt'],
                'commands' => [['type' => 'start_child_workflow', 'workflow_type' => 'tests.internal-child-workflow',
                    'arguments' => ['codec' => 'avro', 'blob' => Serializer::serializeWithCodec('avro', ['Ada'])]]],
            ])->assertOk()->assertJsonPath('recorded', true);
            $link = WorkflowLink::query()->where('parent_workflow_run_id', $runId)->sole();
            $this->runReadyWorkflowTask($link->child_workflow_run_id);
            $terminal = WorkflowHistoryEvent::query()->where('workflow_run_id', $link->child_workflow_run_id)
                ->where('event_type', 'WorkflowCompleted')->sole();
            $wire = $this->poll();
            $entry = $wire['observability_links']['links'][0];
            $this->assertSame('child_outcome', $entry['kind']);
            $this->assertSame($link->id, $entry['workflow_link_id']);
            $this->assertSame($link->child_workflow_run_id, $entry['child_run_id']);
            $this->assertSame($terminal->workflow_task_id, $entry['child_task_id']);
            $this->assertSame($terminal->id, $entry['event_id']);
            $this->assertSame((int) $terminal->sequence, $entry['sequence']);
            $this->assertSame('WorkflowCompleted', $entry['event_type']);
            $spans = array_values(array_filter($exporter->getSpans(), static fn ($span): bool => $span->getName() === 'durable.workflow.task'));
            $this->assertCount(1, $spans);
            $span = $spans[0]->getContext();
            $this->assertSame('00-'.$span->getTraceId().'-'.$span->getSpanId().'-01', $entry['context']['carrier']['traceparent']);
            $this->assertSame($this->carrier(), $wire['observability_context']);
            $this->assertArrayNotHasKey('baggage', $entry['context']['carrier']);
        } finally {
            $configuration->detach();
            $provider->shutdown();
        }
    }

    public function test_bad_original_claims_are_omitted_before_origin_access(): void
    {
        $runId = $this->start();
        $wire = $this->poll();
        $run = WorkflowRun::query()->findOrFail($runId);
        $observed = new ObservedOriginRun;
        $observed->setRawAttributes($run->getRawOriginal(), true);
        $task = WorkflowTask::query()->findOrFail($wire['task_id']);
        foreach ([['lease_owner' => 'wrong-owner'], ['workflow_task_attempt' => 0],
            ['workflow_task_attempt' => 2], ['run_id' => 'wrong-run'],
            ['lease_expires_at' => now()->subSecond()->toJSON()],
            ['lease_expires_at' => now()->addHour()->toJSON()]] as $change) {
            $this->assertSame([], WorkflowObservabilityPoll::forClaim($observed, $task, array_replace($wire, $change), 'workflow'));
        }
        $this->assertSame(0, $observed->originReads);
        $this->assertSame($wire['lease_owner'], $task->fresh()->lease_owner);
        $this->assertSame($wire['workflow_task_attempt'], $task->fresh()->attempt_count);
    }

    public function test_query_observation_requires_original_positive_generation_and_deadlines(): void
    {
        $runId = $this->start();
        $run = WorkflowRun::query()->findOrFail($runId);
        $observed = new ObservedOriginRun;
        $observed->setRawAttributes($run->getRawOriginal(), true);
        $claim = ['status' => 'leased', 'query_task_id' => 'real-query', 'namespace' => 'default',
            'workflow_id' => 'trace-workflow', 'run_id' => $runId, 'lease_owner' => 'trace-worker',
            'attempt_count' => 1, 'query_admitted_at' => now()->toJSON(),
            'query_deadline_at' => now()->addSeconds(30)->toJSON(), 'lease_expires_at' => now()->addSeconds(10)->toJSON()];
        foreach ([['attempt_count' => 0], ['attempt_count' => true], ['namespace' => 'other'],
            ['query_admitted_at' => '1970-01-01T00:00:00Z'],
            ['query_deadline_at' => now()->subSecond()->toJSON()],
            ['lease_expires_at' => now()->subSecond()->toJSON()]] as $change) {
            $this->assertSame([], WorkflowObservabilityPoll::forQuery($observed, array_replace($claim, $change)));
        }
        $this->assertSame(0, $observed->originReads);
        $this->assertSame($this->carrier(), WorkflowObservabilityPoll::forQuery($observed, $claim)['observability_context']);
    }

    public function test_cached_delivery_reapplies_current_baggage_and_disable_settings(): void
    {
        config(['server.observability_context.baggage_allowlist' => ['approved']]);
        $carrier = $this->carrier();
        $carrier['carrier']['baggage'] = 'approved=tenant-a';
        $this->start($carrier);
        $wire = $this->poll();
        $this->assertSame('approved=tenant-a', $wire['observability_context']['carrier']['baggage']);
        config(['server.observability_context.baggage_allowlist' => []]);
        $filtered = WorkflowObservabilityPoll::filterDelivered($wire);
        $this->assertArrayNotHasKey('baggage', $filtered['observability_context']['carrier']);
        config(['server.observability_context.enabled' => false]);
        $filtered = WorkflowObservabilityPoll::filterDelivered($wire);
        $this->assertArrayNotHasKey('observability_context', $filtered);
        foreach (['task_id', 'run_id', 'workflow_task_attempt', 'lease_owner', 'lease_expires_at', 'history_events'] as $field) {
            $this->assertSame($wire[$field], $filtered[$field]);
        }
    }

    public function test_malformed_links_only_set_a_bounded_disposition_on_the_existing_span(): void
    {
        $this->start();
        $wire = $this->poll();
        $exporter = new InMemoryExporter;
        $provider = new TracerProvider([new SimpleSpanProcessor($exporter)]);
        $span = $provider->getTracer('workflow773-server-test')->spanBuilder('existing-business-scope')->startSpan();
        $scope = $span->activate();
        try {
            $wire['observability_links'] = ['schema' => 'unknown', 'secret' => 'must-not-be-exported'];
            $filtered = WorkflowObservabilityPoll::filterDelivered($wire);
            $this->assertArrayNotHasKey('observability_links', $filtered);
            unset($wire['observability_links']);
            $this->assertSame($wire, $filtered);
        } finally {
            $scope->detach();
            $span->end();
            $provider->shutdown();
        }
        $this->assertCount(1, $exporter->getSpans());
        $attributes = $exporter->getSpans()[0]->getAttributes()->toArray();
        $this->assertSame(['durable.trace_context.disposition' => 'discarded'], $attributes);
    }

    private function start(?array $carrier = null): string
    {
        return $this->withHeaders($this->apiHeaders())->postJson('/api/workflows', [
            'workflow_id' => 'trace-workflow', 'workflow_type' => 'tests.interactive-command-workflow',
            'task_queue' => 'default', 'observability_context' => $carrier ?? $this->carrier(),
        ])->assertCreated()->json('run_id');
    }

    private function poll(): array
    {
        return $this->withHeaders($this->workerHeaders())->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'trace-worker', 'task_queue' => 'default', 'timeout_seconds' => 0,
        ])->assertOk()->json('task');
    }

    private function carrier(string $traceId = '4bf92f3577b34da6a3ce929d0e0e4736'): array
    {
        return ['schema' => 'durable-workflow.trace-context', 'version' => 1,
            'carrier' => ['traceparent' => '00-'.$traceId.'-00f067aa0ba902b7-01']];
    }
}

class ObservedOriginRun extends WorkflowRun
{
    public int $originReads = 0;

    public function getAttribute($key)
    {
        if ($key === 'observability_context') {
            $this->originReads++;
        }

        return parent::getAttribute($key);
    }
}
