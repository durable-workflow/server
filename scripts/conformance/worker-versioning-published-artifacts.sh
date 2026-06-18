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
  DW_WORKFLOW_PHP_VERSION    Published durable-workflow/workflow version under test.
  DW_WATERLINE_VERSION       Published Waterline version under test.
  DW_WV_SERVER_PORT          Host port for the published server. Defaults to a free port.
  DW_WV_AUTH_TOKEN           Token used against the published server. Defaults to dev-token.
  DW_WV_NAMESPACE            Namespace used for probes. Defaults to worker-versioning-conformance.
  DW_WV_ARTIFACT_INSTALL_EVIDENCE
                              Optional JSON report proving CLI, Python SDK, PHP workflow,
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

  [[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.-]+)?$ ]]
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

wait_for_server_namespace_setup() {
  local namespace="$1"
  local token="$2"
  local timeout_seconds="$3"
  local resolved_url_path="$4"
  shift 4

  node - "$namespace" "$token" "$timeout_seconds" "${DW_WV_BOOTSTRAP_NAMESPACE:-default}" "$resolved_url_path" "$@" <<'NODE'
const fs = require('node:fs');

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

function writeResolvedUrl(baseUrl) {
  fs.writeFileSync(resolvedUrlPath, `${baseUrl}\n`, 'utf8');
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
      if (show.__http_status === 200 && show.name === namespace) {
        writeResolvedUrl(baseUrl);
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
      if (created) {
        writeResolvedUrl(baseUrl);
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

add_server_url_candidate() {
  local candidate="${1%/}"
  local existing

  [[ -n "$candidate" ]] || return
  for existing in "${server_url_candidates[@]}"; do
    if [[ "$existing" == "$candidate" ]]; then
      return
    fi
  done
  server_url_candidates+=("$candidate")
}

build_server_url_candidates() {
  local gateway

  server_url_candidates=()
  if [[ -n "$server_url_override" ]]; then
    add_server_url_candidate "$server_url_override"
    return
  fi

  add_server_url_candidate "http://${server_connect_host}:${server_port}"
  add_server_url_candidate "http://127.0.0.1:${server_port}"
  add_server_url_candidate "http://localhost:${server_port}"

  if [[ "$server_bind_host" != "0.0.0.0" && "$server_bind_host" != "127.0.0.1" && "$server_bind_host" != "localhost" ]]; then
    add_server_url_candidate "http://${server_bind_host}:${server_port}"
  fi

  gateway="$(default_route_gateway)"
  if [[ -n "$gateway" ]]; then
    add_server_url_candidate "http://${gateway}:${server_port}"
  fi

  gateway="$(docker_bridge_gateway)"
  if [[ -n "$gateway" && "$gateway" != "<no value>" ]]; then
    add_server_url_candidate "http://${gateway}:${server_port}"
  fi

  add_server_url_candidate "http://host.docker.internal:${server_port}"
}

write_server_url_candidates() {
  if [[ "${#server_url_candidates[@]}" -gt 0 ]]; then
    printf '%s\n' "${server_url_candidates[@]}" >"$result_dir/server-url-candidates.txt"
  fi
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

  if [[ -n "$waterline_container" ]]; then
    docker logs "$waterline_container" >"$result_dir/waterline.log" 2>&1 || true
    docker rm -f "$waterline_container" >/dev/null 2>&1 || true
  fi

  if [[ "$server_started" == "1" || "$compose_cleanup_needed" == "1" ]]; then
    if [[ -f "$run_root/waterline-compose.yml" ]]; then
      docker compose -p "$compose_project" \
        -f "$repo_root/docker-compose.published.yml" \
        -f "$run_root/waterline-compose.yml" \
        down -v --remove-orphans >/dev/null 2>&1 || true
    else
      docker compose -p "$compose_project" -f "$repo_root/docker-compose.published.yml" down -v >/dev/null 2>&1 || true
    fi
  fi

  if [[ "$keep_run_root" != "1" && "$code" -eq 0 && "$result_dir" != "$run_root" ]]; then
    rm -rf "$run_root"
  fi
}
trap cleanup EXIT

write_blocked_result() {
  local reason="$1"

  DW_WV_BLOCKED_REASON="$reason" \
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
    docker compose -p "$compose_project" -f "$repo_root/docker-compose.published.yml" ps >"$result_dir/docker-compose-ps.log" 2>&1 || true
    docker compose -p "$compose_project" -f "$repo_root/docker-compose.published.yml" logs server >"$result_dir/server.log" 2>&1 || true
    summary="$(docker compose -p "$compose_project" -f "$repo_root/docker-compose.published.yml" ps server --format json 2>/dev/null | tr '\n' ' ' | sed 's/[[:space:]]\+/ /g' | cut -c1-700 || true)"
    if [[ -z "$summary" ]]; then
      summary="$(docker compose -p "$compose_project" -f "$repo_root/docker-compose.published.yml" ps server 2>/dev/null | tr '\n' ' ' | sed 's/[[:space:]]\+/ /g' | cut -c1-700 || true)"
    fi
  fi

  if [[ -z "$summary" ]]; then
    summary='server process/container state is not managed by this runner'
  fi

  printf '%s\n' "$summary"
}

block_missing_resolved_server_url() {
  local expected_summary="$1"
  local state

  state="$(server_state_summary)"
  write_blocked_result "published server namespace setup returned success without writing a non-empty server-url-resolved.txt before worker-versioning matrix; expected one of ${expected_summary}; server state: ${state}; see server-namespace-setup.log, server-url-candidates.txt, docker-compose-ps.log, and server.log"
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
      block_missing_resolved_server_url "$expected_summary"
    fi

    server_url="$(tr -d '\r\n' <"$resolved_url_file" | sed 's/^[[:space:]]*//; s/[[:space:]]*$//')"
    if [[ -z "$server_url" ]]; then
      block_missing_resolved_server_url "$expected_summary"
    fi

    promote_server_url_candidate "$server_url"
    write_server_url_candidates
    export DW_WV_SERVER_URL="$server_url"
    printf '%s\n' "${server_url%/}/api/namespaces/${namespace}" >"$result_dir/server-namespace-url.txt"
    return 0
  fi

  state="$(server_state_summary)"
  write_blocked_result "published server namespace setup prerequisite failed before worker-versioning matrix; expected one of ${expected_summary}; server state: ${state}; see server-namespace-setup.log, server-url-candidates.txt, docker-compose-ps.log, and server.log"
  exit 0
}

write_published_worker_fallback_evidence() {
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
  publishedWorkerShardFallbackEvidence,
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

fs.mkdirSync(path.dirname(outputPath), { recursive: true });
fs.writeFileSync(
  outputPath,
  `${JSON.stringify(publishedWorkerShardFallbackEvidence(
    generated,
    artifactVersions,
    artifactSources,
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
        if [[ ! -s "${DW_WV_PUBLISHED_WORKER_EVIDENCE:-}" ]]; then
          shard_timed_out=0
          if [[ "$shard_status" -eq 124 || "$shard_status" -eq 137 ]]; then
            shard_timed_out=1
          fi
          write_published_worker_fallback_evidence "$shard_status" "$shard_timed_out"
        fi
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
      write_blocked_result "DW_SERVER_IMAGE must use an exact patch semver tag or an image digest; got ${server_image}"
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

  if ! SERVER_PORT="$compose_server_port" \
    DW_SERVER_IMAGE="$server_image" \
    DW_SERVER_TAG="${DW_SERVER_VERSION:-}" \
    DW_AUTH_TOKEN="${DW_WV_AUTH_TOKEN:-dev-token}" \
    DW_WORKER_POLL_TIMEOUT="${DW_WV_WORKER_POLL_TIMEOUT:-1}" \
    DW_WORKER_POLL_INTERVAL_MS="${DW_WV_WORKER_POLL_INTERVAL_MS:-100}" \
    docker compose -p "$compose_project" -f "$repo_root/docker-compose.published.yml" up -d server >"$result_dir/docker-compose-up.log" 2>&1; then
    write_blocked_result "published server failed to start from ${server_image}; see docker-compose-up.log"
    exit 0
  fi
  server_started=1

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
  if [[ -z "${DW_WATERLINE_VERSION:-}" || -z "$workflow_php_version" ]]; then
    write_blocked_result 'DW_WATERLINE_VERSION and DW_WORKFLOW_PHP_VERSION are required to boot the published Waterline visibility shard'
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
      'durable-workflow/workflow:$workflow_php_version' \
      'durable-workflow/waterline:${DW_WATERLINE_VERSION}'
  " > "$result_dir/waterline-install.log" 2>&1; then
    write_blocked_result "published Waterline app install failed for durable-workflow/waterline ${DW_WATERLINE_VERSION} with workflow ${workflow_php_version}; see waterline-install.log"
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

    if ! docker compose -p "$compose_project" \
      -f "$repo_root/docker-compose.published.yml" \
      -f "$run_root/waterline-compose.yml" \
      up -d waterline >"$result_dir/waterline-compose-up.log" 2>&1; then
      write_blocked_result "published Waterline app failed to start; see waterline-compose-up.log"
      exit 0
    fi
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
      docker compose -p "$compose_project" \
        -f "$repo_root/docker-compose.published.yml" \
        -f "$run_root/waterline-compose.yml" \
        logs waterline > "$result_dir/waterline.log" 2>&1 || true
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

verify_server_namespace_setup
export DW_WV_SERVER_URL="$server_url"
run_published_worker_shard

node "$script_dir/worker-versioning-published-artifacts.mjs"
