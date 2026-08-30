import assert from 'node:assert/strict';
import test from 'node:test';

import {
  assertPythonSdkProbeCapabilityManifest,
  classifyEvidenceStatus,
  compareRefusalMutationStates,
  deriveProtocolAuthority,
  loudRefusalContext,
  pairingClassesForAuthority,
  pythonSdkProbeSource,
  rawWorkerTaskFixtureRegistrationPayload,
  refusalMutationStateBody,
  workerClassification,
} from '../../scripts/conformance/skew-published-artifacts.mjs';

function clusterInfo({
  controlPlane = '7',
  workerProtocol = '4.12',
  portableWorkerAffinityMinimum = '4.11',
} = {}) {
  const [workerMajor, workerMinor] = workerProtocol.split('.').map(Number);
  const acceptedVersions = Array.from(
    { length: workerMinor + 1 },
    (_, minor) => `${workerMajor}.${minor}`,
  );

  return {
    version: '2.0.0-rc.999',
    control_plane: {
      version: controlPlane,
      header: 'X-Test-Control-Plane',
    },
    worker_protocol: {
      version: workerProtocol,
      server_capabilities: {
        local_activities: {
          minimum_worker_protocol_version: portableWorkerAffinityMinimum,
        },
        sticky_execution: {
          minimum_worker_protocol_version: portableWorkerAffinityMinimum,
        },
      },
    },
    client_compatibility: {
      required_protocols: {
        control_plane: {
          version: controlPlane,
          header: 'X-Test-Control-Plane',
        },
        worker_protocol: {
          version: workerProtocol,
          header: 'X-Test-Worker-Protocol',
        },
      },
    },
    surface_stability_contract: {
      surface_families: {
        worker_protocol: {
          negotiation: {
            default_advertised_version: workerProtocol,
            request_header_rule: 'same_major_and_minor_less_than_or_equal_to_advertised',
            accepted_request_versions_by_default: acceptedVersions,
          },
        },
      },
    },
  };
}

test('pairings derive labels, versions, and expectations from the published Server authority', () => {
  const authority = deriveProtocolAuthority(clusterInfo());
  const pairings = pairingClassesForAuthority(authority);

  assert.equal(authority.control_plane.version, '7');
  assert.equal(authority.worker_protocol.version, '4.12');
  assert.deepEqual(authority.worker_protocol.portable_worker_affinity, {
    minimum_protocol_version: '4.11',
    capabilities: ['local_activities', 'worker_sessions', 'sticky_execution'],
  });
  assert.equal(authority.prerelease_package_policy, 'exact_current_tuple_only');
  assert.deepEqual(
    [pairings.compatible.controlPlaneVersion, pairings.compatible.workerProtocolVersion],
    ['7', '4.12'],
  );
  assert.deepEqual(
    [pairings.backward_skew.controlPlaneVersion, pairings.backward_skew.workerProtocolVersion],
    ['6', '4.11'],
  );
  assert.deepEqual(
    [pairings.forward_skew.controlPlaneVersion, pairings.forward_skew.workerProtocolVersion],
    ['8', '4.13'],
  );
  assert.deepEqual(
    [pairings.outside_window.controlPlaneVersion, pairings.outside_window.workerProtocolVersion],
    ['9', '5.0'],
  );
  assert.match(pairings.compatible.label, /control-plane-7_worker-4\.12/);
  assert.match(pairings.compatible.expected, /exact published artifact tuple registers and serves/);
  assert.match(pairings.forward_skew.expected, /refuse before mutation, registration, lease, or dropped work/);
  assert.doesNotMatch(JSON.stringify(pairings), /RC-to-RC|rc\.1|prerelease compatibility window/i);
});

test('authority derivation refuses missing or contradictory manifests before scenario evidence', () => {
  const missingNegotiation = clusterInfo();
  delete missingNegotiation.surface_stability_contract;
  assert.throws(
    () => deriveProtocolAuthority(missingNegotiation),
    /omitted worker protocol negotiation authority/,
  );

  const contradictory = clusterInfo();
  contradictory.client_compatibility.required_protocols.worker_protocol.version = '4.11';
  assert.throws(
    () => deriveProtocolAuthority(contradictory),
    /cluster manifests disagree on worker protocol/,
  );

  const unsafeNegotiation = clusterInfo();
  unsafeNegotiation.surface_stability_contract.surface_families.worker_protocol.negotiation
    .accepted_request_versions_by_default.push('4.13');
  assert.throws(
    () => deriveProtocolAuthority(unsafeNegotiation),
    /invalid worker protocol negotiation range/,
  );

  const contradictoryAffinity = clusterInfo();
  contradictoryAffinity.worker_protocol.server_capabilities.sticky_execution
    .minimum_worker_protocol_version = '4.10';
  assert.throws(
    () => deriveProtocolAuthority(contradictoryAffinity),
    /cluster manifests disagree on portable worker affinity/,
  );
});

test('raw worker-task fixture registration carries the exact-current portable capability manifest', () => {
  const protocolAuthority = deriveProtocolAuthority(clusterInfo());
  const payload = rawWorkerTaskFixtureRegistrationPayload({
    workerId: 'raw-fixture-worker',
    taskQueue: 'raw-fixture-queue',
    runtime: 'python',
    protocolAuthority,
  });

  assert.equal(payload.worker_id, 'raw-fixture-worker');
  assert.equal(payload.task_queue, 'raw-fixture-queue');
  assert.equal(payload.runtime, 'python');
  assert.deepEqual(Object.keys(payload.capability_manifest), [
    'local_activities',
    'worker_sessions',
    'sticky_execution',
  ]);
  for (const entry of Object.values(payload.capability_manifest)) {
    assert.deepEqual(entry, {
      supported: false,
      minimum_protocol_version: '4.11',
      reason: 'skew_fixture_uses_cold_durable_replay',
    });
  }
});

test('exact-current succeeds while unsupported shapes fail closed', () => {
  const observedServerVersion = '2.0.0-rc.999';
  assert.equal(classifyEvidenceStatus({
    surfaceName: 'sdk-php',
    pairingClass: 'compatible',
    operationGroup: 'worker_lifecycle',
    response: { status: 201, body: { registered: true } },
  }), 'pass');
  assert.equal(workerClassification(
    'compatible',
    { status: 201, body: { registered: true } },
    'worker_lifecycle',
  ), 'register_and_serve');

  const typedRefusal = {
    status: 400,
    body: {
      reason: 'unsupported_protocol_version',
      supported_version: '4.12',
      requested_version: '5.0',
    },
  };
  assert.equal(classifyEvidenceStatus({
    surfaceName: 'sdk-php',
    pairingClass: 'outside_window',
    operationGroup: 'worker_lifecycle',
    response: typedRefusal,
  }), 'loud_refuse');
  assert.equal(workerClassification('outside_window', typedRefusal, 'worker_lifecycle'), 'register_refused');

  const authority = deriveProtocolAuthority(clusterInfo());
  const pairing = pairingClassesForAuthority(authority).outside_window;
  const refusal = loudRefusalContext(
    'sdk-php',
    '2.0.0-rc.53',
    {
      artifactVersions: { 'sdk-php': '2.0.0-rc.53' },
      observedServerVersion,
      protocolAuthority: authority,
    },
    pairing,
    typedRefusal,
  );
  assert.equal(refusal.observed_client_or_worker_version, '2.0.0-rc.53');
  assert.equal(refusal.server_version, observedServerVersion);
  assert.equal(refusal.requested_worker_protocol_version, '5.0');
  assert.equal(refusal.supported_worker_protocol_version, '4.12');
  assert.match(refusal.next_step, /pin sdk-php 2\.0\.0-rc\.53 with Server 2\.0\.0-rc\.999/);
  assert.doesNotMatch(refusal.next_step, /RC-to-RC|compatibility window/i);
});

test('generated Python skew registration requires the portable worker capability manifest', () => {
  const source = pythonSdkProbeSource();
  assert.doesNotThrow(() => assertPythonSdkProbeCapabilityManifest(source));
  assert.match(
    source,
    /from durable_workflow\.client import [^\n]*PORTABLE_WORKER_AFFINITY_CAPABILITY_MANIFEST/,
  );
  assert.match(
    source,
    /capability_manifest=PORTABLE_WORKER_AFFINITY_CAPABILITY_MANIFEST/,
  );

  assert.throws(
    () => assertPythonSdkProbeCapabilityManifest(source.replace(
      '                capability_manifest=PORTABLE_WORKER_AFFINITY_CAPABILITY_MANIFEST,\n',
      '',
    )),
    /must supply PORTABLE_WORKER_AFFINITY_CAPABILITY_MANIFEST/,
  );
});

test('silent acceptance, register-and-drop, and mutation-before-refusal remain blocking', () => {
  const accepted = { status: 202, body: { accepted: true } };
  assert.equal(classifyEvidenceStatus({
    surfaceName: 'cli',
    pairingClass: 'forward_skew',
    operationGroup: 'cluster_info_probe',
    response: accepted,
  }), 'silent_success');
  assert.equal(classifyEvidenceStatus({
    surfaceName: 'cli',
    pairingClass: 'outside_window',
    operationGroup: 'workflow_control_plane',
    response: accepted,
  }), 'silent_success');
  assert.equal(classifyEvidenceStatus({
    surfaceName: 'sdk-php',
    pairingClass: 'forward_skew',
    operationGroup: 'worker_lifecycle',
    response: accepted,
  }), 'silent_success');
  assert.equal(
    workerClassification('forward_skew', accepted, 'worker_lifecycle'),
    'register_and_drop',
  );

  const typedRefusal = {
    status: 400,
    body: {
      reason: 'unsupported_control_plane_version',
      supported_version: '7',
      requested_version: '9',
    },
  };
  const before = { observed: true, status: 404, body: { reason: 'workflow_not_found' } };
  const after = { observed: true, status: 200, body: { workflow_id: 'mutated-before-refusal' } };
  const mutatedState = {
    required: true,
    before,
    after,
    ...compareRefusalMutationStates(before, after),
  };
  assert.deepEqual(
    compareRefusalMutationStates(before, after),
    { unchanged: false, mutation_detected: true, outcome: 'fail' },
  );
  const unchangedState = {
    required: true,
    before,
    after: before,
    ...compareRefusalMutationStates(before, before),
  };
  assert.deepEqual(
    compareRefusalMutationStates(before, before),
    { unchanged: true, mutation_detected: false, outcome: 'pass' },
  );
  const readyQueue = refusalMutationStateBody('workflow_task_lease_state', {
    stats: { workflow_tasks: { ready_count: 1, leased_count: 0, expired_lease_count: 0 } },
    current_leases: [],
  });
  const leasedQueue = refusalMutationStateBody('workflow_task_lease_state', {
    stats: { workflow_tasks: { ready_count: 0, leased_count: 1, expired_lease_count: 0 } },
    current_leases: [{ task_id: 'task-1', workflow_id: 'workflow-1', lease_owner: 'worker-1', status: 'leased' }],
  });
  assert.deepEqual(
    compareRefusalMutationStates(
      { observed: true, status: 200, body: readyQueue },
      { observed: true, status: 200, body: leasedQueue },
    ),
    { unchanged: false, mutation_detected: true, outcome: 'fail' },
  );
  assert.equal(classifyEvidenceStatus({
    surfaceName: 'cli',
    pairingClass: 'outside_window',
    operationGroup: 'workflow_control_plane',
    requestTemplate: 'POST /api/workflows',
    response: typedRefusal,
    refusalMutationEvidence: mutatedState,
  }), 'mutation_before_refusal');
  assert.equal(classifyEvidenceStatus({
    surfaceName: 'cli',
    pairingClass: 'outside_window',
    operationGroup: 'workflow_control_plane',
    requestTemplate: 'POST /api/workflows',
    response: typedRefusal,
    refusalMutationEvidence: unchangedState,
  }), 'loud_refuse');
  assert.equal(classifyEvidenceStatus({
    surfaceName: 'cli',
    pairingClass: 'outside_window',
    operationGroup: 'workflow_control_plane',
    requestTemplate: 'POST /api/workflows',
    response: typedRefusal,
  }), 'not_covered');
  assert.equal(workerClassification(
    'outside_window',
    typedRefusal,
    'worker_lifecycle',
    mutatedState,
  ), 'register_and_drop');
});
