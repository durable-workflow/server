#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage: skew-published-artifacts.sh [--result-dir DIR|--result-dir=DIR] [--keep-run-root[=1|true]]

Runs the public skew-refusal matrix contract against published artifacts only.

The runner writes these files to the result directory:
  pins.json
  run-metadata.json
  skew-result.json
  skew-record.json
  request-response-captures.json

Environment overrides:
  DW_SKEW_RUN_ROOT             Scratch directory. Defaults to mktemp.
  DW_SKEW_RESULT_DIR           Result directory. Defaults to run root.
  DW_SKEW_KEEP_RUN_ROOT=1      Keep scratch directory after success.
  DW_SKEW_SERVER_URL           Existing published server URL to probe; disables compose startup.
  DW_SERVER_IMAGE              Exact server image/tag/digest to test.
  DW_SERVER_VERSION            Exact published server version under test.
  DW_CLI_VERSION               Published CLI version under test.
  DW_PYTHON_SDK_VERSION        Published PyPI durable-workflow version under test.
  DW_WORKFLOW_PHP_VERSION      Published durable-workflow/workflow version under test.
  DW_WATERLINE_VERSION         Published Waterline version under test.
  DW_SKEW_WATERLINE_URL        Optional existing Composer-installed Waterline HTTP surface.
                               If unset, the runner starts a disposable Laravel Waterline app.
  DW_SKEW_WATERLINE_PORT       Host port for the disposable Waterline app. Defaults to a free port.
  DW_SKEW_DOCKER_HOST_GATEWAY_NAME
                               Host name Dockerized PHP probes use to reach the recording proxy.
                               Defaults to host.docker.internal with a host-gateway mapping.
  DW_SKEW_SERVER_PORT          Host port for the published server. Defaults to a free port.
  DW_SKEW_AUTH_TOKEN           Token used against the published server. Defaults to dev-token.
  DW_SKEW_NAMESPACE            Namespace used for probes. Defaults to default.
USAGE
}

keep_run_root="${DW_SKEW_KEEP_RUN_ROOT:-0}"
result_dir="${DW_SKEW_RESULT_DIR:-}"

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

is_exact_semver() {
  local version="$1"

  [[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.-]+)?$ ]]
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

wait_for_server() {
  local url="$1"

node - <<'NODE' "$url"
const baseUrl = process.argv[2].replace(/\/+$/, '');
const readyUrl = `${baseUrl}/api/ready`;
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

(async () => {
  for (let attempt = 0; attempt < 120; attempt += 1) {
    try {
      const response = await fetch(readyUrl);
      if (response.ok) {
        process.exit(0);
      }
    } catch {
    }

    await sleep(1000);
  }

  console.error(`published server did not become ready at ${readyUrl}`);
  process.exit(1);
})();
NODE
}

wait_for_waterline() {
  local url="$1"

node - <<'NODE' "$url"
const baseUrl = process.argv[2].replace(/\/+$/, '');
const readyUrl = `${baseUrl}/waterline/api/v2/health`;
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

(async () => {
  for (let attempt = 0; attempt < 90; attempt += 1) {
    try {
      const response = await fetch(readyUrl, {
        headers: {
          Accept: 'application/json',
          'X-Durable-Workflow-Control-Plane-Version': '2',
        },
      });
      if (response.status > 0 && response.status < 500 && response.status !== 404) {
        process.exit(0);
      }
    } catch {
    }

    await sleep(1000);
  }

  console.error(`published Waterline app did not expose ${readyUrl}`);
  process.exit(1);
})();
NODE
}

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/../.." && pwd)"

run_root="${DW_SKEW_RUN_ROOT:-}"
if [[ -z "$run_root" ]]; then
  run_root="$(mktemp -d "${TMPDIR:-/tmp}/dw-skew.XXXXXX")"
fi
mkdir -p "$run_root"

if [[ -z "$result_dir" ]]; then
  result_dir="$run_root"
fi
mkdir -p "$result_dir"

run_label="$(printf '%s' "$(basename "$run_root")" | tr '[:upper:]' '[:lower:]' | tr -c 'a-z0-9_-' '-')"
compose_project="dw-skew-${run_label}"
server_url="${DW_SKEW_SERVER_URL:-}"
server_started=0
compose_cleanup_needed=0
server_artifact_source="published_server_url"
waterline_container=""

cleanup() {
  local code=$?

  if [[ -n "$waterline_container" ]]; then
    docker logs "$waterline_container" >"$result_dir/waterline-serve-container.log" 2>&1 || true
    docker rm -f "$waterline_container" >/dev/null 2>&1 || true
  fi

  if [[ "$server_started" == "1" || "$compose_cleanup_needed" == "1" ]]; then
    docker compose -p "$compose_project" -f "$repo_root/docker-compose.published.yml" down -v >/dev/null 2>&1 || true
  fi

  if [[ "$keep_run_root" != "1" && "$code" -eq 0 && "$result_dir" != "$run_root" ]]; then
    rm -rf "$run_root"
  fi
}
trap cleanup EXIT

write_blocked_result() {
  local reason="$1"

  DW_SKEW_BLOCKED_REASON="$reason" \
  DW_SKEW_RESULT_DIR="$result_dir" \
  DW_SKEW_RUN_ROOT="$run_root" \
  DW_SKEW_REPO_ROOT="$repo_root" \
  node "$script_dir/skew-published-artifacts.mjs"
}

if ! require_command node; then
  printf '%s\n' 'required command not found: node' >&2
  exit 127
fi

if [[ -z "$server_url" ]]; then
  if ! require_command docker; then
    write_blocked_result 'skew conformance runner requires docker unless DW_SKEW_SERVER_URL points at an already running published server'
    exit 0
  fi

  if ! docker compose version >/dev/null 2>&1; then
    write_blocked_result 'skew conformance runner requires docker compose to start the published server topology'
    exit 0
  fi

  server_port="${DW_SKEW_SERVER_PORT:-$(free_port)}"
  server_url="http://127.0.0.1:${server_port}"
  server_image="${DW_SERVER_IMAGE:-}"
  if [[ -z "$server_image" ]]; then
    if [[ -z "${DW_SERVER_VERSION:-}" ]]; then
      write_blocked_result 'DW_SERVER_VERSION or DW_SERVER_IMAGE is required so skew conformance can run an exact published server artifact'
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

  if ! SERVER_PORT="$server_port" \
    DW_SERVER_IMAGE="$server_image" \
    DW_SERVER_TAG="${DW_SERVER_VERSION:-}" \
    DW_AUTH_TOKEN="${DW_SKEW_AUTH_TOKEN:-dev-token}" \
    docker compose -p "$compose_project" -f "$repo_root/docker-compose.published.yml" up -d server worker >"$result_dir/docker-compose-up.log" 2>&1; then
    write_blocked_result "published server failed to start from ${server_image}; see docker-compose-up.log"
    exit 0
  fi
  server_started=1

  if ! wait_for_server "$server_url"; then
    write_blocked_result "published server did not become ready at ${server_url}/api/ready"
    exit 0
  fi

  server_queue_worker_id=""
  for _ in {1..30}; do
    server_queue_worker_id="$(docker compose -p "$compose_project" -f "$repo_root/docker-compose.published.yml" ps -q worker 2>/dev/null || true)"
    if [[ -n "$server_queue_worker_id" ]] \
      && [[ "$(docker inspect -f '{{.State.Running}}' "$server_queue_worker_id" 2>/dev/null || true)" == "true" ]]; then
      break
    fi

    server_queue_worker_id=""
    sleep 1
  done

  if [[ -z "$server_queue_worker_id" ]]; then
    docker compose -p "$compose_project" -f "$repo_root/docker-compose.published.yml" logs worker >"$result_dir/server-queue-worker.log" 2>&1 || true
    write_blocked_result "published server queue worker failed to start; workflow-worker compatible skew evidence requires queue-backed workflow task fixture polling; see server-queue-worker.log"
    exit 0
  fi
fi

artifact_manifest="$run_root/published-artifacts.json"

cli_status="not_covered"
cli_reason="DW_CLI_VERSION is required to install and invoke the published CLI artifact"
cli_executable=""
cli_source="not_installed"
if [[ -n "${DW_CLI_VERSION:-}" ]]; then
  mkdir -p "$run_root/cli/bin"
  cli_reason=""
  cli_installer_url=""
  for candidate_url in \
    "https://github.com/durable-workflow/cli/releases/download/${DW_CLI_VERSION}/install.sh" \
    "https://github.com/durable-workflow/cli/releases/download/v${DW_CLI_VERSION}/install.sh"
  do
    if curl -fsSL --retry 3 -o "$run_root/cli/install.sh" "$candidate_url" >"$result_dir/cli-installer-download.log" 2>&1; then
      cli_installer_url="$candidate_url"
      break
    fi
  done

  if [[ -z "$cli_installer_url" ]]; then
    cli_status="runner_blocked"
    cli_reason="official CLI installer is not downloadable for release ${DW_CLI_VERSION}"
  elif VERSION="$DW_CLI_VERSION" \
    DURABLE_WORKFLOW_INSTALL_DIR="$run_root/cli/bin" \
    DURABLE_WORKFLOW_BIN_NAME=dw \
    sh "$run_root/cli/install.sh" >"$result_dir/cli-install.log" 2>&1 \
    && [[ -x "$run_root/cli/bin/dw" ]]; then
    cli_status="available"
    cli_source="github-release"
    cli_executable="$run_root/cli/bin/dw"
  else
    cli_status="runner_blocked"
    cli_reason="official CLI installer failed for release ${DW_CLI_VERSION}; see cli-install.log"
  fi
fi

python_status="not_covered"
python_reason="DW_PYTHON_SDK_VERSION is required to install and invoke the published Python SDK artifact"
python_executable=""
python_source="not_installed"
if [[ -n "${DW_PYTHON_SDK_VERSION:-}" ]]; then
  if require_command python3; then
    if python3 -m venv "$run_root/.venv" >"$result_dir/python-install.log" 2>&1 \
      && "$run_root/.venv/bin/python" -m pip install --upgrade pip >>"$result_dir/python-install.log" 2>&1 \
      && "$run_root/.venv/bin/python" -m pip install "durable-workflow==${DW_PYTHON_SDK_VERSION}" >>"$result_dir/python-install.log" 2>&1; then
      python_status="available"
      python_reason=""
      python_source="pypi"
      python_executable="$run_root/.venv/bin/python"
    else
      python_status="runner_blocked"
      python_reason="PyPI install failed for durable-workflow==${DW_PYTHON_SDK_VERSION}; see python-install.log"
    fi
  else
    python_status="runner_blocked"
    python_reason="python3 is required to install and invoke durable-workflow from PyPI"
  fi
fi

workflow_status="not_covered"
workflow_reason="DW_WORKFLOW_PHP_VERSION is required to install the published PHP workflow artifact"
workflow_app_dir=""
workflow_source="not_installed"
workflow_version="${DW_WORKFLOW_PHP_VERSION:-${DW_WORKFLOW_VERSION:-}}"
if [[ -n "$workflow_version" ]]; then
  mkdir -p "$run_root/php-worker"
  if ! is_exact_semver "$workflow_version"; then
    workflow_status="runner_blocked"
    workflow_reason="Workflow install requires an exact durable-workflow/workflow version; got ${workflow_version}"
  elif require_command docker; then
    if docker run --rm -v "$run_root/php-worker:/app" composer:2 \
      composer require --no-interaction --no-progress "durable-workflow/workflow:${workflow_version}" >"$result_dir/workflow-composer-install.log" 2>&1; then
      workflow_status="available"
      workflow_reason=""
      workflow_source="packagist"
      workflow_app_dir="$run_root/php-worker"
    else
      workflow_status="runner_blocked"
      workflow_reason="Composer install failed for durable-workflow/workflow:${workflow_version}; see workflow-composer-install.log"
    fi
  else
    workflow_status="runner_blocked"
    workflow_reason="docker is required to install the PHP workflow artifact through composer:2"
  fi
fi

waterline_status="not_covered"
waterline_reason="DW_WATERLINE_VERSION is required to install the published Waterline artifact"
waterline_app_dir=""
waterline_source="not_installed"
waterline_surface_url="${DW_SKEW_WATERLINE_URL:-${DW_SKEW_WATERLINE_BASE_URL:-}}"
if [[ -n "${DW_WATERLINE_VERSION:-}" ]]; then
  mkdir -p "$run_root/waterline"
  if ! is_exact_semver "$DW_WATERLINE_VERSION"; then
    waterline_status="runner_blocked"
    waterline_reason="Waterline install requires an exact durable-workflow/waterline version; got ${DW_WATERLINE_VERSION}"
  elif [[ -z "$workflow_version" ]]; then
    waterline_status="runner_blocked"
    waterline_reason="DW_WORKFLOW_PHP_VERSION or DW_WORKFLOW_VERSION is required as an exact workflow pin before installing Waterline"
  elif ! is_exact_semver "$workflow_version"; then
    waterline_status="runner_blocked"
    waterline_reason="Waterline install requires an exact durable-workflow/workflow version; got ${workflow_version}"
  elif require_command docker; then
    waterline_create_status=0
    waterline_require_status=1
    waterline_key_status=0
    waterline_migrate_status=0
    waterline_serve_status=0

    if [[ -z "$waterline_surface_url" ]]; then
      if docker run --rm -v "$run_root/waterline:/app" -w /app composer:2 \
        composer create-project laravel/laravel . --no-interaction --no-progress \
        >"$result_dir/waterline-create-project.log" 2>&1; then
        waterline_create_status=0
      else
        waterline_create_status=1
      fi
    fi

    if [[ "$waterline_create_status" -eq 0 ]]; then
      mkdir -p "$run_root/waterline/database"
      : > "$run_root/waterline/database/database.sqlite"

      if docker run --rm -v "$run_root/waterline:/app" -w /app composer:2 \
        composer require --no-interaction --no-progress \
          "durable-workflow/workflow:${workflow_version}" \
          "durable-workflow/waterline:${DW_WATERLINE_VERSION}" >"$result_dir/waterline-composer-install.log" 2>&1; then
        waterline_require_status=0
      else
        waterline_require_status=1
      fi
    fi

    if [[ "$waterline_require_status" -eq 0 && -z "$waterline_surface_url" ]]; then
      if docker run --rm \
        -v "$run_root/waterline:/app" \
        -w /app \
        -e APP_ENV=local \
        -e DB_CONNECTION=sqlite \
        -e DB_DATABASE=/app/database/database.sqlite \
        -e WATERLINE_ENGINE_SOURCE=v2 \
        -e WATERLINE_ALLOW_UNAUTHENTICATED=true \
        -e WATERLINE_NAMESPACE="${DW_SKEW_NAMESPACE:-default}" \
        composer:2 php artisan key:generate --force \
        >"$result_dir/waterline-key-generate.log" 2>&1; then
        waterline_key_status=0
      else
        waterline_key_status=1
      fi
    fi

    if [[ "$waterline_key_status" -eq 0 && -z "$waterline_surface_url" ]]; then
      if docker run --rm \
        -v "$run_root/waterline:/app" \
        -w /app \
        -e APP_ENV=local \
        -e DB_CONNECTION=sqlite \
        -e DB_DATABASE=/app/database/database.sqlite \
        -e WATERLINE_ENGINE_SOURCE=v2 \
        -e WATERLINE_ALLOW_UNAUTHENTICATED=true \
        -e WATERLINE_NAMESPACE="${DW_SKEW_NAMESPACE:-default}" \
        composer:2 php artisan migrate --force \
        >"$result_dir/waterline-migrate.log" 2>&1; then
        waterline_migrate_status=0
      else
        waterline_migrate_status=1
      fi
    fi

    if [[ "$waterline_migrate_status" -eq 0 && -z "$waterline_surface_url" ]]; then
      waterline_port="${DW_SKEW_WATERLINE_PORT:-$(free_port)}"
      waterline_surface_url="http://127.0.0.1:${waterline_port}"
      waterline_container="dw-skew-waterline-${run_label}"
      if docker run -d \
        --name "$waterline_container" \
        -p "127.0.0.1:${waterline_port}:${waterline_port}" \
        -v "$run_root/waterline:/app" \
        -w /app \
        -e APP_ENV=local \
        -e DB_CONNECTION=sqlite \
        -e DB_DATABASE=/app/database/database.sqlite \
        -e WATERLINE_ENGINE_SOURCE=v2 \
        -e WATERLINE_ALLOW_UNAUTHENTICATED=true \
        -e WATERLINE_NAMESPACE="${DW_SKEW_NAMESPACE:-default}" \
        composer:2 php artisan serve --host=0.0.0.0 --port "$waterline_port" \
        >"$result_dir/waterline-serve-container.id" 2>"$result_dir/waterline-serve-start.log"; then
        if wait_for_waterline "$waterline_surface_url" >"$result_dir/waterline-ready.log" 2>&1; then
          waterline_serve_status=0
        else
          waterline_serve_status=1
        fi
      else
        waterline_serve_status=1
      fi
    fi

    if [[ "$waterline_create_status" -ne 0 ]]; then
      waterline_status="runner_blocked"
      waterline_reason="Laravel app creation failed before Waterline skew surface startup; see waterline-create-project.log"
      waterline_surface_url=""
    elif [[ "$waterline_require_status" -ne 0 ]]; then
      waterline_status="runner_blocked"
      waterline_reason="Composer install failed for durable-workflow/waterline:${DW_WATERLINE_VERSION}; see waterline-composer-install.log"
      waterline_surface_url=""
    elif [[ "$waterline_key_status" -ne 0 ]]; then
      waterline_status="runner_blocked"
      waterline_reason="Laravel key generation failed before Waterline skew surface startup; see waterline-key-generate.log"
      waterline_surface_url=""
    elif [[ "$waterline_migrate_status" -ne 0 ]]; then
      waterline_status="runner_blocked"
      waterline_reason="Laravel migration failed before Waterline skew surface startup; see waterline-migrate.log"
      waterline_surface_url=""
    elif [[ "$waterline_serve_status" -ne 0 ]]; then
      waterline_status="runner_blocked"
      waterline_reason="Disposable Waterline app failed to expose /waterline/api/v2/health; see waterline-ready.log and waterline-serve-container.log"
      waterline_surface_url=""
    else
      waterline_status="available"
      waterline_reason=""
      waterline_source="packagist"
      waterline_app_dir="$run_root/waterline"
    fi
  else
    waterline_status="runner_blocked"
    waterline_reason="docker is required to install the Waterline artifact through composer:2"
  fi
fi

SERVER_ARTIFACT_SOURCE="$server_artifact_source" \
CLI_STATUS="$cli_status" \
CLI_REASON="$cli_reason" \
CLI_SOURCE="$cli_source" \
CLI_EXECUTABLE="$cli_executable" \
PYTHON_STATUS="$python_status" \
PYTHON_REASON="$python_reason" \
PYTHON_SOURCE="$python_source" \
PYTHON_EXECUTABLE="$python_executable" \
WORKFLOW_STATUS="$workflow_status" \
WORKFLOW_REASON="$workflow_reason" \
WORKFLOW_SOURCE="$workflow_source" \
WORKFLOW_APP_DIR="$workflow_app_dir" \
WORKFLOW_VERSION="$workflow_version" \
WATERLINE_STATUS="$waterline_status" \
WATERLINE_REASON="$waterline_reason" \
WATERLINE_SOURCE="$waterline_source" \
WATERLINE_APP_DIR="$waterline_app_dir" \
WATERLINE_SURFACE_URL="$waterline_surface_url" \
node - <<'NODE' > "$artifact_manifest"
const env = process.env;
const surface = (status, reason, source, extra = {}) => ({
  status,
  source,
  ...(reason ? { reason } : {}),
  ...Object.fromEntries(Object.entries(extra).filter(([, value]) => value)),
});

const workflowVersion = env.WORKFLOW_VERSION || env.DW_WORKFLOW_PHP_VERSION || env.DW_WORKFLOW_VERSION || '';
const manifest = {
  schema: 'durable-workflow.v2.skew-refusal-matrix.published-artifacts',
  artifact_versions: {
    server: env.DW_SERVER_VERSION || '',
    cli: env.DW_CLI_VERSION || '',
    'sdk-python': env.DW_PYTHON_SDK_VERSION || '',
    workflow: workflowVersion,
    waterline: env.DW_WATERLINE_VERSION || '',
  },
  artifact_sources: {
    server: env.SERVER_ARTIFACT_SOURCE || 'published_server_url',
    cli: env.CLI_SOURCE || 'not_installed',
    'sdk-python': env.PYTHON_SOURCE || 'not_installed',
    workflow: env.WORKFLOW_SOURCE || 'not_installed',
    waterline: env.WATERLINE_SOURCE || 'not_installed',
  },
  surfaces: {
    cli: surface(env.CLI_STATUS, env.CLI_REASON, env.CLI_SOURCE, { executable: env.CLI_EXECUTABLE }),
    'sdk-python': surface(env.PYTHON_STATUS, env.PYTHON_REASON, env.PYTHON_SOURCE, { python: env.PYTHON_EXECUTABLE }),
    workflow: surface(env.WORKFLOW_STATUS, env.WORKFLOW_REASON, env.WORKFLOW_SOURCE, { app_dir: env.WORKFLOW_APP_DIR }),
    waterline: surface(env.WATERLINE_STATUS, env.WATERLINE_REASON, env.WATERLINE_SOURCE, {
      app_dir: env.WATERLINE_APP_DIR,
      surface_url: env.WATERLINE_SURFACE_URL,
    }),
  },
  local_product_source_checkouts_used: false,
};

process.stdout.write(`${JSON.stringify(manifest, null, 2)}\n`);
NODE

DW_SKEW_RESULT_DIR="$result_dir" \
DW_SKEW_RUN_ROOT="$run_root" \
DW_SKEW_REPO_ROOT="$repo_root" \
DW_SKEW_SERVER_URL="$server_url" \
DW_SKEW_ARTIFACTS_JSON="$artifact_manifest" \
DW_SKEW_STARTED_AT="${DW_SKEW_STARTED_AT:-$(timestamp)}" \
node "$script_dir/skew-published-artifacts.mjs"
