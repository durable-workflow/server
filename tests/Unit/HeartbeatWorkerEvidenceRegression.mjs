import assert from 'node:assert/strict';
import test from 'node:test';

import {
  captureWorkProcessedBaseline,
  MalformedWorkerLogError,
  parseWorkerLogSnapshot,
  waitForWorkProcessedAdvance,
  WorkerLogIdentityError,
  WorkProcessedEvidenceTimeoutError,
} from '../../scripts/conformance/heartbeat-worker-evidence.mjs';

const workerId = 'heartbeat-php-fresh-example';
const registered = JSON.stringify({ event: 'worker_registered', worker_id: workerId });
const processed = JSON.stringify({ event: 'work_processed' });

test('bounded polling observes work_processed after delayed Docker-log visibility', async () => {
  const baseline = captureWorkProcessedBaseline({ workerId, logOutput: `${registered}\n` });
  const delayedSnapshots = [
    `${registered}\n`,
    `${registered}\n`,
    `${registered}\n${processed}\n`,
  ];

  const immediate = parseWorkerLogSnapshot(delayedSnapshots[0], workerId);
  assert.equal(
    immediate.work_processed_records.length,
    baseline.work_processed_count,
    'the former immediate snapshot has no causal work_processed evidence',
  );

  let reads = 0;
  const observed = await waitForWorkProcessedAdvance({
    baseline,
    readLogs: async () => delayedSnapshots[reads++],
    maxAttempts: delayedSnapshots.length,
    retryDelayMs: 1,
    wait: async () => {},
    observedAt: () => '2026-08-01T12:00:00Z',
  });

  assert.equal(reads, 3);
  assert.equal(observed.baseline_count, 0);
  assert.equal(observed.observed_count, 1);
  assert.equal(observed.attempts, 3);
  assert.deepEqual(observed.new_records, [{ event: 'work_processed' }]);
});

test('missing causal work_processed evidence fails closed at the polling bound', async () => {
  const baseline = captureWorkProcessedBaseline({ workerId, logOutput: `${registered}\n` });

  await assert.rejects(
    waitForWorkProcessedAdvance({
      baseline,
      readLogs: async () => `${registered}\n`,
      maxAttempts: 2,
      retryDelayMs: 1,
      wait: async () => {},
    }),
    WorkProcessedEvidenceTimeoutError,
  );
});

test('malformed log records fail closed instead of being skipped', async () => {
  const baseline = captureWorkProcessedBaseline({ workerId, logOutput: `${registered}\n` });

  await assert.rejects(
    waitForWorkProcessedAdvance({
      baseline,
      readLogs: async () => `${registered}\n{"event":"work_processed"\n`,
      maxAttempts: 2,
      retryDelayMs: 1,
      wait: async () => {},
    }),
    MalformedWorkerLogError,
  );
});

test('a different worker registration cannot satisfy expected-worker evidence', async () => {
  const baseline = captureWorkProcessedBaseline({ workerId, logOutput: `${registered}\n` });
  const wrongWorkerLog = [
    JSON.stringify({ event: 'worker_registered', worker_id: 'heartbeat-php-other-example' }),
    processed,
    '',
  ].join('\n');

  await assert.rejects(
    waitForWorkProcessedAdvance({
      baseline,
      readLogs: async () => wrongWorkerLog,
      maxAttempts: 1,
      retryDelayMs: 0,
    }),
    WorkerLogIdentityError,
  );
});
