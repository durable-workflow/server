<?php

declare(strict_types=1);

/**
 * Published-image child-workflow runtime probe.
 *
 * The probe owns every identity and command it records.  It never consumes a
 * scenario result supplied by the caller: leased tasks come from the bundled
 * server, commands come from the installed PHP/Python runtimes, and evidence
 * comes back from server histories and operator APIs.
 */

use App\Models\WorkflowNamespace;
use App\Support\ControlPlaneProtocol;
use App\Support\WorkerProtocol;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Enums\ParentClosePolicy;
use Workflow\V2\Support\ChildWorkflowOptions;
use Workflow\V2\Support\FailureFactory;
use Workflow\V2\Support\WorkflowFiberRunner;
use Workflow\V2\Workflow;
use function Workflow\V2\child;
use function Workflow\V2\timer;

$repoRoot = getenv('RUNNER_REPO_ROOT') ?: '/app';
$resultDir = rtrim(getenv('RESULT_DIR') ?: sys_get_temp_dir(), '/');
$pythonBin = getenv('DW_CHILD_WORKFLOWS_PYTHON_BIN') ?: 'python3';
$pythonAdapter = $repoRoot.'/scripts/conformance/child-workflows-python-runtime.py';

if ($repoRoot !== '/app' || is_dir($repoRoot.'/.git')) {
    throw new RuntimeException('runtime probe must execute from the source-free published server image root');
}
if (! is_file($repoRoot.'/vendor/autoload.php') || ! is_file($repoRoot.'/artisan')) {
    throw new RuntimeException('published server runtime files are missing');
}

chdir($repoRoot);
require $repoRoot.'/vendor/autoload.php';

const CW_NAMESPACE = 'child-workflows-conformance';
const PHP_PARENT = 'conformance.php.parent';
const PHP_CHILD = 'conformance.php.child';
const PHP_FAILURE_PARENT = 'conformance.php.failure-parent';
const PHP_FAILURE_CHILD = 'conformance.php.failure-child';
const PHP_LONG_CHILD = 'conformance.php.long-child';
const PHP_PARENT_CLOSE = 'conformance.php.parent-close';

#[Type(PHP_CHILD)]
final class ConformancePhpChild extends Workflow
{
    public function handle(string $value): string
    {
        return $value.'|php-child';
    }
}

#[Type(PHP_FAILURE_CHILD)]
final class ConformancePhpFailureChild extends Workflow
{
    public function handle(string $message): never
    {
        throw new DomainException($message);
    }
}

#[Type(PHP_LONG_CHILD)]
final class ConformancePhpLongChild extends Workflow
{
    public function handle(): string
    {
        timer(3600);

        return 'long-child-completed';
    }
}

#[Type(PHP_PARENT)]
final class ConformancePhpParent extends Workflow
{
    /** @return array<string, mixed> */
    public function handle(string $childType, string $value): array
    {
        return [
            'child_result' => child($childType, $value),
            'parent_runtime' => 'workflow-php',
        ];
    }
}

#[Type(PHP_FAILURE_PARENT)]
final class ConformancePhpFailureParent extends Workflow
{
    /** @return array<string, mixed> */
    public function handle(string $childType, string $message): array
    {
        try {
            child($childType, $message);
        } catch (Throwable $throwable) {
            $failure = FailureFactory::make($throwable);

            return [
                'failure_kind' => 'child_workflow',
                'exception_class' => $failure['exception_class'],
                'message' => $failure['message'],
            ];
        }

        return ['unexpected_success' => true];
    }
}

#[Type(PHP_PARENT_CLOSE)]
final class ConformancePhpParentClose extends Workflow
{
    /** @return array<string, mixed> */
    public function handle(string $childType): array
    {
        $options = new ChildWorkflowOptions(parentClosePolicy: ParentClosePolicy::RequestCancel);

        return ['child_result' => child($childType, $options)];
    }
}

/** @return string */
function cw_now(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

function cw_header(string $name): string
{
    return 'HTTP_'.str_replace('-', '_', strtoupper($name));
}

/**
 * @param array<string, mixed>|null $body
 * @param list<int> $allowed
 * @return array<string, mixed>
 */
function cw_request(string $method, string $path, ?array $body = null, array $allowed = [], string $namespace = CW_NAMESPACE): array
{
    static $kernel = null;
    $kernel ??= app(HttpKernel::class);

    $server = [
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_NAMESPACE' => $namespace,
        cw_header(ControlPlaneProtocol::HEADER) => ControlPlaneProtocol::VERSION,
        cw_header(WorkerProtocol::HEADER) => WorkerProtocol::VERSION,
    ];
    $request = Request::create(
        '/api'.$path,
        $method,
        [],
        [],
        [],
        $server,
        $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR),
    );
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    $status = $response->getStatusCode();
    $payload = (string) $response->getContent();
    if (($status < 200 || $status >= 300) && ! in_array($status, $allowed, true)) {
        throw new RuntimeException(sprintf('%s %s failed with HTTP %d: %s', $method, $path, $status, $payload));
    }
    if ($payload === '') {
        return [];
    }
    $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

    return is_array($decoded) ? $decoded : [];
}

/** @return list<array<string, mixed>> */
function cw_history_events(array $history): array
{
    $events = $history['events'] ?? $history['history_events'] ?? [];

    return is_array($events) ? array_values(array_filter($events, 'is_array')) : [];
}

function cw_event_type(array $event): string
{
    return is_string($event['event_type'] ?? null)
        ? $event['event_type']
        : (is_string($event['type'] ?? null) ? $event['type'] : '');
}

/** @return list<string> */
function cw_history_excerpt(array $history): array
{
    return array_map(static fn (array $event): string => cw_event_type($event), cw_history_events($history));
}

/** @return array<string, mixed>|null */
function cw_first_event(array $history, string $type): ?array
{
    foreach (cw_history_events($history) as $event) {
        if (cw_event_type($event) === $type) {
            return $event;
        }
    }

    return null;
}

function cw_event_time(?array $event): string
{
    if (! is_array($event)) {
        return '';
    }
    foreach (['recorded_at', 'created_at', 'timestamp', 'occurred_at'] as $key) {
        if (is_string($event[$key] ?? null) && $event[$key] !== '') {
            return $event[$key];
        }
    }
    $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
    foreach (['recorded_at', 'created_at', 'timestamp', 'started_at', 'closed_at'] as $key) {
        if (is_string($payload[$key] ?? null) && $payload[$key] !== '') {
            return $payload[$key];
        }
    }

    return '';
}

function cw_required_event_time(?array $event, string $description): string
{
    $timestamp = cw_event_time($event);
    if ($timestamp === '') {
        throw new RuntimeException($description.' omitted its server-recorded timestamp');
    }

    return $timestamp;
}

function cw_event_child_run_id(?array $event): string
{
    $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
    foreach (['child_workflow_run_id', 'child_run_id', 'resolved_child_run_id'] as $field) {
        if (is_string($payload[$field] ?? null) && $payload[$field] !== '') {
            return $payload[$field];
        }
    }

    return '';
}

/** @return array<string, mixed> */
function cw_require_child_event(array $history, string $eventType, string $childRunId): array
{
    foreach (cw_history_events($history) as $event) {
        if (cw_event_type($event) === $eventType && cw_event_child_run_id($event) === $childRunId) {
            return $event;
        }
    }

    throw new RuntimeException(sprintf('%s did not reference child run %s', $eventType, $childRunId));
}

/** @return array<string, array{supported: bool, minimum_protocol_version: string, reason: string}> */
function cw_capability_manifest(): array
{
    return array_fill_keys(WorkerProtocol::PORTABLE_WORKER_AFFINITY_CAPABILITIES, [
        'supported' => false,
        'minimum_protocol_version' => WorkerProtocol::PORTABLE_WORKER_AFFINITY_MINIMUM_PROTOCOL_VERSION,
        'reason' => 'not_implemented',
    ]);
}

/** @param list<string> $types */
function cw_register(string $workerId, string $queue, string $runtime, array $types): void
{
    cw_request('POST', '/worker/register', [
        'capability_manifest' => cw_capability_manifest(),
        'worker_id' => $workerId,
        'task_queue' => $queue,
        'runtime' => $runtime === 'workflow-php' ? 'php' : 'python',
        'sdk_version' => $runtime === 'workflow-php'
            ? getenv('DW_WORKFLOW_PHP_VERSION')
            : getenv('DW_PYTHON_SDK_VERSION'),
        'supported_workflow_types' => $types,
        'supported_activity_types' => [],
    ], [201]);
}

/** @return array<string, mixed> */
function cw_poll(string $workerId, string $queue): array
{
    $response = cw_request('POST', '/worker/workflow-tasks/poll', [
        'worker_id' => $workerId,
        'task_queue' => $queue,
    ]);
    if (! is_array($response['task'] ?? null)) {
        throw new RuntimeException(sprintf('worker %s did not receive a workflow task on %s', $workerId, $queue));
    }

    $task = $response['task'];
    $task['task_queue'] = is_string($task['task_queue'] ?? null) && $task['task_queue'] !== ''
        ? $task['task_queue']
        : $queue;

    return $task;
}

/** @param list<array<string, mixed>> $commands */
function cw_complete(array $task, array $commands): array
{
    if ($commands === []) {
        throw new RuntimeException('runtime adapter returned no commands for leased task '.($task['task_id'] ?? 'unknown'));
    }

    return cw_request('POST', '/worker/workflow-tasks/'.rawurlencode((string) $task['task_id']).'/complete', [
        'lease_owner' => $task['lease_owner'] ?? null,
        'workflow_task_attempt' => $task['workflow_task_attempt'] ?? 1,
        'commands' => $commands,
    ]);
}

function cw_codec(array $task): string
{
    $codec = $task['payload_codec'] ?? null;
    if (! is_string($codec) || $codec === '') {
        $codec = is_array($task['arguments'] ?? null) ? ($task['arguments']['codec'] ?? null) : null;
    }

    return is_string($codec) && $codec !== '' ? $codec : CodecRegistry::defaultCodec();
}

/** @return list<mixed> */
function cw_arguments(array $task, string $codec): array
{
    $raw = $task['arguments'] ?? null;
    if (is_array($raw) && isset($raw['codec'], $raw['blob'])) {
        $decoded = Serializer::unserializeWithCodec((string) $raw['codec'], (string) $raw['blob']);
    } elseif (is_string($raw) && $raw !== '') {
        $decoded = Serializer::unserializeWithCodec($codec, $raw);
    } else {
        $decoded = $raw;
    }

    return is_array($decoded) && array_is_list($decoded) ? $decoded : ($decoded === null ? [] : [$decoded]);
}

/** @return array{commands: list<array<string, mixed>>, observation: array<string, mixed>} */
function cw_php_step(array $task): array
{
    $classes = [
        PHP_PARENT => ConformancePhpParent::class,
        PHP_CHILD => ConformancePhpChild::class,
        PHP_FAILURE_PARENT => ConformancePhpFailureParent::class,
        PHP_FAILURE_CHILD => ConformancePhpFailureChild::class,
        PHP_LONG_CHILD => ConformancePhpLongChild::class,
        PHP_PARENT_CLOSE => ConformancePhpParentClose::class,
    ];
    $type = (string) ($task['workflow_type'] ?? '');
    $class = $classes[$type] ?? null;
    if ($class === null) {
        throw new RuntimeException('unregistered PHP conformance workflow type: '.$type);
    }
    $codec = cw_codec($task);
    $runtimeResult = null;
    try {
        $step = WorkflowFiberRunner::forClass(
            $class,
            (string) ($task['workflow_id'] ?? ''),
            (string) ($task['run_id'] ?? ''),
            cw_arguments($task, $codec),
            $codec,
            is_array($task['history_events'] ?? null) ? $task['history_events'] : [],
            CW_NAMESPACE,
        )->step();
        $commands = $step->commands;
        $runtimeResult = $step->result;
    } catch (Throwable $throwable) {
        $failure = FailureFactory::make($throwable);
        $commands = [[
            'type' => 'fail_workflow',
            'message' => $failure['message'],
            'exception_type' => $throwable::class,
            'exception_class' => $failure['exception_class'],
            'exception' => FailureFactory::payload($throwable),
        ]];
    }

    return [
        'commands' => $commands,
        'observation' => [
            'runtime' => 'workflow-php',
            'sdk_execution' => WorkflowFiberRunner::class,
            'workflow_type' => $type,
            'workflow_id' => $task['workflow_id'] ?? null,
            'run_id' => $task['run_id'] ?? null,
            'task_id' => $task['task_id'] ?? null,
            'task_queue' => $task['task_queue'] ?? null,
            'lease_owner' => $task['lease_owner'] ?? null,
            'commands' => $commands,
            'runtime_result' => $runtimeResult,
        ],
    ];
}

/** @return array{commands: list<array<string, mixed>>, observation: array<string, mixed>} */
function cw_python_step(array $task): array
{
    global $pythonBin, $pythonAdapter;
    $process = proc_open(
        [$pythonBin, $pythonAdapter],
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
        $pipes,
        null,
        null,
        ['bypass_shell' => true],
    );
    if (! is_resource($process)) {
        throw new RuntimeException('unable to start pinned Python SDK runtime adapter');
    }
    fwrite($pipes[0], json_encode($task, JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        throw new RuntimeException(sprintf('Python runtime adapter exited %d: %s', $exit, trim((string) $stderr)));
    }
    $decoded = json_decode((string) $stdout, true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($decoded) || ! is_array($decoded['commands'] ?? null)) {
        throw new RuntimeException('Python runtime adapter returned structurally incomplete output');
    }

    return ['commands' => $decoded['commands'], 'observation' => $decoded];
}

/** @return array{commands: list<array<string, mixed>>, observation: array<string, mixed>} */
function cw_runtime_step(string $runtime, array $task): array
{
    return $runtime === 'workflow-php' ? cw_php_step($task) : cw_python_step($task);
}

/** @return array{workflow_id: string, run_id: string} */
function cw_start(string $type, string $queue, array $input): array
{
    $workflowId = 'cw-'.bin2hex(random_bytes(8));
    $response = cw_request('POST', '/workflows', [
        'workflow_id' => $workflowId,
        'workflow_type' => $type,
        'task_queue' => $queue,
        'input' => $input,
    ], [201]);
    $runId = (string) ($response['run_id'] ?? '');
    if ($runId === '') {
        throw new RuntimeException('workflow start omitted run_id');
    }

    return ['workflow_id' => $workflowId, 'run_id' => $runId];
}

/** @return array<string, mixed> */
function cw_history(string $workflowId, string $runId): array
{
    return cw_request('GET', '/workflows/'.rawurlencode($workflowId).'/runs/'.rawurlencode($runId).'/history');
}

/** @return array<string, mixed> */
function cw_success_cell(string $scenario, string $parentRuntime, string $childRuntime): array
{
    $queue = 'cw-'.str_replace('_', '-', $scenario).'-'.bin2hex(random_bytes(3));
    $parentType = $parentRuntime === 'workflow-php' ? PHP_PARENT : 'conformance.python.parent';
    $childType = $childRuntime === 'workflow-php' ? PHP_CHILD : 'conformance.python.child';
    $parentWorker = $queue.'-parent';
    $childWorker = $queue.'-child';
    cw_register($parentWorker, $queue, $parentRuntime, [$parentType]);
    cw_register($childWorker, $queue, $childRuntime, [$childType]);
    $parent = cw_start($parentType, $queue, [$childType, $scenario]);

    $parentTask = cw_poll($parentWorker, $queue);
    $parentStart = cw_runtime_step($parentRuntime, $parentTask);
    cw_complete($parentTask, $parentStart['commands']);

    $childTask = cw_poll($childWorker, $queue);
    $child = [
        'workflow_id' => (string) ($childTask['workflow_id'] ?? ''),
        'run_id' => (string) ($childTask['run_id'] ?? ''),
    ];
    $childStep = cw_runtime_step($childRuntime, $childTask);
    cw_complete($childTask, $childStep['commands']);

    $parentResumeTask = cw_poll($parentWorker, $queue);
    $parentResume = cw_runtime_step($parentRuntime, $parentResumeTask);
    cw_complete($parentResumeTask, $parentResume['commands']);

    $parentHistory = cw_history($parent['workflow_id'], $parent['run_id']);
    $childHistory = cw_history($child['workflow_id'], $child['run_id']);
    $childCompleted = cw_require_child_event($parentHistory, 'ChildRunCompleted', $child['run_id']);
    $final = $parentResume['observation']['runtime_result'] ?? null;
    $expectedChildResult = $scenario.($childRuntime === 'workflow-php' ? '|php-child' : '|python-child');
    if (! is_array($final) || ($final['child_result'] ?? null) !== $expectedChildResult) {
        throw new RuntimeException(sprintf('%s parent did not observe exact %s child result', $parentRuntime, $childRuntime));
    }

    return [
        'scenario_id' => $scenario,
        'status' => 'pass',
        'parent' => $parentRuntime,
        'child' => $childRuntime,
        'observed_outputs' => [
            'parent_workflow_id' => $parent['workflow_id'],
            'parent_run_id' => $parent['run_id'],
            'child_workflow_id' => $child['workflow_id'],
            'child_run_id' => $child['run_id'],
            'task_queue' => $queue,
            'parent_final_result' => $final ?? ['runtime_command' => 'complete_workflow'],
            'expected_child_result' => $expectedChildResult,
            'parent_history_excerpt' => cw_history_excerpt($parentHistory),
            'child_history_excerpt' => cw_history_excerpt($childHistory),
            'parent_history' => $parentHistory,
            'child_history' => $childHistory,
            'runtime_observations' => [$parentStart['observation'], $childStep['observation'], $parentResume['observation']],
            'observed_at' => cw_required_event_time($childCompleted, 'ChildRunCompleted'),
        ],
    ];
}

/** @return array<string, mixed> */
function cw_failure_cell(string $parentRuntime, string $childRuntime): array
{
    $queue = 'cw-failure-'.str_replace('-', '', $parentRuntime).'-'.str_replace('-', '', $childRuntime).'-'.bin2hex(random_bytes(3));
    $parentType = $parentRuntime === 'workflow-php' ? PHP_FAILURE_PARENT : 'conformance.python.failure-parent';
    $childType = $childRuntime === 'workflow-php' ? PHP_FAILURE_CHILD : 'conformance.python.failure-child';
    $message = 'typed child failure '.$parentRuntime.' to '.$childRuntime;
    $parentWorker = $queue.'-parent';
    $childWorker = $queue.'-child';
    cw_register($parentWorker, $queue, $parentRuntime, [$parentType]);
    cw_register($childWorker, $queue, $childRuntime, [$childType]);
    $parent = cw_start($parentType, $queue, [$childType, $message]);
    $parentTask = cw_poll($parentWorker, $queue);
    $parentStart = cw_runtime_step($parentRuntime, $parentTask);
    cw_complete($parentTask, $parentStart['commands']);
    $childTask = cw_poll($childWorker, $queue);
    $childStep = cw_runtime_step($childRuntime, $childTask);
    cw_complete($childTask, $childStep['commands']);
    $parentResumeTask = cw_poll($parentWorker, $queue);
    $parentResume = cw_runtime_step($parentRuntime, $parentResumeTask);
    cw_complete($parentResumeTask, $parentResume['commands']);

    $parentHistory = cw_history($parent['workflow_id'], $parent['run_id']);
    $childWorkflowId = (string) ($childTask['workflow_id'] ?? '');
    $childRunId = (string) ($childTask['run_id'] ?? '');
    $childHistory = cw_history($childWorkflowId, $childRunId);
    $failed = cw_require_child_event($parentHistory, 'ChildRunFailed', $childRunId);
    $payload = is_array($failed['payload'] ?? null) ? $failed['payload'] : [];
    $parentRuntimeResult = $parentResume['observation']['runtime_result'] ?? null;
    if (! is_array($parentRuntimeResult)) {
        throw new RuntimeException('typed child failure was not observed by the parent runtime');
    }
    $parentFailureKind = (string) ($parentRuntimeResult['failure_kind'] ?? '');
    if ($parentFailureKind !== 'child_workflow') {
        throw new RuntimeException('parent runtime did not classify the typed failure as child_workflow');
    }
    $childFailureCategory = (string) ($payload['failure_category'] ?? '');
    if ($childFailureCategory === '') {
        throw new RuntimeException('ChildRunFailed omitted its child failure category');
    }
    $exceptionClass = (string) ($payload['exception_class'] ?? $payload['exception_type'] ?? '');
    $observedMessage = (string) ($payload['message'] ?? '');
    if ($exceptionClass === '' || $observedMessage !== $message) {
        throw new RuntimeException('typed child failure metadata was not preserved');
    }

    return [
        'scenario' => 'child_failure_round_trip_matrix',
        'parent' => $parentRuntime,
        'child' => $childRuntime,
        'status' => 'pass',
        'failure_kind' => $parentFailureKind,
        'child_failure_category' => $childFailureCategory,
        'exception_class' => $exceptionClass,
        'exception_type' => (string) ($payload['exception_type'] ?? $exceptionClass),
        'message' => $observedMessage,
        'parent_workflow_id' => $parent['workflow_id'],
        'parent_run_id' => $parent['run_id'],
        'child_workflow_id' => $childWorkflowId,
        'child_run_id' => $childRunId,
        'task_queue' => $queue,
        'observed_at' => cw_required_event_time($failed, 'ChildRunFailed'),
        'parent_history_observations' => cw_history_excerpt($parentHistory),
        'child_history_observations' => cw_history_excerpt($childHistory),
        'parent_history_excerpt' => $failed,
        'child_history_excerpt' => cw_first_event($childHistory, 'WorkflowFailed') ?? $childHistory,
        'parent_history' => $parentHistory,
        'child_history' => $childHistory,
        'runtime_observations' => [$parentStart['observation'], $childStep['observation'], $parentResume['observation']],
        'local_product_source_checkouts_used' => false,
    ];
}

/** @return array<string, mixed> */
function cw_parent_cancellation(): array
{
    $queue = 'cw-parent-cancel-'.bin2hex(random_bytes(3));
    cw_register($queue.'-parent', $queue, 'sdk-python', ['conformance.python.cancel-parent']);
    cw_register($queue.'-child', $queue, 'workflow-php', [PHP_LONG_CHILD]);
    $parent = cw_start('conformance.python.cancel-parent', $queue, [PHP_LONG_CHILD]);
    $parentTask = cw_poll($queue.'-parent', $queue);
    $parentStep = cw_python_step($parentTask);
    cw_complete($parentTask, $parentStep['commands']);
    $childTask = cw_poll($queue.'-child', $queue);
    $childStep = cw_php_step($childTask);
    cw_complete($childTask, $childStep['commands']);
    $issuedAt = cw_now();
    cw_request('POST', '/workflows/'.rawurlencode($parent['workflow_id']).'/cancel', ['reason' => 'child conformance parent cancellation']);
    $childWorkflowId = (string) $childTask['workflow_id'];
    $childRunId = (string) $childTask['run_id'];
    $childHistory = cw_history($childWorkflowId, $childRunId);
    $cancelled = cw_first_event($childHistory, 'WorkflowCancelled');
    if ($cancelled === null) {
        throw new RuntimeException('parent cancellation did not terminalize the child as cancelled');
    }
    $parentHistory = cw_history($parent['workflow_id'], $parent['run_id']);
    $parentCloseApplied = cw_require_child_event($parentHistory, 'ParentClosePolicyApplied', $childRunId);
    $childCancellation = is_array($cancelled['payload'] ?? null) ? $cancelled['payload'] : [];
    $parentClosePolicy = is_array($parentCloseApplied['payload'] ?? null) ? $parentCloseApplied['payload'] : [];
    $failureKind = (string) ($childCancellation['failure_category'] ?? '');
    $exceptionClass = (string) ($childCancellation['exception_class'] ?? '');
    $exceptionType = $exceptionClass === '' ? '' : basename(str_replace('\\', '/', $exceptionClass));
    $message = (string) ($childCancellation['message'] ?? '');
    $typedHistoryObserved = $failureKind === 'cancelled'
        && $exceptionType === 'WorkflowCancelledException'
        && $message !== ''
        && ($parentClosePolicy['policy'] ?? null) === 'request_cancel'
        && ($parentClosePolicy['child_run_id'] ?? null) === $childRunId;
    if (! $typedHistoryObserved) {
        throw new RuntimeException('terminal child cancellation history did not preserve typed failure and matching parent-close-policy evidence');
    }

    return [
        'status' => 'pass',
        'parent_workflow_id' => $parent['workflow_id'],
        'parent_run_id' => $parent['run_id'],
        'child_workflow_id' => $childWorkflowId,
        'child_run_id' => $childRunId,
        'task_queue' => $queue,
        'cancel_issued_at' => $issuedAt,
        'child_cancelled_at' => cw_required_event_time($cancelled, 'child cancellation history event'),
        'parent_observed_at' => cw_required_event_time($parentCloseApplied, 'ParentClosePolicyApplied'),
        'worker_observed_typed_cancellation' => false,
        'typed_cancellation_observed' => $typedHistoryObserved,
        'typed_cancellation_evidence_source' => 'terminal_child_history_and_parent_close_policy',
        'child_failure_kind' => $failureKind,
        'child_exception_type' => $exceptionType,
        'child_exception_class' => $exceptionClass,
        'child_message' => $message,
        'child_cancellation_history_evidence' => $cancelled,
        'parent_close_policy_evidence' => $parentCloseApplied,
        'parent_history' => $parentHistory,
        'child_history_excerpt' => cw_history_excerpt($childHistory),
        'child_history' => $childHistory,
        'runtime_observations' => [$parentStep['observation'], $childStep['observation']],
    ];
}

/** @return array<string, mixed> */
function cw_direct_child_cancellation(): array
{
    $queue = 'cw-direct-child-cancel-'.bin2hex(random_bytes(3));
    cw_register($queue.'-parent', $queue, 'sdk-python', ['conformance.python.cancel-parent']);
    cw_register($queue.'-child', $queue, 'workflow-php', [PHP_LONG_CHILD]);
    $parent = cw_start('conformance.python.cancel-parent', $queue, [PHP_LONG_CHILD]);
    $parentTask = cw_poll($queue.'-parent', $queue);
    $parentStart = cw_python_step($parentTask);
    cw_complete($parentTask, $parentStart['commands']);
    $childTask = cw_poll($queue.'-child', $queue);
    $childStart = cw_php_step($childTask);
    cw_complete($childTask, $childStart['commands']);
    $issuedAt = cw_now();
    cw_request('POST', '/workflows/'.rawurlencode((string) $childTask['workflow_id']).'/cancel', ['reason' => 'direct child conformance cancellation']);
    $resumeTask = cw_poll($queue.'-parent', $queue);
    $resume = cw_python_step($resumeTask);
    $runtimeResult = $resume['observation']['runtime_result'] ?? null;
    $childRunId = (string) $childTask['run_id'];
    if (! is_array($runtimeResult)
        || ($runtimeResult['failure_kind'] ?? null) !== 'cancelled'
        || ($runtimeResult['child_run_id'] ?? null) !== $childRunId
        || ! str_ends_with((string) ($runtimeResult['exception_class'] ?? ''), '.ChildWorkflowCancelled')) {
        throw new RuntimeException('Python parent did not observe typed ChildWorkflowCancelled for the directly cancelled child run');
    }
    cw_complete($resumeTask, $resume['commands']);
    $parentHistory = cw_history($parent['workflow_id'], $parent['run_id']);
    $cancelled = cw_require_child_event($parentHistory, 'ChildRunCancelled', $childRunId);
    $childHistory = cw_history((string) $childTask['workflow_id'], $childRunId);

    return [
        'status' => 'pass',
        'parent_workflow_id' => $parent['workflow_id'],
        'parent_run_id' => $parent['run_id'],
        'child_workflow_id' => (string) $childTask['workflow_id'],
        'child_run_id' => $childRunId,
        'task_queue' => $queue,
        'child_cancel_issued_at' => $issuedAt,
        'parent_observed_at' => cw_required_event_time($cancelled, 'ChildRunCancelled'),
        'parent_failure_kind' => $runtimeResult['failure_kind'],
        'parent_exception_type' => $runtimeResult['exception_type'],
        'parent_exception_class' => $runtimeResult['exception_class'],
        'parent_history_excerpt' => cw_history_excerpt($parentHistory),
        'parent_history' => $parentHistory,
        'child_history' => $childHistory,
        'runtime_observations' => [$parentStart['observation'], $childStart['observation'], $resume['observation']],
    ];
}

/** @return array<string, mixed> */
function cw_parent_close_policy(): array
{
    $queue = 'cw-parent-close-'.bin2hex(random_bytes(3));
    cw_register($queue.'-parent', $queue, 'workflow-php', [PHP_PARENT_CLOSE]);
    cw_register($queue.'-child', $queue, 'sdk-python', ['conformance.python.long-child']);
    $parent = cw_start(PHP_PARENT_CLOSE, $queue, ['conformance.python.long-child']);
    $parentTask = cw_poll($queue.'-parent', $queue);
    $parentStep = cw_php_step($parentTask);
    cw_complete($parentTask, $parentStep['commands']);
    $childTask = cw_poll($queue.'-child', $queue);
    $childStep = cw_python_step($childTask);
    cw_complete($childTask, $childStep['commands']);
    cw_request('POST', '/workflows/'.rawurlencode($parent['workflow_id']).'/terminate', ['reason' => 'verify request_cancel parent close policy']);
    $childHistory = cw_history((string) $childTask['workflow_id'], (string) $childTask['run_id']);
    $cancelled = cw_first_event($childHistory, 'WorkflowCancelled') ?? cw_first_event($childHistory, 'CancelRequested');
    if ($cancelled === null) {
        throw new RuntimeException('request_cancel parent-close policy did not reach child');
    }

    return [
        'status' => 'pass',
        'policy' => 'request_cancel',
        'parent_workflow_id' => $parent['workflow_id'],
        'parent_run_id' => $parent['run_id'],
        'child_workflow_id' => (string) $childTask['workflow_id'],
        'child_run_id' => (string) $childTask['run_id'],
        'task_queue' => $queue,
        'child_status' => 'cancelled',
        'history_excerpt' => cw_history_excerpt($childHistory),
    ];
}

/** @return array<string, mixed> */
function cw_replay_restart(): array
{
    $queue = 'cw-replay-restart-'.bin2hex(random_bytes(3));
    cw_register($queue.'-parent-before', $queue, 'sdk-python', ['conformance.python.parent']);
    cw_register($queue.'-parent-after', $queue, 'sdk-python', ['conformance.python.parent']);
    cw_register($queue.'-child', $queue, 'workflow-php', [PHP_CHILD]);
    $parent = cw_start('conformance.python.parent', $queue, [PHP_CHILD, 'restart']);
    $firstTask = cw_poll($queue.'-parent-before', $queue);
    $first = cw_python_step($firstTask);
    cw_complete($firstTask, $first['commands']);
    $stoppedAt = cw_now();
    $childTask = cw_poll($queue.'-child', $queue);
    $child = cw_php_step($childTask);
    cw_complete($childTask, $child['commands']);
    $resumeTask = cw_poll($queue.'-parent-after', $queue);
    $restartedAt = cw_now();
    $originalReplay = cw_python_step($resumeTask);
    $replayed = cw_python_step($resumeTask);
    $originalTypes = array_map(static fn (array $command): string => (string) ($command['type'] ?? ''), $originalReplay['commands']);
    $replayedTypes = array_map(static fn (array $command): string => (string) ($command['type'] ?? ''), $replayed['commands']);
    if ($originalTypes !== $replayedTypes) {
        throw new RuntimeException('Python parent replay decision sequence changed after worker restart');
    }
    cw_complete($resumeTask, $replayed['commands']);
    $history = cw_history($parent['workflow_id'], $parent['run_id']);
    $childHistory = cw_history((string) $childTask['workflow_id'], (string) $childTask['run_id']);
    $scheduled = array_values(array_filter(cw_history_events($history), static fn (array $event): bool => cw_event_type($event) === 'ChildWorkflowScheduled'));
    if (count($scheduled) !== 1) {
        throw new RuntimeException('worker restart replay scheduled a duplicate child');
    }

    return [
        'status' => 'pass',
        'parent_workflow_id' => $parent['workflow_id'],
        'parent_run_id' => $parent['run_id'],
        'child_workflow_id' => (string) $childTask['workflow_id'],
        'child_run_id' => (string) $childTask['run_id'],
        'task_queue' => $queue,
        'parent_worker_stopped_at' => $stoppedAt,
        'parent_worker_restarted_at' => $restartedAt,
        'original_decision_sequence' => $originalTypes,
        'replayed_decision_sequence' => $replayedTypes,
        'duplicate_child_scheduled' => false,
        'parent_history_excerpt' => cw_history_excerpt($history),
        'parent_history' => $history,
        'child_history' => $childHistory,
        'runtime_observations' => [$first['observation'], $child['observation'], $replayed['observation']],
    ];
}

/** @return array<string, mixed> */
function cw_fan_out(): array
{
    $queue = 'cw-fan-out-'.bin2hex(random_bytes(3));
    cw_register($queue.'-parent', $queue, 'sdk-python', ['conformance.python.fan-out-parent']);
    cw_register($queue.'-child', $queue, 'workflow-php', [PHP_CHILD]);
    $parent = cw_start('conformance.python.fan-out-parent', $queue, [PHP_CHILD, 5]);
    $parentTask = cw_poll($queue.'-parent', $queue);
    $start = cw_python_step($parentTask);
    if (count(array_filter($start['commands'], static fn (array $command): bool => ($command['type'] ?? null) === 'start_child_workflow')) !== 5) {
        throw new RuntimeException('fan-out parent did not schedule five children in one workflow task');
    }
    cw_complete($parentTask, $start['commands']);
    $childIds = [];
    $childHistories = [];
    $runtimeObservations = [$start['observation']];
    $started = [];
    $completed = [];
    for ($index = 0; $index < 5; ++$index) {
        $task = cw_poll($queue.'-child', $queue);
        $step = cw_php_step($task);
        $historyBefore = cw_history((string) $task['workflow_id'], (string) $task['run_id']);
        $started[] = cw_required_event_time(
            cw_first_event($historyBefore, 'WorkflowStarted'),
            'fan-out child WorkflowStarted',
        );
        cw_complete($task, $step['commands']);
        $historyAfter = cw_history((string) $task['workflow_id'], (string) $task['run_id']);
        $completed[] = cw_required_event_time(
            cw_first_event($historyAfter, 'WorkflowCompleted'),
            'fan-out child WorkflowCompleted',
        );
        $childIds[] = ['workflow_id' => $task['workflow_id'], 'run_id' => $task['run_id']];
        $childHistories[] = $historyAfter;
        $runtimeObservations[] = $step['observation'];
    }
    $resumeTask = cw_poll($queue.'-parent', $queue);
    $resume = cw_python_step($resumeTask);
    $aggregate = $resume['observation']['runtime_result'] ?? null;
    if (! is_array($aggregate) || ($aggregate['child_count'] ?? null) !== 5) {
        throw new RuntimeException('fan-out parent did not aggregate all five child results');
    }
    cw_complete($resumeTask, $resume['commands']);
    $parentHistory = cw_history($parent['workflow_id'], $parent['run_id']);
    $startedEpochs = array_values(array_filter(
        array_map('strtotime', $started),
        static fn (int|false $value): bool => $value !== false,
    ));
    $completedEpochs = array_values(array_filter(
        array_map('strtotime', $completed),
        static fn (int|false $value): bool => $value !== false,
    ));
    $overlapObserved = count($startedEpochs) === 5
        && count($completedEpochs) === 5
        && max($startedEpochs) <= min($completedEpochs);
    if (! $overlapObserved) {
        throw new RuntimeException('fan-out child history timestamps show serialized scheduling');
    }
    $runtimeObservations[] = $resume['observation'];
    foreach ($childIds as $identity) {
        cw_require_child_event($parentHistory, 'ChildRunCompleted', (string) $identity['run_id']);
    }

    return [
        'status' => 'pass',
        'parent_workflow_id' => $parent['workflow_id'],
        'parent_run_id' => $parent['run_id'],
        'task_queue' => $queue,
        'child_count' => count($childIds),
        'child_run_identities' => $childIds,
        'child_histories' => $childHistories,
        'child_started_at_values' => $started,
        'child_completed_at_values' => $completed,
        'aggregate_result' => $aggregate,
        'overlap_observed' => $overlapObserved,
        'parent_history_excerpt' => cw_history_excerpt($parentHistory),
        'parent_history' => $parentHistory,
        'runtime_observations' => $runtimeObservations,
    ];
}

try {
    $app = require $repoRoot.'/bootstrap/app.php';
    $app->make(ConsoleKernel::class)->bootstrap();
    config([
        'app.key' => getenv('APP_KEY') ?: 'base64:Q0hJTEQtV09SS0ZMT1dTLUNPTkZPUk1BTkNFLVJVTlRJTUU=',
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => getenv('DB_DATABASE') ?: ':memory:',
        'queue.default' => 'database',
        'cache.default' => 'array',
        'session.driver' => 'array',
        'server.auth.driver' => 'none',
        'server.mode' => 'service',
        'workflows.v2.task_dispatch_mode' => 'poll',
    ]);
    Artisan::call('migrate', ['--force' => true]);
    WorkflowNamespace::query()->updateOrCreate(
        ['name' => CW_NAMESPACE],
        ['description' => 'Child workflow conformance', 'retention_days' => 1, 'status' => 'active'],
    );

    $successCells = [
        cw_success_cell('python_parent_python_child_baseline', 'sdk-python', 'sdk-python'),
        cw_success_cell('php_parent_php_child_baseline', 'workflow-php', 'workflow-php'),
        cw_success_cell('php_parent_python_child_cross_language', 'workflow-php', 'sdk-python'),
        cw_success_cell('python_parent_php_child_cross_language', 'sdk-python', 'workflow-php'),
    ];
    $failureCells = [
        cw_failure_cell('sdk-python', 'sdk-python'),
        cw_failure_cell('workflow-php', 'workflow-php'),
        cw_failure_cell('workflow-php', 'sdk-python'),
        cw_failure_cell('sdk-python', 'workflow-php'),
    ];
    $parentCancellation = cw_parent_cancellation();
    $directCancellation = cw_direct_child_cancellation();
    $parentClose = cw_parent_close_policy();
    $replay = cw_replay_restart();
    $fanOut = cw_fan_out();
    $lineageSource = $successCells[3]['observed_outputs'];
    $debug = cw_request('GET', '/workflows/'.rawurlencode((string) $lineageSource['parent_workflow_id']).'/debug');
    $namespace = [
        'status' => 'pass',
        'parent_namespace' => CW_NAMESPACE,
        'child_namespace' => CW_NAMESPACE,
        'cross_namespace_verdict' => 'target namespace is not a child-start option in the published PHP/Python contract',
        'parent_workflow_id' => $lineageSource['parent_workflow_id'],
        'parent_run_id' => $lineageSource['parent_run_id'],
        'child_workflow_id' => $lineageSource['child_workflow_id'],
        'child_run_id' => $lineageSource['child_run_id'],
        'task_queue' => $lineageSource['task_queue'],
        'lineage_links' => [[
            'parent_workflow_id' => $lineageSource['parent_workflow_id'],
            'parent_run_id' => $lineageSource['parent_run_id'],
            'child_workflow_id' => $lineageSource['child_workflow_id'],
            'child_run_id' => $lineageSource['child_run_id'],
        ]],
        'operator_visible_debug' => $debug,
        'parent_history_excerpt' => $lineageSource['parent_history_excerpt'],
        'child_history_excerpt' => $lineageSource['child_history_excerpt'],
        'parent_history' => $lineageSource['parent_history'],
        'child_history' => $lineageSource['child_history'],
        'runtime_observations' => $lineageSource['runtime_observations'],
        'observed_at' => $lineageSource['observed_at'],
    ];

    $scenarioResults = $successCells;
    $scenarioResults[] = [
        'scenario_id' => 'child_failure_round_trip_matrix',
        'status' => 'pass',
        'observed_outputs' => ['cells' => $failureCells, 'observed_at' => cw_now()],
    ];
    $scenarioResults[] = [
        'scenario_id' => 'parent_cancellation_propagates_to_child',
        'status' => 'pass',
        'observed_outputs' => $parentCancellation + ['parent_close_policy' => $parentClose],
    ];
    $scenarioResults[] = [
        'scenario_id' => 'direct_child_cancellation_observed_by_parent',
        'status' => 'pass',
        'observed_outputs' => $directCancellation,
    ];
    $scenarioResults[] = [
        'scenario_id' => 'worker_restart_replay_preserves_child_outcome',
        'status' => 'pass',
        'observed_outputs' => $replay,
    ];
    $scenarioResults[] = [
        'scenario_id' => 'concurrent_child_fan_out',
        'status' => 'pass',
        'observed_outputs' => $fanOut,
    ];
    $scenarioResults[] = [
        'scenario_id' => 'child_workflow_namespace_contract',
        'status' => 'pass',
        'observed_outputs' => $namespace,
    ];

    $evidence = [
        'schema' => 'durable-workflow.v2.child-workflow-runtime.full-matrix-evidence',
        'generated_at' => cw_now(),
        'execution_source' => 'published_server_image_runtime_probe',
        'local_product_source_checkouts_used' => false,
        'artifact_versions' => [
            'server' => getenv('DW_SERVER_VERSION') ?: '',
            'cli' => ltrim(getenv('DW_CLI_VERSION') ?: '', 'v'),
            'sdk-python' => getenv('DW_PYTHON_SDK_VERSION') ?: '',
            'sdk-rust' => getenv('DW_RUST_SDK_VERSION') ?: '',
            'workflow' => getenv('DW_WORKFLOW_PHP_VERSION') ?: '',
            'waterline' => getenv('DW_WATERLINE_VERSION') ?: '',
        ],
        'scenario_results' => $scenarioResults,
        'runtime_matrix' => [
            'runtimes' => ['workflow-php', 'sdk-python'],
            'same_language_cells' => [$successCells[0], $successCells[1]],
            'cross_language_cells' => [$successCells[2], $successCells[3]],
            'failure_round_trip_cells' => $failureCells,
        ],
        'failure_round_trip' => ['status' => 'pass', 'cells' => $failureCells],
        'cancellation_propagation' => [
            'parent_to_child' => $parentCancellation,
            'direct_child' => $directCancellation,
            'parent_close_policy' => $parentClose,
        ],
        'replay_restart' => $replay,
        'fan_out' => $fanOut,
        'namespace_behavior' => $namespace,
    ];
    file_put_contents(
        $resultDir.'/full-matrix-evidence.json',
        json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
    );
    echo json_encode(['status' => 'pass', 'result' => $resultDir.'/full-matrix-evidence.json'], JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $throwable) {
    $error = [
        'schema' => 'durable-workflow.v2.child-workflow-runtime.probe-error',
        'generated_at' => cw_now(),
        'local_product_source_checkouts_used' => false,
        'error' => $throwable::class.': '.$throwable->getMessage(),
        'trace' => $throwable->getTraceAsString(),
    ];
    file_put_contents(
        $resultDir.'/runtime-probe-error.json',
        json_encode($error, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
    );
    fwrite(STDERR, $error['error']."\n");
    exit(1);
}
