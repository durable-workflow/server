// Run only against retained real recordings after the selected profile records
// its targets. These mutations never edit an original artifact or database.
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {checkObservation} from '../../scripts/conformance/server-parity/contract.mjs';

const paths = JSON.parse(process.env.DW_PARITY_SELECTED_RUN_RECORDS ?? 'null');
assert(Array.isArray(paths) && paths.length >= 2, 'explicit actual reference/candidate recording paths required');
const records = paths.map(path => JSON.parse(readFileSync(path, 'utf8')));
for (const record of records) {
  assert.equal(record.outcome, 'pass', 'failed original is never relabeled by this suite');
  assert.equal(record.cases.length, 6, 'all supported directions must be present');
}
const control = value => value.selected_run_signal_pair.controls[0];
const state = value => value.selected_run_signal_pair.final_stored;
const mutations = {
  'same original run': value => { value.selected_run_signal_pair.peer.run_id = value.run_id; },
  'peer result rounded': value => { value.selected_run_signal_pair.peer.typed_output.value.count.value = '9007199254740996'; },
  'original deadline renewed': value => { value.selected_run_signal_pair.run_budgets[0].deadline_at_unix += 35; },
  'original budget exceeded': value => { value.selected_run_signal_pair.run_budgets[0].finished_at_unix += 100; },
  'guard mutates raw run': value => { control(value).stored_after.tables.workflow_runs.rows[0].status = 'running'; },
  'guard mutates neighbor': value => { control(value).stored_after.tables.workflow_instances.rows[1].last_message_sequence += 1; },
  'guard changes public identity': value => { control(value).before[0].workflow_id = control(value).after[0].workflow_id = 'other'; },
  'missing control': value => { value.selected_run_signal_pair.controls.pop(); },
  'unexpected validation applicability': value => { value.selected_run_signal_pair.controls.push(structuredClone(control(value))); },
  'missing wait-state table': value => { delete state(value).tables.workflow_run_waits; },
  'unexpected activity': value => { const table = state(value).tables.activity_executions; table.rows.push(Object.fromEntries(table.columns.map(key => [key, key === 'id' ? 'unexpected-activity' : null]))); },
  'raw rejected row erased': value => { state(value).tables.workflow_commands.rows = state(value).tables.workflow_commands.rows.filter(row => row.status !== 'rejected'); },
  'message sequence collapsed': value => { state(value).tables.workflow_commands.rows.find(row => row.command_type === 'signal').message_sequence = 0; },
  'wrong resolved run': value => { state(value).tables.workflow_commands.rows.find(row => row.command_type === 'signal').resolved_workflow_run_id = 'other'; },
  'signal command relationship changed': value => { state(value).tables.workflow_signal_records.rows[0].workflow_command_id = 'other'; },
  'signal status collapsed': value => { state(value).tables.workflow_signal_records.rows.find(row => row.status === 'rejected').status = 'applied'; },
  'stored argument altered': value => { const first = Object.values(state(value).decoded_values.workflow_signal_records)[0]; first.arguments.typed_value = {type: 'null', value: null}; },
  'stored output rounded': value => { state(value).decoded_values.workflow_runs[value.run_id].output.typed_value.value.count.value = '9007199254740992'; },
  'stored history rewritten': value => { state(value).tables.workflow_history_events.rows[0].payload = '{}'; },
  'task repaired': value => { state(value).tables.workflow_tasks.rows[0].repair_count += 1; },
  'task replay attempt': value => { state(value).tables.workflow_tasks.rows[0].attempt_count += 1; },
  'task namespace leaked': value => { state(value).tables.workflow_tasks.rows[0].namespace = 'foreign'; },
};
for (const record of records) for (const item of record.cases) {
  const label = record.target + '/' + item.fixture_id;
  test('actual selected-run positive ' + label, () => {
    checkObservation(item.fixture, item.observation, item.observation.workflow_id);
  });
  for (const [name, mutate] of Object.entries(mutations)) test('refuse selected-run ' + label + ': ' + name, () => {
    const value = structuredClone(item.observation); mutate(value);
    assert.throws(() => checkObservation(item.fixture, value, item.observation.workflow_id));
  });
  if (record.mode === 'http') for (const [name, mutate] of Object.entries({
    'ordinary sender path': value => { value.sdk_signal_direction.phases[1].requests.find(wire => wire.path.endsWith('/signal/payload')).path = '/api/workflows/' + value.workflow_id + '/signal/payload'; },
    'foreign error becomes admitted': value => { control(value).result.signal_sent = true; },
    'foreign wrong namespace': value => { control(value).result.requests.find(wire => wire.path.includes('/signal/')).namespace = value.namespace; },
    'untyped client refusal': value => { control(value).result.typed_error.class = 'RuntimeException'; },
  })) test('refuse selected-run ' + label + ': ' + name, () => {
    const value = structuredClone(item.observation); mutate(value);
    assert.throws(() => checkObservation(item.fixture, value, item.observation.workflow_id));
  });
}
