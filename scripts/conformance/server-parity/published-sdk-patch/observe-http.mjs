// Forward unchanged SDK HTTP bytes; retain bodies/statuses, never credentials.
import http from 'node:http';
import {appendFileSync} from 'node:fs';
import {gunzipSync, inflateSync, brotliDecompressSync} from 'node:zlib';
import {proxyHttpRequest, proxyTargetPath} from '../../proxy-target.mjs';

const [target, journal, observationMode, gateMode, gateWorkflowId, ...extra] = process.argv.slice(2);
if (observationMode !== undefined && observationMode !== '--forwarding-state') throw new Error('Unknown observation mode');
if (gateMode !== undefined && (gateMode !== '--workflow-poll-after-start' || !gateWorkflowId || extra.length
  || observationMode !== '--forwarding-state')) throw new Error('Invalid workflow start gate');
const upstream = new URL(target);
if (upstream.protocol !== 'http:') throw new Error('Isolated fixture target must use HTTP');
const arrivalJournal = process.env.DW_PARITY_HTTP_REQUEST_ARRIVALS;
const captureNamespace = process.env.DW_PARITY_HTTP_CAPTURE_NAMESPACE === '1';
const captureTiming = process.env.DW_PARITY_HTTP_CAPTURE_TIMING === '1';
// Opt-in: retry the SQLite reference's documented retryable operation refusal
// (503 backend_lock_pressure, retryable, nothing recorded) below the SDK, as
// lockPressureOperationRetryDelay does for PHP clients. Polls are never
// retried here, so their pressure stays visible. Only the final reply is
// recorded and returned.
const retryLockPressure = process.env.DW_PARITY_PROXY_RETRY_LOCK_PRESSURE === '1';
const lockPressureRetryDelay = (method, path, status, text, retries, waited) => {
  if (!retryLockPressure || status !== 503 || path.endsWith('/poll') || retries >= 15) return null;
  let reply;
  try { reply = JSON.parse(text); } catch { return null; }
  if (!reply || reply.reason !== 'backend_lock_pressure' || reply.retryable !== true
    || (Object.hasOwn(reply, 'recorded') && reply.recorded !== false)) return null;
  const hint = reply.retry_after_seconds ?? 1;
  if (typeof hint !== 'number') return null;
  const delay = Math.min(Math.max(hint, 0.1), 5);
  return waited + delay <= 30 ? delay : null;
};
if (arrivalJournal !== undefined && (!arrivalJournal || observationMode !== '--forwarding-state')) {
  throw new Error('Request-arrival capture requires a journal and forwarding-state mode');
}
let requestCounter = 0, arrivalCount = 0, arrivalBytes = 0;
let startObserved = gateMode === undefined;
const waitingPolls = new Set();
const server = http.createServer((request, response) => {
  const startedAt = Date.now() / 1000;
  const requestObservationId = ++requestCounter;
  const chunks = [];
  let forwarded;
  let forwardRequest;
  let requestBodyComplete = false;
  let recorded = false;
  const record = fields => {
    if (recorded) return;
    recorded = true;
    appendFileSync(journal, JSON.stringify({method: request.method, path: proxyTargetPath(request.url).pathname,
      ...(captureNamespace ? {namespace: request.headers['x-namespace'] ?? null} : {}),
      request_body: Buffer.concat(chunks).toString('utf8'), status: 0, response_encoding: 'identity',
      response_retry_after: null, response_bytes_base64: '', response_body: '', client_cancelled: false,
      transport_error: null, ...(observationMode === '--forwarding-state' ? {
        request_body_complete: requestBodyComplete, upstream_request_started: Boolean(forwarded),
      } : {}), ...(captureTiming ? {request_started_at: startedAt, response_observed_at: Date.now() / 1000} : {}),
      ...(arrivalJournal ? {request_observation_id: requestObservationId} : {}), ...fields})+'\n');
  };
  const failed = error => {
    record({transport_error: error.code ?? 'upstream_error'});
    response.destroy(error);
  };
  response.on('close', () => {
    waitingPolls.delete(forwardRequest);
    if (!recorded && !response.writableFinished) {
      record({client_cancelled: true});
      forwarded?.destroy();
    }
  });
  request.on('data', chunk => chunks.push(chunk));
  request.on('end', () => {
    requestBodyComplete = true;
    if (recorded) return;
    const body = Buffer.concat(chunks);
    forwardRequest = () => {
      if (recorded) return;
      const arrivalLine = arrivalJournal ? JSON.stringify({schema: 'durable-workflow.server-parity-http-request-arrival/v1',
        request_observation_id: requestObservationId, method: request.method,
        path: proxyTargetPath(request.url).pathname, request_body: body.toString('utf8'),
        request_body_complete: true, upstream_request_started: true, response_observed: false})+'\n' : null;
      const arrivalSize = arrivalLine === null ? 0 : Buffer.byteLength(arrivalLine);
      if (arrivalLine && (arrivalCount >= 512 || arrivalBytes + arrivalSize > 4 * 1024 * 1024)) {
        throw new Error('Request-arrival journal exceeds its bounded fixture budget');
      }
      const send = (retries, waited) => { forwarded = proxyHttpRequest(request.url, upstream, {
        method: request.method,
        headers: {...request.headers, host: upstream.host},
      }, incoming => {
        const received = [];
        incoming.on('data', chunk => received.push(chunk));
        incoming.on('end', () => {
          if (recorded) return;
          const bytes = Buffer.concat(received);
          const encoding = incoming.headers['content-encoding'] ?? 'identity';
          const decoders = {identity: value => value, gzip: gunzipSync, deflate: inflateSync, br: brotliDecompressSync};
          if (!decoders[encoding]) throw new Error(`Unsupported observed content encoding: ${encoding}`);
          const decoded = decoders[encoding](bytes).toString('utf8');
          const delay = lockPressureRetryDelay(request.method, proxyTargetPath(request.url).pathname, incoming.statusCode,
            decoded, retries, waited);
          if (delay !== null) {
            setTimeout(() => { if (!recorded) send(retries + 1, waited + delay); }, delay * 1000);
            return;
          }
          record({status: incoming.statusCode, response_encoding: encoding,
            response_retry_after: incoming.headers['retry-after'] ?? null,
            response_bytes_base64: bytes.toString('base64'), response_body: decoded});
          response.writeHead(incoming.statusCode, incoming.headers);
          response.end(bytes);
          if (!startObserved && request.method === 'POST' && proxyTargetPath(request.url).pathname === '/api/workflows'
            && incoming.statusCode === 201) {
            let started, receipt;
            try { started = JSON.parse(body.toString('utf8')); receipt = JSON.parse(decoded); } catch { return; }
            if (started.workflow_id === gateWorkflowId && typeof receipt.run_id === 'string' && receipt.run_id.length > 0) {
              startObserved = true;
              const pending = [...waitingPolls];
              waitingPolls.clear();
              for (const forward of pending) forward();
            }
          }
        });
        incoming.on('error', failed);
      });
      forwarded.on('error', failed);
      forwarded.end(body); };
      send(0, 0);
      if (arrivalLine) {
        appendFileSync(arrivalJournal, arrivalLine);
        arrivalCount++;
        arrivalBytes += arrivalSize;
      }
    };
    // Keep the real worker request intact until this fixture's accepted start
    // is fully observed. Other cohorts retain their original forwarding behavior.
    if (!startObserved && request.method === 'POST'
      && proxyTargetPath(request.url).pathname === '/api/worker/workflow-tasks/poll') waitingPolls.add(forwardRequest);
    else forwardRequest();
  });
});
server.listen(0, '127.0.0.1', () => console.log(`http://127.0.0.1:${server.address().port}`));
