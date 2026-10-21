import assert from 'node:assert/strict';

// Reviewed custom search attribute registry (SearchAttributeController with
// Laravel's request normalization and validation messages). Embedded
// applications have no HTTP registry, so the projection is the definition alone.
const SYSTEM_ATTRIBUTES = {WorkflowType: 'keyword', WorkflowId: 'keyword', RunId: 'keyword', Status: 'keyword',
  ExecutionStatus: 'keyword', StartTime: 'datetime', ExecutionTime: 'datetime', CloseTime: 'datetime',
  TaskQueue: 'keyword', BuildId: 'keyword', BuildIds: 'keyword'};
const REQUIRED = field => `The ${field} field is required.`;
const STRING = field => `The ${field} field must be a string.`;
const FORMAT = 'The name field format is invalid.';
const TYPE = 'The selected type is invalid.';
const LONG = 'The name field must not be greater than 128 characters.';
// The fixture's invalid bodies, in order.
const INVALID_ERRORS = [
  {name: [REQUIRED('name')], type: [REQUIRED('type')]},
  {name: [FORMAT], type: [TYPE]},
  {name: [STRING('name'), FORMAT], type: [STRING('type'), TYPE]},
  {name: [REQUIRED('name')]},
  {name: [LONG]},
  {name: [FORMAT], type: [STRING('type'), TYPE]},
];

function validation(errors) {
  const messages = Object.values(errors).flat();
  const more = messages.length - 1;
  const message = messages[0] + (more === 0 ? '' : ` (and ${more} more ${more === 1 ? 'error' : 'errors'})`);
  return {status: 422, response: {message, reason: 'validation_failed', errors, validation_errors: errors}};
}

export function checkSearchAttributes(fixture, observation, workflowId) {
  const definition = fixture.search_attributes;
  const state = observation.search_attributes;
  const projection = {scope: 'search_attributes', limit: definition.limit};
  assert.ok(state && typeof state === 'object', 'actual search attribute observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_http_search_attribute_registry'},
      'embedded search attributes explicitly inapplicable');
    return projection;
  }
  const equal = (actual, expected, label) => assert.deepStrictEqual(actual, expected, label);
  const [namespace, limitNamespace] = [`${workflowId}-attributes`, `${workflowId}-attribute-limit`].map(name => name.toLowerCase());
  equal([state.applicable, state.namespaces], [true, [namespace, limitNamespace]], 'registry namespaces');
  // PHP renders an empty map as a JSON array.
  const listing = custom => ({system_attributes: SYSTEM_ATTRIBUTES, custom_attributes: custom});
  equal(state.sdk_empty, listing([]), 'a new namespace lists only the system attributes');
  equal(state.sdk_created, definition.created.map(([name, type]) => ({name, type, outcome: 'created'})),
    'the published SDK registers definitions');
  equal(state.sdk_listed, listing(Object.fromEntries(definition.created)), 'the published SDK lists them');
  equal(state.trimmed, {status: 201, response: {name: 'Region', type: 'keyword_list', outcome: 'created'}},
    'request strings are trimmed before validation');
  equal(state.other_namespace, {status: 200, response: listing([])}, 'definitions belong to one namespace');
  const [name, type] = definition.created.find(([created]) => created === definition.duplicate.name);
  equal(state.duplicate, {status: 409, response: {
    message: `A custom search attribute [${name}] already exists in namespace [${namespace}].`,
    reason: 'attribute_already_exists', name, type}}, 'a duplicate keeps the original definition');
  equal(state.reserved, definition.reserved.map(reserved => ({status: 409, response: {
    message: `The name [${reserved}] is reserved for system search attributes.`, reason: 'name_reserved'}})),
  'system names and their aliases are reserved');
  equal(definition.invalid.length, INVALID_ERRORS.length, 'every invalid body has reviewed messages');
  equal(state.invalid, INVALID_ERRORS.map(validation), 'Laravel validation messages');
  equal(state.delete_system, {status: 409, response: {
    message: 'The system search attribute [WorkflowType] cannot be removed.', reason: 'system_attribute'}},
  'system attributes cannot be removed');
  equal(state.delete_missing, {status: 404, response: {
    message: `Custom search attribute [Absent] not found in namespace [${namespace}].`, reason: 'attribute_not_found'}},
  'an absent definition is not found');
  const [removed] = definition.created[0];
  equal(state.sdk_deleted, {name: removed, outcome: 'deleted'}, 'the published SDK removes a definition');
  const remaining = Object.fromEntries([...definition.created.slice(1), ['Region', 'keyword_list']]);
  equal(state.listed_after_delete, {status: 200, response: listing(remaining)}, 'the removal is listed');
  assert.ok(!('limit_fill_refusal' in state), 'definitions up to the limit are accepted');
  equal(state.over_limit, {status: 422, response: {
    message: `Namespace [${limitNamespace}] has reached the maximum of ${definition.limit} custom search attributes.`,
    reason: 'search_attribute_limit_reached', limit: definition.limit}}, 'the namespace limit is enforced');
  equal(state.limit_count, definition.limit, 'the refused definition was not stored');
  return projection;
}
