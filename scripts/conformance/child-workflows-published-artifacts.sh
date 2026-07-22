#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage: child-workflows-published-artifacts.sh [--result-dir DIR|--result-dir=DIR] [--keep-run-root[=1|true]]

Writes a scenario-level child-workflows conformance result for published artifacts.

The runner writes these files to the result directory:
  pins.json
  run-metadata.json
  child-workflows-result.json
  child-workflows-record.json

Environment overrides:
  DW_CHILD_WORKFLOWS_RESULT_DIR          Result directory. Defaults to run root.
  DW_CHILD_WORKFLOWS_RUN_ROOT           Scratch directory. Defaults to mktemp.
  DW_CHILD_WORKFLOWS_KEEP_RUN_ROOT=1    Keep scratch directory after success.
  DW_CHILD_WORKFLOWS_SCENARIO_MANIFEST  Scenario manifest path. Defaults to the server static mirror.
  DW_CHILD_WORKFLOWS_PYTHON_BIN       Optional Python executable with durable-workflow installed.
                                      Set internally to the run-root venv installed from the pinned PyPI artifact.
  DW_SERVER_IMAGE                       Exact server image tag or digest to test.
  DW_SERVER_VERSION                     Exact server SemVer tag; required for digest-only DW_SERVER_IMAGE.
  DW_CLI_VERSION                        Exact CLI release version.
  DW_PYTHON_SDK_VERSION                 Exact PyPI durable-workflow version.
  DW_RUST_SDK_VERSION                   Exact crates.io durable-workflow version.
  DW_WORKFLOW_PHP_VERSION               Exact Composer durable-workflow/workflow version.
  DW_WATERLINE_VERSION                  Exact Waterline artifact version.
USAGE
}

keep_run_root="${DW_CHILD_WORKFLOWS_KEEP_RUN_ROOT:-0}"
result_dir="${DW_CHILD_WORKFLOWS_RESULT_DIR:-}"

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
scenario_manifest="${DW_CHILD_WORKFLOWS_SCENARIO_MANIFEST:-$repo_root/static/platform-conformance/child-workflow-runtime-scenarios.json}"

run_root="${DW_CHILD_WORKFLOWS_RUN_ROOT:-}"
run_root_owned=0
if [[ -z "$run_root" ]]; then
  run_root="$(mktemp -d "${TMPDIR:-/tmp}/dw-child-workflows.XXXXXX")"
  run_root_owned=1
fi
mkdir -p "$run_root"

if [[ -z "$result_dir" ]]; then
  result_dir="$run_root"
fi
mkdir -p "$result_dir"

cleanup() {
  local code=$?

  if [[ "$keep_run_root" != "1" && "$code" -eq 0 && "$result_dir" != "$run_root" ]]; then
    if [[ "$run_root_owned" == "1" ]]; then
      rm -rf "$run_root" \
        || printf 'warning: unable to remove child-workflows conformance run root %s\n' "$run_root" >&2
    else
      find "$run_root" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} + \
        || printf 'warning: unable to clean child-workflows conformance run root contents: %s\n' "$run_root" >&2
    fi
  fi
}
trap cleanup EXIT

started_at="$(timestamp)"

if ! require_command python3; then
  printf '%s\n' 'required command not found: python3' >&2
  exit 1
fi

# Scenario and install evidence are outputs of this runner.  Caller-authored
# JSON was accepted by older versions of the script; retaining that boundary
# would let fabricated identities and pass rows substitute for execution.
unset DW_CHILD_WORKFLOWS_ARTIFACT_INSTALL_EVIDENCE
unset DW_CHILD_WORKFLOWS_TYPED_FAILURE_EVIDENCE
unset DW_CHILD_WORKFLOWS_FULL_MATRIX_EVIDENCE
export DW_CHILD_WORKFLOWS_SKIP_FOCUSED_TYPED_FAILURE_PROBE=1
rm -f \
  "$result_dir/artifact-install-evidence.json" \
  "$result_dir/typed-failure-evidence.json" \
  "$result_dir/full-matrix-evidence.json"

run_published_matrix_probe() {
  if [[ "$repo_root" != "/app" || -d "$repo_root/.git" ]]; then
    printf '%s\n' 'published matrix probe not run: runner is not inside a source-free published server image' \
      >"$result_dir/runtime-probe.log"
    return 0
  fi
  if ! require_command php || [[ ! -f "$repo_root/vendor/autoload.php" || ! -f "$repo_root/artisan" ]]; then
    printf '%s\n' 'published matrix probe not run: published PHP server runtime is unavailable' \
      >"$result_dir/runtime-probe.log"
    return 0
  fi

  if [[ -z "${DW_RUST_SDK_VERSION:-}" ]]; then
    set +e
    DW_RUST_SDK_VERSION="$(python3 - <<'PY'
import json
import urllib.request

request = urllib.request.Request(
    "https://crates.io/api/v1/crates/durable-workflow",
    headers={"Accept": "application/json", "User-Agent": "durable-workflow-conformance"},
)
with urllib.request.urlopen(request, timeout=60) as response:
    payload = json.loads(response.read().decode("utf-8"))
print(payload.get("crate", {}).get("newest_version", ""))
PY
)"
    set -e
    export DW_RUST_SDK_VERSION
  fi

  set +e
  python3 "$repo_root/scripts/conformance/child-workflows-install-probe.py" \
    "$result_dir" "$run_root" "$repo_root" \
    >"$result_dir/artifact-install-probe.log" 2>&1
  local install_status=$?
  set -e
  if [[ -s "$result_dir/artifact-install-evidence.json" ]]; then
    export DW_CHILD_WORKFLOWS_ARTIFACT_INSTALL_EVIDENCE="$result_dir/artifact-install-evidence.json"
  fi
  if [[ "$install_status" -ne 0 ]]; then
    printf '%s\n' 'published matrix probe stopped because one or more exact public artifacts could not be installed or resolved' \
      >"$result_dir/runtime-probe.log"
    return 0
  fi

  export DW_CHILD_WORKFLOWS_PYTHON_BIN="$run_root/python-venv/bin/python"
  : >"$run_root/child-workflows-runtime.sqlite"
  set +e
  APP_ENV=production \
  APP_DEBUG=false \
  APP_KEY="${APP_KEY:-base64:Q0hJTEQtV09SS0ZMT1dTLUNPTkZPUk1BTkNFLVJVTlRJTUU=}" \
  DB_CONNECTION=sqlite \
  DB_DATABASE="$run_root/child-workflows-runtime.sqlite" \
  QUEUE_CONNECTION=database \
  CACHE_STORE=array \
  SESSION_DRIVER=array \
  DW_AUTH_DRIVER=none \
  DW_TASK_DISPATCH_MODE=poll \
  DW_V2_TASK_DISPATCH_MODE=poll \
  RUNNER_REPO_ROOT="$repo_root" \
  RESULT_DIR="$result_dir" \
  php "$repo_root/scripts/conformance/child-workflows-runtime-probe.php" \
    >"$result_dir/runtime-probe.log" 2>&1
  local runtime_status=$?
  set -e
  if [[ "$runtime_status" -eq 0 && -s "$result_dir/full-matrix-evidence.json" ]]; then
    export DW_CHILD_WORKFLOWS_FULL_MATRIX_EVIDENCE="$result_dir/full-matrix-evidence.json"
  fi
}

run_published_matrix_probe

focused_probe_app_key="${APP_KEY:-base64:Q0hJTEQtV09SS0ZMT1dTLVRZUEVELUZBSUxVUkUtUFJPQkU=}"

should_run_focused_typed_failure_probe() {
  if [[ "${DW_CHILD_WORKFLOWS_SKIP_FOCUSED_TYPED_FAILURE_PROBE:-0}" == "1" \
    || "${DW_CHILD_WORKFLOWS_SKIP_FOCUSED_TYPED_FAILURE_PROBE:-}" == "true" ]]; then
    return 1
  fi
  if [[ -n "${DW_CHILD_WORKFLOWS_TYPED_FAILURE_EVIDENCE:-}" ]]; then
    return 1
  fi
  if [[ -s "$result_dir/typed-failure-evidence.json" ]]; then
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
  if [[ -n "${DW_CHILD_WORKFLOWS_PYTHON_BIN:-}" ]]; then
    return 0
  fi
  if [[ -z "${DW_PYTHON_SDK_VERSION:-}" ]]; then
    return 1
  fi
  if ! require_command python3; then
    return 1
  fi

  local venv="$run_root/sdk-python-typed-child-failure-venv"
  local install_log="$result_dir/sdk-python-typed-child-failure-install.log"
  if python3 -m venv "$venv" >"$install_log" 2>&1 \
    && "$venv/bin/python" -m pip install --disable-pip-version-check --no-input "durable-workflow==${DW_PYTHON_SDK_VERSION}" >>"$install_log" 2>&1; then
    export DW_CHILD_WORKFLOWS_PYTHON_BIN="$venv/bin/python"
    return 0
  fi

  return 1
}

run_focused_typed_failure_probe() {
  local probe_db="$run_root/child-workflows-typed-failure-probe.sqlite"
  local server_evidence="$result_dir/focused-typed-failure-server-evidence.json"
  local python_log="$result_dir/focused-typed-failure-python-sdk.log"

  : > "$probe_db"
  if ! prepare_focused_python_sdk; then
    printf '%s\n' 'focused typed child failure probe skipped: published Python SDK is unavailable' >"$python_log"
    return 0
  fi

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
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowRun;

const CHILD_WORKFLOWS_NAMESPACE = 'child-workflows-conformance';
const CHILD_WORKFLOWS_TASK_QUEUE = 'cw-typed-failure';
const PARENT_WORKFLOW_TYPE = 'conformance.sdk-python.parent-typed-child-failure';
const CHILD_WORKFLOW_TYPE = 'conformance.sdk-python.child-domain-failure';
const CHILD_EXCEPTION_CLASS = 'conformance.child_failure.ChildDomainFailure';
const CHILD_EXCEPTION_TYPE = 'ChildDomainFailure';
const CHILD_EXCEPTION_MESSAGE = 'typed child domain failure from published artifacts';

$repoRoot = getenv('RUNNER_REPO_ROOT') ?: '/app';
if (! is_dir($repoRoot)) {
    throw new RuntimeException('published server root is not available');
}
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

function focused_result_dir(): string
{
    return rtrim(getenv('RESULT_DIR') ?: sys_get_temp_dir(), '/');
}

function bootstrap_application(string $repoRoot): void
{
    $app = require $repoRoot.'/bootstrap/app.php';
    $app->make(ConsoleKernel::class)->bootstrap();

    config([
        'app.key' => getenv('APP_KEY') ?: 'base64:Q0hJTEQtV09SS0ZMT1dTLVRZUEVELUZBSUxVUkUtUFJPQkU=',
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
        ['name' => CHILD_WORKFLOWS_NAMESPACE],
        [
            'description' => 'Child-workflows conformance namespace',
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
        'HTTP_X_NAMESPACE' => CHILD_WORKFLOWS_NAMESPACE,
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

function event_type(array $event): string
{
    return is_string($event['event_type'] ?? null)
        ? $event['event_type']
        : (is_string($event['type'] ?? null) ? $event['type'] : '');
}

function event_payload(array $event): array
{
    return is_array($event['payload'] ?? null) ? $event['payload'] : [];
}

function first_event(array $events, string $eventType): ?array
{
    foreach ($events as $event) {
        if (is_array($event) && event_type($event) === $eventType) {
            return $event;
        }
    }

    return null;
}

function register_worker(string $workerId, string $workflowType): void
{
    request_json('POST', '/worker/register', [
        'worker_id' => $workerId,
        'task_queue' => CHILD_WORKFLOWS_TASK_QUEUE,
        'runtime' => 'python',
        'sdk_version' => getenv('DW_PYTHON_SDK_VERSION') ?: null,
        'supported_workflow_types' => [$workflowType],
        'supported_activity_types' => [],
    ], [201]);
}

function poll_workflow_task(string $workerId): array
{
    $poll = request_json('POST', '/worker/workflow-tasks/poll', [
        'worker_id' => $workerId,
        'task_queue' => CHILD_WORKFLOWS_TASK_QUEUE,
    ]);

    if (! is_array($poll['task'] ?? null)) {
        throw new RuntimeException(sprintf('worker %s did not receive a workflow task: %s', $workerId, json_encode($poll)));
    }

    return $poll['task'];
}

function complete_task(array $task, array $commands): array
{
    $taskId = is_string($task['task_id'] ?? null) ? $task['task_id'] : '';
    if ($taskId === '') {
        throw new RuntimeException('workflow task is missing task_id');
    }

    return request_json('POST', '/worker/workflow-tasks/'.$taskId.'/complete', [
        'lease_owner' => $task['lease_owner'] ?? null,
        'workflow_task_attempt' => $task['workflow_task_attempt'] ?? null,
        'commands' => $commands,
    ]);
}

try {
    bootstrap_application($repoRoot);

    $parentWorkflowId = 'cw-typed-parent-'.bin2hex(random_bytes(6));
    $start = request_json('POST', '/workflows', [
        'workflow_id' => $parentWorkflowId,
        'workflow_type' => PARENT_WORKFLOW_TYPE,
        'task_queue' => CHILD_WORKFLOWS_TASK_QUEUE,
        'input' => ['typed-child-failure'],
    ], [201]);
    $parentRunId = is_string($start['run_id'] ?? null) ? $start['run_id'] : '';
    if ($parentRunId === '') {
        throw new RuntimeException('start response did not include parent run id');
    }

    register_worker('cw-typed-parent-worker', PARENT_WORKFLOW_TYPE);
    register_worker('cw-typed-child-worker', CHILD_WORKFLOW_TYPE);
    register_worker('cw-typed-parent-resume-worker', PARENT_WORKFLOW_TYPE);

    $parentTask = poll_workflow_task('cw-typed-parent-worker');
    complete_task($parentTask, [[
        'type' => 'start_child_workflow',
        'workflow_type' => CHILD_WORKFLOW_TYPE,
        'queue' => CHILD_WORKFLOWS_TASK_QUEUE,
        'arguments' => Serializer::serializeWithCodec('avro', ['typed-child-failure']),
    ]]);

    $childTask = poll_workflow_task('cw-typed-child-worker');
    $childRunId = is_string($childTask['run_id'] ?? null) ? $childTask['run_id'] : '';
    $childWorkflowId = is_string($childTask['workflow_id'] ?? null) ? $childTask['workflow_id'] : '';
    if ($childRunId === '' || $childWorkflowId === '') {
        throw new RuntimeException('child poll response did not include workflow/run identifiers');
    }

    complete_task($childTask, [[
        'type' => 'fail_workflow',
        'message' => CHILD_EXCEPTION_MESSAGE,
        'exception_type' => CHILD_EXCEPTION_TYPE,
        'exception_class' => CHILD_EXCEPTION_CLASS,
        'exception' => [
            'type' => CHILD_EXCEPTION_TYPE,
            'class' => CHILD_EXCEPTION_CLASS,
            'message' => CHILD_EXCEPTION_MESSAGE,
            'details' => [
                'domain_code' => 'CHILD_DOMAIN_FAILURE',
                'surface' => 'published server worker protocol',
            ],
        ],
    ]]);

    $parentResumeTask = poll_workflow_task('cw-typed-parent-resume-worker');
    $resumeEvents = is_array($parentResumeTask['history_events'] ?? null) ? $parentResumeTask['history_events'] : [];
    $childRunFailed = first_event($resumeEvents, HistoryEventType::ChildRunFailed->value);
    if ($childRunFailed === null) {
        throw new RuntimeException('parent resume history did not include ChildRunFailed');
    }

    $failedPayload = event_payload($childRunFailed);
    if (($failedPayload['exception_class'] ?? null) !== CHILD_EXCEPTION_CLASS) {
        throw new RuntimeException('ChildRunFailed lost exception_class metadata');
    }
    if (($failedPayload['message'] ?? null) !== CHILD_EXCEPTION_MESSAGE) {
        throw new RuntimeException('ChildRunFailed lost exception message metadata');
    }
    if (($failedPayload['failure_category'] ?? null) !== 'child_workflow') {
        throw new RuntimeException('ChildRunFailed lost child_workflow failure category');
    }

    /** @var WorkflowRun $childRun */
    $childRun = WorkflowRun::query()->findOrFail($childRunId);
    if ($childRun->status !== RunStatus::Failed) {
        throw new RuntimeException('child run did not reach failed status');
    }

    /** @var WorkflowFailure|null $childFailure */
    $childFailure = WorkflowFailure::query()
        ->where('workflow_run_id', $childRunId)
        ->first();

    $parentHistory = request_json('GET', '/workflows/'.$parentWorkflowId.'/runs/'.$parentRunId.'/history');
    $childHistory = request_json('GET', '/workflows/'.$childWorkflowId.'/runs/'.$childRunId.'/history');

    write_json_file(focused_result_dir().'/focused-typed-failure-server-evidence.json', [
        'schema' => 'durable-workflow.v2.child-workflow-runtime.focused-typed-failure-server-evidence',
        'generated_at' => now_iso(),
        'local_product_source_checkouts_used' => false,
        'parent' => 'sdk-python',
        'child' => 'sdk-python',
        'parent_workflow_id' => $parentWorkflowId,
        'parent_run_id' => $parentRunId,
        'child_workflow_id' => $childWorkflowId,
        'child_run_id' => $childRunId,
        'task_queue' => CHILD_WORKFLOWS_TASK_QUEUE,
        'parent_resume_task' => [
            'task_id' => $parentResumeTask['task_id'] ?? null,
            'workflow_event_type' => $parentResumeTask['workflow_event_type'] ?? null,
            'workflow_sequence' => $parentResumeTask['workflow_sequence'] ?? null,
            'resume_source_kind' => $parentResumeTask['resume_source_kind'] ?? null,
            'resume_source_id' => $parentResumeTask['resume_source_id'] ?? null,
            'child_workflow_run_id' => $parentResumeTask['child_workflow_run_id'] ?? null,
            'child_call_id' => $parentResumeTask['child_call_id'] ?? null,
        ],
        'parent_history_observations' => array_values(array_map(
            static fn (array $event): string => event_type($event),
            array_filter($parentHistory['events'] ?? [], 'is_array')
        )),
        'child_history_observations' => array_values(array_map(
            static fn (array $event): string => event_type($event),
            array_filter($childHistory['events'] ?? [], 'is_array')
        )),
        'parent_child_run_failed_event' => $childRunFailed,
        'child_failure_row' => $childFailure instanceof WorkflowFailure ? [
            'failure_id' => $childFailure->id,
            'failure_category' => $childFailure->failure_category?->value,
            'exception_type' => $childFailure->exception_type,
            'exception_class' => $childFailure->exception_class,
            'message' => $childFailure->message,
        ] : null,
        'parent_history' => $parentHistory,
        'child_history' => $childHistory,
    ]);
} catch (Throwable $throwable) {
    write_json_file(focused_result_dir().'/focused-typed-failure-server-evidence-error.json', [
        'schema' => 'durable-workflow.v2.child-workflow-runtime.focused-typed-failure-server-evidence-error',
        'generated_at' => now_iso(),
        'local_product_source_checkouts_used' => false,
        'error' => $throwable::class.': '.$throwable->getMessage(),
        'trace' => $throwable->getTraceAsString(),
    ]);
}
PHP

  if [[ ! -s "$server_evidence" ]]; then
    return 0
  fi

  "$DW_CHILD_WORKFLOWS_PYTHON_BIN" - "$server_evidence" "$result_dir/typed-failure-evidence.json" >"$python_log" 2>&1 <<'PY' || true
from __future__ import annotations

import json
import os
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from durable_workflow import workflow
from durable_workflow.errors import ChildWorkflowFailed
from durable_workflow.workflow import CompleteWorkflow, WorkflowContext, replay


@workflow.defn(name="conformance.sdk-python.parent-typed-child-failure")
class FocusedTypedChildFailureParent:
    def run(self, ctx: WorkflowContext) -> dict[str, Any]:  # type: ignore[no-untyped-def]
        try:
            yield ctx.start_child_workflow("conformance.sdk-python.child-domain-failure", ["typed-child-failure"])
        except ChildWorkflowFailed as exc:
            return {
                "message": str(exc),
                "exception_class": exc.exception_class,
                "failure_kind": exc.failure_kind,
                "child_workflow_run_id": exc.child_workflow_run_id,
                "child_workflow_type": exc.child_workflow_type,
            }
        return {"unexpected_success": True}


def utc_now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


server_evidence_path = Path(sys.argv[1])
output_path = Path(sys.argv[2])
server_evidence = json.loads(server_evidence_path.read_text(encoding="utf-8"))
parent_history = server_evidence["parent_history"]["events"]
outcome = replay(FocusedTypedChildFailureParent, parent_history, ["typed-child-failure"])
if len(outcome.commands) != 1 or not isinstance(outcome.commands[0], CompleteWorkflow):
    raise RuntimeError(f"Python SDK replay did not complete after ChildRunFailed: {outcome.commands!r}")

parent_observed_failure = outcome.commands[0].result
if not isinstance(parent_observed_failure, dict):
    raise RuntimeError("Python SDK replay did not return typed failure details")

expected_class = "conformance.child_failure.ChildDomainFailure"
expected_message = "typed child domain failure from published artifacts"
if parent_observed_failure.get("exception_class") != expected_class:
    raise RuntimeError(f"Python SDK replay saw exception_class={parent_observed_failure.get('exception_class')!r}")
if parent_observed_failure.get("message") != expected_message:
    raise RuntimeError(f"Python SDK replay saw message={parent_observed_failure.get('message')!r}")
if parent_observed_failure.get("failure_kind") != "child_workflow":
    raise RuntimeError(f"Python SDK replay saw failure_kind={parent_observed_failure.get('failure_kind')!r}")

artifact_versions = {
    "server": os.environ.get("DW_SERVER_VERSION", "").strip(),
    "cli": os.environ.get("DW_CLI_VERSION", "").strip().removeprefix("v"),
    "sdk-python": os.environ.get("DW_PYTHON_SDK_VERSION", "").strip(),
    "workflow": os.environ.get("DW_WORKFLOW_PHP_VERSION", "").strip(),
    "waterline": os.environ.get("DW_WATERLINE_VERSION", "").strip(),
}

parent_observations = list(server_evidence.get("parent_history_observations") or [])
child_observations = list(server_evidence.get("child_history_observations") or [])

evidence = {
    "schema": "durable-workflow.v2.child-workflow-runtime.typed-failure-evidence",
    "generated_at": utc_now(),
    "local_product_source_checkouts_used": False,
    "artifact_versions": artifact_versions,
    "failure_round_trip_cells": [
        {
            "scenario": "child_failure_round_trip_matrix",
            "parent": "sdk-python",
            "child": "sdk-python",
            "status": "pass",
            "exception_class": expected_class,
            "message": expected_message,
            "failure_kind": "child_workflow",
            "parent_workflow_id": server_evidence["parent_workflow_id"],
            "parent_run_id": server_evidence["parent_run_id"],
            "child_workflow_id": server_evidence["child_workflow_id"],
            "child_run_id": server_evidence["child_run_id"],
            "parent_history_observations": parent_observations,
            "child_history_observations": child_observations,
            "public_surfaces": [
                "server worker protocol workflow-task complete",
                "server worker protocol parent resume poll",
                "server history API",
                "published durable-workflow Python SDK replay surface",
            ],
            "parent_observed_failure": parent_observed_failure,
            "server_child_run_failed_event": server_evidence.get("parent_child_run_failed_event"),
            "server_child_failure_row": server_evidence.get("child_failure_row"),
            "local_product_source_checkouts_used": False,
        }
    ],
}
output_path.write_text(json.dumps(evidence, indent=2, sort_keys=True) + "\n", encoding="utf-8")
print(json.dumps({"status": "pass", "output": str(output_path)}, sort_keys=True))
PY
}

if should_run_focused_typed_failure_probe; then
  run_focused_typed_failure_probe
fi

if [[ -z "${DW_CHILD_WORKFLOWS_TYPED_FAILURE_EVIDENCE:-}" && -s "$result_dir/typed-failure-evidence.json" ]]; then
  export DW_CHILD_WORKFLOWS_TYPED_FAILURE_EVIDENCE="$result_dir/typed-failure-evidence.json"
fi

python3 - "$result_dir" "$started_at" "$scenario_manifest" <<'PY'
from __future__ import annotations

import json
import os
import re
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Optional

RESULT_DIR = Path(sys.argv[1])
STARTED_AT = sys.argv[2]
MANIFEST_PATH = Path(sys.argv[3])

SEMVER_RE = re.compile(
    r"^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)"
    r"(?:-(?:0|[1-9]\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*)"
    r"(?:\.(?:0|[1-9]\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*))*)?$",
)
SERVER_TAG_RE = re.compile(
    r"(?::|/)((?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)"
    r"(?:-(?:0|[1-9]\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*)"
    r"(?:\.(?:0|[1-9]\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*))*)?)$",
)
PLACEHOLDER_RE = re.compile(
    r"(<[^>]+>|\$\{[^}]+}|{{[^}]+}}|(^|[^a-z0-9])"
    r"(latest|current|head|main|master|dev|snapshot|unresolved|placeholder)([^a-z0-9]|$))",
    re.I,
)

REQUIRED_INSTALL_ARTIFACTS = [
    "server",
    "cli",
    "sdk-python",
    "sdk-rust",
    "workflow-php",
    "waterline",
]

FORBIDDEN_INSTALL_SOURCE_TOKENS = [
    "local_product_source_checkout",
    "workspace_repo_as_artifact_under_test",
    "local_checkout",
    "source_checkout",
    "/" + "work" + "space/repos/",
]

FALLBACK_REQUIRED_SCENARIO_IDS = [
    "published_artifact_install_only",
    "python_parent_python_child_baseline",
    "php_parent_php_child_baseline",
    "php_parent_python_child_cross_language",
    "python_parent_php_child_cross_language",
    "child_failure_round_trip_matrix",
    "parent_cancellation_propagates_to_child",
    "direct_child_cancellation_observed_by_parent",
    "worker_restart_replay_preserves_child_outcome",
    "concurrent_child_fan_out",
    "child_workflow_namespace_contract",
]

DEFAULT_EXPECTED_BEHAVIOR = {
    "published_artifact_install_only": "all artifacts are resolved from published install channels",
    "python_parent_python_child_baseline": "Python parent starts Python child, receives the exact child result, and records child schedule/completion events",
    "php_parent_php_child_baseline": "PHP parent starts PHP child, receives the exact child result, and records child schedule/completion events",
    "php_parent_python_child_cross_language": "PHP parent starts Python child by workflow type and receives the typed child result",
    "python_parent_php_child_cross_language": "Python parent starts PHP child by workflow type and receives the typed child result",
    "child_failure_round_trip_matrix": "typed child failures preserve exception class, message, and failure kind across all parent/child runtime cells",
    "parent_cancellation_propagates_to_child": "cancelling the parent cancels the scheduled child and the child worker observes typed cancellation",
    "direct_child_cancellation_observed_by_parent": "direct child cancellation is surfaced to the parent as typed cancellation rather than timeout",
    "worker_restart_replay_preserves_child_outcome": "a parent worker restart replays child completion deterministically and does not schedule a duplicate child",
    "concurrent_child_fan_out": "a parent starts five children concurrently and aggregates all child results",
    "child_workflow_namespace_contract": "child workflow lineage records namespace identity and cross-namespace behavior is supported or linked to a documented root-cause finding",
}


def now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def env(name: str) -> str:
    return os.environ.get(name, "").strip()


def load_manifest() -> dict[str, Any]:
    if not MANIFEST_PATH.exists():
        return {}
    return json.loads(MANIFEST_PATH.read_text(encoding="utf-8"))


def normalize_cli_version(value: str) -> str:
    return value[1:] if value.startswith("v") and SEMVER_RE.match(value[1:]) else value


def derive_server_version(server_image: str, explicit_version: str) -> str:
    if explicit_version:
        return explicit_version
    match = SERVER_TAG_RE.search(server_image)
    return match.group(1) if match else ""


def is_placeholder(value: str) -> bool:
    return bool(value and PLACEHOLDER_RE.search(value.lower()))


def is_exact_python_release(value: str) -> bool:
    return re.fullmatch(
        r"(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)"
        r"(?:(?:a|b|rc)(?:0|[1-9]\d*)|-(?:alpha|beta|rc)\.(?:0|[1-9]\d*))?",
        value,
        re.IGNORECASE,
    ) is not None


def exact_version_failures(versions: dict[str, str], server_image: str) -> list[str]:
    failures: list[str] = []
    required = {
        "server": "DW_SERVER_VERSION or exact DW_SERVER_IMAGE tag",
        "cli": "DW_CLI_VERSION",
        "sdk-python": "DW_PYTHON_SDK_VERSION",
        "sdk-rust": "DW_RUST_SDK_VERSION",
        "workflow": "DW_WORKFLOW_PHP_VERSION",
        "waterline": "DW_WATERLINE_VERSION",
    }
    for key, label in required.items():
        version = versions.get(key, "")
        if not version:
            failures.append(f"missing {label}")
            continue
        exact_version = is_exact_python_release(version) if key == "sdk-python" else bool(SEMVER_RE.match(version))
        if is_placeholder(version) or not exact_version:
            failures.append(f"{label} must be an exact semver artifact version; got {version!r}")

    if server_image:
        if is_placeholder(server_image):
            failures.append(f"DW_SERVER_IMAGE must not use a rolling tag or placeholder; got {server_image!r}")
        tag_match = SERVER_TAG_RE.search(server_image)
        if tag_match and versions.get("server") and tag_match.group(1) != versions["server"]:
            failures.append(
                f"DW_SERVER_VERSION {versions['server']!r} does not match DW_SERVER_IMAGE tag {tag_match.group(1)!r}",
            )
        if "@sha256:" in server_image and not versions.get("server"):
            failures.append("DW_SERVER_VERSION is required when DW_SERVER_IMAGE is digest-pinned")

    return failures


def string_value(value: Any) -> str:
    return str(value).strip() if isinstance(value, (str, int, float, bool)) else ""


def truthy_flag(value: Any) -> bool:
    if value is True or value == 1:
        return True
    if isinstance(value, str):
        return value.strip().lower() in {"1", "true", "yes", "y", "on"}
    return False


def explicit_false_flag(value: Any) -> bool:
    if value is False or value == 0:
        return True
    if isinstance(value, str):
        return value.strip().lower() in {"0", "false", "no", "n", "off"}
    return False


def normalized_status(value: Any) -> str:
    status = string_value(value).lower()
    if status in {"pass", "passed", "success", "ok"}:
        return "pass"
    if status in {"fail", "failed", "failure"}:
        return "fail"
    if status in {"blocked", "runner_blocked", "error"}:
        return "runner_blocked"
    if status in {"not_covered", "missing", "not_exercised", "unsupported"}:
        return status
    return status


def artifact_version_for(versions: dict[str, str], artifact: str) -> str:
    aliases = {
        "workflow-php": ["workflow-php", "workflow"],
        "sdk-python": ["sdk-python", "sdk_python", "python"],
        "sdk-rust": ["sdk-rust", "sdk_rust", "rust"],
    }
    for key in aliases.get(artifact, [artifact]):
        value = versions.get(key, "")
        if value:
            return value
    return ""


def entry_source(entry: dict[str, Any]) -> str:
    for key in (
        "source",
        "install_source",
        "installSource",
        "artifact_source",
        "artifactSource",
        "resolved_source",
        "resolvedSource",
    ):
        value = string_value(entry.get(key))
        if value:
            return value
    return ""


def first_string(entry: dict[str, Any], keys: list[str]) -> str:
    for key in keys:
        value = string_value(entry.get(key))
        if value:
            return value
    return ""


def array_value(entry: dict[str, Any], keys: list[str]) -> list[Any]:
    for key in keys:
        value = entry.get(key)
        if isinstance(value, list):
            return value
    return []


def load_artifact_install_evidence(path: Path) -> Optional[dict[str, Any]]:
    if not path.exists():
        return None
    try:
        evidence = json.loads(path.read_text(encoding="utf-8"))
    except Exception as exc:  # noqa: BLE001 - this script must route malformed evidence as runner output
        return {
            "schema": "durable-workflow.v2.child-workflow-runtime.artifact-install-evidence",
            "generated_at": now(),
            "local_product_source_checkouts_used": False,
            "artifacts": [],
            "evidence_load_error": f"{path}: {exc}",
        }
    return evidence if isinstance(evidence, dict) else {
        "schema": "durable-workflow.v2.child-workflow-runtime.artifact-install-evidence",
        "generated_at": now(),
        "local_product_source_checkouts_used": False,
        "artifacts": [],
        "evidence_load_error": f"{path}: expected a JSON object",
    }


def normalize_artifact_install_evidence(
    evidence: Optional[dict[str, Any]],
    artifact_versions: dict[str, str],
) -> dict[str, Any]:
    raw_artifacts = evidence.get("artifacts") if isinstance(evidence, dict) else []
    if not isinstance(raw_artifacts, list):
        raw_artifacts = []
    by_artifact: dict[str, dict[str, Any]] = {}
    for item in raw_artifacts:
        if not isinstance(item, dict):
            continue
        artifact = string_value(item.get("artifact") or item.get("name"))
        if artifact:
            by_artifact[artifact] = item

    artifacts: list[dict[str, Any]] = []
    for artifact in REQUIRED_INSTALL_ARTIFACTS:
        item = by_artifact.get(artifact, {})
        raw_version = string_value(
            item.get("version")
            or item.get("artifact_version")
            or item.get("artifactVersion")
            or item.get("resolved_version")
            or item.get("resolvedVersion"),
        )
        raw_source = entry_source(item)
        version = raw_version or artifact_version_for(artifact_versions, artifact)
        artifacts.append(
            {
                "artifact": artifact,
                "version": version,
                "version_provided": bool(raw_version),
                "source": raw_source or "not_exercised",
                "source_provided": bool(raw_source),
                "status": normalized_status(item.get("status") or item.get("result") or item.get("outcome")),
                "local_product_source_checkouts_used": truthy_flag(
                    item.get("local_product_source_checkouts_used")
                    or item.get("localProductSourceCheckoutsUsed"),
                ),
                "detail": string_value(item.get("detail") or item.get("observed_behavior")),
                "command": item.get("command") if isinstance(item, dict) else None,
                "commands": item.get("commands") if isinstance(item.get("commands"), list) else [],
                "output_sample": item.get("output_sample") or item.get("outputSample") or "",
            },
        )

    top_local = bool(
        isinstance(evidence, dict)
        and (
            truthy_flag(evidence.get("local_product_source_checkouts_used"))
            or truthy_flag(evidence.get("localProductSourceCheckoutsUsed"))
        ),
    )
    top_explicit_false = bool(
        isinstance(evidence, dict)
        and (
            explicit_false_flag(evidence.get("local_product_source_checkouts_used"))
            or explicit_false_flag(evidence.get("localProductSourceCheckoutsUsed"))
        ),
    )

    return {
        "schema": string_value(evidence.get("schema") if isinstance(evidence, dict) else "")
        or "durable-workflow.v2.child-workflow-runtime.artifact-install-evidence",
        "generated_at": string_value(evidence.get("generated_at") if isinstance(evidence, dict) else "") or now(),
        "local_product_source_checkouts_used": top_local
        or any(item["local_product_source_checkouts_used"] for item in artifacts),
        "local_product_source_checkouts_used_explicit_false": top_explicit_false,
        "evidence_load_error": string_value(evidence.get("evidence_load_error") if isinstance(evidence, dict) else ""),
        "artifacts": artifacts,
    }


def artifact_install_entry_by_name(evidence: dict[str, Any]) -> dict[str, dict[str, Any]]:
    entries: dict[str, dict[str, Any]] = {}
    artifacts = evidence.get("artifacts")
    if not isinstance(artifacts, list):
        return entries
    for item in artifacts:
        if not isinstance(item, dict):
            continue
        artifact = string_value(item.get("artifact") or item.get("name"))
        if artifact:
            entries[artifact] = item
    return entries


def install_source_is_forbidden(source: str) -> bool:
    normalized = source.lower()
    return any(token in normalized for token in FORBIDDEN_INSTALL_SOURCE_TOKENS)


def install_source_matches_artifact(artifact: str, version: str, source: str) -> bool:
    normalized = source.lower()
    if not source or source == "not_exercised" or is_placeholder(source) or install_source_is_forbidden(source):
        return False

    if artifact == "server" and "@sha256:" in normalized:
        return "durableworkflow/server" in normalized

    if version and version.lower() not in normalized:
        return False

    generic_sources = {
        "docker",
        "github_release",
        "github_release_installer",
        "published_install_script",
        "pypi",
        "packagist",
        "published_artifact",
    }
    if normalized in generic_sources:
        return False

    if artifact == "server":
        return "durableworkflow/server" in normalized
    if artifact == "cli":
        return "github" in normalized and ("release" in normalized or "/releases/" in normalized)
    if artifact == "sdk-python":
        return "pypi" in normalized or "pythonhosted.org" in normalized or "durable-workflow==" in normalized
    if artifact == "sdk-rust":
        return "crates.io" in normalized or "crates.io/api/v1/crates/durable-workflow" in normalized
    if artifact == "workflow-php":
        return "packagist" in normalized or "durable-workflow/workflow" in normalized
    if artifact == "waterline":
        return "packagist" in normalized or "durable-workflow/waterline" in normalized
    return False


def artifact_install_evidence_failures(
    evidence: dict[str, Any],
    artifact_versions: dict[str, str],
    evidence_was_supplied: bool,
) -> list[str]:
    failures: list[str] = []
    if not evidence_was_supplied:
        failures.append("artifact_install_evidence missing")
    if evidence.get("evidence_load_error"):
        failures.append(f"artifact_install_evidence load failed: {evidence['evidence_load_error']}")
    if evidence.get("local_product_source_checkouts_used"):
        failures.append("artifact_install_evidence.local_product_source_checkouts_used=true")
    if evidence_was_supplied and not evidence.get("local_product_source_checkouts_used_explicit_false"):
        failures.append("artifact_install_evidence.local_product_source_checkouts_used=false missing")

    entries = artifact_install_entry_by_name(evidence)
    for artifact in REQUIRED_INSTALL_ARTIFACTS:
        entry = entries.get(artifact)
        expected_version = artifact_version_for(artifact_versions, artifact)
        if entry is None:
            failures.append(f"{artifact}.artifact_install_evidence=missing")
            continue
        status = normalized_status(entry.get("status"))
        if status != "pass":
            failures.append(f"{artifact}.status={status or 'missing'}")

        version = string_value(entry.get("version"))
        if not truthy_flag(entry.get("version_provided")):
            failures.append(f"{artifact}.version=missing")
        elif not version or not SEMVER_RE.match(version) or is_placeholder(version):
            failures.append(f"{artifact}.version={version or 'missing'}")
        elif expected_version and version != expected_version:
            failures.append(f"{artifact}.version={version} does not match resolved artifact version {expected_version}")

        source = entry_source(entry)
        if not truthy_flag(entry.get("source_provided")):
            failures.append(f"{artifact}.source=missing")
        elif not install_source_matches_artifact(artifact, version, source):
            failures.append(f"{artifact}.source={source}")

        if truthy_flag(entry.get("local_product_source_checkouts_used") or entry.get("localProductSourceCheckoutsUsed")):
            failures.append(f"{artifact}.local_product_source_checkouts_used=true")
        commands = entry.get("commands")
        if not isinstance(commands, list) or not commands:
            failures.append(f"{artifact}.commands=missing")
        elif not all(isinstance(command, dict) and isinstance(command.get("argv"), list) and command.get("argv") for command in commands):
            failures.append(f"{artifact}.commands=invalid")
        if not string_value(entry.get("output_sample")):
            failures.append(f"{artifact}.output_sample=missing")

    return failures


def load_typed_failure_evidence(path_text: str) -> Optional[dict[str, Any]]:
    if not path_text:
        return None

    path = Path(path_text)
    if not path.exists():
        return {
            "schema": "durable-workflow.v2.child-workflow-runtime.typed-failure-evidence",
            "generated_at": now(),
            "local_product_source_checkouts_used": False,
            "failure_round_trip_cells": [],
            "evidence_load_error": f"{path}: missing",
        }

    try:
        evidence = json.loads(path.read_text(encoding="utf-8"))
    except Exception as exc:  # noqa: BLE001 - malformed focused evidence must be routed as result data
        return {
            "schema": "durable-workflow.v2.child-workflow-runtime.typed-failure-evidence",
            "generated_at": now(),
            "local_product_source_checkouts_used": False,
            "failure_round_trip_cells": [],
            "evidence_load_error": f"{path}: {exc}",
        }

    return evidence if isinstance(evidence, dict) else {
        "schema": "durable-workflow.v2.child-workflow-runtime.typed-failure-evidence",
        "generated_at": now(),
        "local_product_source_checkouts_used": False,
        "failure_round_trip_cells": [],
        "evidence_load_error": f"{path}: expected a JSON object",
    }


def load_full_matrix_evidence(path_text: str) -> Optional[dict[str, Any]]:
    if not path_text:
        return None

    path = Path(path_text)
    if not path.exists():
        return {
            "schema": "durable-workflow.v2.child-workflow-runtime.full-matrix-evidence",
            "generated_at": now(),
            "local_product_source_checkouts_used": False,
            "scenario_results": [],
            "evidence_load_error": f"{path}: missing",
        }

    try:
        evidence = json.loads(path.read_text(encoding="utf-8"))
    except Exception as exc:  # noqa: BLE001 - malformed shard evidence must be routed as result data
        return {
            "schema": "durable-workflow.v2.child-workflow-runtime.full-matrix-evidence",
            "generated_at": now(),
            "local_product_source_checkouts_used": False,
            "scenario_results": [],
            "evidence_load_error": f"{path}: {exc}",
        }

    return evidence if isinstance(evidence, dict) else {
        "schema": "durable-workflow.v2.child-workflow-runtime.full-matrix-evidence",
        "generated_at": now(),
        "local_product_source_checkouts_used": False,
        "scenario_results": [],
        "evidence_load_error": f"{path}: expected a JSON object",
    }


def scenario_results_by_id(evidence: Optional[dict[str, Any]]) -> dict[str, dict[str, Any]]:
    if not isinstance(evidence, dict):
        return {}

    raw = evidence.get("scenario_results") or evidence.get("scenarioResults") or []
    if isinstance(raw, dict):
        items = raw.items()
    elif isinstance(raw, list):
        items = enumerate(raw)
    else:
        return {}

    results: dict[str, dict[str, Any]] = {}
    for key, value in items:
        if not isinstance(value, dict):
            continue
        scenario_id = str(key) if isinstance(key, str) else first_string(value, ["scenario_id", "scenarioId", "id"])
        if not scenario_id:
            continue
        item = dict(value)
        item["scenario_id"] = scenario_id
        results[scenario_id] = item

    return results


def runtime_history_events(history: Any) -> list[dict[str, Any]]:
    if not isinstance(history, dict):
        return []
    events = history.get("events") or history.get("history_events") or history.get("historyEvents") or []
    return [event for event in events if isinstance(event, dict)] if isinstance(events, list) else []


def runtime_history_timestamps(history: Any) -> list[str]:
    return [
        timestamp
        for event in runtime_history_events(history)
        if (timestamp := first_string(event, ["timestamp", "recorded_at", "recordedAt", "created_at", "createdAt"]))
    ]


def runtime_history_references_child(history: Any, child_run_id: str) -> bool:
    for event in runtime_history_events(history):
        payload = event.get("payload") if isinstance(event.get("payload"), dict) else {}
        if child_run_id in {
            first_string(payload, ["child_workflow_run_id", "childWorkflowRunId"]),
            first_string(payload, ["child_run_id", "childRunId"]),
            first_string(payload, ["resolved_child_run_id", "resolvedChildRunId"]),
        }:
            return True
    return False


def runtime_history_contains_event(history: Any, evidence_event: Any) -> bool:
    return isinstance(evidence_event, dict) and bool(evidence_event) \
        and evidence_event in runtime_history_events(history)


def synthetic_runtime_identity(value: str) -> bool:
    normalized = value.lower()
    return not value or bool(re.search(r"(^|[-_])(fixture|fake|example|placeholder|synthetic)([-_]|$)", normalized)) \
        or bool(re.search(r"^(parent|child)(-run)?-", normalized))


def runtime_relationship_failures(
    context: str,
    evidence: Any,
    require_typed_runtime_cancellation: bool = False,
) -> list[str]:
    if not isinstance(evidence, dict):
        return [f"{context}.runtime_evidence=missing"]
    parent_workflow_id = first_string(evidence, ["parent_workflow_id", "parentWorkflowId"])
    parent_run_id = first_string(evidence, ["parent_run_id", "parentRunId"])
    child_workflow_id = first_string(evidence, ["child_workflow_id", "childWorkflowId"])
    child_run_id = first_string(evidence, ["child_run_id", "childRunId"])
    task_queue = first_string(evidence, ["task_queue", "taskQueue"])
    parent_history = evidence.get("parent_history") or evidence.get("parentHistory") or {}
    child_history = evidence.get("child_history") or evidence.get("childHistory") or {}
    failures: list[str] = []

    for field, identity in (
        ("parent_workflow_id", parent_workflow_id),
        ("parent_run_id", parent_run_id),
        ("child_workflow_id", child_workflow_id),
        ("child_run_id", child_run_id),
    ):
        if synthetic_runtime_identity(identity):
            failures.append(f"{context}.{field}=synthetic_or_missing")

    for role, history, workflow_id, run_id in (
        ("parent", parent_history, parent_workflow_id, parent_run_id),
        ("child", child_history, child_workflow_id, child_run_id),
    ):
        if not isinstance(history, dict) or not history:
            failures.append(f"{context}.{role}_history=missing")
            continue
        if first_string(history, ["workflow_id", "workflowId"]) != workflow_id \
                or first_string(history, ["run_id", "runId"]) != run_id:
            failures.append(f"{context}.{role}_history.identity=mismatch")
        if not runtime_history_events(history) or not runtime_history_timestamps(history):
            failures.append(f"{context}.{role}_history.events=incomplete")

    if child_run_id and not runtime_history_references_child(parent_history, child_run_id):
        failures.append(f"{context}.parent_history.child_run_id=mismatch")

    observations = evidence.get("runtime_observations") or evidence.get("runtimeObservations") or []
    observed_parent = False
    observed_child = False
    if not isinstance(observations, list) or not observations:
        failures.append(f"{context}.runtime_observations=missing")
        observations = []
    for index, observation in enumerate(observations):
        if not isinstance(observation, dict):
            failures.append(f"{context}.runtime_observations[{index}]=incomplete")
            continue
        workflow_id = first_string(observation, ["workflow_id", "workflowId"])
        run_id = first_string(observation, ["run_id", "runId"])
        is_parent = workflow_id == parent_workflow_id and run_id == parent_run_id
        is_child = workflow_id == child_workflow_id and run_id == child_run_id
        observed_parent = observed_parent or is_parent
        observed_child = observed_child or is_child
        if not (is_parent or is_child) \
                or not first_string(observation, ["task_id", "taskId"]) \
                or not first_string(observation, ["lease_owner", "leaseOwner"]) \
                or not first_string(observation, ["runtime"]) \
                or first_string(observation, ["task_queue", "taskQueue"]) != task_queue:
            failures.append(f"{context}.runtime_observations[{index}]=contradictory_or_incomplete")
    if not observed_parent or not observed_child:
        failures.append(f"{context}.runtime_observations.run_coverage=mismatch")
    if require_typed_runtime_cancellation:
        typed_cancellation_observed = any(
            isinstance(observation, dict)
            and isinstance(observation.get("runtime_result") or observation.get("runtimeResult"), dict)
            and first_string(observation.get("runtime_result") or observation.get("runtimeResult"), ["failure_kind", "failureKind"]) == "cancelled"
            and first_string(observation.get("runtime_result") or observation.get("runtimeResult"), ["child_run_id", "childRunId"]) == child_run_id
            and first_string(observation.get("runtime_result") or observation.get("runtimeResult"), ["exception_class", "exceptionClass"]).endswith(".ChildWorkflowCancelled")
            for observation in observations
        )
        if not typed_cancellation_observed:
            failures.append(f"{context}.runtime_observations.typed_cancellation=mismatch")

    history_timestamps = set(runtime_history_timestamps(parent_history) + runtime_history_timestamps(child_history))
    for field in ("observed_at", "parent_observed_at", "child_cancelled_at"):
        timestamp = first_string(evidence, [field])
        if timestamp and timestamp not in history_timestamps:
            failures.append(f"{context}.{field}=not_from_history")

    return failures


def full_matrix_runtime_relationship_failures(evidence: dict[str, Any]) -> list[str]:
    failures: list[str] = []
    scenarios = scenario_results_by_id(evidence)
    for scenario_id in (
        "python_parent_python_child_baseline",
        "php_parent_php_child_baseline",
        "php_parent_python_child_cross_language",
        "python_parent_php_child_cross_language",
    ):
        scenario = scenarios.get(scenario_id, {})
        outputs = scenario.get("observed_outputs") or scenario.get("observedOutputs") or {}
        failures.extend(runtime_relationship_failures(f"scenario_results.{scenario_id}", outputs))

    failure_section = evidence.get("failure_round_trip") or evidence.get("failureRoundTrip") or {}
    failure_cells = raw_typed_failure_cells(failure_section)
    required_failure_directions = {
        ("sdk-python", "sdk-python"),
        ("workflow-php", "workflow-php"),
        ("workflow-php", "sdk-python"),
        ("sdk-python", "workflow-php"),
    }
    observed_failure_directions: set[tuple[str, str]] = set()
    for index, cell in enumerate(failure_cells):
        observed_failure_directions.add((
            first_string(cell, ["parent", "parent_runtime", "parentRuntime"]),
            first_string(cell, ["child", "child_runtime", "childRuntime"]),
        ))
        for field, aliases in (
            ("failure_kind", ["failure_kind", "failureKind"]),
            ("exception_class", ["exception_class", "exceptionClass"]),
            ("exception_type", ["exception_type", "exceptionType"]),
            ("message", ["message"]),
        ):
            if not first_string(cell, aliases):
                failures.append(f"failure_round_trip.cells[{index}].{field}=missing")
        if first_string(cell, ["failure_kind", "failureKind"]) != "child_workflow":
            failures.append(f"failure_round_trip.cells[{index}].failure_kind=invalid")
        failures.extend(runtime_relationship_failures(f"failure_round_trip.cells[{index}]", cell))
    for parent, child in sorted(required_failure_directions - observed_failure_directions):
        failures.append(f"failure_round_trip.{parent}->{child}=missing")

    cancellation = evidence.get("cancellation_propagation") or evidence.get("cancellationPropagation") or {}
    if isinstance(cancellation, dict):
        parent_cancellation = cancellation.get("parent_to_child") or cancellation.get("parentToChild") or {}
        direct_cancellation = cancellation.get("direct_child") or cancellation.get("directChild") or {}
        failures.extend(runtime_relationship_failures(
            "cancellation_propagation.parent_to_child",
            parent_cancellation,
        ))
        failures.extend(runtime_relationship_failures(
            "cancellation_propagation.direct_child",
            direct_cancellation,
            require_typed_runtime_cancellation=True,
        ))
        if not isinstance(parent_cancellation, dict):
            failures.append("cancellation_propagation.parent_to_child.typed_cancellation=invalid")
        else:
            child_run_id = first_string(parent_cancellation, ["child_run_id", "childRunId"])
            failure_kind = first_string(parent_cancellation, ["child_failure_kind", "childFailureKind"])
            exception_type = first_string(parent_cancellation, ["child_exception_type", "childExceptionType"])
            exception_class = first_string(parent_cancellation, ["child_exception_class", "childExceptionClass"])
            message = first_string(parent_cancellation, ["child_message", "childMessage"])
            parent_history = parent_cancellation.get("parent_history") or parent_cancellation.get("parentHistory") or {}
            child_history = parent_cancellation.get("child_history") or parent_cancellation.get("childHistory") or {}
            child_event = parent_cancellation.get("child_cancellation_history_evidence") \
                or parent_cancellation.get("childCancellationHistoryEvidence") or {}
            policy_event = parent_cancellation.get("parent_close_policy_evidence") \
                or parent_cancellation.get("parentClosePolicyEvidence") or {}
            child_payload = child_event.get("payload") if isinstance(child_event, dict) \
                and isinstance(child_event.get("payload"), dict) else {}
            policy_payload = policy_event.get("payload") if isinstance(policy_event, dict) \
                and isinstance(policy_event.get("payload"), dict) else {}
            if parent_cancellation.get("typed_cancellation_observed") is not True \
                    or first_string(parent_cancellation, ["typed_cancellation_evidence_source", "typedCancellationEvidenceSource"]) != "terminal_child_history_and_parent_close_policy" \
                    or failure_kind != "cancelled" \
                    or exception_type != "WorkflowCancelledException" \
                    or not exception_class.endswith("WorkflowCancelledException") \
                    or not message:
                failures.append("cancellation_propagation.parent_to_child.typed_cancellation=invalid")
            if not runtime_history_contains_event(child_history, child_event) \
                    or first_string(child_event, ["event_type", "eventType", "type"]) != "WorkflowCancelled" \
                    or first_string(child_payload, ["failure_category", "failureCategory"]) != failure_kind \
                    or first_string(child_payload, ["exception_class", "exceptionClass"]) != exception_class \
                    or first_string(child_payload, ["message"]) != message:
                failures.append("cancellation_propagation.parent_to_child.child_history=mismatch")
            if not runtime_history_contains_event(parent_history, policy_event) \
                    or first_string(policy_event, ["event_type", "eventType", "type"]) != "ParentClosePolicyApplied" \
                    or first_string(policy_payload, ["child_run_id", "childRunId"]) != child_run_id \
                    or first_string(policy_payload, ["policy"]) != "request_cancel":
                failures.append("cancellation_propagation.parent_to_child.parent_close_policy=mismatch")
        if not isinstance(direct_cancellation, dict) \
                or first_string(direct_cancellation, ["parent_failure_kind", "parentFailureKind"]) != "cancelled" \
                or not first_string(direct_cancellation, ["parent_exception_class", "parentExceptionClass"]).endswith(".ChildWorkflowCancelled"):
            failures.append("cancellation_propagation.direct_child.typed_cancellation=invalid")

    replay = evidence.get("replay_restart") or evidence.get("replayRestart") or {}
    failures.extend(runtime_relationship_failures("replay_restart", replay))
    if isinstance(replay, dict):
        original = replay.get("original_decision_sequence") or replay.get("originalDecisionSequence") or []
        replayed = replay.get("replayed_decision_sequence") or replay.get("replayedDecisionSequence") or []
        if not original or original != replayed or replay.get("duplicate_child_scheduled") is not False:
            failures.append("replay_restart.decision_sequence_or_duplicate=inconsistent")

    namespace = evidence.get("namespace_behavior") or evidence.get("namespaceBehavior") or {}
    failures.extend(runtime_relationship_failures("namespace_behavior", namespace))
    if isinstance(namespace, dict):
        expected_lineage = (
            first_string(namespace, ["parent_workflow_id", "parentWorkflowId"]),
            first_string(namespace, ["parent_run_id", "parentRunId"]),
            first_string(namespace, ["child_workflow_id", "childWorkflowId"]),
            first_string(namespace, ["child_run_id", "childRunId"]),
        )
        links = namespace.get("lineage_links") or namespace.get("lineageLinks") or []
        matching_lineage = any(isinstance(link, dict) and (
            first_string(link, ["parent_workflow_id", "parentWorkflowId"]),
            first_string(link, ["parent_run_id", "parentRunId"]),
            first_string(link, ["child_workflow_id", "childWorkflowId"]),
            first_string(link, ["child_run_id", "childRunId"]),
        ) == expected_lineage for link in links) if isinstance(links, list) else False
        if not matching_lineage or not isinstance(namespace.get("operator_visible_debug"), dict):
            failures.append("namespace_behavior.operator_lineage=missing_or_contradictory")

    fan_out = evidence.get("fan_out") or evidence.get("fanOut") or {}
    if isinstance(fan_out, dict):
        parent_history = fan_out.get("parent_history") or fan_out.get("parentHistory") or {}
        observations = fan_out.get("runtime_observations") or fan_out.get("runtimeObservations") or []
        child_histories = fan_out.get("child_histories") or fan_out.get("childHistories") or []
        identities = fan_out.get("child_run_identities") or fan_out.get("childRunIdentities") or []
        all_child_timestamps: set[str] = set()
        if first_string(fan_out, ["child_count", "childCount"]) not in {"5"} \
                and fan_out.get("child_count") != 5 and fan_out.get("childCount") != 5:
            failures.append("fan_out.child_count=not_five")
        if not isinstance(identities, list) or len(identities) != 5:
            failures.append("fan_out.child_run_identities=count_mismatch")
        if isinstance(identities, list):
            for index, identity in enumerate(identities):
                if not isinstance(identity, dict):
                    continue
                child_workflow_id = first_string(identity, ["workflow_id", "workflowId"])
                child_run_id = first_string(identity, ["run_id", "runId"])
                child_history = next((history for history in child_histories if isinstance(history, dict)
                    and first_string(history, ["workflow_id", "workflowId"]) == child_workflow_id
                    and first_string(history, ["run_id", "runId"]) == child_run_id), {}) \
                    if isinstance(child_histories, list) else {}
                relevant_observations = [observation for observation in observations if isinstance(observation, dict)
                    and ((first_string(observation, ["workflow_id", "workflowId"]) == first_string(fan_out, ["parent_workflow_id", "parentWorkflowId"])
                        and first_string(observation, ["run_id", "runId"]) == first_string(fan_out, ["parent_run_id", "parentRunId"]))
                    or (first_string(observation, ["workflow_id", "workflowId"]) == child_workflow_id
                        and first_string(observation, ["run_id", "runId"]) == child_run_id))] \
                    if isinstance(observations, list) else []
                failures.extend(runtime_relationship_failures(f"fan_out.children[{index}]", {
                    "parent_workflow_id": first_string(fan_out, ["parent_workflow_id", "parentWorkflowId"]),
                    "parent_run_id": first_string(fan_out, ["parent_run_id", "parentRunId"]),
                    "child_workflow_id": child_workflow_id,
                    "child_run_id": child_run_id,
                    "task_queue": first_string(fan_out, ["task_queue", "taskQueue"]),
                    "parent_history": parent_history,
                    "child_history": child_history,
                    "runtime_observations": relevant_observations,
                }))
                all_child_timestamps.update(runtime_history_timestamps(child_history))
        for field in ("child_started_at_values", "child_completed_at_values"):
            values = fan_out.get(field) or []
            if not isinstance(values, list) or any(not isinstance(value, str) or value not in all_child_timestamps for value in values):
                failures.append(f"fan_out.{field}=not_from_child_histories")

    return failures


def full_matrix_evidence_failures(
    evidence: Optional[dict[str, Any]],
    artifact_versions: dict[str, str],
    evidence_was_supplied: bool,
    install_evidence_pass: bool,
    required_scenario_ids: list[str],
) -> list[str]:
    failures: list[str] = []
    if not evidence_was_supplied:
        return failures
    if not isinstance(evidence, dict):
        return ["full_matrix_evidence expected a JSON object"]
    if string_value(evidence.get("execution_source")) != "published_server_image_runtime_probe":
        failures.append("full_matrix_evidence.execution_source is not the published runtime probe")
    if evidence.get("evidence_load_error"):
        failures.append(f"full_matrix_evidence load failed: {evidence['evidence_load_error']}")
    if evidence.get("local_product_source_checkouts_used") or evidence.get("localProductSourceCheckoutsUsed"):
        failures.append("full_matrix_evidence.local_product_source_checkouts_used=true")
    if not (
        explicit_false_flag(evidence.get("local_product_source_checkouts_used"))
        or explicit_false_flag(evidence.get("localProductSourceCheckoutsUsed"))
    ):
        failures.append("full_matrix_evidence.local_product_source_checkouts_used=false missing")
    if not install_evidence_pass:
        failures.append("full_matrix_evidence requires passing published artifact install evidence")

    raw_versions = evidence.get("artifact_versions") or evidence.get("artifactVersions") or {}
    if isinstance(raw_versions, dict):
        for artifact in ("server", "cli", "sdk-python", "sdk-rust", "workflow", "workflow-php", "waterline"):
            expected = artifact_version_for(artifact_versions, artifact)
            observed = artifact_version_for({str(key): string_value(value) for key, value in raw_versions.items()}, artifact)
            if expected and observed and expected != observed:
                failures.append(f"full_matrix_evidence.{artifact}.version={observed} does not match resolved artifact version {expected}")

    supplied = scenario_results_by_id(evidence)
    for scenario_id in required_scenario_ids:
        if scenario_id == "published_artifact_install_only":
            continue
        scenario = supplied.get(scenario_id)
        if scenario is None:
            failures.append(f"full_matrix_evidence.scenario_results.{scenario_id}=missing")
            continue
        status = normalized_status(scenario.get("status") or scenario.get("outcome") or scenario.get("result"))
        if status != "pass":
            failures.append(f"full_matrix_evidence.scenario_results.{scenario_id}.status={status or 'missing'}")
        outputs = scenario.get("observed_outputs") or scenario.get("observedOutputs")
        if not isinstance(outputs, dict) or not outputs:
            failures.append(f"full_matrix_evidence.scenario_results.{scenario_id}.observed_outputs=missing")

    for section in (
        "runtime_matrix",
        "failure_round_trip",
        "cancellation_propagation",
        "replay_restart",
        "fan_out",
        "namespace_behavior",
    ):
        if not isinstance(evidence.get(section), dict):
            failures.append(f"full_matrix_evidence.{section}=missing")

    failures.extend(full_matrix_runtime_relationship_failures(evidence))

    return failures


def full_matrix_section(evidence: Optional[dict[str, Any]], section: str) -> Optional[dict[str, Any]]:
    if not isinstance(evidence, dict):
        return None
    value = evidence.get(section)
    if isinstance(value, dict):
        return value
    camel = re.sub(r"_([a-z])", lambda match: match.group(1).upper(), section)
    value = evidence.get(camel)
    return value if isinstance(value, dict) else None


def raw_typed_failure_cells(evidence: Optional[dict[str, Any]]) -> list[dict[str, Any]]:
    if not isinstance(evidence, dict):
        return []

    for key in ("failure_round_trip_cells", "failureRoundTripCells", "cells", "matrix"):
        value = evidence.get(key)
        if isinstance(value, list):
            return [item for item in value if isinstance(item, dict)]

    for key in ("failure_round_trip", "failureRoundTrip"):
        section = evidence.get(key)
        if not isinstance(section, dict):
            continue
        for section_key in ("failure_round_trip_cells", "failureRoundTripCells", "cells", "matrix"):
            value = section.get(section_key)
            if isinstance(value, list):
                return [item for item in value if isinstance(item, dict)]

    return []


def normalize_typed_failure_cell(cell: dict[str, Any]) -> dict[str, Any]:
    public_surfaces = array_value(cell, ["public_surfaces", "publicSurfaces", "observation_surfaces"])
    parent_history = array_value(
        cell,
        ["parent_history_observations", "parentHistoryObservations", "parent_history_excerpt", "parentHistoryExcerpt"],
    )
    child_history = array_value(
        cell,
        ["child_history_observations", "childHistoryObservations", "child_history_excerpt", "childHistoryExcerpt"],
    )
    observed_failure = cell.get("parent_observed_failure") or cell.get("parentObservedFailure") or {}
    if not isinstance(observed_failure, dict):
        observed_failure = {}

    return {
        "scenario": first_string(cell, ["scenario", "scenario_id", "scenarioId"]) or "child_failure_round_trip_matrix",
        "parent": first_string(cell, ["parent", "parent_runtime", "parentRuntime"]),
        "child": first_string(cell, ["child", "child_runtime", "childRuntime"]),
        "status": normalized_status(cell.get("status") or cell.get("result") or cell.get("outcome")),
        "exception_class": first_string(cell, ["exception_class", "exceptionClass", "error_class", "errorClass"]),
        "message": first_string(cell, ["message", "error_message", "errorMessage"]),
        "failure_kind": first_string(cell, ["failure_kind", "failureKind", "kind"]),
        "parent_workflow_id": first_string(cell, ["parent_workflow_id", "parentWorkflowId"]),
        "parent_run_id": first_string(cell, ["parent_run_id", "parentRunId"]),
        "child_workflow_id": first_string(cell, ["child_workflow_id", "childWorkflowId"]),
        "child_run_id": first_string(cell, ["child_run_id", "childRunId"]),
        "parent_history_observations": parent_history,
        "child_history_observations": child_history,
        "public_surfaces": public_surfaces,
        "parent_observed_failure": observed_failure,
        "local_product_source_checkouts_used": truthy_flag(
            cell.get("local_product_source_checkouts_used") or cell.get("localProductSourceCheckoutsUsed"),
        ),
        "owning_surface": first_string(cell, ["owning_surface", "owningSurface", "root_cause_surface", "rootCauseSurface"]),
        "observed_behavior": first_string(cell, ["observed_behavior", "observedBehavior", "detail"]),
    }


def normalize_typed_failure_evidence(evidence: Optional[dict[str, Any]]) -> dict[str, Any]:
    top_local = bool(
        isinstance(evidence, dict)
        and (
            truthy_flag(evidence.get("local_product_source_checkouts_used"))
            or truthy_flag(evidence.get("localProductSourceCheckoutsUsed"))
        ),
    )
    top_explicit_false = bool(
        isinstance(evidence, dict)
        and (
            explicit_false_flag(evidence.get("local_product_source_checkouts_used"))
            or explicit_false_flag(evidence.get("localProductSourceCheckoutsUsed"))
        ),
    )
    cells = [normalize_typed_failure_cell(item) for item in raw_typed_failure_cells(evidence)]
    versions = {}
    if isinstance(evidence, dict):
        raw_versions = evidence.get("artifact_versions") or evidence.get("artifactVersions") or {}
        if isinstance(raw_versions, dict):
            versions = {str(key): string_value(value) for key, value in raw_versions.items()}

    return {
        "schema": string_value(evidence.get("schema") if isinstance(evidence, dict) else "")
        or "durable-workflow.v2.child-workflow-runtime.typed-failure-evidence",
        "generated_at": string_value(evidence.get("generated_at") if isinstance(evidence, dict) else "") or now(),
        "local_product_source_checkouts_used": top_local
        or any(item["local_product_source_checkouts_used"] for item in cells),
        "local_product_source_checkouts_used_explicit_false": top_explicit_false,
        "artifact_versions": versions,
        "evidence_load_error": string_value(evidence.get("evidence_load_error") if isinstance(evidence, dict) else ""),
        "failure_round_trip_cells": cells,
    }


def typed_failure_evidence_failures(
    evidence: dict[str, Any],
    artifact_versions: dict[str, str],
    evidence_was_supplied: bool,
    install_evidence_pass: bool,
) -> list[str]:
    if not evidence_was_supplied:
        return []

    failures: list[str] = []
    if evidence.get("evidence_load_error"):
        failures.append(f"typed_failure_evidence load failed: {evidence['evidence_load_error']}")
    if not install_evidence_pass:
        failures.append("typed_failure_evidence requires passing published artifact install evidence")
    if evidence.get("local_product_source_checkouts_used"):
        failures.append("typed_failure_evidence.local_product_source_checkouts_used=true")
    if not evidence.get("local_product_source_checkouts_used_explicit_false"):
        failures.append("typed_failure_evidence.local_product_source_checkouts_used=false missing")

    reported_versions = evidence.get("artifact_versions")
    if isinstance(reported_versions, dict):
        for artifact in ("server", "cli", "sdk-python", "workflow", "waterline"):
            reported = string_value(reported_versions.get(artifact))
            expected = string_value(artifact_versions.get(artifact))
            if reported and expected and reported != expected:
                failures.append(f"typed_failure_evidence.{artifact}.version={reported} does not match {expected}")

    cells = evidence.get("failure_round_trip_cells")
    if not isinstance(cells, list) or not cells:
        failures.append("typed_failure_evidence.failure_round_trip_cells=missing")

    return failures


def cell_matches(cell: dict[str, Any], required_cell: dict[str, Any]) -> bool:
    return (
        cell.get("scenario") == required_cell.get("scenario")
        and cell.get("parent") == required_cell.get("parent")
        and cell.get("child") == required_cell.get("child")
    )


def find_cell(cells: list[dict[str, Any]], required_cell: dict[str, Any]) -> Optional[dict[str, Any]]:
    for cell in cells:
        if cell_matches(cell, required_cell):
            return cell
    return None


def typed_failure_cell_failures(cell: dict[str, Any]) -> list[str]:
    failures: list[str] = []
    if cell.get("local_product_source_checkouts_used"):
        failures.append("local_product_source_checkouts_used=true")

    status = normalized_status(cell.get("status"))
    if status != "pass":
        return failures

    for field in (
        "exception_class",
        "message",
        "failure_kind",
        "parent_workflow_id",
        "parent_run_id",
        "child_workflow_id",
        "child_run_id",
    ):
        if not string_value(cell.get(field)):
            failures.append(f"{field}=missing")

    for field in ("parent_history_observations", "child_history_observations", "public_surfaces"):
        value = cell.get(field)
        if not isinstance(value, list) or not value:
            failures.append(f"{field}=missing")

    return failures


def failure_round_trip_evidence_state(
    matrix: dict[str, Any],
    typed_failure_evidence: dict[str, Any],
    typed_failure_failures: list[str],
    typed_failure_evidence_was_supplied: bool,
    default_status: str,
) -> dict[str, Any]:
    required_cells = matrix.get("failure_round_trip_cells")
    if not isinstance(required_cells, list):
        required_cells = []
    supplied_cells = typed_failure_evidence.get("failure_round_trip_cells")
    if not isinstance(supplied_cells, list):
        supplied_cells = []

    evidence_is_usable = typed_failure_evidence_was_supplied and not typed_failure_failures
    cells: list[dict[str, Any]] = []
    missing_cells: list[dict[str, Any]] = []
    invalid_cells: list[dict[str, Any]] = []
    failed_cells: list[dict[str, Any]] = []

    for required_cell in required_cells:
        if not isinstance(required_cell, dict):
            continue
        item = dict(required_cell)
        matched = find_cell(supplied_cells, required_cell)
        if matched is None:
            item["status"] = default_status
            cells.append(item)
            missing_cells.append(dict(required_cell))
            continue

        item.update({key: value for key, value in matched.items() if value not in ("", [], {})})
        cell_failures = typed_failure_cell_failures(matched)
        if not evidence_is_usable:
            item["status"] = default_status
        elif cell_failures:
            item["status"] = "not_covered"
            invalid_cells.append({"cell": dict(required_cell), "failures": cell_failures})
        else:
            status = normalized_status(matched.get("status")) or "not_covered"
            item["status"] = status
            if status != "pass":
                failed_cells.append(dict(item))
        cells.append(item)

    all_cells_pass = bool(required_cells) and not missing_cells and not invalid_cells and not failed_cells and evidence_is_usable
    if all_cells_pass:
        status = "pass"
    elif failed_cells and evidence_is_usable:
        status = "fail"
    else:
        status = default_status

    return {
        "status": status,
        "cells": cells,
        "all_cells_pass": all_cells_pass,
        "missing_cells": missing_cells,
        "invalid_cells": invalid_cells,
        "failed_cells": failed_cells,
    }


def failure_round_trip_findings(
    state: dict[str, Any],
    typed_failure_failures: list[str],
    artifact_versions: dict[str, str],
    default_runner_blocked: bool,
) -> list[dict[str, Any]]:
    findings: list[dict[str, Any]] = []
    if typed_failure_failures:
        findings.append(
            finding(
                "child_failure_round_trip_matrix",
                DEFAULT_EXPECTED_BEHAVIOR["child_failure_round_trip_matrix"],
                artifact_versions,
                default_runner_blocked,
                "; ".join(typed_failure_failures),
            ),
        )

    missing_cells = state.get("missing_cells")
    if isinstance(missing_cells, list) and missing_cells:
        cell_labels = [
            f"{item.get('parent', 'unknown')}->{item.get('child', 'unknown')}"
            for item in missing_cells
            if isinstance(item, dict)
        ]
        findings.append(
            finding(
                "child_failure_round_trip_matrix",
                DEFAULT_EXPECTED_BEHAVIOR["child_failure_round_trip_matrix"],
                artifact_versions,
                False,
                "typed failure evidence did not include required failure round-trip cells: " + ", ".join(cell_labels),
            ),
        )

    invalid_cells = state.get("invalid_cells")
    if isinstance(invalid_cells, list) and invalid_cells:
        details = []
        for item in invalid_cells:
            if not isinstance(item, dict):
                continue
            cell = item.get("cell") if isinstance(item.get("cell"), dict) else {}
            failures = item.get("failures") if isinstance(item.get("failures"), list) else []
            details.append(
                f"{cell.get('parent', 'unknown')}->{cell.get('child', 'unknown')} ({'; '.join(map(str, failures))})",
            )
        findings.append(
            finding(
                "child_failure_round_trip_matrix",
                DEFAULT_EXPECTED_BEHAVIOR["child_failure_round_trip_matrix"],
                artifact_versions,
                False,
                "typed failure evidence cells were missing stable metadata: " + ", ".join(details),
            ),
        )

    failed_cells = state.get("failed_cells")
    if isinstance(failed_cells, list):
        for cell in failed_cells:
            if not isinstance(cell, dict):
                continue
            observed = string_value(cell.get("observed_behavior")) or (
                "a child workflow typed/domain failure was not observed by the parent with stable failure metadata"
            )
            findings.append(
                {
                    "scenario_id": "child_failure_round_trip_matrix",
                    "finding_type": "product_behavior_gap",
                    "owning_surface": string_value(cell.get("owning_surface")) or "workflow_runtime",
                    "artifact_versions": artifact_versions,
                    "expected_behavior": DEFAULT_EXPECTED_BEHAVIOR["child_failure_round_trip_matrix"],
                    "observed_behavior": observed,
                    "user_visible_reproduction_steps": [
                        "Install the exact published artifacts recorded in artifact_versions.",
                        f"Run a {cell.get('parent', 'parent')} parent workflow that starts a {cell.get('child', 'child')} child workflow which throws a typed/domain failure.",
                        "Observe the parent workflow result, parent history, and public CLI/SDK read surfaces for typed failure metadata.",
                    ],
                    "next_acceptance_criterion": (
                        "the parent observes the child failure as a typed/domain failure with exception class, "
                        "message, failure kind, workflow/run identifiers, and history observations"
                    ),
                    "priority": "P1",
                },
            )

    return findings


def artifact_sources_from_install_evidence(evidence: dict[str, Any]) -> dict[str, str]:
    entries = artifact_install_entry_by_name(evidence)
    sources = {
        artifact: entry_source(entries.get(artifact, {})) or "not_exercised"
        for artifact in REQUIRED_INSTALL_ARTIFACTS
    }
    sources["workflow"] = sources["workflow-php"]
    return sources


def scenario_defs(manifest: dict[str, Any]) -> list[dict[str, Any]]:
    scenarios = manifest.get("scenarios")
    if isinstance(scenarios, list) and scenarios:
        return [item for item in scenarios if isinstance(item, dict)]
    return [{"id": item, "expected_behavior": DEFAULT_EXPECTED_BEHAVIOR[item]} for item in FALLBACK_REQUIRED_SCENARIO_IDS]


def required_matrix(manifest: dict[str, Any]) -> dict[str, Any]:
    matrix = manifest.get("required_matrix")
    if isinstance(matrix, dict):
        return matrix
    return {
        "runtimes": ["workflow-php", "sdk-python"],
        "same_language_cells": [
            {"parent": "sdk-python", "child": "sdk-python", "scenario": "python_parent_python_child_baseline"},
            {"parent": "workflow-php", "child": "workflow-php", "scenario": "php_parent_php_child_baseline"},
        ],
        "cross_language_cells": [
            {"parent": "workflow-php", "child": "sdk-python", "scenario": "php_parent_python_child_cross_language"},
            {"parent": "sdk-python", "child": "workflow-php", "scenario": "python_parent_php_child_cross_language"},
        ],
        "failure_round_trip_cells": [
            {"parent": "sdk-python", "child": "sdk-python", "scenario": "child_failure_round_trip_matrix"},
            {"parent": "workflow-php", "child": "workflow-php", "scenario": "child_failure_round_trip_matrix"},
            {"parent": "workflow-php", "child": "sdk-python", "scenario": "child_failure_round_trip_matrix"},
            {"parent": "sdk-python", "child": "workflow-php", "scenario": "child_failure_round_trip_matrix"},
        ],
    }


def finding(
    scenario_id: str,
    expected_behavior: str,
    artifact_versions: dict[str, str],
    runner_blocked: bool,
    reason: str,
) -> dict[str, Any]:
    if runner_blocked:
        observed = f"child-workflows conformance could not execute before product evidence was collected: {reason}"
        next_step = "provide exact published artifact pins and rerun child-workflows conformance"
        priority = "P0"
        finding_type = "runner_blocked"
    else:
        observed = (
            "child-workflows published-artifact evidence did not execute this required scenario; "
            "the result is routed as a coverage gap instead of being counted as passing smoke coverage"
        )
        if reason:
            observed += f": {reason}"
        next_step = (
            "extend the host runner to execute this scenario against published artifacts, "
            "or replace this coverage-gap finding with a focused product finding from the observed runtime mismatch"
        )
        priority = "P1"
        finding_type = "conformance_runner_coverage_gap"

    return {
        "scenario_id": scenario_id,
        "finding_type": finding_type,
        "owning_surface": "conformance_harness",
        "artifact_versions": artifact_versions,
        "expected_behavior": expected_behavior,
        "observed_behavior": observed,
        "user_visible_reproduction_steps": [
            "Set exact DW_SERVER_VERSION, DW_CLI_VERSION, DW_PYTHON_SDK_VERSION, DW_WORKFLOW_PHP_VERSION, and DW_WATERLINE_VERSION values.",
            "Run scripts/conformance/child-workflows-published-artifacts.sh --result-dir <result-dir>.",
            "Inspect child-workflows-result.json for the scenario status and linked finding.",
        ],
        "next_acceptance_criterion": next_step,
        "priority": priority,
    }


def with_cell_status(cells: Any, status: str) -> list[dict[str, Any]]:
    if not isinstance(cells, list):
        return []
    result = []
    for cell in cells:
        if not isinstance(cell, dict):
            continue
        item = dict(cell)
        item["status"] = status
        result.append(item)
    return result


def finding_links_by_scenario(findings: list[dict[str, Any]]) -> dict[str, list[dict[str, Any]]]:
    links: dict[str, list[dict[str, Any]]] = {}
    for item in findings:
        scenario_id = string_value(item.get("scenario_id"))
        if not scenario_id:
            continue
        links.setdefault(scenario_id, []).append(item)
    return links


def main() -> int:
    manifest = load_manifest()
    scenarios = scenario_defs(manifest)
    matrix = required_matrix(manifest)
    suite_version = manifest.get("suite_version")
    if not isinstance(suite_version, int):
        suite_version = None

    server_image = env("DW_SERVER_IMAGE")
    server_version = derive_server_version(server_image, env("DW_SERVER_VERSION"))
    if server_version and not server_image:
        server_image = f"durableworkflow/server:{server_version}"

    workflow_version = env("DW_WORKFLOW_PHP_VERSION")
    artifact_versions = {
        "server": server_version,
        "cli": normalize_cli_version(env("DW_CLI_VERSION")),
        "sdk-python": env("DW_PYTHON_SDK_VERSION"),
        "sdk-rust": env("DW_RUST_SDK_VERSION"),
        "workflow": workflow_version,
        "workflow-php": workflow_version,
        "waterline": env("DW_WATERLINE_VERSION"),
    }
    published_artifact_versions = {
        "server": artifact_versions["server"],
        "cli": artifact_versions["cli"],
        "sdk-python": artifact_versions["sdk-python"],
        "sdk-rust": artifact_versions["sdk-rust"],
        "workflow": artifact_versions["workflow"],
        "waterline": artifact_versions["waterline"],
    }
    install_evidence_path = Path(
        env("DW_CHILD_WORKFLOWS_ARTIFACT_INSTALL_EVIDENCE")
        or str(RESULT_DIR / "artifact-install-evidence.json"),
    )
    raw_install_evidence = load_artifact_install_evidence(install_evidence_path)
    install_evidence_was_supplied = raw_install_evidence is not None
    artifact_install_evidence = normalize_artifact_install_evidence(raw_install_evidence, artifact_versions)
    artifact_sources = artifact_sources_from_install_evidence(artifact_install_evidence)
    pin_failures = exact_version_failures(artifact_versions, server_image)
    install_failures = artifact_install_evidence_failures(
        artifact_install_evidence,
        artifact_versions,
        install_evidence_was_supplied,
    )
    install_evidence_pass = not pin_failures and not install_failures
    required_scenario_ids = [
        str(scenario.get("id", ""))
        for scenario in scenarios
        if str(scenario.get("id", ""))
    ]
    full_matrix_evidence_path_text = env("DW_CHILD_WORKFLOWS_FULL_MATRIX_EVIDENCE")
    if not full_matrix_evidence_path_text and (RESULT_DIR / "full-matrix-evidence.json").exists():
        full_matrix_evidence_path_text = str(RESULT_DIR / "full-matrix-evidence.json")
    full_matrix_evidence_path = Path(full_matrix_evidence_path_text) if full_matrix_evidence_path_text else None
    raw_full_matrix_evidence = load_full_matrix_evidence(full_matrix_evidence_path_text)
    full_matrix_evidence_was_supplied = raw_full_matrix_evidence is not None
    full_matrix_failures = full_matrix_evidence_failures(
        raw_full_matrix_evidence,
        artifact_versions,
        full_matrix_evidence_was_supplied,
        install_evidence_pass,
        required_scenario_ids,
    )
    full_matrix_usable = full_matrix_evidence_was_supplied and not full_matrix_failures
    full_matrix_scenarios = scenario_results_by_id(raw_full_matrix_evidence)
    typed_failure_evidence_path_text = env("DW_CHILD_WORKFLOWS_TYPED_FAILURE_EVIDENCE")
    typed_failure_evidence_path = Path(typed_failure_evidence_path_text) if typed_failure_evidence_path_text else None
    raw_typed_failure_evidence = load_typed_failure_evidence(typed_failure_evidence_path_text)
    typed_failure_evidence_was_supplied = raw_typed_failure_evidence is not None
    typed_failure_evidence = normalize_typed_failure_evidence(raw_typed_failure_evidence)
    typed_failure_failures = typed_failure_evidence_failures(
        typed_failure_evidence,
        artifact_versions,
        typed_failure_evidence_was_supplied,
        install_evidence_pass,
    )
    runner_blocked = bool(pin_failures)
    blocked_reason = "; ".join(pin_failures)
    non_install_status = "runner_blocked" if runner_blocked else "not_covered"
    finished_at = now()
    failure_round_trip_state = failure_round_trip_evidence_state(
        matrix,
        typed_failure_evidence,
        typed_failure_failures,
        typed_failure_evidence_was_supplied,
        non_install_status,
    )
    failure_round_trip_section = {
        "status": failure_round_trip_state["status"],
        "cells": failure_round_trip_state["cells"],
    }
    if typed_failure_evidence_was_supplied:
        failure_round_trip_section.update(
            {
                "typed_failure_evidence": typed_failure_evidence,
                "typed_failure_evidence_path": str(typed_failure_evidence_path),
                "typed_failure_evidence_failures": typed_failure_failures,
                "missing_failure_round_trip_cells": failure_round_trip_state["missing_cells"],
                "invalid_failure_round_trip_cells": failure_round_trip_state["invalid_cells"],
                "failed_failure_round_trip_cells": failure_round_trip_state["failed_cells"],
            },
        )

    findings: list[dict[str, Any]] = []
    scenario_results: list[dict[str, Any]] = []
    for scenario in scenarios:
        scenario_id = str(scenario.get("id", ""))
        if not scenario_id:
            continue
        expected_behavior = str(
            scenario.get("expected_behavior")
            or DEFAULT_EXPECTED_BEHAVIOR.get(scenario_id)
            or "required child-workflow conformance behavior is observed",
        )
        if scenario_id == "published_artifact_install_only" and install_evidence_pass:
            observed_outputs = {
                "server_image": server_image,
                "cli_release": artifact_versions["cli"],
                "workflow_php_package": f"durable-workflow/workflow:{artifact_versions['workflow']}",
                "sdk_python_package": f"durable-workflow=={artifact_versions['sdk-python']}",
                "sdk_rust_package": f"crates.io:durable-workflow={artifact_versions['sdk-rust']}",
                "waterline_artifact": f"durable-workflow/waterline:{artifact_versions['waterline']}",
                "artifact_sources": artifact_sources,
                "artifact_install_evidence": artifact_install_evidence,
                "artifact_install_evidence_path": str(install_evidence_path),
            }
            scenario_results.append(
                {
                    "scenario_id": scenario_id,
                    "status": "pass",
                    "expected_behavior": expected_behavior,
                    "observed_outputs": observed_outputs,
                },
            )
            continue

        full_matrix_scenario = full_matrix_scenarios.get(scenario_id)
        if full_matrix_usable and isinstance(full_matrix_scenario, dict):
            observed_outputs = full_matrix_scenario.get("observed_outputs") or full_matrix_scenario.get("observedOutputs")
            if not isinstance(observed_outputs, dict):
                observed_outputs = {}
            scenario_results.append(
                {
                    **full_matrix_scenario,
                    "scenario_id": scenario_id,
                    "status": "pass",
                    "expected_behavior": expected_behavior,
                    "observed_outputs": observed_outputs or {
                        "full_matrix_evidence_path": str(full_matrix_evidence_path),
                        "full_matrix_evidence_schema": string_value(
                            raw_full_matrix_evidence.get("schema") if isinstance(raw_full_matrix_evidence, dict) else "",
                        ),
                    },
                },
            )
            continue

        if scenario_id == "child_failure_round_trip_matrix" and typed_failure_evidence_was_supplied:
            scenario_findings = failure_round_trip_findings(
                failure_round_trip_state,
                typed_failure_failures,
                published_artifact_versions,
                runner_blocked,
            )
            findings.extend(scenario_findings)
            if failure_round_trip_state["all_cells_pass"]:
                scenario_results.append(
                    {
                        "scenario_id": scenario_id,
                        "status": "pass",
                        "expected_behavior": expected_behavior,
                        "observed_outputs": {
                            "failure_round_trip": failure_round_trip_section,
                            "typed_failure_evidence": typed_failure_evidence,
                            "typed_failure_evidence_path": str(typed_failure_evidence_path),
                        },
                    },
                )
            else:
                scenario_status = (
                    "fail"
                    if failure_round_trip_state["status"] == "fail"
                    else ("runner_blocked" if runner_blocked else "not_covered")
                )
                scenario_results.append(
                    {
                        "scenario_id": scenario_id,
                        "status": scenario_status,
                        "expected_behavior": expected_behavior,
                        "observed_outputs": {
                            "coverage_status": scenario_status,
                            "failure_round_trip": failure_round_trip_section,
                            "typed_failure_evidence": typed_failure_evidence,
                            "typed_failure_evidence_path": str(typed_failure_evidence_path),
                            "typed_failure_evidence_failures": typed_failure_failures,
                        },
                        "linked_findings": scenario_findings,
                    },
                )
            continue

        scenario_reason = blocked_reason
        if scenario_id == "published_artifact_install_only" and not runner_blocked:
            scenario_reason = "published artifact install evidence did not pass: " + "; ".join(install_failures)

        scenario_finding = finding(
            scenario_id,
            expected_behavior,
            published_artifact_versions,
            runner_blocked,
            scenario_reason,
        )
        findings.append(scenario_finding)
        scenario_results.append(
            {
                "scenario_id": scenario_id,
                "status": "runner_blocked" if runner_blocked else "not_covered",
                "expected_behavior": expected_behavior,
                "observed_outputs": {
                    "coverage_status": "runner_blocked" if runner_blocked else "not_covered",
                    "observed_behavior": scenario_finding["observed_behavior"],
                    "next_acceptance_criterion": scenario_finding["next_acceptance_criterion"],
                    **(
                        {
                            "artifact_install_evidence": artifact_install_evidence,
                            "artifact_install_evidence_path": str(install_evidence_path),
                            "artifact_install_failures": install_failures,
                        }
                        if scenario_id == "published_artifact_install_only"
                        else {}
                    ),
                },
                "linked_findings": [scenario_finding],
            },
        )

    runtime_matrix = full_matrix_section(raw_full_matrix_evidence, "runtime_matrix") if full_matrix_usable else None
    if runtime_matrix is None:
        runtime_matrix = {
        "runtimes": list(matrix.get("runtimes", ["workflow-php", "sdk-python"])),
        "same_language_cells": with_cell_status(matrix.get("same_language_cells"), non_install_status),
        "cross_language_cells": with_cell_status(matrix.get("cross_language_cells"), non_install_status),
        "failure_round_trip_cells": failure_round_trip_state["cells"],
        }

    published_artifact_install = {
        "server_image": server_image,
        "cli_release": artifact_versions["cli"],
        "workflow_php_package": (
            f"durable-workflow/workflow:{artifact_versions['workflow']}"
            if artifact_versions["workflow"]
            else ""
        ),
        "sdk_python_package": (
            f"durable-workflow=={artifact_versions['sdk-python']}"
            if artifact_versions["sdk-python"]
            else ""
        ),
        "sdk_rust_package": (
            f"crates.io:durable-workflow={artifact_versions['sdk-rust']}"
            if artifact_versions["sdk-rust"]
            else ""
        ),
        "waterline_artifact": (
            f"durable-workflow/waterline:{artifact_versions['waterline']}"
            if artifact_versions["waterline"]
            else ""
        ),
        "artifact_sources": artifact_sources,
        "artifact_install_evidence": artifact_install_evidence,
        "artifact_install_evidence_path": str(install_evidence_path),
        "pin_failures": pin_failures,
        "install_failures": install_failures,
    }

    cancellation_propagation = full_matrix_section(raw_full_matrix_evidence, "cancellation_propagation") if full_matrix_usable else None
    if cancellation_propagation is None:
        cancellation_propagation = {
            "parent_to_child": {"status": non_install_status},
            "direct_child": {"status": non_install_status},
        }

    replay_restart = full_matrix_section(raw_full_matrix_evidence, "replay_restart") if full_matrix_usable else None
    if replay_restart is None:
        replay_restart = {"status": non_install_status}

    fan_out = full_matrix_section(raw_full_matrix_evidence, "fan_out") if full_matrix_usable else None
    if fan_out is None:
        fan_out = {
            "status": non_install_status,
            "required_child_count": 5,
        }

    namespace_behavior = full_matrix_section(raw_full_matrix_evidence, "namespace_behavior") if full_matrix_usable else None
    if namespace_behavior is None:
        namespace_behavior = {"status": non_install_status}

    if full_matrix_usable:
        full_failure_round_trip = full_matrix_section(raw_full_matrix_evidence, "failure_round_trip")
        if full_failure_round_trip is not None:
            failure_round_trip_section = full_failure_round_trip

    scenario_status_by_id = {
        str(item.get("scenario_id", "")): normalized_status(item.get("status"))
        for item in scenario_results
        if isinstance(item, dict)
    }
    all_required_scenarios_pass = bool(required_scenario_ids) and all(
        scenario_status_by_id.get(scenario_id) == "pass"
        for scenario_id in required_scenario_ids
    )
    pass_result = install_evidence_pass and full_matrix_usable and all_required_scenarios_pass and not runner_blocked

    result = {
        "schema": "durable-workflow.v2.child-workflow-runtime.result",
        "schema_version": 1,
        "suite_schema": "durable-workflow.v2.platform-conformance.suite",
        "suite_version": suite_version,
        "category": "child_workflow_runtime_contract",
        "runtime_evidence_source": string_value(
            raw_full_matrix_evidence.get("execution_source") if isinstance(raw_full_matrix_evidence, dict) else "",
        ),
        "local_product_source_checkouts_used": bool(
            raw_full_matrix_evidence.get("local_product_source_checkouts_used", True)
            if isinstance(raw_full_matrix_evidence, dict)
            else True
        ),
        "outcome": "pass" if pass_result else ("non_passing_runner_blocked" if runner_blocked else "non_passing"),
        "runner_blocked": runner_blocked,
        "started_at": STARTED_AT,
        "finished_at": finished_at,
        "generated_at": finished_at,
        "artifact_versions": artifact_versions,
        "published_artifact_versions": published_artifact_versions,
        "artifact_sources": artifact_sources,
        "artifact_install_evidence": artifact_install_evidence,
        "published_artifact_install": published_artifact_install,
        "full_matrix_evidence": raw_full_matrix_evidence if full_matrix_evidence_was_supplied else None,
        "full_matrix_evidence_path": str(full_matrix_evidence_path) if full_matrix_evidence_path else "",
        "full_matrix_evidence_failures": full_matrix_failures,
        "runtime_matrix": runtime_matrix,
        "topology": {
            "task_queue": "cw-shared",
            "required_workers": ["workflow-php", "sdk-python"],
            "workflow_types": {
                "workflow-php": {"parent": "PhpParent", "child": "PhpChild"},
                "sdk-python": {"parent": "PythonParent", "child": "PythonChild"},
            },
        },
        "failure_round_trip": failure_round_trip_section,
        "cancellation_propagation": cancellation_propagation,
        "replay_restart": replay_restart,
        "fan_out": fan_out,
        "namespace_behavior": namespace_behavior,
        "scenario_results": scenario_results,
        "findings": findings,
        "finding_links": finding_links_by_scenario(findings),
    }

    metadata = {
        "started_at": STARTED_AT,
        "finished_at": finished_at,
        "generated_at": finished_at,
        "artifact_versions": artifact_versions,
        "published_artifact_versions": published_artifact_versions,
        "artifact_sources": artifact_sources,
        "artifact_install_evidence_path": str(install_evidence_path),
        "artifact_install_evidence_supplied": install_evidence_was_supplied,
        "full_matrix_evidence_path": str(full_matrix_evidence_path) if full_matrix_evidence_path else "",
        "full_matrix_evidence_supplied": full_matrix_evidence_was_supplied,
        "full_matrix_evidence_failures": full_matrix_failures,
        "typed_failure_evidence_path": str(typed_failure_evidence_path) if typed_failure_evidence_path else "",
        "typed_failure_evidence_supplied": typed_failure_evidence_was_supplied,
        "scenario_manifest": str(MANIFEST_PATH),
    }

    record = {
        "experiment": "child-workflows",
        "outcome": "pass" if pass_result else ("error" if runner_blocked else "fail"),
        "runnerBlocked": runner_blocked,
        "artifactVersions": published_artifact_versions,
        "findings": [
            f"{item['scenario_id']}: {item['observed_behavior']}"
            for item in findings
        ],
        "resultPath": str(RESULT_DIR / "child-workflows-result.json"),
    }

    RESULT_DIR.mkdir(parents=True, exist_ok=True)
    (RESULT_DIR / "pins.json").write_text(json.dumps(artifact_versions, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    (RESULT_DIR / "run-metadata.json").write_text(json.dumps(metadata, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    (RESULT_DIR / "child-workflows-result.json").write_text(json.dumps(result, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    (RESULT_DIR / "child-workflows-record.json").write_text(json.dumps(record, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(json.dumps(result, indent=2, sort_keys=True))

    return 0 if pass_result else 1


if __name__ == "__main__":
    raise SystemExit(main())
PY
