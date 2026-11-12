// A merge-queue group skips PHPUnit Feature and the Shared Server fixtures
// only when a successful same-repository pull request run of the same
// workflow already passed the exact same merge: same two parents, same tree
// (Server #1130). Everything else runs the suites.
import test from 'node:test';
import assert from 'node:assert/strict';
import {execFileSync, spawnSync} from 'node:child_process';
import {mkdtempSync, readFileSync, rmSync, writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join, resolve} from 'node:path';
import {localMerge, markerName, recordName, testedRun} from '../../scripts/ci/tested-merge.mjs';

const script = resolve('scripts/ci/tested-merge.mjs');
const workflow = name => readFileSync(`.github/workflows/${name}`, 'utf8');

// Top-level job ids and each job's block of text.
function jobs(text) {
  const body = text.slice(text.indexOf('\njobs:\n') + '\njobs:\n'.length);
  const blocks = {};
  let current;
  for (const line of body.split('\n')) {
    const header = /^ {2}([a-z0-9-]+):$/.exec(line);
    if (header) {
      current = header[1];
      blocks[current] = '';
    } else if (current) {
      blocks[current] += `${line}\n`;
    }
  }
  return blocks;
}

// A repository with main, a pull request head, GitHub's test merge of the
// head into main, and a later queue-group merge of the same two parents.
function repository() {
  const root = mkdtempSync(join(tmpdir(), 'tested-merge-'));
  const git = (...args) => execFileSync('git', ['-C', root, ...args], {encoding: 'utf8'}).trim();
  git('init', '-q', '-b', 'main');
  git('config', 'user.email', 'ci@example.invalid');
  git('config', 'user.name', 'CI');
  git('config', 'commit.gpgsign', 'false');
  const write = (path, text) => writeFileSync(join(root, path), text);
  write('README.md', 'base\n');
  git('add', '-A');
  git('commit', '-q', '-m', 'base');
  git('checkout', '-q', '-b', 'head');
  write('feature.txt', 'feature\n');
  git('add', '-A');
  git('commit', '-q', '-m', 'head');
  const head = git('rev-parse', 'HEAD');
  git('checkout', '-q', 'main');
  write('MAIN.md', 'main moved\n');
  git('add', '-A');
  git('commit', '-q', '-m', 'main');
  const base = git('rev-parse', 'HEAD');
  const merge = message => {
    git('checkout', '-q', '--detach', base);
    git('merge', '-q', '--no-ff', '-m', message, head);
    return git('rev-parse', 'HEAD');
  };
  return {root, git, write, head, base, merge};
}

const inRepository = (root, fn) => {
  const git = (...args) => execFileSync('git', ['-C', root, ...args], {encoding: 'utf8'});
  return fn(git);
};

test('marker names carry the workflow, the first parent and the tree, and nothing else', () => {
  const a = 'a'.repeat(40);
  const b = 'b'.repeat(40);
  assert.equal(markerName('phpunit-feature', a, b), `tested-merge-phpunit-feature-${a}-${b}`);
  for (const [key, base, tree] of [['', a, b], ['Bad Key', a, b], ['../x', a, b], ['k', 'a'.repeat(39), b],
    ['k', a, `${b}\n`], ['k', undefined, b], ['k', a, 'B'.repeat(40)]]) {
    assert.throws(() => markerName(key, base, tree), /Invalid/);
  }
});

test('a pull request test merge and a queue group of the same parents share one marker', () => {
  const repo = repository();
  try {
    const pullRequestMerge = repo.merge('Merge head into main');
    const prMerge = inRepository(repo.root, git => localMerge((...args) => git(...args)));
    const groupMerge = repo.merge('Merge pull request #1 from head');
    assert.notEqual(pullRequestMerge, groupMerge, 'distinct commits');
    const group = inRepository(repo.root, git => localMerge((...args) => git(...args)));
    assert.deepEqual([prMerge.base, prMerge.head], [repo.base, repo.head]);
    assert.equal(group.commit, groupMerge);
    assert.equal(prMerge.tree, group.tree);
    assert.equal(recordName({key: 'server-parity', prHeadSha: repo.head, merge: prMerge}),
      markerName('server-parity', group.base, group.tree));
  } finally {
    rmSync(repo.root, {recursive: true, force: true});
  }
});

test('a group stacked on a queued predecessor has a marker no pull request run recorded', () => {
  const repo = repository();
  try {
    repo.merge('Merge head into main');
    const prMerge = inRepository(repo.root, git => localMerge((...args) => git(...args)));
    // A predecessor entry lands first in the same queue group.
    repo.git('checkout', '-q', '--detach', repo.base);
    repo.write('OTHER.md', 'predecessor\n');
    repo.git('add', '-A');
    repo.git('commit', '-q', '-m', 'predecessor');
    repo.git('merge', '-q', '--no-ff', '-m', 'group', repo.head);
    const stacked = inRepository(repo.root, git => localMerge((...args) => git(...args)));
    assert.equal(stacked.head, repo.head);
    assert.notEqual(markerName('k', stacked.base, stacked.tree), markerName('k', prMerge.base, prMerge.tree));
  } finally {
    rmSync(repo.root, {recursive: true, force: true});
  }
});

test('only a two-parent merge of this pull request head records a marker', () => {
  const repo = repository();
  try {
    // A plain commit is not a test merge.
    assert.throws(() => inRepository(repo.root, git => localMerge((...args) => git(...args))), /two-parent merge/);
    repo.merge('Merge head into main');
    const merge = inRepository(repo.root, git => localMerge((...args) => git(...args)));
    for (const prHeadSha of ['', undefined, '0'.repeat(40), repo.base, `${repo.head}\n`]) {
      assert.throws(() => recordName({key: 'k', prHeadSha, merge}), /not this pull request/);
    }
  } finally {
    rmSync(repo.root, {recursive: true, force: true});
  }
});

const ID = 4242;
const base = 'b'.repeat(40);
const head = 'c'.repeat(40);
const tree = 'd'.repeat(40);
const commit = 'e'.repeat(40);
const merge = {commit, base, head, tree};
const path = '.github/workflows/phpunit-feature.yml';
const name = markerName('phpunit-feature', base, tree);
const artifact = (overrides = {}) => ({name, expired: false, workflow_run: {id: 7, head_sha: head}, ...overrides});
const run = (overrides = {}) => ({id: 7, event: 'pull_request', status: 'completed', conclusion: 'success', path,
  head_sha: head, repository: {id: ID}, head_repository: {id: ID}, ...overrides});

function api({artifacts = [artifact()], runs = {7: run()}, ok = true, throws = false} = {}) {
  const requests = [];
  const fetchImpl = async (url, init) => {
    requests.push({url, init});
    if (throws) throw new Error(`boom ${url}`);
    const runMatch = /\/actions\/runs\/(\d+)$/.exec(url);
    const body = runMatch ? runs[runMatch[1]] ?? {} : {artifacts};
    return {ok, json: async () => body};
  };
  return {requests, fetchImpl};
}

const ask = (fetchImpl, overrides = {}) => testedRun({key: 'phpunit-feature', workflowPath: path, merge,
  mergeGroupHeadSha: commit, repository: 'durable-workflow/server', repositoryId: String(ID), token: 't', fetchImpl,
  ...overrides});

test('a successful same-repository pull request run of this workflow on this exact merge skips the suite', async () => {
  const {requests, fetchImpl} = api();
  const decision = await ask(fetchImpl);
  assert.equal(decision.tested, true, decision.reason);
  assert.equal(decision.runId, 7);
  assert.equal(requests[0].url, `https://api.github.com/repos/durable-workflow/server/actions/artifacts?name=${name}&per_page=100`);
  assert.equal(requests[1].url, 'https://api.github.com/repos/durable-workflow/server/actions/runs/7');
  assert.equal(requests[0].init.headers.authorization, 'Bearer t');
});

for (const [label, overrides] of [
  ['a push run', {event: 'push'}],
  ['a merge-group run', {event: 'merge_group'}],
  ['a pull_request_target run', {event: 'pull_request_target'}],
  ['a dispatched run', {event: 'workflow_dispatch'}],
  ['a failed run', {conclusion: 'failure'}],
  ['a cancelled run', {conclusion: 'cancelled'}],
  ['a run still in progress', {status: 'in_progress', conclusion: null}],
  ['another workflow', {path: '.github/workflows/server-parity.yml'}],
  ['another pull request head', {head_sha: 'f'.repeat(40)}],
  ['a fork', {head_repository: {id: ID + 1}}],
  ['another repository', {repository: {id: ID + 1}}],
  ['a different run id than the artifact named', {id: 8}],
  ['no run', null],
]) {
  test(`${label} never skips the suite`, async () => {
    const {fetchImpl} = api({runs: {7: overrides === null ? {} : run(overrides)}});
    assert.equal((await ask(fetchImpl)).tested, false);
  });
}

for (const [label, artifacts] of [
  ['no marker', []],
  ['an expired marker', [artifact({expired: true})]],
  ['a marker from another head', [artifact({workflow_run: {id: 7, head_sha: 'f'.repeat(40)}})]],
  ['a marker with another name', [artifact({name: `${name}x`})]],
  ['a marker without a run', [artifact({workflow_run: {head_sha: head}})]],
  ['a malformed listing', 'nope'],
]) {
  test(`${label} never skips the suite`, async () => {
    const {fetchImpl} = api({artifacts});
    assert.equal((await ask(fetchImpl)).tested, false);
  });
}

test('one good run among bad ones skips; the bad ones alone never do', async () => {
  const runs = {5: run({id: 5, conclusion: 'failure'}), 7: run()};
  const artifacts = [artifact({workflow_run: {id: 5, head_sha: head}}), artifact()];
  assert.equal((await ask(api({artifacts, runs}).fetchImpl)).tested, true);
  assert.equal((await ask(api({artifacts: artifacts.slice(0, 1), runs}).fetchImpl)).tested, false);
});

test('API errors, missing identity and a checkout that is not this group run the suite', async () => {
  assert.equal((await ask(api({ok: false}).fetchImpl)).tested, false);
  const failing = api({throws: true});
  const decision = await ask(failing.fetchImpl);
  assert.equal(decision.tested, false);
  assert.doesNotMatch(decision.reason, /boom|https:/);
  for (const overrides of [
    {token: ''},
    {mergeGroupHeadSha: 'f'.repeat(40)},
    {mergeGroupHeadSha: undefined},
    {repositoryId: ''},
    {repositoryId: '0'},
    {repository: 'durable-workflow/server/../x'},
    {workflowPath: '.github/workflows/../x.yml'},
    {key: 'Bad Key'},
    {apiUrl: 'http://api.github.com'},
  ]) {
    const {requests, fetchImpl} = api();
    assert.equal((await ask(fetchImpl, overrides)).tested, false, JSON.stringify(overrides));
    assert.equal(requests.length, 0, JSON.stringify(overrides));
  }
});

function cli(root, args, env) {
  const base = {...process.env};
  for (const key of ['RUNS_TOKEN', 'PR_HEAD_SHA', 'MERGE_GROUP_HEAD_SHA', 'GITHUB_REPOSITORY', 'GITHUB_REPOSITORY_ID', 'GITHUB_API_URL']) {
    delete base[key];
  }
  return spawnSync('node', [script, ...args], {cwd: root, env: {...base, ...env}, encoding: 'utf8'});
}

test('the record command prints the marker for this pull request merge and nothing otherwise', () => {
  const repo = repository();
  try {
    repo.merge('Merge head into main');
    const tree = repo.git('rev-parse', 'HEAD^{tree}');
    const ok = cli(repo.root, ['record', 'server-parity', '.github/workflows/server-parity.yml'], {PR_HEAD_SHA: repo.head});
    assert.equal(ok.status, 0, ok.stderr);
    assert.equal(ok.stdout, `marker=${markerName('server-parity', repo.base, tree)}\n`);
    // The workflows call the wrapper, from the repository root.
    const wrapped = spawnSync('bash', [resolve('scripts/ci/tested-merge.sh'), 'record', 'server-parity', '.github/workflows/server-parity.yml'],
      {cwd: resolve('.'), env: {...process.env, PR_HEAD_SHA: repo.head, GIT_DIR: join(repo.root, '.git'), GIT_WORK_TREE: repo.root}, encoding: 'utf8'});
    assert.equal(wrapped.stdout, ok.stdout, wrapped.stderr);
    const wrong = cli(repo.root, ['record', 'server-parity', '.github/workflows/server-parity.yml'], {PR_HEAD_SHA: repo.base});
    assert.equal(wrong.status, 0, wrong.stderr);
    assert.equal(wrong.stdout, 'marker=\n');
  } finally {
    rmSync(repo.root, {recursive: true, force: true});
  }
});

test('the check command runs the suite whenever it cannot prove the merge was tested', () => {
  const repo = repository();
  try {
    const groupHead = repo.merge('Merge pull request #1');
    for (const env of [
      {MERGE_GROUP_HEAD_SHA: groupHead, GITHUB_REPOSITORY: 'o/r', GITHUB_REPOSITORY_ID: '1'},
      {MERGE_GROUP_HEAD_SHA: groupHead, GITHUB_REPOSITORY: 'o/r', GITHUB_REPOSITORY_ID: '1', RUNS_TOKEN: 't',
        GITHUB_API_URL: 'https://127.0.0.1:9'},
      {MERGE_GROUP_HEAD_SHA: repo.head, GITHUB_REPOSITORY: 'o/r', GITHUB_REPOSITORY_ID: '1', RUNS_TOKEN: 't'},
    ]) {
      const outcome = cli(repo.root, ['check', 'phpunit-feature', path], env);
      assert.equal(outcome.status, 0, outcome.stderr);
      assert.equal(outcome.stdout, 'tested=false\n', JSON.stringify(env));
    }
  } finally {
    rmSync(repo.root, {recursive: true, force: true});
  }
});

test('PHPUnit Feature skips only the feature suite, only for a tested merge group', () => {
  const text = workflow('phpunit-feature.yml');
  const blocks = jobs(text);
  const tested = blocks['tested-merge'];
  assert.match(tested, /^ {4}permissions:\n {6}contents: read\n {6}actions: read$/m);
  assert.match(tested, /fetch-depth: 2\n {10}persist-credentials: false/);
  assert.match(tested, /sparse-checkout: \|\n {12}scripts\/ci\/tested-merge\.sh\n {12}scripts\/ci\/tested-merge\.mjs\n/);
  assert.match(tested, /if: \$\{\{ github\.event_name == 'pull_request' \}\}\n {8}env:\n {10}PR_HEAD_SHA: \$\{\{ github\.event\.pull_request\.head\.sha \}\}\n {8}run: bash scripts\/ci\/tested-merge\.sh record phpunit-feature \.github\/workflows\/phpunit-feature\.yml >> "\$GITHUB_OUTPUT"/);
  assert.match(tested, /if: \$\{\{ github\.event_name == 'merge_group' \}\}\n {8}env:\n {10}MERGE_GROUP_HEAD_SHA: \$\{\{ github\.event\.merge_group\.head_sha \}\}\n {10}RUNS_TOKEN: \$\{\{ github\.token \}\}\n {8}run: bash scripts\/ci\/tested-merge\.sh check phpunit-feature \.github\/workflows\/phpunit-feature\.yml >> "\$GITHUB_OUTPUT"/);
  // Only the tested-merge job may read Actions data.
  assert.equal(text.match(/actions: read/g).length, 1);
  assert.match(blocks.feature, /^ {4}needs: \[action-policy, tested-merge\]$/m);
  assert.match(blocks.feature, /^ {4}if: \$\{\{ always\(\) && !cancelled\(\) && needs\.tested-merge\.outputs\.tested != 'true' \}\}$/m);
  assert.match(blocks.qualification, /^ {4}needs: \[preflight, tested-merge, feature\]$/m);
  // Repository contracts, the action policy and every other job always run
  // on a group: no other job reads the decision.
  const code = block => block.split('\n').filter(line => !/^\s*#/.test(line)).join('\n');
  for (const id of Object.keys(blocks).filter(id => !['tested-merge', 'feature', 'qualification'].includes(id))) {
    assert.doesNotMatch(code(blocks[id]), /needs\.tested-merge|tested-merge\.mjs/, id);
  }
});

// The qualification gate, run as the workflow runs it.
function qualify(env) {
  const block = jobs(workflow('phpunit-feature.yml')).qualification;
  const body = /run: \|\n((?: {10}.*\n)+)/.exec(block)[1].replace(/^ {10}/gm, '');
  return spawnSync('bash', ['-c', body], {env: {PATH: process.env.PATH, ...env}, encoding: 'utf8'}).status;
}

test('the qualification gate accepts a skipped suite only for a tested merge group', () => {
  const pass = {EVENT_NAME: 'merge_group', PREFLIGHT_RESULT: 'success', FEATURE_RESULT: 'skipped', TESTED_MERGE: 'true'};
  assert.equal(qualify(pass), 0);
  assert.equal(qualify({...pass, FEATURE_RESULT: 'success', TESTED_MERGE: ''}), 0);
  assert.equal(qualify({...pass, EVENT_NAME: 'pull_request', FEATURE_RESULT: 'success', TESTED_MERGE: ''}), 0);
  for (const overrides of [
    {PREFLIGHT_RESULT: 'failure'},
    {PREFLIGHT_RESULT: 'skipped'},
    {TESTED_MERGE: ''},
    {TESTED_MERGE: 'false'},
    {EVENT_NAME: 'pull_request'},
    {EVENT_NAME: 'push'},
    {FEATURE_RESULT: 'failure'},
    {FEATURE_RESULT: 'cancelled'},
    {FEATURE_RESULT: 'failure', TESTED_MERGE: 'false'},
  ]) {
    assert.notEqual(qualify({...pass, ...overrides}), 0, JSON.stringify(overrides));
  }
});

for (const [file, gateJob, markerSource] of [
  ['phpunit-feature.yml', 'qualification', 'needs.tested-merge.outputs.marker'],
  ['server-parity.yml', 'result', 'needs.changes.outputs.marker'],
]) {
  test(`${file} records a marker only from a passing pull request run`, () => {
    const block = jobs(workflow(file))[gateJob];
    const condition = `if: \${{ success() && github.event_name == 'pull_request' && ${markerSource} != '' }}`;
    assert.equal(block.split(condition).length - 1, 2, 'write and upload share one condition');
    const upload = block.slice(block.indexOf('Record that this exact merge passed'));
    assert.match(upload, /continue-on-error: true/);
    assert.match(upload, /uses: actions\/upload-artifact@043fb46d1a93c77aae656e7c1c64a875d1fc6a0a/);
    assert.match(upload, new RegExp(`name: \\$\\{\\{ ${markerSource.replaceAll('.', '\\.')} \\}\\}`));
    assert.match(upload, /retention-days: 7/);
    // The marker is written after the gate itself.
    assert.ok(block.indexOf('Record that this exact merge passed') > block.indexOf('run: '), file);
  });
}

test('Shared Server fixtures skip every fixture job only for a tested merge group', () => {
  const changes = jobs(workflow('server-parity.yml')).changes;
  assert.ok(changes.split('\n').includes(
    "      run: ${{ steps.tested.outputs.tested == 'true' && 'false' || steps.classify.outputs.run }}"));
  assert.ok(changes.split('\n').includes('      tested: ${{ steps.tested.outputs.tested }}'));
  assert.match(changes, /id: tested\n {8}if: \$\{\{ github\.event_name == 'merge_group' \}\}\n {8}env:\n {10}MERGE_GROUP_HEAD_SHA: \$\{\{ github\.event\.merge_group\.head_sha \}\}\n {10}RUNS_TOKEN: \$\{\{ github\.token \}\}\n {8}run: bash scripts\/ci\/tested-merge\.sh check server-parity \.github\/workflows\/server-parity\.yml >> "\$GITHUB_OUTPUT"/);
  assert.match(changes, /id: record\n {8}if: \$\{\{ github\.event_name == 'pull_request' \}\}\n {8}env:\n {10}PR_HEAD_SHA: \$\{\{ github\.event\.pull_request\.head\.sha \}\}\n {8}run: bash scripts\/ci\/tested-merge\.sh record server-parity \.github\/workflows\/server-parity\.yml >> "\$GITHUB_OUTPUT"/);
});
