<?php

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use App\Support\ControlPlaneProtocol;
use App\Support\QueryTaskQueueFullException;
use App\Support\ServerPollingCache;
use App\Support\WorkerProtocol;
use App\Support\WorkflowQueryTaskBroker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Models\WorkflowRun;

class WorkflowQueryTaskBrokerTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'server.polling.timeout' => 0,
            'server.query_tasks.timeout' => 0,
        ]);

        $this->createNamespace('default');
    }

    public function test_worker_can_poll_and_complete_worker_routed_query_task(): void
    {
        Queue::fake();

        $run = $this->startRemoteWorkflow('wf-query-task-complete');
        $this->registerPythonWorker('python-query-worker', 'python-queries', ['python.queryable']);

        /** @var WorkflowQueryTaskBroker $broker */
        $broker = app(WorkflowQueryTaskBroker::class);
        $queryArguments = [
            'codec' => 'avro',
            'blob' => Serializer::serializeWithCodec('avro', ['summary']),
        ];
        $task = $broker->enqueue('default', $run, 'status', [
            'codec' => $queryArguments['codec'],
            'blob' => $queryArguments['blob'],
        ]);

        $poll = $this->postJson('/api/worker/query-tasks/poll', [
            'worker_id' => 'python-query-worker',
            'task_queue' => 'python-queries',
        ], $this->workerHeaders());

        $poll->assertOk()
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertHeaderMissing(ControlPlaneProtocol::HEADER)
            ->assertJsonPath('protocol_version', WorkerProtocol::VERSION)
            ->assertJsonPath('server_capabilities.query_tasks', true)
            ->assertJsonPath('task.query_task_id', $task['query_task_id'])
            ->assertJsonPath('task.query_task_attempt', 1)
            ->assertJsonPath('task.workflow_id', 'wf-query-task-complete')
            ->assertJsonPath('task.run_id', $run->id)
            ->assertJsonPath('task.workflow_type', 'python.queryable')
            ->assertJsonPath('task.query_name', 'status')
            ->assertJsonPath('task.task_queue', 'python-queries')
            ->assertJsonPath('task.lease_owner', 'python-query-worker')
            ->assertJsonPath('task.query_arguments.codec', 'avro');

        $pollTask = $poll->json('task');

        $this->assertSame(
            ['Ada'],
            Serializer::unserializeWithCodec(
                (string) $pollTask['workflow_arguments']['codec'],
                (string) $pollTask['workflow_arguments']['blob'],
            ),
        );
        $this->assertSame(
            ['summary'],
            Serializer::unserializeWithCodec(
                (string) $pollTask['query_arguments']['codec'],
                (string) $pollTask['query_arguments']['blob'],
            ),
        );
        $this->assertContains(
            'WorkflowStarted',
            array_column($pollTask['history_events'], 'event_type'),
        );

        $complete = $this->postJson("/api/worker/query-tasks/{$task['query_task_id']}/complete", [
            'lease_owner' => 'python-query-worker',
            'query_task_attempt' => 1,
            'result' => ['status' => 'ready'],
            'result_envelope' => [
                'codec' => 'avro',
                'blob' => Serializer::serializeWithCodec('avro', ['status' => 'ready']),
            ],
        ], $this->workerHeaders());

        $complete->assertOk()
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertJsonPath('query_task_id', $task['query_task_id'])
            ->assertJsonPath('query_task_attempt', 1)
            ->assertJsonPath('outcome', 'completed');

        $stored = $broker->task((string) $task['query_task_id']);

        $this->assertSame('completed', $stored['status'] ?? null);
        $this->assertSame(['status' => 'ready'], $stored['result'] ?? null);
        $this->assertSame('avro', $stored['result_envelope']['codec'] ?? null);
        $this->assertSame(
            ['status' => 'ready'],
            Serializer::unserializeWithCodec(
                'avro',
                (string) ($stored['result_envelope']['blob'] ?? ''),
            ),
        );
    }

    public function test_control_plane_query_routes_to_python_worker_and_times_out_without_result(): void
    {
        Queue::fake();

        $this->startRemoteWorkflow('wf-query-task-timeout');
        $this->registerPythonWorker('python-query-timeout-worker', 'python-queries', ['python.queryable']);

        $query = $this->postJson('/api/workflows/wf-query-task-timeout/query/status', [
            'input' => ['summary'],
        ], $this->apiHeaders());

        $query->assertStatus(504)
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertJsonPath('workflow_id', 'wf-query-task-timeout')
            ->assertJsonPath('query_name', 'status')
            ->assertJsonPath('reason', 'query_worker_timeout')
            ->assertJsonPath('control_plane.operation', 'query')
            ->assertJsonPath('control_plane.operation_name', 'status');

        $pollAfterTimeout = $this->postJson('/api/worker/query-tasks/poll', [
            'worker_id' => 'python-query-timeout-worker',
            'task_queue' => 'python-queries',
        ], $this->workerHeaders());

        $pollAfterTimeout->assertOk()
            ->assertJsonPath('task', null);
    }

    public function test_query_task_enqueue_rejects_when_per_queue_pending_limit_is_reached(): void
    {
        Queue::fake();
        config(['server.query_tasks.max_pending_per_queue' => 1]);

        $run = $this->startRemoteWorkflow('wf-query-task-enqueue-limit');

        /** @var WorkflowQueryTaskBroker $broker */
        $broker = app(WorkflowQueryTaskBroker::class);

        $broker->enqueue('default', $run, 'status', $this->queryArguments());

        $this->expectException(QueryTaskQueueFullException::class);

        $broker->enqueue('default', $run, 'status', $this->queryArguments());
    }

    public function test_control_plane_query_reports_queue_full_when_pending_limit_is_reached(): void
    {
        Queue::fake();
        config(['server.query_tasks.max_pending_per_queue' => 1]);

        $run = $this->startRemoteWorkflow('wf-query-task-full-response');
        $this->registerPythonWorker('python-query-full-worker', 'python-queries', ['python.queryable']);

        /** @var WorkflowQueryTaskBroker $broker */
        $broker = app(WorkflowQueryTaskBroker::class);
        $broker->enqueue('default', $run, 'status', $this->queryArguments());

        $query = $this->postJson('/api/workflows/wf-query-task-full-response/query/status', [
            'input' => ['summary'],
        ], $this->apiHeaders());

        $query->assertStatus(429)
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertJsonPath('workflow_id', 'wf-query-task-full-response')
            ->assertJsonPath('query_name', 'status')
            ->assertJsonPath('reason', 'query_task_queue_full')
            ->assertJsonPath('control_plane.operation', 'query')
            ->assertJsonPath('control_plane.operation_name', 'status');
    }

    public function test_concurrent_query_task_enqueues_are_atomic_for_file_cache_backend(): void
    {
        $cachePath = sys_get_temp_dir().'/dw-server-query-task-race-'.bin2hex(random_bytes(5));
        $readyDir = $cachePath.'-ready';
        $barrierPath = $cachePath.'.release';
        $processCount = 8;
        $limit = 3;
        $processes = [];

        File::ensureDirectoryExists($cachePath);
        File::ensureDirectoryExists($readyDir);

        config([
            'cache.default' => 'file',
            'server.polling.cache_path' => $cachePath,
            'server.query_tasks.max_pending_per_queue' => $limit,
        ]);

        try {
            for ($i = 0; $i < $processCount; $i++) {
                $process = new Process([
                    PHP_BINARY,
                    base_path('tests/Fixtures/query_task_enqueue_worker.php'),
                    $cachePath,
                    $barrierPath,
                    $readyDir,
                    (string) $limit,
                    'default',
                    'python-queries',
                    'worker-'.$i,
                ], base_path());
                $process->setTimeout(30);
                $process->start();

                $processes[] = $process;
            }

            $this->waitForReadyQueryTaskEnqueueWorkers($readyDir, $processCount, $processes);

            touch($barrierPath);

            $results = array_map(
                fn (Process $process): array => $this->queryTaskEnqueueWorkerResult($process),
                $processes,
            );

            $errors = array_values(array_filter(
                $results,
                static fn (array $result): bool => ($result['status'] ?? null) === 'error',
            ));

            $this->assertSame([], $errors);

            $enqueuedIds = array_values(array_map(
                static fn (array $result): string => (string) $result['query_task_id'],
                array_filter($results, static fn (array $result): bool => ($result['status'] ?? null) === 'enqueued'),
            ));
            $fullResults = array_values(array_filter(
                $results,
                static fn (array $result): bool => ($result['status'] ?? null) === 'full',
            ));

            $this->assertCount($limit, $enqueuedIds);
            $this->assertCount($processCount - $limit, $fullResults);

            /** @var ServerPollingCache $cache */
            $cache = app(ServerPollingCache::class);
            $store = $cache->store();
            $queueIds = $store->get('server:workflow-query-task:queue:'.sha1('default|python-queries'));

            $this->assertIsArray($queueIds);
            sort($queueIds);
            sort($enqueuedIds);

            $this->assertSame($enqueuedIds, $queueIds);

            foreach ($queueIds as $queryTaskId) {
                $task = $store->get('server:workflow-query-task:task:'.$queryTaskId);

                $this->assertIsArray($task);
                $this->assertSame('pending', $task['status'] ?? null);
            }
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(0);
                }
            }

            File::deleteDirectory($cachePath);
            File::deleteDirectory($readyDir);
            @unlink($barrierPath);
        }
    }

    private function startRemoteWorkflow(string $workflowId): WorkflowRun
    {
        $start = $this->postJson('/api/workflows', [
            'workflow_id' => $workflowId,
            'workflow_type' => 'python.queryable',
            'task_queue' => 'python-queries',
            'input' => ['Ada'],
        ], $this->apiHeaders());

        $start->assertCreated();

        return WorkflowRun::query()->findOrFail((string) $start->json('run_id'));
    }

    /**
     * @return array{codec: string, blob: string}
     */
    private function queryArguments(): array
    {
        return [
            'codec' => 'avro',
            'blob' => Serializer::serializeWithCodec('avro', ['summary']),
        ];
    }

    /**
     * @param  list<string>  $supportedWorkflowTypes
     */
    private function registerPythonWorker(
        string $workerId,
        string $taskQueue,
        array $supportedWorkflowTypes,
    ): void {
        WorkerRegistration::query()->updateOrCreate(
            ['worker_id' => $workerId, 'namespace' => 'default'],
            [
                'task_queue' => $taskQueue,
                'runtime' => 'python',
                'sdk_version' => 'durable-workflow-python/0.2.0',
                'supported_workflow_types' => $supportedWorkflowTypes,
                'supported_activity_types' => [],
                'last_heartbeat_at' => now(),
                'status' => 'active',
            ],
        );
    }

    /**
     * @param  list<Process>  $processes
     */
    private function waitForReadyQueryTaskEnqueueWorkers(string $readyDir, int $expected, array $processes): void
    {
        $deadline = microtime(true) + 15;

        while ($this->readyQueryTaskEnqueueWorkerCount($readyDir) < $expected && microtime(true) < $deadline) {
            foreach ($processes as $process) {
                if (! $process->isRunning()) {
                    $this->fail("Query-task enqueue worker exited before the barrier.\n".$process->getOutput().$process->getErrorOutput());
                }
            }

            usleep(10000);
        }

        $this->assertSame($expected, $this->readyQueryTaskEnqueueWorkerCount($readyDir));
    }

    private function readyQueryTaskEnqueueWorkerCount(string $readyDir): int
    {
        return count(glob($readyDir.'/*.ready') ?: []);
    }

    /**
     * @return array<string, mixed>
     */
    private function queryTaskEnqueueWorkerResult(Process $process): array
    {
        $process->wait();

        $output = trim($process->getOutput());
        $decoded = json_decode($output, true);

        if (! $process->isSuccessful() || ! is_array($decoded)) {
            return [
                'status' => 'error',
                'exit_code' => $process->getExitCode(),
                'stdout' => $output,
                'stderr' => trim($process->getErrorOutput()),
            ];
        }

        return $decoded;
    }
}
