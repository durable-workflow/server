import assert from 'node:assert/strict';
import {checkPatchPollPressureResponse} from './patch-deployment-contract.mjs';

export function checkLegacyMarkerArtifacts(fixture, artifacts) {
  const spec = fixture.patch_deployment;
  assert.deepEqual(spec.legacy_marker_history, {
    original_marker_count: spec.legacy_marker_history.original_marker_count,
    original_calls: spec.legacy_marker_history.original_calls,
    embedded_original: spec.producer.language === 'python' ? '2.5.5' : '2.5.7',
  });
  assert([1, 2].includes(spec.legacy_marker_history.original_marker_count));
  assert([1, 2].includes(spec.legacy_marker_history.original_calls));
  assert.equal(spec.original_patch, true);
  assert.equal(spec.repeated_calls, 2);
  assert.deepEqual(spec.expected_decisions, [true, true]);
  assert.deepEqual(spec.cancel_poll_on_shutdown, ['workflow', 'activity', 'query'],
    'explicit clean published-worker shutdown poll policy');
  assert.deepEqual(spec.query_poll_capacity, {status: 429, poll_status: 'long_poll_capacity_exhausted',
    retry_after_seconds: 1, allow_clean_shutdown_after_commit: true}, 'explicit published idle-query capacity policy');
  for (const selected of [spec.producer, spec.consumer]) {
    assert(['python', 'rust'].includes(selected.language));
    const installed = artifacts.published_sdk_artifacts?.[selected.language];
    assert(installed, 'explicit published producer and reader inputs');
    assert.equal(installed.version, selected.version);
    assert.equal(installed.archive_sha256, selected.archive_sha256);
    assert.match(installed.archive_sha256, /^[a-f0-9]{64}$/);
    assert.match(installed.source_commit, /^[a-f0-9]{40}$/);
    assert.equal(artifacts['sdk_'+selected.language], selected.version);
  }
  assert.equal(spec.consumer.language, 'rust');
  assert.equal(spec.consumer.version, '3.4.3');
  if (spec.producer.language === 'python') {
    assert.equal(spec.producer.version, '2.5.0');
    assert.equal(spec.legacy_marker_history.original_marker_count, spec.legacy_marker_history.original_calls,
      'old author actually persists each repeated call');
  } else {
    assert.equal(spec.producer.version, '3.4.3');
    assert.equal(spec.legacy_marker_history.original_marker_count, 1, 'fresh Rust deduplicates');
    assert.equal(spec.legacy_marker_history.original_calls, 2);
  }
  const old = artifacts.embedded_original;
  assert.equal(old?.version, '2.5.5');
  assert.match(old.source_commit, /^[a-f0-9]{40}$/);
  assert.match(old.composer_lock_sha256, /^[a-f0-9]{64}$/);
  assert.deepEqual(Object.keys(old.loaded_sources).sort(),
    ['VersionResolver', 'WorkflowExecutor', 'WorkflowFiberRunner', 'QueryStateReplayer'].map(name => 'src/V2/Support/'+name+'.php').sort());
  for (const hash of Object.values(old.loaded_sources)) assert.match(hash, /^[a-f0-9]{64}$/);
}

export function checkLegacyMarkerPackageObservation(fixture, observation, artifacts) {
  if (observation.mode !== 'embedded') return;
  const current = {version: artifacts.workflow, source_commit: artifacts.workflow_source_commit,
    composer_lock_sha256: artifacts.embedded_composer_lock_sha256, loaded_sources: artifacts.workflow_loaded_sources};
  for (const [phase, expected] of [[observation, current],
    [observation.patch_deployment.original, fixture.patch_deployment.legacy_marker_history.embedded_original === '2.5.5'
      ? artifacts.embedded_original : current], [observation.patch_deployment.replacement, current]]) {
    assert.equal(phase.workflow_package, expected.version, 'actual selected embedded phase engine');
    assert.equal(phase.workflow_source, expected.source_commit);
    assert.equal(phase.embedded_composer_lock_sha256, expected.composer_lock_sha256);
    assert.deepEqual(phase.workflow_loaded_sources, expected.loaded_sources, 'actual autoloaded phase sources');
  }
}

export function checkLegacyMarkerDeployment(fixture, observation, workflowId) {
  const spec = fixture.patch_deployment, definition = spec.legacy_marker_history;
  const count = definition.original_marker_count, activitySequence = count + 1;
  const state = observation.patch_deployment;
  assert.equal(state.checkpoint, spec.checkpoint);
  assert.equal(state.change_id, spec.change_id);
  const {original, replacement} = state;
  assert.equal(original.phase, 'original');
  assert.equal(replacement.phase, 'replacement');
  for (const phase of [original, replacement]) {
    assert(Number.isInteger(phase.pid) && phase.pid > 0);
    for (const [key, value] of Object.entries({workflow_id: workflowId, run_id: observation.run_id,
      workflow_type: fixture.workflow_type, namespace: 'default', task_queue: 'server-parity-v1',
      payload_codec: 'avro', mode: observation.mode, sdk_php: observation.sdk_php,
      sdk_php_source: observation.sdk_php_source})) assert.equal(phase[key], value);
    assert.deepEqual(phase.typed_input, {type: 'list', value: [fixture.typed_value]});
    if (observation.mode === 'embedded') {
      assert.equal(phase.instance.id, workflowId);
      assert.equal(phase.instance.current_run_id, observation.run_id);
      assert.equal(phase.instance.namespace, 'default');
      assert.equal(phase.instance.workflow_type, fixture.workflow_type);
    }
  }
  assert.notEqual(original.pid, replacement.pid, 'actual cold worker process');
  assert(['activity_pending', 'activity_completed'].includes(spec.checkpoint));
  const length = (spec.checkpoint === 'activity_pending' ? 3 : 5) + count;
  assert.deepEqual(original.events.map(event => event.event_type), fixture.expected_events.slice(0, length));
  assert(['pending', 'running', 'waiting'].includes(original.status), 'original is unfinished');
  assert.equal(original.output, null);
  assert.deepEqual(observation.events.slice(0, length), original.events, 'entire real authored prefix stays immutable');
  assert.deepEqual(replacement.events, observation.events);
  assert.equal(replacement.status, 'completed');
  assert.deepEqual(replacement.typed_output, fixture.typed_value);
  assert(original.decisions.length > 0 && replacement.decisions.length > 0);
  for (const decisions of original.decisions) assert.deepEqual(decisions, Array(definition.original_calls).fill(true));
  for (const decisions of replacement.decisions) assert.deepEqual(decisions, [true, true]);
  const markers = observation.events.filter(event => event.event_type === 'VersionMarkerRecorded');
  assert.equal(markers.length, count, 'retain every actual marker, without appending or deleting one');
  assert.deepEqual(spec.marker, {sequence: 1, change_id: spec.change_id, version: 1, min_supported: -1, max_supported: 1});
  for (const [index, marker] of markers.entries()) {
    const {task, ...payload} = marker.payload;
    assert.deepEqual(payload, {...spec.marker, sequence: index + 1});
    assert.equal(marker.sequence, index + 3, 'physical event ordering');
    assert.deepEqual(marker, original.events[index + 2], 'original marker position and time bytes');
    assert.equal(task.type, 'workflow');
    assert.equal(task.status, 'leased');
    assert.equal(typeof task.id, 'string');
    assert(task.id.length > 0);
    assert.equal(typeof task.lease_owner, 'string');
    assert(task.lease_owner.length > 0);
  }
  assert.equal(observation.events[2 + count].event_type, 'ActivityScheduled');
  assert.equal(observation.events[2 + count].payload.sequence, activitySequence);
  assert.deepEqual(spec.embedded_clock_probe, {phase: 'replacement', marker_sequences: count === 2 ? [1, 2] : [1, 1]});
  assert.equal(original.embedded_clock_probe, undefined);
  if (observation.mode === 'embedded') {
    assert.deepEqual(original.producer, {applicable: false, reason: 'embedded_executes_php_author_definitions'});
    assert.deepEqual(replacement.consumer, {applicable: false, reason: 'embedded_executes_php_author_definitions'});
    assert.deepEqual(observation.embedded_clock_probe, replacement.embedded_clock_probe);
    const clocks = replacement.embedded_clock_probe.clocks;
    assert.equal(clocks.length, replacement.decisions.length);
    for (const marker of markers) assert.match(marker.timestamp, /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/);
    for (const values of clocks) assert.deepEqual(values, spec.embedded_clock_probe.marker_sequences.map(sequence => markers[sequence - 1].timestamp));
  } else {
    assert.equal(observation.embedded_clock_probe, undefined, 'no invented service author-clock observation');
    assert.equal(replacement.embedded_clock_probe, undefined);
    assert.deepEqual(original.producer, spec.producer);
    assert.deepEqual(replacement.consumer, spec.consumer);
    assert.equal(original.worker_finished, true);
    assert.equal(replacement.worker_finished, true);
    assert.equal(spec.start_before_worker_registration, true);
    assert.equal(original.events[1].payload.workflow_definition_fingerprint == null, true);
    assert.notEqual(original.events[1].payload.workflow_definition_fingerprint_source, 'worker');
    assert.equal(spec.original_sticky_ttl_seconds, 0, 'this profile requests no sticky affinity');
    const completions = phase => phase.requests.filter(request => request.method === 'POST'
      && request.path.startsWith('/api/worker/workflow-tasks/') && request.path.endsWith('/complete'));
    const authored = completions(original), resumed = completions(replacement);
    assert.equal(authored.length, 1);
    assert.equal(authored[0].status, 200);
    assert.equal(authored[0].request.sticky_cache, undefined, 'actual published author requests no sticky affinity');
    assert.deepEqual(authored[0].request.commands.map(command => command.type), [...Array(count).fill('record_version_marker'), 'schedule_activity']);
    for (const [index, marker] of markers.entries()) {
      const command = authored[0].request.commands[index];
      const {sequence, ...fields} = spec.marker;
      for (const [key, value] of Object.entries(fields)) assert.equal(command[key], value);
      assert.equal(marker.payload.task.id, authored[0].path.split('/').at(-2));
      assert.equal(marker.payload.task.lease_owner, authored[0].request.lease_owner);
    }
    assert.equal(authored[0].request.commands[count].activity_type, 'parity.v1.echo_activity');
    assert.deepEqual(resumed.flatMap(request => request.request.commands.map(command => command.type)), ['complete_workflow']);
    const activities = phase => phase.requests.filter(request => request.method === 'POST'
      && request.path.startsWith('/api/worker/activity-tasks/') && request.path.endsWith('/complete'));
    assert.equal(activities(original).length, spec.checkpoint === 'activity_pending' ? 0 : 1);
    assert.equal(activities(replacement).length, spec.checkpoint === 'activity_pending' ? 1 : 0);
    for (const phase of [original, replacement]) {
      const role = phase.phase;
      const registrations = role === 'original' && spec.checkpoint === 'activity_completed' ? 2 : 1;
      assert.equal(phase.requests.filter(request => request.method === 'POST' && request.path === '/api/worker/register').length, registrations);
      assert.equal(phase.requests.filter(request => request.method === 'DELETE' && request.path.startsWith('/api/worker/registrations/')).length, registrations);
      const workers = role === 'replacement' ? [workflowId+':replacement'] : [workflowId+':original', workflowId+':original-activity'];
      const registered = phase.requests.filter(request => request.method === 'POST' && request.path === '/api/worker/register');
      assert.deepEqual(registered.map(request => request.request.worker_id).sort(), workers.slice(0, registrations).sort());
      for (const request of registered) assert.equal(request.request.task_queue, 'server-parity-v1');
      for (const worker of workers.slice(0, registrations)) {
        const withdrawal = phase.requests.filter(request => request.method === 'DELETE'
          && request.path === '/api/worker/registrations/'+encodeURIComponent(worker));
        assert.equal(withdrawal.length, 1, 'each actual registered worker withdraws once');
        assert.equal(withdrawal[0].response.worker_id, worker, 'withdrawal acknowledges the same worker');
      }
      for (const request of completions(phase)) assert.equal(request.request.lease_owner, workflowId+':'+role);
      for (const [index, request] of phase.requests.entries()) {
        assert.equal(request.transport_error, null);
        if (request.client_cancelled) {
          assert.equal(request.status, 0);
          assert.equal(request.method, 'POST');
          assert(spec.cancel_poll_on_shutdown.map(kind => '/api/worker/'+kind+'-tasks/poll').includes(request.path));
          assert(workers.slice(0, registrations).includes(request.request.worker_id));
          assert.equal(request.request.task_queue, 'server-parity-v1');
          assert.equal(request.response_encoding, 'identity');
          assert.equal(request.response_retry_after, null);
          assert(request.response && typeof request.response === 'object' && Object.keys(request.response).length === 0,
            'cancelled poll has an empty response');
          assert(phase.requests.slice(0, index).some(item => completions(phase).includes(item) && item.status === 200), 'authored commit precedes shutdown cancellation');
        } else if (request.status === spec.query_poll_capacity.status) {
          assert.equal(request.client_cancelled, false);
          assert.equal(request.method, 'POST');
          assert.equal(request.path, '/api/worker/query-tasks/poll');
          assert.equal(request.response.task, null);
          assert.equal(request.response.poll_status, spec.query_poll_capacity.poll_status);
          assert.equal(request.response.reason, spec.query_poll_capacity.poll_status);
          assert.equal(typeof request.response.message, 'string');
          assert(Object.keys(request.response).every(key => ['task', 'poll_status', 'reason', 'message'].includes(key)));
          assert.equal(request.response_retry_after, String(spec.query_poll_capacity.retry_after_seconds));
          assert.equal(request.request.task_queue, 'server-parity-v1');
          assert.equal(typeof request.request.poll_request_id, 'string');
          assert(request.request.poll_request_id.length > 0);
          const worker = request.request.worker_id;
          assert(registered.some(item => item.request.worker_id === worker && phase.requests.indexOf(item) < index),
            'capacity refusal belongs to an already registered worker');
          const recovered = phase.requests.slice(index + 1).some(next => next.method === request.method && next.path === request.path
            && next.status === 200 && !next.client_cancelled && next.transport_error === null
            && JSON.stringify(next.request) === JSON.stringify(request.request));
          const withdrawal = phase.requests.findIndex(item => item.method === 'DELETE'
            && item.path === '/api/worker/registrations/'+encodeURIComponent(worker) && item.status === 200
            && item.response.worker_id === worker);
          const committed = phase.requests.slice(0, withdrawal).some(item => item.status === 200
            && [...completions(phase), ...activities(phase)].includes(item) && item.request.lease_owner === worker);
          assert(recovered || spec.query_poll_capacity.allow_clean_shutdown_after_commit && withdrawal > index && committed,
            'query capacity refusal recovers identically or the worker withdraws after its own accepted commit');
        } else if (request.status === 503) {
          assert.equal(request.method, 'POST');
          assert(['/api/worker/workflow-tasks/poll', '/api/worker/activity-tasks/poll'].includes(request.path));
          checkPatchPollPressureResponse(request.response);
          assert.match(request.response_retry_after ?? '', /^[1-9][0-9]*$/);
          assert(Number.isSafeInteger(Number(request.response_retry_after)) && Number(request.response_retry_after) <= 25);
          assert(workers.includes(request.request.worker_id));
          assert.equal(request.request.task_queue, 'server-parity-v1');
          assert.equal(typeof request.request.poll_request_id, 'string');
          assert(request.request.poll_request_id.length > 0);
          assert(phase.requests.slice(index + 1).some(next => next.method === request.method && next.path === request.path
            && next.status >= 200 && next.status < 300 && !next.client_cancelled && next.transport_error === null
            && JSON.stringify(next.request) === JSON.stringify(request.request)), 'same original poll recovers');
        } else {
          assert.equal(request.client_cancelled, false);
          assert(request.status >= 200 && request.status < 300, `${role}: ${request.method} ${request.path} returned ${request.status}`);
        }
      }
    }
  }
  return {checkpoint: spec.checkpoint, change_id: spec.change_id, command_sequence: activitySequence,
    decisions: [true, true], original_events: fixture.expected_events.slice(0, length), cold_replacement: true,
    marker_count: count, marker_sequences: markers.map(marker => marker.payload.sequence), activity_outcomes: 1};
}
