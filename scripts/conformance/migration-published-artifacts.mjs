#!/usr/bin/env node
import fs from 'node:fs';
import childProcess from 'node:child_process';
import crypto from 'node:crypto';
import path from 'node:path';
import process from 'node:process';

const RESULT_SCHEMA = 'durable-workflow.v2.migration-runtime.result';
const RECORD_SCHEMA = 'durable-workflow.v2.migration-runtime.record';
const ARTIFACT_SCHEMA = 'durable-workflow.v2.migration-runtime.published-artifacts';
const WORKFLOW_V1_PRIMARY_PACKAGE = 'durable-workflow/workflow';
const WORKFLOW_V1_LEGACY_PACKAGE = 'laravel-workflow/laravel-workflow';

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
const foundationPlanPath = process.env.DW_MIGRATION_FOUNDATION_PLAN_FILE
  ?? path.join(resultDir, 'migration-foundation-plan.json');
const publicArtifactsPath = process.env.DW_MIGRATION_PUBLIC_ARTIFACTS_JSON
  ?? path.join(resultDir, 'migration-public-artifacts.json');
const migrationGuideUrl = process.env.DW_MIGRATION_GUIDE_URL
  ?? 'https://durable-workflow.github.io/docs/2.0/migration/';
const publicGuideAuditMode = stringValue(process.env.DW_MIGRATION_RUN_PUBLIC_GUIDE_AUDIT || 'auto').toLowerCase();
const foundationPlanMode = stringValue(process.env.DW_MIGRATION_RUN_FOUNDATION_PLAN || 'auto').toLowerCase();

const FALLBACK_REQUIRED_ARTIFACTS = [
  'server-v1',
  'server-v2',
  'cli-v1',
  'cli-v2',
  'workflow-php-v1',
  'workflow-php-v2',
  'sdk-python',
  'waterline-v1',
  'waterline-v2',
  'sample-app-v1',
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
const REQUIRED_RUNBOOK_COMMAND_OUTPUT_FIELDS = [
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
const COMMAND_OUTPUT_COLLECTION_FIELDS = [
  'command_outputs',
  'commandOutputs',
  'step_outputs',
  'stepOutputs',
  'executed_steps',
  'executedSteps',
  'command_results',
  'commandResults',
];
const RUNBOOK_COMMAND_OUTPUT_EVIDENCE_FIELDS = [
  'runbook_command_outputs',
  'runbookCommandOutputs',
  'command_outputs_by_section',
  'commandOutputsBySection',
  'section_command_outputs',
  'sectionCommandOutputs',
  'runbook_section_outputs',
  'runbookSectionOutputs',
  'migration_record_command_outputs',
  'migrationRecordCommandOutputs',
  'migration_runbook_command_outputs',
  'migrationRunbookCommandOutputs',
  'command_output_evidence',
  'commandOutputEvidence',
];
const RUNBOOK_SECTION_CONTAINER_FIELDS = [
  'runbook',
  'runbookEvidence',
  'runbook_evidence',
  'migration_runbook',
  'migrationRunbook',
  'runbook_sections',
  'runbookSections',
  'sections',
  'scenario_outputs',
  'scenarioOutputs',
];
const RUNBOOK_SECTION_ALIASES = {
  migration_plan: [
    'migration_plan',
    'migrationPlan',
    'documented_migration_steps_execute',
    'documentedMigrationStepsExecute',
    'migration_guide_execution',
    'migrationGuideExecution',
    'guide_execution',
    'guideExecution',
    'step_by_step_guide',
    'stepByStepGuide',
  ],
  preupgrade_state_snapshot: [
    'preupgrade_state_snapshot',
    'preupgradeStateSnapshot',
    'pre_upgrade_state_snapshot',
    'preUpgradeStateSnapshot',
    'latest_supported_v1_state_setup',
    'latestSupportedV1StateSetup',
    'realistic_v1_state_setup',
    'realisticV1StateSetup',
    'realistic_v1_state_snapshot',
    'realisticV1StateSnapshot',
    'v1_state_snapshot',
    'v1StateSnapshot',
  ],
  postupgrade_state_snapshot: [
    'postupgrade_state_snapshot',
    'postupgradeStateSnapshot',
    'post_upgrade_state_snapshot',
    'postUpgradeStateSnapshot',
    'post_upgrade_verification',
    'postUpgradeVerification',
  ],
  history_dumps: [
    'history_dumps',
    'historyDumps',
    'completed_history_preservation_and_replay',
    'completedHistoryPreservationAndReplay',
    'completed_history_replay',
    'completedHistoryReplay',
  ],
  activity_attempts: [
    'activity_attempts',
    'activityAttempts',
    'mid_activity_retry_preserved',
    'midActivityRetryPreserved',
  ],
  schedule_ticks: [
    'schedule_ticks',
    'scheduleTicks',
    'schedule_cross_upgrade_cadence_preserved',
    'scheduleCrossUpgradeCadencePreserved',
  ],
  worker_registration_observations: [
    'worker_registration_observations',
    'workerRegistrationObservations',
    'worker_registration_projection_preserved',
    'workerRegistrationProjectionPreserved',
    'worker_registrations',
    'workerRegistrations',
  ],
  cli_observations: [
    'cli_observations',
    'cliObservations',
    'cli_access_to_preupgrade_state',
    'cliAccessToPreupgradeState',
  ],
  waterline_observations: [
    'waterline_observations',
    'waterlineObservations',
    'waterline_operator_visibility_preserved',
    'waterlineOperatorVisibilityPreserved',
    'operator_projections',
    'operatorProjections',
  ],
  rollback_observations: [
    'rollback_observations',
    'rollbackObservations',
    'rollback_result',
    'rollbackResult',
    'rollback_contract_verified',
    'rollbackContractVerified',
  ],
  version_skew_observations: [
    'version_skew_observations',
    'versionSkewObservations',
    'version_skew_refusal',
    'versionSkewRefusal',
    'skew_observations',
    'skewObservations',
  ],
  storage_connection_smoke: [
    'storage_connection_smoke',
    'storageConnectionSmoke',
    'storage_smoke',
    'storageSmoke',
    'storage_connection_smoke_result',
    'storageConnectionSmokeResult',
  ],
};
const SCENARIO_RUNBOOK_SECTION_FIELDS = {
  latest_supported_v1_state_setup: ['preupgrade_state_snapshot'],
  documented_migration_steps_execute: ['migration_plan'],
  completed_history_preservation_and_replay: ['history_dumps', 'postupgrade_state_snapshot'],
  in_flight_workflow_progress_preserved: ['history_dumps', 'postupgrade_state_snapshot'],
  mid_activity_retry_preserved: ['activity_attempts', 'postupgrade_state_snapshot'],
  schedule_cross_upgrade_cadence_preserved: ['schedule_ticks', 'postupgrade_state_snapshot'],
  worker_registration_projection_preserved: ['worker_registration_observations', 'postupgrade_state_snapshot'],
  waterline_operator_visibility_preserved: ['waterline_observations'],
  cli_access_to_preupgrade_state: ['cli_observations'],
  new_v2_workflow_start_after_upgrade: ['postupgrade_state_snapshot'],
  rollback_contract_verified: ['rollback_observations'],
  version_skew_refusal: ['version_skew_observations'],
  storage_connection_smoke: ['storage_connection_smoke'],
  migration_storage_connection_smoke: ['storage_connection_smoke'],
};
const OBSERVED_STATE_ENTRY_FIELDS = [
  'observed_states',
  'observedStates',
  'observed_state_entries',
  'observedStateEntries',
  'state_entries',
  'stateEntries',
  'states',
];
const STATE_ENTRY_KIND_FIELDS = ['state_kind', 'stateKind', 'kind', 'type', 'name', 'scenario'];
const STATE_CELL_METADATA_FIELDS = [
  ...STATE_ENTRY_KIND_FIELDS,
  'status',
  'phase',
  'state_kinds',
  'stateKinds',
  'expected_state_kinds',
  'expectedStateKinds',
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
const PLACEHOLDER_EVIDENCE_TOKENS = [
  'not_executed',
  'not_executed_by_public_guide_audit',
  'not_documented_by_public_guide_audit',
  'documented_but_not_executed',
  'blocked_before_execution_by_unexecutable_public_guide_commands',
  'not_exercised',
  'not_supplied',
  'not_available',
  'placeholder',
];
const SIGNAL_EXIT_CODES = {
  SIGHUP: 129,
  SIGINT: 130,
  SIGQUIT: 131,
  SIGILL: 132,
  SIGTRAP: 133,
  SIGABRT: 134,
  SIGBUS: 135,
  SIGFPE: 136,
  SIGKILL: 137,
  SIGUSR1: 138,
  SIGSEGV: 139,
  SIGUSR2: 140,
  SIGPIPE: 141,
  SIGALRM: 142,
  SIGTERM: 143,
};
const EVIDENCE_METADATA_FIELDS = [
  'status',
  'kind',
  'source',
  'phase',
  'state_kind',
  'stateKind',
  'state_kinds',
  'stateKinds',
  'expected_state_kinds',
  'expectedStateKinds',
  'type',
  'name',
  'scenario',
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
  'cli-v1': 'cli',
  'cli-v2': 'cli',
  'workflow-php-v1': 'workflow',
  'workflow-php-v2': 'workflow',
  'sdk-python': 'sdk-python',
  'waterline-v1': 'waterline',
  'waterline-v2': 'waterline',
  'sample-app-v1': 'sample-app',
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

  let evidence = readMigrationEvidence();
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
  const foundationPlanEvidence = maybeExecuteFoundationPlan(
    startedAt,
    resolvedArtifactVersions,
    publishedArtifactVersions,
    artifactSources,
  );
  if (foundationPlanEvidence !== null) {
    evidence = mergeEvidenceObjects(foundationPlanEvidence, evidence);
  }
  let storageSmoke = normalizeStorageSmoke(evidence);
  const storageFoundationEvidence = migrationFoundationEvidenceFromStorageSmoke(
    storageSmoke,
    startedAt,
    resolvedArtifactVersions,
    publishedArtifactVersions,
    artifactSources,
  );
  if (Object.keys(storageFoundationEvidence).length > 0) {
    evidence = mergeEvidenceObjects(storageFoundationEvidence, evidence);
  }
  const publicGuideAudit = await maybeRunPublicGuideAudit(
    evidence,
    startedAt,
    resolvedArtifactVersions,
    publishedArtifactVersions,
    artifactSources,
  );
  if (publicGuideAudit !== null) {
    evidence = mergeEvidenceObjects(publicGuideAudit, evidence);
  }
  storageSmoke = normalizeStorageSmoke(evidence);
  const storageSmokeOnlyProductEvidence = storageSmokeProvidesProductEvidence(storageSmoke)
    && !hasSuppliedFullMigrationEvidence(evidence);

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
    storageSmokeOnlyProductEvidence,
    storageSmoke,
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
    migration_plan: topLevelRunbookObservation(evidence, 'migration_plan')
      ?? missingRunRecordObservation(
        'migration_plan',
        'No public migration-guide execution plan was supplied.',
        storageSmokeOnlyProductEvidence,
      ),
    preupgrade_state_snapshot: topLevelRunbookObservation(evidence, 'preupgrade_state_snapshot')
      ?? missingRunRecordObservation(
        'preupgrade_state_snapshot',
        'No realistic v1 state snapshot was supplied.',
        storageSmokeOnlyProductEvidence,
      ),
    postupgrade_state_snapshot: topLevelRunbookObservation(evidence, 'postupgrade_state_snapshot')
      ?? missingRunRecordObservation(
        'postupgrade_state_snapshot',
        'No migrated v2 state snapshot was supplied.',
        storageSmokeOnlyProductEvidence,
      ),
    history_dumps: topLevelRunbookObservation(evidence, 'history_dumps')
      ?? missingRunRecordObservation(
        'history_dumps',
        'No before/after history dumps were supplied.',
        storageSmokeOnlyProductEvidence,
      ),
    activity_attempts: topLevelRunbookObservation(evidence, 'activity_attempts')
      ?? missingRunRecordObservation(
        'activity_attempts',
        'No activity retry observations were supplied.',
        storageSmokeOnlyProductEvidence,
      ),
    schedule_ticks: topLevelRunbookObservation(evidence, 'schedule_ticks')
      ?? missingRunRecordObservation(
        'schedule_ticks',
        'No cross-upgrade schedule tick observations were supplied.',
        storageSmokeOnlyProductEvidence,
      ),
    worker_registration_observations: topLevelRunbookObservation(evidence, 'worker_registration_observations')
      ?? missingRunRecordObservation(
        'worker_registration_observations',
        'No worker registration projection observations were supplied.',
        storageSmokeOnlyProductEvidence,
      ),
    cli_observations: topLevelRunbookObservation(evidence, 'cli_observations')
      ?? missingRunRecordObservation(
        'cli_observations',
        'No CLI observations against migrated state were supplied.',
        storageSmokeOnlyProductEvidence,
      ),
    waterline_observations: topLevelRunbookObservation(evidence, 'waterline_observations')
      ?? missingRunRecordObservation(
        'waterline_observations',
        'No Waterline/operator observations were supplied.',
        storageSmokeOnlyProductEvidence,
      ),
    rollback_observations: topLevelRunbookObservation(evidence, 'rollback_observations')
      ?? missingRunRecordObservation(
        'rollback_observations',
        'No rollback observations were supplied.',
        storageSmokeOnlyProductEvidence,
      ),
    version_skew_observations: topLevelRunbookObservation(evidence, 'version_skew_observations')
      ?? missingRunRecordObservation(
        'version_skew_observations',
        'No version-skew refusal observations were supplied.',
        storageSmokeOnlyProductEvidence,
      ),
    storage_connection_smoke: topLevelRunbookObservation({ storage_connection_smoke: storageSmoke }, 'storage_connection_smoke') ?? storageSmoke,
    implementation_identity: {
      runner: 'scripts/conformance/migration-published-artifacts.sh',
      evidence_input: fs.existsSync(evidencePath) ? evidencePath : null,
      storage_smoke_input: fs.existsSync(storageSmokePath) ? storageSmokePath : null,
      local_product_source_artifacts: false,
    },
  };

  result.runbook_command_outputs = runbookCommandOutputRecord(result);
  result.runbookCommandOutputs = result.runbook_command_outputs;
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
  storageSmokeOnlyProductEvidence = false,
  storageSmoke = {},
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

    results[scenarioId] = missingScenarioResult(
      scenarioId,
      artifactVersions,
      storageSmokeOnlyProductEvidence,
      storageSmoke,
    );
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

function missingScenarioResult(
  scenarioId,
  artifactVersions,
  storageSmokeOnlyProductEvidence,
  storageSmoke,
) {
  if (storageSmokeOnlyProductEvidence) {
    const scenario = {
      scenario_id: scenarioId,
      status: 'fail',
      observed_outputs: {
        storage_connection_smoke_only: true,
        storage_connection_smoke_status: observedStorageSmokeStatus(storageSmoke),
        required_fields: requiredFieldsFor(scenarioId),
        local_product_source_checkouts_used: false,
        observed_behavior: `Published-artifact migration conformance exercised storage-connection smoke but did not execute ${scenarioId}.`,
      },
    };

    return {
      ...scenario,
      linked_findings: [
        findingForNonPassScenario(scenarioId, 'fail', scenario, artifactVersions),
      ],
    };
  }

  const finding = coverageGapFinding(scenarioId, artifactVersions, {
    observed_behavior: `No published-artifact migration evidence was supplied for ${scenarioId}.`,
    expected_behavior: 'The host migration runner executes this required v1-to-v2 migration cell against pinned published artifacts.',
    next_acceptance_criterion: `run the ${scenarioId} migration cell and attach observed outputs for every required field`,
  });

  return {
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
  let observedOutputs = nonEmptyObject(scenario.observed_outputs)
    ?? nonEmptyObject(scenario.observedOutputs)
    ?? nonEmptyObject(scenario.evidence)
    ?? {};
  const commandOutputs = runbookSectionCommandOutputs({
    ...objectValue(scenario),
    observed_outputs: observedOutputs,
  });
  observedOutputs = withoutDirectCommandOutputFields(observedOutputs);
  if (!isEmptyEvidence(commandOutputs)) {
    observedOutputs = {
      ...observedOutputs,
      command_outputs: commandOutputs,
    };
  }
  const commandFailure = containsFoundationCommandFailure({
    ...objectValue(scenario),
    observed_outputs: observedOutputs,
  });
  const status = commandFailure ? 'fail' : normalizedStatus(scenario.status);
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

  return uniqueStrings([
    ...missing,
    ...missingScenarioCommandOutputFields(scenarioId, scenario, observedOutputs),
    ...scenarioSpecificMissingRequiredFields(scenarioId, scenario, observedOutputs),
  ]);
}

function missingScenarioCommandOutputFields(scenarioId, scenario, observedOutputs) {
  if (scenarioId === 'published_artifact_install_only') {
    return [];
  }

  const scenarioOutputs = runbookSectionCommandOutputs(scenario);
  const observedOutputCommands = runbookSectionCommandOutputs(observedOutputs);
  return isEmptyEvidence(scenarioOutputs) && isEmptyEvidence(observedOutputCommands)
    ? ['command_outputs']
    : [];
}

function scenarioSpecificMissingRequiredFields(scenarioId, scenario, observedOutputs) {
  switch (scenarioId) {
    case 'latest_supported_v1_state_setup':
      return [
        ...missingEvidenceItemsForField(scenario, observedOutputs, 'seeded_workflows', [
          'completed_workflow',
          'running_workflow_waiting_on_signal',
          'workflow_with_activity',
          'workflow_mid_activity_retry',
        ]),
        ...missingEvidenceItemsForField(scenario, observedOutputs, 'seeded_schedules', [
          'active_schedule',
        ]),
        ...missingEvidenceItemsForField(scenario, observedOutputs, 'seeded_worker_registrations', [
          'registered_workers',
        ]),
        ...missingEvidenceItemsForField(scenario, observedOutputs, 'queryable_history', [
          'queryable_history',
        ]),
      ];
    case 'documented_migration_steps_execute':
      return [
        ...['commands_executed', 'exit_codes', 'command_timings']
          .filter((field) => !hasNonEmptyArrayField(observedOutputs, field) && !hasNonEmptyArrayField(scenario, field)),
        ...missingGuideCommandExecutabilityFields(scenario, observedOutputs),
      ];
    case 'rollback_contract_verified':
      return [
        ...['rollback_steps']
          .filter((field) => !hasNonEmptyArrayField(observedOutputs, field) && !hasNonEmptyArrayField(scenario, field)),
        ...missingRollbackClassificationFields(scenario, observedOutputs),
      ];
    case 'version_skew_refusal':
      return [
        ...['skew_matrix', 'refusal_errors', 'request_response_evidence', 'no_partial_mutation_evidence']
          .filter((field) => !hasNonEmptyArrayField(observedOutputs, field) && !hasNonEmptyArrayField(scenario, field)),
        ...missingEvidenceItemsForField(scenario, observedOutputs, 'cli_skew_observations', [
          'cli-v1-to-server-v2',
          'cli-v2-to-server-v1',
        ]),
        ...missingEvidenceItemsForField(scenario, observedOutputs, 'worker_skew_observations', [
          'worker-v1-to-server-v2',
          'worker-v2-to-server-v1',
        ]),
        ...missingEvidenceItemsForField(scenario, observedOutputs, 'request_response_evidence', [
          'cli-v1-to-server-v2',
          'cli-v2-to-server-v1',
          'worker-v1-to-server-v2',
          'worker-v2-to-server-v1',
        ]),
      ];
    default:
      return [];
  }
}

function missingGuideCommandExecutabilityFields(scenario, observedOutputs) {
  let value = fieldValue(observedOutputs, 'guide_command_executability');
  if (isEmptyEvidence(value)) {
    value = fieldValue(scenario, 'guide_command_executability');
  }

  if (isEmptyEvidence(value)) {
    return ['guide_command_executability'];
  }

  const evidence = objectValue(value);
  const status = stringValue(evidence.status).toLowerCase();
  const unexecutableCommands = arrayValue(evidence.unexecutable_commands ?? evidence.unexecutableCommands);
  const missing = [];

  if (status !== 'pass') {
    missing.push('guide_command_executability.status_pass');
  }

  if (unexecutableCommands.length > 0) {
    missing.push('guide_command_executability.unexecutable_commands_empty');
  }

  return missing;
}

function missingRollbackClassificationFields(scenario, observedOutputs) {
  let value = fieldValue(observedOutputs, 'rollback_supported_state');
  if (isEmptyEvidence(value)) {
    value = fieldValue(scenario, 'rollback_supported_state');
  }

  return evidenceContainsAnyToken(value, ['supported', 'refused', 'irreversible', 'unsupported'])
    ? []
    : ['rollback_supported_state.supported_refused_or_irreversible'];
}

function missingEvidenceItemsForField(scenario, observedOutputs, field, items) {
  let value = fieldValue(observedOutputs, field);
  if (isEmptyEvidence(value)) {
    value = fieldValue(scenario, field);
  }

  return items
    .filter((item) => !evidenceContainsItem(value, item))
    .map((item) => `${field}.${item}`);
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

function migrationFoundationEvidenceFromStorageSmoke(
  storageSmoke,
  startedAt,
  resolvedArtifactVersions,
  publishedArtifactVersions,
  artifactSources,
) {
  if (!storageSmokeProvidesProductEvidence(storageSmoke)) {
    return {};
  }

  const smoke = objectValue(storageSmoke);
  const source = firstNonEmptyEvidenceObject(smoke, [
    'migration_foundation_evidence',
    'migrationFoundationEvidence',
    'foundation_evidence',
    'foundationEvidence',
    'v1_to_v2_foundation',
    'v1ToV2Foundation',
  ]);
  const foundation = Object.keys(source).length > 0 ? source : smoke;
  const result = {};

  const migrationPlan = firstNonEmptyEvidenceObject(foundation, [
    'migration_plan',
    'migrationPlan',
    'migration_guide_execution',
    'migrationGuideExecution',
    'guide_execution',
    'guideExecution',
    'step_by_step_guide',
    'stepByStepGuide',
  ]);
  const latestSupportedV1StateSetup = firstNonEmptyEvidenceObject(foundation, [
    'latest_supported_v1_state_setup',
    'latestSupportedV1StateSetup',
    'realistic_v1_state_setup',
    'realisticV1StateSetup',
    'realistic_v1_state_snapshot',
    'realisticV1StateSnapshot',
    'v1_state_setup',
    'v1StateSetup',
    'v1_state_snapshot',
    'v1StateSnapshot',
  ]);
  const preupgradeSnapshot = firstNonEmptyEvidenceObject(foundation, [
    'preupgrade_state_snapshot',
    'preupgradeStateSnapshot',
    'pre_upgrade_state_snapshot',
    'preUpgradeStateSnapshot',
    'realistic_v1_state_snapshot',
    'realisticV1StateSnapshot',
    'v1_state_snapshot',
    'v1StateSnapshot',
  ]);
  const postupgradeSnapshot = firstNonEmptyEvidenceObject(foundation, [
    'postupgrade_state_snapshot',
    'postupgradeStateSnapshot',
    'post_upgrade_state_snapshot',
    'postUpgradeStateSnapshot',
    'post_upgrade_verification',
    'postUpgradeVerification',
    'v2_state_snapshot',
    'v2StateSnapshot',
  ]);

  if (Object.keys(migrationPlan).length > 0) {
    result.migration_plan = foundationTopLevelObservation(
      'migration_plan',
      migrationPlan,
      resolvedArtifactVersions,
      publishedArtifactVersions,
      artifactSources,
    );
  }
  if (Object.keys(preupgradeSnapshot).length > 0) {
    result.preupgrade_state_snapshot = foundationTopLevelObservation(
      'preupgrade_state_snapshot',
      preupgradeSnapshot,
      resolvedArtifactVersions,
      publishedArtifactVersions,
      artifactSources,
    );
  }
  if (Object.keys(postupgradeSnapshot).length > 0) {
    result.postupgrade_state_snapshot = foundationTopLevelObservation(
      'postupgrade_state_snapshot',
      postupgradeSnapshot,
      resolvedArtifactVersions,
      publishedArtifactVersions,
      artifactSources,
    );
  }

  const scenarioResults = {};
  const v1SetupOutputs = observedOutputsForRunbookScenario(
    'latest_supported_v1_state_setup',
    latestSupportedV1StateSetup,
  );
  if (Object.keys(v1SetupOutputs).length > 0) {
    scenarioResults.latest_supported_v1_state_setup = foundationScenarioResult(
      'latest_supported_v1_state_setup',
      latestSupportedV1StateSetup,
      v1SetupOutputs,
      startedAt,
    );
  }

  const guideOutputs = observedOutputsForRunbookScenario(
    'documented_migration_steps_execute',
    migrationPlan,
  );
  if (Object.keys(guideOutputs).length > 0) {
    scenarioResults.documented_migration_steps_execute = foundationScenarioResult(
      'documented_migration_steps_execute',
      migrationPlan,
      guideOutputs,
      startedAt,
    );
  }

  if (Object.keys(scenarioResults).length > 0) {
    result.scenario_results = scenarioResults;
  }

  return result;
}

function foundationTopLevelObservation(
  kind,
  observation,
  resolvedArtifactVersions,
  publishedArtifactVersions,
  artifactSources,
) {
  return {
    status: normalizedStatus(observation.status || observation.outcome || 'pass'),
    kind,
    source: stringValue(observation.source) || 'published_artifact_foundation_run',
    published_artifact_versions: publishedArtifactVersions,
    resolved_artifact_versions: resolvedArtifactVersions,
    artifact_sources: artifactSources,
    ...observation,
  };
}

function foundationScenarioResult(scenarioId, source, observedOutputs, startedAt) {
  return {
    scenario_id: scenarioId,
    status: normalizedStatus(source.status || source.outcome || 'pass'),
    started_at: stringValue(source.started_at) || stringValue(source.startedAt) || startedAt,
    finished_at: stringValue(source.finished_at) || stringValue(source.finishedAt) || timestamp(),
    observed_outputs: {
      source: stringValue(source.source) || 'published_artifact_foundation_run',
      local_product_source_checkouts_used: false,
      ...observedOutputs,
    },
  };
}

function topLevelRunbookObservation(evidence, field) {
  const value = nonEmptyObject(fieldValue(evidence, field));
  if (value === null) {
    return null;
  }

  return withRunbookCommandOutputs(value);
}

function withRunbookCommandOutputs(value) {
  const observation = objectValue(value);
  const commandOutputs = runbookSectionCommandOutputs(observation);
  const normalizedObservation = withoutDirectCommandOutputFields(observation);
  if (isEmptyEvidence(commandOutputs)) {
    return normalizedObservation;
  }

  return {
    ...normalizedObservation,
    command_outputs: commandOutputs,
  };
}

async function maybeRunPublicGuideAudit(
  evidence,
  startedAt,
  resolvedArtifactVersions,
  publishedArtifactVersions,
  artifactSources,
) {
  if (publicGuideAuditDisabled() || hasSuppliedFullMigrationEvidence(evidence)) {
    return null;
  }

  const forced = ['1', 'true', 'yes', 'force'].includes(publicGuideAuditMode);
  if (!forced && !storageSmokeProvidesProductEvidence(normalizeStorageSmoke(evidence))) {
    return null;
  }

  try {
    const guide = await loadPublicMigrationGuide();
    if (guide.text.trim() === '') {
      return null;
    }

    return buildPublicGuideAuditEvidence(
      guide,
      startedAt,
      resolvedArtifactVersions,
      publishedArtifactVersions,
      artifactSources,
    );
  } catch (error) {
    if (!forced) {
      return null;
    }

    const reason = `Public migration guide audit could not read ${migrationGuideUrl}: ${errorMessage(error)}`;
    return {
      migration_plan: publicGuideAuditTopLevelObservation('migration_plan', {
        status: 'fail',
        observed_behavior: reason,
      }),
      scenario_results: Object.fromEntries(
        effectiveRequiredScenarios()
          .filter((scenarioId) => scenarioId !== 'published_artifact_install_only')
          .map((scenarioId) => [
            scenarioId,
            publicGuideAuditScenario(
              scenarioId,
              {
                guide_url: migrationGuideUrl,
                guide_audit_status: 'failed',
                failure_reason: reason,
              },
              resolvedArtifactVersions,
            ),
          ]),
      ),
    };
  }
}

function publicGuideAuditDisabled() {
  return ['0', 'false', 'no', 'off', 'disabled'].includes(publicGuideAuditMode);
}

async function loadPublicMigrationGuide() {
  const inline = stringValue(process.env.DW_MIGRATION_GUIDE_AUDIT_TEXT);
  if (inline !== '') {
    return {
      url: 'inline:DW_MIGRATION_GUIDE_AUDIT_TEXT',
      source: 'DW_MIGRATION_GUIDE_AUDIT_TEXT',
      fetched_at: timestamp(),
      fetch_duration_ms: 0,
      text: inline,
    };
  }

  const guideFile = stringValue(process.env.DW_MIGRATION_GUIDE_AUDIT_FILE);
  if (guideFile !== '') {
    const started = Date.now();
    return {
      url: `file:${guideFile}`,
      source: 'DW_MIGRATION_GUIDE_AUDIT_FILE',
      fetched_at: timestamp(),
      fetch_duration_ms: Date.now() - started,
      text: fs.readFileSync(guideFile, 'utf8'),
    };
  }

  const started = Date.now();
  const response = await fetch(migrationGuideUrl, {
    headers: {
      'user-agent': 'durable-workflow-migration-conformance',
      accept: 'text/html,text/markdown,text/plain',
    },
    signal: AbortSignal.timeout(10000),
  });
  if (!response.ok) {
    throw new Error(`GET returned HTTP ${response.status}`);
  }

  const body = await response.text();
  return {
    url: migrationGuideUrl,
    source: 'live_public_migration_guide',
    fetched_at: timestamp(),
    fetch_duration_ms: Date.now() - started,
    text: body,
  };
}

function buildPublicGuideAuditEvidence(
  guide,
  startedAt,
  resolvedArtifactVersions,
  publishedArtifactVersions,
  artifactSources,
) {
  const text = htmlToText(guide.text);
  const normalized = normalizeGuideText(text);
  const signals = publicGuideSignals(normalized);
  const commands = extractMigrationGuideCommands(guide.text, text);
  const guideCommandExecutability = migrationGuideCommandExecutability(commands);
  const guideDigest = sha256(text);
  const guideRevision = {
    url: guide.url,
    source: guide.source,
    fetched_at: guide.fetched_at,
    sha256: guideDigest,
  };
  const observedBehavior = [
    'The public migration guide was audited after storage-connection smoke passed, but the host runner did not execute a realistic v1-to-v2 stateful upgrade shard.',
    signals.finish_on_v1_strategy
      ? 'The guide documents finish-on-v1 behavior; that claim still requires runtime proof with completed, in-flight, retrying, scheduled, worker, CLI, and Waterline observations.'
      : 'The guide audit did not find an explicit finish-on-v1 migration strategy.',
  ].join(' ');

  return {
    migration_plan: publicGuideAuditTopLevelObservation('migration_plan', {
      guide_revision: guideRevision,
      guide_url: guide.url,
      guide_sha256: guideDigest,
      guide_signals: signals,
      commands_extracted: commands,
      guide_command_executability: guideCommandExecutability,
      recorded_timings: {
        guide_fetch_ms: guide.fetch_duration_ms,
      },
      artifact_sources: artifactSources,
      published_artifact_versions: publishedArtifactVersions,
      resolved_artifact_versions: resolvedArtifactVersions,
      observed_behavior: observedBehavior,
    }),
    preupgrade_state_snapshot: publicGuideAuditTopLevelObservation('preupgrade_state_snapshot', {
      expected_state_kinds: scenarioManifest?.required_matrix?.state_kinds ?? [],
      observed_behavior: 'The guide audit did not seed completed, in-flight, retrying, scheduled, worker, history, or operator-visible v1 state.',
    }),
    postupgrade_state_snapshot: publicGuideAuditTopLevelObservation('postupgrade_state_snapshot', {
      expected_state_kinds: scenarioManifest?.required_matrix?.state_kinds ?? [],
      observed_behavior: 'The guide audit did not start a migrated v2 stack against preserved v1 state.',
    }),
    history_dumps: publicGuideAuditTopLevelObservation('history_dumps', {
      observed_behavior: 'No before/after workflow history exports were produced by the public-guide audit shard.',
    }),
    activity_attempts: publicGuideAuditTopLevelObservation('activity_attempts', {
      observed_behavior: 'No mid-activity retry attempt evidence was produced by the public-guide audit shard.',
    }),
    schedule_ticks: publicGuideAuditTopLevelObservation('schedule_ticks', {
      observed_behavior: 'No cross-upgrade schedule tick evidence was produced by the public-guide audit shard.',
    }),
    worker_registration_observations: publicGuideAuditTopLevelObservation('worker_registration_observations', {
      observed_behavior: 'No before/after worker registration projection evidence was produced by the public-guide audit shard.',
    }),
    cli_observations: publicGuideAuditTopLevelObservation('cli_observations', {
      observed_behavior: 'No v2 CLI access to preupgrade workflow or schedule identifiers was exercised by the public-guide audit shard.',
    }),
    waterline_observations: publicGuideAuditTopLevelObservation('waterline_observations', {
      observed_behavior: signals.waterline_shows_both
        ? 'The guide claims Waterline shows v1 and v2 workflows side-by-side, but the host runner did not exercise the Waterline surface against migrated state.'
        : 'The guide audit did not find complete Waterline preservation instructions, and the host runner did not exercise Waterline against migrated state.',
    }),
    rollback_observations: publicGuideAuditTopLevelObservation('rollback_observations', {
      observed_behavior: signals.rollback_procedure
        ? 'The guide documents a rollback procedure, but the host runner did not restore the pinned v1 artifact set and verify rollback observations.'
        : 'The guide audit did not find a rollback procedure, and no rollback observations were produced.',
    }),
    version_skew_observations: publicGuideAuditTopLevelObservation('version_skew_observations', {
      observed_behavior: 'No v1/v2 server, worker, CLI, SDK, or Waterline skew refusal cells were exercised by the public-guide audit shard.',
    }),
    scenario_results: Object.fromEntries(
      effectiveRequiredScenarios()
        .filter((scenarioId) => scenarioId !== 'published_artifact_install_only')
        .map((scenarioId) => [
          scenarioId,
          publicGuideAuditScenario(
            scenarioId,
            publicGuideAuditScenarioOutputs(
              scenarioId,
              guideRevision,
              guideDigest,
              commands,
              guideCommandExecutability,
              signals,
              resolvedArtifactVersions,
            ),
            resolvedArtifactVersions,
          ),
        ]),
    ),
  };
}

function publicGuideAuditTopLevelObservation(kind, fields = {}) {
  return {
    status: 'fail',
    kind,
    source: 'public_migration_guide_audit',
    guide_audit_only: true,
    ...fields,
  };
}

function publicGuideAuditScenario(scenarioId, observedOutputs, artifactVersions) {
  const scenarioStatus = normalizedStatus(observedOutputs.scenario_status)
    || normalizedStatus(observedOutputs.scenarioStatus)
    || 'not_covered';
  const reason = stringValue(observedOutputs.failure_reason)
    || `The public migration guide audit did not execute the ${scenarioId} migration cell against published artifacts.`;
  const scenario = {
    scenario_id: scenarioId,
    status: scenarioStatus,
    observed_outputs: {
      ...observedOutputs,
      source: 'public_migration_guide_audit',
      guide_audit_only: true,
      required_fields: requiredFieldsFor(scenarioId),
      local_product_source_checkouts_used: false,
    },
  };

  if (scenarioStatus === 'fail' || scenarioStatus === 'unsupported') {
    return {
      ...scenario,
      linked_findings: [
        findingForNonPassScenario(scenarioId, scenarioStatus, scenario, artifactVersions),
      ],
    };
  }

  return {
    ...scenario,
    linked_findings: [
      coverageGapFinding(scenarioId, artifactVersions, {
        guide_url: observedOutputs.guide_url ?? migrationGuideUrl,
        observed_behavior: reason,
        expected_behavior: 'The host migration runner executes this required v1-to-v2 migration cell against pinned published artifacts after following the public migration guide.',
        next_acceptance_criterion: `execute the ${scenarioId} migration cell against the current published v1/v2 tuple and attach the required before/after observations`,
      }),
    ],
  };
}

function publicGuideAuditScenarioOutputs(
  scenarioId,
  guideRevision,
  guideDigest,
  commands,
  guideCommandExecutability,
  signals,
  artifactVersions,
) {
  const common = {
    guide_url: guideRevision.url,
    migration_guide_revision: guideRevision,
    guide_sha256: guideDigest,
    guide_signals: signals,
    commands_extracted: commands,
    guide_command_executability: guideCommandExecutability,
    failure_reason: publicGuideAuditScenarioReason(scenarioId, signals),
  };

  switch (scenarioId) {
    case 'latest_supported_v1_state_setup':
      return {
        ...common,
        source_release_versions: artifactVersions,
        seeded_workflows: 'not_executed_by_public_guide_audit',
        seeded_schedules: 'not_executed_by_public_guide_audit',
        seeded_worker_registrations: 'not_executed_by_public_guide_audit',
        queryable_history: 'not_executed_by_public_guide_audit',
      };
    case 'documented_migration_steps_execute':
      if (guideCommandExecutability.status === 'fail') {
        return {
          ...common,
          scenario_status: 'fail',
          failure_reason: guideCommandExecutability.observed_behavior,
          commands_executed: [],
          exit_codes: [],
          command_timings: [],
          schema_or_storage_migration_output: 'blocked_before_execution_by_unexecutable_public_guide_commands',
        };
      }

      return {
        ...common,
        commands_executed: [],
        exit_codes: [],
        command_timings: [],
        schema_or_storage_migration_output: 'not_executed_by_public_guide_audit',
      };
    case 'completed_history_preservation_and_replay':
      return {
        ...common,
        preupgrade_history_export: 'not_executed_by_public_guide_audit',
        postupgrade_history_export: 'not_executed_by_public_guide_audit',
        replay_result: 'not_executed_by_public_guide_audit',
        query_result: 'not_executed_by_public_guide_audit',
      };
    case 'in_flight_workflow_progress_preserved':
      return {
        ...common,
        preupgrade_progress_marker: 'not_executed_by_public_guide_audit',
        postupgrade_progress_marker: 'not_executed_by_public_guide_audit',
        completion_result: 'not_executed_by_public_guide_audit',
        history_dumps: 'not_executed_by_public_guide_audit',
      };
    case 'mid_activity_retry_preserved':
      return {
        ...common,
        preupgrade_activity_attempt: 'not_executed_by_public_guide_audit',
        postupgrade_activity_attempt: 'not_executed_by_public_guide_audit',
        retry_policy: 'not_executed_by_public_guide_audit',
        final_activity_result: 'not_executed_by_public_guide_audit',
      };
    case 'schedule_cross_upgrade_cadence_preserved':
      return {
        ...common,
        preupgrade_schedule_spec: 'not_executed_by_public_guide_audit',
        last_tick_before_upgrade: 'not_executed_by_public_guide_audit',
        first_tick_after_upgrade: 'not_executed_by_public_guide_audit',
        missed_or_duplicate_ticks: 'not_executed_by_public_guide_audit',
      };
    case 'worker_registration_projection_preserved':
      return {
        ...common,
        preupgrade_worker_list: 'not_executed_by_public_guide_audit',
        postupgrade_worker_list: 'not_executed_by_public_guide_audit',
        task_queue_projection: 'not_executed_by_public_guide_audit',
        polling_continuity: 'not_executed_by_public_guide_audit',
      };
    case 'waterline_operator_visibility_preserved':
      return {
        ...common,
        preupgrade_waterline_snapshot: 'not_executed_by_public_guide_audit',
        postupgrade_waterline_snapshot: 'not_executed_by_public_guide_audit',
        run_detail_visibility: signals.waterline_shows_both ? 'documented_but_not_executed' : 'not_executed_by_public_guide_audit',
        history_visibility: 'not_executed_by_public_guide_audit',
      };
    case 'cli_access_to_preupgrade_state':
      return {
        ...common,
        workflow_describe_json: 'not_executed_by_public_guide_audit',
        workflow_history_json: 'not_executed_by_public_guide_audit',
        schedule_list_json: 'not_executed_by_public_guide_audit',
        exit_codes: [],
      };
    case 'new_v2_workflow_start_after_upgrade':
      return {
        ...common,
        start_request: signals.new_v2_workflow_step ? 'documented_but_not_executed' : 'not_executed_by_public_guide_audit',
        run_id: 'not_executed_by_public_guide_audit',
        completion_result: 'not_executed_by_public_guide_audit',
        history_dumps: 'not_executed_by_public_guide_audit',
      };
    case 'rollback_contract_verified':
      return {
        ...common,
        rollback_steps: signals.rollback_procedure ? commands.filter((command) => /backup|restore|composer require|queue:restart|mysql|psql/i.test(command)) : [],
        rollback_supported_state: signals.rollback_procedure ? 'documented_but_not_executed' : 'not_documented_by_public_guide_audit',
        public_operator_signal: signals.rollback_procedure
          ? {
              source: guideRevision.url,
              status: 'documented_but_not_executed',
              observed_behavior: 'The guide audit found rollback instructions, but no operator-facing rollback refusal or support signal was exercised.',
            }
          : 'not_documented_by_public_guide_audit',
        postrollback_visibility: 'not_executed_by_public_guide_audit',
        postrollback_execution_result: 'not_executed_by_public_guide_audit',
      };
    case 'version_skew_refusal':
      return {
        ...common,
        skew_matrix: scenarioManifest?.required_matrix?.skew_cells ?? [],
        cli_skew_observations: skewObservationPlaceholders('client'),
        worker_skew_observations: skewObservationPlaceholders('worker'),
        refusal_errors: 'not_executed_by_public_guide_audit',
        operator_visible_reason: 'not_executed_by_public_guide_audit',
        request_response_evidence: skewRequestResponsePlaceholders(),
        no_partial_mutation_evidence: 'not_executed_by_public_guide_audit',
      };
    default:
      return common;
  }
}

function skewObservationPlaceholders(kind) {
  return Object.fromEntries(skewCellsFor(kind).map((cell) => [
    skewCellId(cell, kind),
    'not_executed_by_public_guide_audit',
  ]));
}

function skewRequestResponsePlaceholders() {
  return Object.fromEntries([
    ...skewCellsFor('client').map((cell) => [
      skewCellId(cell, 'client'),
      'not_executed_by_public_guide_audit',
    ]),
    ...skewCellsFor('worker').map((cell) => [
      skewCellId(cell, 'worker'),
      'not_executed_by_public_guide_audit',
    ]),
  ]);
}

function skewCellsFor(kind) {
  return arrayValue(scenarioManifest?.required_matrix?.skew_cells)
    .map((cell) => objectValue(cell))
    .filter((cell) => stringValue(cell.server) !== '' && stringValue(cell[kind]) !== '');
}

function skewCellId(cell, kind) {
  const subject = kind === 'worker'
    ? stringValue(cell[kind]).replace(/^workflow-php-/, 'worker-')
    : stringValue(cell[kind]);

  return `${subject}-to-${stringValue(cell.server)}`;
}

function publicGuideAuditScenarioReason(scenarioId, signals) {
  const prefix = 'The public migration guide was audited after storage-connection smoke passed, but the host runner did not execute';
  const suffix = 'against the current pinned published v1/v2 artifact tuple.';
  const guideContext = signals.finish_on_v1_strategy
    ? ' The guide documents a finish-on-v1 strategy, so those claims need live before/after proof rather than storage-routing smoke.'
    : ' The guide audit did not find a complete runtime upgrade strategy to validate.';

  return `${prefix} ${scenarioId} ${suffix}${guideContext}`;
}

function publicGuideSignals(text) {
  return {
    finish_on_v1_strategy: text.includes('finish-on-v1')
      || (text.includes('v1 workflows') && text.includes('v1 engine')),
    v1_tables_preserved: text.includes('v1 tables') && text.includes('preserved'),
    no_direct_data_migration: text.includes('avoids forcing a data migration')
      || text.includes('fundamentally different storage models'),
    v1_list_command: text.includes('workflow:v1:list'),
    waterline_shows_both: text.includes('waterline') && text.includes('both v1 and v2'),
    rollback_procedure: text.includes('rollback procedure') || text.includes('restore database backup'),
    new_v2_workflow_step: text.includes('start a test workflow') || text.includes('v2 workflows start'),
    worker_restart_step: text.includes('queue:restart') || text.includes('restart queue workers'),
  };
}

function migrationGuideCommandExecutability(commands) {
  const checkedCommands = arrayOfStrings(commands);
  const unexecutable = [];

  for (const command of checkedCommands) {
    const reasons = migrationGuideCommandExecutabilityReasons(command);
    if (reasons.length === 0) {
      continue;
    }

    unexecutable.push({
      command,
      reasons,
    });
  }

  if (unexecutable.length === 0) {
    return {
      status: 'pass',
      checked_commands: checkedCommands,
      unexecutable_commands: [],
      observed_behavior: 'The extracted public migration guide command stream contained no unresolved placeholders, interactive password prompts, or long-running monitor commands.',
    };
  }

  const sample = unexecutable
    .slice(0, 5)
    .map((entry) => `${entry.command} (${entry.reasons.join(', ')})`)
    .join('; ');

  return {
    status: 'fail',
    checked_commands: checkedCommands,
    unexecutable_commands: unexecutable,
    observed_behavior: `The live public migration guide includes commands that cannot be executed verbatim by an unattended published-artifact migration run: ${sample}.`,
    expected_behavior: 'Every command in the migration guide can be copied directly into the documented environment, or the guide clearly marks it as an example that must be adapted before execution.',
    next_acceptance_criterion: 'Update the public migration guide so the executable migration runbook has concrete commands for the selected database, worker supervisor, rollback, and monitoring phases, then rerun migration conformance from realistic v1 state.',
  };
}

function migrationGuideCommandExecutabilityReasons(command) {
  const value = stringValue(command);
  const reasons = [];

  if (/\b(?:your_database|your-worker-group)\b/i.test(value) || /<[^>\n]+>/.test(value)) {
    reasons.push('unresolved_placeholder');
  }

  if (/\bYYYYMMDD(?:-HHMMSS)?\b/.test(value) || /%%artifact\.|{{[^}]+}}/.test(value)) {
    reasons.push('unresolved_template_token');
  }

  if (/^(?:mysqldump|mysql)\b/i.test(value) && /(?:^|\s)-p(?:\s|$)/.test(value)) {
    reasons.push('interactive_password_prompt');
  }

  if (/^tail\s+-f\b/i.test(value)) {
    reasons.push('long_running_monitor_command');
  }

  return reasons;
}

function extractMigrationGuideCommands(value, fallbackText = '') {
  const raw = stringValue(value);
  const blockCommands = [
    ...extractCommandsFromBlocks(extractHtmlCodeBlockTexts(raw)),
    ...extractCommandsFromBlocks(extractMarkdownCodeBlockTexts(raw)),
  ];

  if (blockCommands.length > 0) {
    return uniqueStrings(blockCommands).slice(0, 50);
  }

  const fallback = stringValue(fallbackText) || htmlToText(raw);

  return uniqueStrings(extractCommandLines(fallback)).slice(0, 50);
}

function extractCommandsFromBlocks(blocks) {
  return blocks.flatMap((block) => extractCommandLines(block));
}

function extractHtmlCodeBlockTexts(value) {
  const blocks = [];
  const raw = stringValue(value);
  const prePattern = /<pre\b[^>]*>([\s\S]*?)<\/pre>/gi;
  let match = prePattern.exec(raw);

  while (match !== null) {
    const block = htmlCodeBlockToText(match[1]);
    if (block.trim() !== '') {
      blocks.push(block);
    }
    match = prePattern.exec(raw);
  }

  if (blocks.length > 0) {
    return blocks;
  }

  const codePattern = /<code\b(?=[^>]*class=["'][^"']*\blanguage-(?:bash|shell|sh|console|text)\b[^"']*["'])[^>]*>([\s\S]*?)<\/code>/gi;
  match = codePattern.exec(raw);
  while (match !== null) {
    const block = htmlCodeBlockToText(match[1]);
    if (block.trim() !== '') {
      blocks.push(block);
    }
    match = codePattern.exec(raw);
  }

  return blocks;
}

function extractMarkdownCodeBlockTexts(value) {
  const blocks = [];
  const fencePattern = /```[^\n]*\n([\s\S]*?)```/g;
  let match = fencePattern.exec(stringValue(value));

  while (match !== null) {
    const block = match[1].replace(/\r\n?/g, '\n');
    if (block.trim() !== '') {
      blocks.push(block);
    }
    match = fencePattern.exec(stringValue(value));
  }

  return blocks;
}

function extractCommandLines(text) {
  const commands = [];
  const lines = stringValue(text).replace(/\r\n?/g, '\n').split('\n');

  for (let index = 0; index < lines.length; index += 1) {
    let command = normalizeShellCommandLine(lines[index]);
    if (!isMigrationGuideCommand(command)) {
      continue;
    }

    while (/\\\s*$/.test(command) && index + 1 < lines.length) {
      index += 1;
      command = `${command}\n${normalizeShellCommandContinuationLine(lines[index])}`;
    }

    if (command !== '' && !commands.includes(command)) {
      commands.push(command);
    }
  }

  return commands;
}

function normalizeShellCommandLine(line) {
  return decodeHtmlEntities(stringValue(line))
    .replace(/^\s*(?:\$|#|>)\s*/, '')
    .trim();
}

function normalizeShellCommandContinuationLine(line) {
  return decodeHtmlEntitiesPreservingWhitespace(line)
    .replace(/^\s*(?:\$|#|>) ?/, '')
    .trimEnd();
}

function isMigrationGuideCommand(line) {
  const command = stringValue(line).trim();

  return /^(?:composer\s+(?:require|update|install|remove|config|dump-autoload)\b|php\s+artisan\s+\S+|mysqldump\b|pg_dump\b|mysql\s+\S|psql\s+\S|tail\s+\S|sudo\s+supervisorctl\s+\S|sudo\s+systemctl\s+\S)/i.test(command);
}

function normalizeGuideText(text) {
  return text.toLowerCase().replace(/\s+/g, ' ').trim();
}

function htmlToText(value) {
  return decodeHtmlEntities(stringValue(value)
    .replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, ' ')
    .replace(/<style\b[^>]*>[\s\S]*?<\/style>/gi, ' ')
    .replace(/<br\s*\/?>/gi, '\n')
    .replace(/<\/(?:p|div|section|article|header|footer|main|li|h[1-6]|pre|code|tr)>/gi, '\n')
    .replace(/<[^>]+>/g, ' ')
  );
}

function htmlCodeBlockToText(value) {
  const raw = stringValue(value);
  const tokenLines = extractHtmlTokenLineTexts(raw);
  if (tokenLines.length > 0) {
    return tokenLines.join('\n');
  }

  return decodeHtmlEntities(raw
    .replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, ' ')
    .replace(/<style\b[^>]*>[\s\S]*?<\/style>/gi, ' ')
    .replace(/<br\s*\/?>/gi, '\n')
    .replace(/<span\b(?=[^>]*class=["'][^"']*\btoken-line\b[^"']*["'])[^>]*>/gi, '\n')
    .replace(/<\/(?:div|p|li|pre|code)>/gi, '\n')
    .replace(/<[^>]+>/g, '')
  ).replace(/\r\n?/g, '\n');
}

function extractHtmlTokenLineTexts(value) {
  const lines = [];
  const raw = rawStringValue(value);
  const tokenLinePattern = /<span\b(?=[^>]*class=["'][^"']*\btoken-line\b[^"']*["'])[^>]*>([\s\S]*?)(?=<span\b(?=[^>]*class=["'][^"']*\btoken-line\b)|<\/code>|$)/gi;
  let match = tokenLinePattern.exec(raw);

  while (match !== null) {
    const line = htmlTokenLineToText(match[1]);
    if (line !== '') {
      lines.push(line);
    }
    match = tokenLinePattern.exec(raw);
  }

  return lines;
}

function htmlTokenLineToText(value) {
  const raw = rawStringValue(value).replace(/(^|\n)[\t ]+(?=<)/g, '$1');
  const text = htmlInlineCodeToText(raw).replace(/\r\n?/g, '\n');
  const fragments = text.split('\n').filter((line) => line.trim() !== '');

  if (fragments.length === 0) {
    return '';
  }

  return fragments
    .map((line, index) => (index === 0 ? line.trimEnd() : line.trim()))
    .join('')
    .trimEnd();
}

function htmlInlineCodeToText(value) {
  return decodeHtmlEntitiesPreservingWhitespace(rawStringValue(value)
    .replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, ' ')
    .replace(/<style\b[^>]*>[\s\S]*?<\/style>/gi, ' ')
    .replace(/<br\s*\/?>/gi, '')
    .replace(/<[^>]+>/g, '')
  );
}

function decodeHtmlEntities(value) {
  return stringValue(value)
    .replace(/&nbsp;/gi, ' ')
    .replace(/&amp;/gi, '&')
    .replace(/&lt;/gi, '<')
    .replace(/&gt;/gi, '>')
    .replace(/&#39;/g, "'")
    .replace(/&#x([0-9a-f]+);/gi, (_, code) => String.fromCodePoint(Number.parseInt(code, 16)))
    .replace(/&#([0-9]+);/g, (_, code) => String.fromCodePoint(Number.parseInt(code, 10)))
    .replace(/&quot;/gi, '"');
}

function decodeHtmlEntitiesPreservingWhitespace(value) {
  return rawStringValue(value)
    .replace(/&nbsp;/gi, ' ')
    .replace(/&amp;/gi, '&')
    .replace(/&lt;/gi, '<')
    .replace(/&gt;/gi, '>')
    .replace(/&#39;/g, "'")
    .replace(/&#x([0-9a-f]+);/gi, (_, code) => String.fromCodePoint(Number.parseInt(code, 16)))
    .replace(/&#([0-9]+);/g, (_, code) => String.fromCodePoint(Number.parseInt(code, 10)))
    .replace(/&quot;/gi, '"');
}

function sha256(value) {
  return crypto.createHash('sha256').update(value).digest('hex');
}

function storageSmokeProvidesProductEvidence(storageSmoke) {
  const smoke = objectValue(storageSmoke);
  const status = stringValue(smoke.status || smoke.outcome || smoke.result).toLowerCase();

  return truthy(smoke.passed)
    || truthy(smoke.pass)
    || truthy(smoke.success)
    || truthy(smoke.storage_connection_smoke_passed)
    || ['pass', 'passed', 'success', 'succeeded', 'ok'].includes(status);
}

function observedStorageSmokeStatus(storageSmoke) {
  const smoke = objectValue(storageSmoke);
  const status = stringValue(smoke.status || smoke.outcome || smoke.result);
  if (status !== '') {
    return status;
  }
  if (storageSmokeProvidesProductEvidence(storageSmoke)) {
    return 'pass';
  }
  return 'unknown';
}

function hasSuppliedFullMigrationEvidence(evidence) {
  const supplied = scenarioResultsById(evidence);
  for (const scenarioId of effectiveRequiredScenarios()) {
    if (scenarioId === 'published_artifact_install_only') {
      continue;
    }

    if (supplied[scenarioId]) {
      return true;
    }
  }

  return false;
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
    || containsFoundationCommandFailure(result)
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

    if (missingRequiredFieldsForScenario(scenarioId, scenarios[scenarioId], observedOutputs).length > 0) {
      return false;
    }
  }

  for (const field of REQUIRED_TOP_LEVEL_FIELDS) {
    if (isEmptyEvidence(result[field])) {
      return false;
    }
    if (
      runRecordCommandOutputsRequired(field)
      && isEmptyEvidence(runbookSectionCommandOutputs(result[field]))
    ) {
      return false;
    }
  }

  return artifactMapComplete(result.published_artifact_versions, false)
    && artifactMapComplete(result.resolved_artifact_versions, false)
    && artifactMapComplete(result.artifact_sources, true)
    && stateSnapshotsComplete(result);
}

function stateSnapshotsComplete(result) {
  return stateSnapshotFailuresFor(result).length === 0;
}

function stateSnapshotFailuresFor(result) {
  const failures = [];
  const requiredStateKinds = arrayOfStrings(scenarioManifest?.required_matrix?.state_kinds);
  if (requiredStateKinds.length === 0) {
    return failures;
  }

  for (const field of ['preupgrade_state_snapshot', 'postupgrade_state_snapshot']) {
    const snapshot = fieldValue(result, field);
    if (isEmptyEvidence(snapshot)) {
      continue;
    }
    const snapshotStatus = stringValue(snapshot.status || snapshot.outcome);
    if (snapshotStatus !== '' && normalizedStatus(snapshotStatus) !== 'pass') {
      failures.push({
        field,
        status: normalizedStatus(snapshotStatus),
        observed_status: snapshotStatus,
        code: 'non_pass_state_snapshot',
      });
    }

    const stateKinds = observedStateKindsForSnapshot(snapshot, requiredStateKinds);
    for (const stateKind of requiredStateKinds) {
      if (!stateKinds.has(stateKind)) {
        failures.push({
          field,
          state_kind: stateKind,
        });
      }
    }
  }

  return failures;
}

function observedStateKindsForSnapshot(snapshot, requiredStateKinds) {
  const required = new Set(requiredStateKinds);
  const observed = new Set();

  collectObservedStateEntries(snapshot, observed, required);

  for (const field of OBSERVED_STATE_ENTRY_FIELDS) {
    collectObservedStateEntries(fieldValue(snapshot, field), observed, required);
  }

  return observed;
}

function collectObservedStateEntries(value, observed, required) {
  if (!value || typeof value !== 'object') {
    return;
  }

  if (Array.isArray(value)) {
    for (const entry of value) {
      collectObservedStateEntryKind(entry, observed, required);
    }
    return;
  }

  for (const [key, entry] of Object.entries(objectValue(value))) {
    if (required.has(key) && hasObservedStateCellEvidence(entry)) {
      observed.add(key);
    }

    collectObservedStateEntryKind(entry, observed, required);
  }
}

function collectObservedStateEntryKind(entry, observed, required) {
  if (!entry || typeof entry !== 'object' || Array.isArray(entry)) {
    return;
  }

  for (const field of STATE_ENTRY_KIND_FIELDS) {
    const fieldKind = stateKindString(entry[field]);
    if (fieldKind !== '' && required.has(fieldKind) && hasObservedStateCellEvidence(entry)) {
      observed.add(fieldKind);
    }
  }
}

function hasObservedStateCellEvidence(value) {
  if (isEmptyEvidence(value)) {
    return false;
  }

  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return true;
  }

  for (const [key, entry] of Object.entries(value)) {
    if (STATE_CELL_METADATA_FIELDS.includes(key)) {
      continue;
    }

    if (!isEmptyEvidence(entry)) {
      return true;
    }
  }

  return false;
}

function stateKindString(value) {
  return typeof value === 'string' || typeof value === 'number'
    ? String(value).trim()
    : '';
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
      if (runRecordCommandOutputsRequired(field) && isEmptyEvidence(runbookSectionCommandOutputs(fieldValue(result, field)))) {
        findings.push(coverageGapFinding('run_record', artifactVersions, {
          observed_behavior: `No concrete command-output evidence was supplied for migration run record ${field}.`,
          expected_behavior: 'Passing migration conformance records queryable command, request, output, status, exit-code, response, or timing evidence for every migration runbook section required by the public scenario manifest.',
          next_acceptance_criterion: `attach concrete command_outputs entries to ${field} before recording migration conformance as passing`,
          missing_run_record_field: field,
          missing_run_record_command_outputs: field,
        }));
      }
      continue;
    }

    findings.push(coverageGapFinding('run_record', artifactVersions, {
      observed_behavior: `No non-placeholder migration run record evidence was supplied for ${field}.`,
      expected_behavior: 'Passing migration conformance records every top-level migration plan, before/after state, operator observation, rollback/skew observation, and storage smoke section required by the public scenario manifest.',
      next_acceptance_criterion: `attach non-placeholder ${field} evidence before recording migration conformance as passing`,
      missing_run_record_field: field,
    }));
  }

  for (const failure of stateSnapshotFailuresFor(result)) {
    if (failure.code === 'non_pass_state_snapshot') {
      findings.push(coverageGapFinding('run_record', artifactVersions, {
        observed_behavior: `Migration run record ${failure.field} reported ${failure.observed_status || failure.status} instead of pass.`,
        expected_behavior: 'Passing migration conformance records successful before/after state snapshots with observed state cells for every required migration state kind.',
        next_acceptance_criterion: `rerun migration conformance until ${failure.field} reports pass and includes observed state evidence`,
        missing_run_record_field: failure.field,
        state_snapshot_status: failure.status,
        state_snapshot_failure: failure.code,
      }));
      continue;
    }

    findings.push(coverageGapFinding('run_record', artifactVersions, {
      observed_behavior: `Migration run record ${failure.field} did not include observed state evidence for ${failure.state_kind}.`,
      expected_behavior: 'Passing migration conformance records observed before/after state cells for every required migration state kind, not just the expected state-kind list.',
      next_acceptance_criterion: `attach observed ${failure.state_kind} evidence to ${failure.field} before recording migration conformance as passing`,
      missing_run_record_field: failure.field,
      missing_state_kind: failure.state_kind,
    }));
  }

  return findings;
}

function runRecordCommandOutputsRequired(field) {
  return REQUIRED_RUNBOOK_COMMAND_OUTPUT_FIELDS.includes(field);
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
  const resultPath = path.join(resultDir, 'migration-conformance-result.json');
  const artifactPath = path.join(resultDir, 'migration-published-artifacts.json');
  writeJson('migration-conformance-result.json', result);
  writeJson('migration-conformance-record.json', {
    schema: RECORD_SCHEMA,
    version: 1,
    experiment: 'migration',
    generated_at: result.generated_at,
    started_at: result.started_at,
    finished_at: result.finished_at,
    outcome: result.outcome,
    runner_blocked: result.runner_blocked,
    runnerBlocked: result.runner_blocked === true,
    artifact_versions: result.artifact_versions,
    artifactVersions: result.artifact_versions,
    published_artifact_versions: result.published_artifact_versions,
    publishedArtifactVersions: result.published_artifact_versions,
    resolved_artifact_versions: result.resolved_artifact_versions,
    resolvedArtifactVersions: result.resolved_artifact_versions,
    artifact_sources: result.artifact_sources,
    artifactSources: result.artifact_sources,
    public_artifact_resolution: result.public_artifact_resolution ?? {},
    publicArtifactResolution: result.public_artifact_resolution ?? {},
    artifact_prerequisite_failures: result.artifact_prerequisite_failures,
    artifactPrerequisiteFailures: result.artifact_prerequisite_failures,
    local_product_source_checkouts_used: result.local_product_source_checkouts_used,
    localProductSourceCheckoutsUsed: result.local_product_source_checkouts_used === true,
    resultPath,
    artifactPath,
    result_file: 'migration-conformance-result.json',
    artifact_file: 'migration-published-artifacts.json',
    required_scenarios: effectiveRequiredScenarios(),
    reported_scenarios: Object.keys(scenarioResults),
    scenario_statuses: Object.fromEntries(
      Object.entries(scenarioResults).map(([scenarioId, scenario]) => [scenarioId, scenario?.status ?? null]),
    ),
    scenarioStatuses: Object.fromEntries(
      Object.entries(scenarioResults).map(([scenarioId, scenario]) => [scenarioId, scenario?.status ?? null]),
    ),
    non_pass_scenarios: Object.entries(scenarioResults)
      .filter(([, scenario]) => scenario?.status !== 'pass')
      .map(([scenarioId]) => scenarioId),
    nonPassScenarios: Object.entries(scenarioResults)
      .filter(([, scenario]) => scenario?.status !== 'pass')
      .map(([scenarioId]) => scenarioId),
    finding_links: result.finding_links,
    findingLinks: result.finding_links,
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
    runbook_command_outputs: result.runbook_command_outputs ?? runbookCommandOutputRecord(result),
    runbookCommandOutputs: result.runbook_command_outputs ?? runbookCommandOutputRecord(result),
  });
}

function runbookCommandOutputRecord(result) {
  return Object.fromEntries(
    REQUIRED_RUNBOOK_COMMAND_OUTPUT_FIELDS
      .map((field) => [field, runbookSectionCommandOutputs(fieldValue(result, field))])
      .filter(([, commandOutputs]) => !isEmptyEvidence(commandOutputs)),
  );
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

  return normalizeMigrationEvidenceShape(mergeEvidenceObjects(...inputs));
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

function normalizeMigrationEvidenceShape(evidence) {
  const merged = {};

  for (const candidate of unwrappedEvidenceCandidates(evidence)) {
    mergeEvidenceInto(merged, candidate);
  }

  mergeEvidenceInto(merged, runbookEvidenceFrom(merged));

  return merged;
}

function unwrappedEvidenceCandidates(evidence) {
  const root = objectValue(evidence);
  const candidates = [root];

  for (const field of [
    'result',
    'output',
    'evidence',
    'record',
    'run_record',
    'runRecord',
    'migration_run_record',
    'migrationRunRecord',
    'migration_conformance_result',
    'migrationConformanceResult',
    'migration_conformance_record',
    'migrationConformanceRecord',
    'runbook',
    'runbookEvidence',
    'runbook_evidence',
    'migration_runbook',
    'migrationRunbook',
    'runbook_sections',
    'runbookSections',
  ]) {
    const value = objectValue(root[field]);
    if (Object.keys(value).length > 0) {
      candidates.push(value);
    }
  }

  return candidates;
}

function runbookEvidenceFrom(evidence) {
  const runbook = {};
  const commandOutputSections = runbookCommandOutputSections(evidence);
  const pinnedVersions = firstNonEmptyObject(evidence, [
    'pinned_versions',
    'pinnedVersions',
    'pinned_artifact_versions',
    'pinnedArtifactVersions',
    'artifact_tuple',
    'artifactTuple',
  ]);
  const sourceArtifacts = firstNonEmptyObject(evidence, [
    'v1_source_artifacts',
    'v1SourceArtifacts',
    'source_artifacts',
    'sourceArtifacts',
  ]);
  const targetArtifacts = firstNonEmptyObject(evidence, [
    'current_v2_tuple',
    'currentV2Tuple',
    'v2_target_artifacts',
    'v2TargetArtifacts',
    'target_artifacts',
    'targetArtifacts',
  ]);
  const installSources = firstNonEmptyObject(evidence, [
    'artifact_sources',
    'artifactSources',
    'install_sources',
    'installSources',
    'artifact_source_record',
    'artifactSourceRecord',
  ]);
  const artifactVersions = artifactVersionsFromRunbook(
    pinnedVersions,
    sourceArtifacts,
    targetArtifacts,
  );

  if (Object.keys(artifactVersions).length > 0) {
    runbook.published_artifact_versions = artifactVersions;
    runbook.resolved_artifact_versions = artifactVersions;
  }
  if (Object.keys(installSources).length > 0) {
    runbook.artifact_sources = installSources;
  }

  for (const [field, aliases] of Object.entries(RUNBOOK_SECTION_ALIASES)) {
    setRunbookSection(runbook, evidence, field, aliases);
  }

  mergeRunbookCommandOutputSections(runbook, commandOutputSections);
  mergeScenarioRunbookSections(runbook, evidence);

  const scenarios = runbookScenarioResultsFrom({
    ...evidence,
    ...runbook,
  });
  if (Object.keys(scenarios).length > 0) {
    runbook.scenario_results = scenarios;
  }

  return runbook;
}

function setRunbookSection(target, evidence, field, aliases) {
  const value = firstNonEmptyObject(evidence, aliases);
  if (Object.keys(value).length > 0) {
    target[field] = value;
  }
}

function runbookCommandOutputSections(evidence) {
  const sections = {};

  for (const field of RUNBOOK_COMMAND_OUTPUT_EVIDENCE_FIELDS) {
    collectRunbookCommandOutputSections(fieldValue(evidence, field), sections);
  }

  for (const field of RUNBOOK_SECTION_CONTAINER_FIELDS) {
    collectRunbookCommandOutputSections(fieldValue(evidence, field), sections);
  }

  return sections;
}

function collectRunbookCommandOutputSections(value, sections) {
  if (!value || typeof value !== 'object' || isEmptyEvidence(value)) {
    return;
  }

  if (Array.isArray(value)) {
    for (const entry of value) {
      collectRunbookCommandOutputEntry(entry, sections);
    }
    return;
  }

  const object = objectValue(value);
  const directSection = runbookSectionFieldFor(commandOutputSectionValue(object));
  if (directSection !== '') {
    mergeCommandOutputsIntoSection(
      sections,
      directSection,
      runbookSectionCommandOutputs(object) ?? object,
    );
    return;
  }

  for (const [key, entry] of Object.entries(object)) {
    const section = runbookSectionFieldFor(key);
    if (section !== '') {
      mergeCommandOutputsIntoSection(sections, section, entry);
      continue;
    }

    if (RUNBOOK_COMMAND_OUTPUT_EVIDENCE_FIELDS.includes(key) || RUNBOOK_SECTION_CONTAINER_FIELDS.includes(key)) {
      collectRunbookCommandOutputSections(entry, sections);
    }
  }
}

function collectRunbookCommandOutputEntry(entry, sections) {
  if (!entry || typeof entry !== 'object' || Array.isArray(entry)) {
    return;
  }

  const object = objectValue(entry);
  const section = runbookSectionFieldFor(commandOutputSectionValue(object));
  if (section === '') {
    collectRunbookCommandOutputSections(object, sections);
    return;
  }

  mergeCommandOutputsIntoSection(
    sections,
    section,
    runbookSectionCommandOutputs(object) ?? object,
  );
}

function commandOutputSectionValue(entry) {
  for (const field of [
    'runbook_section',
    'runbookSection',
    'section',
    'section_id',
    'sectionId',
    'scenario_id',
    'scenarioId',
    'scenario',
    'kind',
  ]) {
    const value = stringValue(entry[field]);
    if (value !== '') {
      return value;
    }
  }

  return '';
}

function mergeCommandOutputsIntoSection(sections, field, value) {
  const concrete = concreteCommandOutputCollection(runbookSectionCommandOutputs(value) ?? value);
  if (isEmptyEvidence(concrete)) {
    return;
  }

  const existing = sections[field];
  sections[field] = mergeCommandOutputCollections(existing, concrete);
}

function mergeRunbookCommandOutputSections(runbook, sections) {
  for (const [field, commandOutputs] of Object.entries(sections)) {
    const concrete = concreteCommandOutputCollection(commandOutputs);
    if (isEmptyEvidence(concrete)) {
      continue;
    }

    const current = nonEmptyObject(fieldValue(runbook, field)) ?? {};
    const existingOutputs = runbookSectionCommandOutputs(current);
    const mergedOutputs = mergeCommandOutputCollections(existingOutputs, concrete);
    const merged = withRunbookCommandOutputs({
      ...current,
      command_outputs: mergedOutputs,
    });
    const currentStatus = stringValue(current.status ?? current.outcome).toLowerCase();
    if (['', 'not_covered', 'runner_blocked'].includes(currentStatus)) {
      merged.status = commandOutputCollectionStatus(mergedOutputs);
    }

    if (['preupgrade_state_snapshot', 'postupgrade_state_snapshot'].includes(field)) {
      const observedStates = observedStateEntriesFromCommandOutputs(mergedOutputs);
      if (!isEmptyEvidence(observedStates) && isEmptyEvidence(fieldValue(merged, 'observed_states'))) {
        merged.observed_states = observedStates;
      }

      const stateKinds = uniqueStrings(observedStates
        .map((entry) => stateKindString(entry.state_kind ?? entry.stateKind ?? entry.kind))
        .filter(Boolean));
      if (stateKinds.length > 0 && isEmptyEvidence(fieldValue(merged, 'state_kinds'))) {
        merged.state_kinds = stateKinds;
      }
    }

    runbook[field] = merged;
  }
}

function mergeScenarioRunbookSections(runbook, evidence) {
  for (const [scenarioId, scenario] of Object.entries(scenarioResultsById(evidence))) {
    const scenarioObject = objectValue(scenario);
    const observedOutputs = nonEmptyObject(scenarioObject.observed_outputs)
      ?? nonEmptyObject(scenarioObject.observedOutputs)
      ?? nonEmptyObject(scenarioObject.evidence)
      ?? {};
    const commandOutputs = runbookSectionCommandOutputs({
      ...scenarioObject,
      observed_outputs: observedOutputs,
    });

    if (isEmptyEvidence(commandOutputs)) {
      continue;
    }

    for (const field of scenarioRunbookSectionFields(scenarioId)) {
      const observation = scenarioRunbookObservation(
        field,
        scenarioId,
        scenarioObject,
        observedOutputs,
        commandOutputs,
      );
      if (observation !== null) {
        mergeRunbookObservationIntoSection(runbook, field, observation);
      }
    }
  }
}

function scenarioRunbookSectionFields(scenarioId) {
  const direct = runbookSectionFieldFor(scenarioId);
  return uniqueStrings([
    ...arrayOfStrings(SCENARIO_RUNBOOK_SECTION_FIELDS[scenarioId]),
    direct,
  ]);
}

function scenarioRunbookObservation(field, scenarioId, scenario, observedOutputs, commandOutputs) {
  const concrete = concreteCommandOutputCollection(commandOutputs);
  if (isEmptyEvidence(concrete)) {
    return null;
  }

  const status = normalizedStatus(scenario.status || scenario.outcome || observedOutputs.status || observedOutputs.outcome || 'pass');
  const observation = {
    ...withoutDirectCommandOutputFields(observedOutputs),
    kind: field,
    source: `scenario_result.${scenarioId}`,
    scenario_id: scenarioId,
    status,
    command_outputs: concrete,
  };

  if (['preupgrade_state_snapshot', 'postupgrade_state_snapshot'].includes(field)) {
    const phase = field === 'preupgrade_state_snapshot' ? 'preupgrade' : 'postupgrade';
    const observedStates = observedStateEntriesForScenario(scenarioId, observedOutputs, concrete, phase);
    if (!isEmptyEvidence(observedStates)) {
      observation.observed_states = observedStates;
      observation.state_kinds = uniqueStrings(observedStates
        .map((entry) => stateKindString(objectValue(entry).state_kind ?? objectValue(entry).stateKind ?? objectValue(entry).kind))
        .filter(Boolean));
    }
  }

  return withRunbookCommandOutputs(observation);
}

function mergeRunbookObservationIntoSection(runbook, field, observation) {
  const current = nonEmptyObject(fieldValue(runbook, field)) ?? {};
  const currentOutputs = runbookSectionCommandOutputs(current);
  const incomingOutputs = runbookSectionCommandOutputs(observation);
  const mergedOutputs = mergeCommandOutputCollections(currentOutputs, incomingOutputs);
  const currentWithoutOutputs = withoutDirectCommandOutputFields(current);
  const incomingWithoutOutputs = withoutDirectCommandOutputFields(observation);
  const merged = withRunbookCommandOutputs(mergeEvidenceValue(currentWithoutOutputs, {
    ...incomingWithoutOutputs,
    command_outputs: mergedOutputs,
  }));
  const currentStatus = normalizedStatus(current.status || current.outcome);
  const incomingStatus = normalizedStatus(observation.status || observation.outcome);
  const outputStatus = isEmptyEvidence(mergedOutputs) ? '' : commandOutputCollectionStatus(mergedOutputs);

  if (currentStatus === 'pass') {
    merged.status = 'pass';
  } else if (incomingStatus === 'pass' && !containsFoundationCommandFailure(observation)) {
    merged.status = 'pass';
  } else if (outputStatus === 'fail' || incomingStatus === 'fail') {
    merged.status = 'fail';
  } else if (incomingStatus === 'pass' && outputStatus === 'pass') {
    merged.status = 'pass';
  } else if (currentStatus !== '' && currentStatus !== 'not_covered') {
    merged.status = currentStatus;
  } else {
    merged.status = incomingStatus || outputStatus || currentStatus || 'not_covered';
  }

  runbook[field] = merged;
}

function observedStateEntriesForScenario(scenarioId, observedOutputs, commandOutputs, phase) {
  const entries = [
    ...observedStateEntriesFromCommandOutputs(commandOutputs),
    ...derivedStateEntriesFromScenarioOutputs(scenarioId, observedOutputs, phase),
  ];

  return entries.length > 0 ? entries : undefined;
}

function derivedStateEntriesFromScenarioOutputs(scenarioId, observedOutputs, phase) {
  switch (scenarioId) {
    case 'latest_supported_v1_state_setup':
      return [
        ...stateEntriesFromScenarioField(
          nestedScenarioEvidence(observedOutputs, 'seeded_workflows', 'completed_workflow'),
          'completed_history',
          phase,
          'seeded_workflows.completed_workflow',
        ),
        ...stateEntriesFromScenarioField(
          nestedScenarioEvidence(observedOutputs, 'seeded_workflows', 'running_workflow_waiting_on_signal'),
          'in_flight_workflow',
          phase,
          'seeded_workflows.running_workflow_waiting_on_signal',
        ),
        ...stateEntriesFromScenarioField(
          nestedScenarioEvidence(observedOutputs, 'seeded_workflows', 'workflow_mid_activity_retry'),
          'retrying_activity',
          phase,
          'seeded_workflows.workflow_mid_activity_retry',
        ),
        ...stateEntriesFromScenarioField(
          nestedScenarioEvidence(observedOutputs, 'seeded_schedules', 'active_schedule'),
          'schedule',
          phase,
          'seeded_schedules.active_schedule',
        ),
        ...stateEntriesFromScenarioField(
          nestedScenarioEvidence(observedOutputs, 'seeded_worker_registrations', 'registered_workers'),
          'worker_registration',
          phase,
          'seeded_worker_registrations.registered_workers',
        ),
      ];
    case 'completed_history_preservation_and_replay':
      return stateEntriesFromScenarioField(
        firstNonEmptyScenarioEvidence(observedOutputs, [
          'postupgrade_history_export',
          'replay_result',
          'query_result',
        ]),
        'completed_history',
        phase,
        'completed_history_preservation_and_replay',
      );
    case 'in_flight_workflow_progress_preserved':
      return stateEntriesFromScenarioField(
        firstNonEmptyScenarioEvidence(observedOutputs, [
          'postupgrade_progress_marker',
          'completion_result',
        ]),
        'in_flight_workflow',
        phase,
        'in_flight_workflow_progress_preserved',
      );
    case 'mid_activity_retry_preserved':
      return stateEntriesFromScenarioField(
        firstNonEmptyScenarioEvidence(observedOutputs, [
          'postupgrade_activity_attempt',
          'final_activity_result',
          'retry_policy',
        ]),
        'retrying_activity',
        phase,
        'mid_activity_retry_preserved',
      );
    case 'schedule_cross_upgrade_cadence_preserved':
      return stateEntriesFromScenarioField(
        firstNonEmptyScenarioEvidence(observedOutputs, [
          'first_tick_after_upgrade',
          'missed_or_duplicate_ticks',
          'preupgrade_schedule_spec',
        ]),
        'schedule',
        phase,
        'schedule_cross_upgrade_cadence_preserved',
      );
    case 'worker_registration_projection_preserved':
      return stateEntriesFromScenarioField(
        firstNonEmptyScenarioEvidence(observedOutputs, [
          'postupgrade_worker_list',
          'task_queue_projection',
          'polling_continuity',
        ]),
        'worker_registration',
        phase,
        'worker_registration_projection_preserved',
      );
    default:
      return [];
  }
}

function nestedScenarioEvidence(container, field, childField) {
  return fieldValue(objectValue(fieldValue(container, field)), childField);
}

function firstNonEmptyScenarioEvidence(container, fields) {
  for (const field of fields) {
    const value = fieldValue(container, field);
    if (!isEmptyEvidence(value)) {
      return value;
    }
  }

  return undefined;
}

function stateEntriesFromScenarioField(value, stateKind, phase, sourceField) {
  if (isEmptyEvidence(value)) {
    return [];
  }

  if (Array.isArray(value)) {
    return value
      .filter((entry) => !isEmptyEvidence(entry))
      .map((entry, index) => stateEntryFromScenarioEvidence(entry, stateKind, phase, `${sourceField}.${index + 1}`));
  }

  if (value && typeof value === 'object') {
    const object = objectValue(value);
    if (Object.keys(object).length > 0 && Object.values(object).every((entry) => entry && typeof entry === 'object')) {
      return Object.entries(object)
        .filter(([, entry]) => !isEmptyEvidence(entry))
        .map(([key, entry]) => stateEntryFromScenarioEvidence(entry, stateKind, phase, `${sourceField}.${key}`));
    }
  }

  return [stateEntryFromScenarioEvidence(value, stateKind, phase, sourceField)];
}

function stateEntryFromScenarioEvidence(value, stateKind, phase, sourceField) {
  return {
    state_kind: stateKind,
    phase,
    source_field: sourceField,
    evidence: value,
  };
}

function commandOutputCollectionStatus(commandOutputs) {
  const entries = commandOutputEntries(commandOutputs).map(([, entry]) => objectValue(entry));
  if (entries.length === 0) {
    return 'not_covered';
  }

  return entries.some((entry) => commandOutputEntryFailed(entry)) ? 'fail' : 'pass';
}

function commandOutputEntryFailed(entry) {
  const status = stringValue(entry.status).toLowerCase();
  if (['fail', 'failed', 'error', 'timeout', 'timed_out'].includes(status)) {
    return true;
  }

  const exitCode = commandOutputExitCode(entry);
  if (exitCode !== undefined && exitCode !== 0) {
    return true;
  }

  if (truthy(entry.timed_out ?? entry.timedOut) || stringValue(entry.signal) !== '') {
    return true;
  }

  return false;
}

function mergeCommandOutputCollections(...collections) {
  const entries = [];
  for (const collection of collections) {
    entries.push(...commandOutputEntries(collection).map(([, entry]) => entry));
  }

  return entries.length > 0 ? entries : undefined;
}

function observedStateEntriesFromCommandOutputs(commandOutputs) {
  return commandOutputEntries(commandOutputs)
    .map(([, entry]) => objectValue(entry))
    .filter((entry) => stateKindString(entry.state_kind ?? entry.stateKind ?? entry.kind) !== '');
}

function runbookSectionFieldFor(value) {
  const token = evidenceKeyToken(value);
  if (token === '') {
    return '';
  }

  for (const [field, aliases] of Object.entries(RUNBOOK_SECTION_ALIASES)) {
    if (token === evidenceKeyToken(field) || aliases.some((alias) => token === evidenceKeyToken(alias))) {
      return field;
    }
  }

  return '';
}

function evidenceKeyToken(value) {
  return stringValue(value).toLowerCase().replace(/[^a-z0-9]/g, '');
}

function runbookScenarioResultsFrom(evidence) {
  const scenarios = {};
  const scenarioSources = {
    latest_supported_v1_state_setup: firstNonEmptyObject(evidence, [
      'realistic_v1_state_snapshot',
      'realisticV1StateSnapshot',
      'v1_state_snapshot',
      'v1StateSnapshot',
      'preupgrade_state_snapshot',
      'preupgradeStateSnapshot',
      'pre_upgrade_state_snapshot',
      'preUpgradeStateSnapshot',
    ]),
    documented_migration_steps_execute: firstNonEmptyObject(evidence, [
      'migration_guide_execution',
      'migrationGuideExecution',
      'guide_execution',
      'guideExecution',
      'step_by_step_guide',
      'stepByStepGuide',
      'migration_plan',
      'migrationPlan',
    ]),
    completed_history_preservation_and_replay: firstNonEmptyObject(evidence, [
      'completed_history_preservation_and_replay',
      'completedHistoryPreservationAndReplay',
      'completed_history_replay',
      'completedHistoryReplay',
      'history_dumps',
      'historyDumps',
    ]),
    in_flight_workflow_progress_preserved: firstNonEmptyObject(evidence, [
      'in_flight_workflow_progress_preserved',
      'inFlightWorkflowProgressPreserved',
      'in_flight_workflow_progress',
      'inFlightWorkflowProgress',
      'postupgrade_state_snapshot',
      'postupgradeStateSnapshot',
      'post_upgrade_verification',
      'postUpgradeVerification',
    ]),
    mid_activity_retry_preserved: firstNonEmptyObject(evidence, [
      'mid_activity_retry_preserved',
      'midActivityRetryPreserved',
      'activity_attempts',
      'activityAttempts',
      'edge_case_results',
      'edgeCaseResults',
    ]),
    schedule_cross_upgrade_cadence_preserved: firstNonEmptyObject(evidence, [
      'schedule_cross_upgrade_cadence_preserved',
      'scheduleCrossUpgradeCadencePreserved',
      'schedule_ticks',
      'scheduleTicks',
      'edge_case_results',
      'edgeCaseResults',
    ]),
    worker_registration_projection_preserved: firstNonEmptyObject(evidence, [
      'worker_registration_projection_preserved',
      'workerRegistrationProjectionPreserved',
      'worker_registration_observations',
      'workerRegistrationObservations',
      'worker_registrations',
      'workerRegistrations',
    ]),
    waterline_operator_visibility_preserved: firstNonEmptyObject(evidence, [
      'waterline_operator_visibility_preserved',
      'waterlineOperatorVisibilityPreserved',
      'waterline_observations',
      'waterlineObservations',
      'operator_projections',
      'operatorProjections',
    ]),
    cli_access_to_preupgrade_state: firstNonEmptyObject(evidence, [
      'cli_access_to_preupgrade_state',
      'cliAccessToPreupgradeState',
      'cli_observations',
      'cliObservations',
    ]),
    new_v2_workflow_start_after_upgrade: firstNonEmptyObject(evidence, [
      'new_v2_workflow_start_after_upgrade',
      'newV2WorkflowStartAfterUpgrade',
      'new_v2_workflow',
      'newV2Workflow',
      'post_upgrade_verification',
      'postUpgradeVerification',
    ]),
    rollback_contract_verified: firstNonEmptyObject(evidence, [
      'rollback_contract_verified',
      'rollbackContractVerified',
      'rollback_result',
      'rollbackResult',
      'rollback_observations',
      'rollbackObservations',
    ]),
    version_skew_refusal: firstNonEmptyObject(evidence, [
      'version_skew_refusal',
      'versionSkewRefusal',
      'version_skew_observations',
      'versionSkewObservations',
      'skew_observations',
      'skewObservations',
    ]),
  };

  for (const [scenarioId, source] of Object.entries(scenarioSources)) {
    const observedOutputs = observedOutputsForRunbookScenario(scenarioId, source);
    if (Object.keys(observedOutputs).length === 0) {
      continue;
    }

    scenarios[scenarioId] = {
      scenario_id: scenarioId,
      status: normalizedStatus(source.status || source.outcome || 'pass'),
      observed_outputs: observedOutputs,
    };
  }

  return scenarios;
}

function observedOutputsForRunbookScenario(scenarioId, source) {
  const fields = requiredFieldsFor(scenarioId);
  const observed = {};

  for (const field of fields) {
    const value = runbookFieldValue(source, field);
    if (!isEmptyEvidence(value)) {
      observed[field] = value;
    }
  }

  const sectionCommandOutputs = runbookSectionCommandOutputs(source);
  if (!isEmptyEvidence(sectionCommandOutputs)) {
    observed.command_outputs ??= sectionCommandOutputs;
  }

  if (scenarioId === 'documented_migration_steps_execute') {
    const commandOutputs = sectionCommandOutputs;

    if (isEmptyEvidence(observed.commands_executed)) {
      observed.commands_executed = commandsExecutedFromCommandOutputs(commandOutputs);
    }
    if (isEmptyEvidence(observed.commands_executed)) {
      observed.commands_executed = runbookFieldValue(source, 'commands');
    }

    observed.exit_codes ??= runbookFieldValue(source, 'exit_codes_by_command');
    if (isEmptyEvidence(observed.exit_codes)) {
      observed.exit_codes = exitCodesFromCommandOutputs(commandOutputs);
    }

    observed.command_timings ??= runbookFieldValue(source, 'timings');
    if (isEmptyEvidence(observed.command_timings)) {
      observed.command_timings = commandTimingsFromCommandOutputs(commandOutputs);
    }

    observed.schema_or_storage_migration_output ??= runbookFieldValue(source, 'migration_output');
    if (isEmptyEvidence(observed.schema_or_storage_migration_output) && !isEmptyEvidence(commandOutputs)) {
      observed.schema_or_storage_migration_output = {
        source: 'migration_plan.command_outputs',
        command_outputs: commandOutputs,
      };
    }
  }

  return Object.fromEntries(
    Object.entries(observed).filter(([, value]) => !isEmptyEvidence(value)),
  );
}

function runbookSectionCommandOutputs(source) {
  const direct = runbookDirectCommandOutputs(source);
  if (!isEmptyEvidence(direct)) {
    return direct;
  }

  const synthesized = commandOutputsFromExecutedCommands(source);
  if (!isEmptyEvidence(synthesized)) {
    return synthesized;
  }

  const nested = nestedCommandOutputCollection(source);
  if (!isEmptyEvidence(nested)) {
    return nested;
  }

  return undefined;
}

function runbookMigrationCommandOutputs(source) {
  return runbookSectionCommandOutputs(source);
}

function runbookDirectCommandOutputs(source) {
  const direct = runbookFirstNonEmptyField(source, COMMAND_OUTPUT_COLLECTION_FIELDS);
  const concreteDirect = concreteCommandOutputCollection(direct);
  if (!isEmptyEvidence(concreteDirect)) {
    return concreteDirect;
  }

  const commands = runbookFieldValue(source, 'commands');
  const concreteCommands = concreteCommandOutputCollection(commands);
  if (!isEmptyEvidence(concreteCommands)) {
    return concreteCommands;
  }

  return undefined;
}

function commandOutputsFromExecutedCommands(source) {
  const commands = arrayOfStrings(
    runbookFieldValue(source, 'commands_executed')
      ?? runbookFieldValue(source, 'commands')
      ?? runbookFieldValue(source, 'steps'),
  );
  if (commands.length === 0) {
    return undefined;
  }

  const exitCodes = arrayValue(runbookFieldValue(source, 'exit_codes'));
  const timings = objectValue(
    runbookFieldValue(source, 'command_timings')
      ?? runbookFieldValue(source, 'timings'),
  );
  const sectionStatus = normalizedStatus(source.status || source.outcome);
  const entries = commands.map((command, index) => {
    const rawExitCode = exitCodes[index];
    const exitCode = typeof rawExitCode === 'number' || typeof rawExitCode === 'string'
      ? Number.parseInt(String(rawExitCode), 10)
      : undefined;
    const timing = commandOutputTiming({ duration_ms: timings[command] });
    const entry = {
      command,
    };

    if (Number.isFinite(exitCode)) {
      entry.exit_code = exitCode;
      entry.status = exitCode === 0 ? 'pass' : 'fail';
    } else if (sectionStatus !== '') {
      entry.status = sectionStatus;
    }

    if (timing !== undefined) {
      entry.duration_ms = timing;
    }

    return entry;
  }).filter((entry) => hasConcreteCommandOutput(entry));

  return entries.length > 0 ? entries : undefined;
}

function nestedCommandOutputCollection(source) {
  const outputs = [];
  collectNestedCommandOutputs(source, outputs, new Set());

  return outputs.length > 0 ? outputs : undefined;
}

function collectNestedCommandOutputs(value, outputs, seen) {
  if (isEmptyEvidence(value) || value === null || typeof value !== 'object') {
    return;
  }

  if (seen.has(value)) {
    return;
  }
  seen.add(value);

  if (Array.isArray(value)) {
    for (const entry of value) {
      collectNestedCommandOutputs(entry, outputs, seen);
    }
    return;
  }

  if (hasConcreteCommandOutput(value)) {
    outputs.push(value);
  }

  for (const [key, entry] of Object.entries(value)) {
    if (COMMAND_OUTPUT_COLLECTION_FIELDS.includes(key)) {
      const direct = concreteCommandOutputCollection(entry);
      if (Array.isArray(direct)) {
        outputs.push(...direct);
      } else if (direct && typeof direct === 'object') {
        outputs.push(...Object.values(direct));
      }
      continue;
    }

    collectNestedCommandOutputs(entry, outputs, seen);
  }
}

function withoutDirectCommandOutputFields(value) {
  return Object.fromEntries(
    Object.entries(objectValue(value))
      .filter(([key]) => !COMMAND_OUTPUT_COLLECTION_FIELDS.includes(key)),
  );
}

function concreteCommandOutputCollection(commandOutputs) {
  const entries = commandOutputEntries(commandOutputs);
  if (entries.length === 0) {
    return undefined;
  }

  if (Array.isArray(commandOutputs)) {
    return entries.map(([, entry]) => entry);
  }

  return Object.fromEntries(entries);
}

function commandsExecutedFromCommandOutputs(commandOutputs) {
  const commands = commandOutputEntries(commandOutputs)
    .map(([key, entry]) => commandOutputLabel(entry, key))
    .filter(Boolean);

  return commands.length > 0 ? commands : undefined;
}

function exitCodesFromCommandOutputs(commandOutputs) {
  const exitCodes = commandOutputEntries(commandOutputs)
    .map(([, entry]) => commandOutputExitCode(entry))
    .filter((value) => value !== undefined);

  return exitCodes.length > 0 ? exitCodes : undefined;
}

function commandTimingsFromCommandOutputs(commandOutputs) {
  const timings = {};
  for (const [index, [key, entry]] of commandOutputEntries(commandOutputs).entries()) {
    const label = commandOutputLabel(entry, key);
    if (label === '') {
      continue;
    }

    timings[label] = commandOutputTiming(entry) ?? { order: index + 1 };
  }

  return Object.keys(timings).length > 0 ? timings : undefined;
}

function commandOutputEntries(commandOutputs) {
  if (Array.isArray(commandOutputs)) {
    return commandOutputs
      .map((entry, index) => [String(index + 1), normalizeCommandOutputEntry(entry, String(index + 1))])
      .filter(([key, entry]) => hasConcreteCommandOutput(entry, key));
  }

  return Object.entries(objectValue(commandOutputs))
    .map(([key, entry]) => [key, normalizeCommandOutputEntry(entry, key)])
    .filter(([key, entry]) => hasConcreteCommandOutput(entry, key));
}

function normalizeCommandOutputEntry(entry, fallbackKey = '') {
  if (entry && typeof entry === 'object' && !Array.isArray(entry)) {
    return entry;
  }

  if (/^\d+$/.test(fallbackKey) || isEmptyEvidence(entry)) {
    return entry;
  }

  return {
    command: fallbackKey,
    output: entry,
  };
}

function hasConcreteCommandOutput(entry, fallbackKey = '') {
  if (isEmptyEvidence(entry)) {
    return false;
  }

  if (typeof entry === 'string' || typeof entry === 'number' || typeof entry === 'boolean') {
    return false;
  }

  const output = objectValue(entry);
  if (commandOutputLabel(output, fallbackKey) === '') {
    return false;
  }

  return [
    'status',
    'stdout',
    'stderr',
    'output',
    'observed_output',
    'observedOutput',
    'response',
    'body',
    'result',
    'exit_code',
    'exitCode',
    'status_code',
    'statusCode',
    'http_status',
    'httpStatus',
    'duration_ms',
    'durationMs',
    'timing_ms',
    'timingMs',
    'started_at',
    'startedAt',
    'finished_at',
    'finishedAt',
  ].some((field) => !isEmptyEvidence(output[field]));
}

function commandOutputLabel(entry, fallbackKey = '') {
  const output = objectValue(entry);
  for (const field of [
    'public_guide_command',
    'publicGuideCommand',
    'command',
    'api_call',
    'apiCall',
    'request',
    'operation',
    'step',
    'name',
  ]) {
    const value = stringValue(output[field]);
    if (value !== '') {
      return value;
    }
  }

  return /^\d+$/.test(fallbackKey) ? '' : stringValue(fallbackKey);
}

function commandOutputExitCode(entry) {
  const output = objectValue(entry);
  for (const field of ['exit_code', 'exitCode', 'status_code', 'statusCode', 'http_status', 'httpStatus']) {
    const value = output[field];
    if (typeof value === 'number' || typeof value === 'string') {
      const parsed = Number.parseInt(String(value), 10);
      if (Number.isFinite(parsed)) {
        return parsed;
      }
    }
  }

  return undefined;
}

function commandOutputTiming(entry) {
  const output = objectValue(entry);
  for (const field of ['duration_ms', 'durationMs', 'timing_ms', 'timingMs', 'elapsed_ms', 'elapsedMs']) {
    const value = output[field];
    if (typeof value === 'number' || typeof value === 'string') {
      const parsed = Number.parseInt(String(value), 10);
      if (Number.isFinite(parsed)) {
        return parsed;
      }
    }
  }

  const startedAt = stringValue(output.started_at ?? output.startedAt);
  const finishedAt = stringValue(output.finished_at ?? output.finishedAt);
  if (startedAt !== '' && finishedAt !== '') {
    const startedMs = Date.parse(startedAt);
    const finishedMs = Date.parse(finishedAt);
    if (Number.isFinite(startedMs) && Number.isFinite(finishedMs) && finishedMs >= startedMs) {
      return finishedMs - startedMs;
    }
  }

  return undefined;
}

function maybeExecuteFoundationPlan(
  startedAt,
  resolvedArtifactVersions,
  publishedArtifactVersions,
  artifactSources,
) {
  if (foundationPlanDisabled()) {
    return null;
  }

  const plan = readFoundationPlan();
  if (plan === null) {
    if (foundationPlanForced()) {
      throw new Error('DW_MIGRATION_RUN_FOUNDATION_PLAN forced execution, but no foundation plan JSON was supplied.');
    }
    return null;
  }

  return executeFoundationPlan(
    plan,
    startedAt,
    resolvedArtifactVersions,
    publishedArtifactVersions,
    artifactSources,
  );
}

function foundationPlanDisabled() {
  return ['0', 'false', 'no', 'off', 'disabled'].includes(foundationPlanMode);
}

function foundationPlanForced() {
  return ['1', 'true', 'yes', 'force'].includes(foundationPlanMode);
}

function readFoundationPlan() {
  const inline = stringValue(process.env.DW_MIGRATION_FOUNDATION_PLAN_JSON);
  if (inline !== '') {
    const trimmed = inline.trim();
    if (trimmed.startsWith('{') || trimmed.startsWith('[')) {
      return objectValue(JSON.parse(trimmed));
    }

    return readJsonIfExists(trimmed);
  }

  return readJsonIfExists(foundationPlanPath);
}

function executeFoundationPlan(
  rawPlan,
  startedAt,
  resolvedArtifactVersions,
  publishedArtifactVersions,
  artifactSources,
) {
  const plan = objectValue(rawPlan);
  const defaults = {
    cwd: stringValue(plan.working_directory)
      || stringValue(plan.workingDirectory)
      || stringValue(plan.cwd)
      || resultDir,
    env: objectOfStrings(plan.environment ?? plan.env),
    timeoutMs: positiveInteger(plan.command_timeout_ms ?? plan.commandTimeoutMs, 120000),
  };
  const source = stringValue(plan.source) || 'host_executed_migration_foundation_plan';
  const planStartedAt = stringValue(plan.started_at) || stringValue(plan.startedAt) || startedAt;
  const guideRevision = objectValue(plan.migration_guide_revision ?? plan.migrationGuideRevision);
  const sourceReleaseVersions = nonEmptyObject(
    plan.source_release_versions
      ?? plan.sourceReleaseVersions
      ?? plan.v1_artifact_versions
      ?? plan.v1ArtifactVersions,
  ) ?? resolvedArtifactVersions;
  const v1SetupSource = firstNonEmptyObject(plan, [
    'latest_supported_v1_state_setup',
    'latestSupportedV1StateSetup',
    'v1_state_setup',
    'v1StateSetup',
    'realistic_v1_state_setup',
    'realisticV1StateSetup',
    'realistic_v1_state_snapshot',
    'realisticV1StateSnapshot',
  ]);
  let migrationStepSource = firstNonEmptyObject(plan, [
    'documented_migration_steps_execute',
    'documentedMigrationStepsExecute',
    'migration_plan',
    'migrationPlan',
    'guide_execution',
    'guideExecution',
  ]);
  if (Object.keys(migrationStepSource).length === 0 && Array.isArray(plan.migration_steps)) {
    migrationStepSource = { commands: plan.migration_steps };
  }
  if (Object.keys(migrationStepSource).length === 0 && Array.isArray(plan.migrationSteps)) {
    migrationStepSource = { commands: plan.migrationSteps };
  }
  const preupgradeSource = firstNonEmptyObject(plan, [
    'preupgrade_state_snapshot',
    'preupgradeStateSnapshot',
    'pre_upgrade_state_snapshot',
    'preUpgradeStateSnapshot',
  ]);
  const postupgradeSource = firstNonEmptyObject(plan, [
    'postupgrade_state_snapshot',
    'postupgradeStateSnapshot',
    'post_upgrade_state_snapshot',
    'postUpgradeStateSnapshot',
  ]);
  const executedAt = timestamp();
  const v1Setup = buildFoundationV1SetupEvidence(
    v1SetupSource,
    sourceReleaseVersions,
    source,
    defaults,
  );
  const migrationExecution = buildFoundationMigrationStepEvidence(
    migrationStepSource,
    guideRevision,
    source,
    defaults,
  );
  const preupgradeSnapshot = buildFoundationSnapshotEvidence(
    'preupgrade_state_snapshot',
    preupgradeSource,
    source,
    defaults,
  );
  const postupgradeSnapshot = buildFoundationSnapshotEvidence(
    'postupgrade_state_snapshot',
    postupgradeSource,
    source,
    defaults,
  );
  const anyCommandFailed = [
    v1Setup,
    migrationExecution,
    preupgradeSnapshot,
    postupgradeSnapshot,
  ].some((section) => section.commands_failed);
  const evidence = {
    migration_plan: foundationTopLevelObservation(
      'migration_plan',
      {
        status: migrationExecution.status,
        source,
        migration_guide_revision: migrationExecution.migration_guide_revision,
        guide_command_executability: migrationExecution.guide_command_executability,
        commands_executed: migrationExecution.commands_executed,
        exit_codes: migrationExecution.exit_codes,
        command_timings: migrationExecution.command_timings,
        command_outputs: migrationExecution.command_outputs,
        schema_or_storage_migration_output: migrationExecution.schema_or_storage_migration_output,
        observed_behavior: migrationExecution.observed_behavior,
        foundation_plan_executed_at: executedAt,
        commands_failed: migrationExecution.commands_failed,
      },
      resolvedArtifactVersions,
      publishedArtifactVersions,
      artifactSources,
    ),
    preupgrade_state_snapshot: foundationTopLevelObservation(
      'preupgrade_state_snapshot',
      {
        status: preupgradeSnapshot.status,
        source,
        state_kinds: preupgradeSnapshot.state_kinds,
        observed_states: preupgradeSnapshot.observed_states,
        observed_behavior: preupgradeSnapshot.observed_behavior,
        commands_failed: preupgradeSnapshot.commands_failed,
      },
      resolvedArtifactVersions,
      publishedArtifactVersions,
      artifactSources,
    ),
    postupgrade_state_snapshot: foundationTopLevelObservation(
      'postupgrade_state_snapshot',
      {
        status: postupgradeSnapshot.status,
        source,
        state_kinds: postupgradeSnapshot.state_kinds,
        observed_states: postupgradeSnapshot.observed_states,
        observed_behavior: postupgradeSnapshot.observed_behavior,
        commands_failed: postupgradeSnapshot.commands_failed,
      },
      resolvedArtifactVersions,
      publishedArtifactVersions,
      artifactSources,
    ),
    scenario_results: {
      latest_supported_v1_state_setup: {
        scenario_id: 'latest_supported_v1_state_setup',
        status: v1Setup.status,
        started_at: planStartedAt,
        finished_at: timestamp(),
        observed_outputs: {
          source,
          local_product_source_checkouts_used: false,
          source_release_versions: sourceReleaseVersions,
          seeded_workflows: v1Setup.seeded_workflows,
          seeded_schedules: v1Setup.seeded_schedules,
          seeded_worker_registrations: v1Setup.seeded_worker_registrations,
          queryable_history: v1Setup.queryable_history,
          observed_behavior: v1Setup.observed_behavior,
        },
      },
      documented_migration_steps_execute: {
        scenario_id: 'documented_migration_steps_execute',
        status: migrationExecution.status,
        started_at: planStartedAt,
        finished_at: timestamp(),
        observed_outputs: {
          source,
          local_product_source_checkouts_used: false,
          migration_guide_revision: migrationExecution.migration_guide_revision,
          guide_command_executability: migrationExecution.guide_command_executability,
          commands_executed: migrationExecution.commands_executed,
          exit_codes: migrationExecution.exit_codes,
          command_timings: migrationExecution.command_timings,
          schema_or_storage_migration_output: migrationExecution.schema_or_storage_migration_output,
          command_outputs: migrationExecution.command_outputs,
          observed_behavior: migrationExecution.observed_behavior,
        },
      },
    },
  };

  if (anyCommandFailed) {
    evidence.finding_links = {
      latest_supported_v1_state_setup: v1Setup.commands_failed ? [
        foundationCommandFailureFinding(
          'latest_supported_v1_state_setup',
          resolvedArtifactVersions,
          v1Setup.observed_behavior,
        ),
      ] : [],
      documented_migration_steps_execute: migrationExecution.commands_failed ? [
        foundationCommandFailureFinding(
          'documented_migration_steps_execute',
          resolvedArtifactVersions,
          migrationExecution.observed_behavior,
        ),
      ] : [],
    };
  }

  return evidence;
}

function buildFoundationV1SetupEvidence(source, sourceReleaseVersions, evidenceSource, defaults) {
  const setup = objectValue(source);
  const seededWorkflows = executeCommandEvidenceValue(
    firstNonEmptyObject(setup, ['seeded_workflows', 'seededWorkflows']),
    defaults,
  );
  const seededSchedules = executeCommandEvidenceValue(
    firstNonEmptyObject(setup, ['seeded_schedules', 'seededSchedules']),
    defaults,
  );
  const seededWorkerRegistrations = executeCommandEvidenceValue(
    firstNonEmptyObject(setup, ['seeded_worker_registrations', 'seededWorkerRegistrations']),
    defaults,
  );
  const queryableHistory = executeCommandEvidenceValue(
    firstNonEmptyObject(setup, ['queryable_history', 'queryableHistory']),
    defaults,
  );
  const commandsFailed = [
    seededWorkflows,
    seededSchedules,
    seededWorkerRegistrations,
    queryableHistory,
  ].some((entry) => containsFailedCommand(entry));
  const missingEvidence = scenarioSpecificMissingRequiredFields(
    'latest_supported_v1_state_setup',
    {},
    {
      source_release_versions: sourceReleaseVersions,
      seeded_workflows: seededWorkflows,
      seeded_schedules: seededSchedules,
      seeded_worker_registrations: seededWorkerRegistrations,
      queryable_history: queryableHistory,
    },
  );
  const status = commandsFailed || missingEvidence.length > 0 ? 'fail' : 'pass';

  return {
    status,
    seeded_workflows: seededWorkflows,
    seeded_schedules: seededSchedules,
    seeded_worker_registrations: seededWorkerRegistrations,
    queryable_history: queryableHistory,
    commands_failed: commandsFailed,
    observed_behavior: status === 'pass'
      ? 'Executed published-artifact v1 setup commands and captured completed, in-flight, activity, retry, schedule, worker, and history observations.'
      : foundationFailureSummary('latest_supported_v1_state_setup', commandsFailed, missingEvidence, evidenceSource),
  };
}

function buildFoundationMigrationStepEvidence(source, guideRevision, evidenceSource, defaults) {
  const execution = objectValue(source);
  const effectiveGuideRevision = Object.keys(guideRevision).length > 0
    ? guideRevision
    : { url: migrationGuideUrl, source: 'foundation_plan' };
  const commandDescriptors = migrationCommandDescriptors(execution);
  const commandOutputs = commandDescriptors.map((descriptor) => executeCommandDescriptor(descriptor, defaults));
  const commandsExecuted = commandOutputs.map((entry) => entry.command).filter(Boolean);
  const exitCodes = commandOutputs.map((entry) => entry.exit_code);
  const commandTimings = Object.fromEntries(
    commandOutputs
      .filter((entry) => stringValue(entry.command) !== '')
      .map((entry) => [entry.command, entry.duration_ms]),
  );
  const guideCommands = commandDescriptors
    .map((descriptor) => stringValue(objectValue(descriptor).public_guide_command) || stringValue(descriptor))
    .filter(Boolean);
  const guideCommandExecutability = nonEmptyObject(
    execution.guide_command_executability ?? execution.guideCommandExecutability,
  ) ?? migrationGuideCommandExecutability(guideCommands.length > 0 ? guideCommands : commandsExecuted);
  const schemaOutput = executeCommandEvidenceValue(
    firstNonEmptyObject(execution, [
      'schema_or_storage_migration_output',
      'schemaOrStorageMigrationOutput',
      'migration_output',
      'migrationOutput',
    ]),
    defaults,
  );
  const commandsFailed = commandOutputs.some((entry) => entry.status !== 'pass')
    || containsFailedCommand(schemaOutput);
  const missingEvidence = scenarioSpecificMissingRequiredFields(
    'documented_migration_steps_execute',
    {},
    {
      migration_guide_revision: effectiveGuideRevision,
      guide_command_executability: guideCommandExecutability,
      commands_executed: commandsExecuted,
      exit_codes: exitCodes,
      command_timings: commandTimings,
      schema_or_storage_migration_output: schemaOutput,
    },
  );
  const status = commandsFailed || missingEvidence.length > 0 ? 'fail' : 'pass';

  return {
    status,
    migration_guide_revision: effectiveGuideRevision,
    guide_command_executability: guideCommandExecutability,
    commands_executed: commandsExecuted,
    exit_codes: exitCodes,
    command_timings: commandTimings,
    command_outputs: commandOutputs,
    schema_or_storage_migration_output: schemaOutput,
    commands_failed: commandsFailed,
    observed_behavior: status === 'pass'
      ? 'Executed the documented migration command plan and captured command output, exit codes, timings, and schema or storage migration observations.'
      : foundationFailureSummary('documented_migration_steps_execute', commandsFailed, missingEvidence, evidenceSource),
  };
}

function migrationCommandDescriptors(execution) {
  for (const field of [
    'commands',
    'commands_executed',
    'commandsExecuted',
    'documented_steps',
    'documentedSteps',
    'migration_steps',
    'migrationSteps',
  ]) {
    const value = execution[field];
    if (Array.isArray(value) && value.length > 0) {
      return value;
    }
  }

  return [];
}

function buildFoundationSnapshotEvidence(kind, source, evidenceSource, defaults) {
  const snapshot = objectValue(source);
  const observedStates = executeCommandEvidenceValue(
    snapshot.observed_states
      ?? snapshot.observedStates
      ?? snapshot.state_entries
      ?? snapshot.stateEntries
      ?? [],
    defaults,
  );
  const stateKinds = arrayOfStrings(snapshot.state_kinds ?? snapshot.stateKinds);
  const observed = {
    status: normalizedStatus(snapshot.status || snapshot.outcome || 'pass'),
    state_kinds: stateKinds.length > 0
      ? stateKinds
      : arrayOfStrings(scenarioManifest?.required_matrix?.state_kinds),
    observed_states: observedStates,
  };
  const commandsFailed = containsFailedCommand(observedStates);
  const missingStates = stateSnapshotFailuresFor({
    [kind]: observed,
  });
  const status = commandsFailed || missingStates.length > 0 ? 'fail' : observed.status;

  return {
    ...observed,
    status,
    commands_failed: commandsFailed,
    observed_behavior: status === 'pass'
      ? `Executed ${kind} observation commands and captured state cells for every required migration state kind.`
      : foundationFailureSummary(
        kind,
        commandsFailed,
        missingStates.map((failure) => failure.state_kind || failure.code || failure.field).filter(Boolean),
        evidenceSource,
      ),
  };
}

function foundationFailureSummary(scenarioId, commandsFailed, missingEvidence, evidenceSource) {
  const reasons = [];
  if (commandsFailed) {
    reasons.push('one or more foundation commands exited non-zero or timed out');
  }
  if (missingEvidence.length > 0) {
    reasons.push(`missing required evidence: ${missingEvidence.join(', ')}`);
  }

  return `Foundation evidence plan ${evidenceSource} did not satisfy ${scenarioId}: ${reasons.join('; ')}.`;
}

function foundationCommandFailureFinding(scenarioId, artifactVersions, observedBehavior) {
  const policy = SCENARIO_FINDING_POLICIES[scenarioId] ?? SCENARIO_FINDING_POLICIES.documented_migration_steps_execute;

  return {
    scenario_id: scenarioId,
    owning_surface: policy.owning_surface,
    finding_type: policy.finding_type,
    artifact_versions: artifactVersions,
    observed_behavior: observedBehavior,
    expected_behavior: policy.expected_behavior,
    next_acceptance_criterion: policy.next_acceptance_criterion,
  };
}

function executeCommandEvidenceValue(value, defaults) {
  if (Array.isArray(value)) {
    return value.map((entry) => executeCommandEvidenceValue(entry, defaults));
  }

  if (!value || typeof value !== 'object') {
    return value;
  }

  const object = objectValue(value);
  if (stringValue(object.command) !== '') {
    return executeCommandDescriptor(object, defaults);
  }

  return Object.fromEntries(
    Object.entries(object).map(([key, entry]) => [key, executeCommandEvidenceValue(entry, defaults)]),
  );
}

function executeCommandDescriptor(rawDescriptor, defaults) {
  const descriptor = typeof rawDescriptor === 'string'
    ? { command: rawDescriptor }
    : objectValue(rawDescriptor);
  const command = stringValue(descriptor.command);
  const cwd = stringValue(descriptor.cwd)
    || stringValue(descriptor.working_directory)
    || stringValue(descriptor.workingDirectory)
    || defaults.cwd
    || resultDir;
  const shell = stringValue(descriptor.shell) || '/bin/sh';
  const timeoutMs = positiveInteger(descriptor.timeout_ms ?? descriptor.timeoutMs, defaults.timeoutMs || 120000);
  const env = {
    ...process.env,
    ...objectOfStrings(defaults.env),
    ...objectOfStrings(descriptor.environment ?? descriptor.env),
  };
  const metadata = Object.fromEntries(
    Object.entries(descriptor).filter(([key]) => ![
      'command',
      'cwd',
      'working_directory',
      'workingDirectory',
      'shell',
      'timeout_ms',
      'timeoutMs',
      'environment',
      'env',
      'expected_exit_code',
      'expectedExitCode',
    ].includes(key)),
  );
  const started = timestamp();
  const startedMs = Date.now();
  const result = command === ''
    ? {
        status: 127,
        stdout: '',
        stderr: 'foundation command descriptor did not include a command',
        error: null,
        signal: null,
      }
    : childProcess.spawnSync(shell, ['-lc', command], {
        cwd,
        env,
        encoding: 'utf8',
        timeout: timeoutMs,
        maxBuffer: 1024 * 1024 * 5,
      });
  const durationMs = Date.now() - startedMs;
  const signal = result.signal ?? null;
  const exitCode = result.status === null || result.status === undefined
    ? (result.error ? 124 : exitCodeForSignal(signal))
    : result.status;
  const expectedExitCode = Number.isInteger(descriptor.expected_exit_code)
    ? descriptor.expected_exit_code
    : Number.isInteger(descriptor.expectedExitCode)
      ? descriptor.expectedExitCode
      : 0;
  const status = result.error || signal !== null || exitCode !== expectedExitCode ? 'fail' : 'pass';
  const stderr = outputString(result.stderr);
  const stdout = outputString(result.stdout);

  return {
    ...metadata,
    command,
    cwd,
    started_at: started,
    finished_at: timestamp(),
    duration_ms: durationMs,
    timeout_ms: timeoutMs,
    exit_code: exitCode,
    expected_exit_code: expectedExitCode,
    status,
    stdout,
    stderr: result.error ? [stderr, errorMessage(result.error)].filter(Boolean).join('\n') : stderr,
    timed_out: result.error?.code === 'ETIMEDOUT',
    signal,
  };
}

function exitCodeForSignal(signal) {
  if (signal === null || signal === undefined) {
    return 0;
  }

  return SIGNAL_EXIT_CODES[stringValue(signal).toUpperCase()] ?? 1;
}

function containsFailedCommand(value) {
  if (!value || typeof value !== 'object') {
    return false;
  }
  if (Array.isArray(value)) {
    return value.some((entry) => containsFailedCommand(entry));
  }

  const object = objectValue(value);
  if (stringValue(object.command) !== '' && stringValue(object.status) !== 'pass') {
    return true;
  }

  return Object.values(object).some((entry) => containsFailedCommand(entry));
}

function containsFoundationCommandFailure(value) {
  if (!value || typeof value !== 'object') {
    return false;
  }
  if (Array.isArray(value)) {
    return value.some((entry) => containsFoundationCommandFailure(entry));
  }

  const object = objectValue(value);
  if (truthy(object.commands_failed) || truthy(object.commandsFailed)) {
    return true;
  }
  const status = stringValue(object.status);
  const hasCommandExecutionMetadata = [
    'exit_code',
    'exitCode',
    'expected_exit_code',
    'expectedExitCode',
    'duration_ms',
    'durationMs',
    'timed_out',
    'timedOut',
  ].some((key) => Object.hasOwn(object, key));
  if (stringValue(object.command) !== '' && hasCommandExecutionMetadata && status !== '' && status !== 'pass') {
    return true;
  }

  return Object.values(object).some((entry) => containsFoundationCommandFailure(entry));
}

function outputString(value) {
  const text = stringValue(value);
  return text.length > 20000 ? `${text.slice(0, 20000)}\n[truncated]` : text;
}

function objectOfStrings(value) {
  return Object.fromEntries(
    Object.entries(objectValue(value))
      .map(([key, entry]) => [key, stringValue(entry)])
      .filter(([, entry]) => entry !== ''),
  );
}

function positiveInteger(value, fallback) {
  const number = Number.parseInt(String(value ?? ''), 10);
  return Number.isFinite(number) && number > 0 ? number : fallback;
}

function runbookFieldValue(container, field) {
  const direct = fieldValue(container, field);
  if (!isEmptyEvidence(direct)) {
    return direct;
  }

  const object = objectValue(container);
  for (const alias of fieldAliases(field)) {
    const value = object[alias];
    if (!isEmptyEvidence(value)) {
      return value;
    }
  }

  return undefined;
}

function runbookFirstNonEmptyField(container, fields) {
  for (const field of fields) {
    const value = runbookFieldValue(container, field);
    if (!isEmptyEvidence(value)) {
      return value;
    }
  }

  return undefined;
}

function firstNonEmptyObject(container, fields) {
  const object = objectValue(container);
  for (const field of fields) {
    const value = objectValue(object[field]);
    if (Object.keys(value).length > 0) {
      return value;
    }
  }

  return {};
}

function firstNonEmptyEvidenceObject(container, fields) {
  const object = objectValue(container);
  for (const field of fields) {
    const value = objectValue(object[field]);
    if (Object.keys(value).length > 0 && !isEmptyEvidence(value)) {
      return value;
    }
  }

  return {};
}

function artifactVersionsFromRunbook(...sources) {
  const versions = {};

  for (const source of sources) {
    mergeArtifactVersionEntries(versions, source, '');
    mergeArtifactVersionEntries(versions, firstNonEmptyObject(source, ['v1', 'source', 'source_release_set', 'sourceReleaseSet']), 'v1');
    mergeArtifactVersionEntries(versions, firstNonEmptyObject(source, ['v2', 'target', 'target_release_set', 'targetReleaseSet']), 'v2');
  }

  return versions;
}

function mergeArtifactVersionEntries(target, source, generation) {
  const object = objectValue(source);
  if (Object.keys(object).length === 0) {
    return;
  }

  const mappings = generation === 'v1'
    ? [
        ['server-v1', ['server', 'server-v1', 'serverV1']],
        ['cli-v1', ['cli', 'cli-v1', 'cliV1']],
        ['workflow-php-v1', ['workflow', 'workflow-php', 'workflow_php', 'workflow-php-v1', 'workflowPhpV1']],
        ['waterline-v1', ['waterline', 'waterline-v1', 'waterlineV1']],
        ['sample-app-v1', ['sample-app', 'sample_app', 'sampleApp', 'sample-app-v1', 'sampleAppV1']],
      ]
    : generation === 'v2'
      ? [
          ['server-v2', ['server', 'server-v2', 'serverV2']],
          ['cli-v2', ['cli', 'cli-v2', 'cliV2']],
          ['workflow-php-v2', ['workflow', 'workflow-php', 'workflow_php', 'workflow-php-v2', 'workflowPhpV2']],
          ['sdk-python', ['sdk-python', 'sdk_python', 'sdkPython', 'python', 'python-sdk', 'pythonSdk']],
          ['waterline-v2', ['waterline', 'waterline-v2', 'waterlineV2']],
        ]
      : effectiveRequiredArtifacts().map((artifact) => [artifact, [artifact, ...artifactAliasesFor(artifact)]]);

  for (const [artifact, aliases] of mappings) {
    if (stringValue(target[artifact]) !== '') {
      continue;
    }

    const value = aliases.map((alias) => object[alias]).find((entry) => stringValue(entry) !== '');
    if (value !== undefined) {
      target[artifact] = value;
    }
  }
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
    'cli-v1': stringValue(process.env.DW_CLI_V1_VERSION),
    'cli-v2': stringValue(process.env.DW_CLI_V2_VERSION) || stringValue(process.env.DW_CLI_VERSION),
    'workflow-php-v1': stringValue(process.env.DW_WORKFLOW_PHP_V1_VERSION)
      || stringValue(process.env.DW_WORKFLOW_V1_VERSION),
    'workflow-php-v2': workflowV2,
    'sdk-python': stringValue(process.env.DW_PYTHON_SDK_VERSION),
    'waterline-v1': stringValue(process.env.DW_WATERLINE_V1_VERSION),
    'waterline-v2': stringValue(process.env.DW_WATERLINE_V2_VERSION) || stringValue(process.env.DW_WATERLINE_VERSION),
    'sample-app-v1': stringValue(process.env.DW_SAMPLE_APP_V1_VERSION) || stringValue(process.env.DW_SAMPLE_APP_VERSION),
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

  await resolveV1WorkflowPackage(resolution, fixture);

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

  pinV1ServerBaselineFromWorkflowRuntime(resolution);

  if (stringValue(resolution.artifact_versions['cli-v1']) === '') {
    try {
      const cliV1 = await latestGithubReleaseVersion('durable-workflow/cli', /^v?0\.1\./, ['install.sh']);
      if (cliV1 !== '') {
        resolution.artifact_versions['cli-v1'] = cliV1;
        resolution.artifact_sources['cli-v1'] = `github_release:durable-workflow/cli:${cliV1}:install.sh`;
        resolution.observations['cli-v1'] = {
          status: 'resolved',
          channel: 'github_release',
          repository: 'durable-workflow/cli',
          version: cliV1,
          required_assets: ['install.sh'],
        };
      }
    } catch (error) {
      resolution.observations['cli-v1'] = {
        status: 'resolution_error',
        channel: 'github_release',
        repository: 'durable-workflow/cli',
        error: errorMessage(error),
      };
    }
  }

  if (stringValue(resolution.artifact_versions['waterline-v1']) === '') {
    try {
      const waterlineV1 = await latestPackagistVersion('laravel-workflow/waterline', /^v?1\./);
      if (waterlineV1 !== '') {
        resolution.artifact_versions['waterline-v1'] = waterlineV1;
        resolution.artifact_sources['waterline-v1'] =
          `packagist:laravel-workflow/waterline:${waterlineV1}`;
        resolution.observations['waterline-v1'] = {
          status: 'resolved',
          channel: 'packagist',
          package: 'laravel-workflow/waterline',
          version: waterlineV1,
        };
      }
    } catch (error) {
      resolution.observations['waterline-v1'] = {
        status: 'resolution_error',
        channel: 'packagist',
        package: 'laravel-workflow/waterline',
        error: errorMessage(error),
      };
    }
  }

  if (stringValue(resolution.artifact_versions['sample-app-v1']) === '') {
    try {
      const sampleAppV1 = await latestGithubBranchCommit('durable-workflow/sample-app', 'Laravel-12');
      if (sampleAppV1 !== '') {
        resolution.artifact_versions['sample-app-v1'] = sampleAppV1;
        resolution.artifact_sources['sample-app-v1'] =
          `github_branch:durable-workflow/sample-app:Laravel-12@${sampleAppV1}`;
        resolution.observations['sample-app-v1'] = {
          status: 'resolved',
          channel: 'github_branch',
          repository: 'durable-workflow/sample-app',
          branch: 'Laravel-12',
          commit: sampleAppV1,
        };
      }
    } catch (error) {
      resolution.observations['sample-app-v1'] = {
        status: 'resolution_error',
        channel: 'github_branch',
        repository: 'durable-workflow/sample-app',
        branch: 'Laravel-12',
        error: errorMessage(error),
      };
    }
  }

  return resolution;
}

async function resolveV1WorkflowPackage(resolution, fixture) {
  const existingVersion = stringValue(resolution.artifact_versions['workflow-php-v1']);
  const existingSource = stringValue(resolution.artifact_sources['workflow-php-v1']);
  const packageVersionCache = objectValue(
    fixture?.packagist_versions ?? fixture?.packagistVersions,
  );
  const packageDefinitions = [
    { key: 'current_namespace', package: WORKFLOW_V1_PRIMARY_PACKAGE },
    { key: 'legacy_alias', package: WORKFLOW_V1_LEGACY_PACKAGE },
  ];

  if (
    existingVersion !== ''
    && !packageDefinitions.some(({ package: packageName }) => existingSource.includes(packageName))
  ) {
    return;
  }

  const candidates = {};
  for (const definition of packageDefinitions) {
    const packageName = definition.package;
    try {
      let version = '';
      let metadataSource = 'packagist_api';
      if (Object.prototype.hasOwnProperty.call(packageVersionCache, packageName)) {
        version = latestStableVersion(packageVersionCache[packageName], /^v?1\./);
        metadataSource = 'public_artifact_fixture_cache';
      } else if (existingVersion !== '' && existingSource.includes(packageName)) {
        version = existingVersion;
        metadataSource = 'public_artifact_fixture_cache';
      } else {
        version = await latestPackagistVersion(packageName, /^v?1\./);
      }

      candidates[definition.key] = {
        status: version === '' ? 'missing' : 'resolved',
        channel: 'packagist',
        package: packageName,
        version,
        metadata_source: metadataSource,
      };
    } catch (error) {
      candidates[definition.key] = {
        status: 'resolution_error',
        channel: 'packagist',
        package: packageName,
        error: errorMessage(error),
      };
    }
  }

  const current = candidates.current_namespace?.status === 'resolved'
    ? candidates.current_namespace
    : null;
  const legacy = candidates.legacy_alias?.status === 'resolved'
    ? candidates.legacy_alias
    : null;
  const latestSupportedVersion = [current, legacy]
    .filter(Boolean)
    .map((candidate) => candidate.version)
    .sort(compareVersionStrings)
    .pop() ?? '';
  const legacyIsSameOrNewer = legacy !== null
    && current !== null
    && compareVersionStrings(legacy.version, current.version) >= 0;
  const selected = current !== null && current.version === latestSupportedVersion
    ? current
    : legacy !== null && legacy.version === latestSupportedVersion && legacyIsSameOrNewer
      ? legacy
      : null;

  if (selected === null) {
    delete resolution.artifact_versions['workflow-php-v1'];
    delete resolution.artifact_sources['workflow-php-v1'];
    resolution.observations['workflow-php-v1'] = {
      status: 'resolution_error',
      channel: 'packagist',
      selection_policy: 'latest_supported_v1_with_current_namespace_preference',
      latest_observed_version: latestSupportedVersion,
      legacy_alias_fallback: {
        eligible: false,
        selected: false,
        comparison_version: current?.version ?? null,
      },
      candidates,
      error: 'No supported workflow package baseline could be proven across the public v1 namespaces.',
    };
    return;
  }

  resolution.artifact_versions['workflow-php-v1'] = selected.version;
  resolution.artifact_sources['workflow-php-v1'] =
    `packagist:${selected.package}:${selected.version}`;
  resolution.observations['workflow-php-v1'] = {
    status: 'resolved',
    channel: 'packagist',
    package: selected.package,
    version: selected.version,
    latest_supported_version: latestSupportedVersion,
    selection_policy: 'latest_supported_v1_with_current_namespace_preference',
    current_namespace_preferred: selected.package === WORKFLOW_V1_PRIMARY_PACKAGE,
    legacy_alias_fallback: {
      eligible: legacyIsSameOrNewer,
      selected: selected.package === WORKFLOW_V1_LEGACY_PACKAGE,
      comparison_version: current?.version ?? selected.version,
    },
    candidates,
  };
}

function pinV1ServerBaselineFromWorkflowRuntime(resolution) {
  if (stringValue(resolution.artifact_versions['server-v1']) !== '') {
    return;
  }

  const workflowV1 = stringValue(resolution.artifact_versions['workflow-php-v1']);
  if (workflowV1 === '') {
    return;
  }

  const workflowObservation = objectValue(resolution.observations['workflow-php-v1']);
  const workflowPackage = stringValue(workflowObservation.package)
    || WORKFLOW_V1_PRIMARY_PACKAGE;
  const workflowSource = stringValue(resolution.artifact_sources['workflow-php-v1'])
    || `packagist:${workflowPackage}:${workflowV1}`;
  const standaloneServerImage = objectValue(resolution.observations['server-v1']);

  resolution.artifact_versions['server-v1'] = workflowV1;
  resolution.artifact_sources['server-v1'] =
    `${workflowSource}:embedded-v1-server-runtime`;
  resolution.observations['server-v1'] = {
    status: 'resolved',
    channel: 'packagist',
    package: workflowPackage,
    version: workflowV1,
    runtime: 'embedded-v1-server-runtime',
    baseline_source: 'workflow-php-v1',
    standalone_server_image: Object.keys(standaloneServerImage).length === 0
      ? {
          status: 'not_part_of_public_v1_contract',
          channel: 'docker_hub',
          repository: 'durableworkflow/server',
          expected_tag_family: '1.x',
        }
      : standaloneServerImage,
  };
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
  return latestStableVersion(metadata?.packages?.[packageName], versionPattern);
}

function latestStableVersion(entries, versionPattern) {
  const versions = (Array.isArray(entries) ? entries : [entries])
    .map((entry) => stringValue(
      entry && typeof entry === 'object' ? entry.version : entry,
    ))
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

async function latestGithubReleaseVersion(repository, tagPattern, requiredAssets = []) {
  let next = `https://api.github.com/repos/${repository}/releases?per_page=100`;
  const tags = [];
  let pages = 0;

  while (next && pages < 10) {
    pages += 1;
    const { value: releases, headers } = await fetchJsonWithHeaders(next);
    for (const release of arrayValue(releases)) {
      const tag = stringValue(release?.tag_name);
      if (tag === '' || !tagPattern.test(tag) || isPrereleaseVersion(tag) || truthy(release?.draft)) {
        continue;
      }

      const assetNames = arrayValue(release?.assets)
        .map((asset) => stringValue(asset?.name))
        .filter(Boolean);
      const hasRequiredAssets = arrayOfStrings(requiredAssets)
        .every((assetName) => assetNames.includes(assetName));
      if (hasRequiredAssets) {
        tags.push(tag);
      }
    }
    next = githubNextLink(stringValue(headers.link)) || '';
  }

  return tags.sort(compareVersionStrings).pop() ?? '';
}

async function latestGithubBranchCommit(repository, branch) {
  const metadata = await fetchJson(`https://api.github.com/repos/${repository}/commits/${branch}`);
  return stringValue(metadata?.sha);
}

async function fetchJson(url) {
  const { value } = await fetchJsonWithHeaders(url);
  return value;
}

async function fetchJsonWithHeaders(url) {
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
  return {
    value: await response.json(),
    headers: Object.fromEntries(response.headers.entries()),
  };
}

function githubNextLink(linkHeader) {
  const parts = stringValue(linkHeader).split(',');
  for (const part of parts) {
    const match = part.match(/<([^>]+)>;\s*rel="next"/i);
    if (match) {
      return match[1];
    }
  }
  return '';
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
    'cli-v1': stringValue(process.env.DW_CLI_V1_ARTIFACT_SOURCE) || defaultSource,
    'cli-v2': stringValue(process.env.DW_CLI_V2_ARTIFACT_SOURCE)
      || stringValue(process.env.DW_CLI_ARTIFACT_SOURCE)
      || defaultSource,
    'workflow-php-v1': stringValue(process.env.DW_WORKFLOW_PHP_V1_ARTIFACT_SOURCE)
      || stringValue(process.env.DW_WORKFLOW_V1_ARTIFACT_SOURCE)
      || defaultSource,
    'workflow-php-v2': stringValue(process.env.DW_WORKFLOW_PHP_V2_ARTIFACT_SOURCE)
      || stringValue(process.env.DW_WORKFLOW_PHP_ARTIFACT_SOURCE)
      || stringValue(process.env.DW_WORKFLOW_ARTIFACT_SOURCE)
      || defaultSource,
    'sdk-python': stringValue(process.env.DW_PYTHON_SDK_ARTIFACT_SOURCE) || defaultSource,
    'waterline-v1': stringValue(process.env.DW_WATERLINE_V1_ARTIFACT_SOURCE) || defaultSource,
    'waterline-v2': stringValue(process.env.DW_WATERLINE_V2_ARTIFACT_SOURCE)
      || stringValue(process.env.DW_WATERLINE_ARTIFACT_SOURCE)
      || defaultSource,
    'sample-app-v1': stringValue(process.env.DW_SAMPLE_APP_V1_ARTIFACT_SOURCE)
      || stringValue(process.env.DW_SAMPLE_APP_ARTIFACT_SOURCE)
      || defaultSource,
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

function missingRunRecordObservation(kind, reason, storageSmokeOnlyProductEvidence) {
  if (!storageSmokeOnlyProductEvidence) {
    return notCoveredObservation(kind, reason);
  }

  return {
    status: 'fail',
    kind,
    storage_connection_smoke_only: true,
    observed_behavior: `${reason} The current non-runner-blocked product evidence only covers storage-connection migration smoke.`,
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

function hasNonEmptyArrayField(container, field) {
  const object = objectValue(container);
  for (const alias of fieldAliases(field)) {
    if (object[alias] && typeof object[alias] === 'object' && !isEmptyEvidence(object[alias])) {
      return true;
    }
  }
  return false;
}

function evidenceContainsItem(value, item) {
  if (!value || typeof value !== 'object') {
    return false;
  }

  const object = objectValue(value);
  for (const alias of fieldAliases(item)) {
    if (Object.hasOwn(object, alias) && !isEmptyEvidence(object[alias])) {
      return true;
    }
  }

  for (const field of ['state_kinds', 'stateKinds', 'kinds', 'items']) {
    if (evidenceContainsItem(object[field], item)) {
      return true;
    }
  }

  for (const entry of Array.isArray(value) ? value : Object.values(object)) {
    if (!entry || typeof entry !== 'object') {
      continue;
    }

    for (const field of ['id', 'kind', 'type', 'state_kind', 'stateKind', 'name', 'scenario']) {
      if (stringValue(entry[field]) === item && !isEmptyEvidence(entry)) {
        return true;
      }
    }

    if (evidenceContainsItem(entry, item)) {
      return true;
    }
  }

  return false;
}

function evidenceContainsAnyToken(value, tokens) {
  if (typeof value === 'string' || typeof value === 'number') {
    const text = String(value);
    return tokens.some((token) => {
      if (stringValue(token) === '') {
        return false;
      }
      return new RegExp(`\\b${escapeRegex(token)}\\b`, 'i').test(text);
    });
  }

  if (!value || typeof value !== 'object' || isEmptyEvidence(value)) {
    return false;
  }

  for (const [key, entry] of Object.entries(value)) {
    if (!isEmptyEvidence(entry) && evidenceContainsAnyToken(key, tokens)) {
      return true;
    }
    if (evidenceContainsAnyToken(entry, tokens)) {
      return true;
    }
  }

  return false;
}

function escapeRegex(value) {
  return stringValue(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
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
    return value.trim() === '' || isPlaceholderEvidenceString(value);
  }
  if (Array.isArray(value)) {
    return value.length === 0 || value.every((entry) => isEmptyEvidence(entry));
  }
  if (typeof value === 'object') {
    const status = stringValue(value.status).toLowerCase();
    if (['not_covered', 'runner_blocked'].includes(status) || truthy(value.coverage_gap)) {
      return true;
    }

    const entries = Object.entries(value);
    if (entries.length === 0) {
      return true;
    }

    return entries.every(([key, entry]) => EVIDENCE_METADATA_FIELDS.includes(key) || isEmptyEvidence(entry));
  }
  return false;
}

function isPlaceholderEvidenceString(value) {
  const normalized = value.trim().toLowerCase();
  if (normalized === '') {
    return true;
  }

  return PLACEHOLDER_EVIDENCE_TOKENS.some((token) => {
    const candidate = token.toLowerCase();
    return normalized === candidate || normalized.includes(candidate);
  });
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

function rawStringValue(value) {
  return typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean'
    ? String(value)
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
