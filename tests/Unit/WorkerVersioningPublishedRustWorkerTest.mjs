import assert from 'node:assert/strict';
import test from 'node:test';
import { rustVersioningPasses } from '../../scripts/conformance/worker-versioning-rust-published-workers.mjs';

function observations() {
  const old = { workflow_id:'old', run_id:'original-run' };
  const newer = { workflow_id:'new', run_id:'new-run' };
  const poll = (identity, boundary, effects, metrics = {}) => ({
    processed:1, side_effect_calls:effects, metrics,
    callbacks:[{ ...identity, boundary }],
  });
  return {
    schema:'durable-workflow.conformance.rust-worker-versioning', version:1,
    outcome:'pass', sdk_version:'3.4.0', worker_execution:'managed_rust_sdk_workers',
    local_product_source_checkouts_used:false,
    registry_package:{ version:'3.4.0', source:'registry+https://github.com/rust-lang/crates.io-index', checksum:'a'.repeat(64) },
    cells:{
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
