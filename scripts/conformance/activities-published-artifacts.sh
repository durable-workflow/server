#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage: activities-published-artifacts.sh [--result-dir DIR|--result-dir=DIR] [--keep-run-root[=1|true]]

Writes a scenario-level activities conformance result for published artifacts.

The runner writes these files to the result directory:
  pins.json
  run-metadata.json
  activities-result.json
  activities-record.json
  activities-findings.json

Environment overrides:
  DW_ACTIVITIES_RESULT_DIR              Result directory. Defaults to run root.
  DW_ACTIVITIES_RUN_ROOT                Scratch directory. Defaults to mktemp.
  DW_ACTIVITIES_KEEP_RUN_ROOT=1         Keep scratch directory after success.
  DW_ACTIVITIES_SCENARIO_MANIFEST       Scenario manifest path. Defaults to the server static mirror.
  DW_ACTIVITIES_ARTIFACT_INSTALL_EVIDENCE
                                         JSON proof that each published artifact was downloaded/installed.
                                         Defaults to artifact-install-evidence.json in the result directory.
  DW_ACTIVITIES_EVIDENCE                Optional JSON activity evidence from a real host matrix run.
  DW_ACTIVITIES_EVIDENCE_PATH           Optional path to JSON activity evidence from a real host matrix run.
  DW_ACTIVITIES_SKIP_FOCUSED_HOST_PROBE=1
                                         Skip the published server container's focused activity host probe.
  DW_ACTIVITIES_PYTHON_BIN              Optional Python executable for the sdk-python focused activity shard.
                                         Defaults to a run-root venv installed from DW_PYTHON_SDK_VERSION when
                                         python3 and pip are available, then falls back to python3.
  DW_ACTIVITIES_RUNNER_SOURCE           Optional exact image source for the runner process. Defaults to
                                         DW_SERVER_IMAGE when the handoff runs from the release image root.
  DW_SERVER_IMAGE                       Exact server image tag or digest to test.
  DW_SERVER_VERSION                     Exact patch server Docker tag; required for digest-only DW_SERVER_IMAGE.
  DW_CLI_VERSION                        Exact CLI release version.
  DW_PYTHON_SDK_VERSION                 Exact PyPI durable-workflow version.
  DW_WORKFLOW_PHP_VERSION               Exact Composer durable-workflow/workflow version.
  DW_WATERLINE_VERSION                  Exact Waterline artifact version.
USAGE
}

keep_run_root="${DW_ACTIVITIES_KEEP_RUN_ROOT:-0}"
result_dir="${DW_ACTIVITIES_RESULT_DIR:-}"

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
    --keep-run-root)
      keep_run_root=1
      shift
      ;;
    --keep-run-root=*)
      keep_run_root="${1#--keep-run-root=}"
      if [[ "$keep_run_root" == "true" ]]; then
        keep_run_root=1
      elif [[ "$keep_run_root" != "1" ]]; then
        keep_run_root=0
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

timestamp() {
  date -u '+%Y-%m-%dT%H:%M:%SZ'
}

require_command() {
  local name="$1"

  command -v "$name" >/dev/null 2>&1
}

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/../.." && pwd)"
scenario_manifest="${DW_ACTIVITIES_SCENARIO_MANIFEST:-$repo_root/static/platform-conformance/activity-runtime-scenarios.json}"

run_root="${DW_ACTIVITIES_RUN_ROOT:-}"
if [[ -z "$run_root" ]]; then
  run_root="$(mktemp -d "${TMPDIR:-/tmp}/dw-activities.XXXXXX")"
fi
mkdir -p "$run_root"

if [[ -z "$result_dir" ]]; then
  result_dir="$run_root"
fi
mkdir -p "$result_dir"

cleanup() {
  local code=$?

  if [[ "$keep_run_root" != "1" && "$code" -eq 0 && "$result_dir" != "$run_root" ]]; then
    rm -rf "$run_root"
  fi
}
trap cleanup EXIT

focused_probe_app_key="${APP_KEY:-base64:QUNUSVZJVElFUy1DT05GT1JNQU5DRS1GT0NVU0VELUhPU1QtUFJPQkU=}"

should_run_focused_activity_host_probe() {
  if [[ "${DW_ACTIVITIES_SKIP_FOCUSED_HOST_PROBE:-0}" == "1" || "${DW_ACTIVITIES_SKIP_FOCUSED_HOST_PROBE:-}" == "true" ]]; then
    return 1
  fi
  if [[ -n "${DW_ACTIVITIES_EVIDENCE:-}" || -n "${DW_ACTIVITIES_EVIDENCE_PATH:-}" ]]; then
    return 1
  fi
  if [[ -s "$result_dir/activity-evidence.json" ]]; then
    return 1
  fi
  if [[ "$repo_root" != "/app" || -d "$repo_root/.git" ]]; then
    return 1
  fi
  if [[ ! -f "$repo_root/artisan" || ! -f "$repo_root/vendor/autoload.php" ]]; then
    return 1
  fi

  require_command php
}

prepare_focused_python_sdk() {
  if [[ -n "${DW_ACTIVITIES_PYTHON_BIN:-}" ]]; then
    return 0
  fi
  if [[ -z "${DW_PYTHON_SDK_VERSION:-}" ]]; then
    return 0
  fi
  if ! require_command python3; then
    return 0
  fi

  local venv="$run_root/sdk-python-venv"
  local install_log="$result_dir/sdk-python-focused-install.log"
  if python3 -m venv "$venv" >/dev/null 2>"$install_log" \
    && "$venv/bin/python" -m pip install --disable-pip-version-check --no-input "durable-workflow==${DW_PYTHON_SDK_VERSION}" >>"$install_log" 2>&1; then
    export DW_ACTIVITIES_PYTHON_BIN="$venv/bin/python"
  fi
}

run_focused_activity_host_probe() {
  local probe_db="$run_root/activities-focused-host-probe.sqlite"

  : > "$probe_db"
  prepare_focused_python_sdk

  APP_ENV=production \
  APP_DEBUG=false \
  APP_KEY="$focused_probe_app_key" \
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
  RUN_ROOT="$run_root" \
  php <<'PHP' || true
<?php
declare(strict_types=1);

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
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ActivityOptions;
use Workflow\V2\Worker\WorkflowFiberRunner;
use Workflow\V2\Workflow;

const ACTIVITIES_NAMESPACE = 'activities-conformance';
const ACTIVITIES_TASK_QUEUE = 'activities-shared';
const EMBEDDED_WORKFLOW_TYPE = 'activities.conformance.workflow-embedded-result';
const ACTIVITY_TYPE = 'activities.conformance.echo';
const HOST_EVIDENCE_SCHEMA = 'durable-workflow.v2.activity-runtime.published-artifact-host-evidence';
const HOST_EVIDENCE_SOURCE = 'published_server_container';

$repoRoot = getenv('RUNNER_REPO_ROOT') ?: '/app';
if (! is_dir($repoRoot)) {
    throw new RuntimeException('published server root is not available');
}
chdir($repoRoot);

require $repoRoot.'/vendor/autoload.php';

#[Type(EMBEDDED_WORKFLOW_TYPE)]
final class PublishedActivitiesEmbeddedWorkflow extends Workflow
{
    public function handle(array $payload): array
    {
        $activityResult = Workflow::activity(
            ACTIVITY_TYPE,
            new ActivityOptions(queue: ACTIVITIES_TASK_QUEUE),
            $payload
        );

        return [
            'workflow_runtime' => 'workflow-php',
            'requested_runtime' => $payload['runtime'] ?? null,
            'activity_result' => $activityResult,
            'activity_result_message' => is_array($activityResult) ? ($activityResult['message'] ?? null) : null,
        ];
    }
}

function now_iso(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

function write_json_file(string $path, array $value): void
{
    file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
}

function output_path(): string
{
    $dir = getenv('RESULT_DIR') ?: sys_get_temp_dir();

    return rtrim($dir, '/').'/activity-evidence.json';
}

function finding_for_failure(string $scenarioId, string $message): array
{
    return [
        'scenario_id' => $scenarioId,
        'finding_type' => 'activity_runtime_product_gap',
        'classification' => 'product-gap',
        'root_cause_classification' => 'product-gap',
        'owning_surface' => 'activity_runtime',
        'observed_behavior' => $message,
        'next_acceptance_criterion' => 'rerun the focused activity host probe from the pinned published server image and record passing activity host evidence for this scenario',
        'priority' => 'P0',
    ];
}

function failure_scenario(string $scenarioId, string $mode, Throwable $throwable): array
{
    $message = $throwable::class.': '.$throwable->getMessage();
    $hostEvidence = [
        'schema' => HOST_EVIDENCE_SCHEMA,
        'scenario_id' => $scenarioId,
        'status' => 'fail',
        'execution_source' => HOST_EVIDENCE_SOURCE,
        'executed_in_pinned_server_artifact' => true,
        'local_product_source_checkouts_used' => false,
        'activity_cells' => [
            [
                'mode' => $mode,
                'runtime' => 'workflow-php',
                'status' => 'fail',
                'failure' => $message,
                'execution_source' => HOST_EVIDENCE_SOURCE,
            ],
            [
                'mode' => $mode,
                'runtime' => 'sdk-python',
                'status' => 'fail',
                'failure' => $message,
                'execution_source' => HOST_EVIDENCE_SOURCE,
            ],
        ],
    ];

    return [
        'scenario_id' => $scenarioId,
        'status' => 'fail',
        'classification' => 'product-gap',
        'observed_behavior' => $message,
        'observed_outputs' => [
            'activity_host_evidence' => $hostEvidence,
            'execution_source' => HOST_EVIDENCE_SOURCE,
            'failure' => $message,
        ],
        'scenario_evidence' => [
            'activity_host_evidence' => $hostEvidence,
        ],
        'linked_findings' => [finding_for_failure($scenarioId, $message)],
    ];
}

function evidence_document(array $scenarioResults, array $activityCells): array
{
    $behaviorCells = [
        'durable_result_recording_after_worker_restart',
        'retry_attempt_backoff_behavior',
        'timeout_behavior',
        'typed_failure_propagation',
        'heartbeat_and_cancellation_observation',
        'idempotent_completion_handling',
        'php_python_activity_parity',
        'operator_visible_activity_attempt_state',
    ];
    $scenarioStatusById = [];
    foreach ($scenarioResults as $scenario) {
        $scenarioId = is_string($scenario['scenario_id'] ?? null) ? $scenario['scenario_id'] : '';
        if ($scenarioId !== '') {
            $scenarioStatusById[$scenarioId] = is_string($scenario['status'] ?? null) ? $scenario['status'] : 'not_covered';
        }
    }
    $durableScenario = null;
    foreach ($scenarioResults as $scenario) {
        if (($scenario['scenario_id'] ?? null) === 'durable_result_recording_after_worker_restart') {
            $durableScenario = $scenario;
            break;
        }
    }
    $durableOutputs = is_array($durableScenario['observed_outputs'] ?? null)
        ? $durableScenario['observed_outputs']
        : [];
    $retryScenario = null;
    foreach ($scenarioResults as $scenario) {
        if (($scenario['scenario_id'] ?? null) === 'retry_attempt_backoff_behavior') {
            $retryScenario = $scenario;
            break;
        }
    }
    $retryOutputs = is_array($retryScenario['observed_outputs'] ?? null)
        ? $retryScenario['observed_outputs']
        : [];

    return [
        'schema' => 'durable-workflow.v2.activity-runtime.host-evidence',
        'generated_at' => now_iso(),
        'evidence_source' => 'focused_published_server_activity_host_probe',
        'execution_source' => HOST_EVIDENCE_SOURCE,
        'runner' => 'published-server-activities-focused-host-probe',
        'local_product_source_checkouts_used' => false,
        'scenario_results' => $scenarioResults,
        'runtime_matrix' => [
            'execution_modes' => ['workflow-embedded', 'standalone'],
            'runtimes' => ['workflow-php', 'sdk-python'],
            'activity_cells' => $activityCells,
            'behavior_cells' => array_map(
                static fn (string $scenario): array => [
                    'scenario' => $scenario,
                    'status' => $scenarioStatusById[$scenario] ?? 'not_covered',
                ],
                $behaviorCells
            ),
        ],
        'durable_result_recording' => [
            'status' => $scenarioStatusById['durable_result_recording_after_worker_restart'] ?? 'not_covered',
            'scenario' => 'durable_result_recording_after_worker_restart',
            'result_recorded_before_restart' => $durableOutputs['result_recorded_before_restart'] ?? null,
            'result_observed_after_restart' => $durableOutputs['result_observed_after_restart'] ?? null,
            'activity_execution_id' => $durableOutputs['activity_execution_id'] ?? null,
            'duplicate_activity_count' => $durableOutputs['duplicate_activity_count'] ?? null,
        ],
        'retry_backoff' => [
            'status' => $scenarioStatusById['retry_attempt_backoff_behavior'] ?? 'not_covered',
            'scenario' => 'retry_attempt_backoff_behavior',
            'attempts' => $retryOutputs['attempts'] ?? null,
            'failure_payloads' => $retryOutputs['failure_payloads'] ?? null,
            'configured_retry_policy' => $retryOutputs['configured_retry_policy'] ?? null,
            'retry_policy' => $retryOutputs['retry_policy'] ?? null,
            'leased_retry_policies' => $retryOutputs['leased_retry_policies'] ?? null,
            'configured_backoff_seconds' => $retryOutputs['configured_backoff_seconds'] ?? null,
            'scheduled_backoff_seconds' => $retryOutputs['scheduled_backoff_seconds'] ?? null,
            'observed_redelivery_timestamps' => $retryOutputs['observed_redelivery_timestamps'] ?? null,
            'terminal_result' => $retryOutputs['terminal_result'] ?? null,
        ],
    ];
}

function bootstrap_application(string $repoRoot): void
{
    $app = require $repoRoot.'/bootstrap/app.php';
    $app->make(ConsoleKernel::class)->bootstrap();

    config([
        'app.key' => getenv('APP_KEY') ?: 'base64:QUNUSVZJVElFUy1DT05GT1JNQU5DRS1GT0NVU0VELUhPU1QtUFJPQkU=',
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
        ['name' => ACTIVITIES_NAMESPACE],
        [
            'description' => 'Activities conformance namespace',
            'retention_days' => 30,
            'status' => 'active',
        ]
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
        'HTTP_X_NAMESPACE' => ACTIVITIES_NAMESPACE,
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
        return [];
    }

    $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

    return is_array($decoded) ? $decoded : [];
}

function envelope(mixed $value, ?string $codec = null): array
{
    $codec = $codec ?: CodecRegistry::defaultCodec();

    return [
        'codec' => $codec,
        'blob' => Serializer::serializeWithCodec($codec, $value),
    ];
}

function decode_payload(mixed $value, ?string $codec = null): mixed
{
    if ($value === null) {
        return null;
    }
    if (is_array($value) && isset($value['codec'], $value['blob'])) {
        return Serializer::unserializeWithCodec((string) $value['codec'], (string) $value['blob']);
    }
    if (is_string($value)) {
        return Serializer::unserializeWithCodec($codec ?: CodecRegistry::defaultCodec(), $value);
    }

    return $value;
}

function task_codec(array $task): string
{
    $codec = $task['payload_codec'] ?? null;
    if (! is_string($codec) || $codec === '') {
        $codec = is_array($task['arguments'] ?? null) ? ($task['arguments']['codec'] ?? null) : null;
    }

    return is_string($codec) && $codec !== '' ? $codec : CodecRegistry::defaultCodec();
}

function history_events(array $task): array
{
    $events = $task['history_events'] ?? ($task['history']['events'] ?? []);

    return is_array($events) ? $events : [];
}

function workflow_arguments(array $task, string $codec): array
{
    $arguments = decode_payload($task['arguments'] ?? null, $codec);
    if (is_array($arguments) && array_is_list($arguments)) {
        return $arguments;
    }

    return is_array($arguments) ? [$arguments] : [];
}

function complete_workflow_task_from_runtime(array $task): array
{
    $codec = task_codec($task);
    $runner = WorkflowFiberRunner::forClass(
        PublishedActivitiesEmbeddedWorkflow::class,
        (string) ($task['workflow_id'] ?? $task['workflow_instance_id'] ?? ''),
        (string) ($task['run_id'] ?? $task['workflow_run_id'] ?? ''),
        workflow_arguments($task, $codec),
        $codec,
        history_events($task),
        ACTIVITIES_NAMESPACE,
    );
    $step = $runner->step();
    if ($step->commands === []) {
        throw new RuntimeException('workflow runtime produced no commands for the leased task');
    }

    return request_json('POST', '/worker/workflow-tasks/'.rawurlencode((string) $task['task_id']).'/complete', [
        'lease_owner' => $task['lease_owner'],
        'workflow_task_attempt' => $task['workflow_task_attempt'] ?? 1,
        'commands' => $step->commands,
    ]);
}

function register_worker(string $workerId, array $workflowTypes, array $activityTypes, string $runtime): void
{
    $workerRuntime = $runtime === 'sdk-python' ? 'python' : 'php';
    $sdkVersion = $runtime === 'sdk-python'
        ? 'durable-workflow-python/'.(getenv('DW_PYTHON_SDK_VERSION') ?: 'unknown')
        : 'durable-workflow/server:published-artifact';

    request_json('POST', '/worker/register', [
        'worker_id' => $workerId,
        'task_queue' => ACTIVITIES_TASK_QUEUE,
        'runtime' => $workerRuntime,
        'sdk_version' => $sdkVersion,
        'supported_workflow_types' => $workflowTypes,
        'supported_activity_types' => $activityTypes,
        'max_concurrent_workflow_tasks' => 1,
        'max_concurrent_activity_tasks' => 1,
        'task_slots' => [
            'workflow_available' => $workflowTypes === [] ? 0 : 1,
            'activity_available' => $activityTypes === [] ? 0 : 1,
            'session_available' => 0,
        ],
        'process_metrics' => [
            'memory_bytes' => memory_get_usage(true),
            'process_uptime_seconds' => 0,
            'process_id' => getmypid() ?: 0,
            'host' => gethostname() ?: 'published-server-container',
            'process_started_at' => now_iso(),
        ],
    ]);
}

function python_activity_executor_script(): string
{
    return <<<'PY'
import importlib.metadata as metadata
import json
import os
import sys
import time

import durable_workflow
from durable_workflow import serializer


def decode_activity_input(task):
    codec = task.get("payload_codec") or "avro"
    arguments = task.get("arguments")
    if isinstance(arguments, dict) and "codec" in arguments:
        decoded = serializer.decode_envelope(arguments)
    elif isinstance(arguments, str):
        decoded = serializer.decode(arguments, codec)
    else:
        decoded = arguments
    if isinstance(decoded, list):
        return decoded[0] if decoded else {}
    return decoded if isinstance(decoded, dict) else {}


payload = json.load(sys.stdin)
task = payload["task"]
mode = payload["mode"]
expected_version = str(payload.get("expected_version") or "").strip()
package_version = metadata.version("durable-workflow")
if expected_version and package_version != expected_version:
    raise RuntimeError(
        f"installed durable-workflow package version {package_version} does not match expected {expected_version}"
    )

input_payload = decode_activity_input(task)
result = {
    "message": "published artifact activity completed",
    "mode": mode,
    "runtime": "sdk-python",
    "input_marker": input_payload.get("input_marker"),
    "activity_type": task.get("activity_type") or "activities.conformance.echo",
    "sdk_package_version": package_version,
}

print(json.dumps({
    "result_payload": result,
    "result_envelope": serializer.envelope(result, task.get("payload_codec") or "avro"),
    "worker_artifact": {
        "artifact": "sdk-python",
        "package": "durable-workflow",
        "version": package_version,
        "source": f"pypi://durable-workflow=={package_version}",
        "status": "pass",
        "runtime": "sdk-python",
        "language": "python",
        "sdk_module": durable_workflow.__name__,
        "execution_source": "published_server_container",
        "execution_method": "durable_workflow.serializer.envelope",
        "local_product_source_checkouts_used": False,
        "recorded_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
    },
}, separators=(",", ":")))
PY;
}

function run_python_activity_executor(array $task, string $mode): array
{
    $expectedVersion = getenv('DW_PYTHON_SDK_VERSION') ?: '';
    $pythonBinary = getenv('DW_ACTIVITIES_PYTHON_BIN') ?: 'python3';
    $input = json_encode([
        'task' => $task,
        'mode' => $mode,
        'expected_version' => $expectedVersion,
    ], JSON_THROW_ON_ERROR);
    $command = escapeshellarg($pythonBinary).' -c '.escapeshellarg(python_activity_executor_script());
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (! is_resource($process)) {
        throw new RuntimeException('failed to start sdk-python activity executor');
    }

    fwrite($pipes[0], $input);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        throw new RuntimeException('sdk-python activity executor failed: '.trim($stderr ?: $stdout ?: "exit {$exitCode}"));
    }

    $decoded = json_decode((string) $stdout, true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($decoded)) {
        throw new RuntimeException('sdk-python activity executor returned non-object output');
    }
    if (($decoded['worker_artifact']['artifact'] ?? null) !== 'sdk-python') {
        throw new RuntimeException('sdk-python activity executor did not report sdk-python worker artifact evidence');
    }

    return $decoded;
}

function poll_task(string $kind, string $workerId): array
{
    $path = $kind === 'workflow'
        ? '/worker/workflow-tasks/poll'
        : '/worker/activity-tasks/poll';
    $response = request_json('POST', $path, [
        'worker_id' => $workerId,
        'task_queue' => ACTIVITIES_TASK_QUEUE,
    ]);
    $task = $response['task'] ?? null;
    if (! is_array($task)) {
        throw new RuntimeException("expected {$kind} task but poll returned ".json_encode($response));
    }

    return $task;
}

function activity_input(array $task, string $codec): array
{
    $arguments = decode_payload($task['arguments'] ?? null, $codec);
    $payload = is_array($arguments) && array_is_list($arguments) ? ($arguments[0] ?? []) : $arguments;

    return is_array($payload) ? $payload : [];
}

function complete_activity_task(array $task, string $runtime, string $mode): array
{
    $codec = task_codec($task);
    $payload = activity_input($task, $codec);
    $result = [
        'message' => 'published artifact activity completed',
        'mode' => $mode,
        'runtime' => $runtime,
        'input_marker' => $payload['input_marker'] ?? null,
        'activity_type' => $task['activity_type'] ?? ACTIVITY_TYPE,
    ];
    $workerArtifact = [
        'artifact' => 'workflow-php',
        'package' => 'durable-workflow/workflow',
        'version' => getenv('DW_WORKFLOW_PHP_VERSION') ?: 'unknown',
        'source' => 'packagist://durable-workflow/workflow@'.(getenv('DW_WORKFLOW_PHP_VERSION') ?: 'unknown'),
        'status' => 'pass',
        'runtime' => 'workflow-php',
        'language' => 'php',
        'execution_source' => HOST_EVIDENCE_SOURCE,
        'execution_method' => 'Workflow\\Serializers\\Serializer::serializeWithCodec',
        'local_product_source_checkouts_used' => false,
    ];

    if ($runtime === 'sdk-python') {
        $python = run_python_activity_executor($task, $mode);
        $result = is_array($python['result_payload'] ?? null) ? $python['result_payload'] : [];
        $workerArtifact = is_array($python['worker_artifact'] ?? null) ? $python['worker_artifact'] : [];
        if (! is_array($python['result_envelope'] ?? null)) {
            throw new RuntimeException('sdk-python activity executor did not return a result envelope');
        }
        $response = request_json('POST', '/worker/activity-tasks/'.rawurlencode((string) $task['task_id']).'/complete', [
            'activity_attempt_id' => $task['activity_attempt_id'] ?? '',
            'lease_owner' => $task['lease_owner'],
            'result' => $python['result_envelope'],
        ]);

        return [$result, $response, $workerArtifact];
    }

    $response = request_json('POST', '/worker/activity-tasks/'.rawurlencode((string) $task['task_id']).'/complete', [
        'activity_attempt_id' => $task['activity_attempt_id'] ?? '',
        'lease_owner' => $task['lease_owner'],
        'result' => envelope($result, $codec),
    ]);

    return [$result, $response, $workerArtifact];
}

function event_types(array $history): array
{
    $events = $history['history_events'] ?? ($history['events'] ?? []);
    if (! is_array($events)) {
        return [];
    }

    return array_values(array_filter(array_map(
        static fn (mixed $event): ?string => is_array($event) && is_string($event['event_type'] ?? null) ? $event['event_type'] : null,
        $events
    )));
}

function count_event_type(array $history, string $eventType): int
{
    return count(array_filter(
        event_types($history),
        static fn (string $type): bool => $type === $eventType
    ));
}

function history_payloads_for_event(array $history, string $eventType): array
{
    $events = $history['history_events'] ?? ($history['events'] ?? []);
    if (! is_array($events)) {
        return [];
    }

    $payloads = [];
    foreach ($events as $event) {
        if (! is_array($event) || ($event['event_type'] ?? null) !== $eventType) {
            continue;
        }
        $payloads[] = is_array($event['payload'] ?? null) ? $event['payload'] : [];
    }

    return $payloads;
}

function normalized_workflow_output(mixed $output): mixed
{
    try {
        return decode_payload($output);
    } catch (Throwable) {
        return $output;
    }
}

function run_embedded_cell(string $runtime): array
{
    $safeRuntime = str_replace(['/', '_'], '-', $runtime);
    $suffix = bin2hex(random_bytes(3));
    $workerId = "activities-embedded-{$safeRuntime}-{$suffix}";
    $workflowId = "activities-embedded-{$safeRuntime}-{$suffix}";

    register_worker($workerId, [EMBEDDED_WORKFLOW_TYPE], [ACTIVITY_TYPE], $runtime);
    $start = request_json('POST', '/workflows', [
        'workflow_id' => $workflowId,
        'workflow_type' => EMBEDDED_WORKFLOW_TYPE,
        'task_queue' => ACTIVITIES_TASK_QUEUE,
        'input' => [[
            'scenario_id' => 'workflow_embedded_activity_result',
            'runtime' => $runtime,
            'input_marker' => "embedded-{$safeRuntime}",
        ]],
    ]);
    $runId = (string) ($start['run_id'] ?? '');

    $workflowTask = poll_task('workflow', $workerId);
    complete_workflow_task_from_runtime($workflowTask);

    $activityTask = poll_task('activity', $workerId);
    [$activityResult, $activityComplete, $workerArtifact] = complete_activity_task($activityTask, $runtime, 'workflow-embedded');

    $resumeTask = poll_task('workflow', $workerId);
    $workflowComplete = complete_workflow_task_from_runtime($resumeTask);

    $run = request_json('GET', '/workflows/'.rawurlencode($workflowId).'/runs/'.rawurlencode($runId));
    $history = request_json('GET', '/workflows/'.rawurlencode($workflowId).'/runs/'.rawurlencode($runId).'/history');

    if (($run['status'] ?? null) !== RunStatus::Completed->value) {
        throw new RuntimeException("workflow embedded cell {$runtime} did not complete");
    }

    return [
        'mode' => 'workflow-embedded',
        'runtime' => $runtime,
        'status' => 'pass',
        'execution_source' => HOST_EVIDENCE_SOURCE,
        'workflow_id' => $workflowId,
        'run_id' => $runId,
        'activity_execution_id' => $activityTask['activity_execution_id'] ?? null,
        'activity_attempt_id' => $activityTask['activity_attempt_id'] ?? null,
        'activity_type' => $activityTask['activity_type'] ?? ACTIVITY_TYPE,
        'result_payload' => $activityResult,
        'workflow_output' => $run['output'] ?? null,
        'worker_artifact' => $workerArtifact,
        'local_product_source_checkouts_used' => false,
        'history_events' => event_types($history),
        'worker_protocol' => [
            'workflow_task_completion' => $workflowComplete['outcome'] ?? null,
            'activity_task_completion' => $activityComplete['outcome'] ?? null,
            'registered_runtime' => $runtime === 'sdk-python' ? 'python' : 'php',
        ],
    ];
}

function run_standalone_cell(string $runtime): array
{
    $safeRuntime = str_replace(['/', '_'], '-', $runtime);
    $suffix = bin2hex(random_bytes(3));
    $workerId = "activities-standalone-{$safeRuntime}-{$suffix}";
    $activityId = "activities-standalone-{$safeRuntime}-{$suffix}";

    register_worker($workerId, [], [ACTIVITY_TYPE], $runtime);
    $start = request_json('POST', '/activities', [
        'activity_id' => $activityId,
        'activity_type' => ACTIVITY_TYPE,
        'task_queue' => ACTIVITIES_TASK_QUEUE,
        'input' => [[
            'scenario_id' => 'standalone_activity_result',
            'runtime' => $runtime,
            'input_marker' => "standalone-{$safeRuntime}",
        ]],
    ]);
    $runId = (string) ($start['workflow_run_id'] ?? '');

    $activityTask = poll_task('activity', $workerId);
    [$activityResult, $activityComplete, $workerArtifact] = complete_activity_task($activityTask, $runtime, 'standalone');

    $show = request_json('GET', '/activities/'.rawurlencode($activityId));
    $history = request_json('GET', '/workflows/'.rawurlencode($activityId).'/runs/'.rawurlencode($runId).'/history');

    if (($show['status'] ?? null) !== RunStatus::Completed->value) {
        throw new RuntimeException("standalone activity cell {$runtime} did not complete");
    }

    return [
        'mode' => 'standalone',
        'runtime' => $runtime,
        'status' => 'pass',
        'execution_source' => HOST_EVIDENCE_SOURCE,
        'activity_id' => $activityId,
        'workflow_run_id' => $runId,
        'activity_execution_id' => $activityTask['activity_execution_id'] ?? ($start['activity_execution_id'] ?? null),
        'activity_attempt_id' => $activityTask['activity_attempt_id'] ?? null,
        'activity_type' => $activityTask['activity_type'] ?? ACTIVITY_TYPE,
        'result_payload' => $activityResult,
        'worker_artifact' => $workerArtifact,
        'local_product_source_checkouts_used' => false,
        'handle_response' => $show,
        'history_events' => event_types($history),
        'worker_protocol' => [
            'activity_task_completion' => $activityComplete['outcome'] ?? null,
            'registered_runtime' => $runtime === 'sdk-python' ? 'python' : 'php',
        ],
    ];
}

function scenario_from_cells(string $scenarioId, string $mode, array $cells): array
{
    $pass = $cells !== [] && array_reduce(
        $cells,
        static fn (bool $carry, array $cell): bool => $carry && (($cell['status'] ?? null) === 'pass'),
        true
    );
    $hostEvidence = [
        'schema' => HOST_EVIDENCE_SCHEMA,
        'scenario_id' => $scenarioId,
        'status' => $pass ? 'pass' : 'fail',
        'execution_source' => HOST_EVIDENCE_SOURCE,
        'executed_in_pinned_server_artifact' => true,
        'local_product_source_checkouts_used' => false,
        'activity_cells' => $cells,
    ];
    $firstCell = $cells[0] ?? [];
    $observed = array_filter([
        'activity_host_evidence' => $hostEvidence,
        'execution_source' => HOST_EVIDENCE_SOURCE,
        'activity_cells' => $cells,
        'workflow_id' => $firstCell['workflow_id'] ?? null,
        'run_id' => $firstCell['run_id'] ?? ($firstCell['workflow_run_id'] ?? null),
        'activity_id' => $firstCell['activity_id'] ?? null,
        'activity_execution_id' => $firstCell['activity_execution_id'] ?? null,
        'activity_attempt_id' => $firstCell['activity_attempt_id'] ?? null,
        'activity_type' => $firstCell['activity_type'] ?? null,
        'result_payload' => $firstCell['result_payload'] ?? null,
        'history_events' => $firstCell['history_events'] ?? null,
        'handle_response' => $firstCell['handle_response'] ?? null,
    ], static fn (mixed $value): bool => $value !== null && $value !== []);

    $scenario = [
        'scenario_id' => $scenarioId,
        'status' => $pass ? 'pass' : 'fail',
        'classification' => $pass ? null : 'product-gap',
        'observed_outputs' => $observed,
        'scenario_evidence' => $observed,
    ];

    if (! $pass) {
        $failures = array_values(array_filter(array_map(
            static fn (array $cell): ?string => isset($cell['failure']) ? "{$cell['runtime']}: {$cell['failure']}" : null,
            $cells
        )));
        $message = $failures === []
            ? "{$scenarioId} did not produce passing activity host evidence"
            : implode('; ', $failures);
        $scenario['observed_behavior'] = $message;
        $scenario['linked_findings'] = [finding_for_failure($scenarioId, $message)];
    }

    return $scenario;
}

function failure_behavior_scenario(string $scenarioId, Throwable $throwable): array
{
    $message = $throwable::class.': '.$throwable->getMessage();

    return [
        'scenario_id' => $scenarioId,
        'status' => 'fail',
        'classification' => 'product-gap',
        'observed_behavior' => $message,
        'observed_outputs' => [
            'execution_source' => HOST_EVIDENCE_SOURCE,
            'failure' => $message,
        ],
        'scenario_evidence' => [
            'execution_source' => HOST_EVIDENCE_SOURCE,
            'failure' => $message,
        ],
        'linked_findings' => [finding_for_failure($scenarioId, $message)],
    ];
}

function timestamp_from_datetime(mixed $value): ?float
{
    if ($value instanceof DateTimeInterface) {
        return (float) $value->format('U.u');
    }
    if (is_string($value) && trim($value) !== '') {
        try {
            return (float) (new DateTimeImmutable($value))->format('U.u');
        } catch (Throwable) {
            return null;
        }
    }

    return null;
}

function iso_from_datetime(mixed $value): ?string
{
    $timestamp = timestamp_from_datetime($value);

    return $timestamp === null ? null : iso_from_timestamp($timestamp);
}

function iso_from_timestamp(float $timestamp): string
{
    $seconds = (int) floor($timestamp);
    $micros = (int) round(($timestamp - $seconds) * 1_000_000);
    if ($micros >= 1_000_000) {
        $seconds++;
        $micros -= 1_000_000;
    }

    return gmdate('Y-m-d\TH:i:s', $seconds).sprintf('.%06dZ', $micros);
}

function workflow_task_available_at(string $taskId): ?DateTimeInterface
{
    /** @var WorkflowTask|null $task */
    $task = WorkflowTask::query()->find($taskId);

    return $task?->available_at instanceof DateTimeInterface ? $task->available_at : null;
}

function wait_until_timestamp(float $timestamp): void
{
    $sleepSeconds = $timestamp - microtime(true);
    if ($sleepSeconds <= 0) {
        return;
    }

    usleep((int) ceil(($sleepSeconds + 0.05) * 1_000_000));
}

function attempt_snapshots(string $activityExecutionId): array
{
    return ActivityAttempt::query()
        ->where('activity_execution_id', $activityExecutionId)
        ->orderBy('attempt_number')
        ->get()
        ->map(static fn (ActivityAttempt $attempt): array => [
            'activity_attempt_id' => $attempt->id,
            'workflow_task_id' => $attempt->workflow_task_id,
            'attempt_number' => $attempt->attempt_number,
            'status' => $attempt->status instanceof BackedEnum ? $attempt->status->value : (string) $attempt->status,
            'lease_owner' => $attempt->lease_owner,
            'started_at' => $attempt->started_at?->toJSON(),
            'closed_at' => $attempt->closed_at?->toJSON(),
        ])
        ->values()
        ->all();
}

function fail_activity_task(array $task, array $failure): array
{
    return request_json('POST', '/worker/activity-tasks/'.rawurlencode((string) $task['task_id']).'/fail', [
        'activity_attempt_id' => $task['activity_attempt_id'] ?? '',
        'lease_owner' => $task['lease_owner'],
        'failure' => $failure,
    ]);
}

function run_restart_durable_result_cell(): array
{
    $suffix = bin2hex(random_bytes(3));
    $firstWorkerId = "activities-restart-first-{$suffix}";
    $restartWorkerId = "activities-restart-replay-{$suffix}";
    $workflowId = "activities-restart-durable-{$suffix}";

    register_worker($firstWorkerId, [EMBEDDED_WORKFLOW_TYPE], [ACTIVITY_TYPE], 'workflow-php');
    register_worker($restartWorkerId, [EMBEDDED_WORKFLOW_TYPE], [ACTIVITY_TYPE], 'workflow-php');

    $start = request_json('POST', '/workflows', [
        'workflow_id' => $workflowId,
        'workflow_type' => EMBEDDED_WORKFLOW_TYPE,
        'task_queue' => ACTIVITIES_TASK_QUEUE,
        'input' => [[
            'scenario_id' => 'durable_result_recording_after_worker_restart',
            'runtime' => 'workflow-php',
            'input_marker' => "restart-durable-{$suffix}",
        ]],
    ]);
    $runId = (string) ($start['run_id'] ?? '');

    $workflowTask = poll_task('workflow', $firstWorkerId);
    complete_workflow_task_from_runtime($workflowTask);

    $activityTask = poll_task('activity', $firstWorkerId);
    [$activityResult, $activityComplete, $workerArtifact] = complete_activity_task(
        $activityTask,
        'workflow-php',
        'workflow-embedded'
    );

    $historyAfterRecord = request_json('GET', '/workflows/'.rawurlencode($workflowId).'/runs/'.rawurlencode($runId).'/history');
    $completedBeforeRestart = count_event_type($historyAfterRecord, 'ActivityCompleted');
    $resultRecordedBeforeRestart = ($activityComplete['recorded'] ?? null) === true && $completedBeforeRestart === 1;
    if (! $resultRecordedBeforeRestart) {
        throw new RuntimeException('activity result was not durably recorded before the worker restart');
    }

    $resumeTask = poll_task('workflow', $restartWorkerId);
    $workflowComplete = complete_workflow_task_from_runtime($resumeTask);

    $run = request_json('GET', '/workflows/'.rawurlencode($workflowId).'/runs/'.rawurlencode($runId));
    $historyAfterReplay = request_json('GET', '/workflows/'.rawurlencode($workflowId).'/runs/'.rawurlencode($runId).'/history');
    $completedAfterReplay = count_event_type($historyAfterReplay, 'ActivityCompleted');
    $duplicateActivityCount = max(0, $completedAfterReplay - 1);
    $workflowOutput = normalized_workflow_output($run['output'] ?? null);
    $resultObservedAfterRestart = ($run['status'] ?? null) === RunStatus::Completed->value
        && is_array($workflowOutput)
        && ($workflowOutput['activity_result_message'] ?? null) === 'published artifact activity completed'
        && $completedAfterReplay === 1
        && $duplicateActivityCount === 0;

    $emptyActivityPoll = request_json('POST', '/worker/activity-tasks/poll', [
        'worker_id' => $restartWorkerId,
        'task_queue' => ACTIVITIES_TASK_QUEUE,
    ]);
    if (is_array($emptyActivityPoll['task'] ?? null)) {
        throw new RuntimeException('activity task was redelivered after terminal completion was recorded');
    }
    if (! $resultObservedAfterRestart) {
        throw new RuntimeException('workflow replay after worker restart did not observe exactly one durable activity completion');
    }

    return [
        'scenario_id' => 'durable_result_recording_after_worker_restart',
        'mode' => 'workflow-embedded',
        'runtime' => 'workflow-php',
        'status' => 'pass',
        'execution_source' => HOST_EVIDENCE_SOURCE,
        'first_worker_identity' => $firstWorkerId,
        'restart_worker_identity' => $restartWorkerId,
        'workflow_id' => $workflowId,
        'run_id' => $runId,
        'activity_execution_id' => $activityTask['activity_execution_id'] ?? null,
        'activity_attempt_id' => $activityTask['activity_attempt_id'] ?? null,
        'activity_type' => $activityTask['activity_type'] ?? ACTIVITY_TYPE,
        'result_payload' => $activityResult,
        'worker_artifact' => $workerArtifact,
        'local_product_source_checkouts_used' => false,
        'result_recorded_before_restart' => $resultRecordedBeforeRestart,
        'result_observed_after_restart' => $resultObservedAfterRestart,
        'activity_completed_count_before_restart' => $completedBeforeRestart,
        'activity_completed_count_after_replay' => $completedAfterReplay,
        'duplicate_activity_count' => $duplicateActivityCount,
        'history_events_before_restart' => event_types($historyAfterRecord),
        'history_events_after_replay' => event_types($historyAfterReplay),
        'restart_replay_task' => [
            'lease_owner' => $resumeTask['lease_owner'] ?? null,
            'workflow_event_type' => $resumeTask['workflow_event_type'] ?? null,
            'resume_source_kind' => $resumeTask['resume_source_kind'] ?? null,
            'resume_source_id' => $resumeTask['resume_source_id'] ?? null,
        ],
        'worker_protocol' => [
            'activity_task_completion' => $activityComplete['outcome'] ?? null,
            'activity_task_recorded' => $activityComplete['recorded'] ?? null,
            'workflow_task_completion_after_restart' => $workflowComplete['outcome'] ?? null,
            'run_status_after_restart' => $run['status'] ?? null,
            'post_completion_activity_poll_status' => $emptyActivityPoll['poll_status'] ?? null,
        ],
    ];
}

function run_retry_backoff_cell(): array
{
    $suffix = bin2hex(random_bytes(3));
    $workerId = "activities-retry-backoff-{$suffix}";
    $activityId = "activities-retry-backoff-{$suffix}";
    $retryPolicy = [
        'max_attempts' => 3,
        'backoff_seconds' => [1],
        'non_retryable_error_types' => ['ActivitiesConformanceNonRetryable'],
    ];
    $failurePayload = [
        'message' => 'activities conformance retryable failure',
        'type' => 'ActivitiesConformanceRetryableFailure',
        'retryable' => true,
        'non_retryable' => false,
    ];

    register_worker($workerId, [], [ACTIVITY_TYPE], 'workflow-php');
    $start = request_json('POST', '/activities', [
        'activity_id' => $activityId,
        'activity_type' => ACTIVITY_TYPE,
        'task_queue' => ACTIVITIES_TASK_QUEUE,
        'input' => [[
            'scenario_id' => 'retry_attempt_backoff_behavior',
            'runtime' => 'workflow-php',
            'input_marker' => "retry-backoff-{$suffix}",
        ]],
        'retry_policy' => $retryPolicy,
    ]);
    $runId = (string) ($start['workflow_run_id'] ?? '');

    $firstPollAt = microtime(true);
    $firstTask = poll_task('activity', $workerId);
    $firstLeasedAt = microtime(true);

    $failRequestedAt = microtime(true);
    $failResponse = fail_activity_task($firstTask, $failurePayload);
    $failRecordedAt = microtime(true);
    $nextTaskId = is_string($failResponse['next_task_id'] ?? null) ? $failResponse['next_task_id'] : '';
    if ($nextTaskId === '') {
        throw new RuntimeException('retryable activity failure did not return a retry task id');
    }

    $retryAvailableAt = workflow_task_available_at($nextTaskId);
    $retryAvailableTimestamp = timestamp_from_datetime($retryAvailableAt);
    if ($retryAvailableTimestamp === null) {
        throw new RuntimeException('retryable activity failure did not record a retry availability timestamp');
    }

    $notReadyBeforeBackoff = $retryAvailableTimestamp > microtime(true);
    wait_until_timestamp($retryAvailableTimestamp);

    $secondPollAt = microtime(true);
    $secondTask = poll_task('activity', $workerId);
    $secondLeasedAt = microtime(true);

    [$activityResult, $activityComplete, $workerArtifact] = complete_activity_task(
        $secondTask,
        'workflow-php',
        'standalone'
    );

    $show = request_json('GET', '/activities/'.rawurlencode($activityId));
    $history = request_json('GET', '/workflows/'.rawurlencode($activityId).'/runs/'.rawurlencode($runId).'/history');

    if (($show['status'] ?? null) !== RunStatus::Completed->value) {
        throw new RuntimeException('retry/backoff activity did not complete after the retry attempt');
    }

    $firstAttemptNumber = (int) ($firstTask['attempt_number'] ?? 0);
    $secondAttemptNumber = (int) ($secondTask['attempt_number'] ?? 0);
    $firstLeasePolicy = is_array($firstTask['retry_policy'] ?? null) ? $firstTask['retry_policy'] : [];
    $secondLeasePolicy = is_array($secondTask['retry_policy'] ?? null) ? $secondTask['retry_policy'] : [];
    $sameExecution = ($firstTask['activity_execution_id'] ?? null) === ($secondTask['activity_execution_id'] ?? null);
    $attemptIdsChanged = ($firstTask['activity_attempt_id'] ?? null) !== ($secondTask['activity_attempt_id'] ?? null);
    $taskIdsChanged = ($firstTask['task_id'] ?? null) !== ($secondTask['task_id'] ?? null);
    $configuredBackoffSeconds = (int) ($retryPolicy['backoff_seconds'][0] ?? 0);
    $scheduledBackoffSeconds = max(0.0, round($retryAvailableTimestamp - $failRequestedAt, 3));
    $observedRedeliveryDelaySeconds = max(0.0, round($secondLeasedAt - $failRecordedAt, 3));
    $secondAttemptLeasedAfterAvailableAt = $secondLeasedAt + 0.05 >= $retryAvailableTimestamp;
    $backoffRespected = $scheduledBackoffSeconds >= max(0.0, $configuredBackoffSeconds - 0.2)
        && $secondAttemptLeasedAfterAvailableAt;

    if (! $sameExecution || ! $attemptIdsChanged || ! $taskIdsChanged) {
        throw new RuntimeException('retry/backoff attempt identity did not preserve execution id while changing task and attempt ids');
    }
    if ($firstAttemptNumber !== 1 || $secondAttemptNumber !== 2) {
        throw new RuntimeException(sprintf('retry/backoff attempt numbers were %d then %d, expected 1 then 2', $firstAttemptNumber, $secondAttemptNumber));
    }
    $policiesPreserveConfiguredInputs = ($firstLeasePolicy['max_attempts'] ?? null) === 3
        && ($secondLeasePolicy['max_attempts'] ?? null) === 3
        && ($firstLeasePolicy['backoff_seconds'] ?? null) === [1]
        && ($secondLeasePolicy['backoff_seconds'] ?? null) === [1]
        && ($firstLeasePolicy['non_retryable_error_types'] ?? null) === ['ActivitiesConformanceNonRetryable']
        && ($secondLeasePolicy['non_retryable_error_types'] ?? null) === ['ActivitiesConformanceNonRetryable'];
    if (! $policiesPreserveConfiguredInputs) {
        throw new RuntimeException('retry/backoff task leases did not preserve the configured retry policy');
    }
    if (($failResponse['outcome'] ?? null) !== 'failed'
        || ($failResponse['recorded'] ?? null) !== true
        || ($failResponse['next_task_id'] ?? null) !== $nextTaskId) {
        throw new RuntimeException('retry/backoff first failure did not record a retry_scheduled outcome');
    }
    if (! $backoffRespected) {
        throw new RuntimeException('retry/backoff redelivery did not respect the configured backoff window');
    }

    return [
        'scenario_id' => 'retry_attempt_backoff_behavior',
        'mode' => 'standalone',
        'runtime' => 'workflow-php',
        'status' => 'pass',
        'execution_source' => HOST_EVIDENCE_SOURCE,
        'activity_id' => $activityId,
        'workflow_run_id' => $runId,
        'activity_execution_id' => $firstTask['activity_execution_id'] ?? null,
        'activity_type' => $firstTask['activity_type'] ?? ACTIVITY_TYPE,
        'configured_retry_policy' => $retryPolicy,
        'retry_policy' => $firstLeasePolicy,
        'leased_retry_policies' => [
            'first_attempt' => $firstLeasePolicy,
            'second_attempt' => $secondLeasePolicy,
        ],
        'configured_backoff_seconds' => $configuredBackoffSeconds,
        'scheduled_backoff_seconds' => $scheduledBackoffSeconds,
        'observed_redelivery_delay_seconds' => $observedRedeliveryDelaySeconds,
        'backoff_respected' => $backoffRespected,
        'attempts' => [
            [
                'attempt_number' => $firstAttemptNumber,
                'task_id' => $firstTask['task_id'] ?? null,
                'activity_attempt_id' => $firstTask['activity_attempt_id'] ?? null,
                'activity_execution_id' => $firstTask['activity_execution_id'] ?? null,
                'lease_owner' => $firstTask['lease_owner'] ?? null,
                'status_after_report' => 'failed_retry_scheduled',
                'polled_at' => iso_from_timestamp($firstPollAt),
                'leased_at' => iso_from_timestamp($firstLeasedAt),
            ],
            [
                'attempt_number' => $secondAttemptNumber,
                'task_id' => $secondTask['task_id'] ?? null,
                'activity_attempt_id' => $secondTask['activity_attempt_id'] ?? null,
                'activity_execution_id' => $secondTask['activity_execution_id'] ?? null,
                'lease_owner' => $secondTask['lease_owner'] ?? null,
                'status_after_report' => 'completed',
                'polled_at' => iso_from_timestamp($secondPollAt),
                'leased_at' => iso_from_timestamp($secondLeasedAt),
            ],
        ],
        'attempt_state' => attempt_snapshots((string) ($firstTask['activity_execution_id'] ?? '')),
        'failure_payloads' => [
            [
                'attempt_number' => $firstAttemptNumber,
                'failure' => $failurePayload,
                'fail_response' => $failResponse,
                'reported_at' => iso_from_timestamp($failRecordedAt),
            ],
        ],
        'observed_redelivery_timestamps' => [
            'first_attempt_polled_at' => iso_from_timestamp($firstPollAt),
            'first_attempt_failed_at' => iso_from_timestamp($failRecordedAt),
            'retry_task_available_at' => iso_from_datetime($retryAvailableAt),
            'second_attempt_poll_started_at' => iso_from_timestamp($secondPollAt),
            'second_attempt_leased_at' => iso_from_timestamp($secondLeasedAt),
            'retry_task_not_ready_before_backoff_elapsed' => $notReadyBeforeBackoff,
            'second_attempt_leased_after_available_at' => $secondAttemptLeasedAfterAvailableAt,
            'observed_redelivery_delay_seconds' => $observedRedeliveryDelaySeconds,
        ],
        'terminal_result' => [
            'activity_status' => $show['activity_status'] ?? null,
            'run_status' => $show['status'] ?? null,
            'closed_reason' => $show['closed_reason'] ?? null,
            'activity_result' => $activityResult,
            'completion_response' => $activityComplete,
            'handle_response' => $show,
        ],
        'history_events' => event_types($history),
        'retry_history_events' => history_payloads_for_event($history, 'ActivityRetryScheduled'),
        'worker_artifact' => $workerArtifact,
        'local_product_source_checkouts_used' => false,
    ];
}

function scenario_from_restart_cell(array $cell): array
{
    $pass = ($cell['status'] ?? null) === 'pass'
        && ($cell['result_recorded_before_restart'] ?? null) === true
        && ($cell['result_observed_after_restart'] ?? null) === true
        && ($cell['duplicate_activity_count'] ?? 1) === 0;

    $observed = [
        'execution_source' => HOST_EVIDENCE_SOURCE,
        'first_worker_identity' => $cell['first_worker_identity'] ?? null,
        'restart_worker_identity' => $cell['restart_worker_identity'] ?? null,
        'workflow_id' => $cell['workflow_id'] ?? null,
        'run_id' => $cell['run_id'] ?? null,
        'activity_execution_id' => $cell['activity_execution_id'] ?? null,
        'activity_attempt_id' => $cell['activity_attempt_id'] ?? null,
        'result_recorded_before_restart' => $cell['result_recorded_before_restart'] ?? null,
        'result_observed_after_restart' => $cell['result_observed_after_restart'] ?? null,
        'activity_completed_count_before_restart' => $cell['activity_completed_count_before_restart'] ?? null,
        'activity_completed_count_after_replay' => $cell['activity_completed_count_after_replay'] ?? null,
        'duplicate_activity_count' => $cell['duplicate_activity_count'] ?? null,
        'history_events_before_restart' => $cell['history_events_before_restart'] ?? null,
        'history_events_after_replay' => $cell['history_events_after_replay'] ?? null,
        'restart_replay_task' => $cell['restart_replay_task'] ?? null,
        'worker_protocol' => $cell['worker_protocol'] ?? null,
    ];

    $scenario = [
        'scenario_id' => 'durable_result_recording_after_worker_restart',
        'status' => $pass ? 'pass' : 'fail',
        'classification' => $pass ? null : 'product-gap',
        'observed_outputs' => array_filter($observed, static fn (mixed $value): bool => $value !== null && $value !== []),
        'scenario_evidence' => array_filter([
            'restart_durable_result_recording' => $cell,
        ], static fn (mixed $value): bool => $value !== null && $value !== []),
    ];

    if (! $pass) {
        $message = 'activity result recording after worker restart did not prove exactly one terminal completion';
        $scenario['observed_behavior'] = $message;
        $scenario['linked_findings'] = [finding_for_failure('durable_result_recording_after_worker_restart', $message)];
    }

    return $scenario;
}

function scenario_from_retry_backoff_cell(array $cell): array
{
    $attempts = is_array($cell['attempts'] ?? null) ? $cell['attempts'] : [];
    $pass = ($cell['status'] ?? null) === 'pass'
        && count($attempts) >= 2
        && ($cell['backoff_respected'] ?? null) === true
        && ($cell['terminal_result']['run_status'] ?? null) === RunStatus::Completed->value;

    $observed = [
        'execution_source' => HOST_EVIDENCE_SOURCE,
        'activity_id' => $cell['activity_id'] ?? null,
        'workflow_run_id' => $cell['workflow_run_id'] ?? null,
        'activity_execution_id' => $cell['activity_execution_id'] ?? null,
        'activity_type' => $cell['activity_type'] ?? null,
        'attempts' => $attempts,
        'attempt_state' => $cell['attempt_state'] ?? null,
        'failure_payloads' => $cell['failure_payloads'] ?? null,
        'configured_retry_policy' => $cell['configured_retry_policy'] ?? null,
        'retry_policy' => $cell['retry_policy'] ?? null,
        'leased_retry_policies' => $cell['leased_retry_policies'] ?? null,
        'configured_backoff_seconds' => $cell['configured_backoff_seconds'] ?? null,
        'scheduled_backoff_seconds' => $cell['scheduled_backoff_seconds'] ?? null,
        'observed_redelivery_timestamps' => $cell['observed_redelivery_timestamps'] ?? null,
        'terminal_result' => $cell['terminal_result'] ?? null,
        'history_events' => $cell['history_events'] ?? null,
        'retry_history_events' => $cell['retry_history_events'] ?? null,
    ];

    $scenario = [
        'scenario_id' => 'retry_attempt_backoff_behavior',
        'status' => $pass ? 'pass' : 'fail',
        'classification' => $pass ? null : 'product-gap',
        'observed_outputs' => array_filter($observed, static fn (mixed $value): bool => $value !== null && $value !== []),
        'scenario_evidence' => array_filter([
            'retry_backoff_attempt_behavior' => $cell,
        ], static fn (mixed $value): bool => $value !== null && $value !== []),
    ];

    if (! $pass) {
        $message = 'activity retry/backoff did not prove attempt increment, configured backoff, and terminal completion';
        $scenario['observed_behavior'] = $message;
        $scenario['linked_findings'] = [finding_for_failure('retry_attempt_backoff_behavior', $message)];
    }

    return $scenario;
}

function run_cells_for(string $scenarioId, string $mode): array
{
    $cells = [];
    foreach (['workflow-php', 'sdk-python'] as $runtime) {
        try {
            $cells[] = $scenarioId === 'workflow_embedded_activity_result'
                ? run_embedded_cell($runtime)
                : run_standalone_cell($runtime);
        } catch (Throwable $throwable) {
            $cells[] = [
                'mode' => $mode,
                'runtime' => $runtime,
                'status' => 'fail',
                'execution_source' => HOST_EVIDENCE_SOURCE,
                'failure' => $throwable::class.': '.$throwable->getMessage(),
            ];
        }
    }

    return $cells;
}

try {
    bootstrap_application($repoRoot);

    $embeddedCells = run_cells_for('workflow_embedded_activity_result', 'workflow-embedded');
    $standaloneCells = run_cells_for('standalone_activity_result', 'standalone');
    $restartScenario = failure_behavior_scenario(
        'durable_result_recording_after_worker_restart',
        new RuntimeException('restart durability scenario did not execute')
    );
    try {
        $restartScenario = scenario_from_restart_cell(run_restart_durable_result_cell());
    } catch (Throwable $throwable) {
        $restartScenario = failure_behavior_scenario('durable_result_recording_after_worker_restart', $throwable);
    }
    $retryScenario = failure_behavior_scenario(
        'retry_attempt_backoff_behavior',
        new RuntimeException('retry/backoff scenario did not execute')
    );
    try {
        $retryScenario = scenario_from_retry_backoff_cell(run_retry_backoff_cell());
    } catch (Throwable $throwable) {
        $retryScenario = failure_behavior_scenario('retry_attempt_backoff_behavior', $throwable);
    }

    write_json_file(output_path(), evidence_document([
        scenario_from_cells('workflow_embedded_activity_result', 'workflow-embedded', $embeddedCells),
        scenario_from_cells('standalone_activity_result', 'standalone', $standaloneCells),
        $restartScenario,
        $retryScenario,
    ], array_merge($embeddedCells, $standaloneCells)));
} catch (Throwable $throwable) {
    write_json_file(output_path(), evidence_document([
        failure_scenario('workflow_embedded_activity_result', 'workflow-embedded', $throwable),
        failure_scenario('standalone_activity_result', 'standalone', $throwable),
        failure_behavior_scenario('durable_result_recording_after_worker_restart', $throwable),
        failure_behavior_scenario('retry_attempt_backoff_behavior', $throwable),
    ], []));
}
PHP
}

if should_run_focused_activity_host_probe; then
  run_focused_activity_host_probe
fi

started_at="$(timestamp)"

if ! require_command node; then
  printf '%s\n' 'required command not found: node' >&2
  exit 1
fi

RESULT_DIR="$result_dir" \
STARTED_AT="$started_at" \
SCENARIO_MANIFEST="$scenario_manifest" \
RUNNER_REPO_ROOT="$repo_root" \
node <<'JS'
const fs = require('fs');
const path = require('path');

const RESULT_DIR = process.env.RESULT_DIR;
const STARTED_AT = process.env.STARTED_AT;
const MANIFEST_PATH = process.env.SCENARIO_MANIFEST;

const REQUIRED_SCENARIOS = [
  'published_artifact_install_only',
  'workflow_embedded_activity_result',
  'standalone_activity_result',
  'durable_result_recording_after_worker_restart',
  'retry_attempt_backoff_behavior',
  'timeout_behavior',
  'typed_failure_propagation',
  'heartbeat_and_cancellation_observation',
  'idempotent_completion_handling',
  'php_python_activity_parity',
  'operator_visible_activity_attempt_state',
];

const REQUIRED_INSTALL_ARTIFACTS = [
  'server',
  'cli',
  'sdk-python',
  'workflow-php',
  'waterline',
];

const DEFAULT_EXPECTED_BEHAVIOR = {
  published_artifact_install_only:
    'all artifacts are resolved from published channels and no local product checkout is used as an artifact under test',
  workflow_embedded_activity_result:
    'a workflow-scheduled activity completes through the worker protocol and the workflow observes the exact typed result',
  standalone_activity_result:
    'a top-level activity started through POST /api/activities closes its host run with the activity result',
  durable_result_recording_after_worker_restart:
    'activity result recording survives worker restart and replay does not duplicate completion',
  retry_attempt_backoff_behavior:
    'failed attempts increment attempt state, respect configured backoff, and complete or fail according to retry policy',
  timeout_behavior:
    'start-to-close or schedule-to-close deadline is visible to the worker and enforced as a typed timeout',
  typed_failure_propagation:
    'activity failures preserve type, message, and details through history and the caller runtime',
  heartbeat_and_cancellation_observation:
    'activity heartbeat details are recorded and cancellation is observable by a running worker',
  idempotent_completion_handling:
    'duplicate completion attempts do not create duplicate terminal records and return a deterministic worker-protocol response',
  php_python_activity_parity:
    'PHP and Python activity workers produce compatible payload, failure, retry, timeout, and heartbeat observations where both runtimes support the surface',
  operator_visible_activity_attempt_state:
    'operators can see current and historical activity attempt state through API metrics and Waterline',
};

const SEMVER_RE = /^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/;
const SERVER_TAG_RE = /(?::|\/)(\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?)$/;
const PLACEHOLDER_RE = /(<[^>]+>|\$\{[^}]+}|{{[^}]+}}|(^|[^a-z0-9])latest([^a-z0-9]|$)|current|head|unresolved|placeholder)/i;
const ALLOWED_STATUSES = new Set(['pass', 'fail', 'unsupported', 'not_covered', 'runner_blocked']);
const NON_PASS_CLASSIFICATIONS = new Set([
  'product-gap',
  'coverage-gap',
  'runner-gap',
  'stale-artifact',
  'pipeline-churn',
]);
const FORBIDDEN_INSTALL_SOURCE_TOKENS = [
  'local_product_source_checkout',
  'workspace_repo_as_artifact_under_test',
  'local_checkout_artifact',
  'local_source_checkout',
  'local_checkout',
  'source_checkout',
  'workspace_repo',
  '/workspace/repos/',
];
const PUBLISHED_SERVER_IMAGE_REPOSITORIES = [
  'durableworkflow/server',
  'docker.io/durableworkflow/server',
  'index.docker.io/durableworkflow/server',
  'registry-1.docker.io/durableworkflow/server',
  'ghcr.io/durable-workflow/server',
];
const SOURCE_FREE_RUNNER_STATEMENT = 'Activities conformance ran from the pinned published server container; local product checkouts, branch source, and local vendor trees were not used as pass evidence.';
const PUBLISHED_SERVER_CONTAINER_EXECUTION_SOURCE = 'published_server_container';
const FOCUSED_ACTIVITY_HOST_SCENARIOS = new Set([
  'workflow_embedded_activity_result',
  'standalone_activity_result',
]);

function now() {
  return new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
}

function env(name) {
  return (process.env[name] || '').trim();
}

function writeJson(file, value) {
  fs.writeFileSync(file, `${JSON.stringify(value, null, 2)}\n`, 'utf8');
}

function readJsonFile(file) {
  return JSON.parse(fs.readFileSync(file, 'utf8'));
}

function loadJsonFromStringOrPath(raw, file) {
  if (raw && raw.trim() !== '') {
    return {
      supplied: true,
      source: 'environment',
      value: JSON.parse(raw),
    };
  }

  if (file && fs.existsSync(file)) {
    return {
      supplied: true,
      source: file,
      value: readJsonFile(file),
    };
  }

  return {
    supplied: false,
    source: file || '',
    value: null,
  };
}

function safeLoadJsonFromStringOrPath(raw, file, fallbackSchema) {
  try {
    return loadJsonFromStringOrPath(raw, file);
  } catch (error) {
    return {
      supplied: true,
      source: raw && raw.trim() !== '' ? 'environment' : file,
      value: {
        schema: fallbackSchema,
        generated_at: now(),
        load_error: String(error && error.message ? error.message : error),
      },
    };
  }
}

function stringValue(value) {
  if (value === null || value === undefined) {
    return '';
  }
  if (typeof value === 'string') {
    return value.trim();
  }
  if (typeof value === 'number' || typeof value === 'boolean') {
    return String(value).trim();
  }
  return '';
}

function truthy(value) {
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

function normalizeCliVersion(value) {
  return value.startsWith('v') && SEMVER_RE.test(value.slice(1)) ? value.slice(1) : value;
}

function deriveServerVersion(serverImage, explicitVersion) {
  if (explicitVersion) {
    return explicitVersion;
  }
  const match = SERVER_TAG_RE.exec(serverImage);
  return match ? match[1] : '';
}

function isPlaceholder(value) {
  return value !== '' && PLACEHOLDER_RE.test(value);
}

function exactVersionFailures(versions, serverImage) {
  const failures = [];
  const required = {
    server: 'DW_SERVER_VERSION or exact DW_SERVER_IMAGE tag',
    cli: 'DW_CLI_VERSION',
    'sdk-python': 'DW_PYTHON_SDK_VERSION',
    workflow: 'DW_WORKFLOW_PHP_VERSION',
    waterline: 'DW_WATERLINE_VERSION',
  };

  for (const [key, label] of Object.entries(required)) {
    const version = versions[key] || '';
    if (!version) {
      failures.push(`missing ${label}`);
      continue;
    }
    if (isPlaceholder(version) || !SEMVER_RE.test(version)) {
      failures.push(`${label} must be an exact semver artifact version; got ${JSON.stringify(version)}`);
    }
  }

  if (serverImage) {
    if (isPlaceholder(serverImage)) {
      failures.push(`DW_SERVER_IMAGE must not use a rolling tag or placeholder; got ${JSON.stringify(serverImage)}`);
    }
    const tagMatch = SERVER_TAG_RE.exec(serverImage);
    if (tagMatch && versions.server && tagMatch[1] !== versions.server) {
      failures.push(`DW_SERVER_VERSION ${JSON.stringify(versions.server)} does not match DW_SERVER_IMAGE tag ${JSON.stringify(tagMatch[1])}`);
    }
    if (serverImage.includes('@sha256:') && !versions.server) {
      failures.push('DW_SERVER_VERSION is required when DW_SERVER_IMAGE is digest-pinned');
    }
  }

  return failures;
}

function normalizedStatus(value) {
  const status = stringValue(value).toLowerCase();
  if (['pass', 'passed', 'success', 'ok'].includes(status)) {
    return 'pass';
  }
  if (['fail', 'failed', 'failure'].includes(status)) {
    return 'fail';
  }
  if (['blocked', 'runner_blocked', 'error'].includes(status)) {
    return 'runner_blocked';
  }
  if (['not_covered', 'missing', 'not_exercised'].includes(status)) {
    return 'not_covered';
  }
  if (status === 'unsupported') {
    return 'unsupported';
  }
  return status;
}

function artifactVersionFor(versions, artifact) {
  const aliases = {
    'workflow-php': ['workflow-php', 'workflow'],
    'sdk-python': ['sdk-python', 'sdk_python', 'python'],
  };
  for (const key of aliases[artifact] || [artifact]) {
    const value = versions[key] || '';
    if (value) {
      return value;
    }
  }
  return '';
}

function entrySource(entry) {
  for (const key of [
    'source',
    'install_source',
    'installSource',
    'artifact_source',
    'artifactSource',
    'resolved_source',
    'resolvedSource',
  ]) {
    const value = stringValue(entry[key]);
    if (value) {
      return value;
    }
  }
  return '';
}

function normalizeArtifactInstallEvidence(evidenceLoad, artifactVersions) {
  const evidence = evidenceLoad.value && typeof evidenceLoad.value === 'object' ? evidenceLoad.value : {};
  const rawArtifacts = Array.isArray(evidence.artifacts) ? evidence.artifacts : [];
  const byArtifact = new Map();
  for (const item of rawArtifacts) {
    if (!item || typeof item !== 'object') {
      continue;
    }
    const artifact = stringValue(item.artifact || item.name);
    if (artifact) {
      byArtifact.set(artifact, item);
    }
  }

  const artifacts = REQUIRED_INSTALL_ARTIFACTS.map((artifact) => {
    const item = byArtifact.get(artifact) || {};
    const rawVersion = stringValue(
      item.version
      || item.artifact_version
      || item.artifactVersion
      || item.resolved_version
      || item.resolvedVersion,
    );
    const rawSource = entrySource(item);
    return {
      artifact,
      version: rawVersion || artifactVersionFor(artifactVersions, artifact),
      version_provided: rawVersion !== '',
      source: rawSource || 'not_exercised',
      source_provided: rawSource !== '',
      status: normalizedStatus(item.status || item.result || item.outcome),
      local_product_source_checkouts_used: truthy(
        item.local_product_source_checkouts_used || item.localProductSourceCheckoutsUsed,
      ),
      detail: stringValue(item.detail || item.observed_behavior),
      command: item.command || null,
      output_sample: item.output_sample || item.outputSample || '',
    };
  });

  const topLocal = truthy(evidence.local_product_source_checkouts_used || evidence.localProductSourceCheckoutsUsed);
  const topExplicitFalse = explicitFalse(evidence.local_product_source_checkouts_used)
    || explicitFalse(evidence.localProductSourceCheckoutsUsed);

  return {
    schema: stringValue(evidence.schema) || 'durable-workflow.v2.activity-runtime.artifact-install-evidence',
    generated_at: stringValue(evidence.generated_at) || now(),
    supplied: evidenceLoad.supplied,
    source: evidenceLoad.source,
    load_error: stringValue(evidence.load_error),
    local_product_source_checkouts_used: topLocal
      || artifacts.some((artifact) => artifact.local_product_source_checkouts_used),
    local_product_source_checkouts_used_explicit_false: topExplicitFalse,
    artifacts,
  };
}

function installSourceIsForbidden(source) {
  const normalized = source.toLowerCase();
  const decoded = decodeURIComponentSafe(normalized);
  return [normalized, decoded].some((candidate) => {
    return FORBIDDEN_INSTALL_SOURCE_TOKENS.some((token) => candidate.includes(token))
      || sourceLooksLocal(candidate);
  });
}

function installSourceMatchesArtifact(artifact, version, source) {
  if (!source || source === 'not_exercised' || isPlaceholder(source) || installSourceIsForbidden(source)) {
    return false;
  }
  if (!version || isPlaceholder(version)) {
    return false;
  }

  switch (artifact) {
    case 'server':
      return matchesServerArtifactSource(version, source);
    case 'cli':
      return matchesCliArtifactSource(version, source);
    case 'sdk-python':
      return matchesPythonArtifactSource(version, source);
    case 'workflow-php':
      return matchesComposerArtifactSource('durable-workflow/workflow', version, source);
    case 'waterline':
      return matchesComposerArtifactSource('durable-workflow/waterline', version, source);
    default:
      return false;
  }
}

function matchesServerArtifactSource(version, source) {
  const image = source.replace(/^docker:\/\//i, '');
  if (!image) {
    return false;
  }

  return PUBLISHED_SERVER_IMAGE_REPOSITORIES.some((repository) => {
    const escapedRepository = escapeRegExp(repository);
    const escapedVersion = escapeRegExp(version);

    return image.toLowerCase() === `${repository}:${version}`.toLowerCase()
      || new RegExp(`^${escapedRepository}@sha256:[0-9a-f]{64}$`, 'i').test(image)
      || new RegExp(`^${escapedRepository}:${escapedVersion}@sha256:[0-9a-f]{64}$`, 'i').test(image);
  });
}

function decodeURIComponentSafe(value) {
  try {
    return decodeURIComponent(value);
  } catch (_error) {
    return value;
  }
}

function sourceLooksLocal(source) {
  const normalized = source.replace(/\\/g, '/').trim().toLowerCase();
  return normalized.startsWith('file:')
    || /^local(?::|\/|$)/.test(normalized)
    || /^~(?:[^/]*)?(?:\/|$)/.test(normalized)
    || /^\$(?:home|userprofile)(?:\/|$)/.test(normalized)
    || /^\$\{(?:home|userprofile)\}(?:\/|$)/.test(normalized)
    || /^%(?:home|userprofile|homedrive|homepath)%/.test(normalized)
    || /^\/[^/]+/.test(normalized)
    || /^[a-z]:\//.test(normalized)
    || /^\.\.?(?:\/|$)/.test(normalized)
    || /(^|[^a-z0-9])\/?workspace\/repos\//.test(normalized)
    || /^repos\/(?:server|workflow|waterline|cli|cloud|sample-app|sdk-python|durable-workflow\.github\.io)(?:\/|$)/.test(normalized);
}

function matchesCliArtifactSource(version, source) {
  const prefixes = [
    `https://github.com/durable-workflow/cli/releases/download/${version}/`,
    `https://github.com/durable-workflow/cli/releases/download/v${version}/`,
  ];

  return prefixes.some((prefix) => source.startsWith(prefix) && source.slice(prefix.length) !== '');
}

function matchesPythonArtifactSource(version, source) {
  return source === `pypi://durable-workflow==${version}`
    || source === `https://pypi.org/project/durable-workflow/${version}/`
    || (
      (source.startsWith('https://files.pythonhosted.org/') || source.startsWith('https://pypi.io/packages/'))
      && (
        source.includes(`/durable_workflow-${version}`)
        || source.includes(`/durable-workflow-${version}`)
      )
    );
}

function matchesComposerArtifactSource(packageName, version, source) {
  return source === `packagist://${packageName}@${version}`
    || source === `composer://${packageName}:${version}`
    || source === `https://repo.packagist.org/p2/${packageName}.json#${version}`
    || source === `https://packagist.org/packages/${packageName}#${version}`;
}

function escapeRegExp(value) {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function artifactInstallEvidenceFailures(evidence, artifactVersions) {
  const failures = [];
  if (!evidence.supplied) {
    failures.push('artifact_install_evidence missing');
  }
  if (evidence.load_error) {
    failures.push(`artifact_install_evidence load failed: ${evidence.load_error}`);
  }
  if (evidence.local_product_source_checkouts_used) {
    failures.push('artifact_install_evidence.local_product_source_checkouts_used=true');
  }
  if (evidence.supplied && !evidence.local_product_source_checkouts_used_explicit_false) {
    failures.push('artifact_install_evidence.local_product_source_checkouts_used=false missing');
  }

  for (const entry of evidence.artifacts) {
    const expectedVersion = artifactVersionFor(artifactVersions, entry.artifact);
    if (entry.status !== 'pass') {
      failures.push(`${entry.artifact}.status=${entry.status || 'missing'}`);
    }
    if (!entry.version_provided) {
      failures.push(`${entry.artifact}.version=missing`);
    } else if (!entry.version || !SEMVER_RE.test(entry.version) || isPlaceholder(entry.version)) {
      failures.push(`${entry.artifact}.version=${entry.version || 'missing'}`);
    } else if (expectedVersion && entry.version !== expectedVersion) {
      failures.push(`${entry.artifact}.version=${entry.version} does not match resolved artifact version ${expectedVersion}`);
    }
    if (!entry.source_provided) {
      failures.push(`${entry.artifact}.source=missing`);
    } else if (!installSourceMatchesArtifact(entry.artifact, entry.version, entry.source)) {
      failures.push(`${entry.artifact}.source=${entry.source}`);
    }
    if (entry.local_product_source_checkouts_used) {
      failures.push(`${entry.artifact}.local_product_source_checkouts_used=true`);
    }
  }

  return failures;
}

function artifactSourcesFromInstallEvidence(evidence) {
  const sources = {};
  for (const entry of evidence.artifacts) {
    sources[entry.artifact] = entry.source || 'not_exercised';
  }
  sources.workflow = sources['workflow-php'] || 'not_exercised';
  return sources;
}

function loadManifest() {
  if (!MANIFEST_PATH || !fs.existsSync(MANIFEST_PATH)) {
    return {};
  }
  return readJsonFile(MANIFEST_PATH);
}

function scenarioDefs(manifest) {
  if (Array.isArray(manifest.scenarios) && manifest.scenarios.length > 0) {
    return manifest.scenarios.filter((item) => item && typeof item === 'object');
  }
  return REQUIRED_SCENARIOS.map((id) => ({
    id,
    expected_behavior: DEFAULT_EXPECTED_BEHAVIOR[id],
  }));
}

function requiredMatrix(manifest) {
  if (manifest.required_matrix && typeof manifest.required_matrix === 'object') {
    return manifest.required_matrix;
  }
  return {
    execution_modes: ['workflow-embedded', 'standalone'],
    runtimes: ['workflow-php', 'sdk-python'],
    activity_cells: [
      { mode: 'workflow-embedded', runtime: 'workflow-php', scenario: 'workflow_embedded_activity_result' },
      { mode: 'workflow-embedded', runtime: 'sdk-python', scenario: 'workflow_embedded_activity_result' },
      { mode: 'standalone', runtime: 'workflow-php', scenario: 'standalone_activity_result' },
      { mode: 'standalone', runtime: 'sdk-python', scenario: 'standalone_activity_result' },
    ],
    behavior_cells: REQUIRED_SCENARIOS.filter((id) => ![
      'published_artifact_install_only',
      'workflow_embedded_activity_result',
      'standalone_activity_result',
    ].includes(id)),
  };
}

function scenarioEvidenceById(evidence) {
  const byId = new Map();
  if (!evidence || typeof evidence !== 'object') {
    return byId;
  }

  const rawResults = evidence.scenario_results || evidence.scenarioResults || evidence.scenarios || [];
  if (Array.isArray(rawResults)) {
    for (const item of rawResults) {
      if (!item || typeof item !== 'object') {
        continue;
      }
      const id = stringValue(item.scenario_id || item.scenarioId || item.id);
      if (id) {
        byId.set(id, item);
      }
    }
  } else if (rawResults && typeof rawResults === 'object') {
    for (const [id, item] of Object.entries(rawResults)) {
      if (item && typeof item === 'object') {
        byId.set(id, { scenario_id: id, ...item });
      }
    }
  }

  return byId;
}

function observedOutputsFor(item) {
  if (!item || typeof item !== 'object') {
    return {};
  }
  for (const key of ['observed_outputs', 'observedOutputs', 'activity_evidence', 'activityEvidence', 'evidence']) {
    if (item[key] && typeof item[key] === 'object' && !Array.isArray(item[key])) {
      return item[key];
    }
  }
  return {};
}

function nonEmptyObject(value) {
  return value && typeof value === 'object' && !Array.isArray(value) && Object.keys(value).length > 0;
}

function firstObjectValue(...values) {
  for (const value of values) {
    if (value && typeof value === 'object' && !Array.isArray(value)) {
      return value;
    }
  }
  return {};
}

function publishedRuntimeExecutionEvidence(evidence) {
  if (!evidence || typeof evidence !== 'object' || Array.isArray(evidence)) {
    return {};
  }

  return firstObjectValue(
    evidence.published_artifact_worker_execution,
    evidence.publishedArtifactWorkerExecution,
    evidence.published_server_artifact_execution,
    evidence.publishedServerArtifactExecution,
    evidence.published_artifact_execution,
    evidence.publishedArtifactExecution,
    evidence.published_server_image_activity_runtime_probe,
    evidence.publishedServerImageActivityRuntimeProbe,
    evidence.activity_runtime_probe,
    evidence.activityRuntimeProbe,
  );
}

function resolvePublishedRuntimeExecutionEvidence(evidence, serverImage, serverVersion) {
  const supplied = publishedRuntimeExecutionEvidence(evidence);
  if (nonEmptyObject(supplied)) {
    return {
      evidence: supplied,
      source: 'host_evidence',
      execution_source: stringValue(supplied.execution_source || supplied.executionSource) || 'host_evidence',
      derived: false,
      derivation_reason: '',
    };
  }

  const derived = derivedPublishedRuntimeExecutionEvidence(evidence, serverImage, serverVersion);
  if (nonEmptyObject(derived.evidence)) {
    return derived;
  }

  return {
    evidence: {},
    source: 'missing',
    execution_source: 'missing',
    derived: false,
    derivation_reason: derived.derivation_reason,
  };
}

function derivedPublishedRuntimeExecutionEvidence(evidence, serverImage, serverVersion) {
  const runnerSource = env('DW_ACTIVITIES_RUNNER_SOURCE')
    || env('DW_ACTIVITIES_PUBLISHED_SERVER_RUNNER_SOURCE')
    || serverImage;
  const runnerRoot = stringValue(process.env.RUNNER_REPO_ROOT);
  const localSignals = localSourceSignals(evidence).slice(0, 3);

  if (!serverImage || !serverVersion) {
    return {
      evidence: {},
      derivation_reason: 'DW_SERVER_IMAGE and DW_SERVER_VERSION are required to derive pinned published server execution evidence',
    };
  }
  if (!runnerSource || !imageSourceMatchesPinned(runnerSource, serverVersion, serverImage)) {
    return {
      evidence: {},
      derivation_reason: `activities runner source ${runnerSource || 'missing'} does not match pinned DW_SERVER_IMAGE ${serverImage || 'missing'}`,
    };
  }
  if (localSignals.length > 0) {
    return {
      evidence: {},
      derivation_reason: `activity evidence contains local product source probe signals: ${localSignals.join('; ')}`,
    };
  }
  if (!runnerRootLooksLikePublishedImageRoot(runnerRoot)) {
    return {
      evidence: {},
      derivation_reason: `activities runner did not execute from the published server image root: ${runnerRoot || 'missing'}`,
    };
  }

  return {
    evidence: {
      schema: 'durable-workflow.v2.activity-runtime.published-server-execution',
      status: 'pass',
      execution_source: PUBLISHED_SERVER_CONTAINER_EXECUTION_SOURCE,
      execution_environment: 'docker_container',
      worker_execution_mode: 'published_server_image_conformance_handoff',
      executed_in_pinned_server_artifact: true,
      local_product_source_checkouts_used: false,
      source_integrity_statement: SOURCE_FREE_RUNNER_STATEMENT,
      image_identity: {
        pinned_server_image: serverImage,
        runner_source: runnerSource,
        matches_pinned_server_image: true,
      },
      artifacts: [
        {
          artifact: 'server',
          version: serverVersion,
          source: runnerSource,
          status: 'pass',
          execution_source: PUBLISHED_SERVER_CONTAINER_EXECUTION_SOURCE,
          execution_context: 'published_server_image_conformance_handoff',
          local_product_source_checkouts_used: false,
          source_integrity_statement: SOURCE_FREE_RUNNER_STATEMENT,
        },
      ],
    },
    source: 'published_server_image_runtime',
    execution_source: PUBLISHED_SERVER_CONTAINER_EXECUTION_SOURCE,
    derived: true,
    derivation_reason: '',
  };
}

function runnerRootLooksLikePublishedImageRoot(runnerRoot) {
  if (!runnerRoot) {
    return false;
  }

  const normalizedRoot = path.resolve(runnerRoot);
  if (normalizedRoot !== '/app') {
    return false;
  }
  if (fs.existsSync(path.join(normalizedRoot, '.git'))) {
    return false;
  }
  if (!fs.existsSync(path.join(normalizedRoot, 'artisan'))) {
    return false;
  }
  if (!fs.existsSync(path.join(normalizedRoot, 'scripts/conformance/activities-published-artifacts.sh'))) {
    return false;
  }

  return containerRuntimeDetected();
}

function containerRuntimeDetected() {
  if (fs.existsSync('/.dockerenv') || fs.existsSync('/run/.containerenv')) {
    return true;
  }

  try {
    const cgroup = fs.readFileSync('/proc/self/cgroup', 'utf8');
    return /(docker|kubepods|containerd|podman|libpod)/i.test(cgroup);
  } catch (_error) {
    return false;
  }
}

function executionEntries(execution) {
  if (!execution || typeof execution !== 'object' || Array.isArray(execution)) {
    return [];
  }

  const entries = Array.isArray(execution.artifacts)
    ? execution.artifacts
    : (
        Array.isArray(execution.workers)
          ? execution.workers
          : (Array.isArray(execution.executions) ? execution.executions : [])
      );

  if (entries.length > 0) {
    return entries.filter((entry) => entry && typeof entry === 'object' && !Array.isArray(entry));
  }

  if (execution.artifact || execution.name || execution.source || execution.server_image || execution.image) {
    return [execution];
  }

  return [];
}

function canonicalExecutionArtifact(value) {
  const normalized = stringValue(value).toLowerCase().replace(/[_\s]/g, '-');
  if (['server', 'durableworkflow/server', 'durable-workflow/server'].includes(normalized)) {
    return 'server';
  }
  return normalized;
}

function executionSource(entry) {
  return entrySource(entry)
    || stringValue(entry.server_image)
    || stringValue(entry.serverImage)
    || stringValue(entry.image)
    || stringValue(entry.dw_server_image)
    || stringValue(entry.dwServerImage);
}

function executionVersion(entry) {
  return stringValue(
    entry.version
    || entry.artifact_version
    || entry.artifactVersion
    || entry.server_version
    || entry.serverVersion,
  );
}

function normalizeDockerImage(value) {
  return stringValue(value).replace(/^docker:\/\//i, '').toLowerCase();
}

function imageSourceMatchesPinned(source, serverVersion, serverImage) {
  const normalizedSource = normalizeDockerImage(source);
  const normalizedPinned = normalizeDockerImage(serverImage);

  if (!normalizedSource || !normalizedPinned) {
    return false;
  }

  if (normalizedPinned.includes('@sha256:')) {
    return normalizedSource === normalizedPinned;
  }

  return normalizedSource === normalizedPinned || matchesServerArtifactSource(serverVersion, source);
}

function executionClaimsContainer(execution) {
  if (truthy(execution.executed_in_pinned_server_artifact)
    || truthy(execution.executedInPinnedServerArtifact)
    || truthy(execution.executed_in_container)
    || truthy(execution.executedInContainer)
    || truthy(execution.containerized)) {
    return true;
  }

  const mode = [
    execution.execution_environment,
    execution.executionEnvironment,
    execution.runtime_environment,
    execution.runtimeEnvironment,
    execution.worker_execution_mode,
    execution.workerExecutionMode,
  ].map(stringValue).join(' ').toLowerCase();

  return mode.includes('container') || mode.includes('docker') || stringValue(execution.container_id || execution.containerId) !== '';
}

function localSourceSignals(value, signals = [], depth = 0) {
  if (depth > 8 || value === null || value === undefined) {
    return signals;
  }

  if (typeof value === 'string') {
    const normalized = value.replace(/\\/g, '/').toLowerCase();
    if (normalized.includes('/workspace/repos/')
      || normalized.includes('repo_root')
      || normalized.includes('$repo_root')
      || normalized.includes('${repo_root}')
      || normalized.includes('workspace_repo_as_artifact_under_test')
      || normalized.includes('local_product_source_checkout')
      || normalized.includes('local_checkout')
      || normalized.includes('local_source_checkout')
      || normalized.includes('source_checkout')) {
      signals.push(value);
    }
    return signals;
  }

  if (Array.isArray(value)) {
    for (const item of value) {
      localSourceSignals(item, signals, depth + 1);
    }
    return signals;
  }

  if (typeof value === 'object') {
    for (const item of Object.values(value)) {
      localSourceSignals(item, signals, depth + 1);
    }
  }

  return signals;
}

function runtimeExecutionFailures(execution, activityEvidence, serverImage, serverVersion) {
  const failures = [];

  if (!nonEmptyObject(execution)) {
    failures.push('published_artifact_worker_execution missing');
    const localSignals = localSourceSignals(activityEvidence).slice(0, 3);
    if (localSignals.length > 0) {
      failures.push(`activity evidence contains local product source probe signals: ${localSignals.join('; ')}`);
    }
    return failures;
  }

  const localSignals = localSourceSignals(execution).slice(0, 3);
  if (localSignals.length > 0) {
    failures.push(`published_artifact_worker_execution contains local product source probe signals: ${localSignals.join('; ')}`);
  }

  if (!explicitFalse(execution.local_product_source_checkouts_used)
    && !explicitFalse(execution.localProductSourceCheckoutsUsed)) {
    failures.push('published_artifact_worker_execution.local_product_source_checkouts_used=false missing');
  }
  if (!sourceIntegrityStatementPresent(execution)) {
    failures.push('published_artifact_worker_execution.source_integrity_statement must state local product checkouts, branch source, and local vendor trees were not used as pass evidence');
  }

  if (!executionClaimsContainer(execution)) {
    failures.push('published_artifact_worker_execution must prove execution inside the pinned server container');
  }

  const entries = executionEntries(execution);
  const serverEntries = entries.filter((entry) => {
    const artifact = canonicalExecutionArtifact(entry.artifact || entry.name || entry.id || 'server');
    return artifact === 'server';
  });
  if (serverEntries.length === 0) {
    failures.push('published_artifact_worker_execution.artifacts.server missing');
    return failures;
  }

  let sawValidServerEntry = false;
  for (const entry of serverEntries) {
    const status = normalizedStatus(entry.status || entry.result || entry.outcome);
    const source = executionSource(entry);
    const version = executionVersion(entry);

    if (status !== 'pass') {
      failures.push(`published_artifact_worker_execution.server.status=${status || 'missing'}`);
    }
    if (version !== serverVersion) {
      failures.push(`published_artifact_worker_execution.server.version=${version || 'missing'} does not match ${serverVersion || 'missing'}`);
    }
    if (!source) {
      failures.push('published_artifact_worker_execution.server.source=missing');
    } else if (installSourceIsForbidden(source)) {
      failures.push(`published_artifact_worker_execution.server.source is local or forbidden: ${source}`);
    } else if (!imageSourceMatchesPinned(source, serverVersion, serverImage)) {
      failures.push(`published_artifact_worker_execution.server.source=${source} does not match pinned DW_SERVER_IMAGE ${serverImage || 'missing'}`);
    }
    if (truthy(entry.local_product_source_checkouts_used) || truthy(entry.localProductSourceCheckoutsUsed)) {
      failures.push('published_artifact_worker_execution.server.local_product_source_checkouts_used=true');
    }

    if (status === 'pass'
      && version === serverVersion
      && source
      && !installSourceIsForbidden(source)
      && imageSourceMatchesPinned(source, serverVersion, serverImage)
      && !truthy(entry.local_product_source_checkouts_used)
      && !truthy(entry.localProductSourceCheckoutsUsed)) {
      sawValidServerEntry = true;
    }
  }

  if (!sawValidServerEntry) {
    failures.push('published_artifact_worker_execution lacks a passing server artifact entry for the pinned DW_SERVER_IMAGE');
  }

  return failures;
}

function sourceIntegrityStatementPresent(execution) {
  const statement = stringValue(
    execution.source_integrity_statement
    || execution.sourceIntegrityStatement
    || execution.no_local_source_statement
    || execution.noLocalSourceStatement,
  ).toLowerCase();

  return statement.includes('local product checkout')
    && statement.includes('branch source')
    && statement.includes('local vendor');
}

function normalizeClassification(value, fallback) {
  const classification = stringValue(value);
  if (NON_PASS_CLASSIFICATIONS.has(classification)) {
    return classification;
  }
  return fallback;
}

function finding(scenarioId, expectedBehavior, artifactVersions, options) {
  const runnerBlocked = options.runnerBlocked || false;
  const classification = options.classification || (runnerBlocked ? 'runner-gap' : 'coverage-gap');
  const findingType = options.findingType
    || (classification === 'coverage-gap'
      ? 'conformance_runner_coverage_gap'
      : classification.replace('-', '_'));
  const reason = options.reason || '';
  let observed = options.observedBehavior || '';
  if (!observed) {
    if (runnerBlocked) {
      observed = `activities conformance could not execute before product evidence was collected: ${reason}`;
    } else if (classification === 'coverage-gap') {
      observed = 'activities published-artifact evidence did not execute this required scenario; the result is routed as a coverage gap instead of being counted as passing incidental coverage';
      if (reason) {
        observed += `: ${reason}`;
      }
    } else {
      observed = reason || 'activities conformance recorded a non-passing product cell';
    }
  }

  return {
    scenario_id: scenarioId,
    finding_type: findingType,
    classification,
    root_cause_classification: classification,
    owning_surface: options.owner || (classification === 'coverage-gap' || classification === 'runner-gap'
      ? 'conformance_harness'
      : 'activity_runtime'),
    artifact_versions: artifactVersions,
    expected_behavior: expectedBehavior,
    observed_behavior: observed,
    user_visible_reproduction_steps: [
      'Set exact DW_SERVER_VERSION, DW_CLI_VERSION, DW_PYTHON_SDK_VERSION, DW_WORKFLOW_PHP_VERSION, and DW_WATERLINE_VERSION values.',
      'Run scripts/conformance/activities-published-artifacts.sh --result-dir <result-dir> with a host-produced activity evidence document.',
      'Inspect activities-result.json for the scenario status, classification, and linked finding.',
    ],
    next_acceptance_criterion: options.nextAcceptanceCriterion
      || (classification === 'coverage-gap'
        ? 'extend the activities host runner to execute this scenario against published artifacts, or replace this coverage-gap finding with a focused product finding from the observed runtime mismatch'
        : 'fix the routed activity conformance root cause and rerun the published-artifact activities experiment'),
    priority: options.priority || (runnerBlocked ? 'P0' : 'P1'),
  };
}

function withCellStatus(cells, status) {
  if (!Array.isArray(cells)) {
    return [];
  }
  return cells
    .filter((cell) => cell && typeof cell === 'object')
    .map((cell) => ({ ...cell, status }));
}

function evidenceStatusSections(status, reason) {
  const section = (extra = {}) => ({
    status,
    reason,
    ...extra,
  });

  return {
    durable_result_recording: section({
      required_behavior: 'activity result survives worker restart and replay without duplicate completion',
    }),
    retry_backoff: section({
      required_behavior: 'attempt count and backoff timing are recorded',
    }),
    timeout_behavior: section({
      required_behavior: 'start-to-close or schedule-to-close timeout is enforced and typed',
    }),
    typed_failure_propagation: section({
      required_behavior: 'failure type, message, and details propagate through history and caller runtime',
    }),
    heartbeat_cancellation: section({
      required_behavior: 'heartbeat details and cancel_requested observation are recorded',
    }),
    idempotent_completion: section({
      required_behavior: 'duplicate completion attempts are deterministic and do not duplicate terminal records',
    }),
    operator_visibility: section({
      required_behavior: 'activity attempt state is visible through API metrics, history, and Waterline',
    }),
  };
}

function sectionFromEvidence(evidence, key, fallback) {
  if (evidence && typeof evidence === 'object' && evidence[key] && typeof evidence[key] === 'object') {
    return evidence[key];
  }
  return fallback;
}

function observedOutputsWithRuntimeExecution(outputs, runtimeExecutionPass, runtimeExecution) {
  if (!runtimeExecutionPass) {
    return outputs;
  }

  return {
    ...outputs,
    published_artifact_worker_execution: runtimeExecution,
  };
}

function activityHostEvidenceFor(supplied, observedOutputs) {
  const scenarioEvidence = firstObjectValue(
    supplied?.scenario_evidence,
    supplied?.scenarioEvidence,
  );

  return firstObjectValue(
    observedOutputs?.activity_host_evidence,
    observedOutputs?.activityHostEvidence,
    observedOutputs?.published_artifact_activity_host_evidence,
    observedOutputs?.publishedArtifactActivityHostEvidence,
    scenarioEvidence?.activity_host_evidence,
    scenarioEvidence?.activityHostEvidence,
    supplied?.activity_host_evidence,
    supplied?.activityHostEvidence,
  );
}

function activityHostCells(evidence) {
  if (!evidence || typeof evidence !== 'object') {
    return [];
  }
  const cells = Array.isArray(evidence.activity_cells)
    ? evidence.activity_cells
    : (Array.isArray(evidence.activityCells) ? evidence.activityCells : []);

  return cells.filter((cell) => cell && typeof cell === 'object' && !Array.isArray(cell));
}

function cellWorkerArtifact(cell) {
  return firstObjectValue(
    cell?.worker_artifact,
    cell?.workerArtifact,
    cell?.published_artifact_worker_execution,
    cell?.publishedArtifactWorkerExecution,
    cell?.sdk_python_worker_artifact,
    cell?.sdkPythonWorkerArtifact,
  );
}

function sdkPythonCellArtifactFailures(cell, artifactVersions) {
  const failures = [];
  const artifact = cellWorkerArtifact(cell);
  if (!nonEmptyObject(artifact)) {
    return ['sdk-python worker_artifact evidence missing'];
  }

  const packageVersion = artifactVersionFor(artifactVersions, 'sdk-python');
  const artifactName = stringValue(artifact.artifact || artifact.name || artifact.package_artifact || artifact.packageArtifact);
  const version = stringValue(
    artifact.version
    || artifact.package_version
    || artifact.packageVersion
    || artifact.sdk_version
    || artifact.sdkVersion,
  );
  const source = entrySource(artifact);
  const status = normalizedStatus(artifact.status || artifact.result || artifact.outcome);
  const execution = stringValue(artifact.execution_source || artifact.executionSource);
  const runtime = [
    artifact.runtime,
    artifact.language,
    artifact.worker_runtime,
    artifact.workerRuntime,
    artifact.sdk_runtime,
    artifact.sdkRuntime,
  ].map(stringValue).join(' ').toLowerCase();

  if (artifactName !== 'sdk-python') {
    failures.push(`sdk-python worker_artifact.artifact=${artifactName || 'missing'}`);
  }
  if (status !== 'pass') {
    failures.push(`sdk-python worker_artifact.status=${status || 'missing'}`);
  }
  if (version !== packageVersion) {
    failures.push(`sdk-python worker_artifact.version=${version || 'missing'} does not match ${packageVersion || 'missing'}`);
  }
  if (!source || installSourceIsForbidden(source) || !matchesPythonArtifactSource(version, source)) {
    failures.push(`sdk-python worker_artifact.source=${source || 'missing'}`);
  }
  if (execution !== PUBLISHED_SERVER_CONTAINER_EXECUTION_SOURCE) {
    failures.push(`sdk-python worker_artifact.execution_source=${execution || 'missing'}`);
  }
  if (!runtime.includes('python')) {
    failures.push(`sdk-python worker_artifact.runtime=${runtime || 'missing'}`);
  }
  if (truthy(artifact.local_product_source_checkouts_used) || truthy(artifact.localProductSourceCheckoutsUsed)) {
    failures.push('sdk-python worker_artifact.local_product_source_checkouts_used=true');
  }
  if (!explicitFalse(artifact.local_product_source_checkouts_used)
    && !explicitFalse(artifact.localProductSourceCheckoutsUsed)) {
    failures.push('sdk-python worker_artifact.local_product_source_checkouts_used=false missing');
  }
  const localSignals = localSourceSignals(artifact).slice(0, 3);
  if (localSignals.length > 0) {
    failures.push(`sdk-python worker_artifact contains local product source probe signals: ${localSignals.join('; ')}`);
  }

  return failures;
}

function focusedActivityHostEvidenceFailures(scenarioId, supplied, observedOutputs, artifactVersions) {
  if (!FOCUSED_ACTIVITY_HOST_SCENARIOS.has(scenarioId)) {
    return [];
  }

  const failures = [];
  const evidence = activityHostEvidenceFor(supplied, observedOutputs);
  if (!nonEmptyObject(evidence)) {
    return ['activity_host_evidence missing'];
  }

  const executionSource = stringValue(evidence.execution_source || evidence.executionSource);
  if (executionSource !== PUBLISHED_SERVER_CONTAINER_EXECUTION_SOURCE) {
    failures.push(`activity_host_evidence.execution_source=${executionSource || 'missing'}`);
  }
  if (truthy(evidence.local_product_source_checkouts_used) || truthy(evidence.localProductSourceCheckoutsUsed)) {
    failures.push('activity_host_evidence.local_product_source_checkouts_used=true');
  }
  if (!explicitFalse(evidence.local_product_source_checkouts_used)
    && !explicitFalse(evidence.localProductSourceCheckoutsUsed)) {
    failures.push('activity_host_evidence.local_product_source_checkouts_used=false missing');
  }
  const evidenceLocalSignals = localSourceSignals(evidence).slice(0, 3);
  if (evidenceLocalSignals.length > 0) {
    failures.push(`activity_host_evidence contains local product source probe signals: ${evidenceLocalSignals.join('; ')}`);
  }

  const requiredMode = scenarioId === 'workflow_embedded_activity_result'
    ? 'workflow-embedded'
    : 'standalone';
  const cells = activityHostCells(evidence);
  cells.forEach((cell, index) => {
    const localSignals = localSourceSignals(cell).slice(0, 3);
    if (localSignals.length > 0) {
      failures.push(`activity_host_evidence.activity_cells.${index} contains local product source probe signals: ${localSignals.join('; ')}`);
    }
    if (truthy(cell.local_product_source_checkouts_used) || truthy(cell.localProductSourceCheckoutsUsed)) {
      failures.push(`activity_host_evidence.activity_cells.${index}.local_product_source_checkouts_used=true`);
    }
  });
  for (const runtime of ['workflow-php', 'sdk-python']) {
    const matching = cells.find((cell) => stringValue(cell.mode) === requiredMode
      && stringValue(cell.runtime) === runtime
      && normalizedStatus(cell.status || cell.outcome || cell.result) === 'pass'
      && stringValue(cell.execution_source || cell.executionSource) === PUBLISHED_SERVER_CONTAINER_EXECUTION_SOURCE
      && localSourceSignals(cell).length === 0
      && !truthy(cell.local_product_source_checkouts_used)
      && !truthy(cell.localProductSourceCheckoutsUsed)
      && (runtime !== 'sdk-python' || sdkPythonCellArtifactFailures(cell, artifactVersions).length === 0));
    if (!matching) {
      failures.push(`activity_host_evidence missing passing ${requiredMode}/${runtime} cell`);
    }
  }
  cells.forEach((cell, index) => {
    if (stringValue(cell.mode) !== requiredMode || stringValue(cell.runtime) !== 'sdk-python') {
      return;
    }
    const status = normalizedStatus(cell.status || cell.outcome || cell.result);
    if (status !== 'pass') {
      return;
    }
    for (const failure of sdkPythonCellArtifactFailures(cell, artifactVersions)) {
      failures.push(`activity_host_evidence.activity_cells.${index}: ${failure}`);
    }
  });

  return failures;
}

function main() {
  const manifest = loadManifest();
  const scenarios = scenarioDefs(manifest);
  const matrix = requiredMatrix(manifest);
  const suiteVersion = Number.isInteger(manifest.suite_version) ? manifest.suite_version : null;
  let serverImage = env('DW_SERVER_IMAGE');
  const serverVersion = deriveServerVersion(serverImage, env('DW_SERVER_VERSION'));
  if (serverVersion && !serverImage) {
    serverImage = `durableworkflow/server:${serverVersion}`;
  }

  const workflowVersion = env('DW_WORKFLOW_PHP_VERSION');
  const artifactVersions = {
    server: serverVersion,
    cli: normalizeCliVersion(env('DW_CLI_VERSION')),
    'sdk-python': env('DW_PYTHON_SDK_VERSION'),
    workflow: workflowVersion,
    'workflow-php': workflowVersion,
    waterline: env('DW_WATERLINE_VERSION'),
  };
  const publishedArtifactVersions = {
    server: artifactVersions.server,
    cli: artifactVersions.cli,
    'sdk-python': artifactVersions['sdk-python'],
    workflow: artifactVersions.workflow,
    waterline: artifactVersions.waterline,
  };

  const installEvidencePath = env('DW_ACTIVITIES_ARTIFACT_INSTALL_EVIDENCE')
    || path.join(RESULT_DIR, 'artifact-install-evidence.json');
  const installEvidenceLoad = safeLoadJsonFromStringOrPath(
    '',
    installEvidencePath,
    'durable-workflow.v2.activity-runtime.artifact-install-evidence',
  );
  const artifactInstallEvidence = normalizeArtifactInstallEvidence(installEvidenceLoad, artifactVersions);
  const artifactSources = artifactSourcesFromInstallEvidence(artifactInstallEvidence);
  const pinFailures = exactVersionFailures(artifactVersions, serverImage);
  const installFailures = artifactInstallEvidenceFailures(artifactInstallEvidence, artifactVersions);
  const installEvidencePass = pinFailures.length === 0 && installFailures.length === 0;

  const evidencePath = env('DW_ACTIVITIES_EVIDENCE_PATH') || path.join(RESULT_DIR, 'activity-evidence.json');
  const activityEvidenceLoad = safeLoadJsonFromStringOrPath(
    env('DW_ACTIVITIES_EVIDENCE'),
    evidencePath,
    'durable-workflow.v2.activity-runtime.host-evidence',
  );
  const activityEvidence = activityEvidenceLoad.value && typeof activityEvidenceLoad.value === 'object'
    ? activityEvidenceLoad.value
    : {};
  const activityEvidenceById = scenarioEvidenceById(activityEvidence);
  const runtimeExecutionLoad = resolvePublishedRuntimeExecutionEvidence(
    activityEvidence,
    serverImage,
    artifactVersions.server,
  );
  const runtimeExecution = runtimeExecutionLoad.evidence;
  const runtimeExecutionFailureList = runtimeExecutionFailures(
    runtimeExecution,
    activityEvidence,
    serverImage,
    artifactVersions.server,
  );
  const runtimeExecutionPass = runtimeExecutionFailureList.length === 0;
  const evidenceLoadFailure = stringValue(activityEvidence.load_error);

  const runnerBlocked = pinFailures.length > 0;
  const blockedReason = pinFailures.join('; ');
  const missingEvidenceReason = activityEvidenceLoad.supplied
    ? evidenceLoadFailure
    : 'activity host evidence missing';
  const runtimeExecutionReason = runtimeExecutionFailureList.length > 0
    ? `activity host evidence did not prove execution inside the pinned published server artifact: ${runtimeExecutionFailureList.join('; ')}`
    : '';
  const defaultNonPassStatus = runnerBlocked ? 'runner_blocked' : 'not_covered';
  const defaultClassification = runnerBlocked ? 'runner-gap' : 'coverage-gap';
  const defaultReason = runnerBlocked ? blockedReason : (runtimeExecutionReason || missingEvidenceReason);
  const findings = [];
  const scenarioResults = [];

  for (const scenario of scenarios) {
    const scenarioId = stringValue(scenario.id);
    if (!scenarioId || !REQUIRED_SCENARIOS.includes(scenarioId)) {
      continue;
    }
    const expectedBehavior = stringValue(scenario.expected_behavior)
      || stringValue(scenario.expectedBehavior)
      || DEFAULT_EXPECTED_BEHAVIOR[scenarioId]
      || 'required activity conformance behavior is observed';
    const supplied = activityEvidenceById.get(scenarioId);

    if (scenarioId === 'published_artifact_install_only') {
      if (!runnerBlocked && installEvidencePass) {
        scenarioResults.push({
          scenario_id: scenarioId,
          status: 'pass',
          expected_behavior: expectedBehavior,
          classification: null,
          observed_outputs: {
            server_image: serverImage,
            cli_release: artifactVersions.cli,
            workflow_php_package: `durable-workflow/workflow:${artifactVersions.workflow}`,
            sdk_python_package: `durable-workflow==${artifactVersions['sdk-python']}`,
            waterline_artifact: `durable-workflow/waterline:${artifactVersions.waterline}`,
            artifact_sources: artifactSources,
            artifact_install_evidence: artifactInstallEvidence,
            artifact_install_evidence_path: installEvidencePath,
          },
          scenario_evidence: {
            artifact_install_evidence: artifactInstallEvidence,
          },
        });
        continue;
      }

      const scenarioReason = runnerBlocked
        ? blockedReason
        : `published artifact install evidence did not pass: ${installFailures.join('; ')}`;
      const status = runnerBlocked ? 'runner_blocked' : 'not_covered';
      const classification = runnerBlocked ? 'runner-gap' : 'coverage-gap';
      const scenarioFinding = finding(scenarioId, expectedBehavior, publishedArtifactVersions, {
        runnerBlocked: status === 'runner_blocked',
        classification,
        reason: scenarioReason,
      });
      findings.push(scenarioFinding);
      scenarioResults.push({
        scenario_id: scenarioId,
        status,
        expected_behavior: expectedBehavior,
        classification,
        observed_outputs: {
          coverage_status: status,
          observed_behavior: scenarioFinding.observed_behavior,
          next_acceptance_criterion: scenarioFinding.next_acceptance_criterion,
          artifact_install_evidence: artifactInstallEvidence,
          artifact_install_evidence_path: installEvidencePath,
          artifact_install_failures: installFailures,
        },
        linked_findings: [scenarioFinding],
      });
      continue;
    }

    if (!runnerBlocked && supplied) {
      let status = normalizedStatus(supplied.status || supplied.outcome || supplied.verdict);
      if (!ALLOWED_STATUSES.has(status)) {
        status = 'fail';
      }
      const observedOutputs = observedOutputsFor(supplied);
      if (status === 'pass' && !nonEmptyObject(observedOutputs)) {
        status = 'fail';
      }
      if (status === 'pass' && !runtimeExecutionPass) {
        status = 'not_covered';
      }
      const focusedHostEvidenceFailures = focusedActivityHostEvidenceFailures(scenarioId, supplied, observedOutputs, artifactVersions);
      if (status === 'pass' && focusedHostEvidenceFailures.length > 0) {
        status = 'fail';
      }

      if (status === 'pass') {
        const passObservedOutputs = {
          ...observedOutputs,
          published_artifact_worker_execution: runtimeExecution,
        };
        scenarioResults.push({
          scenario_id: scenarioId,
          status,
          expected_behavior: expectedBehavior,
          classification: null,
          observed_outputs: passObservedOutputs,
          scenario_evidence: nonEmptyObject(supplied.scenario_evidence || supplied.scenarioEvidence)
            ? {
              ...(supplied.scenario_evidence || supplied.scenarioEvidence),
              published_artifact_worker_execution: runtimeExecution,
            }
            : passObservedOutputs,
        });
        continue;
      }

      const focusedHostEvidenceReason = focusedHostEvidenceFailures.join('; ');
      const classification = normalizeClassification(
        supplied.classification || supplied.root_cause_classification || supplied.rootCauseClassification,
        status === 'runner_blocked' ? 'runner-gap' : (runtimeExecutionPass ? 'product-gap' : 'coverage-gap'),
      );
      const scenarioFinding = finding(scenarioId, expectedBehavior, publishedArtifactVersions, {
        runnerBlocked: status === 'runner_blocked',
        classification,
        findingType: supplied.finding_type || supplied.findingType,
        owner: supplied.owning_surface || supplied.owner,
        reason: runtimeExecutionPass
          ? (focusedHostEvidenceReason || stringValue(supplied.reason || supplied.observed_behavior || supplied.observedBehavior))
          : runtimeExecutionReason,
        observedBehavior: runtimeExecutionPass
          ? (focusedHostEvidenceReason || stringValue(supplied.observed_behavior || supplied.observedBehavior))
          : '',
      });
      findings.push(scenarioFinding);
      scenarioResults.push({
        scenario_id: scenarioId,
        status,
        expected_behavior: expectedBehavior,
        classification,
        observed_outputs: nonEmptyObject(observedOutputs)
          ? {
            ...observedOutputsWithRuntimeExecution(observedOutputs, runtimeExecutionPass, runtimeExecution),
            ...(focusedHostEvidenceFailures.length > 0
              ? { activity_host_evidence_failures: focusedHostEvidenceFailures }
              : {}),
          }
          : {
            coverage_status: status,
            observed_behavior: scenarioFinding.observed_behavior,
            next_acceptance_criterion: scenarioFinding.next_acceptance_criterion,
            runtime_execution_failures: runtimeExecutionFailureList,
            ...(focusedHostEvidenceFailures.length > 0
              ? { activity_host_evidence_failures: focusedHostEvidenceFailures }
              : {}),
            ...(runtimeExecutionPass
              ? { published_artifact_worker_execution: runtimeExecution }
              : {}),
          },
        linked_findings: [scenarioFinding],
      });
      continue;
    }

    let scenarioReason = defaultReason;
    let status = defaultNonPassStatus;
    let classification = defaultClassification;
    const scenarioFinding = finding(scenarioId, expectedBehavior, publishedArtifactVersions, {
      runnerBlocked: status === 'runner_blocked',
      classification,
      reason: scenarioReason,
    });
    findings.push(scenarioFinding);
    scenarioResults.push({
      scenario_id: scenarioId,
      status,
      expected_behavior: expectedBehavior,
      classification,
      observed_outputs: {
        coverage_status: status,
        observed_behavior: scenarioFinding.observed_behavior,
        next_acceptance_criterion: scenarioFinding.next_acceptance_criterion,
        ...(runtimeExecutionPass
          ? { published_artifact_worker_execution: runtimeExecution }
          : {}),
        ...(scenarioId === 'published_artifact_install_only'
          ? {
            artifact_install_evidence: artifactInstallEvidence,
            artifact_install_evidence_path: installEvidencePath,
            artifact_install_failures: installFailures,
          }
          : {}),
      },
      linked_findings: [scenarioFinding],
    });
  }

  const nonPassScenarios = scenarioResults.filter((result) => result.status !== 'pass');
  const allRequiredReported = REQUIRED_SCENARIOS.every((id) => scenarioResults.some((result) => result.scenario_id === id));
  const outcome = !runnerBlocked
    && allRequiredReported
    && nonPassScenarios.length === 0
    && installEvidencePass
    && activityEvidenceLoad.supplied
    ? 'pass'
    : (runnerBlocked ? 'non_passing_runner_blocked' : 'non_passing');
  const recordOutcome = outcome === 'pass' ? 'pass' : (runnerBlocked ? 'error' : 'fail');
  const finishedAt = now();
  const sectionStatus = runnerBlocked ? 'runner_blocked' : 'not_covered';
  const sections = evidenceStatusSections(sectionStatus, defaultReason);
  const runtimeMatrix = {
    execution_modes: Array.isArray(matrix.execution_modes) ? matrix.execution_modes : ['workflow-embedded', 'standalone'],
    runtimes: Array.isArray(matrix.runtimes) ? matrix.runtimes : ['workflow-php', 'sdk-python'],
    activity_cells: withCellStatus(matrix.activity_cells, sectionStatus),
    behavior_cells: Array.isArray(matrix.behavior_cells)
      ? matrix.behavior_cells.map((scenario) => ({ scenario, status: sectionStatus }))
      : [],
  };

  const publishedArtifactInstall = {
    status: installEvidencePass ? 'pass' : (runnerBlocked ? 'runner_blocked' : 'not_covered'),
    server_image: serverImage,
    cli_release: artifactVersions.cli,
    workflow_php_package: artifactVersions.workflow
      ? `durable-workflow/workflow:${artifactVersions.workflow}`
      : '',
    sdk_python_package: artifactVersions['sdk-python']
      ? `durable-workflow==${artifactVersions['sdk-python']}`
      : '',
    waterline_artifact: artifactVersions.waterline
      ? `durable-workflow/waterline:${artifactVersions.waterline}`
      : '',
    artifact_sources: artifactSources,
    artifact_install_evidence: artifactInstallEvidence,
    artifact_install_evidence_path: installEvidencePath,
    pin_failures: pinFailures,
    install_failures: installFailures,
  };

  const result = {
    schema: 'durable-workflow.v2.activity-runtime.result',
    schema_version: 1,
    suite_schema: 'durable-workflow.v2.platform-conformance.suite',
    suite_version: suiteVersion,
    category: 'activity_runtime_contract',
    outcome,
    runner_blocked: runnerBlocked,
    started_at: STARTED_AT,
    finished_at: finishedAt,
    generated_at: finishedAt,
    artifact_versions: artifactVersions,
    published_artifact_versions: publishedArtifactVersions,
    artifact_sources: artifactSources,
    execution_source: runtimeExecutionLoad.execution_source,
    local_product_source_checkouts_used: artifactInstallEvidence.local_product_source_checkouts_used,
    artifact_install_evidence: artifactInstallEvidence,
    activity_evidence_source: activityEvidenceLoad.source,
    activity_evidence_supplied: activityEvidenceLoad.supplied,
    published_artifact_worker_execution: runtimeExecutionPass ? runtimeExecution : null,
    published_artifact_worker_execution_source: runtimeExecutionLoad.source,
    published_artifact_worker_execution_derived: runtimeExecutionLoad.derived,
    published_artifact_worker_execution_derivation_reason: runtimeExecutionLoad.derivation_reason,
    published_artifact_worker_execution_failures: runtimeExecutionFailureList,
    published_artifact_install: {
      ...sectionFromEvidence(activityEvidence, 'published_artifact_install', {}),
      ...publishedArtifactInstall,
    },
    runtime_matrix: sectionFromEvidence(activityEvidence, 'runtime_matrix', runtimeMatrix),
    topology: {
      namespace: 'activities-conformance',
      task_queue: 'activities-shared',
      required_workers: ['workflow-php', 'sdk-python'],
      execution_modes: ['workflow-embedded', 'standalone'],
    },
    durable_result_recording: sectionFromEvidence(activityEvidence, 'durable_result_recording', sections.durable_result_recording),
    retry_backoff: sectionFromEvidence(activityEvidence, 'retry_backoff', sections.retry_backoff),
    timeout_behavior: sectionFromEvidence(activityEvidence, 'timeout_behavior', sections.timeout_behavior),
    typed_failure_propagation: sectionFromEvidence(activityEvidence, 'typed_failure_propagation', sections.typed_failure_propagation),
    heartbeat_cancellation: sectionFromEvidence(activityEvidence, 'heartbeat_cancellation', sections.heartbeat_cancellation),
    idempotent_completion: sectionFromEvidence(activityEvidence, 'idempotent_completion', sections.idempotent_completion),
    operator_visibility: sectionFromEvidence(activityEvidence, 'operator_visibility', sections.operator_visibility),
    scenario_results: scenarioResults,
    findings,
    finding_links: Object.fromEntries(findings.map((item) => [item.scenario_id, [item]])),
  };

  const metadata = {
    started_at: STARTED_AT,
    finished_at: finishedAt,
    generated_at: finishedAt,
    artifact_versions: artifactVersions,
    published_artifact_versions: publishedArtifactVersions,
    artifact_sources: artifactSources,
    artifact_install_evidence_path: installEvidencePath,
    artifact_install_evidence_supplied: artifactInstallEvidence.supplied,
    activity_evidence_source: activityEvidenceLoad.source,
    activity_evidence_supplied: activityEvidenceLoad.supplied,
    execution_source: runtimeExecutionLoad.execution_source,
    published_artifact_worker_execution_supplied: nonEmptyObject(runtimeExecution),
    published_artifact_worker_execution_source: runtimeExecutionLoad.source,
    published_artifact_worker_execution_derived: runtimeExecutionLoad.derived,
    published_artifact_worker_execution_derivation_reason: runtimeExecutionLoad.derivation_reason,
    published_artifact_worker_execution_pass: runtimeExecutionPass,
    published_artifact_worker_execution_failures: runtimeExecutionFailureList,
    scenario_manifest: MANIFEST_PATH,
  };

  const record = {
    experiment: 'activities',
    outcome: recordOutcome,
    runnerBlocked: runnerBlocked,
    artifactVersions: publishedArtifactVersions,
    executionSource: runtimeExecutionLoad.execution_source,
    findings: findings.map((item) => `${item.scenario_id}: ${item.observed_behavior}`),
    resultPath: path.join(RESULT_DIR, 'activities-result.json'),
  };

  fs.mkdirSync(RESULT_DIR, { recursive: true });
  writeJson(path.join(RESULT_DIR, 'pins.json'), artifactVersions);
  writeJson(path.join(RESULT_DIR, 'run-metadata.json'), metadata);
  writeJson(path.join(RESULT_DIR, 'activities-result.json'), result);
  writeJson(path.join(RESULT_DIR, 'activities-record.json'), record);
  writeJson(path.join(RESULT_DIR, 'activities-findings.json'), findings);
  console.log(JSON.stringify(result, null, 2));

  return outcome === 'pass' ? 0 : 1;
}

process.exitCode = main();
JS
