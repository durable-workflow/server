import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import process from 'node:process';
import { pathToFileURL } from 'node:url';

const MAXIMUM_REQUEST_BYTES = 1024 * 1024;
const MAXIMUM_RESPONSE_BYTES = 16 * 1024 * 1024;
const REQUEST_TIMEOUT_MS = 30_000;
const RELAY_LAUNCHER_NAME = 'heartbeat-relay-launcher';
const RELAY_LAUNCHER_SCRIPT = `set -eu
pid_file="\${DW_HEARTBEAT_RELAY_PID_FILE:?}"
pid_directory=\${pid_file%/*}
temporary="\${pid_file}.launcher-\$\$"
umask 077
mkdir -p "\${pid_directory}"
printf '%s\\n' "\$\$" > "\${temporary}"
mv "\${temporary}" "\${pid_file}"
exec "\$1" "\$2" serve "\$3"
`;
const EXCLUDED_HEADERS = new Set([
  'connection',
  'content-length',
  'host',
  'keep-alive',
  'proxy-authenticate',
  'proxy-authorization',
  'te',
  'trailer',
  'transfer-encoding',
  'upgrade',
]);

function relayError(error) {
  const message = error instanceof Error ? error.message : String(error);
  return message.replace(/(authorization:\s*bearer\s+)\S+/gi, '$1[REDACTED]');
}

function sleepSync(milliseconds) {
  Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, milliseconds);
}

function processExists(pid) {
  try {
    process.kill(pid, 0);
    const stat = fs.readFileSync(`/proc/${pid}/stat`, 'utf8');
    const commandEnd = stat.lastIndexOf(')');
    if (commandEnd >= 0 && stat.slice(commandEnd + 2, commandEnd + 3) === 'Z') {
      return false;
    }
    return true;
  } catch {
    return false;
  }
}

export function readHeartbeatRelayPid(pidFile) {
  try {
    const value = fs.readFileSync(pidFile, 'utf8').trim();
    if (!/^[1-9]\d*$/.test(value)) return null;
    const pid = Number.parseInt(value, 10);
    return Number.isSafeInteger(pid) ? pid : null;
  } catch {
    return null;
  }
}

export function heartbeatRelayProcessMatches(pid, ownershipToken) {
  if (!Number.isInteger(pid) || pid < 1 || !ownershipToken) return false;
  try {
    const argumentsList = fs.readFileSync(`/proc/${pid}/cmdline`)
      .toString('utf8')
      .split('\0')
      .filter(Boolean);
    const relayScriptPresent = argumentsList.some(
      (argument) => argument.endsWith('/heartbeat-shared-relay.mjs'),
    );
    const relayProcess = relayScriptPresent
      && argumentsList.includes('serve')
      && argumentsList.includes(ownershipToken);
    const launcherProcess = relayScriptPresent
      && argumentsList.includes(RELAY_LAUNCHER_NAME)
      && argumentsList.includes(ownershipToken);
    return relayProcess || launcherProcess;
  } catch {
    return false;
  }
}

export function heartbeatRelayLauncherArguments({
  nodeBinary,
  relayScript,
  ownershipToken,
}) {
  if (!path.isAbsolute(nodeBinary)) {
    throw new Error('heartbeat relay Node binary must be an absolute path');
  }
  if (!path.isAbsolute(relayScript)) {
    throw new Error('heartbeat relay script must be an absolute path');
  }
  if (!ownershipToken) {
    throw new Error('heartbeat relay ownership token is required');
  }
  return [
    '/bin/sh',
    '-c',
    RELAY_LAUNCHER_SCRIPT,
    RELAY_LAUNCHER_NAME,
    nodeBinary,
    relayScript,
    ownershipToken,
  ];
}

function removeOwnedPidFile(pidFile, pid) {
  if (readHeartbeatRelayPid(pidFile) === pid) {
    fs.rmSync(pidFile, { force: true });
  }
}

export function stopHeartbeatRelay({ pid, ownershipToken, pidFile }) {
  if (!Number.isInteger(pid) || pid < 1) {
    return { status: 'not_started', signal: null };
  }
  if (!processExists(pid)) {
    removeOwnedPidFile(pidFile, pid);
    return { status: 'already_stopped', signal: null };
  }
  if (readHeartbeatRelayPid(pidFile) !== pid) {
    return {
      status: 'failed',
      signal: null,
      error: 'the relay PID file does not match the recorded process',
    };
  }
  if (!heartbeatRelayProcessMatches(pid, ownershipToken)) {
    return {
      status: 'failed',
      signal: null,
      error: `refusing to signal unverified relay process ${pid}`,
    };
  }

  process.kill(pid, 'SIGTERM');
  for (let attempt = 0; attempt < 50; attempt += 1) {
    if (!processExists(pid)) {
      removeOwnedPidFile(pidFile, pid);
      return { status: 'stopped', signal: 'SIGTERM' };
    }
    sleepSync(50);
  }
  if (!heartbeatRelayProcessMatches(pid, ownershipToken)) {
    return {
      status: 'failed',
      signal: null,
      error: `refusing to force-stop unverified relay process ${pid}`,
    };
  }
  process.kill(pid, 'SIGKILL');
  for (let attempt = 0; attempt < 20; attempt += 1) {
    if (!processExists(pid)) {
      removeOwnedPidFile(pidFile, pid);
      return { status: 'stopped', signal: 'SIGKILL' };
    }
    sleepSync(50);
  }
  return {
    status: 'failed',
    signal: 'SIGKILL',
    error: `relay process ${pid} remained after SIGKILL`,
  };
}

function writeRelayPid(pidFile) {
  fs.mkdirSync(path.dirname(pidFile), { recursive: true });
  const temporary = `${pidFile}.tmp-${process.pid}`;
  fs.writeFileSync(temporary, `${process.pid}\n`, { encoding: 'utf8', mode: 0o600 });
  fs.renameSync(temporary, pidFile);
}

async function requestBody(request) {
  const chunks = [];
  let bytes = 0;
  for await (const chunk of request) {
    bytes += chunk.length;
    if (bytes > MAXIMUM_REQUEST_BYTES) {
      throw new Error(`relay request exceeded ${MAXIMUM_REQUEST_BYTES} bytes`);
    }
    chunks.push(chunk);
  }
  return Buffer.concat(chunks);
}

async function responseBody(response) {
  const chunks = [];
  let bytes = 0;
  for await (const chunk of response.body ?? []) {
    const buffer = Buffer.from(chunk);
    bytes += buffer.length;
    if (bytes > MAXIMUM_RESPONSE_BYTES) {
      throw new Error(`relay response exceeded ${MAXIMUM_RESPONSE_BYTES} bytes`);
    }
    chunks.push(buffer);
  }
  return Buffer.concat(chunks);
}

export async function directRelayRequest({
  targetUrl,
  method,
  headers,
  body,
  fetchImpl = globalThis.fetch,
}) {
  if (typeof fetchImpl !== 'function') throw new Error('fetchImpl must be a function');
  const forwardedHeaders = new Headers();
  for (const [name, value] of Object.entries(headers)) {
    if (EXCLUDED_HEADERS.has(name) || value === undefined) continue;
    const values = Array.isArray(value) ? value : [value];
    for (const entry of values) forwardedHeaders.append(name, String(entry));
  }

  const response = await fetchImpl(targetUrl, {
    method,
    headers: forwardedHeaders,
    body: body.length > 0 || !['GET', 'HEAD'].includes(method) ? body : undefined,
    redirect: 'manual',
    signal: AbortSignal.timeout(REQUEST_TIMEOUT_MS),
  });
  return {
    status: response.status,
    contentType: response.headers.get('content-type') ?? '',
    body: await responseBody(response),
  };
}

export function createHeartbeatRelayServer({
  targetOrigin = 'http://server:8080',
  executeRequest = directRelayRequest,
  onDiagnostic = () => {},
}) {
  const target = new URL(targetOrigin);
  if (target.protocol !== 'http:' || target.pathname !== '/') {
    throw new Error('heartbeat relay target must be an HTTP origin');
  }

  return http.createServer(async (request, response) => {
    try {
      const body = await requestBody(request);
      const requestedUrl = new URL(request.url ?? '/', 'http://heartbeat-relay.invalid');
      const targetUrl = new URL(`${requestedUrl.pathname}${requestedUrl.search}`, target);
      const forwarded = await executeRequest({
        targetUrl: targetUrl.toString(),
        method: request.method ?? 'GET',
        headers: request.headers,
        body,
      });
      response.statusCode = forwarded.status;
      if (forwarded.contentType) response.setHeader('Content-Type', forwarded.contentType);
      response.setHeader('Connection', 'close');
      response.end(forwarded.body);
    } catch (error) {
      const diagnostic = relayError(error);
      onDiagnostic(diagnostic);
      response.statusCode = /timeout|timed out/i.test(diagnostic) ? 504 : 502;
      response.setHeader('Content-Type', 'application/json');
      response.setHeader('Connection', 'close');
      response.end(`${JSON.stringify({
        error: 'heartbeat executor-network relay failed',
        diagnostic,
      })}\n`);
    }
  });
}

async function serve(ownershipToken) {
  const port = Number.parseInt(process.env.DW_HEARTBEAT_RELAY_PORT ?? '', 10);
  const startupDelayMs = Number.parseInt(
    process.env.DW_HEARTBEAT_RELAY_STARTUP_DELAY_MS ?? '0',
    10,
  );
  const targetOrigin = String(
    process.env.DW_HEARTBEAT_RELAY_TARGET_ORIGIN ?? 'http://server:8080',
  ).trim();
  const pidFile = String(process.env.DW_HEARTBEAT_RELAY_PID_FILE ?? '').trim();
  const logFile = String(process.env.DW_HEARTBEAT_RELAY_LOG_FILE ?? '').trim();
  if (!Number.isInteger(port) || port < 1 || port > 65_535) {
    throw new Error('DW_HEARTBEAT_RELAY_PORT is required');
  }
  if (!Number.isInteger(startupDelayMs) || startupDelayMs < 0 || startupDelayMs > 300_000) {
    throw new Error('DW_HEARTBEAT_RELAY_STARTUP_DELAY_MS must be between 0 and 300000');
  }
  if (!ownershipToken) throw new Error('heartbeat relay ownership token is required');
  if (!path.isAbsolute(pidFile)) {
    throw new Error('DW_HEARTBEAT_RELAY_PID_FILE must be an absolute path');
  }
  if (logFile && !path.isAbsolute(logFile)) {
    throw new Error('DW_HEARTBEAT_RELAY_LOG_FILE must be an absolute path');
  }
  const log = (message) => {
    if (!logFile) return;
    fs.appendFileSync(logFile, `${message}\n`, { encoding: 'utf8', mode: 0o600 });
  };

  const server = createHeartbeatRelayServer({
    targetOrigin,
    onDiagnostic: (diagnostic) => {
      log(`[${new Date().toISOString()}] ${diagnostic}`);
    },
  });
  let stopping = false;
  const finish = (exitCode) => {
    removeOwnedPidFile(pidFile, process.pid);
    process.exit(exitCode);
  };
  const stop = () => {
    if (stopping) return;
    stopping = true;
    if (server.listening) {
      server.close(() => finish(0));
    } else {
      finish(0);
    }
    setTimeout(() => finish(1), 5_000).unref();
  };
  process.on('SIGINT', stop);
  process.on('SIGTERM', stop);
  writeRelayPid(pidFile);
  try {
    if (startupDelayMs > 0) {
      await new Promise((resolve) => setTimeout(resolve, startupDelayMs));
    }
    await new Promise((resolve, reject) => {
      server.once('error', reject);
      server.listen(port, '127.0.0.1', resolve);
    });
    log(JSON.stringify({
      status: 'ready',
      host_url: `http://127.0.0.1:${port}`,
      target_origin: targetOrigin,
    }));
  } catch (error) {
    removeOwnedPidFile(pidFile, process.pid);
    throw error;
  }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  const action = process.argv[2] ?? '';
  const ownershipToken = process.argv[3] ?? '';
  if (action === 'serve') {
    await serve(ownershipToken);
  } else if (action === 'stop') {
    const pidFile = String(process.env.DW_HEARTBEAT_RELAY_PID_FILE ?? '').trim();
    const result = stopHeartbeatRelay({
      pid: readHeartbeatRelayPid(pidFile),
      ownershipToken,
      pidFile,
    });
    process.stdout.write(`${JSON.stringify(result)}\n`);
    if (!['stopped', 'already_stopped', 'not_started'].includes(result.status)) {
      process.exitCode = 1;
    }
  } else {
    process.stderr.write('usage: heartbeat-shared-relay.mjs <serve|stop> <ownership-token>\n');
    process.exitCode = 2;
  }
}
