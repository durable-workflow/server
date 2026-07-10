import assert from 'node:assert/strict';
import test from 'node:test';

import { heartbeatCadenceObservation } from '../../scripts/conformance/heartbeat-cadence-observation.mjs';

test('PHP cadence uses persisted server observations when one tick returns batched heartbeats', () => {
  const observation = heartbeatCadenceObservation({
    cell: 'php',
    advertisedSeconds: 2,
    heartbeatRecords: [
      {
        event: 'worker_heartbeat',
        tick_phase: 'pre_poll',
        observed_at: '2026-07-10T14:05:42.139Z',
        acknowledgement: { accepted: true, sequence: 1 },
      },
      {
        event: 'worker_heartbeat',
        tick_phase: 'post_poll',
        observed_at: '2026-07-10T14:05:42.140Z',
        acknowledgement: { accepted: true, sequence: 2 },
      },
    ],
    serverHeartbeatTimestamps: [
      '2026-07-10T14:05:40.000Z',
      '2026-07-10T14:05:42.000Z',
    ],
  });

  assert.equal(observation.sdk_heartbeat_acknowledgement_count, 2);
  assert.equal(observation.cadence_observation_source, 'server_persisted_last_heartbeat_at');
  assert.deepEqual(observation.cadence_heartbeat_timestamps, [
    '2026-07-10T14:05:40.000Z',
    '2026-07-10T14:05:42.000Z',
  ]);
  assert.deepEqual(observation.worker_log_observation_timestamps, [
    '2026-07-10T14:05:42.139Z',
    '2026-07-10T14:05:42.140Z',
  ]);
  assert.deepEqual(observation.inter_arrival_seconds, [2]);
  assert.equal(observation.bounded_advertised_cadence, true);
});

for (const cell of ['python', 'rust']) {
  test(`${cell} cadence keeps its native acknowledgement timestamps`, () => {
    const observation = heartbeatCadenceObservation({
      cell,
      advertisedSeconds: 2,
      heartbeatRecords: [
        { observed_at: '2026-07-10T14:05:40.250Z', acknowledgement: { accepted: true } },
        { observed_at: '2026-07-10T14:05:42.250Z', acknowledgement: { accepted: true } },
      ],
      serverHeartbeatTimestamps: [
        '2026-07-10T14:05:40.000Z',
        '2026-07-10T14:05:43.000Z',
      ],
    });

    assert.equal(observation.cadence_observation_source, 'sdk_native_acknowledgement_timestamp');
    assert.deepEqual(observation.cadence_heartbeat_timestamps, [
      '2026-07-10T14:05:40.250Z',
      '2026-07-10T14:05:42.250Z',
    ]);
    assert.deepEqual(observation.inter_arrival_seconds, [2]);
  });
}
