# Apache Avro 0.22.0 native validation patch

Upstream: `apache/avro-rs`, release `rel/release-0.22.0`, commit
`ec5721cb0c80dcde56c1049a004f1d785abd88cf`.
Crates.io archive SHA-256:
`312c1ea69e5fe9966e0029fb95aca8790100b85aff4f0d3b00a9337c74069a9c`.
The packaged Cargo manifests, notices, runtime source, examples, benches and
tests are retained. Only three upstream source files are modified:

- `src/serde/deser_schema/block.rs`: enforce negative collection-block byte
  lengths while traversing every item, including map keys. Short, long and
  negative declared sizes refuse; exact negative blocks remain supported.
- `src/serde/deser_schema/mod.rs`: discard ignored bytes through bounded copying
  and grow decoded scalar buffers from observed data, instead of allocating a
  declared scalar length before a truncated stream can reject it.
- `src/types.rs`: `Value::validate`, `validate_schemata` and
  `validate_with_names` log only "Invalid value for the schema." on a
  failure. Upstream logs the whole value, the schema and the reason, and the
  reason can itself contain the value, so a rejected payload could reach a
  log. The `bool` API of 0.22.0 is unchanged, and so are the reasons that
  `validate_internal` returns to the writer. The module's tests now expect the
  fixed message, and one checks that the value is not logged. Upstream fixed
  the same defect on `main` in commit
  `3c1decb54c612dce292a4345838cc470d66d8c6c`, which is not yet in a release.
  The native crate also compiles every `log` macro out of its builds.

The external adapter uses `GenericSingleObjectReader::read_deser` and a Serde
visitor that discards decoded collections, checks finite doubles and preserves
the original encoded bytes outside validation. Apache owns schema traversal,
primitive decoding and framing. The native reader does not substitute a custom
Avro codec. No codec/writer or SDK version is changed.

`codec::validation::tests` and `execution::external_payloads` exercise the
block patches. `logging_tests::rejected_avro_values_never_reach_a_logger`
exercises the logging patch.
Frozen PHP 2.6.2 independently rejects the malformed block frames and accepts
the exact negative block. With the unpatched library, the actual new negative
block regression fails by accepting its invalid size. The PHP probe is
`scripts/conformance/server-parity/external-payload-validation.php`.

This patch currently affects the schema-aware external-validation path. Full
inline ingress, provider, allocation/CPU/deep-nesting and all-database acceptance
remain separate qualification gates. The generic datum reader is unchanged.
