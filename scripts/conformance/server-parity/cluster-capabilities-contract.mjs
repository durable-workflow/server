import assert from 'node:assert/strict';

// Reviewed self-description in /api/cluster/info (HealthController::clusterInfo)
// and the activity result read (cluster-capabilities-probe.php), recorded
// against published Server 2.11.7. Published PHP answers `expected` exactly.
// Native (`implementation: "rust"`) answers it with the fixture's reviewed
// `native_differences`, each naming a member native does not serve or does
// not publish, and its reviewed `native_additions`, each a member published
// PHP never had, and names the members it leaves out in `native_unqualified`.
export function expectedFor(definition, implementation) {
  const expected = structuredClone(definition.expected);
  if (implementation !== 'rust') return expected;
  for (const [path, {value}] of Object.entries(definition.native_differences)) {
    const keys = path.split('.');
    const last = keys.pop();
    const parent = keys.reduce((node, key) => node[key], expected);
    assert.ok(Object.hasOwn(parent, last), `reviewed native difference ${path} names a published member`);
    if (value === '{absent}') delete parent[last]; else parent[last] = value;
  }
  // Reviewed members only native serves, which published PHP never had.
  for (const [path, {value}] of Object.entries(definition.native_additions ?? {})) {
    const keys = path.split('.');
    const last = keys.pop();
    const parent = keys.reduce((node, key) => node[key], expected);
    assert.ok(!Object.hasOwn(parent, last), `reviewed native addition ${path} is not a published member`);
    parent[last] = value;
  }
  return expected;
}

export function checkClusterCapabilities(fixture, observation) {
  const definition = fixture.cluster_capabilities;
  const state = observation.cluster_capabilities;
  const projection = {scope: 'cluster_capabilities'};
  assert.ok(state && typeof state === 'object', 'actual cluster capabilities observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_http_cluster_info'},
      'embedded cluster capabilities explicitly inapplicable');
    return projection;
  }
  const {implementation, ...rest} = state;
  assert.ok(implementation === null || implementation === 'rust', 'published PHP or native');
  checkClientManifests(rest, implementation);
  assert.deepStrictEqual(rest, expectedFor(definition, implementation), `cluster capabilities (${implementation ?? 'php'})`);
  return projection;
}

// The protocols native serves beside its client_compatibility block, and so
// the only ones the block may cite (server#1005). The CLI checks every
// protocol the block requires.
const NATIVE_SERVED_PROTOCOLS = ['auth_composition', 'control_plane', 'worker_protocol'];

/// Independent of the reviewed values: each client's range is the same in
/// supported_sdk_versions and client_compatibility, and native's block cites
/// only protocols native serves, with every client requirement resolving.
export function checkClientManifests(state, implementation) {
  const ranges = state.client_ranges;
  assert.ok(ranges && typeof ranges === 'object', 'client ranges recorded');
  for (const [client, {supported_sdk_versions: sdk, client_compatibility: matrix}] of Object.entries(ranges)) {
    assert.ok(typeof sdk === 'string' && sdk !== '', `${client}: supported_sdk_versions range`);
    assert.equal(matrix, sdk, `${client}: client_compatibility and supported_sdk_versions agree`);
  }
  if (implementation !== 'rust') return;
  const block = state.native_client_compatibility;
  assert.ok(block && typeof block === 'object', 'native client_compatibility block');
  assert.equal(block.authority, 'protocol_manifests', 'native compatibility authority');
  const required = block.required_protocols;
  assert.deepStrictEqual(Object.keys(required).sort(), NATIVE_SERVED_PROTOCOLS, 'native cites only protocols it serves');
  assert.ok(!JSON.stringify(block).includes('external_'), 'native cites no external execution contract');
  for (const [client, entry] of Object.entries(block.clients)) {
    for (const requirement of entry.requires) {
      const [protocol, member] = requirement.split('.');
      assert.ok(required[protocol] && Object.hasOwn(required[protocol], member),
        `${client} requires ${requirement}, which the block does not cite`);
    }
  }
}
