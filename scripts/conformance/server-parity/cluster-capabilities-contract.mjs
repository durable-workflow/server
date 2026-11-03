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
  assert.deepStrictEqual(rest, expectedFor(definition, implementation), `cluster capabilities (${implementation ?? 'php'})`);
  return projection;
}
