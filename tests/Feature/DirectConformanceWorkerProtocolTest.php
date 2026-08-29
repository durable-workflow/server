<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\DirectConformanceWorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\Serializers\Avro;
use Workflow\Serializers\AvroBinaryValue;
use Workflow\Serializers\AvroMapValue;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

final class DirectConformanceWorkerProtocolTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->createNamespace('default');
    }

    public function test_current_direct_probe_completion_survives_cold_run_and_history_reads(): void
    {
        $workflowId = 'direct-conformance-avro';
        $workflowType = 'direct.conformance.avro';
        $taskQueue = 'direct-conformance';
        $workerId = 'direct-conformance-worker';
        $registration = DirectConformanceWorkerProtocol::registration(
            $workerId,
            $taskQueue,
            'php',
            'durable-workflow/server:published-artifact',
            [$workflowType],
            [],
        );

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', $registration)
            ->assertCreated()
            ->assertJsonPath('registered', true);

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => $workflowId,
                'workflow_type' => $workflowType,
                'task_queue' => $taskQueue,
                'input' => ['probe'],
            ])
            ->assertCreated();
        $runId = (string) $start->json('run_id');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => $workerId,
                'task_queue' => $taskQueue,
                'timeout_seconds' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('task.run_id', $runId);
        $task = $poll->json('task');
        $this->assertIsArray($task);

        $expected = AvroMapValue::fromPairs([
            ['double', 7.0],
            ['long', 7],
            ['binary', AvroBinaryValue::fromBytes("\x00\xFF")],
            ['text', 'AP8='],
            ['nested', AvroMapValue::fromPairs([
                ['z', 1],
                ['a', 2],
            ])],
        ]);
        $completion = DirectConformanceWorkerProtocol::workflowTaskCompletion($task, [[
            'type' => 'complete_workflow',
            'result' => Avro::envelope($expected),
        ]]);

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/'.$task['task_id'].'/complete', $completion)
            ->assertOk()
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('run_status', 'completed');

        $coldRun = WorkflowRun::query()->findOrFail($runId);
        $coldOutput = $coldRun->workflowOutput();
        $this->assertIsArray($coldOutput);
        $this->assertSame(['double', 'long', 'binary', 'text', 'nested'], array_keys($coldOutput));
        $this->assertIsFloat($coldOutput['double']);
        $this->assertIsInt($coldOutput['long']);
        $this->assertInstanceOf(AvroBinaryValue::class, $coldOutput['binary']);
        $this->assertSame("\x00\xFF", $coldOutput['binary']->bytes);
        $this->assertSame('AP8=', $coldOutput['text']);
        $this->assertSame(['z', 'a'], array_keys($coldOutput['nested']));

        $completed = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::WorkflowCompleted->value)
            ->firstOrFail();
        $historyFrame = $completed->payload['output'] ?? null;
        $this->assertIsString($historyFrame);
        $this->assertSame('avro', $completed->payload['payload_codec'] ?? null);
        $historyOutput = Serializer::unserializeWithCodec('avro', $historyFrame);
        $this->assertSame(array_keys($coldOutput), array_keys($historyOutput));
        $this->assertIsFloat($historyOutput['double']);
        $this->assertIsInt($historyOutput['long']);
        $this->assertInstanceOf(AvroBinaryValue::class, $historyOutput['binary']);
        $this->assertSame("\x00\xFF", $historyOutput['binary']->bytes);
        $this->assertSame('AP8=', $historyOutput['text']);
        $this->assertSame(['z', 'a'], array_keys($historyOutput['nested']));

        $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/{$workflowId}/runs/{$runId}")
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('output.binary.$type', 'bytes')
            ->assertJsonPath('output.binary.base64', 'AP8=')
            ->assertJsonPath('output.text', 'AP8=')
            ->assertJsonPath('output_envelope.codec', 'avro');
    }
}
