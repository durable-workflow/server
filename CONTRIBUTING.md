# Contributing

Run focused PHPUnit coverage and the repository's source-quality checks for
changed code.

Replay transport, replay promotion, and payload-codec fixes also follow the
organization
[regression-corpus contract](https://github.com/durable-workflow/.github/tree/main/regression-corpus).
Add one minimal history or command-sequence fixture under
`tests/Fixtures/ReplayRegression/`, or the shared cross-language wire fixture
under `tests/Fixtures/CodecRegression/`, as applicable.

Fixtures are append-only and preserve protocol version, value and type,
framing, and stable failure policy. Run:

```bash
python scripts/ci/validate-regression-corpus.py --base-ref <target>
```
