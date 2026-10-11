import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import vm from 'node:vm';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const script = fs.readFileSync(process.env.NEXUS_RUNNER_UNDER_TEST
    || path.join(root, 'scripts/conformance/nexus-published-artifacts.sh'), 'utf8');
const match = script.match(/if node - [^\n]* <<'NODE'\n([\s\S]*?)\nNODE\n  then/);
assert.ok(match, 'Missing built-in Nexus probe');
const source = match[1].split('\nmain().catch(')[0];
const context = vm.createContext({ require: createRequire(import.meta.url),
    Buffer, console, process: { argv: ['node', 'probe', '/unused', '/unused', '',
        path.join(root, 'scripts/conformance/nexus-replay-transport.cjs')], env: {} } });
vm.runInContext(`${source}\nglobalThis.probe = { crossLanguageScenarioResult, callerWorkflowInvocationEvidence };`,
    context, { timeout: 1000 });
const { crossLanguageScenarioResult, callerWorkflowInvocationEvidence } = context.probe;

function fixture(status = 'accepted', processId = 123) {
    const request = { name: 'world', scenario: 'php_caller_python_service' };
    const response = (body) => ({ ok: true, status: 200, body });
    const envelope = { schema: 'durable-workflow.v2.external-task-result',
        outcome: { status: 'succeeded' }, result: { payload: { codec: 'avro', blob: 'probe' } },
        decoded_payload: { request_payload: request } };
    return {
        scenarioId: request.scenario, callerLanguage: 'sdk-php', serviceLanguage: 'sdk-python',
        callerWorkflowInstanceId: 'assigned-caller-id', callerWorkflowRunId: 'assigned-run-id',
        operationName: 'Greeter.greet', requestPayload: request,
        execute: response({ service_call_id: 'original-call', client_process_id: processId,
            caller_sdk_invocation: { executed: true, public_method: 'Client.startServiceOperation' } }),
        describe: response({ service_call_id: 'original-call', status,
            completed_at: status === 'completed' ? '2026-10-11T00:00:00Z' : null }),
        history: response({ nexus_operations: [{ service_call_id: 'original-call' }] }),
        serviceProbe: response({ invocable_result: envelope }),
        typedErrorProbe: response({ invocable_result: {
            schema: envelope.schema, outcome: { status: 'failed', recorded: true },
            failure: { type: 'FixtureError', message: 'separate HTTP handler failure' } } }),
        expectedTypedError: 'FixtureError', artifactTupleEvidence: { server: '2.5.19' },
        workerExecution: { workers: [
            { sdk_language: 'sdk-php', public_service_call_surface: { available: true },
                caller_workflow_invocation: callerWorkflowInvocationEvidence('sdk-php', {
                    public_service_call_surface: { available: true, matched_methods: ['startServiceOperation'] },
                }) },
            { sdk_language: 'sdk-python', service_runtime_surface: { available: true } },
        ], service_health: { 'sdk-python': { health_succeeded: true } } },
        missingSurface: null, missingSurfaceOwner: 'server', versions: { server: '2.5.19' },
    };
}

test('API reflection does not execute a caller workflow', () => {
    const observation = callerWorkflowInvocationEvidence('sdk-python', {
        public_service_call_surface: { available: true, matched_methods: ['execute_nexus_operation'] },
    });
    assert.equal(observation.executed, false);
    assert.equal(observation.public_surface_available, true);
});

test('admission plus independent handler probes cannot qualify durable Nexus completion', () => {
    const result = crossLanguageScenarioResult(fixture());
    assert.equal(result.status, 'not_covered');
    assert.equal(result.observed_outputs.attempted_call_evidence.durable_service_response_observed, false);
});

test('a terminal service snapshot does not prove an unexecuted caller workflow', () => {
    const result = crossLanguageScenarioResult(fixture('completed'));
    assert.equal(result.status, 'not_covered');
    assert.equal(result.observed_outputs.response_or_failure_surface.caller_workflow_execution_verified, false);
});

test('a caller language label without SDK process execution cannot qualify a cell', () => {
    const result = crossLanguageScenarioResult(fixture('completed', 0));
    assert.notEqual(result.status, 'pass');
});
