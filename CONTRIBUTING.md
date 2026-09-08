# Contributing

Run focused PHPUnit coverage and the repository's source-quality checks for
changed code.

Payload-codec fixes also follow the organization
[regression-corpus contract](https://github.com/durable-workflow/.github/tree/main/regression-corpus).
Add the smallest cross-language wire fixture under
`tests/Fixtures/CodecRegression/`.

Fixtures are append-only and preserve protocol version, value and type,
framing, and stable failure policy. Run:

```bash
python scripts/ci/validate-regression-corpus.py --base-ref <target>
```

The server's PHPUnit codec corpus runner derives its fixture globs from
`regression-corpus-policy.json`. New portable `codec-regression-v1` selectors
therefore join the PHP execution inventory automatically. Formats without an
official PHP executor are rejected by corpus validation and cannot contribute
guarded growth.

A wire-codec defect also needs an append-only counterfactual proof under
`tests/Fixtures/CodecRegressionProofs/`. The proof pairs each new codec fixture
with one changed boundary path and a changed Feature PHPUnit test. The test
uses `ServerCodecRegressionFixtureExecutor::exercise()` exactly once. That shared
executor invokes a zero-argument proof callback. For each counterfactual source
snapshot, validation embeds the selected fixture in the claimed boundary and
routes its official PHP codec calls through a stateless proxy. The validator
requires the proxy's per-run boundary attestation, while proof adapters that
read verifier input, mutate verifier state, branch, or dispatch dynamically are
rejected. Complete source qualification runs the proof against the candidate,
the target revision, and a candidate with only that boundary reverted. The
regression must pass on the candidate and fail on both defective revisions.
Review the fixture and HTTP reproducer together; substituting an unrelated
valid fixture cannot prove the causality of a request that deliberately submits
malformed bytes.

Transport and resource fixes are different: HTTP body limits, streaming storage,
reference retention and metadata-only reads need their real HTTP, integrity,
memory and recovery regressions. When no new wire fixture is appropriate, the
validator reports changed payload paths for maintainer review without requiring
artificial corpus growth. The PR must explain unchanged wire semantics and show
the original failure and the corrected behavior at the actual affected surface.
Review that evidence before merging. Existing fixtures remain immutable and run
in the normal suite; wire-format, type-identity, framing or codec acceptance
defects still require portable fixtures and the proof above. A green inventory
check alone is not qualification.
