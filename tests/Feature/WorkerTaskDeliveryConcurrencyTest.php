<?php

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use App\Support\WorkerTaskDelivery;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\WorkflowTask;

class WorkerTaskDeliveryConcurrencyTest extends TestCase
{
    use DatabaseMigrations {
        runDatabaseMigrations as migrateDeliveryDatabase;
    }
    use ServerTestHelpers;

    private ?string $sqliteDatabase = null;

    public function runDatabaseMigrations(): void
    {
        if (config('database.default') === 'sqlite') {
            $path = storage_path('framework/cache/delivery-concurrency-'.bin2hex(random_bytes(6)).'.sqlite');
            File::ensureDirectoryExists(dirname($path));
            touch($path);
            $this->sqliteDatabase = $path;
            config(['database.connections.sqlite.database' => $path]);
            DB::purge('sqlite');
        }
        $this->migrateDeliveryDatabase();
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            if ($this->sqliteDatabase !== null) {
                foreach ([$this->sqliteDatabase, $this->sqliteDatabase.'-wal', $this->sqliteDatabase.'-shm'] as $path) {
                    if (is_file($path)) {
                        unlink($path);
                    }
                }
            }
        }
    }

    #[DataProvider('taskKinds')]
    public function test_independent_processes_accepting_one_offer_start_one_attempt(string $kind): void
    {
        Queue::fake();
        $this->createNamespace('default');
        $this->configureWorkflowTypes(['tests.external-greeting-workflow' => ExternalGreetingWorkflow::class]);
        $run = $this->postJson('/api/workflows', [
            'workflow_type' => 'tests.external-greeting-workflow', 'task_queue' => 'delivery', 'input' => ['Ada'],
        ], $this->apiHeaders())->assertCreated()->json('run_id');
        if ($kind === 'activity') {
            $this->runReadyWorkflowTask($run);
        }
        $task = WorkflowTask::query()->where('workflow_run_id', $run)->where('task_type', $kind)->firstOrFail();
        $this->registerWorker('delivery-worker', $task->queue);
        $worker = WorkerRegistration::query()->where('worker_id', 'delivery-worker')->firstOrFail();
        $worker->forceFill(['registration_token' => str_repeat('a', 32),
            'capabilities' => [...$worker->capabilities, WorkerTaskDelivery::CAPABILITY]])->save();
        $path = '/api/worker/'.$kind.'-tasks/offers';
        $headers = $this->workerHeaders() + [WorkerTaskDelivery::HEADER => WorkerTaskDelivery::VERSION];
        $body = ['worker_id' => 'delivery-worker', 'task_queue' => $task->queue, 'poll_request_id' => 'concurrent',
            'registration_token' => str_repeat('a', 32), 'timeout_seconds' => 0];
        $barrier = storage_path('framework/cache/delivery-start-'.bin2hex(random_bytes(6)));
        $bodyFile = $barrier.'.json';
        $connection = config('database.default');
        $db = config('database.connections.'.$connection);
        $environment = [
            'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DW_AUTH_DRIVER' => 'none',
            'DB_CONNECTION' => $connection, 'DB_HOST' => $db['host'] ?? '',
            'DB_PORT' => (string) ($db['port'] ?? ''), 'DB_DATABASE' => $db['database'],
            'DB_USERNAME' => $db['username'] ?? '', 'DB_PASSWORD' => $db['password'] ?? '',
            'DW_POLLING_CACHE_PATH' => config('server.polling.cache_path'),
        ];
        $processes = [];
        try {
            for ($index = 0; $index < 2; $index++) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/TaskDeliveryAcceptProcess.php')], base_path(), $environment);
                $process->setInput(json_encode(['path' => $path, 'barrier' => $barrier,
                    'body_file' => $bodyFile, 'ready' => $barrier.'.ready'.$index], JSON_THROW_ON_ERROR));
                $process->setTimeout(15);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 10;
            do {
                $ready = 0;
                foreach ($processes as $index => $process) {
                    if (! $process->isRunning()) {
                        $this->fail($process->getErrorOutput());
                    }
                    clearstatcache(true, $barrier.'.ready'.$index);
                    $ready += (int) is_file($barrier.'.ready'.$index);
                }
                if ($ready === 2) {
                    break;
                }
                usleep(1000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $ready, 'Both independent applications must be ready before issuing the offer.');
            $offer = $this->postJson($path, $body, $headers)->assertOk()->assertJsonPath('poll_status', 'offered');
            $body['task_offer_token'] = $offer->json('task_offer.token');
            File::put($bodyFile, json_encode($body, JSON_THROW_ON_ERROR));
            touch($barrier);
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
                $lines = explode("\n", trim($process->getOutput()));
                $result = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
                $diagnostic = json_encode(['status' => $result['status'],
                    'reason' => $result['body']['reason'] ?? null, 'task' => $result['body']['task']['task_id'] ?? null]);
                $this->assertSame(200, $result['status'], $diagnostic);
                $this->assertSame($task->id, $result['body']['task']['task_id'] ?? null, $diagnostic);
            }
            $this->assertSame(1, (int) $task->fresh()->attempt_count);
            $this->assertSame($kind === 'activity' ? 1 : 0, ActivityAttempt::query()->count());
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            File::delete([$barrier, $bodyFile, $barrier.'.ready0', $barrier.'.ready1']);
            DB::purge();
        }
    }

    public static function taskKinds(): array
    {
        return ['workflow' => ['workflow'], 'activity' => ['activity']];
    }
}
