import assert from 'node:assert/strict';
import {checkChildCancellation} from './child-cancellation-contract.mjs';
import {checkActivityRetry} from './retry-contract.mjs';
import {checkTerminalActivityFailure} from './failure-contract.mjs';
import {checkImmediateCancellation} from './cancellation-contract.mjs';
import {checkCooperativeCancellation} from './cooperative-contract.mjs';
import {checkSchedule, scheduledWorkflowIdentity} from './schedule-contract.mjs';
import {checkVisibility} from './visibility-contract.mjs';

function instantNanoseconds(value) {
  const shape = typeof value === 'string' && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.(\d{1,9}))?(?:Z|[+-]\d{2}:\d{2})$/.exec(value);
  const milliseconds = Date.parse(value);
  assert.ok(shape && Number.isFinite(milliseconds), 'persisted timer timestamp');
  // Date owns calendar/offset parsing, but truncates fractional milliseconds.
  // Restore only that omitted fraction using integer arithmetic; never round
  // a microsecond-early firing into an apparently equal deadline.
  return BigInt(milliseconds) * 1_000_000n + BigInt((shape[1] ?? '').padEnd(9, '0').slice(3));
}

// Only this fixture's declared semantics are projected. The full observation
// remains in the recording, including implementation metadata and timestamps.
export function checkObservation(fixture, observation, workflowId) {
  const equal = (actual, expected, label) => assert.deepStrictEqual(actual, expected, label);
  const nonempty = (value, label) => assert.ok(typeof value === 'string' && value.length > 0, label);
  const scheduleId = fixture.schedule ? workflowId : null;
  if (fixture.schedule) workflowId = scheduledWorkflowIdentity(observation, scheduleId);
  equal(observation.workflow_id, workflowId, 'public workflow identity');
  nonempty(observation.run_id, 'run identity');
  equal(observation.workflow_type, fixture.workflow_type, 'registered workflow type');
  equal(observation.namespace, 'default', 'namespace');
  equal(observation.task_queue, 'server-parity-v1', 'task queue');
  const cancelled = Boolean(fixture.immediate_cancellation || fixture.cooperative_cancellation);
  equal(observation.status, cancelled ? 'cancelled' : 'completed', 'actual durable terminal state');
  equal(observation.payload_codec, 'avro', 'payload codec');
  const output = cancelled ? null : fixture.output ?? fixture.signal_value ?? fixture.input;
  const typedOutput = cancelled ? {type: 'null', value: null} : fixture.typed_output ?? fixture.typed_signal_value ?? fixture.typed_value;
  equal(observation.input, fixture.signal_count ? [fixture.input, fixture.signal_count] : [fixture.input], 'decoded workflow input');
  equal(observation.output, output, 'decoded workflow result');
  const typedArguments = {type: 'list', value: [fixture.typed_value, ...(fixture.signal_count ? [{type: 'int64', value: String(fixture.signal_count)}] : [])]};
  equal(observation.typed_input, typedArguments, 'decoded workflow input types and exact int64 values');
  equal(observation.typed_output, typedOutput, 'decoded workflow result types and exact int64 values');
  const events = observation.events;
  equal(events.map(event => event.event_type), observation.mode === 'embedded' && fixture.embedded_expected_events ? fixture.embedded_expected_events : fixture.expected_events, 'complete ordered event inventory');
  equal(events.map(event => event.sequence), events.map((_, index) => index + 1), 'durable history sequence');
  const accepted = events[0].payload;
  const started = events[1].payload;
  for (const payload of [accepted, started]) {
    equal(payload.workflow_instance_id, workflowId, 'history workflow relationship');
    equal(payload.workflow_run_id, observation.run_id, 'history run relationship');
    equal(payload.workflow_type, fixture.workflow_type, 'history registered type');
    nonempty(payload.workflow_command_id, 'durable start command identity');
  }
  equal(started.workflow_command_id, accepted.workflow_command_id, 'same accepted start command');
  const identities = [workflowId, observation.run_id, accepted.workflow_command_id];
  equal(new Set(identities).size, identities.length, 'distinct workflow/run/command identities');
  equal(accepted.outcome, 'started_new', 'start command outcome');
  equal(started.execution_timeout_seconds, 3600, 'execution timeout');
  equal(started.run_timeout_seconds, 600, 'run timeout');
  const startTime = Date.parse(observation.execution.started_at);
  assert.ok(Number.isFinite(startTime), 'persisted start timestamp');
  for (const [name, seconds] of [['execution_deadline_at', 3600], ['run_deadline_at', 600]]) {
    const delta = Date.parse(started[name]) - startTime;
    assert.ok(Number.isFinite(delta) && Math.abs(delta - seconds * 1000) < 1000, `${name} retains its original budget`);
  }
  let previousTime = -Infinity;
  for (const event of events) {
    const time = Date.parse(event.timestamp);
    assert.ok(Number.isFinite(time) && time >= previousTime, 'ordered recorded timestamps');
    previousTime = time;
  }
  if (fixture.cooperative_cancellation) {
    // Its shielded cleanup result is an activity outcome, never workflow success.
  } else if (fixture.immediate_cancellation) {
    checkImmediateCancellation(fixture, observation);
  } else {
    equal(events.at(-1).decoded.output, output, 'committed workflow result');
    equal(events.at(-1).typed_decoded.output, typedOutput, 'committed workflow result types');
  }
  let projectedEvents = events.map(({sequence, event_type}) => ({sequence, event_type}));
  const projectedQueries = [];
  const projectedUpdates = [];
  const projectedChildren = [];
  const schedule = fixture.schedule ? checkSchedule(fixture, observation, identities, projectedEvents, instantNanoseconds) : null;
  const visibility = fixture.visibility ? checkVisibility(fixture, observation, workflowId, instantNanoseconds) : null;
  const cooperative = fixture.cooperative_cancellation
    ? checkCooperativeCancellation(fixture, observation, identities, projectedEvents, instantNanoseconds) : null;
  if (fixture.child_cancellation) {
    const cancellation = checkChildCancellation(fixture, observation, identities, projectedEvents);
    projectedEvents = cancellation.events;
    projectedChildren.push(cancellation.child);
  }
  if (fixture.update_values) {
    equal(observation.updates.length, fixture.update_values.length, 'complete update observation inventory');
    equal(started.declared_updates, [fixture.update_name], 'original durable update declaration');
    const acceptedUpdates = events.filter(event => event.event_type === 'UpdateAccepted');
    const appliedUpdates = events.filter(event => event.event_type === 'UpdateApplied');
    const completedUpdates = events.filter(event => event.event_type === 'UpdateCompleted');
    const signalSequence = events.find(event => event.event_type === 'SignalReceived').sequence;
    for (const [index, value] of fixture.update_values.entries()) {
      const update = observation.updates[index];
      const acceptedUpdate = acceptedUpdates[index];
      const applied = appliedUpdates[index];
      const completed = completedUpdates[index];
      const cursor = events[completed.sequence];
      const updateId = acceptedUpdate.payload.update_id;
      const commandId = acceptedUpdate.payload.workflow_command_id;
      nonempty(updateId, 'durable update identity');
      nonempty(commandId, 'durable update command identity');
      identities.push(updateId, commandId);
      equal(new Set(identities).size, identities.length, 'distinct update/command/run identities');
      equal(update.name, fixture.update_name, 'declared update name');
      equal(update.request_id, `${workflowId}:update:${index}`, 'original update request ID');
      equal(update.arguments, [value], 'submitted update arguments');
      const arguments_ = {type: 'list', value: [fixture.typed_update_values[index]]};
      equal(update.typed_arguments, arguments_, 'exact submitted update argument types');
      equal(update.before.id ?? update.before.run_id, observation.run_id, 'update original waiting run');
      equal(update.before.status, 'waiting', 'update occurs during a durable wait');
      for (const receipt of [update.accepted, update.duplicate_accepted]) {
        equal(receipt.command_status ?? (receipt.accepted ? 'accepted' : 'rejected'), 'accepted', 'accepted update command');
        equal(receipt.update_status, 'accepted', 'update acknowledgment precedes execution');
        equal(receipt.command_id, commandId, 'repeat request retains its original command');
        equal(receipt.update_id, updateId, 'repeat request retains its original update');
        equal(receipt.workflow_id, workflowId, 'update acknowledgment workflow');
        equal(receipt.run_id, observation.run_id, 'update acknowledgment original run');
        equal(receipt.update_name, fixture.update_name, 'update acknowledgment name');
      }
      equal(update.history_after_duplicate, update.history_after_acceptance, 'pending duplicate has no durable effect');
      equal(update.history_after_result, update.history_before_result, 'completed duplicate has no durable effect');
      equal(update.history_after_acceptance.at(-1).payload.update_id, updateId, 'acknowledged update is durably accepted');
      equal(update.history_before_result.filter(event => event.event_type === 'UpdateCompleted').length, index + 1, 'result observes the correct completed update prefix');
      for (const event of [acceptedUpdate, applied, completed]) {
        equal(event.payload.update_id, updateId, 'original update across lifecycle');
        equal(event.payload.workflow_command_id, commandId, 'original command across update lifecycle');
        equal(event.payload.workflow_instance_id, workflowId, 'update history workflow');
        equal(event.payload.workflow_run_id, observation.run_id, 'update history run');
        equal(event.payload.update_name, fixture.update_name, 'update history declaration');
      }
      for (const event of [acceptedUpdate, applied]) {
        equal(event.decoded.arguments, [value], 'durable original update arguments');
        equal(event.typed_decoded.arguments, arguments_, 'durable exact update argument types');
      }
      assert.ok(Number.isInteger(applied.payload.sequence) && applied.payload.sequence > 0, 'update callback authoring position');
      equal(completed.payload.sequence, applied.payload.sequence, 'update completion retains callback position');
      assert.ok(acceptedUpdate.sequence < applied.sequence && applied.sequence < completed.sequence && completed.sequence < signalSequence, 'update lifecycle completes before finishing signal');
      const result = {value, applied: index + 1};
      const typedResult = {type: 'map', value: {value: fixture.typed_update_values[index], applied: {type: 'int64', value: String(index + 1)}}};
      equal(update.result, result, 'completed repeat returns the original stateful update result');
      equal(update.typed_result, typedResult, 'exact returned update result types');
      equal(completed.decoded.result, result, 'durable update result');
      equal(completed.typed_decoded.result, typedResult, 'durable exact update result types');
      equal(cursor.event_type, 'MessageCursorAdvanced', 'update advances ordered message cursor');
      equal(cursor.payload.stream_key, `instance:${workflowId}`, 'update cursor stream');
      equal(cursor.payload.previous_position, index, 'update cursor previous position');
      equal(cursor.payload.new_position, index + 1, 'update advances cursor exactly once');
      equal(acceptedUpdate.payload.command.id, commandId, 'update control command identity');
      equal(acceptedUpdate.payload.command.sequence, index + 2, 'update control command order');
      equal(acceptedUpdate.payload.command.message_sequence, index + 1, 'update ordered message position');
      if (observation.mode === 'http') {
        const worker = update.worker;
        const task = worker.task;
        nonempty(task.task_id, 'actual update task identity');
        identities.push(task.task_id);
        equal(new Set(identities).size, identities.length, 'distinct update task identities');
        equal(task.workflow_update_id, updateId, 'update task routes original update');
        equal(task.workflow_id, workflowId, 'update task original workflow');
        equal(task.run_id, observation.run_id, 'update task original run');
        equal(task.workflow_type, fixture.workflow_type, 'update task original workflow type');
        equal(task.workflow_task_attempt, 1, 'first update task attempt');
        nonempty(task.lease_owner, 'actual update task lease owner');
        equal(worker.arguments, [value], 'worker decodes original accepted arguments');
        equal(worker.typed_arguments, arguments_, 'worker exact accepted argument types');
        equal(task.history_events.filter(event => event.event_type === 'UpdateApplied').length, index, 'worker sees prior committed updates');
      }
      projectedUpdates.push({name: update.name, request_id: update.request_id,
        arguments: arguments_, result: typedResult, message_sequence: index + 1});
    }
  }
  if (fixture.queries) {
    equal(observation.queries.length, fixture.queries.length, 'complete query observation inventory');
    equal(started.declared_queries, [fixture.query_name], 'durable query declaration');
    for (const [index, expected] of fixture.queries.entries()) {
      const query = observation.queries[index];
      equal(query.name, fixture.query_name, 'declared query name');
      equal(query.arguments, fixture.query_arguments, 'decoded query arguments');
      equal(query.typed_arguments, fixture.typed_query_arguments, 'exact query argument types');
      for (const execution of [query.before, query.after]) {
        equal(execution.id ?? execution.run_id, observation.run_id, 'query original run');
        equal(execution.status, expected.status, 'query does not change run status');
      }
      equal(query.history_after, query.history_before, 'query leaves durable history unchanged');
      const applied = query.history_before.filter(event => event.event_type === 'SignalApplied');
      equal(applied.length, expected.after_signal_count, 'query observes the intended committed signal state');
      const prefix = events.slice(0, query.history_before.length).map(({sequence, event_type, payload}) => ({sequence, event_type, payload}));
      equal(query.history_before.map(({sequence, event_type, payload}) => ({sequence, event_type, payload})), prefix, 'query observes an original history prefix');
      const result = {request: fixture.query_arguments[0], delivered: expected.after_signal_count,
        last: expected.after_signal_count ? fixture.signal_value : null};
      const typedResult = {type: 'map', value: {request: fixture.typed_query_arguments.value[0],
        delivered: {type: 'int64', value: String(expected.after_signal_count)},
        last: expected.after_signal_count ? fixture.typed_signal_value : {type: 'null', value: null}}};
      equal(query.result, result, 'query result comes from real state and request');
      equal(query.typed_result, typedResult, 'exact query result types');
      if (observation.mode === 'http') {
        const task = query.worker.task;
        nonempty(task.query_task_id, 'actual routed query task');
        identities.push(task.query_task_id);
        equal(new Set(identities).size, identities.length, 'distinct query task identities');
        equal(task.workflow_id, workflowId, 'query task original workflow');
        equal(task.run_id, observation.run_id, 'query task original run');
        equal(task.workflow_type, fixture.workflow_type, 'query task workflow type');
        equal(task.query_name, fixture.query_name, 'query task declared name');
        equal(task.query_task_attempt, 1, 'first query task attempt');
        nonempty(task.lease_owner, 'query task lease owner');
        equal(task.query_arguments.codec, 'avro', 'query task argument codec');
        equal(query.worker.arguments, fixture.query_arguments, 'worker decoded actual query arguments');
        equal(query.worker.typed_arguments, fixture.typed_query_arguments, 'worker exact query argument types');
        equal(task.history_events.filter(event => event.event_type === 'SignalApplied').length, expected.after_signal_count, 'worker receives the intended committed history');
      }
      projectedQueries.push({name: query.name, status: expected.status, after_signal_count: expected.after_signal_count,
        arguments: query.typed_arguments, result: query.typed_result});
    }
  }
  if (fixture.signal_count) {
    assert.ok(['http', 'embedded'].includes(observation.mode), 'explicit signal authoring adapter');
    equal(observation.signal_deliveries.length, fixture.signal_count, 'complete delivered signal inventory');
    equal(started.declared_signals, ['payload'], 'durable signal declaration');
    let offset = 2;
    const signalEvents = fixture.update_values ? events.filter(event =>
      !['UpdateAccepted', 'UpdateApplied', 'UpdateCompleted'].includes(event.event_type)
      && !(event.event_type === 'MessageCursorAdvanced' && event.payload.new_position <= fixture.update_values.length)) : events;
    const updateCount = fixture.update_values?.length ?? 0;
    const commonEvents = projectedEvents.slice(0, 2);
    for (let index = 0; index < fixture.signal_count; index++) {
      const opened = signalEvents[offset++];
      const received = signalEvents[offset++];
      const cursor = signalEvents[offset++];
      const applied = signalEvents[offset++];
      const embedded = observation.mode === 'embedded';
      const satisfied = embedded ? applied : signalEvents[offset++];
      const waitId = opened.payload[embedded ? 'signal_wait_id' : 'condition_wait_id'];
      const signalId = received.payload.signal_id;
      const commandId = received.payload.workflow_command_id;
      for (const [value, label] of [[waitId, 'wait'], [signalId, 'signal'], [commandId, 'signal command']]) {
        nonempty(value, `durable ${label} identity`);
        identities.push(value);
      }
      equal(new Set(identities).size, identities.length, 'distinct wait/signal/command identities');
      const delivery = observation.signal_deliveries[index];
      equal(delivery.before.status, 'waiting', 'signal delivered only after a durable wait');
      equal(delivery.before.id ?? delivery.before.run_id, observation.run_id, 'waiting original run');
      equal(delivery.response.command_status ?? (delivery.response.accepted ? 'accepted' : 'rejected'), 'accepted', 'accepted signal command');
      equal(delivery.response.command_id, commandId, 'acknowledged signal command retained in history');
      equal(delivery.response.run_id, observation.run_id, 'acknowledged original run');
      equal(delivery.response.workflow_id, workflowId, 'acknowledged workflow');
      equal(delivery.response.outcome, 'signal_received', 'signal command outcome');
      equal(received.payload.workflow_instance_id, workflowId, 'signal workflow relationship');
      equal(received.payload.workflow_run_id, observation.run_id, 'signal run relationship');
      equal(received.payload.signal_name, 'payload', 'signal name');
      equal(received.payload.payload_codec, 'avro', 'signal argument codec');
      equal(received.decoded.arguments, [fixture.signal_value], 'decoded signal arguments');
      equal(received.typed_decoded.arguments, {type: 'list', value: [fixture.typed_signal_value]}, 'exact signal argument types');
      equal(opened.payload.sequence, index + 1, 'deterministic wait command sequence');
      if (embedded) {
        equal(opened.payload.signal_name, 'payload', 'direct signal wait name');
        equal(received.payload.signal_wait_id, waitId, 'received signal routes to original direct wait');
        equal(applied.payload.sequence, opened.payload.sequence, 'direct signal wait resolution sequence');
      } else {
        equal(opened.payload.condition_key, `payload:${index}`, 'deterministic wait key');
        assert.match(opened.payload.condition_definition_fingerprint, /^sha256:[a-f0-9]{64}$/, 'recorded condition fingerprint');
        for (const key of ['condition_wait_id', 'condition_key', 'condition_definition_fingerprint', 'sequence']) {
          equal(satisfied.payload[key], opened.payload[key], `condition resolution retains ${key}`);
        }
        equal(satisfied.payload.workflow_signal_id, signalId, 'condition resolved by accepted signal');
      }
      equal(satisfied.payload.signal_name, 'payload', 'condition resolution signal name');
      equal(satisfied.payload.signal_wait_id, received.payload.signal_wait_id, 'condition resolution signal wait relationship');
      nonempty(received.payload.signal_wait_id, 'signal routing wait identity');
      equal(cursor.payload.stream_key, `instance:${workflowId}`, 'message cursor stream');
      equal(cursor.payload.previous_position, index + updateCount, 'message cursor previous position');
      equal(cursor.payload.new_position, index + updateCount + 1, 'message cursor advances once per signal');
      equal(received.payload.command.id, commandId, 'recorded signal command identity');
      equal(received.payload.command.sequence, index + updateCount + 2, 'control command sequence is separate from authored wait sequence');
      equal(received.payload.command.message_sequence, index + updateCount + 1, 'ordered signal message sequence');
      for (const key of ['workflow_command_id', 'signal_id', 'signal_name', 'signal_wait_id']) {
        equal(applied.payload[key], received.payload[key], `applied signal retains ${key}`);
      }
      equal(applied.decoded.value, fixture.signal_value, 'applied signal value');
      equal(applied.typed_decoded.value, fixture.typed_signal_value, 'applied signal value types');
      commonEvents.push(
        {event_type: 'PayloadWaitOpened', wait_id: `@wait:${index + 1}`, command_sequence: index + 1},
        {event_type: 'SignalReceived', signal_id: `@signal:${index + 1}`, command_id: `@signal-command:${index + 1}`, signal_name: 'payload', arguments: {type: 'list', value: [fixture.typed_signal_value]}},
        {event_type: 'MessageCursorAdvanced', previous_position: index + updateCount, new_position: index + updateCount + 1},
        {event_type: 'SignalApplied', signal_id: `@signal:${index + 1}`, command_id: `@signal-command:${index + 1}`, value: fixture.typed_signal_value},
        {event_type: 'PayloadWaitResolved', wait_id: `@wait:${index + 1}`, signal_id: `@signal:${index + 1}`, command_sequence: index + 1},
      );
    }
    commonEvents.push({event_type: 'WorkflowCompleted'});
    projectedEvents.splice(0, projectedEvents.length, ...commonEvents.map((event, index) => ({...event, sequence: index + 1})));
  }
  if (fixture.timer_delays) {
    for (const [index, delay] of fixture.timer_delays.entries()) {
      const offset = 2 + index * 2;
      const [scheduled, fired] = events.slice(offset, offset + 2);
      const timerId = scheduled.payload.timer_id;
      nonempty(timerId, 'durable timer identity');
      identities.push(timerId);
      equal(new Set(identities).size, identities.length, 'distinct timer and run/command identities');
      const fireAt = instantNanoseconds(scheduled.payload.fire_at);
      const scheduledAt = Date.parse(scheduled.timestamp);
      // Published history timestamps can lose fractional seconds. Preserve the
      // exact payload deadline and allow only that known recorder precision.
      assert.ok(Math.abs(Number(fireAt / 1_000_000n) - scheduledAt - delay * 1000) < 1000, 'timer retains requested delay');
      // Embedded PHP's immediate TimerFired omits fire_at. The zero-delay
      // fixture explicitly permits that event shape; positive delays always
      // require the repeated deadline. Both retain scheduled authority and
      // must fire at or after its exact timestamp.
      if (!(delay === 0 && fixture.zero_delay_fired_fire_at_optional && fired.payload.fire_at === undefined)) {
        equal(fired.payload.fire_at, scheduled.payload.fire_at, 'firing retains original deadline');
      }
      const firedAt = instantNanoseconds(fired.payload.fired_at);
      assert.ok(firedAt >= fireAt, 'timer must not fire early');
      for (const [relative, event] of [scheduled, fired].entries()) {
        equal(event.payload.timer_id, timerId, 'same timer throughout history');
        equal(event.payload.sequence, index + 1, 'deterministic timer command sequence');
        equal(event.payload.delay_seconds, delay, 'persisted timer delay');
        Object.assign(projectedEvents[offset + relative], {timer_id: `@timer:${index + 1}`, command_sequence: index + 1, delay_seconds: delay});
      }
    }
  }
  if (fixture.child_count) {
    equal(observation.children.length, fixture.child_count, 'complete original child inventory');
    for (const [index, child] of observation.children.entries()) {
      const offset = 2 + index * 3;
      const [scheduled, childStarted, resolved] = events.slice(offset, offset + 3);
      const input = fixture.input.payloads[index];
      const typedInput = {type: 'list', value: [fixture.typed_value.value.payloads.value[index]]};
      const output = fixture.output.child_results[index];
      const typedOutput = fixture.typed_output.value.child_results.value[index];
      const callId = scheduled.payload.child_call_id;
      for (const key of ['child_call_id', 'workflow_link_id', 'child_workflow_instance_id', 'child_workflow_run_id']) {
        nonempty(scheduled.payload[key], `original child ${key}`);
        equal(childStarted.payload[key], scheduled.payload[key], `child start preserves ${key}`);
        equal(resolved.payload[key], scheduled.payload[key], `child resolution preserves ${key}`);
      }
      equal(scheduled.payload.workflow_link_id, callId, 'child call is its original link');
      equal(child.workflow_id, scheduled.payload.child_workflow_instance_id, 'observed original child instance');
      equal(child.run_id, scheduled.payload.child_workflow_run_id, 'observed original child run');
      identities.push(callId, child.workflow_id, child.run_id);
      equal(new Set(identities).size, identities.length, 'distinct child/call/run and parent identities');
      for (const event of [scheduled, childStarted, resolved]) {
        equal(event.payload.sequence, index + 1, 'authored child call sequence');
        equal(event.payload.child_workflow_type, fixture.child_workflow_type, 'original registered child type');
      }
      for (const event of [scheduled, childStarted]) {
        equal(event.payload.parent_close_policy, 'abandon', 'original default parent-close policy');
        equal(event.payload.cancellation_policy, 'abandon', 'original default child cancellation policy');
      }
      equal(childStarted.payload.child_run_number, 1, 'original child run number');
      equal(resolved.payload.child_run_number, 1, 'resolution of original child run number');
      equal(resolved.payload.child_status, 'completed', 'actual child terminal resolution');
      equal(instantNanoseconds(resolved.payload.closed_at), instantNanoseconds(child.execution.closed_at), 'parent retains original child close timestamp');
      equal(resolved.decoded.result, output, 'parent receives committed child result');
      equal(resolved.typed_decoded.result, typedOutput, 'parent receives exact child result types');
      equal(resolved.decoded.output, output, 'parent resolution retains committed child output');
      equal(resolved.typed_decoded.output, typedOutput, 'parent resolution exact output types');
      equal(child.workflow_type, fixture.child_workflow_type, 'observed registered child type');
      equal(child.namespace, observation.namespace, 'child inherits parent namespace');
      equal(child.task_queue, observation.task_queue, 'child uses original requested queue');
      equal(child.status, 'completed', 'real child completion');
      equal(child.payload_codec, 'avro', 'child Avro codec');
      equal(child.input, [input], 'original child arguments');
      equal(child.typed_input, typedInput, 'exact original child argument types');
      equal(child.output, output, 'actual child output');
      equal(child.typed_output, typedOutput, 'exact child output types');
      const childEvents = child.events;
      const inventory = observation.mode === 'embedded' ? fixture.embedded_child_expected_events : fixture.child_expected_events;
      equal(childEvents.map(event => event.event_type), inventory, 'complete original child event inventory');
      equal(childEvents.map(event => event.sequence), childEvents.map((_, position) => position + 1), 'original child history sequence');
      let previous = -Infinity;
      for (const event of childEvents) {
        const time = Date.parse(event.timestamp);
        assert.ok(Number.isFinite(time) && time >= previous, 'ordered original child timestamps');
        previous = time;
      }
      const childStart = childEvents.find(event => event.event_type === 'WorkflowStarted');
      for (const [key, value] of Object.entries({workflow_instance_id: child.workflow_id, workflow_run_id: child.run_id,
        workflow_type: fixture.child_workflow_type, parent_workflow_instance_id: workflowId,
        parent_workflow_run_id: observation.run_id, parent_sequence: index + 1,
        workflow_link_id: callId, child_call_id: callId})) {
        equal(childStart.payload[key], value, `child history original ${key}`);
      }
      if (observation.mode === 'embedded') {
        const accepted = childEvents[0].payload;
        nonempty(accepted.workflow_command_id, 'embedded child durable start command');
        identities.push(accepted.workflow_command_id);
        equal(new Set(identities).size, identities.length, 'distinct embedded child start command');
        equal(childStart.payload.workflow_command_id, accepted.workflow_command_id, 'embedded child original accepted command');
        equal(accepted.workflow_instance_id, child.workflow_id, 'embedded child accepted instance');
        equal(accepted.workflow_run_id, child.run_id, 'embedded child accepted run');
      }
      const activityEvents = childEvents.slice(observation.mode === 'embedded' ? 2 : 1, observation.mode === 'embedded' ? 5 : 4);
      const activityId = activityEvents[0].payload.activity_execution_id;
      const attemptId = activityEvents[1].payload.activity_attempt_id;
      nonempty(activityId, 'child activity identity');
      nonempty(attemptId, 'child activity attempt identity');
      identities.push(activityId, attemptId);
      equal(new Set(identities).size, identities.length, 'distinct nested activity and attempt identities');
      for (const event of activityEvents) {
        equal(event.payload.activity_execution_id, activityId, 'child activity original execution');
        equal(event.payload.activity_type, 'parity.v1.echo_activity', 'child registered activity type');
        equal(event.payload.sequence, 1, 'child activity authored sequence');
      }
      for (const event of activityEvents.slice(0, 2)) {
        equal(event.decoded.activity_arguments, [input], 'child activity original arguments');
        equal(event.typed_decoded.activity_arguments, typedInput, 'child activity exact argument types');
      }
      for (const event of activityEvents.slice(1)) {
        equal(event.payload.activity_attempt_id, attemptId, 'child activity original committed attempt');
        equal(event.payload.attempt_number, 1, 'one nested activity attempt');
      }
      equal(activityEvents[2].decoded.result, input, 'nested activity committed result');
      equal(activityEvents[2].typed_decoded.result, typedInput.value[0], 'nested activity exact committed result types');
      equal(childEvents.at(-1).decoded.output, output, 'committed original child output');
      equal(childEvents.at(-1).typed_decoded.output, typedOutput, 'committed exact child output types');
      for (const event of [scheduled, childStarted, resolved]) {
        Object.assign(projectedEvents[event.sequence - 1], {call_id: `@child-call:${index + 1}`,
          child_workflow_id: `@child:${index + 1}`, child_run_id: `@child-run:${index + 1}`, command_sequence: index + 1});
      }
      projectedChildren.push({workflow_type: child.workflow_type, namespace: child.namespace, task_queue: child.task_queue,
        status: child.status, input: typedInput, output: typedOutput, parent_sequence: index + 1,
        parent_close_policy: 'abandon', cancellation_policy: 'abandon', activity_type: 'parity.v1.echo_activity', attempt_number: 1});
    }
    if (observation.mode === 'http') {
      equal(observation.workflow_polls.length, 7, 'actual parent/child workflow turns');
      equal(observation.workflow_completions.length, 7, 'actual committed parent/child workflow turns');
      for (const task of observation.workflow_polls) {
        nonempty(task.task_id, 'actual child-family task identity');
        nonempty(task.lease_owner, 'actual child-family lease owner');
        identities.push(task.task_id);
        equal(new Set(identities).size, identities.length, 'distinct child-family task identities');
        equal(task.workflow_task_attempt, 1, 'child-family initial task attempt');
        const childIndex = observation.children.findIndex(child => child.workflow_id === task.workflow_id);
        const owner = childIndex === -1 ? observation : observation.children[childIndex];
        equal(task.workflow_id, owner.workflow_id, 'actual task original workflow');
        equal(task.run_id, owner.run_id, 'actual task original run');
        equal(task.workflow_type, owner.workflow_type, 'actual task original type');
        const completion = observation.workflow_completions.find(item => item.response.task_id === task.task_id);
        assert.ok(completion, 'original leased task completes through published SDK');
        equal(completion.path, `/api/worker/workflow-tasks/${task.task_id}/complete`, 'completion original task path');
        equal(completion.request.lease_owner, task.lease_owner, 'completion original lease owner');
        equal(completion.request.workflow_task_attempt, task.workflow_task_attempt, 'completion original attempt');
        equal(completion.response.run_id, task.run_id, 'completion original run');
        equal(completion.response.recorded, true, 'workflow turn commits once');
        equal(completion.request.commands.length, 1, 'one child-family command per committed turn');
      }
      const parentTasks = observation.workflow_polls.filter(task => task.workflow_id === workflowId);
      equal(parentTasks.length, 3, 'parent initial turn and two real resumptions');
      for (const [index, task] of parentTasks.slice(1).entries()) {
        const child = observation.children[index];
        const callId = events[2 + index * 3].payload.child_call_id;
        equal(task.child_call_id, callId, 'parent resumes original child call');
        equal(task.child_workflow_run_id, child.run_id, 'parent resumes original child run');
        equal(task.resume_source_kind, 'child_workflow_run', 'child terminal resume source');
        equal(task.resume_source_id, child.run_id, 'child terminal resume identity');
        equal(task.workflow_event_type, 'ChildRunCompleted', 'child terminal resume event');
        equal(task.workflow_sequence, index + 1, 'parent resumes original authored position');
        equal(task.open_wait_id, `child:${callId}`, 'parent original child wait identity');
      }
      for (const owner of [observation, ...observation.children]) {
        const parent = owner.workflow_id === workflowId;
        const childIndex = observation.children.indexOf(owner);
        const tasks = observation.workflow_polls.filter(task => task.workflow_id === owner.workflow_id);
        const frame = value => {
          if (typeof value === 'string') return value;
          equal(value?.codec, 'avro', 'original SDK Avro frame codec');
          nonempty(value?.blob, 'original SDK Avro frame bytes');
          return value.blob;
        };
        equal(tasks.length, parent ? 3 : 2, 'complete original workflow turn inventory');
        for (const [turn, task] of tasks.entries()) {
          const prefix = parent ? 2 + turn * 3 : 1 + turn * 3;
          const history = events => events.map(({sequence, event_type, payload}) => ({sequence, event_type, payload}));
          equal(history(task.history_events), history(owner.events.slice(0, prefix)), 'actual worker original committed history prefix');
          const completion = observation.workflow_completions.find(item => item.response.task_id === task.task_id);
          const command = completion.request.commands[0];
          const decoded = completion.decoded_commands[0];
          const complete = turn === tasks.length - 1;
          equal(command.type, complete ? 'complete_workflow' : parent ? 'start_child_workflow' : 'schedule_activity', 'actual SDK command for original workflow turn');
          if (complete) {
            equal(frame(command.result), frame(owner.execution.output_envelope), 'SDK result frame is durably preserved');
            equal(decoded.decoded.result, owner.output, 'SDK commits original workflow result');
            equal(decoded.typed_decoded.result, owner.typed_output, 'SDK commits exact original workflow result types');
          } else {
            const index = parent ? turn : childIndex;
            const stored = parent ? observation.children[index].execution.input_envelope
              : owner.events[1].payload.activity.arguments;
            equal(frame(command.arguments), frame(stored), 'SDK child/activity argument frame is durably preserved');
            equal(decoded.decoded.arguments, [fixture.input.payloads[index]], 'SDK schedules original child/activity arguments');
            equal(decoded.typed_decoded.arguments, {type: 'list', value: [fixture.typed_value.value.payloads.value[index]]}, 'SDK schedules exact child/activity argument types');
            equal(parent ? command.workflow_type : command.activity_type, parent ? fixture.child_workflow_type : 'parity.v1.echo_activity', 'SDK schedules original registered child/activity type');
          }
        }
      }
    }
  }
  if (fixture.terminal_activity_failure) checkTerminalActivityFailure(fixture, observation, identities, projectedEvents, instantNanoseconds);
  else if (fixture.retry_policy) checkActivityRetry(fixture, observation, identities, projectedEvents, instantNanoseconds);
  if (fixture.activity) {
    const [scheduled, running, completed] = events.slice(2, 5);
    const id = scheduled.payload.activity_execution_id;
    const attempt = running.payload.activity_attempt_id;
    nonempty(id, 'activity identity');
    nonempty(attempt, 'activity attempt identity');
    assert.notEqual(id, attempt, 'distinct activity and attempt identities');
    identities.push(id, attempt);
    equal(new Set(identities).size, identities.length, 'bijective generated identity aliases');
    for (const [index, event] of [scheduled, running, completed].entries()) {
      equal(event.payload.activity_execution_id, id, 'same activity throughout history');
      equal(event.payload.activity_type, 'parity.v1.echo_activity', 'registered activity type');
      equal(event.payload.sequence, 1, 'deterministic activity command sequence');
      Object.assign(projectedEvents[index + 2], {activity_execution_id: '@activity:1', command_sequence: 1, activity_type: event.payload.activity_type});
    }
    equal(scheduled.decoded.activity_arguments, [fixture.input], 'scheduled activity arguments');
    equal(running.decoded.activity_arguments, [fixture.input], 'started activity arguments');
    equal(scheduled.typed_decoded.activity_arguments, typedArguments, 'scheduled activity argument types');
    equal(running.typed_decoded.activity_arguments, typedArguments, 'started activity argument types');
    for (const [index, event] of [running, completed].entries()) {
      equal(event.payload.activity_attempt_id, attempt, 'same committed attempt');
      equal(event.payload.attempt_number, 1, 'one attempt');
      Object.assign(projectedEvents[index + 3], {activity_attempt_id: '@attempt:1', attempt_number: 1});
    }
    equal(completed.decoded.result, fixture.input, 'committed activity result');
    equal(completed.typed_decoded.result, fixture.typed_value, 'committed activity result types');
  }
  return {
    fixture_id: fixture.id, workflow_id: scheduleId ?? workflowId, run_id: '@run:1', start_command_id: '@command:1',
    workflow_type: observation.workflow_type, namespace: observation.namespace,
    task_queue: observation.task_queue, status: observation.status, payload_codec: observation.payload_codec,
    input: observation.typed_input, output: observation.typed_output, execution_timeout_seconds: 3600,
    run_timeout_seconds: 600, events: projectedEvents, ...(fixture.queries ? {queries: projectedQueries} : {}),
    ...(fixture.update_values ? {updates: projectedUpdates} : {}),
    ...(fixture.child_count || fixture.child_cancellation ? {children: projectedChildren} : {}),
    ...(cooperative ? {cooperative} : {}),
    ...(schedule ? {schedule} : {}),
    ...(visibility ? {visibility} : {}),
  };
}

export function compareRecords(records, expectedFixtureHashes, expectedFixtures) {
  assert.ok(records.length >= 2, 'at least two recordings are required');
  const reference = records[0];
  for (const record of records) {
    assert.equal(record.schema, 'durable-workflow.server-parity-record/v1');
    assert.equal(record.outcome, 'pass', `${record.target} recording must have passed contract assertions`);
    assert.deepStrictEqual(record.fixture_hashes, expectedFixtureHashes, 'recording covers the current reviewed fixture corpus');
    assert.ok(record.cases.length > 0, 'recorded fixture inventory must not be empty');
    assert.equal(new Set(record.cases.map(c => c.fixture_id)).size, record.cases.length, 'each fixture is recorded exactly once');
    assert.deepStrictEqual(record.cases.map(c => `${c.fixture_id}.json`).sort(), Object.keys(record.fixture_hashes).sort(), 'every hashed fixture has a recorded case');
    assert.deepStrictEqual(record.fixture_hashes, reference.fixture_hashes, 'same reviewed fixture bytes');
    assert.deepStrictEqual(record.artifacts, reference.artifacts, 'same consumer tuple');
    assert.equal(record.runner_revision, reference.runner_revision, 'same runner revision');
    assert.deepStrictEqual(record.cases.map(c => c.fixture_id), reference.cases.map(c => c.fixture_id), 'complete same fixture inventory');
    // Recheck raw observations. A saved pass label or edited projection is not
    // authority, and a missing or corrupted record must never compare green.
    for (const [index, item] of record.cases.entries()) {
      const baseline = reference.cases[index];
      assert.equal(item.fixture_id, item.fixture.id, 'case identity matches fixture');
      assert.deepStrictEqual(item.fixture, expectedFixtures[`${item.fixture_id}.json`], 'case expectations match the current reviewed source fixture');
      if (item.fixture.signal_count || item.fixture.schedule || item.fixture.visibility) assert.equal(item.observation.mode, record.mode, 'observation uses its recording adapter');
      assert.equal(item.observation.sdk_php, record.artifacts.sdk_php, 'installed published SDK matches tuple');
      if (record.mode === 'embedded') assert.equal(item.observation.workflow_package, record.artifacts.workflow, 'installed Workflow matches tuple');
      assert.deepStrictEqual(item.fixture, baseline.fixture, 'same fixture expectations');
      const checked = checkObservation(item.fixture, item.observation, item.projection.workflow_id);
      assert.deepStrictEqual(item.projection, checked, 'projection matches raw observation');
      assert.deepStrictEqual(checked, baseline.projection, `${record.target}: ${item.fixture_id}`);
    }
  }
}
