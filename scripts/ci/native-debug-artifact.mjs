import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {createHash} from 'node:crypto';
import {lstatSync, readFileSync, readdirSync, writeFileSync} from 'node:fs';
import {resolve} from 'node:path';
import {fileURLToPath} from 'node:url';

const digest = value => createHash('sha256').update(value).digest('hex');
const git = (root, ...args) => execFileSync('/usr/bin/git', args, {cwd: root, encoding: 'utf8'}).trim();
const required = (env, key, pattern) => {
  const value = env[key];
  assert.equal(typeof value, 'string', `${key} is required`);
  assert.match(value, pattern, `${key} is invalid`);
  return value;
};

function identity(root, env, consumer) {
  const source_revision = git(root, 'rev-parse', 'HEAD');
  assert.equal(required(env, 'GITHUB_SHA', /^[a-f0-9]{40}$/), source_revision,
    'native debug source does not match the workflow checkout');
  assert.equal(git(root, 'status', '--porcelain', '--untracked-files=all', '--', 'rust'), '',
    'native debug source must match the committed native tree');
  const attempt = required(env, 'GITHUB_RUN_ATTEMPT', /^[1-9]\d{0,9}$/);
  const producerAttempt = consumer && env.NATIVE_BUILD_RUN_ATTEMPT !== undefined
    ? required(env, 'NATIVE_BUILD_RUN_ATTEMPT', /^[1-9]\d{0,9}$/) : attempt;
  assert.ok(Number(producerAttempt) <= Number(attempt), 'native debug producer attempt is in the future');
  return {
    source_revision,
    native_tree: git(root, 'rev-parse', 'HEAD:rust'),
    cargo_lock_sha256: digest(readFileSync(resolve(root, 'rust/Cargo.lock'))),
    compiler_image: required(env, 'NATIVE_RUST_IMAGE', /^mirror\.gcr\.io\/library\/rust@sha256:[a-f0-9]{64}$/),
    repository: required(env, 'GITHUB_REPOSITORY', /^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/),
    github_run_id: required(env, 'GITHUB_RUN_ID', /^[1-9]\d{0,19}$/),
    github_run_attempt: producerAttempt,
    runner_os: required(env, 'RUNNER_OS', /^Linux$/),
    runner_arch: required(env, 'RUNNER_ARCH', /^(X64|ARM64)$/),
    profile: 'dev',
    locked: true,
    jobs: 2,
    resources: {nano_cpus: 2000000000, memory_bytes: 6442450944, memory_swap_bytes: 6442450944},
  };
}

function regularFile(path) {
  assert.ok(lstatSync(path).isFile(), `native debug artifact requires a regular file: ${path}`);
  return readFileSync(path);
}

function checksums(directory) {
  return `${digest(regularFile(resolve(directory, 'server')))}  server\n`
    + `${digest(regularFile(resolve(directory, 'build.json')))}  build.json\n`;
}

export function createArtifact(directory, compiler, seconds, {root = process.cwd(), env = process.env} = {}) {
  assert.match(compiler, /^rustc \d+\.\d+\.\d+[^\r\n]{0,200}$/,
    'native debug artifact needs the actual compiler version');
  assert.ok(Number.isSafeInteger(seconds) && seconds >= 0, 'invalid native debug build duration');
  const record = {
    ...identity(root, env, false),
    compiler,
    binary_sha256: digest(regularFile(resolve(directory, 'server'))),
    build_duration_seconds: seconds,
  };
  writeFileSync(resolve(directory, 'build.json'), `${JSON.stringify(record, null, 2)}\n`);
  writeFileSync(resolve(directory, 'SHA256SUMS'), checksums(directory));
  return record;
}

export function verifyArtifact(directory, {root = process.cwd(), env = process.env} = {}) {
  assert.ok(lstatSync(directory).isDirectory(), 'native debug artifact must be a directory');
  assert.deepEqual(readdirSync(directory).sort(), ['SHA256SUMS', 'build.json', 'server'],
    'native debug artifact must contain only the binary and its identity/checksums');
  const record = JSON.parse(regularFile(resolve(directory, 'build.json')).toString('utf8'));
  const expected = identity(root, env, true);
  for (const [key, value] of Object.entries(expected)) {
    assert.deepEqual(record[key], value, `native debug artifact ${key} does not match this job`);
  }
  assert.match(record.compiler, /^rustc \d+\.\d+\.\d+[^\r\n]{0,200}$/,
    'invalid native debug compiler version');
  assert.ok(Number.isSafeInteger(record.build_duration_seconds) && record.build_duration_seconds >= 0,
    'invalid native debug build duration');
  assert.equal(record.binary_sha256, digest(regularFile(resolve(directory, 'server'))),
    'native debug binary checksum mismatch');
  assert.equal(regularFile(resolve(directory, 'SHA256SUMS')).toString('utf8'), checksums(directory),
    'native debug artifact checksum list mismatch');
  return record;
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  try {
    const [action, directory, compiler, seconds, ...extra] = process.argv.slice(2);
    assert.ok(extra.length === 0 && (directory || action === 'identity'),
      'usage: native-debug-artifact.mjs identity | create DIR COMPILER SECONDS | verify DIR');
    let record;
    if (action === 'identity' && directory === undefined) {
      record = identity(process.cwd(), process.env, false);
    } else if (action === 'create') {
      record = createArtifact(directory, compiler, Number(seconds));
    } else if (action === 'verify' && compiler === undefined) {
      record = verifyArtifact(directory);
    } else {
      assert.fail('unknown native debug artifact operation');
    }
    process.stdout.write(`${JSON.stringify(record)}\n`);
  } catch (error) {
    process.stderr.write(`Native debug artifact refused: ${error.message}\n`);
    process.exitCode = 1;
  }
}
