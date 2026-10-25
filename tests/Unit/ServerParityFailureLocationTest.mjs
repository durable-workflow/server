import assert from 'node:assert/strict';
import test from 'node:test';
import {contractFailureLocation} from '../../scripts/conformance/server-parity/failure-location.mjs';
import {checkSdkQueryDirection} from '../../scripts/conformance/server-parity/sdk-query-direction-contract.mjs';

test('a contract refusal names its source file and line', () => {
  let refusal;
  try {
    checkSdkQueryDirection({sdk_query_direction: {}}, {mode: 'http', sdk_query_direction: {applicable: true}}, 'w');
  } catch (error) { refusal = error; }
  assert.ok(refusal, 'the malformed observation is refused');
  assert.match(contractFailureLocation(refusal), /^scripts\/conformance\/server-parity\/[a-z-]+\.mjs:\d+$/);
});

test('container and file URL frames resolve to the same repository path', () => {
  for (const frame of ['    at check (/source/scripts/conformance/server-parity/x-contract.mjs:42:7)',
    '    at check (file:///source/scripts/conformance/server-parity/x-contract.mjs:42:7)',
    '    at file:///home/runner/work/server/server/scripts/conformance/server-parity/x-contract.mjs:42:7']) {
    assert.equal(contractFailureLocation({stack: 'AssertionError: false !== true\n' + frame}),
      'scripts/conformance/server-parity/x-contract.mjs:42');
  }
});

test('a failure outside the contracts has no location rather than a guess', () => {
  assert.equal(contractFailureLocation({stack: 'Error: x\n    at node:internal/process/task_queues:95:5'}), null);
  assert.equal(contractFailureLocation('not an error'), null);
  assert.equal(contractFailureLocation(undefined), null);
});
