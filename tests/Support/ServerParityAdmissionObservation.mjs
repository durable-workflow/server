// Deterministic comparator examples; these are not execution receipts.
export function admissionObservation(fixture, mode) {
  const clone = value => structuredClone(value);
  const embedded = mode === 'embedded';
  const workflowId = 'admission-reference-auth-namespace';
  const runId = `${mode}-root-run`;
  const peerId = workflowId + fixture.admission.peer_suffix;
  const peerRun = `${mode}-peer-run`;
  const time = seconds => new Date(Date.UTC(2026, 0, 1) + seconds * 1000).toISOString();
  const start = {workflow_instance_id: workflowId, workflow_run_id: runId,
    workflow_type: fixture.workflow_type, workflow_command_id: `${mode}-root-start`};
  const events = [
    {sequence: 1, event_type: 'StartAccepted', timestamp: time(0), payload: {...start, outcome: 'started_new'}, typed_decoded: {}},
    {sequence: 2, event_type: 'WorkflowStarted', timestamp: time(0), payload: {...start,
      execution_timeout_seconds: 3600, run_timeout_seconds: 600, execution_deadline_at: time(3600), run_deadline_at: time(600)}, typed_decoded: {}},
    {sequence: 3, event_type: 'WorkflowCompleted', timestamp: time(1), payload: {},
      decoded: {output: clone(fixture.input)}, typed_decoded: {output: clone(fixture.typed_value)}},
  ];
  const peer = {workflow_id: peerId, run_id: peerRun, id: peerRun, workflow_instance_id: peerId,
    workflow_type: fixture.admission.peer_workflow_type, namespace: 'default', status: 'pending',
    started_at: time(2), closed_at: null};
  const history = ['StartAccepted', 'WorkflowStarted', 'CancelRequested', 'WorkflowCancelled'].map((event_type, index) => ({
    sequence: index + 1, event_type, timestamp: time(index < 2 ? 2 : 3), payload: {
      workflow_instance_id: peerId, workflow_run_id: peerRun, workflow_command_id: `${mode}-peer-${index < 2 ? 'start' : 'cancel'}`,
      ...(index >= 2 ? {reason: fixture.admission.cleanup_reason} : {}), ...(index === 3 ? {failure_category: 'cancelled'} : {}),
    },
  }));
  const capabilities = {workflow_task_poll_request_idempotency: true, query_task_poll_request_idempotency: false};
  const state = {peer_workflow_id: peerId, peer_run_id: peerRun, peer_before: clone(peer), peer_after_refusals: clone(peer),
    peer_after_cleanup: {...clone(peer), status: 'cancelled', closed_at: time(3)},
    history_before: clone(history.slice(0, 2)), history_after_refusals: clone(history.slice(0, 2)), history_after_cleanup: history,
    requests: []};
  if (embedded) state.cleanup = {accepted: true, workflow_id: peerId, run_id: peerRun, command_sequence: 2, outcome: 'cancelled'};
  else {
    if (fixture.admission.cleanup_auth) {
      state.cleanup_auth = fixture.admission.cleanup_auth;
      state.cleanup = {workflow_id: peerId, run_id: peerRun, outcome: 'cancelled'};
    }
    state.cluster = {worker_protocol: {version: '1.20', server_capabilities: clone(capabilities)}};
    state.requests = fixture.admission.requests.map(expected => {
      const worker = expected.plane === 'worker';
      const read = expected.plane === 'read';
      const body = read && expected.status === 200 ? clone(peer) : {reason: expected.reason};
      if (expected.status === 401) body.message = 'Invalid or missing authentication token.';
      else if (expected.status === 403) Object.assign(body, {
        message: 'Authenticated role is not allowed to access this endpoint.',
        role: expected.role, allowed_roles: clone(expected.allowed_roles),
      });
      else if (expected.status === 400) {
        const missing = expected.version === null;
        Object.assign(body, {supported_version: worker ? '1.20' : '2', requested_version: expected.version,
          [worker ? 'error' : 'message']: missing ? worker ? 'Missing worker protocol version header.' : 'Missing control-plane version header.'
            : worker ? 'Unsupported worker protocol version.' : 'Unsupported control-plane version.',
          remediation: missing ? worker ? 'Send the X-Durable-Workflow-Protocol-Version: 1.20 header on worker protocol requests.'
            : 'Send the X-Durable-Workflow-Control-Plane-Version: 2 header on control-plane requests.'
            : worker ? `Worker requested protocol version ${expected.version}; this server supports 1.20. Workers may target any 1.x version with x ≤ 20. Upgrade the worker to a release that targets a compatible version, or connect to a server that matches.`
              : `Client requested control-plane version ${expected.version}; this server only supports 2. Upgrade the client to a release that targets control-plane 2, or connect to a server that supports ${expected.version}.`});
      }
      else if (expected.status === 404) Object.assign(body, {namespace: fixture.admission.unknown_namespace,
        message: `Namespace '${fixture.admission.unknown_namespace}' does not exist.`,
        remediation: 'Register the namespace via POST /api/namespaces, or send an X-Namespace header naming an existing namespace.'});
      if (worker) Object.assign(body, {protocol_version: '1.20', server_capabilities: clone(capabilities)});
      else {
        Object.assign(body, {workflow_id: peerId, run_id: peerRun});
        body.control_plane = {schema: 'durable-workflow.v2.control-plane-response', version: 1,
        operation: read ? 'describe_run' : 'cancel', workflow_id: peerId, run_id: peerRun,
        contract: {schema: 'durable-workflow.v2.control-plane-response.contract', version: 1,
          legacy_field_policy: 'reject_non_canonical',
          legacy_fields: {query: 'query_name', signal: 'signal_name', update: 'update_name', wait_policy: 'wait_for'},
          required_fields: ['workflow_id'], success_fields: read ? ['run_id'] : ['outcome'],
          rejection_fields: read ? ['workflow_id', 'run_id', 'reason', 'message', 'retryable', 'error_id', 'exception']
            : ['workflow_id', 'run_id', 'reason', 'message', 'remediation'],
          rejection_reasons: [read ? 'control_plane_internal_error' : 'v1_projection_read_only']}};
        for (const field of ['reason', 'message', 'namespace', 'remediation']) {
          if (Object.hasOwn(body, field)) body.control_plane[field] = clone(body[field]);
        }
      }
      return {id: expected.id, method: read ? 'GET' : 'POST',
        path: (worker ? '/api/worker/workflow-tasks/poll' : `/api/workflows/${peerId}/runs/${peerRun}` + (read ? '' : '/cancel'))
          + (expected.query_namespace === null ? '' : '?namespace=' + expected.query_namespace),
        request: read ? null : worker ? {worker_id: peerId + '-refused-worker', task_queue: 'server-parity-v1', timeout_ms: 0}
          : {reason: fixture.admission.refused_reason}, auth: expected.auth, requested_version: expected.version,
        header_namespace: expected.header_namespace, query_namespace: expected.query_namespace,
        status: expected.status, response: body, headers: worker ? {control: '', worker: '1.20'} : {control: '2', worker: ''}};
    });
  }
  return {mode, sdk_php: '2.2.6', ...(embedded ? {workflow_package: '2.5.5'} : {}),
    workflow_id: workflowId, run_id: runId, workflow_type: fixture.workflow_type, namespace: 'default',
    task_queue: 'server-parity-v1', status: 'completed', payload_codec: 'avro',
    input: [clone(fixture.input)], output: clone(fixture.input), typed_input: {type: 'list', value: [clone(fixture.typed_value)]},
    typed_output: clone(fixture.typed_value), execution: {started_at: time(0)}, events, admission: state};
}
