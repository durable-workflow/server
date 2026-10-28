import assert from 'node:assert/strict';

// Reviewed workflow-task cancellation scopes (CancellationScopeController over
// Workflow 2.10's portable scope opening and delivery), with the scope
// requests a run cancellation records and the completion admission scopes
// impose, recorded against published Server 2.11.1. The probe normalizes the
// observation: identities and digests become labels, the run requests'
// instants named anchors and other instants their format, and clock-dependent
// relations measured facts. `scope_checkpoints`, recorded against published
// Server 2.11.5, adds the prefix checkpoint a cooperative worker sends
// before opening a scope (POST …/cancellation-scopes/checkpoint): its gates
// and validation, Core's refusals, the committed prefix, the retained
// receipt and its retry, the claim kept for the scope and the completion.
// Each receipt's fingerprint is a server's own digest of the prefix, named
// by the probe, so only its reuse is compared. The fixture's `expected` holds the whole
// normalized observation, with the probe's workflow prefix as `{id}`. Every
// step compares exactly.
//
// Published defect boundary: prepare and deliver with `scope_id: "root"`
// throw `root_scope_requires_run_cancellation` from Workflow's scope requests,
// which the portable delivery does not convert, so the published server
// answers Laravel's bare 500 (fixture `published_defects`). At those steps the
// published answers or the intended 409 refusal are accepted, as a set;
// nothing else is relaxed.
export function checkCancellationScopes(fixture, observation, workflowId) {
  const state = observation.cancellation_scopes;
  const projection = {scope: 'cancellation_scopes'};
  assert.ok(state && typeof state === 'object', 'actual cancellation scopes observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_worker_protocol'},
      'embedded cancellation scopes explicitly inapplicable');
    return projection;
  }
  const definition = fixture.cancellation_scopes;
  const actual = JSON.parse(JSON.stringify(state).replaceAll(workflowId, '{id}'));
  for (const defect of Object.values(definition.published_defects ?? {})) {
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
    assert.deepStrictEqual(actual[step], expected, `cancellation scopes ${step}`);
  }
  assert.deepStrictEqual(Object.keys(actual).sort(), Object.keys(definition.expected).sort(), 'cancellation scopes steps');
  return projection;
}
