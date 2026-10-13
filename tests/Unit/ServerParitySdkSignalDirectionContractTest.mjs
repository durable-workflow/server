import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {observed} from '../Fixtures/ServerParityReaders/sdk-signals.mjs';
import {checkObservation} from '../../scripts/conformance/server-parity/contract.mjs';
import {checkSdkSignalDirectionArtifacts, checkSdkSignalDirectionPackages} from '../../scripts/conformance/server-parity/sdk-signal-direction-contract.mjs';

const profile = new URL('../Fixtures/ServerParityProfiles/published-sdk-signals/', import.meta.url);
const artifacts = JSON.parse(readFileSync(new URL('artifacts.json', profile), 'utf8'));
const fixtureFor = (author, client) => JSON.parse(readFileSync(new URL('signal-sdk-' + author + '-' + client + '.json', profile), 'utf8'));
const copy = value => structuredClone(value);
const workflowId = 'signal-original-workflow';


for (const author of ['php', 'python', 'rust']) for (const client of ['php', 'python', 'rust']) for (const mode of ['http', 'embedded']) {
  test('check signal direction guard model ' + author + '/' + client + ' ' + mode, () => {
    const fixture = fixtureFor(author, client), value = observed(fixture, mode);
    checkSdkSignalDirectionPackages(fixture, value, artifacts);
    assert.deepEqual(checkObservation(fixture, value, workflowId).sdk_signal_direction,
      {two_distinct_identical_signals: true, exact_typed_completion: true});
  });
}
const fixture = fixtureFor('python', 'python');
const replacement = value => value.sdk_signal_direction.phases[4];
const firstSender = value => value.sdk_signal_direction.phases[1];
const completion = value => replacement(value).requests.at(-1);
const mutations = {
  'same process replay': value => {replacement(value).pid = value.sdk_signal_direction.phases[0].pid;},
  'missing cold process': value => {value.sdk_signal_direction.phases.pop();},
  'observer is SDK worker': value => {value.sdk_signal_direction.observer_start.pid = replacement(value).pid;},
  'wrong sender language': value => {firstSender(value).language = 'rust';},
  'wrong installed version': value => {replacement(value).sdk_version = '0.0.0';},
  'unfinished sender': value => {firstSender(value).sender_finished = false;},
  'invented Python receipt': value => {firstSender(value).client_result = copy(firstSender(value).requests[0].response);},
  'second run': value => {replacement(value).run_id = 'other-run';},
  'second workflow callback': value => {replacement(value).workflow_invocations = 2;},
  'activity callback': value => {replacement(value).activity_invocations = 1;},
  'wrong checkpoint queue': value => {replacement(value).snapshot.task_queue = 'other';},
  'wrong checkpoint namespace': value => {replacement(value).snapshot.namespace = 'other';},
  'original not waiting': value => {value.sdk_signal_direction.phases[0].snapshot.status = 'completed';},
  'first signal missing': value => {value.sdk_signal_direction.phases[2].snapshot.events.pop();},
  'cold history truncated': value => {replacement(value).requests[1].response.task.history_events.pop();},
  'wrong claimed run': value => {replacement(value).requests[1].response.task.run_id = 'other';},
  'wrong claimed queue': value => {replacement(value).requests[1].response.task.task_queue = 'other';},
  'registration refused': value => {replacement(value).requests[0].response.registered = false;},
  'extra activity type': value => {replacement(value).requests[0].request.supported_activity_types = ['activity'];},
  'completion refused': value => {completion(value).response.recorded = false;},
  'stale lease': value => {completion(value).request.lease_owner = 'stale';},
  'stale attempt': value => {completion(value).request.workflow_task_attempt = 2;},
  'wrong task receipt': value => {completion(value).response.task_id = 'other';},
  'cold replay reopens wait': value => {completion(value).request.commands[0].type = 'open_condition_wait';},
  'unbound completion copy': value => {value.workflow_completions[2].response.recorded = false;},
  'result frame replaced': value => {value.execution.output_envelope = {codec: 'avro', blob: 'other'};},
  'int64 sender rounded': value => {firstSender(value).requests[0].typed_decoded_input.value[0].value.count.value = '9007199254740992';},
  'int64 result rounded': value => {value.workflow_completions[2].decoded_commands[0].typed_decoded.result.value.count.value = '9007199254740992';},
  'new start before cold replay': value => {replacement(value).requests.push(copy(value.sdk_signal_direction.phases[0].requests[1]));},
  'start before registration': value => {value.sdk_signal_direction.phases[0].requests.reverse();},
  'claim before accepted start': value => {
    const requests = value.sdk_signal_direction.phases[0].requests;
    [requests[1], requests[2]] = [requests[2], requests[1]];
  },
  'signal refused': value => {firstSender(value).requests[0].status = 409;},
  'created signal status': value => {firstSender(value).requests[0].status = 201;},
  'wrong accepted run': value => {firstSender(value).requests[0].response.run_id = 'other';},
  'run selector added': value => {firstSender(value).requests[0].request.run_id = value.run_id;},
  'unbound delivery receipt': value => {value.signal_deliveries[0].response.command_id = 'other';},
  'coalesced signal command': value => {value.sdk_signal_direction.phases[3].requests[0].response.command_id = firstSender(value).requests[0].response.command_id;},
  'wrong next wait key': value => {value.sdk_signal_direction.phases[2].requests.at(-1).request.commands[0].condition_key = 'payload:0';},
  'changed original wait fingerprint': value => {replacement(value).requests[1].response.task.history_events[2].payload.condition_definition_fingerprint = 'sha256:' + 'b'.repeat(64);},
};
for (const [name, mutate] of Object.entries(mutations)) test('refuse signal direction ' + name, () => {
  const value = observed(fixture); mutate(value);
  assert.throws(() => checkObservation(fixture, value, workflowId));
});
test('signal package guard refuses source and tuple substitutions', () => {
  const value = observed(fixture); value.sdk_signal_direction.packages.python.archive_sha256 = 'a'.repeat(64);
  assert.throws(() => checkSdkSignalDirectionPackages(fixture, value, artifacts));
  const altered = copy(artifacts); altered.server.source_commit = 'b'.repeat(40);
  assert.throws(() => checkSdkSignalDirectionArtifacts(fixture, altered));
});

for (const [name, mutate] of Object.entries({
  'missing authored occurrence': value => {delete value.sdk_signal_direction.phases[0].requests.at(-1).request.commands[0].condition_wait_occurrence_id;},
  'reused authored occurrence': value => {value.sdk_signal_direction.phases[2].requests.at(-1).request.commands[0].condition_wait_occurrence_id = 'rust:condition-wait:0';},
  'changed satisfied occurrence': value => {value.events[11].payload.condition_wait_occurrence_id = 'rust:condition-wait:0';},
})) test('refuse Rust signal ' + name, () => {
  const rustFixture = fixtureFor('rust', 'php'), value = observed(rustFixture);
  mutate(value);
  assert.throws(() => checkObservation(rustFixture, value, workflowId));
});
