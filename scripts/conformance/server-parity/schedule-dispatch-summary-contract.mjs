import assert from 'node:assert/strict';

export function checkDispatchSummary(summary) {
  assert.ok(summary && typeof summary === 'object', 'complete dispatch summary');
  assert.deepStrictEqual(Object.keys(summary).sort(), ['buffered_actions_count', 'last_outcome', 'state', 'version']);
  assert.equal(summary.version, 1, 'dispatch summary version');
  assert.ok(Buffer.byteLength(JSON.stringify(summary)) <= 4096, 'bounded complete summary');
  if (summary.state === 'unavailable') {
    assert.deepStrictEqual([summary.last_outcome, summary.buffered_actions_count], [null, null]);
    return;
  }
  assert.ok(Number.isSafeInteger(summary.buffered_actions_count) && summary.buffered_actions_count >= 0, 'current buffer count');
  if (summary.state === 'never') {
    assert.deepStrictEqual([summary.last_outcome, summary.buffered_actions_count], [null, 0]);
    return;
  }
  assert.equal(summary.state, 'known');
  const last = summary.last_outcome;
  assert.ok(last && typeof last === 'object', 'complete dispatch outcome');
  assert.deepStrictEqual(Object.keys(last).sort(), ['id', 'outcome', 'reason', 'recorded_at', 'workflow_instance_id', 'workflow_run_id']);
  const ulid = /^[0-7][0-9A-HJKMNP-TV-Z]{25}$/;
  assert.match(last.id, ulid, 'complete dispatch identity');
  assert.match(last.recorded_at, /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/, 'exact dispatch instant');
  const instant = new Date(last.recorded_at);
  assert.ok(Number.isFinite(instant.getTime()), 'actual dispatch instant');
  assert.equal(instant.toISOString().slice(0, 19), last.recorded_at.slice(0, 19), 'dispatch calendar fields');
  assert.ok(['started', 'buffered', 'skipped', 'failed'].includes(last.outcome), 'dispatch outcome kind');
  if (last.outcome === 'started') {
    assert.equal(last.reason, null);
    assert.match(last.workflow_instance_id, ulid);
    assert.match(last.workflow_run_id, ulid);
  } else {
    assert.deepStrictEqual([last.workflow_instance_id, last.workflow_run_id], [null, null]);
    if (last.outcome === 'buffered') assert.equal(last.reason, null);
    else assert.ok(typeof last.reason === 'string' && [...last.reason].length <= 64 && Buffer.byteLength(last.reason) <= 256,
      'complete bounded reason');
  }
}

// Manual policy vectors have no scheduler/worker transitions. Validate each
// additive detail against the preceding actual control receipts before the
// historical PHP comparison. Raw observations remain unchanged.
export function checkedPolicyDispatchSummaries(steps, target) {
  const states = new Map();
  return steps.map(step => {
    const id = step.response.schedule_id;
    const state = states.get(id) ?? {last: null, count: 0};
    if (step.status === 200 && step.request.endsWith('/trigger')) {
      const result = step.response;
      const kind = {triggered: 'started', buffered: 'buffered', skipped: 'skipped', buffer_full: 'skipped'}[result.outcome];
      assert.ok(kind, 'recorded manual dispatch outcome');
      state.last = {kind, workflow: result.workflow_id ?? null, run: result.run_id ?? null,
        reason: result.outcome === 'buffer_full' ? 'buffer_full' : result.reason ?? null};
      if (kind === 'buffered') state.count = result.buffer_depth;
      states.set(id, state);
    }
    if (step.status !== 200 || !Object.hasOwn(step.response, 'spec') || !id) return step;
    if (target !== 'rust' && !Object.hasOwn(step.response, 'dispatch_summary')) return step;
    const summary = step.response.dispatch_summary;
    checkDispatchSummary(summary);
    assert.equal(summary.state, state.last ? 'known' : 'never', 'detail follows preceding manual controls');
    assert.equal(summary.buffered_actions_count, state.count, 'detail follows actual buffer writes');
    if (state.last) assert.deepStrictEqual([summary.last_outcome.outcome, summary.last_outcome.reason,
      summary.last_outcome.workflow_instance_id, summary.last_outcome.workflow_run_id],
    [state.last.kind, state.last.reason, state.last.workflow, state.last.run], 'complete last manual outcome');
    const {dispatch_summary, ...response} = step.response;
    return {...step, response};
  });
}
