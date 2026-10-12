import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkSdkSignalDirectionPackages} from './sdk-signal-direction-contract.mjs';

const artifacts = JSON.parse(readFileSync(new URL('../../../tests/Fixtures/ServerParityPending/published-sdk-namespace-signals/artifacts.json', import.meta.url), 'utf8'));

export function namespaceSignalRunFixture(fixture, peer = false) {
  const {namespace_signal_pair: pair, ...run} = fixture;
  return {...run, namespace_signal_run: peer ? 'peer' : 'primary',
    ...(peer ? {namespace: pair.peer_namespace, signal_value: pair.peer_signal_value,
      typed_signal_value: pair.typed_peer_signal_value} : {})};
}

export function checkNamespaceSignalPair(fixture, observation, workflowId, checkRun) {
  const spec = fixture.namespace_signal_pair, pair = observation.namespace_signal_pair;
  assert.equal(fixture.namespace, 'parity-signals-left');
  assert.equal(spec.peer_namespace, 'parity-signals-right');
  for (const key of ['same_workflow_type_and_queue', 'both_original_runs_wait_before_delivery']) assert.equal(spec[key], true);
  assert.equal(spec.foreign_signal_target, 'original_workflow_id');
  assert.equal(spec.foreign_signal_expected_status, 404);
  assert.equal(spec.foreign_worker_probe, 'real_empty_workflow_poll_before_correct_claim');
  assert.equal(spec.run_budget_seconds, 35); assert.equal(spec.pair_budget_seconds, 70);
  assert.equal(spec.typed_peer_signal_value.value.count.value, '9007199254740995');
  assert.notDeepEqual(spec.typed_peer_signal_value, fixture.typed_signal_value);
  assert.equal(pair.applicable, true);
  assert.equal(pair.run_budget_seconds, 35); assert.equal(pair.pair_budget_seconds, 70);
  const fixtures = [namespaceSignalRunFixture(fixture), namespaceSignalRunFixture(fixture, true)];
  const ids = [workflowId, workflowId + ':peer'];
  const primary = {...observation}; delete primary.namespace_signal_pair;
  const runs = [primary, pair.peer];
  checkSdkSignalDirectionPackages(fixtures[1], runs[1], artifacts);
  assert.equal(new Set(runs.map(run => run.run_id)).size, 2, 'two different original runs');
  const projections = runs.map((run, index) => checkRun(fixtures[index], run, ids[index]));
  assert.equal(pair.initial.length, 2);
  assert.equal(pair.run_budgets.length, 2);
  assert(Number.isFinite(pair.pair_started_at_unix) && Number.isFinite(pair.pair_finished_at_unix));
  assert(pair.pair_finished_at_unix >= pair.pair_started_at_unix);
  assert(pair.pair_finished_at_unix - pair.pair_started_at_unix <= 70, 'unchanged pair ceiling');
  for (const [index, run] of runs.entries()) {
    const budget = pair.run_budgets[index];
    for (const field of ['started_at_unix', 'deadline_at_unix', 'finished_at_unix']) assert(Number.isFinite(budget[field]));
    assert(Math.abs(budget.deadline_at_unix - budget.started_at_unix - 35) < 0.000001, 'one original 35-second budget');
    assert(budget.started_at_unix >= pair.pair_started_at_unix && budget.finished_at_unix <= pair.pair_finished_at_unix);
    assert(budget.finished_at_unix >= budget.started_at_unix && budget.finished_at_unix <= budget.deadline_at_unix);
    const initial = pair.initial[index];
    for (const field of ['workflow_id', 'run_id', 'workflow_type', 'namespace', 'task_queue']) assert.equal(initial[field], run[field]);
    assert.equal(initial.status, 'waiting');
    assert.deepEqual(initial.events, run.events.slice(0, 3), 'both original waits precede every signal');
    assert.equal(initial.typed_output.type, 'null');
  }
  const identities = runs.flatMap(run => [run.workflow_id, run.run_id, run.events[0].payload.workflow_command_id,
    ...run.events.filter(event => event.event_type === 'SignalReceived').flatMap(event => [event.payload.signal_id, event.payload.workflow_command_id]),
    ...run.events.filter(event => ['SignalWaitOpened', 'ConditionWaitOpened'].includes(event.event_type))
      .map(event => event.payload.signal_wait_id ?? event.payload.condition_wait_id)]);
  assert.equal(new Set(identities).size, identities.length, 'no cross-namespace alias among persisted identities');
  assert.equal(pair.foreign_signals.length, 2);
  const checkSnapshots = values => {
    assert.equal(values.length, 2);
    values.forEach((value, index) => {
      for (const field of ['workflow_id', 'run_id', 'workflow_type', 'namespace', 'task_queue']) assert.equal(value[field], runs[index][field]);
      assert.deepEqual(value.events, runs[index].events.slice(0, value.events.length), 'unmodified original history prefix');
    });
  };
  const foreignPids = [];
  for (const [index, refusal] of pair.foreign_signals.entries()) {
    assert.equal(refusal.target_run, index);
    assert.deepEqual(refusal.before, refusal.after, 'foreign signal makes no mutation');
    const result = refusal.result;
    if (observation.mode === 'embedded') {
      checkSnapshots(refusal.before.runs);
      assert.equal(result.refused, true);
      assert.equal(result.typed_error.class, 'Illuminate\\Database\\Eloquent\\ModelNotFoundException');
      for (const command of refusal.before.commands) assert(runs.some(run => run.run_id === command.workflow_run_id));
      for (const task of refusal.before.tasks) assert.equal(task.namespace, runs.find(run => run.run_id === task.workflow_run_id)?.namespace);
      continue;
    }
    checkSnapshots(refusal.before);
    assert.equal(result.phase, 'foreign-signal');
    assert.equal(result.language, fixture.sdk_signal_direction.client);
    assert.equal(result.sdk_version, runs[index].sdk_signal_direction.phases[1].sdk_version);
    assert.equal(result.workflow_id, ids[index]); assert.equal(result.run_id, runs[index].run_id);
    assert.equal(result.signal_sent, false); assert.equal(result.sender_finished, true);
    assert(Number.isInteger(result.pid) && result.pid > 0); foreignPids.push(result.pid);
    const signals = result.requests.filter(request => request.method === 'POST' && decodeURIComponent(request.path) === '/api/workflows/' + ids[index] + '/signal/payload');
    assert.equal(signals.length, 1, 'one actual wrong-scope SDK request');
    const wire = signals[0];
    assert.equal(wire.status, 404);
    assert.equal(wire.namespace, runs[1 - index].namespace);
    assert.equal(wire.request.run_id, undefined);
    assert(!result.requests.some(request => request.path.startsWith('/api/worker/')));
    const error = result.typed_error;
    if (result.language === 'php') {
      assert.equal(error.class, 'DurableWorkflow\\Exception\\SignalFailed'); assert.equal(error.status, 404);
      assert.equal(error.reason, wire.response.reason);
      assert.deepEqual(error.details, wire.response);
    } else if (result.language === 'rust') {
      assert.equal(error.class, 'durable_workflow::Error::Http'); assert.equal(error.status, 404);
      assert.deepEqual(JSON.parse(error.body), wire.response);
    } else {
      assert(['durable_workflow.errors.WorkflowNotFound', 'durable_workflow.errors.ServerError'].includes(error.class));
      if (error.class.endsWith('.ServerError')) { assert.equal(error.status, 404); assert.deepEqual(error.body, wire.response); }
    }
  }
  if (observation.mode === 'embedded') {
    assert.deepEqual(pair.foreign_polls, {applicable: false, reason: 'embedded_has_no_http_worker_poll'});
  } else {
    assert.equal(pair.administration.length, 2);
    pair.administration.forEach((entry, index) => {
      assert.equal(typeof entry.created, 'boolean');
      assert.equal(entry.namespace.name, runs[index].namespace);
      assert.equal(entry.namespace.status, 'active');
      assert.equal(entry.namespace.retention_days, 7);
    });
    assert.equal(pair.foreign_polls.length, 4);
    assert.deepEqual(pair.foreign_polls.map(item => [item.target_run, item.after_signal]), [[0, 'signal-1'], [1, 'signal-1'], [0, 'signal-2'], [1, 'signal-2']]);
    assert.deepEqual(pair.timeline.map(item => [item.run, item.phase]), [[0, 'original'], [1, 'original'],
      [0, 'signal-1'], [0, 'resumed'], [1, 'signal-1'], [1, 'resumed'],
      [0, 'signal-2'], [0, 'replacement'], [1, 'signal-2'], [1, 'replacement']]);
    let previous = pair.pair_started_at_unix;
    for (const item of pair.timeline) {
      assert(Number.isFinite(item.at_unix) && item.at_unix >= previous && item.at_unix <= pair.run_budgets[item.run].deadline_at_unix);
      previous = item.at_unix;
    }
    for (const item of pair.foreign_polls) {
      checkSnapshots(item.before); assert.deepEqual(item.before, item.after, 'foreign Worker poll leaves both executions and histories unchanged');
      const index = item.target_run, turn = item.after_signal === 'signal-1' ? 1 : 3;
      assert.deepEqual(item.before[index], runs[index].sdk_signal_direction.phases[turn].snapshot);
      const result = item.result, workerId = ids[index] + ':foreign-poll';
      assert.equal(result.phase, 'foreign-poll'); assert.equal(result.language, 'php');
      assert.equal(result.sdk_version, runs[index].sdk_php);
      assert.equal(result.run_id, runs[index].run_id); assert.equal(result.worker_id, workerId);
      assert.equal(result.worker_finished, true); assert.equal(result.workflow_invocations, 0); assert.equal(result.activity_invocations, 0);
      assert(Number.isInteger(result.pid) && result.pid > 0); foreignPids.push(result.pid);
      for (const wire of result.requests) assert.equal(wire.namespace, runs[1 - index].namespace);
      const registration = result.requests.filter(wire => wire.method === 'POST' && wire.path === '/api/worker/register');
      assert.equal(registration.length, 1); assert.equal(registration[0].status, 201); assert.equal(registration[0].response.registered, true);
      assert.equal(registration[0].request.worker_id, workerId);
      assert.equal(registration[0].request.task_queue, runs[index].task_queue);
      assert.deepEqual(registration[0].request.supported_workflow_types, [fixture.workflow_type]);
      assert.deepEqual(registration[0].request.supported_activity_types, []);
      const polls = result.requests.filter(wire => wire.method === 'POST' && wire.path === '/api/worker/workflow-tasks/poll' && wire.status === 200);
      assert.equal(polls.length, 1); assert.equal(polls[0].request.task_queue, runs[index].task_queue);
      assert.equal(polls[0].response.task, null, 'real empty foreign Worker poll');
      assert(!result.requests.some(wire => /-tasks\/[^/]+\/(?:complete|fail|heartbeat)$/.test(wire.path)));
    }
    const authorPids = runs.flatMap(run => run.sdk_signal_direction.phases.map(phase => phase.pid));
    assert.equal(new Set([...authorPids, ...foreignPids]).size, authorPids.length + foreignPids.length, 'all actual SDK phases use distinct processes');
  }
  return {primary: projections[0], peer: projections[1], namespace_signal_pair: {
    both_original_runs_waited: true, distinct_namespace_values: true, foreign_signal_refusals: 2,
    unchanged_original_budgets: true}};
}
