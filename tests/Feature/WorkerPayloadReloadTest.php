<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\Serializers\AvroBinaryValue;
use Workflow\Serializers\Serializer;
use Workflow\V2\Models\WorkflowTask;

final class WorkerPayloadReloadTest extends TestCase
{
    use ServerTestHelpers;

    private ?string $databasePath = null;

    protected function setUp(): void
    {
        parent::setUp();
        $path = tempnam(sys_get_temp_dir(), 'dw-worker-payload-reload-');
        $this->assertIsString($path);
        $this->databasePath = $path;
        $this->configureDatabase();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->createNamespace('default');
        Queue::fake();
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        if ($this->databasePath !== null) {
            foreach ([$this->databasePath, $this->databasePath.'-wal', $this->databasePath.'-shm'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
        parent::tearDown();
    }

    #[DataProvider('payloadShapes')]
    public function test_rejected_completion_can_be_repaired_and_read_from_a_new_process(bool $envelope): void
    {
        $this->registerWorker('reload-worker', 'reload-queue');
        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'payload-reload', 'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'reload-queue',
        ], $this->apiHeaders())->assertCreated();
        $task = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'reload-worker', 'task_queue' => 'reload-queue',
        ], $this->workerHeaders())->assertOk()->assertJsonPath('poll_status', 'leased')->json('task');
        $body = ['lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'],
            'commands' => [['type' => 'complete_workflow', 'result' => 'not Avro']]];
        $url = '/api/worker/workflow-tasks/'.$task['task_id'].'/complete';
        $this->postJson($url, $body, $this->workerHeaders())->assertUnprocessable();

        DB::disconnect('sqlite');
        $this->refreshApplication();
        $this->configureDatabase();
        Queue::fake();
        $this->assertSame('leased', WorkflowTask::query()->findOrFail($task['task_id'])->status->value);

        $value = ['large' => str_repeat('x', 1024 * 1024), 'long' => 7, 'double' => 7.0,
            'binary' => AvroBinaryValue::fromBytes("\xff\0"),
            'nested' => ['codec' => 'json', 'blob' => 'customer text, not an envelope']];
        $blob = Serializer::serializeWithCodec('avro', $value);
        $body['commands'][0]['result'] = $envelope ? ['codec' => 'avro', 'blob' => $blob] : $blob;
        $this->postJson($url, $body, $this->workerHeaders())->assertOk()->assertJsonPath('run_status', 'completed');
        DB::disconnect('sqlite');

        $code = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle(Illuminate\Http\Request::create(
    '/api/workflows/payload-reload/runs/'.$argv[1], 'GET', server: [
        'HTTP_ACCEPT' => 'application/json', 'HTTP_X_NAMESPACE' => 'default',
        'HTTP_X_DURABLE_WORKFLOW_CONTROL_PLANE_VERSION' => '2',
    ],
));
$run = Workflow\V2\Models\WorkflowRun::query()->findOrFail($argv[1]);
$value = Workflow\Serializers\Serializer::unserializeWithCodec($run->payload_codec, $run->output);
$projection = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
echo json_encode([
    'status' => $response->getStatusCode(),
    'stored_sha256' => hash('sha256', $run->output),
    'large_bytes' => strlen($projection['output']['large'] ?? ''),
    'long_type' => get_debug_type($value['long']),
    'double_type' => get_debug_type($value['double']),
    'binary_hex' => bin2hex($value['binary']->bytes),
    'nested' => $value['nested'],
], JSON_THROW_ON_ERROR);
PHP;
        $process = new Process([PHP_BINARY, '-r', $code, $start->json('run_id')], base_path(), [
            'APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'),
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $this->databasePath,
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'database',
            'DW_AUTH_DRIVER' => 'none',
        ]);
        $process->setTimeout(30)->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
        $this->assertSame(['status' => 200, 'stored_sha256' => hash('sha256', $blob),
            'large_bytes' => 1024 * 1024, 'long_type' => 'int', 'double_type' => 'float', 'binary_hex' => 'ff00',
            'nested' => $value['nested']], json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
    }

    public static function payloadShapes(): array
    {
        return ['serialized' => [false], 'envelope' => [true]];
    }

    private function configureDatabase(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $this->databasePath,
            'server.polling.timeout' => 0]);
        DB::purge('sqlite');
    }
}
