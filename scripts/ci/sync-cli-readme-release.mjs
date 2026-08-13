#!/usr/bin/env node

import {readFile, writeFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';

const repositoryRoot = fileURLToPath(new URL('../..', import.meta.url));
const readmePath = `${repositoryRoot}/README.md`;
const releaseAuthorityPath = `${repositoryRoot}/resources/release/cli-readme-release.json`;
const cliRepository = 'durable-workflow/cli';
const cliApiUrl = `https://api.github.com/repos/${cliRepository}`;
const installerUrl = 'https://durable-workflow.com/install.sh';
const generatedStart = '<!-- BEGIN GENERATED CLI INSTALL -->';
const generatedEnd = '<!-- END GENERATED CLI INSTALL -->';
const requiredAssets = [
  'dw.phar',
  'dw-linux-x86_64',
  'dw-linux-aarch64',
  'dw-macos-aarch64',
  'dw-windows-x86_64.exe',
  'dw.rb',
  'install.sh',
  'install.ps1',
  'verify-release.sh',
  'SHA256SUMS',
];

function fail(message) {
  throw new Error(message);
}

function parseJson(bytes, label) {
  try {
    return JSON.parse(bytes.toString('utf8'));
  } catch (error) {
    fail(`${label} is not valid JSON: ${error.message}`);
  }
}

function validateVersion(version, label) {
  if (typeof version !== 'string' || !/^2\.0\.0-rc\.(?:0|[1-9][0-9]*)$/.test(version)) {
    fail(`${label} must be an exact Durable Workflow 2.0 release candidate`);
  }

  return version;
}

function releaseUrl(version) {
  return `https://github.com/${cliRepository}/releases/tag/${version}`;
}

function releaseInstallerUrl(version) {
  return `https://github.com/${cliRepository}/releases/download/${version}/install.sh`;
}

function validateReleaseAuthority(authority, label) {
  if (!authority || typeof authority !== 'object' || Array.isArray(authority)) {
    fail(`${label} must be a JSON object`);
  }
  if (authority.schema !== 'durable-workflow.cli-readme-release/v1') {
    fail(`${label} uses unsupported schema ${String(authority.schema)}`);
  }
  if (authority.repository !== cliRepository) {
    fail(`${label} must select ${cliRepository}`);
  }
  if (authority.channel !== '2.0-rc') {
    fail(`${label} must select the independent CLI 2.0 RC channel`);
  }

  const version = validateVersion(authority.version, `${label} version`);
  if (authority.tag !== version) {
    fail(`${label} tag must match its version`);
  }
  if (typeof authority.commit !== 'string' || !/^[0-9a-f]{40}$/.test(authority.commit)) {
    fail(`${label} commit must be a full lowercase Git commit`);
  }
  if (authority.release_url !== releaseUrl(version)) {
    fail(`${label} release URL must identify its versioned public CLI release`);
  }

  return {
    channel: authority.channel,
    commit: authority.commit,
    releaseUrl: authority.release_url,
    repository: authority.repository,
    tag: authority.tag,
    version,
  };
}

function installerCommand() {
  return `curl -fsSL ${installerUrl} | sh`;
}

function generatedReadmeRegion() {
  return `${generatedStart}
\`\`\`bash
# Install the supported CLI prerelease channel
${installerCommand()}
export PATH="$HOME/.local/bin:$PATH"
\`\`\`
${generatedEnd}`;
}

function replaceGeneratedReadmeRegion(readme) {
  const start = readme.indexOf(generatedStart);
  const end = readme.indexOf(generatedEnd);
  if (start === -1 || end === -1 || end < start) {
    fail('README.md must contain one generated CLI install region');
  }
  if (readme.indexOf(generatedStart, start + generatedStart.length) !== -1
      || readme.indexOf(generatedEnd, end + generatedEnd.length) !== -1) {
    fail('README.md contains duplicate generated CLI install markers');
  }

  const regionEnd = end + generatedEnd.length;
  return `${readme.slice(0, start)}${generatedReadmeRegion()}${readme.slice(regionEnd)}`;
}

function publicGithubToken() {
  const actionsServerUrl = process.env.GITHUB_SERVER_URL?.replace(/\/+$/, '');
  if (actionsServerUrl && actionsServerUrl !== 'https://github.com') {
    fail('public GitHub API release checks are unavailable outside GitHub Actions; use --check --offline');
  }

  const token = process.env.GH_TOKEN || process.env.GITHUB_TOKEN;
  if (!token) {
    fail('public GitHub API release checks require a read-only GH_TOKEN or GITHUB_TOKEN');
  }

  return token;
}

async function fetchJson(url, label) {
  const token = publicGithubToken();
  const response = await fetch(url, {
    headers: {
      accept: 'application/vnd.github+json',
      authorization: `Bearer ${token}`,
      'user-agent': 'durable-workflow-server-cli-readme-release-sync',
      'x-github-api-version': '2022-11-28',
    },
    redirect: 'follow',
  });
  if (!response.ok) {
    fail(`${label} returned HTTP ${response.status}: ${url}`);
  }

  return parseJson(Buffer.from(await response.arrayBuffer()), label);
}

function validatePublicRelease(release, label) {
  if (!release || typeof release !== 'object' || Array.isArray(release)) {
    fail(`${label} must be a JSON object`);
  }
  if (release.draft !== false) {
    fail(`${label} must be published`);
  }

  const version = validateVersion(release.tag_name, `${label} tag`);
  const assetNames = Array.isArray(release.assets)
    ? release.assets.map((asset) => asset?.name).filter((name) => typeof name === 'string')
    : [];
  const missingAssets = requiredAssets.filter((asset) => !assetNames.includes(asset));
  if (missingAssets.length > 0) {
    fail(`${label} is missing required assets: ${missingAssets.join(', ')}`);
  }

  return {release, version};
}

function compareReleaseCandidates(left, right) {
  const leftNumber = Number.parseInt(left.version.slice('2.0.0-rc.'.length), 10);
  const rightNumber = Number.parseInt(right.version.slice('2.0.0-rc.'.length), 10);

  return leftNumber - rightNumber;
}

async function resolveTagCommit(version) {
  const reference = await fetchJson(
    `${cliApiUrl}/git/ref/tags/${encodeURIComponent(version)}`,
    `public CLI tag ${version}`,
  );
  let object = reference?.object;

  for (let depth = 0; depth < 4 && object?.type === 'tag'; depth += 1) {
    const tag = await fetchJson(`${cliApiUrl}/git/tags/${object.sha}`, `public CLI annotated tag ${version}`);
    object = tag?.object;
  }

  if (object?.type !== 'commit' || typeof object.sha !== 'string' || !/^[0-9a-f]{40}$/.test(object.sha)) {
    fail(`public CLI tag ${version} does not resolve to one immutable commit`);
  }

  return object.sha;
}

async function publicAuthorityForRelease(release, label) {
  const current = validatePublicRelease(release, label);
  const commit = await resolveTagCommit(current.version);

  return {
    schema: 'durable-workflow.cli-readme-release/v1',
    repository: cliRepository,
    channel: '2.0-rc',
    version: current.version,
    tag: current.version,
    commit,
    release_url: releaseUrl(current.version),
  };
}

async function currentPublicAuthority() {
  const releases = await fetchJson(`${cliApiUrl}/releases?per_page=100`, 'public CLI releases');
  if (!Array.isArray(releases)) {
    fail('public CLI releases response must be an array');
  }

  const candidates = [];
  for (const release of releases) {
    try {
      candidates.push(validatePublicRelease(release, 'public CLI release'));
    } catch (error) {
      if (typeof release?.tag_name === 'string' && release.tag_name.startsWith('2.0.0-rc.')) {
        throw error;
      }
    }
  }
  candidates.sort(compareReleaseCandidates);
  const current = candidates.at(-1);
  if (!current) {
    fail('public CLI releases do not contain a complete 2.0 release candidate');
  }

  return publicAuthorityForRelease(current.release, `public CLI release ${current.version}`);
}

async function localReleaseAuthority() {
  const bytes = await readFile(releaseAuthorityPath);
  return validateReleaseAuthority(parseJson(bytes, 'checked-in CLI release authority'), 'checked-in CLI release authority');
}

async function checkGeneratedReadme() {
  const readme = await readFile(readmePath, 'utf8');
  const expected = replaceGeneratedReadmeRegion(readme);
  if (readme !== expected) {
    fail('README.md CLI channel command is stale; run node scripts/ci/sync-cli-readme-release.mjs --write');
  }
}

async function checkPublicAuthority(local) {
  const publicRelease = await fetchJson(
    `${cliApiUrl}/releases/tags/${encodeURIComponent(local.version)}`,
    `public CLI release ${local.version}`,
  );
  const published = await publicAuthorityForRelease(publicRelease, `public CLI release ${local.version}`);
  if (published.commit !== local.commit) {
    fail(`checked-in CLI release commit ${local.commit} does not match public tag commit ${published.commit}`);
  }

  const current = validateReleaseAuthority(await currentPublicAuthority(), 'current public CLI release authority');
  if (current.version !== local.version) {
    fail(`README CLI release ${local.version} is stale; the public CLI 2.0 RC channel selects ${current.version}`);
  }
}

async function writeCurrentRelease() {
  const authority = await currentPublicAuthority();
  const readme = await readFile(readmePath, 'utf8');
  const updatedReadme = replaceGeneratedReadmeRegion(readme);

  await writeFile(releaseAuthorityPath, `${JSON.stringify(authority, null, 2)}\n`);
  await writeFile(readmePath, updatedReadme);
  process.stdout.write(`Generated README CLI channel command and refreshed release authority to ${authority.version}.\n`);
}

function usage() {
  process.stderr.write('Usage: node scripts/ci/sync-cli-readme-release.mjs (--check [--offline] | --write | --print <version|commit|assets|installer-command|installer-url|release-installer-url|release-url>)\n');
}

async function main() {
  const args = process.argv.slice(2);
  if (args[0] === '--write' && args.length === 1) {
    await writeCurrentRelease();
    return;
  }

  const local = await localReleaseAuthority();
  if (args[0] === '--check' && (args.length === 1 || (args.length === 2 && args[1] === '--offline'))) {
    await checkGeneratedReadme();
    if (args[1] !== '--offline') {
      await checkPublicAuthority(local);
    }
    process.stdout.write(`README CLI channel command is current; release authority selects ${local.version}.\n`);
    return;
  }

  if (args[0] === '--print' && args.length === 2) {
    const fields = {
      assets: requiredAssets.join('\n'),
      commit: local.commit,
      'installer-command': installerCommand(),
      'installer-url': installerUrl,
      'release-installer-url': releaseInstallerUrl(local.version),
      'release-url': local.releaseUrl,
      version: local.version,
    };
    if (!Object.hasOwn(fields, args[1])) {
      usage();
      process.exitCode = 2;
      return;
    }
    process.stdout.write(`${fields[args[1]]}\n`);
    return;
  }

  usage();
  process.exitCode = 2;
}

main().catch((error) => {
  process.stderr.write(`error: ${error.message}\n`);
  process.exitCode = 1;
});
