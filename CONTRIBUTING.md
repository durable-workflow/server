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
