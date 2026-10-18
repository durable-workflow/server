import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import test from 'node:test';

const name = 'nexus-external:' + 'x'.repeat(4 * 1024 * 1024) + '☃';
const digest = value => crypto.createHash('sha256').update(value).digest('hex');
const events = (history, kind) => history.events.filter(event => event.event_type === kind);
const expected = service => ({language: service, name_bytes: Buffer.byteLength(name), name_sha256: digest(name),
    greeting_bytes: Buffer.byteLength(`hello, ${name}`), greeting_sha256: digest(`hello, ${name}`),
    bigint: '9223372036854775807', nullable: null, unicode: '雪☃'});

function assertExternal(row, service) {
    assert.equal(row.scenario, 'external_payload');
    assert.equal(row.budget_seconds, 30);
    assert.ok(row.elapsed_seconds > 0 && row.elapsed_seconds < row.budget_seconds);
    assert.equal(row.recovery_qualified, true, JSON.stringify(row.failure));
    assert.ok(row.worker_process_id > 0 && row.replacement_worker_process_id > 0);
    assert.notEqual(row.worker_process_id, row.replacement_worker_process_id);
    assert.equal(row.killed_worker.pid, row.worker_process_id);
    assert.equal(row.killed_worker.running, false);
    assert.equal(row.killed_worker.termsig, 9);
    assert.equal(row.replacement_worker_exit_code, 0);
    assert.equal(row.result.status, 'completed');
    assert.equal(row.result.service_call_id, row.original_service_call_id);
    assert.deepEqual(row.result.external_result, expected(service));
    assert.equal(events(row.before_kill_history, 'ServiceCallStarted').length, 1);
    assert.equal(events(row.before_kill_history, 'ServiceCallCompleted').length, 0);
    assert.equal(events(row.before_replacement_history, 'WorkflowCompleted').length, 0);
    const starts = events(row.history, 'ServiceCallStarted');
    const completions = events(row.history, 'ServiceCallCompleted');
    assert.equal(starts.length, 1);
    assert.equal(completions.length, 1);
    for (const event of [starts[0], completions[0]]) {
        assert.equal(event.payload.service_call_id, row.original_service_call_id);
    }
    assert.equal(starts[0].payload.sequence, completions[0].payload.sequence);
    const call = row.durable_service_call.body.service_call || row.durable_service_call.body;
    assert.equal(call.service_call_id || call.id, row.original_service_call_id);
    assert.equal(call.caller_workflow_instance_id, row.workflow_id);
    assert.equal(call.caller_workflow_run_id, row.run_id);
    for (const field of ['accepted_at', 'call_deadline_at', 'linked_workflow_instance_id', 'linked_workflow_run_id']) {
        assert.ok(Object.hasOwn(row.original_service_call, field));
        assert.equal(call[field], row.original_service_call[field]);
    }
    assert.equal(row.raw_caller_history.ok, true);
    assert.equal(row.raw_service_history.ok, true);
    const references = row.retained_references;
    assert.ok(new Set(references.map(item => item.reference.reference_id)).size >= 2,
        'Actual persisted argument and result must use separate runtime references');
    for (const {reference, owner, foreign} of references) {
        assert.equal(reference.schema, 'durable-workflow.v2.runtime-external-payload-reference.v1');
        assert.equal(reference.codec, 'avro');
        assert.ok(reference.size_bytes > 4 * 1024 * 1024);
        assert.match(reference.sha256, /^[a-f0-9]{64}$/);
        assert.equal(owner.status, 200);
        assert.equal(owner.bytes, reference.size_bytes);
        assert.equal(owner.sha256, reference.sha256);
        assert.equal(foreign.status, 404);
    }
}

function fixture() {
    const call = {service_call_id: 'call', caller_workflow_instance_id: 'caller', caller_workflow_run_id: 'run',
        accepted_at: '2026-10-18T00:00:00.123456Z', call_deadline_at: null,
        linked_workflow_instance_id: 'service', linked_workflow_run_id: 'service-run'};
    const start = {event_type: 'ServiceCallStarted', payload: {service_call_id: 'call', sequence: 0}};
    const completion = {event_type: 'ServiceCallCompleted', payload: {service_call_id: 'call', sequence: 0}};
    return {scenario: 'external_payload', budget_seconds: 30, elapsed_seconds: 3, recovery_qualified: true,
        workflow_id: 'caller', run_id: 'run', original_service_call_id: 'call', original_service_call: {...call},
        worker_process_id: 1, replacement_worker_process_id: 2,
        killed_worker: {pid: 1, running: false, termsig: 9}, replacement_worker_exit_code: 0,
        result: {status: 'completed', service_call_id: 'call', external_result: expected('php')},
        before_kill_history: {events: [start]}, before_replacement_history: {events: [start, completion]},
        history: {events: [start, completion]}, durable_service_call: {body: call},
        raw_caller_history: {ok: true}, raw_service_history: {ok: true},
        retained_references: ['argument', 'result'].map(reference_id => ({reference: {
            schema: 'durable-workflow.v2.runtime-external-payload-reference.v1', reference_id, codec: 'avro',
            size_bytes: 4194500, sha256: digest(reference_id)},
            owner: {status: 200, bytes: 4194500, sha256: digest(reference_id)}, foreign: {status: 404}}))};
}

test('external delivery reader accepts one cold original and rejects identity, payload, storage and authority corruption', () => {
    assertExternal(fixture(), 'php');
    const mutations = [
        row => { row.budget_seconds = 60; }, row => { row.elapsed_seconds = 31; },
        row => { row.result.external_result.bigint = '9223372036854775808'; },
        row => { row.result.external_result.nullable = false; },
        row => { row.result.external_result.name_sha256 = digest('wrong'); },
        row => { row.result.external_result.unicode = 'wrong'; },
        row => { row.result.service_call_id = 'new-call'; },
        row => { row.history.events.push(row.history.events[0]); },
        row => { row.replacement_worker_process_id = row.worker_process_id; },
        row => { row.replacement_worker_exit_code = 137; },
        row => { row.durable_service_call.body.linked_workflow_run_id = 'new-run'; },
        row => { row.durable_service_call.body.call_deadline_at = '2026-10-19T00:00:00Z'; },
        row => { row.retained_references.pop(); },
        row => { row.retained_references[0].owner.sha256 = digest('wrong'); },
        row => { row.retained_references[0].owner.status = 404; },
        row => { row.retained_references[0].foreign.status = 200; },
        row => { row.retained_references[0].reference.size_bytes = 1000; },
    ];
    for (const mutate of mutations) {
        const row = fixture(); mutate(row); assert.throws(() => assertExternal(row, 'php'));
    }
});

if (process.env.NEXUS_EXTERNAL_SDK_PROBES) {
    test('all four published SDK pairs deliver oversized values to cold callers in both namespaces', () => {
        const report = JSON.parse(fs.readFileSync(process.env.NEXUS_EXTERNAL_SDK_PROBES, 'utf8'));
        assert.deepEqual(report.reports.map(row => [row.caller, row.service]),
            [['php', 'php'], ['php', 'python'], ['python', 'php'], ['python', 'python']]);
        for (const {caller, service, observation} of report.reports) {
            assert.equal(observation.ok, true, JSON.stringify(observation.body));
            assert.deepEqual(observation.body.observations.map(row => row.caller_namespace),
                ['shared', caller === 'php' ? 'tenant-a' : 'tenant-b']);
            for (const row of observation.body.observations) assertExternal(row, service);
        }
    });
}
