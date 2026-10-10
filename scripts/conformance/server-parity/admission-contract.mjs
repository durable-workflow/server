import assert from 'node:assert/strict';

export function checkAdmission(fixture, observation, workflowId) {
  const state = observation.admission;
  const definition = fixture.admission;
  const embedded = observation.mode === 'embedded';
  assert.ok(state && typeof state === 'object', 'actual admission observation');
  const peerId = workflowId + definition.peer_suffix;
  const peerRun = state.peer_run_id;
  assert.equal(state.peer_workflow_id, peerId, 'admission original peer');
  assert.ok(typeof peerRun === 'string' && peerRun && peerRun !== observation.run_id, 'admission distinct original peer run');
  for (const [key, status] of [['peer_before', 'pending'], ['peer_after_refusals', 'pending'], ['peer_after_cleanup', 'cancelled']]) {
    const run = state[key];
    assert.equal(run[embedded ? 'workflow_instance_id' : 'workflow_id'], peerId, 'admission original workflow identity');
    assert.equal(run[embedded ? 'id' : 'run_id'], peerRun, 'admission original run identity');
    assert.equal(run.workflow_type, definition.peer_workflow_type, 'admission original registered type');
    assert.equal(run.namespace, 'default', 'admission original namespace');
    assert.equal(run.status, status, 'admission durable state');
    if (status === 'pending') assert.equal(run.closed_at, null, 'refused mutation cannot close peer');
    else assert.ok(typeof run.closed_at === 'string' && Number.isFinite(Date.parse(run.closed_at)), 'cleanup original closure');
  }
  assert.deepStrictEqual(state.peer_after_refusals, state.peer_before, 'refused requests preserve complete original description');
  assert.deepStrictEqual(state.history_before.map(event => event.event_type), ['StartAccepted', 'WorkflowStarted'], 'unclaimed peer original start history');
  assert.deepStrictEqual(state.history_after_refusals, state.history_before, 'refused requests preserve complete original history');
  const history = state.history_after_cleanup;
  assert.deepStrictEqual(history.map(event => event.event_type), ['StartAccepted', 'WorkflowStarted', 'CancelRequested', 'WorkflowCancelled'], 'admission original cleanup history');
  assert.deepStrictEqual(history.slice(0, 2), state.history_before, 'cleanup preserves original start events');
  assert.deepStrictEqual(history.map(event => event.sequence), [1, 2, 3, 4], 'admission durable command sequence');
  for (const event of history) {
    assert.equal(event.payload.workflow_instance_id, peerId, 'admission history original workflow');
    assert.equal(event.payload.workflow_run_id, peerRun, 'admission history original run');
    assert.ok(typeof event.payload.workflow_command_id === 'string' && event.payload.workflow_command_id, 'admission original command identity');
  }
  assert.equal(history[0].payload.workflow_command_id, history[1].payload.workflow_command_id, 'admission same original start command');
  assert.equal(history[2].payload.workflow_command_id, history[3].payload.workflow_command_id, 'admission same original cleanup command');
  assert.notEqual(history[0].payload.workflow_command_id, history[2].payload.workflow_command_id, 'admission distinct start and cleanup');
  for (const event of history.slice(2)) assert.equal(event.payload.reason, definition.cleanup_reason, 'only authorized cleanup reason becomes durable');
  assert.equal(history[3].payload.failure_category, 'cancelled', 'admission cleanup failure category');
  if (embedded) {
    assert.deepStrictEqual(state.requests, [], 'HTTP admission is inapplicable to embedded execution');
    assert.equal(state.cleanup?.accepted, true, 'installed engine accepted original cleanup');
    assert.equal(state.cleanup.workflow_id, peerId, 'embedded cleanup original workflow');
    assert.equal(state.cleanup.run_id, peerRun, 'embedded cleanup original run');
    assert.equal(state.cleanup.command_sequence, 2, 'embedded cleanup original command order');
    assert.equal(state.cleanup.outcome, 'cancelled', 'embedded cleanup outcome');
  } else {
    assert.deepStrictEqual(state.requests.map(request => request.id), definition.requests.map(request => request.id), 'complete actual admission request inventory');
    const cluster = state.cluster?.worker_protocol;
    assert.equal(cluster?.version, '1.20', 'original advertised worker protocol');
    assert.ok(cluster.server_capabilities && typeof cluster.server_capabilities === 'object', 'original advertised capabilities');
    for (const [index, expected] of definition.requests.entries()) {
      const receipt = state.requests[index];
      for (const field of ['authorization', 'token', 'auth_token', 'request_headers']) assert.equal(receipt[field], undefined, 'admission receipts exclude credentials');
      const worker = expected.plane === 'worker';
      const read = expected.plane === 'read';
      const path = worker ? '/api/worker/workflow-tasks/poll' : `/api/workflows/${encodeURIComponent(peerId)}/runs/${encodeURIComponent(peerRun)}` + (read ? '' : '/cancel');
      const query = expected.query_namespace === null ? '' : '?namespace=' + encodeURIComponent(expected.query_namespace);
      assert.equal(receipt.path, path + query, 'admission targets original request context');
      assert.equal(receipt.method, read ? 'GET' : 'POST', 'admission original request method');
      for (const field of ['auth', 'header_namespace', 'query_namespace']) assert.equal(receipt[field], expected[field], 'admission original credential/namespace mode');
      assert.equal(receipt.requested_version, expected.version, 'admission original protocol selection');
      assert.deepStrictEqual(receipt.request, read ? null : worker ? {worker_id: peerId + '-refused-worker', task_queue: 'server-parity-v1', timeout_ms: 0} : {reason: definition.refused_reason}, 'original attempted mutation');
      assert.equal(receipt.status, expected.status, 'actual admission transport status');
      const body = receipt.response;
      assert.equal(body.reason ?? null, expected.reason, 'authentication/protocol/namespace precedence');
      assert.deepStrictEqual(receipt.headers, worker ? {control: '', worker: '1.20'} : {control: '2', worker: ''}, 'correct admission response plane');
      if (worker) {
        assert.equal(body.protocol_version, '1.20', 'worker admission response version');
        assert.deepStrictEqual(body.server_capabilities, cluster.server_capabilities, 'worker admission retains advertised capabilities');
        assert.equal(body.control_plane, undefined, 'worker response keeps its plane');
      } else {
        assert.equal(body.protocol_version, undefined, 'control response keeps its plane');
        assert.equal(body.server_capabilities, undefined, 'control response keeps worker capabilities separate');
        const metadata = body.control_plane;
        assert.equal(metadata?.schema, 'durable-workflow.v2.control-plane-response', 'admission control response schema');
        assert.equal(metadata.version, 1, 'admission control response version');
        assert.equal(metadata.operation, read ? 'describe_run' : 'cancel', 'admission original control operation');
        assert.equal(metadata.workflow_id, peerId, 'admission metadata original workflow');
        assert.equal(metadata.run_id, peerRun, 'admission metadata original run');
        assert.equal(metadata.contract?.schema, 'durable-workflow.v2.control-plane-response.contract', 'admission response field contract');
        assert.equal(metadata.contract.version, 1, 'admission response field contract version');
      }
      if (expected.status === 401) {
        assert.equal(body.message, 'Invalid or missing authentication token.', 'canonical authentication failure');
        for (const field of ['namespace', 'supported_version', 'requested_version']) assert.equal(body[field], undefined, 'authentication failure does not reveal later admission state');
      } else if (expected.status === 400) {
        assert.equal(body.supported_version, worker ? '1.20' : '2', 'original supported protocol');
        assert.equal(body.requested_version, expected.version, 'original refused protocol');
        assert.equal(body.namespace, undefined, 'protocol refusal precedes namespace lookup');
      } else if (expected.status === 404) {
        assert.equal(body.namespace, definition.unknown_namespace, 'original refused namespace');
        assert.equal(body.message, `Namespace '${definition.unknown_namespace}' does not exist.`, 'canonical unknown namespace failure');
      } else {
        assert.equal(body.namespace, 'default', 'resolved canonical default namespace');
        assert.equal(body.workflow_id, peerId, 'selected read original workflow');
        assert.equal(body.run_id, peerRun, 'selected read original run');
        assert.equal(body.status, 'pending', 'selected read preserves pending peer');
      }
    }
  }
  return {peer_status_before: 'pending', peer_status_after_refusals: 'pending', peer_status_after_cleanup: 'cancelled',
    history: history.map(({sequence, event_type}) => ({sequence, event_type}))};
}
