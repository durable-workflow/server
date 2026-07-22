#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage: worker-versioning-published-artifacts.sh [--result-dir DIR|--result-dir=DIR] [--keep-run-root[=1|true]]

Runs the public worker-versioning runtime routing cells against published
artifacts only. The runner records worker task delivery counts for v1-pinned
runs while v1 and v2 workers poll the same task queue.

The runner writes these files to the result directory:
  published-artifacts.json
  worker-versioning-result.json
  worker-versioning-record.json
  worker-versioning-http-captures.json

Environment overrides:
  DW_WV_RUN_ROOT             Scratch directory. Defaults to mktemp.
  DW_WV_RESULT_DIR           Result directory. Defaults to run root.
  DW_WV_KEEP_RUN_ROOT=1      Keep scratch directory after success.
  DW_WV_SERVER_URL           Existing published server URL to probe; disables compose startup.
  DW_SERVER_IMAGE            Exact server image/tag/digest to test.
  DW_SERVER_VERSION          Exact published server version under test.
  DW_CLI_VERSION             Published CLI version under test.
  DW_PYTHON_SDK_VERSION      Published PyPI durable-workflow version under test.
  DW_PHP_SDK_VERSION         Exact published durable-workflow/sdk version under test.
  DW_WORKFLOW_PHP_VERSION    Published durable-workflow/workflow version under test.
  DW_WATERLINE_VERSION       Published Waterline version under test.
  DW_WV_SERVER_PORT          Host port for the published server. Defaults to a free port.
  DW_WV_AUTH_TOKEN           Token used against the published server. Defaults to dev-token.
  DW_WV_NAMESPACE            Namespace used for probes. Defaults to worker-versioning-conformance.
  DW_WV_ARTIFACT_INSTALL_EVIDENCE
                              Optional JSON report proving CLI, Python SDK, PHP SDK, Workflow,
                              and Waterline installs from published artifact channels.
  DW_WV_PUBLISHED_WORKER_EVIDENCE
                              Optional JSON report from a host topology that executed
                              registration/replay/cache/no-compatible/cross-language/
                              adversarial cells with published workers.
                              When unset, this runner attempts to generate a Python
                              replay/cache shard and a PHP/Python cross-language shard
                              from published PyPI and Packagist artifacts.
  DW_WV_SERVER_BIND_HOST       Docker host interface for the published server port.
                              Defaults to 0.0.0.0.
  DW_WV_SERVER_CONNECT_HOST    First hostname/address used by the probe for the
                              self-started server URL. Defaults to 127.0.0.1;
                              the runner automatically probes localhost,
                              gateway, and host.docker.internal fallbacks.
  DW_WV_DOCKER_HOST_GATEWAY    Optional Docker host gateway/daemon hostname for
                              containerized host runners.
  DW_WV_SERVER_READINESS_TIMEOUT_SECONDS
                              Seconds to wait for the server namespace setup
                              prerequisite. Defaults to 120.
  DW_WV_WATERLINE_URL         Existing published Waterline URL for the same run database.
                              When unset, a disposable Packagist-installed Waterline
                              app is booted against the server run database when
                              the topology is self-started or external DB attach
                              coordinates are supplied.
  DW_WV_WATERLINE_RUNTIME_IMAGE
                              PHP runtime image used for the disposable Waterline app.
                              Must provide PHP >= 8.4.1 and pdo_mysql. When unset,
                              the runner builds a disposable PHP 8.4 runtime.
  DW_WV_WATERLINE_PHP_BASE_IMAGE
                              Base image for the default disposable Waterline
                              runtime. Defaults to php:8.4-cli.
  DW_WV_WATERLINE_BUILT_RUNTIME_IMAGE
                              Tag to assign the default disposable Waterline
                              runtime image. Defaults to a run-scoped local tag.
  DW_WV_WATERLINE_PORT        Host port for the disposable Waterline app. Defaults to a free port.
  DW_WV_WATERLINE_BIND_HOST   Host interface for the Waterline port. Defaults to 127.0.0.1.
  DW_WV_WATERLINE_CONNECT_HOST
                              Hostname used by the probe for the Waterline URL. Defaults to 127.0.0.1.
  DW_WV_WATERLINE_DB_HOST     Required when DW_WV_SERVER_URL points at an external
                              server and DW_WV_WATERLINE_URL is unset. It must name
                              the same MySQL run database used by that server.
  DW_WV_WATERLINE_DB_PORT     External database port. Defaults to DB_PORT or 3306.
  DW_WV_WATERLINE_DB_DATABASE External database name. Defaults to DB_DATABASE or durable_workflow.
  DW_WV_WATERLINE_DB_USERNAME External database user. Defaults to DB_USERNAME or workflow.
  DW_WV_WATERLINE_DB_PASSWORD External database password. Defaults to DB_PASSWORD or workflow.
  DW_WV_WATERLINE_DOCKER_NETWORK
                              Optional Docker network for the disposable Waterline
                              container when attaching to an external server topology.
  DW_WV_SKIP_WATERLINE_SHARD=1
                              Skip automatic Waterline bootstrapping.
  DW_WV_WORKER_POLL_CLIENT_TIMEOUT_SECONDS
                              Client-side timeout for published-worker shard polls.
                              Defaults to DW_WV_WORKER_POLL_TIMEOUT,
                              DW_WORKER_POLL_TIMEOUT, then 2 seconds.
  DW_WV_PUBLISHED_WORKER_SHARD_TIMEOUT_SECONDS
                              Wall-clock timeout for the automatic published
                              PHP/Python worker shard. Defaults to 90 seconds.
  DW_WV_SKIP_PUBLISHED_WORKER_SHARD=1
                              Skip automatic published PHP/Python worker shard generation.
USAGE
}

keep_run_root="${DW_WV_KEEP_RUN_ROOT:-0}"
result_dir="${DW_WV_RESULT_DIR:-}"

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
  command -v "$1" >/dev/null 2>&1
}

is_exact_semver() {
  local version="$1"

  [[ ! "$version" =~ (^|[-.])(latest|current|head|main|master|dev|snapshot|unresolved|placeholder)([-.]|$) ]] \
    && [[ "$version" =~ ^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-((0|[1-9][0-9]*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*)(\.(0|[1-9][0-9]*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*))*))?$ ]]
}

php_version_at_least() {
  local version="$1"
  local min_major="$2"
  local min_minor="$3"
  local min_patch="$4"

  if [[ ! "$version" =~ ^([0-9]+)\.([0-9]+)\.([0-9]+) ]]; then
    return 1
  fi

  local major="${BASH_REMATCH[1]}"
  local minor="${BASH_REMATCH[2]}"
  local patch="${BASH_REMATCH[3]}"
  local current=$((10#$major * 10000 + 10#$minor * 100 + 10#$patch))
  local minimum=$((10#$min_major * 10000 + 10#$min_minor * 100 + 10#$min_patch))

  [[ "$current" -ge "$minimum" ]]
}

free_port() {
  node - <<'NODE'
const net = require('node:net');
const server = net.createServer();
server.listen(0, '127.0.0.1', () => {
  const address = server.address();
  console.log(address.port);
  server.close();
});
NODE
}

default_route_gateway() {
  python3 - <<'PY' 2>/dev/null || true
from __future__ import annotations

import socket

try:
    with open("/proc/net/route", "r", encoding="utf-8") as route_file:
        next(route_file, None)
        for line in route_file:
            fields = line.strip().split()
            if len(fields) >= 3 and fields[1] == "00000000":
                print(socket.inet_ntoa(bytes.fromhex(fields[2])[::-1]))
                break
except OSError:
    pass
PY
}

docker_bridge_gateway() {
  docker network inspect bridge --format '{{(index .IPAM.Config 0).Gateway}}' 2>/dev/null || true
}

docker_host_from_env() {
  local value="${DOCKER_HOST:-}"

  [[ -n "$value" ]] || return 0
  case "$value" in
    tcp://*|http://*|https://*)
      value="${value#tcp://}"
      value="${value#http://}"
      value="${value#https://}"
      value="${value%%/*}"
      value="${value%:*}"
      ;;
    *)
      return 0
      ;;
  esac

  if [[ -n "$value" && "$value" != "127.0.0.1" && "$value" != "localhost" ]]; then
    printf '%s\n' "$value"
  fi

  return 0
}

wait_for_server_namespace_setup() {
  local namespace="$1"
  local token="$2"
  local timeout_seconds="$3"
  local resolved_url_path="$4"
  shift 4

  node - "$namespace" "$token" "$timeout_seconds" "${DW_WV_BOOTSTRAP_NAMESPACE:-default}" "$resolved_url_path" "$@" <<'NODE'
const fs = require('node:fs');
const path = require('node:path');

const namespace = process.argv[2];
const token = process.argv[3];
const timeoutSeconds = Number.parseInt(process.argv[4] ?? '120', 10);
const bootstrapNamespace = process.argv[5] || 'default';
const resolvedUrlPath = process.argv[6];
const baseUrls = orderedUnique(process.argv.slice(7).map((value) => value.replace(/\/+$/, '')));
const namespacePath = `/api/namespaces/${encodeURIComponent(namespace)}`;
const deadline = Date.now() + Math.max(1, Number.isFinite(timeoutSeconds) ? timeoutSeconds : 120) * 1000;
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const lastErrors = new Map();

function controlHeaders(currentNamespace) {
  return {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    Authorization: `Bearer ${token}`,
    'X-Namespace': currentNamespace,
    'X-Durable-Workflow-Control-Plane-Version': '2',
  };
}

async function requestJson(baseUrl, method, pathName, headers, body, expectedStatuses) {
  const url = `${baseUrl}${pathName}`;
  let response;
  try {
    response = await fetch(url, {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
    });
  } catch (error) {
    lastErrors.set(baseUrl, formatFetchFailure(method, url, error));
    return null;
  }

  const text = await response.text();
  if (!expectedStatuses.includes(response.status)) {
    lastErrors.set(baseUrl, `${method} ${url} returned ${response.status}: ${text.slice(0, 500)}`);
    return null;
  }

  if (text.trim() === '') {
    return { __http_status: response.status };
  }

  try {
    const json = JSON.parse(text);
    if (json && typeof json === 'object' && !Array.isArray(json)) {
      json.__http_status = response.status;
    }
    return json;
  } catch {
    return { __http_status: response.status, raw_body: text };
  }
}

function formatFetchFailure(method, url, error) {
  const reason = error instanceof Error ? error.message : String(error);
  const cause = fetchFailureCause(error);
  const details = orderedUnique([reason, cause].filter((value) => value !== ''));

  return `${method} ${url} failed: ${details.join('; ') || 'request failed'}`;
}

function fetchFailureCause(error) {
  const cause = error && typeof error === 'object' ? error.cause : null;
  if (!cause || typeof cause !== 'object') {
    return '';
  }

  if (Array.isArray(cause.errors)) {
    return cause.errors
      .map((nested) => fetchFailureCause({ cause: nested }) || errorMessage(nested))
      .filter(Boolean)
      .join('; ');
  }

  const fields = [
    stringValue(cause.code),
    stringValue(cause.errno),
    stringValue(cause.syscall),
    stringValue(cause.address),
    stringValue(cause.port),
    errorMessage(cause),
  ].filter(Boolean);

  return orderedUnique(fields).join(' ');
}

function errorMessage(error) {
  return error instanceof Error ? error.message : stringValue(error);
}

function stringValue(value) {
  return typeof value === 'string' ? value : String(value ?? '');
}

function orderedUnique(values) {
  const seen = [];
  for (const value of values) {
    const normalized = stringValue(value).trim();
    if (normalized !== '' && !seen.includes(normalized)) {
      seen.push(normalized);
    }
  }

  return seen;
}

async function confirmReachableNamespace(baseUrl) {
  const ready = await requestJson(baseUrl, 'GET', '/api/ready', controlHeaders(bootstrapNamespace), undefined, [200]);
  if (!ready) {
    return false;
  }

  const show = await requestJson(baseUrl, 'GET', namespacePath, controlHeaders(namespace), undefined, [200]);
  if (show?.name !== namespace) {
    lastErrors.set(
      baseUrl,
      `GET ${baseUrl}${namespacePath} did not confirm namespace ${namespace}: ${JSON.stringify(show ?? null).slice(0, 500)}`,
    );
    return false;
  }

  return true;
}

async function persistResolvedUrl(baseUrl) {
  if (!await confirmReachableNamespace(baseUrl)) {
    return false;
  }

  try {
    fs.mkdirSync(path.dirname(resolvedUrlPath), { recursive: true });
    fs.writeFileSync(resolvedUrlPath, `${baseUrl}\n`, 'utf8');
    const persisted = fs.readFileSync(resolvedUrlPath, 'utf8').trim();
    if (persisted !== baseUrl) {
      lastErrors.set(baseUrl, `resolved server URL file ${resolvedUrlPath} persisted ${persisted || '<empty>'}`);
      return false;
    }
  } catch (error) {
    lastErrors.set(baseUrl, `resolved server URL file ${resolvedUrlPath} could not be written: ${errorMessage(error)}`);
    return false;
  }

  return true;
}

(async () => {
  if (baseUrls.length === 0) {
    console.error('published server namespace setup did not receive any URL candidates');
    process.exit(1);
  }

  while (Date.now() <= deadline) {
    for (const baseUrl of baseUrls) {
      const ready = await requestJson(baseUrl, 'GET', '/api/ready', controlHeaders(bootstrapNamespace), undefined, [200]);
      if (!ready) {
        continue;
      }

      const show = await requestJson(baseUrl, 'GET', namespacePath, controlHeaders(namespace), undefined, [200, 404]);
      if (!show) {
        continue;
      }
      if (show.__http_status === 200 && show.name === namespace && await persistResolvedUrl(baseUrl)) {
        console.log(`published server namespace setup prerequisite satisfied at ${baseUrl}${namespacePath}`);
        process.exit(0);
      }

      const created = await requestJson(
        baseUrl,
        'POST',
        '/api/namespaces',
        controlHeaders(bootstrapNamespace),
        {
          name: namespace,
          description: 'Worker-versioning conformance namespace',
          retention_days: 7,
        },
        [201, 409],
      );
      if (created && await persistResolvedUrl(baseUrl)) {
        console.log(`published server namespace setup prerequisite satisfied at ${baseUrl}${namespacePath}`);
        process.exit(0);
      }
    }

    await sleep(1000);
  }

  const expectedUrls = baseUrls.map((baseUrl) => `${baseUrl}${namespacePath}`).join(', ');
  const readyUrls = baseUrls.map((baseUrl) => `${baseUrl}/api/ready`).join(', ');
  const errors = baseUrls
    .map((baseUrl) => `${baseUrl}: ${lastErrors.get(baseUrl) || 'no response before timeout'}`)
    .join(' | ');

  console.error(
    `published server namespace setup did not become reachable before worker-versioning matrix; expected one of ${expectedUrls}; readiness ${readyUrls}; last_errors=${errors || 'none'}`,
  );
  process.exit(1);
})();
NODE
}

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/../.." && pwd)"

run_root="${DW_WV_RUN_ROOT:-}"
if [[ -z "$run_root" ]]; then
  run_root="$(mktemp -d "${TMPDIR:-/tmp}/dw-worker-versioning.XXXXXX")"
fi
mkdir -p "$run_root"

if [[ -z "$result_dir" ]]; then
  result_dir="$run_root"
fi
mkdir -p "$result_dir"

finalize_worker_versioning_record_for_exit() {
  local code="$1"

  if [[ "$code" -eq 0 || ! -f "$result_dir/worker-versioning-result.json" ]]; then
    return
  fi

  if ! command -v node >/dev/null 2>&1; then
    return
  fi

  node --input-type=module - "$result_dir" "$code" <<'NODE' || true
import fs from 'node:fs';
import path from 'node:path';

const resultDir = process.argv[2];
const exitCode = Number.parseInt(process.argv[3] ?? '', 10);
const resultPath = path.join(resultDir, 'worker-versioning-result.json');
const recordPath = path.join(resultDir, 'worker-versioning-record.json');

function readJson(filePath) {
  try {
    const decoded = JSON.parse(fs.readFileSync(filePath, 'utf8'));
    return decoded && typeof decoded === 'object' && !Array.isArray(decoded) ? decoded : {};
  } catch {
    return {};
  }
}

function writeJson(filePath, value) {
  fs.writeFileSync(filePath, `${JSON.stringify(value, null, 2)}\n`, 'utf8');
}

function token(value) {
  return String(value ?? '').trim().toLowerCase();
}

function declaresPass(value) {
  return ['outcome', 'status', 'verdict'].some((field) => (
    ['pass', 'passed', 'success', 'full'].includes(token(value[field]))
  ));
}

function objectValue(value) {
  return value && typeof value === 'object' && !Array.isArray(value) ? value : {};
}

function artifactVersions(result, record) {
  for (const container of [result, record]) {
    for (const field of [
      'published_artifact_versions',
      'publishedArtifactVersions',
      'resolved_artifact_versions',
      'resolvedArtifactVersions',
      'artifactVersions',
      'artifact_versions',
    ]) {
      const value = objectValue(container[field]);
      if (Object.keys(value).length > 0) {
        return value;
      }
    }
  }

  return {};
}

function appendUnique(items, item, key) {
  const values = Array.isArray(items) ? [...items] : [];
  if (item && typeof item === 'object' && !Array.isArray(item)) {
    const identity = item[key];
    if (!values.some((existing) => (
      existing && typeof existing === 'object' && !Array.isArray(existing) && existing[key] === identity
    ))) {
      values.push(item);
    }
  } else if (!values.includes(item)) {
    values.push(item);
  }

  return values;
}

function replacePassAliases(value) {
  for (const field of ['status', 'verdict']) {
    if (['pass', 'passed', 'success', 'full'].includes(token(value[field]))) {
      value[field] = 'non_passing';
    }
  }
}

function recomputeRecordScenarios(record, scenarioResults) {
  const scenarioStatuses = Object.fromEntries(
    Object.entries(scenarioResults).map(([scenarioId, scenario]) => [
      scenarioId,
      objectValue(scenario).status ?? null,
    ]),
  );
  const nonPassScenarios = Object.entries(scenarioStatuses)
    .filter(([, status]) => status !== 'pass')
    .map(([scenarioId]) => scenarioId);
  const reportedScenarios = Object.keys(scenarioResults);

  record.scenario_results = scenarioResults;
  record.scenarioResults = scenarioResults;
  record.scenario_statuses = scenarioStatuses;
  record.scenarioStatuses = scenarioStatuses;
  record.non_pass_scenarios = nonPassScenarios;
  record.nonPassScenarios = nonPassScenarios;
  record.reported_scenarios = reportedScenarios;
  record.reportedScenarios = reportedScenarios;
}

const result = readJson(resultPath);
const record = readJson(recordPath);
if (!declaresPass(result) && !declaresPass(record)) {
  process.exit(0);
}

const now = new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
const summary = `runner_exit_status: worker-versioning conformance runner exited with status ${Number.isFinite(exitCode) ? exitCode : 'unknown'} after writing a passing record`;
const versions = artifactVersions(result, record);
const finding = {
  id: 'worker-versioning-runner-exit-status-mismatch',
  severity: 'P0',
  surface: 'conformance-runner',
  scenario_id: 'runner_exit_status',
  owning_surface: 'conformance_harness',
  diagnostic_surface: 'runner_process_exit_status',
  next_routed_owner: 'conformance_harness',
  artifact_versions: versions,
  observed_behavior: `The runner process exited with status ${Number.isFinite(exitCode) ? exitCode : 'unknown'} while worker-versioning-result.json or worker-versioning-record.json declared outcome=pass.`,
  expected_behavior: 'A worker-versioning conformance record declares outcome=pass only when the final runner process exit status is 0.',
  next_acceptance_criterion: 'make the runner cleanup and shard execution paths exit successfully, or record the concrete failed worker-versioning scenario or infrastructure step as non-passing before returning a non-zero exit',
  summary,
};
const scenarioResult = {
  scenario_id: 'runner_exit_status',
  status: 'fail',
  observed_outputs: {
    runner_exit_status: Number.isFinite(exitCode) ? exitCode : null,
    runner_exit_status_recorded_at: now,
  },
  linked_findings: [finding],
};

result.outcome = 'non_passing';
replacePassAliases(result);
result.runner_blocked = result.runner_blocked === true;
result.runner_exit_status = Number.isFinite(exitCode) ? exitCode : null;
result.runner_exit_status_recorded_at = now;
result.findings = appendUnique(result.findings, finding, 'id');
result.linked_findings = appendUnique(result.linked_findings, finding, 'id');
result.scenario_results = {
  ...objectValue(result.scenario_results),
  runner_exit_status: scenarioResult,
};
result.finding_links = {
  ...objectValue(result.finding_links),
  runner_exit_status: [finding],
};
writeJson(resultPath, result);

record.experiment = record.experiment || 'worker-versioning';
record.outcome = 'non_passing';
replacePassAliases(record);
record.runner_blocked = record.runner_blocked === true;
record.runnerBlocked = record.runner_blocked;
record.artifactVersions = versions;
record.artifact_versions = versions;
record.runner_exit_status = Number.isFinite(exitCode) ? exitCode : null;
record.runnerExitStatus = Number.isFinite(exitCode) ? exitCode : null;
record.runner_exit_status_recorded_at = now;
record.runnerExitStatusRecordedAt = now;
record.findings = appendUnique(record.findings, finding, 'id');
record.structured_findings = appendUnique(record.structured_findings, finding, 'id');
record.structuredFindings = appendUnique(record.structuredFindings, finding, 'id');
record.linkedFindings = appendUnique(record.linkedFindings, finding, 'id');
record.resultPath = resultPath;
recomputeRecordScenarios(record, result.scenario_results);
writeJson(recordPath, record);
NODE
}

run_label="$(printf '%s' "$(basename "$run_root")" | tr '[:upper:]' '[:lower:]' | tr -c 'a-z0-9_-' '-')"
compose_project="dw-worker-versioning-${run_label}"
server_url_override="${DW_WV_SERVER_URL:-}"
server_url="$server_url_override"
server_url_candidates=()
server_started=0
compose_cleanup_needed=0
server_image="${DW_SERVER_IMAGE:-}"
server_artifact_source="published_server_url"
waterline_container=""

docker_compose() {
  local compose_args=(
    -p "$compose_project"
    -f "$repo_root/docker-compose.published.yml"
  )

  if [[ -f "$run_root/waterline-compose.yml" ]]; then
    compose_args+=(-f "$run_root/waterline-compose.yml")
  fi

  docker compose "${compose_args[@]}" "$@"
}

add_server_url_candidate() {
  local candidate="${1%/}"
  local existing

  [[ -n "$candidate" ]] || return 0
  for existing in "${server_url_candidates[@]}"; do
    if [[ "$existing" == "$candidate" ]]; then
      return
    fi
  done
  server_url_candidates+=("$candidate")
}

is_wildcard_host() {
  local host="${1#[}"
  host="${host%]}"

  [[ -z "$host" || "$host" == "0.0.0.0" || "$host" == "::" || "$host" == "*" ]]
}

host_port_url() {
  local host="${1#[}"
  local port="$2"

  host="${host%]}"
  [[ -n "$host" && "$port" =~ ^[0-9]+$ && "$port" -gt 0 ]] || return

  if [[ "$host" == *:* && "$host" != \[* ]]; then
    printf 'http://[%s]:%s\n' "$host" "$port"
  else
    printf 'http://%s:%s\n' "$host" "$port"
  fi
}

add_server_host_port_candidate() {
  local host="$1"
  local port="$2"
  local candidate

  candidate="$(host_port_url "$host" "$port" || true)"
  add_server_url_candidate "$candidate"
}

add_server_url_candidates_for_port() {
  local port="$1"
  local bind_host="${2:-}"
  local gateway
  local docker_host

  [[ "$port" =~ ^[0-9]+$ && "$port" -gt 0 ]] || return 0

  add_server_host_port_candidate "${server_connect_host:-127.0.0.1}" "$port"
  add_server_host_port_candidate "127.0.0.1" "$port"
  add_server_host_port_candidate "localhost" "$port"

  if [[ -n "$bind_host" ]] && ! is_wildcard_host "$bind_host" \
    && [[ "$bind_host" != "127.0.0.1" && "$bind_host" != "localhost" ]]; then
    add_server_host_port_candidate "$bind_host" "$port"
  fi

  for gateway in \
    "${DW_WV_DOCKER_HOST_GATEWAY:-}" \
    "${DOCKER_HOST_GATEWAY:-}" \
    "${HOST_DOCKER_INTERNAL:-}"; do
    add_server_host_port_candidate "$gateway" "$port"
  done

  docker_host="$(docker_host_from_env)"
  if [[ -n "$docker_host" ]]; then
    add_server_host_port_candidate "$docker_host" "$port"
  fi

  gateway="$(default_route_gateway)"
  if [[ -n "$gateway" ]]; then
    add_server_host_port_candidate "$gateway" "$port"
  fi

  gateway="$(docker_bridge_gateway)"
  if [[ -n "$gateway" && "$gateway" != "<no value>" ]]; then
    add_server_host_port_candidate "$gateway" "$port"
  fi

  add_server_host_port_candidate "host.docker.internal" "$port"
  add_server_host_port_candidate "gateway.docker.internal" "$port"
}

build_server_url_candidates() {
  server_url_candidates=()
  if [[ -n "$server_url_override" ]]; then
    add_server_url_candidate "$server_url_override"
    return
  fi

  add_server_url_candidates_for_port "$server_port" "$server_bind_host"
}

capture_server_port_bindings() {
  if [[ "$server_started" != "1" && "$compose_cleanup_needed" != "1" ]]; then
    return
  fi

  docker_compose port server 8080 >"$result_dir/server-compose-port.txt" 2>"$result_dir/server-compose-port.err" || true

  {
    printf '%s\n' 'docker compose port server 8080:'
    if [[ -s "$result_dir/server-compose-port.txt" ]]; then
      cat "$result_dir/server-compose-port.txt"
    fi
    if [[ -s "$result_dir/server-compose-port.err" ]]; then
      cat "$result_dir/server-compose-port.err"
    fi
    printf '\n%s\n' 'docker compose ps server --format json:'
    docker_compose ps server --format json || true
  } >"$result_dir/server-port-bindings.txt" 2>&1
}

capture_compose_state() {
  if [[ "$server_started" != "1" && "$compose_cleanup_needed" != "1" ]]; then
    return
  fi

  capture_server_port_bindings
  docker_compose ps >"$result_dir/docker-compose-ps.log" 2>&1 || true
  docker_compose logs server >"$result_dir/server.log" 2>&1 || true
}

add_compose_port_binding_candidates() {
  local binding
  local host
  local line
  local port

  [[ -s "$result_dir/server-compose-port.txt" ]] || return 0

  while IFS= read -r line; do
    binding="${line%%[[:space:]]*}"
    port="${binding##*:}"
    host="${binding%:*}"
    host="${host#[}"
    host="${host%]}"

    if [[ "$host" == "$binding" || ! "$port" =~ ^[0-9]+$ || "$port" -le 0 ]]; then
      continue
    fi

    add_server_url_candidates_for_port "$port" "$host"
  done <"$result_dir/server-compose-port.txt"
}

write_server_url_candidates() {
  if [[ "${#server_url_candidates[@]}" -gt 0 ]]; then
    printf '%s\n' "${server_url_candidates[@]}" >"$result_dir/server-url-candidates.txt"
  fi
}

refresh_server_url_candidates_from_compose() {
  if [[ "$server_started" != "1" && "$compose_cleanup_needed" != "1" ]]; then
    return
  fi

  capture_server_port_bindings

  if [[ -n "$server_url_override" ]]; then
    build_server_url_candidates
    server_url="${server_url_candidates[0]}"
    write_server_url_candidates
    return
  fi

  server_url_candidates=()
  add_compose_port_binding_candidates
  if [[ "${#server_url_candidates[@]}" -eq 0 ]]; then
    build_server_url_candidates
  fi
  if [[ "${#server_url_candidates[@]}" -gt 0 ]]; then
    server_url="${server_url_candidates[0]}"
  fi
  write_server_url_candidates
}

promote_server_url_candidate() {
  local selected="${1%/}"
  local existing
  local promoted=()

  [[ -n "$selected" ]] || return
  promoted+=("$selected")
  for existing in "${server_url_candidates[@]}"; do
    if [[ "$existing" != "$selected" ]]; then
      promoted+=("$existing")
    fi
  done
  server_url_candidates=("${promoted[@]}")
}

if [[ -n "$server_url_override" ]]; then
  build_server_url_candidates
  server_url="${server_url_candidates[0]}"
  write_server_url_candidates
fi

cleanup() {
  local code=$?
  local cleanup_status=0

  if [[ -n "$waterline_container" ]]; then
    docker logs "$waterline_container" >"$result_dir/waterline.log" 2>&1 || true
    docker rm -f "$waterline_container" >/dev/null 2>&1 || true
  fi

  if [[ "$server_started" == "1" || "$compose_cleanup_needed" == "1" ]]; then
    docker_compose down -v --remove-orphans >/dev/null 2>&1 || true
  fi

  if [[ "$keep_run_root" != "1" && "$code" -eq 0 && "$result_dir" != "$run_root" ]]; then
    if ! rm -rf "$run_root"; then
      cleanup_status=1
    fi
  fi

  if [[ "$code" -ne 0 ]]; then
    finalize_worker_versioning_record_for_exit "$code"
  elif [[ "$cleanup_status" -ne 0 ]]; then
    finalize_worker_versioning_record_for_exit 1
    exit 1
  fi

  exit "$code"
}
trap cleanup EXIT

write_blocked_result() {
  local reason="$1"
  local blocker_kind="${2:-conformance_harness}"
  local expected_urls="${3:-}"
  local server_state="${4:-}"

  DW_WV_BLOCKED_REASON="$reason" \
  DW_WV_BLOCKED_KIND="$blocker_kind" \
  DW_WV_BLOCKED_EXPECTED_SERVER_URLS="$expected_urls" \
  DW_WV_BLOCKED_SERVER_STATE="$server_state" \
  DW_WV_RESULT_DIR="$result_dir" \
  DW_WV_RUN_ROOT="$run_root" \
  DW_WV_REPO_ROOT="$repo_root" \
  DW_WV_SERVER_URL="$server_url" \
  DW_WV_SERVER_ARTIFACT_SOURCE="$server_artifact_source" \
  node "$script_dir/worker-versioning-published-artifacts.mjs"
}

server_state_summary() {
  local summary=""

  if [[ "$server_started" == "1" || "$compose_cleanup_needed" == "1" ]]; then
    capture_compose_state
    summary="$(docker_compose ps server --format json 2>/dev/null | tr '\n' ' ' | sed 's/[[:space:]]\+/ /g' | cut -c1-700 || true)"
    if [[ -z "$summary" ]]; then
      summary="$(docker_compose ps server 2>/dev/null | tr '\n' ' ' | sed 's/[[:space:]]\+/ /g' | cut -c1-700 || true)"
    fi
  fi

  if [[ ! -s "$result_dir/server-port-bindings.txt" ]]; then
    printf '%s\n' 'server port binding evidence is unavailable because the server process/container state is not managed by this runner' >"$result_dir/server-port-bindings.txt"
  fi

  if [[ -z "$summary" ]]; then
    summary='server process/container state is not managed by this runner'
  fi

  printf '%s\n' "$summary"
}

block_server_readiness_prerequisite() {
  local expected_summary="$1"
  local setup_reason="$2"
  local state

  state="$(server_state_summary)"
  write_blocked_result "published server namespace setup did not record a reachable server URL before worker-versioning matrix; expected one of ${expected_summary}; server state: ${state}; ${setup_reason}; see server-namespace-setup.log, server-url-candidates.txt, server-port-bindings.txt, docker-compose-ps.log, and server.log" \
    "server_readiness_topology" \
    "$expected_summary" \
    "$state"
  exit 0
}

verify_server_namespace_setup() {
  local namespace="${DW_WV_NAMESPACE:-worker-versioning-conformance}"
  local token="${DW_WV_AUTH_TOKEN:-dev-token}"
  local timeout_seconds="${DW_WV_SERVER_READINESS_TIMEOUT_SECONDS:-120}"
  local resolved_url_file="$result_dir/server-url-resolved.txt"
  local expected_paths=()
  local expected_summary
  local candidate
  local state

  if [[ "${#server_url_candidates[@]}" -eq 0 && -n "$server_url" ]]; then
    add_server_url_candidate "$server_url"
  fi

  write_server_url_candidates
  rm -f "$resolved_url_file"

  for candidate in "${server_url_candidates[@]}"; do
    expected_paths+=("${candidate%/}/api/namespaces/${namespace}")
  done
  expected_summary="$(IFS=', '; printf '%s' "${expected_paths[*]}")"

  if wait_for_server_namespace_setup "$namespace" "$token" "$timeout_seconds" "$resolved_url_file" "${server_url_candidates[@]}" >"$result_dir/server-namespace-setup.log" 2>&1; then
    if [[ ! -s "$resolved_url_file" ]]; then
      block_server_readiness_prerequisite "$expected_summary" "namespace setup helper exited successfully without a non-empty server-url-resolved.txt"
    fi

    server_url="$(tr -d '\r\n' <"$resolved_url_file" | sed 's/^[[:space:]]*//; s/[[:space:]]*$//')"
    if [[ -z "$server_url" ]]; then
      block_server_readiness_prerequisite "$expected_summary" "namespace setup helper wrote an empty server-url-resolved.txt"
    fi

    promote_server_url_candidate "$server_url"
    write_server_url_candidates
    export DW_WV_SERVER_URL="$server_url"
    printf '%s\n' "${server_url%/}/api/namespaces/${namespace}" >"$result_dir/server-namespace-url.txt"
    return 0
  fi

  state="$(server_state_summary)"
  write_blocked_result "published server namespace setup prerequisite failed before worker-versioning matrix; expected one of ${expected_summary}; server state: ${state}; see server-namespace-setup.log, server-url-candidates.txt, server-port-bindings.txt, docker-compose-ps.log, and server.log" \
    "server_readiness_topology" \
    "$expected_summary" \
    "$state"
  exit 0
}

write_published_worker_exit_status_evidence() {
  local shard_status="$1"
  local timed_out="$2"

  DW_WV_PUBLISHED_WORKER_SHARD_EXIT_STATUS="$shard_status" \
  DW_WV_PUBLISHED_WORKER_SHARD_TIMED_OUT="$timed_out" \
  node --input-type=module - "$script_dir/worker-versioning-published-artifacts.mjs" <<'NODE'
import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const moduleUrl = pathToFileURL(process.argv[2]).href;
const {
  artifactInstallEvidence,
  artifactSourcesFromEnv,
  artifactVersionsFromEnv,
  mergeArtifactSources,
  publishedWorkerShardExitStatusEvidence,
} = await import(moduleUrl);

const outputPath = process.env.DW_WV_PUBLISHED_WORKER_EVIDENCE;
if (!outputPath) {
  throw new Error('DW_WV_PUBLISHED_WORKER_EVIDENCE is required to write worker shard fallback evidence');
}

const status = Number.parseInt(process.env.DW_WV_PUBLISHED_WORKER_SHARD_EXIT_STATUS ?? '', 10);
const timedOut = ['1', 'true', 'yes'].includes(
  String(process.env.DW_WV_PUBLISHED_WORKER_SHARD_TIMED_OUT ?? '').toLowerCase(),
);
const artifactVersions = artifactVersionsFromEnv();
let artifactSources = artifactSourcesFromEnv();
artifactSources = mergeArtifactSources(
  artifactSources,
  artifactInstallEvidence(artifactVersions, artifactSources),
);
const generated = {
  status: Number.isFinite(status) ? status : null,
  signal: timedOut ? 'SIGTERM' : null,
  error: timedOut
    ? { code: 'ETIMEDOUT', message: 'published worker shard exceeded shell timeout before emitting evidence' }
    : null,
};
let supplied = null;
if (fs.existsSync(outputPath)) {
  try {
    supplied = JSON.parse(fs.readFileSync(outputPath, 'utf8'));
  } catch {
    supplied = null;
  }
}

fs.mkdirSync(path.dirname(outputPath), { recursive: true });
fs.writeFileSync(
  outputPath,
  `${JSON.stringify(publishedWorkerShardExitStatusEvidence(
    generated,
    artifactVersions,
    artifactSources,
    supplied,
  ), null, 2)}\n`,
  'utf8',
);
NODE
}

run_published_worker_shard() {
  if [[ -z "${DW_WV_PUBLISHED_WORKER_EVIDENCE:-}" ]]; then
    export DW_WV_PUBLISHED_WORKER_EVIDENCE="$result_dir/published-worker-execution-evidence.json"
  fi

  if [[ "${DW_WV_SKIP_PUBLISHED_WORKER_SHARD:-0}" != "1" ]]; then
    if require_command timeout; then
      shard_timeout_seconds="${DW_WV_PUBLISHED_WORKER_SHARD_TIMEOUT_SECONDS:-90}"
      shard_status=0
      timeout "${shard_timeout_seconds}s" node "$script_dir/worker-versioning-published-workers.mjs" >"$result_dir/published-worker-shard-direct.log" 2>&1 || shard_status=$?
      if [[ "$shard_status" -ne 0 ]]; then
        printf 'published worker shard did not complete during direct shell handoff; aggregating available evidence\n' >>"$result_dir/published-worker-shard-direct.log"
        shard_timed_out=0
        if [[ "$shard_status" -eq 124 || "$shard_status" -eq 137 ]]; then
          shard_timed_out=1
        fi
        write_published_worker_exit_status_evidence "$shard_status" "$shard_timed_out"
      fi

      export DW_WV_SKIP_PUBLISHED_WORKER_SHARD=1
    fi
  fi
}

wait_for_waterline() {
  local url="$1"

  node - <<'NODE' "$url"
const baseUrl = process.argv[2].replace(/\/+$/, '');
const readyUrl = `${baseUrl}/waterline/api/v2/health`;
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

(async () => {
  for (let attempt = 0; attempt < 120; attempt += 1) {
    try {
      const response = await fetch(readyUrl, {
        headers: {
          Accept: 'application/json',
          'X-Durable-Workflow-Control-Plane-Version': '2',
        },
      });
      if (response.status >= 200 && response.status < 600) {
        process.exit(0);
      }
    } catch {
    }

    await sleep(1000);
  }

  console.error(`published Waterline did not become reachable at ${readyUrl}`);
  process.exit(1);
})();
NODE
}

if ! require_command node; then
  printf '%s\n' 'required command not found: node' >&2
  exit 127
fi

if [[ -z "$server_url" ]]; then
  if ! require_command docker; then
    write_blocked_result 'worker-versioning conformance runner requires docker unless DW_WV_SERVER_URL points at an already running published server'
    exit 0
  fi

  if ! docker compose version >/dev/null 2>&1; then
    write_blocked_result 'worker-versioning conformance runner requires docker compose to start the published server topology'
    exit 0
  fi

  server_port="${DW_WV_SERVER_PORT:-$(free_port)}"
  server_bind_host="${DW_WV_SERVER_BIND_HOST:-0.0.0.0}"
  server_connect_host="${DW_WV_SERVER_CONNECT_HOST:-127.0.0.1}"
  build_server_url_candidates
  server_url="${server_url_candidates[0]}"
  write_server_url_candidates
  compose_server_port="${server_port}"
  if [[ -n "$server_bind_host" ]]; then
    compose_server_port="${server_bind_host}:${server_port}"
  fi
  export SERVER_PORT="$compose_server_port"
  if [[ -z "$server_image" ]]; then
    if [[ -z "${DW_SERVER_VERSION:-}" ]]; then
      write_blocked_result 'DW_SERVER_VERSION or DW_SERVER_IMAGE is required so worker-versioning conformance can run an exact published server artifact'
      exit 0
    fi
    server_image="durableworkflow/server:${DW_SERVER_VERSION}"
  fi

  if [[ "$server_image" == *@sha256:* && -z "${DW_SERVER_VERSION:-}" ]]; then
    write_blocked_result 'DW_SERVER_VERSION is required when DW_SERVER_IMAGE is digest-pinned so the run record carries a concrete server artifact version'
    exit 0
  fi

  if [[ "$server_image" != *@sha256:* ]]; then
    image_tag="${server_image##*:}"
    if [[ "$image_tag" == "$server_image" ]] || ! is_exact_semver "$image_tag"; then
      write_blocked_result "DW_SERVER_IMAGE must use an exact SemVer tag or an image digest; got ${server_image}"
      exit 0
    fi
    if [[ -n "${DW_SERVER_VERSION:-}" && "${DW_SERVER_VERSION}" != "$image_tag" ]]; then
      write_blocked_result "DW_SERVER_VERSION ${DW_SERVER_VERSION} does not match DW_SERVER_IMAGE tag ${image_tag}"
      exit 0
    fi
    export DW_SERVER_VERSION="${DW_SERVER_VERSION:-$image_tag}"
  fi
  server_artifact_source="docker"
  compose_cleanup_needed=1

  if ! docker image pull "$server_image" >"$result_dir/docker-image-pull.log" 2>&1; then
    write_blocked_result "published server image pull failed for ${server_image}; see docker-image-pull.log"
    exit 0
  fi

  docker image inspect "$server_image" >"$result_dir/docker-image-inspect.json" 2>&1 || true

  if ! DW_SERVER_IMAGE="$server_image" \
    DW_SERVER_TAG="${DW_SERVER_VERSION:-}" \
    DW_AUTH_TOKEN="${DW_WV_AUTH_TOKEN:-dev-token}" \
    DW_WORKER_POLL_TIMEOUT="${DW_WV_WORKER_POLL_TIMEOUT:-1}" \
    DW_WORKER_POLL_INTERVAL_MS="${DW_WV_WORKER_POLL_INTERVAL_MS:-100}" \
    docker_compose up -d server >"$result_dir/docker-compose-up.log" 2>&1; then
    write_blocked_result "published server failed to start from ${server_image}; see docker-compose-up.log"
    exit 0
  fi
  server_started=1
  refresh_server_url_candidates_from_compose
  capture_compose_state

  verify_server_namespace_setup
fi

export DW_WV_SERVER_URL="$server_url"
export DW_WV_SERVER_ARTIFACT_SOURCE="$server_artifact_source"
export DW_WV_RESULT_DIR="$result_dir"
export DW_WV_RUN_ROOT="$run_root"
export DW_WV_REPO_ROOT="$repo_root"

if [[ -n "${DW_WV_BLOCKED_REASON:-}" ]]; then
  run_published_worker_shard
  node "$script_dir/worker-versioning-published-artifacts.mjs"
  exit 0
fi

if [[ -z "${DW_WV_WATERLINE_URL:-}" ]]; then
  if [[ "${DW_WV_SKIP_WATERLINE_SHARD:-0}" == "1" ]]; then
    write_blocked_result 'DW_WV_SKIP_WATERLINE_SHARD=1 was set without DW_WV_WATERLINE_URL; provide a Packagist-installed Waterline URL for the same worker-versioning topology or allow the runner to boot Waterline'
    exit 0
  fi

  workflow_php_version="${DW_WORKFLOW_PHP_VERSION:-${DW_WORKFLOW_VERSION:-}}"
  php_sdk_version="${DW_PHP_SDK_VERSION:-}"
  if [[ -z "${DW_WATERLINE_VERSION:-}" || -z "$workflow_php_version" || -z "$php_sdk_version" ]]; then
    write_blocked_result 'DW_WATERLINE_VERSION, DW_WORKFLOW_PHP_VERSION, and DW_PHP_SDK_VERSION are required to boot the published Waterline visibility shard'
    exit 0
  fi

  waterline_db_host="${DW_WV_WATERLINE_DB_HOST:-${DW_WATERLINE_DB_HOST:-}}"
  if [[ "$server_started" != "1" && -z "$waterline_db_host" ]]; then
    write_blocked_result 'DW_WV_SERVER_URL was provided without DW_WV_WATERLINE_URL or DW_WV_WATERLINE_DB_HOST; the runner cannot attach published Waterline to the same worker-versioning run database'
    exit 0
  fi

  if ! require_command docker; then
    write_blocked_result 'worker-versioning Waterline visibility requires docker unless DW_WV_WATERLINE_URL points at an already running published Waterline app'
    exit 0
  fi

  waterline_bind_host="${DW_WV_WATERLINE_BIND_HOST:-127.0.0.1}"
  waterline_connect_host="${DW_WV_WATERLINE_CONNECT_HOST:-127.0.0.1}"
  waterline_port="${DW_WV_WATERLINE_PORT:-$(free_port)}"
  waterline_url="http://${waterline_connect_host}:${waterline_port}"
  waterline_runtime_image="${DW_WV_WATERLINE_RUNTIME_IMAGE:-}"
  if [[ -z "$waterline_runtime_image" ]]; then
    waterline_php_base_image="${DW_WV_WATERLINE_PHP_BASE_IMAGE:-php:8.4-cli}"
    waterline_runtime_image="${DW_WV_WATERLINE_BUILT_RUNTIME_IMAGE:-${compose_project}-waterline-runtime:php84}"
    mkdir -p "$run_root/waterline-runtime"
    cat > "$run_root/waterline-runtime/Dockerfile" <<'DOCKERFILE'
ARG PHP_BASE_IMAGE=php:8.4-cli
FROM ${PHP_BASE_IMAGE}
RUN docker-php-ext-install pdo_mysql
DOCKERFILE

    if ! docker build \
      --pull \
      --build-arg "PHP_BASE_IMAGE=${waterline_php_base_image}" \
      -t "$waterline_runtime_image" \
      "$run_root/waterline-runtime" \
      >"$result_dir/waterline-runtime-build.log" 2>&1; then
      write_blocked_result "published Waterline default PHP runtime could not be built from ${waterline_php_base_image} with pdo_mysql; see waterline-runtime-build.log"
      exit 0
    fi
    printf '%s\n' "$waterline_runtime_image" >"$result_dir/waterline-runtime-image.txt"
  fi
  if ! docker run --rm --entrypoint php "$waterline_runtime_image" -r 'echo PHP_VERSION, PHP_EOL;' >"$result_dir/waterline-runtime-php-version.txt" 2>&1; then
    write_blocked_result "published Waterline runtime image ${waterline_runtime_image} could not report PHP_VERSION; see waterline-runtime-php-version.txt"
    exit 0
  fi
  waterline_php_version="$(tr -d '\r\n' <"$result_dir/waterline-runtime-php-version.txt")"
  if ! php_version_at_least "$waterline_php_version" 8 4 1; then
    write_blocked_result "published Waterline runtime image ${waterline_runtime_image} reports PHP ${waterline_php_version}; durable-workflow/waterline ${DW_WATERLINE_VERSION} requires PHP >= 8.4.1"
    exit 0
  fi
  if ! docker run --rm --entrypoint php "$waterline_runtime_image" -m >"$result_dir/waterline-runtime-php-modules.txt" 2>&1; then
    write_blocked_result "published Waterline runtime image ${waterline_runtime_image} could not report PHP modules; see waterline-runtime-php-modules.txt"
    exit 0
  fi
  if ! grep -qi '^pdo_mysql$' "$result_dir/waterline-runtime-php-modules.txt"; then
    write_blocked_result "published Waterline runtime image ${waterline_runtime_image} does not provide pdo_mysql for the shared MySQL run database; see waterline-runtime-php-modules.txt"
    exit 0
  fi

  mkdir -p "$run_root/waterline-app"
  if ! docker run --rm -v "$run_root/waterline-app:/app" composer:2 sh -lc "
    composer create-project --no-interaction --no-progress laravel/laravel . &&
    composer require --no-interaction --no-progress \
      'durable-workflow/waterline:${DW_WATERLINE_VERSION}@beta' \
      'durable-workflow/workflow:${workflow_php_version}@beta' \
      'durable-workflow/sdk:${php_sdk_version}@beta'
  " > "$result_dir/waterline-install.log" 2>&1; then
    write_blocked_result "published Waterline app install failed for durable-workflow/waterline ${DW_WATERLINE_VERSION} with workflow ${workflow_php_version} and PHP SDK ${php_sdk_version}; see waterline-install.log"
    exit 0
  fi

  if [[ "$server_started" == "1" ]]; then
    cat > "$run_root/waterline-compose.yml" <<YAML
services:
  waterline:
    image: "${waterline_runtime_image}"
    entrypoint: []
    working_dir: /app
    command: ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8090"]
    environment:
      APP_ENV: local
      APP_DEBUG: "false"
      APP_KEY: "base64:UTyp33UhGolgzCK5CJmT+hNHcA+dJyp3+oINtX+VoPI="
      APP_URL: "http://localhost:${waterline_port}"
      DB_CONNECTION: mysql
      DB_HOST: mysql
      DB_PORT: 3306
      DB_DATABASE: ${DB_DATABASE:-durable_workflow}
      DB_USERNAME: ${DB_USERNAME:-workflow}
      DB_PASSWORD: ${DB_PASSWORD:-workflow}
      QUEUE_CONNECTION: sync
      CACHE_STORE: array
      SESSION_DRIVER: array
      WATERLINE_ALLOW_UNAUTHENTICATED: "true"
      WATERLINE_ENGINE_SOURCE: v2
      WATERLINE_HEALTH_TASK_DISPATCH_MODE: poll
      WATERLINE_NAMESPACE: ${DW_WV_NAMESPACE:-worker-versioning-conformance}
      DW_V2_TASK_DISPATCH_MODE: poll
    ports:
      - "${waterline_bind_host}:${waterline_port}:8090"
    volumes:
      - "$run_root/waterline-app:/app"
    depends_on:
      server:
        condition: service_healthy
      mysql:
        condition: service_healthy
YAML

    if ! docker_compose up -d waterline >"$result_dir/waterline-compose-up.log" 2>&1; then
      write_blocked_result "published Waterline app failed to start; see waterline-compose-up.log"
      exit 0
    fi
    refresh_server_url_candidates_from_compose
    capture_compose_state
  else
    waterline_db_port="${DW_WV_WATERLINE_DB_PORT:-${DB_PORT:-3306}}"
    waterline_db_database="${DW_WV_WATERLINE_DB_DATABASE:-${DB_DATABASE:-durable_workflow}}"
    waterline_db_username="${DW_WV_WATERLINE_DB_USERNAME:-${DB_USERNAME:-workflow}}"
    waterline_db_password="${DW_WV_WATERLINE_DB_PASSWORD:-${DB_PASSWORD:-workflow}}"
    waterline_docker_network="${DW_WV_WATERLINE_DOCKER_NETWORK:-}"
    waterline_container="dw-worker-versioning-waterline-${run_label}"
    network_args=()
    if [[ -n "$waterline_docker_network" ]]; then
      network_args=(--network "$waterline_docker_network")
    fi

    if ! docker run -d \
      --name "$waterline_container" \
      --add-host=host.docker.internal:host-gateway \
      "${network_args[@]}" \
      -p "${waterline_bind_host}:${waterline_port}:8090" \
      -v "$run_root/waterline-app:/app" \
      -w /app \
      -e APP_ENV=local \
      -e APP_DEBUG=false \
      -e APP_KEY="base64:UTyp33UhGolgzCK5CJmT+hNHcA+dJyp3+oINtX+VoPI=" \
      -e APP_URL="http://localhost:${waterline_port}" \
      -e DB_CONNECTION=mysql \
      -e DB_HOST="$waterline_db_host" \
      -e DB_PORT="$waterline_db_port" \
      -e DB_DATABASE="$waterline_db_database" \
      -e DB_USERNAME="$waterline_db_username" \
      -e DB_PASSWORD="$waterline_db_password" \
      -e QUEUE_CONNECTION=sync \
      -e CACHE_STORE=array \
      -e SESSION_DRIVER=array \
      -e WATERLINE_ALLOW_UNAUTHENTICATED=true \
      -e WATERLINE_ENGINE_SOURCE=v2 \
      -e WATERLINE_HEALTH_TASK_DISPATCH_MODE=poll \
      -e WATERLINE_NAMESPACE="${DW_WV_NAMESPACE:-worker-versioning-conformance}" \
      -e DW_V2_TASK_DISPATCH_MODE=poll \
      "$waterline_runtime_image" \
      php artisan serve --host=0.0.0.0 --port=8090 \
      >"$result_dir/waterline-container-id.txt" 2>"$result_dir/waterline-docker-run.log"; then
      write_blocked_result "published Waterline app failed to start against external database host ${waterline_db_host}; see waterline-docker-run.log"
      exit 0
    fi
  fi

  if ! wait_for_waterline "$waterline_url"; then
    if [[ "$server_started" == "1" ]]; then
      docker_compose logs waterline > "$result_dir/waterline.log" 2>&1 || true
    elif [[ -n "$waterline_container" ]]; then
      docker logs "$waterline_container" >"$result_dir/waterline.log" 2>&1 || true
    fi
    if [[ "$server_started" == "1" ]]; then
      write_blocked_result "published Waterline app was installed but did not become reachable at ${waterline_url}; see waterline.log"
    else
      write_blocked_result "published Waterline app was installed but did not become reachable at ${waterline_url} while attached to external database host ${waterline_db_host}; see waterline.log"
    fi
    exit 0
  fi

  export DW_WV_WATERLINE_URL="$waterline_url"
  export DW_WATERLINE_ARTIFACT_SOURCE="packagist://durable-workflow/waterline@${DW_WATERLINE_VERSION}"
  printf '%s\n' "$waterline_url" > "$result_dir/waterline-url.txt"
fi

refresh_server_url_candidates_from_compose
capture_compose_state
verify_server_namespace_setup
export DW_WV_SERVER_URL="$server_url"
run_published_worker_shard

node "$script_dir/worker-versioning-published-artifacts.mjs"
