import assert from 'node:assert/strict';
import {createHash} from 'node:crypto';
import {readFileSync, writeFileSync, readdirSync} from 'node:fs';
import {resolve} from 'node:path';

const [directory, profilePath] = process.argv.slice(2);
const artifacts = JSON.parse(readFileSync(profilePath));
const sha = path => createHash('sha256').update(readFileSync(path)).digest('hex');
const lockPath = resolve(directory, 'bin/Cargo.lock');
assert.equal(sha(lockPath), artifacts.rust_consumer_cargo_lock_sha256);
const block = readFileSync(lockPath, 'utf8').split(/^\[\[package\]\]$/m)
  .find(value => /^name = "durable-workflow"$/m.test(value));
assert(block, 'normal published Rust SDK in locked graph');
const field = name => JSON.parse(new RegExp(`^${name} = (".*")$`, 'm').exec(block)?.[1] ?? 'null');
assert.equal(field('source'), 'registry+https://github.com/rust-lang/crates.io-index');
assert.equal(field('version'), artifacts.sdk_rust);
assert.equal(field('checksum'), artifacts.published_sdk_artifacts.rust.archive_sha256);
assert.match(readFileSync(resolve(directory, 'rust-build.txt'), 'utf8'), /Finished `dev` profile/);
const registry = resolve(directory, 'cargo/registry/src');
const sdk = readdirSync(registry).map(name => resolve(registry, name, `durable-workflow-${artifacts.sdk_rust}`))
  .find(path => { try { readFileSync(resolve(path, '.cargo_vcs_info.json')); return true; } catch { return false; } });
assert(sdk, 'actual normally installed Rust source');
assert.equal(JSON.parse(readFileSync(resolve(sdk, '.cargo_vcs_info.json'))).git.sha1,
  artifacts.published_sdk_artifacts.rust.source_commit);
const installed = JSON.parse(readFileSync(resolve(directory, 'php/vendor/composer/installed.json')));
const php = installed.packages.find(package_ => package_.name === 'durable-workflow/sdk');
assert.equal(php.version, artifacts.sdk_php);
assert.equal(php.source.reference, artifacts.sdk_php_source_commit);
assert.equal(sha(resolve(directory, 'php/composer.lock')), artifacts.sdk_composer_lock_sha256);
for (const [path, expected] of Object.entries(artifacts.published_sdk_artifacts.php.loaded_sources)) {
  assert.equal(sha(resolve(directory, 'php/vendor/durable-workflow/sdk', path)), expected);
}
const report = JSON.parse(readFileSync(resolve(directory, 'python250-install-report.json')));
const python = report.install.find(item => item.metadata.name === 'durable-workflow');
assert.equal(python.metadata.version, artifacts.historical_python_producer.version);
assert.equal(python.download_info.archive_info.hashes.sha256, artifacts.historical_python_producer.archive_sha256);
assert.equal(python.is_direct, false);
writeFileSync(resolve(directory, 'identity.json'), JSON.stringify({outcome: 'pass',
  php: artifacts.published_sdk_artifacts.php, rust: artifacts.published_sdk_artifacts.rust,
  historical_python: artifacts.historical_python_producer,
  binary_sha256: sha(resolve(directory, 'bin/rust-worker'))}, null, 2)+'\n');
