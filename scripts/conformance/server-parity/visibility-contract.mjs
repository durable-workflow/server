import assert from 'node:assert/strict';

export function checkVisibility(fixture, observation, workflowId, instant) {
  const state = observation.visibility;
  assert.ok(state && typeof state === 'object', 'actual visibility observation');
  const embedded = observation.mode === 'embedded';
  const definition = fixture.visibility;
  const peerId = workflowId + definition.peer_suffix;
  const peerRun = state.peer_run_id;
  assert.equal(state.peer_workflow_id, peerId, 'original visibility peer');
  assert.ok(typeof peerRun === 'string' && peerRun && peerRun !== observation.run_id, 'distinct original peer run');
  const expected = [
    {workflow_id: peerId, run_id: peerRun, workflow_type: definition.peer_workflow_type, status: 'pending', status_bucket: 'running', is_terminal: false},
    {workflow_id: workflowId, run_id: observation.run_id, workflow_type: fixture.workflow_type, status: 'completed', status_bucket: 'completed', is_terminal: true},
  ];
  function entity(row, wanted, after = false) {
    assert.ok(row && typeof row === 'object', 'actual visibility row');
    const id = embedded ? row.workflow_instance_id : row.workflow_id;
    const run = embedded ? row.id : row.run_id;
    assert.equal(id, wanted.workflow_id, 'visibility original workflow identity');
    assert.equal(run, wanted.run_id, 'visibility original run identity');
    for (const key of ['workflow_type', 'status', 'status_bucket', 'is_terminal'])
      assert.deepStrictEqual(row[key], wanted[key], `visibility ${key}`);
    assert.equal(row[embedded ? 'queue' : 'task_queue'], 'server-parity-v1', 'visibility original queue');
    assert.equal(row.business_key, null, 'visibility default business key');
    assert.equal(row.compatibility, null, 'visibility original unpinned build');
    if (embedded) {
      assert.equal(row.namespace, 'default', 'embedded visibility namespace');
      assert.equal(row.is_current_run, true, 'embedded original current run');
      assert.equal(instant(row.sort_timestamp), instant(row.started_at), 'embedded start-based ordering');
    } else {
      assert.equal(row.compatibility_status, 'compatible', 'visibility unpinned compatibility');
      assert.equal(row.compatibility_supported_in_fleet, true, 'visibility unpinned fleet support');
      assert.equal(row.compatibility_fleet_reason, null, 'visibility no fleet rejection');
      assert.deepStrictEqual(row.search_attributes, [], 'visibility no invented search attributes');
    }
    const original = wanted.workflow_id === workflowId ? observation.execution : state.peer_before;
    assert.equal(instant(row.started_at), instant(original.started_at), 'visibility original start timestamp');
    if (wanted.is_terminal) {
      assert.ok(instant(row.closed_at) >= instant(row.started_at), 'visibility terminal closure timestamp');
      const closure = after ? state.peer_after.closed_at : observation.execution.closed_at;
      assert.equal(instant(row.closed_at), instant(closure), 'visibility original closure timestamp');
    } else assert.equal(row.closed_at, null, 'visibility pending run remains open');
    return {workflow_id: id === workflowId ? '@workflow:1' : '@workflow:2',
      run_id: run === observation.run_id ? '@run:1' : '@run:2',
      workflow_type: wanted.workflow_type, status: wanted.status, status_bucket: wanted.status_bucket, is_terminal: wanted.is_terminal};
  }
  function response(document, rows, after = false, nextToken = null) {
    if (embedded) {
      assert.equal(document.length, rows.length, 'complete embedded visibility inventory');
      return document.map((row, index) => entity(row, rows[index], after));
    }
    assert.equal(document.workflow_count, rows.length, 'visibility page count');
    assert.equal(document.workflows.length, rows.length, 'complete HTTP visibility inventory');
    assert.equal(document.next_page_token, nextToken, 'original visibility cursor');
    const control = document.control_plane;
    assert.equal(control?.schema, 'durable-workflow.v2.control-plane-response', 'visibility response protocol');
    assert.equal(control.version, 1, 'visibility response protocol version');
    assert.equal(control.operation, 'list', 'visibility response operation');
    assert.equal(control.workflow_count, document.workflow_count, 'visibility protocol page count');
    assert.equal(control.next_page_token, document.next_page_token, 'visibility protocol cursor');
    return document.workflows.map((row, index) => entity(row, rows[index], after));
  }
  let initial;
  if (embedded) initial = response(state.summaries, expected);
  else {
    assert.equal(state.pages.length, 2, 'exact size-one visibility page inventory');
    assert.equal(state.pages[0].request_token, null, 'first visibility cursor');
    assert.equal(state.pages[0].response.next_page_token, 'MQ==', 'canonical offset-one cursor');
    assert.equal(state.pages[1].request_token, 'MQ==', 'original next-page cursor');
    const first = state.pages[0].response;
    initial = [...response(first, [expected[0]], false, 'MQ=='),
    ...response(state.pages[1].response, [expected[1]])];
  }
  assert.ok(instant(state.peer_before.started_at) >= instant(observation.execution.started_at), 'newer original peer sorts first');
  const filters = {};
  for (const [key, rows] of Object.entries({running: [expected[0]], completed: [expected[1]], failed: []}))
    filters[key] = response(state.filters[key], rows);
  for (const [key, rows] of Object.entries({pending: [expected[0]], completed: [expected[1]]}))
    assert.deepStrictEqual(response(state.type_filters[key], rows), filters[key === 'pending' ? 'running' : key], 'original workflow-type filter');
  assert.equal(state.peer_before.status, 'pending', 'original peer remains unclaimed');
  assert.equal(state.peer_after.status, 'cancelled', 'original pending peer cleanup');
  for (const execution of [state.peer_before, state.peer_after]) {
    assert.equal(execution[embedded ? 'id' : 'run_id'], peerRun, 'cleanup preserves original run');
    assert.equal(execution[embedded ? 'workflow_instance_id' : 'workflow_id'], peerId, 'cleanup preserves original workflow');
    assert.equal(execution.workflow_type, definition.peer_workflow_type, 'cleanup preserves registered type');
    assert.equal(execution.namespace, 'default', 'cleanup original namespace');
  }
  if (embedded) {
    assert.equal(state.cleanup?.accepted, true, 'installed engine accepted cleanup');
    assert.equal(state.cleanup.workflow_id, peerId, 'engine cleanup original workflow');
    assert.equal(state.cleanup.run_id, peerRun, 'engine cleanup original run');
    assert.equal(state.cleanup.command_sequence, 2, 'engine cleanup command order');
    assert.equal(state.cleanup.outcome, 'cancelled', 'engine cleanup outcome');
  } else {
    assert.equal(state.peer_before.status_bucket, 'running', 'pending describe canonical bucket');
    assert.equal(state.peer_after.status_bucket, 'failed', 'cancelled describe canonical bucket');
    assert.equal(state.alias.status, 422, 'HTTP rejects pending status alias');
    assert.equal(state.alias.reason, 'validation_failed', 'HTTP canonical validation refusal');
    assert.ok(state.alias.response.validation_errors.status.length > 0, 'HTTP names invalid status field');
    const request = state.cluster?.control_plane?.request_contract;
    assert.equal(request?.schema, 'durable-workflow.v2.control-plane-request.contract', 'CLI advertised request contract');
    assert.equal(request.version, 1, 'CLI request contract version');
    assert.deepStrictEqual(request.operations.list.fields.status, {
      canonical_values: ['running', 'completed', 'failed'],
      rejected_aliases: {cancelled: 'failed', terminated: 'failed', pending: 'running', waiting: 'running'},
    }, 'CLI canonical status manifest');
  }
  const cancelled = {...expected[0], status: 'cancelled', status_bucket: 'failed', is_terminal: true};
  const after = response(state.after_cleanup, [cancelled], true);
  const history = state.peer_history;
  assert.deepStrictEqual(history.map(event => event.event_type),
    ['StartAccepted', 'WorkflowStarted', 'CancelRequested', 'WorkflowCancelled'], 'unclaimed peer complete original history');
  assert.deepStrictEqual(history.map(event => event.sequence), [1, 2, 3, 4], 'unclaimed peer durable event sequence');
  for (const event of history) {
    assert.equal(event.payload.workflow_instance_id, peerId, 'peer history original workflow');
    assert.equal(event.payload.workflow_run_id, peerRun, 'peer history original run');
    assert.ok(typeof event.payload.workflow_command_id === 'string' && event.payload.workflow_command_id, 'peer durable command identity');
  }
  assert.equal(history[0].payload.workflow_command_id, history[1].payload.workflow_command_id, 'peer original start command');
  assert.equal(history[2].payload.workflow_command_id, history[3].payload.workflow_command_id, 'peer original cleanup command');
  assert.notEqual(history[0].payload.workflow_command_id, history[2].payload.workflow_command_id, 'distinct peer start and cleanup');
  for (const event of history.slice(2)) assert.equal(event.payload.reason, definition.cleanup.reason, 'peer original cleanup reason');
  assert.equal(history[3].payload.failure_category, 'cancelled', 'peer cancellation category');
  if (!embedded) checkCli();

  return {initial, filters, after_cleanup: after,
    peer_history: history.map(({sequence, event_type}) => ({sequence, event_type}))};

  function checkCli() {
    const cli = state.cli;
    const artifact = definition.cli;
    assert.equal(cli?.sha256, artifact.phar_sha256, 'unchanged published CLI bytes');
    const commands = {
      version: ['--version'],
      list: ['workflow:list', '--query='+workflowId, '--limit=2', '--output=json'],
      running: ['workflow:list', '--query='+workflowId, '--status=running', '--limit=2', '--output=json'],
      completed: ['workflow:list', '--query='+workflowId, '--status=completed', '--limit=2', '--output=json'],
      alias: ['workflow:list', '--query='+workflowId, '--status='+definition.rejected_status_alias, '--output=json'],
      describe: ['workflow:describe', workflowId, '--run-id='+observation.run_id, '--output=json'],
      history: ['workflow:history', workflowId, observation.run_id, '--page-size=1', '--output=json'],
    };
    assert.deepStrictEqual(Object.keys(cli.commands).sort(), Object.keys(commands).sort(), 'complete actual CLI command inventory');
    for (const [name, arguments_] of Object.entries(commands)) {
      const receipt = cli.commands[name];
      assert.deepStrictEqual(receipt.arguments, arguments_, 'CLI targets original executions');
      assert.equal(receipt.timed_out, false, 'CLI completed within observation budget');
      assert.equal(receipt.exit_code, name === 'alias' ? 2 : 0, 'actual CLI exit status');
      if (name === 'alias') {
        assert.match(receipt.stderr, /Server contract rejects --status value \[pending\]; use \[running\]\./, 'CLI refuses alias through advertised manifest');
        continue;
      }
      assert.equal(receipt.stderr, '', 'successful CLI has no error output');
      if (name === 'version') {
        assert.ok(receipt.stdout.startsWith(`dw ${artifact.version} (commit ${artifact.source_commit.slice(0, 12)}, built `), 'actual published CLI version and source');
        continue;
      }
      assert.deepStrictEqual(receipt.document, JSON.parse(receipt.stdout), 'CLI document matches original process output');
      assert.equal(receipt.document.namespace, 'default', 'CLI namespace context');
      if (['list', 'running', 'completed'].includes(name)) {
        const rows = name === 'list' ? expected : name === 'running' ? [expected[0]] : [expected[1]];
        response(receipt.document, rows);
        for (const row of receipt.document.workflows) assert.equal(row.namespace, 'default', 'CLI row namespace context');
      } else if (name === 'describe') {
        const document = receipt.document;
        readContract(document, 'describe_run', ['workflow_id'], ['run_id']);
        for (const key of ['workflow_id', 'run_id', 'workflow_type', 'status', 'status_bucket', 'is_terminal'])
          assert.deepStrictEqual(document[key], expected[1][key], `CLI describe ${key}`);
        assert.equal(document.is_current_run, true, 'CLI describes original current run');
        assert.equal(document.run_number, 1, 'CLI original run number');
        assert.equal(document.run_count, 1, 'CLI actual run inventory');
        assert.equal(document.task_queue, 'server-parity-v1', 'CLI original queue');
        assert.equal(document.payload_codec, 'avro', 'CLI original durable codec');
        assert.deepStrictEqual(receipt.typed_input_preview, observation.typed_input, 'CLI exact input preview types');
        assert.deepStrictEqual(receipt.typed_output_preview, observation.typed_output, 'CLI exact output preview types');
      } else {
        readContract(receipt.document, 'history', ['workflow_id', 'run_id'], ['next_page_token']);
        assert.equal(receipt.document.control_plane.next_page_token, null, 'CLI last original history page exhausted');
        assert.equal(receipt.document.workflow_id, workflowId, 'CLI history original workflow');
        assert.equal(receipt.document.run_id, observation.run_id, 'CLI history original run');
        assert.equal(receipt.document.next_page_token, undefined, 'CLI consumed all original history pages');
        assert.equal(receipt.decoded_events.length, observation.events.length, 'CLI complete paginated history');
        assert.deepStrictEqual(receipt.document.events.map(event => event.payload), observation.events.map(event => event.payload), 'CLI unchanged original history payloads');
        for (const [index, event] of receipt.decoded_events.entries()) {
          assert.equal(event.sequence, index + 1, 'CLI original history sequence');
          assert.equal(event.event_type, observation.events[index].event_type, 'CLI original history event');
          assert.deepStrictEqual(event.typed_decoded, observation.events[index].typed_decoded, 'CLI exact history payload types');
        }
      }
    }
  }

  function readContract(document, operation, required, success) {
    const control = document.control_plane;
    assert.equal(control?.schema, 'durable-workflow.v2.control-plane-response', 'CLI read response schema');
    assert.equal(control.version, 1, 'CLI read response version');
    assert.equal(control.operation, operation, 'CLI read response operation');
    assert.equal(control.workflow_id, workflowId, 'CLI metadata original workflow');
    assert.equal(control.run_id, observation.run_id, 'CLI metadata original run');
    const contract = control.contract;
    assert.equal(contract?.schema, 'durable-workflow.v2.control-plane-response.contract', 'CLI read contract schema');
    assert.equal(contract.version, 1, 'CLI read contract version');
    assert.deepStrictEqual(contract.required_fields, required, 'CLI read required field contract');
    assert.deepStrictEqual(contract.success_fields, success, 'CLI read success field contract');
    assert.equal(contract.legacy_field_policy, 'reject_non_canonical', 'CLI canonical response field policy');
    assert.deepStrictEqual(contract.legacy_fields, {query: 'query_name', signal: 'signal_name', update: 'update_name', wait_policy: 'wait_for'}, 'CLI canonical response field names');
    assert.deepStrictEqual(contract.rejection_fields, ['workflow_id', 'run_id', 'reason', 'message', 'retryable', 'error_id', 'exception'], 'CLI read rejection field contract');
    assert.deepStrictEqual(contract.rejection_reasons, ['control_plane_internal_error'], 'CLI read rejection reason contract');
  }
}
