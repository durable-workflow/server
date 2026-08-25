#!/usr/bin/env node

import {readFile, writeFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';

const repositoryRoot = fileURLToPath(new URL('../..', import.meta.url));
const recordPath = `${repositoryRoot}/resources/release/qualified-onboarding-release.json`;
const publicAuthorityUrl = 'https://durable-workflow.com/public-artifact-compatibility-evidence.json';
const publicAuthoritySchema = 'durable-workflow.docs.public-artifact-compatibility-evidence';
const localRecordSchema = 'durable-workflow.server.qualified-onboarding-release/v1';
const prereleasePattern = String.raw`\d+\.\d+\.\d+-(?:alpha|beta|rc)\.\d+`;

const generatedSurfaces = [
  {
    path: 'docker-compose.published.yml',
    replacements: [
      {
        pattern: new RegExp(`DW_SERVER_TAG:-${prereleasePattern}`, 'g'),
        replacement: ({version}) => `DW_SERVER_TAG:-${version}`,
        count: 2,
      },
    ],
  },
  {
    path: 'docker-compose.dedicated-matching.yml',
    replacements: [
      {
        pattern: new RegExp(`DW_SERVER_TAG:-${prereleasePattern}`, 'g'),
        replacement: ({version}) => `DW_SERVER_TAG:-${version}`,
        count: 2,
      },
    ],
  },
  ...[
    'k8s/migration-job.yaml',
    'k8s/scheduler-cronjob.yaml',
    'k8s/server-deployment.yaml',
    'k8s/worker-deployment.yaml',
  ].map((path) => ({
    path,
    replacements: [
      {
        pattern: new RegExp(`durableworkflow/server:${prereleasePattern}`, 'g'),
        replacement: ({version}) => `durableworkflow/server:${version}`,
        count: 1,
      },
    ],
  })),
  {
    path: 'k8s/secret.yaml',
    replacements: [
      {
        pattern: new RegExp(`APP_VERSION: "${prereleasePattern}"`, 'g'),
        replacement: ({version}) => `APP_VERSION: "${version}"`,
        count: 1,
      },
    ],
  },
  {
    path: 'k8s/README.md',
    replacements: [
      {
        pattern: new RegExp(`durableworkflow/server:${prereleasePattern}`, 'g'),
        replacement: ({version}) => `durableworkflow/server:${version}`,
        count: 4,
      },
      {
        pattern: new RegExp(`ghcr\\.io/durable-workflow/server:${prereleasePattern}`, 'g'),
        replacement: ({version}) => `ghcr.io/durable-workflow/server:${version}`,
        count: 1,
      },
    ],
  },
  {
    path: 'scripts/k8s-kind-smoke.sh',
    replacements: [
      {
        pattern: new RegExp(`manifest_image="durableworkflow/server:${prereleasePattern}"`, 'g'),
        replacement: ({version}) => `manifest_image="durableworkflow/server:${version}"`,
        count: 1,
      },
    ],
  },
  ...[
    'k8s/helm/durable-workflow/values.yaml',
    'k8s/helm/durable-workflow/README.md',
    'k8s/helm/examples/values-dev.yaml',
    'k8s/helm/examples/values-external-secrets-operator.yaml',
    'k8s/helm/examples/values-production-existing-secrets.yaml',
  ].map((path) => ({
    path,
    replacements: [
      {
        pattern: new RegExp(`tag: "${prereleasePattern}"`, 'g'),
        replacement: ({version}) => `tag: "${version}"`,
        count: 1,
      },
    ],
  })),
];

const sourceIdentitySurfaces = [
  {
    path: 'k8s/helm/durable-workflow/Chart.yaml',
    expected: ({version}) => [
      `appVersion: "${version}"`,
      `dev.durable-workflow.image-reference: "docker.io/durableworkflow/server:${version}"`,
    ],
  },
  ...[
    'k8s/helm/durable-workflow/ci/existing-secrets-values.yaml',
    'k8s/helm/durable-workflow/ci/inline-secrets-values.yaml',
    'k8s/helm/durable-workflow/ci/ingress-and-hpa-values.yaml',
  ].map((path) => ({
    path,
    expected: ({version}) => [`tag: "${version}"`],
  })),
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

function assertObject(value, label) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    fail(`${label} must be a JSON object`);
  }
  return value;
}

function exactPrerelease(version, label) {
  if (typeof version !== 'string' || !new RegExp(`^${prereleasePattern}$`).test(version)) {
    fail(`${label} must be an exact prerelease SemVer`);
  }
  return version;
}

function sha256(value, label) {
  if (typeof value !== 'string' || !/^[0-9a-f]{64}$/.test(value)) {
    fail(`${label} must be a lowercase SHA-256 digest`);
  }
  return value;
}

function sourceCommit(value, label) {
  if (typeof value !== 'string' || !/^[0-9a-f]{40}$/.test(value)) {
    fail(`${label} must be a full lowercase Git commit`);
  }
  return value;
}

function normalizedArtifactVersions(value, label) {
  const versions = assertObject(value, label);
  const required = ['cli', 'sdk-php', 'sdk-python', 'sdk-rust', 'server', 'waterline', 'workflow'];
  if (Object.keys(versions).sort().join('\n') !== [...required].sort().join('\n')) {
    fail(`${label} must contain exactly ${required.join(', ')}`);
  }
  return Object.fromEntries(required.map((artifact) => [artifact, exactPrerelease(versions[artifact], `${label}.${artifact}`)]));
}

function normalizedAuthorityReference(value, label) {
  const authority = assertObject(value, label);
  if (typeof authority.schema !== 'string' || authority.schema === '') {
    fail(`${label}.schema must be non-empty`);
  }
  if (typeof authority.tag !== 'string' || authority.tag === '') {
    fail(`${label}.tag must be non-empty`);
  }
  if (typeof authority.source_url !== 'string' || !authority.source_url.startsWith('https://')) {
    fail(`${label}.source_url must be an HTTPS URL`);
  }
  return {
    schema: authority.schema,
    tag: authority.tag,
    source_url: authority.source_url,
    sha256: sha256(authority.sha256, `${label}.sha256`),
  };
}

function normalizePublicAuthority(value) {
  const authority = assertObject(value, 'public compatibility authority');
  if (authority.schema !== publicAuthoritySchema || authority.schema_version !== 2 || authority.outcome !== 'pass') {
    fail('public compatibility authority must be passing schema version 2 evidence');
  }

  const versions = normalizedArtifactVersions(
    authority.qualified_artifact_versions,
    'public compatibility authority qualified_artifact_versions',
  );
  const sdkCompatibility = assertObject(authority.sdk_server_compatibility, 'public SDK/Server compatibility');
  const serverClaims = ['sdk-php', 'sdk-python', 'sdk-rust'].map((sdk) => {
    const claim = assertObject(sdkCompatibility[sdk], `public SDK/Server compatibility ${sdk}`);
    const distribution = assertObject(claim.server_distribution, `public SDK/Server compatibility ${sdk} server_distribution`);
    const artifacts = distribution.artifacts;
    if (claim.outcome !== 'pass' || claim.server_version !== versions.server || claim.supported_server_versions !== versions.server) {
      fail(`public SDK/Server compatibility ${sdk} must pass against the qualified Server version`);
    }
    if (distribution.kind !== 'oci' || distribution.locator !== `oci:docker.io/durableworkflow/server@${versions.server}`) {
      fail(`public SDK/Server compatibility ${sdk} must identify the qualified Docker Hub image`);
    }
    if (!Array.isArray(artifacts) || artifacts.length !== 1 || artifacts[0]?.name !== 'manifest') {
      fail(`public SDK/Server compatibility ${sdk} must bind one OCI manifest`);
    }
    return {
      sourceCommit: sourceCommit(claim.server_source_commit, `public SDK/Server compatibility ${sdk} server_source_commit`),
      manifestSha256: sha256(artifacts[0].sha256, `public SDK/Server compatibility ${sdk} manifest sha256`),
    };
  });
  if (serverClaims.some((claim) => claim.sourceCommit !== serverClaims[0].sourceCommit
      || claim.manifestSha256 !== serverClaims[0].manifestSha256)) {
    fail('public SDK/Server compatibility claims must bind one reproducible Server artifact');
  }

  const publicAuthorities = assertObject(authority.authority, 'public compatibility authority authority');
  const qualification = assertObject(
    publicAuthorities.sdk_server_qualification,
    'public compatibility authority SDK/Server qualification',
  );
  const evidence = assertObject(qualification.evidence, 'public compatibility authority conformance evidence');
  if (evidence.outcome !== 'pass') {
    fail('public compatibility authority conformance evidence must pass');
  }

  return {
    schema: localRecordSchema,
    role: 'qualified_reproducibility_tuple',
    source: {
      url: publicAuthorityUrl,
      schema: publicAuthoritySchema,
      schema_version: 2,
    },
    qualified_artifact_versions: versions,
    server: {
      version: versions.server,
      source_commit: serverClaims[0].sourceCommit,
      image: {
        repository: 'durableworkflow/server',
        reference: `durableworkflow/server:${versions.server}`,
        manifest_sha256: serverClaims[0].manifestSha256,
      },
    },
    qualification: {
      release_plan: normalizedAuthorityReference(publicAuthorities.release_plan, 'public release plan'),
      conformance_evidence: {
        ...normalizedAuthorityReference(evidence, 'public conformance evidence'),
        outcome: 'pass',
      },
    },
  };
}

function validateLocalRecord(value) {
  const record = assertObject(value, 'checked-in qualified onboarding release');
  if (record.schema !== localRecordSchema) {
    fail(`checked-in qualified onboarding release uses unsupported schema ${String(record.schema)}`);
  }
  if (record.role !== 'qualified_reproducibility_tuple') {
    fail('checked-in qualified onboarding release must identify itself as a qualified reproducibility tuple');
  }
  if (record.source?.url !== publicAuthorityUrl
      || record.source?.schema !== publicAuthoritySchema
      || record.source?.schema_version !== 2) {
    fail('checked-in qualified onboarding release must identify the public compatibility authority');
  }
  const versions = normalizedArtifactVersions(
    record.qualified_artifact_versions,
    'checked-in qualified onboarding release qualified_artifact_versions',
  );
  const server = assertObject(record.server, 'checked-in qualified onboarding release server');
  const image = assertObject(server.image, 'checked-in qualified onboarding release server image');
  if (server.version !== versions.server || image.repository !== 'durableworkflow/server'
      || image.reference !== `durableworkflow/server:${versions.server}`) {
    fail('checked-in qualified onboarding release Server identity must match its qualified tuple');
  }
  sourceCommit(server.source_commit, 'checked-in qualified onboarding release server source_commit');
  sha256(image.manifest_sha256, 'checked-in qualified onboarding release server manifest_sha256');
  normalizedAuthorityReference(record.qualification?.release_plan, 'checked-in qualified onboarding release release plan');
  const evidence = normalizedAuthorityReference(
    record.qualification?.conformance_evidence,
    'checked-in qualified onboarding release conformance evidence',
  );
  if (record.qualification.conformance_evidence.outcome !== 'pass') {
    fail('checked-in qualified onboarding release conformance evidence must pass');
  }
  return {record, version: versions.server, evidence};
}

async function readJson(path, label) {
  return parseJson(await readFile(path), label);
}

async function fetchPublicAuthority() {
  const response = await fetch(publicAuthorityUrl, {
    headers: {accept: 'application/json', 'user-agent': 'durable-workflow-server-onboarding-release-sync'},
    redirect: 'follow',
  });
  if (!response.ok) {
    fail(`public compatibility authority returned HTTP ${response.status}`);
  }
  return parseJson(Buffer.from(await response.arrayBuffer()), 'public compatibility authority');
}

async function expectedPublicRecord(sourcePath) {
  const source = sourcePath
    ? await readJson(sourcePath, `compatibility authority ${sourcePath}`)
    : await fetchPublicAuthority();
  return normalizePublicAuthority(source);
}

function replaceAndCount(source, pattern, replacement) {
  let count = 0;
  const updated = source.replace(pattern, () => {
    count += 1;
    return replacement;
  });
  return {count, updated};
}

async function synchronizeSurfaces(authority, write) {
  let changed = 0;
  for (const surface of generatedSurfaces) {
    const path = `${repositoryRoot}/${surface.path}`;
    let source = await readFile(path, 'utf8');
    let expected = source;
    for (const rule of surface.replacements) {
      const result = replaceAndCount(expected, rule.pattern, rule.replacement(authority));
      if (result.count !== rule.count) {
        fail(`${surface.path} must contain ${rule.count} generated onboarding value(s); found ${result.count}`);
      }
      expected = result.updated;
    }
    if (source !== expected) {
      changed += 1;
      if (write) {
        await writeFile(path, expected);
      } else {
        fail(`${surface.path} is stale; run node scripts/ci/sync-qualified-onboarding-release.mjs --write`);
      }
    }
  }

  const rootReadme = await readFile(`${repositoryRoot}/README.md`, 'utf8');
  if (new RegExp(`\\b${prereleasePattern}\\b`, 'i').test(rootReadme)) {
    fail('README.md must resolve prerelease artifacts through their supported channels or qualification authority');
  }
  return changed;
}

async function sourceIdentity() {
  const release = await readJson(
    `${repositoryRoot}/resources/release/source-release.json`,
    'source release manifest',
  );
  if (release?.schema !== 'durable-workflow.server.source-release/v1') {
    fail('source release manifest uses an unsupported schema');
  }
  return {
    version: exactPrerelease(
      release?.server?.version,
      'source release manifest server.version',
    ),
  };
}

async function assertSourceIdentitySurfaces(identity) {
  for (const surface of sourceIdentitySurfaces) {
    const source = await readFile(`${repositoryRoot}/${surface.path}`, 'utf8');
    for (const expected of surface.expected(identity)) {
      const count = source.split(expected).length - 1;
      if (count !== 1) {
        fail(`${surface.path} must retain exactly one source identity ${expected}; found ${count}`);
      }
    }
  }
}

function recordsMatch(left, right) {
  return JSON.stringify(left) === JSON.stringify(right);
}

function usage() {
  process.stderr.write('Usage: node scripts/ci/sync-qualified-onboarding-release.mjs (--check [--offline] | --write [--source <file>] | --print <version|image|manifest-sha256|source-commit>)\n');
}

async function main() {
  const args = process.argv.slice(2);
  if (args[0] === '--write' && (args.length === 1 || (args.length === 3 && args[1] === '--source'))) {
    const record = await expectedPublicRecord(args[2]);
    await writeFile(recordPath, `${JSON.stringify(record, null, 2)}\n`);
    const changed = await synchronizeSurfaces({version: record.server.version}, true);
    await assertSourceIdentitySurfaces(await sourceIdentity());
    process.stdout.write(`Generated ${changed} onboarding surface(s) from qualified Server ${record.server.version}.\n`);
    return;
  }

  const local = await readJson(recordPath, 'checked-in qualified onboarding release');
  const {record, version} = validateLocalRecord(local);
  if (args[0] === '--check' && (args.length === 1 || (args.length === 2 && args[1] === '--offline'))) {
    await synchronizeSurfaces({version}, false);
    await assertSourceIdentitySurfaces(await sourceIdentity());
    if (args[1] !== '--offline') {
      const published = await expectedPublicRecord();
      if (!recordsMatch(record, published)) {
        fail(`checked-in qualified onboarding release ${version} is stale against the public compatibility authority`);
      }
    }
    process.stdout.write(`Compose, Kubernetes, and Helm onboarding select qualified Server ${version}.\n`);
    return;
  }

  if (args[0] === '--print' && args.length === 2) {
    const fields = {
      image: record.server.image.reference,
      'manifest-sha256': record.server.image.manifest_sha256,
      'source-commit': record.server.source_commit,
      version,
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
