#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';

const RESULT_SCHEMA = 'durable-workflow.v2.migration-runtime.result';
const RECORD_SCHEMA = 'durable-workflow.v2.migration-runtime.record';
const ARTIFACT_SCHEMA = 'durable-workflow.v2.migration-runtime.published-artifacts';

const repoRoot = process.env.DW_MIGRATION_REPO_ROOT
  ?? path.resolve(path.dirname(new URL(import.meta.url).pathname), '../..');
const resultDir = process.env.DW_MIGRATION_RESULT_DIR
  ?? process.env.DW_MIGRATION_RUN_ROOT
  ?? process.cwd();
const scenarioManifestPath = process.env.DW_MIGRATION_SCENARIO_MANIFEST
  ?? path.join(repoRoot, 'static/platform-conformance/migration-runtime-scenarios.json');
const evidencePath = process.env.DW_MIGRATION_EVIDENCE_JSON
  ?? path.join(resultDir, 'migration-evidence.json');
const storageSmokePath = process.env.DW_MIGRATION_STORAGE_SMOKE_JSON
  ?? path.join(resultDir, 'storage-connection-smoke.json');

const FALLBACK_REQUIRED_ARTIFACTS = [
  'server-v1',
  'server-v2',
  'cli',
  'workflow-php-v1',
  'workflow-php-v2',
  'sdk-python',
  'waterline',
];
const FALLBACK_REQUIRED_SCENARIOS = [
  'published_artifact_install_only',
  'latest_supported_v1_state_setup',
  'documented_migration_steps_execute',
  'completed_history_preservation_and_replay',
  'in_flight_workflow_progress_preserved',
  'mid_activity_retry_preserved',
  'schedule_cross_upgrade_cadence_preserved',
  'worker_registration_projection_preserved',
  'waterline_operator_visibility_preserved',
  'cli_access_to_preupgrade_state',
  'new_v2_workflow_start_after_upgrade',
  'rollback_contract_verified',
  'version_skew_refusal',
];
const REQUIRED_TOP_LEVEL_FIELDS = [
  'published_artifact_versions',
  'resolved_artifact_versions',
  'artifact_sources',
  'migration_plan',
  'preupgrade_state_snapshot',
  'postupgrade_state_snapshot',
  'history_dumps',
  'activity_attempts',
  'schedule_ticks',
  'worker_registration_observations',
  'cli_observations',
  'waterline_observations',
  'rollback_observations',
  'version_skew_observations',
  'storage_connection_smoke',
];
const FALLBACK_PLACEHOLDER_VERSION_EXAMPLES = [
  'latest',
  'current',
  'head',
  'unresolved',
  'placeholder',
  '<latest>',
  '1.x',
  '2.0.0-alpha.<latest>',
  '${VERSION}',
  '{{ version }}',
];
const FORBIDDEN_SOURCE_TOKENS = [
  'not_exercised',
  'local_product_source_checkout',
  'workspace_repo_as_artifact_under_test',
  'local_checkout_artifact',
  'local_checkout',
  'local_source_checkout',
  'workspace_repo',
  'release_tag_without_required_assets',
  'rolling_server_image_tag',
  'unverified_artifact_source',
];
const ARTIFACT_OWNERS = {
  'server-v1': 'server',
  'server-v2': 'server',
  cli: 'cli',
  'workflow-php-v1': 'workflow',
  'workflow-php-v2': 'workflow',
  'sdk-python': 'sdk-python',
  waterline: 'waterline',
};

const scenarioManifest = readJsonIfExists(scenarioManifestPath) ?? {};
const requiredArtifacts = arrayOfStrings(scenarioManifest?.artifact_policy?.required_artifacts);
const requiredScenarios = Array.isArray(scenarioManifest.scenarios)
  ? scenarioManifest.scenarios.map((scenario) => stringValue(scenario?.id)).filter(Boolean)
  : [];
const scenarioRequirements = objectValue(scenarioManifest.scenario_requirements);
const releaseArtifactAliases = objectOfStringLists(scenarioManifest?.artifact_policy?.release_artifact_aliases);
const placeholderVersionExamples = uniqueStrings([
  ...FALLBACK_PLACEHOLDER_VERSION_EXAMPLES,
  ...arrayOfStrings(scenarioManifest?.artifact_policy?.placeholder_version_examples),
]);

main().catch((error) => {
  const reason = error instanceof Error ? error.message : String(error);
  const startedAt = stringValue(process.env.DW_MIGRATION_STARTED_AT) || timestamp();
  const finishedAt = timestamp();
  const artifactVersions = artifactVersionsFromEnv();
  const artifactSources = artifactSourcesFromEnv();
  writeArtifacts(artifactVersions, artifactVersions, artifactSources, null);
  writeResult(blockedResult(reason, startedAt, finishedAt, artifactVersions, artifactSources));
});

async function main() {
  fs.mkdirSync(resultDir, { recursive: true });

  const evidence = readJsonIfExists(evidencePath) ?? {};
  const blockedReason = stringValue(process.env.DW_MIGRATION_BLOCKED_REASON)
    || stringValue(evidence.blocked_reason)
    || stringValue(evidence.runner_blocked_reason);
  const runnerBlocked = truthy(evidence.runner_blocked) || truthy(evidence.runnerBlocked);
  const startedAt = stringValue(evidence.started_at)
    || stringValue(evidence.startedAt)
    || stringValue(process.env.DW_MIGRATION_STARTED_AT)
    || timestamp();
  const finishedAt = stringValue(evidence.finished_at)
    || stringValue(evidence.finishedAt)
    || timestamp();

  const publishedArtifactVersions = normalizeArtifactAliases(mergeMaps(
    artifactVersionsFromEnv(),
    objectValue(evidence.artifact_versions),
    objectValue(evidence.artifactVersions),
    objectValue(evidence.published_artifact_versions),
    objectValue(evidence.publishedArtifactVersions),
  ));
  const resolvedArtifactVersions = normalizeArtifactAliases(mergeMaps(
    publishedArtifactVersions,
    objectValue(evidence.resolved_artifact_versions),
    objectValue(evidence.resolvedArtifactVersions),
  ));
  const artifactSources = normalizeArtifactAliases(mergeMaps(
    artifactSourcesFromEnv(),
    objectValue(evidence.artifact_sources),
    objectValue(evidence.artifactSources),
    objectValue(evidence.install_sources),
    objectValue(evidence.installSources),
  ), true);
  const storageSmoke = normalizeStorageSmoke(evidence);

  writeArtifacts(publishedArtifactVersions, resolvedArtifactVersions, artifactSources, evidence);

  if (blockedReason !== '' || runnerBlocked) {
    writeResult(blockedResult(
      blockedReason || 'Migration conformance runner reported runner_blocked=true.',
      startedAt,
      finishedAt,
      resolvedArtifactVersions,
      artifactSources,
    ));
    return;
  }

  const artifactPrerequisiteFailures = artifactPrerequisiteFailuresFor(
    publishedArtifactVersions,
    resolvedArtifactVersions,
    artifactSources,
  );
  const scenarioResults = buildScenarioResults(evidence, resolvedArtifactVersions, artifactPrerequisiteFailures);
  const localProductSourceCheckoutsUsed = localProductSourceCheckoutsUsedIn(evidence, scenarioResults);
  const result = {
    schema: RESULT_SCHEMA,
    version: 1,
    suite_version: scenarioManifest.suite_version ?? null,
    started_at: startedAt,
    finished_at: finishedAt,
    generated_at: finishedAt,
    outcome: 'non_passing',
    runner_blocked: false,
    artifact_versions: resolvedArtifactVersions,
    published_artifact_versions: publishedArtifactVersions,
    resolved_artifact_versions: resolvedArtifactVersions,
    artifact_sources: artifactSources,
    artifact_prerequisite_failures: artifactPrerequisiteFailures,
    local_product_source_checkouts_used: localProductSourceCheckoutsUsed,
    scenario_results: scenarioResults,
    findings: [],
    finding_links: {},
    migration_plan: nonEmptyObject(evidence.migration_plan)
      ?? notCoveredObservation('migration_plan', 'No public migration-guide execution plan was supplied.'),
    preupgrade_state_snapshot: nonEmptyObject(evidence.preupgrade_state_snapshot)
      ?? notCoveredObservation('preupgrade_state_snapshot', 'No realistic v1 state snapshot was supplied.'),
    postupgrade_state_snapshot: nonEmptyObject(evidence.postupgrade_state_snapshot)
      ?? notCoveredObservation('postupgrade_state_snapshot', 'No migrated v2 state snapshot was supplied.'),
    history_dumps: nonEmptyObject(evidence.history_dumps)
      ?? notCoveredObservation('history_dumps', 'No before/after history dumps were supplied.'),
    activity_attempts: nonEmptyObject(evidence.activity_attempts)
      ?? notCoveredObservation('activity_attempts', 'No activity retry observations were supplied.'),
    schedule_ticks: nonEmptyObject(evidence.schedule_ticks)
      ?? notCoveredObservation('schedule_ticks', 'No cross-upgrade schedule tick observations were supplied.'),
    worker_registration_observations: nonEmptyObject(evidence.worker_registration_observations)
      ?? notCoveredObservation('worker_registration_observations', 'No worker registration projection observations were supplied.'),
    cli_observations: nonEmptyObject(evidence.cli_observations)
      ?? notCoveredObservation('cli_observations', 'No CLI observations against migrated state were supplied.'),
    waterline_observations: nonEmptyObject(evidence.waterline_observations)
      ?? notCoveredObservation('waterline_observations', 'No Waterline/operator observations were supplied.'),
    rollback_observations: nonEmptyObject(evidence.rollback_observations)
      ?? notCoveredObservation('rollback_observations', 'No rollback observations were supplied.'),
    version_skew_observations: nonEmptyObject(evidence.version_skew_observations)
      ?? notCoveredObservation('version_skew_observations', 'No version-skew refusal observations were supplied.'),
    storage_connection_smoke: storageSmoke,
    implementation_identity: {
      runner: 'scripts/conformance/migration-published-artifacts.sh',
      evidence_input: fs.existsSync(evidencePath) ? evidencePath : null,
      storage_smoke_input: fs.existsSync(storageSmokePath) ? storageSmokePath : null,
      local_product_source_artifacts: false,
    },
  };

  const missingRunRecordFindings = missingRunRecordFindingsFor(result, resolvedArtifactVersions);
  result.finding_links = mergeFindingLinks(evidence, scenarioResults, missingRunRecordFindings);
  result.findings = mergeFindings(evidence, result.finding_links, missingRunRecordFindings);
  result.outcome = resultPasses(result) ? 'pass' : 'non_passing';
  writeResult(result);
}

function buildScenarioResults(evidence, artifactVersions, artifactPrerequisiteFailures = []) {
  const supplied = scenarioResultsById(evidence);
  const results = {};

  for (const scenarioId of effectiveRequiredScenarios()) {
    const suppliedScenario = supplied[scenarioId];
    if (suppliedScenario) {
      results[scenarioId] = scenarioResultWithArtifactPrerequisiteFailures(
        scenarioId,
        normalizeScenarioResult(scenarioId, suppliedScenario, artifactVersions),
        artifactVersions,
        artifactPrerequisiteFailures,
      );
      continue;
    }

    if (artifactPrerequisiteFailures.length > 0) {
      results[scenarioId] = scenarioResultWithArtifactPrerequisiteFailures(scenarioId, {
        scenario_id: scenarioId,
        status: 'fail',
        observed_outputs: {
          required_fields: requiredFieldsFor(scenarioId),
          local_product_source_checkouts_used: false,
        },
      }, artifactVersions, artifactPrerequisiteFailures);
      continue;
    }

    const finding = coverageGapFinding(scenarioId, artifactVersions, {
      observed_behavior: `No published-artifact migration evidence was supplied for ${scenarioId}.`,
      expected_behavior: 'The host migration runner executes this required v1-to-v2 migration cell against pinned published artifacts.',
      next_acceptance_criterion: `run the ${scenarioId} migration cell and attach observed outputs for every required field`,
    });
    results[scenarioId] = {
      scenario_id: scenarioId,
      status: 'not_covered',
      observed_outputs: {
        coverage_gap: true,
        required_fields: requiredFieldsFor(scenarioId),
        local_product_source_checkouts_used: false,
      },
      linked_findings: [finding],
    };
  }

  for (const [scenarioId, suppliedScenario] of Object.entries(supplied)) {
    if (!Object.hasOwn(results, scenarioId)) {
      results[scenarioId] = scenarioResultWithArtifactPrerequisiteFailures(
        scenarioId,
        normalizeScenarioResult(scenarioId, suppliedScenario, artifactVersions),
        artifactVersions,
        artifactPrerequisiteFailures,
      );
    }
  }

  return results;
}

function scenarioResultWithArtifactPrerequisiteFailures(
  scenarioId,
  scenario,
  artifactVersions,
  artifactPrerequisiteFailures,
) {
  if (artifactPrerequisiteFailures.length === 0) {
    return scenario;
  }

  const findings = artifactPrerequisiteFindings(scenarioId, artifactVersions, artifactPrerequisiteFailures);
  const existingFindings = linkedFindingsForScenario(scenario);

  return {
    ...scenario,
    scenario_id: scenarioId,
    status: 'fail',
    observed_outputs: {
      ...objectValue(scenario.observed_outputs),
      artifact_prerequisite_failed: true,
      artifact_prerequisite_failures: artifactPrerequisiteFailures,
    },
    linked_findings: [
      ...existingFindings,
      ...findings,
    ],
  };
}

function artifactPrerequisiteFailuresFor(publishedArtifactVersions, resolvedArtifactVersions, artifactSources) {
  const failures = [];

  for (const artifact of effectiveRequiredArtifacts()) {
    const publishedVersion = stringValue(artifactMapEntry(objectValue(publishedArtifactVersions), artifact));
    const resolvedVersion = stringValue(artifactMapEntry(objectValue(resolvedArtifactVersions), artifact));
    const source = stringValue(artifactMapEntry(objectValue(artifactSources), artifact));

    if (publishedVersion === '') {
      failures.push({
        artifact,
        field: 'published_artifact_versions',
        code: 'missing_published_artifact_version',
      });
    } else if (isPlaceholderArtifactVersion(publishedVersion)) {
      failures.push({
        artifact,
        field: 'published_artifact_versions',
        code: 'placeholder_published_artifact_version',
        value: publishedVersion,
      });
    }

    if (resolvedVersion === '') {
      failures.push({
        artifact,
        field: 'resolved_artifact_versions',
        code: 'missing_resolved_artifact_version',
      });
    } else if (isPlaceholderArtifactVersion(resolvedVersion)) {
      failures.push({
        artifact,
        field: 'resolved_artifact_versions',
        code: 'placeholder_resolved_artifact_version',
        value: resolvedVersion,
      });
    }

    if (source === '') {
      failures.push({
        artifact,
        field: 'artifact_sources',
        code: 'missing_published_artifact_source',
      });
    } else if (containsForbiddenSourceToken(source)) {
      failures.push({
        artifact,
        field: 'artifact_sources',
        code: 'forbidden_published_artifact_source',
        value: source,
      });
    }
  }

  return failures;
}

function artifactPrerequisiteFindings(scenarioId, artifactVersions, artifactPrerequisiteFailures) {
  return artifactPrerequisiteFailures.map((failure) => {
    const artifact = stringValue(failure.artifact);
    const owningSurface = ARTIFACT_OWNERS[artifact] ?? 'conformance_harness';
    const field = stringValue(failure.field);
    const code = stringValue(failure.code);
    const value = stringValue(failure.value);
    const valueDetail = value === '' ? '' : `; observed ${field}=${value}`;

    return {
      scenario_id: scenarioId,
      owning_surface: owningSurface,
      finding_type: 'missing_or_invalid_published_migration_artifact',
      artifact,
      artifact_versions: artifactVersions,
      observed_behavior: `Required migration artifact ${artifact} has ${code} in ${field}${valueDetail}.`,
      expected_behavior: 'Migration conformance starts from exact published v1 and v2 artifacts with a recorded install source for every required channel.',
      next_acceptance_criterion: `publish or record a concrete ${artifact} artifact version and install source, then rerun the ${scenarioId} migration cell`,
    };
  });
}

function normalizeScenarioResult(scenarioId, scenario, artifactVersions) {
  const observedOutputs = nonEmptyObject(scenario.observed_outputs)
    ?? nonEmptyObject(scenario.observedOutputs)
    ?? nonEmptyObject(scenario.evidence)
    ?? {};
  const status = normalizedStatus(scenario.status);
  const missingRequiredFields = status === 'pass'
    ? missingRequiredFieldsForScenario(scenarioId, scenario, observedOutputs)
    : [];
  const normalized = {
    ...scenario,
    scenario_id: scenarioId,
    status: missingRequiredFields.length === 0 ? status : 'not_covered',
    observed_outputs: observedOutputs,
  };
  delete normalized.observedOutputs;

  if (status === 'pass' && missingRequiredFields.length > 0) {
    normalized.observed_outputs = {
      ...observedOutputs,
      missing_required_fields: missingRequiredFields,
    };
    normalized.linked_findings = [
      ...linkedFindingsForScenario(normalized),
      coverageGapFinding(scenarioId, artifactVersions, {
        observed_behavior: `Scenario ${scenarioId} reported pass but omitted required evidence fields: ${missingRequiredFields.join(', ')}.`,
        expected_behavior: 'Passing migration scenarios include non-placeholder observed outputs for every field required by the public migration scenario manifest.',
        next_acceptance_criterion: `attach non-placeholder observations for ${missingRequiredFields.join(', ')} before recording ${scenarioId} as passing`,
      }),
    ];
    return normalized;
  }

  if (status !== 'pass' && !hasLinkedFinding(normalized)) {
    normalized.linked_findings = [
      coverageGapFinding(scenarioId, artifactVersions, {
        observed_behavior: `Scenario ${scenarioId} reported ${status} without a linked root-cause finding.`,
        expected_behavior: 'Every non-pass migration scenario links to the focused product or conformance finding that explains the result.',
        next_acceptance_criterion: 'attach the root-cause finding link to the scenario result before recording the run',
      }),
    ];
  }

  return normalized;
}

function missingRequiredFieldsForScenario(scenarioId, scenario, observedOutputs) {
  const missing = [];

  for (const field of requiredFieldsFor(scenarioId)) {
    if (!hasField(scenario, field) && !hasField(observedOutputs, field)) {
      missing.push(field);
    }
  }

  return missing;
}

function normalizeStorageSmoke(evidence) {
  const supplied = nonEmptyObject(evidence.storage_connection_smoke)
    ?? nonEmptyObject(evidence.storageConnectionSmoke)
    ?? readJsonIfExists(storageSmokePath);

  return supplied ?? {
    status: 'not_covered',
    advisory_only: true,
    required_context_not_passing_by_itself: true,
    observed_behavior: 'No storage-connection smoke result was supplied to this migration run.',
  };
}

function blockedResult(reason, startedAt, finishedAt, artifactVersions, artifactSources) {
  const scenarioResults = {};
  const findingLinks = {};
  const findings = [];

  for (const scenarioId of effectiveRequiredScenarios()) {
    const finding = {
      scenario_id: scenarioId,
      owning_surface: 'conformance_harness',
      finding_type: 'runner_gap',
      artifact_versions: artifactVersions,
      observed_behavior: reason,
      expected_behavior: 'migration conformance runner can compose evidence for every required v1-to-v2 migration cell',
      next_acceptance_criterion: 'restore the missing host capability and rerun migration conformance',
    };
    findings.push(finding);
    findingLinks[scenarioId] = [finding];
    scenarioResults[scenarioId] = {
      scenario_id: scenarioId,
      status: 'runner_blocked',
      observed_outputs: {
        blocked_reason: reason,
        required_fields: requiredFieldsFor(scenarioId),
      },
      linked_findings: [finding],
    };
  }

  return {
    schema: RESULT_SCHEMA,
    version: 1,
    suite_version: scenarioManifest.suite_version ?? null,
    started_at: startedAt,
    finished_at: finishedAt,
    generated_at: finishedAt,
    outcome: 'non_passing_runner_blocked',
    runner_blocked: true,
    artifact_versions: artifactVersions,
    published_artifact_versions: artifactVersions,
    resolved_artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
    local_product_source_checkouts_used: false,
    scenario_results: scenarioResults,
    findings,
    finding_links: findingLinks,
    migration_plan: notCoveredObservation('migration_plan', reason),
    preupgrade_state_snapshot: notCoveredObservation('preupgrade_state_snapshot', reason),
    postupgrade_state_snapshot: notCoveredObservation('postupgrade_state_snapshot', reason),
    history_dumps: notCoveredObservation('history_dumps', reason),
    activity_attempts: notCoveredObservation('activity_attempts', reason),
    schedule_ticks: notCoveredObservation('schedule_ticks', reason),
    worker_registration_observations: notCoveredObservation('worker_registration_observations', reason),
    cli_observations: notCoveredObservation('cli_observations', reason),
    waterline_observations: notCoveredObservation('waterline_observations', reason),
    rollback_observations: notCoveredObservation('rollback_observations', reason),
    version_skew_observations: notCoveredObservation('version_skew_observations', reason),
    storage_connection_smoke: notCoveredObservation('storage_connection_smoke', reason),
  };
}

function resultPasses(result) {
  if (
    result.runner_blocked !== false
    || result.local_product_source_checkouts_used !== false
    || localProductSourceCheckoutsUsedIn(result, objectValue(result.scenario_results))
  ) {
    return false;
  }

  const scenarios = objectValue(result.scenario_results);
  for (const scenarioId of effectiveRequiredScenarios()) {
    if (scenarios[scenarioId]?.status !== 'pass') {
      return false;
    }

    const observedOutputs = objectValue(scenarios[scenarioId].observed_outputs);
    if (Object.keys(observedOutputs).length === 0) {
      return false;
    }

    for (const field of requiredFieldsFor(scenarioId)) {
      if (!hasField(scenarios[scenarioId], field) && !hasField(observedOutputs, field)) {
        return false;
      }
    }
  }

  for (const field of REQUIRED_TOP_LEVEL_FIELDS) {
    if (isEmptyEvidence(result[field])) {
      return false;
    }
  }

  return artifactMapComplete(result.published_artifact_versions, false)
    && artifactMapComplete(result.resolved_artifact_versions, false)
    && artifactMapComplete(result.artifact_sources, true);
}

function artifactMapComplete(map, sourceMap) {
  const value = objectValue(map);
  for (const artifact of effectiveRequiredArtifacts()) {
    const entry = stringValue(artifactMapEntry(value, artifact));
    if (entry === '') {
      return false;
    }

    if (sourceMap && containsForbiddenSourceToken(entry)) {
      return false;
    }

    if (!sourceMap && isPlaceholderArtifactVersion(entry)) {
      return false;
    }
  }

  return true;
}

function normalizeArtifactAliases(map, sourceMap = false) {
  const normalized = { ...objectValue(map) };

  for (const artifact of effectiveRequiredArtifacts()) {
    const current = stringValue(normalized[artifact]);
    if (current !== '' && !(sourceMap && containsForbiddenSourceToken(current))) {
      continue;
    }

    const aliasEntry = artifactAliasesFor(artifact)
      .map((alias) => normalized[alias])
      .find((entry) => {
        const text = stringValue(entry);
        return text !== '' && !(sourceMap && containsForbiddenSourceToken(text));
      });

    if (aliasEntry !== undefined) {
      normalized[artifact] = aliasEntry;
    }
  }

  return normalized;
}

function artifactMapEntry(map, artifact) {
  const direct = stringValue(map?.[artifact]);
  if (direct !== '') {
    return map[artifact];
  }

  return artifactAliasesFor(artifact)
    .map((alias) => map?.[alias])
    .find((entry) => stringValue(entry) !== '');
}

function artifactAliasesFor(artifact) {
  return arrayOfStrings(releaseArtifactAliases[artifact]);
}

function containsForbiddenSourceToken(source) {
  const lower = source.toLowerCase();
  return FORBIDDEN_SOURCE_TOKENS.some((token) => lower.includes(token.toLowerCase()));
}

function isPlaceholderArtifactVersion(version) {
  const normalized = version.toLowerCase();

  return placeholderVersionExamples.some((token) => normalized.includes(token.toLowerCase()))
    || /(^|[^a-z0-9])v?\d+(?:\.\d+)*\.x([^a-z0-9]|$)/i.test(normalized);
}

function missingRunRecordFindingsFor(result, artifactVersions) {
  const findings = [];

  for (const field of REQUIRED_TOP_LEVEL_FIELDS) {
    if (!isEmptyEvidence(fieldValue(result, field))) {
      continue;
    }

    findings.push(coverageGapFinding('run_record', artifactVersions, {
      observed_behavior: `No non-placeholder migration run record evidence was supplied for ${field}.`,
      expected_behavior: 'Passing migration conformance records every top-level migration plan, before/after state, operator observation, rollback/skew observation, and storage smoke section required by the public scenario manifest.',
      next_acceptance_criterion: `attach non-placeholder ${field} evidence before recording migration conformance as passing`,
      missing_run_record_field: field,
    }));
  }

  return findings;
}

function mergeFindingLinks(evidence, scenarioResults, runRecordFindings = []) {
  const merged = {};
  const suppliedLinks = objectValue(evidence.finding_links ?? evidence.findingLinks ?? evidence.linked_findings ?? evidence.linkedFindings);

  for (const scenarioId of Object.keys(scenarioResults)) {
    const suppliedScenario = scenarioResults[scenarioId];
    const links = [];
    for (const source of [
      suppliedScenario.linked_findings,
      suppliedScenario.linkedFindings,
      suppliedScenario.finding_links,
      suppliedScenario.findingLinks,
      suppliedScenario.findings,
      suppliedLinks[scenarioId],
    ]) {
      if (Array.isArray(source)) {
        links.push(...source);
      } else if (source && typeof source === 'object') {
        links.push(source);
      } else if (stringValue(source) !== '') {
        links.push({ scenario_id: scenarioId, url: stringValue(source) });
      }
    }
    if (links.length > 0) {
      merged[scenarioId] = links;
    }
  }

  if (runRecordFindings.length > 0) {
    merged.run_record = [
      ...(Array.isArray(merged.run_record) ? merged.run_record : []),
      ...runRecordFindings,
    ];
  }

  return merged;
}

function mergeFindings(evidence, findingLinks, runRecordFindings = []) {
  const supplied = Array.isArray(evidence.findings) ? evidence.findings : [];
  const merged = [...supplied, ...runRecordFindings];
  const seen = new Set(merged.map((finding) => JSON.stringify(finding)));

  for (const links of Object.values(findingLinks)) {
    for (const link of Array.isArray(links) ? links : []) {
      const encoded = JSON.stringify(link);
      if (!seen.has(encoded)) {
        seen.add(encoded);
        merged.push(link);
      }
    }
  }

  return merged;
}

function writeArtifacts(publishedArtifactVersions, resolvedArtifactVersions, artifactSources, evidence) {
  writeJson('migration-published-artifacts.json', {
    schema: ARTIFACT_SCHEMA,
    generated_at: timestamp(),
    published_artifact_versions: publishedArtifactVersions,
    resolved_artifact_versions: resolvedArtifactVersions,
    artifact_sources: artifactSources,
    local_product_source_checkouts_used: localProductSourceCheckoutsUsedIn(evidence),
    required_artifacts: effectiveRequiredArtifacts(),
  });
}

function writeResult(result) {
  writeJson('migration-conformance-result.json', result);
  writeJson('migration-conformance-record.json', {
    schema: RECORD_SCHEMA,
    version: 1,
    generated_at: result.generated_at,
    outcome: result.outcome,
    runner_blocked: result.runner_blocked,
    result_file: 'migration-conformance-result.json',
    artifact_file: 'migration-published-artifacts.json',
    required_scenarios: effectiveRequiredScenarios(),
    reported_scenarios: Object.keys(objectValue(result.scenario_results)),
    non_pass_scenarios: Object.entries(objectValue(result.scenario_results))
      .filter(([, scenario]) => scenario?.status !== 'pass')
      .map(([scenarioId]) => scenarioId),
    finding_links: result.finding_links,
  });
}

function writeJson(fileName, value) {
  fs.writeFileSync(path.join(resultDir, fileName), `${JSON.stringify(value, null, 2)}\n`);
}

function scenarioResultsById(evidence) {
  const raw = evidence.scenario_results ?? evidence.scenarioResults ?? {};
  const results = {};

  if (Array.isArray(raw)) {
    for (const item of raw) {
      if (!item || typeof item !== 'object') {
        continue;
      }
      const scenarioId = stringValue(item.scenario_id) || stringValue(item.id);
      if (scenarioId !== '') {
        results[scenarioId] = item;
      }
    }
    return results;
  }

  for (const [key, value] of Object.entries(objectValue(raw))) {
    if (value && typeof value === 'object' && !Array.isArray(value)) {
      results[key] = value;
    }
  }

  return results;
}

function artifactVersionsFromEnv() {
  const workflowV2 = stringValue(process.env.DW_WORKFLOW_PHP_V2_VERSION)
    || stringValue(process.env.DW_WORKFLOW_PHP_VERSION)
    || stringValue(process.env.DW_WORKFLOW_VERSION);

  return {
    'server-v1': stringValue(process.env.DW_SERVER_V1_VERSION),
    'server-v2': stringValue(process.env.DW_SERVER_V2_VERSION) || stringValue(process.env.DW_SERVER_VERSION),
    cli: stringValue(process.env.DW_CLI_VERSION),
    'workflow-php-v1': stringValue(process.env.DW_WORKFLOW_PHP_V1_VERSION)
      || stringValue(process.env.DW_WORKFLOW_V1_VERSION),
    'workflow-php-v2': workflowV2,
    'sdk-python': stringValue(process.env.DW_PYTHON_SDK_VERSION),
    waterline: stringValue(process.env.DW_WATERLINE_VERSION),
  };
}

function artifactSourcesFromEnv() {
  return {
    'server-v1': stringValue(process.env.DW_SERVER_V1_ARTIFACT_SOURCE) || 'not_exercised',
    'server-v2': stringValue(process.env.DW_SERVER_V2_ARTIFACT_SOURCE)
      || stringValue(process.env.DW_SERVER_ARTIFACT_SOURCE)
      || 'not_exercised',
    cli: stringValue(process.env.DW_CLI_ARTIFACT_SOURCE) || 'not_exercised',
    'workflow-php-v1': stringValue(process.env.DW_WORKFLOW_PHP_V1_ARTIFACT_SOURCE)
      || stringValue(process.env.DW_WORKFLOW_V1_ARTIFACT_SOURCE)
      || 'not_exercised',
    'workflow-php-v2': stringValue(process.env.DW_WORKFLOW_PHP_V2_ARTIFACT_SOURCE)
      || stringValue(process.env.DW_WORKFLOW_PHP_ARTIFACT_SOURCE)
      || stringValue(process.env.DW_WORKFLOW_ARTIFACT_SOURCE)
      || 'not_exercised',
    'sdk-python': stringValue(process.env.DW_PYTHON_SDK_ARTIFACT_SOURCE) || 'not_exercised',
    waterline: stringValue(process.env.DW_WATERLINE_ARTIFACT_SOURCE) || 'not_exercised',
  };
}

function coverageGapFinding(scenarioId, artifactVersions, overrides) {
  return {
    scenario_id: scenarioId,
    owning_surface: 'conformance_harness',
    finding_type: 'conformance_runner_coverage_gap',
    artifact_versions: artifactVersions,
    ...overrides,
  };
}

function notCoveredObservation(kind, reason) {
  return {
    status: 'not_covered',
    kind,
    observed_behavior: reason,
  };
}

function requiredFieldsFor(scenarioId) {
  return arrayOfStrings(scenarioRequirements?.[scenarioId]?.required_fields);
}

function effectiveRequiredArtifacts() {
  return requiredArtifacts.length > 0 ? requiredArtifacts : FALLBACK_REQUIRED_ARTIFACTS;
}

function effectiveRequiredScenarios() {
  return requiredScenarios.length > 0 ? requiredScenarios : FALLBACK_REQUIRED_SCENARIOS;
}

function normalizedStatus(value) {
  const status = stringValue(value);
  return ['pass', 'fail', 'unsupported', 'not_covered', 'runner_blocked'].includes(status)
    ? status
    : 'not_covered';
}

function hasLinkedFinding(scenario) {
  return [
    scenario.linked_findings,
    scenario.linkedFindings,
    scenario.finding_links,
    scenario.findingLinks,
    scenario.findings,
  ].some((value) => {
    if (Array.isArray(value)) {
      return value.length > 0;
    }
    if (value && typeof value === 'object') {
      return Object.keys(value).length > 0;
    }
    return stringValue(value) !== '';
  });
}

function linkedFindingsForScenario(scenario) {
  const links = [];
  for (const source of [
    scenario.linked_findings,
    scenario.linkedFindings,
    scenario.finding_links,
    scenario.findingLinks,
    scenario.findings,
  ]) {
    if (Array.isArray(source)) {
      links.push(...source);
    } else if (source && typeof source === 'object') {
      links.push(source);
    } else if (stringValue(source) !== '') {
      links.push(stringValue(source));
    }
  }
  return links;
}

function hasField(container, field) {
  for (const alias of fieldAliases(field)) {
    if (!isEmptyEvidence(container?.[alias])) {
      return true;
    }
  }
  return false;
}

function fieldAliases(field) {
  const parts = field.split('_');
  const camel = parts[0] + parts.slice(1).map((part) => part.charAt(0).toUpperCase() + part.slice(1)).join('');
  return [field, camel];
}

function isEmptyEvidence(value) {
  if (value === null || value === undefined) {
    return true;
  }
  if (typeof value === 'string') {
    return value.trim() === '';
  }
  if (Array.isArray(value)) {
    return value.length === 0;
  }
  if (typeof value === 'object') {
    const status = stringValue(value.status).toLowerCase();
    if (['not_covered', 'runner_blocked'].includes(status) || truthy(value.coverage_gap)) {
      return true;
    }

    return Object.keys(value).length === 0;
  }
  return false;
}

function fieldValue(container, field) {
  const object = objectValue(container);
  for (const alias of fieldAliases(field)) {
    if (Object.hasOwn(object, alias)) {
      return object[alias];
    }
  }

  return undefined;
}

function nonEmptyObject(value) {
  const object = objectValue(value);
  return Object.keys(object).length > 0 ? object : null;
}

function objectValue(value) {
  return value && typeof value === 'object' && !Array.isArray(value) ? value : {};
}

function arrayOfStrings(value) {
  return Array.isArray(value)
    ? value.map((entry) => stringValue(entry)).filter(Boolean)
    : [];
}

function objectOfStringLists(value) {
  const output = {};
  for (const [key, entries] of Object.entries(objectValue(value))) {
    const aliases = arrayOfStrings(entries);
    if (aliases.length > 0) {
      output[key] = aliases;
    }
  }
  return output;
}

function uniqueStrings(value) {
  return Array.from(new Set(arrayOfStrings(value)));
}

function mergeMaps(...maps) {
  const merged = {};
  for (const map of maps) {
    for (const [key, value] of Object.entries(objectValue(map))) {
      if (stringValue(value) !== '' || !Object.hasOwn(merged, key)) {
        merged[key] = value;
      }
    }
  }
  return merged;
}

function truthy(value) {
  if (value === true) {
    return true;
  }
  const text = stringValue(value).toLowerCase();
  return ['1', 'true', 'yes'].includes(text);
}

function localProductSourceCheckoutsUsedIn(...containers) {
  return localProductSourceFlagValues(...containers).some((value) => truthy(value));
}

function localProductSourceFlagValues(...containers) {
  const values = [];
  for (const container of containers) {
    collectLocalProductSourceFlagValues(container, values);
  }
  return values;
}

function collectLocalProductSourceFlagValues(value, values) {
  if (!value || typeof value !== 'object') {
    return;
  }

  if (Array.isArray(value)) {
    for (const entry of value) {
      collectLocalProductSourceFlagValues(entry, values);
    }
    return;
  }

  if (Object.hasOwn(value, 'local_product_source_checkouts_used')) {
    values.push(value.local_product_source_checkouts_used);
  }
  if (Object.hasOwn(value, 'localProductSourceCheckoutsUsed')) {
    values.push(value.localProductSourceCheckoutsUsed);
  }

  for (const field of ['scenario_results', 'scenarioResults', 'observed_outputs', 'observedOutputs']) {
    collectLocalProductSourceFlagValues(value[field], values);
  }

  for (const entry of Object.values(value)) {
    if (entry && typeof entry === 'object') {
      collectLocalProductSourceFlagValues(entry, values);
    }
  }
}

function stringValue(value) {
  return typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean'
    ? String(value).trim()
    : '';
}

function timestamp() {
  return new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
}

function readJsonIfExists(filePath) {
  if (!filePath || !fs.existsSync(filePath)) {
    return null;
  }
  return JSON.parse(fs.readFileSync(filePath, 'utf8'));
}
