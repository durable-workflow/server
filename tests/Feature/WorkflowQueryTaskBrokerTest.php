<?php

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use App\Support\ControlPlaneProtocol;
use App\Support\QueryTaskQueueFullException;
use App\Support\ServerPollingCache;
use App\Support\WorkerProtocol;
use App\Support\WorkflowQueryTaskBroker;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Lock as CacheLock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Store as CacheStore;
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

    public function test_worker_query_task_poll_reports_typed_503_when_cache_store_does_not_support_locks(): void
    {
        $this->bindPollingCacheStore(new WorkflowQueryTaskBrokerTestCacheStore);
        $this->registerPythonWorker('python-query-unlocked-worker', 'python-queries', ['python.queryable']);

        $poll = $this->postJson('/api/worker/query-tasks/poll', [
            'worker_id' => 'python-query-unlocked-worker',
            'task_queue' => 'python-queries',
        ], $this->workerHeaders());

        $poll->assertStatus(503)
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertHeaderMissing(ControlPlaneProtocol::HEADER)
            ->assertJsonPath('protocol_version', WorkerProtocol::VERSION)
            ->assertJsonPath('task', null)
            ->assertJsonPath('reason', 'query_task_queue_unavailable')
            ->assertJsonPath('error', 'Query task queue is temporarily unavailable.')
            ->assertJsonPath('namespace', 'default')
            ->assertJsonPath('task_queue', 'python-queries')
            ->assertJsonPath('server_capabilities.query_tasks', true)
            ->assertJsonMissingPath('control_plane');
    }

    public function test_control_plane_query_reports_typed_503_without_orphaning_task_when_cache_store_does_not_support_locks(): void
    {
        Queue::fake();

        $store = new WorkflowQueryTaskBrokerTestCacheStore;
        $this->bindPollingCacheStore($store);
        $this->startRemoteWorkflow('wf-query-task-unlocked-response');
        $this->registerPythonWorker('python-query-unlocked-worker', 'python-queries', ['python.queryable']);

        $query = $this->postJson('/api/workflows/wf-query-task-unlocked-response/query/status', [
            'input' => ['summary'],
        ], $this->apiHeaders());

        $query->assertStatus(503)
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertJsonPath('workflow_id', 'wf-query-task-unlocked-response')
            ->assertJsonPath('query_name', 'status')
            ->assertJsonPath('reason', 'query_task_queue_unavailable')
            ->assertJsonPath('control_plane.operation', 'query')
            ->assertJsonPath('control_plane.operation_name', 'status');

        $this->assertSame([], $store->keysStartingWith('server:workflow-query-task:task:'));
        $this->assertSame([], $store->keysStartingWith('server:workflow-query-task:queue:'));
    }

    public function test_worker_query_task_poll_reports_typed_503_when_queue_lock_times_out(): void
    {
        $this->bindPollingCacheStore(new WorkflowQueryTaskBrokerTestLockTimeoutStore);
        $this->registerPythonWorker('python-query-lock-timeout-worker', 'python-queries', ['python.queryable']);

        $poll = $this->postJson('/api/worker/query-tasks/poll', [
            'worker_id' => 'python-query-lock-timeout-worker',
            'task_queue' => 'python-queries',
        ], $this->workerHeaders());

        $poll->assertStatus(503)
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertHeaderMissing(ControlPlaneProtocol::HEADER)
            ->assertJsonPath('protocol_version', WorkerProtocol::VERSION)
            ->assertJsonPath('task', null)
            ->assertJsonPath('reason', 'query_task_queue_unavailable')
            ->assertJsonPath('error', 'Query task queue is temporarily unavailable.')
            ->assertJsonPath('message', static fn (mixed $message): bool => is_string($message)
                && str_contains($message, 'Timed out waiting for the query task queue lock.'))
            ->assertJsonPath('namespace', 'default')
            ->assertJsonPath('task_queue', 'python-queries')
            ->assertJsonPath('server_capabilities.query_tasks', true)
            ->assertJsonMissingPath('control_plane');
    }

    public function test_control_plane_query_reports_typed_503_without_orphaning_task_when_queue_lock_times_out(): void
    {
        Queue::fake();

        $store = new WorkflowQueryTaskBrokerTestLockTimeoutStore;
        $this->bindPollingCacheStore($store);
        $this->startRemoteWorkflow('wf-query-task-lock-timeout-response');
        $this->registerPythonWorker('python-query-lock-timeout-worker', 'python-queries', ['python.queryable']);

        $query = $this->postJson('/api/workflows/wf-query-task-lock-timeout-response/query/status', [
            'input' => ['summary'],
        ], $this->apiHeaders());

        $query->assertStatus(503)
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertJsonPath('workflow_id', 'wf-query-task-lock-timeout-response')
            ->assertJsonPath('query_name', 'status')
            ->assertJsonPath('reason', 'query_task_queue_unavailable')
            ->assertJsonPath('message', static fn (mixed $message): bool => is_string($message)
                && str_contains($message, 'Timed out waiting for the query task queue lock.'))
            ->assertJsonPath('control_plane.operation', 'query')
            ->assertJsonPath('control_plane.operation_name', 'status');

        $this->assertSame([], $store->keysStartingWith('server:workflow-query-task:task:'));
        $this->assertSame([], $store->keysStartingWith('server:workflow-query-task:queue:'));
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

    private function bindPollingCacheStore(CacheStore $store): void
    {
        $cache = app(ServerPollingCache::class);
        $repository = new CacheRepository($store);
        $property = new \ReflectionProperty(ServerPollingCache::class, 'store');
        $property->setAccessible(true);
        $property->setValue($cache, $repository);

        $this->app->instance(ServerPollingCache::class, $cache);
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

class WorkflowQueryTaskBrokerTestCacheStore implements CacheStore
{
    private ArrayStore $store;

    /** @var array<string, true> */
    private array $keys = [];

    public function __construct()
    {
        $this->store = new ArrayStore;
    }

    public function get($key)
    {
        return $this->store->get($key);
    }

    public function many(array $keys)
    {
        return $this->store->many($keys);
    }

    public function put($key, $value, $seconds)
    {
        $this->keys[(string) $key] = true;

        return $this->store->put($key, $value, $seconds);
    }

    public function putMany(array $values, $seconds)
    {
        foreach (array_keys($values) as $key) {
            $this->keys[(string) $key] = true;
        }

        return $this->store->putMany($values, $seconds);
    }

    public function increment($key, $value = 1)
    {
        return $this->store->increment($key, $value);
    }

    public function decrement($key, $value = 1)
    {
        return $this->store->decrement($key, $value);
    }

    public function forever($key, $value)
    {
        $this->keys[(string) $key] = true;

        return $this->store->forever($key, $value);
    }

    public function touch($key, $seconds)
    {
        return $this->store->touch($key, $seconds);
    }

    public function forget($key)
    {
        unset($this->keys[(string) $key]);

        return $this->store->forget($key);
    }

    public function flush()
    {
        $this->keys = [];

        return $this->store->flush();
    }

    public function getPrefix()
    {
        return $this->store->getPrefix();
    }

    /**
     * @return list<string>
     */
    public function keysStartingWith(string $prefix): array
    {
        $keys = array_values(array_filter(
            array_keys($this->keys),
            static fn (string $key): bool => str_starts_with($key, $prefix),
        ));

        sort($keys);

        return $keys;
    }
}

final class WorkflowQueryTaskBrokerTestLockTimeoutStore extends WorkflowQueryTaskBrokerTestCacheStore implements LockProvider
{
    public function lock($name, $seconds = 0, $owner = null)
    {
        return new WorkflowQueryTaskBrokerTestTimeoutLock((string) $owner);
    }

    public function restoreLock($name, $owner)
    {
        return new WorkflowQueryTaskBrokerTestTimeoutLock((string) $owner);
    }
}

final class WorkflowQueryTaskBrokerTestTimeoutLock implements CacheLock
{
    public function __construct(private readonly string $owner = '') {}

    public function get($callback = null)
    {
        return false;
    }

    public function block($seconds, $callback = null)
    {
        throw new LockTimeoutException;
    }

    public function release()
    {
        return false;
    }

    public function owner()
    {
        return $this->owner;
    }

    public function forceRelease() {}
}
