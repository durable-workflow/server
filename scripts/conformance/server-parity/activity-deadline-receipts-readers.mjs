import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkObservation} from './contract.mjs';
import {checkActivityDeadlineReceiptPackages} from './activity-deadline-receipts-contract.mjs';

const record = JSON.parse(readFileSync(process.argv[2]));
assert.equal(record.outcome, 'pass', 'Actual completed retry/receipt reference');
assert.equal(record.cases.length, 1);
const item = record.cases[0], original = item.observation;
const read = value => {
  checkActivityDeadlineReceiptPackages(item.fixture, value, record.artifacts);
  return checkObservation(item.fixture, value, original.workflow_id);
};
read(original);
const state = value => value.activity_deadline_receipts;
const mutations = {
  'reused first task': value => {state(value).second_attempt.task_id = state(value).first_attempt.task_id;},
  'reused first attempt': value => {state(value).second_attempt.activity_attempt_id = state(value).first_attempt.activity_attempt_id;},
  'replaced original execution': value => {state(value).second_attempt.activity_execution_id = 'other';},
  'wrong original run': value => {state(value).second_attempt.run_id = 'other';},
  'truncated full policy': value => {state(value).second_attempt.retry_policy.backoff_seconds.pop();},
  'rebudgeted observer': value => {state(value).overall_budget_seconds += 1;},
  'duplicates sent before expiry': value => {state(value).global_elapsed_seconds = 0;},
  'accepted expired first completion': value => {state(value).first_refusal.status = 200;},
  'duplicate records another outcome': value => {state(value).duplicate.response.recorded = true;},
  'conflicting output accepted': value => {state(value).conflict.status = 200;},
  'workflow duplicate records again': value => {state(value).workflow_duplicate.response.recorded = true;},
  'changed original prefix': value => {state(value).before_expiry.events[2].payload.sequence += 1;},
  'rounded typed result': value => {value.typed_output.value.integer.value = '9007199254740992';},
  'wrong timeout family': value => {value.events[4].payload.timeout_kind = 'heartbeat';},
  'wrong retry task': value => {value.events[4].payload.retry_task_id = 'other';},
  'early retry claim': value => {value.events[5].timestamp = value.events[4].timestamp;},
  'lost response bytes': value => {state(value).requests[0].response_bytes_base64 = '';},
  'cancelled request counted': value => {state(value).requests[0].client_cancelled = true;},
  'changed installed lock': value => {value.sdk_composer_lock_sha256 = '0'.repeat(64);},
  'changed installed source': value => {value.sdk_loaded_sources['src/Client.php'] = '0'.repeat(64);},
};
for (const [name, mutate] of Object.entries(mutations)) {
  const corrupted = structuredClone(original);
  mutate(corrupted);
  assert.throws(() => read(corrupted), undefined, name);
}
console.log(JSON.stringify({outcome: 'pass', actual_record: true, fixture_id: item.fixture_id,
  mutation_controls: Object.keys(mutations)}));
