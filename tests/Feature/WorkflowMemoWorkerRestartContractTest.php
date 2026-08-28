<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\Serializers\AvroBinaryValue;
use Workflow\Serializers\Serializer;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\MemoPayload;

class WorkflowMemoWorkerRestartContractTest extends TestCase
{
    use ServerTestHelpers;

    private const MEMO_BLOB = 'wwHioz3/VYAiNw4MDGJpbmFyeQgIc2FtZQxkb3VibGUGAAAAAAAAHEAcaW52YWxpZF9iaW5hcnkIBP8ACGxvbmcEDgxuZXN0ZWQOBAphbHBoYQQCCGJldGEEBAAIdGV4dAoIc2FtZQA=';

    private ?string $databasePath = null;

    protected function setUp(): void
    {
        parent::setUp();

        $databaseDirectory = is_dir('/dev/shm') && is_writable('/dev/shm')
            ? '/dev/shm'
            : sys_get_temp_dir();
        $path = tempnam($databaseDirectory, 'dw-server-memo-restart-');

        if ($path === false) {
            $this->fail('Could not allocate a SQLite database file for the memo restart test.');
        }

        $this->databasePath = $path;
        $this->configurePersistedDatabase();

        $this->artisan('migrate:fresh', ['--force' => true])
            ->assertExitCode(0);

        $this->createNamespace('default');
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');

        if (is_string($this->databasePath)) {
            foreach ([$this->databasePath, $this->databasePath.'-wal', $this->databasePath.'-shm'] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }

        parent::tearDown();
    }

    public function test_continue_as_new_successor_exposes_lossless_memo_after_application_restart(): void
    {
        Queue::fake();

        $workflowType = 'tests.memo-restart-contract-workflow';
        $taskQueue = 'memo-restart-queue';
        $entries = MemoPayload::mapEnvelope([
            'binary' => AvroBinaryValue::fromBytes('same'),
            'double' => 7.0,
            'invalid_binary' => AvroBinaryValue::fromBytes("\xFF\x00"),
            'long' => 7,
            'nested' => [
                'beta' => 2,
                'alpha' => 1,
            ],
            'text' => 'same',
        ]);
        $expectedProjection = [
            'binary' => ['$type' => 'bytes', 'base64' => 'c2FtZQ=='],
            'double' => 7,
            'invalid_binary' => ['$type' => 'bytes', 'base64' => '/wA='],
            'long' => 7,
            'nested' => ['alpha' => 1, 'beta' => 2],
            'text' => 'same',
        ];

        $this->assertSame(
            ['codec' => 'avro', 'blob' => self::MEMO_BLOB],
            $entries,
            'Server must persist the shared canonical memo envelope.',
        );

        $this->registerMemoWorker('memo-worker-before-restart', $taskQueue, $workflowType);

        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-worker-memo-restart',
            'workflow_type' => $workflowType,
            'task_queue' => $taskQueue,
        ], $this->apiHeaders());

        $start->assertCreated();
        $runId = (string) $start->json('run_id');

        $firstPoll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'memo-worker-before-restart',
            'task_queue' => $taskQueue,
        ], $this->workerHeaders());

        $firstPoll->assertOk()
            ->assertJsonPath('task.run_id', $runId);

        $firstTaskId = (string) $firstPoll->json('task.task_id');
        $firstAttempt = (int) $firstPoll->json('task.workflow_task_attempt');

        $this->postJson(
            "/api/worker/workflow-tasks/{$firstTaskId}/complete",
            [
                'lease_owner' => 'memo-worker-before-restart',
                'workflow_task_attempt' => $firstAttempt,
                'commands' => [
                    [
                        'type' => 'upsert_memo',
                        'entries' => $entries,
                    ],
                    [
                        'type' => 'open_signal_wait',
                        'signal_name' => 'finish',
                        'timeout_seconds' => 300,
                    ],
                ],
            ],
            $this->workerHeaders(),
        )->assertOk()
            ->assertJsonPath('run_status', 'waiting');

        // Rebuild Laravel and its model/connection state around the persisted file.
        DB::disconnect('sqlite');
        $this->refreshApplication();
        $this->configurePersistedDatabase();
        Queue::fake();

        $this->getJson("/api/workflows/wf-worker-memo-restart/runs/{$runId}", $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('status', 'waiting')
            ->assertJsonPath('memo', $expectedProjection);

        $signal = $this->postJson('/api/workflows/wf-worker-memo-restart/signal/finish', [
            'request_id' => 'memo-worker-restart-finish',
        ], $this->apiHeaders());
        $signal->assertAccepted();

        $this->registerMemoWorker('memo-worker-after-restart', $taskQueue, $workflowType);

        $replacementPoll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'memo-worker-after-restart',
            'task_queue' => $taskQueue,
        ], $this->workerHeaders());

        $replacementPoll->assertOk()
            ->assertJsonPath('task.run_id', $runId)
            ->assertJsonPath('task.workflow_wait_kind', 'signal')
            ->assertJsonPath('task.signal_name', 'finish');

        $memoEvent = collect($replacementPoll->json('task.history_events'))
            ->first(static fn (array $event): bool => ($event['event_type'] ?? null) === 'MemoUpserted');

        $this->assertIsArray($memoEvent);
        $this->assertSame($entries, $memoEvent['payload']['entries'] ?? null);
        $this->assertSame($entries, $memoEvent['payload']['merged'] ?? null);

        $decoded = MemoPayload::decodeEntries($memoEvent['payload']['entries']);
        $this->assertSame(7, $decoded['long']);
        $this->assertIsInt($decoded['long']);
        $this->assertSame(7.0, $decoded['double']);
        $this->assertIsFloat($decoded['double']);
        $this->assertInstanceOf(AvroBinaryValue::class, $decoded['binary']);
        $this->assertSame('same', $decoded['binary']->bytes);
        $this->assertInstanceOf(AvroBinaryValue::class, $decoded['invalid_binary']);
        $this->assertSame("\xFF\x00", $decoded['invalid_binary']->bytes);
        $this->assertSame('ff00', bin2hex($decoded['invalid_binary']->bytes));
        $this->assertSame('same', $decoded['text']);
        $this->assertSame(['alpha' => 1, 'beta' => 2], $decoded['nested']);

        $replacementTaskId = (string) $replacementPoll->json('task.task_id');
        $replacementAttempt = (int) $replacementPoll->json('task.workflow_task_attempt');

        $this->postJson(
            "/api/worker/workflow-tasks/{$replacementTaskId}/complete",
            [
                'lease_owner' => 'memo-worker-after-restart',
                'workflow_task_attempt' => $replacementAttempt,
                'commands' => [
                    [
                        'type' => 'upsert_memo',
                        'entries' => MemoPayload::mapEnvelope([
                            'stage' => 'continued',
                        ]),
                    ],
                    [
                        'type' => 'continue_as_new',
                        'workflow_type' => $workflowType,
                        'arguments' => Serializer::serializeWithCodec('avro', []),
                    ],
                ],
            ],
            $this->workerHeaders(),
        )->assertOk()
            ->assertJsonPath('run_status', 'completed');

        $mergedProjection = [
            'binary' => ['$type' => 'bytes', 'base64' => 'c2FtZQ=='],
            'double' => 7,
            'invalid_binary' => ['$type' => 'bytes', 'base64' => '/wA='],
            'long' => 7,
            'nested' => ['alpha' => 1, 'beta' => 2],
            'stage' => 'continued',
            'text' => 'same',
        ];

        // Rebuild again so both the successor and its inherited memo are loaded
        // from durable storage rather than retained Eloquent state.
        DB::disconnect('sqlite');
        $this->refreshApplication();
        $this->configurePersistedDatabase();
        Queue::fake();

        $runs = $this->getJson('/api/workflows/wf-worker-memo-restart/runs', $this->apiHeaders());
        $runs->assertOk()
            ->assertJsonCount(2, 'runs')
            ->assertJsonPath('runs.0.run_id', $runId)
            ->assertJsonPath('runs.0.status', 'completed')
            ->assertJsonPath('runs.0.memo', $mergedProjection)
            ->assertJsonPath('runs.1.run_number', 2)
            ->assertJsonPath('runs.1.status', 'pending')
            ->assertJsonPath('runs.1.memo', $mergedProjection);

        $continuedRunId = (string) $runs->json('runs.1.run_id');
        $persistedMemo = WorkflowRun::query()->findOrFail($continuedRunId)->typedMemos();
        $this->assertSame(7, $persistedMemo['long']);
        $this->assertIsInt($persistedMemo['long']);
        $this->assertSame(7.0, $persistedMemo['double']);
        $this->assertIsFloat($persistedMemo['double']);
        $this->assertInstanceOf(AvroBinaryValue::class, $persistedMemo['binary']);
        $this->assertSame('same', $persistedMemo['binary']->bytes);
        $this->assertInstanceOf(AvroBinaryValue::class, $persistedMemo['invalid_binary']);
        $this->assertSame("\xFF\x00", $persistedMemo['invalid_binary']->bytes);
        $this->assertSame('same', $persistedMemo['text']);
        $this->assertSame(['alpha' => 1, 'beta' => 2], $persistedMemo['nested']);
        $this->assertSame('continued', $persistedMemo['stage']);

        $continued = $this->getJson(
            "/api/workflows/wf-worker-memo-restart/runs/{$continuedRunId}",
            $this->apiHeaders(),
        );
        $continued->assertOk()
            ->assertJsonPath('memo', $mergedProjection);
        $this->assertSame($continued->json('memo'), $runs->json('runs.1.memo'));

        $this->registerMemoWorker('memo-worker-successor', $taskQueue, $workflowType);
        $successorPoll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'memo-worker-successor',
            'task_queue' => $taskQueue,
        ], $this->workerHeaders());
        $successorPoll->assertOk()
            ->assertJsonPath('task.run_id', $continuedRunId);

        $this->postJson(
            '/api/worker/workflow-tasks/'.$successorPoll->json('task.task_id').'/complete',
            [
                'lease_owner' => 'memo-worker-successor',
                'workflow_task_attempt' => (int) $successorPoll->json('task.workflow_task_attempt'),
                'commands' => [[
                    'type' => 'complete_workflow',
                ]],
            ],
            $this->workerHeaders(),
        )->assertOk()
            ->assertJsonPath('run_status', 'completed');

        $this->getJson("/api/workflows/wf-worker-memo-restart/runs/{$continuedRunId}", $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('memo', $mergedProjection);
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

    private function registerMemoWorker(string $workerId, string $taskQueue, string $workflowType): void
    {
        $this->postJson('/api/worker/register', [
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
            'worker_id' => $workerId,
            'task_queue' => $taskQueue,
            'runtime' => 'php',
            'supported_workflow_types' => [$workflowType],
            'capabilities' => ['workflow_tasks', 'memo_upserts'],
            'workflow_command_contracts' => [
                $workflowType => [
                    'queries' => [],
                    'query_contracts' => [],
                    'signals' => ['finish'],
                    'signal_contracts' => [[
                        'name' => 'finish',
                        'parameters' => [],
                    ]],
                    'updates' => [],
                    'update_contracts' => [],
                    'update_validators' => [],
                ],
            ],
        ], $this->workerHeaders())->assertCreated();
    }
}
