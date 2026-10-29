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
const workflow = readFileSync(join(repository, '.github/workflows/server-parity.yml'), 'utf8');
function workflowJob(id) {
  const match = new RegExp(`\\n  ${id}:\\n([\\s\\S]*?)(?=\\n  [a-z0-9-]+:|$)`).exec(workflow);
  assert.ok(match, `missing workflow job ${id}`);
  return match[1];
}

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

test('the producer shares only three files with pinned actions and bounded retention', () => {
  const producer = workflowJob('native-debug-build');
  assert.match(producer, /needs: changes\n {4}if: \$\{\{ needs\.changes\.outputs\.run == 'true' \}\}/);
  assert.match(producer, /persist-credentials: false/);
  assert.match(producer, /artifact_name: \$\{\{ steps\.artifact\.outputs\.name \}\}/);
  assert.match(producer, /producer_attempt: \$\{\{ steps\.artifact\.outputs\.attempt \}\}/);
  assert.match(producer, /native-debug-build-%s-%s/);
  assert.match(producer, /bash scripts\/ci\/native-debug-build\.sh native-debug-build/);
  assert.match(producer, /node scripts\/ci\/native-debug-artifact\.mjs verify native-debug-build/);
  assert.match(producer, /actions\/upload-artifact@043fb46d1a93c77aae656e7c1c64a875d1fc6a0a/);
  const paths = producer.match(/          path: \|\n((?: {12}.+\n)+)/g).at(-1);
  assert.equal(paths, '          path: |\n            native-debug-build/server\n'
    + '            native-debug-build/build.json\n            native-debug-build/SHA256SUMS\n');
  assert.match(producer, /retention-days: 1\n {10}if-no-files-found: error/);
  assert.doesNotMatch(producer, /cache\/save|contents: write|packages: write/);
});

test('all non-test consumers verify the same-run artifact before installing the executable', () => {
  for (const id of ['mysql-execution', 'postgres-storage', 'execution-and-codec']) {
    const block = workflowJob(id);
    assert.match(block, /needs: \[published-sdk-adapters, native-debug-build\]/);
    assert.match(block, /if: \$\{\{ !cancelled\(\) && needs\.published-sdk-adapters\.result == 'success' \}\}/);
    const download = block.slice(block.indexOf("      - name: Fetch this run's native debug server"),
      block.indexOf("      - name: Verify and install this run's native debug server"));
    assert.match(download, /matrix\.part != 'native-tests' && matrix\.part != 'native-tests-b'/);
    assert.match(download, /actions\/download-artifact@3e5f45b2cfb9172054b4087a40e8e0b5a5461e7c/);
    assert.match(download, /name: \$\{\{ needs\.native-debug-build\.outputs\.artifact_name \}\}/);
    assert.match(download, /digest-mismatch: error/);
    assert.doesNotMatch(download, /continue-on-error|run-id:|repository:/);
    assert.match(block, /NATIVE_BUILD_RUN_ATTEMPT: \$\{\{ needs\.native-debug-build\.outputs\.producer_attempt \}\}/);
    const verify = block.indexOf('node scripts/ci/native-debug-artifact.mjs verify native-debug-build');
    const install = block.indexOf('install -m 0755 native-debug-build/server rust-target/debug/durable-workflow-server');
    assert.ok(verify > 0 && install > verify, id);
  }
});

test('consumer preflight refuses a failed producer or missing identity before download', () => {
  for (const id of ['mysql-execution', 'postgres-storage', 'execution-and-codec']) {
    const block = workflowJob(id);
    const guard = block.slice(block.indexOf('      - name: Require a successful native debug producer'),
      block.indexOf("      - name: Fetch this run's native debug server"));
    assert.match(guard, /matrix\.part != 'native-tests' && matrix\.part != 'native-tests-b'/);
    assert.match(guard, /NATIVE_BUILD_RESULT: \$\{\{ needs\.native-debug-build\.result \}\}/);
    const body = guard.match(/        run: \|\n((?: {10}[^\n]*\n)+)/)?.[1]
      .split('\n').map(line => line.slice(10)).join('\n');
    assert.ok(body);
    const env = {...process.env, GITHUB_RUN_ID: '12345', GITHUB_RUN_ATTEMPT: '2',
      NATIVE_BUILD_RESULT: 'success', NATIVE_BUILD_ARTIFACT: 'native-debug-build-12345-1',
      NATIVE_BUILD_RUN_ATTEMPT: '1'};
    const run = changes => spawnSync('bash', ['-c', body], {env: {...env, ...changes}, encoding: 'utf8'});
    assert.equal(run({}).status, 0);
    for (const changes of [{NATIVE_BUILD_RESULT: 'failure'}, {NATIVE_BUILD_RESULT: 'skipped'},
      {NATIVE_BUILD_RESULT: 'cancelled'}, {NATIVE_BUILD_ARTIFACT: ''},
      {NATIVE_BUILD_ARTIFACT: 'native-debug-build-99999-1'}, {NATIVE_BUILD_RUN_ATTEMPT: ''},
      {NATIVE_BUILD_RUN_ATTEMPT: '3'}, {NATIVE_BUILD_RUN_ATTEMPT: '01'},
      {NATIVE_BUILD_RUN_ATTEMPT: '10000000000'}]) {
      assert.notEqual(run(changes).status, 0, `${id}: ${JSON.stringify(changes)}`);
    }
  }
});

function databaseCommand(id) {
  const line = workflowJob(id).split('\n').find(value => value.trimStart().startsWith(
    id === 'mysql-execution' ? "'case \"$DW_PARITY_PART\"" : "'if [ \"$DW_PARITY_PART\""));
  assert.ok(line?.trim().endsWith("'"));
  return line.trim().slice(1, -1);
}

function runDatabaseCommand(t, id, part, binary = true) {
  const root = mkdtempSync(join(tmpdir(), 'native-debug-cargo-'));
  t.after(() => rmSync(root, {recursive: true, force: true}));
  mkdirSync(join(root, 'bin'));
  mkdirSync(join(root, 'evidence'));
  mkdirSync(join(root, 'target/debug'), {recursive: true});
  if (binary) writeFileSync(join(root, 'target/debug/durable-workflow-server'), 'fixture\n', {mode: 0o755});
  const log = join(root, 'cargo.jsonl');
  writeFileSync(log, '');
  writeFileSync(join(root, 'bin/cargo'), `#!/usr/bin/env node
const fs = require('node:fs');
const args = process.argv.slice(2);
fs.appendFileSync(process.env.CARGO_CALL_LOG, JSON.stringify(args) + '\\n');
if(args.includes('--list')) console.log('case_a: test\\ncase_b: test');
`, {mode: 0o755});
  const script = databaseCommand(id).replaceAll('/evidence/', `${root}/evidence/`).replaceAll('/target/', `${root}/target/`);
  const run = spawnSync('bash', ['-c', script], {encoding: 'utf8', env: {...process.env,
    PATH: `${root}/bin:${process.env.PATH}`, CARGO_CALL_LOG: log, DW_PARITY_PART: part, DW_EXECUTION_SHARDS: '2'}});
  const calls = readFileSync(log, 'utf8').trim().split('\n').filter(Boolean).map(JSON.parse);
  return {...run, calls};
}

for (const part of ['native-tests', 'native-tests-b']) {
  test(`MySQL ${part} retains the original Cargo tests, sharding and build`, t => {
    const run = runDatabaseCommand(t, 'mysql-execution', part);
    assert.equal(run.status, 0, run.stderr);
    const flags = ['--locked', '--offline'];
    const expected = part === 'native-tests' ? [
      ['test', ...flags, '--lib', 'runtime::mysql::', '--', '--ignored', '--skip',
        'runtime::mysql::tests::bootstrap_child', '--nocapture', '--test-threads=2'],
    ] : [];
    expected.push(['test', ...flags, '--test', 'execution', '--', '--list', '--format', 'terse'],
      ['test', ...flags, '--test', 'execution', '--', '--exact', '--nocapture', '--test-threads=2',
        part === 'native-tests' ? 'case_a' : 'case_b'], ['build', ...flags]);
    assert.deepEqual(run.calls, expected);
  });
}

test('PostgreSQL native tests retain qualification, execution and build commands', t => {
  const run = runDatabaseCommand(t, 'postgres-storage', 'native-tests');
  assert.equal(run.status, 0, run.stderr);
  const flags = ['--locked', '--offline'];
  assert.deepEqual(run.calls, [
    ['test', ...flags, '--lib', 'runtime::postgres::tests::qualification_', '--', '--ignored', '--nocapture'],
    ['test', ...flags, '--test', 'execution', '--', '--nocapture'], ['build', ...flags],
  ]);
});

test('database corpus/profile parts never invoke Cargo and fail without their executable', t => {
  for (const id of ['mysql-execution', 'postgres-storage']) {
    for (const part of ['corpus', 'restarts', 'cohorts', 'sdk-directions', 'corrected-markers']) {
      const present = runDatabaseCommand(t, id, part);
      assert.equal(present.status, 0, `${id}/${part}: ${present.stderr}`);
      assert.deepEqual(present.calls, []);
      const missing = runDatabaseCommand(t, id, part, false);
      assert.notEqual(missing.status, 0, `${id}/${part} must fail without the verified executable`);
      assert.deepEqual(missing.calls, [], 'there must be no fallback build');
    }
  }
});
