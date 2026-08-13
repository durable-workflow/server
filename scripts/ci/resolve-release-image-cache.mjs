#!/usr/bin/env node

import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { appendFileSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const root = resolve(process.env.RELEASE_IMAGE_CACHE_ROOT ?? process.cwd());
const dockerfilePath = process.env.RELEASE_IMAGE_DOCKERFILE ?? 'Dockerfile';
const dependencyPaths = [
  'composer.json',
  'composer.lock',
  'scripts/ci/WorkflowPackageAuthority.php',
  'scripts/ci/prepare-release-workflow-composer-metadata.php',
  'scripts/ci/resolve-workflow-package-authority.php',
];
const optionalDependencyPaths = new Set([
  // Trusted recovery tooling may build an older immutable release source whose
  // Dockerfile predates the Composer-authority resolver.
  'scripts/ci/WorkflowPackageAuthority.php',
  'scripts/ci/resolve-workflow-package-authority.php',
]);
const optionalEmptyBuildArgs = new Set([
  'WORKFLOW_PACKAGE_QUALIFICATION_REF',
]);
const platforms = [...new Set(
  (process.env.RELEASE_IMAGE_PLATFORMS ?? 'linux/amd64,linux/arm64')
    .split(',')
    .map((platform) => platform.trim())
    .filter(Boolean),
)].sort();

if (platforms.length === 0) {
  throw new Error('RELEASE_IMAGE_PLATFORMS must select at least one platform.');
}

const sourceRevision = process.env.RELEASE_SOURCE_COMMIT ?? '';
if (!/^[0-9a-f]{40}$/u.test(sourceRevision)) {
  throw new Error('RELEASE_SOURCE_COMMIT must contain the exact lowercase release commit.');
}

const dockerfile = readRequired(dockerfilePath);
const buildArgs = effectiveBuildArgs(dockerfile);
const baseImages = externalBaseImages(dockerfile);

if (baseImages.length === 0) {
  throw new Error(`${dockerfilePath} does not declare an external base image.`);
}

const cacheInputs = {
  schema: 'durable-workflow.release-image-build-cache.v1',
  source_revision: sourceRevision,
  platforms,
  dockerfile: {
    path: dockerfilePath,
    sha256: sha256(dockerfile),
  },
  base_images: baseImages.map(resolveBaseImage),
  build_args: buildArgs,
  dependency_inputs: Object.fromEntries(
    dependencyPaths.map((path) => [path, dependencyIdentity(path)]),
  ),
};

const hash = sha256(JSON.stringify(cacheInputs));
const identity = `sha256:${hash}`;
const cacheImage = process.env.RELEASE_IMAGE_CACHE_IMAGE
  ?? process.env.GHCR_IMAGE
  ?? 'ghcr.io/durable-workflow/server';
const transportScope = sha256(JSON.stringify({
  schema: 'durable-workflow.release-image-build-cache-transport.v1',
  platforms,
})).slice(0, 24);
const cacheRef = `${cacheImage}:buildcache-v1-${transportScope}`;
const cacheFrom = `type=registry,ref=${cacheRef}`;
const cacheTo = `${cacheFrom},mode=max,ignore-error=true`;

writeOutput('identity', identity);
writeOutput('transport_scope', transportScope);
writeOutput('ref', cacheRef);
writeOutput('platforms', platforms.join(','));
writeOutput('cache_from', cacheFrom);
writeOutput('cache_to', cacheTo);

process.stdout.write(`Resolved shared release image cache ${cacheRef}.\n`);

function readRequired(path) {
  try {
    return readFileSync(resolve(root, path), 'utf8');
  } catch (error) {
    throw new Error(`Required cache identity input ${path} is not readable: ${error.message}`);
  }
}

function dependencyIdentity(path) {
  try {
    return sha256(readFileSync(resolve(root, path), 'utf8'));
  } catch (error) {
    if (optionalDependencyPaths.has(path) && error.code === 'ENOENT') {
      return 'absent';
    }
    throw new Error(`Required cache identity input ${path} is not readable: ${error.message}`);
  }
}

function effectiveBuildArgs(source) {
  const args = new Map();

  for (const line of source.split(/\r?\n/u)) {
    const match = line.match(/^\s*ARG\s+([A-Za-z_][A-Za-z0-9_]*)(?:=(.*))?\s*$/u);
    if (!match) {
      continue;
    }

    const [, name, defaultValue = ''] = match;
    const value = Object.hasOwn(process.env, name) ? process.env[name] : defaultValue;
    if ((value === undefined || value === '') && !optionalEmptyBuildArgs.has(name)) {
      throw new Error(`Docker build argument ${name} must have an effective value.`);
    }
    args.set(name, value ?? '');
  }

  for (const name of ['WORKFLOW_PACKAGE_SOURCE', 'WORKFLOW_PACKAGE_REF', 'WORKFLOW_PACKAGE_COMMIT']) {
    if (!Object.hasOwn(process.env, name) || process.env[name] === '') {
      throw new Error(`${name} must contain the selected Workflow package identity.`);
    }
  }

  return Object.fromEntries([...args.entries()].sort(([left], [right]) => left.localeCompare(right)));
}

function externalBaseImages(source) {
  const external = [];
  const stages = new Set();

  for (const line of source.split(/\r?\n/u)) {
    const match = line.match(
      /^\s*FROM\s+(?:--platform=\S+\s+)?(\S+)(?:\s+AS\s+([A-Za-z0-9_.-]+))?\s*$/iu,
    );
    if (!match) {
      continue;
    }

    const [, image, stage] = match;
    if (image.includes('$')) {
      throw new Error(`Variable base image ${image} cannot be resolved for the cache identity.`);
    }
    if (!stages.has(image) && !external.includes(image)) {
      external.push(image);
    }
    if (stage) {
      stages.add(stage);
    }
  }

  return external.sort();
}

function resolveBaseImage(image) {
  const docker = process.env.DOCKER ?? 'docker';
  let raw;

  try {
    raw = execFileSync(
      docker,
      ['buildx', 'imagetools', 'inspect', image, '--format', '{{json .Manifest}}'],
      { encoding: 'utf8', env: process.env, stdio: ['ignore', 'pipe', 'pipe'] },
    );
  } catch (error) {
    const detail = error.stderr?.toString().trim();
    throw new Error(`Could not resolve base image ${image}${detail ? `: ${detail}` : '.'}`);
  }

  let manifest;
  try {
    manifest = JSON.parse(raw);
  } catch (error) {
    throw new Error(`Base image ${image} returned invalid manifest JSON: ${error.message}`);
  }

  requireDigest(manifest.digest, `${image} manifest`);
  const available = Array.isArray(manifest.manifests) ? manifest.manifests : [];
  const platformDigests = {};

  for (const platform of platforms) {
    const [os, architecture, variant] = platform.split('/');
    const match = available.find((entry) => {
      const candidate = entry.platform ?? {};
      return candidate.os === os
        && candidate.architecture === architecture
        && (variant === undefined || candidate.variant === variant);
    });

    if (!match) {
      throw new Error(`Base image ${image} does not publish the required ${platform} platform.`);
    }
    requireDigest(match.digest, `${image} ${platform}`);
    platformDigests[platform] = match.digest;
  }

  return {
    image,
    manifest_digest: manifest.digest,
    platform_digests: platformDigests,
  };
}

function requireDigest(value, label) {
  if (typeof value !== 'string' || !/^sha256:[0-9a-f]{64}$/u.test(value)) {
    throw new Error(`${label} did not resolve to a lowercase sha256 digest.`);
  }
}

function sha256(value) {
  return createHash('sha256').update(value).digest('hex');
}

function writeOutput(name, value) {
  if (process.env.GITHUB_OUTPUT) {
    appendFileSync(process.env.GITHUB_OUTPUT, `${name}=${value}\n`);
  }
}
