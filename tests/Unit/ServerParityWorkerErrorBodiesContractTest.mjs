import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkWorkerErrorBodies} from '../../scripts/conformance/server-parity/worker-error-bodies-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/worker-error-bodies.json', import.meta.url), 'utf8'));
const expected = fixture.worker_error_bodies.expected;
const observation = () => structuredClone({mode: 'http', worker_error_bodies: {applicable: true, ...expected}});
const requests = Object.keys(fixture.worker_error_bodies.requests).length;

test('the published answers pass', () => {
  assert.deepStrictEqual(checkWorkerErrorBodies(fixture, observation()), {scope: 'worker_error_bodies', requests});
});

test('embedded applications have no worker protocol', () => {
  assert.deepStrictEqual(checkWorkerErrorBodies(fixture,
    {mode: 'embedded', worker_error_bodies: {applicable: false, reason: 'embedded_has_no_worker_protocol'}}),
  {scope: 'worker_error_bodies', requests});
});

test('every answer is in the worker envelope', () => {
  for (const [name, answer] of Object.entries(expected)) {
    assert.deepStrictEqual([answer.server_capabilities, answer.body.protocol_version], [true, '1.20'], name);
  }
});

for (const [name, mutate] of [
  ['a bare invalid_request poll refusal', state => {
    state.workflow_poll_empty = {status: 422, server_capabilities: false, body: {message: 'invalid_request', reason: 'invalid_request'}};
  }],
  ['an unknown activity failure without its task fields', state => {
    state.activity_fail_unknown.body = {message: 'task_not_found', reason: 'task_not_found'};
    state.activity_fail_unknown.server_capabilities = false;
  }],
  ['a poll refusal without validation_errors', state => { delete state.activity_poll_empty.body.validation_errors; }],
  ['an unexpected request', state => { state.extra = {}; }],
]) test(`the contract rejects ${name}`, () => {
  const raw = observation(); mutate(raw.worker_error_bodies);
  assert.throws(() => checkWorkerErrorBodies(fixture, raw));
});
