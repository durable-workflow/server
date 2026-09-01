#!/usr/bin/env node

import {createHash} from 'node:crypto';
import {readFile, writeFile} from 'node:fs/promises';
import {resolve} from 'node:path';
import {fileURLToPath} from 'node:url';

const scriptPath = fileURLToPath(import.meta.url);
const defaultRepositoryRoot = resolve(fileURLToPath(new URL('../..', import.meta.url)));
const schema = 'durable-workflow.server.source-release/v1';
const prerelease = String.raw`(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)-(?:alpha|beta|rc)\.(?:0|[1-9]\d*)`;
const stableVersion = String.raw`(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)`;
const serverVersion = String.raw`(?:${stableVersion}|${prerelease})`;
const remediation = 'node scripts/ci/sync-source-release.mjs --write';

function fail(message) {
  throw new Error(message);
}

function object(value, label) {
  if (value === null || typeof value !== 'object' || Array.isArray(value)) {
    fail(`${label} must be a JSON object`);
  }
  return value;
}

function exact(value, pattern, label) {
  if (typeof value !== 'string' || !new RegExp(`^${pattern}$`).test(value)) {
    fail(`${label} must be an exact SemVer value`);
  }
  return value;
}

export async function sourceRelease(repositoryRoot = defaultRepositoryRoot) {
  const path = resolve(repositoryRoot, 'resources/release/source-release.json');
  let record;
  try {
    record = JSON.parse(await readFile(path, 'utf8'));
  } catch (error) {
    fail(`resources/release/source-release.json is not valid JSON: ${error.message}`);
  }
  object(record, 'source release');
  if (record.schema !== schema) {
    fail(`source release schema must be ${schema}`);
  }

  return {
    serverVersion: exact(
      object(record.server, 'source release server').version,
      serverVersion,
      'source release server.version',
    ),
    chartVersion: exact(
      object(record.helm_chart, 'source release helm_chart').version,
      stableVersion,
      'source release helm_chart.version',
    ),
  };
}

export async function legacyServerVersion(repositoryRoot = defaultRepositoryRoot) {
  const path = resolve(repositoryRoot, 'composer.json');
  let composer;
  try {
    composer = JSON.parse(await readFile(path, 'utf8'));
  } catch (error) {
    fail(`legacy source composer.json is not valid JSON: ${error.message}`);
  }

  return exact(
    object(
      object(composer.extra, 'legacy source composer extra')['durable-workflow'],
      'legacy source composer durable-workflow extra',
    )['product-train'],
    serverVersion,
    'legacy source composer product-train',
  );
}

function replaceExactly(source, pattern, replacement, expectedCount, path, label) {
  let count = 0;
  const rendered = source.replace(pattern, (...args) => {
    count += 1;
    return typeof replacement === 'function' ? replacement(...args) : replacement;
  });
  if (count !== expectedCount) {
    fail(`${path}: expected ${expectedCount} ${label}, found ${count}`);
  }
  return rendered;
}

function phpJsonEncode(value) {
  return JSON.stringify(value)
    .replaceAll('/', '\\/')
    .replace(/[\u0080-\uffff]/g, (character) => (
      `\\u${character.charCodeAt(0).toString(16).padStart(4, '0')}`
    ));
}

function composerContentHash(composerSource) {
  let composer;
  try {
    composer = JSON.parse(composerSource);
  } catch (error) {
    fail(`composer.json is not valid JSON: ${error.message}`);
  }
  const relevantKeys = [
    'name',
    'version',
    'require',
    'require-dev',
    'conflict',
    'replace',
    'provide',
    'minimum-stability',
    'prefer-stable',
    'repositories',
    'extra',
  ];
  const relevant = {};
  for (const key of relevantKeys) {
    if (Object.hasOwn(composer, key)) {
      relevant[key] = composer[key];
    }
  }
  if (composer.config?.platform !== undefined) {
    relevant.config = {platform: composer.config.platform};
  }
  const sorted = Object.fromEntries(
    Object.entries(relevant).sort(([left], [right]) => (
      left < right ? -1 : left > right ? 1 : 0
    )),
  );
  return createHash('md5').update(phpJsonEncode(sorted)).digest('hex');
}

const consumers = [
  {
    path: 'composer.json',
    render(source, release) {
      return replaceExactly(
        source,
        new RegExp(`("durable-workflow"\\s*:\\s*\\{\\s*"product-train"\\s*:\\s*")${serverVersion}(")`),
        (_match, prefix, suffix) => `${prefix}${release.serverVersion}${suffix}`,
        1,
        this.path,
        'durable-workflow product-train value',
      );
    },
  },
  {
    path: 'composer.lock',
    render(source, _release, rendered) {
      const contentHash = composerContentHash(rendered.get('composer.json'));
      return replaceExactly(
        source,
        /("content-hash"\s*:\s*")[0-9a-f]{32}(")/,
        (_match, prefix, suffix) => `${prefix}${contentHash}${suffix}`,
        1,
        this.path,
        'Composer content hash',
      );
    },
  },
  ...[
    ['docker-compose.yml', 4],
    ['docker-compose.small-cluster.yml', 1],
    ['docker-compose.memo-rolling.yml', 2],
  ].map(([path, count]) => ({
    path,
    render(source, release) {
      return replaceExactly(
        source,
        new RegExp(`(APP_VERSION:\\s*["']?\\$\\{APP_VERSION:-)${serverVersion}(\\}["']?)`, 'g'),
        (_match, prefix, suffix) => `${prefix}${release.serverVersion}${suffix}`,
        count,
        path,
        'generated APP_VERSION default',
      );
    },
  })),
  ...[
    ['docker-compose.published.yml', 2],
    ['docker-compose.dedicated-matching.yml', 2],
  ].map(([path, count]) => ({
    path,
    render(source, release) {
      return replaceExactly(
        source,
        new RegExp(`(DW_SERVER_TAG:-)${serverVersion}`, 'g'),
        (_match, prefix) => `${prefix}${release.serverVersion}`,
        count,
        path,
        'published Server image default',
      );
    },
  })),
  ...[
    'k8s/migration-job.yaml',
    'k8s/scheduler-cronjob.yaml',
    'k8s/server-deployment.yaml',
    'k8s/worker-deployment.yaml',
  ].map((path) => ({
    path,
    render(source, release) {
      return replaceExactly(
        source,
        new RegExp(`(durableworkflow/server:)${serverVersion}`, 'g'),
        (_match, prefix) => `${prefix}${release.serverVersion}`,
        1,
        path,
        'Kubernetes Server image',
      );
    },
  })),
  {
    path: 'k8s/secret.yaml',
    render(source, release) {
      return replaceExactly(
        source,
        new RegExp(`(APP_VERSION:\\s*["'])${serverVersion}(["'])`),
        (_match, prefix, suffix) => `${prefix}${release.serverVersion}${suffix}`,
        1,
        this.path,
        'Kubernetes application version',
      );
    },
  },
  {
    path: 'k8s/README.md',
    render(source, release) {
      let rendered = replaceExactly(
        source,
        new RegExp(`(durableworkflow/server:)${serverVersion}`, 'g'),
        (_match, prefix) => `${prefix}${release.serverVersion}`,
        4,
        this.path,
        'documented Docker Hub Server image',
      );
      return replaceExactly(
        rendered,
        new RegExp(`(ghcr\\.io/durable-workflow/server:)${serverVersion}`),
        (_match, prefix) => `${prefix}${release.serverVersion}`,
        1,
        this.path,
        'documented GHCR Server image',
      );
    },
  },
  {
    path: 'scripts/k8s-kind-smoke.sh',
    render(source, release) {
      return replaceExactly(
        source,
        new RegExp(`(manifest_image="durableworkflow/server:)${serverVersion}(")`),
        (_match, prefix, suffix) => `${prefix}${release.serverVersion}${suffix}`,
        1,
        this.path,
        'kind smoke manifest image',
      );
    },
  },
  {
    path: 'k8s/helm/durable-workflow/Chart.yaml',
    render(source, release) {
      let rendered = replaceExactly(
        source,
        new RegExp(`^(version:\\s*)${stableVersion}\\s*$`, 'm'),
        (_match, prefix) => `${prefix}${release.chartVersion}`,
        1,
        this.path,
        'top-level chart version',
      );
      rendered = replaceExactly(
        rendered,
        new RegExp(`^(appVersion:\\s*["'])${serverVersion}(["']\\s*)$`, 'm'),
        (_match, prefix, suffix) => `${prefix}${release.serverVersion}${suffix}`,
        1,
        this.path,
        'top-level appVersion',
      );
      return replaceExactly(
        rendered,
        new RegExp(`^(  dev\\.durable-workflow\\.image-reference:\\s*["']docker\\.io/durableworkflow/server:)${serverVersion}(["']\\s*)$`, 'm'),
        (_match, prefix, suffix) => `${prefix}${release.serverVersion}${suffix}`,
        1,
        this.path,
        'release image annotation',
      );
    },
  },
  ...[
    'k8s/helm/durable-workflow/values.yaml',
    'k8s/helm/durable-workflow/README.md',
    'k8s/helm/examples/values-dev.yaml',
    'k8s/helm/examples/values-external-secrets-operator.yaml',
    'k8s/helm/examples/values-production-existing-secrets.yaml',
    'k8s/helm/durable-workflow/ci/existing-secrets-values.yaml',
    'k8s/helm/durable-workflow/ci/inline-secrets-values.yaml',
    'k8s/helm/durable-workflow/ci/ingress-and-hpa-values.yaml',
  ].map((path) => ({
    path,
    render(source, release) {
      return replaceExactly(
        source,
        new RegExp(`^(  tag:\\s*["'])${serverVersion}(["']\\s*)$`, 'm'),
        (_match, prefix, suffix) => `${prefix}${release.serverVersion}${suffix}`,
        1,
        path,
        'generated image tag',
      );
    },
  })),
  {
    path: 'k8s/helm/durable-workflow/templates/_helpers.tpl',
    render(source, release) {
      return replaceExactly(
        source,
        new RegExp(`(docker\\.io/durableworkflow/server:)${serverVersion}`),
        (_match, prefix) => `${prefix}${release.serverVersion}`,
        1,
        this.path,
        'recognized official Server image',
      );
    },
  },
];

export const generatedConsumerPaths = Object.freeze(consumers.map(({path}) => path));

export async function renderSourceRelease(repositoryRoot, release) {
  const rendered = new Map();
  const errors = [];
  for (const consumer of consumers) {
    try {
      const source = rendered.has(consumer.path)
        ? rendered.get(consumer.path)
        : await readFile(resolve(repositoryRoot, consumer.path), 'utf8');
      rendered.set(consumer.path, consumer.render(source, release, rendered));
    } catch (error) {
      errors.push(error.message);
    }
  }
  if (errors.length > 0) {
    fail(
      `source release consumer contract errors:\n - ${errors.join('\n - ')}\nRemediation: ${remediation}`,
    );
  }
  return rendered;
}

async function synchronize(repositoryRoot, write) {
  const release = await sourceRelease(repositoryRoot);
  const rendered = await renderSourceRelease(repositoryRoot, release);
  const stale = [];
  for (const [path, expected] of rendered) {
    const current = await readFile(resolve(repositoryRoot, path), 'utf8');
    if (current !== expected) {
      stale.push(path);
    }
  }

  if (!write && stale.length > 0) {
    fail(
      `source release metadata drift:\n - ${stale.join('\n - ')}\nRemediation: ${remediation}`,
    );
  }
  if (write) {
    for (const path of stale) {
      await writeFile(resolve(repositoryRoot, path), rendered.get(path));
    }
    process.stdout.write(
      `Synchronized ${stale.length} source release consumer(s) for Server ${release.serverVersion} and Helm chart ${release.chartVersion}.\n`,
    );
    return;
  }
  process.stdout.write(
    `Server ${release.serverVersion} and Helm chart ${release.chartVersion} source release consumers are synchronized.\n`,
  );
}

function usage() {
  process.stderr.write(
    'Usage: node scripts/ci/sync-source-release.mjs (--check | --write | --print <server-version|chart-version> | --print-legacy-server-version) [--root <repository>]\n',
  );
}

async function main() {
  const args = process.argv.slice(2);
  let repositoryRoot = defaultRepositoryRoot;
  const rootOffset = args.indexOf('--root');
  if (rootOffset !== -1) {
    if (!args[rootOffset + 1]) {
      usage();
      process.exitCode = 2;
      return;
    }
    repositoryRoot = resolve(args[rootOffset + 1]);
    args.splice(rootOffset, 2);
  }

  if (args.length === 1 && args[0] === '--check') {
    await synchronize(repositoryRoot, false);
    return;
  }
  if (args.length === 1 && args[0] === '--write') {
    await synchronize(repositoryRoot, true);
    return;
  }
  if (args.length === 2 && args[0] === '--print') {
    const release = await sourceRelease(repositoryRoot);
    const fields = {
      'server-version': release.serverVersion,
      'chart-version': release.chartVersion,
    };
    if (!Object.hasOwn(fields, args[1])) {
      usage();
      process.exitCode = 2;
      return;
    }
    process.stdout.write(`${fields[args[1]]}\n`);
    return;
  }
  if (args.length === 1 && args[0] === '--print-legacy-server-version') {
    process.stdout.write(`${await legacyServerVersion(repositoryRoot)}\n`);
    return;
  }
  usage();
  process.exitCode = 2;
}

if (resolve(process.argv[1] ?? '') === resolve(scriptPath)) {
  main().catch((error) => {
    process.stderr.write(`error: ${error.message}\n`);
    process.exitCode = 1;
  });
}
