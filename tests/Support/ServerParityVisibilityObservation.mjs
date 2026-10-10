// Deterministic comparator examples; these are not execution receipts.
export function visibilityObservation(fixture, mode) {
  const clone = value => structuredClone(value);
  const embedded = mode === 'embedded';
  const workflowId = 'visibility-reference-current-runs';
  const runId = `${mode}-root-run`;
  const peerId = workflowId + fixture.visibility.peer_suffix;
  const peerRun = `${mode}-peer-run`;
  const time = seconds => new Date(Date.UTC(2026, 0, 1) + seconds * 1000).toISOString();
  const typedInput = {type: 'list', value: [clone(fixture.typed_value)]};
  const start = {workflow_instance_id: workflowId, workflow_run_id: runId,
    workflow_type: fixture.workflow_type, workflow_command_id: `${mode}-root-start`};
  const events = [
    {sequence: 1, event_type: 'StartAccepted', timestamp: time(0), payload: {...start, outcome: 'started_new'}, typed_decoded: {}},
    {sequence: 2, event_type: 'WorkflowStarted', timestamp: time(0), payload: {...start,
      execution_timeout_seconds: 3600, run_timeout_seconds: 600, execution_deadline_at: time(3600), run_deadline_at: time(600)}, typed_decoded: {}},
    {sequence: 3, event_type: 'WorkflowCompleted', timestamp: time(1), payload: {},
      decoded: {output: clone(fixture.input)}, typed_decoded: {output: clone(fixture.typed_value)}},
  ];
  const execution = {workflow_id: workflowId, run_id: runId, workflow_type: fixture.workflow_type,
    namespace: 'default', task_queue: 'server-parity-v1', status: 'completed', status_bucket: 'completed',
    is_terminal: true, is_current_run: true, run_number: 1, run_count: 1, payload_codec: 'avro',
    started_at: time(0), closed_at: time(1), input: [clone(fixture.input)], output: clone(fixture.input)};
  const peer = {workflow_id: peerId, run_id: peerRun, id: peerRun, workflow_instance_id: peerId,
    workflow_type: fixture.visibility.peer_workflow_type, namespace: 'default', status: 'pending',
    status_bucket: 'running', started_at: time(2), closed_at: null};
  const row = (id, run, type, status, started, closed) => {
    const terminal = ['completed', 'cancelled'].includes(status);
    const common = {workflow_type: type, status, status_bucket: status === 'pending' ? 'running' : status === 'completed' ? 'completed' : 'failed',
      is_terminal: terminal, business_key: null, compatibility: null, started_at: time(started), closed_at: closed === null ? null : time(closed)};
    return {...common, ...(embedded ? {workflow_instance_id: id, id: run, queue: 'server-parity-v1',
      namespace: 'default', is_current_run: true, sort_timestamp: time(started)}
      : {workflow_id: id, run_id: run, task_queue: 'server-parity-v1', compatibility_status: 'compatible',
        compatibility_supported_in_fleet: true, compatibility_fleet_reason: null, search_attributes: []})};
  };
  const root = row(workflowId, runId, fixture.workflow_type, 'completed', 0, 1);
  const pending = row(peerId, peerRun, fixture.visibility.peer_workflow_type, 'pending', 2, null);
  const cancelled = row(peerId, peerRun, fixture.visibility.peer_workflow_type, 'cancelled', 2, 3);
  const page = (rows, token = null) => embedded ? clone(rows) : {workflows: clone(rows), workflow_count: rows.length, next_page_token: token,
    control_plane: {schema: 'durable-workflow.v2.control-plane-response', version: 1, operation: 'list', workflow_count: rows.length, next_page_token: token}};
  const peerHistory = ['StartAccepted', 'WorkflowStarted', 'CancelRequested', 'WorkflowCancelled'].map((event_type, index) => ({
    sequence: index + 1, event_type, timestamp: time(index < 2 ? 2 : 3), payload: {
      workflow_instance_id: peerId, workflow_run_id: peerRun, workflow_command_id: `${mode}-peer-${index < 2 ? 'start' : 'cancel'}`,
      ...(index >= 2 ? {reason: fixture.visibility.cleanup.reason} : {}), ...(index === 3 ? {failure_category: 'cancelled'} : {}),
    },
  }));
  const state = {peer_workflow_id: peerId, peer_run_id: peerRun, peer_before: clone(peer),
    peer_after: {...clone(peer), status: 'cancelled', status_bucket: 'failed', closed_at: time(3)}, peer_history: peerHistory,
    filters: {running: page([pending]), completed: page([root]), failed: page([])},
    type_filters: {pending: page([pending]), completed: page([root])}, after_cleanup: page([cancelled])};
  if (embedded) Object.assign(state, {summaries: [pending, root], cleanup: {accepted: true, workflow_id: peerId, run_id: peerRun, command_sequence: 2, outcome: 'cancelled'}});
  else {
    state.pages = [{request_token: null, response: page([pending], 'MQ==')}, {request_token: 'MQ==', response: page([root])}];
    state.alias = {status: 422, reason: 'validation_failed', response: {validation_errors: {status: ['The selected status is invalid.']}}};
    state.cluster = {control_plane: {request_contract: {schema: 'durable-workflow.v2.control-plane-request.contract', version: 1,
      operations: {list: {fields: {status: {canonical_values: ['running', 'completed', 'failed'],
        rejected_aliases: {cancelled: 'failed', terminated: 'failed', pending: 'running', waiting: 'running'}}}}}}}};
    const commands = {
      version: ['--version'],
      list: ['workflow:list', '--query='+workflowId, '--limit=2', '--output=json'],
      running: ['workflow:list', '--query='+workflowId, '--status=running', '--limit=2', '--output=json'],
      completed: ['workflow:list', '--query='+workflowId, '--status=completed', '--limit=2', '--output=json'],
      alias: ['workflow:list', '--query='+workflowId, '--status=pending', '--output=json'],
      describe: ['workflow:describe', workflowId, '--run-id='+runId, '--output=json'],
      history: ['workflow:history', workflowId, runId, '--page-size=1', '--output=json'],
    };
    const namespacePage = rows => {
      const document = page(rows);
      document.namespace = 'default';
      for (const item of document.workflows) item.namespace = 'default';
      return document;
    };
    const documents = {list: namespacePage([pending, root]), running: namespacePage([pending]),
      completed: namespacePage([root]), describe: clone(execution),
      history: {workflow_id: workflowId, run_id: runId, namespace: 'default', events: clone(events)}};
    for (const [name, operation, required, success] of [
      ['describe', 'describe_run', ['workflow_id'], ['run_id']],
      ['history', 'history', ['workflow_id', 'run_id'], ['next_page_token']],
    ]) documents[name].control_plane = {
      schema: 'durable-workflow.v2.control-plane-response', version: 1, operation,
      workflow_id: workflowId, run_id: runId, ...(name === 'history' ? {next_page_token: null} : {}),
      contract: {schema: 'durable-workflow.v2.control-plane-response.contract', version: 1,
        legacy_field_policy: 'reject_non_canonical', legacy_fields: {query: 'query_name', signal: 'signal_name', update: 'update_name', wait_policy: 'wait_for'},
        required_fields: required, success_fields: success,
        rejection_fields: ['workflow_id', 'run_id', 'reason', 'message', 'retryable', 'error_id', 'exception'], rejection_reasons: ['control_plane_internal_error']},
    };
    state.cli = {sha256: fixture.visibility.cli.phar_sha256, commands: {}};
    for (const [name, arguments_] of Object.entries(commands)) {
      state.cli.commands[name] = {arguments: arguments_, exit_code: name === 'alias' ? 2 : 0, timed_out: false,
        stdout: name === 'version' ? `dw 2.2.0 (commit ${fixture.visibility.cli.source_commit.slice(0, 12)}, built 2026-01-01T00:00:00Z)\n`
          : name === 'alias' ? '' : JSON.stringify(documents[name]),
        stderr: name === 'alias' ? 'Server contract rejects --status value [pending]; use [running].\n' : '',
        ...(documents[name] ? {document: clone(documents[name])} : {}),
        ...(name === 'describe' ? {typed_input_preview: clone(typedInput), typed_output_preview: clone(fixture.typed_value)} : {}),
        ...(name === 'history' ? {decoded_events: clone(events)} : {}),
      };
    }
  }
  return {mode, sdk_php: '2.2.6', ...(embedded ? {workflow_package: '2.5.5'} : {}),
    workflow_id: workflowId, run_id: runId, workflow_type: fixture.workflow_type,
    namespace: 'default', task_queue: 'server-parity-v1', status: 'completed', payload_codec: 'avro',
    input: [clone(fixture.input)], output: clone(fixture.input), typed_input: typedInput,
    typed_output: clone(fixture.typed_value), execution, events, visibility: state};
}
