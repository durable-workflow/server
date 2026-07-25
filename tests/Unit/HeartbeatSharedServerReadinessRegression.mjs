import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { once } from 'node:events';
import fs from 'node:fs';
import net from 'node:net';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath, pathToFileURL } from 'node:url';

import {
  createHeartbeatRelayServer,
  directRelayRequest,
  heartbeatRelayLauncherArguments,
  heartbeatRelayProcessMatches,
  readHeartbeatRelayPid,
  stopHeartbeatRelay,
} from '../../scripts/conformance/heartbeat-shared-relay.mjs';
import {
  HeartbeatReadinessTimeoutError,
  classifySharedServerStartup,
  parsePublishedPortBindings,
  selectLoopbackPublishedEndpoint,
  waitForAuthenticatedReadiness,
} from '../../scripts/conformance/heartbeat-shared-readiness.mjs';

function healthyContainer(port = 48123) {
  return {
    State: {
      Status: 'running',
      Running: true,
      Health: { Status: 'healthy' },
    },
    NetworkSettings: {
      Ports: {
        '8080/tcp': [
          { HostIp: '0.0.0.0', HostPort: String(port) },
          { HostIp: '::', HostPort: String(port) },
        ],
      },
    },
  };
}

async function freePort() {
  const server = net.createServer();
  await new Promise((resolve, reject) => {
    server.once('error', reject);
    server.listen(0, '127.0.0.1', resolve);
  });
  const address = server.address();
  assert.equal(typeof address, 'object');
  await new Promise((resolve) => server.close(resolve));
  return address.port;
}

async function socketIsOpen(port) {
  return new Promise((resolve) => {
    const socket = net.createConnection({ host: '127.0.0.1', port });
    const finish = (result) => {
      socket.destroy();
      resolve(result);
    };
    socket.setTimeout(200, () => finish(false));
    socket.once('connect', () => finish(true));
    socket.once('error', () => finish(false));
  });
}

async function waitFor(predicate, description, timeoutMs = 5_000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    if (await predicate()) return;
    await new Promise((resolve) => setTimeout(resolve, 25));
  }
  assert.fail(`timed out waiting for ${description}`);
}

function effectiveProcessIdentity(pid) {
  const status = fs.readFileSync(`/proc/${pid}/status`, 'utf8');
  const effectiveId = (name) => {
    const match = status.match(new RegExp(`^${name}:\\s+\\d+\\s+(\\d+)`, 'm'));
    assert.ok(match, `${name} must be present in process status`);
    return Number.parseInt(match[1], 10);
  };
  return {
    uid: effectiveId('Uid'),
    gid: effectiveId('Gid'),
  };
}

test('authenticated readiness retries a refused host connection and then succeeds', async () => {
  let monotonicMilliseconds = 0;
  const requests = [];

  const readiness = await waitForAuthenticatedReadiness({
    url: new URL('http://127.0.0.1:48123/api/ready'),
    token: 'control-token',
    timeoutMs: 100,
    attemptTimeoutMs: 20,
    retryIntervalMs: 10,
    monotonicNow: () => monotonicMilliseconds,
    sleep: async (milliseconds) => {
      monotonicMilliseconds += milliseconds;
    },
    fetchImpl: async (url, options) => {
      requests.push({ url: String(url), options });
      if (requests.length === 1) {
        throw Object.assign(new Error('connect ECONNREFUSED 127.0.0.1:48123'), {
          code: 'ECONNREFUSED',
        });
      }
      return { ok: true, status: 200, statusText: 'OK' };
    },
  });

  assert.equal(readiness.status, 200);
  assert.equal(readiness.attempts, 2);
  assert.equal(readiness.elapsed_ms, 10);
  assert.equal(requests.length, 2);
  assert.equal(requests[0].url, 'http://127.0.0.1:48123/api/ready');
  for (const request of requests) {
    assert.equal(request.options.headers.Accept, 'application/json');
    assert.equal(request.options.headers.Authorization, 'Bearer control-token');
    assert.equal(request.options.signal instanceof AbortSignal, true);
  }
});

test('authenticated readiness stops at its monotonic deadline with the final response', async () => {
  let monotonicMilliseconds = 0;
  let attempts = 0;

  await assert.rejects(
    waitForAuthenticatedReadiness({
      url: new URL('http://127.0.0.1:48124/api/ready'),
      token: 'control-token',
      timeoutMs: 90,
      attemptTimeoutMs: 20,
      retryIntervalMs: 30,
      monotonicNow: () => monotonicMilliseconds,
      sleep: async (milliseconds) => {
        monotonicMilliseconds += milliseconds;
      },
      fetchImpl: async () => {
        attempts += 1;
        return { ok: false, status: 503, statusText: 'Service Unavailable' };
      },
    }),
    (error) => {
      assert.equal(error instanceof HeartbeatReadinessTimeoutError, true);
      assert.deepEqual(error.readiness, {
        timeout_ms: 90,
        attempts: 3,
        elapsed_ms: 90,
        final_status: 503,
        final_error: 'HTTP 503 Service Unavailable',
      });
      assert.match(error.message, /final status=503/);
      assert.match(error.message, /final error=HTTP 503 Service Unavailable/);
      return true;
    },
  );

  assert.equal(attempts, 3);
  assert.equal(monotonicMilliseconds, 90);
});

test('authenticated readiness bounds repeated host connection refusal', async () => {
  let monotonicMilliseconds = 0;
  let attempts = 0;

  await assert.rejects(
    waitForAuthenticatedReadiness({
      url: new URL('http://127.0.0.1:48125/api/ready'),
      token: 'control-token',
      timeoutMs: 75,
      attemptTimeoutMs: 20,
      retryIntervalMs: 25,
      monotonicNow: () => monotonicMilliseconds,
      sleep: async (milliseconds) => {
        monotonicMilliseconds += milliseconds;
      },
      fetchImpl: async () => {
        attempts += 1;
        throw Object.assign(new Error('connect ECONNREFUSED 127.0.0.1:48125'), {
          code: 'ECONNREFUSED',
        });
      },
    }),
    (error) => {
      assert.equal(error instanceof HeartbeatReadinessTimeoutError, true);
      assert.deepEqual(error.readiness, {
        timeout_ms: 75,
        attempts: 3,
        elapsed_ms: 75,
        final_status: null,
        final_error: 'Error [ECONNREFUSED]: connect ECONNREFUSED 127.0.0.1:48125',
      });
      assert.match(error.message, /final status=none/);
      assert.match(error.message, /ECONNREFUSED/);
      return true;
    },
  );

  assert.equal(attempts, 3);
  assert.equal(monotonicMilliseconds, 75);
});

test('published port evidence selects the real loopback endpoint', () => {
  const output = '0.0.0.0:48123\n[::]:48123\n';

  assert.deepEqual(parsePublishedPortBindings(output), [
    { host: '0.0.0.0', port: 48123 },
    { host: '::', port: 48123 },
  ]);
  assert.deepEqual(selectLoopbackPublishedEndpoint(output, 48123), {
    host_url: 'http://127.0.0.1:48123',
    port: 48123,
    bindings: [
      { host: '0.0.0.0', port: 48123 },
      { host: '::', port: 48123 },
    ],
  });
  assert.throws(
    () => selectLoopbackPublishedEndpoint('', 48123),
    /did not report a published server port/,
  );
  assert.throws(
    () => selectLoopbackPublishedEndpoint('0.0.0.0:48124', 48123),
    /instead of requested port 48123/,
  );
});

test('executor-network relay preserves authenticated requests and backend status', async (context) => {
  const requests = [];
  const relay = createHeartbeatRelayServer({
    executeRequest: async (request) => {
      requests.push(request);
      return {
        status: 409,
        contentType: 'application/json',
        body: Buffer.from('{"status":"already_exists"}'),
      };
    },
  });
  context.after(() => new Promise((resolve) => relay.close(resolve)));
  await new Promise((resolve, reject) => {
    relay.once('error', reject);
    relay.listen(0, '127.0.0.1', resolve);
  });
  const address = relay.address();
  assert.equal(typeof address, 'object');

  const response = await fetch(
    `http://127.0.0.1:${address.port}/api/namespaces?source=heartbeat`,
    {
      method: 'POST',
      headers: {
        Authorization: 'Bearer control-token',
        'Content-Type': 'application/json',
        'X-Namespace': 'hb-wave-php',
      },
      body: '{"name":"hb-wave-php"}',
    },
  );

  assert.equal(response.status, 409);
  assert.equal(response.headers.get('content-type'), 'application/json');
  assert.deepEqual(await response.json(), { status: 'already_exists' });
  assert.equal(requests.length, 1);
  assert.equal(
    requests[0].targetUrl,
    'http://server:8080/api/namespaces?source=heartbeat',
  );
  assert.equal(requests[0].headers.authorization, 'Bearer control-token');
  assert.equal(requests[0].headers['x-namespace'], 'hb-wave-php');
  assert.equal(requests[0].body.toString(), '{"name":"hb-wave-php"}');
});

test('executor-network relay keeps credentials and request bytes in memory', async () => {
  const requests = [];
  const result = await directRelayRequest({
    targetUrl: 'http://server:8080/api/ready',
    method: 'POST',
    headers: {
      authorization: 'Bearer control-token',
      connection: 'keep-alive',
      'x-namespace': 'hb-wave-php',
    },
    body: Buffer.from('{"probe":true}'),
    fetchImpl: async (url, options) => {
      requests.push({ url, options });
      return new Response('{"ready":true}', {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      });
    },
  });

  assert.equal(result.status, 200);
  assert.equal(result.contentType, 'application/json');
  assert.equal(result.body.toString(), '{"ready":true}');
  assert.equal(requests.length, 1);
  assert.equal(requests[0].url, 'http://server:8080/api/ready');
  assert.equal(requests[0].options.method, 'POST');
  assert.equal(
    requests[0].options.headers.get('authorization'),
    'Bearer control-token',
  );
  assert.equal(requests[0].options.headers.get('x-namespace'), 'hb-wave-php');
  assert.equal(requests[0].options.headers.has('connection'), false);
  assert.equal(requests[0].options.body.toString(), '{"probe":true}');
});

test('executor-network relay reports a bounded gateway failure', async (context) => {
  const diagnostics = [];
  const relay = createHeartbeatRelayServer({
    executeRequest: async () => {
      throw new Error('direct fetch failed with Authorization: Bearer control-token');
    },
    onDiagnostic: (diagnostic) => diagnostics.push(diagnostic),
  });
  context.after(() => new Promise((resolve) => relay.close(resolve)));
  await new Promise((resolve, reject) => {
    relay.once('error', reject);
    relay.listen(0, '127.0.0.1', resolve);
  });
  const address = relay.address();
  assert.equal(typeof address, 'object');

  const response = await fetch(`http://127.0.0.1:${address.port}/api/ready`);
  assert.equal(response.status, 502);
  assert.deepEqual(await response.json(), {
    error: 'heartbeat executor-network relay failed',
    diagnostic: 'direct fetch failed with Authorization: Bearer [REDACTED]',
  });
  assert.deepEqual(
    diagnostics,
    ['direct fetch failed with Authorization: Bearer [REDACTED]'],
  );
});

test('detached relay keeps cleanup user identity and stops from a separate process', async (context) => {
  const sandbox = fs.mkdtempSync(path.join(os.tmpdir(), 'dw-heartbeat-relay-'));
  const relayScript = fileURLToPath(new URL(
    '../../scripts/conformance/heartbeat-shared-relay.mjs',
    import.meta.url,
  ));
  const pidFile = path.join(sandbox, 'relay.pid');
  const logFile = path.join(sandbox, 'relay.log');
  const startRequestFile = path.join(sandbox, 'start-request');
  const ownershipToken = `dw-hb-wave-${path.basename(sandbox).slice(-12)}`;
  const port = await freePort();
  let relayPid = null;
  const daemonOwner = spawn(process.execPath, [
    '--input-type=module',
    '--eval',
    [
      "import { spawn } from 'node:child_process';",
      "import fs from 'node:fs';",
      'const timer = setInterval(() => {',
      '  if (!fs.existsSync(process.env.RELAY_START_REQUEST_FILE)) return;',
      '  clearInterval(timer);',
      '  const relay = spawn(process.execPath, [',
      "    process.env.RELAY_SCRIPT, 'serve', process.env.RELAY_OWNERSHIP_TOKEN,",
      '  ], {',
      "    stdio: 'ignore',",
      '    env: process.env,',
      '  });',
      '  relay.once("exit", () => process.exit(0));',
      '}, 10);',
    ].join('\n'),
  ], {
    env: {
      ...process.env,
      RELAY_SCRIPT: relayScript,
      RELAY_OWNERSHIP_TOKEN: ownershipToken,
      RELAY_START_REQUEST_FILE: startRequestFile,
      DW_HEARTBEAT_RELAY_PORT: String(port),
      DW_HEARTBEAT_RELAY_TARGET_ORIGIN: 'http://server:8080',
      DW_HEARTBEAT_RELAY_PID_FILE: pidFile,
      DW_HEARTBEAT_RELAY_LOG_FILE: logFile,
    },
    stdio: 'ignore',
  });
  const daemonOwnerExit = once(daemonOwner, 'exit');
  context.after(() => {
    if (relayPid && heartbeatRelayProcessMatches(relayPid, ownershipToken)) {
      process.kill(relayPid, 'SIGKILL');
    }
    if (daemonOwner.exitCode === null) daemonOwner.kill('SIGKILL');
    fs.rmSync(sandbox, { recursive: true, force: true });
  });

  const starter = spawnSync(process.execPath, [
    '--input-type=module',
    '--eval',
    [
      "import fs from 'node:fs';",
      "fs.writeFileSync(process.env.RELAY_START_REQUEST_FILE, 'start\\n');",
    ].join('\n'),
  ], {
    env: {
      ...process.env,
      RELAY_START_REQUEST_FILE: startRequestFile,
    },
    encoding: 'utf8',
    timeout: 5_000,
  });
  assert.equal(starter.status, 0, starter.stderr || starter.stdout);

  await waitFor(() => {
    relayPid = readHeartbeatRelayPid(pidFile);
    return relayPid !== null
      && heartbeatRelayProcessMatches(relayPid, ownershipToken);
  }, 'the detached relay PID');
  await waitFor(() => socketIsOpen(port), 'the detached relay socket');
  assert.deepEqual(effectiveProcessIdentity(relayPid), {
    uid: process.getuid(),
    gid: process.getgid(),
  });

  const stopper = spawnSync(process.execPath, [
    '--input-type=module',
    '--eval',
    [
      'const { readHeartbeatRelayPid, stopHeartbeatRelay } = '
        + 'await import(process.env.RELAY_SCRIPT_URL);',
      'const pidFile = process.env.DW_HEARTBEAT_RELAY_PID_FILE;',
      'const result = stopHeartbeatRelay({',
      '  pid: readHeartbeatRelayPid(pidFile),',
      '  ownershipToken: process.env.RELAY_OWNERSHIP_TOKEN,',
      '  pidFile,',
      '});',
      'process.stdout.write(`${JSON.stringify({',
      '  result,',
      '  uid: process.getuid(),',
      '  gid: process.getgid(),',
      '})}\\n`);',
      "if (!['stopped', 'already_stopped'].includes(result.status)) process.exitCode = 1;",
    ].join('\n'),
  ], {
    env: {
      ...process.env,
      RELAY_SCRIPT_URL: pathToFileURL(relayScript).href,
      RELAY_OWNERSHIP_TOKEN: ownershipToken,
      DW_HEARTBEAT_RELAY_PID_FILE: pidFile,
    },
    encoding: 'utf8',
    timeout: 10_000,
  });
  assert.equal(stopper.status, 0, stopper.stderr || stopper.stdout);
  assert.deepEqual(JSON.parse(stopper.stdout), {
    result: {
      status: 'stopped',
      signal: 'SIGTERM',
    },
    uid: process.getuid(),
    gid: process.getgid(),
  });

  await daemonOwnerExit;
  await waitFor(
    () => !fs.existsSync(`/proc/${relayPid}`),
    'the stopped relay PID to disappear',
  );
  await waitFor(async () => !(await socketIsOpen(port)), 'the stopped relay socket to close');
  assert.equal(fs.existsSync(pidFile), false);
});

test('relay cleanup cancels delayed initialization before its socket can bind', async (context) => {
  const sandbox = fs.mkdtempSync(path.join(os.tmpdir(), 'dw-heartbeat-relay-delay-'));
  const relayScript = fileURLToPath(new URL(
    '../../scripts/conformance/heartbeat-shared-relay.mjs',
    import.meta.url,
  ));
  const pidFile = path.join(sandbox, 'relay.pid');
  const logFile = path.join(sandbox, 'relay.log');
  const ownershipToken = `dw-hb-wave-${path.basename(sandbox).slice(-12)}`;
  const port = await freePort();
  const [launcher, ...launcherArguments] = heartbeatRelayLauncherArguments({
    nodeBinary: process.execPath,
    relayScript,
    ownershipToken,
  });
  let relayPid = null;
  const relay = spawn(launcher, launcherArguments, {
    env: {
      ...process.env,
      DW_HEARTBEAT_RELAY_PORT: String(port),
      DW_HEARTBEAT_RELAY_TARGET_ORIGIN: 'http://server:8080',
      DW_HEARTBEAT_RELAY_PID_FILE: pidFile,
      DW_HEARTBEAT_RELAY_LOG_FILE: logFile,
      DW_HEARTBEAT_RELAY_STARTUP_DELAY_MS: '1000',
    },
    stdio: 'ignore',
  });
  const relayExit = once(relay, 'exit');
  context.after(() => {
    if (relayPid && heartbeatRelayProcessMatches(relayPid, ownershipToken)) {
      process.kill(relayPid, 'SIGKILL');
    }
    fs.rmSync(sandbox, { recursive: true, force: true });
  });

  await waitFor(() => {
    relayPid = readHeartbeatRelayPid(pidFile);
    return relayPid !== null
      && heartbeatRelayProcessMatches(relayPid, ownershipToken);
  }, 'the pre-initialization relay ownership handle');
  await new Promise((resolve) => setTimeout(resolve, 150));
  assert.equal(await socketIsOpen(port), false);

  const stopper = spawnSync(process.execPath, [
    relayScript,
    'stop',
    ownershipToken,
  ], {
    env: {
      ...process.env,
      DW_HEARTBEAT_RELAY_PID_FILE: pidFile,
    },
    encoding: 'utf8',
    timeout: 10_000,
  });
  assert.equal(stopper.status, 0, stopper.stderr || stopper.stdout);
  assert.equal(JSON.parse(stopper.stdout).status, 'stopped');
  await relayExit;

  await new Promise((resolve) => setTimeout(resolve, 1000));
  assert.equal(fs.existsSync(`/proc/${relayPid}`), false);
  assert.equal(fs.existsSync(pidFile), false);
  assert.equal(await socketIsOpen(port), false);
  assert.deepEqual(stopHeartbeatRelay({
    pid: relayPid,
    ownershipToken,
    pidFile,
  }), {
    status: 'already_stopped',
    signal: null,
  });
});

test('startup diagnosis distinguishes container, port publication, and slow host bind', () => {
  const composePort = { status: 0, stdout: '0.0.0.0:48123\n[::]:48123\n' };

  assert.deepEqual(classifySharedServerStartup({
    container: {
      ...healthyContainer(),
      State: { Status: 'exited', Running: false, ExitCode: 1 },
    },
    composePort,
    expectedPort: 48123,
  }), {
    kind: 'container_failure',
    reason: 'server container state is exited',
  });

  assert.deepEqual(classifySharedServerStartup({
    container: healthyContainer(),
    composePort: { status: 0, stdout: '' },
    expectedPort: 48123,
  }), {
    kind: 'published_port_failure',
    reason: 'Compose reported no published server port',
  });

  assert.deepEqual(classifySharedServerStartup({
    container: healthyContainer(),
    composePort,
    expectedPort: 48123,
    readiness: {
      final_status: null,
      final_error: 'TypeError: fetch failed <- Error [ECONNREFUSED]: connect refused',
    },
  }), {
    kind: 'host_bind_timeout',
    reason: 'container health and published-port metadata passed but the host endpoint never bound',
  });

  assert.deepEqual(classifySharedServerStartup({
    container: healthyContainer(),
    composePort,
    expectedPort: 48123,
    readiness: {
      final_status: 503,
      final_error: 'HTTP 503 Service Unavailable',
    },
  }), {
    kind: 'readiness_response_failure',
    reason: 'host endpoint last returned HTTP 503',
  });

  assert.deepEqual(classifySharedServerStartup({
    container: healthyContainer(),
    composePort,
    expectedPort: 48123,
    readinessTransport: 'executor_compatibility_relay',
    readiness: {
      final_status: 502,
      final_error: 'HTTP 502 Bad Gateway',
    },
  }), {
    kind: 'relay_target_failure',
    reason: 'the executor-network relay could not reach the server service endpoint',
  });

  assert.deepEqual(classifySharedServerStartup({
    container: healthyContainer(),
    composePort,
    expectedPort: 48123,
    readinessTransport: 'executor_compatibility_relay',
    readiness: {
      final_status: null,
      final_error: 'Error [ECONNREFUSED]: connect refused',
    },
  }), {
    kind: 'relay_bind_timeout',
    reason: 'the workspace relay did not bind before its bounded readiness deadline',
  });

  assert.deepEqual(classifySharedServerStartup({
    container: healthyContainer(),
    composePort,
    expectedPort: 48123,
    readinessTransport: 'executor_network_attachment',
    readiness: {
      final_status: null,
      final_error: 'Error [ECONNREFUSED]: connect refused',
    },
  }), {
    kind: 'executor_network_failure',
    reason: 'the attached executor could not reach the server service endpoint',
  });
});
