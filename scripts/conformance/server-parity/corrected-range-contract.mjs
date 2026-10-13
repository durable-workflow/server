import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {gunzipSync, inflateSync, brotliDecompressSync} from 'node:zlib';
import {checkRangeRepair} from './range-repair-contract.mjs';

const sha = /^[a-f0-9]{64}$/;
const commit = /^[a-f0-9]{40}$/;

export function checkCorrectedRangeArtifacts(fixture, artifacts) {
  if (!fixture.corrected_range) return;
  const spec = fixture.corrected_range;
  const original = JSON.parse(readFileSync(new URL('../../../'+spec.original_fixture, import.meta.url)));
  assert.deepEqual(fixture.typed_value, original.typed_value);
  assert.deepEqual(fixture.input, original.input);
  assert.deepEqual(fixture.expected_events, original.expected_events);
  assert.equal(fixture.workflow_type, original.workflow_type);
  assert.equal(spec.change_id, original.range_repair.change_id);
  assert.deepEqual(spec.original_range, original.range_repair.original_range);
  assert.deepEqual(spec.incompatible_range, original.range_repair.incompatible_range);
  assert.equal(spec.original_event_count, original.range_repair.original_event_count);
  assert.equal(spec.blocked_event_count, original.range_repair.blocked_event_count);
  assert.equal(spec.pending_activity, Boolean(original.range_repair.pending_activity));
  assert.equal(spec.overall_budget_seconds, 35);
  assert.match(artifacts.sdk_php_source_commit, commit);
  assert.match(artifacts.sdk_composer_lock_sha256, sha);
  assert.match(artifacts.rust_consumer_cargo_lock_sha256, sha);
  for (const key of ['source_commit', 'composer_lock_sha256']) {
    assert.match(artifacts.historical_php_range_producer[key], key === 'source_commit' ? commit : sha);
  }
  assert.equal(artifacts.historical_php_range_producer.version, '2.2.7');
  assert.equal(artifacts.published_sdk_artifacts.rust.version, artifacts.sdk_rust);
}

export function checkCorrectedRangePackages(fixture, observation, artifacts) {
  if (!fixture.corrected_range) return;
  checkCorrectedRangeArtifacts(fixture, artifacts);
  assert.equal(observation.sdk_php, artifacts.sdk_php);
  assert.equal(observation.sdk_php_source, artifacts.sdk_php_source_commit);
  assert.equal(observation.sdk_composer_lock_sha256, artifacts.sdk_composer_lock_sha256);
  assert.equal(observation.rust_consumer_cargo_lock_sha256, artifacts.rust_consumer_cargo_lock_sha256);
  assert.deepEqual(observation.sdk_loaded_sources, artifacts.published_sdk_artifacts.php.loaded_sources);
  if (observation.mode === 'embedded') {
    assert.equal(observation.workflow_package, artifacts.workflow);
    assert.equal(observation.workflow_source, artifacts.workflow_source_commit);
    return;
  }
  const original = observation.corrected_range.phases.original.actor;
  const expected = artifacts.historical_php_range_producer;
  assert.equal(original.sdk_php, expected.version);
  assert.equal(original.sdk_php_source, expected.source_commit);
  assert.equal(original.sdk_composer_lock_sha256, expected.composer_lock_sha256);
  assert.deepEqual(original.sdk_loaded_sources, expected.loaded_sources);
  for (const [name, phase] of Object.entries(observation.corrected_range.phases)) {
    if (['original', 'repair'].includes(name)) continue;
    assert.equal(phase.actor.sdk_version, `durable-workflow-rust/${artifacts.sdk_rust}`);
  }
}

function requests(observation) {
  return observation.corrected_range.requests.map(wire => {
    assert.equal(wire.transport_error, null);
    assert.equal(Boolean(wire.client_cancelled), false);
    const decode = {identity: value => value, gzip: gunzipSync, deflate: inflateSync, br: brotliDecompressSync}[wire.response_encoding];
    assert(decode);
    assert.equal(decode(Buffer.from(wire.response_bytes_base64, 'base64')).toString('utf8'), wire.response_body);
    return {...wire, request: wire.request_body ? JSON.parse(wire.request_body) : null,
      response: wire.response_body ? JSON.parse(wire.response_body) : null};
  });
}

export function checkCorrectedRange(fixture, observation, workflowId, instant) {
  const spec = fixture.corrected_range;
  assert.equal(observation.workflow_id, workflowId);
  assert.equal(observation.workflow_type, fixture.workflow_type);
  assert.equal(observation.namespace, 'default');
  assert.equal(observation.task_queue, 'server-parity-v1');
  assert.equal(observation.status, 'completed');
  assert.equal(observation.payload_codec, 'avro');
  assert.deepEqual(observation.typed_input, {type: 'list', value: [fixture.typed_value]});
  assert.deepEqual(observation.typed_output, fixture.typed_value);
  assert.deepEqual(observation.events.map(event => event.event_type), fixture.expected_events);
  assert.deepEqual(observation.events.map(event => event.sequence), observation.events.map((_, index) => index + 1));
  assert.equal(observation.corrected_range.overall_budget_seconds, 35);
  if (observation.mode === 'embedded') {
    assert.equal(observation.corrected_range.applicable, false);
    assert.equal(observation.corrected_range.reason, 'embedded_executes_original_php_definitions');
    const original = JSON.parse(readFileSync(new URL('../../../'+spec.original_fixture, import.meta.url)));
    checkRangeRepair(original, observation, workflowId, instant);
    return {workflow_id: workflowId, typed_output: fixture.typed_value,
      events: fixture.expected_events, recorded_version: 1, replay_blocked: true,
      original_policy_preserved: true, selected_original_repair: true, pending_activity: spec.pending_activity};
  }
  const {phases, recorded_policy: policy} = observation.corrected_range;
  assert.deepEqual(Object.keys(observation.corrected_range.neighbors), ['left', 'right']);
  for (const [side, {before, after}] of Object.entries(observation.corrected_range.neighbors)) {
    assert.equal(before.workflow_id, workflowId+'-neighbor-'+side);
    assert.notEqual(before.run_id, observation.run_id);
    assert.equal(after.run_id, before.run_id);
    assert.equal(before.task_queue, before.workflow_id+'-queue');
    assert.equal(after.status, before.status);
    assert.deepEqual(before.events.map(row => row.event_type), ['WorkflowStarted']);
    assert.deepEqual(after.events, before.events, 'neighbor history survives original-task repair');
    assert.deepEqual(after.typed_input, before.typed_input);
    for (const field of ['execution_deadline_at', 'run_deadline_at']) assert.equal(after.execution[field], before.execution[field]);
    assert.deepEqual(after.debug.pending_workflow_tasks, before.debug.pending_workflow_tasks,
      'neighbor original task identity, attempts and repair count survive');
  }
  const order = spec.pending_activity ? ['original', 'incompatible', 'repair', 'repaired-wait', 'activity', 'final']
    : ['original', 'activity', 'incompatible', 'repair', 'final'];
  assert.deepEqual(Object.keys(phases), order);
  const pids = [phases.original.actor.pid];
  for (const [name, phase] of Object.entries(phases)) {
    assert.equal(phase.run_id, observation.run_id);
    assert.equal(phase.workflow_id, workflowId);
    assert.equal(phase.namespace, 'default');
    assert.equal(phase.payload_codec, 'avro');
    assert.equal(phase.debug_status, 200);
    assert.deepEqual(observation.events.slice(0, phase.events.length), phase.events, 'complete immutable phase prefix and clocks');
    for (const key of ['execution_deadline_at', 'run_deadline_at']) {
      assert.equal(phase.execution[key], phases.original.execution[key], 'original durable deadline');
    }
    if (['original', 'repair'].includes(name)) continue;
    pids.push(phase.actor.pid);
    assert.equal(phase.actor.workflow_id, workflowId);
    assert.equal(phase.actor.run_id, observation.run_id);
    assert.equal(phase.actor.case, spec.pending_activity ? 'range_pending' : 'range_completed');
    assert.equal(phase.actor.mode, name === 'activity' ? 'activity' : 'workflow');
    assert.equal(phase.actor.range_profile, name === 'incompatible' ? 'excluding' : name === 'repaired-wait' ? 'repaired-wait' : 'restored');
    assert.deepEqual(JSON.parse(phase.actor.retry_policy), policy, 'whole original known policy, including every interval');
    assert.deepEqual(phase.actor.outcome, {Ok: 1});
    assert.deepEqual(phase.actor.deregistered, {Ok: true});
  }
  assert(pids.every(pid => Number.isSafeInteger(pid) && pid > 0));
  assert.equal(new Set(pids).size, pids.length, 'all actual actor processes are independent');
  assert.equal(phases.original.status, 'waiting');
  assert.equal(phases.incompatible.status, 'waiting');
  assert.equal(phases.final.status, 'completed');
  assert.equal(phases.original.events.length, spec.original_event_count);
  assert.equal(phases.incompatible.events.length, spec.blocked_event_count);
  assert.deepEqual(phases.original.actor.events, phases.original.events);
  assert.deepEqual(phases.original.actor.versions, [1]);
  assert.equal(phases.original.actor.activity_invocations, 0);
  assert.equal(phases.incompatible.actor.decisions.length, 1);
  assert.equal(phases.incompatible.actor.decisions[0].change_id, spec.change_id);
  assert.match(phases.incompatible.actor.decisions[0].range_refusal, /version_marker_incompatible_range/);
  assert.deepEqual(phases.final.actor.decisions[0], [1]);
  const event = name => {
    const found = observation.events.filter(row => row.event_type === name);
    assert.equal(found.length, 1, name+' committed once');
    return found[0];
  };
  const marker = event('VersionMarkerRecorded');
  assert.equal(marker.payload.sequence, 1);
  assert.equal(marker.payload.change_id, spec.change_id);
  assert.equal(marker.payload.version, 1);
  assert.equal(marker.payload.min_supported, 1);
  assert.equal(marker.payload.max_supported, 1);
  const scheduled = event('ActivityScheduled');
  const activityId = scheduled.payload.activity_execution_id;
  assert.deepEqual(scheduled.payload.activity.retry_policy, policy);
  assert.equal(policy.snapshot_version, 1);
  assert.equal(policy.max_attempts, 1);
  assert.deepEqual(policy.backoff_seconds, [1,2,5,10,15,30,60,120]);
  assert.deepEqual(scheduled.typed_decoded.activity_arguments, {type: 'list', value: [fixture.typed_value]});
  for (const name of ['ActivityStarted', 'ActivityCompleted']) assert.equal(event(name).payload.activity_execution_id, activityId);
  assert.deepEqual(event('ActivityCompleted').typed_decoded.result, fixture.typed_value);
  assert.deepEqual(event('WorkflowCompleted').typed_decoded.output, fixture.typed_value);
  const repaired = event('RepairRequested').payload;
  assert.equal(repaired.task_type, 'workflow');
  assert.equal(repaired.task.id, repaired.task_id);
  assert.equal(repaired.task.repair_count, 1);
  assert.equal(repaired.command.resolved_run_id, observation.run_id);
  const failed = phases.incompatible.debug.latest_workflow_task_failure;
  assert.equal(phases.incompatible.debug.execution.liveness_state, 'workflow_replay_blocked');
  assert.equal(failed.replay_blocked, true);
  assert.equal(failed.type, 'RustWorkflowTaskFailure');
  assert.match(failed.message, /non-deterministic workflow replay \(version_marker_incompatible_range\) at sequence Some\(1\)/);
  assert.equal(Object.hasOwn(failed, 'reason'), false);
  assert.equal(Object.hasOwn(failed, 'sequence'), false);
  assert.equal(repaired.task_id, failed.task_id, 'repair the blocked original logical task');
  assert.equal(phases.repair.actor.repair.outcome, 'repair_dispatched');
  assert.deepEqual(phases.repair.events.slice(0, phases.incompatible.events.length), phases.incompatible.events);
  assert.deepEqual(phases.incompatible.debug.recent_failures, []);
  assert.deepEqual(phases.incompatible.debug.pending_workflow_tasks, []);
  const wire = requests(observation);
  const failures = wire.filter(row => row.method === 'POST' && row.path.endsWith('/fail')
    && row.request?.failure?.type === 'RustWorkflowTaskFailure');
  assert.equal(failures.length, 1);
  const failure = failures[0];
  assert.equal(failure.status, 200);
  assert.equal(failure.path.split('/').at(-2), failed.task_id);
  assert.equal(failure.request.workflow_task_attempt, 1);
  assert.equal(failure.response.recorded, true);
  assert.equal(failure.response.next_task_id, null);
  const repairs = wire.filter(row => row.method === 'POST' && row.path.endsWith('/repair'));
  assert.equal(repairs.length, 1);
  assert(repairs[0].path.includes('/runs/'+observation.run_id+'/'));
  assert.equal(repairs[0].status, 200);
  const finalTask = event('WorkflowCompleted').payload.task;
  const completions = wire.filter(row => row.method === 'POST' && row.path.startsWith('/api/worker/workflow-tasks/')
    && row.path.endsWith('/complete') && row.request?.commands?.some(command => command.type === 'complete_workflow'));
  assert.equal(completions.length, 1);
  assert.equal(completions[0].status, 200);
  assert.equal(completions[0].response.recorded, true);
  assert.equal(completions[0].path.split('/').at(-2), finalTask.id);
  assert.equal(completions[0].request.lease_owner, finalTask.lease_owner);
  if (spec.pending_activity) {
    const timer = event('TimerScheduled'), fired = event('TimerFired'), selection = event('SelectionResolved');
    assert.equal(timer.payload.sequence, 3);
    assert.equal(timer.payload.delay_seconds, 3);
    assert.equal(fired.payload.timer_id, timer.payload.timer_id);
    assert.equal(fired.payload.fire_at, timer.payload.fire_at);
    assert(instant(fired.payload.fired_at) >= instant(timer.payload.fire_at));
    assert.equal(selection.payload.member_key, 'timer');
    assert.equal(selection.payload.operation_identity, timer.payload.timer_id);
    for (const name of ['incompatible', 'repair', 'repaired-wait']) {
      assert.equal(phases[name].status, 'waiting');
      assert(!phases[name].events.some(row => row.event_type === 'ActivityStarted' || row.event_type === 'ActivityCompleted'));
      assert.equal(phases[name].debug.pending_activities.length, 1);
      assert.equal(phases[name].debug.pending_activities[0].activity_execution_id, activityId);
    }
    assert.deepEqual(phases['repaired-wait'].events, phases.repair.events, 'repaired original still awaits genuine activity');
    assert.equal(phases['repaired-wait'].actor.decisions[1].winner, 'timer');
    assert.notEqual(finalTask.id, repaired.task_id, 'completion uses a later task after actual activity completion');
    assert.equal(finalTask.attempt_count, 1);
    assert.equal(finalTask.repair_count, 0);
  } else {
    assert.deepEqual(phases.incompatible.events, phases.activity.events, 'range refusal adds no history');
    assert.equal(finalTask.id, repaired.task_id);
    assert.equal(finalTask.attempt_count, 2);
    assert.equal(finalTask.repair_count, 1);
    assert.notEqual(completions[0].request.lease_owner, failure.request.lease_owner);
  }
  return {workflow_id: workflowId, typed_output: fixture.typed_value,
    events: fixture.expected_events, recorded_version: 1, replay_blocked: true,
    original_policy_preserved: true, selected_original_repair: true, pending_activity: spec.pending_activity};
}
