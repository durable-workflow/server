import assert from 'node:assert/strict';

// Reviewed query arguments matched to a durable query contract
// (query-contract-arguments-probe.php), recorded against published Server
// 2.11.5 with Workflow 2.15.1: named and positional arguments normalize to
// the contract's positional order, a default fills a missing argument, the
// worker receives the normalized list encoded again, and each violation is
// refused with PHP's per-argument messages. A registered JSON default takes
// PHP's array shape in the key order the server's database keeps;
// `sent_defaults` pins that those defaults were sent as JSON objects. The
// fixture's `expected` holds the whole observation, recorded on SQLite.
//
// A MySQL `json` column keeps an object's keys by length, then bytes, for
// both servers; SQLite text and a Postgres `json` column keep the registered
// order. `{"b": 2, "aa": 1}` is in that order already, so every backend
// routes it unchanged, and sorted keys fail it. `{"aa": 1, "b": 2}` shows
// which order the database kept: unchanged, or as the first default.
// `{"1": "y", "0": "x"}` is then refused as PHP's encoder refuses an integer
// key outside a list, or, where the database reordered it into a list,
// routed as the `{"0": "x", "1": "y"}` default is.
const KEPT_ORDER_CASES = new Set(['stored_order_default', 'descending_default']);

function checkKeptOrder(definition, state) {
  const expected = definition.expected;
  const sameBlob = (run, other) => state[run]?.query_arguments?.blob === expected[other].query_arguments.blob;
  const reordered = sameBlob('stored_order_default', 'registered_order_default');
  assert.ok(reordered || sameBlob('stored_order_default', 'stored_order_default'),
    'query contract arguments stored_order_default keeps the registered or the database order');
  const stored = reordered ? expected.registered_order_default : expected.stored_order_default;
  assert.deepStrictEqual(state.stored_order_default, stored, 'query contract arguments stored_order_default');
  if (reordered) {
    assert.deepStrictEqual(state.descending_default, expected.list_default,
      'query contract arguments descending_default routed as a reordered list');
    return;
  }
  const refused = expected.descending_default;
  assert.equal(refused.client.status, 500, 'recorded descending_default is refused');
  // Only the status is pinned, as for the other unencodable defaults.
  assert.deepStrictEqual({...state.descending_default, client: {status: state.descending_default?.client?.status}},
    {...refused, client: {status: 500}}, 'query contract arguments descending_default refused');
}

export function checkQueryContractArguments(fixture, observation) {
  const definition = fixture.query_contract_arguments;
  const state = observation.query_contract_arguments;
  const projection = {scope: 'query_contract_arguments', cases: Object.keys(definition.cases).length};
  assert.ok(state && typeof state === 'object', 'actual query contract arguments observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_worker_protocol'},
      'embedded query contract arguments explicitly inapplicable');
    return projection;
  }
  assert.equal(state.applicable, true, 'HTTP targets record query contract arguments');
  checkKeptOrder(definition, state);
  for (const [run, steps] of Object.entries(definition.expected)) {
    assert.ok(state[run] && typeof state[run] === 'object', `query contract arguments ${run} recorded`);
    if (KEPT_ORDER_CASES.has(run)) {
      continue;
    }
    // Published PHP answers an unencodable registered default with an
    // uncaught 500 and a generic body; only the status is pinned, so a
    // server may say what failed.
    if (steps.status === 500) {
      assert.equal(state[run].status, 500, `query contract arguments ${run} status`);
      assert.equal(state[run].validation_errors, null, `query contract arguments ${run} validation errors`);
      continue;
    }
    for (const [step, expected] of Object.entries(steps)) {
      assert.deepStrictEqual(state[run][step], expected, `query contract arguments ${run} ${step}`);
    }
    assert.deepStrictEqual(Object.keys(state[run]).sort(), Object.keys(steps).sort(), `query contract arguments ${run} steps`);
  }
  assert.deepStrictEqual(Object.keys(state).sort(), ['applicable', ...Object.keys(definition.expected)].sort(),
    'query contract arguments runs');
  return projection;
}
