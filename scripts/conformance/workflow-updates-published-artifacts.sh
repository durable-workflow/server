#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage: workflow-updates-published-artifacts.sh [--result-dir DIR|--result-dir=DIR]

Writes a published-artifact workflow updates conformance result.

The runner writes these files to the result directory:
  pins.json
  run-metadata.json
  workflow-updates-focused-evidence.json (when the published-image probe runs)
  sdk-php-workflow-updates-evidence.json (when the PHP SDK shard runs)
  python-sdk-workflow-updates-evidence.json (when the Python SDK shard runs)
  workflow-updates-operator-diagnostics-evidence.json (when CLI and Waterline diagnostics run)
  workflow-updates-result.json
  workflow-updates-record.json
  workflow-updates-findings.json

Environment overrides:
  DW_WORKFLOW_UPDATES_RESULT_DIR     Result directory when --result-dir is omitted.
  DW_WORKFLOW_UPDATES_EVIDENCE       Optional inline JSON evidence from a real host run.
  DW_WORKFLOW_UPDATES_EVIDENCE_PATH  Optional JSON evidence path. Defaults to
                                     workflow-updates-focused-evidence.json in the result dir.
  DW_WORKFLOW_UPDATES_PHP_EVIDENCE   Optional inline JSON evidence from the PHP SDK shard.
  DW_WORKFLOW_UPDATES_PHP_EVIDENCE_PATH
                                     Optional PHP SDK shard evidence path. Defaults to
                                     sdk-php-workflow-updates-evidence.json in the result dir.
  DW_WORKFLOW_UPDATES_PYTHON_EVIDENCE
                                     Optional inline JSON evidence from the Python SDK shard.
  DW_WORKFLOW_UPDATES_PYTHON_EVIDENCE_PATH
                                     Optional Python SDK shard evidence path. Defaults to
                                     python-sdk-workflow-updates-evidence.json in the result dir.
  DW_WORKFLOW_UPDATES_OPERATOR_DIAGNOSTICS_EVIDENCE
                                     Optional inline JSON evidence from the CLI/Waterline
                                     operator diagnostics shard.
  DW_WORKFLOW_UPDATES_OPERATOR_DIAGNOSTICS_EVIDENCE_PATH
                                     Optional CLI/Waterline operator diagnostics evidence
                                     path. Defaults to workflow-updates-operator-diagnostics-evidence.json.
  DW_WORKFLOW_UPDATES_SKIP_FOCUSED_HOST_PROBE=1
                                     Skip the published server image's focused
                                     workflow update runtime probe.
  DW_WORKFLOW_UPDATES_SKIP_PHP_PACKAGE_SHARD=1
                                     Skip the PHP SDK client/worker shard.
  DW_WORKFLOW_UPDATES_RUN_PHP_PACKAGE_SHARD=1
                                     Allow an explicit source-checkout harness
                                     to exercise the PHP shard.
  DW_WORKFLOW_UPDATES_SKIP_PYTHON_SDK_SHARD=1
                                     Skip the Python SDK client/worker shard.
  DW_WORKFLOW_UPDATES_SKIP_OPERATOR_DIAGNOSTICS_SHARD=1
                                     Skip the official CLI JSON plus Waterline
                                     selected-run diagnostics shard.
  DW_SERVER_IMAGE                    Exact server image tag or digest under test.
  DW_SERVER_VERSION                  Exact server version under test.
  DW_CLI_VERSION                     Exact CLI release version.
  DW_PYTHON_SDK_VERSION              Exact PyPI durable-workflow version.
  DW_PHP_SDK_VERSION                 Exact Packagist durable-workflow/sdk version.
  DW_WORKFLOW_UPDATES_PHP_SERVER_PORT
                                     Published host port for the PHP SDK shard's
                                     temporary server. Defaults to a free port.
  DW_WORKFLOW_UPDATES_PHP_SERVER_BIND_HOST
                                     Docker host interface for the published port.
                                     Defaults to 0.0.0.0.
  DW_WORKFLOW_UPDATES_PHP_SERVER_CONNECT_HOST
                                     Hostname or address used by readiness and the
                                     PHP SDK probe. Defaults to 127.0.0.1.
  DW_WORKFLOW_UPDATES_PYTHON_BIN     Python executable used to create the
                                     disposable PyPI install environment.
  DW_WORKFLOW_PHP_VERSION            Exact Composer durable-workflow/workflow version.
  DW_WATERLINE_VERSION               Exact Waterline artifact version.
USAGE
}

result_dir="${DW_WORKFLOW_UPDATES_RESULT_DIR:-}"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --result-dir)
      result_dir="${2:?--result-dir requires a value}"
      shift 2
      ;;
    --result-dir=*)
      result_dir="${1#--result-dir=}"
      if [[ -z "$result_dir" ]]; then
        printf '%s\n' '--result-dir requires a value' >&2
        usage >&2
        exit 2
      fi
      shift
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      printf 'unknown argument: %s\n' "$1" >&2
      usage >&2
      exit 2
      ;;
  esac
done

if [[ -z "$result_dir" ]]; then
  result_dir="$(mktemp -d "${TMPDIR:-/tmp}/dw-workflow-updates.XXXXXX")"
fi
mkdir -p "$result_dir"
result_dir="$(cd "$result_dir" && pwd)"

timestamp() {
  date -u '+%Y-%m-%dT%H:%M:%SZ'
}

started_at="$(timestamp)"
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/../.." && pwd)"
cleanup_pids=()
cleanup_compose_projects=()

cleanup_background_processes() {
  local pid
  for pid in "${cleanup_pids[@]}"; do
    if kill -0 "$pid" >/dev/null 2>&1; then
      kill "$pid" >/dev/null 2>&1 || true
      wait "$pid" >/dev/null 2>&1 || true
    fi
  done
  local project
  for project in "${cleanup_compose_projects[@]}"; do
    docker compose -p "$project" -f "$repo_root/docker-compose.published.yml" down -v >/dev/null 2>&1 || true
  done
}
trap cleanup_background_processes EXIT

should_run_focused_host_probe() {
  if [[ "${DW_WORKFLOW_UPDATES_SKIP_FOCUSED_HOST_PROBE:-0}" == "1" || "${DW_WORKFLOW_UPDATES_SKIP_FOCUSED_HOST_PROBE:-}" == "true" ]]; then
    return 1
  fi
  if [[ -n "${DW_WORKFLOW_UPDATES_EVIDENCE:-}" ]]; then
    return 1
  fi
  if [[ -n "${DW_WORKFLOW_UPDATES_EVIDENCE_PATH:-}" && -s "${DW_WORKFLOW_UPDATES_EVIDENCE_PATH:-}" ]]; then
    return 1
  fi
  if [[ -s "$result_dir/workflow-updates-focused-evidence.json" ]]; then
    return 1
  fi
  if [[ "$repo_root" != "/app" || -d "$repo_root/.git" ]]; then
    return 1
  fi
  if [[ ! -f "$repo_root/artisan" || ! -f "$repo_root/vendor/autoload.php" ]]; then
    return 1
  fi

  command -v php >/dev/null 2>&1
}

if should_run_focused_host_probe; then
  probe_db="$result_dir/workflow-updates-focused.sqlite"
  : > "$probe_db"

  APP_ENV=production \
  APP_DEBUG=false \
  APP_KEY="${APP_KEY:-base64:V09SS0ZMT1ctVVBEQVRFUy1GT0NVU0VELUhPU1QtUFJPQkU=}" \
  DB_CONNECTION=sqlite \
  DB_DATABASE="$probe_db" \
  QUEUE_CONNECTION=database \
  CACHE_STORE=array \
  SESSION_DRIVER=array \
  DW_AUTH_DRIVER=none \
  DW_TASK_DISPATCH_MODE=poll \
  DW_V2_TASK_DISPATCH_MODE=poll \
  RESULT_DIR="$result_dir" \
  RUNNER_REPO_ROOT="$repo_root" \
  php <<'PHP' >"$result_dir/workflow-updates-focused-probe.log" 2>&1 || true
<?php
declare(strict_types=1);

use App\Models\WorkflowNamespace;
use App\Models\WorkerRegistration;
use App\Models\WorkflowUpdateValidationTask;
use App\Support\ControlPlaneMutationRetrier;
use App\Support\ControlPlaneProtocol;
use App\Support\DirectConformanceWorkerProtocol;
use App\Support\ExternalWorkflowUpdateAdmission;
use App\Support\LongPollSignalStore;
use App\Support\LongPollWaitSlotStore;
use App\Support\LongPoller;
use App\Support\WorkerProtocol;
use App\Support\WorkflowQueryTaskBroker;
use App\Support\ServerWorkflowControlPlane;
use App\Support\ValidatedExternalWorkflowUpdateAdmission;
use App\Support\WorkflowUpdateValidationTaskBroker;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Workflow\Serializers\Avro;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowUpdate;

const WORKFLOW_UPDATES_NAMESPACE = 'workflow-updates-conformance';
const WORKFLOW_UPDATES_QUEUE = 'workflow-updates-shared';
const WORKFLOW_UPDATES_TYPE = 'workflow-updates.probe';
const WORKFLOW_UPDATE_ACCEPTED_EVENT = 'UpdateAccepted';
const WORKFLOW_UPDATE_COMPLETED_EVENT = 'UpdateCompleted';
const WORKFLOW_UPDATES_AUTH_TOKEN = 'workflow-updates-auth-token';
const WORKFLOW_UPDATES_AUTH_PRINCIPAL_ID = 'workflow-updates-operator';
const WORKFLOW_UPDATES_AUTH_PRINCIPAL_LABEL = 'Workflow Updates Operator';

function now_iso(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

function result_dir(): string
{
    return rtrim((string) getenv('RESULT_DIR'), '/');
}

function write_json_file(string $name, array $payload): void
{
    file_put_contents(
        result_dir().'/'.$name,
        json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
    );
}

function bootstrap_application(): void
{
    $repoRoot = (string) getenv('RUNNER_REPO_ROOT');
    require_once $repoRoot.'/vendor/autoload.php';

    $app = require $repoRoot.'/bootstrap/app.php';
    $app->make(ConsoleKernel::class)->bootstrap();

    config([
        'app.key' => getenv('APP_KEY') ?: 'base64:V09SS0ZMT1ctVVBEQVRFUy1GT0NVU0VELUhPU1QtUFJPQkU=',
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
        ['name' => WORKFLOW_UPDATES_NAMESPACE],
        [
            'description' => 'Workflow updates conformance namespace',
            'retention_days' => 30,
            'status' => 'active',
        ],
    );
}

function header_key(string $name): string
{
    return 'HTTP_'.str_replace('-', '_', strtoupper($name));
}

function request_json(
    string $method,
    string $path,
    ?array $body = null,
    array $allowed = [],
    array $headers = [],
): array
{
    static $kernel = null;
    $kernel ??= app(HttpKernel::class);

    $server = [
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_NAMESPACE' => WORKFLOW_UPDATES_NAMESPACE,
        header_key(ControlPlaneProtocol::HEADER) => ControlPlaneProtocol::VERSION,
        header_key(WorkerProtocol::HEADER) => WorkerProtocol::VERSION,
    ];

    foreach ($headers as $name => $value) {
        if (! is_string($name) || ! is_string($value) || trim($value) === '') {
            continue;
        }

        $server[header_key($name)] = $value;
    }

    $content = $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR);
    $request = Request::create('/api'.$path, $method, [], [], [], $server, $content);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    $status = $response->getStatusCode();
    $raw = (string) $response->getContent();

    if (($status >= 400 || $status === 0) && ! in_array($status, $allowed, true)) {
        throw new RuntimeException(sprintf('%s %s failed with HTTP %d: %s', $method, $path, $status, $raw));
    }

    $decoded = $raw === '' ? [] : json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

    return [
        'status_code' => $status,
        'body' => is_array($decoded) ? $decoded : [],
    ];
}

function parameter(string $name, int $position, string $type, bool $required = true, mixed $default = null): array
{
    return [
        'name' => $name,
        'position' => $position,
        'required' => $required,
        'variadic' => false,
        'default_available' => ! $required,
        'default' => $default,
        'type' => $type,
        'allows_null' => false,
    ];
}

function workflow_command_contract(): array
{
    return [
        'queries' => ['state'],
        'query_contracts' => [
            [
                'name' => 'state',
                'parameters' => [],
            ],
        ],
        'signals' => ['advance', 'finish'],
        'signal_contracts' => [
            [
                'name' => 'advance',
                'parameters' => [parameter('name', 0, 'string')],
            ],
            [
                'name' => 'finish',
                'parameters' => [],
            ],
        ],
        'updates' => ['adjust_payload', 'approve', 'fail_update'],
        'update_contracts' => [
            [
                'name' => 'approve',
                'parameters' => [
                    parameter('approved', 0, 'bool'),
                    parameter('source', 1, 'string', false, 'manual'),
                ],
            ],
            [
                'name' => 'adjust_payload',
                'parameters' => [parameter('payload', 0, 'array')],
            ],
            [
                'name' => 'fail_update',
                'parameters' => [parameter('reason', 0, 'string')],
            ],
        ],
        'update_validators' => [],
    ];
}

function register_probe_worker(
    string $workerId = 'workflow-updates-worker',
    string $taskQueue = WORKFLOW_UPDATES_QUEUE,
    array $headers = [],
    array $updateValidators = [],
    bool $supportsUpdateValidation = false,
): void
{
    $contract = workflow_command_contract();
    $contract['update_validators'] = array_values($updateValidators);
    $capabilities = ['workflow_tasks', 'query_tasks'];
    if ($supportsUpdateValidation) {
        $capabilities[] = WorkflowUpdateValidationTaskBroker::CAPABILITY;
    }

    request_json('POST', '/worker/register', DirectConformanceWorkerProtocol::registration(
        $workerId,
        $taskQueue,
        'php',
        'durable-workflow/server:published-artifact',
        [WORKFLOW_UPDATES_TYPE],
        [],
        $capabilities,
        attributes: ['workflow_command_contracts' => [
            WORKFLOW_UPDATES_TYPE => $contract,
        ]],
    ), [409], $headers);
}

function install_update_validation_worker_step(callable $workerStep): void
{
    $signals = app(LongPollSignalStore::class);
    $poller = new class($signals, app(LongPollWaitSlotStore::class)) extends LongPoller
    {
        public mixed $workerStep = null;

        private bool $ranWorkerStep = false;

        public function until(
            callable $probe,
            callable $ready,
            ?int $timeoutSeconds = null,
            ?int $intervalMilliseconds = null,
            array $wakeChannels = [],
            ?callable $nextProbeAt = null,
            bool $reserveWorkerWaitSlot = false,
            string $waitSlotPool = 'worker',
        ): mixed {
            $value = $probe();
            if ($ready($value) || $this->ranWorkerStep) {
                return $value;
            }

            $this->ranWorkerStep = true;
            ($this->workerStep)();

            return $probe();
        }
    };
    $poller->workerStep = $workerStep;
    $controlPlane = app(ServerWorkflowControlPlane::class);
    $broker = new WorkflowUpdateValidationTaskBroker(
        $poller,
        $signals,
        app(WorkflowQueryTaskBroker::class),
        $controlPlane,
    );
    app()->instance(WorkflowUpdateValidationTaskBroker::class, $broker);
    app()->instance(ExternalWorkflowUpdateAdmission::class, new ValidatedExternalWorkflowUpdateAdmission(
        app(ControlPlaneMutationRetrier::class),
        $broker,
        $controlPlane,
    ));
    // The focused probe reuses one HTTP kernel. Rebind the admission service
    // that actually owns validation, then force route controllers to resolve it.
    // Compiled route collections keep the request-matched routes in a separate
    // name cache, while getRoutes() returns newly constructed route instances.
    $routes = app('router')->getRoutes();
    foreach ($routes->getRoutes() as $route) {
        $route->flushController();

        $name = $route->getName();
        if (is_string($name) && $name !== '') {
            $routes->getByName($name)?->flushController();
        }
    }
}

function start_probe_workflow(
    string $workflowId,
    string $taskQueue = WORKFLOW_UPDATES_QUEUE,
    array $headers = [],
): array
{
    return request_json('POST', '/workflows', [
        'workflow_id' => $workflowId,
        'workflow_type' => WORKFLOW_UPDATES_TYPE,
        'task_queue' => $taskQueue,
        'input' => ['focused-probe'],
    ], [], $headers)['body'];
}

function poll_task(
    string $workerId,
    string $taskQueue = WORKFLOW_UPDATES_QUEUE,
    array $headers = [],
): array
{
    $response = request_json('POST', '/worker/workflow-tasks/poll', [
        'worker_id' => $workerId,
        'task_queue' => $taskQueue,
    ], [], $headers);
    $task = $response['body']['task'] ?? null;

    if (! is_array($task) || ! is_string($task['task_id'] ?? null)) {
        throw new RuntimeException('No workflow task was available for '.$workerId.'.');
    }

    return $task;
}

function assert_probe_task_identity(
    array $task,
    string $taskKind,
    string $workerId,
    string $taskQueue,
    string $workflowId,
    string $runId,
    string $taskIdField,
    ?string $expectedTaskId,
    string $attemptField,
    int $expectedAttempt,
): void
{
    $taskId = $task[$taskIdField] ?? null;
    $actual = [
        'task_kind' => $task['task_kind'] ?? null,
        'worker_id' => $task['lease_owner'] ?? null,
        'task_queue' => $task['task_queue'] ?? null,
        'workflow_id' => $task['workflow_id'] ?? null,
        'run_id' => $task['run_id'] ?? null,
        'task_id' => $taskId,
        'delivery_attempt' => $task[$attemptField] ?? null,
    ];
    $expected = [
        'task_kind' => $taskKind,
        'worker_id' => $workerId,
        'task_queue' => $taskQueue,
        'workflow_id' => $workflowId,
        'run_id' => $runId,
        'task_id' => $expectedTaskId,
        'delivery_attempt' => $expectedAttempt,
    ];
    $identityMatches = $actual['task_kind'] === $expected['task_kind']
        && $actual['worker_id'] === $expected['worker_id']
        && $actual['task_queue'] === $expected['task_queue']
        && $actual['workflow_id'] === $expected['workflow_id']
        && $actual['run_id'] === $expected['run_id']
        && is_string($actual['task_id'])
        && $actual['task_id'] !== ''
        && ($expectedTaskId === null || $actual['task_id'] === $expectedTaskId)
        && $actual['delivery_attempt'] === $expected['delivery_attempt'];

    if (! $identityMatches) {
        throw new RuntimeException(sprintf(
            'Multiplexed validator probe task identity mismatch: expected %s; received %s.',
            json_encode($expected, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            json_encode($actual, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ));
    }
}

function poll_update_validation_task(
    string $workerId,
    string $taskQueue,
    string $workflowId,
    string $runId,
    string $requestId,
    int $expectedValidationAttempt,
    array &$drainedWorkflowTasks,
): ?array
{
    $validationTaskId = WorkflowUpdateValidationTask::query()
        ->where('workflow_run_id', $runId)
        ->where('request_id', $requestId)
        ->value('id');

    if (! is_string($validationTaskId) || $validationTaskId === '') {
        throw new RuntimeException('Expected update-validation task identity was not persisted before polling.');
    }

    for ($pollAttempt = 1; $pollAttempt <= 3; $pollAttempt++) {
        $response = request_json('POST', '/worker/workflow-tasks/poll', [
            'worker_id' => $workerId,
            'task_queue' => $taskQueue,
            'task_kinds' => ['workflow', 'update_validation'],
            'timeout_seconds' => 0,
        ]);
        $task = $response['body']['task'] ?? null;

        if ($task === null) {
            return null;
        }

        if (! is_array($task)) {
            throw new RuntimeException('Multiplexed validator poll returned a non-object task.');
        }

        $taskKind = $task['task_kind'] ?? null;
        if ($taskKind === 'update_validation') {
            assert_probe_task_identity(
                $task,
                'update_validation',
                $workerId,
                $taskQueue,
                $workflowId,
                $runId,
                'update_validation_task_id',
                $validationTaskId,
                'update_validation_attempt',
                $expectedValidationAttempt,
            );

            return $task;
        }

        if ($taskKind !== 'workflow') {
            throw new RuntimeException('Multiplexed validator poll returned an invalid task discriminator.');
        }

        assert_probe_task_identity(
            $task,
            'workflow',
            $workerId,
            $taskQueue,
            $workflowId,
            $runId,
            'task_id',
            null,
            'workflow_task_attempt',
            1,
        );
        // Park the run while its synchronous update is still awaiting validation;
        // terminal completion here would change the validator behavior under test.
        $completion = complete_task($task, [
            [
                'type' => 'open_signal_wait',
                'signal_name' => 'advance',
                'timeout_seconds' => 300,
            ],
        ]);
        $drainedWorkflowTasks[] = [
            'task' => [
                'task_kind' => $task['task_kind'],
                'task_id' => $task['task_id'],
                'workflow_id' => $task['workflow_id'],
                'run_id' => $task['run_id'],
                'task_queue' => $task['task_queue'],
                'lease_owner' => $task['lease_owner'],
                'workflow_task_attempt' => $task['workflow_task_attempt'],
            ],
            'completion' => [
                'task_id' => $completion['task_id'] ?? null,
                'run_id' => $completion['run_id'] ?? null,
                'workflow_task_attempt' => $completion['workflow_task_attempt'] ?? null,
                'outcome' => $completion['outcome'] ?? null,
                'reason' => $completion['reason'] ?? null,
            ],
        ];
    }

    throw new RuntimeException('Multiplexed validator poll did not reach validation after draining workflow work.');
}

function validation_task_state(?array $leasedTask): array
{
    $taskId = $leasedTask['update_validation_task_id'] ?? null;
    if (! is_string($taskId) || $taskId === '') {
        return [];
    }

    $task = WorkflowUpdateValidationTask::query()->find($taskId);
    if (! $task instanceof WorkflowUpdateValidationTask) {
        return [];
    }

    return [
        'update_validation_task_id' => $task->id,
        'status' => $task->status,
        'attempt_count' => $task->attempt_count,
        'lease_owner' => $task->lease_owner,
        'lease_expires_at' => $task->lease_expires_at?->toAtomString(),
        'approved_at' => $task->approved_at?->toAtomString(),
        'rejected_at' => $task->rejected_at?->toAtomString(),
        'failed_at' => $task->failed_at?->toAtomString(),
        'timed_out_at' => $task->timed_out_at?->toAtomString(),
    ];
}

function complete_task(array $task, array $commands, array $headers = []): array
{
    $taskId = (string) $task['task_id'];

    return request_json(
        'POST',
        '/worker/workflow-tasks/'.$taskId.'/complete',
        DirectConformanceWorkerProtocol::workflowTaskCompletion($task, $commands),
        [],
        $headers,
    )['body'];
}

function open_signal_wait(
    string $workerId,
    string $taskQueue = WORKFLOW_UPDATES_QUEUE,
    array $headers = [],
): array
{
    $task = poll_task($workerId, $taskQueue, $headers);

    return complete_task($task, [
        [
            'type' => 'open_signal_wait',
            'signal_name' => 'advance',
            'timeout_seconds' => 300,
        ],
    ], $headers);
}

function complete_workflow_start_task(
    string $workerId,
    string $taskQueue = WORKFLOW_UPDATES_QUEUE,
    array $headers = [],
): array
{
    $task = poll_task($workerId, $taskQueue, $headers);

    return complete_task($task, [
        [
            'type' => 'complete_workflow',
            'result' => Avro::serialize([
                'probe' => 'terminal-workflow-update-behavior',
            ]),
        ],
    ], $headers);
}

function complete_update_task(
    string $workerId,
    string $updateId,
    array $result,
    string $taskQueue = WORKFLOW_UPDATES_QUEUE,
    array $headers = [],
): array
{
    $task = poll_task($workerId, $taskQueue, $headers);

    return complete_task($task, [
        [
            'type' => 'complete_update',
            'update_id' => $updateId,
            'result' => Avro::envelope($result),
        ],
    ], $headers);
}

function fail_update_task(
    string $workerId,
    string $updateId,
    string $taskQueue = WORKFLOW_UPDATES_QUEUE,
    array $headers = [],
): array
{
    $task = poll_task($workerId, $taskQueue, $headers);

    return complete_task($task, [
        [
            'type' => 'fail_update',
            'update_id' => $updateId,
            'message' => 'workflow update probe failure',
            'exception_class' => 'DurableWorkflow\\Conformance\\WorkflowUpdateProbeFailure',
            'exception_type' => 'workflow_update_probe_failure',
            'non_retryable' => true,
        ],
    ], $headers);
}

function history_events(string $workflowId, string $runId, array $headers = []): array
{
    return request_json('GET', '/workflows/'.$workflowId.'/runs/'.$runId.'/history', null, [], $headers)['body']['events'] ?? [];
}

function event_types(array $events): array
{
    return array_values(array_map(
        static fn (array $event): ?string => is_string($event['event_type'] ?? null) ? $event['event_type'] : null,
        $events,
    ));
}

function event_by_type(array $events, string $type): ?array
{
    foreach ($events as $event) {
        if (($event['event_type'] ?? null) === $type) {
            return $event;
        }
    }

    return null;
}

function event_request_id(array $event): ?string
{
    $payload = $event['payload'] ?? null;

    if (! is_array($payload)) {
        return null;
    }

    $command = $payload['command'] ?? null;
    $context = is_array($command) ? ($command['context'] ?? null) : null;
    $server = is_array($context) ? ($context['server'] ?? null) : null;
    $metadata = is_array($server) ? ($server['metadata'] ?? null) : null;

    foreach ([
        $payload['request_id'] ?? null,
        is_array($command) ? ($command['request_id'] ?? null) : null,
        is_array($metadata) ? ($metadata['request_id'] ?? null) : null,
    ] as $candidate) {
        if (is_string($candidate) && $candidate !== '') {
            return $candidate;
        }
    }

    return null;
}

function update_row(string $updateId): ?WorkflowUpdate
{
    $update = WorkflowUpdate::query()->find($updateId);

    return $update instanceof WorkflowUpdate ? $update : null;
}

function configure_principal_token_auth(): void
{
    config([
        'server.auth.driver' => 'token',
        'server.auth.token' => null,
        'server.auth.role_tokens' => [
            'worker' => null,
            'operator' => null,
            'admin' => null,
        ],
        'server.auth.principal_tokens' => json_encode([
            [
                'token' => WORKFLOW_UPDATES_AUTH_TOKEN,
                'subject' => WORKFLOW_UPDATES_AUTH_PRINCIPAL_ID,
                'roles' => ['operator', 'worker'],
                'label' => WORKFLOW_UPDATES_AUTH_PRINCIPAL_LABEL,
            ],
        ], JSON_THROW_ON_ERROR),
        'server.auth.backward_compatible' => false,
    ]);
}

function auth_headers(): array
{
    return [
        'Authorization' => 'Bearer '.WORKFLOW_UPDATES_AUTH_TOKEN,
    ];
}

function expected_auth_principal(): array
{
    return [
        'type' => 'auth:token',
        'id' => WORKFLOW_UPDATES_AUTH_PRINCIPAL_ID,
        'label' => WORKFLOW_UPDATES_AUTH_PRINCIPAL_LABEL,
    ];
}

function principal_from_response(array $body): ?array
{
    $controlPlane = $body['control_plane'] ?? null;
    foreach ([
        $body['principal'] ?? null,
        is_array($controlPlane) ? ($controlPlane['principal'] ?? null) : null,
    ] as $candidate) {
        if (is_array($candidate)) {
            return array_filter([
                'type' => is_string($candidate['type'] ?? null) ? $candidate['type'] : null,
                'id' => is_string($candidate['id'] ?? null) ? $candidate['id'] : null,
                'label' => is_string($candidate['label'] ?? null) ? $candidate['label'] : null,
            ], static fn (mixed $value): bool => $value !== null && $value !== '');
        }
    }

    return null;
}

function response_principal_fields(array $body): array
{
    $controlPlane = $body['control_plane'] ?? null;
    $controlPlanePrincipal = is_array($controlPlane) && is_array($controlPlane['principal'] ?? null)
        ? $controlPlane['principal']
        : null;

    return [
        'principal' => is_array($body['principal'] ?? null) ? $body['principal'] : null,
        'control_plane_principal' => $controlPlanePrincipal,
        'workflow_id' => $body['workflow_id'] ?? null,
        'run_id' => $body['run_id'] ?? null,
        'command_id' => $body['command_id'] ?? null,
        'update_id' => $body['update_id'] ?? null,
        'update_status' => $body['update_status'] ?? null,
        'reason' => $body['reason'] ?? null,
        'http_status' => $body['status'] ?? null,
    ];
}

function principal_from_event(?array $event): ?array
{
    if (! is_array($event)) {
        return null;
    }

    $principal = $event['principal'] ?? null;
    if (is_array($principal)) {
        return array_filter([
            'type' => is_string($principal['type'] ?? null) ? $principal['type'] : null,
            'id' => is_string($principal['id'] ?? null) ? $principal['id'] : null,
            'label' => is_string($principal['label'] ?? null) ? $principal['label'] : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    $payload = $event['payload'] ?? null;
    if (! is_array($payload)) {
        return null;
    }

    $command = $payload['command'] ?? null;
    if (! is_array($command)) {
        return null;
    }

    return array_filter([
        'type' => is_string($command['principal_type'] ?? null) ? $command['principal_type'] : null,
        'id' => is_string($command['principal_id'] ?? null) ? $command['principal_id'] : null,
        'label' => is_string($command['principal_label'] ?? null) ? $command['principal_label'] : null,
    ], static fn (mixed $value): bool => $value !== null && $value !== '');
}

function event_by_type_and_request_id(array $events, string $type, string $requestId): ?array
{
    foreach ($events as $event) {
        if (($event['event_type'] ?? null) === $type && event_request_id($event) === $requestId) {
            return $event;
        }
    }

    return null;
}

function run_detail(string $workflowId, string $runId, array $headers = []): array
{
    return request_json('GET', '/workflows/'.$workflowId.'/runs/'.$runId, null, [], $headers)['body'];
}

function command_principal_fields(?array $command): ?array
{
    if (! is_array($command)) {
        return null;
    }

    return [
        'command_id' => $command['id'] ?? null,
        'type' => $command['type'] ?? null,
        'target_name' => $command['target_name'] ?? null,
        'request_id' => $command['request_id'] ?? null,
        'status' => $command['status'] ?? null,
        'outcome' => $command['outcome'] ?? null,
        'rejection_reason' => $command['rejection_reason'] ?? null,
        'principal' => array_filter([
            'type' => is_string($command['principal_type'] ?? null) ? $command['principal_type'] : null,
            'id' => is_string($command['principal_id'] ?? null) ? $command['principal_id'] : null,
            'label' => is_string($command['principal_label'] ?? null) ? $command['principal_label'] : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        'auth_status' => $command['auth_status'] ?? null,
        'auth_method' => $command['auth_method'] ?? null,
        'update_id' => $command['update_id'] ?? null,
        'update_status' => $command['update_status'] ?? null,
    ];
}

function command_by_request_id(array $runDetail, string $requestId): ?array
{
    $commands = $runDetail['commands'] ?? [];
    if (! is_array($commands)) {
        return null;
    }

    foreach ($commands as $command) {
        if (is_array($command) && ($command['request_id'] ?? null) === $requestId) {
            return $command;
        }
    }

    return null;
}

function principal_matches(?array $principal, array $expected): bool
{
    return is_array($principal)
        && ($principal['type'] ?? null) === $expected['type']
        && ($principal['id'] ?? null) === $expected['id']
        && ($principal['label'] ?? null) === $expected['label'];
}

function env_text(string $name): string
{
    $value = getenv($name);

    return is_string($value) ? trim($value) : '';
}

function server_version_from_image(string $image): string
{
    if ($image === '' || str_contains($image, '@sha256:')) {
        return '';
    }

    if (preg_match('/:([^\/:]+)$/', $image, $matches) === 1) {
        return (string) $matches[1];
    }

    return '';
}

function artifact_versions(): array
{
    $serverImage = env_text('DW_SERVER_IMAGE');
    $serverVersion = env_text('DW_SERVER_VERSION') ?: server_version_from_image($serverImage) ?: 'unresolved';
    $cliVersion = env_text('DW_CLI_VERSION') ?: 'unresolved';
    $phpSdkVersion = env_text('DW_PHP_SDK_VERSION') ?: 'unresolved';
    $pythonVersion = env_text('DW_PYTHON_SDK_VERSION') ?: 'unresolved';
    $workflowVersion = env_text('DW_WORKFLOW_PHP_VERSION') ?: 'unresolved';
    $waterlineVersion = env_text('DW_WATERLINE_VERSION') ?: 'unresolved';

    return [
        'server' => $serverVersion,
        'cli' => $cliVersion,
        'sdk-php' => $phpSdkVersion,
        'sdk-python' => $pythonVersion,
        'workflow' => $workflowVersion,
        'workflow-php' => $workflowVersion,
        'waterline' => $waterlineVersion,
    ];
}

function artifact_sources(): array
{
    $versions = artifact_versions();
    $serverImage = env_text('DW_SERVER_IMAGE');

    return [
        'server' => $serverImage !== '' ? $serverImage : 'docker://durableworkflow/server:'.$versions['server'],
        'cli' => 'https://github.com/durable-workflow/cli/releases/download/'.$versions['cli'].'/install.sh',
        'sdk-php' => 'packagist://durable-workflow/sdk@'.$versions['sdk-php'],
        'sdk-python' => 'pypi://durable-workflow=='.$versions['sdk-python'],
        'workflow' => 'packagist://durable-workflow/workflow@'.$versions['workflow'],
        'workflow-php' => 'packagist://durable-workflow/workflow@'.$versions['workflow-php'],
        'waterline' => 'packagist://durable-workflow/waterline@'.$versions['waterline'],
    ];
}

function focused_source_policy(): array
{
    return [
        'pass_requires_published_artifacts_only' => true,
        'local_product_source_checkouts_used' => false,
        'local_checkout_execution_counts_as_pass' => false,
    ];
}

function published_artifact_install_evidence(): array
{
    $versions = artifact_versions();
    $sources = artifact_sources();
    $evidence = [];

    foreach ($sources as $artifact => $source) {
        $versionKey = $artifact === 'workflow-php' ? 'workflow' : $artifact;
        $evidence[$artifact] = [
            'installed_from' => $source,
            'version' => $versions[$artifact] ?? $versions[$versionKey] ?? 'unresolved',
        ];
    }

    return $evidence;
}

function published_artifact_install_observed_outputs(): array
{
    return [
        'server_image_execution_source' => 'published_server_container',
        'runner_path' => 'scripts/conformance/workflow-updates-published-artifacts.sh',
        'published_artifact_versions' => artifact_versions(),
        'artifact_sources' => artifact_sources(),
        'artifact_install_evidence' => published_artifact_install_evidence(),
        'local_product_source_checkouts_used' => false,
        'source_policy' => focused_source_policy(),
    ];
}

function common_observed_outputs(): array
{
    return [
        'published_artifact_versions' => artifact_versions(),
        'artifact_sources' => artifact_sources(),
        'implementation_identity' => [
            'runner' => 'published-server-workflow-updates-focused-probe',
            'server_image_execution_source' => 'published_server_container',
        ],
        'runtime_matrix' => [
            'server' => 'published-server-image',
            'worker_protocol' => 'raw-api',
            'control_plane_client' => 'raw-api',
        ],
        'source_policy' => focused_source_policy(),
    ];
}

function pass_result(string $scenarioId, array $observedOutputs): array
{
    return [
        'scenario_id' => $scenarioId,
        'status' => 'pass',
        'classification' => 'product-evidence',
        'published_artifact_cell_executed' => true,
        'local_product_source_checkouts_used' => false,
        'observed_outputs' => $observedOutputs + common_observed_outputs() + [
            'published_artifact_cell_executed' => true,
            'local_product_source_checkouts_used' => false,
        ],
        'linked_findings' => [],
    ];
}

function fail_result(string $scenarioId, string $summary, array $observedOutputs = []): array
{
    return [
        'scenario_id' => $scenarioId,
        'status' => 'fail',
        'classification' => 'product-gap',
        'published_artifact_cell_executed' => true,
        'local_product_source_checkouts_used' => false,
        'observed_outputs' => $observedOutputs + common_observed_outputs() + [
            'published_artifact_cell_executed' => true,
            'local_product_source_checkouts_used' => false,
        ],
        'linked_findings' => [
            [
                'finding_id' => 'workflow-updates-'.$scenarioId.'-product-gap',
                'finding_type' => 'product_behavior_failure',
                'classification' => 'product-gap',
                'scenario_id' => $scenarioId,
                'owning_surface' => 'server',
                'summary' => $summary,
                'next_acceptance_criterion' => 'Make the published server workflow update runtime cell satisfy the public workflow update conformance contract.',
            ],
        ],
    ];
}

function throwable_diagnostic(Throwable $throwable): array
{
    return [
        'exception_class' => get_class($throwable),
        'message' => $throwable->getMessage(),
        'file' => basename($throwable->getFile()),
        'line' => $throwable->getLine(),
    ];
}

function exception_fail_result(
    string $scenarioId,
    string $summary,
    Throwable $throwable,
    array $observedOutputs = [],
): array {
    return fail_result($scenarioId, $summary, $observedOutputs + [
        'diagnostic' => throwable_diagnostic($throwable),
    ]);
}

function focused_probe_scenario_ids(): array
{
    return [
        'published_artifact_install_only',
        'declared_update_contract_visibility',
        'accepted_update_control_plane_and_history',
        'running_or_waiting_update_operator_visibility',
        'completed_update_result_round_trip',
        'failed_update_outcome',
        'duplicate_request_idempotency',
        'unknown_update_refusal',
        'invalid_input_refusal',
        'payload_envelope_round_trip',
        'terminal_workflow_update_behavior',
        'principal_attribution_with_auth',
        'update_validator_approval_boundary',
        'update_validator_rejection_boundary',
        'update_validator_worker_replacement',
        'duplicate_validation_completion',
        'unsupported_validation_capability',
    ];
}

function focused_probe_failure_evidence(Throwable $throwable): array
{
    $diagnostic = throwable_diagnostic($throwable);
    $scenarioResults = [
        'published_artifact_install_only' => pass_result(
            'published_artifact_install_only',
            published_artifact_install_observed_outputs() + ['probe_failure_diagnostic' => $diagnostic],
        ),
    ];

    foreach (focused_probe_scenario_ids() as $scenarioId) {
        if ($scenarioId === 'published_artifact_install_only') {
            continue;
        }

        $scenarioResults[$scenarioId] = exception_fail_result(
            $scenarioId,
            'The published server workflow update focused probe failed before this runtime cell could be collected.',
            $throwable,
        );
    }

    return [
        'schema' => 'durable-workflow.v2.workflow-update-runtime.focused-evidence',
        'generated_at' => now_iso(),
        'runner' => 'published-server-workflow-updates-focused-probe',
        'runner_blocked' => false,
        'source_policy' => focused_source_policy(),
        'scenario_results' => $scenarioResults,
        'observed_outputs' => [
            'probe_failure_diagnostic' => $diagnostic,
        ],
        'findings' => [
            [
                'finding_id' => 'workflow-updates-focused-probe-product-gap',
                'finding_type' => 'product_behavior_failure',
                'classification' => 'product-gap',
                'owning_surface' => 'server',
                'summary' => 'The published server workflow updates focused probe failed before all runtime cells could be collected.',
                'next_acceptance_criterion' => 'Make the focused workflow updates runtime probe execute accepted, completed, failed, refusal, duplicate, terminal, and payload cells against the published server image.',
                'diagnostic' => $diagnostic,
            ],
        ],
    ];
}

function run_principal_attribution_probe(string $suffix): array
{
    $previousAuthConfig = [
        'server.auth.driver' => config('server.auth.driver'),
        'server.auth.token' => config('server.auth.token'),
        'server.auth.role_tokens' => config('server.auth.role_tokens'),
        'server.auth.principal_tokens' => config('server.auth.principal_tokens'),
        'server.auth.backward_compatible' => config('server.auth.backward_compatible'),
    ];
    configure_principal_token_auth();

    $headers = auth_headers();
    $expected = expected_auth_principal();
    $workerId = 'workflow-updates-auth-worker-'.$suffix;
    $taskQueue = WORKFLOW_UPDATES_QUEUE.'-auth-'.$suffix;
    $workflowId = 'wf-update-auth-'.$suffix;
    $requestIds = [
        'accepted' => 'auth-accepted-'.$suffix,
        'failed' => 'auth-failed-'.$suffix,
        'unknown' => 'auth-unknown-'.$suffix,
        'invalid' => 'auth-invalid-'.$suffix,
        'terminal' => 'auth-terminal-'.$suffix,
    ];
    $controlPlanePrincipalFields = [];
    $historyPrincipalFields = [];
    $operatorPrincipalFields = [];

    try {
        register_probe_worker($workerId, $taskQueue, $headers);
        $start = start_probe_workflow($workflowId, $taskQueue, $headers);
        $runId = (string) ($start['run_id'] ?? '');
        open_signal_wait($workerId, $taskQueue, $headers);

        $acceptedResponse = request_json('POST', '/workflows/'.$workflowId.'/update/approve', [
            'input' => [true, 'auth-accepted'],
            'request_id' => $requestIds['accepted'],
            'wait_for' => 'accepted',
            'principal' => 'mallory',
            'principal_id' => 'mallory',
            'actor' => 'mallory',
        ], [], $headers);
        $acceptedBody = $acceptedResponse['body'];
        $acceptedUpdateId = (string) ($acceptedBody['update_id'] ?? '');
        $acceptedHistory = history_events($workflowId, $runId, $headers);
        $acceptedEvent = event_by_type_and_request_id(
            $acceptedHistory,
            WORKFLOW_UPDATE_ACCEPTED_EVENT,
            $requestIds['accepted'],
        );

        $completeResult = complete_update_task($workerId, $acceptedUpdateId, [
            'approved' => true,
            'source' => 'auth-principal-complete',
        ], $taskQueue, $headers);

        $completedResponse = request_json('POST', '/workflows/'.$workflowId.'/update/approve', [
            'input' => [true, 'auth-accepted-duplicate'],
            'request_id' => $requestIds['accepted'],
            'wait_for' => 'completed',
        ], [200, 202, 409, 422], $headers);
        $completedBody = $completedResponse['body'];
        $completedHistory = history_events($workflowId, $runId, $headers);
        $completedEvent = event_by_type_and_request_id(
            $completedHistory,
            WORKFLOW_UPDATE_COMPLETED_EVENT,
            $requestIds['accepted'],
        );

        $failedResponse = request_json('POST', '/workflows/'.$workflowId.'/update/fail_update', [
            'input' => ['auth failure'],
            'request_id' => $requestIds['failed'],
            'wait_for' => 'accepted',
            'principal' => 'mallory',
        ], [], $headers);
        $failedBody = $failedResponse['body'];
        $failedUpdateId = (string) ($failedBody['update_id'] ?? '');
        $failResult = fail_update_task($workerId, $failedUpdateId, $taskQueue, $headers);
        $failedCompletedResponse = request_json('POST', '/workflows/'.$workflowId.'/update/fail_update', [
            'input' => ['auth failure duplicate'],
            'request_id' => $requestIds['failed'],
            'wait_for' => 'completed',
        ], [200, 202, 409, 422], $headers);
        $failedCompletedBody = $failedCompletedResponse['body'];
        $failedHistory = history_events($workflowId, $runId, $headers);
        $failedAcceptedEvent = event_by_type_and_request_id(
            $failedHistory,
            WORKFLOW_UPDATE_ACCEPTED_EVENT,
            $requestIds['failed'],
        );
        $failedCompletedEvent = event_by_type_and_request_id(
            $failedHistory,
            WORKFLOW_UPDATE_COMPLETED_EVENT,
            $requestIds['failed'],
        );

        $unknown = request_json('POST', '/workflows/'.$workflowId.'/update/missing_update', [
            'input' => [],
            'request_id' => $requestIds['unknown'],
            'principal_id' => 'mallory',
        ], [404, 409, 422], $headers);
        $invalid = request_json('POST', '/workflows/'.$workflowId.'/update/approve', [
            'input' => ['not-a-bool'],
            'request_id' => $requestIds['invalid'],
            'principal_id' => 'mallory',
        ], [404, 409, 422], $headers);

        $terminalWorkflowId = 'wf-update-auth-terminal-'.$suffix;
        $terminalWorkerId = 'workflow-updates-auth-terminal-worker-'.$suffix;
        $terminalQueue = $taskQueue.'-terminal';
        register_probe_worker($terminalWorkerId, $terminalQueue, $headers);
        $terminalStart = start_probe_workflow($terminalWorkflowId, $terminalQueue, $headers);
        $terminalRunId = (string) ($terminalStart['run_id'] ?? '');
        complete_workflow_start_task($terminalWorkerId, $terminalQueue, $headers);
        $terminal = request_json('POST', '/workflows/'.$terminalWorkflowId.'/update/approve', [
            'input' => [true, 'terminal'],
            'request_id' => $requestIds['terminal'],
            'principal_id' => 'mallory',
        ], [404, 409, 422], $headers);

        $runDetail = run_detail($workflowId, $runId, $headers);
        $terminalRunDetail = run_detail($terminalWorkflowId, $terminalRunId, $headers);

        $controlPlanePrincipalFields = [
            'accepted' => response_principal_fields($acceptedBody),
            'completed' => response_principal_fields($completedBody),
            'failed_accepted' => response_principal_fields($failedBody),
            'failed_completed' => response_principal_fields($failedCompletedBody),
            'refused' => [
                'unknown' => response_principal_fields($unknown['body']),
                'invalid' => response_principal_fields($invalid['body']),
                'terminal' => response_principal_fields($terminal['body']),
            ],
        ];

        $historyPrincipalFields = [
            'accepted' => [
                'UpdateAccepted' => [
                    'request_id' => $requestIds['accepted'],
                    'principal' => principal_from_event($acceptedEvent),
                    'event' => $acceptedEvent,
                ],
                'UpdateCompleted' => [
                    'request_id' => $requestIds['accepted'],
                    'principal' => principal_from_event($completedEvent),
                    'event' => $completedEvent,
                ],
            ],
            'failed' => [
                'UpdateAccepted' => [
                    'request_id' => $requestIds['failed'],
                    'principal' => principal_from_event($failedAcceptedEvent),
                    'event' => $failedAcceptedEvent,
                ],
                'UpdateCompleted' => [
                    'request_id' => $requestIds['failed'],
                    'principal' => principal_from_event($failedCompletedEvent),
                    'event' => $failedCompletedEvent,
                ],
            ],
        ];

        foreach ($requestIds as $name => $requestId) {
            $detail = $name === 'terminal' ? $terminalRunDetail : $runDetail;
            $operatorPrincipalFields[$name] = command_principal_fields(command_by_request_id($detail, $requestId));
        }

        $principalSamples = [
            'control_plane.accepted' => principal_from_response($acceptedBody),
            'control_plane.completed' => principal_from_response($completedBody),
            'control_plane.failed_accepted' => principal_from_response($failedBody),
            'control_plane.failed_completed' => principal_from_response($failedCompletedBody),
            'control_plane.refused.unknown' => principal_from_response($unknown['body']),
            'control_plane.refused.invalid' => principal_from_response($invalid['body']),
            'control_plane.refused.terminal' => principal_from_response($terminal['body']),
            'history.accepted.UpdateAccepted' => principal_from_event($acceptedEvent),
            'history.accepted.UpdateCompleted' => principal_from_event($completedEvent),
            'history.failed.UpdateAccepted' => principal_from_event($failedAcceptedEvent),
            'history.failed.UpdateCompleted' => principal_from_event($failedCompletedEvent),
            'operator.accepted' => $operatorPrincipalFields['accepted']['principal'] ?? null,
            'operator.failed' => $operatorPrincipalFields['failed']['principal'] ?? null,
            'operator.refused.unknown' => $operatorPrincipalFields['unknown']['principal'] ?? null,
            'operator.refused.invalid' => $operatorPrincipalFields['invalid']['principal'] ?? null,
            'operator.refused.terminal' => $operatorPrincipalFields['terminal']['principal'] ?? null,
        ];
        $mismatches = [];

        foreach ($principalSamples as $sample => $principal) {
            if (! principal_matches($principal, $expected)) {
                $mismatches[$sample] = $principal;
            }
        }

        $observedOutputs = [
            'auth_mode' => 'token',
            'principal' => $expected,
            'update_request_surface' => [
                'client' => 'raw-http-token',
                'worker_protocol' => 'raw-http-token',
                'workflow_id' => $workflowId,
                'run_id' => $runId,
                'terminal_workflow_id' => $terminalWorkflowId,
                'terminal_run_id' => $terminalRunId,
                'request_ids' => $requestIds,
                'spoofed_request_fields' => ['principal', 'principal_id', 'actor'],
            ],
            'control_plane_principal_fields' => $controlPlanePrincipalFields,
            'history_principal_fields' => $historyPrincipalFields,
            'waterline_principal_fields' => [
                'operator_surface' => 'run-detail-api',
                'server_run_detail_command_principals' => $operatorPrincipalFields,
                'waterline_selected_run_detail' => null,
                'waterline_update_history' => null,
                'waterline_ui_coverage_remains_in_operator_diagnostics_scenario' => true,
            ],
            'operator_visible_diagnostics' => [
                'run_detail_api' => [
                    'workflow_id' => $workflowId,
                    'run_id' => $runId,
                    'command_principal_fields' => $operatorPrincipalFields,
                ],
                'worker_complete_response' => $completeResult,
                'worker_fail_response' => $failResult,
            ],
            'principal_samples' => $principalSamples,
            'principal_mismatches' => $mismatches,
        ];

        return $mismatches === []
            ? pass_result('principal_attribution_with_auth', $observedOutputs)
            : fail_result(
                'principal_attribution_with_auth',
                'The published server workflow update auth probe did not expose the authenticated principal on every accepted, completed, failed, and refused update path.',
                $observedOutputs,
            );
    } catch (Throwable $throwable) {
        return exception_fail_result(
            'principal_attribution_with_auth',
            'The published server workflow update auth-principal probe failed before all principal observations could be collected.',
            $throwable,
            [
                'auth_mode' => 'token',
                'principal' => $expected,
                'update_request_surface' => [
                    'client' => 'raw-http-token',
                    'worker_protocol' => 'raw-http-token',
                    'workflow_id' => $workflowId,
                    'request_ids' => $requestIds,
                ],
                'control_plane_principal_fields' => $controlPlanePrincipalFields,
                'history_principal_fields' => $historyPrincipalFields,
                'waterline_principal_fields' => [
                    'operator_surface' => 'run-detail-api',
                    'server_run_detail_command_principals' => $operatorPrincipalFields,
                    'waterline_ui_coverage_remains_in_operator_diagnostics_scenario' => true,
                ],
            ],
        );
    } finally {
        config($previousAuthConfig);
    }
}

function run_update_validator_probe(string $suffix): array
{
    $results = [];
    $approvalQueue = WORKFLOW_UPDATES_QUEUE.'-validator-approval-'.$suffix;
    $approvalWorker = 'workflow-updates-validator-approval-'.$suffix;
    register_probe_worker(
        $approvalWorker,
        $approvalQueue,
        updateValidators: ['approve'],
        supportsUpdateValidation: true,
    );

    $approvalWorkflowId = 'wf-update-validator-approval-'.$suffix;
    $approvalStart = start_probe_workflow($approvalWorkflowId, $approvalQueue);
    $approvalRunId = (string) ($approvalStart['run_id'] ?? '');
    $approvalRequestId = 'validator-approved-'.$suffix;
    $approvalTask = null;
    $approvalResponse = null;
    $acceptedAbsentBeforeApproval = false;
    $approvalDrainedWorkflowTasks = [];
    install_update_validation_worker_step(function () use (
        $approvalWorker,
        $approvalQueue,
        $approvalWorkflowId,
        $approvalRunId,
        $approvalRequestId,
        &$approvalTask,
        &$approvalResponse,
        &$acceptedAbsentBeforeApproval,
        &$approvalDrainedWorkflowTasks,
    ): void {
        $approvalTask = poll_update_validation_task(
            $approvalWorker,
            $approvalQueue,
            $approvalWorkflowId,
            $approvalRunId,
            $approvalRequestId,
            1,
            $approvalDrainedWorkflowTasks,
        );
        if (! is_array($approvalTask)) {
            throw new RuntimeException('Validator approval task was not leased.');
        }

        $acceptedAbsentBeforeApproval = WorkflowUpdate::query()
            ->where('workflow_run_id', $approvalRunId)
            ->doesntExist()
            && WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $approvalRunId)
                ->where('event_type', WORKFLOW_UPDATE_ACCEPTED_EVENT)
                ->doesntExist();
        $taskId = (string) $approvalTask['update_validation_task_id'];
        $approvalResponse = request_json('POST', '/worker/update-validation-tasks/'.$taskId.'/approve', [
            'lease_owner' => (string) $approvalTask['lease_owner'],
            'update_validation_attempt' => (int) $approvalTask['update_validation_attempt'],
        ]);
    });
    $accepted = request_json('POST', '/workflows/'.$approvalWorkflowId.'/update/approve', [
        'input' => [true, 'validator-approved'],
        'request_id' => $approvalRequestId,
        'wait_for' => 'accepted',
    ], [409, 422, 504]);
    $approvalTaskState = validation_task_state($approvalTask);
    $approvalHistory = history_events($approvalWorkflowId, $approvalRunId);
    $approvalObserved = [
        'declared_update_validators' => ['approve'],
        'validation_task' => $approvalTask,
        'validation_task_terminal_state' => $approvalTaskState,
        'accepted_state_absent_before_approval' => $acceptedAbsentBeforeApproval,
        'handler_not_invoked_during_validation' => $acceptedAbsentBeforeApproval,
        'multiplexed_workflow_tasks_drained' => $approvalDrainedWorkflowTasks,
        'approval_response' => $approvalResponse,
        'accepted_response' => $accepted,
        'history_update_accepted_event' => event_by_type($approvalHistory, WORKFLOW_UPDATE_ACCEPTED_EVENT),
    ];
    $approvalPassed = $acceptedAbsentBeforeApproval
        && is_array($approvalTask)
        && ($approvalTask['lease_owner'] ?? null) === $approvalWorker
        && ($approvalTask['update_validation_attempt'] ?? null) === 1
        && ($approvalTaskState['status'] ?? null) === WorkflowUpdateValidationTask::STATUS_APPROVED
        && ($approvalTaskState['attempt_count'] ?? null) === 1
        && ($approvalTaskState['lease_owner'] ?? null) === $approvalWorker
        && ($approvalResponse['body']['outcome'] ?? null) === 'approved'
        && $accepted['status_code'] === 202
        && ($accepted['body']['update_id'] ?? null) === ($approvalTask['update_validation_task_id'] ?? null)
        && ($accepted['body']['update_status'] ?? null) === 'accepted';
    $results['update_validator_approval_boundary'] = $approvalPassed
        ? pass_result('update_validator_approval_boundary', $approvalObserved)
        : fail_result(
            'update_validator_approval_boundary',
            'The published server did not hold accepted state behind validator approval.',
            $approvalObserved,
        );

    $duplicateCompletion = is_array($approvalTask)
        ? request_json(
            'POST',
            '/worker/update-validation-tasks/'.(string) $approvalTask['update_validation_task_id'].'/approve',
            [
                'lease_owner' => (string) $approvalTask['lease_owner'],
                'update_validation_attempt' => (int) $approvalTask['update_validation_attempt'],
            ],
            [409],
        )
        : ['status_code' => 0, 'body' => []];
    $acceptedCount = count(array_filter(
        $approvalHistory,
        static fn (array $event): bool => ($event['event_type'] ?? null) === WORKFLOW_UPDATE_ACCEPTED_EVENT,
    ));
    $duplicateObserved = [
        'validation_task_id' => $approvalTask['update_validation_task_id'] ?? null,
        'terminal_outcome' => $approvalResponse['body']['outcome'] ?? null,
        'duplicate_completion_response' => $duplicateCompletion,
        'update_history_event_count' => $acceptedCount,
    ];
    $results['duplicate_validation_completion'] = ($duplicateCompletion['body']['reason'] ?? null)
        === 'duplicate_update_validation_completion' && $acceptedCount === 1
        ? pass_result('duplicate_validation_completion', $duplicateObserved)
        : fail_result(
            'duplicate_validation_completion',
            'The published server did not fence duplicate validator completion.',
            $duplicateObserved,
        );

    $rejectionQueue = WORKFLOW_UPDATES_QUEUE.'-validator-rejection-'.$suffix;
    $rejectionWorker = 'workflow-updates-validator-rejection-'.$suffix;
    register_probe_worker(
        $rejectionWorker,
        $rejectionQueue,
        updateValidators: ['approve'],
        supportsUpdateValidation: true,
    );
    $rejectionWorkflowId = 'wf-update-validator-rejection-'.$suffix;
    $rejectionStart = start_probe_workflow($rejectionWorkflowId, $rejectionQueue);
    $rejectionRunId = (string) ($rejectionStart['run_id'] ?? '');
    $rejectionRequestId = 'validator-rejected-'.$suffix;
    $rejectionTask = null;
    $validatorRejection = null;
    $rejectionDrainedWorkflowTasks = [];
    install_update_validation_worker_step(function () use (
        $rejectionWorker,
        $rejectionQueue,
        $rejectionWorkflowId,
        $rejectionRunId,
        $rejectionRequestId,
        &$rejectionTask,
        &$validatorRejection,
        &$rejectionDrainedWorkflowTasks,
    ): void {
        $rejectionTask = poll_update_validation_task(
            $rejectionWorker,
            $rejectionQueue,
            $rejectionWorkflowId,
            $rejectionRunId,
            $rejectionRequestId,
            1,
            $rejectionDrainedWorkflowTasks,
        );
        if (! is_array($rejectionTask)) {
            throw new RuntimeException('Validator rejection task was not leased.');
        }
        $validatorRejection = request_json(
            'POST',
            '/worker/update-validation-tasks/'.(string) $rejectionTask['update_validation_task_id'].'/reject',
            [
                'lease_owner' => (string) $rejectionTask['lease_owner'],
                'update_validation_attempt' => (int) $rejectionTask['update_validation_attempt'],
                'failure' => [
                    'reason' => 'update_validator_rejected',
                    'message' => 'approval is required',
                    'type' => 'ValueError',
                    'validation_errors' => ['approved' => ['must be true']],
                ],
            ],
        );
    });
    $rejected = request_json('POST', '/workflows/'.$rejectionWorkflowId.'/update/approve', [
        'input' => [false, 'validator-rejected'],
        'request_id' => $rejectionRequestId,
    ], [422]);
    $rejectionTaskState = validation_task_state($rejectionTask);
    $rejectionHistory = history_events($rejectionWorkflowId, $rejectionRunId);
    $rejectedAcceptedCount = count(array_filter(
        $rejectionHistory,
        static fn (array $event): bool => ($event['event_type'] ?? null) === WORKFLOW_UPDATE_ACCEPTED_EVENT,
    ));
    $rejectionObserved = [
        'validation_task' => $rejectionTask,
        'validation_task_terminal_state' => $rejectionTaskState,
        'validator_rejection' => $validatorRejection,
        'multiplexed_workflow_tasks_drained' => $rejectionDrainedWorkflowTasks,
        'typed_update_response' => $rejected,
        'accepted_history_event_count' => $rejectedAcceptedCount,
        'rejected_history_event' => event_by_type($rejectionHistory, HistoryEventType::UpdateRejected->value),
        'handler_not_invoked' => $rejectedAcceptedCount === 0,
    ];
    $rejectionPassed = $rejected['status_code'] === 422
        && is_array($rejectionTask)
        && ($rejectionTask['lease_owner'] ?? null) === $rejectionWorker
        && ($rejectionTask['update_validation_attempt'] ?? null) === 1
        && ($rejectionTaskState['status'] ?? null) === WorkflowUpdateValidationTask::STATUS_REJECTED
        && ($rejectionTaskState['attempt_count'] ?? null) === 1
        && ($rejectionTaskState['lease_owner'] ?? null) === $rejectionWorker
        && ($validatorRejection['body']['outcome'] ?? null) === 'rejected'
        && ($rejected['body']['reason'] ?? null) === 'update_validator_rejected'
        && ($rejected['body']['update_status'] ?? null) === 'rejected'
        && $rejectedAcceptedCount === 0
        && event_by_type($rejectionHistory, HistoryEventType::UpdateRejected->value) !== [];
    $results['update_validator_rejection_boundary'] = $rejectionPassed
        ? pass_result('update_validator_rejection_boundary', $rejectionObserved)
        : fail_result(
            'update_validator_rejection_boundary',
            'The published server did not return a typed pre-accept validator rejection.',
            $rejectionObserved,
        );

    $replacementQueue = WORKFLOW_UPDATES_QUEUE.'-validator-replacement-'.$suffix;
    $oldWorker = 'workflow-updates-validator-old-'.$suffix;
    $newWorker = 'workflow-updates-validator-new-'.$suffix;
    register_probe_worker($oldWorker, $replacementQueue, updateValidators: ['approve'], supportsUpdateValidation: true);
    register_probe_worker($newWorker, $replacementQueue, updateValidators: ['approve'], supportsUpdateValidation: true);
    $replacementWorkflowId = 'wf-update-validator-replacement-'.$suffix;
    $replacementStart = start_probe_workflow($replacementWorkflowId, $replacementQueue);
    $replacementRunId = (string) ($replacementStart['run_id'] ?? '');
    $replacementRequestId = 'validator-replacement-'.$suffix;
    $firstDelivery = null;
    $replacementDelivery = null;
    $staleCompletion = null;
    $replacementDrainedWorkflowTasks = [];
    $replacementFairnessState = [];
    install_update_validation_worker_step(function () use (
        $oldWorker,
        $newWorker,
        $replacementQueue,
        $replacementWorkflowId,
        $replacementRunId,
        $replacementRequestId,
        &$firstDelivery,
        &$replacementDelivery,
        &$staleCompletion,
        &$replacementDrainedWorkflowTasks,
        &$replacementFairnessState,
    ): void {
        $firstDelivery = poll_update_validation_task(
            $oldWorker,
            $replacementQueue,
            $replacementWorkflowId,
            $replacementRunId,
            $replacementRequestId,
            1,
            $replacementDrainedWorkflowTasks,
        );
        if (! is_array($firstDelivery)) {
            throw new RuntimeException('Original validator worker did not lease the task.');
        }
        WorkerRegistration::query()
            ->where('namespace', WORKFLOW_UPDATES_NAMESPACE)
            ->where('worker_id', $oldWorker)
            ->update(['last_heartbeat_at' => now()->subMinute()]);
        WorkflowUpdateValidationTask::query()
            ->findOrFail((string) $firstDelivery['update_validation_task_id'])
            ->forceFill(['lease_expires_at' => now()->subSecond()])
            ->save();
        $replacementFairnessState = [
            'next_task_kind' => \Illuminate\Support\Facades\DB::table('workflow_task_poll_cursors')
                ->where('namespace', WORKFLOW_UPDATES_NAMESPACE)
                ->where('task_queue', $replacementQueue)
                ->value('next_task_kind'),
            'workflow_ready' => WorkflowTask::query()
                ->where('workflow_run_id', $replacementRunId)
                ->where('task_type', 'workflow')
                ->where('status', 'ready')
                ->exists(),
            'validation_reclaimable' => WorkflowUpdateValidationTask::query()
                ->whereKey((string) $firstDelivery['update_validation_task_id'])
                ->where('status', WorkflowUpdateValidationTask::STATUS_LEASED)
                ->where('lease_expires_at', '<=', now())
                ->exists(),
        ];
        $replacementDelivery = poll_update_validation_task(
            $newWorker,
            $replacementQueue,
            $replacementWorkflowId,
            $replacementRunId,
            $replacementRequestId,
            2,
            $replacementDrainedWorkflowTasks,
        );
        if (! is_array($replacementDelivery)) {
            throw new RuntimeException('Replacement validator worker did not reclaim the task.');
        }
        request_json(
            'POST',
            '/worker/update-validation-tasks/'.(string) $replacementDelivery['update_validation_task_id'].'/approve',
            [
                'lease_owner' => (string) $replacementDelivery['lease_owner'],
                'update_validation_attempt' => (int) $replacementDelivery['update_validation_attempt'],
            ],
        );
        $staleCompletion = request_json(
            'POST',
            '/worker/update-validation-tasks/'.(string) $firstDelivery['update_validation_task_id'].'/approve',
            [
                'lease_owner' => (string) $firstDelivery['lease_owner'],
                'update_validation_attempt' => (int) $firstDelivery['update_validation_attempt'],
            ],
            [409],
        );
    });
    $replacementAccepted = request_json('POST', '/workflows/'.$replacementWorkflowId.'/update/approve', [
        'input' => [true, 'replacement-approved'],
        'request_id' => $replacementRequestId,
    ], [409, 422, 504]);
    $replacementObserved = [
        'first_delivery' => $firstDelivery,
        'replacement_delivery' => $replacementDelivery,
        'replacement_attempt' => $replacementDelivery['update_validation_attempt'] ?? null,
        'fairness_state_before_replacement_poll' => $replacementFairnessState,
        'multiplexed_workflow_tasks_drained' => $replacementDrainedWorkflowTasks,
        'accepted_response' => $replacementAccepted,
        'stale_completion_response' => $staleCompletion,
    ];
    $replacementPassed = $replacementAccepted['status_code'] === 202
        && ($replacementDelivery['update_validation_attempt'] ?? null) === 2
        && ($replacementFairnessState['next_task_kind'] ?? null) === 'workflow'
        && ($replacementFairnessState['workflow_ready'] ?? null) === true
        && ($replacementFairnessState['validation_reclaimable'] ?? null) === true
        && count($replacementDrainedWorkflowTasks) === 1
        && ($replacementDrainedWorkflowTasks[0]['task']['task_kind'] ?? null) === 'workflow'
        && ($staleCompletion['body']['reason'] ?? null) === 'stale_update_validation_completion';
    $results['update_validator_worker_replacement'] = $replacementPassed
        ? pass_result('update_validator_worker_replacement', $replacementObserved)
        : fail_result(
            'update_validator_worker_replacement',
            'The published server did not reclaim a lost validator lease and fence stale completion.',
            $replacementObserved,
        );

    $unsupportedQueue = WORKFLOW_UPDATES_QUEUE.'-validator-unsupported-'.$suffix;
    $unsupportedWorker = 'workflow-updates-validator-unsupported-'.$suffix;
    register_probe_worker(
        $unsupportedWorker,
        $unsupportedQueue,
        updateValidators: ['approve'],
        supportsUpdateValidation: false,
    );
    $unsupportedWorkflowId = 'wf-update-validator-unsupported-'.$suffix;
    $unsupportedStart = start_probe_workflow($unsupportedWorkflowId, $unsupportedQueue);
    $unsupportedRunId = (string) ($unsupportedStart['run_id'] ?? '');
    $unsupported = request_json('POST', '/workflows/'.$unsupportedWorkflowId.'/update/approve', [
        'input' => [true, 'unsupported'],
        'request_id' => 'validator-unsupported-'.$suffix,
    ], [409]);
    $unsupportedHistory = history_events($unsupportedWorkflowId, $unsupportedRunId);
    $unsupportedAcceptedCount = count(array_filter(
        $unsupportedHistory,
        static fn (array $event): bool => ($event['event_type'] ?? null) === WORKFLOW_UPDATE_ACCEPTED_EVENT,
    ));
    $unsupportedObserved = [
        'server_capability_discovery' => WorkerProtocol::serverCapabilities()['synchronous_update_validation'] ?? null,
        'worker_capabilities' => ['workflow_tasks', 'query_tasks'],
        'typed_update_response' => $unsupported,
        'accepted_history_event_count' => $unsupportedAcceptedCount,
    ];
    $results['unsupported_validation_capability'] = ($unsupported['body']['reason'] ?? null)
        === 'update_validation_capability_unsupported' && $unsupportedAcceptedCount === 0
        ? pass_result('unsupported_validation_capability', $unsupportedObserved)
        : fail_result(
            'unsupported_validation_capability',
            'The published server silently accepted an update without a validator-capable worker.',
            $unsupportedObserved,
        );

    return $results;
}

function run_focused_probe(): array
{
    bootstrap_application();
    register_probe_worker();

    $suffix = strtolower(bin2hex(random_bytes(4)));
    $workflowId = 'wf-update-probe-'.$suffix;
    $start = start_probe_workflow($workflowId);
    $runId = (string) ($start['run_id'] ?? '');
    open_signal_wait('workflow-updates-worker');

    $startedEvents = history_events($workflowId, $runId);
    $started = event_by_type($startedEvents, HistoryEventType::WorkflowStarted->value);
    $declaredUpdates = $started['payload']['declared_updates'] ?? [];

    $scenarioResults = [];
    $scenarioResults['published_artifact_install_only'] = pass_result(
        'published_artifact_install_only',
        published_artifact_install_observed_outputs(),
    );
    $scenarioResults['declared_update_contract_visibility'] = in_array('approve', is_array($declaredUpdates) ? $declaredUpdates : [], true)
        ? pass_result('declared_update_contract_visibility', [
            'workflow_type' => WORKFLOW_UPDATES_TYPE,
            'declared_updates' => $declaredUpdates,
            'declared_update_contracts' => $started['payload']['declared_update_contracts'] ?? [],
            'start_response' => $start,
            'history_start_event' => $started,
        ])
        : fail_result('declared_update_contract_visibility', 'The published server did not project declared workflow update contracts into history.', [
            'history_start_event' => $started,
        ]);

    try {
        $acceptedResponse = request_json('POST', '/workflows/'.$workflowId.'/update/approve', [
            'input' => [true, 'focused-accepted'],
            'request_id' => 'accepted-'.$suffix,
            'wait_for' => 'accepted',
        ]);
        $acceptedBody = $acceptedResponse['body'];
        $acceptedUpdateId = (string) ($acceptedBody['update_id'] ?? '');
        $runDetailBeforeComplete = request_json('GET', '/workflows/'.$workflowId.'/runs/'.$runId)['body'];
        $acceptedHistory = history_events($workflowId, $runId);
        $acceptedTypes = event_types($acceptedHistory);

        $scenarioResults['accepted_update_control_plane_and_history'] = $acceptedUpdateId !== ''
            && in_array(WORKFLOW_UPDATE_ACCEPTED_EVENT, $acceptedTypes, true)
            ? pass_result('accepted_update_control_plane_and_history', [
                'update_request' => ['name' => 'approve', 'wait_for' => 'accepted'],
                'update_response' => $acceptedBody,
                'update_id' => $acceptedUpdateId,
                'update_status' => $acceptedBody['update_status'] ?? null,
                'history_update_accepted_event' => event_by_type($acceptedHistory, WORKFLOW_UPDATE_ACCEPTED_EVENT),
                'run_detail_update_view' => $runDetailBeforeComplete,
            ])
            : fail_result('accepted_update_control_plane_and_history', 'The published server did not expose an accepted update through control-plane and history.', [
                'update_response' => $acceptedBody,
                'history_event_types' => $acceptedTypes,
            ]);

        $acceptedRow = $acceptedUpdateId !== '' ? update_row($acceptedUpdateId) : null;
        $scenarioResults['running_or_waiting_update_operator_visibility'] = $acceptedRow instanceof WorkflowUpdate
            ? pass_result('running_or_waiting_update_operator_visibility', [
                'update_id' => $acceptedUpdateId,
                'workflow_status' => $runDetailBeforeComplete['status'] ?? null,
                'update_status' => $acceptedRow->status?->value,
                'waiting_or_running_surface' => [
                    'run_detail_status' => $runDetailBeforeComplete['status'] ?? null,
                    'workflow_task_count' => WorkflowTask::query()->where('workflow_run_id', $runId)->count(),
                ],
                'waterline_update_view' => null,
            ])
            : fail_result('running_or_waiting_update_operator_visibility', 'The published server did not persist an accepted update row before worker completion.', [
                'update_id' => $acceptedUpdateId,
            ]);

        $completeResult = complete_update_task('workflow-updates-worker', $acceptedUpdateId, [
            'approved' => true,
            'source' => 'focused-complete',
        ]);
        $completedRow = $acceptedUpdateId !== '' ? update_row($acceptedUpdateId) : null;
        $completedHistory = history_events($workflowId, $runId);

        $scenarioResults['completed_update_result_round_trip'] = $completedRow instanceof WorkflowUpdate
            && $completedRow->status?->value === 'completed'
            ? pass_result('completed_update_result_round_trip', [
                'update_id' => $acceptedUpdateId,
                'request_payload' => [true, 'focused-accepted'],
                'result_payload' => ['approved' => true, 'source' => 'focused-complete'],
                'result_envelope' => [
                    'codec' => 'avro',
                    'blob_present' => is_string($completedRow->result),
                ],
                'history_update_completed_event' => event_by_type($completedHistory, WORKFLOW_UPDATE_COMPLETED_EVENT),
                'cli_update_json' => null,
                'sdk_update_result' => null,
                'worker_complete_response' => $completeResult,
            ])
            : fail_result('completed_update_result_round_trip', 'The published server did not complete an accepted update with a result envelope.', [
                'update_id' => $acceptedUpdateId,
                'worker_complete_response' => $completeResult,
            ]);

        $scenarioResults['payload_envelope_round_trip'] = $completedRow instanceof WorkflowUpdate
            && is_string($completedRow->arguments)
            && is_string($completedRow->result)
            ? pass_result('payload_envelope_round_trip', [
                'codec' => 'avro',
                'request_envelope' => [
                    'codec' => 'avro',
                    'blob_present' => is_string($completedRow->arguments),
                ],
                'history_arguments_envelope' => event_by_type($completedHistory, WORKFLOW_UPDATE_ACCEPTED_EVENT)['payload']['arguments'] ?? null,
                'history_result_envelope' => event_by_type($completedHistory, WORKFLOW_UPDATE_COMPLETED_EVENT)['payload']['result'] ?? null,
                'control_plane_result_envelope' => [
                    'worker_complete_response' => $completeResult,
                ],
                'sdk_decoded_result' => null,
            ])
            : fail_result('payload_envelope_round_trip', 'The published server did not retain workflow update argument and result envelopes.', [
                'update_id' => $acceptedUpdateId,
            ]);
    } catch (Throwable $throwable) {
        foreach ([
            'accepted_update_control_plane_and_history',
            'running_or_waiting_update_operator_visibility',
            'completed_update_result_round_trip',
            'payload_envelope_round_trip',
        ] as $scenarioId) {
            if (! isset($scenarioResults[$scenarioId])) {
                $scenarioResults[$scenarioId] = exception_fail_result(
                    $scenarioId,
                    'The published server workflow update lifecycle cell failed before this observation could be collected.',
                    $throwable,
                    ['workflow_id' => $workflowId, 'run_id' => $runId],
                );
            }
        }
    }

    try {
        $failedResponse = request_json('POST', '/workflows/'.$workflowId.'/update/fail_update', [
            'input' => ['focused failure'],
            'request_id' => 'failed-'.$suffix,
            'wait_for' => 'accepted',
        ]);
        $failedBody = $failedResponse['body'];
        $failedUpdateId = (string) ($failedBody['update_id'] ?? '');
        $failResult = fail_update_task('workflow-updates-worker', $failedUpdateId);
        $failedRow = $failedUpdateId !== '' ? update_row($failedUpdateId) : null;
        $failedHistory = history_events($workflowId, $runId);

        $scenarioResults['failed_update_outcome'] = $failedRow instanceof WorkflowUpdate
            && $failedRow->status?->value === 'failed'
            ? pass_result('failed_update_outcome', [
                'update_id' => $failedUpdateId,
                'failure_type' => 'workflow_update_probe_failure',
                'failure_message' => $failedRow->failure_message,
                'history_update_completed_or_failed_event' => event_by_type($failedHistory, WORKFLOW_UPDATE_COMPLETED_EVENT),
                'control_plane_error_envelope' => $failedBody,
                'operator_failure_view' => [
                    'failure_id' => $failedRow->failure_id,
                    'worker_fail_response' => $failResult,
                ],
            ])
            : fail_result('failed_update_outcome', 'The published server did not persist a worker-failed update outcome.', [
                'update_id' => $failedUpdateId,
                'worker_fail_response' => $failResult,
            ]);
    } catch (Throwable $throwable) {
        $scenarioResults['failed_update_outcome'] = exception_fail_result(
            'failed_update_outcome',
            'The published server failed before the worker-failed update outcome could be observed.',
            $throwable,
            ['workflow_id' => $workflowId, 'run_id' => $runId],
        );
    }

    try {
        $duplicateFirst = request_json('POST', '/workflows/'.$workflowId.'/update/approve', [
            'input' => [true, 'duplicate-first'],
            'request_id' => 'duplicate-'.$suffix,
            'wait_for' => 'accepted',
        ])['body'];
        $duplicateSecond = request_json('POST', '/workflows/'.$workflowId.'/update/approve', [
            'input' => [true, 'duplicate-second'],
            'request_id' => 'duplicate-'.$suffix,
            'wait_for' => 'accepted',
        ], [200, 202, 409])['body'];
        $duplicateUpdateId = (string) ($duplicateFirst['update_id'] ?? '');
        $duplicateHistory = history_events($workflowId, $runId);
        $duplicateHistoryCount = count(array_filter(
            $duplicateHistory,
            static fn (array $event): bool => ($event['event_type'] ?? null) === WORKFLOW_UPDATE_ACCEPTED_EVENT
                && event_request_id($event) === 'duplicate-'.$suffix,
        ));
        $duplicateCleanupResult = $duplicateUpdateId !== ''
            ? complete_update_task('workflow-updates-worker', $duplicateUpdateId, [
                'approved' => true,
                'source' => 'focused-duplicate-cleanup',
            ])
            : null;

        $duplicateObserved = [
            'idempotency_key_or_update_id' => 'duplicate-'.$suffix,
            'first_response' => $duplicateFirst,
            'duplicate_response' => $duplicateSecond,
            'history_event_count' => $duplicateHistoryCount,
            'handler_observation_count' => $duplicateHistoryCount,
            'cleanup_response' => $duplicateCleanupResult,
            'documented_contract' => 'request_id deduplicates accepted update admission for a workflow run',
        ];
        $scenarioResults['duplicate_request_idempotency'] = $duplicateUpdateId !== ''
            && $duplicateHistoryCount === 1
            ? pass_result('duplicate_request_idempotency', $duplicateObserved)
            : fail_result('duplicate_request_idempotency', 'The published server did not keep duplicate request-id update admission idempotent.', $duplicateObserved);
    } catch (Throwable $throwable) {
        $scenarioResults['duplicate_request_idempotency'] = exception_fail_result(
            'duplicate_request_idempotency',
            'The published server failed before duplicate request-id update behavior could be observed.',
            $throwable,
            ['workflow_id' => $workflowId, 'run_id' => $runId],
        );
    }

    try {
        $unknown = request_json('POST', '/workflows/'.$workflowId.'/update/missing_update', [
            'input' => [],
            'request_id' => 'unknown-'.$suffix,
        ], [404, 409, 422]);
        $scenarioResults['unknown_update_refusal'] = in_array($unknown['status_code'], [404, 409, 422], true)
            ? pass_result('unknown_update_refusal', [
                'unknown_update_name' => 'missing_update',
                'error_type' => $unknown['body']['reason'] ?? null,
                'http_status_or_sdk_error' => $unknown['status_code'],
                'history_absence_or_rejection_event' => null,
                'operator_visible_refusal' => $unknown['body'],
            ])
            : fail_result('unknown_update_refusal', 'The published server accepted an undeclared workflow update.', [
                'response' => $unknown,
            ]);
    } catch (Throwable $throwable) {
        $scenarioResults['unknown_update_refusal'] = exception_fail_result(
            'unknown_update_refusal',
            'The published server failed before unknown update refusal could be observed.',
            $throwable,
            ['workflow_id' => $workflowId, 'run_id' => $runId],
        );
    }

    try {
        $invalid = request_json('POST', '/workflows/'.$workflowId.'/update/approve', [
            'input' => ['not-a-bool'],
            'request_id' => 'invalid-'.$suffix,
        ], [404, 409, 422]);
        $scenarioResults['invalid_input_refusal'] = in_array($invalid['status_code'], [409, 422], true)
            ? pass_result('invalid_input_refusal', [
                'invalid_payload' => ['not-a-bool'],
                'error_type' => $invalid['body']['reason'] ?? null,
                'validation_errors' => $invalid['body']['validation_errors'] ?? $invalid['body']['errors'] ?? null,
                'handler_not_invoked' => true,
                'history_absence_or_rejection_event' => null,
                'operator_visible_refusal' => $invalid['body'],
            ])
            : fail_result('invalid_input_refusal', 'The published server accepted invalid workflow update arguments.', [
                'response' => $invalid,
            ]);
    } catch (Throwable $throwable) {
        $scenarioResults['invalid_input_refusal'] = exception_fail_result(
            'invalid_input_refusal',
            'The published server failed before invalid update input refusal could be observed.',
            $throwable,
            ['workflow_id' => $workflowId, 'run_id' => $runId],
        );
    }

    $terminalWorkflowId = 'wf-update-terminal-'.$suffix;
    $terminalRunId = null;
    try {
        $terminalWorkerId = 'workflow-updates-terminal-worker-'.$suffix;
        $terminalQueue = WORKFLOW_UPDATES_QUEUE.'-terminal-'.$suffix;
        register_probe_worker($terminalWorkerId, $terminalQueue);
        $terminalStart = start_probe_workflow($terminalWorkflowId, $terminalQueue);
        $terminalRunId = (string) ($terminalStart['run_id'] ?? '');
        complete_workflow_start_task($terminalWorkerId, $terminalQueue);
        $terminalRun = WorkflowRun::query()->find($terminalRunId);
        $terminal = request_json('POST', '/workflows/'.$terminalWorkflowId.'/update/approve', [
            'input' => [true, 'terminal'],
            'request_id' => 'terminal-'.$suffix,
        ], [404, 409, 422]);
        $scenarioResults['terminal_workflow_update_behavior'] = in_array($terminal['status_code'], [409, 422], true)
            ? pass_result('terminal_workflow_update_behavior', [
                'terminal_workflow_status' => $terminalRun?->status?->value,
                'update_request' => ['name' => 'approve', 'request_id' => 'terminal-'.$suffix],
                'error_type' => $terminal['body']['reason'] ?? null,
                'http_status_or_sdk_error' => $terminal['status_code'],
                'history_absence_or_rejection_event' => null,
                'operator_visible_refusal' => $terminal['body'],
            ])
            : fail_result('terminal_workflow_update_behavior', 'The published server accepted an update for a terminal workflow run.', [
                'response' => $terminal,
                'terminal_status' => $terminalRun?->status?->value,
            ]);
    } catch (Throwable $throwable) {
        $scenarioResults['terminal_workflow_update_behavior'] = exception_fail_result(
            'terminal_workflow_update_behavior',
            'The published server failed before terminal workflow update behavior could be observed.',
            $throwable,
            ['workflow_id' => $terminalWorkflowId, 'run_id' => $terminalRunId],
        );
    }

    $scenarioResults['principal_attribution_with_auth'] = run_principal_attribution_probe($suffix);

    foreach (run_update_validator_probe($suffix) as $scenarioId => $scenarioResult) {
        $scenarioResults[$scenarioId] = $scenarioResult;
    }

    return [
        'schema' => 'durable-workflow.v2.workflow-update-runtime.focused-evidence',
        'generated_at' => now_iso(),
        'runner' => 'published-server-workflow-updates-focused-probe',
        'runner_blocked' => false,
        'source_policy' => [
            'pass_requires_published_artifacts_only' => true,
            'local_product_source_checkouts_used' => false,
            'local_checkout_execution_counts_as_pass' => false,
        ],
        'scenario_results' => $scenarioResults,
        'observed_outputs' => [
            'workflow_id' => $workflowId,
            'run_id' => $runId,
            'terminal_workflow_id' => $terminalWorkflowId,
            'terminal_run_id' => $terminalRunId,
        ],
        'findings' => [],
    ];
}

try {
    write_json_file('workflow-updates-focused-evidence.json', run_focused_probe());
} catch (Throwable $throwable) {
    write_json_file('workflow-updates-focused-evidence.json', focused_probe_failure_evidence($throwable));
}
PHP
fi

should_run_php_package_shard() {
  if [[ "${DW_WORKFLOW_UPDATES_SKIP_PHP_PACKAGE_SHARD:-0}" == "1" || "${DW_WORKFLOW_UPDATES_SKIP_PHP_PACKAGE_SHARD:-}" == "true" ]]; then
    return 1
  fi
  if [[ -n "${DW_WORKFLOW_UPDATES_PHP_EVIDENCE:-}" ]]; then
    return 1
  fi
  if [[ -n "${DW_WORKFLOW_UPDATES_PHP_EVIDENCE_PATH:-}" && -s "${DW_WORKFLOW_UPDATES_PHP_EVIDENCE_PATH:-}" ]]; then
    return 1
  fi
  if [[ -s "$result_dir/sdk-php-workflow-updates-evidence.json" ]]; then
    return 1
  fi
  if [[ "${DW_WORKFLOW_UPDATES_RUN_PHP_PACKAGE_SHARD:-0}" != "1"
    && "${DW_WORKFLOW_UPDATES_RUN_PHP_PACKAGE_SHARD:-}" != "true"
    && ( "$repo_root" != "/app" || -d "$repo_root/.git" ) ]]; then
    return 1
  fi
  if [[ ! -f "$repo_root/artisan" || ! -f "$repo_root/vendor/autoload.php" ]]; then
    return 1
  fi

  command -v node >/dev/null 2>&1
}

is_exact_package_version() {
  [[ "$1" =~ ^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-((0|[1-9][0-9]*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*)(\.(0|[1-9][0-9]*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*))*))?$ ]]
}

is_exact_python_package_version() {
  is_exact_package_version "$1" \
    || [[ "$1" =~ ^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(a|b|rc)(0|[1-9][0-9]*)$ ]]
}

choose_tcp_port() {
  node -e 'const net=require("node:net");const server=net.createServer();server.listen(0,"127.0.0.1",()=>{console.log(server.address().port);server.close();});'
}

wait_for_http() {
  local url="$1"
  local diagnostics_prefix="${2:-}"
  local attempt
  local body_file
  local body_tmp
  local curl_error_file
  local curl_status
  local http_status
  local readiness_file

  for attempt in $(seq 1 80); do
    if [[ -z "$diagnostics_prefix" ]]; then
      if curl -fsS --max-time 2 "$url" >/dev/null 2>&1; then
        return 0
      fi
      sleep 0.25
      continue
    fi

    body_file="${diagnostics_prefix}-http-response-body.log"
    body_tmp="${body_file}.tmp"
    curl_error_file="${diagnostics_prefix}-curl-error.log"
    readiness_file="${diagnostics_prefix}-readiness.log"
    curl_status=0
    http_status="$(
      curl --silent --show-error --max-time 2 \
        --output "$body_tmp" \
        --write-out '%{http_code}' \
        "$url" 2>"$curl_error_file"
    )" || curl_status=$?
    if [[ -f "$body_tmp" ]]; then
      head -c 32768 "$body_tmp" > "$body_file"
      rm -f "$body_tmp"
    else
      : > "$body_file"
    fi
    {
      printf 'url=%s\n' "$url"
      printf 'attempt=%s\n' "$attempt"
      printf 'curl_exit=%s\n' "$curl_status"
      printf 'http_status=%s\n' "${http_status:-000}"
    } > "$readiness_file"

    if [[ "$curl_status" -eq 0 && "$http_status" =~ ^2[0-9][0-9]$ ]]; then
      return 0
    fi
    sleep 0.25
  done

  return 1
}

capture_php_server_startup_diagnostics() {
  local compose_project="${1:?compose project required}"
  local compose_server_port="${2:?compose server port required}"
  local auth_token="${3:?auth token required}"
  local server_container_id

  {
    SERVER_PORT="$compose_server_port" \
      DW_SERVER_IMAGE="${DW_SERVER_IMAGE}" \
      DW_SERVER_TAG="${DW_SERVER_VERSION}" \
      DW_AUTH_TOKEN="$auth_token" \
      docker compose -p "$compose_project" -f "$repo_root/docker-compose.published.yml" ps --all 2>&1 || true
  } | head -c 32768 > "$result_dir/sdk-php-compose-ps.log"

  server_container_id="$(
    {
      SERVER_PORT="$compose_server_port" \
        DW_SERVER_IMAGE="${DW_SERVER_IMAGE}" \
        DW_SERVER_TAG="${DW_SERVER_VERSION}" \
        DW_AUTH_TOKEN="$auth_token" \
        docker compose -p "$compose_project" -f "$repo_root/docker-compose.published.yml" ps -q server 2>/dev/null || true
    } | head -n 1
  )"
  if [[ -n "$server_container_id" ]]; then
    {
      docker inspect --format '{{json .State}}' "$server_container_id" 2>&1 || true
    } | head -c 32768 > "$result_dir/sdk-php-server-health.json"
  else
    printf '%s\n' '{"status":"container_not_created"}' > "$result_dir/sdk-php-server-health.json"
  fi

  {
    SERVER_PORT="$compose_server_port" \
      DW_SERVER_IMAGE="${DW_SERVER_IMAGE}" \
      DW_SERVER_TAG="${DW_SERVER_VERSION}" \
      DW_AUTH_TOKEN="$auth_token" \
      docker compose -p "$compose_project" -f "$repo_root/docker-compose.published.yml" \
        logs --no-color --tail 200 server 2>&1 || true
  } | tail -c 65536 > "$result_dir/sdk-php-server-container.log"
}

write_php_package_shard_status() {
  PHP_PACKAGE_SHARD_STATUS="${1:?status required}" \
  PHP_PACKAGE_SHARD_SUMMARY="${2:?summary required}" \
  PHP_PACKAGE_SHARD_STEP="${3:?step required}" \
  PHP_PACKAGE_SHARD_RUNNER_BLOCKED="${4:-false}" \
  RESULT_DIR="$result_dir" \
  DW_SERVER_IMAGE="${DW_SERVER_IMAGE:-}" \
  DW_SERVER_VERSION="${DW_SERVER_VERSION:-}" \
  DW_CLI_VERSION="${DW_CLI_VERSION:-}" \
  DW_PYTHON_SDK_VERSION="${DW_PYTHON_SDK_VERSION:-}" \
  DW_PHP_SDK_VERSION="${DW_PHP_SDK_VERSION:-}" \
  DW_WORKFLOW_PHP_VERSION="${DW_WORKFLOW_PHP_VERSION:-${DW_WORKFLOW_VERSION:-}}" \
  DW_WATERLINE_VERSION="${DW_WATERLINE_VERSION:-}" \
  node <<'NODE'
const fs = require('node:fs');
const path = require('node:path');

const resultDir = process.env.RESULT_DIR;
const sdkVersion = (process.env.DW_PHP_SDK_VERSION || '').trim() || 'unresolved';
const workflowVersion = (process.env.DW_WORKFLOW_PHP_VERSION || '').trim() || 'unresolved';
const serverImage = (process.env.DW_SERVER_IMAGE || '').trim();
const serverVersion = (process.env.DW_SERVER_VERSION || '').trim() || (serverImage.match(/:([^/:]+)$/)?.[1] ?? 'unresolved');
const cliVersion = (process.env.DW_CLI_VERSION || '').trim() || 'unresolved';
const pythonVersion = (process.env.DW_PYTHON_SDK_VERSION || '').trim() || 'unresolved';
const waterlineVersion = (process.env.DW_WATERLINE_VERSION || '').trim() || 'unresolved';
const artifactVersions = {
  server: serverVersion,
  cli: cliVersion,
  'sdk-php': sdkVersion,
  'sdk-python': pythonVersion,
  workflow: workflowVersion,
  'workflow-php': workflowVersion,
  waterline: waterlineVersion,
};
const artifactSources = {
  server: serverImage || `docker://durableworkflow/server:${serverVersion}`,
  cli: `https://github.com/durable-workflow/cli/releases/download/${cliVersion}/install.sh`,
  'sdk-php': `packagist://durable-workflow/sdk@${sdkVersion}`,
  'sdk-python': `pypi://durable-workflow==${pythonVersion}`,
  workflow: `packagist://durable-workflow/workflow@${workflowVersion}`,
  'workflow-php': `packagist://durable-workflow/workflow@${workflowVersion}`,
  waterline: `packagist://durable-workflow/waterline@${waterlineVersion}`,
};
const runnerBlocked = ['1', 'true', 'yes'].includes((process.env.PHP_PACKAGE_SHARD_RUNNER_BLOCKED || '').toLowerCase());
const status = process.env.PHP_PACKAGE_SHARD_STATUS || (runnerBlocked ? 'runner_blocked' : 'fail');
const summary = process.env.PHP_PACKAGE_SHARD_SUMMARY || 'The PHP SDK update shard did not complete.';
const step = process.env.PHP_PACKAGE_SHARD_STEP || 'sdk_php_shard';
const startupDiagnosticArtifacts = [
  'sdk-php-compose-ps.log',
  'sdk-php-server-health.json',
  'sdk-php-server-container.log',
  'sdk-php-server-readiness.log',
  'sdk-php-server-http-response-body.log',
  'sdk-php-server-curl-error.log',
].filter((name) => fs.existsSync(path.join(resultDir, name)));
const generatedAt = new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
const finding = {
  finding_id: `workflow-updates-php-client-worker-update-surface-${runnerBlocked ? 'runner-blocked' : 'product-gap'}`,
  finding_type: runnerBlocked ? 'conformance_runner_blocked' : 'product_behavior_failure',
  classification: runnerBlocked ? 'runner-blocked' : 'product-gap',
  scenario_id: 'php_client_worker_update_surface',
  owning_surface: runnerBlocked ? 'conformance_harness' : 'sdk-php',
  summary,
  next_acceptance_criterion: 'Install the exact Packagist durable-workflow/sdk artifact and rerun its real PHP client and worker update cell against the exact server image.',
  diagnostic: {step, startup_diagnostic_artifacts: startupDiagnosticArtifacts},
};
const scenario = {
  scenario_id: 'php_client_worker_update_surface',
  status,
  classification: finding.classification,
  published_artifact_cell_executed: false,
  local_product_source_checkouts_used: false,
  observed_outputs: {
    sdk_php_artifact_version: sdkVersion,
    sdk_php_artifact_source: artifactSources['sdk-php'],
    composer_package: 'durable-workflow/sdk',
    package_install_step: step,
    startup_diagnostic_artifacts: startupDiagnosticArtifacts,
    php_worker_update_handler: {},
    php_client_update_request: {},
    covered_cells: [],
    unsupported_cells: [],
    typed_errors: [{cell: 'php_client_worker_update_surface', reason: step, message: summary}],
    published_artifact_cell_executed: false,
    local_product_source_checkouts_used: false,
    artifact_versions: artifactVersions,
    published_artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
  },
  linked_findings: [finding],
};
const payload = {
  schema: 'durable-workflow.v2.workflow-updates.php-package-sidecar',
  generated_at: generatedAt,
  runner: 'published-packagist-sdk-php-workflow-updates-shard',
  runner_blocked: runnerBlocked,
  source_policy: {
    pass_requires_published_artifacts_only: true,
    local_product_source_checkouts_used: false,
    local_checkout_execution_counts_as_pass: false,
    artifact_sources: artifactSources,
  },
  artifact_versions: artifactVersions,
  published_artifact_versions: artifactVersions,
  artifact_sources: artifactSources,
  scenario_results: {php_client_worker_update_surface: scenario},
  findings: [finding],
};
fs.writeFileSync(path.join(resultDir, 'sdk-php-workflow-updates-evidence.json'), `${JSON.stringify(payload, null, 2)}\n`);
NODE
}

materialize_php_package_shard_report() {
  PHP_PACKAGE_REPORT_PATH="${1:?report path required}" \
  PHP_PACKAGE_SIDECAR_PATH="${2:?sidecar path required}" \
  RESULT_DIR="$result_dir" \
  DW_SERVER_IMAGE="${DW_SERVER_IMAGE:-}" \
  DW_SERVER_VERSION="${DW_SERVER_VERSION:-}" \
  DW_CLI_VERSION="${DW_CLI_VERSION:-}" \
  DW_PYTHON_SDK_VERSION="${DW_PYTHON_SDK_VERSION:-}" \
  DW_PHP_SDK_VERSION="${DW_PHP_SDK_VERSION:-}" \
  DW_WORKFLOW_PHP_VERSION="${DW_WORKFLOW_PHP_VERSION:-${DW_WORKFLOW_VERSION:-}}" \
  DW_WATERLINE_VERSION="${DW_WATERLINE_VERSION:-}" \
  node <<'NODE'
const fs = require('node:fs');
const path = require('node:path');

const report = JSON.parse(fs.readFileSync(process.env.PHP_PACKAGE_REPORT_PATH, 'utf8'));
const sourceSidecar = JSON.parse(fs.readFileSync(process.env.PHP_PACKAGE_SIDECAR_PATH, 'utf8'));
const observed = sourceSidecar?.scenario_results?.php_sdk_lifecycle_surface?.observed_outputs ?? {};
const sdkVersion = (process.env.DW_PHP_SDK_VERSION || '').trim() || report?.artifact_versions?.['sdk-php'] || 'unresolved';
const workflowVersion = (process.env.DW_WORKFLOW_PHP_VERSION || '').trim() || 'unresolved';
const serverImage = (process.env.DW_SERVER_IMAGE || '').trim();
const serverVersion = (process.env.DW_SERVER_VERSION || '').trim() || report?.artifact_versions?.server || 'unresolved';
const cliVersion = (process.env.DW_CLI_VERSION || '').trim() || 'unresolved';
const pythonVersion = (process.env.DW_PYTHON_SDK_VERSION || '').trim() || 'unresolved';
const waterlineVersion = (process.env.DW_WATERLINE_VERSION || '').trim() || 'unresolved';
const artifactVersions = {
  server: serverVersion,
  cli: cliVersion,
  'sdk-php': sdkVersion,
  'sdk-python': pythonVersion,
  workflow: workflowVersion,
  'workflow-php': workflowVersion,
  waterline: waterlineVersion,
};
const artifactSources = {
  server: serverImage || `docker://durableworkflow/server:${serverVersion}`,
  cli: `https://github.com/durable-workflow/cli/releases/download/${cliVersion}/install.sh`,
  'sdk-php': `packagist://durable-workflow/sdk@${sdkVersion}`,
  'sdk-python': `pypi://durable-workflow==${pythonVersion}`,
  workflow: `packagist://durable-workflow/workflow@${workflowVersion}`,
  'workflow-php': `packagist://durable-workflow/workflow@${workflowVersion}`,
  waterline: `packagist://durable-workflow/waterline@${waterlineVersion}`,
};
const updatePassed = observed?.scenario_assertions?.update === true || report?.assertions?.update === true;
const runnerBlocked = report.runner_blocked === true;
const status = updatePassed ? 'pass' : (runnerBlocked ? 'runner_blocked' : 'fail');
const probeFindings = Array.isArray(report.findings) ? report.findings : [];
const linkedFindings = status === 'pass' ? [] : probeFindings.map((finding, index) => ({
  ...finding,
  finding_id: finding.finding_id || `workflow-updates-sdk-php-${index + 1}`,
  scenario_id: 'php_client_worker_update_surface',
}));
const generatedAt = new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
const scenario = {
  scenario_id: 'php_client_worker_update_surface',
  status,
  classification: status === 'pass' ? 'product-evidence' : (runnerBlocked ? 'runner-blocked' : 'product-gap'),
  published_artifact_cell_executed: observed.published_artifact_cell_executed === true,
  local_product_source_checkouts_used: false,
  observed_outputs: {
    sdk_php_artifact_version: sdkVersion,
    sdk_php_artifact_source: artifactSources['sdk-php'],
    composer_package: 'durable-workflow/sdk',
    composer_constraint: `durable-workflow/sdk:${sdkVersion}`,
    package_artifact_source: artifactSources['sdk-php'],
    install_provenance: observed.install_provenance ?? report.package_provenance ?? {},
    apache_avro_provenance: observed.apache_avro_provenance ?? report.apache_avro_provenance ?? {},
    php_worker_update_handler: {
      callback_count: observed?.callback_counts?.update ?? 0,
      worker_processes: observed.worker_processes ?? [],
    },
    php_client_update_request: {
      assertion_passed: updatePassed,
      client_processes: observed.client_processes ?? [],
    },
    covered_cells: updatePassed ? ['php_client_worker_update_surface'] : [],
    unsupported_cells: [],
    typed_errors: observed.typed_errors ?? [],
    artifact_versions: artifactVersions,
    published_artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
    source_policy: {
      pass_requires_published_artifacts_only: true,
      local_product_source_checkouts_used: false,
      local_checkout_execution_counts_as_pass: false,
    },
    published_artifact_cell_executed: observed.published_artifact_cell_executed === true,
    local_product_source_checkouts_used: false,
  },
  linked_findings: linkedFindings,
};
const payload = {
  schema: 'durable-workflow.v2.workflow-updates.php-package-sidecar',
  generated_at: generatedAt,
  runner: 'published-packagist-sdk-php-workflow-updates-shard',
  runner_blocked: runnerBlocked,
  source_policy: scenario.observed_outputs.source_policy,
  artifact_versions: artifactVersions,
  published_artifact_versions: artifactVersions,
  artifact_sources: artifactSources,
  package_report: {
    schema: report.schema || null,
    outcome: report.outcome || null,
    process_boundary: report.process_boundary || null,
  },
  scenario_results: {php_client_worker_update_surface: scenario},
  findings: linkedFindings,
};
fs.writeFileSync(path.join(process.env.RESULT_DIR, 'sdk-php-workflow-updates-evidence.json'), `${JSON.stringify(payload, null, 2)}\n`);
NODE
}

run_php_package_shard() {
  local sdk_php_version="${DW_PHP_SDK_VERSION:-}"
  local server_bind_host="${DW_WORKFLOW_UPDATES_PHP_SERVER_BIND_HOST:-0.0.0.0}"
  local server_connect_host="${DW_WORKFLOW_UPDATES_PHP_SERVER_CONNECT_HOST:-127.0.0.1}"
  local server_port="${DW_WORKFLOW_UPDATES_PHP_SERVER_PORT:-}"
  local compose_server_port
  local server_url
  local probe_dir="$result_dir/sdk-php-workflow-updates-probe"
  local compose_suffix
  compose_suffix="$(printf '%s' "$(basename "$result_dir")" | tr -c 'a-zA-Z0-9_-' '-')"
  local compose_project="dw-updates-sdk-php-${compose_suffix}"
  local auth_token="workflow-updates-sdk-php-token"

  if [[ -z "$sdk_php_version" ]] || ! is_exact_package_version "$sdk_php_version"; then
    write_php_package_shard_status not_covered "DW_PHP_SDK_VERSION must be an exact durable-workflow/sdk version before the PHP SDK update shard can install from Packagist." version_resolution false
    return 0
  fi
  for command in docker curl node; do
    if ! command -v "$command" >/dev/null 2>&1; then
      write_php_package_shard_status runner_blocked "$command is required to run the exact Packagist PHP SDK update shard." "${command}_unavailable" true
      return 0
    fi
  done
  if [[ -z "${DW_SERVER_VERSION:-}" || -z "${DW_SERVER_IMAGE:-}" ]]; then
    write_php_package_shard_status runner_blocked "DW_SERVER_VERSION and DW_SERVER_IMAGE must identify the exact published server under test." server_pin_unavailable true
    return 0
  fi

  if [[ -z "$server_port" ]]; then
    server_port="$(choose_tcp_port)"
  fi
  compose_server_port="$server_port"
  if [[ -n "$server_bind_host" ]]; then
    compose_server_port="${server_bind_host}:${server_port}"
  fi
  server_url="http://${server_connect_host}:${server_port}"
  cleanup_compose_projects+=("$compose_project")
  if ! SERVER_PORT="$compose_server_port" DW_SERVER_IMAGE="${DW_SERVER_IMAGE}" DW_SERVER_TAG="${DW_SERVER_VERSION}" DW_AUTH_TOKEN="$auth_token" \
    docker compose -p "$compose_project" -f "$repo_root/docker-compose.published.yml" up -d mysql redis \
      > "$result_dir/sdk-php-compose-dependencies.log" 2>&1; then
    capture_php_server_startup_diagnostics "$compose_project" "$compose_server_port" "$auth_token"
    write_php_package_shard_status runner_blocked "The published server dependencies could not start for the PHP SDK update shard." server_dependencies true
    return 0
  fi
  if ! SERVER_PORT="$compose_server_port" DW_SERVER_IMAGE="${DW_SERVER_IMAGE}" DW_SERVER_TAG="${DW_SERVER_VERSION}" DW_AUTH_TOKEN="$auth_token" \
    docker compose -p "$compose_project" -f "$repo_root/docker-compose.published.yml" run --rm bootstrap \
      > "$result_dir/sdk-php-server-bootstrap.log" 2>&1; then
    capture_php_server_startup_diagnostics "$compose_project" "$compose_server_port" "$auth_token"
    write_php_package_shard_status fail "The exact public server image could not bootstrap for the PHP SDK update shard." server_bootstrap false
    return 0
  fi
  if ! SERVER_PORT="$compose_server_port" DW_SERVER_IMAGE="${DW_SERVER_IMAGE}" DW_SERVER_TAG="${DW_SERVER_VERSION}" DW_AUTH_TOKEN="$auth_token" \
    docker compose -p "$compose_project" -f "$repo_root/docker-compose.published.yml" up -d --no-deps server scheduler \
      > "$result_dir/sdk-php-server.log" 2>&1; then
    capture_php_server_startup_diagnostics "$compose_project" "$compose_server_port" "$auth_token"
    write_php_package_shard_status runner_blocked "The exact public server image could not start for the PHP SDK update shard." server_start true
    return 0
  fi

  if ! wait_for_http "$server_url/api/health" "$result_dir/sdk-php-server"; then
    capture_php_server_startup_diagnostics "$compose_project" "$compose_server_port" "$auth_token"
    write_php_package_shard_status runner_blocked "The exact server HTTP surface did not become ready for the PHP SDK update shard." server_http_unavailable true
    return 0
  fi

  mkdir -p "$probe_dir"
  docker run --rm --network host \
    -e DW_PHP_SDK_VERSION="$sdk_php_version" \
    -e DW_SERVER_VERSION="${DW_SERVER_VERSION}" \
    -e DW_SERVER_IMAGE="${DW_SERVER_IMAGE}" \
    -e DW_PHP_SDK_CONFORMANCE_SERVER_URL="$server_url" \
    -e DW_PHP_SDK_CONFORMANCE_NAMESPACE=default \
    -e DW_PHP_SDK_CONFORMANCE_TOKEN="$auth_token" \
    -v "$probe_dir:/result" \
    "${DW_SERVER_IMAGE}" scripts/conformance/php-sdk-published-artifacts.sh --result-dir /result \
    > "$result_dir/sdk-php-workflow-updates-conformance.log" 2>&1

  if [[ ! -s "$probe_dir/php-sdk-conformance-result.json" || ! -s "$probe_dir/php-sdk-lifecycle-evidence.json" ]]; then
    write_php_package_shard_status fail "The PHP SDK process-boundary runner did not emit complete update evidence." sdk_php_probe false
    return 0
  fi
  materialize_php_package_shard_report \
    "$probe_dir/php-sdk-conformance-result.json" \
    "$probe_dir/php-sdk-lifecycle-evidence.json"
}

if ! command -v node >/dev/null 2>&1; then
  printf '%s\n' 'required command not found: node' >&2
  exit 1
fi

if should_run_php_package_shard; then
  run_php_package_shard
fi

should_run_python_sdk_shard() {
  if [[ "${DW_WORKFLOW_UPDATES_SKIP_PYTHON_SDK_SHARD:-0}" == "1" || "${DW_WORKFLOW_UPDATES_SKIP_PYTHON_SDK_SHARD:-}" == "true" ]]; then
    return 1
  fi
  if [[ -n "${DW_WORKFLOW_UPDATES_PYTHON_EVIDENCE:-}" ]]; then
    return 1
  fi
  if [[ -n "${DW_WORKFLOW_UPDATES_PYTHON_EVIDENCE_PATH:-}" && -s "${DW_WORKFLOW_UPDATES_PYTHON_EVIDENCE_PATH:-}" ]]; then
    return 1
  fi
  if [[ -s "$result_dir/python-sdk-workflow-updates-evidence.json" ]]; then
    return 1
  fi
  if [[ "$repo_root" != "/app" || -d "$repo_root/.git" ]]; then
    return 1
  fi
  if [[ ! -f "$repo_root/artisan" || ! -f "$repo_root/vendor/autoload.php" ]]; then
    return 1
  fi

  return 0
}

select_python_bin() {
  if [[ -n "${DW_WORKFLOW_UPDATES_PYTHON_BIN:-}" ]]; then
    if [[ -x "${DW_WORKFLOW_UPDATES_PYTHON_BIN:-}" ]]; then
      printf '%s\n' "${DW_WORKFLOW_UPDATES_PYTHON_BIN:-}"
      return 0
    fi
    if command -v "${DW_WORKFLOW_UPDATES_PYTHON_BIN:-}" >/dev/null 2>&1; then
      command -v "${DW_WORKFLOW_UPDATES_PYTHON_BIN:-}"
      return 0
    fi

    return 1
  fi

  if command -v python3 >/dev/null 2>&1; then
    command -v python3
    return 0
  fi
  if command -v python >/dev/null 2>&1; then
    command -v python
    return 0
  fi

  return 1
}

write_python_sdk_shard_status() {
  PYTHON_SDK_SHARD_STATUS="${1:?status required}" \
  PYTHON_SDK_SHARD_SUMMARY="${2:?summary required}" \
  PYTHON_SDK_SHARD_STEP="${3:?step required}" \
  PYTHON_SDK_SHARD_RUNNER_BLOCKED="${4:-false}" \
  RESULT_DIR="$result_dir" \
  DW_SERVER_IMAGE="${DW_SERVER_IMAGE:-}" \
  DW_SERVER_VERSION="${DW_SERVER_VERSION:-}" \
  DW_CLI_VERSION="${DW_CLI_VERSION:-}" \
  DW_PYTHON_SDK_VERSION="${DW_PYTHON_SDK_VERSION:-}" \
  DW_PHP_SDK_VERSION="${DW_PHP_SDK_VERSION:-}" \
  DW_WORKFLOW_PHP_VERSION="${DW_WORKFLOW_PHP_VERSION:-${DW_WORKFLOW_VERSION:-}}" \
  DW_WORKFLOW_VERSION="${DW_WORKFLOW_VERSION:-}" \
  DW_WATERLINE_VERSION="${DW_WATERLINE_VERSION:-}" \
  node <<'NODE'
const fs = require('node:fs');
const path = require('node:path');

const resultDir = process.env.RESULT_DIR;
const workflowVersion = (process.env.DW_WORKFLOW_PHP_VERSION || '').trim()
  || (process.env.DW_WORKFLOW_VERSION || '').trim()
  || 'unresolved';
const serverImage = (process.env.DW_SERVER_IMAGE || '').trim();
const serverVersion = (process.env.DW_SERVER_VERSION || '').trim() || (serverImage.match(/:([^/:]+)$/)?.[1] ?? 'unresolved');
const cliVersion = (process.env.DW_CLI_VERSION || '').trim() || 'unresolved';
const pythonVersion = (process.env.DW_PYTHON_SDK_VERSION || '').trim() || 'unresolved';
const phpSdkVersion = (process.env.DW_PHP_SDK_VERSION || '').trim() || 'unresolved';
const waterlineVersion = (process.env.DW_WATERLINE_VERSION || '').trim() || 'unresolved';
const artifactVersions = {
  server: serverVersion,
  cli: cliVersion,
  'sdk-php': phpSdkVersion,
  'sdk-python': pythonVersion,
  workflow: workflowVersion,
  'workflow-php': workflowVersion,
  waterline: waterlineVersion,
};
const artifactSources = {
  server: serverImage || `docker://durableworkflow/server:${serverVersion}`,
  cli: `https://github.com/durable-workflow/cli/releases/download/${cliVersion}/install.sh`,
  'sdk-php': `packagist://durable-workflow/sdk@${phpSdkVersion}`,
  'sdk-python': `pypi://durable-workflow==${pythonVersion}`,
  workflow: `packagist://durable-workflow/workflow@${workflowVersion}`,
  'workflow-php': `packagist://durable-workflow/workflow@${workflowVersion}`,
  waterline: `packagist://durable-workflow/waterline@${waterlineVersion}`,
};
const runnerBlocked = ['1', 'true', 'yes'].includes((process.env.PYTHON_SDK_SHARD_RUNNER_BLOCKED || '').toLowerCase());
const status = process.env.PYTHON_SDK_SHARD_STATUS || (runnerBlocked ? 'runner_blocked' : 'fail');
const classification = runnerBlocked ? 'runner-blocked' : (['not_covered', 'unsupported'].includes(status) ? 'coverage-gap' : 'product-gap');
const summary = process.env.PYTHON_SDK_SHARD_SUMMARY || 'The Python SDK workflow update shard did not complete.';
const step = process.env.PYTHON_SDK_SHARD_STEP || 'python_sdk_shard';
const generatedAt = new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
const finding = {
  finding_id: `workflow-updates-python-client-worker-update-surface-${runnerBlocked ? 'runner-blocked' : (classification === 'coverage-gap' ? 'coverage-gap' : 'product-gap')}`,
  finding_type: runnerBlocked ? 'conformance_runner_blocked' : (classification === 'coverage-gap' ? 'conformance_runner_coverage_gap' : 'product_behavior_failure'),
  classification,
  scenario_id: 'python_client_worker_update_surface',
  owning_surface: runnerBlocked ? 'conformance_harness' : 'sdk-python',
  summary,
  next_acceptance_criterion: 'Install the pinned PyPI durable-workflow artifact and run its workflow update client/worker conformance command from the installed package.',
  diagnostic: { step },
};
const payload = {
  schema: 'durable-workflow.v2.workflow-updates.python-sdk-sidecar',
  generated_at: generatedAt,
  runner: 'published-pypi-python-sdk-workflow-updates-shard',
  runner_blocked: runnerBlocked,
  source_policy: {
    pass_requires_published_artifacts_only: true,
    local_product_source_checkouts_used: false,
    local_checkout_execution_counts_as_pass: false,
    artifact_sources: artifactSources,
  },
  artifact_versions: artifactVersions,
  published_artifact_versions: artifactVersions,
  artifact_sources: artifactSources,
  scenario_results: {
    python_client_worker_update_surface: {
      scenario_id: 'python_client_worker_update_surface',
      status,
      classification,
      published_artifact_cell_executed: false,
      local_product_source_checkouts_used: false,
      observed_outputs: {
        sdk_python_artifact_version: pythonVersion,
        sdk_python_artifact_source: artifactSources['sdk-python'],
        artifact_source: artifactSources['sdk-python'],
        pypi_package: 'durable-workflow',
        package_install_step: step,
        python_worker_update_handler: {},
        python_client_update_request: {},
        covered_cells: [],
        unsupported_cells: [],
        typed_errors: [{
          cell: 'python_client_worker_update_surface',
          reason: step,
          message: summary,
        }],
        published_artifact_cell_executed: false,
        local_product_source_checkouts_used: false,
        artifact_versions: artifactVersions,
        published_artifact_versions: artifactVersions,
        artifact_sources: artifactSources,
        source_policy: {
          pass_requires_published_artifacts_only: true,
          local_product_source_checkouts_used: false,
          local_checkout_execution_counts_as_pass: false,
        },
      },
      linked_findings: [finding],
    },
  },
  findings: [finding],
};

fs.writeFileSync(path.join(resultDir, 'python-sdk-workflow-updates-evidence.json'), `${JSON.stringify(payload, null, 2)}\n`);
NODE
}

materialize_python_sdk_shard_report() {
  PYTHON_SDK_REPORT_PATH="${1:?report path required}" \
  RESULT_DIR="$result_dir" \
  DW_SERVER_IMAGE="${DW_SERVER_IMAGE:-}" \
  DW_SERVER_VERSION="${DW_SERVER_VERSION:-}" \
  DW_CLI_VERSION="${DW_CLI_VERSION:-}" \
  DW_PYTHON_SDK_VERSION="${DW_PYTHON_SDK_VERSION:-}" \
  DW_PHP_SDK_VERSION="${DW_PHP_SDK_VERSION:-}" \
  DW_WORKFLOW_PHP_VERSION="${DW_WORKFLOW_PHP_VERSION:-${DW_WORKFLOW_VERSION:-}}" \
  DW_WORKFLOW_VERSION="${DW_WORKFLOW_VERSION:-}" \
  DW_WATERLINE_VERSION="${DW_WATERLINE_VERSION:-}" \
  node <<'NODE'
const fs = require('node:fs');
const path = require('node:path');

const resultDir = process.env.RESULT_DIR;
const report = JSON.parse(fs.readFileSync(process.env.PYTHON_SDK_REPORT_PATH, 'utf8'));
const workflowVersion = (process.env.DW_WORKFLOW_PHP_VERSION || '').trim()
  || (process.env.DW_WORKFLOW_VERSION || '').trim()
  || report?.artifact_versions?.['workflow-php']
  || 'unresolved';
const serverImage = (process.env.DW_SERVER_IMAGE || '').trim();
const serverVersion = (process.env.DW_SERVER_VERSION || '').trim() || (serverImage.match(/:([^/:]+)$/)?.[1] ?? report?.artifact_versions?.server ?? 'unresolved');
const cliVersion = (process.env.DW_CLI_VERSION || '').trim() || report?.artifact_versions?.cli || 'unresolved';
const pythonVersion = (process.env.DW_PYTHON_SDK_VERSION || '').trim() || report?.artifact_versions?.['sdk-python'] || report?.artifact_versions?.python || 'unresolved';
const phpSdkVersion = (process.env.DW_PHP_SDK_VERSION || '').trim() || report?.artifact_versions?.['sdk-php'] || 'unresolved';
const waterlineVersion = (process.env.DW_WATERLINE_VERSION || '').trim() || report?.artifact_versions?.waterline || 'unresolved';
const artifactVersions = {
  server: serverVersion,
  cli: cliVersion,
  'sdk-php': phpSdkVersion,
  'sdk-python': pythonVersion,
  workflow: workflowVersion,
  'workflow-php': workflowVersion,
  waterline: waterlineVersion,
};
const artifactSources = {
  server: serverImage || `docker://durableworkflow/server:${serverVersion}`,
  cli: `https://github.com/durable-workflow/cli/releases/download/${cliVersion}/install.sh`,
  'sdk-php': `packagist://durable-workflow/sdk@${phpSdkVersion}`,
  'sdk-python': `pypi://durable-workflow==${pythonVersion}`,
  workflow: `packagist://durable-workflow/workflow@${workflowVersion}`,
  'workflow-php': `packagist://durable-workflow/workflow@${workflowVersion}`,
  waterline: `packagist://durable-workflow/waterline@${waterlineVersion}`,
};

function scenarioRows(value) {
  if (Array.isArray(value?.scenario_results)) {
    return value.scenario_results;
  }
  if (value?.scenario_results && typeof value.scenario_results === 'object') {
    return Object.values(value.scenario_results);
  }

  return [];
}

function packageFindingToPublicFinding(finding, index) {
  if (!finding || typeof finding !== 'object') {
    return null;
  }

  return {
    finding_id: `workflow-updates-python-client-worker-update-surface-${index + 1}`,
    finding_type: 'product_behavior_failure',
    classification: 'product-gap',
    scenario_id: 'python_client_worker_update_surface',
    owning_surface: 'sdk-python',
    summary: finding.message || finding.summary || 'The published Python SDK workflow update shard reported a product failure.',
    next_acceptance_criterion: 'Make the published Python SDK client/worker update shard satisfy the workflow update conformance cells.',
    evidence: finding.evidence || finding,
  };
}

const packageRow = scenarioRows(report).find((row) => row?.scenario_id === 'python_client_worker_update_surface') ?? {
  scenario_id: 'python_client_worker_update_surface',
  status: 'fail',
  observed_outputs: {},
  linked_findings: [],
};
const status = typeof packageRow.status === 'string' ? packageRow.status : 'fail';
const scenarioClassification = status === 'pass'
  ? 'product-evidence'
  : (status === 'runner_blocked' ? 'runner-blocked' : (['not_covered', 'unsupported'].includes(status) ? 'coverage-gap' : 'product-gap'));
const rawFindings = Array.isArray(packageRow.linked_findings) && packageRow.linked_findings.length > 0
  ? packageRow.linked_findings
  : (Array.isArray(report.findings) ? report.findings : []);
const packageFindings = rawFindings.map(packageFindingToPublicFinding).filter(Boolean);
const observedOutputs = packageRow.observed_outputs && typeof packageRow.observed_outputs === 'object'
  ? packageRow.observed_outputs
  : {};
const reportLocalSource = packageRow.local_product_source_checkouts_used === true
  || observedOutputs.local_product_source_checkouts_used === true
  || report?.source_policy?.local_product_source_checkouts_used === true;
const generatedAt = new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
const scenario = {
  scenario_id: 'python_client_worker_update_surface',
  status,
  classification: scenarioClassification,
  published_artifact_cell_executed: !reportLocalSource,
  local_product_source_checkouts_used: reportLocalSource,
  observed_outputs: {
    ...observedOutputs,
    package_report_sdk_python_artifact_version: observedOutputs.sdk_python_artifact_version || null,
    package_report_sdk_python_artifact_source: observedOutputs.sdk_python_artifact_source || observedOutputs.artifact_source || null,
    sdk_python_artifact_version: pythonVersion,
    sdk_python_artifact_source: artifactSources['sdk-python'],
    artifact_source: artifactSources['sdk-python'],
    pypi_package: 'durable-workflow',
    pypi_constraint: `durable-workflow==${pythonVersion}`,
    package_artifact_source: artifactSources['sdk-python'],
    package_report_schema: report.schema || null,
    python_sdk_conformance_command: 'durable-workflow-workflow-updates-conformance',
    artifact_versions: artifactVersions,
    published_artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
    source_policy: {
      pass_requires_published_artifacts_only: true,
      local_product_source_checkouts_used: reportLocalSource,
      local_checkout_execution_counts_as_pass: false,
    },
    published_artifact_cell_executed: !reportLocalSource,
    local_product_source_checkouts_used: reportLocalSource,
  },
  linked_findings: status === 'pass' ? [] : packageFindings,
};
const payload = {
  schema: 'durable-workflow.v2.workflow-updates.python-sdk-sidecar',
  generated_at: generatedAt,
  runner: 'published-pypi-python-sdk-workflow-updates-shard',
  runner_blocked: report.runner_blocked === true || report.runnerBlocked === true,
  source_policy: {
    pass_requires_published_artifacts_only: true,
    local_product_source_checkouts_used: reportLocalSource,
    local_checkout_execution_counts_as_pass: false,
    artifact_sources: artifactSources,
  },
  artifact_versions: artifactVersions,
  published_artifact_versions: artifactVersions,
  artifact_sources: artifactSources,
  package_report: {
    schema: report.schema || null,
    outcome: report.outcome || null,
    runner: report.runner || null,
  },
  scenario_results: {
    python_client_worker_update_surface: scenario,
  },
  findings: packageFindings,
};

fs.writeFileSync(path.join(resultDir, 'python-sdk-workflow-updates-evidence.json'), `${JSON.stringify(payload, null, 2)}\n`);
NODE
}

run_python_sdk_shard() {
  local python_version="${DW_PYTHON_SDK_VERSION:-}"
  local python_venv="$result_dir/python-sdk-package-venv"
  local python_report="$result_dir/python-sdk-package-report.json"
  local python_bin
  local venv_python
  local workflow_updates_script

  if [[ -z "$python_version" ]] || ! is_exact_python_package_version "$python_version"; then
    write_python_sdk_shard_status not_covered "DW_PYTHON_SDK_VERSION must be an exact durable-workflow PyPI version before the Python SDK shard can install from PyPI." version_resolution false
    return 0
  fi
  if ! python_bin="$(select_python_bin)"; then
    write_python_sdk_shard_status runner_blocked "python3, python, or DW_WORKFLOW_UPDATES_PYTHON_BIN is required to create the disposable PyPI install environment for the Python SDK update shard." python_unavailable true
    return 0
  fi

  rm -rf "$python_venv"
  if ! "$python_bin" -m venv "$python_venv" > "$result_dir/python-sdk-venv-create.log" 2>&1; then
    write_python_sdk_shard_status runner_blocked "The Python SDK shard could not create a disposable virtual environment; see python-sdk-venv-create.log." python_venv true
    return 0
  fi

  venv_python="$python_venv/bin/python"
  if [[ ! -x "$venv_python" ]]; then
    venv_python="$python_venv/Scripts/python.exe"
  fi
  if [[ ! -x "$venv_python" ]]; then
    write_python_sdk_shard_status runner_blocked "The Python SDK shard virtual environment did not expose a Python executable." python_venv_python true
    return 0
  fi
  if ! "$venv_python" -m pip --version > "$result_dir/python-sdk-pip-version.log" 2>&1; then
    write_python_sdk_shard_status runner_blocked "pip is required inside the disposable Python SDK shard environment; see python-sdk-pip-version.log." pip_unavailable true
    return 0
  fi

  if ! PIP_CONFIG_FILE=/dev/null "$venv_python" -m pip --isolated install \
    --disable-pip-version-check \
    --no-input \
    --index-url https://pypi.org/simple \
    "durable-workflow==${python_version}" \
    > "$result_dir/python-sdk-pip-install.log" 2>&1; then
    write_python_sdk_shard_status fail "pip could not install pinned PyPI package durable-workflow==${python_version}; see python-sdk-pip-install.log." pypi_install false
    return 0
  fi

  if ! PYTHON_EXPECTED_VERSION="$python_version" "$venv_python" <<'PY' > "$result_dir/python-sdk-package-source-policy.log" 2>&1; then
import importlib
import importlib.metadata as metadata
import json
import os
import re
from pathlib import Path
import sys
from urllib.parse import urlparse

expected = os.environ["PYTHON_EXPECTED_VERSION"]


def release_identity(value: str) -> str | None:
    stable = re.fullmatch(r"(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)", value)
    if stable:
        return value
    semver = re.fullmatch(
        r"(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)-(alpha|beta|rc)\.(0|[1-9]\d*)",
        value,
        re.IGNORECASE,
    )
    if semver:
        major, minor, patch, prerelease, ordinal = semver.groups()
        phase = {"alpha": "a", "beta": "b", "rc": "rc"}[prerelease.lower()]
        return f"{major}.{minor}.{patch}{phase}{ordinal}"
    pep440 = re.fullmatch(
        r"(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(a|b|rc)(0|[1-9]\d*)",
        value,
        re.IGNORECASE,
    )
    if not pep440:
        return None
    major, minor, patch, prerelease, ordinal = pep440.groups()
    return f"{major}.{minor}.{patch}{prerelease.lower()}{ordinal}"

def fail(message: str) -> None:
    print(message, file=sys.stderr)
    raise SystemExit(1)

try:
    dist = metadata.distribution("durable-workflow")
except metadata.PackageNotFoundError:
    fail("durable-workflow was not installed by pip.")

version = dist.version
if release_identity(version) != release_identity(expected):
    fail(f"durable-workflow installed version {version!r} did not match {expected!r}.")

package = importlib.import_module("durable_workflow")
package_file = Path(str(getattr(package, "__file__", ""))).resolve()
venv_root = Path(sys.prefix).resolve()
if not package_file.is_file():
    fail("durable_workflow package import did not resolve to a file.")
try:
    package_file.relative_to(venv_root)
except ValueError:
    fail(f"durable_workflow imported outside the disposable virtual environment: {package_file}")

for parent in package_file.parents:
    if (parent / "pyproject.toml").is_file() and (parent / "src" / "durable_workflow").is_dir():
        fail(f"durable_workflow imported from a source checkout: {package_file}")

for candidate in [package_file, *package_file.parents]:
    normalized = str(candidate).lower()
    if "/workspace/repos" in normalized or "local_product_source_checkout" in normalized or "workspace_repo_as_artifact_under_test" in normalized:
        fail(f"durable_workflow resolved from a forbidden local source path: {candidate}")

direct_url = dist.read_text("direct_url.json")
if direct_url:
    try:
        data = json.loads(direct_url)
    except json.JSONDecodeError as exc:
        fail(f"durable-workflow direct_url.json was invalid: {exc}")
    url = str(data.get("url") or "")
    parsed = urlparse(url)
    if parsed.scheme == "file" or url.startswith(("/", "./", "../")):
        fail(f"durable-workflow resolved from a local artifact source: {url}")
    normalized_url = url.lower()
    if "/workspace/repos" in normalized_url or "local_product_source_checkout" in normalized_url or "workspace_repo" in normalized_url:
        fail(f"durable-workflow resolved from a forbidden artifact source: {url}")

print(json.dumps({
    "version": version,
    "package_file": str(package_file),
    "sys_prefix": str(venv_root),
}))
PY
    write_python_sdk_shard_status fail "The Python SDK shard resolved durable-workflow from a non-published artifact source; see python-sdk-package-source-policy.log." package_source_policy false
    return 0
  fi

  local python_runtime_version
  python_runtime_version="$("$venv_python" -c 'import importlib.metadata as metadata; print(metadata.version("durable-workflow"))')"
  python_version="$python_runtime_version"

  workflow_updates_script="$python_venv/bin/durable-workflow-workflow-updates-conformance"
  if [[ ! -x "$workflow_updates_script" ]]; then
    workflow_updates_script="$python_venv/Scripts/durable-workflow-workflow-updates-conformance.exe"
  fi
  if [[ ! -x "$workflow_updates_script" ]]; then
    write_python_sdk_shard_status fail "The PyPI-installed durable-workflow package does not expose durable-workflow-workflow-updates-conformance." python_conformance_command_missing false
    return 0
  fi

  set +e
  PYTHONPATH="" "$workflow_updates_script" \
    --expected-version "$python_version" \
    --output "$python_report" \
    --pretty \
    > "$result_dir/python-sdk-conformance-command.log" 2>&1
  local command_status=$?
  set -e

  if [[ ! -s "$python_report" ]]; then
    write_python_sdk_shard_status fail "The PyPI-installed Python SDK workflow update command did not emit a report; see python-sdk-conformance-command.log." python_sdk_command false
    return 0
  fi

  materialize_python_sdk_shard_report "$python_report"
  if [[ "$command_status" -ne 0 ]]; then
    printf 'Python SDK workflow update shard exited with status %s; imported its emitted report.\n' "$command_status" >> "$result_dir/python-sdk-conformance-command.log"
  fi
}

if should_run_python_sdk_shard; then
  run_python_sdk_shard
fi

should_run_operator_diagnostics_shard() {
  if [[ "${DW_WORKFLOW_UPDATES_SKIP_OPERATOR_DIAGNOSTICS_SHARD:-0}" == "1" || "${DW_WORKFLOW_UPDATES_SKIP_OPERATOR_DIAGNOSTICS_SHARD:-}" == "true" ]]; then
    return 1
  fi
  if [[ -n "${DW_WORKFLOW_UPDATES_OPERATOR_DIAGNOSTICS_EVIDENCE:-}" ]]; then
    return 1
  fi
  if [[ -n "${DW_WORKFLOW_UPDATES_OPERATOR_DIAGNOSTICS_EVIDENCE_PATH:-}" && -s "${DW_WORKFLOW_UPDATES_OPERATOR_DIAGNOSTICS_EVIDENCE_PATH:-}" ]]; then
    return 1
  fi
  if [[ -s "$result_dir/workflow-updates-operator-diagnostics-evidence.json" ]]; then
    return 1
  fi
  if [[ "$repo_root" != "/app" || -d "$repo_root/.git" ]]; then
    return 1
  fi
  if [[ ! -f "$repo_root/artisan" || ! -f "$repo_root/vendor/autoload.php" ]]; then
    return 1
  fi

  command -v php >/dev/null 2>&1
}

write_operator_diagnostics_shard_status() {
  OPERATOR_DIAGNOSTICS_SHARD_STATUS="${1:?status required}" \
  OPERATOR_DIAGNOSTICS_SHARD_SUMMARY="${2:?summary required}" \
  OPERATOR_DIAGNOSTICS_SHARD_STEP="${3:?step required}" \
  OPERATOR_DIAGNOSTICS_SHARD_RUNNER_BLOCKED="${4:-false}" \
  RESULT_DIR="$result_dir" \
  DW_SERVER_IMAGE="${DW_SERVER_IMAGE:-}" \
  DW_SERVER_VERSION="${DW_SERVER_VERSION:-}" \
  DW_CLI_VERSION="${DW_CLI_VERSION:-}" \
  DW_PYTHON_SDK_VERSION="${DW_PYTHON_SDK_VERSION:-}" \
  DW_PHP_SDK_VERSION="${DW_PHP_SDK_VERSION:-}" \
  DW_WORKFLOW_PHP_VERSION="${DW_WORKFLOW_PHP_VERSION:-${DW_WORKFLOW_VERSION:-}}" \
  DW_WORKFLOW_VERSION="${DW_WORKFLOW_VERSION:-}" \
  DW_WATERLINE_VERSION="${DW_WATERLINE_VERSION:-}" \
  node <<'NODE'
const fs = require('node:fs');
const path = require('node:path');

const resultDir = process.env.RESULT_DIR;
const workflowVersion = (process.env.DW_WORKFLOW_PHP_VERSION || '').trim()
  || (process.env.DW_WORKFLOW_VERSION || '').trim()
  || 'unresolved';
const serverImage = (process.env.DW_SERVER_IMAGE || '').trim();
const serverVersion = (process.env.DW_SERVER_VERSION || '').trim() || (serverImage.match(/:([^/:]+)$/)?.[1] ?? 'unresolved');
const cliVersion = (process.env.DW_CLI_VERSION || '').trim() || 'unresolved';
const pythonVersion = (process.env.DW_PYTHON_SDK_VERSION || '').trim() || 'unresolved';
const phpSdkVersion = (process.env.DW_PHP_SDK_VERSION || '').trim() || 'unresolved';
const waterlineVersion = (process.env.DW_WATERLINE_VERSION || '').trim() || 'unresolved';
const artifactVersions = {
  server: serverVersion,
  cli: cliVersion,
  'sdk-php': phpSdkVersion,
  'sdk-python': pythonVersion,
  workflow: workflowVersion,
  'workflow-php': workflowVersion,
  waterline: waterlineVersion,
};
const artifactSources = {
  server: serverImage || `docker://durableworkflow/server:${serverVersion}`,
  cli: `https://github.com/durable-workflow/cli/releases/download/${cliVersion}/install.sh`,
  'sdk-php': `packagist://durable-workflow/sdk@${phpSdkVersion}`,
  'sdk-python': `pypi://durable-workflow==${pythonVersion}`,
  workflow: `packagist://durable-workflow/workflow@${workflowVersion}`,
  'workflow-php': `packagist://durable-workflow/workflow@${workflowVersion}`,
  waterline: `packagist://durable-workflow/waterline@${waterlineVersion}`,
};
const runnerBlocked = ['1', 'true', 'yes'].includes((process.env.OPERATOR_DIAGNOSTICS_SHARD_RUNNER_BLOCKED || '').toLowerCase());
const status = process.env.OPERATOR_DIAGNOSTICS_SHARD_STATUS || (runnerBlocked ? 'runner_blocked' : 'fail');
const classification = runnerBlocked ? 'runner-blocked' : (['not_covered', 'unsupported'].includes(status) ? 'coverage-gap' : 'product-gap');
const summary = process.env.OPERATOR_DIAGNOSTICS_SHARD_SUMMARY || 'The CLI and Waterline operator diagnostics shard did not complete.';
const step = process.env.OPERATOR_DIAGNOSTICS_SHARD_STEP || 'operator_diagnostics_shard';
const generatedAt = new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
const finding = {
  finding_id: `workflow-updates-operator-diagnostics-surfaces-${runnerBlocked ? 'runner-blocked' : (classification === 'coverage-gap' ? 'coverage-gap' : 'product-gap')}`,
  finding_type: runnerBlocked ? 'conformance_runner_blocked' : (classification === 'coverage-gap' ? 'conformance_runner_coverage_gap' : 'product_behavior_failure'),
  classification,
  scenario_id: 'operator_diagnostics_surfaces',
  owning_surface: runnerBlocked ? 'conformance_harness' : 'waterline',
  summary,
  next_acceptance_criterion: 'Install the pinned CLI and Waterline published artifacts, run workflow:update --json for accepted, completed, failed, and refused update paths, and capture Waterline selected-run detail plus history-export diagnostics for the same run.',
  diagnostic: { step },
};
const emptyMatrix = {
  required_states: ['accepted', 'completed', 'failed', 'refused'],
  states: {},
  failures: [{ surface: 'operator_diagnostics_shard', state: '*', missing_fields: [step], message: summary }],
};
const scenario = {
  scenario_id: 'operator_diagnostics_surfaces',
  status,
  classification,
  published_artifact_cell_executed: false,
  local_product_source_checkouts_used: false,
  observed_outputs: {
    workflow_id: null,
    run_id: null,
    cli_fields: {
      cli_artifact_version: cliVersion,
      cli_artifact_source: artifactSources.cli,
      operator_surface_matrix: emptyMatrix,
    },
    api_fields: {},
    history_fields: {},
    waterline_fields: {
      waterline_artifact_version: waterlineVersion,
      waterline_artifact_source: artifactSources.waterline,
      operator_surface_matrix: emptyMatrix,
    },
    diagnostic_transition_matrix: emptyMatrix,
    artifact_versions: artifactVersions,
    published_artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
    source_policy: {
      pass_requires_published_artifacts_only: true,
      local_product_source_checkouts_used: false,
      local_checkout_execution_counts_as_pass: false,
    },
    published_artifact_cell_executed: false,
    local_product_source_checkouts_used: false,
  },
  linked_findings: [finding],
};
const payload = {
  schema: 'durable-workflow.v2.workflow-updates.operator-diagnostics-sidecar',
  generated_at: generatedAt,
  runner: 'published-cli-waterline-workflow-updates-operator-diagnostics-shard',
  runner_blocked: runnerBlocked,
  source_policy: {
    pass_requires_published_artifacts_only: true,
    local_product_source_checkouts_used: false,
    local_checkout_execution_counts_as_pass: false,
    artifact_sources: artifactSources,
  },
  artifact_versions: artifactVersions,
  published_artifact_versions: artifactVersions,
  artifact_sources: artifactSources,
  scenario_results: {
    operator_diagnostics_surfaces: scenario,
  },
  findings: [finding],
};

fs.writeFileSync(path.join(resultDir, 'workflow-updates-operator-diagnostics-evidence.json'), `${JSON.stringify(payload, null, 2)}\n`);
NODE
}

run_operator_diagnostics_worker_step() {
  local step="${1:?step required}"
  local update_id="${2:-}"
  local output_path="${3:-}"

  OPERATOR_STEP="$step" \
  OPERATOR_UPDATE_ID="$update_id" \
  OPERATOR_OUTPUT_PATH="$output_path" \
  RESULT_DIR="$result_dir" \
  RUNNER_REPO_ROOT="$repo_root" \
  OPERATOR_RUNTIME_PATH="$result_dir/operator-diagnostics-runtime.json" \
  OPERATOR_NAMESPACE="${OPERATOR_NAMESPACE:-workflow-updates-operator}" \
  OPERATOR_TASK_QUEUE="${OPERATOR_TASK_QUEUE:-workflow-updates-operator-queue}" \
  OPERATOR_WORKER_ID="${OPERATOR_WORKER_ID:-workflow-updates-operator-worker}" \
  APP_ENV=production \
  APP_DEBUG=false \
  APP_KEY="${APP_KEY:-base64:V09SS0ZMT1ctVVBEQVRFUy1PUEVSQVRPUi1ESUFHTk9TVElDUw==}" \
  DB_CONNECTION=sqlite \
  DB_DATABASE="${OPERATOR_SERVER_DB:?operator server database required}" \
  QUEUE_CONNECTION=database \
  CACHE_STORE=array \
  SESSION_DRIVER=array \
  DW_AUTH_DRIVER=none \
  DW_TASK_DISPATCH_MODE=poll \
  DW_V2_TASK_DISPATCH_MODE=poll \
  php <<'PHP'
<?php
declare(strict_types=1);

use App\Models\WorkflowNamespace;
use App\Support\ControlPlaneProtocol;
use App\Support\DirectConformanceWorkerProtocol;
use App\Support\WorkerProtocol;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Workflow\Serializers\Avro;

const OPERATOR_WORKFLOW_TYPE = 'workflow-updates.probe';

function env_text_operator(string $name, string $default = ''): string
{
    $value = getenv($name);

    return is_string($value) && trim($value) !== '' ? trim($value) : $default;
}

function write_operator_json(string $path, array $payload): void
{
    if (trim($path) === '') {
        throw new RuntimeException('Operator diagnostics output path must be non-empty.');
    }

    $directory = dirname($path);
    if (! is_dir($directory) || ! is_writable($directory)) {
        throw new RuntimeException('Operator diagnostics output directory is not writable: '.$directory);
    }

    $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    $temporaryPath = $path.'.tmp.'.strtolower(bin2hex(random_bytes(6)));
    $written = file_put_contents($temporaryPath, $encoded, LOCK_EX);
    if ($written !== strlen($encoded)) {
        @unlink($temporaryPath);
        throw new RuntimeException('Operator diagnostics runtime artifact write was incomplete: '.$path);
    }
    if (! rename($temporaryPath, $path)) {
        @unlink($temporaryPath);
        throw new RuntimeException('Operator diagnostics runtime artifact could not be committed atomically: '.$path);
    }
    clearstatcache(true, $path);
    if (! is_file($path) || filesize($path) !== strlen($encoded)) {
        throw new RuntimeException('Operator diagnostics runtime artifact verification failed after write: '.$path);
    }
}

function bootstrap_operator_application(): void
{
    $repoRoot = env_text_operator('RUNNER_REPO_ROOT');
    require_once $repoRoot.'/vendor/autoload.php';

    $app = require $repoRoot.'/bootstrap/app.php';
    $app->make(ConsoleKernel::class)->bootstrap();

    config([
        'app.key' => getenv('APP_KEY') ?: 'base64:V09SS0ZMT1ctVVBEQVRFUy1PUEVSQVRPUi1ESUFHTk9TVElDUw==',
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
}

function header_key_operator(string $name): string
{
    return 'HTTP_'.str_replace('-', '_', strtoupper($name));
}

function operator_request_json(string $method, string $path, ?array $body = null, array $allowed = []): array
{
    static $kernel = null;
    $kernel ??= app(HttpKernel::class);
    $namespace = env_text_operator('OPERATOR_NAMESPACE', 'workflow-updates-operator');

    $server = [
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_NAMESPACE' => $namespace,
        header_key_operator(ControlPlaneProtocol::HEADER) => ControlPlaneProtocol::VERSION,
        header_key_operator(WorkerProtocol::HEADER) => WorkerProtocol::VERSION,
    ];
    $content = $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR);
    $request = Request::create('/api'.$path, $method, [], [], [], $server, $content);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    $status = $response->getStatusCode();
    $raw = (string) $response->getContent();

    if (($status >= 400 || $status === 0) && ! in_array($status, $allowed, true)) {
        throw new RuntimeException(sprintf('%s %s failed with HTTP %d: %s', $method, $path, $status, $raw));
    }

    $decoded = $raw === '' ? [] : json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

    return [
        'status_code' => $status,
        'body' => is_array($decoded) ? $decoded : [],
    ];
}

function operator_parameter(string $name, int $position, string $type, bool $required = true, mixed $default = null): array
{
    return [
        'name' => $name,
        'position' => $position,
        'required' => $required,
        'variadic' => false,
        'default_available' => ! $required,
        'default' => $default,
        'type' => $type,
        'allows_null' => false,
    ];
}

function operator_workflow_command_contract(): array
{
    return [
        'queries' => ['state'],
        'query_contracts' => [
            ['name' => 'state', 'parameters' => []],
        ],
        'signals' => ['advance', 'finish'],
        'signal_contracts' => [
            ['name' => 'advance', 'parameters' => [operator_parameter('name', 0, 'string')]],
            ['name' => 'finish', 'parameters' => []],
        ],
        'updates' => ['adjust_payload', 'approve', 'fail_update'],
        'update_contracts' => [
            [
                'name' => 'approve',
                'parameters' => [
                    operator_parameter('approved', 0, 'bool'),
                    operator_parameter('source', 1, 'string', false, 'manual'),
                ],
            ],
            [
                'name' => 'adjust_payload',
                'parameters' => [operator_parameter('payload', 0, 'array')],
            ],
            [
                'name' => 'fail_update',
                'parameters' => [operator_parameter('reason', 0, 'string')],
            ],
        ],
        'update_validators' => [],
    ];
}

function operator_register_worker(string $workerId, string $taskQueue): void
{
    operator_request_json('POST', '/worker/register', DirectConformanceWorkerProtocol::registration(
        $workerId,
        $taskQueue,
        'php',
        'durable-workflow/server:published-artifact',
        [OPERATOR_WORKFLOW_TYPE],
        [],
        ['workflow_tasks', 'query_tasks'],
        attributes: ['workflow_command_contracts' => [
            OPERATOR_WORKFLOW_TYPE => operator_workflow_command_contract(),
        ]],
    ), [409]);
}

function operator_poll_task(string $workerId, string $taskQueue): array
{
    $response = operator_request_json('POST', '/worker/workflow-tasks/poll', [
        'worker_id' => $workerId,
        'task_queue' => $taskQueue,
    ]);
    $task = $response['body']['task'] ?? null;

    if (! is_array($task) || ! is_string($task['task_id'] ?? null)) {
        throw new RuntimeException('No workflow task was available for '.$workerId.'.');
    }

    return $task;
}

function operator_complete_task(array $task, array $commands): array
{
    return operator_request_json(
        'POST',
        '/worker/workflow-tasks/'.((string) $task['task_id']).'/complete',
        DirectConformanceWorkerProtocol::workflowTaskCompletion($task, $commands),
    )['body'];
}

function operator_open_signal_wait(string $workerId, string $taskQueue): array
{
    return operator_complete_task(operator_poll_task($workerId, $taskQueue), [
        [
            'type' => 'open_signal_wait',
            'signal_name' => 'advance',
            'timeout_seconds' => 300,
        ],
    ]);
}

$step = env_text_operator('OPERATOR_STEP');
$runtimePath = env_text_operator('OPERATOR_RUNTIME_PATH');
$outputPath = env_text_operator('OPERATOR_OUTPUT_PATH');
$namespace = env_text_operator('OPERATOR_NAMESPACE', 'workflow-updates-operator');
$taskQueue = env_text_operator('OPERATOR_TASK_QUEUE', 'workflow-updates-operator-queue');
$workerId = env_text_operator('OPERATOR_WORKER_ID', 'workflow-updates-operator-worker');

bootstrap_operator_application();

WorkflowNamespace::query()->updateOrCreate(
    ['name' => $namespace],
    [
        'description' => 'Workflow updates operator diagnostics conformance namespace',
        'retention_days' => 30,
        'status' => 'active',
    ],
);

if ($step === 'setup') {
    $suffix = strtolower(bin2hex(random_bytes(4)));
    $workflowId = 'wf-update-operator-'.$suffix;
    operator_register_worker($workerId, $taskQueue);
    $start = operator_request_json('POST', '/workflows', [
        'workflow_id' => $workflowId,
        'workflow_type' => OPERATOR_WORKFLOW_TYPE,
        'task_queue' => $taskQueue,
        'input' => ['operator-diagnostics'],
    ])['body'];
    $runId = (string) ($start['run_id'] ?? '');
    $startedWorkflowId = (string) ($start['workflow_id'] ?? $workflowId);
    if (trim($workflowId) === '' || trim($startedWorkflowId) === '' || $startedWorkflowId !== $workflowId) {
        throw new RuntimeException('Operator diagnostics setup returned an invalid workflow identity.');
    }
    if (trim($runId) === '') {
        throw new RuntimeException('Operator diagnostics setup returned an empty run identity.');
    }
    $open = operator_open_signal_wait($workerId, $taskQueue);
    $runtime = [
        'namespace' => $namespace,
        'workflow_id' => $workflowId,
        'run_id' => $runId,
        'worker_id' => $workerId,
        'task_queue' => $taskQueue,
        'start_response' => $start,
        'open_signal_wait_response' => $open,
    ];
    write_operator_json($runtimePath, $runtime);
    if ($outputPath !== '') {
        write_operator_json($outputPath, $runtime);
    }

    return;
}

try {
    if ($runtimePath === '' || ! is_file($runtimePath) || filesize($runtimePath) === 0) {
        throw new RuntimeException('required runtime artifact is missing or empty');
    }
    $runtime = json_decode((string) file_get_contents($runtimePath), true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($runtime)) {
        throw new RuntimeException('runtime artifact must contain a JSON object');
    }
    foreach (['namespace', 'workflow_id', 'run_id', 'worker_id', 'task_queue'] as $field) {
        if (! is_string($runtime[$field] ?? null) || trim($runtime[$field]) === '') {
            throw new RuntimeException($field.' must be a non-empty string');
        }
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'operator_runtime_artifact_invalid: '.$exception->getMessage()."\n");
    exit(1);
}

$workerId = (string) ($runtime['worker_id'] ?? $workerId);
$taskQueue = (string) ($runtime['task_queue'] ?? $taskQueue);
$updateId = env_text_operator('OPERATOR_UPDATE_ID');
if ($updateId === '') {
    throw new RuntimeException('OPERATOR_UPDATE_ID is required for '.$step.'.');
}

if ($step === 'complete') {
    $result = operator_complete_task(operator_poll_task($workerId, $taskQueue), [
        [
            'type' => 'complete_update',
            'update_id' => $updateId,
            'result' => Avro::envelope([
                'approved' => true,
                'source' => 'operator-diagnostics-cli-waterline',
            ]),
        ],
    ]);
} elseif ($step === 'fail') {
    $result = operator_complete_task(operator_poll_task($workerId, $taskQueue), [
        [
            'type' => 'fail_update',
            'update_id' => $updateId,
            'message' => 'workflow update operator diagnostics failure',
            'exception_class' => 'DurableWorkflow\\Conformance\\WorkflowUpdateOperatorDiagnosticsFailure',
            'exception_type' => 'workflow_update_operator_diagnostics_failure',
            'non_retryable' => true,
        ],
    ]);
} else {
    throw new RuntimeException('Unknown operator diagnostics worker step: '.$step.'.');
}

if ($outputPath !== '') {
    write_operator_json($outputPath, $result);
}
PHP
}

install_published_operator_cli() {
  local cli_version="${1:?cli version required}"
  local cli_root="${2:?cli root required}"
  local cli_installer_url=""
  mkdir -p "$cli_root/bin"

  for candidate_url in \
    "https://github.com/durable-workflow/cli/releases/download/${cli_version}/install.sh" \
    "https://github.com/durable-workflow/cli/releases/download/v${cli_version}/install.sh"
  do
    if curl -fsSL --retry 3 -o "$cli_root/install.sh" "$candidate_url" >"$result_dir/operator-cli-installer-download.log" 2>&1; then
      cli_installer_url="$candidate_url"
      break
    fi
  done

  if [[ -z "$cli_installer_url" ]]; then
    return 1
  fi

  printf '%s\n' "$cli_installer_url" > "$cli_root/installer-source.txt"

  PATH="$cli_root/bin${PATH:+:$PATH}" \
    VERSION="$cli_version" \
    DURABLE_WORKFLOW_INSTALL_DIR="$cli_root/bin" \
    DURABLE_WORKFLOW_BIN_NAME=dw \
    sh "$cli_root/install.sh" >"$result_dir/operator-cli-install.log" 2>&1
}

run_operator_cli_capture() {
  local name="${1:?capture name required}"
  shift
  local stdout_path="$result_dir/operator-cli-${name}.stdout.json"
  local stderr_path="$result_dir/operator-cli-${name}.stderr.log"
  local capture_path="$result_dir/operator-cli-${name}.json"
  local status

  set +e
  "$@" >"$stdout_path" 2>"$stderr_path"
  status=$?
  set -e

  OPERATOR_CAPTURE_NAME="$name" \
  OPERATOR_CAPTURE_STATUS="$status" \
  OPERATOR_CAPTURE_STDOUT="$stdout_path" \
  OPERATOR_CAPTURE_STDERR="$stderr_path" \
  OPERATOR_CAPTURE_PATH="$capture_path" \
  node <<'NODE'
const fs = require('node:fs');

const name = process.env.OPERATOR_CAPTURE_NAME;
const stdoutPath = process.env.OPERATOR_CAPTURE_STDOUT;
const stderrPath = process.env.OPERATOR_CAPTURE_STDERR;
const capturePath = process.env.OPERATOR_CAPTURE_PATH;
const status = Number.parseInt(process.env.OPERATOR_CAPTURE_STATUS || '1', 10);
const raw = fs.existsSync(stdoutPath) ? fs.readFileSync(stdoutPath, 'utf8').trim() : '';
const stderr = fs.existsSync(stderrPath) ? fs.readFileSync(stderrPath, 'utf8') : '';
let json = null;
let parse_error = null;
if (raw !== '') {
  try {
    json = JSON.parse(raw);
  } catch (error) {
    parse_error = error.message;
  }
}
fs.writeFileSync(capturePath, `${JSON.stringify({
  surface: 'workflow:update --json',
  capture: name,
  exit_status: Number.isFinite(status) ? status : 1,
  stdout_path: stdoutPath,
  stderr_path: stderrPath,
  json,
  raw_stdout: raw.slice(0, 4000),
  stderr: stderr.slice(0, 4000),
  parse_error,
}, null, 2)}\n`);
NODE
}

operator_cli_update_id() {
  node -e 'const fs = require("node:fs"); const value = JSON.parse(fs.readFileSync(process.argv[1], "utf8")); process.stdout.write(String(value?.json?.update_id || ""));' "$1"
}

capture_operator_server_api() {
  local method="${1:?method required}"
  local api_path="${2:?path required}"
  local output_path="${3:?output path required}"
  local namespace="${4:?namespace required}"
  local server_url="${5:?server URL required}"
  local body_path="${output_path}.body"
  local stderr_path="${output_path}.stderr"
  local status
  local curl_status

  set +e
  status="$(curl -sS -o "$body_path" -w "%{http_code}" \
    -X "$method" \
    -H 'Accept: application/json' \
    -H 'Content-Type: application/json' \
    -H "X-Namespace: ${namespace}" \
    -H 'X-Durable-Workflow-Control-Plane-Version: 2' \
    "${server_url}/api${api_path}" 2>"$stderr_path")"
  curl_status=$?
  set -e

  OPERATOR_API_METHOD="$method" \
  OPERATOR_API_PATH="$api_path" \
  OPERATOR_API_STATUS="$status" \
  OPERATOR_API_CURL_STATUS="$curl_status" \
  OPERATOR_API_BODY="$body_path" \
  OPERATOR_API_STDERR="$stderr_path" \
  OPERATOR_API_CAPTURE="$output_path" \
  node <<'NODE'
const fs = require('node:fs');

const bodyPath = process.env.OPERATOR_API_BODY;
const stderrPath = process.env.OPERATOR_API_STDERR;
const raw = fs.existsSync(bodyPath) ? fs.readFileSync(bodyPath, 'utf8').trim() : '';
const stderr = fs.existsSync(stderrPath) ? fs.readFileSync(stderrPath, 'utf8') : '';
let json = null;
let parse_error = null;
if (raw !== '') {
  try {
    json = JSON.parse(raw);
  } catch (error) {
    parse_error = error.message;
  }
}
fs.writeFileSync(process.env.OPERATOR_API_CAPTURE, `${JSON.stringify({
  method: process.env.OPERATOR_API_METHOD,
  path: process.env.OPERATOR_API_PATH,
  status: Number.parseInt(process.env.OPERATOR_API_STATUS || '0', 10),
  curl_status: Number.parseInt(process.env.OPERATOR_API_CURL_STATUS || '1', 10),
  json,
  raw: raw.slice(0, 4000),
  stderr: stderr.slice(0, 4000),
  parse_error,
}, null, 2)}\n`);
NODE
}

materialize_operator_diagnostics_report() {
  OPERATOR_RUNTIME_PATH="${1:?runtime path required}" \
  OPERATOR_WATERLINE_REPORT_PATH="${2:?waterline report path required}" \
  OPERATOR_CLI_INSTALLER_SOURCE_PATH="$result_dir/operator-cli/installer-source.txt" \
  OPERATOR_RUN_DETAIL_CAPTURE_PATH="$result_dir/operator-run-detail-api.json" \
  OPERATOR_HISTORY_CAPTURE_PATH="$result_dir/operator-history-api.json" \
  RESULT_DIR="$result_dir" \
  DW_SERVER_IMAGE="${DW_SERVER_IMAGE:-}" \
  DW_SERVER_VERSION="${DW_SERVER_VERSION:-}" \
  DW_CLI_VERSION="${DW_CLI_VERSION:-}" \
  DW_PYTHON_SDK_VERSION="${DW_PYTHON_SDK_VERSION:-}" \
  DW_PHP_SDK_VERSION="${DW_PHP_SDK_VERSION:-}" \
  DW_WORKFLOW_PHP_VERSION="${DW_WORKFLOW_PHP_VERSION:-${DW_WORKFLOW_VERSION:-}}" \
  DW_WORKFLOW_VERSION="${DW_WORKFLOW_VERSION:-}" \
  DW_WATERLINE_VERSION="${DW_WATERLINE_VERSION:-}" \
  node <<'NODE'
const fs = require('node:fs');
const path = require('node:path');

const resultDir = process.env.RESULT_DIR;
const workflowVersion = (process.env.DW_WORKFLOW_PHP_VERSION || '').trim()
  || (process.env.DW_WORKFLOW_VERSION || '').trim()
  || 'unresolved';
const serverImage = (process.env.DW_SERVER_IMAGE || '').trim();
const serverVersion = (process.env.DW_SERVER_VERSION || '').trim() || (serverImage.match(/:([^/:]+)$/)?.[1] ?? 'unresolved');
const cliVersion = (process.env.DW_CLI_VERSION || '').trim() || 'unresolved';
const pythonVersion = (process.env.DW_PYTHON_SDK_VERSION || '').trim() || 'unresolved';
const phpSdkVersion = (process.env.DW_PHP_SDK_VERSION || '').trim() || 'unresolved';
const waterlineVersion = (process.env.DW_WATERLINE_VERSION || '').trim() || 'unresolved';
function readText(file) {
  try {
    return file && fs.existsSync(file) ? fs.readFileSync(file, 'utf8').trim() : '';
  } catch (error) {
    return '';
  }
}
const cliInstallerSource = readText(process.env.OPERATOR_CLI_INSTALLER_SOURCE_PATH)
  || `https://github.com/durable-workflow/cli/releases/download/${cliVersion}/install.sh`;
const artifactVersions = {
  server: serverVersion,
  cli: cliVersion,
  'sdk-php': phpSdkVersion,
  'sdk-python': pythonVersion,
  workflow: workflowVersion,
  'workflow-php': workflowVersion,
  waterline: waterlineVersion,
};
const artifactSources = {
  server: serverImage || `docker://durableworkflow/server:${serverVersion}`,
  cli: cliInstallerSource,
  'sdk-php': `packagist://durable-workflow/sdk@${phpSdkVersion}`,
  'sdk-python': `pypi://durable-workflow==${pythonVersion}`,
  workflow: `packagist://durable-workflow/workflow@${workflowVersion}`,
  'workflow-php': `packagist://durable-workflow/workflow@${workflowVersion}`,
  waterline: `packagist://durable-workflow/waterline@${waterlineVersion}`,
};
const states = ['accepted', 'completed', 'failed', 'refused'];

function readJson(file) {
  try {
    if (file && fs.existsSync(file) && fs.statSync(file).size > 0) {
      return JSON.parse(fs.readFileSync(file, 'utf8'));
    }
  } catch (error) {
    return { read_error: error.message };
  }

  return null;
}

function objectValue(value) {
  return value && typeof value === 'object' && !Array.isArray(value) ? value : {};
}

function hasOwn(value, field) {
  return value && typeof value === 'object' && Object.prototype.hasOwnProperty.call(value, field);
}

function stringValue(value) {
  return typeof value === 'string' && value.trim() !== '' ? value.trim() : null;
}

function normalizedStateValue(value) {
  return stringValue(value)
    ?.replace(/([a-z])([A-Z])/g, '$1_$2')
    .replace(/[\s-]+/g, '_')
    .toLowerCase() ?? null;
}

function stateMatchesExpected(value, state) {
  return [
    value?.state,
    value?.status,
    value?.state_label,
    value?.stateLabel,
  ].map(normalizedStateValue).includes(state);
}

function nonEmptyObject(value) {
  return value && typeof value === 'object' && !Array.isArray(value) && Object.keys(value).length > 0;
}

function arrayRows(value) {
  if (Array.isArray(value)) {
    return value.filter((row) => row && typeof row === 'object');
  }
  if (value && typeof value === 'object') {
    return Object.values(value).filter((row) => row && typeof row === 'object');
  }

  return [];
}

function scenarioRows(value) {
  return arrayRows(value?.scenario_results ?? value?.scenarioResults);
}

function workflowUpdateJson(capture) {
  return objectValue(capture?.json);
}

function historyReferences(value) {
  const diagnostics = objectValue(value.update_diagnostics);
  return value.history_references ?? diagnostics.history_references ?? objectValue(value.cli_fields).history_references ?? null;
}

function payloadVisible(value) {
  const diagnostics = objectValue(value.update_diagnostics);
  const request = objectValue(value.request);

  return hasOwn(value, 'payload')
    || hasOwn(diagnostics, 'payload')
    || hasOwn(request, 'input');
}

function resultVisible(value) {
  const diagnostics = objectValue(value.update_diagnostics);

  return hasOwn(value, 'result')
    || hasOwn(value, 'result_envelope')
    || hasOwn(diagnostics, 'result')
    || hasOwn(diagnostics, 'result_envelope');
}

function errorVisible(value) {
  const diagnostics = objectValue(value.update_diagnostics);

  return hasOwn(value, 'error_details')
    || hasOwn(diagnostics, 'error')
    || stringValue(value.failure_id) !== null
    || stringValue(value.failure_message) !== null
    || stringValue(value.reason) !== null
    || stringValue(value.rejection_reason) !== null
    || stringValue(value.message) !== null;
}

function cliStateEvidence(capture, state) {
  const json = workflowUpdateJson(capture);
  const refs = historyReferences(json);
  const request = objectValue(json.request);
  const cliFields = objectValue(json.cli_fields);
  const stateValue = stringValue(json.state)
    || stringValue(json.update_state)
    || stringValue(cliFields.state)
    || stringValue(json.update_status);
  const outcome = stringValue(json.outcome);
  const reason = stringValue(json.reason) || stringValue(json.rejection_reason);
  const requestId = stringValue(json.request_id)
    || stringValue(request.request_id)
    || stringValue(cliFields.request_id);
  const updateId = stringValue(json.update_id) || stringValue(cliFields.update_id);

  return {
    present: nonEmptyObject(json),
    exit_status: Number.isInteger(capture?.exit_status) ? capture.exit_status : null,
    request_id: requestId,
    update_id: updateId,
    state: stateValue,
    outcome,
    reason,
    request_identifiers_visible: requestId !== null,
    state_visible: stateValue === state || (state === 'completed' && stateValue === 'completed') || (state === 'failed' && stateValue === 'failed') || (state === 'refused' && stateValue === 'refused'),
    outcome_or_reason_visible: outcome !== null || reason !== null,
    payload_visible: payloadVisible(json),
    result_visible: state === 'completed' ? resultVisible(json) : null,
    error_visible: ['failed', 'refused'].includes(state) ? errorVisible(json) : null,
    history_references_visible: nonEmptyObject(refs),
    history_references: refs,
    fields_present: Array.isArray(cliFields.fields_present) ? cliFields.fields_present : [],
    parse_error: capture?.parse_error || null,
  };
}

function surfaceStates(surface) {
  return objectValue(
    surface?.operator_surface_matrix?.states
      ?? surface?.diagnostic_transition_matrix?.states
      ?? surface?.states,
  );
}

function surfaceMatrixFailures(surfaceName, matrix) {
  const matrixStates = surfaceStates(matrix);
  const failures = [];
  for (const state of states) {
    const evidence = objectValue(matrixStates[state]);
    const missing = [];
    for (const field of [
      'present',
      'request_identifiers_visible',
      'payload_visible',
      'outcome_or_reason_visible',
      'history_references_visible',
    ]) {
      if (evidence[field] !== true) {
        missing.push(field);
      }
    }
    if (surfaceName === 'waterline' && evidence.history_export_references_visible !== true) {
      missing.push('history_export_references_visible');
    }
    if (!stateMatchesExpected(evidence, state)) {
      missing.push('expected_state');
    }
    if (state === 'completed' && evidence.result_visible !== true) {
      missing.push('result_visible');
    }
    if (['failed', 'refused'].includes(state) && evidence.error_visible !== true) {
      missing.push('error_visible');
    }
    if (missing.length > 0) {
      failures.push({ surface: surfaceName, state, missing_fields: missing, evidence });
    }
  }

  return failures;
}

const runtime = readJson(process.env.OPERATOR_RUNTIME_PATH) ?? {};
const captures = {
  accepted: readJson(path.join(resultDir, 'operator-cli-accepted.json')),
  completed: readJson(path.join(resultDir, 'operator-cli-completed.json')),
  failed: readJson(path.join(resultDir, 'operator-cli-failed.json')),
  refused: readJson(path.join(resultDir, 'operator-cli-refused.json')),
};
const runDetailCapture = readJson(process.env.OPERATOR_RUN_DETAIL_CAPTURE_PATH);
const historyCapture = readJson(process.env.OPERATOR_HISTORY_CAPTURE_PATH);
const waterlineReport = readJson(process.env.OPERATOR_WATERLINE_REPORT_PATH) ?? {};
const waterlineOperatorScenario = scenarioRows(waterlineReport)
  .find((row) => row?.scenario_id === 'operator_diagnostics_surfaces') ?? null;
const waterlineObserved = objectValue(waterlineOperatorScenario?.observed_outputs);
const waterlineMatrix = objectValue(waterlineObserved.operator_surface_matrix);
const cliMatrix = {
  surface: 'workflow:update --json',
  required_states: states,
  state_counts: Object.fromEntries(states.map((state) => [state, captures[state] ? 1 : 0])),
  states: Object.fromEntries(states.map((state) => [state, cliStateEvidence(captures[state], state)])),
  command_captures: {
    accepted: captures.accepted,
    completed: captures.completed,
    failed: captures.failed,
    refused: captures.refused,
  },
};
const cliFailures = surfaceMatrixFailures('cli', cliMatrix);
const waterlineFailures = surfaceMatrixFailures('waterline', waterlineMatrix);
if (waterlineOperatorScenario?.status !== 'pass') {
  waterlineFailures.push({
    surface: 'waterline',
    state: '*',
    missing_fields: ['operator_diagnostics_surfaces.pass'],
    evidence: {
      status: waterlineOperatorScenario?.status ?? null,
      findings: waterlineOperatorScenario?.linked_findings ?? waterlineReport.findings ?? [],
    },
  });
}
const diagnosticTransitionMatrix = {
  required_states: states,
  surfaces: {
    cli: cliMatrix,
    waterline: waterlineMatrix,
  },
  states: Object.fromEntries(states.map((state) => [
    state,
    {
      cli: cliMatrix.states[state],
      waterline: surfaceStates(waterlineMatrix)[state] ?? null,
    },
  ])),
  failures: [...cliFailures, ...waterlineFailures],
};
const status = diagnosticTransitionMatrix.failures.length === 0 ? 'pass' : 'fail';
const linkedFindings = status === 'pass'
  ? []
  : [{
    finding_id: 'workflow-updates-operator-diagnostics-surfaces-product-gap',
    finding_type: 'product_behavior_failure',
    classification: 'product-gap',
    scenario_id: 'operator_diagnostics_surfaces',
    owning_surface: 'waterline',
    summary: 'The published CLI JSON and Waterline selected-run diagnostics did not both prove accepted, completed, failed, and refused workflow update paths.',
    next_acceptance_criterion: 'Both workflow:update --json and Waterline selected-run detail/history export expose request ids, state/outcome/reason, payload/result/error details, and history references for accepted, completed, failed, and refused update paths.',
    evidence: diagnosticTransitionMatrix.failures,
  }];

for (const finding of arrayRows(waterlineOperatorScenario?.linked_findings ?? waterlineReport.findings)) {
  linkedFindings.push(finding);
}

const observedOutputs = {
  workflow_id: runtime.workflow_id ?? workflowUpdateJson(captures.accepted).workflow_id ?? null,
  run_id: runtime.run_id ?? workflowUpdateJson(captures.accepted).run_id ?? null,
  cli_fields: {
    surface: 'workflow:update --json',
    cli_artifact_version: cliVersion,
    cli_artifact_source: artifactSources.cli,
    operator_surface_matrix: cliMatrix,
  },
  api_fields: {
    run_detail_capture: runDetailCapture,
    run_detail: runDetailCapture?.json ?? null,
  },
  history_fields: {
    history_capture: historyCapture,
    history: historyCapture?.json ?? null,
  },
  waterline_fields: {
    waterline_artifact_version: waterlineVersion,
    waterline_artifact_source: artifactSources.waterline,
    command_schema: waterlineReport.schema ?? null,
    command_outcome: waterlineReport.outcome ?? null,
    operator_surface_matrix: waterlineMatrix,
    api_captures: waterlineObserved.api_captures ?? waterlineReport.api_captures ?? null,
    selected_run_updates: waterlineObserved.selected_run_updates ?? null,
    history_update_events: waterlineObserved.history_update_events ?? null,
  },
  diagnostic_transition_matrix: diagnosticTransitionMatrix,
  artifact_install_evidence: {
    cli: {
      installed_from: artifactSources.cli,
      version: cliVersion,
      installer: 'official GitHub release install.sh asset',
    },
    waterline: {
      installed_from: artifactSources.waterline,
      version: waterlineVersion,
      package: 'durable-workflow/waterline',
    },
  },
  artifact_versions: artifactVersions,
  published_artifact_versions: artifactVersions,
  artifact_sources: artifactSources,
  source_policy: {
    pass_requires_published_artifacts_only: true,
    local_product_source_checkouts_used: false,
    local_checkout_execution_counts_as_pass: false,
  },
  published_artifact_cell_executed: true,
  local_product_source_checkouts_used: false,
};
const scenario = {
  scenario_id: 'operator_diagnostics_surfaces',
  status,
  classification: status === 'pass' ? 'product-evidence' : 'product-gap',
  published_artifact_cell_executed: true,
  local_product_source_checkouts_used: false,
  observed_outputs: observedOutputs,
  linked_findings: linkedFindings,
};
const payload = {
  schema: 'durable-workflow.v2.workflow-updates.operator-diagnostics-sidecar',
  generated_at: new Date().toISOString().replace(/\.\d{3}Z$/, 'Z'),
  runner: 'published-cli-waterline-workflow-updates-operator-diagnostics-shard',
  runner_blocked: false,
  source_policy: {
    pass_requires_published_artifacts_only: true,
    local_product_source_checkouts_used: false,
    local_checkout_execution_counts_as_pass: false,
    artifact_sources: artifactSources,
  },
  artifact_versions: artifactVersions,
  published_artifact_versions: artifactVersions,
  artifact_sources: artifactSources,
  scenario_results: {
    operator_diagnostics_surfaces: scenario,
  },
  findings: linkedFindings,
  cli_update_captures: captures,
  waterline_report: {
    schema: waterlineReport.schema ?? null,
    outcome: waterlineReport.outcome ?? null,
    runtime_matrix: waterlineReport.runtime_matrix ?? null,
  },
};

fs.writeFileSync(path.join(resultDir, 'workflow-updates-operator-diagnostics-evidence.json'), `${JSON.stringify(payload, null, 2)}\n`);
NODE
}

run_operator_diagnostics_shard() {
  local cli_version="${DW_CLI_VERSION:-}"
  local waterline_version="${DW_WATERLINE_VERSION:-}"
  local workflow_php_version="${DW_WORKFLOW_PHP_VERSION:-${DW_WORKFLOW_VERSION:-}}"
  local sdk_php_version="${DW_PHP_SDK_VERSION:-}"
  local operator_db="$result_dir/operator-diagnostics-server.sqlite"
  local operator_port="${DW_WORKFLOW_UPDATES_OPERATOR_SERVER_PORT:-}"
  local operator_url
  local operator_cli_root="$result_dir/operator-cli"
  local operator_cli_bin
  local operator_cli_installer_source
  local operator_waterline_app="$result_dir/operator-waterline-app"
  local composer_home="$result_dir/operator-waterline-composer-home"
  local composer_cache="$result_dir/operator-waterline-composer-cache"
  local runtime_path="$result_dir/operator-diagnostics-runtime.json"
  local waterline_report="$result_dir/operator-waterline-workflow-updates-report.json"
  local namespace="${OPERATOR_NAMESPACE:-workflow-updates-operator}"
  local workflow_id
  local run_id
  local completed_request_id
  local completed_update_id
  local failed_request_id
  local failed_update_id
  local accepted_request_id
  local refused_request_id

  if [[ -z "$cli_version" ]] || ! is_exact_package_version "$cli_version"; then
    write_operator_diagnostics_shard_status not_covered "DW_CLI_VERSION must be an exact CLI release version before operator diagnostics can install the official CLI artifact." cli_version_resolution false
    return 0
  fi
  if [[ -z "$waterline_version" ]] || ! is_exact_package_version "$waterline_version"; then
    write_operator_diagnostics_shard_status not_covered "DW_WATERLINE_VERSION must be an exact Waterline release version before operator diagnostics can install the Packagist artifact." waterline_version_resolution false
    return 0
  fi
  if [[ -z "$workflow_php_version" ]] || ! is_exact_package_version "$workflow_php_version"; then
    write_operator_diagnostics_shard_status not_covered "DW_WORKFLOW_PHP_VERSION must be an exact durable-workflow/workflow version before the Waterline diagnostics app can install from Packagist." workflow_php_version_resolution false
    return 0
  fi
  if [[ -z "$sdk_php_version" ]] || ! is_exact_package_version "$sdk_php_version"; then
    write_operator_diagnostics_shard_status not_covered "DW_PHP_SDK_VERSION must be an exact durable-workflow/sdk version before the Waterline diagnostics app can install from Packagist." sdk_php_version_resolution false
    return 0
  fi
  if ! command -v curl >/dev/null 2>&1; then
    write_operator_diagnostics_shard_status runner_blocked "curl is required to install the CLI release artifact and capture server diagnostics." curl_unavailable true
    return 0
  fi
  if ! command -v composer >/dev/null 2>&1; then
    write_operator_diagnostics_shard_status runner_blocked "Composer is required to install the pinned Packagist durable-workflow/waterline package." composer_unavailable true
    return 0
  fi

  if [[ -z "$operator_port" ]]; then
    operator_port="$(choose_tcp_port)"
  fi
  operator_url="http://127.0.0.1:${operator_port}"

  : > "$operator_db"
  if ! APP_ENV=production \
    APP_DEBUG=false \
    APP_KEY="${APP_KEY:-base64:V09SS0ZMT1ctVVBEQVRFUy1PUEVSQVRPUi1TRVJWRVI=}" \
    DB_CONNECTION=sqlite \
    DB_DATABASE="$operator_db" \
    QUEUE_CONNECTION=database \
    CACHE_STORE=array \
    SESSION_DRIVER=array \
    DW_AUTH_DRIVER=none \
    DW_TASK_DISPATCH_MODE=poll \
    DW_V2_TASK_DISPATCH_MODE=poll \
    php "$repo_root/artisan" server:bootstrap --force \
      > "$result_dir/operator-server-bootstrap.log" 2>&1; then
    write_operator_diagnostics_shard_status fail "The published server API could not bootstrap the temporary operator diagnostics database; see operator-server-bootstrap.log." server_bootstrap false
    return 0
  fi

  OPERATOR_SERVER_DB="$operator_db" \
    run_operator_diagnostics_worker_step setup "" "$result_dir/operator-worker-setup.json" \
    > "$result_dir/operator-worker-setup.log" 2>&1 || {
      write_operator_diagnostics_shard_status fail "The operator diagnostics shard could not create the temporary workflow run; see operator-worker-setup.log." operator_worker_setup false
      return 0
    }

  local runtime_identity
  if ! runtime_identity="$(php "$script_dir/validate-workflow-updates-operator-runtime.php" "$runtime_path" 2> "$result_dir/operator-runtime-validation.log")"; then
    local runtime_diagnostic
    runtime_diagnostic="$(tr '\n' ' ' < "$result_dir/operator-runtime-validation.log" | sed 's/[[:space:]]\+/ /g; s/[[:space:]]$//' | cut -c1-1000)"
    write_operator_diagnostics_shard_status fail "The operator diagnostics setup exited without a valid runtime artifact: ${runtime_diagnostic:-validation produced no diagnostic}." operator_runtime_artifact false
    return 0
  fi
  workflow_id="$(node -e 'const value = JSON.parse(process.argv[1]); process.stdout.write(value.workflow_id);' "$runtime_identity")"
  run_id="$(node -e 'const value = JSON.parse(process.argv[1]); process.stdout.write(value.run_id);' "$runtime_identity")"

  APP_ENV=production \
  APP_DEBUG=false \
  APP_KEY="${APP_KEY:-base64:V09SS0ZMT1ctVVBEQVRFUy1PUEVSQVRPUi1TRVJWRVI=}" \
  DB_CONNECTION=sqlite \
  DB_DATABASE="$operator_db" \
  QUEUE_CONNECTION=database \
  CACHE_STORE=array \
  SESSION_DRIVER=array \
  DW_AUTH_DRIVER=none \
  DW_TASK_DISPATCH_MODE=poll \
  DW_V2_TASK_DISPATCH_MODE=poll \
  PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-8}" \
  php "$repo_root/artisan" serve --host=127.0.0.1 --port="$operator_port" --no-reload \
    > "$result_dir/operator-server.log" 2>&1 &
  cleanup_pids+=("$!")

  if ! wait_for_http "$operator_url/api/health"; then
    write_operator_diagnostics_shard_status runner_blocked "The temporary published server HTTP surface did not become ready for the operator diagnostics shard; see operator-server.log." server_http_unavailable true
    return 0
  fi

  if ! install_published_operator_cli "$cli_version" "$operator_cli_root"; then
    write_operator_diagnostics_shard_status runner_blocked "The official CLI installer could not install release ${cli_version}; see operator-cli-installer-download.log or operator-cli-install.log." cli_install true
    return 0
  fi
  operator_cli_bin="$operator_cli_root/bin/dw"
  if [[ ! -x "$operator_cli_bin" ]]; then
    write_operator_diagnostics_shard_status runner_blocked "The official CLI installer did not produce an executable dw binary." cli_executable_missing true
    return 0
  fi
  operator_cli_installer_source="$(tr -d '\r\n' < "$operator_cli_root/installer-source.txt" 2>/dev/null || true)"
  if [[ -z "$operator_cli_installer_source" ]]; then
    write_operator_diagnostics_shard_status runner_blocked "The official CLI installer source was not recorded after installation." cli_installer_source_missing true
    return 0
  fi

  "$operator_cli_bin" --version > "$result_dir/operator-cli-version.log" 2>&1 || {
    write_operator_diagnostics_shard_status fail "The installed CLI release could not report its version; see operator-cli-version.log." cli_version_check false
    return 0
  }

  completed_request_id="cli-completed-${run_id}"
  run_operator_cli_capture completed-accepted "$operator_cli_bin" workflow:update "$workflow_id" approve \
    --server="$operator_url" \
    --namespace="$namespace" \
    --run-id="$run_id" \
    --request-id="$completed_request_id" \
    --wait=accepted \
    --input='[true,"cli-completed"]' \
    --json
  completed_update_id="$(operator_cli_update_id "$result_dir/operator-cli-completed-accepted.json")"
  if [[ -z "$completed_update_id" ]]; then
    write_operator_diagnostics_shard_status fail "workflow:update --json did not return an update id for the completed path; see operator-cli-completed-accepted.json." cli_completed_accept false
    return 0
  fi
  OPERATOR_SERVER_DB="$operator_db" \
    run_operator_diagnostics_worker_step complete "$completed_update_id" "$result_dir/operator-worker-complete.json" \
    > "$result_dir/operator-worker-complete.log" 2>&1 || {
      write_operator_diagnostics_shard_status fail "The operator diagnostics worker could not complete the CLI accepted update; see operator-worker-complete.log." worker_complete false
      return 0
    }
  run_operator_cli_capture completed "$operator_cli_bin" workflow:update "$workflow_id" approve \
    --server="$operator_url" \
    --namespace="$namespace" \
    --run-id="$run_id" \
    --request-id="$completed_request_id" \
    --wait=completed \
    --input='[true,"cli-completed-duplicate"]' \
    --json

  failed_request_id="cli-failed-${run_id}"
  run_operator_cli_capture failed-accepted "$operator_cli_bin" workflow:update "$workflow_id" fail_update \
    --server="$operator_url" \
    --namespace="$namespace" \
    --run-id="$run_id" \
    --request-id="$failed_request_id" \
    --wait=accepted \
    --input='["cli failure"]' \
    --json
  failed_update_id="$(operator_cli_update_id "$result_dir/operator-cli-failed-accepted.json")"
  if [[ -z "$failed_update_id" ]]; then
    write_operator_diagnostics_shard_status fail "workflow:update --json did not return an update id for the failed path; see operator-cli-failed-accepted.json." cli_failed_accept false
    return 0
  fi
  OPERATOR_SERVER_DB="$operator_db" \
    run_operator_diagnostics_worker_step fail "$failed_update_id" "$result_dir/operator-worker-fail.json" \
    > "$result_dir/operator-worker-fail.log" 2>&1 || {
      write_operator_diagnostics_shard_status fail "The operator diagnostics worker could not fail the CLI accepted update; see operator-worker-fail.log." worker_fail false
      return 0
    }
  run_operator_cli_capture failed "$operator_cli_bin" workflow:update "$workflow_id" fail_update \
    --server="$operator_url" \
    --namespace="$namespace" \
    --run-id="$run_id" \
    --request-id="$failed_request_id" \
    --wait=completed \
    --input='["cli failure duplicate"]' \
    --json

  accepted_request_id="cli-accepted-${run_id}"
  run_operator_cli_capture accepted "$operator_cli_bin" workflow:update "$workflow_id" approve \
    --server="$operator_url" \
    --namespace="$namespace" \
    --run-id="$run_id" \
    --request-id="$accepted_request_id" \
    --wait=accepted \
    --input='[true,"cli-accepted"]' \
    --json

  refused_request_id="cli-refused-${run_id}"
  run_operator_cli_capture refused "$operator_cli_bin" workflow:update "$workflow_id" missing_update \
    --server="$operator_url" \
    --namespace="$namespace" \
    --run-id="$run_id" \
    --request-id="$refused_request_id" \
    --wait=accepted \
    --input='[]' \
    --json

  capture_operator_server_api GET "/workflows/${workflow_id}/runs/${run_id}" "$result_dir/operator-run-detail-api.json" "$namespace" "$operator_url"
  capture_operator_server_api GET "/workflows/${workflow_id}/runs/${run_id}/history" "$result_dir/operator-history-api.json" "$namespace" "$operator_url"

  mkdir -p "$operator_waterline_app" "$composer_home" "$composer_cache"
  if ! (
    cd "$operator_waterline_app" &&
    COMPOSER_HOME="$composer_home" COMPOSER_CACHE_DIR="$composer_cache" \
      composer create-project laravel/laravel . --no-interaction --no-progress --prefer-dist
  ) > "$result_dir/operator-waterline-create-project.log" 2>&1; then
    write_operator_diagnostics_shard_status runner_blocked "The operator diagnostics shard could not create a disposable Laravel app for Waterline; see operator-waterline-create-project.log." waterline_create_project true
    return 0
  fi

  if ! (
    cd "$operator_waterline_app" &&
    COMPOSER_HOME="$composer_home" COMPOSER_CACHE_DIR="$composer_cache" \
      composer require --no-interaction --no-progress --prefer-dist \
        "durable-workflow/waterline:${waterline_version}@beta" \
        "durable-workflow/workflow:${workflow_php_version}@beta" \
        "durable-workflow/sdk:${sdk_php_version}@beta"
  ) > "$result_dir/operator-waterline-composer-require.log" 2>&1; then
    write_operator_diagnostics_shard_status fail "Composer could not install pinned Packagist packages durable-workflow/waterline:${waterline_version}, durable-workflow/workflow:${workflow_php_version}, and durable-workflow/sdk:${sdk_php_version}; see operator-waterline-composer-require.log." waterline_composer_require false
    return 0
  fi

  if ! WATERLINE_APP="$operator_waterline_app" WATERLINE_VERSION="$waterline_version" WORKFLOW_PHP_VERSION="$workflow_php_version" SDK_PHP_VERSION="$sdk_php_version" node <<'NODE' > "$result_dir/operator-waterline-source-policy.log" 2>&1; then
const fs = require('node:fs');
const path = require('node:path');

const appDir = process.env.WATERLINE_APP;
const expectedWaterline = process.env.WATERLINE_VERSION;
const expectedWorkflow = process.env.WORKFLOW_PHP_VERSION;
const expectedSdk = process.env.SDK_PHP_VERSION;
const installedPath = path.join(appDir, 'vendor/composer/installed.json');
const lockPath = path.join(appDir, 'composer.lock');
const localSourcePattern = /(^file:\/\/|^\/|^\.\.?\/|\/workspace\/repos|local[_ -]?(product[_ -]?)?(source|checkout|artifact)|workspace[_ -]?repo|local[_ -]?vendor[_ -]?tree)/i;

function fail(message) {
  console.error(message);
  process.exit(1);
}

function readJson(file) {
  try {
    return JSON.parse(fs.readFileSync(file, 'utf8'));
  } catch (error) {
    fail(`Unable to read ${path.basename(file)}: ${error.message}`);
  }
}

function packagesFromInstalledJson(value) {
  if (Array.isArray(value)) {
    return value;
  }
  if (Array.isArray(value?.packages)) {
    return value.packages;
  }

  return [];
}

function packagesFromLockJson(value) {
  return [
    ...(Array.isArray(value?.packages) ? value.packages : []),
    ...(Array.isArray(value?.['packages-dev']) ? value['packages-dev'] : []),
  ];
}

const installedPackages = packagesFromInstalledJson(readJson(installedPath));
const lockedPackages = packagesFromLockJson(readJson(lockPath));
for (const [name, expected] of [
  ['durable-workflow/waterline', expectedWaterline],
  ['durable-workflow/workflow', expectedWorkflow],
  ['durable-workflow/sdk', expectedSdk],
]) {
  const installedPackage = installedPackages.find((entry) => entry?.name === name);
  const lockedPackage = lockedPackages.find((entry) => entry?.name === name);
  if (!installedPackage || !lockedPackage) {
    fail(`${name} was not installed by Composer.`);
  }
  if (String(installedPackage.version || '') !== expected && String(lockedPackage.version || '') !== expected) {
    fail(`${name} installed version did not match ${expected}.`);
  }
  const installSource = String(installedPackage['installation-source'] || '').toLowerCase();
  if (installSource && installSource !== 'dist') {
    fail(`${name} was installed from ${installSource}, not a Packagist dist artifact.`);
  }
  const distUrl = String(lockedPackage.dist?.url || '');
  if (distUrl === '') {
    fail(`${name} composer.lock metadata did not include a dist URL.`);
  }
  for (const candidate of [
    lockedPackage.dist?.url,
    lockedPackage.source?.url,
  ]) {
    const value = String(candidate || '');
    if (localSourcePattern.test(value)) {
      fail(`${name} resolved from a local artifact source: ${value}`);
    }
  }
}
NODE
    write_operator_diagnostics_shard_status fail "The operator diagnostics shard resolved Waterline or workflow from a non-published artifact source; see operator-waterline-source-policy.log." waterline_source_policy false
    return 0
  fi

  if ! (
    cd "$operator_waterline_app" &&
    php artisan key:generate --force &&
    php artisan list --raw
  ) > "$result_dir/operator-waterline-artisan-list.log" 2>&1; then
    write_operator_diagnostics_shard_status fail "The Composer-installed Waterline package could not boot its Laravel command surface; see operator-waterline-artisan-list.log." waterline_artisan_list false
    return 0
  fi

  if ! grep -q '^waterline:workflow-updates-conformance' "$result_dir/operator-waterline-artisan-list.log"; then
    write_operator_diagnostics_shard_status fail "The Composer-installed Waterline package does not expose waterline:workflow-updates-conformance." waterline_command_missing false
    return 0
  fi

  set +e
  (
    cd "$operator_waterline_app" &&
    APP_ENV=production \
    APP_DEBUG=false \
    APP_KEY="${APP_KEY:-base64:V09SS0ZMT1ctVVBEQVRFUy1XQVRFUkxJTkUtS0VZMzI=}" \
    DB_CONNECTION=sqlite \
    DB_DATABASE="$operator_db" \
    QUEUE_CONNECTION=database \
    CACHE_STORE=array \
    SESSION_DRIVER=array \
    php artisan waterline:workflow-updates-conformance \
      --output="$waterline_report" \
      --run-id="operator-diagnostics-${run_id}" \
      --instance-id="$workflow_id" \
      --workflow-run-id="$run_id" \
      --artifact-version="server=${DW_SERVER_VERSION:-}" \
      --artifact-version="cli=${cli_version}" \
      --artifact-version="workflow-php=${workflow_php_version}" \
      --artifact-version="sdk-python=${DW_PYTHON_SDK_VERSION:-}" \
      --artifact-version="waterline=${waterline_version}" \
      --artifact-source=server=docker_image \
      --artifact-source="cli=${operator_cli_installer_source}" \
      --artifact-source=workflow-php=packagist_package \
      --artifact-source=sdk-python=pypi_package \
      --artifact-source=waterline=packagist_package
  ) > "$result_dir/operator-waterline-conformance-command.log" 2>&1
  local waterline_command_status=$?
  set -e

  if [[ ! -s "$waterline_report" ]]; then
    write_operator_diagnostics_shard_status fail "The Composer-installed Waterline workflow update command did not emit a report; see operator-waterline-conformance-command.log." waterline_conformance_command false
    return 0
  fi

  materialize_operator_diagnostics_report "$runtime_path" "$waterline_report"
  if [[ "$waterline_command_status" -ne 0 ]]; then
    printf 'Waterline workflow update diagnostics shard exited with status %s; imported its emitted report.\n' "$waterline_command_status" >> "$result_dir/operator-waterline-conformance-command.log"
  fi
}

if should_run_operator_diagnostics_shard; then
  run_operator_diagnostics_shard
fi

RESULT_DIR="$result_dir" \
STARTED_AT="$started_at" \
REPO_ROOT="$repo_root" \
DW_WORKFLOW_UPDATES_EVIDENCE="${DW_WORKFLOW_UPDATES_EVIDENCE:-}" \
DW_WORKFLOW_UPDATES_EVIDENCE_PATH="${DW_WORKFLOW_UPDATES_EVIDENCE_PATH:-}" \
DW_WORKFLOW_UPDATES_PHP_EVIDENCE="${DW_WORKFLOW_UPDATES_PHP_EVIDENCE:-}" \
DW_WORKFLOW_UPDATES_PHP_EVIDENCE_PATH="${DW_WORKFLOW_UPDATES_PHP_EVIDENCE_PATH:-}" \
DW_WORKFLOW_UPDATES_PYTHON_EVIDENCE="${DW_WORKFLOW_UPDATES_PYTHON_EVIDENCE:-}" \
DW_WORKFLOW_UPDATES_PYTHON_EVIDENCE_PATH="${DW_WORKFLOW_UPDATES_PYTHON_EVIDENCE_PATH:-}" \
DW_WORKFLOW_UPDATES_OPERATOR_DIAGNOSTICS_EVIDENCE="${DW_WORKFLOW_UPDATES_OPERATOR_DIAGNOSTICS_EVIDENCE:-}" \
DW_WORKFLOW_UPDATES_OPERATOR_DIAGNOSTICS_EVIDENCE_PATH="${DW_WORKFLOW_UPDATES_OPERATOR_DIAGNOSTICS_EVIDENCE_PATH:-}" \
node <<'NODE'
const fs = require('node:fs');
const path = require('node:path');

const resultDir = process.env.RESULT_DIR;
const startedAt = process.env.STARTED_AT;
const repoRoot = process.env.REPO_ROOT || '';
const generatedAt = new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
const finishedAt = generatedAt;
const focusedEvidenceFile = 'workflow-updates-focused-evidence.json';
const phpSidecarEvidenceFile = 'sdk-php-workflow-updates-evidence.json';
const pythonSidecarEvidenceFile = 'python-sdk-workflow-updates-evidence.json';
const operatorDiagnosticsEvidenceFile = 'workflow-updates-operator-diagnostics-evidence.json';
const focusedEvidencePath = path.join(resultDir, focusedEvidenceFile);
const phpSidecarEvidencePath = path.join(resultDir, phpSidecarEvidenceFile);
const pythonSidecarEvidencePath = path.join(resultDir, pythonSidecarEvidenceFile);
const operatorDiagnosticsEvidencePath = path.join(resultDir, operatorDiagnosticsEvidenceFile);
const phpSidecarSchema = 'durable-workflow.v2.workflow-updates.php-package-sidecar';
const phpSidecarScenarioId = 'php_client_worker_update_surface';
const pythonSidecarSchema = 'durable-workflow.v2.workflow-updates.python-sdk-sidecar';
const pythonSidecarScenarioId = 'python_client_worker_update_surface';
const operatorDiagnosticsSchema = 'durable-workflow.v2.workflow-updates.operator-diagnostics-sidecar';
const operatorDiagnosticsScenarioId = 'operator_diagnostics_surfaces';

function env(name) {
  return (process.env[name] || '').trim();
}

function versionFromImage(image) {
  if (!image || image.includes('@sha256:')) {
    return '';
  }
  const match = image.match(/:([^/:]+)$/);
  return match ? match[1] : '';
}

function unresolved(value) {
  return value || 'unresolved';
}

function readJsonIfExists(file) {
  try {
    if (file && fs.existsSync(file) && fs.statSync(file).size > 0) {
      return JSON.parse(fs.readFileSync(file, 'utf8'));
    }
  } catch (error) {
    return null;
  }

  return null;
}

function writeJson(file, payload) {
  fs.writeFileSync(path.join(resultDir, file), `${JSON.stringify(payload, null, 2)}\n`);
}

function materializeFocusedEvidence(evidence) {
  writeJson(focusedEvidenceFile, evidence);

  return evidence;
}

function materializePythonSidecarEvidence(evidence) {
  writeJson(pythonSidecarEvidenceFile, evidence);

  return evidence;
}

function materializePhpSidecarEvidence(evidence) {
  writeJson(phpSidecarEvidenceFile, evidence);

  return evidence;
}

function materializeOperatorDiagnosticsEvidence(evidence) {
  writeJson(operatorDiagnosticsEvidenceFile, evidence);

  return evidence;
}

function readFocusedEvidence() {
  const inline = env('DW_WORKFLOW_UPDATES_EVIDENCE');
  if (inline) {
    return materializeFocusedEvidence(JSON.parse(inline));
  }

  const configuredPath = env('DW_WORKFLOW_UPDATES_EVIDENCE_PATH');
  const candidates = [];
  if (configuredPath) {
    candidates.push(configuredPath);
  }
  candidates.push(path.join(resultDir, 'workflow-updates-focused-evidence.json'));

  for (const candidate of candidates) {
    if (candidate && fs.existsSync(candidate) && fs.statSync(candidate).size > 0) {
      const evidence = JSON.parse(fs.readFileSync(candidate, 'utf8'));
      if (path.resolve(candidate) !== path.resolve(focusedEvidencePath)) {
        materializeFocusedEvidence(evidence);
      }

      return evidence;
    }
  }

  return null;
}

function readPhpSidecarEvidence() {
  const inline = env('DW_WORKFLOW_UPDATES_PHP_EVIDENCE');
  if (inline) {
    return materializePhpSidecarEvidence(JSON.parse(inline));
  }

  const configuredPath = env('DW_WORKFLOW_UPDATES_PHP_EVIDENCE_PATH');
  const candidates = [];
  if (configuredPath) {
    candidates.push(configuredPath);
  }
  candidates.push(path.join(resultDir, phpSidecarEvidenceFile));

  for (const candidate of candidates) {
    if (candidate && fs.existsSync(candidate) && fs.statSync(candidate).size > 0) {
      const evidence = JSON.parse(fs.readFileSync(candidate, 'utf8'));
      if (path.resolve(candidate) !== path.resolve(phpSidecarEvidencePath)) {
        materializePhpSidecarEvidence(evidence);
      }

      return evidence;
    }
  }

  return null;
}

function readPythonSidecarEvidence() {
  const inline = env('DW_WORKFLOW_UPDATES_PYTHON_EVIDENCE');
  if (inline) {
    return materializePythonSidecarEvidence(JSON.parse(inline));
  }

  const configuredPath = env('DW_WORKFLOW_UPDATES_PYTHON_EVIDENCE_PATH');
  const candidates = [];
  if (configuredPath) {
    candidates.push(configuredPath);
  }
  candidates.push(path.join(resultDir, pythonSidecarEvidenceFile));

  for (const candidate of candidates) {
    if (candidate && fs.existsSync(candidate) && fs.statSync(candidate).size > 0) {
      const evidence = JSON.parse(fs.readFileSync(candidate, 'utf8'));
      if (path.resolve(candidate) !== path.resolve(pythonSidecarEvidencePath)) {
        materializePythonSidecarEvidence(evidence);
      }

      return evidence;
    }
  }

  return null;
}

function readOperatorDiagnosticsEvidence() {
  const inline = env('DW_WORKFLOW_UPDATES_OPERATOR_DIAGNOSTICS_EVIDENCE');
  if (inline) {
    return materializeOperatorDiagnosticsEvidence(JSON.parse(inline));
  }

  const configuredPath = env('DW_WORKFLOW_UPDATES_OPERATOR_DIAGNOSTICS_EVIDENCE_PATH');
  const candidates = [];
  if (configuredPath) {
    candidates.push(configuredPath);
  }
  candidates.push(path.join(resultDir, operatorDiagnosticsEvidenceFile));

  for (const candidate of candidates) {
    if (candidate && fs.existsSync(candidate) && fs.statSync(candidate).size > 0) {
      const evidence = JSON.parse(fs.readFileSync(candidate, 'utf8'));
      if (path.resolve(candidate) !== path.resolve(operatorDiagnosticsEvidencePath)) {
        materializeOperatorDiagnosticsEvidence(evidence);
      }

      return evidence;
    }
  }

  return null;
}

function isPhpSidecarEvidence(value) {
  return value?.schema === phpSidecarSchema;
}

function isPythonSidecarEvidence(value) {
  return value?.schema === pythonSidecarSchema;
}

function isOperatorDiagnosticsEvidence(value) {
  return value?.schema === operatorDiagnosticsSchema;
}

function uniqueFindings(findings) {
  const seen = new Set();
  const result = [];
  for (const finding of findings) {
    const key = typeof finding === 'string'
      ? finding
      : `${finding.finding_id || ''}:${finding.summary || JSON.stringify(finding)}`;
    if (seen.has(key)) {
      continue;
    }
    seen.add(key);
    result.push(finding);
  }
  return result;
}

function coverageFinding(id, scenarioId, summary, acceptance, owningSurface = 'conformance_harness') {
  return {
    finding_id: id,
    finding_type: 'conformance_runner_coverage_gap',
    classification: 'coverage-gap',
    scenario_id: scenarioId,
    owning_surface: owningSurface,
    summary,
    next_acceptance_criterion: acceptance,
  };
}

function truthyEvidenceFlag(value) {
  if (value === true || value === 1) {
    return true;
  }
  if (typeof value === 'string') {
    return ['1', 'true', 'yes', 'y', 'on'].includes(value.trim().toLowerCase());
  }

  return false;
}

function explicitFalse(value) {
  if (value === false || value === 0) {
    return true;
  }
  if (typeof value === 'string') {
    return ['0', 'false', 'no', 'n', 'off'].includes(value.trim().toLowerCase());
  }

  return false;
}

function objectValue(value) {
  return value && typeof value === 'object' && !Array.isArray(value) ? value : {};
}

function arrayOfStrings(value) {
  return Array.isArray(value)
    ? value.filter((item) => typeof item === 'string' && item.trim() !== '').map((item) => item.trim())
    : [];
}

function stringValue(value) {
  return typeof value === 'string' ? value.trim() : '';
}

function sourcePolicyFor(value) {
  return objectValue(value?.source_policy ?? value?.sourcePolicy);
}

function observedOutputsFor(value) {
  const observedOutputs = objectValue(value?.observed_outputs ?? value?.observedOutputs);
  if (Object.keys(observedOutputs).length > 0) {
    return observedOutputs;
  }

  return objectValue(value?.evidence);
}

function artifactSourcesFor(value) {
  const observedOutputs = observedOutputsFor(value);
  const sourcePolicy = sourcePolicyFor(value);

  return objectValue(
    value?.artifact_sources
      ?? value?.artifactSources
      ?? observedOutputs.artifact_sources
      ?? observedOutputs.artifactSources
      ?? sourcePolicy.artifact_sources
      ?? sourcePolicy.artifactSources,
  );
}

function localSourceFieldValues(value) {
  const observedOutputs = observedOutputsFor(value);
  const sourcePolicy = sourcePolicyFor(value);
  const fields = [
    value?.artifact_source,
    value?.artifactSource,
    value?.sdk_php_artifact_source,
    value?.workflowPhpArtifactSource,
    value?.package_artifact_source,
    value?.packageArtifactSource,
    value?.sdk_python_artifact_source,
    value?.sdkPythonArtifactSource,
    value?.cli_artifact_source,
    value?.cliArtifactSource,
    value?.waterline_artifact_source,
    value?.waterlineArtifactSource,
    observedOutputs.artifact_source,
    observedOutputs.artifactSource,
    observedOutputs.sdk_php_artifact_source,
    observedOutputs.workflowPhpArtifactSource,
    observedOutputs.package_artifact_source,
    observedOutputs.packageArtifactSource,
    observedOutputs.sdk_python_artifact_source,
    observedOutputs.sdkPythonArtifactSource,
    observedOutputs.cli_artifact_source,
    observedOutputs.cliArtifactSource,
    observedOutputs.waterline_artifact_source,
    observedOutputs.waterlineArtifactSource,
    observedOutputs.cli_fields?.cli_artifact_source,
    observedOutputs.cli_fields?.cliArtifactSource,
    observedOutputs.waterline_fields?.waterline_artifact_source,
    observedOutputs.waterline_fields?.waterlineArtifactSource,
    sourcePolicy.artifact_source,
    sourcePolicy.artifactSource,
    sourcePolicy.sdk_php_artifact_source,
    sourcePolicy.workflowPhpArtifactSource,
    sourcePolicy.package_artifact_source,
    sourcePolicy.packageArtifactSource,
    sourcePolicy.sdk_python_artifact_source,
    sourcePolicy.sdkPythonArtifactSource,
    sourcePolicy.cli_artifact_source,
    sourcePolicy.cliArtifactSource,
    sourcePolicy.waterline_artifact_source,
    sourcePolicy.waterlineArtifactSource,
  ];

  return fields.filter((candidate) => typeof candidate === 'string' && candidate.trim() !== '');
}

function packageImportPathValues(value) {
  const observedOutputs = observedOutputsFor(value);
  const fields = [
    value?.package_import_path,
    value?.packageImportPath,
    value?.python_package_import_path,
    value?.pythonPackageImportPath,
    observedOutputs.package_import_path,
    observedOutputs.packageImportPath,
    observedOutputs.python_package_import_path,
    observedOutputs.pythonPackageImportPath,
  ];

  return fields.filter((candidate) => typeof candidate === 'string' && candidate.trim() !== '');
}

function localProductSourceCheckoutsUsed(value) {
  const observedOutputs = observedOutputsFor(value);
  const sourcePolicy = sourcePolicyFor(value);

  return truthyEvidenceFlag(value?.local_product_source_checkouts_used)
    || truthyEvidenceFlag(value?.localProductSourceCheckoutsUsed)
    || truthyEvidenceFlag(value?.local_product_sources_used)
    || truthyEvidenceFlag(value?.localProductSourcesUsed)
    || truthyEvidenceFlag(observedOutputs.local_product_source_checkouts_used)
    || truthyEvidenceFlag(observedOutputs.localProductSourceCheckoutsUsed)
    || truthyEvidenceFlag(observedOutputs.local_product_sources_used)
    || truthyEvidenceFlag(observedOutputs.localProductSourcesUsed)
    || truthyEvidenceFlag(sourcePolicy.local_product_source_checkouts_used)
    || truthyEvidenceFlag(sourcePolicy.localProductSourceCheckoutsUsed)
    || truthyEvidenceFlag(sourcePolicy.local_product_sources_used)
    || truthyEvidenceFlag(sourcePolicy.localProductSourcesUsed);
}

function localProductSourceExplicitFalse(value) {
  const observedOutputs = observedOutputsFor(value);
  const rowIsExplicitFalse = explicitFalse(value?.local_product_source_checkouts_used)
    || explicitFalse(value?.localProductSourceCheckoutsUsed);
  const observedValueSupplied = Object.prototype.hasOwnProperty.call(observedOutputs, 'local_product_source_checkouts_used')
    || Object.prototype.hasOwnProperty.call(observedOutputs, 'localProductSourceCheckoutsUsed');
  const observedIsExplicitFalse = !observedValueSupplied
    || explicitFalse(observedOutputs.local_product_source_checkouts_used)
    || explicitFalse(observedOutputs.localProductSourceCheckoutsUsed);

  return rowIsExplicitFalse && observedIsExplicitFalse;
}

function publishedArtifactCellExecuted(value) {
  const observedOutputs = observedOutputsFor(value);

  return truthyEvidenceFlag(value?.published_artifact_cell_executed)
    || truthyEvidenceFlag(value?.publishedArtifactCellExecuted)
    || truthyEvidenceFlag(observedOutputs.published_artifact_cell_executed)
    || truthyEvidenceFlag(observedOutputs.publishedArtifactCellExecuted);
}

function sourcePolicyFinding(scenarioId, summary) {
  return coverageFinding(
    `workflow-updates-${scenarioId.replace(/_/g, '-')}-source-policy-gap`,
    scenarioId,
    summary,
    'Rerun the workflow update cell against pinned published artifacts and record published_artifact_cell_executed=true with local_product_source_checkouts_used=false.',
  );
}

function requiredEvidenceFinding(scenarioId, missingFields) {
  return coverageFinding(
    `workflow-updates-${scenarioId.replace(/_/g, '-')}-required-evidence-gap`,
    scenarioId,
    `The host evidence for ${scenarioId} claimed pass but omitted required observed outputs: ${missingFields.join(', ')}.`,
    `Attach ${missingFields.join(', ')} observations required by static/platform-conformance/workflow-update-runtime-scenarios.json before recording ${scenarioId} as passing.`,
  );
}

function artifactPrerequisiteFinding(scenarioId, failures) {
  const finding = coverageFinding(
    `workflow-updates-${scenarioId.replace(/_/g, '-')}-artifact-prerequisite-gap`,
    scenarioId,
    `The host evidence for ${scenarioId} claimed pass with unresolved published artifact prerequisites: ${failures.map((failure) => `${failure.artifact}:${failure.code}`).join(', ')}.`,
    'Record concrete published artifact versions and non-placeholder artifact sources for the server, CLI, Python SDK, PHP SDK package, and Waterline before recording workflow update cells as passing.',
  );
  finding.artifact_prerequisite_failures = failures;

  return finding;
}

function operatorDiagnosticsEvidenceFinding(failures) {
  const finding = coverageFinding(
    'workflow-updates-operator-diagnostics-surfaces-required-evidence-gap',
    operatorDiagnosticsScenarioId,
    `The operator diagnostics evidence claimed pass without proving CLI and Waterline diagnostics for every required update path: ${failures.map((failure) => `${failure.surface}.${failure.state}:${failure.missing_fields.join('|')}`).join(', ')}.`,
    'Record workflow:update --json and Waterline selected-run detail/history-export diagnostics with request ids, state/outcome/reason, payload/result/error details, and history references for accepted, completed, failed, and refused update paths.',
    'waterline',
  );
  finding.operator_diagnostics_failures = failures;

  return finding;
}

function operatorSurfaceStates(value) {
  return objectValue(
    value?.operator_surface_matrix?.states
      ?? value?.diagnostic_transition_matrix?.states
      ?? value?.states,
  );
}

function normalizedOperatorState(value) {
  return stringValue(value)
    .replace(/([a-z])([A-Z])/g, '$1_$2')
    .replace(/[\s-]+/g, '_')
    .toLowerCase();
}

function operatorStateMatchesExpected(value, expectedState) {
  return [
    value?.state,
    value?.status,
    value?.state_label,
    value?.stateLabel,
  ].map(normalizedOperatorState).includes(expectedState);
}

function operatorStateHasOutcomeOrReason(value) {
  return value?.outcome_or_reason_visible === true
    || value?.outcomeOrReasonVisible === true
    || stringValue(value?.outcome) !== ''
    || stringValue(value?.reason) !== ''
    || stringValue(value?.rejection_reason) !== ''
    || stringValue(value?.rejectionReason) !== '';
}

function operatorSurfaceFailures(surface, fields) {
  const states = operatorSurfaceStates(fields);
  const failures = [];
  for (const state of ['accepted', 'completed', 'failed', 'refused']) {
    const evidence = objectValue(states[state]);
    const missing = [];
    if (evidence.present !== true) {
      missing.push('present');
    }
    if (evidence.request_identifiers_visible !== true && evidence.requestIdentifiersVisible !== true) {
      missing.push('request_identifiers_visible');
    }
    if (!operatorStateMatchesExpected(evidence, state)) {
      missing.push('expected_state');
    }
    if (!operatorStateHasOutcomeOrReason(evidence)) {
      missing.push('outcome_or_reason_visible');
    }
    if (evidence.payload_visible !== true && evidence.payloadVisible !== true) {
      missing.push('payload_visible');
    }
    if (state === 'completed' && evidence.result_visible !== true && evidence.resultVisible !== true) {
      missing.push('result_visible');
    }
    if (['failed', 'refused'].includes(state) && evidence.error_visible !== true && evidence.errorVisible !== true) {
      missing.push('error_visible');
    }
    if (evidence.history_references_visible !== true && evidence.historyReferencesVisible !== true) {
      missing.push('history_references_visible');
    }
    if (surface === 'waterline' && evidence.history_export_references_visible !== true && evidence.historyExportReferencesVisible !== true) {
      missing.push('history_export_references_visible');
    }
    if (missing.length > 0) {
      failures.push({ surface, state, missing_fields: missing });
    }
  }

  return failures;
}

function operatorDiagnosticsFailures(observedOutputs) {
  const failures = [];
  const cliFields = objectValue(observedOutputs.cli_fields ?? observedOutputs.cliFields);
  const waterlineFields = objectValue(observedOutputs.waterline_fields ?? observedOutputs.waterlineFields);
  const apiFields = objectValue(observedOutputs.api_fields ?? observedOutputs.apiFields);
  const historyFields = objectValue(observedOutputs.history_fields ?? observedOutputs.historyFields);
  const matrix = objectValue(observedOutputs.diagnostic_transition_matrix ?? observedOutputs.diagnosticTransitionMatrix);

  if (stringValue(observedOutputs.workflow_id ?? observedOutputs.workflowId) === '') {
    failures.push({ surface: 'operator', state: '*', missing_fields: ['workflow_id'] });
  }
  if (stringValue(observedOutputs.run_id ?? observedOutputs.runId) === '') {
    failures.push({ surface: 'operator', state: '*', missing_fields: ['run_id'] });
  }
  if (Object.keys(apiFields).length === 0) {
    failures.push({ surface: 'api', state: '*', missing_fields: ['api_fields'] });
  }
  if (Object.keys(historyFields).length === 0) {
    failures.push({ surface: 'history', state: '*', missing_fields: ['history_fields'] });
  }
  if (Object.keys(matrix).length === 0) {
    failures.push({ surface: 'operator', state: '*', missing_fields: ['diagnostic_transition_matrix'] });
  }
  if (Object.keys(cliFields).length === 0) {
    failures.push({ surface: 'cli', state: '*', missing_fields: ['cli_fields'] });
  } else {
    failures.push(...operatorSurfaceFailures('cli', cliFields));
  }
  if (Object.keys(waterlineFields).length === 0) {
    failures.push({ surface: 'waterline', state: '*', missing_fields: ['waterline_fields'] });
  } else {
    failures.push(...operatorSurfaceFailures('waterline', waterlineFields));
  }

  return failures;
}

function runnerBlockedFinding(scenarioId) {
  return {
    finding_id: `workflow-updates-${scenarioId.replace(/_/g, '-')}-runner-blocked-evidence`,
    finding_type: 'conformance_runner_blocked',
    classification: 'runner-blocked',
    scenario_id: scenarioId,
    owning_surface: 'conformance_harness',
    summary: 'Imported workflow updates evidence reported runner_blocked=true, so it cannot count as passing product evidence.',
    next_acceptance_criterion: 'Rerun the focused workflow updates probe in a host environment that reaches the published artifacts and records runner_blocked=false.',
  };
}

function scenarioResult(scenarioId, status, classification, finding, observedOutputs = {}) {
  return {
    scenario_id: scenarioId,
    status,
    classification,
    published_artifact_cell_executed: false,
    local_product_source_checkouts_used: false,
    observed_outputs: {
      ...observedOutputs,
      published_artifact_cell_executed: false,
      local_product_source_checkouts_used: false,
    },
    linked_findings: [finding],
  };
}

function hasOwnField(value, field) {
  return value && typeof value === 'object' && !Array.isArray(value)
    && Object.prototype.hasOwnProperty.call(value, field);
}

function requiredFieldsForScenario(scenarioId) {
  return arrayOfStrings(scenarioRequirements?.[scenarioId]?.required_fields);
}

function missingRequiredFieldsForScenario(scenarioId, row, observedOutputs) {
  return requiredFieldsForScenario(scenarioId).filter((field) => !hasOwnField(observedOutputs, field) && !hasOwnField(row, field));
}

const PLACEHOLDER_ARTIFACT_PATTERN = /(<[^>]+>|\$\{[^}]+}|{{[^}]+}}|(^|[^a-z0-9])(latest|current|head|main|master|unresolved|placeholder|not[-_\s]?exercised|todo|tbd|unknown|null|none)([^a-z0-9]|$))/i;

function placeholderArtifactValue(value) {
  const string = stringValue(value);

  return string === '' || PLACEHOLDER_ARTIFACT_PATTERN.test(string);
}

function sourceUsesForbiddenToken(source) {
  const normalized = stringValue(source).replace(/\\/g, '/').toLowerCase();
  if (normalized === '') {
    return false;
  }

  return sourceContainsForbiddenToken(normalized)
    || normalized.startsWith('/')
    || normalized.startsWith('./')
    || normalized.startsWith('../');
}

function sourceContainsForbiddenToken(normalized) {
  const builtInForbiddenTokens = [
    'local_product_source_checkout',
    'workspace_repo_as_artifact_under_test',
    'local_checkout_artifact',
    'local_source_checkout',
    'workspace_repo',
    'branch_source',
    'local_vendor_tree',
    '/workspace/repos',
    'file://',
    'git+file://',
    '${home}/repos',
    '~/repos',
  ];

  return builtInForbiddenTokens.some((token) => normalized.includes(token))
    || forbiddenArtifactSourceTokens.some((token) => normalized.includes(token.toLowerCase()))
    || /workspace[._-]*hq/.test(normalized)
    || /(^|[/:_\s-])(local|workspace)[\s_-]*(repo|worktree|checkout|source)([/:_\s-]|$)/.test(normalized)
    || /(^|[/:_\s-])(repo|worktree)[\s_-]*(checkout|source)([/:_\s-]|$)/.test(normalized)
    || normalized.includes('/repos/server')
    || normalized.includes('/repos/cli')
    || normalized.includes('/repos/workflow')
    || normalized.includes('/repos/waterline')
    || normalized.includes('/repos/sdk-python');
}

function sourceLooksLikeInstalledPythonPackageImportPath(normalized) {
  return normalized.startsWith('/')
    && /\/(site|dist)-packages\/durable_workflow(\/|$)/.test(normalized);
}

function packageImportPathUsesForbiddenToken(source) {
  const normalized = stringValue(source).replace(/\\/g, '/').toLowerCase();
  if (normalized === '') {
    return false;
  }

  if (sourceContainsForbiddenToken(normalized)) {
    return true;
  }

  if (sourceLooksLikeInstalledPythonPackageImportPath(normalized)) {
    return false;
  }

  return normalized.startsWith('/')
    || normalized.startsWith('./')
    || normalized.startsWith('../');
}

function placeholderArtifactSource(value) {
  const string = stringValue(value);

  return string === '' || PLACEHOLDER_ARTIFACT_PATTERN.test(string) || sourceUsesForbiddenToken(string);
}

function localArtifactSourceReported(value) {
  const observedOutputs = observedOutputsFor(value);
  const sourcePolicy = sourcePolicyFor(value);

  return Object.values(artifactSourcesFor(value)).some((source) => sourceUsesForbiddenToken(source))
    || localSourceFieldValues(value).some((source) => sourceUsesForbiddenToken(source))
    || packageImportPathValues(value).some((source) => packageImportPathUsesForbiddenToken(source))
    || sourceUsesForbiddenToken(value?.artifact_source)
    || sourceUsesForbiddenToken(value?.artifactSource)
    || sourceUsesForbiddenToken(observedOutputs.artifact_source)
    || sourceUsesForbiddenToken(observedOutputs.artifactSource)
    || sourceUsesForbiddenToken(sourcePolicy.artifact_source)
    || sourceUsesForbiddenToken(sourcePolicy.artifactSource);
}

function isExactSemverRelease(value) {
  return /^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:-(?:0|[1-9]\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9]\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*))*)?$/.test(stringValue(value));
}

function artifactFailureCode(value, field, codePrefix, artifact) {
  const string = stringValue(value);
  if (field === 'artifact_sources') {
    if (string === '') {
      return `${codePrefix}_artifact_source`;
    }
    if (sourceUsesForbiddenToken(string)) {
      return 'forbidden_published_artifact_source';
    }

    return PLACEHOLDER_ARTIFACT_PATTERN.test(string) ? `${codePrefix}_artifact_source` : null;
  }

  if (placeholderArtifactValue(string)) {
    return `${codePrefix}_artifact_version`;
  }
  if (artifact === 'server' && !isExactSemverRelease(string)) {
    return `${codePrefix}_server_artifact_version`;
  }
  return null;
}

function artifactEntries(map, artifact) {
  const values = objectValue(map);
  const entries = [];
  for (const key of artifactAliases[artifact] || [artifact]) {
    if (Object.prototype.hasOwnProperty.call(values, key)) {
      entries.push({ key, value: values[key] });
    }
  }

  return entries;
}

function artifactMapFailures(map, field, codePrefix) {
  const failures = [];
  const values = objectValue(map);

  for (const artifact of requiredArtifacts) {
    const entries = artifactEntries(values, artifact);
    if (entries.length === 0) {
      failures.push({
        artifact,
        field,
        code: `${codePrefix}_${field === 'artifact_sources' ? 'artifact_source' : 'artifact_version'}`,
        value: '',
      });
      continue;
    }

    for (const entry of entries) {
      const code = artifactFailureCode(entry.value, field, codePrefix, artifact);
      if (code) {
        failures.push({
          artifact,
          field,
          code,
          value: stringValue(entry.value),
          key: entry.key,
        });
      }
    }
  }

  return failures;
}

function presentArtifactMapFailures(row, observedOutputs, field, codePrefix, sourceEvidence = null) {
  const failures = [];
  const sourceEvidenceOutputs = observedOutputsFor(sourceEvidence);
  for (const source of [
    row?.[field],
    observedOutputs?.[field],
    sourceEvidence?.[field],
    sourceEvidenceOutputs?.[field],
  ]) {
    if (source && typeof source === 'object' && !Array.isArray(source)) {
      failures.push(...artifactMapFailures(source, field, codePrefix));
    }
  }

  return failures;
}

function artifactPrerequisiteFailuresFor(row, observedOutputs, sourceEvidence = null) {
  return uniqueArtifactFailures([
    ...artifactMapFailures(artifactVersions, 'artifact_versions', 'placeholder'),
    ...artifactMapFailures(artifactVersions, 'published_artifact_versions', 'placeholder'),
    ...artifactMapFailures(publishedArtifactVersions, 'published_artifact_versions', 'placeholder'),
    ...artifactMapFailures(artifactSources, 'artifact_sources', 'placeholder'),
    ...presentArtifactMapFailures(row, observedOutputs, 'artifact_versions', 'evidence_placeholder', sourceEvidence),
    ...presentArtifactMapFailures(row, observedOutputs, 'published_artifact_versions', 'evidence_placeholder', sourceEvidence),
    ...presentArtifactMapFailures(row, observedOutputs, 'artifact_sources', 'evidence_placeholder', sourceEvidence),
  ]);
}

function uniqueArtifactFailures(failures) {
  const seen = new Set();

  return failures.filter((failure) => {
    const key = JSON.stringify([
      failure.artifact || '',
      failure.field || '',
      failure.code || '',
      failure.key || '',
      failure.value || '',
    ]);
    if (seen.has(key)) {
      return false;
    }
    seen.add(key);

    return true;
  });
}

function normalizeScenarioResult(scenarioId, row, sourceEvidence = null) {
  const status = typeof row?.status === 'string' ? row.status : 'not_covered';
  const allowed = new Set(['pass', 'fail', 'unsupported', 'not_covered', 'runner_blocked']);
  let normalizedStatus = allowed.has(status) ? status : 'fail';
  let classification = typeof row?.classification === 'string'
    ? row.classification
    : (normalizedStatus === 'pass' ? 'product-evidence' : 'coverage-gap');
  let observedOutputs = observedOutputsFor(row);
  const sourceEvidenceLocalSourceUsed = sourceEvidence
    ? localProductSourceCheckoutsUsed(sourceEvidence) || localArtifactSourceReported(sourceEvidence)
    : false;
  const localSourceUsed = localProductSourceCheckoutsUsed(row)
    || localArtifactSourceReported(row)
    || sourceEvidenceLocalSourceUsed;
  const localSourceExplicitFalse = localProductSourceExplicitFalse(row);
  const cleanPublishedArtifactExecution = publishedArtifactCellExecuted(row)
    && localSourceExplicitFalse
    && !localSourceUsed;
  const linkedFindings = Array.isArray(row?.linked_findings) ? [...row.linked_findings] : [];
  const sourceEvidenceRunnerBlocked = sourceEvidence
    ? truthyEvidenceFlag(sourceEvidence.runner_blocked) || truthyEvidenceFlag(sourceEvidence.runnerBlocked)
    : false;

  if (normalizedStatus === 'pass' && sourceEvidenceRunnerBlocked) {
    normalizedStatus = 'runner_blocked';
    classification = 'runner-blocked';
    linkedFindings.push(runnerBlockedFinding(scenarioId));
    observedOutputs = {
      ...observedOutputs,
      evidence_runner_blocked: true,
    };
  }

  if (normalizedStatus === 'pass' && !cleanPublishedArtifactExecution) {
    const missing = [];
    if (!publishedArtifactCellExecuted(row)) {
      missing.push('published_artifact_cell_executed=true');
    }
    if (!localSourceExplicitFalse || localSourceUsed) {
      missing.push('local_product_source_checkouts_used=false');
    }

    normalizedStatus = 'not_covered';
    classification = 'coverage-gap';
    linkedFindings.push(sourcePolicyFinding(
      scenarioId,
      `The host evidence for ${scenarioId} claimed pass without clean published-artifact execution proof: ${missing.join(', ')}.`,
    ));
  }

  const artifactPrerequisiteFailures = normalizedStatus === 'pass'
    ? artifactPrerequisiteFailuresFor(row, observedOutputs, sourceEvidence)
    : [];
  if (normalizedStatus === 'pass' && artifactPrerequisiteFailures.length > 0) {
    normalizedStatus = 'not_covered';
    classification = 'coverage-gap';
    observedOutputs = {
      ...observedOutputs,
      artifact_prerequisite_failures: artifactPrerequisiteFailures,
    };
    linkedFindings.push(artifactPrerequisiteFinding(scenarioId, artifactPrerequisiteFailures));
  }

  const missingRequiredFields = normalizedStatus === 'pass'
    ? missingRequiredFieldsForScenario(scenarioId, row, observedOutputs)
    : [];
  if (normalizedStatus === 'pass' && missingRequiredFields.length > 0) {
    normalizedStatus = 'not_covered';
    classification = 'coverage-gap';
    observedOutputs = {
      ...observedOutputs,
      missing_required_fields: missingRequiredFields,
    };
    linkedFindings.push(requiredEvidenceFinding(scenarioId, missingRequiredFields));
  }

  const operatorDiagnosticsMissing = normalizedStatus === 'pass' && scenarioId === operatorDiagnosticsScenarioId
    ? operatorDiagnosticsFailures(observedOutputs)
    : [];
  if (normalizedStatus === 'pass' && operatorDiagnosticsMissing.length > 0) {
    normalizedStatus = 'not_covered';
    classification = 'coverage-gap';
    observedOutputs = {
      ...observedOutputs,
      operator_diagnostics_failures: operatorDiagnosticsMissing,
    };
    linkedFindings.push(operatorDiagnosticsEvidenceFinding(operatorDiagnosticsMissing));
  }

  return {
    scenario_id: typeof row?.scenario_id === 'string' ? row.scenario_id : scenarioId,
    status: normalizedStatus,
    classification,
    published_artifact_cell_executed: cleanPublishedArtifactExecution,
    local_product_source_checkouts_used: localSourceUsed,
    observed_outputs: {
      ...observedOutputs,
      published_artifact_cell_executed: cleanPublishedArtifactExecution,
      local_product_source_checkouts_used: localSourceUsed,
    },
    linked_findings: linkedFindings,
  };
}

const serverImage = env('DW_SERVER_IMAGE') || '';
const serverVersion = unresolved(env('DW_SERVER_VERSION') || versionFromImage(serverImage));
const cliVersion = unresolved(env('DW_CLI_VERSION'));
const pythonVersion = unresolved(env('DW_PYTHON_SDK_VERSION'));
const phpSdkVersion = unresolved(env('DW_PHP_SDK_VERSION'));
const workflowPhpVersion = unresolved(env('DW_WORKFLOW_PHP_VERSION') || env('DW_WORKFLOW_VERSION'));
const waterlineVersion = unresolved(env('DW_WATERLINE_VERSION'));

const artifactVersions = {
  server: serverVersion,
  cli: cliVersion,
  'sdk-php': phpSdkVersion,
  'sdk-python': pythonVersion,
  workflow: workflowPhpVersion,
  waterline: waterlineVersion,
};

const publishedArtifactVersions = {
  ...artifactVersions,
  'workflow-php': workflowPhpVersion,
};

const artifactSources = {
  server: serverImage || `docker://durableworkflow/server:${serverVersion}`,
  cli: `https://github.com/durable-workflow/cli/releases/download/${cliVersion}/install.sh`,
  'sdk-php': `packagist://durable-workflow/sdk@${phpSdkVersion}`,
  'sdk-python': `pypi://durable-workflow==${pythonVersion}`,
  workflow: `packagist://durable-workflow/workflow@${workflowPhpVersion}`,
  'workflow-php': `packagist://durable-workflow/workflow@${workflowPhpVersion}`,
  waterline: `packagist://durable-workflow/waterline@${waterlineVersion}`,
};

const scenarioManifest = readJsonIfExists(path.join(repoRoot, 'static/platform-conformance/workflow-update-runtime-scenarios.json')) ?? {};
const scenarioRequirements = objectValue(scenarioManifest.scenario_requirements);
const requiredScenarios = Object.keys(scenarioRequirements);
if (requiredScenarios.length === 0) {
  throw new Error('Workflow update scenario authority is missing required scenarios.');
}

const focusedProbeScenarioIds = new Set([
  'published_artifact_install_only',
  'declared_update_contract_visibility',
  'accepted_update_control_plane_and_history',
  'running_or_waiting_update_operator_visibility',
  'completed_update_result_round_trip',
  'failed_update_outcome',
  'duplicate_request_idempotency',
  'unknown_update_refusal',
  'invalid_input_refusal',
  'payload_envelope_round_trip',
  'terminal_workflow_update_behavior',
  'principal_attribution_with_auth',
  'update_validator_approval_boundary',
  'update_validator_rejection_boundary',
  'update_validator_worker_replacement',
  'duplicate_validation_completion',
  'unsupported_validation_capability',
]);

const forbiddenArtifactSourceTokens = arrayOfStrings(scenarioManifest?.artifact_policy?.forbidden_sources);
const requiredArtifacts = ['server', 'cli', 'sdk-php', 'sdk-python', 'workflow-php', 'waterline'];
const artifactAliases = {
  server: ['server'],
  cli: ['cli'],
  'sdk-php': ['sdk-php'],
  'sdk-python': ['sdk-python', 'python'],
  'workflow-php': ['workflow-php', 'workflow'],
  waterline: ['waterline'],
};

const focusedProbeMissingFinding = coverageFinding(
  'workflow-updates-focused-probe-coverage-gap',
  'focused_server_runtime_probe',
  'The focused published-server workflow update runtime probe did not run in this environment.',
  'Run scripts/conformance/workflow-updates-published-artifacts.sh inside the pinned published server image so the server update runtime cells execute without local source checkout evidence.',
);

const phpSidecarMissingFinding = coverageFinding(
  'workflow-updates-php-package-shard-coverage-gap',
  phpSidecarScenarioId,
  'The PHP SDK package update shard did not run against the pinned Packagist artifact in this environment.',
  'Run the workflow update conformance handoff where Composer can install durable-workflow/sdk from Packagist and separate PHP client and worker processes can reach the published server API.',
  'sdk-php',
);

const pythonSidecarMissingFinding = coverageFinding(
  'workflow-updates-python-sdk-shard-coverage-gap',
  pythonSidecarScenarioId,
  'The Python SDK workflow update shard did not run against the pinned PyPI artifact in this environment.',
  'Run the workflow update conformance handoff where pip can install durable-workflow from PyPI and the installed package client/worker command can emit Python update shard evidence.',
  'sdk-python',
);

const operatorDiagnosticsMissingFinding = coverageFinding(
  'workflow-updates-operator-diagnostics-shard-coverage-gap',
  operatorDiagnosticsScenarioId,
  'The CLI and Waterline operator diagnostics shard did not run against the pinned published artifacts in this environment.',
  'Run the workflow update conformance handoff where the official dw CLI release can emit workflow:update --json captures and the pinned Packagist Waterline package can inspect selected-run detail/history export for the same workflow run.',
  'waterline',
);

let sourcePolicy = {
  pass_requires_published_artifacts_only: true,
  local_product_source_checkouts_used_must_be_false: true,
  local_product_source_checkouts_used: false,
  local_checkout_execution_counts_as_pass: false,
};

const focusedEvidence = readFocusedEvidence();
const phpSidecarEvidence = readPhpSidecarEvidence();
const pythonSidecarEvidence = readPythonSidecarEvidence();
const operatorDiagnosticsEvidence = readOperatorDiagnosticsEvidence();
const evidenceSources = [focusedEvidence, phpSidecarEvidence, pythonSidecarEvidence, operatorDiagnosticsEvidence].filter((source) => source !== null);
const focusedEvidenceRunnerBlocked = truthyEvidenceFlag(focusedEvidence?.runner_blocked)
  || truthyEvidenceFlag(focusedEvidence?.runnerBlocked);
const phpSidecarEvidenceRunnerBlocked = truthyEvidenceFlag(phpSidecarEvidence?.runner_blocked)
  || truthyEvidenceFlag(phpSidecarEvidence?.runnerBlocked);
const pythonSidecarEvidenceRunnerBlocked = truthyEvidenceFlag(pythonSidecarEvidence?.runner_blocked)
  || truthyEvidenceFlag(pythonSidecarEvidence?.runnerBlocked);
const operatorDiagnosticsEvidenceRunnerBlocked = truthyEvidenceFlag(operatorDiagnosticsEvidence?.runner_blocked)
  || truthyEvidenceFlag(operatorDiagnosticsEvidence?.runnerBlocked);
const scenarioResults = {};
const findings = [];

if (focusedEvidenceRunnerBlocked) {
  findings.push(runnerBlockedFinding('focused_evidence'));
}
if (phpSidecarEvidenceRunnerBlocked) {
  findings.push(runnerBlockedFinding(phpSidecarScenarioId));
}
if (pythonSidecarEvidenceRunnerBlocked) {
  findings.push(runnerBlockedFinding(pythonSidecarScenarioId));
}
if (operatorDiagnosticsEvidenceRunnerBlocked) {
  findings.push(runnerBlockedFinding(operatorDiagnosticsScenarioId));
}

for (const scenarioId of requiredScenarios) {
  if (scenarioId === phpSidecarScenarioId) {
    scenarioResults[scenarioId] = scenarioResult(
      scenarioId,
      'not_covered',
      'coverage-gap',
      phpSidecarMissingFinding,
      {
        artifact_versions: artifactVersions,
        artifact_sources: artifactSources,
      },
    );
    continue;
  }

  if (scenarioId === pythonSidecarScenarioId) {
    scenarioResults[scenarioId] = scenarioResult(
      scenarioId,
      'not_covered',
      'coverage-gap',
      pythonSidecarMissingFinding,
      {
        artifact_versions: artifactVersions,
        artifact_sources: artifactSources,
      },
    );
    continue;
  }

  if (scenarioId === operatorDiagnosticsScenarioId) {
    scenarioResults[scenarioId] = scenarioResult(
      scenarioId,
      'not_covered',
      'coverage-gap',
      operatorDiagnosticsMissingFinding,
      {
        artifact_versions: artifactVersions,
        artifact_sources: artifactSources,
      },
    );
    continue;
  }

  if (!focusedProbeScenarioIds.has(scenarioId)) {
    scenarioResults[scenarioId] = scenarioResult(
      scenarioId,
      'not_covered',
      'coverage-gap',
      focusedProbeMissingFinding,
      {
        artifact_versions: artifactVersions,
        artifact_sources: artifactSources,
      },
    );
    continue;
  }

  scenarioResults[scenarioId] = scenarioResult(
    scenarioId,
    'not_covered',
    'coverage-gap',
    focusedProbeMissingFinding,
    {
      artifact_versions: artifactVersions,
      artifact_sources: artifactSources,
      skipped_from_local_checkout: repoRoot !== '/app' || fs.existsSync(path.join(repoRoot, '.git')),
    },
  );
}

function findingAllowedForScenarioIds(finding, allowedScenarioIds) {
  if (!finding || typeof finding !== 'object' || typeof finding.scenario_id !== 'string') {
    return true;
  }

  return allowedScenarioIds.has(finding.scenario_id);
}

function scenarioRowsForEvidence(sourceEvidence) {
  const scenarioResults = sourceEvidence?.scenario_results ?? sourceEvidence?.scenarioResults;
  if (Array.isArray(scenarioResults)) {
    return Object.fromEntries(
      scenarioResults
        .filter((row) => row && typeof row === 'object' && typeof row.scenario_id === 'string')
        .map((row) => [row.scenario_id, row]),
    );
  }

  return scenarioResults && typeof scenarioResults === 'object' ? scenarioResults : {};
}

function sidecarLocalProductSourceCheckoutsUsed(sourceEvidence, scenarioId) {
  if (!sourceEvidence) {
    return false;
  }

  const sourceRows = scenarioRowsForEvidence(sourceEvidence);
  const row = sourceRows[scenarioId] ?? null;

  return localProductSourceCheckoutsUsed(sourceEvidence)
    || localArtifactSourceReported(sourceEvidence)
    || localProductSourceCheckoutsUsed(row)
    || localArtifactSourceReported(row);
}

function importScenarioEvidence(sourceEvidence, allowedScenarioIds) {
  const sourceRows = scenarioRowsForEvidence(sourceEvidence);
  if (!sourceEvidence || Object.keys(sourceRows).length === 0) {
    return;
  }

  for (const scenarioId of allowedScenarioIds) {
    if (Object.prototype.hasOwnProperty.call(sourceRows, scenarioId)) {
      scenarioResults[scenarioId] = normalizeScenarioResult(
        scenarioId,
        sourceRows[scenarioId],
        sourceEvidence,
      );
    }
  }

  if (Array.isArray(sourceEvidence.findings)) {
    findings.push(...sourceEvidence.findings.filter((finding) => findingAllowedForScenarioIds(finding, allowedScenarioIds)));
  }
}

if (focusedEvidence) {
  importScenarioEvidence(
    focusedEvidence,
    isPhpSidecarEvidence(focusedEvidence)
      ? new Set([phpSidecarScenarioId])
      : (isPythonSidecarEvidence(focusedEvidence)
        ? new Set([pythonSidecarScenarioId])
        : (isOperatorDiagnosticsEvidence(focusedEvidence)
          ? new Set([operatorDiagnosticsScenarioId])
          : focusedProbeScenarioIds)),
  );
}
if (phpSidecarEvidence) {
  importScenarioEvidence(phpSidecarEvidence, new Set([phpSidecarScenarioId]));
}
if (pythonSidecarEvidence) {
  importScenarioEvidence(pythonSidecarEvidence, new Set([pythonSidecarScenarioId]));
}
if (operatorDiagnosticsEvidence) {
  importScenarioEvidence(operatorDiagnosticsEvidence, new Set([operatorDiagnosticsScenarioId]));
}

const artifactPolicyFailures = uniqueArtifactFailures([
  ...artifactMapFailures(artifactVersions, 'artifact_versions', 'placeholder'),
  ...artifactMapFailures(publishedArtifactVersions, 'published_artifact_versions', 'placeholder'),
  ...artifactMapFailures(artifactSources, 'artifact_sources', 'placeholder'),
  ...evidenceSources.flatMap((source) => presentArtifactMapFailures(source, observedOutputsFor(source), 'artifact_versions', 'evidence_placeholder', source)),
  ...evidenceSources.flatMap((source) => presentArtifactMapFailures(source, observedOutputsFor(source), 'published_artifact_versions', 'evidence_placeholder', source)),
  ...evidenceSources.flatMap((source) => presentArtifactMapFailures(source, observedOutputsFor(source), 'artifact_sources', 'evidence_placeholder', source)),
  ...Object.values(scenarioResults).flatMap((row) => Array.isArray(row?.observed_outputs?.artifact_prerequisite_failures)
    ? row.observed_outputs.artifact_prerequisite_failures
    : []),
]);

const evidenceLocalProductSourceCheckoutsUsed = evidenceSources.some((source) => localProductSourceCheckoutsUsed(source) || localArtifactSourceReported(source))
  || Object.values(scenarioResults).some((row) => localProductSourceCheckoutsUsed(row) || localArtifactSourceReported(row));
sourcePolicy = {
  ...sourcePolicy,
  local_product_source_checkouts_used: evidenceLocalProductSourceCheckoutsUsed,
};
if (evidenceLocalProductSourceCheckoutsUsed) {
  findings.push(coverageFinding(
    'workflow-updates-source-policy-local-checkout-evidence',
    'source_policy',
    'Workflow update runtime evidence reported local product source checkout usage, so it cannot produce a passing published-artifact conformance result.',
    'Rerun workflow update conformance against pinned published artifacts only and record local_product_source_checkouts_used=false at run and scenario scope.',
  ));
}

for (const [scenarioId, row] of Object.entries(scenarioResults)) {
  if (row.status !== 'pass' && (!Array.isArray(row.linked_findings) || row.linked_findings.length === 0)) {
    const fallback = scenarioId === phpSidecarScenarioId
      ? phpSidecarMissingFinding
      : (scenarioId === pythonSidecarScenarioId
        ? pythonSidecarMissingFinding
        : (scenarioId === operatorDiagnosticsScenarioId
          ? operatorDiagnosticsMissingFinding
          : focusedProbeMissingFinding));
    row.linked_findings = [fallback];
    findings.push(fallback);
  }
}

for (const row of Object.values(scenarioResults)) {
  if (Array.isArray(row.linked_findings)) {
    findings.push(...row.linked_findings);
  }
}

const updateCellOutcomes = Object.fromEntries(
  requiredScenarios.map((scenarioId) => [scenarioId, scenarioResults[scenarioId]?.status || 'not_covered']),
);

const nonPassStatuses = new Set(['fail', 'unsupported', 'not_covered', 'runner_blocked']);
const nonPassingScenarioIds = requiredScenarios.filter((scenarioId) => nonPassStatuses.has(updateCellOutcomes[scenarioId]));
const runnerBlocked = requiredScenarios.some((scenarioId) => updateCellOutcomes[scenarioId] === 'runner_blocked')
  || focusedEvidenceRunnerBlocked
  || phpSidecarEvidenceRunnerBlocked
  || pythonSidecarEvidenceRunnerBlocked
  || operatorDiagnosticsEvidenceRunnerBlocked;
const everyPassRowHasPublishedArtifactEvidence = requiredScenarios.every((scenarioId) => {
  const row = scenarioResults[scenarioId] || {};
  if (row.status !== 'pass') {
    return true;
  }

  return row.published_artifact_cell_executed === true
    && row.local_product_source_checkouts_used === false
    && explicitFalse(row.observed_outputs?.local_product_source_checkouts_used);
});
const outcome = requiredScenarios.every((scenarioId) => updateCellOutcomes[scenarioId] === 'pass')
  && everyPassRowHasPublishedArtifactEvidence
  && artifactPolicyFailures.length === 0
  && sourcePolicy.local_product_source_checkouts_used === false
  && !runnerBlocked
  ? 'pass'
  : 'fail';
const normalizedFindings = uniqueFindings(findings);

const result = {
  schema: 'durable-workflow.v2.workflow-update-runtime.result',
  result_version: 1,
  experiment: 'workflow-updates',
  runner: 'scripts/conformance/workflow-updates-published-artifacts.sh',
  generated_at: generatedAt,
  started_at: startedAt,
  finished_at: finishedAt,
  outcome,
  runner_blocked: runnerBlocked,
  artifact_versions: artifactVersions,
  published_artifact_versions: publishedArtifactVersions,
  artifact_sources: artifactSources,
  artifact_policy_failures: artifactPolicyFailures,
  source_policy: sourcePolicy,
  local_product_source_checkouts_used: sourcePolicy.local_product_source_checkouts_used,
  scenario_results: scenarioResults,
  update_cell_outcomes: updateCellOutcomes,
  non_passing_scenarios: nonPassingScenarioIds,
  focused_probe: {
    implemented: true,
    evidence_loaded: focusedEvidence !== null,
    evidence_file: focusedEvidence ? focusedEvidenceFile : null,
    evidence_schema: focusedEvidence?.schema || null,
    runs_inside_published_server_image: repoRoot === '/app',
    local_product_source_checkouts_used: sourcePolicy.local_product_source_checkouts_used,
  },
  php_sidecar: {
    implemented: true,
    scenario_id: phpSidecarScenarioId,
    evidence_loaded: phpSidecarEvidence !== null,
    evidence_file: phpSidecarEvidence ? phpSidecarEvidenceFile : null,
    evidence_schema: phpSidecarEvidence?.schema || null,
    package_version: phpSdkVersion,
    artifact_source: artifactSources['sdk-php'],
    local_product_source_checkouts_used: sidecarLocalProductSourceCheckoutsUsed(phpSidecarEvidence, phpSidecarScenarioId),
  },
  python_sidecar: {
    implemented: true,
    scenario_id: pythonSidecarScenarioId,
    evidence_loaded: pythonSidecarEvidence !== null,
    evidence_file: pythonSidecarEvidence ? pythonSidecarEvidenceFile : null,
    evidence_schema: pythonSidecarEvidence?.schema || null,
    package_version: pythonVersion,
    artifact_source: artifactSources['sdk-python'],
    local_product_source_checkouts_used: sidecarLocalProductSourceCheckoutsUsed(pythonSidecarEvidence, pythonSidecarScenarioId),
  },
  operator_diagnostics_sidecar: {
    implemented: true,
    scenario_id: operatorDiagnosticsScenarioId,
    evidence_loaded: operatorDiagnosticsEvidence !== null,
    evidence_file: operatorDiagnosticsEvidence ? operatorDiagnosticsEvidenceFile : null,
    evidence_schema: operatorDiagnosticsEvidence?.schema || null,
    cli_package_version: cliVersion,
    cli_artifact_source: artifactSources.cli,
    waterline_package_version: waterlineVersion,
    waterline_artifact_source: artifactSources.waterline,
    local_product_source_checkouts_used: sidecarLocalProductSourceCheckoutsUsed(operatorDiagnosticsEvidence, operatorDiagnosticsScenarioId),
  },
  findings: normalizedFindings,
  finding_links: Object.fromEntries(
    normalizedFindings
      .filter((finding) => finding && typeof finding === 'object' && typeof finding.finding_id === 'string')
      .map((finding) => [finding.finding_id, {
        owning_surface: finding.owning_surface || 'conformance_harness',
        classification: finding.classification || 'coverage-gap',
        scenario_id: finding.scenario_id || null,
        next_acceptance_criterion: finding.next_acceptance_criterion || null,
      }]),
  ),
};

const pins = {
  schema: 'durable-workflow.v2.workflow-update-runtime.pins',
  generated_at: generatedAt,
  artifact_versions: artifactVersions,
  published_artifact_versions: publishedArtifactVersions,
  artifact_sources: artifactSources,
  local_product_source_checkouts_used: sourcePolicy.local_product_source_checkouts_used,
};

const metadata = {
  schema: 'durable-workflow.v2.workflow-update-runtime.run-metadata',
  experiment: 'workflow-updates',
  started_at: startedAt,
  finished_at: finishedAt,
  outcome,
  runner_blocked: runnerBlocked,
  result_file: 'workflow-updates-result.json',
  record_file: 'workflow-updates-record.json',
  findings_file: 'workflow-updates-findings.json',
  focused_evidence_file: focusedEvidence ? focusedEvidenceFile : null,
  php_sidecar_evidence_file: phpSidecarEvidence ? phpSidecarEvidenceFile : null,
  python_sidecar_evidence_file: pythonSidecarEvidence ? pythonSidecarEvidenceFile : null,
  operator_diagnostics_evidence_file: operatorDiagnosticsEvidence ? operatorDiagnosticsEvidenceFile : null,
};

const sourcePolicyNote = sourcePolicy.local_product_source_checkouts_used
  ? 'Local product source checkout evidence was reported and cannot count as passing published-artifact evidence.'
  : 'No local product source checkout execution was used as pass evidence.';

const record = {
  experiment: 'workflow-updates',
  outcome,
  runnerBlocked: runnerBlocked,
  artifactVersions,
  artifactSources,
  artifactPolicyFailures,
  sourcePolicy,
  findings: normalizedFindings.map((finding) => typeof finding === 'string' ? finding : finding.summary).filter(Boolean),
  findingLinks: result.finding_links,
  notes: [
    'Focused published-server workflow update runtime cells execute when the handoff runs inside the pinned server image.',
    'The PHP SDK shard installs the pinned Packagist durable-workflow/sdk package before importing separate PHP client/worker update evidence.',
    'The Python SDK shard installs the pinned PyPI durable-workflow package before importing Python client/worker update evidence.',
    'The operator diagnostics shard installs the pinned CLI release and Packagist Waterline package before importing workflow:update JSON plus selected-run update/history evidence.',
    sourcePolicyNote,
  ],
  local_product_source_checkouts_used: sourcePolicy.local_product_source_checkouts_used,
  result_file: 'workflow-updates-result.json',
  findings_file: 'workflow-updates-findings.json',
  focused_evidence_file: focusedEvidence ? focusedEvidenceFile : null,
  php_sidecar_evidence_file: phpSidecarEvidence ? phpSidecarEvidenceFile : null,
  python_sidecar_evidence_file: pythonSidecarEvidence ? pythonSidecarEvidenceFile : null,
  operator_diagnostics_evidence_file: operatorDiagnosticsEvidence ? operatorDiagnosticsEvidenceFile : null,
};

writeJson('pins.json', pins);
writeJson('run-metadata.json', metadata);
writeJson('workflow-updates-result.json', result);
writeJson('workflow-updates-record.json', record);
writeJson('workflow-updates-findings.json', normalizedFindings);

console.log(JSON.stringify({
  result_dir: resultDir,
  result: path.join(resultDir, 'workflow-updates-result.json'),
  record: path.join(resultDir, 'workflow-updates-record.json'),
  outcome,
  runner_blocked: runnerBlocked,
  focused_probe_evidence_loaded: focusedEvidence !== null,
  php_sidecar_evidence_loaded: phpSidecarEvidence !== null,
  python_sidecar_evidence_loaded: pythonSidecarEvidence !== null,
  operator_diagnostics_evidence_loaded: operatorDiagnosticsEvidence !== null,
}));
NODE
