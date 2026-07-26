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

test('exact crates.io preparation shares one deadline and finishes offline', () => {
  let currentTime = 1_000;
  const calls = [];
  const preparation = prepareExactRustCrate({
    steps,
    timeoutMilliseconds: 240,
    clock: () => currentTime,
    execute(step) {
      calls.push({
        phase: step.phase,
        timeoutMilliseconds: step.timeoutMilliseconds,
        networkAccess: step.networkAccess,
      });
      currentTime += 20;
      return { stdout: step.phase === 'metadata' ? '{"packages":[]}' : '' };
    },
  });

  assert.deepEqual(
    calls.map((call) => call.timeoutMilliseconds),
    [240, 220, 200, 180],
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
