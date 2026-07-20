'use strict';

const crypto = require('crypto');
const http = require('http');
const https = require('https');

const MAX_ERROR_MESSAGE_LENGTH = 240;
const MAX_RESPONSE_BYTES = 1024 * 1024;
const STALE_SOCKET_CODES = new Set([
  'ECONNRESET',
  'EPIPE',
  'UND_ERR_SOCKET',
]);

function boundedMessage(value) {
  return String(value || '')
    .replace(/[\r\n\t]+/g, ' ')
    .replace(/Bearer\s+\S+/gi, 'Bearer [redacted]')
    .replace(/\/\/[^\s/@:]+:[^\s/@]+@/g, '//[redacted]@')
    .slice(0, MAX_ERROR_MESSAGE_LENGTH);
}

function errorChain(error) {
  const chain = [];
  const seen = new Set();
  let current = error;

  while (current && typeof current === 'object' && !seen.has(current) && chain.length < 5) {
    chain.push(current);
    seen.add(current);
    current = current.cause;
  }

  return chain;
}

function errorCode(error) {
  for (const candidate of errorChain(error)) {
    if (typeof candidate.code === 'string' && candidate.code !== '') {
      return candidate.code;
    }
  }

  return '';
}

function staleSocketTransportError(error) {
  return errorChain(error).some((candidate) => STALE_SOCKET_CODES.has(String(candidate.code || '')));
}

function transportErrorEvidence(error) {
  return {
    name: boundedMessage(error?.name || 'Error'),
    code: boundedMessage(errorCode(error)),
    message: boundedMessage(error?.message || 'transport request failed'),
  };
}

function requestBodySha256(body) {
  return crypto.createHash('sha256').update(JSON.stringify(body)).digest('hex');
}

function idempotencyKeySha256(body) {
  return crypto
    .createHash('sha256')
    .update(String(body?.idempotency_key || ''))
    .digest('hex');
}

function requestEvidence(apiPath, namespace, body) {
  return {
    method: 'POST',
    path: `/api${apiPath}`,
    namespace,
    request_body_sha256: requestBodySha256(body),
    idempotency_key_sha256: idempotencyKeySha256(body),
  };
}

function transportFailureResponse(namespace, apiPath, body, error) {
  return {
    request: {
      method: 'POST',
      path: `/api${apiPath}`,
      namespace,
      body,
    },
    status: 0,
    ok: false,
    body: {
      transport_error: transportErrorEvidence(error),
    },
    raw_body: '',
  };
}

function freshApiRequest(baseUrl, token, namespace, method, apiPath, body = null) {
  const target = new URL(`/api${apiPath}`, baseUrl);
  const serializedBody = body === null ? '' : JSON.stringify(body);
  const headers = {
    Authorization: `Bearer ${token}`,
    'X-Durable-Workflow-Control-Plane-Version': '2',
    'X-Namespace': namespace,
    Accept: 'application/json',
  };

  if (body !== null) {
    headers['Content-Type'] = 'application/json';
    headers['Content-Length'] = Buffer.byteLength(serializedBody);
  }

  const client = target.protocol === 'https:' ? https : http;

  return new Promise((resolve, reject) => {
    const request = client.request(target, {
      method,
      headers,
      agent: false,
    }, (response) => {
      const chunks = [];
      let responseBytes = 0;

      response.on('data', (chunk) => {
        responseBytes += chunk.length;
        if (responseBytes > MAX_RESPONSE_BYTES) {
          const error = new Error(`response exceeded ${MAX_RESPONSE_BYTES} bytes`);
          error.code = 'ERR_RESPONSE_TOO_LARGE';
          response.destroy(error);
          return;
        }
        chunks.push(chunk);
      });
      response.on('error', reject);
      response.on('end', () => {
        const rawBody = Buffer.concat(chunks).toString('utf8');
        let parsed = null;
        try {
          parsed = rawBody === '' ? null : JSON.parse(rawBody);
        } catch {
          parsed = {raw_body: rawBody};
        }

        const status = Number(response.statusCode || 0);
        resolve({
          request: {method, path: `/api${apiPath}`, namespace, body},
          status,
          ok: status >= 200 && status < 300,
          body: parsed,
          raw_body: rawBody,
        });
      });
    });

    request.on('error', reject);
    if (serializedBody !== '') {
      request.write(serializedBody);
    }
    request.end();
  });
}

function responseAttempt(attempt, connection, response, identity) {
  return {
    attempt,
    connection,
    outcome: response.ok === true ? 'http_success' : 'http_error',
    http_status: response.status,
    request_body_sha256: identity.request_body_sha256,
    idempotency_key_sha256: identity.idempotency_key_sha256,
  };
}

function errorAttempt(attempt, connection, error, identity) {
  return {
    attempt,
    connection,
    outcome: 'transport_error',
    recognized_stale_socket: staleSocketTransportError(error),
    error: transportErrorEvidence(error),
    request_body_sha256: identity.request_body_sha256,
    idempotency_key_sha256: identity.idempotency_key_sha256,
  };
}

async function replayPostWithStaleSocketRecovery({
  baseUrl,
  token,
  namespace,
  apiPath,
  body,
  pooledRequest,
  freshRequest = freshApiRequest,
}) {
  const identity = requestEvidence(apiPath, namespace, body);
  const attempts = [];

  try {
    const response = await pooledRequest(baseUrl, token, namespace, 'POST', apiPath, body);
    attempts.push(responseAttempt(1, 'pooled', response, identity));

    return {
      response,
      transport: {
        strategy: 'retry_once_on_stale_socket',
        max_retries: 1,
        retry_count: 0,
        recovery_needed: false,
        recovery_attempted: false,
        fresh_connection_used: false,
        transport_recovered: false,
        request: identity,
        attempts,
      },
    };
  } catch (error) {
    const firstAttempt = errorAttempt(1, 'pooled', error, identity);
    attempts.push(firstAttempt);

    if (!firstAttempt.recognized_stale_socket) {
      return {
        response: transportFailureResponse(namespace, apiPath, body, error),
        transport: {
          strategy: 'retry_once_on_stale_socket',
          max_retries: 1,
          retry_count: 0,
          recovery_needed: false,
          recovery_attempted: false,
          fresh_connection_used: false,
          transport_recovered: false,
          request: identity,
          attempts,
        },
      };
    }
  }

  try {
    const response = await freshRequest(baseUrl, token, namespace, 'POST', apiPath, body);
    attempts.push(responseAttempt(2, 'fresh', response, identity));

    return {
      response,
      transport: {
        strategy: 'retry_once_on_stale_socket',
        max_retries: 1,
        retry_count: 1,
        recovery_needed: true,
        recovery_attempted: true,
        fresh_connection_used: true,
        transport_recovered: true,
        request: identity,
        attempts,
      },
    };
  } catch (error) {
    attempts.push(errorAttempt(2, 'fresh', error, identity));

    return {
      response: transportFailureResponse(namespace, apiPath, body, error),
      transport: {
        strategy: 'retry_once_on_stale_socket',
        max_retries: 1,
        retry_count: 1,
        recovery_needed: true,
        recovery_attempted: true,
        fresh_connection_used: true,
        transport_recovered: false,
        request: identity,
        attempts,
      },
    };
  }
}

module.exports = {
  freshApiRequest,
  replayPostWithStaleSocketRecovery,
  staleSocketTransportError,
};
