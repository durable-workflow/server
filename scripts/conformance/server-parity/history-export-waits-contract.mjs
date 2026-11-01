import assert from 'node:assert/strict';

// Reviewed history export waits and timeline (history-export-waits-probe.php),
// recorded against published Server 2.11.7: the `waits` and `timeline`
// sections of each run's export while it waits and once closed, for an
// activity, a failed activity, a timer, a signal wait, a condition timeout, a
// child workflow and an update. Identities are labels and instants are
// masked, so each section compares exactly. Published PHP serves the timeline
// from its stored projection; native maps the run's history when it exports,
// as PHP does when that projection is missing, and names the source
// `workflow_run_timeline_entries_rebuilt`.
const TIMELINE_SOURCES = ['workflow_run_timeline_entries', 'workflow_run_timeline_entries_rebuilt'];

export function checkHistoryExportWaits(fixture, observation) {
  const definition = fixture.history_export_waits;
  const state = observation.history_export_waits;
  const projection = {scope: 'history_export_waits', runs: Object.keys(definition.expected).length};
  assert.ok(state && typeof state === 'object', 'actual history export waits observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_worker_protocol'},
      'embedded history export waits explicitly inapplicable');
    return projection;
  }
  assert.equal(state.applicable, true, 'HTTP targets record history export waits');
  for (const [run, exports] of Object.entries(definition.expected)) {
    assert.ok(state[run] && typeof state[run] === 'object', `history export waits ${run} recorded`);
    for (const [step, expected] of Object.entries(exports)) {
      const actual = state[run][step];
      assert.ok(actual && typeof actual === 'object', `history export waits ${run} ${step} recorded`);
      assert.equal(actual.selected_run?.waits_projection_source, expected.selected_run.waits_projection_source,
        `history export waits ${run} ${step} waits source`);
      assert.ok(TIMELINE_SOURCES.includes(actual.selected_run?.timeline_projection_source),
        `history export waits ${run} ${step} timeline source`);
      assert.deepStrictEqual(actual.waits, expected.waits, `history export waits ${run} ${step} waits`);
      assert.equal(actual.timeline?.length, expected.timeline.length, `history export waits ${run} ${step} timeline length`);
      expected.timeline.forEach((entry, index) => assert.deepStrictEqual(actual.timeline[index], entry,
        `history export waits ${run} ${step} timeline ${index} ${entry.type}`));
    }
    assert.deepStrictEqual(Object.keys(state[run]).sort(), Object.keys(exports).sort(), `history export waits ${run} steps`);
  }
  assert.deepStrictEqual(Object.keys(state).sort(), ['applicable', ...Object.keys(definition.expected)].sort(),
    'history export waits runs');
  return projection;
}
