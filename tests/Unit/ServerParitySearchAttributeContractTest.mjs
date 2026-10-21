import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkSearchAttributes} from '../../scripts/conformance/server-parity/search-attribute-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/search-attributes.json', import.meta.url)));
const system = {WorkflowType: 'keyword', WorkflowId: 'keyword', RunId: 'keyword', Status: 'keyword',
  ExecutionStatus: 'keyword', StartTime: 'datetime', ExecutionTime: 'datetime', CloseTime: 'datetime',
  TaskQueue: 'keyword', BuildId: 'keyword', BuildIds: 'keyword'};
const invalid = errors => {
  const messages = Object.values(errors).flat();
  const more = messages.length - 1;
  return {status: 422, response: {message: messages[0] + (more ? ` (and ${more} more error${more === 1 ? '' : 's'})` : ''),
    reason: 'validation_failed', errors, validation_errors: errors}};
};
function model() {
  const listing = custom => ({system_attributes: system, custom_attributes: custom});
  const format = 'The name field format is invalid.';
  return structuredClone({mode: 'http', search_attributes: {applicable: true,
    namespaces: ['test-attributes', 'test-attribute-limit'], sdk_empty: listing([]),
    sdk_created: [{name: 'Customer', type: 'keyword', outcome: 'created'}, {name: 'OrderTotal', type: 'double', outcome: 'created'}],
    sdk_listed: listing({Customer: 'keyword', OrderTotal: 'double'}),
    trimmed: {status: 201, response: {name: 'Region', type: 'keyword_list', outcome: 'created'}},
    other_namespace: {status: 200, response: listing([])},
    duplicate: {status: 409, response: {message: 'A custom search attribute [OrderTotal] already exists in namespace [test-attributes].',
      reason: 'attribute_already_exists', name: 'OrderTotal', type: 'double'}},
    reserved: fixture.search_attributes.reserved.map(name => ({status: 409, response: {
      message: `The name [${name}] is reserved for system search attributes.`, reason: 'name_reserved'}})),
    invalid: [
      invalid({name: ['The name field is required.'], type: ['The type field is required.']}),
      invalid({name: [format], type: ['The selected type is invalid.']}),
      invalid({name: ['The name field must be a string.', format], type: ['The type field must be a string.', 'The selected type is invalid.']}),
      invalid({name: ['The name field is required.']}),
      invalid({name: ['The name field must not be greater than 128 characters.']}),
      invalid({name: [format], type: ['The type field must be a string.', 'The selected type is invalid.']}),
    ],
    delete_system: {status: 409, response: {message: 'The system search attribute [WorkflowType] cannot be removed.', reason: 'system_attribute'}},
    delete_missing: {status: 404, response: {message: 'Custom search attribute [Absent] not found in namespace [test-attributes].',
      reason: 'attribute_not_found'}},
    sdk_deleted: {name: 'Customer', outcome: 'deleted'},
    listed_after_delete: {status: 200, response: listing({OrderTotal: 'double', Region: 'keyword_list'})},
    over_limit: {status: 422, response: {message: 'Namespace [test-attribute-limit] has reached the maximum of 100 custom search attributes.',
      reason: 'search_attribute_limit_reached', limit: 100}},
    limit_count: 100}});
}
test('published registry behavior passes', () =>
  assert.deepStrictEqual(checkSearchAttributes(fixture, model(), 'test'), {scope: 'search_attributes', limit: 100}));
test('embedded explicitly declares the HTTP registry inapplicable', () => checkSearchAttributes(fixture,
  {mode: 'embedded', search_attributes: {applicable: false, reason: 'embedded_has_no_http_search_attribute_registry'}}, 'test'));
for (const [label, mutate] of [
  ['an empty map rendered as an object', s => s.sdk_empty.custom_attributes = {}],
  ['a missing system attribute', s => delete s.sdk_listed.system_attributes.BuildIds],
  ['untrimmed names stored', s => s.trimmed.response.name = '  Region\n'],
  ['definitions shared across namespaces', s => s.other_namespace.response.custom_attributes = {Customer: 'keyword'}],
  ['a duplicate that replaces the type', s => s.duplicate.response.type = 'int'],
  ['an alias accepted', s => s.reserved[1] = {status: 201, response: {name: 'task_queue', type: 'keyword', outcome: 'created'}}],
  ['a validation message changed', s => s.invalid[2].response.errors.name.pop()],
  ['the summary miscounts errors', s => s.invalid[0].response.message = 'The name field is required.'],
  ['validation errors without the legacy field', s => delete s.invalid[1].response.validation_errors],
  ['a system attribute removed', s => s.delete_system = {status: 200, response: {name: 'WorkflowType', outcome: 'deleted'}}],
  ['an absent definition removed', s => s.delete_missing.status = 200],
  ['a removal not listed', s => s.listed_after_delete.response.custom_attributes.Customer = 'keyword'],
  ['the limit not enforced', s => s.over_limit = {status: 201, response: {name: 'OneTooMany', type: 'int', outcome: 'created'}}],
  ['a refusal before the limit', s => s.limit_fill_refusal = {index: 99, status: 422}],
  ['the refused definition stored', s => s.limit_count = 101],
]) {
  test(`rejects ${label}`, () => {
    const observation = model();
    mutate(observation.search_attributes);
    assert.throws(() => checkSearchAttributes(fixture, observation, 'test'));
  });
}
