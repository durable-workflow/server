import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkSdkSignalDirectionPackages} from './sdk-signal-direction-contract.mjs';

const artifacts = JSON.parse(readFileSync(new URL('../../../tests/Fixtures/ServerParityProfiles/published-sdk-selected-run-signals/artifacts.json', import.meta.url), 'utf8'));
const required = ['activity_attempts', 'activity_executions', 'workflow_child_calls', 'workflow_child_projection_repairs',
  'workflow_commands', 'workflow_durable_stream_items', 'workflow_durable_streams', 'workflow_failures', 'workflow_history_events',
  'workflow_inbound_stream_items', 'workflow_inbound_streams', 'workflow_instances', 'workflow_links', 'workflow_memos',
  'workflow_messages', 'workflow_run_lineage_entries', 'workflow_run_summaries', 'workflow_run_timeline_entries',
  'workflow_run_timer_entries', 'workflow_run_timers', 'workflow_run_waits', 'workflow_runs', 'workflow_schedule_history_events',
  'workflow_search_attributes', 'workflow_signal_records', 'workflow_tasks', 'workflow_update_validation_tasks', 'workflow_updates'];
const rows = (state, table) => state.tables[table].rows;
const nonempty = value => assert(typeof value === 'string' && value.length > 0);
const frame = value => typeof value === 'string' ? value : (assert.equal(value?.codec, 'avro'), value.blob);
const without = (row, fields) => Object.fromEntries(Object.entries(row).filter(([key]) => !fields.includes(key)));
const storedPayload = value => {
  if (Array.isArray(value)) return value.map(storedPayload);
  if (value && typeof value === 'object') {
    if (value.codec === 'avro' && typeof value.blob === 'string') {
      assert.deepEqual(Object.keys(value).sort(), ['blob', 'codec'], 'only the exact Avro frame wrapper may be projected');
      return value.blob;
    }
    return Object.fromEntries(Object.entries(value).map(([key, child]) => [key, storedPayload(child)]));
  }
  return value;
};

export function selectedRunSignalFixture(fixture, peer = false) {
  const {selected_run_signal_pair: pair, ...run} = fixture;
  return {...run, selected_run_signal_run: peer ? 'peer' : 'primary', namespace_signal_run: peer ? 'peer' : 'primary',
    selected_run_signal_validation: pair.invalid_arguments,
    ...(peer ? {namespace: pair.peer_namespace, signal_value: pair.peer_signal_value, typed_signal_value: pair.typed_peer_signal_value} : {})};
}

function checkState(state, ids, runs) {
  assert.equal(state.schema, 'durable-workflow.selected-run-signal-state/v1');
  assert.equal(state.read_only, true);
  assert.deepEqual(state.workflow_ids, ids); assert.deepEqual(state.run_ids, runs);
  required.forEach(table => assert(state.tables[table], 'required persisted table ' + table));
  for (const [table, value] of Object.entries(state.tables)) {
    assert(/^(workflow_[a-z_]+|activity_attempts|activity_executions)$/.test(table)); assert(Array.isArray(value.columns) && value.columns.length > 0);
    assert.equal(new Set(value.columns).size, value.columns.length);
    for (const row of value.rows) assert.deepEqual(Object.keys(row), value.columns, 'complete raw row columns');
    if (value.columns.includes('id')) assert.equal(new Set(value.rows.map(row => row.id)).size, value.rows.length, 'unique persisted row identity');
  }
  assert.equal(rows(state, 'workflow_instances').length, 2);
  assert.equal(rows(state, 'workflow_runs').length, 2);
  ids.forEach((id, index) => {
    const instance = rows(state, 'workflow_instances').find(row => row.id === id);
    const run = rows(state, 'workflow_runs').find(row => row.id === runs[index]);
    assert(instance && run); assert.equal(instance.current_run_id, run.id);
    assert.equal(instance.run_count, 1); assert.equal(run.workflow_instance_id, id);
  });
}

function checkRejectionMutation(control, ids, runs, receipt, reason, outcome, commandSequence) {
  const before = control.stored_before, after = control.stored_after, index = control.target_run;
  assert.deepEqual(Object.keys(before.tables), Object.keys(after.tables), 'unchanged table inventory');
  const command = rows(after, 'workflow_commands').find(row => row.id === receipt.command_id);
  assert(command, 'refusal points to persisted rejected command');
  assert.equal(command.workflow_instance_id, ids[index]); assert.equal(command.workflow_run_id, runs[index]);
  assert.equal(command.resolved_workflow_run_id, runs[index]);
  assert.equal(command.command_type, 'signal'); assert.equal(command.status, 'rejected');
  assert.equal(command.outcome, outcome); assert.equal(command.rejection_reason, reason);
  assert.equal(command.command_sequence, commandSequence); assert.equal(command.message_sequence, commandSequence - 1);
  assert.equal(command.accepted_at, null); assert.equal(command.applied_at, null); nonempty(command.rejected_at);
  const signal = rows(after, 'workflow_signal_records').find(row => row.workflow_command_id === command.id);
  assert(signal); assert.equal(signal.status, 'rejected'); assert.equal(signal.outcome, outcome);
  assert.equal(signal.rejection_reason, reason); assert.equal(signal.workflow_run_id, runs[index]);
  assert.equal(signal.workflow_instance_id, ids[index]); assert.equal(signal.command_sequence, commandSequence);
  assert.equal(signal.signal_name, control.phase === 'unknown-signal' ? 'undeclared' : 'payload');
  for (const field of ['received_at', 'applied_at', 'signal_wait_id', 'workflow_sequence']) assert.equal(signal[field], null);
  nonempty(signal.rejected_at); assert.equal(signal.closed_at, signal.rejected_at);
  for (const [table, value] of Object.entries(before.tables)) {
    const later = after.tables[table]; assert.deepEqual(later.columns, value.columns);
    if (['workflow_commands', 'workflow_signal_records'].includes(table)) {
      const added = table === 'workflow_commands' ? command : signal;
      assert.equal(later.rows.length, value.rows.length + 1, 'one durable rejected row in ' + table);
      assert.deepEqual(later.rows.filter(row => row.id !== added.id), value.rows, 'every prior row unchanged');
    } else if (table === 'workflow_runs' || table === 'workflow_instances') {
      assert.equal(later.rows.length, value.rows.length);
      for (const row of value.rows) {
        const next = later.rows.find(item => item.id === row.id); assert(next);
        const target = row.id === (table === 'workflow_runs' ? runs[index] : ids[index]);
        if (!target) { assert.deepEqual(next, row, 'neighbor remains byte-exact'); continue; }
        const counter = table === 'workflow_runs' ? 'last_command_sequence' : 'last_message_sequence';
        assert.equal(next[counter], row[counter] + 1);
        assert.deepEqual(without(next, [counter, 'updated_at']), without(row, [counter, 'updated_at']), 'only documented counter/timestamp may change');
      }
    } else assert.deepEqual(later.rows, value.rows, 'rejection preserves all other persisted state: ' + table);
  }
}

export function checkSelectedRunSignalPair(fixture, observation, workflowId, checkRun) {
  const spec = fixture.selected_run_signal_pair, pair = observation.selected_run_signal_pair;
  const validatesArguments = fixture.sdk_signal_direction.workflow !== 'rust';
  const terminalSequence = validatesArguments ? 6 : 5;
  assert.deepEqual(spec.invalid_arguments, validatesArguments ? {applicable: true, strategy: 'omit_required_argument'}
    : {applicable: false, reason: 'published_rust_signal_contract_is_optional_variadic_untyped'});
  assert.equal(fixture.namespace, 'parity-signals-left'); assert.equal(spec.peer_namespace, 'parity-signals-right');
  assert.equal(spec.run_budget_seconds, 35); assert.equal(spec.pair_budget_seconds, 70);
  assert.equal(spec.same_workflow_type_and_queue, true); assert.equal(spec.both_original_runs_wait_before_delivery, true);
  assert.equal(spec.foreign_signal_target, 'selected_original_workflow_run_id');
  for (const [key, value] of Object.entries({foreign_signal_expected_status: 404, mismatched_run_expected_status: 409,
    unknown_signal_expected_status: 404, invalid_signal_expected_status: 422, inactive_signal_expected_status: 409})) assert.equal(spec[key], value);
  assert.equal(spec.typed_peer_signal_value.value.count.value, '9007199254740995');
  assert.equal(pair.applicable, true); assert.equal(pair.run_budget_seconds, 35); assert.equal(pair.pair_budget_seconds, 70);
  const fixtures = [selectedRunSignalFixture(fixture), selectedRunSignalFixture(fixture, true)];
  const ids = [workflowId, workflowId + ':peer'];
  const primary = {...observation}; delete primary.selected_run_signal_pair;
  const observations = [primary, pair.peer], runs = observations.map(run => run.run_id);
  assert.equal(new Set(runs).size, 2);
  checkSdkSignalDirectionPackages(fixtures[1], observations[1], artifacts);
  const projections = observations.map((run, index) => checkRun(fixtures[index], run, ids[index]));
  assert.equal(pair.initial.length, 2); assert.equal(pair.run_budgets.length, 2);
  assert(Number.isFinite(pair.pair_started_at_unix) && Number.isFinite(pair.pair_finished_at_unix));
  assert(pair.pair_finished_at_unix >= pair.pair_started_at_unix && pair.pair_finished_at_unix - pair.pair_started_at_unix <= 70);
  for (const [index, run] of observations.entries()) {
    const initial = pair.initial[index], budget = pair.run_budgets[index];
    for (const field of ['workflow_id', 'run_id', 'workflow_type', 'namespace', 'task_queue']) assert.equal(initial[field], run[field]);
    assert.equal(initial.status, 'waiting'); assert.deepEqual(initial.events, run.events.slice(0, 3));
    assert.deepEqual(initial.typed_input, run.typed_input); assert.deepEqual(initial.typed_output, {type: 'null', value: null});
    for (const key of ['started_at_unix', 'deadline_at_unix', 'finished_at_unix']) assert(Number.isFinite(budget[key]));
    assert(Math.abs(budget.deadline_at_unix - budget.started_at_unix - 35) < 0.000001);
    assert(budget.started_at_unix >= pair.pair_started_at_unix && budget.finished_at_unix <= pair.pair_finished_at_unix);
    assert(budget.finished_at_unix >= budget.started_at_unix && budget.finished_at_unix <= budget.deadline_at_unix);
  }
  assert.deepEqual(pair.controls.map(control => [control.phase, control.target_run]), [
    ['foreign-signal', 0], ['mismatched-run', 0], ['foreign-signal', 1], ['mismatched-run', 1],
    ['unknown-signal', 0], ...(validatesArguments ? [['invalid-signal', 0]] : []),
    ['unknown-signal', 1], ...(validatesArguments ? [['invalid-signal', 1]] : []), ['inactive-signal', 0], ['inactive-signal', 1]]);
  const pids = [];
  for (const control of pair.controls) {
    const index = control.target_run, guard = ['foreign-signal', 'mismatched-run'].includes(control.phase);
    const selected = control.phase === 'mismatched-run' ? runs[1 - index] : runs[index];
    assert.equal(control.selected_run_id, selected);
    assert.equal(control.scope_run, control.phase === 'foreign-signal' ? 1 - index : index);
    assert.equal(control.before.length, 2); assert.equal(control.after.length, 2);
    for (const [scope, before] of control.before.entries()) {
      const after = control.after[scope];
      for (const value of [before, after]) {
        for (const field of ['workflow_id', 'run_id', 'workflow_type', 'namespace', 'task_queue']) assert.equal(value[field], observations[scope][field]);
        assert.deepEqual(value.events, observations[scope].events.slice(0, value.events.length), 'complete original prefix at every control');
        assert.equal(value.status, control.phase === 'inactive-signal' ? 'completed' : 'waiting');
      }
      if (guard || scope !== index) { assert.deepEqual(after, before, 'guard and neighbor execution/history unchanged'); continue; }
      assert.deepEqual(without(after, ['execution']), without(before, ['execution']), 'durable rejection preserves history and typed result');
      if (observation.mode === 'embedded') {
        assert.equal(after.execution.last_command_sequence, before.execution.last_command_sequence + 1);
        assert.deepEqual(without(after.execution, ['last_command_sequence', 'updated_at']), without(before.execution, ['last_command_sequence', 'updated_at']));
      } else {
        assert.deepEqual(without(after.execution, ['commands']), without(before.execution, ['commands']));
        assert.equal(after.execution.commands.length, before.execution.commands.length + 1);
        assert.deepEqual(after.execution.commands.slice(0, -1), before.execution.commands, 'previous public commands remain unchanged');
      }
    }
    for (const state of [control.stored_before, control.stored_after]) checkState(state, ids, runs);
    let receipt;
    if (observation.mode === 'embedded') {
      assert.equal(control.result.refused, true);
      if (guard) assert.deepEqual(control.result.typed_error, {class: 'Illuminate\\Database\\Eloquent\\ModelNotFoundException'});
      else receipt = control.result.command;
    } else {
      const result = control.result; assert.equal(result.phase, control.phase);
      assert.equal(result.language, fixture.sdk_signal_direction.client); assert.equal(result.sdk_version, artifacts['sdk_' + result.language]);
      assert.equal(result.workflow_id, ids[index]); assert.equal(result.run_id, selected);
      assert.equal(result.sender_finished, true); assert.equal(result.signal_sent, false);
      assert(Number.isInteger(result.pid) && result.pid > 0); pids.push(result.pid);
      assert(!result.requests.some(wire => wire.path.startsWith('/api/worker/')));
      const signals = result.requests.filter(wire => wire.method === 'POST' && decodeURIComponent(wire.path) ===
        '/api/workflows/' + ids[index] + '/runs/' + selected + '/signal/' + (control.phase === 'unknown-signal' ? 'undeclared' : 'payload'));
      assert.equal(signals.length, 1); const wire = signals[0];
      assert.equal(wire.namespace, observations[control.scope_run].namespace);
      assert.equal(wire.request.run_id, undefined); nonempty(frame(wire.request.input));
      const status = control.phase === 'foreign-signal' || control.phase === 'unknown-signal' ? 404 : control.phase === 'invalid-signal' ? 422 : 409;
      assert.equal(wire.status, status); receipt = wire.response;
      if (result.language === 'php') {
        assert.equal(result.typed_error.class, 'DurableWorkflow\\Exception\\SignalFailed');
        assert.equal(result.typed_error.status, status); assert.equal(result.typed_error.reason, receipt.reason);
        assert.deepEqual(result.typed_error.details, receipt);
      } else {
        assert.equal(result.typed_error.class, 'durable_workflow::Error::Http'); assert.equal(result.typed_error.status, status);
        assert.deepEqual(JSON.parse(result.typed_error.body), receipt);
      }
      assert.equal(receipt.control_plane.operation, 'signal'); assert.equal(receipt.control_plane.workflow_id, ids[index]);
      if (guard) {
        assert.equal(receipt.reason, control.phase === 'foreign-signal' ? 'instance_not_found' : 'historical_run_command_rejected');
        assert.equal(receipt.command_id, undefined);
        if (control.phase === 'mismatched-run') { assert.equal(receipt.run_id, selected); assert.equal(receipt.target_scope, 'run'); }
      }
    }
    if (guard) assert.deepEqual(control.stored_before, control.stored_after, 'guard makes zero persisted mutation');
    else {
      const [reason, outcome, sequence] = control.phase === 'unknown-signal' ? ['unknown_signal', 'rejected_unknown_signal', 2]
        : control.phase === 'invalid-signal' ? ['invalid_signal_arguments', 'rejected_invalid_arguments', 3]
        : ['run_not_active', 'rejected_not_active', terminalSequence];
      assert.equal(receipt.reason, reason); assert.equal(receipt.outcome, outcome);
      checkRejectionMutation(control, ids, runs, receipt, reason, outcome, sequence);
    }
  }
  checkState(pair.final_stored, ids, runs);
  for (const table of ['activity_attempts', 'activity_executions']) assert.deepEqual(rows(pair.final_stored, table), [], 'signal-only recipe admits no activities');
  assert.equal(rows(pair.final_stored, 'workflow_commands').length, terminalSequence * 2);
  assert.equal(rows(pair.final_stored, 'workflow_signal_records').length, (terminalSequence - 1) * 2);
  assert.equal(rows(pair.final_stored, 'workflow_history_events').length, observation.mode === 'embedded' ? 22 : 26);
  const tasks = rows(pair.final_stored, 'workflow_tasks'); assert.equal(tasks.length, 6);
  for (const task of tasks) { assert.equal(task.status, 'completed'); assert.equal(task.attempt_count, 1); assert.equal(task.repair_count, 0); }
  for (const [index, runId] of runs.entries()) {
    const commands = rows(pair.final_stored, 'workflow_commands').filter(row => row.workflow_run_id === runId).sort((a, b) => a.command_sequence - b.command_sequence);
    assert.deepEqual(commands.map(row => row.command_sequence), Array.from({length: terminalSequence}, (_, index) => index + 1));
    assert.deepEqual(commands.map(row => row.message_sequence), [null, ...Array.from({length: terminalSequence - 1}, (_, index) => index + 1)]);
    assert.deepEqual(commands.map(row => row.outcome), ['started_new', 'rejected_unknown_signal',
      ...(validatesArguments ? ['rejected_invalid_arguments'] : []), 'signal_received', 'signal_received', 'rejected_not_active']);
    for (const command of commands) {
      assert.equal(command.workflow_instance_id, ids[index]); assert.equal(command.resolved_workflow_run_id, runId);
      const runScoped = observation.mode === 'embedded' && command.command_type === 'signal';
      assert.equal(command.target_scope, runScoped ? 'run' : 'instance');
      assert.equal(command.requested_workflow_run_id, runScoped ? runId : null);
    }
    const signals = rows(pair.final_stored, 'workflow_signal_records').filter(row => row.workflow_run_id === runId);
    assert.equal(signals.length, terminalSequence - 1); assert.equal(signals.filter(row => row.status === 'applied').length, 2);
    for (const signal of signals) {
      const command = commands.find(row => row.id === signal.workflow_command_id); assert(command);
      for (const key of ['workflow_run_id', 'workflow_instance_id', 'command_sequence', 'outcome', 'target_scope', 'requested_workflow_run_id', 'resolved_workflow_run_id']) assert.equal(signal[key], command[key]);
      const expectedArguments = command.rejection_reason === 'invalid_signal_arguments' ? {type: 'list', value: []}
        : {type: 'list', value: [fixtures[index].typed_signal_value]};
      assert.deepEqual(pair.final_stored.decoded_values.workflow_signal_records[signal.id].arguments.typed_value, expectedArguments, 'durable signal keeps exact original argument types');
    }
    assert.deepEqual(pair.final_stored.decoded_values.workflow_runs[runId].output.typed_value, fixtures[index].typed_signal_value, 'durable output exact int64/null/Unicode');
    for (const [number, delivery] of observations[index].signal_deliveries.entries()) {
      const command = commands[number + (validatesArguments ? 3 : 2)]; assert.equal(delivery.response.command_id, command.id);
      assert.equal(delivery.response.command_sequence, number + (validatesArguments ? 4 : 3));
      assert.equal(delivery.response.target_scope, command.target_scope);
      assert.equal(delivery.response.requested_run_id, command.requested_workflow_run_id);
    }
    const run = rows(pair.final_stored, 'workflow_runs').find(row => row.id === runId);
    const history = rows(pair.final_stored, 'workflow_history_events').filter(row => row.workflow_run_id === runId).sort((a, b) => a.sequence - b.sequence);
    assert.equal(history.length, observations[index].events.length);
    for (const [position, row] of history.entries()) {
      const event = observations[index].events[position];
      assert.equal(row.sequence, event.sequence); assert.equal(row.event_type, event.event_type);
      assert.deepEqual(storedPayload(JSON.parse(row.payload)), storedPayload(event.payload), 'persisted complete event payload matches its public original');
    }
    const runTasks = tasks.filter(task => task.workflow_run_id === runId); assert.equal(runTasks.length, 3);
    runTasks.forEach(task => assert.equal(task.namespace, fixtures[index].namespace));
    if (observation.mode !== 'embedded') assert.deepEqual(runTasks.map(task => task.id).sort(), observations[index].workflow_polls.map(task => task.task_id).sort());
    assert.equal(run.last_command_sequence, terminalSequence); assert.equal(run.message_cursor_position, terminalSequence - 2);
    assert.equal(rows(pair.final_stored, 'workflow_instances').find(row => row.id === ids[index]).last_message_sequence, terminalSequence - 1);
  }
  if (observation.mode !== 'embedded') {
    for (const run of observations) {
      const registration = run.sdk_signal_direction.phases[0].requests.find(wire => wire.path === '/api/worker/register');
      const declarations = registration.request.workflow_command_contracts[fixture.workflow_type].signal_contracts;
      const signal = declarations.find(value => value.name === 'payload'); assert(signal);
      assert.equal(signal.parameters.length, 1);
      const parameter = signal.parameters[0];
      assert.equal(parameter.required, validatesArguments); assert.equal(parameter.variadic, !validatesArguments);
      assert.equal(parameter.type, fixture.sdk_signal_direction.workflow === 'php' ? 'array' : null);
    }
    const authors = observations.flatMap(run => run.sdk_signal_direction.phases.map(phase => phase.pid));
    assert.equal(new Set([...authors, ...pids]).size, authors.length + pids.length, 'every author/sender/control uses a distinct real process');
  }
  return {workflow_id: workflowId, primary: projections[0], peer: projections[1], selected_run_signals: {
    supported_originals: 2, accepted_signals: 4, persisted_rejected_signals: validatesArguments ? 6 : 4,
    invalid_arguments_validation: spec.invalid_arguments, zero_mutation_guards: 4,
    exact_original_sequences: true, unchanged_original_budgets: true}};
}
