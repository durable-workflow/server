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
//
// A workflow task snapshot names the storage connection that recorded it:
// sqlite where the fixture was recorded, pgsql or mysql on the other
// backends. Each observation names one of them throughout, compared as
// `{connection}`.
const CONNECTIONS = new Set(['sqlite', 'pgsql', 'mysql']);

export function normalizeConnections(state, label) {
  const names = new Set();
  const walk = value => {
    if (Array.isArray(value)) return value.map(walk);
    if (value === null || typeof value !== 'object') return value;
    return Object.fromEntries(Object.entries(value).map(([key, item]) => {
      if (key === 'connection' && typeof item === 'string') {
        names.add(item);
        return [key, '{connection}'];
      }
      return [key, walk(item)];
    }));
  };
  const normalized = walk(state);
  assert.ok(names.size <= 1, `${label} names one storage connection: ${[...names].join(', ')}`);
  for (const name of names) assert.ok(CONNECTIONS.has(name), `${label} storage connection ${name}`);
  return normalized;
}

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
  const actual = normalizeConnections(JSON.parse(JSON.stringify(state).replaceAll(workflowId, '{id}')), 'actual');
  const expected = normalizeConnections(definition.expected, 'expected');
  const defect = normalizeConnections(definition.published_defects.validated_reorders_nested_reports.published, 'published');
  const steps = Object.keys(defect);
  let published = true;
  try {
    for (const step of steps) assert.deepStrictEqual(actual[step], defect[step]);
  } catch {
    published = false;
  }
  if (published) {
    for (const step of steps) actual[step] = expected[step];
  }
  for (const [step, value] of Object.entries(expected)) {
    assert.deepStrictEqual(actual[step], value, `record local activity ${step}`);
  }
  assert.deepStrictEqual(Object.keys(actual).sort(), Object.keys(expected).sort(), 'record local activity steps');
  return projection;
}
