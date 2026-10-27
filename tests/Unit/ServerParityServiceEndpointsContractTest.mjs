import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {checkServiceEndpoints} from '../../scripts/conformance/server-parity/service-endpoints-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/service-endpoints.json', import.meta.url)));
const id = 'parity-v1-service-endpoints';
const own = `${id}-endpoints`;

// The recorded answers with each placeholder given a concrete value: one
// ULID per id name and one time per time name.
const observation = () => {
  const values = new Map();
  const concrete = value => {
    if (typeof value === 'string') {
      const placeholder = /^\{(id|time)(?::(.+))?\}$/.exec(value);
      if (!placeholder) {
        return value.split('@own').join(own);
      }
      const key = `${placeholder[1]}:${placeholder[2] ?? ''}`;
      if (!values.has(key) || placeholder[2] === undefined) {
        const index = String(values.size).padStart(4, '0');
        values.set(key, placeholder[1] === 'id' ? `01m5wvy8d1j3dtr13es05k${index}` : `2026-10-26T19:${index.slice(2)}:00+00:00`);
      }
      return values.get(key);
    }
    if (Array.isArray(value)) {
      return value.map(concrete);
    }
    if (value !== null && typeof value === 'object') {
      return Object.fromEntries(Object.entries(value).map(([key, item]) => [key, concrete(item)]));
    }
    return value;
  };
  const responses = {};
  for (const {label} of fixture.service_endpoints.requests) {
    responses[label] = concrete(fixture.service_endpoints.expected[label]);
  }
  return {mode: 'http', service_endpoints: {applicable: true, namespace: own, responses}};
};

test('the service endpoints accept the published answers', () => {
  assert.deepStrictEqual(checkServiceEndpoints(fixture, observation(), id), {scope: 'service_endpoints', cases: 72});
});

test('the fixture covers every administration route and refusal', () => {
  const {requests, expected} = fixture.service_endpoints;
  const routes = new Set(requests.filter(request => request.path.startsWith('service-endpoints'))
    .map(request => `${request.method} ${request.path.split('/').map((segment, index) => index % 2 === 1 ? '{}' : segment).join('/')}`));
  assert.equal(routes.size, 15, [...routes].join('\n'));
  const statuses = new Set(Object.values(expected).map(([status]) => status));
  assert.deepStrictEqual([...statuses].sort(), [200, 201, 404, 409, 422]);
  const services = expected['services list'][1].services.map(service => service.service_name);
  assert.deepStrictEqual(services, ['invoices', 'accounts'], 'services list in creation order');
  assert.deepStrictEqual(expected['endpoint create'][1].metadata.indexed, ['a', 'b'], 'documents read back as PHP arrays');
});

test('the embedded service endpoints are explicitly inapplicable', () => {
  checkServiceEndpoints(fixture, {mode: 'embedded', service_endpoints: {applicable: false, reason: 'embedded_has_no_http_service_endpoints'}}, id);
  assert.throws(() => checkServiceEndpoints(fixture, {mode: 'embedded', service_endpoints: {applicable: true}}, id));
});

for (const [label, mutate] of [
  ['a missing response', responses => { delete responses['endpoint create']; }],
  ['an extra response', responses => { responses.extra = [200, {}]; }],
  ['a route that is not implemented', responses => { responses['endpoints empty'] = [501, {reason: 'rust_capability_not_implemented'}]; }],
  ['an uppercase id', responses => { responses['endpoint create'][1].id = responses['endpoint create'][1].id.toUpperCase(); }],
  ['an id that changes', responses => { responses['endpoint show'][1].id = responses['endpoint second'][1].id; }],
  ['one id for two objects', responses => { responses['endpoint second'][1].id = responses['endpoint create'][1].id; }],
  ['a fractional time', responses => { responses['endpoint create'][1].created_at = '2026-10-26T19:00:00.5+00:00'; }],
  ['an unchanged update that moves updated_at', responses => {
    responses['endpoint update unchanged'][1].updated_at = '2030-01-01T00:00:00+00:00';
  }],
  ['an object metadata where PHP has a list', responses => { responses['endpoint create'][1].metadata.indexed = {0: 'a', 1: 'b'}; }],
  ['services listed by name', responses => { responses['services list'][1].services.reverse(); }],
  ['a missing accepted flag', responses => { delete responses['endpoint missing'][1].accepted; }],
  ['an allowed rename', responses => { responses['operation rename'] = [200, {}]; }],
  ['a lost read default', responses => { responses['read endpoints'][1].data[0].metadata = null; }],
  ['a foreign identity found', responses => { responses['read endpoint elsewhere'] = [200, {}]; }],
  ['a namespace that is not the fixture\'s', responses => { responses['endpoint missing'][1].namespace = 'default'; }],
]) {
  test(`the service endpoints reject ${label}`, () => {
    const value = observation();
    mutate(value.service_endpoints.responses);
    assert.throws(() => checkServiceEndpoints(fixture, value, id));
  });
}
