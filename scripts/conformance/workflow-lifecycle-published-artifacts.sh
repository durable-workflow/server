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
  rust-sdk-lifecycle-evidence.json
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
  DW_WORKFLOW_LIFECYCLE_SKIP_PHP_SDK_PROBE=1
                                    Skip the published PHP SDK lifecycle
                                    surface probe.
  DW_WORKFLOW_LIFECYCLE_SKIP_PYTHON_SDK_PROBE=1
                                    Skip the published Python SDK lifecycle
                                    surface probe.
  DW_WORKFLOW_LIFECYCLE_SKIP_RUST_SDK_PROBE=1
                                    Skip execution of the mandatory Rust shard.
                                    A missing Rust sidecar is always non-passing.
  DW_WORKFLOW_LIFECYCLE_PHP_BIN     Optional PHP binary for the local PHP SDK
                                    probe. Defaults to PHP_BIN, php, or common
                                    absolute PHP paths.
  DW_WORKFLOW_LIFECYCLE_COMPOSER_BIN
                                    Optional Composer binary for the local PHP
                                    SDK probe. Defaults to COMPOSER_BIN,
                                    composer, or common absolute Composer paths.
  DW_WORKFLOW_LIFECYCLE_PYTHON_BIN  Optional Python binary with the published
                                    durable-workflow package already installed
                                    for the Python SDK probe.
  DW_WORKFLOW_LIFECYCLE_CARGO_BIN   Optional Cargo binary for the Rust shard.
                                    Otherwise the probe uses a pinned Rust image.
  DW_WORKFLOW_LIFECYCLE_SERVER_URL  Public server endpoint used by the Rust shard.
  DW_PHP_SDK_VERSION                Exact Packagist durable-workflow/sdk version.
  DW_RUST_SDK_VERSION               Exact crates.io durable-workflow version.
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

run_php_sdk_lifecycle_probe() {
  if [[ "${DW_WORKFLOW_LIFECYCLE_SKIP_PHP_SDK_PROBE:-0}" == "1" || "${DW_WORKFLOW_LIFECYCLE_SKIP_PHP_SDK_PROBE:-}" == "true" ]]; then
    return 0
  fi
  if [[ -n "${DW_WORKFLOW_LIFECYCLE_EVIDENCE:-}" || -n "${DW_WORKFLOW_LIFECYCLE_EVIDENCE_PATH:-}" ]]; then
    return 0
  fi
  if [[ -s "$result_dir/php-sdk-lifecycle-evidence.json" ]]; then
    return 0
  fi

  DW_PHP_SDK_CONFORMANCE_RESULT_DIR="$result_dir" \
  DW_PHP_SDK_CONFORMANCE_SERVER_URL="${DW_WORKFLOW_LIFECYCLE_SERVER_URL:-}" \
  DW_PHP_SDK_CONFORMANCE_NAMESPACE="${DW_WORKFLOW_LIFECYCLE_NAMESPACE:-workflow-lifecycle-conformance}" \
  DW_PHP_SDK_CONFORMANCE_TOKEN="${DW_WORKFLOW_LIFECYCLE_AUTH_TOKEN:-dev-token}" \
  DW_PHP_SDK_CONFORMANCE_PHP_BIN="${DW_WORKFLOW_LIFECYCLE_PHP_BIN:-${PHP_BIN:-php}}" \
  DW_PHP_SDK_CONFORMANCE_COMPOSER_BIN="${DW_WORKFLOW_LIFECYCLE_COMPOSER_BIN:-${COMPOSER_BIN:-composer}}" \
  "$script_dir/php-sdk-published-artifacts.sh" --result-dir "$result_dir"
}

write_python_sdk_runner_blocked() {
  local reason="${1:-Python SDK lifecycle probe could not run.}"
  local operation="${2:-python_sdk_lifecycle_probe}"
  local exception_type="${3:-RunnerSetupError}"
  local message="${4:-$reason}"
  local executor="${5:-unknown}"

  RESULT_DIR="$result_dir" \
  DW_PYTHON_SDK_VERSION="${DW_PYTHON_SDK_VERSION:-}" \
  PYTHON_SDK_RUNNER_BLOCKED_REASON="$reason" \
  PYTHON_SDK_RUNNER_BLOCKED_OPERATION="$operation" \
  PYTHON_SDK_RUNNER_BLOCKED_EXCEPTION_TYPE="$exception_type" \
  PYTHON_SDK_RUNNER_BLOCKED_MESSAGE="$message" \
  PYTHON_SDK_RUNNER_BLOCKED_EXECUTOR="$executor" \
  node <<'NODE'
const fs = require('node:fs');
const path = require('node:path');

const resultDir = process.env.RESULT_DIR;
const version = (process.env.DW_PYTHON_SDK_VERSION || '').trim();
const boundedText = (value, maxBytes = 768) => {
  const normalized = String(value || '').replace(/\s+/g, ' ').trim();
  const bytes = Buffer.from(normalized, 'utf8');
  return bytes.length <= maxBytes
    ? normalized
    : `${bytes.subarray(0, maxBytes).toString('utf8').replace(/�+$/g, '').trimEnd()}...`;
};
const reason = boundedText(process.env.PYTHON_SDK_RUNNER_BLOCKED_REASON || 'Python SDK lifecycle probe could not run.');
const operation = boundedText(process.env.PYTHON_SDK_RUNNER_BLOCKED_OPERATION || 'python_sdk_lifecycle_probe', 160);
const exceptionType = boundedText(process.env.PYTHON_SDK_RUNNER_BLOCKED_EXCEPTION_TYPE || 'RunnerSetupError', 160);
const message = boundedText(process.env.PYTHON_SDK_RUNNER_BLOCKED_MESSAGE || reason);
const executor = boundedText(process.env.PYTHON_SDK_RUNNER_BLOCKED_EXECUTOR || 'unknown', 96);
const artifactSource = version ? `pypi://durable-workflow==${version}` : 'pypi://durable-workflow==unresolved';
const runtimeFailureEvidence = {
  schema: 'durable-workflow.v2.workflow-lifecycle.python-sdk-exception',
  operation,
  classification: 'runner-gap',
  owning_surface: 'conformance_harness',
  exception_type: exceptionType,
  message,
};

const payload = {
  schema: 'durable-workflow.v2.workflow-lifecycle.python-sdk-sidecar',
  generated_at: new Date().toISOString().replace(/\.\d{3}Z$/, 'Z'),
  runner: 'published-python-sdk-lifecycle-surface-probe',
  runner_blocked: true,
  scenario_results: {
    python_sdk_lifecycle_surface: {
      scenario_id: 'python_sdk_lifecycle_surface',
      status: 'runner_blocked',
      classification: 'runner-gap',
      published_artifact_cell_executed: false,
      observed_outputs: {
        sdk: 'sdk-python',
        artifact_version: version,
        artifact_source: artifactSource,
        published_artifact_cell_executed: false,
        local_product_source_checkouts_used: false,
        failure_stage: operation,
        failure_summary: reason,
        probe_executor: executor,
        runner_blocked_reason: reason,
        runtime_failure_evidence: runtimeFailureEvidence,
      },
      linked_findings: [
        {
          finding_id: 'workflow-lifecycle-python-sdk-lifecycle-surface-runner-gap',
          finding_type: 'conformance_runner_blocked',
          classification: 'runner-gap',
          scenario_id: 'python_sdk_lifecycle_surface',
          owning_surface: 'conformance_harness',
          summary: reason,
          observed_evidence: runtimeFailureEvidence,
          next_acceptance_criterion: 'Run the Python SDK lifecycle surface probe against the pinned PyPI artifact and record covered lifecycle cells plus typed refusals.',
        },
      ],
    },
  },
};

fs.writeFileSync(path.join(resultDir, 'python-sdk-lifecycle-evidence.json'), `${JSON.stringify(payload, null, 2)}\n`);
NODE
}

python_sdk_resolve_command() {
  local candidate resolved

  for candidate in "$@"; do
    if [[ -z "$candidate" ]]; then
      continue
    fi

    if [[ "$candidate" == */* ]]; then
      if [[ -x "$candidate" ]]; then
        printf '%s\n' "$candidate"
        return 0
      fi
      continue
    fi

    if resolved="$(command -v "$candidate" 2>/dev/null)" && [[ -n "$resolved" ]]; then
      printf '%s\n' "$resolved"
      return 0
    fi
  done

  return 1
}

python_sdk_explicit_python_bin() {
  python_sdk_resolve_command \
    "${DW_WORKFLOW_LIFECYCLE_PYTHON_BIN:-}" \
    "${PYTHON_BIN:-}"
}

python_sdk_python3_bin() {
  python_sdk_resolve_command \
    python3 \
    /usr/local/bin/python3 \
    /usr/bin/python3
}

python_sdk_last_log_line() {
  local log_path="$1"
  local fallback="$2"
  local line=""

  if [[ -s "$log_path" ]]; then
    line="$(tail -n 1 "$log_path" 2>/dev/null | tr '\r\n\t' '   ')"
  fi
  printf '%s\n' "${line:-$fallback}"
}

run_python_sdk_lifecycle_probe() {
  if [[ "${DW_WORKFLOW_LIFECYCLE_SKIP_PYTHON_SDK_PROBE:-0}" == "1" || "${DW_WORKFLOW_LIFECYCLE_SKIP_PYTHON_SDK_PROBE:-}" == "true" ]]; then
    return 0
  fi
  if [[ -n "${DW_WORKFLOW_LIFECYCLE_EVIDENCE:-}" || -n "${DW_WORKFLOW_LIFECYCLE_EVIDENCE_PATH:-}" ]]; then
    return 0
  fi
  if [[ -s "$result_dir/python-sdk-lifecycle-evidence.json" ]]; then
    return 0
  fi

  local sdk_version="${DW_PYTHON_SDK_VERSION:-}"
  if [[ -z "$sdk_version" ]]; then
    write_python_sdk_runner_blocked \
      'DW_PYTHON_SDK_VERSION is required for the Python SDK lifecycle probe.' \
      'python_sdk_probe.configuration' \
      'ConfigurationError'
    return 0
  fi

  local probe_dir="$result_dir/python-sdk-lifecycle-probe"
  mkdir -p "$probe_dir"
  if ! cp "$script_dir/workflow_lifecycle_python_discovery_fixture.py" "$probe_dir/"; then
    write_python_sdk_runner_blocked \
      'Python SDK lifecycle probe could not stage the Server runtime-discovery fixture.' \
      'python_sdk_probe.fixture_staging' \
      'FixtureStagingError'
    return 0
  fi

  cat > "$probe_dir/python-sdk-lifecycle-probe.py" <<'PY'
from __future__ import annotations

import asyncio
import json
import os
import sys
from importlib import metadata
from pathlib import Path
from typing import Any

import httpx

import durable_workflow
from durable_workflow import (
    ChildWorkflowRetryPolicy,
    Client,
    ContinueAsNew,
    InvalidArgument,
    StartChildWorkflow,
    WorkflowAlreadyStarted,
    WorkflowCancelled,
    WorkflowTerminated,
)
import durable_workflow.serializer as serializer
from workflow_lifecycle_python_discovery_fixture import (
    QUERY_TASKS_CAPABILITY_PATH,
    RUNTIME_DISCOVERY_PATH,
    response_for as discovery_response_for,
)

EXPECTED_VERSION = os.environ.get("DW_PYTHON_SDK_VERSION", "").strip()
RESULT_DIR = Path(os.environ.get("RESULT_DIR", ".")).resolve()
PROBE_EXECUTOR = os.environ.get("PYTHON_SDK_PROBE_EXECUTOR", "unknown").strip() or "unknown"

failures: list[str] = []
covered_cells: list[str] = []
unsupported_cells: list[str] = []
typed_errors: list[dict[str, Any]] = []
captured_requests: list[dict[str, Any]] = []
completed_operations: list[str] = []
current_operation = "python_sdk_probe.preflight"
product_behavior_reached = False
runtime_discovery: dict[str, Any] = {
    "method": "GET",
    "path": RUNTIME_DISCOVERY_PATH,
    "capability_path": QUERY_TASKS_CAPABILITY_PATH,
    "request_observed": False,
    "fixture_response_served": False,
    "response_status": None,
    "capability_value": None,
    "valid_public_response": False,
}


def check(condition: bool, message: str) -> None:
    if not condition:
        failures.append(message)


def mark(cell: str) -> None:
    covered_cells.append(cell)


def begin_operation(operation: str, *, product_behavior: bool = True) -> None:
    global current_operation, product_behavior_reached
    current_operation = operation
    if product_behavior:
        product_behavior_reached = True


def complete_operation() -> None:
    completed_operations.append(current_operation)


def bounded_message(value: object, limit: int = 768) -> str:
    message = " ".join(str(value).split())
    if not message:
        message = "The probe raised an exception without a message."
    encoded = message.encode("utf-8")
    if len(encoded) <= limit:
        return message
    return encoded[:limit].decode("utf-8", errors="ignore").rstrip() + "..."


def typed_error(cell: str, error_type: str, reason: str, *, unsupported: bool = False) -> None:
    if unsupported:
        unsupported_cells.append(cell)
    typed_errors.append(
        {
            "cell": cell,
            "typed_error": error_type,
            "refusal_reason": reason,
            "documented": True,
        }
    )


def response(status: int, payload: dict[str, Any] | None = None) -> httpx.Response:
    return httpx.Response(status, json=payload or {})


def record_discovery_response(
    request: httpx.Request,
    status: int,
    payload: dict[str, Any],
    *,
    fixture_response_served: bool,
) -> None:
    if request.method != "GET" or request.url.path != RUNTIME_DISCOVERY_PATH:
        return

    capability_value = (
        payload.get("worker_protocol", {})
        .get("server_capabilities", {})
        .get("query_tasks")
    )
    runtime_discovery.update(
        {
            "request_observed": True,
            "fixture_response_served": fixture_response_served,
            "response_status": status,
            "capability_value": capability_value,
            "valid_public_response": (
                fixture_response_served
                and status == 200
                and capability_value is True
            ),
        }
    )


def request_body(request: httpx.Request) -> dict[str, Any]:
    if not request.content:
        return {}
    decoded = json.loads(request.content.decode("utf-8"))
    return decoded if isinstance(decoded, dict) else {}


def handler(request: httpx.Request) -> httpx.Response:
    body = request_body(request)
    captured_requests.append(
        {
            "method": request.method,
            "path": request.url.path,
            "body": body,
            "namespace_header": request.headers.get("X-Namespace") == "workflow-lifecycle-conformance",
            "control_plane_header": request.headers.get("X-Durable-Workflow-Control-Plane-Version") == "2",
        }
    )

    discovery_response = discovery_response_for(request.method, request.url.path)
    if discovery_response is not None:
        status, payload = discovery_response
        record_discovery_response(
            request,
            status,
            payload,
            fixture_response_served=True,
        )
        return response(status, payload)

    if request.method == "POST" and request.url.path == "/api/workflows":
        if body.get("workflow_id") == "wf-python-duplicate":
            return response(
                409,
                {
                    "reason": "duplicate_not_allowed",
                    "message": "workflow already started",
                    "control_plane": {"operation": "start_workflow"},
                },
            )
        if "retry_policy" in body:
            return response(
                422,
                {
                    "reason": "validation_error",
                    "message": "The retry_policy field is not supported by the v2 workflow start API.",
                    "errors": {"retry_policy": ["unsupported field"]},
                    "control_plane": {"operation": "start_workflow"},
                },
            )
        return response(
            201,
            {
                "workflow_id": body.get("workflow_id"),
                "run_id": "run-python-started",
                "workflow_type": body.get("workflow_type"),
            },
        )

    if request.method == "POST" and request.url.path == "/api/workflows/wf-python-lifecycle/signal/approve":
        return response(202, {"accepted": True, "workflow_id": "wf-python-lifecycle"})

    if request.method == "POST" and request.url.path == "/api/workflows/wf-python-lifecycle/query/current":
        return response(200, {"result": {"state": "ready"}})

    if request.method == "POST" and request.url.path == "/api/workflows/wf-python-lifecycle/cancel":
        return response(202, {"outcome": "accepted", "command_status": "accepted"})

    if request.method == "POST" and request.url.path == "/api/workflows/wf-python-lifecycle/terminate":
        return response(202, {"outcome": "accepted", "command_status": "accepted"})

    not_found = {"reason": "workflow_not_found", "message": request.url.path}
    record_discovery_response(
        request,
        404,
        not_found,
        fixture_response_served=False,
    )
    return response(404, not_found)


async def run_client_surface() -> None:
    client = Client(
        "http://server:8080",
        token="test-token",
        namespace="workflow-lifecycle-conformance",
    )
    original_http = client._http
    client._http = httpx.AsyncClient(
        base_url=client.base_url,
        transport=httpx.MockTransport(handler),
        timeout=client.timeout,
    )
    await original_http.aclose()

    try:
        begin_operation("Client.start_workflow")
        handle = await client.start_workflow(
            workflow_type="workflow.lifecycle.python",
            task_queue="workflow-lifecycle-python",
            workflow_id="wf-python-lifecycle",
            input=["payload"],
            duplicate_policy="fail",
            execution_timeout_seconds=300,
            run_timeout_seconds=120,
        )
        start_request = captured_requests[-1]
        start_body = start_request["body"]

        check(handle.workflow_id == "wf-python-lifecycle", "Client.start_workflow must return a handle for the started workflow id.")
        check(handle.run_id == "run-python-started", "Client.start_workflow must preserve the started run id.")
        check(start_request["method"] == "POST", "Client.start_workflow must use POST.")
        check(start_request["path"] == "/api/workflows", "Client.start_workflow must target the workflow start API.")
        check(start_body.get("workflow_id") == "wf-python-lifecycle", "Client.start_workflow must preserve workflow_id.")
        check(start_body.get("duplicate_policy") == "fail", "Client.start_workflow must forward duplicate_policy.")
        check(start_body.get("execution_timeout_seconds") == 300, "Client.start_workflow must forward execution_timeout_seconds.")
        check(start_body.get("run_timeout_seconds") == 120, "Client.start_workflow must forward run_timeout_seconds.")
        check(start_body.get("task_queue") == "workflow-lifecycle-python", "Client.start_workflow must forward task_queue.")
        check(start_request["namespace_header"], "Client must include the selected namespace header.")
        check(start_request["control_plane_header"], "Client must include the control-plane version header.")
        envelope = start_body.get("input") if isinstance(start_body.get("input"), dict) else {}
        check(
            serializer.decode(str(envelope.get("blob", "")), codec=str(envelope.get("codec", "avro"))) == ["payload"],
            "Client.start_workflow must encode input arguments as a payload envelope.",
        )
        mark("workflow_client_start_with_duplicate_policy_and_timeout_budgets")
        mark("workflow_start")
        mark("workflow_timeout_budget")
        complete_operation()

        begin_operation("WorkflowHandle.signal")
        await handle.signal("approve", ["ok"])
        signal_request = captured_requests[-1]
        signal_body = signal_request["body"]
        check(signal_request["path"] == "/api/workflows/wf-python-lifecycle/signal/approve", "WorkflowHandle.signal must target the signal API.")
        check("input" in signal_body, "WorkflowHandle.signal must encode signal arguments.")
        mark("workflow_signal")
        complete_operation()

        begin_operation("WorkflowHandle.query.runtime_discovery")
        query = await handle.query("current")
        query_request = captured_requests[-1]
        discovery_requests = [
            request
            for request in captured_requests
            if request["method"] == "GET" and request["path"] == RUNTIME_DISCOVERY_PATH
        ]
        check(len(discovery_requests) == 1, "WorkflowHandle.query must perform Server runtime discovery exactly once.")
        check(query_request["path"] == "/api/workflows/wf-python-lifecycle/query/current", "WorkflowHandle.query must target the query API.")
        check(query == {"result": {"state": "ready"}}, "WorkflowHandle.query must expose the server query result.")
        mark("runtime_discovery_query_tasks_capability")
        mark("workflow_query")
        complete_operation()

        begin_operation("WorkflowHandle.cancel")
        await handle.cancel(reason="operator requested cancellation")
        cancel_request = captured_requests[-1]
        check(cancel_request["path"] == "/api/workflows/wf-python-lifecycle/cancel", "WorkflowHandle.cancel must target the cancel API.")
        check(cancel_request["body"].get("reason") == "operator requested cancellation", "WorkflowHandle.cancel must forward the cancellation reason.")
        mark("workflow_cancellation")
        complete_operation()

        begin_operation("WorkflowHandle.terminate")
        await handle.terminate(reason="operator requested termination")
        terminate_request = captured_requests[-1]
        check(terminate_request["path"] == "/api/workflows/wf-python-lifecycle/terminate", "WorkflowHandle.terminate must target the terminate API.")
        check(terminate_request["body"].get("reason") == "operator requested termination", "WorkflowHandle.terminate must forward the termination reason.")
        mark("workflow_handle_signal_query_cancel_terminate_methods")
        mark("workflow_termination")
        complete_operation()

        begin_operation("Client.start_workflow.duplicate")
        try:
            await client.start_workflow(
                workflow_type="workflow.lifecycle.python",
                task_queue="workflow-lifecycle-python",
                workflow_id="wf-python-duplicate",
                duplicate_policy="fail",
            )
            check(False, "duplicate workflow id start must raise WorkflowAlreadyStarted.")
        except WorkflowAlreadyStarted as exc:
            typed_error("workflow_id_reuse_duplicate_start_policy", exc.__class__.__name__, str(exc))
            mark("workflow_duplicate_start_typed_error")
            mark("workflow_duplicate_start")
        complete_operation()

        begin_operation("Client.start_workflow.retry_policy_refusal")
        try:
            await client._request(
                "POST",
                "/workflows",
                json={
                    "workflow_id": "wf-python-retry",
                    "workflow_type": "workflow.lifecycle.python",
                    "task_queue": "workflow-lifecycle-python",
                    "input": [],
                    "retry_policy": {"maximum_attempts": 2},
                },
                context="wf-python-retry",
            )
            check(False, "unsupported workflow-level retry policy must be refused by the server API.")
        except InvalidArgument as exc:
            typed_error("workflow_level_retry_policy", exc.__class__.__name__, str(exc), unsupported=True)
            mark("workflow_retry_policy_typed_refusal")
            mark("workflow_retry_policy")
        complete_operation()
    finally:
        await client.aclose()


def run_workflow_command_surface() -> None:
    begin_operation("ContinueAsNew.to_server_command")
    continue_as_new = ContinueAsNew(
        workflow_type="workflow.lifecycle.python.next",
        arguments=["next", 2],
        task_queue="workflow-lifecycle-python-next",
    )
    continue_command = continue_as_new.to_server_command("workflow-lifecycle-python")
    check(continue_command.get("type") == "continue_as_new", "ContinueAsNew must emit a continue_as_new command.")
    check(continue_command.get("workflow_type") == "workflow.lifecycle.python.next", "ContinueAsNew must preserve workflow_type.")
    check(continue_command.get("queue") == "workflow-lifecycle-python-next", "ContinueAsNew must preserve task_queue routing.")
    arguments = continue_command.get("arguments") if isinstance(continue_command.get("arguments"), dict) else {}
    check(
        serializer.decode(str(arguments.get("blob", "")), codec=str(arguments.get("codec", "avro"))) == ["next", 2],
        "ContinueAsNew must encode replacement run arguments.",
    )
    mark("continue_as_new_command_surface")
    mark("continue_as_new")
    complete_operation()

    begin_operation("StartChildWorkflow.to_server_command")
    retry = ChildWorkflowRetryPolicy(
        max_attempts=3,
        backoff_seconds=[1, 2],
        non_retryable_error_types=["DomainException"],
    )
    child = StartChildWorkflow(
        workflow_type="workflow.lifecycle.python.child",
        arguments=["child"],
        task_queue="workflow-lifecycle-python-child",
        parent_close_policy="terminate",
        retry_policy=retry,
        execution_timeout_seconds=600,
        run_timeout_seconds=120,
    )
    child_command = child.to_server_command("workflow-lifecycle-python")
    check(child_command.get("type") == "start_child_workflow", "StartChildWorkflow must emit a start_child_workflow command.")
    check(child_command.get("queue") == "workflow-lifecycle-python-child", "StartChildWorkflow must preserve child task queue routing.")
    check(child_command.get("parent_close_policy") == "terminate", "StartChildWorkflow must preserve parent close policy.")
    check(child_command.get("execution_timeout_seconds") == 600, "StartChildWorkflow must preserve execution timeout.")
    check(child_command.get("run_timeout_seconds") == 120, "StartChildWorkflow must preserve run timeout.")
    check(child_command.get("retry_policy", {}).get("max_attempts") == 3, "ChildWorkflowRetryPolicy must preserve max attempts.")
    check(child_command.get("retry_policy", {}).get("backoff_seconds") == [1, 2], "ChildWorkflowRetryPolicy must preserve backoff seconds.")
    check(
        child_command.get("retry_policy", {}).get("non_retryable_error_types") == ["DomainException"],
        "ChildWorkflowRetryPolicy must preserve non-retryable error types.",
    )
    mark("child_workflow_retry_timeout_and_parent_close_policy")
    mark("child_workflow")
    complete_operation()

    begin_operation("StartChildWorkflow.invalid_timeout_refusal")
    try:
        StartChildWorkflow(
            workflow_type="workflow.lifecycle.python.child",
            execution_timeout_seconds=120,
            run_timeout_seconds=121,
        ).to_server_command("workflow-lifecycle-python")
        check(False, "StartChildWorkflow must reject run_timeout_seconds greater than execution_timeout_seconds.")
    except ValueError as exc:
        typed_error("invalid_child_workflow_timeout_budget", exc.__class__.__name__, str(exc), unsupported=True)
    complete_operation()

    begin_operation("StartChildWorkflow.invalid_execution_timeout_refusal")
    try:
        StartChildWorkflow(
            workflow_type="workflow.lifecycle.python.child",
            execution_timeout_seconds=0,
        ).to_server_command("workflow-lifecycle-python")
        check(False, "StartChildWorkflow must reject zero execution_timeout_seconds.")
    except ValueError as exc:
        typed_error("invalid_child_workflow_execution_timeout", exc.__class__.__name__, str(exc), unsupported=True)
    complete_operation()

    begin_operation("workflow_terminal_exception_contract")
    cancelled = WorkflowCancelled("operator requested cancellation")
    terminated = WorkflowTerminated("operator requested termination")
    check(isinstance(cancelled, BaseException) and not isinstance(cancelled, Exception), "WorkflowCancelled must be a typed non-Exception throwable.")
    check(isinstance(terminated, Exception), "WorkflowTerminated must be catchable as an Exception.")
    mark("workflow_terminal_exception_contract")
    complete_operation()


def artifact_version() -> str:
    try:
        return metadata.version("durable-workflow")
    except metadata.PackageNotFoundError:
        return ""


def comparable_version(value: str) -> str:
    normalized = value.strip().lower().lstrip("v")
    for public, metadata_form in [
        ("-alpha.", "a"),
        ("-beta.", "b"),
        ("-rc.", "rc"),
    ]:
        normalized = normalized.replace(public, metadata_form)
    return normalized


version = artifact_version()
artifact_version_matches = (
    version != ""
    and (
        EXPECTED_VERSION == ""
        or comparable_version(version) == comparable_version(EXPECTED_VERSION)
    )
    and getattr(durable_workflow, "__version__", "") == version
)
check(version != "", "durable-workflow must be installed from PyPI with package metadata.")
check(
    EXPECTED_VERSION == ""
    or comparable_version(version) == comparable_version(EXPECTED_VERSION),
    f"installed durable-workflow version {version} did not match {EXPECTED_VERSION}.",
)
check(getattr(durable_workflow, "__version__", "") == version, "durable_workflow.__version__ must match package metadata.")

for symbol in [
    Client,
    ContinueAsNew,
    StartChildWorkflow,
    ChildWorkflowRetryPolicy,
    WorkflowAlreadyStarted,
    WorkflowCancelled,
    WorkflowTerminated,
    InvalidArgument,
]:
    check(symbol is not None, f"{symbol!r} must be exported by the PyPI package.")

runner_blocked = bool(failures)
runtime_failure_evidence: dict[str, Any] | None = None
if not runner_blocked:
    mark("pypi_artifact_imported")
    mark("public_lifecycle_symbols_exported")
    try:
        asyncio.run(run_client_surface())
        run_workflow_command_surface()
    except BaseException as exc:
        exception_type = exc.__class__.__name__
        message = bounded_message(exc)
        runtime_discovery_failure = (
            current_operation == "WorkflowHandle.query.runtime_discovery"
            and exception_type == "RuntimeDiscoveryUnavailable"
        )
        runner_blocked = (
            runtime_discovery_failure
            and not runtime_discovery["valid_public_response"]
        )
        classification = "runner-gap" if runner_blocked else "product-gap"
        owning_surface = "conformance_harness" if runner_blocked else "sdk-python"
        runtime_failure_evidence = {
            "schema": "durable-workflow.v2.workflow-lifecycle.python-sdk-exception",
            "operation": current_operation,
            "classification": classification,
            "owning_surface": owning_surface,
            "exception_type": exception_type,
            "message": message,
        }
        if runtime_discovery_failure:
            runtime_failure_evidence["runtime_discovery"] = dict(runtime_discovery)
        failures.append(f"{current_operation} raised {exception_type}: {message}")

covered_cells = sorted(set(covered_cells))
unsupported_cells = sorted(set(unsupported_cells))
if runner_blocked:
    status = "runner_blocked"
    classification = "runner-gap"
    owning_surface = "conformance_harness"
    finding_type = "conformance_runner_blocked"
elif failures:
    status = "fail"
    classification = "product-gap"
    owning_surface = "sdk-python"
    finding_type = "product_behavior_gap"
else:
    status = "pass"
    classification = "passed"
    owning_surface = "sdk-python"
    finding_type = ""

published_artifact_cell_executed = product_behavior_reached
failure_summary = bounded_message("; ".join(failures)) if failures else ""
artifact_source = f"pypi://durable-workflow=={EXPECTED_VERSION or version}"
finding = {
    "finding_id": f"workflow-lifecycle-python-sdk-lifecycle-surface-{classification}",
    "finding_type": finding_type,
    "classification": classification,
    "scenario_id": "python_sdk_lifecycle_surface",
    "owning_surface": owning_surface,
    "summary": failure_summary,
    "observed_evidence": runtime_failure_evidence or {},
    "next_acceptance_criterion": (
        "Correct the Python lifecycle conformance harness and rerun the pinned PyPI artifact."
        if runner_blocked
        else "Publish a Python SDK artifact whose lifecycle surface covers supported cells and typed refusals, then rerun workflow-lifecycle conformance."
    ),
}
payload = {
    "schema": "durable-workflow.v2.workflow-lifecycle.python-sdk-sidecar",
    "generated_at": __import__("datetime").datetime.now(__import__("datetime").timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
    "runner": "published-python-sdk-lifecycle-surface-probe",
    "runner_blocked": runner_blocked,
    "scenario_results": {
        "python_sdk_lifecycle_surface": {
            "scenario_id": "python_sdk_lifecycle_surface",
            "status": status,
            "classification": classification,
            "published_artifact_cell_executed": published_artifact_cell_executed,
            "observed_outputs": {
                "sdk": "sdk-python",
                "covered_cells": covered_cells,
                "unsupported_cells": unsupported_cells,
                "typed_errors": typed_errors,
                "artifact_version": EXPECTED_VERSION or version,
                "installed_artifact_version": version,
                "artifact_source": artifact_source,
                "pypi_package": "durable-workflow",
                "pypi_artifact_verified": artifact_version_matches,
                "python_runtime": sys.version.split()[0],
                "probe_executor": PROBE_EXECUTOR,
                "evidence_method": "pypi_installed_artifact_runtime_import_client_transport_and_workflow_command_execution",
                "captured_request_count": len(captured_requests),
                "captured_request_paths": [request["path"] for request in captured_requests],
                "runtime_discovery": runtime_discovery,
                "completed_operations": completed_operations,
                "operation": current_operation,
                "failure_stage": current_operation if failures else None,
                "failure_summary": failure_summary or None,
                "runtime_failure_evidence": runtime_failure_evidence,
                "published_artifact_cell_executed": published_artifact_cell_executed,
                "local_product_source_checkouts_used": False,
                "failures": failures,
            },
            "linked_findings": [] if status == "pass" else [finding],
        },
    },
}
RESULT_DIR.mkdir(parents=True, exist_ok=True)
(RESULT_DIR / "python-sdk-lifecycle-evidence.json").write_text(json.dumps(payload, indent=2) + "\n", encoding="utf-8")
PY

  local python_bin executor
  if python_bin="$(python_sdk_explicit_python_bin)"; then
    executor="configured_python_binary"
  else
    local python3_bin venv
    if ! python3_bin="$(python_sdk_python3_bin)"; then
      write_python_sdk_runner_blocked \
        'Python SDK lifecycle probe requires python3, or DW_WORKFLOW_LIFECYCLE_PYTHON_BIN pointing to a Python environment with the published durable-workflow package installed.' \
        'python_sdk_probe.runtime_resolution' \
        'RuntimeUnavailable'
      return 0
    fi

    venv="$result_dir/python-sdk-lifecycle-venv"
    if ! "$python3_bin" -m venv "$venv" >"$result_dir/python-sdk-lifecycle-venv.log" 2>&1; then
      local venv_error
      venv_error="$(python_sdk_last_log_line "$result_dir/python-sdk-lifecycle-venv.log" 'python -m venv exited without diagnostic output.')"
      write_python_sdk_runner_blocked \
        "Python SDK lifecycle probe could not create a virtual environment: ${venv_error}" \
        'python_sdk_probe.venv_creation' \
        'VirtualEnvironmentError' \
        "$venv_error" \
        'python_venv'
      return 0
    fi

    python_bin="$venv/bin/python"
    executor="venv_pypi_install"

    if ! "$python_bin" -m pip install --disable-pip-version-check --no-input "durable-workflow==${sdk_version}" >"$result_dir/python-sdk-lifecycle-pip-install.log" 2>&1; then
      local install_error
      install_error="$(python_sdk_last_log_line "$result_dir/python-sdk-lifecycle-pip-install.log" 'pip exited without diagnostic output.')"
      write_python_sdk_runner_blocked \
        "Pinned PyPI artifact installation failed for durable-workflow==${sdk_version}: ${install_error}" \
        'python_sdk_probe.pypi_install' \
        'PackageInstallError' \
        "$install_error" \
        "$executor"
      return 0
    fi
  fi

  if ! (
    cd "$probe_dir"
    RESULT_DIR="$result_dir" \
    DW_PYTHON_SDK_VERSION="$sdk_version" \
    PYTHON_SDK_PROBE_EXECUTOR="$executor" \
    "$python_bin" "$probe_dir/python-sdk-lifecycle-probe.py"
  ) >"$result_dir/python-sdk-lifecycle-probe.log" 2>&1; then
    if [[ -s "$result_dir/python-sdk-lifecycle-evidence.json" ]]; then
      return 0
    fi
    local probe_error probe_exception_type probe_message
    probe_error="$(python_sdk_last_log_line "$result_dir/python-sdk-lifecycle-probe.log" 'Python probe exited without diagnostic output.')"
    probe_exception_type="ProbeProcessError"
    probe_message="$probe_error"
    if [[ "$probe_error" == *:* ]]; then
      probe_exception_type="${probe_error%%:*}"
      probe_message="${probe_error#*:}"
    fi
    write_python_sdk_runner_blocked \
      "Published Python SDK lifecycle probe exited before writing evidence: ${probe_message}" \
      'python_sdk_probe.execution' \
      "$probe_exception_type" \
      "$probe_message" \
      "$executor"
    return 0
  fi

  if [[ ! -s "$result_dir/python-sdk-lifecycle-evidence.json" ]]; then
    write_python_sdk_runner_blocked \
      'Published Python SDK lifecycle probe completed without writing its structured evidence sidecar.' \
      'python_sdk_probe.evidence_write' \
      'EvidenceWriteError' \
      'The probe process exited successfully, but python-sdk-lifecycle-evidence.json was not created.' \
      "$executor"
  fi
}

run_rust_sdk_lifecycle_probe() {
  if [[ "${DW_WORKFLOW_LIFECYCLE_SKIP_RUST_SDK_PROBE:-0}" == "1" || "${DW_WORKFLOW_LIFECYCLE_SKIP_RUST_SDK_PROBE:-}" == "true" ]]; then
    return 0
  fi

  RESULT_DIR="$result_dir" \
  REPO_ROOT="$repo_root" \
  DW_SERVER_IMAGE="${DW_SERVER_IMAGE:-}" \
  DW_SERVER_VERSION="${DW_SERVER_VERSION:-}" \
  DW_RUST_SDK_VERSION="${DW_RUST_SDK_VERSION:-}" \
  node "$script_dir/workflow-lifecycle-rust-published-artifacts.mjs"
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
use App\Support\DirectConformanceWorkerProtocol;
use App\Support\WorkerProtocol;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Workflow\Serializers\Avro;
use Workflow\V2\Contracts\OperatorObservabilityRepository;
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
    'workflow_retry_backoff_or_refusal',
    'operator_diagnostics_surfaces',
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
        'sdk-php' => string_env('DW_PHP_SDK_VERSION'),
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
        'sdk-php' => $versions['sdk-php'] !== '' ? 'packagist://durable-workflow/sdk@'.$versions['sdk-php'] : '',
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

function run_workflow_retry_backoff_or_refusal_probe(): array
{
    $workflowId = 'workflow-lifecycle-retry-refusal-'.strtolower(bin2hex(random_bytes(4)));
    $retryPolicy = [
        'maximum_attempts' => 3,
        'initial_interval_seconds' => 1,
        'backoff_coefficient' => 2.0,
    ];

    $response = request_json('POST', '/workflows', [
        'workflow_id' => $workflowId,
        'workflow_type' => 'workflow.lifecycle.retry.unsupported',
        'task_queue' => LIFECYCLE_TERMINAL_TASK_QUEUE,
        'retry_policy' => $retryPolicy,
        'input' => ['cell' => 'workflow_retry_backoff_or_refusal'],
    ], [400, 422]);

    $refusal = typed_validation_refusal($response, 'retry_policy', 'workflow_retry_policy');
    $refusal['counted_as_pass_evidence'] = true;

    return pass_scenario('workflow_retry_backoff_or_refusal', [
        'workflow_id' => $workflowId,
        'retry_policy_shape' => $retryPolicy,
        'attempt_count_or_refusal_reason' => $refusal['refusal_reason'],
        'backoff_observation_or_error_type' => $refusal['typed_error'],
        'docs_match' => true,
        'typed_refusal' => [
            'typed_error' => $refusal['typed_error'],
            'refusal_reason' => $refusal['refusal_reason'],
            'documented' => true,
            'http_status' => $refusal['http_status'],
            'field' => $refusal['field'],
        ],
        'unsupported_retry_policy_refusal' => $refusal,
        'public_start_api' => [
            'path' => '/api/workflows',
            'field' => 'retry_policy',
            'http_status' => $refusal['http_status'],
            'message' => $refusal['refusal_reason'],
        ],
    ]);
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

function unique_strings(array $values): array
{
    $seen = [];
    $result = [];

    foreach ($values as $value) {
        if (! is_string($value)) {
            continue;
        }

        $value = trim($value);
        if ($value === '' || isset($seen[$value])) {
            continue;
        }

        $seen[$value] = true;
        $result[] = $value;
    }

    return $result;
}

function scenario_observed_outputs(array $scenarioResults, string $scenarioId): array
{
    $scenario = $scenarioResults[$scenarioId] ?? null;
    if (! is_array($scenario)) {
        return [];
    }

    return is_array($scenario['observed_outputs'] ?? null) ? $scenario['observed_outputs'] : [];
}

function require_diagnostic_outputs(array $scenarioResults, string $scenarioId): array
{
    $scenario = $scenarioResults[$scenarioId] ?? null;
    if (! is_array($scenario)) {
        throw new RuntimeException('operator diagnostics cannot prove '.$scenarioId.' because the lifecycle cell did not run');
    }

    $status = is_string($scenario['status'] ?? null) ? $scenario['status'] : '';
    if (! in_array($status, ['pass', 'unsupported'], true)) {
        throw new RuntimeException('operator diagnostics cannot prove '.$scenarioId.' because the lifecycle cell status is '.$status);
    }

    $outputs = scenario_observed_outputs($scenarioResults, $scenarioId);
    if ($outputs === []) {
        throw new RuntimeException('operator diagnostics cannot prove '.$scenarioId.' because observed outputs are missing');
    }

    return $outputs;
}

function public_cli_diagnostic_fields(): array
{
    return [
        'workflow:start.workflow_id',
        'workflow:start.run_id',
        'workflow:start.outcome',
        'workflow:describe.workflow_id',
        'workflow:describe.run_id',
        'workflow:describe.status',
        'workflow:describe.status_bucket',
        'workflow:describe.run_number',
        'workflow:describe.run_count',
        'workflow:describe.is_current_run',
        'workflow:describe.is_terminal',
        'workflow:describe.run_timeout_seconds',
        'workflow:describe.run_deadline_at',
        'workflow:describe.closed_at',
        'workflow:describe.closed_reason',
        'workflow:list-runs.run_count',
        'workflow:list-runs.runs[].run_id',
        'workflow:list-runs.runs[].run_number',
        'workflow:list-runs.runs[].status',
        'workflow:history.events[].sequence',
        'workflow:history.events[].event_type',
        'workflow:history.events[].timestamp',
        'workflow:history.events[].payload',
        'workflow:cancel.workflow_id',
        'workflow:cancel.run_id',
        'workflow:cancel.outcome',
        'workflow:cancel.command_status',
        'workflow:terminate.workflow_id',
        'workflow:terminate.run_id',
        'workflow:terminate.outcome',
        'workflow:terminate.command_status',
    ];
}

function public_api_diagnostic_fields(): array
{
    return [
        'POST /api/workflows.workflow_id',
        'POST /api/workflows.run_id',
        'POST /api/workflows.outcome',
        'POST /api/workflows.validation_errors',
        'GET /api/workflows/{workflowId}.run_id',
        'GET /api/workflows/{workflowId}.status',
        'GET /api/workflows/{workflowId}.run_count',
        'GET /api/workflows/{workflowId}.is_current_run',
        'GET /api/workflows/{workflowId}/runs.run_count',
        'GET /api/workflows/{workflowId}/runs.runs[].run_id',
        'GET /api/workflows/{workflowId}/runs.runs[].run_number',
        'GET /api/workflows/{workflowId}/runs.runs[].status',
        'GET /api/workflows/{workflowId}/runs/{runId}.status',
        'GET /api/workflows/{workflowId}/runs/{runId}.status_bucket',
        'GET /api/workflows/{workflowId}/runs/{runId}.closed_at',
        'GET /api/workflows/{workflowId}/runs/{runId}.closed_reason',
        'GET /api/workflows/{workflowId}/runs/{runId}.run_timeout_seconds',
        'GET /api/workflows/{workflowId}/runs/{runId}.run_deadline_at',
        'GET /api/workflows/{workflowId}/runs/{runId}/history.events[].sequence',
        'GET /api/workflows/{workflowId}/runs/{runId}/history.events[].event_type',
        'GET /api/workflows/{workflowId}/runs/{runId}/history.events[].timestamp',
        'GET /api/workflows/{workflowId}/runs/{runId}/history.events[].principal',
        'GET /api/workflows/{workflowId}/runs/{runId}/history.events[].payload',
        'POST /api/workflows/{workflowId}/runs/{runId}/cancel.outcome',
        'POST /api/workflows/{workflowId}/runs/{runId}/terminate.outcome',
        'POST /api/workflows/{workflowId}/runs/{runId}/query/{queryName}.reason',
        'POST /api/worker/workflow-tasks/{taskId}/history.stop_reason',
        'POST /api/worker/workflow-tasks/{taskId}/history.run_status',
    ];
}

function public_waterline_diagnostic_fields(): array
{
    return [
        'flow.run_id',
        'flow.instance_id',
        'flow.status',
        'flow.status_bucket',
        'flow.is_terminal',
        'flow.closed_at',
        'flow.closed_reason',
        'flow.current_run_id',
        'flow.current_run_status',
        'flow.current_run_status_bucket',
        'flow.run_navigation[].run_id',
        'flow.run_navigation[].run_number',
        'flow.run_navigation[].status',
        'flow.history_event_count',
        'flow.timeline[].event_type',
        'flow.commands[].type',
        'flow.commands[].status',
        'flow.commands[].outcome',
        'flow.commands[].requested_run_id',
        'flow.commands[].resolved_run_id',
        'flow.can_issue_terminal_commands',
        'flow.can_cancel',
        'flow.can_terminate',
        'flow.run_diagnostics[].code',
    ];
}

function workflow_history_diagnostic_fields(array $scenarioResults): array
{
    $eventTypes = [
        'WorkflowStarted',
        'WorkflowContinuedAsNew',
        'WorkflowCancelled',
        'WorkflowTerminated',
        'WorkflowTimedOut',
    ];

    foreach ($scenarioResults as $scenario) {
        if (! is_array($scenario)) {
            continue;
        }

        $outputs = is_array($scenario['observed_outputs'] ?? null) ? $scenario['observed_outputs'] : [];
        foreach ($outputs['history_events'] ?? [] as $eventType) {
            if (is_string($eventType)) {
                $eventTypes[] = $eventType;
            }
        }
        if (is_string($outputs['terminal_history_event'] ?? null)) {
            $eventTypes[] = $outputs['terminal_history_event'];
        }
        $timing = is_array($outputs['operator_visible_timing'] ?? null) ? $outputs['operator_visible_timing'] : [];
        $history = is_array($timing['history'] ?? null) ? $timing['history'] : [];
        if (is_string($history['terminal_event'] ?? null)) {
            $eventTypes[] = $history['terminal_event'];
        }
    }

    return array_merge([
        'sequence',
        'event_type',
        'timestamp',
        'principal',
        'payload',
        'next_page_token',
        'compatibility_status',
        'compatibility_supported_in_fleet',
        'compatibility_fleet_reason',
    ], array_map(
        static fn (string $eventType): string => 'event_type:'.$eventType,
        unique_strings($eventTypes),
    ));
}

function waterline_observer_snapshot(?string $runId): array
{
    if (! is_string($runId) || trim($runId) === '') {
        return [
            'available' => false,
            'reason' => 'no_run_created_for_this_transition',
        ];
    }

    try {
        $run = WorkflowRun::query()->find($runId);
        if (! $run instanceof WorkflowRun) {
            return [
                'available' => false,
                'run_id' => $runId,
                'reason' => 'run_not_found_in_published_server_store',
            ];
        }

        /** @var OperatorObservabilityRepository $repository */
        $repository = app(OperatorObservabilityRepository::class);
        $detail = $repository->runDetail($run->fresh(), 200);
        $expectedFields = [
            'run_id',
            'instance_id',
            'status',
            'status_bucket',
            'is_terminal',
            'closed_at',
            'closed_reason',
            'current_run_id',
            'current_run_status',
            'current_run_status_bucket',
            'run_navigation',
            'history_event_count',
            'history_size_bytes',
            'timeline',
            'timeline_total_count',
            'commands',
            'can_issue_terminal_commands',
            'can_cancel',
            'can_terminate',
        ];
        $commands = is_array($detail['commands'] ?? null) ? array_values($detail['commands']) : [];

        return [
            'available' => true,
            'source' => 'Waterline flow detail observer state',
            'api_surface' => '/waterline/api/instances/{instanceId}/runs/{runId}',
            'run_id' => $runId,
            'instance_id' => is_string($detail['instance_id'] ?? null) ? $detail['instance_id'] : $run->workflow_instance_id,
            'status' => is_string($detail['status'] ?? null) ? $detail['status'] : $run->status->value,
            'status_bucket' => is_string($detail['status_bucket'] ?? null) ? $detail['status_bucket'] : null,
            'closed_reason' => is_string($detail['closed_reason'] ?? null) ? $detail['closed_reason'] : $run->closed_reason,
            'fields_present' => array_values(array_intersect($expectedFields, array_keys($detail))),
            'run_navigation_count' => is_array($detail['run_navigation'] ?? null) ? count($detail['run_navigation']) : 0,
            'timeline_total_count' => is_numeric($detail['timeline_total_count'] ?? null) ? (int) $detail['timeline_total_count'] : null,
            'history_event_count' => is_numeric($detail['history_event_count'] ?? null) ? (int) $detail['history_event_count'] : null,
            'can_issue_terminal_commands' => is_bool($detail['can_issue_terminal_commands'] ?? null) ? $detail['can_issue_terminal_commands'] : null,
            'command_statuses' => array_values(array_filter(array_map(
                static fn (mixed $command): string => is_array($command) && is_string($command['status'] ?? null)
                    ? $command['status']
                    : '',
                array_slice($commands, 0, 8),
            ))),
        ];
    } catch (Throwable $throwable) {
        return [
            'available' => false,
            'run_id' => $runId,
            'reason' => $throwable::class.': '.$throwable->getMessage(),
        ];
    }
}

function diagnostic_transition_matrix(array $scenarioResults): array
{
    $continueChain = require_diagnostic_outputs($scenarioResults, 'continue_as_new_run_chain_visibility');
    $continueHistory = require_diagnostic_outputs($scenarioResults, 'continue_as_new_identity_and_history_continuity');
    $continueSideEffects = require_diagnostic_outputs($scenarioResults, 'continue_as_new_duplicate_side_effect_prevention');
    $cancellation = require_diagnostic_outputs($scenarioResults, 'cancellation_public_surface_terminal_state');
    $termination = require_diagnostic_outputs($scenarioResults, 'termination_public_surface_terminal_state');
    $duplicateStart = require_diagnostic_outputs($scenarioResults, 'workflow_id_reuse_duplicate_start_policy');
    $timeout = require_diagnostic_outputs($scenarioResults, 'workflow_timeout_terminal_state');
    $retry = require_diagnostic_outputs($scenarioResults, 'workflow_retry_backoff_or_refusal');

    return [
        'continue_as_new_run_chain' => [
            'scenario_ids' => [
                'continue_as_new_run_chain_visibility',
                'continue_as_new_identity_and_history_continuity',
                'continue_as_new_duplicate_side_effect_prevention',
            ],
            'transition' => 'initial_run_continued_as_new_to_successor_run',
            'workflow_id' => $continueChain['workflow_id'] ?? null,
            'initial_run_id' => $continueChain['initial_run_id'] ?? null,
            'continued_run_id' => $continueChain['continued_run_id'] ?? null,
            'current_run_id' => $continueChain['current_run_id'] ?? null,
            'run_count' => $continueChain['run_count'] ?? null,
            'run_numbers' => $continueChain['run_numbers'] ?? [],
            'cli_surfaces' => ['workflow:start --json', 'workflow:list-runs --json', 'workflow:history --json'],
            'api_surfaces' => [
                $continueChain['run_chain_api_link'] ?? '/api/workflows/{workflowId}/runs',
                '/api/workflows/{workflowId}',
                '/api/workflows/{workflowId}/runs/{runId}/history',
            ],
            'history_events' => unique_strings(array_merge(
                is_array($continueHistory['history_events'] ?? null) ? $continueHistory['history_events'] : [],
                [
                    is_string($continueHistory['predecessor_closed_event'] ?? null) ? $continueHistory['predecessor_closed_event'] : '',
                    is_string($continueHistory['successor_started_event'] ?? null) ? $continueHistory['successor_started_event'] : '',
                ],
            )),
            'side_effect_diagnostic' => [
                'side_effect_key' => $continueSideEffects['side_effect_key'] ?? null,
                'expected_count' => $continueSideEffects['expected_count'] ?? null,
                'observed_count' => $continueSideEffects['observed_count'] ?? null,
                'replay_or_restart_window' => $continueSideEffects['replay_or_restart_window'] ?? null,
            ],
            'waterline_observer_state' => waterline_observer_snapshot(
                is_string($continueChain['continued_run_id'] ?? null) ? $continueChain['continued_run_id'] : null,
            ),
        ],
        'cancellation_requested_to_cancelled' => [
            'scenario_id' => 'cancellation_public_surface_terminal_state',
            'transition' => 'public_cancel_command_to_cancelled_terminal_state',
            'workflow_id' => $cancellation['workflow_id'] ?? null,
            'run_id' => $cancellation['run_id'] ?? null,
            'terminal_status' => $cancellation['terminal_status'] ?? null,
            'worker_error_type' => $cancellation['worker_error_type'] ?? null,
            'caller_error_type' => $cancellation['caller_error_type'] ?? null,
            'cli_surfaces' => ['workflow:cancel --json', 'workflow:describe --json', 'workflow:history --json'],
            'api_surfaces' => [
                '/api/workflows/{workflowId}/runs/{runId}/cancel',
                '/api/workflows/{workflowId}/runs/{runId}',
                '/api/workflows/{workflowId}/runs/{runId}/history',
                '/api/worker/workflow-tasks/{taskId}/history',
            ],
            'history_events' => is_array($cancellation['history_events'] ?? null) ? $cancellation['history_events'] : [],
            'waterline_observer_state' => waterline_observer_snapshot(
                is_string($cancellation['run_id'] ?? null) ? $cancellation['run_id'] : null,
            ),
        ],
        'termination_requested_to_terminated' => [
            'scenario_id' => 'termination_public_surface_terminal_state',
            'transition' => 'public_terminate_command_to_terminated_terminal_state',
            'workflow_id' => $termination['workflow_id'] ?? null,
            'run_id' => $termination['run_id'] ?? null,
            'terminal_status' => $termination['terminal_status'] ?? null,
            'worker_error_type' => $termination['worker_error_type'] ?? null,
            'caller_error_type' => $termination['caller_error_type'] ?? null,
            'cli_surfaces' => ['workflow:terminate --json', 'workflow:describe --json', 'workflow:history --json'],
            'api_surfaces' => [
                '/api/workflows/{workflowId}/runs/{runId}/terminate',
                '/api/workflows/{workflowId}/runs/{runId}',
                '/api/workflows/{workflowId}/runs/{runId}/history',
                '/api/worker/workflow-tasks/{taskId}/history',
            ],
            'history_events' => is_array($termination['history_events'] ?? null) ? $termination['history_events'] : [],
            'waterline_observer_state' => waterline_observer_snapshot(
                is_string($termination['run_id'] ?? null) ? $termination['run_id'] : null,
            ),
        ],
        'duplicate_start_refused' => [
            'scenario_id' => 'workflow_id_reuse_duplicate_start_policy',
            'transition' => 'duplicate_start_fail_policy_refused_without_new_run',
            'workflow_id' => $duplicateStart['workflow_id'] ?? null,
            'first_run_id' => $duplicateStart['first_run_id'] ?? null,
            'duplicate_start_outcome' => $duplicateStart['duplicate_start_outcome'] ?? null,
            'http_status_or_error_type' => $duplicateStart['http_status_or_error_type'] ?? null,
            'run_count_after_duplicate' => $duplicateStart['run_count_after_duplicate'] ?? null,
            'run_ids_after_duplicate' => $duplicateStart['run_ids_after_duplicate'] ?? [],
            'cli_surfaces' => ['workflow:start --duplicate-policy=fail --json', 'workflow:list-runs --json'],
            'api_surfaces' => ['/api/workflows', '/api/workflows/{workflowId}/runs'],
            'history_events' => [],
            'waterline_observer_state' => waterline_observer_snapshot(
                is_string($duplicateStart['first_run_id'] ?? null) ? $duplicateStart['first_run_id'] : null,
            ),
        ],
        'timeout_deadline_to_timed_out' => [
            'scenario_id' => 'workflow_timeout_terminal_state',
            'transition' => 'run_timeout_deadline_to_timed_out_terminal_state',
            'workflow_id' => $timeout['workflow_id'] ?? null,
            'run_id' => $timeout['run_id'] ?? null,
            'timeout_field' => $timeout['timeout_field'] ?? null,
            'deadline_at' => $timeout['deadline_at'] ?? null,
            'observed_terminal_at' => $timeout['observed_terminal_at'] ?? null,
            'terminal_status' => $timeout['terminal_status'] ?? null,
            'cli_surfaces' => ['workflow:start --run-timeout --json', 'workflow:describe --json', 'workflow:history --json'],
            'api_surfaces' => [
                '/api/workflows',
                '/api/workflows/{workflowId}/runs/{runId}',
                '/api/workflows/{workflowId}/runs/{runId}/history',
            ],
            'history_events' => is_array($timeout['history_events'] ?? null) ? $timeout['history_events'] : [],
            'operator_visible_timing' => is_array($timeout['operator_visible_timing'] ?? null) ? $timeout['operator_visible_timing'] : [],
            'waterline_observer_state' => waterline_observer_snapshot(
                is_string($timeout['run_id'] ?? null) ? $timeout['run_id'] : null,
            ),
        ],
        'retry_policy_typed_refusal' => [
            'scenario_id' => 'workflow_retry_backoff_or_refusal',
            'transition' => 'unsupported_workflow_retry_policy_refused_before_run_creation',
            'workflow_id' => $retry['workflow_id'] ?? null,
            'retry_policy_shape' => $retry['retry_policy_shape'] ?? null,
            'attempt_count_or_refusal_reason' => $retry['attempt_count_or_refusal_reason'] ?? null,
            'backoff_observation_or_error_type' => $retry['backoff_observation_or_error_type'] ?? null,
            'docs_match' => $retry['docs_match'] ?? null,
            'cli_surfaces' => ['workflow:start --json'],
            'api_surfaces' => ['/api/workflows'],
            'history_events' => [],
            'waterline_observer_state' => [
                'available' => false,
                'reason' => 'request_refused_before_run_creation',
            ],
        ],
    ];
}

function run_operator_diagnostics_surfaces_probe(array $scenarioResults): array
{
    $matrix = diagnostic_transition_matrix($scenarioResults);
    $workflowIds = unique_strings(array_map(
        static fn (mixed $row): string => is_array($row) && is_string($row['workflow_id'] ?? null)
            ? $row['workflow_id']
            : '',
        $matrix,
    ));

    return pass_scenario('operator_diagnostics_surfaces', [
        'workflow_id' => $workflowIds[0] ?? 'workflow-lifecycle-diagnostics',
        'workflow_ids' => $workflowIds,
        'cli_fields' => public_cli_diagnostic_fields(),
        'api_fields' => public_api_diagnostic_fields(),
        'history_fields' => workflow_history_diagnostic_fields($scenarioResults),
        'waterline_fields' => public_waterline_diagnostic_fields(),
        'diagnostic_transition_matrix' => $matrix,
        'public_cli_schema_sources' => [
            'workflow-start.schema.json',
            'workflow-run.schema.json',
            'workflow-runs.schema.json',
            'workflow-history.schema.json',
            'workflow-operation.schema.json',
        ],
        'waterline_observer_source' => 'Waterline flow detail API backed by OperatorObservabilityRepository::runDetail for the selected workflow run',
        'diagnostic_coverage_statement' => 'The matrix ties each lifecycle transition exercised by the focused published-artifact host probes to public CLI JSON fields, server API fields, history event fields, and Waterline-visible observer state where a run exists.',
    ]);
}

function run_workflow_timeout_terminal_state_probe(): array
{
    $workflowId = 'workflow-lifecycle-timeout-'.strtolower(bin2hex(random_bytes(4)));
    $workerId = 'workflow-lifecycle-timeout-worker-'.strtolower(bin2hex(random_bytes(4)));
    $workflowType = 'workflow.lifecycle.timeout';
    $runTimeoutSeconds = 1;

    request_json('POST', '/worker/register', DirectConformanceWorkerProtocol::registration(
        $workerId,
        LIFECYCLE_TERMINAL_TASK_QUEUE,
        'php',
        'durable-workflow/server:published-artifact',
        [$workflowType],
        [],
    ));

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

    request_json('POST', '/worker/register', DirectConformanceWorkerProtocol::registration(
        $workerId,
        LIFECYCLE_TERMINAL_TASK_QUEUE,
        'php',
        'durable-workflow/server:published-artifact',
        [$workflowType],
        [],
    ));

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

    request_json('POST', '/worker/register', DirectConformanceWorkerProtocol::registration(
        $workerId,
        LIFECYCLE_TERMINAL_TASK_QUEUE,
        'php',
        'durable-workflow/server:published-artifact',
        [$workflowType],
        [],
    ));

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

    request_json('POST', '/worker/register', DirectConformanceWorkerProtocol::registration(
        LIFECYCLE_WORKER_ID,
        LIFECYCLE_TASK_QUEUE,
        'php',
        'durable-workflow/server:published-artifact',
        [LIFECYCLE_WORKFLOW_TYPE],
        [],
    ));

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
                'arguments' => Avro::serialize(['Ada v2', $sideEffectKey]),
            ],
        ],
    ];
    $completionBody = DirectConformanceWorkerProtocol::workflowTaskCompletion($task, $completionBody['commands']);
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

    try {
        $scenarioResults['workflow_retry_backoff_or_refusal'] = run_workflow_retry_backoff_or_refusal_probe();
    } catch (Throwable $throwable) {
        $scenarioResults['workflow_retry_backoff_or_refusal'] = failure_scenario(
            'workflow_retry_backoff_or_refusal',
            $throwable,
        );
    }

    try {
        $scenarioResults['operator_diagnostics_surfaces'] = run_operator_diagnostics_surfaces_probe($scenarioResults);
    } catch (Throwable $throwable) {
        $scenarioResults['operator_diagnostics_surfaces'] = failure_scenario(
            'operator_diagnostics_surfaces',
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

run_php_sdk_lifecycle_probe
run_python_sdk_lifecycle_probe
run_rust_sdk_lifecycle_probe

RESULT_DIR="$result_dir" \
STARTED_AT="$started_at" \
MANIFEST_PATH="$manifest_path" \
DW_SERVER_IMAGE="${DW_SERVER_IMAGE:-}" \
DW_SERVER_VERSION="${DW_SERVER_VERSION:-}" \
DW_CLI_VERSION="${DW_CLI_VERSION:-}" \
DW_PYTHON_SDK_VERSION="${DW_PYTHON_SDK_VERSION:-}" \
DW_RUST_SDK_VERSION="${DW_RUST_SDK_VERSION:-}" \
DW_PHP_SDK_VERSION="${DW_PHP_SDK_VERSION:-}" \
DW_WORKFLOW_PHP_VERSION="${DW_WORKFLOW_PHP_VERSION:-}" \
DW_WATERLINE_VERSION="${DW_WATERLINE_VERSION:-}" \
node "$script_dir/workflow-lifecycle-published-artifacts.mjs"
