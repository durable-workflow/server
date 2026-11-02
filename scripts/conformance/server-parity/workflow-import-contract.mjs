import assert from 'node:assert/strict';

// Reviewed history import (EmbeddedV2ImportController), recorded against
// published Server 2.11.1 and moved to 2.11.8. The fixture's `expected` holds the whole
// normalized observation, with the probe's workflow prefix as `{id}`, bundle
// identities, checksums and signatures as `{<label>.<token>}` labels, and
// generated import ids, import times and raw database messages as
// placeholders. Every step compares exactly.
//
// Published defect boundaries (fixture `published_defects`): at a recorded
// defect's steps the published answers, as a set, or the isolated answers in
// `expected` are accepted; nothing else is relaxed. Published 2.11.1 to
// 2.11.7 looked runs and instances up in every namespace (#765). Core
// 2.15.12, in Server 2.11.8, refuses both as native does, so the reference
// records no defect now.
export function checkWorkflowImport(fixture, observation, workflowId) {
  const state = observation.workflow_import;
  const projection = {scope: 'workflow_import'};
  assert.ok(state && typeof state === 'object', 'actual workflow import observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_http_workflow_import'},
      'embedded workflow import explicitly inapplicable');
    return projection;
  }
  const definition = fixture.workflow_import;
  const actual = JSON.parse(JSON.stringify(state).replaceAll(workflowId, '{id}'));
  for (const defect of Object.values(definition.published_defects)) {
    const steps = Object.keys(defect.published);
    let published = true;
    try {
      for (const step of steps) assert.deepStrictEqual(actual[step], defect.published[step]);
    } catch {
      published = false;
    }
    if (published) {
      for (const step of steps) actual[step] = definition.expected[step];
    }
  }
  for (const [step, expected] of Object.entries(definition.expected)) {
    assert.deepStrictEqual(actual[step], expected, `workflow import ${step}`);
  }
  assert.deepStrictEqual(Object.keys(actual).sort(), Object.keys(definition.expected).sort(), 'workflow import steps');
  return projection;
}
