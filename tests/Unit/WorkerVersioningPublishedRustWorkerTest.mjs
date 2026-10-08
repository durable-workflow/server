import assert from 'node:assert/strict';
import test from 'node:test';
import { rustVersioningPasses } from '../../scripts/conformance/worker-versioning-rust-published-workers.mjs';

function observations() {
  const old = { workflow_id:'old', run_id:'original-run' };
  const newer = { workflow_id:'new', run_id:'new-run' };
  const drained = { workflow_id:'drained', run_id:'drain-run' };
  const poll = (identity, boundary, effects, metrics = {}) => ({
    processed:1, side_effect_calls:effects, metrics,
    callbacks:[{ ...identity, boundary }],
  });
  const firstDrainPoll = { ...poll(drained, 'ready', 1), pid:201 };
  const held = { ...firstDrainPoll, processed:0 };
  const rollout = (intent, active, draining) => ({ build_ids:[{
    build_id:'drain-v1', drain_intent:intent, active_worker_count:active, draining_worker_count:draining,
    pending_workflow_tasks:{ ready_count:1, leased_count:0 },
  }] });
  const workers = { workers:['v1', 'v2'].map((build, index) => ({ worker_id:`drain-w${index + 1}`,
    build_id:`drain-${build}`, runtime:'rust', sdk_version:'durable-workflow-rust/3.4.0' })) };
  return {
    schema:'durable-workflow.conformance.rust-worker-versioning', version:1,
    outcome:'pass', sdk_version:'3.4.0', worker_execution:'managed_rust_sdk_workers',
    local_product_source_checkouts_used:false,
    registry_package:{ version:'3.4.0', source:'registry+https://github.com/rust-lang/crates.io-index', checksum:'a'.repeat(64) },
    cells:{
      drain_resume:{ original:drained, v1_build:'drain-v1', v2_build:'drain-v2',
        v1_worker_id:'drain-w1', v2_worker_id:'drain-w2', workers, restored_workers:workers,
        initial:{ pid:201 }, first:firstDrainPoll, before:rollout('active', 1, 0),
        promotion:{ build_id:'drain-v2', new_start_selected:true },
        drain:{ build_id:'drain-v1', drain_intent:'draining', drained_at:'2026-10-08T12:00:00Z' },
        duplicate_drain:{ drained_at:'2026-10-08T12:00:00Z' }, repeated_after_ms:1100, blocked_rollout:rollout('draining', 0, 1),
        blocked_show:{ ...drained, compatibility:'drain-v1', status:'waiting' },
        blocked_history:{ events:[{ event_type:'SignalReceived' }, { event_type:'SideEffectRecorded' }] },
        drained_polls:[held, held], incompatible_polls:[{ processed:0 }, { processed:0 }, { processed:0 }],
        shutdown:{ pid:201, exit_code:0, signal:null, response:held }, absent_rollout:rollout('draining', 0, 0),
        resume:{ build_id:'drain-v1', drain_intent:'active', drained_at:null }, duplicate_resume:{ drain_intent:'active' },
        resumed_rollout:rollout('active', 0, 0), replacement:{ pid:202, metrics:{ entries:0, hit:0 } },
        resumed:{ ...poll(drained, 'finish', 0), pid:202 }, completed:poll(drained, 'completed', 0),
        show:{ ...drained, status:'completed', compatibility:'drain-v1' }, result:{ producer:'original-drain-v1' },
        history:{ events:[{ event_type:'SideEffectRecorded' }, { event_type:'WorkflowCompleted' }] } },
      registration_build_ids:{ v1_build:'v1', v2_build:'v2', v1_worker_id:'w1', v2_worker_id:'w2',
        workers:{ workers:['v1', 'v2'].map((build, index) => ({
          worker_id:`w${index + 1}`, build_id:build, runtime:'rust', sdk_version:'durable-workflow-rust/3.4.0',
        })) } },
      pinned_delivery_and_promotion:{ old, new:newer, v1_build:'v1', v2_build:'v2',
        wrong_old_poll:{ processed:0 }, wrong_new_poll:{ processed:0 },
        first_v1_poll:poll(old, 'ready', 1), new_completed:poll(newer, 'completed', 1) },
      cache_eviction_replay:{ original:old, eviction:{ metrics:{ eviction:1 } },
        cold_replay:poll(old, 'finish', 2, { forced_cold_replay:1 }) },
      no_compatible_worker:{ original:old,
        show:{ run_id:old.run_id, compatibility:'v1', compatibility_status:'no_compatible_worker' },
        incompatible_polls:[{ processed:0 }] },
      sigkill_cold_replay:{ original:old, first_replacement:{ pid:101 }, killed:{ pid:101, signal:9 },
        resumed:poll(old, 'settle', 0, { entries:1 }),
        replacement:{ pid:102, metrics:{ entries:0, hit:0 } },
        completed:poll(old, 'completed', 0, { entries:0 }), result:{ producer:'original-v1' },
        show:{ run_id:old.run_id, compatibility:'v1', status:'completed' },
        history:{ events:[{ event_type:'SideEffectRecorded' }, { event_type:'WorkflowCompleted' }] } },
    },
  };
}

test('complete managed-worker observations pass', () => {
  assert.equal(rustVersioningPasses(observations(), '3.4.0'), true);
});

const invalid = {
  'wrong promoted peer during drain':(r) => { r.cells.drain_resume.promotion.build_id = 'drain-v1'; },
  'empty queue during drain':(r) => { r.cells.drain_resume.before.build_ids[0].pending_workflow_tasks.ready_count = 0; },
  'drained task claimed':(r) => { r.cells.drain_resume.drained_polls[0].processed = 1; },
  'callback advanced while drained':(r) => { r.cells.drain_resume.drained_polls[0].callbacks = []; },
  'drained backlog disappeared':(r) => { r.cells.drain_resume.blocked_rollout.build_ids[0].pending_workflow_tasks.ready_count = 0; },
  'drained task leased':(r) => { r.cells.drain_resume.blocked_rollout.build_ids[0].pending_workflow_tasks.leased_count = 1; },
  'drain timestamp changed on repeat':(r) => { r.cells.drain_resume.duplicate_drain.drained_at = 'later'; },
  'repeat drain within timestamp resolution':(r) => { r.cells.drain_resume.repeated_after_ms = 0; },
  'signal consumed while drained':(r) => { r.cells.drain_resume.blocked_history.events.push({ event_type:'SignalApplied' }); },
  'drain worker killed':(r) => { r.cells.drain_resume.shutdown.signal = 9; },
  'drain worker failed':(r) => { r.cells.drain_resume.shutdown.exit_code = 1; },
  'drain worker stayed active':(r) => { r.cells.drain_resume.absent_rollout.build_ids[0].active_worker_count = 1; },
  'resume left build drained':(r) => { r.cells.drain_resume.resumed_rollout.build_ids[0].drain_intent = 'draining'; },
  'resume falsely marked absent worker active':(r) => { r.cells.drain_resume.resumed_rollout.build_ids[0].active_worker_count = 1; },
  'resume reused exited process':(r) => { r.cells.drain_resume.replacement.pid = 201; },
  'resumed task was never delivered':(r) => { r.cells.drain_resume.resumed.processed = 0; },
  'resumed producer repeated':(r) => { r.cells.drain_resume.resumed.side_effect_calls = 1; },
  'drained original build changed':(r) => { r.cells.drain_resume.show.compatibility = 'drain-v2'; },
  'drained result lost':(r) => { r.cells.drain_resume.result.producer = 'replacement'; },
  'drained completion duplicated':(r) => { r.cells.drain_resume.history.events.push({ event_type:'WorkflowCompleted' }); },
  'incompatible delivery':(r) => { r.cells.pinned_delivery_and_promotion.wrong_old_poll.processed = 1; },
  'wrong callback run':(r) => { r.cells.sigkill_cold_replay.completed.callbacks[0].run_id = 'different-run'; },
  'old waiting state without delivery':(r) => { r.cells.sigkill_cold_replay.resumed.processed = 0; },
  'missing compatibility diagnosis':(r) => { r.cells.no_compatible_worker.show.compatibility_status = 'compatible'; },
  'changed original build':(r) => { r.cells.sigkill_cold_replay.show.compatibility = 'v2'; },
  'repeated side-effect producer':(r) => { r.cells.sigkill_cold_replay.completed.side_effect_calls = 1; },
  'repeated completion':(r) => { r.cells.sigkill_cold_replay.history.events.push({ event_type:'WorkflowCompleted' }); },
  'graceful exit instead of SIGKILL':(r) => { r.cells.sigkill_cold_replay.killed.signal = 15; },
  'same worker process':(r) => { r.cells.sigkill_cold_replay.replacement.pid = 101; },
  'nonempty successor cache':(r) => { r.cells.sigkill_cold_replay.replacement.metrics.entries = 1; },
  'wrong installed SDK':(r) => { r.cells.registration_build_ids.workers.workers[0].sdk_version = 'durable-workflow-rust/3.3.3'; },
  'missing required cell':(r) => { delete r.cells.no_compatible_worker; },
  'SDK from a checkout':(r) => { r.registry_package.source = null; },
  'missing registry checksum':(r) => { delete r.registry_package.checksum; },
};
for (const [name, mutate] of Object.entries(invalid)) {
  test(`reject ${name} despite a pass label`, () => {
    const report = observations();
    mutate(report);
    assert.equal(rustVersioningPasses(report, '3.4.0'), false);
  });
}

test('missing or failed results cannot pass', () => {
  assert.equal(rustVersioningPasses(null, '3.4.0'), false);
  assert.equal(rustVersioningPasses({ ...observations(), outcome:'fail' }, '3.4.0'), false);
});
