import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkObservation} from '../../scripts/conformance/server-parity/contract.mjs';
import {childCancellationObservation} from '../Support/ServerParityChildCancellationObservation.mjs';

const read = path => JSON.parse(readFileSync(new URL(path, import.meta.url)));
for (const phase of ['before-claim', 'pending-timer']) {
  const fixture = read(`../Fixtures/ServerParity/direct-child-cancel-${phase}.json`);
  const examples = ['http', 'embedded'].map(mode => childCancellationObservation(fixture, mode));
  const check = raw => checkObservation(fixture, raw, `child-reference-${phase}`);
  test(`${phase}: modeled SDK and embedded representations project equally`, () => {
    assert.deepStrictEqual(check(examples[0]), check(examples[1]));
  });
  for (const example of examples) {
    const event = (raw, type) => raw.events.find(row => row.event_type === type).payload;
    const childEvent = (raw, type) => raw.children[0].events.find(row => row.event_type === type).payload;
    const mutations = {
      'replacement child run': raw => { event(raw, 'ChildRunCancelled').child_workflow_run_id = 'replacement'; },
      'replacement child instance': raw => { event(raw, 'ChildRunCancelled').child_workflow_instance_id = 'replacement'; },
      'replacement call': raw => { event(raw, 'ChildRunCancelled').child_call_id = 'replacement'; },
      'replacement link': raw => { event(raw, 'ChildRunCancelled').workflow_link_id = 'replacement'; },
      'replacement parent startup': raw => { childEvent(raw, 'WorkflowStarted').parent_workflow_run_id = 'replacement'; },
      'replacement child type': raw => { event(raw, 'ChildRunCancelled').child_workflow_type = 'replacement'; },
      'replacement failure': raw => { event(raw, 'ChildRunCancelled').failure_id = 'replacement'; },
      'replacement cancel command': raw => { childEvent(raw, 'WorkflowCancelled').workflow_command_id = 'replacement'; },
      'successful child substitution': raw => { raw.children[0].status = 'completed'; },
      'missing child': raw => { raw.children = []; },
      'extra child': raw => { raw.children.push(structuredClone(raw.children[0])); },
      'rounded child int64': raw => { raw.children[0].typed_input.value[0].value.count.value = '9007199254740992'; },
      'lost caller reason': raw => { childEvent(raw, 'WorkflowCancelled').reason = 'replacement'; },
      'truncated child diagnostic': raw => { childEvent(raw, 'WorkflowCancelled').message = 'Workflow cancelled:'; },
      'truncated parent diagnostic': raw => { raw.child_cancellation.caught.message = 'child cancelled'; },
      'wrong caught exception': raw => { raw.child_cancellation.caught.class = 'RuntimeException'; },
      'successful child output': raw => { raw.children[0].output = fixture.input; },
      'wrong receipt scope': raw => { raw.child_cancellation.accepted.response.target_scope = 'replacement'; },
      'wrong resolved receipt run': raw => { raw.child_cancellation.accepted.response.resolved_run_id = 'replacement'; },
      'accepted replacement cancellation': raw => { raw.child_cancellation.duplicate.status = 200; },
      'duplicate changes child history': raw => { raw.child_cancellation.child_history_after_duplicate.pop(); },
      'duplicate changes parent history': raw => { raw.child_cancellation.parent_history_after_duplicate.pop(); },
      'post-parent duplicate changes child': raw => { raw.child_cancellation.child_history_after_terminal_duplicate.pop(); },
      'post-parent duplicate changes parent': raw => { raw.child_cancellation.parent_history_after_terminal_duplicate.pop(); },
      'fresh child changes history': raw => { raw.child_cancellation.fresh_child_history.pop(); },
      'fresh parent changes history': raw => { raw.child_cancellation.fresh_parent_history.pop(); },
      'fresh child replacement': raw => { raw.child_cancellation.fresh_child.run_id = 'replacement'; },
      'fresh parent cancelled': raw => { raw.child_cancellation.fresh_parent.status = 'cancelled'; },
      'wrong cancellation phase': raw => { raw.child_cancellation.child_before.status = 'running'; },
      'extra child history': raw => { raw.children[0].events.push(structuredClone(raw.children[0].events.at(-1))); },
      'missing child history': raw => { raw.children[0].events.pop(); },
      'wrong child sequence': raw => { raw.children[0].events[0].sequence = 9; },
    };
    if (phase === 'pending-timer') Object.assign(mutations, {
      'replacement child timer': raw => { childEvent(raw, 'TimerCancelled').timer_id = 'replacement'; },
      'changed child timer budget': raw => { childEvent(raw, 'TimerScheduled').delay_seconds = 1; },
      'changed child fire time': raw => { childEvent(raw, 'TimerCancelled').fire_at = '2027-01-01T00:00:00Z'; },
      'child timer fired before cancel': raw => { childEvent(raw, 'TimerCancelled').cancelled_at = '2027-01-01T00:00:00Z'; },
    });
    if (example.mode === 'embedded') Object.assign(mutations, {
      'handled unrelated failure': raw => { event(raw, 'FailureHandled').failure_id = 'replacement'; },
      'handled unrelated child': raw => { event(raw, 'FailureHandled').source_id = 'replacement'; },
      'missing handled event': raw => { raw.events = raw.events.filter(row => row.event_type !== 'FailureHandled'); },
      'duplicate changes physical failure': raw => { raw.child_cancellation.failures_after_duplicate[0].message = 'replacement'; },
      'fresh physical failure changes': raw => { raw.child_cancellation.fresh_failures[0].message = 'replacement'; },
    });
    else Object.assign(mutations, {
      'SDK receives failed child instead': raw => { raw.child_cancellation.caught.failure_type = 'ChildRunFailed'; },
      'SDK loses original child payload': raw => { raw.child_cancellation.caught.payload.child_workflow_run_id = 'replacement'; },
      'SDK missing parent resumption': raw => { raw.workflow_polls.pop(); },
      'SDK resumed unrelated child': raw => { raw.workflow_polls.at(-1).child_workflow_run_id = 'replacement'; },
      'SDK claims cancelled child again': raw => { raw.workflow_polls.push({run_id: raw.children[0].run_id}); },
    });
    for (const [name, mutate] of Object.entries(mutations)) test(`${example.mode} ${phase}: rejects ${name}`, () => {
      const damaged = structuredClone(example);
      mutate(damaged);
      assert.throws(() => check(damaged));
    });
  }
}
