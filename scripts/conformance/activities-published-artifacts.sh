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
  DW_SERVER_VERSION                     Exact patch server Docker tag; required for digest-only DW_SERVER_IMAGE.
  DW_CLI_VERSION                        Exact CLI release version.
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
  local code=$?

  if [[ "$keep_run_root" != "1" && "$code" -eq 0 && "$result_dir" != "$run_root" && "$run_root_supplied" != "1" ]]; then
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
  fi
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
  local installer_url=""
  mkdir -p "$cli_root/bin"

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
  if VERSION="$DW_CLI_VERSION" \
    DURABLE_WORKFLOW_INSTALL_DIR="$cli_root/bin" \
    DURABLE_WORKFLOW_BIN_NAME=dw \
    DURABLE_WORKFLOW_INSTALL_VERIFY_ATTESTATIONS=0 \
    sh "$installer" >"$result_dir/activity-cli-install.log" 2>&1 \
    && [[ -x "$cli_bin" ]]; then
    export DW_ACTIVITIES_CLI_BIN="$cli_bin"
    export DW_ACTIVITIES_CLI_SOURCE="$installer_url"
    return 0
  fi

  export DW_ACTIVITIES_CLI_UNAVAILABLE_REASON="official CLI installer failed for release ${DW_CLI_VERSION}; see activity-cli-install.log"
  return 0
}

run_focused_activity_host_probe() {
  local probe_db="$run_root/activities-focused-host-probe.sqlite"

  : > "$probe_db"
  prepare_focused_python_sdk
  prepare_published_activity_cli

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
  DW_ACTIVITIES_CLI_BIN="${DW_ACTIVITIES_CLI_BIN:-}" \
  DW_ACTIVITIES_CLI_SOURCE="${DW_ACTIVITIES_CLI_SOURCE:-}" \
  DW_ACTIVITIES_CLI_UNAVAILABLE_REASON="${DW_ACTIVITIES_CLI_UNAVAILABLE_REASON:-}" \
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
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\FailureCategory;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ActivityOptions;
use Workflow\V2\Support\RunActivityView;
use Workflow\V2\Support\WorkflowFiberRunner;
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
        $scenarioId = is_string($payload['scenario_id'] ?? null) ? $payload['scenario_id'] : '';

        if ($scenarioId === 'typed_failure_propagation') {
            try {
                Workflow::activity(
                    ACTIVITY_TYPE,
                    new ActivityOptions(queue: ACTIVITIES_TASK_QUEUE),
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

function run_activity_views(string $runId): array
{
    $run = workflow_run_or_fail($runId);

    return RunActivityView::activitiesForRun($run);
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

    register_worker($workerId, [], [ACTIVITY_TYPE], $runtime);
    $start = request_json('POST', '/activities', [
        'activity_id' => $activityId,
        'activity_type' => ACTIVITY_TYPE,
        'task_queue' => ACTIVITIES_TASK_QUEUE,
        'input' => [[
            'scenario_id' => 'php_python_activity_parity',
            'runtime' => $runtime,
            'observation' => $observation,
            'input_marker' => "parity-{$observation}-{$suffix}",
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
        'activity_id' => $activityId,
        'workflow_run_id' => $runId,
        'activity_execution_id' => $activityExecutionId,
        'task' => poll_task('activity', $workerId),
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
    $pass = ($show['status'] ?? null) === RunStatus::Completed->value
        && ($complete['recorded'] ?? null) === true
        && is_array($result);
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
    $secondTask = poll_task('activity', $activity['worker_id']);
    [$result, $complete, $completionArtifact] = complete_activity_task($secondTask, $runtime, 'standalone');
    if ($runtime === 'sdk-python') {
        $workerArtifact = $completionArtifact;
    }
    $show = request_json('GET', '/activities/'.rawurlencode($activity['activity_id']));
    $history = request_json('GET', '/workflows/'.rawurlencode($activity['activity_id']).'/runs/'.rawurlencode($activity['workflow_run_id']).'/history');
    $pass = ($firstTask['activity_execution_id'] ?? null) === ($secondTask['activity_execution_id'] ?? null)
        && (int) ($firstTask['attempt_number'] ?? 0) === 1
        && (int) ($secondTask['attempt_number'] ?? 0) === 2
        && ($firstTask['activity_attempt_id'] ?? null) !== ($secondTask['activity_attempt_id'] ?? null)
        && ($show['status'] ?? null) === RunStatus::Completed->value
        && ($complete['recorded'] ?? null) === true;
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
    $heartbeatPayload = history_payload_for_execution(
        $history,
        HistoryEventType::ActivityHeartbeatRecorded->value,
        $activity['activity_execution_id'],
    );
    $pass = ($heartbeat['heartbeat_recorded'] ?? null) === true
        && ($heartbeat['cancel_requested'] ?? null) === false
        && is_array($heartbeatPayload)
        && ($show['status'] ?? null) === RunStatus::Completed->value;
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
    $attemptState = attempt_snapshots($activity['activity_execution_id']);
    $latestAttempt = latest_attempt_snapshot($attemptState);
    $terminalState = ($lateCompletion['outcome'] ?? null) === 'ignored'
        && ($lateCompletion['reason'] ?? null) === 'run_cancelled'
        && ($lateCompletion['run_status'] ?? null) === RunStatus::Cancelled->value
        && cancelled_or_failed_activity_status($lateCompletion['activity_status'] ?? null)
        && cancelled_or_failed_activity_status($latestAttempt['status'] ?? null);
    $pass = ($cancelHeartbeat['cancel_requested'] ?? null) === true
        && ($cancelHeartbeat['can_continue'] ?? null) === false
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

function operator_surface_snapshot(string $state, string $activityId, string $runId, string $activityExecutionId, ?string $activityAttemptId = null): array
{
    $apiDetail = request_json('GET', '/activities/'.rawurlencode($activityId));
    $apiRunDetail = request_json('GET', '/workflows/'.rawurlencode($activityId).'/runs/'.rawurlencode($runId));
    $history = request_json('GET', '/workflows/'.rawurlencode($activityId).'/runs/'.rawurlencode($runId).'/history');
    $taskQueueDetail = request_json('GET', '/task-queues/'.rawurlencode(ACTIVITIES_TASK_QUEUE));
    $listEvidence = activity_list_evidence($activityId);
    $activityViews = run_activity_views($runId);
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
            'task_queue' => ACTIVITIES_TASK_QUEUE,
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
            'projection_source' => 'Workflow\\V2\\Support\\RunActivityView::activitiesForRun',
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

    register_worker($workerId, [], [ACTIVITY_TYPE], 'workflow-php');
    $start = request_json('POST', '/activities', [
        'activity_id' => $activityId,
        'activity_type' => ACTIVITY_TYPE,
        'task_queue' => ACTIVITIES_TASK_QUEUE,
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
        'activity_id' => $activityId,
        'workflow_run_id' => $runId,
        'activity_execution_id' => $activityExecutionId,
        'task' => poll_task('activity', $workerId),
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

    register_worker($workerId, [], [ACTIVITY_TYPE], 'workflow-php');
    $start = request_json('POST', '/activities', [
        'activity_id' => $activityId,
        'activity_type' => ACTIVITY_TYPE,
        'task_queue' => ACTIVITIES_TASK_QUEUE,
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

    $activityTask = poll_task('activity', $workerId);
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

    register_worker($workerId, [], [ACTIVITY_TYPE], 'workflow-php');
    $start = request_json('POST', '/activities', [
        'activity_id' => $activityId,
        'activity_type' => ACTIVITY_TYPE,
        'task_queue' => ACTIVITIES_TASK_QUEUE,
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

    $activityTask = poll_task('activity', $workerId);
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
    $completedHistoryCount = count_event_type($history, HistoryEventType::ActivityCompleted->value);
    $recordedOnce = ($firstCompletion['recorded'] ?? null) === true
        && ($duplicateCompletion['recorded'] ?? null) === false
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
        'activity_execution_id' => $activityExecutionId,
        'activity_attempt_id' => $activityTask['activity_attempt_id'] ?? null,
        'activity_type' => $activityTask['activity_type'] ?? ACTIVITY_TYPE,
        'first_completion_response' => $firstCompletion,
        'duplicate_completion_response' => $duplicateCompletion,
        'recorded_once' => $recordedOnce,
        'stale_attempt_or_idempotent_verdict' => $duplicateCompletion['reason'] ?? null,
        'activity_completed_history_count' => $completedHistoryCount,
        'activity_completed_history_events' => history_payloads_for_event($history, HistoryEventType::ActivityCompleted->value),
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
    $stateObservations = [];
    foreach (['in_flight', 'retrying', 'timed_out', 'failed', 'completed', 'cancelled'] as $state) {
        $stateObservations[$state] = operator_visibility_state_observation($state, $suffix);
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
            'task_queue' => ACTIVITIES_TASK_QUEUE,
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

function run_timeout_behavior_cell(): array
{
    $suffix = bin2hex(random_bytes(3));
    $workerId = "activities-timeout-{$suffix}";
    $activityId = "activities-timeout-{$suffix}";
    $configuredTimeouts = [
        'start_to_close_timeout_seconds' => 1,
        'schedule_to_close_timeout_seconds' => 30,
        'retry_policy' => [
            'max_attempts' => 1,
            'backoff_seconds' => [0],
        ],
    ];

    register_worker($workerId, [], [ACTIVITY_TYPE], 'workflow-php');
    $start = request_json('POST', '/activities', [
        'activity_id' => $activityId,
        'activity_type' => ACTIVITY_TYPE,
        'task_queue' => ACTIVITIES_TASK_QUEUE,
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
    $activityTask = poll_task('activity', $workerId);
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

function run_typed_failure_propagation_cell(): array
{
    $suffix = bin2hex(random_bytes(3));
    $workerId = "activities-typed-failure-{$suffix}";
    $workflowId = "activities-typed-failure-{$suffix}";
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

    register_worker($workerId, [EMBEDDED_WORKFLOW_TYPE], [ACTIVITY_TYPE], 'workflow-php');
    $start = request_json('POST', '/workflows', [
        'workflow_id' => $workflowId,
        'workflow_type' => EMBEDDED_WORKFLOW_TYPE,
        'task_queue' => ACTIVITIES_TASK_QUEUE,
        'input' => [[
            'scenario_id' => 'typed_failure_propagation',
            'runtime' => 'workflow-php',
            'input_marker' => "typed-failure-{$suffix}",
        ]],
    ]);
    $runId = (string) ($start['run_id'] ?? '');
    if ($runId === '') {
        throw new RuntimeException('typed failure workflow start did not return a run id');
    }

    $workflowTask = poll_task('workflow', $workerId);
    complete_workflow_task_from_runtime($workflowTask);

    $activityTask = poll_task('activity', $workerId);
    $failResponse = fail_activity_task($activityTask, $failurePayload);
    if (($failResponse['outcome'] ?? null) !== 'failed' || ($failResponse['recorded'] ?? null) !== true) {
        throw new RuntimeException('typed failure activity report was not durably recorded');
    }

    $resumeTask = poll_task('workflow', $workerId);
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
    $nonCliSurfacesPass = array_diff($requiredStates, array_keys($statePassesWithoutCli)) === []
        && ! in_array(false, array_map(
            static fn (mixed $value): bool => $value === true,
            $statePassesWithoutCli
        ), true)
        && ($cell['api_run_detail']['api_visible'] ?? null) === true
        && ($cell['history_activity_attempts']['history_visible'] ?? null) === true
        && ($cell['operator_metrics']['lease_visible'] ?? null) === true
        && ($cell['waterline_activity_attempt_view']['waterline_visible'] ?? null) === true;
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
        failure_behavior_scenario('idempotent_completion_handling', $throwable),
        failure_behavior_scenario('php_python_activity_parity', $throwable),
        failure_behavior_scenario('operator_visible_activity_attempt_state', $throwable),
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

const REQUIRED_DISTRIBUTION_IDENTITIES = [
  'workflow',
  'waterline',
  'server',
  'cli',
  'sdk-python',
];

const DISTRIBUTION_COMPONENTS = {
  workflow: { kind: 'composer', package: 'durable-workflow/workflow', versionKey: 'workflow' },
  waterline: { kind: 'composer', package: 'durable-workflow/waterline', versionKey: 'waterline' },
  server: { kind: 'oci', package: 'docker.io/durableworkflow/server', versionKey: 'server' },
  cli: { kind: 'github-release', package: 'durable-workflow/cli', versionKey: 'cli' },
  'sdk-python': { kind: 'pypi', package: 'durable-workflow', versionKey: 'sdk-python' },
};

const DISTRIBUTION_VERSION_PATTERN = /^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z][0-9A-Za-z.-]*)?$/;
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
  if (!DISTRIBUTION_VERSION_PATTERN.test(version)) {
    throw new Error(`exact distribution version is unavailable for ${component}`);
  }
  const expectedLocator = `${definition.kind}:${definition.package}@${version}`;
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
    const name = stringValue(artifact.name);
    const digest = stringValue(artifact.sha256);
    if (!name || name.length > 256 || (!['workflow', 'waterline'].includes(component) && name.includes('/'))) {
      throw new Error(`executed distribution artifact name for ${component} is invalid`);
    }
    if (!DISTRIBUTION_DIGEST_PATTERN.test(digest)) {
      throw new Error(`executed distribution SHA-256 for ${component}:${name} is invalid`);
    }
    return { name, sha256: digest };
  }).sort((left, right) => left.name.localeCompare(right.name));

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
        .sort(([left], [right]) => left.localeCompare(right))
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
  const recordOutcome = outcome === 'pass' ? 'pass' : (runnerBlocked ? 'error' : 'fail');
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
    executed_distribution_identity_failures: distributionIdentityFailures,
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
