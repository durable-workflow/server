import assert from 'node:assert/strict';

export function checkNamespaces(fixture, observation, workflowId, checkRun) {
  const definition = fixture.namespace_isolation;
  const state = observation.namespace_isolation;
  const embedded = observation.mode === 'embedded';
  assert.ok(state && typeof state === 'object', 'actual named namespace observation');
  const names = definition.namespace_suffixes.map(suffix => (workflowId + suffix).toLowerCase());
  const ids = definition.peer_suffixes.map(suffix => workflowId + suffix);
  assert.deepStrictEqual(state.namespaces, names, 'original named namespaces');
  assert.equal(state.runs.length, 2, 'two actually completed named runs');
  assert.equal(state.before.length, 2, 'two original pending peers');
  assert.deepStrictEqual(state.after_refusals, state.before, 'foreign requests preserve complete original pending states and histories');
  const identities = [observation.run_id];
  const projections = [];
  for (const index of [0, 1]) {
    const run = state.runs[index];
    const before = state.before[index];
    assert.equal(before.workflow_id, ids[index], 'original pending workflow');
    assert.equal(before.namespace, names[index], 'original pending namespace');
    assert.equal(before.status, 'pending', 'peer was actually pending before foreign requests');
    assert.equal(before.run_id, run.run_id, 'completion preserves original run');
    assert.deepStrictEqual(before.events.map(event => event.event_type), ['StartAccepted', 'WorkflowStarted'], 'original pending event inventory');
    assert.deepStrictEqual(run.events.slice(0, 2), before.events, 'completion preserves original start history');
    identities.push(run.run_id);
    projections.push(checkRun({id: fixture.id + definition.peer_suffixes[index],
      workflow_type: definition.peer_workflow_type, namespace: names[index], input: fixture.input,
      typed_value: fixture.typed_value, activity: true, expected_events: definition.expected_events},
    {...run, mode: observation.mode}, ids[index]));
  }
  assert.equal(new Set(identities).size, identities.length, 'distinct original root and peer runs');
  if (embedded) {
    for (const field of ['administration', 'requests', 'worker_receipts', 'worker_refusals']) {
      assert.deepStrictEqual(state[field], [], 'HTTP namespace and worker operations are explicitly inapplicable to embedded mode');
    }
    assert.equal(state.foreign_selections.length, 2, 'both foreign embedded selections exercised');
    for (const selection of state.foreign_selections) {
      assert.deepStrictEqual(selection, {refused: true, exception: 'Illuminate\\Database\\Eloquent\\ModelNotFoundException'}, 'installed engine refuses foreign namespace selection');
    }
  } else {
    assert.equal(state.worker_id, workflowId + '-shared-worker', 'same worker ID used in both namespaces');
    assert.equal(state.administration.length, 2, 'two actual namespace creations');
    assert.deepStrictEqual(state.listed.map(item => item.name).sort(), [...names].sort(), 'namespace list contains both original namespaces');
    for (const index of [0, 1]) {
      const {created, described} = state.administration[index];
      assert.deepStrictEqual(described, created, 'case-insensitive namespace description preserves original metadata');
      for (const metadata of [created, state.listed.find(item => item.name === names[index])]) {
        assert.equal(metadata.name, names[index], 'normalized namespace name');
        assert.equal(metadata.description, definition.description, 'original namespace description');
        assert.equal(metadata.status, 'active', 'registered namespace is active');
        assert.equal(metadata.retention_mode, 'bounded', 'original retention mode');
        assert.equal(metadata.retention_days, definition.retention_days, 'original retention budget');
        assert.equal(metadata.external_payload_storage, null, 'original unset external storage');
        for (const field of ['created_at', 'updated_at']) assert.ok(Number.isFinite(Date.parse(metadata[field])), 'persisted namespace timestamps');
      }
      const registration = state.registrations[index];
      assert.equal(registration.worker_id, state.worker_id, 'same original registration worker ID');
      assert.equal(registration.namespace, names[index], 'registration belongs to selected namespace');
      assert.equal(registration.registered, true, 'actual worker registration acknowledged');
      const receipts = state.worker_receipts[index];
      assert.ok(receipts.length > 0, 'actual SDK worker I/O retained');
      for (const receipt of receipts) {
        assert.equal(receipt.namespace, names[index], 'all worker I/O uses its original namespace');
        for (const field of ['authorization', 'token', 'auth_token', 'request_headers']) assert.equal(receipt[field], undefined, 'worker receipts exclude credentials');
        if (receipt.path.endsWith('/poll') && receipt.response.task) {
          const task = receipt.response.task;
          assert.equal(receipt.request.worker_id, state.worker_id, 'poll uses shared worker ID');
          assert.equal(receipt.request.task_queue, 'server-parity-v1', 'poll uses same task queue');
          assert.equal(task.workflow_id, ids[index], 'poll selects its own workflow');
          assert.equal(task.run_id, state.runs[index].run_id, 'poll selects its own original run');
          // The published task payload omits namespace. The actual request
          // header and original run relationship supply its namespace binding.
          if (task.namespace !== undefined) assert.equal(task.namespace, names[index], 'poll retains original namespace when included');
          assert.equal(task.lease_owner, state.worker_id, 'actual shared-ID lease owner');
        }
      }
      const claimed = receipts.filter(receipt => receipt.path.endsWith('/poll') && receipt.response.task);
      assert.equal(claimed.filter(receipt => receipt.path.includes('/workflow-tasks/')).length, 2, 'original workflow is replayed after its activity');
      assert.equal(claimed.filter(receipt => receipt.path.includes('/activity-tasks/')).length, 1, 'original activity executed once');
      assert.equal(receipts.filter(receipt => receipt.status === 200 && receipt.path.endsWith('/complete')).length, 3, 'actual authored task outcomes committed');
      assert.ok(receipts.some(receipt => receipt.method === 'DELETE' && receipt.path === '/api/worker/registrations/' + state.worker_id && receipt.status === 200), 'SDK shutdown deregisters its own namespace');
    }
    assert.deepStrictEqual(state.second_after_first, state.before[1], 'first worker cannot claim or mutate second namespace');
    assert.equal(state.second_worker_heartbeat.acknowledged, true, 'first deregistration preserves same-ID second worker');
    const expected = [
      ['foreign_current_read', 404, 'instance_not_found'], ['foreign_run_read', 404, 'run_not_found'],
      ['foreign_history', 404, 'run_not_found'], ['foreign_cancel', 404, 'instance_not_found'],
      ['reserved_workflow_id', 409, 'workflow_id_reserved_in_namespace'],
    ];
    assert.deepStrictEqual(state.requests.map(item => item.id), expected.map(item => item[0]), 'complete foreign control request inventory');
    for (const [index, [id, status, reason]] of expected.entries()) {
      const receipt = state.requests[index];
      assert.equal(receipt.status, status, id + ' actual transport refusal');
      assert.equal(receipt.response.reason, reason, id + ' canonical refusal');
      assert.equal(receipt.response.workflow_id, ids[0], 'refusal retains original workflow target');
      if (id === 'reserved_workflow_id') {
        assert.equal(receipt.response.outcome, 'rejected_workflow_id_reserved_in_namespace', 'global workflow ID reservation');
        assert.equal(receipt.response.message, `Workflow [${ids[0]}] is already reserved in another namespace.`, 'original reservation diagnostic');
      }
    }
    assert.deepStrictEqual(state.worker_refusals.map(item => item.kind), ['workflow', 'activity', 'workflow'], 'foreign completion attempts occur before each real commit');
    for (const [index, refusal] of state.worker_refusals.entries()) {
      assert.deepStrictEqual(refusal.after, refusal.before, 'foreign known task completion preserves complete original description and history');
      assert.equal(refusal.before.workflow_id, ids[0], 'foreign task probe preserves original workflow target');
      assert.equal(refusal.before.run_id, state.runs[0].run_id, 'foreign task probe preserves original run target');
      assert.equal(refusal.before.status, ['pending','waiting','waiting'][index], 'task leasing preserves authored lifecycle state');
      assert.equal(refusal.before.execution.status, refusal.before.status, 'original description and selected state agree');
      assert.equal(refusal.receipt.status, 404, 'foreign known task transport refusal');
      assert.equal(refusal.receipt.response.reason, 'task_not_found', 'namespace fence precedes same-ID lease authority');
      assert.equal(refusal.request.lease_owner, state.worker_id, 'foreign completion supplied actual same-ID lease owner');
      const original = state.worker_receipts[0].find(receipt => receipt.path.endsWith('/' + refusal.task_id + '/complete'));
      assert.ok(original, 'refused completion comes from an actual authored original worker request');
      assert.deepStrictEqual(refusal.request, original.request, 'foreign completion retains actual original command and claim body');
      assert.equal(original.status, 200, 'original namespace can still commit the authored completion');
    }
  }
  return {namespaces: names, runs: projections};
}
