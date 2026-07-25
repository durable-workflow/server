import crypto from 'node:crypto';
import fs from 'node:fs';
import net from 'node:net';
import path from 'node:path';
import process from 'node:process';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

import {
  HeartbeatReadinessTimeoutError,
  classifySharedServerStartup,
  selectLoopbackPublishedEndpoint,
  waitForAuthenticatedReadiness,
} from './heartbeat-shared-readiness.mjs';
import {
  heartbeatRelayLauncherArguments,
  heartbeatRelayProcessMatches,
  readHeartbeatRelayPid,
  stopHeartbeatRelay,
} from './heartbeat-shared-relay.mjs';
import { isExactSemverRelease } from './version-identities.mjs';

const SCHEMA = 'durable-workflow.v2.heartbeat-runtime.shared-server-bootstrap';
const STARTUP_DIAGNOSTIC_FILES = {
  summary: 'shared-server-startup-diagnostics.json',
  compose_ps: 'shared-server-compose-ps.log',
  port_mapping: 'shared-server-port-mapping.log',
  relay_log: 'shared-server-relay.log',
  server_log: 'shared-server-server.log',
};
const RELAY_PID_FILE = 'shared-server-relay.pid';
const DIAGNOSTIC_OUTPUT_LIMIT = 64 * 1024;
const action = process.argv[2] ?? '';
const stateArgument = process.argv[3] ?? '';
const statePath = stateArgument ? path.resolve(stateArgument) : '';
const scriptDirectory = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(process.env.REPO_ROOT ?? path.join(scriptDirectory, '../..'));

function env(name) {
  return String(process.env[name] ?? '').trim();
}

function now() {
  return new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
}

function digest(value) {
  return crypto.createHash('sha256').update(value).digest('hex');
}

function parseJson(value) {
  const parsed = JSON.parse(String(value));
  return Array.isArray(parsed) && parsed.length === 1 ? parsed[0] : parsed;
}

function parseJsonOrNull(value) {
  try {
    return parseJson(value);
  } catch {
    return null;
  }
}

function run(command, args, options = {}) {
  const result = spawnSync(command, args, {
    cwd: options.cwd ?? repoRoot,
    env: options.env ?? process.env,
    encoding: 'utf8',
    maxBuffer: 20 * 1024 * 1024,
    timeout: options.timeout ?? 180_000,
  });
  if (!options.allowFailure && result.status !== 0) {
    throw new Error(
      `${[command, ...args].join(' ')} failed (${result.status}): `
      + `${String(result.stderr || result.stdout).trim()}`,
    );
  }
  return result;
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
  if (!port) throw new Error('could not allocate a shared heartbeat server port');
  return port;
}

function writeJson(file, value) {
  fs.mkdirSync(path.dirname(file), { recursive: true });
  const temporary = `${file}.tmp-${process.pid}`;
  fs.writeFileSync(temporary, `${JSON.stringify(value, null, 2)}\n`, 'utf8');
  fs.renameSync(temporary, file);
}

function boundedOutput(value) {
  const text = String(value ?? '');
  if (Buffer.byteLength(text, 'utf8') <= DIAGNOSTIC_OUTPUT_LIMIT) {
    return { text, truncated: false };
  }
  const suffix = '\n[diagnostic output truncated]\n';
  return {
    text: `${Buffer.from(text).subarray(0, DIAGNOSTIC_OUTPUT_LIMIT - suffix.length).toString()}${suffix}`,
    truncated: true,
  };
}

function captureDiagnostic(command, args, options = {}) {
  const result = run(command, args, {
    ...options,
    allowFailure: true,
    timeout: options.timeout ?? 30_000,
  });
  const stdout = boundedOutput(result.stdout);
  const stderr = boundedOutput(result.stderr);
  return {
    command: [command, ...args],
    status: result.status,
    signal: result.signal,
    error: result.error ? String(result.error.message ?? result.error) : null,
    stdout: stdout.text,
    stderr: stderr.text,
    stdout_truncated: stdout.truncated,
    stderr_truncated: stderr.truncated,
  };
}

function diagnosticText(record) {
  const output = [
    `$ ${record.command.join(' ')}`,
    `exit_status=${record.status ?? 'none'} signal=${record.signal ?? 'none'}`,
  ];
  if (record.error) output.push(`error=${record.error}`);
  if (record.stdout) output.push('', record.stdout.trimEnd());
  if (record.stderr) output.push('', record.stderr.trimEnd());
  return `${output.join('\n')}\n`;
}

function collectStartupDiagnostics(
  composePrefix,
  composeEnvironment,
  serverContainerId,
) {
  const inspect = serverContainerId
    ? captureDiagnostic('docker', [
      'container', 'inspect', '--format',
      '{"Image":{{json .Image}},"Config":{"Image":{{json .Config.Image}}},'
      + '"State":{{json .State}},"NetworkSettings":{"Ports":{{json .NetworkSettings.Ports}}}}',
      serverContainerId,
    ], { timeout: 60_000 })
    : {
      command: ['docker', 'container', 'inspect'],
      status: null,
      signal: null,
      error: 'server container id was unavailable',
      stdout: '',
      stderr: '',
      stdout_truncated: false,
      stderr_truncated: false,
    };
  return {
    schema: 'durable-workflow.v2.heartbeat-runtime.shared-server-startup-diagnostics',
    version: 1,
    captured_at: now(),
    output_limit_bytes: DIAGNOSTIC_OUTPUT_LIMIT,
    compose_port: captureDiagnostic('docker', [
      ...composePrefix, 'port', 'server', '8080',
    ], { env: composeEnvironment }),
    compose_ps: captureDiagnostic('docker', [
      ...composePrefix, 'ps', '-a',
    ], { env: composeEnvironment }),
    server_log: captureDiagnostic('docker', [
      ...composePrefix, 'logs', '--no-color', '--tail', '200', 'server',
    ], { env: composeEnvironment }),
    server_container_inspect: inspect,
  };
}

function writeStartupDiagnostics(diagnostics) {
  const directory = path.dirname(statePath);
  writeJson(path.join(directory, STARTUP_DIAGNOSTIC_FILES.summary), diagnostics);
  fs.writeFileSync(
    path.join(directory, STARTUP_DIAGNOSTIC_FILES.compose_ps),
    diagnosticText(diagnostics.compose_ps),
    'utf8',
  );
  fs.writeFileSync(
    path.join(directory, STARTUP_DIAGNOSTIC_FILES.port_mapping),
    diagnosticText(diagnostics.compose_port),
    'utf8',
  );
  fs.writeFileSync(
    path.join(directory, STARTUP_DIAGNOSTIC_FILES.server_log),
    diagnosticText(diagnostics.server_log),
    'utf8',
  );
  const relayLog = path.join(directory, STARTUP_DIAGNOSTIC_FILES.relay_log);
  if (!fs.existsSync(relayLog)) fs.writeFileSync(relayLog, '', 'utf8');
}

function startupError(classification, readiness = null) {
  const readinessDetails = readiness
    ? `; final readiness status=${readiness.final_status ?? 'none'}; `
      + `final readiness error=${readiness.final_error ?? 'none'}`
    : '';
  const error = new Error(
    `shared published server startup failed (${classification.kind}): `
    + `${classification.reason}${readinessDetails}; `
    + `see ${Object.values(STARTUP_DIAGNOSTIC_FILES).join(', ')}`,
  );
  error.name = 'HeartbeatSharedServerStartupError';
  error.failure_kind = classification.kind;
  error.readiness = readiness;
  return error;
}

function dockerResources(project) {
  return {
    containers: String(run('docker', [
      'ps', '-aq', '--filter', `label=com.docker.compose.project=${project}`,
    ], { timeout: 30_000 }).stdout).trim().split(/\r?\n/).filter(Boolean),
    volumes: String(run('docker', [
      'volume', 'ls',
      '--filter', `label=com.docker.compose.project=${project}`,
      '--format', '{{.Name}}',
    ], { timeout: 30_000 }).stdout).trim().split(/\r?\n/).filter(Boolean),
    networks: String(run('docker', [
      'network', 'ls',
      '--filter', `label=com.docker.compose.project=${project}`,
      '--format', '{{.Name}}',
    ], { timeout: 30_000 }).stdout).trim().split(/\r?\n/).filter(Boolean),
  };
}

function networkContainerIds(network, excludedReferences = []) {
  const inspected = run('docker', [
    'network', 'inspect', '--format', '{{json .Containers}}', network,
  ], { allowFailure: true, timeout: 30_000 });
  if (inspected.status !== 0) return [];
  const containers = parseJson(inspected.stdout) ?? {};
  const exclusions = excludedReferences.map((value) => String(value ?? '').trim()).filter(Boolean);
  return [...new Set(Object.entries(containers)
    .filter(([id, container]) => !exclusions.some((reference) =>
      id.startsWith(reference)
      || reference.startsWith(id)
      || String(container?.Name ?? '') === reference
      || String(container?.ContainerID ?? '').startsWith(reference)))
    .map(([, container]) => String(container?.Name ?? container?.ContainerID ?? '').trim())
    .filter(Boolean))];
}

function currentExecutorContainer() {
  if (!fs.existsSync('/.dockerenv')) return null;
  const reference = env('HOSTNAME');
  if (!reference) return null;
  const inspection = run('docker', [
    'container', 'inspect', '--format',
    '{"Id":{{json .Id}},"Config":{"Hostname":{{json .Config.Hostname}}},'
    + '"State":{"Running":{{json .State.Running}}},"NetworkSettings":'
    + '{"Networks":{{json .NetworkSettings.Networks}}}}',
    reference,
  ], {
    allowFailure: true,
    timeout: 30_000,
  });
  if (inspection.status !== 0) return null;
  const container = parseJsonOrNull(inspection.stdout);
  if (!container?.Id
    || container?.Config?.Hostname !== reference
    || container?.State?.Running !== true) {
    return null;
  }
  return {
    reference,
    id: String(container.Id),
    networks: container?.NetworkSettings?.Networks ?? {},
  };
}

function executorNetworkAttachment(network) {
  const executor = currentExecutorContainer();
  if (!executor) {
    return {
      mode: 'published_loopback',
      attached: false,
      owned: false,
    };
  }
  if (executor.networks?.[network]) {
    throw new Error('temporary heartbeat network was attached before wave ownership began');
  }
  const connection = run('docker', ['network', 'connect', network, executor.reference], {
    allowFailure: true,
    timeout: 30_000,
  });
  const attached = currentExecutorContainer();
  if (connection.status !== 0 || !attached?.networks?.[network]) {
    throw new Error('could not attach the current executor to the shared Compose network');
  }
  return {
    mode: 'executor_network_attachment',
    attached: true,
    owned: true,
    executor_reference: executor.reference,
  };
}

function disconnectExecutorNetwork(network) {
  const executor = currentExecutorContainer();
  if (!executor) {
    return {
      status: 'failed',
      error: 'could not identify the current executor for network detachment',
    };
  }
  if (!executor.networks?.[network]) return { status: 'already_detached', error: null };
  const disconnection = run('docker', [
    'network', 'disconnect', '--force', network, executor.reference,
  ], {
    allowFailure: true,
    timeout: 30_000,
  });
  const detached = currentExecutorContainer();
  if (disconnection.status !== 0 || !detached || detached.networks?.[network]) {
    return {
      status: 'failed',
      error: 'the current executor remained attached to the shared Compose network',
    };
  }
  return { status: 'detached', error: null };
}

function waitForRelayPid(pidFile, ownershipToken, timeoutMs = 10_000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const pid = readHeartbeatRelayPid(pidFile);
    if (pid) {
      if (!heartbeatRelayProcessMatches(pid, ownershipToken)) {
        throw new Error('the heartbeat relay PID does not match wave ownership');
      }
      return pid;
    }
    Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 50);
  }
  throw new Error('the daemon-owned heartbeat relay did not publish its PID in time');
}

function currentNumericUser() {
  const userId = process.getuid?.();
  const groupId = process.getgid?.();
  if (!Number.isInteger(userId) || userId < 0
    || !Number.isInteger(groupId) || groupId < 0) {
    throw new Error('the heartbeat relay requires a numeric executor UID and GID');
  }
  return `${userId}:${groupId}`;
}

function startRelayProcess(executorReference, port, ownershipToken, targetOrigin) {
  const relayLog = path.join(
    path.dirname(statePath),
    STARTUP_DIAGNOSTIC_FILES.relay_log,
  );
  const relayPidFile = path.join(
    path.dirname(statePath),
    RELAY_PID_FILE,
  );
  fs.rmSync(relayPidFile, { force: true });
  const relayCommand = heartbeatRelayLauncherArguments({
    nodeBinary: process.execPath,
    relayScript: path.join(scriptDirectory, 'heartbeat-shared-relay.mjs'),
    ownershipToken,
  });
  run('docker', [
    'exec', '-d',
    '--user', currentNumericUser(),
    '--workdir', repoRoot,
    '--env', `DW_HEARTBEAT_RELAY_PORT=${port}`,
    '--env', `DW_HEARTBEAT_RELAY_TARGET_ORIGIN=${targetOrigin}`,
    '--env', `DW_HEARTBEAT_RELAY_PID_FILE=${relayPidFile}`,
    '--env', `DW_HEARTBEAT_RELAY_LOG_FILE=${relayLog}`,
    executorReference,
    ...relayCommand,
  ], { timeout: 30_000 });
  return waitForRelayPid(relayPidFile, ownershipToken);
}

function composeDown(state, allowFailure = false) {
  const baseFile = path.join(repoRoot, state.compose.base_file);
  const overrideFile = path.join(path.dirname(statePath), state.compose.override_file);
  return run('docker', [
    'compose',
    '-p', state.compose.project,
    '-f', baseFile,
    '-f', overrideFile,
    'down', '-v', '--remove-orphans',
  ], {
    env: {
      ...process.env,
      SERVER_PORT: String(state.endpoint.port),
      DW_SERVER_TAG: state.server.version,
      DW_SERVER_IMAGE: state.server.requested_reference,
      DW_AUTH_TOKEN: 'cleanup-only-redacted-token',
      DB_DATABASE: 'durable_workflow',
      DB_USERNAME: 'workflow',
      DB_PASSWORD: 'workflow',
      DB_ROOT_PASSWORD: 'root',
    },
    allowFailure,
    timeout: 180_000,
  });
}

function validateState(value) {
  if (value?.schema !== SCHEMA || value?.version !== 1) {
    throw new Error(`shared heartbeat server state at ${statePath} has an unsupported schema`);
  }
  if (!value?.compose?.project || !value?.compose?.base_file || !value?.compose?.override_file) {
    throw new Error(`shared heartbeat server state at ${statePath} has no compose ownership`);
  }
  if (value.compose.base_file !== 'docker-compose.published.yml'
    || path.basename(value.compose.override_file) !== value.compose.override_file
    || value.compose.network !== `${value.compose.project}_default`) {
    throw new Error(`shared heartbeat server state at ${statePath} has invalid compose paths`);
  }
  const transport = value?.endpoint?.transport;
  if (!['executor_network_attachment', 'published_loopback'].includes(transport?.mode)
    || value.endpoint.container_url !== 'http://server:8080'
    || transport.compose_network !== value.compose.network
    || transport.host_control_url !== value.endpoint.host_control_url
    || (transport.mode === 'executor_network_attachment'
      && (
        transport.executor_network_attached !== true
        || transport.attachment_owned_by_wave !== true
        || !Number.isInteger(transport.relay_pid)
        || transport.relay_pid_file !== RELAY_PID_FILE
        || value.endpoint.host_control_url !== 'http://server:8080'
      ))
    || (transport.mode === 'published_loopback'
      && (
        transport.executor_network_attached !== false
        || transport.attachment_owned_by_wave !== false
        || transport.relay_pid !== null
        || transport.relay_pid_file !== null
        || value.endpoint.host_control_url !== value.endpoint.host_url
      ))) {
    throw new Error(`shared heartbeat server state at ${statePath} has invalid transport ownership`);
  }
  return value;
}

async function start() {
  const serverVersion = env('DW_SERVER_VERSION');
  const serverImage = env('DW_SERVER_IMAGE') || `durableworkflow/server:${serverVersion}`;
  const token = env('DW_HEARTBEATS_AUTH_TOKEN') || 'dev-token';
  const heartbeatSeconds = Number.parseInt(env('DW_HEARTBEATS_HEARTBEAT_SECONDS') || '2', 10);
  const staleAfterSeconds = Number.parseInt(env('DW_HEARTBEATS_STALE_AFTER_SECONDS') || '7', 10);
  if (!isExactSemverRelease(serverVersion)) {
    throw new Error('DW_SERVER_VERSION must be an exact SemVer release');
  }
  const exactTag = new RegExp(
    `^(?:(?:docker\\.io|index\\.docker\\.io)/)?durableworkflow/server:${serverVersion.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}$`,
  ).test(serverImage);
  const exactDigest = /^(?:(?:docker\.io|index\.docker\.io)\/)?durableworkflow\/server(?::[^@]+)?@sha256:[0-9a-f]{64}$/i
    .test(serverImage);
  if (!exactTag && !exactDigest) {
    throw new Error('DW_SERVER_IMAGE must be an exact public version tag or digest pin');
  }
  if (!Number.isInteger(heartbeatSeconds) || heartbeatSeconds < 1
    || !Number.isInteger(staleAfterSeconds) || staleAfterSeconds < 1) {
    throw new Error('shared heartbeat cadence and stale thresholds must be positive integers');
  }
  if (fs.existsSync(statePath)) {
    throw new Error(`refusing to overwrite existing shared heartbeat server state: ${statePath}`);
  }

  const baseFile = path.join(repoRoot, 'docker-compose.published.yml');
  if (!fs.existsSync(baseFile)) throw new Error(`published compose file not found: ${baseFile}`);
  fs.mkdirSync(path.dirname(statePath), { recursive: true });
  const suffix = digest(`${statePath}:${process.pid}:${Date.now()}`).slice(0, 12);
  const project = `dw-hb-wave-${suffix}`;
  const overrideFile = path.join(path.dirname(statePath), `docker-compose.heartbeat-${suffix}.yml`);
  const port = await freePort();
  let relayPort = await freePort();
  while (relayPort === port) relayPort = await freePort();
  fs.writeFileSync(overrideFile, `services:
  server:
    environment:
      DW_WORKER_HEARTBEAT_INTERVAL_SECONDS: "${heartbeatSeconds}"
      DW_WORKER_STALE_AFTER_SECONDS: "${staleAfterSeconds}"
      DW_WORKER_POLL_TIMEOUT: "1"
    healthcheck:
      interval: 2s
      timeout: 5s
      retries: 60
`, 'utf8');
  const composeEnvironment = {
    ...process.env,
    SERVER_PORT: String(port),
    DW_SERVER_TAG: serverVersion,
    DW_SERVER_IMAGE: serverImage,
    DW_AUTH_TOKEN: token,
    DW_AUTH_BACKWARD_COMPATIBLE: 'true',
    DB_DATABASE: 'durable_workflow',
    DB_USERNAME: 'workflow',
    DB_PASSWORD: 'workflow',
    DB_ROOT_PASSWORD: 'root',
  };
  const composePrefix = [
    'compose', '-p', project, '-f', baseFile, '-f', overrideFile,
  ];
  const partialState = {
    compose: {
      project,
      base_file: path.basename(baseFile),
      override_file: path.basename(overrideFile),
    },
    endpoint: { port },
    server: {
      version: serverVersion,
      requested_reference: serverImage,
    },
  };
  let executorAttachment = null;
  let relayPid = null;
  const relayPidFile = path.join(
    path.dirname(statePath),
    RELAY_PID_FILE,
  );
  try {
    run('docker', ['pull', serverImage], { timeout: 300_000 });
    const image = parseJson(run('docker', ['image', 'inspect', serverImage], { timeout: 60_000 }).stdout);
    if (!String(image?.Id ?? '').startsWith('sha256:')) {
      throw new Error(`could not resolve the pulled server image id for ${serverImage}`);
    }
    const publicDigest = (image.RepoDigests ?? []).find((value) =>
      /^(?:(?:docker\.io|index\.docker\.io)\/)?durableworkflow\/server@sha256:[0-9a-f]{64}$/i.test(String(value)));
    if (!publicDigest) throw new Error(`pulled server image ${serverImage} has no public repository digest`);
    const canonicalPublicDigest = String(publicDigest).replace(/^(?:docker\.io|index\.docker\.io)\//i, '');
    const versionTagReference = `durableworkflow/server:${serverVersion}`;
    if (serverImage.includes('@sha256:')) {
      run('docker', ['pull', versionTagReference], { timeout: 300_000 });
      const tagged = parseJson(run('docker', ['image', 'inspect', versionTagReference], { timeout: 60_000 }).stdout);
      if (tagged?.Id !== image.Id) {
        throw new Error(`digest-pinned server image does not match public version tag ${versionTagReference}`);
      }
    }

    run('docker', [...composePrefix, 'up', '-d', '--wait', 'server'], {
      env: composeEnvironment,
      timeout: 360_000,
    });
    const serverContainerId = String(run('docker', [
      ...composePrefix, 'ps', '-q', 'server',
    ], { env: composeEnvironment, timeout: 30_000 }).stdout).trim();
    const bootstrapContainerId = String(run('docker', [
      ...composePrefix, 'ps', '-a', '-q', 'bootstrap',
    ], { env: composeEnvironment, timeout: 30_000 }).stdout).trim();
    if (!serverContainerId || !bootstrapContainerId) {
      throw new Error('shared compose project did not retain its server and bootstrap containers');
    }
    let startupDiagnostics = collectStartupDiagnostics(
      composePrefix,
      composeEnvironment,
      serverContainerId,
    );
    writeStartupDiagnostics(startupDiagnostics);
    const serverContainer = parseJsonOrNull(startupDiagnostics.server_container_inspect.stdout);
    const bootstrapContainer = parseJson(run('docker', [
      'container', 'inspect', '--format',
      '{"Image":{{json .Image}},"Config":{"Image":{{json .Config.Image}},"Cmd":{{json .Config.Cmd}}},"State":{{json .State}}}',
      bootstrapContainerId,
    ], { timeout: 60_000 }).stdout);
    const startupClassification = classifySharedServerStartup({
      container: serverContainer,
      composePort: startupDiagnostics.compose_port,
      expectedPort: port,
    });
    if (startupClassification) {
      startupDiagnostics.classification = startupClassification;
      writeStartupDiagnostics(startupDiagnostics);
      throw startupError(startupClassification);
    }
    if (serverContainer?.Image !== image.Id || serverContainer?.Config?.Image !== serverImage) {
      throw new Error('running shared server container does not match the exact selected image');
    }
    if (bootstrapContainer?.Image !== image.Id
      || bootstrapContainer?.Config?.Image !== serverImage
      || bootstrapContainer?.State?.ExitCode !== 0
      || bootstrapContainer?.State?.Status !== 'exited'
      || !Array.isArray(bootstrapContainer?.Config?.Cmd)
      || !bootstrapContainer.Config.Cmd.includes('server-bootstrap')) {
      throw new Error('clean published-server bootstrap and migrations did not complete successfully');
    }
    const publishedEndpoint = selectLoopbackPublishedEndpoint(
      startupDiagnostics.compose_port.stdout,
      port,
    );
    const publishedHostUrl = publishedEndpoint.host_url;
    const network = `${project}_default`;
    executorAttachment = executorNetworkAttachment(network);
    const executorMode = executorAttachment.mode === 'executor_network_attachment';
    const hostControlUrl = executorMode ? 'http://server:8080' : publishedHostUrl;
    let hostUrl = publishedHostUrl;
    if (executorMode) {
      hostUrl = `http://127.0.0.1:${relayPort}`;
      relayPid = startRelayProcess(
        executorAttachment.executor_reference,
        relayPort,
        project,
        hostControlUrl,
      );
    }
    let readiness;
    let compatibilityReadiness = null;
    try {
      readiness = await waitForAuthenticatedReadiness({
        url: new URL('/api/ready', hostControlUrl),
        token,
        timeoutMs: executorMode ? 30_000 : 90_000,
      });
      if (executorMode) {
        compatibilityReadiness = await waitForAuthenticatedReadiness({
          url: new URL('/api/ready', hostUrl),
          token,
          timeoutMs: 15_000,
        });
      }
    } catch (error) {
      startupDiagnostics = collectStartupDiagnostics(
        composePrefix,
        composeEnvironment,
        serverContainerId,
      );
      if (error instanceof HeartbeatReadinessTimeoutError) {
        const finalContainer = parseJsonOrNull(
          startupDiagnostics.server_container_inspect.stdout,
        );
        const classification = classifySharedServerStartup({
          container: finalContainer,
          composePort: startupDiagnostics.compose_port,
          expectedPort: port,
          readiness: error.readiness,
          readinessTransport: executorMode
            ? (readiness
              ? 'executor_compatibility_relay'
              : 'executor_network_attachment')
            : 'published_host',
        }) ?? {
          kind: executorMode ? 'executor_network_failure' : 'host_reachability_failure',
          reason: executorMode
            ? 'the authenticated executor-network readiness deadline expired'
            : 'the authenticated published-host readiness deadline expired',
        };
        startupDiagnostics.readiness = error.readiness;
        startupDiagnostics.classification = classification;
        writeStartupDiagnostics(startupDiagnostics);
        throw startupError(classification, error.readiness);
      }
      startupDiagnostics.readiness = {
        final_status: null,
        final_error: error instanceof Error ? error.message : String(error),
      };
      writeStartupDiagnostics(startupDiagnostics);
      throw error;
    }
    startupDiagnostics = collectStartupDiagnostics(
      composePrefix,
      composeEnvironment,
      serverContainerId,
    );
    startupDiagnostics.readiness = readiness;
    startupDiagnostics.compatibility_relay_readiness = compatibilityReadiness;
    startupDiagnostics.classification = {
      kind: 'ready',
      reason: executorMode
        ? 'the authenticated executor-network endpoint returned a successful response'
        : 'the authenticated published-host endpoint returned a successful response',
    };
    startupDiagnostics.transport = {
      mode: executorAttachment.mode,
      relay_pid: relayPid,
      relay_pid_file: executorMode ? RELAY_PID_FILE : null,
      relay_host_url: hostUrl,
      host_control_url: hostControlUrl,
      target_origin: hostControlUrl,
      compose_network: network,
      executor_network_attached: executorAttachment.attached,
      attachment_owned_by_wave: executorAttachment.owned,
    };
    writeStartupDiagnostics(startupDiagnostics);

    const namespaceBase = `hb-wave-${suffix}`;
    const state = {
      schema: SCHEMA,
      version: 1,
      wave_run_id: `heartbeat-wave-${suffix}`,
      started_at: now(),
      server: {
        version: serverVersion,
        requested_reference: serverImage,
        public_version_tag: versionTagReference,
        resolved_public_digest: canonicalPublicDigest,
        resolved_image_id: image.Id,
        running_container_id: serverContainerId,
        running_configured_reference: serverContainer.Config.Image,
        running_image_id: serverContainer.Image,
        exact_published_image_verified: true,
      },
      clean_bootstrap: {
        status: 'pass',
        fresh_compose_project: true,
        bootstrap_container_id: bootstrapContainerId,
        configured_command: bootstrapContainer.Config.Cmd,
        container_status: bootstrapContainer.State.Status,
        exit_code: bootstrapContainer.State.ExitCode,
        migrations_completed: true,
      },
      endpoint: {
        host_url: hostUrl,
        host_control_url: hostControlUrl,
        published_host_url: publishedHostUrl,
        container_url: 'http://server:8080',
        port: publishedEndpoint.port,
        published_bindings: publishedEndpoint.bindings,
        readiness_status: readiness.status,
        readiness_attempts: readiness.attempts,
        readiness_elapsed_ms: readiness.elapsed_ms,
        readiness_timeout_ms: readiness.timeout_ms,
        compatibility_readiness_status: compatibilityReadiness?.status ?? null,
        compatibility_readiness_attempts: compatibilityReadiness?.attempts ?? null,
        startup_diagnostics: STARTUP_DIAGNOSTIC_FILES,
        transport: {
          mode: executorAttachment.mode,
          relay_pid: relayPid,
          relay_pid_file: executorMode ? RELAY_PID_FILE : null,
          relay_host_url: hostUrl,
          host_control_url: hostControlUrl,
          target_origin: hostControlUrl,
          compose_network: network,
          executor_network_attached: executorAttachment.attached,
          attachment_owned_by_wave: executorAttachment.owned,
        },
      },
      compose: {
        project,
        base_file: path.basename(baseFile),
        override_file: path.basename(overrideFile),
        network,
      },
      cell_isolation: Object.fromEntries(['php', 'python', 'rust', 'waterline'].map((cell) => [
        cell,
        {
          namespace: `${namespaceBase}-${cell}`,
          task_queue_prefix: cell === 'waterline' ? 'waterline-status-' : `hb-${cell}-`,
          workflow_id_prefix: cell === 'waterline' ? 'waterline-worker-status-' : `hb-${cell}-`,
          worker_id_prefix: cell === 'waterline' ? 'waterline-' : `heartbeat-${cell}-`,
        },
      ])),
      lifecycle: {
        owner: 'heartbeat-wave-runner',
        cleanup_required: true,
        cleanup_status: 'pending',
      },
    };
    writeJson(statePath, state);
    process.stdout.write(`${JSON.stringify(state)}\n`);
  } catch (error) {
    const cleanupRelayPid = relayPid ?? readHeartbeatRelayPid(relayPidFile);
    if (cleanupRelayPid) {
      stopHeartbeatRelay({
        pid: cleanupRelayPid,
        ownershipToken: project,
        pidFile: relayPidFile,
      });
    }
    if (executorAttachment?.owned) disconnectExecutorNetwork(`${project}_default`);
    composeDown(partialState, true);
    fs.rmSync(overrideFile, { force: true });
    throw error;
  }
}

function stop() {
  const state = validateState(JSON.parse(fs.readFileSync(statePath, 'utf8')));
  const executor = currentExecutorContainer();
  const executorExclusions = [env('HOSTNAME'), executor?.id];
  const relayCleanup = stopHeartbeatRelay({
    pid: state.endpoint.transport.relay_pid,
    ownershipToken: state.compose.project,
    pidFile: state.endpoint.transport.relay_pid_file
      ? path.join(path.dirname(statePath), state.endpoint.transport.relay_pid_file)
      : path.join(path.dirname(statePath), RELAY_PID_FILE),
  });
  const executorNetworkCleanup =
    state.endpoint.transport.mode === 'executor_network_attachment'
      && state.endpoint.transport.attachment_owned_by_wave === true
      ? disconnectExecutorNetwork(state.compose.network)
      : { status: 'not_required', error: null };
  const downAttempts = [composeDown(state, true)];
  const attachedCellContainers = executorNetworkCleanup.status === 'failed'
    ? []
    : networkContainerIds(state.compose.network, executorExclusions);
  const attachedCellRemovalFailures = [];
  const fallbackRemoved = { containers: [], volumes: [], networks: [] };
  for (const name of attachedCellContainers) {
    const removal = run('docker', ['rm', '-f', name], {
      allowFailure: true,
      timeout: 30_000,
    });
    if (removal.status === 0) {
      fallbackRemoved.containers.push(name);
    } else {
      attachedCellRemovalFailures.push(
        `${name}: ${String(removal.stderr || removal.stdout).trim()}`,
      );
    }
  }
  if (attachedCellContainers.length > 0) {
    downAttempts.push(composeDown(state, true));
  }
  let remaining = dockerResources(state.compose.project);
  const removalCommands = {
    containers: (name) => ['rm', '-f', name],
    volumes: (name) => ['volume', 'rm', '-f', name],
    networks: (name) => ['network', 'rm', name],
  };
  for (const kind of ['containers', 'volumes', 'networks']) {
    for (const name of remaining[kind]) {
      const removal = run('docker', removalCommands[kind](name), {
        allowFailure: true,
        timeout: 30_000,
      });
      if (removal.status === 0) fallbackRemoved[kind].push(name);
    }
  }
  remaining = {
    ...dockerResources(state.compose.project),
    attached_containers: networkContainerIds(state.compose.network, executorExclusions),
  };
  const failures = [...attachedCellRemovalFailures];
  if (!['stopped', 'already_stopped', 'not_started'].includes(relayCleanup.status)) {
    failures.push(`daemon-network relay cleanup failed: ${relayCleanup.error ?? relayCleanup.status}`);
  }
  if (!['detached', 'already_detached', 'not_required'].includes(executorNetworkCleanup.status)) {
    failures.push(
      `executor network cleanup failed: `
      + `${executorNetworkCleanup.error ?? executorNetworkCleanup.status}`,
    );
    failures.push('attached container fallback removal was skipped without verified executor detachment');
  }
  for (const [kind, values] of Object.entries(remaining)) {
    if (values.length > 0) failures.push(`${kind} remain: ${values.join(', ')}`);
  }
  state.lifecycle.cleanup_status = failures.length === 0 ? 'pass' : 'fail';
  state.lifecycle.cleanup_finished_at = now();
  state.lifecycle.cleanup_failures = failures;
  state.lifecycle.cleanup_resources_remaining = remaining;
  state.lifecycle.compose_down_exit_code = downAttempts.at(-1)?.status ?? null;
  state.lifecycle.compose_down_exit_codes = downAttempts.map((attempt) => attempt.status);
  state.lifecycle.relay_cleanup = relayCleanup;
  state.lifecycle.executor_network_cleanup = executorNetworkCleanup;
  state.lifecycle.attached_cell_containers_found = attachedCellContainers;
  state.lifecycle.fallback_removed = fallbackRemoved;
  writeJson(statePath, state);
  fs.rmSync(path.join(path.dirname(statePath), state.compose.override_file), { force: true });
  process.stdout.write(`${JSON.stringify(state.lifecycle)}\n`);
  if (failures.length > 0) throw new Error(failures.join('; '));
}

if (!statePath || !['start', 'stop'].includes(action)) {
  process.stderr.write('usage: heartbeats-shared-server.mjs <start|stop> <state-file>\n');
  process.exitCode = 2;
} else if (action === 'start') {
  await start();
} else {
  stop();
}
