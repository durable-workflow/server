# October 28, named and validated query arguments

- **Before.** For a declared query with a parameter contract, native
  differed from published PHP in four ways:
  - it checked positional arguments only;
  - it refused a named-argument map as "must decode to an array";
  - it answered every violation with a bare `invalid_query_arguments`;
  - it refused some contracts as `unsupported_query_contract` (defaults,
    and declared types other than a few scalars).

  The worker then received the arguments exactly as the client sent them.
- **Contract** (published 2.11.5, Core 2.15.1;
  `WorkflowQueryTaskBroker::validateContractedQueryArguments`,
  `WorkflowQueryContract::validatedArgumentsForRun`,
  `normalizePositionalArguments`, `normalizeNamedArguments`,
  `valueMatchesDeclaredType`):
  - **Arguments.** An Avro array is a positional list. A non-empty map
    with no key PHP casts to an integer is a PHP array of named arguments.
    `Avro::fromDatum` returns any other map (empty, or with a canonical
    decimal integer key) as an `AvroMapValue` object, which is not an array.
    At the top level it is refused before normalization with "must decode to
    an array" (found in review).
  - **Positional.** Values fill the parameters in order. A variadic
    parameter takes the rest. A missing argument takes its default or is
    reported if required. Extra values are reported under `arguments`.
  - **Named.** Each parameter takes its name's value. A variadic parameter
    spreads a list or a map's values. Defaults and required arguments are
    handled as for positional ones. Each unknown name is reported under
    itself.
  - **Values.** A null is refused unless the parameter allows it. A value
    must match the declared type: nullable, union, intersection, `int`,
    `float` (an int too), `string`, `bool`, `array`, `iterable`, `scalar`,
    `true`, `false`, `null`, `mixed`. An adapter map is not an `array`. A
    class or `object` type matches only an object: decoded bytes or an
    adapter map. Neither object is `callable`. A named variadic spreads a
    PHP array's values in wire order and keeps any other value, an adapter
    map included, as one argument (found in review).
  - **Refusal.** Any violation answers `422 invalid_query_arguments`, with
    the message "Workflow query [<name>] argument validation failed." and
    `validation_errors` by argument.
  - **Worker.** Otherwise the worker receives the normalized positional
    list, encoded again with the request's codec.
  - **No parameter contract.** A declared query without one takes a list as
    given and refuses named arguments: "Named arguments require a durable
    or loadable workflow query contract." (a native server never loads a
    workflow class).
- **Fixture.** `query-contract-arguments`, raw HTTP on protocol 1.20 in a
  fresh namespace. The worker declares `state(string $prefix)`,
  `greet(string $prefix = 'dflt')`, `plain`, `arr(array $value)`,
  `obj(object $value)` and `rest(mixed ...$value)`, and three `mixed`
  queries whose registered defaults are raw JSON objects. There are
  twenty-two cases:
  - named, positional, default and named-default queries are routed, and
    the worker's blob is checked;
  - unknown, missing, wrong-type, null, too-many and named-without-contract
    queries are refused, with their messages checked;
  - an empty map, a `{"0": "ok"}` map and a mixed-key map are refused as not
    arrays;
  - a nested empty map is refused by `array`, but routed whole by `object`
    and by the variadic `mixed`;
  - `{value: {z: 20, a: 10}}` reaches the variadic `rest` as `[20, 10]`;
  - a registered default `{"0": "x", "1": "y"}`, sent as a JSON object
    (the probe records the bytes it sends), reaches the worker as the list
    `[["x", "y"]]`. On the previous source native routed it as a map;
  - a registered default `{"0": "x", "k": "y"}` or `{"1": "x"}` fails
    published PHP's Avro encoder (`invalid_map_key`) with an uncaught 500.
    The fixture pins only that status.
- **Native.** `query_contract.rs` ports the normalization and type rules
  over `codec::OrderedValue`. Apache's generic reader keeps maps unordered,
  so the new `codec/wire_order.rs` reads the bytes the public reader has
  already validated again through a private binding schema. That schema
  spells each map as an array of key/value records, as PHP's
  `ValueDatumWriter` does, so the binary layout is the same and Apache still
  reads every primitive and block. Writing goes through the same binding,
  under the public header, and the public reader checks the result. For every
  fixture blob the bytes match PHP's.
  A registered default takes PHP's array shape: canonical integer keys
  0 to n-1 make a list, string keys a map, and any other integer key is
  answered 500 `query_default_unencodable`, naming the parameter.
  Violations answer first, as PHP encodes only valid arguments.
  `check_query_contract` returns the normalized list for a declared query,
  or the refusal. `query_attempt` enqueues the re-encoded blob.
- **Kept.**
  - A registered default map keeps its keys in sorted order, not the
    registered order: native's JSON objects do not preserve order
    (`serde_json` without `preserve_order`). The same limit makes
    `{"1": "a", "0": "b"}` a list on native, where PHP's array is not a list
    and fails to encode.
  - `callable` matches nothing here: PHP's `is_callable` also accepts
    strings naming functions, which a native server cannot resolve.
  - A class name that a published PHP server can load (a real class) would
    refuse other objects. Native, loading no classes, accepts any object.
- **Tests.** `query_contract_arguments.rs` (routed normalization; refusals
  with messages) fails on the previous source. `query_contract.rs` unit
  tests cover the type rules and integer-keyed maps.
