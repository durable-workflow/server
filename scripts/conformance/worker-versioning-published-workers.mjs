#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { spawnSync } from 'node:child_process';

const SHARD_SCHEMA = 'durable-workflow.v2.worker-versioning-runtime.published-worker-execution-evidence';
const REGISTRATION_SCENARIO = 'worker_registration_build_ids';
const REPLAY_SCENARIO = 'replay_only_by_compatible_workers';
const CACHE_EVICTION_SCENARIO = 'replay_across_cache_eviction';
const NO_COMPATIBLE_SCENARIO = 'no_compatible_worker_behavior';
const CROSS_LANGUAGE_SCENARIO = 'cross_language_php_python_pinning';
const ADVERSARIAL_SCENARIO = 'adversarial_no_version_bump';

const resultDir = process.env.DW_WV_RESULT_DIR ?? process.cwd();
const runRoot = process.env.DW_WV_RUN_ROOT ?? resultDir;
const outputPath = process.env.DW_WV_PUBLISHED_WORKER_EVIDENCE
  ?? path.join(resultDir, 'published-worker-execution-evidence.json');
const serverUrl = trimTrailingSlash(process.env.DW_WV_SERVER_URL ?? '');
const token = process.env.DW_WV_AUTH_TOKEN ?? 'dev-token';
const namespace = process.env.DW_WV_NAMESPACE ?? 'worker-versioning-conformance';
const bootstrapNamespace = process.env.DW_WV_BOOTSTRAP_NAMESPACE ?? 'default';
const pythonVersion = trim(process.env.DW_PYTHON_SDK_VERSION);
const sdkPhpVersion = trim(process.env.DW_PHP_SDK_VERSION);
const serverVersion = trim(process.env.DW_SERVER_VERSION);
const suffix = `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;
const taskQueue = process.env.DW_WV_PUBLISHED_WORKER_TASK_QUEUE
  ?? `worker-versioning-published-${suffix}`;
const workflowType = process.env.DW_WV_WORKFLOW_TYPE ?? 'Sequence';
const noCompatibleVisibilitySeconds = Math.max(1, numberValue(
  process.env.DW_WV_NO_COMPATIBLE_VISIBILITY_SECONDS
    ?? process.env.DW_WV_WORKER_VERSIONING_NO_COMPATIBLE_VISIBILITY_SECONDS,
) ?? 60);

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
  if (!commandExists('python3')) missing.push('python3');

  if (missing.length > 0) {
    writeShard(notCoveredShard(
      `published Python worker shard prerequisites are missing: ${missing.join(', ')}`,
      { missing_prerequisites: missing },
    ));
    return;
  }

  const shardRoot = path.join(runRoot, 'published-php-python-worker-shard');
  fs.rmSync(shardRoot, { recursive: true, force: true });
  fs.mkdirSync(shardRoot, { recursive: true });

  await ensureNamespace();

  const python = await installPythonWorker(shardRoot);
  let pythonReplay = emptySupplementalShard();
  let pythonNoCompatible = emptySupplementalShard();
  let pythonAdversarial = emptySupplementalShard();

  const crossLanguageMissing = [];
  if (!sdkPhpVersion) crossLanguageMissing.push('DW_PHP_SDK_VERSION');
  if (!commandExists('docker')) crossLanguageMissing.push('docker');

  if (crossLanguageMissing.length > 0) {
    writeShard(notCoveredShard(
      `published PHP/Python cross-language worker shard prerequisites are missing: ${crossLanguageMissing.join(', ')}`,
      { missing_prerequisites: crossLanguageMissing },
    ));
    return;
  }

  const php = installPhpWorker(shardRoot);

  const phpV1BuildId = `wv-php-v1-${suffix}`;
  const pythonV2BuildId = `wv-python-v2-${suffix}`;
  const pythonV1BuildId = `wv-python-v1-${suffix}`;
  const phpV2BuildId = `wv-php-v2-${suffix}`;

  const phpV1WorkerId = `php-v1-${suffix}`;
  const pythonV2WorkerId = `python-v2-${suffix}`;
  const pythonV1WorkerId = `python-v1-${suffix}`;
  const phpV2WorkerId = `php-v2-${suffix}`;

  const phpV1Registration = runPhpWorker(php, {
    action: 'register',
    worker_id: phpV1WorkerId,
    build_id: phpV1BuildId,
    fingerprint: `sequence-php-v1-${suffix}`,
  });
  const pythonV2Registration = runPythonWorker(python, {
    action: 'register',
    worker_id: pythonV2WorkerId,
    build_id: pythonV2BuildId,
    fingerprint: `sequence-python-v2-${suffix}`,
  });
  const registrationWorkerList = await workerList();
  const registrationBuildIds = await taskQueueBuildIds();

  await promoteBuildId(phpV1BuildId);
  const phpV1RolloutState = await taskQueueBuildIds();
  const phpStartedWorkflowId = `wv-php-start-${suffix}`;
  const phpStarted = await startWorkflow(phpStartedWorkflowId, ['php-v1']);
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
  const pythonV1RolloutState = await taskQueueBuildIds();
  const pythonStartedWorkflowId = `wv-python-start-${suffix}`;
  const pythonStarted = await startWorkflow(pythonStartedWorkflowId, ['python-v1']);
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
  const passes = phpToPythonIncompatible === 0
    && pythonToPhpIncompatible === 0
    && phpCompatible > 0
    && pythonCompatible > 0;
  const registrationBuildIdValues = unique([
    ...arrayValue(registrationBuildIds.build_ids).map((entry) => stringValue(entry?.build_id)),
  ].filter(Boolean));
  const registrationWorkerListBuildIds = unique([
    ...arrayValue(registrationWorkerList.workers).map((worker) => stringValue(worker?.build_id)),
  ].filter(Boolean));
  const registrationActiveWorkerCounts = Object.fromEntries(
    arrayValue(registrationBuildIds.build_ids)
      .map((entry) => [
        stringValue(entry?.build_id),
        numberValue(entry?.active_worker_count) ?? 0,
      ])
      .filter(([buildId]) => buildId !== ''),
  );
  const registrationWorkerExecution = {
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
        artifact: 'sdk-php',
        version: sdkPhpVersion,
        source: 'packagist_release',
        status: 'pass',
        command: `composer require durable-workflow/sdk:${sdkPhpVersion}`,
      },
    ],
  };
  const registrationOutputs = {
    task_queue: taskQueue,
    registered_build_ids: {
      sdk_php: phpV1BuildId,
      sdk_python: pythonV2BuildId,
      [phpV1WorkerId]: phpV1BuildId,
      [pythonV2WorkerId]: pythonV2BuildId,
    },
    worker_registration_responses: {
      sdk_php: {
        artifact: 'sdk-php',
        runtime: 'php',
        worker_id: phpV1WorkerId,
        task_queue: taskQueue,
        build_id: phpV1BuildId,
        response: phpV1Registration.response,
        raw_response: phpV1Registration,
      },
      sdk_python: {
        artifact: 'sdk-python',
        runtime: 'python',
        worker_id: pythonV2WorkerId,
        task_queue: taskQueue,
        build_id: pythonV2BuildId,
        response: pythonV2Registration.response,
        raw_response: pythonV2Registration,
      },
    },
    published_worker_registration_entries: [
      {
        artifact: 'sdk-php',
        runtime: 'php',
        worker_id: phpV1WorkerId,
        task_queue: taskQueue,
        build_id: phpV1BuildId,
        response_build_id: stringValue(phpV1Registration.response?.build_id),
      },
      {
        artifact: 'sdk-python',
        runtime: 'python',
        worker_id: pythonV2WorkerId,
        task_queue: taskQueue,
        build_id: pythonV2BuildId,
        response_build_id: stringValue(pythonV2Registration.response?.build_id),
      },
    ],
    php_worker_registration_response: phpV1Registration,
    python_worker_registration_response: pythonV2Registration,
    worker_list_build_ids: registrationWorkerListBuildIds,
    task_queue_build_ids: registrationBuildIdValues,
    active_worker_counts_per_cohort: registrationActiveWorkerCounts,
    worker_list_surface: registrationWorkerList,
    task_queue_build_id_surface: registrationBuildIds,
    public_outcome: {
      verification_surface: 'published worker registration responses plus worker-list and task-queue build-id APIs',
      passed: registrationWorkerListBuildIds.includes(phpV1BuildId)
        && registrationWorkerListBuildIds.includes(pythonV2BuildId)
        && registrationBuildIdValues.includes(phpV1BuildId)
        && registrationBuildIdValues.includes(pythonV2BuildId)
        && (registrationActiveWorkerCounts[phpV1BuildId] ?? 0) > 0
        && (registrationActiveWorkerCounts[pythonV2BuildId] ?? 0) > 0,
    },
    published_artifact_worker_execution: registrationWorkerExecution,
    local_product_source_checkouts_used: false,
    worker_execution_mode: 'published_php_python_worker_protocol_clients',
  };
  const registrationPasses = registrationOutputs.public_outcome.passed
    && stringValue(phpV1Registration.response?.build_id) === phpV1BuildId
    && stringValue(pythonV2Registration.response?.build_id) === pythonV2BuildId;
  const registrationFinding = registrationPasses ? null : {
    scenario_id: REGISTRATION_SCENARIO,
    owning_surface: registrationOutputs.public_outcome.passed ? 'conformance_harness' : 'server',
    artifact_versions: artifactVersions(),
    observed_behavior: 'Published PHP/Python worker registration evidence did not prove both registered build-id cohorts through the public worker-list and task-queue build-id surfaces.',
    expected_behavior: 'Published sdk-php and sdk-python worker artifacts register on the same task queue, echo the requested build IDs, and appear as active cohorts through public operator surfaces.',
    next_acceptance_criterion: 'rerun the published worker-versioning shard and record PHP and Python registration responses, worker-list build IDs, task-queue build IDs, and positive active worker counts for both cohorts on the same task queue',
    worker_list_build_ids: registrationWorkerListBuildIds,
    task_queue_build_ids: registrationBuildIdValues,
    active_worker_counts_per_cohort: registrationActiveWorkerCounts,
  };

  const observedOutputs = {
    php_worker_build_id: phpV1BuildId,
    python_worker_build_id: pythonV2BuildId,
    worker_runtime_identities: [
      { worker_id: phpV1WorkerId, runtime: 'php', language: 'php', build_id: phpV1BuildId },
      { worker_id: pythonV2WorkerId, runtime: 'python', language: 'python', build_id: pythonV2BuildId },
      { worker_id: pythonV1WorkerId, runtime: 'python', language: 'python', build_id: pythonV1BuildId },
      { worker_id: phpV2WorkerId, runtime: 'php', language: 'php', build_id: phpV2BuildId },
    ],
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
    workflow_runs: {
      php_v1_started: {
        workflow_id: phpStartedWorkflowId,
        run_id: phpStartedRunId,
        started_by_runtime: 'php',
        pinned_build_id: phpV1BuildId,
        compatible_worker_runtime: 'php',
        incompatible_worker_runtime: 'python',
      },
      python_v1_started: {
        workflow_id: pythonStartedWorkflowId,
        run_id: pythonStartedRunId,
        started_by_runtime: 'python',
        pinned_build_id: pythonV1BuildId,
        compatible_worker_runtime: 'python',
        incompatible_worker_runtime: 'php',
      },
    },
    rollout_state: {
      after_php_v1_promotion: phpV1RolloutState,
      after_python_v1_promotion: pythonV1RolloutState,
      promoted_build_ids: {
        php_started_run: phpV1BuildId,
        python_started_run: pythonV1BuildId,
      },
    },
    cross_language_delivery: {
      task_queue: taskQueue,
      cells: [
        {
          scenario: 'php_v1_not_delivered_to_python_v2',
          started_by: 'sdk-php-v1',
          incompatible_worker: 'sdk-python-v2',
          compatible_worker: 'sdk-php-v1',
          compatible_delivery_count: phpCompatible,
          incompatible_delivery_count: phpToPythonIncompatible,
          workflow_id: phpStartedWorkflowId,
          run_id: phpStartedRunId,
          started_run_id: phpStartedRunId,
          compatible_worker_output: phpV1Poll,
          incompatible_worker_output: pythonV2ForPhpV1,
        },
        {
          scenario: 'python_v1_not_delivered_to_php_v2',
          started_by: 'sdk-python-v1',
          incompatible_worker: 'sdk-php-v2',
          compatible_worker: 'sdk-python-v1',
          compatible_delivery_count: pythonCompatible,
          incompatible_delivery_count: pythonToPhpIncompatible,
          workflow_id: pythonStartedWorkflowId,
          run_id: pythonStartedRunId,
          started_run_id: pythonStartedRunId,
          compatible_worker_output: pythonV1Poll,
          incompatible_worker_output: phpV2ForPythonV1,
        },
      ],
    },
    public_outcome: {
      verification_surface: 'published worker poll outputs and task-queue build-id rollout API',
      passed: passes,
      php_v1_to_python_v2_incompatible_delivery_count: phpToPythonIncompatible,
      python_v1_to_php_v2_incompatible_delivery_count: pythonToPhpIncompatible,
      php_v1_compatible_delivery_count: phpCompatible,
      python_v1_compatible_delivery_count: pythonCompatible,
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
          artifact: 'sdk-php',
          version: sdkPhpVersion,
          source: 'packagist_release',
          status: 'pass',
          command: `composer require durable-workflow/sdk:${sdkPhpVersion}`,
        },
      ],
    },
    local_product_source_checkouts_used: false,
    worker_execution_mode: 'published_php_python_worker_protocol_clients',
  };

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

  const publishedPhpPythonShard = {
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
        ...pythonReplay.workers,
        ...pythonNoCompatible.workers,
        ...pythonAdversarial.workers,
        { worker_id: phpV1WorkerId, runtime: 'php', build_id: phpV1BuildId },
        { worker_id: pythonV2WorkerId, runtime: 'python', build_id: pythonV2BuildId },
        { worker_id: pythonV1WorkerId, runtime: 'python', build_id: pythonV1BuildId },
        { worker_id: phpV2WorkerId, runtime: 'php', build_id: phpV2BuildId },
      ],
    },
    scenario_results: {
      [REGISTRATION_SCENARIO]: {
        scenario_id: REGISTRATION_SCENARIO,
        status: registrationPasses ? 'pass' : 'fail',
        observed_outputs: registrationOutputs,
        linked_findings: registrationFinding ? [registrationFinding] : [],
      },
      [CROSS_LANGUAGE_SCENARIO]: {
        scenario_id: CROSS_LANGUAGE_SCENARIO,
        status: passes ? 'pass' : 'fail',
        observed_outputs: observedOutputs,
        linked_findings: finding ? [finding] : [],
      },
    },
    findings: [
      ...(registrationFinding ? [registrationFinding] : []),
      ...(finding ? [finding] : []),
    ],
    logs: {
      python_install: python.install_log,
      php_install: php.install_log,
      shard_root: shardRoot,
    },
  };
  const baseShard = writeShard(publishedPhpPythonShard);

  pythonReplay = await runPythonReplayShardSafely(python);
  pythonNoCompatible = await runPythonNoCompatibleShardSafely(python);
  pythonAdversarial = await runPythonAdversarialShardSafely(python);
  const supplementalShard = pythonScenarioShard(
    python,
    shardRoot,
    pythonReplay,
    pythonNoCompatible,
    pythonAdversarial,
  );
  writeJson(outputPath, mergeShardValues(baseShard, supplementalShard));
}

function emptySupplementalShard() {
  return {
    workers: [],
    scenario_results: {},
    findings: [],
  };
}

function pythonScenarioShard(python, shardRoot, pythonReplay, pythonNoCompatible, pythonAdversarial) {
  return {
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
        ...pythonReplay.workers,
        ...pythonNoCompatible.workers,
        ...pythonAdversarial.workers,
      ],
    },
    scenario_results: {
      ...pythonReplay.scenario_results,
      ...pythonNoCompatible.scenario_results,
      ...pythonAdversarial.scenario_results,
    },
    findings: [
      ...pythonReplay.findings,
      ...pythonNoCompatible.findings,
      ...pythonAdversarial.findings,
    ],
    logs: {
      python_install: python.install_log,
      shard_root: shardRoot,
    },
  };
}

async function runPythonNoCompatibleShardSafely(python) {
  try {
    return await runPythonNoCompatibleShard(python);
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return notCoveredPythonNoCompatibleShard(message);
  }
}

async function runPythonNoCompatibleShard(python) {
  const noCompatibleV1BuildId = `wv-python-no-compatible-v1-${suffix}`;
  const noCompatibleV2BuildId = `wv-python-no-compatible-v2-${suffix}`;
  const noCompatibleV1WorkerId = `python-no-compatible-v1-${suffix}`;
  const noCompatibleV2WorkerId = `python-no-compatible-v2-${suffix}`;
  const noCompatibleWorkflowId = `wv-python-no-compatible-${suffix}`;
  const noCompatibleRoot = path.join(runRoot, 'published-php-python-worker-shard', 'no-compatible');

  runPythonWorker(python, {
    action: 'register',
    worker_id: noCompatibleV1WorkerId,
    build_id: noCompatibleV1BuildId,
    fingerprint: `sequence-python-no-compatible-v1-${suffix}`,
    output_path: path.join(noCompatibleRoot, 'python-no-compatible-v1-register.json'),
  });
  runPythonWorker(python, {
    action: 'register',
    worker_id: noCompatibleV2WorkerId,
    build_id: noCompatibleV2BuildId,
    fingerprint: `sequence-python-no-compatible-v2-${suffix}`,
    output_path: path.join(noCompatibleRoot, 'python-no-compatible-v2-register.json'),
  });

  await promoteBuildId(noCompatibleV1BuildId);
  const started = await startWorkflow(noCompatibleWorkflowId, ['python-no-compatible-v1']);
  const runId = stringValue(started.run_id);
  const pinnedRunBuildId = stringValue(started.compatibility) || noCompatibleV1BuildId;
  const startedWorkflowVisibility = runId === ''
    ? {}
    : await workflowVisibility(noCompatibleWorkflowId, runId);
  const deregisterResponse = await requestJson(
    'DELETE',
    `/api/workers/${encodeURIComponent(noCompatibleV1WorkerId)}`,
    undefined,
    controlHeaders(namespace),
    [200, 404],
  );

  await sleep(1200);

  const incompatiblePolls = [];
  for (let attempt = 1; attempt <= 3; attempt += 1) {
    const poll = runPythonWorker(python, {
      action: 'raw_poll',
      worker_id: noCompatibleV2WorkerId,
      build_id: noCompatibleV2BuildId,
      output_path: path.join(noCompatibleRoot, `python-no-compatible-v2-poll-${attempt}.json`),
    });
    incompatiblePolls.push(poll);

    if (countTaskForRun(poll, runId) > 0 || isExplicitNoCompatibleSignal(
      stringValue(poll.poll_status) || stringValue(poll.response?.poll_status),
    )) {
      break;
    }
  }
  const workflowVisibilityResult = runId === ''
    ? emptyNoCompatibleVisibilityResult()
    : await waitForNoCompatibleVisibility(noCompatibleWorkflowId, runId, noCompatibleV1BuildId);
  const latestWorkflowVisibility = workflowVisibilityResult.latest;
  const noCompatibleBuildIds = workflowVisibilityResult.task_queue_build_ids;
  const noCompatibleBuildIdEntry = workflowVisibilityResult.task_queue_build_id_entry;
  const incompatiblePollStatuses = incompatiblePolls
    .map((poll) => stringValue(poll.poll_status) || stringValue(poll.response?.poll_status))
    .filter(Boolean);
  const incompatibleWorkerPollErrorCount = incompatiblePollStatuses
    .filter((pollStatus) => isGenericPollErrorStatus(pollStatus))
    .length;
  const operatorVisibleSignal = stringValue(firstExplicitNoCompatibleSignal(
    ...incompatiblePollStatuses,
    latestWorkflowVisibility.compatibility_status,
    latestWorkflowVisibility.compatibilityStatus,
    latestWorkflowVisibility.compatibility_fleet_reason,
    latestWorkflowVisibility.compatibilityFleetReason,
    ...workflowVisibilityResult.samples.map((sample) => sample.compatibility_status),
    ...workflowVisibilityResult.samples.map((sample) => sample.compatibilityStatus),
    ...workflowVisibilityResult.samples.map((sample) => sample.compatibility_fleet_reason),
    ...workflowVisibilityResult.samples.map((sample) => sample.compatibilityFleetReason),
    ...pendingWorkflowTaskDiagnosticSignals(noCompatibleBuildIdEntry),
  ));
  const pendingOrTypedError = isExplicitNoCompatibleSignal(operatorVisibleSignal)
    ? operatorVisibleSignal
    : 'pending';
  const incompatibleWorkerTaskCount = incompatiblePolls
    .reduce((count, poll) => count + countTaskForRun(poll, runId), 0);
  const compatibleWorkerDeregistered = numberValue(deregisterResponse.__http_status) === 200;
  const workerExecution = publishedPythonWorkerExecution();
  const observedOutputs = {
    operator_visible_signal: operatorVisibleSignal,
    operator_visible_signal_explicit: isExplicitNoCompatibleSignal(operatorVisibleSignal),
    pending_or_typed_error: pendingOrTypedError,
    incompatible_worker_task_count: incompatibleWorkerTaskCount,
    workflow_id: noCompatibleWorkflowId,
    v1_pinned_run_id: runId,
    pinned_run_build_id: pinnedRunBuildId,
    started_workflow_visibility: startedWorkflowVisibility,
    v1_worker_build_id: noCompatibleV1BuildId,
    v2_worker_build_id: noCompatibleV2BuildId,
    compatible_worker_deregistered: compatibleWorkerDeregistered,
    deregister_response: deregisterResponse,
    incompatible_worker_poll_attempts: incompatiblePolls.length,
    incompatible_worker_poll_statuses: incompatiblePollStatuses,
    incompatible_worker_poll_error_count: incompatibleWorkerPollErrorCount,
    incompatible_worker_polls: incompatiblePolls,
    workflow_visibility: latestWorkflowVisibility,
    workflow_visibility_samples: workflowVisibilityResult.samples,
    task_queue_build_ids: noCompatibleBuildIds,
    task_queue_build_id_entry: noCompatibleBuildIdEntry,
    task_queue_build_id_samples: workflowVisibilityResult.task_queue_build_id_samples,
    no_compatible_visibility_deadline_seconds: noCompatibleVisibilitySeconds,
    no_compatible_visibility_attempts: workflowVisibilityResult.attempts,
    worker_execution_mode: 'published_python_worker_protocol_client',
    published_artifact_worker_execution: workerExecution,
    local_product_source_checkouts_used: false,
  };
  const passes = runId !== ''
    && compatibleWorkerDeregistered
    && incompatibleWorkerTaskCount === 0
    && incompatibleWorkerPollErrorCount === 0
    && isExplicitNoCompatibleSignal(operatorVisibleSignal)
    && (
      pendingOrTypedError === 'pending'
      || isExplicitNoCompatibleSignal(pendingOrTypedError)
    );
  const finding = passes ? null : {
    scenario_id: NO_COMPATIBLE_SCENARIO,
    owning_surface: incompatibleWorkerTaskCount > 0 || operatorVisibleSignal === '' ? 'server' : 'conformance_harness',
    artifact_versions: artifactVersions(),
    observed_behavior: incompatibleWorkerTaskCount > 0
      ? 'A published Python v2 worker received a task for a v1-pinned run after the v1-compatible worker was deregistered.'
      : incompatibleWorkerPollErrorCount > 0
        ? 'Published Python no-compatible-worker evidence included generic raw poll errors, so the incompatible worker poll could not prove zero task delivery.'
        : 'Published Python no-compatible-worker evidence did not prove the v1 worker was deregistered and paired with an explicit public signal.',
    expected_behavior: 'A v1-pinned workflow with no compatible registered worker remains unclaimed by v2 workers and exposes a typed no-compatible-worker or compatibility-blocked signal.',
    next_acceptance_criterion: 'rerun the published worker-versioning shard and record incompatible_worker_task_count equal to zero plus an explicit no-compatible-worker or compatibility-blocked signal from the published Python worker poll',
    incompatible_worker_task_count: incompatibleWorkerTaskCount,
    incompatible_worker_poll_error_count: incompatibleWorkerPollErrorCount,
    compatible_worker_deregistered: compatibleWorkerDeregistered,
    operator_visible_signal: operatorVisibleSignal,
    v1_pinned_run_id: runId,
  };

  return {
    workers: [
      { worker_id: noCompatibleV1WorkerId, runtime: 'python', build_id: noCompatibleV1BuildId },
      { worker_id: noCompatibleV2WorkerId, runtime: 'python', build_id: noCompatibleV2BuildId },
    ],
    scenario_results: {
      [NO_COMPATIBLE_SCENARIO]: {
        scenario_id: NO_COMPATIBLE_SCENARIO,
        status: passes ? 'pass' : 'fail',
        observed_outputs: observedOutputs,
        linked_findings: finding ? [finding] : [],
      },
    },
    findings: finding ? [finding] : [],
  };
}

function notCoveredPythonNoCompatibleShard(reason) {
  const finding = {
    scenario_id: NO_COMPATIBLE_SCENARIO,
    owning_surface: 'conformance_harness',
    artifact_versions: artifactVersions(),
    observed_behavior: `Published Python no-compatible-worker shard could not complete: ${reason}`,
    expected_behavior: 'A v1-pinned workflow with no compatible registered worker remains unclaimed by v2 workers and exposes a typed no-compatible-worker or compatibility-blocked signal.',
    next_acceptance_criterion: 'rerun the published worker-versioning shard and record zero incompatible delivery plus an explicit public no-compatible-worker diagnostic from published Python worker execution',
  };

  return {
    workers: [],
    scenario_results: {
      [NO_COMPATIBLE_SCENARIO]: {
        scenario_id: NO_COMPATIBLE_SCENARIO,
        status: 'not_covered',
        observed_outputs: {
          shard_error: reason,
          worker_execution_mode: 'published_python_worker_protocol_client',
          published_artifact_worker_execution: publishedPythonWorkerExecution(),
          local_product_source_checkouts_used: false,
        },
        linked_findings: [finding],
      },
    },
    findings: [finding],
  };
}

async function runPythonAdversarialShardSafely(python) {
  try {
    return await runPythonAdversarialShard(python);
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return notCoveredPythonAdversarialShard(message);
  }
}

async function runPythonAdversarialShard(python) {
  const adversarialBuildId = `wv-python-adversarial-v1-${suffix}`;
  const adversarialWorkerId = `python-adversarial-${suffix}`;
  const adversarialRoot = path.join(runRoot, 'published-php-python-worker-shard', 'adversarial');
  const v1Fingerprint = `sequence-python-adversarial-v1-${suffix}`;
  const divergentFingerprint = `sequence-python-adversarial-v2-divergent-${suffix}`;

  runPythonWorker(python, {
    action: 'register',
    worker_id: adversarialWorkerId,
    build_id: adversarialBuildId,
    fingerprint: v1Fingerprint,
    output_path: path.join(adversarialRoot, 'python-adversarial-v1-register.json'),
  });

  const changedRegister = runPythonWorker(python, {
    action: 'register',
    worker_id: adversarialWorkerId,
    build_id: adversarialBuildId,
    fingerprint: divergentFingerprint,
    allow_register_error: true,
    output_path: path.join(adversarialRoot, 'python-adversarial-divergent-reregister.json'),
  });
  const rolloutState = await requestJson(
    'GET',
    `/api/task-queues/${encodeURIComponent(taskQueue)}/build-ids`,
    undefined,
    controlHeaders(namespace),
    [200],
  );

  const httpStatus = numberValue(changedRegister.http_status);
  const reason = stringValue(changedRegister.reason)
    || stringValue(changedRegister.response?.reason);
  const rejectedDefinitionChange = httpStatus === 409 && reason === 'workflow_definition_changed';
  const acceptedSameBuildId = httpStatus !== null && httpStatus >= 200 && httpStatus < 300;
  const fingerprintConflictVisible = workflowDefinitionFingerprintConflictVisible(
    rolloutState,
    adversarialBuildId,
    workflowType,
  );
  const observedBehavior = rejectedDefinitionChange
    ? 'register_rejected_changed_workflow_definition'
    : (
        acceptedSameBuildId
          ? 'accepted_with_same_build_id'
          : `register_failed_${httpStatus ?? 'unknown'}`
      );
  const operatorAuditSignal = rejectedDefinitionChange
    ? reason
    : (fingerprintConflictVisible ? 'worker_definition_fingerprint_conflict_visible' : '');
  const workerExecution = publishedPythonWorkerExecution();
  const observedOutputs = {
    observed_behavior: observedBehavior,
    operator_audit_signal: operatorAuditSignal,
    worker_id: adversarialWorkerId,
    build_id: adversarialBuildId,
    initial_workflow_definition_fingerprint: v1Fingerprint,
    divergent_workflow_definition_fingerprint: divergentFingerprint,
    register_response: changedRegister,
    rollout_state: rolloutState,
    workflow_definition_fingerprint_conflict_visible: fingerprintConflictVisible,
    worker_execution_mode: 'published_python_worker_protocol_client',
    published_artifact_worker_execution: workerExecution,
    local_product_source_checkouts_used: false,
  };
  const passes = observedBehavior !== '' && operatorAuditSignal !== '';
  const finding = passes ? null : {
    scenario_id: ADVERSARIAL_SCENARIO,
    owning_surface: acceptedSameBuildId ? 'server' : 'conformance_harness',
    artifact_versions: artifactVersions(),
    observed_behavior: acceptedSameBuildId
      ? 'A published Python worker registered divergent workflow code under the same build id without an operator-visible fingerprint conflict.'
      : 'Published Python worker adversarial no-version-bump evidence did not record an accepted or rejected registration outcome with an operator audit signal.',
    expected_behavior: 'A published worker artifact that ships divergent workflow code under an existing build id is rejected or exposes an auditable conflict signal.',
    next_acceptance_criterion: 'rerun the adversarial no-version-bump cell with the installed Python worker artifact and record observed_behavior plus operator_audit_signal from the public worker registration or rollout surfaces',
    register_http_status: httpStatus,
    register_reason: reason,
    workflow_definition_fingerprint_conflict_visible: fingerprintConflictVisible,
  };

  return {
    workers: [
      { worker_id: adversarialWorkerId, runtime: 'python', build_id: adversarialBuildId },
    ],
    scenario_results: {
      [ADVERSARIAL_SCENARIO]: {
        scenario_id: ADVERSARIAL_SCENARIO,
        status: passes ? 'pass' : 'fail',
        observed_outputs: observedOutputs,
        linked_findings: finding ? [finding] : [],
      },
    },
    findings: finding ? [finding] : [],
  };
}

function notCoveredPythonAdversarialShard(reason) {
  const finding = {
    scenario_id: ADVERSARIAL_SCENARIO,
    owning_surface: 'conformance_harness',
    artifact_versions: artifactVersions(),
    observed_behavior: `Published Python worker adversarial no-version-bump shard could not complete: ${reason}`,
    expected_behavior: 'A published worker artifact that ships divergent workflow code under an existing build id is rejected or exposes an auditable conflict signal.',
    next_acceptance_criterion: 'rerun the published worker-versioning adversarial shard and record observed_behavior plus operator_audit_signal from published worker execution',
  };

  return {
    workers: [],
    scenario_results: {
      [ADVERSARIAL_SCENARIO]: {
        scenario_id: ADVERSARIAL_SCENARIO,
        status: 'not_covered',
        observed_outputs: {
          shard_error: reason,
          worker_execution_mode: 'published_python_worker_protocol_client',
          published_artifact_worker_execution: publishedPythonWorkerExecution(),
          local_product_source_checkouts_used: false,
        },
        linked_findings: [finding],
      },
    },
    findings: [finding],
  };
}

async function runPythonReplayShardSafely(python) {
  try {
    return await runPythonReplayShard(python);
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return notCoveredPythonReplayShard(message);
  }
}

async function runPythonReplayShard(python) {
  const replayV1BuildId = `wv-python-replay-v1-${suffix}`;
  const replayV2BuildId = `wv-python-replay-v2-${suffix}`;
  const replayV1WorkerId = `python-replay-v1-${suffix}`;
  const replayV2WorkerId = `python-replay-v2-${suffix}`;
  const replayWorkflowId = `wv-python-replay-${suffix}`;
  const v1Fingerprint = `sequence-python-replay-v1-${suffix}`;
  const v2Fingerprint = `sequence-python-replay-v2-divergent-${suffix}`;
  const workflowResult = ['activity_a', 'activity_b'];
  const replayRoot = path.join(runRoot, 'published-php-python-worker-shard', 'replay');

  runPythonWorker(python, {
    action: 'register',
    worker_id: replayV1WorkerId,
    build_id: replayV1BuildId,
    fingerprint: v1Fingerprint,
    output_path: path.join(replayRoot, 'python-replay-v1-register.json'),
  });
  runPythonWorker(python, {
    action: 'register',
    worker_id: replayV2WorkerId,
    build_id: replayV2BuildId,
    fingerprint: v2Fingerprint,
    output_path: path.join(replayRoot, 'python-replay-v2-register.json'),
  });

  await promoteBuildId(replayV1BuildId);
  const started = await startWorkflow(replayWorkflowId, ['python-replay-v1']);
  const runId = stringValue(started.run_id);
  const pinnedRunBuildId = stringValue(started.compatibility) || replayV1BuildId;

  const v2BeforeReplay = runPythonWorker(python, {
    action: 'poll',
    worker_id: replayV2WorkerId,
    build_id: replayV2BuildId,
    output_path: path.join(replayRoot, 'python-replay-v2-before-replay.json'),
  });
  const v1FirstPoll = runPythonWorker(python, {
    action: 'poll',
    worker_id: replayV1WorkerId,
    build_id: replayV1BuildId,
    fail: true,
    failure_message: 'published worker process restarted before workflow task completion',
    failure_type: 'RuntimeError',
    output_path: path.join(replayRoot, 'python-replay-v1-first-poll.json'),
  });

  runPythonWorker(python, {
    action: 'register',
    worker_id: replayV1WorkerId,
    build_id: replayV1BuildId,
    fingerprint: v1Fingerprint,
    output_path: path.join(replayRoot, 'python-replay-v1-reregister.json'),
  });

  const v2AfterRestart = runPythonWorker(python, {
    action: 'poll',
    worker_id: replayV2WorkerId,
    build_id: replayV2BuildId,
    output_path: path.join(replayRoot, 'python-replay-v2-after-restart.json'),
  });
  const v1ReplayPoll = runPythonWorker(python, {
    action: 'poll',
    worker_id: replayV1WorkerId,
    build_id: replayV1BuildId,
    complete: true,
    result: workflowResult,
    output_path: path.join(replayRoot, 'python-replay-v1-replay-poll.json'),
  });

  const v1TaskCount = countTaskForRun(v1FirstPoll, runId) + countTaskForRun(v1ReplayPoll, runId);
  const v2TaskCountForV1Run =
    countTaskForRun(v2BeforeReplay, runId) + countTaskForRun(v2AfterRestart, runId);
  const cacheEvictionIncompatibleCount = countTaskForRun(v2AfterRestart, runId);
  const replayWorkerBuildId = stringValue(v1ReplayPoll?.task?.compatibility);
  const v1FirstTaskId = stringValue(v1FirstPoll?.task?.task_id);
  const v1ReplayTaskId = stringValue(v1ReplayPoll?.task?.task_id);
  const replayRetryOfTaskId = workflowTaskRetryOf(v1ReplayPoll);
  const cacheEvictionObserved = countTaskForRun(v1ReplayPoll, runId) > 0
    && (
      numberValue(v1ReplayPoll?.task?.workflow_task_attempt) >= 2
      || replayRetryOfTaskId !== ''
      || (v1FirstTaskId !== '' && v1ReplayTaskId !== '' && v1ReplayTaskId !== v1FirstTaskId)
    );
  const divergentWorkflowExecutionObserved = v1Fingerprint !== v2Fingerprint
    && v1TaskCount > 0
    && workflowResult[0] === 'activity_a';
  const workerExecution = publishedPythonWorkerExecution();

  const replayOutputs = {
    v1_worker_task_count: v1TaskCount,
    v2_worker_task_count_for_v1_run: v2TaskCountForV1Run,
    workflow_result: workflowResult,
    workflow_id: replayWorkflowId,
    v1_pinned_run_id: runId,
    pinned_run_build_id: pinnedRunBuildId,
    v1_worker_build_id: replayV1BuildId,
    v2_worker_build_id: replayV2BuildId,
    v1_first_task_id: v1FirstTaskId,
    replay_task_id: v1ReplayTaskId,
    workflow_task_retry_of: replayRetryOfTaskId,
    divergent_workflow_execution_observed: divergentWorkflowExecutionObserved,
    worker_task_counts_by_run: {
      [runId]: {
        [replayV1WorkerId]: v1TaskCount,
        [replayV2WorkerId]: v2TaskCountForV1Run,
      },
    },
    replay_decision: {
      task_queue: taskQueue,
      compatible_worker_id: replayV1WorkerId,
      compatible_worker_build_id: replayV1BuildId,
      incompatible_worker_id: replayV2WorkerId,
      incompatible_worker_build_id: replayV2BuildId,
      compatible_delivery_count: v1TaskCount,
      incompatible_delivery_count: v2TaskCountForV1Run,
      routed_only_to_compatible_worker: v1TaskCount > 0 && v2TaskCountForV1Run === 0,
    },
    poll_outputs: {
      v2_before_replay: v2BeforeReplay,
      v1_first_poll: v1FirstPoll,
      v2_after_restart: v2AfterRestart,
      v1_replay_poll: v1ReplayPoll,
    },
    worker_execution_mode: 'published_python_worker_protocol_client',
    published_artifact_worker_execution: workerExecution,
    local_product_source_checkouts_used: false,
  };
  const cacheOutputs = {
    cache_eviction_observed: cacheEvictionObserved,
    replay_worker_build_id: replayWorkerBuildId,
    expected_replay_worker_build_id: pinnedRunBuildId,
    pinned_run_build_id: pinnedRunBuildId,
    v1_pinned_run_id: runId,
    incompatible_delivery_count: cacheEvictionIncompatibleCount,
    v1_worker_task_count: v1TaskCount,
    v2_worker_task_count_for_v1_run: v2TaskCountForV1Run,
    divergent_workflow_execution_observed: divergentWorkflowExecutionObserved,
    replay_attempt: numberValue(v1ReplayPoll?.task?.workflow_task_attempt),
    v1_first_task_id: v1FirstTaskId,
    replay_task_id: v1ReplayTaskId,
    workflow_task_retry_of: replayRetryOfTaskId,
    replay_decision: {
      task_queue: taskQueue,
      replay_worker_id: replayV1WorkerId,
      replay_worker_build_id: replayWorkerBuildId,
      incompatible_worker_id: replayV2WorkerId,
      incompatible_worker_build_id: replayV2BuildId,
      incompatible_delivery_count: cacheEvictionIncompatibleCount,
      routed_only_to_compatible_worker: cacheEvictionObserved && cacheEvictionIncompatibleCount === 0,
    },
    poll_outputs: {
      v2_after_restart: v2AfterRestart,
      v1_replay_poll: v1ReplayPoll,
    },
    worker_execution_mode: 'published_python_worker_protocol_client',
    published_artifact_worker_execution: workerExecution,
    local_product_source_checkouts_used: false,
  };

  const replayPasses = divergentWorkflowExecutionObserved
    && runId !== ''
    && v1TaskCount > 0
    && v2TaskCountForV1Run === 0;
  const cachePasses = divergentWorkflowExecutionObserved
    && runId !== ''
    && cacheEvictionObserved
    && cacheEvictionIncompatibleCount === 0
    && replayWorkerBuildId === pinnedRunBuildId;

  const replayFinding = replayPasses ? null : {
    scenario_id: REPLAY_SCENARIO,
    owning_surface: v2TaskCountForV1Run > 0 ? 'server' : 'conformance_harness',
    artifact_versions: artifactVersions(),
    observed_behavior: 'Published Python worker replay evidence did not prove positive compatible delivery with zero incompatible delivery for one v1-pinned divergent run.',
    expected_behavior: 'A v1-pinned workflow with divergent v2 code is replayed only by a v1-compatible worker while v2 workers poll the same task queue.',
    next_acceptance_criterion: 'rerun the published worker-versioning shard and record a non-empty v1_pinned_run_id, v1_worker_task_count above zero, v2_worker_task_count_for_v1_run equal to zero, and divergent_workflow_execution_observed=true',
    v1_worker_task_count: v1TaskCount,
    v2_worker_task_count_for_v1_run: v2TaskCountForV1Run,
    v1_pinned_run_id: runId,
  };
  const cacheFinding = cachePasses ? null : {
    scenario_id: CACHE_EVICTION_SCENARIO,
    owning_surface: cacheEvictionIncompatibleCount > 0 || replayWorkerBuildId !== pinnedRunBuildId
      ? 'server'
      : 'conformance_harness',
    artifact_versions: artifactVersions(),
    observed_behavior: 'Published Python worker cache-eviction evidence did not prove replay on the pinned build with zero incompatible delivery.',
    expected_behavior: 'After a published worker task failure/restart, v1-pinned history is replayed only by the v1-compatible build while v2 workers receive zero tasks for that run.',
    next_acceptance_criterion: 'rerun the published worker-versioning shard and record cache_eviction_observed=true, replay_worker_build_id equal to pinned_run_build_id, and incompatible_delivery_count equal to zero',
    expected_replay_worker_build_id: pinnedRunBuildId,
    replay_worker_build_id: replayWorkerBuildId,
    incompatible_delivery_count: cacheEvictionIncompatibleCount,
    cache_eviction_observed: cacheEvictionObserved,
    v1_pinned_run_id: runId,
  };

  return {
    workers: [
      { worker_id: replayV1WorkerId, runtime: 'python', build_id: replayV1BuildId },
      { worker_id: replayV2WorkerId, runtime: 'python', build_id: replayV2BuildId },
    ],
    scenario_results: {
      [REPLAY_SCENARIO]: {
        scenario_id: REPLAY_SCENARIO,
        status: replayPasses ? 'pass' : 'fail',
        observed_outputs: replayOutputs,
        linked_findings: replayFinding ? [replayFinding] : [],
      },
      [CACHE_EVICTION_SCENARIO]: {
        scenario_id: CACHE_EVICTION_SCENARIO,
        status: cachePasses ? 'pass' : 'fail',
        observed_outputs: cacheOutputs,
        linked_findings: cacheFinding ? [cacheFinding] : [],
      },
    },
    findings: [
      ...(replayFinding ? [replayFinding] : []),
      ...(cacheFinding ? [cacheFinding] : []),
    ],
  };
}

function notCoveredPythonReplayShard(reason) {
  const replayFinding = {
    scenario_id: REPLAY_SCENARIO,
    owning_surface: 'conformance_harness',
    artifact_versions: artifactVersions(),
    observed_behavior: `Published Python worker replay shard could not complete: ${reason}`,
    expected_behavior: 'A v1-pinned workflow with divergent v2 code is replayed only by a v1-compatible worker while v2 workers poll the same task queue.',
    next_acceptance_criterion: 'rerun the published worker-versioning replay shard and record positive v1-compatible delivery with zero v2 delivery for the v1-pinned run',
  };
  const cacheFinding = {
    scenario_id: CACHE_EVICTION_SCENARIO,
    owning_surface: 'conformance_harness',
    artifact_versions: artifactVersions(),
    observed_behavior: `Published Python worker cache-eviction shard could not complete: ${reason}`,
    expected_behavior: 'After a published worker task failure/restart, v1-pinned history is replayed only by the v1-compatible build while v2 workers receive zero tasks for that run.',
    next_acceptance_criterion: 'rerun the published worker-versioning cache-eviction shard and record replay_worker_build_id equal to pinned_run_build_id with zero incompatible delivery',
  };
  const observedOutputs = {
    shard_error: reason,
    worker_execution_mode: 'published_python_worker_protocol_client',
    published_artifact_worker_execution: publishedPythonWorkerExecution(),
    local_product_source_checkouts_used: false,
  };

  return {
    workers: [],
    scenario_results: {
      [REPLAY_SCENARIO]: {
        scenario_id: REPLAY_SCENARIO,
        status: 'not_covered',
        observed_outputs: observedOutputs,
        linked_findings: [replayFinding],
      },
      [CACHE_EVICTION_SCENARIO]: {
        scenario_id: CACHE_EVICTION_SCENARIO,
        status: 'not_covered',
        observed_outputs: observedOutputs,
        linked_findings: [cacheFinding],
      },
    },
    findings: [replayFinding, cacheFinding],
  };
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
    `durable-workflow/sdk:${sdkPhpVersion}`,
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
  const pollTimeoutSeconds = Math.max(1, numberValue(
    process.env.DW_WV_WORKER_POLL_CLIENT_TIMEOUT_SECONDS
      ?? process.env.DW_WV_WORKER_POLL_TIMEOUT
      ?? process.env.DW_WORKER_POLL_TIMEOUT,
  ) ?? 2);

  writeJson(inputPath, {
    server_url: serverUrl,
    token,
    namespace,
    task_queue: taskQueue,
    workflow_type: workflowType,
    supported_activity_types: ['activity_a', 'activity_b'],
    python_version: pythonVersion,
    sdk_php_version: sdkPhpVersion,
    complete: false,
    fail: false,
    failure_message: 'published worker task failed',
    failure_type: 'RuntimeError',
    poll_timeout_seconds: pollTimeoutSeconds,
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

async function taskQueueBuildIds() {
  return requestJson(
    'GET',
    `/api/task-queues/${encodeURIComponent(taskQueue)}/build-ids`,
    undefined,
    controlHeaders(namespace),
    [200],
  );
}

async function workerList() {
  return requestJson(
    'GET',
    `/api/workers?task_queue=${encodeURIComponent(taskQueue)}`,
    undefined,
    controlHeaders(namespace),
    [200],
  );
}

function taskQueueBuildIdEntry(snapshot, buildId) {
  const wanted = stringValue(buildId);
  const entries = Array.isArray(snapshot?.build_ids) ? snapshot.build_ids : [];

  return entries.find((entry) => stringValue(entry?.build_id) === wanted) ?? null;
}

function pendingWorkflowTaskDiagnosticSignals(entry) {
  const pending = objectValue(entry?.pending_workflow_tasks ?? entry?.pendingWorkflowTasks);

  return [
    pending.operator_visible_signal,
    pending.operatorVisibleSignal,
    pending.status,
    pending.message,
  ];
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

function emptyNoCompatibleVisibilityResult() {
  return {
    latest: {},
    samples: [],
    task_queue_build_ids: {},
    task_queue_build_id_entry: null,
    task_queue_build_id_samples: [],
    attempts: 0,
  };
}

async function workflowVisibility(workflowId, runId) {
  return requestJson(
    'GET',
    `/api/workflows/${encodeURIComponent(workflowId)}/runs/${encodeURIComponent(runId)}`,
    undefined,
    controlHeaders(namespace),
    [200],
  );
}

async function waitForNoCompatibleVisibility(workflowId, runId, buildId) {
  const samples = [];
  const taskQueueBuildIdSamples = [];
  const deadline = Date.now() + noCompatibleVisibilitySeconds * 1000;
  let latest = {};
  let latestBuildIds = {};
  let latestBuildIdEntry = null;
  let attempts = 0;

  do {
    attempts += 1;
    const sample = await workflowVisibility(workflowId, runId);
    samples.push(sample);
    latest = sample;

    latestBuildIds = await taskQueueBuildIds();
    latestBuildIdEntry = taskQueueBuildIdEntry(latestBuildIds, buildId);
    taskQueueBuildIdSamples.push({
      attempt: attempts,
      build_id_entry: latestBuildIdEntry,
      pending_workflow_tasks: objectValue(
        latestBuildIdEntry?.pending_workflow_tasks
          ?? latestBuildIdEntry?.pendingWorkflowTasks,
      ),
    });

    const signal = firstExplicitNoCompatibleSignal(
      sample.compatibility_status,
      sample.compatibilityStatus,
      sample.compatibility_fleet_reason,
      sample.compatibilityFleetReason,
      ...pendingWorkflowTaskDiagnosticSignals(latestBuildIdEntry),
    );
    if (isExplicitNoCompatibleSignal(signal)) {
      break;
    }

    if (Date.now() >= deadline) {
      break;
    }

    await sleep(1000);
  } while (Date.now() < deadline);

  return {
    latest,
    samples,
    task_queue_build_ids: latestBuildIds,
    task_queue_build_id_entry: latestBuildIdEntry,
    task_queue_build_id_samples: taskQueueBuildIdSamples,
    attempts,
  };
}

async function requestJson(method, pathName, body, headers, expectedStatuses) {
  const url = `${serverUrl}${pathName}`;
  let response;
  try {
    response = await fetch(url, {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
    });
  } catch (error) {
    throw new Error(formatFetchFailure(method, url, error));
  }
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

function workflowTaskRetryOf(output) {
  const task = output?.task;
  const payload = task?.payload && typeof task.payload === 'object' && !Array.isArray(task.payload)
    ? task.payload
    : {};

  return stringValue(task?.workflow_task_retry_of)
    || stringValue(task?.workflowTaskRetryOf)
    || stringValue(payload.workflow_task_retry_of)
    || stringValue(payload.workflowTaskRetryOf)
    || stringValue(payload.retry_of)
    || stringValue(payload.retryOf);
}

function numberValue(value) {
  if (typeof value === 'number' && Number.isFinite(value)) {
    return value;
  }
  if (typeof value === 'string' && value.trim() !== '' && Number.isFinite(Number(value))) {
    return Number(value);
  }

  return null;
}

function unique(values) {
  return Array.from(new Set(values));
}

function notCoveredShard(reason, observedOutputs) {
  const finding = {
    scenario_id: CROSS_LANGUAGE_SCENARIO,
    owning_surface: 'conformance_harness',
    artifact_versions: artifactVersions(),
    observed_behavior: reason,
    expected_behavior: 'Published sdk-php and sdk-python worker artifacts execute the cross-language worker-versioning cell without local product checkouts.',
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

function publishedPythonWorkerExecution() {
  return {
    local_product_source_checkouts_used: false,
    artifacts: [
      {
        artifact: 'sdk-python',
        version: pythonVersion,
        source: 'pypi_release',
        status: 'pass',
        command: `python3 -m pip install durable-workflow==${pythonVersion}`,
      },
    ],
  };
}

function artifactVersions() {
  return {
    server: serverVersion,
    'sdk-python': pythonVersion,
    'sdk-php': sdkPhpVersion,
  };
}

function artifactSources() {
  return {
    server: process.env.DW_WV_SERVER_ARTIFACT_SOURCE ?? 'published_server_url',
    'sdk-python': pythonVersion ? 'pypi_release' : 'not_exercised',
    'sdk-php': sdkPhpVersion ? 'packagist_release' : 'not_exercised',
  };
}

function writeShard(value) {
  const merged = mergeExistingShard(value);
  writeJson(outputPath, merged);

  return merged;
}

function mergeExistingShard(value) {
  const existing = readJsonIfExists(outputPath);
  if (!existing || typeof existing !== 'object' || Array.isArray(existing)) {
    return value;
  }

  return mergeShardValues(existing, value);
}

export function mergeShardValues(existing, value) {
  const existingScenarios = objectValue(existing.scenario_results);
  const incomingScenarios = objectValue(value.scenario_results);
  const scenarioResults = {
    ...existingScenarios,
    ...incomingScenarios,
  };
  for (const [scenarioId, existingScenario] of Object.entries(existingScenarios)) {
    const incomingScenario = objectValue(incomingScenarios[scenarioId]);
    if (stringValue(existingScenario?.status) === 'pass'
      && stringValue(incomingScenario.status) !== 'pass') {
      scenarioResults[scenarioId] = existingScenario;
    }
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
      workers: mergeWorkerEntries(
        arrayValue(existing.topology?.workers),
        arrayValue(value.topology?.workers),
      ),
    },
    scenario_results: scenarioResults,
    findings: uniqueJsonEntries([
      ...arrayValue(existing.findings),
      ...arrayValue(value.findings),
    ]),
    logs: {
      ...objectValue(existing.logs),
      ...objectValue(value.logs),
    },
  };
}

function mergeWorkerEntries(existingWorkers, incomingWorkers) {
  const seen = new Set();
  const workers = [];

  for (const worker of [...existingWorkers, ...incomingWorkers]) {
    if (!worker || typeof worker !== 'object' || Array.isArray(worker)) {
      continue;
    }

    const key = [
      stringValue(worker.worker_id) || stringValue(worker.workerId),
      stringValue(worker.runtime),
      stringValue(worker.build_id) || stringValue(worker.buildId),
    ].join('|');
    const dedupeKey = key === '||' ? JSON.stringify(worker) : key;
    if (seen.has(dedupeKey)) {
      continue;
    }

    seen.add(dedupeKey);
    workers.push(worker);
  }

  return workers;
}

function uniqueJsonEntries(entries) {
  const seen = new Set();
  const unique = [];

  for (const entry of entries) {
    const key = JSON.stringify(entry);
    if (seen.has(key)) {
      continue;
    }

    seen.add(key);
    unique.push(entry);
  }

  return unique;
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

function formatFetchFailure(method, url, error) {
  const reason = error instanceof Error ? error.message : String(error);
  const cause = fetchFailureCause(error);
  const details = orderedUnique([reason, cause].filter((value) => value !== ''));

  return `${method} ${url} failed: ${details.join('; ') || 'request failed'}`;
}

function fetchFailureCause(error) {
  const cause = error && typeof error === 'object' ? error.cause : null;
  if (!cause || typeof cause !== 'object') {
    return '';
  }

  if (Array.isArray(cause.errors)) {
    return cause.errors
      .map((nested) => fetchFailureCause({ cause: nested }) || errorMessage(nested))
      .filter(Boolean)
      .join('; ');
  }

  const fields = [
    stringValue(cause.code),
    stringValue(cause.errno),
    stringValue(cause.syscall),
    stringValue(cause.address),
    stringValue(cause.port),
    errorMessage(cause),
  ].filter(Boolean);

  return orderedUnique(fields).join(' ');
}

function errorMessage(error) {
  return error instanceof Error ? error.message : stringValue(error);
}

function orderedUnique(values) {
  const seen = [];
  for (const value of values) {
    const normalized = stringValue(value);
    if (normalized !== '' && !seen.includes(normalized)) {
      seen.push(normalized);
    }
  }

  return seen;
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

function workflowDefinitionFingerprintConflictVisible(value, buildId, workflowType) {
  if (!value || typeof value !== 'object') {
    return false;
  }

  if (Array.isArray(value)) {
    return value.some((item) => workflowDefinitionFingerprintConflictVisible(item, buildId, workflowType));
  }

  const reportedBuildId = stringValue(value.build_id) || stringValue(value.buildId);
  if (reportedBuildId !== '' && reportedBuildId !== buildId) {
    return false;
  }

  for (const [key, child] of Object.entries(value)) {
    const normalizedKey = key.toLowerCase();
    if (normalizedKey.includes('workflow_definition_fingerprint_conflict')) {
      if (Array.isArray(child)) {
        if (child.length > 0) {
          return true;
        }
      } else if (child && typeof child === 'object') {
        if (Object.keys(child).length > 0) {
          return true;
        }
      } else if (stringValue(child) !== '') {
        return true;
      }
    }

    if (normalizedKey === workflowType.toLowerCase()
      && Array.isArray(child)
      && child.length > 1) {
      return true;
    }

    if (workflowDefinitionFingerprintConflictVisible(child, buildId, workflowType)) {
      return true;
    }
  }

  return false;
}

function objectValue(value) {
  return value && typeof value === 'object' && !Array.isArray(value) ? value : {};
}

function arrayValue(value) {
  return Array.isArray(value) ? value : [];
}

function firstExplicitNoCompatibleSignal(...values) {
  for (const value of values) {
    if (value !== undefined && value !== null && isExplicitNoCompatibleSignal(value)) {
      return value;
    }
  }

  for (const value of values) {
    if (value !== undefined && value !== null) {
      return value;
    }
  }

  return undefined;
}

function isExplicitNoCompatibleSignal(value) {
  const normalized = stringValue(value)
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '');

  return [
    'no_compatible_worker',
    'compatibility_blocked',
    'compatibility_unsupported',
  ].some((token) => normalized.includes(token));
}

function isGenericPollErrorStatus(value) {
  return stringValue(value).toLowerCase() === 'poll_error';
}

function sleep(milliseconds) {
  return new Promise((resolve) => setTimeout(resolve, milliseconds));
}

function shellQuote(value) {
  return `'${String(value).replace(/'/g, "'\\''")}'`;
}

function isMainModule() {
  return process.argv[1] && path.resolve(process.argv[1]) === path.resolve(new URL(import.meta.url).pathname);
}

export function pythonWorkerScript() {
  return String.raw`import asyncio
import json
import os
import sys
import time
import urllib.error
import urllib.request

from durable_workflow import Client, serializer
from durable_workflow.client import PORTABLE_WORKER_AFFINITY_CAPABILITY_MANIFEST
from durable_workflow.errors import ServerError


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
            try:
                response = await client.register_worker(
                    worker_id=payload["worker_id"],
                    task_queue=payload["task_queue"],
                    supported_workflow_types=[payload["workflow_type"]],
                    workflow_definition_fingerprints={payload["workflow_type"]: payload["fingerprint"]},
                    supported_activity_types=payload["supported_activity_types"],
                    capability_manifest=PORTABLE_WORKER_AFFINITY_CAPABILITY_MANIFEST,
                    max_concurrent_workflow_tasks=10,
                    max_concurrent_activity_tasks=10,
                    runtime="python",
                    sdk_version=payload["python_version"],
                    build_id=payload["build_id"],
                    task_slots={"workflow_available": 10, "activity_available": 10},
                    process_metrics=process_metrics(),
                )
                result = {"action": "register", "response": response, "task": None, "http_status": 201}
            except ServerError as exc:
                if not payload.get("allow_register_error"):
                    raise
                result = {
                    "action": "register",
                    "response": exc.body,
                    "task": None,
                    "http_status": exc.status,
                    "reason": exc.reason(),
                    "error": str(exc),
                }
        elif payload["action"] == "poll":
            if hasattr(client, "poll_workflow_task_response"):
                response = await client.poll_workflow_task_response(
                    worker_id=payload["worker_id"],
                    task_queue=payload["task_queue"],
                    build_id=payload["build_id"],
                    timeout=float(payload.get("poll_timeout_seconds") or 2.0),
                )
                task = response.get("task") if isinstance(response, dict) else None
                poll_status = response.get("poll_status") if isinstance(response, dict) else None
            else:
                task = await client.poll_workflow_task(
                    worker_id=payload["worker_id"],
                    task_queue=payload["task_queue"],
                    timeout=float(payload.get("poll_timeout_seconds") or 2.0),
                )
                poll_status = None
            if task and payload.get("complete"):
                await client.complete_workflow_task(
                    task_id=task["task_id"],
                    lease_owner=task["lease_owner"],
                    workflow_task_attempt=int(task.get("workflow_task_attempt") or 1),
                    commands=[
                        {
                            "type": "complete_workflow",
                            "result": serializer.encode(payload.get("result") or [], codec=serializer.AVRO_CODEC),
                        }
                    ],
                )
            elif task and payload.get("fail"):
                await client.fail_workflow_task(
                    task_id=task["task_id"],
                    lease_owner=task["lease_owner"],
                    workflow_task_attempt=int(task.get("workflow_task_attempt") or 1),
                    message=payload.get("failure_message") or "published worker task failed",
                    failure_type=payload.get("failure_type") or "RuntimeError",
                )
            result = {
                "action": "poll",
                "task": task,
                "poll_status": poll_status,
                "sdk_poll_envelope_used": hasattr(client, "poll_workflow_task_response"),
            }
        elif payload["action"] == "raw_poll":
            body = {
                "worker_id": payload["worker_id"],
                "task_queue": payload["task_queue"],
                "build_id": payload["build_id"],
                "poll_request_id": f"{payload['worker_id']}-{time.time_ns()}",
            }
            poll_status = None
            sdk_poll_envelope_used = False
            if hasattr(client, "poll_workflow_task_response"):
                response = await client.poll_workflow_task_response(
                    worker_id=payload["worker_id"],
                    task_queue=payload["task_queue"],
                    build_id=payload["build_id"],
                    poll_request_id=body["poll_request_id"],
                    timeout=float(payload.get("poll_timeout_seconds") or 2.0),
                )
                http_status = 200
                error = None
                error_type = None
                sdk_poll_envelope_used = True
            else:
                request = urllib.request.Request(
                    f"{payload['server_url'].rstrip('/')}/api/worker/workflow-tasks/poll",
                    data=json.dumps(body).encode("utf-8"),
                    method="POST",
                    headers={
                        "Accept": "application/json",
                        "Content-Type": "application/json",
                        "Authorization": f"Bearer {payload['token']}",
                        "X-Namespace": payload["namespace"],
                        "X-Durable-Workflow-Protocol-Version": "1.19",
                    },
                )
                try:
                    with urllib.request.urlopen(
                        request,
                        timeout=float(payload.get("poll_timeout_seconds") or 2.0),
                    ) as handle:
                        http_status = handle.status
                        response_text = handle.read().decode("utf-8")
                    error = None
                    error_type = None
                except urllib.error.HTTPError as exc:
                    http_status = exc.code
                    response_text = exc.read().decode("utf-8")
                    error = str(exc)
                    error_type = exc.__class__.__name__
                except Exception as exc:
                    http_status = None
                    response_text = ""
                    poll_status = "poll_timeout" if exc.__class__.__name__ in ("TimeoutException", "TimeoutError", "timeout") else "poll_error"
                    error = str(exc)
                    error_type = exc.__class__.__name__

                if response_text:
                    try:
                        response = json.loads(response_text)
                    except json.JSONDecodeError:
                        response = {"raw_body": response_text}
                else:
                    response = None

            if isinstance(response, dict):
                poll_status = response.get("poll_status") or response.get("reason") or poll_status
                if error is None and http_status is not None and http_status >= 400:
                    error = response.get("reason") or response.get("error")
                    error_type = "HTTPError"
            if (
                http_status is not None
                and http_status >= 400
                and poll_status not in ("no_compatible_worker", "compatibility_blocked", "compatibility_unsupported")
            ):
                poll_status = "poll_error"

            result = {
                "action": "raw_poll",
                "request": body,
                "http_status": http_status,
                "response": response,
                "task": (response or {}).get("task"),
                "poll_status": poll_status,
                "error": error,
                "error_type": error_type,
                "sdk_poll_envelope_used": sdk_poll_envelope_used,
            }
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

use DurableWorkflow\Client;

$payload = json_decode((string) file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$outputPath = $argv[2];

$client = new Client(
    $payload['server_url'],
    namespace: $payload['namespace'],
    token: $payload['token'],
);

if ($payload['action'] === 'register') {
    $response = $client->registerWorker(
        $payload['worker_id'],
        $payload['task_queue'],
        [$payload['workflow_type']],
        $payload['supported_activity_types'],
        ['query_tasks', 'workflow_updates', 'durable_history_replay'],
        maxConcurrentWorkflowTasks: 10,
        maxConcurrentActivityTasks: 10,
        buildId: $payload['build_id'],
    );

    $result = ['action' => 'register', 'response' => $response, 'task' => null];
} elseif ($payload['action'] === 'poll') {
    $task = $client->pollWorkflowTask(
        $payload['worker_id'],
        $payload['task_queue'],
        2,
    );

    if (is_array($task) && ($payload['complete'] ?? false)) {
        $client->completeWorkflowTask(
            (string) $task['task_id'],
            (string) ($task['lease_owner'] ?? $payload['worker_id']),
            (int) ($task['workflow_task_attempt'] ?? 1),
            [[
                'type' => 'complete_workflow',
                'result' => $client->payloadCodec()->envelope($payload['result'] ?? []),
            ]],
        );
    }

    $result = ['action' => 'poll', 'task' => $task];
} else {
    throw new RuntimeException('unknown action: '.(string) $payload['action']);
}

file_put_contents($outputPath, json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
`;
}
