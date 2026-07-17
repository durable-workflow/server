import assert from 'node:assert/strict';
import test from 'node:test';

import {
  cliControlPlaneTransportError,
  ControlPlaneTransportError,
  FinalVisibilityInvariantError,
  PersistentFinalVisibilityTransportError,
  classifyPersistentTransportOwner,
  persistentTransportEvidence,
  recoverFinalVisibility,
} from '../../scripts/conformance/heartbeat-final-visibility.mjs';

function refusedRequest(attempt) {
  const connection = Object.assign(new Error('connect ECONNREFUSED 127.0.0.1:48123'), {
    code: 'ECONNREFUSED',
    syscall: 'connect',
    address: '127.0.0.1',
    port: 48123,
  });
  return new ControlPlaneTransportError(
    'GET',
    `http://127.0.0.1:48123/api/workers?attempt=${attempt}`,
    new TypeError('fetch failed', { cause: connection }),
    `2026-07-17T10:39:2${attempt}Z`,
  );
}

function exactVisibility() {
  return {
    api_stale: true,
    api_fresh: true,
    cli_stale: true,
    cli_fresh: true,
  };
}

function failedVisibilityInvariants(visibility) {
  return Object.entries(visibility)
    .filter(([, observed]) => observed !== true)
    .map(([invariant]) => invariant);
}

test('bounded recovery passes only after the exact final visibility invariants are observed', async () => {
  let calls = 0;
  const result = await recoverFinalVisibility({
    capture: async () => {
      calls += 1;
      if (calls === 1) throw refusedRequest(calls);
      return exactVisibility();
    },
    validate: failedVisibilityInvariants,
    maxAttempts: 3,
    retryDelayMs: 1,
    wait: async () => {},
    observedAt: () => '2026-07-17T10:39:30Z',
  });

  assert.equal(calls, 2);
  assert.equal(result.recovery.outcome, 'recovered');
  assert.equal(result.recovery.invariants_observed, true);
  assert.deepEqual(result.recovery.attempts.map((attempt) => attempt.outcome), [
    'transport_error',
    'success',
  ]);
});

test('a recovered transport cannot skip or manufacture missing product assertions', async () => {
  let calls = 0;
  await assert.rejects(
    recoverFinalVisibility({
      capture: async () => {
        calls += 1;
        if (calls === 1) throw refusedRequest(calls);
        return { ...exactVisibility(), cli_stale: false };
      },
      validate: failedVisibilityInvariants,
      maxAttempts: 3,
      retryDelayMs: 1,
      wait: async () => {},
    }),
    (error) => {
      assert.ok(error instanceof FinalVisibilityInvariantError);
      assert.deepEqual(error.failedInvariants, ['cli_stale']);
      return true;
    },
  );
  assert.equal(calls, 2);
});

test('persistent transport loss retains the request cause, completed behavior, and healthy server diagnostics as a runner gap', async () => {
  let persistentError;
  try {
    await recoverFinalVisibility({
      capture: async () => {
        throw refusedRequest(1);
      },
      validate: failedVisibilityInvariants,
      maxAttempts: 3,
      retryDelayMs: 1,
      wait: async () => {},
    });
    assert.fail('persistent transport loss must remain non-passing');
  } catch (error) {
    assert.ok(error instanceof PersistentFinalVisibilityTransportError);
    persistentError = error;
  }

  const serverDiagnostics = {
    container: {
      present: true,
      state: {
        status: 'running',
        running: true,
        restarting: false,
        exit_code: 0,
        health: { status: 'healthy' },
      },
      restart_count: 0,
    },
    logs: { artifact: 'server-container.log', tail: 'server remained healthy' },
    readiness_probe: { ok: true, status: 200 },
  };
  const completedBehavior = {
    all_checks_passed: true,
    checks: {
      stale_worker_successive_heartbeats: true,
      stale_worker_excluded_from_routing: true,
      fresh_peer_completed_work_after_stale: true,
    },
    workflows: { before_stale: { status: 'completed' }, after_stale: { status: 'completed' } },
  };
  const retained = persistentTransportEvidence({
    error: persistentError,
    serverDiagnostics,
    completedBehavior,
  });

  assert.equal(retained.classification, 'runner-transport-gap');
  assert.equal(retained.owning_surface, 'conformance_harness');
  assert.equal(retained.runner_blocked, true);
  assert.equal(retained.final_visibility_transport.attempts.length, 3);
  assert.equal(retained.final_visibility_transport.failed_request.method, 'GET');
  assert.equal(retained.final_visibility_transport.underlying_connection_cause.code, 'ECONNREFUSED');
  assert.equal(retained.completed_behavior_before_final_visibility.all_checks_passed, true);
  assert.equal(retained.server_diagnostics.container.state.health.status, 'healthy');
  assert.equal(retained.server_diagnostics.logs.artifact, 'server-container.log');
});

test('an outage after successful API reads but before CLI visibility retains CLI transport diagnostics', async () => {
  const completedReads = [];
  const clusterInfoUrl = 'http://127.0.0.1:48123/api/cluster/info';
  const intendedWorkerListUrl = 'http://127.0.0.1:48123/api/workers?task_queue=heartbeat-conformance';
  const publishedCliError = `Server unreachable: Failed to connect to 127.0.0.1 port 48123 after 0 ms: Couldn't connect to server for "${clusterInfoUrl}".`;
  const publishedCliEnvelope = {
    error: publishedCliError,
    exit_code: 3,
    command: 'worker:list',
    namespace: 'heartbeats-conformance',
    recommendations: [{
      id: 'server.unreachable',
      severity: 'error',
      message: 'Check the server URL, DNS, port, and TLS settings for the selected environment.',
      command: 'dw doctor --server=http://127.0.0.1:48123 --output=json',
    }],
  };
  let persistentError;
  try {
    await recoverFinalVisibility({
      capture: async () => {
        completedReads.push('api-worker-list', 'api-stale-list', 'api-stale-detail', 'api-fresh-detail');
        const sample = {
          command: ['dw', 'worker:list', '--task-queue=heartbeat-conformance', '--output=json'],
          exit_code: 3,
          stdout: JSON.stringify(publishedCliEnvelope),
          stderr: '',
          output: publishedCliEnvelope,
        };
        throw cliControlPlaneTransportError({
          sample,
          method: 'GET',
          url: intendedWorkerListUrl,
          observedAt: '2026-07-17T10:39:26Z',
        });
      },
      validate: failedVisibilityInvariants,
      maxAttempts: 2,
      retryDelayMs: 1,
      wait: async () => {},
    });
    assert.fail('persistent CLI transport loss must remain non-passing');
  } catch (error) {
    assert.ok(error instanceof PersistentFinalVisibilityTransportError);
    persistentError = error;
  }

  assert.equal(completedReads.length, 8, 'each bounded attempt completed all four API reads first');
  assert.equal(persistentError.attempts.length, 2);
  assert.equal(persistentError.failedRequest.channel, 'cli');
  assert.equal(persistentError.failedRequest.method, 'GET');
  assert.equal(persistentError.failedRequest.url, clusterInfoUrl);
  assert.equal(persistentError.failedRequest.actual_request_source, 'cli_error_envelope');
  assert.deepEqual(persistentError.failedRequest.intended_command, [
    'dw',
    'worker:list',
    '--task-queue=heartbeat-conformance',
    '--output=json',
  ]);
  assert.deepEqual(persistentError.failedRequest.intended_request, {
    method: 'GET',
    url: intendedWorkerListUrl,
  });
  assert.equal(persistentError.failedRequest.cli_exit_code, 3);
  assert.deepEqual(persistentError.failedRequest.cli_error_envelope, publishedCliEnvelope);
  assert.equal(persistentError.attempts[0].failed_request.url, clusterInfoUrl);
  assert.equal(persistentError.attempts[0].failed_request.intended_request.url, intendedWorkerListUrl);

  const retained = persistentTransportEvidence({
    error: persistentError,
    serverDiagnostics: {
      container: {
        present: true,
        state: {
          status: 'running',
          running: true,
          restarting: false,
          exit_code: 0,
          health: { status: 'healthy' },
        },
        restart_count: 0,
      },
      logs: { artifact: 'server-container.log', tail: 'server remained healthy' },
    },
    completedBehavior: { all_checks_passed: true },
  });
  assert.equal(retained.classification, 'runner-transport-gap');
  assert.equal(retained.owning_surface, 'conformance_harness');
  assert.equal(retained.final_visibility_transport.failed_request.channel, 'cli');
  assert.equal(retained.final_visibility_transport.failed_request.url, clusterInfoUrl);
  assert.equal(
    retained.final_visibility_transport.failed_request.intended_request.url,
    intendedWorkerListUrl,
  );
  assert.equal(retained.final_visibility_transport.underlying_connection_cause.name, 'CliNetworkCause');
  assert.match(
    retained.final_visibility_transport.underlying_connection_cause.message,
    /Couldn't connect to server.*\/api\/cluster\/info/,
  );
  assert.equal(retained.server_diagnostics.logs.artifact, 'server-container.log');
});

test('non-transport CLI failures remain product invariant evidence', () => {
  const error = cliControlPlaneTransportError({
    sample: {
      command: ['dw', 'worker:describe', 'missing', '--output=json'],
      exit_code: 5,
      output: { error: 'worker not found', exit_code: 5 },
    },
    method: 'GET',
    url: 'http://127.0.0.1:48123/api/workers/missing',
  });

  assert.equal(error, null);
});

test('retained exited or unhealthy container state assigns one supported server owner', () => {
  for (const state of [
    {
      status: 'exited',
      running: false,
      restarting: false,
      exit_code: 137,
      health: { status: null },
    },
    {
      status: 'running',
      running: true,
      restarting: false,
      exit_code: 0,
      health: { status: 'unhealthy' },
    },
  ]) {
    const classification = classifyPersistentTransportOwner({
      container: { present: true, state, restart_count: 2 },
    });
    assert.equal(classification.classification, 'standalone-server-availability-gap');
    assert.equal(classification.owning_surface, 'server');
    assert.equal(classification.runner_blocked, false);
  }
});
