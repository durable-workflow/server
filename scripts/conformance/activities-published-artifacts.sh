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
  DW_ACTIVITIES_EVIDENCE                Optional JSON activity evidence from a real host matrix run, including
                                         executed_distribution_identities captured from consumed bytes.
  DW_ACTIVITIES_EVIDENCE_PATH           Optional path to JSON activity evidence from a real host matrix run.
  DW_ACTIVITIES_SKIP_FOCUSED_HOST_PROBE=1
                                         Skip the published server container's focused activity host probe.
  DW_ACTIVITIES_PYTHON_BIN              Optional Python executable for the sdk-python focused activity shard.
                                         Defaults to a run-root venv installed from DW_PYTHON_SDK_VERSION when
                                         python3 and pip are available, then falls back to python3.
  DW_ACTIVITIES_RUNNER_SOURCE           Optional exact image source for the runner process. Defaults to
                                         DW_SERVER_IMAGE when the handoff runs from the release image root.
  DW_SERVER_IMAGE                       Exact server image tag or digest to test.
  DW_SERVER_VERSION                     Exact server SemVer tag; required for digest-only DW_SERVER_IMAGE.
  DW_CLI_VERSION                        Exact CLI release version.
  DW_PHP_SDK_VERSION                    Exact Composer durable-workflow/sdk version.
  DW_ACTIVITIES_CLI_BIN                 Optional executable official CLI binary to use for CLI observations.
  DW_CLI_BIN / DW_CLI_EXECUTABLE         Fallback executable official CLI binary names.
  DW_ACTIVITIES_CLI_INSTALLER_URL        Optional official CLI installer URL override.
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

should_delegate_to_published_server_container() {
  if [[ "${DW_ACTIVITIES_CONTAINER_HANDOFF:-0}" == "1" ]]; then
    return 1
  fi

  [[ "$repo_root" != "/app" && ! -d "$repo_root/.git" ]]
}

run_in_published_server_container() {
  if ! require_command docker; then
    printf '%s\n' 'required host command not found: docker' >&2
    return 2
  fi

  local server_image="${DW_SERVER_IMAGE:-}"
  if [[ -z "$server_image" ]]; then
    printf '%s\n' 'DW_SERVER_IMAGE is required for the published server container handoff' >&2
    return 2
  fi
  if [[ ! "$server_image" =~ ^(docker\.io/)?durableworkflow/server:[0-9]+\.[0-9]+\.[0-9]+([+-][0-9A-Za-z.-]+)?$ \
    && ! "$server_image" =~ ^(docker\.io/)?durableworkflow/server(@sha256:[0-9a-f]{64}|:[0-9]+\.[0-9]+\.[0-9]+([+-][0-9A-Za-z.-]+)?@sha256:[0-9a-f]{64})$ ]]; then
    printf 'DW_SERVER_IMAGE must name an exact Durable Workflow server tag or digest: %s\n' "$server_image" >&2
    return 2
  fi

  if [[ -z "$result_dir" ]]; then
    result_dir="$(mktemp -d "${TMPDIR:-/tmp}/dw-activities-result.XXXXXX")"
  fi
  mkdir -p "$result_dir"
  result_dir="$(cd "$result_dir" && pwd)"

  local docker_env=(
    -e DW_ACTIVITIES_CONTAINER_HANDOFF=1
    -e DW_ACTIVITIES_CONTAINER_REMOVED_ON_EXIT=1
    -e DW_ACTIVITIES_RUNNER_SOURCE="$server_image"
  )
  local variable
  for variable in \
    DW_SERVER_IMAGE DW_SERVER_VERSION DW_CLI_VERSION DW_PHP_SDK_VERSION DW_PYTHON_SDK_VERSION \
    DW_WORKFLOW_PHP_VERSION DW_WATERLINE_VERSION DW_ACTIVITIES_CLI_INSTALLER_URL \
    DW_CLI_INSTALLER_URL DW_ACTIVITIES_SKIP_FOCUSED_HOST_PROBE; do
    if [[ -n "${!variable:-}" ]]; then
      docker_env+=(-e "$variable=${!variable}")
    fi
  done

  local runner_args=(--result-dir /result)
  local host_user
  host_user="$(id -u):$(id -g)"
  if [[ "$keep_run_root" == "1" ]]; then
    runner_args+=(--keep-run-root)
  fi

  docker run --rm \
    --user "$host_user" \
    --entrypoint bash \
    -v "$result_dir:/result" \
    "${docker_env[@]}" \
    "$server_image" \
    /app/scripts/conformance/activities-published-artifacts.sh \
    "${runner_args[@]}"
}

if should_delegate_to_published_server_container; then
  run_in_published_server_container
  exit $?
fi

scenario_manifest="${DW_ACTIVITIES_SCENARIO_MANIFEST:-$repo_root/static/platform-conformance/activity-runtime-scenarios.json}"

run_root="${DW_ACTIVITIES_RUN_ROOT:-}"
run_root_supplied=1
if [[ -z "$run_root" ]]; then
  run_root="$(mktemp -d "${TMPDIR:-/tmp}/dw-activities.XXXXXX")"
  run_root_supplied=0
fi
mkdir -p "$run_root"

if [[ -z "$result_dir" ]]; then
  result_dir="$run_root"
fi
mkdir -p "$result_dir"
distribution_identity_file="$result_dir/executed-distribution-identities.json"

cleanup() {
  if [[ "$keep_run_root" != "1" && "$result_dir" != "$run_root" && "$run_root_supplied" != "1" ]]; then
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
    return 1
  fi
  if ! require_command python3; then
    return 1
  fi

  local venv="$run_root/sdk-python-venv"
  local install_log="$result_dir/sdk-python-focused-install.log"
  local distribution_dir="$run_root/sdk-python-distributions"
  local distribution
  mkdir -p "$distribution_dir"
  if python3 -m venv "$venv" >/dev/null 2>"$install_log" \
    && "$venv/bin/python" -m pip download --disable-pip-version-check --no-deps \
      --dest "$distribution_dir" "durable-workflow==${DW_PYTHON_SDK_VERSION}" >>"$install_log" 2>&1 \
    && python3 "$script_dir/distribution_identities.py" record-unique \
      "$distribution_identity_file" sdk-python "$DW_PYTHON_SDK_VERSION" "$distribution_dir" '*' \
      >>"$install_log" 2>&1 \
    && distribution="$(find "$distribution_dir" -maxdepth 1 -type f -print -quit)" \
    && "$venv/bin/python" -m pip install --disable-pip-version-check --no-input "$distribution" >>"$install_log" 2>&1; then
    export DW_ACTIVITIES_PYTHON_BIN="$venv/bin/python"
    return 0
  fi

  return 1
}

prepare_published_activity_cli() {
  local explicit="${DW_ACTIVITIES_CLI_BIN:-${DW_CLI_BIN:-${DW_CLI_EXECUTABLE:-}}}"
  if [[ -n "$explicit" ]]; then
    if [[ -x "$explicit" ]]; then
      export DW_ACTIVITIES_CLI_BIN="$explicit"
      export DW_ACTIVITIES_CLI_SOURCE="${DW_ACTIVITIES_CLI_SOURCE:-configured_cli_binary}"
      return 0
    fi

    export DW_ACTIVITIES_CLI_UNAVAILABLE_REASON="configured CLI binary is not executable"
    return 0
  fi

  if [[ -z "${DW_CLI_VERSION:-}" ]]; then
    export DW_ACTIVITIES_CLI_UNAVAILABLE_REASON="DW_CLI_VERSION is required to install the official CLI artifact"
    return 0
  fi
  if ! require_command curl; then
    export DW_ACTIVITIES_CLI_UNAVAILABLE_REASON="curl is required to download the official CLI installer"
    return 0
  fi

  local normalized="${DW_CLI_VERSION#v}"
  local cli_root="$run_root/cli"
  local cli_bin="$cli_root/bin/dw"
  local installer="$cli_root/install.sh"
  local checksums="$cli_root/SHA256SUMS"
  local installer_url=""
  local cli_asset=""
  mkdir -p "$cli_root/bin"

  case "$(uname -s)-$(uname -m)" in
    Linux-x86_64|Linux-amd64) cli_asset="dw-linux-x86_64" ;;
    Linux-aarch64|Linux-arm64) cli_asset="dw-linux-aarch64" ;;
    Darwin-aarch64|Darwin-arm64) cli_asset="dw-macos-aarch64" ;;
    *)
      export DW_ACTIVITIES_CLI_UNAVAILABLE_REASON="the current platform has no official CLI release asset"
      return 0
      ;;
  esac

  local candidates=()
  if [[ -n "${DW_ACTIVITIES_CLI_INSTALLER_URL:-${DW_CLI_INSTALLER_URL:-}}" ]]; then
    candidates+=("${DW_ACTIVITIES_CLI_INSTALLER_URL:-${DW_CLI_INSTALLER_URL:-}}")
  fi
  candidates+=(
    "https://github.com/durable-workflow/cli/releases/download/${normalized}/install.sh"
    "https://github.com/durable-workflow/cli/releases/download/v${normalized}/install.sh"
  )

  for candidate_url in "${candidates[@]}"; do
    if curl -fsSL --retry 3 -o "$installer" "$candidate_url" >"$result_dir/activity-cli-installer-download.log" 2>&1; then
      installer_url="$candidate_url"
      break
    fi
  done

  if [[ -z "$installer_url" ]]; then
    export DW_ACTIVITIES_CLI_UNAVAILABLE_REASON="official CLI installer is not downloadable for release ${DW_CLI_VERSION}"
    return 0
  fi

  if ! python3 "$script_dir/distribution_identities.py" record-file \
    "$distribution_identity_file" cli "$normalized" "$installer" \
    --artifact-name install.sh; then
    export DW_ACTIVITIES_CLI_UNAVAILABLE_REASON="official CLI installer bytes could not be identified"
    return 0
  fi

  chmod +x "$installer"
  if PATH="$cli_root/bin${PATH:+:$PATH}" \
    VERSION="$DW_CLI_VERSION" \
    DURABLE_WORKFLOW_INSTALL_DIR="$cli_root/bin" \
    DURABLE_WORKFLOW_BIN_NAME=dw \
    DURABLE_WORKFLOW_INSTALL_VERIFY_ATTESTATIONS=0 \
    sh "$installer" >"$result_dir/activity-cli-install.log" 2>&1 \
    && [[ -x "$cli_bin" ]]; then
    if ! curl -fsSL --retry 3 -o "$checksums" "${installer_url%/*}/SHA256SUMS" \
      >>"$result_dir/activity-cli-install.log" 2>&1 \
      || ! python3 "$script_dir/distribution_identities.py" record-file \
        "$distribution_identity_file" cli "$normalized" "$cli_bin" \
        --artifact-name "$cli_asset" \
      || ! python3 "$script_dir/distribution_identities.py" record-file \
        "$distribution_identity_file" cli "$normalized" "$checksums" \
        --artifact-name SHA256SUMS; then
      export DW_ACTIVITIES_CLI_UNAVAILABLE_REASON="the CLI installer did not retain exact identities for every consumed release asset"
      return 0
    fi
    export DW_ACTIVITIES_CLI_BIN="$cli_bin"
    export DW_ACTIVITIES_CLI_SOURCE="$installer_url"
    return 0
  fi

  export DW_ACTIVITIES_CLI_UNAVAILABLE_REASON="official CLI installer failed for release ${DW_CLI_VERSION}; see activity-cli-install.log"
  return 0
}

activity_prerequisite_failure=""

fail_activity_prerequisite() {
  activity_prerequisite_failure="$1"
  printf '%s\n' "$activity_prerequisite_failure" > "$result_dir/activity-runner-prerequisite.log"
  return 1
}

prepare_published_php_activity_artifacts() {
  if ! require_command composer; then
    fail_activity_prerequisite 'Composer is required inside the published server image to install the exact workflow and Waterline distributions'
    return 1
  fi

  local project_dir="$run_root/published-php-activity-artifacts"
  local composer_cache="$run_root/published-php-composer-cache"
  mkdir -p "$project_dir" "$composer_cache"
  printf '{\n  "name": "durable-workflow/activities-conformance",\n  "type": "project",\n  "require": {\n    "durable-workflow/workflow": "%s",\n    "durable-workflow/sdk": "%s",\n    "durable-workflow/waterline": "%s"\n  },\n  "minimum-stability": "dev",\n  "prefer-stable": true\n}\n' \
    "$DW_WORKFLOW_PHP_VERSION" "$DW_PHP_SDK_VERSION" "$DW_WATERLINE_VERSION" > "$project_dir/composer.json"

  if ! (
    cd "$project_dir"
    COMPOSER_HOME="$run_root/composer-home" \
    COMPOSER_CACHE_DIR="$composer_cache" \
    composer install --no-interaction --no-progress --prefer-dist --no-scripts
  ) > "$result_dir/activity-composer-install.log" 2>&1; then
    fail_activity_prerequisite "Composer could not install durable-workflow/workflow:${DW_WORKFLOW_PHP_VERSION}, durable-workflow/sdk:${DW_PHP_SDK_VERSION}, and durable-workflow/waterline:${DW_WATERLINE_VERSION}; see activity-composer-install.log"
    return 1
  fi

  local published_workflow="$project_dir/vendor/durable-workflow/workflow"
  local published_php_sdk="$project_dir/vendor/durable-workflow/sdk"
  local published_waterline="$project_dir/vendor/durable-workflow/waterline"
  local published_autoload="$project_dir/vendor/autoload.php"
  if [[ ! -d "$published_workflow" ]]; then
    fail_activity_prerequisite 'The published workflow package could not be bound into the server activity runtime'
    return 1
  fi
  if [[ ! -d "$published_php_sdk" ]]; then
    fail_activity_prerequisite 'The exact published PHP SDK package could not be bound into the server activity runtime'
    return 1
  fi
  if [[ ! -d "$published_waterline" || ! -f "$published_autoload" ]]; then
    fail_activity_prerequisite 'The exact published Waterline package does not expose a loadable Composer runtime'
    return 1
  fi
  export DW_ACTIVITIES_PUBLISHED_PHP_AUTOLOAD="$published_autoload"
  export DW_ACTIVITIES_WORKFLOW_PACKAGE_ROOT="$published_workflow"
  export DW_ACTIVITIES_PHP_SDK_PACKAGE_ROOT="$published_php_sdk"
  export DW_ACTIVITIES_WATERLINE_PACKAGE_ROOT="$published_waterline"
  export DW_ACTIVITIES_WORKFLOW_EXECUTION_OBSERVATION="$result_dir/workflow-execution-observation.json"
  export DW_ACTIVITIES_WATERLINE_EXECUTION_OBSERVATION="$result_dir/waterline-execution-observation.json"
  export DW_ACTIVITIES_PHP_SDK_EXECUTION_OBSERVATION="$result_dir/php-sdk-execution-observation.json"
  rm -f \
    "$DW_ACTIVITIES_WORKFLOW_EXECUTION_OBSERVATION" \
    "$DW_ACTIVITIES_WATERLINE_EXECUTION_OBSERVATION" \
    "$DW_ACTIVITIES_PHP_SDK_EXECUTION_OBSERVATION"
}

record_executed_php_activity_distributions() {
  local workflow_observation="${DW_ACTIVITIES_WORKFLOW_EXECUTION_OBSERVATION:-}"
  local waterline_observation="${DW_ACTIVITIES_WATERLINE_EXECUTION_OBSERVATION:-}"
  local php_sdk_observation="${DW_ACTIVITIES_PHP_SDK_EXECUTION_OBSERVATION:-}"
  if [[ -z "$workflow_observation" || ! -s "$workflow_observation" ]]; then
    fail_activity_prerequisite 'The focused activity probe did not retain a Workflow runtime execution observation'
    return 1
  fi
  if [[ -z "$waterline_observation" || ! -s "$waterline_observation" ]]; then
    fail_activity_prerequisite 'The focused activity probe did not retain a Waterline runtime execution observation'
    return 1
  fi
  if [[ -z "$php_sdk_observation" || ! -s "$php_sdk_observation" ]]; then
    fail_activity_prerequisite 'The focused activity probe did not retain a PHP SDK worker runtime execution observation'
    return 1
  fi
  if ! WORKFLOW_EXECUTION_OBSERVATION="$workflow_observation" \
    WATERLINE_EXECUTION_OBSERVATION="$waterline_observation" \
    PHP_SDK_EXECUTION_OBSERVATION="$php_sdk_observation" \
    WORKFLOW_VERSION="$DW_WORKFLOW_PHP_VERSION" \
    PHP_SDK_VERSION="$DW_PHP_SDK_VERSION" \
    WATERLINE_VERSION="$DW_WATERLINE_VERSION" \
    python3 <<'PY'
import json
import os
from pathlib import Path

observations = {
    "Workflow": json.loads(Path(os.environ["WORKFLOW_EXECUTION_OBSERVATION"]).read_text(encoding="utf-8")),
    "Waterline": json.loads(Path(os.environ["WATERLINE_EXECUTION_OBSERVATION"]).read_text(encoding="utf-8")),
    "PHP SDK": json.loads(Path(os.environ["PHP_SDK_EXECUTION_OBSERVATION"]).read_text(encoding="utf-8")),
}
expected = {
    "Workflow": {
        "schema": "durable-workflow.v2.activity-runtime.distribution-execution-observation",
        "component": "workflow",
        "package": "durable-workflow/workflow",
        "version": os.environ["WORKFLOW_VERSION"],
        "class": "Workflow\\V2\\Support\\WorkflowFiberRunner",
        "method": "step",
    },
    "Waterline": {
        "schema": "durable-workflow.v2.activity-runtime.distribution-execution-observation",
        "component": "waterline",
        "package": "durable-workflow/waterline",
        "version": os.environ["WATERLINE_VERSION"],
        "class": "Waterline\\Support\\CompensationVisibility",
        "method": "activitiesForRun",
    },
    "PHP SDK": {
        "schema": "durable-workflow.v2.activity-runtime.distribution-execution-observation",
        "component": "sdk-php",
        "package": "durable-workflow/sdk",
        "version": os.environ["PHP_SDK_VERSION"],
        "class": "DurableWorkflow\\Worker",
        "method": "run",
    },
}
for component, fields in expected.items():
    for field, value in fields.items():
        if observations[component].get(field) != value:
            raise SystemExit(f"{component} execution observation {field} did not match the exact package runtime")
command_count = observations["Workflow"].get("observed_command_count")
if type(command_count) is not int or not 1 <= command_count <= 100:
    raise SystemExit("Workflow execution observation did not retain a bounded command count")
activity_count = observations["Waterline"].get("observed_activity_count")
if type(activity_count) is not int or not 0 <= activity_count <= 1000:
    raise SystemExit("Waterline execution observation did not retain a bounded activity count")
heartbeat_count = observations["PHP SDK"].get("observed_heartbeat_count")
enforcement_count = observations["PHP SDK"].get("observed_enforcement_pass_count")
if type(heartbeat_count) is not int or heartbeat_count < 4:
    raise SystemExit("PHP SDK execution observation did not retain repeated accepted activity heartbeats")
if type(enforcement_count) is not int or enforcement_count < 3:
    raise SystemExit("PHP SDK execution observation did not retain repeated timeout enforcement passes")
PY
  then
    fail_activity_prerequisite 'The focused activity probe emitted invalid Workflow, PHP SDK, or Waterline runtime execution evidence'
    return 1
  fi

  if ! python3 "$script_dir/distribution_identities.py" record-unique \
    "$distribution_identity_file" workflow "$DW_WORKFLOW_PHP_VERSION" \
    "$run_root/published-php-composer-cache/files/durable-workflow/workflow" '**/*' \
    --artifact-name durable-workflow/workflow; then
    fail_activity_prerequisite 'The executed Workflow Composer archive could not be identified'
    return 1
  fi

  if ! python3 "$script_dir/distribution_identities.py" record-unique \
    "$distribution_identity_file" sdk-php "$DW_PHP_SDK_VERSION" \
    "$run_root/published-php-composer-cache/files/durable-workflow/sdk" '**/*' \
    --artifact-name durable-workflow/sdk; then
    fail_activity_prerequisite 'The executed PHP SDK Composer archive could not be identified'
    return 1
  fi

  if ! python3 "$script_dir/distribution_identities.py" record-unique \
    "$distribution_identity_file" waterline "$DW_WATERLINE_VERSION" \
    "$run_root/published-php-composer-cache/files/durable-workflow/waterline" '**/*' \
    --artifact-name durable-workflow/waterline; then
    fail_activity_prerequisite 'The executed Waterline Composer archive could not be identified'
    return 1
  fi
  if ! python3 "$script_dir/distribution_identities.py" validate \
    "$distribution_identity_file" workflow waterline server cli sdk-php sdk-python \
    > "$result_dir/executed-distribution-identities-validation.log" 2>&1; then
    fail_activity_prerequisite 'The activity runner did not retain the complete exact executed distribution set'
    return 1
  fi
}

write_published_activity_install_evidence() {
  ARTIFACT_INSTALL_EVIDENCE_PATH="$result_dir/artifact-install-evidence.json" \
  ACTIVITY_CLI_SOURCE="$DW_ACTIVITIES_CLI_SOURCE" \
  python3 <<'PY'
import json
import os
from datetime import datetime, timezone
from pathlib import Path

versions = {
    "server": os.environ["DW_SERVER_VERSION"],
    "cli": os.environ["DW_CLI_VERSION"].removeprefix("v"),
    "sdk-php": os.environ["DW_PHP_SDK_VERSION"],
    "sdk-python": os.environ["DW_PYTHON_SDK_VERSION"],
    "workflow-php": os.environ["DW_WORKFLOW_PHP_VERSION"],
    "waterline": os.environ["DW_WATERLINE_VERSION"],
}
sources = {
    "server": os.environ["DW_SERVER_IMAGE"],
    "cli": os.environ["ACTIVITY_CLI_SOURCE"],
    "sdk-php": f'packagist://durable-workflow/sdk@{versions["sdk-php"]}',
    "sdk-python": f'pypi://durable-workflow=={versions["sdk-python"]}',
    "workflow-php": f'packagist://durable-workflow/workflow@{versions["workflow-php"]}',
    "waterline": f'packagist://durable-workflow/waterline@{versions["waterline"]}',
}
evidence = {
    "schema": "durable-workflow.v2.activity-runtime.artifact-install-evidence",
    "generated_at": datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z"),
    "local_product_source_checkouts_used": False,
    "artifacts": [
        {
            "artifact": artifact,
            "version": version,
            "source": sources[artifact],
            "status": "pass",
            "local_product_source_checkouts_used": False,
        }
        for artifact, version in versions.items()
    ],
}
Path(os.environ["ARTIFACT_INSTALL_EVIDENCE_PATH"]).write_text(
    json.dumps(evidence, indent=2, sort_keys=True) + "\n",
    encoding="utf-8",
)
PY
}

prepare_published_activity_artifacts() {
  rm -f "$distribution_identity_file"

  for variable in \
    DW_SERVER_IMAGE DW_SERVER_VERSION DW_CLI_VERSION DW_PHP_SDK_VERSION DW_PYTHON_SDK_VERSION \
    DW_WORKFLOW_PHP_VERSION DW_WATERLINE_VERSION; do
    if [[ -z "${!variable:-}" ]]; then
      fail_activity_prerequisite "Required exact candidate variable is empty: ${variable}"
      return 1
    fi
  done

  local server_digest="${DW_SERVER_IMAGE##*@}"
  if [[ "$server_digest" == "$DW_SERVER_IMAGE" || ! "$server_digest" =~ ^sha256:[0-9a-f]{64}$ ]]; then
    fail_activity_prerequisite 'DW_SERVER_IMAGE must be digest-pinned so the consumed server manifest can be identified'
    return 1
  fi
  if ! python3 "$script_dir/distribution_identities.py" record-digest \
    "$distribution_identity_file" server "$DW_SERVER_VERSION" manifest "$server_digest"; then
    fail_activity_prerequisite 'The consumed server OCI manifest could not be identified'
    return 1
  fi

  if ! prepare_published_php_activity_artifacts; then
    return 1
  fi
  if ! prepare_focused_python_sdk; then
    fail_activity_prerequisite "PyPI durable-workflow==${DW_PYTHON_SDK_VERSION} could not be downloaded, identified, and installed; see sdk-python-focused-install.log"
    return 1
  fi
  prepare_published_activity_cli
  if [[ -z "${DW_ACTIVITIES_CLI_BIN:-}" || ! -x "$DW_ACTIVITIES_CLI_BIN" ]]; then
    fail_activity_prerequisite "${DW_ACTIVITIES_CLI_UNAVAILABLE_REASON:-The exact CLI release could not be installed}"
    return 1
  fi
  if ! python3 "$script_dir/distribution_identities.py" validate \
    "$distribution_identity_file" server cli sdk-python \
    > "$result_dir/executed-distribution-identities-validation.log" 2>&1; then
    fail_activity_prerequisite 'The activity runner did not retain the complete exact prepared distribution set'
    return 1
  fi

  if ! write_published_activity_install_evidence; then
    fail_activity_prerequisite 'The activity runner could not retain exact published artifact install evidence'
    return 1
  fi
}

run_focused_activity_host_probe() {
  local probe_db="$run_root/activities-focused-host-probe.sqlite"
  local probe_storage="$run_root/activities-focused-host-storage"
  local scratch_removed_on_exit=true
  if [[ "$keep_run_root" == "1" || "$result_dir" == "$run_root" || "$run_root_supplied" == "1" ]]; then
    scratch_removed_on_exit=false
  fi

  : > "$probe_db"
  mkdir -p \
    "$probe_storage/logs" \
    "$probe_storage/framework/cache/data" \
    "$probe_storage/framework/sessions" \
    "$probe_storage/framework/testing" \
    "$probe_storage/framework/views"

  APP_ENV=production \
  APP_DEBUG=false \
  APP_KEY="$focused_probe_app_key" \
  DB_CONNECTION=sqlite \
  DB_DATABASE="$probe_db" \
  QUEUE_CONNECTION=database \
  CACHE_STORE=array \
  SESSION_DRIVER=array \
  LARAVEL_STORAGE_PATH="$probe_storage" \
  VIEW_COMPILED_PATH="$probe_storage/framework/views" \
  DW_AUTH_DRIVER=none \
  DW_TASK_DISPATCH_MODE=poll \
  DW_V2_TASK_DISPATCH_MODE=poll \
  DW_ACTIVITIES_CLI_BIN="${DW_ACTIVITIES_CLI_BIN:-}" \
  DW_ACTIVITIES_CLI_SOURCE="${DW_ACTIVITIES_CLI_SOURCE:-}" \
  DW_ACTIVITIES_CLI_UNAVAILABLE_REASON="${DW_ACTIVITIES_CLI_UNAVAILABLE_REASON:-}" \
  DW_ACTIVITIES_PUBLISHED_PHP_AUTOLOAD="${DW_ACTIVITIES_PUBLISHED_PHP_AUTOLOAD:-}" \
  DW_ACTIVITIES_SCRATCH_REMOVED_ON_EXIT="$scratch_removed_on_exit" \
  DW_ACTIVITIES_WORKFLOW_PACKAGE_ROOT="${DW_ACTIVITIES_WORKFLOW_PACKAGE_ROOT:-}" \
  DW_ACTIVITIES_PHP_SDK_PACKAGE_ROOT="${DW_ACTIVITIES_PHP_SDK_PACKAGE_ROOT:-}" \
  DW_ACTIVITIES_WATERLINE_PACKAGE_ROOT="${DW_ACTIVITIES_WATERLINE_PACKAGE_ROOT:-}" \
  DW_ACTIVITIES_WORKFLOW_EXECUTION_OBSERVATION="${DW_ACTIVITIES_WORKFLOW_EXECUTION_OBSERVATION:-}" \
  DW_ACTIVITIES_PHP_SDK_EXECUTION_OBSERVATION="${DW_ACTIVITIES_PHP_SDK_EXECUTION_OBSERVATION:-}" \
  DW_ACTIVITIES_WATERLINE_EXECUTION_OBSERVATION="${DW_ACTIVITIES_WATERLINE_EXECUTION_OBSERVATION:-}" \
  RUNNER_REPO_ROOT="$repo_root" \
  RESULT_DIR="$result_dir" \
  RUN_ROOT="$run_root" \
  php <<'PHP' || true
<?php
declare(strict_types=1);

use App\Models\WorkflowNamespace;
use App\Support\ActivitiesConformanceWorkerRegistration;
use App\Support\ControlPlaneProtocol;
use App\Support\WorkerProtocol;
use DurableWorkflow\Client as PublishedPhpSdkClient;
use DurableWorkflow\Exception\ServerException as PublishedPhpSdkServerException;
use DurableWorkflow\Exception\TransportException as PublishedPhpSdkTransportException;
use DurableWorkflow\Transport\Transport as PublishedPhpSdkTransport;
use DurableWorkflow\Worker as PublishedPhpSdkWorker;
use DurableWorkflow\Worker\ActivityContext as PublishedPhpSdkActivityContext;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Waterline\Support\CompensationVisibility;
use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\FailureCategory;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ActivityOptions;
use Workflow\V2\Support\WorkflowFiberRunner;
use Workflow\V2\Workflow;

const ACTIVITIES_NAMESPACE = 'activities-conformance';
const EMBEDDED_WORKFLOW_TYPE = 'activities.conformance.workflow-embedded-result';
const ACTIVITY_TYPE = 'activities.conformance.echo';
const HOST_EVIDENCE_SCHEMA = 'durable-workflow.v2.activity-runtime.published-artifact-host-evidence';
const HOST_EVIDENCE_SOURCE = 'published_server_container';

$repoRoot = getenv('RUNNER_REPO_ROOT') ?: '/app';
if (! is_dir($repoRoot)) {
    throw new RuntimeException('published server root is not available');
}
chdir($repoRoot);

$publishedPhpAutoload = getenv('DW_ACTIVITIES_PUBLISHED_PHP_AUTOLOAD') ?: '';
if ($publishedPhpAutoload === '' || ! is_file($publishedPhpAutoload)) {
    throw new RuntimeException('exact published Workflow and Waterline Composer autoloader is not available');
}
$publishedPhpLoader = require $publishedPhpAutoload;
$publishedPhpLoader->unregister();
require $repoRoot.'/vendor/autoload.php';
spl_autoload_register(
    static function (string $class) use ($publishedPhpLoader): void {
        if (! str_starts_with($class, 'Workflow\\')
            && ! str_starts_with($class, 'Waterline\\')
            && ! str_starts_with($class, 'DurableWorkflow\\')) {
            return;
        }

        $file = $publishedPhpLoader->findFile($class);
        if (is_string($file)) {
            require $file;
        }
    },
    true,
    true,
);

$workflowPackageRoot = realpath(getenv('DW_ACTIVITIES_WORKFLOW_PACKAGE_ROOT') ?: '');
$workflowClassFile = realpath((new ReflectionClass(WorkflowFiberRunner::class))->getFileName() ?: '');
if ($workflowPackageRoot === false
    || $workflowClassFile === false
    || ! str_starts_with($workflowClassFile, rtrim($workflowPackageRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Workflow activity runtime did not load from the exact installed package');
}

$phpSdkPackageRoot = realpath(getenv('DW_ACTIVITIES_PHP_SDK_PACKAGE_ROOT') ?: '');
$phpSdkWorkerFile = realpath((new ReflectionClass(PublishedPhpSdkWorker::class))->getFileName() ?: '');
if ($phpSdkPackageRoot === false
    || $phpSdkWorkerFile === false
    || ! str_starts_with($phpSdkWorkerFile, rtrim($phpSdkPackageRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('PHP SDK activity worker did not load from the exact installed package');
}

#[Type(EMBEDDED_WORKFLOW_TYPE)]
final class PublishedActivitiesEmbeddedWorkflow extends Workflow
{
    public function handle(array $payload): array
    {
        $scenarioId = is_string($payload['scenario_id'] ?? null) ? $payload['scenario_id'] : '';
        $taskQueue = is_string($payload['task_queue'] ?? null) ? $payload['task_queue'] : '';
        if ($taskQueue === '') {
            throw new RuntimeException('activities conformance workflow input did not include its isolated task queue');
        }

        if ($scenarioId === 'typed_failure_propagation') {
            try {
                Workflow::activity(
                    ACTIVITY_TYPE,
                    new ActivityOptions(queue: $taskQueue),
                    $payload
                );

                return [
                    'workflow_runtime' => 'workflow-php',
                    'requested_runtime' => $payload['runtime'] ?? null,
                    'caller_observed_failure' => [
                        'status' => 'unexpected_success',
                    ],
                ];
            } catch (Throwable $throwable) {
                $failurePayload = method_exists($throwable, 'failurePayload')
                    ? $throwable->failurePayload()
                    : [];
                $details = is_array($failurePayload) && array_key_exists('details', $failurePayload)
                    ? decode_payload($failurePayload['details'], $failurePayload['details_payload_codec'] ?? null)
                    : null;

                return [
                    'workflow_runtime' => 'workflow-php',
                    'requested_runtime' => $payload['runtime'] ?? null,
                    'caller_observed_failure' => [
                        'status' => 'caught',
                        'class' => $throwable::class,
                        'message' => $throwable->getMessage(),
                        'original_exception_class' => method_exists($throwable, 'originalExceptionClass')
                            ? $throwable->originalExceptionClass()
                            : $throwable::class,
                        'failure_type' => is_array($failurePayload) ? ($failurePayload['type'] ?? null) : null,
                        'failure_message' => is_array($failurePayload) ? ($failurePayload['message'] ?? null) : $throwable->getMessage(),
                        'details_payload_codec' => is_array($failurePayload) ? ($failurePayload['details_payload_codec'] ?? null) : null,
                        'failure_details' => $details,
                        'failure_payload' => $failurePayload,
                    ],
                ];
            }
        }

        $activityResult = Workflow::activity(
            ACTIVITY_TYPE,
            new ActivityOptions(queue: $taskQueue),
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

function cli_activity_visibility_finding(string $message): array
{
    $finding = finding_for_failure('cli_activity_attempt_state_visibility', $message);
    $finding['owning_surface'] = 'cli';
    $finding['next_acceptance_criterion'] = 'rerun activities conformance with official dw activity:list and activity:describe JSON output exposing activity execution ids and attempt rows';

    return $finding;
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
        'heartbeat_timeout_renewal_across_enforcement_passes',
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
    $timeoutScenario = null;
    foreach ($scenarioResults as $scenario) {
        if (($scenario['scenario_id'] ?? null) === 'timeout_behavior') {
            $timeoutScenario = $scenario;
            break;
        }
    }
    $timeoutOutputs = is_array($timeoutScenario['observed_outputs'] ?? null)
        ? $timeoutScenario['observed_outputs']
        : [];
    $typedFailureScenario = null;
    foreach ($scenarioResults as $scenario) {
        if (($scenario['scenario_id'] ?? null) === 'typed_failure_propagation') {
            $typedFailureScenario = $scenario;
            break;
        }
    }
    $typedFailureOutputs = is_array($typedFailureScenario['observed_outputs'] ?? null)
        ? $typedFailureScenario['observed_outputs']
        : [];
    $heartbeatScenario = null;
    foreach ($scenarioResults as $scenario) {
        if (($scenario['scenario_id'] ?? null) === 'heartbeat_and_cancellation_observation') {
            $heartbeatScenario = $scenario;
            break;
        }
    }
    $heartbeatOutputs = is_array($heartbeatScenario['observed_outputs'] ?? null)
        ? $heartbeatScenario['observed_outputs']
        : [];
    $heartbeatTimeoutRenewalScenario = null;
    foreach ($scenarioResults as $scenario) {
        if (($scenario['scenario_id'] ?? null) === 'heartbeat_timeout_renewal_across_enforcement_passes') {
            $heartbeatTimeoutRenewalScenario = $scenario;
            break;
        }
    }
    $heartbeatTimeoutRenewalOutputs = is_array($heartbeatTimeoutRenewalScenario['observed_outputs'] ?? null)
        ? $heartbeatTimeoutRenewalScenario['observed_outputs']
        : [];
    $idempotentScenario = null;
    foreach ($scenarioResults as $scenario) {
        if (($scenario['scenario_id'] ?? null) === 'idempotent_completion_handling') {
            $idempotentScenario = $scenario;
            break;
        }
    }
    $idempotentOutputs = is_array($idempotentScenario['observed_outputs'] ?? null)
        ? $idempotentScenario['observed_outputs']
        : [];
    $operatorVisibilityScenario = null;
    foreach ($scenarioResults as $scenario) {
        if (($scenario['scenario_id'] ?? null) === 'operator_visible_activity_attempt_state') {
            $operatorVisibilityScenario = $scenario;
            break;
        }
    }
    $operatorVisibilityOutputs = is_array($operatorVisibilityScenario['observed_outputs'] ?? null)
        ? $operatorVisibilityScenario['observed_outputs']
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
            'runtimes' => ['workflow-php', 'sdk-php', 'sdk-python'],
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
        'timeout_behavior' => [
            'status' => $scenarioStatusById['timeout_behavior'] ?? 'not_covered',
            'scenario' => 'timeout_behavior',
            'configured_timeout_inputs' => $timeoutOutputs['configured_timeout_inputs'] ?? null,
            'timeout_type' => $timeoutOutputs['timeout_type'] ?? null,
            'deadline_at' => $timeoutOutputs['deadline_at'] ?? null,
            'worker_visible_deadlines' => $timeoutOutputs['worker_visible_deadlines'] ?? null,
            'enforcement_endpoint' => $timeoutOutputs['enforcement_endpoint'] ?? null,
            'enforcement_observed_at' => $timeoutOutputs['enforcement_observed_at'] ?? null,
            'timeout_status_before_enforce' => $timeoutOutputs['timeout_status_before_enforce'] ?? null,
            'enforce_response' => $timeoutOutputs['enforce_response'] ?? null,
            'typed_timeout_payload' => $timeoutOutputs['typed_timeout_payload'] ?? null,
            'activity_status' => $timeoutOutputs['activity_status'] ?? null,
            'caller_visible_outcome' => $timeoutOutputs['caller_visible_outcome'] ?? null,
            'history_events' => $timeoutOutputs['history_events'] ?? null,
        ],
        'typed_failure_propagation' => [
            'status' => $scenarioStatusById['typed_failure_propagation'] ?? 'not_covered',
            'scenario' => 'typed_failure_propagation',
            'failure_type' => $typedFailureOutputs['failure_type'] ?? null,
            'failure_message' => $typedFailureOutputs['failure_message'] ?? null,
            'failure_details' => $typedFailureOutputs['failure_details'] ?? null,
            'history_exception' => $typedFailureOutputs['history_exception'] ?? null,
            'caller_observed_failure' => $typedFailureOutputs['caller_observed_failure'] ?? null,
            'history_events' => $typedFailureOutputs['history_events'] ?? null,
            'activity_failed_history_events' => $typedFailureOutputs['activity_failed_history_events'] ?? null,
            'failure_row' => $typedFailureOutputs['failure_row'] ?? null,
        ],
        'heartbeat_cancellation' => [
            'status' => $scenarioStatusById['heartbeat_and_cancellation_observation'] ?? 'not_covered',
            'scenario' => 'heartbeat_and_cancellation_observation',
            'heartbeat_details' => $heartbeatOutputs['heartbeat_details'] ?? null,
            'heartbeat_history_event' => $heartbeatOutputs['heartbeat_history_event'] ?? null,
            'cancel_requested_response' => $heartbeatOutputs['cancel_requested_response'] ?? null,
            'worker_observed_cancellation' => $heartbeatOutputs['worker_observed_cancellation'] ?? null,
            'activity_handle_after_cancel' => $heartbeatOutputs['activity_handle_after_cancel'] ?? null,
            'late_completion_after_cancel_response' => $heartbeatOutputs['late_completion_after_cancel_response'] ?? null,
            'terminal_cancellation_state' => $heartbeatOutputs['terminal_cancellation_state'] ?? null,
            'activity_execution_id' => $heartbeatOutputs['activity_execution_id'] ?? null,
            'activity_attempt_id' => $heartbeatOutputs['activity_attempt_id'] ?? null,
            'attempt_state' => $heartbeatOutputs['attempt_state'] ?? null,
        ],
        'heartbeat_timeout_renewal' => [
            'status' => $scenarioStatusById['heartbeat_timeout_renewal_across_enforcement_passes'] ?? 'not_covered',
            'scenario' => 'heartbeat_timeout_renewal_across_enforcement_passes',
            'php_sdk_worker_artifact' => $heartbeatTimeoutRenewalOutputs['php_sdk_worker_artifact'] ?? null,
            'heartbeat_timeout_seconds' => $heartbeatTimeoutRenewalOutputs['heartbeat_timeout_seconds'] ?? null,
            'heartbeat_cadence_seconds' => $heartbeatTimeoutRenewalOutputs['heartbeat_cadence_seconds'] ?? null,
            'initial_heartbeat_deadline_at' => $heartbeatTimeoutRenewalOutputs['initial_heartbeat_deadline_at'] ?? null,
            'heartbeat_acknowledgements' => $heartbeatTimeoutRenewalOutputs['heartbeat_acknowledgements'] ?? null,
            'enforcement_passes' => $heartbeatTimeoutRenewalOutputs['enforcement_passes'] ?? null,
            'in_flight_duration_seconds' => $heartbeatTimeoutRenewalOutputs['in_flight_duration_seconds'] ?? null,
            'completion_response' => $heartbeatTimeoutRenewalOutputs['completion_response'] ?? null,
            'terminal_history' => $heartbeatTimeoutRenewalOutputs['terminal_history'] ?? null,
            'negative_control' => $heartbeatTimeoutRenewalOutputs['negative_control'] ?? null,
            'isolated_cleanup' => $heartbeatTimeoutRenewalOutputs['isolated_cleanup'] ?? null,
        ],
        'idempotent_completion' => [
            'status' => $scenarioStatusById['idempotent_completion_handling'] ?? 'not_covered',
            'scenario' => 'idempotent_completion_handling',
            'first_completion_response' => $idempotentOutputs['first_completion_response'] ?? null,
            'duplicate_completion_response' => $idempotentOutputs['duplicate_completion_response'] ?? null,
            'activity_attempt_id' => $idempotentOutputs['activity_attempt_id'] ?? null,
            'recorded_once' => $idempotentOutputs['recorded_once'] ?? null,
            'stale_attempt_or_idempotent_verdict' => $idempotentOutputs['stale_attempt_or_idempotent_verdict'] ?? null,
            'activity_completed_history_count' => $idempotentOutputs['activity_completed_history_count'] ?? null,
        ],
        'operator_visibility' => [
            'status' => $scenarioStatusById['operator_visible_activity_attempt_state'] ?? 'not_covered',
            'scenario' => 'operator_visible_activity_attempt_state',
            'api_run_detail' => $operatorVisibilityOutputs['api_run_detail'] ?? null,
            'history_activity_attempts' => $operatorVisibilityOutputs['history_activity_attempts'] ?? null,
            'operator_metrics' => $operatorVisibilityOutputs['operator_metrics'] ?? null,
            'waterline_activity_attempt_view' => $operatorVisibilityOutputs['waterline_activity_attempt_view'] ?? null,
            'cli_json_list_evidence' => $operatorVisibilityOutputs['cli_json_list_evidence'] ?? null,
            'required_operator_states' => $operatorVisibilityOutputs['required_operator_states'] ?? null,
            'operator_state_matrix' => $operatorVisibilityOutputs['operator_state_matrix'] ?? null,
            'operator_state_passes' => $operatorVisibilityOutputs['operator_state_passes'] ?? null,
            'operator_state_passes_without_cli' => $operatorVisibilityOutputs['operator_state_passes_without_cli'] ?? null,
            'missing_operator_surface_reasons' => $operatorVisibilityOutputs['missing_operator_surface_reasons'] ?? null,
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

final class PublishedServerKernelSdkTransport implements PublishedPhpSdkTransport
{
    /** @var list<array<string, mixed>> */
    private array $exchanges = [];

    public function send(string $method, string $uri, array $headers, ?array $body = null): ?array
    {
        $parts = parse_url($uri);
        $path = is_array($parts) && is_string($parts['path'] ?? null) ? $parts['path'] : '/';
        $query = is_array($parts) && is_string($parts['query'] ?? null) ? $parts['query'] : '';
        $target = $query === '' ? $path : $path.'?'.$query;
        $server = [];
        foreach ($headers as $name => $value) {
            $normalized = strtolower($name);
            if ($normalized === 'content-type') {
                $server['CONTENT_TYPE'] = $value;
            } elseif ($normalized === 'accept') {
                $server['HTTP_ACCEPT'] = $value;
            } else {
                $server[header_key($name)] = $value;
            }
        }

        $startedAt = microtime(true);
        $content = $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR);
        $request = Request::create($target, strtoupper($method), [], [], [], $server, $content);
        /** @var HttpKernel $kernel */
        $kernel = app(HttpKernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        $status = $response->getStatusCode();
        $raw = (string) $response->getContent();
        $decoded = $raw === '' ? null : json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        if ($decoded !== null && ! is_array($decoded)) {
            throw new RuntimeException('published PHP SDK transport received a non-object JSON response');
        }

        $this->exchanges[] = [
            'method' => strtoupper($method),
            'path' => $path,
            'request_started_at' => iso_from_timestamp($startedAt),
            'response_received_at' => iso_from_timestamp(microtime(true)),
            'request' => $body,
            'http_status' => $status,
            'response' => $decoded,
        ];

        if ($status < 200 || $status >= 300) {
            throw PublishedPhpSdkTransportException::fromResponse($status, $decoded, $raw);
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    public function latest(string $pathSuffix, ?string $method = null): array
    {
        foreach (array_reverse($this->exchanges) as $exchange) {
            if (! str_ends_with((string) ($exchange['path'] ?? ''), $pathSuffix)) {
                continue;
            }
            if ($method !== null && ($exchange['method'] ?? null) !== strtoupper($method)) {
                continue;
            }

            return $exchange;
        }

        return [];
    }

    public function count(string $pathSuffix, ?string $method = null): int
    {
        return count(array_filter(
            $this->exchanges,
            static fn (array $exchange): bool => str_ends_with((string) ($exchange['path'] ?? ''), $pathSuffix)
                && ($method === null || ($exchange['method'] ?? null) === strtoupper($method))
        ));
    }
}

function focused_result_dir(): string
{
    return rtrim(getenv('RESULT_DIR') ?: sys_get_temp_dir(), '/');
}

function cli_unavailable_reason(): string
{
    $reason = getenv('DW_ACTIVITIES_CLI_UNAVAILABLE_REASON');
    if (is_string($reason) && trim($reason) !== '') {
        return trim($reason);
    }

    $bin = getenv('DW_ACTIVITIES_CLI_BIN');
    if (! is_string($bin) || trim($bin) === '') {
        return 'DW_ACTIVITIES_CLI_BIN is not configured and the official CLI artifact was not installed';
    }
    if (! is_executable($bin)) {
        return 'configured official CLI binary is not executable';
    }

    return 'official CLI binary is unavailable';
}

function reserve_loopback_port(): int
{
    $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (! is_resource($socket)) {
        throw new RuntimeException("could not reserve loopback port for CLI observations: {$errstr}");
    }

    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    $port = is_string($name) && preg_match('/:(\d+)$/', $name, $matches) === 1
        ? (int) $matches[1]
        : 0;
    if ($port <= 0) {
        throw new RuntimeException('could not determine reserved loopback port for CLI observations');
    }

    return $port;
}

function process_status_code(mixed $status): ?int
{
    return is_array($status) && is_int($status['exitcode'] ?? null) && $status['exitcode'] >= 0
        ? $status['exitcode']
        : null;
}

function process_environment(array $overrides = []): array
{
    $environment = getenv();
    if (! is_array($environment)) {
        $environment = [];
    }

    foreach ($overrides as $key => $value) {
        if (is_string($value)) {
            $environment[$key] = $value;
        }
    }

    return $environment;
}

function stop_cli_observation_server(mixed $process): void
{
    if (! is_resource($process)) {
        return;
    }

    $status = proc_get_status($process);
    if (is_array($status) && ($status['running'] ?? false) === true) {
        proc_terminate($process);
        usleep(200000);
        $status = proc_get_status($process);
        if (is_array($status) && ($status['running'] ?? false) === true) {
            proc_terminate($process, 9);
        }
    }

    proc_close($process);
}

function cli_server_ready(string $baseUrl): bool
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'ignore_errors' => true,
            'timeout' => 1,
            'header' => implode("\r\n", [
                'Accept: application/json',
                ControlPlaneProtocol::HEADER.': '.ControlPlaneProtocol::VERSION,
            ]),
        ],
    ]);

    $body = @file_get_contents($baseUrl.'/api/cluster/info', false, $context);
    if (! is_string($body) || $body === '') {
        return false;
    }

    try {
        $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return false;
    }

    return is_array($decoded);
}

function cli_observation_context(): array
{
    static $context = null;
    if (is_array($context)) {
        return $context;
    }

    $bin = getenv('DW_ACTIVITIES_CLI_BIN');
    if (! is_string($bin) || trim($bin) === '' || ! is_executable($bin)) {
        return $context = [
            'available' => false,
            'reason' => cli_unavailable_reason(),
            'artifact_source' => getenv('DW_ACTIVITIES_CLI_SOURCE') ?: null,
        ];
    }

    $port = reserve_loopback_port();
    $baseUrl = 'http://127.0.0.1:'.$port;
    $logPath = focused_result_dir().'/activity-cli-server.log';
    $command = [PHP_BINARY, 'artisan', 'serve', '--host=127.0.0.1', '--port='.$port];
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $logPath, 'a'],
        2 => ['file', $logPath, 'a'],
    ];
    $process = proc_open($command, $descriptors, $pipes, getcwd() ?: null, process_environment([
        'APP_ENV' => getenv('APP_ENV') ?: 'production',
        'APP_DEBUG' => getenv('APP_DEBUG') ?: 'false',
        'APP_KEY' => getenv('APP_KEY') ?: 'base64:QUNUSVZJVElFUy1DT05GT1JNQU5DRS1GT0NVU0VELUhPU1QtUFJPQkU=',
        'DB_CONNECTION' => getenv('DB_CONNECTION') ?: 'sqlite',
        'DB_DATABASE' => getenv('DB_DATABASE') ?: '',
        'QUEUE_CONNECTION' => getenv('QUEUE_CONNECTION') ?: 'database',
        'CACHE_STORE' => getenv('CACHE_STORE') ?: 'array',
        'SESSION_DRIVER' => getenv('SESSION_DRIVER') ?: 'array',
        'DW_AUTH_DRIVER' => getenv('DW_AUTH_DRIVER') ?: 'none',
        'DW_TASK_DISPATCH_MODE' => getenv('DW_TASK_DISPATCH_MODE') ?: 'poll',
        'DW_V2_TASK_DISPATCH_MODE' => getenv('DW_V2_TASK_DISPATCH_MODE') ?: 'poll',
    ]));

    if (! is_resource($process)) {
        return $context = [
            'available' => false,
            'reason' => 'could not start temporary HTTP server for official CLI observations',
            'artifact_source' => getenv('DW_ACTIVITIES_CLI_SOURCE') ?: null,
            'server_log' => $logPath,
        ];
    }
    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }
    register_shutdown_function(static fn () => stop_cli_observation_server($process));

    $ready = false;
    $deadline = microtime(true) + 15.0;
    do {
        if (cli_server_ready($baseUrl)) {
            $ready = true;
            break;
        }
        usleep(200000);
        $status = proc_get_status($process);
        if (is_array($status) && ($status['running'] ?? false) !== true) {
            break;
        }
    } while (microtime(true) < $deadline);

    if (! $ready) {
        stop_cli_observation_server($process);

        return $context = [
            'available' => false,
            'reason' => 'temporary HTTP server for official CLI observations did not become ready',
            'artifact_source' => getenv('DW_ACTIVITIES_CLI_SOURCE') ?: null,
            'server_log' => $logPath,
        ];
    }

    return $context = [
        'available' => true,
        'bin' => $bin,
        'base_url' => $baseUrl,
        'artifact_source' => getenv('DW_ACTIVITIES_CLI_SOURCE') ?: null,
        'server_log' => $logPath,
    ];
}

function parse_cli_json_output(string $stdout): array
{
    $trimmed = trim($stdout);
    if ($trimmed === '') {
        return ['value' => null, 'error' => 'stdout was empty'];
    }

    try {
        $decoded = json_decode($trimmed, true, flags: JSON_THROW_ON_ERROR);

        return [
            'value' => is_array($decoded) ? $decoded : null,
            'error' => is_array($decoded) ? null : 'stdout did not decode to a JSON object',
        ];
    } catch (Throwable $throwable) {
        return ['value' => null, 'error' => $throwable->getMessage()];
    }
}

function run_dw_json_command(array $args, array $context): array
{
    if (($context['available'] ?? false) !== true) {
        return [
            'command' => ['dw', ...$args],
            'exit_code' => null,
            'status' => 'not_exercised',
            'error' => $context['reason'] ?? 'official CLI unavailable',
            'parsed_json' => null,
            'json_parse_error' => null,
        ];
    }

    $fullArgs = [
        ...$args,
        '--server='.$context['base_url'],
        '--namespace='.ACTIVITIES_NAMESPACE,
    ];
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open([$context['bin'], ...$fullArgs], $descriptors, $pipes, getcwd() ?: null, process_environment([
        'DURABLE_WORKFLOW_SERVER_URL' => $context['base_url'],
        'DURABLE_WORKFLOW_NAMESPACE' => ACTIVITIES_NAMESPACE,
    ]));

    if (! is_resource($process)) {
        return [
            'command' => ['dw', ...$fullArgs],
            'exit_code' => null,
            'status' => 'failed_to_start',
            'error' => 'could not start official CLI process',
            'parsed_json' => null,
            'json_parse_error' => null,
        ];
    }

    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }
    $stdout = isset($pipes[1]) && is_resource($pipes[1]) ? stream_get_contents($pipes[1]) : '';
    $stderr = isset($pipes[2]) && is_resource($pipes[2]) ? stream_get_contents($pipes[2]) : '';
    if (isset($pipes[1]) && is_resource($pipes[1])) {
        fclose($pipes[1]);
    }
    if (isset($pipes[2]) && is_resource($pipes[2])) {
        fclose($pipes[2]);
    }
    $exitCode = proc_close($process);
    $parsed = parse_cli_json_output(is_string($stdout) ? $stdout : '');

    return [
        'command' => ['dw', ...$fullArgs],
        'exit_code' => is_int($exitCode) ? $exitCode : process_status_code($exitCode),
        'status' => $exitCode === 0 ? 'completed' : 'failed',
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
        'parsed_json' => $parsed['value'],
        'json_parse_error' => $parsed['error'],
    ];
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
    $observationPath = getenv('DW_ACTIVITIES_WORKFLOW_EXECUTION_OBSERVATION') ?: '';
    if ($observationPath === '') {
        throw new RuntimeException('Workflow execution observation path is not configured');
    }
    write_json_file($observationPath, [
        'schema' => 'durable-workflow.v2.activity-runtime.distribution-execution-observation',
        'component' => 'workflow',
        'package' => 'durable-workflow/workflow',
        'version' => getenv('DW_WORKFLOW_PHP_VERSION') ?: 'unknown',
        'class' => WorkflowFiberRunner::class,
        'method' => 'step',
        'source_file' => 'src/V2/Support/WorkflowFiberRunner.php',
        'workflow_task_id' => $task['task_id'] ?? null,
        'observed_command_count' => count($step->commands),
    ]);

    return request_json('POST', '/worker/workflow-tasks/'.rawurlencode((string) $task['task_id']).'/complete', [
        'lease_owner' => $task['lease_owner'],
        'workflow_task_attempt' => $task['workflow_task_attempt'] ?? 1,
        'commands' => $step->commands,
    ]);
}

function scenario_task_queue(string $identity): string
{
    $normalized = preg_replace('/[^a-z0-9-]+/', '-', strtolower($identity));
    $normalized = is_string($normalized) ? trim($normalized, '-') : '';
    if ($normalized === '') {
        throw new RuntimeException('activities conformance scenario task queue identity must not be empty');
    }

    return substr("activities-isolated-{$normalized}", 0, 191);
}

function register_worker(
    string $workerId,
    string $taskQueue,
    array $workflowTypes,
    array $activityTypes,
    string $runtime,
): void
{
    $workerRuntime = $runtime === 'sdk-python' ? 'python' : 'php';
    $sdkVersion = $runtime === 'sdk-python'
        ? 'durable-workflow-python/'.(getenv('DW_PYTHON_SDK_VERSION') ?: 'unknown')
        : 'durable-workflow/server:published-artifact';

    request_json('POST', '/worker/register', ActivitiesConformanceWorkerRegistration::payload(
        $workerId,
        $taskQueue,
        $workerRuntime,
        $sdkVersion,
        $workflowTypes,
        $activityTypes,
        [
            'memory_bytes' => memory_get_usage(true),
            'process_uptime_seconds' => 0,
            'process_id' => getmypid() ?: 0,
            'host' => gethostname() ?: 'published-server-container',
            'process_started_at' => now_iso(),
        ],
    ));
}

function python_activity_executor_script(): string
{
    return <<<'PY'
import importlib.metadata as metadata
import json
import os
import re
import sys
import time

import durable_workflow
from durable_workflow import serializer


def python_release_identity(version):
    stable = re.fullmatch(r"(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)", version)
    if stable:
        return version
    semver = re.fullmatch(
        r"(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)-(alpha|beta|rc)\.(0|[1-9]\d*)",
        version,
        re.IGNORECASE,
    )
    if semver:
        major, minor, patch, prerelease, ordinal = semver.groups()
        phase = {"alpha": "a", "beta": "b", "rc": "rc"}[prerelease.lower()]
        return f"{major}.{minor}.{patch}{phase}{ordinal}"
    pep440 = re.fullmatch(
        r"(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(a|b|rc)(0|[1-9]\d*)",
        version,
        re.IGNORECASE,
    )
    if not pep440:
        return None
    major, minor, patch, prerelease, ordinal = pep440.groups()
    return f"{major}.{minor}.{patch}{prerelease.lower()}{ordinal}"


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
if expected_version and python_release_identity(package_version) != python_release_identity(expected_version):
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

function assert_task_identity(array $task, array $expectedIdentity, string $context): void
{
    if ($expectedIdentity === []) {
        throw new RuntimeException("{$context} poll did not declare an expected execution identity");
    }

    $actualIdentity = [
        'workflow_id' => $task['workflow_id'] ?? ($task['workflow_instance_id'] ?? null),
        'run_id' => $task['run_id'] ?? ($task['workflow_run_id'] ?? null),
        'activity_execution_id' => $task['activity_execution_id'] ?? null,
    ];
    foreach ($expectedIdentity as $field => $expected) {
        if (! is_string($expected) || $expected === '') {
            throw new RuntimeException("{$context} expected {$field} must be a non-empty string");
        }
        if (($actualIdentity[$field] ?? null) !== $expected) {
            throw new RuntimeException(sprintf(
                '%s leased a task for the wrong execution: expected %s=%s, received %s',
                $context,
                $field,
                $expected,
                json_encode($actualIdentity[$field] ?? null),
            ));
        }
    }
}

function poll_task(
    string $kind,
    string $workerId,
    string $taskQueue,
    array $expectedIdentity,
): array
{
    $path = $kind === 'workflow'
        ? '/worker/workflow-tasks/poll'
        : '/worker/activity-tasks/poll';
    $response = request_json('POST', $path, [
        'worker_id' => $workerId,
        'task_queue' => $taskQueue,
    ]);
    $task = $response['task'] ?? null;
    if (! is_array($task)) {
        throw new RuntimeException("expected {$kind} task but poll returned ".json_encode($response));
    }
    assert_task_identity($task, $expectedIdentity, "{$kind} worker {$workerId}");

    return $task;
}

function assert_activity_handle_identity(
    array $handle,
    string $activityId,
    string $runId,
    string $activityExecutionId,
    string $context,
): void {
    $expected = [
        'activity_id' => $activityId,
        'workflow_run_id' => $runId,
        'activity_execution_id' => $activityExecutionId,
    ];
    foreach ($expected as $field => $value) {
        if (($handle[$field] ?? null) !== $value) {
            throw new RuntimeException("{$context} returned a terminal handle for the wrong {$field}");
        }
    }
}

function activity_execution_id_for_run(string $runId, string $context): string
{
    $activityExecutionId = ActivityExecution::query()
        ->where('workflow_run_id', $runId)
        ->orderBy('sequence')
        ->value('id');
    if (! is_string($activityExecutionId) || $activityExecutionId === '') {
        throw new RuntimeException("{$context} did not schedule an activity execution before its activity poll");
    }

    return $activityExecutionId;
}

function activity_input(array $task, string $codec): array
{
    $arguments = decode_payload($task['arguments'] ?? null, $codec);
    $payload = is_array($arguments) && array_is_list($arguments) ? ($arguments[0] ?? []) : $arguments;

    return is_array($payload) ? $payload : [];
}

function activity_completion_payload(array $task, string $runtime, string $mode): array
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

        return [$result, $python['result_envelope'], $workerArtifact];
    }

    return [$result, envelope($result, $codec), $workerArtifact];
}

function complete_activity_task(array $task, string $runtime, string $mode): array
{
    [$result, $resultEnvelope, $workerArtifact] = activity_completion_payload($task, $runtime, $mode);
    $response = request_json('POST', '/worker/activity-tasks/'.rawurlencode((string) $task['task_id']).'/complete', [
        'activity_attempt_id' => $task['activity_attempt_id'] ?? '',
        'lease_owner' => $task['lease_owner'],
        'result' => $resultEnvelope,
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

function history_payload_for_execution(array $history, string $eventType, string $activityExecutionId): array
{
    foreach (history_payloads_for_event($history, $eventType) as $payload) {
        if (($payload['activity_execution_id'] ?? null) === $activityExecutionId) {
            return $payload;
        }
    }

    return [];
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
    $taskQueue = scenario_task_queue($workflowId);

    register_worker($workerId, $taskQueue, [EMBEDDED_WORKFLOW_TYPE], [ACTIVITY_TYPE], $runtime);
    $start = request_json('POST', '/workflows', [
        'workflow_id' => $workflowId,
        'workflow_type' => EMBEDDED_WORKFLOW_TYPE,
        'task_queue' => $taskQueue,
        'input' => [[
            'scenario_id' => 'workflow_embedded_activity_result',
            'runtime' => $runtime,
            'input_marker' => "embedded-{$safeRuntime}",
            'task_queue' => $taskQueue,
        ]],
    ]);
    $runId = (string) ($start['run_id'] ?? '');
    $expectedIdentity = ['workflow_id' => $workflowId, 'run_id' => $runId];

    $workflowTask = poll_task('workflow', $workerId, $taskQueue, $expectedIdentity);
    complete_workflow_task_from_runtime($workflowTask);

    $activityExecutionId = activity_execution_id_for_run($runId, "workflow embedded {$runtime}");
    $activityTask = poll_task('activity', $workerId, $taskQueue, [
        ...$expectedIdentity,
        'activity_execution_id' => $activityExecutionId,
    ]);
    [$activityResult, $activityComplete, $workerArtifact] = complete_activity_task($activityTask, $runtime, 'workflow-embedded');

    $resumeTask = poll_task('workflow', $workerId, $taskQueue, $expectedIdentity);
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
        'task_queue' => $taskQueue,
        'activity_execution_id' => $activityExecutionId,
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
    $taskQueue = scenario_task_queue($activityId);

    register_worker($workerId, $taskQueue, [], [ACTIVITY_TYPE], $runtime);
    $start = request_json('POST', '/activities', [
        'activity_id' => $activityId,
        'activity_type' => ACTIVITY_TYPE,
        'task_queue' => $taskQueue,
        'input' => [[
            'scenario_id' => 'standalone_activity_result',
            'runtime' => $runtime,
            'input_marker' => "standalone-{$safeRuntime}",
        ]],
    ]);
    $runId = (string) ($start['workflow_run_id'] ?? '');
    $activityExecutionId = (string) ($start['activity_execution_id'] ?? '');

    $activityTask = poll_task('activity', $workerId, $taskQueue, [
        'workflow_id' => $activityId,
        'run_id' => $runId,
        'activity_execution_id' => $activityExecutionId,
    ]);
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
        'task_queue' => $taskQueue,
        'activity_execution_id' => $activityExecutionId,
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
            'last_heartbeat_at' => $attempt->last_heartbeat_at?->toJSON(),
            'last_heartbeat_progress' => $attempt->getAttribute('last_heartbeat_progress'),
            'lease_expires_at' => $attempt->lease_expires_at?->toJSON(),
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

function heartbeat_activity_task(array $task, array $progress, array $allowed = []): array
{
    return request_json(
        'POST',
        '/worker/activity-tasks/'.rawurlencode((string) $task['task_id']).'/heartbeat',
        [
            'activity_attempt_id' => $task['activity_attempt_id'] ?? '',
            'lease_owner' => $task['lease_owner'] ?? '',
            ...$progress,
        ],
        $allowed,
    );
}

function workflow_run_or_fail(string $runId): WorkflowRun
{
    /** @var WorkflowRun|null $run */
    $run = WorkflowRun::query()
        ->with(['activityExecutions.attempts', 'historyEvents'])
        ->find($runId);

    if (! $run instanceof WorkflowRun) {
        throw new RuntimeException("workflow run {$runId} was not found");
    }

    return $run;
}

function activity_execution_state(string $activityExecutionId): ?array
{
    /** @var ActivityExecution|null $execution */
    $execution = ActivityExecution::query()->find($activityExecutionId);
    if (! $execution instanceof ActivityExecution) {
        return null;
    }

    return [
        'activity_execution_id' => $execution->id,
        'workflow_run_id' => $execution->workflow_run_id,
        'activity_type' => $execution->activity_type,
        'status' => $execution->status instanceof BackedEnum ? $execution->status->value : (string) $execution->status,
        'attempt_count' => $execution->attempt_count,
        'current_attempt_id' => $execution->current_attempt_id,
        'last_heartbeat_at' => $execution->last_heartbeat_at?->toJSON(),
        'heartbeat_deadline_at' => $execution->heartbeat_deadline_at?->toJSON(),
        'started_at' => $execution->started_at?->toJSON(),
        'closed_at' => $execution->closed_at?->toJSON(),
        'attempts' => attempt_snapshots($activityExecutionId),
    ];
}

function run_waterline_activity_views(string $runId): array
{
    $run = workflow_run_or_fail($runId);
    $packageRoot = realpath(getenv('DW_ACTIVITIES_WATERLINE_PACKAGE_ROOT') ?: '');
    $reflection = new ReflectionClass(CompensationVisibility::class);
    $classFile = realpath($reflection->getFileName() ?: '');
    if ($packageRoot === false
        || $classFile === false
        || ! str_starts_with($classFile, rtrim($packageRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Waterline activity projection did not load from the exact installed package');
    }

    $activities = CompensationVisibility::activitiesForRun($run);
    $observationPath = getenv('DW_ACTIVITIES_WATERLINE_EXECUTION_OBSERVATION') ?: '';
    if ($observationPath === '') {
        throw new RuntimeException('Waterline execution observation path is not configured');
    }
    write_json_file($observationPath, [
        'schema' => 'durable-workflow.v2.activity-runtime.distribution-execution-observation',
        'component' => 'waterline',
        'package' => 'durable-workflow/waterline',
        'version' => getenv('DW_WATERLINE_VERSION') ?: 'unknown',
        'class' => CompensationVisibility::class,
        'method' => 'activitiesForRun',
        'source_file' => 'app/Support/CompensationVisibility.php',
        'workflow_run_id' => $runId,
        'observed_activity_count' => count($activities),
    ]);

    return $activities;
}

function activity_view_for_execution(array $activities, string $activityExecutionId): array
{
    foreach ($activities as $activity) {
        if (! is_array($activity)) {
            continue;
        }
        if (($activity['id'] ?? null) === $activityExecutionId) {
            return $activity;
        }
    }

    return [];
}

function current_lease_for_attempt(array $taskQueueDetail, string $activityAttemptId): array
{
    $leases = is_array($taskQueueDetail['current_leases'] ?? null) ? $taskQueueDetail['current_leases'] : [];
    foreach ($leases as $lease) {
        if (! is_array($lease)) {
            continue;
        }
        if (($lease['activity_attempt_id'] ?? null) === $activityAttemptId) {
            return $lease;
        }
    }

    return [];
}

function latest_attempt_snapshot(array $attempts): array
{
    $latest = [];
    foreach ($attempts as $attempt) {
        if (is_array($attempt)) {
            $latest = $attempt;
        }
    }

    return $latest;
}

function cancelled_or_failed_activity_status(mixed $value): bool
{
    return is_string($value) && in_array($value, ['cancelled', 'failed'], true);
}

function same_activity_payload_shape(array $left, array $right): array
{
    $keys = ['message', 'mode', 'input_marker', 'activity_type'];
    $matches = [];
    foreach ($keys as $key) {
        $matches[$key] = array_key_exists($key, $left)
            && array_key_exists($key, $right)
            && $left[$key] === $right[$key];
    }

    return [
        'checked_fields' => $keys,
        'field_matches' => $matches,
        'matches' => ! in_array(false, $matches, true),
    ];
}

function same_observation_shape(array $left, array $right, array $keys): array
{
    $matches = [];
    foreach ($keys as $key) {
        $matches[$key] = array_key_exists($key, $left)
            && array_key_exists($key, $right)
            && $left[$key] === $right[$key];
    }

    return [
        'checked_fields' => $keys,
        'field_matches' => $matches,
        'matches' => ! in_array(false, $matches, true),
    ];
}

function workflow_php_worker_artifact(): array
{
    return [
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
}

function worker_artifact_probe(array $task, string $runtime, string $mode): array
{
    if ($runtime === 'sdk-python') {
        $python = run_python_activity_executor($task, $mode);

        return is_array($python['worker_artifact'] ?? null) ? $python['worker_artifact'] : [];
    }

    return workflow_php_worker_artifact();
}

function start_parity_activity(string $runtime, string $suffix, string $observation, array $options = []): array
{
    $safeRuntime = str_replace(['/', '_'], '-', $runtime);
    $workerId = "activities-parity-{$observation}-{$safeRuntime}-{$suffix}";
    $activityId = "activities-parity-{$observation}-{$safeRuntime}-{$suffix}";
    $taskQueue = scenario_task_queue($activityId);
    $inputMarker = "parity-{$observation}-{$suffix}";

    register_worker($workerId, $taskQueue, [], [ACTIVITY_TYPE], $runtime);
    $start = request_json('POST', '/activities', [
        'activity_id' => $activityId,
        'activity_type' => ACTIVITY_TYPE,
        'task_queue' => $taskQueue,
        'input' => [[
            'scenario_id' => 'php_python_activity_parity',
            'runtime' => $runtime,
            'observation' => $observation,
            'input_marker' => $inputMarker,
        ]],
        ...$options,
    ]);
    $runId = (string) ($start['workflow_run_id'] ?? '');
    $activityExecutionId = (string) ($start['activity_execution_id'] ?? '');
    if ($runId === '' || $activityExecutionId === '') {
        throw new RuntimeException("parity {$observation} {$runtime} activity start did not return execution and run identifiers");
    }

    return [
        'runtime' => $runtime,
        'worker_id' => $workerId,
        'task_queue' => $taskQueue,
        'activity_id' => $activityId,
        'workflow_run_id' => $runId,
        'activity_execution_id' => $activityExecutionId,
        'input_marker' => $inputMarker,
        'task' => poll_task('activity', $workerId, $taskQueue, [
            'workflow_id' => $activityId,
            'run_id' => $runId,
            'activity_execution_id' => $activityExecutionId,
        ]),
    ];
}

function parity_failure_shape(array $history, string $activityExecutionId): array
{
    $payload = history_payload_for_execution(
        $history,
        HistoryEventType::ActivityFailed->value,
        $activityExecutionId,
    );
    $exception = is_array($payload['exception'] ?? null) ? $payload['exception'] : [];

    return [
        'activity_execution_id' => $payload['activity_execution_id'] ?? null,
        'exception_type' => $payload['exception_type'] ?? ($exception['type'] ?? null),
        'message' => $payload['message'] ?? ($exception['message'] ?? null),
        'failure_category' => $payload['failure_category'] ?? null,
        'non_retryable' => $exception['non_retryable'] ?? ($payload['non_retryable'] ?? null),
    ];
}

function run_parity_result_observation(string $runtime, string $suffix): array
{
    $activity = start_parity_activity($runtime, $suffix, 'result');
    [$result, $complete, $workerArtifact] = complete_activity_task($activity['task'], $runtime, 'standalone');
    $show = request_json('GET', '/activities/'.rawurlencode($activity['activity_id']));
    $history = request_json('GET', '/workflows/'.rawurlencode($activity['activity_id']).'/runs/'.rawurlencode($activity['workflow_run_id']).'/history');
    assert_activity_handle_identity($show, $activity['activity_id'], $activity['workflow_run_id'], $activity['activity_execution_id'], "parity result {$runtime}");
    $pass = ($show['status'] ?? null) === RunStatus::Completed->value
        && ($complete['recorded'] ?? null) === true
        && ($result['runtime'] ?? null) === $runtime
        && ($result['input_marker'] ?? null) === $activity['input_marker'];
    if (! $pass) {
        throw new RuntimeException("parity result observation {$runtime} did not complete");
    }

    return [
        'runtime' => $runtime,
        'status' => 'pass',
        'activity_id' => $activity['activity_id'],
        'workflow_run_id' => $activity['workflow_run_id'],
        'activity_execution_id' => $activity['activity_execution_id'],
        'activity_attempt_id' => $activity['task']['activity_attempt_id'] ?? null,
        'result_payload' => $result,
        'completion_response' => $complete,
        'handle_response' => $show,
        'history_events' => event_types($history),
        'worker_artifact' => $workerArtifact,
    ];
}

function run_parity_failure_observation(string $runtime, string $suffix): array
{
    $activity = start_parity_activity($runtime, $suffix, 'failure', [
        'retry_policy' => ['max_attempts' => 1, 'backoff_seconds' => [0]],
    ]);
    $workerArtifact = worker_artifact_probe($activity['task'], $runtime, 'standalone');
    $failure = [
        'message' => 'activities parity failure shape',
        'type' => 'ActivitiesConformanceParityFailure',
        'class' => 'DurableWorkflow\\Conformance\\Activities\\ParityFailure',
        'code' => 503,
        'non_retryable' => true,
        'retryable' => false,
        'details' => envelope([
            'failure_code' => 'ACTIVITY_PARITY_FAILURE',
            'observation' => 'failure',
        ], task_codec($activity['task'])),
    ];
    $failResponse = fail_activity_task($activity['task'], $failure);
    $show = request_json('GET', '/activities/'.rawurlencode($activity['activity_id']));
    $history = request_json('GET', '/workflows/'.rawurlencode($activity['activity_id']).'/runs/'.rawurlencode($activity['workflow_run_id']).'/history');
    assert_activity_handle_identity($show, $activity['activity_id'], $activity['workflow_run_id'], $activity['activity_execution_id'], "parity failure {$runtime}");
    $failureShape = parity_failure_shape($history, $activity['activity_execution_id']);
    $pass = ($failResponse['recorded'] ?? null) === true
        && ($show['status'] ?? null) === RunStatus::Failed->value
        && ($show['activity_status'] ?? null) === ActivityStatus::Failed->value
        && ($failureShape['exception_type'] ?? null) === 'ActivitiesConformanceParityFailure'
        && ($failureShape['message'] ?? null) === 'activities parity failure shape';
    if (! $pass) {
        throw new RuntimeException("parity failure observation {$runtime} did not preserve failure shape");
    }

    return [
        'runtime' => $runtime,
        'status' => 'pass',
        'activity_id' => $activity['activity_id'],
        'workflow_run_id' => $activity['workflow_run_id'],
        'activity_execution_id' => $activity['activity_execution_id'],
        'activity_attempt_id' => $activity['task']['activity_attempt_id'] ?? null,
        'failure_payload' => $failure,
        'failure_response' => $failResponse,
        'failure_shape' => $failureShape,
        'handle_response' => $show,
        'history_events' => event_types($history),
        'activity_failed_history_events' => history_payloads_for_event($history, HistoryEventType::ActivityFailed->value),
        'worker_artifact' => $workerArtifact,
    ];
}

function run_parity_retry_observation(string $runtime, string $suffix): array
{
    $retryPolicy = ['max_attempts' => 2, 'backoff_seconds' => [0]];
    $activity = start_parity_activity($runtime, $suffix, 'retry', [
        'retry_policy' => $retryPolicy,
    ]);
    $firstTask = $activity['task'];
    $workerArtifact = worker_artifact_probe($firstTask, $runtime, 'standalone');
    $failure = [
        'message' => 'activities parity retryable failure',
        'type' => 'ActivitiesConformanceParityRetryableFailure',
        'retryable' => true,
        'non_retryable' => false,
    ];
    $failResponse = fail_activity_task($firstTask, $failure);
    $nextTaskId = is_string($failResponse['next_task_id'] ?? null) ? $failResponse['next_task_id'] : '';
    if ($nextTaskId === '') {
        throw new RuntimeException("parity retry observation {$runtime} did not schedule a retry task");
    }
    $retryAvailableAt = workflow_task_available_at($nextTaskId);
    $retryAvailableTimestamp = timestamp_from_datetime($retryAvailableAt);
    if ($retryAvailableTimestamp !== null) {
        wait_until_timestamp($retryAvailableTimestamp);
    }
    $secondTask = poll_task('activity', $activity['worker_id'], $activity['task_queue'], [
        'workflow_id' => $activity['activity_id'],
        'run_id' => $activity['workflow_run_id'],
        'activity_execution_id' => $activity['activity_execution_id'],
    ]);
    [$result, $complete, $completionArtifact] = complete_activity_task($secondTask, $runtime, 'standalone');
    if ($runtime === 'sdk-python') {
        $workerArtifact = $completionArtifact;
    }
    $show = request_json('GET', '/activities/'.rawurlencode($activity['activity_id']));
    $history = request_json('GET', '/workflows/'.rawurlencode($activity['activity_id']).'/runs/'.rawurlencode($activity['workflow_run_id']).'/history');
    assert_activity_handle_identity($show, $activity['activity_id'], $activity['workflow_run_id'], $activity['activity_execution_id'], "parity retry {$runtime}");
    $pass = ($firstTask['activity_execution_id'] ?? null) === ($secondTask['activity_execution_id'] ?? null)
        && (int) ($firstTask['attempt_number'] ?? 0) === 1
        && (int) ($secondTask['attempt_number'] ?? 0) === 2
        && ($firstTask['activity_attempt_id'] ?? null) !== ($secondTask['activity_attempt_id'] ?? null)
        && ($show['status'] ?? null) === RunStatus::Completed->value
        && ($complete['recorded'] ?? null) === true
        && ($result['runtime'] ?? null) === $runtime
        && ($result['input_marker'] ?? null) === $activity['input_marker'];
    if (! $pass) {
        throw new RuntimeException("parity retry observation {$runtime} did not retry then complete on attempt two");
    }

    return [
        'runtime' => $runtime,
        'status' => 'pass',
        'activity_id' => $activity['activity_id'],
        'workflow_run_id' => $activity['workflow_run_id'],
        'activity_execution_id' => $activity['activity_execution_id'],
        'configured_retry_policy' => $retryPolicy,
        'attempt_numbers' => [
            (int) ($firstTask['attempt_number'] ?? 0),
            (int) ($secondTask['attempt_number'] ?? 0),
        ],
        'attempt_ids' => [
            $firstTask['activity_attempt_id'] ?? null,
            $secondTask['activity_attempt_id'] ?? null,
        ],
        'failure_response' => $failResponse,
        'completion_response' => $complete,
        'result_payload' => $result,
        'handle_response' => $show,
        'attempt_state' => attempt_snapshots($activity['activity_execution_id']),
        'history_events' => event_types($history),
        'retry_history_events' => history_payloads_for_event($history, 'ActivityRetryScheduled'),
        'worker_artifact' => $workerArtifact,
    ];
}

function run_parity_timeout_observation(string $runtime, string $suffix): array
{
    $activity = start_parity_activity($runtime, $suffix, 'timeout', [
        'start_to_close_timeout_seconds' => 1,
        'schedule_to_close_timeout_seconds' => 30,
        'retry_policy' => ['max_attempts' => 1, 'backoff_seconds' => [0]],
    ]);
    $task = $activity['task'];
    $workerArtifact = worker_artifact_probe($task, $runtime, 'standalone');
    $deadlines = is_array($task['deadlines'] ?? null) ? $task['deadlines'] : [];
    $deadlineTimestamp = timestamp_from_datetime(is_string($deadlines['start_to_close'] ?? null) ? $deadlines['start_to_close'] : null);
    if ($deadlineTimestamp === null) {
        throw new RuntimeException("parity timeout observation {$runtime} did not expose a start-to-close deadline");
    }
    wait_until_timestamp($deadlineTimestamp + 0.20);
    $statusBefore = request_json('GET', '/system/activity-timeouts');
    $expiredIds = is_array($statusBefore['expired_execution_ids'] ?? null) ? $statusBefore['expired_execution_ids'] : [];
    if (! in_array($activity['activity_execution_id'], $expiredIds, true)) {
        wait_until_timestamp($deadlineTimestamp + 0.60);
    }
    $enforceResponse = request_json('POST', '/system/activity-timeouts/pass', [
        'execution_ids' => [$activity['activity_execution_id']],
    ]);
    $show = request_json('GET', '/activities/'.rawurlencode($activity['activity_id']));
    $history = request_json('GET', '/workflows/'.rawurlencode($activity['activity_id']).'/runs/'.rawurlencode($activity['workflow_run_id']).'/history');
    assert_activity_handle_identity($show, $activity['activity_id'], $activity['workflow_run_id'], $activity['activity_execution_id'], "parity timeout {$runtime}");
    $timeoutPayload = history_payload_for_execution(
        $history,
        HistoryEventType::ActivityTimedOut->value,
        $activity['activity_execution_id'],
    );
    $pass = ($enforceResponse['enforced'] ?? null) === 1
        && ($timeoutPayload['timeout_kind'] ?? null) === 'start_to_close'
        && ($show['status'] ?? null) === RunStatus::Failed->value
        && ($show['closed_reason'] ?? null) === 'timed_out';
    if (! $pass) {
        throw new RuntimeException("parity timeout observation {$runtime} did not enforce typed start-to-close timeout");
    }

    return [
        'runtime' => $runtime,
        'status' => 'pass',
        'activity_id' => $activity['activity_id'],
        'workflow_run_id' => $activity['workflow_run_id'],
        'activity_execution_id' => $activity['activity_execution_id'],
        'activity_attempt_id' => $task['activity_attempt_id'] ?? null,
        'worker_visible_deadlines' => $deadlines,
        'timeout_status_before_enforce' => $statusBefore,
        'enforce_response' => $enforceResponse,
        'timeout_shape' => [
            'timeout_kind' => $timeoutPayload['timeout_kind'] ?? null,
            'failure_category' => $timeoutPayload['failure_category'] ?? null,
            'exception_class' => $timeoutPayload['exception_class'] ?? null,
            'activity_execution_id' => $timeoutPayload['activity_execution_id'] ?? null,
        ],
        'handle_response' => $show,
        'attempt_state' => attempt_snapshots($activity['activity_execution_id']),
        'history_events' => event_types($history),
        'timeout_history_events' => history_payloads_for_event($history, HistoryEventType::ActivityTimedOut->value),
        'worker_artifact' => $workerArtifact,
    ];
}

function run_parity_heartbeat_observation(string $runtime, string $suffix): array
{
    $activity = start_parity_activity($runtime, $suffix, 'heartbeat', [
        'heartbeat_timeout_seconds' => 30,
        'schedule_to_close_timeout_seconds' => 120,
    ]);
    $task = $activity['task'];
    $workerArtifact = worker_artifact_probe($task, $runtime, 'standalone');
    $heartbeat = heartbeat_activity_task($task, [
        'message' => 'parity heartbeat',
        'current' => 1,
        'total' => 1,
        'unit' => 'step',
        'details' => ['runtime' => $runtime, 'observation' => 'heartbeat'],
    ]);
    [$result, $complete, $completionArtifact] = complete_activity_task($task, $runtime, 'standalone');
    if ($runtime === 'sdk-python') {
        $workerArtifact = $completionArtifact;
    }
    $show = request_json('GET', '/activities/'.rawurlencode($activity['activity_id']));
    $history = request_json('GET', '/workflows/'.rawurlencode($activity['activity_id']).'/runs/'.rawurlencode($activity['workflow_run_id']).'/history');
    assert_activity_handle_identity($show, $activity['activity_id'], $activity['workflow_run_id'], $activity['activity_execution_id'], "parity heartbeat {$runtime}");
    $heartbeatPayload = history_payload_for_execution(
        $history,
        HistoryEventType::ActivityHeartbeatRecorded->value,
        $activity['activity_execution_id'],
    );
    $pass = ($heartbeat['heartbeat_recorded'] ?? null) === true
        && ($heartbeat['cancel_requested'] ?? null) === false
        && is_array($heartbeatPayload)
        && ($show['status'] ?? null) === RunStatus::Completed->value
        && ($result['runtime'] ?? null) === $runtime
        && ($result['input_marker'] ?? null) === $activity['input_marker'];
    if (! $pass) {
        throw new RuntimeException("parity heartbeat observation {$runtime} did not record heartbeat then complete");
    }

    return [
        'runtime' => $runtime,
        'status' => 'pass',
        'activity_id' => $activity['activity_id'],
        'workflow_run_id' => $activity['workflow_run_id'],
        'activity_execution_id' => $activity['activity_execution_id'],
        'activity_attempt_id' => $task['activity_attempt_id'] ?? null,
        'heartbeat_response' => $heartbeat,
        'heartbeat_shape' => [
            'heartbeat_recorded' => $heartbeat['heartbeat_recorded'] ?? null,
            'cancel_requested' => $heartbeat['cancel_requested'] ?? null,
            'history_event_type' => HistoryEventType::ActivityHeartbeatRecorded->value,
        ],
        'heartbeat_history_event' => $heartbeatPayload,
        'completion_response' => $complete,
        'result_payload' => $result,
        'handle_response' => $show,
        'history_events' => event_types($history),
        'worker_artifact' => $workerArtifact,
    ];
}

function run_parity_cancellation_observation(string $runtime, string $suffix): array
{
    $activity = start_parity_activity($runtime, $suffix, 'cancellation', [
        'heartbeat_timeout_seconds' => 30,
        'schedule_to_close_timeout_seconds' => 120,
    ]);
    $task = $activity['task'];
    heartbeat_activity_task($task, [
        'message' => 'parity cancellation preflight heartbeat',
        'details' => ['runtime' => $runtime, 'observation' => 'cancellation'],
    ]);
    $cancelResponse = request_json('POST', '/workflows/'.rawurlencode($activity['activity_id']).'/runs/'.rawurlencode($activity['workflow_run_id']).'/cancel', [
        'reason' => 'activities parity cancellation observation',
    ]);
    $cancelHeartbeat = heartbeat_activity_task($task, [
        'message' => 'parity cancellation check',
        'details' => ['runtime' => $runtime, 'observation' => 'cancellation'],
    ]);
    [$lateResult, $lateEnvelope, $workerArtifact] = activity_completion_payload($task, $runtime, 'standalone');
    $lateCompletion = request_json(
        'POST',
        '/worker/activity-tasks/'.rawurlencode((string) $task['task_id']).'/complete',
        [
            'activity_attempt_id' => $task['activity_attempt_id'] ?? '',
            'lease_owner' => $task['lease_owner'],
            'result' => $lateEnvelope,
        ],
        [409],
    );
    $show = request_json('GET', '/activities/'.rawurlencode($activity['activity_id']));
    $history = request_json('GET', '/workflows/'.rawurlencode($activity['activity_id']).'/runs/'.rawurlencode($activity['workflow_run_id']).'/history');
    assert_activity_handle_identity($show, $activity['activity_id'], $activity['workflow_run_id'], $activity['activity_execution_id'], "parity cancellation {$runtime}");
    $attemptState = attempt_snapshots($activity['activity_execution_id']);
    $latestAttempt = latest_attempt_snapshot($attemptState);
    $terminalState = ($lateCompletion['outcome'] ?? null) === 'ignored'
        && ($lateCompletion['reason'] ?? null) === 'run_cancelled'
        && ($lateCompletion['run_status'] ?? null) === RunStatus::Cancelled->value
        && cancelled_or_failed_activity_status($lateCompletion['activity_status'] ?? null)
        && cancelled_or_failed_activity_status($latestAttempt['status'] ?? null);
    $pass = ($cancelHeartbeat['cancel_requested'] ?? null) === true
        && ($cancelHeartbeat['can_continue'] ?? null) === false
        && ($lateResult['runtime'] ?? null) === $runtime
        && ($lateResult['input_marker'] ?? null) === $activity['input_marker']
        && $terminalState;
    if (! $pass) {
        throw new RuntimeException("parity cancellation observation {$runtime} did not expose cancel_requested and terminal cancelled state");
    }

    return [
        'runtime' => $runtime,
        'status' => 'pass',
        'activity_id' => $activity['activity_id'],
        'workflow_run_id' => $activity['workflow_run_id'],
        'activity_execution_id' => $activity['activity_execution_id'],
        'activity_attempt_id' => $task['activity_attempt_id'] ?? null,
        'cancel_response' => $cancelResponse,
        'cancel_requested_response' => $cancelHeartbeat,
        'late_completion_after_cancel_response' => $lateCompletion,
        'late_completion_after_cancel_result' => $lateResult,
        'cancellation_shape' => [
            'cancel_requested' => $cancelHeartbeat['cancel_requested'] ?? null,
            'can_continue' => $cancelHeartbeat['can_continue'] ?? null,
            'reason' => $cancelHeartbeat['reason'] ?? null,
            'run_status' => $lateCompletion['run_status'] ?? null,
            'activity_status' => $lateCompletion['activity_status'] ?? null,
            'attempt_status' => $lateCompletion['attempt_status'] ?? null,
            'task_status' => $lateCompletion['task_status'] ?? null,
        ],
        'terminal_cancellation_state' => $terminalState,
        'handle_response' => $show,
        'attempt_state' => $attemptState,
        'history_events' => event_types($history),
        'worker_artifact' => $workerArtifact,
    ];
}

function list_activity_entry(array $listResponse, string $activityId): array
{
    $activities = is_array($listResponse['activities'] ?? null) ? $listResponse['activities'] : [];
    foreach ($activities as $activity) {
        if (is_array($activity) && ($activity['activity_id'] ?? null) === $activityId) {
            return $activity;
        }
    }

    return [];
}

function activity_list_evidence(string $activityId): array
{
    $all = request_json('GET', '/activities?page_size=200');
    $running = request_json('GET', '/activities?status=running&page_size=200');
    $completed = request_json('GET', '/activities?status=completed&page_size=200');
    $failed = request_json('GET', '/activities?status=failed&page_size=200');

    return [
        'all' => $all,
        'running' => $running,
        'completed' => $completed,
        'failed' => $failed,
        'selected' => [
            'all' => list_activity_entry($all, $activityId),
            'running' => list_activity_entry($running, $activityId),
            'completed' => list_activity_entry($completed, $activityId),
            'failed' => list_activity_entry($failed, $activityId),
        ],
    ];
}

function selected_activity_list_entry(array $listEvidence): array
{
    $selected = is_array($listEvidence['selected'] ?? null) ? $listEvidence['selected'] : [];

    foreach ($selected as $entry) {
        if (is_array($entry) && $entry !== []) {
            return $entry;
        }
    }

    return [];
}

function activity_attempts_visible_in_entry(array $entry, string $activityExecutionId, ?string $activityAttemptId): bool
{
    if (($entry['activity_execution_id'] ?? null) !== $activityExecutionId) {
        return false;
    }

    $attempts = is_array($entry['attempts'] ?? null) ? $entry['attempts'] : [];
    if ($attempts === []) {
        return false;
    }

    foreach ($attempts as $attempt) {
        if (! is_array($attempt)) {
            continue;
        }

        $attemptId = $attempt['activity_attempt_id'] ?? ($attempt['id'] ?? null);
        $status = $attempt['status'] ?? null;
        if (is_string($status) && $status !== ''
            && ($activityAttemptId === null || $attemptId === $activityAttemptId)) {
            return true;
        }
    }

    return false;
}

function cli_activity_json_contract_evidence(
    string $activityId,
    string $activityExecutionId,
    ?string $activityAttemptId
): array {
    $context = cli_observation_context();
    $listCommand = run_dw_json_command([
        'activity:list',
        '--output=json',
        '--limit=200',
    ], $context);
    $describeCommand = run_dw_json_command([
        'activity:describe',
        $activityId,
        '--output=json',
    ], $context);

    $listOutput = is_array($listCommand['parsed_json'] ?? null) ? $listCommand['parsed_json'] : [];
    $describeOutput = is_array($describeCommand['parsed_json'] ?? null) ? $describeCommand['parsed_json'] : [];
    $listEntry = list_activity_entry($listOutput, $activityId);
    $listVisible = ($listCommand['exit_code'] ?? null) === 0
        && ($listCommand['json_parse_error'] ?? null) === null
        && activity_attempts_visible_in_entry($listEntry, $activityExecutionId, $activityAttemptId);
    $detailVisible = ($describeCommand['exit_code'] ?? null) === 0
        && ($describeCommand['json_parse_error'] ?? null) === null
        && activity_attempts_visible_in_entry($describeOutput, $activityExecutionId, $activityAttemptId);
    $visible = $listVisible && $detailVisible;
    $unsupportedCommand = false;
    foreach ([$listCommand, $describeCommand] as $command) {
        $text = strtolower((string) ($command['stderr'] ?? '')."\n".(string) ($command['stdout'] ?? '')."\n".(string) ($command['error'] ?? ''));
        if (str_contains($text, 'command') && (
            str_contains($text, 'not defined')
            || str_contains($text, 'does not exist')
            || str_contains($text, 'unknown command')
            || str_contains($text, 'no commands defined')
        )) {
            $unsupportedCommand = true;
        }
    }
    $status = match (true) {
        $visible => 'pass',
        ($context['available'] ?? false) !== true => 'cli_not_exercised',
        $unsupportedCommand => 'unsupported_cli_command',
        ($listCommand['exit_code'] ?? null) !== 0 || ($describeCommand['exit_code'] ?? null) !== 0 => 'cli_command_failed',
        ($listCommand['json_parse_error'] ?? null) !== null || ($describeCommand['json_parse_error'] ?? null) !== null => 'cli_json_parse_failed',
        default => 'missing_contract_fields',
    };

    return [
        'artifact' => 'durable-workflow/cli',
        'artifact_version' => getenv('DW_CLI_VERSION') ?: 'unknown',
        'artifact_source' => $context['artifact_source'] ?? null,
        'expected_surface' => 'published CLI JSON list/detail evidence for activity attempt state',
        'status' => $status,
        'cli_list_visible' => $visible,
        'command_contracts' => [
            'list' => 'dw activity:list --output=json --limit=200',
            'describe' => 'dw activity:describe <activity-id> --output=json',
        ],
        'json_contract_source' => 'official published dw CLI JSON command output',
        'cli_observation_server' => [
            'available' => $context['available'] ?? false,
            'base_url' => $context['base_url'] ?? null,
            'server_log' => $context['server_log'] ?? null,
            'unavailable_reason' => $context['reason'] ?? null,
        ],
        'command_outputs' => [
            'list' => $listCommand,
            'describe' => $describeCommand,
        ],
        'selected_list_entry' => $listEntry,
        'detail_attempt_state' => [
            'activity_execution_id' => $describeOutput['activity_execution_id'] ?? null,
            'current_attempt_id' => $describeOutput['current_attempt_id'] ?? null,
            'current_attempt_status' => $describeOutput['current_attempt_status'] ?? null,
            'attempts' => $describeOutput['attempts'] ?? null,
        ],
        'observed_behavior' => $visible
            ? 'the official CLI activity list/detail JSON commands expose the activity execution id and attempt rows with attempt ids and statuses'
            : 'the official CLI activity list/detail JSON commands did not expose attempt rows with attempt ids and statuses for this state',
    ];
}

function operator_surface_snapshot(
    string $state,
    string $activityId,
    string $runId,
    string $activityExecutionId,
    string $taskQueue,
    ?string $activityAttemptId = null,
): array
{
    $apiDetail = request_json('GET', '/activities/'.rawurlencode($activityId));
    $apiRunDetail = request_json('GET', '/workflows/'.rawurlencode($activityId).'/runs/'.rawurlencode($runId));
    $history = request_json('GET', '/workflows/'.rawurlencode($activityId).'/runs/'.rawurlencode($runId).'/history');
    $taskQueueDetail = request_json('GET', '/task-queues/'.rawurlencode($taskQueue));
    $listEvidence = activity_list_evidence($activityId);
    $activityViews = run_waterline_activity_views($runId);
    $activityView = activity_view_for_execution($activityViews, $activityExecutionId);
    $attemptState = attempt_snapshots($activityExecutionId);
    $executionState = activity_execution_state($activityExecutionId);
    $currentLease = $activityAttemptId === null
        ? []
        : current_lease_for_attempt($taskQueueDetail, $activityAttemptId);
    $selectedListEntries = is_array($listEvidence['selected'] ?? null) ? $listEvidence['selected'] : [];
    $listVisible = array_reduce(
        $selectedListEntries,
        static fn (bool $carry, mixed $entry): bool => $carry || (is_array($entry) && $entry !== []),
        false,
    );
    $waterlineVisible = ($activityView['id'] ?? null) === $activityExecutionId
        && is_array($activityView['attempts'] ?? null)
        && ($activityView['attempts'] ?? []) !== [];
    $cliEvidence = cli_activity_json_contract_evidence(
        $activityId,
        $activityExecutionId,
        $activityAttemptId,
    );

    return [
        'state' => $state,
        'activity_id' => $activityId,
        'workflow_run_id' => $runId,
        'activity_execution_id' => $activityExecutionId,
        'activity_attempt_id' => $activityAttemptId,
        'api_detail' => $apiDetail,
        'api_run_detail' => $apiRunDetail,
        'api_list_evidence' => $listEvidence,
        'history_events' => event_types($history),
        'history_payloads' => [
            'activity_started' => history_payload_for_execution($history, HistoryEventType::ActivityStarted->value, $activityExecutionId),
            'activity_completed' => history_payload_for_execution($history, HistoryEventType::ActivityCompleted->value, $activityExecutionId),
            'activity_failed' => history_payload_for_execution($history, HistoryEventType::ActivityFailed->value, $activityExecutionId),
            'activity_timed_out' => history_payload_for_execution($history, HistoryEventType::ActivityTimedOut->value, $activityExecutionId),
            'activity_heartbeat_recorded' => history_payload_for_execution($history, HistoryEventType::ActivityHeartbeatRecorded->value, $activityExecutionId),
        ],
        'operator_metrics' => [
            'task_queue' => $taskQueue,
            'current_lease' => $currentLease,
            'stats' => $taskQueueDetail['stats'] ?? null,
            'admission' => $taskQueueDetail['admission'] ?? null,
        ],
        'waterline_activity_attempt_view' => [
            'surface' => 'Waterline selected run activity attempt view',
            'artifact' => 'durable-workflow/waterline',
            'artifact_version' => getenv('DW_WATERLINE_VERSION') ?: 'unknown',
            'artifact_source' => 'packagist://durable-workflow/waterline@'.(getenv('DW_WATERLINE_VERSION') ?: 'unknown'),
            'selected_run_detail_path' => '/waterline/api/instances/'.$activityId.'/runs/'.$runId,
            'projection_source' => 'Waterline\\Support\\CompensationVisibility::activitiesForRun',
            'activity_view' => $activityView,
            'waterline_visible' => $waterlineVisible,
        ],
        'cli_json_list_evidence' => $cliEvidence,
        'attempt_state' => $attemptState,
        'execution_state' => $executionState,
        'surface_visibility' => [
            'api_detail_visible' => ($apiDetail['activity_execution_id'] ?? null) === $activityExecutionId,
            'api_list_visible' => $listVisible,
            'history_visible' => in_array(HistoryEventType::ActivityStarted->value, event_types($history), true),
            'waterline_visible' => $waterlineVisible,
            'cli_list_visible' => ($cliEvidence['cli_list_visible'] ?? null) === true,
        ],
    ];
}

function start_operator_visibility_activity(string $suffix, string $state, array $options = []): array
{
    $workerId = "activities-operator-{$state}-{$suffix}";
    $activityId = "activities-operator-{$state}-{$suffix}";
    $taskQueue = scenario_task_queue($activityId);

    register_worker($workerId, $taskQueue, [], [ACTIVITY_TYPE], 'workflow-php');
    $start = request_json('POST', '/activities', [
        'activity_id' => $activityId,
        'activity_type' => ACTIVITY_TYPE,
        'task_queue' => $taskQueue,
        'input' => [[
            'scenario_id' => 'operator_visible_activity_attempt_state',
            'runtime' => 'workflow-php',
            'operator_state' => $state,
            'input_marker' => "operator-visible-{$state}-{$suffix}",
        ]],
        ...$options,
    ]);
    $runId = (string) ($start['workflow_run_id'] ?? '');
    $activityExecutionId = (string) ($start['activity_execution_id'] ?? '');
    if ($runId === '' || $activityExecutionId === '') {
        throw new RuntimeException("operator visibility {$state} activity start did not return execution and run identifiers");
    }

    return [
        'state' => $state,
        'worker_id' => $workerId,
        'task_queue' => $taskQueue,
        'activity_id' => $activityId,
        'workflow_run_id' => $runId,
        'activity_execution_id' => $activityExecutionId,
        'task' => poll_task('activity', $workerId, $taskQueue, [
            'workflow_id' => $activityId,
            'run_id' => $runId,
            'activity_execution_id' => $activityExecutionId,
        ]),
    ];
}

function operator_visibility_state_observation(string $state, string $suffix): array
{
    if ($state === 'in_flight') {
        $activity = start_operator_visibility_activity($suffix, $state, [
            'heartbeat_timeout_seconds' => 30,
            'schedule_to_close_timeout_seconds' => 120,
        ]);
        $heartbeat = heartbeat_activity_task($activity['task'], [
            'message' => 'operator visibility heartbeat',
            'current' => 1,
            'total' => 3,
            'unit' => 'step',
            'details' => ['state' => $state],
        ]);
        $snapshot = operator_surface_snapshot(
            $state,
            $activity['activity_id'],
            $activity['workflow_run_id'],
            $activity['activity_execution_id'],
            $activity['task_queue'],
            $activity['task']['activity_attempt_id'] ?? null,
        );
        $snapshot['heartbeat_response'] = $heartbeat;

        return $snapshot;
    }

    if ($state === 'retrying') {
        $activity = start_operator_visibility_activity($suffix, $state, [
            'retry_policy' => ['max_attempts' => 2, 'backoff_seconds' => [60]],
        ]);
        $failure = [
            'message' => 'operator visibility retryable failure',
            'type' => 'ActivitiesConformanceVisibilityRetryableFailure',
            'retryable' => true,
            'non_retryable' => false,
        ];
        $failResponse = fail_activity_task($activity['task'], $failure);
        $snapshot = operator_surface_snapshot(
            $state,
            $activity['activity_id'],
            $activity['workflow_run_id'],
            $activity['activity_execution_id'],
            $activity['task_queue'],
            $activity['task']['activity_attempt_id'] ?? null,
        );
        $snapshot['failure_response'] = $failResponse;
        $snapshot['configured_retry_policy'] = ['max_attempts' => 2, 'backoff_seconds' => [60]];

        return $snapshot;
    }

    if ($state === 'timed_out') {
        $activity = start_operator_visibility_activity($suffix, $state, [
            'start_to_close_timeout_seconds' => 1,
            'schedule_to_close_timeout_seconds' => 30,
            'retry_policy' => ['max_attempts' => 1, 'backoff_seconds' => [0]],
        ]);
        $deadlines = is_array($activity['task']['deadlines'] ?? null) ? $activity['task']['deadlines'] : [];
        $deadlineTimestamp = timestamp_from_datetime(is_string($deadlines['start_to_close'] ?? null) ? $deadlines['start_to_close'] : null);
        if ($deadlineTimestamp === null) {
            throw new RuntimeException('operator visibility timed-out state did not expose start-to-close deadline');
        }
        wait_until_timestamp($deadlineTimestamp + 0.20);
        $enforceResponse = request_json('POST', '/system/activity-timeouts/pass', [
            'execution_ids' => [$activity['activity_execution_id']],
        ]);
        $snapshot = operator_surface_snapshot(
            $state,
            $activity['activity_id'],
            $activity['workflow_run_id'],
            $activity['activity_execution_id'],
            $activity['task_queue'],
            $activity['task']['activity_attempt_id'] ?? null,
        );
        $snapshot['worker_visible_deadlines'] = $deadlines;
        $snapshot['enforce_response'] = $enforceResponse;

        return $snapshot;
    }

    if ($state === 'failed') {
        $activity = start_operator_visibility_activity($suffix, $state, [
            'retry_policy' => ['max_attempts' => 1, 'backoff_seconds' => [0]],
        ]);
        $failure = [
            'message' => 'operator visibility terminal failure',
            'type' => 'ActivitiesConformanceVisibilityFailure',
            'retryable' => false,
            'non_retryable' => true,
        ];
        $failResponse = fail_activity_task($activity['task'], $failure);
        $snapshot = operator_surface_snapshot(
            $state,
            $activity['activity_id'],
            $activity['workflow_run_id'],
            $activity['activity_execution_id'],
            $activity['task_queue'],
            $activity['task']['activity_attempt_id'] ?? null,
        );
        $snapshot['failure_response'] = $failResponse;

        return $snapshot;
    }

    if ($state === 'completed') {
        $activity = start_operator_visibility_activity($suffix, $state);
        [$result, $complete, $workerArtifact] = complete_activity_task($activity['task'], 'workflow-php', 'standalone');
        $snapshot = operator_surface_snapshot(
            $state,
            $activity['activity_id'],
            $activity['workflow_run_id'],
            $activity['activity_execution_id'],
            $activity['task_queue'],
            $activity['task']['activity_attempt_id'] ?? null,
        );
        $snapshot['activity_result'] = $result;
        $snapshot['completion_response'] = $complete;
        $snapshot['worker_artifact'] = $workerArtifact;

        return $snapshot;
    }

    if ($state === 'cancelled') {
        $activity = start_operator_visibility_activity($suffix, $state, [
            'heartbeat_timeout_seconds' => 30,
            'schedule_to_close_timeout_seconds' => 120,
        ]);
        request_json('POST', '/workflows/'.rawurlencode($activity['activity_id']).'/runs/'.rawurlencode($activity['workflow_run_id']).'/cancel', [
            'reason' => 'operator visibility cancellation state',
        ]);
        [$lateResult, $lateEnvelope, $workerArtifact] = activity_completion_payload($activity['task'], 'workflow-php', 'standalone');
        $lateCompletion = request_json(
            'POST',
            '/worker/activity-tasks/'.rawurlencode((string) $activity['task']['task_id']).'/complete',
            [
                'activity_attempt_id' => $activity['task']['activity_attempt_id'] ?? '',
                'lease_owner' => $activity['task']['lease_owner'],
                'result' => $lateEnvelope,
            ],
            [409],
        );
        $snapshot = operator_surface_snapshot(
            $state,
            $activity['activity_id'],
            $activity['workflow_run_id'],
            $activity['activity_execution_id'],
            $activity['task_queue'],
            $activity['task']['activity_attempt_id'] ?? null,
        );
        $snapshot['late_completion_after_cancel_response'] = $lateCompletion;
        $snapshot['late_completion_after_cancel_result'] = $lateResult;
        $snapshot['worker_artifact'] = $workerArtifact;

        return $snapshot;
    }

    throw new RuntimeException("unsupported operator visibility state {$state}");
}

function operator_visibility_state_pass(array $observation, bool $requireCli = true): bool
{
    $state = is_string($observation['state'] ?? null) ? $observation['state'] : '';
    $visibility = is_array($observation['surface_visibility'] ?? null) ? $observation['surface_visibility'] : [];
    if (($visibility['api_detail_visible'] ?? null) !== true
        || ($visibility['api_list_visible'] ?? null) !== true
        || ($visibility['history_visible'] ?? null) !== true
        || ($visibility['waterline_visible'] ?? null) !== true) {
        return false;
    }
    if ($requireCli && ($visibility['cli_list_visible'] ?? null) !== true) {
        return false;
    }

    $apiStatus = $observation['api_detail']['activity_status'] ?? null;
    $runStatus = $observation['api_detail']['status'] ?? null;
    $executionStatus = $observation['execution_state']['status'] ?? null;

    return match ($state) {
        'in_flight' => $apiStatus === ActivityStatus::Running->value && $runStatus === RunStatus::Running->value,
        'retrying' => is_array($observation['failure_response'] ?? null)
            && is_string($observation['failure_response']['next_task_id'] ?? null)
            && $executionStatus !== ActivityStatus::Failed->value,
        'timed_out' => $apiStatus === ActivityStatus::Failed->value
            && $runStatus === RunStatus::Failed->value
            && ($observation['api_detail']['closed_reason'] ?? null) === 'timed_out',
        'failed' => $apiStatus === ActivityStatus::Failed->value
            && $runStatus === RunStatus::Failed->value,
        'completed' => $apiStatus === ActivityStatus::Completed->value
            && $runStatus === RunStatus::Completed->value,
        'cancelled' => cancelled_or_failed_activity_status($apiStatus)
            && in_array($runStatus, [RunStatus::Cancelled->value, RunStatus::Failed->value], true),
        default => false,
    };
}

function run_heartbeat_cancellation_cell(): array
{
    $suffix = bin2hex(random_bytes(3));
    $workerId = "activities-heartbeat-cancel-{$suffix}";
    $activityId = "activities-heartbeat-cancel-{$suffix}";
    $taskQueue = scenario_task_queue($activityId);
    $heartbeatDetails = [
        'message' => 'activities conformance heartbeat',
        'current' => 1,
        'total' => 2,
        'unit' => 'step',
        'details' => [
            'phase' => 'heartbeat_and_cancellation_observation',
            'marker' => "heartbeat-cancel-{$suffix}",
        ],
    ];

    register_worker($workerId, $taskQueue, [], [ACTIVITY_TYPE], 'workflow-php');
    $start = request_json('POST', '/activities', [
        'activity_id' => $activityId,
        'activity_type' => ACTIVITY_TYPE,
        'task_queue' => $taskQueue,
        'input' => [[
            'scenario_id' => 'heartbeat_and_cancellation_observation',
            'runtime' => 'workflow-php',
            'input_marker' => "heartbeat-cancel-{$suffix}",
        ]],
        'heartbeat_timeout_seconds' => 30,
        'schedule_to_close_timeout_seconds' => 120,
    ]);
    $runId = (string) ($start['workflow_run_id'] ?? '');
    $activityExecutionId = (string) ($start['activity_execution_id'] ?? '');
    if ($runId === '' || $activityExecutionId === '') {
        throw new RuntimeException('heartbeat/cancellation activity start did not return execution and run identifiers');
    }

    $activityTask = poll_task('activity', $workerId, $taskQueue, [
        'workflow_id' => $activityId,
        'run_id' => $runId,
        'activity_execution_id' => $activityExecutionId,
    ]);
    $heartbeatResponse = heartbeat_activity_task($activityTask, $heartbeatDetails);
    $historyAfterHeartbeat = request_json('GET', '/workflows/'.rawurlencode($activityId).'/runs/'.rawurlencode($runId).'/history');
    $heartbeatPayload = history_payload_for_execution(
        $historyAfterHeartbeat,
        HistoryEventType::ActivityHeartbeatRecorded->value,
        $activityExecutionId,
    );
    $showAfterHeartbeat = request_json('GET', '/activities/'.rawurlencode($activityId));

    $cancelResponse = request_json('POST', '/workflows/'.rawurlencode($activityId).'/runs/'.rawurlencode($runId).'/cancel', [
        'reason' => 'activities conformance cancellation observation',
    ]);
    $cancelHeartbeatResponse = heartbeat_activity_task($activityTask, [
        'message' => 'activities conformance cancellation check',
        'details' => [
            'phase' => 'cancel_requested',
            'marker' => "heartbeat-cancel-{$suffix}",
        ],
    ]);
    [$lateResult, $lateResultEnvelope, $workerArtifact] = activity_completion_payload(
        $activityTask,
        'workflow-php',
        'standalone',
    );
    $lateCompletionResponse = request_json(
        'POST',
        '/worker/activity-tasks/'.rawurlencode((string) $activityTask['task_id']).'/complete',
        [
            'activity_attempt_id' => $activityTask['activity_attempt_id'] ?? '',
            'lease_owner' => $activityTask['lease_owner'],
            'result' => $lateResultEnvelope,
        ],
        [409],
    );
    $showAfterCancel = request_json('GET', '/activities/'.rawurlencode($activityId));
    $historyAfterCancel = request_json('GET', '/workflows/'.rawurlencode($activityId).'/runs/'.rawurlencode($runId).'/history');
    $attemptState = attempt_snapshots($activityExecutionId);
    $executionState = activity_execution_state($activityExecutionId);
    $latestAttempt = latest_attempt_snapshot($attemptState);

    $heartbeatRecorded = ($heartbeatResponse['heartbeat_recorded'] ?? null) === true
        && ($heartbeatResponse['cancel_requested'] ?? null) === false
        && ($heartbeatPayload['activity_attempt_id'] ?? null) === ($activityTask['activity_attempt_id'] ?? null)
        && is_array($heartbeatPayload['progress'] ?? null);
    $workerObservedCancellation = ($cancelHeartbeatResponse['cancel_requested'] ?? null) === true
        && ($cancelHeartbeatResponse['can_continue'] ?? null) === false
        && ($cancelHeartbeatResponse['reason'] ?? null) === 'run_cancelled'
        && ($cancelHeartbeatResponse['heartbeat_recorded'] ?? null) === false;
    $terminalCancellationState = ($lateCompletionResponse['outcome'] ?? null) === 'ignored'
        && ($lateCompletionResponse['recorded'] ?? null) === false
        && ($lateCompletionResponse['reason'] ?? null) === 'run_cancelled'
        && ($lateCompletionResponse['cancel_requested'] ?? null) === true
        && ($lateCompletionResponse['can_continue'] ?? null) === false
        && ($lateCompletionResponse['run_status'] ?? null) === RunStatus::Cancelled->value
        && ($lateCompletionResponse['run_closed_reason'] ?? null) === RunStatus::Cancelled->value
        && cancelled_or_failed_activity_status($lateCompletionResponse['activity_status'] ?? null)
        && cancelled_or_failed_activity_status($lateCompletionResponse['attempt_status'] ?? null)
        && cancelled_or_failed_activity_status($lateCompletionResponse['task_status'] ?? null)
        && ($showAfterCancel['status'] ?? null) === RunStatus::Cancelled->value
        && cancelled_or_failed_activity_status($showAfterCancel['activity_status'] ?? null)
        && cancelled_or_failed_activity_status($executionState['status'] ?? null)
        && cancelled_or_failed_activity_status($latestAttempt['status'] ?? null);

    if (! $heartbeatRecorded) {
        throw new RuntimeException('heartbeat/cancellation did not record heartbeat details in history and worker response');
    }
    if (! $workerObservedCancellation) {
        throw new RuntimeException('heartbeat/cancellation did not expose cancel_requested=true to the running worker');
    }
    if (! $terminalCancellationState) {
        throw new RuntimeException('heartbeat/cancellation did not expose a documented terminal cancelled or failed activity state after cancellation');
    }

    return [
        'scenario_id' => 'heartbeat_and_cancellation_observation',
        'mode' => 'standalone',
        'runtime' => 'workflow-php',
        'status' => 'pass',
        'execution_source' => HOST_EVIDENCE_SOURCE,
        'activity_id' => $activityId,
        'workflow_run_id' => $runId,
        'task_queue' => $taskQueue,
        'activity_execution_id' => $activityExecutionId,
        'activity_attempt_id' => $activityTask['activity_attempt_id'] ?? null,
        'activity_type' => $activityTask['activity_type'] ?? ACTIVITY_TYPE,
        'heartbeat_details' => $heartbeatDetails,
        'heartbeat_response' => $heartbeatResponse,
        'heartbeat_history_event' => $heartbeatPayload,
        'heartbeat_recorded' => $heartbeatRecorded,
        'cancel_response' => $cancelResponse,
        'cancel_requested_response' => $cancelHeartbeatResponse,
        'worker_observed_cancellation' => $workerObservedCancellation,
        'activity_handle_after_heartbeat' => $showAfterHeartbeat,
        'activity_handle_after_cancel' => $showAfterCancel,
        'late_completion_after_cancel_response' => $lateCompletionResponse,
        'late_completion_after_cancel_result' => $lateResult,
        'terminal_cancellation_state' => [
            'documented_terminal_state_observed' => $terminalCancellationState,
            'run_status' => $lateCompletionResponse['run_status'] ?? null,
            'run_closed_reason' => $lateCompletionResponse['run_closed_reason'] ?? null,
            'activity_status' => $lateCompletionResponse['activity_status'] ?? null,
            'attempt_status' => $lateCompletionResponse['attempt_status'] ?? null,
            'task_status' => $lateCompletionResponse['task_status'] ?? null,
            'activity_handle_status' => $showAfterCancel['activity_status'] ?? null,
            'stored_execution_status' => $executionState['status'] ?? null,
            'stored_attempt_status' => $latestAttempt['status'] ?? null,
        ],
        'worker_artifact' => $workerArtifact,
        'attempt_state' => $attemptState,
        'execution_state' => $executionState,
        'history_events_after_heartbeat' => event_types($historyAfterHeartbeat),
        'history_events_after_cancel' => event_types($historyAfterCancel),
        'local_product_source_checkouts_used' => false,
    ];
}

function run_idempotent_completion_cell(): array
{
    $suffix = bin2hex(random_bytes(3));
    $workerId = "activities-idempotent-complete-{$suffix}";
    $activityId = "activities-idempotent-complete-{$suffix}";
    $taskQueue = scenario_task_queue($activityId);

    register_worker($workerId, $taskQueue, [], [ACTIVITY_TYPE], 'workflow-php');
    $start = request_json('POST', '/activities', [
        'activity_id' => $activityId,
        'activity_type' => ACTIVITY_TYPE,
        'task_queue' => $taskQueue,
        'input' => [[
            'scenario_id' => 'idempotent_completion_handling',
            'runtime' => 'workflow-php',
            'input_marker' => "idempotent-complete-{$suffix}",
        ]],
    ]);
    $runId = (string) ($start['workflow_run_id'] ?? '');
    $activityExecutionId = (string) ($start['activity_execution_id'] ?? '');
    if ($runId === '' || $activityExecutionId === '') {
        throw new RuntimeException('idempotent completion activity start did not return execution and run identifiers');
    }

    $activityTask = poll_task('activity', $workerId, $taskQueue, [
        'workflow_id' => $activityId,
        'run_id' => $runId,
        'activity_execution_id' => $activityExecutionId,
    ]);
    $codec = task_codec($activityTask);
    $payload = activity_input($activityTask, $codec);
    $result = [
        'message' => 'published artifact activity completed',
        'mode' => 'standalone',
        'runtime' => 'workflow-php',
        'input_marker' => $payload['input_marker'] ?? null,
        'activity_type' => $activityTask['activity_type'] ?? ACTIVITY_TYPE,
    ];
    $completionRequest = [
        'activity_attempt_id' => $activityTask['activity_attempt_id'] ?? '',
        'lease_owner' => $activityTask['lease_owner'],
        'result' => envelope($result, $codec),
    ];
    $firstCompletion = request_json(
        'POST',
        '/worker/activity-tasks/'.rawurlencode((string) $activityTask['task_id']).'/complete',
        $completionRequest,
    );
    $duplicateCompletion = request_json(
        'POST',
        '/worker/activity-tasks/'.rawurlencode((string) $activityTask['task_id']).'/complete',
        $completionRequest,
        [409],
    );
    $show = request_json('GET', '/activities/'.rawurlencode($activityId));
    $history = request_json('GET', '/workflows/'.rawurlencode($activityId).'/runs/'.rawurlencode($runId).'/history');
    assert_activity_handle_identity($show, $activityId, $runId, $activityExecutionId, 'idempotent completion');
    $completedHistoryEvents = array_values(array_filter(
        history_payloads_for_event($history, HistoryEventType::ActivityCompleted->value),
        static fn (array $payload): bool => ($payload['activity_execution_id'] ?? null) === $activityExecutionId
            && ($payload['activity_attempt_id'] ?? null) === ($activityTask['activity_attempt_id'] ?? null),
    ));
    $completedHistoryCount = count($completedHistoryEvents);
    $sameTaskAndAttempt = ($firstCompletion['task_id'] ?? null) === ($activityTask['task_id'] ?? null)
        && ($duplicateCompletion['task_id'] ?? null) === ($activityTask['task_id'] ?? null)
        && ($firstCompletion['activity_attempt_id'] ?? null) === ($activityTask['activity_attempt_id'] ?? null)
        && ($duplicateCompletion['activity_attempt_id'] ?? null) === ($activityTask['activity_attempt_id'] ?? null);
    $recordedOnce = ($firstCompletion['recorded'] ?? null) === true
        && ($duplicateCompletion['recorded'] ?? null) === false
        && $sameTaskAndAttempt
        && $completedHistoryCount === 1;
    $deterministicDuplicate = ($duplicateCompletion['outcome'] ?? null) === 'completed'
        && ($duplicateCompletion['reason'] ?? null) === 'stale_attempt';

    if (! $recordedOnce || ! $deterministicDuplicate) {
        throw new RuntimeException('idempotent completion did not return stale_attempt after exactly one recorded completion');
    }
    if (($show['status'] ?? null) !== RunStatus::Completed->value) {
        throw new RuntimeException('idempotent completion activity did not close as completed after first completion');
    }

    return [
        'scenario_id' => 'idempotent_completion_handling',
        'mode' => 'standalone',
        'runtime' => 'workflow-php',
        'status' => 'pass',
        'execution_source' => HOST_EVIDENCE_SOURCE,
        'activity_id' => $activityId,
        'workflow_run_id' => $runId,
        'task_queue' => $taskQueue,
        'activity_execution_id' => $activityExecutionId,
        'activity_attempt_id' => $activityTask['activity_attempt_id'] ?? null,
        'activity_type' => $activityTask['activity_type'] ?? ACTIVITY_TYPE,
        'first_completion_response' => $firstCompletion,
        'duplicate_completion_response' => $duplicateCompletion,
        'recorded_once' => $recordedOnce,
        'same_task_and_attempt_ids' => $sameTaskAndAttempt,
        'stale_attempt_or_idempotent_verdict' => $duplicateCompletion['reason'] ?? null,
        'activity_completed_history_count' => $completedHistoryCount,
        'activity_completed_history_events' => $completedHistoryEvents,
        'terminal_result' => [
            'activity_status' => $show['activity_status'] ?? null,
            'run_status' => $show['status'] ?? null,
            'closed_reason' => $show['closed_reason'] ?? null,
            'activity_result' => $result,
            'handle_response' => $show,
        ],
        'attempt_state' => attempt_snapshots($activityExecutionId),
        'execution_state' => activity_execution_state($activityExecutionId),
        'history_events' => event_types($history),
        'local_product_source_checkouts_used' => false,
    ];
}

function run_php_python_parity_cell(): array
{
    $suffix = bin2hex(random_bytes(3));
    $phpResultObservation = run_parity_result_observation('workflow-php', $suffix);
    $pythonResultObservation = run_parity_result_observation('sdk-python', $suffix);
    $phpFailureObservation = run_parity_failure_observation('workflow-php', $suffix);
    $pythonFailureObservation = run_parity_failure_observation('sdk-python', $suffix);
    $phpRetryObservation = run_parity_retry_observation('workflow-php', $suffix);
    $pythonRetryObservation = run_parity_retry_observation('sdk-python', $suffix);
    $phpTimeoutObservation = run_parity_timeout_observation('workflow-php', $suffix);
    $pythonTimeoutObservation = run_parity_timeout_observation('sdk-python', $suffix);
    $phpHeartbeatObservation = run_parity_heartbeat_observation('workflow-php', $suffix);
    $pythonHeartbeatObservation = run_parity_heartbeat_observation('sdk-python', $suffix);
    $phpCancellationObservation = run_parity_cancellation_observation('workflow-php', $suffix);
    $pythonCancellationObservation = run_parity_cancellation_observation('sdk-python', $suffix);

    $shape = same_activity_payload_shape(
        $phpResultObservation['result_payload'] ?? [],
        $pythonResultObservation['result_payload'] ?? [],
    );
    $failureShape = same_observation_shape(
        $phpFailureObservation['failure_shape'] ?? [],
        $pythonFailureObservation['failure_shape'] ?? [],
        ['exception_type', 'message', 'failure_category', 'non_retryable'],
    );
    $retryShape = same_observation_shape(
        [
            'attempt_numbers' => $phpRetryObservation['attempt_numbers'] ?? null,
            'terminal_status' => $phpRetryObservation['handle_response']['status'] ?? null,
        ],
        [
            'attempt_numbers' => $pythonRetryObservation['attempt_numbers'] ?? null,
            'terminal_status' => $pythonRetryObservation['handle_response']['status'] ?? null,
        ],
        ['attempt_numbers', 'terminal_status'],
    );
    $timeoutShape = same_observation_shape(
        [
            'timeout_kind' => $phpTimeoutObservation['timeout_shape']['timeout_kind'] ?? null,
            'failure_category' => $phpTimeoutObservation['timeout_shape']['failure_category'] ?? null,
            'terminal_status' => $phpTimeoutObservation['handle_response']['status'] ?? null,
            'closed_reason' => $phpTimeoutObservation['handle_response']['closed_reason'] ?? null,
        ],
        [
            'timeout_kind' => $pythonTimeoutObservation['timeout_shape']['timeout_kind'] ?? null,
            'failure_category' => $pythonTimeoutObservation['timeout_shape']['failure_category'] ?? null,
            'terminal_status' => $pythonTimeoutObservation['handle_response']['status'] ?? null,
            'closed_reason' => $pythonTimeoutObservation['handle_response']['closed_reason'] ?? null,
        ],
        ['timeout_kind', 'failure_category', 'terminal_status', 'closed_reason'],
    );
    $heartbeatShape = same_observation_shape(
        $phpHeartbeatObservation['heartbeat_shape'] ?? [],
        $pythonHeartbeatObservation['heartbeat_shape'] ?? [],
        ['heartbeat_recorded', 'cancel_requested', 'history_event_type'],
    );
    $cancellationShape = same_observation_shape(
        $phpCancellationObservation['cancellation_shape'] ?? [],
        $pythonCancellationObservation['cancellation_shape'] ?? [],
        ['cancel_requested', 'can_continue', 'reason', 'run_status', 'activity_status', 'attempt_status', 'task_status'],
    );
    $parityObservations = [
        'result' => $shape,
        'failure' => $failureShape,
        'retry' => $retryShape,
        'timeout' => $timeoutShape,
        'heartbeat' => $heartbeatShape,
        'cancellation' => $cancellationShape,
    ];
    $runtimeMatrix = [
        'execution_modes' => ['standalone'],
        'runtimes' => ['workflow-php', 'sdk-python'],
        'activity_cells' => [
            [
                'mode' => 'standalone',
                'runtime' => 'workflow-php',
                'status' => 'pass',
                'execution_source' => HOST_EVIDENCE_SOURCE,
                'activity_execution_id' => $phpResultObservation['activity_execution_id'] ?? null,
                'activity_attempt_id' => $phpResultObservation['activity_attempt_id'] ?? null,
                'worker_artifact' => $phpResultObservation['worker_artifact'] ?? null,
                'parity_observations' => ['result', 'failure', 'retry', 'timeout', 'heartbeat', 'cancellation'],
                'local_product_source_checkouts_used' => false,
            ],
            [
                'mode' => 'standalone',
                'runtime' => 'sdk-python',
                'status' => 'pass',
                'execution_source' => HOST_EVIDENCE_SOURCE,
                'activity_execution_id' => $pythonResultObservation['activity_execution_id'] ?? null,
                'activity_attempt_id' => $pythonResultObservation['activity_attempt_id'] ?? null,
                'worker_artifact' => $pythonResultObservation['worker_artifact'] ?? null,
                'parity_observations' => ['result', 'failure', 'retry', 'timeout', 'heartbeat', 'cancellation'],
                'local_product_source_checkouts_used' => false,
            ],
        ],
    ];
    $pythonArtifactOk = ($pythonResultObservation['worker_artifact']['artifact'] ?? null) === 'sdk-python'
        && ($pythonResultObservation['worker_artifact']['status'] ?? null) === 'pass';
    $pass = ! in_array(false, array_map(
        static fn (array $observation): bool => ($observation['matches'] ?? null) === true,
        $parityObservations
    ), true)
        && $pythonArtifactOk;

    if (! $pass) {
        throw new RuntimeException('PHP/Python activity parity did not preserve result, failure, retry, timeout, heartbeat, and cancellation observation shapes with published sdk-python artifact evidence');
    }

    return [
        'scenario_id' => 'php_python_activity_parity',
        'mode' => 'standalone',
        'runtime' => 'workflow-php+sdk-python',
        'status' => 'pass',
        'execution_source' => HOST_EVIDENCE_SOURCE,
        'php_activity_id' => $phpResultObservation['activity_id'] ?? null,
        'python_activity_id' => $pythonResultObservation['activity_id'] ?? null,
        'php_workflow_run_id' => $phpResultObservation['workflow_run_id'] ?? null,
        'python_workflow_run_id' => $pythonResultObservation['workflow_run_id'] ?? null,
        'php_activity_execution_id' => $phpResultObservation['activity_execution_id'] ?? null,
        'python_activity_execution_id' => $pythonResultObservation['activity_execution_id'] ?? null,
        'php_activity_result' => $phpResultObservation['result_payload'] ?? null,
        'python_activity_result' => $pythonResultObservation['result_payload'] ?? null,
        'cross_language_payload_shape' => $shape,
        'cross_language_failure_shape' => $failureShape,
        'cross_language_retry_shape' => $retryShape,
        'cross_language_timeout_shape' => $timeoutShape,
        'cross_language_heartbeat_shape' => $heartbeatShape,
        'cross_language_cancellation_shape' => $cancellationShape,
        'parity_observations' => $parityObservations,
        'runtime_matrix' => $runtimeMatrix,
        'heartbeat_observations' => [
            'workflow-php' => $phpHeartbeatObservation,
            'sdk-python' => $pythonHeartbeatObservation,
        ],
        'failure_observations' => [
            'workflow-php' => $phpFailureObservation,
            'sdk-python' => $pythonFailureObservation,
        ],
        'retry_observations' => [
            'workflow-php' => $phpRetryObservation,
            'sdk-python' => $pythonRetryObservation,
        ],
        'timeout_observations' => [
            'workflow-php' => $phpTimeoutObservation,
            'sdk-python' => $pythonTimeoutObservation,
        ],
        'cancellation_observations' => [
            'workflow-php' => $phpCancellationObservation,
            'sdk-python' => $pythonCancellationObservation,
        ],
        'completion_responses' => [
            'workflow-php' => $phpResultObservation['completion_response'] ?? null,
            'sdk-python' => $pythonResultObservation['completion_response'] ?? null,
        ],
        'handle_responses' => [
            'workflow-php' => $phpResultObservation['handle_response'] ?? null,
            'sdk-python' => $pythonResultObservation['handle_response'] ?? null,
        ],
        'history_events' => [
            'workflow-php' => $phpResultObservation['history_events'] ?? null,
            'sdk-python' => $pythonResultObservation['history_events'] ?? null,
        ],
        'worker_artifacts' => [
            'workflow-php' => $phpResultObservation['worker_artifact'] ?? null,
            'sdk-python' => $pythonResultObservation['worker_artifact'] ?? null,
        ],
        'local_product_source_checkouts_used' => false,
    ];
}

function run_operator_visibility_cell(): array
{
    $suffix = bin2hex(random_bytes(3));
    $stateObservations = [
        'in_flight' => operator_visibility_state_observation('in_flight', $suffix),
        'retrying' => operator_visibility_state_observation('retrying', $suffix),
    ];
    $retryTaskId = (string) ($stateObservations['retrying']['failure_response']['next_task_id'] ?? '');
    $retryAvailableAt = workflow_task_available_at($retryTaskId);
    $retryAvailableTimestamp = timestamp_from_datetime($retryAvailableAt);
    if ($retryTaskId === '' || $retryAvailableTimestamp === null) {
        throw new RuntimeException('operator visibility stale-queue fixture did not retain its 60-second retry task');
    }
    wait_until_timestamp($retryAvailableTimestamp + 0.10);
    $retryBackoffCrossedAt = microtime(true);

    $stateObservations['timed_out'] = operator_visibility_state_observation('timed_out', $suffix);
    foreach (['failed', 'completed', 'cancelled'] as $state) {
        $stateObservations[$state] = operator_visibility_state_observation($state, $suffix);
    }
    $staleQueueRegressionFixture = [
        'retry_task_id' => $retryTaskId,
        'retry_activity_execution_id' => $stateObservations['retrying']['activity_execution_id'] ?? null,
        'retry_task_queue' => $stateObservations['retrying']['operator_metrics']['task_queue'] ?? null,
        'configured_backoff_seconds' => 60,
        'retry_available_at' => iso_from_datetime($retryAvailableAt),
        'backoff_crossed_at' => iso_from_timestamp($retryBackoffCrossedAt),
        'backoff_crossed_before_timed_out_poll' => $retryBackoffCrossedAt >= $retryAvailableTimestamp,
        'timed_out_activity_execution_id' => $stateObservations['timed_out']['activity_execution_id'] ?? null,
        'timed_out_task_queue' => $stateObservations['timed_out']['operator_metrics']['task_queue'] ?? null,
        'isolated_task_queues' => ($stateObservations['retrying']['operator_metrics']['task_queue'] ?? null)
            !== ($stateObservations['timed_out']['operator_metrics']['task_queue'] ?? null),
        'timed_out_worker_visible_start_to_close_deadline' => $stateObservations['timed_out']['worker_visible_deadlines']['start_to_close'] ?? null,
    ];
    if (($staleQueueRegressionFixture['backoff_crossed_before_timed_out_poll'] ?? null) !== true
        || ($staleQueueRegressionFixture['isolated_task_queues'] ?? null) !== true
        || ! is_string($staleQueueRegressionFixture['timed_out_worker_visible_start_to_close_deadline'] ?? null)) {
        throw new RuntimeException('operator visibility stale-queue fixture did not prove isolated timed-out delivery after the 60-second retry became ready');
    }

    $statePasses = [];
    $statePassesWithoutCli = [];
    foreach ($stateObservations as $state => $observation) {
        $statePasses[$state] = operator_visibility_state_pass($observation);
        $statePassesWithoutCli[$state] = operator_visibility_state_pass($observation, false);
    }
    $missingSurfaceReasons = [];
    foreach ($stateObservations as $state => $observation) {
        $visibility = is_array($observation['surface_visibility'] ?? null) ? $observation['surface_visibility'] : [];
        foreach (['api_detail_visible', 'api_list_visible', 'history_visible', 'waterline_visible', 'cli_list_visible'] as $field) {
            if (($visibility[$field] ?? null) !== true) {
                $missingSurfaceReasons[] = "{$state}.{$field}";
            }
        }
        if (($statePasses[$state] ?? false) !== true) {
            $missingSurfaceReasons[] = "{$state}.state_contract";
        }
    }

    $inFlightObservation = $stateObservations['in_flight'];
    $cellStatus = $missingSurfaceReasons === [] ? 'pass' : 'fail';

    return [
        'scenario_id' => 'operator_visible_activity_attempt_state',
        'mode' => 'standalone',
        'runtime' => 'workflow-php',
        'status' => $cellStatus,
        'execution_source' => HOST_EVIDENCE_SOURCE,
        'activity_id' => $inFlightObservation['activity_id'] ?? null,
        'workflow_run_id' => $inFlightObservation['workflow_run_id'] ?? null,
        'activity_execution_id' => $inFlightObservation['activity_execution_id'] ?? null,
        'activity_attempt_id' => $inFlightObservation['activity_attempt_id'] ?? null,
        'activity_type' => ACTIVITY_TYPE,
        'required_operator_states' => ['in_flight', 'retrying', 'timed_out', 'failed', 'completed', 'cancelled'],
        'operator_state_matrix' => $stateObservations,
        'stale_shared_queue_regression_fixture' => $staleQueueRegressionFixture,
        'operator_state_passes' => $statePasses,
        'operator_state_passes_without_cli' => $statePassesWithoutCli,
        'missing_operator_surface_reasons' => $missingSurfaceReasons,
        'api_run_detail' => [
            'workflow_run_detail' => $inFlightObservation['api_run_detail'] ?? null,
            'activity_handle_detail' => $inFlightObservation['api_detail'] ?? null,
            'api_list_evidence' => $inFlightObservation['api_list_evidence'] ?? null,
            'api_visible' => $inFlightObservation['surface_visibility']['api_detail_visible'] ?? null,
        ],
        'history_activity_attempts' => [
            'activity_started' => $inFlightObservation['history_payloads']['activity_started'] ?? null,
            'activity_heartbeat_recorded' => $inFlightObservation['history_payloads']['activity_heartbeat_recorded'] ?? null,
            'attempt_snapshots' => $inFlightObservation['attempt_state'] ?? null,
            'history_events' => $inFlightObservation['history_events'] ?? null,
            'history_visible' => $inFlightObservation['surface_visibility']['history_visible'] ?? null,
        ],
        'operator_metrics' => [
            'task_queue' => $inFlightObservation['operator_metrics']['task_queue'] ?? null,
            'current_lease' => $inFlightObservation['operator_metrics']['current_lease'] ?? null,
            'stats' => $inFlightObservation['operator_metrics']['stats'] ?? null,
            'admission' => $inFlightObservation['operator_metrics']['admission'] ?? null,
            'lease_visible' => ($inFlightObservation['operator_metrics']['current_lease']['activity_attempt_id'] ?? null) === ($inFlightObservation['activity_attempt_id'] ?? null),
        ],
        'waterline_activity_attempt_view' => $inFlightObservation['waterline_activity_attempt_view'] ?? null,
        'cli_json_list_evidence' => $inFlightObservation['cli_json_list_evidence'] ?? null,
        'heartbeat_response' => $inFlightObservation['heartbeat_response'] ?? null,
        'local_product_source_checkouts_used' => false,
    ];
}

function run_restart_durable_result_cell(): array
{
    $suffix = bin2hex(random_bytes(3));
    $firstWorkerId = "activities-restart-first-{$suffix}";
    $restartWorkerId = "activities-restart-replay-{$suffix}";
    $workflowId = "activities-restart-durable-{$suffix}";
    $taskQueue = scenario_task_queue($workflowId);

    register_worker($firstWorkerId, $taskQueue, [EMBEDDED_WORKFLOW_TYPE], [ACTIVITY_TYPE], 'workflow-php');
    register_worker($restartWorkerId, $taskQueue, [EMBEDDED_WORKFLOW_TYPE], [ACTIVITY_TYPE], 'workflow-php');

    $start = request_json('POST', '/workflows', [
        'workflow_id' => $workflowId,
        'workflow_type' => EMBEDDED_WORKFLOW_TYPE,
        'task_queue' => $taskQueue,
        'input' => [[
            'scenario_id' => 'durable_result_recording_after_worker_restart',
            'runtime' => 'workflow-php',
            'input_marker' => "restart-durable-{$suffix}",
            'task_queue' => $taskQueue,
        ]],
    ]);
    $runId = (string) ($start['run_id'] ?? '');
    $expectedIdentity = ['workflow_id' => $workflowId, 'run_id' => $runId];

    $workflowTask = poll_task('workflow', $firstWorkerId, $taskQueue, $expectedIdentity);
    complete_workflow_task_from_runtime($workflowTask);

    $activityExecutionId = activity_execution_id_for_run($runId, 'restart durability');
    $activityTask = poll_task('activity', $firstWorkerId, $taskQueue, [
        ...$expectedIdentity,
        'activity_execution_id' => $activityExecutionId,
    ]);
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

    $resumeTask = poll_task('workflow', $restartWorkerId, $taskQueue, $expectedIdentity);
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
        'task_queue' => $taskQueue,
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
        'task_queue' => $taskQueue,
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
    $taskQueue = scenario_task_queue($activityId);
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

    register_worker($workerId, $taskQueue, [], [ACTIVITY_TYPE], 'workflow-php');
    $start = request_json('POST', '/activities', [
        'activity_id' => $activityId,
        'activity_type' => ACTIVITY_TYPE,
        'task_queue' => $taskQueue,
        'input' => [[
            'scenario_id' => 'retry_attempt_backoff_behavior',
            'runtime' => 'workflow-php',
            'input_marker' => "retry-backoff-{$suffix}",
        ]],
        'retry_policy' => $retryPolicy,
    ]);
    $runId = (string) ($start['workflow_run_id'] ?? '');
    $activityExecutionId = (string) ($start['activity_execution_id'] ?? '');
    $expectedIdentity = [
        'workflow_id' => $activityId,
        'run_id' => $runId,
        'activity_execution_id' => $activityExecutionId,
    ];

    $firstPollAt = microtime(true);
    $firstTask = poll_task('activity', $workerId, $taskQueue, $expectedIdentity);
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
    $secondTask = poll_task('activity', $workerId, $taskQueue, $expectedIdentity);
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
        'task_queue' => $taskQueue,
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

function run_timeout_behavior_cell(): array
{
    $suffix = bin2hex(random_bytes(3));
    $workerId = "activities-timeout-{$suffix}";
    $activityId = "activities-timeout-{$suffix}";
    $taskQueue = scenario_task_queue($activityId);
    $configuredTimeouts = [
        'start_to_close_timeout_seconds' => 1,
        'schedule_to_close_timeout_seconds' => 30,
        'retry_policy' => [
            'max_attempts' => 1,
            'backoff_seconds' => [0],
        ],
    ];

    register_worker($workerId, $taskQueue, [], [ACTIVITY_TYPE], 'workflow-php');
    $start = request_json('POST', '/activities', [
        'activity_id' => $activityId,
        'activity_type' => ACTIVITY_TYPE,
        'task_queue' => $taskQueue,
        'input' => [[
            'scenario_id' => 'timeout_behavior',
            'runtime' => 'workflow-php',
            'input_marker' => "timeout-behavior-{$suffix}",
        ]],
        ...$configuredTimeouts,
    ]);
    $runId = (string) ($start['workflow_run_id'] ?? '');
    $activityExecutionId = (string) ($start['activity_execution_id'] ?? '');
    if ($activityExecutionId === '' || $runId === '') {
        throw new RuntimeException('timeout behavior activity start did not return execution and run identifiers');
    }

    $pollStartedAt = microtime(true);
    $activityTask = poll_task('activity', $workerId, $taskQueue, [
        'workflow_id' => $activityId,
        'run_id' => $runId,
        'activity_execution_id' => $activityExecutionId,
    ]);
    $leasedAt = microtime(true);
    $deadlines = is_array($activityTask['deadlines'] ?? null) ? $activityTask['deadlines'] : [];
    $deadlineAt = is_string($deadlines['start_to_close'] ?? null) ? $deadlines['start_to_close'] : '';
    $deadlineTimestamp = timestamp_from_datetime($deadlineAt);
    if ($deadlineTimestamp === null) {
        throw new RuntimeException('timeout behavior activity lease did not expose a start-to-close deadline to the worker');
    }

    wait_until_timestamp($deadlineTimestamp + 0.20);

    $statusBefore = request_json('GET', '/system/activity-timeouts');
    $expiredIds = array_values(array_filter(
        is_array($statusBefore['expired_execution_ids'] ?? null) ? $statusBefore['expired_execution_ids'] : [],
        static fn (mixed $value): bool => is_string($value)
    ));
    if (! in_array($activityExecutionId, $expiredIds, true)) {
        wait_until_timestamp($deadlineTimestamp + 0.60);
        $statusBefore = request_json('GET', '/system/activity-timeouts');
        $expiredIds = array_values(array_filter(
            is_array($statusBefore['expired_execution_ids'] ?? null) ? $statusBefore['expired_execution_ids'] : [],
            static fn (mixed $value): bool => is_string($value)
        ));
    }
    if (! in_array($activityExecutionId, $expiredIds, true)) {
        throw new RuntimeException('timeout behavior activity did not become visible to the timeout scanner after its start-to-close deadline');
    }

    $enforcementObservedAt = now_iso();
    $enforceResponse = request_json('POST', '/system/activity-timeouts/pass', [
        'execution_ids' => [$activityExecutionId],
    ]);
    $enforceResults = is_array($enforceResponse['results'] ?? null) ? $enforceResponse['results'] : [];
    $enforceResult = is_array($enforceResults[0] ?? null) ? $enforceResults[0] : [];
    if (($enforceResponse['enforced'] ?? null) !== 1 || ($enforceResult['outcome'] ?? null) !== 'enforced') {
        throw new RuntimeException('timeout behavior enforcement pass did not enforce the expired activity execution');
    }

    $show = request_json('GET', '/activities/'.rawurlencode($activityId));
    $history = request_json('GET', '/workflows/'.rawurlencode($activityId).'/runs/'.rawurlencode($runId).'/history');
    $timeoutPayloads = history_payloads_for_event($history, HistoryEventType::ActivityTimedOut->value);
    $timeoutPayload = is_array($timeoutPayloads[0] ?? null) ? $timeoutPayloads[0] : [];
    $workflowFailedPayloads = history_payloads_for_event($history, HistoryEventType::WorkflowFailed->value);
    $workflowFailedPayload = is_array($workflowFailedPayloads[0] ?? null) ? $workflowFailedPayloads[0] : [];

    /** @var ActivityExecution|null $execution */
    $execution = ActivityExecution::query()->find($activityExecutionId);
    /** @var WorkflowFailure|null $failure */
    $failure = WorkflowFailure::query()
        ->where('workflow_run_id', $runId)
        ->where('source_id', $activityExecutionId)
        ->first();

    $typedPayload = [
        'timeout_type' => $timeoutPayload['timeout_kind'] ?? null,
        'timeout_kind' => $timeoutPayload['timeout_kind'] ?? null,
        'failure_category' => $timeoutPayload['failure_category'] ?? null,
        'exception_class' => $timeoutPayload['exception_class'] ?? null,
        'message' => $timeoutPayload['message'] ?? null,
        'activity_execution_id' => $timeoutPayload['activity_execution_id'] ?? null,
        'activity_attempt_id' => $timeoutPayload['activity_attempt_id'] ?? null,
        'failure_id' => $timeoutPayload['failure_id'] ?? null,
        'workflow_failed_payload' => $workflowFailedPayload,
        'failure_row' => $failure instanceof WorkflowFailure ? [
            'failure_category' => $failure->failure_category instanceof BackedEnum
                ? $failure->failure_category->value
                : (string) $failure->failure_category,
            'propagation_kind' => $failure->propagation_kind,
            'exception_class' => $failure->exception_class,
            'message' => $failure->message,
        ] : null,
    ];

    $deadlineVisible = isset($deadlines['start_to_close'])
        && isset($deadlines['schedule_to_close']);
    $typedTimeoutRecorded = ($typedPayload['timeout_type'] ?? null) === 'start_to_close'
        && ($typedPayload['failure_category'] ?? null) === FailureCategory::Timeout->value
        && ($typedPayload['activity_execution_id'] ?? null) === $activityExecutionId;
    $callerObservedTimeout = ($show['activity_status'] ?? null) === ActivityStatus::Failed->value
        && ($show['status'] ?? null) === RunStatus::Failed->value
        && ($show['closed_reason'] ?? null) === 'timed_out';

    if (! $deadlineVisible) {
        throw new RuntimeException('timeout behavior activity lease did not expose both start-to-close and schedule-to-close deadlines');
    }
    if (! $typedTimeoutRecorded) {
        throw new RuntimeException('timeout behavior did not record an ActivityTimedOut history payload with timeout category and start-to-close kind');
    }
    if (! $callerObservedTimeout) {
        throw new RuntimeException('timeout behavior caller-visible activity handle did not close as a timed-out failure');
    }

    return [
        'scenario_id' => 'timeout_behavior',
        'mode' => 'standalone',
        'runtime' => 'workflow-php',
        'status' => 'pass',
        'execution_source' => HOST_EVIDENCE_SOURCE,
        'activity_id' => $activityId,
        'workflow_run_id' => $runId,
        'task_queue' => $taskQueue,
        'activity_execution_id' => $activityExecutionId,
        'activity_attempt_id' => $activityTask['activity_attempt_id'] ?? null,
        'activity_type' => $activityTask['activity_type'] ?? ACTIVITY_TYPE,
        'configured_timeout_inputs' => $configuredTimeouts,
        'timeout_type' => 'start_to_close',
        'deadline_at' => $deadlineAt,
        'worker_visible_deadlines' => $deadlines,
        'deadline_visible_to_worker' => $deadlineVisible,
        'activity_task_poll_started_at' => iso_from_timestamp($pollStartedAt),
        'activity_task_leased_at' => iso_from_timestamp($leasedAt),
        'timeout_status_before_enforce' => $statusBefore,
        'enforcement_endpoint' => 'POST /api/system/activity-timeouts/pass',
        'enforcement_observed_at' => $enforcementObservedAt,
        'enforce_response' => $enforceResponse,
        'server_expired_scan_visible' => true,
        'typed_timeout_payload' => $typedPayload,
        'typed_timeout_recorded' => $typedTimeoutRecorded,
        'activity_status' => $show['activity_status'] ?? null,
        'caller_visible_outcome' => [
            'activity_status' => $show['activity_status'] ?? null,
            'run_status' => $show['status'] ?? null,
            'closed_reason' => $show['closed_reason'] ?? null,
            'activity_handle_response' => $show,
        ],
        'attempt_state' => attempt_snapshots($activityExecutionId),
        'execution_state' => $execution instanceof ActivityExecution ? [
            'status' => $execution->status instanceof BackedEnum ? $execution->status->value : (string) $execution->status,
            'attempt_count' => $execution->attempt_count,
            'close_deadline_at' => $execution->close_deadline_at?->toJSON(),
            'schedule_to_close_deadline_at' => $execution->schedule_to_close_deadline_at?->toJSON(),
            'closed_at' => $execution->closed_at?->toJSON(),
        ] : null,
        'history_events' => event_types($history),
        'timeout_history_events' => $timeoutPayloads,
        'workflow_failed_history_events' => $workflowFailedPayloads,
        'local_product_source_checkouts_used' => false,
    ];
}

function published_php_sdk_worker_artifact(): array
{
    $packageRoot = realpath(getenv('DW_ACTIVITIES_PHP_SDK_PACKAGE_ROOT') ?: '');
    $classFile = realpath((new ReflectionClass(PublishedPhpSdkWorker::class))->getFileName() ?: '');
    $composerFile = $packageRoot === false ? false : $packageRoot.'/composer.json';
    $packageMetadata = is_string($composerFile) && is_file($composerFile)
        ? json_decode((string) file_get_contents($composerFile), true, flags: JSON_THROW_ON_ERROR)
        : [];
    $expectedVersion = getenv('DW_PHP_SDK_VERSION') ?: '';
    $metadataVersion = is_array($packageMetadata)
        ? ($packageMetadata['extra']['durable-workflow']['product-train'] ?? null)
        : null;

    if ($packageRoot === false
        || $classFile === false
        || ! str_starts_with($classFile, rtrim($packageRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)
        || ($packageMetadata['name'] ?? null) !== 'durable-workflow/sdk'
        || $metadataVersion !== $expectedVersion) {
        throw new RuntimeException('published PHP SDK worker artifact identity did not match the exact installed Composer package');
    }

    return [
        'artifact' => 'sdk-php',
        'package' => 'durable-workflow/sdk',
        'version' => $expectedVersion,
        'source' => 'packagist://durable-workflow/sdk@'.$expectedVersion,
        'status' => 'pass',
        'runtime' => 'sdk-php',
        'language' => 'php',
        'execution_source' => HOST_EVIDENCE_SOURCE,
        'execution_method' => PublishedPhpSdkWorker::class.'::run',
        'worker_protocol_version' => DurableWorkflow\Version::WORKER_PROTOCOL,
        'package_metadata_version' => $metadataVersion,
        'local_product_source_checkouts_used' => false,
    ];
}

function published_php_sdk_conflict(callable $command): array
{
    try {
        $response = $command();

        return [
            'http_status' => 200,
            'reason' => $response['reason'] ?? null,
            'recorded' => $response['recorded'] ?? null,
            'response' => $response,
        ];
    } catch (PublishedPhpSdkServerException $exception) {
        $details = is_array($exception->details) ? $exception->details : [];

        return [
            'http_status' => $exception->status,
            'reason' => $exception->reason,
            'recorded' => $details['recorded'] ?? null,
            'task_id' => $details['task_id'] ?? null,
            'activity_attempt_id' => $details['activity_attempt_id'] ?? null,
            'response' => $details,
        ];
    }
}

function run_heartbeat_timeout_renewal_cell(): array
{
    $suffix = bin2hex(random_bytes(3));
    $workerId = "activities-sdk-php-heartbeat-{$suffix}";
    $activityId = "activities-sdk-php-heartbeat-{$suffix}";
    $taskQueue = scenario_task_queue($activityId);
    $negativeWorkerId = "activities-sdk-php-heartbeat-negative-worker-{$suffix}";
    $negativeActivityId = "activities-sdk-php-heartbeat-negative-{$suffix}";
    $negativeTaskQueue = scenario_task_queue($negativeActivityId);
    $heartbeatTimeoutSeconds = 2;
    $heartbeatCadenceSeconds = 0.35;
    $heartbeatCount = 7;
    $transport = new PublishedServerKernelSdkTransport();
    $client = new PublishedPhpSdkClient(
        'http://published-server-artifact.invalid',
        namespace: ACTIVITIES_NAMESPACE,
        transport: $transport,
    );
    $workerArtifact = published_php_sdk_worker_artifact();

    $start = request_json('POST', '/activities', [
        'activity_id' => $activityId,
        'activity_type' => ACTIVITY_TYPE,
        'task_queue' => $taskQueue,
        'input' => [[
            'scenario_id' => 'heartbeat_timeout_renewal_across_enforcement_passes',
            'runtime' => 'sdk-php',
            'input_marker' => "sdk-php-heartbeat-renewal-{$suffix}",
        ]],
        'heartbeat_timeout_seconds' => $heartbeatTimeoutSeconds,
        'schedule_to_close_timeout_seconds' => 30,
        'retry_policy' => [
            'max_attempts' => 1,
            'backoff_seconds' => [0],
        ],
    ]);
    $runId = (string) ($start['workflow_run_id'] ?? '');
    $activityExecutionId = (string) ($start['activity_execution_id'] ?? '');
    if ($runId === '' || $activityExecutionId === '') {
        throw new RuntimeException('PHP SDK heartbeat renewal activity start did not return durable identifiers');
    }

    $acknowledgements = [];
    $enforcementPasses = [];
    $handlerStartedAt = 0.0;
    $handlerFinishedAt = 0.0;
    $initialHeartbeatDeadlineAt = '';
    $worker = null;
    $worker = new PublishedPhpSdkWorker(
        $client,
        $taskQueue,
        $workerId,
    );
    $worker->registerActivity(
        ACTIVITY_TYPE,
        function (
            PublishedPhpSdkActivityContext $context,
            mixed ...$arguments,
        ) use (
            &$worker,
            $transport,
            $activityId,
            $activityExecutionId,
            $runId,
            $heartbeatTimeoutSeconds,
            $heartbeatCadenceSeconds,
            $heartbeatCount,
            &$acknowledgements,
            &$enforcementPasses,
            &$handlerStartedAt,
            &$handlerFinishedAt,
            &$initialHeartbeatDeadlineAt,
            $suffix,
        ): array {
            $handlerStartedAt = microtime(true);
            // Stop the managed loop after this leased task, including when a
            // fail-closed assertion throws and the SDK reports task failure.
            $worker?->requestShutdown();
            $pollExchange = $transport->latest('/activity-tasks/poll', 'POST');
            $polledTask = is_array($pollExchange['response']['task'] ?? null)
                ? $pollExchange['response']['task']
                : [];
            assert_task_identity($polledTask, [
                'workflow_id' => $activityId,
                'run_id' => $runId,
                'activity_execution_id' => $activityExecutionId,
            ], 'managed PHP SDK heartbeat worker');
            $polledInput = activity_input($polledTask, task_codec($polledTask));
            if (($polledInput['input_marker'] ?? null) !== "sdk-php-heartbeat-renewal-{$suffix}") {
                throw new RuntimeException('managed PHP SDK heartbeat worker leased the wrong scenario payload');
            }
            $initialState = activity_execution_state($activityExecutionId) ?? [];
            $initialHeartbeatDeadlineAt = (string) ($initialState['heartbeat_deadline_at'] ?? '');
            $previousDeadlineTimestamp = timestamp_from_datetime($initialHeartbeatDeadlineAt);
            if ($previousDeadlineTimestamp === null) {
                throw new RuntimeException('PHP SDK heartbeat renewal did not expose the initial authoritative heartbeat deadline');
            }

            $previousHeartbeatAt = null;
            for ($sequence = 1; $sequence <= $heartbeatCount; $sequence++) {
                wait_until_timestamp($handlerStartedAt + ($sequence * $heartbeatCadenceSeconds));
                $progress = [
                    'scenario_id' => 'heartbeat_timeout_renewal_across_enforcement_passes',
                    'sequence' => $sequence,
                    'total' => $heartbeatCount,
                    'marker' => "sdk-php-heartbeat-renewal-{$suffix}",
                ];
                $context->heartbeat($progress);
                $exchange = $transport->latest('/heartbeat', 'POST');
                $response = is_array($exchange['response'] ?? null) ? $exchange['response'] : [];
                $state = activity_execution_state($activityExecutionId) ?? [];
                $authoritativeDeadlineAt = (string) ($state['heartbeat_deadline_at'] ?? '');
                $authoritativeDeadlineTimestamp = timestamp_from_datetime($authoritativeDeadlineAt);
                $lastHeartbeatAt = timestamp_from_datetime($state['last_heartbeat_at'] ?? null);
                $observedCadence = $previousHeartbeatAt === null || $lastHeartbeatAt === null
                    ? null
                    : $lastHeartbeatAt - $previousHeartbeatAt;
                $deadlineAdvanced = $authoritativeDeadlineTimestamp !== null
                    && $authoritativeDeadlineTimestamp > $previousDeadlineTimestamp;

                if (($response['heartbeat_recorded'] ?? null) !== true
                    || ($response['can_continue'] ?? null) !== true
                    || ! $deadlineAdvanced
                    || ($observedCadence !== null && $observedCadence > $heartbeatTimeoutSeconds / 2)) {
                    throw new RuntimeException("PHP SDK heartbeat acknowledgement {$sequence} did not advance the authoritative deadline at the required cadence");
                }

                $enforcementStartedAt = microtime(true);
                $enforcement = request_json('POST', '/system/activity-timeouts/pass', [
                    'execution_ids' => [$activityExecutionId],
                ]);
                $enforcementResult = is_array($enforcement['results'][0] ?? null)
                    ? $enforcement['results'][0]
                    : [];
                $timedOutCount = WorkflowHistoryEvent::query()
                    ->where('workflow_run_id', $runId)
                    ->where('event_type', HistoryEventType::ActivityTimedOut->value)
                    ->count();
                if (($enforcement['processed'] ?? null) !== 1
                    || ($enforcement['enforced'] ?? null) !== 0
                    || ($enforcement['skipped'] ?? null) !== 1
                    || ($enforcement['failed'] ?? null) !== 0
                    || ($enforcementResult['outcome'] ?? null) !== 'skipped'
                    || ($enforcementResult['reason'] ?? null) !== 'no_deadline_expired'
                    || $timedOutCount !== 0) {
                    throw new RuntimeException("PHP SDK heartbeat renewal enforcement pass {$sequence} contradicted the accepted heartbeat");
                }

                $acknowledgements[] = [
                    'sequence' => $sequence,
                    'progress' => $progress,
                    'request_started_at' => $exchange['request_started_at'] ?? null,
                    'response_received_at' => $exchange['response_received_at'] ?? null,
                    'response' => $response,
                    'previous_deadline_at' => iso_from_timestamp($previousDeadlineTimestamp),
                    'authoritative_deadline_at' => $authoritativeDeadlineAt,
                    'last_heartbeat_at' => $state['last_heartbeat_at'] ?? null,
                    'observed_cadence_seconds' => $observedCadence,
                    'deadline_advanced' => $deadlineAdvanced,
                ];
                $enforcementPasses[] = [
                    'pass' => $sequence,
                    'observed_at' => iso_from_timestamp($enforcementStartedAt),
                    'finished_at' => iso_from_timestamp(microtime(true)),
                    'authoritative_deadline_at' => $authoritativeDeadlineAt,
                    'activity_timed_out_history_count' => $timedOutCount,
                    'response' => $enforcement,
                ];

                $previousDeadlineTimestamp = $authoritativeDeadlineTimestamp;
                $previousHeartbeatAt = $lastHeartbeatAt;
            }

            $handlerFinishedAt = microtime(true);
            if (($handlerFinishedAt - $handlerStartedAt) <= $heartbeatTimeoutSeconds) {
                throw new RuntimeException('PHP SDK heartbeat renewal activity did not remain in flight beyond the original heartbeat timeout');
            }
            return [
                'message' => 'published PHP SDK heartbeat renewal completed',
                'runtime' => 'sdk-php',
                'accepted_heartbeat_count' => count($acknowledgements),
                'input_marker' => is_array($arguments[0] ?? null)
                    ? ($arguments[0]['input_marker'] ?? null)
                    : null,
            ];
        },
    );
    $worker->run(0);
    $managedWorkerDeregistration = $transport->latest('/worker/registrations/'.$workerId, 'DELETE');
    if (($managedWorkerDeregistration['response']['outcome'] ?? null) !== 'deregistered') {
        throw new RuntimeException('managed PHP SDK heartbeat worker did not deregister after its orderly run exit');
    }

    $completionExchange = $transport->latest('/complete', 'POST');
    $completionResponse = is_array($completionExchange['response'] ?? null)
        ? $completionExchange['response']
        : [];
    $show = request_json('GET', '/activities/'.rawurlencode($activityId));
    $history = request_json('GET', '/workflows/'.rawurlencode($activityId).'/runs/'.rawurlencode($runId).'/history');
    assert_activity_handle_identity($show, $activityId, $runId, $activityExecutionId, 'PHP SDK heartbeat renewal');
    $heartbeatPayloads = history_payloads_for_event($history, HistoryEventType::ActivityHeartbeatRecorded->value);
    $completedPayloads = history_payloads_for_event($history, HistoryEventType::ActivityCompleted->value);
    $timedOutPayloads = history_payloads_for_event($history, HistoryEventType::ActivityTimedOut->value);
    $completedExactlyOnce = count($completedPayloads) === 1
        && $transport->count('/complete', 'POST') === 1
        && ($completionResponse['recorded'] ?? null) === true
        && ($show['status'] ?? null) === RunStatus::Completed->value;
    $historyWithoutContradiction = count($timedOutPayloads) === 0
        && count($heartbeatPayloads) === count($acknowledgements);
    if (! $completedExactlyOnce || ! $historyWithoutContradiction) {
        throw new RuntimeException('PHP SDK heartbeat renewal did not complete exactly once with contradiction-free heartbeat history');
    }

    $executionObservationPath = getenv('DW_ACTIVITIES_PHP_SDK_EXECUTION_OBSERVATION') ?: '';
    if ($executionObservationPath === '') {
        throw new RuntimeException('PHP SDK execution observation path is not configured');
    }
    write_json_file($executionObservationPath, [
        'schema' => 'durable-workflow.v2.activity-runtime.distribution-execution-observation',
        'component' => 'sdk-php',
        'package' => 'durable-workflow/sdk',
        'version' => getenv('DW_PHP_SDK_VERSION') ?: 'unknown',
        'class' => PublishedPhpSdkWorker::class,
        'method' => 'run',
        'source_file' => 'src/Worker.php',
        'activity_execution_id' => $activityExecutionId,
        'observed_heartbeat_count' => count($acknowledgements),
        'observed_enforcement_pass_count' => count($enforcementPasses),
    ]);

    $negativeStart = request_json('POST', '/activities', [
        'activity_id' => $negativeActivityId,
        'activity_type' => ACTIVITY_TYPE,
        'task_queue' => $negativeTaskQueue,
        'input' => [[
            'scenario_id' => 'heartbeat_timeout_renewal_across_enforcement_passes',
            'runtime' => 'sdk-php',
            'control' => 'stop_heartbeating',
        ]],
        'heartbeat_timeout_seconds' => $heartbeatTimeoutSeconds,
        'schedule_to_close_timeout_seconds' => 30,
        'retry_policy' => [
            'max_attempts' => 1,
            'backoff_seconds' => [0],
        ],
    ]);
    $negativeRunId = (string) ($negativeStart['workflow_run_id'] ?? '');
    $negativeExecutionId = (string) ($negativeStart['activity_execution_id'] ?? '');
    $negativeWorkerRegistration = $client->registerWorker(
        $negativeWorkerId,
        $negativeTaskQueue,
        [],
        [ACTIVITY_TYPE],
    );
    $negativeTask = $client->pollActivityTask($negativeWorkerId, $negativeTaskQueue, 0);
    if (! is_array($negativeTask)) {
        throw new RuntimeException('PHP SDK negative control did not lease the expected activity attempt');
    }
    assert_task_identity($negativeTask, [
        'workflow_id' => $negativeActivityId,
        'run_id' => $negativeRunId,
        'activity_execution_id' => $negativeExecutionId,
    ], 'dedicated PHP SDK heartbeat negative-control worker');
    $negativeState = activity_execution_state($negativeExecutionId) ?? [];
    $negativeDeadlineAt = (string) ($negativeState['heartbeat_deadline_at'] ?? '');
    $negativeDeadlineTimestamp = timestamp_from_datetime($negativeDeadlineAt);
    if ($negativeDeadlineTimestamp === null) {
        throw new RuntimeException('PHP SDK negative control did not expose a heartbeat deadline');
    }

    wait_until_timestamp($negativeDeadlineTimestamp + 0.25);
    $negativeStatusBefore = request_json('GET', '/system/activity-timeouts');
    $negativeEnforcementObservedAt = microtime(true);
    $negativeEnforcement = request_json('POST', '/system/activity-timeouts/pass', [
        'execution_ids' => [$negativeExecutionId],
    ]);
    $negativeEnforcementResult = is_array($negativeEnforcement['results'][0] ?? null)
        ? $negativeEnforcement['results'][0]
        : [];
    if (($negativeEnforcement['enforced'] ?? null) !== 1
        || ($negativeEnforcementResult['outcome'] ?? null) !== 'enforced') {
        throw new RuntimeException('PHP SDK no-heartbeat negative control did not enforce its expired heartbeat deadline');
    }

    $lateHeartbeat = $client->heartbeatActivityTask(
        (string) $negativeTask['task_id'],
        (string) $negativeTask['activity_attempt_id'],
        (string) $negativeTask['lease_owner'],
        ['scenario_id' => 'heartbeat_timeout_renewal_across_enforcement_passes', 'late' => true],
    );
    $lateCompletion = published_php_sdk_conflict(
        fn (): array => $client->completeActivityTask(
            (string) $negativeTask['task_id'],
            (string) $negativeTask['activity_attempt_id'],
            (string) $negativeTask['lease_owner'],
            ['result' => 'too late'],
        )
    );
    $lateFailure = published_php_sdk_conflict(
        fn (): array => $client->failActivityTask(
            (string) $negativeTask['task_id'],
            (string) $negativeTask['activity_attempt_id'],
            (string) $negativeTask['lease_owner'],
            'too late',
            'ActivitiesConformanceLateFailure',
            true,
        )
    );
    $negativeShow = request_json('GET', '/activities/'.rawurlencode($negativeActivityId));
    assert_activity_handle_identity($negativeShow, $negativeActivityId, $negativeRunId, $negativeExecutionId, 'PHP SDK heartbeat negative control');
    $negativeHistory = request_json(
        'GET',
        '/workflows/'.rawurlencode($negativeActivityId).'/runs/'.rawurlencode($negativeRunId).'/history'
    );
    $negativeTimeoutPayloads = history_payloads_for_event($negativeHistory, HistoryEventType::ActivityTimedOut->value);
    $negativeTimeoutPayload = is_array($negativeTimeoutPayloads[0] ?? null)
        ? $negativeTimeoutPayloads[0]
        : [];
    $negativeTerminalHistory = [
        'event_types' => event_types($negativeHistory),
        'activity_timed_out_count' => count($negativeTimeoutPayloads),
        'activity_completed_count' => count_event_type($negativeHistory, HistoryEventType::ActivityCompleted->value),
        'activity_failed_count' => count_event_type($negativeHistory, HistoryEventType::ActivityFailed->value),
        'workflow_failed_count' => count_event_type($negativeHistory, HistoryEventType::WorkflowFailed->value),
    ];
    if (($negativeTimeoutPayload['timeout_kind'] ?? null) !== 'heartbeat'
        || ($negativeTimeoutPayload['failure_category'] ?? null) !== FailureCategory::Timeout->value
        || ($lateHeartbeat['heartbeat_recorded'] ?? null) !== false
        || ($lateHeartbeat['can_continue'] ?? null) !== false
        || ($lateHeartbeat['reason'] ?? null) !== 'attempt_closed'
        || ($lateCompletion['http_status'] ?? null) !== 409
        || ($lateCompletion['reason'] ?? null) !== 'stale_attempt'
        || ($lateCompletion['recorded'] ?? null) !== false
        || ($lateFailure['http_status'] ?? null) !== 409
        || ($lateFailure['reason'] ?? null) !== 'stale_attempt'
        || ($lateFailure['recorded'] ?? null) !== false
        || $negativeTerminalHistory['activity_timed_out_count'] !== 1
        || $negativeTerminalHistory['activity_completed_count'] !== 0
        || $negativeTerminalHistory['activity_failed_count'] !== 0) {
        throw new RuntimeException('PHP SDK no-heartbeat negative control did not preserve typed timeout and deterministic stale-attempt responses');
    }
    $negativeWorkerDeregistration = $client->deregisterWorkerRegistration($negativeWorkerId);
    if (($negativeWorkerDeregistration['outcome'] ?? null) !== 'deregistered') {
        throw new RuntimeException('PHP SDK heartbeat negative-control worker did not deregister after the typed stale-attempt checks');
    }

    $hostEvidence = [
        'schema' => HOST_EVIDENCE_SCHEMA,
        'scenario_id' => 'heartbeat_timeout_renewal_across_enforcement_passes',
        'status' => 'pass',
        'execution_source' => HOST_EVIDENCE_SOURCE,
        'executed_in_pinned_server_artifact' => true,
        'local_product_source_checkouts_used' => false,
        'activity_cells' => [[
            'mode' => 'standalone',
            'runtime' => 'sdk-php',
            'status' => 'pass',
            'execution_source' => HOST_EVIDENCE_SOURCE,
            'activity_execution_id' => $activityExecutionId,
            'activity_attempt_id' => $acknowledgements[0]['response']['activity_attempt_id'] ?? null,
            'worker_artifact' => $workerArtifact,
            'local_product_source_checkouts_used' => false,
        ]],
    ];

    return [
        'scenario_id' => 'heartbeat_timeout_renewal_across_enforcement_passes',
        'mode' => 'standalone',
        'runtime' => 'sdk-php',
        'status' => 'pass',
        'execution_source' => HOST_EVIDENCE_SOURCE,
        'activity_id' => $activityId,
        'workflow_run_id' => $runId,
        'task_queue' => $taskQueue,
        'worker_id' => $workerId,
        'managed_worker_deregistration' => $managedWorkerDeregistration,
        'activity_execution_id' => $activityExecutionId,
        'activity_attempt_id' => $acknowledgements[0]['response']['activity_attempt_id'] ?? null,
        'php_sdk_worker_artifact' => $workerArtifact,
        'heartbeat_timeout_seconds' => $heartbeatTimeoutSeconds,
        'heartbeat_cadence_seconds' => $heartbeatCadenceSeconds,
        'initial_heartbeat_deadline_at' => $initialHeartbeatDeadlineAt,
        'handler_started_at' => iso_from_timestamp($handlerStartedAt),
        'handler_finished_at' => iso_from_timestamp($handlerFinishedAt),
        'in_flight_duration_seconds' => $handlerFinishedAt - $handlerStartedAt,
        'heartbeat_acknowledgements' => $acknowledgements,
        'enforcement_passes' => $enforcementPasses,
        'completion_response' => $completionResponse,
        'terminal_history' => [
            'event_types' => event_types($history),
            'heartbeat_payloads' => $heartbeatPayloads,
            'activity_heartbeat_recorded_count' => count($heartbeatPayloads),
            'activity_completed_count' => count($completedPayloads),
            'activity_timed_out_count' => count($timedOutPayloads),
            'completed_exactly_once' => $completedExactlyOnce,
            'history_without_contradiction' => $historyWithoutContradiction,
            'activity_handle_response' => $show,
        ],
        'negative_control' => [
            'activity_id' => $negativeActivityId,
            'workflow_run_id' => $negativeRunId,
            'task_queue' => $negativeTaskQueue,
            'worker_id' => $negativeWorkerId,
            'worker_registration' => $negativeWorkerRegistration,
            'worker_deregistration' => $negativeWorkerDeregistration,
            'activity_execution_id' => $negativeExecutionId,
            'activity_attempt_id' => $negativeTask['activity_attempt_id'] ?? null,
            'initial_heartbeat_deadline_at' => $negativeDeadlineAt,
            'timeout_status_before_enforce' => $negativeStatusBefore,
            'enforcement_observed_at' => iso_from_timestamp($negativeEnforcementObservedAt),
            'enforcement_pass' => $negativeEnforcement,
            'typed_timeout_payload' => $negativeTimeoutPayload,
            'late_heartbeat_response' => $lateHeartbeat,
            'late_completion_conflict' => $lateCompletion,
            'late_failure_conflict' => $lateFailure,
            'terminal_history' => $negativeTerminalHistory,
            'activity_handle_response' => $negativeShow,
        ],
        'isolated_cleanup' => [
            'isolated_database' => true,
            'isolated_storage' => true,
            'scratch_scope' => 'ephemeral_run_root',
            'scratch_removed_on_exit' => (getenv('DW_ACTIVITIES_SCRATCH_REMOVED_ON_EXIT') ?: 'false') === 'true',
            'published_server_container_removed' => (getenv('DW_ACTIVITIES_CONTAINER_REMOVED_ON_EXIT') ?: '0') === '1',
            'published_server_container_remove_policy' => 'docker_run_rm',
            'result_evidence_retained_outside_scratch' => true,
        ],
        'activity_host_evidence' => $hostEvidence,
        'local_product_source_checkouts_used' => false,
    ];
}

function scenario_from_heartbeat_timeout_renewal_cell(array $cell): array
{
    $acknowledgements = is_array($cell['heartbeat_acknowledgements'] ?? null)
        ? $cell['heartbeat_acknowledgements']
        : [];
    $enforcementPasses = is_array($cell['enforcement_passes'] ?? null)
        ? $cell['enforcement_passes']
        : [];
    $terminalHistory = is_array($cell['terminal_history'] ?? null) ? $cell['terminal_history'] : [];
    $negative = is_array($cell['negative_control'] ?? null) ? $cell['negative_control'] : [];
    $lateCompletion = is_array($negative['late_completion_conflict'] ?? null)
        ? $negative['late_completion_conflict']
        : [];
    $lateFailure = is_array($negative['late_failure_conflict'] ?? null)
        ? $negative['late_failure_conflict']
        : [];
    $pass = ($cell['status'] ?? null) === 'pass'
        && ($cell['runtime'] ?? null) === 'sdk-php'
        && count($acknowledgements) >= 4
        && count($enforcementPasses) >= 3
        && ($terminalHistory['completed_exactly_once'] ?? null) === true
        && ($terminalHistory['history_without_contradiction'] ?? null) === true
        && ($negative['typed_timeout_payload']['timeout_kind'] ?? null) === 'heartbeat'
        && ($lateCompletion['http_status'] ?? null) === 409
        && ($lateCompletion['reason'] ?? null) === 'stale_attempt'
        && ($lateFailure['http_status'] ?? null) === 409
        && ($lateFailure['reason'] ?? null) === 'stale_attempt'
        && ($cell['managed_worker_deregistration']['response']['outcome'] ?? null) === 'deregistered'
        && ($negative['worker_deregistration']['outcome'] ?? null) === 'deregistered'
        && ($negative['worker_id'] ?? null) !== ($cell['worker_id'] ?? null)
        && ($negative['task_queue'] ?? null) !== ($cell['task_queue'] ?? null)
        && ($cell['isolated_cleanup']['scratch_removed_on_exit'] ?? null) === true;
    $observed = [
        'activity_host_evidence' => $cell['activity_host_evidence'] ?? null,
        'execution_source' => HOST_EVIDENCE_SOURCE,
        'activity_id' => $cell['activity_id'] ?? null,
        'workflow_run_id' => $cell['workflow_run_id'] ?? null,
        'activity_execution_id' => $cell['activity_execution_id'] ?? null,
        'activity_attempt_id' => $cell['activity_attempt_id'] ?? null,
        'task_queue' => $cell['task_queue'] ?? null,
        'worker_id' => $cell['worker_id'] ?? null,
        'managed_worker_deregistration' => $cell['managed_worker_deregistration'] ?? null,
        'php_sdk_worker_artifact' => $cell['php_sdk_worker_artifact'] ?? null,
        'heartbeat_timeout_seconds' => $cell['heartbeat_timeout_seconds'] ?? null,
        'heartbeat_cadence_seconds' => $cell['heartbeat_cadence_seconds'] ?? null,
        'initial_heartbeat_deadline_at' => $cell['initial_heartbeat_deadline_at'] ?? null,
        'handler_started_at' => $cell['handler_started_at'] ?? null,
        'handler_finished_at' => $cell['handler_finished_at'] ?? null,
        'in_flight_duration_seconds' => $cell['in_flight_duration_seconds'] ?? null,
        'heartbeat_acknowledgements' => $acknowledgements,
        'enforcement_passes' => $enforcementPasses,
        'completion_response' => $cell['completion_response'] ?? null,
        'terminal_history' => $terminalHistory,
        'negative_control' => $negative,
        'isolated_cleanup' => $cell['isolated_cleanup'] ?? null,
    ];
    $scenario = [
        'scenario_id' => 'heartbeat_timeout_renewal_across_enforcement_passes',
        'status' => $pass ? 'pass' : 'fail',
        'classification' => $pass ? null : 'product-gap',
        'observed_outputs' => array_filter($observed, static fn (mixed $value): bool => $value !== null && $value !== []),
        'scenario_evidence' => [
            'heartbeat_timeout_renewal' => $cell,
            'activity_host_evidence' => $cell['activity_host_evidence'] ?? null,
        ],
    ];
    if (! $pass) {
        $message = 'published PHP SDK heartbeat renewal did not preserve deadline advancement, repeated enforcement safety, exact-once completion, typed negative-control timeout, deterministic stale-attempt responses, and isolated cleanup';
        $scenario['observed_behavior'] = $message;
        $scenario['linked_findings'] = [finding_for_failure(
            'heartbeat_timeout_renewal_across_enforcement_passes',
            $message
        )];
    }

    return $scenario;
}

function run_typed_failure_propagation_cell(): array
{
    $suffix = bin2hex(random_bytes(3));
    $workerId = "activities-typed-failure-{$suffix}";
    $workflowId = "activities-typed-failure-{$suffix}";
    $taskQueue = scenario_task_queue($workflowId);
    $failureType = 'ActivitiesConformanceTypedFailure';
    $failureMessage = 'typed activity failure propagated from published artifact worker';
    $failureClass = 'DurableWorkflow\\Conformance\\Activities\\TypedActivityFailure';
    $failureDetails = [
        'failure_code' => 'ACTIVITY_TYPED_FAILURE',
        'stage' => 'typed_failure_propagation',
        'retry_after_seconds' => 45,
        'runtime' => 'workflow-php',
    ];
    $failurePayload = [
        'message' => $failureMessage,
        'type' => $failureType,
        'class' => $failureClass,
        'code' => 409,
        'stack_trace' => 'at activities.conformance.typed_failure:42',
        'non_retryable' => true,
        'retryable' => false,
        'details' => envelope($failureDetails, CodecRegistry::defaultCodec()),
        'runtime_diagnostics' => [
            'runtime' => 'workflow-php',
            'scenario_id' => 'typed_failure_propagation',
        ],
    ];

    register_worker($workerId, $taskQueue, [EMBEDDED_WORKFLOW_TYPE], [ACTIVITY_TYPE], 'workflow-php');
    $start = request_json('POST', '/workflows', [
        'workflow_id' => $workflowId,
        'workflow_type' => EMBEDDED_WORKFLOW_TYPE,
        'task_queue' => $taskQueue,
        'input' => [[
            'scenario_id' => 'typed_failure_propagation',
            'runtime' => 'workflow-php',
            'input_marker' => "typed-failure-{$suffix}",
            'task_queue' => $taskQueue,
        ]],
    ]);
    $runId = (string) ($start['run_id'] ?? '');
    if ($runId === '') {
        throw new RuntimeException('typed failure workflow start did not return a run id');
    }
    $expectedIdentity = ['workflow_id' => $workflowId, 'run_id' => $runId];

    $workflowTask = poll_task('workflow', $workerId, $taskQueue, $expectedIdentity);
    complete_workflow_task_from_runtime($workflowTask);

    $activityExecutionId = activity_execution_id_for_run($runId, 'typed failure propagation');
    $activityTask = poll_task('activity', $workerId, $taskQueue, [
        ...$expectedIdentity,
        'activity_execution_id' => $activityExecutionId,
    ]);
    $failResponse = fail_activity_task($activityTask, $failurePayload);
    if (($failResponse['outcome'] ?? null) !== 'failed' || ($failResponse['recorded'] ?? null) !== true) {
        throw new RuntimeException('typed failure activity report was not durably recorded');
    }

    $resumeTask = poll_task('workflow', $workerId, $taskQueue, $expectedIdentity);
    $workflowComplete = complete_workflow_task_from_runtime($resumeTask);

    $run = request_json('GET', '/workflows/'.rawurlencode($workflowId).'/runs/'.rawurlencode($runId));
    $history = request_json('GET', '/workflows/'.rawurlencode($workflowId).'/runs/'.rawurlencode($runId).'/history');
    $activityFailedPayloads = history_payloads_for_event($history, HistoryEventType::ActivityFailed->value);
    $activityFailedPayload = is_array($activityFailedPayloads[0] ?? null) ? $activityFailedPayloads[0] : [];
    $historyException = is_array($activityFailedPayload['exception'] ?? null)
        ? $activityFailedPayload['exception']
        : [];
    $historyDetails = array_key_exists('details', $historyException)
        ? decode_payload($historyException['details'], $historyException['details_payload_codec'] ?? null)
        : null;
    $workflowOutput = normalized_workflow_output($run['output'] ?? null);
    $callerObservedFailure = is_array($workflowOutput['caller_observed_failure'] ?? null)
        ? $workflowOutput['caller_observed_failure']
        : [];

    /** @var ActivityExecution|null $execution */
    $execution = ActivityExecution::query()->find((string) ($activityTask['activity_execution_id'] ?? ''));
    /** @var WorkflowFailure|null $failure */
    $failure = WorkflowFailure::query()
        ->where('workflow_run_id', $runId)
        ->where('source_id', (string) ($activityTask['activity_execution_id'] ?? ''))
        ->first();

    $historyPreservedFailure = ($activityFailedPayload['exception_type'] ?? null) === $failureType
        && ($activityFailedPayload['message'] ?? null) === $failureMessage
        && ($historyException['type'] ?? null) === $failureType
        && ($historyException['message'] ?? null) === $failureMessage
        && $historyDetails === $failureDetails;
    $callerObservedTypedFailure = ($callerObservedFailure['status'] ?? null) === 'caught'
        && ($callerObservedFailure['failure_type'] ?? null) === $failureType
        && ($callerObservedFailure['failure_message'] ?? null) === $failureMessage
        && ($callerObservedFailure['failure_details'] ?? null) === $failureDetails;
    $failureRowPreservedType = $failure instanceof WorkflowFailure
        && $failure->exception_class === $failureClass
        && $failure->message === $failureMessage;

    if (($run['status'] ?? null) !== RunStatus::Completed->value) {
        throw new RuntimeException('typed failure workflow did not complete after catching the activity failure');
    }
    if (! $historyPreservedFailure) {
        throw new RuntimeException('typed failure history did not preserve type, message, and decoded details');
    }
    if (! $callerObservedTypedFailure) {
        throw new RuntimeException('typed failure was not observed by the caller runtime with type, message, and details');
    }
    if (! $failureRowPreservedType) {
        throw new RuntimeException('typed failure row did not preserve exception class and message');
    }

    return [
        'scenario_id' => 'typed_failure_propagation',
        'mode' => 'workflow-embedded',
        'runtime' => 'workflow-php',
        'status' => 'pass',
        'execution_source' => HOST_EVIDENCE_SOURCE,
        'workflow_id' => $workflowId,
        'run_id' => $runId,
        'task_queue' => $taskQueue,
        'activity_execution_id' => $activityTask['activity_execution_id'] ?? null,
        'activity_attempt_id' => $activityTask['activity_attempt_id'] ?? null,
        'activity_type' => $activityTask['activity_type'] ?? ACTIVITY_TYPE,
        'failure_type' => $failureType,
        'failure_message' => $failureMessage,
        'failure_details' => $failureDetails,
        'history_exception' => $historyException,
        'history_details' => $historyDetails,
        'history_preserved_failure' => $historyPreservedFailure,
        'caller_observed_failure' => $callerObservedFailure,
        'caller_observed_typed_failure' => $callerObservedTypedFailure,
        'failure_row_preserved_type' => $failureRowPreservedType,
        'failure_report' => [
            'request' => [
                'message' => $failurePayload['message'],
                'type' => $failurePayload['type'],
                'class' => $failurePayload['class'],
                'non_retryable' => $failurePayload['non_retryable'],
                'details' => $failureDetails,
            ],
            'response' => $failResponse,
        ],
        'failure_row' => $failure instanceof WorkflowFailure ? [
            'failure_category' => $failure->failure_category instanceof BackedEnum
                ? $failure->failure_category->value
                : (string) $failure->failure_category,
            'propagation_kind' => $failure->propagation_kind,
            'exception_class' => $failure->exception_class,
            'message' => $failure->message,
            'non_retryable' => (bool) $failure->non_retryable,
        ] : null,
        'execution_state' => $execution instanceof ActivityExecution ? [
            'status' => $execution->status instanceof BackedEnum ? $execution->status->value : (string) $execution->status,
            'exception' => $execution->exception,
            'attempt_count' => $execution->attempt_count,
            'closed_at' => $execution->closed_at?->toJSON(),
        ] : null,
        'workflow_output' => $workflowOutput,
        'history_events' => event_types($history),
        'activity_failed_history_events' => $activityFailedPayloads,
        'worker_protocol' => [
            'activity_task_failure' => $failResponse['outcome'] ?? null,
            'activity_task_recorded' => $failResponse['recorded'] ?? null,
            'workflow_task_completion_after_failure' => $workflowComplete['outcome'] ?? null,
            'run_status_after_caller_observation' => $run['status'] ?? null,
            'registered_runtime' => 'php',
        ],
        'local_product_source_checkouts_used' => false,
    ];
}

function scenario_from_typed_failure_cell(array $cell): array
{
    $historyEvents = is_array($cell['history_events'] ?? null) ? $cell['history_events'] : [];
    $pass = ($cell['status'] ?? null) === 'pass'
        && is_string($cell['failure_type'] ?? null)
        && ($cell['failure_type'] ?? '') !== ''
        && is_string($cell['failure_message'] ?? null)
        && ($cell['failure_message'] ?? '') !== ''
        && is_array($cell['failure_details'] ?? null)
        && is_array($cell['history_exception'] ?? null)
        && is_array($cell['caller_observed_failure'] ?? null)
        && ($cell['history_preserved_failure'] ?? null) === true
        && ($cell['caller_observed_typed_failure'] ?? null) === true
        && in_array(HistoryEventType::ActivityFailed->value, $historyEvents, true);
    $hostEvidence = [
        'schema' => HOST_EVIDENCE_SCHEMA,
        'scenario_id' => 'typed_failure_propagation',
        'status' => $pass ? 'pass' : 'fail',
        'execution_source' => HOST_EVIDENCE_SOURCE,
        'executed_in_pinned_server_artifact' => true,
        'local_product_source_checkouts_used' => false,
        'activity_cells' => [[
            'mode' => 'workflow-embedded',
            'runtime' => 'workflow-php',
            'status' => $pass ? 'pass' : 'fail',
            'execution_source' => HOST_EVIDENCE_SOURCE,
            'activity_execution_id' => $cell['activity_execution_id'] ?? null,
            'activity_attempt_id' => $cell['activity_attempt_id'] ?? null,
            'local_product_source_checkouts_used' => false,
        ]],
    ];
    $observed = [
        'activity_host_evidence' => $hostEvidence,
        'execution_source' => HOST_EVIDENCE_SOURCE,
        'workflow_id' => $cell['workflow_id'] ?? null,
        'run_id' => $cell['run_id'] ?? null,
        'activity_execution_id' => $cell['activity_execution_id'] ?? null,
        'activity_attempt_id' => $cell['activity_attempt_id'] ?? null,
        'failure_type' => $cell['failure_type'] ?? null,
        'failure_message' => $cell['failure_message'] ?? null,
        'failure_details' => $cell['failure_details'] ?? null,
        'history_exception' => $cell['history_exception'] ?? null,
        'caller_observed_failure' => $cell['caller_observed_failure'] ?? null,
        'failure_report' => $cell['failure_report'] ?? null,
        'failure_row' => $cell['failure_row'] ?? null,
        'execution_state' => $cell['execution_state'] ?? null,
        'workflow_output' => $cell['workflow_output'] ?? null,
        'history_events' => $historyEvents,
        'activity_failed_history_events' => $cell['activity_failed_history_events'] ?? null,
        'worker_protocol' => $cell['worker_protocol'] ?? null,
    ];

    $scenario = [
        'scenario_id' => 'typed_failure_propagation',
        'status' => $pass ? 'pass' : 'fail',
        'classification' => $pass ? null : 'product-gap',
        'observed_outputs' => array_filter($observed, static fn (mixed $value): bool => $value !== null && $value !== []),
        'scenario_evidence' => array_filter([
            'typed_failure_propagation' => $cell,
            'activity_host_evidence' => $hostEvidence,
        ], static fn (mixed $value): bool => $value !== null && $value !== []),
    ];

    if (! $pass) {
        $message = 'activity typed failure propagation did not prove type, message, details, history visibility, and caller-runtime observation';
        $scenario['observed_behavior'] = $message;
        $scenario['linked_findings'] = [finding_for_failure('typed_failure_propagation', $message)];
    }

    return $scenario;
}

function scenario_from_timeout_behavior_cell(array $cell): array
{
    $historyEvents = is_array($cell['history_events'] ?? null) ? $cell['history_events'] : [];
    $pass = ($cell['status'] ?? null) === 'pass'
        && ($cell['timeout_type'] ?? null) === 'start_to_close'
        && ($cell['deadline_visible_to_worker'] ?? null) === true
        && ($cell['server_expired_scan_visible'] ?? null) === true
        && ($cell['typed_timeout_recorded'] ?? null) === true
        && ($cell['activity_status'] ?? null) === ActivityStatus::Failed->value
        && in_array(HistoryEventType::ActivityTimedOut->value, $historyEvents, true);
    $hostEvidence = [
        'schema' => HOST_EVIDENCE_SCHEMA,
        'scenario_id' => 'timeout_behavior',
        'status' => $pass ? 'pass' : 'fail',
        'execution_source' => HOST_EVIDENCE_SOURCE,
        'executed_in_pinned_server_artifact' => true,
        'local_product_source_checkouts_used' => false,
        'activity_cells' => [[
            'mode' => 'standalone',
            'runtime' => 'workflow-php',
            'status' => $pass ? 'pass' : 'fail',
            'execution_source' => HOST_EVIDENCE_SOURCE,
            'activity_execution_id' => $cell['activity_execution_id'] ?? null,
            'activity_attempt_id' => $cell['activity_attempt_id'] ?? null,
            'worker_visible_deadlines' => $cell['worker_visible_deadlines'] ?? null,
            'local_product_source_checkouts_used' => false,
        ]],
    ];
    $observed = [
        'activity_host_evidence' => $hostEvidence,
        'execution_source' => HOST_EVIDENCE_SOURCE,
        'activity_id' => $cell['activity_id'] ?? null,
        'workflow_run_id' => $cell['workflow_run_id'] ?? null,
        'activity_execution_id' => $cell['activity_execution_id'] ?? null,
        'activity_attempt_id' => $cell['activity_attempt_id'] ?? null,
        'configured_timeout_inputs' => $cell['configured_timeout_inputs'] ?? null,
        'timeout_type' => $cell['timeout_type'] ?? null,
        'deadline_at' => $cell['deadline_at'] ?? null,
        'worker_visible_deadlines' => $cell['worker_visible_deadlines'] ?? null,
        'deadline_visible_to_worker' => $cell['deadline_visible_to_worker'] ?? null,
        'timeout_status_before_enforce' => $cell['timeout_status_before_enforce'] ?? null,
        'enforcement_endpoint' => $cell['enforcement_endpoint'] ?? null,
        'enforcement_observed_at' => $cell['enforcement_observed_at'] ?? null,
        'enforce_response' => $cell['enforce_response'] ?? null,
        'typed_timeout_payload' => $cell['typed_timeout_payload'] ?? null,
        'activity_status' => $cell['activity_status'] ?? null,
        'caller_visible_outcome' => $cell['caller_visible_outcome'] ?? null,
        'attempt_state' => $cell['attempt_state'] ?? null,
        'execution_state' => $cell['execution_state'] ?? null,
        'history_events' => $historyEvents,
        'timeout_history_events' => $cell['timeout_history_events'] ?? null,
        'workflow_failed_history_events' => $cell['workflow_failed_history_events'] ?? null,
    ];

    $scenario = [
        'scenario_id' => 'timeout_behavior',
        'status' => $pass ? 'pass' : 'fail',
        'classification' => $pass ? null : 'product-gap',
        'observed_outputs' => array_filter($observed, static fn (mixed $value): bool => $value !== null && $value !== []),
        'scenario_evidence' => array_filter([
            'timeout_behavior' => $cell,
            'activity_host_evidence' => $hostEvidence,
        ], static fn (mixed $value): bool => $value !== null && $value !== []),
    ];

    if (! $pass) {
        $message = 'activity timeout behavior did not prove worker-visible deadline, typed timeout history, and caller-visible timed-out closure';
        $scenario['observed_behavior'] = $message;
        $scenario['linked_findings'] = [finding_for_failure('timeout_behavior', $message)];
    }

    return $scenario;
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

function scenario_from_heartbeat_cancellation_cell(array $cell): array
{
    $pass = ($cell['status'] ?? null) === 'pass'
        && is_array($cell['heartbeat_details'] ?? null)
        && is_array($cell['heartbeat_history_event'] ?? null)
        && is_array($cell['cancel_requested_response'] ?? null)
        && is_array($cell['terminal_cancellation_state'] ?? null)
        && ($cell['worker_observed_cancellation'] ?? null) === true
        && ($cell['heartbeat_recorded'] ?? null) === true
        && ($cell['terminal_cancellation_state']['documented_terminal_state_observed'] ?? null) === true;
    $hostEvidence = [
        'schema' => HOST_EVIDENCE_SCHEMA,
        'scenario_id' => 'heartbeat_and_cancellation_observation',
        'status' => $pass ? 'pass' : 'fail',
        'execution_source' => HOST_EVIDENCE_SOURCE,
        'executed_in_pinned_server_artifact' => true,
        'local_product_source_checkouts_used' => false,
        'activity_cells' => [[
            'mode' => 'standalone',
            'runtime' => 'workflow-php',
            'status' => $pass ? 'pass' : 'fail',
            'execution_source' => HOST_EVIDENCE_SOURCE,
            'activity_execution_id' => $cell['activity_execution_id'] ?? null,
            'activity_attempt_id' => $cell['activity_attempt_id'] ?? null,
            'local_product_source_checkouts_used' => false,
        ]],
    ];
    $observed = [
        'activity_host_evidence' => $hostEvidence,
        'execution_source' => HOST_EVIDENCE_SOURCE,
        'activity_id' => $cell['activity_id'] ?? null,
        'workflow_run_id' => $cell['workflow_run_id'] ?? null,
        'activity_execution_id' => $cell['activity_execution_id'] ?? null,
        'activity_attempt_id' => $cell['activity_attempt_id'] ?? null,
        'heartbeat_details' => $cell['heartbeat_details'] ?? null,
        'heartbeat_response' => $cell['heartbeat_response'] ?? null,
        'heartbeat_history_event' => $cell['heartbeat_history_event'] ?? null,
        'cancel_response' => $cell['cancel_response'] ?? null,
        'cancel_requested_response' => $cell['cancel_requested_response'] ?? null,
        'worker_observed_cancellation' => $cell['worker_observed_cancellation'] ?? null,
        'activity_handle_after_heartbeat' => $cell['activity_handle_after_heartbeat'] ?? null,
        'activity_handle_after_cancel' => $cell['activity_handle_after_cancel'] ?? null,
        'late_completion_after_cancel_response' => $cell['late_completion_after_cancel_response'] ?? null,
        'terminal_cancellation_state' => $cell['terminal_cancellation_state'] ?? null,
        'attempt_state' => $cell['attempt_state'] ?? null,
        'execution_state' => $cell['execution_state'] ?? null,
        'history_events_after_heartbeat' => $cell['history_events_after_heartbeat'] ?? null,
        'history_events_after_cancel' => $cell['history_events_after_cancel'] ?? null,
    ];

    $scenario = [
        'scenario_id' => 'heartbeat_and_cancellation_observation',
        'status' => $pass ? 'pass' : 'fail',
        'classification' => $pass ? null : 'product-gap',
        'observed_outputs' => array_filter($observed, static fn (mixed $value): bool => $value !== null && $value !== []),
        'scenario_evidence' => array_filter([
            'heartbeat_cancellation' => $cell,
            'activity_host_evidence' => $hostEvidence,
        ], static fn (mixed $value): bool => $value !== null && $value !== []),
    ];

    if (! $pass) {
        $message = 'activity heartbeat/cancellation did not prove heartbeat details, cancel_requested observation, and terminal cancelled or failed state';
        $scenario['observed_behavior'] = $message;
        $scenario['linked_findings'] = [finding_for_failure('heartbeat_and_cancellation_observation', $message)];
    }

    return $scenario;
}

function scenario_from_idempotent_completion_cell(array $cell): array
{
    $pass = ($cell['status'] ?? null) === 'pass'
        && is_array($cell['first_completion_response'] ?? null)
        && is_array($cell['duplicate_completion_response'] ?? null)
        && is_string($cell['activity_attempt_id'] ?? null)
        && ($cell['recorded_once'] ?? null) === true
        && ($cell['same_task_and_attempt_ids'] ?? null) === true
        && ($cell['stale_attempt_or_idempotent_verdict'] ?? null) === 'stale_attempt'
        && ($cell['activity_completed_history_count'] ?? null) === 1;
    $hostEvidence = [
        'schema' => HOST_EVIDENCE_SCHEMA,
        'scenario_id' => 'idempotent_completion_handling',
        'status' => $pass ? 'pass' : 'fail',
        'execution_source' => HOST_EVIDENCE_SOURCE,
        'executed_in_pinned_server_artifact' => true,
        'local_product_source_checkouts_used' => false,
        'activity_cells' => [[
            'mode' => 'standalone',
            'runtime' => 'workflow-php',
            'status' => $pass ? 'pass' : 'fail',
            'execution_source' => HOST_EVIDENCE_SOURCE,
            'activity_execution_id' => $cell['activity_execution_id'] ?? null,
            'activity_attempt_id' => $cell['activity_attempt_id'] ?? null,
            'local_product_source_checkouts_used' => false,
        ]],
    ];
    $observed = [
        'activity_host_evidence' => $hostEvidence,
        'execution_source' => HOST_EVIDENCE_SOURCE,
        'activity_id' => $cell['activity_id'] ?? null,
        'workflow_run_id' => $cell['workflow_run_id'] ?? null,
        'activity_execution_id' => $cell['activity_execution_id'] ?? null,
        'activity_attempt_id' => $cell['activity_attempt_id'] ?? null,
        'first_completion_response' => $cell['first_completion_response'] ?? null,
        'duplicate_completion_response' => $cell['duplicate_completion_response'] ?? null,
        'recorded_once' => $cell['recorded_once'] ?? null,
        'same_task_and_attempt_ids' => $cell['same_task_and_attempt_ids'] ?? null,
        'stale_attempt_or_idempotent_verdict' => $cell['stale_attempt_or_idempotent_verdict'] ?? null,
        'activity_completed_history_count' => $cell['activity_completed_history_count'] ?? null,
        'activity_completed_history_events' => $cell['activity_completed_history_events'] ?? null,
        'terminal_result' => $cell['terminal_result'] ?? null,
        'attempt_state' => $cell['attempt_state'] ?? null,
        'execution_state' => $cell['execution_state'] ?? null,
        'history_events' => $cell['history_events'] ?? null,
    ];

    $scenario = [
        'scenario_id' => 'idempotent_completion_handling',
        'status' => $pass ? 'pass' : 'fail',
        'classification' => $pass ? null : 'product-gap',
        'observed_outputs' => array_filter($observed, static fn (mixed $value): bool => $value !== null && $value !== []),
        'scenario_evidence' => array_filter([
            'idempotent_completion' => $cell,
            'activity_host_evidence' => $hostEvidence,
        ], static fn (mixed $value): bool => $value !== null && $value !== []),
    ];

    if (! $pass) {
        $message = 'activity idempotent completion did not prove deterministic stale_attempt response after exactly one terminal completion';
        $scenario['observed_behavior'] = $message;
        $scenario['linked_findings'] = [finding_for_failure('idempotent_completion_handling', $message)];
    }

    return $scenario;
}

function scenario_from_php_python_parity_cell(array $cell): array
{
    $shape = is_array($cell['cross_language_payload_shape'] ?? null) ? $cell['cross_language_payload_shape'] : [];
    $parityObservations = is_array($cell['parity_observations'] ?? null) ? $cell['parity_observations'] : [];
    $runtimeMatrix = is_array($cell['runtime_matrix'] ?? null) ? $cell['runtime_matrix'] : [];
    $activityCells = is_array($runtimeMatrix['activity_cells'] ?? null) ? $runtimeMatrix['activity_cells'] : [];
    $pass = ($cell['status'] ?? null) === 'pass'
        && is_array($cell['php_activity_result'] ?? null)
        && is_array($cell['python_activity_result'] ?? null)
        && ($shape['matches'] ?? null) === true
        && isset(
            $parityObservations['result'],
            $parityObservations['failure'],
            $parityObservations['retry'],
            $parityObservations['timeout'],
            $parityObservations['heartbeat'],
            $parityObservations['cancellation']
        )
        && ! in_array(false, array_map(
            static fn (mixed $observation): bool => is_array($observation) && ($observation['matches'] ?? null) === true,
            $parityObservations
        ), true)
        && $activityCells !== [];
    $hostEvidence = [
        'schema' => HOST_EVIDENCE_SCHEMA,
        'scenario_id' => 'php_python_activity_parity',
        'status' => $pass ? 'pass' : 'fail',
        'execution_source' => HOST_EVIDENCE_SOURCE,
        'executed_in_pinned_server_artifact' => true,
        'local_product_source_checkouts_used' => false,
        'activity_cells' => $activityCells,
    ];
    $observed = [
        'activity_host_evidence' => $hostEvidence,
        'execution_source' => HOST_EVIDENCE_SOURCE,
        'php_activity_id' => $cell['php_activity_id'] ?? null,
        'python_activity_id' => $cell['python_activity_id'] ?? null,
        'php_workflow_run_id' => $cell['php_workflow_run_id'] ?? null,
        'python_workflow_run_id' => $cell['python_workflow_run_id'] ?? null,
        'php_activity_execution_id' => $cell['php_activity_execution_id'] ?? null,
        'python_activity_execution_id' => $cell['python_activity_execution_id'] ?? null,
        'php_activity_result' => $cell['php_activity_result'] ?? null,
        'python_activity_result' => $cell['python_activity_result'] ?? null,
        'cross_language_payload_shape' => $shape,
        'cross_language_failure_shape' => $cell['cross_language_failure_shape'] ?? null,
        'cross_language_retry_shape' => $cell['cross_language_retry_shape'] ?? null,
        'cross_language_timeout_shape' => $cell['cross_language_timeout_shape'] ?? null,
        'cross_language_heartbeat_shape' => $cell['cross_language_heartbeat_shape'] ?? null,
        'cross_language_cancellation_shape' => $cell['cross_language_cancellation_shape'] ?? null,
        'parity_observations' => $parityObservations,
        'runtime_matrix' => $runtimeMatrix,
        'heartbeat_observations' => $cell['heartbeat_observations'] ?? null,
        'failure_observations' => $cell['failure_observations'] ?? null,
        'retry_observations' => $cell['retry_observations'] ?? null,
        'timeout_observations' => $cell['timeout_observations'] ?? null,
        'cancellation_observations' => $cell['cancellation_observations'] ?? null,
        'completion_responses' => $cell['completion_responses'] ?? null,
        'handle_responses' => $cell['handle_responses'] ?? null,
        'history_events' => $cell['history_events'] ?? null,
        'worker_artifacts' => $cell['worker_artifacts'] ?? null,
    ];

    $scenario = [
        'scenario_id' => 'php_python_activity_parity',
        'status' => $pass ? 'pass' : 'fail',
        'classification' => $pass ? null : 'product-gap',
        'observed_outputs' => array_filter($observed, static fn (mixed $value): bool => $value !== null && $value !== []),
        'scenario_evidence' => array_filter([
            'php_python_activity_parity' => $cell,
            'activity_host_evidence' => $hostEvidence,
        ], static fn (mixed $value): bool => $value !== null && $value !== []),
    ];

    if (! $pass) {
        $message = 'PHP/Python activity parity did not prove compatible result, failure, retry, timeout, heartbeat, and cancellation observations with published sdk-python worker evidence';
        $scenario['observed_behavior'] = $message;
        $scenario['linked_findings'] = [finding_for_failure('php_python_activity_parity', $message)];
    }

    return $scenario;
}

function scenario_from_operator_visibility_cell(array $cell): array
{
    $statePasses = is_array($cell['operator_state_passes'] ?? null) ? $cell['operator_state_passes'] : [];
    $statePassesWithoutCli = is_array($cell['operator_state_passes_without_cli'] ?? null)
        ? $cell['operator_state_passes_without_cli']
        : [];
    $requiredStates = ['in_flight', 'retrying', 'timed_out', 'failed', 'completed', 'cancelled'];
    $staleQueueRegressionFixture = is_array($cell['stale_shared_queue_regression_fixture'] ?? null)
        ? $cell['stale_shared_queue_regression_fixture']
        : [];
    $nonCliSurfacesPass = array_diff($requiredStates, array_keys($statePassesWithoutCli)) === []
        && ! in_array(false, array_map(
            static fn (mixed $value): bool => $value === true,
            $statePassesWithoutCli
        ), true)
        && ($cell['api_run_detail']['api_visible'] ?? null) === true
        && ($cell['history_activity_attempts']['history_visible'] ?? null) === true
        && ($cell['operator_metrics']['lease_visible'] ?? null) === true
        && ($cell['waterline_activity_attempt_view']['waterline_visible'] ?? null) === true
        && ($staleQueueRegressionFixture['configured_backoff_seconds'] ?? null) === 60
        && ($staleQueueRegressionFixture['backoff_crossed_before_timed_out_poll'] ?? null) === true
        && ($staleQueueRegressionFixture['isolated_task_queues'] ?? null) === true
        && is_string($staleQueueRegressionFixture['timed_out_worker_visible_start_to_close_deadline'] ?? null);
    $cliOnlyFailure = $nonCliSurfacesPass
        && in_array(false, array_map(
            static fn (mixed $value): bool => $value === true,
            $statePasses
        ), true);
    $pass = ($cell['status'] ?? null) === 'pass'
        && is_array($cell['api_run_detail'] ?? null)
        && is_array($cell['history_activity_attempts'] ?? null)
        && is_array($cell['operator_metrics'] ?? null)
        && is_array($cell['waterline_activity_attempt_view'] ?? null)
        && is_array($cell['operator_state_matrix'] ?? null)
        && array_diff($requiredStates, array_keys($statePasses)) === []
        && ! in_array(false, array_map(
            static fn (mixed $value): bool => $value === true,
            $statePasses
        ), true)
        && ($cell['api_run_detail']['api_visible'] ?? null) === true
        && ($cell['history_activity_attempts']['history_visible'] ?? null) === true
        && ($cell['operator_metrics']['lease_visible'] ?? null) === true
        && ($cell['waterline_activity_attempt_view']['waterline_visible'] ?? null) === true;
    $hostEvidence = [
        'schema' => HOST_EVIDENCE_SCHEMA,
        'scenario_id' => 'operator_visible_activity_attempt_state',
        'status' => $pass ? 'pass' : 'fail',
        'execution_source' => HOST_EVIDENCE_SOURCE,
        'executed_in_pinned_server_artifact' => true,
        'local_product_source_checkouts_used' => false,
        'activity_cells' => [[
            'mode' => 'standalone',
            'runtime' => 'workflow-php',
            'status' => $pass ? 'pass' : 'fail',
            'execution_source' => HOST_EVIDENCE_SOURCE,
            'activity_execution_id' => $cell['activity_execution_id'] ?? null,
            'activity_attempt_id' => $cell['activity_attempt_id'] ?? null,
            'local_product_source_checkouts_used' => false,
        ]],
    ];
    $observed = [
        'activity_host_evidence' => $hostEvidence,
        'execution_source' => HOST_EVIDENCE_SOURCE,
        'activity_id' => $cell['activity_id'] ?? null,
        'workflow_run_id' => $cell['workflow_run_id'] ?? null,
        'activity_execution_id' => $cell['activity_execution_id'] ?? null,
        'activity_attempt_id' => $cell['activity_attempt_id'] ?? null,
        'api_run_detail' => $cell['api_run_detail'] ?? null,
        'history_activity_attempts' => $cell['history_activity_attempts'] ?? null,
        'operator_metrics' => $cell['operator_metrics'] ?? null,
        'waterline_activity_attempt_view' => $cell['waterline_activity_attempt_view'] ?? null,
        'cli_json_list_evidence' => $cell['cli_json_list_evidence'] ?? null,
        'required_operator_states' => $cell['required_operator_states'] ?? null,
        'operator_state_matrix' => $cell['operator_state_matrix'] ?? null,
        'stale_shared_queue_regression_fixture' => $staleQueueRegressionFixture,
        'operator_state_passes' => $statePasses,
        'operator_state_passes_without_cli' => $statePassesWithoutCli,
        'missing_operator_surface_reasons' => $cell['missing_operator_surface_reasons'] ?? null,
        'heartbeat_details' => $cell['heartbeat_details'] ?? null,
        'heartbeat_response' => $cell['heartbeat_response'] ?? null,
        'completion_response' => $cell['completion_response'] ?? null,
        'activity_result' => $cell['activity_result'] ?? null,
        'worker_artifact' => $cell['worker_artifact'] ?? null,
    ];

    $scenario = [
        'scenario_id' => 'operator_visible_activity_attempt_state',
        'status' => $pass ? 'pass' : 'fail',
        'classification' => $pass ? null : 'product-gap',
        'observed_outputs' => array_filter($observed, static fn (mixed $value): bool => $value !== null && $value !== []),
        'scenario_evidence' => array_filter([
            'operator_visibility' => $cell,
            'activity_host_evidence' => $hostEvidence,
        ], static fn (mixed $value): bool => $value !== null && $value !== []),
    ];

    if (! $pass) {
        $missing = is_array($cell['missing_operator_surface_reasons'] ?? null)
            ? implode(', ', $cell['missing_operator_surface_reasons'])
            : '';
        if ($cliOnlyFailure) {
            $message = 'official CLI activity list/detail JSON commands did not prove in-flight, retrying, timed-out, failed, completed, and cancelled activity attempt state';
            if ($missing !== '') {
                $message .= ': '.$missing;
            }
            $scenario['observed_behavior'] = $message;
            $scenario['linked_findings'] = [cli_activity_visibility_finding($message)];
        } else {
            $message = 'operator visibility did not prove in-flight, retrying, timed-out, failed, completed, and cancelled activity attempt state through API detail/list, history, task queue metrics, Waterline, and CLI/list evidence';
            if ($missing !== '') {
                $message .= ': '.$missing;
            }
            $scenario['observed_behavior'] = $message;
            $scenario['linked_findings'] = [finding_for_failure('operator_visible_activity_attempt_state', $message)];
        }
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
    $timeoutScenario = failure_behavior_scenario(
        'timeout_behavior',
        new RuntimeException('timeout behavior scenario did not execute')
    );
    try {
        $timeoutScenario = scenario_from_timeout_behavior_cell(run_timeout_behavior_cell());
    } catch (Throwable $throwable) {
        $timeoutScenario = failure_behavior_scenario('timeout_behavior', $throwable);
    }
    $typedFailureScenario = failure_behavior_scenario(
        'typed_failure_propagation',
        new RuntimeException('typed failure propagation scenario did not execute')
    );
    try {
        $typedFailureScenario = scenario_from_typed_failure_cell(run_typed_failure_propagation_cell());
    } catch (Throwable $throwable) {
        $typedFailureScenario = failure_behavior_scenario('typed_failure_propagation', $throwable);
    }
    $heartbeatScenario = failure_behavior_scenario(
        'heartbeat_and_cancellation_observation',
        new RuntimeException('heartbeat/cancellation scenario did not execute')
    );
    try {
        $heartbeatScenario = scenario_from_heartbeat_cancellation_cell(run_heartbeat_cancellation_cell());
    } catch (Throwable $throwable) {
        $heartbeatScenario = failure_behavior_scenario('heartbeat_and_cancellation_observation', $throwable);
    }
    $heartbeatTimeoutRenewalScenario = failure_behavior_scenario(
        'heartbeat_timeout_renewal_across_enforcement_passes',
        new RuntimeException('PHP SDK heartbeat timeout renewal scenario did not execute')
    );
    try {
        $heartbeatTimeoutRenewalScenario = scenario_from_heartbeat_timeout_renewal_cell(
            run_heartbeat_timeout_renewal_cell()
        );
    } catch (Throwable $throwable) {
        $heartbeatTimeoutRenewalScenario = failure_behavior_scenario(
            'heartbeat_timeout_renewal_across_enforcement_passes',
            $throwable
        );
    }
    $idempotentScenario = failure_behavior_scenario(
        'idempotent_completion_handling',
        new RuntimeException('idempotent completion scenario did not execute')
    );
    try {
        $idempotentScenario = scenario_from_idempotent_completion_cell(run_idempotent_completion_cell());
    } catch (Throwable $throwable) {
        $idempotentScenario = failure_behavior_scenario('idempotent_completion_handling', $throwable);
    }
    $parityScenario = failure_behavior_scenario(
        'php_python_activity_parity',
        new RuntimeException('PHP/Python activity parity scenario did not execute')
    );
    try {
        $parityScenario = scenario_from_php_python_parity_cell(run_php_python_parity_cell());
    } catch (Throwable $throwable) {
        $parityScenario = failure_behavior_scenario('php_python_activity_parity', $throwable);
    }
    $operatorVisibilityScenario = failure_behavior_scenario(
        'operator_visible_activity_attempt_state',
        new RuntimeException('operator visibility scenario did not execute')
    );
    try {
        $operatorVisibilityScenario = scenario_from_operator_visibility_cell(run_operator_visibility_cell());
    } catch (Throwable $throwable) {
        $operatorVisibilityScenario = failure_behavior_scenario('operator_visible_activity_attempt_state', $throwable);
    }

    write_json_file(output_path(), evidence_document([
        scenario_from_cells('workflow_embedded_activity_result', 'workflow-embedded', $embeddedCells),
        scenario_from_cells('standalone_activity_result', 'standalone', $standaloneCells),
        $restartScenario,
        $retryScenario,
        $timeoutScenario,
        $typedFailureScenario,
        $heartbeatScenario,
        $heartbeatTimeoutRenewalScenario,
        $idempotentScenario,
        $parityScenario,
        $operatorVisibilityScenario,
    ], array_merge($embeddedCells, $standaloneCells)));
} catch (Throwable $throwable) {
    write_json_file(output_path(), evidence_document([
        failure_scenario('workflow_embedded_activity_result', 'workflow-embedded', $throwable),
        failure_scenario('standalone_activity_result', 'standalone', $throwable),
        failure_behavior_scenario('durable_result_recording_after_worker_restart', $throwable),
        failure_behavior_scenario('retry_attempt_backoff_behavior', $throwable),
        failure_behavior_scenario('timeout_behavior', $throwable),
        failure_behavior_scenario('typed_failure_propagation', $throwable),
        failure_behavior_scenario('heartbeat_and_cancellation_observation', $throwable),
        failure_behavior_scenario('heartbeat_timeout_renewal_across_enforcement_passes', $throwable),
        failure_behavior_scenario('idempotent_completion_handling', $throwable),
        failure_behavior_scenario('php_python_activity_parity', $throwable),
        failure_behavior_scenario('operator_visible_activity_attempt_state', $throwable),
    ], []));
}
PHP
}

if should_run_focused_activity_host_probe; then
  if prepare_published_activity_artifacts; then
    run_focused_activity_host_probe
    if ! record_executed_php_activity_distributions; then
      export DW_ACTIVITIES_PREREQUISITE_FAILURE="$activity_prerequisite_failure"
    fi
  else
    export DW_ACTIVITIES_PREREQUISITE_FAILURE="$activity_prerequisite_failure"
  fi
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
const crypto = require('crypto');

const RESULT_DIR = process.env.RESULT_DIR;
const STARTED_AT = process.env.STARTED_AT;
const MANIFEST_PATH = process.env.SCENARIO_MANIFEST;

const PORTABLE_RESULT_LIMIT_BYTES = 4 * 1024 * 1024;
const PORTABLE_RESULT_TARGET_BYTES = 3 * 1024 * 1024;
const PORTABLE_EVIDENCE_CELL_LIMIT_BYTES = 64 * 1024;
const PORTABLE_EVIDENCE_STRING_LIMIT_BYTES = 4 * 1024;
const PORTABLE_EVIDENCE_COLLECTION_LIMIT = 32;
const PORTABLE_EVIDENCE_DEPTH_LIMIT = 8;
const PORTABLE_FINDING_LIMIT = 64;

const REQUIRED_SCENARIOS = [
  'published_artifact_install_only',
  'workflow_embedded_activity_result',
  'standalone_activity_result',
  'durable_result_recording_after_worker_restart',
  'retry_attempt_backoff_behavior',
  'timeout_behavior',
  'typed_failure_propagation',
  'heartbeat_and_cancellation_observation',
  'heartbeat_timeout_renewal_across_enforcement_passes',
  'idempotent_completion_handling',
  'php_python_activity_parity',
  'operator_visible_activity_attempt_state',
];

const REQUIRED_INSTALL_ARTIFACTS = [
  'server',
  'cli',
  'sdk-php',
  'sdk-python',
  'workflow-php',
  'waterline',
];

const REQUIRED_DISTRIBUTION_IDENTITIES = [
  'workflow',
  'waterline',
  'server',
  'cli',
  'sdk-php',
  'sdk-python',
];

const DISTRIBUTION_COMPONENTS = {
  workflow: { kind: 'composer', package: 'durable-workflow/workflow', versionKey: 'workflow' },
  waterline: { kind: 'composer', package: 'durable-workflow/waterline', versionKey: 'waterline' },
  server: { kind: 'oci', package: 'docker.io/durableworkflow/server', versionKey: 'server' },
  cli: { kind: 'github-release', package: 'durable-workflow/cli', versionKey: 'cli' },
  'sdk-php': { kind: 'composer', package: 'durable-workflow/sdk', versionKey: 'sdk-php' },
  'sdk-python': { kind: 'pypi', package: 'durable-workflow', versionKey: 'sdk-python' },
};

const DISTRIBUTION_VERSION_PATTERN = /^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:-(?:0|[1-9]\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9]\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*))*)?$/;
const DISTRIBUTION_DIGEST_PATTERN = /^[0-9a-f]{64}$/;

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
  heartbeat_timeout_renewal_across_enforcement_passes:
    'an exact published PHP SDK worker renews the authoritative heartbeat deadline across repeated timeout enforcement, completes once without contradictory timeout history, and retains typed stale-attempt behavior when heartbeats stop',
  idempotent_completion_handling:
    'duplicate completion attempts do not create duplicate terminal records and return a deterministic worker-protocol response',
  php_python_activity_parity:
    'PHP and Python activity workers produce compatible payload, failure, retry, timeout, and heartbeat observations where both runtimes support the surface',
  operator_visible_activity_attempt_state:
    'operators can see current and historical activity attempt state through API metrics and Waterline',
};

const SEMVER_RE = /^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:-(?:0|[1-9]\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9]\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*))*)?$/;
const PYTHON_RELEASE_RE = /^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:(?:a|b|rc)(?:0|[1-9]\d*)|-(?:alpha|beta|rc)\.(?:0|[1-9]\d*))?$/i;
const SERVER_TAG_RE = /(?::|\/)((?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:-(?:0|[1-9]\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9]\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*))*)?)$/;
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

function encodedResultBytes(value) {
  return Buffer.from(`${JSON.stringify(value, null, 2)}\n`, 'utf8');
}

function portableJsonBytes(value) {
  const encoded = JSON.stringify(value);
  return Buffer.from(encoded === undefined ? String(value) : encoded, 'utf8');
}

function portableValueSummary(value, reason) {
  const encoded = portableJsonBytes(value);
  return {
    retained: false,
    reason,
    original_type: Array.isArray(value) ? 'array' : typeof value,
    original_bytes: encoded.length,
    sha256: crypto.createHash('sha256').update(encoded).digest('hex'),
  };
}

function portableSensitiveKey(key) {
  const normalized = String(key).toLowerCase().replace(/[^a-z0-9]+/g, '_');
  return /(^|_)(access_token|authorization|cookie|password|private_key|secret|token)($|_)/.test(normalized);
}

function portablePriorityKey(key) {
  return new Set([
    'scenario_id',
    'status',
    'classification',
    'execution_source',
    'local_product_source_checkouts_used',
    'activity_host_evidence',
    'published_artifact_worker_execution',
    'worker_artifact',
    'mode',
    'runtime',
    'artifact',
    'version',
    'source',
  ]).has(String(key));
}

function portableValue(value, depth = 0) {
  if (value === null || typeof value === 'boolean' || typeof value === 'number') {
    return value;
  }
  if (typeof value === 'string') {
    return Buffer.byteLength(value, 'utf8') <= PORTABLE_EVIDENCE_STRING_LIMIT_BYTES
      ? value
      : portableValueSummary(value, 'string_limit');
  }
  if (depth >= PORTABLE_EVIDENCE_DEPTH_LIMIT) {
    return portableValueSummary(value, 'depth_limit');
  }
  if (Array.isArray(value)) {
    const retained = value
      .slice(0, PORTABLE_EVIDENCE_COLLECTION_LIMIT)
      .map((item) => portableValue(item, depth + 1));
    if (value.length > PORTABLE_EVIDENCE_COLLECTION_LIMIT) {
      retained.push({
        ...portableValueSummary(value, 'collection_limit'),
        omitted_items: value.length - PORTABLE_EVIDENCE_COLLECTION_LIMIT,
      });
    }
    return retained;
  }
  if (value && typeof value === 'object') {
    const keys = Object.keys(value).sort((left, right) => {
      const priority = Number(portablePriorityKey(right)) - Number(portablePriorityKey(left));
      return priority || left.localeCompare(right);
    });
    const retained = {};
    let omittedKeys = 0;
    let sensitiveKeys = 0;
    for (const key of keys) {
      if (portableSensitiveKey(key)) {
        sensitiveKeys += 1;
        continue;
      }
      if (Object.keys(retained).length >= PORTABLE_EVIDENCE_COLLECTION_LIMIT) {
        omittedKeys += 1;
        continue;
      }
      retained[key] = portableValue(value[key], depth + 1);
    }
    if (omittedKeys > 0 || sensitiveKeys > 0) {
      retained._portable_evidence_omitted = {
        ...portableValueSummary(value, 'collection_or_sensitive_key_limit'),
        omitted_keys: omittedKeys,
        sensitive_keys: sensitiveKeys,
      };
    }
    return retained;
  }
  return portableValue(String(value), depth);
}

function boundedPortableCell(value) {
  const retained = portableValue(value);
  return portableJsonBytes(retained).length <= PORTABLE_EVIDENCE_CELL_LIMIT_BYTES
    ? retained
    : portableValueSummary(value, 'evidence_cell_limit');
}

function portableOmission(value, retainedKeys) {
  const omittedKeys = Object.keys(value || {}).filter((key) => !retainedKeys.includes(key));
  return omittedKeys.length === 0
    ? null
    : {
      ...portableValueSummary(value, 'non_decisive_fields_omitted'),
      omitted_keys: omittedKeys.length,
    };
}

function portableWorkerArtifact(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return boundedPortableCell(value);
  }
  const keys = [
    'artifact',
    'package',
    'version',
    'source',
    'status',
    'runtime',
    'language',
    'execution_source',
    'execution_method',
    'local_product_source_checkouts_used',
  ];
  const retained = Object.fromEntries(keys.filter((key) => key in value).map((key) => [key, boundedPortableCell(value[key])]));
  const omitted = portableOmission(value, keys);
  if (omitted) {
    retained._portable_evidence_omitted = omitted;
  }
  return retained;
}

function portableActivityHostCell(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return boundedPortableCell(value);
  }
  const keys = [
    'mode',
    'runtime',
    'status',
    'execution_source',
    'local_product_source_checkouts_used',
    'worker_protocol',
    'worker_artifact',
  ];
  const retained = {};
  for (const key of keys) {
    if (!(key in value)) {
      continue;
    }
    retained[key] = key === 'worker_artifact'
      ? portableWorkerArtifact(value[key])
      : boundedPortableCell(value[key]);
  }
  const omitted = portableOmission(value, keys);
  if (omitted) {
    retained._portable_evidence_omitted = omitted;
  }
  return retained;
}

function portableActivityHostEvidence(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return boundedPortableCell(value);
  }
  const keys = [
    'schema',
    'status',
    'scenario_id',
    'execution_source',
    'local_product_source_checkouts_used',
    'activity_cells',
  ];
  const retained = Object.fromEntries(keys
    .filter((key) => key in value && key !== 'activity_cells')
    .map((key) => [key, boundedPortableCell(value[key])]));
  retained.activity_cells = Array.isArray(value.activity_cells)
    ? value.activity_cells.map(portableActivityHostCell)
    : [];
  const omitted = portableOmission(value, keys);
  if (omitted) {
    retained._portable_evidence_omitted = omitted;
  }
  return retained;
}

function portablePublishedExecution(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return boundedPortableCell(value);
  }
  const keys = [
    'schema',
    'status',
    'execution_source',
    'execution_environment',
    'worker_execution_mode',
    'executed_in_pinned_server_artifact',
    'local_product_source_checkouts_used',
    'source_integrity_statement',
    'image_identity',
    'artifacts',
  ];
  const retained = Object.fromEntries(keys
    .filter((key) => key in value && key !== 'artifacts')
    .map((key) => [key, boundedPortableCell(value[key])]));
  retained.artifacts = Array.isArray(value.artifacts)
    ? value.artifacts.map(portableWorkerArtifact)
    : [];
  const omitted = portableOmission(value, keys);
  if (omitted) {
    retained._portable_evidence_omitted = omitted;
  }
  return retained;
}

function portableFindingRef(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return boundedPortableCell(value);
  }
  const keys = [
    'id',
    'finding_type',
    'type',
    'scenario_id',
    'classification',
    'owning_surface',
    'summary',
    'observed_behavior',
    'next_acceptance_criterion',
  ];
  return Object.fromEntries(keys.filter((key) => key in value).map((key) => [key, boundedPortableCell(value[key])]));
}

function portableFinding(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return boundedPortableCell(value);
  }
  const keys = [
    'id',
    'finding_type',
    'type',
    'scenario_id',
    'classification',
    'root_cause_classification',
    'owning_surface',
    'summary',
    'artifact_versions',
    'expected_behavior',
    'observed_behavior',
    'next_acceptance_criterion',
  ];
  return Object.fromEntries(keys.filter((key) => key in value).map((key) => [key, boundedPortableCell(value[key])]));
}

function portableObservedOutputs(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return { portable_diagnostic: boundedPortableCell(value) };
  }
  const keys = Object.keys(value).sort((left, right) => {
    const priority = Number(portablePriorityKey(right)) - Number(portablePriorityKey(left));
    return priority || left.localeCompare(right);
  });
  const retained = {};
  let omittedKeys = 0;
  for (const key of keys) {
    if (portableSensitiveKey(key)) {
      omittedKeys += 1;
      continue;
    }
    if (key === 'published_artifact_worker_execution') {
      continue;
    }
    if (Object.keys(retained).length >= PORTABLE_EVIDENCE_COLLECTION_LIMIT) {
      omittedKeys += 1;
      continue;
    }
    retained[key] = key === 'activity_host_evidence'
      ? portableActivityHostEvidence(value[key])
      : boundedPortableCell(value[key]);
  }
  if (omittedKeys > 0) {
    retained._portable_evidence_omitted = {
      ...portableValueSummary(value, 'collection_or_sensitive_key_limit'),
      omitted_keys: omittedKeys,
    };
  }
  return Object.keys(retained).length > 0
    ? retained
    : { portable_diagnostic: portableValueSummary(value, 'empty_portable_projection') };
}

function portableScenarioResult(value) {
  const retained = {
    scenario_id: value.scenario_id,
    status: value.status,
  };
  for (const key of ['expected_behavior', 'classification']) {
    if (key in value) {
      retained[key] = boundedPortableCell(value[key]);
    }
  }
  retained.observed_outputs = portableObservedOutputs(value.observed_outputs);
  if (Array.isArray(value.linked_findings) && value.linked_findings.length > 0) {
    retained.linked_findings = value.linked_findings
      .slice(0, PORTABLE_FINDING_LIMIT)
      .map(portableFindingRef);
  }
  return retained;
}

function minimalPortableScenarioResult(value) {
  const retained = {
    scenario_id: value.scenario_id,
    status: value.status,
    observed_outputs: {
      portable_diagnostic: portableValueSummary(value.observed_outputs, 'projection_target'),
    },
  };
  if (value.classification !== undefined) {
    retained.classification = value.classification;
  }
  const hostEvidence = activityHostEvidenceFor(value, value.observed_outputs || {});
  if (hostEvidence && typeof hostEvidence === 'object') {
    retained.observed_outputs.activity_host_evidence = portableActivityHostEvidence(hostEvidence);
  }
  if (Array.isArray(value.linked_findings) && value.linked_findings.length > 0) {
    retained.linked_findings = value.linked_findings
      .slice(0, PORTABLE_FINDING_LIMIT)
      .map(portableFindingRef);
  }
  return retained;
}

function portableEvidenceContract() {
  return {
    schema: 'durable-workflow.v1.portable-native-evidence',
    max_result_bytes: PORTABLE_RESULT_LIMIT_BYTES,
    projection_target_bytes: PORTABLE_RESULT_TARGET_BYTES,
    max_evidence_cell_bytes: PORTABLE_EVIDENCE_CELL_LIMIT_BYTES,
    max_string_bytes: PORTABLE_EVIDENCE_STRING_LIMIT_BYTES,
    scenario_evidence: 'complete_required_statuses_and_bounded_diagnostics',
    exact_distribution_identity_field: 'executed_distribution_identities',
    product_assertions: 'fail_closed_before_projection',
    sensitive_values: 'omitted',
    unbounded_values: 'sha256_summary_without_payload_bytes',
  };
}

function readJsonFile(file) {
  return JSON.parse(fs.readFileSync(file, 'utf8'));
}

function compareCodePointStrings(left, right) {
  const leftCodePoints = Array.from(left, (character) => character.codePointAt(0));
  const rightCodePoints = Array.from(right, (character) => character.codePointAt(0));
  const sharedLength = Math.min(leftCodePoints.length, rightCodePoints.length);
  for (let index = 0; index < sharedLength; index += 1) {
    if (leftCodePoints[index] !== rightCodePoints[index]) {
      return leftCodePoints[index] - rightCodePoints[index];
    }
  }
  return leftCodePoints.length - rightCodePoints.length;
}

function normalizeDistributionIdentity(component, value, artifactVersions) {
  const definition = DISTRIBUTION_COMPONENTS[component];
  if (!definition) {
    throw new Error(`unknown executed distribution component: ${component}`);
  }
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    throw new Error(`executed distribution identity for ${component} must be an object`);
  }
  const keys = Object.keys(value).sort();
  if (JSON.stringify(keys) !== JSON.stringify(['artifacts', 'kind', 'locator'])) {
    throw new Error(`executed distribution identity for ${component} has an invalid shape`);
  }

  const version = stringValue(artifactVersions[definition.versionKey]);
  const exactVersion = component === 'sdk-python'
    ? pythonReleaseIdentity(version) !== null
    : DISTRIBUTION_VERSION_PATTERN.test(version);
  if (!exactVersion) {
    throw new Error(`exact distribution version is unavailable for ${component}`);
  }
  const locatorVersion = component === 'sdk-python' ? pythonReleaseIdentity(version) : version;
  const expectedLocator = `${definition.kind}:${definition.package}@${locatorVersion}`;
  if (value.kind !== definition.kind || value.locator !== expectedLocator) {
    throw new Error(`executed distribution locator for ${component} does not match ${expectedLocator}`);
  }
  if (!Array.isArray(value.artifacts) || value.artifacts.length === 0) {
    throw new Error(`executed distribution identity for ${component} has no artifacts`);
  }

  const artifacts = value.artifacts.map((artifact) => {
    if (!artifact || typeof artifact !== 'object' || Array.isArray(artifact)) {
      throw new Error(`executed distribution artifact for ${component} must be an object`);
    }
    if (JSON.stringify(Object.keys(artifact).sort()) !== JSON.stringify(['name', 'sha256'])) {
      throw new Error(`executed distribution artifact for ${component} has an invalid shape`);
    }
    const name = artifact.name;
    const digest = artifact.sha256;
    if (typeof name !== 'string'
      || name.trim() !== name
      || !name
      || name.length > 256
      || (!['workflow', 'waterline', 'sdk-php'].includes(component) && name.includes('/'))) {
      throw new Error(`executed distribution artifact name for ${component} is invalid`);
    }
    if (typeof digest !== 'string' || !DISTRIBUTION_DIGEST_PATTERN.test(digest)) {
      throw new Error(`executed distribution SHA-256 for ${component}:${name} is invalid`);
    }
    return { name, sha256: digest };
  }).sort((left, right) => compareCodePointStrings(left.name, right.name));

  if (new Set(artifacts.map((artifact) => artifact.name)).size !== artifacts.length) {
    throw new Error(`executed distribution artifacts for ${component} contain duplicate names`);
  }
  return { kind: definition.kind, locator: expectedLocator, artifacts };
}

function mergeDistributionIdentityMaps(target, supplied, artifactVersions) {
  if (!supplied || typeof supplied !== 'object' || Array.isArray(supplied)) {
    throw new Error('executed distribution identities must be a component map');
  }

  for (const [component, rawIdentity] of Object.entries(supplied)) {
    const observed = normalizeDistributionIdentity(component, rawIdentity, artifactVersions);
    const current = target[component];
    if (!current) {
      target[component] = observed;
      continue;
    }

    const normalizedCurrent = normalizeDistributionIdentity(component, current, artifactVersions);
    if (normalizedCurrent.kind !== observed.kind || normalizedCurrent.locator !== observed.locator) {
      throw new Error(`conflicting executed distribution locator for ${component}`);
    }
    const artifacts = new Map(normalizedCurrent.artifacts.map((artifact) => [artifact.name, artifact.sha256]));
    for (const artifact of observed.artifacts) {
      const previous = artifacts.get(artifact.name);
      if (previous && previous !== artifact.sha256) {
        throw new Error(`conflicting consumed bytes for ${component}:${artifact.name}`);
      }
      artifacts.set(artifact.name, artifact.sha256);
    }
    target[component] = {
      kind: observed.kind,
      locator: observed.locator,
      artifacts: [...artifacts.entries()]
        .sort(([left], [right]) => compareCodePointStrings(left, right))
        .map(([name, sha256]) => ({ name, sha256 })),
    };
  }
}

function resolveExecutedDistributionIdentities(activityEvidence, artifactVersions) {
  const identityPath = path.join(RESULT_DIR, 'executed-distribution-identities.json');
  const identities = {};
  const failures = [];

  if (fs.existsSync(identityPath)) {
    try {
      mergeDistributionIdentityMaps(identities, readJsonFile(identityPath), artifactVersions);
    } catch (error) {
      failures.push(`recorded executed distribution identities are invalid: ${String(error.message || error)}`);
    }
  }

  const handoff = activityEvidence.executed_distribution_identities
    ?? activityEvidence.executedDistributionIdentities;
  if (handoff !== undefined) {
    try {
      mergeDistributionIdentityMaps(identities, handoff, artifactVersions);
    } catch (error) {
      failures.push(`activity evidence distribution identity handoff is invalid: ${String(error.message || error)}`);
    }
  }

  const missing = REQUIRED_DISTRIBUTION_IDENTITIES.filter((component) => !identities[component]);
  if (missing.length > 0) {
    failures.push(`missing executed distribution evidence for: ${missing.join(', ')}`);
  }

  fs.mkdirSync(RESULT_DIR, { recursive: true });
  writeJson(identityPath, identities);
  return { identities, failures };
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

function firstActionableHostProbeException(activityEvidence) {
  const scenarios = Array.isArray(activityEvidence.scenario_results)
    ? activityEvidence.scenario_results
    : [];

  for (const scenario of scenarios) {
    if (!scenario || typeof scenario !== 'object' || Array.isArray(scenario)) {
      continue;
    }

    const observedOutputs = nonEmptyObject(scenario.observed_outputs)
      ? scenario.observed_outputs
      : {};
    const scenarioEvidence = nonEmptyObject(scenario.scenario_evidence)
      ? scenario.scenario_evidence
      : {};
    for (const candidate of [scenario.failure, observedOutputs.failure, scenarioEvidence.failure]) {
      const failure = stringValue(candidate);
      if (failure) {
        return failure;
      }
    }

    const hostEvidence = activityHostEvidenceFor(scenario, observedOutputs);
    for (const cells of [
      observedOutputs.activity_cells,
      scenarioEvidence.activity_cells,
      hostEvidence && hostEvidence.activity_cells,
    ]) {
      if (!Array.isArray(cells)) {
        continue;
      }
      for (const cell of cells) {
        const failure = stringValue(cell && typeof cell === 'object' ? cell.failure : '');
        if (failure) {
          return failure;
        }
      }
    }
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
    'sdk-php': 'DW_PHP_SDK_VERSION',
    workflow: 'DW_WORKFLOW_PHP_VERSION',
    waterline: 'DW_WATERLINE_VERSION',
  };

  for (const [key, label] of Object.entries(required)) {
    const version = versions[key] || '';
    if (!version) {
      failures.push(`missing ${label}`);
      continue;
    }
    const exactVersion = key === 'sdk-python' ? PYTHON_RELEASE_RE.test(version) : SEMVER_RE.test(version);
    if (isPlaceholder(version) || !exactVersion) {
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
    'sdk-php': ['sdk-php', 'sdk_php', 'php'],
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
    case 'sdk-php':
      return matchesComposerArtifactSource('durable-workflow/sdk', version, source);
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
    || /^repos\/(?:server|workflow|waterline|cli|cloud|sample-app|sdk-php|sdk-python|durable-workflow\.github\.io)(?:\/|$)/.test(normalized);
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

function pythonReleaseIdentity(version) {
  const stable = /^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/.exec(version);
  if (stable) return version;
  const semver = /^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)-(alpha|beta|rc)\.(0|[1-9]\d*)$/i.exec(version);
  if (semver) {
    const phase = semver[4].toLowerCase() === 'alpha' ? 'a' : (semver[4].toLowerCase() === 'beta' ? 'b' : 'rc');
    return `${semver[1]}.${semver[2]}.${semver[3]}${phase}${semver[5]}`;
  }
  const pep440 = /^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(a|b|rc)(0|[1-9]\d*)$/i.exec(version);
  return pep440
    ? `${pep440[1]}.${pep440[2]}.${pep440[3]}${pep440[4].toLowerCase()}${pep440[5]}`
    : null;
}

function samePythonRelease(expected, observed) {
  const expectedIdentity = pythonReleaseIdentity(expected);
  return expectedIdentity !== null && expectedIdentity === pythonReleaseIdentity(observed);
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
    const component = entry.artifact === 'workflow-php' ? 'workflow' : entry.artifact;
    sources[component] = entry.source || 'not_exercised';
  }
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
    runtimes: ['workflow-php', 'sdk-php', 'sdk-python'],
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
      'Set exact DW_SERVER_VERSION, DW_CLI_VERSION, DW_PHP_SDK_VERSION, DW_PYTHON_SDK_VERSION, DW_WORKFLOW_PHP_VERSION, and DW_WATERLINE_VERSION values.',
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
    heartbeat_timeout_renewal: section({
      required_behavior: 'the published PHP SDK worker renews a short heartbeat deadline across repeated timeout enforcement and retains a typed no-heartbeat negative control',
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
    return ['sdk_python_activity_worker_artifact_missing: sdk-python worker_artifact evidence missing'];
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
  if (!samePythonRelease(packageVersion, version)) {
    failures.push(`sdk-python worker_artifact.version=${version || 'missing'} does not match ${packageVersion || 'missing'}`);
  }
  if (!source || installSourceIsForbidden(source)
    || (!matchesPythonArtifactSource(version, source) && !matchesPythonArtifactSource(packageVersion, source))) {
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

function sdkPhpWorkerArtifactFailures(artifact, artifactVersions) {
  if (!nonEmptyObject(artifact)) {
    return ['sdk_php_activity_worker_artifact_missing'];
  }
  const failures = [];
  const expectedVersion = artifactVersionFor(artifactVersions, 'sdk-php');
  const artifactName = stringValue(artifact.artifact || artifact.name);
  const packageName = stringValue(artifact.package || artifact.package_name || artifact.packageName);
  const version = stringValue(artifact.version || artifact.package_version || artifact.packageVersion);
  const source = entrySource(artifact);
  const status = normalizedStatus(artifact.status || artifact.outcome);
  const runtime = [artifact.runtime, artifact.language].map(stringValue).join(' ').toLowerCase();
  const executionSource = stringValue(artifact.execution_source || artifact.executionSource);

  if (artifactName !== 'sdk-php' || packageName !== 'durable-workflow/sdk') {
    failures.push('sdk_php_activity_worker_artifact_invalid_package');
  }
  if (version !== expectedVersion || !SEMVER_RE.test(version)) {
    failures.push(`sdk_php_activity_worker_artifact_invalid_version:${version || 'missing'}`);
  }
  if (!matchesComposerArtifactSource('durable-workflow/sdk', version, source)
    || installSourceIsForbidden(source)) {
    failures.push(`sdk_php_activity_worker_artifact_unrecognized_source:${source || 'missing'}`);
  }
  if (status !== 'pass'
    || !runtime.includes('php')
    || executionSource !== PUBLISHED_SERVER_CONTAINER_EXECUTION_SOURCE
    || !explicitFalse(artifact.local_product_source_checkouts_used)
    || localSourceSignals(artifact).length > 0) {
    failures.push('sdk_php_activity_worker_artifact_execution_invalid');
  }

  return failures;
}

function heartbeatTimeoutRenewalFailures(scenarioId, observedOutputs, artifactVersions) {
  if (scenarioId !== 'heartbeat_timeout_renewal_across_enforcement_passes') {
    return [];
  }

  const failures = [];
  failures.push(...sdkPhpWorkerArtifactFailures(
    observedOutputs.php_sdk_worker_artifact || observedOutputs.phpSdkWorkerArtifact,
    artifactVersions,
  ));
  const timeoutSeconds = observedOutputs.heartbeat_timeout_seconds;
  const cadenceSeconds = observedOutputs.heartbeat_cadence_seconds;
  const durationSeconds = observedOutputs.in_flight_duration_seconds;
  const initialDeadline = Date.parse(stringValue(observedOutputs.initial_heartbeat_deadline_at));
  if (!Number.isInteger(timeoutSeconds) || timeoutSeconds <= 0 || timeoutSeconds > 10) {
    failures.push('heartbeat_timeout_renewal_invalid_short_timeout');
  }
  if (typeof cadenceSeconds !== 'number'
    || cadenceSeconds <= 0
    || !Number.isInteger(timeoutSeconds)
    || cadenceSeconds > timeoutSeconds / 2) {
    failures.push('heartbeat_timeout_renewal_cadence_not_materially_faster');
  }
  if (typeof durationSeconds !== 'number'
    || !Number.isInteger(timeoutSeconds)
    || durationSeconds <= timeoutSeconds) {
    failures.push('heartbeat_timeout_renewal_activity_not_in_flight_beyond_timeout');
  }
  if (!Number.isFinite(initialDeadline)) {
    failures.push('heartbeat_timeout_renewal_initial_deadline_missing');
  }

  const acknowledgements = Array.isArray(observedOutputs.heartbeat_acknowledgements)
    ? observedOutputs.heartbeat_acknowledgements
    : [];
  if (acknowledgements.length < 4) {
    failures.push('heartbeat_timeout_renewal_insufficient_acknowledgements');
  }
  acknowledgements.forEach((acknowledgement, index) => {
    const response = nonEmptyObject(acknowledgement?.response) ? acknowledgement.response : {};
    const requestStartedAt = Date.parse(stringValue(acknowledgement?.request_started_at));
    const responseReceivedAt = Date.parse(stringValue(acknowledgement?.response_received_at));
    const lastHeartbeatAt = Date.parse(stringValue(acknowledgement?.last_heartbeat_at));
    const previousDeadline = Date.parse(stringValue(acknowledgement?.previous_deadline_at));
    const authoritativeDeadline = Date.parse(stringValue(acknowledgement?.authoritative_deadline_at));
    if (response.heartbeat_recorded !== true
      || response.can_continue !== true
      || acknowledgement?.deadline_advanced !== true
      || !Number.isFinite(requestStartedAt)
      || !Number.isFinite(responseReceivedAt)
      || !Number.isFinite(lastHeartbeatAt)
      || !Number.isFinite(previousDeadline)
      || !Number.isFinite(authoritativeDeadline)
      || responseReceivedAt < requestStartedAt
      || authoritativeDeadline <= previousDeadline
      || authoritativeDeadline <= lastHeartbeatAt) {
      failures.push(`heartbeat_timeout_renewal_acknowledgement_${index + 1}_did_not_advance_deadline`);
    }
    if (index === 0 && Number.isFinite(initialDeadline) && previousDeadline !== initialDeadline) {
      failures.push('heartbeat_timeout_renewal_initial_deadline_not_authoritative');
    }
    if (index > 0) {
      const previousAcknowledgement = acknowledgements[index - 1];
      const previousHeartbeatAt = Date.parse(stringValue(previousAcknowledgement?.last_heartbeat_at));
      const previousAuthoritativeDeadline = Date.parse(
        stringValue(previousAcknowledgement?.authoritative_deadline_at),
      );
      if (!Number.isFinite(previousHeartbeatAt)
        || !Number.isFinite(lastHeartbeatAt)
        || !Number.isInteger(timeoutSeconds)
        || lastHeartbeatAt <= previousHeartbeatAt
        || (lastHeartbeatAt - previousHeartbeatAt) / 1000 > timeoutSeconds / 2
        || previousDeadline !== previousAuthoritativeDeadline) {
        failures.push(`heartbeat_timeout_renewal_acknowledgement_${index + 1}_invalid_observed_cadence`);
      }
    }
  });

  const enforcementPasses = Array.isArray(observedOutputs.enforcement_passes)
    ? observedOutputs.enforcement_passes
    : [];
  if (enforcementPasses.length < 3) {
    failures.push('heartbeat_timeout_renewal_insufficient_enforcement_passes');
  }
  enforcementPasses.forEach((pass, index) => {
    const response = nonEmptyObject(pass?.response) ? pass.response : {};
    const observedAt = Date.parse(stringValue(pass?.observed_at));
    const finishedAt = Date.parse(stringValue(pass?.finished_at));
    const authoritativeDeadline = Date.parse(stringValue(pass?.authoritative_deadline_at));
    const result = Array.isArray(response.results) && nonEmptyObject(response.results[0])
      ? response.results[0]
      : {};
    if (response.processed !== 1
      || response.enforced !== 0
      || response.skipped !== 1
      || response.failed !== 0
      || result.outcome !== 'skipped'
      || result.reason !== 'no_deadline_expired'
      || pass?.activity_timed_out_history_count !== 0
      || !Number.isFinite(observedAt)
      || !Number.isFinite(finishedAt)
      || !Number.isFinite(authoritativeDeadline)
      || finishedAt < observedAt
      || finishedAt >= authoritativeDeadline) {
      failures.push(`heartbeat_timeout_renewal_enforcement_pass_${index + 1}_contradicted_heartbeat`);
    }
  });

  const completion = nonEmptyObject(observedOutputs.completion_response)
    ? observedOutputs.completion_response
    : {};
  const terminal = nonEmptyObject(observedOutputs.terminal_history)
    ? observedOutputs.terminal_history
    : {};
  if (completion.recorded !== true
    || terminal.activity_completed_count !== 1
    || terminal.activity_timed_out_count !== 0
    || terminal.activity_heartbeat_recorded_count !== acknowledgements.length
    || terminal.completed_exactly_once !== true
    || terminal.history_without_contradiction !== true) {
    failures.push('heartbeat_timeout_renewal_terminal_history_is_contradictory');
  }

  const negative = nonEmptyObject(observedOutputs.negative_control)
    ? observedOutputs.negative_control
    : {};
  const negativeEnforcement = nonEmptyObject(negative.enforcement_pass) ? negative.enforcement_pass : {};
  const timeoutPayload = nonEmptyObject(negative.typed_timeout_payload) ? negative.typed_timeout_payload : {};
  const lateHeartbeat = nonEmptyObject(negative.late_heartbeat_response) ? negative.late_heartbeat_response : {};
  const lateCompletion = nonEmptyObject(negative.late_completion_conflict) ? negative.late_completion_conflict : {};
  const lateFailure = nonEmptyObject(negative.late_failure_conflict) ? negative.late_failure_conflict : {};
  const negativeHistory = nonEmptyObject(negative.terminal_history) ? negative.terminal_history : {};
  const negativeDeadline = Date.parse(stringValue(negative.initial_heartbeat_deadline_at));
  const negativeEnforcedAt = Date.parse(stringValue(negative.enforcement_observed_at));
  if (negativeEnforcement.enforced !== 1
    || timeoutPayload.timeout_kind !== 'heartbeat'
    || timeoutPayload.failure_category !== 'timeout'
    || lateHeartbeat.heartbeat_recorded !== false
    || lateHeartbeat.can_continue !== false
    || lateHeartbeat.reason !== 'attempt_closed'
    || lateCompletion.http_status !== 409
    || lateCompletion.reason !== 'stale_attempt'
    || lateCompletion.recorded !== false
    || lateFailure.http_status !== 409
    || lateFailure.reason !== 'stale_attempt'
    || lateFailure.recorded !== false
    || negativeHistory.activity_timed_out_count !== 1
    || negativeHistory.activity_completed_count !== 0
    || negativeHistory.activity_failed_count !== 0
    || !Number.isFinite(negativeDeadline)
    || !Number.isFinite(negativeEnforcedAt)
    || negativeEnforcedAt <= negativeDeadline) {
    failures.push('heartbeat_timeout_renewal_negative_control_invalid');
  }

  const cleanup = nonEmptyObject(observedOutputs.isolated_cleanup)
    ? observedOutputs.isolated_cleanup
    : {};
  if (cleanup.isolated_database !== true
    || cleanup.scratch_removed_on_exit !== true
    || cleanup.published_server_container_removed !== true) {
    failures.push('heartbeat_timeout_renewal_isolated_cleanup_missing');
  }

  return failures;
}

function activityIsolationFailures(scenarioId, observedOutputs) {
  const failures = [];
  if (scenarioId === 'heartbeat_timeout_renewal_across_enforcement_passes') {
    const managedDeregistration = nonEmptyObject(observedOutputs.managed_worker_deregistration)
      ? observedOutputs.managed_worker_deregistration
      : {};
    const negative = nonEmptyObject(observedOutputs.negative_control)
      ? observedOutputs.negative_control
      : {};
    if (managedDeregistration?.response?.outcome !== 'deregistered'
      || negative?.worker_deregistration?.outcome !== 'deregistered'
      || !stringValue(observedOutputs.worker_id)
      || !stringValue(negative.worker_id)
      || observedOutputs.worker_id === negative.worker_id
      || !stringValue(observedOutputs.task_queue)
      || !stringValue(negative.task_queue)
      || observedOutputs.task_queue === negative.task_queue) {
      failures.push('heartbeat_timeout_renewal_fresh_negative_worker_missing');
    }
  }

  if (scenarioId === 'idempotent_completion_handling') {
    const first = nonEmptyObject(observedOutputs.first_completion_response)
      ? observedOutputs.first_completion_response
      : {};
    const duplicate = nonEmptyObject(observedOutputs.duplicate_completion_response)
      ? observedOutputs.duplicate_completion_response
      : {};
    const completedEvents = Array.isArray(observedOutputs.activity_completed_history_events)
      ? observedOutputs.activity_completed_history_events
      : [];
    const completed = nonEmptyObject(completedEvents[0]) ? completedEvents[0] : {};
    const executionId = stringValue(observedOutputs.activity_execution_id);
    if (observedOutputs.same_task_and_attempt_ids !== true
      || observedOutputs.recorded_once !== true
      || observedOutputs.activity_completed_history_count !== 1
      || completedEvents.length !== 1
      || first.recorded !== true
      || duplicate.recorded !== false
      || duplicate.reason !== 'stale_attempt'
      || !stringValue(first.task_id)
      || first.task_id !== duplicate.task_id
      || !stringValue(first.activity_attempt_id)
      || first.activity_attempt_id !== duplicate.activity_attempt_id
      || !executionId
      || completed.activity_execution_id !== executionId
      || completed.activity_attempt_id !== first.activity_attempt_id) {
      failures.push('idempotent_completion_task_attempt_identity_invalid');
    }
  }

  if (scenarioId === 'php_python_activity_parity') {
    const handles = nonEmptyObject(observedOutputs.handle_responses)
      ? observedOutputs.handle_responses
      : {};
    for (const [key, runtime] of [['workflow-php', 'workflow-php'], ['sdk-python', 'sdk-python']]) {
      const prefix = runtime === 'workflow-php' ? 'php' : 'python';
      const result = nonEmptyObject(
        runtime === 'workflow-php'
          ? observedOutputs.php_activity_result
          : observedOutputs.python_activity_result,
      )
        ? (runtime === 'workflow-php'
          ? observedOutputs.php_activity_result
          : observedOutputs.python_activity_result)
        : {};
      const handle = nonEmptyObject(handles[key]) ? handles[key] : {};
      const activityId = stringValue(observedOutputs[`${prefix}_activity_id`]);
      const runId = stringValue(observedOutputs[`${prefix}_workflow_run_id`]);
      const executionId = stringValue(observedOutputs[`${prefix}_activity_execution_id`]);
      if (!activityId
        || !runId
        || !executionId
        || result.runtime !== runtime
        || !stringValue(result.input_marker).startsWith('parity-result-')
        || handle.activity_id !== activityId
        || handle.workflow_run_id !== runId
        || handle.activity_execution_id !== executionId) {
        failures.push(`php_python_parity_${prefix}_completion_identity_invalid`);
      }
    }
  }

  if (scenarioId === 'operator_visible_activity_attempt_state') {
    const fixture = nonEmptyObject(observedOutputs.stale_shared_queue_regression_fixture)
      ? observedOutputs.stale_shared_queue_regression_fixture
      : {};
    const retryAvailableAt = Date.parse(stringValue(fixture.retry_available_at));
    const backoffCrossedAt = Date.parse(stringValue(fixture.backoff_crossed_at));
    const deadline = Date.parse(stringValue(fixture.timed_out_worker_visible_start_to_close_deadline));
    if (fixture.configured_backoff_seconds !== 60
      || fixture.backoff_crossed_before_timed_out_poll !== true
      || fixture.isolated_task_queues !== true
      || !stringValue(fixture.retry_task_queue)
      || !stringValue(fixture.timed_out_task_queue)
      || fixture.retry_task_queue === fixture.timed_out_task_queue
      || !stringValue(fixture.retry_activity_execution_id)
      || !stringValue(fixture.timed_out_activity_execution_id)
      || fixture.retry_activity_execution_id === fixture.timed_out_activity_execution_id
      || !Number.isFinite(retryAvailableAt)
      || !Number.isFinite(backoffCrossedAt)
      || backoffCrossedAt < retryAvailableAt
      || !Number.isFinite(deadline)) {
      failures.push('operator_visibility_stale_queue_regression_fixture_invalid');
    }
  }

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
    'sdk-php': env('DW_PHP_SDK_VERSION'),
    'sdk-python': env('DW_PYTHON_SDK_VERSION'),
    workflow: workflowVersion,
    waterline: env('DW_WATERLINE_VERSION'),
  };
  const publishedArtifactVersions = {
    server: artifactVersions.server,
    cli: artifactVersions.cli,
    'sdk-php': artifactVersions['sdk-php'],
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
  const distributionIdentityEvidence = resolveExecutedDistributionIdentities(
    activityEvidence,
    artifactVersions,
  );
  const executedDistributionIdentities = distributionIdentityEvidence.identities;
  const distributionIdentityFailures = distributionIdentityEvidence.failures;
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

  const prerequisiteFailure = env('DW_ACTIVITIES_PREREQUISITE_FAILURE');
  const hostProbeException = firstActionableHostProbeException(activityEvidence);
  const runnerBlocked = pinFailures.length > 0 || prerequisiteFailure !== '';
  const blockedReason = [
    ...pinFailures,
    ...(hostProbeException ? [`focused activity host probe exception: ${hostProbeException}`] : []),
    ...(prerequisiteFailure ? [prerequisiteFailure] : []),
  ].join('; ');
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
            sdk_php_package: `durable-workflow/sdk:${artifactVersions['sdk-php']}`,
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
      const scenarioContractFailures = [
        ...heartbeatTimeoutRenewalFailures(scenarioId, observedOutputs, artifactVersions),
        ...activityIsolationFailures(scenarioId, observedOutputs),
      ];
      if (status === 'pass' && scenarioContractFailures.length > 0) {
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

      const focusedHostEvidenceReason = [
        ...focusedHostEvidenceFailures,
        ...scenarioContractFailures,
      ].join('; ');
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
            ...(scenarioContractFailures.length > 0
              ? { scenario_contract_failures: scenarioContractFailures }
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
            ...(scenarioContractFailures.length > 0
              ? { scenario_contract_failures: scenarioContractFailures }
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

  if (distributionIdentityFailures.length > 0) {
    findings.push({
      id: 'executed_distribution_identity_missing_or_conflicting',
      type: 'executed_distribution_identity_missing_or_conflicting',
      scenario_id: 'executed_distribution_identities',
      owning_surface: 'conformance_harness',
      summary: 'Activity execution did not retain a complete, conflict-free consumed distribution identity set.',
      observed_behavior: {
        failures: distributionIdentityFailures,
        observed_components: Object.keys(executedDistributionIdentities).sort(),
      },
      expected_behavior: 'passing activity evidence identifies every package, release asset, and OCI manifest consumed by the runner',
      next_acceptance_criterion: 'retain faithful consumed-byte identities for the complete activity distribution set and rerun conformance',
    });
  }

  const nonPassScenarios = scenarioResults.filter((result) => result.status !== 'pass');
  const allRequiredReported = REQUIRED_SCENARIOS.every((id) => scenarioResults.some((result) => result.scenario_id === id));
  const outcome = !runnerBlocked
    && allRequiredReported
    && nonPassScenarios.length === 0
    && installEvidencePass
    && activityEvidenceLoad.supplied
    && distributionIdentityFailures.length === 0
    ? 'pass'
    : (runnerBlocked ? 'non_passing_runner_blocked' : 'non_passing');
  const finishedAt = now();
  const sectionStatus = runnerBlocked ? 'runner_blocked' : 'not_covered';
  const sections = evidenceStatusSections(sectionStatus, defaultReason);
  const runtimeMatrix = {
    execution_modes: Array.isArray(matrix.execution_modes) ? matrix.execution_modes : ['workflow-embedded', 'standalone'],
    runtimes: Array.isArray(matrix.runtimes) ? matrix.runtimes : ['workflow-php', 'sdk-php', 'sdk-python'],
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
    sdk_php_package: artifactVersions['sdk-php']
      ? `durable-workflow/sdk:${artifactVersions['sdk-php']}`
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

  const nativeResult = {
    schema: 'durable-workflow.v2.activity-runtime.result',
    schema_version: 1,
    suite_schema: 'durable-workflow.v2.platform-conformance.suite',
    suite_version: suiteVersion,
    category: 'activity_runtime_contract',
    outcome,
    runner_blocked: runnerBlocked,
    ...(runnerBlocked ? {
      runner_blocked_evidence: {
        first_actionable_host_probe_exception: hostProbeException || null,
        prerequisite_failure: prerequisiteFailure || null,
        pin_failures: pinFailures,
      },
    } : {}),
    started_at: STARTED_AT,
    finished_at: finishedAt,
    generated_at: finishedAt,
    artifact_versions: artifactVersions,
    executed_distribution_identities: executedDistributionIdentities,
    executed_distribution_identity_failures: distributionIdentityFailures,
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
      task_queue_strategy: 'per_scenario_isolated',
      task_queue_identity_source: 'scenario_execution_identity',
      worker_identity_strategy: 'per_scenario_or_restart_pair',
      required_workers: ['workflow-php', 'sdk-php', 'sdk-python'],
      execution_modes: ['workflow-embedded', 'standalone'],
    },
    durable_result_recording: sectionFromEvidence(activityEvidence, 'durable_result_recording', sections.durable_result_recording),
    retry_backoff: sectionFromEvidence(activityEvidence, 'retry_backoff', sections.retry_backoff),
    timeout_behavior: sectionFromEvidence(activityEvidence, 'timeout_behavior', sections.timeout_behavior),
    heartbeat_timeout_renewal: sectionFromEvidence(
      activityEvidence,
      'heartbeat_timeout_renewal',
      sections.heartbeat_timeout_renewal,
    ),
    typed_failure_propagation: sectionFromEvidence(activityEvidence, 'typed_failure_propagation', sections.typed_failure_propagation),
    heartbeat_cancellation: sectionFromEvidence(activityEvidence, 'heartbeat_cancellation', sections.heartbeat_cancellation),
    idempotent_completion: sectionFromEvidence(activityEvidence, 'idempotent_completion', sections.idempotent_completion),
    operator_visibility: sectionFromEvidence(activityEvidence, 'operator_visibility', sections.operator_visibility),
    scenario_results: scenarioResults,
    findings,
    finding_links: Object.fromEntries(findings.map((item) => [item.scenario_id, [item]])),
  };

  const portableContract = portableEvidenceContract();
  const portableRuntimeMatrix = {
    execution_modes: Array.isArray(nativeResult.runtime_matrix?.execution_modes)
      ? nativeResult.runtime_matrix.execution_modes
      : [],
    runtimes: Array.isArray(nativeResult.runtime_matrix?.runtimes)
      ? nativeResult.runtime_matrix.runtimes
      : [],
    activity_cells: boundedPortableCell(nativeResult.runtime_matrix?.activity_cells || []),
    behavior_cells: boundedPortableCell(nativeResult.runtime_matrix?.behavior_cells || []),
  };
  let result = {
    schema: nativeResult.schema,
    schema_version: nativeResult.schema_version,
    suite_schema: nativeResult.suite_schema,
    suite_version: nativeResult.suite_version,
    category: nativeResult.category,
    outcome: nativeResult.outcome,
    runner_blocked: nativeResult.runner_blocked,
    runner_blocked_evidence: nativeResult.runner_blocked_evidence
      ? boundedPortableCell(nativeResult.runner_blocked_evidence)
      : null,
    started_at: nativeResult.started_at,
    finished_at: nativeResult.finished_at,
    generated_at: nativeResult.generated_at,
    artifact_versions: nativeResult.artifact_versions,
    executed_distribution_identities: nativeResult.executed_distribution_identities,
    executed_distribution_identity_failures: boundedPortableCell(nativeResult.executed_distribution_identity_failures),
    published_artifact_versions: nativeResult.published_artifact_versions,
    artifact_sources: nativeResult.artifact_sources,
    execution_source: nativeResult.execution_source,
    local_product_source_checkouts_used: nativeResult.local_product_source_checkouts_used,
    activity_evidence_source: nativeResult.activity_evidence_source,
    activity_evidence_supplied: nativeResult.activity_evidence_supplied,
    published_artifact_worker_execution: nativeResult.published_artifact_worker_execution
      ? portablePublishedExecution(nativeResult.published_artifact_worker_execution)
      : null,
    published_artifact_worker_execution_source: nativeResult.published_artifact_worker_execution_source,
    published_artifact_worker_execution_derived: nativeResult.published_artifact_worker_execution_derived,
    published_artifact_worker_execution_derivation_reason: boundedPortableCell(
      nativeResult.published_artifact_worker_execution_derivation_reason,
    ),
    published_artifact_worker_execution_failures: boundedPortableCell(
      nativeResult.published_artifact_worker_execution_failures,
    ),
    published_artifact_install: boundedPortableCell(nativeResult.published_artifact_install),
    runtime_matrix: portableRuntimeMatrix,
    topology: nativeResult.topology,
    durable_result_recording: boundedPortableCell(nativeResult.durable_result_recording),
    retry_backoff: boundedPortableCell(nativeResult.retry_backoff),
    timeout_behavior: boundedPortableCell(nativeResult.timeout_behavior),
    heartbeat_timeout_renewal: boundedPortableCell(nativeResult.heartbeat_timeout_renewal),
    typed_failure_propagation: boundedPortableCell(nativeResult.typed_failure_propagation),
    heartbeat_cancellation: boundedPortableCell(nativeResult.heartbeat_cancellation),
    idempotent_completion: boundedPortableCell(nativeResult.idempotent_completion),
    operator_visibility: boundedPortableCell(nativeResult.operator_visibility),
    scenario_results: nativeResult.scenario_results.map(portableScenarioResult),
    findings: nativeResult.findings.slice(0, PORTABLE_FINDING_LIMIT).map(portableFinding),
    finding_links: Object.fromEntries(Object.entries(nativeResult.finding_links).map(
      ([scenarioId, linked]) => [
        scenarioId,
        Array.isArray(linked) ? linked.slice(0, PORTABLE_FINDING_LIMIT).map(portableFindingRef) : [],
      ],
    )),
    portable_evidence_contract: portableContract,
  };

  const projectedResultBytes = encodedResultBytes(result).length;
  if (projectedResultBytes > PORTABLE_RESULT_TARGET_BYTES) {
    result = {
      ...result,
      scenario_results: nativeResult.scenario_results.map(minimalPortableScenarioResult),
      findings: result.findings.map(portableFindingRef),
      durable_result_recording: boundedPortableCell({ status: nativeResult.durable_result_recording?.status }),
      retry_backoff: boundedPortableCell({ status: nativeResult.retry_backoff?.status }),
      timeout_behavior: boundedPortableCell({ status: nativeResult.timeout_behavior?.status }),
      heartbeat_timeout_renewal: boundedPortableCell({ status: nativeResult.heartbeat_timeout_renewal?.status }),
      typed_failure_propagation: boundedPortableCell({ status: nativeResult.typed_failure_propagation?.status }),
      heartbeat_cancellation: boundedPortableCell({ status: nativeResult.heartbeat_cancellation?.status }),
      idempotent_completion: boundedPortableCell({ status: nativeResult.idempotent_completion?.status }),
      operator_visibility: boundedPortableCell({ status: nativeResult.operator_visibility?.status }),
      portable_projection: {
        reason: 'projection_target',
        projected_bytes: projectedResultBytes,
        projection_target_bytes: PORTABLE_RESULT_TARGET_BYTES,
      },
    };
  }

  if (encodedResultBytes(result).length > PORTABLE_RESULT_LIMIT_BYTES) {
    const originalBytes = encodedResultBytes(result).length;
    const findingId = 'activity_portable_result_limit_exceeded';
    const infrastructureFinding = {
      id: findingId,
      finding_type: findingId,
      scenario_id: 'portable_evidence',
      classification: 'runner-gap',
      owning_surface: 'conformance_harness',
      summary: `The projected activity result required ${originalBytes} bytes; the portable contract allows ${PORTABLE_RESULT_LIMIT_BYTES} bytes.`,
    };
    const blockedScenarios = REQUIRED_SCENARIOS.map((scenarioId) => ({
      scenario_id: scenarioId,
      status: 'runner_blocked',
      classification: 'runner-gap',
      observed_outputs: {
        portable_diagnostic: {
          code: 'portable_result_limit_exceeded',
          original_bytes: originalBytes,
          limit_bytes: PORTABLE_RESULT_LIMIT_BYTES,
        },
      },
      linked_findings: [portableFindingRef(infrastructureFinding)],
    }));
    const blockedSection = { status: 'runner_blocked', reason: 'portable_result_limit_exceeded' };
    result = {
      schema: nativeResult.schema,
      schema_version: nativeResult.schema_version,
      suite_schema: nativeResult.suite_schema,
      suite_version: nativeResult.suite_version,
      category: nativeResult.category,
      outcome: 'non_passing_runner_blocked',
      runner_blocked: true,
      runner_blocked_evidence: nativeResult.runner_blocked_evidence
        ? boundedPortableCell(nativeResult.runner_blocked_evidence)
        : null,
      started_at: nativeResult.started_at,
      finished_at: nativeResult.finished_at,
      generated_at: nativeResult.generated_at,
      artifact_versions: nativeResult.artifact_versions,
      executed_distribution_identities: nativeResult.executed_distribution_identities,
      executed_distribution_identity_failures: boundedPortableCell(nativeResult.executed_distribution_identity_failures),
      published_artifact_versions: nativeResult.published_artifact_versions,
      artifact_sources: nativeResult.artifact_sources,
      execution_source: nativeResult.execution_source,
      local_product_source_checkouts_used: nativeResult.local_product_source_checkouts_used,
      published_artifact_worker_execution: nativeResult.published_artifact_worker_execution
        ? portablePublishedExecution(nativeResult.published_artifact_worker_execution)
        : null,
      published_artifact_install: blockedSection,
      runtime_matrix: portableRuntimeMatrix,
      topology: nativeResult.topology,
      durable_result_recording: blockedSection,
      retry_backoff: blockedSection,
      timeout_behavior: blockedSection,
      heartbeat_timeout_renewal: blockedSection,
      typed_failure_propagation: blockedSection,
      heartbeat_cancellation: blockedSection,
      idempotent_completion: blockedSection,
      operator_visibility: blockedSection,
      scenario_results: blockedScenarios,
      findings: [infrastructureFinding],
      finding_links: Object.fromEntries(REQUIRED_SCENARIOS.map((scenarioId) => [
        scenarioId,
        [portableFindingRef(infrastructureFinding)],
      ])),
      portable_evidence_contract: portableContract,
      portable_projection: {
        reason: 'portable_result_limit_exceeded',
        projected_bytes: originalBytes,
        max_result_bytes: PORTABLE_RESULT_LIMIT_BYTES,
      },
    };
  }

  if (encodedResultBytes(result).length > PORTABLE_RESULT_LIMIT_BYTES) {
    throw new Error('portable activity result exceeded its result budget after infrastructure fallback');
  }

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
    executed_distribution_identity_failures: distributionIdentityFailures,
    scenario_manifest: MANIFEST_PATH,
  };

  const record = {
    experiment: 'activities',
    outcome: result.outcome === 'pass' ? 'pass' : (result.runner_blocked ? 'error' : 'fail'),
    runnerBlocked: result.runner_blocked,
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
  writeJson(path.join(RESULT_DIR, 'activities-findings.json'), result.findings);
  console.log(JSON.stringify(result, null, 2));

  return result.outcome === 'pass' ? 0 : 1;
}

process.exitCode = main();
JS
