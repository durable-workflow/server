import assert from 'node:assert/strict';

// Reviewed service catalog administration (Server 2.11.1's
// ServiceCatalogController endpoint, service and operation routes) and the
// catalog reads over its rows. Every response is exact except generated
// values: "{id:NAME}" is a lowercase ULID, the same wherever NAME recurs and
// distinct across names; "{time}" is a whole-second UTC ISO-8601 time and
// "{time:NAME}" one that recurs exactly. "@own" is the fixture's namespace,
// also inside messages. Embedded applications have no HTTP catalog.
const ULID = /^[0-9a-hjkmnp-tv-z]{26}$/;
const TIME = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/;
const PLACEHOLDER = /^\{(id|time)(?::(.+))?\}$/;

export function checkServiceEndpoints(fixture, observation, workflowId) {
  const definition = fixture.service_endpoints;
  const state = observation.service_endpoints;
  const projection = {scope: 'service_endpoints', cases: definition.requests.length};
  assert.ok(state && typeof state === 'object', 'actual service endpoints observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_http_service_endpoints'},
      'embedded service endpoints explicitly inapplicable');
    return projection;
  }
  const own = `${workflowId}-endpoints`.toLowerCase();
  assert.deepStrictEqual([state.applicable, state.namespace], [true, own], 'service endpoints namespace');
  const labels = definition.requests.map(request => request.label);
  assert.deepStrictEqual(Object.keys(state.responses ?? {}).sort(), [...labels].sort(), 'every catalog request answered');
  const bindings = new Map();
  for (const label of labels) {
    match(definition.expected[label], state.responses[label], {bindings, own}, label);
  }
  const ids = [...bindings].filter(([name]) => name.startsWith('id:')).map(([, value]) => value);
  assert.equal(new Set(ids).size, ids.length, 'each id names one catalog object');
  return projection;
}

function match(expected, actual, context, path) {
  if (typeof expected === 'string') {
    const placeholder = PLACEHOLDER.exec(expected);
    if (!placeholder) {
      assert.equal(actual, expected.split('@own').join(context.own), path);
      return;
    }
    const [, kind, name] = placeholder;
    assert.ok(typeof actual === 'string' && (kind === 'id' ? ULID : TIME).test(actual),
      `${path}: ${kind} expected, got ${JSON.stringify(actual)}`);
    if (name !== undefined) {
      const key = `${kind}:${name}`;
      if (context.bindings.has(key)) {
        assert.equal(actual, context.bindings.get(key), `${path}: ${key}`);
      } else {
        context.bindings.set(key, actual);
      }
    }
    return;
  }
  if (Array.isArray(expected)) {
    assert.ok(Array.isArray(actual), `${path}: a list expected, got ${JSON.stringify(actual)}`);
    assert.equal(actual.length, expected.length, `${path}: length`);
    expected.forEach((item, index) => match(item, actual[index], context, `${path}[${index}]`));
    return;
  }
  if (expected !== null && typeof expected === 'object') {
    assert.ok(actual !== null && typeof actual === 'object' && !Array.isArray(actual),
      `${path}: an object expected, got ${JSON.stringify(actual)}`);
    assert.deepStrictEqual(Object.keys(actual).sort(), Object.keys(expected).sort(), `${path}: fields`);
    for (const key of Object.keys(expected)) {
      match(expected[key], actual[key], context, `${path}.${key}`);
    }
    return;
  }
  assert.deepStrictEqual(actual, expected, path);
}
