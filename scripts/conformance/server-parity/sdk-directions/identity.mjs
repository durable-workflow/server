// Reuse the locked registry/wheel checks, then verify the compiled crate's VCS receipt.
import '../published-sdk-patch/identity.mjs';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {resolve} from 'node:path';

const [directory, profilePath] = process.argv.slice(2);
const profile = JSON.parse(readFileSync(profilePath, 'utf8'));
const vcs = JSON.parse(readFileSync(resolve(directory, 'rust-sdk-vcs.json'), 'utf8'));
assert.equal(vcs.git.sha1, profile.published_sdk_artifacts.rust.source_commit,
  'actual compiled published Rust crate source commit');
