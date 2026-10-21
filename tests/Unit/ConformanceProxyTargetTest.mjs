import assert from 'node:assert/strict';
import http from 'node:http';
import { once } from 'node:events';
import test from 'node:test';

import { proxyHttpRequest, proxyTargetPath, proxyTargetUrl } from '../../scripts/conformance/proxy-target.mjs';

test('proxy target retains the configured origin and requested path', () => {
  assert.equal(
    proxyTargetUrl('/api/workflows?status=running', 'http://server:8080').toString(),
    'http://server:8080/api/workflows?status=running',
  );
});

test('absolute and network-path request targets cannot replace the configured origin', () => {
  assert.equal(
    proxyTargetUrl('http://169.254.169.254/latest/meta-data', 'http://server:8080').toString(),
    'http://server:8080/latest/meta-data',
  );
  assert.equal(
    proxyTargetUrl('//attacker.invalid/collect?secret=1', 'http://server:8080').toString(),
    'http://server:8080/collect?secret=1',
  );
});

test('proxy upstream is an exact HTTP origin without embedded credentials', () => {
  for (const target of [
    'file:///tmp/socket',
    'http://user:secret@server:8080',
    'http://server:8080/prefix',
    'http://server:8080/?route=other',
    'http://server:8080/#fragment',
  ]) {
    assert.throws(() => proxyTargetUrl('/api/ready', target), /HTTP\(S\) origin/);
  }
});

test('proxy request uses only the configured origin for the network destination', async (context) => {
  let configuredRequests = 0;
  let attackerRequests = 0;
  const configured = http.createServer((request, response) => {
    configuredRequests++;
    response.end(request.url);
  });
  const attacker = http.createServer((_request, response) => {
    attackerRequests++;
    response.end('unexpected');
  });
  context.after(async () => {
    configured.closeAllConnections();
    attacker.closeAllConnections();
    await Promise.all([
      new Promise((resolve) => configured.close(resolve)),
      new Promise((resolve) => attacker.close(resolve)),
    ]);
  });
  configured.listen(0, '127.0.0.1');
  attacker.listen(0, '127.0.0.1');
  await Promise.all([once(configured, 'listening'), once(attacker, 'listening')]);
  const configuredAddress = configured.address();
  const attackerAddress = attacker.address();
  assert.equal(typeof configuredAddress, 'object');
  assert.equal(typeof attackerAddress, 'object');

  const response = await new Promise((resolve, reject) => {
    const request = proxyHttpRequest(
      `http://127.0.0.1:${attackerAddress.port}/latest/meta-data?role=admin`,
      `http://127.0.0.1:${configuredAddress.port}`,
      {},
      resolve,
    );
    request.once('error', reject);
    request.end();
  });
  const chunks = [];
  for await (const chunk of response) chunks.push(chunk);
  assert.equal(Buffer.concat(chunks).toString(), '/latest/meta-data?role=admin');
  assert.equal(configuredRequests, 1);
  assert.equal(attackerRequests, 0);
});

test('proxy request options cannot replace the configured destination or path', () => {
  for (const options of [
    { hostname: 'attacker.invalid' },
    { port: 1 },
    { socketPath: '/tmp/attacker.sock' },
    { path: '//attacker.invalid/collect' },
  ]) {
    assert.throws(
      () => proxyHttpRequest('/api/ready', 'http://server:8080', options),
      /must not override/,
    );
  }
  assert.deepEqual(proxyTargetPath('//attacker.invalid/a?b=1'), {
    path: '/a?b=1',
    pathname: '/a',
  });
});
