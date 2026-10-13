import assert from 'node:assert/strict';
import {gunzipSync, inflateSync, brotliDecompressSync} from 'node:zlib';

const sha = /^[a-f0-9]{64}$/;
const commit = /^[a-f0-9]{40}$/;
const timestamp = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/;
const typed = value => value === null ? {type: 'null', value: null}
  : typeof value === 'boolean' ? {type: 'boolean', value}
  : {type: 'int64', value: String(value)};

export function checkCorrectedMarkerArtifacts(fixture, artifacts) {
  if (!fixture.corrected_markers) return;
  assert.equal(artifacts.schema, 'durable-workflow.server-port-artifacts/v1');
  assert.match(artifacts.server.source_commit, commit);
  assert.match(artifacts.server.images['linux/amd64'], /@sha256:[a-f0-9]{64}$/);
  assert.match(artifacts.sdk_php_source_commit, commit);
  assert.match(artifacts.sdk_composer_lock_sha256, sha);
  assert.equal(artifacts.published_sdk_artifacts.php.version, artifacts.sdk_php);
  assert.equal(artifacts.published_sdk_artifacts.php.source_commit, artifacts.sdk_php_source_commit);
  assert.deepEqual(Object.keys(artifacts.published_sdk_artifacts.php.loaded_sources).sort(),
    ['src/Client.php', 'src/Worker.php', 'src/Worker/WorkflowContext.php']);
  for (const hash of Object.values(artifacts.published_sdk_artifacts.php.loaded_sources)) assert.match(hash, sha);
  const rust = artifacts.published_sdk_artifacts.rust;
  assert.equal(rust.version, artifacts.sdk_rust);
  assert.match(rust.source_commit, commit);
  assert.match(rust.archive_sha256, sha);
  assert.match(artifacts.rust_consumer_cargo_lock_sha256, sha);
  const spec = fixture.corrected_markers;
  assert.equal(spec.phase_budget_seconds, 30);
  assert.equal(spec.overall_budget_seconds, 90);
  assert(['php', 'rust', 'python'].includes(spec.producer));
  assert(['empty', 'php_full'].includes(spec.retry_policy));
  for (const marker of spec.markers) {
    for (const name of ['sequence', 'version', 'min_supported', 'max_supported']) assert(Number.isSafeInteger(marker[name]));
    assert(marker.min_supported <= marker.version && marker.version <= marker.max_supported);
  }
}

export function checkCorrectedMarkerPackages(fixture, observation, artifacts) {
  if (!fixture.corrected_markers) return;
  checkCorrectedMarkerArtifacts(fixture, artifacts);
  assert.equal(observation.sdk_php_source, artifacts.sdk_php_source_commit);
  assert.equal(observation.sdk_composer_lock_sha256, artifacts.sdk_composer_lock_sha256);
  assert.equal(observation.rust_consumer_cargo_lock_sha256, artifacts.rust_consumer_cargo_lock_sha256);
  assert.deepEqual(observation.sdk_loaded_sources, artifacts.published_sdk_artifacts.php.loaded_sources);
  for (const [name, phase] of Object.entries(observation.corrected_markers.phases)) {
    const producer = name === 'original' ? fixture.corrected_markers.producer : 'rust';
    const expected = producer === 'php' ? artifacts.sdk_php : producer === 'python'
      ? artifacts.historical_python_producer.version : `durable-workflow-rust/${artifacts.sdk_rust}`;
    assert.equal(phase.actor.sdk_version, expected);
  }
}

function wireRequests(fixture, observation, workflowId) {
  return observation.corrected_markers.requests.map(wire => {
    assert.equal(wire.transport_error, null);
    if (wire.client_cancelled) {
      const spec = fixture.corrected_markers;
      assert.equal(spec.producer, 'python');
      assert.equal(spec.original_shutdown, 'SIGKILL');
      assert.equal(observation.corrected_markers.phases.original.actor.author_exit_code, -9);
      assert.deepEqual(spec.cancelled_original_polls, ['workflow', 'activity', 'query']);
      assert.equal(wire.method, 'POST');
      assert(spec.cancelled_original_polls.some(kind => wire.path === `/api/worker/${kind}-tasks/poll`),
        'only killed original no-result polls can be cancelled');
      assert.equal(wire.status, 0);
      assert.equal(wire.response_body, '');
      assert.equal(wire.response_bytes_base64, '');
      assert.equal(wire.request_body_complete, true);
      assert.equal(wire.upstream_request_started, true, 'actual forwarded original poll');
      const request = JSON.parse(wire.request_body);
      assert.equal(request.worker_id, `${workflowId}:workflow:original`);
      assert.equal(request.task_queue, `${workflowId}-workflow`);
      assert.equal(request.timeout_seconds, 1);
      return {...wire, request, response: null};
    }
    const decoders = {identity: value => value, gzip: gunzipSync, deflate: inflateSync, br: brotliDecompressSync};
    assert(decoders[wire.response_encoding], 'known actual response encoding');
    assert.equal(decoders[wire.response_encoding](Buffer.from(wire.response_bytes_base64, 'base64')).toString('utf8'),
      wire.response_body, 'whole actual response bytes agree with decoded body');
    return {...wire, request: wire.request_body ? JSON.parse(wire.request_body) : null,
      response: wire.response_body ? JSON.parse(wire.response_body) : null};
  });
}

export function checkCorrectedMarkers(fixture, observation, workflowId) {
  const spec = fixture.corrected_markers;
  const {phases} = observation.corrected_markers;
  assert.equal(observation.workflow_id, workflowId);
  assert.equal(observation.workflow_type, fixture.workflow_type);
  assert.equal(observation.namespace, 'default');
  assert.equal(observation.task_queue, `${workflowId}-workflow`);
  assert.equal(observation.status, 'completed');
  assert.equal(observation.payload_codec, 'avro');
  assert.equal(observation.corrected_markers.overall_budget_seconds, spec.overall_budget_seconds);
  assert.equal(observation.corrected_markers.phase_budget_seconds, spec.phase_budget_seconds);
  assert.deepEqual(observation.typed_input, {type: 'list', value: [fixture.typed_value]});
  const output = {type: 'map', value: {
    projection: {type: 'list', value: spec.expected_decisions.map(typed)}, typed: fixture.typed_value,
  }};
  assert.deepEqual(observation.typed_output, output, 'exact public decoded result types');
  assert.deepEqual(Object.keys(phases), ['original', 'activity', 'replacement']);
  assert.equal(new Set(Object.values(phases).map(phase => phase.actor.pid)).size, 3, 'three independent public worker processes');
  for (const [name, phase] of Object.entries(phases)) {
    assert(Number.isSafeInteger(phase.actor.pid) && phase.actor.pid > 0);
    assert.equal(phase.actor.workflow_id, workflowId);
    assert.equal(phase.actor.run_id, observation.run_id);
    assert.equal(phase.actor.mode, name === 'activity' ? 'activity' : 'workflow');
    assert.equal(phase.actor.case, spec.case);
    assert.equal(phase.actor.retry_policy, spec.retry_policy);
    assert.equal(phase.actor.range_profile, name === 'replacement' ? 'wider' : 'original');
    if (name !== 'activity') assert.deepEqual(phase.actor.decisions,
      [name === 'original' ? spec.original_expected_decisions ?? spec.expected_decisions : spec.expected_decisions]);
    else assert.deepEqual(phase.actor.decisions, []);
    if (phase.actor.outcome) {
      assert.deepEqual(phase.actor.outcome, {Ok: 1});
      assert.deepEqual(phase.actor.deregistered, {Ok: true});
    } else assert.equal(phase.actor.worker_finished, true);
    assert.deepEqual(observation.events.slice(0, phase.events.length), phase.events, 'entire phase prefix and microclocks immutable');
    for (const key of ['execution_deadline_at', 'run_deadline_at']) {
      assert.equal(phase.execution[key], phases.original.execution[key], 'original durable deadline unchanged');
    }
  }
  assert.equal(phases.original.status, 'waiting');
  assert.equal(phases.activity.status, 'waiting');
  assert.equal(phases.replacement.status, 'completed');
  if (spec.producer === 'python') {
    assert.equal(phases.original.actor.author_exit_code, -9, 'actual historical author SIGKILL');
    assert.notEqual(phases.original.actor.pid, phases.original.actor.observer_pid);
  }
  const expectedTypes = ['StartAccepted', 'WorkflowStarted', ...spec.markers.map(() => 'VersionMarkerRecorded'),
    'ActivityScheduled', 'ActivityStarted', 'ActivityCompleted', 'WorkflowCompleted'];
  assert.deepEqual(observation.events.map(event => event.event_type), expectedTypes, 'complete event inventory');
  assert.deepEqual(phases.original.events.map(event => event.event_type), expectedTypes.slice(0, 3 + spec.markers.length));
  assert.deepEqual(phases.activity.events.map(event => event.event_type), expectedTypes.slice(0, -1));
  const start = observation.events[1].payload;
  assert.equal(start.execution_timeout_seconds, 1200);
  assert.equal(start.run_timeout_seconds, 1200);
  for (const key of ['execution_deadline_at', 'run_deadline_at']) {
    assert(Math.abs(Date.parse(start[key]) - Date.parse(observation.execution.started_at) - 1200000) < 1000);
  }
  for (const [i, event] of observation.events.entries()) {
    assert.equal(event.sequence, i + 1);
    assert.match(event.timestamp, timestamp, 'original microsecond timestamp retained');
    if (i) assert(event.timestamp >= observation.events[i - 1].timestamp);
  }
  const markers = observation.events.filter(event => event.event_type === 'VersionMarkerRecorded');
  assert.equal(markers.length, spec.markers.length);
  for (const [i, event] of markers.entries()) {
    const {task, ...fields} = event.payload;
    assert.deepEqual(fields, {change_id: spec.change_id, ...spec.markers[i]}, 'all marker fields and stored bounds');
    assert.equal(event.sequence, i + 3, 'physical original marker position');
    assert.equal(task.status, 'leased');
    assert.equal(task.type, 'workflow');
  }
  const scheduled = observation.events.find(event => event.event_type === 'ActivityScheduled');
  const activity = scheduled.payload.activity;
  assert.equal(scheduled.payload.sequence, spec.markers.length + 1);
  assert.equal(activity.sequence, spec.markers.length + 1);
  assert.equal(activity.type, 'parity.v1.echo_activity');
  assert.equal(activity.queue, `${workflowId}-activity`);
  assert.equal(activity.idempotency_key, activity.id);
  const retry = {snapshot_version: 1, max_attempts: 1,
    backoff_seconds: spec.retry_policy === 'empty' ? [] : [1, 2, 5, 10, 15, 30, 60, 120],
    start_to_close_timeout: 120, schedule_to_start_timeout: null, schedule_to_close_timeout: 600,
    heartbeat_timeout: null, non_retryable_error_types: []};
  assert.deepEqual(activity.retry_policy, retry, 'entire stored retry policy; no unused-entry trimming');
  assert.deepEqual(scheduled.typed_decoded.activity_arguments, {type: 'list', value: [fixture.typed_value]});
  const completed = observation.events.find(event => event.event_type === 'ActivityCompleted');
  assert.deepEqual(completed.typed_decoded.result, fixture.typed_value);
  assert.deepEqual(observation.events.at(-1).typed_decoded.output, output);
  if (observation.mode === 'http') {
    const requests = wireRequests(fixture, observation, workflowId);
    const outcomes = requests.filter(item => item.method === 'POST' && /^\/api\/worker\/workflow-tasks\/[^/]+\/complete$/.test(item.path));
    assert.equal(outcomes.length, 2, 'original admission and independent cold completion only');
    for (const item of outcomes) {
      assert.equal(item.status, 200);
      assert.equal(item.response.recorded, true);
    }
    assert.deepEqual(outcomes[0].request.commands.map(command => command.type),
      [...spec.markers.map(() => 'record_version_marker'), 'schedule_activity']);
    assert.deepEqual(outcomes[1].request.commands.map(command => command.type), ['complete_workflow']);
    for (const [i, marker] of markers.entries()) {
      const {sequence, ...expected} = spec.markers[i];
      assert.deepEqual(outcomes[0].request.commands[i], {type: 'record_version_marker', change_id: spec.change_id, ...expected});
      assert.equal(marker.payload.task.id, outcomes[0].path.split('/').at(-2));
      assert.equal(marker.payload.task.lease_owner, outcomes[0].request.lease_owner);
    }
    const activityOutcomes = requests.filter(item => item.method === 'POST' && /^\/api\/worker\/activity-tasks\/[^/]+\/complete$/.test(item.path));
    assert.equal(activityOutcomes.length, 1);
    assert.equal(activityOutcomes[0].status, 200);
    assert.equal(activityOutcomes[0].response.recorded, true);
    const deliveries = requests.filter(item => item.path === '/api/worker/workflow-tasks/poll' && item.response?.task);
    assert.equal(deliveries.length, 2, 'two actual public workflow deliveries');
    const cold = deliveries[1].response.task.history_events;
    assert.deepEqual(cold.map(event => event.event_type), phases.activity.events.map(event => event.event_type));
    for (const [i, event] of cold.entries()) {
      assert.equal(event.recorded_at, phases.activity.events[i].timestamp);
      assert.equal(event.sequence, phases.activity.events[i].sequence);
      assert.deepEqual(event.payload, phases.activity.events[i].payload, 'whole raw cold delivery prefix');
    }
  }
  return {fixture_id: fixture.id, workflow_id: workflowId, run_id: '@run:1', workflow_type: fixture.workflow_type,
    namespace: 'default', status: 'completed', input: observation.typed_input, output,
    markers: spec.markers, retry_policy: retry, events: expectedTypes,
    cold_processes: 3, original_pending_activity: true};
}
