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
import { heartbeatCadenceObservation } from './heartbeat-cadence-observation.mjs';

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
const HEARTBEAT_SECONDS = positiveInt(env('DW_HEARTBEATS_HEARTBEAT_SECONDS'), 2);
const CONFIGURED_STALE_AFTER_SECONDS = positiveInt(env('DW_HEARTBEATS_STALE_AFTER_SECONDS'), 7);
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
  artifact_sources: ARTIFACT_SOURCES,
  local_product_source_checkouts_used: false,
  separate_uncovered_cells: SEPARATE_UNCOVERED_CELLS,
  topology: {
    namespace: NAMESPACE,
    task_queue: TASK_QUEUE,
    stale_worker_id: STALE_WORKER_ID,
    fresh_worker_id: FRESH_WORKER_ID,
    workflow_type: WORKFLOW_TYPE,
  },
  scenario_results: {},
  findings: [],
};

let publishedExecutionStarted = false;
let serverBaseUrl = '';
let cliBin = '';

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

function run(command, args, options = {}) {
  const rendered = [command, ...args].join(' ');
  log(`command: ${rendered}`);
  const result = spawnSync(command, args, {
    cwd: options.cwd ?? RUN_ROOT,
    env: options.env ?? process.env,
    encoding: 'utf8',
    maxBuffer: 20 * 1024 * 1024,
    timeout: options.timeout ?? 180_000,
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
    throw new Error(`${rendered} failed (${result.status}): ${(result.stderr || result.stdout || '').trim()}`);
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

function dockerObjectMissing(result) {
  return result.status !== 0 && /no such (?:object|container)/i.test(`${result.stderr}\n${result.stdout}`);
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
  const removalErrors = [];
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
    removalErrors.push(`initial inspection: ${(initialInspect.stderr || initialInspect.stdout).trim()}`);
  }
  for (let attempt = 1; attempt <= 2; attempt += 1) {
    try {
      run('docker', ['rm', '-f', containerName], { timeout: 30_000 });
      break;
    } catch (error) {
      removalErrors.push(`attempt ${attempt}: ${errorSummary(error)}`);
    }
  }
  const finalInspect = run('docker', ['container', 'inspect', containerName], {
    allowFailure: true,
    timeout: 30_000,
  });
  if (!dockerObjectMissing(finalInspect)) {
    removalErrors.push(finalInspect.status === 0
      ? `worker container ${containerName} still exists after docker rm -f`
      : `could not verify removal of worker container ${containerName}: ${(finalInspect.stderr || finalInspect.stdout).trim()}`);
  }
  if (removalErrors.length > 0) throw new Error(removalErrors.join('; '));
  return { resource: 'worker_container', name: containerName, status: 'removed', log_capture: logCapture };
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
  if (!/^\d+\.\d+\.\d+$/.test(SERVER_VERSION)) failures.push('DW_SERVER_VERSION must be an exact patch release');
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
  const response = await fetch(url, { headers: controlPlaneHeaders() });
  const raw = await response.text();
  const body = parseJsonOutput(raw);
  const capture = { timestamp: now(), method: 'GET', url: url.toString(), status: response.status, body };
  requestCaptures.push(capture);
  if (!response.ok) throw new Error(`GET ${url} returned ${response.status}: ${raw}`);
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
    ['query_tasks', 'workflow_updates', 'durable_history_replay', 'graceful_shutdown'],
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
  await waitFor('published server readiness', async () => {
    const response = await fetch(new URL('/api/ready', serverBaseUrl), { headers: controlPlaneHeaders() });
    return response.ok;
  }, 120_000, 1_000);
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
  fs.chmodSync(installer, 0o755);
  run('sh', [installer], {
    env: {
      ...process.env,
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
  run('docker', [
    'run', '--rm',
    ...pythonRuntimeArgs(),
    PYTHON_IMAGE,
    'python', '-m', 'pip', 'install', '--disable-pip-version-check', '--no-cache-dir',
    '--target', '/app/site-packages',
    `durable-workflow==${SDK_PYTHON_VERSION}`,
  ], { timeout: 600_000 });
  const version = run('docker', [
    'run', '--rm',
    ...pythonRuntimeArgs(),
    PYTHON_IMAGE,
    'python', '-c', "from importlib.metadata import version; print(version('durable-workflow'))",
  ], { timeout: 60_000 });
  const installed = normalizeVersion(String(version.stdout).trim());
  if (installed !== SDK_PYTHON_VERSION) {
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
    '-v', `${PROJECT_DIR}:/app`,
    '-w', '/app',
  ];
}

function installRustPackage() {
  if (!commandExists('docker')) throw new Error('docker is required to install the pinned public Rust package');
  writeRustProject();
  run('docker', ['pull', RUST_IMAGE], { timeout: 300_000 });
  try {
    run('docker', [
      'run', '--rm',
      ...rustRuntimeArgs(),
      RUST_IMAGE,
      'cargo', 'generate-lockfile',
    ], { timeout: 600_000 });
  } catch (error) {
    if (/no matching package named|failed to select a version|not found in registry/i.test(errorSummary(error))) {
      publishedExecutionStarted = true;
    }
    throw error;
  }
  // The exact registry version resolved. Failures from this point are defects
  // in the published crate or its compatibility with the pinned server tuple.
  publishedExecutionStarted = true;
  const metadataResult = run('docker', [
    'run', '--rm',
    ...rustRuntimeArgs(),
    RUST_IMAGE,
    'cargo', 'metadata', '--locked', '--format-version=1',
  ], { timeout: 600_000 });
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
  if (releaseMetadata['supported-server-versions'] !== '>=0.2,<0.3') {
    throw new Error('pinned Rust package is missing the supported Durable Workflow server range');
  }
  run('docker', [
    'run', '--rm',
    ...rustRuntimeArgs(),
    RUST_IMAGE,
    'cargo', 'build', '--release', '--locked',
  ], { timeout: 900_000 });

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
  const stoppedAt = now();
  run('docker', ['stop', '--time', '2', worker.container_name], { allowFailure: true, timeout: 30_000 });
  return stoppedAt;
}

function workerLogRecords(worker) {
  const result = run('docker', ['logs', worker.container_name], { allowFailure: true, timeout: 30_000 });
  return String(result.stdout).split(/\r?\n/).flatMap((line) => {
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

async function captureOperatorVisibility(staleWorkerStatus = null) {
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
      worker_list: cli(['worker:list', `--task-queue=${TASK_QUEUE}`]),
      fresh_worker_describe: cli(['worker:describe', FRESH_WORKER_ID]),
      stale_worker_list: staleWorkerStatus ? cli(['worker:list', `--task-queue=${TASK_QUEUE}`, '--status=stale']) : null,
      stale_worker_describe: staleWorkerStatus ? cli(['worker:describe', STALE_WORKER_ID]) : null,
    },
  };
}

function workersFromList(payload) {
  return Array.isArray(payload?.workers) ? payload.workers : [];
}

async function waitForStaleTransition(stoppedAt, staleAfterSeconds) {
  return waitFor(`${STALE_WORKER_ID} stale transition`, async () => {
    const detail = await api(`/workers/${encodeURIComponent(STALE_WORKER_ID)}`);
    const active = await api('/workers', { task_queue: TASK_QUEUE });
    const stale = await api('/workers', { task_queue: TASK_QUEUE, status: 'stale' });
    const activeIds = workersFromList(active).map((worker) => worker.worker_id);
    const staleIds = workersFromList(stale).map((worker) => worker.worker_id);
    if (detail.status !== 'stale' || activeIds.includes(STALE_WORKER_ID) || !staleIds.includes(STALE_WORKER_ID)) return null;
    const observedStaleAt = now();
    const transitionElapsedSeconds = (Date.parse(observedStaleAt) - Date.parse(stoppedAt)) / 1_000;
    const boundedMaxSeconds = staleAfterSeconds + 5;
    return {
      stopped_at: stoppedAt,
      observed_stale_at: observedStaleAt,
      stale_after_seconds: staleAfterSeconds,
      transition_elapsed_seconds: transitionElapsedSeconds,
      bounded_max_seconds: boundedMaxSeconds,
      within_bounded_window: transitionElapsedSeconds >= 0 && transitionElapsedSeconds <= boundedMaxSeconds,
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
        ? evidence.python_package_install?.installed_version === SDK_PYTHON_VERSION
        : (IS_RUST_CELL
          ? evidence.rust_package_install?.installed_version === SDK_RUST_VERSION
            && evidence.rust_package_install?.resolved_registry_source?.startsWith('registry+')
            && /^[0-9a-f]{64}$/.test(evidence.rust_package_install?.registry_checksum_sha256 ?? '')
          : evidence.php_package_install?.installed_version === SDK_PHP_VERSION))
      && evidence.cli_install?.detected_version === CLI_VERSION,
    real_workflow_completed_by_sdk_loop: completedWorkflow(context.initialWorkflow)
      && completedWorkflow(context.freshWorkflow)
      && context.staleWorkerLog.work_processed_records.length >= 1
      && context.freshWorkerLog.work_processed_records.length >= 1,
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

function writeResultFiles(context = null) {
  const finishedAt = now();
  evidence.finished_at = finishedAt;
  evidence.generated_at = finishedAt;
  evidence.artifact_sources = ARTIFACT_SOURCES;

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
  evidence.outcome = publishedExecutionStarted ? 'fail' : 'runner_blocked';
  evidence.runner_blocked = !publishedExecutionStarted;
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
  publishedExecutionStarted = true;

  const staleWorker = startWorker(STALE_WORKER_ID);
  const staleRegistration = await waitForWorkerRegistration(staleWorker);
  const staleCadence = await observeSuccessiveHeartbeats(staleWorker, staleRegistration);
  const initialWorkflow = startWorkflow('initial');
  if (!completedWorkflow(initialWorkflow)) throw new Error(`the initial real workflow did not complete through the ${CELL} worker loop`);

  const freshWorker = startWorker(FRESH_WORKER_ID);
  const freshRegistration = await waitForWorkerRegistration(freshWorker);
  const freshCadence = await observeSuccessiveHeartbeats(freshWorker, freshRegistration);
  const beforeVisibility = await captureOperatorVisibility();

  const stoppedAt = stopWorker(staleWorker);
  const staleAfterSeconds = Number(staleRegistration.registration.registration?.stale_after_seconds
    ?? staleRegistration.detail.stale_after_seconds
    ?? CONFIGURED_STALE_AFTER_SECONDS);
  const staleTransition = await waitForStaleTransition(stoppedAt, staleAfterSeconds);
  const stalePoll = stalePollProbe();
  const freshWorkflow = startWorkflow('fresh-after-stale');
  if (!completedWorkflow(freshWorkflow)) throw new Error(`the fresh ${CELL} worker did not complete work after its peer became stale`);
  const afterVisibility = await captureOperatorVisibility('stale');
  stopWorker(freshWorker);

  const context = {
    staleWorker,
    freshWorker,
    staleRegistration,
    freshRegistration,
    staleCadence,
    freshCadence,
    initialWorkflow,
    freshWorkflow,
    beforeVisibility,
    afterVisibility,
    staleTransition,
    stalePoll,
    staleWorkerLog: workerLogEvidence(staleWorker),
    freshWorkerLog: workerLogEvidence(freshWorker),
  };
  completedContext = context;
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
  recordFailure(error);
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
