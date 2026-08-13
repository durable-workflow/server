#!/usr/bin/env node

import {readFile, writeFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';

const repositoryRoot = fileURLToPath(new URL('../..', import.meta.url));
const readmePath = `${repositoryRoot}/README.md`;
const channelAuthorityPath = `${repositoryRoot}/resources/release/cli-readme-channel.json`;
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

function validateExactKeys(value, expectedKeys, label) {
  const actual = Object.keys(value).sort();
  const expected = [...expectedKeys].sort();
  if (actual.length !== expected.length || actual.some((key, index) => key !== expected[index])) {
    fail(`${label} must contain exactly: ${expected.join(', ')}`);
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

function validateChannelAuthority(authority, label) {
  if (!authority || typeof authority !== 'object' || Array.isArray(authority)) {
    fail(`${label} must be a JSON object`);
  }
  validateExactKeys(authority, ['schema', 'repository', 'channel', 'installer_url'], label);
  if (authority.schema !== 'durable-workflow.cli-readme-channel/v1') {
    fail(`${label} uses unsupported schema ${String(authority.schema)}`);
  }
  if (authority.repository !== cliRepository) {
    fail(`${label} must select ${cliRepository}`);
  }
  if (authority.channel !== '2.0-rc') {
    fail(`${label} must select the independent CLI 2.0 RC channel`);
  }
  if (authority.installer_url !== installerUrl) {
    fail(`${label} must select the published supported-channel installer`);
  }

  return {
    channel: authority.channel,
    installerUrl: authority.installer_url,
    repository: authority.repository,
  };
}

function validateReleaseEvidence(evidence, label) {
  if (!evidence || typeof evidence !== 'object' || Array.isArray(evidence)) {
    fail(`${label} must be a JSON object`);
  }
  validateExactKeys(evidence, [
    'schema',
    'repository',
    'channel',
    'version',
    'tag',
    'commit',
    'release_url',
    'release_installer_url',
    'required_assets',
  ], label);
  if (evidence.schema !== 'durable-workflow.cli-channel-resolution/v1') {
    fail(`${label} uses unsupported schema ${String(evidence.schema)}`);
  }
  if (evidence.repository !== cliRepository) {
    fail(`${label} must select ${cliRepository}`);
  }
  if (evidence.channel !== '2.0-rc') {
    fail(`${label} must resolve the CLI 2.0 RC channel`);
  }

  const version = validateVersion(evidence.version, `${label} version`);
  if (evidence.tag !== version) {
    fail(`${label} tag must match its version`);
  }
  if (typeof evidence.commit !== 'string' || !/^[0-9a-f]{40}$/.test(evidence.commit)) {
    fail(`${label} commit must be a full lowercase Git commit`);
  }
  if (evidence.release_url !== releaseUrl(version)) {
    fail(`${label} release URL must identify its versioned public CLI release`);
  }
  if (evidence.release_installer_url !== releaseInstallerUrl(version)) {
    fail(`${label} installer URL must identify its versioned public CLI installer`);
  }
  if (!Array.isArray(evidence.required_assets)
      || evidence.required_assets.length !== requiredAssets.length
      || evidence.required_assets.some((asset, index) => asset !== requiredAssets[index])) {
    fail(`${label} must contain the complete required CLI asset set`);
  }

  return {
    assets: [...evidence.required_assets],
    channel: evidence.channel,
    commit: evidence.commit,
    releaseInstallerUrl: evidence.release_installer_url,
    releaseUrl: evidence.release_url,
    repository: evidence.repository,
    tag: evidence.tag,
    version,
  };
}

function installerCommand(authority) {
  return `curl -fsSL ${authority.installerUrl} | sh`;
}

function generatedReadmeRegion(authority) {
  return `${generatedStart}
\`\`\`bash
# Install the supported CLI prerelease channel
${installerCommand(authority)}
export PATH="$HOME/.local/bin:$PATH"
\`\`\`
${generatedEnd}`;
}

function replaceGeneratedReadmeRegion(readme, authority) {
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
  return `${readme.slice(0, start)}${generatedReadmeRegion(authority)}${readme.slice(regionEnd)}`;
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
      'user-agent': 'durable-workflow-server-cli-readme-channel-check',
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

async function publicEvidenceForRelease(release, label) {
  const current = validatePublicRelease(release, label);
  const commit = await resolveTagCommit(current.version);

  return {
    schema: 'durable-workflow.cli-channel-resolution/v1',
    repository: cliRepository,
    channel: '2.0-rc',
    version: current.version,
    tag: current.version,
    commit,
    release_url: releaseUrl(current.version),
    release_installer_url: releaseInstallerUrl(current.version),
    required_assets: [...requiredAssets],
  };
}

async function currentPublicEvidence() {
  const releases = await fetchJson(`${cliApiUrl}/releases?per_page=100`, 'public CLI releases');
  if (!Array.isArray(releases)) {
    fail('public CLI releases response must be an array');
  }

  const candidates = releases
    .filter((release) => typeof release?.tag_name === 'string'
      && /^2\.0\.0-rc\.(?:0|[1-9][0-9]*)$/.test(release.tag_name))
    .map((release) => ({release, version: release.tag_name}))
    .sort(compareReleaseCandidates);
  const current = candidates.at(-1);
  if (!current) {
    fail('public CLI releases do not contain a 2.0 release candidate');
  }

  return publicEvidenceForRelease(current.release, `public CLI release ${current.version}`);
}

async function localChannelAuthority() {
  const bytes = await readFile(channelAuthorityPath);
  return validateChannelAuthority(parseJson(bytes, 'checked-in CLI channel authority'), 'checked-in CLI channel authority');
}

async function localReleaseEvidence(path) {
  const bytes = await readFile(path);
  return validateReleaseEvidence(parseJson(bytes, 'CLI channel resolution evidence'), 'CLI channel resolution evidence');
}

async function checkGeneratedReadme(authority) {
  const readme = await readFile(readmePath, 'utf8');
  const expected = replaceGeneratedReadmeRegion(readme, authority);
  if (readme !== expected) {
    fail('README.md CLI channel command is stale; run node scripts/ci/sync-cli-readme-release.mjs --write');
  }
}

async function writeChannelReadme(authority) {
  const readme = await readFile(readmePath, 'utf8');
  const updatedReadme = replaceGeneratedReadmeRegion(readme, authority);

  await writeFile(readmePath, updatedReadme);
  process.stdout.write('Generated the README CLI command from the static supported-channel authority.\n');
}

function usage() {
  process.stderr.write('Usage: node scripts/ci/sync-cli-readme-release.mjs (--check [--offline | --evidence <path>] | --write | --print <assets|channel|installer-command|installer-url|repository> | --print-evidence <path> <assets|commit|release-installer-url|release-url|version>)\n');
}

async function main() {
  const args = process.argv.slice(2);
  const authority = await localChannelAuthority();

  if (args[0] === '--write' && args.length === 1) {
    await writeChannelReadme(authority);
    return;
  }

  if (args[0] === '--check'
      && (args.length === 1
        || (args.length === 2 && args[1] === '--offline')
        || (args.length === 3 && args[1] === '--evidence'))) {
    await checkGeneratedReadme(authority);
    if (args[1] === '--offline') {
      process.stdout.write(`README CLI channel command is current; static authority selects ${authority.channel}.\n`);
      return;
    }

    const evidence = validateReleaseEvidence(await currentPublicEvidence(), 'public CLI channel resolution evidence');
    if (args[1] === '--evidence') {
      await writeFile(args[2], `${JSON.stringify({
        schema: 'durable-workflow.cli-channel-resolution/v1',
        repository: evidence.repository,
        channel: evidence.channel,
        version: evidence.version,
        tag: evidence.tag,
        commit: evidence.commit,
        release_url: evidence.releaseUrl,
        release_installer_url: evidence.releaseInstallerUrl,
        required_assets: evidence.assets,
      }, null, 2)}\n`);
    }
    process.stdout.write(`README CLI channel command is current; public channel resolves to complete immutable release ${evidence.version}.\n`);
    return;
  }

  if (args[0] === '--print' && args.length === 2) {
    const fields = {
      assets: requiredAssets.join('\n'),
      channel: authority.channel,
      'installer-command': installerCommand(authority),
      'installer-url': authority.installerUrl,
      repository: authority.repository,
    };
    if (!Object.hasOwn(fields, args[1])) {
      usage();
      process.exitCode = 2;
      return;
    }
    process.stdout.write(`${fields[args[1]]}\n`);
    return;
  }

  if (args[0] === '--print-evidence' && args.length === 3) {
    const evidence = await localReleaseEvidence(args[1]);
    const fields = {
      assets: evidence.assets.join('\n'),
      commit: evidence.commit,
      'release-installer-url': evidence.releaseInstallerUrl,
      'release-url': evidence.releaseUrl,
      version: evidence.version,
    };
    if (!Object.hasOwn(fields, args[2])) {
      usage();
      process.exitCode = 2;
      return;
    }
    process.stdout.write(`${fields[args[2]]}\n`);
    return;
  }

  usage();
  process.exitCode = 2;
}

try {
  await main();
} catch (error) {
  process.stderr.write(`error: ${error.message}\n`);
  process.exitCode = 1;
}
