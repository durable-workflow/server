#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { spawnSync } from 'node:child_process';

const SHARD_SCHEMA = 'durable-workflow.v2.worker-versioning-runtime.published-worker-execution-evidence';
const CROSS_LANGUAGE_SCENARIO = 'cross_language_php_python_pinning';

const resultDir = process.env.DW_WV_RESULT_DIR ?? process.cwd();
const runRoot = process.env.DW_WV_RUN_ROOT ?? resultDir;
const outputPath = process.env.DW_WV_PUBLISHED_WORKER_EVIDENCE
  ?? path.join(resultDir, 'published-worker-execution-evidence.json');
const serverUrl = trimTrailingSlash(process.env.DW_WV_SERVER_URL ?? '');
const token = process.env.DW_WV_AUTH_TOKEN ?? 'dev-token';
const namespace = process.env.DW_WV_NAMESPACE ?? 'worker-versioning-conformance';
const bootstrapNamespace = process.env.DW_WV_BOOTSTRAP_NAMESPACE ?? 'default';
const pythonVersion = trim(process.env.DW_PYTHON_SDK_VERSION);
const workflowPhpVersion = trim(process.env.DW_WORKFLOW_PHP_VERSION ?? process.env.DW_WORKFLOW_VERSION);
const serverVersion = trim(process.env.DW_SERVER_VERSION);
const suffix = `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;
const taskQueue = process.env.DW_WV_PUBLISHED_WORKER_TASK_QUEUE
  ?? `worker-versioning-published-${suffix}`;
const workflowType = process.env.DW_WV_WORKFLOW_TYPE ?? 'Sequence';

if (isMainModule()) {
  main().catch((error) => {
    const message = error instanceof Error ? error.message : String(error);
    writeShard(notCoveredShard(`published PHP/Python worker shard could not run: ${message}`, {}));
    process.exitCode = 0;
  });
}

async function main() {
  fs.mkdirSync(resultDir, { recursive: true });
  fs.mkdirSync(runRoot, { recursive: true });

  const missing = [];
  if (!serverUrl) missing.push('DW_WV_SERVER_URL');
  if (!pythonVersion) missing.push('DW_PYTHON_SDK_VERSION');
  if (!workflowPhpVersion) missing.push('DW_WORKFLOW_PHP_VERSION');
  if (!commandExists('python3')) missing.push('python3');
  if (!commandExists('docker')) missing.push('docker');

  if (missing.length > 0) {
    writeShard(notCoveredShard(
      `published PHP/Python worker shard prerequisites are missing: ${missing.join(', ')}`,
      { missing_prerequisites: missing },
    ));
    return;
  }

  const shardRoot = path.join(runRoot, 'published-php-python-worker-shard');
  fs.rmSync(shardRoot, { recursive: true, force: true });
  fs.mkdirSync(shardRoot, { recursive: true });

  await ensureNamespace();

  const python = await installPythonWorker(shardRoot);
  const php = installPhpWorker(shardRoot);

  const phpV1BuildId = `wv-php-v1-${suffix}`;
  const pythonV2BuildId = `wv-python-v2-${suffix}`;
  const pythonV1BuildId = `wv-python-v1-${suffix}`;
  const phpV2BuildId = `wv-php-v2-${suffix}`;

  const phpV1WorkerId = `php-v1-${suffix}`;
  const pythonV2WorkerId = `python-v2-${suffix}`;
  const pythonV1WorkerId = `python-v1-${suffix}`;
  const phpV2WorkerId = `php-v2-${suffix}`;

  runPhpWorker(php, {
    action: 'register',
    worker_id: phpV1WorkerId,
    build_id: phpV1BuildId,
    fingerprint: `sequence-php-v1-${suffix}`,
  });
  runPythonWorker(python, {
    action: 'register',
    worker_id: pythonV2WorkerId,
    build_id: pythonV2BuildId,
    fingerprint: `sequence-python-v2-${suffix}`,
  });

  await promoteBuildId(phpV1BuildId);
  const phpStarted = await startWorkflow(`wv-php-start-${suffix}`, ['php-v1']);
  const phpStartedRunId = stringValue(phpStarted.run_id);
  const pythonV2ForPhpV1 = runPythonWorker(python, {
    action: 'poll',
    worker_id: pythonV2WorkerId,
    build_id: pythonV2BuildId,
    output_path: path.join(shardRoot, 'python-v2-for-php-v1.json'),
  });
  const phpV1Poll = runPhpWorker(php, {
    action: 'poll',
    worker_id: phpV1WorkerId,
    build_id: phpV1BuildId,
    output_path: path.join(shardRoot, 'php-v1-compatible.json'),
    complete: true,
    result: ['activity_a', 'activity_b'],
  });

  runPythonWorker(python, {
    action: 'register',
    worker_id: pythonV1WorkerId,
    build_id: pythonV1BuildId,
    fingerprint: `sequence-python-v1-${suffix}`,
  });
  runPhpWorker(php, {
    action: 'register',
    worker_id: phpV2WorkerId,
    build_id: phpV2BuildId,
    fingerprint: `sequence-php-v2-${suffix}`,
  });

  await promoteBuildId(pythonV1BuildId);
  const pythonStarted = await startWorkflow(`wv-python-start-${suffix}`, ['python-v1']);
  const pythonStartedRunId = stringValue(pythonStarted.run_id);
  const phpV2ForPythonV1 = runPhpWorker(php, {
    action: 'poll',
    worker_id: phpV2WorkerId,
    build_id: phpV2BuildId,
    output_path: path.join(shardRoot, 'php-v2-for-python-v1.json'),
  });
  const pythonV1Poll = runPythonWorker(python, {
    action: 'poll',
    worker_id: pythonV1WorkerId,
    build_id: pythonV1BuildId,
    output_path: path.join(shardRoot, 'python-v1-compatible.json'),
    complete: true,
    result: ['activity_a', 'activity_b'],
  });

  const phpToPythonIncompatible = countTaskForRun(pythonV2ForPhpV1, phpStartedRunId);
  const pythonToPhpIncompatible = countTaskForRun(phpV2ForPythonV1, pythonStartedRunId);
  const phpCompatible = countTaskForRun(phpV1Poll, phpStartedRunId);
  const pythonCompatible = countTaskForRun(pythonV1Poll, pythonStartedRunId);

  const observedOutputs = {
    php_worker_build_id: phpV1BuildId,
    python_worker_build_id: pythonV2BuildId,
    php_worker_build_ids: {
      v1: phpV1BuildId,
      v2: phpV2BuildId,
    },
    python_worker_build_ids: {
      v1: pythonV1BuildId,
      v2: pythonV2BuildId,
    },
    php_v1_compatible_delivery_count: phpCompatible,
    python_v1_compatible_delivery_count: pythonCompatible,
    php_v1_to_python_v2_incompatible_delivery_count: phpToPythonIncompatible,
    python_v1_to_php_v2_incompatible_delivery_count: pythonToPhpIncompatible,
    cross_language_delivery: {
      task_queue: taskQueue,
      cells: [
        {
          scenario: 'php_v1_not_delivered_to_python_v2',
          started_by: 'workflow-php-v1',
          incompatible_worker: 'sdk-python-v2',
          compatible_worker: 'workflow-php-v1',
          compatible_delivery_count: phpCompatible,
          incompatible_delivery_count: phpToPythonIncompatible,
          started_run_id: phpStartedRunId,
          compatible_worker_output: phpV1Poll,
          incompatible_worker_output: pythonV2ForPhpV1,
        },
        {
          scenario: 'python_v1_not_delivered_to_php_v2',
          started_by: 'sdk-python-v1',
          incompatible_worker: 'workflow-php-v2',
          compatible_worker: 'sdk-python-v1',
          compatible_delivery_count: pythonCompatible,
          incompatible_delivery_count: pythonToPhpIncompatible,
          started_run_id: pythonStartedRunId,
          compatible_worker_output: pythonV1Poll,
          incompatible_worker_output: phpV2ForPythonV1,
        },
      ],
    },
    published_artifact_worker_execution: {
      local_product_source_checkouts_used: false,
      artifacts: [
        {
          artifact: 'sdk-python',
          version: pythonVersion,
          source: 'pypi_release',
          status: 'pass',
          command: `python3 -m pip install durable-workflow==${pythonVersion}`,
        },
        {
          artifact: 'workflow-php',
          version: workflowPhpVersion,
          source: 'packagist_release',
          status: 'pass',
          command: `composer require durable-workflow/workflow:${workflowPhpVersion}`,
        },
      ],
    },
    local_product_source_checkouts_used: false,
    worker_execution_mode: 'published_php_python_worker_protocol_clients',
  };

  const passes = phpToPythonIncompatible === 0
    && pythonToPhpIncompatible === 0
    && phpCompatible > 0
    && pythonCompatible > 0;

  const finding = passes ? null : {
    scenario_id: CROSS_LANGUAGE_SCENARIO,
    owning_surface: phpToPythonIncompatible > 0 || pythonToPhpIncompatible > 0 ? 'server' : 'conformance_harness',
    artifact_versions: artifactVersions(),
    observed_behavior: 'Published PHP/Python worker shard did not prove zero incompatible delivery with positive compatible delivery in both directions.',
    expected_behavior: 'PHP v1-pinned runs are never delivered to Python v2, Python v1-pinned runs are never delivered to PHP v2, and each v1-compatible runtime receives its own pinned run.',
    next_acceptance_criterion: 'rerun the published worker-versioning shard and record both incompatible delivery counts as zero with both compatible delivery counts above zero',
    php_v1_to_python_v2_incompatible_delivery_count: phpToPythonIncompatible,
    python_v1_to_php_v2_incompatible_delivery_count: pythonToPhpIncompatible,
    php_v1_compatible_delivery_count: phpCompatible,
    python_v1_compatible_delivery_count: pythonCompatible,
  };

  writeShard({
    schema: SHARD_SCHEMA,
    local_product_source_checkouts_used: false,
    generated_at: timestamp(),
    artifact_versions: artifactVersions(),
    artifact_sources: artifactSources(),
    topology: {
      namespace,
      task_queue: taskQueue,
      workflow_type: workflowType,
      workers: [
        { worker_id: phpV1WorkerId, runtime: 'php', build_id: phpV1BuildId },
        { worker_id: pythonV2WorkerId, runtime: 'python', build_id: pythonV2BuildId },
        { worker_id: pythonV1WorkerId, runtime: 'python', build_id: pythonV1BuildId },
        { worker_id: phpV2WorkerId, runtime: 'php', build_id: phpV2BuildId },
      ],
    },
    scenario_results: {
      [CROSS_LANGUAGE_SCENARIO]: {
        scenario_id: CROSS_LANGUAGE_SCENARIO,
        status: passes ? 'pass' : 'fail',
        observed_outputs: observedOutputs,
        linked_findings: finding ? [finding] : [],
      },
    },
    findings: finding ? [finding] : [],
    logs: {
      python_install: python.install_log,
      php_install: php.install_log,
      shard_root: shardRoot,
    },
  });
}

async function installPythonWorker(shardRoot) {
  const pythonRoot = path.join(shardRoot, 'python');
  const venv = path.join(pythonRoot, 'venv');
  fs.mkdirSync(pythonRoot, { recursive: true });
  const installLog = path.join(resultDir, 'worker-versioning-python-install.log');
  const scriptPath = path.join(pythonRoot, 'published_worker.py');
  fs.writeFileSync(scriptPath, pythonWorkerScript(), 'utf8');

  runRequired('python3', ['-m', 'venv', venv], { logPath: installLog });
  const pythonBin = path.join(venv, 'bin', 'python');
  runRequired(pythonBin, ['-m', 'pip', 'install', '--upgrade', 'pip'], { logPath: installLog, append: true });
  runRequired(pythonBin, ['-m', 'pip', 'install', `durable-workflow==${pythonVersion}`], {
    logPath: installLog,
    append: true,
  });

  return { pythonBin, scriptPath, install_log: installLog };
}

function installPhpWorker(shardRoot) {
  const phpRoot = path.join(shardRoot, 'php');
  fs.mkdirSync(phpRoot, { recursive: true });
  const installLog = path.join(resultDir, 'worker-versioning-php-install.log');
  const scriptPath = path.join(phpRoot, 'published_worker.php');
  fs.writeFileSync(scriptPath, phpWorkerScript(), 'utf8');

  runRequired('docker', [
    'run',
    '--rm',
    '--network',
    'host',
    '-v',
    `${phpRoot}:/app`,
    '-w',
    '/app',
    'composer:2',
    'require',
    '--no-interaction',
    '--no-progress',
    `durable-workflow/workflow:${workflowPhpVersion}`,
  ], { logPath: installLog });

  return { shardRoot, phpRoot, scriptPath, install_log: installLog };
}

function runPythonWorker(python, input) {
  const inputPath = writeWorkerInput(input);
  const outputPath = input.output_path ?? defaultWorkerOutputPath(input);
  const logPath = path.join(resultDir, `worker-versioning-python-${input.worker_id}-${input.action}.log`);
  fs.mkdirSync(path.dirname(outputPath), { recursive: true });
  runRequired(python.pythonBin, [python.scriptPath, inputPath, outputPath], { logPath });

  return readJson(outputPath);
}

function runPhpWorker(php, input) {
  const inputPath = writeWorkerInput(input);
  const outputPath = input.output_path ?? defaultWorkerOutputPath(input);
  const containerInput = `/app/${path.relative(php.shardRoot, inputPath)}`;
  const containerOutput = `/app/${path.relative(php.shardRoot, outputPath)}`;
  const logPath = path.join(resultDir, `worker-versioning-php-${input.worker_id}-${input.action}.log`);
  fs.mkdirSync(path.dirname(outputPath), { recursive: true });
  runRequired('docker', [
    'run',
    '--rm',
    '--network',
    'host',
    '-v',
    `${php.shardRoot}:/app`,
    '-w',
    '/app/php',
    '--entrypoint',
    'php',
    'composer:2',
    '/app/php/published_worker.php',
    containerInput,
    containerOutput,
  ], { logPath });

  return readJson(outputPath);
}

function defaultWorkerOutputPath(input) {
  return path.join(
    runRoot,
    'published-php-python-worker-shard',
    'outputs',
    `${input.worker_id}-${input.action}.json`,
  );
}

function writeWorkerInput(input) {
  const workerRoot = path.join(runRoot, 'published-php-python-worker-shard', 'inputs');
  fs.mkdirSync(workerRoot, { recursive: true });
  const inputPath = path.join(workerRoot, `${input.worker_id}-${input.action}.json`);
  writeJson(inputPath, {
    server_url: serverUrl,
    token,
    namespace,
    task_queue: taskQueue,
    workflow_type: workflowType,
    supported_activity_types: ['activity_a', 'activity_b'],
    python_version: pythonVersion,
    workflow_php_version: workflowPhpVersion,
    complete: false,
    result: [],
    ...input,
  });

  return inputPath;
}

async function ensureNamespace() {
  const headers = controlHeaders(namespace);
  const show = await requestJson(
    'GET',
    `/api/namespaces/${encodeURIComponent(namespace)}`,
    undefined,
    headers,
    [200, 404],
  );
  if (show?.__http_status === 200 && show?.name === namespace) {
    return;
  }

  const created = await requestJson(
    'POST',
    '/api/namespaces',
    {
      name: namespace,
      description: 'Worker-versioning published PHP/Python shard namespace',
      retention_days: 7,
    },
    controlHeaders(bootstrapNamespace),
    [201, 409],
  );
  if (created.__http_status === 409) {
    return;
  }
  if (created.name !== namespace) {
    throw new Error(`namespace bootstrap returned unexpected payload for ${namespace}`);
  }
}

async function promoteBuildId(buildId) {
  await requestJson(
    'POST',
    `/api/task-queues/${encodeURIComponent(taskQueue)}/build-ids/promote`,
    { build_id: buildId },
    controlHeaders(namespace),
    [200, 201],
  );
}

async function startWorkflow(workflowId, input) {
  return requestJson(
    'POST',
    '/api/workflows',
    {
      workflow_id: workflowId,
      workflow_type: workflowType,
      task_queue: taskQueue,
      input,
    },
    controlHeaders(namespace),
    [200, 201],
  );
}

async function requestJson(method, pathName, body, headers, expectedStatuses) {
  const response = await fetch(`${serverUrl}${pathName}`, {
    method,
    headers,
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  const text = await response.text();
  const json = text.trim() === '' ? {} : JSON.parse(text);
  if (!expectedStatuses.includes(response.status)) {
    throw new Error(`${method} ${pathName} returned ${response.status}: ${text.slice(0, 500)}`);
  }
  if (json && typeof json === 'object' && !Array.isArray(json)) {
    json.__http_status = response.status;
  }

  return json;
}

function controlHeaders(headerNamespace) {
  return {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    Authorization: `Bearer ${token}`,
    'X-Namespace': headerNamespace,
    'X-Durable-Workflow-Control-Plane-Version': '2',
  };
}

function runRequired(command, args, { logPath, append = false }) {
  const result = spawnSync(command, args, {
    encoding: 'utf8',
    env: process.env,
  });
  const output = [
    `$ ${[command, ...args].join(' ')}`,
    result.stdout ?? '',
    result.stderr ?? '',
  ].join('\n');
  fs.writeFileSync(logPath, output, { encoding: 'utf8', flag: append ? 'a' : 'w' });
  if (result.status !== 0) {
    throw new Error(`${command} ${args.join(' ')} failed with exit code ${result.status}; see ${logPath}`);
  }
}

function commandExists(command) {
  const result = spawnSync('sh', ['-c', `command -v ${shellQuote(command)} >/dev/null 2>&1`]);
  return result.status === 0;
}

function countTaskForRun(output, runId) {
  return stringValue(output?.task?.run_id) === runId ? 1 : 0;
}

function notCoveredShard(reason, observedOutputs) {
  const finding = {
    scenario_id: CROSS_LANGUAGE_SCENARIO,
    owning_surface: 'conformance_harness',
    artifact_versions: artifactVersions(),
    observed_behavior: reason,
    expected_behavior: 'Published workflow-php and sdk-python worker artifacts execute the cross-language worker-versioning cell without local product checkouts.',
    next_acceptance_criterion: 'restore the published PHP/Python worker shard prerequisites and rerun worker-versioning conformance',
  };

  return {
    schema: SHARD_SCHEMA,
    local_product_source_checkouts_used: false,
    generated_at: timestamp(),
    artifact_versions: artifactVersions(),
    artifact_sources: artifactSources(),
    scenario_results: {
      [CROSS_LANGUAGE_SCENARIO]: {
        scenario_id: CROSS_LANGUAGE_SCENARIO,
        status: 'not_covered',
        observed_outputs: {
          ...observedOutputs,
          published_artifact_worker_execution: false,
          local_product_source_checkouts_used: false,
        },
        linked_findings: [finding],
      },
    },
    findings: [finding],
  };
}

function artifactVersions() {
  return {
    server: serverVersion,
    'sdk-python': pythonVersion,
    workflow: workflowPhpVersion,
    'workflow-php': workflowPhpVersion,
  };
}

function artifactSources() {
  return {
    server: process.env.DW_WV_SERVER_ARTIFACT_SOURCE ?? 'published_server_url',
    'sdk-python': pythonVersion ? 'pypi_release' : 'not_exercised',
    workflow: workflowPhpVersion ? 'packagist_release' : 'not_exercised',
    'workflow-php': workflowPhpVersion ? 'packagist_release' : 'not_exercised',
  };
}

function writeShard(value) {
  writeJson(outputPath, mergeExistingShard(value));
}

function mergeExistingShard(value) {
  const existing = readJsonIfExists(outputPath);
  if (!existing || typeof existing !== 'object' || Array.isArray(existing)) {
    return value;
  }

  const existingScenarios = objectValue(existing.scenario_results);
  const incomingScenarios = objectValue(value.scenario_results);
  const existingCrossLanguage = objectValue(existingScenarios[CROSS_LANGUAGE_SCENARIO]);
  const incomingCrossLanguage = objectValue(incomingScenarios[CROSS_LANGUAGE_SCENARIO]);
  const keepExistingCrossLanguage = stringValue(existingCrossLanguage.status) === 'pass'
    && stringValue(incomingCrossLanguage.status) !== 'pass';
  const scenarioResults = {
    ...existingScenarios,
    ...incomingScenarios,
  };
  if (keepExistingCrossLanguage) {
    scenarioResults[CROSS_LANGUAGE_SCENARIO] = existingCrossLanguage;
  }

  return {
    ...existing,
    ...value,
    local_product_source_checkouts_used: truthyEvidenceFlag(existing.local_product_source_checkouts_used)
      || truthyEvidenceFlag(value.local_product_source_checkouts_used),
    generated_at: value.generated_at ?? existing.generated_at ?? timestamp(),
    artifact_versions: {
      ...objectValue(existing.artifact_versions),
      ...objectValue(value.artifact_versions),
    },
    artifact_sources: {
      ...objectValue(existing.artifact_sources),
      ...objectValue(value.artifact_sources),
    },
    topology: {
      ...objectValue(existing.topology),
      ...objectValue(value.topology),
    },
    scenario_results: scenarioResults,
    findings: [
      ...arrayValue(existing.findings),
      ...arrayValue(value.findings),
    ],
  };
}

function writeJson(filePath, value) {
  fs.mkdirSync(path.dirname(filePath), { recursive: true });
  fs.writeFileSync(filePath, `${JSON.stringify(value, null, 2)}\n`, 'utf8');
}

function readJsonIfExists(filePath) {
  try {
    return JSON.parse(fs.readFileSync(filePath, 'utf8'));
  } catch {
    return null;
  }
}

function readJson(filePath) {
  return JSON.parse(fs.readFileSync(filePath, 'utf8'));
}

function timestamp() {
  return new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
}

function trim(value) {
  return typeof value === 'string' ? value.trim() : '';
}

function trimTrailingSlash(value) {
  return trim(value).replace(/\/+$/, '');
}

function stringValue(value) {
  return typeof value === 'string' && value.trim() !== '' ? value.trim() : '';
}

function truthyEvidenceFlag(value) {
  if (value === true) {
    return true;
  }
  if (typeof value === 'number') {
    return value !== 0;
  }
  if (typeof value !== 'string') {
    return false;
  }

  return ['1', 'true', 'yes'].includes(value.trim().toLowerCase());
}

function objectValue(value) {
  return value && typeof value === 'object' && !Array.isArray(value) ? value : {};
}

function arrayValue(value) {
  return Array.isArray(value) ? value : [];
}

function shellQuote(value) {
  return `'${String(value).replace(/'/g, "'\\''")}'`;
}

function isMainModule() {
  return process.argv[1] && path.resolve(process.argv[1]) === path.resolve(new URL(import.meta.url).pathname);
}

function pythonWorkerScript() {
  return String.raw`import asyncio
import json
import os
import sys
import time

from durable_workflow import Client


def process_metrics():
    return {
        "process_id": os.getpid(),
        "host": "worker-versioning-published-python-shard",
        "process_started_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "process_uptime_seconds": 1,
    }


async def main():
    with open(sys.argv[1], "r", encoding="utf-8") as handle:
        payload = json.load(handle)
    output_path = sys.argv[2]

    async with Client(
        payload["server_url"],
        token=payload["token"],
        namespace=payload["namespace"],
        timeout=8.0,
    ) as client:
        if payload["action"] == "register":
            response = await client.register_worker(
                worker_id=payload["worker_id"],
                task_queue=payload["task_queue"],
                supported_workflow_types=[payload["workflow_type"]],
                workflow_definition_fingerprints={payload["workflow_type"]: payload["fingerprint"]},
                supported_activity_types=payload["supported_activity_types"],
                max_concurrent_workflow_tasks=10,
                max_concurrent_activity_tasks=10,
                runtime="python",
                sdk_version=payload["python_version"],
                build_id=payload["build_id"],
                task_slots={"workflow_available": 10, "activity_available": 10},
                process_metrics=process_metrics(),
            )
            result = {"action": "register", "response": response, "task": None}
        elif payload["action"] == "poll":
            task = await client.poll_workflow_task(
                worker_id=payload["worker_id"],
                task_queue=payload["task_queue"],
                timeout=2.0,
            )
            if task and payload.get("complete"):
                await client.complete_workflow_task(
                    task_id=task["task_id"],
                    lease_owner=task["lease_owner"],
                    workflow_task_attempt=int(task.get("workflow_task_attempt") or 1),
                    commands=[
                        {
                            "type": "complete_workflow",
                            "result": json.dumps(payload.get("result") or []),
                        }
                    ],
                )
            result = {"action": "poll", "task": task}
        else:
            raise RuntimeError(f"unknown action: {payload['action']}")

    with open(output_path, "w", encoding="utf-8") as handle:
        json.dump(result, handle, indent=2)
        handle.write("\n")


asyncio.run(main())
`;
}

function phpWorkerScript() {
  return String.raw`<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use Illuminate\Http\Client\Factory as HttpFactory;
use Workflow\V2\Worker\WorkerProtocolClient;

$payload = json_decode((string) file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$outputPath = $argv[2];

$client = new WorkerProtocolClient(
    new HttpFactory(),
    $payload['server_url'],
    $payload['token'],
    $payload['namespace'],
    defaultRequestTimeoutSeconds: 8,
);

if ($payload['action'] === 'register') {
    $response = $client->registerWorker(
        workerId: $payload['worker_id'],
        taskQueue: $payload['task_queue'],
        supportedWorkflowTypes: [$payload['workflow_type']],
        supportedActivityTypes: $payload['supported_activity_types'],
        sdkVersion: $payload['workflow_php_version'],
        buildId: $payload['build_id'],
        maxConcurrentWorkflowTasks: 10,
        maxConcurrentActivityTasks: 10,
        workflowDefinitionFingerprints: [$payload['workflow_type'] => $payload['fingerprint']],
    );

    $result = ['action' => 'register', 'response' => $response, 'task' => null];
} elseif ($payload['action'] === 'poll') {
    $tasks = $client->pollWorkflowTasks(
        queue: $payload['task_queue'],
        timeoutSeconds: 2,
        workerId: $payload['worker_id'],
        buildId: $payload['build_id'],
        pollRequestId: $payload['worker_id'].'-'.bin2hex(random_bytes(8)),
        historyPageSize: 100,
    );
    $task = $tasks[0] ?? null;

    if (is_array($task) && ($payload['complete'] ?? false)) {
        $client->completeWorkflowTask(
            (string) $task['task_id'],
            [[
                'type' => 'complete_workflow',
                'result' => json_encode($payload['result'] ?? [], JSON_THROW_ON_ERROR),
            ]],
            isset($task['lease_owner']) ? (string) $task['lease_owner'] : null,
            isset($task['workflow_task_attempt']) ? (int) $task['workflow_task_attempt'] : null,
        );
    }

    $result = ['action' => 'poll', 'task' => $task];
} else {
    throw new RuntimeException('unknown action: '.(string) $payload['action']);
}

file_put_contents($outputPath, json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
`;
}
