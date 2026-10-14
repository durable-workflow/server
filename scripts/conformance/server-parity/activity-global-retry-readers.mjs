import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkObservation} from './contract.mjs';
import {checkActivityDeadlineReceiptPackages} from './activity-deadline-receipts-contract.mjs';

const record = JSON.parse(readFileSync(process.argv[2]));
assert.equal(record.outcome, 'pass');
const item = record.cases.find(row => row.fixture_id === 'activity-global-deadline-retry');
assert(item, 'Actual global-deadline case is required');
const original = item.observation;
assert.equal(item.fixture.activity_deadline_receipts.global_timeout_control, true);
const state = value => value.activity_deadline_receipts;
const http = original.mode === 'http';
const read = value => {
  checkActivityDeadlineReceiptPackages(item.fixture, value, record.artifacts);
  return checkObservation(item.fixture, value, original.workflow_id);
};
read(original);
const mutations = {
  'reused task': value => {state(value).second_attempt.task_id = state(value).first_attempt.task_id;},
  'reused attempt': value => {state(value).second_attempt.activity_attempt_id = state(value).first_attempt.activity_attempt_id;},
  'replaced execution': value => {state(value).second_attempt.activity_execution_id = 'other';},
  'retry list truncated': value => {state(value).second_attempt.retry_policy.backoff_seconds.pop();},
  'observer rebudgeted': value => {state(value).overall_budget_seconds += 1;},
  'observer has not crossed global expiry': value => {state(value).global_elapsed_seconds = 0;},
  'attempt budget exhausted': value => {state(value).second_attempt.attempt_number = 3;},
  'wrong timeout family': value => {value.events[6].payload.timeout_kind = 'start_to_close';},
  'wrong timeout attempt': value => {value.events[6].payload.activity_attempt_id = 'other';},
  'early timeout': value => {value.events[6].timestamp = value.events[5].timestamp;},
  'early retry claim': value => {value.events[5].timestamp = value.events[4].timestamp;},
  'changed prefix': value => {state(value).before_expiry.events[0].sequence += 1;},
  'rounded typed arguments': value => {value.events[2].typed_decoded.activity_arguments.value[0].value.integer.value = '9007199254740992';},
  'status grants authority': value => {state(value).status_after_expiry.can_continue = true;},
  'heartbeat records progress': value => {state(value).late_outcomes[2].response.heartbeat_recorded = true;},
  'late completion accepted': value => {if (http) state(value).late_outcomes[0].status = 200; else state(value).late_outcomes[0].response.recorded = true;},
  'third activity delivery': value => {state(value).post_expiry_activity_polls[0] = {task_id: 'third'};},
  'second parent delivery': value => {state(value).extra_parent_poll = {task_id: 'other'};},
  'parent belongs to another run': value => {state(value).parent_delivery.run_id = 'other';},
  'changed installed SDK': value => {value.sdk_loaded_sources['src/Client.php'] = '0'.repeat(64);},
};
for (const [name, mutate] of Object.entries(mutations)) {
  const value = structuredClone(original);
  mutate(value);
  assert.throws(() => read(value), undefined, name);
}
console.log(JSON.stringify({outcome: 'pass', actual_record: true, target: record.target,
  fixture_id: item.fixture_id, guards: Object.keys(mutations)}));
