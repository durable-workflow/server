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
vm.runInContext(`${source}\nglobalThis.probe = { crossLanguageScenarioResult, callerWorkflowInvocationEvidence, authoredCallerWorkflowEvidence };`,
    context, { timeout: 1000 });
const { crossLanguageScenarioResult, callerWorkflowInvocationEvidence, authoredCallerWorkflowEvidence } = context.probe;

function authoredFixture(caller = 'php', service = 'python') {
    const probes = {};
    for (const status of ['completed', 'failed', 'cancelled']) {
        probes[status] = {ok: true, body: {scope: `${caller}_caller_${service}_workflow_service`, outcome: 'pass',
            observations: ['shared', caller === 'php' ? 'tenant-a' : 'tenant-b'].map((namespace, index) => {
                const identity = `${caller}-${status}-${namespace}`;
                const callId = `call-${identity}`, workflowId = `workflow-${identity}`, runId = `run-${identity}`;
                const linkedRun = `linked-${identity}`;
                const value = {greeting: 'hello, world', language: service, name: 'world'};
                const failure = {exception_type: 'NexusFixtureFailure', message: 'fixture service failure'};
                const terminal = {completed: 'ServiceCallCompleted', failed: 'ServiceCallFailed', cancelled: 'ServiceCallCancelled'}[status];
                const event = (event_type, sequence, payload = {}) => ({event_type, sequence, payload});
                return {
                    scenario: status, caller_namespace: namespace, workflow_id: workflowId, run_id: runId,
                    process_id: 200 + index, worker_process_id: 300 + index, installed_sdk_version: '1.2.3',
                    public_method: caller === 'php' ? 'WorkflowContext.serviceOperation' : 'WorkflowContext.call_nexus_service',
                    worker_log: 'synthetic worker identity', worker_exit_code: 0, worker_signal_handlers_available: true,
                    receipts: [{method: 'POST', status: 200, path: `/worker/workflow-tasks/task-${identity}/complete`}],
                    request_qualified: true, service_input_qualified: true, completed: status === 'completed',
                    typed_failure_qualified: status === 'failed', cancellation_qualified: status === 'cancelled',
                    result: {service_call_id: callId, status, result: value,
                        failure_class: caller === 'php' ? 'DurableWorkflow\\Exception\\ServiceOperationFailed' : 'NexusOperationFailed',
                        failure_message: failure.message, failure_type: failure.exception_type, cancelled: status === 'cancelled'},
                    linked_service_run_id: linkedRun, linked_service_result: value,
                    linked_service_history: {events: [event({completed: 'WorkflowCompleted', failed: 'WorkflowFailed', cancelled: 'WorkflowCancelled'}[status], 1, failure)]},
                    history: {events: [event('WorkflowStarted', 0),
                        event('ServiceCallStarted', 1, {service_call_id: callId, sequence: 1, caller_sdk_language: `sdk-${caller}`}),
                        event(terminal, 2, {service_call_id: callId, sequence: 1}), event('WorkflowCompleted', 3)]},
                    durable_service_call: {ok: true, status: 200, body: {service_call_id: callId, status,
                        caller_workflow_instance_id: workflowId, caller_workflow_run_id: runId,
                        caller_namespace: namespace, target_namespace: 'shared', linked_workflow_run_id: linkedRun,
                        endpoint_name: `published-${service}-workflow-service`, operation_name: 'greet',
                        service_name: service === 'php' ? 'PublishedWorkflowGreeter' : 'PublishedPythonWorkflowGreeter',
                        service_error_type: failure.exception_type, completed_at: '2026-10-01T00:00:00Z', failed_at: '2026-10-01T00:00:00Z'}},
                };
            })}};
    }
    return probes;
}

test('authored qualification binds both namespace histories and every original terminal outcome', () => {
    for (const [caller, service, serviceLanguage] of [['php', 'python', 'sdk-python'], ['python', 'php', 'workflow-php']]) {
        const proof = authoredCallerWorkflowEvidence(authoredFixture(caller, service), `sdk-${caller}`, serviceLanguage, '1.2.3');
        assert.equal(proof.qualified, true, proof.failure_reason);
        assert.equal(proof.observations.length, 6);
        assert.equal(proof.selected.caller_namespace, caller === 'php' ? 'tenant-a' : 'tenant-b');
    }
});

test('aggregate cross-language completion uses executed workflows rather than an unrelated client admission', () => {
    const input = fixture();
    input.versions['sdk-php'] = '1.2.3';
    input.authoredCallerProbes = authoredFixture();
    const result = crossLanguageScenarioResult(input);
    assert.equal(result.status, 'pass');
    assert.equal(result.observed_outputs.service_call_id, 'call-php-completed-tenant-a');
    assert.equal(result.observed_outputs.caller_workflow_run_id, 'run-php-completed-tenant-a');
    assert.equal(result.observed_outputs.authored_caller_workflow_observations.length, 6);
    assert.equal(result.observed_outputs.response_or_failure_surface.sdk_client_admission_service_call_id, 'original-call');
});

test('aggregate evidence refuses absent, unrelated, partial and contradictory authored outcomes', () => {
    const corruptions = [
        proof => { delete proof.completed; }, proof => { delete proof.failed; }, proof => { delete proof.cancelled; },
        proof => { proof.completed.body.observations.pop(); },
        proof => { proof.failed.body.observations[0].installed_sdk_version = '9.9.9'; },
        proof => { proof.cancelled.body.observations[0].result.cancelled = false; },
        proof => { proof.completed.body.observations[0].process_id = 0; },
        proof => { proof.completed.body.observations[0].public_method = 'Client.startServiceOperation'; },
        proof => { proof.completed.body.observations[0].durable_service_call.body.caller_workflow_run_id = 'unrelated'; },
        proof => { proof.completed.body.observations[0].durable_service_call.body.target_namespace = 'foreign'; },
        proof => { proof.completed.body.observations[0].durable_service_call.body.status = 'accepted'; },
        proof => { proof.completed.body.observations[0].history.events.splice(2, 1); },
        proof => { proof.failed.body.observations[0].linked_service_history.events = []; },
        proof => { proof.completed.body.observations[0].result.result.name = 'wrong input'; },
        proof => { proof.completed.body.observations[0].worker_exit_code = 255; },
    ];
    for (const corrupt of corruptions) {
        const input = fixture();
        input.versions['sdk-php'] = '1.2.3';
        input.authoredCallerProbes = authoredFixture();
        corrupt(input.authoredCallerProbes);
        assert.notEqual(crossLanguageScenarioResult(input).status, 'pass');
    }
});

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

function assertOriginalServiceDelivery(row, sdkLanguage, status = 'completed', serviceCallId = row.result.service_call_id) {
    const call = row.durable_service_call?.body?.service_call || row.durable_service_call?.body;
    assert.equal(row.durable_service_call?.ok, true);
    assert.equal(call?.status, status);
    assert.equal(call.service_call_id || call.id, serviceCallId);
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
    assert.equal(starts[0].payload.service_call_id, serviceCallId);
    assert.equal(completions[0].payload.service_call_id, serviceCallId);
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

function assertUncaughtServiceFailure(row, caller, service) {
    assert.equal(row.scenario, 'uncaught_failed');
    assert.equal(row.budget_seconds, 30);
    assert.ok(Number.isFinite(row.elapsed_seconds) && row.elapsed_seconds > 0 && row.elapsed_seconds < 30);
    assert.equal(row.uncaught_failure_qualified, true);
    assert.equal(row.request_qualified, true);
    assert.ok(row.process_id > 0 && row.installed_sdk_version);
    for (const field of ['workflow_id', 'run_id', 'linked_service_run_id', 'original_service_call_id']) {
        assert.ok(typeof row[field] === 'string' && row[field].length > 0, `Missing original ${field}`);
    }
    assert.equal(row.result, undefined, 'An uncaught failure cannot become a handled workflow result');
    assert.equal(row.failure.type, caller === 'php' ? 'DurableWorkflow\\Exception\\WorkflowFailed' : 'WorkflowFailed');
    assert.equal(row.caller_status.toLowerCase(), 'failed');
    if (caller === 'php') {
        assert.ok(row.worker_process_id > 0);
        assert.equal(row.worker_exit_code, row.worker_signal_handlers_available ? 0 : 15);
    } else {
        assert.equal(row.worker_stopped, true);
    }
    const events = (history, type) => history.events.filter(event => (event.event_type || event.type) === type);
    const [callerFailure] = events(row.history, 'WorkflowFailed');
    const [serviceFailure] = events(row.linked_service_history, 'WorkflowFailed');
    assert.equal(events(row.history, 'WorkflowFailed').length, 1);
    assert.equal(events(row.history, 'WorkflowCompleted').length, 0);
    assert.equal(events(row.history, 'WorkflowCancelled').length, 0);
    assert.equal(events(row.history, 'WorkflowStarted').length, 1);
    assert.equal(events(row.linked_service_history, 'WorkflowFailed').length, 1);
    assert.equal(events(row.linked_service_history, 'WorkflowCompleted').length, 0);
    const remoteType = serviceFailure.payload.exception_type || serviceFailure.payload.exception_class;
    assert.ok(remoteType?.endsWith('NexusFixtureFailure'));
    assert.equal(serviceFailure.payload.message, 'fixture service failure');
    const callerType = callerFailure.payload.exception_type || callerFailure.payload.exception_class;
    assert.ok(callerType?.endsWith(caller === 'php' ? 'ServiceOperationFailed' : 'NexusOperationFailed'));
    assert.ok(callerFailure.payload.message.includes('fixture service failure'));
    assertOriginalServiceDelivery(row, `sdk-${caller}`, 'failed', row.original_service_call_id);
    const [started] = events(row.history, 'ServiceCallStarted');
    const [terminal] = events(row.history, 'ServiceCallFailed');
    assert.ok(callerFailure.sequence > terminal.sequence);
    const original = started.payload.service_call;
    const call = row.durable_service_call.body.service_call || row.durable_service_call.body;
    assert.equal(original.service_call_id || original.id, row.original_service_call_id);
    assert.ok(typeof original.accepted_at === 'string' && original.accepted_at.length > 0);
    assert.ok(typeof original.linked_workflow_instance_id === 'string' && original.linked_workflow_instance_id.length > 0);
    assert.equal(call.resolved_binding_kind, 'workflow_run');
    assert.equal(call.endpoint_name, `published-${service}-workflow-service`);
    assert.equal(call.service_name, service === 'php' ? 'publishedworkflowgreeter' : 'publishedpythonworkflowgreeter');
    assert.equal(call.operation_name, 'greet');
    assert.equal(call.caller_namespace, row.caller_namespace);
    assert.equal(call.target_namespace, 'shared');
    assert.ok(call.failed_at);
    assert.equal(call.service_error_type, remoteType);
    assert.equal(terminal.payload.service_call.service_call_id || terminal.payload.service_call.id, row.original_service_call_id);
    assert.equal(terminal.payload.service_call.service_error_type, remoteType);
    assert.equal(terminal.payload.service_call.typed_error_message, serviceFailure.payload.message);
    for (const field of ['accepted_at', 'call_deadline_at', 'linked_workflow_instance_id', 'linked_workflow_run_id',
        'endpoint_name', 'service_name', 'operation_name', 'caller_namespace', 'target_namespace']) {
        assert.ok(Object.hasOwn(original, field), `Original call omitted ${field}`);
        assert.equal(call[field], original[field]);
        assert.equal(terminal.payload.service_call[field], original[field]);
    }
}

function uncaughtFailureFixture(caller = 'php', service = 'python', namespace = caller === 'php' ? 'tenant-a' : 'tenant-b') {
    const remoteType = 'NexusFixtureFailure';
    const wrapper = caller === 'php' ? 'DurableWorkflow\\Exception\\ServiceOperationFailed' : 'NexusOperationFailed';
    const identity = `${caller}-${service}-${namespace}`;
    const ids = {workflow: `caller-${identity}`, run: `run-${identity}`, call: `call-${identity}`,
        target: `target-${identity}`, targetRun: `target-run-${identity}`};
    const original = {id: ids.call, caller_workflow_instance_id: ids.workflow, caller_workflow_run_id: ids.run,
        caller_namespace: namespace, target_namespace: 'shared',
        endpoint_name: `published-${service}-workflow-service`,
        service_name: service === 'php' ? 'publishedworkflowgreeter' : 'publishedpythonworkflowgreeter', operation_name: 'greet',
        linked_workflow_instance_id: ids.target, linked_workflow_run_id: ids.targetRun,
        accepted_at: '2026-10-18T08:00:00.123456Z', call_deadline_at: '2026-10-18T08:00:20.123456Z'};
    const call = {...original, status: 'failed', resolved_binding_kind: 'workflow_run',
        failed_at: '2026-10-18T08:00:01.123456Z', service_error_type: remoteType,
        typed_error_message: 'fixture service failure'};
    return {scenario: 'uncaught_failed', budget_seconds: 30, elapsed_seconds: 2, uncaught_failure_qualified: true,
        request_qualified: true, process_id: 1, worker_process_id: 2, installed_sdk_version: 'published',
        worker_exit_code: 0, worker_signal_handlers_available: true, worker_stopped: true,
        workflow_id: ids.workflow, run_id: ids.run, linked_service_run_id: ids.targetRun,
        caller_namespace: original.caller_namespace,
        original_service_call_id: ids.call, caller_status: 'Failed',
        failure: {type: caller === 'php' ? 'DurableWorkflow\\Exception\\WorkflowFailed' : 'WorkflowFailed'},
        durable_service_call: {ok: true, body: call},
        history: {events: [{event_type: 'WorkflowStarted', sequence: 0},
            {event_type: 'ServiceCallStarted', sequence: 1,
                payload: {service_call_id: ids.call, sequence: 0, caller_sdk_language: `sdk-${caller}`, service_call: original}},
            {event_type: 'ServiceCallFailed', sequence: 2,
                payload: {service_call_id: ids.call, sequence: 0, service_call: {...call}}},
            {event_type: 'WorkflowFailed', sequence: 3,
                payload: {exception_type: wrapper, message: 'fixture service failure'}}]},
        linked_service_history: {events: [{event_type: 'WorkflowFailed',
            payload: {exception_type: remoteType, message: 'fixture service failure'}}]}};
}

test('uncaught failure reader requires the original terminal failure rather than a caught result or transport error', () => {
    for (const caller of ['php', 'python']) {
        const service = caller === 'php' ? 'python' : 'php';
        assertUncaughtServiceFailure(uncaughtFailureFixture(caller, service), caller, service);
    }
    const noCallDeadline = uncaughtFailureFixture();
    noCallDeadline.history.events[1].payload.service_call.call_deadline_at = null;
    noCallDeadline.history.events[2].payload.service_call.call_deadline_at = null;
    noCallDeadline.durable_service_call.body.call_deadline_at = null;
    assertUncaughtServiceFailure(noCallDeadline, 'php', 'python');
    for (const mutate of [
        row => { row.result = {status: 'failed', service_call_id: 'call'}; },
        row => { row.history.events.at(-1).event_type = 'WorkflowCompleted'; },
        row => { row.failure.type = 'TimeoutError'; },
        row => { row.history.events.at(-1).payload.exception_type = 'RuntimeError'; },
        row => { row.history.events.at(-1).payload.message = 'generic failure'; },
        row => { row.history.events.push(structuredClone(row.history.events[2])); },
        row => { row.durable_service_call.body.caller_workflow_run_id = 'other'; },
        row => { row.durable_service_call.body.linked_workflow_run_id = 'other'; },
        row => { row.durable_service_call.body.accepted_at = '2026-10-18T08:00:02.123456Z'; },
        row => { row.durable_service_call.body.call_deadline_at = '2026-10-18T08:00:21.123456Z'; },
        row => { row.durable_service_call.body.service_error_type = 'ServiceOperationFailed'; },
        row => { row.history.events[1].payload.service_call.id = 'other'; },
        row => { row.original_service_call_id = ''; },
        row => { row.durable_service_call.body.endpoint_name = 'another-service'; },
        row => { row.durable_service_call.body.target_namespace = 'other'; },
        row => { row.durable_service_call.body.operation_name = 'other'; },
        row => { row.durable_service_call.body.service_name = 'PublishedPythonWorkflowGreeter'; },
        row => { delete row.history.events[1].payload.service_call.call_deadline_at; },
        row => { row.history.events[2].payload.service_call.call_deadline_at = '2026-10-18T08:01:00Z'; },
        row => { row.history.events.at(-1).sequence = 1; },
        row => { row.worker_exit_code = 137; },
        row => { row.elapsed_seconds = 30.01; },
        row => { row.process_id = 0; },
    ]) {
        const row = uncaughtFailureFixture(); mutate(row); assert.throws(() => assertUncaughtServiceFailure(row, 'php', 'python'));
    }
});

function assertUncaughtFailureReports(proof) {
    assert.equal(proof.scope, 'actual_uncaught_workflow_failure');
    assert.deepEqual(proof.reports.map(row => [row.caller, row.service]), [
        ['php', 'python'], ['python', 'php'], ['php', 'php'], ['python', 'python'],
    ]);
    for (const {caller, service, observation} of proof.reports) {
        assert.equal(observation.ok, true, JSON.stringify(observation.body));
        assert.equal(observation.body.outcome, 'pass');
        assert.deepEqual(observation.body.observations.map(row => row.caller_namespace),
            ['shared', caller === 'php' ? 'tenant-a' : 'tenant-b']);
        for (const row of observation.body.observations) assertUncaughtServiceFailure(row, caller, service);
    }
    const originals = proof.reports.flatMap(report => report.observation.body.observations);
    for (const field of ['run_id', 'original_service_call_id']) {
        assert.equal(new Set(originals.map(row => row[field])).size, 8, `Each case needs its own original ${field}`);
    }
}

test('uncaught failure report needs every direction and namespace with distinct original runs and calls', () => {
    const proof = {scope: 'actual_uncaught_workflow_failure', reports: [
        ['php', 'python'], ['python', 'php'], ['php', 'php'], ['python', 'python'],
    ].map(([caller, service]) => ({caller, service, observation: {ok: true, body: {outcome: 'pass',
        observations: ['shared', caller === 'php' ? 'tenant-a' : 'tenant-b']
            .map(namespace => uncaughtFailureFixture(caller, service, namespace))}}}))};
    assertUncaughtFailureReports(proof);
    const missing = structuredClone(proof); missing.reports.pop();
    assert.throws(() => assertUncaughtFailureReports(missing));
    const reused = structuredClone(proof);
    const [first, second] = reused.reports[0].observation.body.observations;
    second.run_id = first.run_id;
    second.durable_service_call.body.caller_workflow_run_id = first.run_id;
    second.history.events[1].payload.service_call.caller_workflow_run_id = first.run_id;
    second.history.events[2].payload.service_call.caller_workflow_run_id = first.run_id;
    assert.throws(() => assertUncaughtFailureReports(reused));
});

function assertCallerRecovery(row, service, caller = 'php') {
    assert.equal(row.scenario, 'caller_recovery');
    assert.equal(row.recovery_qualified, true, JSON.stringify(row.failure));
    assert.ok(row.worker_process_id > 0 && row.replacement_worker_process_id > 0);
    assert.notEqual(row.worker_process_id, row.replacement_worker_process_id);
    assert.equal(row.killed_worker.pid, row.worker_process_id);
    assert.equal(row.killed_worker.running, false);
    assert.equal(row.killed_worker.signaled, true);
    assert.equal(row.killed_worker.termsig, 9);
    assert.equal(row.replacement_worker_exit_code, 0);
    assert.ok(row.elapsed_seconds > 0 && row.elapsed_seconds < row.budget_seconds);
    assert.ok(row.installed_sdk_version && row.worker_log);
    const expected = {language: service, name: 'recover', greeting: 'hello, recover'};
    assert.deepEqual(caller === 'python' ? row.decoded_service_result : row.result.result, expected);
    assert.deepEqual(row.linked_service_result, expected);
    assert.equal(row.result.status, 'completed');
    assert.equal(row.result.service_call_id, row.original_service_call_id);
    assertOriginalServiceDelivery(row, `sdk-${caller}`);
    const call = row.durable_service_call.body.service_call || row.durable_service_call.body;
    const original = row.original_service_call;
    assert.equal(original.service_call_id || original.id, row.original_service_call_id);
    for (const field of ['accepted_at', 'call_deadline_at', 'linked_workflow_run_id', 'linked_workflow_instance_id']) {
        assert.ok(Object.hasOwn(original, field), `Original call omitted ${field}`);
        assert.equal(call[field], original[field]);
    }
    const events = (history, type) => history.events.filter(event => (event.event_type || event.type) === type);
    assert.equal(events(row.before_kill_history, 'ServiceCallStarted').length, 1);
    assert.equal(events(row.before_kill_history, 'ServiceCallCompleted').length, 0);
    assert.equal(events(row.before_kill_service_history, 'TimerScheduled').length, 1);
    assert.equal(events(row.before_kill_service_history, 'WorkflowCompleted').length, 0);
    assert.equal(events(row.linked_service_history, 'WorkflowStarted').length, 1);
    assert.equal(events(row.linked_service_history, 'WorkflowCompleted').length, 1);
    assert.equal(events(row.before_replacement_history, 'WorkflowCompleted').length, 0);
    assert.equal(events(row.history, 'WorkflowCompleted').length, 1);
    assert.equal(row.caller_operations.ok, true);
    assert.equal(row.caller_operations.body.workflow_run_id, row.run_id);
    assert.deepEqual(row.caller_operations.body.nexus_operations.map(call => call.service_call_id), [row.original_service_call_id]);
}

function callerRecoveryFixture() {
    const start = {event_type: 'ServiceCallStarted', sequence: 1,
        payload: {service_call_id: 'call', sequence: 0, caller_sdk_language: 'sdk-php'}};
    const completion = {event_type: 'ServiceCallCompleted', sequence: 2, payload: {service_call_id: 'call', sequence: 0}};
    const call = {service_call_id: 'call', status: 'completed', caller_workflow_instance_id: 'caller',
        caller_workflow_run_id: 'run', linked_workflow_run_id: 'target-run', linked_workflow_instance_id: 'target',
        accepted_at: '2026-10-11T00:00:00.000000Z', call_deadline_at: null};
    const result = {language: 'php', name: 'recover', greeting: 'hello, recover'};
    return {scenario: 'caller_recovery', recovery_qualified: true, worker_process_id: 1, replacement_worker_process_id: 2,
        killed_worker: {pid: 1, running: false, signaled: true, termsig: 9}, replacement_worker_exit_code: 0,
        installed_sdk_version: '2.3.0-rc.1', worker_log: 'original and replacement', budget_seconds: 60, elapsed_seconds: 9,
        workflow_id: 'caller', run_id: 'run', original_service_call_id: 'call', linked_service_run_id: 'target-run',
        original_service_call: {...call}, durable_service_call: {ok: true, body: call},
        result: {service_call_id: 'call', status: 'completed', result}, linked_service_result: result,
        before_kill_history: {events: [start]}, before_kill_service_history: {events: [{event_type: 'TimerScheduled'}]},
        before_replacement_history: {events: [start]}, history: {events: [start, completion, {event_type: 'WorkflowCompleted'}]},
        linked_service_history: {events: [{event_type: 'WorkflowStarted'}, {event_type: 'WorkflowCompleted'}]},
        caller_operations: {ok: true, body: {workflow_run_id: 'run', nexus_operations: [{service_call_id: 'call'}]}}};
}

function assertBackendInterruption(row, service, caller) {
    assertCallerRecovery(row, service, caller);
    assert.equal(row.budget_seconds, 60, 'Keep the original recovery budget');
    const proof = row.backend_interruption;
    assert.equal(proof.restored, true, JSON.stringify(proof));
    assert.equal(proof.error, undefined);
    assert.equal(proof.restore_error, undefined);
    assert.match(proof.original_database_id, /^[0-9a-f]{64}$/);
    assert.equal(proof.restored_database_id, proof.original_database_id);
    assert.equal(proof.before_stop.Running, true);
    assert.equal(proof.stopped.Running, false);
    assert.equal(proof.failure_signal, 9, 'The database crash is an explicit SIGKILL');
    assert.equal(proof.stopped.ExitCode, 137);
    assert.equal(proof.stopped.OOMKilled, false, 'Resource exhaustion must not imitate the requested crash');
    assert.equal(proof.unready.status, 503, 'Readiness must fail while the database is absent');
    assert.equal(proof.ready.status, 200);
    assert.equal(proof.after_restart.Running, true);
    assert.equal(proof.checkpoint.workflow_id, row.workflow_id);
    assert.equal(proof.checkpoint.run_id, row.run_id);
    assert.equal(proof.checkpoint.service_call_id, row.original_service_call_id);
    assert.equal(proof.checkpoint.caller_namespace, row.caller_namespace);
    const timer = row.before_kill_service_history.events.find(event => event.event_type === 'TimerScheduled');
    assert.equal(proof.checkpoint.timer_fire_at, timer.payload.fire_at);
    const times = ['stop_requested_at', 'stopped_at', 'down_until', 'restart_requested_at', 'restored_at'];
    const parsed = times.map(field => Date.parse(proof[field]));
    assert.ok(parsed.every(Number.isFinite), 'Every backend transition needs its actual timestamp');
    for (let index = 1; index < parsed.length; index++) assert.ok(parsed[index] >= parsed[index - 1]);
    const fireAt = Date.parse(timer.payload.fire_at);
    assert.ok(parsed[1] < fireAt, 'Stop the database before the original target timer is due');
    assert.ok(parsed[2] > fireAt, 'Keep it absent across the original timer boundary');
}

test('backend evidence rejects a running database, healthy readiness, new database or changed budget', () => {
    const row = callerRecoveryFixture();
    row.caller_namespace = 'shared';
    row.before_kill_service_history.events[0].payload = {fire_at: '2026-10-11T00:00:08Z'};
    row.backend_interruption = {restored: true, original_database_id: 'a'.repeat(64), restored_database_id: 'a'.repeat(64),
        failure_signal: 9, before_stop: {Running: true}, stopped: {Running: false, ExitCode: 137, OOMKilled: false}, after_restart: {Running: true},
        unready: {status: 503}, ready: {status: 200}, stop_requested_at: '2026-10-11T00:00:01Z',
        stopped_at: '2026-10-11T00:00:02Z', down_until: '2026-10-11T00:00:09Z',
        restart_requested_at: '2026-10-11T00:00:09Z', restored_at: '2026-10-11T00:00:10Z',
        checkpoint: {workflow_id: 'caller', run_id: 'run', service_call_id: 'call', caller_namespace: 'shared',
            timer_fire_at: '2026-10-11T00:00:08Z'}};
    assertBackendInterruption(row, 'php', 'php');
    for (const mutate of [
        copy => { copy.backend_interruption.stopped.Running = true; },
        copy => { copy.backend_interruption.stopped.OOMKilled = true; },
        copy => { copy.backend_interruption.failure_signal = 15; },
        copy => { copy.backend_interruption.unready.status = 200; },
        copy => { copy.backend_interruption.restored_database_id = 'b'.repeat(64); },
        copy => { copy.backend_interruption.checkpoint.service_call_id = 'other'; },
        copy => { copy.backend_interruption.stopped_at = '2026-10-11T00:00:08Z'; },
        copy => { copy.backend_interruption.down_until = '2026-10-11T00:00:07Z'; },
        copy => { copy.budget_seconds = 120; },
    ]) {
        const copy = structuredClone(row);
        mutate(copy);
        assert.throws(() => assertBackendInterruption(copy, 'php', 'php'));
    }
});

if (process.env.NEXUS_BACKEND_INTERRUPTION_PROBES) {
    test('actual PHP/Python callers preserve their original call across SIGKILL and a database interruption', () => {
        const proof = JSON.parse(fs.readFileSync(process.env.NEXUS_BACKEND_INTERRUPTION_PROBES, 'utf8'));
        assert.equal(proof.scope, 'actual_php_python_caller_sigkill_and_mysql_crash_restart');
        assert.deepEqual(proof.reports.map(({caller, service}) => [caller, service]),
            [['php', 'php'], ['php', 'python'], ['python', 'php'], ['python', 'python']]);
        for (const {caller, service, observation} of proof.reports) {
            assert.equal(observation.ok, true);
            assert.equal(observation.body.outcome, 'pass');
            assert.deepEqual(observation.body.observations.map(row => row.caller_namespace),
                ['shared', caller === 'php' ? 'tenant-a' : 'tenant-b']);
            for (const row of observation.body.observations) assertBackendInterruption(row, service, caller);
        }
    });
}

test('caller recovery rejects a surviving worker, duplicate call, changed deadline or independently completed run', () => {
    assertCallerRecovery(callerRecoveryFixture(), 'php');
    for (const mutate of [
        row => { row.killed_worker.running = true; },
        row => { row.replacement_worker_process_id = row.worker_process_id; },
        row => { row.caller_operations.body.nexus_operations.push({service_call_id: 'duplicate'}); },
        row => { row.durable_service_call.body.call_deadline_at = '2026-10-11T00:01:00.000000Z'; },
        row => { row.before_replacement_history.events.push({event_type: 'WorkflowCompleted'}); },
        row => { row.result.result.greeting = 'wrong result'; },
    ]) {
        const row = callerRecoveryFixture();
        mutate(row);
        assert.throws(() => assertCallerRecovery(row, 'php'));
    }
});

test('Python caller recovery requires its decoded result and original Python delivery boundary', () => {
    const row = callerRecoveryFixture();
    row.decoded_service_result = row.result.result;
    row.result.result = 'encoded-avro';
    row.history.events[0].payload.caller_sdk_language = 'sdk-python';
    assertCallerRecovery(row, 'php', 'python');
    row.decoded_service_result = {language: 'php', name: 'recover', greeting: 'wrong result'};
    assert.throws(() => assertCallerRecovery(row, 'php', 'python'));
});

if (process.env.NEXUS_CALLER_RECOVERY_PROBES) {
    test('actual replacement PHP/Python callers resume the original call after SIGKILL in every direction', () => {
        const proof = JSON.parse(fs.readFileSync(process.env.NEXUS_CALLER_RECOVERY_PROBES, 'utf8'));
        assert.equal(proof.scope, 'actual_php_python_caller_sigkill_recovery');
        assert.deepEqual(proof.reports.map(({caller, service}) => [caller, service]),
            [['php', 'php'], ['php', 'python'], ['python', 'php'], ['python', 'python']]);
        for (const {caller, service, observation} of proof.reports) {
            assert.equal(observation.ok, true);
            assert.equal(observation.body.outcome, 'pass');
            assert.deepEqual(observation.body.observations.map(row => row.caller_namespace),
                ['shared', caller === 'php' ? 'tenant-a' : 'tenant-b']);
            for (const row of observation.body.observations) assertCallerRecovery(row, service, caller);
        }
    });
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

function assertOriginalDeadline(row) {
    const call = row.durable_service_call?.body?.service_call || row.durable_service_call?.body;
    assert.deepEqual(call.deadline_policy, {timeout_seconds: 1});
    const micros = (value) => {
        const timestamp = /^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})\.(\d{6})Z$/.exec(value);
        assert.ok(timestamp, 'Original timestamps must retain UTC microseconds');
        return BigInt(Date.parse(`${timestamp[1]}Z`)) * 1000n + BigInt(timestamp[2]);
    };
    assert.equal(micros(call.call_deadline_at) - micros(call.accepted_at), 1000000n);
    const first = row.result.response_or_failure_surface;
    const delivered = first.service_call || first;
    assert.equal(delivered.accepted_at, call.accepted_at);
    assert.equal(delivered.call_deadline_at, call.call_deadline_at);
    const duplicate = row.duplicate_result;
    const repeated = duplicate.response_or_failure_surface.service_call || duplicate.response_or_failure_surface;
    assert.equal(duplicate.service_call_id, row.result.service_call_id);
    assert.equal(duplicate.status, 'failed');
    assert.equal(repeated.idempotent_replay, true);
    assert.equal(repeated.accepted_at, call.accepted_at);
    assert.equal(repeated.call_deadline_at, call.call_deadline_at);
    assert.equal(repeated.outcome, 'timed_out');
    assert.equal(call.completed_at, null);
    assert.equal(call.output_payload_reference, null);
    assert.equal(call.service_error_type, 'ServiceCallTimedOut');
    assert.equal(row.result.failure_type, 'ServiceCallTimedOut');
    assert.equal(row.service_input_qualified, true, 'The late target retains its own legitimate result');
    const terminal = row.linked_service_history.events.filter(event => event.event_type === 'WorkflowCompleted');
    assert.equal(terminal.length, 1);
    assert.ok(Date.parse(terminal[0].timestamp) > Date.parse(call.call_deadline_at));
}

test('deadline evidence rejects a restarted budget or discarded late target history', () => {
    const call = {
        deadline_policy: {timeout_seconds: 1}, accepted_at: '2026-10-11T00:00:00.123456Z',
        call_deadline_at: '2026-10-11T00:00:01.123456Z', completed_at: null,
        output_payload_reference: null, service_error_type: 'ServiceCallTimedOut',
    };
    const row = {
        durable_service_call: {body: call},
        result: {service_call_id: 'call-1', failure_type: 'ServiceCallTimedOut', response_or_failure_surface: {...call}},
        duplicate_result: {service_call_id: 'call-1', status: 'failed', response_or_failure_surface: {
            ...call, idempotent_replay: true, outcome: 'timed_out',
        }},
        service_input_qualified: true,
        linked_service_history: {events: [{event_type: 'WorkflowCompleted', timestamp: '2026-10-11T00:00:03.123456Z'}]},
    };
    assertOriginalDeadline(row);
    call.call_deadline_at = '2026-10-11T00:00:02.123456Z';
    assert.throws(() => assertOriginalDeadline(row));
    call.call_deadline_at = '2026-10-11T00:00:01.123456Z';
    row.service_input_qualified = false;
    assert.throws(() => assertOriginalDeadline(row));
});

if (process.env.NEXUS_DEADLINE_PROBE) {
    test('the original call deadline rejects a late PHP service result in both namespaces', () => {
        const proof = JSON.parse(fs.readFileSync(process.env.NEXUS_DEADLINE_PROBE, 'utf8'));
        assert.equal(proof.scope, 'python_caller_php_service_original_deadline');
        assert.deepEqual(proof.declared_policy, {timeout_seconds: 1});
        assert.equal(proof.handler_sleep_seconds, 3);
        assert.equal(proof.registration.ok, true);
        assert.deepEqual(proof.registration.body.deadline_policy, proof.declared_policy);
        assert.equal(proof.observation.ok, true);
        assert.deepEqual(proof.observation.body.observations.map(row => row.caller_namespace), ['shared', 'tenant-b']);
        for (const row of proof.observation.body.observations) {
            assert.equal(row.scenario, 'deadline');
            assert.equal(row.request_qualified, true);
            assert.equal(row.deadline_qualified, true, JSON.stringify(row.failure || row.result));
            assertOriginalServiceDelivery(row, 'sdk-python', 'failed');
            assert.equal(row.result.failure_class, 'NexusOperationFailed');
            const call = row.durable_service_call.body.service_call || row.durable_service_call.body;
            assert.equal(call.outcome, 'timed_out');
            assert.ok(call.failed_at);
            assertOriginalDeadline(row);
        }
    });
}

if (process.env.NEXUS_WAITING_CALLER_CANCELLATION_PROBES) {
    test('cancelling actual waiting callers cooperatively closes their original target cascade', () => {
        const proof = JSON.parse(fs.readFileSync(process.env.NEXUS_WAITING_CALLER_CANCELLATION_PROBES, 'utf8'));
        assert.equal(proof.scope, 'actual_waiting_caller_cooperative_cancellation');
        assert.deepEqual(proof.reports.map(row => [row.caller, row.service]), [
            ['php', 'python'], ['python', 'php'], ['php', 'php'], ['python', 'python'],
        ]);
        for (const {caller, observation} of proof.reports) {
            assert.equal(observation.ok, true, JSON.stringify(observation.body));
            assert.deepEqual(observation.body.observations.map(row => row.caller_namespace),
                ['shared', caller === 'php' ? 'tenant-a' : 'tenant-b']);
            for (const row of observation.body.observations) {
                assert.equal(row.request_qualified, true);
                assert.equal(row.scenario, 'caller_cancelled');
                assert.equal(row.caller_cancellation_qualified, true, JSON.stringify(row.failure || row.caller_status));
                assert.equal(row.linked_cancellation_requests, undefined,
                    'The investigator must not directly cancel the target');
                const [first, duplicate] = row.caller_cancellation_requests;
                assert.equal(first.accepted, true);
                assert.equal(duplicate.duplicate, true);
                const request = first.cancellation_request;
                for (const field of ['request_id', 'requested_at', 'cleanup_deadline_at']) {
                    assert.ok(request[field]);
                    assert.equal(duplicate.cancellation_request[field], request[field]);
                }
                const deadline = Date.parse(request.cleanup_deadline_at);
                const original = row.service_call_before_cancellation;
                const call = row.durable_service_call.body.service_call || row.durable_service_call.body;
                assert.equal(call.status, 'cancelled');
                assert.equal(call.service_call_id || call.id, original.service_call_id || original.id);
                assert.equal(call.caller_workflow_instance_id, row.workflow_id);
                assert.equal(call.caller_workflow_run_id, row.run_id);
                assert.equal(call.linked_workflow_run_id, original.linked_workflow_run_id);
                assert.ok(original.call_deadline_at);
                assert.equal(call.call_deadline_at, original.call_deadline_at);
                const starts = row.history.events.filter(event => event.event_type === 'ServiceCallStarted');
                const terminals = row.history.events.filter(event =>
                    ['ServiceCallCompleted', 'ServiceCallFailed', 'ServiceCallCancelled'].includes(event.event_type));
                assert.equal(starts.length, 1);
                assert.equal(terminals.length, 1);
                assert.equal(terminals[0].event_type, 'ServiceCallCancelled');
                assert.equal(terminals[0].payload.service_call_id, starts[0].payload.service_call_id);
                assert.equal(terminals[0].payload.sequence, starts[0].payload.sequence);
                for (const events of [row.history.events, row.linked_service_history.events]) {
                    for (const kind of ['CooperativeCancellationRequested', 'CooperativeCancellationDelivered', 'WorkflowCancelled']) {
                        assert.equal(events.filter(event => event.event_type === kind).length, 1);
                    }
                    assert.equal(events.filter(event => event.event_type === 'WorkflowTerminated').length, 0);
                    const requested = events.find(event => event.event_type === 'CooperativeCancellationRequested');
                    const context = requested.payload.cancellation;
                    assert.equal(context.root_request_id, request.request_id);
                    assert.equal(context.root_workflow_instance_id, row.workflow_id);
                    assert.equal(context.root_workflow_run_id, row.run_id);
                    assert.equal(context.cleanup_deadline_at, request.cleanup_deadline_at);
                    assert.equal(context.reason, 'fixture waiting caller cancellation');
                    const terminal = events.find(event => event.event_type === 'WorkflowCancelled');
                    assert.ok(Date.parse(terminal.timestamp) <= deadline);
                    assert.equal(terminal.payload.cancellation_cleanup.outcome, 'completed');
                }
            }
        }
    });
}

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
    for (const claimed of [false, true]) {
        const input = fixture('completed');
        input.workerExecution.workers[0].caller_workflow_invocation.executed = claimed;
        input.workerExecution.workers[0].caller_workflow_invocation.execution_mode = 'claimed_workflow';
        const result = crossLanguageScenarioResult(input);
        assert.equal(result.status, 'not_covered');
        assert.equal(result.observed_outputs.response_or_failure_surface.caller_workflow_execution_verified, false);
    }
});

test('a caller language label without SDK process execution cannot qualify a cell', () => {
    const result = crossLanguageScenarioResult(fixture('completed', 0));
    assert.notEqual(result.status, 'pass');
});

if (process.env.NEXUS_UNCAUGHT_FAILURE_PROBES) {
    test('actual published uncaught service exceptions fail the original caller workflow once', () => {
        const proof = JSON.parse(fs.readFileSync(process.env.NEXUS_UNCAUGHT_FAILURE_PROBES, 'utf8'));
        assertUncaughtFailureReports(proof);
    });
}

if (process.env.NEXUS_SDK_CLIENT_MATRIX) {
    test('published client admissions preserve their limited qualification scope', () => {
        const matrix = JSON.parse(fs.readFileSync(process.env.NEXUS_SDK_CLIENT_MATRIX, 'utf8'));
        assert.equal(matrix.scope, 'sdk_client_admission');
        assert.equal(matrix.outcome, 'pass');
        assert.deepEqual(matrix.pairs.map(({ caller, service }) => [caller, service]).sort(), [
            ['sdk-php', 'sdk-python'], ['sdk-php', 'workflow-php'],
            ['sdk-python', 'sdk-python'], ['sdk-python', 'workflow-php'],
        ]);
        for (const pair of matrix.pairs) {
            assert.equal(pair.admission_verified, true);
            assert.equal(pair.caller_workflow_execution_verified, false);
        }
        for (const field of ['caller_workflow_execution_verified', 'durable_completion_qualified',
            'typed_failure_qualified', 'cancellation_qualified']) assert.equal(matrix[field], false);
        // Authored workflow outcomes qualify the aggregate independently of this admission matrix.
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
