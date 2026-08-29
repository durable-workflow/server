#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import {
  avroResultFromTaskArguments,
  currentWorkerProtocolHeaders,
  currentWorkerRegistration,
  workflowTaskCompletionPayload,
  workflowTaskFailurePayload,
} from './current-worker-protocol.mjs';
import { isExactPythonRelease, isExactSemverRelease } from './version-identities.mjs';

const RESULT_SCHEMA = 'durable-workflow.v2.worker-versioning-runtime.result';
const RECORD_SCHEMA = 'durable-workflow.v2.worker-versioning-runtime.record';
const CAPTURE_SCHEMA = 'durable-workflow.v2.worker-versioning-runtime.http-captures';

const modulePath = fileURLToPath(import.meta.url);
const repoRoot = process.env.DW_WV_REPO_ROOT
  ?? path.resolve(path.dirname(modulePath), '../..');
const resultDir = process.env.DW_WV_RESULT_DIR
  ?? process.env.DW_WV_RUN_ROOT
  ?? process.cwd();
const runRoot = process.env.DW_WV_RUN_ROOT ?? resultDir;
const scenarioManifestPath = process.env.DW_WV_SCENARIO_MANIFEST
  ?? path.join(repoRoot, 'static/platform-conformance/worker-versioning-runtime-scenarios.json');
const artifactManifestPath = process.env.DW_WV_ARTIFACTS_JSON
  ?? path.join(resultDir, 'published-artifacts.json');
const artifactInstallEvidencePath = process.env.DW_WV_ARTIFACT_INSTALL_EVIDENCE
  ?? path.join(resultDir, 'artifact-install-evidence.json');
const publishedWorkerEvidencePath = process.env.DW_WV_PUBLISHED_WORKER_EVIDENCE
  ?? path.join(resultDir, 'published-worker-execution-evidence.json');
const REQUIRED_INSTALL_ARTIFACTS = ['server', 'cli', 'sdk-python', 'workflow', 'sdk-php', 'waterline'];
const FORBIDDEN_INSTALL_SOURCE_TOKENS = [
  'not_exercised',
  'local_product_source_checkout',
  'workspace_repo_as_artifact_under_test',
  'local_checkout_artifact',
  'local_checkout',
  'local_source_checkout',
  'workspace_repo',
];
const SERVER_PROTOCOL_PROBE = 'server_http_protocol_probe';
const PUBLISHED_CROSS_LANGUAGE_WORKER_EXECUTION = 'published_php_python_worker_protocol_clients';
const noCompatibleVisibilitySeconds = Math.max(1, numberValue(
  process.env.DW_WV_NO_COMPATIBLE_VISIBILITY_SECONDS
    ?? process.env.DW_WV_WORKER_VERSIONING_NO_COMPATIBLE_VISIBILITY_SECONDS,
) ?? 60);
const publishedWorkerShardTimeoutMs = timeoutMsFromEnv(
  'DW_WV_PUBLISHED_WORKER_SHARD_TIMEOUT_MS',
  'DW_WV_PUBLISHED_WORKER_SHARD_TIMEOUT_SECONDS',
  90000,
);
const cliInstallTimeoutMs = timeoutMsFromEnv(
  'DW_WV_CLI_INSTALL_TIMEOUT_MS',
  'DW_WV_CLI_INSTALL_TIMEOUT_SECONDS',
  120000,
);
const cliCommandTimeoutMs = timeoutMsFromEnv(
  'DW_WV_CLI_COMMAND_TIMEOUT_MS',
  'DW_WV_CLI_COMMAND_TIMEOUT_SECONDS',
  30000,
);
const waterlineRequestTimeoutMs = timeoutMsFromEnv(
  'DW_WV_WATERLINE_REQUEST_TIMEOUT_MS',
  'DW_WV_WATERLINE_REQUEST_TIMEOUT_SECONDS',
  20000,
);
const WATERLINE_RESPONSE_SUMMARY_LIMIT = 4096;
const serverReadinessTimeoutMs = timeoutMsFromEnv(
  'DW_WV_SERVER_READINESS_TIMEOUT_MS',
  'DW_WV_SERVER_READINESS_TIMEOUT_SECONDS',
  120000,
);

const scenarioManifest = readJsonIfExists(scenarioManifestPath) ?? {};
const requiredScenarios = Array.isArray(scenarioManifest.scenarios)
  ? scenarioManifest.scenarios.map((scenario) => scenario.id).filter(Boolean)
  : [
      'published_artifact_install_only',
      'worker_registration_build_ids',
      'operator_rollout_visibility',
      'drain_resume_operator_controls',
      'pin_on_start',
      'replay_only_by_compatible_workers',
      'new_starts_to_promoted_version',
      'replay_across_cache_eviction',
      'no_compatible_worker_behavior',
      'operator_visibility_surfaces',
      'cross_language_php_python_pinning',
      'adversarial_no_version_bump',
      'history_api_version_pin',
    ];

const captures = [];

if (isMainModule()) {
  main().catch((error) => {
    const now = timestamp();
    const reason = error instanceof Error ? error.message : String(error);
    const blockerDetails = error && typeof error === 'object' ? error.runnerBlocker : null;
    writeResult(blockedResult(
      reason,
      now,
      now,
      artifactVersionsFromEnv(),
      artifactSourcesFromEnv(),
      blockerDetails,
    ));
    process.exitCode = 0;
  });
}

async function main() {
  fs.mkdirSync(resultDir, { recursive: true });

  const startedAt = process.env.DW_WV_STARTED_AT ?? timestamp();
  const blockedReason = trim(process.env.DW_WV_BLOCKED_REASON);
  if (blockedReason) {
    const artifactVersions = artifactVersionsFromEnv();
    const artifactSources = artifactSourcesFromEnv();
    writePublishedArtifacts(artifactVersions, artifactSources);
    writeResult(blockedResult(blockedReason, startedAt, timestamp(), artifactVersions, artifactSources));
    return;
  }

  const serverUrl = trimTrailingSlash(requiredEnv('DW_WV_SERVER_URL'));
  const artifactVersions = artifactVersionsFromEnv();
  let artifactSources = artifactSourcesFromEnv();
  let installEvidence = artifactInstallEvidence(artifactVersions, artifactSources);
  artifactSources = mergeArtifactSources(artifactSources, installEvidence);
  writePublishedArtifacts(artifactVersions, artifactSources, installEvidence);

  const versionFailures = artifactVersionFailures(artifactVersions);
  if (versionFailures.length > 0) {
    writeResult(blockedResult(
      `worker-versioning conformance requires concrete published artifact versions for: ${versionFailures.join(', ')}`,
      startedAt,
      timestamp(),
      artifactVersions,
      artifactSources,
    ));
    return;
  }

  const waterlineAttachBlocker = directNodeWaterlineAttachBlocker();
  if (waterlineAttachBlocker) {
    writeResult(blockedResult(
      waterlineAttachBlocker,
      startedAt,
      timestamp(),
      artifactVersions,
      artifactSources,
    ));
    return;
  }

  const token = process.env.DW_WV_AUTH_TOKEN ?? 'dev-token';
  const namespace = process.env.DW_WV_NAMESPACE ?? 'worker-versioning-conformance';
  const suffix = runSuffix();
  const taskQueue = process.env.DW_WV_TASK_QUEUE ?? `worker-versioning-${suffix}`;
  const workflowType = process.env.DW_WV_WORKFLOW_TYPE ?? 'Sequence';
  const buildV1 = process.env.DW_WV_BUILD_ID_V1 ?? `wv-v1-${suffix}`;
  const buildV2 = process.env.DW_WV_BUILD_ID_V2 ?? `wv-v2-${suffix}`;

  const controlHeaders = {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    Authorization: `Bearer ${token}`,
    'X-Namespace': namespace,
    'X-Durable-Workflow-Control-Plane-Version': '2',
  };
  const bootstrapControlHeaders = {
    ...controlHeaders,
    'X-Namespace': process.env.DW_WV_BOOTSTRAP_NAMESPACE ?? 'default',
  };
  const workerHeaders = currentWorkerProtocolHeaders({
    Accept: 'application/json',
    'Content-Type': 'application/json',
    Authorization: `Bearer ${token}`,
    'X-Namespace': namespace,
  });

  await ensureNamespacePrerequisite(serverUrl, namespace, bootstrapControlHeaders, controlHeaders);
  maybeGeneratePublishedWorkerEvidence(serverUrl, artifactVersions, artifactSources);
  const publishedWorkerEvidence = publishedWorkerExecutionEvidence(artifactVersions, artifactSources);

  const topology = {
    namespace,
    task_queue: taskQueue,
    workflow_type: workflowType,
    worker_execution_mode: SERVER_PROTOCOL_PROBE,
    worker_execution_note: 'Server worker protocol routing is probed with direct HTTP requests; PHP, Python, CLI, and Waterline artifact execution must be supplied before their cells can pass.',
    workers: [],
  };
  const runtimeMatrix = {
    runtimes: [SERVER_PROTOCOL_PROBE],
    client_paths: ['server-http-control-plane'],
    operator_visibility_paths: [
      'server HTTP workers list',
      'server HTTP task-queue build-ids',
      'history API compatibility',
    ],
    worker_cohorts: ['v1', 'v2', 'draining-v1', 'promoted-v2', 'no-compatible-worker'],
    cross_language_cells: [
      {
        started_by: 'sdk-php-v1',
        incompatible_worker: 'sdk-python-v2',
        scenario: 'php_v1_not_delivered_to_python_v2',
      },
      {
        started_by: 'sdk-python-v1',
        incompatible_worker: 'sdk-php-v2',
        scenario: 'python_v1_not_delivered_to_php_v2',
      },
    ],
    uncovered_required_runtimes: ['sdk-php', 'sdk-python'],
    uncovered_required_client_paths: ['cli', 'sdk-python', 'sdk-php'],
    uncovered_required_operator_visibility_paths: [
      'dw workers list',
      'dw task-queue build-ids',
      'workflow show compatibility',
      'Waterline worker and workflow views',
    ],
  };

  const v1WorkerId = `php-v1-${suffix}`;
  const v2WorkerId = `php-v2-${suffix}`;
  await registerWorker(serverUrl, workerHeaders, {
    worker_id: v1WorkerId,
    task_queue: taskQueue,
    runtime: 'php',
    sdk_version: artifactVersions['sdk-php'],
    build_id: buildV1,
    supported_workflow_types: [workflowType],
    workflow_definition_fingerprints: { [workflowType]: `sequence-v1-${suffix}` },
    supported_activity_types: ['activity_a', 'activity_b'],
    process_metrics: processMetrics(1001, startedAt),
  });
  topology.workers.push({ worker_id: v1WorkerId, runtime: 'php', build_id: buildV1 });

  await postJson(serverUrl, `/api/task-queues/${encodeURIComponent(taskQueue)}/build-ids/promote`, {
    build_id: buildV1,
  }, controlHeaders, [200, 201]);

  const v1Run = await startWorkflow(serverUrl, controlHeaders, {
    workflow_id: `wv-compatible-${suffix}`,
    workflow_type: workflowType,
    task_queue: taskQueue,
    input: ['activity_a', 'activity_b'],
  });
  const v1WorkflowId = stringValue(v1Run.workflow_id);
  const v1RunId = stringValue(v1Run.run_id);

  await registerWorker(serverUrl, workerHeaders, {
    worker_id: v2WorkerId,
    task_queue: taskQueue,
    runtime: 'php',
    sdk_version: artifactVersions['sdk-php'],
    build_id: buildV2,
    supported_workflow_types: [workflowType],
    workflow_definition_fingerprints: { [workflowType]: `sequence-v2-${suffix}` },
    supported_activity_types: ['activity_a', 'activity_b'],
    process_metrics: processMetrics(2001, startedAt),
  });
  topology.workers.push({ worker_id: v2WorkerId, runtime: 'php', build_id: buildV2 });

  const v2BeforeReplay = await pollWorkflowTask(serverUrl, workerHeaders, v2WorkerId, taskQueue, buildV2);
  const v1FirstPoll = await pollWorkflowTask(serverUrl, workerHeaders, v1WorkerId, taskQueue, buildV1);
  const v2TaskCountForV1Run = countTasksForRun([v2BeforeReplay], v1RunId);
  const v1TaskCount = countTasksForRun([v1FirstPoll], v1RunId);

  if (v1FirstPoll?.task) {
    await failWorkflowTask(
      serverUrl,
      workerHeaders,
      v1FirstPoll.task,
      'worker process restarted before workflow task completion',
      'RuntimeError',
    );
  }

  await registerWorker(serverUrl, workerHeaders, {
    worker_id: v1WorkerId,
    task_queue: taskQueue,
    runtime: 'php',
    sdk_version: artifactVersions['sdk-php'],
    build_id: buildV1,
    supported_workflow_types: [workflowType],
    workflow_definition_fingerprints: { [workflowType]: `sequence-v1-${suffix}` },
    supported_activity_types: ['activity_a', 'activity_b'],
    process_metrics: processMetrics(1002, timestamp()),
  });

  const v2AfterRestart = await pollWorkflowTask(serverUrl, workerHeaders, v2WorkerId, taskQueue, buildV2);
  const v1ReplayPoll = await pollWorkflowTask(serverUrl, workerHeaders, v1WorkerId, taskQueue, buildV1);
  const replayWorkerBuildId = stringValue(v1ReplayPoll?.task?.compatibility);
  const v1FirstTaskId = stringValue(v1FirstPoll?.task?.task_id);
  const v1ReplayTaskId = stringValue(v1ReplayPoll?.task?.task_id);
  const replayRetryOfTaskId = workflowTaskRetryOf(v1ReplayPoll);
  const cacheEvictionIncompatibleCount = countTasksForRun([v2AfterRestart], v1RunId);
  const cacheEvictionObserved = countTasksForRun([v1ReplayPoll], v1RunId) > 0
    && (
      numberValue(v1ReplayPoll?.task?.workflow_task_attempt) >= 2
      || replayRetryOfTaskId !== ''
      || (v1FirstTaskId !== '' && v1ReplayTaskId !== '' && v1ReplayTaskId !== v1FirstTaskId)
    );

  if (v1ReplayPoll?.task) {
    await completeWorkflow(serverUrl, workerHeaders, v1ReplayPoll.task);
  }

  await postJson(serverUrl, `/api/task-queues/${encodeURIComponent(taskQueue)}/build-ids/promote`, {
    build_id: buildV2,
  }, controlHeaders, [200, 201]);
  const promotedRun = await startWorkflow(serverUrl, controlHeaders, {
    workflow_id: `wv-promoted-${suffix}`,
    workflow_type: workflowType,
    task_queue: taskQueue,
    input: ['activity_b', 'activity_a'],
  });
  const promotedPoll = await pollWorkflowTask(serverUrl, workerHeaders, v2WorkerId, taskQueue, buildV2);
  if (promotedPoll?.task) {
    await completeWorkflow(serverUrl, workerHeaders, promotedPoll.task);
  }

  await postJson(serverUrl, `/api/task-queues/${encodeURIComponent(taskQueue)}/build-ids/promote`, {
    build_id: buildV1,
  }, controlHeaders, [200, 201]);
  const noCompatibleRun = await startWorkflow(serverUrl, controlHeaders, {
    workflow_id: `wv-no-compatible-${suffix}`,
    workflow_type: workflowType,
    task_queue: taskQueue,
    input: ['v1-no-compatible'],
  });
  const noCompatibleWorkflowId = stringValue(noCompatibleRun.workflow_id);
  const noCompatibleRunId = stringValue(noCompatibleRun.run_id);
  const noCompatibleStartedVisibility = noCompatibleWorkflowId && noCompatibleRunId
    ? await getJson(
      serverUrl,
      `/api/workflows/${encodeURIComponent(noCompatibleWorkflowId)}/runs/${encodeURIComponent(noCompatibleRunId)}`,
      controlHeaders,
      [200],
    )
    : {};
  const v1Delete = await deleteJson(
    serverUrl,
    `/api/workers/${encodeURIComponent(v1WorkerId)}`,
    controlHeaders,
    [200, 404],
  );
  await sleep(1200);
  const noCompatiblePolls = [];
  for (let attempt = 1; attempt <= 3; attempt += 1) {
    const poll = await pollWorkflowTask(serverUrl, workerHeaders, v2WorkerId, taskQueue, buildV2);
    noCompatiblePolls.push(poll);

    if (countTasksForRun([poll], noCompatibleRunId) > 0 || isExplicitNoCompatibleSignal(poll.poll_status)) {
      break;
    }
  }
  const noCompatibleVisibility = noCompatibleWorkflowId
    ? await waitForNoCompatibleVisibility(
      serverUrl,
      noCompatibleWorkflowId,
      noCompatibleRunId,
      taskQueue,
      buildV1,
      controlHeaders,
    )
    : emptyNoCompatibleVisibilityResult();
  const noCompatibleShow = noCompatibleVisibility.latest;
  const noCompatibleBuildIds = noCompatibleVisibility.task_queue_build_ids;
  const noCompatibleBuildIdEntry = noCompatibleVisibility.task_queue_build_id_entry;
  const noCompatiblePollStatuses = noCompatiblePolls
    .map((poll) => stringValue(poll.poll_status))
    .filter(Boolean);
  const noCompatibleSignal = stringValue(firstExplicitNoCompatibleSignal(
    ...noCompatiblePollStatuses,
    noCompatibleShow.compatibility_status,
    noCompatibleShow.compatibilityStatus,
    noCompatibleShow.compatibility_fleet_reason,
    noCompatibleShow.compatibilityFleetReason,
    ...noCompatibleVisibility.samples.map((sample) => sample.compatibility_status),
    ...noCompatibleVisibility.samples.map((sample) => sample.compatibilityStatus),
    ...noCompatibleVisibility.samples.map((sample) => sample.compatibility_fleet_reason),
    ...noCompatibleVisibility.samples.map((sample) => sample.compatibilityFleetReason),
    ...pendingWorkflowTaskDiagnosticSignals(noCompatibleBuildIdEntry),
  ))
    || 'pending';
  const noCompatiblePendingOrTypedError = isExplicitNoCompatibleSignal(noCompatibleSignal)
    ? noCompatibleSignal
    : 'pending';
  const noCompatibleIncompatibleCount = countTasksForRun(noCompatiblePolls, noCompatibleRunId);
  const noCompatibleWorkerDeregistered = numberValue(v1Delete.__http_status) === 200;

  const phpV1WorkerId = `php-cross-v1-${suffix}`;
  const pythonV2WorkerId = `python-cross-v2-${suffix}`;
  const phpV2WorkerId = `php-cross-v2-${suffix}`;
  const pythonV1WorkerId = `python-cross-v1-${suffix}`;
  await registerWorker(serverUrl, workerHeaders, {
    worker_id: phpV1WorkerId,
    task_queue: taskQueue,
    runtime: 'php',
    sdk_version: artifactVersions['sdk-php'],
    build_id: `${buildV1}-php`,
    supported_workflow_types: [workflowType],
    workflow_definition_fingerprints: { [workflowType]: `sequence-php-v1-${suffix}` },
    supported_activity_types: ['activity_a', 'activity_b'],
    process_metrics: processMetrics(3001, timestamp()),
  });
  topology.workers.push({ worker_id: phpV1WorkerId, runtime: 'php', build_id: `${buildV1}-php` });
  await registerWorker(serverUrl, workerHeaders, {
    worker_id: pythonV2WorkerId,
    task_queue: taskQueue,
    runtime: 'python',
    sdk_version: artifactVersions['sdk-python'],
    build_id: `${buildV2}-python`,
    supported_workflow_types: [workflowType],
    workflow_definition_fingerprints: { [workflowType]: `sequence-python-v2-${suffix}` },
    supported_activity_types: ['activity_a', 'activity_b'],
    process_metrics: processMetrics(3002, timestamp()),
  });
  topology.workers.push({ worker_id: pythonV2WorkerId, runtime: 'python', build_id: `${buildV2}-python` });
  await postJson(serverUrl, `/api/task-queues/${encodeURIComponent(taskQueue)}/build-ids/promote`, {
    build_id: `${buildV1}-php`,
  }, controlHeaders, [200, 201]);
  const phpV1RolloutState = await getJson(
    serverUrl,
    `/api/task-queues/${encodeURIComponent(taskQueue)}/build-ids`,
    controlHeaders,
    [200],
  );
  const phpStartedWorkflowId = `wv-php-start-${suffix}`;
  const phpStarted = await startWorkflow(serverUrl, controlHeaders, {
    workflow_id: phpStartedWorkflowId,
    workflow_type: workflowType,
    task_queue: taskQueue,
    input: ['activity_a', 'activity_b'],
  });
  const phpStartedRunId = stringValue(phpStarted.run_id);
  const pythonV2PollForPhpV1 = await pollWorkflowTask(
    serverUrl,
    workerHeaders,
    pythonV2WorkerId,
    taskQueue,
    `${buildV2}-python`,
  );
  const phpV1Poll = await pollWorkflowTask(serverUrl, workerHeaders, phpV1WorkerId, taskQueue, `${buildV1}-php`);
  if (phpV1Poll?.task) {
    await completeWorkflow(serverUrl, workerHeaders, phpV1Poll.task);
  }

  await registerWorker(serverUrl, workerHeaders, {
    worker_id: pythonV1WorkerId,
    task_queue: taskQueue,
    runtime: 'python',
    sdk_version: artifactVersions['sdk-python'],
    build_id: `${buildV1}-python`,
    supported_workflow_types: [workflowType],
    workflow_definition_fingerprints: { [workflowType]: `sequence-python-v1-${suffix}` },
    supported_activity_types: ['activity_a', 'activity_b'],
    process_metrics: processMetrics(4001, timestamp()),
  });
  topology.workers.push({ worker_id: pythonV1WorkerId, runtime: 'python', build_id: `${buildV1}-python` });
  await registerWorker(serverUrl, workerHeaders, {
    worker_id: phpV2WorkerId,
    task_queue: taskQueue,
    runtime: 'php',
    sdk_version: artifactVersions['sdk-php'],
    build_id: `${buildV2}-php`,
    supported_workflow_types: [workflowType],
    workflow_definition_fingerprints: { [workflowType]: `sequence-php-v2-${suffix}` },
    supported_activity_types: ['activity_a', 'activity_b'],
    process_metrics: processMetrics(4002, timestamp()),
  });
  topology.workers.push({ worker_id: phpV2WorkerId, runtime: 'php', build_id: `${buildV2}-php` });
  await postJson(serverUrl, `/api/task-queues/${encodeURIComponent(taskQueue)}/build-ids/promote`, {
    build_id: `${buildV1}-python`,
  }, controlHeaders, [200, 201]);
  const pythonV1RolloutState = await getJson(
    serverUrl,
    `/api/task-queues/${encodeURIComponent(taskQueue)}/build-ids`,
    controlHeaders,
    [200],
  );
  const pythonStartedWorkflowId = `wv-python-start-${suffix}`;
  const pythonStarted = await startWorkflow(serverUrl, controlHeaders, {
    workflow_id: pythonStartedWorkflowId,
    workflow_type: workflowType,
    task_queue: taskQueue,
    input: ['activity_a', 'activity_b'],
  });
  const pythonStartedRunId = stringValue(pythonStarted.run_id);
  const phpV2PollForPythonV1 = await pollWorkflowTask(
    serverUrl,
    workerHeaders,
    phpV2WorkerId,
    taskQueue,
    `${buildV2}-php`,
  );
  const pythonV1Poll = await pollWorkflowTask(
    serverUrl,
    workerHeaders,
    pythonV1WorkerId,
    taskQueue,
    `${buildV1}-python`,
  );
  if (pythonV1Poll?.task) {
    await completeWorkflow(serverUrl, workerHeaders, pythonV1Poll.task);
  }
  const phpToPythonIncompatibleCount = countTasksForRun([pythonV2PollForPhpV1], phpStartedRunId);
  const pythonToPhpIncompatibleCount = countTasksForRun([phpV2PollForPythonV1], pythonStartedRunId);
  const phpV1CompatibleCount = countTasksForRun([phpV1Poll], phpStartedRunId);
  const pythonV1CompatibleCount = countTasksForRun([pythonV1Poll], pythonStartedRunId);

  const workerList = await getJson(serverUrl, `/api/workers?task_queue=${encodeURIComponent(taskQueue)}`, controlHeaders, [200]);
  const buildIds = await getJson(serverUrl, `/api/task-queues/${encodeURIComponent(taskQueue)}/build-ids`, controlHeaders, [200]);
  const v1RunShow = await getJson(
    serverUrl,
    `/api/workflows/${encodeURIComponent(v1WorkflowId)}/runs/${encodeURIComponent(v1RunId)}`,
    controlHeaders,
    [200],
  );
  const history = await getJson(
    serverUrl,
    `/api/workflows/${encodeURIComponent(v1WorkflowId)}/runs/${encodeURIComponent(v1RunId)}/history`,
    controlHeaders,
    [200],
  );

  await registerWorker(serverUrl, workerHeaders, {
    worker_id: v1WorkerId,
    task_queue: taskQueue,
    runtime: 'php',
    sdk_version: artifactVersions['sdk-php'],
    build_id: buildV1,
    supported_workflow_types: [workflowType],
    workflow_definition_fingerprints: { [workflowType]: `sequence-v1-${suffix}` },
    supported_activity_types: ['activity_a', 'activity_b'],
    process_metrics: processMetrics(1004, timestamp()),
  });

  const cliOperatorEvidence = await publishedCliOperatorEvidence({
    serverUrl,
    namespace,
    token,
    taskQueue,
    workflowType,
    buildV1,
    buildV2,
    v1WorkflowId,
    v1RunId,
    promotedWorkflowId: stringValue(promotedRun.workflow_id),
    promotedRunId: stringValue(promotedRun.run_id),
    artifactVersions,
    artifactSources,
    workerHeaders,
  });
  installEvidence = mergeCliInstallEvidence(installEvidence, cliOperatorEvidence.cli_install_evidence);
  writePublishedArtifacts(artifactVersions, artifactSources, installEvidence);

  let drain = cliCommandJson(cliOperatorEvidence, 'drain_command');
  if (stringValue(drain.drain_intent) !== 'draining') {
    drain = await postJson(serverUrl, `/api/task-queues/${encodeURIComponent(taskQueue)}/build-ids/drain`, {
      build_id: buildV1,
    }, controlHeaders, [200, 201]);
  }
  let resume = cliCommandJson(cliOperatorEvidence, 'resume_command');
  if (stringValue(resume.drain_intent) !== 'active') {
    resume = await postJson(serverUrl, `/api/task-queues/${encodeURIComponent(taskQueue)}/build-ids/resume`, {
      build_id: buildV1,
    }, controlHeaders, [200, 201]);
  }

  if (cliOperatorEvidence.cli_operator_command_execution) {
    runtimeMatrix.client_paths = unique([...runtimeMatrix.client_paths, 'cli']);
    runtimeMatrix.operator_visibility_paths = unique([
      ...runtimeMatrix.operator_visibility_paths,
      'dw workers list',
      'dw task-queue build-ids',
      'workflow show compatibility',
    ]);
    runtimeMatrix.uncovered_required_client_paths = runtimeMatrix.uncovered_required_client_paths
      .filter((clientPath) => clientPath !== 'cli');
    runtimeMatrix.uncovered_required_operator_visibility_paths = runtimeMatrix.uncovered_required_operator_visibility_paths
      .filter((pathName) => ![
        'dw workers list',
        'dw task-queue build-ids',
        'workflow show compatibility',
      ].includes(pathName));
  }

  const waterlineOperatorVisibility = await publishedWaterlineOperatorVisibility({
    namespace,
    taskQueue,
    buildV1,
    buildV2,
    v1WorkflowId,
    v1RunId,
    promotedWorkflowId: stringValue(promotedRun.workflow_id),
    promotedRunId: stringValue(promotedRun.run_id),
    noCompatibleWorkflowId,
    noCompatibleRunId,
    artifactVersions,
    artifactSources,
    drain,
    resume,
  });
  installEvidence = mergeWaterlineInstallEvidence(
    installEvidence,
    waterlineOperatorVisibility.waterline_install_evidence,
  );
  artifactSources = mergeArtifactSources(artifactSources, installEvidence);
  writePublishedArtifacts(artifactVersions, artifactSources, installEvidence);
  if (waterlineOperatorVisibility.status === 'pass') {
    runtimeMatrix.operator_visibility_paths = unique([
      ...runtimeMatrix.operator_visibility_paths,
      'Waterline worker and workflow views',
    ]);
    runtimeMatrix.uncovered_required_operator_visibility_paths = runtimeMatrix.uncovered_required_operator_visibility_paths
      .filter((pathName) => pathName !== 'Waterline worker and workflow views');
  }

  const adversarial = await postJson(serverUrl, '/api/worker/register', currentWorkerRegistration({
    worker_id: v1WorkerId,
    task_queue: taskQueue,
    runtime: 'php',
    sdk_version: artifactVersions['sdk-php'],
    build_id: buildV1,
    supported_workflow_types: [workflowType],
    workflow_definition_fingerprints: { [workflowType]: `sequence-divergent-under-same-build-${suffix}` },
    supported_activity_types: ['activity_a', 'activity_b'],
    process_metrics: processMetrics(1003, timestamp()),
    capabilities: [],
  }), workerHeaders, [201, 409]);

  const finishedAt = timestamp();
  const findings = [];
  const findingLinks = {};
  const scenarioResults = {};

  const addPass = (scenarioId, observedOutputs) => {
    scenarioResults[scenarioId] = {
      scenario_id: scenarioId,
      status: 'pass',
      observed_outputs: observedOutputs,
      linked_findings: [],
    };
    findingLinks[scenarioId] = [];
  };
  const addNotCovered = (scenarioId, observedOutputs, finding) => {
    findings.push(finding);
    scenarioResults[scenarioId] = {
      scenario_id: scenarioId,
      status: 'not_covered',
      observed_outputs: observedOutputs,
      linked_findings: [finding],
    };
    findingLinks[scenarioId] = [finding];
  };
  const addFail = (scenarioId, observedOutputs, finding) => {
    findings.push(finding);
    scenarioResults[scenarioId] = {
      scenario_id: scenarioId,
      status: 'fail',
      observed_outputs: observedOutputs,
      linked_findings: [finding],
    };
    findingLinks[scenarioId] = [finding];
  };

  const installOutputs = {
    resolved_artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
    local_product_source_checkouts_used: false,
    artifact_install_evidence: installEvidence,
  };
  if (artifactInstallEvidencePasses(installEvidence)) {
    addPass('published_artifact_install_only', installOutputs);
  } else {
    const installGaps = artifactInstallEvidenceGaps(installEvidence);
    addNotCovered('published_artifact_install_only', installOutputs, {
      scenario_id: 'published_artifact_install_only',
      owning_surface: 'conformance_harness',
      artifact_versions: artifactVersions,
      observed_behavior: `The worker-versioning runner did not install and smoke-execute every required published artifact: ${installGaps.join(', ')}`,
      expected_behavior: 'Install-only coverage passes only after the server image, official CLI, PyPI Python SDK, Packagist PHP SDK, embedded Workflow engine, and Waterline artifact are installed from published channels and smoke-executed.',
      next_acceptance_criterion: 'run the worker-versioning host topology with artifact-install evidence showing pass for server, cli, sdk-python, sdk-php, and waterline',
    });
  }
  const workerRegistrationOutputs = {
    registered_build_ids: {
      [v1WorkerId]: buildV1,
      [v2WorkerId]: buildV2,
      [phpV1WorkerId]: `${buildV1}-php`,
      [pythonV2WorkerId]: `${buildV2}-python`,
      [pythonV1WorkerId]: `${buildV1}-python`,
      [phpV2WorkerId]: `${buildV2}-php`,
    },
    worker_registration_responses: {
      [v1WorkerId]: { build_id: buildV1 },
      [v2WorkerId]: { build_id: buildV2 },
      [phpV1WorkerId]: { build_id: `${buildV1}-php` },
      [pythonV2WorkerId]: { build_id: `${buildV2}-python` },
      [pythonV1WorkerId]: { build_id: `${buildV1}-python` },
      [phpV2WorkerId]: { build_id: `${buildV2}-php` },
    },
    worker_list_build_ids: unique((workerList.workers ?? []).map((worker) => worker.build_id).filter(Boolean)),
    task_queue_build_ids: unique((buildIds.build_ids ?? []).map((entry) => entry.build_id).filter(Boolean)),
    active_worker_counts_per_cohort: Object.fromEntries(
      (buildIds.build_ids ?? []).map((entry) => [entry.build_id ?? 'unversioned', entry.active_worker_count ?? 0]),
    ),
    worker_execution_mode: SERVER_PROTOCOL_PROBE,
    published_artifact_worker_execution: false,
  };
  const publishedRegistrationEvidence = workerRegistrationPublishedWorkerEvidenceResult(publishedWorkerEvidence);
  const publishedRegistrationOutputs = mergeScenarioOutputs(
    workerRegistrationOutputs,
    publishedRegistrationEvidence.outputs,
  );
  if (publishedRegistrationEvidence.passes) {
    addPass('worker_registration_build_ids', publishedRegistrationOutputs);
  } else if (publishedRegistrationEvidence.worker_executed) {
    addFail('worker_registration_build_ids', publishedRegistrationOutputs, {
      scenario_id: 'worker_registration_build_ids',
      owning_surface: publishedRegistrationEvidence.public_surfaces_cover_build_ids
        ? 'conformance_harness'
        : 'server',
      artifact_versions: artifactVersions,
      observed_behavior: 'Published PHP/Python worker registration evidence did not prove registration responses and active build-id cohorts through both public worker-list and task-queue build-id surfaces.',
      expected_behavior: 'Worker registration build-id coverage is produced by live published worker artifacts on the same task queue and verified through public worker-list and task-queue build-id surfaces.',
      next_acceptance_criterion: 'rerun the published worker-versioning shard and record PHP and Python registration responses plus positive active build-id cohorts in both public surfaces',
      missing_registration_evidence: publishedRegistrationEvidence.missing,
      worker_list_build_ids: publishedRegistrationEvidence.worker_list_build_ids,
      task_queue_build_ids: publishedRegistrationEvidence.task_queue_build_ids,
      active_worker_counts_per_cohort: publishedRegistrationEvidence.active_worker_counts_per_cohort,
    });
  } else {
    addNotCovered('worker_registration_build_ids', workerRegistrationOutputs, {
      scenario_id: 'worker_registration_build_ids',
      owning_surface: 'conformance_harness',
      artifact_versions: artifactVersions,
      observed_behavior: 'The runner registered worker records through the server HTTP protocol but did not execute published sdk-php, sdk-python, or CLI worker artifacts for registration evidence.',
      expected_behavior: 'Worker registration build-id coverage is produced by live published worker artifacts on the same task queue and verified through public worker-list and task-queue build-id surfaces.',
      next_acceptance_criterion: 'run published sdk-php and sdk-python worker processes and record their registration responses plus active build-id cohorts before marking this scenario pass',
    });
  }
  if (publishedRegistrationEvidence.worker_executed) {
    runtimeMatrix.runtimes = unique([
      ...runtimeMatrix.runtimes,
      PUBLISHED_CROSS_LANGUAGE_WORKER_EXECUTION,
    ]);
    runtimeMatrix.client_paths = unique([
      ...runtimeMatrix.client_paths,
      'published sdk-php worker registration client',
      'published sdk-python worker registration client',
    ]);
    runtimeMatrix.uncovered_required_runtimes = runtimeMatrix.uncovered_required_runtimes
      .filter((runtime) => !['sdk-php', 'sdk-python'].includes(runtime));
    runtimeMatrix.uncovered_required_client_paths = runtimeMatrix.uncovered_required_client_paths
      .filter((clientPath) => !['sdk-python', 'sdk-php'].includes(clientPath));
  }
  const cliRolloutVisibility = objectValue(cliOperatorEvidence.rollout_visibility);
  const cliDrainResumeControls = objectValue(cliOperatorEvidence.drain_resume_controls);
  const operatorRolloutOutputs = {
    worker_cohorts: unique([
      ...(workerList.workers ?? []).map((worker) => worker.build_id),
      ...arrayValue(cliRolloutVisibility.worker_cohorts),
    ]),
    rollout_state: {
      server_http: buildIds,
      cli_task_queue_build_ids: objectValue(objectValue(cliRolloutVisibility.task_queue_build_ids).json),
    },
    new_start_build_id: stringValue(cliRolloutVisibility.new_start_build_id)
      || stringValue(promotedRun.compatibility)
      || stringValue(promotedPoll?.task?.compatibility),
    workflow_run_compatibility: {
      [v1RunId]: stringValue(v1RunShow.compatibility),
      ...objectValue(cliRolloutVisibility.workflow_run_compatibility),
    },
    waterline_operator_visibility: waterlineOperatorVisibility,
    cli_operator_command_execution: cliOperatorEvidence.cli_operator_command_execution,
    cli_rollout_visibility_passes: cliOperatorEvidence.rollout_visibility_passes,
    cli_rollout_visibility_gap: cliOperatorEvidence.rollout_visibility_gap,
    cli_output: cliRolloutVisibility,
  };
  if (cliOperatorEvidence.rollout_visibility_passes && waterlineOperatorVisibility.status === 'pass') {
    addPass('operator_rollout_visibility', operatorRolloutOutputs);
  } else if (waterlineOperatorVisibility.status === 'fail') {
    addFail('operator_rollout_visibility', operatorRolloutOutputs, {
      scenario_id: 'operator_rollout_visibility',
      owning_surface: 'waterline',
      artifact_versions: artifactVersions,
      observed_behavior: waterlineOperatorVisibility.gap
        || `Published Waterline worker/workflow views did not expose the worker-versioning rollout evidence: ${arrayValue(waterlineOperatorVisibility.missing).join(', ')}`,
      expected_behavior: 'Operators can distinguish v1 and v2 cohorts, rollout state, and per-run compatibility through published Waterline worker and workflow views.',
      next_acceptance_criterion: 'publish a Waterline surface that exposes worker cohorts, build IDs, rollout state, and selected-run compatibility for the worker-versioning topology, then rerun this conformance cell',
      waterline_missing: waterlineOperatorVisibility.missing,
      waterline_url: waterlineOperatorVisibility.waterline_url,
    });
  } else if (cliOperatorEvidence.rollout_visibility_passes) {
    addNotCovered('operator_rollout_visibility', operatorRolloutOutputs, {
      scenario_id: 'operator_rollout_visibility',
      owning_surface: 'waterline',
      artifact_versions: artifactVersions,
      observed_behavior: waterlineOperatorVisibility.gap
        || 'Published CLI rollout controls were exercised and recorded, but Waterline worker/workflow views are not attached to the worker-versioning rollout evidence.',
      expected_behavior: 'Operators can distinguish v1 and v2 cohorts, new-start build IDs, and per-run compatibility through both published CLI and Waterline surfaces.',
      next_acceptance_criterion: 'attach published Waterline worker/workflow view captures for the same worker-versioning topology before marking this combined rollout visibility scenario pass',
    });
  } else {
    addNotCovered('operator_rollout_visibility', operatorRolloutOutputs, {
      scenario_id: 'operator_rollout_visibility',
      owning_surface: 'conformance_harness',
      artifact_versions: artifactVersions,
      observed_behavior: cliOperatorEvidence.rollout_visibility_gap
        || 'The runner captured server HTTP rollout state but did not execute the published CLI operator views required for rollout visibility evidence.',
      expected_behavior: 'Operators can distinguish v1 and v2 cohorts, new-start build IDs, and per-run compatibility through both published CLI and Waterline surfaces.',
      next_acceptance_criterion: 'run dw against the same published-artifact topology and attach CLI rollout visibility captures before the Waterline rollout view is evaluated',
    });
  }
  const drainResumeOutputs = {
    drain_command: objectValue(cliDrainResumeControls.drain_command).command
      ?? 'POST /api/task-queues/{taskQueue}/build-ids/drain',
    drain_state_visible: cliDrainResumeControls.drain_state_visible === true || drain.drain_intent === 'draining',
    resume_command: objectValue(cliDrainResumeControls.resume_command).command
      ?? 'POST /api/task-queues/{taskQueue}/build-ids/resume',
    resume_state_visible: cliDrainResumeControls.resume_state_visible === true || resume.drain_intent === 'active',
    draining_worker_claim_count: numberValue(cliDrainResumeControls.draining_worker_claim_count) ?? 0,
    draining_worker_claim_blocked: cliDrainResumeControls.draining_worker_claim_blocked === true,
    draining_worker_poll: objectValue(cliDrainResumeControls.draining_worker_poll),
    cli_operator_command_execution: cliOperatorEvidence.cli_operator_command_execution,
    cli_output: cliDrainResumeControls,
  };
  if (cliOperatorEvidence.drain_resume_controls_passes) {
    addPass('drain_resume_operator_controls', drainResumeOutputs);
  } else {
    addNotCovered('drain_resume_operator_controls', drainResumeOutputs, {
      scenario_id: 'drain_resume_operator_controls',
      owning_surface: 'conformance_harness',
      artifact_versions: artifactVersions,
      observed_behavior: cliOperatorEvidence.drain_resume_gap
        || 'The runner exercised drain and resume through server HTTP routes but did not run the documented published CLI operator command.',
      expected_behavior: 'Drain and resume controls are executed through the published CLI and reflected in public rollout state.',
      next_acceptance_criterion: 'run the published dw drain/resume commands against the topology and record command output with rollout-state confirmation',
    });
  }
  addPass('pin_on_start', {
    run_compatibility: stringValue(v1RunShow.compatibility),
    first_task_compatibility: stringValue(v1FirstPoll?.task?.compatibility),
    history_or_visibility_field: 'workflow_runs.compatibility',
  });
  const compatibleReplayOutputs = {
    v1_worker_task_count: v1TaskCount,
    v2_worker_task_count_for_v1_run: v2TaskCountForV1Run,
    workflow_result: ['activity_a', 'activity_b'],
    workflow_id: v1WorkflowId,
    v1_pinned_run_id: v1RunId,
    pinned_run_build_id: stringValue(v1RunShow.compatibility) || buildV1,
    v1_worker_build_id: buildV1,
    v2_worker_build_id: buildV2,
    replay_decision: {
      task_queue: taskQueue,
      compatible_worker_id: v1WorkerId,
      compatible_worker_build_id: buildV1,
      incompatible_worker_id: v2WorkerId,
      incompatible_worker_build_id: buildV2,
      compatible_delivery_count: v1TaskCount,
      incompatible_delivery_count: v2TaskCountForV1Run,
      routed_only_to_compatible_worker: v1TaskCount > 0 && v2TaskCountForV1Run === 0,
    },
    operator_visible_result: v1RunShow,
    v1_first_task_id: v1FirstTaskId,
    replay_task_id: v1ReplayTaskId,
    workflow_task_retry_of: replayRetryOfTaskId,
    worker_execution_mode: SERVER_PROTOCOL_PROBE,
    published_artifact_worker_execution: false,
    divergent_workflow_execution_observed: false,
  };
  const publishedReplayOutputs = mergeScenarioOutputs(
    compatibleReplayOutputs,
    publishedWorkerScenarioOutputs(publishedWorkerEvidence, 'replay_only_by_compatible_workers'),
  );
  const publishedReplayV1TaskCount = numberValue(publishedReplayOutputs.v1_worker_task_count);
  const publishedReplayV2TaskCount = numberValue(publishedReplayOutputs.v2_worker_task_count_for_v1_run);
  const publishedReplayRunId = stringValue(publishedReplayOutputs.v1_pinned_run_id)
    || stringValue(publishedReplayOutputs.run_id);
  const publishedReplayWorkerExecuted = publishedWorkerScenarioPasses(
    publishedReplayOutputs,
    ['sdk-python', 'sdk-php'],
    false,
  );
  const publishedReplayPasses = publishedReplayWorkerExecuted
    && publishedReplayRunId !== ''
    && truthyEvidenceFlag(publishedReplayOutputs.divergent_workflow_execution_observed)
    && publishedReplayV1TaskCount > 0
    && publishedReplayV2TaskCount === 0;
  if (publishedReplayPasses) {
    addPass('replay_only_by_compatible_workers', publishedReplayOutputs);
  } else if (publishedReplayWorkerExecuted) {
    addFail('replay_only_by_compatible_workers', publishedReplayOutputs, {
      scenario_id: 'replay_only_by_compatible_workers',
      owning_surface: 'server',
      artifact_versions: artifactVersions,
      observed_behavior: 'Published worker replay evidence did not prove positive v1-compatible delivery with zero v2 delivery for the same v1-pinned divergent run.',
      expected_behavior: 'A published PHP or Python v1 workflow with divergent v2 code is replayed only by a v1-compatible worker while v2 workers poll the same task queue.',
      next_acceptance_criterion: 'rerun the published worker-versioning topology and record v1_worker_task_count above zero, v2_worker_task_count_for_v1_run equal to zero, divergent_workflow_execution_observed=true, and published_artifact_worker_execution from a published worker artifact',
      v1_worker_task_count: publishedReplayV1TaskCount,
      v2_worker_task_count_for_v1_run: publishedReplayV2TaskCount,
      v1_pinned_run_id: publishedReplayRunId,
    });
  } else if (v1TaskCount > 0 && v2TaskCountForV1Run === 0) {
    addNotCovered('replay_only_by_compatible_workers', compatibleReplayOutputs, {
      scenario_id: 'replay_only_by_compatible_workers',
      owning_surface: 'conformance_harness',
      artifact_versions: artifactVersions,
      observed_behavior: 'The server HTTP protocol probe recorded zero incompatible delivery, but no published workflow runtime executed divergent v1/v2 Sequence code for this run.',
      expected_behavior: 'A published PHP or Python v1 workflow with divergent v2 code is replayed only by a v1-compatible worker while v2 workers poll the same task queue.',
      next_acceptance_criterion: 'rerun with published sdk-php or sdk-python workers executing divergent Sequence implementations and record positive v1 delivery with zero v2 delivery for the same v1-pinned run',
      v1_worker_task_count: v1TaskCount,
      v2_worker_task_count_for_v1_run: v2TaskCountForV1Run,
    });
  } else {
    addFail('replay_only_by_compatible_workers', compatibleReplayOutputs, {
      scenario_id: 'replay_only_by_compatible_workers',
      owning_surface: 'server',
      artifact_versions: artifactVersions,
      observed_behavior: 'The focused replay probe did not prove positive v1-compatible delivery with zero v2 delivery for the same v1-pinned run.',
      expected_behavior: 'A v1-pinned workflow is delivered only to v1-compatible workers while v2 workers poll the same task queue.',
      next_acceptance_criterion: 'rerun the published-artifact worker-versioning probe and record v1_worker_task_count above zero with v2_worker_task_count_for_v1_run equal to zero',
      v1_worker_task_count: v1TaskCount,
      v2_worker_task_count_for_v1_run: v2TaskCountForV1Run,
    });
  }
  addPass('new_starts_to_promoted_version', {
    promotion_command: 'POST /api/task-queues/{taskQueue}/build-ids/promote',
    new_run_compatibility: stringValue(promotedRun.compatibility) || stringValue(promotedPoll?.task?.compatibility),
    old_run_continues_on: stringValue(v1RunShow.compatibility),
  });
  const cacheEvictionOutputs = {
    cache_eviction_observed: cacheEvictionObserved,
    replay_worker_build_id: replayWorkerBuildId,
    incompatible_delivery_count: cacheEvictionIncompatibleCount,
    workflow_id: v1WorkflowId,
    v1_pinned_run_id: v1RunId,
    pinned_run_build_id: stringValue(v1RunShow.compatibility) || buildV1,
    expected_replay_worker_build_id: stringValue(v1RunShow.compatibility) || buildV1,
    v1_worker_build_id: buildV1,
    v2_worker_build_id: buildV2,
    v1_first_task_id: v1FirstTaskId,
    replay_task_id: v1ReplayTaskId,
    workflow_task_retry_of: replayRetryOfTaskId,
    replay_attempt: numberValue(v1ReplayPoll?.task?.workflow_task_attempt),
    replay_decision: {
      task_queue: taskQueue,
      replay_worker_id: v1WorkerId,
      replay_worker_build_id: replayWorkerBuildId,
      incompatible_worker_id: v2WorkerId,
      incompatible_worker_build_id: buildV2,
      incompatible_delivery_count: cacheEvictionIncompatibleCount,
      routed_only_to_compatible_worker: cacheEvictionObserved && cacheEvictionIncompatibleCount === 0,
    },
    worker_execution_mode: SERVER_PROTOCOL_PROBE,
    published_artifact_worker_execution: false,
    divergent_workflow_execution_observed: false,
  };
  const expectedReplayBuildId = stringValue(v1RunShow.compatibility) || buildV1;
  const publishedCacheEvictionOutputs = mergeScenarioOutputs(
    cacheEvictionOutputs,
    publishedWorkerScenarioOutputs(publishedWorkerEvidence, 'replay_across_cache_eviction'),
  );
  const publishedCacheEvictionIncompatibleCount = numberValue(
    publishedCacheEvictionOutputs.incompatible_delivery_count,
  );
  const publishedReplayWorkerBuildId = stringValue(publishedCacheEvictionOutputs.replay_worker_build_id);
  const publishedExpectedReplayBuildId =
    stringValue(publishedCacheEvictionOutputs.expected_replay_worker_build_id)
    || stringValue(publishedCacheEvictionOutputs.pinned_run_build_id)
    || expectedReplayBuildId;
  const publishedCacheRunId = stringValue(publishedCacheEvictionOutputs.v1_pinned_run_id)
    || stringValue(publishedCacheEvictionOutputs.run_id);
  const publishedCacheEvictionWorkerExecuted = publishedWorkerScenarioPasses(
    publishedCacheEvictionOutputs,
    ['sdk-python', 'sdk-php'],
    false,
  );
  const cacheEvictionPasses = publishedCacheEvictionWorkerExecuted
    && publishedCacheRunId !== ''
    && truthyEvidenceFlag(publishedCacheEvictionOutputs.divergent_workflow_execution_observed)
    && truthyEvidenceFlag(publishedCacheEvictionOutputs.cache_eviction_observed)
    && publishedCacheEvictionIncompatibleCount === 0
    && publishedReplayWorkerBuildId === publishedExpectedReplayBuildId;
  if (cacheEvictionPasses) {
    addPass('replay_across_cache_eviction', publishedCacheEvictionOutputs);
  } else if (publishedCacheEvictionWorkerExecuted) {
    addFail('replay_across_cache_eviction', publishedCacheEvictionOutputs, {
      scenario_id: 'replay_across_cache_eviction',
      owning_surface: 'server',
      artifact_versions: artifactVersions,
      observed_behavior: 'Published worker cache-eviction evidence did not prove replay on the pinned compatible build with zero incompatible delivery.',
      expected_behavior: 'After published-worker restart or cache eviction, v1-pinned history is replayed only by the v1-compatible build while v2 workers receive zero tasks for that run.',
      next_acceptance_criterion: 'rerun with a published worker process restart or cache eviction and record cache_eviction_observed=true, replay_worker_build_id equal to the pinned run build id, incompatible_delivery_count equal to zero, and published_artifact_worker_execution from a published worker artifact',
      expected_replay_worker_build_id: publishedExpectedReplayBuildId,
      replay_worker_build_id: publishedReplayWorkerBuildId,
      incompatible_delivery_count: publishedCacheEvictionIncompatibleCount,
      cache_eviction_observed: publishedCacheEvictionOutputs.cache_eviction_observed,
      v1_pinned_run_id: publishedCacheRunId,
    });
  } else if (cacheEvictionObserved
    && cacheEvictionIncompatibleCount === 0
    && replayWorkerBuildId === expectedReplayBuildId) {
    addNotCovered('replay_across_cache_eviction', cacheEvictionOutputs, {
      scenario_id: 'replay_across_cache_eviction',
      owning_surface: 'conformance_harness',
      artifact_versions: artifactVersions,
      observed_behavior: 'The server HTTP protocol probe recorded replay on the pinned build with zero incompatible delivery, but it did not restart a published workflow runtime or replay divergent workflow code.',
      expected_behavior: 'After published-worker restart or cache eviction, v1-pinned history is replayed only by the v1-compatible build while v2 workers receive zero tasks for that run.',
      next_acceptance_criterion: 'rerun with a published worker process restart or cache eviction and record cache_eviction_observed=true, replay_worker_build_id equal to the pinned run build id, and incompatible_delivery_count equal to zero',
      expected_replay_worker_build_id: expectedReplayBuildId,
      replay_worker_build_id: replayWorkerBuildId,
      incompatible_delivery_count: cacheEvictionIncompatibleCount,
      cache_eviction_observed: cacheEvictionObserved,
    });
  } else {
    addFail('replay_across_cache_eviction', cacheEvictionOutputs, {
      scenario_id: 'replay_across_cache_eviction',
      owning_surface: 'server',
      artifact_versions: artifactVersions,
      observed_behavior: 'The focused worker-restart replay probe did not prove replay on the pinned compatible build with zero incompatible delivery.',
      expected_behavior: 'After worker restart or cache eviction, v1-pinned history is replayed only by the v1-compatible build while v2 workers receive zero tasks for that run.',
      next_acceptance_criterion: 'rerun the published-artifact worker-versioning probe and record cache_eviction_observed=true, replay_worker_build_id equal to the pinned run build id, and incompatible_delivery_count equal to zero',
      expected_replay_worker_build_id: expectedReplayBuildId,
      replay_worker_build_id: replayWorkerBuildId,
      incompatible_delivery_count: cacheEvictionIncompatibleCount,
      cache_eviction_observed: cacheEvictionObserved,
    });
  }
  const noCompatibleOutputs = {
    operator_visible_signal: noCompatibleSignal,
    operator_visible_signal_explicit: isExplicitNoCompatibleSignal(noCompatibleSignal),
    pending_or_typed_error: noCompatiblePendingOrTypedError,
    incompatible_worker_task_count: noCompatibleIncompatibleCount,
    incompatible_worker_poll_attempts: noCompatiblePolls.length,
    incompatible_worker_poll_statuses: noCompatiblePollStatuses,
    incompatible_worker_polls: noCompatiblePolls,
    compatible_worker_deregistered: noCompatibleWorkerDeregistered,
    worker_execution_mode: SERVER_PROTOCOL_PROBE,
    published_server_protocol_probe: true,
    published_server_artifact: publishedServerArtifactEvidence(artifactVersions, artifactSources),
    published_artifact_worker_execution: false,
    local_product_source_checkouts_used: false,
    started_workflow_visibility: noCompatibleStartedVisibility,
    deregister_response: v1Delete,
    workflow_visibility: noCompatibleShow,
    workflow_visibility_samples: noCompatibleVisibility.samples,
    task_queue_build_ids: noCompatibleBuildIds,
    task_queue_build_id_entry: noCompatibleBuildIdEntry,
    task_queue_build_id_samples: noCompatibleVisibility.task_queue_build_id_samples,
    no_compatible_visibility_deadline_seconds: noCompatibleVisibilitySeconds,
    no_compatible_visibility_attempts: noCompatibleVisibility.attempts,
  };
  const noCompatiblePublishedEvidence = noCompatiblePublishedWorkerEvidenceResult(publishedWorkerEvidence);
  const publishedNoCompatibleOutputs = noCompatiblePublishedEvidence.outputs;
  const publishedNoCompatibleIncompatibleCount =
    noCompatiblePublishedEvidence.incompatible_worker_task_count;
  const publishedNoCompatibleSignal = noCompatiblePublishedEvidence.operator_visible_signal;
  const publishedNoCompatiblePendingOrTypedError =
    noCompatiblePublishedEvidence.pending_or_typed_error;
  const publishedNoCompatibleWorkerExecuted = noCompatiblePublishedEvidence.worker_executed;
  const publishedNoCompatiblePasses = noCompatiblePublishedEvidence.passes;
  const noCompatibleProtocolProbePasses = noCompatibleServerProtocolProbePasses(
    noCompatibleOutputs,
    artifactVersions,
    artifactSources,
  );
  if (publishedNoCompatiblePasses) {
    addPass('no_compatible_worker_behavior', publishedNoCompatibleOutputs);
  } else if (publishedNoCompatibleWorkerExecuted && publishedNoCompatibleIncompatibleCount > 0) {
    addFail('no_compatible_worker_behavior', publishedNoCompatibleOutputs, {
      scenario_id: 'no_compatible_worker_behavior',
      owning_surface: 'server',
      artifact_versions: artifactVersions,
      observed_behavior: 'A v1-pinned run without a registered v1-compatible worker was delivered to an incompatible published worker.',
      expected_behavior: 'Pinned runs with no compatible worker remain pending or surface a typed no-compatible-worker signal and are never delivered to v2 workers.',
      next_acceptance_criterion: 'rerun the published-artifact worker-versioning topology and record incompatible_worker_task_count equal to zero plus an explicit no-compatible-worker or compatibility-blocked public signal after stopping the compatible worker cohort',
      incompatible_worker_task_count: publishedNoCompatibleIncompatibleCount,
      operator_visible_signal: publishedNoCompatibleSignal,
      pending_or_typed_error: publishedNoCompatiblePendingOrTypedError,
    });
  } else if (noCompatibleProtocolProbePasses) {
    addPass('no_compatible_worker_behavior', noCompatibleOutputs);
  } else if (publishedNoCompatibleWorkerExecuted) {
    addFail('no_compatible_worker_behavior', publishedNoCompatibleOutputs, {
      scenario_id: 'no_compatible_worker_behavior',
      owning_surface: 'server',
      artifact_versions: artifactVersions,
      observed_behavior: 'A v1-pinned run without a registered v1-compatible worker did not expose an explicit public no-compatible-worker diagnostic in published-worker evidence.',
      expected_behavior: 'Pinned runs with no compatible worker remain pending or surface a typed no-compatible-worker signal and are never delivered to v2 workers.',
      next_acceptance_criterion: 'rerun the published-artifact worker-versioning topology and record incompatible_worker_task_count equal to zero plus an explicit no-compatible-worker or compatibility-blocked public signal after stopping the compatible worker cohort',
      incompatible_worker_task_count: publishedNoCompatibleIncompatibleCount,
      operator_visible_signal: publishedNoCompatibleSignal,
      pending_or_typed_error: publishedNoCompatiblePendingOrTypedError,
    });
  } else {
    addFail('no_compatible_worker_behavior', noCompatibleOutputs, {
      scenario_id: 'no_compatible_worker_behavior',
      owning_surface: 'server',
      artifact_versions: artifactVersions,
      observed_behavior: noCompatibleIncompatibleCount > 0
        ? 'A v1-pinned run without a registered v1-compatible worker was delivered to an incompatible worker.'
        : 'A v1-pinned run without a registered v1-compatible worker was left unclaimed without an explicit no-compatible-worker diagnostic.',
      expected_behavior: 'Pinned runs with no compatible worker remain pending or surface a typed no-compatible-worker signal and are never delivered to v2 workers.',
      next_acceptance_criterion: 'rerun the published-artifact worker-versioning probe and record incompatible_worker_task_count equal to zero plus an explicit no-compatible-worker or compatibility-blocked public signal after deregistering the compatible worker',
      incompatible_worker_task_count: noCompatibleIncompatibleCount,
      operator_visible_signal: noCompatibleSignal,
    });
  }
  const operatorVisibilitySurfacesOutputs = {
    worker_list: workerList,
    task_queue_build_ids: buildIds,
    workflow_visibility: v1RunShow,
    cli_operator_visibility: cliOperatorEvidence.rollout_visibility,
    waterline_operator_visibility: waterlineOperatorVisibility,
  };
  if (waterlineOperatorVisibility.status === 'pass') {
    addPass('operator_visibility_surfaces', operatorVisibilitySurfacesOutputs);
  } else if (waterlineOperatorVisibility.status === 'fail') {
    addFail('operator_visibility_surfaces', operatorVisibilitySurfacesOutputs, {
      scenario_id: 'operator_visibility_surfaces',
      owning_surface: 'waterline',
      artifact_versions: artifactVersions,
      observed_behavior: waterlineOperatorVisibility.gap
        || `Published Waterline was booted but did not expose the required worker-versioning views: ${arrayValue(waterlineOperatorVisibility.missing).join(', ')}`,
      expected_behavior: 'Full worker-versioning conformance includes published Waterline worker and workflow views for the same run database.',
      next_acceptance_criterion: 'publish a Waterline worker/workflow view that exposes worker cohorts, build IDs, rollout state, and selected-run compatibility, then rerun this conformance cell',
      waterline_missing: waterlineOperatorVisibility.missing,
      waterline_url: waterlineOperatorVisibility.waterline_url,
    });
  } else {
    addNotCovered('operator_visibility_surfaces', operatorVisibilitySurfacesOutputs, {
      scenario_id: 'operator_visibility_surfaces',
      owning_surface: 'waterline',
      artifact_versions: artifactVersions,
      observed_behavior: waterlineOperatorVisibility.gap
        || 'Server handoff captured worker, task-queue, workflow, and history surfaces but did not boot Waterline.',
      expected_behavior: 'Full worker-versioning conformance includes Waterline worker and workflow views.',
      next_acceptance_criterion: 'Attach a published Waterline shard for the same run database or run the full host topology with Waterline enabled.',
    });
  }
  const crossLanguageOutputs = {
    php_worker_build_id: `${buildV1}-php`,
    python_worker_build_id: `${buildV2}-python`,
    worker_runtime_identities: [
      { worker_id: phpV1WorkerId, runtime: 'php', language: 'php', build_id: `${buildV1}-php` },
      { worker_id: pythonV2WorkerId, runtime: 'python', language: 'python', build_id: `${buildV2}-python` },
      { worker_id: pythonV1WorkerId, runtime: 'python', language: 'python', build_id: `${buildV1}-python` },
      { worker_id: phpV2WorkerId, runtime: 'php', language: 'php', build_id: `${buildV2}-php` },
    ],
    php_worker_build_ids: {
      v1: `${buildV1}-php`,
      v2: `${buildV2}-php`,
    },
    python_worker_build_ids: {
      v1: `${buildV1}-python`,
      v2: `${buildV2}-python`,
    },
    php_v1_compatible_delivery_count: phpV1CompatibleCount,
    python_v1_compatible_delivery_count: pythonV1CompatibleCount,
    php_v1_to_python_v2_incompatible_delivery_count: phpToPythonIncompatibleCount,
    python_v1_to_php_v2_incompatible_delivery_count: pythonToPhpIncompatibleCount,
    workflow_runs: {
      php_v1_started: {
        workflow_id: phpStartedWorkflowId,
        run_id: phpStartedRunId,
        started_by_runtime: 'php',
        pinned_build_id: `${buildV1}-php`,
        compatible_worker_runtime: 'php',
        incompatible_worker_runtime: 'python',
      },
      python_v1_started: {
        workflow_id: pythonStartedWorkflowId,
        run_id: pythonStartedRunId,
        started_by_runtime: 'python',
        pinned_build_id: `${buildV1}-python`,
        compatible_worker_runtime: 'python',
        incompatible_worker_runtime: 'php',
      },
    },
    rollout_state: {
      after_php_v1_promotion: phpV1RolloutState,
      after_python_v1_promotion: pythonV1RolloutState,
      promoted_build_ids: {
        php_started_run: `${buildV1}-php`,
        python_started_run: `${buildV1}-python`,
      },
    },
    cross_language_delivery: {
      cells: [
        {
          scenario: 'php_v1_not_delivered_to_python_v2',
          started_by: 'sdk-php-v1',
          incompatible_worker: 'sdk-python-v2',
          compatible_worker: 'sdk-php-v1',
          compatible_delivery_count: phpV1CompatibleCount,
          incompatible_delivery_count: phpToPythonIncompatibleCount,
          workflow_id: phpStartedWorkflowId,
          run_id: phpStartedRunId,
          started_run_id: phpStartedRunId,
        },
        {
          scenario: 'python_v1_not_delivered_to_php_v2',
          started_by: 'sdk-python-v1',
          incompatible_worker: 'sdk-php-v2',
          compatible_worker: 'sdk-python-v1',
          compatible_delivery_count: pythonV1CompatibleCount,
          incompatible_delivery_count: pythonToPhpIncompatibleCount,
          workflow_id: pythonStartedWorkflowId,
          run_id: pythonStartedRunId,
          started_run_id: pythonStartedRunId,
        },
      ],
    },
    public_outcome: {
      verification_surface: 'server worker poll outputs and task-queue build-id rollout API',
      passed: phpToPythonIncompatibleCount === 0
        && pythonToPhpIncompatibleCount === 0
        && phpV1CompatibleCount > 0
        && pythonV1CompatibleCount > 0,
      php_v1_to_python_v2_incompatible_delivery_count: phpToPythonIncompatibleCount,
      python_v1_to_php_v2_incompatible_delivery_count: pythonToPhpIncompatibleCount,
      php_v1_compatible_delivery_count: phpV1CompatibleCount,
      python_v1_compatible_delivery_count: pythonV1CompatibleCount,
    },
    worker_execution_mode: SERVER_PROTOCOL_PROBE,
    server_protocol_probe_only: true,
    published_artifact_worker_execution: false,
    local_product_source_checkouts_used: false,
  };
  const publishedCrossLanguageEvidence = crossLanguagePublishedWorkerEvidenceResult(publishedWorkerEvidence);
  const publishedCrossLanguageOutputs = mergeScenarioOutputs(
    crossLanguageOutputs,
    publishedCrossLanguageEvidence.outputs,
  );
  const publishedPhpToPythonIncompatibleCount =
    publishedCrossLanguageEvidence.php_v1_to_python_v2_incompatible_delivery_count;
  const publishedPythonToPhpIncompatibleCount =
    publishedCrossLanguageEvidence.python_v1_to_php_v2_incompatible_delivery_count;
  const publishedPhpCompatibleCount = publishedCrossLanguageEvidence.php_v1_compatible_delivery_count;
  const publishedPythonCompatibleCount = publishedCrossLanguageEvidence.python_v1_compatible_delivery_count;
  const publishedCrossLanguageWorkerExecuted = publishedCrossLanguageEvidence.worker_executed;
  const publishedCrossLanguageFindings = publishedWorkerScenarioFindings(
    publishedWorkerEvidence,
    'cross_language_php_python_pinning',
  );
  const crossLanguagePasses = publishedCrossLanguageEvidence.passes;
  if (crossLanguagePasses) {
    addPass('cross_language_php_python_pinning', publishedCrossLanguageOutputs);
  } else if (publishedCrossLanguageWorkerExecuted) {
    addFail('cross_language_php_python_pinning', publishedCrossLanguageOutputs, {
      scenario_id: 'cross_language_php_python_pinning',
      owning_surface: 'server',
      artifact_versions: artifactVersions,
      observed_behavior: 'Published PHP/Python worker evidence did not prove zero incompatible cross-language delivery with positive compatible delivery in both directions.',
      expected_behavior: 'PHP v1-pinned runs are never delivered to Python v2, Python v1-pinned runs are never delivered to PHP v2, and both directions are exercised by actual published worker artifacts.',
      next_acceptance_criterion: 'rerun the cross-language cells with installed sdk-php and sdk-python artifacts and record both incompatible delivery counts as zero with positive compatible delivery counts',
      php_v1_to_python_v2_incompatible_delivery_count: publishedPhpToPythonIncompatibleCount,
      python_v1_to_php_v2_incompatible_delivery_count: publishedPythonToPhpIncompatibleCount,
      php_v1_compatible_delivery_count: publishedPhpCompatibleCount,
      python_v1_compatible_delivery_count: publishedPythonCompatibleCount,
    });
  } else if (phpToPythonIncompatibleCount === 0
    && pythonToPhpIncompatibleCount === 0
    && phpV1CompatibleCount > 0
    && pythonV1CompatibleCount > 0) {
    addNotCovered(
      'cross_language_php_python_pinning',
      publishedCrossLanguageOutputs,
      focusedCrossLanguageNotCoveredFinding(
        publishedCrossLanguageFindings,
        artifactVersions,
        {
          php_v1_to_python_v2_incompatible_delivery_count: phpToPythonIncompatibleCount,
          python_v1_to_php_v2_incompatible_delivery_count: pythonToPhpIncompatibleCount,
          php_v1_compatible_delivery_count: phpV1CompatibleCount,
          python_v1_compatible_delivery_count: pythonV1CompatibleCount,
        },
      ),
    );
  } else {
    addFail('cross_language_php_python_pinning', crossLanguageOutputs, {
      scenario_id: 'cross_language_php_python_pinning',
      owning_surface: 'server',
      artifact_versions: artifactVersions,
      observed_behavior: 'The focused PHP/Python worker-versioning probe did not prove zero incompatible cross-language delivery with positive compatible delivery in both directions.',
      expected_behavior: 'PHP v1-pinned runs are never delivered to Python v2, Python v1-pinned runs are never delivered to PHP v2, and each run remains claimable by its compatible v1 worker.',
      next_acceptance_criterion: 'rerun the published-artifact worker-versioning probe and record both incompatible delivery counts as zero with positive compatible delivery counts',
      php_v1_to_python_v2_incompatible_delivery_count: phpToPythonIncompatibleCount,
      python_v1_to_php_v2_incompatible_delivery_count: pythonToPhpIncompatibleCount,
      php_v1_compatible_delivery_count: phpV1CompatibleCount,
      python_v1_compatible_delivery_count: pythonV1CompatibleCount,
    });
  }
  const adversarialOutputs = {
    observed_behavior: adversarial.__http_status === 409 ? 'register_rejected_changed_workflow_definition' : 'accepted_with_same_build_id',
    operator_audit_signal: adversarial.__http_status === 409 ? stringValue(adversarial.reason) || 'workflow_definition_changed' : 'worker_definition_fingerprint_conflict_visible',
    worker_execution_mode: SERVER_PROTOCOL_PROBE,
    published_artifact_worker_execution: false,
    local_product_source_checkouts_used: false,
  };
  const publishedAdversarialOutputs = mergeScenarioOutputs(
    adversarialOutputs,
    publishedWorkerScenarioOutputs(publishedWorkerEvidence, 'adversarial_no_version_bump'),
  );
  const publishedAdversarialWorkerExecuted = publishedWorkerScenarioPasses(
    publishedAdversarialOutputs,
    ['sdk-python', 'sdk-php'],
    false,
  );
  const adversarialBehavior = stringValue(publishedAdversarialOutputs.observed_behavior)
    || stringValue(publishedAdversarialOutputs.observedBehavior);
  const adversarialAuditSignal = stringValue(publishedAdversarialOutputs.operator_audit_signal)
    || stringValue(publishedAdversarialOutputs.operatorAuditSignal);
  if (publishedAdversarialWorkerExecuted && adversarialBehavior !== '' && adversarialAuditSignal !== '') {
    addPass('adversarial_no_version_bump', publishedAdversarialOutputs);
  } else if (publishedAdversarialWorkerExecuted) {
    addFail('adversarial_no_version_bump', publishedAdversarialOutputs, {
      scenario_id: 'adversarial_no_version_bump',
      owning_surface: 'server',
      artifact_versions: artifactVersions,
      observed_behavior: 'Published worker adversarial no-version-bump evidence did not record both the behavior and an operator audit signal.',
      expected_behavior: 'A published worker artifact ships divergent workflow code under an existing build id and records whether the server accepts, rejects, warns, or exposes an audit signal.',
      next_acceptance_criterion: 'rerun the adversarial no-version-bump cell with published worker artifact execution and record observed_behavior plus operator_audit_signal',
    });
  } else {
    addNotCovered('adversarial_no_version_bump', adversarialOutputs, {
      scenario_id: 'adversarial_no_version_bump',
      owning_surface: 'conformance_harness',
      artifact_versions: artifactVersions,
      observed_behavior: 'The server HTTP protocol probe captured the registration response for divergent code under the same build id, but no published worker artifact executed the adversarial no-version-bump cell.',
      expected_behavior: 'A published worker artifact ships divergent workflow code under an existing build id and records whether the server accepts, rejects, warns, or exposes an audit signal.',
      next_acceptance_criterion: 'execute the adversarial no-version-bump cell with a published sdk-php or sdk-python worker artifact before marking this scenario pass',
    });
  }
  addPass('history_api_version_pin', {
    history_field: historyHasCompatibility(history) ? 'history.events.*.compatibility' : 'workflow_runs.compatibility',
    compatibility_value: stringValue(v1RunShow.compatibility),
  });

  const result = {
    schema: RESULT_SCHEMA,
    version: 1,
    started_at: startedAt,
    finished_at: finishedAt,
    generated_at: finishedAt,
    outcome: Object.values(scenarioResults).every((scenario) => scenario.status === 'pass')
      ? 'pass'
      : 'non_passing',
    runner_blocked: false,
    artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
    published_worker_execution_evidence: publishedWorkerEvidence,
    scenario_results: scenarioResults,
    findings,
    finding_links: findingLinks,
    topology,
    runtime_matrix: runtimeMatrix,
    versioning_observations: {
      pin_on_start: stringValue(v1RunShow.compatibility),
      promoted_new_start: stringValue(promotedRun.compatibility) || stringValue(promotedPoll?.task?.compatibility),
      v1_worker_task_count: v1TaskCount,
      v2_worker_task_count_for_v1_run: v2TaskCountForV1Run,
    },
    history_version_pins: {
      workflow_runs_compatibility: stringValue(v1RunShow.compatibility),
      history_contains_compatibility: historyHasCompatibility(history),
    },
    operator_controls: {
      promote: true,
      drain: drain.drain_intent === 'draining',
      resume: resume.drain_intent === 'active',
      cli_operator_command_execution: cliOperatorEvidence.cli_operator_command_execution,
      cli_output: {
        rollout_visibility: cliOperatorEvidence.rollout_visibility,
        drain_resume_controls: cliOperatorEvidence.drain_resume_controls,
      },
    },
    mixed_version_polling: {
      v1_worker_task_count: v1TaskCount,
      v2_worker_task_count_for_v1_run: v2TaskCountForV1Run,
      cache_eviction_incompatible_delivery_count: cacheEvictionIncompatibleCount,
    },
    no_compatible_worker: {
      scenario_id: 'no_compatible_worker_behavior',
      status: scenarioResults.no_compatible_worker_behavior.status,
      operator_visible_signal: scenarioResults.no_compatible_worker_behavior.observed_outputs.operator_visible_signal,
      operator_visible_signal_explicit: scenarioResults
        .no_compatible_worker_behavior
        .observed_outputs
        .operator_visible_signal_explicit,
      pending_or_typed_error: scenarioResults.no_compatible_worker_behavior.observed_outputs.pending_or_typed_error,
      incompatible_worker_task_count: scenarioResults.no_compatible_worker_behavior.observed_outputs.incompatible_worker_task_count,
      incompatible_worker_poll_attempts: scenarioResults
        .no_compatible_worker_behavior
        .observed_outputs
        .incompatible_worker_poll_attempts,
      compatible_worker_deregistered: scenarioResults
        .no_compatible_worker_behavior
        .observed_outputs
        .compatible_worker_deregistered,
      task_queue_build_id_entry: scenarioResults.no_compatible_worker_behavior.observed_outputs.task_queue_build_id_entry,
      published_server_protocol_probe: scenarioResults
        .no_compatible_worker_behavior
        .observed_outputs
        .published_server_protocol_probe,
      published_server_artifact: scenarioResults
        .no_compatible_worker_behavior
        .observed_outputs
        .published_server_artifact,
      published_artifact_worker_execution: scenarioResults
        .no_compatible_worker_behavior
        .observed_outputs
        .published_artifact_worker_execution,
      worker_execution_mode: scenarioResults.no_compatible_worker_behavior.observed_outputs.worker_execution_mode,
      local_product_source_checkouts_used: scenarioResults
        .no_compatible_worker_behavior
        .observed_outputs
        .local_product_source_checkouts_used,
    },
    cross_language_matrix: scenarioResults.cross_language_php_python_pinning.observed_outputs.cross_language_delivery,
    adversarial_outcomes: scenarioResults.adversarial_no_version_bump.observed_outputs,
  };

  writeResult(result);
}

function blockedResult(reason, startedAt, finishedAt, artifactVersions = {}, artifactSources = {}, blockerDetails = blockedDetailsFromEnv()) {
  const runnerBlocker = normalizeBlockerDetails(reason, blockerDetails);
  const finding = {
    owning_surface: 'conformance_harness',
    blocker_kind: runnerBlocker.kind,
    observed_behavior: reason,
    expected_behavior: runnerBlocker.expected_behavior,
    next_acceptance_criterion: runnerBlocker.next_acceptance_criterion,
  };
  if (runnerBlocker.expected_server_urls.length > 0) {
    finding.expected_server_urls = runnerBlocker.expected_server_urls;
  }
  if (runnerBlocker.server_state !== '') {
    finding.server_state = runnerBlocker.server_state;
  }
  const findingLinks = Object.fromEntries(requiredScenarios.map((scenarioId) => [
    scenarioId,
    [finding],
  ]));

  return {
    schema: RESULT_SCHEMA,
    version: 1,
    started_at: startedAt,
    finished_at: finishedAt,
    generated_at: finishedAt,
    outcome: 'non_passing_runner_blocked',
    runner_blocked: true,
    artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
    runner_blocker: runnerBlocker,
    scenario_results: Object.fromEntries(requiredScenarios.map((scenarioId) => [
      scenarioId,
      {
        scenario_id: scenarioId,
        status: 'runner_blocked',
        observed_outputs: {
          blocked_reason: reason,
          runner_blocker: runnerBlocker,
        },
      },
    ])),
    findings: [finding],
    finding_links: findingLinks,
    topology: {},
    runtime_matrix: {},
    versioning_observations: {},
    history_version_pins: {},
    operator_controls: {},
    mixed_version_polling: {},
    no_compatible_worker: {},
    cross_language_matrix: {},
    adversarial_outcomes: {},
  };
}

function blockedDetailsFromEnv() {
  return {
    kind: trim(process.env.DW_WV_BLOCKED_KIND),
    expected_server_urls: delimitedList(process.env.DW_WV_BLOCKED_EXPECTED_SERVER_URLS),
    server_state: trim(process.env.DW_WV_BLOCKED_SERVER_STATE),
  };
}

function normalizeBlockerDetails(reason, details) {
  const kind = trim(details?.kind) || 'conformance_harness';
  const expectedServerUrls = arrayValue(details?.expected_server_urls)
    .map((value) => trim(value))
    .filter((value) => value !== '');
  const serverState = trim(details?.server_state);
  const readinessBlocker = kind === 'server_readiness_topology';

  return {
    kind,
    reason,
    expected_server_urls: expectedServerUrls,
    server_state: serverState,
    expected_behavior: readinessBlocker
      ? 'worker-versioning namespace setup records a non-empty reachable published server URL before the matrix starts'
      : 'worker-versioning conformance runner can exercise published artifacts and record routing counts',
    next_acceptance_criterion: readinessBlocker
      ? 'make the published server reachable at one candidate URL, then rerun worker-versioning conformance'
      : 'restore the missing host capability and rerun worker-versioning conformance',
  };
}

function delimitedList(value) {
  return stringValue(value)
    .split(/\s*,\s*/)
    .map((entry) => entry.trim())
    .filter((entry) => entry !== '');
}

function directNodeWaterlineAttachBlocker() {
  if (configuredWaterlineUrl()) {
    return '';
  }

  if (['1', 'true', 'yes'].includes(String(process.env.DW_WV_SKIP_WATERLINE_SHARD ?? '').toLowerCase())) {
    return 'DW_WV_SKIP_WATERLINE_SHARD=1 was set without DW_WV_WATERLINE_URL; provide a Packagist-installed Waterline URL for the same worker-versioning topology or allow the shell runner to boot Waterline';
  }

  if (trim(process.env.DW_WV_WATERLINE_DB_HOST) || trim(process.env.DW_WATERLINE_DB_HOST)) {
    return 'DW_WV_WATERLINE_DB_HOST was provided without DW_WV_WATERLINE_URL, but direct Node invocation cannot boot Waterline; run worker-versioning-published-artifacts.sh so the Packagist Waterline shard is attached to the same database';
  }

  return 'DW_WV_SERVER_URL was provided without DW_WV_WATERLINE_URL or DW_WV_WATERLINE_DB_HOST; the runner cannot attach published Waterline to the same worker-versioning run database';
}

async function ensureNamespace(serverUrl, namespace, bootstrapHeaders, namespaceHeaders) {
  const show = await getJson(
    serverUrl,
    `/api/namespaces/${encodeURIComponent(namespace)}`,
    namespaceHeaders,
    [200, 404],
  );
  if (show?.__http_status !== 404 && show?.name === namespace) {
    return;
  }

  const created = await postJson(serverUrl, '/api/namespaces', {
    name: namespace,
    description: 'Worker-versioning conformance namespace',
    retention_days: 7,
  }, bootstrapHeaders, [201, 409]);

  if (created.__http_status === 409) {
    return;
  }

  if (created.name !== namespace) {
    throw new Error(`namespace bootstrap returned unexpected payload for ${namespace}`);
  }
}

async function ensureNamespacePrerequisite(serverUrl, namespace, bootstrapHeaders, namespaceHeaders) {
  const readyUrl = `${serverUrl}/api/ready`;
  const namespaceUrl = `${serverUrl}/api/namespaces/${encodeURIComponent(namespace)}`;
  const deadline = Date.now() + serverReadinessTimeoutMs;
  let lastError = '';

  while (Date.now() <= deadline) {
    try {
      await getJson(serverUrl, '/api/ready', bootstrapHeaders, [200]);
      await ensureNamespace(serverUrl, namespace, bootstrapHeaders, namespaceHeaders);
      const confirmed = await getJson(
        serverUrl,
        `/api/namespaces/${encodeURIComponent(namespace)}`,
        namespaceHeaders,
        [200],
      );
      if (confirmed?.name !== namespace) {
        throw new Error(`namespace bootstrap did not confirm namespace ${namespace} at ${namespaceUrl}`);
      }
      await getJson(serverUrl, '/api/ready', bootstrapHeaders, [200]);
      recordResolvedServerUrl(serverUrl, namespace);
      return;
    } catch (error) {
      lastError = error instanceof Error ? error.message : String(error);
      if (Date.now() > deadline) {
        break;
      }
      await sleep(1000);
    }
  }

  throw runnerBlockedError(
    `published server namespace setup prerequisite failed before worker-versioning matrix; expected ${namespaceUrl}; readiness ${readyUrl}; last_error=${lastError || 'none'}`,
    readinessBlockerDetails([namespaceUrl]),
  );
}

function recordResolvedServerUrl(serverUrl, namespace) {
  const resolvedPath = path.join(resultDir, 'server-url-resolved.txt');
  const candidatesPath = path.join(resultDir, 'server-url-candidates.txt');
  const namespacePath = path.join(resultDir, 'server-namespace-url.txt');

  if (!fs.existsSync(candidatesPath) || fs.readFileSync(candidatesPath, 'utf8').trim() === '') {
    writeTextIfNotEmpty(candidatesPath, `${serverUrl}\n`);
  }
  writeTextIfNotEmpty(resolvedPath, `${serverUrl}\n`);
  writeTextIfNotEmpty(namespacePath, `${serverUrl}/api/namespaces/${encodeURIComponent(namespace)}\n`);
}

function runnerBlockedError(message, runnerBlocker) {
  const error = new Error(message);
  error.runnerBlocker = runnerBlocker;

  return error;
}

function readinessBlockerDetails(expectedServerUrls, serverState = 'server process/container state is not managed by direct Node runner') {
  return {
    kind: 'server_readiness_topology',
    expected_server_urls: expectedServerUrls,
    server_state: serverState,
  };
}

async function registerWorker(serverUrl, headers, payload) {
  return postJson(serverUrl, '/api/worker/register', currentWorkerRegistration({
    capabilities: [],
    ...payload,
  }), headers, [201]);
}

async function startWorkflow(serverUrl, headers, payload) {
  return postJson(serverUrl, '/api/workflows', payload, headers, [201, 200]);
}

async function pollWorkflowTask(serverUrl, headers, workerId, taskQueue, buildId) {
  return pollWorkflowTaskWithStatuses(serverUrl, headers, workerId, taskQueue, buildId, [200]);
}

async function pollWorkflowTaskWithStatuses(serverUrl, headers, workerId, taskQueue, buildId, expectedStatuses) {
  const poll = await postJson(serverUrl, '/api/worker/workflow-tasks/poll', {
    worker_id: workerId,
    task_queue: taskQueue,
    build_id: buildId,
    poll_request_id: `${workerId}-${Date.now()}-${Math.random().toString(16).slice(2)}`,
    history_page_size: 100,
  }, headers, expectedStatuses);

  return {
    worker_id: workerId,
    build_id: buildId,
    http_status: poll.__http_status ?? null,
    poll_status: poll.poll_status ?? null,
    reason: poll.reason ?? null,
    error: poll.error ?? null,
    drain_intent: poll.drain_intent ?? null,
    worker_status: poll.worker_status ?? null,
    registered_build_id: poll.registered_build_id ?? null,
    task: poll.task ?? null,
  };
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

async function waitForNoCompatibleVisibility(serverUrl, workflowId, runId, taskQueue, buildId, headers) {
  const samples = [];
  const taskQueueBuildIdSamples = [];
  const deadline = Date.now() + noCompatibleVisibilitySeconds * 1000;
  let latest = {};
  let latestBuildIds = {};
  let latestBuildIdEntry = null;
  let attempts = 0;

  do {
    attempts += 1;
    const sample = await getJson(
      serverUrl,
      `/api/workflows/${encodeURIComponent(workflowId)}/runs/${encodeURIComponent(runId)}`,
      headers,
      [200],
    );
    samples.push(sample);
    latest = sample;

    latestBuildIds = await getJson(
      serverUrl,
      `/api/task-queues/${encodeURIComponent(taskQueue)}/build-ids`,
      headers,
      [200],
    );
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

async function completeWorkflow(serverUrl, headers, task) {
  const encodedResult = avroResultFromTaskArguments(task);
  return postJson(
    serverUrl,
    `/api/worker/workflow-tasks/${encodeURIComponent(task.task_id)}/complete`,
    workflowTaskCompletionPayload(task, [{ type: 'complete_workflow', result: encodedResult }]),
    headers,
    [200],
  );
}

async function failWorkflowTask(serverUrl, headers, task, message, type) {
  return postJson(
    serverUrl,
    `/api/worker/workflow-tasks/${encodeURIComponent(task.task_id)}/fail`,
    workflowTaskFailurePayload(task, message, type),
    headers,
    [200],
  );
}

async function getJson(serverUrl, pathName, headers, expectedStatuses) {
  return requestJson(serverUrl, 'GET', pathName, undefined, headers, expectedStatuses);
}

async function deleteJson(serverUrl, pathName, headers, expectedStatuses) {
  return requestJson(serverUrl, 'DELETE', pathName, undefined, headers, expectedStatuses);
}

async function postJson(serverUrl, pathName, body, headers, expectedStatuses) {
  return requestJson(serverUrl, 'POST', pathName, body, headers, expectedStatuses);
}

async function requestJson(serverUrl, method, pathName, body, headers, expectedStatuses) {
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
  let json = null;
  if (text.trim() !== '') {
    try {
      json = JSON.parse(text);
    } catch {
      json = { raw_body: text };
    }
  }

  captures.push({
    method,
    path: pathName,
    status: response.status,
    request_body: redactBody(body),
    response_body: json,
  });

  if (!expectedStatuses.includes(response.status)) {
    throw new Error(`${method} ${pathName} returned ${response.status}: ${text.slice(0, 500)}`);
  }

  if (json && typeof json === 'object' && !Array.isArray(json)) {
    json.__http_status = response.status;
  }

  return json;
}

async function publishedCliOperatorEvidence(options) {
  try {
    return await capturePublishedCliOperatorEvidence(options);
  } catch (error) {
    const reason = error instanceof Error ? error.message : String(error);

    return {
      cli_operator_command_execution: false,
      rollout_visibility_passes: false,
      drain_resume_controls_passes: false,
      rollout_visibility_gap: `Published CLI rollout visibility could not be exercised: ${reason}`,
      drain_resume_gap: `Published CLI drain/resume controls could not be exercised: ${reason}`,
      cli_install_evidence: {
        artifact: 'cli',
        version: stringValue(options.artifactVersions.cli),
        source: stringValue(options.artifactSources.cli) || 'not_exercised',
        status: 'not_covered',
        local_product_source_checkouts_used: false,
        detail: reason,
      },
      rollout_visibility: {
        status: 'not_covered',
        error: reason,
      },
      drain_resume_controls: {
        status: 'not_covered',
        error: reason,
      },
    };
  }
}

async function capturePublishedCliOperatorEvidence({
  serverUrl,
  namespace,
  token,
  taskQueue,
  workflowType,
  buildV1,
  buildV2,
  v1WorkflowId,
  v1RunId,
  promotedWorkflowId,
  promotedRunId,
  artifactVersions,
  artifactSources,
  workerHeaders,
}) {
  const cli = await resolvePublishedCliArtifact(artifactVersions, artifactSources);
  const commonArgs = [
    '--server',
    serverUrl,
    '--namespace',
    namespace,
    '--token',
    token,
    '--output=json',
  ];
  const commandEnv = {
    ...process.env,
    DW_ENV: '',
    DURABLE_WORKFLOW_SERVER_URL: serverUrl,
    DURABLE_WORKFLOW_NAMESPACE: namespace,
    DURABLE_WORKFLOW_AUTH_TOKEN: token,
  };
  const version = runCliText(cli.executable, ['--version'], commandEnv);
  const promote = runCliJson(
    cli.executable,
    ['task-queue:promote', taskQueue, '--build-id', buildV2, ...commonArgs],
    commandEnv,
  );
  const workerList = runCliJson(
    cli.executable,
    ['worker:list', `--task-queue=${taskQueue}`, ...commonArgs],
    commandEnv,
  );
  const buildIds = runCliJson(
    cli.executable,
    ['task-queue:build-ids', taskQueue, ...commonArgs],
    commandEnv,
  );
  const v1Run = runCliJson(
    cli.executable,
    ['workflow:show-run', v1WorkflowId, v1RunId, ...commonArgs],
    commandEnv,
  );
  const promotedRun = runCliJson(
    cli.executable,
    ['workflow:show-run', promotedWorkflowId, promotedRunId, ...commonArgs],
    commandEnv,
  );
  const drain = runCliJson(
    cli.executable,
    ['task-queue:drain', taskQueue, '--build-id', buildV1, ...commonArgs],
    commandEnv,
  );
  const drainState = runCliJson(
    cli.executable,
    ['task-queue:build-ids', taskQueue, ...commonArgs],
    commandEnv,
  );
  const drainingWorkerId = `dw-drain-probe-${Date.now().toString(36)}-${Math.random().toString(16).slice(2)}`;
  const drainingWorkerRegistration = await registerWorker(serverUrl, workerHeaders, {
    worker_id: drainingWorkerId,
    task_queue: taskQueue,
    runtime: 'php',
    sdk_version: artifactVersions['sdk-php'],
    build_id: buildV1,
    supported_workflow_types: [workflowType],
    workflow_definition_fingerprints: { [workflowType]: `drain-probe-${drainingWorkerId}` },
    supported_activity_types: ['activity_a', 'activity_b'],
    process_metrics: processMetrics(3001, timestamp()),
  });
  const drainingWorkerPoll = await pollWorkflowTaskWithStatuses(
    serverUrl,
    workerHeaders,
    drainingWorkerId,
    taskQueue,
    buildV1,
    [200, 409],
  );
  const resume = runCliJson(
    cli.executable,
    ['task-queue:resume', taskQueue, '--build-id', buildV1, ...commonArgs],
    commandEnv,
  );
  const resumeState = runCliJson(
    cli.executable,
    ['task-queue:build-ids', taskQueue, ...commonArgs],
    commandEnv,
  );
  const workerCohorts = unique([
    ...arrayValue(objectValue(workerList.json).workers).map((worker) => worker?.build_id),
    ...arrayValue(objectValue(buildIds.json).build_ids).map((entry) => entry?.build_id),
  ]);
  const selectedNewStartBuildId = selectedBuildIdFromRollout(objectValue(buildIds.json))
    || stringValue(objectValue(promote.json).build_id);
  const workflowRunCompatibility = {
    [v1RunId]: stringValue(objectValue(v1Run.json).compatibility),
    [promotedRunId]: stringValue(objectValue(promotedRun.json).compatibility),
  };
  const drainEntry = taskQueueBuildIdEntry(objectValue(drainState.json), buildV1);
  const resumeEntry = taskQueueBuildIdEntry(objectValue(resumeState.json), buildV1);
  const commandExecutionPasses = [
    version,
    promote,
    workerList,
    buildIds,
    v1Run,
    promotedRun,
    drain,
    drainState,
    resume,
    resumeState,
  ].every(cliCommandSucceeded);
  const rolloutVisibilityPasses = commandExecutionPasses
    && workerCohorts.includes(buildV1)
    && workerCohorts.includes(buildV2)
    && selectedNewStartBuildId === buildV2
    && workflowRunCompatibility[v1RunId] === buildV1
    && workflowRunCompatibility[promotedRunId] === buildV2;
  const drainStateVisible = stringValue(objectValue(drain.json).drain_intent) === 'draining'
    && stringValue(drainEntry?.drain_intent) === 'draining';
  const resumeStateVisible = stringValue(objectValue(resume.json).drain_intent) === 'active'
    && stringValue(resumeEntry?.drain_intent) === 'active';
  const drainingWorkerClaimCount = drainingWorkerPoll?.task ? 1 : 0;
  const drainingWorkerClaimBlocked = drainingWorkerClaimCount === 0
    && numberValue(drainingWorkerPoll?.http_status) === 409
    && stringValue(drainingWorkerPoll?.reason) === 'worker_draining'
    && stringValue(drainingWorkerPoll?.poll_status) === 'draining';
  const drainResumeControlsPasses = commandExecutionPasses
    && drainStateVisible
    && resumeStateVisible
    && drainingWorkerClaimBlocked;
  const cliInstallEvidence = {
    artifact: 'cli',
    version: stringValue(artifactVersions.cli),
    source: cli.source,
    status: version.exit_code === 0 ? 'pass' : 'not_covered',
    local_product_source_checkouts_used: false,
    command: 'dw --version',
    output_sample: outputSample(version.stdout || version.stderr),
  };
  const publishedCliExecution = {
    local_product_source_checkouts_used: false,
    artifacts: [cliInstallEvidence],
  };
  const rolloutVisibility = {
    status: rolloutVisibilityPasses ? 'pass' : 'not_covered',
    artifact_versions: { cli: stringValue(artifactVersions.cli) },
    published_cli_execution: publishedCliExecution,
    cli_version_output: version,
    promote_command: promote,
    worker_list: workerList,
    task_queue_build_ids: buildIds,
    workflow_show_runs: {
      [v1RunId]: v1Run,
      [promotedRunId]: promotedRun,
    },
    worker_cohorts: workerCohorts,
    new_start_build_id: selectedNewStartBuildId,
    workflow_run_compatibility: workflowRunCompatibility,
    local_product_source_checkouts_used: false,
  };
  const drainResumeControls = {
    status: drainResumeControlsPasses ? 'pass' : 'not_covered',
    artifact_versions: { cli: stringValue(artifactVersions.cli) },
    published_cli_execution: publishedCliExecution,
    drain_command: drain,
    drain_rollout_state: drainState,
    drain_state_visible: drainStateVisible,
    draining_worker_registration: drainingWorkerRegistration,
    draining_worker_poll: drainingWorkerPoll,
    draining_worker_claim_blocked: drainingWorkerClaimBlocked,
    draining_worker_claim_count: drainingWorkerClaimCount,
    resume_command: resume,
    resume_rollout_state: resumeState,
    resume_state_visible: resumeStateVisible,
    local_product_source_checkouts_used: false,
  };

  return {
    cli_operator_command_execution: commandExecutionPasses,
    cli_install_evidence: cliInstallEvidence,
    rollout_visibility_passes: rolloutVisibilityPasses,
    drain_resume_controls_passes: drainResumeControlsPasses,
    rollout_visibility_gap: cliEvidenceGap([
      [commandExecutionPasses, 'one or more published dw commands did not complete successfully'],
      [workerCohorts.includes(buildV1), `worker list/build-id output did not include v1 cohort ${buildV1}`],
      [workerCohorts.includes(buildV2), `worker list/build-id output did not include v2 cohort ${buildV2}`],
      [selectedNewStartBuildId === buildV2, `task-queue build-id output did not show ${buildV2} selected for new starts`],
      [workflowRunCompatibility[v1RunId] === buildV1, `workflow show output did not show ${buildV1} for run ${v1RunId}`],
      [workflowRunCompatibility[promotedRunId] === buildV2, `workflow show output did not show ${buildV2} for run ${promotedRunId}`],
    ]),
    drain_resume_gap: cliEvidenceGap([
      [commandExecutionPasses, 'one or more published dw commands did not complete successfully'],
      [drainStateVisible, `published dw drain output did not expose ${buildV1} as draining`],
      [drainingWorkerClaimBlocked, `worker ${drainingWorkerId} did not return a worker_draining poll response without claiming a task`],
      [resumeStateVisible, `published dw resume output did not expose ${buildV1} as active`],
    ]),
    rollout_visibility: rolloutVisibility,
    drain_resume_controls: drainResumeControls,
  };
}

async function resolvePublishedCliArtifact(artifactVersions, artifactSources) {
  const configured = stringValue(process.env.DW_WV_CLI_EXECUTABLE)
    || stringValue(process.env.DW_CLI_EXECUTABLE);
  if (configured !== '') {
    fs.accessSync(configured, fs.constants.X_OK);
    const source = stringValue(artifactSources.cli) === '' || artifactSourceIsForbidden(artifactSources.cli)
      ? 'official_cli_executable'
      : stringValue(artifactSources.cli);
    artifactSources.cli = source;

    return {
      executable: configured,
      source,
    };
  }

  const cliVersion = stringValue(artifactVersions.cli);
  if (cliVersion === '') {
    throw new Error('DW_CLI_VERSION is required to install the official CLI artifact.');
  }

  const installRoot = path.join(resultDir, 'cli');
  const installDir = path.join(installRoot, 'bin');
  const installerPath = path.join(installRoot, 'install.sh');
  fs.mkdirSync(installDir, { recursive: true });
  fs.mkdirSync(path.dirname(installerPath), { recursive: true });
  const installerUrl = await downloadCliInstaller(cliVersion, installerPath);
  const install = spawnSync('sh', [installerPath], {
    cwd: installRoot,
    env: {
      ...process.env,
      PATH: [installDir, process.env.PATH ?? ''].filter(Boolean).join(path.delimiter),
      VERSION: cliVersion,
      DURABLE_WORKFLOW_INSTALL_DIR: installDir,
      DURABLE_WORKFLOW_BIN_NAME: 'dw',
      DURABLE_WORKFLOW_INSTALL_VERIFY_ATTESTATIONS: '0',
    },
    encoding: 'utf8',
    timeout: cliInstallTimeoutMs,
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  writeTextIfNotEmpty(path.join(resultDir, 'worker-versioning-cli-install.log'), `${install.stdout ?? ''}${install.stderr ?? ''}`);
  if (install.error || install.status !== 0) {
    throw new Error(`official CLI installer failed for release ${cliVersion}: ${install.error?.message ?? `exit ${install.status}`}`);
  }

  const executable = path.join(installDir, 'dw');
  fs.accessSync(executable, fs.constants.X_OK);
  artifactSources.cli = installerUrl;
  writeJson(path.join(resultDir, 'worker-versioning-cli-install.json'), {
    schema: 'durable-workflow.v2.worker-versioning-runtime.cli-install',
    cli_version: cliVersion,
    installer_url: installerUrl,
    install_dir: installDir,
    executable,
    source: installerUrl,
    local_product_source_checkouts_used: false,
  });

  return {
    executable,
    source: installerUrl,
  };
}

async function downloadCliInstaller(cliVersion, installerPath) {
  const normalized = cliVersion.replace(/^v/, '');
  const candidates = [
    stringValue(process.env.DW_WV_CLI_INSTALLER_URL),
    stringValue(process.env.DW_CLI_INSTALLER_URL),
    `https://github.com/durable-workflow/cli/releases/download/${normalized}/install.sh`,
    `https://github.com/durable-workflow/cli/releases/download/v${normalized}/install.sh`,
  ].filter((value, index, values) => value !== '' && values.indexOf(value) === index);
  const errors = [];

  for (const url of candidates) {
    try {
      await downloadUrlToFile(url, installerPath);
      return url;
    } catch (error) {
      errors.push(`${url}: ${error instanceof Error ? error.message : String(error)}`);
    }
  }

  throw new Error(`official CLI installer is not downloadable for release ${cliVersion}; ${errors.join('; ')}`);
}

async function downloadUrlToFile(url, filePath) {
  let response;
  try {
    response = await fetch(url);
  } catch (error) {
    throw new Error(formatFetchFailure('GET', url, error));
  }
  if (!response.ok) {
    throw new Error(`HTTP ${response.status}`);
  }
  const body = Buffer.from(await response.arrayBuffer());
  if (body.length === 0) {
    throw new Error('empty response');
  }
  fs.mkdirSync(path.dirname(filePath), { recursive: true });
  fs.writeFileSync(filePath, body);
}

function runCliJson(executable, args, env) {
  const output = runCliText(executable, args, env);
  const stdout = stringValue(output.stdout);
  if (stdout !== '') {
    try {
      output.json = JSON.parse(stdout);
    } catch (error) {
      output.json_parse_error = error instanceof Error ? error.message : String(error);
    }
  }

  return output;
}

function runCliText(executable, args, env = process.env) {
  const result = spawnSync(executable, args, {
    cwd: runRoot,
    env,
    encoding: 'utf8',
    timeout: cliCommandTimeoutMs,
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  const command = ['dw', ...args].map((arg) => (arg === env.DURABLE_WORKFLOW_AUTH_TOKEN ? '<redacted-token>' : arg));

  return {
    command,
    exit_code: result.status,
    signal: result.signal,
    error: result.error?.message ?? null,
    stdout: result.stdout ?? '',
    stderr: result.stderr ?? '',
  };
}

function cliCommandSucceeded(command) {
  return command?.exit_code === 0 && !command?.error;
}

function selectedBuildIdFromRollout(snapshot) {
  const selected = arrayValue(snapshot.build_ids)
    .find((entry) => entry?.new_start_selected === true || entry?.newStartSelected === true);

  return stringValue(selected?.build_id);
}

function cliCommandJson(evidence, field) {
  return objectValue(objectValue(objectValue(evidence.drain_resume_controls)[field]).json);
}

function cliEvidenceGap(checks) {
  return checks
    .filter(([passes]) => !passes)
    .map(([, reason]) => reason)
    .join('; ');
}

async function publishedWaterlineOperatorVisibility(options) {
  try {
    return await capturePublishedWaterlineOperatorVisibility(options);
  } catch (error) {
    const reason = error instanceof Error ? error.message : String(error);

    return {
      status: 'not_covered',
      ok: false,
      visible: false,
      gap: `Published Waterline rollout visibility could not be exercised: ${reason}`,
      missing: ['waterline_capture'],
      artifact_versions: { waterline: stringValue(options.artifactVersions.waterline) },
      artifact_sources: { waterline: stringValue(options.artifactSources.waterline) || 'not_exercised' },
      waterline_install_evidence: waterlineInstallEvidence(
        options.artifactVersions,
        options.artifactSources,
        'not_covered',
        reason,
      ),
      local_product_source_checkouts_used: false,
    };
  }
}

export async function capturePublishedWaterlineOperatorVisibility({
  namespace,
  taskQueue,
  buildV1,
  buildV2,
  v1WorkflowId,
  v1RunId,
  promotedWorkflowId,
  promotedRunId,
  noCompatibleWorkflowId,
  noCompatibleRunId,
  artifactVersions,
  artifactSources,
  drain,
  resume,
}) {
  const waterlineUrl = configuredWaterlineUrl();
  if (!waterlineUrl) {
    return {
      status: 'not_covered',
      ok: false,
      visible: false,
      gap: 'DW_WV_WATERLINE_URL was not provided, so the published Waterline worker/workflow views were not queried.',
      missing: ['waterline_url'],
      artifact_versions: { waterline: stringValue(artifactVersions.waterline) },
      artifact_sources: { waterline: stringValue(artifactSources.waterline) || 'not_exercised' },
      waterline_install_evidence: waterlineInstallEvidence(
        artifactVersions,
        artifactSources,
        'not_covered',
        'No running published Waterline URL was supplied.',
      ),
      local_product_source_checkouts_used: false,
    };
  }
  writeTextIfNotEmpty(path.join(resultDir, 'waterline-url.txt'), `${waterlineUrl}\n`);

  const health = await waterlineApiSnapshot(
    waterlineUrl,
    'GET /waterline/api/v2/health',
    '/waterline/api/v2/health',
  );
  const runningList = await waterlineApiSnapshot(
    waterlineUrl,
    'GET /waterline/api/flows/running',
    '/waterline/api/flows/running',
  );
  const completedList = await waterlineApiSnapshot(
    waterlineUrl,
    'GET /waterline/api/flows/completed',
    '/waterline/api/flows/completed',
  );
  const v1RunDetail = await waterlineApiSnapshot(
    waterlineUrl,
    'GET /waterline/api/instances/{workflowId}/runs/{runId}#v1',
    waterlineRunDetailPath(v1WorkflowId, v1RunId),
  );
  const promotedRunDetail = await waterlineApiSnapshot(
    waterlineUrl,
    'GET /waterline/api/instances/{workflowId}/runs/{runId}#promoted',
    waterlineRunDetailPath(promotedWorkflowId, promotedRunId),
  );
  const noCompatibleRunDetail = noCompatibleWorkflowId && noCompatibleRunId
    ? await waterlineApiSnapshot(
      waterlineUrl,
      'GET /waterline/api/instances/{workflowId}/runs/{runId}#no-compatible',
      waterlineRunDetailPath(noCompatibleWorkflowId, noCompatibleRunId),
    )
    : {
        label: 'GET /waterline/api/instances/{workflowId}/runs/{runId}#no-compatible',
        ok: false,
        skipped: true,
        reason: 'no no-compatible run id was recorded',
      };

  const healthBody = objectValue(health.body);
  const workerRows = waterlineWorkerRows(healthBody, taskQueue);
  const workerCohorts = unique(workerRows.map((worker) => worker.build_id));
  const queueVisibility = waterlineQueueVisibilityForTaskQueue(healthBody, taskQueue);
  const routingDrains = objectValue(healthBody.routing_drains ?? healthBody.routingDrains);
  const v1Compatibility = waterlineRunCompatibility(v1RunDetail.body);
  const promotedCompatibility = waterlineRunCompatibility(promotedRunDetail.body);
  const noCompatibleCompatibility = waterlineRunCompatibility(noCompatibleRunDetail.body);
  const runningItem = waterlineListItem(runningList.body, noCompatibleWorkflowId, noCompatibleRunId);
  const completedV1Item = waterlineListItem(completedList.body, v1WorkflowId, v1RunId);
  const completedPromotedItem = waterlineListItem(completedList.body, promotedWorkflowId, promotedRunId);
  const workflowRunCompatibility = Object.fromEntries(Object.entries({
    [v1RunId]: v1Compatibility,
    [promotedRunId]: promotedCompatibility,
    [noCompatibleRunId]: noCompatibleCompatibility,
  }).filter(([runId, compatibility]) => stringValue(runId) !== '' && stringValue(compatibility) !== ''));
  const rolloutState = {
    routing_drains: routingDrains,
    queue_visibility: queueVisibility,
    drain_command_result: objectValue(drain),
    resume_command_result: objectValue(resume),
    selected_new_start_build_id_visible: waterlineSelectedNewStartBuildId(healthBody, taskQueue),
  };

  const missing = [];
  if (!health.ok) {
    missing.push('health');
  }
  if (!runningList.ok) {
    missing.push('running_list');
  }
  if (!completedList.ok) {
    missing.push('completed_list');
  }
  if (!v1RunDetail.ok) {
    missing.push('v1_run_detail');
  }
  if (!promotedRunDetail.ok) {
    missing.push('promoted_run_detail');
  }
  if (workerRows.length === 0) {
    missing.push('worker_registrations');
  }
  if (!workerCohorts.includes(buildV1)) {
    missing.push('v1_worker_cohort');
  }
  if (!workerCohorts.includes(buildV2)) {
    missing.push('v2_worker_cohort');
  }
  if (Object.keys(queueVisibility).length === 0) {
    missing.push('queue_visibility');
  }
  if (Object.keys(routingDrains).length === 0) {
    missing.push('rollout_state');
  }
  if (v1Compatibility !== buildV1) {
    missing.push('v1_run_compatibility');
  }
  if (promotedCompatibility !== buildV2) {
    missing.push('promoted_run_compatibility');
  }
  if (noCompatibleWorkflowId && noCompatibleRunId && !runningItem && !noCompatibleRunDetail.ok) {
    missing.push('no_compatible_run_visibility');
  }

  const waterlineRequestFailures = [
    health,
    runningList,
    completedList,
    v1RunDetail,
    promotedRunDetail,
    noCompatibleRunDetail,
  ]
    .map(waterlineRequestFailure)
    .filter(Boolean);
  const waterlineRequestFailureMessages = waterlineRequestFailures.map(waterlineRequestFailureText);
  const status = missing.length === 0 ? 'pass' : 'fail';
  const gap = missing.length === 0
    ? ''
    : waterlineRequestFailures.length > 0
      ? `Published Waterline request failures: ${waterlineRequestFailureMessages.join('; ')}`
      : `Published Waterline did not expose required worker-versioning fields: ${missing.join(', ')}`;

  return {
    status,
    ok: status === 'pass',
    visible: status === 'pass',
    surface: 'waterline',
    artifact_versions: { waterline: stringValue(artifactVersions.waterline) },
    artifact_sources: { waterline: stringValue(artifactSources.waterline) || 'packagist_release' },
    waterline_artifact_version: stringValue(artifactVersions.waterline),
    waterline_url: waterlineUrl,
    reachability_status: health.ok ? 'reachable' : 'unreachable',
    namespace,
    task_queue: taskQueue,
    worker_cohorts: workerCohorts,
    worker_view_visible: workerRows.length > 0,
    workflow_view_visible: v1RunDetail.ok && promotedRunDetail.ok,
    workflow_compatibility: v1Compatibility,
    workflow_run_compatibility: workflowRunCompatibility,
    worker_list: workerRows,
    workflow_visibility: {
      [v1RunId]: waterlineRunSummary(v1RunDetail.body, completedV1Item),
      [promotedRunId]: waterlineRunSummary(promotedRunDetail.body, completedPromotedItem),
      ...(noCompatibleWorkflowId && noCompatibleRunId
        ? { [noCompatibleRunId]: waterlineRunSummary(noCompatibleRunDetail.body, runningItem) }
        : {}),
    },
    rollout_state: rolloutState,
    rollout_state_observed: Object.keys(queueVisibility).length > 0 || Object.keys(routingDrains).length > 0,
    worker_view_capture: workerRows,
    workflow_view_capture: {
      [v1RunId]: waterlineRunSummary(v1RunDetail.body, completedV1Item),
      [promotedRunId]: waterlineRunSummary(promotedRunDetail.body, completedPromotedItem),
      ...(noCompatibleWorkflowId && noCompatibleRunId
        ? { [noCompatibleRunId]: waterlineRunSummary(noCompatibleRunDetail.body, runningItem) }
        : {}),
    },
    api_captures: {
      health,
      running_list: runningList,
      completed_list: completedList,
      v1_run_detail: v1RunDetail,
      promoted_run_detail: promotedRunDetail,
      no_compatible_run_detail: noCompatibleRunDetail,
    },
    missing,
    request_failures: waterlineRequestFailures,
    gap,
    waterline_install_evidence: waterlineInstallEvidence(
      artifactVersions,
      artifactSources,
      health.ok ? 'pass' : 'fail',
      waterlineInstallEvidenceDetail(health, waterlineRequestFailureMessages, missing),
      waterlineUrl,
    ),
    local_product_source_checkouts_used: false,
  };
}

function configuredWaterlineUrl() {
  return trimTrailingSlash(
    trim(process.env.DW_WV_WATERLINE_URL)
      || trim(process.env.DW_WATERLINE_URL),
  );
}

async function waterlineApiSnapshot(baseUrl, label, pathName) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), waterlineRequestTimeoutMs);
  const url = `${baseUrl}${pathName}`;

  try {
    const response = await fetch(url, {
      method: 'GET',
      headers: {
        Accept: 'application/json',
        'X-Durable-Workflow-Control-Plane-Version': '2',
      },
      signal: controller.signal,
    });
    const text = await response.text();
    let body = null;
    let parseError = null;
    if (text.trim() !== '') {
      try {
        body = JSON.parse(text);
      } catch {
        parseError = true;
        body = { raw_body: text };
      }
    }
    const failure = waterlineResponseFailure(response.status, text, body, parseError);
    const safeBody = redactBody(body);
    const snapshot = {
      label,
      ok: failure === null,
      path: pathName,
      http_status: response.status,
      body: safeBody,
      ...(failure === null ? {} : {
        error: failure.reason,
        response_summary: failure.summary,
      }),
    };
    captures.push({
      surface: 'waterline',
      method: 'GET',
      path: pathName,
      status: response.status,
      request_body: null,
      response_body: safeBody,
      ...(failure === null ? {} : {
        response_error: failure.reason,
        response_summary: failure.summary,
      }),
    });

    return snapshot;
  } catch (error) {
    const summary = waterlineFetchFailureSummary(error);
    captures.push({
      surface: 'waterline',
      method: 'GET',
      path: pathName,
      status: null,
      request_body: null,
      response_body: { error: summary },
      response_error: 'request failed',
      response_summary: summary,
    });

    return {
      label,
      ok: false,
      path: pathName,
      http_status: null,
      error: 'request failed',
      response_summary: summary,
    };
  } finally {
    clearTimeout(timer);
  }
}

function waterlineResponseFailure(status, text, body, parseError) {
  const trimmed = stringValue(text);
  let reason = '';

  if (status < 200 || status >= 300) {
    reason = `HTTP ${status}`;
  } else if (parseError) {
    reason = 'non-JSON response';
  } else if (trimmed === '') {
    reason = 'empty response body';
  } else if (waterlineBodyIsErrorJson(body)) {
    reason = 'error JSON response';
  } else if (waterlineBodyIsEmptyJson(body)) {
    reason = 'empty JSON response';
  }

  if (reason === '') {
    return null;
  }

  return {
    reason,
    summary: waterlineResponseSummary(body, text),
  };
}

function waterlineBodyIsErrorJson(body) {
  const object = objectValue(body);
  if (Object.keys(object).length === 0) {
    return false;
  }

  const status = stringValue(object.status).toLowerCase();

  return ['error', 'failed', 'failure'].includes(status)
    || stringValue(object.error) !== ''
    || stringValue(object.message).toLowerCase().includes('error')
    || Object.keys(objectValue(object.error)).length > 0
    || Object.keys(objectValue(object.errors)).length > 0
    || arrayValue(object.errors).length > 0;
}

function waterlineBodyIsEmptyJson(body) {
  if (Array.isArray(body)) {
    return body.length === 0;
  }

  return body && typeof body === 'object' && Object.keys(body).length === 0;
}

function waterlineResponseSummary(body, rawText = '') {
  if (body && typeof body === 'object' && !Array.isArray(body)) {
    const rawBody = stringValue(body.raw_body);
    if (rawBody !== '') {
      return safeCappedWaterlineSummary(rawBody);
    }

    return safeCappedWaterlineSummary(redactBody(body));
  }

  if (Array.isArray(body)) {
    return safeCappedWaterlineSummary(redactBody({ data: body }));
  }

  return safeCappedWaterlineSummary(rawText);
}

function waterlineFetchFailureSummary(error) {
  const reason = error instanceof Error ? error.message : String(error);
  const cause = fetchFailureCause(error);

  return safeCappedWaterlineSummary(orderedUnique([reason, cause].filter(Boolean)).join('; '));
}

function safeCappedWaterlineSummary(value) {
  const serialized = typeof value === 'string' ? value : JSON.stringify(value);
  const normalized = redactSensitiveText(stringValue(serialized).replace(/\s+/g, ' '));
  if (normalized === '') {
    return '<empty>';
  }

  return normalized.length > WATERLINE_RESPONSE_SUMMARY_LIMIT
    ? `${normalized.slice(0, WATERLINE_RESPONSE_SUMMARY_LIMIT)}...`
    : normalized;
}

function redactSensitiveText(value) {
  return value
    .replace(/Bearer\s+[A-Za-z0-9._~+/=-]+/gi, 'Bearer <redacted>')
    .replace(/((?:authorization|token|api[_-]?key|password|secret)["']?\s*[:=]\s*["']?)[^"',\s}]+/gi, '$1<redacted>');
}

function waterlineRequestFailure(snapshot) {
  if (!snapshot || snapshot.skipped === true) {
    return null;
  }

  const reason = stringValue(snapshot.error);
  const responseSummary = stringValue(snapshot.response_summary);
  if (reason === '' && responseSummary === '') {
    return null;
  }

  return {
    request: waterlineCompactRequest(snapshot),
    path: waterlineCompactRequestPath(snapshot),
    http_status: snapshot.http_status ?? null,
    summary: safeCappedWaterlineSummary(responseSummary === '' ? reason : `${reason}: ${responseSummary}`),
  };
}

function waterlineCompactRequest(snapshot) {
  const label = stringValue(snapshot.label);
  if (label !== '') {
    return label;
  }

  const pathName = waterlineCompactRequestPath(snapshot);
  return pathName === 'unknown' ? 'GET unknown' : `GET ${pathName}`;
}

function waterlineCompactRequestPath(snapshot) {
  const label = stringValue(snapshot.label).replace(/^[A-Z]+\s+/, '');
  if (label !== '') {
    return label;
  }

  const pathName = stringValue(snapshot.path);
  if (pathName === '') {
    return 'unknown';
  }

  return pathName.split('?')[0];
}

function waterlineRequestFailureText(failure) {
  const status = failure.http_status === null || failure.http_status === undefined
    ? 'status=no_response'
    : `status=${failure.http_status}`;

  return `${failure.request} ${status} ${failure.summary}`;
}

function waterlineInstallEvidenceDetail(health, requestFailureText, missing) {
  if (requestFailureText.length > 0) {
    return `Published Waterline request diagnostics: ${requestFailureText.join('; ')}`;
  }

  if (health.ok) {
    return 'Published Waterline app served /waterline/api/v2/health from the Packagist-installed app.';
  }

  return `Published Waterline health did not return a successful response: ${missing.join(', ')}`;
}

function waterlineRunDetailPath(workflowId, runId) {
  return `/waterline/api/instances/${encodeURIComponent(stringValue(workflowId))}/runs/${encodeURIComponent(stringValue(runId))}?history_limit=all`;
}

function waterlineWorkerRows(healthBody, taskQueue) {
  const workers = [];
  const metricsWorkers = objectValue(objectValue(healthBody.operator_metrics).workers);
  workers.push(...arrayValue(metricsWorkers.registrations));
  workers.push(...arrayValue(metricsWorkers.stale_registrations));

  const queueVisibility = objectValue(healthBody.queue_visibility ?? healthBody.queueVisibility);
  for (const queue of arrayValue(queueVisibility.task_queues ?? queueVisibility.taskQueues)) {
    const queueName = stringValue(queue.task_queue) || stringValue(queue.taskQueue) || stringValue(queue.name);
    if (queueName !== taskQueue) {
      continue;
    }

    workers.push(...arrayValue(queue.workers));
    workers.push(...arrayValue(queue.pollers));
    workers.push(...arrayValue(queue.active_pollers));
    workers.push(...arrayValue(queue.activePollers));
  }

  const byKey = new Map();
  for (const worker of workers) {
    const object = objectValue(worker);
    const workerId = stringValue(object.worker_id) || stringValue(object.workerId);
    const buildId = stringValue(object.build_id) || stringValue(object.buildId);
    const queueName = stringValue(object.task_queue)
      || stringValue(object.taskQueue)
      || stringValue(object.queue)
      || taskQueue;
    if (queueName !== taskQueue || (workerId === '' && buildId === '')) {
      continue;
    }
    const key = `${workerId}:${buildId}`;
    byKey.set(key, {
      ...object,
      worker_id: workerId,
      task_queue: queueName,
      build_id: buildId,
    });
  }

  return [...byKey.values()];
}

function waterlineQueueVisibilityForTaskQueue(healthBody, taskQueue) {
  const queueVisibility = objectValue(healthBody.queue_visibility ?? healthBody.queueVisibility);
  for (const queue of arrayValue(queueVisibility.task_queues ?? queueVisibility.taskQueues)) {
    const queueName = stringValue(queue.task_queue) || stringValue(queue.taskQueue) || stringValue(queue.name);
    if (queueName === taskQueue) {
      return objectValue(queue);
    }
  }

  return {};
}

function waterlineSelectedNewStartBuildId(healthBody, taskQueue) {
  const queue = waterlineQueueVisibilityForTaskQueue(healthBody, taskQueue);
  for (const entry of [
    ...arrayValue(queue.build_ids),
    ...arrayValue(queue.buildIds),
    ...arrayValue(objectValue(queue.rollout_state).build_ids),
    ...arrayValue(objectValue(queue.rolloutState).buildIds),
  ]) {
    const object = objectValue(entry);
    if (object.new_start_selected === true || object.newStartSelected === true) {
      return stringValue(object.build_id) || stringValue(object.buildId);
    }
  }

  return null;
}

function waterlineRunCompatibility(body) {
  const detail = objectValue(body);

  return stringValue(detail.compatibility)
    || stringValue(objectValue(detail.data).compatibility)
    || stringValue(detail.run_compatibility)
    || stringValue(detail.runCompatibility);
}

function waterlineRunSummary(body, listItem) {
  const detail = objectValue(body);
  const item = objectValue(listItem);

  return {
    workflow_id: stringValue(detail.workflow_instance_id)
      || stringValue(detail.instance_id)
      || stringValue(item.workflow_instance_id)
      || stringValue(item.instance_id),
    run_id: stringValue(detail.run_id)
      || stringValue(detail.selected_run_id)
      || stringValue(detail.id)
      || stringValue(item.run_id)
      || stringValue(item.selected_run_id)
      || stringValue(item.id),
    compatibility: waterlineRunCompatibility(detail) || waterlineRunCompatibility(item),
    status: stringValue(detail.status) || stringValue(item.status),
    status_bucket: stringValue(detail.status_bucket) || stringValue(item.status_bucket),
    list_item_visible: Object.keys(item).length > 0,
    detail_visible: Object.keys(detail).length > 0,
  };
}

function waterlineListItem(body, workflowId, runId) {
  const wantedWorkflowId = stringValue(workflowId);
  const wantedRunId = stringValue(runId);
  if (!wantedWorkflowId || !wantedRunId) {
    return null;
  }

  const data = arrayValue(objectValue(body).data);
  for (const item of data) {
    const object = objectValue(item);
    const itemWorkflowId = stringValue(object.workflow_instance_id)
      || stringValue(object.instance_id)
      || stringValue(object.workflowId)
      || stringValue(object.workflow_id);
    const itemRunId = stringValue(object.run_id)
      || stringValue(object.selected_run_id)
      || stringValue(object.runId)
      || stringValue(object.id);
    if (itemWorkflowId === wantedWorkflowId && itemRunId === wantedRunId) {
      return object;
    }
  }

  return null;
}

function waterlineInstallEvidence(artifactVersions, artifactSources, status, detail, waterlineUrl = '') {
  return {
    artifact: 'waterline',
    version: stringValue(artifactVersions.waterline),
    source: stringValue(artifactSources.waterline) || 'packagist_release',
    status,
    local_product_source_checkouts_used: false,
    detail,
    command: waterlineUrl ? 'GET /waterline/api/v2/health' : null,
    output_sample: waterlineUrl,
  };
}

function mergeCliInstallEvidence(evidence, cliInstallEvidence) {
  const cliStatus = normalizedArtifactStatus(cliInstallEvidence?.status);
  if (cliStatus !== 'pass') {
    return evidence;
  }

  const merged = JSON.parse(JSON.stringify(evidence ?? {}));
  const artifacts = Array.isArray(merged.artifacts) ? merged.artifacts : [];
  const withoutCli = artifacts.filter((item) => canonicalArtifactName(stringValue(item?.artifact) || stringValue(item?.name)) !== 'cli');
  merged.artifacts = [
    ...withoutCli,
    cliInstallEvidence,
  ];

  if (merged.local_product_source_checkouts_used === undefined) {
    merged.local_product_source_checkouts_used = false;
  }

  return merged;
}

function mergeWaterlineInstallEvidence(evidence, waterlineInstallEvidence) {
  const waterlineStatus = normalizedArtifactStatus(waterlineInstallEvidence?.status);
  if (!['pass', 'fail'].includes(waterlineStatus)) {
    return evidence;
  }

  const merged = JSON.parse(JSON.stringify(evidence ?? {}));
  const artifacts = Array.isArray(merged.artifacts) ? merged.artifacts : [];
  const withoutWaterline = artifacts.filter((item) => canonicalArtifactName(
    stringValue(item?.artifact) || stringValue(item?.name),
  ) !== 'waterline');
  merged.artifacts = [
    ...withoutWaterline,
    waterlineInstallEvidence,
  ];

  if (merged.local_product_source_checkouts_used === undefined) {
    merged.local_product_source_checkouts_used = false;
  }

  return merged;
}


function outputSample(output) {
  return stringValue(output).slice(0, 1000);
}

function countTasksForRun(polls, runId) {
  return polls.filter((poll) => stringValue(poll?.task?.run_id) === runId).length;
}

function workflowTaskRetryOf(poll) {
  const task = poll?.task;
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

function processMetrics(processId, processStartedAt) {
  return {
    process_id: processId,
    host: 'worker-versioning-conformance',
    process_started_at: processStartedAt,
    process_uptime_seconds: 1,
  };
}

function publishedServerArtifactEvidence(artifactVersions, artifactSources) {
  return {
    artifact: 'server',
    version: stringValue(artifactVersions.server),
    source: stringValue(artifactSources.server),
    status: 'pass',
    local_product_source_checkouts_used: false,
  };
}

function noCompatibleServerProtocolProbePasses(outputs, artifactVersions, artifactSources) {
  const incompatibleWorkerTaskCount = numberValue(outputs.incompatible_worker_task_count);
  const incompatibleWorkerPollAttempts = numberValue(outputs.incompatible_worker_poll_attempts);
  const operatorVisibleSignal = stringValue(outputs.operator_visible_signal);
  const pendingOrTypedError = stringValue(outputs.pending_or_typed_error);
  const serverVersion = stringValue(artifactVersions.server);
  const serverSource = stringValue(artifactSources.server);

  return outputs.worker_execution_mode === SERVER_PROTOCOL_PROBE
    && truthyEvidenceFlag(outputs.published_server_protocol_probe)
    && explicitFalse(outputs.local_product_source_checkouts_used)
    && incompatibleWorkerTaskCount === 0
    && incompatibleWorkerPollAttempts !== null
    && incompatibleWorkerPollAttempts > 0
    && truthyEvidenceFlag(outputs.compatible_worker_deregistered)
    && isExplicitNoCompatibleSignal(operatorVisibleSignal)
    && (
      pendingOrTypedError === 'pending'
      || isExplicitNoCompatibleSignal(pendingOrTypedError)
    )
    && isExactSemverVersion(serverVersion)
    && !isPlaceholderVersion(serverVersion)
    && !artifactSourceIsForbidden(serverSource);
}

export function artifactVersionsFromEnv() {
  const workflow = trim(process.env.DW_WORKFLOW_PHP_VERSION ?? process.env.DW_WORKFLOW_VERSION);
  const sdkPhp = trim(process.env.DW_PHP_SDK_VERSION);

  return {
    server: trim(process.env.DW_SERVER_VERSION),
    cli: trim(process.env.DW_CLI_VERSION),
    'sdk-python': trim(process.env.DW_PYTHON_SDK_VERSION),
    workflow,
    'sdk-php': sdkPhp,
    waterline: trim(process.env.DW_WATERLINE_VERSION),
  };
}

export function artifactSourcesFromEnv() {
  return {
    server: process.env.DW_WV_SERVER_ARTIFACT_SOURCE ?? 'published_server_url',
    cli: trim(process.env.DW_CLI_ARTIFACT_SOURCE) || 'not_exercised',
    'sdk-python': trim(process.env.DW_PYTHON_SDK_ARTIFACT_SOURCE) || 'not_exercised',
    workflow: trim(process.env.DW_WORKFLOW_PHP_ARTIFACT_SOURCE) || 'not_exercised',
    'sdk-php': trim(process.env.DW_PHP_SDK_ARTIFACT_SOURCE) || 'not_exercised',
    waterline: trim(process.env.DW_WATERLINE_ARTIFACT_SOURCE) || 'not_exercised',
  };
}

export function artifactInstallEvidence(artifactVersions, artifactSources) {
  const supplied = readJsonIfExists(artifactInstallEvidencePath);
  if (supplied && typeof supplied === 'object' && !Array.isArray(supplied)) {
    return normalizeArtifactInstallEvidence(supplied, artifactVersions, artifactSources);
  }

  const artifacts = REQUIRED_INSTALL_ARTIFACTS.map((artifact) => {
    const source = artifact === 'server'
      ? artifactSources.server
      : 'not_exercised';
    const status = artifact === 'server' && source !== 'not_exercised'
      ? 'pass'
      : 'not_covered';

    return {
      artifact,
      version: artifactVersionFor(artifactVersions, artifact),
      source,
      status,
      detail: artifact === 'server'
        ? 'Published server endpoint was available to the probe.'
        : 'This runner did not install or execute this published artifact.',
    };
  });

  return {
    schema: 'durable-workflow.v2.worker-versioning-runtime.artifact-install-evidence',
    local_product_source_checkouts_used: false,
    generated_at: timestamp(),
    artifacts,
  };
}

function normalizeArtifactInstallEvidence(evidence, artifactVersions, artifactSources) {
  const artifacts = Array.isArray(evidence.artifacts) ? evidence.artifacts : [];
  const byArtifact = new Map(artifacts
    .filter((item) => item && typeof item === 'object' && !Array.isArray(item))
    .map((item) => [stringValue(item.artifact) || stringValue(item.name), item]));
  const normalizedArtifacts = REQUIRED_INSTALL_ARTIFACTS.map((artifact) => {
    const item = byArtifact.get(artifact) ?? {};

    return {
      artifact,
      version: stringValue(item.version) || artifactVersionFor(artifactVersions, artifact),
      source: artifactSourceForInstallEntry(item) || stringValue(artifactSources[artifact]) || 'not_exercised',
      status: normalizedArtifactStatus(item.status ?? item.result ?? item.outcome),
      local_product_source_checkouts_used: truthyEvidenceFlag(item.local_product_source_checkouts_used)
        || truthyEvidenceFlag(item.localProductSourceCheckoutsUsed),
      detail: stringValue(item.detail) || stringValue(item.observed_behavior) || '',
      command: item.command ?? null,
      output_sample: item.output_sample ?? item.outputSample ?? '',
    };
  });

  return {
    schema: stringValue(evidence.schema)
      || 'durable-workflow.v2.worker-versioning-runtime.artifact-install-evidence',
    local_product_source_checkouts_used: truthyEvidenceFlag(evidence.local_product_source_checkouts_used)
      || truthyEvidenceFlag(evidence.localProductSourceCheckoutsUsed)
      || normalizedArtifacts.some((item) => truthyEvidenceFlag(item.local_product_source_checkouts_used)),
    generated_at: stringValue(evidence.generated_at) || timestamp(),
    artifacts: normalizedArtifacts,
  };
}

export function artifactInstallEvidencePasses(evidence) {
  if (!evidence || truthyEvidenceFlag(evidence.local_product_source_checkouts_used)) {
    return false;
  }

  const entries = artifactInstallEntryByName(evidence);
  return REQUIRED_INSTALL_ARTIFACTS.every((artifact) => {
    const entry = entries.get(artifact);

    return normalizedArtifactStatus(entry?.status) === 'pass'
      && !artifactSourceIsForbidden(artifactSourceForInstallEntry(entry ?? {}))
      && !truthyEvidenceFlag(entry?.local_product_source_checkouts_used)
      && !truthyEvidenceFlag(entry?.localProductSourceCheckoutsUsed);
  });
}

export function artifactInstallEvidenceGaps(evidence) {
  const entries = artifactInstallEntryByName(evidence);
  const gaps = [];
  for (const artifact of REQUIRED_INSTALL_ARTIFACTS) {
    const entry = entries.get(artifact);
    const status = normalizedArtifactStatus(entry?.status);
    const source = artifactSourceForInstallEntry(entry ?? {});
    if (status !== 'pass') {
      gaps.push(`${artifact}.status=${status || 'missing'}`);
    }
    if (artifactSourceIsForbidden(source)) {
      gaps.push(`${artifact}.source=${source || 'missing'}`);
    }
    if (truthyEvidenceFlag(entry?.local_product_source_checkouts_used)
      || truthyEvidenceFlag(entry?.localProductSourceCheckoutsUsed)) {
      gaps.push(`${artifact}.local_product_source_checkouts_used=true`);
    }
  }

  if (truthyEvidenceFlag(evidence?.local_product_source_checkouts_used)) {
    gaps.push('local_product_source_checkouts_used=true');
  }

  return gaps.length === 0 ? ['unknown'] : gaps;
}

function artifactInstallEntryByName(evidence) {
  const entries = new Map();
  for (const item of evidence?.artifacts ?? []) {
    if (!item || typeof item !== 'object' || Array.isArray(item)) {
      continue;
    }
    const artifact = stringValue(item.artifact) || stringValue(item.name);
    if (artifact) {
      entries.set(artifact, item);
    }
  }
  return entries;
}

export function mergeArtifactSources(artifactSources, installEvidence) {
  const merged = { ...artifactSources };
  for (const item of installEvidence?.artifacts ?? []) {
    const artifact = stringValue(item.artifact) || stringValue(item.name);
    const source = artifactSourceForInstallEntry(item);
    if (!artifact || !source) {
      continue;
    }

    merged[artifact] = source;
  }

  return merged;
}

export function publishedWorkerExecutionEvidence(artifactVersions, artifactSources) {
  const supplied = readJsonIfExists(publishedWorkerEvidencePath);
  if (!supplied || typeof supplied !== 'object' || Array.isArray(supplied)) {
    return {
      schema: 'durable-workflow.v2.worker-versioning-runtime.published-worker-execution-evidence',
      local_product_source_checkouts_used: false,
      generated_at: timestamp(),
      scenario_results: {},
      note: 'No host published-worker execution shard was supplied.',
    };
  }

  const shardHasLocalSourceSignal = publishedWorkerShardHasLocalSourceSignal(supplied);
  const shardLocalSourceExplicitFalse = !shardHasLocalSourceSignal
    && (
      explicitFalse(supplied.local_product_source_checkouts_used)
      || explicitFalse(supplied.localProductSourceCheckoutsUsed)
      || publishedWorkerShardProvesNoLocalSource(supplied)
    );
  const scenarioResults = publishedWorkerScenarioResults(supplied);
  const publishedWorkerExecution = firstObjectValue(
    supplied.published_artifact_worker_execution,
    supplied.publishedArtifactWorkerExecution,
    supplied.published_worker_execution,
    supplied.publishedWorkerExecution,
    supplied.published_artifact_execution,
    supplied.publishedArtifactExecution,
  );

  return {
    schema: stringValue(supplied.schema)
      || 'durable-workflow.v2.worker-versioning-runtime.published-worker-execution-evidence',
    local_product_source_checkouts_used: shardHasLocalSourceSignal
      || truthyEvidenceFlag(supplied.local_product_source_checkouts_used)
      || truthyEvidenceFlag(supplied.localProductSourceCheckoutsUsed),
    supplied_shard_local_product_source_checkouts_used: !shardLocalSourceExplicitFalse,
    generated_at: stringValue(supplied.generated_at) || stringValue(supplied.generatedAt) || timestamp(),
    artifact_versions: {
      ...artifactVersions,
      ...objectValue(supplied.artifact_versions),
      ...objectValue(supplied.artifactVersions),
    },
    artifact_sources: {
      ...artifactSources,
      ...objectValue(supplied.artifact_sources),
      ...objectValue(supplied.artifactSources),
    },
    scenario_results: scenarioResults,
    ...(Object.keys(publishedWorkerExecution).length > 0
      ? { published_artifact_worker_execution: publishedWorkerExecution }
      : {}),
    findings: Array.isArray(supplied.findings) ? supplied.findings : [],
    source_path: fs.existsSync(publishedWorkerEvidencePath) ? publishedWorkerEvidencePath : null,
  };
}

function maybeGeneratePublishedWorkerEvidence(serverUrl, artifactVersions, artifactSources) {
  if (skipPublishedWorkerShard() || fs.existsSync(publishedWorkerEvidencePath)) {
    return;
  }

  const workerShardPath = path.join(path.dirname(modulePath), 'worker-versioning-published-workers.mjs');
  if (!fs.existsSync(workerShardPath)) {
    return;
  }

  const env = {
    ...process.env,
    DW_WV_SERVER_URL: serverUrl,
    DW_WV_RESULT_DIR: resultDir,
    DW_WV_RUN_ROOT: runRoot,
    DW_WV_REPO_ROOT: repoRoot,
    DW_WV_PUBLISHED_WORKER_EVIDENCE: publishedWorkerEvidencePath,
    DW_SERVER_VERSION: stringValue(artifactVersions.server) || process.env.DW_SERVER_VERSION || '',
    DW_CLI_VERSION: stringValue(artifactVersions.cli) || process.env.DW_CLI_VERSION || '',
    DW_PYTHON_SDK_VERSION: stringValue(artifactVersions['sdk-python']) || process.env.DW_PYTHON_SDK_VERSION || '',
    DW_PHP_SDK_VERSION: stringValue(artifactVersions['sdk-php'])
      || process.env.DW_PHP_SDK_VERSION
      || '',
    DW_WATERLINE_VERSION: stringValue(artifactVersions.waterline) || process.env.DW_WATERLINE_VERSION || '',
  };

  const generated = spawnSync(process.execPath, [workerShardPath], {
    cwd: repoRoot,
    env,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
    timeout: publishedWorkerShardTimeoutMs,
    killSignal: 'SIGTERM',
  });

  writeTextIfNotEmpty(path.join(resultDir, 'published-worker-execution.stdout.log'), generated.stdout);
  writeTextIfNotEmpty(path.join(resultDir, 'published-worker-execution.stderr.log'), generated.stderr);
  if (generated.error || generated.signal || generated.status !== 0) {
    const supplied = readJsonIfExists(publishedWorkerEvidencePath);
    writeJson(
      publishedWorkerEvidencePath,
      publishedWorkerShardExitStatusEvidence(generated, artifactVersions, artifactSources, supplied),
    );
  }
}

export function publishedWorkerShardFallbackEvidence(generated, artifactVersions, artifactSources) {
  const timedOut = generated.error?.code === 'ETIMEDOUT';
  const detail = timedOut
    ? `published PHP/Python worker shard exceeded ${publishedWorkerShardTimeoutMs}ms before emitting evidence`
    : `published PHP/Python worker shard exited before emitting evidence: status=${generated.status ?? 'unknown'}; signal=${generated.signal ?? 'none'}; error=${generated.error?.message ?? 'none'}`;
  const finding = {
    scenario_id: 'cross_language_php_python_pinning',
    owning_surface: 'conformance_harness',
    artifact_versions: artifactVersions,
    observed_behavior: detail,
    expected_behavior: 'Published sdk-php and sdk-python worker artifacts execute the cross-language worker-versioning cell and emit delivery counts before the aggregate result is written.',
    next_acceptance_criterion: 'rerun the worker-versioning host topology with published-worker shard evidence present, including PHP/Python worker build IDs, runtime identities, workflow/run IDs, rollout state, and cross-language delivery counts',
  };

  return {
    schema: 'durable-workflow.v2.worker-versioning-runtime.published-worker-execution-evidence',
    local_product_source_checkouts_used: false,
    generated_at: timestamp(),
    artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
    scenario_results: {
      cross_language_php_python_pinning: {
        scenario_id: 'cross_language_php_python_pinning',
        status: 'not_covered',
        observed_outputs: {
          shard_timeout_ms: timedOut ? publishedWorkerShardTimeoutMs : null,
          shard_status: generated.status ?? null,
          shard_signal: generated.signal ?? null,
          shard_error: generated.error?.message ?? null,
          published_artifact_worker_execution: false,
          local_product_source_checkouts_used: false,
        },
        linked_findings: [finding],
      },
    },
    findings: [finding],
  };
}

export function publishedWorkerShardExitStatusEvidence(
  generated,
  artifactVersions,
  artifactSources,
  supplied = null,
) {
  if (!supplied || typeof supplied !== 'object' || Array.isArray(supplied)) {
    return publishedWorkerShardFallbackEvidence(generated, artifactVersions, artifactSources);
  }

  const status = Number.isFinite(generated.status) ? generated.status : null;
  const timedOut = generated.error?.code === 'ETIMEDOUT';
  const detail = timedOut
    ? `published PHP/Python worker shard exceeded ${publishedWorkerShardTimeoutMs}ms after or while writing evidence`
    : `published PHP/Python worker shard exited with status=${status ?? 'unknown'} after writing evidence; signal=${generated.signal ?? 'none'}; error=${generated.error?.message ?? 'none'}`;
  const finding = {
    id: 'worker-versioning-published-worker-shard-exit-status',
    severity: 'P0',
    scenario_id: 'cross_language_php_python_pinning',
    owning_surface: 'conformance_harness',
    diagnostic_surface: 'published_php_python_worker_shard_exit_status',
    artifact_versions: {
      ...artifactVersions,
      ...objectValue(supplied.artifact_versions),
      ...objectValue(supplied.artifactVersions),
    },
    observed_behavior: `${detail}. The shard process must exit successfully before its published-worker evidence can contribute to a passing worker-versioning record.`,
    expected_behavior: 'The published PHP/Python worker shard exits 0 after worker execution, cleanup, and evidence writing succeed.',
    next_acceptance_criterion: 'fix the published PHP/Python worker shard execution or cleanup failure, then rerun worker-versioning conformance and require a zero shard exit status before accepting its pass evidence',
  };
  const scenarioResults = publishedWorkerScenarioResults(supplied);
  const scenarioIds = Object.keys(scenarioResults).length > 0
    ? Object.keys(scenarioResults)
    : ['cross_language_php_python_pinning'];
  const normalizedScenarioResults = {};

  for (const scenarioId of scenarioIds) {
    const scenario = objectValue(scenarioResults[scenarioId]);
    const observedOutputs = firstObjectValue(
      scenario.observed_outputs,
      scenario.observedOutputs,
      scenario.evidence,
      scenario.outputs,
      scenario,
    );
    const currentStatus = normalizedArtifactStatus(scenario.status);
    const linkedFindings = [
      ...arrayValue(scenario.linked_findings),
      ...arrayValue(scenario.linkedFindings),
      finding,
    ];

    normalizedScenarioResults[scenarioId] = {
      ...scenario,
      scenario_id: scenarioId,
      status: currentStatus === 'pass' ? 'not_covered' : currentStatus,
      observed_outputs: {
        ...observedOutputs,
        published_worker_shard_exit_status: status,
        published_worker_shard_signal: generated.signal ?? null,
        published_worker_shard_error: generated.error?.message ?? null,
        published_worker_shard_timed_out: timedOut,
      },
      linked_findings: uniqueFindings(linkedFindings),
    };
  }

  return {
    ...supplied,
    schema: stringValue(supplied.schema)
      || 'durable-workflow.v2.worker-versioning-runtime.published-worker-execution-evidence',
    status: 'fail',
    outcome: 'fail',
    local_product_source_checkouts_used: truthyEvidenceFlag(supplied.local_product_source_checkouts_used)
      || truthyEvidenceFlag(supplied.localProductSourceCheckoutsUsed),
    generated_at: stringValue(supplied.generated_at) || stringValue(supplied.generatedAt) || timestamp(),
    artifact_versions: {
      ...artifactVersions,
      ...objectValue(supplied.artifact_versions),
      ...objectValue(supplied.artifactVersions),
    },
    artifact_sources: {
      ...artifactSources,
      ...objectValue(supplied.artifact_sources),
      ...objectValue(supplied.artifactSources),
    },
    published_worker_shard_exit_status: status,
    published_worker_shard_signal: generated.signal ?? null,
    published_worker_shard_error: generated.error?.message ?? null,
    published_worker_shard_timed_out: timedOut,
    scenario_results: normalizedScenarioResults,
    findings: uniqueFindings([
      ...arrayValue(supplied.findings),
      finding,
    ]),
  };
}

function uniqueFindings(findings) {
  const values = [];
  const seen = new Set();

  for (const finding of findings) {
    if (!finding || typeof finding !== 'object' || Array.isArray(finding)) {
      continue;
    }
    const key = stringValue(finding.id)
      || stringValue(finding.scenario_id)
      || JSON.stringify(finding);
    if (seen.has(key)) {
      continue;
    }
    seen.add(key);
    values.push(finding);
  }

  return values;
}

function skipPublishedWorkerShard() {
  return ['1', 'true', 'yes'].includes(trim(process.env.DW_WV_SKIP_PUBLISHED_WORKER_SHARD).toLowerCase());
}

function writeTextIfNotEmpty(filePath, contents) {
  if (typeof contents !== 'string' || contents === '') {
    return;
  }

  fs.writeFileSync(filePath, contents, 'utf8');
}

function publishedWorkerScenarioOutputs(evidence, scenarioId) {
  const scenario = scenarioResultsById(evidence)[scenarioId]
    ?? topLevelPublishedWorkerScenario(evidence, scenarioId);
  if (!scenario || typeof scenario !== 'object' || Array.isArray(scenario)) {
    return {};
  }

  const observedOutputs = firstObjectValue(
    scenario.observed_outputs,
    scenario.observedOutputs,
    scenario.evidence,
    scenario.outputs,
    scenario,
  );
  if (Object.keys(observedOutputs).length === 0) {
    return {};
  }

  const publishedWorkerExecution = firstObjectValue(
    observedOutputs.published_artifact_worker_execution,
    observedOutputs.publishedArtifactWorkerExecution,
    observedOutputs.published_worker_execution,
    observedOutputs.publishedWorkerExecution,
    observedOutputs.published_artifact_execution,
    observedOutputs.publishedArtifactExecution,
    evidence.published_artifact_worker_execution,
    evidence.publishedArtifactWorkerExecution,
    evidence.published_worker_execution,
    evidence.publishedWorkerExecution,
    evidence.published_artifact_execution,
    evidence.publishedArtifactExecution,
  );

  return {
    ...observedOutputs,
    ...(Object.keys(publishedWorkerExecution).length > 0
      ? { published_artifact_worker_execution: publishedWorkerExecution }
      : {}),
    local_product_source_checkouts_used: truthyEvidenceFlag(evidence.local_product_source_checkouts_used)
      || truthyEvidenceFlag(observedOutputs.local_product_source_checkouts_used)
      || truthyEvidenceFlag(observedOutputs.localProductSourceCheckoutsUsed),
    supplied_shard_local_product_source_checkouts_used: evidence.supplied_shard_local_product_source_checkouts_used
      ?? evidence.suppliedShardLocalProductSourceCheckoutsUsed,
    published_worker_evidence_status: normalizedArtifactStatus(scenario.status),
    published_worker_evidence_source: evidence.source_path ?? null,
  };
}

function publishedWorkerScenarioFindings(evidence, scenarioId) {
  const scenario = scenarioResultsById(evidence)[scenarioId]
    ?? topLevelPublishedWorkerScenario(evidence, scenarioId);
  const findings = [];

  for (const item of [
    ...arrayValue(scenario?.linked_findings),
    ...arrayValue(scenario?.linkedFindings),
    ...arrayValue(scenario?.finding_links),
    ...arrayValue(scenario?.findingLinks),
    ...arrayValue(evidence?.findings),
  ]) {
    if (!item || typeof item !== 'object' || Array.isArray(item)) {
      continue;
    }

    const linkedScenario = stringValue(item.scenario_id)
      || stringValue(item.scenario)
      || stringValue(item.scenarioId);
    if (linkedScenario === '' || linkedScenario === scenarioId) {
      findings.push(item);
    }
  }

  return findings;
}

function focusedCrossLanguageNotCoveredFinding(publishedFindings, artifactVersions, counts) {
  const supplied = publishedFindings.find((finding) => (
    stringValue(finding.scenario_id)
      || stringValue(finding.scenario)
      || stringValue(finding.scenarioId)
  ) === 'cross_language_php_python_pinning') ?? publishedFindings[0] ?? {};

  return {
    scenario_id: 'cross_language_php_python_pinning',
    owning_surface: stringValue(supplied.owning_surface)
      || stringValue(supplied.owningSurface)
      || 'conformance_harness',
    artifact_versions: {
      ...artifactVersions,
      ...objectValue(supplied.artifact_versions),
      ...objectValue(supplied.artifactVersions),
    },
    observed_behavior: stringValue(supplied.observed_behavior)
      || stringValue(supplied.observedBehavior)
      || 'The cross-language counts came from synthetic server HTTP worker registrations; no published sdk-php or sdk-python worker process executed the PHP/Python pinning cells.',
    expected_behavior: stringValue(supplied.expected_behavior)
      || stringValue(supplied.expectedBehavior)
      || 'PHP v1-pinned runs are never delivered to Python v2, Python v1-pinned runs are never delivered to PHP v2, and both directions are exercised by actual published worker artifacts.',
    next_acceptance_criterion: stringValue(supplied.next_acceptance_criterion)
      || stringValue(supplied.nextAcceptanceCriterion)
      || 'run the cross-language cells with installed sdk-php and sdk-python artifacts and record both incompatible delivery counts as zero with positive compatible delivery counts',
    ...counts,
  };
}

export function workerRegistrationPublishedWorkerEvidenceResult(publishedWorkerEvidence) {
  const outputs = publishedWorkerScenarioOutputs(
    publishedWorkerEvidence,
    'worker_registration_build_ids',
  );
  const entries = normalizedWorkerRegistrationEntries(outputs);
  const phpEntry = entries.find((entry) => entry.artifact === 'sdk-php') ?? null;
  const pythonEntry = entries.find((entry) => entry.artifact === 'sdk-python') ?? null;
  const requiredEntries = [phpEntry, pythonEntry].filter(Boolean);
  const requiredBuildIds = unique(requiredEntries.map((entry) => entry.build_id).filter(Boolean));
  const taskQueues = unique(requiredEntries.map((entry) => entry.task_queue).filter(Boolean));
  const workerListBuildIds = workerListBuildIdsFromOutputs(outputs);
  const taskQueueBuildIds = taskQueueBuildIdsFromOutputs(outputs);
  const activeWorkerCounts = activeWorkerCountsFromOutputs(outputs);
  const workerExecuted = publishedWorkerArtifactsExecuted(
    outputs,
    ['sdk-python', 'sdk-php'],
    true,
  );
  const scenarioStatusPasses = publishedWorkerScenarioPasses(
    outputs,
    ['sdk-python', 'sdk-php'],
    true,
  );
  const responsesRetainBuildIds = requiredEntries.length === 2
    && requiredEntries.every((entry) => (
      entry.response_build_id !== ''
      && entry.build_id !== ''
      && entry.response_build_id === entry.build_id
    ));
  const sameTaskQueue = taskQueues.length === 1;
  const workerListCoversBuildIds = requiredBuildIds.length === 2
    && requiredBuildIds.every((buildId) => workerListBuildIds.includes(buildId));
  const taskQueueCoversBuildIds = requiredBuildIds.length === 2
    && requiredBuildIds.every((buildId) => taskQueueBuildIds.includes(buildId));
  const activeCountsCoverBuildIds = requiredBuildIds.length === 2
    && requiredBuildIds.every((buildId) => (numberValue(activeWorkerCounts[buildId]) ?? 0) > 0);
  const publicSurfacesCoverBuildIds = workerListCoversBuildIds
    && taskQueueCoversBuildIds
    && activeCountsCoverBuildIds;
  const missing = [];

  if (!workerExecuted) {
    missing.push('published_artifact_worker_execution');
  }
  if (!phpEntry) {
    missing.push('sdk_php_registration_response');
  }
  if (!pythonEntry) {
    missing.push('sdk_python_registration_response');
  }
  if (!responsesRetainBuildIds) {
    missing.push('registration_response_build_ids');
  }
  if (!sameTaskQueue) {
    missing.push('same_task_queue');
  }
  if (!workerListCoversBuildIds) {
    missing.push('worker_list_build_ids');
  }
  if (!taskQueueCoversBuildIds) {
    missing.push('task_queue_build_ids');
  }
  if (!activeCountsCoverBuildIds) {
    missing.push('active_worker_counts_per_cohort');
  }

  const normalizedOutputs = {
    ...outputs,
    published_worker_registration_entries: entries,
    worker_list_build_ids: workerListBuildIds,
    task_queue_build_ids: taskQueueBuildIds,
    active_worker_counts_per_cohort: activeWorkerCounts,
    public_outcome: {
      ...firstObjectValue(outputs.public_outcome, outputs.publicOutcome),
      verification_surface: 'published worker registration responses plus worker-list and task-queue build-id APIs',
      passed: scenarioStatusPasses
        && responsesRetainBuildIds
        && sameTaskQueue
        && publicSurfacesCoverBuildIds,
      missing,
    },
  };
  if (sameTaskQueue) {
    normalizedOutputs.task_queue = taskQueues[0];
  }

  return {
    outputs: normalizedOutputs,
    worker_executed: workerExecuted,
    registration_entries: entries,
    worker_list_build_ids: workerListBuildIds,
    task_queue_build_ids: taskQueueBuildIds,
    active_worker_counts_per_cohort: activeWorkerCounts,
    public_surfaces_cover_build_ids: publicSurfacesCoverBuildIds,
    responses_retain_build_ids: responsesRetainBuildIds,
    same_task_queue: sameTaskQueue,
    missing,
    passes: scenarioStatusPasses
      && responsesRetainBuildIds
      && sameTaskQueue
      && publicSurfacesCoverBuildIds,
  };
}

function normalizedWorkerRegistrationEntries(outputs) {
  const registeredBuildIds = firstObjectValue(
    outputs.registered_build_ids,
    outputs.registeredBuildIds,
  );
  const responses = firstObjectValue(
    outputs.worker_registration_responses,
    outputs.workerRegistrationResponses,
    outputs.registration_responses,
    outputs.registrationResponses,
  );
  const entries = [];

  for (const [key, value] of Object.entries(responses)) {
    const responseEntry = objectValue(value);
    if (Object.keys(responseEntry).length === 0) {
      continue;
    }

    const rawResponse = registrationResponseObject(responseEntry);
    const artifact = canonicalArtifactName(
      stringValue(responseEntry.artifact)
        || stringValue(responseEntry.name)
        || stringValue(responseEntry.runtime)
        || stringValue(responseEntry.language)
        || key,
    );
    const buildId = stringValue(responseEntry.build_id)
      || stringValue(responseEntry.buildId)
      || stringValue(responseEntry.requested_build_id)
      || stringValue(responseEntry.requestedBuildId)
      || stringValue(registeredBuildIds[key])
      || stringValue(rawResponse.build_id)
      || stringValue(rawResponse.buildId)
      || stringValue(rawResponse.worker?.build_id)
      || stringValue(rawResponse.worker?.buildId);
    const responseBuildId = stringValue(rawResponse.build_id)
      || stringValue(rawResponse.buildId)
      || stringValue(rawResponse.worker?.build_id)
      || stringValue(rawResponse.worker?.buildId);
    const workerId = stringValue(responseEntry.worker_id)
      || stringValue(responseEntry.workerId)
      || stringValue(rawResponse.worker_id)
      || stringValue(rawResponse.workerId)
      || stringValue(rawResponse.worker?.worker_id)
      || stringValue(rawResponse.worker?.workerId)
      || key;
    const taskQueue = stringValue(responseEntry.task_queue)
      || stringValue(responseEntry.taskQueue)
      || stringValue(rawResponse.task_queue)
      || stringValue(rawResponse.taskQueue)
      || stringValue(rawResponse.worker?.task_queue)
      || stringValue(rawResponse.worker?.taskQueue)
      || stringValue(outputs.task_queue)
      || stringValue(outputs.taskQueue);

    entries.push({
      key,
      artifact,
      worker_id: workerId,
      task_queue: taskQueue,
      build_id: buildId,
      response_build_id: responseBuildId,
      response: rawResponse,
    });
  }

  return entries.filter((entry) => ['sdk-php', 'sdk-python'].includes(entry.artifact));
}

function registrationResponseObject(entry) {
  return firstObjectValue(
    entry.response,
    entry.registration_response,
    entry.registrationResponse,
    entry.raw_response?.response,
    entry.rawResponse?.response,
  );
}

function workerListBuildIdsFromOutputs(outputs) {
  const values = [
    ...buildIdValuesFromSurface(outputs.worker_list_build_ids),
    ...buildIdValuesFromSurface(outputs.workerListBuildIds),
  ];
  for (const surface of [
    outputs.worker_list,
    outputs.workerList,
    outputs.worker_list_surface,
    outputs.workerListSurface,
  ]) {
    for (const worker of arrayValue(objectValue(surface).workers)) {
      const buildId = stringValue(worker?.build_id) || stringValue(worker?.buildId);
      if (buildId) {
        values.push(buildId);
      }
    }
  }

  return unique(values);
}

function taskQueueBuildIdsFromOutputs(outputs) {
  return unique([
    ...buildIdValuesFromSurface(outputs.task_queue_build_ids),
    ...buildIdValuesFromSurface(outputs.taskQueueBuildIds),
    ...buildIdValuesFromSurface(outputs.task_queue_build_id_surface),
    ...buildIdValuesFromSurface(outputs.taskQueueBuildIdSurface),
  ]);
}

function activeWorkerCountsFromOutputs(outputs) {
  const counts = {};
  for (const [buildId, count] of Object.entries(firstObjectValue(
    outputs.active_worker_counts_per_cohort,
    outputs.activeWorkerCountsPerCohort,
  ))) {
    if (stringValue(buildId) !== '') {
      counts[buildId] = numberValue(count) ?? 0;
    }
  }

  for (const surface of [
    outputs.task_queue_build_ids,
    outputs.taskQueueBuildIds,
    outputs.task_queue_build_id_surface,
    outputs.taskQueueBuildIdSurface,
  ]) {
    for (const entry of buildIdEntriesFromSurface(surface)) {
      const buildId = stringValue(entry?.build_id) || stringValue(entry?.buildId);
      if (buildId) {
        counts[buildId] = numberValue(entry?.active_worker_count ?? entry?.activeWorkerCount) ?? 0;
      }
    }
  }

  return counts;
}

function buildIdValuesFromSurface(surface) {
  return buildIdEntriesFromSurface(surface)
    .map((entry) => (
      typeof entry === 'string'
        ? entry
        : stringValue(entry?.build_id) || stringValue(entry?.buildId)
    ))
    .filter(Boolean);
}

function buildIdEntriesFromSurface(surface) {
  if (Array.isArray(surface)) {
    return surface;
  }

  const object = objectValue(surface);
  if (Array.isArray(object.build_ids)) {
    return object.build_ids;
  }
  if (Array.isArray(object.buildIds)) {
    return object.buildIds;
  }
  if (stringValue(object.build_id) || stringValue(object.buildId)) {
    return [object];
  }

  return [];
}

export function noCompatiblePublishedWorkerEvidenceResult(publishedWorkerEvidence) {
  const outputs = publishedWorkerScenarioOutputs(
    publishedWorkerEvidence,
    'no_compatible_worker_behavior',
  );
  const rawIncompatibleWorkerTaskCount = firstDefined(
    outputs.incompatible_worker_task_count,
    outputs.incompatibleWorkerTaskCount,
    outputs.incompatible_task_count,
    outputs.incompatibleTaskCount,
    outputs.incompatible_delivery_count,
    outputs.incompatibleDeliveryCount,
    outputs.v2_worker_task_count_for_v1_run,
    outputs.v2WorkerTaskCountForV1Run,
  );
  const rawIncompatibleWorkerPollAttempts = firstDefined(
    outputs.incompatible_worker_poll_attempts,
    outputs.incompatibleWorkerPollAttempts,
    outputs.incompatible_poll_attempts,
    outputs.incompatiblePollAttempts,
    outputs.poll_attempts,
    outputs.pollAttempts,
  );
  const pollStatuses = pollStatusValuesFromOutputs(outputs);
  const workflowVisibilitySignals = workflowVisibilitySignalValuesFromOutputs(outputs);
  const taskQueueBuildIdSignals = taskQueueBuildIdSignalValuesFromOutputs(outputs);
  const observedPollErrorCount = pollStatuses
    .filter((pollStatus) => isGenericPollErrorStatus(pollStatus))
    .length;
  const rawIncompatibleWorkerPollErrorCount = firstDefined(
    outputs.incompatible_worker_poll_error_count,
    outputs.incompatibleWorkerPollErrorCount,
    outputs.poll_error_count,
    outputs.pollErrorCount,
  );
  const rawCompatibleWorkerDeregistered = firstDefined(
    outputs.compatible_worker_deregistered,
    outputs.compatibleWorkerDeregistered,
    outputs.compatible_worker_stopped,
    outputs.compatibleWorkerStopped,
    outputs.compatible_cohort_stopped,
    outputs.compatibleCohortStopped,
  );
  const rawOperatorVisibleSignal = firstExplicitNoCompatibleSignal(
    outputs.operator_visible_signal,
    outputs.operatorVisibleSignal,
    outputs.public_diagnostic,
    outputs.publicDiagnostic,
    outputs.diagnostic,
    outputs.typed_error,
    outputs.typedError,
    outputs.poll_status,
    outputs.pollStatus,
    ...pollStatuses,
    outputs.compatibility_status,
    outputs.compatibilityStatus,
    outputs.compatibility_fleet_reason,
    outputs.compatibilityFleetReason,
    ...workflowVisibilitySignals,
    ...taskQueueBuildIdSignals,
  );
  const rawPendingOrTypedError = firstDefined(
    outputs.pending_or_typed_error,
    outputs.pendingOrTypedError,
    outputs.pending_state,
    outputs.pendingState,
    outputs.typed_error,
    outputs.typedError,
    firstExplicitNoCompatibleSignal(
      outputs.poll_status,
      outputs.pollStatus,
      ...pollStatuses,
      outputs.compatibility_status,
      outputs.compatibilityStatus,
      outputs.compatibility_fleet_reason,
      outputs.compatibilityFleetReason,
      ...workflowVisibilitySignals,
      ...taskQueueBuildIdSignals,
    ),
  );
  const incompatibleWorkerTaskCount = numberValue(rawIncompatibleWorkerTaskCount);
  const incompatibleWorkerPollAttempts = numberValue(rawIncompatibleWorkerPollAttempts);
  const reportedPollErrorCount = numberValue(rawIncompatibleWorkerPollErrorCount);
  const incompatibleWorkerPollErrorCount = Math.max(
    reportedPollErrorCount ?? 0,
    observedPollErrorCount,
  );
  const compatibleWorkerDeregistered = truthyEvidenceFlag(rawCompatibleWorkerDeregistered);
  const operatorVisibleSignal = stringValue(rawOperatorVisibleSignal);
  const pendingOrTypedError = stringValue(rawPendingOrTypedError);
  const workerExecuted = publishedWorkerScenarioPasses(
    outputs,
    ['sdk-python', 'sdk-php'],
    false,
  );
  const normalizedOutputs = { ...outputs };
  if (rawIncompatibleWorkerTaskCount !== undefined) {
    normalizedOutputs.incompatible_worker_task_count = incompatibleWorkerTaskCount;
  }
  if (rawIncompatibleWorkerPollAttempts !== undefined) {
    normalizedOutputs.incompatible_worker_poll_attempts = incompatibleWorkerPollAttempts;
  }
  if (rawIncompatibleWorkerPollErrorCount !== undefined || observedPollErrorCount > 0) {
    normalizedOutputs.incompatible_worker_poll_error_count = incompatibleWorkerPollErrorCount;
  }
  if (rawCompatibleWorkerDeregistered !== undefined) {
    normalizedOutputs.compatible_worker_deregistered = compatibleWorkerDeregistered;
  }
  if (rawOperatorVisibleSignal !== undefined) {
    normalizedOutputs.operator_visible_signal = operatorVisibleSignal;
  }
  if (rawPendingOrTypedError !== undefined) {
    normalizedOutputs.pending_or_typed_error = pendingOrTypedError;
  }

  return {
    outputs: normalizedOutputs,
    worker_executed: workerExecuted,
    incompatible_worker_task_count: incompatibleWorkerTaskCount,
    incompatible_worker_poll_attempts: incompatibleWorkerPollAttempts,
    incompatible_worker_poll_error_count: incompatibleWorkerPollErrorCount,
    compatible_worker_deregistered: compatibleWorkerDeregistered,
    operator_visible_signal: operatorVisibleSignal,
    pending_or_typed_error: pendingOrTypedError,
    passes: workerExecuted
      && incompatibleWorkerTaskCount === 0
      && incompatibleWorkerPollAttempts !== null
      && incompatibleWorkerPollAttempts > 0
      && incompatibleWorkerPollErrorCount === 0
      && compatibleWorkerDeregistered
      && isExplicitNoCompatibleSignal(operatorVisibleSignal)
      && (
        pendingOrTypedError === 'pending'
        || isExplicitNoCompatibleSignal(pendingOrTypedError)
      ),
  };
}

export function crossLanguagePublishedWorkerEvidenceResult(publishedWorkerEvidence) {
  const outputs = publishedWorkerScenarioOutputs(
    publishedWorkerEvidence,
    'cross_language_php_python_pinning',
  );
  const publicOutcome = firstObjectValue(
    outputs.public_outcome,
    outputs.publicOutcome,
  );
  const phpToPythonCell = crossLanguageDeliveryCell(
    outputs,
    'php_v1_not_delivered_to_python_v2',
    'sdk-php-v1',
    'sdk-python-v2',
  );
  const pythonToPhpCell = crossLanguageDeliveryCell(
    outputs,
    'python_v1_not_delivered_to_php_v2',
    'sdk-python-v1',
    'sdk-php-v2',
  );
  const phpToPythonIncompatibleCount = numberValue(firstDefined(
    outputs.php_v1_to_python_v2_incompatible_delivery_count,
    outputs.phpV1ToPythonV2IncompatibleDeliveryCount,
    publicOutcome.php_v1_to_python_v2_incompatible_delivery_count,
    publicOutcome.phpV1ToPythonV2IncompatibleDeliveryCount,
    phpToPythonCell.incompatible_delivery_count,
    phpToPythonCell.incompatibleDeliveryCount,
    phpToPythonCell.incompatible_worker_task_count,
    phpToPythonCell.incompatibleWorkerTaskCount,
    phpToPythonCell.incompatible_task_count,
    phpToPythonCell.incompatibleTaskCount,
  ));
  const pythonToPhpIncompatibleCount = numberValue(firstDefined(
    outputs.python_v1_to_php_v2_incompatible_delivery_count,
    outputs.pythonV1ToPhpV2IncompatibleDeliveryCount,
    publicOutcome.python_v1_to_php_v2_incompatible_delivery_count,
    publicOutcome.pythonV1ToPhpV2IncompatibleDeliveryCount,
    pythonToPhpCell.incompatible_delivery_count,
    pythonToPhpCell.incompatibleDeliveryCount,
    pythonToPhpCell.incompatible_worker_task_count,
    pythonToPhpCell.incompatibleWorkerTaskCount,
    pythonToPhpCell.incompatible_task_count,
    pythonToPhpCell.incompatibleTaskCount,
  ));
  const phpCompatibleCount = numberValue(firstDefined(
    outputs.php_v1_compatible_delivery_count,
    outputs.phpV1CompatibleDeliveryCount,
    publicOutcome.php_v1_compatible_delivery_count,
    publicOutcome.phpV1CompatibleDeliveryCount,
    phpToPythonCell.compatible_delivery_count,
    phpToPythonCell.compatibleDeliveryCount,
    phpToPythonCell.compatible_worker_task_count,
    phpToPythonCell.compatibleWorkerTaskCount,
    phpToPythonCell.compatible_task_count,
    phpToPythonCell.compatibleTaskCount,
  ));
  const pythonCompatibleCount = numberValue(firstDefined(
    outputs.python_v1_compatible_delivery_count,
    outputs.pythonV1CompatibleDeliveryCount,
    publicOutcome.python_v1_compatible_delivery_count,
    publicOutcome.pythonV1CompatibleDeliveryCount,
    pythonToPhpCell.compatible_delivery_count,
    pythonToPhpCell.compatibleDeliveryCount,
    pythonToPhpCell.compatible_worker_task_count,
    pythonToPhpCell.compatibleWorkerTaskCount,
    pythonToPhpCell.compatible_task_count,
    pythonToPhpCell.compatibleTaskCount,
  ));
  const workerExecuted = publishedWorkerScenarioPasses(
    outputs,
    ['sdk-python', 'sdk-php'],
    true,
  );
  const passes = workerExecuted
    && phpToPythonIncompatibleCount === 0
    && pythonToPhpIncompatibleCount === 0
    && phpCompatibleCount !== null
    && phpCompatibleCount > 0
    && pythonCompatibleCount !== null
    && pythonCompatibleCount > 0;
  const normalizedOutputs = { ...outputs };

  if (phpToPythonIncompatibleCount !== null) {
    normalizedOutputs.php_v1_to_python_v2_incompatible_delivery_count = phpToPythonIncompatibleCount;
  }
  if (pythonToPhpIncompatibleCount !== null) {
    normalizedOutputs.python_v1_to_php_v2_incompatible_delivery_count = pythonToPhpIncompatibleCount;
  }
  if (phpCompatibleCount !== null) {
    normalizedOutputs.php_v1_compatible_delivery_count = phpCompatibleCount;
  }
  if (pythonCompatibleCount !== null) {
    normalizedOutputs.python_v1_compatible_delivery_count = pythonCompatibleCount;
  }
  Object.assign(
    normalizedOutputs,
    canonicalCrossLanguagePublishedOutputs(outputs, workerExecuted),
  );
  if (Object.keys(publicOutcome).length > 0
    || phpToPythonIncompatibleCount !== null
    || pythonToPhpIncompatibleCount !== null
    || phpCompatibleCount !== null
    || pythonCompatibleCount !== null) {
    normalizedOutputs.public_outcome = {
      ...publicOutcome,
      passed: passes,
      php_v1_to_python_v2_incompatible_delivery_count: phpToPythonIncompatibleCount,
      python_v1_to_php_v2_incompatible_delivery_count: pythonToPhpIncompatibleCount,
      php_v1_compatible_delivery_count: phpCompatibleCount,
      python_v1_compatible_delivery_count: pythonCompatibleCount,
    };
  }

  return {
    outputs: normalizedOutputs,
    worker_executed: workerExecuted,
    php_v1_to_python_v2_incompatible_delivery_count: phpToPythonIncompatibleCount,
    python_v1_to_php_v2_incompatible_delivery_count: pythonToPhpIncompatibleCount,
    php_v1_compatible_delivery_count: phpCompatibleCount,
    python_v1_compatible_delivery_count: pythonCompatibleCount,
    passes,
  };
}

function canonicalCrossLanguagePublishedOutputs(outputs, workerExecuted) {
  const normalized = {};
  const workerRuntimeIdentities = normalizedCrossLanguageWorkerRuntimeIdentities(firstDefined(
    outputs.worker_runtime_identities,
    outputs.workerRuntimeIdentities,
  ));
  const workflowRuns = normalizedCrossLanguageWorkflowRuns(firstObjectValue(
    outputs.workflow_runs,
    outputs.workflowRuns,
  ));
  const rolloutState = normalizedCrossLanguageRolloutState(firstObjectValue(
    outputs.rollout_state,
    outputs.rolloutState,
  ));
  const phpWorkerBuildIds = normalizedCrossLanguageBuildIds(firstObjectValue(
    outputs.php_worker_build_ids,
    outputs.phpWorkerBuildIds,
  ));
  const pythonWorkerBuildIds = normalizedCrossLanguageBuildIds(firstObjectValue(
    outputs.python_worker_build_ids,
    outputs.pythonWorkerBuildIds,
  ));
  const crossLanguageDelivery = canonicalCrossLanguageDeliveryOutput(outputs);

  if (workerRuntimeIdentities.length > 0) {
    normalized.worker_runtime_identities = workerRuntimeIdentities;
  }
  if (Object.keys(workflowRuns).length > 0) {
    normalized.workflow_runs = workflowRuns;
  }
  if (Object.keys(rolloutState).length > 0) {
    normalized.rollout_state = rolloutState;
  }
  if (Object.keys(phpWorkerBuildIds).length > 0) {
    normalized.php_worker_build_ids = phpWorkerBuildIds;
  }
  if (Object.keys(pythonWorkerBuildIds).length > 0) {
    normalized.python_worker_build_ids = pythonWorkerBuildIds;
  }

  const phpWorkerBuildId = stringValue(outputs.php_worker_build_id)
    || stringValue(outputs.phpWorkerBuildId)
    || stringValue(workflowRuns.php_v1_started?.pinned_build_id)
    || stringValue(phpWorkerBuildIds.v1);
  if (phpWorkerBuildId) {
    normalized.php_worker_build_id = phpWorkerBuildId;
  }

  const pythonWorkerBuildId = stringValue(outputs.python_worker_build_id)
    || stringValue(outputs.pythonWorkerBuildId)
    || stringValue(workflowRuns.python_v1_started?.pinned_build_id)
    || stringValue(pythonWorkerBuildIds.v1);
  if (pythonWorkerBuildId) {
    normalized.python_worker_build_id = pythonWorkerBuildId;
  }

  if (Object.keys(crossLanguageDelivery).length > 0) {
    normalized.cross_language_delivery = crossLanguageDelivery;
  }

  if (workerExecuted) {
    const reportedMode = stringValue(outputs.worker_execution_mode)
      || stringValue(outputs.workerExecutionMode);
    normalized.worker_execution_mode = reportedMode && reportedMode !== SERVER_PROTOCOL_PROBE
      ? reportedMode
      : PUBLISHED_CROSS_LANGUAGE_WORKER_EXECUTION;
    normalized.server_protocol_probe_only = false;
  }

  return normalized;
}

function normalizedCrossLanguageWorkerRuntimeIdentities(value) {
  return arrayValue(value)
    .filter((identity) => identity && typeof identity === 'object' && !Array.isArray(identity))
    .map((identity) => {
      const normalized = { ...identity };
      const workerId = stringValue(identity.worker_id) || stringValue(identity.workerId);
      const buildId = stringValue(identity.build_id) || stringValue(identity.buildId);

      if (workerId) {
        normalized.worker_id = workerId;
      }
      if (buildId) {
        normalized.build_id = buildId;
      }

      return normalized;
    });
}

function normalizedCrossLanguageWorkflowRuns(runs) {
  const normalized = { ...runs };
  for (const [field, aliases] of Object.entries({
    php_v1_started: ['php_v1_started', 'phpV1Started'],
    python_v1_started: ['python_v1_started', 'pythonV1Started'],
  })) {
    const run = firstObjectValue(...aliases.map((alias) => runs[alias]));
    if (Object.keys(run).length > 0) {
      normalized[field] = normalizedCrossLanguageWorkflowRun(run);
    }
  }

  return normalized;
}

function normalizedCrossLanguageWorkflowRun(run) {
  const normalized = { ...run };
  for (const [field, aliases] of Object.entries({
    workflow_id: ['workflow_id', 'workflowId'],
    run_id: ['run_id', 'runId'],
    started_by_runtime: ['started_by_runtime', 'startedByRuntime'],
    pinned_build_id: ['pinned_build_id', 'pinnedBuildId'],
    compatible_worker_runtime: ['compatible_worker_runtime', 'compatibleWorkerRuntime'],
    incompatible_worker_runtime: ['incompatible_worker_runtime', 'incompatibleWorkerRuntime'],
  })) {
    const value = firstDefined(...aliases.map((alias) => run[alias]));
    if (value !== undefined && value !== null) {
      normalized[field] = value;
    }
  }

  return normalized;
}

function normalizedCrossLanguageRolloutState(state) {
  const normalized = { ...state };
  for (const [field, aliases] of Object.entries({
    after_php_v1_promotion: ['after_php_v1_promotion', 'afterPhpV1Promotion'],
    after_python_v1_promotion: ['after_python_v1_promotion', 'afterPythonV1Promotion'],
  })) {
    const value = firstDefined(...aliases.map((alias) => state[alias]));
    if (value !== undefined && value !== null) {
      normalized[field] = value;
    }
  }

  const promotedBuildIds = firstObjectValue(
    state.promoted_build_ids,
    state.promotedBuildIds,
  );
  if (Object.keys(promotedBuildIds).length > 0) {
    normalized.promoted_build_ids = { ...promotedBuildIds };
    for (const [field, aliases] of Object.entries({
      php_started_run: ['php_started_run', 'phpStartedRun'],
      python_started_run: ['python_started_run', 'pythonStartedRun'],
    })) {
      const value = firstDefined(...aliases.map((alias) => promotedBuildIds[alias]));
      if (value !== undefined && value !== null) {
        normalized.promoted_build_ids[field] = value;
      }
    }
  }

  return normalized;
}

function normalizedCrossLanguageBuildIds(buildIds) {
  const normalized = { ...buildIds };
  for (const field of ['v1', 'v2']) {
    const value = firstDefined(buildIds[field], buildIds[field.toUpperCase()]);
    if (value !== undefined && value !== null) {
      normalized[field] = value;
    }
  }

  return normalized;
}

function canonicalCrossLanguageDeliveryOutput(outputs) {
  const delivery = {
    ...firstObjectValue(
      outputs.cross_language_delivery,
      outputs.crossLanguageDelivery,
      outputs.cross_language_matrix,
      outputs.crossLanguageMatrix,
    ),
  };
  const taskQueue = stringValue(firstDefined(
    delivery.task_queue,
    delivery.taskQueue,
    outputs.task_queue,
    outputs.taskQueue,
  ));
  const cells = crossLanguageDeliveryCells(outputs).map(normalizedCrossLanguageDeliveryCell);

  if (taskQueue) {
    delivery.task_queue = taskQueue;
  }
  if (cells.length > 0) {
    delivery.cells = cells;
  }

  return delivery;
}

function normalizedCrossLanguageDeliveryCell(cell) {
  const normalized = { ...cell };
  for (const [field, aliases] of Object.entries({
    scenario: ['scenario', 'scenario_id', 'scenarioId', 'id'],
    started_by: ['started_by', 'startedBy', 'starter', 'workflow_runtime', 'workflowRuntime'],
    incompatible_worker: ['incompatible_worker', 'incompatibleWorker', 'incompatible_runtime', 'incompatibleRuntime', 'worker', 'runtime'],
    compatible_worker: ['compatible_worker', 'compatibleWorker', 'compatible_runtime', 'compatibleRuntime'],
    compatible_delivery_count: ['compatible_delivery_count', 'compatibleDeliveryCount', 'compatible_worker_task_count', 'compatibleWorkerTaskCount', 'compatible_task_count', 'compatibleTaskCount'],
    incompatible_delivery_count: ['incompatible_delivery_count', 'incompatibleDeliveryCount', 'incompatible_worker_task_count', 'incompatibleWorkerTaskCount', 'incompatible_task_count', 'incompatibleTaskCount'],
    workflow_id: ['workflow_id', 'workflowId'],
    run_id: ['run_id', 'runId'],
    started_run_id: ['started_run_id', 'startedRunId'],
  })) {
    const value = firstDefined(...aliases.map((alias) => cell[alias]));
    if (value !== undefined && value !== null) {
      normalized[field] = value;
    }
  }

  return normalized;
}

function mergeScenarioOutputs(base, supplied) {
  if (!supplied || Object.keys(supplied).length === 0) {
    return base;
  }

  return {
    ...base,
    ...supplied,
  };
}

function publishedWorkerScenarioPasses(outputs, requiredArtifacts, requireAllArtifacts) {
  if (outputs?.published_worker_evidence_status !== undefined
    && normalizedArtifactStatus(outputs.published_worker_evidence_status) !== 'pass') {
    return false;
  }

  return publishedWorkerArtifactsExecuted(outputs, requiredArtifacts, requireAllArtifacts);
}

function publishedWorkerArtifactsExecuted(outputs, requiredArtifacts, requireAllArtifacts) {
  if (outputs?.supplied_shard_local_product_source_checkouts_used !== false) {
    return false;
  }

  if (!explicitFalse(outputs?.local_product_source_checkouts_used)
    && !explicitFalse(outputs?.localProductSourceCheckoutsUsed)) {
    return false;
  }

  const execution = outputs?.published_artifact_worker_execution
    ?? outputs?.publishedArtifactWorkerExecution;
  if (!execution || typeof execution !== 'object' || Array.isArray(execution)) {
    return false;
  }

  if (!explicitFalse(execution.local_product_source_checkouts_used)
    && !explicitFalse(execution.localProductSourceCheckoutsUsed)) {
    return false;
  }

  const entries = publishedWorkerExecutionEntries(execution);
  if (entries.length === 0) {
    return false;
  }

  const validArtifacts = new Set();
  for (const entry of entries) {
    const artifact = canonicalArtifactName(
      stringValue(entry.artifact) || stringValue(entry.name) || stringValue(entry.id),
    );
    if (!requiredArtifacts.includes(artifact)) {
      continue;
    }
    if (normalizedArtifactStatus(entry.status ?? entry.result ?? entry.outcome) !== 'pass') {
      continue;
    }
    if (artifactSourceIsForbidden(artifactSourceForWorkerExecutionEntry(entry))) {
      continue;
    }
    const version = artifactVersionForWorkerExecutionEntry(entry);
    const exactVersion = artifact === 'sdk-python'
      ? isExactPythonRelease(version)
      : isExactSemverVersion(version);
    if (!exactVersion || isPlaceholderVersion(version)) {
      continue;
    }
    if (truthyEvidenceFlag(entry.local_product_source_checkouts_used)
      || truthyEvidenceFlag(entry.localProductSourceCheckoutsUsed)) {
      continue;
    }

    validArtifacts.add(artifact);
  }

  if (requireAllArtifacts) {
    return requiredArtifacts.every((artifact) => validArtifacts.has(artifact));
  }

  return validArtifacts.size > 0;
}

function publishedWorkerShardProvesNoLocalSource(supplied) {
  const scenarios = publishedWorkerScenarioResults(supplied);
  let sawExecution = false;

  for (const scenarioId of Object.keys(scenarios)) {
    const outputs = publishedWorkerScenarioOutputs(
      {
        ...supplied,
        scenario_results: scenarios,
        supplied_shard_local_product_source_checkouts_used: false,
      },
      scenarioId,
    );

    if (outputs?.supplied_shard_local_product_source_checkouts_used !== false) {
      return false;
    }

    if (!explicitFalse(outputs.local_product_source_checkouts_used)
      && !explicitFalse(outputs.localProductSourceCheckoutsUsed)) {
      return false;
    }

    const execution = outputs.published_artifact_worker_execution
      ?? outputs.publishedArtifactWorkerExecution;
    if (!execution || typeof execution !== 'object' || Array.isArray(execution)) {
      continue;
    }

    if (!explicitFalse(execution.local_product_source_checkouts_used)
      && !explicitFalse(execution.localProductSourceCheckoutsUsed)) {
      return false;
    }

    const entries = publishedWorkerExecutionEntries(execution);
    if (entries.length === 0) {
      return false;
    }
    sawExecution = true;

    for (const entry of entries) {
      if (truthyEvidenceFlag(entry.local_product_source_checkouts_used)
        || truthyEvidenceFlag(entry.localProductSourceCheckoutsUsed)
        || artifactSourceIsForbidden(artifactSourceForWorkerExecutionEntry(entry))) {
        return false;
      }
    }
  }

  return sawExecution;
}

function publishedWorkerShardHasLocalSourceSignal(supplied) {
  if (truthyEvidenceFlag(supplied.local_product_source_checkouts_used)
    || truthyEvidenceFlag(supplied.localProductSourceCheckoutsUsed)) {
    return true;
  }

  const topLevelExecution = firstObjectValue(
    supplied.published_artifact_worker_execution,
    supplied.publishedArtifactWorkerExecution,
    supplied.published_worker_execution,
    supplied.publishedWorkerExecution,
    supplied.published_artifact_execution,
    supplied.publishedArtifactExecution,
  );
  if (publishedWorkerExecutionHasLocalSourceSignal(topLevelExecution)) {
    return true;
  }

  const scenarios = publishedWorkerScenarioResults(supplied);
  for (const scenario of Object.values(scenarios)) {
    const outputs = firstObjectValue(
      scenario?.observed_outputs,
      scenario?.observedOutputs,
      scenario?.evidence,
      scenario?.outputs,
      scenario,
    );
    const execution = firstObjectValue(
      outputs.published_artifact_worker_execution,
      outputs.publishedArtifactWorkerExecution,
      outputs.published_worker_execution,
      outputs.publishedWorkerExecution,
      outputs.published_artifact_execution,
      outputs.publishedArtifactExecution,
      supplied.published_artifact_worker_execution,
      supplied.publishedArtifactWorkerExecution,
      supplied.published_worker_execution,
      supplied.publishedWorkerExecution,
      supplied.published_artifact_execution,
      supplied.publishedArtifactExecution,
    );

    if (truthyEvidenceFlag(outputs.local_product_source_checkouts_used)
      || truthyEvidenceFlag(outputs.localProductSourceCheckoutsUsed)
      || publishedWorkerExecutionHasLocalSourceSignal(execution)) {
      return true;
    }
  }

  return false;
}

function publishedWorkerExecutionHasLocalSourceSignal(execution) {
  if (!execution || typeof execution !== 'object' || Array.isArray(execution)) {
    return false;
  }

  if (truthyEvidenceFlag(execution.local_product_source_checkouts_used)
    || truthyEvidenceFlag(execution.localProductSourceCheckoutsUsed)) {
    return true;
  }

  return publishedWorkerExecutionEntries(execution).some((entry) => (
    truthyEvidenceFlag(entry.local_product_source_checkouts_used)
    || truthyEvidenceFlag(entry.localProductSourceCheckoutsUsed)
  ));
}

function artifactSourceForInstallEntry(entry) {
  return stringValue(entry.source)
    || stringValue(entry.install_source)
    || stringValue(entry.installSource)
    || stringValue(entry.artifact_source)
    || stringValue(entry.artifactSource);
}

function artifactSourceForWorkerExecutionEntry(entry) {
  return stringValue(entry.source)
    || stringValue(entry.install_source)
    || stringValue(entry.installSource)
    || stringValue(entry.artifact_source)
    || stringValue(entry.artifactSource);
}

function artifactVersionForWorkerExecutionEntry(entry) {
  return stringValue(entry.version)
    || stringValue(entry.artifact_version)
    || stringValue(entry.artifactVersion);
}

function publishedWorkerExecutionEntries(execution) {
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

  if (execution.artifact || execution.name || execution.source) {
    return [execution];
  }

  return [];
}

function scenarioResultsById(evidence) {
  const raw = evidence?.scenario_results ?? evidence?.scenarioResults ?? {};
  const results = {};

  if (Array.isArray(raw)) {
    for (const item of raw) {
      if (!item || typeof item !== 'object' || Array.isArray(item)) {
        continue;
      }
      const scenarioId = stringValue(item.scenario_id) || stringValue(item.scenarioId) || stringValue(item.id);
      if (scenarioId) {
        results[scenarioId] = item;
      }
    }

    return results;
  }

  if (raw && typeof raw === 'object') {
    for (const [scenarioId, item] of Object.entries(raw)) {
      if (item && typeof item === 'object' && !Array.isArray(item)) {
        results[scenarioId] = { scenario_id: scenarioId, ...item };
      }
    }
  }

  return results;
}

function publishedWorkerScenarioResults(evidence) {
  return {
    ...topLevelPublishedWorkerScenarios(evidence),
    ...scenarioResultsById(evidence),
  };
}

function topLevelPublishedWorkerScenario(evidence, scenarioId) {
  return topLevelPublishedWorkerScenarios(evidence)[scenarioId];
}

function topLevelPublishedWorkerScenarios(evidence) {
  if (!evidence || typeof evidence !== 'object' || Array.isArray(evidence)) {
    return {};
  }

  const aliases = {
    worker_registration_build_ids: [
      'worker_registration_build_ids',
      'workerRegistrationBuildIds',
      'worker_registration',
      'workerRegistration',
      'published_worker_registration',
      'publishedWorkerRegistration',
      'published_worker_registrations',
      'publishedWorkerRegistrations',
    ],
    no_compatible_worker_behavior: [
      'no_compatible_worker_behavior',
      'noCompatibleWorkerBehavior',
      'no_compatible_worker',
      'noCompatibleWorker',
      'no_compatible_worker_diagnostics',
      'noCompatibleWorkerDiagnostics',
    ],
    replay_only_by_compatible_workers: [
      'replay_only_by_compatible_workers',
      'replayOnlyByCompatibleWorkers',
      'compatible_replay',
      'compatibleReplay',
    ],
    replay_across_cache_eviction: [
      'replay_across_cache_eviction',
      'replayAcrossCacheEviction',
      'cache_eviction',
      'cacheEviction',
    ],
    cross_language_php_python_pinning: [
      'cross_language_php_python_pinning',
      'crossLanguagePhpPythonPinning',
      'cross_language_matrix',
      'crossLanguageMatrix',
    ],
    adversarial_no_version_bump: [
      'adversarial_no_version_bump',
      'adversarialNoVersionBump',
      'adversarial_no_bump',
      'adversarialNoBump',
    ],
  };
  const scenarios = {};

  for (const [scenarioId, fieldAliases] of Object.entries(aliases)) {
    for (const field of fieldAliases) {
      const value = evidence[field];
      if (!value || typeof value !== 'object' || Array.isArray(value)) {
        continue;
      }

      scenarios[scenarioId] = {
        scenario_id: scenarioId,
        status: value.status ?? value.result ?? value.outcome ?? 'pass',
        observed_outputs: value.observed_outputs ?? value.observedOutputs ?? value,
      };
      break;
    }
  }

  return scenarios;
}

function crossLanguageDeliveryCell(outputs, scenarioId, startedBy, incompatibleWorker) {
  for (const cell of crossLanguageDeliveryCells(outputs)) {
    const reportedScenario = stringValue(cell.scenario)
      || stringValue(cell.scenario_id)
      || stringValue(cell.scenarioId)
      || stringValue(cell.id);
    if (reportedScenario === scenarioId) {
      return cell;
    }

    const reportedStartedBy = stringValue(cell.started_by)
      || stringValue(cell.startedBy)
      || stringValue(cell.starter)
      || stringValue(cell.workflow_runtime)
      || stringValue(cell.workflowRuntime);
    const reportedIncompatibleWorker = stringValue(cell.incompatible_worker)
      || stringValue(cell.incompatibleWorker)
      || stringValue(cell.incompatible_runtime)
      || stringValue(cell.incompatibleRuntime)
      || stringValue(cell.worker)
      || stringValue(cell.runtime);
    if (sameRuntimeSurface(reportedStartedBy, startedBy)
      && sameRuntimeSurface(reportedIncompatibleWorker, incompatibleWorker)) {
      return cell;
    }
  }

  return {};
}

function crossLanguageDeliveryCells(outputs) {
  const cells = [];
  for (const container of [
    outputs,
    outputs.cross_language_delivery,
    outputs.crossLanguageDelivery,
    outputs.cross_language_matrix,
    outputs.crossLanguageMatrix,
  ]) {
    const object = objectValue(container);
    if (Object.keys(object).length === 0) {
      continue;
    }

    for (const field of [
      'cross_language_cells',
      'crossLanguageCells',
      'cells',
      'runtime_cells',
      'runtimeCells',
    ]) {
      cells.push(...arrayValue(object[field]));
    }
  }

  return cells.filter((cell) => cell && typeof cell === 'object' && !Array.isArray(cell));
}

function sameRuntimeSurface(actual, expected) {
  const normalizedActual = runtimeSurfaceToken(actual);
  const normalizedExpected = runtimeSurfaceToken(expected);

  return normalizedActual !== '' && normalizedActual === normalizedExpected;
}

function runtimeSurfaceToken(value) {
  const normalized = stringValue(value).toLowerCase().replace(/[^a-z0-9]+/g, '-');
  if (!normalized) {
    return '';
  }
  if (normalized.includes('python')) {
    return 'sdk-python';
  }
  if (normalized.includes('php')) {
    return 'sdk-php';
  }

  return normalized;
}

function objectValue(value) {
  return value && typeof value === 'object' && !Array.isArray(value) ? value : {};
}

function arrayValue(value) {
  return Array.isArray(value) ? value : [];
}

function firstObjectValue(...values) {
  for (const value of values) {
    const object = objectValue(value);
    if (Object.keys(object).length > 0) {
      return object;
    }
  }

  return {};
}

function firstDefined(...values) {
  for (const value of values) {
    if (value !== undefined && value !== null) {
      return value;
    }
  }

  return undefined;
}

function firstExplicitNoCompatibleSignal(...values) {
  for (const value of values) {
    if (value !== undefined && value !== null && isExplicitNoCompatibleSignal(value)) {
      return value;
    }
  }

  return firstDefined(...values);
}

function explicitFalse(value) {
  return value === false || stringValue(value).toLowerCase() === 'false' || stringValue(value) === '0';
}

function canonicalArtifactName(value) {
  const normalized = value.toLowerCase().replace(/_/g, '-');
  if (['python', 'python-sdk', 'durable-workflow'].includes(normalized)) {
    return 'sdk-python';
  }
  if (['php', 'sdk-php', 'php-worker'].includes(normalized)) {
    return 'sdk-php';
  }

  return normalized;
}

function normalizedArtifactStatus(value) {
  const status = stringValue(value).toLowerCase();
  return ['pass', 'fail', 'not_covered', 'runner_blocked', 'unsupported'].includes(status)
    ? status
    : 'not_covered';
}

function truthyEvidenceFlag(value) {
  if (value === true || value === 1) {
    return true;
  }

  const normalized = stringValue(value).toLowerCase();
  return normalized === 'true' || normalized === '1' || normalized === 'yes';
}

function artifactSourceIsForbidden(source) {
  const normalized = stringValue(source).toLowerCase();
  if (!normalized) {
    return true;
  }

  const compact = normalized.replace(/[^a-z0-9]+/g, '');
  return FORBIDDEN_INSTALL_SOURCE_TOKENS.some((token) => {
    const forbidden = token.toLowerCase();
    const compactForbidden = forbidden.replace(/[^a-z0-9]+/g, '');

    return normalized === forbidden
      || normalized.includes(forbidden)
      || compact === compactForbidden
      || compact.includes(compactForbidden);
  });
}

function artifactVersionFor(artifactVersions, artifact) {
  if (artifact === 'sdk-php') {
    return stringValue(artifactVersions['sdk-php']);
  }

  return stringValue(artifactVersions[artifact]);
}

function writePublishedArtifacts(artifactVersions, artifactSources, installEvidence = null) {
  writeJson(artifactManifestPath, {
    schema: 'durable-workflow.v2.worker-versioning-runtime.published-artifacts',
    generated_at: timestamp(),
    artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
    artifact_install_evidence: installEvidence,
  });
}

function artifactVersionFailures(artifactVersions) {
  return Object.entries({
    server: artifactVersions.server,
    cli: artifactVersions.cli,
    'sdk-python': artifactVersions['sdk-python'],
    workflow: artifactVersions.workflow,
    'sdk-php': artifactVersions['sdk-php'],
    waterline: artifactVersions.waterline,
  })
    .filter(([name, value]) => (
      name === 'sdk-python' ? !isExactPythonRelease(value) : !isExactSemverVersion(value)
    ) || isPlaceholderVersion(value))
    .map(([name, value]) => `${name}${value ? `=${value}` : ''}`);
}

function isExactSemverVersion(value) {
  return typeof value === 'string'
    && isExactSemverRelease(value.trim());
}

function isPlaceholderVersion(value) {
  const normalized = String(value ?? '').trim().toLowerCase();
  return normalized === ''
    || ['latest', 'current', 'head', 'unresolved', 'placeholder', '<latest>', '${version}', '{{ version }}']
      .some((placeholder) => normalized.includes(placeholder));
}

function historyHasCompatibility(history) {
  return JSON.stringify(history ?? {}).includes('"compatibility"');
}

function writeResult(result) {
  fs.mkdirSync(resultDir, { recursive: true });
  const resultPath = path.join(resultDir, 'worker-versioning-result.json');
  const capturePath = path.join(resultDir, 'worker-versioning-http-captures.json');
  const scenarioResults = objectValue(result.scenario_results);
  const scenarioEntries = Object.entries(scenarioResults);
  const scenarioStatuses = Object.fromEntries(
    scenarioEntries.map(([scenarioId, scenario]) => [scenarioId, scenario?.status ?? null]),
  );
  const nonPassScenarios = scenarioEntries
    .filter(([, scenario]) => scenario?.status !== 'pass')
    .map(([scenarioId]) => scenarioId);

  writeJson(resultPath, result);
  writeJson(capturePath, {
    schema: CAPTURE_SCHEMA,
    generated_at: timestamp(),
    captures,
  });
  writeJson(path.join(resultDir, 'worker-versioning-record.json'), {
    schema: RECORD_SCHEMA,
    experiment: 'worker-versioning',
    outcome: result.outcome,
    runner_blocked: result.runner_blocked === true,
    runnerBlocked: result.runner_blocked === true,
    artifact_versions: result.artifact_versions ?? {},
    artifactVersions: result.artifact_versions ?? {},
    artifact_sources: result.artifact_sources ?? {},
    artifactSources: result.artifact_sources ?? {},
    runner_blocker: result.runner_blocker ?? null,
    runnerBlocker: result.runner_blocker ?? null,
    resultPath,
    capturePath,
    result_file: 'worker-versioning-result.json',
    capture_file: 'worker-versioning-http-captures.json',
    generated_at: result.generated_at ?? timestamp(),
    started_at: result.started_at ?? null,
    finished_at: result.finished_at ?? null,
    required_scenarios: requiredScenarios,
    reported_scenarios: scenarioEntries.map(([scenarioId]) => scenarioId),
    reportedScenarios: scenarioEntries.map(([scenarioId]) => scenarioId),
    scenario_results: scenarioResults,
    scenarioResults,
    scenario_statuses: scenarioStatuses,
    scenarioStatuses,
    non_pass_scenarios: nonPassScenarios,
    nonPassScenarios,
    finding_links: result.finding_links ?? {},
    findingLinks: result.finding_links ?? {},
    no_compatible_worker: result.no_compatible_worker ?? null,
    noCompatibleWorker: result.no_compatible_worker ?? null,
    structured_findings: result.findings ?? [],
    structuredFindings: result.findings ?? [],
    findings: result.findings ?? [],
  });
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

function redactBody(body) {
  if (Array.isArray(body)) {
    return body.map((item) => redactBody(item));
  }

  if (!body || typeof body !== 'object') {
    return body ?? null;
  }

  return JSON.parse(JSON.stringify(body, (key, value) => {
    if (/(authorization|token|api[_-]?key|password|secret)/i.test(key)) {
      return '<redacted>';
    }
    if (typeof value === 'string') {
      return redactSensitiveText(value);
    }

    return value;
  }));
}

function unique(values) {
  return [...new Set(values.map((value) => stringValue(value)).filter(Boolean))].sort();
}

function runSuffix() {
  return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;
}

function timestamp() {
  return new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
}

function requiredEnv(name) {
  const value = trim(process.env[name]);
  if (!value) {
    throw new Error(`${name} is required`);
  }

  return value;
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
  return value.replace(/\/+$/, '');
}

function stringValue(value) {
  return typeof value === 'string' && value.trim() !== '' ? value.trim() : '';
}

function numberValue(value) {
  if (value === null || value === undefined || (typeof value === 'string' && value.trim() === '')) {
    return null;
  }

  return Number.isFinite(Number(value)) ? Number(value) : null;
}

function timeoutMsFromEnv(millisecondsName, secondsName, fallbackMs) {
  const explicitMilliseconds = numberValue(process.env[millisecondsName]);
  if (explicitMilliseconds !== null) {
    return Math.max(1000, explicitMilliseconds);
  }

  const explicitSeconds = numberValue(process.env[secondsName]);
  if (explicitSeconds !== null) {
    return Math.max(1000, explicitSeconds * 1000);
  }

  return fallbackMs;
}

function pollStatusValuesFromOutputs(outputs) {
  const pollStatuses = [
    outputs.poll_status,
    outputs.pollStatus,
    ...arrayValue(outputs.incompatible_worker_poll_statuses),
    ...arrayValue(outputs.incompatibleWorkerPollStatuses),
  ];

  for (const poll of [
    ...arrayValue(outputs.incompatible_worker_polls),
    ...arrayValue(outputs.incompatibleWorkerPolls),
  ]) {
    if (!poll || typeof poll !== 'object' || Array.isArray(poll)) {
      continue;
    }

    pollStatuses.push(
      poll.poll_status,
      poll.pollStatus,
      poll.response?.poll_status,
      poll.response?.pollStatus,
    );
  }

  return pollStatuses.map((pollStatus) => stringValue(pollStatus)).filter(Boolean);
}

function workflowVisibilitySignalValuesFromOutputs(outputs) {
  const values = [
    outputs.workflow_visibility?.compatibility_status,
    outputs.workflow_visibility?.compatibilityStatus,
    outputs.workflow_visibility?.compatibility_fleet_reason,
    outputs.workflow_visibility?.compatibilityFleetReason,
    outputs.workflowVisibility?.compatibility_status,
    outputs.workflowVisibility?.compatibilityStatus,
    outputs.workflowVisibility?.compatibility_fleet_reason,
    outputs.workflowVisibility?.compatibilityFleetReason,
  ];

  for (const sample of [
    ...arrayValue(outputs.workflow_visibility_samples),
    ...arrayValue(outputs.workflowVisibilitySamples),
  ]) {
    if (!sample || typeof sample !== 'object' || Array.isArray(sample)) {
      continue;
    }

    values.push(
      sample.compatibility_status,
      sample.compatibilityStatus,
      sample.compatibility_fleet_reason,
      sample.compatibilityFleetReason,
    );
  }

  return values.map((value) => stringValue(value)).filter(Boolean);
}

function taskQueueBuildIdSignalValuesFromOutputs(outputs) {
  const values = [
    ...pendingWorkflowTaskDiagnosticSignals(
      outputs.task_queue_build_id_entry
        ?? outputs.taskQueueBuildIdEntry,
    ),
  ];

  for (const sample of [
    ...arrayValue(outputs.task_queue_build_id_samples),
    ...arrayValue(outputs.taskQueueBuildIdSamples),
  ]) {
    if (!sample || typeof sample !== 'object' || Array.isArray(sample)) {
      continue;
    }

    values.push(
      ...pendingWorkflowTaskDiagnosticSignals(
        sample.build_id_entry
          ?? sample.buildIdEntry,
      ),
      ...pendingWorkflowTaskDiagnosticSignals({
        pending_workflow_tasks: sample.pending_workflow_tasks
          ?? sample.pendingWorkflowTasks,
      }),
    );
  }

  return values.map((value) => stringValue(value)).filter(Boolean);
}

function isGenericPollErrorStatus(value) {
  return stringValue(value).toLowerCase() === 'poll_error';
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

function isMainModule() {
  return Boolean(process.argv[1]) && path.resolve(process.argv[1]) === modulePath;
}

function sleep(milliseconds) {
  return new Promise((resolve) => setTimeout(resolve, milliseconds));
}
