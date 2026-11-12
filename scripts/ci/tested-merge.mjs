#!/usr/bin/env node
// Lets a merge-queue group skip a suite that already passed on exactly the
// same merge (Server #1130).
//
// A pull request run checks out GitHub's test merge of the pull request head
// into its base. When a merge-queue group is built straight onto that same
// base, the group commit merges the same two parents and has the same tree:
// the files, workflows and base revisions the suite sees are identical, so
// running it again proves nothing new.
//
// `record` runs in the pull request run. It names a marker artifact after the
// workflow, the merge's first parent and its tree. The workflow uploads the
// marker only after its suites passed.
//
// `check` runs in the merge group. It skips only when all of these hold:
//   - the checkout is this merge group's head commit, a two-parent merge;
//   - a marker exists for this workflow, this group's first parent and tree;
//   - the run that uploaded it is a completed, successful `pull_request` run
//     of this same workflow file, in this repository, from a branch in this
//     repository (never a fork), on exactly the group's second parent.
// A group stacked on an unmerged predecessor has a first parent no pull
// request was tested on, so it runs everything. Any other doubt, error or
// missing permission runs everything.
import {execFileSync} from 'node:child_process';
import {pathToFileURL} from 'node:url';

const SHA = /^[0-9a-f]{40}$/;
const KEY = /^[a-z0-9-]{1,40}$/;

export function markerName(key, base, tree) {
  if (!KEY.test(key ?? '')) throw new Error('Invalid workflow key');
  if (!SHA.test(base ?? '') || !SHA.test(tree ?? '')) throw new Error('Invalid merge identity');
  return `tested-merge-${key}-${base}-${tree}`;
}

// The checked-out merge commit: its two parents and its tree. The runner's
// fixed Git runs without a shell or PATH lookup, from the current directory.
export function localMerge(git = (...args) => execFileSync('/usr/bin/git', args, {encoding: 'utf8'})) {
  const [commit, ...parents] = git('rev-list', '--parents', '-n', '1', 'HEAD').trim().split(' ');
  const tree = git('rev-parse', 'HEAD^{tree}').trim();
  if (!SHA.test(commit) || parents.length !== 2 || !parents.every(parent => SHA.test(parent)) || !SHA.test(tree)) {
    throw new Error('HEAD is not a two-parent merge commit');
  }
  return {commit, base: parents[0], head: parents[1], tree};
}

// The marker this pull request run may record, or an error.
export function recordName({key, prHeadSha, merge}) {
  if (!SHA.test(prHeadSha ?? '') || merge.head !== prHeadSha) {
    throw new Error('HEAD is not this pull request\'s test merge');
  }
  return markerName(key, merge.base, merge.tree);
}

// Whether a successful pull request run of `workflowPath` already tested this
// merge group's exact merge. Returns {tested, reason}; every doubt is false.
export async function testedRun({key, workflowPath, merge, mergeGroupHeadSha, repository, repositoryId, token,
  apiUrl = 'https://api.github.com', fetchImpl = fetch}) {
  const no = reason => ({tested: false, reason});
  if (!SHA.test(mergeGroupHeadSha ?? '') || merge?.commit !== mergeGroupHeadSha) return no('HEAD is not this merge group');
  if (!/^\.github\/workflows\/[a-z0-9-]+\.yml$/.test(workflowPath ?? '')) return no('invalid workflow path');
  if (!/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/.test(repository ?? '') || !/^[1-9]\d*$/.test(String(repositoryId ?? ''))) {
    return no('invalid repository identity');
  }
  if (!token) return no('no token to read workflow runs');
  if (!/^https:\/\/[A-Za-z0-9.-]+(?::\d+)?(?:\/[A-Za-z0-9._/-]*)?$/.test(apiUrl)) return no('invalid API URL');
  let name;
  try {
    name = markerName(key, merge.base, merge.tree);
  } catch (error) {
    return no(error.message);
  }
  const get = async path => {
    const response = await fetchImpl(`${apiUrl}/repos/${repository}${path}`, {
      headers: {authorization: `Bearer ${token}`, accept: 'application/vnd.github+json', 'x-github-api-version': '2022-11-28'},
      signal: AbortSignal.timeout(30_000),
    });
    if (!response.ok) throw new Error(`GitHub API answered ${response.status}`);
    return response.json();
  };
  const id = Number(repositoryId);
  try {
    const listing = await get(`/actions/artifacts?${new URLSearchParams({name, per_page: '100'})}`);
    const candidates = (Array.isArray(listing?.artifacts) ? listing.artifacts : []).filter(artifact => artifact?.name === name
      && artifact?.expired === false && artifact?.workflow_run?.head_sha === merge.head
      && Number.isSafeInteger(artifact?.workflow_run?.id) && artifact.workflow_run.id > 0);
    for (const runId of new Set(candidates.map(artifact => artifact.workflow_run.id))) {
      const run = await get(`/actions/runs/${runId}`);
      if (run?.id === runId && run?.event === 'pull_request' && run?.status === 'completed' && run?.conclusion === 'success'
          && run?.path === workflowPath && run?.head_sha === merge.head
          && run?.repository?.id === id && run?.head_repository?.id === id) {
        return {tested: true, runId, reason: `pull request run ${runId} already passed this exact merge (${name})`};
      }
    }
    return no(`no successful same-repository pull request run recorded ${name}`);
  } catch {
    // Network errors may echo request details; the decision needs none.
    return no('workflow runs could not be read');
  }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  const [mode, key, workflowPath] = process.argv.slice(2);
  const env = process.env;
  if (mode === 'record') {
    // Never fails the suite: without a marker the queue simply runs it again.
    try {
      const name = recordName({key, prHeadSha: env.PR_HEAD_SHA, merge: localMerge()});
      console.error(`A passing run records ${name}`);
      console.log(`marker=${name}`);
    } catch (error) {
      console.error(`No tested-merge marker: ${error.message}`);
      console.log('marker=');
    }
  } else if (mode === 'check') {
    let decision;
    try {
      decision = await testedRun({
        key, workflowPath, merge: localMerge(), mergeGroupHeadSha: env.MERGE_GROUP_HEAD_SHA,
        repository: env.GITHUB_REPOSITORY, repositoryId: env.GITHUB_REPOSITORY_ID, token: env.RUNS_TOKEN,
        apiUrl: env.GITHUB_API_URL ?? 'https://api.github.com',
      });
    } catch (error) {
      decision = {tested: false, reason: error.message};
    }
    console.error(decision.tested ? `Skipping: ${decision.reason}` : `Running the suite: ${decision.reason}`);
    console.log(`tested=${decision.tested}`);
  } else {
    console.error('Usage: tested-merge.mjs record|check <key> <workflow path>');
    process.exitCode = 2;
  }
}
