import assert from 'node:assert/strict';

// Reviewed record_local_activity completions (worker protocol 1.18+),
// recorded against published Server 2.11.1. The fixture's `expected` holds
// the whole normalized observation, with the probe's workflow prefix as
// `{id}`, run identities as labels that keep their letter case, attempt
// times as offsets from each local activity's close and other times as
// `{time}`. Every step compares exactly.
//
// Published defect boundary: published PHP validates a completion's
// commands through Laravel's validated(), which rebuilds nested lists in
// rule order, so a report whose first attempt lacks attempt_id, or whose
// first heartbeat lacks details, while a later entry has it is refused as
// unordered (fixture `published_defects`). At those steps the published
// refusals, all of them, or the normalizer's own answers on the reports as
// sent are accepted; nothing else is relaxed.
export function checkRecordLocalActivity(fixture, observation, workflowId) {
  const state = observation.record_local_activity;
  const projection = {scope: 'record_local_activity'};
  assert.ok(state && typeof state === 'object', 'actual record local activity observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_worker_protocol'},
      'embedded record local activity explicitly inapplicable');
    return projection;
  }
  const definition = fixture.record_local_activity;
  const actual = JSON.parse(JSON.stringify(state).replaceAll(workflowId, '{id}'));
  const defect = definition.published_defects.validated_reorders_nested_reports.published;
  const steps = Object.keys(defect);
  let published = true;
  try {
    for (const step of steps) assert.deepStrictEqual(actual[step], defect[step]);
  } catch {
    published = false;
  }
  if (published) {
    for (const step of steps) actual[step] = definition.expected[step];
  }
  for (const [step, expected] of Object.entries(definition.expected)) {
    assert.deepStrictEqual(actual[step], expected, `record local activity ${step}`);
  }
  assert.deepStrictEqual(Object.keys(actual).sort(), Object.keys(definition.expected).sort(), 'record local activity steps');
  return projection;
}
