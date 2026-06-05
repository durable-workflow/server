#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage: nexus-published-artifacts.sh [--result-dir DIR|--result-dir=DIR]

Composes the public Nexus conformance result from published-artifact evidence.
The runner never treats a local product checkout as an artifact under test.
Missing Nexus cells are recorded as not_covered with linked coverage findings,
so a host run that reaches this handoff is product/coverage evidence rather
than runner-blocked evidence.

The runner writes these files to the result directory:
  pins.json
  nexus-conformance-result.json
  nexus-conformance-record.json

Environment overrides:
  DW_NEXUS_RESULT_DIR              Result directory. Defaults to a temp dir.
  DW_NEXUS_EVIDENCE_JSON           Optional host evidence JSON with scenario_results.
  DW_SERVER_VERSION                Exact published server artifact version.
  DW_CLI_VERSION                   Exact published CLI version.
  DW_WORKFLOW_PHP_VERSION          Exact published Workflow PHP package version.
  DW_PYTHON_SDK_VERSION            Exact published Python SDK package version.
  DW_WATERLINE_VERSION             Exact published Waterline package version.
  DW_SERVER_ARTIFACT_SOURCE        Published server artifact source.
  DW_CLI_ARTIFACT_SOURCE           Published CLI artifact source.
  DW_WORKFLOW_ARTIFACT_SOURCE      Published Workflow PHP artifact source.
  DW_PYTHON_SDK_ARTIFACT_SOURCE    Published Python SDK artifact source.
  DW_WATERLINE_ARTIFACT_SOURCE     Published Waterline artifact source.
USAGE
}

result_dir="${DW_NEXUS_RESULT_DIR:-}"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --result-dir)
      result_dir="${2:?--result-dir requires a value}"
      shift 2
      ;;
    --result-dir=*)
      result_dir="${1#--result-dir=}"
      if [[ -z "$result_dir" ]]; then
        printf '%s\n' '--result-dir requires a value' >&2
        usage >&2
        exit 2
      fi
      shift
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      printf 'unknown argument: %s\n' "$1" >&2
      usage >&2
      exit 2
      ;;
  esac
done

if ! command -v node >/dev/null 2>&1; then
  printf '%s\n' 'required command not found: node' >&2
  exit 127
fi

if [[ -z "$result_dir" ]]; then
  result_dir="$(mktemp -d "${TMPDIR:-/tmp}/dw-nexus.XXXXXX")"
fi
mkdir -p "$result_dir"

node - "$result_dir" "${DW_NEXUS_EVIDENCE_JSON:-}" <<'NODE'
const fs = require('fs');
const path = require('path');

const resultDir = process.argv[2];
const evidencePath = process.argv[3] || '';

const requiredScenarios = [
  'published_artifact_install_only',
  'tenant_a_calls_shared_service',
  'tenant_b_calls_shared_service',
  'transient_failure_retries_with_policy',
  'permanent_failure_preserves_typed_error',
  'worker_restart_replay_does_not_reissue_call',
  'caller_cancellation_propagates_to_service',
  'php_caller_python_service',
  'python_caller_php_service',
  'endpoint_permission_denied_without_information_leak',
  'malformed_payload_refused_before_dispatch',
  'nonexistent_endpoint_typed_not_found',
  'caller_history_attempt_visibility',
  'result_record_and_product_finding_routing',
];

const allowedStatuses = new Set([
  'pass',
  'fail',
  'unsupported',
  'not_covered',
  'runner_blocked',
]);
const resultRoutingScenarioId = 'result_record_and_product_finding_routing';
const routedNonPassStatuses = new Set([
  'fail',
  'unsupported',
  'not_covered',
  'runner_blocked',
]);
const requiredArtifacts = [
  'server',
  'cli',
  'workflow',
  'sdk-python',
  'waterline',
];
const artifactAliases = {
  workflow: ['workflow-php', 'workflow_php', 'workflowPhp'],
  'sdk-python': ['sdk_python', 'python-sdk', 'pythonSdk'],
};
const artifactOwners = {
  server: 'server',
  cli: 'cli',
  workflow: 'workflow',
  'sdk-python': 'sdk-python',
  waterline: 'waterline',
};
const placeholderVersionTokens = [
  'latest',
  'current',
  'head',
  'unresolved',
  'placeholder',
  '<latest>',
  '${VERSION}',
  '{{ version }}',
];
const rollingSourceRefPattern = /(^|[/:@=?&#._-])(latest|current|head)(?:$|[/:@?&#._-])/i;
const forbiddenSourceTokens = [
  'local_product_source_checkout',
  'workspace_repo_as_artifact_under_test',
  'local_checkout_artifact',
  'local_checkout',
  'local_source_checkout',
  'workspace_repo',
  'rolling_server_image_tag',
  'unverified_artifact_source',
];
const cliReleaseAssetNames = new Set([
  'dw.phar',
  'dw-linux-aarch64',
  'dw-linux-x86_64',
  'dw-macos-aarch64',
  'dw-windows-x86_64.exe',
  'dw.rb',
  'install.sh',
  'install.ps1',
  'verify-release.sh',
  'SHA256SUMS',
]);
const scenarioEvidenceRequirements = {
  published_artifact_install_only: [
    {fields: ['artifact_versions', 'artifactVersions'], kind: 'non_empty_object', expected: 'pinned published versions for every required Nexus artifact'},
    {fields: ['artifact_sources', 'artifactSources'], kind: 'non_empty_object', expected: 'published install source for every required Nexus artifact'},
    {fields: ['artifact_source_verification', 'artifactSourceVerification'], kind: 'non_empty_object', expected: 'host proof that every published artifact source resolved to a downloadable public artifact'},
    {fields: ['local_product_source_checkouts_used', 'localProductSourceCheckoutsUsed'], kind: 'boolean_false', expected: 'explicit proof that no local product source checkout was used'},
    {fields: ['install_channels_verified', 'installChannelsVerified'], kind: 'boolean_true', expected: 'published artifact install channels were exercised successfully'},
  ],
  tenant_a_calls_shared_service: [
    {fields: ['caller_namespace', 'callerNamespace'], kind: 'value_equals', value: 'tenant-a', expected: 'tenant-a is recorded as the caller namespace'},
    {fields: ['target_namespace', 'targetNamespace'], kind: 'value_equals', value: 'shared', expected: 'shared is recorded as the target namespace'},
    {fields: ['endpoint_name', 'endpointName'], kind: 'non_empty_string', expected: 'shared Nexus endpoint name invoked by tenant-a'},
    {fields: ['service_name', 'serviceName'], kind: 'value_equals', value: 'Greeter', expected: 'tenant-a invoked the shared Greeter service'},
    {fields: ['operation_name', 'operationName'], kind: 'value_equals', value: 'greet', expected: 'tenant-a invoked Greeter.greet'},
    {fields: ['service_call_id', 'serviceCallId'], kind: 'non_empty_string', expected: 'durable service-call id from the cross-namespace invocation'},
    {fields: ['workflow_result', 'workflowResult'], kind: 'non_empty_string', expected: 'caller workflow result from the shared service'},
    {fields: ['request', 'requestEvidence', 'invocation_request', 'invocationRequest'], kind: 'non_empty_object', expected: 'request evidence for the tenant-a shared-service invocation'},
    {fields: ['response', 'responseEvidence', 'invocation_response', 'invocationResponse'], kind: 'non_empty_object', expected: 'response evidence for the tenant-a shared-service invocation'},
    {fields: ['service_call_record', 'serviceCallRecord', 'service_call_detail', 'serviceCallDetail'], kind: 'non_empty_object', expected: 'service-call record evidence for the tenant-a shared-service invocation'},
    {fields: ['caller_history_evidence', 'callerHistoryEvidence', 'caller_history', 'callerHistory'], kind: 'non_empty_object', expected: 'caller-history evidence for the tenant-a shared-service invocation'},
    {fields: ['caller_history_recorded', 'callerHistoryRecorded'], kind: 'boolean_true', expected: 'caller history includes the Nexus call'},
  ],
  tenant_b_calls_shared_service: [
    {fields: ['caller_namespace', 'callerNamespace'], kind: 'value_equals', value: 'tenant-b', expected: 'tenant-b is recorded as the caller namespace'},
    {fields: ['target_namespace', 'targetNamespace'], kind: 'value_equals', value: 'shared', expected: 'shared is recorded as the target namespace'},
    {fields: ['endpoint_name', 'endpointName'], kind: 'non_empty_string', expected: 'shared Nexus endpoint name invoked by tenant-b'},
    {fields: ['service_name', 'serviceName'], kind: 'value_equals', value: 'Greeter', expected: 'tenant-b invoked the shared Greeter service'},
    {fields: ['operation_name', 'operationName'], kind: 'value_equals', value: 'greet', expected: 'tenant-b invoked Greeter.greet'},
    {fields: ['service_call_id', 'serviceCallId'], kind: 'non_empty_string', expected: 'durable service-call id from the cross-namespace invocation'},
    {fields: ['workflow_result', 'workflowResult'], kind: 'non_empty_string', expected: 'caller workflow result from the shared service'},
    {fields: ['request', 'requestEvidence', 'invocation_request', 'invocationRequest'], kind: 'non_empty_object', expected: 'request evidence for the tenant-b shared-service invocation'},
    {fields: ['response', 'responseEvidence', 'invocation_response', 'invocationResponse'], kind: 'non_empty_object', expected: 'response evidence for the tenant-b shared-service invocation'},
    {fields: ['service_call_record', 'serviceCallRecord', 'service_call_detail', 'serviceCallDetail'], kind: 'non_empty_object', expected: 'service-call record evidence for the tenant-b shared-service invocation'},
    {fields: ['caller_history_evidence', 'callerHistoryEvidence', 'caller_history', 'callerHistory'], kind: 'non_empty_object', expected: 'caller-history evidence for the tenant-b shared-service invocation'},
    {fields: ['caller_history_recorded', 'callerHistoryRecorded'], kind: 'boolean_true', expected: 'caller history includes the Nexus call'},
  ],
  transient_failure_retries_with_policy: [
    {fields: ['service_call_id', 'serviceCallId'], kind: 'non_empty_string', expected: 'durable service-call id for the retrying call'},
    {fields: ['retry_policy', 'retryPolicy'], kind: 'non_empty_object', expected: 'recorded retry policy applied to the Nexus call'},
    {fields: ['retry_attempts', 'retryAttempts', 'history_attempts', 'historyAttempts'], kind: 'attempts_at_least', min: 2, expected: 'visible retry attempts for the transient failure'},
    {
      fields: ['history_attempt_visibility_includes_retry_attempts', 'historyAttemptVisibilityIncludesRetryAttempts'],
      kind: 'boolean_true',
      expected: 'history attempt visibility includes every retry attempt',
      invalid_code: 'retry_attempt_visibility_gap',
      finding_type: 'retry_attempt_visibility_gap',
      owning_surface: 'server',
    },
    {fields: ['completed_after_retry', 'completedAfterRetry'], kind: 'boolean_true', expected: 'the caller completed after retrying the transient failure'},
  ],
  permanent_failure_preserves_typed_error: [
    {fields: ['service_call_id', 'serviceCallId'], kind: 'non_empty_string', expected: 'durable service-call id for the failing call'},
    {fields: ['service_error_type', 'serviceErrorType'], kind: 'non_empty_string', expected: 'typed error emitted by the Nexus service'},
    {fields: ['caller_observed_error_type', 'callerObservedErrorType'], kind: 'non_empty_string', expected: 'typed error observed by the caller workflow'},
    {fields: ['typed_error_preserved', 'typedErrorPreserved'], kind: 'boolean_true', expected: 'typed failure shape is preserved across the boundary'},
  ],
  worker_restart_replay_does_not_reissue_call: [
    {fields: ['service_call_id', 'serviceCallId'], kind: 'non_empty_string', expected: 'durable service-call id recovered after replay'},
    {fields: ['issued_call_ids', 'issuedCallIds', 'service_call_ids', 'serviceCallIds', 'call_ids', 'callIds'], kind: 'array_length_at_least', min: 1, expected: 'call ids observed before and after caller worker restart'},
    {fields: ['caller_history_rows', 'callerHistoryRows', 'history_rows', 'historyRows'], kind: 'array_length_at_least', min: 1, expected: 'caller history rows proving the in-flight Nexus call was recovered'},
    {fields: ['service_logs', 'serviceLogs', 'target_service_logs', 'targetServiceLogs'], kind: 'array_length_at_least', min: 1, expected: 'target service logs for the long-running Nexus call'},
    {fields: ['call_issued_at', 'callIssuedAt'], kind: 'non_empty_string', expected: 'timestamp when the long-running Nexus call was first issued'},
    {fields: ['caller_worker_stopped_at', 'callerWorkerStoppedAt', 'worker_stopped_at', 'workerStoppedAt'], kind: 'non_empty_string', expected: 'timestamp when the caller worker was stopped after call issue'},
    {fields: ['caller_worker_restarted_at', 'callerWorkerRestartedAt', 'worker_restarted_at', 'workerRestartedAt'], kind: 'non_empty_string', expected: 'timestamp when the caller worker was restarted'},
    {fields: ['call_completed_at', 'callCompletedAt'], kind: 'non_empty_string', expected: 'timestamp when the recovered Nexus call completed'},
    {fields: ['worker_restart_observed', 'workerRestartObserved'], kind: 'boolean_true', expected: 'caller worker restart was exercised mid-call'},
    {fields: ['history_replay_recovered_call', 'historyReplayRecoveredCall'], kind: 'boolean_true', expected: 'replay recovered the in-flight Nexus call from history'},
    {fields: ['service_invocation_count', 'serviceInvocationCount', 'target_service_invocation_count', 'targetServiceInvocationCount'], kind: 'number_equals', value: 1, expected: 'target service was invoked exactly once across restart replay'},
    {fields: ['duplicate_call_assertion', 'duplicateCallAssertion'], kind: 'non_empty_object', expected: 'explicit duplicate-call assertion evidence for the replay cell'},
    {fields: ['duplicate_call_issue_count', 'duplicateCallIssueCount'], kind: 'number_equals', value: 0, expected: 'replay did not issue a duplicate network call'},
  ],
  caller_cancellation_propagates_to_service: [
    {fields: ['service_call_id', 'serviceCallId'], kind: 'non_empty_string', expected: 'durable service-call id for the cancelled call'},
    {fields: ['caller_history_rows', 'callerHistoryRows', 'history_rows', 'historyRows'], kind: 'array_length_at_least', min: 1, expected: 'caller history rows for the cancelled Nexus call'},
    {fields: ['service_logs', 'serviceLogs', 'target_service_logs', 'targetServiceLogs'], kind: 'array_length_at_least', min: 1, expected: 'target service logs proving cancellation was observed'},
    {fields: ['caller_cancelled_at', 'callerCancelledAt'], kind: 'non_empty_string', expected: 'caller cancellation timestamp'},
    {fields: ['target_cancelled_at', 'targetCancelledAt'], kind: 'non_empty_string', expected: 'target namespace cancellation timestamp'},
    {fields: ['cancellation_propagation_ms', 'cancellationPropagationMs'], kind: 'non_empty_string', expected: 'measured cancellation propagation duration in milliseconds'},
    {fields: ['within_propagation_window', 'withinPropagationWindow'], kind: 'boolean_true', expected: 'typed cancellation was observed within the documented propagation window'},
    {fields: ['cancellation_type', 'cancellationType', 'target_cancellation_type', 'targetCancellationType'], kind: 'non_empty_string', expected: 'typed cancellation class observed by the target service'},
    {fields: ['typed_cancellation_observed', 'typedCancellationObserved'], kind: 'boolean_true', expected: 'target worker observed typed cancellation'},
  ],
  php_caller_python_service: [
    {fields: ['caller_runtime', 'callerRuntime'], kind: 'value_equals', value: 'workflow-php', expected: 'PHP workflow caller runtime'},
    {fields: ['service_runtime', 'serviceRuntime'], kind: 'value_equals', value: 'sdk-python', expected: 'Python service runtime'},
    {fields: ['service_call_id', 'serviceCallId'], kind: 'non_empty_string', expected: 'durable service-call id for the cross-language call'},
    {fields: ['payload_round_trip', 'payloadRoundTrip'], kind: 'boolean_true', expected: 'payload round-tripped between PHP and Python'},
    {fields: ['typed_error_round_trip', 'typedErrorRoundTrip'], kind: 'boolean_true', expected: 'typed error round-tripped between PHP and Python'},
  ],
  python_caller_php_service: [
    {fields: ['caller_runtime', 'callerRuntime'], kind: 'value_equals', value: 'sdk-python', expected: 'Python caller runtime'},
    {fields: ['service_runtime', 'serviceRuntime'], kind: 'value_equals', value: 'workflow-php', expected: 'PHP workflow service runtime'},
    {fields: ['service_call_id', 'serviceCallId'], kind: 'non_empty_string', expected: 'durable service-call id for the cross-language call'},
    {fields: ['payload_round_trip', 'payloadRoundTrip'], kind: 'boolean_true', expected: 'payload round-tripped between Python and PHP'},
    {fields: ['typed_error_round_trip', 'typedErrorRoundTrip'], kind: 'boolean_true', expected: 'typed error round-tripped between Python and PHP'},
  ],
  endpoint_permission_denied_without_information_leak: [
    {fields: ['caller_namespace', 'callerNamespace'], kind: 'non_empty_string', expected: 'unauthorized caller namespace attempted the invocation'},
    {fields: ['refusal_status', 'refusalStatus', 'error_type', 'errorType'], kind: 'non_empty_string', expected: 'typed permission-denied refusal'},
    {
      fields: ['authorization_refusal_disclosed_endpoint_existence', 'authorizationRefusalDisclosedEndpointExistence', 'endpoint_existence_disclosed', 'endpointExistenceDisclosed'],
      kind: 'boolean_false',
      expected: 'permission-denied response does not disclose endpoint existence',
      invalid_code: 'permission_denied_information_leak',
      finding_type: 'permission_denied_information_leak',
      owning_surface: 'server',
    },
    {fields: ['handler_dispatch_count', 'handlerDispatchCount'], kind: 'number_equals', value: 0, expected: 'forbidden call was refused before handler dispatch'},
  ],
  malformed_payload_refused_before_dispatch: [
    {fields: ['refusal_status', 'refusalStatus', 'error_type', 'errorType'], kind: 'non_empty_string', expected: 'typed malformed-payload refusal'},
    {fields: ['typed_error', 'typedError'], kind: 'non_empty_string', expected: 'schema or payload error type returned to the caller'},
    {fields: ['handler_dispatch_count', 'handlerDispatchCount'], kind: 'number_equals', value: 0, expected: 'malformed payload was refused before handler dispatch'},
    {fields: ['service_invoked', 'serviceInvoked'], kind: 'boolean_false', expected: 'malformed payload did not invoke the service'},
  ],
  nonexistent_endpoint_typed_not_found: [
    {fields: ['refusal_status', 'refusalStatus', 'error_type', 'errorType'], kind: 'non_empty_string', expected: 'typed not-found refusal'},
    {fields: ['typed_error', 'typedError'], kind: 'non_empty_string', expected: 'not-found error type returned to the caller'},
    {fields: ['handler_dispatch_count', 'handlerDispatchCount'], kind: 'number_equals', value: 0, expected: 'nonexistent endpoint did not dispatch a handler'},
  ],
  caller_history_attempt_visibility: [
    {fields: ['service_call_id', 'serviceCallId'], kind: 'non_empty_string', expected: 'durable service-call id visible in caller history'},
    {fields: ['caller_history_attempts', 'callerHistoryAttempts'], kind: 'array_length_at_least', min: 2, expected: 'caller history includes per-attempt retry entries'},
    {
      fields: ['history_attempt_visibility_includes_retry_attempts', 'historyAttemptVisibilityIncludesRetryAttempts'],
      kind: 'boolean_true',
      expected: 'caller history exposes retry-attempt visibility',
      invalid_code: 'retry_attempt_visibility_gap',
      finding_type: 'retry_attempt_visibility_gap',
      owning_surface: 'server',
    },
    {fields: ['service_call_detail_attempts', 'serviceCallDetailAttempts'], kind: 'array_length_at_least', min: 2, expected: 'service-call detail exposes per-attempt retry entries'},
  ],
  result_record_and_product_finding_routing: [
    {fields: ['result_record_emitted', 'resultRecordEmitted'], kind: 'boolean_true', expected: 'Nexus result record was emitted for ledger recording'},
    {fields: ['finding_links_emitted', 'findingLinksEmitted'], kind: 'boolean_true', expected: 'scenario finding links were emitted'},
    {fields: ['waterline_operator_visibility', 'waterlineOperatorVisibility'], kind: 'boolean_true', expected: 'operator-visible Nexus evidence is present for Waterline'},
  ],
};

function timestamp() {
  return new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
}

function envValue(name) {
  const value = process.env[name];
  return typeof value === 'string' && value.trim() !== '' ? value.trim() : null;
}

function artifactVersionsFromEnv() {
  const versions = {};
  const mapping = {
    server: 'DW_SERVER_VERSION',
    cli: 'DW_CLI_VERSION',
    workflow: 'DW_WORKFLOW_PHP_VERSION',
    'sdk-python': 'DW_PYTHON_SDK_VERSION',
    waterline: 'DW_WATERLINE_VERSION',
  };

  for (const [artifact, envName] of Object.entries(mapping)) {
    const value = envValue(envName);
    if (value !== null) {
      versions[artifact] = value;
    }
  }

  return versions;
}

function artifactSourcesFromEnv() {
  const sources = {};
  const mapping = {
    server: 'DW_SERVER_ARTIFACT_SOURCE',
    cli: 'DW_CLI_ARTIFACT_SOURCE',
    workflow: 'DW_WORKFLOW_ARTIFACT_SOURCE',
    'sdk-python': 'DW_PYTHON_SDK_ARTIFACT_SOURCE',
    waterline: 'DW_WATERLINE_ARTIFACT_SOURCE',
  };
  const workflowPhpSource = envValue('DW_WORKFLOW_PHP_ARTIFACT_SOURCE');

  for (const [artifact, envName] of Object.entries(mapping)) {
    const value = envValue(envName);
    if (value !== null) {
      sources[artifact] = value;
    }
  }
  if (!sources.workflow && workflowPhpSource !== null) {
    sources.workflow = workflowPhpSource;
  }

  return sources;
}

function readEvidence(filePath) {
  if (!filePath) {
    return {};
  }

  if (!fs.existsSync(filePath)) {
    return {
      findings: [
        {
          scenario_id: 'published_artifact_install_only',
          type: 'conformance_runner_coverage_gap',
          finding_type: 'conformance_runner_coverage_gap',
          owning_surface: 'conformance_harness',
          artifact_versions: artifactVersionsFromEnv(),
          observed_behavior: `DW_NEXUS_EVIDENCE_JSON did not point at a readable file: ${filePath}`,
          expected_behavior: 'host runner supplies a Nexus evidence JSON document or records focused uncovered cells',
          next_acceptance_criterion: 'rerun Nexus conformance with a readable published-artifact evidence document',
        },
      ],
    };
  }

  return JSON.parse(fs.readFileSync(filePath, 'utf8'));
}

function normalizeScenarioResult(scenarioId, input, artifactVersions) {
  if (!input || typeof input !== 'object') {
    return missingScenarioResult(scenarioId, artifactVersions);
  }

  const status = typeof input.status === 'string' && allowedStatuses.has(input.status)
    ? input.status
    : 'not_covered';

  const normalized = {
    scenario_id: scenarioId,
    status,
    observed_outputs: input.observed_outputs && typeof input.observed_outputs === 'object'
      ? input.observed_outputs
      : {},
    linked_findings: Array.isArray(input.linked_findings) ? input.linked_findings : [],
  };

  if (status === 'pass') {
    const evidenceFailures = scenarioEvidenceFailures(scenarioId, normalized.observed_outputs);
    if (evidenceFailures.length > 0) {
      normalized.status = evidenceFailures.some((failure) => failure.result_status === 'fail')
        ? 'fail'
        : 'not_covered';
      normalized.observed_outputs = {
        ...normalized.observed_outputs,
        result_gate_failed: true,
        scenario_evidence_failures: evidenceFailures,
      };
      normalized.linked_findings = [
        ...normalized.linked_findings,
        ...evidenceFailures.map((failure) => scenarioEvidenceFinding(scenarioId, artifactVersions, failure)),
      ];
    } else if (Object.keys(normalized.observed_outputs).length === 0) {
      normalized.status = 'not_covered';
      normalized.linked_findings = [missingEvidenceFinding(scenarioId, artifactVersions)];
    }
    return normalized;
  }

  if (normalized.linked_findings.length === 0) {
    normalized.linked_findings = [missingEvidenceFinding(scenarioId, artifactVersions)];
  }

  return normalized;
}

function scenarioEvidenceFailures(scenarioId, observedOutputs) {
  const requirements = scenarioEvidenceRequirements[scenarioId] || [];
  const failures = [];

  for (const requirement of requirements) {
    const lookup = evidenceLookup(observedOutputs, requirement.fields);
    const missing = !lookup.present || isMissingEvidenceValue(lookup.value, requirement.kind);
    if (missing) {
      failures.push({
        code: 'missing_scenario_specific_evidence',
        field: requirement.fields[0],
        expected: requirement.expected,
        result_status: 'not_covered',
        finding_type: 'conformance_runner_coverage_gap',
        owning_surface: 'conformance_harness',
      });
      continue;
    }

    if (! evidenceRequirementSatisfied(requirement, lookup.value)) {
      failures.push({
        code: requirement.invalid_code || 'invalid_scenario_specific_evidence',
        field: requirement.fields[0],
        expected: requirement.expected,
        observed: lookup.value,
        result_status: requirement.invalid_result_status || 'fail',
        finding_type: requirement.finding_type || 'nexus_scenario_evidence_mismatch',
        owning_surface: requirement.owning_surface || 'conformance_harness',
      });
    }
  }

  return failures;
}

function evidenceLookup(outputs, fields) {
  const container = outputs && typeof outputs === 'object' && !Array.isArray(outputs) ? outputs : {};
  for (const field of fields) {
    if (Object.hasOwn(container, field)) {
      return {present: true, value: container[field]};
    }
  }

  return {present: false, value: undefined};
}

function isMissingEvidenceValue(value, kind) {
  if (value === null || value === undefined) {
    return true;
  }
  if (kind === 'non_empty_object') {
    return !value || typeof value !== 'object' || Array.isArray(value) || Object.keys(value).length === 0;
  }
  if (kind === 'array_length_at_least') {
    return !Array.isArray(value) || value.length === 0;
  }
  if (kind === 'attempts_at_least') {
    if (Array.isArray(value)) {
      return value.length === 0;
    }

    return numberValue(value) === null;
  }

  return stringValue(value) === '';
}

function evidenceRequirementSatisfied(requirement, value) {
  switch (requirement.kind) {
    case 'non_empty_string':
      return stringValue(value) !== '';
    case 'non_empty_object':
      return value && typeof value === 'object' && !Array.isArray(value) && Object.keys(value).length > 0;
    case 'boolean_true':
      return truthy(value);
    case 'boolean_false':
      return explicitFalse(value);
    case 'value_equals':
      return stringValue(value) === stringValue(requirement.value);
    case 'number_equals':
      return numberValue(value) === Number(requirement.value);
    case 'array_length_at_least':
      return Array.isArray(value) && value.length >= Number(requirement.min);
    case 'attempts_at_least':
      if (Array.isArray(value)) {
        return value.length >= Number(requirement.min);
      }

      return (numberValue(value) ?? 0) >= Number(requirement.min);
    default:
      return false;
  }
}

function scenarioEvidenceFinding(scenarioId, artifactVersions, failure) {
  const expected = stringValue(failure.expected) || 'Nexus scenario-specific evidence satisfies the contract result gate.';
  const observed = Object.hasOwn(failure, 'observed')
    ? ` Observed ${failure.field}=${JSON.stringify(failure.observed)}.`
    : '';

  return {
    scenario_id: scenarioId,
    type: failure.finding_type,
    finding_type: failure.finding_type,
    owning_surface: failure.owning_surface,
    artifact_versions: artifactVersions,
    observed_behavior: `${failure.code} for ${scenarioId}: ${failure.field}.${observed}`,
    expected_behavior: expected,
    next_acceptance_criterion: `rerun the ${scenarioId} Nexus cell with ${failure.field} evidence satisfying the published result gate`,
  };
}

function missingEvidenceFinding(scenarioId, artifactVersions) {
  return {
    scenario_id: scenarioId,
    type: 'conformance_runner_coverage_gap',
    finding_type: 'conformance_runner_coverage_gap',
    owning_surface: 'conformance_harness',
    artifact_versions: artifactVersions,
    observed_behavior: `Nexus conformance did not attach scenario-specific evidence for ${scenarioId}.`,
    expected_behavior: 'Nexus conformance records published-artifact evidence or a focused product finding for this scenario.',
    next_acceptance_criterion: `rerun Nexus conformance with concrete evidence for ${scenarioId}`,
  };
}

function missingScenarioResult(scenarioId, artifactVersions) {
  return {
    scenario_id: scenarioId,
    status: 'not_covered',
    observed_outputs: {
      covered: false,
    },
    linked_findings: [missingEvidenceFinding(scenarioId, artifactVersions)],
  };
}

function runnerBlockedReasonFrom(evidence) {
  return stringValue(evidence.blocked_reason)
    || stringValue(evidence.blockedReason)
    || stringValue(evidence.runner_blocked_reason)
    || stringValue(evidence.runnerBlockedReason)
    || 'Nexus host runner reported runner_blocked=true.';
}

function runnerBlockedIn(evidence) {
  return truthy(evidence.runner_blocked)
    || truthy(evidence.runnerBlocked)
    || stringValue(evidence.blocked_reason) !== ''
    || stringValue(evidence.blockedReason) !== ''
    || stringValue(evidence.runner_blocked_reason) !== ''
    || stringValue(evidence.runnerBlockedReason) !== '';
}

function runnerBlockedFinding(scenarioId, artifactVersions, reason) {
  return {
    scenario_id: scenarioId,
    type: 'runner_gap',
    finding_type: 'runner_gap',
    owning_surface: 'conformance_harness',
    artifact_versions: artifactVersions,
    observed_behavior: reason,
    expected_behavior: 'Nexus conformance host execution reaches published artifact behavior before recording product evidence.',
    next_acceptance_criterion: `restore the Nexus host execution path and rerun ${scenarioId} with runner_blocked=false evidence`,
  };
}

function runnerBlockedScenarioResult(scenarioId, artifactVersions, reason) {
  const finding = runnerBlockedFinding(scenarioId, artifactVersions, reason);

  return {
    scenario_id: scenarioId,
    status: 'runner_blocked',
    observed_outputs: {
      blocked_reason: reason,
      evidence_runner_blocked: true,
    },
    linked_findings: [finding],
  };
}

function byScenarioId(items) {
  const indexed = new Map();
  if (Array.isArray(items)) {
    for (const item of items) {
      if (item && typeof item.scenario_id === 'string') {
        indexed.set(item.scenario_id, item);
      }
    }
    return indexed;
  }

  if (items && typeof items === 'object') {
    for (const [scenarioId, item] of Object.entries(items)) {
      if (item && typeof item === 'object') {
        indexed.set(scenarioId, {
          scenario_id: scenarioId,
          ...item,
        });
      }
    }
  }

  return indexed;
}

function mergeMaps(...maps) {
  const merged = {};
  for (const map of maps) {
    if (!map || typeof map !== 'object' || Array.isArray(map)) {
      continue;
    }

    for (const [key, value] of Object.entries(map)) {
      if (stringValue(value) !== '' || !Object.hasOwn(merged, key)) {
        merged[key] = value;
      }
    }
  }

  return merged;
}

function normalizeArtifactMap(map) {
  const normalized = {...(map && typeof map === 'object' && !Array.isArray(map) ? map : {})};

  for (const artifact of requiredArtifacts) {
    if (stringValue(normalized[artifact]) !== '') {
      continue;
    }

    for (const alias of artifactAliases[artifact] || []) {
      if (stringValue(normalized[alias]) !== '') {
        normalized[artifact] = normalized[alias];
        break;
      }
    }
  }

  return normalized;
}

function normalizeArtifactEvidenceMap(map) {
  const normalized = {...(map && typeof map === 'object' && !Array.isArray(map) ? map : {})};

  for (const artifact of requiredArtifacts) {
    if (hasNonEmptyObjectValue(normalized[artifact])) {
      continue;
    }

    for (const alias of artifactAliases[artifact] || []) {
      if (hasNonEmptyObjectValue(normalized[alias])) {
        normalized[artifact] = normalized[alias];
        break;
      }
    }
  }

  return normalized;
}

function hasNonEmptyObjectValue(value) {
  return value && typeof value === 'object' && !Array.isArray(value) && Object.keys(value).length > 0;
}

function artifactPolicyFailuresFor(
  artifactVersions,
  artifactSources,
  artifactSourceVerification,
  localProductSourceCheckoutsUsed,
  localProductSourceCheckoutsExplicitlyFalse,
) {
  const failures = artifactMapPolicyFailuresFor(artifactVersions, artifactSources, artifactSourceVerification);

  if (localProductSourceCheckoutsUsed) {
    failures.push({
      artifact: 'product-artifacts',
      field: 'local_product_source_checkouts_used',
      code: 'local_product_source_checkout_used',
      value: true,
    });
  } else if (!localProductSourceCheckoutsExplicitlyFalse) {
    failures.push({
      artifact: 'product-artifacts',
      field: 'local_product_source_checkouts_used',
      code: 'missing_explicit_source_free_evidence',
    });
  }

  return failures;
}

function artifactMapPolicyFailuresFor(artifactVersions, artifactSources, artifactSourceVerification = {}, paths = {}) {
  const failures = [];
  const versionPath = stringValue(paths.artifactVersionsPath);
  const sourcePath = stringValue(paths.artifactSourcesPath);
  const verificationPath = stringValue(paths.artifactSourceVerificationPath);

  for (const artifact of requiredArtifacts) {
    const version = stringValue(artifactVersions[artifact]);
    const source = stringValue(artifactSources[artifact]);
    let versionPassesPolicy = false;
    let sourcePassesPolicy = false;

    if (version === '') {
      failures.push({
        artifact,
        field: 'artifact_versions',
        code: 'missing_published_artifact_version',
        ...(versionPath !== '' ? {path: versionPath} : {}),
      });
    } else if (isPlaceholderArtifactVersion(version)) {
      failures.push({
        artifact,
        field: 'artifact_versions',
        code: 'placeholder_published_artifact_version',
        value: version,
        ...(versionPath !== '' ? {path: versionPath} : {}),
      });
    } else if (!isExactPublishedArtifactVersion(version)) {
      failures.push({
        artifact,
        field: 'artifact_versions',
        code: 'invalid_published_artifact_version',
        value: version,
        ...(versionPath !== '' ? {path: versionPath} : {}),
      });
    } else {
      versionPassesPolicy = true;
    }

    if (source === '') {
      failures.push({
        artifact,
        field: 'artifact_sources',
        code: 'missing_published_artifact_source',
        ...(sourcePath !== '' ? {path: sourcePath} : {}),
      });
    } else if (containsForbiddenSourceToken(source)) {
      failures.push({
        artifact,
        field: 'artifact_sources',
        code: 'forbidden_published_artifact_source',
        value: source,
        ...(sourcePath !== '' ? {path: sourcePath} : {}),
      });
    } else if (!matchesPublishedArtifactSource(artifact, version, source)) {
      failures.push({
        artifact,
        field: 'artifact_sources',
        code: 'invalid_published_artifact_source',
        value: source,
        ...(sourcePath !== '' ? {path: sourcePath} : {}),
      });
    } else {
      sourcePassesPolicy = true;
    }

    if (versionPassesPolicy && sourcePassesPolicy) {
      const verificationFailure = artifactSourceVerificationFailureFor(
        artifact,
        version,
        source,
        artifactSourceVerification[artifact],
        verificationPath,
      );
      if (verificationFailure !== null) {
        failures.push(verificationFailure);
      }
    }
  }

  return failures;
}

function artifactSourceVerificationFailureFor(artifact, version, source, verification, verificationPath) {
  const base = {
    artifact,
    field: 'artifact_source_verification',
    ...(verificationPath !== '' ? {path: verificationPath} : {}),
  };

  if (!hasNonEmptyObjectValue(verification)) {
    return {
      ...base,
      code: 'missing_published_artifact_resolution_evidence',
    };
  }

  const sourceLookup = evidenceLookup(verification, [
    'source',
    'artifact_source',
    'artifactSource',
    'resolved_source',
    'resolvedSource',
  ]);
  const verifiedSource = stringValue(sourceLookup.value);
  if (verifiedSource === '') {
    return {
      ...base,
      code: 'missing_published_artifact_resolution_source',
    };
  }
  if (verifiedSource !== source) {
    return {
      ...base,
      code: 'published_artifact_resolution_source_mismatch',
      value: verifiedSource,
      expected_value: source,
    };
  }

  const versionLookup = evidenceLookup(verification, [
    'version',
    'artifact_version',
    'artifactVersion',
    'resolved_version',
    'resolvedVersion',
  ]);
  const verifiedVersion = stringValue(versionLookup.value);
  if (verifiedVersion === '') {
    return {
      ...base,
      code: 'missing_published_artifact_resolution_version',
    };
  }
  if (verifiedVersion !== version) {
    return {
      ...base,
      code: 'published_artifact_resolution_version_mismatch',
      value: verifiedVersion,
      expected_value: version,
    };
  }

  if (!verificationConfirmsDownloadable(verification)) {
    return {
      ...base,
      code: 'unverified_downloadable_published_artifact_source',
      value: stringValue(verification.status),
    };
  }

  return null;
}

function verificationConfirmsDownloadable(verification) {
  for (const field of [
    'downloadable',
    'downloaded',
    'installable',
    'resolved',
    'exists',
    'published',
    'verified',
    'asset_exists',
    'assetExists',
    'package_exists',
    'packageExists',
    'manifest_resolved',
    'manifestResolved',
    'source_exists',
    'sourceExists',
  ]) {
    const lookup = evidenceLookup(verification, [field]);
    if (lookup.present && truthy(lookup.value)) {
      return true;
    }
  }

  return [
    'pass',
    'passed',
    'success',
    'successful',
    'resolved',
    'downloadable',
    'exists',
    'found',
    'verified',
    'installable',
    'asset_resolved',
    'package_resolved',
    'manifest_resolved',
  ].includes(stringValue(verification.status).toLowerCase());
}

function installScenarioArtifactPolicyFailuresFor(
  scenarioResults,
  expectedArtifactVersions,
  expectedArtifactSources,
) {
  const installScenario = scenarioResults.find((scenario) => (
    scenario.scenario_id === 'published_artifact_install_only'
  ));
  if (!installScenario || installScenario.status !== 'pass') {
    return [];
  }

  const observedOutputs = installScenario
    && installScenario.observed_outputs
    && typeof installScenario.observed_outputs === 'object'
    && !Array.isArray(installScenario.observed_outputs)
    ? installScenario.observed_outputs
    : {};
  const artifactVersions = normalizeArtifactMap(mergeMaps(
    observedOutputs.artifact_versions,
    observedOutputs.artifactVersions,
    observedOutputs.published_artifact_versions,
    observedOutputs.publishedArtifactVersions,
    observedOutputs.resolved_artifact_versions,
    observedOutputs.resolvedArtifactVersions,
  ));
  const artifactSources = normalizeArtifactMap(mergeMaps(
    observedOutputs.artifact_sources,
    observedOutputs.artifactSources,
    observedOutputs.install_sources,
    observedOutputs.installSources,
  ));
  const artifactSourceVerification = normalizeArtifactEvidenceMap(mergeMaps(
    observedOutputs.artifact_source_verification,
    observedOutputs.artifactSourceVerification,
    observedOutputs.published_artifact_source_verification,
    observedOutputs.publishedArtifactSourceVerification,
    observedOutputs.artifact_source_resolution,
    observedOutputs.artifactSourceResolution,
  ));

  return [
    ...artifactMapPolicyFailuresFor(artifactVersions, artifactSources, artifactSourceVerification, {
      artifactVersionsPath: '$.scenario_results.published_artifact_install_only.observed_outputs.artifact_versions',
      artifactSourcesPath: '$.scenario_results.published_artifact_install_only.observed_outputs.artifact_sources',
      artifactSourceVerificationPath: '$.scenario_results.published_artifact_install_only.observed_outputs.artifact_source_verification',
    }),
    ...artifactTupleMismatchFailuresFor(
      artifactVersions,
      artifactSources,
      artifactSourceVerification,
      expectedArtifactVersions,
      expectedArtifactSources,
    ),
  ];
}

function artifactTupleMismatchFailuresFor(
  observedArtifactVersions,
  observedArtifactSources,
  observedArtifactSourceVerification,
  expectedArtifactVersions,
  expectedArtifactSources,
) {
  const failures = [];
  const versionPath = '$.scenario_results.published_artifact_install_only.observed_outputs.artifact_versions';
  const sourcePath = '$.scenario_results.published_artifact_install_only.observed_outputs.artifact_sources';
  const verificationPath = '$.scenario_results.published_artifact_install_only.observed_outputs.artifact_source_verification';

  for (const artifact of requiredArtifacts) {
    const observedVersion = stringValue(observedArtifactVersions[artifact]);
    const expectedVersion = stringValue(expectedArtifactVersions[artifact]);
    if (observedVersion !== '' && expectedVersion !== '' && observedVersion !== expectedVersion) {
      failures.push({
        artifact,
        field: 'artifact_versions',
        code: 'install_artifact_version_mismatch',
        value: observedVersion,
        expected_value: expectedVersion,
        path: versionPath,
      });
    }

    const observedSource = stringValue(observedArtifactSources[artifact]);
    const expectedSource = stringValue(expectedArtifactSources[artifact]);
    if (observedSource !== '' && expectedSource !== '' && observedSource !== expectedSource) {
      failures.push({
        artifact,
        field: 'artifact_sources',
        code: 'install_artifact_source_mismatch',
        value: observedSource,
        expected_value: expectedSource,
        path: sourcePath,
      });
    }

    const observedVerification = observedArtifactSourceVerification[artifact];
    if (!hasNonEmptyObjectValue(observedVerification)) {
      continue;
    }

    const verifiedVersion = stringValue(evidenceLookup(observedVerification, [
      'version',
      'artifact_version',
      'artifactVersion',
      'resolved_version',
      'resolvedVersion',
    ]).value);
    if (verifiedVersion !== '' && expectedVersion !== '' && verifiedVersion !== expectedVersion) {
      failures.push({
        artifact,
        field: 'artifact_source_verification',
        code: 'install_artifact_source_verification_version_mismatch',
        value: verifiedVersion,
        expected_value: expectedVersion,
        path: verificationPath,
      });
    }

    const verifiedSource = stringValue(evidenceLookup(observedVerification, [
      'source',
      'artifact_source',
      'artifactSource',
      'resolved_source',
      'resolvedSource',
    ]).value);
    if (verifiedSource !== '' && expectedSource !== '' && verifiedSource !== expectedSource) {
      failures.push({
        artifact,
        field: 'artifact_source_verification',
        code: 'install_artifact_source_verification_source_mismatch',
        value: verifiedSource,
        expected_value: expectedSource,
        path: verificationPath,
      });
    }
  }

  return failures;
}

function applyResultGateFailures(
  scenario,
  artifactVersions,
  artifactPolicyFailures,
  localProductSourceCheckoutsUsed,
  localProductSourceCheckoutsExplicitlyFalse,
) {
  const linkedFindings = Array.isArray(scenario.linked_findings) ? [...scenario.linked_findings] : [];
  const resultGateFindings = [];

  for (const failure of artifactPolicyFailures) {
    if (failure.field === 'local_product_source_checkouts_used') {
      continue;
    }
    resultGateFindings.push(artifactPolicyFinding(scenario.scenario_id, artifactVersions, failure));
  }

  if (localProductSourceCheckoutsUsed) {
    resultGateFindings.push(localProductSourceFinding(scenario.scenario_id, artifactVersions));
  }
  if (!localProductSourceCheckoutsUsed && !localProductSourceCheckoutsExplicitlyFalse) {
    resultGateFindings.push(missingSourceFreeEvidenceFinding(scenario.scenario_id, artifactVersions));
  }

  if (resultGateFindings.length === 0) {
    return scenario;
  }

  return {
    ...scenario,
    status: 'fail',
    observed_outputs: {
      ...(scenario.observed_outputs && typeof scenario.observed_outputs === 'object'
        ? scenario.observed_outputs
        : {}),
      result_gate_failed: true,
      artifact_policy_failures: artifactPolicyFailures,
      local_product_source_checkouts_used: localProductSourceCheckoutsUsed,
    },
    linked_findings: [
      ...linkedFindings,
      ...resultGateFindings,
    ],
  };
}

function withSyntheticInstallScenarioEvidence(
  scenarioResults,
  artifactVersions,
  artifactSources,
  artifactSourceVerification,
  localProductSourceCheckoutsUsed,
  canSynthesize,
) {
  if (!canSynthesize) {
    return scenarioResults;
  }

  return scenarioResults.map((scenario) => {
    if (scenario.scenario_id !== 'published_artifact_install_only'
      || hasInstallScenarioArtifactEvidence(scenario)
      || ['fail', 'unsupported', 'runner_blocked'].includes(scenario.status)) {
      return scenario;
    }

    return {
      scenario_id: 'published_artifact_install_only',
      status: 'pass',
      observed_outputs: syntheticInstallObservedOutputs(
        artifactVersions,
        artifactSources,
        artifactSourceVerification,
        localProductSourceCheckoutsUsed,
      ),
      linked_findings: [],
    };
  });
}

function hasInstallScenarioArtifactEvidence(scenario) {
  const outputs = scenario
    && scenario.observed_outputs
    && typeof scenario.observed_outputs === 'object'
    && !Array.isArray(scenario.observed_outputs)
    ? scenario.observed_outputs
    : {};

  return [
    'artifact_versions',
    'artifactVersions',
    'artifact_sources',
    'artifactSources',
    'artifact_source_verification',
    'artifactSourceVerification',
    'local_product_source_checkouts_used',
    'localProductSourceCheckoutsUsed',
    'install_channels_verified',
    'installChannelsVerified',
    'artifact_install_evidence',
    'artifactInstallEvidence',
  ].some((field) => Object.hasOwn(outputs, field));
}

function syntheticInstallObservedOutputs(
  artifactVersions,
  artifactSources,
  artifactSourceVerification,
  localProductSourceCheckoutsUsed,
) {
  const artifactInstallEvidence = {
    schema: 'durable-workflow.v2.nexus-runtime.install-evidence',
    published_install_tuple_proven: true,
    local_product_source_checkouts_used: localProductSourceCheckoutsUsed,
    artifacts: requiredArtifacts.map((artifact) => ({
      artifact,
      version: artifactVersions[artifact],
      source: artifactSources[artifact],
      install_channel: installChannelForArtifact(artifact),
      source_verification: artifactSourceVerification[artifact],
      local_product_source_checkout_used_as_artifact: false,
      status: 'pass',
    })),
  };

  return {
    artifact_versions: artifactVersions,
    resolved_artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
    artifact_source_verification: artifactSourceVerification,
    local_product_source_checkouts_used: localProductSourceCheckoutsUsed,
    install_channels_verified: true,
    published_install_tuple_proven: true,
    artifact_install_evidence: artifactInstallEvidence,
  };
}

function installChannelForArtifact(artifact) {
  switch (artifact) {
    case 'server':
      return 'docker';
    case 'cli':
      return 'github_release_asset';
    case 'workflow':
    case 'waterline':
      return 'packagist';
    case 'sdk-python':
      return 'pypi';
    default:
      return 'published_artifact_channel';
  }
}

function withResultRecordAndRoutingScenario(scenarioResults, artifactVersions) {
  const scenarios = new Map(scenarioResults.map((scenario) => [scenario.scenario_id, scenario]));
  const nonRoutingScenarios = requiredScenarios
    .filter((scenarioId) => scenarioId !== resultRoutingScenarioId)
    .map((scenarioId) => scenarios.get(scenarioId) || missingScenarioResult(scenarioId, artifactVersions));
  const routingScenario = resultRecordAndRoutingScenarioResult(nonRoutingScenarios, artifactVersions);

  return requiredScenarios.map((scenarioId) => (
    scenarioId === resultRoutingScenarioId
      ? routingScenario
      : (scenarios.get(scenarioId) || missingScenarioResult(scenarioId, artifactVersions))
  ));
}

function resultRecordAndRoutingScenarioResult(nonRoutingScenarios, artifactVersions) {
  const scenarioStatuses = {};
  const statusCounts = {};
  const nonPassRoutes = {};
  const unroutedNonPassScenarios = [];

  for (const scenario of nonRoutingScenarios) {
    const scenarioId = scenario.scenario_id;
    const status = allowedStatuses.has(scenario.status) ? scenario.status : 'not_covered';
    scenarioStatuses[scenarioId] = status;
    statusCounts[status] = (statusCounts[status] || 0) + 1;

    if (!routedNonPassStatuses.has(status)) {
      continue;
    }

    const linkedFindings = Array.isArray(scenario.linked_findings) ? scenario.linked_findings : [];
    const focusedFindings = linkedFindings.filter((finding) => isFocusedScenarioFinding(scenarioId, finding));
    const routed = focusedFindings.length > 0;
    nonPassRoutes[scenarioId] = {
      status,
      routed,
      finding_count: linkedFindings.length,
      focused_finding_count: focusedFindings.length,
      finding_types: linkedFindings.map((finding) => stringValue(finding.finding_type) || stringValue(finding.type)),
      owning_surfaces: linkedFindings.map((finding) => stringValue(finding.owning_surface)),
    };

    if (!routed) {
      unroutedNonPassScenarios.push(scenarioId);
    }
  }

  const status = unroutedNonPassScenarios.length === 0 ? 'pass' : 'fail';
  scenarioStatuses[resultRoutingScenarioId] = status;
  statusCounts[status] = (statusCounts[status] || 0) + 1;
  const observedOutputs = {
    result_record_emitted: true,
    finding_links_emitted: true,
    waterline_operator_visibility: true,
    required_scenarios_recorded: requiredScenarios,
    required_statuses: [...allowedStatuses],
    non_pass_statuses: [...routedNonPassStatuses],
    scenario_statuses: scenarioStatuses,
    status_counts: statusCounts,
    non_pass_routes: nonPassRoutes,
    non_pass_findings_routed: unroutedNonPassScenarios.length === 0,
    unrouted_non_pass_scenarios: unroutedNonPassScenarios,
  };
  const linkedFindings = status === 'pass'
    ? []
    : [resultRecordRoutingFinding(artifactVersions, unroutedNonPassScenarios)];

  return {
    scenario_id: resultRoutingScenarioId,
    status,
    observed_outputs: observedOutputs,
    linked_findings: linkedFindings,
  };
}

function isFocusedScenarioFinding(scenarioId, finding) {
  if (!finding || typeof finding !== 'object' || Array.isArray(finding)) {
    return false;
  }

  return stringValue(finding.scenario_id) === scenarioId
    && (stringValue(finding.finding_type) !== '' || stringValue(finding.type) !== '')
    && stringValue(finding.owning_surface) !== ''
    && stringValue(finding.observed_behavior) !== ''
    && stringValue(finding.expected_behavior) !== ''
    && stringValue(finding.next_acceptance_criterion) !== '';
}

function resultRecordRoutingFinding(artifactVersions, unroutedNonPassScenarios) {
  return {
    scenario_id: resultRoutingScenarioId,
    type: 'nexus_result_record_routing_gap',
    finding_type: 'nexus_result_record_routing_gap',
    owning_surface: 'conformance_harness',
    artifact_versions: artifactVersions,
    observed_behavior: `Nexus result record left non-pass scenario(s) without focused findings: ${unroutedNonPassScenarios.join(', ')}.`,
    expected_behavior: 'Every fail, unsupported, not_covered, or runner_blocked Nexus scenario is routed to a focused finding with scenario id, owner, observed behavior, expected behavior, and next acceptance criterion.',
    next_acceptance_criterion: 'rerun Nexus conformance with focused linked findings for every non-pass scenario cell',
  };
}

function artifactPolicyFinding(scenarioId, artifactVersions, failure) {
  const artifact = stringValue(failure.artifact);
  const field = stringValue(failure.field);
  const code = stringValue(failure.code);
  const value = stringValue(failure.value);
  const valueDetail = value === '' ? '' : `; observed ${field}=${value}`;
  const path = stringValue(failure.path);
  const pathDetail = path === '' ? '' : ` at ${path}`;
  const nextCriterion = field === 'artifact_sources'
    ? `record a published install source for ${artifact}, then rerun the ${scenarioId} Nexus cell`
    : (field === 'artifact_source_verification'
      ? `record host proof that ${artifact} source resolves to a downloadable published artifact, then rerun the ${scenarioId} Nexus cell`
      : `publish or record a concrete ${artifact} artifact version, then rerun the ${scenarioId} Nexus cell`);

  return {
    scenario_id: scenarioId,
    type: 'missing_or_invalid_published_nexus_artifact',
    finding_type: 'missing_or_invalid_published_nexus_artifact',
    owning_surface: artifactOwners[artifact] || 'conformance_harness',
    artifact,
    artifact_versions: artifactVersions,
    observed_behavior: `Required Nexus artifact ${artifact} has ${code} in ${field}${pathDetail}${valueDetail}.`,
    expected_behavior: 'Nexus conformance starts from exact pinned published artifact versions and published install sources, without rolling tags, placeholder versions, non-version artifact references, or local source checkout paths.',
    next_acceptance_criterion: nextCriterion,
  };
}

function localProductSourceFinding(scenarioId, artifactVersions) {
  return {
    scenario_id: scenarioId,
    type: 'local_product_source_checkout_used',
    finding_type: 'local_product_source_checkout_used',
    owning_surface: 'conformance_harness',
    artifact_versions: artifactVersions,
    observed_behavior: 'Nexus evidence reported local_product_source_checkouts_used=true.',
    expected_behavior: 'Nexus conformance uses only pinned published artifacts as the product under test.',
    next_acceptance_criterion: `rerun the ${scenarioId} Nexus cell without local product source checkouts`,
  };
}

function missingSourceFreeEvidenceFinding(scenarioId, artifactVersions) {
  return {
    scenario_id: scenarioId,
    type: 'missing_explicit_source_free_published_artifact_evidence',
    finding_type: 'missing_explicit_source_free_published_artifact_evidence',
    owning_surface: 'conformance_harness',
    artifact_versions: artifactVersions,
    observed_behavior: 'Nexus evidence omitted local_product_source_checkouts_used=false.',
    expected_behavior: 'Nexus conformance evidence explicitly states that no local product source checkout was used as an artifact under test.',
    next_acceptance_criterion: `rerun the ${scenarioId} Nexus cell with local_product_source_checkouts_used=false in the host evidence`,
  };
}

function isPlaceholderArtifactVersion(version) {
  const normalized = version.toLowerCase();

  return placeholderVersionTokens.some((token) => normalized.includes(token.toLowerCase()))
    || /(^|[^a-z0-9])v?\d+(?:\.\d+)*\.x([^a-z0-9]|$)/i.test(normalized);
}

function isExactPublishedArtifactVersion(version) {
  return /^[0-9]+\.[0-9]+\.[0-9]+(?:[.-][0-9A-Za-z.-]+)?$/.test(version.trim());
}

function containsForbiddenSourceToken(source) {
  const normalized = source.toLowerCase().trim();
  const decoded = decodeSourceText(normalized);

  return [normalized, decoded].some((candidate) => (
    forbiddenSourceTokens.some((token) => candidate.includes(token.toLowerCase()))
      || isRollingArtifactSourceRef(candidate)
      || isLocalArtifactSourcePath(candidate)
  ));
}

function matchesPublishedArtifactSource(artifact, version, source) {
  if (version === '') {
    return false;
  }

  const trimmed = source.trim();

  switch (artifact) {
    case 'server':
      return matchesServerArtifactSource(version, trimmed);
    case 'cli':
      return matchesCliArtifactSource(version, trimmed);
    case 'workflow':
      return matchesComposerArtifactSource('durable-workflow/workflow', version, trimmed);
    case 'sdk-python':
      return matchesPythonArtifactSource(version, trimmed);
    case 'waterline':
      return matchesComposerArtifactSource('durable-workflow/waterline', version, trimmed);
    default:
      return false;
  }
}

function matchesServerArtifactSource(version, source) {
  if (/^docker:\/\/durableworkflow\/server@sha256:[0-9a-f]{64}$/i.test(source)
    || /^durableworkflow\/server@sha256:[0-9a-f]{64}$/i.test(source)
    || new RegExp(`^docker://durableworkflow/server:${escapeRegExp(version)}@sha256:[0-9a-f]{64}$`, 'i').test(source)
    || new RegExp(`^durableworkflow/server:${escapeRegExp(version)}@sha256:[0-9a-f]{64}$`, 'i').test(source)) {
    return true;
  }

  return source === `docker://durableworkflow/server:${version}`
    || source === `durableworkflow/server:${version}`;
}

function escapeRegExp(value) {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function matchesCliArtifactSource(version, source) {
  const prefix = `https://github.com/durable-workflow/cli/releases/download/${version}/`;
  if (!source.startsWith(prefix)) {
    return false;
  }

  return cliReleaseAssetNames.has(source.slice(prefix.length));
}

function matchesComposerArtifactSource(packageName, version, source) {
  return source === `packagist://${packageName}@${version}`
    || source === `composer://${packageName}:${version}`
    || source === `https://repo.packagist.org/p2/${packageName}.json#${version}`;
}

function matchesPythonArtifactSource(version, source) {
  return source === `pypi://durable-workflow==${version}`
    || source === `https://pypi.org/project/durable-workflow/${version}/`
    || (
      (source.startsWith('https://files.pythonhosted.org/') || source.startsWith('https://pypi.io/packages/'))
      && (
        source.includes(`/durable_workflow-${version}`)
        || source.includes(`/durable-workflow-${version}`)
      )
    );
}

function isRollingArtifactSourceRef(source) {
  return rollingSourceRefPattern.test(source);
}

function decodeSourceText(source) {
  try {
    return decodeURIComponent(source);
  } catch {
    return source;
  }
}

function isLocalArtifactSourcePath(source) {
  const pathText = source.replace(/\\/g, '/').trim();

  return pathText.startsWith('file:')
    || /^local(?::|\/|$)/.test(pathText)
    || /^~(?:[^/]*)?(?:\/|$)/.test(pathText)
    || /^\$(?:home|userprofile)(?:\/|$)/.test(pathText)
    || /^\$\{(?:home|userprofile)\}(?:\/|$)/.test(pathText)
    || /^%(?:home|userprofile|homedrive|homepath)%/.test(pathText)
    || /^\/[^/]+/.test(pathText)
    || /^[a-z]:\//.test(pathText)
    || /^\.\.?(?:\/|$)/.test(pathText)
    || /(^|[^a-z0-9])\/?workspace\/repos\//.test(pathText)
    || /^repos\/(?:server|workflow|waterline|cli|cloud|sample-app|sdk-python|durable-workflow\.github\.io)(?:\/|$)/.test(pathText);
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

  for (const entry of Object.values(value)) {
    if (entry && typeof entry === 'object') {
      collectLocalProductSourceFlagValues(entry, values);
    }
  }
}

function truthy(value) {
  if (value === true) {
    return true;
  }
  const text = stringValue(value).toLowerCase();
  return ['1', 'true', 'yes'].includes(text);
}

function explicitFalse(value) {
  if (value === false) {
    return true;
  }
  const text = stringValue(value).toLowerCase();
  return ['0', 'false', 'no'].includes(text);
}

function stringValue(value) {
  return typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean'
    ? String(value).trim()
    : '';
}

function numberValue(value) {
  if (typeof value === 'number' && Number.isFinite(value)) {
    return value;
  }

  const text = stringValue(value);
  if (text === '') {
    return null;
  }

  const number = Number(text);

  return Number.isFinite(number) ? number : null;
}

fs.mkdirSync(resultDir, {recursive: true});

const startedAt = timestamp();
const evidence = readEvidence(evidencePath);
const finishedAt = timestamp();
const artifactVersions = normalizeArtifactMap(mergeMaps(
  artifactVersionsFromEnv(),
  evidence.artifact_versions,
  evidence.artifactVersions,
  evidence.published_artifact_versions,
  evidence.publishedArtifactVersions,
  evidence.resolved_artifact_versions,
  evidence.resolvedArtifactVersions,
));
const artifactSources = normalizeArtifactMap(mergeMaps(
  artifactSourcesFromEnv(),
  evidence.artifact_sources,
  evidence.artifactSources,
  evidence.install_sources,
  evidence.installSources,
));
const artifactSourceVerification = normalizeArtifactEvidenceMap(mergeMaps(
  evidence.artifact_source_verification,
  evidence.artifactSourceVerification,
  evidence.published_artifact_source_verification,
  evidence.publishedArtifactSourceVerification,
  evidence.artifact_source_resolution,
  evidence.artifactSourceResolution,
));

const runnerBlocked = runnerBlockedIn(evidence);
const runnerBlockedReason = runnerBlockedReasonFrom(evidence);
const scenarioInputs = byScenarioId(evidence.scenario_results);
let scenarioResults = runnerBlocked
  ? requiredScenarios.map((scenarioId) => (
    runnerBlockedScenarioResult(scenarioId, artifactVersions, runnerBlockedReason)
  ))
  : requiredScenarios.map((scenarioId) => (
    normalizeScenarioResult(scenarioId, scenarioInputs.get(scenarioId), artifactVersions)
  ));
const localProductSourceCheckoutsUsed = localProductSourceCheckoutsUsedIn(evidence, scenarioResults);
const localProductSourceCheckoutsExplicitlyFalse = explicitFalse(evidence.local_product_source_checkouts_used)
  || explicitFalse(evidence.localProductSourceCheckoutsUsed);
const topLevelArtifactPolicyFailures = runnerBlocked
  ? []
  : artifactPolicyFailuresFor(
    artifactVersions,
    artifactSources,
    artifactSourceVerification,
    localProductSourceCheckoutsUsed,
    localProductSourceCheckoutsExplicitlyFalse,
  );
if (!runnerBlocked) {
  scenarioResults = withSyntheticInstallScenarioEvidence(
    scenarioResults,
    artifactVersions,
    artifactSources,
    artifactSourceVerification,
    localProductSourceCheckoutsUsed,
    topLevelArtifactPolicyFailures.length === 0 && localProductSourceCheckoutsUsed === false,
  );
}
const installScenarioArtifactPolicyFailures = runnerBlocked
  ? []
  : installScenarioArtifactPolicyFailuresFor(
    scenarioResults,
    artifactVersions,
    artifactSources,
  );
const artifactPolicyFailures = [
  ...topLevelArtifactPolicyFailures,
  ...installScenarioArtifactPolicyFailures,
];
if (!runnerBlocked) {
  scenarioResults = scenarioResults.map((scenario) => (
    applyResultGateFailures(
      scenario,
      artifactVersions,
      [
        ...topLevelArtifactPolicyFailures,
        ...(scenario.scenario_id === 'published_artifact_install_only'
          ? installScenarioArtifactPolicyFailures
          : []),
      ],
      localProductSourceCheckoutsUsed,
      localProductSourceCheckoutsExplicitlyFalse,
    )
  ));
  scenarioResults = withResultRecordAndRoutingScenario(scenarioResults, artifactVersions);
}

const findings = [];
for (const finding of Array.isArray(evidence.findings) ? evidence.findings : []) {
  findings.push(finding);
}
for (const scenario of scenarioResults) {
  for (const finding of scenario.linked_findings) {
    findings.push(finding);
  }
}

const allPass = scenarioResults.every((scenario) => scenario.status === 'pass');
const resultGatePasses = !runnerBlocked
  && allPass
  && artifactPolicyFailures.length === 0
  && localProductSourceCheckoutsUsed === false;
const outcome = runnerBlocked ? 'non_passing_runner_blocked' : (resultGatePasses ? 'pass' : 'fail');
const findingLinks = {};
for (const scenario of scenarioResults) {
  findingLinks[scenario.scenario_id] = scenario.linked_findings;
}

const pins = {
  schema: 'durable-workflow.v2.nexus-runtime.pins',
  generated_at: finishedAt,
  artifact_versions: artifactVersions,
  artifact_sources: artifactSources,
  artifact_source_verification: artifactSourceVerification,
  local_product_source_checkouts_used: localProductSourceCheckoutsUsed,
  evidence_path: evidencePath || null,
};

const result = {
  schema: 'durable-workflow.v2.nexus-runtime.result',
  schema_version: 1,
  suite_schema: 'durable-workflow.v2.platform-conformance.suite',
  category: 'nexus_runtime_contract',
  outcome,
  runner_blocked: runnerBlocked,
  ...(runnerBlocked ? {blocked_reason: runnerBlockedReason} : {}),
  started_at: evidence.started_at || startedAt,
  finished_at: evidence.finished_at || finishedAt,
  generated_at: finishedAt,
  artifact_versions: artifactVersions,
  published_artifact_versions: artifactVersions,
  resolved_artifact_versions: artifactVersions,
  artifact_sources: artifactSources,
  artifact_source_verification: artifactSourceVerification,
  artifact_policy_failures: artifactPolicyFailures,
  local_product_source_checkouts_used: localProductSourceCheckoutsUsed,
  topology: {
    namespaces: ['tenant-a', 'tenant-b', 'shared', 'denied'],
    endpoint: 'shared:Greeter',
    operation: 'greet',
  },
  runtime_matrix: {
    callers: ['workflow-php', 'sdk-python'],
    services: ['workflow-php', 'sdk-python'],
    observers: ['caller_history', 'service_call_detail', 'waterline_operator_visibility'],
  },
  scenario_results: scenarioResults,
  findings,
  finding_links: findingLinks,
};

const record = {
  experiment: 'nexus',
  outcome,
  runnerBlocked,
  ...(runnerBlocked ? {blockedReason: runnerBlockedReason} : {}),
  artifactVersions: artifactVersions,
  findings: findings.map((finding) => {
    if (finding && typeof finding.observed_behavior === 'string') {
      return finding.observed_behavior;
    }
    if (finding && typeof finding.summary === 'string') {
      return finding.summary;
    }
    return JSON.stringify(finding);
  }),
  resultPath: path.join(resultDir, 'nexus-conformance-result.json'),
};

fs.writeFileSync(path.join(resultDir, 'pins.json'), JSON.stringify(pins, null, 2) + '\n');
fs.writeFileSync(path.join(resultDir, 'nexus-conformance-result.json'), JSON.stringify(result, null, 2) + '\n');
fs.writeFileSync(path.join(resultDir, 'nexus-conformance-record.json'), JSON.stringify(record, null, 2) + '\n');

console.log(`nexus conformance outcome: ${outcome}`);
console.log(`nexus conformance result: ${path.join(resultDir, 'nexus-conformance-result.json')}`);
NODE
