import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import {checkObservation} from '../../scripts/conformance/server-parity/contract.mjs';
import {visibilityObservation} from '../Support/ServerParityVisibilityObservation.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/visibility-current-runs.json', import.meta.url)));
const examples = ['http', 'embedded'].map(mode => visibilityObservation(fixture, mode));
const check = raw => checkObservation(fixture, raw, 'visibility-reference-current-runs');
const firstRow = raw => raw.mode === 'embedded' ? raw.visibility.summaries[0] : raw.visibility.pages[0].response.workflows[0];
const rows = (raw, value) => raw.mode === 'embedded' ? value : value.workflows;
const changeCli = (raw, name, mutate) => {
  const receipt = raw.visibility.cli.commands[name];
  mutate(receipt.document);
  receipt.stdout = JSON.stringify(receipt.document);
};
test('modeled visibility adapters project equally', () => assert.deepStrictEqual(check(examples[0]), check(examples[1])));
const runner = fileURLToPath(new URL('../../scripts/conformance/server-parity.mjs', import.meta.url));
test('combined candidate selection refuses duplicate reviewed fixture identities', () => {
  const outcome = spawnSync(process.execPath, [runner, 'compare', '--include-fixture', 'tests/Fixtures/ServerParity/one-activity.json'], {encoding: 'utf8'});
  assert.equal(outcome.status, 1);
  assert.match(outcome.stderr, /distinct JSON filenames/);
});
test('candidate selection refuses mixing replacement and additive inventories', () => {
  const path = 'tests/Fixtures/ServerParity/visibility-current-runs.json';
  const outcome = spawnSync(process.execPath, [runner, 'compare', '--fixture', path, '--include-fixture', path], {encoding: 'utf8'});
  assert.equal(outcome.status, 1);
  assert.match(outcome.stderr, /Choose either --fixture or --include-fixture/);
});
for (const example of examples) {
  const mutations = {
    'missing actual visibility': raw => { delete raw.visibility; },
    'replacement peer': raw => { raw.visibility.peer_workflow_id = 'replacement'; },
    'reused root run': raw => { raw.visibility.peer_run_id = raw.run_id; },
    'foreign workflow in listing': raw => { firstRow(raw)[raw.mode === 'embedded' ? 'workflow_instance_id' : 'workflow_id'] = 'foreign'; },
    'replacement listed run': raw => { firstRow(raw)[raw.mode === 'embedded' ? 'id' : 'run_id'] = 'replacement'; },
    'incorrect registered type': raw => { firstRow(raw).workflow_type = fixture.workflow_type; },
    'incorrect pending bucket': raw => { firstRow(raw).status_bucket = 'open'; },
    'pending declared terminal': raw => { firstRow(raw).is_terminal = true; },
    'invented pending closure': raw => { firstRow(raw).closed_at = raw.execution.closed_at; },
    'replacement start timestamp': raw => { firstRow(raw).started_at = '2027-01-01T00:00:00Z'; },
    'wrong original queue': raw => { firstRow(raw)[raw.mode === 'embedded' ? 'queue' : 'task_queue'] = 'other'; },
    'concealed prior failed workflow': raw => { rows(raw, raw.visibility.filters.failed).push(structuredClone(firstRow(raw))); },
    'completed included as running': raw => { rows(raw, raw.visibility.filters.running)[0].status = 'completed'; },
    'type filter returns wrong original': raw => { rows(raw, raw.visibility.type_filters.pending)[0].workflow_type = fixture.workflow_type; },
    'cleanup replacement run': raw => { raw.visibility.peer_after[raw.mode === 'embedded' ? 'id' : 'run_id'] = 'replacement'; },
    'cleanup not durable': raw => { raw.visibility.peer_after.status = 'pending'; },
    'missing cancelled failed-bucket run': raw => { rows(raw, raw.visibility.after_cleanup).pop(); },
    'cancelled classified completed': raw => { rows(raw, raw.visibility.after_cleanup)[0].status_bucket = 'completed'; },
    'peer executed before cleanup': raw => { raw.visibility.peer_before.status = 'running'; },
    'unexpected peer activity': raw => { raw.visibility.peer_history.splice(2, 0, {sequence: 3, event_type: 'ActivityStarted', payload: {}}); },
    'lost cancellation event': raw => { raw.visibility.peer_history.pop(); },
    'peer history replacement run': raw => { raw.visibility.peer_history[2].payload.workflow_run_id = 'replacement'; },
    'changed original cleanup reason': raw => { raw.visibility.peer_history[3].payload.reason = 'changed'; },
    'reused start and cleanup command': raw => { for (const event of raw.visibility.peer_history.slice(2)) event.payload.workflow_command_id = raw.visibility.peer_history[0].payload.workflow_command_id; },
  };
  if (example.mode === 'embedded') Object.assign(mutations, {
    'wrong embedded namespace': raw => { firstRow(raw).namespace = 'other'; },
    'historical run replaces current': raw => { firstRow(raw).is_current_run = false; },
    'wrong sort timestamp': raw => { firstRow(raw).sort_timestamp = raw.execution.closed_at; },
    'extra scoped summary': raw => { raw.visibility.summaries.push(structuredClone(firstRow(raw))); },
    'cleanup not accepted by engine': raw => { raw.visibility.cleanup.accepted = false; },
    'wrong engine cleanup order': raw => { raw.visibility.cleanup.command_sequence = 3; },
  });
  else Object.assign(mutations, {
    'missing original page': raw => { raw.visibility.pages.pop(); },
    'extra page': raw => { raw.visibility.pages.push(structuredClone(raw.visibility.pages[1])); },
    'noncanonical next cursor': raw => { raw.visibility.pages[0].response.next_page_token = 'Mg=='; },
    'wrong request cursor': raw => { raw.visibility.pages[1].request_token = null; },
    'wrong reported page count': raw => { raw.visibility.pages[0].response.workflow_count = 2; },
    'wrong protocol cursor': raw => { raw.visibility.pages[0].response.control_plane.next_page_token = null; },
    'missing list response metadata': raw => { delete raw.visibility.pages[0].response.control_plane; },
    'HTTP accepts status alias': raw => { raw.visibility.alias.status = 200; },
    'missing canonical request manifest': raw => { delete raw.visibility.cluster.control_plane.request_contract; },
    'incorrect advertised alias': raw => { raw.visibility.cluster.control_plane.request_contract.operations.list.fields.status.rejected_aliases.pending = 'completed'; },
    'modified CLI artifact': raw => { raw.visibility.cli.sha256 = 'replacement'; },
    'missing CLI history command': raw => { delete raw.visibility.cli.commands.history; },
    'CLI timeout': raw => { raw.visibility.cli.commands.list.timed_out = true; },
    'CLI execution fails': raw => { raw.visibility.cli.commands.describe.exit_code = 1; },
    'CLI missing describe contract': raw => changeCli(raw, 'describe', doc => { delete doc.control_plane; }),
    'CLI wrong response schema': raw => changeCli(raw, 'describe', doc => { doc.control_plane.schema = 'other'; }),
    'CLI wrong response operation': raw => changeCli(raw, 'describe', doc => { doc.control_plane.operation = 'describe'; }),
    'CLI wrong metadata run': raw => changeCli(raw, 'describe', doc => { doc.control_plane.run_id = 'replacement'; }),
    'CLI wrong history contract schema': raw => changeCli(raw, 'history', doc => { doc.control_plane.contract.schema = 'other'; }),
    'CLI wrong history required fields': raw => changeCli(raw, 'history', doc => { doc.control_plane.contract.required_fields.pop(); }),
    'CLI conceals history success cursor contract': raw => changeCli(raw, 'history', doc => { doc.control_plane.contract.success_fields = []; }),
    'CLI unfinished final history cursor': raw => changeCli(raw, 'history', doc => { doc.control_plane.next_page_token = 'Mw=='; }),
    'CLI alias accepted': raw => { raw.visibility.cli.commands.alias.exit_code = 0; },
    'wrong published CLI source': raw => { raw.visibility.cli.commands.version.stdout = 'dw 2.2.0 (commit replacement)'; },
    'CLI lists wrong namespace': raw => changeCli(raw, 'list', doc => { doc.namespace = 'other'; }),
    'CLI describes wrong status bucket': raw => changeCli(raw, 'describe', doc => { doc.status_bucket = 'closed'; }),
    'CLI omits current-run identity': raw => changeCli(raw, 'describe', doc => { delete doc.is_current_run; }),
    'CLI conceals extra run': raw => changeCli(raw, 'describe', doc => { doc.run_count = 2; }),
    'CLI rounds exact int64': raw => { raw.visibility.cli.commands.describe.typed_output_preview.value.count.value = '9007199254740992'; },
    'CLI drops history page': raw => { raw.visibility.cli.commands.history.decoded_events.pop(); },
    'CLI targets replacement history': raw => { raw.visibility.cli.commands.history.arguments[2] = 'replacement'; },
  });
  for (const [name, mutate] of Object.entries(mutations)) test(`${example.mode}: refuses ${name}`, () => {
    const raw = structuredClone(example);
    mutate(raw);
    assert.throws(() => check(raw));
  });
}
