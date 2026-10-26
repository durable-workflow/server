import test from 'node:test';
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {mkdtempSync, readFileSync, rmSync, symlinkSync, writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {prepareSource, queueIdentity} from '../../scripts/ci/merge-queue-sonar.mjs';

const repository = {id: 123, full_name: 'durable-workflow/server'};
const valid = () => ({repository, workflow_run: {
  repository, head_repository: repository, name: 'PHPUnit Feature',
  path: '.github/workflows/phpunit-feature.yml', event: 'merge_group',
  status: 'completed', conclusion: 'success', head_sha: 'a'.repeat(40),
  head_branch: `gh-readonly-queue/main/pr-42-${'b'.repeat(40)}`,
}});
const identity = event => queueIdentity(event, '123', repository.full_name);

test('accepts the successful same-repository synthetic identity', () => {
  assert.deepEqual(identity(valid()), {sha: 'a'.repeat(40), branch: valid().workflow_run.head_branch});
});

for (const [key, value] of [
  ['event', 'pull_request'], ['event', 'workflow_dispatch'], ['conclusion', 'failure'],
  ['conclusion', 'skipped'], ['status', 'in_progress'], ['name', 'Other workflow'],
  ['path', '.github/workflows/other.yml'], ['head_repository', {id: 456}],
  ['repository', {id: 456}], ['head_sha', '0'.repeat(40)], ['head_sha', 'main'],
  ['head_sha', `${'a'.repeat(40)}\n`], ['head_sha', `${'a'.repeat(40)}\r`], ['head_branch', 'main'],
  ['head_branch', `gh-readonly-queue/main/pr-42-${'b'.repeat(40)}\n`],
  ['head_branch', `gh-readonly-queue/other/pr-42-${'b'.repeat(40)}`],
  ['head_branch', `gh-readonly-queue/main/pr-42-${'b'.repeat(40)}\n-Dsonar.host.url=https://example.invalid`],
]) {
  test(`refuses ${key}=${JSON.stringify(value)}`, () => {
    const event = valid();
    event.workflow_run[key] = value;
    assert.throws(() => identity(event));
  });
}

test('does not trust a matching name from a different repository ID', () => {
  assert.throws(() => queueIdentity(valid(), '456', repository.full_name));
});

function source(t) {
  const root = mkdtempSync(join(tmpdir(), 'merge-queue-sonar-'));
  t.after(() => rmSync(root, {recursive: true, force: true}));
  execFileSync('git', ['init', '-q', root]);
  writeFileSync(join(root, 'sonar-project.properties'), 'sonar.host.url=https://example.invalid\n');
  execFileSync('git', ['-C', root, 'add', '.']);
  execFileSync('git', ['-C', root, '-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', 'commit', '-qm', 'fixture']);
  const sha = execFileSync('git', ['-C', root, 'rev-parse', 'HEAD'], {encoding: 'utf8'}).trim();
  const trusted = join(root, '.git', 'trusted.properties');
  writeFileSync(trusted, 'sonar.projectKey=durable-workflow_server\n');
  return {root, sha, trusted};
}

test('replaces candidate scanner settings using trusted configuration', t => {
  const {root, sha, trusted} = source(t);
  prepareSource(root, trusted, sha);
  assert.equal(readFileSync(join(root, 'sonar-project.properties'), 'utf8'), readFileSync(trusted, 'utf8'));
});

test('refuses a checkout other than the reported synthetic commit', t => {
  const {root, trusted} = source(t);
  assert.throws(() => prepareSource(root, trusted, 'f'.repeat(40)), /differs/);
});

test('refuses tracked and untracked symlinks before overwriting configuration', t => {
  const {root, sha, trusted} = source(t);
  symlinkSync(trusted, join(root, 'escape'));
  assert.throws(() => prepareSource(root, trusted, sha), /regular files/);
  execFileSync('git', ['-C', root, 'add', 'escape']);
  assert.throws(() => prepareSource(root, trusted, sha), /symlinks or submodules/);
  assert.equal(readFileSync(join(root, 'sonar-project.properties'), 'utf8'), 'sonar.host.url=https://example.invalid\n');
});

test('refuses submodule entries even though checkout does not populate them', t => {
  const {root, sha, trusted} = source(t);
  execFileSync('git', ['-C', root, 'update-index', '--add', '--cacheinfo', `160000,${sha},module`]);
  assert.throws(() => prepareSource(root, trusted, sha), /symlinks or submodules/);
});

test('queue scanning uses trusted controls and a separate candidate without running its commands', () => {
  const text = readFileSync('.github/workflows/sonar.yml', 'utf8');
  const analysis = text.split('  analysis:\n')[1].split('  queue-analysis:\n')[0];
  const queue = text.split('  queue-analysis:\n')[1];
  assert.ok(analysis.includes("github.event_name != 'workflow_run'"));
  assert.ok(text.includes('workflows: [PHPUnit Feature]'));
  assert.ok(queue.includes("github.event.workflow_run.event == 'merge_group'"));
  assert.ok(queue.includes("github.event.workflow_run.conclusion == 'success'"));
  assert.ok(queue.includes('ref: ${{ github.workflow_sha }}'));
  assert.ok(queue.includes('ref: ${{ steps.candidate.outputs.sha }}'));
  assert.deepEqual(queue.match(/^ *run:.*$/gm)?.map(line => line.trim()), [
    'run: node .sonar-control/scripts/ci/merge-queue-sonar.mjs validate',
    'run: node .sonar-control/scripts/ci/merge-queue-sonar.mjs prepare',
  ]);
  assert.equal((queue.match(/SONAR_TOKEN: /g) ?? []).length, 1);
  assert.ok(queue.includes('-Dsonar.scm.revision=${{ steps.candidate.outputs.sha }}'));
  assert.ok(queue.includes('-Dsonar.working.directory=${{ runner.temp }}/queue-sonar-work'));
});
