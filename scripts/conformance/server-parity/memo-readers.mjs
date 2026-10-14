import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkMemoRecord} from './memo-contract.mjs';

const [recordPath, fixturePath, artifactsPath, mode = 'http'] = process.argv.slice(2);
const record = JSON.parse(readFileSync(recordPath));
const fixture = JSON.parse(readFileSync(fixturePath));
const artifacts = JSON.parse(readFileSync(artifactsPath));
const projection = checkMemoRecord(record, fixture, artifacts, mode);
const controls = [
  ['changed int64', r => {r.phases.replacement.typed_memo.value.keep.value.integer.value = '9007199254740992';}],
  ['null lost', r => {r.phases.original.typed_memo.value.keep.value.optional = {type: 'string', value: ''};}],
  ['false lost', r => {r.phases.original.typed_memo.value.replace = {type: 'null', value: null};}],
  ['list lost', r => {r.phases.original.typed_memo.value.empty = {type: 'map', value: {}};}],
  ['unrelated key deleted', r => {delete r.phases.replacement.typed_memo.value.keep;}],
  ['null deletion ignored', r => {r.phases.replacement.typed_memo.value.added = {type: 'null', value: null};}],
  ['replayed memo appended', r => {r.phases.replacement.events.push(r.phases.replacement.events.find(e => e.event_type === 'MemoUpserted'));}],
  ['wrong original run', r => {r.phases.replacement.run_id = 'replacement';}],
  ['same process', r => {const a = mode === 'http' ? r.phases.activity.actor : r.phases.activity;
    a.pid = (mode === 'http' ? r.phases.original.actor : r.phases.original).pid;}],
  ['changed pending prefix', r => {r.phases.replacement.events[0].timestamp = '2000-01-01T00:00:00.000000Z';}],
  ['second activity invocation', r => {(mode === 'http' ? r.phases.activity.actor : r.phases.activity).activity_invocations++;}],
  ['different actor source', r => {const a = mode === 'http' ? r.phases.original.actor : r.phases.original;
    a[mode === 'http' ? 'sdk_source' : 'workflow_source'] = '0'.repeat(40);}],
];
if (mode === 'http') controls.push(
  ['missing capability', r => {r.discovery.workflow_memo_updates.supported = false;}],
  ['duplicate mutation', r => {r.duplicate.after.events.pop();}],
);
for (const [name, mutate] of controls) {
  const changed = structuredClone(record);
  mutate(changed);
  assert.throws(() => checkMemoRecord(changed, fixture, artifacts, mode), undefined, name);
}
console.log(JSON.stringify({outcome: 'pass', run_id: record.phases.original.run_id,
  refused_corruptions: controls.length, projection}));
