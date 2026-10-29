import assert from 'node:assert/strict';
import {execFileSync, spawnSync} from 'node:child_process';
import {createHash} from 'node:crypto';
import {copyFileSync, existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, symlinkSync, writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {dirname, join, resolve} from 'node:path';
import test from 'node:test';
import {fileURLToPath} from 'node:url';
import {createArtifact, verifyArtifact} from '../../scripts/ci/native-debug-artifact.mjs';

const repository = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const compiler = 'rustc 1.99.0 (test fixture)';
const digest = path => createHash('sha256').update(readFileSync(path)).digest('hex');

function fixture(t) {
  const root = mkdtempSync(join(tmpdir(), 'native-debug-artifact-'));
  t.after(() => rmSync(root, {recursive: true, force: true}));
  mkdirSync(join(root, 'rust/src'), {recursive: true});
  writeFileSync(join(root, 'rust/Cargo.lock'), '# fixture lock\n');
  writeFileSync(join(root, 'rust/Cargo.toml'), '[package]\nname="fixture"\nversion="0.0.0"\n');
  writeFileSync(join(root, 'rust/src/main.rs'), 'fn main() {}\n');
  mkdirSync(join(root, 'scripts/ci'), {recursive: true});
  for (const name of ['native-debug-artifact.mjs', 'native-debug-build.sh']) {
    copyFileSync(join(repository, 'scripts/ci', name), join(root, 'scripts/ci', name));
  }
  const git = (...args) => execFileSync('git', args, {cwd: root, encoding: 'utf8'}).trim();
  git('init', '-q');
  git('add', '.');
  git('-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '-qm', 'fixture');
  const env = {...process.env, GITHUB_SHA: git('rev-parse', 'HEAD'), GITHUB_REPOSITORY: 'fixture/server',
    GITHUB_RUN_ID: '12345', GITHUB_RUN_ATTEMPT: '1', GITHUB_JOB: 'native-debug-build',
    NATIVE_RUST_IMAGE: `mirror.gcr.io/library/rust@sha256:${'a'.repeat(64)}`, RUNNER_OS: 'Linux', RUNNER_ARCH: 'X64'};
  delete env.NATIVE_BUILD_RUN_ATTEMPT;
  const directory = join(root, 'artifact');
  mkdirSync(directory);
  writeFileSync(join(directory, 'server'), 'not an executable: artifact-integrity test fixture\n');
  const options = {root, env};
  createArtifact(directory, compiler, 7, options);
  return {root, directory, env, options};
}

test('a consumer accepts only the bound binary and identity files', t => {
  const {directory, options} = fixture(t);
  const actual = verifyArtifact(directory, options);
  assert.equal(actual.build_duration_seconds, 7);
  assert.equal(actual.profile, 'dev');
  assert.equal(actual.locked, true);
});

for (const [name, key, value] of [
  ['another workflow run', 'GITHUB_RUN_ID', '54321'],
  ['another repository', 'GITHUB_REPOSITORY', 'other/server'],
  ['another compiler', 'NATIVE_RUST_IMAGE', `mirror.gcr.io/library/rust@sha256:${'b'.repeat(64)}`],
  ['another architecture', 'RUNNER_ARCH', 'ARM64'],
  ['another producer attempt', 'GITHUB_RUN_ATTEMPT', '2'],
  ['another checkout', 'GITHUB_SHA', '0'.repeat(40)],
]) {
  test(`refuses ${name}`, t => {
    const {directory, root, env} = fixture(t);
    assert.throws(() => verifyArtifact(directory, {root, env: {...env, [key]: value}}));
  });
}

test('a failed-job retry can use its successful producer output from this same run', t => {
  const {directory, root, env} = fixture(t);
  const retry = {...env, GITHUB_RUN_ATTEMPT: '2', NATIVE_BUILD_RUN_ATTEMPT: '1'};
  assert.equal(verifyArtifact(directory, {root, env: retry}).github_run_attempt, '1');
  assert.throws(() => verifyArtifact(directory, {root, env: {...retry, NATIVE_BUILD_RUN_ATTEMPT: '3'}}));
});

for (const [key, value] of [['native_tree', '0'.repeat(40)], ['cargo_lock_sha256', '0'.repeat(64)],
  ['profile', 'release'], ['locked', false], ['jobs', 1], ['compiler', ''],
  ['resources', {nano_cpus: 2000000000, memory_bytes: 128, memory_swap_bytes: 128}],
  ['build_duration_seconds', -1]]) {
  test(`refuses a changed ${key} even with a recomputed metadata checksum`, t => {
    const {directory, options} = fixture(t);
    const path = join(directory, 'build.json');
    const record = JSON.parse(readFileSync(path));
    record[key] = value;
    writeFileSync(path, `${JSON.stringify(record)}\n`);
    writeFileSync(join(directory, 'SHA256SUMS'),
      `${digest(join(directory, 'server'))}  server\n${digest(path)}  build.json\n`);
    const reason = key === 'build_duration_seconds' ? 'duration' : key;
    assert.throws(() => verifyArtifact(directory, options), new RegExp(reason));
  });
}

test('refuses an edited native working tree before creating or accepting an artifact', t => {
  const {directory, root, options} = fixture(t);
  writeFileSync(join(root, 'rust/src/main.rs'), 'fn main() { panic!() }\n');
  assert.throws(() => verifyArtifact(directory, options), /committed native tree/);
  assert.throws(() => createArtifact(directory, compiler, 1, options), /committed native tree/);
});

test('refuses binary tampering, checksum-list tampering and compiler-cache payloads', t => {
  const {directory, options} = fixture(t);
  writeFileSync(join(directory, 'server'), 'substituted binary\n');
  assert.throws(() => verifyArtifact(directory, options), /binary checksum/);
  createArtifact(directory, compiler, 7, options);
  writeFileSync(join(directory, 'SHA256SUMS'), 'untrusted list\n');
  assert.throws(() => verifyArtifact(directory, options), /checksum list/);
  createArtifact(directory, compiler, 7, options);
  mkdirSync(join(directory, 'target'));
  assert.throws(() => verifyArtifact(directory, options), /only the binary/);
});

test('refuses symlinked binary and metadata files', t => {
  const {root, directory, options} = fixture(t);
  for (const name of ['server', 'build.json', 'SHA256SUMS']) {
    const path = join(directory, name);
    const outside = join(root, `${name}.outside`);
    copyFileSync(path, outside);
    rmSync(path);
    symlinkSync(outside, path);
    assert.throws(() => verifyArtifact(directory, options), /regular file/);
    rmSync(path);
    copyFileSync(outside, path);
  }
});

test('identity preflight and existing-output guard refuse before any container starts', t => {
  const {root, env, directory} = fixture(t);
  const bin = fakeDocker(root);
  const log = join(root, 'docker-calls.jsonl');
  const commandEnv = {...env, PATH: `${bin}:${env.PATH}`, FAKE_DOCKER_LOG: log};
  const args = ['scripts/ci/native-debug-build.sh', 'artifact'];
  const existing = readFileSync(join(directory, 'server'));
  const run = spawnSync('bash', args, {cwd: root, env: commandEnv, encoding: 'utf8'});
  assert.notEqual(run.status, 0);
  assert.deepEqual(readFileSync(join(directory, 'server')), existing);
  assert.equal(existsSync(log), false);
  const badIdentity = spawnSync('bash', ['scripts/ci/native-debug-build.sh', 'other'],
    {cwd: root, env: {...commandEnv, GITHUB_SHA: '0'.repeat(40)}, encoding: 'utf8'});
  assert.notEqual(badIdentity.status, 0);
  assert.match(badIdentity.stderr, /workflow checkout/);
  assert.equal(existsSync(log), false);
});

function fakeDocker(root) {
  const bin = join(root, 'fake-bin');
  mkdirSync(bin);
  writeFileSync(join(bin, 'docker'), `#!/usr/bin/env node
import('node:fs').then(async fs => {
  const {createHash} = await import('node:crypto');
  const args = process.argv.slice(2);
  fs.appendFileSync(process.env.FAKE_DOCKER_LOG, JSON.stringify(args) + '\\n');
  if (args[0] !== 'run') return;
  const cid = createHash('sha256').update(args.join(' ')).digest('hex');
  fs.writeFileSync(args[args.indexOf('--cidfile') + 1], cid + '\\n');
  if (!args.some(arg => arg.includes('cargo build'))) return;
  if (process.env.FAKE_BUILD_FAIL) process.exit(23);
  const target = args.find(arg => arg.endsWith(':/target')).slice(0, -8);
  fs.mkdirSync(target + '/debug', {recursive: true});
  fs.writeFileSync(target + '/debug/durable-workflow-server', 'fake compiled server\\n');
  process.stdout.write('rustc 1.99.0 (fixture)\\n');
});
`, {mode: 0o755});
  return bin;
}

for (const failure of [false, true]) {
  test(`the build helper ${failure ? 'cleans its own failed build' : 'exports only the thin artifact'}`, t => {
    const {root, env} = fixture(t);
    const bin = fakeDocker(root);
    const log = join(root, 'docker-calls.jsonl');
    const commandEnv = {...env, PATH: `${bin}:${env.PATH}`, FAKE_DOCKER_LOG: log};
    if (failure) commandEnv.FAKE_BUILD_FAIL = '1';
    const run = spawnSync('bash', ['scripts/ci/native-debug-build.sh', 'bundle'], {cwd: root, env: commandEnv, encoding: 'utf8'});
    assert.equal(run.status === 0, !failure, run.stderr);
    const calls = readFileSync(log, 'utf8').trim().split('\n').map(JSON.parse);
    const builds = calls.filter(args => args[0] === 'run');
    assert.equal(builds.length, 2);
    for (const args of builds) {
      assert.ok(args.includes('--init') && args.includes('--network=none'));
      assert.ok(args.includes('--cpus=2') && args.includes(env.NATIVE_RUST_IMAGE));
      assert.ok(args.includes(`--user=${process.getuid()}:${process.getgid()}`));
    }
    assert.deepEqual(builds[0].slice(-6), ['cargo', 'clean', '--locked', '--offline', '--package', 'durable-workflow-server']);
    assert.ok(builds[1].some(arg => arg.includes('cargo build --locked --offline --bin durable-workflow-server')));
    assert.ok(builds[1].includes('--memory=6g') && builds[1].includes('--memory-swap=6g'));
    assert.equal(calls.filter(args => args[0] === 'rm' && /^[a-f0-9]{64}$/.test(args.at(-1))).length, 2);
    if (!failure) verifyArtifact(join(root, 'bundle'), {root, env});
  });
}
