<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\ExternalPayloads;

final class PayloadCodecDeploymentPreflightRestartTest extends TestCase
{
    use ServerTestHelpers;

    private ?string $databasePath = null;

    private ?string $externalStorageDirectory = null;

    protected function setUp(): void
    {
        parent::setUp();

        $temporaryDirectory = is_dir('/dev/shm') && is_writable('/dev/shm')
            ? '/dev/shm'
            : sys_get_temp_dir();
        $databasePath = tempnam($temporaryDirectory, 'dw-server-payload-preflight-');

        if ($databasePath === false) {
            $this->fail('Could not allocate a SQLite database file for the payload preflight restart test.');
        }

        $this->databasePath = $databasePath;
        $this->externalStorageDirectory = $temporaryDirectory.'/dw-server-payload-preflight-storage-'.bin2hex(random_bytes(6));

        $this->configurePersistedDatabase();
        $this->artisan('migrate:fresh', ['--force' => true])
            ->assertExitCode(0);

        WorkflowNamespace::query()->create([
            'name' => 'default',
            'description' => 'Payload preflight restart namespace',
            'retention_days' => 30,
            'status' => 'active',
            'external_payload_storage' => [
                'driver' => 'local',
                'enabled' => true,
                'threshold_bytes' => 32,
                'config' => [
                    'uri' => 'file://'.$this->externalStorageDirectory,
                ],
            ],
        ]);

        Queue::fake();
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');

        if (is_string($this->externalStorageDirectory)) {
            File::deleteDirectory($this->externalStorageDirectory);
            File::deleteDirectory($this->externalStorageDirectory.'.offline');
        }

        if (is_string($this->databasePath)) {
            foreach ([$this->databasePath, $this->databasePath.'-wal', $this->databasePath.'-shm'] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }

        parent::tearDown();
    }

    public function test_cold_bootstrap_accepts_externalized_workflow_command_activity_and_result_payloads(): void
    {
        $workflowInput = ['input' => str_repeat('A', 256)];
        $activityInput = ['input' => str_repeat('B', 256)];
        $activityResult = ['result' => str_repeat('C', 256)];
        $workflowResult = ['result' => str_repeat('D', 256)];

        $start = $this->withHeaders($this->apiHeaders())->postJson('/api/workflows', [
            'workflow_id' => 'preflight-cold-bootstrap',
            'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'preflight-external-payloads',
            'input' => $workflowInput,
        ]);
        $start->assertCreated();

        $runId = (string) $start->json('run_id');
        $run = WorkflowRun::query()->findOrFail($runId);
        $this->assertStoredReference($run->arguments);

        $startCommand = WorkflowCommand::query()
            ->where('workflow_run_id', $runId)
            ->firstOrFail();
        $this->assertStoredReference($startCommand->payload);

        $this->registerWorker(
            'preflight-external-worker',
            'preflight-external-payloads',
            supportedWorkflowTypes: ['tests.external-greeting-workflow'],
        );
        $workflowTask = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'preflight-external-worker',
                'task_queue' => 'preflight-external-payloads',
            ]);
        $workflowTask->assertOk()->assertJsonPath('poll_status', 'leased');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/'.$workflowTask->json('task.task_id').'/complete', [
                'lease_owner' => 'preflight-external-worker',
                'workflow_task_attempt' => $workflowTask->json('task.workflow_task_attempt'),
                'commands' => [[
                    'type' => 'schedule_activity',
                    'activity_type' => 'tests.external-greeting-activity',
                    'arguments' => $this->avroEnvelope($activityInput),
                ]],
            ])
            ->assertOk();

        $activity = ActivityExecution::query()
            ->where('workflow_run_id', $runId)
            ->firstOrFail();
        $this->assertStoredReference($activity->arguments);

        $activityTask = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'preflight-external-worker',
                'task_queue' => 'preflight-external-payloads',
            ]);
        $activityTask->assertOk()->assertJsonPath('poll_status', 'leased');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/'.$activityTask->json('task.task_id').'/complete', [
                'activity_attempt_id' => $activityTask->json('task.activity_attempt_id'),
                'lease_owner' => 'preflight-external-worker',
                'result' => $this->avroEnvelope($activityResult),
            ])
            ->assertOk();
        $activity->refresh();
        $this->assertStoredReference($activity->result);

        $resumeTask = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'preflight-external-worker',
                'task_queue' => 'preflight-external-payloads',
            ]);
        $resumeTask->assertOk()->assertJsonPath('poll_status', 'leased');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/'.$resumeTask->json('task.task_id').'/complete', [
                'lease_owner' => 'preflight-external-worker',
                'workflow_task_attempt' => $resumeTask->json('task.workflow_task_attempt'),
                'commands' => [[
                    'type' => 'complete_workflow',
                    'result' => $this->avroEnvelope($workflowResult),
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('run_status', 'completed');

        $run->refresh();
        $this->assertStoredReference($run->output);
        $this->assertHistoryContainsExternalEnvelope($runId, 'WorkflowStarted');
        $this->assertHistoryContainsExternalEnvelope($runId, 'ActivityScheduled');
        $this->assertHistoryContainsExternalEnvelope($runId, 'ActivityCompleted');
        $this->assertHistoryContainsExternalEnvelope($runId, 'WorkflowCompleted');

        $retainedReferences = [
            'workflow_arguments' => $run->arguments,
            'workflow_output' => $run->output,
            'command_payload' => $startCommand->payload,
            'activity_arguments' => $activity->arguments,
            'activity_result' => $activity->result,
        ];

        DB::disconnect('sqlite');
        $process = $this->coldBootstrapWithExternalStorageOffline();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
        $this->assertStringContainsString('Avro-only payload preflight passed', $process->getOutput());

        $run->refresh();
        $startCommand->refresh();
        $activity->refresh();
        $this->assertSame($retainedReferences, [
            'workflow_arguments' => $run->arguments,
            'workflow_output' => $run->output,
            'command_payload' => $startCommand->payload,
            'activity_arguments' => $activity->arguments,
            'activity_result' => $activity->result,
        ]);
    }

    private function configurePersistedDatabase(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->databasePath,
            'database.connections.sqlite.foreign_key_constraints' => true,
            'server.polling.timeout' => 0,
        ]);

        DB::purge('sqlite');
    }

    /** @return array{codec: string, blob: string} */
    private function avroEnvelope(mixed $value): array
    {
        return [
            'codec' => 'avro',
            'blob' => Serializer::serializeWithCodec('avro', $value),
        ];
    }

    private function assertStoredReference(mixed $payload): void
    {
        $this->assertIsString($payload);
        $this->assertStringStartsWith(ExternalPayloads::STORED_REFERENCE_PREFIX, $payload);
    }

    private function assertHistoryContainsExternalEnvelope(string $runId, string $eventType): void
    {
        $event = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', $eventType)
            ->firstOrFail();
        $encoded = json_encode($event->payload, JSON_UNESCAPED_SLASHES);

        $this->assertIsString($encoded);
        $this->assertStringContainsString('external_storage', $encoded);
    }

    private function coldBootstrapWithExternalStorageOffline(): Process
    {
        $this->assertIsString($this->databasePath);
        $this->assertIsString($this->externalStorageDirectory);

        $offlineDirectory = $this->externalStorageDirectory.'.offline';
        $this->assertTrue(rename($this->externalStorageDirectory, $offlineDirectory));

        try {
            $process = new Process(
                [PHP_BINARY, 'artisan', 'server:bootstrap', '--force'],
                base_path(),
                [
                    'APP_ENV' => 'testing',
                    'APP_KEY' => (string) config('app.key'),
                    'CACHE_STORE' => 'array',
                    'DB_CONNECTION' => 'sqlite',
                    'DB_DATABASE' => $this->databasePath,
                    'DW_AUTH_DRIVER' => 'none',
                    'QUEUE_CONNECTION' => 'database',
                    'SESSION_DRIVER' => 'array',
                ],
            );
            $process->setTimeout(60);
            $process->run();

            return $process;
        } finally {
            rename($offlineDirectory, $this->externalStorageDirectory);
        }
    }
}
