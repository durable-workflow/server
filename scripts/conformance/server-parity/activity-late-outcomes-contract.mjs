import assert from 'node:assert/strict';

// Reviewed activity outcomes after a deadline
// (activity-late-outcomes-probe.php), recorded against published Server
// 2.11.7. Each case's `expected` holds the whole observation with the
// refusal class as `{refusal}`: the deadline's timeout reason before the
// timeout sweep closes the attempt, stale_attempt after it. Which one a run
// sees races the sweep on both servers, so either is accepted and nothing
// else; every other value compares exactly.
export function checkActivityLateOutcomes(fixture, observation) {
  const definition = fixture.activity_late_outcomes;
  const state = observation.activity_late_outcomes;
  const projection = {scope: 'activity_late_outcomes', cases: Object.keys(definition.cases).length};
  assert.ok(state && typeof state === 'object', 'actual activity late outcomes observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_worker_protocol'},
      'embedded activity late outcomes explicitly inapplicable');
    return projection;
  }
  assert.equal(state.applicable, true, 'HTTP targets record activity late outcomes');
  assert.deepStrictEqual(Object.keys(definition.expected).sort(), Object.keys(definition.cases).sort(),
    'every activity late outcomes case has an expectation');
  for (const [name, expected] of Object.entries(definition.expected)) {
    const actual = structuredClone(state[name]);
    assert.ok(actual && typeof actual === 'object', `activity late outcomes ${name}`);
    assert.ok(definition.refusals.includes(actual.answer?.refusal),
      `activity late outcomes ${name} refusal: ${JSON.stringify(actual.answer?.refusal)}`);
    actual.answer.refusal = '{refusal}';
    assert.deepStrictEqual(actual, expected, `activity late outcomes ${name}`);
  }
  assert.deepStrictEqual(Object.keys(state).sort(), ['applicable', ...Object.keys(definition.expected)].sort(),
    'activity late outcomes cases');
  return projection;
}
