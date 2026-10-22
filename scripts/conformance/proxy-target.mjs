import http from 'node:http';
import https from 'node:https';

const REQUEST_TARGET_BASE = 'http://request-target.invalid';
const DESTINATION_OPTIONS = ['protocol', 'host', 'hostname', 'port', 'socketPath', 'href', 'origin', 'path'];

function proxyUpstream(configuredOrigin) {
  const upstream = configuredOrigin instanceof URL
    ? configuredOrigin
    : new URL(configuredOrigin);
  if (!['http:', 'https:'].includes(upstream.protocol)
      || upstream.username
      || upstream.password
      || upstream.pathname !== '/'
      || upstream.search
      || upstream.hash) {
    throw new Error('proxy upstream must be an HTTP(S) origin without credentials');
  }

  return upstream;
}

export function proxyTargetPath(requestTarget) {
  const incoming = new URL(requestTarget ?? '/', REQUEST_TARGET_BASE);
  return {
    path: `${incoming.pathname}${incoming.search}`,
    pathname: incoming.pathname,
  };
}

export function proxyTargetUrl(requestTarget, configuredOrigin) {
  const upstream = proxyUpstream(configuredOrigin);
  const target = new URL(proxyTargetPath(requestTarget).path, upstream);
  if (target.origin !== upstream.origin) {
    throw new Error('proxy request target changed the configured upstream origin');
  }

  return target;
}

export function proxyHttpRequest(requestTarget, configuredOrigin, options = {}, callback) {
  const upstream = proxyUpstream(configuredOrigin);
  for (const name of DESTINATION_OPTIONS) {
    if (Object.hasOwn(options, name)) {
      throw new Error(`proxy request options must not override ${name}`);
    }
  }
  const transport = upstream.protocol === 'https:' ? https : http;
  return transport.request(upstream.origin, {
    ...options,
    path: proxyTargetPath(requestTarget).path,
  }, callback);
}
