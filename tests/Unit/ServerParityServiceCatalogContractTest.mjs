import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {checkServiceCatalog} from '../../scripts/conformance/server-parity/service-catalog-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/service-catalog.json', import.meta.url)));
const id = 'parity-v1-service-catalog';
const own = `${id}-catalog`;
const observation = () => {
  const responses = {};
  for (const {label} of fixture.service_catalog.requests) {
    responses[label] = JSON.parse(JSON.stringify(fixture.service_catalog.expected[label]).split('"@own"').join(JSON.stringify(own)));
  }
  return {mode: 'http', service_catalog: {applicable: true, namespace: own, responses}};
};

test('the service catalog accepts the published answers', () => {
  assert.deepStrictEqual(checkServiceCatalog(fixture, observation(), id), {scope: 'service_catalog', cases: 37});
});

test('the fixture covers lists, identities, filters and namespace selection', () => {
  const {expected} = fixture.service_catalog;
  const statuses = Object.values(expected).map(([status]) => status);
  for (const status of [200, 400, 404, 422]) {
    assert.ok(statuses.includes(status), `a ${status} case`);
  }
  assert.deepStrictEqual(expected['header second, query default'][1].namespace, '@own');
  assert.deepStrictEqual(expected['calls list'][1].outcome_buckets.policy.length, 5);
});

test('the embedded service catalog is explicitly inapplicable', () => {
  checkServiceCatalog(fixture, {mode: 'embedded', service_catalog: {applicable: false, reason: 'embedded_has_no_http_service_catalog'}}, id);
  assert.throws(() => checkServiceCatalog(fixture, {mode: 'embedded', service_catalog: {applicable: true}}, id));
});

for (const [label, mutate] of [
  ['a missing response', responses => { delete responses['calls list']; }],
  ['an extra response', responses => { responses.extra = [200, {}]; }],
  ['a route that is not implemented', responses => { responses['endpoints list'] = [501, {reason: 'rust_capability_not_implemented'}]; }],
  ['a non-empty page', responses => { responses['services list'][1].total = 1; }],
  ['a default page size other than 50', responses => { responses['operations list'][1].per_page = 25; }],
  ['the header namespace losing to the query', responses => { responses['header default, query second'][1].namespace = own; }],
  ['an uncased namespace', responses => { responses['header upper'][1].namespace = own.toUpperCase(); }],
  ['an identity found', responses => { responses['calls show'] = [200, {}]; }],
  ['a generic not found', responses => { responses['endpoints show'][1].reason = 'not_found'; }],
  ['a min message for per_page', responses => { responses['endpoints per0'][1].message = 'The per page field must be at least 1.'; }],
  ['an accepted unknown filter', responses => { responses['endpoints unknown'] = [200, {}]; }],
  ['an accepted page on a detail', responses => { responses['show with page'] = [404, {}]; }],
  ['an unchecked bucket disagreement', responses => { responses['calls bucket mismatch'] = [200, {}]; }],
  ['a bucket not echoed as status_bucket', responses => { responses['calls bucket'][1].status_bucket = null; }],
  ['a missing outcome vocabulary', responses => { delete responses['calls filters'][1].outcomes; }],
]) {
  test(`the service catalog rejects ${label}`, () => {
    const value = observation();
    mutate(value.service_catalog.responses);
    assert.throws(() => checkServiceCatalog(fixture, value, id));
  });
}
