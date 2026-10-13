// Refuse corrupted copies of actual admissions, deliveries and repair receipts.
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkObservation} from '../contract.mjs';
import {checkCorrectedRangePackages} from '../corrected-range-contract.mjs';

const record = JSON.parse(readFileSync(process.argv[2]));
assert.equal(record.outcome, 'pass');
const results = [];
for (const item of record.cases.filter(item => item.fixture.corrected_range)) {
  const original = item.observation, fixture = item.fixture;
  const read = value => {
    checkCorrectedRangePackages(fixture, value, record.artifacts);
    return checkObservation(fixture, value, original.workflow_id);
  };
  read(original);
  const event = (value, type) => value.events.find(row => row.event_type === type);
  if (original.mode === 'embedded') {
    const mutations = {
      'changed marker bound': value => { delete event(value, 'VersionMarkerRecorded').payload.min_supported; },
      'changed marker microclock': value => { event(value, 'VersionMarkerRecorded').timestamp = '2000-01-01T00:00:00.000000Z'; },
      'changed physical sequence': value => { event(value, 'ActivityScheduled').sequence += 1; },
      'original prefix changed': value => { value.range_repair.original.events[2].payload.change_id = 'other'; },
      'original deadline rebased': value => { value.range_repair.corrected.execution.run_deadline_at = '2000-01-01T00:00:00Z'; },
      'cold process reused': value => { value.range_repair.corrected.pid = value.range_repair.original.pid; },
      'compatible definition not restored': value => { value.range_repair.corrected.definition_sha256 = '0'.repeat(64); },
      'wrong repaired original run': value => { value.range_repair.corrected.run_id = 'other'; },
      'replay block removed': value => { value.range_repair.incompatible.tasks.find(row => row.payload?.replay_blocked).payload.replay_blocked = false; },
      'repair changes original task': value => { event(value, 'RepairRequested').payload.task_id = 'other'; },
      'wrong terminal attempt': value => { event(value, 'WorkflowCompleted').payload.task.attempt_count += 1; },
      'wrong terminal repair count': value => { event(value, 'WorkflowCompleted').payload.task.repair_count += 1; },
      'neighbor repaired': value => { value.corrected_range.neighbors.left.after.tasks[0].repair_count += 1; },
      'neighbor history lost': value => { value.corrected_range.neighbors.right.after.events.pop(); },
      'typed bigint rounded': value => { value.typed_output.value.count.value = '9007199254740992'; },
      'typed null lost': value => { value.typed_output.value.optional = {type: 'string', value: ''}; },
      'false completion': value => { value.status = 'waiting'; },
      'loaded original package changed': value => { value.range_repair.original.workflow_package = '0.0.0'; },
      'loaded source changed': value => { value.range_repair.corrected.workflow_loaded_sources['src/V2/Support/WorkflowExecutor.php'] = '0'.repeat(64); },
      'dependency lock changed': value => { value.range_repair.incompatible.embedded_composer_lock_sha256 = '0'.repeat(64); },
    };
    if (fixture.corrected_range.pending_activity) Object.assign(mutations, {
      'retained timer identity changes': value => { event(value, 'TimerFired').payload.timer_id = 'other'; },
      'retained timer deadline changes': value => { event(value, 'TimerFired').payload.fire_at = '2000-01-01T00:00:00.000000Z'; },
      'selected operation changes': value => { event(value, 'SelectionResolved').payload.operation_identity = 'other'; },
      'pending original activity replaced': value => {
        value.range_repair.corrected.checkpoints.after_waiting.tasks.find(row => row.task_type === 'activity').payload.activity_execution_id = 'other';
      },
    });
    for (const [name, mutate] of Object.entries(mutations)) {
      const corrupted = structuredClone(original);
      mutate(corrupted);
      assert.throws(() => read(corrupted), undefined, name);
    }
    results.push({fixture_id: fixture.id, actual_record: true, mutation_controls: Object.keys(mutations)});
    continue;
  }
  const mutations = {
    'changed original marker bound': value => { delete event(value, 'VersionMarkerRecorded').payload.min_supported; },
    'changed original marker clock': value => { event(value, 'VersionMarkerRecorded').timestamp = '2000-01-01T00:00:00.000000Z'; },
    'changed physical event sequence': value => { event(value, 'ActivityScheduled').sequence += 1; },
    'changed original prefix': value => { value.corrected_range.phases.original.events[2].payload.change_id = 'other'; },
    'invented legacy retry policy': value => { event(value, 'ActivityScheduled').payload.activity.retry_policy = {max_attempts: 1, backoff_seconds: [1]}; },
    'changed original execution deadline': value => { value.corrected_range.phases.original.execution.run_deadline_at = '2000-01-01T00:00:00Z'; },
    'cold process reused': value => { value.corrected_range.phases.final.actor.pid = value.corrected_range.phases.original.actor.pid; },
    'wrong original successor run': value => { value.corrected_range.phases.final.actor.run_id = 'other'; },
    'false empty-poll completion': value => { value.corrected_range.phases.final.actor.outcome = {Ok: 0}; },
    'missing explicit refusal': value => { value.corrected_range.phases.incompatible.actor.decisions = []; },
    'wrong refusal reason': value => { value.corrected_range.phases.incompatible.debug.latest_workflow_task_failure.message = 'other'; },
    'automatic replay readmission': value => { value.corrected_range.phases.incompatible.debug.pending_workflow_tasks.push({task_id: 'other'}); },
    'repair changes logical task': value => { event(value, 'RepairRequested').payload.task_id = 'other'; },
    'completion has wrong repair count': value => { event(value, 'WorkflowCompleted').payload.task.repair_count += 1; },
    'neighbor original task repaired': value => { value.corrected_range.neighbors.left.after.debug.pending_workflow_tasks[0].repair_count += 1; },
    'neighbor history changes': value => { value.corrected_range.neighbors.right.after.events.pop(); },
    'neighbor original run replaced': value => { value.corrected_range.neighbors.left.after.run_id = 'other'; },
    'typed bigint rounded': value => { value.typed_output.value.count.value = '9007199254740992'; },
    'typed null lost': value => { value.typed_output.value.optional = {type: 'string', value: ''}; },
    'terminal status missing': value => { value.status = 'waiting'; },
    'whole response bytes lost': value => { value.corrected_range.requests.find(row => row.status === 200).response_bytes_base64 = ''; },
    'canonical cold delivery missing': value => { value.corrected_range.requests = value.corrected_range.requests.filter(row => !row.path.endsWith('/workflow-tasks/poll')); },
    'committed failure cancelled': value => { value.corrected_range.requests.find(row => row.path.endsWith('/fail')).client_cancelled = true; },
    'loaded producer source changed': value => { value.corrected_range.phases.original.actor.sdk_php_source = '0'.repeat(40); },
    'loaded successor version changed': value => { value.corrected_range.phases.final.actor.sdk_version = 'durable-workflow-rust/0.0.0'; },
  };
  if (fixture.corrected_range.pending_activity) Object.assign(mutations, {
    'pending activity lost before execution': value => { value.corrected_range.phases['repaired-wait'].debug.pending_activities = []; },
    'pending activity replaced': value => { value.corrected_range.phases.incompatible.debug.pending_activities[0].activity_execution_id = 'other'; },
    'retained timer winner changed': value => { value.corrected_range.phases['repaired-wait'].actor.decisions[1].winner = 'activity'; },
    'timer rebased': value => { event(value, 'TimerFired').payload.fire_at = '2000-01-01T00:00:00.000000Z'; },
    'selection references another operation': value => { event(value, 'SelectionResolved').payload.operation_identity = 'other'; },
    'false original-task completion after activity': value => { event(value, 'WorkflowCompleted').payload.task.id = event(value, 'RepairRequested').payload.task_id; },
  });
  for (const [name, mutate] of Object.entries(mutations)) {
    const corrupted = structuredClone(original);
    mutate(corrupted);
    assert.throws(() => read(corrupted), undefined, name);
  }
  results.push({fixture_id: fixture.id, actual_record: true, mutation_controls: Object.keys(mutations)});
}
assert(results.length > 0, 'At least one actual range observation');
console.log(JSON.stringify({outcome: 'pass', results}));
