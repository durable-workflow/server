#!/usr/bin/env node
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import process from 'node:process';
import { spawn } from 'node:child_process';

const RESULT_SCHEMA = 'durable-workflow.v2.skew-refusal-matrix.result';
const METADATA_SCHEMA = 'durable-workflow.v2.skew-refusal-matrix.metadata';
const RECORD_SCHEMA = 'durable-workflow.v2.skew-refusal-matrix.record';
const CAPTURE_SCHEMA = 'durable-workflow.v2.skew-refusal-matrix.request-response-captures';

const repoRoot = process.env.DW_SKEW_REPO_ROOT
  ?? path.resolve(path.dirname(new URL(import.meta.url).pathname), '../..');
const resultDir = process.env.DW_SKEW_RESULT_DIR
  ?? process.env.DW_SKEW_RUN_ROOT
  ?? process.cwd();
const runRoot = process.env.DW_SKEW_RUN_ROOT ?? resultDir;
const scenarioManifestPath = process.env.DW_SKEW_SCENARIO_MANIFEST
  ?? path.join(repoRoot, 'static/platform-conformance/skew-refusal-matrix-scenarios.json');

const scenarioManifest = readJsonIfExists(scenarioManifestPath) ?? {};
const artifactManifestPath = process.env.DW_SKEW_ARTIFACTS_JSON
  ?? path.join(runRoot, 'published-artifacts.json');
const artifactManifest = readJsonIfExists(artifactManifestPath) ?? {};
const suiteVersion = Number.isInteger(scenarioManifest.suite_version)
  ? scenarioManifest.suite_version
  : null;
const requiredScenarios = Array.isArray(scenarioManifest.scenarios)
  ? scenarioManifest.scenarios.map((scenario) => scenario.id).filter(Boolean)
  : [
      'published_artifact_install_only',
      'cli_version_pair_matrix',
      'sdk_python_version_pair_matrix',
      'workflow_worker_version_pair_matrix',
      'waterline_version_pair_matrix',
      'future_version_boundary_matrix',
      'request_response_capture_for_skewed_operations',
      'focused_finding_routing',
    ];

const operationGroups = {
  cluster_info_probe: {
    requests: ['GET /api/cluster/info'],
    evidence: [
      'request',
      'status',
      'status_code',
      'response_body',
      'client_or_observer_version',
      'server_version',
      'protocol_manifest_versions',
    ],
  },
  workflow_control_plane: {
    requests: [
      'POST /api/workflows',
      'GET /api/workflows/{workflowId}',
      'GET /api/workflows/{workflowId}/runs',
      'GET /api/workflows/{workflowId}/runs/{runId}',
      'GET /api/workflows/{workflowId}/runs/{runId}/history',
      'POST /api/workflows/{workflowId}/signal/{signalName}',
      'POST /api/workflows/{workflowId}/query/{queryName}',
      'POST /api/workflows/{workflowId}/update/{updateName}',
      'POST /api/workflows/{workflowId}/runs/{runId}/signal/{signalName}',
      'POST /api/workflows/{workflowId}/runs/{runId}/query/{queryName}',
      'POST /api/workflows/{workflowId}/runs/{runId}/update/{updateName}',
      'POST /api/workflows/{workflowId}/cancel',
      'POST /api/workflows/{workflowId}/terminate',
    ],
  },
  worker_lifecycle: {
    requests: [
      'POST /api/worker/register',
      'POST /api/worker/heartbeat',
      'POST /api/worker/workflow-tasks/poll',
      'POST /api/worker/workflow-tasks/{task}/complete',
      'POST /api/worker/workflow-tasks/{task}/fail',
    ],
  },
  schedule_control_plane: {
    requests: [
      'POST /api/schedules',
      'GET /api/schedules/{id}',
      'POST /api/schedules/{id}/trigger',
    ],
  },
  waterline_render: {
    requests: [
      'GET /waterline/api/v2/health',
      'GET /waterline/api/flows/running',
      'GET /waterline/api/flows/{id}',
    ],
  },
};

const refusalRequirements = {
  cli: [
    'names_client_version',
    'names_server_version',
    'names_protocol_or_manifest',
    'explains_compatibility_window',
    'suggests_upgrade_or_pin_next_step',
    'uses_documented_exit_code',
  ],
  'sdk-python': [
    'raises_typed_or_documented_exception',
    'names_client_version',
    'names_server_version',
    'names_protocol_or_manifest',
    'explains_compatibility_window',
    'suggests_upgrade_or_pin_next_step',
  ],
  'workflow-worker': [
    'register_refused_or_register_and_serve_only',
    'names_worker_version',
    'names_server_version',
    'names_worker_protocol_version',
    'explains_compatibility_window',
    'suggests_upgrade_or_pin_next_step',
  ],
  waterline: [
    'banner_or_render_refused',
    'names_waterline_version',
    'names_server_version',
    'explains_compatibility_window',
    'suggests_upgrade_or_pin_next_step',
  ],
};

const surfaces = {
  cli: {
    artifact: 'cli',
    component: 'CLI',
    versionEnv: 'DW_CLI_VERSION',
    versionField: 'cli_version',
    operationGroups: ['cluster_info_probe', 'workflow_control_plane', 'schedule_control_plane'],
    protocolKind: 'control-plane',
  },
  'sdk-python': {
    artifact: 'sdk-python',
    component: 'Python SDK',
    versionEnv: 'DW_PYTHON_SDK_VERSION',
    versionField: 'sdk_python_version',
    operationGroups: ['cluster_info_probe', 'workflow_control_plane', 'worker_lifecycle', 'schedule_control_plane'],
    protocolKind: 'control-plane-and-worker',
  },
  'workflow-worker': {
    artifact: 'workflow',
    component: 'PHP workflow worker',
    versionEnv: 'DW_WORKFLOW_PHP_VERSION',
    alternateVersionEnv: 'DW_WORKFLOW_VERSION',
    versionField: 'workflow_version',
    operationGroups: ['cluster_info_probe', 'worker_lifecycle'],
    protocolKind: 'worker',
  },
  waterline: {
    artifact: 'waterline',
    component: 'Waterline',
    versionEnv: 'DW_WATERLINE_VERSION',
    versionField: 'waterline_version',
    operationGroups: ['cluster_info_probe', 'waterline_render'],
    protocolKind: 'observer',
  },
};

const pairingClasses = {
  compatible: {
    controlPlaneVersion: '2',
    workerProtocolVersion: '1.8',
    expected: 'inside-window interop',
    compatibilityWindow: 'control-plane version 2; worker protocol same-major <= 1.8',
  },
  backward_skew: {
    controlPlaneVersion: '1',
    workerProtocolVersion: '1.7',
    expected: 'inside-window interop or loud refusal before unsupported shape',
    compatibilityWindow: 'server supports control-plane 2 and worker protocol 1.x minors <= 1.8',
  },
  forward_skew: {
    controlPlaneVersion: '3',
    workerProtocolVersion: '1.9',
    expected: 'inside-window interop or loud refusal before unsupported shape',
    compatibilityWindow: 'server supports control-plane 2 and worker protocol 1.x minors <= 1.8',
  },
  outside_window: {
    controlPlaneVersion: '999',
    workerProtocolVersion: '2.0',
    expected: 'loud refusal before mutation, registration, dropped work, or stale render',
    compatibilityWindow: 'outside supported control-plane 2 and worker protocol same-major <= 1.8 window',
  },
};

const workflowWorkerDependentRequests = new Set([
  'POST /api/workflows/{workflowId}/query/{queryName}',
  'POST /api/workflows/{workflowId}/update/{updateName}',
  'POST /api/workflows/{workflowId}/runs/{runId}/query/{queryName}',
  'POST /api/workflows/{workflowId}/runs/{runId}/update/{updateName}',
]);

main().catch((error) => {
  const now = timestamp();
  const reason = error instanceof Error ? error.message : String(error);
  writeBlockedResult(reason, now, now);
  process.exitCode = 0;
});

async function main() {
  fs.mkdirSync(resultDir, { recursive: true });

  const startedAt = process.env.DW_SKEW_STARTED_AT ?? timestamp();
  const blockedReason = process.env.DW_SKEW_BLOCKED_REASON;
  if (blockedReason) {
    writeBlockedResult(blockedReason, startedAt, timestamp());
    return;
  }

  const serverUrl = trimTrailingSlash(requiredEnv('DW_SKEW_SERVER_URL'));
  const artifactVersions = artifactVersionsFromEnv();
  const missingVersions = Object.entries(artifactVersions)
    .filter(([, value]) => !value || isPlaceholderVersion(value))
    .map(([name]) => name);

  if (missingVersions.length > 0) {
    writeBlockedResult(
      `skew conformance requires concrete published artifact versions for: ${missingVersions.join(', ')}`,
      startedAt,
      timestamp(),
      artifactVersions,
    );
    return;
  }

  const inexactVersions = Object.entries(artifactVersions)
    .filter(([, value]) => value && !isExactSemverVersion(value))
    .map(([name, value]) => `${name}=${value}`);

  if (inexactVersions.length > 0) {
    writeBlockedResult(
      `skew conformance requires exact published artifact semver pins for: ${inexactVersions.join(', ')}`,
      startedAt,
      timestamp(),
      artifactVersions,
    );
    return;
  }

  const token = process.env.DW_SKEW_AUTH_TOKEN ?? 'dev-token';
  const namespace = process.env.DW_SKEW_NAMESPACE ?? 'default';
  const baseHeaders = {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    Authorization: `Bearer ${token}`,
    'X-Namespace': namespace,
  };

  const clusterInfo = await requestJson(serverUrl, 'GET', '/api/cluster/info', baseHeaders);
  const observedServerVersion = extractServerVersion(clusterInfo.body);
  if (!observedServerVersion) {
    writeBlockedResult(
      `probed published server at ${serverUrl} did not report a server version from GET /api/cluster/info; cannot prove it is published server artifact ${artifactVersions.server}`,
      startedAt,
      timestamp(),
      artifactVersions,
    );
    return;
  }

  if (observedServerVersion !== artifactVersions.server) {
    writeBlockedResult(
      `probed published server version mismatch: expected DW_SERVER_VERSION ${artifactVersions.server}, but GET /api/cluster/info reported ${observedServerVersion}; refusing to emit skew evidence for a mismatched published server URL`,
      startedAt,
      timestamp(),
      artifactVersions,
    );
    return;
  }

  const protocolManifestVersions = extractProtocolManifestVersions(clusterInfo.body);

  const context = {
    artifactVersions,
    baseHeaders,
    namespace,
    observedServerVersion,
    protocolManifestVersions,
    serverUrl,
    runId: `skew-${Date.now().toString(36)}`,
  };

  const surfaceResults = {};
  const pairingResults = {};
  const operationEvidence = {};
  const requestResponseCaptures = [];
  const findings = [];
  const findingLinks = {};

  for (const [surfaceName, surface] of Object.entries(surfaces)) {
    surfaceResults[surfaceName] = {
      surface: surfaceName,
      artifact: surface.artifact,
      component: surface.component,
      artifact_version: artifactVersions[surface.artifact],
      artifact_install: artifactInstallSummary(surfaceName),
      required_pairing_classes: Object.keys(pairingClasses),
      operation_groups: surface.operationGroups,
      status: 'pass',
    };
    pairingResults[surfaceName] = {};
    operationEvidence[surfaceName] = {};

    for (const pairingClass of Object.keys(pairingClasses)) {
      operationEvidence[surfaceName][pairingClass] = {};
      const rowsForPairing = [];

      for (const operationGroup of surface.operationGroups) {
        operationEvidence[surfaceName][pairingClass][operationGroup] = [];

        for (const requestTemplate of operationGroups[operationGroup].requests) {
          const row = await probeOperation({
            surfaceName,
            surface,
            pairingClass,
            operationGroup,
            requestTemplate,
            context,
            clusterInfo,
          });

          operationEvidence[surfaceName][pairingClass][operationGroup].push(row.evidence);
          requestResponseCaptures.push(row.capture);
          rowsForPairing.push(row.evidence);
        }
      }

      const pairing = summarizePairing(surfaceName, pairingClass, rowsForPairing, context);
      pairingResults[surfaceName][pairingClass] = pairing;

      const finding = findingForPairing(surfaceName, pairingClass, pairing, rowsForPairing, context);
      if (finding) {
        findings.push(finding);
        findingLinks[`${surfaceName}.${pairingClass}`] = finding.link;
      }
    }
  }

  for (const finding of findings) {
    surfaceResults[finding.surface].status = 'fail';
  }

  const finishedAt = timestamp();
  const outcome = findings.length === 0 ? 'pass' : 'fail';
  const compatibilityWindows = compatibilityWindowReport(clusterInfo.body);
  const futureVersionBoundary = futureBoundaryReport(pairingResults, operationEvidence);

  const pins = {
    schema: 'durable-workflow.v2.skew-refusal-matrix.pins',
    suite_version: suiteVersion,
    artifact_versions: artifactVersions,
    published_artifact_versions: artifactVersions,
    artifact_sources: artifactSources(),
    local_product_source_checkouts_used: false,
  };

  const metadata = {
    schema: METADATA_SCHEMA,
    suite_schema: scenarioManifest.suite_schema ?? 'durable-workflow.v2.platform-conformance.suite',
    suite_version: suiteVersion,
    scenario_manifest: 'static/platform-conformance/skew-refusal-matrix-scenarios.json',
    started_at: startedAt,
    finished_at: finishedAt,
    generated_at: finishedAt,
    runner_blocked: false,
    server_url: serverUrl,
    namespace,
    artifact_versions: artifactVersions,
    published_artifact_versions: artifactVersions,
    resolved_artifact_versions: artifactVersions,
    implementation_identity: {
      runner_repository: 'server',
      runner_path: 'scripts/conformance/skew-published-artifacts.sh',
      probe_transport: 'published-artifact-invocation-recording-proxy',
      artifact_manifest: path.basename(artifactManifestPath),
    },
  };

  const result = {
    schema: RESULT_SCHEMA,
    suite_schema: scenarioManifest.suite_schema ?? 'durable-workflow.v2.platform-conformance.suite',
    suite_version: suiteVersion,
    scenario_id: 'skew_refusal_matrix',
    required_scenarios: requiredScenarios,
    status: outcome,
    outcome,
    verdict: outcome,
    started_at: startedAt,
    finished_at: finishedAt,
    generated_at: finishedAt,
    runner_blocked: false,
    artifact_versions: artifactVersions,
    published_artifact_versions: artifactVersions,
    resolved_artifact_versions: artifactVersions,
    artifact_sources: artifactSources(),
    local_product_source_checkouts_used: false,
    implementation_identity: metadata.implementation_identity,
    surface_results: surfaceResults,
    pairing_results: pairingResults,
    operation_evidence: operationEvidence,
    compatibility_windows: compatibilityWindows,
    future_version_boundary: futureVersionBoundary,
    request_response_captures: requestResponseCaptures,
    findings,
    finding_links: findingLinks,
  };

  const record = {
    schema: RECORD_SCHEMA,
    suite_version: suiteVersion,
    outcome,
    runnerBlocked: false,
    artifactVersions: artifactVersions,
    record: result,
  };

  writeJson('pins.json', pins);
  writeJson('run-metadata.json', metadata);
  writeJson('request-response-captures.json', {
    schema: CAPTURE_SCHEMA,
    suite_version: suiteVersion,
    generated_at: finishedAt,
    captures: requestResponseCaptures,
  });
  writeJson('skew-result.json', result);
  writeJson('skew-record.json', record);
}

async function probeOperation({
  surfaceName,
  surface,
  pairingClass,
  operationGroup,
  requestTemplate,
  context,
  clusterInfo,
}) {
  const pairing = pairingClasses[pairingClass];
  const state = pairingState(context, surfaceName, pairingClass);
  const { method, path: requestPath } = materializeRequest(
    requestTemplate,
    context.runId,
    state,
  );
  const headers = { ...context.baseHeaders };
  const body = bodyForRequest(method, requestPath, context.runId, state);
  const surfaceVersion = context.artifactVersions[surface.artifact];

  if (operationGroup === 'worker_lifecycle') {
    headers['X-Durable-Workflow-Protocol-Version'] = pairing.workerProtocolVersion;
  } else if (operationGroup !== 'cluster_info_probe' && operationGroup !== 'waterline_render') {
    headers['X-Durable-Workflow-Control-Plane-Version'] = pairing.controlPlaneVersion;
  }

  const availability = invocationAvailability(surfaceName);
  if (!availability.available) {
    return notCoveredProbe({
      surfaceName,
      surface,
      pairingClass,
      operationGroup,
      requestTemplate,
      method,
      requestPath,
      headers,
      body,
      context,
      status: availability.status,
      reason: availability.reason,
    });
  }

  const workerDependencyGap = workflowWorkerDependencyGap(surfaceName, operationGroup, requestTemplate);
  if (workerDependencyGap) {
    return notCoveredProbe({
      surfaceName,
      surface,
      pairingClass,
      operationGroup,
      requestTemplate,
      method,
      requestPath,
      headers,
      body,
      context,
      status: workerDependencyGap.status,
      reason: workerDependencyGap.reason,
    });
  }

  const invocation = await invokeSurfaceOperation({
    surfaceName,
    surface,
    pairingClass,
    operationGroup,
    requestTemplate,
    method,
    requestPath,
    headers,
    body,
    context,
    clusterInfo,
  });
  const response = invocation.response;

  const status = classifyEvidenceStatus({
    surfaceName,
    pairingClass,
    operationGroup,
    response,
  });
  const captureId = [
    surfaceName,
    pairingClass,
    operationGroup,
    normalizeRequestKey(requestTemplate),
  ].join('.');

  const capture = {
    id: captureId,
    surface: surfaceName,
    pairing_class: pairingClass,
    operation_group: operationGroup,
    artifact_versions: context.artifactVersions,
    client_or_worker_version: surfaceVersion,
    server_version: context.observedServerVersion,
    compatibility_window: pairing.compatibilityWindow,
    request: {
      method,
      path: requestPath,
      headers: redactHeaders(headers),
      body: body ?? null,
    },
    response: {
      status: response.status,
      headers: response.headers,
      body: response.body,
    },
    artifact_invocation: invocation.artifact_invocation,
    proxy_captures: invocation.proxy_captures,
  };

  let evidence;
  if (operationGroup === 'cluster_info_probe') {
    evidence = {
      surface: surfaceName,
      pairing_class: pairingClass,
      operation_group: operationGroup,
      request: `${method} ${requestPath}`,
      status,
      status_code: response.status,
      response_body: response.body,
      client_or_observer_version: surfaceVersion,
      server_version: context.observedServerVersion,
      protocol_manifest_versions: context.protocolManifestVersions,
      compatibility_window: pairing.compatibilityWindow,
      request_response_capture_id: captureId,
      artifact_invocation: invocation.artifact_invocation,
    };
  } else if (operationGroup === 'waterline_render') {
    const classification = waterlineClassification(pairingClass, response);
    evidence = {
      surface: surfaceName,
      pairing_class: pairingClass,
      operation_group: operationGroup,
      request: `${method} ${requestPath}`,
      response_status: response.status,
      response_body: response.body,
      screenshot_or_dom_snapshot: domSnapshotForWaterline(classification, response, pairingClass, context),
      server_version: context.observedServerVersion,
      waterline_version: surfaceVersion,
      status,
      waterline_skew_classification: classification,
      compatibility_window: pairing.compatibilityWindow,
      request_response_capture_id: captureId,
      artifact_invocation: invocation.artifact_invocation,
    };
  } else {
    evidence = {
      surface: surfaceName,
      pairing_class: pairingClass,
      operation_group: operationGroup,
      request_method: method,
      request_path: requestPath,
      request_headers: redactHeaders(headers),
      request_body: body ?? null,
      response_status: response.status,
      response_headers: response.headers,
      response_body: response.body,
      client_or_worker_version: surfaceVersion,
      server_version: context.observedServerVersion,
      compatibility_window: pairing.compatibilityWindow,
      status,
      request_response_capture_id: captureId,
      artifact_invocation: invocation.artifact_invocation,
    };
  }

  if (status === 'loud_refuse') {
    evidence.refusal_requirements_met = refusalRequirements[surfaceName];
    evidence.refusal_context = loudRefusalContext(surfaceName, surfaceVersion, context, pairing, response);
  }

  if (surfaceName === 'workflow-worker') {
    evidence.worker_skew_classification = workerClassification(pairingClass, response, operationGroup);
  }

  if (surfaceName === 'waterline') {
    evidence.waterline_skew_classification = waterlineClassification(pairingClass, response);
  }

  return { evidence, capture };
}

function notCoveredProbe({
  surfaceName,
  surface,
  pairingClass,
  operationGroup,
  requestTemplate,
  method,
  requestPath,
  headers,
  body,
  context,
  status,
  reason,
}) {
  const surfaceVersion = context.artifactVersions[surface.artifact];
  const pairing = pairingClasses[pairingClass];
  const captureId = [
    surfaceName,
    pairingClass,
    operationGroup,
    normalizeRequestKey(requestTemplate),
  ].join('.');
  const response = {
    status: 0,
    headers: {},
    body: {
      status,
      reason,
      coverage_gap: true,
      surface: surfaceName,
      artifact: surface.artifact,
    },
  };
  const capture = {
    id: captureId,
    surface: surfaceName,
    pairing_class: pairingClass,
    operation_group: operationGroup,
    artifact_versions: context.artifactVersions,
    client_or_worker_version: surfaceVersion,
    server_version: context.observedServerVersion,
    compatibility_window: pairing.compatibilityWindow,
    request: {
      method,
      path: requestPath,
      headers: redactHeaders(headers),
      body: body ?? null,
      not_sent: true,
      not_sent_reason: reason,
    },
    response,
    artifact_invocation: {
      status,
      reason,
      surface: surfaceName,
      artifact: surface.artifact,
    },
    proxy_captures: [],
  };

  let evidence;
  if (operationGroup === 'cluster_info_probe') {
    evidence = {
      surface: surfaceName,
      pairing_class: pairingClass,
      operation_group: operationGroup,
      request: `${method} ${requestPath}`,
      status,
      status_code: 0,
      response_body: response.body,
      client_or_observer_version: surfaceVersion,
      server_version: context.observedServerVersion,
      protocol_manifest_versions: context.protocolManifestVersions,
      compatibility_window: pairing.compatibilityWindow,
      request_response_capture_id: captureId,
      coverage_gap_reason: reason,
    };
  } else if (operationGroup === 'waterline_render') {
    evidence = {
      surface: surfaceName,
      pairing_class: pairingClass,
      operation_group: operationGroup,
      request: `${method} ${requestPath}`,
      response_status: 0,
      response_body: response.body,
      screenshot_or_dom_snapshot: {
        type: 'not_covered',
        reason,
        surface: surfaceName,
        pairing_class: pairingClass,
      },
      server_version: context.observedServerVersion,
      waterline_version: surfaceVersion,
      status,
      compatibility_window: pairing.compatibilityWindow,
      request_response_capture_id: captureId,
      coverage_gap_reason: reason,
    };
  } else {
    evidence = {
      surface: surfaceName,
      pairing_class: pairingClass,
      operation_group: operationGroup,
      request_method: method,
      request_path: requestPath,
      request_headers: redactHeaders(headers),
      request_body: body ?? null,
      response_status: 0,
      response_headers: {},
      response_body: response.body,
      client_or_worker_version: surfaceVersion,
      server_version: context.observedServerVersion,
      compatibility_window: pairing.compatibilityWindow,
      status,
      request_response_capture_id: captureId,
      coverage_gap_reason: reason,
    };
  }

  return { evidence, capture };
}

function artifactRecordForSurface(surfaceName) {
  const artifact = surfaces[surfaceName]?.artifact;
  if (!artifact) {
    return {};
  }

  const records = artifactManifest.surfaces;
  return records && typeof records === 'object' && records[artifact] && typeof records[artifact] === 'object'
    ? records[artifact]
    : {};
}

function artifactInstallSummary(surfaceName) {
  const record = artifactRecordForSurface(surfaceName);
  if (Object.keys(record).length === 0) {
    return {
      status: 'not_covered',
      reason: 'published artifact handoff did not report this surface',
    };
  }

  return {
    status: stringValue(record.status) || 'not_covered',
    source: stringValue(record.source) || artifactSources()[surfaces[surfaceName].artifact] || null,
    path: stringValue(record.executable || record.python || record.app_dir || record.package_dir) || null,
    reason: stringValue(record.reason) || null,
  };
}

function invocationAvailability(surfaceName) {
  const record = artifactRecordForSurface(surfaceName);
  const status = stringValue(record.status) || 'not_covered';
  const reason = stringValue(record.reason);

  if (status !== 'available') {
    return {
      available: false,
      status: status === 'runner_blocked' ? 'runner_blocked' : 'not_covered',
      reason: reason || `${surfaceName} published artifact was not installed by the handoff`,
    };
  }

  if (surfaceName === 'cli') {
    const executable = stringValue(record.executable) || envValue('DW_SKEW_CLI_BIN');
    if (executable && fs.existsSync(executable)) {
      return { available: true, executable };
    }

    return {
      available: false,
      status: 'runner_blocked',
      reason: 'CLI artifact install completed without an executable dw binary',
    };
  }

  if (surfaceName === 'sdk-python') {
    const python = stringValue(record.python) || envValue('DW_SKEW_PYTHON_BIN');
    if (python && fs.existsSync(python)) {
      return { available: true, python };
    }

    return {
      available: false,
      status: 'runner_blocked',
      reason: 'Python SDK artifact install completed without a runnable Python interpreter',
    };
  }

  if (surfaceName === 'workflow-worker') {
    return {
      available: false,
      status: 'not_covered',
      reason: 'durable-workflow/workflow was installed from Packagist, but this runner does not yet boot a PHP worker process through the package API',
    };
  }

  if (surfaceName === 'waterline') {
    return {
      available: false,
      status: 'not_covered',
      reason: 'durable-workflow/waterline was installed from Packagist, but this runner does not yet boot a Waterline app and capture DOM evidence',
    };
  }

  return {
    available: false,
    status: 'not_covered',
    reason: `${surfaceName} does not have a published-artifact invoker`,
  };
}

function workflowWorkerDependencyGap(surfaceName, operationGroup, requestTemplate) {
  if (
    (surfaceName !== 'cli' && surfaceName !== 'sdk-python')
    || operationGroup !== 'workflow_control_plane'
    || !workflowWorkerDependentRequests.has(requestTemplate)
  ) {
    return null;
  }

  const workerAvailability = invocationAvailability('workflow-worker');
  if (workerAvailability.available) {
    return null;
  }

  return {
    status: workerAvailability.status,
    reason: [
      `${requestTemplate} requires a live compatible published workflow worker for skew_conformance_workflow.`,
      workerAvailability.reason,
    ].filter(Boolean).join(' '),
  };
}

async function invokeSurfaceOperation(options) {
  if (options.surfaceName === 'cli') {
    return invokeCliOperation(options);
  }

  if (options.surfaceName === 'sdk-python') {
    return invokePythonSdkOperation(options);
  }

  throw new Error(`no artifact invoker registered for ${options.surfaceName}`);
}

async function invokeCliOperation({
  surfaceName,
  pairingClass,
  operationGroup,
  requestTemplate,
  method,
  requestPath,
  context,
}) {
  const executable = invocationAvailability(surfaceName).executable;
  const args = cliArgsFor(requestTemplate, context, pairingClass);
  const pairing = pairingClasses[pairingClass];

  return runArtifactWithProxy({
    surfaceName,
    pairingClass,
    operationGroup,
    method,
    requestPath,
    context,
    pairing,
    command: executable,
    args,
    env: {
      DURABLE_WORKFLOW_SERVER_URL: '__DW_SKEW_PROXY_URL__',
      DURABLE_WORKFLOW_AUTH_TOKEN: process.env.DW_SKEW_AUTH_TOKEN ?? 'dev-token',
      DURABLE_WORKFLOW_NAMESPACE: context.namespace,
      DURABLE_WORKFLOW_TLS_VERIFY: 'false',
      DW_ENV: '',
    },
    timeoutMs: Number.parseInt(process.env.DW_SKEW_CLI_TIMEOUT_MS ?? '20000', 10),
  });
}

function cliArgsFor(requestTemplate, context, pairingClass) {
  const state = pairingState(context, 'cli', pairingClass);
  const workflowId = state.workflowId;
  const runId = state.runId || `run-${context.runId}`;
  const scheduleId = state.scheduleId;
  const global = [
    `--server=__DW_SKEW_PROXY_URL__`,
    `--token=${process.env.DW_SKEW_AUTH_TOKEN ?? 'dev-token'}`,
    `--namespace=${context.namespace}`,
    '--tls-verify=false',
  ];

  switch (requestTemplate) {
    case 'GET /api/cluster/info':
      return [...global, 'server:info', '--json'];
    case 'POST /api/workflows':
      return [
        ...global,
        'workflow:start',
        '--type=skew_conformance_workflow',
        `--workflow-id=${workflowId}`,
        '--task-queue=skew-conformance',
        '--input=[]',
        '--json',
      ];
    case 'GET /api/workflows/{workflowId}':
      return [...global, 'workflow:describe', workflowId, '--json'];
    case 'GET /api/workflows/{workflowId}/runs':
      return [...global, 'workflow:list-runs', workflowId, '--json'];
    case 'GET /api/workflows/{workflowId}/runs/{runId}':
      return [...global, 'workflow:show-run', workflowId, runId, '--json'];
    case 'GET /api/workflows/{workflowId}/runs/{runId}/history':
      return [...global, 'workflow:history', workflowId, runId, '--json'];
    case 'POST /api/workflows/{workflowId}/signal/{signalName}':
      return [...global, 'workflow:signal', workflowId, 'advance', '--input={"source":"skew-conformance"}', '--json'];
    case 'POST /api/workflows/{workflowId}/query/{queryName}':
      return [...global, 'workflow:query', workflowId, 'currentState', '--input=[]', '--json'];
    case 'POST /api/workflows/{workflowId}/update/{updateName}':
      return [...global, 'workflow:update', workflowId, 'approve', '--input=[]', '--json'];
    case 'POST /api/workflows/{workflowId}/runs/{runId}/signal/{signalName}':
      return [...global, 'workflow:signal', workflowId, 'advance', `--run-id=${runId}`, '--input={"source":"skew-conformance"}', '--json'];
    case 'POST /api/workflows/{workflowId}/runs/{runId}/query/{queryName}':
      return [...global, 'workflow:query', workflowId, 'currentState', `--run-id=${runId}`, '--input=[]', '--json'];
    case 'POST /api/workflows/{workflowId}/runs/{runId}/update/{updateName}':
      return [...global, 'workflow:update', workflowId, 'approve', `--run-id=${runId}`, '--input=[]', '--json'];
    case 'POST /api/workflows/{workflowId}/cancel':
      return [...global, 'workflow:cancel', workflowId, '--reason=skew conformance boundary probe', '--json'];
    case 'POST /api/workflows/{workflowId}/terminate':
      return [...global, 'workflow:terminate', workflowId, '--reason=skew conformance boundary probe', '--json'];
    case 'POST /api/schedules':
      return [
        ...global,
        'schedule:create',
        `--schedule-id=${scheduleId}`,
        '--workflow-type=skew_conformance_workflow',
        '--task-queue=skew-conformance',
        '--interval=PT1M',
        '--paused',
        '--json',
      ];
    case 'GET /api/schedules/{id}':
      return [...global, 'schedule:describe', scheduleId, '--json'];
    case 'POST /api/schedules/{id}/trigger':
      return [...global, 'schedule:trigger', scheduleId, '--overlap-policy=skip', '--json'];
    default:
      return [...global, 'server:info', '--json'];
  }
}

async function invokePythonSdkOperation({
  surfaceName,
  pairingClass,
  operationGroup,
  requestTemplate,
  method,
  requestPath,
  context,
}) {
  const python = invocationAvailability(surfaceName).python;
  const script = ensurePythonProbeScript();
  const pairing = pairingClasses[pairingClass];
  const state = pairingState(context, 'sdk-python', pairingClass);
  const payload = {
    operation: requestTemplate,
    base_url: '__DW_SKEW_PROXY_URL__',
    namespace: context.namespace,
    workflow_id: state.workflowId,
    run_id: state.runId || `run-${context.runId}`,
    schedule_id: state.scheduleId,
    worker_id: state.workerId,
  };

  return runArtifactWithProxy({
    surfaceName,
    pairingClass,
    operationGroup,
    method,
    requestPath,
    context,
    pairing,
    command: python,
    args: [script, JSON.stringify(payload)],
    env: {
      DW_SKEW_AUTH_TOKEN: process.env.DW_SKEW_AUTH_TOKEN ?? 'dev-token',
    },
    timeoutMs: Number.parseInt(process.env.DW_SKEW_PYTHON_TIMEOUT_MS ?? '20000', 10),
  });
}

function ensurePythonProbeScript() {
  const scriptPath = path.join(runRoot, 'python-sdk-skew-probe.py');
  if (fs.existsSync(scriptPath)) {
    return scriptPath;
  }

  fs.writeFileSync(scriptPath, `from __future__ import annotations

import asyncio
import dataclasses
import json
import os
import sys
from typing import Any

from durable_workflow import Client
from durable_workflow.client import ScheduleAction, ScheduleSpec


def public(value: Any) -> Any:
    if dataclasses.is_dataclass(value):
        return dataclasses.asdict(value)
    if hasattr(value, "__dict__"):
        return {k: public(v) for k, v in vars(value).items() if not k.startswith("_")}
    if isinstance(value, list):
        return [public(v) for v in value]
    if isinstance(value, dict):
        return {str(k): public(v) for k, v in value.items()}
    return value


async def run(payload: dict[str, Any]) -> dict[str, Any]:
    client = Client(
        payload["base_url"],
        token=os.environ.get("DW_SKEW_AUTH_TOKEN"),
        namespace=payload.get("namespace") or "default",
        timeout=8.0,
    )
    op = payload["operation"]
    workflow_id = payload["workflow_id"]
    run_id = payload["run_id"]
    schedule_id = payload["schedule_id"]
    worker_id = payload["worker_id"]
    try:
        if op == "GET /api/cluster/info":
            result = await client.get_cluster_info()
        elif op == "POST /api/workflows":
            handle = await client.start_workflow(
                workflow_type="skew_conformance_workflow",
                task_queue="skew-conformance",
                workflow_id=workflow_id,
                input=[],
            )
            result = {"workflow_id": handle.workflow_id, "run_id": handle.run_id, "workflow_type": handle.workflow_type}
        elif op == "GET /api/workflows/{workflowId}":
            result = await client.describe_workflow(workflow_id)
        elif op == "GET /api/workflows/{workflowId}/runs":
            result = await client.list_workflow_runs(workflow_id)
        elif op == "GET /api/workflows/{workflowId}/runs/{runId}":
            result = await client.describe_workflow_run(workflow_id, run_id)
        elif op == "GET /api/workflows/{workflowId}/runs/{runId}/history":
            result = await client.get_history(workflow_id, run_id)
        elif op == "POST /api/workflows/{workflowId}/signal/{signalName}":
            result = await client.signal_workflow(workflow_id, "advance", args=[{"source": "skew-conformance"}])
        elif op == "POST /api/workflows/{workflowId}/query/{queryName}":
            result = await client.query_workflow(workflow_id, "currentState", args=[])
        elif op == "POST /api/workflows/{workflowId}/update/{updateName}":
            result = await client.update_workflow(workflow_id, "approve", args=[], wait_for="accepted")
        elif op == "POST /api/workflows/{workflowId}/runs/{runId}/signal/{signalName}":
            result = await client.signal_workflow(workflow_id, "advance", args=[{"source": "skew-conformance"}])
        elif op == "POST /api/workflows/{workflowId}/runs/{runId}/query/{queryName}":
            result = await client.query_workflow(workflow_id, "currentState", args=[])
        elif op == "POST /api/workflows/{workflowId}/runs/{runId}/update/{updateName}":
            result = await client.update_workflow(workflow_id, "approve", args=[], wait_for="accepted")
        elif op == "POST /api/workflows/{workflowId}/cancel":
            result = await client.cancel_workflow(workflow_id, reason="skew conformance boundary probe")
        elif op == "POST /api/workflows/{workflowId}/terminate":
            result = await client.terminate_workflow(workflow_id, reason="skew conformance boundary probe")
        elif op == "POST /api/worker/register":
            result = await client.register_worker(
                worker_id=worker_id,
                task_queue="skew-conformance",
                supported_workflow_types=[],
                supported_activity_types=[],
            )
        elif op == "POST /api/worker/heartbeat":
            result = await client.heartbeat_worker(worker_id=worker_id)
        elif op == "POST /api/worker/workflow-tasks/poll":
            result = await client.poll_workflow_task(worker_id=worker_id, task_queue="skew-conformance", timeout=1.0)
        elif op == "POST /api/worker/workflow-tasks/{task}/complete":
            result = await client.complete_workflow_task(
                task_id="task-skew-conformance",
                lease_owner=worker_id,
                workflow_task_attempt=1,
                commands=[],
            )
        elif op == "POST /api/worker/workflow-tasks/{task}/fail":
            result = await client.fail_workflow_task(
                task_id="task-skew-conformance",
                lease_owner=worker_id,
                workflow_task_attempt=1,
                message="skew conformance boundary probe",
                exception_type="SkewConformanceFailure",
            )
        elif op == "POST /api/schedules":
            handle = await client.create_schedule(
                schedule_id=schedule_id,
                spec=ScheduleSpec(intervals=[{"every": "PT1M"}]),
                action=ScheduleAction(
                    workflow_type="skew_conformance_workflow",
                    task_queue="skew-conformance",
                    input=[],
                ),
                paused=True,
                overlap_policy="skip",
            )
            result = {"schedule_id": handle.schedule_id}
        elif op == "GET /api/schedules/{id}":
            result = await client.describe_schedule(schedule_id)
        elif op == "POST /api/schedules/{id}/trigger":
            result = await client.trigger_schedule(schedule_id, overlap_policy="skip")
        else:
            raise RuntimeError(f"unsupported Python SDK skew operation: {op}")
        return {"ok": True, "result": public(result)}
    except BaseException as exc:
        return {
            "ok": False,
            "exception_type": type(exc).__name__,
            "message": str(exc),
            "status": getattr(exc, "status", None),
            "reason": getattr(exc, "reason", None),
            "body": public(getattr(exc, "body", None)),
            "errors": public(getattr(exc, "errors", None)),
        }
    finally:
        await client.aclose()


if __name__ == "__main__":
    print(json.dumps(asyncio.run(run(json.loads(sys.argv[1]))), sort_keys=True))
`);

  return scriptPath;
}

async function runArtifactWithProxy({
  surfaceName,
  pairingClass,
  operationGroup,
  method,
  requestPath,
  context,
  pairing,
  command,
  args,
  env,
  timeoutMs,
}) {
  const proxyResult = await withRecordingProxy({
    targetUrl: context.serverUrl,
    controlPlaneVersion: pairing.controlPlaneVersion,
    workerProtocolVersion: pairing.workerProtocolVersion,
  }, async (proxyUrl) => {
    const rewrittenArgs = args.map((arg) => arg.replaceAll('__DW_SKEW_PROXY_URL__', proxyUrl));
    const rewrittenEnv = Object.fromEntries(
      Object.entries(env).map(([key, value]) => [key, String(value).replaceAll('__DW_SKEW_PROXY_URL__', proxyUrl)]),
    );

    return runProcess(command, rewrittenArgs, {
      ...process.env,
      ...rewrittenEnv,
    }, timeoutMs);
  });

  const exactCapture = selectProxyCapture(proxyResult.captures, method, requestPath);
  const selectedCapture = exactCapture
    ?? proxyResult.captures.find((capture) => isProtocolRefusal(capture.response))
    ?? null;
  const stdoutJson = parseJson(proxyResult.process.stdout.trim());
  const response = selectedCapture?.response ?? {
    status: 0,
    headers: {},
    body: {
      reason: exactCapture === null && proxyResult.captures.length > 0
        ? 'advertised_operation_not_observed'
        : 'artifact_did_not_contact_server',
      message: 'The published artifact invocation did not produce wire evidence for the advertised operation.',
      exit_code: proxyResult.process.exitCode,
      artifact_output: redactJsonSecrets(stdoutJson),
      stdout: redactKnownSecrets(proxyResult.process.stdout.slice(0, 2000)),
      stderr: redactKnownSecrets(proxyResult.process.stderr.slice(0, 2000)),
    },
  };

  updatePairingStateFromResponse(context, surfaceName, pairingClass, response.body);

  return {
    response: {
      ...response,
      artifact_exit_code: proxyResult.process.exitCode,
    },
    artifact_invocation: {
      surface: surfaceName,
      pairing_class: pairingClass,
      operation_group: operationGroup,
      command: path.basename(command),
      args: redactCommandArgs(proxyResult.process.args),
      exit_code: proxyResult.process.exitCode,
      timed_out: proxyResult.process.timedOut,
      stdout_excerpt: redactKnownSecrets(proxyResult.process.stdout.slice(0, 4000)),
      stderr_excerpt: redactKnownSecrets(proxyResult.process.stderr.slice(0, 4000)),
      selected_proxy_capture: selectedCapture?.id ?? null,
    },
    proxy_captures: proxyResult.captures,
  };
}

async function withRecordingProxy({ targetUrl, controlPlaneVersion, workerProtocolVersion }, callback) {
  const captures = [];
  const server = http.createServer(async (request, response) => {
    const chunks = [];
    request.on('data', (chunk) => chunks.push(chunk));
    request.on('end', async () => {
      const requestBody = Buffer.concat(chunks);
      const headers = { ...request.headers };
      delete headers.host;
      delete headers.connection;
      delete headers['content-length'];

      if ((request.url ?? '').startsWith('/api/worker')) {
        headers['x-durable-workflow-protocol-version'] = workerProtocolVersion;
      } else {
        headers['x-durable-workflow-control-plane-version'] = controlPlaneVersion;
      }

      const requestUrl = new URL(request.url ?? '/', targetUrl);
      const capture = {
        id: `proxy-${captures.length + 1}`,
        request: {
          method: request.method ?? 'GET',
          path: requestUrl.pathname,
          headers: redactHeaders(headers),
          body: parseJson(requestBody.toString('utf8')) ?? (requestBody.length > 0 ? requestBody.toString('utf8') : null),
        },
        response: {
          status: 0,
          headers: {},
          body: null,
        },
      };

      try {
        const upstream = await fetch(requestUrl, {
          method: request.method,
          headers,
          body: requestBody.length > 0 ? requestBody : undefined,
        });
        const text = await upstream.text();
        const parsed = parseJson(text);
        const responseHeaders = Object.fromEntries(upstream.headers.entries());
        capture.response = {
          status: upstream.status,
          headers: responseHeaders,
          body: parsed ?? text,
        };
        response.statusCode = upstream.status;
        for (const [key, value] of Object.entries(responseHeaders)) {
          if (['content-encoding', 'content-length', 'transfer-encoding', 'connection'].includes(key.toLowerCase())) {
            continue;
          }
          response.setHeader(key, value);
        }
        response.end(text);
      } catch (error) {
        capture.response = {
          status: 502,
          headers: {},
          body: {
            reason: 'skew_proxy_upstream_error',
            message: error instanceof Error ? error.message : String(error),
          },
        };
        response.statusCode = 502;
        response.setHeader('Content-Type', 'application/json');
        response.end(JSON.stringify(capture.response.body));
      } finally {
        captures.push(capture);
      }
    });
  });

  await new Promise((resolve, reject) => {
    server.once('error', reject);
    server.listen(0, '127.0.0.1', resolve);
  });

  const address = server.address();
  const proxyUrl = `http://127.0.0.1:${address.port}`;

  try {
    const processResult = await callback(proxyUrl);
    return { process: processResult, captures };
  } finally {
    await new Promise((resolve) => server.close(resolve));
  }
}

function runProcess(command, args, env, timeoutMs) {
  return new Promise((resolve) => {
    const child = spawn(command, args, {
      env,
      stdio: ['ignore', 'pipe', 'pipe'],
    });
    let stdout = '';
    let stderr = '';
    let timedOut = false;
    const timer = setTimeout(() => {
      timedOut = true;
      child.kill('SIGTERM');
      setTimeout(() => child.kill('SIGKILL'), 1000).unref();
    }, Number.isFinite(timeoutMs) && timeoutMs > 0 ? timeoutMs : 20000);

    child.stdout.on('data', (chunk) => {
      stdout += chunk.toString('utf8');
    });
    child.stderr.on('data', (chunk) => {
      stderr += chunk.toString('utf8');
    });
    child.on('error', (error) => {
      clearTimeout(timer);
      resolve({
        command,
        args,
        exitCode: 127,
        stdout,
        stderr: `${stderr}${stderr ? '\n' : ''}${error.message}`,
        timedOut,
      });
    });
    child.on('close', (code, signal) => {
      clearTimeout(timer);
      resolve({
        command,
        args,
        exitCode: code ?? (signal ? 128 : 1),
        stdout,
        stderr,
        timedOut,
      });
    });
  });
}

function selectProxyCapture(captures, method, requestPath) {
  const normalized = normalizeOperationRequest(`${method} ${requestPath}`);
  return captures.find((capture) => normalizeOperationRequest(
    `${capture.request.method} ${capture.request.path}`,
  ) === normalized) ?? null;
}

function updatePairingStateFromResponse(context, surfaceName, pairingClass, body) {
  const state = pairingState(context, surfaceName, pairingClass);
  if (body && typeof body === 'object') {
    const workflowId = stringValue(body.workflow_id);
    const runId = stringValue(body.run_id);
    const scheduleId = stringValue(body.schedule_id);
    if (workflowId) {
      state.workflowId = workflowId;
    }
    if (runId) {
      state.runId = runId;
    }
    if (scheduleId) {
      state.scheduleId = scheduleId;
    }
  }
}

function pairingState(context, surfaceName, pairingClass) {
  const key = `${surfaceName}.${pairingClass}`;
  context.pairingState ??= {};
  context.pairingState[key] ??= {
    workflowId: `skew-${context.runId}-${surfaceName.replace(/[^a-z0-9]+/gi, '-')}-${pairingClass}`,
    runId: '',
    scheduleId: `schedule-${context.runId}-${surfaceName.replace(/[^a-z0-9]+/gi, '-')}-${pairingClass}`,
    workerId: `worker-${context.runId}-${surfaceName.replace(/[^a-z0-9]+/gi, '-')}-${pairingClass}`,
  };

  return context.pairingState[key];
}

function redactCommandArgs(args) {
  const redacted = [];
  for (let index = 0; index < args.length; index += 1) {
    const arg = String(args[index]);
    if (arg === '--token') {
      redacted.push(arg);
      if (index + 1 < args.length) {
        redacted.push('<redacted>');
        index += 1;
      }
      continue;
    }

    if (arg.startsWith('--token=')) {
      redacted.push('--token=<redacted>');
      continue;
    }

    const parsed = parseJson(arg);
    if (parsed !== null) {
      redacted.push(JSON.stringify(redactJsonSecrets(parsed)));
      continue;
    }

    redacted.push(redactKnownSecrets(arg));
  }

  return redacted;
}

function redactJsonSecrets(value) {
  if (Array.isArray(value)) {
    return value.map((item) => redactJsonSecrets(item));
  }

  if (value && typeof value === 'object') {
    return Object.fromEntries(
      Object.entries(value).map(([key, nested]) => [
        key,
        isSensitiveKey(key) ? '<redacted>' : redactJsonSecrets(nested),
      ]),
    );
  }

  if (typeof value === 'string') {
    return redactKnownSecrets(value);
  }

  return value;
}

function isSensitiveKey(key) {
  return /(?:authorization|credential|password|secret|token)/i.test(key);
}

function redactKnownSecrets(value) {
  let redacted = String(value);
  for (const secret of knownSecretValues()) {
    redacted = redacted.replaceAll(secret, '<redacted>');
  }

  return redacted;
}

function knownSecretValues() {
  return Array.from(new Set([
    process.env.DW_SKEW_AUTH_TOKEN ?? 'dev-token',
    process.env.DURABLE_WORKFLOW_AUTH_TOKEN,
  ].filter((value) => typeof value === 'string' && value.length >= 3)));
}

function classifyEvidenceStatus({ surfaceName, pairingClass, operationGroup, response }) {
  if (operationGroup === 'cluster_info_probe') {
    if (isProtocolRefusal(response)) {
      return 'loud_refuse';
    }

    if (response.status >= 400 || response.status === 0) {
      return 'silent_failure';
    }

    return 'pass';
  }

  if (operationGroup === 'waterline_render') {
    const classification = waterlineClassification(pairingClass, response);
    if (classification === 'stale_render') {
      return 'silent_success';
    }

    return pairingClass === 'compatible' ? 'pass' : 'loud_refuse';
  }

  if (isProtocolRefusal(response)) {
    return 'loud_refuse';
  }

  if (response.status >= 400 || response.status === 0) {
    return 'silent_failure';
  }

  if (surfaceName === 'workflow-worker') {
    if (pairingClass === 'compatible' || pairingClass === 'backward_skew') {
      return 'pass';
    }

    return 'silent_success';
  }

  if (pairingClass === 'compatible' || pairingClass === 'backward_skew') {
    return 'pass';
  }

  return 'silent_success';
}

function summarizePairing(surfaceName, pairingClass, rows, context) {
  const statuses = Array.from(new Set(rows.map((row) => row.status).filter(Boolean)));
  const pairingStatusPriority = [
    'corrupt',
    'silent_success',
    'silent_failure',
    'not_covered',
    'runner_blocked',
  ];
  const prioritizedStatus = pairingStatusPriority.find((value) => statuses.includes(value));
  let status = prioritizedStatus ?? 'pass';
  if (!prioritizedStatus && statuses.includes('loud_refuse')) {
    status = 'loud_refuse';
  }

  const surface = surfaces[surfaceName];
  const pairing = pairingClasses[pairingClass];
  const result = {
    surface: surfaceName,
    pairing_class: pairingClass,
    status,
    client_or_worker_version: context.artifactVersions[surface.artifact],
    server_version: context.observedServerVersion,
    compatibility_window: pairing.compatibilityWindow,
    observed_operation_statuses: statuses,
  };

  if (status === 'loud_refuse') {
    result.refusal_requirements_met = refusalRequirements[surfaceName];
    result.refusal_context = loudRefusalContext(
      surfaceName,
      context.artifactVersions[surface.artifact],
      context,
      pairing,
      rows.find((row) => row.status === 'loud_refuse')?.response_body ?? {},
    );
  }

  if (surfaceName === 'workflow-worker' && !['not_covered', 'runner_blocked'].includes(status)) {
    const classifications = rows.map((row) => row.worker_skew_classification).filter(Boolean);
    result.worker_skew_classification = classifications.includes('register_and_drop')
      ? 'register_and_drop'
      : classifications.includes('register_refused')
        ? 'register_refused'
        : 'register_and_serve';
  }

  if (surfaceName === 'waterline' && !['not_covered', 'runner_blocked'].includes(status)) {
    const classifications = rows.map((row) => row.waterline_skew_classification).filter(Boolean);
    result.waterline_skew_classification = classifications.includes('stale_render')
      ? 'stale_render'
      : classifications.includes('render_refused')
        ? 'render_refused'
        : 'banner';
  }

  return result;
}

function findingForPairing(surfaceName, pairingClass, pairing, rows, context) {
  const blockingStatuses = ['silent_success', 'silent_failure', 'corrupt', 'register_and_drop', 'stale_render', 'not_covered', 'runner_blocked'];
  const classificationStatus = pairing.worker_skew_classification === 'register_and_drop'
    ? 'register_and_drop'
    : pairing.waterline_skew_classification === 'stale_render'
      ? 'stale_render'
      : null;
  const findingStatus = classificationStatus ?? pairing.status;

  if (!blockingStatuses.includes(findingStatus)) {
    return null;
  }

  const key = `${surfaceName}-${pairingClass}-${findingStatus}`.replace(/[^a-z0-9_.-]+/gi, '-').toLowerCase();
  const firstCapture = rows.find((row) => row.status === findingStatus && row.request_response_capture_id)?.request_response_capture_id
    ?? rows.find((row) => row.worker_skew_classification === findingStatus && row.request_response_capture_id)?.request_response_capture_id
    ?? rows.find((row) => row.waterline_skew_classification === findingStatus && row.request_response_capture_id)?.request_response_capture_id
    ?? rows.find((row) => row.request_response_capture_id)?.request_response_capture_id
    ?? null;
  const surface = surfaces[surfaceName];

  return {
    id: key,
    type: findingStatus === 'not_covered'
      ? 'conformance_runner_coverage_gap'
      : findingStatus === 'runner_blocked'
        ? 'runner_gap'
        : 'product_gap',
    severity: ['register_and_drop', 'stale_render', 'silent_success', 'silent_failure', 'corrupt'].includes(findingStatus)
      ? 'blocker'
      : 'tracking',
    surface: surfaceName,
    owning_surface: ownerForFinding(surfaceName, findingStatus),
    artifact_versions: context.artifactVersions,
    pairing_class: pairingClass,
    operation_group: 'pairing_matrix',
    observed_behavior: findingStatus,
    expected_behavior: pairingClasses[pairingClass].expected,
    request_response_evidence: firstCapture,
    next_acceptance_criterion: nextAcceptanceForFinding(surfaceName, findingStatus),
    link: `https://durable-workflow.github.io/conformance/findings/${key}`,
  };
}

function ownerForFinding(surfaceName, status) {
  if (status === 'not_covered' || status === 'runner_blocked') {
    return 'conformance_harness';
  }

  if (status === 'register_and_drop') {
    return 'worker_and_server_boundary';
  }

  if (status === 'stale_render') {
    return 'durable-workflow/waterline';
  }

  return surfaces[surfaceName]?.artifact
    ? `durable-workflow/${surfaces[surfaceName].artifact}`
    : 'conformance_harness';
}

function nextAcceptanceForFinding(surfaceName, status) {
  if (status === 'register_and_drop') {
    return 'Worker skew must register_refused or register_and_serve; it must never register and then drop tasks silently.';
  }

  if (status === 'stale_render') {
    return 'Waterline must show a compatibility banner or refuse to render when observer/server versions are outside the supported window.';
  }

  if (status === 'silent_success') {
    return 'Skewed requests must refuse loudly before mutating state.';
  }

  return `${surfaceName} skew evidence must name both versions, the compatibility window, and the next step.`;
}

function workerClassification(pairingClass, response, operationGroup = '') {
  if (operationGroup === 'cluster_info_probe') {
    return pairingClass === 'compatible' || pairingClass === 'backward_skew'
      ? 'register_and_serve'
      : 'register_refused';
  }

  if (isProtocolRefusal(response)) {
    return 'register_refused';
  }

  if (pairingClass === 'compatible' || pairingClass === 'backward_skew') {
    return 'register_and_serve';
  }

  return 'register_and_drop';
}

function waterlineClassification(pairingClass, response) {
  if (pairingClass === 'compatible') {
    return 'banner';
  }

  if (response.status >= 400) {
    return 'render_refused';
  }

  const bodyText = JSON.stringify(response.body ?? '').toLowerCase();
  if (bodyText.includes('compat') || bodyText.includes('version') || bodyText.includes('skew')) {
    return 'banner';
  }

  return 'stale_render';
}

function domSnapshotForWaterline(classification, response, pairingClass, context) {
  return {
    type: 'dom_snapshot',
    classification,
    pairing_class: pairingClass,
    server_version: context.observedServerVersion,
    status_code: response.status,
    body_excerpt: JSON.stringify(response.body ?? '').slice(0, 500),
  };
}

function loudRefusalContext(surfaceName, surfaceVersion, context, pairing, response) {
  return {
    surface: surfaceName,
    surface_version: surfaceVersion,
    server_version: context.observedServerVersion,
    compatibility_window: pairing.compatibilityWindow,
    protocol_or_manifest: surfaceName === 'workflow-worker' ? 'worker_protocol' : 'control_plane',
    next_step: 'Upgrade the older side, pin the client to the advertised range, or connect to a server that supports the requested protocol.',
    response_reason: response?.body?.reason ?? response?.reason ?? null,
  };
}

function isProtocolRefusal(response) {
  const reason = response?.body?.reason;
  return response.status === 400
    && (
      reason === 'missing_control_plane_version'
      || reason === 'unsupported_control_plane_version'
      || reason === 'missing_protocol_version'
      || reason === 'unsupported_protocol_version'
    );
}

function materializeRequest(template, runId, state = {}) {
  const parts = template.split(' ');
  const method = parts[0];
  let requestPath = parts.slice(1).join(' ');
  const replacements = {
    '{workflowId}': state.workflowId || `skew-${runId}`,
    '{runId}': state.runId || `run-${runId}`,
    '{signalName}': 'advance',
    '{queryName}': 'currentState',
    '{updateName}': 'approve',
    '{task}': state.taskId || `task-${runId}`,
    '{id}': state.scheduleId || `schedule-${runId}`,
  };

  for (const [from, to] of Object.entries(replacements)) {
    requestPath = requestPath.replaceAll(from, to);
  }

  return { method, path: requestPath };
}

function bodyForRequest(method, requestPath, runId, state = {}) {
  if (method !== 'POST' && method !== 'PUT' && method !== 'PATCH') {
    return undefined;
  }

  if (requestPath === '/api/workflows') {
    return {
      workflow_id: state.workflowId || `skew-${runId}`,
      workflow_type: 'skew_conformance_workflow',
      task_queue: 'skew-conformance',
      input: { source: 'skew-conformance' },
    };
  }

  if (requestPath.startsWith('/api/workflows/') && requestPath.includes('/signal/')) {
    return { payload: { source: 'skew-conformance' } };
  }

  if (requestPath.startsWith('/api/workflows/') && requestPath.includes('/query/')) {
    return { args: { source: 'skew-conformance' } };
  }

  if (requestPath.startsWith('/api/workflows/') && requestPath.includes('/update/')) {
    return { args: { source: 'skew-conformance' } };
  }

  if (requestPath.endsWith('/cancel') || requestPath.endsWith('/terminate')) {
    return { reason: 'skew conformance boundary probe' };
  }

  if (requestPath === '/api/schedules') {
    return {
      schedule_id: state.scheduleId || `schedule-${runId}`,
      spec: { intervals: [{ every: 'PT1M' }] },
      action: {
        type: 'start_workflow',
        workflow_type: 'skew_conformance_workflow',
        workflow_id: `scheduled-${state.workflowId || `skew-${runId}`}`,
        task_queue: 'skew-conformance',
      },
    };
  }

  if (requestPath.includes('/api/schedules/') && requestPath.endsWith('/trigger')) {
    return { overlap_policy: 'skip' };
  }

  if (requestPath === '/api/worker/register') {
    return {
      worker_id: state.workerId || `worker-${runId}`,
      task_queue: 'skew-conformance',
      workflows: [],
      activities: [],
    };
  }

  if (requestPath === '/api/worker/heartbeat') {
    return { worker_id: state.workerId || `worker-${runId}` };
  }

  if (requestPath === '/api/worker/workflow-tasks/poll') {
    return { worker_id: state.workerId || `worker-${runId}`, task_queue: 'skew-conformance', timeout_seconds: 0 };
  }

  if (requestPath.includes('/api/worker/workflow-tasks/') && requestPath.endsWith('/complete')) {
    return { worker_id: state.workerId || `worker-${runId}`, commands: [] };
  }

  if (requestPath.includes('/api/worker/workflow-tasks/') && requestPath.endsWith('/fail')) {
    return { worker_id: state.workerId || `worker-${runId}`, reason: 'skew conformance boundary probe' };
  }

  return { source: 'skew-conformance' };
}

async function requestJson(baseUrl, method, requestPath, headers, body = undefined) {
  const options = { method, headers };
  if (body !== undefined) {
    options.body = JSON.stringify(body);
  }

  const response = await fetch(`${baseUrl}${requestPath}`, options);
  const text = await response.text();
  const parsed = parseJson(text);
  return {
    status: response.status,
    headers: Object.fromEntries(response.headers.entries()),
    body: parsed ?? text,
  };
}

function extractServerVersion(clusterInfo) {
  return stringValue(clusterInfo?.version)
    || stringValue(clusterInfo?.server?.version)
    || stringValue(clusterInfo?.server_version)
    || null;
}

function extractProtocolManifestVersions(clusterInfo) {
  return {
    control_plane: stringValue(clusterInfo?.control_plane?.version),
    worker_protocol: stringValue(clusterInfo?.worker_protocol?.version),
    client_compatibility: stringValue(clusterInfo?.client_compatibility?.version),
    skew_refusal_matrix_contract: stringValue(clusterInfo?.skew_refusal_matrix_contract?.version),
  };
}

function compatibilityWindowReport(clusterInfo) {
  return {
    advertised: clusterInfo?.client_compatibility ?? null,
    control_plane: {
      supported_version: stringValue(clusterInfo?.control_plane?.version) || '2',
      enforced_header: stringValue(clusterInfo?.control_plane?.header) || 'X-Durable-Workflow-Control-Plane-Version',
      window: 'exact control-plane version match required',
    },
    worker_protocol: {
      supported_version: stringValue(clusterInfo?.worker_protocol?.version) || '1.8',
      enforced_header: stringValue(clusterInfo?.worker_protocol?.header) || 'X-Durable-Workflow-Protocol-Version',
      window: 'same major and client minor <= server minor',
    },
  };
}

function futureBoundaryReport(pairingResults, operationEvidence) {
  return {
    client: {
      surface: 'cli',
      pairing_class: 'forward_skew',
      outcome: pairingResults.cli.forward_skew.status,
      evidence_cells: Object.keys(operationEvidence.cli.forward_skew),
    },
    worker: {
      surface: 'workflow-worker',
      pairing_class: 'forward_skew',
      outcome: pairingResults['workflow-worker'].forward_skew.status,
      classification: pairingResults['workflow-worker'].forward_skew.worker_skew_classification,
      evidence_cells: Object.keys(operationEvidence['workflow-worker'].forward_skew),
    },
    observer: {
      surface: 'waterline',
      pairing_class: 'forward_skew',
      outcome: pairingResults.waterline.forward_skew.status,
      classification: pairingResults.waterline.forward_skew.waterline_skew_classification,
      evidence_cells: Object.keys(operationEvidence.waterline.forward_skew),
    },
    server: {
      surface: 'server',
      pairing_class: 'outside_window',
      outcome: pairingResults.cli.outside_window.status,
      evidence_cells: Object.keys(operationEvidence.cli.outside_window),
    },
  };
}

function artifactVersionsFromEnv() {
  const versions = artifactManifest.artifact_versions && typeof artifactManifest.artifact_versions === 'object'
    ? artifactManifest.artifact_versions
    : {};

  return {
    server: stringValue(versions.server) || envValue('DW_SERVER_VERSION'),
    cli: stringValue(versions.cli) || envValue('DW_CLI_VERSION'),
    'sdk-python': stringValue(versions['sdk-python']) || envValue('DW_PYTHON_SDK_VERSION'),
    workflow: stringValue(versions.workflow) || envValue('DW_WORKFLOW_PHP_VERSION') || envValue('DW_WORKFLOW_VERSION'),
    waterline: stringValue(versions.waterline) || envValue('DW_WATERLINE_VERSION'),
  };
}

function artifactSources() {
  const sources = artifactManifest.artifact_sources && typeof artifactManifest.artifact_sources === 'object'
    ? artifactManifest.artifact_sources
    : {};

  return {
    server: stringValue(sources.server) || (envValue('DW_SERVER_IMAGE') ? 'docker' : 'published_server_url'),
    cli: stringValue(sources.cli) || 'not_installed',
    'sdk-python': stringValue(sources['sdk-python']) || 'not_installed',
    workflow: stringValue(sources.workflow) || 'not_installed',
    waterline: stringValue(sources.waterline) || 'not_installed',
  };
}

function writeBlockedResult(reason, startedAt, finishedAt, artifactVersions = artifactVersionsFromEnv()) {
  fs.mkdirSync(resultDir, { recursive: true });
  const versions = {
    server: artifactVersions.server || null,
    cli: artifactVersions.cli || null,
    'sdk-python': artifactVersions['sdk-python'] || null,
    workflow: artifactVersions.workflow || null,
    waterline: artifactVersions.waterline || null,
  };
  const finding = {
    id: 'skew-runner-blocked',
    type: 'runner_gap',
    severity: 'tracking',
    owning_surface: 'conformance_harness',
    observed_behavior: reason,
    expected_behavior: 'Skew conformance runner can execute the full published-artifact matrix.',
    next_acceptance_criterion: 'Provide Docker or an existing published server URL plus concrete artifact versions for every required surface.',
  };
  const result = {
    schema: RESULT_SCHEMA,
    suite_schema: scenarioManifest.suite_schema ?? 'durable-workflow.v2.platform-conformance.suite',
    suite_version: suiteVersion,
    scenario_id: 'skew_refusal_matrix',
    required_scenarios: requiredScenarios,
    status: 'runner_blocked',
    outcome: 'runner_blocked',
    verdict: 'runner_blocked',
    started_at: startedAt,
    finished_at: finishedAt,
    generated_at: finishedAt,
    runner_blocked: true,
    runnerBlocked: true,
    blocked_reason: reason,
    artifact_versions: versions,
    published_artifact_versions: versions,
    resolved_artifact_versions: versions,
    surface_results: {},
    pairing_results: {},
    operation_evidence: {},
    request_response_captures: [],
    findings: [finding],
    finding_links: {
      runner_blocked: 'https://durable-workflow.github.io/conformance/findings/skew-runner-blocked',
    },
  };
  const metadata = {
    schema: METADATA_SCHEMA,
    suite_schema: scenarioManifest.suite_schema ?? 'durable-workflow.v2.platform-conformance.suite',
    suite_version: suiteVersion,
    started_at: startedAt,
    finished_at: finishedAt,
    generated_at: finishedAt,
    outcome: 'runner_blocked',
    runner_blocked: true,
    blocked_reason: reason,
    artifact_versions: versions,
    published_artifact_versions: versions,
    implementation_identity: {
      runner_repository: 'server',
      runner_path: 'scripts/conformance/skew-published-artifacts.sh',
    },
  };
  writeJson('pins.json', {
    schema: 'durable-workflow.v2.skew-refusal-matrix.pins',
    suite_version: suiteVersion,
    artifact_versions: versions,
    published_artifact_versions: versions,
    artifact_sources: artifactSources(),
    local_product_source_checkouts_used: false,
  });
  writeJson('run-metadata.json', metadata);
  writeJson('request-response-captures.json', {
    schema: CAPTURE_SCHEMA,
    suite_version: suiteVersion,
    generated_at: finishedAt,
    captures: [],
  });
  writeJson('skew-result.json', result);
  writeJson('skew-record.json', {
    schema: RECORD_SCHEMA,
    suite_version: suiteVersion,
    outcome: 'runner_blocked',
    runnerBlocked: true,
    artifactVersions: versions,
    record: result,
  });
}

function readJsonIfExists(filePath) {
  try {
    return JSON.parse(fs.readFileSync(filePath, 'utf8'));
  } catch {
    return null;
  }
}

function writeJson(fileName, value) {
  fs.writeFileSync(path.join(resultDir, fileName), `${JSON.stringify(value, null, 2)}\n`);
}

function parseJson(value) {
  try {
    return JSON.parse(value);
  } catch {
    return null;
  }
}

function requiredEnv(name) {
  const value = envValue(name);
  if (!value) {
    throw new Error(`${name} is required`);
  }

  return value;
}

function envValue(name) {
  const value = process.env[name];
  return typeof value === 'string' && value.trim() !== '' ? value.trim() : '';
}

function isPlaceholderVersion(value) {
  const normalized = String(value).trim().toLowerCase();
  return [
    'latest',
    'current',
    'head',
    'unresolved',
    'placeholder',
    '<latest>',
    '${version}',
    '{{ version }}',
  ].includes(normalized);
}

function isExactSemverVersion(value) {
  return /^[0-9]+\.[0-9]+\.[0-9]+(?:[.-][0-9A-Za-z.-]+)?$/.test(String(value).trim());
}

function stringValue(value) {
  if (typeof value === 'string' && value.trim() !== '') {
    return value.trim();
  }

  if (typeof value === 'number' || typeof value === 'boolean') {
    return String(value);
  }

  return '';
}

function trimTrailingSlash(value) {
  return value.replace(/\/+$/, '');
}

function normalizeRequestKey(value) {
  return value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
}

function normalizeOperationRequest(value) {
  const parts = String(value).trim().replace(/\s+/g, ' ').split(' ');
  if (parts.length < 2) {
    return String(value).trim();
  }

  const method = parts[0].toUpperCase();
  let requestPath = parts.slice(1).join(' ');
  if (/^https?:\/\//i.test(requestPath)) {
    requestPath = new URL(requestPath).pathname;
  }
  requestPath = requestPath.split('#', 1)[0].split('?', 1)[0] || '/';

  return `${method} ${requestPath.startsWith('/') ? requestPath : `/${requestPath}`}`;
}

function redactHeaders(headers) {
  const redacted = {};
  for (const [key, value] of Object.entries(headers)) {
    redacted[key] = key.toLowerCase() === 'authorization' ? 'Bearer <redacted>' : value;
  }

  return redacted;
}

function timestamp() {
  return new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
}
