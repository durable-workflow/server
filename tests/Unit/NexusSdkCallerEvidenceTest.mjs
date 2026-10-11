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

function assertOriginalServiceDelivery(row, sdkLanguage, status = 'completed') {
    const call = row.durable_service_call?.body?.service_call || row.durable_service_call?.body;
    assert.equal(row.durable_service_call?.ok, true);
    assert.equal(call?.status, status);
    assert.equal(call.service_call_id || call.id, row.result.service_call_id);
    assert.equal(call.caller_workflow_instance_id, row.workflow_id);
    assert.equal(call.caller_workflow_run_id, row.run_id);
    assert.equal(call.linked_workflow_run_id, row.linked_service_run_id);
    const events = row.history.events;
    const starts = events.filter(event => (event.event_type || event.type) === 'ServiceCallStarted');
    const terminalType = {failed: 'ServiceCallFailed', cancelled: 'ServiceCallCancelled', completed: 'ServiceCallCompleted'}[status];
    const completions = events.filter(event => ['ServiceCallCompleted', 'ServiceCallFailed', 'ServiceCallCancelled']
        .includes(event.event_type || event.type));
    assert.equal(starts.length, 1);
    assert.equal(completions.length, 1);
    assert.equal(completions[0].event_type || completions[0].type, terminalType);
    assert.equal(starts[0].payload.caller_sdk_language, sdkLanguage);
    assert.equal(starts[0].payload.service_call_id, row.result.service_call_id);
    assert.equal(completions[0].payload.service_call_id, row.result.service_call_id);
    assert.equal(completions[0].payload.sequence, starts[0].payload.sequence);
    assert.ok(completions[0].sequence > starts[0].sequence);
}

function assertTypedServiceFailure(row, caller) {
    assert.equal(row.scenario, 'failed');
    assert.equal(row.typed_failure_qualified, true, JSON.stringify(row.failure || row.result));
    assert.equal(row.request_qualified, true);
    assert.ok(row.workflow_id && row.run_id && row.linked_service_run_id);
    assert.ok(row.process_id > 0 && row.installed_sdk_version);
    assert.equal(row.result.failure_class, caller === 'php'
        ? 'DurableWorkflow\\Exception\\ServiceOperationFailed' : 'NexusOperationFailed');
    assert.equal(row.result.failure_message, 'fixture service failure');
    const linked = row.linked_service_history.events.filter(event => event.event_type === 'WorkflowFailed');
    assert.equal(linked.length, 1, 'The actual service workflow must fail once');
    const payload = linked[0].payload;
    const failureType = payload.exception_type || payload.exception_class;
    assert.ok(failureType?.endsWith('NexusFixtureFailure'));
    assert.equal(payload.message, row.result.failure_message);
    assert.equal(row.result.failure_type, failureType, 'Preserve the original service error type');
    assertOriginalServiceDelivery(row, `sdk-${caller}`, 'failed');
    const call = row.durable_service_call.body.service_call || row.durable_service_call.body;
    assert.ok(call.failed_at, 'Failure must have a durable terminal timestamp');
    assert.equal(call.service_error_type, failureType);
}

if (process.env.NEXUS_TYPED_FAILURE_PROBES) {
    test('actual PHP/Python callers catch original typed service failures in all four directions', () => {
        const proof = JSON.parse(fs.readFileSync(process.env.NEXUS_TYPED_FAILURE_PROBES, 'utf8'));
        assert.equal(proof.scope, 'actual_workflow_typed_failure');
        assert.deepEqual(proof.reports.map(row => [row.caller, row.service]), [
            ['php', 'python'], ['python', 'php'], ['php', 'php'], ['python', 'python'],
        ]);
        for (const {caller, observation} of proof.reports) {
            assert.equal(observation.ok, true);
            assert.deepEqual(observation.body.observations.map(row => row.caller_namespace),
                ['shared', caller === 'php' ? 'tenant-a' : 'tenant-b']);
            for (const row of observation.body.observations) assertTypedServiceFailure(row, caller);
        }
    });
}

test('an admission or independent failure cannot qualify original typed delivery', () => {
    const row = {durable_service_call: {ok: true, body: {status: 'started'}}};
    assert.throws(() => assertOriginalServiceDelivery(row, 'sdk-python', 'failed'));
    assert.throws(() => assertTypedServiceFailure({scenario: 'failed', typed_failure_qualified: false}, 'python'));
});

if (process.env.NEXUS_CANCELLATION_PROBES) {
    test('cooperatively cancelled services close original PHP/Python caller operations once', () => {
        const proof = JSON.parse(fs.readFileSync(process.env.NEXUS_CANCELLATION_PROBES, 'utf8'));
        assert.equal(proof.scope, 'linked_workflow_cooperative_cancellation');
        assert.deepEqual(proof.reports.map(row => [row.caller, row.service]), [
            ['php', 'python'], ['python', 'php'], ['php', 'php'], ['python', 'python'],
        ]);
        for (const {caller, service, observation} of proof.reports) {
            assert.equal(observation.ok, true);
            assert.deepEqual(observation.body.observations.map(row => row.caller_namespace),
                ['shared', caller === 'php' ? 'tenant-a' : 'tenant-b']);
            const logs = observation.service_worker_log.split('\n').flatMap(line => {
                try { return [JSON.parse(line.slice(line.indexOf('{')))]; } catch { return []; }
            });
            assert.ok(logs.some(log => log.worker_protocol === '1.20' && log.cooperative_cancellation === true
                && (service !== 'php' || (log.pcntl_available === true && log.posix_available === true))),
                'The actual service worker must explicitly enable cooperative cancellation');
            for (const row of observation.body.observations) {
                assert.equal(row.cancellation_qualified, true, JSON.stringify(row.failure || row.result));
                assert.equal(row.scenario, 'cancelled');
                assert.equal(row.request_qualified, true);
                assertOriginalServiceDelivery(row, `sdk-${caller}`, 'cancelled');
                assert.equal(row.result.failure_class, caller === 'php'
                    ? 'DurableWorkflow\\Exception\\ServiceOperationFailed' : 'NexusOperationFailed');
                const [first, duplicate] = row.linked_cancellation_requests;
                assert.equal(first.accepted, true);
                assert.equal(first.duplicate, false);
                assert.equal(duplicate.duplicate, true);
                const request = first.cancellation_request;
                assert.ok(request.request_id && request.cleanup_deadline_at);
                for (const field of ['request_id', 'requested_at', 'cleanup_deadline_at']) {
                    assert.equal(duplicate.cancellation_request[field], request[field]);
                }
                const events = row.linked_service_history.events;
                for (const kind of ['CooperativeCancellationRequested', 'CooperativeCancellationDelivered', 'WorkflowCancelled']) {
                    assert.equal(events.filter(event => event.event_type === kind).length, 1);
                }
                const requested = events.find(event => event.event_type === 'CooperativeCancellationRequested');
                assert.equal(requested.payload.workflow_command_id, request.request_id);
                assert.equal(requested.payload.reason, 'fixture service cancellation');
                const terminal = events.find(event => event.event_type === 'WorkflowCancelled');
                assert.ok(Date.parse(terminal.timestamp) <= Date.parse(request.cleanup_deadline_at));
                assert.ok(logs.some(log => log.scenario === 'service_cancellation_observed'
                    && log.request_id === request.request_id && log.type === (service === 'php'
                        ? 'DurableWorkflow\\Exception\\WorkflowCancelled' : 'WorkflowCancelled')),
                    'The actual service application must receive typed cooperative cancellation');
            }
        }
    });
}

for (const [input, service] of [
    [process.env.NEXUS_PHP_WORKFLOW_PROBE, 'python'],
    [process.env.NEXUS_PHP_PHP_WORKFLOW_PROBE, 'php'],
]) {
    if (!input) continue;
    test(`actual PHP caller workflows complete through the ${service} service in both namespaces`, () => {
        const response = JSON.parse(fs.readFileSync(input, 'utf8'));
        assert.equal(response.ok, true, 'PHP application probe must execute before outcomes can qualify');
        const observation = response.body;
        assert.equal(observation.scope, `php_caller_${service}_workflow_service`);
        assert.deepEqual(observation.observations.map(row => row.caller_namespace), ['shared', 'tenant-a']);
        for (const row of observation.observations) {
            assert.ok(row.workflow_id && row.run_id && row.process_id > 0 && row.worker_process_id > 0);
            assert.ok(row.installed_sdk_version && row.worker_log);
            assert.equal(row.request_qualified, true, `${row.caller_namespace}: inspect original caller history`);
            assert.equal(row.service_input_qualified, true, `${row.caller_namespace}: inspect linked Python history`);
            assert.equal(row.completed, true, `${row.caller_namespace}: ${JSON.stringify(row.failure || row.result)}`);
            assert.ok(row.linked_service_run_id && row.linked_service_history);
            assert.equal(row.worker_exit_code, row.worker_signal_handlers_available ? 0 : 15,
                'PHP worker must exit through its available signal handling');
            assertOriginalServiceDelivery(row, 'sdk-php');
        }
    });
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

if (process.env.NEXUS_SDK_CLIENT_MATRIX) {
    test('published client admissions preserve their limited qualification scope', () => {
        const matrix = JSON.parse(fs.readFileSync(process.env.NEXUS_SDK_CLIENT_MATRIX, 'utf8'));
        const complete = JSON.parse(fs.readFileSync(process.env.NEXUS_CONFORMANCE_RESULT, 'utf8'));
        assert.equal(matrix.scope, 'sdk_client_admission');
        assert.equal(matrix.outcome, 'pass');
        assert.deepEqual(matrix.pairs.map(({ caller, service }) => [caller, service]).sort(), [
            ['sdk-php', 'sdk-python'], ['sdk-php', 'workflow-php'],
            ['sdk-python', 'sdk-python'], ['sdk-python', 'workflow-php'],
        ]);
        for (const pair of matrix.pairs) assert.equal(pair.admission_verified, true);
        for (const field of ['caller_workflow_execution_verified', 'durable_completion_qualified',
            'typed_failure_qualified', 'cancellation_qualified']) assert.equal(matrix[field], false);
        assert.notEqual(complete.outcome, 'pass');
        assert.equal(complete.runner_blocked, false);
        assert.deepEqual(complete.non_pass_scenarios.slice().sort(), [
            'php_caller_python_service', 'python_caller_php_service',
        ]);
        for (const scenario of complete.non_pass_scenarios) {
            assert.equal(complete.scenario_statuses[scenario], 'not_covered');
        }
    });
}

for (const [input, service] of [
    [process.env.NEXUS_PYTHON_WORKFLOW_PROBE, 'php'],
    [process.env.NEXUS_PYTHON_PYTHON_WORKFLOW_PROBE, 'python'],
]) {
    if (!input) continue;
    test(`installed Python SDK routes original caller identity and Avro input to the actual ${service} worker`, () => {
        const response = JSON.parse(fs.readFileSync(input, 'utf8'));
        assert.equal(response.ok, true, 'The application probe must actually execute');
        assert.deepEqual(response.body.observations.map(row => row.caller_namespace), ['shared', 'tenant-b']);
        for (const row of response.body.observations) {
            assert.equal(row.request_qualified, true, `${row.caller_namespace}: inspect actual request receipt`);
            assert.equal(row.service_input_qualified, true, `${row.caller_namespace}: inspect linked PHP history`);
            assert.ok(row.linked_service_run_id && row.linked_service_history);
        }
    });

    test(`actual Python caller workflows complete through the ${service} service in both namespaces`, () => {
        const response = JSON.parse(fs.readFileSync(input, 'utf8'));
        assert.equal(response.ok, true, 'Python application probe must execute before outcomes can qualify');
        const observation = response.body;
        assert.equal(observation.scope, `python_caller_${service}_workflow_service`);
        assert.deepEqual(observation.observations.map(row => row.caller_namespace), ['shared', 'tenant-b']);
        for (const row of observation.observations) {
            assert.ok(row.workflow_id && row.run_id && row.process_id > 0 && row.installed_sdk_version);
            assert.equal(row.completed, true, `${row.caller_namespace}: ${JSON.stringify(row.failure || row.result)}`);
            assert.ok(row.receipts.some(receipt => receipt.method === 'POST' && receipt.status === 200));
            assertOriginalServiceDelivery(row, 'sdk-python');
        }
    });
}
