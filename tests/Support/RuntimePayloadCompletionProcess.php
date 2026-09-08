<?php

use App\Models\RuntimeExternalPayload;
use App\Models\RuntimePayloadCompletionBudget;
use App\Models\WorkerRegistration;
use App\Models\WorkflowNamespace;
use App\Support\RuntimeExternalPayloadRegistry;
use App\Support\RuntimePayloadCompletionContext;
use App\Support\StoragePressure;
use App\Support\WorkerProtocol;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Workflow\Serializers\Serializer;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
Queue::fake();

try {
    [$script, $action, $directory, $kind, $variant, $slot, $barrier] = $argv;
    config(['server.storage_admission.file' => $directory.'/pressure.json',
        'server.storage_admission.source' => 'completion-process-test',
        'server.storage_admission.max_age_seconds' => 300,
        'server.external_payload_transport.max_payload_bytes' => 4096,
        'server.external_payload_transport.completion_max_bytes' => strlen(Serializer::serializeWithCodec('avro', str_repeat('alpha', 100)))]);
    $request = static function (string $path, array|string $body, array $headers = []): array {
        $request = Request::create($path, 'POST', [], [], [], array_replace([
            'CONTENT_TYPE' => is_array($body) ? 'application/json' : 'application/octet-stream',
            'HTTP_ACCEPT' => 'application/json', 'HTTP_X_NAMESPACE' => 'default',
            'HTTP_X_DURABLE_WORKFLOW_CONTROL_PLANE_VERSION' => '2',
            'HTTP_'.str_replace('-', '_', strtoupper(WorkerProtocol::HEADER)) => WorkerProtocol::VERSION,
        ], $headers), is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : $body);
        $kernel = app(Illuminate\Contracts\Http\Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return ['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)];
    };
    if (in_array($action, ['init', 'init-independent'], true)) {
        file_put_contents($directory.'/pressure.json', json_encode(['schema' => StoragePressure::SCHEMA,
            'source' => 'completion-process-test', 'observed_at' => time(), 'state' => 'normal'], JSON_THROW_ON_ERROR));
        Artisan::call('migrate:fresh', ['--force' => true]);
        WorkflowNamespace::query()->updateOrCreate(['name' => 'default'], ['status' => 'active', 'retention_days' => 30,
            'external_payload_storage' => ['driver' => 'local', 'enabled' => true, 'threshold_bytes' => 32,
                'config' => ['uri' => 'file://'.$directory.'/objects']]]);
        foreach (range(0, $action === 'init-independent' ? 7 : 0) as $member) {
            $worker = $member === 0 ? 'worker' : 'worker-'.$member;
            WorkerRegistration::query()->create(['worker_id' => $worker, 'namespace' => 'default', 'task_queue' => 'queue',
                'runtime' => 'php', 'supported_workflow_types' => ['test.workflow'], 'supported_activity_types' => ['test.activity'],
                'last_heartbeat_at' => now(), 'status' => 'active']);
            $plural = $kind === 'activity' ? 'activities' : 'workflows';
            $started = $request('/api/'.$plural, [$kind.'_id' => 'test-'.$member, $kind.'_type' => 'test.'.$kind,
                'task_queue' => 'queue', 'input' => []]);
            if ($started['status'] !== 201) {
                throw new RuntimeException(json_encode($started, JSON_THROW_ON_ERROR));
            }
            $polled = $request('/api/worker/'.$kind.'-tasks/poll', ['worker_id' => $worker, 'task_queue' => 'queue']);
            $task = $polled['body']['task'] ?? null;
            if (! is_array($task)) {
                throw new RuntimeException(json_encode($polled, JSON_THROW_ON_ERROR));
            }
            $context = ['schema' => RuntimePayloadCompletionContext::SCHEMA, 'kind' => $kind,
                'task_id' => $task['task_id'], 'attempt' => $task[$kind === 'activity' ? 'activity_attempt_id' : 'workflow_task_attempt'],
                'lease_owner' => $worker, 'operation' => 'complete',
                'slot' => $kind === 'activity' ? ['result'] : ['commands', 0, 'result']];
            $contextFile = $action === 'init-independent' ? '/context-'.$member.'.json' : '/context.json';
            file_put_contents($directory.$contextFile, json_encode($context, JSON_THROW_ON_ERROR));
        }
        file_put_contents($directory.'/pressure.json', json_encode(['schema' => StoragePressure::SCHEMA,
            'source' => 'completion-process-test', 'observed_at' => time(), 'state' => 'draining'], JSON_THROW_ON_ERROR));
        echo '{}';
        exit(0);
    }
    if ($action === 'status-independent') {
        $budgets = RuntimePayloadCompletionBudget::query()->get();
        echo json_encode(['budgets' => $budgets->count(), 'slots' => $budgets->sum(fn ($budget) => count($budget->slots)),
            'objects' => $budgets->sum(fn ($budget) => count($budget->objects)),
            'rows' => RuntimeExternalPayload::query()->count()], JSON_THROW_ON_ERROR);
        exit(0);
    }
    $contextFile = $action === 'upload-independent' ? '/context-'.$slot.'.json' : '/context.json';
    $context = json_decode(file_get_contents($directory.$contextFile), true, flags: JSON_THROW_ON_ERROR);
    if ($action === 'upload-independent') {
        $action = 'upload';
        $slot = '0';
    }
    if ($action === 'upload') {
        if ($slot !== '0') {
            if ($kind === 'activity') {
                $context['operation'] = 'fail';
                $context['slot'] = ['failure', 'details'];
            } else {
                $context['slot'] = ['commands', (int) $slot, 'result'];
            }
        }
        if ($barrier !== '') {
            touch($directory.'/'.$barrier.'.ready');
            $deadline = microtime(true) + 10;
            while (! is_file($directory.'/go')) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Upload barrier timed out.');
                }
                usleep(10000);
            }
        }
        $bytes = Serializer::serializeWithCodec('avro', str_repeat($variant, 100));
        echo json_encode($request('/api/external-payloads/v1', $bytes, [
            'HTTP_X_DURABLE_WORKFLOW_PAYLOAD_COMPLETION' => json_encode($context, JSON_THROW_ON_ERROR),
            'HTTP_X_DURABLE_WORKFLOW_PAYLOAD_CODEC' => 'avro', 'HTTP_X_DURABLE_WORKFLOW_PAYLOAD_SIZE' => (string) strlen($bytes),
            'HTTP_X_DURABLE_WORKFLOW_PAYLOAD_SHA256' => hash('sha256', $bytes),
        ]), JSON_THROW_ON_ERROR);
    } elseif ($action === 'complete') {
        $row = RuntimeExternalPayload::query()->sole();
        $reference = app(RuntimeExternalPayloadRegistry::class)->referenceForUri('default', $row->storage_uri);
        $envelope = ['codec' => 'avro', 'external_payload' => $reference];
        $body = ['lease_owner' => 'worker'];
        if ($kind === 'activity') {
            $body += ['activity_attempt_id' => $context['attempt'], 'result' => $envelope];
        } else {
            $body += ['workflow_task_attempt' => $context['attempt'],
                'commands' => [['type' => 'complete_workflow', 'sequence' => 1, 'result' => $envelope]]];
        }
        echo json_encode($request('/api/worker/'.$kind.'-tasks/'.$context['task_id'].'/complete', $body), JSON_THROW_ON_ERROR);
    } elseif ($action === 'status') {
        $budget = RuntimePayloadCompletionBudget::query()->sole();
        echo json_encode(['budgets' => RuntimePayloadCompletionBudget::query()->count(),
            'slots' => count($budget->slots), 'objects' => count($budget->objects),
            'bytes' => array_sum($budget->objects), 'rows' => RuntimeExternalPayload::query()->count()], JSON_THROW_ON_ERROR);
    } else {
        throw new RuntimeException('Unknown completion probe action.');
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    exit(1);
}
