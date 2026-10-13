import {readFileSync} from 'node:fs';
const artifacts = JSON.parse(readFileSync(new URL('../ServerParityProfiles/published-sdk-signals/artifacts.json', import.meta.url), 'utf8'));
const phases = ['original', 'signal-1', 'resumed', 'signal-2', 'replacement'];
const lengths = [3, 4, 8, 9, 13];
const nil = {type: 'null', value: null};
const copy = value => structuredClone(value);
// Reader guard models only; runtime observations always come from the real recorder.
function renamed(raw, suffix, pidOffset) {
  const visit = (value, key) => {
    if (key === 'phase') return value;
    if (key === 'pid') return value + pidOffset;
    if (typeof value === 'string' && key === 'path') return value.replace(/(\/task-(?:original|resumed|replacement))(\/)/g, '$1' + suffix + '$2');
    if (typeof value === 'string' && /^(?:original-signal-run|original-start-command|wait-\d+|routing-wait-\d+|signal-command-\d+|signal-\d+|task-(?:original|resumed|replacement))$/.test(value)) return value + suffix;
    if (Array.isArray(value)) return value.map(item => visit(item));
    if (value && typeof value === 'object') return Object.fromEntries(Object.entries(value).map(([name, item]) => [name, visit(item, name)]));
    return value;
  };
  return visit(raw);
}

export function observed(fixture, mode = 'http', workflowId = 'signal-original-workflow', suffix = '', pidOffset = 0) {
  const spec = fixture.sdk_signal_direction;
  const raw = {mode, workflow_id: workflowId, run_id: 'original-signal-run', workflow_type: fixture.workflow_type,
    namespace: fixture.namespace ?? 'default', task_queue: spec.task_queue, status: 'completed', payload_codec: 'avro',
    input: [fixture.input, 2], typed_input: {type: 'list', value: [fixture.typed_value, {type: 'int64', value: '2'}]},
    output: fixture.signal_value, typed_output: fixture.typed_signal_value,
    execution: {run_id: 'original-signal-run', status: 'completed', started_at: '2026-01-01T00:00:00Z',
      output_envelope: {codec: 'avro', blob: 'original-output-frame'}},
    sdk_php: artifacts.sdk_php, sdk_php_source: artifacts.sdk_php_source_commit,
    sdk_composer_lock_sha256: artifacts.sdk_php_composer_lock_sha256,
    sdk_loaded_sources: copy(artifacts.sdk_php_loaded_sources)};
  const start = {workflow_instance_id: workflowId, workflow_run_id: raw.run_id,
    workflow_command_id: 'original-start-command', workflow_type: fixture.workflow_type};
  raw.events = [
    {event_type: 'StartAccepted', payload: {...start, outcome: 'started_new'}, decoded: {}, typed_decoded: {}},
    {event_type: 'WorkflowStarted', payload: {...start, declared_signals: ['payload'],
      execution_timeout_seconds: 3600, run_timeout_seconds: 600,
      execution_deadline_at: '2026-01-01T01:00:00Z', run_deadline_at: '2026-01-01T00:10:00Z'}, decoded: {}, typed_decoded: {}},
  ];
  raw.signal_deliveries = [];
  for (let index = 0; index < 2; index++) {
    const opened = mode === 'embedded' ? {signal_wait_id: 'wait-' + index, signal_name: 'payload', sequence: index + 1}
      : {condition_wait_id: 'wait-' + index, condition_key: 'payload:' + index,
        ...(spec.workflow === 'rust' ? {condition_wait_occurrence_id: 'rust:condition-wait:' + index} : {}),
        condition_definition_fingerprint: 'sha256:' + 'a'.repeat(64), sequence: index + 1};
    const received = {workflow_instance_id: workflowId, workflow_run_id: raw.run_id,
      workflow_command_id: 'signal-command-' + index, signal_id: 'signal-' + index,
      signal_wait_id: (mode === 'embedded' ? 'wait-' : 'routing-wait-') + index,
      signal_name: 'payload', payload_codec: 'avro',
      command: {id: 'signal-command-' + index, sequence: index + 2, message_sequence: index + 1}};
    const receipt = {accepted: true, command_id: received.workflow_command_id, run_id: raw.run_id,
      workflow_id: workflowId, outcome: 'signal_received'};
    raw.signal_deliveries.push({before: {status: 'waiting', run_id: raw.run_id}, response: receipt});
    raw.events.push(
      {event_type: mode === 'embedded' ? 'SignalWaitOpened' : 'ConditionWaitOpened', payload: opened, decoded: {}, typed_decoded: {}},
      {event_type: 'SignalReceived', payload: received, decoded: {arguments: [fixture.signal_value]},
        typed_decoded: {arguments: {type: 'list', value: [fixture.typed_signal_value]}}},
      {event_type: 'MessageCursorAdvanced', payload: {stream_key: 'instance:' + workflowId,
        previous_position: index, new_position: index + 1}, decoded: {}, typed_decoded: {}},
      {event_type: 'SignalApplied', payload: {...received, ...(mode === 'embedded' ? {sequence: index + 1} : {})},
        decoded: {value: fixture.signal_value}, typed_decoded: {value: fixture.typed_signal_value}},
      ...(mode === 'embedded' ? [] : [{event_type: 'ConditionWaitSatisfied', payload: {...opened,
        workflow_signal_id: received.signal_id, signal_name: received.signal_name, signal_wait_id: received.signal_wait_id},
        decoded: {}, typed_decoded: {}}]));
  }
  raw.events.push({event_type: 'WorkflowCompleted', payload: {}, decoded: {output: raw.output}, typed_decoded: {output: raw.typed_output}});
  raw.events = raw.events.map((event, index) => ({...event, sequence: index + 1, timestamp: '2026-01-01T00:00:01Z'}));
  if (mode === 'embedded') {
    Object.assign(raw, {workflow_package: artifacts.workflow, workflow_source: artifacts.embedded_image_path_reference,
      embedded_composer_lock_sha256: artifacts.embedded_image_composer_lock_sha256,
      workflow_loaded_sources: copy(artifacts.workflow_loaded_sources),
      sdk_signal_direction: {applicable: false, reason: 'embedded_executes_php_author_definitions'}});
    return renamed(raw, suffix, pidOffset);
  }
  raw.workflow_polls = []; raw.workflow_completions = [];
  const recorded = [];
  let turn = 0;
  const request = (path, body, response, status = 200) => ({method: 'POST', path, status, request: body, response});
  for (const [index, phase] of phases.entries()) {
    const sender = index === 1 || index === 3;
    const checkpoint = {workflow_id: workflowId, run_id: raw.run_id, workflow_type: raw.workflow_type,
      namespace: raw.namespace, task_queue: raw.task_queue, payload_codec: raw.payload_codec,
      status: index === 4 ? 'completed' : 'waiting', events: copy(raw.events.slice(0, lengths[index])),
      typed_input: copy(raw.typed_input), typed_output: copy(index === 4 ? raw.typed_output : nil),
      execution: {...raw.execution, status: index === 4 ? 'completed' : 'waiting',
        output_envelope: index === 4 ? raw.execution.output_envelope : null}};
    if (sender) {
      const signalIndex = index === 1 ? 0 : 1, receipt = copy(raw.signal_deliveries[signalIndex].response);
      raw.signal_deliveries[signalIndex].before = copy(recorded[index - 1].snapshot.execution);
      const wire = request('/api/workflows/' + workflowId + '/signal/payload',
        {input: {codec: 'avro', blob: 'signal-frame-' + signalIndex}}, receipt, 202);
      wire.decoded_input = [fixture.signal_value];
      wire.typed_decoded_input = {type: 'list', value: [fixture.typed_signal_value]};
      recorded.push({phase, language: spec.client, sdk_version: artifacts['sdk_' + spec.client],
        pid: 201 + index, workflow_id: workflowId, run_id: raw.run_id, sender_finished: true,
        signal_sent: true, client_result: spec.client === 'python' ? null : copy(receipt), requests: [wire], snapshot: checkpoint});
      continue;
    }
    const workerId = workflowId + ':' + phase;
    const task = {task_id: 'task-' + phase, lease_owner: workerId, workflow_task_attempt: 1,
      workflow_id: workflowId, run_id: raw.run_id, workflow_type: raw.workflow_type, task_queue: raw.task_queue,
      history_events: copy(raw.events.slice(0, [2, 4, 9][turn]))};
    const command = turn === 2 ? {type: 'complete_workflow', result: raw.execution.output_envelope}
      : {type: 'open_condition_wait', condition_key: 'payload:' + turn,
        ...(spec.workflow === 'rust' ? {condition_wait_occurrence_id: 'rust:condition-wait:' + turn} : {}),
        condition_definition_fingerprint: raw.events[turn === 0 ? 2 : 7].payload.condition_definition_fingerprint};
    const requests = [request('/api/worker/register', {worker_id: workerId, task_queue: raw.task_queue,
      supported_workflow_types: [raw.workflow_type], supported_activity_types: []}, {registered: true}, 201)];
    if (turn === 0) requests.push(request('/api/workflows', {workflow_id: workflowId,
      workflow_type: raw.workflow_type, task_queue: raw.task_queue, input: {codec: 'avro', blob: 'input-frame'}}, {run_id: raw.run_id}, 201));
    requests.push(request('/api/worker/workflow-tasks/poll', {task_queue: raw.task_queue}, {task}));
    const completion = request('/api/worker/workflow-tasks/' + task.task_id + '/complete',
      {lease_owner: task.lease_owner, workflow_task_attempt: 1, commands: [command]},
      {recorded: true, task_id: task.task_id, run_id: raw.run_id});
    requests.push(completion);
    raw.workflow_polls.push(copy(task));
    raw.workflow_completions.push({...copy(completion), decoded_commands: [turn === 2
      ? {decoded: {result: raw.output}, typed_decoded: {result: raw.typed_output}}
      : {decoded: {}, typed_decoded: {}}]});
    recorded.push({phase, language: spec.workflow, sdk_version: artifacts['sdk_' + spec.workflow],
      pid: 201 + index, worker_finished: true, run_id: raw.run_id, worker_id: workerId,
      task_queue: raw.task_queue, workflow_invocations: 1, activity_invocations: 0, requests, snapshot: checkpoint});
    turn++;
  }
  raw.sdk_signal_direction = {applicable: true, phases: recorded,
    observer_start: {role: 'observer', language: 'php', sdk_version: artifacts.sdk_php, pid: 120, run_id: raw.run_id},
    packages: {outcome: 'pass', rust: copy(artifacts.published_sdk_artifacts.rust), python: copy(artifacts.published_sdk_artifacts.python)}};
  return renamed(raw, suffix, pidOffset);
}
