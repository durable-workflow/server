import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkCrossKindPollWake} from '../../scripts/conformance/server-parity/cross-kind-poll-contract.mjs';

const profile = JSON.parse(readFileSync(new URL('../../benchmarks/server-port/v2/matched.json', import.meta.url)));
function observation(kind) {
  const poll = {worker_id: 'original-worker', task_queue: 'original-queue', namespace_header: 'default', http: 200};
  return {
    outcome: 'observed', worker_exit: 0, kind, original_budget_seconds: 35, cross_kind_capability: true,
    sdk_version: profile.artifacts.sdk_php_version, sdk_source: profile.artifacts.sdk_php_source,
    adapter_sha256: profile.workload.adapter_sha256,
    worker_id: 'original-worker', task_queue: 'original-queue', workflow_id: 'original-workflow', run_id: 'original-run',
    case_started_at: 100, held_observed_at: 110, start_request_at: 111, start_accepted_at: 112, observed_at: 114,
    held_request_id: 2,
    polls: [
      {...poll, request_id: 2, path: `/api/worker/${kind}-tasks/poll`, requested_seconds: 5,
        started_at: 109.9, responded_at: 111.8, poll_status: 'task_queue_changed', task_is_null: true, task_id: null},
      ...(kind === 'activity' ? [{...poll, request_id: 3, path: '/api/worker/query-tasks/poll', requested_seconds: 0,
        started_at: 111.9, responded_at: 112, poll_status: 'empty', task_is_null: true, task_id: null}] : []),
      {...poll, request_id: 4, path: '/api/worker/workflow-tasks/poll', requested_seconds: 0,
        started_at: 112, responded_at: 112.1, poll_status: 'leased', task_is_null: false, task_id: 'original-task'},
    ],
    history_events: ['StartAccepted', 'WorkflowStarted', 'ActivityScheduled', 'ActivityStarted', 'ActivityCompleted', 'WorkflowCompleted'],
    output_bytes: 1024, output_sha256: '004ff2ad28c856e1e1eccdb7a0222f100eb2c26b6301a9f5968cdda899307987',
  };
}
for (const kind of ['activity', 'query']) test(`${kind} hint and separate workflow claim are required`, () => {
  assert.deepEqual(checkCrossKindPollWake(observation(kind), profile), {kind, wake: true});
});
const corruptions = {
  'completed work after a full empty wait': r => { r.polls[0].poll_status = 'timeout'; },
  'empty response without a wake': r => { r.polls[0].poll_status = 'empty'; },
  'no capability opt-in': r => { r.cross_kind_capability = false; },
  'hint claims a task': r => { r.polls[0].task_id = 'illicit-lease'; },
  'missing explicit null task': r => { delete r.polls[0].task_is_null; },
  'hint belongs to another queue': r => { r.polls[0].task_queue = 'foreign-queue'; },
  'hint belongs to another worker': r => { r.polls[0].worker_id = 'foreign-worker'; },
  'sweep changes namespace': r => { r.polls.at(-1).namespace_header = 'foreign'; },
  'sweep waits again': r => { r.polls.at(-1).requested_seconds = 5; },
  'sweep grants no task': r => { r.polls.at(-1).task_id = null; },
  'hint ended before admission': r => { r.polls[0].responded_at = 110.5; },
  'sweep precedes wake': r => { r.polls.at(-1).started_at = 110.5; },
  'wrong held request': r => { r.held_request_id = 99; },
  'HTTP refusal': r => { r.polls[0].http = 503; },
  'later rebudget': r => { r.observed_at = 136; },
  'changed durable history': r => { r.history_events.push('WorkflowCompleted'); },
  'wrong exact output': r => { r.output_sha256 = 'changed'; },
  'different installed SDK': r => { r.sdk_source = 'different'; },
};
for (const [name, corrupt] of Object.entries(corruptions)) test(`cross-kind reader refuses ${name}`, () => {
  const record = observation('query');
  corrupt(record);
  assert.throws(() => checkCrossKindPollWake(record, profile));
});
