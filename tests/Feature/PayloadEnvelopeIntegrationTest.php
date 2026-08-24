<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Api\WorkerController;
use App\Models\RuntimeExternalPayload;
use App\Models\WorkerRegistration;
use App\Models\WorkflowNamespace;
use App\Support\ExternalPayloadEnvelopeService;
use App\Support\RuntimeExternalPayloadRegistry;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\Fixtures\InteractiveCommandWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Avro;
use Workflow\Serializers\AvroBinaryValue;
use Workflow\Serializers\AvroMapValue;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ExternalPayloads;
use Workflow\V2\Support\WorkflowCommandNormalizer;
use Workflow\V2\Support\WorkflowExecutor;

class PayloadEnvelopeIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private string $externalStorageDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->externalStorageDirectory = storage_path('framework/testing/payload-envelope-external-storage');
        File::deleteDirectory($this->externalStorageDirectory);

        config([
            'cache.default' => 'file',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->externalStorageDirectory);

        parent::tearDown();
    }

    public function test_signal_accepts_avro_envelope_input(): void
    {
        Queue::fake();
        $this->configureWorkflowTypes();
        $this->createNamespace('default');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-envelope-signal',
                'workflow_type' => 'tests.interactive-command-workflow',
            ])
            ->assertCreated();

        $runId = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-envelope-signal')
            ->json('run_id');

        $this->runReadyWorkflowTask($runId);

        $signal = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-envelope-signal/signal/advance', [
                'input' => $this->avroEnvelope(['EnvelopeUser']),
            ]);

        $signal->assertStatus(202)
            ->assertJsonPath('signal_name', 'advance');

        $this->runReadyWorkflowTask($runId);

        $query = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-envelope-signal/query/currentState');

        $query->assertOk()
            ->assertJsonPath('result.name', 'EnvelopeUser')
            ->assertJsonPath('result.stage', 'waiting-for-finish');
    }

    public function test_start_accepts_configured_external_storage_envelope_input(): void
    {
        Queue::fake();
        $this->configureWorkflowTypes();
        $this->createNamespace('default', [
            'driver' => 'local',
            'enabled' => true,
            'config' => [
                'uri' => 'file://'.$this->externalStorageDirectory,
            ],
        ]);

        $payload = Serializer::serializeWithCodec('avro', ['ExternalAda']);

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-external-storage-start',
                'workflow_type' => 'tests.external-greeting-workflow',
                'input' => $this->externalStorageEnvelope('avro', $payload),
            ]);

        $start->assertCreated()
            ->assertJsonPath('payload_codec', 'avro');

        $run = WorkflowRun::query()->findOrFail((string) $start->json('run_id'));

        $this->assertSame('avro', $run->payload_codec);
        $this->assertSame($payload, $run->arguments);
    }

    public function test_signal_accepts_configured_external_storage_envelope_input(): void
    {
        Queue::fake();
        $this->configureWorkflowTypes();
        $this->createNamespace('default', [
            'driver' => 'local',
            'enabled' => true,
            'config' => [
                'uri' => 'file://'.$this->externalStorageDirectory,
            ],
        ]);

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-external-storage-signal',
                'workflow_type' => 'tests.interactive-command-workflow',
            ])
            ->assertCreated();

        $runId = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-external-storage-signal')
            ->json('run_id');

        $this->runReadyWorkflowTask($runId);

        $payload = Serializer::serializeWithCodec('avro', ['ExternalSignal']);
        $signal = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-external-storage-signal/signal/advance', [
                'input' => $this->externalStorageEnvelope('avro', $payload),
            ]);

        $signal->assertStatus(202)
            ->assertJsonPath('signal_name', 'advance');

        $recordedSignal = WorkflowSignal::query()
            ->where('workflow_run_id', $runId)
            ->where('signal_name', 'advance')
            ->firstOrFail();

        $this->assertSame('avro', $recordedSignal->payload_codec);
        $this->assertSame($payload, $recordedSignal->arguments);

        $this->runReadyWorkflowTask($runId);

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-external-storage-signal/query/currentState')
            ->assertOk()
            ->assertJsonPath('result.name', 'ExternalSignal')
            ->assertJsonPath('result.stage', 'waiting-for-finish');
    }

    public function test_signal_dry_run_preview_does_not_write_external_payload_state(): void
    {
        Queue::fake();
        $this->configureWorkflowTypes();
        $this->createNamespace('default', [
            'driver' => 'local',
            'enabled' => true,
            'threshold_bytes' => 32,
            'config' => [
                'uri' => 'file://'.$this->externalStorageDirectory,
            ],
        ]);

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-external-storage-signal-preview',
                'workflow_type' => 'tests.interactive-command-workflow',
            ])
            ->assertCreated();

        $runId = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-external-storage-signal-preview')
            ->json('run_id');

        $this->runReadyWorkflowTask($runId);

        $payloadFiles = $this->externalStorageFilePaths();
        $signalCommandCount = WorkflowCommand::query()
            ->where('workflow_instance_id', 'wf-external-storage-signal-preview')
            ->where('command_type', 'signal')
            ->count();
        $signalHistoryCount = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::SignalReceived->value)
            ->count();
        $signalRecordCount = WorkflowSignal::query()
            ->where('workflow_run_id', $runId)
            ->count();

        $preview = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-external-storage-signal-preview/signal/advance', [
                'input' => [str_repeat('S', 2048)],
                'dry_run' => true,
            ]);

        $preview->assertOk()
            ->assertJsonPath('workflow_id', 'wf-external-storage-signal-preview')
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('signal_name', 'advance')
            ->assertJsonPath('outcome', 'signal_preview')
            ->assertJsonPath('command_status', 'preview')
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('preview.would_record_signal', true);

        $this->assertSame($payloadFiles, $this->externalStorageFilePaths());
        $this->assertSame(
            $signalCommandCount,
            WorkflowCommand::query()
                ->where('workflow_instance_id', 'wf-external-storage-signal-preview')
                ->where('command_type', 'signal')
                ->count(),
        );
        $this->assertSame(
            $signalHistoryCount,
            WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $runId)
                ->where('event_type', HistoryEventType::SignalReceived->value)
                ->count(),
        );
        $this->assertSame(
            $signalRecordCount,
            WorkflowSignal::query()
                ->where('workflow_run_id', $runId)
                ->count(),
        );
    }

    public function test_signal_rejects_undecodable_envelope(): void
    {
        Queue::fake();
        $this->configureWorkflowTypes();
        $this->createNamespace('default');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-envelope-signal-reject',
                'workflow_type' => 'tests.interactive-command-workflow',
            ])
            ->assertCreated();

        $runId = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-envelope-signal-reject')
            ->json('run_id');

        $this->runReadyWorkflowTask($runId);

        $signal = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-envelope-signal-reject/signal/advance', [
                'input' => [
                    'codec' => 'workflow-serializer-y',
                    'blob' => 'php-serialized-data',
                ],
            ]);

        $signal->assertStatus(422);
    }

    public function test_query_accepts_avro_envelope_input(): void
    {
        Queue::fake();
        $this->configureWorkflowTypes();
        $this->createNamespace('default');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-envelope-query',
                'workflow_type' => 'tests.interactive-command-workflow',
            ])
            ->assertCreated();

        $runId = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-envelope-query')
            ->json('run_id');

        $this->runReadyWorkflowTask($runId);

        $query = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-envelope-query/query/events-starting-with', [
                'input' => $this->avroEnvelope(['start']),
            ]);

        $query->assertOk()
            ->assertJsonPath('result', 1);
    }

    public function test_update_accepts_avro_envelope_input(): void
    {
        Queue::fake();
        $this->configureWorkflowTypes();
        $this->createNamespace('default');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-envelope-update',
                'workflow_type' => 'tests.interactive-command-workflow',
            ])
            ->assertCreated();

        $runId = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-envelope-update')
            ->json('run_id');

        $this->runReadyWorkflowTask($runId);

        $update = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-envelope-update/update/approve', [
                'input' => $this->avroEnvelope([true, 'envelope-api']),
                'wait_for' => 'completed',
            ]);

        $update->assertOk()
            ->assertJsonPath('update_name', 'approve')
            ->assertJsonPath('result.approved', true);

        $this->assertContains('approved:yes:envelope-api', (array) $update->json('result.events'));
    }

    public function test_complete_workflow_projects_its_result_codec_and_rejects_stale_null_completion(): void
    {
        Queue::fake();
        $this->configureWorkflowTypes();
        $this->createNamespace('default');

        $workflowId = 'wf-envelope-complete';
        $expected = ['greeting' => 'Hello Ada'];

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => $workflowId,
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'ext-q',
                'input' => [
                    'codec' => 'avro',
                    'blob' => Serializer::serializeWithCodec('avro', ['Ada']),
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('payload_codec', 'avro');

        $this->registerWorker('worker-1', 'ext-q');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'worker-1',
                'task_queue' => 'ext-q',
            ]);

        $poll->assertOk();
        $taskId = $poll->json('task.task_id');
        $attempt = $poll->json('task.workflow_task_attempt');

        $complete = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => 'worker-1',
                'workflow_task_attempt' => $attempt,
                'commands' => [
                    [
                        'type' => 'complete_workflow',
                        'result' => $this->avroEnvelope($expected),
                    ],
                ],
            ]);

        $complete->assertOk()
            ->assertJsonPath('outcome', 'completed');

        $runId = (string) $poll->json('task.run_id');
        $projectedRun = WorkflowRun::query()->findOrFail($runId);
        $this->assertSame('avro', $projectedRun->output_payload_codec);

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $projectedEnvelope = $projectedRun->outputEnvelope();
            $projectedOutput = $projectedRun->workflowOutput();
        } finally {
            $terminalReadQueries = DB::getQueryLog();
            DB::disableQueryLog();
        }

        $this->assertSame('avro', $projectedEnvelope['codec'] ?? null);
        $this->assertSame($expected, $projectedOutput);
        $this->assertSame([], array_values(array_filter(
            $terminalReadQueries,
            static fn (array $query): bool => str_contains($query['query'], 'workflow_history_events'),
        )), 'Terminal output reads must not query completion history.');

        $current = $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/{$workflowId}");
        $selected = $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/{$workflowId}/runs/{$runId}");

        foreach ([$current, $selected] as $result) {
            $result->assertOk()
                ->assertJsonPath('payload_codec', 'avro')
                ->assertJsonPath('output', $expected)
                ->assertJsonPath('output_envelope.codec', 'avro');

            $this->assertSame(
                $expected,
                Serializer::unserializeWithCodec(
                    'avro',
                    (string) $result->json('output_envelope.blob'),
                ),
            );
        }

        $completionEvents = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::WorkflowCompleted->value)
            ->get();

        $this->assertCount(1, $completionEvents);
        $completionEvent = $completionEvents->first();
        $this->assertInstanceOf(WorkflowHistoryEvent::class, $completionEvent);
        $completionPayload = $completionEvent->payload;
        $this->assertIsArray($completionPayload);
        $this->assertSame('avro', $completionPayload['payload_codec'] ?? null);
        $this->assertSame(
            $expected,
            Serializer::unserializeWithCodec(
                'avro',
                (string) ($completionPayload['output'] ?? ''),
            ),
        );

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => 'worker-1',
                'workflow_task_attempt' => $attempt,
                'commands' => [
                    [
                        'type' => 'complete_workflow',
                        'result' => $this->avroEnvelope(null),
                    ],
                ],
            ])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'run_closed');

        $this->assertSame(1, WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::WorkflowCompleted->value)
            ->count());

        $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/{$workflowId}")
            ->assertOk()
            ->assertJsonPath('output', $expected);

        $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/{$workflowId}/runs/{$runId}")
            ->assertOk()
            ->assertJsonPath('output', $expected);
    }

    public function test_schedule_activity_accepts_envelope_arguments(): void
    {
        Queue::fake();
        $this->configureWorkflowTypes();
        $this->createNamespace('default');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-envelope-activity-args',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'ext-q',
                'input' => ['Ada'],
            ])
            ->assertCreated();

        $this->registerWorker('worker-1', 'ext-q');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'worker-1',
                'task_queue' => 'ext-q',
            ]);

        $poll->assertOk();
        $taskId = $poll->json('task.task_id');
        $attempt = $poll->json('task.workflow_task_attempt');

        $complete = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => 'worker-1',
                'workflow_task_attempt' => $attempt,
                'commands' => [
                    [
                        'type' => 'schedule_activity',
                        'activity_type' => 'tests.greeting-activity',
                        'arguments' => $this->avroEnvelope(['Hello', 'Ada']),
                    ],
                ],
            ]);

        $complete->assertOk()
            ->assertJsonPath('outcome', 'completed');
    }

    public function test_activity_complete_accepts_envelope_result(): void
    {
        Queue::fake();
        $this->configureWorkflowTypes();
        $this->createNamespace('default');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-envelope-activity-result',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'ext-q',
                'input' => ['Ada'],
            ])
            ->assertCreated();

        $this->registerWorker('worker-1', 'ext-q');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'worker-1',
                'task_queue' => 'ext-q',
            ]);

        $taskId = $poll->json('task.task_id');
        $attempt = $poll->json('task.workflow_task_attempt');

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => 'worker-1',
                'workflow_task_attempt' => $attempt,
                'commands' => [
                    [
                        'type' => 'schedule_activity',
                        'activity_type' => 'tests.greeting-activity',
                        'arguments' => '["Ada"]',
                    ],
                ],
            ])
            ->assertOk();

        $activityPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'worker-1',
                'task_queue' => 'ext-q',
            ]);

        $activityTask = $activityPoll->json('task');

        if ($activityTask === null) {
            $this->markTestSkipped('No activity task available for polling');
        }

        $activityTaskId = $activityTask['task_id'];
        $attemptId = $activityTask['activity_attempt_id'];

        $complete = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/activity-tasks/{$activityTaskId}/complete", [
                'activity_attempt_id' => $attemptId,
                'lease_owner' => 'worker-1',
                'result' => $this->avroEnvelope('Hello Ada from activity'),
            ]);

        $complete->assertOk()
            ->assertJsonPath('outcome', 'completed');

        $resumePoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'worker-1',
                'task_queue' => 'ext-q',
            ]);

        $resumePoll->assertOk();

        $completedEvent = collect($resumePoll->json('task.history_events'))
            ->firstWhere('event_type', 'ActivityCompleted');

        $this->assertIsArray($completedEvent);
        $this->assertSame('avro', $completedEvent['payload']['payload_codec'] ?? null);
        $this->assertSame('avro', $completedEvent['payload']['result']['codec'] ?? null);
        $this->assertSame(
            'Hello Ada from activity',
            Serializer::unserializeWithCodec('avro', (string) ($completedEvent['payload']['result']['blob'] ?? '')),
        );
    }

    public function test_cluster_info_advertises_payload_codec_envelope_capability(): void
    {
        $this->createNamespace('default');

        $info = $this->getJson('/api/cluster/info');

        $info->assertOk()
            ->assertJsonPath('capabilities.payload_codec_envelope', true);
    }

    public function test_start_child_workflow_accepts_envelope_arguments(): void
    {
        Queue::fake();
        $this->configureWorkflowTypes();
        $this->createNamespace('default');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-envelope-child',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'ext-q',
                'input' => ['Ada'],
            ])
            ->assertCreated();

        $this->registerWorker('worker-1', 'ext-q');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'worker-1',
                'task_queue' => 'ext-q',
            ]);

        $taskId = $poll->json('task.task_id');
        $attempt = $poll->json('task.workflow_task_attempt');

        $complete = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => 'worker-1',
                'workflow_task_attempt' => $attempt,
                'commands' => [
                    [
                        'type' => 'start_child_workflow',
                        'workflow_type' => 'tests.external-greeting-workflow',
                        'arguments' => $this->avroEnvelope(['child-arg']),
                    ],
                ],
            ]);

        $complete->assertOk()
            ->assertJsonPath('outcome', 'completed');
    }

    public function test_continue_as_new_accepts_envelope_arguments(): void
    {
        Queue::fake();
        $this->configureWorkflowTypes();
        $this->createNamespace('default');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-envelope-continue',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'ext-q',
                'input' => ['Ada'],
            ])
            ->assertCreated();

        $this->registerWorker('worker-1', 'ext-q');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'worker-1',
                'task_queue' => 'ext-q',
            ]);

        $taskId = $poll->json('task.task_id');
        $attempt = $poll->json('task.workflow_task_attempt');

        $complete = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => 'worker-1',
                'workflow_task_attempt' => $attempt,
                'commands' => [
                    [
                        'type' => 'continue_as_new',
                        'arguments' => $this->avroEnvelope(['new-generation']),
                    ],
                ],
            ]);

        $complete->assertOk()
            ->assertJsonPath('outcome', 'completed');
    }

    public function test_signal_with_plain_array_still_works(): void
    {
        Queue::fake();
        $this->configureWorkflowTypes();
        $this->createNamespace('default');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-envelope-signal-compat',
                'workflow_type' => 'tests.interactive-command-workflow',
            ])
            ->assertCreated();

        $runId = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-envelope-signal-compat')
            ->json('run_id');

        $this->runReadyWorkflowTask($runId);

        $signal = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-envelope-signal-compat/signal/advance', [
                'input' => ['PlainUser'],
            ]);

        $signal->assertStatus(202);

        $this->runReadyWorkflowTask($runId);

        $query = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-envelope-signal-compat/query/currentState');

        $query->assertOk()
            ->assertJsonPath('result.name', 'PlainUser');
    }

    public function test_start_response_includes_payload_codec(): void
    {
        Queue::fake();
        $this->configureWorkflowTypes();
        $this->createNamespace('default');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-codec-start',
                'workflow_type' => 'tests.interactive-command-workflow',
                'input' => ['hello'],
            ]);

        $start->assertCreated()
            ->assertJsonPath('payload_codec', 'avro');
    }

    /**
     * Regression for TD-S047: when the request omits `input`, the run row's
     * arguments blob is encoded with the default codec (Avro) and labeled
     * "avro" — so the codec tag always matches the bytes. The no-input
     * fallback now correctly uses `CodecRegistry::defaultCodec()` and stamps
     * the row with the matching `payload_codec`.
     */
    public function test_no_input_start_labels_payload_codec_consistently_under_avro_default(): void
    {
        Queue::fake();
        config()->set('workflows.serializer', 'avro');
        $this->configureWorkflowTypes();
        $this->createNamespace('default');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-codec-start-noinput-avro-default',
                'workflow_type' => 'tests.interactive-command-workflow',
            ]);

        $start->assertCreated()
            ->assertJsonPath('payload_codec', 'avro');

        $runId = $start->json('run_id');

        // Round-trip the stored arguments with the labeled codec — this would
        // throw if the bytes were Avro-encoded but tagged differently.
        $describe = $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/wf-codec-start-noinput-avro-default/runs/{$runId}");

        $describe->assertOk()
            ->assertJsonPath('payload_codec', 'avro')
            ->assertJsonPath('input_envelope.codec', 'avro');

        $blob = $describe->json('input_envelope.blob');
        $this->assertIsString($blob);
        $this->assertSame([], Serializer::unserializeWithCodec('avro', $blob));
    }

    public function test_describe_response_includes_payload_codec(): void
    {
        Queue::fake();
        $this->configureWorkflowTypes();
        $this->createNamespace('default');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-codec-describe',
                'workflow_type' => 'tests.interactive-command-workflow',
                'input' => ['hello'],
            ])
            ->assertCreated();

        $describe = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-codec-describe');

        $describe->assertOk()
            ->assertJsonPath('payload_codec', 'avro');
    }

    public function test_show_run_response_includes_payload_codec(): void
    {
        Queue::fake();
        $this->configureWorkflowTypes();
        $this->createNamespace('default');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-codec-run',
                'workflow_type' => 'tests.interactive-command-workflow',
                'input' => ['hello'],
            ]);

        $start->assertCreated();
        $runId = $start->json('run_id');

        $showRun = $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/wf-codec-run/runs/{$runId}");

        $showRun->assertOk()
            ->assertJsonPath('payload_codec', 'avro');
    }

    public function test_cluster_info_advertises_available_payload_codecs(): void
    {
        $this->createNamespace('default');

        $info = $this->getJson('/api/cluster/info');

        $info->assertOk()
            ->assertJsonPath('capabilities.payload_codecs', ['avro'])
            ->assertJsonMissingPath('capabilities.payload_codecs_engine_specific')
            ->assertJsonPath(
                'capabilities.avro_value_protocol.schema',
                'durable_workflow.protocol.Value',
            )
            ->assertJsonPath(
                'capabilities.avro_value_protocol.fingerprint',
                Avro::valueSchemaFingerprint(),
            )
            ->assertJsonPath('capabilities.avro_value_protocol.framing', 'single_object')
            ->assertJsonPath('capabilities.avro_value_protocol.magic_hex', 'c301');
    }

    public function test_control_plane_request_contract_advertises_payload_codec_field(): void
    {
        $this->createNamespace('default');

        $info = $this->getJson('/api/cluster/info');

        $info->assertOk();

        $startFields = $info->json('control_plane.request_contract.operations.start.fields');
        $this->assertArrayHasKey('payload_codec', $startFields);
        $this->assertSame('string', $startFields['payload_codec']['type']);

        $this->assertContains('avro', $startFields['payload_codec']['canonical_values']);
    }

    public function test_control_plane_request_contract_lists_only_avro(): void
    {
        $this->createNamespace('default');

        $info = $this->getJson('/api/cluster/info');

        $info->assertOk();

        $startFields = $info->json('control_plane.request_contract.operations.start.fields');
        $canonical = $startFields['payload_codec']['canonical_values'];

        $this->assertSame(['avro'], $canonical);
        $this->assertArrayNotHasKey('engine_specific_values', $startFields['payload_codec']);
    }

    public function test_activity_poll_returns_arguments_as_codec_envelope(): void
    {
        Queue::fake();
        $this->configureWorkflowTypes();
        $this->createNamespace('default');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-activity-envelope',
                'workflow_type' => 'tests.external-greeting-workflow',
                'input' => ['Ada'],
            ])
            ->assertCreated();

        $runId = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-activity-envelope')
            ->json('run_id');

        $this->runReadyWorkflowTask($runId);

        $this->registerWorker('py-worker-envelope', 'external-activities');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'py-worker-envelope',
                'task_queue' => 'external-activities',
            ]);

        $poll->assertOk()
            ->assertJsonPath('task.arguments.codec', 'avro')
            ->assertJsonStructure(['task' => ['arguments' => ['codec', 'blob']]]);

        $blob = $poll->json('task.arguments.blob');
        $this->assertIsString($blob);
        $this->assertSame(['Ada'], Serializer::unserializeWithCodec('avro', $blob));
    }

    public function test_external_storage_round_trips_oversize_worker_payloads_through_history(): void
    {
        Queue::fake();
        $this->configureWorkflowTypes();
        $this->createNamespace('default', [
            'driver' => 'local',
            'enabled' => true,
            'threshold_bytes' => 32,
            'config' => [
                'uri' => 'file://'.$this->externalStorageDirectory,
            ],
        ]);

        $largeInput = str_repeat('A', 128);
        $activityResult = ['message' => str_repeat('B', 128)];
        $workflowResult = ['done' => str_repeat('C', 128)];

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-external-roundtrip',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'ext-q',
                'input' => [$largeInput],
            ]);

        $start->assertCreated();
        $runId = (string) $start->json('run_id');

        $run = WorkflowRun::query()->findOrFail($runId);
        $this->assertStoredExternalPayload($run->arguments);
        $this->assertSame([$largeInput], $run->workflowArguments());

        $this->registerWorker('worker-external-roundtrip', 'ext-q');

        $firstPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'worker-external-roundtrip',
                'task_queue' => 'ext-q',
            ]);

        $firstPoll->assertOk()
            ->assertJsonPath('task.arguments.codec', 'avro')
            ->assertJsonStructure(['task' => ['arguments' => ['codec', 'external_payload']]]);
        $this->assertExternalEnvelopeDecodes($firstPoll->json('task.arguments'), [$largeInput]);

        $activityArguments = Serializer::serializeWithCodec('avro', [$largeInput]);
        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/'.$firstPoll->json('task.task_id').'/complete', [
                'lease_owner' => 'worker-external-roundtrip',
                'workflow_task_attempt' => $firstPoll->json('task.workflow_task_attempt'),
                'commands' => [
                    [
                        'type' => 'schedule_activity',
                        'activity_type' => 'tests.external-greeting-activity',
                        'arguments' => $this->externalStorageEnvelope('avro', $activityArguments),
                    ],
                ],
            ])
            ->assertOk();

        $activity = ActivityExecution::query()
            ->where('workflow_run_id', $runId)
            ->where('activity_type', 'tests.external-greeting-activity')
            ->firstOrFail();
        $this->assertStoredExternalPayload($activity->arguments);
        $this->assertSame([$largeInput], $activity->activityArguments());

        $activityPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'worker-external-roundtrip',
                'task_queue' => 'ext-q',
            ]);

        $activityPoll->assertOk()
            ->assertJsonPath('task.arguments.codec', 'avro')
            ->assertJsonStructure(['task' => ['arguments' => ['codec', 'external_payload']]]);
        $this->assertExternalEnvelopeDecodes($activityPoll->json('task.arguments'), [$largeInput]);

        $activityResultPayload = Serializer::serializeWithCodec('avro', $activityResult);
        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/'.$activityPoll->json('task.task_id').'/complete', [
                'activity_attempt_id' => $activityPoll->json('task.activity_attempt_id'),
                'lease_owner' => 'worker-external-roundtrip',
                'result' => $this->externalStorageEnvelope('avro', $activityResultPayload),
            ])
            ->assertOk();

        $activity->refresh();
        $this->assertStoredExternalPayload($activity->result);
        $this->assertSame($activityResult, $activity->activityResult());

        $resumePoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'worker-external-roundtrip',
                'task_queue' => 'ext-q',
            ]);

        $resumePoll->assertOk();
        $activityCompleted = collect($resumePoll->json('task.history_events'))
            ->firstWhere('event_type', 'ActivityCompleted');

        $this->assertIsArray($activityCompleted);
        $this->assertExternalEnvelopeDecodes($activityCompleted['payload']['result'], $activityResult);

        $workflowResultCodec = 'avro';
        $workflowResultPayload = Serializer::serializeWithCodec($workflowResultCodec, $workflowResult);
        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/'.$resumePoll->json('task.task_id').'/complete', [
                'lease_owner' => 'worker-external-roundtrip',
                'workflow_task_attempt' => $resumePoll->json('task.workflow_task_attempt'),
                'commands' => [
                    [
                        'type' => 'complete_workflow',
                        'result' => $this->externalStorageEnvelope($workflowResultCodec, $workflowResultPayload),
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('run_status', 'completed');

        $run->refresh();
        $this->assertStoredExternalPayload($run->output);
        $this->assertSame($workflowResultCodec, $run->output_payload_codec);
        $this->assertSame($workflowResult, $run->workflowOutput());

        $history = $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/wf-external-roundtrip/runs/{$runId}/history");

        $history->assertOk();
        $historyActivityCompleted = collect($history->json('events'))
            ->firstWhere('event_type', 'ActivityCompleted');
        $historyWorkflowCompleted = collect($history->json('events'))
            ->firstWhere('event_type', 'WorkflowCompleted');

        $this->assertIsArray($historyActivityCompleted);
        $this->assertIsArray($historyWorkflowCompleted);
        $this->assertExternalEnvelopeDecodes($historyActivityCompleted['payload']['result'], $activityResult);
        $this->assertExternalEnvelopeDecodes(
            $historyWorkflowCompleted['payload']['output'],
            $workflowResult,
            $workflowResultCodec,
        );

        foreach ([
            '/api/workflows/wf-external-roundtrip',
            "/api/workflows/wf-external-roundtrip/runs/{$runId}",
        ] as $path) {
            $show = $this->withHeaders($this->apiHeaders())->getJson($path);

            $show->assertOk()
                ->assertJsonPath('output.done', $workflowResult['done'])
                ->assertJsonPath('output_envelope.codec', $workflowResultCodec)
                ->assertJsonStructure(['output_envelope' => ['codec', 'external_payload']]);
            $this->assertExternalEnvelopeDecodes(
                $show->json('output_envelope'),
                $workflowResult,
                $workflowResultCodec,
            );
        }
    }

    public function test_read_surfaces_do_not_externalize_existing_inline_payloads(): void
    {
        Queue::fake();
        $this->configureWorkflowTypes();
        $this->createNamespace('default');

        $largeInput = str_repeat('I', 128);

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-inline-read-path',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'inline-read-q',
                'input' => [$largeInput],
            ]);

        $start->assertCreated();

        $run = WorkflowRun::query()->findOrFail((string) $start->json('run_id'));
        $this->assertIsString($run->arguments);
        $this->assertStringStartsNotWith(ExternalPayloads::STORED_REFERENCE_PREFIX, $run->arguments);

        $this->createNamespace('default', [
            'driver' => 's3',
            'enabled' => true,
            'threshold_bytes' => 32,
            'config' => [
                'disk' => 'unavailable-external-payloads',
                'bucket' => 'payloads',
            ],
        ]);

        $show = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-inline-read-path');

        $show->assertOk();
        $this->assertInlineEnvelope($show->json('input_envelope'), $run->arguments, (string) $run->payload_codec);

        $this->registerWorker('worker-inline-read-path', 'inline-read-q');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'worker-inline-read-path',
                'task_queue' => 'inline-read-q',
            ]);

        $poll->assertOk()
            ->assertJsonPath('poll_status', 'leased');
        $this->assertInlineEnvelope($poll->json('task.arguments'), $run->arguments, (string) $run->payload_codec);
    }

    public function test_read_path_payload_formatting_keeps_oversize_inline_payloads_inline(): void
    {
        Queue::fake();
        $this->createNamespace('default', [
            'driver' => 's3',
            'enabled' => true,
            'threshold_bytes' => 32,
            'config' => [
                'disk' => 'unavailable-external-payloads',
                'bucket' => 'payloads',
            ],
        ]);

        $arguments = ['input' => str_repeat('A', 128)];
        $result = ['result' => str_repeat('B', 128)];
        $output = ['output' => str_repeat('C', 128)];
        $commandPayload = ['command' => str_repeat('D', 128)];
        $activityArguments = ['activity_input' => str_repeat('E', 128)];
        $activityResult = ['activity_result' => str_repeat('F', 128)];
        $details = ['details' => str_repeat('G', 128)];

        /** @var ExternalPayloadEnvelopeService $envelopes */
        $envelopes = app(ExternalPayloadEnvelopeService::class);
        $workerBlob = Serializer::serializeWithCodec('avro', ['worker' => str_repeat('H', 128)]);
        $workerEnvelope = $envelopes->workerEnvelope('default', 'avro', $workerBlob);
        $payload = $envelopes->historyPayload('default', [
            'payload_codec' => 'avro',
            'arguments' => Serializer::serializeWithCodec('avro', $arguments),
            'result' => Serializer::serializeWithCodec('avro', $result),
            'output' => Serializer::serializeWithCodec('avro', $output),
            'command' => [
                'payload_codec' => 'avro',
                'payload' => Serializer::serializeWithCodec('avro', $commandPayload),
            ],
            'activity' => [
                'payload_codec' => 'avro',
                'arguments' => Serializer::serializeWithCodec('avro', $activityArguments),
                'result' => Serializer::serializeWithCodec('avro', $activityResult),
            ],
            'exception' => [
                'details_payload_codec' => 'avro',
                'details' => Serializer::serializeWithCodec('avro', $details),
            ],
        ], 'avro');

        $this->assertInlineEnvelope($workerEnvelope, $workerBlob, 'avro');
        $this->assertInlineEnvelope($payload['arguments'], Serializer::serializeWithCodec('avro', $arguments), 'avro');
        $this->assertInlineEnvelope($payload['result'], Serializer::serializeWithCodec('avro', $result), 'avro');
        $this->assertInlineEnvelope($payload['output'], Serializer::serializeWithCodec('avro', $output), 'avro');
        $this->assertInlineEnvelope($payload['command']['payload'], Serializer::serializeWithCodec('avro', $commandPayload), 'avro');
        $this->assertInlineEnvelope($payload['activity']['arguments'], Serializer::serializeWithCodec('avro', $activityArguments), 'avro');
        $this->assertInlineEnvelope($payload['activity']['result'], Serializer::serializeWithCodec('avro', $activityResult), 'avro');
        $this->assertInlineEnvelope($payload['exception']['details'], Serializer::serializeWithCodec('avro', $details), 'avro');
    }

    public function test_history_payload_inlines_string_fields_below_external_storage_threshold(): void
    {
        Queue::fake();
        $this->createNamespace('default', [
            'driver' => 'local',
            'enabled' => true,
            'threshold_bytes' => 1024,
            'config' => [
                'uri' => 'file://'.$this->externalStorageDirectory,
            ],
        ]);

        /** @var ExternalPayloadEnvelopeService $envelopes */
        $envelopes = app(ExternalPayloadEnvelopeService::class);
        $encoded = Serializer::serializeWithCodec('avro', 'small-payload');
        $payload = $envelopes->historyPayload('default', [
            'payload_codec' => 'avro',
            'output' => $encoded,
        ], 'avro');

        $this->assertSame('avro', $payload['output']['codec'] ?? null);
        $this->assertSame($encoded, $payload['output']['blob'] ?? null);
        $this->assertArrayNotHasKey('external_storage', $payload['output']);
    }

    public function test_workflow_task_completion_rejects_wrong_lease_before_reading_external_payload_reference(): void
    {
        Queue::fake();
        $this->configureWorkflowTypes();
        $this->createNamespace('default', [
            'driver' => 'local',
            'enabled' => true,
            'threshold_bytes' => 32,
            'config' => [
                'uri' => 'file://'.$this->externalStorageDirectory,
            ],
        ]);

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-external-wrong-lease',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'ext-q',
                'input' => ['Ada'],
            ])
            ->assertCreated();

        $this->registerWorker('worker-external-wrong-lease', 'ext-q');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'worker-external-wrong-lease',
                'task_queue' => 'ext-q',
            ]);

        $poll->assertOk()
            ->assertJsonPath('task.lease_owner', 'worker-external-wrong-lease');

        $missingPayload = Serializer::serializeWithCodec('avro', ['not-read']);

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/'.$poll->json('task.task_id').'/complete', [
                'lease_owner' => 'wrong-worker',
                'workflow_task_attempt' => $poll->json('task.workflow_task_attempt'),
                'commands' => [
                    [
                        'type' => 'complete_workflow',
                        'result' => $this->missingExternalStorageEnvelope('avro', $missingPayload),
                    ],
                ],
            ])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'lease_owner_mismatch')
            ->assertJsonPath('lease_owner', 'worker-external-wrong-lease');
    }

    public function test_workflow_task_completion_rejects_correct_lease_missing_external_payload_reference(): void
    {
        $workerId = 'worker-external-missing-reference';
        $poll = $this->pollExternalStorageWorkflowTask('wf-external-missing-reference', $workerId);
        $missingPayload = Serializer::serializeWithCodec('avro', ['missing-reference']);

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/'.$poll->json('task.task_id').'/complete', [
                'lease_owner' => $workerId,
                'workflow_task_attempt' => $poll->json('task.workflow_task_attempt'),
                'commands' => [
                    [
                        'type' => 'complete_workflow',
                        'result' => $this->missingExternalStorageEnvelope('avro', $missingPayload),
                    ],
                ],
            ])
            ->assertStatus(404)
            ->assertJsonPath('reason', 'external_payload_not_found')
            ->assertJsonPath('retryable', false);
    }

    public function test_workflow_task_completion_rejects_correct_lease_corrupt_external_payload_reference(): void
    {
        $workerId = 'worker-external-corrupt-reference';
        $poll = $this->pollExternalStorageWorkflowTask('wf-external-corrupt-reference', $workerId);
        $payload = Serializer::serializeWithCodec('avro', ['corrupt-reference']);

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/'.$poll->json('task.task_id').'/complete', [
                'lease_owner' => $workerId,
                'workflow_task_attempt' => $poll->json('task.workflow_task_attempt'),
                'commands' => [
                    [
                        'type' => 'complete_workflow',
                        'result' => $this->corruptExternalStorageEnvelope('avro', $payload),
                    ],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'external_payload_integrity_mismatch')
            ->assertJsonPath('retryable', false);
    }

    public function test_workflow_task_command_external_storage_arguments_preserve_codec_for_normalizer(): void
    {
        $this->createNamespace('default', [
            'driver' => 'local',
            'enabled' => true,
            'threshold_bytes' => 32,
            'config' => [
                'uri' => 'file://'.$this->externalStorageDirectory,
            ],
        ]);

        $payload = Serializer::serializeWithCodec('avro', ['continue']);

        $commands = $this->resolveWorkflowTaskCommandPayloadReferences([
            [
                'type' => 'continue_as_new',
                'arguments' => $this->internalExternalStorageEnvelope('avro', $payload),
            ],
        ]);

        $this->assertSame('avro', $commands[0]['payload_codec'] ?? null);
        $this->assertSame([
            'codec' => 'avro',
            'blob' => $payload,
        ], $commands[0]['arguments']);
    }

    public function test_workflow_task_command_external_storage_results_preserve_codec_for_supported_result_commands(): void
    {
        $this->createNamespace('default', [
            'driver' => 'local',
            'enabled' => true,
            'threshold_bytes' => 32,
            'config' => [
                'uri' => 'file://'.$this->externalStorageDirectory,
            ],
        ]);

        $workflowPayload = Serializer::serializeWithCodec('avro', ['workflow-result']);
        $sideEffectPayload = Serializer::serializeWithCodec('avro', ['side-effect']);

        $commands = $this->resolveWorkflowTaskCommandPayloadReferences([
            [
                'type' => 'complete_workflow',
                'result' => $this->internalExternalStorageEnvelope('avro', $workflowPayload),
            ],
            [
                'type' => 'record_side_effect',
                'payload_codec' => 'avro',
                'result' => $this->internalExternalStorageEnvelope('avro', $sideEffectPayload),
            ],
        ]);

        $this->assertSame([
            'codec' => 'avro',
            'blob' => $workflowPayload,
        ], $commands[0]['result']);
        $this->assertSame('avro', $commands[0]['payload_codec'] ?? null);
        $this->assertSame([
            'codec' => 'avro',
            'blob' => $sideEffectPayload,
        ], $commands[1]['result']);
        $this->assertSame('avro', $commands[1]['payload_codec'] ?? null);
    }

    public function test_workflow_task_command_external_storage_update_results_use_package_payload_envelope_contract(): void
    {
        $this->createNamespace('default', [
            'driver' => 'local',
            'enabled' => true,
            'threshold_bytes' => 32,
            'config' => [
                'uri' => 'file://'.$this->externalStorageDirectory,
            ],
        ]);

        $updatePayload = Serializer::serializeWithCodec('avro', ['update-result']);

        $commands = $this->resolveWorkflowTaskCommandPayloadReferences([
            [
                'type' => 'complete_update',
                'update_id' => 'update-1',
                'payload_codec' => 'avro',
                'result' => $this->internalExternalStorageEnvelope('avro', $updatePayload),
            ],
        ]);

        $this->assertSame([
            'codec' => 'avro',
            'blob' => $updatePayload,
        ], $commands[0]['result']);
        $this->assertSame('avro', $commands[0]['payload_codec'] ?? null);

        $normalized = WorkflowCommandNormalizer::normalize($commands);

        $this->assertSame($updatePayload, $normalized[0]['result']);
        $this->assertSame('avro', $normalized[0]['payload_codec'] ?? null);
    }

    public function test_describe_response_includes_input_and_output_envelopes(): void
    {
        Queue::fake();
        $this->configureWorkflowTypes();
        $this->createNamespace('default');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-envelope-describe',
                'workflow_type' => 'tests.external-greeting-workflow',
                'input' => ['Ada'],
            ])
            ->assertCreated();

        $describe = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-envelope-describe');

        $describe->assertOk()
            ->assertJsonPath('input.0', 'Ada')
            ->assertJsonPath('input_envelope.codec', 'avro')
            ->assertJsonStructure(['input_envelope' => ['codec', 'blob']]);

        $blob = $describe->json('input_envelope.blob');
        $this->assertIsString($blob);
        $this->assertSame(['Ada'], Serializer::unserializeWithCodec('avro', $blob));

        $this->assertNull($describe->json('output_envelope'));
    }

    public function test_describe_projects_bytes_and_ambiguous_maps_without_losing_envelopes(): void
    {
        Queue::fake();
        $this->configureWorkflowTypes();
        $this->createNamespace('default');

        $input = [
            AvroMapValue::fromPairs([]),
            AvroMapValue::fromPairs([['0', 'zero'], ['1', ['nested']]]),
            AvroBinaryValue::fromBytes("\x00\xFF"),
            ['zero', 'one'],
        ];
        $inputBlob = Avro::serialize($input);

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-envelope-typed-projection',
                'workflow_type' => 'tests.external-greeting-workflow',
                'input' => ['codec' => 'avro', 'blob' => $inputBlob],
            ])
            ->assertCreated();

        $run = WorkflowRun::query()->findOrFail((string) $start->json('run_id'));
        $outputBlob = Avro::serialize(
            AvroMapValue::fromPairs([['0', AvroBinaryValue::fromBytes('result')]]),
        );
        $run->forceFill([
            'output' => $outputBlob,
            'output_payload_codec' => 'avro',
        ])->save();

        $describe = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-envelope-typed-projection')
            ->assertOk()
            ->assertJsonPath('input.0.$type', 'map')
            ->assertJsonPath('input.0.entries', [])
            ->assertJsonPath('input.1.entries.0.key', '0')
            ->assertJsonPath('input.1.entries.1.value.0', 'nested')
            ->assertJsonPath('input.2.$type', 'bytes')
            ->assertJsonPath('input.2.base64', 'AP8=')
            ->assertJsonPath('input.3.0', 'zero')
            ->assertJsonPath('output.$type', 'map')
            ->assertJsonPath('output.entries.0.value.$type', 'bytes')
            ->assertJsonPath('output.entries.0.value.base64', 'cmVzdWx0')
            ->assertJsonPath('input_envelope.codec', 'avro')
            ->assertJsonPath('input_envelope.blob', $inputBlob)
            ->assertJsonPath('output_envelope.codec', 'avro')
            ->assertJsonPath('output_envelope.blob', $outputBlob);

        json_encode($describe->json(), JSON_THROW_ON_ERROR);
    }

    public function test_show_run_includes_output_envelope_when_completed(): void
    {
        Queue::fake();
        $this->configureWorkflowTypes();
        $this->createNamespace('default');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-envelope-output',
                'workflow_type' => 'tests.interactive-command-workflow',
                'input' => ['Ada'],
            ])
            ->assertCreated();

        $runId = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-envelope-output')
            ->json('run_id');

        $this->runReadyWorkflowTask($runId);

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-envelope-output/signal/advance', [
                'input' => ['finish'],
            ])
            ->assertStatus(202);

        $this->runReadyWorkflowTask($runId);

        $showRun = $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/wf-envelope-output/runs/{$runId}");

        $showRun->assertOk()
            ->assertJsonPath('payload_codec', 'avro');

        if ($showRun->json('output') !== null) {
            $showRun->assertJsonStructure(['output_envelope' => ['codec', 'blob']]);
            $this->assertSame('avro', $showRun->json('output_envelope.codec'));
        }
    }

    public function test_query_result_includes_envelope(): void
    {
        Queue::fake();
        $this->configureWorkflowTypes();
        $this->createNamespace('default');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-envelope-query-result',
                'workflow_type' => 'tests.interactive-command-workflow',
            ])
            ->assertCreated();

        $runId = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-envelope-query-result')
            ->json('run_id');

        $this->runReadyWorkflowTask($runId);

        $query = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-envelope-query-result/query/events-starting-with', [
                'input' => ['start'],
            ]);

        $query->assertOk()
            ->assertJsonPath('result', 1)
            ->assertJsonStructure(['result_envelope' => ['codec', 'blob']]);

        $this->assertSame('avro', $query->json('result_envelope.codec'));

        $blob = $query->json('result_envelope.blob');
        $this->assertIsString($blob);
        $this->assertSame(1, Serializer::unserializeWithCodec('avro', $blob));
    }

    public function test_update_result_includes_envelope(): void
    {
        Queue::fake();
        $this->configureWorkflowTypes();
        $this->createNamespace('default');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-envelope-update-result',
                'workflow_type' => 'tests.interactive-command-workflow',
            ])
            ->assertCreated();

        $runId = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-envelope-update-result')
            ->json('run_id');

        $this->runReadyWorkflowTask($runId);

        $update = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-envelope-update-result/update/approve', [
                'input' => [true, 'envelope-result-test'],
                'wait_for' => 'completed',
            ]);

        $update->assertOk()
            ->assertJsonPath('update_name', 'approve')
            ->assertJsonPath('result.approved', true)
            ->assertJsonStructure(['result_envelope' => ['codec', 'blob']]);

        $this->assertSame('avro', $update->json('result_envelope.codec'));

        $blob = $update->json('result_envelope.blob');
        $this->assertIsString($blob);
        $decoded = Serializer::unserializeWithCodec('avro', $blob);
        $this->assertSame(true, $decoded['approved']);
    }

    public function test_signal_with_avro_envelope_stores_codec_on_signal_model(): void
    {
        Queue::fake();
        $this->configureWorkflowTypes();
        $this->createNamespace('default');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-signal-codec-store',
                'workflow_type' => 'tests.interactive-command-workflow',
            ])
            ->assertCreated();

        $runId = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-signal-codec-store')
            ->json('run_id');

        $this->runReadyWorkflowTask($runId);

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-signal-codec-store/signal/advance', [
                'input' => $this->avroEnvelope(['EnvelopeCodecUser']),
            ])
            ->assertStatus(202);

        $signal = WorkflowSignal::query()
            ->where('workflow_run_id', $runId)
            ->where('signal_name', 'advance')
            ->first();

        $this->assertNotNull($signal);
        $this->assertSame('avro', $signal->payload_codec);

        $decoded = Serializer::unserializeWithCodec('avro', (string) $signal->arguments);
        $this->assertSame(['EnvelopeCodecUser'], $decoded);
    }

    public function test_cluster_info_advertises_envelope_response_capability(): void
    {
        $this->createNamespace('default');

        $info = $this->getJson('/api/cluster/info');

        $info->assertOk()
            ->assertJsonPath('capabilities.payload_codec_envelope_responses', true);
    }

    private function configureWorkflowTypes(): void
    {
        config()->set('workflows.v2.types.workflows', [
            'tests.interactive-command-workflow' => InteractiveCommandWorkflow::class,
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);
    }

    private function createNamespace(string $name, ?array $externalPayloadStorage = null): void
    {
        WorkflowNamespace::query()->updateOrCreate(
            ['name' => $name],
            [
                'description' => 'Test namespace',
                'retention_days' => 30,
                'status' => 'active',
                'external_payload_storage' => $externalPayloadStorage,
            ],
        );
    }

    private function apiHeaders(string $namespace = 'default'): array
    {
        return [
            'X-Namespace' => $namespace,
            'X-Durable-Workflow-Control-Plane-Version' => '2',
        ];
    }

    private function workerHeaders(string $namespace = 'default'): array
    {
        return [
            'X-Namespace' => $namespace,
            WorkerProtocol::HEADER => WorkerProtocol::VERSION,
        ];
    }

    /**
     * @return array{codec: string, blob: string}
     */
    private function avroEnvelope(mixed $payload): array
    {
        $blob = Serializer::serializeWithCodec('avro', $payload);
        $this->assertStringStartsWith(
            Avro::SINGLE_OBJECT_MAGIC.Avro::VALUE_SCHEMA_FINGERPRINT,
            (string) base64_decode($blob, true),
        );

        return [
            'codec' => 'avro',
            'blob' => $blob,
        ];
    }

    /**
     * @return array{codec: string, external_payload: array{schema: string, reference_id: string, sha256: string, size_bytes: int, codec: string}}
     */
    private function externalStorageEnvelope(string $codec, string $payload): array
    {
        $reference = app(RuntimeExternalPayloadRegistry::class)->upload(
            'default',
            $payload,
            $codec,
            hash('sha256', $payload),
        );

        return [
            'codec' => $codec,
            'external_payload' => $reference,
        ];
    }

    /**
     * @return array{codec: string, external_payload: array{schema: string, reference_id: string, sha256: string, size_bytes: int, codec: string}}
     */
    private function missingExternalStorageEnvelope(string $codec, string $payload): array
    {
        $envelope = $this->externalStorageEnvelope($codec, $payload);
        $row = RuntimeExternalPayload::query()
            ->where('id', $envelope['external_payload']['reference_id'])
            ->firstOrFail();
        File::delete(rawurldecode(substr($row->storage_uri, strlen('file://'))));

        return $envelope;
    }

    /**
     * @return array{codec: string, external_payload: array{schema: string, reference_id: string, sha256: string, size_bytes: int, codec: string}}
     */
    private function corruptExternalStorageEnvelope(string $codec, string $payload): array
    {
        $envelope = $this->externalStorageEnvelope($codec, $payload);
        $row = RuntimeExternalPayload::query()
            ->where('id', $envelope['external_payload']['reference_id'])
            ->firstOrFail();
        $corruptPayload = strlen($payload) > 0
            ? (($payload[0] === 'x' ? 'y' : 'x').substr($payload, 1))
            : 'x';
        file_put_contents(rawurldecode(substr($row->storage_uri, strlen('file://'))), $corruptPayload);

        return $envelope;
    }

    /**
     * @return array{codec: string, external_storage: array{schema: string, uri: string, sha256: string, size_bytes: int, codec: string}}
     */
    private function internalExternalStorageEnvelope(string $codec, string $payload): array
    {
        File::ensureDirectoryExists($this->externalStorageDirectory);

        $sha256 = hash('sha256', $payload);
        $path = $this->externalStorageDirectory.'/'.$codec.'-'.$sha256.'.bin';
        file_put_contents($path, $payload);
        app(RuntimeExternalPayloadRegistry::class)->trackRetained(
            'default',
            'file://'.$path,
            $codec,
            $sha256,
            strlen($payload),
        );

        return [
            'codec' => $codec,
            'external_storage' => [
                'schema' => 'durable-workflow.v2.external-payload-reference.v1',
                'uri' => 'file://'.$path,
                'sha256' => $sha256,
                'size_bytes' => strlen($payload),
                'codec' => $codec,
            ],
        ];
    }

    private function pollExternalStorageWorkflowTask(string $workflowId, string $workerId): TestResponse
    {
        Queue::fake();
        $this->configureWorkflowTypes();
        $this->createNamespace('default', [
            'driver' => 'local',
            'enabled' => true,
            'threshold_bytes' => 32,
            'config' => [
                'uri' => 'file://'.$this->externalStorageDirectory,
            ],
        ]);

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => $workflowId,
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'ext-q',
                'input' => ['Ada'],
            ])
            ->assertCreated();

        $this->registerWorker($workerId, 'ext-q');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => $workerId,
                'task_queue' => 'ext-q',
            ]);

        $poll->assertOk()
            ->assertJsonPath('task.lease_owner', $workerId);

        return $poll;
    }

    /**
     * @param  array<string, mixed>|null  $envelope
     */
    private function assertInlineEnvelope(?array $envelope, string $expectedBlob, string $expectedCodec): void
    {
        $this->assertIsArray($envelope);
        $this->assertSame($expectedCodec, $envelope['codec'] ?? null);
        $this->assertSame($expectedBlob, $envelope['blob'] ?? null);
        $this->assertArrayNotHasKey('external_storage', $envelope);
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function assertExternalEnvelopeDecodes(
        array $envelope,
        mixed $expected,
        string $expectedCodec = 'avro',
    ): void {
        $this->assertSame($expectedCodec, $envelope['codec'] ?? null);
        $this->assertArrayHasKey('external_payload', $envelope);
        $this->assertArrayNotHasKey('blob', $envelope);

        $resolved = app(RuntimeExternalPayloadRegistry::class)->fetch(
            'default',
            $envelope['external_payload'],
        );

        $this->assertSame(
            $expected,
            Serializer::unserializeWithCodec($expectedCodec, $resolved['data']),
        );
    }

    private function assertStoredExternalPayload(?string $payload): void
    {
        if (! is_string($payload)) {
            $this->fail('Expected payload column to contain a stored external reference.');
        }

        $this->assertStringStartsWith(ExternalPayloads::STORED_REFERENCE_PREFIX, $payload);
    }

    /**
     * @return list<string>
     */
    private function externalStorageFilePaths(): array
    {
        if (! File::exists($this->externalStorageDirectory)) {
            return [];
        }

        $paths = array_map(
            static fn ($file): string => $file->getPathname(),
            File::allFiles($this->externalStorageDirectory),
        );
        sort($paths);

        return array_values($paths);
    }

    /**
     * @param  list<array<string, mixed>>  $commands
     * @return list<array<string, mixed>>
     */
    private function resolveWorkflowTaskCommandPayloadReferences(array $commands): array
    {
        $controller = app(WorkerController::class);
        $method = new \ReflectionMethod($controller, 'resolveWorkflowTaskCommandPayloadReferences');
        $method->setAccessible(true);

        /** @var list<array<string, mixed>> $resolved */
        $resolved = $method->invoke($controller, $commands, 'default');

        return $resolved;
    }

    private function registerWorker(string $workerId, string $taskQueue): void
    {
        // Capability-aware routing: declare every workflow and activity
        // type this suite drives so the worker is eligible for any of the
        // tasks tests in this file enqueue.
        WorkerRegistration::query()->updateOrCreate(
            ['worker_id' => $workerId, 'namespace' => 'default'],
            [
                'task_queue' => $taskQueue,
                'runtime' => 'php',
                'supported_workflow_types' => [
                    'tests.external-greeting-workflow',
                    'tests.interactive-command-workflow',
                ],
                'supported_activity_types' => [
                    'tests.external-greeting-activity',
                ],
                'last_heartbeat_at' => now(),
                'status' => 'active',
            ],
        );
    }

    private function runReadyWorkflowTask(string $runId): void
    {
        $taskId = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', 'workflow')
            ->where('status', 'ready')
            ->orderBy('available_at')
            ->value('id');

        $this->assertIsString($taskId);

        $job = new RunWorkflowTask($taskId);
        $job->handle(app(WorkflowExecutor::class));
    }
}
