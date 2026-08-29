#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage: timers-published-artifacts.sh [--result-dir DIR|--result-dir=DIR] [--keep-run-root[=1|true]]

Writes the published-artifact timer conformance handoff result.

When this handoff runs from the pinned published server image root, it executes
the focused normal sleep completion, worker-restart-while-sleeping,
server-restart-while-sleeping, replay-after-timer-fire,
concurrent-timers-with-distinct-deadlines, cancellation-while-waiting, and
operator-visible-waiting-state shards and records concrete timer runtime
evidence.

The runner writes these files to the result directory:
  pins.json
  run-metadata.json
  timer-runtime-result.json
  timer-runtime-record.json
  timer-runtime-findings.json
  timers-result.json
  timers-record.json

Environment overrides:
  DW_TIMERS_RESULT_DIR              Result directory. Defaults to run root.
  DW_TIMERS_RUN_ROOT                Scratch directory. Defaults to mktemp.
  DW_TIMERS_KEEP_RUN_ROOT=1         Keep scratch directory after success.
  DW_TIMERS_SCENARIO_MANIFEST       Scenario manifest path. Defaults to the server static mirror.
  DW_TIMERS_EVIDENCE                Optional JSON timer evidence from a real host shard.
  DW_TIMERS_EVIDENCE_PATH           Optional path to JSON timer evidence from a real host shard.
  DW_TIMERS_SKIP_FOCUSED_HOST_PROBE=1
                                     Skip the published server image's focused timer shards.
  DW_TIMERS_RUNNER_SOURCE           Exact source for the runner process. Defaults to DW_SERVER_IMAGE.
  DW_SERVER_IMAGE                   Exact server image tag or digest to test.
  DW_SERVER_VERSION                 Exact server SemVer tag; required for digest-only DW_SERVER_IMAGE.
  DW_CLI_VERSION                    Exact CLI release version.
  DW_PYTHON_SDK_VERSION             Exact PyPI durable-workflow version.
  DW_WORKFLOW_PHP_VERSION           Exact Composer durable-workflow/workflow version.
  DW_WATERLINE_VERSION              Exact Waterline artifact version.
USAGE
}

keep_run_root="${DW_TIMERS_KEEP_RUN_ROOT:-0}"
result_dir="${DW_TIMERS_RESULT_DIR:-}"

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
scenario_manifest="${DW_TIMERS_SCENARIO_MANIFEST:-$repo_root/static/platform-conformance/timer-runtime-scenarios.json}"

run_root="${DW_TIMERS_RUN_ROOT:-}"
run_root_supplied=1
if [[ -z "$run_root" ]]; then
  run_root="$(mktemp -d "${TMPDIR:-/tmp}/dw-timers.XXXXXX")"
  run_root_supplied=0
fi
mkdir -p "$run_root"

if [[ -z "$result_dir" ]]; then
  result_dir="$run_root"
fi
mkdir -p "$result_dir"

cleanup() {
  local code=$?

  if [[ "$keep_run_root" != "1" && "$code" -eq 0 && "$result_dir" != "$run_root" && "$run_root_supplied" != "1" ]]; then
    rm -rf "$run_root"
  fi
}
trap cleanup EXIT

if ! require_command node; then
  printf '%s\n' 'required command not found: node' >&2
  exit 1
fi

should_run_focused_timer_host_probe() {
  if [[ "${DW_TIMERS_SKIP_FOCUSED_HOST_PROBE:-0}" == "1" || "${DW_TIMERS_SKIP_FOCUSED_HOST_PROBE:-}" == "true" ]]; then
    return 1
  fi
  if [[ -n "${DW_TIMERS_EVIDENCE:-}" || -n "${DW_TIMERS_EVIDENCE_PATH:-}" ]]; then
    return 1
  fi
  if [[ -s "$result_dir/timer-evidence.json" ]]; then
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

run_focused_timer_host_probe() {
  local probe_db="$run_root/timers-focused-host-probe.sqlite"

  : > "$probe_db"

  APP_ENV=production \
  APP_DEBUG=false \
  APP_KEY="${APP_KEY:-base64:VElNRVJTLUNPTkZPUk1BTkNFLUZPQ1VTRUQtSE9TVC1QUk9CRQ==}" \
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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Workflow\Serializers\Avro;
use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Support\WorkflowFiberRunner;
use Workflow\V2\Workflow;
use function Workflow\V2\timer;

const TIMERS_NAMESPACE = 'timers-conformance';
const TIMERS_TASK_QUEUE = 'timers-normal-sleep';
const NORMAL_SLEEP_WORKFLOW_TYPE = 'timers.conformance.normal-sleep';
const HOST_EVIDENCE_SCHEMA = 'durable-workflow.v2.timer-runtime.published-artifact-host-evidence';
const HOST_EVIDENCE_SOURCE = 'published_server_container';

$timersHttpKernel = null;

$repoRoot = getenv('RUNNER_REPO_ROOT') ?: '/app';
if (! is_dir($repoRoot)) {
    throw new RuntimeException('published server root is not available');
}
chdir($repoRoot);

require $repoRoot.'/vendor/autoload.php';

#[Type(NORMAL_SLEEP_WORKFLOW_TYPE)]
final class PublishedTimerNormalSleepWorkflow extends Workflow
{
    public function handle(array $payload = []): array
    {
        $seconds = isset($payload['sleep_seconds']) && is_numeric($payload['sleep_seconds'])
            ? max(1, min(10, (int) $payload['sleep_seconds']))
            : 2;

        timer($seconds);

        return [
            'slept' => true,
            'requested_sleep_seconds' => $seconds,
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }
}

function now_iso(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

function timestamp_iso(mixed $value): ?string
{
    if ($value instanceof DateTimeInterface) {
        return gmdate('Y-m-d\TH:i:s\Z', $value->getTimestamp());
    }
    if (is_string($value) && trim($value) !== '') {
        $timestamp = strtotime($value);

        return $timestamp === false ? null : gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    return null;
}

function run_status_value(mixed $status): string
{
    if ($status instanceof RunStatus) {
        return $status->value;
    }

    return is_scalar($status) ? (string) $status : get_debug_type($status);
}

function write_json_file(string $path, array $value): void
{
    file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
}

function output_path(): string
{
    $dir = getenv('RESULT_DIR') ?: sys_get_temp_dir();

    return rtrim($dir, '/').'/timer-evidence.json';
}

function scenario_slug(string $scenarioId): string
{
    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($scenarioId)) ?: 'scenario';

    return trim($slug, '-');
}

function scenario_expected_behavior(string $scenarioId): string
{
    return match ($scenarioId) {
        'worker_restart_while_sleeping' => 'worker_restart_does_not_drop_or_duplicate_a_sleeping_timer',
        'server_restart_while_sleeping' => 'server_restart_recovers_waiting_timer_state_and_completes_after_wake_up',
        'replay_after_timer_fire' => 'replay_after_timer_fire_is_deterministic_and_does_not_schedule_duplicate_timers',
        'concurrent_timers_distinct_deadlines' => 'timers_resume_in_recorded_wake_up_order_without_early_or_duplicate_fires',
        'cancellation_while_waiting' => 'cancellation_requested_before_recorded_wake_up_and_timer_never_fires_after_cancel',
        'operator_visible_timer_waiting_state' => 'operators_can_observe_an_explicit_waiting_or_timer_waiting_state_on_a_public_surface',
        default => 'workflow_sleep_completes_after_recorded_wake_up_without_early_resume',
    };
}

function scenario_next_acceptance(string $scenarioId): string
{
    return match ($scenarioId) {
        'worker_restart_while_sleeping' => 'rerun the focused timer host probe from the pinned published server image and record completion after wake_up_at with timer_fire_count exactly one and duplicate_resume_count zero after worker restart',
        'server_restart_while_sleeping' => 'rerun the focused timer host probe from the pinned published server image and record timer_state_recovered true, completion after wake_up_at, timer_fire_count exactly one, and duplicate_resume_count zero after server restart',
        'replay_after_timer_fire' => 'rerun the focused timer host probe from the pinned published server image and record replay_started_at after fired_at, replayed_event_types including TimerFired, and duplicate_timer_commands exactly zero',
        'concurrent_timers_distinct_deadlines' => 'rerun the focused timer host probe from the pinned published server image and record timer ids, distinct wake_up_times, observed_resume_order matching deadline order, fired_at_times at or after wake_up_times, and fire_counts exactly one for every timer id',
        'cancellation_while_waiting' => 'rerun the focused timer host probe from the pinned published server image and record cancellation_requested_at before wake_up_at, fired_after_cancel false, and a documented terminal workflow_status',
        'operator_visible_timer_waiting_state' => 'rerun the focused timer host probe from the pinned published server image and record an explicit waiting status from CLI, Waterline, or a public API response before the timer wake-up',
        default => 'rerun the focused timer host probe from the pinned published server image and record completed_at greater than or equal to wake_up_at with no early terminal observation',
    };
}

function finding_for_failure(string $scenarioId, string $message): array
{
    return [
        'id' => 'timer-'.scenario_slug($scenarioId).'-product-gap',
        'scenario_id' => $scenarioId,
        'finding_type' => 'timer_runtime_product_gap',
        'classification' => 'product-gap',
        'root_cause_classification' => 'product-gap',
        'owning_surface' => 'timer_runtime',
        'observed_behavior' => $message,
        'expected_behavior' => scenario_expected_behavior($scenarioId),
        'next_acceptance_criterion' => scenario_next_acceptance($scenarioId),
        'priority' => 'P0',
    ];
}

function failure_scenario(string $scenarioId, Throwable $throwable): array
{
    $message = $throwable::class.': '.$throwable->getMessage();
    $finding = finding_for_failure($scenarioId, $message);

    return [
        'scenario_id' => $scenarioId,
        'status' => 'fail',
        'classification' => 'product-gap',
        'observed_behavior' => $message,
        'observed_outputs' => [
            'execution_source' => HOST_EVIDENCE_SOURCE,
            'failure' => $message,
            'no_local_product_source_checkout_pass_evidence' => true,
        ],
        'linked_findings' => [[
            'finding_id' => $finding['id'],
            'finding_type' => $finding['finding_type'],
            'classification' => $finding['classification'],
        ]],
        'finding' => $finding,
    ];
}

function evidence_document(array $scenarios, array $findings = []): array
{
    return [
        'schema' => HOST_EVIDENCE_SCHEMA,
        'generated_at' => now_iso(),
        'evidence_source' => 'focused_published_server_timer_host_probe',
        'execution_source' => HOST_EVIDENCE_SOURCE,
        'runner' => 'published-server-timers-focused-host-probe',
        'local_product_source_checkouts_used' => false,
        'scenario_results' => $scenarios,
        'findings' => $findings,
    ];
}

function bootstrap_application(string $repoRoot, bool $runMigrations = true): void
{
    $app = require $repoRoot.'/bootstrap/app.php';
    $app->make(ConsoleKernel::class)->bootstrap();

    config([
        'app.key' => getenv('APP_KEY') ?: 'base64:VElNRVJTLUNPTkZPUk1BTkNFLUZPQ1VTRUQtSE9TVC1QUk9CRQ==',
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => getenv('DB_DATABASE') ?: ':memory:',
        'queue.default' => 'database',
        'cache.default' => 'array',
        'session.driver' => 'array',
        'server.auth.driver' => 'none',
        'server.mode' => 'service',
        'workflows.v2.task_dispatch_mode' => 'poll',
    ]);

    if ($runMigrations) {
        $bootstrapExitCode = Artisan::call('server:bootstrap', ['--force' => true]);
        if ($bootstrapExitCode !== 0) {
            throw new RuntimeException(sprintf(
                'published image server-bootstrap failed with exit code %d: %s',
                $bootstrapExitCode,
                trim(Artisan::output()),
            ));
        }
        if (! Schema::hasTable('jobs')) {
            throw new RuntimeException('published image server-bootstrap did not create the database queue jobs table');
        }
    }

    WorkflowNamespace::query()->updateOrCreate(
        ['name' => TIMERS_NAMESPACE],
        [
            'description' => 'Timers conformance namespace',
            'retention_days' => 30,
            'status' => 'active',
        ]
    );
}

function reset_http_kernel(): void
{
    $GLOBALS['timersHttpKernel'] = null;
}

function header_key(string $name): string
{
    return 'HTTP_'.str_replace('-', '_', strtoupper($name));
}

function request_json(string $method, string $path, ?array $body = null, array $allowed = []): array
{
    global $timersHttpKernel;

    if (! $timersHttpKernel instanceof HttpKernel) {
        $timersHttpKernel = app(HttpKernel::class);
    }

    $kernel = $timersHttpKernel;

    $server = [
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_NAMESPACE' => TIMERS_NAMESPACE,
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

function workflow_task_runtime_step(array $task): array
{
    $codec = task_codec($task);
    $runner = WorkflowFiberRunner::forClass(
        PublishedTimerNormalSleepWorkflow::class,
        (string) ($task['workflow_id'] ?? $task['workflow_instance_id'] ?? ''),
        (string) ($task['run_id'] ?? $task['workflow_run_id'] ?? ''),
        workflow_arguments($task, $codec),
        $codec,
        history_events($task),
        TIMERS_NAMESPACE,
    );
    $step = $runner->step();
    if ($step->commands === []) {
        throw new RuntimeException('workflow runtime produced no commands for the leased timer task');
    }

    return [
        'completed' => $step->completed,
        'result' => $step->result,
        'commands' => $step->commands,
    ];
}

function complete_workflow_task_with_commands(array $task, array $commands): array
{
    return request_json(
        'POST',
        '/worker/workflow-tasks/'.rawurlencode((string) $task['task_id']).'/complete',
        DirectConformanceWorkerProtocol::workflowTaskCompletion($task, $commands),
    );
}

function complete_workflow_task_from_runtime(array $task): array
{
    $step = workflow_task_runtime_step($task);

    return complete_workflow_task_with_commands($task, $step['commands']);
}

function command_type_count(array $commands, string $type): int
{
    $count = 0;
    foreach ($commands as $command) {
        if (is_array($command) && ($command['type'] ?? null) === $type) {
            ++$count;
        }
    }

    return $count;
}

function command_types(array $commands): array
{
    $types = [];
    foreach ($commands as $command) {
        if (is_array($command) && is_string($command['type'] ?? null)) {
            $types[] = $command['type'];
        }
    }

    return $types;
}

function history_event_identity(array $event): int|string|null
{
    foreach (['event_id', 'eventId', 'history_event_id', 'historyEventId', 'id', 'sequence'] as $field) {
        $value = $event[$field] ?? null;
        if (is_int($value) || is_string($value)) {
            return $value;
        }
    }

    return null;
}

function history_event_id_list(array $events): array
{
    $ids = [];
    foreach ($events as $event) {
        if (! is_array($event)) {
            continue;
        }

        $id = history_event_identity($event);
        if ($id !== null) {
            $ids[] = $id;
        }
    }

    return $ids;
}

function history_event_type_list(array $events): array
{
    $types = [];
    foreach ($events as $event) {
        if (! is_array($event)) {
            continue;
        }

        $type = $event['event_type'] ?? ($event['type'] ?? null);
        if (is_string($type)) {
            $types[] = $type;
        }
    }

    return $types;
}

function resume_timer_id_from_task(array $task): ?string
{
    foreach (['timer_id', 'resume_source_id', 'open_wait_id'] as $field) {
        $value = $task[$field] ?? null;
        if (is_string($value) && $value !== '') {
            return str_starts_with($value, 'timer:') ? substr($value, 6) : $value;
        }
    }

    $events = array_reverse(history_events($task));
    foreach ($events as $event) {
        if (! is_array($event)) {
            continue;
        }
        $type = $event['event_type'] ?? ($event['type'] ?? null);
        if ($type !== HistoryEventType::TimerFired->value) {
            continue;
        }
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
        $timerId = $payload['timer_id'] ?? null;
        if (is_string($timerId) && $timerId !== '') {
            return $timerId;
        }
    }

    return null;
}

function register_worker(string $workerId): void
{
    request_json('POST', '/worker/register', DirectConformanceWorkerProtocol::registration(
        $workerId,
        TIMERS_TASK_QUEUE,
        'php',
        'durable-workflow/server:published-artifact',
        [NORMAL_SLEEP_WORKFLOW_TYPE],
        [],
        attributes: ['process_metrics' => [
            'memory_bytes' => memory_get_usage(true),
            'process_uptime_seconds' => 0,
            'process_id' => getmypid() ?: 0,
            'host' => gethostname() ?: 'published-server-container',
            'process_started_at' => now_iso(),
        ]],
    ));
}

function poll_workflow_task(string $workerId): array
{
    $response = request_json('POST', '/worker/workflow-tasks/poll', [
        'worker_id' => $workerId,
        'task_queue' => TIMERS_TASK_QUEUE,
    ]);
    $task = $response['task'] ?? null;
    if (! is_array($task)) {
        throw new RuntimeException('expected workflow task but poll returned '.json_encode($response));
    }

    return $task;
}

function wait_until_timestamp(DateTimeInterface $timestamp): void
{
    $deadlineMs = ((int) $timestamp->format('U')) * 1000 + (int) floor(((int) $timestamp->format('u')) / 1000);
    $nowMs = (int) floor(microtime(true) * 1000);
    $sleepMs = max(0, $deadlineMs - $nowMs + 250);
    if ($sleepMs > 0) {
        usleep($sleepMs * 1000);
    }
}

function run_timer_through_database_queue(WorkflowTask $timerTask): array
{
    $connection = config('queue.default');
    if ($connection !== 'database') {
        throw new RuntimeException('focused timer queue proof requires queue.default=database');
    }

    $table = config('queue.connections.database.table', 'jobs');
    $queue = config('queue.connections.database.queue', 'default');
    if (! is_string($table) || trim($table) === '') {
        throw new RuntimeException('database queue table is not configured');
    }
    if (! is_string($queue) || trim($queue) === '') {
        $queue = 'default';
    }

    $table = trim($table);
    $queue = trim($queue);
    $taskId = (string) $timerTask->id;
    $queuedJob = DB::table($table)
        ->where('payload', 'like', '%'.$taskId.'%')
        ->orderBy('id')
        ->first();

    if ($queuedJob === null) {
        throw new RuntimeException('ServiceModeTimerDispatcher did not persist timer task '.$taskId.' in the configured database queue');
    }

    $jobId = $queuedJob->id ?? null;
    $availableAt = $queuedJob->available_at ?? null;
    $exitCode = Artisan::call('queue:work', [
        'connection' => $connection,
        '--once' => true,
        '--queue' => $queue,
        '--sleep' => 0,
        '--tries' => 1,
    ]);

    if ($exitCode !== 0) {
        throw new RuntimeException(sprintf(
            'published image queue worker failed for timer task %s with exit code %d: %s',
            $taskId,
            $exitCode,
            trim(Artisan::output()),
        ));
    }

    $jobRemoved = $jobId !== null && ! DB::table($table)->where('id', $jobId)->exists();
    if (! $jobRemoved) {
        throw new RuntimeException('published image queue worker did not consume database queue job for timer task '.$taskId);
    }

    $timerTask->refresh();

    return [
        'connection' => $connection,
        'driver' => 'database',
        'table' => $table,
        'queue' => $queue,
        'queued_job_observed' => true,
        'queued_job_id' => $jobId,
        'queued_job_available_at' => $availableAt,
        'queue_worker_command' => 'php artisan queue:work database --once --queue='.$queue.' --sleep=0 --tries=1',
        'queue_worker_exit_code' => $exitCode,
        'queued_job_consumed' => $jobRemoved,
        'timer_task_status_after_worker' => $timerTask->status instanceof BackedEnum
            ? $timerTask->status->value
            : (string) $timerTask->status,
    ];
}

function history_event_time(string $runId, HistoryEventType $type): ?string
{
    $event = WorkflowHistoryEvent::query()
        ->where('workflow_run_id', $runId)
        ->where('event_type', $type->value)
        ->orderBy('sequence')
        ->first();

    return $event instanceof WorkflowHistoryEvent ? timestamp_iso($event->recorded_at) : null;
}

/**
 * @return array{
 *   server_restart_window: array<string, mixed>,
 *   timer_state_recovered: bool,
 *   timer: WorkflowTimer,
 *   timer_task: WorkflowTask
 * }
 */
function restart_server_application(string $repoRoot, WorkflowTimer $timer, WorkflowTask $timerTask): array
{
    $startedAt = now_iso();
    DB::disconnect();
    reset_http_kernel();
    Facade::clearResolvedInstances();

    bootstrap_application($repoRoot, false);

    /** @var WorkflowTimer|null $recoveredTimer */
    $recoveredTimer = WorkflowTimer::query()->find($timer->id);
    /** @var WorkflowTask|null $recoveredTimerTask */
    $recoveredTimerTask = WorkflowTask::query()->find($timerTask->id);
    $finishedAt = now_iso();

    if (! $recoveredTimer instanceof WorkflowTimer || ! $recoveredTimer->fire_at instanceof DateTimeInterface) {
        throw new RuntimeException('server restart did not recover the pending timer row');
    }

    if (! $recoveredTimerTask instanceof WorkflowTask) {
        throw new RuntimeException('server restart did not recover the pending timer task row');
    }

    $sameTimerDeadline = timestamp_iso($recoveredTimer->fire_at) === timestamp_iso($timer->fire_at);
    $timerStateRecovered = $sameTimerDeadline
        && (string) $recoveredTimer->workflow_run_id === (string) $timer->workflow_run_id
        && (string) $recoveredTimerTask->workflow_run_id === (string) $timerTask->workflow_run_id;

    return [
        'server_restart_window' => [
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'restart_type' => 'fresh_laravel_application_boot',
            'timer_id_before_restart' => (string) $timer->id,
            'timer_id_after_restart' => (string) $recoveredTimer->id,
            'timer_task_id_before_restart' => (string) $timerTask->id,
            'timer_task_id_after_restart' => (string) $recoveredTimerTask->id,
        ],
        'timer_state_recovered' => $timerStateRecovered,
        'timer' => $recoveredTimer,
        'timer_task' => $recoveredTimerTask,
    ];
}

function timer_fire_events(string $runId, string $timerId): array
{
    return WorkflowHistoryEvent::query()
        ->where('workflow_run_id', $runId)
        ->where('event_type', HistoryEventType::TimerFired->value)
        ->orderBy('sequence')
        ->get()
        ->filter(static fn (WorkflowHistoryEvent $event): bool => (string) ($event->payload['timer_id'] ?? '') === $timerId)
        ->values()
        ->all();
}

function run_sleep_probe(
    string $scenarioId,
    string $workflowPrefix,
    string $businessKey,
    bool $restartWorker,
    bool $restartServer = false,
): array
{
    $suffix = bin2hex(random_bytes(4));
    $workflowId = $workflowPrefix.'-'.$suffix;
    $initialWorkerId = $workflowPrefix.'-worker-a-'.$suffix;
    $resumeWorkerId = $restartWorker ? $workflowPrefix.'-worker-b-'.$suffix : $initialWorkerId;
    $sleepSeconds = $restartServer ? 4 : 2;

    register_worker($initialWorkerId);

    $start = request_json('POST', '/workflows', [
        'workflow_id' => $workflowId,
        'workflow_type' => NORMAL_SLEEP_WORKFLOW_TYPE,
        'task_queue' => TIMERS_TASK_QUEUE,
        'input' => [[
            'sleep_seconds' => $sleepSeconds,
        ]],
        'business_key' => $businessKey,
    ]);
    if (($start['command_status'] ?? null) !== 'accepted') {
        throw new RuntimeException('workflow start was not accepted: '.json_encode($start));
    }

    $runId = is_string($start['run_id'] ?? null) ? $start['run_id'] : '';
    if ($runId === '') {
        throw new RuntimeException('workflow start did not return a run_id');
    }

    $firstTask = poll_workflow_task($initialWorkerId);
    complete_workflow_task_from_runtime($firstTask);

    /** @var WorkflowTimer|null $timer */
    $timer = WorkflowTimer::query()
        ->where('workflow_run_id', $runId)
        ->orderBy('sequence')
        ->first();
    if (! $timer instanceof WorkflowTimer || ! $timer->fire_at instanceof DateTimeInterface) {
        throw new RuntimeException($scenarioId.' workflow did not persist a pending timer with fire_at');
    }

    /** @var WorkflowTask|null $timerTask */
    $timerTask = WorkflowTask::query()
        ->where('workflow_run_id', $runId)
        ->where('task_type', TaskType::Timer->value)
        ->orderBy('available_at')
        ->first();
    if (! $timerTask instanceof WorkflowTask) {
        throw new RuntimeException($scenarioId.' workflow did not persist a timer task');
    }

    $preWakeObservedAt = now_iso();
    /** @var WorkflowRun $preWakeRun */
    $preWakeRun = WorkflowRun::query()->findOrFail($runId);
    $preWakeStatus = run_status_value($preWakeRun->status);
    $earlyResumeObserved = in_array($preWakeStatus, [RunStatus::Completed->value, RunStatus::Failed->value], true);
    $restartWindow = null;
    $serverRestartWindow = null;
    $timerStateRecovered = null;

    if ($restartServer) {
        $restart = restart_server_application(getenv('RUNNER_REPO_ROOT') ?: '/app', $timer, $timerTask);
        $serverRestartWindow = $restart['server_restart_window'];
        $timerStateRecovered = $restart['timer_state_recovered'];
        $timer = $restart['timer'];
        $timerTask = $restart['timer_task'];
        register_worker($resumeWorkerId);
    }

    if ($restartWorker) {
        $restartStartedAt = now_iso();
        register_worker($resumeWorkerId);
        $restartFinishedAt = now_iso();
        $restartWindow = [
            'started_at' => $restartStartedAt,
            'finished_at' => $restartFinishedAt,
            'initial_worker_id' => $initialWorkerId,
            'restarted_worker_id' => $resumeWorkerId,
        ];
    }

    wait_until_timestamp($timer->fire_at);
    $queueTransport = run_timer_through_database_queue($timerTask);

    $resumeTask = poll_workflow_task($resumeWorkerId);
    complete_workflow_task_from_runtime($resumeTask);

    /** @var WorkflowRun $run */
    $run = WorkflowRun::query()->findOrFail($runId);
    $completedAt = timestamp_iso($run->closed_at)
        ?? history_event_time($runId, HistoryEventType::WorkflowCompleted)
        ?? now_iso();
    $wakeUpAt = timestamp_iso($timer->fire_at);
    $sleepRequestedAt = history_event_time($runId, HistoryEventType::TimerScheduled)
        ?? timestamp_iso($timer->created_at)
        ?? $preWakeObservedAt;
    $workflowResult = $run->workflowOutput();
    $completedEpoch = strtotime((string) $completedAt);
    $wakeEpoch = strtotime((string) $wakeUpAt);
    $runStatus = run_status_value($run->status);
    $completionAfterWakeUp = $completedEpoch !== false && $wakeEpoch !== false && $completedEpoch >= $wakeEpoch;
    $timerFireEvents = timer_fire_events($runId, (string) $timer->id);
    $timerFireCount = count($timerFireEvents);
    $lastTimerFireEvent = $timerFireEvents === [] ? null : $timerFireEvents[array_key_last($timerFireEvents)];
    $timerFiredAt = $lastTimerFireEvent instanceof WorkflowHistoryEvent
        ? (timestamp_iso($lastTimerFireEvent->payload['fired_at'] ?? null) ?? timestamp_iso($lastTimerFireEvent->recorded_at))
        : null;
    $resumeWorkerObserved = is_string($resumeTask['lease_owner'] ?? null)
        ? $resumeTask['lease_owner']
        : $resumeWorkerId;
    $resumedByExpectedWorker = $resumeWorkerObserved === $resumeWorkerId;
    $duplicateResumeCount = max(0, $timerFireCount - 1);
    $basePasses = ! $earlyResumeObserved
        && $wakeEpoch !== false
        && $completedEpoch !== false
        && $completionAfterWakeUp
        && $runStatus === RunStatus::Completed->value;
    if ($restartServer) {
        $serverRestartFinishedAt = is_array($serverRestartWindow) ? (string) ($serverRestartWindow['finished_at'] ?? '') : '';
        $serverRestartFinishedEpoch = strtotime($serverRestartFinishedAt);
        $passes = $basePasses
            && $timerStateRecovered === true
            && $timerFireCount === 1
            && $duplicateResumeCount === 0
            && $serverRestartFinishedEpoch !== false
            && $wakeEpoch !== false
            && $serverRestartFinishedEpoch < $wakeEpoch;
    } elseif ($restartWorker) {
        $passes = $basePasses && $timerFireCount === 1 && $duplicateResumeCount === 0 && $resumedByExpectedWorker;
    } else {
        $passes = $basePasses;
    }

    $observedOutputs = [
        'workflow_id' => $workflowId,
        'run_id' => $runId,
        'timer_id' => $timer->id,
        'timer_task_id' => $timerTask->id,
        'requested_sleep_seconds' => $sleepSeconds,
        'sleep_requested_at' => $sleepRequestedAt,
        'sleep_started_at' => $sleepRequestedAt,
        'wake_up_at' => $wakeUpAt,
        'completed_at' => $completedAt,
        'workflow_result' => $workflowResult,
        'pre_wake_observation_at' => $preWakeObservedAt,
        'pre_wake_status' => $preWakeStatus,
        'early_resume_observed' => $earlyResumeObserved,
        'completion_after_wake_up' => $completionAfterWakeUp,
        'timer_fired_at' => $timerFiredAt,
        'timer_fire_count' => $timerFireCount,
        'queue_transport' => $queueTransport,
        'execution_source' => HOST_EVIDENCE_SOURCE,
        'no_local_product_source_checkout_pass_evidence' => true,
    ];

    if ($restartWorker) {
        $observedOutputs = array_merge($observedOutputs, [
            'worker_restart_window' => $restartWindow,
            'initial_worker_id' => $initialWorkerId,
            'resume_worker_id' => $resumeWorkerId,
            'observed_resume_worker_id' => $resumeWorkerObserved,
            'resumed_by_restarted_worker' => $resumedByExpectedWorker,
            'duplicate_resume_count' => $duplicateResumeCount,
            'timer_dropped_observed' => $timerFireCount < 1,
        ]);
    }

    if ($restartServer) {
        $observedOutputs = array_merge($observedOutputs, [
            'server_restart_window' => $serverRestartWindow,
            'timer_state_recovered' => $timerStateRecovered,
            'duplicate_resume_count' => $duplicateResumeCount,
            'timer_dropped_observed' => $timerFireCount < 1,
            'server_restart_finished_before_wake_up' => is_array($serverRestartWindow)
                && strtotime((string) ($serverRestartWindow['finished_at'] ?? '')) !== false
                && $wakeEpoch !== false
                && strtotime((string) ($serverRestartWindow['finished_at'] ?? '')) < $wakeEpoch,
        ]);
    }

    if ($passes) {
        return [
            'scenario_id' => $scenarioId,
            'status' => 'pass',
            'classification' => null,
            'observed_outputs' => $observedOutputs,
            'linked_findings' => [],
        ];
    }

    $message = sprintf(
        '%s completed with status=%s, early_resume_observed=%s, wake_up_at=%s, completed_at=%s, timer_fire_count=%d, duplicate_resume_count=%d, timer_state_recovered=%s',
        $scenarioId,
        $runStatus,
        $earlyResumeObserved ? 'true' : 'false',
        (string) $wakeUpAt,
        (string) $completedAt,
        $timerFireCount,
        $duplicateResumeCount,
        $timerStateRecovered === null ? 'n/a' : ($timerStateRecovered ? 'true' : 'false'),
    );
    $finding = finding_for_failure($scenarioId, $message);

    return [
        'scenario_id' => $scenarioId,
        'status' => 'fail',
        'classification' => 'product-gap',
        'observed_behavior' => $message,
        'observed_outputs' => array_merge($observedOutputs, [
            'failure' => $message,
        ]),
        'linked_findings' => [[
            'finding_id' => $finding['id'],
            'finding_type' => $finding['finding_type'],
            'classification' => $finding['classification'],
        ]],
        'finding' => $finding,
    ];
}

function run_replay_after_timer_fire_probe(): array
{
    $suffix = bin2hex(random_bytes(4));
    $workflowId = 'timers-replay-after-fire-'.$suffix;
    $initialWorkerId = 'timers-replay-after-fire-worker-a-'.$suffix;
    $replayWorkerId = 'timers-replay-after-fire-worker-b-'.$suffix;
    $sleepSeconds = 2;

    register_worker($initialWorkerId);

    $start = request_json('POST', '/workflows', [
        'workflow_id' => $workflowId,
        'workflow_type' => NORMAL_SLEEP_WORKFLOW_TYPE,
        'task_queue' => TIMERS_TASK_QUEUE,
        'input' => [[
            'sleep_seconds' => $sleepSeconds,
        ]],
        'business_key' => 'timers-conformance-replay-after-fire',
    ]);
    if (($start['command_status'] ?? null) !== 'accepted') {
        throw new RuntimeException('workflow start was not accepted: '.json_encode($start));
    }

    $runId = is_string($start['run_id'] ?? null) ? $start['run_id'] : '';
    if ($runId === '') {
        throw new RuntimeException('workflow start did not return a run_id');
    }

    $firstTask = poll_workflow_task($initialWorkerId);
    complete_workflow_task_from_runtime($firstTask);

    /** @var WorkflowTimer|null $timer */
    $timer = WorkflowTimer::query()
        ->where('workflow_run_id', $runId)
        ->orderBy('sequence')
        ->first();
    if (! $timer instanceof WorkflowTimer || ! $timer->fire_at instanceof DateTimeInterface) {
        throw new RuntimeException('replay_after_timer_fire workflow did not persist a pending timer with fire_at');
    }

    /** @var WorkflowTask|null $timerTask */
    $timerTask = WorkflowTask::query()
        ->where('workflow_run_id', $runId)
        ->where('task_type', TaskType::Timer->value)
        ->orderBy('available_at')
        ->first();
    if (! $timerTask instanceof WorkflowTask) {
        throw new RuntimeException('replay_after_timer_fire workflow did not persist a timer task');
    }

    wait_until_timestamp($timer->fire_at);
    $queueTransport = run_timer_through_database_queue($timerTask);

    $timerFireEvents = timer_fire_events($runId, (string) $timer->id);
    $lastTimerFireEvent = $timerFireEvents === [] ? null : $timerFireEvents[array_key_last($timerFireEvents)];
    $firedAt = $lastTimerFireEvent instanceof WorkflowHistoryEvent
        ? (timestamp_iso($lastTimerFireEvent->payload['fired_at'] ?? null) ?? timestamp_iso($lastTimerFireEvent->recorded_at))
        : null;
    if ($firedAt === null) {
        throw new RuntimeException('replay_after_timer_fire did not record a TimerFired event for the timer');
    }

    register_worker($replayWorkerId);
    $replayTask = poll_workflow_task($replayWorkerId);
    $replayHistory = history_events($replayTask);
    $replayedEventIds = history_event_id_list($replayHistory);
    $replayedEventTypes = history_event_type_list($replayHistory);
    $replayStartedAt = now_iso();
    $timerRowsBeforeReplay = WorkflowTimer::query()->where('workflow_run_id', $runId)->count();
    $timerScheduledEventsBeforeReplay = WorkflowHistoryEvent::query()
        ->where('workflow_run_id', $runId)
        ->where('event_type', HistoryEventType::TimerScheduled->value)
        ->count();

    $runtimeStep = workflow_task_runtime_step($replayTask);
    $commands = $runtimeStep['commands'];
    $duplicateTimerCommands = command_type_count($commands, 'start_timer');
    complete_workflow_task_with_commands($replayTask, $commands);

    /** @var WorkflowRun $run */
    $run = WorkflowRun::query()->findOrFail($runId);
    $timerRowsAfterReplay = WorkflowTimer::query()->where('workflow_run_id', $runId)->count();
    $timerScheduledEventsAfterReplay = WorkflowHistoryEvent::query()
        ->where('workflow_run_id', $runId)
        ->where('event_type', HistoryEventType::TimerScheduled->value)
        ->count();
    $runStatus = run_status_value($run->status);
    $workflowResult = $run->workflowOutput();
    $timerFireCount = count(timer_fire_events($runId, (string) $timer->id));
    $firedEpoch = strtotime($firedAt);
    $replayStartedEpoch = strtotime($replayStartedAt);
    $replayStartedAfterFire = $firedEpoch !== false
        && $replayStartedEpoch !== false
        && $replayStartedEpoch >= $firedEpoch;
    $duplicateTimerRows = max(0, $timerRowsAfterReplay - $timerRowsBeforeReplay);
    $duplicateTimerScheduledEvents = max(0, $timerScheduledEventsAfterReplay - $timerScheduledEventsBeforeReplay);
    $replayedTimerFire = in_array(HistoryEventType::TimerFired->value, $replayedEventTypes, true);

    $passes = $runStatus === RunStatus::Completed->value
        && $duplicateTimerCommands === 0
        && $duplicateTimerRows === 0
        && $duplicateTimerScheduledEvents === 0
        && $timerFireCount === 1
        && $replayStartedAfterFire
        && $replayedTimerFire
        && $replayedEventIds !== [];

    $observedOutputs = [
        'workflow_id' => $workflowId,
        'run_id' => $runId,
        'timer_id' => (string) $timer->id,
        'fired_at' => $firedAt,
        'replay_started_at' => $replayStartedAt,
        'replayed_event_ids' => $replayedEventIds,
        'replayed_event_types' => $replayedEventTypes,
        'replayed_timer_fire_event' => $replayedTimerFire,
        'replay_started_after_fire' => $replayStartedAfterFire,
        'replay_worker_id' => $replayWorkerId,
        'replay_command_types' => command_types($commands),
        'duplicate_timer_commands' => $duplicateTimerCommands,
        'timer_rows_before_replay' => $timerRowsBeforeReplay,
        'timer_rows_after_replay' => $timerRowsAfterReplay,
        'duplicate_timer_rows' => $duplicateTimerRows,
        'timer_scheduled_event_count_before_replay' => $timerScheduledEventsBeforeReplay,
        'timer_scheduled_event_count_after_replay' => $timerScheduledEventsAfterReplay,
        'duplicate_timer_scheduled_events' => $duplicateTimerScheduledEvents,
        'timer_fire_count' => $timerFireCount,
        'queue_transport' => $queueTransport,
        'workflow_status' => $runStatus,
        'workflow_result' => $workflowResult,
        'execution_source' => HOST_EVIDENCE_SOURCE,
        'no_local_product_source_checkout_pass_evidence' => true,
    ];

    if ($passes) {
        return [
            'scenario_id' => 'replay_after_timer_fire',
            'status' => 'pass',
            'classification' => null,
            'observed_outputs' => $observedOutputs,
            'linked_findings' => [],
        ];
    }

    $message = sprintf(
        'replay_after_timer_fire completed with status=%s, replay_started_after_fire=%s, replayed_timer_fire=%s, timer_fire_count=%d, duplicate_timer_commands=%d, duplicate_timer_rows=%d, duplicate_timer_scheduled_events=%d',
        $runStatus,
        $replayStartedAfterFire ? 'true' : 'false',
        $replayedTimerFire ? 'true' : 'false',
        $timerFireCount,
        $duplicateTimerCommands,
        $duplicateTimerRows,
        $duplicateTimerScheduledEvents,
    );
    $finding = finding_for_failure('replay_after_timer_fire', $message);

    return [
        'scenario_id' => 'replay_after_timer_fire',
        'status' => 'fail',
        'classification' => 'product-gap',
        'observed_behavior' => $message,
        'observed_outputs' => array_merge($observedOutputs, [
            'failure' => $message,
        ]),
        'linked_findings' => [[
            'finding_id' => $finding['id'],
            'finding_type' => $finding['finding_type'],
            'classification' => $finding['classification'],
        ]],
        'finding' => $finding,
    ];
}

function run_concurrent_timers_probe(): array
{
    $suffix = bin2hex(random_bytes(4));
    $workflowId = 'timers-concurrent-distinct-'.$suffix;
    $workerId = 'timers-concurrent-distinct-worker-'.$suffix;
    $declaredDelays = [1, 3, 5];

    register_worker($workerId);

    $start = request_json('POST', '/workflows', [
        'workflow_id' => $workflowId,
        'workflow_type' => NORMAL_SLEEP_WORKFLOW_TYPE,
        'task_queue' => TIMERS_TASK_QUEUE,
        'input' => [[
            'sleep_seconds' => 30,
            'probe' => 'concurrent_timers_distinct_deadlines',
        ]],
        'business_key' => 'timers-conformance-concurrent-distinct',
    ]);
    if (($start['command_status'] ?? null) !== 'accepted') {
        throw new RuntimeException('workflow start was not accepted: '.json_encode($start));
    }

    $runId = is_string($start['run_id'] ?? null) ? $start['run_id'] : '';
    if ($runId === '') {
        throw new RuntimeException('workflow start did not return a run_id');
    }

    $firstTask = poll_workflow_task($workerId);
    $commands = array_map(
        static fn (int $delaySeconds): array => [
            'type' => 'start_timer',
            'delay_seconds' => $delaySeconds,
        ],
        $declaredDelays,
    );
    $complete = complete_workflow_task_with_commands($firstTask, $commands);
    if (($complete['outcome'] ?? null) !== 'completed' || ($complete['run_status'] ?? null) !== RunStatus::Waiting->value) {
        throw new RuntimeException('concurrent_timers_distinct_deadlines initial timer scheduling did not leave run waiting: '.json_encode($complete));
    }

    $createdTaskIds = array_values(array_filter(
        $complete['created_task_ids'] ?? [],
        static fn (mixed $value): bool => is_string($value) && $value !== '',
    ));
    if (count($createdTaskIds) !== count($declaredDelays)) {
        throw new RuntimeException(sprintf(
            'concurrent_timers_distinct_deadlines expected %d timer tasks, got %d',
            count($declaredDelays),
            count($createdTaskIds),
        ));
    }

    $timerRecords = [];
    foreach ($createdTaskIds as $taskId) {
        /** @var WorkflowTask|null $timerTask */
        $timerTask = WorkflowTask::query()->find($taskId);
        if (! $timerTask instanceof WorkflowTask) {
            throw new RuntimeException('concurrent_timers_distinct_deadlines missing timer task '.$taskId);
        }

        $timerId = is_array($timerTask->payload) ? ($timerTask->payload['timer_id'] ?? null) : null;
        if (! is_string($timerId) || $timerId === '') {
            throw new RuntimeException('concurrent_timers_distinct_deadlines timer task did not include timer_id '.$taskId);
        }

        /** @var WorkflowTimer|null $timer */
        $timer = WorkflowTimer::query()->find($timerId);
        if (! $timer instanceof WorkflowTimer || ! $timer->fire_at instanceof DateTimeInterface) {
            throw new RuntimeException('concurrent_timers_distinct_deadlines timer row did not include fire_at '.$timerId);
        }

        $timerRecords[] = [
            'timer_id' => (string) $timer->id,
            'timer_task_id' => (string) $timerTask->id,
            'delay_seconds' => (int) $timer->delay_seconds,
            'wake_up_at' => timestamp_iso($timer->fire_at),
            'timer' => $timer,
            'timer_task' => $timerTask,
        ];
    }

    usort(
        $timerRecords,
        static fn (array $left, array $right): int => strtotime((string) $left['wake_up_at'])
            <=> strtotime((string) $right['wake_up_at']),
    );

    $wakeUpTimes = [];
    $timerTaskIds = [];
    $declaredDelaySeconds = [];
    foreach ($timerRecords as $record) {
        $wakeUpTimes[(string) $record['timer_id']] = (string) $record['wake_up_at'];
        $timerTaskIds[(string) $record['timer_id']] = (string) $record['timer_task_id'];
        $declaredDelaySeconds[(string) $record['timer_id']] = (int) $record['delay_seconds'];
    }
    $expectedResumeOrder = array_keys($wakeUpTimes);

    $preWakeObservedAt = now_iso();
    $preWakeWorkflowTaskCount = WorkflowTask::query()
        ->where('workflow_run_id', $runId)
        ->where('task_type', TaskType::Workflow->value)
        ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
        ->count();
    $preWakeTimerFireCount = WorkflowHistoryEvent::query()
        ->where('workflow_run_id', $runId)
        ->where('event_type', HistoryEventType::TimerFired->value)
        ->count();

    $observedResumeOrder = [];
    $firedAtTimes = [];
    $fireCounts = [];
    $queueTransports = [];
    $resumeTaskIds = [];
    $resumeTaskTimerIds = [];

    foreach ($timerRecords as $index => $record) {
        /** @var WorkflowTimer $timer */
        $timer = $record['timer'];
        /** @var WorkflowTask $timerTask */
        $timerTask = $record['timer_task'];
        $timerId = (string) $record['timer_id'];

        wait_until_timestamp($timer->fire_at);
        $queueTransports[$timerId] = run_timer_through_database_queue($timerTask);

        $timerFireEvents = timer_fire_events($runId, $timerId);
        $fireCounts[$timerId] = count($timerFireEvents);
        $lastTimerFireEvent = $timerFireEvents === [] ? null : $timerFireEvents[array_key_last($timerFireEvents)];
        $firedAtTimes[$timerId] = $lastTimerFireEvent instanceof WorkflowHistoryEvent
            ? (timestamp_iso($lastTimerFireEvent->payload['fired_at'] ?? null) ?? timestamp_iso($lastTimerFireEvent->recorded_at))
            : null;

        $resumeTask = poll_workflow_task($workerId);
        $resumeTimerId = resume_timer_id_from_task($resumeTask) ?? $timerId;
        $observedResumeOrder[] = $resumeTimerId;
        $resumeTaskTimerIds[(string) ($resumeTask['task_id'] ?? $index)] = $resumeTimerId;
        if (is_string($resumeTask['task_id'] ?? null)) {
            $resumeTaskIds[] = $resumeTask['task_id'];
        }

        $isLastTimer = $index === count($timerRecords) - 1;
        $resumeCommands = $isLastTimer
            ? [[
                'type' => 'complete_workflow',
                'result' => Avro::serialize([
                    'probe' => 'concurrent_timers_distinct_deadlines',
                    'observed_resume_order' => $observedResumeOrder,
                ]),
            ]]
            : [[
                'type' => 'record_side_effect',
                'result' => Avro::serialize([
                    'probe' => 'concurrent_timers_distinct_deadlines',
                    'timer_id' => $resumeTimerId,
                    'resume_index' => $index,
                ]),
            ]];
        $resumeComplete = complete_workflow_task_with_commands($resumeTask, $resumeCommands);
        if (($resumeComplete['outcome'] ?? null) !== 'completed') {
            throw new RuntimeException('concurrent_timers_distinct_deadlines resume completion was not accepted: '.json_encode($resumeComplete));
        }
    }

    /** @var WorkflowRun $run */
    $run = WorkflowRun::query()->findOrFail($runId);
    $runStatus = run_status_value($run->status);
    $completedAt = timestamp_iso($run->closed_at)
        ?? history_event_time($runId, HistoryEventType::WorkflowCompleted)
        ?? null;
    $workflowResult = $run->workflowOutput();
    $distinctWakeUpTimes = count(array_unique(array_values($wakeUpTimes))) === count($wakeUpTimes);
    $resumeOrderMatchesDeadlines = $observedResumeOrder === $expectedResumeOrder;
    $duplicateResumeCount = count($observedResumeOrder) - count(array_unique($observedResumeOrder));
    $duplicateFireObserved = false;
    $allFireCountsExactlyOne = true;
    $noEarlyFires = true;

    foreach ($wakeUpTimes as $timerId => $wakeUpAt) {
        if (($fireCounts[$timerId] ?? null) !== 1) {
            $allFireCountsExactlyOne = false;
        }
        if (($fireCounts[$timerId] ?? 0) > 1) {
            $duplicateFireObserved = true;
        }

        $wakeEpoch = strtotime($wakeUpAt);
        $firedEpoch = strtotime((string) ($firedAtTimes[$timerId] ?? ''));
        if ($wakeEpoch === false || $firedEpoch === false || $firedEpoch < $wakeEpoch) {
            $noEarlyFires = false;
        }
    }

    $earlyResumeObserved = $preWakeWorkflowTaskCount > 0 || $preWakeTimerFireCount > 0;
    $passes = count($timerRecords) >= 2
        && $distinctWakeUpTimes
        && ! $earlyResumeObserved
        && $resumeOrderMatchesDeadlines
        && $duplicateResumeCount === 0
        && ! $duplicateFireObserved
        && $allFireCountsExactlyOne
        && $noEarlyFires
        && $runStatus === RunStatus::Completed->value;

    $observedOutputs = [
        'workflow_id' => $workflowId,
        'run_id' => $runId,
        'timer_ids' => array_keys($wakeUpTimes),
        'timer_task_ids' => $timerTaskIds,
        'declared_delay_seconds' => $declaredDelaySeconds,
        'wake_up_times' => $wakeUpTimes,
        'expected_resume_order' => $expectedResumeOrder,
        'observed_resume_order' => $observedResumeOrder,
        'resume_task_ids' => $resumeTaskIds,
        'resume_task_timer_ids' => $resumeTaskTimerIds,
        'fired_at_times' => $firedAtTimes,
        'fire_counts' => $fireCounts,
        'queue_transports' => $queueTransports,
        'pre_wake_observation_at' => $preWakeObservedAt,
        'pre_wake_workflow_task_count' => $preWakeWorkflowTaskCount,
        'pre_wake_timer_fire_count' => $preWakeTimerFireCount,
        'early_resume_observed' => $earlyResumeObserved,
        'distinct_wake_up_times' => $distinctWakeUpTimes,
        'resume_order_matches_deadlines' => $resumeOrderMatchesDeadlines,
        'duplicate_resume_count' => $duplicateResumeCount,
        'duplicate_fire_observed' => $duplicateFireObserved,
        'no_early_fires' => $noEarlyFires,
        'all_fire_counts_exactly_one' => $allFireCountsExactlyOne,
        'workflow_status' => $runStatus,
        'completed_at' => $completedAt,
        'workflow_result' => $workflowResult,
        'execution_source' => HOST_EVIDENCE_SOURCE,
        'no_local_product_source_checkout_pass_evidence' => true,
    ];

    if ($passes) {
        return [
            'scenario_id' => 'concurrent_timers_distinct_deadlines',
            'status' => 'pass',
            'classification' => null,
            'observed_outputs' => $observedOutputs,
            'linked_findings' => [],
        ];
    }

    $message = sprintf(
        'concurrent_timers_distinct_deadlines completed with status=%s, distinct_wake_up_times=%s, early_resume_observed=%s, resume_order_matches_deadlines=%s, duplicate_resume_count=%d, duplicate_fire_observed=%s, all_fire_counts_exactly_one=%s, no_early_fires=%s',
        $runStatus,
        $distinctWakeUpTimes ? 'true' : 'false',
        $earlyResumeObserved ? 'true' : 'false',
        $resumeOrderMatchesDeadlines ? 'true' : 'false',
        $duplicateResumeCount,
        $duplicateFireObserved ? 'true' : 'false',
        $allFireCountsExactlyOne ? 'true' : 'false',
        $noEarlyFires ? 'true' : 'false',
    );
    $finding = finding_for_failure('concurrent_timers_distinct_deadlines', $message);

    return [
        'scenario_id' => 'concurrent_timers_distinct_deadlines',
        'status' => 'fail',
        'classification' => 'product-gap',
        'observed_behavior' => $message,
        'observed_outputs' => array_merge($observedOutputs, [
            'failure' => $message,
        ]),
        'linked_findings' => [[
            'finding_id' => $finding['id'],
            'finding_type' => $finding['finding_type'],
            'classification' => $finding['classification'],
        ]],
        'finding' => $finding,
    ];
}

function run_cancellation_while_waiting_probe(): array
{
    $suffix = bin2hex(random_bytes(4));
    $workflowId = 'timers-cancel-while-waiting-'.$suffix;
    $workerId = 'timers-cancel-while-waiting-worker-'.$suffix;
    $sleepSeconds = 30;

    register_worker($workerId);

    $start = request_json('POST', '/workflows', [
        'workflow_id' => $workflowId,
        'workflow_type' => NORMAL_SLEEP_WORKFLOW_TYPE,
        'task_queue' => TIMERS_TASK_QUEUE,
        'input' => [[
            'sleep_seconds' => $sleepSeconds,
        ]],
        'business_key' => 'timers-conformance-cancellation-while-waiting',
    ]);
    if (($start['command_status'] ?? null) !== 'accepted') {
        throw new RuntimeException('workflow start was not accepted: '.json_encode($start));
    }

    $runId = is_string($start['run_id'] ?? null) ? $start['run_id'] : '';
    if ($runId === '') {
        throw new RuntimeException('workflow start did not return a run_id');
    }

    $firstTask = poll_workflow_task($workerId);
    complete_workflow_task_from_runtime($firstTask);

    /** @var WorkflowTimer|null $timer */
    $timer = WorkflowTimer::query()
        ->where('workflow_run_id', $runId)
        ->orderBy('sequence')
        ->first();
    if (! $timer instanceof WorkflowTimer || ! $timer->fire_at instanceof DateTimeInterface) {
        throw new RuntimeException('cancellation_while_waiting workflow did not persist a pending timer with fire_at');
    }

    /** @var WorkflowTask|null $timerTask */
    $timerTask = WorkflowTask::query()
        ->where('workflow_run_id', $runId)
        ->where('task_type', TaskType::Timer->value)
        ->orderBy('available_at')
        ->first();
    if (! $timerTask instanceof WorkflowTask) {
        throw new RuntimeException('cancellation_while_waiting workflow did not persist a timer task');
    }

    /** @var WorkflowRun $preCancelRun */
    $preCancelRun = WorkflowRun::query()->findOrFail($runId);
    $preCancelStatus = run_status_value($preCancelRun->status);
    $wakeUpAt = timestamp_iso($timer->fire_at);
    $sleepRequestedAt = history_event_time($runId, HistoryEventType::TimerScheduled)
        ?? timestamp_iso($timer->created_at)
        ?? now_iso();
    $cancelRequestedAt = now_iso();
    $cancel = request_json('POST', '/workflows/'.rawurlencode($workflowId).'/cancel', [
        'reason' => 'timer conformance cancellation while waiting',
        'request_id' => 'timer-cancel-while-waiting-'.$suffix,
    ]);

    $cancelRequested = WorkflowHistoryEvent::query()
        ->where('workflow_run_id', $runId)
        ->where('event_type', HistoryEventType::CancelRequested->value)
        ->orderBy('sequence')
        ->first();
    $timerCancelled = WorkflowHistoryEvent::query()
        ->where('workflow_run_id', $runId)
        ->where('event_type', HistoryEventType::TimerCancelled->value)
        ->orderBy('sequence')
        ->first();
    $workflowCancelled = WorkflowHistoryEvent::query()
        ->where('workflow_run_id', $runId)
        ->where('event_type', HistoryEventType::WorkflowCancelled->value)
        ->orderBy('sequence')
        ->first();

    $cancellationRequestedAt = $cancelRequested instanceof WorkflowHistoryEvent
        ? timestamp_iso($cancelRequested->recorded_at)
        : $cancelRequestedAt;
    $timerCancelledAt = $timerCancelled instanceof WorkflowHistoryEvent
        ? (timestamp_iso($timerCancelled->payload['cancelled_at'] ?? null) ?? timestamp_iso($timerCancelled->recorded_at))
        : null;
    $workflowCancelledAt = $workflowCancelled instanceof WorkflowHistoryEvent
        ? timestamp_iso($workflowCancelled->recorded_at)
        : null;

    (new RunTimerTask($timerTask->id))->handle();

    /** @var WorkflowRun $run */
    $run = WorkflowRun::query()->findOrFail($runId);
    /** @var WorkflowTimer $timer */
    $timer = WorkflowTimer::query()->findOrFail($timer->id);
    /** @var WorkflowTask $timerTask */
    $timerTask = WorkflowTask::query()->findOrFail($timerTask->id);

    $runStatus = run_status_value($run->status);
    $cancelEpoch = strtotime((string) $cancellationRequestedAt);
    $wakeEpoch = strtotime((string) $wakeUpAt);
    $cancelledBeforeWakeUp = $cancelEpoch !== false && $wakeEpoch !== false && $cancelEpoch < $wakeEpoch;
    $timerFireEvents = timer_fire_events($runId, (string) $timer->id);
    $timerFireCount = count($timerFireEvents);
    $firedAfterCancel = false;
    foreach ($timerFireEvents as $event) {
        if (! $event instanceof WorkflowHistoryEvent) {
            continue;
        }

        $firedAt = timestamp_iso($event->payload['fired_at'] ?? null) ?? timestamp_iso($event->recorded_at);
        $firedEpoch = strtotime((string) $firedAt);
        if ($cancelEpoch !== false && $firedEpoch !== false && $firedEpoch >= $cancelEpoch) {
            $firedAfterCancel = true;
            break;
        }
    }

    $timerStatus = $timer->status instanceof BackedEnum ? $timer->status->value : (string) $timer->status;
    $timerTaskStatus = $timerTask->status instanceof BackedEnum ? $timerTask->status->value : (string) $timerTask->status;
    $cancelAccepted = ($cancel['command_status'] ?? null) === 'accepted'
        && ($cancel['outcome'] ?? null) === RunStatus::Cancelled->value;
    $passes = $cancelAccepted
        && $preCancelStatus === RunStatus::Waiting->value
        && $cancelledBeforeWakeUp
        && ! $firedAfterCancel
        && $runStatus === RunStatus::Cancelled->value
        && $timerStatus === TimerStatus::Cancelled->value
        && $timerTaskStatus === TaskStatus::Cancelled->value
        && $timerCancelled instanceof WorkflowHistoryEvent
        && $workflowCancelled instanceof WorkflowHistoryEvent;

    $observedOutputs = [
        'workflow_id' => $workflowId,
        'run_id' => $runId,
        'timer_id' => (string) $timer->id,
        'timer_task_id' => (string) $timerTask->id,
        'sleep_requested_at' => $sleepRequestedAt,
        'wake_up_at' => $wakeUpAt,
        'cancellation_requested_at' => $cancellationRequestedAt,
        'timer_cancelled_at' => $timerCancelledAt,
        'workflow_cancelled_at' => $workflowCancelledAt,
        'cancelled_before_wake_up' => $cancelledBeforeWakeUp,
        'fired_after_cancel' => $firedAfterCancel,
        'timer_fire_count_after_cancel' => $timerFireCount,
        'workflow_status' => $runStatus,
        'timer_status' => $timerStatus,
        'timer_task_status' => $timerTaskStatus,
        'pre_cancel_status' => $preCancelStatus,
        'cancel_command_status' => $cancel['command_status'] ?? null,
        'cancel_outcome' => $cancel['outcome'] ?? null,
        'cancelled_timer_task_attempted_at' => now_iso(),
        'timer_cancelled_event_recorded' => $timerCancelled instanceof WorkflowHistoryEvent,
        'workflow_cancelled_event_recorded' => $workflowCancelled instanceof WorkflowHistoryEvent,
        'execution_source' => HOST_EVIDENCE_SOURCE,
        'no_local_product_source_checkout_pass_evidence' => true,
    ];

    if ($passes) {
        return [
            'scenario_id' => 'cancellation_while_waiting',
            'status' => 'pass',
            'classification' => null,
            'observed_outputs' => $observedOutputs,
            'linked_findings' => [],
        ];
    }

    $message = sprintf(
        'cancellation_while_waiting completed with status=%s, pre_cancel_status=%s, cancelled_before_wake_up=%s, fired_after_cancel=%s, timer_fire_count_after_cancel=%d, timer_status=%s, timer_task_status=%s',
        $runStatus,
        $preCancelStatus,
        $cancelledBeforeWakeUp ? 'true' : 'false',
        $firedAfterCancel ? 'true' : 'false',
        $timerFireCount,
        $timerStatus,
        $timerTaskStatus,
    );
    $finding = finding_for_failure('cancellation_while_waiting', $message);

    return [
        'scenario_id' => 'cancellation_while_waiting',
        'status' => 'fail',
        'classification' => 'product-gap',
        'observed_behavior' => $message,
        'observed_outputs' => array_merge($observedOutputs, [
            'failure' => $message,
        ]),
        'linked_findings' => [[
            'finding_id' => $finding['id'],
            'finding_type' => $finding['finding_type'],
            'classification' => $finding['classification'],
        ]],
        'finding' => $finding,
    ];
}

function run_operator_visible_timer_waiting_state_probe(): array
{
    $suffix = bin2hex(random_bytes(4));
    $workflowId = 'timers-operator-visible-waiting-'.$suffix;
    $workerId = 'timers-operator-visible-waiting-worker-'.$suffix;
    $sleepSeconds = 30;

    register_worker($workerId);

    $start = request_json('POST', '/workflows', [
        'workflow_id' => $workflowId,
        'workflow_type' => NORMAL_SLEEP_WORKFLOW_TYPE,
        'task_queue' => TIMERS_TASK_QUEUE,
        'input' => [[
            'sleep_seconds' => $sleepSeconds,
        ]],
        'business_key' => 'timers-conformance-operator-visible-waiting-state',
    ]);
    if (($start['command_status'] ?? null) !== 'accepted') {
        throw new RuntimeException('workflow start was not accepted: '.json_encode($start));
    }

    $runId = is_string($start['run_id'] ?? null) ? $start['run_id'] : '';
    if ($runId === '') {
        throw new RuntimeException('workflow start did not return a run_id');
    }

    $firstTask = poll_workflow_task($workerId);
    complete_workflow_task_from_runtime($firstTask);

    /** @var WorkflowTimer|null $timer */
    $timer = WorkflowTimer::query()
        ->where('workflow_run_id', $runId)
        ->orderBy('sequence')
        ->first();
    if (! $timer instanceof WorkflowTimer || ! $timer->fire_at instanceof DateTimeInterface) {
        throw new RuntimeException('operator_visible_timer_waiting_state workflow did not persist a pending timer with fire_at');
    }

    /** @var WorkflowTask|null $timerTask */
    $timerTask = WorkflowTask::query()
        ->where('workflow_run_id', $runId)
        ->where('task_type', TaskType::Timer->value)
        ->orderBy('available_at')
        ->first();
    if (! $timerTask instanceof WorkflowTask) {
        throw new RuntimeException('operator_visible_timer_waiting_state workflow did not persist a timer task');
    }

    $observedAt = now_iso();
    $publicResponse = request_json('GET', '/workflows/'.rawurlencode($workflowId));
    $visibleStatus = is_string($publicResponse['status'] ?? null) ? $publicResponse['status'] : '';
    $surface = 'public_api';
    $wakeUpAt = timestamp_iso($timer->fire_at);
    $sleepRequestedAt = history_event_time($runId, HistoryEventType::TimerScheduled)
        ?? timestamp_iso($timer->created_at)
        ?? $observedAt;
    $observedEpoch = strtotime($observedAt);
    $wakeEpoch = strtotime((string) $wakeUpAt);
    $observedBeforeWakeUp = $observedEpoch !== false && $wakeEpoch !== false && $observedEpoch < $wakeEpoch;
    $passes = $visibleStatus === RunStatus::Waiting->value
        && $observedBeforeWakeUp
        && ($publicResponse['workflow_id'] ?? null) === $workflowId
        && ($publicResponse['run_id'] ?? null) === $runId;

    $observedOutputs = [
        'workflow_id' => $workflowId,
        'run_id' => $runId,
        'timer_id' => (string) $timer->id,
        'timer_task_id' => (string) $timerTask->id,
        'sleep_requested_at' => $sleepRequestedAt,
        'wake_up_at' => $wakeUpAt,
        'observed_at' => $observedAt,
        'observed_before_wake_up' => $observedBeforeWakeUp,
        'status' => $visibleStatus,
        'surface' => $surface,
        'public_api_endpoint' => '/api/workflows/{workflowId}',
        'public_api_response' => [
            'workflow_id' => $publicResponse['workflow_id'] ?? null,
            'run_id' => $publicResponse['run_id'] ?? null,
            'status' => $publicResponse['status'] ?? null,
            'status_bucket' => $publicResponse['status_bucket'] ?? null,
            'wait_kind' => $publicResponse['wait_kind'] ?? null,
            'wait_reason' => $publicResponse['wait_reason'] ?? null,
            'is_terminal' => $publicResponse['is_terminal'] ?? null,
        ],
        'execution_source' => HOST_EVIDENCE_SOURCE,
        'no_local_product_source_checkout_pass_evidence' => true,
    ];

    if ($passes) {
        return [
            'scenario_id' => 'operator_visible_timer_waiting_state',
            'status' => 'pass',
            'classification' => null,
            'observed_outputs' => $observedOutputs,
            'linked_findings' => [],
        ];
    }

    $message = sprintf(
        'operator_visible_timer_waiting_state observed public_api status=%s, observed_before_wake_up=%s, workflow_id_matches=%s, run_id_matches=%s',
        $visibleStatus === '' ? 'missing' : $visibleStatus,
        $observedBeforeWakeUp ? 'true' : 'false',
        ($publicResponse['workflow_id'] ?? null) === $workflowId ? 'true' : 'false',
        ($publicResponse['run_id'] ?? null) === $runId ? 'true' : 'false',
    );
    $finding = finding_for_failure('operator_visible_timer_waiting_state', $message);

    return [
        'scenario_id' => 'operator_visible_timer_waiting_state',
        'status' => 'fail',
        'classification' => 'product-gap',
        'observed_behavior' => $message,
        'observed_outputs' => array_merge($observedOutputs, [
            'failure' => $message,
        ]),
        'linked_findings' => [[
            'finding_id' => $finding['id'],
            'finding_type' => $finding['finding_type'],
            'classification' => $finding['classification'],
        ]],
        'finding' => $finding,
    ];
}

try {
    bootstrap_application(getenv('RUNNER_REPO_ROOT') ?: '/app');

    $scenarios = [];
    $findings = [];
    foreach ([
        ['normal_sleep_completion', 'timers-normal-sleep', 'timers-conformance-normal-sleep', false, false],
        ['worker_restart_while_sleeping', 'timers-worker-restart', 'timers-conformance-worker-restart', true, false],
        ['server_restart_while_sleeping', 'timers-server-restart', 'timers-conformance-server-restart', false, true],
        ['replay_after_timer_fire', 'timers-replay-after-fire', 'timers-conformance-replay-after-fire', false, false],
        ['concurrent_timers_distinct_deadlines', 'timers-concurrent-distinct', 'timers-conformance-concurrent-distinct', false, false],
        ['cancellation_while_waiting', 'timers-cancel-while-waiting', 'timers-conformance-cancellation-while-waiting', false, false],
        ['operator_visible_timer_waiting_state', 'timers-operator-visible-waiting', 'timers-conformance-operator-visible-waiting-state', false, false],
    ] as [$scenarioId, $workflowPrefix, $businessKey, $restartWorker, $restartServer]) {
        try {
            $scenario = match ($scenarioId) {
                'replay_after_timer_fire' => run_replay_after_timer_fire_probe(),
                'concurrent_timers_distinct_deadlines' => run_concurrent_timers_probe(),
                'cancellation_while_waiting' => run_cancellation_while_waiting_probe(),
                'operator_visible_timer_waiting_state' => run_operator_visible_timer_waiting_state_probe(),
                default => run_sleep_probe($scenarioId, $workflowPrefix, $businessKey, $restartWorker, $restartServer),
            };
        } catch (Throwable $throwable) {
            $scenario = failure_scenario($scenarioId, $throwable);
        }

        if (isset($scenario['finding']) && is_array($scenario['finding'])) {
            $findings[] = $scenario['finding'];
        }
        unset($scenario['finding']);
        $scenarios[] = $scenario;
    }

    write_json_file(output_path(), evidence_document($scenarios, $findings));
} catch (Throwable $throwable) {
    $scenario = failure_scenario('normal_sleep_completion', $throwable);
    $finding = $scenario['finding'];
    unset($scenario['finding']);
    write_json_file(output_path(), evidence_document([$scenario], [$finding]));
}
PHP
}

if should_run_focused_timer_host_probe; then
  run_focused_timer_host_probe
fi

node "$script_dir/timers-published-artifacts.mjs" "$result_dir" "$(timestamp)" "$scenario_manifest" "$repo_root"
