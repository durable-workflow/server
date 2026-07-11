#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage: migration-published-artifacts.sh [--result-dir DIR|--result-dir=DIR] [--keep-run-root[=1|true]]

Composes the public v1-to-v2 migration conformance result from published
artifact evidence only. The runner never treats a local product checkout as an
artifact under test. The selected v1 artifact capability inventory is recorded
before continuity is evaluated, so control-plane surfaces absent from v1 are
classified explicitly while durable v1 state remains required. Missing
migration cells are recorded as not_covered with linked conformance-harness
findings so storage-connection smoke cannot pass by itself.

The runner writes these files to the result directory:
  migration-published-artifacts.json
  migration-conformance-result.json
  migration-conformance-record.json

Environment overrides:
  DW_MIGRATION_RUN_ROOT              Scratch directory. Defaults to mktemp.
  DW_MIGRATION_RESULT_DIR            Result directory. Defaults to run root.
  DW_MIGRATION_KEEP_RUN_ROOT=1       Keep scratch directory after success.
  DW_MIGRATION_EVIDENCE_JSON         Full-result, runbook-shaped, sectioned
                                      command-output, or scenario-shard JSON from
                                      the host migration runner. May include a
                                      source_capabilities inventory; embedded v1
                                      runtime metadata selects the published
                                      embedded capability profile automatically.
  DW_MIGRATION_EVIDENCE_DIR          Directory of sorted JSON evidence shards from the host migration runner.
  DW_MIGRATION_FOUNDATION_PLAN_FILE  JSON plan of host commands to execute for
                                      latest v1 state and queued-task setup,
                                      migration-guide commands, queue continuity,
                                      before/after snapshots, and a focused
                                      post-upgrade v2 schedule cell plus a v2
                                      worker registration cell. The schedule cell
                                      creates one schedule with the public CLI on an
                                      isolated server artifact, describes the same
                                      identity through the CLI and operator API, triggers it,
                                      and captures schedule history and workflow-run
                                      identity. Setup, transport, product, and
                                      assertion failures remain distinct.
                                      The worker cell runs registration, typed
                                      operator API projection, typed CLI
                                      projection, and public worker-protocol poll
                                      commands in that order on one unique queue.
                                      Each operation should emit its JSON body or
                                      a JSON {http_status,body} envelope; curl's
                                      trailing HTTP status is also recognized.
                                      Defaults to migration-foundation-plan.json
                                      in the result directory when present.
  DW_MIGRATION_FOUNDATION_PLAN_JSON  Inline JSON plan, or a path to a JSON plan.
  DW_MIGRATION_RUN_FOUNDATION_PLAN   Auto-execute the foundation plan when it is
                                      present. Defaults to auto. Set to 0 to
                                      disable, or 1/true/force to require it.
  DW_MIGRATION_STORAGE_SMOKE_JSON    Storage-connection smoke JSON. Detailed
                                      foundation runs may include migration_plan,
                                      latest_supported_v1_state_setup, and
                                      before/after state snapshots here.
  DW_MIGRATION_RUN_PUBLIC_GUIDE_AUDIT
                                      Auto-audit the live public migration guide
                                      when storage smoke passed but no full
                                      migration shard exists. Defaults to auto.
                                      Set to 0 to disable or 1 to force.
  DW_MIGRATION_GUIDE_URL             Public migration guide URL to audit.
                                      Defaults to the versioned 2.0 guide.
  DW_MIGRATION_GUIDE_AUDIT_TEXT      Inline guide text fixture for the audit.
  DW_MIGRATION_GUIDE_AUDIT_FILE      Guide text/HTML fixture path for the audit.
  DW_MIGRATION_RESOLVE_PUBLIC_ARTIFACTS
                                      Resolve missing v1 artifact pins from public package registries. Defaults to 1.
  DW_MIGRATION_PUBLIC_ARTIFACTS_JSON Optional JSON fixture/cache for public artifact resolution,
                                      including packagist_versions keyed by package name.
  DW_SERVER_V1_VERSION               Exact latest supported v1 server runtime version.
                                      When no standalone v1 image is published,
                                      this is the embedded v1 runtime package.
  DW_SERVER_V1_ARTIFACT_SOURCE       Published source for the v1 server runtime.
  DW_SERVER_VERSION                  Exact target v2 server artifact version.
  DW_SERVER_ARTIFACT_SOURCE          Published source for the v2 server artifact.
  DW_CLI_V1_VERSION                  Exact latest supported v1 CLI artifact version.
  DW_CLI_V1_ARTIFACT_SOURCE          Published source for the v1 CLI artifact.
  DW_CLI_VERSION                     Exact published v2 CLI version.
  DW_CLI_ARTIFACT_SOURCE             Published source for the v2 CLI artifact.
  DW_WORKFLOW_PHP_V1_VERSION         Exact latest published v1 workflow package version.
  DW_WORKFLOW_PHP_V1_ARTIFACT_SOURCE Published source for the v1 workflow package. Resolution
                                      prefers durable-workflow/workflow and uses the legacy alias
                                      only at the same or a newer supported version.
  DW_WORKFLOW_PHP_VERSION            Exact published v2 workflow package version.
  DW_WORKFLOW_PHP_ARTIFACT_SOURCE    Published source for the v2 workflow package.
  DW_PYTHON_SDK_VERSION              Exact published Python SDK version.
  DW_PYTHON_SDK_ARTIFACT_SOURCE      Published source for the Python SDK package.
  DW_WATERLINE_V1_VERSION            Exact published v1 Waterline artifact version.
  DW_WATERLINE_V1_ARTIFACT_SOURCE    Published source for the v1 Waterline artifact.
  DW_WATERLINE_VERSION               Exact published v2 Waterline version.
  DW_WATERLINE_ARTIFACT_SOURCE       Published source for the v2 Waterline package.
  DW_SAMPLE_APP_V1_VERSION           Exact published v1-compatible sample-app tag or commit.
  DW_SAMPLE_APP_V1_ARTIFACT_SOURCE   Published source for the v1-compatible sample-app.
USAGE
}

keep_run_root="${DW_MIGRATION_KEEP_RUN_ROOT:-0}"
result_dir="${DW_MIGRATION_RESULT_DIR:-}"

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

run_root="${DW_MIGRATION_RUN_ROOT:-}"
if [[ -z "$run_root" ]]; then
  run_root="$(mktemp -d "${TMPDIR:-/tmp}/dw-migration.XXXXXX")"
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

if ! command -v node >/dev/null 2>&1; then
  printf '%s\n' 'required command not found: node' >&2
  exit 127
fi

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/../.." && pwd)"

export DW_MIGRATION_RESULT_DIR="$result_dir"
export DW_MIGRATION_RUN_ROOT="$run_root"
export DW_MIGRATION_REPO_ROOT="$repo_root"

node "$script_dir/migration-published-artifacts.mjs"
