import assert from 'node:assert/strict';

export function checkPatchPollPressureResponse(response) {
  assert.ok(response && typeof response === 'object' && !Array.isArray(response), 'actual pressure response summary');
  assert.equal(response.task, null, 'pressure response carries no leased task');
  assert.equal(response.poll_status, 'backend_lock_pressure', 'only declared backend lock pressure');
  assert.ok(Object.keys(response).every(key => ['task', 'poll_status', 'reason', 'message'].includes(key)),
    'pressure summary contains only core fields and published optional diagnostics');
  if (Object.hasOwn(response, 'reason')) assert.equal(response.reason, 'backend_lock_pressure', 'diagnostic reason agrees');
  if (Object.hasOwn(response, 'message')) assert.equal(typeof response.message, 'string', 'published diagnostic message is text');
}

// Whether a request deregisters exactly this worker: DELETE
// /api/worker/registrations/{workerId} with the id as one percent-encoded
// route segment, as the SDKs send it.
function deregisters(request, workerId) {
  const prefix = '/api/worker/registrations/';
  if (request.method !== 'DELETE' || typeof request.path !== 'string' || !request.path.startsWith(prefix)) return false;
  const segment = request.path.slice(prefix.length);
  if (segment === '' || segment.includes('/')) return false;
  try {
    return decodeURIComponent(segment) === workerId;
  } catch {
    return false;
  }
}

// A server whose worker long-poll wait slots are full answers a workflow or
// activity poll with PHP's typed, retryable 429 (WorkerPollBackpressure, the
// DW_WORKER_LONG_POLL_MAX_CONCURRENT contract) before holding a wait. It
// leases nothing. The worker then retries that same poll or, with its work
// done, deregisters; either way no task, outcome or authority is lost.
function checkLongPollCapacity(item, index, phase, observation, workflowId) {
  const kinds = {'/api/worker/workflow-tasks/poll': 'workflow_task', '/api/worker/activity-tasks/poll': 'activity_task'};
  assert.equal(item.method, 'POST');
  assert.ok(Object.hasOwn(kinds, item.path), 'only workflow/activity long-poll capacity is tolerated');
  assert.equal(item.client_cancelled ?? false, false);
  assert.equal(item.transport_error ?? null, null);
  const response = item.response;
  assert.ok(response && typeof response === 'object' && !Array.isArray(response), 'actual capacity response');
  assert.equal(response.task, null, 'capacity refusal leases no task');
  assert.equal(response.poll_status, 'long_poll_capacity_exhausted');
  assert.equal(response.reason, 'long_poll_capacity_exhausted');
  assert.match(item.response_retry_after ?? '', /^[1-9][0-9]*$/, 'positive explicit Retry-After');
  assert.ok(Number(item.response_retry_after) <= 25, 'retry hint fits the actual published worker deadline');
  if (item.response_projection === 'read_or_poll_summary') {
    // The SDK probe keeps only a poll's core fields (patch-deployment-probe.php);
    // the full bytes stay in its raw sidecar.
    assert.ok(Object.keys(response).every(key => ['task', 'poll_status', 'reason', 'message'].includes(key)),
      'projected capacity summary carries only core poll fields');
    if (Object.hasOwn(response, 'message')) assert.equal(typeof response.message, 'string');
  } else {
    assert.equal(response.retryable, true);
    assert.equal(response.task_kind, kinds[item.path]);
    assert.equal(response.namespace, observation.namespace);
    assert.equal(response.task_queue, observation.task_queue);
    assert.equal(item.response_retry_after, String(response.retry_after_seconds), 'Retry-After header agrees with the body');
  }
  assert.equal(item.request.worker_id, `${workflowId}:${phase.phase}`);
  assert.equal(item.request.task_queue, observation.task_queue);
  assert.ok(typeof item.request.poll_request_id === 'string' && item.request.poll_request_id.length > 0);
  const later = phase.requests.slice(index + 1);
  const ok = request => request.status >= 200 && request.status < 300;
  const retry = later.find(request => request.method === item.method && request.path === item.path
    && request.request?.poll_request_id === item.request.poll_request_id && ok(request));
  if (retry) {
    assert.deepEqual(retry.request, item.request, 'retry changes no poll authority or request fields');
    return;
  }
  assert.ok(later.some(request => deregisters(request, item.request.worker_id) && ok(request)),
    'the same poll succeeds later, or the refused worker itself stops cleanly after the refusal');
}

function checkPhpPollPressure(item, index, phase, observation, workflowId) {
  assert.equal(item.method, 'POST');
  assert.equal(item.path, '/api/worker/workflow-tasks/poll', 'PHP pressure is confined to workflow polling');
  assert.equal(item.client_cancelled ?? false, false);
  assert.equal(item.transport_error ?? null, null);
  const response = item.response;
  assert.deepEqual(Object.keys(response ?? {}).sort(), ['backend', 'error', 'message', 'namespace', 'poll_status',
    'protocol_version', 'reason', 'retry_after_seconds', 'server_capabilities', 'task', 'task_kind', 'task_queue']);
  assert.equal(response.task, null, 'pressure acquires no task');
  assert.equal(response.poll_status, 'backend_lock_pressure');
  assert.equal(response.reason, 'backend_lock_pressure');
  assert.deepEqual(response.backend, {driver: 'sqlite', lock_pressure: true});
  for (const field of ['error', 'message']) assert.ok(typeof response[field] === 'string' && response[field].length > 0);
  assert.equal(response.namespace, observation.namespace);
  assert.equal(response.task_queue, observation.task_queue);
  assert.equal(response.task_kind, 'workflow_task');
  assert.ok(Number.isSafeInteger(response.retry_after_seconds) && response.retry_after_seconds > 0
    && response.retry_after_seconds <= 25, 'bounded PHP response retry hint');
  assert.equal(item.request.worker_id, `${workflowId}:${phase.phase}`);
  assert.equal(item.request.task_queue, observation.task_queue);
  assert.ok(typeof item.request.poll_request_id === 'string' && item.request.poll_request_id.length > 0);
  const registration = phase.requests.slice(0, index).find(request => request.method === 'POST'
    && request.path === '/api/worker/register' && request.status >= 200 && request.status < 300);
  assert.ok(registration, 'pressure follows original registration');
  assert.match(response.protocol_version ?? '', /^\d+\.\d+$/);
  assert.ok(response.server_capabilities !== null && typeof response.server_capabilities === 'object'
    && !Array.isArray(response.server_capabilities) && Object.keys(response.server_capabilities).length > 0);
  assert.equal(response.protocol_version, registration.response.protocol_version);
  assert.deepEqual(response.server_capabilities, registration.response.server_capabilities,
    'pressure preserves original discovered capabilities');
  assert.ok(phase.diagnostics.includes('worker.retrying'), 'actual PHP retry diagnostic');
  const recovery = phase.requests.slice(index + 1).find(request => request.method === item.method && request.path === item.path
    && request.status >= 200 && request.status < 300);
  assert.ok(recovery, 'PHP poll recovers in the original phase');
  const {poll_request_id: originalPoll, ...originalAuthority} = item.request;
  const {poll_request_id: recoveredPoll, ...recoveredAuthority} = recovery.request;
  assert.ok(typeof recoveredPoll === 'string' && recoveredPoll.length > 0);
  assert.deepEqual(recoveredAuthority, originalAuthority, 'PHP recovery changes no worker, queue or options');
}

export function checkPublishedPatchArtifacts(fixture, artifacts) {
  if (fixture.patch_deployment?.embedded_clock_probe) {
    assert.match(artifacts.workflow_source_commit ?? '', /^[a-f0-9]{40}$/);
    assert.match(artifacts.embedded_composer_lock_sha256 ?? '', /^[a-f0-9]{64}$/);
    assert.deepEqual(Object.keys(artifacts.workflow_loaded_sources ?? {}).sort(),
      ['QueryStateReplayer', 'VersionDecisions', 'WorkflowExecutor', 'WorkflowFiberRunner'].map(name => `src/V2/Support/${name}.php`).sort(),
      'exact published engine source inventory');
    for (const hash of Object.values(artifacts.workflow_loaded_sources)) assert.match(hash, /^[a-f0-9]{64}$/);
  }
  const consumer = fixture.patch_deployment?.consumer;
  if (!consumer) return;
  assert.ok(['python', 'rust'].includes(consumer.language), 'reviewed published SDK language');
  assert.match(consumer.archive_sha256, /^[a-f0-9]{64}$/);
  assert.equal(artifacts[`sdk_${consumer.language}`], consumer.version, 'consumer version matches selected profile');
  const installed = artifacts.published_sdk_artifacts?.[consumer.language];
  assert.equal(installed?.version, consumer.version);
  assert.equal(installed?.archive_sha256, consumer.archive_sha256, 'exact published archive in selected profile');
  assert.match(installed?.source_commit ?? '', /^[a-f0-9]{40}$/);
}

export function checkPatchPackageObservation(fixture, observation, artifacts) {
  if (!fixture.patch_deployment?.embedded_clock_probe || observation.mode !== 'embedded') return;
  for (const phase of [observation, observation.patch_deployment?.original, observation.patch_deployment?.replacement]) {
    assert.ok(phase, 'both actual embedded engine processes');
    assert.equal(phase.workflow_package, artifacts.workflow, 'actually installed published engine');
    assert.equal(phase.workflow_source, artifacts.workflow_source_commit, 'actually installed published engine source');
    assert.equal(phase.embedded_composer_lock_sha256, artifacts.embedded_composer_lock_sha256,
      'complete installed embedded dependencies match the selected lock');
    assert.deepEqual(phase.workflow_loaded_sources, artifacts.workflow_loaded_sources,
      'autoloaded engine classes match the exact published source bytes');
  }
}

export function checkPatchDeployment(fixture, observation, workflowId) {
  const spec = fixture.patch_deployment;
  const marked = spec.original_patch === true;
  const markerCount = marked ? 1 : 0;
  const activitySequence = marked ? 2 : 1;
  const state = observation.patch_deployment;
  assert.ok(state, 'complete patch deployment observations');
  assert.equal(state.checkpoint, spec.checkpoint, 'declared original checkpoint');
  assert.equal(state.change_id, spec.change_id, 'original patch change ID');
  const {original, replacement} = state;
  assert.equal(original.phase, 'original');
  assert.equal(replacement.phase, 'replacement');
  assert.ok(Number.isInteger(original.pid) && original.pid > 0, 'original process identity');
  assert.ok(Number.isInteger(replacement.pid) && replacement.pid > 0, 'replacement process identity');
  assert.notEqual(original.pid, replacement.pid, 'actual cold replacement process');
  for (const phase of [original, replacement]) {
    assert.equal(phase.workflow_id, workflowId, 'same workflow across deployment');
    assert.equal(phase.run_id, observation.run_id, 'same original run across deployment');
    assert.equal(phase.workflow_type, fixture.workflow_type);
    assert.equal(phase.namespace, observation.namespace);
    assert.equal(phase.task_queue, observation.task_queue);
    assert.equal(phase.mode, observation.mode);
    assert.equal(phase.sdk_php, observation.sdk_php, 'exact PHP original worker / observation reader');
    assert.equal(phase.sdk_php_source, observation.sdk_php_source, 'exact PHP original worker / observation reader source');
    assert.deepEqual(phase.typed_input, observation.typed_input, 'exact original input');
    assert.equal(phase.payload_codec, 'avro');
    if (observation.mode === 'embedded') {
      assert.equal(phase.workflow_package, observation.workflow_package, 'unchanged exact embedded package across processes');
      assert.equal(phase.instance.id, workflowId, 'original physical embedded instance');
      assert.equal(phase.instance.namespace, observation.namespace, 'explicit persisted embedded namespace');
      assert.equal(phase.instance.workflow_type, fixture.workflow_type, 'original registered instance type');
      assert.equal(phase.instance.current_run_id, observation.run_id, 'original current run across replacement');
    }
  }
  if (spec.consumer) {
    assert.deepEqual(spec.workflow_poll_retry, {status: 503, poll_status: 'backend_lock_pressure',
      require_same_request: true, require_positive_retry_after: true}, 'explicit bounded poll retry contract');
    assert.equal(spec.cancel_query_poll_on_shutdown, true, 'explicit cancellation of background query polls');
    assert.equal(spec.start_before_worker_registration, true, 'explicit legacy start before a worker advertises identity');
    assert.notEqual(original.events[1].payload.workflow_definition_fingerprint_source, 'worker',
      'this legacy control must not bypass a recorded worker definition fingerprint');
    if (observation.mode === 'http') assert.ok(original.events[1].payload.workflow_definition_fingerprint == null,
      'actual HTTP legacy start has no advertised definition fingerprint');
    if (observation.mode === 'embedded') assert.deepStrictEqual(replacement.consumer,
      {applicable: false, reason: 'embedded_executes_php_author_definitions'});
    else {
      assert.deepStrictEqual(replacement.consumer, spec.consumer, 'actual explicitly pinned published replacement SDK');
      assert.equal(replacement.worker_finished, true, 'published worker returns after shutdown');
    }
  }
  const checkpointLength = (spec.checkpoint === 'activity_pending' ? 3 : 5) + markerCount;
  assert.ok(['activity_pending', 'activity_completed'].includes(spec.checkpoint));
  assert.deepEqual(original.events.map(event => event.event_type), fixture.expected_events.slice(0, checkpointLength),
    'original worker stops at the exact persisted checkpoint');
  assert.notEqual(original.status, 'completed', 'original worker leaves unfinished work');
  assert.equal(original.output, null, 'no premature outcome');
  if (marked) {
    assert.ok(original.decisions.length > 0, 'fresh original author executes patch');
    for (const decisions of original.decisions) assert.deepEqual(decisions, spec.expected_decisions);
  } else assert.deepEqual(original.decisions, [], 'original author has no patch call');
  assert.deepEqual(observation.events.slice(0, checkpointLength), original.events, 'immutable complete checkpoint history');
  assert.equal(replacement.status, 'completed');
  assert.deepEqual(replacement.events, observation.events, 'replacement observes the committed complete history');
  assert.deepEqual(replacement.typed_output, observation.typed_output, 'replacement preserves typed outcome');
  assert.ok(replacement.decisions.length > 0, 'actual replacement patch execution');
  for (const decisions of replacement.decisions) {
    assert.equal(decisions.length, spec.repeated_calls);
    assert.deepEqual(decisions, spec.expected_decisions, 'one repeated frozen decision without consuming another command');
  }
  const markers = observation.events.filter(event => event.event_type === 'VersionMarkerRecorded');
  assert.equal(markers.length, markerCount, 'one fresh marker or no marker on an unmarked old run');
  if (marked) {
    assert.deepEqual(spec.marker, {sequence: 1, change_id: spec.change_id, version: 1, min_supported: -1, max_supported: 1},
      'reviewed fresh patched marker');
    const {task, ...payload} = markers[0].payload;
    assert.deepEqual(payload, spec.marker, 'exact frozen five-field marker with original task annotation');
    assert.ok(task && typeof task.id === 'string' && task.id.length > 0, 'original marker task identity');
    assert.equal(task.type, 'workflow');
    assert.equal(task.status, 'leased');
    assert.ok(typeof task.lease_owner === 'string' && task.lease_owner.length > 0, 'original marker authority');
    const {task: originalTask, ...originalPayload} = original.events[2].payload;
    assert.deepEqual(originalPayload, spec.marker, 'marker is committed by the original worker');
    assert.deepEqual(originalTask, task, 'original marker task annotation stays immutable');
  }
  if (spec.embedded_clock_probe) {
    assert.equal(marked, true, 'clock probe replays a persisted marker');
    assert.deepEqual(spec.embedded_clock_probe, {phase: 'replacement', marker_sequences: [1, 1]},
      'reviewed repeated replacement clock contract');
    assert.equal(original.embedded_clock_probe, undefined, 'clock observations begin at cold replacement');
    if (observation.mode === 'embedded') {
      assert.match(markers[0].timestamp, /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/, 'retain original marker microseconds');
      assert.deepEqual(observation.embedded_clock_probe, replacement.embedded_clock_probe);
      const clocks = replacement.embedded_clock_probe?.clocks;
      assert.ok(Array.isArray(clocks), 'actual embedded author clock snapshots');
      assert.equal(clocks.length, replacement.decisions.length, 'clock snapshots cover every actual replay');
      for (const snapshot of clocks) assert.deepEqual(snapshot, spec.embedded_clock_probe.marker_sequences.map(sequence => {
        const marker = original.events.find(event => event.event_type === 'VersionMarkerRecorded' && event.payload.sequence === sequence);
        assert.ok(marker, 'clock comes from the original persisted marker');
        return marker.timestamp;
      }), 'each actual author clock preserves the original marker time');
    } else {
      assert.equal(observation.embedded_clock_probe, undefined, 'HTTP observation cannot claim an embedded author helper');
      assert.equal(replacement.embedded_clock_probe, undefined);
    }
  }
  assert.equal(observation.events[2 + markerCount].payload.sequence, activitySequence,
    'original activity stays at its authored sequence');
  if (observation.mode === 'http') {
    const commands = phase => phase.requests.filter(item => item.method === 'POST'
      && item.path.startsWith('/api/worker/workflow-tasks/') && item.path.endsWith('/complete'));
    const oldCompletions = commands(original);
    const newCompletions = commands(replacement);
    assert.equal(oldCompletions.length, 1, 'one accepted original authored turn');
    assert.equal(oldCompletions[0].status, 200);
    if (spec.consumer) {
      assert.equal(spec.original_sticky_ttl_seconds, 1, 'explicit bounded affinity expiry before cold replacement');
      assert.equal(oldCompletions[0].request.sticky_cache?.ttl_seconds, spec.original_sticky_ttl_seconds,
        'actual original SDK uses the declared affinity budget');
    }
    assert.deepEqual(oldCompletions[0].request.commands.map(command => command.type),
      [...(marked ? ['record_version_marker'] : []), 'schedule_activity']);
    if (marked) {
      const command = oldCompletions[0].request.commands[0];
      const {sequence, ...fields} = spec.marker;
      for (const [name, value] of Object.entries(fields)) assert.equal(command[name], value, 'actual SDK marker request');
      assert.equal(markers[0].payload.task.id, oldCompletions[0].path.split('/').at(-2), 'original accepted marker task');
      assert.equal(markers[0].payload.task.lease_owner, oldCompletions[0].request.lease_owner, 'original marker lease owner');
    }
    assert.equal(oldCompletions[0].request.commands[markerCount].activity_type, 'parity.v1.echo_activity');
    assert.ok(newCompletions.length > 0);
    assert.deepEqual(newCompletions.flatMap(item => item.request.commands.map(command => command.type)),
      ['complete_workflow'], 'replacement neither reschedules old activity nor emits marker/new activity');
    for (const phase of [original, replacement]) {
      if (phase === replacement && spec.consumer) {
        assert.equal(phase.requests.filter(item => item.method === 'POST' && item.path === '/api/worker/register').length, 1,
          'real published SDK registration');
        assert.equal(phase.requests.filter(item => item.method === 'DELETE' && item.path.startsWith('/api/worker/registrations/')).length, 1,
          'real published SDK deregistration');
        assert.ok(phase.requests.some(item => deregisters(item, `${workflowId}:replacement`)),
          'the replacement worker deregisters itself');
      } else {
        assert.ok(phase.diagnostics.includes('worker.registered'));
        assert.ok(phase.diagnostics.includes('worker.stopped'));
        assert.ok(!phase.diagnostics.some(event => ['worker.failed', 'worker.handler_failed', 'worker.shutdown_failed'].includes(event)),
          'no hidden worker or shutdown failure');
      }
      for (const [index, item] of phase.requests.entries()) {
        const phpWorkerPhase = phase === original ? !spec.legacy_marker_history
          : spec.embedded_clock_probe && !spec.consumer;
        if (phpWorkerPhase && item.status === 503) {
          checkPhpPollPressure(item, index, phase, observation, workflowId);
          continue;
        }
        if (phase === replacement && spec.consumer && item.client_cancelled === true) {
          assert.equal(item.status, 0, 'cancelled poll received no HTTP response');
          assert.equal(item.method, 'POST');
          assert.equal(item.path, '/api/worker/query-tasks/poll', 'only background query polling may be cancelled at shutdown');
          assert.equal(item.transport_error, null, 'actual client cancellation, not hidden upstream failure');
          assert.equal(item.request.worker_id, `${workflowId}:replacement`);
          assert.equal(item.request.task_queue, observation.task_queue);
          assert.ok(phase.requests.slice(0, index).some(request => newCompletions.includes(request) && request.status === 200),
            'original workflow completion precedes query poll cancellation');
          continue;
        }
        if (phase === replacement && spec.consumer && item.status === 503) {
          assert.equal(item.method, 'POST');
          assert.ok(['/api/worker/workflow-tasks/poll', '/api/worker/activity-tasks/poll'].includes(item.path),
            'only workflow/activity poll pressure is tolerated');
          checkPatchPollPressureResponse(item.response);
          assert.match(item.response_retry_after ?? '', /^[1-9][0-9]*$/, 'positive explicit Retry-After');
          assert.ok(Number.isSafeInteger(Number(item.response_retry_after)) && Number(item.response_retry_after) <= 25,
            'retry hint fits the actual published worker deadline');
          assert.equal(item.client_cancelled, false);
          assert.equal(item.transport_error, null);
          assert.equal(item.request.worker_id, `${workflowId}:replacement`);
          assert.equal(item.request.task_queue, observation.task_queue);
          assert.ok(typeof item.request.poll_request_id === 'string' && item.request.poll_request_id.length > 0);
          const retry = phase.requests.slice(index + 1).find(request => request.method === item.method && request.path === item.path
            && request.request?.poll_request_id === item.request.poll_request_id && request.status >= 200 && request.status < 300);
          assert.ok(retry, 'same original poll request succeeds after pressure');
          assert.deepEqual(retry.request, item.request, 'retry changes no poll authority or request fields');
          continue;
        }
        if (item.status === 429) {
          checkLongPollCapacity(item, index, phase, observation, workflowId);
          continue;
        }
        assert.ok(item.status >= 200 && item.status < 300, 'all other observed I/O succeeds');
      }
    }
    const outcomes = phase => phase.requests.filter(item => item.method === 'POST'
      && item.path.startsWith('/api/worker/activity-tasks/') && item.path.endsWith('/complete'));
    assert.equal(outcomes(original).length, spec.checkpoint === 'activity_pending' ? 0 : 1);
    assert.equal(outcomes(replacement).length, spec.checkpoint === 'activity_pending' ? 1 : 0);
    assert.equal(outcomes(original).length + outcomes(replacement).length, 1, 'one actual SDK activity outcome');
  }
  return {checkpoint: spec.checkpoint, change_id: spec.change_id, command_sequence: activitySequence,
    decisions: spec.expected_decisions, original_events: fixture.expected_events.slice(0, checkpointLength),
    cold_replacement: true, marker_count: markerCount, ...(marked ? {marker: spec.marker} : {}), activity_outcomes: 1};
}
