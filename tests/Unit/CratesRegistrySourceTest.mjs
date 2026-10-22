import assert from 'node:assert/strict';
import test from 'node:test';

import { isOfficialCratesIoRegistrySource } from '../../scripts/conformance/crates-registry-source.mjs';

test('official crates.io Cargo registry spellings are accepted', () => {
  for (const source of [
    'registry+https://github.com/rust-lang/crates.io-index',
    'registry+https://index.crates.io',
    'registry+https://index.crates.io/',
    'registry+sparse+https://index.crates.io',
    'registry+sparse+https://index.crates.io/',
    'sparse+https://index.crates.io',
    'sparse+https://index.crates.io/',
  ]) {
    assert.equal(isOfficialCratesIoRegistrySource(source), true, source);
  }
});

test('lookalike hosts and paths cannot satisfy crates.io provenance', () => {
  for (const source of [
    'registry+https://index.crates.io.attacker.invalid',
    'registry+https://attacker.invalid/crates.io',
    'registry+https://index.crates.io/alternate-index',
    'https://index.crates.io',
    '',
  ]) {
    assert.equal(isOfficialCratesIoRegistrySource(source), false, source);
  }
});
