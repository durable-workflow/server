import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkObservation} from './contract.mjs';
import {checkActivityDeadlineReceiptPackages} from './activity-deadline-receipts-contract.mjs';

const record = JSON.parse(readFileSync(process.argv[2]));
assert.equal(record.outcome, 'pass');
const item = record.cases.find(row => row.fixture_id === 'activity-heartbeat-renewal');
assert(item, 'Actual heartbeat renewal case is required');
const original = item.observation;
const state = value => value.activity_deadline_receipts;
const reply = (value, name) => value.mode === 'http' ? state(value)[name].response : state(value)[name];
const read = value => {
  checkActivityDeadlineReceiptPackages(item.fixture, value, record.artifacts);
  return checkObservation(item.fixture, value, original.workflow_id);
};
read(original);
const mutations = {
  'heartbeat does not renew lease': value => {reply(value, 'heartbeat_two').lease_expires_at = reply(value, 'heartbeat_one').lease_expires_at;},
  'status silently renews': value => {state(value).status_after_initial_expiry.lease_expires_at = reply(value, 'heartbeat_two').lease_expires_at;},
  'status records progress': value => {state(value).status_after_initial_expiry.heartbeat_recorded = true;},
  'status refuses surviving original': value => {state(value).status_after_initial_expiry.can_continue = false;},
  'second heartbeat before original deadline': value => {reply(value, 'heartbeat_two').last_heartbeat_at = value.events[3].timestamp;},
  'missed renewed heartbeat deadline': value => {reply(value, 'heartbeat_two').last_heartbeat_at = reply(value, 'heartbeat_one').lease_expires_at;},
  'post-terminal progress accepted': value => {reply(value, 'late_heartbeat').heartbeat_recorded = true;},
  'post-terminal authority granted': value => {reply(value, 'late_heartbeat').can_continue = true;},
  'changed heartbeat prefix': value => {state(value).after_status_read.events[0].sequence += 1;},
  'missing progress details': value => {value.events[4].payload.progress = {};},
  'heartbeat belongs to another attempt': value => {value.events[5].payload.activity_attempt_id = 'other';},
  'second attempt introduced': value => {state(value).first_attempt.attempt_number = 2;},
  'changed original retry policy': value => {state(value).first_attempt.retry_policy.backoff_seconds = [1];},
  'rounded activity arguments': value => {value.events[2].typed_decoded.activity_arguments.value[0].value.integer.value = '9007199254740992';},
  'changed typed result': value => {value.events[6].typed_decoded.result.value.integer.value = '9007199254740992';},
  'parent belongs to another run': value => {state(value).parent_delivery.run_id = 'other';},
  'additional activity delivered': value => {state(value).post_completion_activity_poll = {task_id: 'other'};},
  'original run budget changed': value => {state(value).before_expiry.execution.run_deadline_at = '2100-01-01T00:00:00.000000Z';},
  'observer rebudgeted': value => {state(value).overall_budget_seconds += 1;},
  'changed installed package': value => {value.sdk_php_source = '0'.repeat(40);},
};
for (const [name, mutate] of Object.entries(mutations)) {
  const value = structuredClone(original);
  mutate(value);
  assert.throws(() => read(value), undefined, name);
}
console.log(JSON.stringify({outcome: 'pass', target: record.target, actual_record: true,
  fixture_id: item.fixture_id, guards: Object.keys(mutations)}));
