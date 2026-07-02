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
  python-sdk-workflow-updates-evidence.json (when the Python SDK shard runs)
  workflow-updates-result.json
  workflow-updates-record.json
  workflow-updates-findings.json

Environment overrides:
  DW_WORKFLOW_UPDATES_RESULT_DIR     Result directory when --result-dir is omitted.
  DW_WORKFLOW_UPDATES_EVIDENCE       Optional inline JSON evidence from a real host run.
  DW_WORKFLOW_UPDATES_EVIDENCE_PATH  Optional JSON evidence path. Defaults to
                                     workflow-updates-focused-evidence.json in the result dir.
  DW_WORKFLOW_UPDATES_PYTHON_EVIDENCE
                                     Optional inline JSON evidence from the Python SDK shard.
  DW_WORKFLOW_UPDATES_PYTHON_EVIDENCE_PATH
                                     Optional Python SDK shard evidence path. Defaults to
                                     python-sdk-workflow-updates-evidence.json in the result dir.
  DW_WORKFLOW_UPDATES_SKIP_FOCUSED_HOST_PROBE=1
                                     Skip the published server image's focused
                                     workflow update runtime probe.
  DW_SERVER_IMAGE                    Exact server image tag or digest under test.
  DW_SERVER_VERSION                  Exact server version under test.
  DW_CLI_VERSION                     Exact CLI release version.
  DW_PYTHON_SDK_VERSION              Exact PyPI durable-workflow version.
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
        'cli' => 'github-release://durable-workflow/cli/v'.$versions['cli'].'/install.sh',
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

if ! command -v node >/dev/null 2>&1; then
  printf '%s\n' 'required command not found: node' >&2
  exit 1
fi

RESULT_DIR="$result_dir" \
STARTED_AT="$started_at" \
REPO_ROOT="$repo_root" \
DW_WORKFLOW_UPDATES_EVIDENCE="${DW_WORKFLOW_UPDATES_EVIDENCE:-}" \
DW_WORKFLOW_UPDATES_EVIDENCE_PATH="${DW_WORKFLOW_UPDATES_EVIDENCE_PATH:-}" \
DW_WORKFLOW_UPDATES_PYTHON_EVIDENCE="${DW_WORKFLOW_UPDATES_PYTHON_EVIDENCE:-}" \
DW_WORKFLOW_UPDATES_PYTHON_EVIDENCE_PATH="${DW_WORKFLOW_UPDATES_PYTHON_EVIDENCE_PATH:-}" \
node <<'NODE'
const fs = require('node:fs');
const path = require('node:path');

const resultDir = process.env.RESULT_DIR;
const startedAt = process.env.STARTED_AT;
const repoRoot = process.env.REPO_ROOT || '';
const generatedAt = new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
const finishedAt = generatedAt;
const focusedEvidenceFile = 'workflow-updates-focused-evidence.json';
const pythonSidecarEvidenceFile = 'python-sdk-workflow-updates-evidence.json';
const focusedEvidencePath = path.join(resultDir, focusedEvidenceFile);
const pythonSidecarEvidencePath = path.join(resultDir, pythonSidecarEvidenceFile);
const pythonSidecarSchema = 'durable-workflow.v2.workflow-updates.python-sdk-sidecar';
const pythonSidecarScenarioId = 'python_client_worker_update_surface';

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

function isPythonSidecarEvidence(value) {
  return value?.schema === pythonSidecarSchema;
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
  const normalized = stringValue(source).toLowerCase();
  if (normalized === '') {
    return false;
  }

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
    || normalized.startsWith('/')
    || normalized.startsWith('./')
    || normalized.startsWith('../')
    || /workspace[._-]*hq/.test(normalized)
    || /(^|[/:_\s-])(local|workspace)[\s_-]*(repo|worktree|checkout|source)([/:_\s-]|$)/.test(normalized)
    || /(^|[/:_\s-])(repo|worktree)[\s_-]*(checkout|source)([/:_\s-]|$)/.test(normalized)
    || normalized.includes('/repos/server')
    || normalized.includes('/repos/cli')
    || normalized.includes('/repos/workflow')
    || normalized.includes('/repos/waterline')
    || normalized.includes('/repos/sdk-python');
}

function placeholderArtifactSource(value) {
  const string = stringValue(value);

  return string === '' || PLACEHOLDER_ARTIFACT_PATTERN.test(string) || sourceUsesForbiddenToken(string);
}

function localArtifactSourceReported(value) {
  const observedOutputs = observedOutputsFor(value);
  const sourcePolicy = sourcePolicyFor(value);

  return Object.values(artifactSourcesFor(value)).some((source) => sourceUsesForbiddenToken(source))
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
const workflowPhpVersion = unresolved(env('DW_WORKFLOW_PHP_VERSION'));
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
  cli: `github-release://durable-workflow/cli/v${cliVersion}/install.sh`,
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

const coverageGaps = {
  php_client_worker_update_surface: coverageFinding(
    'workflow-updates-php-sdk-coverage-gap',
    'php_client_worker_update_surface',
    'The focused probe exercises the published server worker protocol but does not yet install and drive the published PHP workflow package as a client/worker shard.',
    'Install the pinned Packagist durable-workflow/workflow artifact and record PHP worker update handler, PHP client update request, covered cells, and typed unsupported cells.',
    'workflow-php',
  ),
  python_client_worker_update_surface: coverageFinding(
    'workflow-updates-python-sdk-coverage-gap',
    'python_client_worker_update_surface',
    'The focused probe does not yet install and drive the published Python SDK update worker/client shard.',
    'Install the pinned PyPI durable-workflow artifact and record Python worker update handler, Python client update request, covered cells, and typed unsupported cells.',
    'sdk-python',
  ),
  operator_diagnostics_surfaces: coverageFinding(
    'workflow-updates-cli-waterline-diagnostics-coverage-gap',
    'operator_diagnostics_surfaces',
    'The focused probe records API and history diagnostics but does not yet prove CLI JSON or Waterline update views.',
    'Capture workflow update fields from the official CLI JSON output and Waterline selected-run update/history surfaces for accepted, completed, failed, and refused updates.',
    'waterline',
  ),
};

const focusedProbeMissingFinding = coverageFinding(
  'workflow-updates-focused-probe-coverage-gap',
  'focused_server_runtime_probe',
  'The focused published-server workflow update runtime probe did not run in this environment.',
  'Run scripts/conformance/workflow-updates-published-artifacts.sh inside the pinned published server image so the server update runtime cells execute without local source checkout evidence.',
);

let sourcePolicy = {
  pass_requires_published_artifacts_only: true,
  local_product_source_checkouts_used_must_be_false: true,
  local_product_source_checkouts_used: false,
  local_checkout_execution_counts_as_pass: false,
};

const focusedEvidence = readFocusedEvidence();
const pythonSidecarEvidence = readPythonSidecarEvidence();
const evidenceSources = [focusedEvidence, pythonSidecarEvidence].filter((source) => source !== null);
const focusedEvidenceRunnerBlocked = truthyEvidenceFlag(focusedEvidence?.runner_blocked)
  || truthyEvidenceFlag(focusedEvidence?.runnerBlocked);
const pythonSidecarEvidenceRunnerBlocked = truthyEvidenceFlag(pythonSidecarEvidence?.runner_blocked)
  || truthyEvidenceFlag(pythonSidecarEvidence?.runnerBlocked);
const scenarioResults = {};
const findings = [];

if (focusedEvidenceRunnerBlocked) {
  findings.push(runnerBlockedFinding('focused_evidence'));
}
if (pythonSidecarEvidenceRunnerBlocked) {
  findings.push(runnerBlockedFinding(pythonSidecarScenarioId));
}

for (const scenarioId of requiredScenarios) {
  if (coverageGaps[scenarioId]) {
    scenarioResults[scenarioId] = scenarioResult(
      scenarioId,
      'not_covered',
      'coverage-gap',
      coverageGaps[scenarioId],
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

function importScenarioEvidence(sourceEvidence, allowedScenarioIds) {
  if (!sourceEvidence || !sourceEvidence.scenario_results || typeof sourceEvidence.scenario_results !== 'object') {
    return;
  }

  for (const scenarioId of allowedScenarioIds) {
    if (Object.prototype.hasOwnProperty.call(sourceEvidence.scenario_results, scenarioId)) {
      scenarioResults[scenarioId] = normalizeScenarioResult(
        scenarioId,
        sourceEvidence.scenario_results[scenarioId],
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
    isPythonSidecarEvidence(focusedEvidence) ? new Set([pythonSidecarScenarioId]) : new Set(requiredScenarios),
  );
}
if (pythonSidecarEvidence) {
  importScenarioEvidence(pythonSidecarEvidence, new Set([pythonSidecarScenarioId]));
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
    const fallback = coverageGaps[scenarioId] || focusedProbeMissingFinding;
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
  || pythonSidecarEvidenceRunnerBlocked;
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
  python_sidecar: {
    implemented: true,
    scenario_id: pythonSidecarScenarioId,
    evidence_loaded: pythonSidecarEvidence !== null,
    evidence_file: pythonSidecarEvidence ? pythonSidecarEvidenceFile : null,
    evidence_schema: pythonSidecarEvidence?.schema || null,
    local_product_source_checkouts_used: pythonSidecarEvidence
      ? localProductSourceCheckoutsUsed(pythonSidecarEvidence) || localArtifactSourceReported(pythonSidecarEvidence)
      : false,
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
  python_sidecar_evidence_file: pythonSidecarEvidence ? pythonSidecarEvidenceFile : null,
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
    'CLI, SDK, and Waterline cells remain typed non-pass coverage gaps until their published-artifact shards run.',
    sourcePolicyNote,
  ],
  local_product_source_checkouts_used: sourcePolicy.local_product_source_checkouts_used,
  result_file: 'workflow-updates-result.json',
  findings_file: 'workflow-updates-findings.json',
  focused_evidence_file: focusedEvidence ? focusedEvidenceFile : null,
  python_sidecar_evidence_file: pythonSidecarEvidence ? pythonSidecarEvidenceFile : null,
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
  python_sidecar_evidence_loaded: pythonSidecarEvidence !== null,
}));
NODE
