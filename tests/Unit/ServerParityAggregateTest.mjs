// The Shared Server fixtures workflow reports one terminal result on every
// pull request, so that result can become a required check (Server #725).
// Pull requests run without a path filter; the `changes` job applies the push
// path list to them, and the `result` job fails closed on anything other than
// all fixture jobs passing, or all of them skipped for unchanged inputs.
import test from 'node:test';
import assert from 'node:assert/strict';
import {execFileSync, spawnSync} from 'node:child_process';
import {copyFileSync, mkdirSync, mkdtempSync, readFileSync, renameSync, rmSync, writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {dirname, join, resolve} from 'node:path';
import {matchingFiles, nulSeparatedPaths, patternRegExp, pushPathPatterns} from '../../scripts/conformance/server-parity/changes.mjs';
import {decide} from '../../scripts/conformance/server-parity/aggregate.mjs';

const workflowPath = '.github/workflows/server-parity.yml';
const workflowText = readFileSync(workflowPath, 'utf8');
const aggregate = resolve('scripts/conformance/server-parity/aggregate.mjs');
const aggregateWrapper = 'scripts/conformance/server-parity/aggregate.sh';
const classifierWrapper = 'scripts/conformance/server-parity/changes.sh';

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

function triggers(text) {
  const start = text.indexOf('\non:\n') + '\non:\n'.length;
  const rest = text.slice(start);
  return rest.slice(0, rest.search(/\n[a-z_-]+:/));
}

test('pull requests run without a path filter and push keeps the one path list', () => {
  const on = triggers(workflowText);
  const pullRequest = on.slice(on.indexOf('  pull_request:\n'), on.indexOf('  push:\n'));
  assert.doesNotMatch(pullRequest, /paths|branches/);
  const patterns = pushPathPatterns(workflowText);
  assert.ok(patterns.includes('rust/**'));
  assert.ok(patterns.includes('.github/workflows/server-parity.yml'));
  assert.ok(patterns.includes('scripts/conformance/server-parity/**'));
});

test('path patterns follow GitHub filter semantics for the forms in use', () => {
  assert.ok(patternRegExp('rust/**').test('rust/src/main.rs'));
  assert.ok(patternRegExp('scripts/conformance/server-parity*').test('scripts/conformance/server-parity.sh'));
  assert.ok(!patternRegExp('scripts/conformance/server-parity*').test('scripts/conformance/server-parity/run.mjs'));
  assert.ok(!patternRegExp('tests/Unit/ServerParityRunnerTest.mjs').test('tests/Unit/ServerParityRunnerTestXmjs'));
  assert.throws(() => patternRegExp('docs/{a,b}/**'), /Unsupported path filter pattern/);
  assert.deepEqual(matchingFiles(['rust/**', 'docs/x.md'], ['README.md', 'rust/Cargo.toml', 'docs/x.md']), ['rust/Cargo.toml', 'docs/x.md']);
});

test('path patterns accept no regular-expression syntax and match every other character literally', () => {
  for (const pattern of ['rust\\**', 'rust/\\d', 'a+b', 'a?b', 'a(b)', 'a[b]', 'a|b', 'a^b', 'a$b', 'a{1}', 'docs/{a,b}/**', 'a b', '']) {
    assert.throws(() => patternRegExp(pattern), /Unsupported path filter pattern/, JSON.stringify(pattern));
  }
  assert.ok(!patternRegExp('a.b').test('aXb'));
  assert.ok(patternRegExp('a-b/c_d.e').test('a-b/c_d.e'));
  assert.ok(!patternRegExp('a-b').test('a+b'));
});

test('path patterns match file names holding newlines and tabs', () => {
  assert.ok(patternRegExp('rust/**').test('rust/hidden\nfixture.rs'));
  assert.ok(patternRegExp('rust/**').test('rust/a\n/b\tc.rs'));
  assert.ok(patternRegExp('scripts/conformance/server-parity*').test('scripts/conformance/server-parity\n.sh'));
  assert.ok(!patternRegExp('scripts/conformance/server-parity*').test('scripts/conformance/server-parity\n/run.mjs'));
  assert.ok(!patternRegExp('rust/**').test('docs/x\nrust/y'));
});

test('the file list splits only on NUL and refuses what it cannot decode', () => {
  assert.deepEqual(nulSeparatedPaths(Buffer.from('README.md\0rust/hidden\nfixture.rs\0 lead\ttab \0')),
    ['README.md', 'rust/hidden\nfixture.rs', ' lead\ttab ']);
  assert.deepEqual(nulSeparatedPaths(Buffer.alloc(0)), []);
  assert.throws(() => nulSeparatedPaths(Buffer.from('rust/a.rs')), /does not end with NUL/);
  assert.throws(() => nulSeparatedPaths(Buffer.from([0x72, 0xff, 0])), TypeError);
});

test('the fixture jobs run only through the classifier and the result waits for all of them', () => {
  const blocks = jobs(workflowText);
  const ids = Object.keys(blocks);
  assert.equal(ids[0], 'changes');
  assert.match(blocks.changes, /^ {4}timeout-minutes: \d+$/m);
  assert.match(blocks.changes, /run: \$\{\{ steps\.classify\.outputs\.run \}\}/);
  assert.match(blocks.changes, /run: bash scripts\/conformance\/server-parity\/changes\.sh >> "\$GITHUB_OUTPUT"$/m);
  // The root fixture jobs run only through the classifier.
  const roots = ['published-sdk-adapters', 'codec-native-build'];
  for (const id of roots) {
    assert.match(blocks[id], /^ {4}needs: changes\n {4}if: \$\{\{ needs\.changes\.outputs\.run == 'true' \}\}$/m, id);
  }
  const fixtureJobs = ids.filter(id => id !== 'changes' && id !== 'result');
  for (const id of fixtureJobs.filter(id => !roots.includes(id))) {
    // A skipped root skips these jobs; no job-level condition may override
    // that. Every one waits for the SDK adapters, and only deadline health
    // also waits for the optimized native build.
    const expected = id === 'codec-deadline-health' ? '[published-sdk-adapters, codec-native-build]' : 'published-sdk-adapters';
    assert.ok(blocks[id].split('\n').includes(`    needs: ${expected}`), `${id} needs ${expected}`);
    assert.doesNotMatch(blocks[id], /^ {4}if:/m, id);
  }
  // Every job other than the classifier and the result is a gated fixture job.
  assert.deepEqual(fixtureJobs.sort(), ['codec-deadline-health', 'codec-native-build', 'execution-and-codec',
    'mysql-execution', 'postgres-storage', 'published-sdk-adapters']);
  const result = blocks.result;
  assert.equal(ids.at(-1), 'result');
  assert.match(result, /^ {4}name: Shared Server fixtures result$/m);
  assert.doesNotMatch(result, /strategy:/);
  assert.match(result, /^ {4}if: \$\{\{ always\(\) \}\}$/m);
  const needs = [...result.matchAll(/^ {6}- ([a-z0-9-]+)$/gm)].map(match => match[1]);
  assert.deepEqual(needs.sort(), ['changes', ...fixtureJobs].sort());
  assert.match(result, /NEEDS: \$\{\{ toJSON\(needs\) \}\}/);
  assert.match(result, /run: bash scripts\/conformance\/server-parity\/aggregate\.sh$/m);
  assert.match(result, /sparse-checkout: \|\n {12}scripts\/conformance\/server-parity\/aggregate\.sh\n {12}scripts\/conformance\/server-parity\/aggregate\.mjs\n/);
});

function runAggregate(needs) {
  const text = typeof needs === 'string' ? needs : JSON.stringify(needs);
  return spawnSync('node', [aggregate], {env: {...process.env, NEEDS: text}, encoding: 'utf8'});
}

const fixture = (result, run) => ({
  changes: {result: 'success', outputs: run === undefined ? {} : {run}},
  'published-sdk-adapters': {result, outputs: {}},
  'codec-native-build': {result, outputs: {}},
  'codec-deadline-health': {result, outputs: {}},
  'mysql-execution': {result, outputs: {}},
  'postgres-storage': {result, outputs: {}},
  'execution-and-codec': {result, outputs: {}},
});

test('the result passes when every fixture job passed', () => {
  const outcome = runAggregate(fixture('success', 'true'));
  assert.equal(outcome.status, 0, outcome.stderr);
  assert.match(outcome.stdout, /Every fixture job passed/);
});

test('the result passes when no input changed and every fixture job was skipped', () => {
  const outcome = runAggregate(fixture('skipped', 'false'));
  assert.equal(outcome.status, 0, outcome.stderr);
  assert.match(outcome.stdout, /every fixture job was skipped/);
});

for (const [label, needs, reason] of [
  ['a failed fixture job', {...fixture('success', 'true'), 'mysql-execution': {result: 'failure', outputs: {}}}, /must be success when run=true: mysql-execution=failure\./],
  ['a cancelled fixture job', {...fixture('success', 'true'), 'execution-and-codec': {result: 'cancelled', outputs: {}}}, /must be success when run=true: execution-and-codec=cancelled\./],
  ['a skipped fixture job when inputs changed', {...fixture('success', 'true'), 'postgres-storage': {result: 'skipped', outputs: {}}}, /must be success when run=true: postgres-storage=skipped\./],
  ['a fixture job that ran when no input changed', {...fixture('skipped', 'false'), 'codec-deadline-health': {result: 'success', outputs: {}}}, /must be skipped when run=false: codec-deadline-health=success\./],
  ['a failed classifier', {...fixture('skipped', 'false'), changes: {result: 'failure', outputs: {}}}, /classifier did not succeed \(failure\)/],
  ['a cancelled classifier', {...fixture('skipped'), changes: {result: 'cancelled', outputs: {}}}, /classifier did not succeed \(cancelled\)/],
  ['a missing classifier', (({changes, ...rest}) => rest)(fixture('success', 'true')), /classifier did not succeed \(undefined\)/],
  ['a classifier with no decision', fixture('skipped'), /reported no decision \(undefined\)/],
  ['a classifier with an unknown decision', fixture('skipped', 'maybe'), /reported no decision \(maybe\)/],
  ['no fixture jobs', {changes: {result: 'success', outputs: {run: 'true'}}}, /No fixture jobs reported/],
  ['a context that is not an object', [], /not an object/],
]) {
  test(`the result fails on ${label}`, () => {
    const decision = decide(needs);
    assert.equal(decision.pass, false);
    assert.match(decision.reason, reason);
    const outcome = runAggregate(needs);
    assert.equal(outcome.status, 1);
    assert.match(outcome.stderr, reason);
  });
}

test('the workflow wrapper passes the result through, from the repository root', () => {
  const run = needs => spawnSync('bash', [aggregateWrapper], {env: {...process.env, NEEDS: JSON.stringify(needs)}, encoding: 'utf8'});
  assert.equal(run(fixture('success', 'true')).status, 0);
  const failed = run({...fixture('success', 'true'), 'mysql-execution': {result: 'failure', outputs: {}}});
  assert.equal(failed.status, 1);
  assert.match(failed.stderr, /mysql-execution=failure/);
});

test('the result fails when the needs context cannot be read', () => {
  for (const text of ['', 'not json']) {
    const outcome = runAggregate(text);
    assert.equal(outcome.status, 1);
    assert.match(outcome.stderr, /could not be read/);
  }
});

// A pull request merge commit in a scratch repository with this workflow.
function pullRequest(change) {
  const root = mkdtempSync(join(tmpdir(), 'parity-changes-'));
  const git = (...args) => execFileSync('git', ['-c', 'user.name=t', '-c', 'user.email=t@example.invalid', ...args], {cwd: root, encoding: 'utf8'}).trim();
  const write = (path, text) => {
    mkdirSync(dirname(join(root, path)), {recursive: true});
    writeFileSync(join(root, path), text);
  };
  git('init', '-q', '-b', 'main');
  mkdirSync(join(root, '.github/workflows'), {recursive: true});
  copyFileSync(workflowPath, join(root, workflowPath));
  mkdirSync(join(root, dirname(classifierWrapper)), {recursive: true});
  for (const file of [classifierWrapper, 'scripts/conformance/server-parity/changes.mjs']) copyFileSync(file, join(root, file));
  write('README.md', 'base\n');
  write('tests/Fixtures/ServerParity/case.json', '{}\n');
  git('add', '-A');
  git('commit', '-q', '-m', 'base');
  git('checkout', '-q', '-b', 'head');
  change({root, write});
  git('add', '-A');
  git('commit', '-q', '-m', 'head');
  const head = git('rev-parse', 'HEAD');
  git('checkout', '-q', 'main');
  write('MAIN.md', 'main moved\n');
  git('add', '-A');
  git('commit', '-q', '-m', 'main');
  git('merge', '-q', '--no-ff', '-m', 'merge', 'head');
  return {root, head};
}

function classify(root, env) {
  // The workflow runs the wrapper from the repository root.
  const outcome = spawnSync('bash', [classifierWrapper], {cwd: root, env: {...process.env, ...env}, encoding: 'utf8'});
  assert.equal(outcome.status, 0, outcome.stderr);
  return outcome.stdout.trim();
}

for (const [label, change, expected] of [
  ['a documentation-only change skips the fixtures', ({write}) => write('docs/note.md', 'x\n'), 'run=false'],
  ['a Rust change runs the fixtures', ({write}) => write('rust/src/lib.rs', 'x\n'), 'run=true'],
  ['a Rust file whose name holds a newline runs the fixtures', ({write}) => write('rust/hidden\nfixture.rs', 'x\n'), 'run=true'],
  ['a Rust file whose name holds a tab runs the fixtures', ({write}) => write('rust/hidden\tfixture.rs', 'x\n'), 'run=true'],
  ['a fixture whose name holds a newline runs the fixtures', ({write}) => write('tests/Fixtures/ServerParity/a\nb.json', '{}\n'), 'run=true'],
  ['a documentation file whose name holds a newline still skips', ({write}) => write('docs/a\nrust/b.md', 'x\n'), 'run=false'],
  ['a file name that is not UTF-8 runs the fixtures', ({root}) => {
    mkdirSync(join(root, 'docs'), {recursive: true});
    writeFileSync(Buffer.concat([Buffer.from(join(root, 'docs/')), Buffer.from([0xff, 0x2e, 0x6d, 0x64])]), 'x\n');
  }, 'run=true'],
  ['an unsupported push path pattern runs the fixtures', ({write}) => {
    write('docs/note.md', 'x\n');
    write(workflowPath, workflowText.replace('    paths:\n', '    paths:\n      - docs/{a,b}/**\n'));
  }, 'run=true'],
  ['moving a fixture out of the corpus runs the fixtures', ({root, write}) => {
    write('docs/.keep', '');
    renameSync(join(root, 'tests/Fixtures/ServerParity/case.json'), join(root, 'docs/case.json'));
  }, 'run=true'],
]) {
  test(`pull request classification: ${label}`, () => {
    const {root, head} = pullRequest(change);
    try {
      assert.equal(classify(root, {EVENT_NAME: 'pull_request', PR_HEAD_SHA: head}), expected);
    } finally {
      rmSync(root, {recursive: true, force: true});
    }
  });
}

test('pull request classification runs every fixture when HEAD is not that pull request', () => {
  const {root} = pullRequest(({write}) => write('docs/note.md', 'x\n'));
  try {
    assert.equal(classify(root, {EVENT_NAME: 'pull_request', PR_HEAD_SHA: '0'.repeat(40)}), 'run=true');
  } finally {
    rmSync(root, {recursive: true, force: true});
  }
});

for (const event of ['push', 'merge_group', 'workflow_dispatch', '']) {
  test(`${event || 'an unnamed event'} runs every fixture job`, () => {
    const {root, head} = pullRequest(({write}) => write('docs/note.md', 'x\n'));
    try {
      assert.equal(classify(root, {EVENT_NAME: event, PR_HEAD_SHA: head}), 'run=true');
    } finally {
      rmSync(root, {recursive: true, force: true});
    }
  });
}
