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
const evidenceDirPath = process.env.DW_MIGRATION_EVIDENCE_DIR
  ?? path.join(resultDir, 'migration-evidence.d');
const storageSmokePath = process.env.DW_MIGRATION_STORAGE_SMOKE_JSON
  ?? path.join(resultDir, 'storage-connection-smoke.json');
const publicArtifactsPath = process.env.DW_MIGRATION_PUBLIC_ARTIFACTS_JSON
  ?? path.join(resultDir, 'migration-public-artifacts.json');

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
const SCENARIO_FINDING_POLICIES = {
  published_artifact_install_only: {
    owning_surface: 'conformance_harness',
    finding_type: 'missing_or_invalid_published_migration_artifact',
    expected_behavior: 'Migration conformance installs every required v1 and v2 artifact from a pinned published channel.',
    next_acceptance_criterion: 'rerun migration conformance with exact downloadable artifact versions and install sources for every required channel',
  },
  latest_supported_v1_state_setup: {
    owning_surface: 'workflow',
    finding_type: 'migration_v1_state_setup_failure',
    expected_behavior: 'The latest supported v1 release set can seed completed, in-flight, retrying, scheduled, worker, history, and operator-visible state through public surfaces.',
    next_acceptance_criterion: 'seed all required v1 state kinds from published artifacts and attach the preupgrade observations',
  },
  documented_migration_steps_execute: {
    owning_surface: 'docs',
    finding_type: 'missing_or_wrong_migration_guide_step',
    expected_behavior: 'A user can follow the live public migration guide verbatim and start the target v2 stack without manual undocumented steps.',
    next_acceptance_criterion: 'fix the public guide or product command so every documented migration step executes as written',
  },
  completed_history_preservation_and_replay: {
    owning_surface: 'workflow',
    finding_type: 'data_loss_or_replay_break',
    expected_behavior: 'Completed v1 histories remain readable, exportable, queryable, and replay-safe after migration.',
    next_acceptance_criterion: 'preserve completed history replay/query behavior across the v1-to-v2 upgrade and attach before/after history evidence',
  },
  in_flight_workflow_progress_preserved: {
    owning_surface: 'workflow',
    finding_type: 'data_loss_or_replay_break',
    expected_behavior: 'Open v1 workflows resume from their preupgrade durable progress marker under v2 workers.',
    next_acceptance_criterion: 'preserve in-flight progress across migration and attach before/after progress and completion evidence',
  },
  mid_activity_retry_preserved: {
    owning_surface: 'workflow',
    finding_type: 'data_loss_or_replay_break',
    expected_behavior: 'Activity retry attempt counts, retry timing, and final results survive migration without duplicate execution.',
    next_acceptance_criterion: 'preserve mid-activity retry state across migration and attach retry attempt evidence',
  },
  schedule_cross_upgrade_cadence_preserved: {
    owning_surface: 'server',
    finding_type: 'schedule_drift',
    expected_behavior: 'Schedules retain cadence across the upgrade without silent missed or duplicate ticks.',
    next_acceptance_criterion: 'preserve cross-upgrade schedule cadence and attach before/after tick evidence',
  },
  worker_registration_projection_preserved: {
    owning_surface: 'server',
    finding_type: 'worker_compatibility_gap',
    expected_behavior: 'Worker registrations and task queue projections remain operator-visible and compatible across the upgrade.',
    next_acceptance_criterion: 'preserve worker registration projection across migration and attach worker-list and polling evidence',
  },
  waterline_operator_visibility_preserved: {
    owning_surface: 'waterline',
    finding_type: 'waterline_visibility_break',
    expected_behavior: 'Waterline continues to render preupgrade workflow, run, schedule, and history state after migration.',
    next_acceptance_criterion: 'restore Waterline visibility for migrated state and attach before/after operator snapshots',
  },
  cli_access_to_preupgrade_state: {
    owning_surface: 'cli',
    finding_type: 'cli_regression',
    expected_behavior: 'The v2 CLI can describe migrated workflows, histories, and schedules with typed JSON responses.',
    next_acceptance_criterion: 'restore CLI access to migrated state and attach command, exit-code, and JSON response evidence',
  },
  new_v2_workflow_start_after_upgrade: {
    owning_surface: 'workflow',
    finding_type: 'postupgrade_start_regression',
    expected_behavior: 'New v2 workflows can start and complete after the migrated v1-origin state remains readable.',
    next_acceptance_criterion: 'start and complete a new v2 workflow after migration and attach completion and history evidence',
  },
  rollback_contract_verified: {
    owning_surface: 'docs',
    finding_type: 'rollback_mismatch',
    expected_behavior: 'The guide either verifies a supported rollback path or clearly documents rollback as unsupported with typed refusal behavior.',
    next_acceptance_criterion: 'verify documented rollback behavior or update the guide with explicit rollback limits and attach rollback observations',
  },
  version_skew_refusal: {
    owning_surface: 'server',
    finding_type: 'skew_silence',
    expected_behavior: 'Unsupported v1/v2 server, worker, CLI, SDK, and Waterline combinations refuse loudly before partial durable-state mutation.',
    next_acceptance_criterion: 'record loud version-skew refusal for every required migration skew cell and attach no-partial-mutation evidence',
  },
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
  const artifactSources = artifactSourcesFromEnv({ includeDefaults: true });
  writeArtifacts(artifactVersions, artifactVersions, artifactSources, null);
  writeResult(blockedResult(reason, startedAt, finishedAt, artifactVersions, artifactSources));
});

async function main() {
  fs.mkdirSync(resultDir, { recursive: true });

  const evidence = readMigrationEvidence();
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
  const publicArtifactResolution = await resolvePublicArtifactDefaults();

  const publishedArtifactVersions = normalizeArtifactAliases(mergeMaps(
    publicArtifactResolution.artifact_versions,
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
  const artifactSources = normalizeArtifactAliases(mergeArtifactSourceMapsPreservingForbidden(
    mergeArtifactSourceMaps(
      publicArtifactResolution.artifact_sources,
      artifactSourcesFromEnv({ includeDefaults: false }),
    ),
    ...artifactSourceMapsFromEvidence(evidence),
  ), true);
  const storageSmoke = normalizeStorageSmoke(evidence);

  writeArtifacts(publishedArtifactVersions, resolvedArtifactVersions, artifactSources, evidence, publicArtifactResolution);

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

  const artifactPrerequisiteFailures = [
    ...artifactPrerequisiteFailuresFor(
      publishedArtifactVersions,
      resolvedArtifactVersions,
      artifactSources,
    ),
    ...artifactSourceFailuresForEvidence(evidence),
  ];
  const scenarioResults = buildScenarioResults(
    evidence,
    resolvedArtifactVersions,
    artifactPrerequisiteFailures,
    publishedArtifactVersions,
    artifactSources,
  );
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
    public_artifact_resolution: publicArtifactResolution,
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

function buildScenarioResults(
  evidence,
  artifactVersions,
  artifactPrerequisiteFailures = [],
  publishedArtifactVersions = {},
  artifactSources = {},
) {
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

    if (
      scenarioId === 'published_artifact_install_only'
      && artifactPrerequisiteFailures.length === 0
      && artifactMapComplete(publishedArtifactVersions, false)
      && artifactMapComplete(artifactVersions, false)
      && artifactMapComplete(artifactSources, true)
      && !localProductSourceCheckoutsUsedIn(evidence)
    ) {
      results[scenarioId] = synthesizedPublishedArtifactInstallScenario(
        publishedArtifactVersions,
        artifactVersions,
        artifactSources,
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

function synthesizedPublishedArtifactInstallScenario(
  publishedArtifactVersions,
  resolvedArtifactVersions,
  artifactSources,
) {
  return {
    scenario_id: 'published_artifact_install_only',
    status: 'pass',
    observed_outputs: {
      published_artifact_versions: publishedArtifactVersions,
      resolved_artifact_versions: resolvedArtifactVersions,
      artifact_sources: artifactSources,
      local_product_source_checkouts_used: false,
      source: 'recorded_published_artifact_install_policy',
    },
  };
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
    const path = stringValue(failure.path);
    const valueDetail = value === '' ? '' : `; observed ${field}=${value}`;
    const pathDetail = path === '' ? '' : ` at ${path}`;

    return {
      scenario_id: scenarioId,
      owning_surface: owningSurface,
      finding_type: 'missing_or_invalid_published_migration_artifact',
      artifact,
      artifact_versions: artifactVersions,
      observed_behavior: `Required migration artifact ${artifact} has ${code} in ${field}${pathDetail}${valueDetail}.`,
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
      findingForNonPassScenario(scenarioId, status, normalized, artifactVersions),
    ];
  }

  return normalized;
}

function findingForNonPassScenario(scenarioId, status, scenario, artifactVersions) {
  if (['fail', 'unsupported'].includes(status)) {
    const policy = SCENARIO_FINDING_POLICIES[scenarioId] ?? {
      owning_surface: 'conformance_harness',
      finding_type: 'migration_contract_failure',
      expected_behavior: 'Migration conformance records a focused root-cause finding for every failed or unsupported migration contract cell.',
      next_acceptance_criterion: 'attach the owning product or documentation root-cause finding before recording this scenario as non-passing',
    };

    return {
      scenario_id: scenarioId,
      owning_surface: policy.owning_surface,
      finding_type: policy.finding_type,
      artifact_versions: artifactVersions,
      observed_behavior: observedBehaviorForScenarioFailure(scenarioId, status, scenario),
      expected_behavior: policy.expected_behavior,
      next_acceptance_criterion: policy.next_acceptance_criterion,
    };
  }

  return coverageGapFinding(scenarioId, artifactVersions, {
    observed_behavior: `Scenario ${scenarioId} reported ${status} without a linked root-cause finding.`,
    expected_behavior: 'Every non-pass migration scenario links to the focused product or conformance finding that explains the result.',
    next_acceptance_criterion: 'attach the root-cause finding link to the scenario result before recording the run',
  });
}

function observedBehaviorForScenarioFailure(scenarioId, status, scenario) {
  const observedOutputs = objectValue(scenario.observed_outputs);
  const candidates = [
    scenario.observed_behavior,
    scenario.observedBehavior,
    scenario.failure_reason,
    scenario.failureReason,
    observedOutputs.observed_behavior,
    observedOutputs.observedBehavior,
    observedOutputs.failure_reason,
    observedOutputs.failureReason,
    observedOutputs.error,
    observedOutputs.message,
  ];

  const detail = candidates.map((candidate) => stringValue(candidate)).find((candidate) => candidate !== '');
  return detail || `Migration scenario ${scenarioId} reported ${status} without a detailed observed behavior.`;
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
    || arrayValue(result.artifact_prerequisite_failures).length > 0
    || artifactSourceFailuresForEvidence(result).length > 0
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

function writeArtifacts(
  publishedArtifactVersions,
  resolvedArtifactVersions,
  artifactSources,
  evidence,
  publicArtifactResolution = {},
) {
  writeJson('migration-published-artifacts.json', {
    schema: ARTIFACT_SCHEMA,
    generated_at: timestamp(),
    published_artifact_versions: publishedArtifactVersions,
    resolved_artifact_versions: resolvedArtifactVersions,
    artifact_sources: artifactSources,
    public_artifact_resolution: publicArtifactResolution,
    local_product_source_checkouts_used: localProductSourceCheckoutsUsedIn(evidence),
    required_artifacts: effectiveRequiredArtifacts(),
  });
}

function writeResult(result) {
  const scenarioResults = objectValue(result.scenario_results);
  writeJson('migration-conformance-result.json', result);
  writeJson('migration-conformance-record.json', {
    schema: RECORD_SCHEMA,
    version: 1,
    generated_at: result.generated_at,
    started_at: result.started_at,
    finished_at: result.finished_at,
    outcome: result.outcome,
    runner_blocked: result.runner_blocked,
    artifact_versions: result.artifact_versions,
    published_artifact_versions: result.published_artifact_versions,
    resolved_artifact_versions: result.resolved_artifact_versions,
    artifact_sources: result.artifact_sources,
    public_artifact_resolution: result.public_artifact_resolution ?? {},
    artifact_prerequisite_failures: result.artifact_prerequisite_failures,
    local_product_source_checkouts_used: result.local_product_source_checkouts_used,
    result_file: 'migration-conformance-result.json',
    artifact_file: 'migration-published-artifacts.json',
    required_scenarios: effectiveRequiredScenarios(),
    reported_scenarios: Object.keys(scenarioResults),
    scenario_statuses: Object.fromEntries(
      Object.entries(scenarioResults).map(([scenarioId, scenario]) => [scenarioId, scenario?.status ?? null]),
    ),
    non_pass_scenarios: Object.entries(scenarioResults)
      .filter(([, scenario]) => scenario?.status !== 'pass')
      .map(([scenarioId]) => scenarioId),
    finding_links: result.finding_links,
    findings: result.findings,
    migration_plan: result.migration_plan,
    preupgrade_state_snapshot: result.preupgrade_state_snapshot,
    postupgrade_state_snapshot: result.postupgrade_state_snapshot,
    history_dumps: result.history_dumps,
    activity_attempts: result.activity_attempts,
    schedule_ticks: result.schedule_ticks,
    worker_registration_observations: result.worker_registration_observations,
    cli_observations: result.cli_observations,
    waterline_observations: result.waterline_observations,
    rollback_observations: result.rollback_observations,
    version_skew_observations: result.version_skew_observations,
    storage_connection_smoke: result.storage_connection_smoke,
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

function readMigrationEvidence() {
  const inputs = [];
  const fileEvidence = readJsonIfExists(evidencePath);
  if (fileEvidence) {
    inputs.push(fileEvidence);
  }

  for (const shardPath of evidenceShardPaths(evidenceDirPath)) {
    const shard = readJsonIfExists(shardPath);
    if (shard) {
      inputs.push(shard);
    }
  }

  return mergeEvidenceObjects(...inputs);
}

function evidenceShardPaths(dirPath) {
  if (!dirPath || !fs.existsSync(dirPath) || !fs.statSync(dirPath).isDirectory()) {
    return [];
  }

  return fs.readdirSync(dirPath)
    .filter((fileName) => fileName.endsWith('.json'))
    .sort()
    .map((fileName) => path.join(dirPath, fileName));
}

function mergeEvidenceObjects(...entries) {
  const merged = {};

  for (const entry of entries) {
    const evidence = normalizeEvidenceShard(entry);
    mergeEvidenceInto(merged, evidence);
  }

  return merged;
}

function normalizeEvidenceShard(value) {
  const object = objectValue(value);
  const scenarioId = stringValue(object.scenario_id) || stringValue(object.id);

  if (scenarioId === '' || object.scenario_results || object.scenarioResults) {
    return object;
  }

  const scenario = { ...object };
  delete scenario.id;

  return {
    scenario_results: {
      [scenarioId]: scenario,
    },
  };
}

function mergeEvidenceInto(target, source) {
  for (const [key, value] of Object.entries(objectValue(source))) {
    if (['scenario_results', 'scenarioResults'].includes(key)) {
      target.scenario_results = mergeScenarioResults(target.scenario_results, value);
      continue;
    }

    if (['finding_links', 'findingLinks', 'linked_findings', 'linkedFindings'].includes(key)) {
      target.finding_links = mergeFindingLinkObjects(target.finding_links, value);
      continue;
    }

    if (key === 'findings' && Array.isArray(value)) {
      target.findings = [
        ...(Array.isArray(target.findings) ? target.findings : []),
        ...value,
      ];
      continue;
    }

    target[key] = mergeEvidenceValue(target[key], value);
  }
}

function mergeScenarioResults(left, right) {
  const merged = scenarioResultsById({ scenario_results: left });
  const incoming = scenarioResultsById({ scenario_results: right });

  for (const [scenarioId, scenario] of Object.entries(incoming)) {
    merged[scenarioId] = mergeEvidenceValue(merged[scenarioId], scenario);
  }

  return merged;
}

function mergeFindingLinkObjects(left, right) {
  const merged = { ...objectValue(left) };
  for (const [scenarioId, links] of Object.entries(objectValue(right))) {
    merged[scenarioId] = [
      ...arrayValue(merged[scenarioId]),
      ...arrayValue(links),
    ];
  }
  return merged;
}

function mergeEvidenceValue(left, right) {
  if (right === undefined || right === null) {
    return left;
  }

  if (Array.isArray(left) || Array.isArray(right)) {
    return [
      ...arrayValue(left),
      ...arrayValue(right),
    ];
  }

  if (left && typeof left === 'object' && right && typeof right === 'object') {
    const merged = { ...objectValue(left) };
    for (const [key, value] of Object.entries(objectValue(right))) {
      merged[key] = mergeEvidenceValue(merged[key], value);
    }
    return merged;
  }

  return right;
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

async function resolvePublicArtifactDefaults() {
  const disabled = ['0', 'false', 'no'].includes(
    stringValue(process.env.DW_MIGRATION_RESOLVE_PUBLIC_ARTIFACTS || '1').toLowerCase(),
  );
  const fixture = readJsonIfExists(publicArtifactsPath);
  const resolution = {
    artifact_versions: {},
    artifact_sources: {},
    observations: {},
  };

  mergePublicArtifactResolution(resolution, fixture);

  if (disabled) {
    resolution.observations.resolution = {
      status: 'disabled',
      source: 'DW_MIGRATION_RESOLVE_PUBLIC_ARTIFACTS',
    };
    return resolution;
  }

  if (stringValue(resolution.artifact_versions['workflow-php-v1']) === '') {
    try {
      const workflowV1 = await latestPackagistVersion('laravel-workflow/laravel-workflow', /^v?1\./);
      if (workflowV1 !== '') {
        resolution.artifact_versions['workflow-php-v1'] = workflowV1;
        resolution.artifact_sources['workflow-php-v1'] =
          `packagist:laravel-workflow/laravel-workflow:${workflowV1}`;
        resolution.observations['workflow-php-v1'] = {
          status: 'resolved',
          channel: 'packagist',
          package: 'laravel-workflow/laravel-workflow',
          version: workflowV1,
        };
      }
    } catch (error) {
      resolution.observations['workflow-php-v1'] = {
        status: 'resolution_error',
        channel: 'packagist',
        package: 'laravel-workflow/laravel-workflow',
        error: errorMessage(error),
      };
    }
  }

  if (
    stringValue(resolution.artifact_versions['server-v1']) === ''
    && stringValue(resolution.observations['server-v1']?.status) !== 'missing'
  ) {
    try {
      const serverV1 = await latestDockerHubTag('durableworkflow/server', /^v?1\./);
      if (serverV1 !== '') {
        resolution.artifact_versions['server-v1'] = serverV1;
        resolution.artifact_sources['server-v1'] = `docker_hub:durableworkflow/server:${serverV1}`;
        resolution.observations['server-v1'] = {
          status: 'resolved',
          channel: 'docker_hub',
          repository: 'durableworkflow/server',
          tag: serverV1,
        };
      } else if (stringValue(resolution.artifact_sources['server-v1']) === '') {
        resolution.artifact_sources['server-v1'] =
          'docker_hub:durableworkflow/server:no_v1_release_tag_found';
        resolution.observations['server-v1'] = {
          status: 'missing',
          channel: 'docker_hub',
          repository: 'durableworkflow/server',
          expected_tag_family: '1.x',
        };
      }
    } catch (error) {
      resolution.observations['server-v1'] = {
        status: 'resolution_error',
        channel: 'docker_hub',
        repository: 'durableworkflow/server',
        error: errorMessage(error),
      };
    }
  }

  return resolution;
}

function mergePublicArtifactResolution(target, source) {
  const object = objectValue(source);
  target.artifact_versions = mergeMaps(
    target.artifact_versions,
    objectValue(object.artifact_versions),
    objectValue(object.artifactVersions),
    objectValue(object.published_artifact_versions),
    objectValue(object.publishedArtifactVersions),
  );
  target.artifact_sources = mergeArtifactSourceMaps(
    target.artifact_sources,
    objectValue(object.artifact_sources),
    objectValue(object.artifactSources),
    objectValue(object.install_sources),
    objectValue(object.installSources),
  );
  target.observations = mergeEvidenceValue(
    target.observations,
    objectValue(object.observations ?? object.public_artifact_resolution ?? object.publicArtifactResolution),
  );
}

async function latestPackagistVersion(packageName, versionPattern) {
  const metadata = await fetchJson(`https://repo.packagist.org/p2/${packageName}.json`);
  const versions = arrayValue(metadata?.packages?.[packageName])
    .map((entry) => stringValue(entry?.version))
    .filter((version) => versionPattern.test(version) && !isPrereleaseVersion(version));

  return versions.sort(compareVersionStrings).pop() ?? '';
}

async function latestDockerHubTag(repository, tagPattern) {
  let next = `https://registry.hub.docker.com/v2/repositories/${repository}/tags?page_size=100`;
  const tags = [];
  let pages = 0;

  while (next && pages < 10) {
    pages += 1;
    const metadata = await fetchJson(next);
    for (const tag of arrayValue(metadata.results)) {
      const name = stringValue(tag?.name);
      if (tagPattern.test(name) && !isPrereleaseVersion(name)) {
        tags.push(name);
      }
    }
    next = stringValue(metadata.next);
  }

  return tags.sort(compareVersionStrings).pop() ?? '';
}

async function fetchJson(url) {
  const response = await fetch(url, {
    headers: {
      'user-agent': 'durable-workflow-migration-conformance',
      accept: 'application/json',
    },
    signal: AbortSignal.timeout(8000),
  });
  if (!response.ok) {
    throw new Error(`GET ${url} returned HTTP ${response.status}`);
  }
  return response.json();
}

function isPrereleaseVersion(version) {
  return /(?:alpha|beta|rc|dev|snapshot)/i.test(version);
}

function compareVersionStrings(left, right) {
  const leftParts = versionParts(left);
  const rightParts = versionParts(right);
  const length = Math.max(leftParts.length, rightParts.length);

  for (let index = 0; index < length; index += 1) {
    const leftPart = leftParts[index] ?? 0;
    const rightPart = rightParts[index] ?? 0;
    if (leftPart !== rightPart) {
      return leftPart - rightPart;
    }
  }

  return left.localeCompare(right);
}

function versionParts(version) {
  return stringValue(version)
    .replace(/^v/i, '')
    .split(/[^0-9]+/)
    .filter((part) => part !== '')
    .map((part) => Number.parseInt(part, 10));
}

function errorMessage(error) {
  return error instanceof Error ? error.message : String(error);
}

function artifactSourcesFromEnv({ includeDefaults = false } = {}) {
  const defaultSource = includeDefaults ? 'not_exercised' : '';

  return {
    'server-v1': stringValue(process.env.DW_SERVER_V1_ARTIFACT_SOURCE) || defaultSource,
    'server-v2': stringValue(process.env.DW_SERVER_V2_ARTIFACT_SOURCE)
      || stringValue(process.env.DW_SERVER_ARTIFACT_SOURCE)
      || defaultSource,
    cli: stringValue(process.env.DW_CLI_ARTIFACT_SOURCE) || defaultSource,
    'workflow-php-v1': stringValue(process.env.DW_WORKFLOW_PHP_V1_ARTIFACT_SOURCE)
      || stringValue(process.env.DW_WORKFLOW_V1_ARTIFACT_SOURCE)
      || defaultSource,
    'workflow-php-v2': stringValue(process.env.DW_WORKFLOW_PHP_V2_ARTIFACT_SOURCE)
      || stringValue(process.env.DW_WORKFLOW_PHP_ARTIFACT_SOURCE)
      || stringValue(process.env.DW_WORKFLOW_ARTIFACT_SOURCE)
      || defaultSource,
    'sdk-python': stringValue(process.env.DW_PYTHON_SDK_ARTIFACT_SOURCE) || defaultSource,
    waterline: stringValue(process.env.DW_WATERLINE_ARTIFACT_SOURCE) || defaultSource,
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

function arrayValue(value) {
  if (Array.isArray(value)) {
    return value;
  }
  if (value === undefined || value === null) {
    return [];
  }
  return [value];
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

function mergeArtifactSourceMaps(...maps) {
  return mergeArtifactSourceMapsWithPolicy(false, ...maps);
}

function mergeArtifactSourceMapsPreservingForbidden(...maps) {
  return mergeArtifactSourceMapsWithPolicy(true, ...maps);
}

function mergeArtifactSourceMapsWithPolicy(preserveForbiddenSources, ...maps) {
  const merged = {};
  for (const map of maps) {
    for (const [key, value] of Object.entries(objectValue(map))) {
      const source = stringValue(value);
      const existing = stringValue(merged[key]);
      if (source === '') {
        if (!Object.hasOwn(merged, key)) {
          merged[key] = value;
        }
        continue;
      }
      if (preserveForbiddenSources && containsForbiddenSourceToken(existing)) {
        continue;
      }
      if (preserveForbiddenSources && containsForbiddenSourceToken(source)) {
        merged[key] = value;
        continue;
      }
      if (source === 'not_exercised' && existing !== '' && existing !== 'not_exercised') {
        continue;
      }
      merged[key] = value;
    }
  }
  return merged;
}

function artifactSourceMapsFromEvidence(evidence) {
  const maps = [];
  for (const field of ['artifact_sources', 'artifactSources', 'install_sources', 'installSources']) {
    const map = objectValue(evidence?.[field]);
    if (Object.keys(map).length > 0) {
      maps.push(map);
    }
  }
  return maps;
}

function artifactSourceFailuresForEvidence(evidence) {
  const failures = topLevelArtifactSourceFailuresForEvidence(evidence);
  const scenarios = scenarioResultsById(evidence);

  for (const [scenarioId, scenario] of Object.entries(scenarios)) {
    appendScenarioArtifactSourceFailures(
      failures,
      scenarioId,
      objectValue(scenario),
      `$.scenario_results.${scenarioId}`,
    );

    const observedOutputs = nonEmptyObject(scenario.observed_outputs)
      ?? nonEmptyObject(scenario.observedOutputs)
      ?? nonEmptyObject(scenario.evidence)
      ?? null;
    if (observedOutputs !== null) {
      appendScenarioArtifactSourceFailures(
        failures,
        scenarioId,
        observedOutputs,
        `$.scenario_results.${scenarioId}.observed_outputs`,
      );
    }
  }

  return failures;
}

function topLevelArtifactSourceFailuresForEvidence(evidence) {
  const failures = [];

  for (const field of ['artifact_sources', 'artifactSources', 'install_sources', 'installSources']) {
    const sources = objectValue(evidence?.[field]);
    if (Object.keys(sources).length === 0) {
      continue;
    }

    failures.push(...artifactSourceMapFailuresFor(sources, {
      field,
      path: `$.${field}`,
      scenarioId: null,
      requireComplete: false,
    }));
  }

  return failures;
}

function appendScenarioArtifactSourceFailures(failures, scenarioId, container, pathPrefix) {
  const requiresCompleteSources = requiredFieldsFor(scenarioId).includes('artifact_sources');

  for (const field of ['artifact_sources', 'artifactSources', 'install_sources', 'installSources']) {
    const sources = objectValue(container[field]);
    if (Object.keys(sources).length === 0) {
      continue;
    }

    failures.push(...artifactSourceMapFailuresFor(sources, {
      field,
      path: `${pathPrefix}.${field}`,
      scenarioId,
      requireComplete: requiresCompleteSources,
    }));
  }
}

function artifactSourceMapFailuresFor(sources, {
  field,
  path: sourcePath,
  scenarioId = null,
  requireComplete = false,
}) {
  const failures = [];
  const reportedForbiddenArtifacts = new Set();

  for (const [key, value] of Object.entries(objectValue(sources))) {
    const source = stringValue(value);
    if (source === '' || !containsForbiddenSourceToken(source)) {
      continue;
    }

    const artifact = canonicalArtifactFor(key);
    reportedForbiddenArtifacts.add(artifact);
    failures.push({
      artifact,
      field,
      code: 'forbidden_published_artifact_source',
      value: source,
      path: sourcePath,
      scenario_id: scenarioId,
    });
  }

  if (!requireComplete) {
    return failures;
  }

  for (const artifact of effectiveRequiredArtifacts()) {
    const source = stringValue(artifactMapEntry(objectValue(sources), artifact));
    if (source !== '' || reportedForbiddenArtifacts.has(artifact)) {
      continue;
    }

    failures.push({
      artifact,
      field,
      code: 'missing_published_artifact_source',
      path: sourcePath,
      scenario_id: scenarioId,
    });
  }

  return failures;
}

function canonicalArtifactFor(key) {
  const artifactKey = stringValue(key);
  if (effectiveRequiredArtifacts().includes(artifactKey)) {
    return artifactKey;
  }

  return effectiveRequiredArtifacts()
    .find((artifact) => artifactAliasesFor(artifact).includes(artifactKey))
    ?? artifactKey;
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
