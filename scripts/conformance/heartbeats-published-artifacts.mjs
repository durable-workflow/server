import fs from 'node:fs';
import crypto from 'node:crypto';
import net from 'node:net';
import os from 'node:os';
import path from 'node:path';
import process from 'node:process';
import { spawnSync } from 'node:child_process';
import {
  SERVER_CONTAINER_IMAGE_INSPECT_FORMAT,
  safeContainerInspectCommandRecord,
} from './heartbeat-container-inspect-evidence.mjs';
import {
  dockerObjectMissing,
  removeNamedDockerContainer,
} from './heartbeat-container-cleanup.mjs';
import { heartbeatCadenceObservation } from './heartbeat-cadence-observation.mjs';
import {
  cliControlPlaneTransportError,
  ControlPlaneHttpError,
  ControlPlaneTransportError,
  FinalVisibilityInvariantError,
  PersistentFinalVisibilityTransportError,
  persistentTransportEvidence,
  recoverFinalVisibility,
  transportErrorDetails,
} from './heartbeat-final-visibility.mjs';
import {
  PersistentPostStopDetailTransportError,
  PostStopDetailHttpError,
  persistentPostStopDetailEvidence,
  recoverPostStopWorkerDetail,
  semanticPostStopDetailEvidence,
} from './heartbeat-post-stop-detail.mjs';
import {
  prepareExactRustCrate,
  RustCratesIoPreparationTimeoutError,
} from './heartbeat-rust-preparation.mjs';
import {
  refineWorkerShutdownBoundary,
  staleTransitionEvidence,
  workerShutdownBoundary,
} from './heartbeat-stale-transition.mjs';
import {
  captureWorkProcessedBaseline,
  waitForWorkProcessedAdvance,
} from './heartbeat-worker-evidence.mjs';
import { isExactSemverRelease, samePythonRelease } from './version-identities.mjs';

const RESULT_DIR = mustEnv('RESULT_DIR');
const REPO_ROOT = mustEnv('REPO_ROOT');
const STARTED_AT = now();
const RUN_ID = `${Date.now()}-${Math.random().toString(16).slice(2, 10)}`;
const SUFFIX = RUN_ID.replace(/[^a-zA-Z0-9]/g, '').slice(-12).toLowerCase();
const CELL = env('DW_HEARTBEATS_CELL') || 'php';
const IS_PYTHON_CELL = CELL === 'python';
const IS_RUST_CELL = CELL === 'rust';
const NAMESPACE = env('DW_HEARTBEATS_NAMESPACE') || 'heartbeats-conformance';
const TASK_QUEUE = `hb-${CELL}-${SUFFIX}`;
const STALE_WORKER_ID = `heartbeat-${CELL}-stale-${SUFFIX}`;
const FRESH_WORKER_ID = `heartbeat-${CELL}-fresh-${SUFFIX}`;
const WORKFLOW_TYPE = `conformance.heartbeat.${CELL}`;
const TOKEN = env('DW_HEARTBEATS_AUTH_TOKEN') || 'dev-token';
const PHP_IMAGE = env('DW_HEARTBEATS_PHP_IMAGE') || 'composer:2';
const PYTHON_IMAGE = env('DW_HEARTBEATS_PYTHON_IMAGE') || 'python:3.12-slim';
const RUST_IMAGE = env('DW_HEARTBEATS_RUST_IMAGE') || 'rust:1.86.0-slim-bookworm';
const SERVER_VERSION = env('DW_SERVER_VERSION');
const CLI_VERSION = normalizeVersion(env('DW_CLI_VERSION'));
const SDK_PHP_VERSION = env('DW_PHP_SDK_VERSION');
const SDK_PYTHON_VERSION = env('DW_PYTHON_SDK_VERSION');
const SDK_RUST_VERSION = env('DW_RUST_SDK_VERSION');
const SERVER_IMAGE = env('DW_SERVER_IMAGE') || `durableworkflow/server:${SERVER_VERSION}`;
const SERVER_HOST = env('DW_HEARTBEATS_SERVER_HOST') || '127.0.0.1';
const SHARED_SERVER_STATE_FILE = env('DW_HEARTBEATS_SHARED_SERVER_STATE');
const USE_SHARED_SERVER = SHARED_SERVER_STATE_FILE !== '';
const HEARTBEAT_SECONDS = positiveInt(env('DW_HEARTBEATS_HEARTBEAT_SECONDS'), 2);
const CONFIGURED_STALE_AFTER_SECONDS = positiveInt(env('DW_HEARTBEATS_STALE_AFTER_SECONDS'), 7);
const POST_STOP_DETAIL_ATTEMPTS = positiveInt(env('DW_HEARTBEATS_POST_STOP_DETAIL_ATTEMPTS'), 3);
const POST_STOP_DETAIL_RETRY_MS = positiveInt(env('DW_HEARTBEATS_POST_STOP_DETAIL_RETRY_MS'), 1_000);
const FINAL_VISIBILITY_ATTEMPTS = positiveInt(env('DW_HEARTBEATS_FINAL_VISIBILITY_ATTEMPTS'), 3);
const FINAL_VISIBILITY_RETRY_MS = positiveInt(env('DW_HEARTBEATS_FINAL_VISIBILITY_RETRY_MS'), 1_000);
const WORK_PROCESSED_VISIBILITY_ATTEMPTS = positiveInt(
  env('DW_HEARTBEATS_WORK_PROCESSED_VISIBILITY_ATTEMPTS'),
  20,
);
const WORK_PROCESSED_VISIBILITY_RETRY_MS = positiveInt(
  env('DW_HEARTBEATS_WORK_PROCESSED_VISIBILITY_RETRY_MS'),
  250,
);
const RUST_PREPARATION_TIMEOUT_SECONDS = positiveInt(
  env('DW_HEARTBEATS_RUST_PREPARATION_TIMEOUT_SECONDS'),
  360,
);
const KEEP_RUN_ROOT = truthy(env('DW_HEARTBEATS_KEEP_RUN_ROOT'));
const HOST_UID = typeof process.getuid === 'function' ? process.getuid() : null;
const HOST_GID = typeof process.getgid === 'function' ? process.getgid() : null;
const CONTAINER_USER = `${HOST_UID}:${HOST_GID}`;
const RUN_ROOT = fs.mkdtempSync(path.join(RESULT_DIR, `${CELL}-heartbeat-run.`));
const PROJECT_DIR = path.join(
  RUN_ROOT,
  IS_PYTHON_CELL ? 'sdk-python' : (IS_RUST_CELL ? 'sdk-rust' : 'sdk-php'),
);
const COMPOSE_OVERRIDE = path.join(RUN_ROOT, 'docker-compose.heartbeat.yml');
const COMPOSE_FILE = path.join(REPO_ROOT, 'docker-compose.published.yml');
const DISTRIBUTION_IDENTITY_HELPER = path.join(REPO_ROOT, 'scripts/conformance/distribution_identities.py');
const DISTRIBUTION_IDENTITY_FILE = path.join(RESULT_DIR, 'executed-distribution-identities.json');
const SDK_ARTIFACT = IS_PYTHON_CELL ? 'sdk-python' : (IS_RUST_CELL ? 'sdk-rust' : 'sdk-php');
const SDK_ARTIFACT_VERSION = IS_PYTHON_CELL ? SDK_PYTHON_VERSION : (IS_RUST_CELL ? SDK_RUST_VERSION : SDK_PHP_VERSION);
const ARTIFACT_VERSIONS = {
  server: SERVER_VERSION,
  cli: CLI_VERSION,
  [SDK_ARTIFACT]: SDK_ARTIFACT_VERSION,
};
const ARTIFACT_SOURCES = {
  server: `docker://${SERVER_IMAGE}`,
  cli: 'github_release',
  [SDK_ARTIFACT]: IS_PYTHON_CELL
    ? `pypi://durable-workflow==${SDK_PYTHON_VERSION}`
    : (IS_RUST_CELL
      ? `crates.io://durable-workflow@${SDK_RUST_VERSION}`
      : `packagist://durable-workflow/sdk@${SDK_PHP_VERSION}`),
};
const SCENARIO_ID = `${CELL}_sdk_heartbeat_loop`;
const RUNTIME = SDK_ARTIFACT;
const EVIDENCE_FILE = `${CELL}-sdk-heartbeat-loop-evidence.json`;
const SEPARATE_UNCOVERED_CELLS = IS_PYTHON_CELL
  ? ['php_sdk_heartbeat_loop', 'rust_sdk_heartbeat_loop', 'waterline_worker_status_visibility']
  : (IS_RUST_CELL
    ? ['php_sdk_heartbeat_loop', 'python_sdk_heartbeat_loop', 'waterline_worker_status_visibility']
    : ['python_sdk_heartbeat_loop', 'rust_sdk_heartbeat_loop', 'waterline_worker_status_visibility']);

const cleanupCommands = [];
const workerContainers = new Set();
const requestCaptures = [];
const evidence = {
  schema: `durable-workflow.v2.heartbeat-runtime.${CELL}-sdk-loop-evidence`,
  version: 1,
  scenario_id: SCENARIO_ID,
  conformance_run_id: RUN_ID,
  started_at: STARTED_AT,
  finished_at: null,
  generated_at: null,
  outcome: 'runner_blocked',
  runner_blocked: true,
  artifact_versions: ARTIFACT_VERSIONS,
  executed_distribution_identities: {},
  artifact_sources: ARTIFACT_SOURCES,
  local_product_source_checkouts_used: false,
  separate_uncovered_cells: SEPARATE_UNCOVERED_CELLS,
  topology: {
    namespace: NAMESPACE,
    task_queue: TASK_QUEUE,
    stale_worker_id: STALE_WORKER_ID,
    fresh_worker_id: FRESH_WORKER_ID,
    workflow_type: WORKFLOW_TYPE,
    isolation: {
      namespace: NAMESPACE,
      task_queue: TASK_QUEUE,
      workflow_id_prefix: `hb-${CELL}-`,
      worker_id_prefix: `heartbeat-${CELL}-`,
    },
  },
  scenario_results: {},
  findings: [],
};

let publishedExecutionStarted = false;
let serverBaseUrl = '';
let cliBin = '';
let serverTopology = null;
let sharedServerNetwork = '';
let sharedServerContainerUrl = '';
let completedBehavior = null;
let postStopDetailContext = null;
let executionPhase = 'prerequisites';

function env(name) {
  return (process.env[name] ?? '').trim();
}

function mustEnv(name) {
  const value = env(name);
  if (!value) throw new Error(`${name} is required`);
  return value;
}

function now() {
  return new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
}

function preciseNow() {
  return new Date().toISOString();
}

function normalizeVersion(value) {
  return value.startsWith('v') ? value.slice(1) : value;
}

function truthy(value) {
  return ['1', 'true', 'yes', 'on'].includes(String(value).trim().toLowerCase());
}

function positiveInt(value, fallback) {
  const parsed = Number.parseInt(value, 10);
  return Number.isInteger(parsed) && parsed > 0 ? parsed : fallback;
}

function sleep(milliseconds) {
  return new Promise((resolve) => setTimeout(resolve, milliseconds));
}

function writeJson(fileName, value) {
  fs.writeFileSync(path.join(RESULT_DIR, fileName), `${JSON.stringify(value, null, 2)}\n`, 'utf8');
}

function log(message) {
  fs.appendFileSync(path.join(RESULT_DIR, `${CELL}-sdk-heartbeat-loop.log`), `[${now()}] ${message}\n`, 'utf8');
}

function commandExists(command) {
  const result = spawnSync('sh', ['-c', `command -v "$1" >/dev/null 2>&1`, 'sh', command]);
  return result.status === 0;
}

class CommandExecutionError extends Error {
  constructor(rendered, result, timeoutMilliseconds) {
    const timedOut = result.error?.code === 'ETIMEDOUT';
    const detail = timedOut
      ? `timed out after ${timeoutMilliseconds}ms`
      : (result.stderr || result.stdout || '').trim();
    super(`${rendered} failed (${result.status}): ${detail}`);
    this.name = 'CommandExecutionError';
    this.status = result.status;
    this.signal = result.signal;
    this.code = result.error?.code ?? null;
    this.timedOut = timedOut;
    this.timeoutMilliseconds = timeoutMilliseconds;
  }
}

class WorkerStopConfirmationError extends Error {
  constructor(workerId, detail) {
    super(`could not confirm stopped worker ${workerId}: ${detail}`);
    this.name = 'WorkerStopConfirmationError';
    this.workerId = workerId;
  }
}

function run(command, args, options = {}) {
  if (command === 'docker' && args[0] === 'run' && sharedServerNetwork) {
    args = [
      'run',
      '--network', sharedServerNetwork,
      ...args.slice(1),
    ];
  }
  const rendered = [command, ...args].join(' ');
  log(`command: ${rendered}`);
  const timeoutMilliseconds = options.timeout ?? 180_000;
  const result = spawnSync(command, args, {
    cwd: options.cwd ?? RUN_ROOT,
    env: options.env ?? process.env,
    encoding: 'utf8',
    maxBuffer: 20 * 1024 * 1024,
    timeout: timeoutMilliseconds,
  });
  const record = {
    command: [command, ...args],
    status: result.status,
    signal: result.signal,
    stdout: result.stdout ?? '',
    stderr: result.stderr ?? '',
  };
  if (options.captureFile) {
    const capturedRecord = typeof options.captureTransform === 'function'
      ? options.captureTransform(record)
      : record;
    writeJson(options.captureFile, capturedRecord);
  }
  if (!options.allowFailure && result.status !== 0) {
    throw new CommandExecutionError(rendered, result, timeoutMilliseconds);
  }
  return record;
}

function parseJsonOutput(text) {
  const trimmed = String(text ?? '').trim();
  if (!trimmed) return {};
  try {
    return JSON.parse(trimmed);
  } catch {
    const lines = trimmed.split(/\r?\n/).reverse();
    for (const line of lines) {
      try {
        return JSON.parse(line);
      } catch {
        // Keep looking for the final structured line after installer warnings.
      }
    }
  }
  return { raw_output: trimmed };
}

function parseCliVersionOutput(output) {
  const raw = String(output ?? '').trim();
  const match = raw.match(/(?:^|\s)v?(\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?)(?=$|\s|\))/);
  return match ? normalizeVersion(match[1]) : '';
}

function recordDistributionFile(component, version, artifact, artifactName = '') {
  const args = [
    DISTRIBUTION_IDENTITY_HELPER,
    'record-file',
    DISTRIBUTION_IDENTITY_FILE,
    component,
    version,
    artifact,
  ];
  if (artifactName) args.push('--artifact-name', artifactName);
  run('python3', args, { timeout: 60_000 });
  evidence.executed_distribution_identities = loadDistributionIdentities();
}

function recordUniqueDistributionFile(component, version, root, pattern, artifactName = '') {
  const args = [
    DISTRIBUTION_IDENTITY_HELPER,
    'record-unique',
    DISTRIBUTION_IDENTITY_FILE,
    component,
    version,
    root,
    pattern,
  ];
  if (artifactName) args.push('--artifact-name', artifactName);
  run('python3', args, { timeout: 60_000 });
  evidence.executed_distribution_identities = loadDistributionIdentities();
}

function recordDistributionDigest(component, version, artifactName, digest) {
  run('python3', [
    DISTRIBUTION_IDENTITY_HELPER,
    'record-digest',
    DISTRIBUTION_IDENTITY_FILE,
    component,
    version,
    artifactName,
    digest,
  ], { timeout: 60_000 });
  evidence.executed_distribution_identities = loadDistributionIdentities();
}

function loadDistributionIdentities() {
  if (!fs.existsSync(DISTRIBUTION_IDENTITY_FILE)) return {};
  return JSON.parse(fs.readFileSync(DISTRIBUTION_IDENTITY_FILE, 'utf8'));
}

function requireDistributionIdentities() {
  run('python3', [
    DISTRIBUTION_IDENTITY_HELPER,
    'validate',
    DISTRIBUTION_IDENTITY_FILE,
    'server',
    'cli',
    SDK_ARTIFACT,
  ], { timeout: 60_000 });
  evidence.executed_distribution_identities = loadDistributionIdentities();
}

function errorSummary(error) {
  return error instanceof Error ? error.message : String(error);
}

function cleanupWorkerContainer(containerName) {
  const initialInspect = run('docker', ['container', 'inspect', containerName], {
    allowFailure: true,
    timeout: 30_000,
  });
  if (dockerObjectMissing(initialInspect)) {
    return { resource: 'worker_container', name: containerName, status: 'already_absent' };
  }
  let logCapture = 'not_attempted';
  if (initialInspect.status === 0) {
    const containerLogs = run('docker', ['logs', containerName], { allowFailure: true, timeout: 30_000 });
    try {
      fs.writeFileSync(
        path.join(RESULT_DIR, `${containerName}.log`),
        `${containerLogs.stdout}${containerLogs.stderr}`,
        'utf8',
      );
      logCapture = containerLogs.status === 0 ? 'captured' : 'captured_with_docker_error';
    } catch (error) {
      logCapture = `write_failed: ${errorSummary(error)}`;
    }
  } else {
    logCapture = `initial_inspection_failed: ${(initialInspect.stderr || initialInspect.stdout).trim()}`;
  }
  const removal = removeNamedDockerContainer({
    containerName,
    initialInspection: initialInspect,
    inspect: () => run('docker', ['container', 'inspect', containerName], {
      allowFailure: true,
      timeout: 30_000,
    }),
    remove: () => run('docker', ['rm', '-f', containerName], {
      allowFailure: true,
      timeout: 30_000,
    }),
  });
  return {
    resource: 'worker_container',
    name: containerName,
    ...removal,
    log_capture: logCapture,
  };
}

function cleanupComposeProject(project, composeArgs, composeEnv) {
  const cleanupErrors = [];
  for (let attempt = 1; attempt <= 2; attempt += 1) {
    try {
      run('docker', [...composeArgs, 'down', '-v'], { env: composeEnv, timeout: 120_000 });
      break;
    } catch (error) {
      cleanupErrors.push(`down attempt ${attempt}: ${errorSummary(error)}`);
    }
  }

  try {
    const containers = run('docker', [...composeArgs, 'ps', '-aq'], { env: composeEnv, timeout: 30_000 });
    if (String(containers.stdout).trim()) {
      cleanupErrors.push(`compose project ${project} still has containers: ${String(containers.stdout).trim()}`);
    }
  } catch (error) {
    cleanupErrors.push(`container verification: ${errorSummary(error)}`);
  }
  try {
    const volumes = run('docker', [
      'volume', 'ls',
      '--filter', `label=com.docker.compose.project=${project}`,
      '--format', '{{.Name}}',
    ], { timeout: 30_000 });
    if (String(volumes.stdout).trim()) {
      cleanupErrors.push(`compose project ${project} still has volumes: ${String(volumes.stdout).trim()}`);
    }
  } catch (error) {
    cleanupErrors.push(`volume verification: ${errorSummary(error)}`);
  }
  if (cleanupErrors.length > 0) throw new Error(cleanupErrors.join('; '));
  return { resource: 'compose_project', name: project, status: 'removed_with_volumes' };
}

function ensureExactPins() {
  const failures = [];
  if (!['php', 'python', 'rust'].includes(CELL)) failures.push('DW_HEARTBEATS_CELL must be php, python, or rust');
  if (!isExactSemverRelease(SERVER_VERSION)) failures.push('DW_SERVER_VERSION must be an exact SemVer release');
  if (!/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/.test(CLI_VERSION)) failures.push('DW_CLI_VERSION must be an exact release');
  if (IS_PYTHON_CELL && !/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/.test(SDK_PYTHON_VERSION)) {
    failures.push('DW_PYTHON_SDK_VERSION must be an exact release');
  }
  if (IS_RUST_CELL && !/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/.test(SDK_RUST_VERSION)) {
    failures.push('DW_RUST_SDK_VERSION must be an exact release');
  }
  if (!IS_PYTHON_CELL && !IS_RUST_CELL && !/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/.test(SDK_PHP_VERSION)) {
    failures.push('DW_PHP_SDK_VERSION must be an exact released PHP SDK version');
  }
  const exactTag = new RegExp(`^(?:(?:docker\\.io|index\\.docker\\.io)/)?durableworkflow/server:${escapeRegex(SERVER_VERSION)}$`).test(SERVER_IMAGE);
  const exactDigest = /^(?:(?:docker\.io|index\.docker\.io)\/)?durableworkflow\/server(?::[^@]+)?@sha256:[0-9a-f]{64}$/i.test(SERVER_IMAGE);
  if (!exactTag && !exactDigest) {
    failures.push('DW_SERVER_IMAGE must be an exact durableworkflow/server tag matching DW_SERVER_VERSION or a digest pin');
  }
  if (!Number.isInteger(HOST_UID) || !Number.isInteger(HOST_GID)) {
    failures.push('the heartbeat runner requires a host UID and GID for mounted Docker writes');
  }
  if (USE_SHARED_SERVER && NAMESPACE === 'heartbeats-conformance') {
    failures.push('a shared heartbeat wave requires its prescribed cell namespace');
  }
  if (failures.length > 0) throw new Error(failures.join('; '));
}

function escapeRegex(value) {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

async function freePort() {
  const server = net.createServer();
  await new Promise((resolve, reject) => {
    server.once('error', reject);
    server.listen(0, '127.0.0.1', resolve);
  });
  const address = server.address();
  const port = typeof address === 'object' && address ? address.port : 0;
  await new Promise((resolve) => server.close(resolve));
  if (!port) throw new Error('could not allocate a server port');
  return port;
}

function workerBaseUrl(baseUrl) {
  if (USE_SHARED_SERVER && sharedServerContainerUrl) {
    return sharedServerContainerUrl;
  }
  const parsed = new URL(baseUrl);
  parsed.hostname = 'host.docker.internal';
  return parsed.toString().replace(/\/$/, '');
}

function pythonWorkerBaseUrl() {
  return workerBaseUrl(String(serverBaseUrl));
}

function controlPlaneHeaders() {
  return {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    Authorization: `Bearer ${TOKEN}`,
    'X-Namespace': NAMESPACE,
    'X-Durable-Workflow-Protocol-Version': '1.0',
    'X-Durable-Workflow-Control-Plane-Version': '2',
  };
}

async function api(pathName, query = {}) {
  const url = new URL(`/api${pathName}`, serverBaseUrl);
  for (const [key, value] of Object.entries(query)) url.searchParams.set(key, String(value));
  let response;
  let raw;
  try {
    response = await fetch(url, { headers: controlPlaneHeaders() });
    raw = await response.text();
  } catch (error) {
    const transportFailure = new ControlPlaneTransportError('GET', url, error, now());
    requestCaptures.push({
      timestamp: now(),
      method: 'GET',
      url: url.toString(),
      status: response?.status ?? null,
      transport_error: transportFailure.transport,
    });
    throw transportFailure;
  }
  const body = parseJsonOutput(raw);
  const capture = { timestamp: now(), method: 'GET', url: url.toString(), status: response.status, body };
  requestCaptures.push(capture);
  if (!response.ok) {
    throw new ControlPlaneHttpError('GET', url, response.status, body ?? raw, capture.timestamp);
  }
  return body;
}

async function ensureNamespace() {
  const url = new URL('/api/namespaces', serverBaseUrl);
  const response = await fetch(url, {
    method: 'POST',
    headers: controlPlaneHeaders(),
    body: JSON.stringify({
      name: NAMESPACE,
      description: `Published ${CELL} heartbeat-loop conformance namespace`,
      retention_days: 1,
    }),
  });
  const raw = await response.text();
  const body = parseJsonOutput(raw);
  requestCaptures.push({ timestamp: now(), method: 'POST', url: url.toString(), status: response.status, body });
  if (![201, 409].includes(response.status)) {
    throw new Error(`POST ${url} returned ${response.status}: ${raw}`);
  }
  evidence.namespace_setup = {
    status: response.status === 201 ? 'created' : 'already_exists',
    response: body,
  };
}

async function waitFor(label, callback, timeoutMs, intervalMs = 500) {
  const deadline = Date.now() + timeoutMs;
  let lastError = null;
  while (Date.now() < deadline) {
    try {
      const value = await callback();
      if (value) return value;
    } catch (error) {
      lastError = error;
    }
    await sleep(intervalMs);
  }
  throw new Error(`${label} did not become true within ${timeoutMs}ms${lastError ? `: ${lastError.message}` : ''}`);
}

function writePhpProject() {
  fs.mkdirSync(PROJECT_DIR, { recursive: true });
  writeProjectJson('composer.json', {
    require: { 'durable-workflow/sdk': SDK_PHP_VERSION },
    'minimum-stability': 'stable',
    'prefer-stable': true,
    config: {
      'preferred-install': 'dist',
      'sort-packages': true,
      'allow-plugins': { 'php-http/discovery': true },
    },
  });
  fs.writeFileSync(path.join(PROJECT_DIR, 'heartbeat-worker.php'), phpWorkerSource(), 'utf8');
  fs.writeFileSync(path.join(PROJECT_DIR, 'stale-poll.php'), phpStalePollSource(), 'utf8');
}

function writeProjectJson(fileName, value) {
  fs.writeFileSync(path.join(PROJECT_DIR, fileName), `${JSON.stringify(value, null, 2)}\n`, 'utf8');
}

function phpWorkerSource() {
  return `<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use Composer\\InstalledVersions;
use DurableWorkflow\\Client;
use DurableWorkflow\\Worker;
use DurableWorkflow\\Worker\\WorkflowContext;

function heartbeat_log(array $record, string $timestampField = 'observed_at'): void
{
    $record[$timestampField] ??= (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\\TH:i:s.v\\Z');
    fwrite(STDOUT, json_encode($record, JSON_UNESCAPED_SLASHES).PHP_EOL);
    fflush(STDOUT);
}

function heartbeat_log_tick(?array $result): void
{
    if (!is_array($result)) return;
    foreach (($result['worker_heartbeats'] ?? []) as $ack) {
        heartbeat_log(
            ['event' => 'worker_heartbeat', 'acknowledgement' => $ack],
            'acknowledgement_logged_at',
        );
    }
    if (($result['processed'] ?? false) === true) {
        heartbeat_log(['event' => 'work_processed', 'result' => $result]);
    }
}

if ($argc < 6) {
    fwrite(STDERR, "usage: heartbeat-worker.php <base-url> <namespace> <task-queue> <worker-id> <seconds>\\n");
    exit(2);
}

[$script, $baseUrl, $namespace, $taskQueue, $workerId, $seconds] = $argv;
$token = getenv('DURABLE_WORKFLOW_AUTH_TOKEN');
if (!is_string($token) || $token === '') {
    throw new RuntimeException('DURABLE_WORKFLOW_AUTH_TOKEN is required');
}
$sdkVersion = InstalledVersions::getPrettyVersion('durable-workflow/sdk') ?? 'unknown';
$client = new Client($baseUrl, token: $token, namespace: $namespace);
$registration = $client->registerWorker(
    $workerId,
    $taskQueue,
    ['${WORKFLOW_TYPE}'],
    [],
    ['query_tasks', 'workflow_updates', 'durable_history_replay', 'graceful_shutdown', 'sticky_execution'],
    maxConcurrentWorkflowTasks: 2,
);
heartbeat_log([
    'event' => 'worker_registered',
    'worker_id' => $workerId,
    'task_queue' => $taskQueue,
    'workflow_type' => '${WORKFLOW_TYPE}',
    'sdk_version' => $sdkVersion,
    'registration' => $registration,
]);

$worker = new Worker($client, $taskQueue, workerId: $workerId, heartbeatIntervalSeconds: 1);
$worker->registerWorkflow(
    '${WORKFLOW_TYPE}',
    static fn (WorkflowContext $context): array => ['completed' => true, 'runtime' => 'sdk-php'],
);
$deadline = time() + max(1, (int) $seconds);
$ticks = 0;
while (time() < $deadline) {
    $processed = $worker->tick(1);
    $ack = $client->heartbeatWorker($workerId, ['workflow_available' => 2, 'activity_available' => 1]);
    heartbeat_log(['event' => 'worker_heartbeat', 'acknowledgement' => $ack], 'acknowledgement_logged_at');
    if ($processed) heartbeat_log(['event' => 'work_processed']);
    ++$ticks;
}
heartbeat_log(['event' => 'worker_loop_stopped', 'summary' => ['ticks' => $ticks]]);
`;
}

function phpStalePollSource() {
  return `<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use DurableWorkflow\\Client;

if ($argc < 5) exit(2);
[$script, $baseUrl, $namespace, $taskQueue, $workerId] = $argv;
$token = getenv('DURABLE_WORKFLOW_AUTH_TOKEN');
if (!is_string($token) || $token === '') throw new RuntimeException('DURABLE_WORKFLOW_AUTH_TOKEN is required');
$client = new Client($baseUrl, token: $token, namespace: $namespace);
$poll = $client->pollWorkflowTaskResponse($workerId, $taskQueue, 0);
$task = isset($poll['task']) && is_array($poll['task']) ? $poll['task'] : null;
echo json_encode([
    'worker_id' => $workerId,
    'task_queue' => $taskQueue,
    'tasks' => $task === null ? [] : [$task],
    'poll' => $poll,
], JSON_UNESCAPED_SLASHES).PHP_EOL;
`;
}

function writePythonProject() {
  fs.mkdirSync(PROJECT_DIR, { recursive: true });
  fs.writeFileSync(path.join(PROJECT_DIR, 'heartbeat-worker.py'), pythonWorkerSource(), 'utf8');
  fs.writeFileSync(path.join(PROJECT_DIR, 'stale-poll.py'), pythonStalePollSource(), 'utf8');
}

function pythonWorkerSource() {
  return `from __future__ import annotations

import asyncio
import json
import os
import sys
from datetime import datetime, timezone
from importlib import metadata
from typing import Any

from durable_workflow import Client, Worker, workflow


def observed_at() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="milliseconds").replace("+00:00", "Z")


def emit(record: dict[str, Any]) -> None:
    record.setdefault("observed_at", observed_at())
    print(json.dumps(record, separators=(",", ":")), flush=True)


class EvidenceClient(Client):
    async def register_worker(self, **kwargs: Any) -> Any:
        acknowledgement = await super().register_worker(**kwargs)
        emit({
            "event": "worker_registered",
            "worker_id": kwargs.get("worker_id"),
            "task_queue": kwargs.get("task_queue"),
            "workflow_type": "${WORKFLOW_TYPE}",
            "sdk_version": metadata.version("durable-workflow"),
            "registration": acknowledgement,
        })
        return acknowledgement

    async def heartbeat_worker(self, **kwargs: Any) -> Any:
        acknowledgement = await super().heartbeat_worker(**kwargs)
        emit({
            "event": "worker_heartbeat",
            "worker_id": kwargs.get("worker_id"),
            "task_slots": kwargs.get("task_slots"),
            "process_metrics": kwargs.get("process_metrics"),
            "acknowledgement": acknowledgement,
        })
        return acknowledgement

    async def complete_workflow_task(self, **kwargs: Any) -> Any:
        acknowledgement = await super().complete_workflow_task(**kwargs)
        emit({
            "event": "work_processed",
            "task_id": kwargs.get("task_id"),
            "workflow_task_attempt": kwargs.get("workflow_task_attempt"),
            "acknowledgement": acknowledgement,
        })
        return acknowledgement


@workflow.defn(name="${WORKFLOW_TYPE}")
class PythonHeartbeatConformanceWorkflow:
    def run(self, ctx: Any) -> dict[str, Any]:
        return {"completed": True, "runtime": "sdk-python"}


async def main() -> None:
    if len(sys.argv) != 6:
        raise SystemExit("usage: heartbeat-worker.py <base-url> <namespace> <task-queue> <worker-id> <seconds>")
    base_url, namespace, task_queue, worker_id, seconds = sys.argv[1:]
    token = os.environ.get("DURABLE_WORKFLOW_AUTH_TOKEN", "")
    if not token:
        raise RuntimeError("DURABLE_WORKFLOW_AUTH_TOKEN is required")
    async with EvidenceClient(base_url, token=token, namespace=namespace, timeout=10.0) as client:
        worker = Worker(
            client,
            task_queue=task_queue,
            workflows=[PythonHeartbeatConformanceWorkflow],
            worker_id=worker_id,
            poll_timeout=1.0,
            max_concurrent_workflow_tasks=2,
            max_concurrent_activity_tasks=1,
            heartbeat_interval=60.0,
        )
        worker_task = asyncio.create_task(worker.run())
        try:
            await asyncio.sleep(max(1, int(seconds)))
        finally:
            await worker.stop()
            await worker_task
            emit({"event": "worker_loop_stopped", "worker_id": worker_id})


if __name__ == "__main__":
    asyncio.run(main())
`;
}

function pythonStalePollSource() {
  return `from __future__ import annotations

import asyncio
import json
import os
import sys

from durable_workflow import Client


async def main() -> None:
    if len(sys.argv) != 5:
        raise SystemExit("usage: stale-poll.py <base-url> <namespace> <task-queue> <worker-id>")
    base_url, namespace, task_queue, worker_id = sys.argv[1:]
    token = os.environ.get("DURABLE_WORKFLOW_AUTH_TOKEN", "")
    if not token:
        raise RuntimeError("DURABLE_WORKFLOW_AUTH_TOKEN is required")
    async with Client(base_url, token=token, namespace=namespace, timeout=10.0) as client:
        poll = await client.poll_workflow_task_response(
            worker_id=worker_id,
            task_queue=task_queue,
            timeout=0.0,
        )
    print(json.dumps({
        "worker_id": worker_id,
        "task_queue": task_queue,
        "tasks": [poll["task"]] if poll.get("task") is not None else [],
        "poll": poll,
    }, separators=(",", ":")))


if __name__ == "__main__":
    asyncio.run(main())
`;
}

function writeRustProject() {
  fs.mkdirSync(path.join(PROJECT_DIR, 'src', 'bin'), { recursive: true });
  fs.writeFileSync(path.join(PROJECT_DIR, 'Cargo.toml'), `[package]
name = "durable-workflow-heartbeat-probe"
version = "0.0.0"
edition = "2021"
publish = false

[dependencies]
durable-workflow = "=${SDK_RUST_VERSION}"
tokio = { version = "1", features = ["macros", "rt-multi-thread", "time"] }

[[bin]]
name = "heartbeat-worker"
path = "src/bin/heartbeat-worker.rs"

[[bin]]
name = "stale-poll"
path = "src/bin/stale-poll.rs"
`, 'utf8');
  fs.writeFileSync(
    path.join(PROJECT_DIR, 'src', 'bin', 'heartbeat-worker.rs'),
    rustWorkerSource(),
    'utf8',
  );
  fs.writeFileSync(
    path.join(PROJECT_DIR, 'src', 'bin', 'stale-poll.rs'),
    rustStalePollSource(),
    'utf8',
  );
}

function rustWorkerSource() {
  return `use std::{env, process, time::Duration};

use durable_workflow::{json, Client, Result, Value, Worker};

fn emit(record: Value) {
    println!("{record}");
}

#[tokio::main]
async fn main() -> Result<()> {
    let arguments: Vec<String> = env::args().collect();
    if arguments.len() != 6 {
        eprintln!("usage: heartbeat-worker <base-url> <namespace> <task-queue> <worker-id> <seconds>");
        process::exit(2);
    }
    let base_url = &arguments[1];
    let namespace = &arguments[2];
    let task_queue = &arguments[3];
    let worker_id = &arguments[4];
    let seconds = arguments[5].parse::<u64>().unwrap_or(600);
    let token = env::var("DURABLE_WORKFLOW_AUTH_TOKEN").unwrap_or_default();
    if token.is_empty() {
        eprintln!("DURABLE_WORKFLOW_AUTH_TOKEN is required");
        process::exit(2);
    }

    let client = Client::builder(base_url)
        .token(Some(token))
        .namespace(namespace)
        .timeout(Duration::from_secs(10))
        .build()?;
    let mut worker = Worker::new(client, task_queue)
        .worker_id(worker_id)
        .poll_timeout(Duration::from_secs(1))
        .max_concurrent_workflow_tasks(2)
        .max_concurrent_activity_tasks(1)
        .on_worker_heartbeat(|observation| {
            emit(json!({
                "event": "worker_heartbeat",
                "worker_id": observation.worker_id,
                "task_queue": observation.task_queue,
                "observed_at_unix_millis": observation.acknowledged_at_unix_millis,
                "acknowledgement": observation.acknowledgement,
            }));
        });
    worker.register_workflow("${WORKFLOW_TYPE}", |_context, _input| async move {
        emit(json!({
            "event": "work_processed",
            "workflow_type": "${WORKFLOW_TYPE}",
            "runtime": "sdk-rust",
        }));
        Ok(json!({"completed": true, "runtime": "sdk-rust"}))
    });

    let registration = worker.register().await?;
    emit(json!({
        "event": "worker_registered",
        "worker_id": registration.worker_id,
        "task_queue": task_queue,
        "workflow_type": "${WORKFLOW_TYPE}",
        "sdk_version": "${SDK_RUST_VERSION}",
        "registration": {
            "registered": registration.registered,
            "heartbeat_interval_seconds": registration.heartbeat_interval_seconds,
            "protocol_version": registration.protocol_version,
            "server_capabilities": registration.server_capabilities,
        },
    }));
    worker.run_until(tokio::time::sleep(Duration::from_secs(seconds))).await?;
    emit(json!({"event": "worker_loop_stopped", "worker_id": worker_id}));
    Ok(())
}
`;
}

function rustStalePollSource() {
  return `use std::{env, process, time::Duration};

use durable_workflow::{json, Client, Result};

#[tokio::main]
async fn main() -> Result<()> {
    let arguments: Vec<String> = env::args().collect();
    if arguments.len() != 5 {
        eprintln!("usage: stale-poll <base-url> <namespace> <task-queue> <worker-id>");
        process::exit(2);
    }
    let token = env::var("DURABLE_WORKFLOW_AUTH_TOKEN").unwrap_or_default();
    if token.is_empty() {
        eprintln!("DURABLE_WORKFLOW_AUTH_TOKEN is required");
        process::exit(2);
    }
    let client = Client::builder(&arguments[1])
        .token(Some(token))
        .namespace(&arguments[2])
        .timeout(Duration::from_secs(10))
        .build()?;
    let response = client
        .poll_workflow_task_response(&arguments[4], &arguments[3], Duration::from_secs(0))
        .await?;
    let tasks = if response.task.is_some() { vec!["claimed"] } else { Vec::new() };
    println!("{}", json!({
        "worker_id": arguments[4],
        "task_queue": arguments[3],
        "tasks": tasks,
        "poll": {
            "poll_status": response.poll_status,
            "reason": response.reason,
            "protocol_version": response.protocol_version,
            "server_capabilities": response.server_capabilities,
        },
    }));
    Ok(())
}
`;
}

async function startServer() {
  if (!commandExists('docker')) throw new Error('docker is required to start the pinned published server');
  if (USE_SHARED_SERVER) {
    await attachSharedServer();
    return;
  }
  if (!fs.existsSync(COMPOSE_FILE)) throw new Error(`published compose file not found: ${COMPOSE_FILE}`);
  const port = await freePort();
  const project = `dw-hb-${CELL}-${SUFFIX}`;
  fs.writeFileSync(COMPOSE_OVERRIDE, `services:
  server:
    environment:
      DW_WORKER_HEARTBEAT_INTERVAL_SECONDS: "${HEARTBEAT_SECONDS}"
      DW_WORKER_STALE_AFTER_SECONDS: "${CONFIGURED_STALE_AFTER_SECONDS}"
      DW_WORKER_POLL_TIMEOUT: "1"
`, 'utf8');
  const composeEnv = {
    ...process.env,
    SERVER_PORT: String(port),
    DW_SERVER_TAG: SERVER_VERSION,
    DW_SERVER_IMAGE: SERVER_IMAGE,
    DW_AUTH_TOKEN: TOKEN,
    DW_AUTH_BACKWARD_COMPATIBLE: 'true',
  };
  const composeArgs = ['compose', '-p', project, '-f', COMPOSE_FILE, '-f', COMPOSE_OVERRIDE];
  cleanupCommands.push(() => cleanupComposeProject(project, composeArgs, composeEnv));
  const pull = run('docker', ['pull', SERVER_IMAGE], {
    captureFile: 'server-image-pull.json',
    timeout: 300_000,
  });
  const imageInspect = run('docker', ['image', 'inspect', SERVER_IMAGE], {
    captureFile: 'server-image-inspect-command.json',
    timeout: 60_000,
  });
  const image = parseJsonOutput(imageInspect.stdout)?.[0];
  if (!image?.Id || !String(image.Id).startsWith('sha256:')) {
    throw new Error(`could not resolve the pulled server image id for ${SERVER_IMAGE}`);
  }
  const publicDigest = (image.RepoDigests ?? []).find((digest) =>
    /^(?:(?:docker\.io|index\.docker\.io)\/)?durableworkflow\/server@sha256:[0-9a-f]{64}$/i.test(String(digest)));
  if (!publicDigest) {
    throw new Error(`pulled server image ${SERVER_IMAGE} has no durableworkflow/server repository digest`);
  }
  const canonicalPublicDigest = String(publicDigest).replace(/^(?:docker\.io|index\.docker\.io)\//i, '');
  recordDistributionDigest(
    'server',
    SERVER_VERSION,
    'manifest',
    canonicalPublicDigest.slice(canonicalPublicDigest.indexOf('@') + 1),
  );
  const versionTagReference = `durableworkflow/server:${SERVER_VERSION}`;
  if (SERVER_IMAGE.includes('@sha256:')) {
    run('docker', ['pull', versionTagReference], {
      captureFile: 'server-version-tag-pull.json',
      timeout: 300_000,
    });
    const versionTagInspect = run('docker', ['image', 'inspect', versionTagReference], {
      captureFile: 'server-version-tag-inspect-command.json',
      timeout: 60_000,
    });
    const versionTagImage = parseJsonOutput(versionTagInspect.stdout)?.[0];
    if (versionTagImage?.Id !== image.Id) {
      throw new Error(`digest-pinned server image does not match public version tag ${versionTagReference}`);
    }
  }
  ARTIFACT_SOURCES.server = `docker://${canonicalPublicDigest}`;
  writeJson('server-image-inspect.json', image);

  run('docker', [...composeArgs, 'up', '-d', '--wait', 'server'], { env: composeEnv, timeout: 300_000 });
  const serverContainer = run('docker', [...composeArgs, 'ps', '-q', 'server'], {
    env: composeEnv,
    timeout: 30_000,
  });
  const containerId = String(serverContainer.stdout).trim();
  if (!containerId) throw new Error('compose did not report a running published server container');
  const containerInspect = run('docker', [
    'container',
    'inspect',
    '--format',
    SERVER_CONTAINER_IMAGE_INSPECT_FORMAT,
    containerId,
  ], {
    captureFile: 'server-container-inspect-command.json',
    captureTransform: safeContainerInspectCommandRecord,
    timeout: 60_000,
  });
  const container = parseJsonOutput(containerInspect.stdout);
  if (container?.Image !== image.Id || container?.Config?.Image !== SERVER_IMAGE) {
    throw new Error(`running server image mismatch: expected ${SERVER_IMAGE} (${image.Id}), got ${container?.Config?.Image ?? 'unknown'} (${container?.Image ?? 'unknown'})`);
  }
  evidence.server_image_install = {
    requested_reference: SERVER_IMAGE,
    public_version_tag: versionTagReference,
    resolved_public_digest: canonicalPublicDigest,
    resolved_image_id: image.Id,
    running_container_id: containerId,
    running_configured_reference: container.Config.Image,
    running_image_id: container.Image,
    pulled_stdout: String(pull.stdout).trim(),
    exact_published_image_verified: true,
  };
  serverBaseUrl = `http://${SERVER_HOST}:${port}`;
  evidence.server_endpoint = serverBaseUrl;
  serverTopology = {
    project,
    compose_args: composeArgs,
    compose_env: composeEnv,
    container_id: containerId,
    lifecycle_owner: 'focused-cell',
  };
  await waitFor('published server readiness', async () => {
    const response = await fetch(new URL('/api/ready', serverBaseUrl), { headers: controlPlaneHeaders() });
    return response.ok;
  }, 120_000, 1_000);
  const bootstrapContainer = run('docker', [...composeArgs, 'ps', '-a', '-q', 'bootstrap'], {
    env: composeEnv,
    timeout: 30_000,
  });
  const bootstrapContainerId = String(bootstrapContainer.stdout).trim();
  const bootstrapInspect = bootstrapContainerId
    ? run('docker', [
      'container', 'inspect', '--format',
      '{"Config":{"Cmd":{{json .Config.Cmd}}},"State":{{json .State}}}',
      bootstrapContainerId,
    ], { timeout: 30_000 })
    : null;
  const bootstrap = bootstrapInspect ? parseJsonOutput(bootstrapInspect.stdout) : null;
  if (bootstrap?.State?.Status !== 'exited'
    || bootstrap?.State?.ExitCode !== 0
    || !Array.isArray(bootstrap?.Config?.Cmd)
    || !bootstrap.Config.Cmd.includes('server-bootstrap')) {
    throw new Error('focused clean published-server bootstrap and migrations did not complete successfully');
  }
  evidence.server_bootstrap = {
    mode: 'focused_cell_clean_bootstrap',
    reused: false,
    lifecycle_owner: 'focused-cell',
    compose_project: project,
    bootstrap_container_id: bootstrapContainerId,
    configured_command: bootstrap.Config.Cmd,
    container_status: bootstrap.State.Status,
    exit_code: bootstrap.State.ExitCode,
    migrations_completed: true,
  };
}

async function attachSharedServer() {
  const statePath = path.resolve(SHARED_SERVER_STATE_FILE);
  if (!fs.existsSync(statePath)) {
    throw new Error(`shared heartbeat server state not found: ${statePath}`);
  }
  const stateBytes = fs.readFileSync(statePath);
  const state = JSON.parse(stateBytes.toString('utf8'));
  const isolation = state?.cell_isolation?.[CELL];
  const failures = [];
  if (state?.schema !== 'durable-workflow.v2.heartbeat-runtime.shared-server-bootstrap'
    || state?.version !== 1) {
    failures.push('shared heartbeat server state has an unsupported schema');
  }
  if (state?.server?.version !== SERVER_VERSION
    || state?.server?.requested_reference !== SERVER_IMAGE
    || state?.server?.exact_published_image_verified !== true) {
    failures.push('shared heartbeat server state does not bind the selected exact server image');
  }
  if (state?.clean_bootstrap?.status !== 'pass'
    || state?.clean_bootstrap?.migrations_completed !== true
    || state?.clean_bootstrap?.fresh_compose_project !== true) {
    failures.push('shared heartbeat server state does not prove clean bootstrap and migrations');
  }
  if (state?.lifecycle?.owner !== 'heartbeat-wave-runner'
    || state?.lifecycle?.cleanup_required !== true
    || state?.lifecycle?.cleanup_status !== 'pending') {
    failures.push('shared heartbeat server lifecycle is not owned by an active wave');
  }
  if (!state?.compose?.project
    || state?.compose?.network !== `${state.compose.project}_default`) {
    failures.push('shared heartbeat server state has no isolated compose network');
  }
  if (!['executor_network_attachment', 'published_loopback'].includes(
    state?.endpoint?.transport?.mode,
  )
    || state.endpoint.transport.compose_network !== state?.compose?.network
    || state?.endpoint?.container_url !== 'http://server:8080') {
    failures.push('shared heartbeat server state has no daemon-portable endpoint transport');
  }
  if (!isolation || isolation.namespace !== NAMESPACE
    || !TASK_QUEUE.startsWith(isolation.task_queue_prefix ?? '\0')
    || !STALE_WORKER_ID.startsWith(isolation.worker_id_prefix ?? '\0')
    || !FRESH_WORKER_ID.startsWith(isolation.worker_id_prefix ?? '\0')) {
    failures.push(`shared heartbeat server did not prescribe isolated ${CELL} cell identities`);
  }
  let endpoint = null;
  let hostControlEndpoint = null;
  try {
    endpoint = new URL(state?.endpoint?.host_url ?? '');
    if (!['127.0.0.1', 'localhost'].includes(endpoint.hostname)
      || endpoint.protocol !== 'http:'
      || endpoint.pathname !== '/') {
      failures.push('shared heartbeat server endpoint must be a loopback HTTP origin');
    }
  } catch {
    failures.push('shared heartbeat server endpoint is invalid');
  }
  try {
    hostControlEndpoint = new URL(state?.endpoint?.host_control_url ?? '');
    const executorMode =
      state?.endpoint?.transport?.mode === 'executor_network_attachment';
    if (hostControlEndpoint.protocol !== 'http:'
      || hostControlEndpoint.pathname !== '/'
      || (executorMode && hostControlEndpoint.origin !== 'http://server:8080')
      || (!executorMode && hostControlEndpoint.origin !== endpoint?.origin)) {
      failures.push('shared heartbeat host-control endpoint does not match its transport mode');
    }
  } catch {
    failures.push('shared heartbeat host-control endpoint is invalid');
  }
  if (failures.length > 0) throw new Error(failures.join('; '));

  const imageInspect = run('docker', ['image', 'inspect', SERVER_IMAGE], { timeout: 60_000 });
  const image = parseJsonOutput(imageInspect.stdout)?.[0];
  const containerInspect = run('docker', [
    'container',
    'inspect',
    '--format',
    SERVER_CONTAINER_IMAGE_INSPECT_FORMAT,
    state.server.running_container_id,
  ], {
    captureFile: 'shared-server-container-inspect-command.json',
    captureTransform: safeContainerInspectCommandRecord,
    timeout: 60_000,
  });
  const container = parseJsonOutput(containerInspect.stdout);
  if (image?.Id !== state.server.resolved_image_id
    || container?.Image !== state.server.resolved_image_id
    || container?.Config?.Image !== SERVER_IMAGE) {
    throw new Error('running shared server no longer matches its exact published-image receipt');
  }
  const publicDigest = String(state.server.resolved_public_digest ?? '');
  if (!/^durableworkflow\/server@sha256:[0-9a-f]{64}$/i.test(publicDigest)) {
    throw new Error('shared heartbeat server receipt has no canonical public image digest');
  }
  recordDistributionDigest(
    'server',
    SERVER_VERSION,
    'manifest',
    publicDigest.slice(publicDigest.indexOf('@') + 1),
  );
  ARTIFACT_SOURCES.server = `docker://${publicDigest}`;
  serverBaseUrl = hostControlEndpoint.origin;
  sharedServerNetwork = state.compose.network;
  sharedServerContainerUrl = state.endpoint.container_url;
  evidence.server_endpoint = serverBaseUrl;
  evidence.server_network_endpoint = sharedServerContainerUrl;
  evidence.server_image_install = {
    requested_reference: SERVER_IMAGE,
    public_version_tag: state.server.public_version_tag,
    resolved_public_digest: publicDigest,
    resolved_image_id: state.server.resolved_image_id,
    running_container_id: state.server.running_container_id,
    running_configured_reference: container.Config.Image,
    running_image_id: container.Image,
    exact_published_image_verified: true,
    shared_bootstrap_receipt_sha256: crypto.createHash('sha256').update(stateBytes).digest('hex'),
  };
  evidence.server_bootstrap = {
    ...state.clean_bootstrap,
    mode: 'shared_wave_clean_bootstrap',
    reused: true,
    wave_run_id: state.wave_run_id,
    lifecycle_owner: state.lifecycle.owner,
    cleanup_status_at_handoff: state.lifecycle.cleanup_status,
  };
  evidence.topology.shared_wave_run_id = state.wave_run_id;
  evidence.topology.isolation = {
    ...evidence.topology.isolation,
    prescribed_namespace: isolation.namespace,
    task_queue_prefix: isolation.task_queue_prefix,
    workflow_id_prefix: isolation.workflow_id_prefix,
    worker_id_prefix: isolation.worker_id_prefix,
  };
  serverTopology = {
    project: state.compose.project,
    network: sharedServerNetwork,
    container_id: state.server.running_container_id,
    lifecycle_owner: state.lifecycle.owner,
  };
  cleanupCommands.push(() => ({
    resource: 'shared_server',
    name: state.compose.project,
    status: 'retained_for_wave_cleanup',
    lifecycle_owner: state.lifecycle.owner,
  }));
  await waitFor('shared published server readiness', async () => {
    const response = await fetch(new URL('/api/ready', serverBaseUrl), { headers: controlPlaneHeaders() });
    return response.ok;
  }, 30_000, 500);
}

function installCli() {
  if (!commandExists('curl')) throw new Error('curl is required to install the pinned published CLI');
  const cliRoot = path.join(RUN_ROOT, 'cli');
  const binDir = path.join(cliRoot, 'bin');
  fs.mkdirSync(binDir, { recursive: true });
  const installer = path.join(cliRoot, 'install.sh');
  let sourceUrl = '';
  for (const tag of [CLI_VERSION, `v${CLI_VERSION}`]) {
    const url = `https://github.com/durable-workflow/cli/releases/download/${tag}/install.sh`;
    const result = run('curl', ['--fail', '--location', '--silent', '--show-error', url, '--output', installer], {
      allowFailure: true,
      timeout: 60_000,
    });
    if (result.status === 0) {
      sourceUrl = url;
      break;
    }
  }
  if (!sourceUrl) throw new Error(`could not download the official dw ${CLI_VERSION} installer`);
  recordDistributionFile('cli', CLI_VERSION, installer, 'install.sh');
  fs.chmodSync(installer, 0o755);
  run('sh', [installer], {
    env: {
      ...process.env,
      PATH: [binDir, process.env.PATH ?? ''].filter(Boolean).join(path.delimiter),
      VERSION: CLI_VERSION,
      DURABLE_WORKFLOW_INSTALL_DIR: binDir,
      DURABLE_WORKFLOW_INSTALL_VERIFY_ATTESTATIONS: '0',
    },
    timeout: 180_000,
  });
  cliBin = path.join(binDir, os.platform() === 'win32' ? 'dw.exe' : 'dw');
  const version = run(cliBin, ['--version'], { timeout: 30_000 });
  const versionOutput = String(version.stdout || version.stderr).trim();
  const installedVersion = parseCliVersionOutput(versionOutput);
  if (installedVersion !== CLI_VERSION) {
    throw new Error(`pinned CLI version mismatch: expected ${CLI_VERSION}, got ${versionOutput || 'empty'}`);
  }
  evidence.cli_version_output = versionOutput;
  ARTIFACT_SOURCES.cli = sourceUrl;
  evidence.cli_install = {
    version: CLI_VERSION,
    detected_version: installedVersion,
    source: ARTIFACT_SOURCES.cli,
    source_url: ARTIFACT_SOURCES.cli,
    binary: path.basename(cliBin),
  };
}

function installPhpPackage() {
  if (!commandExists('docker')) throw new Error('docker is required to install the pinned public PHP package');
  writePhpProject();
  run('docker', [
    'run', '--rm',
    '--user', CONTAINER_USER,
    '--env', 'COMPOSER_CACHE_DIR=/app/.composer-cache',
    '-v', `${PROJECT_DIR}:/app`,
    '-w', '/app',
    PHP_IMAGE,
    'install', '--no-interaction', '--no-progress', '--prefer-dist', '--no-scripts',
  ], { timeout: 600_000 });
  const version = run('docker', [
    'run', '--rm',
    '--user', CONTAINER_USER,
    '-v', `${PROJECT_DIR}:/app`,
    '-w', '/app',
    '--entrypoint', 'php',
    PHP_IMAGE,
    '-r', "require 'vendor/autoload.php'; echo Composer\\InstalledVersions::getPrettyVersion('durable-workflow/sdk') ?: '';",
  ], { timeout: 60_000 });
  const installed = String(version.stdout).trim();
  if (normalizeVersion(installed) !== SDK_PHP_VERSION) {
    throw new Error(`pinned PHP package mismatch: expected ${SDK_PHP_VERSION}, got ${installed || 'empty'}`);
  }
  recordUniqueDistributionFile(
    'sdk-php',
    SDK_PHP_VERSION,
    path.join(PROJECT_DIR, '.composer-cache', 'files', 'durable-workflow', 'sdk'),
    '**/*',
    'durable-workflow/sdk',
  );
  evidence.php_package_install = {
    package: 'durable-workflow/sdk',
    requested_version: SDK_PHP_VERSION,
    installed_version: installed,
    source: ARTIFACT_SOURCES['sdk-php'],
    installer_runtime: PHP_IMAGE,
    preferred_install: 'dist',
  };
}

function pythonProjectMount() {
  return `${PROJECT_DIR}:/app`;
}

function pythonContainerUser() {
  return CONTAINER_USER;
}

function pythonRuntimeArgs() {
  return [
    '--user', pythonContainerUser(),
    '--env', 'HOME=/tmp',
    '--env', 'PYTHONPATH=/app/site-packages',
    '-v', pythonProjectMount(),
    '-w', '/app',
  ];
}

function installPythonPackage() {
  if (!commandExists('docker')) throw new Error('docker is required to install the pinned public Python package');
  writePythonProject();
  run('docker', [
    'pull', PYTHON_IMAGE,
  ], { timeout: 300_000 });
  const distributionDir = path.join(PROJECT_DIR, 'distributions');
  fs.mkdirSync(distributionDir, { recursive: true });
  run('docker', [
    'run', '--rm',
    ...pythonRuntimeArgs(),
    PYTHON_IMAGE,
    'python', '-m', 'pip', 'download', '--disable-pip-version-check', '--no-deps',
    '--dest', '/app/distributions',
    `durable-workflow==${SDK_PYTHON_VERSION}`,
  ], { timeout: 600_000 });
  const distributions = fs.readdirSync(distributionDir).filter((name) => fs.statSync(path.join(distributionDir, name)).isFile());
  if (distributions.length !== 1) {
    throw new Error(`expected one downloaded Python SDK distribution, found ${distributions.length}`);
  }
  const distribution = distributions[0];
  recordDistributionFile('sdk-python', SDK_PYTHON_VERSION, path.join(distributionDir, distribution));
  run('docker', [
    'run', '--rm',
    ...pythonRuntimeArgs(),
    PYTHON_IMAGE,
    'python', '-m', 'pip', 'install', '--disable-pip-version-check', '--no-cache-dir',
    '--target', '/app/site-packages',
    `/app/distributions/${distribution}`,
  ], { timeout: 600_000 });
  const version = run('docker', [
    'run', '--rm',
    ...pythonRuntimeArgs(),
    PYTHON_IMAGE,
    'python', '-c', "from importlib.metadata import version; print(version('durable-workflow'))",
  ], { timeout: 60_000 });
  const installed = normalizeVersion(String(version.stdout).trim());
  if (!samePythonRelease(SDK_PYTHON_VERSION, installed)) {
    throw new Error(`pinned Python package mismatch: expected ${SDK_PYTHON_VERSION}, got ${installed || 'empty'}`);
  }
  evidence.python_package_install = {
    package: 'durable-workflow',
    requested_version: SDK_PYTHON_VERSION,
    installed_version: installed,
    source: ARTIFACT_SOURCES['sdk-python'],
    installer_runtime: PYTHON_IMAGE,
    install_mode: 'pip --target',
  };
}

function rustRuntimeArgs() {
  return [
    '--user', CONTAINER_USER,
    '--env', 'HOME=/tmp',
    '--env', 'CARGO_HOME=/app/.cargo-home',
    '--env', 'CARGO_REGISTRIES_CRATES_IO_PROTOCOL=sparse',
    '--env', 'CARGO_NET_RETRY=2',
    '--env', 'CARGO_HTTP_TIMEOUT=30',
    '-v', `${PROJECT_DIR}:/app`,
    '-w', '/app',
  ];
}

function runRustCargoPreparation(step) {
  const containerName = `dw-hb-rust-${step.phase}-${SUFFIX}`.slice(0, 63);
  workerContainers.add(containerName);
  return run('docker', [
    'run', '--rm', '--name', containerName,
    ...rustRuntimeArgs(),
    ...(step.networkAccess ? [] : ['--env', 'CARGO_NET_OFFLINE=true']),
    RUST_IMAGE,
    'cargo', ...step.cargoArguments,
  ], { timeout: step.timeoutMilliseconds });
}

function installRustPackage() {
  if (!commandExists('docker')) throw new Error('docker is required to install the pinned public Rust package');
  writeRustProject();
  run('docker', ['pull', RUST_IMAGE], { timeout: 300_000 });
  const preparationSteps = [
    {
      phase: 'lockfile_resolution',
      cargoArguments: ['generate-lockfile'],
      networkAccess: true,
    },
    {
      phase: 'crate_download',
      cargoArguments: ['fetch', '--locked'],
      networkAccess: true,
    },
    {
      phase: 'metadata',
      cargoArguments: ['metadata', '--locked', '--format-version=1'],
      networkAccess: false,
    },
    {
      phase: 'release_build',
      cargoArguments: ['build', '--release', '--locked'],
      networkAccess: false,
    },
  ];
  let preparation;
  try {
    preparation = prepareExactRustCrate({
      steps: preparationSteps,
      execute: runRustCargoPreparation,
      timeoutMilliseconds: RUST_PREPARATION_TIMEOUT_SECONDS * 1_000,
    });
  } catch (error) {
    if (/no matching package named|failed to select a version|not found in registry/i.test(errorSummary(error))) {
      publishedExecutionStarted = true;
    }
    throw error;
  }
  evidence.rust_crates_io_preparation = preparation.evidence;
  // The exact registry version resolved and all dependencies were downloaded.
  // The remaining behavioral execution is offline from crates.io.
  publishedExecutionStarted = true;
  const metadataResult = preparation.results.metadata;
  const metadata = parseJsonOutput(metadataResult.stdout);
  const installedPackage = Array.isArray(metadata.packages)
    ? metadata.packages.find((candidate) => candidate.name === 'durable-workflow' && candidate.version === SDK_RUST_VERSION)
    : null;
  if (!installedPackage) {
    throw new Error(`pinned Rust package mismatch: expected durable-workflow ${SDK_RUST_VERSION} in Cargo metadata`);
  }
  if (!String(installedPackage.source ?? '').startsWith('registry+')) {
    throw new Error(`pinned Rust package did not resolve from a public Cargo registry: ${installedPackage.source ?? 'missing source'}`);
  }
  if (installedPackage.repository !== 'https://github.com/durable-workflow/sdk-rust') {
    throw new Error(`pinned Rust package repository provenance mismatch: ${installedPackage.repository ?? 'missing repository'}`);
  }
  const releaseMetadata = installedPackage.metadata?.['durable-workflow'] ?? {};
  recordUniqueDistributionFile(
    'sdk-rust',
    SDK_RUST_VERSION,
    path.join(PROJECT_DIR, '.cargo-home', 'registry', 'cache'),
    `**/durable-workflow-${SDK_RUST_VERSION}.crate`,
  );

  const cargoLock = fs.readFileSync(path.join(PROJECT_DIR, 'Cargo.lock'), 'utf8');
  const packageBlock = cargoLock.split('[[package]]').find((block) =>
    block.includes('name = "durable-workflow"') && block.includes(`version = "${SDK_RUST_VERSION}"`));
  const registryChecksum = packageBlock?.match(/checksum = "([0-9a-f]{64})"/)?.[1] ?? '';
  if (!registryChecksum) throw new Error('pinned Rust package Cargo.lock entry has no registry checksum');
  evidence.rust_package_install = {
    package: 'durable-workflow',
    requested_version: SDK_RUST_VERSION,
    installed_version: installedPackage.version,
    source: ARTIFACT_SOURCES['sdk-rust'],
    resolved_registry_source: installedPackage.source,
    resolved_manifest_path: installedPackage.manifest_path,
    repository: installedPackage.repository,
    registry_checksum_sha256: registryChecksum,
    cargo_lock_sha256: crypto.createHash('sha256').update(cargoLock).digest('hex'),
    installer_runtime: RUST_IMAGE,
    install_mode: 'exact crates.io dependency with Cargo.lock',
    release_metadata: releaseMetadata,
  };
}

function installSdkPackage() {
  if (IS_PYTHON_CELL) {
    installPythonPackage();
    return;
  }
  if (IS_RUST_CELL) {
    installRustPackage();
    return;
  }
  installPhpPackage();
}

function startWorker(workerId) {
  const containerName = `dw-hb-${workerId}`.slice(0, 63);
  workerContainers.add(containerName);
  if (IS_RUST_CELL) {
    const result = run('docker', [
      'run', '-d', '--name', containerName,
      ...rustRuntimeArgs(),
      '--add-host', 'host.docker.internal:host-gateway',
      '--env', 'DURABLE_WORKFLOW_AUTH_TOKEN',
      RUST_IMAGE,
      '/app/target/release/heartbeat-worker',
      workerBaseUrl(serverBaseUrl),
      NAMESPACE,
      TASK_QUEUE,
      workerId,
      '600',
    ], {
      env: { ...process.env, DURABLE_WORKFLOW_AUTH_TOKEN: TOKEN },
      timeout: 60_000,
    });
    return {
      worker_id: workerId,
      container_name: containerName,
      container_id: String(result.stdout).trim(),
    };
  }
  if (IS_PYTHON_CELL) {
    const result = run('docker', [
      'run', '-d', '--name', containerName,
      ...pythonRuntimeArgs(),
      '--add-host', 'host.docker.internal:host-gateway',
      '--env', 'DURABLE_WORKFLOW_AUTH_TOKEN',
      PYTHON_IMAGE,
      'python', 'heartbeat-worker.py',
      pythonWorkerBaseUrl(),
      NAMESPACE,
      TASK_QUEUE,
      workerId,
      '600',
    ], {
      env: { ...process.env, DURABLE_WORKFLOW_AUTH_TOKEN: TOKEN },
      timeout: 60_000,
    });
    return {
      worker_id: workerId,
      container_name: containerName,
      container_id: String(result.stdout).trim(),
    };
  }
  const result = run('docker', [
    'run', '-d', '--name', containerName,
    '--user', CONTAINER_USER,
    '--add-host', 'host.docker.internal:host-gateway',
    '--env', 'DURABLE_WORKFLOW_AUTH_TOKEN',
    '-v', `${PROJECT_DIR}:/app`,
    '-w', '/app',
    '--entrypoint', 'php',
    PHP_IMAGE,
    'heartbeat-worker.php',
    workerBaseUrl(serverBaseUrl),
    NAMESPACE,
    TASK_QUEUE,
    workerId,
    '600',
  ], {
    env: { ...process.env, DURABLE_WORKFLOW_AUTH_TOKEN: TOKEN },
    timeout: 60_000,
  });
  return {
    worker_id: workerId,
    container_name: containerName,
    container_id: String(result.stdout).trim(),
  };
}

function stopWorker(worker) {
  const stopped = run('docker', ['stop', '--time', '2', worker.container_name], {
    allowFailure: true,
    timeout: 30_000,
  });
  if (stopped.status !== 0) {
    throw new WorkerStopConfirmationError(
      worker.worker_id,
      (stopped.stderr || stopped.stdout).trim(),
    );
  }
  const inspected = run('docker', [
    'container',
    'inspect',
    '--format',
    '{{json .State}}',
    worker.container_name,
  ], { allowFailure: true, timeout: 30_000 });
  const state = inspected.status === 0 ? parseJsonOutput(inspected.stdout) : null;
  if (inspected.status !== 0 || state?.Running !== false) {
    throw new WorkerStopConfirmationError(
      worker.worker_id,
      `container inspect did not report running=false: ${(inspected.stderr || inspected.stdout).trim()}`,
    );
  }
  return {
    docker_stop_exit_code: stopped.status,
    container_state: {
      status: state.Status ?? null,
      running: state.Running,
      exit_code: Number.isInteger(state.ExitCode) ? state.ExitCode : null,
      finished_at: state.FinishedAt ?? null,
    },
  };
}

function workerLogRecords(worker) {
  return workerLogOutput(worker).split(/\r?\n/).flatMap((line) => {
    if (!line.trim()) return [];
    try {
      const parsed = JSON.parse(line);
      if (parsed && typeof parsed === 'object'
        && !parsed.observed_at
        && Number.isFinite(Number(parsed.observed_at_unix_millis))) {
        parsed.observed_at = new Date(Number(parsed.observed_at_unix_millis)).toISOString();
      }
      return parsed && typeof parsed === 'object' ? [parsed] : [];
    } catch {
      return [];
    }
  });
}

function workerLogOutput(worker) {
  const result = run('docker', ['logs', worker.container_name], { allowFailure: true, timeout: 30_000 });
  if (result.status !== 0) {
    throw new Error(
      `could not read logs for worker ${worker.worker_id}: ${(result.stderr || result.stdout).trim()}`,
    );
  }
  return String(result.stdout);
}

function workProcessedBaseline(worker) {
  return captureWorkProcessedBaseline({
    workerId: worker.worker_id,
    logOutput: workerLogOutput(worker),
  });
}

function waitForWorkerWorkProcessed(worker, baseline) {
  return waitForWorkProcessedAdvance({
    baseline,
    readLogs: () => workerLogOutput(worker),
    maxAttempts: WORK_PROCESSED_VISIBILITY_ATTEMPTS,
    retryDelayMs: WORK_PROCESSED_VISIBILITY_RETRY_MS,
    wait: sleep,
    observedAt: now,
  });
}

function workerRegistration(worker) {
  return workerLogRecords(worker).find((record) => record.event === 'worker_registered') ?? null;
}

function workerHeartbeatRecords(worker) {
  return workerLogRecords(worker).filter((record) => record.event === 'worker_heartbeat');
}

async function waitForWorkerRegistration(worker) {
  return waitFor(`${worker.worker_id} registration`, async () => {
    const registration = workerRegistration(worker);
    if (!registration) return null;
    const detail = await api(`/workers/${encodeURIComponent(worker.worker_id)}`);
    return { registration, detail };
  }, 90_000, 500);
}

async function observeSuccessiveHeartbeats(worker, registrationEvidence) {
  const apiTimestamps = [];
  const apiSamples = [];
  const advertised = Number(registrationEvidence.registration.registration?.heartbeat_interval_seconds
    ?? registrationEvidence.detail.heartbeat_interval_seconds
    ?? 60);
  const timeout = Math.max(20_000, (advertised * 4 + 10) * 1_000);
  await waitFor(`${worker.worker_id} successive SDK heartbeats`, async () => {
    const records = workerHeartbeatRecords(worker);
    const detail = await api(`/workers/${encodeURIComponent(worker.worker_id)}`);
    apiSamples.push({ observed_at: now(), worker: detail });
    if (detail.last_heartbeat_at && !apiTimestamps.includes(detail.last_heartbeat_at)) apiTimestamps.push(detail.last_heartbeat_at);
    const observation = heartbeatCadenceObservation({
      cell: CELL,
      heartbeatRecords: records,
      serverHeartbeatTimestamps: apiTimestamps,
      advertisedSeconds: advertised,
    });
    return observation.sdk_heartbeat_acknowledgement_count >= 2
      && observation.server_last_heartbeat_timestamps.length >= 2
      && (IS_PYTHON_CELL || IS_RUST_CELL
        ? observation.sdk_native_heartbeat_timestamps.length >= 2
        : true);
  }, timeout, Math.min(1_000, Math.max(250, advertised * 250)));

  return {
    ...heartbeatCadenceObservation({
      cell: CELL,
      heartbeatRecords: workerHeartbeatRecords(worker),
      serverHeartbeatTimestamps: apiTimestamps,
      advertisedSeconds: advertised,
    }),
    api_samples: apiSamples,
  };
}

function cliEnvironment() {
  return {
    ...process.env,
    DURABLE_WORKFLOW_SERVER_URL: serverBaseUrl,
    DURABLE_WORKFLOW_AUTH_TOKEN: TOKEN,
    DURABLE_WORKFLOW_NAMESPACE: NAMESPACE,
    DURABLE_WORKFLOW_TLS_VERIFY: 'false',
  };
}

function cli(command, options = {}) {
  const result = run(cliBin, [...command, '--output=json'], {
    env: cliEnvironment(),
    allowFailure: options.allowFailure ?? false,
    timeout: options.timeout ?? 120_000,
  });
  return {
    command: ['dw', ...command, '--output=json'],
    exit_code: result.status,
    stdout: result.stdout,
    stderr: result.stderr,
    output: parseJsonOutput(result.stdout),
  };
}

function controlPlaneUrl(pathName, query = {}) {
  const url = new URL(`/api${pathName}`, serverBaseUrl);
  for (const [key, value] of Object.entries(query)) url.searchParams.set(key, String(value));
  return url;
}

function visibilityCli(command, pathName, query = {}, options = {}) {
  const sample = cli(command, options);
  const url = controlPlaneUrl(pathName, query);
  const transportFailure = cliControlPlaneTransportError({
    sample,
    method: 'GET',
    url,
    observedAt: now(),
  });
  if (transportFailure) {
    requestCaptures.push({
      timestamp: now(),
      ...transportFailure.request,
      status: null,
      transport_error: transportFailure.transport,
    });
    throw transportFailure;
  }
  return sample;
}

function startWorkflow(label) {
  const workflowId = `hb-${CELL}-${label}-${SUFFIX}`;
  const sample = cli([
    'workflow:start',
    `--type=${WORKFLOW_TYPE}`,
    `--workflow-id=${workflowId}`,
    `--task-queue=${TASK_QUEUE}`,
    '--wait',
  ], { timeout: 120_000 });
  return { workflow_id: workflowId, ...sample };
}

function completedWorkflow(sample) {
  const status = String(sample.output?.status ?? sample.output?.run?.status ?? '').toLowerCase();
  return sample.exit_code === 0 && status === 'completed';
}

async function captureOperatorVisibility(staleWorkerStatus = null, options = {}) {
  const apiList = await api('/workers', { task_queue: TASK_QUEUE });
  const apiStaleList = staleWorkerStatus ? await api('/workers', { task_queue: TASK_QUEUE, status: 'stale' }) : null;
  const apiStaleDetail = staleWorkerStatus ? await api(`/workers/${encodeURIComponent(STALE_WORKER_ID)}`) : null;
  return {
    raw_api: {
      worker_list: apiList,
      stale_worker_list: apiStaleList,
      stale_worker_detail: apiStaleDetail,
      fresh_worker_detail: await api(`/workers/${encodeURIComponent(FRESH_WORKER_ID)}`),
    },
    cli: {
      worker_list: visibilityCli(
        ['worker:list', `--task-queue=${TASK_QUEUE}`],
        '/workers',
        { task_queue: TASK_QUEUE },
        options,
      ),
      fresh_worker_describe: visibilityCli(
        ['worker:describe', FRESH_WORKER_ID],
        `/workers/${encodeURIComponent(FRESH_WORKER_ID)}`,
        {},
        options,
      ),
      stale_worker_list: staleWorkerStatus ? visibilityCli(
        ['worker:list', `--task-queue=${TASK_QUEUE}`, '--status=stale'],
        '/workers',
        { task_queue: TASK_QUEUE, status: 'stale' },
        options,
      ) : null,
      stale_worker_describe: staleWorkerStatus ? visibilityCli(
        ['worker:describe', STALE_WORKER_ID],
        `/workers/${encodeURIComponent(STALE_WORKER_ID)}`,
        {},
        options,
      ) : null,
    },
  };
}

function workersFromList(payload) {
  return Array.isArray(payload?.workers) ? payload.workers : [];
}

async function waitForStaleTransition(shutdownBoundary) {
  const staleAfterSeconds = shutdownBoundary.advertised_stale_after_seconds;
  return waitFor(`${STALE_WORKER_ID} stale transition`, async () => {
    const detail = await api(`/workers/${encodeURIComponent(STALE_WORKER_ID)}`);
    const active = await api('/workers', { task_queue: TASK_QUEUE });
    const stale = await api('/workers', { task_queue: TASK_QUEUE, status: 'stale' });
    const activeIds = workersFromList(active).map((worker) => worker.worker_id);
    const staleIds = workersFromList(stale).map((worker) => worker.worker_id);
    if (detail.status !== 'stale' || activeIds.includes(STALE_WORKER_ID) || !staleIds.includes(STALE_WORKER_ID)) return null;
    const observedStaleAt = preciseNow();
    const finalShutdownBoundary = refineWorkerShutdownBoundary({
      shutdownBoundary,
      workerDetailObservedAt: observedStaleAt,
      workerDetail: detail,
    });
    return {
      ...staleTransitionEvidence({ shutdownBoundary: finalShutdownBoundary, observedStaleAt }),
      stale_worker_detail: detail,
      default_active_worker_list: active,
      stale_worker_list: stale,
    };
  }, (staleAfterSeconds + 20) * 1_000, 500);
}

function stalePollProbe() {
  if (IS_RUST_CELL) {
    const result = run('docker', [
      'run', '--rm',
      ...rustRuntimeArgs(),
      '--add-host', 'host.docker.internal:host-gateway',
      '--env', 'DURABLE_WORKFLOW_AUTH_TOKEN',
      RUST_IMAGE,
      '/app/target/release/stale-poll',
      workerBaseUrl(serverBaseUrl),
      NAMESPACE,
      TASK_QUEUE,
      STALE_WORKER_ID,
    ], {
      env: { ...process.env, DURABLE_WORKFLOW_AUTH_TOKEN: TOKEN },
      timeout: 60_000,
    });
    return parseJsonOutput(result.stdout);
  }
  if (IS_PYTHON_CELL) {
    const result = run('docker', [
      'run', '--rm',
      ...pythonRuntimeArgs(),
      '--add-host', 'host.docker.internal:host-gateway',
      '--env', 'DURABLE_WORKFLOW_AUTH_TOKEN',
      PYTHON_IMAGE,
      'python', 'stale-poll.py',
      pythonWorkerBaseUrl(),
      NAMESPACE,
      TASK_QUEUE,
      STALE_WORKER_ID,
    ], {
      env: { ...process.env, DURABLE_WORKFLOW_AUTH_TOKEN: TOKEN },
      timeout: 60_000,
    });
    return parseJsonOutput(result.stdout);
  }
  const result = run('docker', [
    'run', '--rm',
    '--user', CONTAINER_USER,
    '--add-host', 'host.docker.internal:host-gateway',
    '--env', 'DURABLE_WORKFLOW_AUTH_TOKEN',
    '-v', `${PROJECT_DIR}:/app`,
    '-w', '/app',
    '--entrypoint', 'php',
    PHP_IMAGE,
    'stale-poll.php',
    workerBaseUrl(serverBaseUrl),
    NAMESPACE,
    TASK_QUEUE,
    STALE_WORKER_ID,
  ], {
    env: { ...process.env, DURABLE_WORKFLOW_AUTH_TOKEN: TOKEN },
    timeout: 60_000,
  });
  return parseJsonOutput(result.stdout);
}

function workerLogEvidence(worker) {
  const records = workerLogRecords(worker);
  return {
    worker_id: worker.worker_id,
    registration: records.find((record) => record.event === 'worker_registered') ?? null,
    heartbeat_records: records.filter((record) => record.event === 'worker_heartbeat'),
    work_processed_records: records.filter((record) => record.event === 'work_processed'),
    loop_stopped: records.find((record) => record.event === 'worker_loop_stopped') ?? null,
  };
}

function apiHasWorker(payload, workerId, expectedStatus = null) {
  const worker = workersFromList(payload).find((candidate) => candidate.worker_id === workerId);
  return Boolean(worker && (expectedStatus === null || worker.status === expectedStatus));
}

function cliWorker(payload) {
  const output = payload?.output ?? {};
  if (Array.isArray(output?.workers)) return output.workers;
  return output && typeof output === 'object' ? [output] : [];
}

function validTimestamp(value) {
  return typeof value === 'string' && Number.isFinite(Date.parse(value));
}

function validProtocolMetadata(value) {
  return typeof value?.protocol_version === 'string'
    && /^\d+\.\d+$/.test(value.protocol_version)
    && value?.server_capabilities
    && typeof value.server_capabilities === 'object';
}

function workerSurfacesConsistent(apiWorker, cliProjection) {
  if (apiWorker?.worker_id !== cliProjection?.worker_id
    || apiWorker?.task_queue !== cliProjection?.task_queue
    || apiWorker?.status !== cliProjection?.status
    || !validTimestamp(apiWorker?.last_heartbeat_at)
    || !validTimestamp(cliProjection?.last_heartbeat_at)) {
    return false;
  }
  const advertised = Number(apiWorker.heartbeat_interval_seconds ?? HEARTBEAT_SECONDS);
  const timestampDeltaSeconds = Math.abs(
    Date.parse(apiWorker.last_heartbeat_at) - Date.parse(cliProjection.last_heartbeat_at),
  ) / 1_000;
  return timestampDeltaSeconds <= Math.max(advertised * 2, advertised + 2);
}

function buildChecks(context) {
  const staleDetail = context.afterVisibility.raw_api.stale_worker_detail ?? {};
  const freshDetail = context.afterVisibility.raw_api.fresh_worker_detail ?? {};
  const activeList = context.afterVisibility.raw_api.worker_list ?? {};
  const staleList = context.afterVisibility.raw_api.stale_worker_list ?? {};
  const cliFresh = cliWorker(context.afterVisibility.cli.fresh_worker_describe)[0] ?? {};
  const cliStale = cliWorker(context.afterVisibility.cli.stale_worker_describe)[0] ?? {};
  const cliActiveList = cliWorker(context.afterVisibility.cli.worker_list);
  const cliStaleList = cliWorker(context.afterVisibility.cli.stale_worker_list);
  const stalePollStatus = context.stalePoll.poll?.poll_status ?? '';
  return {
    exact_published_artifacts_installed: evidence.server_image_install?.exact_published_image_verified === true
      && (IS_PYTHON_CELL
        ? samePythonRelease(SDK_PYTHON_VERSION, evidence.python_package_install?.installed_version)
        : (IS_RUST_CELL
          ? evidence.rust_package_install?.installed_version === SDK_RUST_VERSION
            && evidence.rust_package_install?.resolved_registry_source?.startsWith('registry+')
            && /^[0-9a-f]{64}$/.test(evidence.rust_package_install?.registry_checksum_sha256 ?? '')
          : evidence.php_package_install?.installed_version === SDK_PHP_VERSION))
      && evidence.cli_install?.detected_version === CLI_VERSION,
    real_workflow_completed_by_sdk_loop: completedWorkflow(context.initialWorkflow)
      && completedWorkflow(context.freshWorkflow)
      && context.staleWorkProcessed.observed_count > context.staleWorkProcessed.baseline_count
      && context.freshWorkProcessed.observed_count > context.freshWorkProcessed.baseline_count,
    at_least_two_sdk_heartbeats: context.staleCadence.sdk_heartbeat_acknowledgement_count >= 2,
    server_observed_successive_heartbeats: context.staleCadence.server_last_heartbeat_timestamps.length >= 2,
    advertised_cadence_bounded: context.staleCadence.bounded_advertised_cadence,
    task_queue_association_visible: staleDetail.task_queue === TASK_QUEUE && freshDetail.task_queue === TASK_QUEUE,
    worker_identity_namespace_visible: !IS_RUST_CELL || (
      staleDetail.worker_id === STALE_WORKER_ID
      && freshDetail.worker_id === FRESH_WORKER_ID
      && staleDetail.namespace === NAMESPACE
      && freshDetail.namespace === NAMESPACE
    ),
    runtime_and_protocol_metadata_visible: !IS_RUST_CELL || (
      staleDetail.runtime === 'rust'
      && freshDetail.runtime === 'rust'
      && staleDetail.sdk_version === `durable-workflow-rust/${SDK_RUST_VERSION}`
      && freshDetail.sdk_version === `durable-workflow-rust/${SDK_RUST_VERSION}`
      && validProtocolMetadata(context.staleRegistration.registration.registration)
      && context.staleCadence.acknowledgements.every(validProtocolMetadata)
      && cliFresh.runtime === 'rust'
      && cliFresh.sdk_version === `durable-workflow-rust/${SDK_RUST_VERSION}`
      && cliFresh.namespace === NAMESPACE
      && cliFresh.task_slots && typeof cliFresh.task_slots === 'object'
      && cliFresh.process_metrics && typeof cliFresh.process_metrics === 'object'
    ),
    heartbeat_freshness_visible: validTimestamp(staleDetail.last_heartbeat_at)
      && validTimestamp(freshDetail.last_heartbeat_at),
    task_slots_visible: Boolean(staleDetail.task_slots && freshDetail.task_slots),
    process_metrics_visible: Boolean(staleDetail.process_metrics && freshDetail.process_metrics),
    api_cli_worker_state_consistent: workerSurfacesConsistent(freshDetail, cliFresh)
      && workerSurfacesConsistent(staleDetail, cliStale),
    stale_worker_excluded_from_default_list: staleDetail.status === 'stale'
      && !apiHasWorker(activeList, STALE_WORKER_ID)
      && apiHasWorker(staleList, STALE_WORKER_ID, 'stale'),
    stale_transition_bounded: context.staleTransition.within_bounded_window === true,
    stale_sdk_poll_refused: Array.isArray(context.stalePoll.tasks)
      && context.stalePoll.tasks.length === 0
      && ['stale_worker_registration', 'worker_heartbeat_stale'].includes(String(stalePollStatus)),
    fresh_worker_remains_eligible: freshDetail.status === 'active'
      && apiHasWorker(activeList, FRESH_WORKER_ID, 'active')
      && completedWorkflow(context.freshWorkflow),
    cli_fresh_and_stale_visible: cliFresh.worker_id === FRESH_WORKER_ID
      && cliFresh.status === 'active'
      && cliFresh.task_queue === TASK_QUEUE
      && cliStale.worker_id === STALE_WORKER_ID
      && cliStale.status === 'stale'
      && cliStale.task_queue === TASK_QUEUE
      && cliActiveList.some((worker) => worker.worker_id === FRESH_WORKER_ID && worker.task_queue === TASK_QUEUE)
      && cliStaleList.some((worker) => worker.worker_id === STALE_WORKER_ID && worker.task_queue === TASK_QUEUE),
  };
}

function buildFinalVisibilityChecks(context) {
  const checks = buildChecks(context);
  return Object.fromEntries([
    'task_queue_association_visible',
    'heartbeat_freshness_visible',
    'task_slots_visible',
    'process_metrics_visible',
    'api_cli_worker_state_consistent',
    'stale_worker_excluded_from_default_list',
    'fresh_worker_remains_eligible',
    'cli_fresh_and_stale_visible',
  ].map((name) => [name, checks[name]]));
}

function buildCompletedBehaviorCheckpoint(context) {
  const stalePollStatus = String(context.stalePoll.poll?.poll_status ?? '');
  const checks = {
    stale_worker_registered: Boolean(context.staleRegistration.registration.registration),
    fresh_worker_registered: Boolean(context.freshRegistration.registration.registration),
    stale_worker_successive_heartbeats: context.staleCadence.sdk_heartbeat_acknowledgement_count >= 2
      && context.staleCadence.server_last_heartbeat_timestamps.length >= 2
      && context.staleCadence.bounded_advertised_cadence === true,
    fresh_worker_successive_heartbeats: context.freshCadence.sdk_heartbeat_acknowledgement_count >= 2
      && context.freshCadence.server_last_heartbeat_timestamps.length >= 2
      && context.freshCadence.bounded_advertised_cadence === true,
    workflows_completed_by_real_workers: completedWorkflow(context.initialWorkflow)
      && completedWorkflow(context.freshWorkflow)
      && context.staleWorkProcessed.observed_count > context.staleWorkProcessed.baseline_count
      && context.freshWorkProcessed.observed_count > context.freshWorkProcessed.baseline_count,
    stale_transition_observed: context.staleTransition.within_bounded_window === true
      && context.staleTransition.stale_worker_detail?.status === 'stale',
    stale_worker_excluded_from_routing: !apiHasWorker(
      context.staleTransition.default_active_worker_list,
      STALE_WORKER_ID,
    ) && apiHasWorker(context.staleTransition.stale_worker_list, STALE_WORKER_ID, 'stale')
      && Array.isArray(context.stalePoll.tasks)
      && context.stalePoll.tasks.length === 0
      && ['stale_worker_registration', 'worker_heartbeat_stale'].includes(stalePollStatus),
    fresh_peer_completed_work_after_stale: completedWorkflow(context.freshWorkflow)
      && context.freshWorkProcessed.observed_count > context.freshWorkProcessed.baseline_count,
  };

  return {
    observed_at: now(),
    all_checks_passed: Object.values(checks).every((value) => value === true),
    checks,
    registrations: {
      stale: context.staleRegistration,
      fresh: context.freshRegistration,
    },
    cadence: {
      stale: context.staleCadence,
      fresh: context.freshCadence,
    },
    workflows: {
      before_stale: context.initialWorkflow,
      after_stale: context.freshWorkflow,
    },
    stale_transition: context.staleTransition,
    stale_poll: context.stalePoll,
    visibility_before_stale: context.beforeVisibility,
    worker_logs: {
      stale: context.staleWorkerLog,
      fresh: context.freshWorkerLog,
    },
  };
}

function redactDiagnosticText(value) {
  let redacted = String(value ?? '').replace(/(authorization:\s*bearer\s+)\S+/gi, '$1[REDACTED]');
  for (const secret of [TOKEN, env('DB_PASSWORD'), env('DW_DATABASE_PASSWORD')]) {
    if (secret.length >= 4) redacted = redacted.split(secret).join('[REDACTED]');
  }
  return redacted;
}

function parseJsonLines(value) {
  const raw = String(value ?? '').trim();
  if (!raw) return [];
  try {
    const parsed = JSON.parse(raw);
    return Array.isArray(parsed) ? parsed : [parsed];
  } catch {
    return raw.split(/\r?\n/).flatMap((line) => {
      try {
        return [JSON.parse(line)];
      } catch {
        return [];
      }
    });
  }
}

function normalizedServerState(inspect) {
  const state = inspect?.State ?? {};
  const health = state?.Health ?? {};
  return {
    status: state?.Status ?? null,
    running: typeof state?.Running === 'boolean' ? state.Running : null,
    paused: typeof state?.Paused === 'boolean' ? state.Paused : null,
    restarting: typeof state?.Restarting === 'boolean' ? state.Restarting : null,
    oom_killed: typeof state?.OOMKilled === 'boolean' ? state.OOMKilled : null,
    dead: typeof state?.Dead === 'boolean' ? state.Dead : null,
    exit_code: Number.isInteger(state?.ExitCode) ? state.ExitCode : null,
    error: redactDiagnosticText(state?.Error),
    started_at: state?.StartedAt ?? null,
    finished_at: state?.FinishedAt ?? null,
    health: {
      status: health?.Status ?? null,
      failing_streak: Number.isInteger(health?.FailingStreak) ? health.FailingStreak : null,
      recent_checks: Array.isArray(health?.Log) ? health.Log.slice(-5).map((entry) => ({
        start: entry?.Start ?? null,
        end: entry?.End ?? null,
        exit_code: Number.isInteger(entry?.ExitCode) ? entry.ExitCode : null,
        output: redactDiagnosticText(entry?.Output),
      })) : [],
    },
  };
}

async function captureServerTransportDiagnostics() {
  const diagnostics = {
    observed_at: now(),
    endpoint: serverBaseUrl,
    topology: {
      compose_project: serverTopology?.project ?? null,
      server_container_id: serverTopology?.container_id ?? null,
    },
    container: {
      present: false,
      inspect_exit_code: null,
      inspect_error: null,
      state: {},
      restart_count: null,
    },
    compose_state: [],
    readiness_probe: null,
    logs: null,
  };

  if (!serverTopology?.container_id) return diagnostics;

  const inspectResult = run('docker', [
    'container',
    'inspect',
    '--format',
    '{"State":{{json .State}},"RestartCount":{{json .RestartCount}}}',
    serverTopology.container_id,
  ], { allowFailure: true, timeout: 30_000 });
  const inspected = inspectResult.status === 0 ? parseJsonOutput(inspectResult.stdout) : null;
  diagnostics.container = {
    present: inspectResult.status === 0 && Boolean(inspected?.State),
    inspect_exit_code: inspectResult.status,
    inspect_error: inspectResult.status === 0
      ? null
      : redactDiagnosticText(inspectResult.stderr || inspectResult.stdout),
    state: normalizedServerState(inspected),
    restart_count: Number.isInteger(inspected?.RestartCount) ? inspected.RestartCount : null,
  };

  if (Array.isArray(serverTopology.compose_args)) {
    const composeResult = run('docker', [
      ...serverTopology.compose_args,
      'ps',
      '--all',
      '--format',
      'json',
    ], {
      env: serverTopology.compose_env,
      allowFailure: true,
      timeout: 30_000,
    });
    diagnostics.compose_state = parseJsonLines(composeResult.stdout).map((entry) => ({
      name: entry?.Name ?? null,
      service: entry?.Service ?? null,
      state: entry?.State ?? null,
      health: entry?.Health ?? null,
      exit_code: Number.isInteger(entry?.ExitCode) ? entry.ExitCode : null,
    }));
    if (composeResult.status !== 0) {
      diagnostics.compose_state_error = redactDiagnosticText(composeResult.stderr || composeResult.stdout);
    }
  } else {
    diagnostics.compose_state = [{
      name: serverTopology.container_id,
      service: 'server',
      state: diagnostics.container.state.status,
      health: diagnostics.container.state.health.status,
      lifecycle_owner: serverTopology.lifecycle_owner,
    }];
  }

  const logsResult = run('docker', [
    'logs',
    '--timestamps',
    '--tail',
    '400',
    serverTopology.container_id,
  ], { allowFailure: true, timeout: 30_000 });
  const retainedLogs = redactDiagnosticText(`${logsResult.stdout}${logsResult.stderr}`);
  const serverLogFile = 'server-container.log';
  fs.writeFileSync(path.join(RESULT_DIR, serverLogFile), retainedLogs, 'utf8');
  diagnostics.logs = {
    artifact: serverLogFile,
    docker_exit_code: logsResult.status,
    line_count: retainedLogs ? retainedLogs.split(/\r?\n/).length : 0,
    tail: retainedLogs,
  };

  const readinessUrl = new URL('/api/ready', serverBaseUrl);
  try {
    const response = await fetch(readinessUrl, { headers: controlPlaneHeaders() });
    const raw = await response.text();
    diagnostics.readiness_probe = {
      observed_at: now(),
      method: 'GET',
      url: readinessUrl.toString(),
      status: response.status,
      ok: response.ok,
      body: redactDiagnosticText(raw).slice(0, 4_000),
    };
  } catch (error) {
    diagnostics.readiness_probe = {
      observed_at: now(),
      method: 'GET',
      url: readinessUrl.toString(),
      status: null,
      ok: false,
      transport_error: transportErrorDetails(error),
    };
  }

  return diagnostics;
}

function writeResultFiles(context = null) {
  const finishedAt = now();
  evidence.finished_at = finishedAt;
  evidence.generated_at = finishedAt;
  evidence.artifact_sources = ARTIFACT_SOURCES;
  evidence.executed_distribution_identities = loadDistributionIdentities();

  const pins = {
    schema: `durable-workflow.v2.heartbeat-runtime.${CELL}-sdk-loop-pins`,
    generated_at: finishedAt,
    artifact_versions: ARTIFACT_VERSIONS,
    artifact_sources: ARTIFACT_SOURCES,
    local_product_source_checkouts_used: false,
  };
  const metadata = {
    schema: `durable-workflow.v2.heartbeat-runtime.${CELL}-sdk-loop-run-metadata`,
    conformance_run_id: RUN_ID,
    started_at: STARTED_AT,
    finished_at: finishedAt,
    outcome: evidence.outcome,
    runner_blocked: evidence.runner_blocked,
    topology: evidence.topology,
    public_surfaces: [
      'POST /api/namespaces',
      'POST /api/worker/register',
      'POST /api/worker/heartbeat',
      'POST /api/worker/workflow-tasks/poll',
      'GET /api/workers',
      'GET /api/workers/{workerId}',
      'dw workflow:start --wait',
      'dw worker:list',
      'dw worker:describe',
    ],
  };
  writeJson('pins.json', pins);
  writeJson('run-metadata.json', metadata);
  writeJson(EVIDENCE_FILE, evidence);
  writeJson('heartbeat-request-response-captures.json', {
    schema: 'durable-workflow.v2.heartbeat-runtime.request-response-captures',
    conformance_run_id: RUN_ID,
    captures: requestCaptures,
  });
  writeJson('heartbeat-cadence-dataset.json', context ? {
    schema: 'durable-workflow.v2.heartbeat-runtime.cadence-dataset',
    conformance_run_id: RUN_ID,
    task_queue: TASK_QUEUE,
    workers: {
      [STALE_WORKER_ID]: context.staleCadence,
      [FRESH_WORKER_ID]: context.freshCadence,
    },
  } : {
    schema: 'durable-workflow.v2.heartbeat-runtime.cadence-dataset',
    conformance_run_id: RUN_ID,
    workers: {},
  });
}

function recordFailure(error) {
  const summary = error instanceof Error ? error.message : String(error);
  const runnerBlocked = !publishedExecutionStarted || error instanceof WorkerStopConfirmationError;
  evidence.outcome = runnerBlocked ? 'runner_blocked' : 'fail';
  evidence.runner_blocked = runnerBlocked;
  evidence.classification = evidence.runner_blocked
    ? 'conformance-runner-blocked'
    : `${CELL}-sdk-heartbeat-loop-failed`;
  evidence.scenario_results[SCENARIO_ID] = {
    scenario_id: SCENARIO_ID,
    status: evidence.runner_blocked ? 'runner_blocked' : 'fail',
    classification: evidence.runner_blocked ? 'runner-gap' : 'product-gap',
    observed_outputs: {
      runtime: RUNTIME,
      worker_id: STALE_WORKER_ID,
      task_queue: TASK_QUEUE,
      published_artifact_worker_execution: publishedExecutionStarted,
      local_product_source_checkouts_used: false,
      error: summary,
      stale_worker_shutdown: evidence.stale_worker_shutdown ?? null,
      stale_transition: evidence.stale_transition ?? null,
      completed_behavior_before_final_visibility: completedBehavior,
    },
  };
  evidence.findings.push({
    finding_id: `${CELL}-sdk-heartbeat-loop-${evidence.runner_blocked ? 'runner-gap' : 'product-gap'}-${SUFFIX}`,
    finding_type: evidence.runner_blocked ? 'conformance_runner_blocked' : `${CELL}_sdk_heartbeat_loop_gap`,
    classification: evidence.runner_blocked ? 'runner-gap' : 'product-gap',
    scenario_id: SCENARIO_ID,
    owning_surface: evidence.runner_blocked ? 'conformance_harness' : `${RUNTIME}-or-server-worker-protocol`,
    artifact_versions: ARTIFACT_VERSIONS,
    observed_behavior: summary,
    expected_behavior: `The pinned public ${CELL} SDK emits successive acknowledged heartbeats while completing real workflow work, then stale routing excludes the stopped worker while a fresh peer remains eligible.`,
    next_acceptance_criterion: evidence.runner_blocked
      ? `Restore the missing host prerequisite and rerun the focused published-artifact ${CELL} heartbeat shard.`
      : 'Fix the owning public worker or server surface and rerun this focused shard against the next published tuple.',
  });
}

function recordRustPreparationTimeout(error) {
  evidence.outcome = 'runner_blocked';
  evidence.runner_blocked = true;
  evidence.classification = 'rust-crates-io-preparation-timeout';
  evidence.rust_crates_io_preparation = {
    source: ARTIFACT_SOURCES['sdk-rust'],
    status: 'runner_blocked',
    timeout_ms: error.timeoutMilliseconds,
    elapsed_ms: error.elapsedMilliseconds,
    failed_phase: error.phase,
    completed_phases: error.completedPhases,
  };
  evidence.scenario_results[SCENARIO_ID] = {
    scenario_id: SCENARIO_ID,
    status: 'runner_blocked',
    classification: 'runner-gap',
    observed_outputs: {
      runtime: RUNTIME,
      worker_id: STALE_WORKER_ID,
      task_queue: TASK_QUEUE,
      published_artifact_worker_execution: false,
      exact_artifact_versions: ARTIFACT_VERSIONS,
      exact_artifact_sources: ARTIFACT_SOURCES,
      crates_io_preparation: evidence.rust_crates_io_preparation,
      local_product_source_checkouts_used: false,
      error: error.message,
    },
  };
  evidence.findings.push({
    finding_id: `rust-sdk-heartbeat-loop-crates-io-timeout-${SUFFIX}`,
    finding_type: 'conformance_runner_blocked',
    classification: 'runner-gap',
    scenario_id: SCENARIO_ID,
    owning_surface: 'conformance_harness',
    artifact_versions: ARTIFACT_VERSIONS,
    observed_behavior: error.message,
    expected_behavior: 'The exact public Rust crate and its locked dependencies prepare within the bounded registry budget before offline compilation and heartbeat execution.',
    next_acceptance_criterion: 'Restore bounded crates.io access on the runner and rerun the focused published-artifact Rust heartbeat shard.',
  });
}

function recordPostStopDetailFailure(error, serverDiagnostics) {
  const failure = error instanceof PostStopDetailHttpError
    ? semanticPostStopDetailEvidence({
      error,
      serverDiagnostics,
      shutdown: postStopDetailContext,
    })
    : persistentPostStopDetailEvidence({
      error,
      serverDiagnostics,
      shutdown: postStopDetailContext,
    });
  evidence.outcome = failure.runner_blocked ? 'runner_blocked' : 'fail';
  evidence.runner_blocked = failure.runner_blocked;
  evidence.classification = failure.classification;
  evidence.post_stop_worker_detail_recovery = failure.post_stop_worker_detail_transport;
  evidence.server_transport_diagnostics = failure.server_diagnostics;
  evidence.stale_worker_shutdown = {
    ...postStopDetailContext,
    boundary_complete: false,
    causal_stale_anchor: 'final_server_accepted_heartbeat',
    final_accepted_heartbeat_at: null,
    worker_detail_observed_at: null,
    stale_window_widened: false,
    shared_wave_retried: false,
    incomplete_reason: 'post_stop_worker_detail_unavailable',
  };
  evidence.scenario_results[SCENARIO_ID] = {
    scenario_id: SCENARIO_ID,
    status: failure.runner_blocked ? 'runner_blocked' : 'fail',
    classification: failure.classification,
    observed_outputs: {
      runtime: RUNTIME,
      worker_id: STALE_WORKER_ID,
      task_queue: TASK_QUEUE,
      published_artifact_worker_execution: true,
      sdk_or_worker_protocol_defect_proven: false,
      stale_worker_shutdown: evidence.stale_worker_shutdown,
      post_stop_worker_detail_transport: failure.post_stop_worker_detail_transport,
      server_diagnostics: failure.server_diagnostics,
      stale_transition_attempted: false,
      local_product_source_checkouts_used: false,
    },
  };
  evidence.findings = [{
    finding_id: `${CELL}-post-stop-worker-detail-${failure.runner_blocked ? 'transport' : 'http'}-${SUFFIX}`,
    finding_type: failure.finding_type,
    classification: failure.classification,
    scenario_id: SCENARIO_ID,
    owning_surface: failure.owning_surface,
    artifact_versions: ARTIFACT_VERSIONS,
    observed_behavior: `${error.message} ${failure.reason}`,
    expected_behavior: 'After confirmed worker shutdown, a bounded worker-detail read retains the final server-accepted heartbeat that anchors the unchanged stale window.',
    next_acceptance_criterion: failure.runner_blocked
      ? 'Restore reliable focused host-to-container control-plane transport and rerun the published-artifact heartbeat wave.'
      : 'Restore standalone server worker-detail availability and rerun the published-artifact heartbeat wave against the next server image.',
  }];
}

function recordPersistentFinalVisibilityFailure(error, serverDiagnostics) {
  const failure = persistentTransportEvidence({
    error,
    serverDiagnostics,
    completedBehavior,
  });
  evidence.outcome = failure.runner_blocked ? 'runner_blocked' : 'fail';
  evidence.runner_blocked = failure.runner_blocked;
  evidence.classification = failure.classification;
  evidence.completed_behavior_before_final_visibility = completedBehavior;
  evidence.final_visibility_transport = failure.final_visibility_transport;
  evidence.server_transport_diagnostics = failure.server_diagnostics;
  evidence.scenario_results[SCENARIO_ID] = {
    scenario_id: SCENARIO_ID,
    status: failure.runner_blocked ? 'runner_blocked' : 'fail',
    classification: failure.classification,
    observed_outputs: {
      runtime: RUNTIME,
      worker_id: STALE_WORKER_ID,
      peer_worker_id: FRESH_WORKER_ID,
      task_queue: TASK_QUEUE,
      published_artifact_worker_execution: true,
      sdk_behavior_completed: completedBehavior?.all_checks_passed === true,
      completed_behavior_before_final_visibility: completedBehavior,
      final_visibility_transport: failure.final_visibility_transport,
      server_diagnostics: failure.server_diagnostics,
      operator_visibility_invariants_observed: false,
      local_product_source_checkouts_used: false,
    },
  };
  evidence.findings = [{
    finding_id: `${CELL}-final-visibility-transport-${SUFFIX}`,
    finding_type: failure.finding_type,
    classification: failure.classification,
    scenario_id: SCENARIO_ID,
    owning_surface: failure.owning_surface,
    artifact_versions: ARTIFACT_VERSIONS,
    observed_behavior: `${error.message} ${failure.reason}`,
    expected_behavior: 'The final API and CLI worker views remain reachable after heartbeat cadence, workflow execution, stale exclusion, and fresh-peer work have succeeded.',
    next_acceptance_criterion: failure.runner_blocked
      ? 'Restore reliable host-to-container control-plane transport and rerun the focused published-artifact heartbeat shard.'
      : 'Restore standalone server availability and rerun the focused published-artifact heartbeat shard against the next server image.',
  }];
}

function recordFinalVisibilityInvariantFailure(error) {
  const cliFailures = Object.values(error.observedVisibility?.cli ?? {})
    .filter((sample) => sample && sample.exit_code !== 0)
    .map((sample) => ({ command: sample.command, exit_code: sample.exit_code, stderr: sample.stderr }));
  const owningSurface = cliFailures.length > 0 ? 'cli' : 'server';
  const findingType = cliFailures.length > 0 ? 'cli_operator_visibility_gap' : 'api_operator_visibility_gap';
  evidence.outcome = 'fail';
  evidence.runner_blocked = false;
  evidence.classification = `${owningSurface}-operator-visibility-gap`;
  evidence.completed_behavior_before_final_visibility = completedBehavior;
  evidence.operator_visibility = error.observedVisibility;
  evidence.final_visibility_recovery = {
    outcome: 'invariants_failed',
    failed_invariants: error.failedInvariants,
    invariants_observed: false,
  };
  evidence.scenario_results[SCENARIO_ID] = {
    scenario_id: SCENARIO_ID,
    status: 'fail',
    classification: evidence.classification,
    observed_outputs: {
      runtime: RUNTIME,
      worker_id: STALE_WORKER_ID,
      peer_worker_id: FRESH_WORKER_ID,
      task_queue: TASK_QUEUE,
      published_artifact_worker_execution: true,
      sdk_behavior_completed: completedBehavior?.all_checks_passed === true,
      completed_behavior_before_final_visibility: completedBehavior,
      failed_operator_visibility_invariants: error.failedInvariants,
      failed_cli_commands: cliFailures,
      operator_visibility: error.observedVisibility,
      local_product_source_checkouts_used: false,
    },
  };
  evidence.findings = [{
    finding_id: `${CELL}-final-visibility-invariants-${SUFFIX}`,
    finding_type: findingType,
    classification: evidence.classification,
    scenario_id: SCENARIO_ID,
    owning_surface: owningSurface,
    artifact_versions: ARTIFACT_VERSIONS,
    observed_behavior: error.message,
    expected_behavior: 'The exact final API and CLI views show the stale worker excluded and the fresh worker active after real workflow work completes.',
    next_acceptance_criterion: `Restore the ${owningSurface} operator-visibility invariants and rerun this focused published-artifact heartbeat shard.`,
  }];
}

function recordCleanupFailure(cleanupFailures) {
  const summary = cleanupFailures.map((failure) => `${failure.resource}: ${failure.error}`).join('; ');
  evidence.execution_outcome_before_cleanup = evidence.outcome;
  evidence.outcome = 'runner_blocked';
  evidence.runner_blocked = true;
  evidence.classification = 'conformance-cleanup-failed';
  evidence.scenario_results[SCENARIO_ID].execution_status_before_cleanup =
    evidence.scenario_results[SCENARIO_ID].status;
  evidence.scenario_results[SCENARIO_ID].status = 'runner_blocked';
  evidence.scenario_results[SCENARIO_ID].classification = 'runner-gap';
  evidence.scenario_results[SCENARIO_ID].observed_outputs.cleanup_error = summary;
  evidence.findings.push({
    finding_id: `${CELL}-sdk-heartbeat-loop-cleanup-${SUFFIX}`,
    finding_type: 'conformance_runner_cleanup_failed',
    classification: 'runner-gap',
    scenario_id: SCENARIO_ID,
    owning_surface: 'conformance_harness',
    artifact_versions: ARTIFACT_VERSIONS,
    observed_behavior: summary,
    expected_behavior: `The focused runner removes every named ${CELL} worker container and the compose project volumes before it emits consumable evidence.`,
    next_acceptance_criterion: `Restore deterministic Docker cleanup and rerun the focused published-artifact ${CELL} heartbeat shard.`,
  });
}

let completedContext = null;

async function main() {
  ensureExactPins();
  for (const command of ['docker']) {
    if (!commandExists(command)) throw new Error(`required command not found: ${command}`);
  }
  await startServer();
  await ensureNamespace();
  installCli();
  installSdkPackage();
  requireDistributionIdentities();
  publishedExecutionStarted = true;

  const staleWorker = startWorker(STALE_WORKER_ID);
  const staleRegistration = await waitForWorkerRegistration(staleWorker);
  const staleCadence = await observeSuccessiveHeartbeats(staleWorker, staleRegistration);
  const staleWorkProcessedBaseline = workProcessedBaseline(staleWorker);
  const initialWorkflow = startWorkflow('initial');
  if (!completedWorkflow(initialWorkflow)) throw new Error(`the initial real workflow did not complete through the ${CELL} worker loop`);
  const staleWorkProcessed = await waitForWorkerWorkProcessed(staleWorker, staleWorkProcessedBaseline);

  const freshWorker = startWorker(FRESH_WORKER_ID);
  const freshRegistration = await waitForWorkerRegistration(freshWorker);
  const freshCadence = await observeSuccessiveHeartbeats(freshWorker, freshRegistration);
  const beforeVisibility = await captureOperatorVisibility();

  const stopRequestedAt = preciseNow();
  const stopConfirmation = stopWorker(staleWorker);
  const stoppedAt = preciseNow();
  const finalHeartbeatRecords = workerHeartbeatRecords(staleWorker);
  const finalHeartbeatRecord = finalHeartbeatRecords[finalHeartbeatRecords.length - 1] ?? null;
  postStopDetailContext = {
    worker_id: STALE_WORKER_ID,
    stop_requested_at: stopRequestedAt,
    stopped_at: stoppedAt,
    stop_confirmation: stopConfirmation,
    last_sdk_heartbeat_acknowledgement_observed_at: finalHeartbeatRecord?.observed_at
      ?? finalHeartbeatRecord?.acknowledgement_logged_at
      ?? null,
    last_sdk_heartbeat_acknowledgement: finalHeartbeatRecord?.acknowledgement ?? null,
  };
  executionPhase = 'post_stop_worker_detail';
  const recoveredPostStopDetail = await recoverPostStopWorkerDetail({
    capture: () => api(`/workers/${encodeURIComponent(STALE_WORKER_ID)}`),
    maxAttempts: POST_STOP_DETAIL_ATTEMPTS,
    retryDelayMs: POST_STOP_DETAIL_RETRY_MS,
  });
  const stoppedWorkerDetail = recoveredPostStopDetail.workerDetail;
  const workerDetailObservedAt = recoveredPostStopDetail.workerDetailObservedAt;
  evidence.post_stop_worker_detail_recovery = recoveredPostStopDetail.recovery;
  const shutdownBoundary = workerShutdownBoundary({
    stopRequestedAt,
    stoppedAt,
    workerDetailObservedAt,
    workerDetail: {
      ...stoppedWorkerDetail,
      stale_after_seconds: stoppedWorkerDetail.stale_after_seconds
        ?? staleRegistration.registration.registration?.stale_after_seconds
        ?? staleRegistration.detail.stale_after_seconds
        ?? CONFIGURED_STALE_AFTER_SECONDS,
    },
    finalHeartbeatRecord,
    stopConfirmation,
  });
  evidence.stale_worker_shutdown = shutdownBoundary;
  const staleAfterSeconds = shutdownBoundary.advertised_stale_after_seconds;
  executionPhase = 'stale_transition';
  const staleTransition = await waitForStaleTransition(shutdownBoundary);
  evidence.stale_worker_shutdown = {
    ...shutdownBoundary,
    worker_detail_observed_at: staleTransition.worker_detail_observed_at,
    final_accepted_heartbeat_at: staleTransition.final_accepted_heartbeat_at,
    advertised_stale_after_seconds: staleTransition.advertised_stale_after_seconds,
  };
  evidence.stale_transition = staleTransition;
  const stalePoll = stalePollProbe();
  const freshWorkProcessedBaseline = workProcessedBaseline(freshWorker);
  const freshWorkflow = startWorkflow('fresh-after-stale');
  if (!completedWorkflow(freshWorkflow)) throw new Error(`the fresh ${CELL} worker did not complete work after its peer became stale`);
  const freshWorkProcessed = await waitForWorkerWorkProcessed(freshWorker, freshWorkProcessedBaseline);
  const context = {
    staleWorker,
    freshWorker,
    staleRegistration,
    freshRegistration,
    staleCadence,
    freshCadence,
    initialWorkflow,
    freshWorkflow,
    staleWorkProcessed,
    freshWorkProcessed,
    beforeVisibility,
    staleTransition,
    stalePoll,
    staleWorkerLog: workerLogEvidence(staleWorker),
    freshWorkerLog: workerLogEvidence(freshWorker),
  };
  completedContext = context;
  completedBehavior = buildCompletedBehaviorCheckpoint(context);
  evidence.completed_behavior_before_final_visibility = completedBehavior;
  if (!completedBehavior.all_checks_passed) {
    const failedChecks = Object.entries(completedBehavior.checks)
      .filter(([, value]) => value !== true)
      .map(([key]) => key);
    throw new Error(`${CELL} SDK behavior checkpoint failed before final visibility: ${failedChecks.join(', ')}`);
  }

  executionPhase = 'final_operator_visibility';
  const recoveredVisibility = await recoverFinalVisibility({
    capture: () => captureOperatorVisibility('stale', { allowFailure: true }),
    validate: (afterVisibility) => Object.entries(buildFinalVisibilityChecks({ ...context, afterVisibility }))
      .filter(([, value]) => value !== true)
      .map(([key]) => key),
    maxAttempts: FINAL_VISIBILITY_ATTEMPTS,
    retryDelayMs: FINAL_VISIBILITY_RETRY_MS,
  });
  const afterVisibility = recoveredVisibility.visibility;
  context.afterVisibility = afterVisibility;
  evidence.final_visibility_recovery = recoveredVisibility.recovery;
  stopWorker(freshWorker);
  context.freshWorkerLog = workerLogEvidence(freshWorker);
  executionPhase = 'final_assertions';
  const checks = buildChecks(context);
  const failedChecks = Object.entries(checks).filter(([, value]) => value !== true).map(([key]) => key);
  if (failedChecks.length > 0) throw new Error(`${CELL} SDK heartbeat-loop assertions failed: ${failedChecks.join(', ')}`);

  const heartbeatAcks = context.staleCadence.acknowledgements;
  const lastAck = heartbeatAcks[heartbeatAcks.length - 1] ?? {};
  evidence.outcome = 'pass';
  evidence.runner_blocked = false;
  evidence.classification = `published-${CELL}-sdk-heartbeat-loop-proven`;
  evidence.covered_scenarios = [SCENARIO_ID];
  evidence.scenario_results[SCENARIO_ID] = {
    scenario_id: SCENARIO_ID,
    status: 'pass',
    classification: 'product-behavior-passed',
    observed_outputs: {
      runtime: RUNTIME,
      worker_id: STALE_WORKER_ID,
      peer_worker_id: FRESH_WORKER_ID,
      namespace: NAMESPACE,
      task_queue: TASK_QUEUE,
      registered_types: {
        workflows: [WORKFLOW_TYPE],
        activities: [],
      },
      heartbeat_timestamps: context.staleCadence.sdk_emitted_heartbeat_timestamps,
      heartbeat_timestamp_source: context.staleCadence.cadence_observation_source,
      server_heartbeat_timestamps: context.staleCadence.server_last_heartbeat_timestamps,
      heartbeat_acknowledgements: heartbeatAcks,
      protocol_metadata: {
        registration: context.staleRegistration.registration.registration,
        heartbeat_acknowledgements: heartbeatAcks,
        api_runtime: staleTransition.stale_worker_detail.runtime,
        api_sdk_version: staleTransition.stale_worker_detail.sdk_version,
      },
      heartbeat_interval_seconds: context.staleCadence.advertised_heartbeat_interval_seconds,
      stale_after_seconds: Number(lastAck.stale_after_seconds ?? staleAfterSeconds),
      task_slots: staleTransition.stale_worker_detail.task_slots,
      process_metrics: staleTransition.stale_worker_detail.process_metrics,
      published_artifact_worker_execution: true,
      public_package: IS_RUST_CELL ? 'durable-workflow' : (IS_PYTHON_CELL ? 'durable-workflow' : 'durable-workflow/sdk'),
      public_package_version: SDK_ARTIFACT_VERSION,
      worker_protocol_client: IS_RUST_CELL
        ? 'durable_workflow::Client'
        : (IS_PYTHON_CELL ? 'durable_workflow.Client' : 'DurableWorkflow\\Client'),
      worker_loop: IS_RUST_CELL
        ? ['durable_workflow::Worker::run_until()', 'durable_workflow::Worker::on_worker_heartbeat()']
        : (IS_PYTHON_CELL
          ? ['durable_workflow.Worker.run()', 'durable_workflow.Worker._heartbeat_loop()']
          : [
            'DurableWorkflow\\Worker::tick()',
            'DurableWorkflow\\Client::heartbeatWorker()',
          ]),
      local_product_source_checkouts_used: false,
      real_workflow_execution: {
        before_stale: initialWorkflow,
        after_stale: freshWorkflow,
        causal_work_processed_evidence: {
          before_stale: context.staleWorkProcessed,
          after_stale: context.freshWorkProcessed,
        },
        stale_worker_processed_records: context.staleWorkerLog.work_processed_records,
        fresh_worker_processed_records: context.freshWorkerLog.work_processed_records,
      },
      cadence: context.staleCadence,
      checks,
    },
  };
  evidence.stale_transition = staleTransition;
  evidence.routing_exclusion = {
    stale_worker_id: STALE_WORKER_ID,
    fresh_worker_id: FRESH_WORKER_ID,
    configured_stale_threshold_seconds: staleAfterSeconds,
    observed_stale_transition_timing: staleTransition,
    routing_observations_before_stale: beforeVisibility,
    routing_observations_after_stale: afterVisibility,
    stale_sdk_poll: stalePoll,
    stale_worker_claim_count: Array.isArray(stalePoll.tasks) ? stalePoll.tasks.length : null,
    fresh_worker_eligibility_after_stale: {
      worker_id: FRESH_WORKER_ID,
      eligible: true,
      status: afterVisibility.raw_api.fresh_worker_detail.status,
      completed_workflow_id: freshWorkflow.workflow_id,
    },
    public_surfaces: [
      'POST /api/worker/workflow-tasks/poll',
      'GET /api/workers',
      'GET /api/workers/{workerId}',
      'dw workflow:start --wait',
    ],
    conformance_run_id: RUN_ID,
    timestamp: now(),
  };
  evidence.operator_visibility = afterVisibility;
  evidence.worker_list_snapshots = {
    before_stale: beforeVisibility,
    after_stale: afterVisibility,
  };
  evidence.sdk_worker_logs = {
    stale: context.staleWorkerLog,
    fresh: context.freshWorkerLog,
  };
  evidence.findings = [];
}

try {
  await main();
} catch (error) {
  log(`failure: ${error instanceof Error ? error.stack ?? error.message : String(error)}`);
  if (error instanceof RustCratesIoPreparationTimeoutError) {
    recordRustPreparationTimeout(error);
  } else if ((error instanceof PersistentPostStopDetailTransportError
      || error instanceof PostStopDetailHttpError)
    && executionPhase === 'post_stop_worker_detail'
    && postStopDetailContext?.stop_confirmation?.container_state?.running === false) {
    const serverDiagnostics = await captureServerTransportDiagnostics();
    recordPostStopDetailFailure(error, serverDiagnostics);
  } else if (error instanceof PersistentFinalVisibilityTransportError
    && executionPhase === 'final_operator_visibility'
    && completedBehavior?.all_checks_passed === true) {
    const serverDiagnostics = await captureServerTransportDiagnostics();
    recordPersistentFinalVisibilityFailure(error, serverDiagnostics);
  } else if (error instanceof FinalVisibilityInvariantError
    && executionPhase === 'final_operator_visibility'
    && completedBehavior?.all_checks_passed === true) {
    if (completedContext) completedContext.afterVisibility = error.observedVisibility;
    recordFinalVisibilityInvariantFailure(error);
  } else {
    recordFailure(error);
  }
  process.exitCode = 1;
} finally {
  const cleanupResults = [];
  const cleanupFailures = [];
  for (const containerName of workerContainers) {
    try {
      cleanupResults.push(cleanupWorkerContainer(containerName));
    } catch (error) {
      cleanupFailures.push({ resource: `worker_container:${containerName}`, error: errorSummary(error) });
    }
  }
  for (const cleanup of cleanupCommands.reverse()) {
    try {
      cleanupResults.push(cleanup());
    } catch (error) {
      cleanupFailures.push({ resource: 'compose_project', error: errorSummary(error) });
    }
  }
  if (!KEEP_RUN_ROOT) {
    try {
      fs.rmSync(RUN_ROOT, { recursive: true, force: true });
      cleanupResults.push({ resource: 'run_root', name: path.basename(RUN_ROOT), status: 'removed' });
    } catch (error) {
      cleanupFailures.push({ resource: 'run_root', error: errorSummary(error) });
    }
  } else {
    cleanupResults.push({ resource: 'run_root', name: RUN_ROOT, status: 'retained_by_request' });
  }
  evidence.cleanup = {
    status: cleanupFailures.length === 0 ? 'pass' : 'fail',
    worker_container_names: [...workerContainers],
    results: cleanupResults,
    failures: cleanupFailures,
    finished_at: now(),
  };
  if (cleanupFailures.length > 0) {
    recordCleanupFailure(cleanupFailures);
    process.exitCode = 1;
    log(`cleanup failed: ${cleanupFailures.map((failure) => `${failure.resource}: ${failure.error}`).join('; ')}`);
  }
  try {
    writeResultFiles(completedContext);
  } catch (error) {
    process.exitCode = 1;
    process.stderr.write(`could not write ${CELL} heartbeat evidence: ${errorSummary(error)}\n`);
  }
}
