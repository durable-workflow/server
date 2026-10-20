import assert from 'node:assert/strict';

const SUMMARY_FIELDS = ['worker_id', 'namespace', 'task_queue', 'runtime', 'sdk_version', 'build_id',
  'supported_workflow_types', 'workflow_definition_fingerprints', 'workflow_command_contracts',
  'supported_activity_types', 'capabilities', 'capability_manifest', 'max_concurrent_workflow_tasks',
  'max_concurrent_activity_tasks', 'max_concurrent_worker_sessions', 'task_slots', 'process_metrics',
  'heartbeat_interval_seconds', 'status', 'last_heartbeat_at', 'registered_at'];

// Reviewed worker management contract (WorkerManagementController and the
// worker registration and heartbeat facts it reports). Embedded applications
// have no HTTP worker registration, so the projection is the definition alone.
export function checkWorkerListing(fixture, observation, workflowId) {
  const definition = fixture.worker_listing;
  const state = observation.worker_listing;
  const projection = {scope: 'worker_listing', workflow_type: definition.workflow_type};
  assert.ok(state && typeof state === 'object', 'actual worker listing observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_http_worker_registration_lifecycle'},
      'embedded worker listing explicitly inapplicable');
    return projection;
  }
  const equal = (actual, expected, label) => assert.deepStrictEqual(actual, expected, label);
  const time = (value, label) => assert.ok(typeof value === 'string' && Number.isFinite(Date.parse(value)), label);
  const first = `${workflowId}-listing-first`;
  const second = `${workflowId}-listing-second`;
  const queues = [`${workflowId}-listing-a`, `${workflowId}-listing-b`];
  equal(state.applicable, true, 'HTTP worker listing actually executed');
  equal([state.first, state.second, state.queues], [first, second, queues], 'listing identities');
  assert.ok(Number.isInteger(state.probe_pid) && state.probe_pid > 0, 'probe process identity');

  const registrations = [['first_registration', first, queues[0], definition.build_id],
    ['second_registration', second, queues[1], null], ['reregistration', first, queues[0], definition.build_id]];
  for (const [key, workerId, queue, buildId] of registrations) {
    const receipt = state[key];
    equal([receipt.worker_id, receipt.registered, receipt.namespace, receipt.task_queue, receipt.runtime,
      receipt.build_id, receipt.status, receipt.heartbeat_interval_seconds],
    [workerId, true, 'default', queue, 'php', buildId, 'active', definition.heartbeat_interval_seconds], `${key} receipt`);
  }
  const heartbeat = state.heartbeat;
  equal([heartbeat.worker_id, heartbeat.acknowledged, heartbeat.heartbeat_interval_seconds, heartbeat.stale_after_seconds],
    [first, true, definition.heartbeat_interval_seconds, definition.stale_after_seconds], 'heartbeat acknowledgement');

  const capacity = definition.capacity;
  const firstFacts = {worker_id: first, namespace: 'default', task_queue: queues[0], runtime: 'php',
    build_id: definition.build_id, supported_workflow_types: [definition.workflow_type],
    workflow_definition_fingerprints: {[definition.workflow_type]: definition.fingerprint},
    // Published PHP renders an empty map as a JSON list.
    workflow_command_contracts: [], supported_activity_types: [definition.activity_type],
    max_concurrent_workflow_tasks: capacity.workflow, max_concurrent_activity_tasks: capacity.activity,
    max_concurrent_worker_sessions: capacity.sessions,
    task_slots: {...definition.stored_task_slots, workflow_capacity: capacity.workflow,
      activity_capacity: capacity.activity, session_capacity: capacity.sessions},
    process_metrics: {process_uptime_seconds: 0, process_id: state.probe_pid},
    heartbeat_interval_seconds: null, status: 'active'};
  // The published SDK's registration defaults.
  const secondFacts = {worker_id: second, namespace: 'default', task_queue: queues[1], runtime: 'php', build_id: null,
    supported_workflow_types: [definition.workflow_type], workflow_definition_fingerprints: [],
    workflow_command_contracts: [], supported_activity_types: [], max_concurrent_workflow_tasks: 1,
    max_concurrent_activity_tasks: 1, max_concurrent_worker_sessions: 10,
    task_slots: {workflow_available: null, activity_available: null, session_available: null,
      workflow_capacity: 1, activity_capacity: 1, session_capacity: 10},
    process_metrics: null, heartbeat_interval_seconds: null, status: 'active'};
  const registered = {[first]: state.first_registration, [second]: state.second_registration};
  const summary = (worker, facts, label) => {
    equal(Object.keys(worker).sort(), [...SUMMARY_FIELDS].sort(), `${label} summary fields`);
    for (const [field, value] of Object.entries(facts)) equal(worker[field], value, `${label} ${field}`);
    assert.ok(typeof worker.sdk_version === 'string' && worker.sdk_version.startsWith('durable-workflow-php/'), `${label} SDK identity`);
    equal(worker.capabilities, registered[worker.worker_id].capabilities, `${label} registered capabilities`);
    equal(worker.capability_manifest, registered[worker.worker_id].capability_manifest, `${label} registered manifest`);
    time(worker.last_heartbeat_at, `${label} heartbeat time`);
    time(worker.registered_at, `${label} registration time`);
  };
  const listing = (value, expected, label) => {
    equal(value.stale_after_seconds, definition.stale_after_seconds, `${label} stale window`);
    // Equal heartbeat times may tie, so order is not part of this contract.
    const workers = [...value.workers].sort((a, b) => a.worker_id.localeCompare(b.worker_id));
    equal(workers.map(worker => worker.worker_id), expected.map(facts => facts.worker_id), `${label} workers`);
    workers.forEach((worker, index) => summary(worker, expected[index], `${label} ${worker.worker_id}`));
  };
  listing(state.listed, [firstFacts, secondFacts], 'listed');
  listing(state.first_queue, [firstFacts], 'task_queue filter');
  listing(state.active, [firstFacts, secondFacts], 'status=active');
  listing(state.stale, [], 'status=stale');
  listing(state.after_deregistration, [], 'after deregistration');

  const described = ({updated_at, stale_after_seconds, ...worker}, facts, label) => {
    summary(worker, facts, label);
    time(updated_at, `${label} update time`);
    equal(stale_after_seconds, definition.stale_after_seconds, `${label} stale window`);
  };
  described(state.described, firstFacts, 'described');
  equal(state.missing, {status: 404, response: {reason: 'worker_not_found',
    message: `Worker [${workflowId}-listing-missing] not found in namespace [default].`}}, 'missing worker');
  const refusal = (receipt, reason, error, remediation, label) => {
    equal(receipt.status, 409, `${label} status`);
    const {protocol_version, server_capabilities, ...body} = receipt.response;
    equal(body, {error, reason, workflow_type: definition.workflow_type, remediation}, `${label} refusal`);
  };
  refusal(state.changed_definition, 'workflow_definition_changed', 'Worker attempted to re-register a changed workflow definition.',
    'Restart the worker with a new worker_id before registering a changed workflow class definition.', 'changed definition');
  refusal(state.missing_fingerprint, 'workflow_definition_fingerprint_missing', 'Worker omitted a previously advertised workflow definition fingerprint.',
    'Advertise the matching definition fingerprint or restart with a new worker_id.', 'omitted fingerprint');
  equal(state.after_refusals, state.described, 'refused registrations change nothing');

  // A valid re-registration keeps the advertised fingerprint and resets the
  // task slots and process metrics it did not report.
  described(state.after_reregistration, {...firstFacts,
    task_slots: {...firstFacts.task_slots, workflow_available: null, activity_available: null, session_available: null},
    process_metrics: null}, 'after re-registration');
  equal(state.after_reregistration.registered_at, state.described.registered_at, 're-registration keeps the registration time');
  return projection;
}
