import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkLegacyMarkerArtifacts, checkLegacyMarkerPackageObservation, checkLegacyMarkerDeployment} from '../../scripts/conformance/server-parity/legacy-marker-contract.mjs';
const profile = new URL('../Fixtures/ServerParityProfiles/legacy-marker-aliases/', import.meta.url);
const artifacts = JSON.parse(readFileSync(new URL('artifacts.json', profile)));

for (const id of ['legacy-two-markers-pending', 'legacy-two-markers-completed', 'legacy-one-marker-control', 'fresh-rust-marker-deduplication']) {
  const fixture = JSON.parse(readFileSync(new URL(id+'.json', profile)));
  const spec = fixture.patch_deployment, count = spec.legacy_marker_history.original_marker_count;
  function model(mode = 'embedded') {
    const events = fixture.expected_events.map((event_type, index) => ({sequence: index + 1, event_type, decoded: [], typed_decoded: [],
      timestamp: `2026-01-01T00:00:0${index}.123456Z`, payload: event_type === 'VersionMarkerRecorded'
        ? {...spec.marker, sequence: index - 1, task: {id: 'original-task', type: 'workflow', status: 'leased', lease_owner: 'test:original'}}
        : {sequence: count + 1, ...(['ActivityScheduled', 'ActivityCompleted', 'WorkflowCompleted'].includes(event_type)
          ? {task: {id: event_type === 'ActivityScheduled' ? 'original-task' : event_type === 'ActivityCompleted' ? 'original-activity' : 'replacement-task',
            lease_owner: event_type === 'ActivityScheduled' ? 'test:original' : event_type === 'WorkflowCompleted' || spec.checkpoint === 'activity_pending'
              ? 'test:replacement' : 'test:original-activity'}} : {})}}));
    const current = {workflow_package: artifacts.workflow, workflow_source: artifacts.workflow_source_commit,
      embedded_composer_lock_sha256: artifacts.embedded_composer_lock_sha256, workflow_loaded_sources: artifacts.workflow_loaded_sources};
    const old = {workflow_package: artifacts.embedded_original.version, workflow_source: artifacts.embedded_original.source_commit,
      embedded_composer_lock_sha256: artifacts.embedded_original.composer_lock_sha256, workflow_loaded_sources: artifacts.embedded_original.loaded_sources};
    const base = {mode, sdk_php: artifacts.sdk_php, sdk_php_source: artifacts.sdk_php_source_commit,
      workflow_id: 'test', run_id: 'original-run', workflow_type: fixture.workflow_type,
      namespace: 'default', task_queue: 'server-parity-v1', payload_codec: 'avro',
      typed_input: {type: 'list', value: [fixture.typed_value]}, ...current,
      instance: {id: 'test', current_run_id: 'original-run', namespace: 'default', workflow_type: fixture.workflow_type}};
    const io = (method, path, request = {}) => ({method, path, request, status: 200, transport_error: null, client_cancelled: false,
      ...(path.endsWith('/complete') ? {response: {recorded: true}} : {}),
      request_body_complete: true, upstream_request_started: true});
    const registration = role => [io('POST', '/api/worker/register', {worker_id: 'test:'+role, task_queue: 'server-parity-v1'}),
      {...io('DELETE', '/api/worker/registrations/'+encodeURIComponent('test:'+role)), response: {worker_id: 'test:'+role}}];
    const outcome = io('POST', '/api/worker/activity-tasks/original-activity/complete', {
      lease_owner: spec.checkpoint === 'activity_completed' ? 'test:original-activity' : 'test:replacement'});
    const {sequence, ...fields} = spec.marker;
    const original = {...structuredClone(base), ...(spec.legacy_marker_history.embedded_original === '2.5.5' ? old : {}),
      phase: 'original', pid: 101, status: 'pending', output: null, worker_finished: true,
      producer: mode === 'http' ? spec.producer : {applicable: false, reason: 'embedded_executes_php_author_definitions'},
      decisions: [Array(spec.legacy_marker_history.original_calls).fill(true)],
      events: structuredClone(events.slice(0, (spec.checkpoint === 'activity_pending' ? 3 : 5) + count)),
      requests: [...registration('original'), io('POST', '/api/worker/workflow-tasks/original-task/complete', {
        lease_owner: 'test:original',
        commands: [...Array.from({length: count}, () => ({type: 'record_version_marker', ...fields})),
          {type: 'schedule_activity', activity_type: 'parity.v1.echo_activity'}]}),
      ...(spec.checkpoint === 'activity_completed' ? [...registration('original-activity'), outcome] : [])]};
    const replacement = {...structuredClone(base), phase: 'replacement', pid: 102, status: 'completed',
      typed_output: fixture.typed_value, worker_finished: true, decisions: [[true, true]], events: structuredClone(events),
      consumer: mode === 'http' ? spec.consumer : {applicable: false, reason: 'embedded_executes_php_author_definitions'},
      ...(mode === 'embedded' ? {embedded_clock_probe: {clocks: [spec.embedded_clock_probe.marker_sequences.map(sequence => events[sequence + 1].timestamp)]}} : {}),
      requests: [...registration('replacement'), ...(spec.checkpoint === 'activity_pending' ? [outcome] : []),
        io('POST', '/api/worker/workflow-tasks/replacement-task/complete', {lease_owner: 'test:replacement', commands: [{type: 'complete_workflow'}]})]};
    for (const selected of [original, replacement]) {
      selected.requests = [...selected.requests.filter(item => item.path === '/api/worker/register'),
        ...selected.requests.filter(item => item.path.endsWith('/complete')),
        ...selected.requests.filter(item => item.method === 'DELETE')];
    }
    return {...structuredClone(replacement), patch_deployment: {checkpoint: spec.checkpoint, change_id: spec.change_id, original, replacement}};
  }
  for (const mode of ['http', 'embedded']) test(`${id} ${mode}: preserve actual retained positions and cold original-run completion`, () => {
    const raw = model(mode);
    checkLegacyMarkerArtifacts(fixture, artifacts);
    checkLegacyMarkerPackageObservation(fixture, raw, artifacts);
    const projection = checkLegacyMarkerDeployment(fixture, raw, 'test');
    assert.equal(projection.marker_count, count);
    assert.equal(projection.command_sequence, count + 1);
  });
  for (const [name, mutate] of [
    ['missing original event', x => x.patch_deployment.original.events.pop()],
    ['new run', x => x.patch_deployment.replacement.run_id = 'replacement-run'],
    ['same process', x => x.patch_deployment.replacement.pid = 101],
    ['wrong second decision', x => x.patch_deployment.replacement.decisions[0][1] = false],
    ['changed original timestamp', x => x.patch_deployment.original.events[2].timestamp = '2026-01-01T00:00:02.123455Z'],
    ['dropped physical marker', x => x.events.splice(2, 1)],
    ['appended marker', x => x.events.push(structuredClone(x.events[2]))],
    ['conflicting version', x => x.events[2].payload.version = 2],
    ['invalid range', x => x.events[2].payload.min_supported = 2],
    ['activity shifted', x => x.events[2 + count].payload.sequence++],
    ['missing clock', x => delete x.patch_deployment.replacement.embedded_clock_probe],
    ['changed second clock', x => x.patch_deployment.replacement.embedded_clock_probe.clocks[0][1] = '2026-01-01T00:00:09.123456Z'],
    ['clock precision lost', x => x.patch_deployment.replacement.embedded_clock_probe.clocks[0][0] = '2026-01-01T00:00:02.123Z'],
  ]) test(`${id}: rejects ${name}`, () => {
    const raw = model(); mutate(raw);
    assert.throws(() => checkLegacyMarkerDeployment(fixture, raw, 'test'));
  });
  for (const phase of ['original', 'replacement']) for (const field of ['workflow_package', 'workflow_source', 'embedded_composer_lock_sha256', 'workflow_loaded_sources']) {
    test(`${id} ${phase}: rejects missing ${field}`, () => {
      const raw = model(); delete raw.patch_deployment[phase][field];
      assert.throws(() => checkLegacyMarkerPackageObservation(fixture, raw, artifacts));
    });
  }
  test(`${id}: rejects a retargeted published archive`, () => {
    const changed = structuredClone(artifacts); changed.published_sdk_artifacts.rust.archive_sha256 = '0'.repeat(64);
    assert.throws(() => checkLegacyMarkerArtifacts(fixture, changed));
  });
  test(`${id}: rejects a fabricated HTTP author clock`, () => {
    const raw = model('http'); raw.embedded_clock_probe = {clocks: []};
    assert.throws(() => checkLegacyMarkerDeployment(fixture, raw, 'test'));
  });
  test(`${id}: rejects an unexpected sticky affinity request`, () => {
    const raw = model('http');
    raw.patch_deployment.original.requests.find(request => request.path.endsWith('/complete')).request.sticky_cache = {ttl_seconds: 1};
    assert.throws(() => checkLegacyMarkerDeployment(fixture, raw, 'test'));
  });
  function queryCapacityModel(phase, worker = 'test:'+phase) {
    const raw = model('http'), requests = raw.patch_deployment[phase].requests;
    const position = requests.findIndex(request => request.method === 'DELETE' && request.response.worker_id === worker);
    const [withdrawal] = requests.splice(position, 1);
    const refusal = {method: 'POST', path: '/api/worker/query-tasks/poll', status: 429,
      request: {worker_id: worker, task_queue: 'server-parity-v1', poll_request_id: 'original-query-poll', timeout_seconds: 1},
      response: {task: null, poll_status: 'long_poll_capacity_exhausted', reason: 'long_poll_capacity_exhausted', message: 'Retry after the delay'},
      response_retry_after: '1', response_encoding: 'identity', transport_error: null, client_cancelled: false};
    refusal.request_body_complete = true;
    refusal.upstream_request_started = true;
    requests.push(refusal, withdrawal);
    return {raw, requests, refusal, withdrawal};
  }
  for (const phase of ['original', 'replacement']) {
    test(`${id} ${phase}: accepts declared query capacity then own commit and withdrawal`, () => {
      const {raw, requests, refusal} = queryCapacityModel(phase);
      const position = requests.findIndex(request => request.path.endsWith('/complete') && request.request.lease_owner === 'test:'+phase);
      const [commit] = requests.splice(position, 1);
      requests.splice(requests.indexOf(refusal) + 1, 0, commit);
      checkLegacyMarkerDeployment(fixture, raw, 'test');
    });
    test(`${id} ${phase}: accepts identical query capacity recovery`, () => {
      const {raw, requests, refusal} = queryCapacityModel(phase);
      requests.splice(requests.indexOf(refusal) + 1, 0, {...structuredClone(refusal), status: 200, response_retry_after: null, response: {task: null}});
      checkLegacyMarkerDeployment(fixture, raw, 'test');
    });
    for (const [name, mutate] of [
      ['cancelled HTTP response', x => x.refusal.client_cancelled = true],
      ['transport failure', x => x.refusal.transport_error = 'connection reset'],
      ['completion capacity', x => x.refusal.path = '/api/worker/query-tasks/task/complete'],
      ['HTTP authentication refusal', x => x.refusal.status = 401],
      ['leased task', x => x.refusal.response.task = {task_id: 'lost-task'}],
      ['missing null task', x => delete x.refusal.response.task],
      ['wrong poll status', x => x.refusal.response.poll_status = 'backend_lock_pressure'],
      ['wrong reason', x => x.refusal.response.reason = 'unauthorized'],
      ['missing message', x => delete x.refusal.response.message],
      ['unknown summary field', x => x.refusal.response.accepted = true],
      ['zero retry hint', x => x.refusal.response_retry_after = '0'],
      ['long retry hint', x => x.refusal.response_retry_after = '2'],
      ['missing retry hint', x => x.refusal.response_retry_after = null],
      ['foreign queue', x => x.refusal.request.task_queue = 'other-queue'],
      ['foreign worker', x => x.refusal.request.worker_id = 'unregistered-worker'],
      ['empty poll identity', x => x.refusal.request.poll_request_id = ''],
      ['foreign withdrawal acknowledgement', x => x.withdrawal.response.worker_id = 'other-worker'],
      ['withdrawal before own commit', x => {x.requests.splice(x.requests.indexOf(x.withdrawal), 1); x.requests.unshift(x.withdrawal);}],
    ]) test(`${id} ${phase}: rejects query ${name}`, () => {
      const state = queryCapacityModel(phase); mutate(state);
      assert.throws(() => checkLegacyMarkerDeployment(fixture, state.raw, 'test'));
    });
  }
  if (spec.checkpoint === 'activity_completed') {
    test(`${id}: accepts actual activity-only worker query capacity after its activity commit`, () => {
      const state = queryCapacityModel('original', 'test:original-activity');
      checkLegacyMarkerDeployment(fixture, state.raw, 'test');
    });
    test(`${id}: rejects activity-only withdrawal backed by another worker's commit`, () => {
      const state = queryCapacityModel('original', 'test:original-activity');
      state.requests.find(request => request.path.startsWith('/api/worker/activity-tasks/') && request.path.endsWith('/complete')).request.lease_owner = 'test:original';
      assert.throws(() => checkLegacyMarkerDeployment(fixture, state.raw, 'test'));
    });
  }
  for (const phase of ['original', 'replacement']) for (const kind of ['workflow', 'activity', 'query']) {
    function capacityRecoveryModel(freshIdentity = true) {
      const state = queryCapacityModel(phase);
      state.refusal.path = '/api/worker/'+kind+'-tasks/poll';
      const recovery = {...structuredClone(state.refusal), status: 200, response_retry_after: null, response: {task: null}};
      if (freshIdentity) recovery.request.poll_request_id = 'fresh-admitted-poll';
      state.requests.splice(state.requests.indexOf(state.refusal) + 1, 0, recovery);
      return {...state, recovery};
    }
    for (const freshIdentity of [true, false]) test(`${id} ${phase} ${kind}: accepts no-task admission recovery with ${freshIdentity ? 'fresh' : 'original'} poll identity`, () => {
      checkLegacyMarkerDeployment(fixture, capacityRecoveryModel(freshIdentity).raw, 'test');
    });
    for (const [name, mutate] of [
      ['foreign recovered worker', x => x.recovery.request.worker_id = 'other-worker'],
      ['foreign recovered queue', x => x.recovery.request.task_queue = 'other-queue'],
      ['changed timeout', x => x.recovery.request.timeout_seconds++],
      ['changed history page size', x => x.recovery.request.history_page_size = 20],
      ['empty recovered poll identity', x => x.recovery.request.poll_request_id = ''],
      ['missing recovered poll identity', x => delete x.recovery.request.poll_request_id],
      ['different recovered path', x => x.recovery.path = '/api/worker/other-tasks/poll'],
      ['cancelled recovery', x => x.recovery.client_cancelled = true],
      ['failed recovery', x => x.recovery.status = 500],
      ['recovery transport failure', x => x.recovery.transport_error = 'ECONNRESET'],
      ['refusal contains a task', x => x.refusal.response.task = {task_id: 'lost-task'}],
      ['refusal has another reason', x => x.refusal.response.reason = 'unauthorized'],
    ]) test(`${id} ${phase} ${kind}: rejects capacity ${name}`, () => {
      const state = capacityRecoveryModel();
      // Disable the separate idle-shutdown alternative by placing withdrawal
      // before refusal, so these mutations isolate recovery evidence.
      state.requests.splice(state.requests.indexOf(state.withdrawal), 1);
      state.requests.splice(state.requests.indexOf(state.refusal), 0, state.withdrawal);
      mutate(state);
      assert.throws(() => checkLegacyMarkerDeployment(fixture, state.raw, 'test'));
    });
    function idleShutdownModel() {
      const state = capacityRecoveryModel();
      state.requests.splice(state.requests.indexOf(state.recovery), 1);
      return state;
    }
    test(`${id} ${phase} ${kind}: accepts an idle refusal then the worker's own withdrawal after its commit`, () => {
      checkLegacyMarkerDeployment(fixture, idleShutdownModel().raw, 'test');
    });
    for (const [name, mutate] of [
      ['no withdrawal after the refusal', x => {
        x.requests.splice(x.requests.indexOf(x.withdrawal), 1);
        x.requests.splice(x.requests.indexOf(x.refusal), 0, x.withdrawal);
      }],
      ['withdrawal before its own commit', x => {x.requests.splice(x.requests.indexOf(x.withdrawal), 1); x.requests.unshift(x.withdrawal);}],
      ['another worker withdraws', x => x.withdrawal.response.worker_id = 'other-worker'],
    ]) test(`${id} ${phase} ${kind}: rejects idle refusal with ${name}`, () => {
      const state = idleShutdownModel(); mutate(state);
      assert.throws(() => checkLegacyMarkerDeployment(fixture, state.raw, 'test'));
    });
  }
  for (const phase of ['original', 'replacement']) for (const kind of ['workflow', 'activity', 'query']) {
    function cancelledModel() {
      const raw = model('http');
      const requests = raw.patch_deployment[phase].requests;
      const withdrawal = requests.find(item => item.method === 'DELETE' && item.response.worker_id === 'test:'+phase);
      requests.splice(requests.indexOf(withdrawal), 1);
      requests.push({method: 'POST', path: '/api/worker/'+kind+'-tasks/poll',
        request: {worker_id: 'test:'+phase, task_queue: 'server-parity-v1'}, status: 0,
        request_body_complete: true, upstream_request_started: true,
        transport_error: null, client_cancelled: true, response_encoding: 'identity', response_retry_after: null, response: []}, withdrawal);
      return raw;
    }
    test(`${id} ${phase}: accepts clean ${kind} poll cancellation after commit`, () => {
      checkLegacyMarkerDeployment(fixture, cancelledModel(), 'test');
    });
    for (const [name, mutate] of [
      ['cancelled completion', x => x.path = '/api/worker/'+kind+'-tasks/task/complete'],
      ['cancelled fail', x => x.path = '/api/worker/'+kind+'-tasks/task/fail'],
      ['transport failure', x => x.transport_error = 'connection reset'],
      ['HTTP failure', x => x.status = 500],
      ['foreign worker', x => x.request.worker_id = 'other-worker'],
      ['foreign queue', x => x.request.task_queue = 'other-queue'],
      ['response body', x => x.response = {task: 'lost-task'}],
      ['response compression', x => x.response_encoding = 'gzip'],
      ['retry hint', x => x.response_retry_after = '1'],
    ]) test(`${id} ${phase} ${kind}: rejects ${name}`, () => {
      const raw = cancelledModel(); mutate(raw.patch_deployment[phase].requests.at(-2));
      assert.throws(() => checkLegacyMarkerDeployment(fixture, raw, 'test'));
    });
    test(`${id} ${phase} ${kind}: rejects cancellation before accepted commit`, () => {
      const raw = cancelledModel(), requests = raw.patch_deployment[phase].requests;
      requests.unshift(...requests.splice(requests.length - 2, 1));
      assert.throws(() => checkLegacyMarkerDeployment(fixture, raw, 'test'));
    });
    function unsentModel() {
      const raw = model('http'), requests = raw.patch_deployment[phase].requests;
      const withdrawals = requests.filter(request => request.method === 'DELETE');
      for (const withdrawal of withdrawals) requests.splice(requests.indexOf(withdrawal), 1);
      const cancellation = {method: 'POST', path: '/api/worker/'+kind+'-tasks/poll', request: null,
        status: 0, request_body_complete: false, upstream_request_started: false, transport_error: null,
        client_cancelled: true, response_encoding: 'identity', response_retry_after: null, response: []};
      requests.push(cancellation, ...withdrawals);
      return {raw, requests, cancellation, withdrawals};
    }
    test(`${id} ${phase} ${kind}: accepts explicitly unforwarded cancellation after real commit`, () => {
      checkLegacyMarkerDeployment(fixture, unsentModel().raw, 'test');
    });
    for (const [name, mutate] of [
      ['missing upstream state', x => delete x.cancellation.upstream_request_started],
      ['missing body state', x => delete x.cancellation.request_body_complete],
      ['forwarded incomplete body', x => x.cancellation.upstream_request_started = true],
      ['complete unforwarded body', x => x.cancellation.request_body_complete = true],
      ['invented worker authority', x => x.cancellation.request = {worker_id: 'test:'+phase}],
      ['noncancelled request', x => x.cancellation.client_cancelled = false],
      ['HTTP failure', x => x.cancellation.status = 500],
      ['transport failure', x => x.cancellation.transport_error = 'ECONNRESET'],
      ['completion path', x => x.cancellation.path = '/api/worker/'+kind+'-tasks/task/complete'],
      ['failure path', x => x.cancellation.path = '/api/worker/'+kind+'-tasks/task/fail'],
      ['foreign path', x => x.cancellation.path = '/api/unknown/poll'],
      ['wrong method', x => x.cancellation.method = 'GET'],
      ['response body', x => x.cancellation.response = {task: 'lost-task'}],
      ['compressed response', x => x.cancellation.response_encoding = 'gzip'],
      ['retry hint', x => x.cancellation.response_retry_after = '1'],
      ['cancellation before commit', x => {x.requests.splice(x.requests.indexOf(x.cancellation), 1); x.requests.unshift(x.cancellation);}],
      ['withdrawal before own commit', x => {for (const withdrawal of x.withdrawals) x.requests.splice(x.requests.indexOf(withdrawal), 1); x.requests.unshift(...x.withdrawals);}],
    ]) test(`${id} ${phase} ${kind}: rejects unsent ${name}`, () => {
      const state = unsentModel(); mutate(state);
      assert.throws(() => checkLegacyMarkerDeployment(fixture, state.raw, 'test'));
    });
  }
  for (const workerRole of ['original', ...(spec.checkpoint === 'activity_completed' ? ['original-activity'] : []), 'replacement']) {
    const phase = workerRole === 'replacement' ? 'replacement' : 'original';
    for (const kind of ['workflow', 'activity', 'query']) for (const forwarded of [true, false]) {
      function checkpointModel() {
        const raw = model('http'), selected = raw.patch_deployment[phase], worker = 'test:'+workerRole;
        const previous = selected.requests;
        const registration = previous.find(item => item.method === 'POST' && item.path === '/api/worker/register' && item.request.worker_id === worker);
        const commit = previous.find(item => item.path.startsWith('/api/worker/'+(workerRole === 'original-activity' ? 'activity' : 'workflow')+'-tasks/')
          && item.path.endsWith('/complete') && item.request.lease_owner === worker);
        const withdrawal = previous.find(item => item.method === 'DELETE' && item.response.worker_id === worker);
        const eventType = workerRole === 'original' ? 'ActivityScheduled' : workerRole === 'original-activity' ? 'ActivityCompleted' : 'WorkflowCompleted';
        const checkpoint = {method: 'GET', path: '/api/workflows/test/runs/original-run/history', request: {}, status: 200,
          client_cancelled: false, transport_error: null, request_body_complete: true, upstream_request_started: true,
          response: {workflow_id: 'test', run_id: 'original-run', events: structuredClone(selected.events.slice(0,
            selected.events.findIndex(event => event.event_type === eventType) + 1).map(({decoded, typed_decoded, ...event}) => event))}};
        const cancellation = {method: 'POST', path: '/api/worker/'+kind+'-tasks/poll',
          request: forwarded ? {worker_id: worker, task_queue: 'server-parity-v1'} : null,
          status: 0, request_body_complete: forwarded, upstream_request_started: forwarded, transport_error: null,
          client_cancelled: true, response_encoding: 'identity', response_retry_after: null, response: []};
        // Other workers finish independently; this worker's ACK arrives after
        // its persisted checkpoint triggers idle-poll shutdown.
        const others = previous.filter(item => ![registration, commit, withdrawal].includes(item));
        const otherRegistrations = others.filter(item => item.path === '/api/worker/register');
        const otherCommits = others.filter(item => item.path.endsWith('/complete'));
        const otherWithdrawals = others.filter(item => item.method === 'DELETE');
        selected.requests = [...otherRegistrations, ...otherCommits, ...otherWithdrawals,
          registration, checkpoint, cancellation, commit, withdrawal];
        return {raw, requests: selected.requests, checkpoint, cancellation, commit, withdrawal, registration};
      }
      const label = `${id} ${workerRole} ${kind} ${forwarded ? 'forwarded' : 'unsent'}`;
      test(`${label}: accepts exact persisted checkpoint before cancellation and delayed own ACK`, () => {
        checkLegacyMarkerDeployment(fixture, checkpointModel().raw, 'test');
      });
      for (const [name, mutate] of [
        ['missing checkpoint', x => x.requests.splice(x.requests.indexOf(x.checkpoint), 1)],
        ['late checkpoint', x => {x.requests.splice(x.requests.indexOf(x.checkpoint), 1); x.requests.splice(x.requests.indexOf(x.cancellation) + 1, 0, x.checkpoint);}],
        ['foreign path run', x => x.checkpoint.path = '/api/workflows/test/runs/foreign-run/history'],
        ['foreign body run', x => x.checkpoint.response.run_id = 'foreign-run'],
        ['foreign body workflow', x => x.checkpoint.response.workflow_id = 'foreign-workflow'],
        ['missing events', x => delete x.checkpoint.response.events],
        ['empty events', x => x.checkpoint.response.events = []],
        ['changed checkpoint type', x => x.checkpoint.response.events.at(-1).event_type = 'ActivityStarted'],
        ['foreign checkpoint task', x => x.checkpoint.response.events.at(-1).payload.task.id = 'foreign-task'],
        ['foreign checkpoint owner', x => x.checkpoint.response.events.at(-1).payload.task.lease_owner = 'foreign-worker'],
        ['changed retained clock', x => x.checkpoint.response.events.at(-1).timestamp = '2026-01-02T00:00:00.123456Z'],
        ['changed earlier prefix', x => x.checkpoint.response.events[0].payload.extra = 'changed'],
        ['failed checkpoint response', x => x.checkpoint.status = 503],
        ['cancelled checkpoint', x => x.checkpoint.client_cancelled = true],
        ['checkpoint transport error', x => x.checkpoint.transport_error = 'ECONNRESET'],
        ['rejected own commit', x => x.commit.response.recorded = false],
        ['missing own acceptance', x => delete x.commit.response.recorded],
        ['foreign own commit', x => x.commit.request.lease_owner = 'foreign-worker'],
        ['withdrawal before own ACK', x => {x.requests.splice(x.requests.indexOf(x.withdrawal), 1); x.requests.splice(x.requests.indexOf(x.commit), 0, x.withdrawal);}],
        ['withdrawal before cancellation', x => {x.requests.splice(x.requests.indexOf(x.withdrawal), 1); x.requests.splice(x.requests.indexOf(x.cancellation), 0, x.withdrawal);}],
        ['late registration', x => {x.requests.splice(x.requests.indexOf(x.registration), 1); x.requests.push(x.registration);}],
      ]) test(`${label}: rejects ${name}`, () => {
        const state = checkpointModel(); mutate(state);
        assert.throws(() => checkLegacyMarkerDeployment(fixture, state.raw, 'test'));
      });
      if (!forwarded && workerRole === 'original' && spec.checkpoint === 'activity_completed') test(`${label}: rejects another active worker lacking prior own commit proof`, () => {
        const state = checkpointModel();
        const otherCommit = state.requests.find(item => item.path.startsWith('/api/worker/activity-tasks/') && item.path.endsWith('/complete'));
        const otherWithdrawal = state.requests.find(item => item.method === 'DELETE' && item.response.worker_id === 'test:original-activity');
        state.requests.splice(state.requests.indexOf(otherCommit), 1);
        state.requests.splice(state.requests.indexOf(otherWithdrawal), 1);
        state.requests.push(otherCommit, otherWithdrawal);
        assert.throws(() => checkLegacyMarkerDeployment(fixture, state.raw, 'test'));
      });
    }
  }
}
