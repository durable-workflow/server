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
  failure_kind: 'unreachable_audit',
  message,
  source_release_check: {
    repository,
    ref: process.env.GITHUB_REF_NAME || null,
    sha: process.env.GITHUB_SHA || null,
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
    cli|sdk-python|sdk-rust|server|workflow|waterline) ;;
    *) fail "Docs release-audit artifact required" "DOCS_RELEASE_AUDIT_ARTIFACT must be one of cli, sdk-python, sdk-rust, server, workflow, or waterline." ;;
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
const artifactVersionSchema = 'durable-workflow.docs.public-artifact-versions';
const expectedArtifacts = ['cli', 'sdk-python', 'sdk-rust', 'server', 'waterline', 'workflow'];
const expectedSynchronizedFields = [
  'artifact_versions',
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
const verdicts = ['CLEAN', 'LEAK', 'MIXED'];
const refreshCommand = 'npm run refresh:public-artifact-versions';
const refreshFiles = [
  'scripts/public-artifact-versions.json',
  'docs/compatibility.md',
  'static/quickstart-execution-contract.json',
];
const refreshFileList = refreshFiles.join(', ');
const releaseAuditAssertions = [
  'LEAK=0',
  'MIXED=0',
  'stable default 1.x',
  'explicit prerelease 2.0',
];

function isRecord(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function sameValues(actual, expectedValues) {
  return JSON.stringify(actual) === JSON.stringify(expectedValues);
}

function parseArtifactVersion(version) {
  const match = /^(\d+)\.(\d+)\.(\d+)(?:-(alpha|beta)\.(\d+))?$/.exec(version);
  if (!match) {
    return null;
  }

  return [
    Number(match[1]),
    Number(match[2]),
    Number(match[3]),
    match[4] === 'alpha' ? 0 : match[4] === 'beta' ? 1 : 2,
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
    ref: process.env.GITHUB_REF_NAME || null,
    sha: process.env.GITHUB_SHA || null,
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
    schema: 'durable-workflow.release.docs-artifact-tuple-handoff',
    schema_version: 1,
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
        `Run ${refreshCommand} and commit only ${refreshFileList} through the normal docs merge path.`,
      ].join('\n'),
      labels: [
        'pipeline:ready-item',
        'branch:main',
        'state:pending',
      ],
      acceptance: [
        'The public docs release-audit JSON reports the current published artifact tuple.',
        'Stable 1.x remains the default public docs line.',
        'The live release-audit JSON reports LEAK=0 and MIXED=0.',
        'The refresh lands through the docs merge gate, not from a public release workflow.',
      ],
    },
  };
}

function docsRefreshRequest(handoff) {
  return {
    schema: 'durable-workflow.docs.refresh-request',
    reason: handoff.reason,
    repository: handoff.repository,
    target_branch: handoff.target_branch,
    integration: handoff.integration,
    refresh_command: handoff.refresh_command,
    refresh_files: handoff.refresh_files,
    stale_artifact: handoff.stale_artifact,
    observed_artifact_versions: handoff.observed_artifact_versions,
    source_release_check: handoff.source_release_check,
    ready_item: handoff.ready_item,
    handoff_schema: handoff.schema,
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
  fail('Malformed docs release audit', 'malformed', 'malformed_audit', message, extra);
}

function publicSafetyFailure(failureKind, message, extra = {}) {
  fail('Docs public-safety audit failed', 'public_safety_failure', failureKind, message, extra);
}

function releaseReadinessFailure(failureKind, message, extra = {}) {
  fail('Docs release readiness conflict', 'release_readiness_failure', failureKind, message, extra);
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

if (audit.schema_version !== 1) {
  malformed(`${auditUrl} returned schema_version ${audit.schema_version ?? '<missing>'}, not 1.`);
}

if (audit.classifier !== 'content-derived-release-status-v2') {
  malformed(
    `${auditUrl} returned classifier ${audit.classifier || '<missing>'}, ` +
      `not content-derived-release-status-v2.`
  );
}

const versions = audit.artifact_versions;
if (!isRecord(versions)) {
  malformed(`${auditUrl} must contain an artifact_versions object.`);
}

const artifactKeys = Object.keys(versions).sort();
if (!sameValues(artifactKeys, expectedArtifacts)) {
  malformed(
    `${auditUrl} artifact_versions keys must be ${expectedArtifacts.join(', ')}; ` +
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

if (versionSource.schema !== artifactVersionSchema) {
  malformed(
    `${auditUrl} artifact_version_source.schema must be ${artifactVersionSchema}; ` +
      `got ${versionSource.schema || '<missing>'}.`
  );
}

if (versionSource.source_file !== 'scripts/public-artifact-versions.json') {
  malformed(`${auditUrl} artifact_version_source.source_file must identify scripts/public-artifact-versions.json.`);
}

if (!sameValues(versionSource.synchronized_fields, expectedSynchronizedFields)) {
  malformed(
    `${auditUrl} artifact_version_source.synchronized_fields must be ` +
      `${expectedSynchronizedFields.join(', ')}.`
  );
}

const currentServerArtifact = versionSource.current_server_artifact;
if (!isRecord(currentServerArtifact)) {
  malformed(`${auditUrl} must describe artifact_version_source.current_server_artifact.`);
}

const distributionSurfaces = audit.artifact_distribution_surfaces;
if (!isRecord(distributionSurfaces) || !Array.isArray(distributionSurfaces.server)) {
  malformed(`${auditUrl} must describe artifact_distribution_surfaces.server.`);
}

if (distributionSurfaces.server.length !== expectedServerSurfaces.length) {
  publicSafetyFailure(
    'mixed_artifact_tuple',
    `${auditUrl} must describe both synchronized public server image surfaces; ` +
      `found ${distributionSurfaces.server.length}.`,
    {
      observed_artifact_versions: versions,
      observed_server_surfaces: distributionSurfaces.server,
    }
  );
}

const expectedServerReferences = [];
for (const expectedSurface of expectedServerSurfaces) {
  const surface = distributionSurfaces.server.find(candidate => (
    isRecord(candidate) && candidate.surface === expectedSurface.surface
  ));

  if (!surface) {
    publicSafetyFailure(
      'mixed_artifact_tuple',
      `${auditUrl} is missing the ${expectedSurface.surface} server image surface.`,
      {
        observed_artifact_versions: versions,
        observed_server_surfaces: distributionSurfaces.server,
      }
    );
  }

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
  !sameValues(currentServerArtifact.references, expectedServerReferences)
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

if (distributionSurfaces['sdk-rust'].length !== expectedRustSurfaces.length) {
  publicSafetyFailure(
    'mixed_artifact_tuple',
    `${auditUrl} must describe the crates.io, source repository, and API documentation ` +
      `surfaces for the Rust SDK; found ${distributionSurfaces['sdk-rust'].length}.`,
    {
      observed_artifact_versions: versions,
      observed_rust_surfaces: distributionSurfaces['sdk-rust'],
    }
  );
}

for (const expectedSurface of expectedRustSurfaces) {
  const surface = distributionSurfaces['sdk-rust'].find(candidate => (
    isRecord(candidate) && candidate.surface === expectedSurface.surface
  ));

  if (!surface) {
    publicSafetyFailure(
      'mixed_artifact_tuple',
      `${auditUrl} is missing the ${expectedSurface.surface} Rust SDK surface.`,
      {
        observed_artifact_versions: versions,
        observed_rust_surfaces: distributionSurfaces['sdk-rust'],
      }
    );
  }

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

const observedVerdictCounts = Object.fromEntries(verdicts.map(verdict => [verdict, 0]));
const inventoryPaths = new Set();
const nonCleanPages = [];

for (const [index, entry] of audit.page_inventory.entries()) {
  if (!isRecord(entry) || typeof entry.path !== 'string' || entry.path.trim() === '') {
    malformed(`${auditUrl} page_inventory[${index}] must contain a non-empty path.`);
  }

  if (inventoryPaths.has(entry.path)) {
    malformed(`${auditUrl} contains duplicate page_inventory path ${entry.path}.`);
  }
  inventoryPaths.add(entry.path);

  if (!verdicts.includes(entry.verdict)) {
    malformed(
      `${auditUrl} page_inventory entry ${entry.path} has invalid verdict ${entry.verdict ?? '<missing>'}.`
    );
  }

  if (!Array.isArray(entry.findings) || !Number.isInteger(entry.leak_count) || entry.leak_count < 0) {
    malformed(
      `${auditUrl} page_inventory entry ${entry.path} must contain findings and a non-negative leak_count.`
    );
  }

  if (entry.leak_count !== entry.findings.length) {
    malformed(
      `${auditUrl} page_inventory entry ${entry.path} has leak_count=${entry.leak_count}, ` +
        `but ${entry.findings.length} finding(s).`
    );
  }

  if (entry.verdict === 'CLEAN' && entry.findings.length !== 0) {
    malformed(`${auditUrl} page_inventory entry ${entry.path} is CLEAN but has findings.`);
  }

  if (entry.verdict !== 'CLEAN' && entry.findings.length === 0) {
    malformed(`${auditUrl} page_inventory entry ${entry.path} is ${entry.verdict} but has no findings.`);
  }

  observedVerdictCounts[entry.verdict] += 1;
  if (entry.verdict !== 'CLEAN') {
    nonCleanPages.push({
      path: entry.path,
      verdict: entry.verdict,
      findings: entry.findings,
    });
  }
}

const summary = audit.summary;
if (!isRecord(summary) || !isRecord(summary.verdict_counts)) {
  malformed(`${auditUrl} must contain summary.verdict_counts.`);
}

const reportedVerdictKeys = Object.keys(summary.verdict_counts).sort();
if (!sameValues(reportedVerdictKeys, [...verdicts].sort())) {
  malformed(
    `${auditUrl} summary.verdict_counts keys must be ${verdicts.join(', ')}; ` +
      `got ${reportedVerdictKeys.join(', ') || '<none>'}.`
  );
}

for (const verdict of verdicts) {
  if (!Number.isInteger(summary.verdict_counts[verdict]) || summary.verdict_counts[verdict] < 0) {
    malformed(`${auditUrl} summary.verdict_counts.${verdict} must be a non-negative integer.`);
  }

  if (summary.verdict_counts[verdict] !== observedVerdictCounts[verdict]) {
    malformed(
      `${auditUrl} summary.verdict_counts does not match page_inventory.`,
      {
        reported_verdict_counts: summary.verdict_counts,
        observed_verdict_counts: observedVerdictCounts,
      }
    );
  }
}

if (!Array.isArray(summary.missing_classifications)) {
  malformed(`${auditUrl} summary.missing_classifications must be an array.`);
}

if (summary.missing_classifications.length !== 0) {
  publicSafetyFailure(
    'missing_page_classification',
    `${auditUrl} has ${summary.missing_classifications.length} public surface(s) ` +
      `without a release-status classification.`,
    {missing_classifications: summary.missing_classifications}
  );
}

if (
  !Number.isInteger(summary.stable_default_docs_pages) || summary.stable_default_docs_pages < 1 ||
  !Number.isInteger(summary.explicit_prerelease_2_0_pages) || summary.explicit_prerelease_2_0_pages < 1
) {
  publicSafetyFailure(
    'default_version_policy',
    `${auditUrl} must cover at least one stable default 1.x page and one explicit prerelease 2.0 page.`,
    {
      stable_default_docs_pages: summary.stable_default_docs_pages ?? null,
      explicit_prerelease_2_0_pages: summary.explicit_prerelease_2_0_pages ?? null,
    }
  );
}

if (observedVerdictCounts.LEAK !== 0 || observedVerdictCounts.MIXED !== 0) {
  publicSafetyFailure(
    'non_clean_page_verdicts',
    `${auditUrl} reports LEAK=${observedVerdictCounts.LEAK} and MIXED=${observedVerdictCounts.MIXED}; ` +
      `all public surfaces must be CLEAN before this release audit can pass.`,
    {
      verdict_counts: observedVerdictCounts,
      non_clean_pages: nonCleanPages,
    }
  );
}

const publicSafety = {
  outcome: 'pass',
  verdict_counts: observedVerdictCounts,
  missing_classifications: [],
  artifact_tuple_internal_consistency: 'pass',
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
        `published version ${expected}. Only a clean live docs version that is behind a newly published ` +
        `artifact is classified as non-blocking tuple lag.`,
      {actual_version: actualVersion, observed_artifact_versions: versions}
    );
  }

  const message = `${auditUrl} reports artifact_versions.${artifact}=${actual || '<missing>'}, expected ${expected}. ` +
    `Run ${refreshCommand} in durable-workflow.github.io and land ${refreshFileList} through the normal docs merge path before treating this release as fully surfaced.`;
  const handoff = docsRefreshHandoff(message, actualVersion, versions);

  writeHandoff(handoff);

  const pendingMessage = `${message} The image publication remains successful because the live audit is ` +
    `otherwise clean and internally consistent. When DOCS_RELEASE_AUDIT_HANDOFF is set, the uploaded handoff ` +
    `artifact contains the pipeline-ready docs refresh request.`;

  writeEvidence('downstream_pending', {
    status: 'success',
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
    `${pendingMessage}\n\nPublic-safety checks: LEAK=0, MIXED=0, stable default 1.x, explicit prerelease 2.0.`
  );
  console.error(`::warning title=Docs release readiness pending::${pendingMessage}`);
  console.log(pendingMessage);
  process.exit(0);
}

writeEvidence('pass', {
  status: 'success',
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
