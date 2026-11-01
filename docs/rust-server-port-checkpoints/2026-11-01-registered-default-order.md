# November 1, registered default key order

- **Before.** Native's JSON objects kept their keys sorted (`serde_json`
  without `preserve_order`). A query parameter default a worker registered
  as a JSON object therefore lost its order on the way in:
  - `{"z": 20, "a": 10}` reached the worker as the map `{a, z}`;
  - `{"1": "a", "0": "b"}` read as keys 0 and 1 and reached the worker as the
    list `["b", "a"]`.
- **Contract** (published 2.11.7, Core 2.15.7;
  `WorkflowQueryContract::validatedArgumentsForRun` and the Avro Value
  codec). PHP decodes the registration into arrays and keeps their keys in
  the order its database returns them:
  - A map default reaches the worker in that order.
  - Integer keys make a list only when they are 0 to n-1 in that order
    (`array_is_list`). Otherwise the encoder fails with `invalid_map_key`
    and an uncaught 500.
  - The order the database returns depends on the column. SQLite text and
    a Postgres `json` column keep the text the server wrote. A MySQL `json`
    column orders keys by length, then bytes, for both servers. On MySQL,
    `{"aa": 1, "b": 2}` therefore reads back as `{b, aa}`, and
    `{"1": "y", "0": "x"}` reads back as a list.
- **Native.** `serde_json` now has `preserve_order`, so every object keeps
  its document order through the registration, the `WorkflowStarted`
  snapshot and the query. `query_contract::from_json` no longer sorts integer
  keys: it makes a list only from keys 0 to n-1 in stored order, and answers
  any other integer key with `query_default_unencodable`. Native's Postgres
  binding sends documents as `json` text, and its MySQL columns are `json`.
  So native reads back the same order PHP does on each backend.
- **Other effects of `preserve_order`.** Every object now keeps document
  order, not only these defaults. Places that relied on sorted keys were
  audited and kept, or now follow PHP's order:
  - Kept by sorting explicitly (`json_order::sorted_keys`):
    - the command context's request fingerprint, which PHP computes after
      `ksort`;
    - the query task completion fingerprint;
    - the cancellation-scope checkpoint fingerprint.
    The last two stay independent of a retry's key order. All three hashes
    are byte for byte what they were.
  - Already explicit: the history export's canonical JSON (`php_value`
    ksort), the envelope key checks (sorted key lists), the schedule
    filter fingerprint (formatted text), cancellation descriptor hashes
    (scalars), build-ID and pagination digests (lists) and the upgrade
    catalog digest (table name and definition pairs).
  - Now in PHP's order, the request's or the stored one:
    - search attribute validation messages, as PHP's
      `SearchAttributeValueValidator` loops over the attributes;
    - workflow definition fingerprint pairs, as
      `workflowDefinitionFingerprints` loops over the stored map;
    - service endpoint documents, which `php_value` reads as PHP's array
      cast does: an out-of-order integer-keyed object now stays a map.
  - Response objects native builds itself now list their keys in code order,
    not sorted. JSON readers and the parity contracts compare objects
    without regard to key order.
  - Equality of two objects still ignores key order, so a document update
    that only reorders keys still writes nothing.
- **Fixture.** `query-contract-arguments` gains three cases, recorded
  against published 2.11.7 on SQLite. Its contract holds on every backend:
  - `registered_order_default`: `{"b": 2, "aa": 1}` is already in MySQL's
    order and is not sorted, so every backend routes `[{b: 2, aa: 1}]` in
    that order. A server that sorts keys fails it.
  - `stored_order_default`: `{"aa": 1, "b": 2}` is routed unchanged where
    the database keeps the registered order, or as the first default where
    MySQL reorders it. The result tells the contract which order the
    database kept.
  - `descending_default`: `{"1": "y", "0": "x"}` is refused with the 500
    where the order was kept. Where the database reordered it, it is routed
    as `[["x", "y"]]`, the `list_default` case's recorded answer. The probe
    holds it as a routed query and stops polling once the server answers.
  - Every earlier case recorded the same answers on 2.11.7 as on 2.11.5.
- **Tests.**
  - `query_contract.rs` unit tests: a map default keeps its registered
    order, and descending integer keys are unencodable.
  - `query_contract_arguments.rs` execution test: the worker receives
    `{z, a}` in registered order, and `{"1": "y", "0": "x"}` answers
    `query_default_unencodable`. This fails on the previous source.
  - `json_order.rs` unit test.
  - Updated expectations in `search_attribute_upserts.rs` and
    `task_queue_build_ids.rs` give PHP's order for their inputs.
  - `ServerParityQueryContractArgumentsContractTest.mjs` covers both
    backend outcomes and rejects sorted maps and mismatched pairs.
