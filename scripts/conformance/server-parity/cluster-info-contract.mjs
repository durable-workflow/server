import assert from 'node:assert/strict';

// The transport manifest a server advertises. Published PHP advertises the
// fixture's manifest exactly. Native (`implementation: "rust"`) advertises it
// with the fixture's reviewed differences, each a feature native does not serve.
export function expectedTransport(definition, implementation) {
  const transport = structuredClone(definition.transport);
  if (implementation === 'rust') {
    for (const [path, {value}] of Object.entries(definition.native_differences)) {
      const keys = path.split('.');
      const last = keys.pop();
      const parent = keys.reduce((node, key) => node[key], transport);
      assert.ok(Object.hasOwn(parent, last), `reviewed native difference ${path} names a published member`);
      parent[last] = value;
    }
  }
  return transport;
}

// Reviewed runtime external payload discovery (HealthController::clusterInfo):
// the request limits, each namespace's storage policy and the transport
// manifest, which is also the worker capability `runtime_external_payload_transport`.
export function checkClusterInfo(fixture, observation, workflowId) {
  const definition = fixture.cluster_info;
  const state = observation.cluster_info;
  assert.ok(state && typeof state === 'object', 'actual cluster info observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_http_cluster_info'}, 'embedded cluster info explicitly inapplicable');
    return {scope: 'cluster_info', cases: Object.keys(definition.namespaces).length};
  }
  const names = Object.fromEntries(Object.entries(definition.namespaces)
    .map(([key, suffix]) => [key, `${workflowId}-${suffix}`.toLowerCase()]));
  assert.deepStrictEqual(state.namespaces, names, 'fresh namespaces');
  assert.deepStrictEqual(Object.keys(state.policies).sort(), Object.keys(definition.policies).sort(), 'configured policies');
  for (const [key, policy] of Object.entries(definition.policies)) {
    const recorded = state.policies[key];
    assert.equal(recorded.status, 200, `${key} policy stored`);
    assert.deepStrictEqual(recorded.response.external_payload_storage, policy, `${key} stored policy`);
  }
  const implementations = new Set(Object.values(state.discovery).map(discovery => discovery.implementation));
  assert.equal(implementations.size, 1, 'one server answered every read');
  const [implementation] = implementations;
  assert.ok(implementation === null || implementation === 'rust', 'published PHP or native');
  const transport = expectedTransport(definition, implementation);
  assert.deepStrictEqual(Object.keys(state.discovery), Object.keys(definition.namespaces), 'every namespace read');
  for (const [key, discovery] of Object.entries(state.discovery)) {
    assert.equal(discovery.status, 200, `${key} cluster info`);
    assert.deepStrictEqual(discovery.limits, definition.limits, `${key} request limits`);
    assert.equal(discovery.default_namespace, definition.default_namespace, `${key} default namespace`);
    assert.equal(discovery.server_id_is_text, true, `${key} server identity`);
    assert.deepStrictEqual(discovery.transport_capability, transport, `${key} transport capability`);
    assert.deepStrictEqual(discovery.namespace, {name: names[key], ...definition.rows[key],
      external_payload_storage: {...definition.storage_envelope, ...definition.storage[key], transport}},
    `${key} namespace discovery`);
  }

  return {scope: 'cluster_info', cases: Object.keys(definition.namespaces).length,
    statuses: Object.fromEntries(Object.entries(state.discovery)
      .map(([key, discovery]) => [key, discovery.namespace.external_payload_storage.status]))};
}
