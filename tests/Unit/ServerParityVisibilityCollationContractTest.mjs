import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkVisibilityCollation} from '../../scripts/conformance/server-parity/visibility-collation-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/visibility-collation.json', import.meta.url), 'utf8'));
const {exact, mysql} = fixture.visibility_collation.expected;
const observation = (collation, answers) => structuredClone({mode: 'http',
  visibility_collation: {applicable: true, collation, ...answers}});

test('each collation passes with its own published answers', () => {
  assert.deepStrictEqual(checkVisibilityCollation(fixture, observation('exact', exact)),
    {scope: 'visibility_collation'});
  assert.deepStrictEqual(checkVisibilityCollation(fixture, observation('mysql', mysql)),
    {scope: 'visibility_collation'});
});

test('embedded applications have no HTTP list queries', () => {
  assert.deepStrictEqual(checkVisibilityCollation(fixture,
    {mode: 'embedded', visibility_collation: {applicable: false, reason: 'embedded_has_no_http_list_queries'}}),
  {scope: 'visibility_collation'});
});

test('MySQL ignores case, accents and trailing spaces; the others compare exactly', () => {
  assert.deepStrictEqual(mysql.keyword_equal.runs, ['r1', 'r2', 'r3', 'r4', 'r5']);
  assert.deepStrictEqual(exact.keyword_equal_upper.runs, ['r2']);
  assert.deepStrictEqual(mysql.id_equal.runs, ['r1']);
  assert.deepStrictEqual(exact.id_equal.runs, []);
  for (const answers of [exact, mysql]) assert.deepStrictEqual(answers.keyword_ordered, {status: 422, reason: 'validation_failed'});
});

for (const [name, raw] of [
  ['exact answers on MySQL', observation('mysql', exact)],
  ['MySQL answers on an exact backend', observation('exact', mysql)],
  ['an unknown collation', observation('nocase', exact)],
]) test(`the contract rejects ${name}`, () => {
  assert.throws(() => checkVisibilityCollation(fixture, raw));
});
