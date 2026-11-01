import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkHistoryExportWaits} from '../../scripts/conformance/server-parity/history-export-waits-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/history-export-waits.json', import.meta.url), 'utf8'));
const expected = fixture.history_export_waits.expected;
const observation = () => structuredClone({mode: 'http', history_export_waits: {applicable: true, ...expected}});

test('the published answers pass', () => {
  assert.deepStrictEqual(checkHistoryExportWaits(fixture, observation()), {scope: 'history_export_waits', runs: 7});
});

test('a timeline rebuilt from history passes', () => {
  const value = observation();
  for (const exports of Object.values(value.history_export_waits).filter(item => typeof item === 'object')) {
    for (const exported of Object.values(exports)) {
      exported.selected_run.timeline_projection_source = 'workflow_run_timeline_entries_rebuilt';
    }
  }
  assert.deepStrictEqual(checkHistoryExportWaits(fixture, value), {scope: 'history_export_waits', runs: 7});
});

test('embedded applications have no worker protocol', () => {
  assert.deepStrictEqual(checkHistoryExportWaits(fixture,
    {mode: 'embedded', history_export_waits: {applicable: false, reason: 'embedded_has_no_worker_protocol'}}),
  {scope: 'history_export_waits', runs: 7});
  assert.throws(() => checkHistoryExportWaits(fixture, {mode: 'embedded', history_export_waits: {applicable: true}}));
});

test('the fixture exports every wait kind open and closed', () => {
  const kinds = new Set(Object.values(expected).flatMap(exports => Object.values(exports))
    .flatMap(exported => exported.waits.map(wait => `${wait.kind}:${wait.status}`)));
  for (const kind of ['activity:open', 'activity:resolved', 'timer:open', 'timer:resolved', 'signal:open',
    'signal:resolved', 'condition:open', 'condition:cancelled', 'child:open', 'child:resolved', 'update:open',
    'update:resolved']) {
    assert.ok(kinds.has(kind), kind);
  }
  assert.deepStrictEqual(expected.activity.closed.timeline.map(entry => entry.type),
    ['StartAccepted', 'WorkflowStarted', 'ActivityScheduled', 'ActivityStarted', 'ActivityCompleted', 'WorkflowCompleted']);
  assert.equal(expected.activity_failure.closed.timeline.at(-1).summary, 'Workflow failed: run failed.');
});

for (const [name, mutate] of [
  ['an unavailable wait projection', state => { state.timer.waiting.selected_run.waits_projection_source = 'native_unavailable'; }],
  ['an unavailable timeline', state => { state.timer.waiting.selected_run.timeline_projection_source = 'native_unavailable'; }],
  ['an empty wait section', state => { state.activity.waiting.waits = []; }],
  ['an empty timeline', state => { state.child.closed.timeline = []; }],
  ['another wait summary', state => { state.signal.waiting.waits[0].summary = 'Waiting.'; }],
  ['a task-backed signal wait', state => { state.signal.waiting.waits[0].task_backed = true; }],
  ['an update queued behind nothing', state => { state.update.accepted.waits[1].ordering_state = 'queued'; }],
  ['a timeline point of another kind', state => { state.timer.closed.timeline[3].kind = 'workflow'; }],
  ['another activity summary', state => { state.activity.closed.timeline[4].summary = 'Done.'; }],
  ['a missing failure on the failed run', state => { state.activity_failure.closed.timeline.at(-1).failure = null; }],
  ['an unreviewed export', state => { state.timer.extra = state.timer.closed; }],
  ['a missing run', state => { delete state.update; }],
]) test(`the contract rejects ${name}`, () => {
  const value = observation();
  mutate(value.history_export_waits);
  assert.throws(() => checkHistoryExportWaits(fixture, value));
});
