#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage: workflow-lifecycle-published-artifacts.sh [--result-dir DIR|--result-dir=DIR]

Writes the published-artifact workflow lifecycle conformance result.

The runner writes these files to the result directory:
  pins.json
  run-metadata.json
  workflow-lifecycle-result.json
  workflow-lifecycle-record.json
  workflow-lifecycle-findings.json
  lifecycle-result.json
  lifecycle-record.json

Environment overrides:
  DW_WORKFLOW_LIFECYCLE_RESULT_DIR  Result directory when --result-dir is omitted.
  DW_WORKFLOW_LIFECYCLE_EVIDENCE    Inline JSON evidence from the host runtime shard.
  DW_WORKFLOW_LIFECYCLE_EVIDENCE_PATH
                                    JSON evidence file. Defaults to
                                    workflow-lifecycle-evidence.json in the result directory.
  DW_WORKFLOW_LIFECYCLE_SKIP_FOCUSED_HOST_PROBE=1
                                    Skip the published server container's
                                    focused workflow lifecycle host probes.
  DW_SERVER_IMAGE                   Exact server image tag or digest under test.
  DW_SERVER_VERSION                 Exact server version under test.
  DW_CLI_VERSION                    Exact CLI release version.
  DW_PYTHON_SDK_VERSION             Exact PyPI durable-workflow version.
  DW_WORKFLOW_PHP_VERSION           Exact Composer durable-workflow/workflow version.
  DW_WATERLINE_VERSION              Exact Waterline artifact version.
USAGE
}

result_dir="${DW_WORKFLOW_LIFECYCLE_RESULT_DIR:-}"

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
  result_dir="$(mktemp -d "${TMPDIR:-/tmp}/dw-workflow-lifecycle.XXXXXX")"
fi
mkdir -p "$result_dir"
result_dir="$(cd "$result_dir" && pwd)"

timestamp() {
  date -u '+%Y-%m-%dT%H:%M:%SZ'
}

started_at="$(timestamp)"
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/../.." && pwd)"
manifest_path="${DW_WORKFLOW_LIFECYCLE_SCENARIO_MANIFEST:-$repo_root/static/platform-conformance/workflow-lifecycle-scenarios.json}"

if ! command -v node >/dev/null 2>&1; then
  printf '%s\n' 'required command not found: node' >&2
  exit 1
fi

should_run_focused_host_probes() {
  if [[ "${DW_WORKFLOW_LIFECYCLE_SKIP_FOCUSED_HOST_PROBE:-0}" == "1" || "${DW_WORKFLOW_LIFECYCLE_SKIP_FOCUSED_HOST_PROBE:-}" == "true" ]]; then
    return 1
  fi
  if [[ -n "${DW_WORKFLOW_LIFECYCLE_EVIDENCE:-}" || -n "${DW_WORKFLOW_LIFECYCLE_EVIDENCE_PATH:-}" ]]; then
    return 1
  fi
  if [[ -s "$result_dir/workflow-lifecycle-evidence.json" ]]; then
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

run_focused_host_probes() {
  local probe_db="$result_dir/workflow-lifecycle-continue-as-new.sqlite"
  local probe_app_key="${APP_KEY:-base64:V09SS0ZMT1ctTElGRUNZQ0xFLUNPTlRJTlVFLUFTLU5FVw==}"

  : > "$probe_db"

  APP_ENV=production \
  APP_DEBUG=false \
  APP_KEY="$probe_app_key" \
  DB_CONNECTION=sqlite \
  DB_DATABASE="$probe_db" \
  QUEUE_CONNECTION=database \
  CACHE_STORE=array \
  SESSION_DRIVER=array \
  DW_AUTH_DRIVER=none \
  DW_TASK_DISPATCH_MODE=poll \
  DW_V2_TASK_DISPATCH_MODE=poll \
  RUNNER_REPO_ROOT="$repo_root" \
  RESULT_DIR="$result_dir" \
  php <<'PHP' || true
<?php
declare(strict_types=1);

use App\Models\WorkflowNamespace;
use App\Support\ControlPlaneProtocol;
use App\Support\WorkerProtocol;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Workflow\Serializers\Serializer;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\WorkflowExecutor;

const LIFECYCLE_NAMESPACE = 'workflow-lifecycle-conformance';
const LIFECYCLE_TASK_QUEUE = 'workflow-lifecycle-shared';
const LIFECYCLE_WORKFLOW_TYPE = 'workflow.lifecycle.continue-as-new';
const LIFECYCLE_WORKER_ID = 'workflow-lifecycle-continue-as-new-worker';
const LIFECYCLE_TERMINAL_TASK_QUEUE = 'workflow-lifecycle-terminal';
const HOST_EVIDENCE_SCHEMA = 'durable-workflow.v2.workflow-lifecycle.host-evidence';
const HOST_EVIDENCE_SOURCE = 'published_server_container';
const FOCUSED_SCENARIOS = [
    'continue_as_new_run_chain_visibility',
    'continue_as_new_identity_and_history_continuity',
    'continue_as_new_duplicate_side_effect_prevention',
    'cancellation_public_surface_terminal_state',
    'termination_public_surface_terminal_state',
    'workflow_id_reuse_duplicate_start_policy',
    'workflow_timeout_terminal_state',
];

$repoRoot = getenv('RUNNER_REPO_ROOT') ?: '/app';
$resultDir = rtrim(getenv('RESULT_DIR') ?: sys_get_temp_dir(), '/');
chdir($repoRoot);
require $repoRoot.'/vendor/autoload.php';

function now_iso(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

function write_json_file(string $path, array $value): void
{
    file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
}

function evidence_path(): string
{
    global $resultDir;

    return $resultDir.'/workflow-lifecycle-evidence.json';
}

function string_env(string $name): string
{
    $value = getenv($name);

    return is_string($value) ? trim($value) : '';
}

function artifact_versions_from_env(): array
{
    return [
        'server' => string_env('DW_SERVER_VERSION'),
        'cli' => string_env('DW_CLI_VERSION'),
        'workflow' => string_env('DW_WORKFLOW_PHP_VERSION'),
        'workflow-php' => string_env('DW_WORKFLOW_PHP_VERSION'),
        'sdk-python' => string_env('DW_PYTHON_SDK_VERSION'),
        'waterline' => string_env('DW_WATERLINE_VERSION'),
    ];
}

function artifact_sources_from_env(): array
{
    $serverImage = string_env('DW_SERVER_IMAGE');
    $versions = artifact_versions_from_env();

    return [
        'server' => $serverImage !== '' ? $serverImage : 'docker://durableworkflow/server:'.$versions['server'],
        'cli' => $versions['cli'] !== '' ? 'github-release://durable-workflow/cli/v'.$versions['cli'].'/install.sh' : '',
        'workflow' => $versions['workflow'] !== '' ? 'packagist://durable-workflow/workflow@'.$versions['workflow'] : '',
        'workflow-php' => $versions['workflow-php'] !== '' ? 'packagist://durable-workflow/workflow@'.$versions['workflow-php'] : '',
        'sdk-python' => $versions['sdk-python'] !== '' ? 'pypi://durable-workflow=='.$versions['sdk-python'] : '',
        'waterline' => $versions['waterline'] !== '' ? 'packagist://durable-workflow/waterline@'.$versions['waterline'] : '',
    ];
}

function source_policy(array $artifactSources): array
{
    return [
        'policy_name' => 'published_artifacts_only',
        'published_artifacts_only' => true,
        'published_artifact_evidence_only' => true,
        'pass_evidence_must_come_from_published_artifacts' => true,
        'artifact_sources' => $artifactSources,
        'local_product_source_checkouts_used' => false,
        'local_product_source_checkout_used_as_pass_evidence' => false,
    ];
}

function focused_finding(string $scenarioId, string $message): array
{
    $owningSurface = match (true) {
        str_starts_with($scenarioId, 'cancellation'), str_starts_with($scenarioId, 'termination') => 'server-cli-and-sdks',
        str_contains($scenarioId, 'duplicate_start') => 'server',
        str_contains($scenarioId, 'timeout') => 'server',
        default => 'workflow-runtime-and-server',
    };

    return [
        'finding_id' => 'workflow-lifecycle-'.$scenarioId.'-focused-product-gap',
        'finding_type' => 'product_behavior_gap',
        'classification' => 'product-gap',
        'scenario_id' => $scenarioId,
        'owning_surface' => $owningSurface,
        'summary' => $message,
        'next_acceptance_criterion' => 'Rerun workflow-lifecycle conformance from the pinned published server image and record passing runtime evidence for this focused cell.',
    ];
}

function failure_scenario(string $scenarioId, Throwable $throwable): array
{
    $message = $throwable::class.': '.$throwable->getMessage();

    return [
        'scenario_id' => $scenarioId,
        'status' => 'fail',
        'classification' => 'product-gap',
        'published_artifact_cell_executed' => true,
        'observed_outputs' => [
            'published_artifact_cell_executed' => true,
            'execution_source' => HOST_EVIDENCE_SOURCE,
            'failure' => $message,
        ],
        'linked_findings' => [focused_finding($scenarioId, $message)],
    ];
}

function failure_evidence(Throwable $throwable, ?array $scenarios = null): array
{
    $scenarios ??= FOCUSED_SCENARIOS;
    $scenarioResults = [];
    foreach ($scenarios as $scenarioId) {
        $scenarioResults[$scenarioId] = failure_scenario($scenarioId, $throwable);
    }

    $artifactSources = artifact_sources_from_env();

    return [
        'schema' => HOST_EVIDENCE_SCHEMA,
        'generated_at' => now_iso(),
        'evidence_source' => 'focused_published_server_workflow_lifecycle_host_probes',
        'execution_source' => HOST_EVIDENCE_SOURCE,
        'runner' => 'published-server-workflow-lifecycle-focused-host-probes',
        'artifact_versions' => artifact_versions_from_env(),
        'artifact_sources' => $artifactSources,
        'source_policy' => source_policy($artifactSources),
        'local_product_source_checkouts_used' => false,
        'runner_blocked' => false,
        'scenario_results' => $scenarioResults,
    ];
}

function bootstrap_application(string $repoRoot): void
{
    $app = require $repoRoot.'/bootstrap/app.php';
    $app->make(ConsoleKernel::class)->bootstrap();

    config([
        'app.key' => getenv('APP_KEY') ?: 'base64:V09SS0ZMT1ctTElGRUNZQ0xFLUNPTlRJTlVFLUFTLU5FVw==',
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
        ['name' => LIFECYCLE_NAMESPACE],
        [
            'description' => 'Workflow lifecycle conformance namespace',
            'retention_days' => 30,
            'status' => 'active',
        ],
    );
}

function header_key(string $name): string
{
    return 'HTTP_'.str_replace('-', '_', strtoupper($name));
}

function request_json(string $method, string $path, ?array $body = null, array $allowed = []): array
{
    static $kernel = null;
    $kernel ??= app(HttpKernel::class);

    $server = [
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_NAMESPACE' => LIFECYCLE_NAMESPACE,
        header_key(ControlPlaneProtocol::HEADER) => ControlPlaneProtocol::VERSION,
        header_key(WorkerProtocol::HEADER) => WorkerProtocol::VERSION,
    ];
    $content = $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR);
    $request = Request::create('/api'.$path, $method, [], [], [], $server, $content);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    $status = $response->getStatusCode();
    $payload = (string) $response->getContent();

    if (($status >= 400 || $status === 0) && ! in_array($status, $allowed, true)) {
        throw new RuntimeException(sprintf('%s %s failed with HTTP %d: %s', $method, $path, $status, $payload));
    }

    if ($payload === '') {
        return ['_http_status' => $status];
    }

    $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
    $result = is_array($decoded) ? $decoded : [];
    $result['_http_status'] = $status;

    return $result;
}

function event_types(array $history): array
{
    $events = is_array($history['events'] ?? null) ? $history['events'] : [];

    return array_values(array_filter(array_map(
        static fn (mixed $event): string => is_array($event) && is_string($event['event_type'] ?? null) ? $event['event_type'] : '',
        $events,
    )));
}

function history_events(array $history): array
{
    return is_array($history['events'] ?? null) ? array_values($history['events']) : [];
}

function first_matching_event(array $events, array $needles): string
{
    foreach ($events as $event) {
        foreach ($needles as $needle) {
            if (stripos($event, $needle) !== false) {
                return $event;
            }
        }
    }

    return '';
}

function first_matching_history_event(array $history, array $needles): array
{
    foreach (history_events($history) as $event) {
        if (! is_array($event)) {
            continue;
        }

        $eventType = is_string($event['event_type'] ?? null) ? $event['event_type'] : '';
        foreach ($needles as $needle) {
            if (stripos($eventType, $needle) !== false) {
                return $event;
            }
        }
    }

    return [];
}

function require_string(array $source, string $key, string $message): string
{
    $value = $source[$key] ?? null;

    if (! is_string($value) || trim($value) === '') {
        throw new RuntimeException($message);
    }

    return trim($value);
}

function typed_validation_refusal(array $response, string $field, string $shape): array
{
    $status = is_numeric($response['_http_status'] ?? null) ? (int) $response['_http_status'] : 0;
    $errors = is_array($response['errors'] ?? null) ? $response['errors'] : [];
    $fieldErrors = is_array($errors[$field] ?? null) ? array_values($errors[$field]) : [];
    $fieldMessage = is_string($fieldErrors[0] ?? null) ? trim($fieldErrors[0]) : '';
    $message = $fieldMessage !== ''
        ? $fieldMessage
        : (is_string($response['message'] ?? null) ? trim($response['message']) : 'validation_error');

    if ($status < 400) {
        throw new RuntimeException($shape.' was accepted instead of refused with a typed validation error');
    }
    if ($message === '') {
        throw new RuntimeException($shape.' refusal did not expose a user-visible validation message');
    }

    return [
        'shape' => $shape,
        'field' => $field,
        'http_status' => $status,
        'typed_error' => 'validation_error',
        'refusal_reason' => $message,
        'documented' => true,
        'counted_as_pass_evidence' => false,
    ];
}

function unsupported_timeout_shape_refusals(string $workflowId): array
{
    $legacyRunTimeout = request_json('POST', '/workflows', [
        'workflow_id' => $workflowId.'-legacy-run-timeout',
        'workflow_type' => 'workflow.lifecycle.timeout.unsupported',
        'task_queue' => LIFECYCLE_TERMINAL_TASK_QUEUE,
        'workflow_run_timeout' => 1,
        'input' => ['cell' => 'workflow_timeout_terminal_state'],
    ], [400, 422]);

    $workflowTaskTimeout = request_json('POST', '/workflows', [
        'workflow_id' => $workflowId.'-workflow-task-timeout',
        'workflow_type' => 'workflow.lifecycle.timeout.unsupported',
        'task_queue' => LIFECYCLE_TERMINAL_TASK_QUEUE,
        'workflow_task_timeout' => 1,
        'input' => ['cell' => 'workflow_timeout_terminal_state'],
    ], [400, 422]);

    return [
        typed_validation_refusal($legacyRunTimeout, 'workflow_run_timeout', 'workflow_run_timeout'),
        typed_validation_refusal($workflowTaskTimeout, 'workflow_task_timeout', 'workflow_task_timeout'),
    ];
}

function require_terminal_response(array $source, string $key, string $expected, string $message): string
{
    $value = require_string($source, $key, $message);

    if ($value !== $expected) {
        throw new RuntimeException($message.'; expected '.$expected.', got '.$value);
    }

    return $value;
}

function pass_scenario(string $scenarioId, array $outputs): array
{
    return [
        'scenario_id' => $scenarioId,
        'status' => 'pass',
        'classification' => 'passed',
        'published_artifact_cell_executed' => true,
        'observed_outputs' => $outputs + [
            'published_artifact_cell_executed' => true,
            'execution_source' => HOST_EVIDENCE_SOURCE,
            'local_product_source_checkouts_used' => false,
        ],
    ];
}

function run_workflow_timeout_terminal_state_probe(): array
{
    $workflowId = 'workflow-lifecycle-timeout-'.strtolower(bin2hex(random_bytes(4)));
    $workerId = 'workflow-lifecycle-timeout-worker-'.strtolower(bin2hex(random_bytes(4)));
    $workflowType = 'workflow.lifecycle.timeout';
    $runTimeoutSeconds = 1;

    request_json('POST', '/worker/register', [
        'worker_id' => $workerId,
        'task_queue' => LIFECYCLE_TERMINAL_TASK_QUEUE,
        'runtime' => 'php',
        'supported_workflow_types' => [$workflowType],
    ]);

    $start = request_json('POST', '/workflows', [
        'workflow_id' => $workflowId,
        'workflow_type' => $workflowType,
        'task_queue' => LIFECYCLE_TERMINAL_TASK_QUEUE,
        'run_timeout_seconds' => $runTimeoutSeconds,
        'input' => [
            'cell' => 'workflow_timeout_terminal_state',
            'timeout_shape' => 'run_timeout_seconds',
        ],
    ]);
    $runId = require_string($start, 'run_id', 'timeout workflow start response did not include run_id');

    $beforeTimeout = request_json('GET', '/workflows/'.$workflowId.'/runs/'.$runId);
    $deadlineAt = require_string($beforeTimeout, 'run_deadline_at', 'timeout workflow describe response did not include run_deadline_at');

    $poll = request_json('POST', '/worker/workflow-tasks/poll', [
        'worker_id' => $workerId,
        'task_queue' => LIFECYCLE_TERMINAL_TASK_QUEUE,
    ]);
    $task = is_array($poll['task'] ?? null) ? $poll['task'] : [];
    $taskId = require_string($task, 'task_id', 'timeout worker poll did not return task_id');
    $attempt = is_numeric($task['workflow_task_attempt'] ?? null) ? (int) $task['workflow_task_attempt'] : 0;
    if ($attempt < 1) {
        throw new RuntimeException('timeout worker poll did not return workflow_task_attempt');
    }

    $deadline = Carbon::parse($deadlineAt);
    $enforcedAt = $deadline->copy()->addSecond();
    Carbon::setTestNow($enforcedAt);
    try {
        $run = WorkflowRun::query()->findOrFail($runId);
        $taskModel = WorkflowTask::query()->findOrFail($taskId);
        app(WorkflowExecutor::class)->run($run->fresh(), $taskModel->fresh());
    } finally {
        Carbon::setTestNow();
    }

    $afterTimeout = request_json('GET', '/workflows/'.$workflowId.'/runs/'.$runId);
    $terminalStatus = require_terminal_response(
        $afterTimeout,
        'closed_reason',
        'timed_out',
        'timeout describe-run response did not expose timed_out closed_reason',
    );
    $observedTerminalAt = require_string(
        $afterTimeout,
        'closed_at',
        'timeout describe-run response did not expose closed_at',
    );

    $history = request_json('GET', '/workflows/'.$workflowId.'/runs/'.$runId.'/history');
    $historyEvents = event_types($history);
    $terminalEvent = first_matching_event($historyEvents, ['WorkflowTimedOut']);
    if ($terminalEvent === '') {
        throw new RuntimeException('timeout history did not expose WorkflowTimedOut');
    }
    $terminalHistoryEvent = first_matching_history_event($history, ['WorkflowTimedOut']);

    $callerError = request_json('POST', '/workflows/'.$workflowId.'/runs/'.$runId.'/query/currentState', [], [409]);
    $unsupportedTimeoutShapeRefusals = unsupported_timeout_shape_refusals($workflowId);

    return pass_scenario('workflow_timeout_terminal_state', [
        'workflow_id' => $workflowId,
        'run_id' => $runId,
        'timeout_field' => 'run_timeout_seconds',
        'timeout_value_seconds' => $runTimeoutSeconds,
        'deadline_at' => $deadlineAt,
        'observed_terminal_at' => $observedTerminalAt,
        'terminal_status' => $terminalStatus,
        'public_run_status' => $afterTimeout['status'] ?? null,
        'start_http_status' => $start['_http_status'] ?? null,
        'describe_before_timeout' => [
            'status' => $beforeTimeout['status'] ?? null,
            'closed_reason' => $beforeTimeout['closed_reason'] ?? null,
            'run_timeout_seconds' => $beforeTimeout['run_timeout_seconds'] ?? null,
            'run_deadline_at' => $beforeTimeout['run_deadline_at'] ?? null,
            'started_at' => $beforeTimeout['started_at'] ?? null,
        ],
        'describe_after_timeout' => [
            'status' => $afterTimeout['status'] ?? null,
            'closed_reason' => $afterTimeout['closed_reason'] ?? null,
            'closed_at' => $afterTimeout['closed_at'] ?? null,
            'run_deadline_at' => $afterTimeout['run_deadline_at'] ?? null,
            'error' => $afterTimeout['error'] ?? null,
        ],
        'operator_visible_timing' => [
            'server_api' => [
                'start_path' => '/api/workflows',
                'describe_path' => '/api/workflows/{workflowId}/runs/{runId}',
                'run_timeout_seconds' => $runTimeoutSeconds,
                'deadline_at' => $deadlineAt,
                'observed_terminal_at' => $observedTerminalAt,
                'closed_reason' => $terminalStatus,
            ],
            'history' => [
                'history_path' => '/api/workflows/{workflowId}/runs/{runId}/history',
                'terminal_event' => $terminalEvent,
                'terminal_event_payload' => is_array($terminalHistoryEvent['payload'] ?? null)
                    ? $terminalHistoryEvent['payload']
                    : [],
                'event_count' => count($historyEvents),
            ],
            'worker_protocol' => [
                'poll_path' => '/api/worker/workflow-tasks/poll',
                'worker_id' => $workerId,
                'task_id' => $taskId,
                'workflow_task_attempt' => $attempt,
                'enforced_by_executor_at' => $enforcedAt->toJSON(),
            ],
            'caller_query_after_terminal' => [
                'query_path' => '/api/workflows/{workflowId}/runs/{runId}/query/currentState',
                'http_status' => $callerError['_http_status'] ?? null,
                'reason' => $callerError['reason'] ?? null,
                'run_status' => $callerError['run_status'] ?? null,
                'message' => $callerError['message'] ?? null,
            ],
            'cli' => [
                'artifact_version' => string_env('DW_CLI_VERSION'),
                'status' => 'not_exercised_in_published_server_host_probe',
                'typed_refusal' => [
                    'typed_error' => 'cli_surface_outside_focused_timeout_cell',
                    'refusal_reason' => 'The focused timeout host probe records server API, history, and worker-protocol timing from the published server image; broad CLI diagnostics remain in the operator diagnostics lifecycle cell.',
                    'documented' => true,
                ],
            ],
        ],
        'history_events' => $historyEvents,
        'terminal_history_event' => $terminalEvent,
        'terminal_history_event_payload' => is_array($terminalHistoryEvent['payload'] ?? null)
            ? $terminalHistoryEvent['payload']
            : [],
        'unsupported_timeout_shape_refusals' => $unsupportedTimeoutShapeRefusals,
        'source_policy' => 'published_artifacts_only',
    ]);
}

function run_terminal_surface_probe(
    string $command,
    string $scenarioId,
    string $workflowType,
    string $timestampField,
    string $terminalStatus,
    string $expectedWorkerStopReason,
    string $expectedTerminalEvent,
): array {
    $workflowId = 'workflow-lifecycle-'.$command.'-'.strtolower(bin2hex(random_bytes(4)));
    $workerId = 'workflow-lifecycle-'.$command.'-worker';
    $reason = 'workflow lifecycle conformance '.$command;

    request_json('POST', '/worker/register', [
        'worker_id' => $workerId,
        'task_queue' => LIFECYCLE_TERMINAL_TASK_QUEUE,
        'runtime' => 'php',
        'supported_workflow_types' => [$workflowType],
    ]);

    $start = request_json('POST', '/workflows', [
        'workflow_id' => $workflowId,
        'workflow_type' => $workflowType,
        'task_queue' => LIFECYCLE_TERMINAL_TASK_QUEUE,
        'input' => ['reason' => $reason],
    ]);
    $runId = require_string($start, 'run_id', $command.' workflow start response did not include run_id');

    $poll = request_json('POST', '/worker/workflow-tasks/poll', [
        'worker_id' => $workerId,
        'task_queue' => LIFECYCLE_TERMINAL_TASK_QUEUE,
    ]);
    $task = is_array($poll['task'] ?? null) ? $poll['task'] : [];
    $taskId = require_string($task, 'task_id', $command.' worker poll did not return task_id');
    $leaseOwner = is_string($task['lease_owner'] ?? null) && trim($task['lease_owner']) !== ''
        ? trim($task['lease_owner'])
        : $workerId;
    $attempt = is_numeric($task['workflow_task_attempt'] ?? null) ? (int) $task['workflow_task_attempt'] : 0;
    if ($attempt < 1) {
        throw new RuntimeException($command.' worker poll did not return workflow_task_attempt');
    }

    $requestedAt = now_iso();
    $control = request_json('POST', '/workflows/'.$workflowId.'/runs/'.$runId.'/'.$command, [
        'reason' => $reason,
        'request_id' => $workflowId.'-'.$command,
    ]);
    require_terminal_response($control, 'outcome', $terminalStatus, $command.' control-plane response did not expose terminal outcome');

    $showRun = request_json('GET', '/workflows/'.$workflowId.'/runs/'.$runId);
    require_terminal_response($showRun, 'status', $terminalStatus, $command.' describe-run response did not expose terminal status');

    $workerError = request_json('POST', '/worker/workflow-tasks/'.$taskId.'/history', [
        'lease_owner' => $leaseOwner,
        'workflow_task_attempt' => $attempt,
        'next_history_page_token' => base64_encode('0'),
    ], [409]);
    require_terminal_response($workerError, 'run_status', $terminalStatus, $command.' worker response did not expose terminal run_status');
    require_terminal_response($workerError, 'stop_reason', $expectedWorkerStopReason, $command.' worker response did not expose typed stop_reason');

    $callerError = request_json('POST', '/workflows/'.$workflowId.'/runs/'.$runId.'/query/currentState', [], [409]);
    require_terminal_response($callerError, 'reason', 'run_not_active', $command.' caller query did not expose run_not_active refusal');
    require_terminal_response($callerError, 'run_status', $terminalStatus, $command.' caller query did not expose terminal run_status');

    $history = request_json('GET', '/workflows/'.$workflowId.'/runs/'.$runId.'/history');
    $historyEvents = event_types($history);
    $terminalEvent = first_matching_event($historyEvents, [$expectedTerminalEvent]);
    if ($terminalEvent === '') {
        throw new RuntimeException($command.' history did not expose '.$expectedTerminalEvent);
    }

    return pass_scenario($scenarioId, [
        'workflow_id' => $workflowId,
        'run_id' => $runId,
        'request_surface' => 'server_api_run_targeted',
        $timestampField => $requestedAt,
        'terminal_status' => $terminalStatus,
        'worker_error_type' => (string) ($workerError['stop_reason'] ?? $expectedWorkerStopReason),
        'caller_error_type' => 'run_not_active_'.$terminalStatus,
        'control_plane_http_status' => $control['_http_status'] ?? null,
        'control_plane_outcome' => $control['outcome'] ?? null,
        'worker_protocol_reason' => $workerError['reason'] ?? null,
        'worker_protocol_stop_reason' => $workerError['stop_reason'] ?? null,
        'worker_protocol_run_status' => $workerError['run_status'] ?? null,
        'caller_reason' => $callerError['reason'] ?? null,
        'caller_run_status' => $callerError['run_status'] ?? null,
        'caller_message' => $callerError['message'] ?? null,
        'history_events' => $historyEvents,
        'terminal_history_event' => $terminalEvent,
        'run_closed_at' => $showRun['closed_at'] ?? ($workerError['run_closed_at'] ?? null),
        'public_surface_matrix' => [
            'server_api' => [
                'command_path' => '/api/workflows/{workflowId}/runs/{runId}/'.$command,
                'describe_path' => '/api/workflows/{workflowId}/runs/{runId}',
                'query_after_terminal_path' => '/api/workflows/{workflowId}/runs/{runId}/query/currentState',
                'terminal_status' => $terminalStatus,
            ],
            'worker_protocol' => [
                'history_after_terminal_path' => '/api/worker/workflow-tasks/{taskId}/history',
                'reason' => $workerError['reason'] ?? null,
                'stop_reason' => $workerError['stop_reason'] ?? null,
            ],
        ],
    ]);
}

function run_duplicate_start_policy_probe(): array
{
    $workflowId = 'workflow-lifecycle-duplicate-start-'.strtolower(bin2hex(random_bytes(4)));
    $workflowType = 'workflow.lifecycle.duplicate-start';
    $workerId = 'workflow-lifecycle-duplicate-start-worker-'.strtolower(bin2hex(random_bytes(4)));
    $startBody = [
        'workflow_id' => $workflowId,
        'workflow_type' => $workflowType,
        'task_queue' => LIFECYCLE_TERMINAL_TASK_QUEUE,
        'duplicate_policy' => 'fail',
        'input' => [
            'policy' => 'fail',
            'cell' => 'workflow_id_reuse_duplicate_start_policy',
        ],
    ];

    request_json('POST', '/worker/register', [
        'worker_id' => $workerId,
        'task_queue' => LIFECYCLE_TERMINAL_TASK_QUEUE,
        'runtime' => 'php',
        'supported_workflow_types' => [$workflowType],
    ]);

    $firstStart = request_json('POST', '/workflows', $startBody);
    $firstRunId = require_string($firstStart, 'run_id', 'duplicate-start first response did not include run_id');

    $duplicateStart = request_json('POST', '/workflows', $startBody, [409]);
    $duplicateStatus = is_numeric($duplicateStart['_http_status'] ?? null) ? (int) $duplicateStart['_http_status'] : 0;
    $typedError = trim(implode(' ', array_filter([
        is_string($duplicateStart['outcome'] ?? null) ? $duplicateStart['outcome'] : null,
        is_string($duplicateStart['reason'] ?? null) ? $duplicateStart['reason'] : null,
        is_string($duplicateStart['rejection_reason'] ?? null) ? $duplicateStart['rejection_reason'] : null,
    ], static fn (mixed $value): bool => is_string($value) && trim($value) !== '')));
    if ($typedError === '') {
        $typedError = $duplicateStatus > 0 ? 'http_'.$duplicateStatus : 'unknown_duplicate_start_result';
    }

    $runsAfterDuplicate = request_json('GET', '/workflows/'.$workflowId.'/runs');
    $runRowsAfterDuplicate = is_array($runsAfterDuplicate['runs'] ?? null) ? array_values($runsAfterDuplicate['runs']) : [];
    $runIdsAfterDuplicate = array_values(array_filter(array_map(
        static fn (mixed $run): string => is_array($run) && is_string($run['run_id'] ?? null) ? $run['run_id'] : '',
        $runRowsAfterDuplicate,
    )));
    $runCountAfterDuplicate = (int) ($runsAfterDuplicate['run_count'] ?? count($runRowsAfterDuplicate));

    if ($duplicateStatus < 400) {
        throw new RuntimeException('duplicate-start fail policy accepted duplicate request with HTTP '.($duplicateStatus > 0 ? (string) $duplicateStatus : 'unknown_status'));
    }
    if ($runCountAfterDuplicate !== 1) {
        throw new RuntimeException('duplicate-start fail policy left '.$runCountAfterDuplicate.' runs after duplicate request; expected exactly one run preserving '.$firstRunId.'; observed run ids: '.json_encode($runIdsAfterDuplicate, JSON_THROW_ON_ERROR));
    }
    if (count($runIdsAfterDuplicate) !== 1 || $runIdsAfterDuplicate[0] !== $firstRunId) {
        throw new RuntimeException('duplicate-start fail policy did not preserve only the first run id '.$firstRunId.'; observed run ids: '.json_encode($runIdsAfterDuplicate, JSON_THROW_ON_ERROR));
    }

    return pass_scenario('workflow_id_reuse_duplicate_start_policy', [
        'workflow_id' => $workflowId,
        'duplicate_policy' => 'fail',
        'first_start_outcome' => (string) ($firstStart['outcome'] ?? 'started'),
        'duplicate_start_outcome' => $duplicateStatus >= 400 ? 'refused_'.$typedError : 'accepted',
        'http_status_or_error_type' => trim(($duplicateStatus > 0 ? (string) $duplicateStatus : 'unknown_status').' '.$typedError),
        'first_start_http_status' => $firstStart['_http_status'] ?? null,
        'first_run_id' => $firstRunId,
        'duplicate_start_http_status' => $duplicateStatus > 0 ? $duplicateStatus : null,
        'duplicate_start_raw_outcome' => $duplicateStart['outcome'] ?? null,
        'duplicate_start_reason' => $duplicateStart['reason'] ?? null,
        'duplicate_start_rejection_reason' => $duplicateStart['rejection_reason'] ?? null,
        'duplicate_start_command_status' => $duplicateStart['command_status'] ?? null,
        'duplicate_start_message' => $duplicateStart['message'] ?? null,
        'run_count_after_duplicate' => $runCountAfterDuplicate,
        'run_ids_after_duplicate' => $runIdsAfterDuplicate,
        'duplicate_start_policy_enforcement' => 'refused_without_creating_or_replacing_run',
        'public_surface_matrix' => [
            'server_api' => [
                'start_path' => '/api/workflows',
                'runs_path' => '/api/workflows/{workflowId}/runs',
                'duplicate_policy_field' => 'duplicate_policy',
                'requested_duplicate_policy' => 'fail',
            ],
        ],
    ]);
}

function run_continue_as_new_probe(): array
{
    $workflowId = 'workflow-lifecycle-continue-as-new-'.strtolower(bin2hex(random_bytes(4)));
    $sideEffectKey = $workflowId.':successor-run-creation';

    request_json('POST', '/worker/register', [
        'worker_id' => LIFECYCLE_WORKER_ID,
        'task_queue' => LIFECYCLE_TASK_QUEUE,
        'runtime' => 'php',
        'supported_workflow_types' => [LIFECYCLE_WORKFLOW_TYPE],
    ]);

    $start = request_json('POST', '/workflows', [
        'workflow_id' => $workflowId,
        'workflow_type' => LIFECYCLE_WORKFLOW_TYPE,
        'task_queue' => LIFECYCLE_TASK_QUEUE,
        'input' => ['name' => 'Ada', 'side_effect_key' => $sideEffectKey],
    ]);
    $initialRunId = (string) ($start['run_id'] ?? '');
    if ($initialRunId === '') {
        throw new RuntimeException('workflow start response did not include run_id');
    }

    $poll = request_json('POST', '/worker/workflow-tasks/poll', [
        'worker_id' => LIFECYCLE_WORKER_ID,
        'task_queue' => LIFECYCLE_TASK_QUEUE,
    ]);
    $task = is_array($poll['task'] ?? null) ? $poll['task'] : [];
    $taskId = is_string($task['task_id'] ?? null) ? $task['task_id'] : '';
    $leaseOwner = is_string($task['lease_owner'] ?? null) ? $task['lease_owner'] : LIFECYCLE_WORKER_ID;
    $attempt = is_numeric($task['workflow_task_attempt'] ?? null) ? (int) $task['workflow_task_attempt'] : 0;
    if ($taskId === '' || $attempt < 1) {
        throw new RuntimeException('worker poll did not return a leased workflow task');
    }

    $completionBody = [
        'lease_owner' => $leaseOwner,
        'workflow_task_attempt' => $attempt,
        'commands' => [
            [
                'type' => 'continue_as_new',
                'workflow_type' => LIFECYCLE_WORKFLOW_TYPE,
                'arguments' => Serializer::serializeWithCodec('avro', ['Ada v2', $sideEffectKey]),
            ],
        ],
    ];
    $complete = request_json('POST', '/worker/workflow-tasks/'.$taskId.'/complete', $completionBody);
    if (($complete['recorded'] ?? null) !== true) {
        throw new RuntimeException('continue-as-new completion was not recorded');
    }

    $runs = request_json('GET', '/workflows/'.$workflowId.'/runs');
    $runRows = is_array($runs['runs'] ?? null) ? array_values($runs['runs']) : [];
    if (count($runRows) < 2) {
        throw new RuntimeException('continue-as-new did not create a visible successor run');
    }

    $continuedRun = $runRows[count($runRows) - 1];
    $continuedRunId = is_array($continuedRun) && is_string($continuedRun['run_id'] ?? null) ? $continuedRun['run_id'] : '';
    if ($continuedRunId === '' || $continuedRunId === $initialRunId) {
        throw new RuntimeException('continue-as-new successor run id was missing or not distinct');
    }

    $current = request_json('GET', '/workflows/'.$workflowId);
    $initialHistory = request_json('GET', '/workflows/'.$workflowId.'/runs/'.$initialRunId.'/history');
    $continuedHistory = request_json('GET', '/workflows/'.$workflowId.'/runs/'.$continuedRunId.'/history');
    $initialEvents = event_types($initialHistory);
    $continuedEvents = event_types($continuedHistory);
    $predecessorClosedEvent = first_matching_event($initialEvents, ['ContinuedAsNew', 'Completed', 'Closed']);
    $successorStartedEvent = first_matching_event($continuedEvents, ['WorkflowStarted', 'Started']);
    if ($predecessorClosedEvent === '' || $successorStartedEvent === '') {
        throw new RuntimeException('continue-as-new history did not expose predecessor closed and successor started events');
    }

    $duplicate = request_json('POST', '/worker/workflow-tasks/'.$taskId.'/complete', $completionBody, [409]);
    $runsAfterDuplicate = request_json('GET', '/workflows/'.$workflowId.'/runs');
    $runRowsAfterDuplicate = is_array($runsAfterDuplicate['runs'] ?? null) ? array_values($runsAfterDuplicate['runs']) : [];
    $successorCount = max(0, count($runRowsAfterDuplicate) - 1);

    $historyLinks = [
        '/api/workflows/'.$workflowId.'/runs/'.$initialRunId.'/history',
        '/api/workflows/'.$workflowId.'/runs/'.$continuedRunId.'/history',
    ];

    return [
        'continue_as_new_run_chain_visibility' => pass_scenario('continue_as_new_run_chain_visibility', [
            'workflow_id' => $workflowId,
            'initial_run_id' => $initialRunId,
            'continued_run_id' => $continuedRunId,
            'run_count' => (int) ($runsAfterDuplicate['run_count'] ?? count($runRowsAfterDuplicate)),
            'current_run_id' => (string) ($current['run_id'] ?? $continuedRunId),
            'run_numbers' => array_values(array_map(
                static fn (mixed $run): int => is_array($run) && is_numeric($run['run_number'] ?? null) ? (int) $run['run_number'] : 0,
                $runRowsAfterDuplicate,
            )),
            'run_chain_api_link' => '/api/workflows/'.$workflowId.'/runs',
            'duplicate_completion_http_status' => $duplicate['_http_status'] ?? null,
        ]),
        'continue_as_new_identity_and_history_continuity' => pass_scenario('continue_as_new_identity_and_history_continuity', [
            'workflow_id' => $workflowId,
            'history_events' => array_values(array_unique(array_merge($initialEvents, $continuedEvents))),
            'predecessor_closed_event' => $predecessorClosedEvent,
            'successor_started_event' => $successorStartedEvent,
            'history_api_links' => $historyLinks,
            'initial_run_id' => $initialRunId,
            'continued_run_id' => $continuedRunId,
            'initial_history_event_count' => count($initialEvents),
            'continued_history_event_count' => count($continuedEvents),
        ]),
        'continue_as_new_duplicate_side_effect_prevention' => pass_scenario('continue_as_new_duplicate_side_effect_prevention', [
            'workflow_id' => $workflowId,
            'side_effect_key' => $sideEffectKey,
            'expected_count' => 1,
            'observed_count' => $successorCount,
            'replay_or_restart_window' => 'duplicate_worker_completion_after_continue_as_new',
            'duplicate_completion_http_status' => $duplicate['_http_status'] ?? null,
            'duplicate_completion_reason' => $duplicate['reason'] ?? null,
            'successor_run_ids_after_duplicate' => array_values(array_map(
                static fn (mixed $run): string => is_array($run) && is_string($run['run_id'] ?? null) ? $run['run_id'] : '',
                array_slice($runRowsAfterDuplicate, 1),
            )),
        ]),
    ];
}

try {
    bootstrap_application($repoRoot);
    $scenarioResults = [];

    try {
        $scenarioResults += run_continue_as_new_probe();
    } catch (Throwable $throwable) {
        foreach ([
            'continue_as_new_run_chain_visibility',
            'continue_as_new_identity_and_history_continuity',
            'continue_as_new_duplicate_side_effect_prevention',
        ] as $scenarioId) {
            $scenarioResults[$scenarioId] = failure_scenario($scenarioId, $throwable);
        }
    }

    foreach ([
        [
            'command' => 'cancel',
            'scenario_id' => 'cancellation_public_surface_terminal_state',
            'workflow_type' => 'workflow.lifecycle.cancel',
            'timestamp_field' => 'cancel_requested_at',
            'terminal_status' => 'cancelled',
            'worker_stop_reason' => 'run_cancelled',
            'terminal_event' => 'WorkflowCancelled',
        ],
        [
            'command' => 'terminate',
            'scenario_id' => 'termination_public_surface_terminal_state',
            'workflow_type' => 'workflow.lifecycle.terminate',
            'timestamp_field' => 'terminate_requested_at',
            'terminal_status' => 'terminated',
            'worker_stop_reason' => 'run_terminated',
            'terminal_event' => 'WorkflowTerminated',
        ],
    ] as $terminalProbe) {
        try {
            $scenarioResults[$terminalProbe['scenario_id']] = run_terminal_surface_probe(
                $terminalProbe['command'],
                $terminalProbe['scenario_id'],
                $terminalProbe['workflow_type'],
                $terminalProbe['timestamp_field'],
                $terminalProbe['terminal_status'],
                $terminalProbe['worker_stop_reason'],
                $terminalProbe['terminal_event'],
            );
        } catch (Throwable $throwable) {
            $scenarioResults[$terminalProbe['scenario_id']] = failure_scenario($terminalProbe['scenario_id'], $throwable);
        }
    }

    try {
        $scenarioResults['workflow_id_reuse_duplicate_start_policy'] = run_duplicate_start_policy_probe();
    } catch (Throwable $throwable) {
        $scenarioResults['workflow_id_reuse_duplicate_start_policy'] = failure_scenario(
            'workflow_id_reuse_duplicate_start_policy',
            $throwable,
        );
    }

    try {
        $scenarioResults['workflow_timeout_terminal_state'] = run_workflow_timeout_terminal_state_probe();
    } catch (Throwable $throwable) {
        $scenarioResults['workflow_timeout_terminal_state'] = failure_scenario(
            'workflow_timeout_terminal_state',
            $throwable,
        );
    }

    $artifactSources = artifact_sources_from_env();
    write_json_file(evidence_path(), [
        'schema' => HOST_EVIDENCE_SCHEMA,
        'generated_at' => now_iso(),
        'evidence_source' => 'focused_published_server_workflow_lifecycle_host_probes',
        'execution_source' => HOST_EVIDENCE_SOURCE,
        'runner' => 'published-server-workflow-lifecycle-focused-host-probes',
        'artifact_versions' => artifact_versions_from_env(),
        'artifact_sources' => $artifactSources,
        'source_policy' => source_policy($artifactSources),
        'local_product_source_checkouts_used' => false,
        'runner_blocked' => false,
        'scenario_results' => $scenarioResults,
    ]);
} catch (Throwable $throwable) {
    write_json_file(evidence_path(), failure_evidence($throwable));
}
PHP
}

if should_run_focused_host_probes; then
  run_focused_host_probes
fi

RESULT_DIR="$result_dir" \
STARTED_AT="$started_at" \
MANIFEST_PATH="$manifest_path" \
DW_SERVER_IMAGE="${DW_SERVER_IMAGE:-}" \
DW_SERVER_VERSION="${DW_SERVER_VERSION:-}" \
DW_CLI_VERSION="${DW_CLI_VERSION:-}" \
DW_PYTHON_SDK_VERSION="${DW_PYTHON_SDK_VERSION:-}" \
DW_WORKFLOW_PHP_VERSION="${DW_WORKFLOW_PHP_VERSION:-}" \
DW_WATERLINE_VERSION="${DW_WATERLINE_VERSION:-}" \
node "$script_dir/workflow-lifecycle-published-artifacts.mjs"
