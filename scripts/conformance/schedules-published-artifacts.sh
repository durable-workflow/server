#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage: schedules-published-artifacts.sh [--result-dir DIR|--result-dir=DIR] [--keep-run-root[=1|true]]

Builds a schedules conformance result from published-artifact evidence only.
The runner never treats a Python CRUD smoke as complete schedule conformance:
unexecuted cadence, restart, public-client, and cross-language cells are
emitted as not_covered with focused findings.

The runner writes these files to the result directory:
  published-artifacts.json
  schedules-runtime-result.json
  schedules-runtime-record.json

Environment overrides:
  DW_SCHEDULES_RUN_ROOT             Scratch directory. Defaults to mktemp.
  DW_SCHEDULES_RESULT_DIR           Result directory. Defaults to run root.
  DW_SCHEDULES_KEEP_RUN_ROOT=1      Keep scratch directory after success.
  DW_SERVER_VERSION                 Published server version under test.
  DW_CLI_VERSION                    Published CLI version under test.
  DW_PYTHON_SDK_VERSION             Published PyPI durable-workflow version.
  DW_PHP_SDK_VERSION                Exact published durable-workflow/sdk version.
                                    When empty, Composer resolves the latest
                                    stable Packagist version before shards run.
  DW_WATERLINE_VERSION              Published Waterline version under test.
  DW_SCHEDULES_SMOKE_EVIDENCE       Optional JSON from a published-artifact
                                    smoke or shard run. Scenario results in
                                    this file are merged into the output.
  DW_SCHEDULES_CADENCE_EVIDENCE     Optional JSON from a focused cron/fixed-rate
                                    cadence shard. Scenario results in this file
                                    are merged into the output.
  DW_SCHEDULES_CLI_EVIDENCE         Optional JSON from a focused official-CLI
                                    schedule lifecycle shard. Scenario results
                                    in this file are merged into the output.
  DW_SCHEDULES_PYTHON_LIFECYCLE_EVIDENCE
                                    Optional JSON from a focused published
                                    Python SDK schedule lifecycle shard.
                                    Scenario results in this file are merged
                                    into the output.
  DW_SCHEDULES_PHP_SURFACE_EVIDENCE Optional JSON from a focused published
                                    standalone PHP SDK schedule client shard.
                                    Scenario results in this file are merged
                                    into the output.
  DW_SCHEDULES_OPERATOR_CONTROLS_EVIDENCE
                                    Optional JSON from a focused list/describe,
                                    pause/resume, and delete shard. Scenario
                                    results in this file are merged into the output.
  DW_SCHEDULES_MISSED_RESTART_EVIDENCE
                                    Optional JSON from a focused missed-fire and
                                    restart-survival shard. Scenario results in
                                    this file are merged into the output.
  DW_SCHEDULES_CROSS_LANGUAGE_EVIDENCE
                                    Optional JSON from a focused PHP/Python
                                    cross-language schedule dispatch shard.
                                    Scenario results in this file are merged
                                    into the output.
  DW_SCHEDULES_ADVERSARIAL_EVIDENCE Optional JSON from a focused adversarial
                                    schedule-input shard. Scenario results in
                                    this file are merged into the output.
  DW_SCHEDULES_ARTIFACT_INSTALL_EVIDENCE
                                    Optional JSON proving each artifact was
                                    installed from a published channel. Defaults
                                    to schedules-artifact-install-evidence.json
                                    and then artifact-install-evidence.json in
                                    the result directory.
  DW_SCHEDULES_RUN_CADENCE_SHARD    Set to 0 to skip the automatic Docker-backed
                                    cadence shard. Defaults to auto in this shell
                                    handoff: run it when Docker Compose and a
                                    published server artifact are available.
  DW_SCHEDULES_RUN_OPERATOR_CONTROLS_SHARD
                                    Set to 0 to skip the automatic Docker-backed
                                    operator-controls shard. Defaults to auto.
  DW_SCHEDULES_RUN_MISSED_RESTART_SHARD
                                    Set to 0 to skip the automatic Docker-backed
                                    missed-fire/restart shard. Defaults to auto.
  DW_SCHEDULES_RUN_CLI_SURFACE_SHARD
                                    Set to 0 to skip the automatic official-CLI
                                    schedule lifecycle shard. Defaults to auto.
  DW_SCHEDULES_RUN_PYTHON_LIFECYCLE_SHARD
                                    Set to 0 to skip the automatic published
                                    Python SDK schedule lifecycle shard.
                                    Defaults to auto.
  DW_SCHEDULES_RUN_PHP_SURFACE_SHARD
                                    Set to 0 to skip the automatic published
                                    standalone PHP SDK schedule client shard.
                                    Defaults to auto.
  DW_SCHEDULES_RUN_CROSS_LANGUAGE_SHARD
                                    Set to 0 to skip the automatic published
                                    PHP/Python cross-language schedule shard.
                                    Defaults to auto.
  DW_SCHEDULES_RUN_ADVERSARIAL_SHARD
                                    Set to 0 to skip the automatic adversarial
                                    unregistered workflow-type schedule shard.
                                    Defaults to auto.
  DW_SCHEDULES_SHARD_CONCURRENCY    Maximum automatic shard concurrency.
                                    Defaults to 1 for local published Compose
                                    stacks and 2 for an existing server URL.
  DW_SCHEDULES_ADVERSARIAL_INTERVAL Fixed-rate interval for the unregistered
                                    workflow-type probe. Defaults to PT10S.
  DW_SCHEDULES_ADVERSARIAL_FIRE_TIMEOUT_SECONDS
                                    Wait for first scheduled fire or skip event.
                                    Defaults to 90.
  DW_SCHEDULES_CLI_EXECUTABLE       Existing official dw executable to use instead
                                    of installing via the release install script.
  DW_SCHEDULES_SERVER_URL           Existing published server URL to probe instead
                                    of starting docker-compose.published.yml.
  DW_SERVER_IMAGE                   Exact published server image/tag/digest to test.
  DW_SCHEDULES_SERVER_PORT          Host port for the published server. Defaults
                                    to a free port.
  DW_SCHEDULES_SERVER_HOST          Optional host/IP to try when a Docker-published
                                    server port is not reachable on loopback.
  DW_SCHEDULES_DOCKER_HOST_GATEWAY  Optional Docker host gateway address for
                                    containerized conformance runners.
  DW_SCHEDULES_SERVER_READY_TIMEOUT_SECONDS
                                    Server readiness timeout per shard. Defaults
                                    to 120.
  DW_SCHEDULES_COMPOSE_WAIT_TIMEOUT_SECONDS
                                    Docker Compose --wait timeout for published
                                    stack startup. Defaults to 600.
  DW_SCHEDULES_CADENCE_TIMEOUT_SECONDS
                                    Overall wait for cadence fires. Defaults to 420.
  DW_SCHEDULES_OPERATOR_PAUSE_SECONDS
                                    Pause-window duration. Minimum/default is 120/125.
  DW_SCHEDULES_OPERATOR_DELETE_WINDOW_SECONDS
                                    Delete observation window. Defaults to 65.
  DW_SCHEDULES_MISSED_FIRE_DOWNTIME_SECONDS
                                    Scheduler downtime for missed-fire policy.
                                    Minimum/default is 120/125.
  DW_SCHEDULES_RESTART_FIRE_DEADLINE_SECONDS
                                    Restart survival fire deadline. Minimum/default
                                    is 90.
  DW_SCHEDULES_CROSS_LANGUAGE_TIMEOUT_SECONDS
                                    Overall wait for Python-created/PHP-worker
                                    and PHP-created/Python-worker scheduled fires.
                                    Defaults to 150.
  DW_SCHEDULES_CROSS_LANGUAGE_FOCUS Target cross-language cell for focused
                                    reruns. Accepts all, both,
                                    python_created_php_workflow, or
                                    php_created_python_workflow. Defaults to all.
USAGE
}

keep_run_root="${DW_SCHEDULES_KEEP_RUN_ROOT:-0}"
result_dir="${DW_SCHEDULES_RESULT_DIR:-}"

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

require_command() {
  command -v "$1" >/dev/null 2>&1
}

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/../.." && pwd)"

run_root="${DW_SCHEDULES_RUN_ROOT:-}"
if [[ -z "$run_root" ]]; then
  run_root="$(mktemp -d "${TMPDIR:-/tmp}/dw-schedules.XXXXXX")"
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

if ! require_command node; then
  printf '%s\n' 'required command not found: node' >&2
  exit 127
fi

export DW_SCHEDULES_RESULT_DIR="$result_dir"
export DW_SCHEDULES_RUN_ROOT="$run_root"
export DW_SCHEDULES_REPO_ROOT="$repo_root"
export DW_SCHEDULES_RUN_CADENCE_SHARD="${DW_SCHEDULES_RUN_CADENCE_SHARD:-auto}"
export DW_SCHEDULES_RUN_OPERATOR_CONTROLS_SHARD="${DW_SCHEDULES_RUN_OPERATOR_CONTROLS_SHARD:-auto}"
export DW_SCHEDULES_RUN_MISSED_RESTART_SHARD="${DW_SCHEDULES_RUN_MISSED_RESTART_SHARD:-auto}"
export DW_SCHEDULES_RUN_CLI_SURFACE_SHARD="${DW_SCHEDULES_RUN_CLI_SURFACE_SHARD:-auto}"
export DW_SCHEDULES_RUN_PYTHON_LIFECYCLE_SHARD="${DW_SCHEDULES_RUN_PYTHON_LIFECYCLE_SHARD:-auto}"
export DW_SCHEDULES_RUN_PHP_SURFACE_SHARD="${DW_SCHEDULES_RUN_PHP_SURFACE_SHARD:-auto}"
export DW_SCHEDULES_RUN_CROSS_LANGUAGE_SHARD="${DW_SCHEDULES_RUN_CROSS_LANGUAGE_SHARD:-auto}"
export DW_SCHEDULES_RUN_ADVERSARIAL_SHARD="${DW_SCHEDULES_RUN_ADVERSARIAL_SHARD:-auto}"

node "$script_dir/schedules-published-artifacts.mjs"
