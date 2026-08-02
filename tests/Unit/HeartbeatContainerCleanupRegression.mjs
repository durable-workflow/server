import assert from 'node:assert/strict';
import test from 'node:test';
import { removeNamedDockerContainer } from '../../scripts/conformance/heartbeat-container-cleanup.mjs';

const present = {
  status: 0,
  stdout: '[{"Id":"container-id"}]',
  stderr: '',
};
const absent = {
  status: 1,
  stdout: '',
  stderr: 'Error: No such container: heartbeat-rust-worker',
};

test('removal already in progress passes after bounded retry and confirmed absence', () => {
  const inspections = [present, absent];
  let removalCalls = 0;
  let sleepCalls = 0;

  const result = removeNamedDockerContainer({
    containerName: 'heartbeat-rust-worker',
    initialInspection: present,
    inspect: () => inspections.shift(),
    remove() {
      removalCalls += 1;
      return {
        status: 1,
        stdout: '',
        stderr: removalCalls === 1
          ? 'Error response from daemon: removal of container heartbeat-rust-worker is already in progress'
          : 'Error: No such container: heartbeat-rust-worker',
      };
    },
    retryDelayMilliseconds: 250,
    sleep() {
      sleepCalls += 1;
    },
  });

  assert.equal(result.status, 'removed_asynchronously');
  assert.equal(result.absence_confirmed, true);
  assert.equal(result.asynchronous_removal, true);
  assert.equal(removalCalls, 2);
  assert.equal(sleepCalls, 1);
  assert.match(result.attempts[0].error, /removal .* already in progress/);
});

test('cleanup fails when bounded retries cannot establish final absence', () => {
  let removalCalls = 0;

  assert.throws(
    () => removeNamedDockerContainer({
      containerName: 'heartbeat-rust-worker',
      initialInspection: present,
      inspect: () => present,
      remove() {
        removalCalls += 1;
        return {
          status: 1,
          stdout: '',
          stderr: 'Error response from daemon: removal is already in progress',
        };
      },
      attempts: 3,
      retryDelayMilliseconds: 0,
    }),
    /still exists after 3 bounded removal attempts/,
  );
  assert.equal(removalCalls, 3);
});
