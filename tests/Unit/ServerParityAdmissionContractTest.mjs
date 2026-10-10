import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkObservation} from '../../scripts/conformance/server-parity/contract.mjs';
import {admissionObservation} from '../Support/ServerParityAdmissionObservation.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParityPending/admission-auth-namespace.json', import.meta.url)));
const examples = ['http', 'embedded'].map(mode => admissionObservation(fixture, mode));
const check = raw => checkObservation(fixture, raw, 'admission-reference-auth-namespace');
test('modeled admission adapters project equally', () => assert.deepStrictEqual(check(examples[0]), check(examples[1])));
for (const example of examples) {
  const mutations = {
    'missing admission observation': raw => { delete raw.admission; },
    'replacement peer': raw => { raw.admission.peer_workflow_id = 'replacement'; },
    'reused root run': raw => { raw.admission.peer_run_id = raw.run_id; },
    'wrong registered peer type': raw => { raw.admission.peer_before.workflow_type = 'replacement'; },
    'wrong peer namespace': raw => { raw.admission.peer_before.namespace = 'other'; },
    'refused mutation closes peer': raw => { raw.admission.peer_after_refusals.status = 'cancelled'; },
    'refused mutation invents closure': raw => { raw.admission.peer_after_refusals.closed_at = '2026-01-01T00:00:02Z'; },
    'refused mutation changes description': raw => { raw.admission.peer_after_refusals.started_at = '2026-01-01T00:00:01Z'; },
    'refused mutation appends history': raw => { raw.admission.history_after_refusals.push(structuredClone(raw.admission.history_after_cleanup[2])); },
    'refused mutation edits history': raw => { raw.admission.history_after_refusals[0].payload.outcome = 'changed'; },
    'unclaimed peer executes activity': raw => { raw.admission.history_before.push({event_type: 'ActivityStarted'}); },
    'cleanup replaces run': raw => { raw.admission.peer_after_cleanup[raw.mode === 'embedded' ? 'id' : 'run_id'] = 'replacement'; },
    'cleanup loses terminal event': raw => { raw.admission.history_after_cleanup.pop(); },
    'cleanup overwrites start event': raw => { raw.admission.history_after_cleanup[0].payload.workflow_command_id = 'changed'; },
    'cleanup records refused reason': raw => { raw.admission.history_after_cleanup[3].payload.reason = fixture.admission.refused_reason; },
    'cleanup invents command identity': raw => { raw.admission.history_after_cleanup[3].payload.workflow_command_id = 'changed'; },
  };
  if (example.mode === 'embedded') Object.assign(mutations, {
    'invented embedded HTTP receipts': raw => { raw.admission.requests.push({id: 'invented'}); },
    'engine refuses cleanup': raw => { raw.admission.cleanup.accepted = false; },
    'engine changes cleanup sequence': raw => { raw.admission.cleanup.command_sequence = 3; },
  });
  else {
    Object.assign(mutations, {
      'missing HTTP request': raw => { raw.admission.requests.pop(); },
      'duplicate HTTP request': raw => { raw.admission.requests.push(structuredClone(raw.admission.requests[0])); },
      'changed original request target': raw => { raw.admission.requests[0].path = '/api/workflows/replacement/cancel'; },
      'changed original credential mode': raw => { raw.admission.requests[0].auth = 'valid'; },
      'retained credential': raw => { raw.admission.requests[0].authorization = 'Bearer forbidden'; },
      'authentication leaks namespace': raw => { raw.admission.requests[0].response.namespace = fixture.admission.unknown_namespace; },
      'authentication leaks requested version': raw => { raw.admission.requests[0].response.requested_version = '999'; },
      'wrong worker capability envelope': raw => { raw.admission.requests[2].response.server_capabilities.workflow_task_poll_request_idempotency = false; },
      'missing control response contract': raw => { delete raw.admission.requests[0].response.control_plane; },
      'metadata replaces original peer': raw => { raw.admission.requests[0].response.control_plane.run_id = 'replacement'; },
      'control body replaces original peer': raw => { raw.admission.requests[0].response.run_id = 'replacement'; },
      'control metadata changes diagnostic': raw => { raw.admission.requests[0].response.control_plane.reason = 'different'; },
      'control response weakens legacy policy': raw => { raw.admission.requests[0].response.control_plane.contract.legacy_field_policy = 'ignore'; },
      'control response drops field contract': raw => { delete raw.admission.requests[0].response.control_plane.contract.rejection_fields; },
      'control response changes allowed reasons': raw => { raw.admission.requests[0].response.control_plane.contract.rejection_reasons = ['different']; },
      'unknown query selects default': raw => { const r = raw.admission.requests.find(r => r.id === 'control_unknown_query'); r.response.reason = null; r.status = 200; },
      'positive read selects other namespace': raw => { raw.admission.requests.at(-1).response.namespace = 'other'; },
      'positive read hides mutation': raw => { raw.admission.requests.at(-1).response.status = 'cancelled'; },
    });
    for (const expected of fixture.admission.requests) {
      mutations[`${expected.id} wrong status`] = raw => { raw.admission.requests.find(r => r.id === expected.id).status = 500; };
      mutations[`${expected.id} wrong plane`] = raw => { raw.admission.requests.find(r => r.id === expected.id).headers = {control: '', worker: ''}; };
      mutations[`${expected.id} wrong reason`] = raw => { raw.admission.requests.find(r => r.id === expected.id).response.reason = 'different'; };
      if (expected.status === 400) {
        mutations[`${expected.id} loses diagnostic`] = raw => { const r = raw.admission.requests.find(r => r.id === expected.id); delete r.response[expected.plane === 'worker' ? 'error' : 'message']; };
        mutations[`${expected.id} loses remediation`] = raw => { delete raw.admission.requests.find(r => r.id === expected.id).response.remediation; };
      }
      if (expected.status === 404) mutations[`${expected.id} loses remediation`] = raw => { delete raw.admission.requests.find(r => r.id === expected.id).response.remediation; };
    }
  }
  for (const [name, mutate] of Object.entries(mutations)) test(`${example.mode}: refuses ${name}`, () => {
    const raw = structuredClone(example); mutate(raw); assert.throws(() => check(raw));
  });
}
