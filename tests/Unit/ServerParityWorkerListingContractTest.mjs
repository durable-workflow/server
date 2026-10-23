import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkWorkerListing} from '../../scripts/conformance/server-parity/worker-listing-contract.mjs';

// A modeled observation shaped like published PHP's. It checks the contract
// itself; only actual recordings qualify a server.
const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/worker-listing.json', import.meta.url)));
const definition = fixture.worker_listing;
const id = 'listing-reference';
const [first, second] = [`${id}-listing-first`, `${id}-listing-second`];
const queues = [`${id}-listing-a`, `${id}-listing-b`];
const capabilities = ['query_tasks', 'workflow_updates'];
const manifest = {local_activities: {supported: false, minimum_protocol_version: '1.18', reason: 'not_enabled_for_this_worker_registration'}};
const at = '2026-10-20T14:49:45.000000Z';
const receipt = (workerId, queue, buildId) => ({worker_id: workerId, registered: true, namespace: 'default', task_queue: queue,
  runtime: 'php', build_id: buildId, capabilities, capability_manifest: manifest, status: 'active',
  heartbeat_interval_seconds: definition.heartbeat_interval_seconds, protocol_version: '1.20'});
const capacity = definition.capacity;
const firstWorker = {worker_id: first, namespace: 'default', task_queue: queues[0], runtime: 'php',
  sdk_version: 'durable-workflow-php/2.2.6', build_id: definition.build_id, supported_workflow_types: [definition.workflow_type],
  workflow_definition_fingerprints: {[definition.workflow_type]: definition.fingerprint}, workflow_command_contracts: [],
  supported_activity_types: [definition.activity_type], capabilities, capability_manifest: manifest,
  max_concurrent_workflow_tasks: capacity.workflow, max_concurrent_activity_tasks: capacity.activity,
  max_concurrent_worker_sessions: capacity.sessions, task_slots: {...definition.stored_task_slots,
    workflow_capacity: capacity.workflow, activity_capacity: capacity.activity, session_capacity: capacity.sessions},
  process_metrics: {process_uptime_seconds: 0, process_id: 17, _worker_protocol_version: '1.19'}, heartbeat_interval_seconds: null, status: 'active',
  last_heartbeat_at: at, registered_at: at};
const secondWorker = {...firstWorker, worker_id: second, task_queue: queues[1], build_id: null,
  workflow_definition_fingerprints: [], supported_activity_types: [], max_concurrent_workflow_tasks: 1,
  max_concurrent_activity_tasks: 1, max_concurrent_worker_sessions: 10,
  task_slots: {workflow_available: null, activity_available: null, session_available: null,
    workflow_capacity: 1, activity_capacity: 1, session_capacity: 10}, process_metrics: {_worker_protocol_version: '1.19'}};
const listing = workers => ({stale_after_seconds: definition.stale_after_seconds, workers});
const described = {...firstWorker, updated_at: at, stale_after_seconds: definition.stale_after_seconds};
const refusal = (reason, error, remediation) => ({status: 409, response: {error, reason,
  workflow_type: definition.workflow_type, remediation, protocol_version: '1.20'}});
const observation = () => structuredClone({mode: 'http', worker_listing: {applicable: true, first, second, queues, probe_pid: 17,
  first_registration: receipt(first, queues[0], definition.build_id), second_registration: receipt(second, queues[1], null),
  reregistration: receipt(first, queues[0], definition.build_id),
  heartbeat: {worker_id: first, acknowledged: true, heartbeat_interval_seconds: 10, stale_after_seconds: 30, protocol_version: '1.20'},
  listed: listing([secondWorker, firstWorker]), first_queue: listing([firstWorker]), active: listing([firstWorker, secondWorker]),
  stale: listing([]), after_deregistration: listing([]), described,
  missing: {status: 404, response: {reason: 'worker_not_found', message: `Worker [${id}-listing-missing] not found in namespace [default].`}},
  changed_definition: refusal('workflow_definition_changed', 'Worker attempted to re-register a changed workflow definition.',
    'Restart the worker with a new worker_id before registering a changed workflow class definition.'),
  missing_fingerprint: refusal('workflow_definition_fingerprint_missing', 'Worker omitted a previously advertised workflow definition fingerprint.',
    'Advertise the matching definition fingerprint or restart with a new worker_id.'),
  after_refusals: described,
  after_reregistration: {...described, task_slots: {...described.task_slots, workflow_available: null,
    activity_available: null, session_available: null}, process_metrics: {_worker_protocol_version: '1.19'}}}});

test('worker listing accepts the published facts in any equal-heartbeat order', () => {
  assert.deepStrictEqual(checkWorkerListing(fixture, observation(), id),
    {scope: 'worker_listing', workflow_type: definition.workflow_type});
});

test('embedded worker listing is explicitly inapplicable', () => {
  assert.deepStrictEqual(checkWorkerListing(fixture, {mode: 'embedded', worker_listing:
    {applicable: false, reason: 'embedded_has_no_http_worker_registration_lifecycle'}}, id),
  checkWorkerListing(fixture, observation(), id));
});

for (const [label, mutate] of [
  ['an unbounded task slot', state => { state.listed.workers[1].task_slots.activity_available = 9; }],
  ['default capacities in place of the registered ones', state => { state.described.max_concurrent_workflow_tasks = 100; }],
  ['a dropped fingerprint', state => { state.listed.workers[1].workflow_definition_fingerprints = []; }],
  ['an empty map rendered as an object', state => { state.listed.workers[0].workflow_definition_fingerprints = {}; }],
  ['missing process metrics', state => { state.described.process_metrics = null; }],
  ['an extra summary field', state => { state.listed.workers[0].invented = true; }],
  ['an unfiltered task queue', state => { state.first_queue.workers.push(structuredClone(secondWorker)); }],
  ['a live worker listed as stale', state => { state.stale.workers.push(structuredClone(firstWorker)); }],
  ['an accepted definition change', state => { state.changed_definition = {status: 201, response: {}}; }],
  ['a refusal that changed the registration', state => { state.after_refusals.task_slots.workflow_available = 1; }],
  ['a lost advertised fingerprint', state => { state.after_reregistration.workflow_definition_fingerprints = []; }],
  ['slots kept across re-registration', state => { state.after_reregistration.task_slots.workflow_available = 2; }],
  ['a replaced registration time', state => { state.after_reregistration.registered_at = '2026-10-20T15:00:00.000000Z'; }],
  ['a wrong stale window', state => { state.heartbeat.stale_after_seconds = 300; }],
  ['a worker left after deregistration', state => { state.after_deregistration.workers.push(structuredClone(firstWorker)); }],
  ['a different missing-worker message', state => { state.missing.response.message = 'Worker not found.'; }],
]) test(`worker listing rejects ${label}`, () => {
  const value = observation();
  mutate(value.worker_listing);
  assert.throws(() => checkWorkerListing(fixture, value, id));
});
