import assert from 'node:assert/strict';
import test from 'node:test';
import {
  prepareExactRustCrate,
  RustCratesIoPreparationTimeoutError,
} from '../../scripts/conformance/heartbeat-rust-preparation.mjs';

const steps = [
  {
    phase: 'lockfile_resolution',
    cargoArguments: ['generate-lockfile'],
    networkAccess: true,
  },
  {
    phase: 'crate_download',
    cargoArguments: ['fetch', '--locked'],
    networkAccess: true,
  },
  {
    phase: 'metadata',
    cargoArguments: ['metadata', '--locked', '--format-version=1'],
    networkAccess: false,
  },
  {
    phase: 'release_build',
    cargoArguments: ['build', '--release', '--locked'],
    networkAccess: false,
  },
];

test('a cold exact crates.io preparation finishes offline near the enlarged budget', () => {
  let currentTime = 1_000;
  const calls = [];
  const phaseDurations = {
    lockfile_resolution: 3_000,
    crate_download: 14_000,
    metadata: 1_533,
    release_build: 340_000,
  };
  const preparation = prepareExactRustCrate({
    steps,
    timeoutMilliseconds: 360_000,
    clock: () => currentTime,
    execute(step) {
      calls.push({
        phase: step.phase,
        timeoutMilliseconds: step.timeoutMilliseconds,
        networkAccess: step.networkAccess,
      });
      currentTime += phaseDurations[step.phase];
      return { stdout: step.phase === 'metadata' ? '{"packages":[]}' : '' };
    },
  });

  assert.deepEqual(
    calls.map((call) => call.timeoutMilliseconds),
    [360_000, 357_000, 343_000, 341_467],
  );
  assert.deepEqual(
    calls.map((call) => call.networkAccess),
    [true, true, false, false],
  );
  assert.deepEqual(
    preparation.evidence.completed_phases,
    ['lockfile_resolution', 'crate_download', 'metadata', 'release_build'],
  );
  assert.equal(
    preparation.evidence.network_access_completed_before_offline_metadata_and_build,
    true,
  );
  assert.equal(preparation.results.metadata.stdout, '{"packages":[]}');
  assert.equal(preparation.evidence.elapsed_ms, 358_533);
});

test('a registry stall becomes structured runner-blocked evidence before later phases start', () => {
  let currentTime = 2_000;
  const calls = [];

  assert.throws(
    () => prepareExactRustCrate({
      steps,
      timeoutMilliseconds: 240,
      clock: () => currentTime,
      execute(step) {
        calls.push(step.phase);
        if (step.phase === 'lockfile_resolution') {
          currentTime += 40;
          return { stdout: '' };
        }
        currentTime += 200;
        const error = new Error('registry request timed out');
        error.timedOut = true;
        throw error;
      },
    }),
    (error) => {
      assert.ok(error instanceof RustCratesIoPreparationTimeoutError);
      assert.equal(error.runnerBlocked, true);
      assert.equal(error.phase, 'crate_download');
      assert.equal(error.timeoutMilliseconds, 240);
      assert.equal(error.elapsedMilliseconds, 240);
      assert.deepEqual(error.completedPhases, ['lockfile_resolution']);
      return true;
    },
  );
  assert.deepEqual(calls, ['lockfile_resolution', 'crate_download']);
});
