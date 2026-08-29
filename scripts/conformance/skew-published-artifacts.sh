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
  DW_PHP_SDK_VERSION           Exact published durable-workflow/sdk version under test.
  DW_WORKFLOW_PHP_VERSION      Published durable-workflow/workflow version under test.
  DW_WATERLINE_VERSION         Published Waterline version under test.
  DW_SKEW_WATERLINE_URL        Optional existing Composer-installed Waterline HTTP surface.
                               If unset, the runner starts a disposable Laravel Waterline app.
  DW_SKEW_WATERLINE_HOST       Hostname the runner should use for the disposable Waterline app.
                               Defaults to 127.0.0.1 on the host, or the published server host
                               when the runner itself is containerized.
  DW_SKEW_WATERLINE_BIND_HOST  Host interface for the disposable Waterline port publish.
                               Defaults to 127.0.0.1 on the host, or 0.0.0.0 when containerized.
  DW_SKEW_WATERLINE_FIXTURE_RUN_ID
                               Existing Waterline run id to render, or the seeded fixture id.
  DW_SKEW_WATERLINE_PORT       Host port for the disposable Waterline app. Defaults to a free port.
  DW_SKEW_PHP_CONTAINER_NETWORK_TARGET
                               Container id/name whose network namespace PHP probes may share.
                               Defaults to the runner container hostname when containerized.
  DW_SKEW_DISABLE_PHP_CONTAINER_NETWORK=1
                               Disable the container-network PHP probe strategy.
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

  [[ ! "$version" =~ (^|[-.])(latest|current|head|main|master|dev|snapshot|unresolved|placeholder)([-.]|$) ]] \
    && [[ "$version" =~ ^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-((0|[1-9][0-9]*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*)(\.(0|[1-9][0-9]*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*))*))?$ ]]
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

running_in_container() {
  if [[ -f /.dockerenv ]]; then
    return 0
  fi

  if [[ -r /proc/1/cgroup ]] && grep -qaE '(docker|kubepods|containerd)' /proc/1/cgroup; then
    return 0
  fi

  return 1
}

url_host() {
  local url="$1"

node - "$url" <<'NODE'
try {
  const parsed = new URL(process.argv[2]);
  process.stdout.write(parsed.hostname);
} catch {
  process.exit(1);
}
NODE
}

waterline_host_for_published_port() {
  if [[ -n "${DW_SKEW_WATERLINE_HOST:-}" ]]; then
    printf '%s\n' "$DW_SKEW_WATERLINE_HOST"
    return
  fi

  if running_in_container; then
    local server_host=""
    if [[ -n "$server_url" ]]; then
      server_host="$(url_host "$server_url" 2>/dev/null || true)"
    fi

    if [[ -n "$server_host" && "$server_host" != "127.0.0.1" && "$server_host" != "localhost" ]]; then
      printf '%s\n' "$server_host"
      return
    fi

    printf '%s\n' "${DW_SKEW_DOCKER_HOST_GATEWAY_NAME:-host.docker.internal}"
    return
  fi

  printf '%s\n' '127.0.0.1'
}

waterline_bind_host_for_published_port() {
  if [[ -n "${DW_SKEW_WATERLINE_BIND_HOST:-}" ]]; then
    printf '%s\n' "$DW_SKEW_WATERLINE_BIND_HOST"
    return
  fi

  if running_in_container; then
    printf '%s\n' '0.0.0.0'
    return
  fi

  printf '%s\n' '127.0.0.1'
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
    rm -rf "$run_root" >/dev/null 2>&1 \
      || printf 'warning: unable to remove skew conformance run root %s\n' "$run_root" >&2
  fi

  exit "$code"
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

exit_with_skew_record_status() {
  local runner_status="${1:-1}"
  local record_path="$result_dir/skew-record.json"

  node - "$record_path" "$runner_status" <<'NODE'
const fs = require('node:fs');

const recordPath = process.argv[2];
const parsedFallbackStatus = Number.parseInt(process.argv[3] ?? '', 10);
const fallbackStatus = Number.isInteger(parsedFallbackStatus) ? parsedFallbackStatus : 1;
const fallbackExit = fallbackStatus === 0 ? 1 : fallbackStatus;

function token(value) {
  return String(value ?? '').trim().toLowerCase();
}

function truthy(value) {
  return value === true || ['1', 'true', 'yes'].includes(token(value));
}

let record;
try {
  record = JSON.parse(fs.readFileSync(recordPath, 'utf8'));
} catch (error) {
  console.error(`skew runner did not write a readable skew-record.json: ${error.message}`);
  process.exit(fallbackExit);
}

const nestedRecord = record?.record && typeof record.record === 'object' ? record.record : {};
const outcome = token(record.outcome || record.status || record.verdict || nestedRecord.outcome || nestedRecord.status || nestedRecord.verdict);
const runnerBlocked = truthy(record.runnerBlocked)
  || truthy(record.runner_blocked)
  || truthy(nestedRecord.runnerBlocked)
  || truthy(nestedRecord.runner_blocked);

if (outcome === 'pass' && !runnerBlocked) {
  process.exit(0);
}

process.exit(fallbackStatus);
NODE
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
    write_blocked_result "published server queue worker failed to start; sdk-php compatible skew evidence requires queue-backed workflow task fixture polling; see server-queue-worker.log"
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
  elif PATH="$run_root/cli/bin${PATH:+:$PATH}" \
    VERSION="$DW_CLI_VERSION" \
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

php_sdk_status="not_covered"
php_sdk_reason="DW_PHP_SDK_VERSION is required to install the published PHP SDK artifact"
php_sdk_app_dir=""
php_sdk_source="not_installed"
php_sdk_version="${DW_PHP_SDK_VERSION:-}"
workflow_version="${DW_WORKFLOW_PHP_VERSION:-${DW_WORKFLOW_VERSION:-}}"
if [[ -n "$php_sdk_version" ]]; then
  mkdir -p "$run_root/php-sdk-worker"
  if ! is_exact_semver "$php_sdk_version"; then
    php_sdk_status="runner_blocked"
    php_sdk_reason="PHP SDK install requires an exact durable-workflow/sdk version; got ${php_sdk_version}"
  elif require_command docker; then
    if docker run --rm -v "$run_root/php-sdk-worker:/app" composer:2 \
      composer require --no-interaction --no-progress "durable-workflow/sdk:${php_sdk_version}" >"$result_dir/php-sdk-composer-install.log" 2>&1; then
      php_sdk_status="available"
      php_sdk_reason=""
      php_sdk_source="packagist"
      php_sdk_app_dir="$run_root/php-sdk-worker"
    else
      php_sdk_status="runner_blocked"
      php_sdk_reason="Composer install failed for durable-workflow/sdk:${php_sdk_version}; see php-sdk-composer-install.log"
    fi
  else
    php_sdk_status="runner_blocked"
    php_sdk_reason="docker is required to install the PHP SDK artifact through composer:2"
  fi
fi

waterline_status="not_covered"
waterline_reason="DW_WATERLINE_VERSION is required to install the published Waterline artifact"
waterline_app_dir=""
waterline_source="not_installed"
waterline_surface_url="${DW_SKEW_WATERLINE_URL:-${DW_SKEW_WATERLINE_BASE_URL:-}}"
waterline_fixture_run_id="${DW_SKEW_WATERLINE_FIXTURE_RUN_ID:-skew-waterline-fixture}"
waterline_fixture_status=0
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
  elif [[ -z "$php_sdk_version" ]]; then
    waterline_status="runner_blocked"
    waterline_reason="DW_PHP_SDK_VERSION is required as an exact PHP SDK pin before installing Waterline"
  elif ! is_exact_semver "$php_sdk_version"; then
    waterline_status="runner_blocked"
    waterline_reason="Waterline install requires an exact durable-workflow/sdk version; got ${php_sdk_version}"
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
          "durable-workflow/waterline:${DW_WATERLINE_VERSION}@beta" \
          "durable-workflow/workflow:${workflow_version}@beta" \
          "durable-workflow/sdk:${php_sdk_version}@beta" >"$result_dir/waterline-composer-install.log" 2>&1; then
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
      waterline_fixture_script="$run_root/waterline-seed.php"
      cat > "$waterline_fixture_script" <<'PHP'
<?php
declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/waterline/vendor/autoload.php';

$app = require __DIR__.'/waterline/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$runId = (string) (getenv('DW_SKEW_WATERLINE_FIXTURE_RUN_ID') ?: 'skew-waterline-fixture');
$instanceId = (string) (getenv('DW_SKEW_WATERLINE_FIXTURE_INSTANCE_ID') ?: 'skew-waterline-instance');
$namespace = (string) (getenv('WATERLINE_NAMESPACE') ?: 'default');
$now = now()->format('Y-m-d H:i:s.u');
$labels = json_encode(['source' => 'skew-conformance'], JSON_THROW_ON_ERROR);
$memo = json_encode(['fixture' => 'waterline-skew-render'], JSON_THROW_ON_ERROR);

DB::table('workflow_instances')->updateOrInsert(
    ['id' => $instanceId],
    [
        'workflow_class' => 'SkewConformanceWorkflow',
        'workflow_type' => 'skew_conformance_workflow',
        'namespace' => $namespace,
        'business_key' => 'skew-conformance',
        'visibility_labels' => $labels,
        'memo' => $memo,
        'current_run_id' => $runId,
        'run_count' => 1,
        'last_message_sequence' => 0,
        'started_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ],
);

DB::table('workflow_runs')->updateOrInsert(
    ['id' => $runId],
    [
        'workflow_instance_id' => $instanceId,
        'run_number' => 1,
        'workflow_class' => 'SkewConformanceWorkflow',
        'workflow_type' => 'skew_conformance_workflow',
        'namespace' => $namespace,
        'business_key' => 'skew-conformance',
        'visibility_labels' => $labels,
        'status' => 'running',
        'compatibility' => 'skew-compatible',
        'connection' => 'sync',
        'queue' => 'skew-conformance',
        'last_history_sequence' => 1,
        'last_command_sequence' => 0,
        'message_cursor_position' => 0,
        'started_at' => $now,
        'last_progress_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ],
);

DB::table('workflow_run_summaries')->updateOrInsert(
    ['id' => $runId],
    [
        'workflow_instance_id' => $instanceId,
        'run_number' => 1,
        'is_current_run' => true,
        'engine_source' => 'v2',
        'projection_schema_version' => 1,
        'class' => 'SkewConformanceWorkflow',
        'workflow_type' => 'skew_conformance_workflow',
        'namespace' => $namespace,
        'compatibility' => 'skew-compatible',
        'declared_entry_mode' => 'compatibility',
        'declared_contract_source' => 'skew-conformance',
        'business_key' => 'skew-conformance',
        'visibility_labels' => $labels,
        'status' => 'running',
        'status_bucket' => 'running',
        'connection' => 'sync',
        'queue' => 'skew-conformance',
        'started_at' => $now,
        'sort_timestamp' => $now,
        'sort_key' => $now.'|'.$runId,
        'liveness_state' => 'running',
        'liveness_reason' => 'Seeded Waterline skew conformance fixture.',
        'repair_attention' => false,
        'task_problem' => false,
        'exception_count' => 0,
        'history_event_count' => 1,
        'history_size_bytes' => 0,
        'continue_as_new_recommended' => false,
        'created_at' => $now,
        'updated_at' => $now,
    ],
);
PHP

      if docker run --rm \
        -v "$run_root:/workspace" \
        -w /workspace \
        -e APP_ENV=local \
        -e DB_CONNECTION=sqlite \
        -e DB_DATABASE=/workspace/waterline/database/database.sqlite \
        -e WATERLINE_ENGINE_SOURCE=v2 \
        -e WATERLINE_ALLOW_UNAUTHENTICATED=true \
        -e WATERLINE_NAMESPACE="${DW_SKEW_NAMESPACE:-default}" \
        -e DW_SKEW_WATERLINE_FIXTURE_RUN_ID="$waterline_fixture_run_id" \
        composer:2 php /workspace/waterline-seed.php \
        >"$result_dir/waterline-seed-fixture.log" 2>&1; then
        waterline_fixture_status=0
      else
        waterline_fixture_status=1
      fi
    fi

    if [[ "$waterline_migrate_status" -eq 0 && -z "$waterline_surface_url" ]]; then
      waterline_port="${DW_SKEW_WATERLINE_PORT:-$(free_port)}"
      waterline_host="$(waterline_host_for_published_port)"
      waterline_bind_host="$(waterline_bind_host_for_published_port)"
      waterline_surface_url="http://${waterline_host}:${waterline_port}"
      waterline_container="dw-skew-waterline-${run_label}"
      if docker run -d \
        --name "$waterline_container" \
        -p "${waterline_bind_host}:${waterline_port}:${waterline_port}" \
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
    elif [[ "$waterline_fixture_status" -ne 0 ]]; then
      waterline_status="runner_blocked"
      waterline_reason="Waterline fixture seed failed before render evidence; see waterline-seed-fixture.log"
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
PHP_SDK_STATUS="$php_sdk_status" \
PHP_SDK_REASON="$php_sdk_reason" \
PHP_SDK_SOURCE="$php_sdk_source" \
PHP_SDK_APP_DIR="$php_sdk_app_dir" \
PHP_SDK_VERSION="$php_sdk_version" \
WORKFLOW_VERSION="$workflow_version" \
WATERLINE_STATUS="$waterline_status" \
WATERLINE_REASON="$waterline_reason" \
WATERLINE_SOURCE="$waterline_source" \
WATERLINE_APP_DIR="$waterline_app_dir" \
WATERLINE_SURFACE_URL="$waterline_surface_url" \
DW_SKEW_WATERLINE_FIXTURE_RUN_ID="$waterline_fixture_run_id" \
node - <<'NODE' > "$artifact_manifest"
const env = process.env;
const surface = (status, reason, source, extra = {}) => ({
  status,
  source,
  ...(reason ? { reason } : {}),
  ...Object.fromEntries(Object.entries(extra).filter(([, value]) => value)),
});

const workflowVersion = env.WORKFLOW_VERSION || env.DW_WORKFLOW_PHP_VERSION || env.DW_WORKFLOW_VERSION || '';
const phpSdkVersion = env.PHP_SDK_VERSION || env.DW_PHP_SDK_VERSION || '';
const manifest = {
  schema: 'durable-workflow.v2.skew-refusal-matrix.published-artifacts',
  artifact_versions: {
    server: env.DW_SERVER_VERSION || '',
    cli: env.DW_CLI_VERSION || '',
    'sdk-python': env.DW_PYTHON_SDK_VERSION || '',
    workflow: workflowVersion,
    'sdk-php': phpSdkVersion,
    waterline: env.DW_WATERLINE_VERSION || '',
  },
  artifact_sources: {
    server: env.SERVER_ARTIFACT_SOURCE || 'published_server_url',
    cli: env.CLI_SOURCE || 'not_installed',
    'sdk-python': env.PYTHON_SOURCE || 'not_installed',
    workflow: workflowVersion ? 'packagist' : 'not_installed',
    'sdk-php': env.PHP_SDK_SOURCE || 'not_installed',
    waterline: env.WATERLINE_SOURCE || 'not_installed',
  },
  surfaces: {
    cli: surface(env.CLI_STATUS, env.CLI_REASON, env.CLI_SOURCE, { executable: env.CLI_EXECUTABLE }),
    'sdk-python': surface(env.PYTHON_STATUS, env.PYTHON_REASON, env.PYTHON_SOURCE, { python: env.PYTHON_EXECUTABLE }),
    'sdk-php': surface(env.PHP_SDK_STATUS, env.PHP_SDK_REASON, env.PHP_SDK_SOURCE, { app_dir: env.PHP_SDK_APP_DIR }),
    waterline: surface(env.WATERLINE_STATUS, env.WATERLINE_REASON, env.WATERLINE_SOURCE, {
      app_dir: env.WATERLINE_APP_DIR,
      surface_url: env.WATERLINE_SURFACE_URL,
      fixture_run_id: env.DW_SKEW_WATERLINE_FIXTURE_RUN_ID,
    }),
  },
  local_product_source_checkouts_used: false,
};

process.stdout.write(`${JSON.stringify(manifest, null, 2)}\n`);
NODE

set +e
DW_SKEW_RESULT_DIR="$result_dir" \
DW_SKEW_RUN_ROOT="$run_root" \
DW_SKEW_REPO_ROOT="$repo_root" \
DW_SKEW_SERVER_URL="$server_url" \
DW_SKEW_ARTIFACTS_JSON="$artifact_manifest" \
DW_SKEW_WATERLINE_FIXTURE_RUN_ID="$waterline_fixture_run_id" \
DW_SKEW_STARTED_AT="${DW_SKEW_STARTED_AT:-$(timestamp)}" \
node "$script_dir/skew-published-artifacts.mjs"
runner_status=$?
set -e
exit_with_skew_record_status "$runner_status"
