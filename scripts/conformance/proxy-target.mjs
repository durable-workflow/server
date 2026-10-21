const REQUEST_TARGET_BASE = 'http://request-target.invalid';

export function proxyTargetUrl(requestTarget, configuredOrigin) {
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

  const incoming = new URL(requestTarget ?? '/', REQUEST_TARGET_BASE);
  const target = new URL(`${incoming.pathname}${incoming.search}`, upstream);
  if (target.origin !== upstream.origin) {
    throw new Error('proxy request target changed the configured upstream origin');
  }

  return target;
}
