import assert from 'node:assert/strict';
import test from 'node:test';

import {
  isExactSemverRelease,
  pythonReleaseIdentity,
  samePythonRelease,
} from '../../scripts/conformance/version-identities.mjs';

test('exact server release identities include prerelease and stable tags', () => {
  for (const version of [
    '2.0.0-alpha.1',
    '2.0.0-beta.12',
    '2.0.0-rc.2',
    '2.0.0',
  ]) {
    assert.equal(isExactSemverRelease(version), true, version);
  }
});

test('malformed and rolling server release inputs are rejected', () => {
  for (const version of [
    '',
    'latest',
    'current',
    '2.0.0-latest',
    '2.0.0-snapshot.4',
    '2.0',
    '2.0.x',
    '2.0.0-beta.01',
    '2.0.0-beta..3',
    'v2.0.0-beta.12',
    '2.0.0-beta.12 || 2.0.0',
  ]) {
    assert.equal(isExactSemverRelease(version), false, version);
  }
});

test('PEP 440 and product SemVer spellings preserve one Python release identity', () => {
  assert.equal(pythonReleaseIdentity('2.0.0-alpha.4'), '2.0.0a4');
  assert.equal(pythonReleaseIdentity('2.0.0-beta.12'), '2.0.0b12');
  assert.equal(pythonReleaseIdentity('2.0.0-rc.2'), '2.0.0rc2');
  assert.equal(samePythonRelease('2.0.0-beta.12', '2.0.0b12'), true);
  assert.equal(samePythonRelease('2.0.0-beta.12', '2.0.0b3'), false);
  assert.equal(samePythonRelease('2.0.0-rc.4', '2.0.0b8'), false);
});
