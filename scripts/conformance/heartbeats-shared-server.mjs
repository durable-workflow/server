import crypto from 'node:crypto';
import fs from 'node:fs';
import net from 'node:net';
import path from 'node:path';
import process from 'node:process';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

import { waitForAuthenticatedReadiness } from './heartbeat-shared-readiness.mjs';
import { isExactSemverRelease } from './version-identities.mjs';

const SCHEMA = 'durable-workflow.v2.heartbeat-runtime.shared-server-bootstrap';
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

function networkContainerIds(network) {
  const inspected = run('docker', [
    'network', 'inspect', '--format', '{{json .Containers}}', network,
  ], { allowFailure: true, timeout: 30_000 });
  if (inspected.status !== 0) return [];
  const containers = parseJson(inspected.stdout) ?? {};
  return [...new Set(Object.values(containers)
    .map((container) => String(container?.Name ?? container?.ContainerID ?? '').trim())
    .filter(Boolean))];
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
    const serverContainer = parseJson(run('docker', [
      'container', 'inspect', '--format',
      '{"Image":{{json .Image}},"Config":{"Image":{{json .Config.Image}}},"State":{{json .State}}}',
      serverContainerId,
    ], { timeout: 60_000 }).stdout);
    const bootstrapContainer = parseJson(run('docker', [
      'container', 'inspect', '--format',
      '{"Image":{{json .Image}},"Config":{"Image":{{json .Config.Image}},"Cmd":{{json .Config.Cmd}}},"State":{{json .State}}}',
      bootstrapContainerId,
    ], { timeout: 60_000 }).stdout);
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
    const hostUrl = `http://127.0.0.1:${port}`;
    const readiness = await waitForAuthenticatedReadiness({
      url: new URL('/api/ready', hostUrl),
      token,
    });

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
        container_url: 'http://server:8080',
        port,
        readiness_status: readiness.status,
        readiness_attempts: readiness.attempts,
        readiness_elapsed_ms: readiness.elapsed_ms,
      },
      compose: {
        project,
        base_file: path.basename(baseFile),
        override_file: path.basename(overrideFile),
        network: `${project}_default`,
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
    composeDown(partialState, true);
    fs.rmSync(overrideFile, { force: true });
    throw error;
  }
}

function stop() {
  const state = validateState(JSON.parse(fs.readFileSync(statePath, 'utf8')));
  const downAttempts = [composeDown(state, true)];
  const attachedCellContainers = networkContainerIds(state.compose.network);
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
    attached_containers: networkContainerIds(state.compose.network),
  };
  const failures = [...attachedCellRemovalFailures];
  for (const [kind, values] of Object.entries(remaining)) {
    if (values.length > 0) failures.push(`${kind} remain: ${values.join(', ')}`);
  }
  state.lifecycle.cleanup_status = failures.length === 0 ? 'pass' : 'fail';
  state.lifecycle.cleanup_finished_at = now();
  state.lifecycle.cleanup_failures = failures;
  state.lifecycle.cleanup_resources_remaining = remaining;
  state.lifecycle.compose_down_exit_code = downAttempts.at(-1)?.status ?? null;
  state.lifecycle.compose_down_exit_codes = downAttempts.map((attempt) => attempt.status);
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
