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
  const rollout = (intent, active, draining) => ({ task_queue:'drain-queue', build_ids:[{
    build_id:'drain-v1', drain_intent:intent, active_worker_count:active, draining_worker_count:draining,
    pending_workflow_tasks:{ ready_count:1, leased_count:0 },
  }] });
  const workers = { workers:['v1', 'v2'].map((build, index) => ({ worker_id:`drain-w${index + 1}`,
    build_id:`drain-${build}`, runtime:'rust', sdk_version:'durable-workflow-rust/3.4.0' })) };
  const definitionOld = { workflow_id:'definition-old', run_id:'definition-old-run' };
  const definitionNew = { workflow_id:'definition-new', run_id:'definition-new-run' };
  const fingerprint = `sha256:${'a'.repeat(64)}`;
  const divergentFingerprint = `sha256:${'b'.repeat(64)}`;
  const definitionWorker = (id, source) => ({ worker_id:id, task_queue:'definition-queue', build_id:'definition-build',
    runtime:'rust', sdk_version:'durable-workflow-rust/3.4.0',
    workflow_definition_fingerprints:{ 'conformance.rust-build-cohort':source } });
  const definitionHistory = { events:[{ event_type:'WorkflowStarted', payload:{
    workflow_definition_fingerprint:fingerprint, workflow_definition_fingerprint_source:'worker',
  } }, { event_type:'SideEffectRecorded' }, { event_type:'SignalReceived' }] };
  const refusal = (reason) => ({ registered:false, http_status:409,
    response:{ reason, workflow_type:'conformance.rust-build-cohort' }, side_effect_calls:0, callbacks:[] });
  return {
    schema:'durable-workflow.conformance.rust-worker-versioning', version:1,
    outcome:'pass', sdk_version:'3.4.0', worker_execution:'managed_rust_sdk_workers',
    local_product_source_checkouts_used:false,
    artifact_versions:{ server:'2.5.11' },
    registry_package:{ version:'3.4.0', source:'registry+https://github.com/rust-lang/crates.io-index', checksum:'a'.repeat(64) },
    cells:{
      divergent_definition_registration:{ original:definitionOld, new:definitionNew,
        task_queue:'definition-queue', build_id:'definition-build', changed_build:'changed-build',
        worker_id:'definition-worker', peer_id:'changed-worker', fingerprint, divergent_fingerprint:divergentFingerprint,
        initial:{ pid:301 }, before:{ workers:[definitionWorker('definition-worker', fingerprint)] },
        preserved:{ workers:[definitionWorker('definition-worker', fingerprint)] },
        peers:{ workers:[definitionWorker('definition-worker', fingerprint), definitionWorker('changed-worker', divergentFingerprint)] },
        changed_registration:refusal('workflow_definition_changed'), missing_registration:refusal('workflow_definition_fingerprint_missing'),
        history_before:definitionHistory, blocked_history:structuredClone(definitionHistory),
        first:poll(definitionOld, 'ready', 1),
        conflicts:{ build_ids:[{ build_id:'definition-build', workflow_definition_fingerprint_conflicts:[{
          workflow_type:'conformance.rust-build-cohort', fingerprint_count:2,
        }] }] }, incompatible_polls:[{ processed:0, side_effect_calls:0, callbacks:[] }, { processed:0, side_effect_calls:0, callbacks:[] }],
        blocked_rollout:{ build_ids:[{ build_id:'definition-build', pending_workflow_tasks:{ ready_count:1, leased_count:0 } }] },
        killed:{ pid:301, signal:9 }, replacement:{ pid:302, registered:true, metrics:{ entries:0, hit:0 } },
        resumed:poll(definitionOld, 'finish', 0), completed:poll(definitionOld, 'completed', 0),
        result:{ producer:'original-definition' }, show:{ ...definitionOld, compatibility:'definition-build', status:'completed' },
        history:{ events:[{ event_type:'SideEffectRecorded' }, { event_type:'WorkflowCompleted' }] },
        recovered:{ build_ids:[{ build_id:'definition-build', workflow_definition_fingerprint_conflicts:[] }] },
        changed_first:poll(definitionNew, 'ready', 1), changed_resumed:poll(definitionNew, 'finish', 1),
        changed_completed:poll(definitionNew, 'completed', 1), changed_result:{ producer:'divergent-definition' },
        changed_show:{ ...definitionNew, compatibility:'changed-build', status:'completed' },
        changed_history:{ events:[{ event_type:'SideEffectRecorded' }, { event_type:'WorkflowCompleted' }] } },
      drain_resume:{ original:drained, v1_build:'drain-v1', v2_build:'drain-v2',
        blocked_debug:{ ...drained, findings:[{ code:'workflow_build_draining', routing_status:'draining',
          required_build_id:'drain-v1', task_queue:'drain-queue', next_event:'Resume and run a worker.', expected_resolution:'Resume and start a compatible worker.' }] },
        resumed_debug:{ ...drained, findings:[{ code:'no_eligible_workflow_worker', routing_status:'no_eligible_worker',
          required_build_id:'drain-v1', task_queue:'drain-queue', next_event:'Worker registers and polls.', expected_resolution:'Start a compatible worker.' }] },
        recovered_debug:{ ...drained, findings:[] }, completed_debug:{ ...drained, findings:[] },
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

function upgradeObservations() {
  const report = observations();
  const original = { workflow_id:'sdk-upgrade', run_id:'sdk-original-run', client:true, sdk_version:'durable-workflow-rust/3.3.3' };
  const fingerprint = `sha256:${'c'.repeat(64)}`;
  const historyBefore = { events:[{ event_type:'WorkflowStarted', payload:{
    workflow_definition_fingerprint:fingerprint, workflow_definition_fingerprint_source:'worker',
  } }, { event_type:'SideEffectRecorded' }] };
  const pendingHistory = { events:[...historyBefore.events, { event_type:'SignalReceived' }] };
  const upgradedHistory = { events:[...pendingHistory.events, { event_type:'SignalApplied' }] };
  const history = { events:[...upgradedHistory.events, { event_type:'SignalReceived' },
    { event_type:'SignalApplied' }, { event_type:'WorkflowCompleted' }] };
  const registry = (version, checksum) => ({ version, source:'registry+https://github.com/rust-lang/crates.io-index', checksum });
  const sources = { 'worker-versioning-rust-upgrade-worker.rs':'d'.repeat(64),
    'worker-versioning-rust-definition-v1.rs':'e'.repeat(64) };
  report.previous_sdk_version = '3.3.3';
  report.upgrade_artifacts = {
    current:{ registry_package:registry('3.4.0', 'a'.repeat(64)), binary_sha256:'b'.repeat(64), source_sha256:sources },
    previous:{ registry_package:registry('3.3.3', 'c'.repeat(64)), binary_sha256:'f'.repeat(64), source_sha256:structuredClone(sources) },
  };
  const worker = (version) => ({ workers:[{ worker_id:'upgrade-worker', task_queue:'upgrade-queue', build_id:null,
    runtime:'rust', sdk_version:`durable-workflow-rust/${version}`,
    workflow_definition_fingerprints:{ 'conformance.rust-sdk-upgrade':fingerprint } }] });
  const initial = (pid, version) => ({ pid, registered:true, sdk_version:`durable-workflow-rust/${version}`, side_effect_calls:0, callbacks:[] });
  const poll = (pid, version, boundary, effects) => ({ pid, sdk_version:`durable-workflow-rust/${version}`,
    processed:1, side_effect_calls:effects, callbacks:[{ workflow_id:original.workflow_id, run_id:original.run_id, boundary }] });
  report.cells.sdk_crate_upgrade = { original, task_queue:'upgrade-queue', worker_id:'upgrade-worker', fingerprint,
    initial:initial(401, '3.3.3'), first:poll(401, '3.3.3', 'ready', 1), before:worker('3.3.3'), history_before:historyBefore,
    killed:{ pid:401, signal:9 }, pending:{ build_ids:[{ build_id:null, pending_workflow_tasks:{ ready_count:1, leased_count:0 } }] },
    pending_history:pendingHistory, successor:initial(402, '3.4.0'), after:worker('3.4.0'),
    resumed:poll(402, '3.4.0', 'finish', 0), upgraded_history:upgradedHistory, successor_killed:{ pid:402, signal:9 },
    replacement:initial(403, '3.4.0'), completed:poll(403, '3.4.0', 'completed', 0),
    result:{ workflow_id:original.workflow_id, run_id:original.run_id, sdk_version:original.sdk_version, result:{ producer:'original-definition' } },
    history, show:{ workflow_id:original.workflow_id, run_id:original.run_id, status:'completed', compatibility:null }, final_workers:worker('3.4.0'),
  };
  return report;
}

test('published SDK upgrade observations pass when explicitly selected', () => {
  assert.equal(rustVersioningPasses(upgradeObservations(), '3.4.0', '3.3.3'), true);
  assert.equal(rustVersioningPasses(observations(), '3.4.0', '3.3.3'), false);
});

const invalidUpgrade = {
  'same crate twice':(r) => { r.previous_sdk_version = '3.4.0'; },
  'previous crate from checkout':(r) => { r.upgrade_artifacts.previous.registry_package.source = null; },
  'missing previous crate checksum':(r) => { delete r.upgrade_artifacts.previous.registry_package.checksum; },
  'changed application sources':(r) => { r.upgrade_artifacts.previous.source_sha256['worker-versioning-rust-upgrade-worker.rs'] = '0'.repeat(64); },
  'same executable twice':(r) => { r.upgrade_artifacts.previous.binary_sha256 = r.upgrade_artifacts.current.binary_sha256; },
  'previous SDK never registered':(r) => { r.cells.sdk_crate_upgrade.before.workers[0].sdk_version = 'durable-workflow-rust/3.4.0'; },
  'upgrade changed source identity':(r) => { r.cells.sdk_crate_upgrade.after.workers[0].workflow_definition_fingerprints = {}; },
  'upgrade changed original build':(r) => { r.cells.sdk_crate_upgrade.show.compatibility = 'new-build'; },
  'missing pending signal':(r) => { r.cells.sdk_crate_upgrade.pending_history.events.pop(); },
  'pending upgrade task acquired':(r) => { r.cells.sdk_crate_upgrade.pending.build_ids[0].pending_workflow_tasks.leased_count = 1; },
  'upgrade reused previous process':(r) => { r.cells.sdk_crate_upgrade.successor.pid = 401; },
  'upgrade repeated producer':(r) => { r.cells.sdk_crate_upgrade.resumed.side_effect_calls = 1; },
  'post-upgrade worker not killed':(r) => { r.cells.sdk_crate_upgrade.successor_killed.signal = 15; },
  'replacement did not deliver completion':(r) => { r.cells.sdk_crate_upgrade.completed.processed = 0; },
  'older caller got a different run':(r) => { r.cells.sdk_crate_upgrade.result.run_id = 'other-run'; },
  'older caller lost recorded result':(r) => { r.cells.sdk_crate_upgrade.result.result.producer = 'other'; },
  'upgraded history overwrote old history':(r) => { r.cells.sdk_crate_upgrade.history.events[0] = { event_type:'Replaced' }; },
  'upgrade duplicated completion':(r) => { r.cells.sdk_crate_upgrade.history.events.push({ event_type:'WorkflowCompleted' }); },
};
for (const [name, mutate] of Object.entries(invalidUpgrade)) {
  test(`reject SDK upgrade: ${name}`, () => {
    const report = upgradeObservations();
    mutate(report);
    assert.equal(rustVersioningPasses(report, '3.4.0', '3.3.3'), false);
  });
}

const invalid = {
  'divergent source accepted':(r) => { r.cells.divergent_definition_registration.changed_registration.registered = true; },
  'wrong divergent refusal reason':(r) => { r.cells.divergent_definition_registration.changed_registration.response.reason = 'other'; },
  'omitted source accepted':(r) => { r.cells.divergent_definition_registration.missing_registration.http_status = 201; },
  'changed registration overwrote original source':(r) => { r.cells.divergent_definition_registration.preserved.workers[0].workflow_definition_fingerprints = {}; },
  'unbound original definition':(r) => { r.cells.divergent_definition_registration.history_before.events[0].payload.workflow_definition_fingerprint_source = 'caller'; },
  'divergent code reused fingerprint':(r) => { r.cells.divergent_definition_registration.divergent_fingerprint = r.cells.divergent_definition_registration.fingerprint; },
  'silent cohort definition conflict':(r) => { r.cells.divergent_definition_registration.conflicts.build_ids[0].workflow_definition_fingerprint_conflicts = []; },
  'divergent same-build delivery':(r) => { r.cells.divergent_definition_registration.incompatible_polls[0].processed = 1; },
  'divergent task lease':(r) => { r.cells.divergent_definition_registration.blocked_rollout.build_ids[0].pending_workflow_tasks.leased_count = 1; },
  'original definition history mutated':(r) => { r.cells.divergent_definition_registration.blocked_history.events.push({ event_type:'WorkflowFailed' }); },
  'definition recovery repeated producer':(r) => { r.cells.divergent_definition_registration.resumed.side_effect_calls = 1; },
  'definition recovery reused killed process':(r) => { r.cells.divergent_definition_registration.replacement.pid = 301; },
  'definition recovery changed run pin':(r) => { r.cells.divergent_definition_registration.show.compatibility = 'changed-build'; },
  'definition recovery changed result':(r) => { r.cells.divergent_definition_registration.result.producer = 'divergent-definition'; },
  'definition completion duplicated':(r) => { r.cells.divergent_definition_registration.history.events.push({ event_type:'WorkflowCompleted' }); },
  'retired definition still conflicts':(r) => { r.cells.divergent_definition_registration.recovered.build_ids[0].workflow_definition_fingerprint_conflicts = [{}]; },
  'changed positive control never ran':(r) => { r.cells.divergent_definition_registration.changed_completed.processed = 0; },
  'changed positive control has original behavior':(r) => { r.cells.divergent_definition_registration.changed_result.producer = 'original-definition'; },
  'missing drain routing explanation':(r) => { r.cells.drain_resume.blocked_debug.findings = []; },
  'missing resume recovery guidance':(r) => { r.cells.drain_resume.resumed_debug.findings[0].expected_resolution = ''; },
  'routing explanation for another build':(r) => { r.cells.drain_resume.blocked_debug.findings[0].required_build_id = 'v2'; },
  'routing explanation for another run':(r) => { r.cells.drain_resume.resumed_debug.run_id = 'other-run'; },
  'recovery still reported routing blocked':(r) => { r.cells.drain_resume.recovered_debug.findings = r.cells.drain_resume.resumed_debug.findings; },
  'terminal run still reported drained':(r) => { r.cells.drain_resume.completed_debug.findings = r.cells.drain_resume.blocked_debug.findings; },
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
