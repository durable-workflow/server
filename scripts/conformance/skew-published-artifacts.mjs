#!/usr/bin/env node
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import process from 'node:process';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { isExactPythonRelease, isExactSemverRelease } from './version-identities.mjs';

const RESULT_SCHEMA = 'durable-workflow.v2.skew-refusal-matrix.result';
const METADATA_SCHEMA = 'durable-workflow.v2.skew-refusal-matrix.metadata';
const RECORD_SCHEMA = 'durable-workflow.v2.skew-refusal-matrix.record';
const CAPTURE_SCHEMA = 'durable-workflow.v2.skew-refusal-matrix.request-response-captures';

const modulePath = fileURLToPath(import.meta.url);
const repoRoot = process.env.DW_SKEW_REPO_ROOT
  ?? path.resolve(path.dirname(modulePath), '../..');
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
  'sdk-php': [
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
  'sdk-php': {
    artifact: 'sdk-php',
    component: 'PHP SDK worker',
    versionEnv: 'DW_PHP_SDK_VERSION',
    versionField: 'sdk_php_version',
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

const pairingClassNames = [
  'compatible',
  'backward_skew',
  'forward_skew',
  'outside_window',
];

const portableWorkerAffinityCapabilities = [
  'local_activities',
  'worker_sessions',
  'sticky_execution',
];

export function deriveProtocolAuthority(clusterInfo) {
  const controlPlaneVersion = requiredManifestVersion(
    clusterInfo?.control_plane?.version,
    'control_plane.version',
    /^\d+$/,
  );
  const workerProtocolVersion = requiredManifestVersion(
    clusterInfo?.worker_protocol?.version,
    'worker_protocol.version',
    /^\d+\.\d+$/,
  );
  const compatibilityControlPlaneVersion = stringValue(
    clusterInfo?.client_compatibility?.required_protocols?.control_plane?.version,
  );
  const compatibilityWorkerProtocolVersion = stringValue(
    clusterInfo?.client_compatibility?.required_protocols?.worker_protocol?.version,
  );
  assertMatchingManifestVersion(
    controlPlaneVersion,
    compatibilityControlPlaneVersion,
    'control-plane',
  );
  assertMatchingManifestVersion(
    workerProtocolVersion,
    compatibilityWorkerProtocolVersion,
    'worker protocol',
  );

  const negotiation = clusterInfo
    ?.surface_stability_contract
    ?.surface_families
    ?.worker_protocol
    ?.negotiation;
  if (!negotiation || typeof negotiation !== 'object' || Array.isArray(negotiation)) {
    throw new Error('published Server cluster manifest omitted worker protocol negotiation authority');
  }
  const advertisedWorkerProtocolVersion = requiredManifestVersion(
    negotiation.default_advertised_version,
    'surface_stability_contract.surface_families.worker_protocol.negotiation.default_advertised_version',
    /^\d+\.\d+$/,
  );
  assertMatchingManifestVersion(
    workerProtocolVersion,
    advertisedWorkerProtocolVersion,
    'worker protocol negotiation',
  );
  if (stringValue(negotiation.request_header_rule) !== 'same_major_and_minor_less_than_or_equal_to_advertised') {
    throw new Error('published Server cluster manifest advertised an unsupported worker protocol negotiation rule');
  }

  const acceptedWorkerProtocolVersions = Array.isArray(negotiation.accepted_request_versions_by_default)
    ? negotiation.accepted_request_versions_by_default.map((version, index) => requiredManifestVersion(
      version,
      `surface_stability_contract.surface_families.worker_protocol.negotiation.accepted_request_versions_by_default.${index}`,
      /^\d+\.\d+$/,
    ))
    : [];
  const currentWorkerProtocol = parseProtocolVersion(workerProtocolVersion);
  if (acceptedWorkerProtocolVersions.length === 0
    || acceptedWorkerProtocolVersions.some((version) => {
      const accepted = parseProtocolVersion(version);

      return accepted === null
        || accepted.major !== currentWorkerProtocol.major
        || accepted.minor > currentWorkerProtocol.minor;
    })) {
    throw new Error('published Server cluster manifest advertised an invalid worker protocol negotiation range');
  }
  if (!acceptedWorkerProtocolVersions.includes(workerProtocolVersion)) {
    throw new Error('published Server cluster manifest worker protocol negotiation omits its advertised version');
  }

  const portableWorkerAffinityMinimum = requiredManifestVersion(
    clusterInfo?.worker_protocol?.server_capabilities?.local_activities?.minimum_worker_protocol_version,
    'worker_protocol.server_capabilities.local_activities.minimum_worker_protocol_version',
    /^\d+\.\d+$/,
  );
  const stickyExecutionMinimum = requiredManifestVersion(
    clusterInfo?.worker_protocol?.server_capabilities?.sticky_execution?.minimum_worker_protocol_version,
    'worker_protocol.server_capabilities.sticky_execution.minimum_worker_protocol_version',
    /^\d+\.\d+$/,
  );
  assertMatchingManifestVersion(
    portableWorkerAffinityMinimum,
    stickyExecutionMinimum,
    'portable worker affinity',
  );
  if (!acceptedWorkerProtocolVersions.includes(portableWorkerAffinityMinimum)) {
    throw new Error('published Server cluster manifest portable worker affinity floor is outside its worker protocol negotiation range');
  }

  const controlPlaneHeader = stringValue(clusterInfo?.control_plane?.header)
    || stringValue(clusterInfo?.client_compatibility?.required_protocols?.control_plane?.header);
  const workerProtocolHeader = stringValue(clusterInfo?.worker_protocol?.header)
    || stringValue(clusterInfo?.client_compatibility?.required_protocols?.worker_protocol?.header);
  if (!controlPlaneHeader || !workerProtocolHeader) {
    throw new Error('published Server cluster manifest omitted protocol request header authority');
  }

  return {
    source: 'GET /api/cluster/info',
    prerelease_package_policy: 'exact_current_tuple_only',
    control_plane: {
      version: controlPlaneVersion,
      header: controlPlaneHeader,
      request_rule: 'exact_match',
    },
    worker_protocol: {
      version: workerProtocolVersion,
      header: workerProtocolHeader,
      request_rule: 'same_major_and_minor_less_than_or_equal_to_advertised',
      accepted_versions: acceptedWorkerProtocolVersions,
      portable_worker_affinity: {
        minimum_protocol_version: portableWorkerAffinityMinimum,
        capabilities: portableWorkerAffinityCapabilities,
      },
    },
  };
}

export function rawWorkerTaskFixtureRegistrationPayload({
  workerId,
  taskQueue,
  runtime,
  protocolAuthority,
}) {
  const affinity = protocolAuthority?.worker_protocol?.portable_worker_affinity;
  const minimumProtocolVersion = requiredManifestVersion(
    affinity?.minimum_protocol_version,
    'worker_protocol.portable_worker_affinity.minimum_protocol_version',
    /^\d+\.\d+$/,
  );
  const capabilities = Array.isArray(affinity?.capabilities)
    ? affinity.capabilities
    : [];
  if (capabilities.length !== portableWorkerAffinityCapabilities.length
    || portableWorkerAffinityCapabilities.some((capability) => !capabilities.includes(capability))) {
    throw new Error('published Server protocol authority omitted the portable worker affinity capability set');
  }

  return {
    worker_id: workerId,
    task_queue: taskQueue,
    runtime,
    supported_workflow_types: ['skew_conformance_workflow'],
    supported_activity_types: [],
    capability_manifest: Object.fromEntries(capabilities.map((capability) => [capability, {
      supported: false,
      minimum_protocol_version: minimumProtocolVersion,
      reason: 'skew_fixture_uses_cold_durable_replay',
    }])),
  };
}

export function pairingClassesForAuthority(protocolAuthority) {
  const controlPlane = parseControlPlaneVersion(protocolAuthority?.control_plane?.version);
  const workerProtocol = parseProtocolVersion(protocolAuthority?.worker_protocol?.version);
  if (controlPlane === null || workerProtocol === null) {
    throw new Error('skew protocol authority contains malformed current protocol versions');
  }

  const acceptedWorkerProtocolVersions = (Array.isArray(protocolAuthority?.worker_protocol?.accepted_versions)
    ? protocolAuthority.worker_protocol.accepted_versions
    : [])
    .map((version) => ({ version: stringValue(version), parsed: parseProtocolVersion(version) }))
    .filter(({ parsed }) => parsed !== null
      && parsed.major === workerProtocol.major
      && parsed.minor < workerProtocol.minor)
    .sort((left, right) => right.parsed.minor - left.parsed.minor);
  const supportedOlderWorkerProtocol = acceptedWorkerProtocolVersions[0]?.version;
  if (!supportedOlderWorkerProtocol) {
    throw new Error('published Server protocol authority has no supported older worker protocol for negotiation coverage');
  }

  const futureControlPlaneVersion = String(controlPlane + 1);
  const unsupportedControlPlaneMajor = String(controlPlane + 2);
  const futureWorkerProtocolVersion = `${workerProtocol.major}.${workerProtocol.minor + 1}`;
  const unsupportedWorkerProtocolMajor = `${workerProtocol.major + 1}.0`;
  const currentWorkerProtocolVersion = protocolAuthority.worker_protocol.version;
  const currentControlPlaneVersion = protocolAuthority.control_plane.version;
  const protocolPolicy = [
    `control-plane ${currentControlPlaneVersion} requires an exact match`,
    `worker protocol accepts ${protocolAuthority.worker_protocol.accepted_versions.join(', ')}`,
    'prerelease package compatibility is not asserted',
  ].join('; ');

  return {
    compatible: {
      label: `exact-current_control-plane-${currentControlPlaneVersion}_worker-${currentWorkerProtocolVersion}`,
      controlPlaneVersion: currentControlPlaneVersion,
      workerProtocolVersion: currentWorkerProtocolVersion,
      expected: 'the exact published artifact tuple registers and serves using the Server-advertised protocols',
      compatibilityWindow: `exact current tuple; ${protocolPolicy}`,
      protocolSupport: 'exact_current',
    },
    backward_skew: {
      label: `supported-worker-negotiation-${supportedOlderWorkerProtocol}_control-plane-refusal-${controlPlane - 1}`,
      controlPlaneVersion: String(controlPlane - 1),
      workerProtocolVersion: supportedOlderWorkerProtocol,
      expected: 'the supported older worker protocol serves while the non-current control-plane shape refuses before mutation',
      compatibilityWindow: `protocol negotiation only; ${protocolPolicy}`,
      protocolSupport: 'supported_worker_protocol_only',
    },
    forward_skew: {
      label: `future-protocol_control-plane-${futureControlPlaneVersion}_worker-${futureWorkerProtocolVersion}`,
      controlPlaneVersion: futureControlPlaneVersion,
      workerProtocolVersion: futureWorkerProtocolVersion,
      expected: 'future protocol shapes refuse before mutation, registration, lease, or dropped work',
      compatibilityWindow: `unsupported future protocol shape; ${protocolPolicy}`,
      protocolSupport: 'unsupported_future',
    },
    outside_window: {
      label: `unsupported-major_control-plane-${unsupportedControlPlaneMajor}_worker-${unsupportedWorkerProtocolMajor}`,
      controlPlaneVersion: unsupportedControlPlaneMajor,
      workerProtocolVersion: unsupportedWorkerProtocolMajor,
      expected: 'unsupported protocol majors refuse before mutation, registration, lease, dropped work, or stale render',
      compatibilityWindow: `unsupported protocol major; ${protocolPolicy}`,
      protocolSupport: 'unsupported_major',
    },
  };
}

function requiredManifestVersion(value, pathName, pattern) {
  const version = stringValue(value);
  if (!pattern.test(version)) {
    throw new Error(`published Server cluster manifest omitted or malformed ${pathName}`);
  }

  return version;
}

function assertMatchingManifestVersion(current, candidate, authorityName) {
  if (!candidate) {
    throw new Error(`published Server client compatibility manifest omitted ${authorityName} authority`);
  }
  if (current !== candidate) {
    throw new Error(`published Server cluster manifests disagree on ${authorityName}: ${current} versus ${candidate}`);
  }
}

function parseControlPlaneVersion(value) {
  return /^\d+$/.test(String(value ?? '')) ? Number.parseInt(value, 10) : null;
}

const workflowWorkerDependentRequests = new Set([
  'POST /api/workflows/{workflowId}/query/{queryName}',
  'POST /api/workflows/{workflowId}/update/{updateName}',
  'POST /api/workflows/{workflowId}/runs/{runId}/query/{queryName}',
  'POST /api/workflows/{workflowId}/runs/{runId}/update/{updateName}',
]);

const workflowTaskCompletionRequests = new Set([
  'POST /api/worker/workflow-tasks/{task}/complete',
  'POST /api/worker/workflow-tasks/{task}/fail',
]);

const workerTaskFixtureRequests = new Set([
  'POST /api/worker/workflow-tasks/poll',
  'POST /api/worker/workflow-tasks/{task}/complete',
  'POST /api/worker/workflow-tasks/{task}/fail',
]);

const mutationBearingRequests = new Set([
  'POST /api/workflows',
  'POST /api/workflows/{workflowId}/signal/{signalName}',
  'POST /api/workflows/{workflowId}/update/{updateName}',
  'POST /api/workflows/{workflowId}/runs/{runId}/signal/{signalName}',
  'POST /api/workflows/{workflowId}/runs/{runId}/update/{updateName}',
  'POST /api/workflows/{workflowId}/cancel',
  'POST /api/workflows/{workflowId}/terminate',
  'POST /api/worker/register',
  'POST /api/worker/heartbeat',
  'POST /api/worker/workflow-tasks/poll',
  'POST /api/worker/workflow-tasks/{task}/complete',
  'POST /api/worker/workflow-tasks/{task}/fail',
  'POST /api/schedules',
  'POST /api/schedules/{id}/trigger',
]);

if (isMainModule()) {
  main().catch((error) => {
    const now = timestamp();
    const reason = error instanceof Error ? error.message : String(error);
    writeBlockedResult(reason, now, now);
    process.exitCode = 0;
  });
}

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
    .filter(([name, value]) => value && (
      name === 'sdk-python' ? !isExactPythonRelease(value) : !isExactSemverVersion(value)
    ))
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

  const protocolAuthority = deriveProtocolAuthority(clusterInfo.body);
  const pairingClasses = pairingClassesForAuthority(protocolAuthority);
  const protocolManifestVersions = extractProtocolManifestVersions(clusterInfo.body);

  const context = {
    artifactVersions,
    baseHeaders,
    namespace,
    observedServerVersion,
    pairingClasses,
    protocolAuthority,
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
      required_pairing_classes: pairingClassNames,
      protocol_authority: protocolAuthority,
      operation_groups: surface.operationGroups,
      status: 'pass',
    };
    pairingResults[surfaceName] = {};
    operationEvidence[surfaceName] = {};

    for (const pairingClass of pairingClassNames) {
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
  const compatibilityWindows = compatibilityWindowReport(protocolAuthority);
  const futureVersionBoundary = futureBoundaryReport(
    pairingResults,
    operationEvidence,
    protocolAuthority,
    pairingClasses,
  );

  const pins = {
    schema: 'durable-workflow.v2.skew-refusal-matrix.pins',
    suite_version: suiteVersion,
    artifact_versions: artifactVersions,
    published_artifact_versions: artifactVersions,
    artifact_sources: artifactSources(),
    protocol_authority: protocolAuthority,
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
    protocol_authority: protocolAuthority,
    protocol_pairing_classes: pairingClasses,
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
    protocol_authority: protocolAuthority,
    protocol_pairing_classes: pairingClasses,
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
  const pairing = context.pairingClasses[pairingClass];
  const state = pairingState(context, surfaceName, pairingClass);
  let { method, path: requestPath } = materializeRequest(
    requestTemplate,
    context.runId,
    state,
  );
  const surfaceVersion = context.artifactVersions[surface.artifact];
  const nextStep = compatibilityNextStep(surfaceName, pairingClass, context);

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
      context,
      status: availability.status,
      reason: availability.reason,
    });
  }

  const fixtureGap = await prepareOperationFixture({
    surfaceName,
    pairingClass,
    operationGroup,
    requestTemplate,
    context,
    state,
  });
  ({ method, path: requestPath } = materializeRequest(
    requestTemplate,
    context.runId,
    state,
  ));
  if (fixtureGap) {
    const optionalGap = compatibleOptionalCoverageGap(surfaceName, pairingClass, operationGroup, requestTemplate);
    return notCoveredProbe({
      surfaceName,
      surface,
      pairingClass,
      operationGroup,
      requestTemplate,
      method,
      requestPath,
      context,
      status: fixtureGap.status,
      reason: fixtureGap.reason,
      optionalCoverageGap: fixtureGap.optional_coverage_gap === true || optionalGap !== null,
      coverageGapScope: fixtureGap.coverage_gap_scope ?? optionalGap?.coverage_gap_scope,
    });
  }

  const workerTaskGap = workerTaskCompletionGap({
    pairingClass,
    operationGroup,
    requestTemplate,
    context,
    state,
  });
  if (workerTaskGap) {
    const optionalGap = compatibleOptionalCoverageGap(surfaceName, pairingClass, operationGroup, requestTemplate);
    return notCoveredProbe({
      surfaceName,
      surface,
      pairingClass,
      operationGroup,
      requestTemplate,
      method,
      requestPath,
      context,
      status: workerTaskGap.status,
      reason: workerTaskGap.reason,
      optionalCoverageGap: optionalGap !== null,
      coverageGapScope: optionalGap?.coverage_gap_scope,
    });
  }

  const refusalMutationProbe = await beginRefusalMutationProbe({
    surfaceName,
    pairingClass,
    operationGroup,
    requestTemplate,
    context,
    state,
  });

  const invocation = await invokeSurfaceOperation({
    surfaceName,
    surface,
    pairingClass,
    operationGroup,
    requestTemplate,
    method,
    requestPath,
    context,
    clusterInfo,
  });

  if (invocation.wire_evidence_gap) {
    const optionalGap = compatibleOptionalCoverageGap(surfaceName, pairingClass, operationGroup, requestTemplate);
    return notCoveredProbe({
      surfaceName,
      surface,
      pairingClass,
      operationGroup,
      requestTemplate,
      method,
      requestPath,
      context,
      status: invocation.wire_evidence_gap.status,
      reason: invocation.wire_evidence_gap.reason,
      artifactInvocation: invocation.artifact_invocation,
      proxyCaptures: invocation.proxy_captures,
      optionalCoverageGap: optionalGap !== null,
      coverageGapScope: optionalGap?.coverage_gap_scope,
    });
  }

  const matchedCapture = invocation.matched_proxy_capture;
  const wireRequest = normalizedCaptureRequest(matchedCapture);
  const wireResponse = normalizedCaptureResponse(matchedCapture);
  const evidenceRequest = invocation.evidence_request ?? wireRequest;
  const response = invocation.response;
  const refusalMutationEvidence = await completeRefusalMutationProbe(
    refusalMutationProbe,
    context,
  );
  const protocolGap = protocolEvidenceGap(
    operationGroup,
    pairing,
    wireRequest,
    context.protocolAuthority,
  );
  const optionalProtocolGap = protocolGap
    ? compatibleOptionalCoverageGap(surfaceName, pairingClass, operationGroup, requestTemplate)
    : null;

  const status = protocolGap
    ? 'not_covered'
    : classifyEvidenceStatus({
      surfaceName,
      pairingClass,
      operationGroup,
      requestTemplate,
      response,
      refusalMutationEvidence,
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
    ...protocolScenarioFields(pairing),
    compatibility_window: pairing.compatibilityWindow,
    next_step: nextStep,
    request: wireRequest,
    response: wireResponse,
    artifact_invocation: invocation.artifact_invocation,
    proxy_captures: invocation.proxy_captures,
  };
  if (refusalMutationEvidence) {
    capture.refusal_state_evidence = refusalMutationEvidence;
  }
  if (surfaceName === 'sdk-php') {
    capture.worker_version = surfaceVersion;
    capture.sdk_php_package_version = surfaceVersion;
    capture.worker_protocol_version = pairing.workerProtocolVersion;
  }

  let evidence;
  if (operationGroup === 'cluster_info_probe') {
    evidence = {
      surface: surfaceName,
      pairing_class: pairingClass,
      ...protocolScenarioFields(pairing),
      operation_group: operationGroup,
      request: `${evidenceRequest.method} ${evidenceRequest.path}`,
      status,
      status_code: response.status,
      response_body: response.body,
      client_or_observer_version: surfaceVersion,
      server_version: context.observedServerVersion,
      protocol_manifest_versions: context.protocolManifestVersions,
      compatibility_window: pairing.compatibilityWindow,
      next_step: nextStep,
      request_response_capture_id: captureId,
      artifact_invocation: invocation.artifact_invocation,
    };
  } else if (operationGroup === 'waterline_render') {
    const classification = status === 'not_covered' || status === 'runner_blocked'
      ? null
      : waterlineClassification(pairingClass, response);
    evidence = {
      surface: surfaceName,
      pairing_class: pairingClass,
      ...protocolScenarioFields(pairing),
      operation_group: operationGroup,
      request: `${evidenceRequest.method} ${evidenceRequest.path}`,
      response_status: response.status,
      response_body: response.body,
      screenshot_or_dom_snapshot: domSnapshotForWaterline(classification ?? status, response, pairingClass, context),
      server_version: context.observedServerVersion,
      waterline_version: surfaceVersion,
      status,
      compatibility_window: pairing.compatibilityWindow,
      next_step: nextStep,
      request_response_capture_id: captureId,
      artifact_invocation: invocation.artifact_invocation,
    };
    if (classification) {
      evidence.waterline_skew_classification = classification;
    }
  } else {
    evidence = {
      surface: surfaceName,
      pairing_class: pairingClass,
      ...protocolScenarioFields(pairing),
      operation_group: operationGroup,
      request_method: evidenceRequest.method,
      request_path: evidenceRequest.path,
      request_headers: wireRequest.headers,
      request_body: wireRequest.body ?? null,
      response_status: response.status,
      response_headers: response.headers,
      response_body: response.body,
      client_or_worker_version: surfaceVersion,
      server_version: context.observedServerVersion,
      compatibility_window: pairing.compatibilityWindow,
      next_step: nextStep,
      status,
      request_response_capture_id: captureId,
      artifact_invocation: invocation.artifact_invocation,
    };
  }

  if (invocation.guard_proxy_capture) {
    evidence.guard_request = `${wireRequest.method} ${wireRequest.path}`;
    evidence.guard_response_status = wireResponse.status;
    evidence.guard_response_body = wireResponse.body;
    evidence.wire_request_method = wireRequest.method;
    evidence.wire_request_path = wireRequest.path;
    evidence.advertised_request = `${method} ${requestPath}`;
    capture.advertised_request = {
      method,
      path: requestPath,
    };
    capture.guard_proxy_capture = invocation.guard_proxy_capture.id;
  }

  if (isProtocolRefusal(response)) {
    evidence.refusal_requirements_met = refusalRequirements[surfaceName];
    evidence.refusal_context = loudRefusalContext(surfaceName, surfaceVersion, context, pairing, response);
  }

  if (refusalMutationEvidence) {
    evidence.refusal_state_evidence = refusalMutationEvidence;
  }

  const interopClassification = compatibleControlPlaneInteropClassification({
    surfaceName,
    pairingClass,
    operationGroup,
    response,
  });
  if (interopClassification) {
    evidence.interop_classification = interopClassification;
    capture.interop_classification = evidence.interop_classification;
  }

  if (surfaceName === 'sdk-python') {
    evidence.sdk_python_version = surfaceVersion;
    evidence.sdk_version = surfaceVersion;
    evidence.typed_sdk_evidence = true;
    evidence.sdk_operation = requestTemplate;
    capture.sdk_python_version = surfaceVersion;
    capture.sdk_version = surfaceVersion;
    capture.typed_sdk_evidence = true;
    capture.sdk_operation = requestTemplate;
  }

  if (protocolGap) {
    evidence.coverage_gap_reason = protocolGap.reason;
    evidence.expected_protocol_header = protocolGap.header;
    evidence.expected_protocol_version = protocolGap.expected;
    evidence.observed_protocol_version = protocolGap.observed;
  }

  if (optionalProtocolGap) {
    evidence.optional_coverage_gap = true;
    evidence.coverage_requirement = 'optional';
    evidence.coverage_gap_scope = optionalProtocolGap.coverage_gap_scope;
    capture.optional_coverage_gap = true;
    capture.coverage_requirement = 'optional';
    capture.coverage_gap_scope = optionalProtocolGap.coverage_gap_scope;
  }

  const coverageGapReason = waterlineCoverageGapReason(operationGroup, status, response);
  if (coverageGapReason && !evidence.coverage_gap_reason) {
    evidence.coverage_gap_reason = coverageGapReason;
  }

  if (surfaceName === 'sdk-php') {
    evidence.worker_version = surfaceVersion;
    evidence.sdk_php_package_version = surfaceVersion;
    evidence.worker_protocol_version = pairing.workerProtocolVersion;
    evidence.worker_skew_classification = workerClassification(
      pairingClass,
      response,
      operationGroup,
      refusalMutationEvidence,
    );
  }

  if (surfaceName === 'waterline' && status !== 'not_covered' && status !== 'runner_blocked') {
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
  context,
  status,
  reason,
  artifactInvocation = null,
  proxyCaptures = [],
  optionalCoverageGap = false,
  coverageGapScope = '',
}) {
  const surfaceVersion = context.artifactVersions[surface.artifact];
  const pairing = context.pairingClasses[pairingClass];
  const nextStep = compatibilityNextStep(surfaceName, pairingClass, context);
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
  if (optionalCoverageGap) {
    response.body.optional_coverage_gap = true;
    response.body.coverage_gap_scope = coverageGapScope || defaultCompatibleCoverageGapScope(surfaceName);
  }
  const capture = {
    id: captureId,
    surface: surfaceName,
    pairing_class: pairingClass,
    operation_group: operationGroup,
    artifact_versions: context.artifactVersions,
    client_or_worker_version: surfaceVersion,
    server_version: context.observedServerVersion,
    ...protocolScenarioFields(pairing),
    compatibility_window: pairing.compatibilityWindow,
    next_step: nextStep,
    request: {
      method,
      path: requestPath,
      headers: {},
      body: null,
      not_observed: true,
      not_observed_reason: reason,
    },
    response,
    artifact_invocation: artifactInvocation ?? {
      status,
      reason,
      surface: surfaceName,
      artifact: surface.artifact,
    },
    proxy_captures: proxyCaptures,
  };
  if (optionalCoverageGap) {
    capture.optional_coverage_gap = true;
    capture.coverage_requirement = 'optional';
    capture.coverage_gap_scope = coverageGapScope || defaultCompatibleCoverageGapScope(surfaceName);
  }
  if (surfaceName === 'sdk-php') {
    capture.worker_version = surfaceVersion;
    capture.sdk_php_package_version = surfaceVersion;
    capture.worker_protocol_version = pairing.workerProtocolVersion;
  }

  let evidence;
  if (operationGroup === 'cluster_info_probe') {
    evidence = {
      surface: surfaceName,
      pairing_class: pairingClass,
      ...protocolScenarioFields(pairing),
      operation_group: operationGroup,
      request: `${method} ${requestPath}`,
      status,
      status_code: 0,
      response_body: response.body,
      client_or_observer_version: surfaceVersion,
      server_version: context.observedServerVersion,
      protocol_manifest_versions: context.protocolManifestVersions,
      compatibility_window: pairing.compatibilityWindow,
      next_step: nextStep,
      request_response_capture_id: captureId,
      coverage_gap_reason: reason,
      artifact_invocation: artifactInvocation ?? undefined,
    };
  } else if (operationGroup === 'waterline_render') {
    evidence = {
      surface: surfaceName,
      pairing_class: pairingClass,
      ...protocolScenarioFields(pairing),
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
      next_step: nextStep,
      request_response_capture_id: captureId,
      coverage_gap_reason: reason,
      artifact_invocation: artifactInvocation ?? undefined,
    };
  } else {
    evidence = {
      surface: surfaceName,
      pairing_class: pairingClass,
      ...protocolScenarioFields(pairing),
      operation_group: operationGroup,
      request_method: method,
      request_path: requestPath,
      request_headers: {},
      request_body: null,
      response_status: 0,
      response_headers: {},
      response_body: response.body,
      client_or_worker_version: surfaceVersion,
      server_version: context.observedServerVersion,
      compatibility_window: pairing.compatibilityWindow,
      next_step: nextStep,
      status,
      request_response_capture_id: captureId,
      coverage_gap_reason: reason,
      artifact_invocation: artifactInvocation ?? undefined,
    };
  }

  if (surfaceName === 'sdk-php') {
    evidence.worker_version = surfaceVersion;
    evidence.sdk_php_package_version = surfaceVersion;
    evidence.worker_protocol_version = pairing.workerProtocolVersion;
  }

  if (optionalCoverageGap) {
    evidence.optional_coverage_gap = true;
    evidence.coverage_requirement = 'optional';
    evidence.coverage_gap_scope = coverageGapScope || defaultCompatibleCoverageGapScope(surfaceName);
  }

  return { evidence, capture };
}

function compatibleOptionalCoverageGap(surfaceName, pairingClass, operationGroup, requestTemplate) {
  if (pairingClass !== 'compatible') {
    return null;
  }

  if (
    surfaceName === 'cli'
    && operationGroup === 'workflow_control_plane'
    && requestTemplate === 'GET /api/workflows/{workflowId}/runs/{runId}/history'
  ) {
    return {
      optional_coverage_gap: true,
      coverage_gap_scope: 'compatible_cli_inside_window_interop',
    };
  }

  if (
    surfaceName === 'sdk-python'
    && ['workflow_control_plane', 'schedule_control_plane', 'worker_lifecycle'].includes(operationGroup)
  ) {
    return {
      optional_coverage_gap: true,
      coverage_gap_scope: 'compatible_sdk_python_inside_window_interop',
    };
  }

  return null;
}

function defaultCompatibleCoverageGapScope(surfaceName) {
  return surfaceName === 'sdk-python'
    ? 'compatible_sdk_python_inside_window_interop'
    : 'compatible_cli_inside_window_interop';
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
    surface_url: surfaceName === 'waterline' ? waterlineSurfaceUrlFor(record) || null : null,
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

  if (surfaceName === 'sdk-php') {
    const appDir = stringValue(record.app_dir) || envValue('DW_SKEW_PHP_SDK_APP_DIR');
    if (appDir && fs.existsSync(path.join(appDir, 'vendor/autoload.php'))) {
      if (executableOnPath('docker')) {
        return { available: true, appDir, phpImage: envValue('DW_SKEW_PHP_IMAGE') || 'composer:2' };
      }

      return {
        available: false,
        status: 'runner_blocked',
        reason: 'Docker is required to run the published durable-workflow/sdk probe through its Composer-installed package.',
      };
    }

    return {
      available: false,
      status: 'runner_blocked',
      reason: 'PHP SDK artifact install completed without vendor/autoload.php for the Composer-installed package.',
    };
  }

  if (surfaceName === 'waterline') {
    const appDir = stringValue(record.app_dir) || envValue('DW_SKEW_WATERLINE_APP_DIR');
    const surfaceUrl = waterlineSurfaceUrlFor(record);
    if (!surfaceUrl) {
      return {
        available: false,
        status: 'not_covered',
        reason: [
          'Waterline render evidence requires DW_SKEW_WATERLINE_URL pointing at a running Composer-installed Waterline HTTP surface.',
          'Composer package install alone is not Waterline render evidence.',
        ].join(' '),
      };
    }

    if (!isHttpUrl(surfaceUrl)) {
      return {
        available: false,
        status: 'runner_blocked',
        reason: `DW_SKEW_WATERLINE_URL must be an http(s) URL for a running Waterline surface; got ${surfaceUrl}`,
      };
    }

    if (appDir && fs.existsSync(path.join(appDir, 'vendor/autoload.php'))) {
      if (executableOnPath('docker')) {
        return {
          available: true,
          appDir,
          surfaceUrl,
          phpImage: envValue('DW_SKEW_PHP_IMAGE') || 'composer:2',
        };
      }

      return {
        available: false,
        status: 'runner_blocked',
        reason: 'Docker is required to run the published durable-workflow/waterline probe through its Composer-installed package.',
      };
    }

    return {
      available: false,
      status: 'runner_blocked',
      reason: 'Waterline artifact install completed without vendor/autoload.php for the Composer-installed package.',
    };
  }

  return {
    available: false,
    status: 'not_covered',
    reason: `${surfaceName} does not have a published-artifact invoker`,
  };
}

function waterlineSurfaceUrlFor(record = {}) {
  const url = stringValue(record.surface_url)
    || stringValue(record.surfaceUrl)
    || envValue('DW_SKEW_WATERLINE_URL')
    || envValue('DW_SKEW_WATERLINE_BASE_URL');

  return url ? trimTrailingSlash(url) : '';
}

function waterlineFixtureRunId() {
  const record = artifactRecordForSurface('waterline');

  return stringValue(record.fixture_run_id)
    || stringValue(record.fixtureRunId)
    || envValue('DW_SKEW_WATERLINE_FIXTURE_RUN_ID');
}

function workerTaskCompletionGap({
  pairingClass,
  operationGroup,
  requestTemplate,
  context,
  state,
}) {
  if (
    operationGroup !== 'worker_lifecycle'
    || !workflowTaskCompletionRequests.has(requestTemplate)
    || state.taskId
  ) {
    return null;
  }

  if (!requiresPublishedWorkerTaskId(pairingClass, context)) {
    return null;
  }

  return {
    status: 'not_covered',
    reason: [
      `${requestTemplate} requires a workflow task id obtained from a successful fixture poll before completing or failing an inside-window task.`,
      'Protocol-refusal rows may use the advertised task placeholder only when the server must reject before task lookup.',
    ].join(' '),
  };
}

function requiresPublishedWorkerTaskId(pairingClass, context) {
  const pairing = context.pairingClasses[pairingClass];
  if (!pairing) {
    return true;
  }

  return workerProtocolCompatible(
    pairing.workerProtocolVersion,
    context.pairingClasses.compatible.workerProtocolVersion,
  );
}

function workerProtocolCompatible(workerProtocolVersion, serverWorkerProtocolVersion) {
  const worker = parseProtocolVersion(workerProtocolVersion);
  const server = parseProtocolVersion(serverWorkerProtocolVersion);
  if (worker === null || server === null) {
    return workerProtocolVersion === serverWorkerProtocolVersion;
  }

  return worker.major === server.major && worker.minor <= server.minor;
}

function parseProtocolVersion(value) {
  const match = String(value ?? '').match(/^(\d+)\.(\d+)$/);
  if (!match) {
    return null;
  }

  return {
    major: Number.parseInt(match[1], 10),
    minor: Number.parseInt(match[2], 10),
  };
}

function taskIdForPublishedWorkerProbe(state, pairingClass, context) {
  if (state.taskId) {
    return state.taskId;
  }

  return requiresPublishedWorkerTaskId(pairingClass, context)
    ? ''
    : 'poll-task-id-required';
}

async function prepareOperationFixture({
  surfaceName,
  pairingClass,
  operationGroup,
  requestTemplate,
  context,
  state,
}) {
  if (operationGroup === 'workflow_control_plane') {
    return prepareWorkflowFixture({
      surfaceName,
      pairingClass,
      requestTemplate,
      context,
      state,
    });
  }

  if (operationGroup === 'schedule_control_plane') {
    return prepareScheduleFixture({
      surfaceName,
      pairingClass,
      requestTemplate,
      context,
      state,
    });
  }

  if (operationGroup === 'worker_lifecycle') {
    return prepareWorkerTaskFixture({
      surfaceName,
      pairingClass,
      requestTemplate,
      context,
      state,
    });
  }

  return null;
}

async function prepareWorkerTaskFixture({
  surfaceName,
  pairingClass,
  requestTemplate,
  context,
  state,
}) {
  if (
    !workerTaskFixtureRequests.has(requestTemplate)
    || (surfaceName !== 'sdk-python' && surfaceName !== 'sdk-php')
  ) {
    return null;
  }

  const fixtureKey = [
    surfaceName,
    pairingClass,
    'worker-task',
    normalizeRequestKey(requestTemplate),
  ].join('.');
  context.operationFixtures ??= {};
  if (context.operationFixtures[fixtureKey]) {
    Object.assign(state, context.operationFixtures[fixtureKey]);
    return null;
  }

  const workerId = state.workerId;
  const taskQueue = 'skew-conformance';
  const workerHeaders = fixtureWorkerHeaders(context, 'compatible');

  let registerResponse;
  try {
    registerResponse = await requestJson(
      context.serverUrl,
      'POST',
      '/api/worker/register',
      workerHeaders,
      rawWorkerTaskFixtureRegistrationPayload({
        workerId,
        taskQueue,
        runtime: workerRuntimeForSurface(surfaceName),
        protocolAuthority: context.protocolAuthority,
      }),
    );
  } catch (error) {
    return {
      status: 'runner_blocked',
      reason: `Worker task fixture registration failed before ${requestTemplate}: ${error instanceof Error ? error.message : String(error)}`,
    };
  }

  if (registerResponse.status >= 400 || registerResponse.status === 0) {
    return {
      status: 'not_covered',
      reason: [
        `${requestTemplate} requires a workflow-capable worker registration before fixture polling.`,
        `Compatible worker fixture registration returned HTTP ${registerResponse.status}.`,
      ].join(' '),
    };
  }

  const workflowId = `skew-${context.runId}-${surfaceName.replace(/[^a-z0-9]+/gi, '-')}-${pairingClass}-worker-${normalizeRequestKey(requestTemplate)}`;
  let startResponse;
  try {
    startResponse = await requestJson(
      context.serverUrl,
      'POST',
      '/api/workflows',
      fixtureHeaders(context),
      {
        workflow_type: 'skew_conformance_workflow',
        workflow_id: workflowId,
        task_queue: taskQueue,
        input: [],
      },
    );
  } catch (error) {
    return {
      status: 'runner_blocked',
      reason: `Worker task fixture workflow start failed before ${requestTemplate}: ${error instanceof Error ? error.message : String(error)}`,
    };
  }

  if (startResponse.status >= 400 || startResponse.status === 0) {
    return {
      status: 'not_covered',
      reason: [
        `${requestTemplate} requires a queued workflow task before the published-artifact worker request is invoked.`,
        `Compatible worker task fixture start returned HTTP ${startResponse.status}.`,
      ].join(' '),
    };
  }

  const fixture = {
    workflowId,
    runId: workflowRunIdFromBody(startResponse.body),
  };

  if (requestTemplate === 'POST /api/worker/workflow-tasks/poll') {
    context.operationFixtures[fixtureKey] = fixture;
    Object.assign(state, fixture);
    return null;
  }

  let pollResponse;
  try {
    pollResponse = await pollWorkflowTaskFixture({
      context,
      surfaceName,
      pairingClass,
      workerHeaders,
      workerId,
      taskQueue,
      requestTemplate,
    });
  } catch (error) {
    return {
      status: 'runner_blocked',
      reason: `Worker task fixture poll failed before ${requestTemplate}: ${error instanceof Error ? error.message : String(error)}`,
    };
  }

  const taskId = workflowTaskIdFromBody(pollResponse.body);
  const workflowTaskAttempt = workflowTaskAttemptFromBody(pollResponse.body) ?? 1;
  if (pollResponse.status >= 400 || pollResponse.status === 0 || !taskId) {
    return {
      status: 'not_covered',
      reason: [
        `${requestTemplate} requires a workflow task id obtained from a compatible fixture poll.`,
        `Compatible worker task fixture poll returned HTTP ${pollResponse.status} without a task id.`,
      ].join(' '),
    };
  }

  fixture.taskId = taskId;
  fixture.workflowTaskAttempt = workflowTaskAttempt;
  context.operationFixtures[fixtureKey] = fixture;
  Object.assign(state, fixture);

  return null;
}

async function pollWorkflowTaskFixture({
  context,
  surfaceName,
  pairingClass,
  workerHeaders,
  workerId,
  taskQueue,
  requestTemplate,
}) {
  const attempts = Math.max(
    1,
    Math.min(30, Number.parseInt(process.env.DW_SKEW_WORKER_FIXTURE_POLL_ATTEMPTS ?? '10', 10) || 10),
  );
  const intervalMs = Math.max(
    50,
    Math.min(5000, Number.parseInt(process.env.DW_SKEW_WORKER_FIXTURE_POLL_INTERVAL_MS ?? '500', 10) || 500),
  );
  let lastResponse = null;

  for (let attempt = 1; attempt <= attempts; attempt += 1) {
    const response = await requestJson(
      context.serverUrl,
      'POST',
      '/api/worker/workflow-tasks/poll',
      workerHeaders,
      {
        worker_id: workerId,
        task_queue: taskQueue,
        poll_request_id: workerFixturePollRequestId(context, surfaceName, pairingClass, requestTemplate, attempt),
      },
    );
    lastResponse = response;

    if (response.status >= 400 || response.status === 0 || workflowTaskIdFromBody(response.body)) {
      return response;
    }

    if (attempt < attempts) {
      await sleep(intervalMs);
    }
  }

  return lastResponse;
}

function workerFixturePollRequestId(context, surfaceName, pairingClass, requestTemplate, attempt) {
  return [
    'fixture',
    context.runId,
    surfaceName,
    pairingClass,
    normalizeRequestKey(requestTemplate),
    attempt,
  ].join('-');
}

async function prepareWorkflowFixture({
  surfaceName,
  pairingClass,
  requestTemplate,
  context,
  state,
}) {
  if (
    requestTemplate === 'POST /api/workflows'
    || workflowWorkerDependentRequests.has(requestTemplate)
  ) {
    return null;
  }

  const fixtureKey = [
    surfaceName,
    pairingClass,
    'workflow',
    normalizeRequestKey(requestTemplate),
  ].join('.');
  context.operationFixtures ??= {};
  if (context.operationFixtures[fixtureKey]) {
    Object.assign(state, context.operationFixtures[fixtureKey]);
    return null;
  }

  const workflowId = `skew-${context.runId}-${surfaceName.replace(/[^a-z0-9]+/gi, '-')}-${pairingClass}-${normalizeRequestKey(requestTemplate)}`;
  let response;
  try {
    response = await requestJson(
      context.serverUrl,
      'POST',
      '/api/workflows',
      fixtureHeaders(context),
      {
        workflow_type: 'skew_conformance_workflow',
        workflow_id: workflowId,
        task_queue: 'skew-conformance',
        input: [],
      },
    );
  } catch (error) {
    return {
      status: 'runner_blocked',
      reason: `Compatible workflow fixture setup failed before ${requestTemplate}: ${error instanceof Error ? error.message : String(error)}`,
    };
  }

  if (response.status >= 400 || response.status === 0) {
    return {
      status: 'not_covered',
      reason: [
        `${requestTemplate} requires an active workflow fixture before the skewed artifact request is invoked.`,
        `Compatible fixture setup returned HTTP ${response.status}.`,
      ].join(' '),
    };
  }

  const fixture = {
    workflowId,
    runId: workflowRunIdFromBody(response.body),
  };
  if (requestTemplate.includes('{runId}') && !fixture.runId) {
    const gap = {
      status: 'not_covered',
      reason: `${requestTemplate} requires a workflow fixture run id, but the compatible fixture start response did not report one.`,
    };
    const optionalGap = compatibleOptionalCoverageGap(surfaceName, pairingClass, 'workflow_control_plane', requestTemplate);
    if (optionalGap) {
      gap.optional_coverage_gap = true;
      gap.coverage_gap_scope = optionalGap.coverage_gap_scope;
    }

    return gap;
  }

  context.operationFixtures[fixtureKey] = fixture;
  Object.assign(state, fixture);

  return null;
}

async function prepareScheduleFixture({
  surfaceName,
  pairingClass,
  requestTemplate,
  context,
  state,
}) {
  if (requestTemplate === 'POST /api/schedules') {
    return null;
  }

  const fixtureKey = [
    surfaceName,
    pairingClass,
    'schedule',
    normalizeRequestKey(requestTemplate),
  ].join('.');
  context.operationFixtures ??= {};
  if (context.operationFixtures[fixtureKey]) {
    Object.assign(state, context.operationFixtures[fixtureKey]);
    return null;
  }

  const scheduleId = `schedule-${context.runId}-${surfaceName.replace(/[^a-z0-9]+/gi, '-')}-${pairingClass}-${normalizeRequestKey(requestTemplate)}`;
  let response;
  try {
    response = await requestJson(
      context.serverUrl,
      'POST',
      '/api/schedules',
      fixtureHeaders(context),
      {
        schedule_id: scheduleId,
        spec: {
          intervals: [
            {
              every: 'PT1M',
            },
          ],
          timezone: 'UTC',
        },
        action: {
          workflow_type: 'skew_conformance_workflow',
          task_queue: 'skew-conformance',
          input: [],
        },
        overlap_policy: 'skip',
        paused: true,
      },
    );
  } catch (error) {
    return {
      status: 'runner_blocked',
      reason: `Compatible schedule fixture setup failed before ${requestTemplate}: ${error instanceof Error ? error.message : String(error)}`,
    };
  }

  if (response.status >= 400 || response.status === 0) {
    return {
      status: 'not_covered',
      reason: [
        `${requestTemplate} requires a schedule fixture before the skewed artifact request is invoked.`,
        `Compatible fixture setup returned HTTP ${response.status}.`,
      ].join(' '),
    };
  }

  const fixture = { scheduleId };
  context.operationFixtures[fixtureKey] = fixture;
  Object.assign(state, fixture);

  return null;
}

function fixtureHeaders(context) {
  return {
    ...context.baseHeaders,
    [context.protocolAuthority.control_plane.header]: context.pairingClasses.compatible.controlPlaneVersion,
    [context.protocolAuthority.worker_protocol.header]: context.pairingClasses.compatible.workerProtocolVersion,
  };
}

function fixtureWorkerHeaders(context, pairingClass) {
  return {
    ...context.baseHeaders,
    [context.protocolAuthority.worker_protocol.header]: context.pairingClasses[pairingClass]?.workerProtocolVersion
      ?? context.pairingClasses.compatible.workerProtocolVersion,
  };
}

function workerRuntimeForSurface(surfaceName) {
  return surfaceName === 'sdk-python' ? 'python' : 'php';
}

async function beginRefusalMutationProbe({
  surfaceName,
  pairingClass,
  operationGroup,
  requestTemplate,
  context,
  state,
}) {
  if (!requiresRefusalMutationEvidence(pairingClass, operationGroup, requestTemplate)) {
    return null;
  }

  const target = refusalMutationProbeTarget(operationGroup, requestTemplate, state);
  if (target === null) {
    return {
      schema: 'durable-workflow.v2.skew-refusal-matrix.refusal-state-evidence',
      required: true,
      surface: surfaceName,
      pairing_class: pairingClass,
      operation_group: operationGroup,
      operation: requestTemplate,
      target: null,
      before: {
        observed: false,
        reason: 'no post-refusal state probe is defined for this mutation-bearing operation',
      },
    };
  }

  return {
    schema: 'durable-workflow.v2.skew-refusal-matrix.refusal-state-evidence',
    required: true,
    surface: surfaceName,
    pairing_class: pairingClass,
    operation_group: operationGroup,
    operation: requestTemplate,
    target,
    before: await captureRefusalMutationState(context, target),
  };
}

async function completeRefusalMutationProbe(probe, context) {
  if (probe === null) {
    return null;
  }

  if (probe.target === null) {
    return {
      ...probe,
      after: {
        observed: false,
        reason: 'post-refusal state could not be observed without a probe target',
      },
      unchanged: false,
      mutation_detected: false,
      outcome: 'not_covered',
    };
  }

  const after = await captureRefusalMutationState(context, probe.target);
  const comparison = compareRefusalMutationStates(probe.before, after);

  return {
    ...probe,
    after,
    ...comparison,
  };
}

function requiresRefusalMutationEvidence(pairingClass, operationGroup, requestTemplate) {
  if (!mutationBearingRequests.has(requestTemplate) || pairingClass === 'compatible') {
    return false;
  }

  if (operationGroup === 'worker_lifecycle') {
    return pairingClass === 'forward_skew' || pairingClass === 'outside_window';
  }

  return true;
}

function refusalMutationProbeTarget(operationGroup, requestTemplate, state) {
  if (operationGroup === 'workflow_control_plane') {
    const workflowId = encodeURIComponent(state.workflowId);
    if (requestTemplate !== 'POST /api/workflows' && state.runId) {
      return {
        method: 'GET',
        path: `/api/workflows/${workflowId}/runs/${encodeURIComponent(state.runId)}/history`,
        resource: 'workflow_history',
      };
    }

    return {
      method: 'GET',
      path: `/api/workflows/${workflowId}`,
      resource: 'workflow',
    };
  }

  if (operationGroup === 'worker_lifecycle') {
    if (workerTaskFixtureRequests.has(requestTemplate)) {
      return {
        method: 'GET',
        path: '/api/task-queues/skew-conformance',
        resource: 'workflow_task_lease_state',
      };
    }

    return {
      method: 'GET',
      path: `/api/workers/${encodeURIComponent(state.workerId)}`,
      resource: 'worker_registration',
    };
  }

  if (operationGroup === 'schedule_control_plane') {
    return {
      method: 'GET',
      path: `/api/schedules/${encodeURIComponent(state.scheduleId)}`,
      resource: 'schedule',
    };
  }

  return null;
}

async function captureRefusalMutationState(context, target) {
  try {
    const response = await requestJson(
      context.serverUrl,
      target.method,
      target.path,
      fixtureHeaders(context),
    );

    return {
      observed: true,
      status: response.status,
      body: refusalMutationStateBody(target.resource, response.body),
    };
  } catch (error) {
    return {
      observed: false,
      reason: error instanceof Error ? error.message : String(error),
    };
  }
}

export function refusalMutationStateBody(resource, body) {
  if (resource !== 'workflow_task_lease_state' || !body || typeof body !== 'object') {
    return body;
  }

  const workflowTaskStats = body?.stats?.workflow_tasks ?? {};
  const leases = Array.isArray(body.current_leases) ? body.current_leases : [];

  return {
    ready_count: integerValue(workflowTaskStats.ready_count) ?? 0,
    leased_count: integerValue(workflowTaskStats.leased_count) ?? 0,
    expired_lease_count: integerValue(workflowTaskStats.expired_lease_count) ?? 0,
    current_leases: leases.map((lease) => ({
      task_id: firstStringValue(lease?.task_id, lease?.taskId, lease?.id),
      workflow_id: firstStringValue(lease?.workflow_id, lease?.workflowId),
      lease_owner: firstStringValue(lease?.lease_owner, lease?.leaseOwner),
      status: stringValue(lease?.status),
    })),
  };
}

function refusalMutationStateComparable(snapshot) {
  return snapshot?.observed === true
    && (snapshot.status === 200 || snapshot.status === 404);
}

export function compareRefusalMutationStates(before, after) {
  const comparable = refusalMutationStateComparable(before)
    && refusalMutationStateComparable(after);
  const unchanged = comparable
    && before.status === after.status
    && JSON.stringify(canonicalJsonValue(before.body)) === JSON.stringify(canonicalJsonValue(after.body));

  return {
    unchanged,
    mutation_detected: comparable && !unchanged,
    outcome: comparable ? (unchanged ? 'pass' : 'fail') : 'not_covered',
  };
}

function canonicalJsonValue(value) {
  if (Array.isArray(value)) {
    return value.map((item) => canonicalJsonValue(item));
  }

  if (value && typeof value === 'object') {
    return Object.fromEntries(
      Object.keys(value)
        .sort()
        .map((key) => [key, canonicalJsonValue(value[key])]),
    );
  }

  return value;
}

function workflowRunIdFromBody(body) {
  return firstStringValue(
    body?.run_id,
    body?.runId,
    body?.workflow_run_id,
    body?.workflowRunId,
    body?.result?.run_id,
    body?.result?.runId,
    body?.result?.workflow_run_id,
    body?.result?.workflowRunId,
    body?.run?.run_id,
    body?.run?.runId,
    body?.run?.id,
    body?.workflow?.run_id,
    body?.workflow?.runId,
    body?.execution?.run_id,
    body?.execution?.runId,
    body?.execution?.workflow_run_id,
    body?.execution?.workflowRunId,
  );
}

function workflowTaskIdFromBody(body) {
  return firstStringValue(
    firstArrayObjectStringValue(Array.isArray(body) ? body : [], ['task_id', 'taskId', 'workflow_task_id', 'workflowTaskId', 'id']),
    body?.task?.task_id,
    body?.task?.taskId,
    body?.task?.workflow_task_id,
    body?.task?.workflowTaskId,
    body?.task?.id,
    body?.task_id,
    body?.taskId,
    body?.workflow_task_id,
    body?.workflowTaskId,
    body?.workflow_task?.task_id,
    body?.workflowTask?.taskId,
    body?.workflow_task?.id,
    body?.workflowTask?.id,
    body?.result?.task?.task_id,
    body?.result?.task?.taskId,
    body?.result?.task?.workflow_task_id,
    body?.result?.task?.workflowTaskId,
    body?.result?.task?.id,
    body?.result?.task_id,
    body?.result?.taskId,
    body?.result?.workflow_task_id,
    body?.result?.workflowTaskId,
    body?.result?.workflow_task?.task_id,
    body?.result?.workflowTask?.taskId,
    body?.result?.workflow_task?.id,
    body?.result?.workflowTask?.id,
    firstArrayObjectStringValue(body?.tasks, ['task_id', 'taskId', 'workflow_task_id', 'workflowTaskId', 'id']),
    firstArrayObjectStringValue(body?.result?.tasks, ['task_id', 'taskId', 'workflow_task_id', 'workflowTaskId', 'id']),
  );
}

function workflowTaskAttemptFromBody(body) {
  return firstIntegerValue(
    firstArrayObjectIntegerValue(Array.isArray(body) ? body : [], ['workflow_task_attempt', 'workflowTaskAttempt', 'attempt', 'attempt_number']),
    body?.task?.workflow_task_attempt,
    body?.task?.workflowTaskAttempt,
    body?.task?.attempt,
    body?.task?.attempt_number,
    body?.workflow_task_attempt,
    body?.workflowTaskAttempt,
    body?.workflow_task?.workflow_task_attempt,
    body?.workflowTask?.workflowTaskAttempt,
    body?.workflow_task?.attempt,
    body?.workflowTask?.attempt,
    body?.result?.task?.workflow_task_attempt,
    body?.result?.task?.workflowTaskAttempt,
    body?.result?.task?.attempt,
    body?.result?.task?.attempt_number,
    body?.result?.workflow_task_attempt,
    body?.result?.workflowTaskAttempt,
    body?.result?.workflow_task?.workflow_task_attempt,
    body?.result?.workflowTask?.workflowTaskAttempt,
    body?.result?.workflow_task?.attempt,
    body?.result?.workflowTask?.attempt,
    firstArrayObjectIntegerValue(body?.tasks, ['workflow_task_attempt', 'workflowTaskAttempt', 'attempt', 'attempt_number']),
    firstArrayObjectIntegerValue(body?.result?.tasks, ['workflow_task_attempt', 'workflowTaskAttempt', 'attempt', 'attempt_number']),
  );
}

async function invokeSurfaceOperation(options) {
  if (options.surfaceName === 'cli') {
    return invokeCliOperation(options);
  }

  if (options.surfaceName === 'sdk-python') {
    return invokePythonSdkOperation(options);
  }

  if (options.surfaceName === 'sdk-php') {
    return invokeWorkflowWorkerOperation(options);
  }

  if (options.surfaceName === 'waterline') {
    return invokeWaterlineOperation(options);
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
  const pairing = context.pairingClasses[pairingClass];

  return runArtifactWithProxy({
    surfaceName,
    pairingClass,
    operationGroup,
    requestTemplate,
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
      DURABLE_WORKFLOW_CONTROL_PLANE_VERSION: pairing.controlPlaneVersion,
      DURABLE_WORKFLOW_WORKER_PROTOCOL_VERSION: pairing.workerProtocolVersion,
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
      return [...global, 'server:info', '--output=json'];
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
      return [...global, 'server:info', '--output=json'];
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
  const pairing = context.pairingClasses[pairingClass];
  const state = pairingState(context, 'sdk-python', pairingClass);
  const payload = {
    operation: requestTemplate,
    base_url: '__DW_SKEW_PROXY_URL__',
    namespace: context.namespace,
    workflow_id: state.workflowId,
    run_id: state.runId || `run-${context.runId}`,
    schedule_id: state.scheduleId,
    worker_id: state.workerId,
    task_id: taskIdForPublishedWorkerProbe(state, pairingClass, context),
    workflow_task_attempt: state.workflowTaskAttempt ?? 1,
  };

  return runArtifactWithProxy({
    surfaceName,
    pairingClass,
    operationGroup,
    requestTemplate,
    method,
    requestPath,
    context,
    pairing,
    command: python,
    args: [script, JSON.stringify(payload)],
    env: {
      DW_SKEW_AUTH_TOKEN: process.env.DW_SKEW_AUTH_TOKEN ?? 'dev-token',
      DURABLE_WORKFLOW_CONTROL_PLANE_VERSION: pairing.controlPlaneVersion,
      DURABLE_WORKFLOW_WORKER_PROTOCOL_VERSION: pairing.workerProtocolVersion,
    },
    timeoutMs: Number.parseInt(process.env.DW_SKEW_PYTHON_TIMEOUT_MS ?? '20000', 10),
  });
}

async function invokeWorkflowWorkerOperation({
  surfaceName,
  pairingClass,
  operationGroup,
  requestTemplate,
  method,
  requestPath,
  context,
}) {
  const availability = invocationAvailability(surfaceName);
  const script = ensureWorkflowWorkerProbeScript();
  const pairing = context.pairingClasses[pairingClass];
  const state = pairingState(context, 'sdk-php', pairingClass);
  const payload = {
    operation: requestTemplate,
    base_url: '__DW_SKEW_PROXY_URL__',
    namespace: context.namespace,
    control_plane_header: context.protocolAuthority.control_plane.header,
    control_plane_version: pairing.controlPlaneVersion,
    worker_protocol_header: context.protocolAuthority.worker_protocol.header,
    worker_protocol_version: pairing.workerProtocolVersion,
    worker_id: state.workerId,
    task_queue: 'skew-conformance',
    task_id: taskIdForPublishedWorkerProbe(state, pairingClass, context),
    workflow_task_attempt: state.workflowTaskAttempt ?? 1,
  };
  return runPhpArtifactWithProxyFallback({
    surfaceName,
    pairingClass,
    operationGroup,
    requestTemplate,
    method,
    requestPath,
    context,
    pairing,
    availability,
    script,
    payload,
    env: {
      DW_SKEW_AUTH_TOKEN: process.env.DW_SKEW_AUTH_TOKEN ?? 'dev-token',
    },
    timeoutMs: Number.parseInt(process.env.DW_SKEW_WORKFLOW_TIMEOUT_MS ?? '30000', 10),
  });
}

async function invokeWaterlineOperation({
  surfaceName,
  pairingClass,
  operationGroup,
  requestTemplate,
  method,
  requestPath,
  context,
}) {
  const availability = invocationAvailability(surfaceName);
  const script = ensureWaterlineProbeScript();
  const pairing = context.pairingClasses[pairingClass];
  const state = pairingState(context, 'waterline', pairingClass);
  const payload = {
    operation: requestTemplate,
    base_url: '__DW_SKEW_PROXY_URL__',
    namespace: context.namespace,
    control_plane_version: pairing.controlPlaneVersion,
    workflow_id: state.workflowId,
    request_path: requestPath,
  };
  const targetUrl = operationGroup === 'waterline_render'
    ? availability.surfaceUrl
    : null;

  return runPhpArtifactWithProxyFallback({
    surfaceName,
    pairingClass,
    operationGroup,
    requestTemplate,
    method,
    requestPath,
    targetUrl,
    context,
    pairing,
    availability,
    script,
    payload,
    env: {
      DW_SKEW_AUTH_TOKEN: process.env.DW_SKEW_AUTH_TOKEN ?? 'dev-token',
    },
    timeoutMs: Number.parseInt(process.env.DW_SKEW_WATERLINE_TIMEOUT_MS ?? '30000', 10),
  });
}

async function runPhpArtifactWithProxyFallback({
  availability,
  script,
  payload,
  ...options
}) {
  const strategies = phpDockerNetworkStrategies();
  let firstResult = null;
  const previousAttempts = [];

  for (let index = 0; index < strategies.length; index += 1) {
    const strategy = strategies[index];
    const docker = phpDockerInvocation(availability, script, payload, strategy);
    const result = await runArtifactWithProxy({
      ...options,
      command: docker.command,
      args: docker.args,
      artifactProxyHost: docker.artifactProxyHost,
      dockerNetworkStrategy: docker.dockerNetworkStrategy,
    });

    if (firstResult === null) {
      firstResult = result;
    }

    if (previousAttempts.length > 0) {
      result.artifact_invocation.previous_proxy_attempt = previousAttempts[0];
      result.artifact_invocation.previous_proxy_attempts = previousAttempts;
    }

    if (!phpProxyRetryable(result, index < strategies.length - 1)) {
      return result;
    }

    previousAttempts.push(phpProxyAttemptSummary(result));
  }

  return firstResult;
}

function phpDockerNetworkStrategies() {
  const strategies = [];
  const containerTarget = phpContainerNetworkTarget();
  if (containerTarget && envValue('DW_SKEW_DISABLE_PHP_CONTAINER_NETWORK') !== '1') {
    strategies.push({
      kind: 'container-network',
      target: containerTarget,
    });
  }

  strategies.push({ kind: 'host-gateway' });

  if (envValue('DW_SKEW_DISABLE_PHP_HOST_NETWORK_FALLBACK') !== '1') {
    strategies.push({ kind: 'host-network' });
  }

  return strategies;
}

function phpContainerNetworkTarget() {
  return envValue('DW_SKEW_PHP_CONTAINER_NETWORK_TARGET')
    || envValue('DW_SKEW_CONTAINER_NETWORK_TARGET')
    || (runningInsideContainer() ? envValue('HOSTNAME') : '');
}

function runningInsideContainer() {
  if (fs.existsSync('/.dockerenv')) {
    return true;
  }

  try {
    const cgroup = fs.readFileSync('/proc/1/cgroup', 'utf8');
    return /(docker|kubepods|containerd)/i.test(cgroup);
  } catch {
    return false;
  }
}

function phpProxyRetryable(result, hasMoreStrategies) {
  return hasMoreStrategies
    && result?.wire_evidence_gap !== null
    && Array.isArray(result?.proxy_captures)
    && result.proxy_captures.length === 0
    && result?.artifact_invocation?.response_source === 'no_matched_proxy_capture';
}

function phpProxyAttemptSummary(result) {
  return {
    docker_network_strategy: result?.artifact_invocation?.docker_network_strategy ?? null,
    response_source: result?.artifact_invocation?.response_source ?? null,
    exit_code: result?.artifact_invocation?.exit_code ?? null,
    timed_out: result?.artifact_invocation?.timed_out ?? null,
    proxy_capture_count: Array.isArray(result?.proxy_captures) ? result.proxy_captures.length : 0,
    wire_evidence_gap: result?.wire_evidence_gap?.reason ?? null,
    stdout_excerpt: result?.artifact_invocation?.stdout_excerpt ?? '',
    stderr_excerpt: result?.artifact_invocation?.stderr_excerpt ?? '',
  };
}

function phpDockerInvocation(availability, script, payload, strategy = { kind: 'host-gateway' }) {
  const dockerNetworkStrategy = strategy.kind ?? 'host-gateway';
  const artifactProxyHost = envValue('DW_SKEW_DOCKER_HOST_GATEWAY_NAME') || 'host.docker.internal';
  const networkArgs = dockerNetworkStrategy === 'container-network'
    ? ['--network', `container:${strategy.target}`]
    : dockerNetworkStrategy === 'host-network'
      ? ['--network', 'host']
      : ['--add-host', `${artifactProxyHost}:host-gateway`];

  return {
    command: 'docker',
    args: [
      'run',
      '--rm',
      ...networkArgs,
      '-e',
      'DW_SKEW_AUTH_TOKEN',
      '-v',
      `${availability.appDir}:/app`,
      '-v',
      `${script}:/tmp/dw-skew-probe.php:ro`,
      '-w',
      '/app',
      availability.phpImage || 'composer:2',
      'php',
      '/tmp/dw-skew-probe.php',
      JSON.stringify(payload),
    ],
    artifactProxyHost: ['container-network', 'host-network'].includes(dockerNetworkStrategy) ? null : artifactProxyHost,
    dockerNetworkStrategy,
  };
}

function ensurePythonProbeScript() {
  const scriptPath = path.join(runRoot, 'python-sdk-skew-probe.py');
  if (fs.existsSync(scriptPath)) {
    assertPythonSdkProbeCapabilityManifest(fs.readFileSync(scriptPath, 'utf8'));
    return scriptPath;
  }

  const source = pythonSdkProbeSource();
  assertPythonSdkProbeCapabilityManifest(source);
  fs.writeFileSync(scriptPath, source);

  return scriptPath;
}

export function pythonSdkProbeSource() {
  return `from __future__ import annotations

import asyncio
import dataclasses
import json
import os
import sys
from typing import Any

from durable_workflow import Client
from durable_workflow.client import PORTABLE_WORKER_AFFINITY_CAPABILITY_MANIFEST, ScheduleAction, ScheduleSpec


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
    task_id = payload["task_id"]
    workflow_task_attempt = int(payload.get("workflow_task_attempt") or 1)
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
            result = await client._request(
                "POST",
                f"/workflows/{workflow_id}/runs/{run_id}/signal/advance",
                json={
                    "input": client._payload_envelope(
                        [{"source": "skew-conformance"}],
                        kind="signal",
                        workflow_id=workflow_id,
                        signal_name="advance",
                    )
                },
                context=workflow_id,
            )
        elif op == "POST /api/workflows/{workflowId}/runs/{runId}/query/{queryName}":
            result = await client._request(
                "POST",
                f"/workflows/{workflow_id}/runs/{run_id}/query/currentState",
                json={},
                context=workflow_id,
            )
        elif op == "POST /api/workflows/{workflowId}/runs/{runId}/update/{updateName}":
            result = await client._request(
                "POST",
                f"/workflows/{workflow_id}/runs/{run_id}/update/approve",
                json={"wait_for": "accepted"},
                context=workflow_id,
            )
        elif op == "POST /api/workflows/{workflowId}/cancel":
            result = await client.cancel_workflow(workflow_id, reason="skew conformance boundary probe")
        elif op == "POST /api/workflows/{workflowId}/terminate":
            result = await client.terminate_workflow(workflow_id, reason="skew conformance boundary probe")
        elif op == "POST /api/worker/register":
            result = await client.register_worker(
                worker_id=worker_id,
                task_queue="skew-conformance",
                supported_workflow_types=["skew_conformance_workflow"],
                supported_activity_types=[],
                capability_manifest=PORTABLE_WORKER_AFFINITY_CAPABILITY_MANIFEST,
            )
        elif op == "POST /api/worker/heartbeat":
            result = await client.heartbeat_worker(worker_id=worker_id)
        elif op == "POST /api/worker/workflow-tasks/poll":
            result = await client.poll_workflow_task(worker_id=worker_id, task_queue="skew-conformance", timeout=1.0)
        elif op == "POST /api/worker/workflow-tasks/{task}/complete":
            result = await client.complete_workflow_task(
                task_id=task_id,
                lease_owner=worker_id,
                workflow_task_attempt=workflow_task_attempt,
                commands=[{"type": "complete_workflow", "result": None}],
            )
        elif op == "POST /api/worker/workflow-tasks/{task}/fail":
            result = await client.fail_workflow_task(
                task_id=task_id,
                lease_owner=worker_id,
                workflow_task_attempt=workflow_task_attempt,
                message="skew conformance boundary probe",
                failure_type="SkewConformanceFailure",
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
`;
}

export function assertPythonSdkProbeCapabilityManifest(source) {
  const registrations = source.match(/\bclient\.register_worker\s*\(/g) ?? [];
  const manifests = source.match(
    /\bcapability_manifest\s*=\s*PORTABLE_WORKER_AFFINITY_CAPABILITY_MANIFEST\b/g,
  ) ?? [];
  const importsManifest = /from durable_workflow\.client import [^\n]*\bPORTABLE_WORKER_AFFINITY_CAPABILITY_MANIFEST\b/.test(source);

  if (registrations.length === 0
    || manifests.length !== registrations.length
    || !importsManifest) {
    throw new Error(
      'Python SDK skew probe must supply PORTABLE_WORKER_AFFINITY_CAPABILITY_MANIFEST to every register_worker call',
    );
  }
}

function ensureWorkflowWorkerProbeScript() {
  const scriptPath = path.join(runRoot, 'sdk-php-skew-probe.php');
  if (fs.existsSync(scriptPath)) {
    return scriptPath;
  }

  fs.writeFileSync(scriptPath, String.raw`<?php
declare(strict_types=1);

require __DIR__.'/../app/vendor/autoload.php';

final class SkewVersionTransport implements \DurableWorkflow\Transport\Transport
{
    public function __construct(
        private readonly \DurableWorkflow\Transport\Transport $inner,
        private readonly string $controlPlaneHeader,
        private readonly string $controlPlaneVersion,
        private readonly string $workerProtocolHeader,
        private readonly string $workerProtocolVersion,
    ) {
    }

    public function send(string $method, string $uri, array $headers, ?array $body = null): ?array
    {
        if (array_key_exists($this->workerProtocolHeader, $headers)) {
            $headers[$this->workerProtocolHeader] = $this->workerProtocolVersion;
        } else {
            $headers[$this->controlPlaneHeader] = $this->controlPlaneVersion;
        }

        return $this->inner->send($method, $uri, $headers, $body);
    }
}

function skew_json(mixed $value): void
{
    echo json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
}

function skew_payload(): array
{
    $payload = json_decode($GLOBALS['argv'][1] ?? '{}', true);

    return is_array($payload) ? $payload : [];
}

function skew_public(mixed $value): mixed
{
    if (is_array($value)) {
        $public = [];
        foreach ($value as $key => $nested) {
            $public[$key] = skew_public($nested);
        }

        return $public;
    }

    if (is_object($value)) {
        if (method_exists($value, 'toArray')) {
            return skew_public($value->toArray());
        }

        return skew_public(get_object_vars($value));
    }

    return $value;
}

function skew_call(callable $operation): array
{
    try {
        return [
            'ok' => true,
            'result' => skew_public($operation()),
        ];
    } catch (\Throwable $exception) {
        $status = property_exists($exception, 'status')
            ? (int) $exception->status
            : (($exception->getCode() >= 100 && $exception->getCode() <= 599) ? (int) $exception->getCode() : 0);
        $body = property_exists($exception, 'details') ? $exception->details : null;
        $reason = property_exists($exception, 'reason') ? $exception->reason : null;

        return [
            'ok' => false,
            'exception_type' => get_class($exception),
            'message' => $exception->getMessage(),
            'status_code' => $status,
            'reason' => $reason,
            'body' => skew_public($body),
        ];
    }
}

$payload = skew_payload();
$operation = (string) ($payload['operation'] ?? '');
$baseUrl = (string) ($payload['base_url'] ?? '');
$namespace = (string) ($payload['namespace'] ?? 'default');
$token = (string) (getenv('DW_SKEW_AUTH_TOKEN') ?: 'dev-token');
$controlPlaneHeader = (string) ($payload['control_plane_header'] ?? '');
$controlPlaneVersion = (string) ($payload['control_plane_version'] ?? \DurableWorkflow\Version::CONTROL_PLANE_PROTOCOL);
$workerProtocolHeader = (string) ($payload['worker_protocol_header'] ?? '');
$workerProtocolVersion = (string) ($payload['worker_protocol_version'] ?? \DurableWorkflow\Version::WORKER_PROTOCOL);
if ($controlPlaneHeader === '' || $workerProtocolHeader === '') {
    throw new RuntimeException('Skew probe requires protocol header names from the published Server cluster manifest.');
}
$workerId = (string) ($payload['worker_id'] ?? 'worker-skew-conformance');
$taskQueue = (string) ($payload['task_queue'] ?? 'skew-conformance');
$taskId = (string) ($payload['task_id'] ?? '');
$workflowTaskAttempt = (int) ($payload['workflow_task_attempt'] ?? 1);
$transport = new SkewVersionTransport(
    new \DurableWorkflow\Transport\Psr18Transport(),
    $controlPlaneHeader,
    $controlPlaneVersion,
    $workerProtocolHeader,
    $workerProtocolVersion,
);
$client = new \DurableWorkflow\Client(
    $baseUrl,
    namespace: $namespace,
    transport: $transport,
    token: $token,
);

if ($operation === 'GET /api/cluster/info') {
    $path = '/api/cluster/info';
    $response = skew_call(static fn (): mixed => $client->clusterInfo());
} else {
    $path = match ($operation) {
        'POST /api/worker/register' => '/api/worker/register',
        'POST /api/worker/heartbeat' => '/api/worker/heartbeat',
        'POST /api/worker/workflow-tasks/poll' => '/api/worker/workflow-tasks/poll',
        'POST /api/worker/workflow-tasks/{task}/complete' => '/api/worker/workflow-tasks/'.rawurlencode($taskId).'/complete',
        'POST /api/worker/workflow-tasks/{task}/fail' => '/api/worker/workflow-tasks/'.rawurlencode($taskId).'/fail',
        default => '/api/worker/register',
    };
    $response = match ($operation) {
        'POST /api/worker/register' => skew_call(static fn (): array => $client->registerWorker(
            $workerId,
            $taskQueue,
            ['skew_conformance_workflow'],
            [],
            ['query_tasks', 'workflow_updates', 'durable_history_replay'],
        )),
        'POST /api/worker/heartbeat' => skew_call(static fn (): array => $client->heartbeatWorker($workerId)),
        'POST /api/worker/workflow-tasks/poll' => skew_call(static fn (): ?array => $client->pollWorkflowTask(
            $workerId,
            $taskQueue,
            1,
        )),
        'POST /api/worker/workflow-tasks/{task}/complete' => skew_call(static fn (): array => $client->completeWorkflowTask(
            $taskId,
            $workerId,
            $workflowTaskAttempt,
            [[
                'type' => 'complete_workflow',
                'result' => $client->payloadCodec()->envelope(null),
            ]],
        )),
        'POST /api/worker/workflow-tasks/{task}/fail' => skew_call(static fn (): array => $client->failWorkflowTask(
            $taskId,
            $workerId,
            $workflowTaskAttempt,
            'skew conformance boundary probe',
            'SkewConformanceFailure',
        )),
        default => skew_call(static fn (): array => $client->registerWorker($workerId, $taskQueue, [], [])),
    };
}

skew_json([
    'artifact' => 'durable-workflow/sdk',
    'control_plane_version' => $controlPlaneVersion,
    'worker_protocol_version' => $workerProtocolVersion,
    'operation' => $operation,
    'request_path' => $path,
    'response' => $response,
]);
`);

  return scriptPath;
}

function ensureWaterlineProbeScript() {
  const scriptPath = path.join(runRoot, 'waterline-skew-probe.php');
  if (fs.existsSync(scriptPath)) {
    return scriptPath;
  }

  fs.writeFileSync(scriptPath, String.raw`<?php
declare(strict_types=1);

require __DIR__.'/../app/vendor/autoload.php';

function skew_json(mixed $value): void
{
    echo json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
}

function skew_payload(): array
{
    $payload = json_decode($GLOBALS['argv'][1] ?? '{}', true);

    return is_array($payload) ? $payload : [];
}

function skew_request(string $method, string $baseUrl, string $path, array $headers): array
{
    $headerLines = [];
    foreach ($headers as $name => $value) {
        $headerLines[] = $name.': '.$value;
    }

    $responseBody = @file_get_contents(rtrim($baseUrl, '/').$path, false, stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headerLines),
            'ignore_errors' => true,
            'timeout' => 12,
        ],
    ]));
    $responseHeaders = $http_response_header ?? [];
    $status = 0;
    if (isset($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', $responseHeaders[0], $matches) === 1) {
        $status = (int) $matches[1];
    }

    $decoded = is_string($responseBody) ? json_decode($responseBody, true) : null;

    return [
        'status_code' => $status,
        'headers' => $responseHeaders,
        'body' => is_array($decoded) ? $decoded : $responseBody,
        'dom_snapshot' => is_string($responseBody) ? substr($responseBody, 0, 1200) : null,
    ];
}

$payload = skew_payload();
$operation = (string) ($payload['operation'] ?? '');
$baseUrl = (string) ($payload['base_url'] ?? '');
$namespace = (string) ($payload['namespace'] ?? 'default');
$token = (string) (getenv('DW_SKEW_AUTH_TOKEN') ?: 'dev-token');
$controlPlaneVersion = (string) ($payload['control_plane_version'] ?? '2');
$apiFloorMissing = class_exists(\Waterline\Support\WorkflowPackageApiFloor::class)
    ? \Waterline\Support\WorkflowPackageApiFloor::findMissing()
    : ['Waterline\Support\WorkflowPackageApiFloor'];

$path = match ($operation) {
    'GET /api/cluster/info' => '/api/cluster/info',
    'GET /waterline/api/v2/health' => '/waterline/api/v2/health',
    'GET /waterline/api/flows/running' => '/waterline/api/flows/running',
    'GET /waterline/api/flows/{id}' => '/waterline/api/flows/'.rawurlencode((string) ($payload['workflow_id'] ?? 'skew-conformance')),
    default => (string) ($payload['request_path'] ?? '/waterline/api/v2/health'),
};
$headers = [
    'Accept' => 'application/json,text/html',
    'Authorization' => 'Bearer '.$token,
    'X-Namespace' => $namespace,
    'X-Durable-Workflow-Control-Plane-Version' => $controlPlaneVersion,
];
$response = skew_request('GET', $baseUrl, $path, $headers);

skew_json([
    'artifact' => 'durable-workflow/waterline',
    'control_plane_version' => $controlPlaneVersion,
    'operation' => $operation,
    'request_path' => $path,
    'api_floor_missing' => $apiFloorMissing,
    'response' => $response,
]);
`);

  return scriptPath;
}

async function runArtifactWithProxy({
  surfaceName,
  pairingClass,
  operationGroup,
  requestTemplate,
  method,
  requestPath,
  targetUrl = null,
  artifactProxyHost = null,
  dockerNetworkStrategy = null,
  context,
  pairing,
  command,
  args,
  env,
  timeoutMs,
}) {
  const proxyResult = await withRecordingProxy({
    targetUrl: targetUrl ?? context.serverUrl,
    artifactProxyHost,
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

  const exactCapture = selectProxyCapture(proxyResult.captures, method, requestPath, requestTemplate);
  const stdoutJson = parseJson(proxyResult.process.stdout.trim());
  const artifactResponse = artifactOutputResponse(surfaceName, operationGroup, stdoutJson);
  const artifactOutputAuthoritative = surfaceName === 'waterline'
    && operationGroup === 'waterline_render';
  const artifactRefusal = pairingClass !== 'compatible'
    ? artifactCompatibilityRefusalResponse({
      surfaceName,
      pairingClass,
      operationGroup,
      pairing,
      processResult: proxyResult.process,
      stdoutJson,
    })
    : null;
  const guardCapture = artifactRefusal && exactCapture === null
    ? selectCompatibilityGuardCapture(
      proxyResult.captures,
      pairing,
      operationGroup,
      context.protocolAuthority,
    )
    : null;
  const matchedCapture = exactCapture ?? guardCapture;
  const guardCaptureUsed = exactCapture === null && guardCapture !== null;
  const artifactRefusalUsed = artifactRefusal !== null
    && (
      guardCaptureUsed
      || (operationGroup === 'cluster_info_probe' && exactCapture !== null)
    );
  const fallbackReason = matchedCapture === null
    ? proxyResult.captures.length > 0
      ? 'advertised_operation_not_observed'
      : 'artifact_did_not_contact_surface'
    : artifactOutputAuthoritative
    ? 'artifact_did_not_report_waterline_render_response'
    : 'artifact_response_unavailable';
  const fallbackMessage = matchedCapture === null
    ? proxyResult.captures.length > 0
      ? 'The published artifact contacted the recording proxy, but no captured request matched the advertised operation.'
      : 'The published artifact invocation did not contact the recording proxy for the advertised operation.'
    : artifactOutputAuthoritative
    ? 'The Composer-installed Waterline artifact probe did not emit render response evidence.'
    : 'The matched recording-proxy capture did not include response evidence.';
  const response = matchedCapture === null
    ? null
    : artifactRefusalUsed
      ? artifactRefusal
      : artifactOutputAuthoritative
      ? artifactResponse
      : matchedCapture.response;
  const normalizedResponse = response
    ?? {
    status: 0,
    headers: {},
    body: {
      reason: fallbackReason,
      message: fallbackMessage,
      observed_proxy_requests: proxyResult.captures.map((capture) => `${capture.request.method} ${capture.request.path}`),
      exit_code: proxyResult.process.exitCode,
      artifact_output: redactJsonSecrets(stdoutJson),
      stdout: redactKnownSecrets(proxyResult.process.stdout.slice(0, 2000)),
      stderr: redactKnownSecrets(proxyResult.process.stderr.slice(0, 2000)),
    },
  };

  updatePairingStateFromResponse(context, surfaceName, pairingClass, normalizedResponse.body);

  const responseSource = matchedCapture === null
    ? 'no_matched_proxy_capture'
    : artifactRefusalUsed
      ? guardCaptureUsed
        ? 'artifact_refusal_guarded_by_proxy_capture'
        : 'artifact_refusal_after_advertised_operation'
      : artifactResponse !== null && artifactOutputAuthoritative
      ? 'artifact_stdout'
      : artifactOutputAuthoritative
        ? 'artifact_stdout_missing'
        : 'recording_proxy';

  return {
    response: {
      ...normalizedResponse,
      artifact_exit_code: proxyResult.process.exitCode,
    },
    matched_proxy_capture: matchedCapture,
    guard_proxy_capture: guardCaptureUsed ? guardCapture : null,
    evidence_request: guardCaptureUsed
      ? {
        method,
        path: requestPath,
      }
      : null,
    wire_evidence_gap: matchedCapture === null
      ? {
        status: 'not_covered',
        reason: fallbackMessage,
      }
      : null,
    artifact_invocation: {
      surface: surfaceName,
      pairing_class: pairingClass,
      operation_group: operationGroup,
      ...(surfaceName === 'sdk-python' ? {
        sdk_python_version: context.artifactVersions['sdk-python'],
        sdk_version: context.artifactVersions['sdk-python'],
        typed_sdk_evidence: true,
        typed_sdk_client: 'durable_workflow.Client',
        sdk_operation: `${method} ${requestPath}`,
        artifact_output: redactJsonSecrets(stdoutJson),
      } : {}),
      command: path.basename(command),
      args: redactCommandArgs(proxyResult.process.args),
      exit_code: proxyResult.process.exitCode,
      timed_out: proxyResult.process.timedOut,
      ...(dockerNetworkStrategy ? { docker_network_strategy: dockerNetworkStrategy } : {}),
      stdout_excerpt: redactKnownSecrets(proxyResult.process.stdout.slice(0, 4000)),
      stderr_excerpt: redactKnownSecrets(proxyResult.process.stderr.slice(0, 4000)),
      matched_proxy_capture: matchedCapture?.id ?? null,
      selected_proxy_capture: matchedCapture?.id ?? null,
      selected_proxy_capture_kind: guardCaptureUsed ? 'guard_refusal_preflight' : 'advertised_operation',
      response_source: responseSource,
      target_url: targetUrl ?? context.serverUrl,
    },
    proxy_captures: proxyResult.captures,
  };
}

function artifactCompatibilityRefusalResponse({
  surfaceName,
  pairingClass,
  operationGroup,
  pairing,
  processResult,
  stdoutJson,
}) {
  const message = artifactCompatibilityRefusalMessage(stdoutJson, processResult);
  if (!message) {
    return null;
  }

  return {
    status: 400,
    headers: {},
    body: {
      reason: 'artifact_compatibility_refusal',
      message,
      surface: surfaceName,
      pairing_class: pairingClass,
      operation_group: operationGroup,
      compatibility_window: pairing.compatibilityWindow,
      expected_control_plane_version: pairing.controlPlaneVersion,
      expected_worker_protocol_version: pairing.workerProtocolVersion,
      next_step: 'Upgrade the older side, pin the client to the advertised range, or connect to a server that supports the requested protocol.',
    },
  };
}

function artifactCompatibilityRefusalMessage(stdoutJson, processResult) {
  const candidates = [];
  for (const payload of artifactOutputPayloads(stdoutJson)) {
    candidates.push(
      stringValue(payload.message),
      stringValue(payload.reason),
      stringValue(payload.exception_type),
      JSON.stringify(redactJsonSecrets(payload.body ?? null)),
      JSON.stringify(redactJsonSecrets(payload.errors ?? null)),
    );
  }
  candidates.push(processResult.stderr, processResult.stdout);

  const text = redactKnownSecrets(candidates.filter(Boolean).join('\n')).trim();
  if (text === '') {
    return '';
  }

  const artifactReportedFailure = processResult.exitCode !== 0
    || processResult.timedOut
    || artifactOutputPayloads(stdoutJson).some((payload) => (
      payload.ok === false
      || stringValue(payload.exception_type) !== ''
      || ((integerValue(payload.status) ?? integerValue(payload.status_code) ?? 0) >= 400)
    ));
  if (!artifactReportedFailure) {
    return '';
  }

  const compatibilityText = /(server compatibility error|compatib|unsupported|outside.*window|protocol|control_plane|worker_protocol|request_contract|version)/i;
  if (!compatibilityText.test(text)) {
    return '';
  }

  return text.slice(0, 2000);
}

function artifactOutputPayloads(stdoutJson) {
  if (!stdoutJson || typeof stdoutJson !== 'object' || Array.isArray(stdoutJson)) {
    return [];
  }

  return [
    stdoutJson,
    stdoutJson.response,
    stdoutJson.result,
    stdoutJson.error,
    stdoutJson.artifact_response,
  ].filter((payload) => payload && typeof payload === 'object' && !Array.isArray(payload));
}

function selectCompatibilityGuardCapture(captures, pairing, operationGroup, protocolAuthority) {
  const expectation = protocolExpectationForOperation(operationGroup, pairing, protocolAuthority);
  const matchingHeader = captures.filter((capture) => headerValue(
    capture?.request?.headers ?? {},
    expectation.header,
  ) === expectation.expected);

  return matchingHeader.find((capture) => normalizeOperationRequest(
    `${capture.request.method} ${capture.request.path}`,
  ) === 'GET /api/cluster/info') ?? matchingHeader[0] ?? null;
}

function artifactOutputResponse(surfaceName, operationGroup, stdoutJson) {
  if (surfaceName !== 'waterline' || operationGroup !== 'waterline_render') {
    return null;
  }

  const rawResponse = stdoutJson && typeof stdoutJson === 'object'
    ? stdoutJson.response
    : null;
  if (!rawResponse || typeof rawResponse !== 'object') {
    return null;
  }

  const status = integerValue(rawResponse.status)
    ?? integerValue(rawResponse.status_code)
    ?? 0;

  return {
    status,
    headers: normalizeArtifactHeaders(rawResponse.headers),
    body: rawResponse.body ?? null,
    dom_snapshot: typeof rawResponse.dom_snapshot === 'string'
      ? rawResponse.dom_snapshot
      : null,
    source: 'published_waterline_artifact',
  };
}

function normalizeArtifactHeaders(headers) {
  if (!headers || typeof headers !== 'object') {
    return {};
  }

  if (!Array.isArray(headers)) {
    return Object.fromEntries(
      Object.entries(headers).map(([key, value]) => [key.toLowerCase(), Array.isArray(value) ? value.join(', ') : String(value)]),
    );
  }

  const normalized = {};
  for (const line of headers) {
    const value = String(line);
    const index = value.indexOf(':');
    if (index <= 0) {
      continue;
    }

    normalized[value.slice(0, index).trim().toLowerCase()] = value.slice(index + 1).trim();
  }

  return normalized;
}

async function withRecordingProxy({ targetUrl, artifactProxyHost = null }, callback) {
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

  const listenHost = artifactProxyHost ? '0.0.0.0' : '127.0.0.1';
  await new Promise((resolve, reject) => {
    server.once('error', reject);
    server.listen(0, listenHost, resolve);
  });

  const address = server.address();
  const proxyUrl = `http://${artifactProxyHost ?? '127.0.0.1'}:${address.port}`;

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

function selectProxyCapture(captures, method, requestPath, requestTemplate = '') {
  const normalized = normalizeOperationRequest(`${method} ${requestPath}`);
  const exact = captures.find((capture) => normalizeOperationRequest(
    `${capture.request.method} ${capture.request.path}`,
  ) === normalized) ?? null;

  if (exact !== null) {
    return exact;
  }

  const normalizedTemplate = normalizeOperationRequest(requestTemplate);
  if (normalizedTemplate === '' || !normalizedTemplate.includes('{')) {
    return null;
  }

  return captures.find((capture) => operationRequestMatchesTemplate(
    normalizedTemplate,
    normalizeOperationRequest(`${capture.request.method} ${capture.request.path}`),
  )) ?? null;
}

function operationRequestMatchesTemplate(templateRequest, observedRequest) {
  const template = splitOperationRequest(templateRequest);
  const observed = splitOperationRequest(observedRequest);
  if (template.method !== observed.method) {
    return false;
  }

  return operationPathTemplateRegex(template.path).test(observed.path);
}

function splitOperationRequest(value) {
  const normalized = normalizeOperationRequest(value);
  const [method, ...pathParts] = normalized.split(' ');

  return {
    method: stringValue(method) || 'GET',
    path: pathParts.join(' ') || '/',
  };
}

function operationPathTemplateRegex(pathTemplate) {
  const pattern = pathTemplate
    .split('/')
    .map((segment) => /^\{[^/{}]+\}$/.test(segment)
      ? '[^/]+'
      : segment.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))
    .join('/');

  return new RegExp(`^${pattern}$`);
}

function normalizedCaptureRequest(capture) {
  return {
    method: stringValue(capture?.request?.method) || 'GET',
    path: stringValue(capture?.request?.path) || '/',
    headers: capture?.request?.headers && typeof capture.request.headers === 'object'
      ? redactHeaders(capture.request.headers)
      : {},
    body: capture?.request?.body ?? null,
  };
}

function normalizedCaptureResponse(capture) {
  return {
    status: integerValue(capture?.response?.status) ?? 0,
    headers: capture?.response?.headers && typeof capture.response.headers === 'object'
      ? capture.response.headers
      : {},
    body: capture?.response?.body ?? null,
  };
}

function protocolEvidenceGap(operationGroup, pairing, wireRequest, protocolAuthority) {
  const expectation = protocolExpectationForOperation(operationGroup, pairing, protocolAuthority);
  if (!expectation) {
    return null;
  }

  const observed = headerValue(wireRequest.headers, expectation.header);
  if (observed === expectation.expected) {
    return null;
  }

  return {
    header: expectation.header,
    expected: expectation.expected,
    observed: observed || null,
    reason: [
      `Matched artifact request did not send ${expectation.header}: ${expectation.expected}.`,
      'The row is a coverage gap because the published artifact did not exercise the requested skew pairing.',
    ].join(' '),
  };
}

function protocolExpectationForOperation(operationGroup, pairing, protocolAuthority) {
  if (operationGroup === 'worker_lifecycle') {
    return {
      header: protocolAuthority.worker_protocol.header,
      expected: pairing.workerProtocolVersion,
    };
  }

  return {
    header: protocolAuthority.control_plane.header,
    expected: pairing.controlPlaneVersion,
  };
}

function headerValue(headers, wantedHeader) {
  const wanted = wantedHeader.toLowerCase();
  for (const [key, value] of Object.entries(headers ?? {})) {
    if (key.toLowerCase() === wanted) {
      return Array.isArray(value) ? value.join(', ') : String(value);
    }
  }

  return '';
}

function updatePairingStateFromResponse(context, surfaceName, pairingClass, body) {
  const state = pairingState(context, surfaceName, pairingClass);
  if (body && typeof body === 'object') {
    const workflowId = firstStringValue(
      body.workflow_id,
      body.workflowId,
      body.workflow_instance_id,
      body.workflowInstanceId,
      body.result?.workflow_id,
      body.result?.workflowId,
      body.result?.workflow_instance_id,
      body.result?.workflowInstanceId,
      body.workflow?.workflow_id,
      body.workflow?.workflowId,
      body.workflow?.workflow_instance_id,
      body.workflow?.workflowInstanceId,
      body.workflow?.id,
      body.execution?.workflow_id,
      body.execution?.workflowId,
      body.execution?.workflow_instance_id,
      body.execution?.workflowInstanceId,
    );
    const runId = firstStringValue(
      body.run_id,
      body.runId,
      body.workflow_run_id,
      body.workflowRunId,
      body.result?.run_id,
      body.result?.runId,
      body.result?.workflow_run_id,
      body.result?.workflowRunId,
      body.run?.run_id,
      body.run?.runId,
      body.run?.id,
      body.workflow?.run_id,
      body.workflow?.runId,
      body.execution?.run_id,
      body.execution?.runId,
      body.execution?.workflow_run_id,
      body.execution?.workflowRunId,
      firstArrayObjectStringValue(body.runs, ['run_id', 'runId', 'id']),
    );
    const scheduleId = firstStringValue(
      body.schedule_id,
      body.scheduleId,
      body.result?.schedule_id,
      body.result?.scheduleId,
      body.result?.schedule?.schedule_id,
      body.result?.schedule?.scheduleId,
      body.result?.schedule?.id,
      body.schedule?.schedule_id,
      body.schedule?.scheduleId,
      body.schedule?.id,
    );
    const taskId = firstStringValue(
      body.task?.task_id,
      body.task?.taskId,
      body.task?.id,
      body.task_id,
      body.taskId,
      body.result?.task?.task_id,
      body.result?.task?.taskId,
      body.result?.task?.id,
      body.result?.task_id,
      body.result?.taskId,
      body.workflow_task?.task_id,
      body.workflowTask?.taskId,
      body.result?.workflow_task?.task_id,
      body.result?.workflowTask?.taskId,
    );
    if (workflowId) {
      state.workflowId = workflowId;
    }
    if (runId) {
      state.runId = runId;
    }
    if (scheduleId) {
      state.scheduleId = scheduleId;
    }
    if (taskId) {
      state.taskId = taskId;
    }
  }
}

function firstStringValue(...values) {
  for (const value of values) {
    const candidate = stringValue(value);
    if (candidate) {
      return candidate;
    }
  }

  return '';
}

function firstIntegerValue(...values) {
  for (const value of values) {
    const candidate = integerValue(value);
    if (candidate !== null) {
      return candidate;
    }
  }

  return null;
}

function firstArrayObjectStringValue(values, fields) {
  if (!Array.isArray(values)) {
    return '';
  }

  for (const value of values) {
    if (!value || typeof value !== 'object') {
      continue;
    }

    const candidate = firstStringValue(...fields.map((field) => value[field]));
    if (candidate) {
      return candidate;
    }
  }

  return '';
}

function firstArrayObjectIntegerValue(values, fields) {
  if (!Array.isArray(values)) {
    return null;
  }

  for (const value of values) {
    if (!value || typeof value !== 'object') {
      continue;
    }

    const candidate = firstIntegerValue(...fields.map((field) => value[field]));
    if (candidate !== null) {
      return candidate;
    }
  }

  return null;
}

function pairingState(context, surfaceName, pairingClass) {
  const key = `${surfaceName}.${pairingClass}`;
  const defaultWorkflowId = surfaceName === 'waterline'
    ? waterlineFixtureRunId() || `skew-${context.runId}-${surfaceName.replace(/[^a-z0-9]+/gi, '-')}-${pairingClass}`
    : `skew-${context.runId}-${surfaceName.replace(/[^a-z0-9]+/gi, '-')}-${pairingClass}`;
  context.pairingState ??= {};
  context.pairingState[key] ??= {
    workflowId: defaultWorkflowId,
    runId: '',
    scheduleId: `schedule-${context.runId}-${surfaceName.replace(/[^a-z0-9]+/gi, '-')}-${pairingClass}`,
    workerId: `worker-${context.runId}-${surfaceName.replace(/[^a-z0-9]+/gi, '-')}-${pairingClass}`,
    taskId: '',
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

function protocolScenarioFields(pairing) {
  return {
    pairing_label: pairing.label,
    protocol_expectation: pairing.expected,
    protocol_support: pairing.protocolSupport,
    protocol_versions: {
      control_plane: pairing.controlPlaneVersion,
      worker_protocol: pairing.workerProtocolVersion,
    },
  };
}

export function classifyEvidenceStatus({
  surfaceName,
  pairingClass,
  operationGroup,
  requestTemplate = '',
  response,
  refusalMutationEvidence = null,
}) {
  if (isProtocolRefusal(response)) {
    if (refusalMutationEvidence?.required === true) {
      if (refusalMutationEvidence.outcome === 'fail'
        && refusalMutationEvidence.mutation_detected === true) {
        return 'mutation_before_refusal';
      }

      if (refusalMutationEvidence.outcome !== 'pass') {
        return 'not_covered';
      }
    } else if (requiresRefusalMutationEvidence(pairingClass, operationGroup, requestTemplate)) {
      return 'not_covered';
    }

    return 'loud_refuse';
  }

  if (operationGroup === 'cluster_info_probe') {
    if (response.status >= 400 || response.status === 0) {
      return 'silent_failure';
    }

    if (pairingClass === 'forward_skew' || pairingClass === 'outside_window') {
      return 'silent_success';
    }

    return 'pass';
  }

  if (operationGroup === 'waterline_render') {
    const classification = waterlineClassification(pairingClass, response);
    if (isWaterlineTransportFailure(response) || isWaterlineSurfaceMissing(response)) {
      return 'not_covered';
    }

    if (pairingClass === 'compatible' && response.status >= 400) {
      return 'silent_failure';
    }

    if (classification === 'stale_render') {
      return 'silent_success';
    }

    return pairingClass === 'compatible' ? 'pass' : 'loud_refuse';
  }

  if (compatibleControlPlaneInteropClassification({
    surfaceName,
    pairingClass,
    operationGroup,
    response,
  })) {
    return 'pass';
  }

  if (response.status >= 400 || response.status === 0) {
    return 'silent_failure';
  }

  if (surfaceName === 'sdk-php') {
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

function compatibleControlPlaneInteropClassification({
  surfaceName,
  pairingClass,
  operationGroup,
  response,
}) {
  if (pairingClass !== 'compatible') {
    return '';
  }

  if (
    !['cli', 'sdk-python'].includes(surfaceName)
    || operationGroup !== 'workflow_control_plane'
    || response.status < 400
    || response.status >= 500
  ) {
    return '';
  }

  const contract = response?.body?.control_plane;
  if (!contract || typeof contract !== 'object') {
    return '';
  }

  const operation = stringValue(contract.operation);
  const schema = stringValue(contract.schema);
  const reason = stringValue(response.body?.reason ?? contract.reason);

  const isStructuredControlPlaneResponse = operation !== ''
    && schema !== ''
    && reason !== ''
    && schema.startsWith('durable-workflow.v2.control-plane-response');

  if (!isStructuredControlPlaneResponse) {
    return '';
  }

  return surfaceName === 'sdk-python'
    ? 'typed_sdk_structured_control_plane_domain_response'
    : 'structured_control_plane_domain_response';
}

export function summarizePairing(surfaceName, pairingClass, rows, context) {
  const statuses = Array.from(new Set(rows.map((row) => row.status).filter(Boolean)));
  const productBlockingStatusPriority = [
    'mutation_before_refusal',
    'corrupt',
    'silent_success',
    'silent_failure',
  ];
  const coverageGapStatusPriority = [
    'not_covered',
    'runner_blocked',
  ];
  const productBlockingStatus = productBlockingStatusPriority.find((value) => statuses.includes(value));
  const coverageGapStatus = coverageGapStatusPriority.find((value) => statuses.includes(value));
  const runnerBlockedStatus = statuses.includes('runner_blocked') ? 'runner_blocked' : null;
  const compatibleInteropEvidence = compatibleInteropEvidenceForCell(surfaceName, pairingClass, rows, context);
  let status = productBlockingStatus
    ?? runnerBlockedStatus
    ?? (compatibleInteropEvidence ? null : coverageGapStatus)
    ?? 'pass';
  if (!productBlockingStatus && !coverageGapStatus && statuses.includes('loud_refuse')) {
    status = 'loud_refuse';
  }

  const surface = surfaces[surfaceName];
  const pairing = context.pairingClasses[pairingClass];
  const result = {
    surface: surfaceName,
    pairing_class: pairingClass,
    ...protocolScenarioFields(pairing),
    status,
    observed_result: status,
    client_or_worker_version: context.artifactVersions[surface.artifact],
    server_version: context.observedServerVersion,
    compatibility_window: pairing.compatibilityWindow,
    next_step: compatibilityNextStep(surfaceName, pairingClass, context),
    observed_operation_statuses: statuses,
  };
  if (compatibleInteropEvidence) {
    result.compatible_interop_evidence = compatibleInteropEvidence;
  }
  if (surfaceName === 'sdk-php') {
    result.worker_version = context.artifactVersions[surface.artifact];
    result.sdk_php_package_version = context.artifactVersions[surface.artifact];
    result.worker_protocol_version = pairing.workerProtocolVersion;
  }

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

  if (surfaceName === 'sdk-php' && !['not_covered', 'runner_blocked'].includes(status)) {
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

function compatibleInteropEvidenceForCell(surfaceName, pairingClass, rows, context) {
  if (!['cli', 'sdk-python'].includes(surfaceName) || pairingClass !== 'compatible') {
    return null;
  }

  const surface = surfaces[surfaceName];
  const pairing = context.pairingClasses[pairingClass];
  const clientVersion = stringValue(context.artifactVersions?.[surface.artifact]);
  const serverVersion = stringValue(context.observedServerVersion);
  const compatibilityWindow = pairing.compatibilityWindow;
  const nextStep = compatibilityNextStep(surfaceName, pairingClass, context);
  const interopOperationGroups = surfaceName === 'sdk-python'
    ? ['workflow_control_plane', 'schedule_control_plane', 'worker_lifecycle']
    : ['workflow_control_plane', 'schedule_control_plane'];
  const row = rows.find((candidate) => {
    const operationGroup = stringValue(candidate.operation_group);
    const candidateClientVersion = firstStringValue(
      candidate.client_or_worker_version,
      candidate.client_or_observer_version,
      candidate.sdk_python_version,
      candidate.sdk_version,
    );
    const typedSdkEvidence = candidate.typed_sdk_evidence === true
      || candidate.artifact_invocation?.typed_sdk_evidence === true;

    return candidate.status === 'pass'
      && interopOperationGroups.includes(operationGroup)
      && candidateClientVersion === clientVersion
      && stringValue(candidate.server_version) === serverVersion
      && stringValue(candidate.compatibility_window) === compatibilityWindow
      && stringValue(candidate.next_step) === nextStep
      && stringValue(candidate.request_response_capture_id) !== ''
      && (surfaceName !== 'sdk-python' || typedSdkEvidence);
  });

  if (!row) {
    return null;
  }

  const evidence = {
    surface: surfaceName,
    pairing_class: pairingClass,
    ...protocolScenarioFields(pairing),
    operation_group: stringValue(row.operation_group),
    observed_result: 'pass',
    client_or_worker_version: clientVersion,
    server_version: serverVersion,
    compatibility_window: compatibilityWindow,
    next_step: nextStep,
    request_response_capture_id: stringValue(row.request_response_capture_id),
  };
  const request = evidenceRequestLabel(row);
  if (request) {
    evidence.request = request;
  }
  const interopClassification = stringValue(row.interop_classification);
  if (interopClassification) {
    evidence.interop_classification = interopClassification;
  }
  if (surfaceName === 'sdk-python') {
    evidence.sdk_python_version = clientVersion;
    evidence.sdk_version = clientVersion;
    evidence.typed_sdk_evidence = true;
    const sdkOperation = firstStringValue(row.sdk_operation, row.artifact_invocation?.sdk_operation);
    if (sdkOperation) {
      evidence.sdk_operation = sdkOperation;
    }
  }

  return evidence;
}

function evidenceRequestLabel(row) {
  const request = stringValue(row.request);
  if (request) {
    return request;
  }

  const method = stringValue(row.request_method);
  const requestPath = stringValue(row.request_path);
  return method && requestPath ? `${method} ${requestPath}` : '';
}

function findingForPairing(surfaceName, pairingClass, pairing, rows, context) {
  const blockingStatuses = ['mutation_before_refusal', 'silent_success', 'silent_failure', 'corrupt', 'register_and_drop', 'stale_render', 'not_covered', 'runner_blocked'];
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
    severity: ['mutation_before_refusal', 'register_and_drop', 'stale_render', 'silent_success', 'silent_failure', 'corrupt'].includes(findingStatus)
      ? 'blocker'
      : 'tracking',
    surface: surfaceName,
    owning_surface: ownerForFinding(surfaceName, findingStatus),
    artifact_versions: context.artifactVersions,
    pairing_class: pairingClass,
    ...protocolScenarioFields(context.pairingClasses[pairingClass]),
    operation_group: 'pairing_matrix',
    observed_behavior: findingStatus,
    expected_behavior: context.pairingClasses[pairingClass].expected,
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

  if (status === 'mutation_before_refusal') {
    return 'server_protocol_boundary';
  }

  if (status === 'stale_render') {
    return 'durable-workflow/waterline';
  }

  return surfaces[surfaceName]?.artifact
    ? `durable-workflow/${surfaces[surfaceName].artifact}`
    : 'conformance_harness';
}

function nextAcceptanceForFinding(surfaceName, status) {
  if (status === 'mutation_before_refusal') {
    return 'Unsupported protocol shapes must refuse before changing the observed workflow, schedule, worker-registration, or lease state.';
  }

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

export function workerClassification(
  pairingClass,
  response,
  operationGroup = '',
  refusalMutationEvidence = null,
) {
  if (operationGroup === 'cluster_info_probe') {
    return pairingClass === 'compatible' || pairingClass === 'backward_skew'
      ? 'register_and_serve'
      : 'register_refused';
  }

  if (isProtocolRefusal(response)
    && refusalMutationEvidence?.mutation_detected === true) {
    return 'register_and_drop';
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
  if (response.status >= 400) {
    return 'render_refused';
  }

  if (pairingClass === 'compatible') {
    return 'banner';
  }

  const bodyText = JSON.stringify({
    body: response.body ?? '',
    dom_snapshot: response.dom_snapshot ?? '',
  }).toLowerCase();
  if (bodyText.includes('compat') || bodyText.includes('version') || bodyText.includes('skew')) {
    return 'banner';
  }

  return 'stale_render';
}

function domSnapshotForWaterline(classification, response, pairingClass, context) {
  if (typeof response.dom_snapshot === 'string' && response.dom_snapshot !== '') {
    return {
      type: 'dom_snapshot',
      source: response.source ?? 'published_waterline_artifact',
      classification,
      pairing_class: pairingClass,
      server_version: context.observedServerVersion,
      status_code: response.status,
      dom_excerpt: response.dom_snapshot.slice(0, 1200),
    };
  }

  return {
    type: 'dom_snapshot',
    classification,
    pairing_class: pairingClass,
    server_version: context.observedServerVersion,
    status_code: response.status,
    body_excerpt: JSON.stringify(response.body ?? '').slice(0, 500),
  };
}

function isWaterlineTransportFailure(response) {
  const reason = response?.body?.reason;
  return response.status === 0
    || response.status >= 500
    || reason === 'skew_proxy_upstream_error'
    || reason === 'artifact_did_not_contact_server'
    || reason === 'artifact_did_not_contact_surface'
    || reason === 'advertised_operation_not_observed'
    || reason === 'artifact_did_not_report_waterline_render_response';
}

function isWaterlineSurfaceMissing(response) {
  const reason = response?.body?.reason;
  if (reason === 'not_found' || reason === 'route_not_found') {
    return true;
  }

  if (response.status !== 404) {
    return false;
  }

  const bodyText = JSON.stringify(response.body ?? '').toLowerCase();
  return !bodyText.includes('compat')
    && !bodyText.includes('version')
    && !bodyText.includes('skew');
}

function waterlineCoverageGapReason(operationGroup, status, response) {
  if (operationGroup !== 'waterline_render' || status !== 'not_covered') {
    return '';
  }

  if (isWaterlineSurfaceMissing(response)) {
    return 'The running Waterline surface did not serve the advertised render route; route-missing responses are not valid render_refused evidence.';
  }

  if (isWaterlineTransportFailure(response)) {
    return 'The running Waterline surface was unavailable or returned a transport failure; no render classification was captured.';
  }

  return '';
}

export function loudRefusalContext(surfaceName, surfaceVersion, context, pairing, response) {
  const result = {
    surface: surfaceName,
    surface_version: surfaceVersion,
    observed_client_or_worker_version: surfaceVersion,
    server_version: context.observedServerVersion,
    requested_control_plane_version: pairing.controlPlaneVersion,
    requested_worker_protocol_version: pairing.workerProtocolVersion,
    supported_control_plane_version: context.protocolAuthority.control_plane.version,
    supported_worker_protocol_version: context.protocolAuthority.worker_protocol.version,
    compatibility_window: pairing.compatibilityWindow,
    protocol_or_manifest: surfaceName === 'sdk-php' ? 'worker_protocol' : 'control_plane',
    next_step: compatibilityNextStep(surfaceName, 'outside_window', context),
    response_reason: response?.body?.reason ?? response?.reason ?? null,
  };
  if (surfaceName === 'sdk-php') {
    result.worker_version = surfaceVersion;
    result.sdk_php_package_version = surfaceVersion;
    result.worker_protocol_version = pairing.workerProtocolVersion;
  }

  return result;
}

function compatibilityNextStep(surfaceName, pairingClass, context) {
  const surface = surfaces[surfaceName];
  const surfaceVersion = stringValue(context.artifactVersions?.[surface?.artifact]);
  const serverVersion = stringValue(context.observedServerVersion);
  const controlPlaneVersion = context.protocolAuthority.control_plane.version;
  const workerProtocolVersion = context.protocolAuthority.worker_protocol.version;
  if (pairingClass === 'compatible') {
    return `Keep ${surfaceName} ${surfaceVersion} pinned with Server ${serverVersion}; this exact tuple uses control-plane ${controlPlaneVersion} and worker protocol ${workerProtocolVersion}.`;
  }

  return `Use control-plane ${controlPlaneVersion} and worker protocol ${workerProtocolVersion}; pin ${surfaceName} ${surfaceVersion} with Server ${serverVersion}, or upgrade to artifacts that advertise those protocol versions.`;
}

function isProtocolRefusal(response) {
  const reason = response?.body?.reason;
  return response.status === 400
    && (
      reason === 'missing_control_plane_version'
      || reason === 'unsupported_control_plane_version'
      || reason === 'missing_protocol_version'
      || reason === 'unsupported_protocol_version'
      || reason === 'artifact_compatibility_refusal'
    );
}

export function materializeRequest(template, runId, state = {}) {
  const parts = template.split(' ');
  const method = parts[0];
  let requestPath = parts.slice(1).join(' ');
  const idReplacement = requestPath.includes('/waterline/api/flows/{id}')
    ? state.workflowId || `skew-${runId}`
    : state.scheduleId || `schedule-${runId}`;
  const replacements = {
    '{workflowId}': state.workflowId || `skew-${runId}`,
    '{runId}': state.runId || `run-${runId}`,
    '{signalName}': 'advance',
    '{queryName}': 'currentState',
    '{updateName}': 'approve',
    '{task}': state.taskId || 'poll-task-id-required',
    '{id}': idReplacement,
  };

  for (const [from, to] of Object.entries(replacements)) {
    requestPath = requestPath.replaceAll(from, to);
  }

  return { method, path: requestPath };
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

function sleep(milliseconds) {
  return new Promise((resolve) => setTimeout(resolve, milliseconds));
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

function compatibilityWindowReport(protocolAuthority) {
  return {
    authority_source: protocolAuthority.source,
    prerelease_package_policy: protocolAuthority.prerelease_package_policy,
    control_plane: {
      supported_version: protocolAuthority.control_plane.version,
      enforced_header: protocolAuthority.control_plane.header,
      window: 'exact control-plane version match required',
    },
    worker_protocol: {
      supported_version: protocolAuthority.worker_protocol.version,
      enforced_header: protocolAuthority.worker_protocol.header,
      accepted_versions: protocolAuthority.worker_protocol.accepted_versions,
      window: protocolAuthority.worker_protocol.request_rule,
    },
  };
}

function futureBoundaryReport(pairingResults, operationEvidence, protocolAuthority, pairingClasses) {
  return {
    protocol_authority: protocolAuthority,
    client: {
      surface: 'cli',
      pairing_class: 'forward_skew',
      ...protocolScenarioFields(pairingClasses.forward_skew),
      outcome: pairingResults.cli.forward_skew.status,
      evidence_cells: Object.keys(operationEvidence.cli.forward_skew),
    },
    worker: {
      surface: 'sdk-php',
      pairing_class: 'forward_skew',
      ...protocolScenarioFields(pairingClasses.forward_skew),
      outcome: pairingResults['sdk-php'].forward_skew.status,
      classification: pairingResults['sdk-php'].forward_skew.worker_skew_classification,
      evidence_cells: Object.keys(operationEvidence['sdk-php'].forward_skew),
    },
    observer: {
      surface: 'waterline',
      pairing_class: 'forward_skew',
      ...protocolScenarioFields(pairingClasses.forward_skew),
      outcome: pairingResults.waterline.forward_skew.status,
      classification: pairingResults.waterline.forward_skew.waterline_skew_classification,
      evidence_cells: Object.keys(operationEvidence.waterline.forward_skew),
    },
    server: {
      surface: 'server',
      pairing_class: 'outside_window',
      ...protocolScenarioFields(pairingClasses.outside_window),
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
    'sdk-php': stringValue(versions['sdk-php']) || envValue('DW_PHP_SDK_VERSION'),
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
    'sdk-php': stringValue(sources['sdk-php']) || 'not_installed',
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
    'sdk-php': artifactVersions['sdk-php'] || null,
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

function executableOnPath(name) {
  const pathValue = process.env.PATH ?? '';
  for (const directory of pathValue.split(path.delimiter)) {
    if (!directory) {
      continue;
    }

    try {
      fs.accessSync(path.join(directory, name), fs.constants.X_OK);
      return true;
    } catch {
    }
  }

  return false;
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
  return isExactSemverRelease(String(value).trim());
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

function integerValue(value) {
  if (Number.isInteger(value)) {
    return value;
  }

  if (typeof value === 'string' && /^-?\d+$/.test(value.trim())) {
    return Number.parseInt(value.trim(), 10);
  }

  return null;
}

function isHttpUrl(value) {
  try {
    const parsed = new URL(value);
    return parsed.protocol === 'http:' || parsed.protocol === 'https:';
  } catch {
    return false;
  }
}

function trimTrailingSlash(value) {
  return value.replace(/\/+$/, '');
}

function isMainModule() {
  if (process.execArgv.some((arg) => arg === '-e' || arg === '--eval' || arg.startsWith('--eval='))) {
    return false;
  }

  return Boolean(process.argv[1]) && path.resolve(process.argv[1]) === modulePath;
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
