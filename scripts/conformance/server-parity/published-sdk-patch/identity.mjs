import assert from 'node:assert/strict';
import {readFileSync, writeFileSync} from 'node:fs';
import {resolve} from 'node:path';

const [directory, profilePath] = process.argv.slice(2);
const profile = JSON.parse(readFileSync(profilePath, 'utf8'));
const lock = readFileSync(resolve(directory, 'Cargo.lock'), 'utf8');
const sdk = lock.split(/^\[\[package\]\]$/m).find(block => /^name = "durable-workflow"$/m.test(block));
assert.ok(sdk, 'published Rust SDK in the compiled locked dependency graph');
const field = name => JSON.parse(new RegExp(`^${name} = (".*")$`, 'm').exec(sdk)?.[1] ?? 'null');
assert.equal(field('source'), 'registry+https://github.com/rust-lang/crates.io-index');
assert.equal(field('version'), profile.sdk_rust);
assert.equal(field('checksum'), profile.published_sdk_artifacts.rust.archive_sha256);
const report = JSON.parse(readFileSync(resolve(directory, 'python-install-report.json'), 'utf8'));
const installed = report.install.find(item => item.metadata.name === 'durable-workflow');
assert.ok(installed, 'published Python SDK in the actual pip install report');
assert.equal(installed.metadata.version, profile.sdk_python);
assert.equal(installed.download_info.archive_info.hashes.sha256, profile.published_sdk_artifacts.python.archive_sha256);
assert.equal(installed.is_direct, false, 'registry installation, without a source replacement');
assert.match(readFileSync(resolve(directory, 'rust-sdk-build.txt'), 'utf8'), /Finished `dev` profile/);
writeFileSync(resolve(directory, 'identity.json'), JSON.stringify({outcome: 'pass',
  rust: profile.published_sdk_artifacts.rust, python: profile.published_sdk_artifacts.python}, null, 2)+'\n');
