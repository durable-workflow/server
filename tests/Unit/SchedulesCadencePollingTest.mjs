import assert from 'node:assert/strict';
import test from 'node:test';

import {
  CadenceObservationInfrastructureError,
  cadenceBlockedEvidence,
  cadenceEvidenceFromObservations,
  observeCadence,
} from '../../scripts/conformance/schedules-published-artifacts.mjs';

const ARTIFACT_VERSIONS = { server: '2.0.0-beta.18' };
const ARTIFACT_SOURCES = { server: 'docker://durableworkflow/server:2.0.0-beta.18' };

function schedules() {
  return [
    {
      kind: 'cron',
      scenarioId: 'cron_cadence',
      scheduleId: 'cadence-cron',
      minimumObservedFires: 2,
      expectedIntervalMs: 60000,
      cron_expression: '* * * * *',
    },
    {
      kind: 'fixed_rate',
      scenarioId: 'fixed_rate_cadence',
      scheduleId: 'cadence-fixed-rate',
      minimumObservedFires: 2,
      expectedIntervalMs: 30000,
      interval: 'PT30S',
    },
  ];
}

function history(scheduleId, count) {
  const intervalMs = scheduleId === 'cadence-cron' ? 60000 : 30000;
  const startedAt = Date.parse('2026-07-27T08:00:00Z');

  return {
    events: Array.from({ length: count }, (_, index) => {
      const occurredAt = new Date(startedAt + intervalMs * index).toISOString();
      return {
        event_type: 'ScheduleTriggered',
        recorded_at: occurredAt,
        payload: { occurrence_time: occurredAt },
      };
    }),
  };
}

function deterministicTime() {
  let current = 0;
  return {
    now: () => current,
    wait: async (milliseconds) => {
      current += milliseconds;
    },
  };
}

test('cadence polling preserves successful reads and continues after a transient transport failure', async () => {
  const calls = new Map();
  const time = deterministicTime();
  const observations = await observeCadence({
    serverUrl: 'http://published-server.test',
    token: 'test-token',
    namespace: 'schedules-conformance',
    schedules: schedules(),
    timeoutSeconds: 10,
    pollSeconds: 1,
    driftToleranceMs: 1000,
    intervalToleranceMs: 1000,
    transportFailureBudget: 2,
    artifactVersions: ARTIFACT_VERSIONS,
    artifactSources: ARTIFACT_SOURCES,
    now: time.now,
    wait: time.wait,
    historyReader: async (_serverUrl, _token, _namespace, scheduleId) => {
      const call = (calls.get(scheduleId) ?? 0) + 1;
      calls.set(scheduleId, call);
      if (scheduleId === 'cadence-cron' && call === 2) {
        throw new TypeError('fetch failed');
      }

      return history(scheduleId, call === 1 ? 1 : 2);
    },
  });

  const evidence = cadenceEvidenceFromObservations({
    observations,
    startedAt: '2026-07-27T08:00:00Z',
    finishedAt: '2026-07-27T08:01:00Z',
    artifactVersions: ARTIFACT_VERSIONS,
    artifactSources: ARTIFACT_SOURCES,
    namespace: 'schedules-conformance',
    taskQueue: 'schedules-cadence',
    schedulesCreated: ['cadence-cron', 'cadence-fixed-rate'],
  });

  assert.equal(calls.get('cadence-cron'), 3);
  assert.equal(observations.cron_cadence.successful_history_read_count, 2);
  assert.equal(observations.cron_cadence.transient_transport_failure_count, 1);
  assert.equal(observations.cron_cadence.observed_fire_count, 2);
  assert.equal(observations.fixed_rate_cadence.observed_fire_count, 2);
  assert.equal(evidence.scenario_results.cron_cadence.status, 'pass');
  assert.equal(evidence.scenario_results.fixed_rate_cadence.status, 'pass');
  assert.deepEqual(evidence.findings, []);
});

test('persistent cadence transport loss is conformance infrastructure evidence with partial reads retained', async () => {
  const calls = new Map();
  const time = deterministicTime();
  let failure;

  await assert.rejects(
    observeCadence({
      serverUrl: 'http://published-server.test',
      token: 'test-token',
      namespace: 'schedules-conformance',
      schedules: schedules(),
      timeoutSeconds: 10,
      pollSeconds: 1,
      driftToleranceMs: 1000,
      intervalToleranceMs: 1000,
      transportFailureBudget: 2,
      artifactVersions: ARTIFACT_VERSIONS,
      artifactSources: ARTIFACT_SOURCES,
      now: time.now,
      wait: time.wait,
      historyReader: async (_serverUrl, _token, _namespace, scheduleId) => {
        const call = (calls.get(scheduleId) ?? 0) + 1;
        calls.set(scheduleId, call);
        if (call > 1) {
          throw new TypeError('fetch failed');
        }

        return history(scheduleId, 1);
      },
    }),
    (error) => {
      failure = error;
      return error instanceof CadenceObservationInfrastructureError;
    },
  );

  assert.match(failure.message, /after 3 consecutive transport failures \(retry budget 2\)/);
  const evidence = cadenceBlockedEvidence(
    failure.message,
    '2026-07-27T08:00:00Z',
    ARTIFACT_VERSIONS,
    ARTIFACT_SOURCES,
    failure.observations,
  );

  for (const scenarioId of ['cron_cadence', 'fixed_rate_cadence']) {
    const scenario = evidence.scenario_results[scenarioId];
    assert.equal(scenario.status, 'runner_blocked');
    assert.equal(scenario.observed_outputs.observed_fire_count, 1);
    assert.equal(scenario.observed_outputs.successful_history_read_count, 1);
    assert.equal(scenario.observed_outputs.transient_transport_failure_count, 3);
    assert.equal(scenario.linked_findings[0].finding_type, 'conformance_runner_blocked');
    assert.equal(scenario.linked_findings[0].owning_surface, 'conformance_harness');
  }
  assert.equal(
    evidence.findings.some((finding) => finding.finding_type === 'schedule_cadence_contract_gap'),
    false,
  );
});
