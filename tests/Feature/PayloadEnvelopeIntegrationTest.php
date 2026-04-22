<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use App\Models\WorkflowNamespace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\Fixtures\InteractiveCommandWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
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

    public function test_complete_workflow_accepts_envelope_result(): void
    {
        Queue::fake();
        $this->configureWorkflowTypes();
        $this->createNamespace('default');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-envelope-complete',
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
                        'type' => 'complete_workflow',
                        'result' => $this->avroEnvelope(['greeting' => 'Hello Ada']),
                    ],
                ],
            ]);

        $complete->assertOk()
            ->assertJsonPath('outcome', 'completed');
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
        $this->assertSame(
            'Hello Ada from activity',
            Serializer::unserializeWithCodec('avro', (string) ($completedEvent['payload']['result'] ?? '')),
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
            ->assertJsonPath('capabilities.payload_codecs_engine_specific.php', [
                'workflow-serializer-y',
                'workflow-serializer-base64',
            ]);
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

    public function test_control_plane_request_contract_omits_engine_specific_codecs_from_canonical_values(): void
    {
        $this->createNamespace('default');

        $info = $this->getJson('/api/cluster/info');

        $info->assertOk();

        $startFields = $info->json('control_plane.request_contract.operations.start.fields');
        $canonical = $startFields['payload_codec']['canonical_values'];

        $this->assertNotContains('workflow-serializer-y', $canonical);
        $this->assertNotContains('workflow-serializer-base64', $canonical);

        // Engine-specific codecs, when present, are exposed under an
        // explicitly engine-scoped key so polyglot clients can choose whether
        // to opt into a codec they know how to decode.
        $engineSpecific = $startFields['payload_codec']['engine_specific_values'] ?? null;
        $this->assertIsArray($engineSpecific);
        $this->assertArrayHasKey('php', $engineSpecific);
        $this->assertContains('workflow-serializer-y', $engineSpecific['php']);
        $this->assertContains('workflow-serializer-base64', $engineSpecific['php']);
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
            'X-Durable-Workflow-Protocol-Version' => '1.0',
        ];
    }

    /**
     * @return array{codec: string, blob: string}
     */
    private function avroEnvelope(mixed $payload): array
    {
        return [
            'codec' => 'avro',
            'blob' => Serializer::serializeWithCodec('avro', $payload),
        ];
    }

    /**
     * @return array{codec: string, external_storage: array{schema: string, uri: string, sha256: string, size_bytes: int, codec: string}}
     */
    private function externalStorageEnvelope(string $codec, string $payload): array
    {
        File::ensureDirectoryExists($this->externalStorageDirectory);

        $sha256 = hash('sha256', $payload);
        $path = $this->externalStorageDirectory.'/'.$codec.'-'.$sha256.'.bin';
        file_put_contents($path, $payload);

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

    private function registerWorker(string $workerId, string $taskQueue): void
    {
        WorkerRegistration::query()->updateOrCreate(
            ['worker_id' => $workerId, 'namespace' => 'default'],
            [
                'task_queue' => $taskQueue,
                'runtime' => 'php',
                'supported_workflow_types' => [],
                'supported_activity_types' => [],
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
