import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync, mkdtempSync, writeFileSync, rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {resolve} from 'node:path';
import {createHash} from 'node:crypto';
import {spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import {checkObservation} from '../../scripts/conformance/server-parity/contract.mjs';
import {scheduleObservation} from '../Support/ServerParityScheduleObservation.mjs';

const audit = (raw, type, mutate) => {
  for (const key of ['created_history', 'paused_history', 'history_before_worker', 'history_after_workflow', 'fresh_history'])
    for (const event of raw.schedule[key] ?? []) if (event.event_type === type) mutate(event);
};
for (const phase of ['manual-lifecycle', 'fixed-rate-one-occurrence']) {
  const fixture = JSON.parse(readFileSync(new URL(`../Fixtures/ServerParityPending/schedule-${phase}.json`, import.meta.url)));
  const examples = ['http', 'embedded'].map(mode => scheduleObservation(fixture, mode));
  const check = raw => checkObservation(fixture, raw, `schedule-reference-${phase === 'manual-lifecycle' ? 'manual' : 'rate'}`);
  test(`${phase}: modeled schedule adapters project equally`, () => assert.deepStrictEqual(check(examples[0]), check(examples[1])));
  for (const example of examples) {
    const mutations = {
      'replacement schedule': raw => { raw.schedule.schedule_id = 'replacement'; },
      'unrelated generated workflow': raw => { raw.workflow_id = 'replacement'; },
      'replacement triggered run': raw => { raw.schedule.trigger.run_id = 'replacement'; },
      'unrelated trigger receipt': raw => { raw.schedule.trigger.schedule_id = 'replacement'; },
      'replacement durable schedule': raw => { raw.events[2].payload.schedule_ulid = 'replacement'; },
      'replacement durable relationship': raw => audit(raw, 'ScheduleTriggered', event => { event.payload.workflow_run_id = 'replacement'; }),
      'second trigger number': raw => audit(raw, 'ScheduleTriggered', event => { event.payload.trigger_number = 2; }),
      'lost original overlap policy': raw => audit(raw, 'ScheduleTriggered', event => { event.payload.effective_overlap_policy = 'allow_all'; }),
      'wrong stored fire outcome': raw => audit(raw, 'ScheduleTriggered', event => { event.payload.outcome = 'failed'; }),
      'wrong deletion reason': raw => audit(raw, 'ScheduleDeleted', event => { event.payload.reason = 'replacement'; }),
      'concealed extra fire': raw => audit(raw, 'ScheduleDeleted', event => { event.payload.schedule.fires_count = 2; }),
      'wrong namespace': raw => audit(raw, 'ScheduleCreated', event => { event.payload.schedule.namespace = 'other'; }),
      'wrong original spec': raw => audit(raw, 'ScheduleCreated', event => { event.payload.spec.timezone = 'America/New_York'; }),
      'wrong action type': raw => audit(raw, 'ScheduleCreated', event => { event.payload.action.workflow_type = 'replacement'; }),
      'changed original execution budget': raw => audit(raw, 'ScheduleCreated', event => { event.payload.action.execution_timeout_seconds = 1; }),
      'rounded action int64': raw => { raw.schedule.typed_action_input.value[0].value.count.value = '9007199254740992'; },
      'lost fresh original run': raw => { raw.schedule.fresh_workflow.run_id = 'replacement'; },
      'fresh workflow incomplete': raw => { raw.schedule.fresh_workflow.status = 'waiting'; },
      'missing audit': raw => { raw.schedule.fresh_history.pop(); },
      'duplicate audit identity': raw => audit(raw, 'ScheduleDeleted', event => { event.id = raw.schedule.fresh_history[0].id; }),
      'corrupted audit sequence': raw => audit(raw, 'ScheduleTriggered', event => { event.sequence = 99; }),
      'changed fresh audit': raw => { raw.schedule.fresh_history[0].payload.next_fire_at = '2027-01-01T00:00:00Z'; },
    };
    if (phase === 'manual-lifecycle') Object.assign(mutations, {
      'paused trigger starts workflow': raw => { raw.schedule.paused_trigger.workflow_id = 'unexpected'; },
      'paused trigger starts run': raw => { raw.schedule.paused_trigger.run_id = 'unexpected'; },
      'paused trigger accepted': raw => { raw.schedule.paused_trigger.outcome = 'triggered'; },
      'wrong skip reason': raw => { raw.schedule.paused_trigger.reason = 'replacement'; },
      'paused state active': raw => { raw.schedule.paused.status = 'active'; },
      'resumed skip lost': raw => { if (raw.mode === 'embedded') raw.schedule.resumed.skipped_trigger_count = 0; else raw.schedule.resumed.info.skipped_trigger_count = 0; },
      'concealed failed fire': raw => { raw.schedule.after_workflow.failures_count = 1; },
      'recent action replacement run': raw => { (raw.mode === 'embedded' ? raw.schedule.after_workflow.recent_actions : raw.schedule.after_workflow.info.recent_actions)[0].run_id = 'replacement'; },
    });
    else Object.assign(mutations, {
      'replacement original occurrence': raw => audit(raw, 'ScheduleTriggered', event => { event.payload.occurrence_time = '2027-01-01T00:00:00Z'; }),
      'submillisecond early admission': raw => {
        const due = '2026-01-01T00:00:02.020001Z';
        audit(raw, 'ScheduleCreated', event => { event.payload.next_fire_at = due; event.payload.schedule.next_fire_at = due; });
        audit(raw, 'ScheduleTriggered', event => { event.payload.occurrence_time = due; event.payload.schedule.next_fire_at = due; });
        raw.events[2].payload.occurrence_time = due;
      },
      'later interval window too short': raw => { raw.schedule.later_finished_at = raw.schedule.later_started_at; },
      'later window precedes completion': raw => { raw.schedule.later_started_at = '2026-01-01T00:00:00Z'; },
    });
    if (example.mode === 'embedded') Object.assign(mutations, {
      'extra physical schedule': raw => { raw.schedule.physical_schedule_rows = 2; },
      'extra physical workflow': raw => { raw.schedule.related_run_ids.push('unexpected'); },
      'missing tombstone': raw => { raw.schedule.fresh_schedule.status = 'active'; },
      'deleted schedule retains occurrence': raw => { raw.schedule.fresh_schedule.next_fire_at = '2027-01-01T00:00:00Z'; },
      'fresh result rounded': raw => { raw.schedule.fresh_workflow.typed_output.value.count.value = '9007199254740992'; },
      ...(phase === 'manual-lifecycle' ? {'changed frozen resume audit': raw => audit(raw, 'ScheduleResumed', event => { event.payload.schedule.skipped_trigger_count = 1; })}
        : {'quota replenished': raw => { raw.schedule.fresh_schedule.remaining_actions = 1; },
          'normal tick different run': raw => { raw.schedule.tick_results[1][0].run_id = 'replacement'; },
          'later tick starts extra workflow': raw => { raw.schedule.later_tick_results[0].push({schedule_id: raw.schedule.schedule_id}); }}),
    });
    else Object.assign(mutations, {
      'deleted schedule still visible': raw => { raw.schedule.after_delete_describe.status = 200; },
      'deleted trigger wrong reason': raw => { raw.schedule.after_delete_trigger.reason = 'replacement'; },
      'extra post-close task': raw => { raw.schedule.post_close_poll = {run_id: 'unexpected'}; },
      'missing actual HTTP controls': raw => { raw.schedule.control_receipts.pop(); },
      'wrong actual HTTP create response': raw => { raw.schedule.control_receipts[0].status = 200; },
      'wrong actual task': raw => { raw.workflow_polls[0].run_id = 'replacement'; },
      'extra actual task': raw => { raw.workflow_polls.push(structuredClone(raw.workflow_polls[0])); },
      'lost authored completion': raw => { raw.workflow_completions = []; },
      'wrong completion lease': raw => { raw.workflow_completions[0].request.lease_owner = 'replacement'; },
      'rounded authored result': raw => { raw.workflow_completions[0].decoded_commands[0].typed_decoded.result.value.count.value = '9007199254740992'; },
      'unauthorized schedule audit': raw => audit(raw, 'ScheduleCreated', event => { event.payload.command_context.context.auth.status = 'denied'; }),
      'wrong audit principal': raw => audit(raw, 'ScheduleCreated', event => { event.payload.command_context.context.principal.id = 'replacement'; }),
      'wrong audit namespace': raw => audit(raw, 'ScheduleCreated', event => { event.payload.command_context.context.server.namespace = 'replacement'; }),
      'wrong audit request': raw => audit(raw, 'ScheduleCreated', event => { event.payload.command_context.context.request.path = '/wrong'; }),
      'plausible but unrelated fingerprint': raw => audit(raw, 'ScheduleCreated', event => { event.payload.command_context.context.request.fingerprint = `sha256:${'f'.repeat(64)}`; }),
      ...(phase === 'manual-lifecycle' ? {'unbound control request payload': raw => { raw.schedule.control_receipts[1].request = {forged: 'payload'}; }} : {}),
    });
    for (const [name, mutate] of Object.entries(mutations)) test(`${example.mode} ${phase}: rejects ${name}`, () => {
      const damaged = structuredClone(example);
      mutate(damaged);
      assert.throws(() => check(damaged));
    });
  }
}

test('candidate CLI compares selected current fixture bytes without enlarging the default corpus', () => {
  const root = fileURLToPath(new URL('../..', import.meta.url));
  const directory = mkdtempSync(resolve(tmpdir(), 'server-parity-schedule-'));
  const names = ['schedule-fixed-rate-one-occurrence', 'schedule-manual-lifecycle'];
  const files = names.map(name => `tests/Fixtures/ServerParityPending/${name}.json`);
  const fixtures = files.map(file => JSON.parse(readFileSync(resolve(root, file))));
  const hashes = Object.fromEntries(files.map((file, index) => [`${names[index]}.json`, createHash('sha256').update(readFileSync(resolve(root, file))).digest('hex')]));
  try {
    const records = ['http', 'embedded'].map(mode => {
      const record = {schema: 'durable-workflow.server-parity-record/v1', target: mode, mode, outcome: 'pass',
        artifacts: {sdk_php: '2.2.6', workflow: '2.5.5'}, runner_revision: 'a'.repeat(40), fixture_hashes: hashes,
        cases: fixtures.map(fixture => {
          const observation = scheduleObservation(fixture, mode);
          return {fixture_id: fixture.id, fixture, observation, projection: checkObservation(fixture, observation, observation.schedule.schedule_id)};
        })};
      const path = resolve(directory, `${mode}.json`);
      writeFileSync(path, JSON.stringify(record));
      return path;
    });
    const selected = files.flatMap(file => ['--fixture', file]);
    const result = spawnSync(process.execPath, ['scripts/conformance/server-parity.mjs', 'compare', ...selected, ...records], {cwd: root, encoding: 'utf8'});
    assert.equal(result.status, 0, result.stderr);
    assert.deepStrictEqual(JSON.parse(result.stdout), {outcome: 'pass', recordings: 2});
    const substituted = JSON.parse(readFileSync(records[1]));
    substituted.mode = 'http';
    writeFileSync(records[1], JSON.stringify(substituted));
    const wrongAdapter = spawnSync(process.execPath, ['scripts/conformance/server-parity.mjs', 'compare', ...selected, ...records], {cwd: root, encoding: 'utf8'});
    assert.notEqual(wrongAdapter.status, 0, 'embedded raw observations cannot impersonate HTTP qualification');
    const mismatch = spawnSync(process.execPath, ['scripts/conformance/server-parity.mjs', 'compare', ...records], {cwd: root, encoding: 'utf8'});
    assert.notEqual(mismatch.status, 0, 'selected candidates cannot impersonate the default corpus');
    const duplicate = spawnSync(process.execPath, ['scripts/conformance/server-parity.mjs', 'compare', ...selected, '--fixture', files[0], ...records], {cwd: root, encoding: 'utf8'});
    assert.notEqual(duplicate.status, 0, 'duplicate selected expectations cannot overwrite hashes');
  } finally {
    rmSync(directory, {recursive: true, force: true});
  }
});
