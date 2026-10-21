// Isolated test observer: forward original HTTP bytes, hold one actual poll
// response when requested, and retain only bounded authority metadata.
import http from 'node:http';
import {appendFileSync} from 'node:fs';
import {createHash} from 'node:crypto';
import {gunzipSync, inflateSync, brotliDecompressSync} from 'node:zlib';
import {proxyTargetUrl} from '../../proxy-target.mjs';

const [target, mode, journal] = process.argv.slice(2);
if (!['timely', 'late-poll', 'late-handler-async', 'late-handler-sync'].includes(mode)) throw Error('Unknown case');
const upstream = new URL(target);
if (upstream.protocol !== 'http:') throw Error('Use only an isolated HTTP target');
let count = 0, retainedBytes = 0, held = false;
const authorityFields = ['query_task_id', 'query_task_attempt', 'lease_owner', 'task_queue', 'namespace',
  'workflow_id', 'run_id', 'query_admitted_at', 'query_deadline_at', 'lease_expires_at', 'server_time', 'can_continue', 'reason'];
const select = value => Object.fromEntries(authorityFields.filter(key => key in value).map(key => [key, value[key]]));
function record(event, details = {}) {
  const line = JSON.stringify({event, observed_at: Date.now() / 1000, ...details}) + '\n';
  if (++count > 256 || (retainedBytes += Buffer.byteLength(line)) > 256 * 1024) throw Error('Observer limit exceeded');
  appendFileSync(journal, line);
}
function exchange(method, path, headers, body = Buffer.alloc(0)) {
  return new Promise((resolve, reject) => {
    const started = Date.now() / 1000;
    let response, size = 0, timedOut = false, settled = false;
    const fail = error => {
      if (settled) return;
      settled = true;
      error.observerExchange = {
        phase: response ? 'response' : 'request', started_at: started, failed_at: Date.now() / 1000,
        headers_received: response !== undefined, status: response?.statusCode ?? null, received_bytes: size,
        response_complete: response?.complete ?? null, response_aborted: response?.aborted ?? null,
        timed_out: timedOut, error_name: String(error.name ?? '').slice(0, 64),
        error_code: typeof error.code === 'string' ? error.code.slice(0, 64) : null,
      };
      reject(error);
    };
    const request = http.request(proxyTargetUrl(path, upstream), {method, headers: {...headers, host: upstream.host}}, incoming => {
      response = incoming;
      const chunks = [];
      incoming.on('data', chunk => {
        size += chunk.length;
        if (size > 2 * 1024 * 1024) incoming.destroy(Error('Observer response limit exceeded'));
        else chunks.push(chunk);
      });
      incoming.on('error', fail);
      incoming.on('end', () => {
        settled = true;
        resolve({status: incoming.statusCode, headers: incoming.headers, bytes: Buffer.concat(chunks)});
      });
    });
    request.setTimeout(35_000, () => {
      timedOut = true;
      request.destroy(Error('Observer exchange timeout'));
    });
    request.on('error', fail);
    request.end(body);
  });
}
function json(reply) {
  const options = {maxOutputLength: 2 * 1024 * 1024};
  const decoders = {identity: value => value, gzip: value => gunzipSync(value, options),
    deflate: value => inflateSync(value, options), br: value => brotliDecompressSync(value, options)};
  const decoder = decoders[reply.headers['content-encoding'] ?? 'identity'];
  if (!decoder) throw Error('Unknown observed encoding');
  return reply.bytes.length ? JSON.parse(decoder(reply.bytes).toString('utf8')) : {};
}
const sha = bytes => createHash('sha256').update(bytes).digest('hex');
const server = http.createServer(async (request, response) => {
  const path = proxyTargetUrl(request.url, upstream).pathname;
  let upstreamStarted = false;
  const forward = (...arguments_) => {
    upstreamStarted = true;
    return exchange(...arguments_);
  };
  try {
    const chunks = [];
    let size = 0;
    for await (const chunk of request) {
      if ((size += chunk.length) > 128 * 1024) throw Error('Observer request limit exceeded');
      chunks.push(chunk);
    }
    const body = Buffer.concat(chunks);
    const headers = {...request.headers};
    const terminal = request.method === 'POST' && /\/query-tasks\/[^/]+\/(complete|fail)$/.test(path);
    if (terminal) record('submission', {path});
    const query = request.method === 'POST' && /^\/api\/workflows\/[^/]+\/(?:runs\/[^/]+\/)?query\//.test(path);
    let historyPath;
    let before;
    if (query) {
      const workflowPath = path.replace(/\/query\/[^/]+$/, '');
      if (workflowPath.includes('/runs/')) historyPath = workflowPath + '/history';
      else {
        const description = await forward('GET', workflowPath, {...headers, 'content-length': '0'});
        if (description.status !== 200 || typeof json(description).run_id !== 'string') throw Error('Original run unavailable');
        historyPath = workflowPath + '/runs/' + encodeURIComponent(json(description).run_id) + '/history';
      }
      const snapshot = await forward('GET', historyPath, {...headers, 'content-length': '0'});
      if (snapshot.status !== 200) throw Error('Original history unavailable');
      before = sha(Buffer.from(JSON.stringify(json(snapshot))));
    }
    const reply = await forward(request.method, request.url, headers, body);
    if (path === '/api/cluster/info' && request.method === 'GET') {
      record('discovery', {status: reply.status,
        query_task_authority: json(reply).worker_protocol?.server_capabilities?.query_task_authority === true});
    }
    if (path === '/api/worker/register' && request.method === 'POST') record('registered', {status: reply.status});
    if (path.endsWith('/query-tasks/poll') && reply.status === 200) {
      const task = json(reply).task;
      if (task && typeof task === 'object') {
        const utc6 = /^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}Z$/;
        const times = {};
        for (const key of ['query_admitted_at', 'query_deadline_at', 'lease_expires_at', 'server_time']) {
          const parsed = Date.parse(task[key]);
          if (!utc6.test(task[key]) || !Number.isFinite(parsed)
            || new Date(parsed).toISOString() !== task[key].slice(0, 23) + 'Z') {
            throw Error('Missing original UTC6 authority: ' + key);
          }
          times[key] = BigInt(parsed) * 1000n + BigInt(task[key].slice(23, 26));
        }
        const original = JSON.parse(body.toString('utf8'));
        if (times.query_deadline_at - times.query_admitted_at !== 4_000_000n
          || times.lease_expires_at < times.query_admitted_at
          || times.lease_expires_at > times.query_deadline_at
          || times.server_time < times.query_admitted_at
          || task.query_task_attempt !== 1 || task.lease_owner !== original.worker_id
          || task.task_queue !== original.task_queue
          || typeof request.headers['x-namespace'] !== 'string' || !request.headers['x-namespace']
          || (task.namespace !== undefined && task.namespace !== request.headers['x-namespace'])
          || ['query_task_id', 'workflow_id', 'run_id'].some(key => typeof task[key] !== 'string' || !task[key])) {
          throw Error('Changed original query authority');
        }
        record('claim', {protocol: request.headers['x-durable-workflow-protocol-version'],
          namespace: request.headers['x-namespace'], claim: select(task)});
        if (mode === 'late-poll') {
          if (held) throw Error('Multiple poll responses invalidate this single-delivery fault control');
          held = true;
          record('held');
          await new Promise(resolve => setTimeout(resolve, 6_000));
        }
        record('delivered');
      }
    } else if (/\/query-tasks\/[^/]+\/status$/.test(path)) {
      record('status', {status: reply.status, observation: select(json(reply))});
    } else if (terminal) {
      record('acknowledgement', {status: reply.status, recorded: json(reply).recorded});
    } else if (request.method === 'DELETE' && path.startsWith('/api/worker')) {
      record('deregistered', {status: reply.status});
    }
    if (query) {
      const snapshot = await forward('GET', historyPath, {...headers, 'content-length': '0'});
      if (snapshot.status !== 200) throw Error('Original history disappeared');
      const after = sha(Buffer.from(JSON.stringify(json(snapshot))));
      if (after !== before) throw Error('Query changed the original workflow history');
      record('query-result', {status: reply.status, reason: json(reply).reason ?? null, history_sha256: before});
    }
    response.writeHead(reply.status, reply.headers);
    response.end(reply.bytes);
  } catch (error) {
    const details = {method: request.method, path, message: String(error).slice(0, 1024),
      upstream_started: upstreamStarted,
      error_code: typeof error.code === 'string' ? error.code.slice(0, 64) : null,
      ...(error.observerExchange ? {upstream: error.observerExchange} : {}),
      downstream: {request_complete: request.complete, request_aborted: request.aborted,
        response_destroyed: response.destroyed, response_ended: response.writableEnded}};
    // SDK shutdown may cancel a new poll while its request body is still being
    // sent. This request never reached the Server and cannot claim a task.
    // Completed requests, upstream failures and terminal submissions still
    // invalidate the observation, regardless of peer disconnect state.
    const event = request.method === 'POST' && path === '/api/worker/query-tasks/poll'
      && !upstreamStarted && !request.complete && request.aborted && error.code === 'ECONNRESET'
      ? 'poll-disconnected' : 'observer-error';
    record(event, details);
    console.error(JSON.stringify({event, ...details}));
    response.destroy(error);
  }
});
server.listen(Number(process.env.DW_PARITY_QUERY_OBSERVER_PORT ?? 8080), '0.0.0.0', () =>
  console.log(JSON.stringify({ready: true, port: server.address().port})));
process.on('SIGTERM', () => server.close(() => process.exit(0)));

