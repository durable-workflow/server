import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {expectedFor, nativeDivergence} from '../../scripts/conformance/server-parity/native-divergences.mjs';
import {checkClosedWaitTimeouts} from '../../scripts/conformance/server-parity/closed-wait-timeouts-contract.mjs';
import {checkConcurrentWaits} from '../../scripts/conformance/server-parity/concurrent-waits-contract.mjs';
import {checkCancellationScopes} from '../../scripts/conformance/server-parity/cancellation-scopes-contract.mjs';

const read = path => JSON.parse(readFileSync(new URL(path, import.meta.url), 'utf8'));
const registry = read('../../scripts/conformance/server-parity/native-divergences.json');
const closed = read('../Fixtures/ServerParity/closed-wait-timeouts.json');
const concurrent = read('../Fixtures/ServerParity/concurrent-waits.json');
const scopes = read('../Fixtures/ServerParity/cancellation-scopes.json');
const recordedScopes = read('../Fixtures/ServerParityExpected/cancellation-scopes-observation.json');

test('every registered divergence names the fixtures that apply it, and they name it back', () => {
  const sections = {'closed-wait-timeouts': closed.closed_wait_timeouts, 'concurrent-waits': concurrent.concurrent_waits,
    'cancellation-scopes': scopes.cancellation_scopes};
  const entry = registry.divergences.find(divergence => divergence.id === 'run-close-settles-open-work');
  assert.deepStrictEqual(entry.applies_to.toSorted(), Object.keys(sections).toSorted());
  for (const section of Object.values(sections)) assert.equal(section.native_divergence, entry.id);
});

test('the overlay applies only to the native target under a registered divergence', () => {
  const section = {expected: {a: {b: 1, c: [1]}, d: 2}, native_divergence: 'run-close-settles-open-work',
    native_expected: {a: {c: [1, 2]}}};
  assert.deepStrictEqual(expectedFor(section, 'rust'), {a: {b: 1, c: [1, 2]}, d: 2});
  assert.deepStrictEqual(expectedFor(section, 'php'), section.expected);
  assert.deepStrictEqual(expectedFor(section, 'embedded'), section.expected);
  assert.throws(() => expectedFor({...section, native_divergence: 'unreviewed'}, 'rust'), /unregistered/);
  assert.throws(() => expectedFor({expected: {}, native_expected: {}}, 'rust'), /native_divergence/);
  // An overlay can change reviewed steps only, never add new ones.
  assert.throws(() => expectedFor({...section, native_expected: {added: 1}}, 'rust'), /lacks/);
  assert.equal(nativeDivergence('rust', 'run-close-settles-open-work'), true);
});

test('closed wait timeouts: native own-close histories pass only for the native target', () => {
  const native = {mode: 'http', closed_wait_timeouts: {applicable: true, cases: expectedFor(closed.closed_wait_timeouts, 'rust')}};
  const php = {mode: 'http', closed_wait_timeouts: {applicable: true, cases: structuredClone(closed.closed_wait_timeouts.expected)}};
  assert.deepStrictEqual(checkClosedWaitTimeouts(closed, native, 'rust'), {scope: 'closed_wait_timeouts', cases: 10});
  assert.throws(() => checkClosedWaitTimeouts(closed, native, 'php'));
  assert.throws(() => checkClosedWaitTimeouts(closed, php, 'rust'));
  // Only complete, fail and continue changed; cancel and terminate are published PHP.
  const cases = native.closed_wait_timeouts.cases;
  for (const [name, value] of Object.entries(cases)) {
    const added = value.history.length - closed.closed_wait_timeouts.expected[name].history.length;
    assert.equal(added, /_(complete|fail|continue)$/.test(name) ? 1 : 0, name);
  }
});

test('concurrent waits: the native mixed run pass only for the native target', () => {
  const native = {mode: 'http', concurrent_waits: {applicable: true, ...expectedFor(concurrent.concurrent_waits, 'rust')}};
  const php = {mode: 'http', concurrent_waits: {applicable: true, ...structuredClone(concurrent.concurrent_waits.expected)}};
  assert.deepStrictEqual(checkConcurrentWaits(concurrent, native, 'rust'), checkConcurrentWaits(concurrent, php, 'php'));
  assert.throws(() => checkConcurrentWaits(concurrent, native, 'php'));
  assert.throws(() => checkConcurrentWaits(concurrent, php, 'rust'));
});

test('cancellation scopes: the native scope checkpoints pass only for the native target', () => {
  const id = 'pr1-cancellation-scopes';
  const native = structuredClone(recordedScopes);
  const overlay = scopes.cancellation_scopes.native_expected.scope_checkpoints;
  native.scope_checkpoints.history = structuredClone(overlay.history);
  native.scope_checkpoints.open_after.response.history_event_id = overlay.open_after.response.history_event_id;
  const observation = state => ({mode: 'http', cancellation_scopes: state});
  assert.deepStrictEqual(checkCancellationScopes(scopes, observation(native), id, 'rust'), {scope: 'cancellation_scopes'});
  assert.throws(() => checkCancellationScopes(scopes, observation(native), id, 'php'));
  assert.throws(() => checkCancellationScopes(scopes, observation(structuredClone(recordedScopes)), id, 'rust'));
});
