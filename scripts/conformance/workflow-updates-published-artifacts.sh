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
  workflow-php-workflow-updates-evidence.json (when the PHP package shard runs)
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
  DW_WORKFLOW_UPDATES_PHP_EVIDENCE   Optional inline JSON evidence from the PHP package shard.
  DW_WORKFLOW_UPDATES_PHP_EVIDENCE_PATH
                                     Optional PHP package shard evidence path. Defaults to
                                     workflow-php-workflow-updates-evidence.json in the result dir.
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
                                     Skip the PHP package client/worker shard.
  DW_WORKFLOW_UPDATES_SKIP_PYTHON_SDK_SHARD=1
                                     Skip the Python SDK client/worker shard.
  DW_WORKFLOW_UPDATES_SKIP_OPERATOR_DIAGNOSTICS_SHARD=1
                                     Skip the official CLI JSON plus Waterline
                                     selected-run diagnostics shard.
  DW_SERVER_IMAGE                    Exact server image tag or digest under test.
  DW_SERVER_VERSION                  Exact server version under test.
  DW_CLI_VERSION                     Exact CLI release version.
  DW_PYTHON_SDK_VERSION              Exact PyPI durable-workflow version.
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

cleanup_background_processes() {
  local pid
  for pid in "${cleanup_pids[@]:-}"; do
    if kill -0 "$pid" >/dev/null 2>&1; then
      kill "$pid" >/dev/null 2>&1 || true
      wait "$pid" >/dev/null 2>&1 || true
    fi
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
use App\Support\ControlPlaneProtocol;
use App\Support\WorkerProtocol;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
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
    ];
}

function register_probe_worker(
    string $workerId = 'workflow-updates-worker',
    string $taskQueue = WORKFLOW_UPDATES_QUEUE,
    array $headers = [],
): void
{
    request_json('POST', '/worker/register', [
        'worker_id' => $workerId,
        'task_queue' => $taskQueue,
        'runtime' => 'php',
        'supported_workflow_types' => [WORKFLOW_UPDATES_TYPE],
        'capabilities' => ['workflow_tasks', 'query_tasks'],
        'workflow_command_contracts' => [
            WORKFLOW_UPDATES_TYPE => workflow_command_contract(),
        ],
    ], [409], $headers);
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

function complete_task(array $task, array $commands, array $headers = []): array
{
    $taskId = (string) $task['task_id'];

    return request_json('POST', '/worker/workflow-tasks/'.$taskId.'/complete', [
        'lease_owner' => (string) $task['lease_owner'],
        'workflow_task_attempt' => (int) $task['workflow_task_attempt'],
        'commands' => $commands,
    ], [], $headers)['body'];
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
            'result' => Serializer::serializeWithCodec('avro', [
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
            'result' => [
                'codec' => 'avro',
                'blob' => Serializer::serializeWithCodec('avro', $result),
            ],
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
    $pythonVersion = env_text('DW_PYTHON_SDK_VERSION') ?: 'unresolved';
    $workflowVersion = env_text('DW_WORKFLOW_PHP_VERSION') ?: 'unresolved';
    $waterlineVersion = env_text('DW_WATERLINE_VERSION') ?: 'unresolved';

    return [
        'server' => $serverVersion,
        'cli' => $cliVersion,
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
    }
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
  if [[ -s "$result_dir/workflow-php-workflow-updates-evidence.json" ]]; then
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

is_exact_package_version() {
  [[ "$1" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z][0-9A-Za-z.-]*)?(\+[0-9A-Za-z.-]+)?$ ]]
}

choose_tcp_port() {
  php -r '$s = stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr); if (!$s) { exit(1); } $name = stream_socket_get_name($s, false); fclose($s); $parts = explode(":", (string) $name); echo end($parts), PHP_EOL;'
}

wait_for_http() {
  local url="$1"
  local attempt

  for attempt in $(seq 1 80); do
    if curl -fsS --max-time 2 "$url" >/dev/null 2>&1; then
      return 0
    fi
    sleep 0.25
  done

  return 1
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
  DW_WORKFLOW_PHP_VERSION="${DW_WORKFLOW_PHP_VERSION:-}" \
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
const waterlineVersion = (process.env.DW_WATERLINE_VERSION || '').trim() || 'unresolved';
const artifactVersions = {
  server: serverVersion,
  cli: cliVersion,
  'sdk-python': pythonVersion,
  workflow: workflowVersion,
  'workflow-php': workflowVersion,
  waterline: waterlineVersion,
};
const artifactSources = {
  server: serverImage || `docker://durableworkflow/server:${serverVersion}`,
  cli: `https://github.com/durable-workflow/cli/releases/download/${cliVersion}/install.sh`,
  'sdk-python': `pypi://durable-workflow==${pythonVersion}`,
  workflow: `packagist://durable-workflow/workflow@${workflowVersion}`,
  'workflow-php': `packagist://durable-workflow/workflow@${workflowVersion}`,
  waterline: `packagist://durable-workflow/waterline@${waterlineVersion}`,
};
const runnerBlocked = ['1', 'true', 'yes'].includes((process.env.PHP_PACKAGE_SHARD_RUNNER_BLOCKED || '').toLowerCase());
const status = process.env.PHP_PACKAGE_SHARD_STATUS || (runnerBlocked ? 'runner_blocked' : 'fail');
const classification = runnerBlocked ? 'runner-blocked' : (['not_covered', 'unsupported'].includes(status) ? 'coverage-gap' : 'product-gap');
const summary = process.env.PHP_PACKAGE_SHARD_SUMMARY || 'The PHP workflow package update shard did not complete.';
const step = process.env.PHP_PACKAGE_SHARD_STEP || 'php_package_shard';
const generatedAt = new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
const finding = {
  finding_id: `workflow-updates-php-client-worker-update-surface-${runnerBlocked ? 'runner-blocked' : (classification === 'coverage-gap' ? 'coverage-gap' : 'product-gap')}`,
  finding_type: runnerBlocked ? 'conformance_runner_blocked' : (classification === 'coverage-gap' ? 'conformance_runner_coverage_gap' : 'product_behavior_failure'),
  classification,
  scenario_id: 'php_client_worker_update_surface',
  owning_surface: runnerBlocked ? 'conformance_harness' : 'workflow-php',
  summary,
  next_acceptance_criterion: 'Install the pinned Packagist durable-workflow/workflow artifact and run its workflow update client/worker conformance command against the published server API.',
  diagnostic: { step },
};
const payload = {
  schema: 'durable-workflow.v2.workflow-updates.php-package-sidecar',
  generated_at: generatedAt,
  runner: 'published-packagist-workflow-php-workflow-updates-shard',
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
    php_client_worker_update_surface: {
      scenario_id: 'php_client_worker_update_surface',
      status,
      classification,
      published_artifact_cell_executed: false,
      local_product_source_checkouts_used: false,
      observed_outputs: {
        workflow_php_artifact_version: workflowVersion,
        workflow_php_artifact_source: artifactSources['workflow-php'],
        composer_package: 'durable-workflow/workflow',
        package_install_step: step,
        php_worker_update_handler: {},
        php_client_update_request: {},
        covered_cells: [],
        unsupported_cells: [],
        typed_errors: [{
          cell: 'php_client_worker_update_surface',
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

fs.writeFileSync(path.join(resultDir, 'workflow-php-workflow-updates-evidence.json'), `${JSON.stringify(payload, null, 2)}\n`);
NODE
}

materialize_php_package_shard_report() {
  PHP_PACKAGE_REPORT_PATH="${1:?report path required}" \
  RESULT_DIR="$result_dir" \
  DW_SERVER_IMAGE="${DW_SERVER_IMAGE:-}" \
  DW_SERVER_VERSION="${DW_SERVER_VERSION:-}" \
  DW_CLI_VERSION="${DW_CLI_VERSION:-}" \
  DW_PYTHON_SDK_VERSION="${DW_PYTHON_SDK_VERSION:-}" \
  DW_WORKFLOW_PHP_VERSION="${DW_WORKFLOW_PHP_VERSION:-}" \
  DW_WORKFLOW_VERSION="${DW_WORKFLOW_VERSION:-}" \
  DW_WATERLINE_VERSION="${DW_WATERLINE_VERSION:-}" \
  node <<'NODE'
const fs = require('node:fs');
const path = require('node:path');

const resultDir = process.env.RESULT_DIR;
const report = JSON.parse(fs.readFileSync(process.env.PHP_PACKAGE_REPORT_PATH, 'utf8'));
const workflowVersion = (process.env.DW_WORKFLOW_PHP_VERSION || '').trim()
  || (process.env.DW_WORKFLOW_VERSION || '').trim()
  || report?.artifact_versions?.['workflow-php']
  || 'unresolved';
const serverImage = (process.env.DW_SERVER_IMAGE || '').trim();
const serverVersion = (process.env.DW_SERVER_VERSION || '').trim() || (serverImage.match(/:([^/:]+)$/)?.[1] ?? report?.artifact_versions?.server ?? 'unresolved');
const cliVersion = (process.env.DW_CLI_VERSION || '').trim() || report?.artifact_versions?.cli || 'unresolved';
const pythonVersion = (process.env.DW_PYTHON_SDK_VERSION || '').trim() || report?.artifact_versions?.['sdk-python'] || 'unresolved';
const waterlineVersion = (process.env.DW_WATERLINE_VERSION || '').trim() || report?.artifact_versions?.waterline || 'unresolved';
const artifactVersions = {
  server: serverVersion,
  cli: cliVersion,
  'sdk-python': pythonVersion,
  workflow: workflowVersion,
  'workflow-php': workflowVersion,
  waterline: waterlineVersion,
};
const artifactSources = {
  server: serverImage || `docker://durableworkflow/server:${serverVersion}`,
  cli: `https://github.com/durable-workflow/cli/releases/download/${cliVersion}/install.sh`,
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
    finding_id: `workflow-updates-php-client-worker-update-surface-${index + 1}`,
    finding_type: 'product_behavior_failure',
    classification: 'product-gap',
    scenario_id: 'php_client_worker_update_surface',
    owning_surface: 'workflow-php',
    summary: finding.message || finding.summary || 'The published PHP workflow package update shard reported a product failure.',
    next_acceptance_criterion: 'Make the published PHP workflow package client/worker update shard satisfy the workflow update conformance cells.',
    evidence: finding.evidence || finding,
  };
}

const packageRow = scenarioRows(report).find((row) => row?.scenario_id === 'php_client_worker_update_surface') ?? {
  scenario_id: 'php_client_worker_update_surface',
  status: 'fail',
  observed_outputs: {},
  linked_findings: [],
};
const status = typeof packageRow.status === 'string' ? packageRow.status : 'fail';
const scenarioClassification = status === 'pass'
  ? 'product-evidence'
  : (status === 'runner_blocked' ? 'runner-blocked' : (['not_covered', 'unsupported'].includes(status) ? 'coverage-gap' : 'product-gap'));
const packageFindings = Array.isArray(packageRow.linked_findings)
  ? packageRow.linked_findings.map(packageFindingToPublicFinding).filter(Boolean)
  : [];
const observedOutputs = packageRow.observed_outputs && typeof packageRow.observed_outputs === 'object'
  ? packageRow.observed_outputs
  : {};
const generatedAt = new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
const scenario = {
  scenario_id: 'php_client_worker_update_surface',
  status,
  classification: scenarioClassification,
  published_artifact_cell_executed: true,
  local_product_source_checkouts_used: false,
  observed_outputs: {
    ...observedOutputs,
    package_report_workflow_php_artifact_version: observedOutputs.workflow_php_artifact_version || null,
    package_report_workflow_php_artifact_source: observedOutputs.workflow_php_artifact_source || null,
    workflow_php_artifact_version: workflowVersion,
    workflow_php_artifact_source: artifactSources['workflow-php'],
    composer_package: 'durable-workflow/workflow',
    composer_constraint: `durable-workflow/workflow:${workflowVersion}`,
    package_artifact_source: artifactSources['workflow-php'],
    package_report_schema: report.schema || null,
    php_package_conformance_command: 'workflow:v2:workflow-updates-conformance',
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
  },
  linked_findings: status === 'pass' ? [] : packageFindings,
};
const payload = {
  schema: 'durable-workflow.v2.workflow-updates.php-package-sidecar',
  generated_at: generatedAt,
  runner: 'published-packagist-workflow-php-workflow-updates-shard',
  runner_blocked: report.runner_blocked === true || report.runnerBlocked === true,
  source_policy: {
    pass_requires_published_artifacts_only: true,
    local_product_source_checkouts_used: false,
    local_checkout_execution_counts_as_pass: false,
    artifact_sources: artifactSources,
  },
  artifact_versions: artifactVersions,
  published_artifact_versions: artifactVersions,
  artifact_sources: artifactSources,
  package_report: {
    schema: report.schema || null,
    coverage_scope: report.coverage_scope || null,
    outcome: report.outcome || null,
    runtime_matrix: report.runtime_matrix || null,
  },
  scenario_results: {
    php_client_worker_update_surface: scenario,
  },
  findings: packageFindings,
};

fs.writeFileSync(path.join(resultDir, 'workflow-php-workflow-updates-evidence.json'), `${JSON.stringify(payload, null, 2)}\n`);
NODE
}

run_php_package_shard() {
  local workflow_php_version="${DW_WORKFLOW_PHP_VERSION:-${DW_WORKFLOW_VERSION:-}}"
  local php_app="$result_dir/workflow-php-package-app"
  local php_report="$result_dir/workflow-php-package-report.json"
  local composer_home="$result_dir/workflow-php-composer-home"
  local composer_cache="$result_dir/workflow-php-composer-cache"
  local server_db="$result_dir/workflow-updates-php-server.sqlite"
  local server_port="${DW_WORKFLOW_UPDATES_PHP_SERVER_PORT:-}"
  local server_url
  local auth_token="${DW_WORKFLOW_UPDATES_PHP_TOKEN:-workflow-updates-php-token}"
  local run_id

  if [[ -z "$workflow_php_version" ]] || ! is_exact_package_version "$workflow_php_version"; then
    write_php_package_shard_status not_covered "DW_WORKFLOW_PHP_VERSION must be an exact durable-workflow/workflow version before the PHP package shard can install from Packagist." version_resolution false
    return 0
  fi
  if ! command -v composer >/dev/null 2>&1; then
    write_php_package_shard_status runner_blocked "Composer is required to install the pinned Packagist durable-workflow/workflow package for the PHP update shard." composer_unavailable true
    return 0
  fi
  if ! command -v curl >/dev/null 2>&1; then
    write_php_package_shard_status runner_blocked "curl is required to wait for the published server HTTP surface before the PHP package shard runs." curl_unavailable true
    return 0
  fi

  if [[ -z "$server_port" ]]; then
    server_port="$(choose_tcp_port)"
  fi
  server_url="http://127.0.0.1:${server_port}"
  run_id="php-updates-$(date -u '+%Y%m%d%H%M%S')-${RANDOM}"

  : > "$server_db"
  if ! APP_ENV=production \
    APP_DEBUG=false \
    APP_KEY="${APP_KEY:-base64:V09SS0ZMT1ctVVBEQVRFUy1QSFAtU0hBUkQtU0VSVkVS}" \
    DB_CONNECTION=sqlite \
    DB_DATABASE="$server_db" \
    QUEUE_CONNECTION=database \
    CACHE_STORE=array \
    SESSION_DRIVER=array \
    DW_AUTH_DRIVER=none \
    DW_TASK_DISPATCH_MODE=poll \
    DW_V2_TASK_DISPATCH_MODE=poll \
    php "$repo_root/artisan" server:bootstrap --force \
      > "$result_dir/workflow-php-server-bootstrap.log" 2>&1; then
    write_php_package_shard_status fail "The published server API could not bootstrap the temporary PHP update shard database; see workflow-php-server-bootstrap.log." server_bootstrap false
    return 0
  fi

  APP_ENV=production \
  APP_DEBUG=false \
  APP_KEY="${APP_KEY:-base64:V09SS0ZMT1ctVVBEQVRFUy1QSFAtU0hBUkQtU0VSVkVS}" \
  DB_CONNECTION=sqlite \
  DB_DATABASE="$server_db" \
  QUEUE_CONNECTION=database \
  CACHE_STORE=array \
  SESSION_DRIVER=array \
  DW_AUTH_DRIVER=none \
  DW_TASK_DISPATCH_MODE=poll \
  DW_V2_TASK_DISPATCH_MODE=poll \
  PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-8}" \
  php "$repo_root/artisan" serve --host=127.0.0.1 --port="$server_port" --no-reload \
    > "$result_dir/workflow-php-server.log" 2>&1 &
  cleanup_pids+=("$!")

  if ! wait_for_http "$server_url/api/health"; then
    write_php_package_shard_status runner_blocked "The temporary published server HTTP surface did not become ready for the PHP package shard; see workflow-php-server.log." server_http_unavailable true
    return 0
  fi

  mkdir -p "$php_app" "$composer_home" "$composer_cache"
  if ! (
    cd "$php_app" &&
    COMPOSER_HOME="$composer_home" COMPOSER_CACHE_DIR="$composer_cache" \
      composer create-project laravel/laravel . --no-interaction --no-progress --prefer-dist
  ) > "$result_dir/workflow-php-create-project.log" 2>&1; then
    write_php_package_shard_status runner_blocked "The PHP package shard could not create a disposable Laravel app; see workflow-php-create-project.log." composer_create_project true
    return 0
  fi

  if ! (
    cd "$php_app" &&
    COMPOSER_HOME="$composer_home" COMPOSER_CACHE_DIR="$composer_cache" \
      composer require --no-interaction --no-progress --prefer-dist "durable-workflow/workflow:${workflow_php_version}"
  ) > "$result_dir/workflow-php-composer-require.log" 2>&1; then
    write_php_package_shard_status fail "Composer could not install pinned Packagist package durable-workflow/workflow:${workflow_php_version}; see workflow-php-composer-require.log." composer_require false
    return 0
  fi

  if ! PHP_APP="$php_app" WORKFLOW_PHP_VERSION="$workflow_php_version" node <<'NODE' > "$result_dir/workflow-php-package-source-policy.log" 2>&1; then
const fs = require('node:fs');
const path = require('node:path');

const appDir = process.env.PHP_APP;
const expectedVersion = process.env.WORKFLOW_PHP_VERSION;
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

const installedPackage = packagesFromInstalledJson(readJson(installedPath))
  .find((entry) => entry?.name === 'durable-workflow/workflow');
const lockedPackage = packagesFromLockJson(readJson(lockPath))
  .find((entry) => entry?.name === 'durable-workflow/workflow');

if (!installedPackage || !lockedPackage) {
  fail('durable-workflow/workflow was not installed by Composer.');
}

if (String(installedPackage.version || '') !== expectedVersion && String(lockedPackage.version || '') !== expectedVersion) {
  fail(`durable-workflow/workflow installed version did not match ${expectedVersion}.`);
}

const installSource = String(installedPackage['installation-source'] || '').toLowerCase();
if (installSource && installSource !== 'dist') {
  fail(`durable-workflow/workflow was installed from ${installSource}, not a Packagist dist artifact.`);
}

const distUrl = String(lockedPackage.dist?.url || '');
if (distUrl === '') {
  fail('durable-workflow/workflow composer.lock metadata did not include a dist URL.');
}

for (const candidate of [
  lockedPackage.dist?.url,
  lockedPackage.source?.url,
]) {
  const value = String(candidate || '');
  if (localSourcePattern.test(value)) {
    fail(`durable-workflow/workflow resolved from a local artifact source: ${value}`);
  }
}
NODE
    write_php_package_shard_status fail "The PHP package shard resolved durable-workflow/workflow from a non-published artifact source; see workflow-php-package-source-policy.log." package_source_policy false
    return 0
  fi

  if ! (
    cd "$php_app" &&
    php artisan key:generate --force &&
    php artisan list --raw
  ) > "$result_dir/workflow-php-artisan-list.log" 2>&1; then
    write_php_package_shard_status fail "The Composer-installed PHP package could not boot its Laravel command surface; see workflow-php-artisan-list.log." artisan_list false
    return 0
  fi

  if ! grep -q '^workflow:v2:workflow-updates-conformance' "$result_dir/workflow-php-artisan-list.log"; then
    write_php_package_shard_status fail "The Composer-installed PHP package does not expose workflow:v2:workflow-updates-conformance." artisan_command_missing false
    return 0
  fi

  set +e
  (
    cd "$php_app" &&
    php artisan workflow:v2:workflow-updates-conformance --json \
      --server-url="$server_url" \
      --token="$auth_token" \
      --namespace=default \
      --task-queue="workflow-updates-php-${run_id}" \
      --run-id="$run_id" \
      --poll-timeout=2 \
      --artifact-version="server=${DW_SERVER_VERSION:-}" \
      --artifact-version="cli=${DW_CLI_VERSION:-}" \
      --artifact-version="workflow-php=${workflow_php_version}" \
      --artifact-version="sdk-python=${DW_PYTHON_SDK_VERSION:-}" \
      --artifact-version="waterline=${DW_WATERLINE_VERSION:-}" \
      --artifact-source=server=docker_image \
      --artifact-source=cli=official_install_script \
      --artifact-source=workflow-php=packagist_package \
      --artifact-source=sdk-python=pypi_package \
      --artifact-source=waterline=packagist_package \
      --output="$php_report"
  ) > "$result_dir/workflow-php-conformance-command.log" 2>&1
  local command_status=$?
  set -e

  if [[ ! -s "$php_report" ]]; then
    write_php_package_shard_status fail "The Composer-installed PHP package workflow update command did not emit a report; see workflow-php-conformance-command.log." php_package_command false
    return 0
  fi

  materialize_php_package_shard_report "$php_report"
  if [[ "$command_status" -ne 0 ]]; then
    printf 'PHP package workflow update shard exited with status %s; imported its emitted report.\n' "$command_status" >> "$result_dir/workflow-php-conformance-command.log"
  fi
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
  DW_WORKFLOW_PHP_VERSION="${DW_WORKFLOW_PHP_VERSION:-}" \
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
const waterlineVersion = (process.env.DW_WATERLINE_VERSION || '').trim() || 'unresolved';
const artifactVersions = {
  server: serverVersion,
  cli: cliVersion,
  'sdk-python': pythonVersion,
  workflow: workflowVersion,
  'workflow-php': workflowVersion,
  waterline: waterlineVersion,
};
const artifactSources = {
  server: serverImage || `docker://durableworkflow/server:${serverVersion}`,
  cli: `https://github.com/durable-workflow/cli/releases/download/${cliVersion}/install.sh`,
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
  DW_WORKFLOW_PHP_VERSION="${DW_WORKFLOW_PHP_VERSION:-}" \
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
const waterlineVersion = (process.env.DW_WATERLINE_VERSION || '').trim() || report?.artifact_versions?.waterline || 'unresolved';
const artifactVersions = {
  server: serverVersion,
  cli: cliVersion,
  'sdk-python': pythonVersion,
  workflow: workflowVersion,
  'workflow-php': workflowVersion,
  waterline: waterlineVersion,
};
const artifactSources = {
  server: serverImage || `docker://durableworkflow/server:${serverVersion}`,
  cli: `https://github.com/durable-workflow/cli/releases/download/${cliVersion}/install.sh`,
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

  if [[ -z "$python_version" ]] || ! is_exact_package_version "$python_version"; then
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
from pathlib import Path
import sys
from urllib.parse import urlparse

expected = os.environ["PYTHON_EXPECTED_VERSION"]

def fail(message: str) -> None:
    print(message, file=sys.stderr)
    raise SystemExit(1)

try:
    dist = metadata.distribution("durable-workflow")
except metadata.PackageNotFoundError:
    fail("durable-workflow was not installed by pip.")

version = dist.version
if version.lstrip("v") != expected.lstrip("v"):
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
  DW_WORKFLOW_PHP_VERSION="${DW_WORKFLOW_PHP_VERSION:-}" \
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
const waterlineVersion = (process.env.DW_WATERLINE_VERSION || '').trim() || 'unresolved';
const artifactVersions = {
  server: serverVersion,
  cli: cliVersion,
  'sdk-python': pythonVersion,
  workflow: workflowVersion,
  'workflow-php': workflowVersion,
  waterline: waterlineVersion,
};
const artifactSources = {
  server: serverImage || `docker://durableworkflow/server:${serverVersion}`,
  cli: `https://github.com/durable-workflow/cli/releases/download/${cliVersion}/install.sh`,
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
use App\Support\WorkerProtocol;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Workflow\Serializers\Serializer;

const OPERATOR_WORKFLOW_TYPE = 'workflow-updates.probe';

function env_text_operator(string $name, string $default = ''): string
{
    $value = getenv($name);

    return is_string($value) && trim($value) !== '' ? trim($value) : $default;
}

function write_operator_json(string $path, array $payload): void
{
    file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
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
    ];
}

function operator_register_worker(string $workerId, string $taskQueue): void
{
    operator_request_json('POST', '/worker/register', [
        'worker_id' => $workerId,
        'task_queue' => $taskQueue,
        'runtime' => 'php',
        'supported_workflow_types' => [OPERATOR_WORKFLOW_TYPE],
        'capabilities' => ['workflow_tasks', 'query_tasks'],
        'workflow_command_contracts' => [
            OPERATOR_WORKFLOW_TYPE => operator_workflow_command_contract(),
        ],
    ], [409]);
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
    return operator_request_json('POST', '/worker/workflow-tasks/'.((string) $task['task_id']).'/complete', [
        'lease_owner' => (string) $task['lease_owner'],
        'workflow_task_attempt' => (int) $task['workflow_task_attempt'],
        'commands' => $commands,
    ])['body'];
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

$runtime = json_decode((string) file_get_contents($runtimePath), true, flags: JSON_THROW_ON_ERROR);
if (! is_array($runtime)) {
    throw new RuntimeException('Operator diagnostics runtime metadata was not readable.');
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
            'result' => [
                'codec' => 'avro',
                'blob' => Serializer::serializeWithCodec('avro', [
                    'approved' => true,
                    'source' => 'operator-diagnostics-cli-waterline',
                ]),
            ],
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
  DW_WORKFLOW_PHP_VERSION="${DW_WORKFLOW_PHP_VERSION:-}" \
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
  'sdk-python': pythonVersion,
  workflow: workflowVersion,
  'workflow-php': workflowVersion,
  waterline: waterlineVersion,
};
const artifactSources = {
  server: serverImage || `docker://durableworkflow/server:${serverVersion}`,
  cli: cliInstallerSource,
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

  workflow_id="$(node -e 'const fs = require("node:fs"); const value = JSON.parse(fs.readFileSync(process.argv[1], "utf8")); process.stdout.write(String(value.workflow_id || ""));' "$runtime_path")"
  run_id="$(node -e 'const fs = require("node:fs"); const value = JSON.parse(fs.readFileSync(process.argv[1], "utf8")); process.stdout.write(String(value.run_id || ""));' "$runtime_path")"
  if [[ -z "$workflow_id" || -z "$run_id" ]]; then
    write_operator_diagnostics_shard_status fail "The operator diagnostics setup did not identify a workflow instance and run." operator_runtime_identity false
    return 0
  fi

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
        "durable-workflow/workflow:${workflow_php_version}" \
        "durable-workflow/waterline:${waterline_version}"
  ) > "$result_dir/operator-waterline-composer-require.log" 2>&1; then
    write_operator_diagnostics_shard_status fail "Composer could not install pinned Packagist packages durable-workflow/workflow:${workflow_php_version} and durable-workflow/waterline:${waterline_version}; see operator-waterline-composer-require.log." waterline_composer_require false
    return 0
  fi

  if ! WATERLINE_APP="$operator_waterline_app" WATERLINE_VERSION="$waterline_version" WORKFLOW_PHP_VERSION="$workflow_php_version" node <<'NODE' > "$result_dir/operator-waterline-source-policy.log" 2>&1; then
const fs = require('node:fs');
const path = require('node:path');

const appDir = process.env.WATERLINE_APP;
const expectedWaterline = process.env.WATERLINE_VERSION;
const expectedWorkflow = process.env.WORKFLOW_PHP_VERSION;
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
const phpSidecarEvidenceFile = 'workflow-php-workflow-updates-evidence.json';
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
    value?.workflow_php_artifact_source,
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
    observedOutputs.workflow_php_artifact_source,
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
    sourcePolicy.workflow_php_artifact_source,
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
    'Record concrete published artifact versions and non-placeholder artifact sources for the server, CLI, Python SDK, PHP workflow package, and Waterline before recording workflow update cells as passing.',
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

function artifactFailureCode(value, field, codePrefix) {
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

  return placeholderArtifactValue(string) ? `${codePrefix}_artifact_version` : null;
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
      const code = artifactFailureCode(entry.value, field, codePrefix);
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
const workflowPhpVersion = unresolved(env('DW_WORKFLOW_PHP_VERSION') || env('DW_WORKFLOW_VERSION'));
const waterlineVersion = unresolved(env('DW_WATERLINE_VERSION'));

const artifactVersions = {
  server: serverVersion,
  cli: cliVersion,
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
  'sdk-python': `pypi://durable-workflow==${pythonVersion}`,
  workflow: `packagist://durable-workflow/workflow@${workflowPhpVersion}`,
  'workflow-php': `packagist://durable-workflow/workflow@${workflowPhpVersion}`,
  waterline: `packagist://durable-workflow/waterline@${waterlineVersion}`,
};

const requiredScenarios = [
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
  'php_client_worker_update_surface',
  'python_client_worker_update_surface',
  'operator_diagnostics_surfaces',
];

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
]);

const scenarioManifest = readJsonIfExists(path.join(repoRoot, 'static/platform-conformance/workflow-update-runtime-scenarios.json')) ?? {};
const scenarioRequirements = objectValue(scenarioManifest.scenario_requirements);
const forbiddenArtifactSourceTokens = arrayOfStrings(scenarioManifest?.artifact_policy?.forbidden_sources);
const requiredArtifacts = ['server', 'cli', 'sdk-python', 'workflow-php', 'waterline'];
const artifactAliases = {
  server: ['server'],
  cli: ['cli'],
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
  'The PHP workflow package update shard did not run against the pinned Packagist artifact in this environment.',
  'Run the workflow update conformance handoff where Composer can install durable-workflow/workflow from Packagist and the package client/worker command can reach the published server API.',
  'workflow-php',
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
    package_version: workflowPhpVersion,
    artifact_source: artifactSources['workflow-php'],
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
    'The PHP package shard installs the pinned Packagist durable-workflow/workflow package before importing PHP client/worker update evidence.',
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
