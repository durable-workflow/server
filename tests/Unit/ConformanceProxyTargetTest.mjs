import assert from 'node:assert/strict';
import test from 'node:test';

import { proxyTargetUrl } from '../../scripts/conformance/proxy-target.mjs';

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
