#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage: nexus-published-artifacts.sh [--result-dir DIR|--result-dir=DIR]

Composes the public Nexus conformance result from published-artifact evidence.
The runner never treats a local product checkout as an artifact under test.
Missing Nexus cells are recorded as not_covered with linked coverage findings,
so a host run that reaches this handoff is product/coverage evidence rather
than runner-blocked evidence.

The runner writes these files to the result directory:
  pins.json
  nexus-conformance-result.json
  nexus-conformance-record.json

Environment overrides:
  DW_NEXUS_RESULT_DIR              Result directory. Defaults to a temp dir.
  DW_NEXUS_EVIDENCE_JSON           Optional host evidence JSON with scenario_results.
  DW_NEXUS_ARTIFACT_INSTALL_EVIDENCE
                                    Optional dedicated install-evidence JSON. When
                                    omitted, no result-dir evidence file is reused.
  DW_NEXUS_SKIP_SHARED_SERVICE_PROBE=1
                                    Skip the built-in shared-service probe when
                                    no host evidence JSON is supplied.
  DW_NEXUS_KEEP_RUN_ROOT=1          Keep the probe scratch directory.
  DW_NEXUS_SERVER_PORT              Host port for the published server probe.
  DW_NEXUS_SKIP_DOCKER_PULL=1       Reuse a local server image for the probe.
  DW_SERVER_IMAGE                   Exact published server image/tag/digest.
  DW_SERVER_VERSION                Exact published server artifact version.
  DW_CLI_VERSION                   Exact published CLI version.
  DW_WORKFLOW_PHP_VERSION          Exact published Workflow PHP package version.
  DW_PYTHON_SDK_VERSION            Exact published Python SDK package version.
  DW_WATERLINE_VERSION             Exact published Waterline package version.
  DW_SERVER_ARTIFACT_SOURCE        Published server artifact source.
  DW_CLI_ARTIFACT_SOURCE           Published CLI artifact source.
  DW_WORKFLOW_ARTIFACT_SOURCE      Published Workflow PHP artifact source.
  DW_PYTHON_SDK_ARTIFACT_SOURCE    Published Python SDK artifact source.
  DW_WATERLINE_ARTIFACT_SOURCE     Published Waterline artifact source.
USAGE
}

result_dir="${DW_NEXUS_RESULT_DIR:-}"

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

if ! command -v node >/dev/null 2>&1; then
  printf '%s\n' 'required command not found: node' >&2
  exit 127
fi

if [[ -z "$result_dir" ]]; then
  result_dir="$(mktemp -d "${TMPDIR:-/tmp}/dw-nexus.XXXXXX")"
fi
mkdir -p "$result_dir"

if [[ -z "${DW_NEXUS_EVIDENCE_JSON:-}" && "${DW_NEXUS_SKIP_SHARED_SERVICE_PROBE:-0}" != "1" ]]; then
  generated_evidence_path="$result_dir/shared-service-evidence.json"

  if node - "$result_dir" "$generated_evidence_path" <<'NODE'
const fs = require('fs');
const os = require('os');
const net = require('net');
const path = require('path');
const crypto = require('crypto');
const {spawnSync} = require('child_process');

const resultDir = process.argv[2];
const evidencePath = process.argv[3];
const requiredArtifacts = ['server', 'cli', 'workflow', 'sdk-python', 'waterline'];
const artifactOwners = {
  server: 'server',
  cli: 'cli',
  workflow: 'workflow',
  'sdk-python': 'sdk-python',
  waterline: 'waterline',
};
const scenarioIds = [
  'tenant_a_calls_shared_service',
  'tenant_b_calls_shared_service',
];

function timestamp() {
  return new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
}

function env(name) {
  const value = process.env[name];
  return typeof value === 'string' && value.trim() !== '' ? value.trim() : null;
}

function randomToken(prefix) {
  return `${prefix}-${crypto.randomBytes(12).toString('hex')}`;
}

function ulidLike() {
  return `01${crypto.randomBytes(12).toString('hex').toUpperCase()}`.slice(0, 26);
}

function exactServerVersionFrom(image) {
  const withoutDigest = image.split('@', 1)[0];
  const last = withoutDigest.split('/').pop() || '';
  const tag = last.includes(':') ? last.split(':').pop() : '';
  return /^\d+\.\d+\.\d+$/.test(tag) ? tag : null;
}

function serverImage() {
  const explicit = env('DW_SERVER_IMAGE');
  if (explicit !== null) {
    return explicit.replace(/^docker:\/\//, '');
  }

  const source = env('DW_SERVER_ARTIFACT_SOURCE');
  if (source !== null && /^(docker:\/\/)?durableworkflow\/server[:@]/.test(source)) {
    return source.replace(/^docker:\/\//, '');
  }

  const version = env('DW_SERVER_VERSION');
  return version === null ? null : `durableworkflow/server:${version}`;
}

function artifactVersions(image) {
  return {
    server: env('DW_SERVER_VERSION') || (image === null ? null : exactServerVersionFrom(image)),
    cli: env('DW_CLI_VERSION'),
    workflow: env('DW_WORKFLOW_PHP_VERSION') || env('DW_WORKFLOW_VERSION'),
    'sdk-python': env('DW_PYTHON_SDK_VERSION') || env('DW_SDK_PYTHON_VERSION'),
    waterline: env('DW_WATERLINE_VERSION'),
  };
}

function compactObject(object) {
  return Object.fromEntries(Object.entries(object).filter(([, value]) => value !== null && value !== undefined && value !== ''));
}

function artifactSources(versions, image) {
  return compactObject({
    server: env('DW_SERVER_ARTIFACT_SOURCE')
      || (image === null ? null : `docker://${image}`),
    cli: env('DW_CLI_ARTIFACT_SOURCE')
      || (versions.cli ? `https://github.com/durable-workflow/cli/releases/download/${versions.cli}/install.sh` : null),
    workflow: env('DW_WORKFLOW_ARTIFACT_SOURCE')
      || env('DW_WORKFLOW_PHP_ARTIFACT_SOURCE')
      || (versions.workflow ? `packagist://durable-workflow/workflow@${versions.workflow}` : null),
    'sdk-python': env('DW_PYTHON_SDK_ARTIFACT_SOURCE')
      || (versions['sdk-python'] ? `pypi://durable-workflow==${versions['sdk-python']}` : null),
    waterline: env('DW_WATERLINE_ARTIFACT_SOURCE')
      || (versions.waterline ? `packagist://durable-workflow/waterline@${versions.waterline}` : null),
  });
}

async function httpDownloadable(url) {
  const headers = {'User-Agent': 'durable-workflow-nexus-conformance'};
  for (const method of ['HEAD', 'GET']) {
    const requestHeaders = {...headers};
    if (method === 'GET') {
      requestHeaders.Range = 'bytes=0-0';
    }
    try {
      const response = await fetch(url, {
        method,
        headers: requestHeaders,
        redirect: 'follow',
      });
      if (response.status >= 200 && response.status < 400) {
        return true;
      }
    } catch {
      if (method === 'GET') {
        return false;
      }
    }
  }

  return false;
}

async function fetchJson(url) {
  const response = await fetch(url, {
    headers: {'User-Agent': 'durable-workflow-nexus-conformance'},
    redirect: 'follow',
  });
  if (!response.ok) {
    throw new Error(`${url} returned HTTP ${response.status}`);
  }

  return response.json();
}

async function verifyGithubReleaseAsset(version, source) {
  if (!await httpDownloadable(source)) {
    throw new Error(`CLI release asset is not downloadable: ${source}`);
  }

  return {
    version,
    source,
    status: 'asset_resolved',
    downloadable: true,
    asset_exists: true,
    verified_at: timestamp(),
  };
}

async function verifyPackagistPackage(packageName, version, source) {
  const metadataUrl = `https://repo.packagist.org/p2/${packageName}.json`;
  const payload = await fetchJson(metadataUrl);
  const versions = Array.isArray(payload.packages?.[packageName])
    ? payload.packages[packageName]
    : [];
  if (!versions.some((entry) => String(entry.version || '') === version)) {
    throw new Error(`Packagist package ${packageName} does not publish ${version}`);
  }

  return {
    version,
    source,
    status: 'package_resolved',
    package_exists: true,
    manifest_resolved: true,
    metadata_url: `${metadataUrl}#${version}`,
    verified_at: timestamp(),
  };
}

async function verifyPypiPackage(version, source) {
  const metadataUrl = `https://pypi.org/pypi/durable-workflow/${encodeURIComponent(version)}/json`;
  const payload = await fetchJson(metadataUrl);
  if (String(payload.info?.version || '') !== version) {
    throw new Error(`PyPI durable-workflow metadata resolved ${payload.info?.version || '<missing>'}, expected ${version}`);
  }

  return {
    version,
    source,
    status: 'package_resolved',
    package_exists: true,
    manifest_resolved: true,
    metadata_url: metadataUrl,
    verified_at: timestamp(),
  };
}

async function verifyPublishedArtifactSource(artifact, version, source, serverDigest) {
  switch (artifact) {
    case 'server':
      if (!serverDigest || !/@sha256:[0-9a-f]{64}$/i.test(serverDigest)) {
        throw new Error('server image digest was not resolved after pull');
      }
      return {
        version,
        source,
        status: 'image_manifest_resolved',
        downloadable: true,
        manifest_resolved: true,
        image_digest: serverDigest,
        verified_at: timestamp(),
      };
    case 'cli':
      return verifyGithubReleaseAsset(version, source);
    case 'workflow':
      return verifyPackagistPackage('durable-workflow/workflow', version, source);
    case 'sdk-python':
      return verifyPypiPackage(version, source);
    case 'waterline':
      return verifyPackagistPackage('durable-workflow/waterline', version, source);
    default:
      throw new Error(`unsupported artifact ${artifact}`);
  }
}

async function artifactSourceVerification(versions, sources, serverDigest) {
  const verification = {};
  const failures = [];
  for (const artifact of requiredArtifacts) {
    if (!versions[artifact] || !sources[artifact]) {
      failures.push({
        artifact,
        reason: `missing ${artifact} version or source`,
      });
      continue;
    }
    try {
      verification[artifact] = await verifyPublishedArtifactSource(
        artifact,
        versions[artifact],
        sources[artifact],
        serverDigest,
      );
    } catch (error) {
      verification[artifact] = {
        version: versions[artifact],
        source: sources[artifact],
        status: 'resolution_failed',
        downloadable: false,
        error: `${error.name}: ${error.message}`,
        verified_at: timestamp(),
      };
      failures.push({
        artifact,
        reason: `${error.name}: ${error.message}`,
      });
    }
  }

  return {verification, failures};
}

function commandAvailable(command, args = ['--version']) {
  const result = spawnSync(command, args, {encoding: 'utf8'});
  return result.status === 0;
}

function runLogged(command, args, logPath, options = {}) {
  const result = spawnSync(command, args, {
    encoding: 'utf8',
    maxBuffer: 16 * 1024 * 1024,
    ...options,
  });
  fs.writeFileSync(
    logPath,
    [
      `$ ${command} ${args.join(' ')}`,
      `exit_status=${result.status ?? 'null'}`,
      result.stdout || '',
      result.stderr || '',
    ].join('\n'),
  );

  if (result.status !== 0) {
    throw new Error(`${command} ${args.join(' ')} failed; see ${logPath}`);
  }

  return result.stdout || '';
}

function freePort() {
  const requested = env('DW_NEXUS_SERVER_PORT');
  if (requested !== null) {
    return Promise.resolve(Number(requested));
  }

  return new Promise((resolve, reject) => {
    const server = net.createServer();
    server.once('error', reject);
    server.listen(0, '127.0.0.1', () => {
      const address = server.address();
      const port = typeof address === 'object' && address !== null ? address.port : 0;
      server.close(() => resolve(port));
    });
  });
}

function composeYaml(image, port, token) {
  const escapedImage = JSON.stringify(image);
  const escapedToken = JSON.stringify(token);
  return `
services:
  bootstrap:
    image: ${escapedImage}
    command: ["server-bootstrap"]
    environment: &server_environment
      APP_ENV: local
      APP_DEBUG: "false"
      DB_CONNECTION: mysql
      DB_HOST: mysql
      DB_PORT: 3306
      DB_DATABASE: durable_workflow
      DB_USERNAME: workflow
      DB_PASSWORD: workflow
      REDIS_HOST: redis
      QUEUE_CONNECTION: redis
      CACHE_STORE: redis
      DW_AUTH_DRIVER: token
      DW_AUTH_TOKEN: ${escapedToken}
      DW_AUTH_BACKWARD_COMPATIBLE: "true"
    depends_on:
      mysql:
        condition: service_healthy
      redis:
        condition: service_healthy
  server:
    image: ${escapedImage}
    ports:
      - "127.0.0.1:${port}:8080"
    environment:
      <<: *server_environment
      DW_SERVER_TOPOLOGY_SHAPE: standalone_server
      DW_SERVER_PROCESS_CLASS: server_http_node
    depends_on:
      bootstrap:
        condition: service_completed_successfully
      mysql:
        condition: service_healthy
      redis:
        condition: service_healthy
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:8080/api/ready"]
      interval: 5s
      timeout: 5s
      retries: 24
  worker:
    image: ${escapedImage}
    command: php artisan queue:work --sleep=1 --tries=3 --max-time=3600
    environment:
      <<: *server_environment
      DW_SERVER_TOPOLOGY_SHAPE: standalone_server
      DW_SERVER_PROCESS_CLASS: worker_node
    depends_on:
      bootstrap:
        condition: service_completed_successfully
      server:
        condition: service_healthy
      mysql:
        condition: service_healthy
      redis:
        condition: service_healthy
  mysql:
    image: mysql:8.0
    environment:
      MYSQL_DATABASE: durable_workflow
      MYSQL_USER: workflow
      MYSQL_PASSWORD: workflow
      MYSQL_ROOT_PASSWORD: root
    volumes:
      - mysql_data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost"]
      interval: 5s
      timeout: 3s
      retries: 30
  redis:
    image: redis:7-alpine
    volumes:
      - redis_data:/data
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 5s
      timeout: 3s
      retries: 10
volumes:
  mysql_data:
  redis_data:
`;
}

async function waitForReady(baseUrl, timeoutMs) {
  const deadline = Date.now() + timeoutMs;
  let lastError = '';
  while (Date.now() < deadline) {
    try {
      const response = await fetch(`${baseUrl}/api/ready`);
      if (response.ok) {
        return;
      }
      lastError = `${response.status} ${await response.text().catch(() => '')}`.trim();
    } catch (error) {
      lastError = `${error.name}: ${error.message}`;
    }
    await new Promise((resolve) => setTimeout(resolve, 1000));
  }
  throw new Error(`server did not become ready: ${lastError}`);
}

async function apiRequest(baseUrl, token, namespace, method, apiPath, body = null) {
  const headers = {
    Authorization: `Bearer ${token}`,
    'X-Durable-Workflow-Control-Plane-Version': '2',
    'X-Namespace': namespace,
    Accept: 'application/json',
  };
  const init = {method, headers};
  if (body !== null) {
    headers['Content-Type'] = 'application/json';
    init.body = JSON.stringify(body);
  }

  const response = await fetch(`${baseUrl}/api${apiPath}`, init);
  const rawBody = await response.text();
  let parsed = null;
  try {
    parsed = rawBody === '' ? null : JSON.parse(rawBody);
  } catch {
    parsed = {raw_body: rawBody};
  }

  return {
    request: {method, path: `/api${apiPath}`, namespace, body},
    status: response.status,
    ok: response.ok,
    body: parsed,
    raw_body: rawBody,
  };
}

function productFinding(scenarioId, versions, observed, expected, next, type = 'shared_service_tenant_invocation_failed') {
  return {
    scenario_id: scenarioId,
    type,
    finding_type: type,
    owning_surface: 'server',
    artifact_versions: compactObject(versions),
    observed_behavior: observed,
    expected_behavior: expected,
    next_acceptance_criterion: next,
  };
}

function failureScenario(scenarioId, versions, reason, evidence = {}) {
  return {
    scenario_id: scenarioId,
    status: 'fail',
    observed_outputs: {
      caller_namespace: scenarioId === 'tenant_a_calls_shared_service' ? 'tenant-a' : 'tenant-b',
      target_namespace: 'shared',
      endpoint_name: 'shared-greeter',
      service_name: 'Greeter',
      operation_name: 'greet',
      error_shape: evidence,
      failure_reason: reason,
    },
    linked_findings: [
      productFinding(
        scenarioId,
        versions,
        `${scenarioId} failed: ${reason}. Observed ${JSON.stringify(evidence).slice(0, 1000)}`,
        'tenant-a and tenant-b can invoke shared:Greeter.greet through the published Nexus service-call surface and inspect request, response, durable service-call, and caller-history evidence.',
        `fix the shared-service Nexus invocation path for ${scenarioId} and rerun the published-artifact Nexus conformance probe`,
      ),
    ],
  };
}

function passScenario(scenarioId, callerNamespace, request, response, serviceCallRecord, callerHistory) {
  const serviceCallId = response.body && response.body.service_call_id
    ? String(response.body.service_call_id)
    : String(serviceCallRecord.body?.service_call_id || serviceCallRecord.body?.id || '');

  return {
    scenario_id: scenarioId,
    status: 'pass',
    observed_outputs: {
      caller_namespace: callerNamespace,
      target_namespace: 'shared',
      endpoint_name: 'shared-greeter',
      service_name: 'Greeter',
      operation_name: 'greet',
      service_call_id: serviceCallId,
      workflow_result: String(response.body?.status || response.body?.outcome || 'accepted'),
      request: request.request,
      response: {
        status: response.status,
        body: response.body,
      },
      service_call_record: serviceCallRecord.body,
      caller_history_evidence: callerHistory.body,
      caller_history_recorded: true,
    },
    linked_findings: [],
  };
}

async function ensureNamespace(baseUrl, token, namespace) {
  const response = await apiRequest(baseUrl, token, 'default', 'POST', '/namespaces', {
    name: namespace,
    description: `Nexus conformance namespace ${namespace}`,
  });
  if (![200, 201, 409].includes(response.status)) {
    throw new Error(`namespace ${namespace} create failed: ${JSON.stringify(response.body)}`);
  }
  return response;
}

async function setupSharedService(baseUrl, token) {
  const endpoint = await apiRequest(baseUrl, token, 'shared', 'POST', '/service-endpoints', {
    endpoint_name: 'shared-greeter',
    description: 'Nexus conformance shared Greeter endpoint',
    metadata: {conformance: 'nexus-shared-service'},
  });
  if (![200, 201, 409].includes(endpoint.status)) {
    throw new Error(`endpoint create failed: ${JSON.stringify(endpoint.body)}`);
  }

  const service = await apiRequest(baseUrl, token, 'shared', 'POST', '/service-endpoints/shared-greeter/services', {
    service_name: 'Greeter',
    description: 'Shared Greeter service for Nexus conformance',
    metadata: {conformance: 'nexus-shared-service'},
  });
  if (![200, 201, 409].includes(service.status)) {
    throw new Error(`service create failed: ${JSON.stringify(service.body)}`);
  }

  const operation = await apiRequest(baseUrl, token, 'shared', 'POST', '/service-endpoints/shared-greeter/services/Greeter/operations', {
    operation_name: 'greet',
    description: 'Return a greeting for the supplied name',
    operation_mode: 'async',
    handler_binding_kind: 'activity_execution',
    handler_target_reference: 'Greeter.greet',
    handler_binding: {
      activity_type: 'Greeter.greet',
    },
    retry_policy: {
      maximum_attempts: 3,
      initial_interval_seconds: 1,
    },
    boundary_policy: {
      authorization: {
        caller_namespaces: {
          allow: ['tenant-a', 'tenant-b'],
        },
      },
    },
    metadata: {conformance: 'nexus-shared-service'},
  });
  if (![200, 201, 409].includes(operation.status)) {
    throw new Error(`operation create failed: ${JSON.stringify(operation.body)}`);
  }

  return {endpoint, service, operation};
}

async function invokeSharedService(baseUrl, token, callerNamespace, versions) {
  const scenarioId = callerNamespace === 'tenant-a'
    ? 'tenant_a_calls_shared_service'
    : 'tenant_b_calls_shared_service';
  const callerWorkflowInstanceId = `${callerNamespace}-call-greeter`;
  const callerWorkflowRunId = ulidLike();
  const requestBody = {
    arguments: {
      name: 'world',
      caller_namespace: callerNamespace,
    },
    mode_override: 'async',
    wait_for: 'accepted',
    caller_namespace: callerNamespace,
    caller_workflow_instance_id: callerWorkflowInstanceId,
    caller_workflow_run_id: callerWorkflowRunId,
    idempotency_key: `${callerNamespace}-${crypto.randomBytes(6).toString('hex')}`,
    metadata: {
      conformance: 'nexus-shared-service',
      expected_greeting: 'hello, world',
    },
  };
  const execute = await apiRequest(
    baseUrl,
    token,
    'shared',
    'POST',
    '/service-endpoints/shared-greeter/services/Greeter/operations/greet/execute',
    requestBody,
  );

  if (!execute.ok || execute.body?.accepted !== true || !execute.body?.service_call_id) {
    return failureScenario(scenarioId, versions, 'execute returned a non-accepted response', {
      request: execute.request,
      status: execute.status,
      body: execute.body,
    });
  }

  const serviceCallId = String(execute.body.service_call_id);
  const describe = await apiRequest(
    baseUrl,
    token,
    'shared',
    'GET',
    `/service-endpoints/shared-greeter/services/Greeter/operations/greet/service-calls/${encodeURIComponent(serviceCallId)}`,
  );
  const history = await apiRequest(
    baseUrl,
    token,
    callerNamespace,
    'GET',
    `/workflows/${encodeURIComponent(callerWorkflowInstanceId)}/runs/${encodeURIComponent(callerWorkflowRunId)}/nexus-operations`,
  );
  const historyRows = Array.isArray(history.body?.nexus_operations) ? history.body.nexus_operations : [];
  const historyContainsCall = historyRows.some((row) => String(row.service_call_id || '') === serviceCallId);

  if (!describe.ok || describe.body?.found !== true) {
    return failureScenario(scenarioId, versions, 'service-call describe did not return the durable call', {
      execute: {status: execute.status, body: execute.body},
      describe: {status: describe.status, body: describe.body},
    });
  }
  if (!history.ok || !historyContainsCall) {
    return failureScenario(scenarioId, versions, 'caller-history evidence did not include the durable call', {
      execute: {status: execute.status, body: execute.body},
      history: {status: history.status, body: history.body},
    });
  }

  return passScenario(scenarioId, callerNamespace, execute, execute, describe, history);
}

function blockedEvidence(startedAt, finishedAt, versions, sources, reason) {
  return {
    outcome: 'non_passing_runner_blocked',
    runner_blocked: true,
    blocked_reason: reason,
    started_at: startedAt,
    finished_at: finishedAt,
    artifact_versions: compactObject(versions),
    artifact_sources: sources,
    artifact_source_verification: {},
    local_product_source_checkouts_used: false,
    findings: scenarioIds.map((scenarioId) => ({
      scenario_id: scenarioId,
      type: 'runner_gap',
      finding_type: 'runner_gap',
      owning_surface: 'conformance_harness',
      artifact_versions: compactObject(versions),
      observed_behavior: `Nexus shared-service probe was runner-blocked: ${reason}`,
      expected_behavior: 'host runner can start the published server image and exercise shared-service Nexus calls',
      next_acceptance_criterion: `restore host execution for ${scenarioId} and rerun Nexus conformance`,
    })),
    scenario_results: {},
  };
}

function artifactResolutionEvidence(startedAt, finishedAt, versions, sources, verification, failures) {
  const findings = failures.map((failure) => ({
    scenario_id: 'published_artifact_install_only',
    type: 'missing_or_invalid_published_nexus_artifact',
    finding_type: 'missing_or_invalid_published_nexus_artifact',
    owning_surface: artifactOwners[failure.artifact] || 'conformance_harness',
    artifact_versions: compactObject(versions),
    observed_behavior: `${failure.artifact} published artifact source did not resolve: ${failure.reason}`,
    expected_behavior: 'every required Nexus artifact source resolves to a downloadable public artifact before shared-service proof is recorded',
    next_acceptance_criterion: `resolve the ${failure.artifact} published artifact source and rerun the Nexus shared-service probe`,
  }));

  return {
    outcome: 'fail',
    runner_blocked: false,
    started_at: startedAt,
    finished_at: finishedAt,
    artifact_versions: compactObject(versions),
    published_artifact_versions: compactObject(versions),
    resolved_artifact_versions: compactObject(versions),
    artifact_sources: sources,
    artifact_source_verification: verification,
    local_product_source_checkouts_used: false,
    findings,
    scenario_results: {
      published_artifact_install_only: {
        status: 'not_covered',
        observed_outputs: {
          artifact_versions: compactObject(versions),
          artifact_sources: sources,
          artifact_source_verification: verification,
          local_product_source_checkouts_used: false,
          install_channels_verified: false,
          resolution_failures: failures,
        },
        linked_findings: findings,
      },
    },
  };
}

function artifactInstallEvidence(versions, sources, verification, localProductSourceCheckoutsUsed) {
  return {
    schema: 'durable-workflow.v2.nexus-runtime.install-evidence',
    published_install_tuple_proven: true,
    local_product_source_checkouts_used: localProductSourceCheckoutsUsed,
    artifacts: requiredArtifacts.map((artifact) => ({
      artifact,
      version: versions[artifact],
      source: sources[artifact],
      install_channel: installChannelForArtifact(artifact),
      source_verification: verification[artifact],
      local_product_source_checkout_used_as_artifact: false,
      status: 'pass',
    })),
  };
}

function installChannelForArtifact(artifact) {
  switch (artifact) {
    case 'server':
      return 'docker';
    case 'cli':
      return 'github_release_asset';
    case 'workflow':
    case 'waterline':
      return 'packagist';
    case 'sdk-python':
      return 'pypi';
    default:
      return 'published_artifact_channel';
  }
}

function productFailureEvidence(startedAt, finishedAt, versions, sources, verification, reason, details = {}) {
  const installEvidence = artifactInstallEvidence(versions, sources, verification, false);
  const scenarioResults = scenarioIds.map((scenarioId) => failureScenario(
    scenarioId,
    versions,
    reason,
    details,
  ));

  return {
    outcome: 'fail',
    runner_blocked: false,
    started_at: startedAt,
    finished_at: finishedAt,
    artifact_versions: compactObject(versions),
    published_artifact_versions: compactObject(versions),
    resolved_artifact_versions: compactObject(versions),
    artifact_sources: sources,
    artifact_source_verification: verification,
    artifact_install_evidence: installEvidence,
    local_product_source_checkouts_used: false,
    findings: scenarioResults.flatMap((scenario) => scenario.linked_findings || []),
    scenario_results: {
      published_artifact_install_only: {
        status: 'pass',
        observed_outputs: {
          artifact_versions: compactObject(versions),
          artifact_sources: sources,
          artifact_source_verification: verification,
          local_product_source_checkouts_used: false,
          install_channels_verified: true,
          published_install_tuple_proven: true,
          artifact_install_evidence: installEvidence,
        },
        linked_findings: [],
      },
      tenant_a_calls_shared_service: scenarioResults[0],
      tenant_b_calls_shared_service: scenarioResults[1],
    },
  };
}

async function main() {
  fs.mkdirSync(resultDir, {recursive: true});
  const startedAt = timestamp();
  const image = serverImage();
  const versions = artifactVersions(image);
  const sources = artifactSources(versions, image);

  const writeEvidence = (evidence) => {
    fs.writeFileSync(evidencePath, JSON.stringify(evidence, null, 2) + '\n');
  };

  if (image === null) {
    process.exitCode = 3;
    return;
  }
  if (!commandAvailable('docker')) {
    writeEvidence(blockedEvidence(startedAt, timestamp(), versions, sources, 'required command not found: docker'));
    return;
  }
  if (!commandAvailable('docker', ['compose', 'version'])) {
    writeEvidence(blockedEvidence(startedAt, timestamp(), versions, sources, 'required command not available: docker compose'));
    return;
  }

  const runRoot = env('DW_NEXUS_RUN_ROOT') || fs.mkdtempSync(path.join(os.tmpdir(), 'dw-nexus-shared-service.'));
  fs.mkdirSync(runRoot, {recursive: true});
  const port = await freePort();
  const baseUrl = `http://127.0.0.1:${port}`;
  const token = randomToken('nexus-token');
  const project = `dw-nexus-${crypto.randomBytes(5).toString('hex')}`;
  const composePath = path.join(runRoot, 'compose.yml');
  fs.writeFileSync(composePath, composeYaml(image, port, token));

  let serverDigest = '';
  try {
    if (env('DW_NEXUS_SKIP_DOCKER_PULL') !== '1') {
      runLogged('docker', ['pull', image], path.join(resultDir, 'nexus-shared-service-docker-pull.log'));
    }

    serverDigest = runLogged(
      'docker',
      ['image', 'inspect', '--format', '{{if .RepoDigests}}{{index .RepoDigests 0}}{{end}}', image],
      path.join(resultDir, 'nexus-shared-service-image-inspect.log'),
    ).trim();

    const {verification, failures} = await artifactSourceVerification(versions, sources, serverDigest);
    if (failures.length > 0) {
      writeEvidence(artifactResolutionEvidence(
        startedAt,
        timestamp(),
        versions,
        sources,
        verification,
        failures,
      ));
      return;
    }

    runLogged(
      'docker',
      ['compose', '-p', project, '-f', composePath, 'up', '-d', '--wait'],
      path.join(resultDir, 'nexus-shared-service-compose-up.log'),
      {cwd: runRoot},
    );
    await waitForReady(baseUrl, 120000);

    let namespaceResponses = [];
    let registration = null;
    let scenarioResults = [];
    try {
      for (const namespace of ['tenant-a', 'tenant-b', 'shared']) {
        namespaceResponses.push(await ensureNamespace(baseUrl, token, namespace));
      }
      registration = await setupSharedService(baseUrl, token);
      scenarioResults = [
        await invokeSharedService(baseUrl, token, 'tenant-a', versions),
        await invokeSharedService(baseUrl, token, 'tenant-b', versions),
      ];
    } catch (error) {
      writeEvidence(productFailureEvidence(
        startedAt,
        timestamp(),
        versions,
        sources,
        verification,
        `shared-service setup or invocation failed: ${error.name}: ${error.message}`,
        {namespace_responses: namespaceResponses, setup_error: `${error.name}: ${error.message}`},
      ));
      return;
    }

    const finishedAt = timestamp();
    const findings = scenarioResults.flatMap((scenario) => scenario.linked_findings || []);
    const installEvidence = artifactInstallEvidence(versions, sources, verification, false);

    writeEvidence({
      outcome: scenarioResults.every((scenario) => scenario.status === 'pass') ? 'pass' : 'fail',
      runner_blocked: false,
      started_at: startedAt,
      finished_at: finishedAt,
      artifact_versions: compactObject(versions),
      published_artifact_versions: compactObject(versions),
      resolved_artifact_versions: compactObject(versions),
      artifact_sources: sources,
      artifact_source_verification: verification,
      artifact_install_evidence: installEvidence,
      local_product_source_checkouts_used: false,
      topology: {
        namespaces: ['tenant-a', 'tenant-b', 'shared'],
        endpoint: 'shared:shared-greeter',
        service: 'Greeter',
        operation: 'greet',
      },
      setup_evidence: {
        server_url: baseUrl,
        namespace_requests: namespaceResponses.map((response) => ({
          request: response.request,
          status: response.status,
          body: response.body,
        })),
        registration: {
          endpoint: {status: registration.endpoint.status, body: registration.endpoint.body},
          service: {status: registration.service.status, body: registration.service.body},
          operation: {status: registration.operation.status, body: registration.operation.body},
        },
      },
      findings,
      scenario_results: {
        published_artifact_install_only: {
          status: 'pass',
          observed_outputs: {
            artifact_versions: compactObject(versions),
            artifact_sources: sources,
            artifact_source_verification: verification,
            local_product_source_checkouts_used: false,
            install_channels_verified: true,
            published_install_tuple_proven: true,
            artifact_install_evidence: installEvidence,
          },
          linked_findings: [],
        },
        tenant_a_calls_shared_service: scenarioResults[0],
        tenant_b_calls_shared_service: scenarioResults[1],
      },
    });
  } catch (error) {
    writeEvidence(blockedEvidence(
      startedAt,
      timestamp(),
      versions,
      sources,
      `${error.name}: ${error.message}`,
    ));
  } finally {
    const down = spawnSync('docker', ['compose', '-p', project, '-f', composePath, 'down', '-v'], {
      cwd: runRoot,
      encoding: 'utf8',
      maxBuffer: 8 * 1024 * 1024,
    });
    fs.writeFileSync(
      path.join(resultDir, 'nexus-shared-service-compose-down.log'),
      [`exit_status=${down.status ?? 'null'}`, down.stdout || '', down.stderr || ''].join('\n'),
    );
    if (env('DW_NEXUS_KEEP_RUN_ROOT') !== '1') {
      fs.rmSync(runRoot, {recursive: true, force: true});
    }
  }
}

main().catch((error) => {
  fs.writeFileSync(evidencePath, JSON.stringify({
    runner_blocked: true,
    blocked_reason: `${error.name}: ${error.message}`,
    findings: [],
    scenario_results: {},
  }, null, 2) + '\n');
});
NODE
  then
    export DW_NEXUS_EVIDENCE_JSON="$generated_evidence_path"
  elif [[ -f "$generated_evidence_path" ]]; then
    export DW_NEXUS_EVIDENCE_JSON="$generated_evidence_path"
  fi
fi

node - "$result_dir" "${DW_NEXUS_EVIDENCE_JSON:-}" "${DW_NEXUS_ARTIFACT_INSTALL_EVIDENCE:-}" <<'NODE'
const fs = require('fs');
const path = require('path');

const resultDir = process.argv[2];
const evidencePath = process.argv[3] || '';
const dedicatedInstallEvidencePath = process.argv[4] || '';

const requiredScenarios = [
  'published_artifact_install_only',
  'tenant_a_calls_shared_service',
  'tenant_b_calls_shared_service',
  'transient_failure_retries_with_policy',
  'permanent_failure_preserves_typed_error',
  'worker_restart_replay_does_not_reissue_call',
  'caller_cancellation_propagates_to_service',
  'php_caller_python_service',
  'python_caller_php_service',
  'endpoint_permission_denied_without_information_leak',
  'malformed_payload_refused_before_dispatch',
  'nonexistent_endpoint_typed_not_found',
  'caller_history_attempt_visibility',
  'result_record_and_product_finding_routing',
];

const allowedStatuses = new Set([
  'pass',
  'fail',
  'unsupported',
  'not_covered',
  'runner_blocked',
]);
const resultRoutingScenarioId = 'result_record_and_product_finding_routing';
const routedNonPassStatuses = new Set([
  'fail',
  'unsupported',
  'not_covered',
  'runner_blocked',
]);
const requiredArtifacts = [
  'server',
  'cli',
  'workflow',
  'sdk-python',
  'waterline',
];
const artifactAliases = {
  workflow: ['workflow-php', 'workflow_php', 'workflowPhp'],
  'sdk-python': ['sdk_python', 'python-sdk', 'pythonSdk'],
};
const artifactOwners = {
  server: 'server',
  cli: 'cli',
  workflow: 'workflow',
  'sdk-python': 'sdk-python',
  waterline: 'waterline',
};
const placeholderVersionTokens = [
  'latest',
  'current',
  'head',
  'unresolved',
  'placeholder',
  '<latest>',
  '${VERSION}',
  '{{ version }}',
];
const rollingSourceRefPattern = /(^|[/:@=?&#._-])(latest|current|head)(?:$|[/:@?&#._-])/i;
const forbiddenSourceTokens = [
  'local_product_source_checkout',
  'workspace_repo_as_artifact_under_test',
  'local_checkout_artifact',
  'local_checkout',
  'local_source_checkout',
  'workspace_repo',
  'rolling_server_image_tag',
  'unverified_artifact_source',
];
const cliReleaseAssetNames = new Set([
  'dw.phar',
  'dw-linux-aarch64',
  'dw-linux-x86_64',
  'dw-macos-aarch64',
  'dw-windows-x86_64.exe',
  'dw.rb',
  'install.sh',
  'install.ps1',
  'verify-release.sh',
  'SHA256SUMS',
]);
const scenarioEvidenceRequirements = {
  published_artifact_install_only: [
    {fields: ['artifact_versions', 'artifactVersions'], kind: 'non_empty_object', expected: 'pinned published versions for every required Nexus artifact'},
    {fields: ['artifact_sources', 'artifactSources'], kind: 'non_empty_object', expected: 'published install source for every required Nexus artifact'},
    {fields: ['artifact_source_verification', 'artifactSourceVerification'], kind: 'non_empty_object', expected: 'host proof that every published artifact source resolved to a downloadable public artifact'},
    {fields: ['local_product_source_checkouts_used', 'localProductSourceCheckoutsUsed'], kind: 'boolean_false', expected: 'explicit proof that no local product source checkout was used'},
    {fields: ['install_channels_verified', 'installChannelsVerified'], kind: 'boolean_true', expected: 'published artifact install channels were exercised successfully'},
    {fields: ['artifact_install_evidence', 'artifactInstallEvidence', 'install_evidence', 'installEvidence'], kind: 'non_empty_object', expected: 'explicit install-only proof for every required published Nexus artifact'},
  ],
  tenant_a_calls_shared_service: [
    {fields: ['caller_namespace', 'callerNamespace'], kind: 'value_equals', value: 'tenant-a', expected: 'tenant-a is recorded as the caller namespace'},
    {fields: ['target_namespace', 'targetNamespace'], kind: 'value_equals', value: 'shared', expected: 'shared is recorded as the target namespace'},
    {fields: ['endpoint_name', 'endpointName'], kind: 'non_empty_string', expected: 'shared Nexus endpoint name invoked by tenant-a'},
    {fields: ['service_name', 'serviceName'], kind: 'value_equals', value: 'Greeter', expected: 'tenant-a invoked the shared Greeter service'},
    {fields: ['operation_name', 'operationName'], kind: 'value_equals', value: 'greet', expected: 'tenant-a invoked Greeter.greet'},
    {fields: ['service_call_id', 'serviceCallId'], kind: 'non_empty_string', expected: 'durable service-call id from the cross-namespace invocation'},
    {fields: ['workflow_result', 'workflowResult'], kind: 'non_empty_string', expected: 'caller workflow result from the shared service'},
    {fields: ['request', 'requestEvidence', 'invocation_request', 'invocationRequest'], kind: 'non_empty_object', expected: 'request evidence for the tenant-a shared-service invocation'},
    {fields: ['response', 'responseEvidence', 'invocation_response', 'invocationResponse'], kind: 'non_empty_object', expected: 'response evidence for the tenant-a shared-service invocation'},
    {fields: ['service_call_record', 'serviceCallRecord', 'service_call_detail', 'serviceCallDetail'], kind: 'non_empty_object', expected: 'service-call record evidence for the tenant-a shared-service invocation'},
    {fields: ['caller_history_evidence', 'callerHistoryEvidence', 'caller_history', 'callerHistory'], kind: 'non_empty_object', expected: 'caller-history evidence for the tenant-a shared-service invocation'},
    {fields: ['caller_history_recorded', 'callerHistoryRecorded'], kind: 'boolean_true', expected: 'caller history includes the Nexus call'},
  ],
  tenant_b_calls_shared_service: [
    {fields: ['caller_namespace', 'callerNamespace'], kind: 'value_equals', value: 'tenant-b', expected: 'tenant-b is recorded as the caller namespace'},
    {fields: ['target_namespace', 'targetNamespace'], kind: 'value_equals', value: 'shared', expected: 'shared is recorded as the target namespace'},
    {fields: ['endpoint_name', 'endpointName'], kind: 'non_empty_string', expected: 'shared Nexus endpoint name invoked by tenant-b'},
    {fields: ['service_name', 'serviceName'], kind: 'value_equals', value: 'Greeter', expected: 'tenant-b invoked the shared Greeter service'},
    {fields: ['operation_name', 'operationName'], kind: 'value_equals', value: 'greet', expected: 'tenant-b invoked Greeter.greet'},
    {fields: ['service_call_id', 'serviceCallId'], kind: 'non_empty_string', expected: 'durable service-call id from the cross-namespace invocation'},
    {fields: ['workflow_result', 'workflowResult'], kind: 'non_empty_string', expected: 'caller workflow result from the shared service'},
    {fields: ['request', 'requestEvidence', 'invocation_request', 'invocationRequest'], kind: 'non_empty_object', expected: 'request evidence for the tenant-b shared-service invocation'},
    {fields: ['response', 'responseEvidence', 'invocation_response', 'invocationResponse'], kind: 'non_empty_object', expected: 'response evidence for the tenant-b shared-service invocation'},
    {fields: ['service_call_record', 'serviceCallRecord', 'service_call_detail', 'serviceCallDetail'], kind: 'non_empty_object', expected: 'service-call record evidence for the tenant-b shared-service invocation'},
    {fields: ['caller_history_evidence', 'callerHistoryEvidence', 'caller_history', 'callerHistory'], kind: 'non_empty_object', expected: 'caller-history evidence for the tenant-b shared-service invocation'},
    {fields: ['caller_history_recorded', 'callerHistoryRecorded'], kind: 'boolean_true', expected: 'caller history includes the Nexus call'},
  ],
  transient_failure_retries_with_policy: [
    {fields: ['service_call_id', 'serviceCallId'], kind: 'non_empty_string', expected: 'durable service-call id for the retrying call'},
    {fields: ['retry_policy', 'retryPolicy'], kind: 'non_empty_object', expected: 'recorded retry policy applied to the Nexus call'},
    {fields: ['retry_attempts', 'retryAttempts', 'history_attempts', 'historyAttempts', 'service_call_attempts', 'serviceCallAttempts'], kind: 'attempts_at_least', min: 2, expected: 'visible retry attempts for the transient failure'},
    {
      fields: ['history_attempt_visibility_includes_retry_attempts', 'historyAttemptVisibilityIncludesRetryAttempts'],
      kind: 'boolean_true',
      expected: 'history attempt visibility includes every retry attempt',
      invalid_code: 'retry_attempt_visibility_gap',
      finding_type: 'retry_attempt_visibility_gap',
      owning_surface: 'server',
    },
    {fields: ['completed_after_retry', 'completedAfterRetry'], kind: 'boolean_true', expected: 'the caller completed after retrying the transient failure'},
  ],
  permanent_failure_preserves_typed_error: [
    {fields: ['service_call_id', 'serviceCallId'], kind: 'non_empty_string', expected: 'durable service-call id for the failing call'},
    {fields: ['service_error_type', 'serviceErrorType'], kind: 'non_empty_string', expected: 'typed error emitted by the Nexus service'},
    {fields: ['caller_observed_error_type', 'callerObservedErrorType'], kind: 'non_empty_string', expected: 'typed error observed by the caller workflow'},
    {fields: ['typed_error_preserved', 'typedErrorPreserved'], kind: 'boolean_true', expected: 'typed failure shape is preserved across the boundary'},
  ],
  worker_restart_replay_does_not_reissue_call: [
    {fields: ['service_call_id', 'serviceCallId'], kind: 'non_empty_string', expected: 'durable service-call id recovered after replay'},
    {fields: ['issued_call_ids', 'issuedCallIds', 'service_call_ids', 'serviceCallIds', 'call_ids', 'callIds'], kind: 'array_length_at_least', min: 1, expected: 'call ids observed before and after caller worker restart'},
    {fields: ['caller_history_rows', 'callerHistoryRows', 'history_rows', 'historyRows'], kind: 'array_length_at_least', min: 1, expected: 'caller history rows proving the in-flight Nexus call was recovered'},
    {fields: ['service_logs', 'serviceLogs', 'target_service_logs', 'targetServiceLogs'], kind: 'array_length_at_least', min: 1, expected: 'target service logs for the long-running Nexus call'},
    {fields: ['call_issued_at', 'callIssuedAt'], kind: 'non_empty_string', expected: 'timestamp when the long-running Nexus call was first issued'},
    {fields: ['caller_worker_stopped_at', 'callerWorkerStoppedAt', 'worker_stopped_at', 'workerStoppedAt'], kind: 'non_empty_string', expected: 'timestamp when the caller worker was stopped after call issue'},
    {fields: ['caller_worker_restarted_at', 'callerWorkerRestartedAt', 'worker_restarted_at', 'workerRestartedAt'], kind: 'non_empty_string', expected: 'timestamp when the caller worker was restarted'},
    {fields: ['call_completed_at', 'callCompletedAt'], kind: 'non_empty_string', expected: 'timestamp when the recovered Nexus call completed'},
    {fields: ['worker_restart_observed', 'workerRestartObserved'], kind: 'boolean_true', expected: 'caller worker restart was exercised mid-call'},
    {fields: ['history_replay_recovered_call', 'historyReplayRecoveredCall'], kind: 'boolean_true', expected: 'replay recovered the in-flight Nexus call from history'},
    {fields: ['service_invocation_count', 'serviceInvocationCount', 'target_service_invocation_count', 'targetServiceInvocationCount'], kind: 'number_equals', value: 1, expected: 'target service was invoked exactly once across restart replay'},
    {fields: ['duplicate_call_assertion', 'duplicateCallAssertion'], kind: 'non_empty_object', expected: 'explicit duplicate-call assertion evidence for the replay cell'},
    {fields: ['duplicate_call_issue_count', 'duplicateCallIssueCount'], kind: 'number_equals', value: 0, expected: 'replay did not issue a duplicate network call'},
  ],
  caller_cancellation_propagates_to_service: [
    {fields: ['service_call_id', 'serviceCallId'], kind: 'non_empty_string', expected: 'durable service-call id for the cancelled call'},
    {fields: ['caller_history_rows', 'callerHistoryRows', 'history_rows', 'historyRows'], kind: 'array_length_at_least', min: 1, expected: 'caller history rows for the cancelled Nexus call'},
    {fields: ['service_logs', 'serviceLogs', 'target_service_logs', 'targetServiceLogs'], kind: 'array_length_at_least', min: 1, expected: 'target service logs proving cancellation was observed'},
    {fields: ['caller_cancelled_at', 'callerCancelledAt'], kind: 'non_empty_string', expected: 'caller cancellation timestamp'},
    {fields: ['target_cancelled_at', 'targetCancelledAt'], kind: 'non_empty_string', expected: 'target namespace cancellation timestamp'},
    {fields: ['cancellation_propagation_ms', 'cancellationPropagationMs'], kind: 'non_empty_string', expected: 'measured cancellation propagation duration in milliseconds'},
    {fields: ['within_propagation_window', 'withinPropagationWindow'], kind: 'boolean_true', expected: 'typed cancellation was observed within the documented propagation window'},
    {fields: ['cancellation_type', 'cancellationType', 'target_cancellation_type', 'targetCancellationType'], kind: 'non_empty_string', expected: 'typed cancellation class observed by the target service'},
    {fields: ['typed_cancellation_observed', 'typedCancellationObserved'], kind: 'boolean_true', expected: 'target worker observed typed cancellation'},
  ],
  php_caller_python_service: [
    {fields: ['caller_runtime', 'callerRuntime'], kind: 'value_equals', value: 'workflow-php', expected: 'PHP workflow caller runtime'},
    {fields: ['service_runtime', 'serviceRuntime'], kind: 'value_equals', value: 'sdk-python', expected: 'Python service runtime'},
    {fields: ['service_call_id', 'serviceCallId'], kind: 'non_empty_string', expected: 'durable service-call id for the cross-language call'},
    {fields: ['payload_round_trip', 'payloadRoundTrip'], kind: 'boolean_true', expected: 'payload round-tripped between PHP and Python'},
    {fields: ['typed_error_round_trip', 'typedErrorRoundTrip'], kind: 'boolean_true', expected: 'typed error round-tripped between PHP and Python'},
  ],
  python_caller_php_service: [
    {fields: ['caller_runtime', 'callerRuntime'], kind: 'value_equals', value: 'sdk-python', expected: 'Python caller runtime'},
    {fields: ['service_runtime', 'serviceRuntime'], kind: 'value_equals', value: 'workflow-php', expected: 'PHP workflow service runtime'},
    {fields: ['service_call_id', 'serviceCallId'], kind: 'non_empty_string', expected: 'durable service-call id for the cross-language call'},
    {fields: ['payload_round_trip', 'payloadRoundTrip'], kind: 'boolean_true', expected: 'payload round-tripped between Python and PHP'},
    {fields: ['typed_error_round_trip', 'typedErrorRoundTrip'], kind: 'boolean_true', expected: 'typed error round-tripped between Python and PHP'},
  ],
  endpoint_permission_denied_without_information_leak: [
    {fields: ['caller_namespace', 'callerNamespace'], kind: 'non_empty_string', expected: 'unauthorized caller namespace attempted the invocation'},
    {fields: ['refusal_status', 'refusalStatus', 'error_type', 'errorType'], kind: 'non_empty_string', expected: 'typed permission-denied refusal'},
    {
      fields: ['authorization_refusal_disclosed_endpoint_existence', 'authorizationRefusalDisclosedEndpointExistence', 'endpoint_existence_disclosed', 'endpointExistenceDisclosed'],
      kind: 'boolean_false',
      expected: 'permission-denied response does not disclose endpoint existence',
      invalid_code: 'permission_denied_information_leak',
      finding_type: 'permission_denied_information_leak',
      owning_surface: 'server',
    },
    {fields: ['handler_dispatch_count', 'handlerDispatchCount'], kind: 'number_equals', value: 0, expected: 'forbidden call was refused before handler dispatch'},
  ],
  malformed_payload_refused_before_dispatch: [
    {fields: ['refusal_status', 'refusalStatus', 'error_type', 'errorType'], kind: 'non_empty_string', expected: 'typed malformed-payload refusal'},
    {fields: ['typed_error', 'typedError'], kind: 'non_empty_string', expected: 'schema or payload error type returned to the caller'},
    {fields: ['handler_dispatch_count', 'handlerDispatchCount'], kind: 'number_equals', value: 0, expected: 'malformed payload was refused before handler dispatch'},
    {fields: ['service_invoked', 'serviceInvoked'], kind: 'boolean_false', expected: 'malformed payload did not invoke the service'},
  ],
  nonexistent_endpoint_typed_not_found: [
    {fields: ['refusal_status', 'refusalStatus', 'error_type', 'errorType'], kind: 'non_empty_string', expected: 'typed not-found refusal'},
    {fields: ['typed_error', 'typedError'], kind: 'non_empty_string', expected: 'not-found error type returned to the caller'},
    {fields: ['handler_dispatch_count', 'handlerDispatchCount'], kind: 'number_equals', value: 0, expected: 'nonexistent endpoint did not dispatch a handler'},
  ],
  caller_history_attempt_visibility: [
    {fields: ['service_call_id', 'serviceCallId'], kind: 'non_empty_string', expected: 'durable service-call id visible in caller history'},
    {fields: ['caller_history_attempts', 'callerHistoryAttempts'], kind: 'array_length_at_least', min: 2, expected: 'caller history includes per-attempt retry entries'},
    {
      fields: ['history_attempt_visibility_includes_retry_attempts', 'historyAttemptVisibilityIncludesRetryAttempts'],
      kind: 'boolean_true',
      expected: 'caller history exposes retry-attempt visibility',
      invalid_code: 'retry_attempt_visibility_gap',
      finding_type: 'retry_attempt_visibility_gap',
      owning_surface: 'server',
    },
    {fields: ['service_call_detail_attempts', 'serviceCallDetailAttempts', 'service_call_attempts', 'serviceCallAttempts'], kind: 'array_length_at_least', min: 2, expected: 'service-call detail exposes per-attempt retry entries'},
  ],
  result_record_and_product_finding_routing: [
    {fields: ['result_record_emitted', 'resultRecordEmitted'], kind: 'boolean_true', expected: 'Nexus result record was emitted for ledger recording'},
    {fields: ['finding_links_emitted', 'findingLinksEmitted'], kind: 'boolean_true', expected: 'scenario finding links were emitted'},
    {fields: ['waterline_operator_visibility', 'waterlineOperatorVisibility'], kind: 'boolean_true', expected: 'operator-visible Nexus evidence is present for Waterline'},
  ],
};

function timestamp() {
  return new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
}

function envValue(name) {
  const value = process.env[name];
  return typeof value === 'string' && value.trim() !== '' ? value.trim() : null;
}

function artifactVersionsFromEnv() {
  const versions = {};
  const mapping = {
    server: 'DW_SERVER_VERSION',
    cli: 'DW_CLI_VERSION',
    workflow: 'DW_WORKFLOW_PHP_VERSION',
    'sdk-python': 'DW_PYTHON_SDK_VERSION',
    waterline: 'DW_WATERLINE_VERSION',
  };

  for (const [artifact, envName] of Object.entries(mapping)) {
    const value = envValue(envName);
    if (value !== null) {
      versions[artifact] = value;
    }
  }

  return versions;
}

function artifactSourcesFromEnv() {
  const sources = {};
  const mapping = {
    server: 'DW_SERVER_ARTIFACT_SOURCE',
    cli: 'DW_CLI_ARTIFACT_SOURCE',
    workflow: 'DW_WORKFLOW_ARTIFACT_SOURCE',
    'sdk-python': 'DW_PYTHON_SDK_ARTIFACT_SOURCE',
    waterline: 'DW_WATERLINE_ARTIFACT_SOURCE',
  };
  const workflowPhpSource = envValue('DW_WORKFLOW_PHP_ARTIFACT_SOURCE');

  for (const [artifact, envName] of Object.entries(mapping)) {
    const value = envValue(envName);
    if (value !== null) {
      sources[artifact] = value;
    }
  }
  if (!sources.workflow && workflowPhpSource !== null) {
    sources.workflow = workflowPhpSource;
  }

  return sources;
}

function readEvidence(filePath) {
  if (!filePath) {
    return {};
  }

  if (!fs.existsSync(filePath)) {
    return {
      findings: [
        {
          scenario_id: 'published_artifact_install_only',
          type: 'conformance_runner_coverage_gap',
          finding_type: 'conformance_runner_coverage_gap',
          owning_surface: 'conformance_harness',
          artifact_versions: artifactVersionsFromEnv(),
          observed_behavior: `DW_NEXUS_EVIDENCE_JSON did not point at a readable file: ${filePath}`,
          expected_behavior: 'host runner supplies a Nexus evidence JSON document or records focused uncovered cells',
          next_acceptance_criterion: 'rerun Nexus conformance with a readable published-artifact evidence document',
        },
      ],
    };
  }

  return JSON.parse(fs.readFileSync(filePath, 'utf8'));
}

function readDedicatedInstallEvidence(filePath) {
  if (!filePath) {
    return {installEvidence: null, findings: []};
  }

  if (!fs.existsSync(filePath)) {
    return {
      installEvidence: null,
      findings: [
        {
          scenario_id: 'published_artifact_install_only',
          type: 'conformance_runner_coverage_gap',
          finding_type: 'conformance_runner_coverage_gap',
          owning_surface: 'conformance_harness',
          artifact_versions: artifactVersionsFromEnv(),
          observed_behavior: `DW_NEXUS_ARTIFACT_INSTALL_EVIDENCE did not point at a readable file: ${filePath}`,
          expected_behavior: 'host runner supplies readable published artifact install evidence or records focused uncovered cells',
          next_acceptance_criterion: 'rerun Nexus conformance with readable install evidence for every published artifact under test',
        },
      ],
    };
  }

  const parsed = JSON.parse(fs.readFileSync(filePath, 'utf8'));
  return {
    installEvidence: installEvidenceFrom(parsed),
    findings: [],
  };
}

function normalizeScenarioResult(scenarioId, input, artifactVersions, installEvidenceForPromotion = null) {
  if (!input || typeof input !== 'object') {
    return missingScenarioResult(scenarioId, artifactVersions);
  }

  const status = typeof input.status === 'string' && allowedStatuses.has(input.status)
    ? input.status
    : 'not_covered';

  const normalized = {
    scenario_id: scenarioId,
    status,
    observed_outputs: input.observed_outputs && typeof input.observed_outputs === 'object'
      ? input.observed_outputs
      : {},
    linked_findings: Array.isArray(input.linked_findings) ? input.linked_findings : [],
  };

  if (scenarioId === 'published_artifact_install_only') {
    normalized.observed_outputs = withPromotedInstallEvidence(
      normalized.observed_outputs,
      installEvidenceForPromotion,
    );
  }

  if (status === 'pass') {
    const evidenceFailures = scenarioEvidenceFailures(scenarioId, normalized.observed_outputs);
    if (evidenceFailures.length > 0) {
      normalized.status = evidenceFailures.some((failure) => failure.result_status === 'fail')
        ? 'fail'
        : 'not_covered';
      normalized.observed_outputs = {
        ...normalized.observed_outputs,
        result_gate_failed: true,
        scenario_evidence_failures: evidenceFailures,
      };
      normalized.linked_findings = [
        ...normalized.linked_findings,
        ...evidenceFailures.map((failure) => scenarioEvidenceFinding(scenarioId, artifactVersions, failure)),
      ];
    } else if (Object.keys(normalized.observed_outputs).length === 0) {
      normalized.status = 'not_covered';
      normalized.linked_findings = [missingEvidenceFinding(scenarioId, artifactVersions)];
    }
    return normalized;
  }

  if (normalized.linked_findings.length === 0) {
    normalized.linked_findings = [missingEvidenceFinding(scenarioId, artifactVersions)];
  }

  return normalized;
}

function withPromotedInstallEvidence(outputs, installEvidence) {
  const promoted = outputs && typeof outputs === 'object' && !Array.isArray(outputs)
    ? {...outputs}
    : {};
  if (!installEvidence || typeof installEvidence !== 'object' || Array.isArray(installEvidence)) {
    return promoted;
  }
  if (installEvidenceFrom(promoted) === null) {
    promoted.artifact_install_evidence = installEvidence;
  }
  if (!Object.hasOwn(promoted, 'local_product_source_checkouts_used')
    && !Object.hasOwn(promoted, 'localProductSourceCheckoutsUsed')
    && hasExplicitFalseLocalProductSourceFlag(installEvidence)) {
    promoted.local_product_source_checkouts_used = false;
  }
  if (!Object.hasOwn(promoted, 'install_channels_verified')
    && !Object.hasOwn(promoted, 'installChannelsVerified')
    && installEvidenceArtifactsAllPass(installEvidence)) {
    promoted.install_channels_verified = true;
  }
  if (!Object.hasOwn(promoted, 'published_install_tuple_proven')
    && !Object.hasOwn(promoted, 'publishedInstallTupleProven')
    && truthy(installEvidence.published_install_tuple_proven ?? installEvidence.publishedInstallTupleProven)) {
    promoted.published_install_tuple_proven = true;
  }

  return promoted;
}

function scenarioEvidenceFailures(scenarioId, observedOutputs) {
  const requirements = scenarioEvidenceRequirements[scenarioId] || [];
  const failures = [];

  for (const requirement of requirements) {
    const lookup = evidenceLookup(observedOutputs, requirement.fields);
    const missing = !lookup.present || isMissingEvidenceValue(lookup.value, requirement.kind);
    if (missing) {
      failures.push({
        code: 'missing_scenario_specific_evidence',
        field: requirement.fields[0],
        expected: requirement.expected,
        result_status: 'not_covered',
        finding_type: 'conformance_runner_coverage_gap',
        owning_surface: 'conformance_harness',
      });
      continue;
    }

    if (! evidenceRequirementSatisfied(requirement, lookup.value)) {
      failures.push({
        code: requirement.invalid_code || 'invalid_scenario_specific_evidence',
        field: requirement.fields[0],
        expected: requirement.expected,
        observed: lookup.value,
        result_status: requirement.invalid_result_status || 'fail',
        finding_type: requirement.finding_type || 'nexus_scenario_evidence_mismatch',
        owning_surface: requirement.owning_surface || 'conformance_harness',
      });
    }
  }

  return failures;
}

function evidenceLookup(outputs, fields) {
  const container = outputs && typeof outputs === 'object' && !Array.isArray(outputs) ? outputs : {};
  for (const field of fields) {
    if (Object.hasOwn(container, field)) {
      return {present: true, value: container[field]};
    }
  }

  return {present: false, value: undefined};
}

function isMissingEvidenceValue(value, kind) {
  if (value === null || value === undefined) {
    return true;
  }
  if (kind === 'non_empty_object') {
    return !value || typeof value !== 'object' || Array.isArray(value) || Object.keys(value).length === 0;
  }
  if (kind === 'array_length_at_least') {
    return !Array.isArray(value) || value.length === 0;
  }
  if (kind === 'attempts_at_least') {
    if (Array.isArray(value)) {
      return value.length === 0;
    }

    return numberValue(value) === null;
  }

  return stringValue(value) === '';
}

function evidenceRequirementSatisfied(requirement, value) {
  switch (requirement.kind) {
    case 'non_empty_string':
      return stringValue(value) !== '';
    case 'non_empty_object':
      return value && typeof value === 'object' && !Array.isArray(value) && Object.keys(value).length > 0;
    case 'boolean_true':
      return truthy(value);
    case 'boolean_false':
      return explicitFalse(value);
    case 'value_equals':
      return stringValue(value) === stringValue(requirement.value);
    case 'number_equals':
      return numberValue(value) === Number(requirement.value);
    case 'array_length_at_least':
      return Array.isArray(value) && value.length >= Number(requirement.min);
    case 'attempts_at_least':
      if (Array.isArray(value)) {
        return value.length >= Number(requirement.min);
      }

      return (numberValue(value) ?? 0) >= Number(requirement.min);
    default:
      return false;
  }
}

function scenarioEvidenceFinding(scenarioId, artifactVersions, failure) {
  const expected = stringValue(failure.expected) || 'Nexus scenario-specific evidence satisfies the contract result gate.';
  const observed = Object.hasOwn(failure, 'observed')
    ? ` Observed ${failure.field}=${JSON.stringify(failure.observed)}.`
    : '';

  return {
    scenario_id: scenarioId,
    type: failure.finding_type,
    finding_type: failure.finding_type,
    owning_surface: failure.owning_surface,
    artifact_versions: artifactVersions,
    observed_behavior: `${failure.code} for ${scenarioId}: ${failure.field}.${observed}`,
    expected_behavior: expected,
    next_acceptance_criterion: `rerun the ${scenarioId} Nexus cell with ${failure.field} evidence satisfying the published result gate`,
  };
}

function missingEvidenceFinding(scenarioId, artifactVersions) {
  return {
    scenario_id: scenarioId,
    type: 'conformance_runner_coverage_gap',
    finding_type: 'conformance_runner_coverage_gap',
    owning_surface: 'conformance_harness',
    artifact_versions: artifactVersions,
    observed_behavior: `Nexus conformance did not attach scenario-specific evidence for ${scenarioId}.`,
    expected_behavior: 'Nexus conformance records published-artifact evidence or a focused product finding for this scenario.',
    next_acceptance_criterion: `rerun Nexus conformance with concrete evidence for ${scenarioId}`,
  };
}

function missingScenarioResult(scenarioId, artifactVersions) {
  return {
    scenario_id: scenarioId,
    status: 'not_covered',
    observed_outputs: {
      covered: false,
    },
    linked_findings: [missingEvidenceFinding(scenarioId, artifactVersions)],
  };
}

function runnerBlockedReasonFrom(evidence) {
  return stringValue(evidence.blocked_reason)
    || stringValue(evidence.blockedReason)
    || stringValue(evidence.runner_blocked_reason)
    || stringValue(evidence.runnerBlockedReason)
    || 'Nexus host runner reported runner_blocked=true.';
}

function runnerBlockedIn(evidence) {
  return truthy(evidence.runner_blocked)
    || truthy(evidence.runnerBlocked)
    || stringValue(evidence.blocked_reason) !== ''
    || stringValue(evidence.blockedReason) !== ''
    || stringValue(evidence.runner_blocked_reason) !== ''
    || stringValue(evidence.runnerBlockedReason) !== '';
}

function runnerBlockedFinding(scenarioId, artifactVersions, reason) {
  return {
    scenario_id: scenarioId,
    type: 'runner_gap',
    finding_type: 'runner_gap',
    owning_surface: 'conformance_harness',
    artifact_versions: artifactVersions,
    observed_behavior: reason,
    expected_behavior: 'Nexus conformance host execution reaches published artifact behavior before recording product evidence.',
    next_acceptance_criterion: `restore the Nexus host execution path and rerun ${scenarioId} with runner_blocked=false evidence`,
  };
}

function runnerBlockedScenarioResult(scenarioId, artifactVersions, reason) {
  const finding = runnerBlockedFinding(scenarioId, artifactVersions, reason);

  return {
    scenario_id: scenarioId,
    status: 'runner_blocked',
    observed_outputs: {
      blocked_reason: reason,
      evidence_runner_blocked: true,
    },
    linked_findings: [finding],
  };
}

function byScenarioId(items) {
  const indexed = new Map();
  if (Array.isArray(items)) {
    for (const item of items) {
      if (item && typeof item.scenario_id === 'string') {
        indexed.set(item.scenario_id, item);
      }
    }
    return indexed;
  }

  if (items && typeof items === 'object') {
    for (const [scenarioId, item] of Object.entries(items)) {
      if (item && typeof item === 'object') {
        indexed.set(scenarioId, {
          scenario_id: scenarioId,
          ...item,
        });
      }
    }
  }

  return indexed;
}

function mergeMaps(...maps) {
  const merged = {};
  for (const map of maps) {
    if (!map || typeof map !== 'object' || Array.isArray(map)) {
      continue;
    }

    for (const [key, value] of Object.entries(map)) {
      if (stringValue(value) !== '' || !Object.hasOwn(merged, key)) {
        merged[key] = value;
      }
    }
  }

  return merged;
}

function normalizeArtifactMap(map) {
  const normalized = {...(map && typeof map === 'object' && !Array.isArray(map) ? map : {})};

  for (const artifact of requiredArtifacts) {
    if (stringValue(normalized[artifact]) !== '') {
      continue;
    }

    for (const alias of artifactAliases[artifact] || []) {
      if (stringValue(normalized[alias]) !== '') {
        normalized[artifact] = normalized[alias];
        break;
      }
    }
  }

  return normalized;
}

function normalizeArtifactEvidenceMap(map) {
  const normalized = {...(map && typeof map === 'object' && !Array.isArray(map) ? map : {})};

  for (const artifact of requiredArtifacts) {
    if (hasNonEmptyObjectValue(normalized[artifact])) {
      continue;
    }

    for (const alias of artifactAliases[artifact] || []) {
      if (hasNonEmptyObjectValue(normalized[alias])) {
        normalized[artifact] = normalized[alias];
        break;
      }
    }
  }

  return normalized;
}

function hasNonEmptyObjectValue(value) {
  return value && typeof value === 'object' && !Array.isArray(value) && Object.keys(value).length > 0;
}

function artifactPolicyFailuresFor(
  artifactVersions,
  artifactSources,
  artifactSourceVerification,
  localProductSourceCheckoutsUsed,
  localProductSourceCheckoutsExplicitlyFalse,
) {
  const failures = artifactMapPolicyFailuresFor(artifactVersions, artifactSources, artifactSourceVerification);

  if (localProductSourceCheckoutsUsed) {
    failures.push({
      artifact: 'product-artifacts',
      field: 'local_product_source_checkouts_used',
      code: 'local_product_source_checkout_used',
      value: true,
    });
  } else if (!localProductSourceCheckoutsExplicitlyFalse) {
    failures.push({
      artifact: 'product-artifacts',
      field: 'local_product_source_checkouts_used',
      code: 'missing_explicit_source_free_evidence',
    });
  }

  return failures;
}

function artifactMapPolicyFailuresFor(artifactVersions, artifactSources, artifactSourceVerification = {}, paths = {}) {
  const failures = [];
  const versionPath = stringValue(paths.artifactVersionsPath);
  const sourcePath = stringValue(paths.artifactSourcesPath);
  const verificationPath = stringValue(paths.artifactSourceVerificationPath);

  for (const artifact of requiredArtifacts) {
    const version = stringValue(artifactVersions[artifact]);
    const source = stringValue(artifactSources[artifact]);
    let versionPassesPolicy = false;
    let sourcePassesPolicy = false;

    if (version === '') {
      failures.push({
        artifact,
        field: 'artifact_versions',
        code: 'missing_published_artifact_version',
        ...(versionPath !== '' ? {path: versionPath} : {}),
      });
    } else if (isPlaceholderArtifactVersion(version)) {
      failures.push({
        artifact,
        field: 'artifact_versions',
        code: 'placeholder_published_artifact_version',
        value: version,
        ...(versionPath !== '' ? {path: versionPath} : {}),
      });
    } else if (!isExactPublishedArtifactVersion(version)) {
      failures.push({
        artifact,
        field: 'artifact_versions',
        code: 'invalid_published_artifact_version',
        value: version,
        ...(versionPath !== '' ? {path: versionPath} : {}),
      });
    } else {
      versionPassesPolicy = true;
    }

    if (source === '') {
      failures.push({
        artifact,
        field: 'artifact_sources',
        code: 'missing_published_artifact_source',
        ...(sourcePath !== '' ? {path: sourcePath} : {}),
      });
    } else if (containsForbiddenSourceToken(source)) {
      failures.push({
        artifact,
        field: 'artifact_sources',
        code: 'forbidden_published_artifact_source',
        value: source,
        ...(sourcePath !== '' ? {path: sourcePath} : {}),
      });
    } else if (!matchesPublishedArtifactSource(artifact, version, source)) {
      failures.push({
        artifact,
        field: 'artifact_sources',
        code: 'invalid_published_artifact_source',
        value: source,
        ...(sourcePath !== '' ? {path: sourcePath} : {}),
      });
    } else {
      sourcePassesPolicy = true;
    }

    if (versionPassesPolicy && sourcePassesPolicy) {
      const verificationFailure = artifactSourceVerificationFailureFor(
        artifact,
        version,
        source,
        artifactSourceVerification[artifact],
        verificationPath,
      );
      if (verificationFailure !== null) {
        failures.push(verificationFailure);
      }
    }
  }

  return failures;
}

function artifactSourceVerificationFailureFor(artifact, version, source, verification, verificationPath) {
  const base = {
    artifact,
    field: 'artifact_source_verification',
    ...(verificationPath !== '' ? {path: verificationPath} : {}),
  };

  if (!hasNonEmptyObjectValue(verification)) {
    return {
      ...base,
      code: 'missing_published_artifact_resolution_evidence',
    };
  }

  const sourceLookup = evidenceLookup(verification, [
    'source',
    'artifact_source',
    'artifactSource',
    'resolved_source',
    'resolvedSource',
  ]);
  const verifiedSource = stringValue(sourceLookup.value);
  if (verifiedSource === '') {
    return {
      ...base,
      code: 'missing_published_artifact_resolution_source',
    };
  }
  if (verifiedSource !== source) {
    return {
      ...base,
      code: 'published_artifact_resolution_source_mismatch',
      value: verifiedSource,
      expected_value: source,
    };
  }

  const versionLookup = evidenceLookup(verification, [
    'version',
    'artifact_version',
    'artifactVersion',
    'resolved_version',
    'resolvedVersion',
  ]);
  const verifiedVersion = stringValue(versionLookup.value);
  if (verifiedVersion === '') {
    return {
      ...base,
      code: 'missing_published_artifact_resolution_version',
    };
  }
  if (verifiedVersion !== version) {
    return {
      ...base,
      code: 'published_artifact_resolution_version_mismatch',
      value: verifiedVersion,
      expected_value: version,
    };
  }

  if (!verificationConfirmsDownloadable(verification)) {
    return {
      ...base,
      code: 'unverified_downloadable_published_artifact_source',
      value: stringValue(verification.status),
    };
  }

  return null;
}

function verificationConfirmsDownloadable(verification) {
  for (const field of [
    'downloadable',
    'downloaded',
    'installable',
    'resolved',
    'exists',
    'published',
    'verified',
    'asset_exists',
    'assetExists',
    'package_exists',
    'packageExists',
    'manifest_resolved',
    'manifestResolved',
    'source_exists',
    'sourceExists',
  ]) {
    const lookup = evidenceLookup(verification, [field]);
    if (lookup.present && truthy(lookup.value)) {
      return true;
    }
  }

  return [
    'pass',
    'passed',
    'success',
    'successful',
    'resolved',
    'downloadable',
    'exists',
    'found',
    'verified',
    'installable',
    'asset_resolved',
    'package_resolved',
    'manifest_resolved',
  ].includes(stringValue(verification.status).toLowerCase());
}

function installScenarioArtifactPolicyFailuresFor(
  scenarioResults,
  expectedArtifactVersions,
  expectedArtifactSources,
) {
  const installScenario = scenarioResults.find((scenario) => (
    scenario.scenario_id === 'published_artifact_install_only'
  ));
  if (!installScenario || installScenario.status !== 'pass') {
    return [];
  }

  const observedOutputs = installScenario
    && installScenario.observed_outputs
    && typeof installScenario.observed_outputs === 'object'
    && !Array.isArray(installScenario.observed_outputs)
    ? installScenario.observed_outputs
    : {};
  const artifactVersions = normalizeArtifactMap(mergeMaps(
    observedOutputs.artifact_versions,
    observedOutputs.artifactVersions,
    observedOutputs.published_artifact_versions,
    observedOutputs.publishedArtifactVersions,
    observedOutputs.resolved_artifact_versions,
    observedOutputs.resolvedArtifactVersions,
  ));
  const artifactSources = normalizeArtifactMap(mergeMaps(
    observedOutputs.artifact_sources,
    observedOutputs.artifactSources,
    observedOutputs.install_sources,
    observedOutputs.installSources,
  ));
  const artifactSourceVerification = normalizeArtifactEvidenceMap(mergeMaps(
    observedOutputs.artifact_source_verification,
    observedOutputs.artifactSourceVerification,
    observedOutputs.published_artifact_source_verification,
    observedOutputs.publishedArtifactSourceVerification,
    observedOutputs.artifact_source_resolution,
    observedOutputs.artifactSourceResolution,
  ));
  const artifactInstallEvidence = installEvidenceFrom(observedOutputs);

  return [
    ...artifactMapPolicyFailuresFor(artifactVersions, artifactSources, artifactSourceVerification, {
      artifactVersionsPath: '$.scenario_results.published_artifact_install_only.observed_outputs.artifact_versions',
      artifactSourcesPath: '$.scenario_results.published_artifact_install_only.observed_outputs.artifact_sources',
      artifactSourceVerificationPath: '$.scenario_results.published_artifact_install_only.observed_outputs.artifact_source_verification',
    }),
    ...artifactInstallEvidencePolicyFailuresFor(
      artifactInstallEvidence,
      expectedArtifactVersions,
      expectedArtifactSources,
      '$.scenario_results.published_artifact_install_only.observed_outputs.artifact_install_evidence',
    ),
    ...artifactTupleMismatchFailuresFor(
      artifactVersions,
      artifactSources,
      artifactSourceVerification,
      expectedArtifactVersions,
      expectedArtifactSources,
    ),
  ];
}

function artifactTupleMismatchFailuresFor(
  observedArtifactVersions,
  observedArtifactSources,
  observedArtifactSourceVerification,
  expectedArtifactVersions,
  expectedArtifactSources,
) {
  const failures = [];
  const versionPath = '$.scenario_results.published_artifact_install_only.observed_outputs.artifact_versions';
  const sourcePath = '$.scenario_results.published_artifact_install_only.observed_outputs.artifact_sources';
  const verificationPath = '$.scenario_results.published_artifact_install_only.observed_outputs.artifact_source_verification';

  for (const artifact of requiredArtifacts) {
    const observedVersion = stringValue(observedArtifactVersions[artifact]);
    const expectedVersion = stringValue(expectedArtifactVersions[artifact]);
    if (observedVersion !== '' && expectedVersion !== '' && observedVersion !== expectedVersion) {
      failures.push({
        artifact,
        field: 'artifact_versions',
        code: 'install_artifact_version_mismatch',
        value: observedVersion,
        expected_value: expectedVersion,
        path: versionPath,
      });
    }

    const observedSource = stringValue(observedArtifactSources[artifact]);
    const expectedSource = stringValue(expectedArtifactSources[artifact]);
    if (observedSource !== '' && expectedSource !== '' && observedSource !== expectedSource) {
      failures.push({
        artifact,
        field: 'artifact_sources',
        code: 'install_artifact_source_mismatch',
        value: observedSource,
        expected_value: expectedSource,
        path: sourcePath,
      });
    }

    const observedVerification = observedArtifactSourceVerification[artifact];
    if (!hasNonEmptyObjectValue(observedVerification)) {
      continue;
    }

    const verifiedVersion = stringValue(evidenceLookup(observedVerification, [
      'version',
      'artifact_version',
      'artifactVersion',
      'resolved_version',
      'resolvedVersion',
    ]).value);
    if (verifiedVersion !== '' && expectedVersion !== '' && verifiedVersion !== expectedVersion) {
      failures.push({
        artifact,
        field: 'artifact_source_verification',
        code: 'install_artifact_source_verification_version_mismatch',
        value: verifiedVersion,
        expected_value: expectedVersion,
        path: verificationPath,
      });
    }

    const verifiedSource = stringValue(evidenceLookup(observedVerification, [
      'source',
      'artifact_source',
      'artifactSource',
      'resolved_source',
      'resolvedSource',
    ]).value);
    if (verifiedSource !== '' && expectedSource !== '' && verifiedSource !== expectedSource) {
      failures.push({
        artifact,
        field: 'artifact_source_verification',
        code: 'install_artifact_source_verification_source_mismatch',
        value: verifiedSource,
        expected_value: expectedSource,
        path: verificationPath,
      });
    }
  }

  return failures;
}

function applyResultGateFailures(
  scenario,
  artifactVersions,
  artifactPolicyFailures,
  localProductSourceCheckoutsUsed,
  localProductSourceCheckoutsExplicitlyFalse,
) {
  if (scenario.status !== 'pass') {
    return scenario;
  }

  const linkedFindings = Array.isArray(scenario.linked_findings) ? [...scenario.linked_findings] : [];
  const resultGateFindings = [];

  for (const failure of artifactPolicyFailures) {
    if (failure.field === 'local_product_source_checkouts_used') {
      continue;
    }
    resultGateFindings.push(artifactPolicyFinding(scenario.scenario_id, artifactVersions, failure));
  }

  if (localProductSourceCheckoutsUsed) {
    resultGateFindings.push(localProductSourceFinding(scenario.scenario_id, artifactVersions));
  }
  if (!localProductSourceCheckoutsUsed && !localProductSourceCheckoutsExplicitlyFalse) {
    resultGateFindings.push(missingSourceFreeEvidenceFinding(scenario.scenario_id, artifactVersions));
  }

  if (resultGateFindings.length === 0) {
    return scenario;
  }

  return {
    ...scenario,
    status: 'fail',
    observed_outputs: {
      ...(scenario.observed_outputs && typeof scenario.observed_outputs === 'object'
        ? scenario.observed_outputs
        : {}),
      result_gate_failed: true,
      artifact_policy_failures: artifactPolicyFailures,
      local_product_source_checkouts_used: localProductSourceCheckoutsUsed,
    },
    linked_findings: [
      ...linkedFindings,
      ...resultGateFindings,
    ],
  };
}

function withSyntheticInstallScenarioEvidence(
  scenarioResults,
  artifactVersions,
  artifactSources,
  artifactSourceVerification,
  localProductSourceCheckoutsUsed,
  artifactInstallEvidence,
  canSynthesize,
) {
  if (!canSynthesize) {
    return scenarioResults;
  }

  return scenarioResults.map((scenario) => {
    if (scenario.scenario_id !== 'published_artifact_install_only'
      || hasInstallScenarioArtifactEvidence(scenario)
      || ['fail', 'unsupported', 'runner_blocked'].includes(scenario.status)) {
      return scenario;
    }

    return {
      scenario_id: 'published_artifact_install_only',
      status: 'pass',
      observed_outputs: syntheticInstallObservedOutputs(
        artifactVersions,
        artifactSources,
        artifactSourceVerification,
        localProductSourceCheckoutsUsed,
        artifactInstallEvidence,
      ),
      linked_findings: [],
    };
  });
}

function hasInstallScenarioArtifactEvidence(scenario) {
  const outputs = scenario
    && scenario.observed_outputs
    && typeof scenario.observed_outputs === 'object'
    && !Array.isArray(scenario.observed_outputs)
    ? scenario.observed_outputs
    : {};

  return [
    'artifact_versions',
    'artifactVersions',
    'artifact_sources',
    'artifactSources',
    'artifact_source_verification',
    'artifactSourceVerification',
    'local_product_source_checkouts_used',
    'localProductSourceCheckoutsUsed',
    'install_channels_verified',
    'installChannelsVerified',
    'artifact_install_evidence',
    'artifactInstallEvidence',
  ].some((field) => Object.hasOwn(outputs, field));
}

function syntheticInstallObservedOutputs(
  artifactVersions,
  artifactSources,
  artifactSourceVerification,
  localProductSourceCheckoutsUsed,
  artifactInstallEvidence,
) {
  return {
    artifact_versions: artifactVersions,
    resolved_artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
    artifact_source_verification: artifactSourceVerification,
    local_product_source_checkouts_used: localProductSourceCheckoutsUsed,
    install_channels_verified: true,
    published_install_tuple_proven: true,
    artifact_install_evidence: artifactInstallEvidence,
  };
}

function withResultRecordAndRoutingScenario(scenarioResults, artifactVersions) {
  const scenarios = new Map(scenarioResults.map((scenario) => [scenario.scenario_id, scenario]));
  const nonRoutingScenarios = requiredScenarios
    .filter((scenarioId) => scenarioId !== resultRoutingScenarioId)
    .map((scenarioId) => scenarios.get(scenarioId) || missingScenarioResult(scenarioId, artifactVersions));
  const routingScenario = resultRecordAndRoutingScenarioResult(nonRoutingScenarios, artifactVersions);

  return requiredScenarios.map((scenarioId) => (
    scenarioId === resultRoutingScenarioId
      ? routingScenario
      : (scenarios.get(scenarioId) || missingScenarioResult(scenarioId, artifactVersions))
  ));
}

function resultRecordAndRoutingScenarioResult(nonRoutingScenarios, artifactVersions) {
  const scenarioStatuses = {};
  const statusCounts = {};
  const nonPassRoutes = {};
  const unroutedNonPassScenarios = [];

  for (const scenario of nonRoutingScenarios) {
    const scenarioId = scenario.scenario_id;
    const status = allowedStatuses.has(scenario.status) ? scenario.status : 'not_covered';
    scenarioStatuses[scenarioId] = status;
    statusCounts[status] = (statusCounts[status] || 0) + 1;

    if (!routedNonPassStatuses.has(status)) {
      continue;
    }

    const linkedFindings = Array.isArray(scenario.linked_findings) ? scenario.linked_findings : [];
    const focusedFindings = linkedFindings.filter((finding) => isFocusedScenarioFinding(scenarioId, finding));
    const routed = focusedFindings.length > 0;
    nonPassRoutes[scenarioId] = {
      status,
      routed,
      finding_count: linkedFindings.length,
      focused_finding_count: focusedFindings.length,
      finding_types: linkedFindings.map((finding) => stringValue(finding.finding_type) || stringValue(finding.type)),
      owning_surfaces: linkedFindings.map((finding) => stringValue(finding.owning_surface)),
    };

    if (!routed) {
      unroutedNonPassScenarios.push(scenarioId);
    }
  }

  const status = unroutedNonPassScenarios.length === 0 ? 'pass' : 'fail';
  scenarioStatuses[resultRoutingScenarioId] = status;
  statusCounts[status] = (statusCounts[status] || 0) + 1;
  const observedOutputs = {
    result_record_emitted: true,
    finding_links_emitted: true,
    waterline_operator_visibility: true,
    required_scenarios_recorded: requiredScenarios,
    required_statuses: [...allowedStatuses],
    non_pass_statuses: [...routedNonPassStatuses],
    scenario_statuses: scenarioStatuses,
    status_counts: statusCounts,
    non_pass_routes: nonPassRoutes,
    non_pass_findings_routed: unroutedNonPassScenarios.length === 0,
    unrouted_non_pass_scenarios: unroutedNonPassScenarios,
  };
  const linkedFindings = status === 'pass'
    ? []
    : [resultRecordRoutingFinding(artifactVersions, unroutedNonPassScenarios)];

  return {
    scenario_id: resultRoutingScenarioId,
    status,
    observed_outputs: observedOutputs,
    linked_findings: linkedFindings,
  };
}

function isFocusedScenarioFinding(scenarioId, finding) {
  if (!finding || typeof finding !== 'object' || Array.isArray(finding)) {
    return false;
  }

  return stringValue(finding.scenario_id) === scenarioId
    && (stringValue(finding.finding_type) !== '' || stringValue(finding.type) !== '')
    && stringValue(finding.owning_surface) !== ''
    && stringValue(finding.observed_behavior) !== ''
    && stringValue(finding.expected_behavior) !== ''
    && stringValue(finding.next_acceptance_criterion) !== '';
}

function resultRecordRoutingFinding(artifactVersions, unroutedNonPassScenarios) {
  return {
    scenario_id: resultRoutingScenarioId,
    type: 'nexus_result_record_routing_gap',
    finding_type: 'nexus_result_record_routing_gap',
    owning_surface: 'conformance_harness',
    artifact_versions: artifactVersions,
    observed_behavior: `Nexus result record left non-pass scenario(s) without focused findings: ${unroutedNonPassScenarios.join(', ')}.`,
    expected_behavior: 'Every fail, unsupported, not_covered, or runner_blocked Nexus scenario is routed to a focused finding with scenario id, owner, observed behavior, expected behavior, and next acceptance criterion.',
    next_acceptance_criterion: 'rerun Nexus conformance with focused linked findings for every non-pass scenario cell',
  };
}

function artifactPolicyFinding(scenarioId, artifactVersions, failure) {
  const artifact = stringValue(failure.artifact);
  const field = stringValue(failure.field);
  const code = stringValue(failure.code);
  const value = stringValue(failure.value);
  const valueDetail = value === '' ? '' : `; observed ${field}=${value}`;
  const path = stringValue(failure.path);
  const pathDetail = path === '' ? '' : ` at ${path}`;
  const nextCriterion = field.startsWith('artifact_install_evidence')
    ? `record passing published install evidence for ${artifact}, then rerun the ${scenarioId} Nexus cell`
    : (field === 'artifact_sources'
    ? `record a published install source for ${artifact}, then rerun the ${scenarioId} Nexus cell`
    : (field === 'artifact_source_verification'
      ? `record host proof that ${artifact} source resolves to a downloadable published artifact, then rerun the ${scenarioId} Nexus cell`
      : `publish or record a concrete ${artifact} artifact version, then rerun the ${scenarioId} Nexus cell`));

  return {
    scenario_id: scenarioId,
    type: 'missing_or_invalid_published_nexus_artifact',
    finding_type: 'missing_or_invalid_published_nexus_artifact',
    owning_surface: artifactOwners[artifact] || 'conformance_harness',
    artifact,
    artifact_versions: artifactVersions,
    observed_behavior: `Required Nexus artifact ${artifact} has ${code} in ${field}${pathDetail}${valueDetail}.`,
    expected_behavior: 'Nexus conformance starts from exact pinned published artifact versions and published install sources, without rolling tags, placeholder versions, non-version artifact references, or local source checkout paths.',
    next_acceptance_criterion: nextCriterion,
  };
}

function localProductSourceFinding(scenarioId, artifactVersions) {
  return {
    scenario_id: scenarioId,
    type: 'local_product_source_checkout_used',
    finding_type: 'local_product_source_checkout_used',
    owning_surface: 'conformance_harness',
    artifact_versions: artifactVersions,
    observed_behavior: 'Nexus evidence reported local_product_source_checkouts_used=true.',
    expected_behavior: 'Nexus conformance uses only pinned published artifacts as the product under test.',
    next_acceptance_criterion: `rerun the ${scenarioId} Nexus cell without local product source checkouts`,
  };
}

function missingSourceFreeEvidenceFinding(scenarioId, artifactVersions) {
  return {
    scenario_id: scenarioId,
    type: 'missing_explicit_source_free_published_artifact_evidence',
    finding_type: 'missing_explicit_source_free_published_artifact_evidence',
    owning_surface: 'conformance_harness',
    artifact_versions: artifactVersions,
    observed_behavior: 'Nexus evidence omitted local_product_source_checkouts_used=false.',
    expected_behavior: 'Nexus conformance evidence explicitly states that no local product source checkout was used as an artifact under test.',
    next_acceptance_criterion: `rerun the ${scenarioId} Nexus cell with local_product_source_checkouts_used=false in the host evidence`,
  };
}

function isPlaceholderArtifactVersion(version) {
  const normalized = version.toLowerCase();

  return placeholderVersionTokens.some((token) => normalized.includes(token.toLowerCase()))
    || /(^|[^a-z0-9])v?\d+(?:\.\d+)*\.x([^a-z0-9]|$)/i.test(normalized);
}

function isExactPublishedArtifactVersion(version) {
  return /^[0-9]+\.[0-9]+\.[0-9]+(?:[.-][0-9A-Za-z.-]+)?$/.test(version.trim());
}

function containsForbiddenSourceToken(source) {
  const normalized = source.toLowerCase().trim();
  const decoded = decodeSourceText(normalized);

  return [normalized, decoded].some((candidate) => (
    forbiddenSourceTokens.some((token) => candidate.includes(token.toLowerCase()))
      || isRollingArtifactSourceRef(candidate)
      || isLocalArtifactSourcePath(candidate)
  ));
}

function matchesPublishedArtifactSource(artifact, version, source) {
  if (version === '') {
    return false;
  }

  const trimmed = source.trim();

  switch (artifact) {
    case 'server':
      return matchesServerArtifactSource(version, trimmed);
    case 'cli':
      return matchesCliArtifactSource(version, trimmed);
    case 'workflow':
      return matchesComposerArtifactSource('durable-workflow/workflow', version, trimmed);
    case 'sdk-python':
      return matchesPythonArtifactSource(version, trimmed);
    case 'waterline':
      return matchesComposerArtifactSource('durable-workflow/waterline', version, trimmed);
    default:
      return false;
  }
}

function matchesServerArtifactSource(version, source) {
  if (/^docker:\/\/durableworkflow\/server@sha256:[0-9a-f]{64}$/i.test(source)
    || /^durableworkflow\/server@sha256:[0-9a-f]{64}$/i.test(source)
    || new RegExp(`^docker://durableworkflow/server:${escapeRegExp(version)}@sha256:[0-9a-f]{64}$`, 'i').test(source)
    || new RegExp(`^durableworkflow/server:${escapeRegExp(version)}@sha256:[0-9a-f]{64}$`, 'i').test(source)) {
    return true;
  }

  return source === `docker://durableworkflow/server:${version}`
    || source === `durableworkflow/server:${version}`;
}

function escapeRegExp(value) {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function matchesCliArtifactSource(version, source) {
  const prefix = `https://github.com/durable-workflow/cli/releases/download/${version}/`;
  if (!source.startsWith(prefix)) {
    return false;
  }

  return cliReleaseAssetNames.has(source.slice(prefix.length));
}

function matchesComposerArtifactSource(packageName, version, source) {
  return source === `packagist://${packageName}@${version}`
    || source === `composer://${packageName}:${version}`
    || source === `https://repo.packagist.org/p2/${packageName}.json#${version}`;
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

function isRollingArtifactSourceRef(source) {
  return rollingSourceRefPattern.test(source);
}

function decodeSourceText(source) {
  try {
    return decodeURIComponent(source);
  } catch {
    return source;
  }
}

function isLocalArtifactSourcePath(source) {
  const pathText = source.replace(/\\/g, '/').trim();

  return pathText.startsWith('file:')
    || /^local(?::|\/|$)/.test(pathText)
    || /^~(?:[^/]*)?(?:\/|$)/.test(pathText)
    || /^\$(?:home|userprofile)(?:\/|$)/.test(pathText)
    || /^\$\{(?:home|userprofile)\}(?:\/|$)/.test(pathText)
    || /^%(?:home|userprofile|homedrive|homepath)%/.test(pathText)
    || /^\/[^/]+/.test(pathText)
    || /^[a-z]:\//.test(pathText)
    || /^\.\.?(?:\/|$)/.test(pathText)
    || /(^|[^a-z0-9])\/?workspace\/repos\//.test(pathText)
    || /^repos\/(?:server|workflow|waterline|cli|cloud|sample-app|sdk-python|durable-workflow\.github\.io)(?:\/|$)/.test(pathText);
}

function localProductSourceCheckoutsUsedIn(...containers) {
  return localProductSourceFlagValues(...containers).some((value) => truthy(value));
}

function localProductSourceCheckoutsExplicitlyFalseIn(...containers) {
  return localProductSourceCheckoutsUsedFlagValues(...containers).some((value) => explicitFalse(value));
}

function localProductSourceFlagValues(...containers) {
  const values = [];
  for (const container of containers) {
    collectLocalProductSourceFlagValues(container, values);
  }
  return values;
}

function collectLocalProductSourceFlagValues(value, values) {
  if (!value || typeof value !== 'object') {
    return;
  }

  if (Array.isArray(value)) {
    for (const entry of value) {
      collectLocalProductSourceFlagValues(entry, values);
    }
    return;
  }

  if (Object.hasOwn(value, 'local_product_source_checkouts_used')) {
    values.push(value.local_product_source_checkouts_used);
  }
  if (Object.hasOwn(value, 'localProductSourceCheckoutsUsed')) {
    values.push(value.localProductSourceCheckoutsUsed);
  }
  if (Object.hasOwn(value, 'local_product_source_checkout_used_as_artifact')) {
    values.push(value.local_product_source_checkout_used_as_artifact);
  }
  if (Object.hasOwn(value, 'localProductSourceCheckoutUsedAsArtifact')) {
    values.push(value.localProductSourceCheckoutUsedAsArtifact);
  }

  for (const entry of Object.values(value)) {
    if (entry && typeof entry === 'object') {
      collectLocalProductSourceFlagValues(entry, values);
    }
  }
}

function hasExplicitFalseLocalProductSourceFlag(container) {
  if (!container || typeof container !== 'object' || Array.isArray(container)) {
    return false;
  }

  return explicitFalse(container.local_product_source_checkouts_used)
    || explicitFalse(container.localProductSourceCheckoutsUsed);
}

function localProductSourceCheckoutsUsedFlagValues(...containers) {
  const values = [];
  for (const container of containers) {
    collectLocalProductSourceCheckoutsUsedFlagValues(container, values);
  }
  return values;
}

function collectLocalProductSourceCheckoutsUsedFlagValues(value, values) {
  if (!value || typeof value !== 'object') {
    return;
  }

  if (Array.isArray(value)) {
    for (const entry of value) {
      collectLocalProductSourceCheckoutsUsedFlagValues(entry, values);
    }
    return;
  }

  if (Object.hasOwn(value, 'local_product_source_checkouts_used')) {
    values.push(value.local_product_source_checkouts_used);
  }
  if (Object.hasOwn(value, 'localProductSourceCheckoutsUsed')) {
    values.push(value.localProductSourceCheckoutsUsed);
  }

  for (const entry of Object.values(value)) {
    if (entry && typeof entry === 'object') {
      collectLocalProductSourceCheckoutsUsedFlagValues(entry, values);
    }
  }
}

function installEvidenceFrom(container) {
  if (!container || typeof container !== 'object' || Array.isArray(container)) {
    return null;
  }

  for (const field of [
    'artifact_install_evidence',
    'artifactInstallEvidence',
    'install_evidence',
    'installEvidence',
  ]) {
    if (container[field] && typeof container[field] === 'object' && !Array.isArray(container[field])) {
      return container[field];
    }
  }

  if (looksLikeInstallEvidence(container)) {
    return container;
  }

  return null;
}

function installEvidenceFromScenarioInputs(scenarioInputs) {
  const scenario = scenarioInputs instanceof Map
    ? scenarioInputs.get('published_artifact_install_only')
    : null;
  const outputs = scenario
    && scenario.observed_outputs
    && typeof scenario.observed_outputs === 'object'
    && !Array.isArray(scenario.observed_outputs)
    ? scenario.observed_outputs
    : {};

  return installEvidenceFrom(outputs);
}

function installEvidenceFromScenarioResults(scenarioResults) {
  const scenario = scenarioResults.find((item) => item.scenario_id === 'published_artifact_install_only');
  const outputs = scenario
    && scenario.observed_outputs
    && typeof scenario.observed_outputs === 'object'
    && !Array.isArray(scenario.observed_outputs)
    ? scenario.observed_outputs
    : {};

  return installEvidenceFrom(outputs);
}

function looksLikeInstallEvidence(value) {
  return value && typeof value === 'object' && !Array.isArray(value)
    && (
      Array.isArray(value.artifacts)
      || (value.artifacts && typeof value.artifacts === 'object')
      || stringValue(value.schema).includes('install-evidence')
    );
}

function installEvidenceArtifactsAllPass(installEvidence) {
  const artifacts = installEvidence && installEvidence.artifacts;
  if (Array.isArray(artifacts)) {
    return artifacts.length > 0 && artifacts.every((entry) => (
      stringValue(entry && (entry.status ?? entry.result ?? entry.outcome)).toLowerCase() === 'pass'
    ));
  }
  if (artifacts && typeof artifacts === 'object') {
    const entries = Object.values(artifacts);
    return entries.length > 0 && entries.every((entry) => (
      stringValue(entry && (entry.status ?? entry.result ?? entry.outcome)).toLowerCase() === 'pass'
    ));
  }

  return false;
}

function selectArtifactInstallEvidence(candidates, artifactVersions, artifactSources) {
  const supplied = candidates.filter((candidate) => (
    candidate.evidence && typeof candidate.evidence === 'object' && !Array.isArray(candidate.evidence)
  ));
  if (supplied.length === 0) {
    return {
      evidence: null,
      failures: artifactInstallEvidencePolicyFailuresFor(
        null,
        artifactVersions,
        artifactSources,
        '$.artifact_install_evidence',
      ),
    };
  }

  let firstFailures = null;
  for (const candidate of supplied) {
    const failures = artifactInstallEvidencePolicyFailuresFor(
      candidate.evidence,
      artifactVersions,
      artifactSources,
      candidate.path,
    );
    if (failures.length === 0) {
      return {
        evidence: {
          ...candidate.evidence,
          supplied_install_evidence: candidate.source !== 'synthesized',
          supplied_install_evidence_source: candidate.source,
          ...(candidate.filePath ? {supplied_install_evidence_path: candidate.filePath} : {}),
        },
        failures: [],
      };
    }
    if (firstFailures === null) {
      firstFailures = failures;
    }
  }

  return {
    evidence: supplied[0].evidence,
    failures: firstFailures || [],
  };
}

function artifactInstallEvidencePolicyFailuresFor(
  installEvidence,
  artifactVersions,
  artifactSources,
  pathPrefix,
) {
  const failures = [];
  if (!installEvidence || typeof installEvidence !== 'object' || Array.isArray(installEvidence)) {
    return [{
      artifact: 'product-artifacts',
      field: 'artifact_install_evidence',
      code: 'missing_published_artifact_install_evidence',
      path: pathPrefix,
    }];
  }

  if (!hasExplicitFalseLocalProductSourceFlag(installEvidence)) {
    failures.push({
      artifact: 'product-artifacts',
      field: 'artifact_install_evidence.local_product_source_checkouts_used',
      code: 'local_product_source_checkouts_used_must_be_false',
      value: installEvidence.local_product_source_checkouts_used
        ?? installEvidence.localProductSourceCheckoutsUsed
        ?? null,
      path: pathPrefix,
    });
  }

  for (const artifact of requiredArtifacts) {
    const entry = artifactInstallEntry(installEvidence, artifact);
    if (entry === null) {
      failures.push({
        artifact,
        field: 'artifact_install_evidence.artifacts',
        code: 'missing_published_artifact_install_evidence_artifact',
        path: `${pathPrefix}.artifacts`,
      });
      continue;
    }

    const status = stringValue(entry.status ?? entry.result ?? entry.outcome).toLowerCase();
    if (status !== 'pass') {
      failures.push({
        artifact,
        field: 'artifact_install_evidence.artifacts.status',
        code: 'published_artifact_install_evidence_not_pass',
        value: status,
        path: `${pathPrefix}.artifacts`,
      });
    }

    const version = stringValue(evidenceLookup(entry, [
      'version',
      'resolved_version',
      'resolvedVersion',
      'artifact_version',
      'artifactVersion',
    ]).value);
    const expectedVersion = stringValue(artifactVersions[artifact]);
    if (version === '') {
      failures.push({
        artifact,
        field: 'artifact_install_evidence.artifacts.version',
        code: 'missing_published_artifact_install_evidence_version',
        path: `${pathPrefix}.artifacts`,
      });
    } else if (isPlaceholderArtifactVersion(version)) {
      failures.push({
        artifact,
        field: 'artifact_install_evidence.artifacts.version',
        code: 'placeholder_published_artifact_install_evidence_version',
        value: version,
        path: `${pathPrefix}.artifacts`,
      });
    } else if (!isExactPublishedArtifactVersion(version)) {
      failures.push({
        artifact,
        field: 'artifact_install_evidence.artifacts.version',
        code: 'invalid_published_artifact_install_evidence_version',
        value: version,
        path: `${pathPrefix}.artifacts`,
      });
    } else if (expectedVersion !== '' && version !== expectedVersion) {
      failures.push({
        artifact,
        field: 'artifact_install_evidence.artifacts.version',
        code: 'published_artifact_install_evidence_version_mismatch',
        value: version,
        expected_value: expectedVersion,
        path: `${pathPrefix}.artifacts`,
      });
    }

    const source = stringValue(evidenceLookup(entry, [
      'source',
      'install_source',
      'installSource',
      'artifact_source',
      'artifactSource',
    ]).value);
    const expectedSource = stringValue(artifactSources[artifact]);
    if (source === '') {
      failures.push({
        artifact,
        field: 'artifact_install_evidence.artifacts.source',
        code: 'missing_published_artifact_install_evidence_source',
        path: `${pathPrefix}.artifacts`,
      });
    } else if (containsForbiddenSourceToken(source)) {
      failures.push({
        artifact,
        field: 'artifact_install_evidence.artifacts.source',
        code: 'forbidden_published_artifact_install_evidence_source',
        value: source,
        path: `${pathPrefix}.artifacts`,
      });
    } else if (!matchesPublishedArtifactSource(artifact, version, source)) {
      failures.push({
        artifact,
        field: 'artifact_install_evidence.artifacts.source',
        code: 'invalid_published_artifact_install_evidence_source',
        value: source,
        path: `${pathPrefix}.artifacts`,
      });
    } else if (expectedSource !== '' && source !== expectedSource) {
      failures.push({
        artifact,
        field: 'artifact_install_evidence.artifacts.source',
        code: 'published_artifact_install_evidence_source_mismatch',
        value: source,
        expected_value: expectedSource,
        path: `${pathPrefix}.artifacts`,
      });
    }

    if (localProductSourceCheckoutsUsedIn(entry)) {
      failures.push({
        artifact,
        field: 'artifact_install_evidence.artifacts.local_product_source_checkouts_used',
        code: 'local_product_source_checkouts_used_must_be_false',
        value: true,
        path: `${pathPrefix}.artifacts`,
      });
    }
  }

  return failures;
}

function artifactInstallEntry(installEvidence, artifact) {
  const artifacts = installEvidence && installEvidence.artifacts;
  const names = [artifact, ...(artifactAliases[artifact] || [])];

  if (Array.isArray(artifacts)) {
    for (const entry of artifacts) {
      if (!entry || typeof entry !== 'object' || Array.isArray(entry)) {
        continue;
      }
      const entryName = stringValue(entry.artifact ?? entry.name ?? entry.id ?? entry.package);
      if (names.includes(entryName)) {
        return entry;
      }
    }
    return null;
  }

  if (artifacts && typeof artifacts === 'object') {
    for (const name of names) {
      if (artifacts[name] && typeof artifacts[name] === 'object' && !Array.isArray(artifacts[name])) {
        return {
          artifact: name,
          ...artifacts[name],
        };
      }
    }
  }

  return null;
}

function truthy(value) {
  if (value === true) {
    return true;
  }
  const text = stringValue(value).toLowerCase();
  return ['1', 'true', 'yes'].includes(text);
}

function explicitFalse(value) {
  if (value === false) {
    return true;
  }
  const text = stringValue(value).toLowerCase();
  return ['0', 'false', 'no'].includes(text);
}

function stringValue(value) {
  return typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean'
    ? String(value).trim()
    : '';
}

function numberValue(value) {
  if (typeof value === 'number' && Number.isFinite(value)) {
    return value;
  }

  const text = stringValue(value);
  if (text === '') {
    return null;
  }

  const number = Number(text);

  return Number.isFinite(number) ? number : null;
}

fs.mkdirSync(resultDir, {recursive: true});

const startedAt = timestamp();
const evidence = readEvidence(evidencePath);
const dedicatedInstallEvidenceResult = readDedicatedInstallEvidence(dedicatedInstallEvidencePath);
const finishedAt = timestamp();
const topLevelInstallEvidence = installEvidenceFrom(evidence);
const rawScenarioInputs = byScenarioId(evidence.scenario_results);
const scenarioInputInstallEvidence = installEvidenceFromScenarioInputs(rawScenarioInputs);
const promotionInstallEvidence = dedicatedInstallEvidenceResult.installEvidence
  || topLevelInstallEvidence
  || null;
const artifactVersions = normalizeArtifactMap(mergeMaps(
  artifactVersionsFromEnv(),
  evidence.artifact_versions,
  evidence.artifactVersions,
  evidence.published_artifact_versions,
  evidence.publishedArtifactVersions,
  evidence.resolved_artifact_versions,
  evidence.resolvedArtifactVersions,
));
const artifactSources = normalizeArtifactMap(mergeMaps(
  artifactSourcesFromEnv(),
  evidence.artifact_sources,
  evidence.artifactSources,
  evidence.install_sources,
  evidence.installSources,
));
const artifactSourceVerification = normalizeArtifactEvidenceMap(mergeMaps(
  evidence.artifact_source_verification,
  evidence.artifactSourceVerification,
  evidence.published_artifact_source_verification,
  evidence.publishedArtifactSourceVerification,
  evidence.artifact_source_resolution,
  evidence.artifactSourceResolution,
));

const runnerBlocked = runnerBlockedIn(evidence);
const runnerBlockedReason = runnerBlockedReasonFrom(evidence);
let scenarioResults = runnerBlocked
  ? requiredScenarios.map((scenarioId) => (
    runnerBlockedScenarioResult(scenarioId, artifactVersions, runnerBlockedReason)
  ))
  : requiredScenarios.map((scenarioId) => (
    normalizeScenarioResult(scenarioId, rawScenarioInputs.get(scenarioId), artifactVersions, promotionInstallEvidence)
  ));
const localProductSourceCheckoutsUsed = localProductSourceCheckoutsUsedIn(
  evidence,
  scenarioResults,
  dedicatedInstallEvidenceResult.installEvidence,
);
const localProductSourceCheckoutsExplicitlyFalse = localProductSourceCheckoutsExplicitlyFalseIn(
  evidence,
  scenarioResults,
  dedicatedInstallEvidenceResult.installEvidence,
);
const topLevelArtifactPolicyFailures = runnerBlocked
  ? []
  : artifactPolicyFailuresFor(
    artifactVersions,
    artifactSources,
    artifactSourceVerification,
    localProductSourceCheckoutsUsed,
    localProductSourceCheckoutsExplicitlyFalse,
  );
const scenarioResultInstallEvidence = installEvidenceFromScenarioResults(scenarioResults);
let artifactInstallEvidenceSelection = runnerBlocked
  ? {evidence: null, failures: []}
  : selectArtifactInstallEvidence([
    {
      evidence: dedicatedInstallEvidenceResult.installEvidence,
      source: 'DW_NEXUS_ARTIFACT_INSTALL_EVIDENCE',
      filePath: dedicatedInstallEvidencePath || null,
      path: '$.artifact_install_evidence',
    },
    {
      evidence: topLevelInstallEvidence,
      source: 'DW_NEXUS_EVIDENCE_JSON.artifact_install_evidence',
      path: '$.artifact_install_evidence',
    },
    {
      evidence: scenarioInputInstallEvidence || scenarioResultInstallEvidence,
      source: 'scenario_results.published_artifact_install_only.observed_outputs.artifact_install_evidence',
      path: '$.scenario_results.published_artifact_install_only.observed_outputs.artifact_install_evidence',
    },
  ], artifactVersions, artifactSources);
const artifactInstallEvidence = artifactInstallEvidenceSelection.evidence;
const artifactInstallEvidencePolicyFailures = artifactInstallEvidenceSelection.failures;
if (!runnerBlocked) {
  scenarioResults = withSyntheticInstallScenarioEvidence(
    scenarioResults,
    artifactVersions,
    artifactSources,
    artifactSourceVerification,
    localProductSourceCheckoutsUsed,
    artifactInstallEvidence,
    topLevelArtifactPolicyFailures.length === 0
      && artifactInstallEvidencePolicyFailures.length === 0
      && artifactInstallEvidence !== null
      && localProductSourceCheckoutsUsed === false,
  );
}
const installScenarioArtifactPolicyFailures = runnerBlocked
  ? []
  : installScenarioArtifactPolicyFailuresFor(
    scenarioResults,
    artifactVersions,
    artifactSources,
  );
const artifactPolicyFailures = [
  ...topLevelArtifactPolicyFailures,
  ...artifactInstallEvidencePolicyFailures,
  ...installScenarioArtifactPolicyFailures,
];
if (!runnerBlocked) {
  scenarioResults = scenarioResults.map((scenario) => (
    applyResultGateFailures(
      scenario,
      artifactVersions,
      [
        ...topLevelArtifactPolicyFailures,
        ...artifactInstallEvidencePolicyFailures,
        ...(scenario.scenario_id === 'published_artifact_install_only'
          ? installScenarioArtifactPolicyFailures
          : []),
      ],
      localProductSourceCheckoutsUsed,
      localProductSourceCheckoutsExplicitlyFalse,
    )
  ));
  scenarioResults = withResultRecordAndRoutingScenario(scenarioResults, artifactVersions);
}

const findings = [];
for (const finding of Array.isArray(evidence.findings) ? evidence.findings : []) {
  findings.push(finding);
}
for (const finding of dedicatedInstallEvidenceResult.findings) {
  findings.push(finding);
}
for (const scenario of scenarioResults) {
  for (const finding of scenario.linked_findings) {
    findings.push(finding);
  }
}

const allPass = scenarioResults.every((scenario) => scenario.status === 'pass');
const resultGatePasses = !runnerBlocked
  && allPass
  && artifactPolicyFailures.length === 0
  && localProductSourceCheckoutsUsed === false;
const outcome = runnerBlocked ? 'non_passing_runner_blocked' : (resultGatePasses ? 'pass' : 'fail');
const findingLinks = {};
for (const scenario of scenarioResults) {
  findingLinks[scenario.scenario_id] = scenario.linked_findings;
}

const pins = {
  schema: 'durable-workflow.v2.nexus-runtime.pins',
  generated_at: finishedAt,
  artifact_versions: artifactVersions,
  artifact_sources: artifactSources,
  artifact_source_verification: artifactSourceVerification,
  artifact_install_evidence: artifactInstallEvidence,
  local_product_source_checkouts_used: localProductSourceCheckoutsUsed,
  evidence_path: evidencePath || null,
};

const result = {
  schema: 'durable-workflow.v2.nexus-runtime.result',
  schema_version: 1,
  suite_schema: 'durable-workflow.v2.platform-conformance.suite',
  category: 'nexus_runtime_contract',
  outcome,
  runner_blocked: runnerBlocked,
  ...(runnerBlocked ? {blocked_reason: runnerBlockedReason} : {}),
  started_at: evidence.started_at || startedAt,
  finished_at: evidence.finished_at || finishedAt,
  generated_at: finishedAt,
  artifact_versions: artifactVersions,
  published_artifact_versions: artifactVersions,
  resolved_artifact_versions: artifactVersions,
  artifact_sources: artifactSources,
  artifact_source_verification: artifactSourceVerification,
  artifact_install_evidence: artifactInstallEvidence,
  artifact_policy_failures: artifactPolicyFailures,
  local_product_source_checkouts_used: localProductSourceCheckoutsUsed,
  topology: {
    namespaces: ['tenant-a', 'tenant-b', 'shared', 'denied'],
    endpoint: 'shared:Greeter',
    operation: 'greet',
  },
  runtime_matrix: {
    callers: ['workflow-php', 'sdk-python'],
    services: ['workflow-php', 'sdk-python'],
    observers: ['caller_history', 'service_call_detail', 'waterline_operator_visibility'],
  },
  scenario_results: scenarioResults,
  findings,
  finding_links: findingLinks,
};

const record = {
  experiment: 'nexus',
  outcome,
  runnerBlocked,
  ...(runnerBlocked ? {blockedReason: runnerBlockedReason} : {}),
  artifactVersions: artifactVersions,
  artifactInstallEvidence: artifactInstallEvidence,
  findings: findings.map((finding) => {
    if (finding && typeof finding.observed_behavior === 'string') {
      return finding.observed_behavior;
    }
    if (finding && typeof finding.summary === 'string') {
      return finding.summary;
    }
    return JSON.stringify(finding);
  }),
  resultPath: path.join(resultDir, 'nexus-conformance-result.json'),
};

fs.writeFileSync(path.join(resultDir, 'pins.json'), JSON.stringify(pins, null, 2) + '\n');
fs.writeFileSync(path.join(resultDir, 'nexus-conformance-result.json'), JSON.stringify(result, null, 2) + '\n');
fs.writeFileSync(path.join(resultDir, 'nexus-conformance-record.json'), JSON.stringify(record, null, 2) + '\n');

console.log(`nexus conformance outcome: ${outcome}`);
console.log(`nexus conformance result: ${path.join(resultDir, 'nexus-conformance-result.json')}`);
NODE
