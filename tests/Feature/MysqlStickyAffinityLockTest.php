<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

class MysqlStickyAffinityLockTest extends TestCase
{
    use ServerTestHelpers;

    private ?PDO $databaseAdmin = null;

    private ?string $databaseName = null;

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('DW_TEST_COMPLETION_MYSQL_HOST');
        if (! $host) {
            $this->markTestSkipped('Set DW_TEST_COMPLETION_MYSQL_HOST to a disposable database test server.');
        }

        $port = getenv('DW_TEST_COMPLETION_MYSQL_PORT') ?: '3306';
        $user = getenv('DW_TEST_COMPLETION_MYSQL_USER') ?: 'root';
        $password = getenv('DW_TEST_COMPLETION_MYSQL_PASSWORD') ?: '';
        $this->databaseAdmin = new PDO('mysql:host='.$host.';port='.$port, $user, $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->databaseName = 'dw_sticky_affinity_'.bin2hex(random_bytes(6));
        $this->databaseAdmin->exec('CREATE DATABASE '.$this->databaseName);
        config(['database.default' => 'mysql', 'database.connections.mysql.url' => null,
            'database.connections.mysql.host' => $host, 'database.connections.mysql.port' => $port,
            'database.connections.mysql.database' => $this->databaseName,
            'database.connections.mysql.username' => $user, 'database.connections.mysql.password' => $password]);
        DB::purge('mysql');
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
        Queue::fake();
        $this->createNamespace('default');
    }

    protected function tearDown(): void
    {
        if ($this->databaseAdmin !== null && $this->databaseName !== null) {
            DB::disconnect('mysql');
            $this->databaseAdmin->exec('DROP DATABASE '.$this->databaseName);
        }
        parent::tearDown();
    }

    public static function completionModes(): array
    {
        return ['terminal' => [true], 'waiting with a ready successor' => [false]];
    }

    #[DataProvider('completionModes')]
    public function test_sticky_completion_does_not_wait_for_another_runs_task(bool $terminal): void
    {
        $this->registerWorker('sticky-owner', 'sticky-a');
        $this->registerWorker('neighbor-owner', 'sticky-b');
        $parent = $this->start('parent', 'sticky-a');
        $neighbor = $this->start('neighbor', 'sticky-b');
        $claim = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'sticky-owner', 'task_queue' => 'sticky-a', 'build_id' => 'sticky-build',
        ], $this->workerHeaders())->assertOk()->json('task');
        $this->assertIsArray($claim);
        $parentTask = WorkflowTask::query()->findOrFail($claim['task_id']);

        // A run with many completed tasks and few ready tasks makes the shared
        // status index attractive, as in the captured production-schema deadlock.
        // These are storage fixtures, not claimed execution histories.
        $historical = [];
        for ($i = 0; $i < 500; $i++) {
            $historical[] = [
                'id' => strtolower((string) Str::ulid()), 'workflow_run_id' => $parent['run_id'],
                'namespace' => 'default', 'task_type' => 'workflow', 'status' => 'completed',
                'queue' => 'sticky-a', 'attempt_count' => 1, 'repair_count' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        WorkflowTask::query()->insert($historical);
        $successor = null;
        if (! $terminal) {
            $successor = $parentTask->replicate();
            $successor->forceFill([
                'id' => strtolower((string) Str::ulid()), 'status' => TaskStatus::Ready,
                'lease_owner' => null, 'leased_at' => null, 'lease_expires_at' => null,
                'attempt_count' => 0,
            ])->save();
        }
        DB::statement('ANALYZE TABLE workflow_tasks');
        $neighborTask = WorkflowTask::query()->where('workflow_run_id', $neighbor['run_id'])->sole();
        $beforeTask = $neighborTask->getRawOriginal();
        $beforeHistory = $this->history($neighbor['run_id']);
        $connection = DB::connection()->getConfig();
        $holder = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $connection['host'], $connection['port'], $connection['database']),
            $connection['username'], $connection['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $holder->beginTransaction();
        try {
            // A real independent connection holds the same primary-row lock
            // needed by an in-flight claim. Completion of another run must not
            // need that row, even when MySQL chooses the shared status index.
            $lock = $holder->prepare('SELECT id FROM workflow_tasks WHERE id = ? FOR UPDATE');
            $lock->execute([$neighborTask->id]);
            $this->assertSame($neighborTask->id, $lock->fetchColumn());

            $completion = $this->postJson('/api/worker/workflow-tasks/'.$parentTask->id.'/complete', [
                'lease_owner' => 'sticky-owner', 'workflow_task_attempt' => $claim['workflow_task_attempt'],
                'commands' => $terminal ? [['type' => 'complete_workflow', 'result' => Serializer::serializeWithCodec('avro', 7)]]
                    : [['type' => 'start_timer', 'delay_seconds' => 120]],
                'sticky_cache' => [
                    'worker_id' => 'sticky-owner', 'workflow_id' => 'parent', 'run_id' => $parent['run_id'],
                    'build_id' => 'sticky-build', 'ttl_seconds' => 120,
                    'metrics' => ['hit' => 1, 'miss' => 2, 'eviction' => 0, 'forced_cold_replay' => 0],
                ],
            ], $this->workerHeaders());

            $this->assertSame($beforeTask, $neighborTask->fresh()->getRawOriginal());
            $this->assertSame($beforeHistory, $this->history($neighbor['run_id']));
            $this->assertSame(200, $completion->status(), (string) $completion->json('reason'));
            $completion->assertJsonPath('outcome', 'completed')->assertJsonPath('recorded', true);
        } finally {
            $holder->rollBack();
        }

        $run = WorkflowRun::query()->findOrFail($parent['run_id']);
        if ($terminal) {
            $this->assertNull($run->sticky_worker_id);
            $this->assertNull($run->sticky_until);
        } else {
            $this->assertSame('sticky-owner', $run->sticky_worker_id);
            $this->assertNotNull($run->sticky_until);
            $this->assertSame('sticky-owner', $successor->fresh()->sticky_worker_id);
            $this->assertSame($run->sticky_until->format('Y-m-d H:i:s.u'),
                $successor->fresh()->sticky_until->format('Y-m-d H:i:s.u'));
            $this->assertNull($successor->fresh()->sticky_replay_mode);
            $this->assertNull($successor->fresh()->sticky_claimed_at);
        }
        $this->assertSame(['hit' => 1, 'miss' => 2, 'eviction' => 0, 'forced_cold_replay' => 0],
            WorkerRegistration::query()->where('worker_id', 'sticky-owner')->sole()->process_metrics['sticky_cache']);

        $neighborClaim = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'neighbor-owner', 'task_queue' => 'sticky-b', 'build_id' => 'sticky-build',
        ], $this->workerHeaders())->assertOk()->json('task');
        $this->assertSame($neighborTask->id, $neighborClaim['task_id']);
        $this->assertSame(1, $neighborClaim['workflow_task_attempt']);
        $this->assertSame($beforeHistory, $this->history($neighbor['run_id']));
        $this->postJson('/api/worker/workflow-tasks/'.$neighborTask->id.'/complete', [
            'lease_owner' => 'neighbor-owner', 'workflow_task_attempt' => 1,
            'commands' => [['type' => 'complete_workflow', 'result' => Serializer::serializeWithCodec('avro', 9)]],
        ], $this->workerHeaders())->assertOk()
            ->assertJsonPath('outcome', 'completed')->assertJsonPath('recorded', true);
        $this->assertSame($beforeHistory, array_slice($this->history($neighbor['run_id']), 0, count($beforeHistory)));
        $this->assertSame(0, $neighborTask->fresh()->repair_count);
    }

    private function registerWorker(string $id, string $queue): void
    {
        $manifest = $this->portableWorkerAffinityRefusalManifest();
        $manifest['sticky_execution'] = [
            'supported' => true, 'minimum_protocol_version' => '1.18', 'implementation' => 'test',
        ];
        $this->postJson('/api/worker/register', [
            'worker_id' => $id, 'task_queue' => $queue, 'runtime' => 'php',
            'sdk_version' => 'synthetic/test', 'build_id' => 'sticky-build',
            'supported_workflow_types' => ['sticky.scope.workflow'],
            'capabilities' => ['sticky_execution'], 'capability_manifest' => $manifest,
        ], $this->workerHeaders())->assertCreated();
    }

    private function start(string $id, string $queue): array
    {
        return $this->postJson('/api/workflows', [
            'workflow_id' => $id, 'workflow_type' => 'sticky.scope.workflow', 'task_queue' => $queue,
        ], $this->apiHeaders())->assertCreated()->json();
    }

    private function history(string $runId): array
    {
        return WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->orderBy('sequence')
            ->get(['id', 'sequence', 'event_type', 'payload', 'recorded_at'])->toArray();
    }
}
