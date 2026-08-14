#!/usr/bin/env sh

set -eu

fail() {
    title="$1"
    message="$2"

    if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
        {
            printf '## %s\n\n' "$title"
            printf '%s\n' "$message"
        } >> "$GITHUB_STEP_SUMMARY"
    fi

    printf '::error title=%s::%s\n' "$title" "$message" >&2
    printf '%s\n' "$message" >&2
    exit 1
}

artifact="${DOCS_RELEASE_AUDIT_ARTIFACT:-}"
expected="${DOCS_RELEASE_AUDIT_VERSION:-${GITHUB_REF_NAME:-}}"
audit_url="${DOCS_RELEASE_AUDIT_URL:-https://durable-workflow.com/docs-page-release-audit.json}"
attempts="${DOCS_RELEASE_AUDIT_ATTEMPTS:-6}"
sleep_seconds="${DOCS_RELEASE_AUDIT_RETRY_SLEEP:-20}"
evidence_path="${DOCS_RELEASE_AUDIT_EVIDENCE:-}"
handoff_path="${DOCS_RELEASE_AUDIT_HANDOFF:-}"

write_unavailable_evidence() {
    message="$1"

    [ -n "$evidence_path" ] || return 0

    node - "$evidence_path" "$artifact" "$expected" "$audit_url" "$message" <<'NODE'
const fs = require('fs');

const [evidencePath, artifact, expected, auditUrl, message] = process.argv.slice(2);
const serverUrl = process.env.GITHUB_SERVER_URL || 'https://github.com';
const repository = process.env.GITHUB_REPOSITORY || null;
const runId = process.env.GITHUB_RUN_ID || null;

fs.writeFileSync(evidencePath, `${JSON.stringify({
  schema: 'durable-workflow.release.docs-release-audit-evidence',
  checked_at: new Date().toISOString(),
  surface: 'public_docs_release_audit',
  audit_url: auditUrl,
  artifact,
  expected_version: expected,
  outcome: 'unavailable',
  status: 'failure',
  classification: 'unavailable',
  failure_kind: 'unreachable_audit',
  message,
  source_release_check: {
    repository,
    ref: process.env.DOCS_RELEASE_AUDIT_SOURCE_REF || process.env.GITHUB_REF_NAME || null,
    sha: process.env.DOCS_RELEASE_AUDIT_SOURCE_SHA || process.env.GITHUB_SHA || null,
    run_id: runId,
    run_attempt: process.env.GITHUB_RUN_ATTEMPT || null,
    run_url: repository && runId
      ? `${serverUrl}/${repository}/actions/runs/${runId}`
      : null,
  },
}, null, 2)}\n`);
NODE
}

case "$artifact" in
    cli|sdk-php|sdk-python|sdk-rust|server|workflow|waterline) ;;
    *) fail "Docs release-audit artifact required" "DOCS_RELEASE_AUDIT_ARTIFACT must be one of cli, sdk-php, sdk-python, sdk-rust, server, workflow, or waterline." ;;
esac

expected="${expected#v}"
if [ -z "$expected" ]; then
    fail "Docs release-audit version required" "DOCS_RELEASE_AUDIT_VERSION or GITHUB_REF_NAME must name the published artifact version."
fi

case "$attempts" in
    ''|*[!0-9]*) fail "Invalid docs release-audit retry count" "DOCS_RELEASE_AUDIT_ATTEMPTS must be a positive integer." ;;
esac
case "$sleep_seconds" in
    ''|*[!0-9]*) fail "Invalid docs release-audit retry delay" "DOCS_RELEASE_AUDIT_RETRY_SLEEP must be a non-negative integer." ;;
esac
if [ "$attempts" -lt 1 ]; then
    fail "Invalid docs release-audit retry count" "DOCS_RELEASE_AUDIT_ATTEMPTS must be at least 1."
fi

tmp_dir="${RUNNER_TEMP:-${TMPDIR:-/tmp}}"
audit_path="${tmp_dir}/docs-page-release-audit-${artifact}-${expected}-$$.json"
trap 'rm -f "$audit_path"' EXIT HUP INT TERM
attempt=1

while [ "$attempt" -le "$attempts" ]; do
    if curl -fsSL --retry 3 --retry-all-errors --connect-timeout 10 --max-time 30 -o "$audit_path" "$audit_url"; then
        if node - "$audit_path" "$artifact" "$expected" "$audit_url" "$evidence_path" "$handoff_path" <<'NODE'
const fs = require('fs');

const [auditPath, artifact, expected, auditUrl, evidencePath, handoffPath] = process.argv.slice(2);
const auditSchema = 'durable-workflow.docs.page-release-audit';
const minimumAuditSchemaVersion = 4;
const minimumAuditClassifierVersion = 4;
const auditClassifierPattern = /^route-and-public-artifact-inventory-v([1-9]\d*)$/;
const auditGeneratedFrom = 'production sitemap and build artifact inventory';
const legacyArtifactVersionSchema = 'durable-workflow.docs.public-artifact-versions';
const publishedArtifactVersionSchema = 'durable-workflow.docs.published-artifact-versions';
const artifactCompatibilityEvidenceSchema =
  'durable-workflow.docs.public-artifact-compatibility-evidence';
const artifactCompatibilityEvidencePath = '/public-artifact-compatibility-evidence.json';
const publicDocsRepositoryUrl = 'https://github.com/durable-workflow/durable-workflow.github.io';
const expectedArtifacts = ['cli', 'sdk-php', 'sdk-python', 'sdk-rust', 'server', 'waterline', 'workflow'];
const expectedSynchronizedFields = [
  'artifact_versions',
  'artifact_distribution_surfaces.sdk-php',
  'artifact_distribution_surfaces.server',
  'artifact_distribution_surfaces.sdk-rust',
];
const expectedServerSurfaces = [
  {
    surface: 'docker_hub_container_image',
    registry: 'docker_hub',
    image: 'durableworkflow/server',
  },
  {
    surface: 'ghcr_container_image',
    registry: 'ghcr',
    image: 'ghcr.io/durable-workflow/server',
  },
];
const expectedRustSurfaces = [
  {
    surface: 'crates_io_package',
    package: 'durable-workflow',
    url: 'https://crates.io/crates/durable-workflow',
  },
  {
    surface: 'source_repository',
    repository: 'durable-workflow/sdk-rust',
    url: 'https://github.com/durable-workflow/sdk-rust',
  },
  {
    surface: 'api_documentation',
    url: 'https://rust.durable-workflow.com/',
  },
];
const expectedPhpSurfaces = [
  {
    surface: 'packagist_package',
    package: 'durable-workflow/sdk',
    url: 'https://packagist.org/packages/durable-workflow/sdk',
  },
  {
    surface: 'source_repository',
    repository: 'durable-workflow/sdk-php',
    url: 'https://github.com/durable-workflow/sdk-php',
  },
  {
    surface: 'api_documentation',
    url: 'https://php.durable-workflow.com/api/',
  },
];
const stableLlmPaths = [
  '/llms.txt',
  '/llms-full.txt',
  '/llms-1.x.txt',
  '/llms-full-1.x.txt',
];
const prereleaseLlmPaths = [
  '/llms-2.0.txt',
  '/llms-full-2.0.txt',
  '/2.0/llms-full.txt',
];
const generatedStableDocsNullVersionPaths = [
  '/docs/',
  '/docs/platform-conformance/',
];
const docsArtifactTupleHandoffSchema =
  'durable-workflow.release.docs-artifact-tuple-handoff';
const docsArtifactTupleHandoffSchemaVersion = 1;
const docsRefreshRequestSchema = 'durable-workflow.docs.refresh-request';
const docsRefreshRequestSchemaVersion = 1;
const docsRefreshRequestCompatibility = {
  additive_fields: 'ignore',
  compatible_handoff_schema_versions: [docsArtifactTupleHandoffSchemaVersion],
  unsupported_schema_version: 'reject_with_supported_versions',
};
const refreshCommand = 'npm run refresh:public-artifact-versions';
const refreshFiles = [
  'scripts/public-artifact-versions.json',
  'scripts/published-artifact-versions.json',
  'static/public-artifact-compatibility-evidence.json',
  'static/quickstart-execution-contract.json',
  'static/compatibility-contract.json',
  'static/sdk-neutrality-contract.json',
  'scripts/workflow-sdk-neutrality-authority-lock.json',
];
const refreshFileList = refreshFiles.join(', ');
const releaseAuditAssertions = [
  'compatible public route inventory contract',
  'internally consistent artifact tuple',
  'stable default 1.x',
  'explicit prerelease 2.0',
  'public-reference cleanliness',
];
const artifactVersionSourceContracts = {
  [legacyArtifactVersionSchema]: {
    sourcePath: 'scripts/public-artifact-versions.json',
    minimumAuditVersion: 4,
    minimumClassifierVersion: 4,
    role: null,
    separateCompatibilityEvidence: false,
  },
  [publishedArtifactVersionSchema]: {
    sourcePath: 'scripts/published-artifact-versions.json',
    minimumAuditVersion: 5,
    minimumClassifierVersion: 5,
    role: 'current_published_component_artifacts',
    separateCompatibilityEvidence: true,
  },
};
const forbiddenInventoryFields = ['source_sha256', 'content_sha256', 'verdict', 'findings'];
const repoLocalReferencePattern = new RegExp([
  String.raw`^\.{1,2}[\\/]`,
  String.raw`^[A-Za-z]:[\\/]`,
  String.raw`^(?:\.github|blog|build|docs|generated|scripts|src|static)[\\/]`,
  String.raw`^(?:[^:\/]+[\\/])+[^\\/]+\.(?:cjs|js|json|jsx|md|mdx|mjs|ps1|sh|ts|tsx|ya?ml)(?:$|[?#])`,
  String.raw`^[^\\/]+\.(?:cjs|js|json|jsx|md|mdx|mjs|ps1|sh|ts|tsx|ya?ml)(?:$|[?#])`,
].join('|'), 'i');

function isRecord(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function containsRequiredUniqueValues(actual, requiredValues) {
  return Array.isArray(actual) &&
    actual.every(value => typeof value === 'string' && value.trim() !== '') &&
    new Set(actual).size === actual.length &&
    requiredValues.every(value => actual.includes(value));
}

function isPublicRoute(value) {
  return /^\/(?!\/)/.test(value) && !value.includes('\\');
}

function isPublicUrl(value) {
  try {
    const parsed = new URL(value);
    return parsed.protocol === 'https:' && !parsed.username && !parsed.password;
  } catch (err) {
    return false;
  }
}

function findRepoLocalReference(value, valuePath = '$') {
  if (typeof value === 'string') {
    if (!isPublicRoute(value) && !isPublicUrl(value) && repoLocalReferencePattern.test(value)) {
      return {path: valuePath, value};
    }
    return null;
  }

  if (Array.isArray(value)) {
    for (const [index, item] of value.entries()) {
      const found = findRepoLocalReference(item, `${valuePath}[${index}]`);
      if (found) {
        return found;
      }
    }
    return null;
  }

  if (isRecord(value)) {
    for (const [key, item] of Object.entries(value)) {
      const found = findRepoLocalReference(item, `${valuePath}.${key}`);
      if (found) {
        return found;
      }
    }
  }

  return null;
}

function routeKind(routePath) {
  if (routePath.startsWith('/docs/2.0/')) {
    return 'explicit_prerelease_2_0_docs';
  }
  if (routePath.startsWith('/docs/')) {
    return 'stable_default_docs';
  }
  if (stableLlmPaths.includes(routePath)) {
    return 'stable_default_llm';
  }
  if (prereleaseLlmPaths.includes(routePath)) {
    return 'explicit_prerelease_2_0_llm';
  }
  if (routePath === '/') {
    return 'homepage';
  }
  return 'public_artifact';
}

function parseArtifactVersion(version) {
  const match = /^(\d+)\.(\d+)\.(\d+)(?:-(alpha|beta|rc)\.(\d+))?$/.exec(version);
  if (!match) {
    return null;
  }

  const channel = match[4] === 'alpha'
    ? 0
    : match[4] === 'beta'
      ? 1
      : match[4] === 'rc'
        ? 2
        : 3;

  return [
    Number(match[1]),
    Number(match[2]),
    Number(match[3]),
    channel,
    match[5] ? Number(match[5]) : 0,
  ];
}

function compareArtifactVersions(left, right) {
  for (let index = 0; index < left.length; index += 1) {
    if (left[index] !== right[index]) {
      return left[index] < right[index] ? -1 : 1;
    }
  }

  return 0;
}

function releaseCheckSource() {
  const serverUrl = process.env.GITHUB_SERVER_URL || 'https://github.com';
  const repository = process.env.GITHUB_REPOSITORY || null;
  const runId = process.env.GITHUB_RUN_ID || null;
  const runAttempt = process.env.GITHUB_RUN_ATTEMPT || null;

  return {
    repository,
    ref: process.env.DOCS_RELEASE_AUDIT_SOURCE_REF || process.env.GITHUB_REF_NAME || null,
    sha: process.env.DOCS_RELEASE_AUDIT_SOURCE_SHA || process.env.GITHUB_SHA || null,
    run_id: runId,
    run_attempt: runAttempt,
    run_url: repository && runId
      ? `${serverUrl}/${repository}/actions/runs/${runId}`
      : null,
  };
}

function docsRefreshHandoff(message, actualVersion, observedVersions) {
  const staleArtifact = {
    name: artifact,
    expected_version: expected,
    live_version: actualVersion,
  };

  return {
    schema: docsArtifactTupleHandoffSchema,
    schema_version: docsArtifactTupleHandoffSchemaVersion,
    action: 'pipeline_ready_item',
    reason: 'public_docs_release_audit_stale',
    repository: 'durable-workflow.github.io',
    target_branch: 'main',
    integration: 'pipeline',
    refresh_command: refreshCommand,
    refresh_files: refreshFiles,
    stale_artifact: staleArtifact,
    observed_artifact_versions: observedVersions,
    source_release_check: releaseCheckSource(),
    public_boundary: {
      allowed_paths: refreshFiles,
      forbidden_paths: [
        'docusaurus.config.js',
        'sidebars.js',
        'versioned_docs/version-1.x',
        'versioned_sidebars/version-1.x-sidebars.json',
      ],
    },
    release_status_guard: {
      stable_default_docs_line: '1.x',
      prerelease_docs_line: '2.0',
      no_default_docs_cutover: true,
      live_release_audit_assertions: releaseAuditAssertions,
    },
    ready_item: {
      title: `Refresh public docs artifact tuple for ${artifact} ${expected}`,
      body: [
        message,
        '',
        `Expected ${artifact} ${expected}; live docs release audit reports ${actualVersion || '<missing>'}.`,
        `Run ${refreshCommand} and land its generated changes within the bounded public artifact tuple: ` +
          `${refreshFileList}.`,
      ].join('\n'),
      labels: [
        'pipeline:ready-item',
        'branch:main',
        'state:pending',
      ],
      acceptance: [
        'The public docs release-audit JSON reports the current published artifact tuple.',
        'Stable 1.x remains the default public docs line.',
        'The live release audit reports a compatible, clean public route inventory and release-status guardrail.',
        'The refresh lands through the docs merge gate, not from a public release workflow.',
      ],
    },
  };
}

function docsRefreshRequest(handoff) {
  return {
    schema: docsRefreshRequestSchema,
    schema_version: docsRefreshRequestSchemaVersion,
    reason: handoff.reason,
    repository: handoff.repository,
    target_branch: handoff.target_branch,
    integration: handoff.integration,
    refresh_command: handoff.refresh_command,
    refresh_files: handoff.refresh_files,
    stale_artifact: handoff.stale_artifact,
    observed_artifact_versions: handoff.observed_artifact_versions,
    source_release_check: handoff.source_release_check,
    public_boundary: handoff.public_boundary,
    release_status_guard: handoff.release_status_guard,
    ready_item: handoff.ready_item,
    handoff_schema: handoff.schema,
    handoff_schema_version: handoff.schema_version,
    compatibility: docsRefreshRequestCompatibility,
  };
}

function writeHandoff(handoff) {
  if (!handoffPath) {
    return;
  }

  fs.writeFileSync(handoffPath, `${JSON.stringify(handoff, null, 2)}\n`);
}

function writeEvidence(outcome, extra = {}) {
  if (!evidencePath) {
    return;
  }

  fs.writeFileSync(evidencePath, `${JSON.stringify({
    schema: 'durable-workflow.release.docs-release-audit-evidence',
    checked_at: new Date().toISOString(),
    surface: 'public_docs_release_audit',
    audit_url: auditUrl,
    artifact,
    expected_version: expected,
    source_release_check: releaseCheckSource(),
    outcome,
    ...extra,
  }, null, 2)}\n`);
}

function appendSummary(title, message) {
  if (!process.env.GITHUB_STEP_SUMMARY) {
    return;
  }

  fs.appendFileSync(
    process.env.GITHUB_STEP_SUMMARY,
    `## ${title}\n\n${message}\n\n`
  );
}

function fail(title, outcome, failureKind, message, extra = {}) {
  writeEvidence(outcome, {
    status: 'failure',
    failure_kind: failureKind,
    message,
    ...extra,
  });

  appendSummary(title, message);
  console.error(`::error title=${title}::${message}`);
  console.error(message);
  process.exit(2);
}

function malformed(message, extra = {}) {
  fail('Malformed docs release audit', 'malformed', 'malformed_audit', message, {
    classification: 'incompatible',
    ...extra,
  });
}

function publicSafetyFailure(failureKind, message, extra = {}) {
  fail('Docs public-safety audit failed', 'public_safety_failure', failureKind, message, {
    classification: failureKind === 'mixed_artifact_tuple' ? 'mixed' : 'incompatible',
    ...extra,
  });
}

function releaseReadinessFailure(failureKind, message, extra = {}) {
  fail('Docs release readiness conflict', 'release_readiness_failure', failureKind, message, {
    classification: 'conflict',
    ...extra,
  });
}

function aggregateCompatibilityFailure(message, extra = {}) {
  publicSafetyFailure(
    'aggregate_compatibility_evidence',
    message,
    {observed_aggregate_compatibility_evidence: audit.artifact_compatibility_evidence, ...extra}
  );
}

function validateAggregateCompatibilityEvidence(evidence) {
  if (!isRecord(evidence)) {
    aggregateCompatibilityFailure(
      `${auditUrl} must contain artifact_compatibility_evidence for the current component publication contract.`
    );
  }

  if (evidence.role !== 'qualified_aggregate_recommendation') {
    aggregateCompatibilityFailure(
      `${auditUrl} artifact_compatibility_evidence.role must be qualified_aggregate_recommendation; ` +
        `got ${evidence.role || '<missing>'}.`
    );
  }

  if (evidence.source_url !== artifactCompatibilityEvidencePath) {
    aggregateCompatibilityFailure(
      `${auditUrl} artifact_compatibility_evidence.source_url must be ` +
        `${artifactCompatibilityEvidencePath}; got ${evidence.source_url || '<missing>'}.`
    );
  }

  if (evidence.schema !== artifactCompatibilityEvidenceSchema) {
    aggregateCompatibilityFailure(
      `${auditUrl} artifact_compatibility_evidence.schema must be ` +
        `${artifactCompatibilityEvidenceSchema}; got ${evidence.schema || '<missing>'}.`
    );
  }

  if (!Number.isInteger(evidence.schema_version) || evidence.schema_version < 2) {
    aggregateCompatibilityFailure(
      `${auditUrl} artifact_compatibility_evidence.schema_version must be a compatible revision ` +
        '(minimum 2).'
    );
  }

  if (evidence.outcome !== 'pass') {
    aggregateCompatibilityFailure(
      `${auditUrl} artifact_compatibility_evidence.outcome must be pass; ` +
        `got ${evidence.outcome || '<missing>'}.`
    );
  }

  const qualifiedVersions = evidence.qualified_artifact_versions;
  if (!isRecord(qualifiedVersions)) {
    aggregateCompatibilityFailure(
      `${auditUrl} artifact_compatibility_evidence must contain qualified_artifact_versions.`
    );
  }

  const missingQualifiedArtifacts = expectedArtifacts.filter(
    name => !Object.prototype.hasOwnProperty.call(qualifiedVersions, name)
  );
  if (missingQualifiedArtifacts.length > 0) {
    aggregateCompatibilityFailure(
      `${auditUrl} artifact_compatibility_evidence.qualified_artifact_versions is missing ` +
        `${missingQualifiedArtifacts.join(', ')}.`
    );
  }

  for (const name of expectedArtifacts) {
    if (
      typeof qualifiedVersions[name] !== 'string' ||
      qualifiedVersions[name] !== qualifiedVersions[name].trim() ||
      !parseArtifactVersion(qualifiedVersions[name])
    ) {
      aggregateCompatibilityFailure(
        `${auditUrl} artifact_compatibility_evidence.qualified_artifact_versions.${name}=` +
          `${qualifiedVersions[name] ?? '<missing>'} is not a supported public artifact version.`
      );
    }
  }

  const releasePlan = evidence.release_plan;
  if (
    !isRecord(releasePlan) ||
    typeof releasePlan.tag !== 'string' ||
    !/^release-plan\/[a-z0-9][a-z0-9-]{2,79}$/.test(releasePlan.tag) ||
    typeof releasePlan.sha256 !== 'string' ||
    !/^[a-f0-9]{64}$/.test(releasePlan.sha256)
  ) {
    aggregateCompatibilityFailure(
      `${auditUrl} artifact_compatibility_evidence.release_plan must bind a valid release-plan tag ` +
        'and lowercase SHA-256.'
    );
  }

  const qualification = evidence.sdk_server_qualification;
  if (!isRecord(qualification)) {
    aggregateCompatibilityFailure(
      `${auditUrl} artifact_compatibility_evidence must contain sdk_server_qualification.`
    );
  }

  for (const field of ['source_url', 'evidence_source']) {
    if (!isPublicUrl(qualification[field])) {
      aggregateCompatibilityFailure(
        `${auditUrl} artifact_compatibility_evidence.sdk_server_qualification.${field} ` +
          'must be a public HTTPS URL.'
      );
    }
  }

  for (const field of ['sha256', 'evidence_sha256']) {
    if (typeof qualification[field] !== 'string' || !/^[a-f0-9]{64}$/.test(qualification[field])) {
      aggregateCompatibilityFailure(
        `${auditUrl} artifact_compatibility_evidence.sdk_server_qualification.${field} ` +
          'must be a lowercase SHA-256.'
      );
    }
  }

  if (qualification.outcome !== 'pass') {
    aggregateCompatibilityFailure(
      `${auditUrl} artifact_compatibility_evidence.sdk_server_qualification.outcome must be pass; ` +
        `got ${qualification.outcome || '<missing>'}.`
    );
  }

  return {
    outcome: 'pass',
    role: evidence.role,
    source_url: evidence.source_url,
    schema: evidence.schema,
    schema_version: evidence.schema_version,
    qualified_artifact_versions: qualifiedVersions,
    release_plan: releasePlan,
    sdk_server_qualification: qualification,
  };
}

let audit;
try {
  audit = JSON.parse(fs.readFileSync(auditPath, 'utf8'));
} catch (err) {
  malformed(`${auditUrl} did not return parseable JSON: ${err.message}`);
}

if (!isRecord(audit)) {
  malformed(`${auditUrl} must return a JSON object.`);
}

if (audit.schema !== auditSchema) {
  malformed(`${auditUrl} returned schema ${audit.schema || '<missing>'}, not ${auditSchema}.`);
}

if (!Number.isInteger(audit.schema_version) || audit.schema_version < minimumAuditSchemaVersion) {
  malformed(
    `${auditUrl} returned schema_version ${audit.schema_version ?? '<missing>'}, ` +
      `which is not a compatible public contract revision (minimum ${minimumAuditSchemaVersion}).`,
    {
      observed_schema: audit.schema,
      observed_schema_version: audit.schema_version ?? null,
      minimum_schema_version: minimumAuditSchemaVersion,
    }
  );
}

const classifierMatch = typeof audit.classifier === 'string'
  ? auditClassifierPattern.exec(audit.classifier)
  : null;
if (!classifierMatch || Number(classifierMatch[1]) < minimumAuditClassifierVersion) {
  malformed(
    `${auditUrl} returned classifier ${audit.classifier || '<missing>'}, ` +
      `not a compatible route-and-public-artifact-inventory classifier.`
  );
}

if (audit.generated_from !== auditGeneratedFrom) {
  malformed(
    `${auditUrl} generated_from must be ${auditGeneratedFrom}; ` +
      `got ${audit.generated_from || '<missing>'}.`
  );
}

if (
  typeof audit.generated_at !== 'string' ||
  !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/.test(audit.generated_at) ||
  Number.isNaN(Date.parse(audit.generated_at))
) {
  malformed(`${auditUrl} generated_at must be an ISO-8601 UTC timestamp with millisecond precision.`);
}

if (typeof audit.docs_revision !== 'string' || !/^[a-f0-9]{40}$/.test(audit.docs_revision)) {
  malformed(`${auditUrl} docs_revision must be a 40-character lowercase Git SHA.`);
}

const repoLocalReference = findRepoLocalReference(audit);
if (repoLocalReference) {
  malformed(
    `${auditUrl} exposes repo-local reference ${JSON.stringify(repoLocalReference.value)} ` +
      `at ${repoLocalReference.path}; public audit references must be root-relative routes or HTTPS URLs.`,
    {observed_repo_local_reference: repoLocalReference}
  );
}

const versions = audit.artifact_versions;
if (!isRecord(versions)) {
  malformed(`${auditUrl} must contain an artifact_versions object.`);
}

const artifactKeys = Object.keys(versions).sort();
const missingArtifactKeys = expectedArtifacts.filter(name => !Object.prototype.hasOwnProperty.call(versions, name));
if (missingArtifactKeys.length > 0) {
  malformed(
    `${auditUrl} artifact_versions is missing required entries: ${missingArtifactKeys.join(', ')}; ` +
      `got ${artifactKeys.join(', ') || '<none>'}.`,
    {observed_artifact_versions: versions}
  );
}

for (const name of expectedArtifacts) {
  if (typeof versions[name] !== 'string' || versions[name].trim() === '' || versions[name] !== versions[name].trim()) {
    malformed(
      `${auditUrl} artifact_versions.${name} must be a non-empty version without surrounding whitespace.`,
      {observed_artifact_versions: versions}
    );
  }

  if (!parseArtifactVersion(versions[name])) {
    malformed(
      `${auditUrl} artifact_versions.${name}=${versions[name]} is not a supported public artifact version.`,
      {observed_artifact_versions: versions}
    );
  }
}

const versionSource = audit.artifact_version_source;
if (!isRecord(versionSource)) {
  malformed(`${auditUrl} must contain artifact_version_source metadata.`);
}

const artifactVersionSourceContract = Object.prototype.hasOwnProperty.call(
  artifactVersionSourceContracts,
  versionSource.schema
)
  ? artifactVersionSourceContracts[versionSource.schema]
  : null;
if (!artifactVersionSourceContract) {
  malformed(
    `${auditUrl} artifact_version_source.schema must be ${legacyArtifactVersionSchema} or ` +
      `${publishedArtifactVersionSchema}; ` +
      `got ${versionSource.schema || '<missing>'}.`
  );
}

const classifierVersion = Number(classifierMatch[1]);
if (
  audit.schema_version < artifactVersionSourceContract.minimumAuditVersion ||
  classifierVersion < artifactVersionSourceContract.minimumClassifierVersion
) {
  malformed(
    `${auditUrl} artifact_version_source.schema ${versionSource.schema} requires schema_version ` +
      `${artifactVersionSourceContract.minimumAuditVersion} and classifier version ` +
      `${artifactVersionSourceContract.minimumClassifierVersion} or newer.`
  );
}

if (
  artifactVersionSourceContract.role !== null &&
  versionSource.role !== artifactVersionSourceContract.role
) {
  malformed(
    `${auditUrl} artifact_version_source.role must be ${artifactVersionSourceContract.role}; ` +
      `got ${versionSource.role || '<missing>'}.`
  );
}

const artifactVersionSourcePath = artifactVersionSourceContract.sourcePath;
const expectedArtifactVersionSourceUrl =
  `${publicDocsRepositoryUrl}/blob/${audit.docs_revision}/${artifactVersionSourcePath}`;
if (versionSource.source_url !== expectedArtifactVersionSourceUrl) {
  malformed(
    `${auditUrl} artifact_version_source.source_url must resolve ${artifactVersionSourcePath} ` +
      `at docs_revision ${audit.docs_revision}; got ${versionSource.source_url || '<missing>'}.`,
    {expected_source_url: expectedArtifactVersionSourceUrl}
  );
}

if (!containsRequiredUniqueValues(versionSource.synchronized_fields, expectedSynchronizedFields)) {
  malformed(
    `${auditUrl} artifact_version_source.synchronized_fields must contain the unique required fields ` +
      `${expectedSynchronizedFields.join(', ')}.`
  );
}

const aggregateCompatibilityEvidence =
  artifactVersionSourceContract.separateCompatibilityEvidence
    ? validateAggregateCompatibilityEvidence(audit.artifact_compatibility_evidence)
    : null;

const currentServerArtifact = versionSource.current_server_artifact;
if (!isRecord(currentServerArtifact)) {
  malformed(`${auditUrl} must describe artifact_version_source.current_server_artifact.`);
}

const distributionSurfaces = audit.artifact_distribution_surfaces;
if (!isRecord(distributionSurfaces) || !Array.isArray(distributionSurfaces.server)) {
  malformed(`${auditUrl} must describe artifact_distribution_surfaces.server.`);
}

if (!Array.isArray(distributionSurfaces['sdk-php'])) {
  malformed(`${auditUrl} must describe artifact_distribution_surfaces.sdk-php.`);
}

for (const expectedSurface of expectedPhpSurfaces) {
  const matchingSurfaces = distributionSurfaces['sdk-php'].filter(candidate => (
    isRecord(candidate) && candidate.surface === expectedSurface.surface
  ));

  if (matchingSurfaces.length !== 1) {
    publicSafetyFailure(
      'mixed_artifact_tuple',
      `${auditUrl} must contain exactly one ${expectedSurface.surface} PHP SDK surface; ` +
        `found ${matchingSurfaces.length}.`,
      {
        observed_artifact_versions: versions,
        observed_php_surfaces: distributionSurfaces['sdk-php'],
      }
    );
  }
  const [surface] = matchingSurfaces;

  const expectedFields = expectedSurface.surface === 'packagist_package'
    ? {...expectedSurface, version: versions['sdk-php']}
    : expectedSurface;

  for (const [field, expectedValue] of Object.entries(expectedFields)) {
    if (surface[field] !== expectedValue) {
      publicSafetyFailure(
        'mixed_artifact_tuple',
        `${auditUrl} mixes artifact_versions.sdk-php=${versions['sdk-php']} with ` +
          `${expectedSurface.surface}.${field}=${surface[field] ?? '<missing>'}; expected ${expectedValue}.`,
        {
          observed_artifact_versions: versions,
          observed_php_surfaces: distributionSurfaces['sdk-php'],
        }
      );
    }
  }
}

const expectedServerReferences = [];
for (const expectedSurface of expectedServerSurfaces) {
  const matchingSurfaces = distributionSurfaces.server.filter(candidate => (
    isRecord(candidate) && candidate.surface === expectedSurface.surface
  ));

  if (matchingSurfaces.length !== 1) {
    publicSafetyFailure(
      'mixed_artifact_tuple',
      `${auditUrl} must contain exactly one ${expectedSurface.surface} server image surface; ` +
        `found ${matchingSurfaces.length}.`,
      {
        observed_artifact_versions: versions,
        observed_server_surfaces: distributionSurfaces.server,
      }
    );
  }
  const [surface] = matchingSurfaces;

  const expectedReference = `${expectedSurface.image}:${versions.server}`;
  expectedServerReferences.push(expectedReference);

  for (const [field, expectedValue] of Object.entries({
    registry: expectedSurface.registry,
    image: expectedSurface.image,
    tag: versions.server,
    reference: expectedReference,
  })) {
    if (surface[field] !== expectedValue) {
      publicSafetyFailure(
        'mixed_artifact_tuple',
        `${auditUrl} mixes artifact_versions.server=${versions.server} with ` +
          `${expectedSurface.surface}.${field}=${surface[field] ?? '<missing>'}; expected ${expectedValue}.`,
        {
          observed_artifact_versions: versions,
          observed_server_surfaces: distributionSurfaces.server,
        }
      );
    }
  }
}

if (
  currentServerArtifact.version !== versions.server ||
  !containsRequiredUniqueValues(currentServerArtifact.references, expectedServerReferences)
) {
  publicSafetyFailure(
    'mixed_artifact_tuple',
    `${auditUrl} does not synchronize artifact_version_source.current_server_artifact with ` +
      `artifact_versions.server=${versions.server} and both public image references.`,
    {
      observed_artifact_versions: versions,
      observed_current_server_artifact: currentServerArtifact,
      expected_server_references: expectedServerReferences,
    }
  );
}

if (!Array.isArray(distributionSurfaces['sdk-rust'])) {
  malformed(`${auditUrl} must describe artifact_distribution_surfaces.sdk-rust.`);
}

for (const expectedSurface of expectedRustSurfaces) {
  const matchingSurfaces = distributionSurfaces['sdk-rust'].filter(candidate => (
    isRecord(candidate) && candidate.surface === expectedSurface.surface
  ));

  if (matchingSurfaces.length !== 1) {
    publicSafetyFailure(
      'mixed_artifact_tuple',
      `${auditUrl} must contain exactly one ${expectedSurface.surface} Rust SDK surface; ` +
        `found ${matchingSurfaces.length}.`,
      {
        observed_artifact_versions: versions,
        observed_rust_surfaces: distributionSurfaces['sdk-rust'],
      }
    );
  }
  const [surface] = matchingSurfaces;

  const expectedFields = expectedSurface.surface === 'crates_io_package'
    ? {...expectedSurface, version: versions['sdk-rust']}
    : expectedSurface;

  for (const [field, expectedValue] of Object.entries(expectedFields)) {
    if (surface[field] !== expectedValue) {
      publicSafetyFailure(
        'mixed_artifact_tuple',
        `${auditUrl} mixes artifact_versions.sdk-rust=${versions['sdk-rust']} with ` +
          `${expectedSurface.surface}.${field}=${surface[field] ?? '<missing>'}; expected ${expectedValue}.`,
        {
          observed_artifact_versions: versions,
          observed_rust_surfaces: distributionSurfaces['sdk-rust'],
        }
      );
    }
  }
}

const guardrail = audit.release_status_guardrail;
if (!isRecord(guardrail)) {
  publicSafetyFailure(
    'default_version_policy',
    `${auditUrl} is missing the release_status_guardrail required to preserve stable 1.x as the default docs line.`
  );
}

if (
  guardrail.stable_default_docs_version !== '1.x' ||
  guardrail.explicit_prerelease_docs_version !== '2.0'
) {
  publicSafetyFailure(
    'default_version_policy',
    `${auditUrl} must report stable_default_docs_version=1.x and ` +
      `explicit_prerelease_docs_version=2.0; got ` +
      `${guardrail.stable_default_docs_version ?? '<missing>'} and ` +
      `${guardrail.explicit_prerelease_docs_version ?? '<missing>'}.`,
    {observed_release_status_guardrail: guardrail}
  );
}

if (!Array.isArray(audit.page_inventory) || audit.page_inventory.length === 0) {
  malformed(`${auditUrl} must contain a non-empty page_inventory array.`);
}

const inventoryPaths = new Set();
let stableDocsPages = 0;
let prereleaseDocsPages = 0;

for (const [index, entry] of audit.page_inventory.entries()) {
  if (!isRecord(entry) || typeof entry.path !== 'string' || entry.path.trim() === '') {
    malformed(`${auditUrl} page_inventory[${index}] must contain a non-empty path.`);
  }

  if (
    !entry.path.startsWith('/') ||
    entry.path.includes('\\') ||
    entry.path.includes('//') ||
    entry.path.includes('/./') ||
    entry.path.includes('/../') ||
    entry.path.includes('?') ||
    entry.path.includes('#')
  ) {
    malformed(`${auditUrl} page_inventory[${index}].path=${entry.path} is not a normalized public path.`);
  }

  if (inventoryPaths.has(entry.path)) {
    malformed(`${auditUrl} contains duplicate page_inventory path ${entry.path}.`);
  }
  inventoryPaths.add(entry.path);

  const expectedRouteKind = routeKind(entry.path);
  if (entry.route_kind !== expectedRouteKind) {
    malformed(
      `${auditUrl} page_inventory entry ${entry.path} has route_kind ` +
        `${entry.route_kind ?? '<missing>'}; expected ${expectedRouteKind}.`
    );
  }

  if (entry.artifact_route !== entry.path) {
    malformed(
      `${auditUrl} page_inventory entry ${entry.path} has artifact_route ` +
        `${entry.artifact_route ?? '<missing>'}; expected the same public route.`
    );
  }

  const forbiddenFields = forbiddenInventoryFields.filter(field => (
    Object.prototype.hasOwnProperty.call(entry, field)
  ));
  if (forbiddenFields.length > 0) {
    malformed(
      `${auditUrl} page_inventory entry ${entry.path} publishes forbidden self-attested fields: ` +
        `${forbiddenFields.join(', ')}.`
    );
  }

  if (
    entry.docusaurus_version !== null &&
    (typeof entry.docusaurus_version !== 'string' || entry.docusaurus_version.trim() === '')
  ) {
    malformed(
      `${auditUrl} page_inventory entry ${entry.path} must contain a string or null docusaurus_version.`
    );
  }

  if (entry.route_kind === 'stable_default_docs') {
    stableDocsPages += 1;
    const generatedStableRoute = generatedStableDocsNullVersionPaths.includes(entry.path);
    if (entry.docusaurus_version !== '1.x' && !(generatedStableRoute && entry.docusaurus_version === null)) {
      const observedDocusaurusVersion = entry.docusaurus_version === null
        ? 'null'
        : entry.docusaurus_version ?? '<missing>';
      publicSafetyFailure(
        'default_version_policy',
        `${auditUrl} classifies ${entry.path} as stable default docs but reports ` +
          `docusaurus_version=${observedDocusaurusVersion}; expected 1.x` +
          `${generatedStableRoute ? ' or the contract-defined null generated-route value' : ''}.`,
        {observed_page_inventory_entry: entry}
      );
    }
  }

  if (entry.route_kind === 'explicit_prerelease_2_0_docs') {
    prereleaseDocsPages += 1;
    const generatedTagRoute = entry.path.startsWith('/docs/2.0/tags/');
    if (entry.docusaurus_version !== 'current' && !(generatedTagRoute && entry.docusaurus_version === null)) {
      publicSafetyFailure(
        'default_version_policy',
        `${auditUrl} classifies ${entry.path} as explicit prerelease 2.0 docs but reports ` +
          `docusaurus_version=${entry.docusaurus_version ?? '<missing>'}.`,
        {observed_page_inventory_entry: entry}
      );
    }
  }
}

const summary = audit.summary;
if (!isRecord(summary)) {
  malformed(`${auditUrl} must contain a summary object.`);
}

if (
  !Number.isInteger(summary.stable_default_docs_pages) ||
  !Number.isInteger(summary.explicit_prerelease_2_0_pages) ||
  !Number.isInteger(summary.inventoried_routes)
) {
  malformed(
    `${auditUrl} summary must contain integer stable_default_docs_pages, ` +
      `explicit_prerelease_2_0_pages, and inventoried_routes fields.`
  );
}

if (
  summary.stable_default_docs_pages !== stableDocsPages ||
  summary.explicit_prerelease_2_0_pages !== prereleaseDocsPages ||
  summary.inventoried_routes !== audit.page_inventory.length
) {
  malformed(
    `${auditUrl} summary counts do not match page_inventory.`,
    {
      reported_summary: summary,
      observed_summary: {
        stable_default_docs_pages: stableDocsPages,
        explicit_prerelease_2_0_pages: prereleaseDocsPages,
        inventoried_routes: audit.page_inventory.length,
      },
    }
  );
}

if (stableDocsPages < 1 || prereleaseDocsPages < 1 || !inventoryPaths.has('/')) {
  publicSafetyFailure(
    'default_version_policy',
    `${auditUrl} must inventory the homepage, at least one stable default 1.x docs route, ` +
      `and at least one explicit prerelease 2.0 docs route.`,
    {
      homepage_inventoried: inventoryPaths.has('/'),
      stable_default_docs_pages: stableDocsPages,
      explicit_prerelease_2_0_pages: prereleaseDocsPages,
    }
  );
}

const publicSafety = {
  outcome: 'pass',
  route_inventory: {
    schema_version: audit.schema_version,
    classifier: audit.classifier,
    docs_revision: audit.docs_revision,
    stable_default_docs_pages: stableDocsPages,
    explicit_prerelease_2_0_pages: prereleaseDocsPages,
    inventoried_routes: audit.page_inventory.length,
  },
  artifact_tuple_internal_consistency: 'pass',
  validated_artifacts: expectedArtifacts,
  component_publication_state: artifactVersionSourceContract.separateCompatibilityEvidence
    ? {
        outcome: 'pass',
        source_schema: versionSource.schema,
        source_role: versionSource.role,
        artifact_versions: versions,
      }
    : null,
  aggregate_compatibility_evidence: aggregateCompatibilityEvidence,
  stable_default_docs_version: guardrail.stable_default_docs_version,
  explicit_prerelease_docs_version: guardrail.explicit_prerelease_docs_version,
};

const actual = versions[artifact];
if (actual !== expected) {
  const actualVersion = Object.prototype.hasOwnProperty.call(versions, artifact) ? actual : null;
  const actualVersionParts = parseArtifactVersion(actualVersion);
  const expectedVersionParts = parseArtifactVersion(expected);

  if (!actualVersionParts) {
    malformed(
      `${auditUrl} artifact_versions.${artifact}=${actualVersion} is not a supported public artifact version.`,
      {actual_version: actualVersion, observed_artifact_versions: versions}
    );
  }

  if (!expectedVersionParts) {
    releaseReadinessFailure(
      'unsupported_expected_artifact_version',
      `Published ${artifact} version ${expected} cannot be compared with the live docs tuple version ${actualVersion}.`,
      {actual_version: actualVersion, observed_artifact_versions: versions}
    );
  }

  if (compareArtifactVersions(actualVersionParts, expectedVersionParts) >= 0) {
    releaseReadinessFailure(
      'live_docs_version_not_behind_publication',
      `${auditUrl} reports artifact_versions.${artifact}=${actualVersion}, which is newer than the ` +
        `published version ${expected}. Only an otherwise valid live docs version that is behind a newly published ` +
        `artifact is classified as non-blocking tuple lag.`,
      {actual_version: actualVersion, observed_artifact_versions: versions}
    );
  }

  const message = `${auditUrl} reports artifact_versions.${artifact}=${actual || '<missing>'}, expected ${expected}. ` +
    `Run ${refreshCommand} in durable-workflow.github.io and land its generated changes within the bounded ` +
    `public artifact tuple (${refreshFileList}) through the normal docs merge path before treating this release ` +
    'as fully surfaced.';
  const handoff = docsRefreshHandoff(message, actualVersion, versions);

  writeHandoff(handoff);

  const pendingMessage = `${message} The image publication remains successful because the live audit is ` +
    `otherwise valid and internally consistent. When DOCS_RELEASE_AUDIT_HANDOFF is set, the uploaded handoff ` +
    `artifact contains the pipeline-ready docs refresh request.`;

  writeEvidence('downstream_pending', {
    status: 'success',
    classification: 'handoff',
    release_readiness: 'docs_tuple_refresh_required',
    message: pendingMessage,
    public_safety: publicSafety,
    actual_version: actualVersion,
    observed_artifact_versions: versions,
    docs_refresh_request: docsRefreshRequest(handoff),
    docs_artifact_tuple_handoff: handoff,
    docs_artifact_tuple_handoff_path: handoffPath || null,
  });
  appendSummary(
    'Public images published; docs tuple refresh pending',
    `${pendingMessage}\n\nPublic-safety checks: compatible schema version ${audit.schema_version} public route ` +
      `inventory, internally consistent artifact tuple, stable default 1.x, explicit prerelease 2.0, ` +
      `and public-reference cleanliness.`
  );
  console.error(`::warning title=Docs release readiness pending::${pendingMessage}`);
  console.log(pendingMessage);
  process.exit(0);
}

writeEvidence('pass', {
  status: 'success',
  classification: 'ready',
  release_readiness: 'fully_surfaced',
  public_safety: publicSafety,
  actual_version: actual,
  observed_artifact_versions: versions,
});
console.log(`${auditUrl} confirms artifact_versions.${artifact}=${expected}.`);
NODE
        then
            exit 0
        else
            node_status=$?
            if [ "$node_status" -eq 2 ]; then
                exit 1
            fi
        fi
    fi

    if [ "$attempt" -lt "$attempts" ]; then
        printf 'Waiting for docs release-audit JSON (%s/%s): %s\n' "$attempt" "$attempts" "$audit_url" >&2
        sleep "$sleep_seconds"
    fi
    attempt=$((attempt + 1))
done

message="Could not fetch ${audit_url} after ${attempts} attempt(s)."
write_unavailable_evidence "$message"
fail "Docs release-audit unavailable" "$message"
