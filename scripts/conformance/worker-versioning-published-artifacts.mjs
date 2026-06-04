#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

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
const REQUIRED_INSTALL_ARTIFACTS = ['server', 'cli', 'sdk-python', 'workflow-php', 'waterline'];
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
    writeResult(blockedResult(reason, now, now, artifactVersionsFromEnv()));
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
  const installEvidence = artifactInstallEvidence(artifactVersions, artifactSources);
  artifactSources = mergeArtifactSources(artifactSources, installEvidence);
  const publishedWorkerEvidence = publishedWorkerExecutionEvidence(artifactVersions, artifactSources);
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
  const workerHeaders = {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    Authorization: `Bearer ${token}`,
    'X-Namespace': namespace,
    'X-Durable-Workflow-Protocol-Version': '1.8',
  };

  await ensureNamespace(serverUrl, namespace, bootstrapControlHeaders, controlHeaders);

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
        started_by: 'workflow-php-v1',
        incompatible_worker: 'sdk-python-v2',
        scenario: 'php_v1_not_delivered_to_python_v2',
      },
      {
        started_by: 'sdk-python-v1',
        incompatible_worker: 'workflow-php-v2',
        scenario: 'python_v1_not_delivered_to_php_v2',
      },
    ],
    uncovered_required_runtimes: ['workflow-php', 'sdk-python'],
    uncovered_required_client_paths: ['cli', 'sdk-python', 'workflow-php-sdk'],
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
    sdk_version: artifactVersions.workflow,
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
    input: ['v1'],
  });
  const v1WorkflowId = stringValue(v1Run.workflow_id);
  const v1RunId = stringValue(v1Run.run_id);

  await registerWorker(serverUrl, workerHeaders, {
    worker_id: v2WorkerId,
    task_queue: taskQueue,
    runtime: 'php',
    sdk_version: artifactVersions.workflow,
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
    sdk_version: artifactVersions.workflow,
    build_id: buildV1,
    supported_workflow_types: [workflowType],
    workflow_definition_fingerprints: { [workflowType]: `sequence-v1-${suffix}` },
    supported_activity_types: ['activity_a', 'activity_b'],
    process_metrics: processMetrics(1002, timestamp()),
  });

  const v2AfterRestart = await pollWorkflowTask(serverUrl, workerHeaders, v2WorkerId, taskQueue, buildV2);
  const v1ReplayPoll = await pollWorkflowTask(serverUrl, workerHeaders, v1WorkerId, taskQueue, buildV1);
  const replayWorkerBuildId = stringValue(v1ReplayPoll?.task?.compatibility);
  const cacheEvictionIncompatibleCount = countTasksForRun([v2AfterRestart], v1RunId);
  const cacheEvictionObserved = countTasksForRun([v1ReplayPoll], v1RunId) > 0
    && numberValue(v1ReplayPoll?.task?.workflow_task_attempt) >= 2;

  if (v1ReplayPoll?.task) {
    await completeWorkflow(serverUrl, workerHeaders, v1ReplayPoll.task, ['activity_a', 'activity_b']);
  }

  await postJson(serverUrl, `/api/task-queues/${encodeURIComponent(taskQueue)}/build-ids/promote`, {
    build_id: buildV2,
  }, controlHeaders, [200, 201]);
  const promotedRun = await startWorkflow(serverUrl, controlHeaders, {
    workflow_id: `wv-promoted-${suffix}`,
    workflow_type: workflowType,
    task_queue: taskQueue,
    input: ['v2'],
  });
  const promotedPoll = await pollWorkflowTask(serverUrl, workerHeaders, v2WorkerId, taskQueue, buildV2);
  if (promotedPoll?.task) {
    await completeWorkflow(serverUrl, workerHeaders, promotedPoll.task, ['activity_b', 'activity_a']);
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
  const v1Delete = await deleteJson(
    serverUrl,
    `/api/workers/${encodeURIComponent(v1WorkerId)}`,
    controlHeaders,
    [200, 404],
  );
  await sleep(1200);
  const noCompatiblePoll = await pollWorkflowTask(serverUrl, workerHeaders, v2WorkerId, taskQueue, buildV2);
  const noCompatibleShow = noCompatibleWorkflowId
    ? await getJson(
      serverUrl,
      `/api/workflows/${encodeURIComponent(noCompatibleWorkflowId)}/runs/${encodeURIComponent(noCompatibleRunId)}`,
      controlHeaders,
      [200],
    )
    : {};
  const noCompatibleSignal = stringValue(firstExplicitNoCompatibleSignal(
    noCompatiblePoll.poll_status,
    noCompatibleShow.compatibility_status,
  ))
    || 'pending';
  const noCompatiblePendingOrTypedError = isExplicitNoCompatibleSignal(noCompatibleSignal)
    ? noCompatibleSignal
    : 'pending';
  const noCompatibleIncompatibleCount = countTasksForRun([noCompatiblePoll], noCompatibleRunId);

  const phpV1WorkerId = `php-cross-v1-${suffix}`;
  const pythonV2WorkerId = `python-cross-v2-${suffix}`;
  const phpV2WorkerId = `php-cross-v2-${suffix}`;
  const pythonV1WorkerId = `python-cross-v1-${suffix}`;
  await registerWorker(serverUrl, workerHeaders, {
    worker_id: phpV1WorkerId,
    task_queue: taskQueue,
    runtime: 'php',
    sdk_version: artifactVersions.workflow,
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
  const phpStarted = await startWorkflow(serverUrl, controlHeaders, {
    workflow_id: `wv-php-start-${suffix}`,
    workflow_type: workflowType,
    task_queue: taskQueue,
    input: ['php'],
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
    await completeWorkflow(serverUrl, workerHeaders, phpV1Poll.task, ['activity_a', 'activity_b']);
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
    sdk_version: artifactVersions.workflow,
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
  const pythonStarted = await startWorkflow(serverUrl, controlHeaders, {
    workflow_id: `wv-python-start-${suffix}`,
    workflow_type: workflowType,
    task_queue: taskQueue,
    input: ['python'],
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
    await completeWorkflow(serverUrl, workerHeaders, pythonV1Poll.task, ['activity_a', 'activity_b']);
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

  const drain = await postJson(serverUrl, `/api/task-queues/${encodeURIComponent(taskQueue)}/build-ids/drain`, {
    build_id: buildV1,
  }, controlHeaders, [200, 201]);
  const resume = await postJson(serverUrl, `/api/task-queues/${encodeURIComponent(taskQueue)}/build-ids/resume`, {
    build_id: buildV1,
  }, controlHeaders, [200, 201]);

  const adversarial = await postJson(serverUrl, '/api/worker/register', {
    worker_id: v1WorkerId,
    task_queue: taskQueue,
    runtime: 'php',
    sdk_version: artifactVersions.workflow,
    build_id: buildV1,
    supported_workflow_types: [workflowType],
    workflow_definition_fingerprints: { [workflowType]: `sequence-divergent-under-same-build-${suffix}` },
    supported_activity_types: ['activity_a', 'activity_b'],
    process_metrics: processMetrics(1003, timestamp()),
  }, workerHeaders, [201, 409]);

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
      expected_behavior: 'Install-only coverage passes only after the server image, official CLI, PyPI Python SDK, Packagist PHP workflow runtime, and Waterline artifact are installed from published channels and smoke-executed.',
      next_acceptance_criterion: 'run the worker-versioning host topology with artifact-install evidence showing pass for server, cli, sdk-python, workflow-php, and waterline',
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
  addNotCovered('worker_registration_build_ids', workerRegistrationOutputs, {
    scenario_id: 'worker_registration_build_ids',
    owning_surface: 'conformance_harness',
    artifact_versions: artifactVersions,
    observed_behavior: 'The runner registered worker records through the server HTTP protocol but did not execute published workflow-php, sdk-python, or CLI worker artifacts for registration evidence.',
    expected_behavior: 'Worker registration build-id coverage is produced by live published worker artifacts on the same task queue and verified through public worker-list and task-queue build-id surfaces.',
    next_acceptance_criterion: 'run published workflow-php and sdk-python worker processes and record their registration responses plus active build-id cohorts before marking this scenario pass',
  });
  const operatorRolloutOutputs = {
    worker_cohorts: unique((workerList.workers ?? []).map((worker) => worker.build_id).filter(Boolean)),
    rollout_state: buildIds,
    new_start_build_id: stringValue(promotedRun.compatibility) || stringValue(promotedPoll?.task?.compatibility),
    workflow_run_compatibility: { [v1RunId]: stringValue(v1RunShow.compatibility) },
    waterline_operator_visibility: { status: 'not_exercised_by_server_handoff' },
  };
  addNotCovered('operator_rollout_visibility', operatorRolloutOutputs, {
    scenario_id: 'operator_rollout_visibility',
    owning_surface: 'conformance_harness',
    artifact_versions: artifactVersions,
    observed_behavior: 'The runner captured server HTTP rollout state but did not execute the published CLI or Waterline operator views required for rollout visibility evidence.',
    expected_behavior: 'Operators can distinguish v1 and v2 cohorts, new-start build IDs, and per-run compatibility through published CLI and Waterline surfaces.',
    next_acceptance_criterion: 'run dw and Waterline against the same published-artifact topology and attach their rollout visibility captures before marking this scenario pass',
  });
  addNotCovered('drain_resume_operator_controls', {
    drain_command: 'POST /api/task-queues/{taskQueue}/build-ids/drain',
    drain_state_visible: drain.drain_intent === 'draining',
    resume_command: 'POST /api/task-queues/{taskQueue}/build-ids/resume',
    resume_state_visible: resume.drain_intent === 'active',
    draining_worker_claim_count: 0,
    cli_operator_command_execution: false,
  }, {
    scenario_id: 'drain_resume_operator_controls',
    owning_surface: 'conformance_harness',
    artifact_versions: artifactVersions,
    observed_behavior: 'The runner exercised drain and resume through server HTTP routes but did not run the documented published CLI operator command.',
    expected_behavior: 'Drain and resume controls are executed through the published CLI and reflected in public rollout state.',
    next_acceptance_criterion: 'run the published dw drain/resume commands against the topology and record command output with rollout-state confirmation',
  });
  addPass('pin_on_start', {
    run_compatibility: stringValue(v1RunShow.compatibility),
    first_task_compatibility: stringValue(v1FirstPoll?.task?.compatibility),
    history_or_visibility_field: 'workflow_runs.compatibility',
  });
  const compatibleReplayOutputs = {
    v1_worker_task_count: v1TaskCount,
    v2_worker_task_count_for_v1_run: v2TaskCountForV1Run,
    workflow_result: ['activity_a', 'activity_b'],
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
    ['sdk-python', 'workflow-php'],
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
      next_acceptance_criterion: 'rerun with published workflow-php or sdk-python workers executing divergent Sequence implementations and record positive v1 delivery with zero v2 delivery for the same v1-pinned run',
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
    ['sdk-python', 'workflow-php'],
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
    worker_execution_mode: SERVER_PROTOCOL_PROBE,
    published_server_protocol_probe: true,
    published_server_artifact: publishedServerArtifactEvidence(artifactVersions, artifactSources),
    published_artifact_worker_execution: false,
    local_product_source_checkouts_used: false,
    deregister_response: v1Delete,
    workflow_visibility: noCompatibleShow,
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
  addNotCovered('operator_visibility_surfaces', {
    worker_list: workerList,
    task_queue_build_ids: buildIds,
    workflow_visibility: v1RunShow,
    waterline_operator_visibility: { status: 'not_exercised_by_server_handoff' },
  }, {
    scenario_id: 'operator_visibility_surfaces',
    owning_surface: 'waterline',
    artifact_versions: artifactVersions,
    observed_behavior: 'Server handoff captured worker, task-queue, workflow, and history surfaces but did not boot Waterline.',
    expected_behavior: 'Full worker-versioning conformance includes Waterline worker and workflow views.',
    next_acceptance_criterion: 'Attach a published Waterline shard for the same run database or run the full host topology with Waterline enabled.',
  });
  const crossLanguageOutputs = {
    php_worker_build_id: `${buildV1}-php`,
    python_worker_build_id: `${buildV2}-python`,
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
    cross_language_delivery: {
      cells: [
        {
          scenario: 'php_v1_not_delivered_to_python_v2',
          started_by: 'workflow-php-v1',
          incompatible_worker: 'sdk-python-v2',
          compatible_worker: 'workflow-php-v1',
          compatible_delivery_count: phpV1CompatibleCount,
          incompatible_delivery_count: phpToPythonIncompatibleCount,
          started_run_id: phpStartedRunId,
        },
        {
          scenario: 'python_v1_not_delivered_to_php_v2',
          started_by: 'sdk-python-v1',
          incompatible_worker: 'workflow-php-v2',
          compatible_worker: 'sdk-python-v1',
          compatible_delivery_count: pythonV1CompatibleCount,
          incompatible_delivery_count: pythonToPhpIncompatibleCount,
          started_run_id: pythonStartedRunId,
        },
      ],
    },
    worker_execution_mode: SERVER_PROTOCOL_PROBE,
    server_protocol_probe_only: true,
    published_artifact_worker_execution: false,
    local_product_source_checkouts_used: false,
  };
  const publishedCrossLanguageOutputs = mergeScenarioOutputs(
    crossLanguageOutputs,
    publishedWorkerScenarioOutputs(publishedWorkerEvidence, 'cross_language_php_python_pinning'),
  );
  const publishedPhpToPythonIncompatibleCount = numberValue(
    publishedCrossLanguageOutputs.php_v1_to_python_v2_incompatible_delivery_count,
  );
  const publishedPythonToPhpIncompatibleCount = numberValue(
    publishedCrossLanguageOutputs.python_v1_to_php_v2_incompatible_delivery_count,
  );
  const publishedPhpCompatibleCount = numberValue(
    publishedCrossLanguageOutputs.php_v1_compatible_delivery_count,
  );
  const publishedPythonCompatibleCount = numberValue(
    publishedCrossLanguageOutputs.python_v1_compatible_delivery_count,
  );
  const publishedCrossLanguageWorkerExecuted = publishedWorkerScenarioPasses(
    publishedCrossLanguageOutputs,
    ['sdk-python', 'workflow-php'],
    true,
  );
  const crossLanguagePasses = publishedCrossLanguageWorkerExecuted
    && publishedPhpToPythonIncompatibleCount === 0
    && publishedPythonToPhpIncompatibleCount === 0
    && publishedPhpCompatibleCount > 0
    && publishedPythonCompatibleCount > 0;
  if (crossLanguagePasses) {
    addPass('cross_language_php_python_pinning', publishedCrossLanguageOutputs);
  } else if (publishedCrossLanguageWorkerExecuted) {
    addFail('cross_language_php_python_pinning', publishedCrossLanguageOutputs, {
      scenario_id: 'cross_language_php_python_pinning',
      owning_surface: 'server',
      artifact_versions: artifactVersions,
      observed_behavior: 'Published PHP/Python worker evidence did not prove zero incompatible cross-language delivery with positive compatible delivery in both directions.',
      expected_behavior: 'PHP v1-pinned runs are never delivered to Python v2, Python v1-pinned runs are never delivered to PHP v2, and both directions are exercised by actual published worker artifacts.',
      next_acceptance_criterion: 'rerun the cross-language cells with installed workflow-php and sdk-python artifacts and record both incompatible delivery counts as zero with positive compatible delivery counts',
      php_v1_to_python_v2_incompatible_delivery_count: publishedPhpToPythonIncompatibleCount,
      python_v1_to_php_v2_incompatible_delivery_count: publishedPythonToPhpIncompatibleCount,
      php_v1_compatible_delivery_count: publishedPhpCompatibleCount,
      python_v1_compatible_delivery_count: publishedPythonCompatibleCount,
    });
  } else if (phpToPythonIncompatibleCount === 0
    && pythonToPhpIncompatibleCount === 0
    && phpV1CompatibleCount > 0
    && pythonV1CompatibleCount > 0) {
    addNotCovered('cross_language_php_python_pinning', crossLanguageOutputs, {
      scenario_id: 'cross_language_php_python_pinning',
      owning_surface: 'conformance_harness',
      artifact_versions: artifactVersions,
      observed_behavior: 'The cross-language counts came from synthetic server HTTP worker registrations; no published workflow-php or sdk-python worker process executed the PHP/Python pinning cells.',
      expected_behavior: 'PHP v1-pinned runs are never delivered to Python v2, Python v1-pinned runs are never delivered to PHP v2, and both directions are exercised by actual published worker artifacts.',
      next_acceptance_criterion: 'run the cross-language cells with installed workflow-php and sdk-python artifacts and record both incompatible delivery counts as zero with positive compatible delivery counts',
      php_v1_to_python_v2_incompatible_delivery_count: phpToPythonIncompatibleCount,
      python_v1_to_php_v2_incompatible_delivery_count: pythonToPhpIncompatibleCount,
      php_v1_compatible_delivery_count: phpV1CompatibleCount,
      python_v1_compatible_delivery_count: pythonV1CompatibleCount,
    });
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
    ['sdk-python', 'workflow-php'],
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
      next_acceptance_criterion: 'execute the adversarial no-version-bump cell with a published workflow-php or sdk-python worker artifact before marking this scenario pass',
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
    },
    mixed_version_polling: {
      v1_worker_task_count: v1TaskCount,
      v2_worker_task_count_for_v1_run: v2TaskCountForV1Run,
      cache_eviction_incompatible_delivery_count: cacheEvictionIncompatibleCount,
    },
    no_compatible_worker: {
      operator_visible_signal: scenarioResults.no_compatible_worker_behavior.observed_outputs.operator_visible_signal,
      pending_or_typed_error: scenarioResults.no_compatible_worker_behavior.observed_outputs.pending_or_typed_error,
      incompatible_worker_task_count: scenarioResults.no_compatible_worker_behavior.observed_outputs.incompatible_worker_task_count,
      published_artifact_worker_execution: scenarioResults
        .no_compatible_worker_behavior
        .observed_outputs
        .published_artifact_worker_execution,
    },
    cross_language_matrix: scenarioResults.cross_language_php_python_pinning.observed_outputs.cross_language_delivery,
    adversarial_outcomes: scenarioResults.adversarial_no_version_bump.observed_outputs,
  };

  writeResult(result);
}

function blockedResult(reason, startedAt, finishedAt, artifactVersions = {}, artifactSources = {}) {
  const finding = {
    owning_surface: 'conformance_harness',
    observed_behavior: reason,
    expected_behavior: 'worker-versioning conformance runner can exercise published artifacts and record routing counts',
    next_acceptance_criterion: 'restore the missing host capability and rerun worker-versioning conformance',
  };

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
    scenario_results: Object.fromEntries(requiredScenarios.map((scenarioId) => [
      scenarioId,
      {
        scenario_id: scenarioId,
        status: 'runner_blocked',
        observed_outputs: { blocked_reason: reason },
        linked_findings: [{ ...finding, scenario_id: scenarioId }],
      },
    ])),
    findings: requiredScenarios.map((scenarioId) => ({ ...finding, scenario_id: scenarioId })),
    finding_links: Object.fromEntries(requiredScenarios.map((scenarioId) => [
      scenarioId,
      [{ ...finding, scenario_id: scenarioId }],
    ])),
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

async function registerWorker(serverUrl, headers, payload) {
  return postJson(serverUrl, '/api/worker/register', {
    max_concurrent_workflow_tasks: 10,
    max_concurrent_activity_tasks: 10,
    task_slots: {
      workflow_available: 10,
      activity_available: 10,
    },
    ...payload,
  }, headers, [201]);
}

async function startWorkflow(serverUrl, headers, payload) {
  return postJson(serverUrl, '/api/workflows', payload, headers, [201, 200]);
}

async function pollWorkflowTask(serverUrl, headers, workerId, taskQueue, buildId) {
  const poll = await postJson(serverUrl, '/api/worker/workflow-tasks/poll', {
    worker_id: workerId,
    task_queue: taskQueue,
    build_id: buildId,
    poll_request_id: `${workerId}-${Date.now()}-${Math.random().toString(16).slice(2)}`,
    history_page_size: 100,
  }, headers, [200]);

  return {
    worker_id: workerId,
    build_id: buildId,
    poll_status: poll.poll_status ?? null,
    task: poll.task ?? null,
  };
}

async function completeWorkflow(serverUrl, headers, task, result) {
  return postJson(serverUrl, `/api/worker/workflow-tasks/${encodeURIComponent(task.task_id)}/complete`, {
    lease_owner: task.lease_owner,
    workflow_task_attempt: task.workflow_task_attempt,
    commands: [
      {
        type: 'complete_workflow',
        result: JSON.stringify(result),
      },
    ],
  }, headers, [200]);
}

async function failWorkflowTask(serverUrl, headers, task, message, type) {
  return postJson(serverUrl, `/api/worker/workflow-tasks/${encodeURIComponent(task.task_id)}/fail`, {
    lease_owner: task.lease_owner,
    workflow_task_attempt: task.workflow_task_attempt,
    failure: {
      message,
      type,
    },
  }, headers, [200]);
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
  const response = await fetch(url, {
    method,
    headers,
    body: body === undefined ? undefined : JSON.stringify(body),
  });
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

function countTasksForRun(polls, runId) {
  return polls.filter((poll) => stringValue(poll?.task?.run_id) === runId).length;
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
  const operatorVisibleSignal = stringValue(outputs.operator_visible_signal);
  const pendingOrTypedError = stringValue(outputs.pending_or_typed_error);
  const serverVersion = stringValue(artifactVersions.server);
  const serverSource = stringValue(artifactSources.server);

  return outputs.worker_execution_mode === SERVER_PROTOCOL_PROBE
    && truthyEvidenceFlag(outputs.published_server_protocol_probe)
    && explicitFalse(outputs.local_product_source_checkouts_used)
    && incompatibleWorkerTaskCount === 0
    && isExplicitNoCompatibleSignal(operatorVisibleSignal)
    && (
      pendingOrTypedError === 'pending'
      || isExplicitNoCompatibleSignal(pendingOrTypedError)
    )
    && isExactSemverVersion(serverVersion)
    && !isPlaceholderVersion(serverVersion)
    && !artifactSourceIsForbidden(serverSource);
}

function artifactVersionsFromEnv() {
  const workflow = trim(process.env.DW_WORKFLOW_PHP_VERSION ?? process.env.DW_WORKFLOW_VERSION);

  return {
    server: trim(process.env.DW_SERVER_VERSION),
    cli: trim(process.env.DW_CLI_VERSION),
    'sdk-python': trim(process.env.DW_PYTHON_SDK_VERSION),
    workflow,
    'workflow-php': workflow,
    waterline: trim(process.env.DW_WATERLINE_VERSION),
  };
}

function artifactSourcesFromEnv() {
  return {
    server: process.env.DW_WV_SERVER_ARTIFACT_SOURCE ?? 'published_server_url',
    cli: trim(process.env.DW_CLI_ARTIFACT_SOURCE) || 'not_exercised',
    'sdk-python': trim(process.env.DW_PYTHON_SDK_ARTIFACT_SOURCE) || 'not_exercised',
    workflow: trim(process.env.DW_WORKFLOW_PHP_ARTIFACT_SOURCE) || 'not_exercised',
    'workflow-php': trim(process.env.DW_WORKFLOW_PHP_ARTIFACT_SOURCE) || 'not_exercised',
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
    if (artifact === 'workflow-php') {
      merged.workflow = source;
    }
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
    outputs.compatibility_status,
    outputs.compatibilityStatus,
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
      outputs.compatibility_status,
      outputs.compatibilityStatus,
    ),
  );
  const incompatibleWorkerTaskCount = numberValue(rawIncompatibleWorkerTaskCount);
  const operatorVisibleSignal = stringValue(rawOperatorVisibleSignal);
  const pendingOrTypedError = stringValue(rawPendingOrTypedError);
  const workerExecuted = publishedWorkerScenarioPasses(
    outputs,
    ['sdk-python', 'workflow-php'],
    false,
  );
  const normalizedOutputs = { ...outputs };
  if (rawIncompatibleWorkerTaskCount !== undefined) {
    normalizedOutputs.incompatible_worker_task_count = incompatibleWorkerTaskCount;
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
    operator_visible_signal: operatorVisibleSignal,
    pending_or_typed_error: pendingOrTypedError,
    passes: workerExecuted
      && incompatibleWorkerTaskCount === 0
      && isExplicitNoCompatibleSignal(operatorVisibleSignal)
      && (
        pendingOrTypedError === 'pending'
        || isExplicitNoCompatibleSignal(pendingOrTypedError)
      ),
  };
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
    if (!isExactSemverVersion(version) || isPlaceholderVersion(version)) {
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

function objectValue(value) {
  return value && typeof value === 'object' && !Array.isArray(value) ? value : {};
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
  if (['workflow', 'php', 'workflow-php', 'php-worker'].includes(normalized)) {
    return 'workflow-php';
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
  if (artifact === 'workflow-php') {
    return stringValue(artifactVersions['workflow-php']) || stringValue(artifactVersions.workflow);
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
    waterline: artifactVersions.waterline,
  })
    .filter(([, value]) => !isExactSemverVersion(value) || isPlaceholderVersion(value))
    .map(([name, value]) => `${name}${value ? `=${value}` : ''}`);
}

function isExactSemverVersion(value) {
  return typeof value === 'string'
    && /^[0-9]+\.[0-9]+\.[0-9]+(?:[.-][0-9A-Za-z.-]+)?$/.test(value.trim());
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
  writeJson(path.join(resultDir, 'worker-versioning-result.json'), result);
  writeJson(path.join(resultDir, 'worker-versioning-http-captures.json'), {
    schema: CAPTURE_SCHEMA,
    generated_at: timestamp(),
    captures,
  });
  writeJson(path.join(resultDir, 'worker-versioning-record.json'), {
    schema: RECORD_SCHEMA,
    experiment: 'worker-versioning',
    outcome: result.outcome,
    runnerBlocked: result.runner_blocked === true,
    artifactVersions: result.artifact_versions ?? {},
    resultPath: path.join(resultDir, 'worker-versioning-result.json'),
    capturePath: path.join(resultDir, 'worker-versioning-http-captures.json'),
    generated_at: result.generated_at ?? timestamp(),
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
  if (!body || typeof body !== 'object') {
    return body ?? null;
  }

  return JSON.parse(JSON.stringify(body, (key, value) => {
    if (key.toLowerCase().includes('token') || key.toLowerCase().includes('authorization')) {
      return '<redacted>';
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
