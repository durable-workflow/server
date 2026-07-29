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

A server codec-boundary fix also needs an append-only counterfactual proof under
`tests/Fixtures/CodecRegressionProofs/`. The proof pairs each new codec fixture
with one changed boundary path and a changed Feature PHPUnit test. The test
consumes the fixture path from `SERVER_CODEC_REGRESSION_FIXTURE`. Complete source
qualification runs that test against both the candidate and the target revision:
the candidate must pass, while the target and a candidate with only that boundary
reverted must reproduce the assertion failure.
